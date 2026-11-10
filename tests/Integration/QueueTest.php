<?php
/**
 * The retry queue: backoff, attempt ceiling, and what a retry actually sends.
 *
 * @package Send4WC
 */

/**
 * A queued order event has already left the checkout behind, so the rules here
 * are about not losing it and not delivering it twice.
 */
class Send4WC_Queue_Test extends Send4WC_Test_Case {

	const STORE_EVENTS = '/api/integrations/wordpress/store-events';

	/**
	 * A queued row, produced the way the order hook produces one: due at once,
	 * no attempt made yet.
	 *
	 * @return object
	 */
	private function queueOne() {
		return $this->queueEvent( $this->makeOrder() );
	}

	/**
	 * A queued row that has failed its first attempt with a 500.
	 *
	 * @return object
	 */
	private function failedOnce() {
		$this->queueOne();

		$this->http->queueToken();
		$this->http->queue500();

		Send4WC_Delivery::process_queue();

		return $this->onlyQueueRow();
	}

	/**
	 * A reference for tests that drive the queue directly, without an order.
	 *
	 * @param int    $order_id    Order id.
	 * @param string $occurred_at When the order reached the status.
	 * @return array
	 */
	private function reference( $order_id = 512, $occurred_at = '2026-11-10T09:00:00+00:00' ) {
		return array(
			'event' => array(
				'id'          => 'woo:' . $order_id . ':order.status.processing',
				'topic'       => 'order.status.processing',
				'occurred_at' => $occurred_at,
			),
			'order' => array( 'id' => (string) $order_id ),
			'full'  => true,
		);
	}

	/**
	 * Queue several due order events, one order each.
	 *
	 * @param int $count How many.
	 * @return array<int,object>
	 */
	private function queueDue( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->assertTrue(
				Send4WC_Delivery::queue(
					Send4WC_Order_Payload::reference( $this->makeOrder(), 'order.status.processing', true ),
					'order.status.processing'
				)
			);
		}

		foreach ( $this->queueRows() as $row ) {
			$this->makeDue( $row );
		}

		return $this->queueRows();
	}

	/**
	 * Set columns on a row directly.
	 *
	 * @param object $row    Queue row.
	 * @param array  $values Column => value.
	 * @return void
	 */
	private function force( $row, array $values ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Send4WC_Queue::table(), $values, array( 'id' => (int) $row->id ) );
	}

	/**
	 * A new row is due at once and has had no attempt: the queue is the only
	 * path an order event takes, not a fallback after a failed send.
	 *
	 * @return void
	 */
	public function test_a_queued_event_is_due_at_once_with_no_attempt_made() {
		$row = $this->queueOne();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 0, (int) $row->attempts );
		$this->assertLessThanOrEqual( time(), strtotime( $row->next_attempt_at . ' UTC' ) );
		$this->assertSame( (int) $row->id, (int) Send4WC_Queue::due()[0]->id );
	}

	/**
	 * A row always belongs to a connection. With none there is nobody to
	 * deliver it to, and nothing is queued.
	 *
	 * @return void
	 */
	public function test_nothing_is_queued_without_a_connection() {
		Send4WC_Settings::disconnect();

		$this->assertFalse( Send4WC_Queue::enqueue( $this->reference(), 'order.status.processing' ) );
		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * A retry must be the same event, not a second one: Send4 keeps one per
	 * order and status, and so does the queue.
	 *
	 * @return void
	 */
	public function test_a_retry_sends_the_same_event_id() {
		$row = $this->failedOnce();

		$this->makeDue( $row );
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 1, $summary['delivered'] );

		$sent = $this->http->requestsTo( self::STORE_EVENTS );

		$this->assertCount( 2, $sent );
		$this->assertSame( $row->event_id, $sent[1]['body']['event']['id'] );
		$this->assertSame( $sent[0]['body']['event'], $sent[1]['body']['event'], 'The retry is the same event, occurred_at included.' );
	}

	/**
	 * A delivered event leaves nothing behind.
	 *
	 * @return void
	 */
	public function test_a_delivered_retry_deletes_the_row() {
		$row = $this->failedOnce();

		$this->makeDue( $row );
		$this->http->queue202();

		Send4WC_Delivery::process_queue();

		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'delivered', Send4WC_Settings::delivery_status()['status'] );
	}

	/**
	 * Same for a duplicate or an unrouted answer: both mean Send4 has it.
	 *
	 * @return void
	 */
	public function test_a_duplicate_retry_also_deletes_the_row() {
		$row = $this->failedOnce();

		$this->makeDue( $row );
		$this->http->queueDuplicate();

		Send4WC_Delivery::process_queue();

		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'duplicate', Send4WC_Settings::delivery_status()['status'] );
	}

	/**
	 * A still-failing retry is rescheduled rather than dropped.
	 *
	 * @return void
	 */
	public function test_a_failing_retry_is_rescheduled_and_counts_the_attempt() {
		$row = $this->failedOnce();

		$this->assertSame( 1, (int) $row->attempts );
		$this->assertEqualsWithDelta( time() + 60, strtotime( $row->next_attempt_at . ' UTC' ), 5 );

		$this->makeDue( $row );
		$this->http->queue500();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 1, $summary['failed'] );

		$after = $this->onlyQueueRow();

		$this->assertSame( 2, (int) $after->attempts );
		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $after->status );
		$this->assertEqualsWithDelta( time() + 300, strtotime( $after->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * A row that is not due yet is left alone.
	 *
	 * @return void
	 */
	public function test_it_leaves_rows_that_are_not_due() {
		$this->failedOnce();

		$before = $this->http->requestCount();

		$this->assertSame( 0, Send4WC_Delivery::process_queue()['attempted'] );
		$this->assertSame( $before, $this->http->requestCount() );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * One minute, five, fifteen, an hour, six hours, and then six hours for
	 * ever — which puts the tenth and last attempt about a day and a half out.
	 *
	 * @return void
	 */
	public function test_the_backoff_schedule() {
		$expected = array(
			1  => 60,
			2  => 300,
			3  => 900,
			4  => 3600,
			5  => 21600,
			6  => 21600,
			7  => 21600,
			8  => 21600,
			9  => 21600,
			10 => 21600,
		);

		foreach ( $expected as $attempt => $delay ) {
			$this->assertSame(
				$delay,
				Send4WC_Queue::delay_for_attempt( $attempt ),
				'attempt ' . $attempt
			);
		}

		// Attempt zero cannot happen, but must not index out of the array.
		$this->assertSame( 60, Send4WC_Queue::delay_for_attempt( 0 ) );
	}

	/**
	 * After ten attempts the row stops being retried and is kept for the
	 * settings screen (and for the retention sweep) as a failure.
	 *
	 * @return void
	 */
	public function test_it_gives_up_after_ten_attempts() {
		$row = $this->queueOne();

		$this->http->queueToken();

		for ( $i = 0; $i < Send4WC_Queue::MAX_ATTEMPTS; $i++ ) {
			$current = $this->onlyQueueRow();

			$this->assertSame( Send4WC_Queue::STATUS_PENDING, $current->status );

			$this->makeDue( $current );
			$this->http->queue500();

			Send4WC_Delivery::process_queue();
		}

		$final = $this->onlyQueueRow();

		$this->assertSame( 10, (int) $final->attempts );
		$this->assertSame( Send4WC_Queue::STATUS_FAILED, $final->status );
		$this->assertSame( $row->event_id, $final->event_id );

		// And it is never picked up again, however long we wait.
		$this->makeDue( $final );

		$this->assertSame( 0, Send4WC_Delivery::process_queue()['attempted'] );
	}

	/**
	 * The event id is the unique key, so the same order reaching the same
	 * status twice before the first is sent is one row, not two deliveries.
	 *
	 * @return void
	 */
	public function test_enqueueing_the_same_event_twice_keeps_one_row() {
		$first  = Send4WC_Queue::enqueue( $this->reference(), 'order.status.processing' );
		$second = Send4WC_Queue::enqueue( $this->reference(), 'order.status.processing' );

		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * A row whose reference cannot be read is parked rather than retried for a
	 * day and a half against a body that will never parse.
	 *
	 * @return void
	 */
	public function test_an_unreadable_envelope_is_parked() {
		$row = $this->queueOne();

		$this->force(
			$row,
			array(
				'envelope'        => 'not json',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ),
			)
		);

		$this->http->queueToken();

		$this->assertSame( 1, Send4WC_Delivery::process_queue()['failed'] );
		$this->assertCount( 0, $this->http->requestsTo( self::STORE_EVENTS ), 'Nothing was posted for it.' );

		$parked = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $parked->status );
		$this->assertSame( 'The queued order event could not be read.', $parked->last_error );
	}

	/**
	 * Counts drive the settings screen and `wp send4wc status`.
	 *
	 * @return void
	 */
	public function test_it_counts_rows_by_status() {
		$this->queueOne();

		$counts = Send4WC_Queue::counts();

		$this->assertSame( 1, $counts[ Send4WC_Queue::STATUS_PENDING ] );
		$this->assertSame( 0, $counts[ Send4WC_Queue::STATUS_FAILED ] );
		$this->assertSame( 0, $counts[ Send4WC_Queue::STATUS_PERMANENT ] );
	}

	/**
	 * The tenth failure must be reported as a failure, not as "rescheduled"
	 * with the store's status left on queued, since nothing will ever try it
	 * again.
	 *
	 * @return void
	 */
	public function test_the_attempt_that_exhausts_the_retries_is_reported_as_failed() {
		$row = $this->queueOne();

		$this->force( $row, array( 'attempts' => Send4WC_Queue::MAX_ATTEMPTS - 1 ) );
		$this->makeDue( $row );
		$this->http->queueToken();
		$this->http->queue500();

		Send4WC_Delivery::process_queue();

		$this->assertSame( Send4WC_Queue::STATUS_FAILED, $this->onlyQueueRow()->status );
		$this->assertSame( 'failed', Send4WC_Settings::delivery_status()['status'] );

		$entry = Send4WC_Logger::entries()[0];

		$this->assertSame( 'error', $entry['level'] );
		$this->assertStringContainsString( 'giving up after 10 attempts', $entry['message'] );
		$this->assertSame( 'failed', $entry['context']['status'] );
	}

	/**
	 * record_failure() says where the row ended up, so the caller can report it.
	 *
	 * @return void
	 */
	public function test_record_failure_returns_the_new_status() {
		$row = $this->queueOne();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, Send4WC_Queue::record_failure( $row, 'still down' ) );

		$row->attempts = Send4WC_Queue::MAX_ATTEMPTS - 1;

		$this->assertSame( Send4WC_Queue::STATUS_FAILED, Send4WC_Queue::record_failure( $row, 'still down' ) );
	}

	/**
	 * A sign-in outage is the same answer for every row. Asking the token
	 * endpoint once per row — rate limited to ten a minute — would charge
	 * each row an attempt for an answer that said nothing about it.
	 *
	 * @return void
	 */
	public function test_a_token_outage_leaves_the_batch_untouched() {
		$this->queueDue( 3 );
		$this->http->queue500();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 0, $this->http->requestsTo( self::STORE_EVENTS ) );

		foreach ( $this->queueRows() as $row ) {
			$this->assertSame( 0, (int) $row->attempts, 'A row was charged an attempt for a sign-in failure.' );
			$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		}
	}

	/**
	 * A sign-in answered with 429 and a Retry-After is not tried again until
	 * the wait is over: the next run, and any other send, go without one.
	 *
	 * @return void
	 */
	public function test_runs_during_a_sign_in_retry_after_do_not_sign_in() {
		$this->queueDue( 2 );

		$limited            = Send4WC_Http_Recorder::response( 429, array( 'error' => 'too_many_requests' ) );
		$limited['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '120',
		);
		$this->http->queue( $limited );

		Send4WC_Delivery::process_queue();
		Send4WC_Delivery::process_queue();

		$result = ( new Send4WC_Api_Client() )->send_store_event( array( 'event' => array( 'id' => 'woo:1:order.status.processing' ) ) );

		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 0, $this->http->requestsTo( self::STORE_EVENTS ) );
		$this->assertSame( Send4WC_Api_Result::TRANSIENT, $result->outcome );
		$this->assertGreaterThan( 100, $result->retry_after );

		foreach ( $this->queueRows() as $row ) {
			$this->assertSame( 0, (int) $row->attempts );
			$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		}
	}

	/**
	 * Once the wait is over, sign-in is tried again.
	 *
	 * @return void
	 */
	public function test_sign_in_resumes_once_the_retry_after_has_passed() {
		$this->queueDue( 1 );

		set_transient(
			Send4WC_Api_Client::SIGN_IN_COOLDOWN,
			array(
				'until'    => time() - 1,
				'status'   => 429,
				'message'  => 'Too many sign-ins.',
				'bound_to' => 'whatever',
			),
			60
		);

		$this->http->queueToken();
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['delivered'] );
	}

	/**
	 * A reconnect is a different credential, with its own sign-in budget.
	 *
	 * @return void
	 */
	public function test_a_reconnect_clears_the_sign_in_wait() {
		$limited            = Send4WC_Http_Recorder::response( 429, array( 'error' => 'too_many_requests' ) );
		$limited['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '120',
		);
		$this->http->queue( $limited );

		( new Send4WC_Api_Client() )->token();

		$this->assertNotFalse( get_transient( Send4WC_Api_Client::SIGN_IN_COOLDOWN ) );

		Send4WC_Api_Client::forget_token();

		$this->assertFalse( get_transient( Send4WC_Api_Client::SIGN_IN_COOLDOWN ) );
	}

	/**
	 * One healthy batch costs one token exchange, not one per row.
	 *
	 * @return void
	 */
	public function test_a_batch_exchanges_the_token_once() {
		$this->queueDue( 3 );
		$this->http->queueToken();
		$this->http->queue202();
		$this->http->queue202();
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 3, $summary['delivered'] );
		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 3, $this->http->requestsTo( self::STORE_EVENTS ) );
	}

	/**
	 * During an outage every row behind the first would spend its own timeout
	 * on the same answer — twenty rows at ten seconds outlast WP-Cron's lock,
	 * and the next run starts sending the same rows.
	 *
	 * @return void
	 */
	public function test_the_batch_stops_at_the_first_sign_send4_is_down() {
		$this->queueDue( 3 );
		$this->http->queueToken();
		$this->http->queueTimeout();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertCount( 1, $this->http->requestsTo( self::STORE_EVENTS ) );

		$attempts = array_map(
			static function ( $row ) {
				return (int) $row->attempts;
			},
			$this->queueRows()
		);

		sort( $attempts );

		$this->assertSame( array( 0, 0, 1 ), $attempts, 'Only the row actually tried is charged an attempt.' );
	}

	/**
	 * Same for a 5xx.
	 *
	 * @return void
	 */
	public function test_the_batch_stops_at_a_server_error() {
		$this->queueDue( 2 );
		$this->http->queueToken();
		$this->http->queue500();

		$this->assertSame( 1, Send4WC_Delivery::process_queue()['attempted'] );
	}

	/**
	 * A run stops starting new sends once its time budget is spent, so it
	 * finishes well inside WP-Cron's 60 second lock.
	 *
	 * @return void
	 */
	public function test_the_batch_stops_when_its_time_budget_is_spent() {
		$this->queueDue( 3 );
		$this->http->queueToken();
		$this->http->queue202();

		add_filter( 'send4wc_queue_time_budget', '__return_zero' );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'], 'The first row is always attempted, so a run makes progress.' );
		$this->assertCount( 2, $this->queueRows() );
	}

	/**
	 * Two workers holding the same SELECT result must not both send a row.
	 * Only the claim that still matches the row as it was read wins.
	 *
	 * @return void
	 */
	public function test_a_row_can_be_claimed_only_once() {
		$rows     = $this->queueDue( 1 );
		$snapshot = Send4WC_Queue::due()[0];

		$this->assertSame( (int) $rows[0]->id, (int) $snapshot->id );
		$this->assertTrue( Send4WC_Queue::claim( $snapshot ), 'The first worker gets the row.' );
		$this->assertFalse( Send4WC_Queue::claim( $snapshot ), 'The second worker, holding the same snapshot, does not.' );
	}

	/**
	 * A claimed row is out of everybody else's batch while the lease runs.
	 *
	 * @return void
	 */
	public function test_a_claimed_row_is_skipped_by_an_overlapping_run() {
		$this->queueDue( 1 );

		$this->assertTrue( Send4WC_Queue::claim( Send4WC_Queue::due()[0] ) );

		$this->assertSame( 0, Send4WC_Delivery::process_queue()['attempted'] );
		$this->assertSame( 0, $this->http->requestCount() );

		$lease = strtotime( $this->onlyQueueRow()->next_attempt_at . ' UTC' );

		$this->assertEqualsWithDelta( time() + Send4WC_Queue::LEASE_SECONDS, $lease, 5 );
	}

	/**
	 * A worker that died mid-send needs no cleanup: once the lease runs out
	 * the row is due again.
	 *
	 * @return void
	 */
	public function test_an_expired_lease_makes_the_row_due_again() {
		$this->queueDue( 1 );
		$this->assertTrue( Send4WC_Queue::claim( Send4WC_Queue::due()[0] ) );

		$this->makeDue( $this->onlyQueueRow() );
		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4WC_Delivery::process_queue()['delivered'] );
	}

	/**
	 * A 429 says how long to wait. Retrying sooner earns another 429 and burns
	 * one of the ten attempts.
	 *
	 * @return void
	 */
	public function test_a_rate_limited_retry_waits_as_long_as_send4_asked() {
		$this->queueDue( 1 );
		$this->http->queueToken();

		$response            = Send4WC_Http_Recorder::response( 429, array( 'message' => 'Too Many Attempts.' ) );
		$response['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '7200',
		);
		$this->http->queue( $response );

		Send4WC_Delivery::process_queue();

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertEqualsWithDelta( time() + 7200, strtotime( $row->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * And the backoff still wins when it is the longer of the two.
	 *
	 * @return void
	 */
	public function test_the_backoff_wins_over_a_shorter_retry_after() {
		$row = $this->queueOne();

		Send4WC_Queue::record_failure( $row, 'slow down', 5 );

		$this->assertEqualsWithDelta( time() + 60, strtotime( $this->onlyQueueRow()->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * The every-minute event exists only while there is work for it.
	 *
	 * @return void
	 */
	public function test_the_event_is_dropped_once_the_queue_is_empty() {
		$this->queueDue( 1 );
		Send4WC_Cron::ensure_scheduled();

		$this->http->queueToken();
		$this->http->queue202();

		Send4WC_Delivery::process_queue();

		$this->assertSame( array(), $this->queueRows() );
		$this->assertFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
	}

	/**
	 * But not while an order event is still waiting.
	 *
	 * @return void
	 */
	public function test_the_event_is_kept_while_an_order_event_is_pending() {
		$this->queueDue( 1 );
		Send4WC_Cron::ensure_scheduled();

		$this->http->queueToken();
		$this->http->queue500();

		Send4WC_Delivery::process_queue();

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
	}

	/**
	 * A disconnected store sends nothing, and must keep the event for its
	 * pending order events for the moment it is reconnected.
	 *
	 * @return void
	 */
	public function test_a_disconnected_store_keeps_the_event_for_its_pending_rows() {
		$this->queueDue( 1 );
		Send4WC_Cron::ensure_scheduled();
		Send4WC_Settings::disconnect();

		Send4WC_Delivery::process_queue();

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * The same order reaching the same status again, after its first event was
	 * dead-lettered, puts the row back in line with a fresh set of attempts:
	 * the order has evidently moved again, and whatever dead-lettered it may
	 * have changed since.
	 *
	 * @return void
	 */
	public function test_queueing_again_revives_a_dead_lettered_row() {
		foreach ( array( Send4WC_Queue::STATUS_FAILED, Send4WC_Queue::STATUS_PERMANENT ) as $index => $status ) {
			$order_id = 600 + $index;
			$first    = Send4WC_Queue::enqueue( $this->reference( $order_id ), 'order.status.processing' );
			$row      = Send4WC_Queue::find_by_event_id( 'woo:' . $order_id . ':order.status.processing', 'test-client-id' );

			$this->force(
				$row,
				array(
					'status'          => $status,
					'attempts'        => 7,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					'last_error'      => 'missing ability',
				)
			);

			$again = Send4WC_Queue::enqueue( $this->reference( $order_id, '2026-11-10T12:00:00+00:00' ), 'order.status.processing' );

			$this->assertSame( $first, $again, $status );

			$revived = Send4WC_Queue::find_by_event_id( $row->event_id, 'test-client-id' );

			$this->assertSame( Send4WC_Queue::STATUS_PENDING, $revived->status, $status );
			$this->assertSame( 0, (int) $revived->attempts, $status );
			$this->assertSame( '', $revived->last_error, $status );
			$this->assertLessThanOrEqual( time(), strtotime( $revived->next_attempt_at . ' UTC' ), $status );
			$this->assertSame( '2026-11-10T12:00:00+00:00', Send4WC_Queue::envelope( $revived )['event']['occurred_at'], $status );
		}

		$this->assertCount( 2, $this->queueRows() );
	}

	/**
	 * A pending row is left exactly as it is: it will get its own answer, and
	 * the event is built from the live order when it is sent anyway.
	 *
	 * @return void
	 */
	public function test_queueing_again_leaves_a_pending_row_as_it_was() {
		Send4WC_Queue::enqueue( $this->reference(), 'order.status.processing' );

		Send4WC_Queue::record_failure( $this->onlyQueueRow(), 'first failure' );

		$before = $this->onlyQueueRow();

		Send4WC_Queue::enqueue( $this->reference( 512, '2026-11-10T12:00:00+00:00' ), 'order.status.processing' );

		$after = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $after->status );
		$this->assertSame( 'first failure', $after->last_error );
		$this->assertSame( 1, (int) $after->attempts );
		$this->assertSame( $before->next_attempt_at, $after->next_attempt_at );
		$this->assertSame( $before->envelope, $after->envelope );
	}
}
