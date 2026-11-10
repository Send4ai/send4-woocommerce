<?php
/**
 * Activation, deactivation and the schema.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that has to happen once, rather than on every request.
 */
class Send4WC_Installer {

	const DB_VERSION_OPTION = 'send4wc_db_version';

	/**
	 * Schema version.
	 *
	 * 1: the queue of order events.
	 */
	const DB_VERSION = 1;

	/**
	 * Runs on activation.
	 *
	 * On a network activation the hook fires once, for whichever site happened
	 * to be current, so every other subsite would silently have no queue table
	 * and lose order events. Hence the loop — and hence maybe_upgrade(), which catches
	 * subsites created later.
	 *
	 * @param bool $network_wide True when activated across a multisite network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( self::site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				self::ensure_events();
				Send4WC_Cron::ensure_scheduled_if_pending();
				restore_current_blog();
			}

			return;
		}

		self::install();
		self::ensure_events();

		// Deactivating dropped the queue's event; order events still waiting from
		// before then need it back now rather than at the next hourly sync.
		Send4WC_Cron::ensure_scheduled_if_pending();
	}

	/**
	 * Give a subsite created after a network activation its own table.
	 *
	 * @param WP_Site|int $site The new site.
	 * @return void
	 */
	public static function on_new_site( $site ) {
		if ( ! is_multisite() ) {
			return;
		}

		// wp_insert_site() can be called from the front end, from REST or from
		// WP-CLI, and is_plugin_active_for_network() lives in an admin include
		// that is not loaded there.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( SEND4WC_PLUGIN_FILE ) ) ) {
			return;
		}

		$site_id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : (int) $site;

		switch_to_blog( $site_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Every site id in the network, or just this one.
	 *
	 * @return int[]
	 */
	public static function site_ids() {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() );
		}

		return array_map(
			'intval',
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
	}

	/**
	 * Runs on deactivation. Data is kept: uninstall.php is what removes it.
	 *
	 * A network deactivation is the mirror of a network activation: the hook
	 * fires once, so without the loop every other subsite would keep waking
	 * WP-Cron for a plugin that is no longer running there.
	 *
	 * @param bool $network_wide True when deactivated across a multisite network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( self::site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::unschedule_events();
				restore_current_blog();
			}

			return;
		}

		self::unschedule_events();
	}

	/**
	 * Remove this site's recurring events.
	 *
	 * @return void
	 */
	private static function unschedule_events() {
		Send4WC_Cron::unschedule();
		Send4WC_Store_Sync::unschedule();
	}

	/**
	 * Create or upgrade the schema.
	 *
	 * The version is recorded only once the table is really there. Recording it
	 * regardless meant one failed create (a permissions hiccup, a storage engine
	 * dbDelta misreads) was never retried: maybe_upgrade() saw a current
	 * version and every later order event that needed the queue was dropped.
	 *
	 * Autoloaded, because maybe_upgrade() reads it on every request; not
	 * autoloading it cost a query per page view. The version bump is what
	 * moves existing installs over, since update_option() only applies a new
	 * autoload flag when the value changes.
	 *
	 * @return void
	 */
	public static function install() {
		Send4WC_Queue::install();

		if ( Send4WC_Queue::table_exists() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		}
	}

	/**
	 * Bring an older install up to date on a normal request.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Make sure this site's recurring events exist.
	 *
	 * Separate from maybe_upgrade() because cron events are NOT part of the
	 * schema version: updating a plugin runs no activation hook, so a site
	 * activated before an event existed would never schedule it. For the store
	 * sync that would mean a topic list that never refreshes until somebody
	 * deactivates and reactivates, which nobody would think to do.
	 *
	 * The every-minute queue event is deliberately NOT ensured here. It only
	 * has work while something is pending, so Send4WC_Delivery schedules it when
	 * it enqueues, requeueing schedules it, process_queue() drops it once the
	 * queue is empty, and the hourly sync re-ensures it if rows are pending.
	 * Ensuring it on every init kept an idle site waking WP-Cron every minute.
	 *
	 * ⚠️ Call this on `init`, never earlier. wp_schedule_event() runs the
	 * `cron_schedules` filter, and Send4WC_Cron::add_schedule() translates its
	 * display name — doing that before the textdomain is loaded earns a
	 * `_load_textdomain_just_in_time` notice on the customer's site.
	 *
	 * Idempotent, and reads only the one autoloaded `cron` option.
	 *
	 * @return void
	 */
	public static function ensure_events() {
		Send4WC_Store_Sync::ensure_scheduled();
	}
}
