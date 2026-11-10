<?php
/**
 * The order hooks: which status changes leave the store.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns WooCommerce status changes into queued order events.
 *
 * `woocommerce_order_status_changed` is the one hook every path fires — any
 * payment gateway, the admin's order screen, REST, classic and Block checkout,
 * HPOS or posts — so a store's own statuses ("ready for delivery") work too.
 * It does not fire for an order saved straight into its first status, so
 * `woocommerce_new_order` covers that; when both fire for one save, the queue
 * and Send4 each keep one event per order and topic.
 *
 * Only a topic Send4 asked for leaves the store (Send4WC_Settings::store_topics()):
 * a status a flow starts on goes with the order's details, a status that only
 * ends runs (a cancellation, a refund) goes with its number and status alone,
 * and every other status is not sent at all.
 */
class Send4WC_Store_Events {

	/**
	 * Hook into WooCommerce.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 2 );
		add_action( Send4WC_Delivery::ASYNC_HOOK, array( __CLASS__, 'send_now' ) );
	}

	/**
	 * An order moved from one status to another.
	 *
	 * @param int           $order_id Order id.
	 * @param string        $from     Previous status, without `wc-`.
	 * @param string        $to       New status, without `wc-`.
	 * @param WC_Order|null $order    The order, when WooCommerce passes it.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		self::capture( (int) $order_id, (string) $to, $order );
	}

	/**
	 * An order was saved for the first time, in whatever status it was given.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    The order, when WooCommerce passes it.
	 * @return void
	 */
	public static function on_new_order( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( $order instanceof WC_Order ) {
			self::capture( (int) $order_id, (string) $order->get_status(), $order );
		}
	}

	/**
	 * Action Scheduler's callback: send what is due, now.
	 *
	 * @return void
	 */
	public static function send_now() {
		Send4WC_Delivery::process_queue();
	}

	/**
	 * Queue the event for an order reaching a status, when Send4 wants it.
	 *
	 * Runs inside whatever request changed the order, a checkout included, so it
	 * reads only options and writes one queue row, and nothing it does may break
	 * the order: every failure is logged and swallowed.
	 *
	 * @param int           $order_id Order id.
	 * @param string        $status   New status, without `wc-`.
	 * @param WC_Order|null $order    The order, when the caller has it.
	 * @return bool True when an event was queued.
	 */
	public static function capture( $order_id, $status, $order = null ) {
		try {
			if ( ! Send4WC_Settings::has_credentials() ) {
				return false;
			}

			$topic = Send4WC_Order_Payload::topic( $status );

			if ( '' === $topic ) {
				return false;
			}

			$topics = Send4WC_Settings::store_topics();
			$starts = in_array( $topic, $topics['start_topics'], true );
			$ends   = in_array( $topic, $topics['lifecycle_topics'], true );

			if ( ! $starts && ! $ends ) {
				return false;
			}

			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

			// A subscription or a refund is not a store order a buyer waits on.
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				return false;
			}

			return Send4WC_Delivery::queue( Send4WC_Order_Payload::reference( $order, $topic, $starts ), $topic );
		} catch ( Throwable $error ) {
			Send4WC_Logger::log(
				'error',
				'An order event could not be queued.',
				array(
					'order' => (int) $order_id,
					'error' => get_class( $error ),
				)
			);

			return false;
		}
	}
}
