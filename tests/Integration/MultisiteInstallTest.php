<?php
/**
 * Network activation: every subsite gets a queue table, or it loses order
 * events.
 *
 * @package Send4WC
 */

/**
 * This is the only reason Send4WC_Installer has any multisite code at all.
 *
 * register_activation_hook fires once, for whichever site happened to be
 * current when the network administrator clicked Activate. Without the loop,
 * exactly one subsite ends up with {prefix}send4wc_queue and every other
 * store on the network silently loses each order event it tries to queue,
 * silently because Send4WC_Delivery is written never to break a checkout. The
 * same goes for a subsite created afterwards, which is what on_new_site is.
 *
 * The suite runs single-site by default, so these are skipped there and run in
 * the multisite leg of the phpunit job.
 */
class Send4WC_Multisite_Install_Test extends Send4WC_Test_Case {

	/**
	 * Sites this test created, newest last.
	 *
	 * @var int[]
	 */
	private $created_sites = array();

	/**
	 * What active_sitewide_plugins held before the test.
	 *
	 * @var mixed
	 */
	private $network_plugins_before = null;

	/**
	 * Skip off a network, and let this class issue real DDL.
	 *
	 * WP_UnitTestCase rewrites CREATE TABLE into CREATE TEMPORARY TABLE, and
	 * SHOW TABLES does not list temporary tables, so under the rewrite
	 * Send4WC_Queue::table_exists() answers false however well the installer
	 * worked. Creating a site creates real tables anyway, so the rewrite would
	 * only ever hide the plugin's own.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network activation can only be tested on multisite; run the suite with WP_TESTS_MULTISITE=1.' );
		}

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->dropOrphanedSubsiteTables();

		$this->network_plugins_before = get_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * Remove subsite queue tables a crashed earlier run left behind.
	 *
	 * The tables here are real, and subsite ids restart at 2 every time the test
	 * database is reinstalled, so one aborted run would otherwise hand the next
	 * one a brand-new site that already has a queue table, and the assertions
	 * about who created it would quietly stop meaning anything.
	 *
	 * The main site's table has no numeric segment, so it does not match.
	 *
	 * @return void
	 */
	private function dropOrphanedSubsiteTables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphans = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' . $wpdb->esc_like( '_send4wc_queue' ) )
		);

		foreach ( (array) $orphans as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Take the network back to how it was: the sites and their tables are real,
	 * so nothing here is undone by the transaction rollback.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		foreach ( array_reverse( $this->created_sites ) as $site_id ) {
			switch_to_blog( $site_id );
			Send4WC_Queue::drop();
			restore_current_blog();

			wp_delete_site( $site_id );
		}

		$this->created_sites = array();

		if ( is_multisite() ) {
			if ( false === $this->network_plugins_before ) {
				delete_site_option( 'active_sitewide_plugins' );
			} else {
				update_site_option( 'active_sitewide_plugins', $this->network_plugins_before );
			}

			/*
			 * Creating a site creates tables, and MySQL commits the open
			 * transaction on the way through DDL, so the wp_blogs row survives
			 * the rollback while the tables do not. Committing the deletions is
			 * what stops the next test finding a half-deleted site: without it
			 * the row comes back, and Send4WC_Installer::activate() then switches
			 * to a blog whose options table is gone.
			 */
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		parent::tear_down();
	}

	/**
	 * Add a subsite to the network.
	 *
	 * @param string $path Path below the network root.
	 * @return int Blog id.
	 */
	private function createSite( $path ) {
		$site_id = wp_insert_site(
			array(
				'domain'     => WP_TESTS_DOMAIN,
				'path'       => $path,
				'title'      => 'Subsite ' . trim( $path, '/' ),
				'network_id' => get_current_network_id(),
				'user_id'    => 1,
			)
		);

		$this->assertIsInt( $site_id, 'Could not create the subsite.' );

		$this->created_sites[] = $site_id;

		return $site_id;
	}

	/**
	 * Whether one site has a queue table.
	 *
	 * @param int $site_id Blog id.
	 * @return bool
	 */
	private function siteHasQueueTable( $site_id ) {
		switch_to_blog( $site_id );

		$exists = Send4WC_Queue::table_exists();

		restore_current_blog();

		return $exists;
	}

	/**
	 * Tell WordPress the plugin is activated across the network.
	 *
	 * @return void
	 */
	private function markNetworkActive() {
		update_site_option(
			'active_sitewide_plugins',
			array( plugin_basename( SEND4WC_PLUGIN_FILE ) => time() )
		);

		$this->assertTrue( is_plugin_active_for_network( plugin_basename( SEND4WC_PLUGIN_FILE ) ) );
	}

	/**
	 * A network activation reaches every subsite, not just the current one:
	 * each gets its queue table, its schema version and its hourly sync.
	 *
	 * @return void
	 */
	public function test_a_network_activation_installs_on_every_subsite() {
		$one = $this->createSite( '/one/' );
		$two = $this->createSite( '/two/' );

		// Neither has one yet, so the assertions below are about activate().
		$this->assertFalse( $this->siteHasQueueTable( $one ) );
		$this->assertFalse( $this->siteHasQueueTable( $two ) );

		Send4WC_Installer::activate( true );

		foreach ( array( get_current_blog_id(), $one, $two ) as $site_id ) {
			switch_to_blog( $site_id );

			$this->assertTrue(
				Send4WC_Queue::table_exists(),
				'Site ' . $site_id . ' has no queue table, so it would lose its order events the moment Send4 was unreachable.'
			);
			$this->assertSame(
				Send4WC_Installer::DB_VERSION,
				(int) get_option( Send4WC_Installer::DB_VERSION_OPTION ),
				'Site ' . $site_id . ' does not know which schema it has.'
			);
			$this->assertNotFalse(
				wp_next_scheduled( Send4WC_Store_Sync::HOOK ),
				'Site ' . $site_id . ' has no hourly sync, so nothing would refresh its order statuses or re-arm its queue.'
			);

			restore_current_blog();
		}
	}

	/**
	 * A network deactivation reaches every subsite too. The hook fires once,
	 * so without the loop every other subsite kept its events and went on
	 * waking WP-Cron for a plugin that was no longer running there.
	 *
	 * @return void
	 */
	public function test_a_network_deactivation_clears_every_subsites_events() {
		$one = $this->createSite( '/one/' );
		$two = $this->createSite( '/two/' );

		Send4WC_Installer::activate( true );

		$sites = array( get_current_blog_id(), $one, $two );

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			// As a store with an order event waiting and a category just edited would have it.
			Send4WC_Cron::ensure_scheduled();
			wp_schedule_single_event( time() + Send4WC_Store_Sync::PUSH_DELAY, Send4WC_Store_Sync::PUSH_HOOK );
			restore_current_blog();
		}

		Send4WC_Installer::deactivate( true );

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );

			$this->assertFalse( wp_next_scheduled( Send4WC_Cron::HOOK ), 'Site ' . $site_id . ' kept its queue event.' );
			$this->assertFalse( wp_next_scheduled( Send4WC_Store_Sync::HOOK ), 'Site ' . $site_id . ' kept its hourly sync.' );
			$this->assertFalse( wp_next_scheduled( Send4WC_Store_Sync::PUSH_HOOK ), 'Site ' . $site_id . ' kept its store push.' );

			restore_current_blog();
		}
	}

	/**
	 * The tables are per site, so one store's queue is not another's.
	 *
	 * @return void
	 */
	public function test_each_subsite_gets_a_table_of_its_own() {
		$one = $this->createSite( '/one/' );

		Send4WC_Installer::activate( true );

		switch_to_blog( $one );
		$subsite_table = Send4WC_Queue::table();
		restore_current_blog();

		$this->assertNotSame( Send4WC_Queue::table(), $subsite_table );
		$this->assertStringContainsString( (string) $one, $subsite_table );
	}

	/**
	 * A subsite created after the network activation gets one too. Without this
	 * the failure is invisible until the day that store's first order event is
	 * lost.
	 *
	 * @return void
	 */
	public function test_a_subsite_created_later_is_installed_on_creation() {
		Send4WC_Installer::activate( true );

		$this->markNetworkActive();

		$later = $this->createSite( '/later/' );

		$this->assertTrue(
			$this->siteHasQueueTable( $later ),
			'wp_initialize_site should have given the new subsite a queue table.'
		);

		switch_to_blog( $later );
		$this->assertSame(
			Send4WC_Installer::DB_VERSION,
			(int) get_option( Send4WC_Installer::DB_VERSION_OPTION )
		);
		restore_current_blog();
	}

	/**
	 * And a network the plugin is not activated across is left alone: a site
	 * owner who activated Send4 for WooCommerce on one subsite has not asked
	 * for a table on every other one.
	 *
	 * @return void
	 */
	public function test_a_new_subsite_is_left_alone_when_the_plugin_is_not_network_active() {
		delete_site_option( 'active_sitewide_plugins' );

		$untouched = $this->createSite( '/untouched/' );

		$this->assertFalse( $this->siteHasQueueTable( $untouched ) );
	}

	/**
	 * Each subsite is a store of its own, with its own Send4 connection and its
	 * own list of the order statuses Send4 wants. Connecting one store, or
	 * telling it which statuses to send, must not make another store send its
	 * buyers' orders anywhere.
	 *
	 * @return void
	 */
	public function test_the_connection_and_order_statuses_belong_to_one_site() {
		$other = $this->createSite( '/other-store/' );

		$this->wantTopics( array( 'order.status.ready-for-delivery' ) );

		$this->assertTrue( Send4WC_Settings::has_credentials() );
		$this->assertSame( array( 'order.status.ready-for-delivery' ), Send4WC_Settings::store_topics()['start_topics'] );

		switch_to_blog( $other );
		$connected_there = Send4WC_Settings::has_credentials();
		$topics_there    = Send4WC_Settings::store_topics();
		restore_current_blog();

		$this->assertFalse( $connected_there );
		$this->assertSame( array(), $topics_there['start_topics'] );
		$this->assertSame( array(), $topics_there['lifecycle_topics'] );
	}

	/**
	 * on_new_site takes either a WP_Site or a bare id, because
	 * wp_initialize_site passes the object and WP-CLI passes the id.
	 *
	 * @return void
	 */
	public function test_on_new_site_accepts_a_site_id_as_well_as_an_object() {
		// Created before the plugin is network active, so it starts without one.
		$site_id = $this->createSite( '/by-id/' );

		$this->assertFalse( $this->siteHasQueueTable( $site_id ) );

		$this->markNetworkActive();

		Send4WC_Installer::on_new_site( $site_id );

		$this->assertTrue( $this->siteHasQueueTable( $site_id ) );
	}
}
