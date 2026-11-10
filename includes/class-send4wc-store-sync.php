<?php
/**
 * Keeping the store and Send4 in step.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * An hourly event that asks Send4 which order statuses it wants and tells it
 * which statuses and categories the store has.
 *
 * The topic list cannot be fetched when an order changes status, because that
 * can be a buyer's checkout request. So it is cached (Send4WC_Settings) and
 * refreshed here, on connect, from the admin screen, and by "Sync now". A flow
 * switched on in Send4 is therefore picked up within the hour, or at once from
 * the screen.
 *
 * The same event runs the queue's retention sweep and re-arms the queue's
 * every-minute event if something is still pending, because that event drops
 * itself whenever the queue is empty.
 */
class Send4WC_Store_Sync {

	const HOOK = 'send4wc_sync_store';

	/** A one-off push shortly after a category or tag changes. */
	const PUSH_HOOK = 'send4wc_push_store';

	/** Seconds to wait after a catalogue change, so a bulk edit is one push. */
	const PUSH_DELAY = 60;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::PUSH_HOOK, array( __CLASS__, 'run_push' ) );

		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			add_action( 'created_' . $taxonomy, array( __CLASS__, 'defer_push' ) );
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'defer_push' ) );
			add_action( 'delete_' . $taxonomy, array( __CLASS__, 'defer_push' ) );
		}
	}

	/**
	 * Make sure the hourly event exists.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove this site's events.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::PUSH_HOOK );
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run() {
		Send4WC_Privacy::enforce_retention();
		Send4WC_Cron::ensure_scheduled_if_pending();

		if ( Send4WC_Settings::has_credentials() ) {
			self::sync();
		}
	}

	/**
	 * Refresh the topic list, then push the inventory.
	 *
	 * @return true|WP_Error
	 */
	public static function sync() {
		$client    = new Send4WC_Api_Client();
		$handshake = $client->handshake();

		if ( is_wp_error( $handshake ) ) {
			Send4WC_Settings::record_store_sync( $handshake->get_error_message() );
			Send4WC_Logger::log( 'warning', 'Could not ask Send4 which order statuses it wants.', array( 'error' => $handshake->get_error_message() ) );

			return $handshake;
		}

		Send4WC_Settings::update_store_topics( $handshake['store'] );

		return self::push_inventory( $client );
	}

	/**
	 * Send the store's statuses and categories to Send4.
	 *
	 * @param Send4WC_Api_Client|null $client Client to reuse, or null for a new one.
	 * @return true|WP_Error
	 */
	public static function push_inventory( $client = null ) {
		if ( ! Send4WC_Settings::has_credentials() || ! function_exists( 'wc_get_order_statuses' ) ) {
			return new WP_Error( 'send4wc_not_connected', __( 'Connect this store to Send4 first.', 'send4-for-woocommerce' ) );
		}

		$client = $client instanceof Send4WC_Api_Client ? $client : new Send4WC_Api_Client();
		$result = $client->report_store( Send4WC_Store_Inventory::build() );

		if ( is_wp_error( $result ) ) {
			Send4WC_Settings::record_store_sync( $result->get_error_message() );
			Send4WC_Logger::log( 'warning', 'Could not send the store\'s statuses and categories to Send4.', array( 'error' => $result->get_error_message() ) );

			return $result;
		}

		Send4WC_Settings::record_store_sync();

		return true;
	}

	/**
	 * Cron callback for the one-off push.
	 *
	 * @return void
	 */
	public static function run_push() {
		self::push_inventory();
	}

	/**
	 * Push the inventory shortly after a category or tag changed.
	 *
	 * @return void
	 */
	public static function defer_push() {
		if ( ! Send4WC_Settings::has_credentials() || wp_next_scheduled( self::PUSH_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::PUSH_DELAY, self::PUSH_HOOK );
	}
}
