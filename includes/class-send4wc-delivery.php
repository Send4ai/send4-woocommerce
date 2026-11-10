<?php
/**
 * Queue, send, and retry. The only path an order event takes to Send4.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue first, send from the worker.
 */
class Send4WC_Delivery {

	/**
	 * Seconds one queue run may spend sending, filterable as
	 * `send4wc_queue_time_budget`. A third of WP-Cron's 60 second lock, leaving
	 * room for the one request still in flight when the budget runs out.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Action Scheduler hook that drains the queue right after an enqueue.
	 */
	const ASYNC_HOOK = 'send4wc_send_now';

	/**
	 * Put an order event on the queue and ask for it to be sent soon.
	 *
	 * Runs inside whatever request changed the order — a checkout, the admin's
	 * order screen, a payment gateway's callback — so it does no network call
	 * and never throws.
	 *
	 * @param array  $envelope The reference Send4WC_Order_Payload::reference() made.
	 * @param string $topic    `order.status.<status>`.
	 * @return bool True when the event is on the queue.
	 */
	public static function queue( array $envelope, $topic ) {
		// A row is always some connection's. Without one there is nowhere to
		// send it, and nothing is lost: Send4 asked for no topic.
		if ( ! Send4WC_Settings::has_credentials() ) {
			return false;
		}

		$id = Send4WC_Queue::enqueue( $envelope, $topic );

		// The table can be missing when the schema install failed on activation,
		// or someone dropped it. Created once here rather than losing the event.
		if ( false === $id ) {
			Send4WC_Queue::install();
			$id = Send4WC_Queue::enqueue( $envelope, $topic );
		}

		$context = array(
			'topic'    => $topic,
			'event_id' => isset( $envelope['event']['id'] ) ? (string) $envelope['event']['id'] : '',
		);

		if ( false === $id ) {
			Send4WC_Settings::record_delivery(
				'failed',
				__( 'An order event could not be queued: the queue table is missing or not writable.', 'send4-for-woocommerce' )
			);
			Send4WC_Logger::log( 'error', 'The order event could not be queued: it is lost.', $context );

			return false;
		}

		Send4WC_Logger::log( 'info', 'Order event queued for Send4.', $context );
		self::schedule_send();

		return true;
	}

	/**
	 * Ask for the queue to be worked within seconds, outside this request.
	 *
	 * Action Scheduler (part of WooCommerce) runs an async action at the end of
	 * the request or on its next queue run. The every-minute WP-Cron event is
	 * the safety net for a site where that does not happen, and carries the
	 * retries.
	 *
	 * @return void
	 */
	public static function schedule_send() {
		Send4WC_Cron::ensure_scheduled();

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::ASYNC_HOOK, array(), 'send4wc' ) ) {
			return;
		}

		as_enqueue_async_action( self::ASYNC_HOOK, array(), 'send4wc' );
	}

	/**
	 * Work the queue. Called by Action Scheduler, WP-Cron, the dashboard and WP-CLI.
	 *
	 * Built for the moment it matters most, which is an outage: the token is
	 * exchanged once for the whole batch (sign-in is rate limited to ten a
	 * minute), each row is claimed before it is sent so an overlapping run
	 * cannot send it too, and the batch stops at the first sign Send4 is down
	 * or once TIME_BUDGET is spent, rather than spending a timeout on every row.
	 *
	 * @param int $limit Maximum rows to attempt in one pass.
	 * @return array{attempted:int,delivered:int,failed:int}
	 */
	public static function process_queue( $limit = 20 ) {
		$summary = array(
			'attempted' => 0,
			'delivered' => 0,
			'failed'    => 0,
		);

		if ( ! Send4WC_Settings::has_credentials() ) {
			// Nothing can be sent, but a pending row must keep the event alive
			// for the moment the site is reconnected.
			self::unschedule_when_idle();

			return $summary;
		}

		// Rows queued under another API client belong to another workspace.
		// Send4 dedupes per client, so sending one here would be accepted as
		// an order of the wrong account. Parked, never sent; retention
		// deletes it like any other dead letter.
		$quarantined = Send4WC_Queue::quarantine_foreign(
			Send4WC_Queue::current_client_id(),
			__( 'Queued while this site was connected to a different Send4 account, so it was not sent to this one.', 'send4-for-woocommerce' )
		);

		if ( $quarantined > 0 ) {
			Send4WC_Logger::log(
				'warning',
				'Queued order events from a previous Send4 connection were set aside, not sent.',
				array( 'count' => $quarantined )
			);
		}

		$rows = Send4WC_Queue::due( $limit );

		if ( empty( $rows ) ) {
			self::unschedule_when_idle();

			return $summary;
		}

		$client  = new Send4WC_Api_Client();
		$token   = $client->token();
		$failure = null;

		if ( is_wp_error( $token ) ) {
			$failure = $client->result_from_token_error( $token );

			if ( $failure->is_retryable() ) {
				// Send4 is down or rate limiting sign-in. Every row would fail
				// the same way, and each one would cost an exchange and an
				// attempt; leave them exactly as they are for the next run.
				// While a Retry-After runs no request was made at all, and
				// that was logged when Send4 asked for it.
				if ( 'send4wc_sign_in_cooldown' === $token->get_error_code() ) {
					return $summary;
				}

				Send4WC_Logger::log(
					'warning',
					'Could not sign in to Send4; queued order events left for the next run.',
					array(
						'code'  => $failure->code,
						'error' => $failure->message,
					)
				);

				return $summary;
			}
		}

		$started = microtime( true );

		/**
		 * Seconds one queue run may spend sending before it leaves the rest.
		 *
		 * @param int $budget Seconds. Default Send4WC_Delivery::TIME_BUDGET.
		 */
		$budget = (float) apply_filters( 'send4wc_queue_time_budget', self::TIME_BUDGET );

		foreach ( $rows as $row ) {
			// Checked before every row but the first, so a run always makes
			// progress however small the budget.
			if ( $summary['attempted'] > 0 && ( microtime( true ) - $started ) >= $budget ) {
				break;
			}

			if ( ! Send4WC_Queue::claim( $row ) ) {
				// Another run took it between our SELECT and now.
				continue;
			}

			++$summary['attempted'];

			// The queue holds a reference; the event is built from the order as
			// it is now (see Send4WC_Order_Payload).
			$event = Send4WC_Order_Payload::build( Send4WC_Queue::envelope( $row ) );

			if ( is_wp_error( $event ) ) {
				Send4WC_Queue::record_permanent_failure( $row, $event->get_error_message() );
				Send4WC_Settings::record_delivery( 'failed', $event->get_error_message() );
				Send4WC_Logger::log(
					'warning',
					'Order event not sent: ' . $event->get_error_code(),
					array(
						'topic'    => $row->topic,
						'event_id' => $row->event_id,
					)
				);
				++$summary['failed'];
				continue;
			}

			// A credential that can never work is the same answer for every
			// row, and it is already known: no request needed to learn it.
			$result  = null !== $failure ? $failure : $client->send_store_event( $event );
			$context = array(
				'topic'    => $row->topic,
				'event_id' => $row->event_id,
				'attempt'  => (int) $row->attempts + 1,
				'code'     => $result->code,
			);

			if ( $result->is_final() ) {
				$status = self::final_status( $result );

				Send4WC_Queue::delete( (int) $row->id );
				Send4WC_Settings::record_delivery( $status, $result->message );
				$context['status'] = $status;
				Send4WC_Logger::log( 'info', 'Order event delivered to Send4.', $context );
				++$summary['delivered'];
				continue;
			}

			$context['error'] = $result->message;

			if ( Send4WC_Api_Result::PERMANENT === $result->outcome ) {
				Send4WC_Queue::record_permanent_failure( $row, $result->message );
				Send4WC_Settings::record_delivery( 'failed', $result->message );
				$context['status'] = 'failed';
				Send4WC_Logger::log( 'error', 'Order event rejected; it will not be retried.', $context );
				++$summary['failed'];
				continue;
			}

			$status = Send4WC_Queue::record_failure( $row, $result->message, $result->retry_after );
			++$summary['failed'];

			if ( Send4WC_Queue::STATUS_FAILED === $status ) {
				Send4WC_Settings::record_delivery( 'failed', $result->message );
				$context['status'] = 'failed';
				Send4WC_Logger::log(
					'error',
					sprintf( 'Order event still failing; giving up after %d attempts.', Send4WC_Queue::MAX_ATTEMPTS ),
					$context
				);
			} else {
				Send4WC_Settings::record_delivery( 'queued', $result->message );
				$context['status'] = 'queued';
				Send4WC_Logger::log( 'warning', 'Order event not delivered yet; rescheduled.', $context );
			}

			// Unreachable, down, or telling us to slow down: the rows behind
			// this one would get the same answer, each after its own timeout.
			// …except when only this order was busy: Send4 answered, and the
			// rows behind it are other orders.
			if ( ! $result->order_busy && ( 0 === $result->code || 429 === $result->code || $result->code >= 500 ) ) {
				break;
			}
		}

		self::unschedule_when_idle();

		return $summary;
	}

	/**
	 * Move this connection's dead letters back to pending and wake the worker.
	 *
	 * Called from the dashboard and `wp send4wc requeue`, once whatever made the
	 * rows fail has been fixed.
	 *
	 * @return int Rows requeued.
	 */
	public static function requeue() {
		if ( ! Send4WC_Settings::has_credentials() || ! Send4WC_Queue::table_exists() ) {
			return 0;
		}

		$moved = Send4WC_Queue::requeue( Send4WC_Queue::current_client_id() );

		if ( $moved > 0 ) {
			Send4WC_Cron::ensure_scheduled();
			Send4WC_Logger::log( 'info', 'Failed order events put back on the queue for another attempt.', array( 'count' => $moved ) );
		}

		return $moved;
	}

	/**
	 * What a final answer means for the dashboard.
	 *
	 * @param Send4WC_Api_Result $result A final result.
	 * @return string delivered, duplicate or unrouted.
	 */
	private static function final_status( Send4WC_Api_Result $result ) {
		if ( Send4WC_Api_Result::DUPLICATE === $result->outcome ) {
			return 'duplicate';
		}

		return Send4WC_Api_Result::UNROUTED === $result->outcome ? 'unrouted' : 'delivered';
	}

	/**
	 * Drop the every-minute event once there is nothing left for it to do.
	 *
	 * An idle site should not wake WP-Cron sixty times an hour. Enqueueing and
	 * requeueing schedule it again, and the daily sweep re-ensures it as a
	 * safety net.
	 *
	 * @return void
	 */
	private static function unschedule_when_idle() {
		if ( ! Send4WC_Queue::has_pending() ) {
			Send4WC_Cron::unschedule();
		}
	}
}
