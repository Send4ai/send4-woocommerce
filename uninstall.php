<?php
/**
 * Uninstall: remove everything the plugin created.
 *
 * Deactivating keeps the queue and the connection; uninstalling does not. That
 * is the WordPress convention.
 *
 * ⚠️ Only names that start with `send4wc`. The forms plugin `send4` may run on
 * the same site, and its options and transients start with `send4_`: a pattern
 * that matched those would disconnect its forms.
 *
 * @package Send4WC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Remove one site's table, options and scheduled events.
 *
 * @return void
 */
function send4wc_uninstall_current_site() {
	global $wpdb;

	$table = $wpdb->prefix . 'send4wc_queue';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

	foreach ( array( 'send4wc_connection', 'send4wc_store_topics', 'send4wc_delivery_status', 'send4wc_store_sync', 'send4wc_log', 'send4wc_db_version', 'send4wc_api_base_url' ) as $send4wc_option ) {
		delete_option( $send4wc_option );
	}

	// By name first: behind a persistent object cache a transient never reaches
	// the options table, so the sweep below cannot find it, and the access
	// token lives a day.
	foreach ( array( 'send4wc_access_token', 'send4wc_sign_in_cooldown', 'send4wc_handshake' ) as $send4wc_transient ) {
		delete_transient( $send4wc_transient );
	}

	// Transients named at run time (an admin notice per user, a minute long) go
	// by prefix, along with any added after this file was last edited. Only the
	// options table can be searched that way.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '\\_transient\\_send4wc\\_%'
		OR option_name LIKE '\\_transient\\_timeout\\_send4wc\\_%'"
	);

	foreach ( array( 'send4wc_process_queue', 'send4wc_sync_store', 'send4wc_push_store' ) as $send4wc_hook ) {
		wp_clear_scheduled_hook( $send4wc_hook );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'send4wc_send_now', array(), 'send4wc' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $send4wc_site_id ) {
		switch_to_blog( (int) $send4wc_site_id );
		send4wc_uninstall_current_site();
		restore_current_blog();
	}
} else {
	send4wc_uninstall_current_site();
}
