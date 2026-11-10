<?php
/**
 * Privacy: the policy text, and how long queue rows are kept.
 *
 * @package Send4WC
 */

/**
 * The queue holds an order id and a status, never the buyer's details, so the
 * plugin has nothing of its own to export or erase. What is left to get right
 * is telling the store's buyers what leaves the store, and not keeping rows
 * nobody will ever deliver.
 */
class Send4WC_Privacy_Test extends Send4WC_Test_Case {

	const STORE_EVENTS = '/api/integrations/wordpress/store-events';

	/**
	 * Put a row in the queue directly.
	 *
	 * @param string $status   Row status.
	 * @param int    $age_days How long ago the row was created and last touched.
	 * @param int    $order_id Order id; makes the event id unique.
	 * @return int Row id.
	 */
	private function seedRow( $status = Send4WC_Queue::STATUS_PENDING, $age_days = 0, $order_id = 512 ) {
		global $wpdb;

		$when     = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
		$event_id = 'woo:' . $order_id . ':order.status.processing';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Send4WC_Queue::table(),
			array(
				'event_id'        => $event_id,
				'topic'           => 'order.status.processing',
				'client_id'       => 'test-client-id',
				'envelope'        => wp_json_encode(
					array(
						'event' => array(
							'id'          => $event_id,
							'topic'       => 'order.status.processing',
							'occurred_at' => gmdate( 'c', time() - ( $age_days * DAY_IN_SECONDS ) ),
						),
						'order' => array( 'id' => (string) $order_id ),
						'full'  => true,
					)
				),
				'attempts'        => 1,
				'status'          => $status,
				'next_attempt_at' => $when,
				'created_at'      => $when,
				'updated_at'      => $when,
				'last_error'      => 'Send4 returned status 500.',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The suggested policy text, as an administrator opening wp-admin gets it.
	 *
	 * It tells the buyer what Send4 receives and when, that a cancellation
	 * sends only the order's number and status, and that while an order waits
	 * on the queue the store keeps no copy of their details.
	 *
	 * @return void
	 */
	public function test_the_policy_text_says_what_is_sent_and_what_waits() {
		$this->assertNotFalse( has_action( 'admin_init', array( 'Send4WC_Privacy', 'add_policy_content' ) ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		$registered = new ReflectionProperty( 'WP_Privacy_Policy_Content', 'policy_content' );
		$registered->setAccessible( true );
		$before = $registered->getValue();

		// Only ours, inside wp-admin's admin_init, where WordPress accepts it.
		// The hooks and the screen are put back by tear_down().
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' );
		add_action( 'admin_init', array( 'Send4WC_Privacy', 'add_policy_content' ) );

		try {
			do_action( 'admin_init' );

			$ours = array_values(
				array_filter(
					(array) $registered->getValue(),
					static function ( $entry ) {
						return 'Send4' === $entry['plugin_name'];
					}
				)
			);
		} finally {
			$registered->setValue( null, $before );
		}

		$this->assertCount( 1, $ours );

		$text = $ours[0]['policy_text'];

		$this->assertStringContainsString( 'send4.ai', $text );
		$this->assertStringContainsString( 'your name, phone number, email address, delivery address', $text );
		$this->assertStringContainsString( 'only its number and status are sent', $text );
		$this->assertStringContainsString( 'never your details', $text );
	}

	/**
	 * Retention: undeliverable rows go after seven days. Pending rows stay while
	 * the retry schedule could still be working them, and go once they are
	 * older than that schedule plus the same seven days — which is what a
	 * disconnected store's queue looks like, and a week-old order event must
	 * not reach Send4 the day the store reconnects.
	 *
	 * @return void
	 */
	public function test_retention_deletes_old_failures_and_abandoned_pending_rows() {
		$old_failed     = $this->seedRow( Send4WC_Queue::STATUS_FAILED, 8, 1 );
		$old_permanent  = $this->seedRow( Send4WC_Queue::STATUS_PERMANENT, 30, 2 );
		$old_pending    = $this->seedRow( Send4WC_Queue::STATUS_PENDING, 30, 3 );
		$new_failed     = $this->seedRow( Send4WC_Queue::STATUS_FAILED, 1, 4 );
		$recent_pending = $this->seedRow( Send4WC_Queue::STATUS_PENDING, 8, 5 );

		// Eight days is past RETENTION_DAYS but inside the retry span on top of
		// it, so the pending row above must survive.
		$this->assertGreaterThan(
			8 * DAY_IN_SECONDS,
			Send4WC_Queue::retry_span() + Send4WC_Privacy::RETENTION_DAYS * DAY_IN_SECONDS
		);

		$this->assertSame( 3, Send4WC_Privacy::enforce_retention() );

		$ids = array_map(
			static function ( $row ) {
				return (int) $row->id;
			},
			$this->queueRows()
		);

		sort( $ids );

		$this->assertSame( array( $new_failed, $recent_pending ), $ids );
		$this->assertNotContains( $old_failed, $ids );
		$this->assertNotContains( $old_permanent, $ids );
		$this->assertNotContains( $old_pending, $ids );
	}

	/**
	 * A pending row an operator requeued is being worked again, however old
	 * the order event itself is, so its created_at alone must not get it swept.
	 *
	 * @return void
	 */
	public function test_retention_keeps_an_old_row_that_was_requeued() {
		global $wpdb;

		$id = $this->seedRow( Send4WC_Queue::STATUS_PENDING, 30 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Send4WC_Queue::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );

		$this->assertSame( 0, Send4WC_Privacy::enforce_retention() );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * The two events split the work: the queue event drains what is due, and
	 * the hourly store sync sweeps what is past retention. The every-minute
	 * event only exists while something is pending, so it cannot be the one
	 * that guarantees a sweep.
	 *
	 * Asserted by firing the events rather than by reading has_action(): a
	 * priority literal is true of the wiring and says nothing about whether
	 * either callback runs, or whether a store that never opens wp-admin ends
	 * up keeping dead letters for ever.
	 *
	 * @return void
	 */
	public function test_the_queue_event_drains_and_the_hourly_event_sweeps() {
		$due   = $this->queueEvent( $this->makeOrder() );
		$stale = $this->seedRow( Send4WC_Queue::STATUS_FAILED, 8, 999999 );

		$this->assertCount( 2, $this->queueRows() );

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		do_action( Send4WC_Cron::HOOK );

		$sent = $this->http->requestsTo( self::STORE_EVENTS );

		$this->assertCount( 1, $sent, 'The due row should have been sent exactly once.' );
		$this->assertSame( $due->event_id, $sent[0]['body']['event']['id'] );
		$this->assertNull(
			Send4WC_Queue::find_by_event_id( $due->event_id, 'test-client-id' ),
			'The delivered row should have been removed, not merely marked.'
		);
		$this->assertSame( 'delivered', Send4WC_Settings::delivery_status()['status'] );

		// Not the every-minute event's job: a DELETE sixty times an hour on an
		// idle store would be most of what that event costs.
		$this->assertSame( array( $stale ), array_map( 'intval', wp_list_pluck( $this->queueRows(), 'id' ) ) );

		do_action( Send4WC_Store_Sync::HOOK );

		$this->assertSame(
			array(),
			$this->queueRows(),
			'The stale failure is swept by the hourly event.'
		);
	}

	/**
	 * And the sweep happens when there is nothing to deliver, so a store whose
	 * queue has quietly filled with failures still forgets them.
	 *
	 * @return void
	 */
	public function test_the_hourly_event_sweeps_even_with_nothing_due() {
		$this->seedRow( Send4WC_Queue::STATUS_PERMANENT, 30 );

		do_action( Send4WC_Store_Sync::HOOK );

		$this->assertSame( array(), $this->queueRows() );
		$this->assertCount(
			0,
			$this->http->requestsTo( self::STORE_EVENTS ),
			'A parked failure is never retried.'
		);
	}

	/**
	 * Retention on an empty queue is a no-op, not a warning.
	 *
	 * @return void
	 */
	public function test_retention_on_an_empty_queue_removes_nothing() {
		$this->assertSame( 0, Send4WC_Privacy::enforce_retention() );
	}
}
