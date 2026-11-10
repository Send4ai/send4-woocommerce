<?php
/**
 * Every query the retry queue makes runs.
 *
 * @package Send4WC
 */

/**
 * The queue names its table through $wpdb->prepare()'s %i placeholder
 * (WordPress 6.2) instead of interpolating it, so that Plugin Check sees every
 * statement as prepared. A mistake there is a SQL error the higher-level tests
 * could mistake for "no rows", so each method is run here on its own and the
 * database is asked whether it complained.
 */
class Send4WC_Queue_Queries_Test extends Send4WC_Test_Case {

	/**
	 * Put a row on the queue and make it due.
	 *
	 * The queue only ever writes a row for the current connection, so a row of
	 * another status or another client is given it afterwards.
	 *
	 * @param int         $order_id  Order id; makes the event id unique.
	 * @param string      $status    Row status.
	 * @param string|null $client_id Owning client, null for the current connection.
	 * @return object The row.
	 */
	private function seed( $order_id, $status = Send4WC_Queue::STATUS_PENDING, $client_id = null ) {
		global $wpdb;

		$event_id = 'woo:' . $order_id . ':order.status.processing';

		$this->assertIsInt(
			Send4WC_Queue::enqueue(
				array(
					'event' => array(
						'id'          => $event_id,
						'topic'       => 'order.status.processing',
						'occurred_at' => '2026-11-10T09:00:00+00:00',
					),
					'order' => array( 'id' => (string) $order_id ),
					'full'  => true,
				),
				'order.status.processing'
			)
		);

		$row = Send4WC_Queue::find_by_event_id( $event_id, 'test-client-id' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Send4WC_Queue::table(),
			array(
				'status'     => $status,
				'client_id'  => null === $client_id ? 'test-client-id' : $client_id,
				'last_error' => 'Send4 returned status 500.',
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->makeDue( $row );

		return Send4WC_Queue::find_by_event_id( $event_id, null === $client_id ? 'test-client-id' : $client_id );
	}

	/**
	 * Set a row's timestamps, as if it were this old.
	 *
	 * @param object $row     Queue row.
	 * @param int    $seconds Age in seconds.
	 * @return void
	 */
	private function age( $row, $seconds ) {
		global $wpdb;

		$then = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Send4WC_Queue::table(),
			array(
				'created_at' => $then,
				'updated_at' => $then,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fail the test if the last statement was refused by the database.
	 *
	 * @param string $what Which call ran.
	 * @return void
	 */
	private function assertNoSqlError( $what ) {
		global $wpdb;

		$this->assertSame( '', (string) $wpdb->last_error, "{$what} made the database complain." );
	}

	/**
	 * The reads.
	 *
	 * @return void
	 */
	public function test_the_reads_run() {
		$this->assertFalse( Send4WC_Queue::has_pending() );
		$this->assertNoSqlError( 'has_pending, empty' );

		$first  = $this->seed( 9001 );
		$second = $this->seed( 9002, Send4WC_Queue::STATUS_FAILED );

		$this->assertTrue( Send4WC_Queue::has_pending() );
		$this->assertNoSqlError( 'has_pending' );

		$this->assertSame( (int) $first->id, (int) Send4WC_Queue::find_by_event_id( $first->event_id, 'test-client-id' )->id );
		$this->assertNoSqlError( 'find_by_event_id' );

		$this->assertNull( Send4WC_Queue::find_by_event_id( $first->event_id, 'someone-else' ), 'Another client\'s row is not this one.' );
		$this->assertNoSqlError( 'find_by_event_id, another client' );

		$this->assertSame( array( (int) $first->id ), array_map( 'intval', wp_list_pluck( Send4WC_Queue::due( 10 ), 'id' ) ) );
		$this->assertNoSqlError( 'due' );

		$this->assertSame( array( (int) $second->id, (int) $first->id ), array_map( 'intval', wp_list_pluck( Send4WC_Queue::all( 10 ), 'id' ) ) );
		$this->assertNoSqlError( 'all' );

		$this->assertSame( array( (int) $second->id ), array_map( 'intval', wp_list_pluck( Send4WC_Queue::after( (int) $first->id, 10 ), 'id' ) ) );
		$this->assertNoSqlError( 'after' );

		$counts = Send4WC_Queue::counts();
		$this->assertNoSqlError( 'counts' );
		$this->assertSame( 1, $counts[ Send4WC_Queue::STATUS_PENDING ] );
		$this->assertSame( 1, $counts[ Send4WC_Queue::STATUS_FAILED ] );
	}

	/**
	 * Claiming a row.
	 *
	 * @return void
	 */
	public function test_claim_runs() {
		$row = $this->seed( 9001 );

		$this->assertTrue( Send4WC_Queue::claim( $row ) );
		$this->assertNoSqlError( 'claim' );
		$this->assertFalse( Send4WC_Queue::claim( $row ), 'A claimed row cannot be claimed again.' );
	}

	/**
	 * Setting another connection's rows aside, and bringing dead letters back
	 * into line.
	 *
	 * @return void
	 */
	public function test_the_connection_updates_run() {
		$foreign = $this->seed( 9001, Send4WC_Queue::STATUS_PENDING, 'old-client' );

		$this->assertSame( 1, Send4WC_Queue::quarantine_foreign( 'test-client-id', 'Queued under another Send4 account.' ) );
		$this->assertNoSqlError( 'quarantine_foreign' );
		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, Send4WC_Queue::find_by_event_id( $foreign->event_id, 'old-client' )->status );

		$this->seed( 9002, Send4WC_Queue::STATUS_FAILED );
		$this->assertSame( 1, Send4WC_Queue::requeue( 'test-client-id' ) );
		$this->assertNoSqlError( 'requeue' );
	}

	/**
	 * The sweeps and the clear-out.
	 *
	 * @return void
	 */
	public function test_the_deletes_run() {
		$old_failed    = $this->seed( 9001, Send4WC_Queue::STATUS_FAILED );
		$old_permanent = $this->seed( 9002, Send4WC_Queue::STATUS_PERMANENT );
		$new_failed    = $this->seed( 9003, Send4WC_Queue::STATUS_FAILED );
		$old_pending   = $this->seed( 9004 );

		$this->age( $old_failed, 40 * DAY_IN_SECONDS );
		$this->age( $old_permanent, 40 * DAY_IN_SECONDS );
		$this->age( $old_pending, 40 * DAY_IN_SECONDS );

		$removed = Send4WC_Queue::purge_older_than( 30, array( Send4WC_Queue::STATUS_FAILED, Send4WC_Queue::STATUS_PERMANENT, Send4WC_Queue::STATUS_FAILED ) );
		$this->assertNoSqlError( 'purge_older_than' );
		$this->assertSame( 2, $removed, 'Both old dead letters, each status once, counted together.' );
		$this->assertNotNull( Send4WC_Queue::find_by_event_id( $new_failed->event_id, 'test-client-id' ), 'A recent failure stays.' );
		$this->assertNotNull( Send4WC_Queue::find_by_event_id( $old_pending->event_id, 'test-client-id' ), 'A status not asked for stays.' );

		$this->assertSame( 0, Send4WC_Queue::purge_older_than( 30, array() ) );

		$this->assertSame( 1, Send4WC_Queue::purge_stale_pending( 30 * DAY_IN_SECONDS ) );
		$this->assertNoSqlError( 'purge_stale_pending' );

		$this->seed( 9005 );
		Send4WC_Queue::purge( Send4WC_Queue::STATUS_FAILED );
		$this->assertNoSqlError( 'purge, one status' );
		$this->assertSame( array( Send4WC_Queue::STATUS_PENDING ), wp_list_pluck( $this->queueRows(), 'status' ) );

		Send4WC_Queue::purge();
		$this->assertNoSqlError( 'purge, everything' );
		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * The schema statements: the table carries its one-row-per-event-per-client
	 * key, drops, and comes back.
	 *
	 * @return void
	 */
	public function test_the_schema_statements_run() {
		global $wpdb;

		// WP_UnitTestCase rewrites CREATE/DROP TABLE into their TEMPORARY forms,
		// under which dropping the real table is a silent no-op. Lifted for this
		// test only, as UninstallTest does; tear_down() puts them back.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$table = Send4WC_Queue::table();

		try {
			Send4WC_Queue::install();
			$this->assertNoSqlError( 'install, over an existing table' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$key = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'event_client' ) );
			$this->assertNoSqlError( 'SHOW INDEX' );
			$this->assertSame( array( 'event_id', 'client_id' ), wp_list_pluck( $key, 'Column_name' ), 'One row per event per client.' );
			$this->assertSame( '0', (string) $key[0]->Non_unique );

			Send4WC_Queue::drop();
			$this->assertNoSqlError( 'drop' );
			$this->assertFalse( Send4WC_Queue::table_exists() );
		} finally {
			Send4WC_Queue::install();
		}

		$this->assertTrue( Send4WC_Queue::table_exists() );
		$this->assertNotNull( $this->seed( 9001 ) );
	}
}
