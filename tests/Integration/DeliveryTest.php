<?php
/**
 * What the plugin does with each answer Send4 can give an order-event POST.
 *
 * @package Send4WC
 */

/**
 * The queue decision is the whole point of this class: an order event must
 * never be lost to a deploy, and must never be sent twice because a 202 was
 * misread.
 */
class Send4WC_Delivery_Test extends Send4WC_Test_Case {

	const STORE_EVENTS = '/api/integrations/wordpress/store-events';

	/**
	 * Queue an order's processing event, script Send4's answer, and run the
	 * worker once.
	 *
	 * @param callable      $answer Scripts the store-event response on the recorder.
	 * @param WC_Order|null $order  The order, or null for a new one.
	 * @return array{summary:array,row:object} What the run did, and the row as it was queued.
	 */
	private function deliver( callable $answer, $order = null ) {
		$row = $this->queueEvent( $order instanceof WC_Order ? $order : $this->makeOrder() );

		$this->http->queueToken();
		$answer( $this->http );

		return array(
			'summary' => Send4WC_Delivery::process_queue(),
			'row'     => $row,
		);
	}

	/**
	 * The most recent log entry.
	 *
	 * @return array
	 */
	private function lastLogEntry() {
		$entries = Send4WC_Logger::entries();

		$this->assertNotEmpty( $entries );

		return $entries[0];
	}

	/**
	 * Send4 binds a credential to the site it was minted for and answers 403
	 * {status:'rejected'} when an order event arrives from anywhere else. That
	 * needs a different fix from the other 403 (a key missing the ability), so
	 * the admin has to be told which one happened.
	 *
	 * @return void
	 */
	public function test_a_site_mismatch_403_reports_what_send4_said() {
		$run = $this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue(
					Send4WC_Http_Recorder::response(
						403,
						array(
							'status'  => 'rejected',
							'routed'  => false,
							'message' => 'This credential is connected to a different site.',
						)
					)
				);
			}
		);

		$this->assertSame( 1, $run['summary']['failed'] );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
		$this->assertSame(
			'This credential is connected to a different site.',
			$row->last_error,
			'Telling this admin to tick an ability sends them to the wrong fix.'
		);
		$this->assertSame( 'This credential is connected to a different site.', Send4WC_Settings::delivery_status()['error'] );
	}

	/**
	 * A 403 with nothing useful in it still has to say something actionable.
	 *
	 * @return void
	 */
	public function test_a_403_without_a_message_falls_back_to_the_ability_hint() {
		$this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue( Send4WC_Http_Recorder::response( 403, array() ) );
			}
		);

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
		$this->assertStringContainsString( 'WordPress plugin', $row->last_error );
	}

	/**
	 * The happy path: accepted, routed, nothing left behind — and what went
	 * out was the order as it is now, keyed by the event the queue held.
	 *
	 * @return void
	 */
	public function test_a_routed_202_is_delivered_and_the_row_removed() {
		$order = $this->makeOrder();
		$run   = $this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue202( true, 4242 );
			},
			$order
		);

		$this->assertSame( 1, $run['summary']['delivered'] );
		$this->assertSame( array(), $this->queueRows() );

		$status = Send4WC_Settings::delivery_status();

		$this->assertSame( 'delivered', $status['status'] );
		$this->assertSame( '', $status['error'] );
		$this->assertSame( 'info', $this->lastLogEntry()['level'] );

		// The event really went to the store-events endpoint, whole.
		$sent = $this->http->requestsTo( self::STORE_EVENTS );

		$this->assertCount( 1, $sent );

		$posted = $sent[0]['body'];

		$this->assertSame( $run['row']->event_id, $posted['event']['id'] );
		$this->assertSame( 'order.status.processing', $posted['event']['topic'] );
		$this->assertSame( (string) $order->get_id(), $posted['order']['id'] );
		$this->assertSame( 'processing', $posted['order']['status'] );
		$this->assertSame( '861234567', $posted['customer']['phone_raw'] );
	}

	/**
	 * Accepted but unrouted means "no flow starts on this status", not
	 * "retry": the event may still have ended runs.
	 *
	 * @return void
	 */
	public function test_an_unrouted_202_is_recorded_but_never_retried() {
		$this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue202( false );
			}
		);

		$this->assertSame( array(), $this->queueRows() );

		$status = Send4WC_Settings::delivery_status();

		$this->assertSame( 'unrouted', $status['status'] );
		$this->assertStringContainsString( 'No flow starts on this status', $status['error'] );

		// Nothing to retry, so a queue pass makes no further requests.
		$before = $this->http->requestCount();

		Send4WC_Delivery::process_queue();

		$this->assertSame( $before, $this->http->requestCount() );
	}

	/**
	 * A buyer who has opted out of texts is a final answer too: Send4 has the
	 * event and chose not to start a flow, and no retry will change its mind.
	 *
	 * @return void
	 */
	public function test_an_unrouted_202_for_an_opted_out_buyer_is_final() {
		$this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue202( false, null, 'lead_opted_out' );
			}
		);

		$this->assertSame( array(), $this->queueRows() );

		$status = Send4WC_Settings::delivery_status();

		$this->assertSame( 'unrouted', $status['status'] );
		$this->assertStringContainsString( 'opted out', $status['error'] );

		$before = $this->http->requestCount();

		Send4WC_Delivery::process_queue();

		$this->assertSame( $before, $this->http->requestCount() );
	}

	/**
	 * Send4 having seen the order event already is a success, not a warning.
	 *
	 * @return void
	 */
	public function test_a_duplicate_is_treated_as_delivered() {
		$run = $this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queueDuplicate();
			}
		);

		$this->assertSame( 1, $run['summary']['delivered'] );
		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'duplicate', Send4WC_Settings::delivery_status()['status'] );

		$entry = $this->lastLogEntry();

		$this->assertSame( 'info', $entry['level'] );
		$this->assertNotSame( 'warning', $entry['level'] );
	}

	/**
	 * A malformed event will be malformed forever, so it is parked, not
	 * retried, and the error is kept for the settings screen.
	 *
	 * @return void
	 */
	public function test_a_422_is_parked_as_a_permanent_failure() {
		$this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue422( array( 'customer.phone_raw' => array( 'The customer.phone raw field must not be greater than 32 characters.' ) ) );
			}
		);

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
		$this->assertSame( 'customer.phone_raw: The customer.phone raw field must not be greater than 32 characters.', $row->last_error );
		$this->assertSame( 'failed', Send4WC_Settings::delivery_status()['status'] );
		$this->assertSame( 'error', $this->lastLogEntry()['level'] );

		$before = $this->http->requestCount();

		$this->assertSame( 0, Send4WC_Delivery::process_queue()['attempted'] );
		$this->assertSame( $before, $this->http->requestCount() );
	}

	/**
	 * A hand-made API key has no installation for Send4 to key the order's
	 * receipt on. Every event would get the same 409, so it is parked with
	 * Send4's own words, which say to reconnect.
	 *
	 * @return void
	 */
	public function test_a_409_is_parked_as_a_permanent_failure() {
		$this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue409();
			}
		);

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
		$this->assertSame( 'This API key was not created by connecting a site.', $row->last_error );
		$this->assertSame( 'failed', Send4WC_Settings::delivery_status()['status'] );
		$this->assertSame( 'error', $this->lastLogEntry()['level'] );

		$before = $this->http->requestCount();

		$this->assertSame( 0, Send4WC_Delivery::process_queue()['attempted'] );
		$this->assertSame( $before, $this->http->requestCount() );
	}

	/**
	 * A server error is somebody else's outage: keep the event and try later.
	 *
	 * @return void
	 */
	public function test_a_500_is_kept_for_retry() {
		$this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queue500();
			}
		);

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 1, (int) $row->attempts );
		$this->assertSame( 'order.status.processing', $row->topic );

		$this->assertSame( 'queued', Send4WC_Settings::delivery_status()['status'] );

		// The retry is scheduled, and the first backoff step is a minute.
		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
		$this->assertEqualsWithDelta(
			time() + 60,
			strtotime( $row->next_attempt_at . ' UTC' ),
			5
		);
	}

	/**
	 * Send4 answers 503 {status: retry} when the run this event ends is in the
	 * middle of answering the buyer. It wrote nothing, and its Retry-After says
	 * when to come back — longer than the first backoff step here, so that is
	 * the wait.
	 *
	 * @return void
	 */
	public function test_a_503_retry_is_postponed_by_its_retry_after() {
		$this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queueRetry503( 600 );
			}
		);

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 1, (int) $row->attempts );
		$this->assertStringContainsString( 'asked for a retry', $row->last_error );
		$this->assertEqualsWithDelta( time() + 600, strtotime( $row->next_attempt_at . ' UTC' ), 5 );
		$this->assertSame( 'queued', Send4WC_Settings::delivery_status()['status'] );
	}

	/**
	 * A busy order is one order. Send4 answered, so the orders queued behind it
	 * go in the same run, unlike after a real outage.
	 *
	 * @return void
	 */
	public function test_a_busy_order_does_not_hold_up_the_others() {
		$busy  = $this->makeOrder();
		$other = $this->makeOrder();
		Send4WC_Delivery::queue( Send4WC_Order_Payload::reference( $busy, 'order.status.processing', true ), 'order.status.processing' );
		Send4WC_Delivery::queue( Send4WC_Order_Payload::reference( $other, 'order.status.processing', true ), 'order.status.processing' );
		// Both are due in the same second; the busy one goes first.
		$this->makeDue( $this->queueRows()[0] );

		$this->http->queueToken();
		$this->http->queueRetry503( 15 );
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 2, $summary['attempted'] );
		$this->assertSame( 1, $summary['delivered'] );
		$this->assertSame( 'woo:' . $busy->get_id() . ':order.status.processing', $this->onlyQueueRow()->event_id );
	}

	/**
	 * A request that never completes is the same class of problem as a 500.
	 *
	 * @return void
	 */
	public function test_a_network_timeout_is_kept_for_retry() {
		$run = $this->deliver(
			static function ( Send4WC_Http_Recorder $http ) {
				$http->queueTimeout();
			}
		);

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertStringContainsString( 'timed out', $row->last_error );

		// The reference is kept intact, so the retry is the same event.
		$this->assertSame( $run['row']->envelope, $row->envelope );
		$this->assertSame( $run['row']->event_id, Send4WC_Queue::envelope( $row )['event']['id'] );
	}

	/**
	 * A flow cannot start on an order that is gone. The event for a status a
	 * flow starts on is parked as a permanent failure, without a request: there
	 * is nothing to send but an order number Send4 can do nothing with.
	 *
	 * @return void
	 */
	public function test_a_deleted_orders_start_event_is_a_permanent_failure() {
		$order = $this->makeOrder();

		$this->queueEvent( $order );
		$order->delete( true );

		$this->http->queueToken();
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertCount( 0, $this->http->requestsTo( self::STORE_EVENTS ) );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
		$this->assertSame( 'The order was deleted before its event could be sent.', $row->last_error );

		$status = Send4WC_Settings::delivery_status();

		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( 'The order was deleted before its event could be sent.', $status['error'] );
	}

	/**
	 * A cancellation still has to end the runs its order started, so a deleted
	 * order's lifecycle event goes anyway — with the order id and status, and
	 * nothing about the buyer.
	 *
	 * @return void
	 */
	public function test_a_deleted_orders_lifecycle_event_is_still_sent() {
		$order    = $this->makeOrder();
		$order_id = $order->get_id();

		$this->queueEvent( $order, 'order.status.cancelled', false );
		$order->delete( true );

		$this->http->queueToken();
		$this->http->queue202( false );

		$this->assertSame( 1, Send4WC_Delivery::process_queue()['delivered'] );
		$this->assertSame( array(), $this->queueRows() );

		$sent = $this->http->requestsTo( self::STORE_EVENTS );

		$this->assertCount( 1, $sent );
		$this->assertSame( (string) $order_id, $sent[0]['body']['order']['id'] );
		$this->assertSame( 'cancelled', $sent[0]['body']['order']['status'] );
		$this->assertNull( $sent[0]['body']['order']['number'] );
		$this->assertArrayNotHasKey( 'customer', $sent[0]['body'] );
	}

	/**
	 * A disconnected store sends nothing and spends nothing: the row keeps its
	 * attempts for the moment the store is reconnected.
	 *
	 * @return void
	 */
	public function test_it_does_nothing_when_the_store_is_not_connected() {
		$this->queueEvent( $this->makeOrder() );

		Send4WC_Settings::disconnect();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'] );
		$this->assertSame( 0, $this->http->requestCount() );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 0, (int) $row->attempts );
	}
}
