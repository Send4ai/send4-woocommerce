<?php
/**
 * The schema version, what it promises, and the events a site keeps.
 *
 * @package Send4WC
 */

/**
 * send4wc_db_version is what stops maybe_upgrade() from running dbDelta on
 * every request, so it must only ever claim a table that really exists, and,
 * being read on every request, it must come out of the autoloaded options.
 *
 * The events are the hourly store sync, which refreshes the order statuses
 * Send4 wants and re-arms the queue, and the every-minute queue event, which
 * only exists while an order event is waiting.
 */
class Send4WC_Installer_Test extends Send4WC_Test_Case {

	/**
	 * Start every test with no events of ours.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Send4WC_Cron::unschedule();
		Send4WC_Store_Sync::unschedule();
	}

	/**
	 * Put the version back the way the bootstrap left it.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( Send4WC_Installer::DB_VERSION_OPTION, Send4WC_Installer::DB_VERSION, true );

		Send4WC_Cron::unschedule();
		Send4WC_Store_Sync::unschedule();

		parent::tear_down();
	}

	/**
	 * Make the queue table look missing, and every attempt to create it fail.
	 *
	 * Nothing is really dropped: SHOW TABLES is pointed at a name that does not
	 * exist, and CREATE is swallowed, which also keeps WP_UnitTestCase's
	 * CREATE TEMPORARY TABLE rewrite from shadowing the real table for the rest
	 * of the run.
	 *
	 * @return void
	 */
	private function breakTheTable() {
		add_filter(
			'query',
			static function ( $query ) {
				$trimmed = ltrim( (string) $query );

				if ( 0 === stripos( $trimmed, 'CREATE' ) ) {
					return '';
				}

				// esc_like() has escaped the underscores by now: send4wc\_queue.
				if ( 0 === stripos( $trimmed, 'SHOW TABLES LIKE' ) && false !== strpos( $trimmed, 'send4wc' ) ) {
					return "SHOW TABLES LIKE 'send4wc_no_such_table'";
				}

				return $query;
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Make the next $failures inserts into the queue table fail.
	 *
	 * @param int $failures How many to fail; PHP_INT_MAX for all of them.
	 * @return void
	 */
	private function failQueueInserts( $failures ) {
		add_filter(
			'query',
			static function ( $query ) use ( &$failures ) {
				if ( $failures > 0 && 0 === stripos( ltrim( (string) $query ), 'INSERT INTO `' . Send4WC_Queue::table() . '`' ) ) {
					--$failures;

					return '';
				}

				return $query;
			}
		);
	}

	/**
	 * A create that failed must be retried on the next request, which only
	 * happens while the version is not recorded. Recording it anyway would
	 * leave the store with no queue for good, and every order event that
	 * needed one lost.
	 *
	 * @return void
	 */
	public function test_the_version_is_not_recorded_when_the_table_is_missing() {
		delete_option( Send4WC_Installer::DB_VERSION_OPTION );

		$this->breakTheTable();

		Send4WC_Installer::install();

		$this->assertFalse( get_option( Send4WC_Installer::DB_VERSION_OPTION, false ) );
	}

	/**
	 * With the table there, the version is recorded as usual.
	 *
	 * @return void
	 */
	public function test_the_version_is_recorded_once_the_table_exists() {
		delete_option( Send4WC_Installer::DB_VERSION_OPTION );

		Send4WC_Installer::install();

		$this->assertSame( 1, Send4WC_Installer::DB_VERSION );
		$this->assertSame( Send4WC_Installer::DB_VERSION, (int) get_option( Send4WC_Installer::DB_VERSION_OPTION ) );
	}

	/**
	 * Read on every request by maybe_upgrade(), so autoloaded: not
	 * autoloading it would cost a query per page view.
	 *
	 * @return void
	 */
	public function test_the_version_is_autoloaded() {
		global $wpdb;

		delete_option( Send4WC_Installer::DB_VERSION_OPTION );

		Send4WC_Installer::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Send4WC_Installer::DB_VERSION_OPTION )
		);

		$autoloaded = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );

		$this->assertSame( Send4WC_Installer::DB_VERSION, (int) get_option( Send4WC_Installer::DB_VERSION_OPTION ) );
		$this->assertContains( $autoload, $autoloaded );
	}

	/**
	 * A current version means a normal request runs no dbDelta at all.
	 *
	 * @return void
	 */
	public function test_a_current_install_is_not_touched_on_a_normal_request() {
		$queries = array();

		$spy = static function ( $query ) use ( &$queries ) {
			$queries[] = (string) $query;

			return $query;
		};

		add_filter( 'query', $spy );
		Send4WC_Installer::maybe_upgrade();
		remove_filter( 'query', $spy );

		$this->assertSame( array(), $queries );
	}

	/**
	 * A row is unique per order event and Send4 account: the same order
	 * reaching the same status twice before it is sent is one delivery, and a
	 * row queued under another account is never mistaken for this one's.
	 *
	 * @return void
	 */
	public function test_the_queue_is_unique_per_event_and_client() {
		global $wpdb;

		$table = Send4WC_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$unique  = array();

		foreach ( $indexes as $index ) {
			if ( 0 === (int) $index->Non_unique ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$unique[ $index->Key_name ][] = $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		$this->assertSame( array( 'event_id', 'client_id' ), $unique['event_client'] );
		$this->assertArrayNotHasKey( 'event_id', $unique, 'A key on the event alone would refuse the new account\'s row.' );
	}

	/**
	 * The columns the queue writes. Send4's receipt is per API client, so a
	 * row says which client it was queued under; sent under another it would
	 * be an order in somebody else's workspace.
	 *
	 * @return void
	 */
	public function test_the_queue_has_the_columns_it_writes() {
		global $wpdb;

		$table = Send4WC_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE {$table}" );

		foreach ( array( 'event_id', 'topic', 'client_id', 'envelope', 'attempts', 'status', 'next_attempt_at', 'last_error' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	/**
	 * If the table is missing when an order event needs it, the order path
	 * creates it and queues the event, rather than losing it.
	 *
	 * @return void
	 */
	public function test_a_failed_enqueue_installs_the_table_and_tries_once_more() {
		$order = $this->makeOrder();
		$topic = 'order.status.processing';

		$this->failQueueInserts( 1 );

		$this->assertTrue( Send4WC_Delivery::queue( Send4WC_Order_Payload::reference( $order, $topic, true ), $topic ) );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( $topic, $row->topic );
		$this->assertSame( 0, $this->http->requestCount(), 'Queueing never waits on the network.' );
	}

	/**
	 * And when even that fails, the shop manager is told the truth: the order
	 * event was not queued and is lost.
	 *
	 * @return void
	 */
	public function test_an_order_event_that_cannot_be_queued_is_reported_as_failed() {
		$order = $this->makeOrder();
		$topic = 'order.status.processing';

		Send4WC_Logger::clear();
		$this->failQueueInserts( PHP_INT_MAX );

		$this->assertFalse( Send4WC_Delivery::queue( Send4WC_Order_Payload::reference( $order, $topic, true ), $topic ) );

		$this->assertSame( array(), $this->queueRows() );

		$status = Send4WC_Settings::delivery_status();
		$this->assertSame( 'failed', $status['status'] );
		$this->assertStringContainsString( 'could not be queued', $status['error'] );

		$entry = Send4WC_Logger::entries()[0];

		$this->assertSame( 'error', $entry['level'] );
		$this->assertStringContainsString( 'could not be queued', $entry['message'] );
		$this->assertSame( $topic, $entry['context']['topic'] );
	}

	/**
	 * Activation schedules the hourly store sync. The every-minute queue event
	 * is left out while nothing waits, or an idle store would wake WP-Cron
	 * every minute for nothing.
	 *
	 * @return void
	 */
	public function test_activation_schedules_the_hourly_sync_and_no_idle_queue_event() {
		Send4WC_Installer::activate();

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Store_Sync::HOOK ) );
		$this->assertSame( 'hourly', wp_get_schedule( Send4WC_Store_Sync::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
	}

	/**
	 * Deactivating dropped the queue event, so order events still waiting from
	 * before then get it back at activation rather than at the next sync.
	 *
	 * @return void
	 */
	public function test_activation_brings_the_queue_event_back_for_waiting_order_events() {
		$this->queueEvent( $this->makeOrder() );
		Send4WC_Cron::unschedule();

		Send4WC_Installer::activate();

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
		$this->assertSame( Send4WC_Cron::SCHEDULE, wp_get_schedule( Send4WC_Cron::HOOK ) );
	}

	/**
	 * Deactivating clears every event the plugin schedules, the one-off push
	 * after a category change included, and keeps the data: that is
	 * uninstall.php's to remove.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_every_event_and_keeps_the_data() {
		$this->queueEvent( $this->makeOrder() );
		Send4WC_Store_Sync::ensure_scheduled();
		Send4WC_Store_Sync::defer_push();

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Send4WC_Store_Sync::PUSH_HOOK ) );

		Send4WC_Installer::deactivate();

		$this->assertFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Send4WC_Store_Sync::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Send4WC_Store_Sync::PUSH_HOOK ) );

		$this->assertCount( 1, $this->queueRows(), 'The waiting order event is kept for reactivation.' );
		$this->assertTrue( Send4WC_Settings::has_credentials() );
	}

	/**
	 * ensure_events() runs on every init, so it must not add a second hourly
	 * event, and it never adds the queue event by itself.
	 *
	 * @return void
	 */
	public function test_ensuring_the_events_twice_schedules_them_once() {
		Send4WC_Installer::ensure_events();
		Send4WC_Installer::ensure_events();

		$crons = _get_cron_array();
		$count = 0;

		foreach ( $crons as $events ) {
			if ( isset( $events[ Send4WC_Store_Sync::HOOK ] ) ) {
				$count += count( $events[ Send4WC_Store_Sync::HOOK ] );
			}
		}

		$this->assertSame( 1, $count );
		$this->assertFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
	}

	/**
	 * The plugin hooks ensure_events() on init, never earlier: scheduling runs
	 * the `cron_schedules` filter, whose display names are translated.
	 *
	 * @return void
	 */
	public function test_the_events_are_ensured_on_init() {
		$this->assertNotFalse( has_action( 'init', array( send4wc(), 'on_init' ) ) );
		$this->assertFalse( has_action( 'plugins_loaded', array( 'Send4WC_Installer', 'ensure_events' ) ) );
	}
}
