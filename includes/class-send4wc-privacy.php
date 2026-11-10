<?php
/**
 * Privacy: the policy text, and how long queue rows are kept.
 *
 * The queue keeps an order id and a status, never the buyer's details: the
 * event Send4 receives is built from the order when it is sent (see
 * Send4WC_Order_Payload). So the plugin holds no personal data of its own to
 * export or erase — the order is WooCommerce's, and WooCommerce's own privacy
 * tools cover it.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * The privacy-policy suggestion and the retention sweep.
 */
class Send4WC_Privacy {

	/** Rows that will never be delivered are kept this long, then deleted. */
	const RETENTION_DAYS = 7;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
		// Retention runs from Send4WC_Store_Sync::run(), the hourly event: the
		// every-minute queue event only exists while something is pending.
	}

	/**
	 * Suggested privacy-policy text.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'When your order reaches a status this store has connected to Send4 — for example "ready for delivery" — your name, phone number, email address, delivery address, the order number, the items you ordered and your order note are sent to Send4 (send4.ai) so that we can text you about your delivery or installation. When an order is cancelled or refunded, only its number and status are sent, so that we stop texting you about it. If Send4 cannot be reached at that moment, the order is retried for up to about a day and a half; while it waits, this store keeps only the order number and status, never your details.', 'send4-for-woocommerce' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Send4', 'send4-for-woocommerce' ), wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * Delete queue rows that will never be delivered and are past retention.
	 *
	 * Dead letters go RETENTION_DAYS after they last failed. Pending rows go
	 * once they are older than the whole retry schedule plus RETENTION_DAYS
	 * (about nine days): by then nothing is working them, which is what a
	 * disconnected store looks like, and a week-old order event would otherwise
	 * reach Send4 the day the store reconnects.
	 *
	 * @return int Rows removed.
	 */
	public static function enforce_retention() {
		if ( ! Send4WC_Queue::table_exists() ) {
			return 0;
		}

		$removed = Send4WC_Queue::purge_older_than(
			self::RETENTION_DAYS,
			array( Send4WC_Queue::STATUS_FAILED, Send4WC_Queue::STATUS_PERMANENT )
		);

		return $removed + Send4WC_Queue::purge_stale_pending( Send4WC_Queue::retry_span() + self::RETENTION_DAYS * DAY_IN_SECONDS );
	}
}
