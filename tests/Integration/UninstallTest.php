<?php
/**
 * Uninstall: everything the plugin created has to go, and nothing the forms
 * plugin created may.
 *
 * @package Send4WC
 */

/**
 * Deactivating keeps the queue and the connection; uninstalling does not. The
 * connection holds the store's client secret and the token cache a bearer
 * token for its Send4 account, and a credential that outlives the plugin is
 * one the store owner has no interface left to find or revoke.
 *
 * uninstall.php runs in a WordPress that has not loaded the plugin, so it
 * cannot use the class constants and re-declares every option name as a
 * string literal. That duplication is one failure mode this file is about: a
 * renamed option would leave its data behind, silently, on every store that
 * ever removes the plugin. So the literals are checked against the constants,
 * and the run itself is checked against "nothing called send4wc is left".
 *
 * The other is the forms plugin `send4`, which may run on the same site. Its
 * options and transients start with `send4_`, one letter short of ours, and
 * removing this plugin must not disconnect its forms.
 */
class Send4WC_Uninstall_Test extends Send4WC_Test_Case {

	/**
	 * Another plugin's transient, one character away from ours: a hyphen where
	 * we have an underscore, which is exactly what an unescaped LIKE lets through.
	 */
	const NEIGHBOURS_TRANSIENT = 'send4wc-widget_cache';

	/**
	 * The forms plugin's connection, as that plugin stores it.
	 */
	const FORMS_PLUGIN_OPTION = 'send4_connection';

	/**
	 * The forms plugin's handshake cache, as that plugin stores it.
	 */
	const FORMS_PLUGIN_TRANSIENT = 'send4_handshake';

	/**
	 * Let this class issue real DDL.
	 *
	 * WP_UnitTestCase rewrites every CREATE TABLE and DROP TABLE into its
	 * TEMPORARY form, so that a test cannot damage the schema the rest of the
	 * run depends on. Under that rewrite `DROP TABLE IF EXISTS` against a real
	 * table is a silent no-op, which would make this whole file pass while
	 * uninstall.php dropped nothing at all. The rewrites are lifted here and
	 * WP_UnitTestCase::tear_down() puts them back by itself.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->assertTrue( Send4WC_Queue::table_exists(), 'The suite should start with a queue table.' );
	}

	/**
	 * Put the queue table back.
	 *
	 * The drop was real and MySQL commits the surrounding transaction on the way
	 * through DDL, so the rollback in tear_down cannot undo it, and every later
	 * test needs somewhere to put a queue row.
	 *
	 * The same commit keeps what uninstalling is meant to leave alone, the
	 * neighbour's transient and the forms plugin's rows, so they are taken out
	 * by hand. Here rather than in their tests, so that a failed assertion
	 * there cannot leave them for the next one. The CREATE below commits the
	 * removals, and the schema version install() records after it is
	 * committed by hand: the rollback would otherwise undo it, and every
	 * later test would see a store whose schema is unrecorded.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		wp_using_ext_object_cache( false );

		delete_transient( self::NEIGHBOURS_TRANSIENT );
		delete_transient( self::FORMS_PLUGIN_TRANSIENT );
		delete_option( self::FORMS_PLUGIN_OPTION );

		Send4WC_Installer::install();

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		parent::tear_down();
	}

	/**
	 * Run the uninstaller the way WordPress does.
	 *
	 * The file is included once per process — it declares a function — so later
	 * tests in this class call that function directly. Both routes run the same
	 * code; only the first proves the file's top-level guard and dispatch work.
	 *
	 * @return void
	 */
	private function runUninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'send4-for-woocommerce/send4-for-woocommerce.php' );
		}

		if ( function_exists( 'send4wc_uninstall_current_site' ) ) {
			send4wc_uninstall_current_site();

			return;
		}

		require_once dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * What the queue keeps for one order event, without an order behind it.
	 *
	 * A real order would outlive the test: the DDL in uninstall.php commits it.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	private function reference( $order_id ) {
		return array(
			'event' => array(
				'id'          => 'woo:' . $order_id . ':order.status.processing',
				'topic'       => 'order.status.processing',
				'occurred_at' => gmdate( 'c' ),
			),
			'order' => array( 'id' => (string) $order_id ),
			'full'  => true,
		);
	}

	/**
	 * A store that has been used: credentials, the order statuses Send4 wants,
	 * a delivery outcome, a store sync that landed, a log, a cached token and
	 * handshake, a sign-in cooldown, a queued order event with its async
	 * action, and every scheduled event.
	 *
	 * @return void
	 */
	private function seedAWorkingSite() {
		update_option( Send4WC_Settings::BASE_URL_OPTION, 'https://staging.send4.ai', false );

		Send4WC_Settings::record_delivery( 'delivered' );
		Send4WC_Settings::record_store_sync();
		Send4WC_Logger::log( 'info', 'Order event delivered to Send4.' );

		set_transient(
			Send4WC_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'a-real-looking-token',
				'expires_at' => time() + DAY_IN_SECONDS,
				'bound_to'   => 'whatever',
			),
			DAY_IN_SECONDS
		);
		set_transient( Send4WC_Api_Client::SIGN_IN_COOLDOWN, array( 'until' => time() + 30 ), 30 );
		set_transient( Send4WC_Admin::HANDSHAKE_CACHE, array( 'client' => array( 'name' => 'Baldai UAB' ) ), 300 );

		// Queues the row, the every-minute event and the async action.
		$this->assertTrue( Send4WC_Delivery::queue( $this->reference( 1001 ), 'order.status.processing' ) );

		Send4WC_Store_Sync::ensure_scheduled();
		Send4WC_Store_Sync::defer_push();

		// Everything above really is there, or the test below proves nothing.
		$this->assertNotEmpty( get_option( Send4WC_Settings::CONNECTION_OPTION ) );
		$this->assertNotEmpty( get_option( Send4WC_Settings::TOPICS_OPTION ) );
		$this->assertNotEmpty( get_option( Send4WC_Settings::STATUS_OPTION ) );
		$this->assertNotEmpty( get_option( Send4WC_Settings::SYNC_OPTION ) );
		$this->assertNotEmpty( get_option( Send4WC_Settings::BASE_URL_OPTION ) );
		$this->assertNotEmpty( get_option( Send4WC_Logger::OPTION ) );
		$this->assertSame( Send4WC_Installer::DB_VERSION, (int) get_option( Send4WC_Installer::DB_VERSION_OPTION ) );

		$rows = $this->leftoverOptionNames();
		$this->assertContains( '_transient_' . Send4WC_Api_Client::TOKEN_TRANSIENT, $rows );
		$this->assertContains( '_transient_' . Send4WC_Api_Client::SIGN_IN_COOLDOWN, $rows );
		$this->assertContains( '_transient_' . Send4WC_Admin::HANDSHAKE_CACHE, $rows );

		$this->assertCount( 1, $this->queueRows() );
		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Send4WC_Store_Sync::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Send4WC_Store_Sync::PUSH_HOOK ) );
		$this->assertNotFalse( as_has_scheduled_action( Send4WC_Delivery::ASYNC_HOOK, array(), 'send4wc' ) );
	}

	/**
	 * Plant the forms plugin's connection and its handshake cache.
	 *
	 * @return void
	 */
	private function seedTheFormsPlugin() {
		update_option(
			self::FORMS_PLUGIN_OPTION,
			array(
				'client_id'     => 'forms-client-id',
				'client_secret' => 'forms-client-secret',
				'client_name'   => 'Stogas UAB',
			),
			false
		);
		set_transient( self::FORMS_PLUGIN_TRANSIENT, array( 'client' => array( 'name' => 'Stogas UAB' ) ), 300 );
	}

	/**
	 * Option names still in the database that look like ours.
	 *
	 * Catches the case the literal-versus-constant check below cannot: an
	 * option the plugin started writing after uninstall.php was last edited.
	 * Rows, not get_option(): a direct DELETE leaves the object cache behind.
	 *
	 * @return array<int,string>
	 */
	private function leftoverOptionNames() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'%' . $wpdb->esc_like( 'send4wc' ) . '%'
			)
		);

		return is_array( $names ) ? $names : array();
	}

	/**
	 * One option's stored value, read from the table rather than the cache.
	 *
	 * @param string $name Option name.
	 * @return mixed Null when there is no such row.
	 */
	private function storedOption( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $value ? null : maybe_unserialize( $value );
	}

	/**
	 * The whole thing, through the file WordPress actually runs.
	 *
	 * @return void
	 */
	public function test_it_removes_every_option_transient_row_and_event() {
		$this->seedAWorkingSite();

		$this->runUninstall();

		$this->assertFalse( get_option( Send4WC_Settings::CONNECTION_OPTION, false ) );
		$this->assertFalse( get_option( Send4WC_Settings::TOPICS_OPTION, false ) );
		$this->assertFalse( get_option( Send4WC_Settings::STATUS_OPTION, false ) );
		$this->assertFalse( get_option( Send4WC_Settings::SYNC_OPTION, false ) );
		$this->assertFalse( get_option( Send4WC_Settings::BASE_URL_OPTION, false ) );
		$this->assertFalse( get_option( Send4WC_Logger::OPTION, false ) );
		$this->assertFalse( get_option( Send4WC_Installer::DB_VERSION_OPTION, false ) );

		$this->assertFalse(
			wp_next_scheduled( Send4WC_Cron::HOOK ),
			'A cron event left behind fires for ever against a plugin that is gone.'
		);
		$this->assertFalse( wp_next_scheduled( Send4WC_Store_Sync::HOOK ), 'The hourly store sync must go with the plugin too.' );
		$this->assertFalse( wp_next_scheduled( Send4WC_Store_Sync::PUSH_HOOK ), 'And the one-off push after a category change.' );
		$this->assertFalse(
			as_has_scheduled_action( Send4WC_Delivery::ASYNC_HOOK, array(), 'send4wc' ),
			'Action Scheduler would run send4wc_send_now against nothing.'
		);

		$this->assertFalse(
			Send4WC_Queue::table_exists(),
			'The queue table must not outlive the plugin.'
		);

		$this->assertSame(
			array(),
			$this->leftoverOptionNames(),
			'Something the plugin wrote, a transient included, is still in wp_options after uninstall.'
		);
	}

	/**
	 * The forms plugin keeps its own connection, and its forms keep sending,
	 * when this plugin is removed from the same site. Its names are one letter
	 * away from ours, so a pattern a little too wide would take them.
	 *
	 * @return void
	 */
	public function test_it_leaves_the_forms_plugin_alone() {
		$this->seedTheFormsPlugin();
		$this->seedAWorkingSite();

		$connection = $this->storedOption( self::FORMS_PLUGIN_OPTION );

		$this->runUninstall();

		$this->assertSame( $connection, $this->storedOption( self::FORMS_PLUGIN_OPTION ), 'The forms plugin was disconnected.' );
		$this->assertNotNull( $this->storedOption( '_transient_' . self::FORMS_PLUGIN_TRANSIENT ), 'The forms plugin\'s handshake cache was swept.' );
		$this->assertNotNull( $this->storedOption( '_transient_timeout_' . self::FORMS_PLUGIN_TRANSIENT ) );
	}

	/**
	 * One transient is named at run time, a pending notice per user, so
	 * uninstall.php cannot list it and sweeps the prefix instead.
	 *
	 * The sweep is a LIKE, where an unescaped "_" matches any character. The
	 * neighbour below is what a sloppier pattern would take with it.
	 *
	 * @return void
	 */
	public function test_it_sweeps_up_the_transients_it_cannot_name() {
		$notice = Send4WC_Admin::NOTICE_PREFIX . '7';

		set_transient(
			$notice,
			array(
				'type'    => 'success',
				'message' => 'Synced.',
			),
			Send4WC_Admin::NOTICE_CACHE_S
		);
		set_transient( self::NEIGHBOURS_TRANSIENT, 1, DAY_IN_SECONDS );

		// Rows, not get_transient(): that would answer from the object cache.
		$this->assertContains( '_transient_' . $notice, $this->leftoverOptionNames() );
		$this->assertContains( '_transient_timeout_' . $notice, $this->leftoverOptionNames() );

		$this->runUninstall();

		$left = $this->leftoverOptionNames();
		sort( $left );

		$this->assertSame(
			array(
				'_transient_' . self::NEIGHBOURS_TRANSIENT,
				'_transient_timeout_' . self::NEIGHBOURS_TRANSIENT,
			),
			$left,
			'Every transient of ours must go, and nothing that only looks like one.'
		);
	}

	/**
	 * Behind a persistent object cache, the kind many WooCommerce hosts run,
	 * a transient never reaches wp_options, so the prefix sweep cannot see it.
	 * The access token is a bearer credential for the store's Send4 account
	 * and lives a day, so it has to be deleted by name like the forms plugin
	 * deletes its own, not left to expire.
	 *
	 * @return void
	 */
	public function test_behind_a_persistent_object_cache_the_access_token_goes_too() {
		wp_using_ext_object_cache( true );

		set_transient(
			Send4WC_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'a-real-looking-token',
				'expires_at' => time() + DAY_IN_SECONDS,
				'bound_to'   => 'whatever',
			),
			DAY_IN_SECONDS
		);

		$this->assertNotFalse( get_transient( Send4WC_Api_Client::TOKEN_TRANSIENT ) );

		$this->runUninstall();

		$this->assertFalse(
			get_transient( Send4WC_Api_Client::TOKEN_TRANSIENT ),
			'The bearer token is still in the object cache after uninstall.'
		);
	}

	/**
	 * Uninstalling a store that never got as far as connecting is a no-op,
	 * not a warning or a fatal.
	 *
	 * @return void
	 */
	public function test_it_is_safe_on_a_site_that_was_never_used() {
		Send4WC_Settings::disconnect();
		Send4WC_Queue::drop();

		$this->runUninstall();

		$this->assertFalse( Send4WC_Queue::table_exists() );
		$this->assertSame( array(), $this->leftoverOptionNames() );
	}

	/**
	 * Every name uninstall.php spells out has to be the name the plugin uses.
	 *
	 * @dataProvider ownedNameProvider
	 *
	 * @param string $name  The literal that must appear.
	 * @param string $where Which constant it mirrors, for the failure message.
	 * @return void
	 */
	public function test_the_literals_match_the_constants( $name, $where ) {
		$this->assertStringContainsString(
			"'" . $name . "'",
			$this->uninstallSource(),
			'uninstall.php does not mention ' . $name . ', which is ' . $where . '.'
		);
	}

	/**
	 * Every stored name the plugin owns, and where it is defined.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function ownedNameProvider() {
		return array(
			'the connection'        => array( Send4WC_Settings::CONNECTION_OPTION, 'Send4WC_Settings::CONNECTION_OPTION' ),
			'the order statuses'    => array( Send4WC_Settings::TOPICS_OPTION, 'Send4WC_Settings::TOPICS_OPTION' ),
			'the delivery status'   => array( Send4WC_Settings::STATUS_OPTION, 'Send4WC_Settings::STATUS_OPTION' ),
			'the store sync'        => array( Send4WC_Settings::SYNC_OPTION, 'Send4WC_Settings::SYNC_OPTION' ),
			'the activity log'      => array( Send4WC_Logger::OPTION, 'Send4WC_Logger::OPTION' ),
			'the schema version'    => array( Send4WC_Installer::DB_VERSION_OPTION, 'Send4WC_Installer::DB_VERSION_OPTION' ),
			'the base URL'          => array( Send4WC_Settings::BASE_URL_OPTION, 'Send4WC_Settings::BASE_URL_OPTION' ),
			'the queue event'       => array( Send4WC_Cron::HOOK, 'Send4WC_Cron::HOOK' ),
			'the hourly sync event' => array( Send4WC_Store_Sync::HOOK, 'Send4WC_Store_Sync::HOOK' ),
			'the one-off push'      => array( Send4WC_Store_Sync::PUSH_HOOK, 'Send4WC_Store_Sync::PUSH_HOOK' ),
			'the async action'      => array( Send4WC_Delivery::ASYNC_HOOK, 'Send4WC_Delivery::ASYNC_HOOK' ),
		);
	}

	/**
	 * The sweep's pattern is `send4wc_` with every underscore escaped, so it
	 * takes ours and neither the forms plugin's `send4_` nor a neighbour's
	 * `send4wc-`.
	 *
	 * @return void
	 */
	public function test_the_sweep_matches_exactly_the_send4wc_prefix() {
		$source = $this->uninstallSource();

		$this->assertStringContainsString( "LIKE '\\\\_transient\\\\_send4wc\\\\_%'", $source );
		$this->assertStringContainsString( "LIKE '\\\\_transient\\\\_timeout\\\\_send4wc\\\\_%'", $source );
	}

	/**
	 * The transients are swept by prefix rather than named, so each has to
	 * carry the prefix the sweep matches.
	 *
	 * @dataProvider sweptTransientProvider
	 *
	 * @param string $name  Transient name or prefix.
	 * @param string $where Which constant it is, for the failure message.
	 * @return void
	 */
	public function test_every_transient_is_under_the_swept_prefix( $name, $where ) {
		$this->assertStringStartsWith( 'send4wc_', $name, $where . ' would be missed by the sweep.' );
	}

	/**
	 * Every transient the plugin writes.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function sweptTransientProvider() {
		return array(
			'the access token'     => array( Send4WC_Api_Client::TOKEN_TRANSIENT, 'Send4WC_Api_Client::TOKEN_TRANSIENT' ),
			'the sign-in cooldown' => array( Send4WC_Api_Client::SIGN_IN_COOLDOWN, 'Send4WC_Api_Client::SIGN_IN_COOLDOWN' ),
			'the handshake cache'  => array( Send4WC_Admin::HANDSHAKE_CACHE, 'Send4WC_Admin::HANDSHAKE_CACHE' ),
			'the notices'          => array( Send4WC_Admin::NOTICE_PREFIX, 'Send4WC_Admin::NOTICE_PREFIX' ),
		);
	}

	/**
	 * And the table it drops has to be the table the plugin writes to.
	 *
	 * @return void
	 */
	public function test_it_drops_the_table_the_queue_actually_uses() {
		global $wpdb;

		$suffix = substr( Send4WC_Queue::table(), strlen( $wpdb->prefix ) );

		$this->assertSame( 'send4wc_queue', $suffix );
		$this->assertStringContainsString(
			"\$wpdb->prefix . '" . $suffix . "'",
			$this->uninstallSource(),
			'uninstall.php drops a different table from the one Send4WC_Queue::table() names.'
		);
	}

	/**
	 * A network install must not leave one subsite's store behind.
	 *
	 * @return void
	 */
	public function test_it_walks_every_site_on_a_network() {
		$source = $this->uninstallSource();

		$this->assertStringContainsString( 'is_multisite()', $source );
		$this->assertStringContainsString( 'get_sites(', $source );
		$this->assertStringContainsString( 'switch_to_blog(', $source );
		$this->assertStringContainsString( 'restore_current_blog();', $source );
	}

	/**
	 * uninstall.php, as text.
	 *
	 * @return string
	 */
	private function uninstallSource() {
		$path = dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
