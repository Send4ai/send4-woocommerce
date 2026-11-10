<?php
/**
 * What an order event says, and when it is decided.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two shapes: the reference the queue keeps, and the event Send4 receives.
 *
 * The queue keeps only a reference — the order id, the topic and when it
 * happened — never the buyer's details. The event is built from the live order
 * when the worker sends it, seconds later:
 *
 * - `woocommerce_new_order` fires at the order's first save, which for
 *   wc_create_order() is before its items and addresses are added, so a payload
 *   built in the hook could describe an empty order;
 * - the store's database never holds a copy of anybody's address waiting on
 *   Send4, which leaves nothing for the plugin to export or erase;
 * - a retry a day later carries the address as it is now, corrections included.
 *
 * The field limits are Send4's (`POST /api/integrations/wordpress/store-events`):
 * a value over a limit would refuse the whole event.
 */
class Send4WC_Order_Payload {

	/** Topics are `order.status.<status>`. */
	const TOPIC_PREFIX = 'order.status.';

	/**
	 * What Send4 accepts as a topic. `\z`, not `$`: `$` also matches before a
	 * final line break, and this pattern decides what leaves the store.
	 */
	const TOPIC_PATTERN = '/^order\.status\.[a-z0-9][a-z0-9_-]{0,59}\z/';

	/** Line items sent; the summary still names every one. */
	const MAX_ITEMS = 20;

	/** Categories or tags sent per item. */
	const MAX_TERMS = 20;

	/** Order meta keys sent. */
	const MAX_META = 20;

	/**
	 * The topic for an order status, or '' when Send4 could never accept it.
	 *
	 * @param string $status Order status without the `wc-` prefix.
	 * @return string
	 */
	public static function topic( $status ) {
		$topic = self::TOPIC_PREFIX . (string) $status;

		return 1 === preg_match( self::TOPIC_PATTERN, $topic ) ? $topic : '';
	}

	/**
	 * What the queue keeps for one order event: no personal data.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $topic `order.status.<status>`.
	 * @param bool     $full  True when a flow starts on the topic, false when it
	 *                        only ends runs (a cancellation): then Send4 gets the
	 *                        order's number and status and nothing else.
	 * @return array{event:array{id:string,topic:string,occurred_at:string},order:array{id:string},full:bool}
	 */
	public static function reference( WC_Order $order, $topic, $full ) {
		return array(
			'event' => array(
				// The same order reaching the same status again is the same
				// event: Send4 keeps one per order and topic, and so does the
				// queue.
				'id'          => 'woo:' . $order->get_id() . ':' . $topic,
				'topic'       => (string) $topic,
				'occurred_at' => gmdate( 'c' ),
			),
			'order' => array( 'id' => (string) $order->get_id() ),
			'full'  => (bool) $full,
		);
	}

	/**
	 * The event Send4 receives, built from the order as it is now.
	 *
	 * @param array $reference What reference() returned.
	 * @return array|WP_Error The event, or why there is none to send.
	 */
	public static function build( array $reference ) {
		$topic    = isset( $reference['event']['topic'] ) ? (string) $reference['event']['topic'] : '';
		$order_id = isset( $reference['order']['id'] ) ? (int) $reference['order']['id'] : 0;

		if ( '' === $topic || 1 !== preg_match( self::TOPIC_PATTERN, $topic ) || $order_id < 1 ) {
			return new WP_Error( 'send4wc_bad_reference', __( 'The queued order event could not be read.', 'send4-for-woocommerce' ) );
		}

		$full  = ! empty( $reference['full'] );
		$event = array(
			'site_url' => untrailingslashit( home_url() ),
			'event'    => array(
				'id'          => self::text( $reference['event']['id'] ?? '', 191 ),
				'topic'       => $topic,
				'occurred_at' => self::text( $reference['event']['occurred_at'] ?? '', 64 ),
			),
			'order'    => array(
				'id'     => (string) $order_id,
				'number' => null,
				// The status the event is about, not whatever the order has moved
				// on to since: a flow on "processing" reads "processing".
				'status' => substr( $topic, strlen( self::TOPIC_PREFIX ) ),
			),
		);

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			// A cancellation still has to end the runs its order started, so it
			// goes without a number. A flow cannot start on an order that is gone.
			return $full
				? new WP_Error( 'send4wc_order_gone', __( 'The order was deleted before its event could be sent.', 'send4-for-woocommerce' ) )
				: $event;
		}

		$event['order']['number'] = self::text( $order->get_order_number(), 64 );

		if ( ! $full ) {
			return $event;
		}

		$created = $order->get_date_created();

		$event['order'] += array(
			'total'                => self::text( wc_format_decimal( $order->get_total(), wc_get_price_decimals() ), 32 ),
			'currency'             => self::text( $order->get_currency(), 8 ),
			'created_at'           => $created ? self::text( $created->date( 'c' ), 64 ) : null,
			'payment_method_title' => self::text( $order->get_payment_method_title(), 191 ),
			'shipping_method'      => self::text( $order->get_shipping_method(), 191 ),
			'customer_note'        => self::text( $order->get_customer_note(), 2000 ),
			'admin_url'            => self::text( $order->get_edit_order_url(), 2048 ),
		);

		$event['customer'] = array(
			'first_name' => self::text( $order->get_billing_first_name(), 191 ),
			'last_name'  => self::text( $order->get_billing_last_name(), 191 ),
			'email'      => self::text( $order->get_billing_email(), 191 ),
			// As typed: Send4 reads it in the billing country, so a Lithuanian
			// `861234567` becomes +37061234567 there, not here.
			'phone_raw'  => self::text( $order->get_billing_phone(), 32 ),
			'country'    => self::country( $order->get_billing_country() ),
			// WooCommerce stores no language on an order; the site's is the
			// best guess, and a multilingual plugin can change it with the filter.
			'locale'     => self::text( (string) apply_filters( 'send4wc_order_locale', get_locale(), $order ), 16 ),
		);

		$event['shipping'] = array(
			'address_1' => self::text( $order->get_shipping_address_1(), 191 ),
			'address_2' => self::text( $order->get_shipping_address_2(), 191 ),
			'city'      => self::text( $order->get_shipping_city(), 191 ),
			'postcode'  => self::text( $order->get_shipping_postcode(), 32 ),
			'country'   => self::country( $order->get_shipping_country() ),
			'phone_raw' => self::text( $order->get_shipping_phone(), 32 ),
			'formatted' => self::text( self::one_line( $order->get_formatted_shipping_address() ), 1000 ),
		);

		list( $items, $summary ) = self::items( $order );

		$event['items']         = $items;
		$event['items_summary'] = self::text( $summary, 1000 );

		$meta = self::meta( $order );

		if ( ! empty( $meta ) ) {
			$event['meta'] = $meta;
		}

		/**
		 * Change an order event just before it is sent to Send4. Must return
		 * the array; Send4 refuses keys and lengths it does not know.
		 *
		 * @param array    $event The event.
		 * @param WC_Order $order The order.
		 */
		return apply_filters( 'send4wc_order_event', $event, $order );
	}

	/**
	 * The line items Send4 sees, and a one-line summary of all of them.
	 *
	 * Categories and tags are slugs, read from the parent product for a
	 * variation: those are what a flow's product filter matches on.
	 *
	 * @param WC_Order $order The order.
	 * @return array{0:array<int,array>,1:string}
	 */
	private static function items( WC_Order $order ) {
		$items   = array();
		$summary = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$name      = (string) $item->get_name();
			$qty       = (float) $item->get_quantity();
			$summary[] = wc_format_decimal( $qty ) . ' × ' . $name;

			if ( count( $items ) >= self::MAX_ITEMS ) {
				continue;
			}

			$product    = $item->get_product();
			$product_id = $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0;

			$items[] = array(
				'name'       => self::text( $name, 191 ),
				'sku'        => $product ? self::text( $product->get_sku(), 100 ) : null,
				'qty'        => $qty,
				'categories' => self::term_slugs( $product_id, 'product_cat' ),
				'tags'       => self::term_slugs( $product_id, 'product_tag' ),
			);
		}

		return array( $items, implode( ', ', $summary ) );
	}

	/**
	 * Up to MAX_TERMS slugs of a product's terms.
	 *
	 * @param int    $product_id Product id, 0 for none.
	 * @param string $taxonomy   product_cat or product_tag.
	 * @return string[]
	 */
	private static function term_slugs( $product_id, $taxonomy ) {
		if ( $product_id < 1 ) {
			return array();
		}

		$slugs = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'slugs' ) );

		if ( ! is_array( $slugs ) ) {
			return array();
		}

		$slugs = array_slice( array_values( array_filter( array_map( 'strval', $slugs ) ) ), 0, self::MAX_TERMS );

		return array_map(
			static function ( $slug ) {
				return (string) self::text( $slug, 191 );
			},
			$slugs
		);
	}

	/**
	 * The order meta a store chose to send, e.g. a delivery-date plugin's field.
	 *
	 * Nothing by default. A store adds keys with the `send4wc_order_meta_keys`
	 * filter; each reaches Send4 as `meta.<key>`, and from there the AI.
	 *
	 * @param WC_Order $order The order.
	 * @return array<string,string>
	 */
	private static function meta( WC_Order $order ) {
		/**
		 * Order meta keys to send with an order event, up to 20.
		 *
		 * @param string[] $keys  Meta keys. Default none.
		 * @param WC_Order $order The order.
		 */
		$keys = apply_filters( 'send4wc_order_meta_keys', array(), $order );
		$meta = array();

		foreach ( array_slice( (array) $keys, 0, self::MAX_META ) as $key ) {
			if ( '' === $key ) {
				continue;
			}

			$value = $order->get_meta( $key );

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$meta[ $key ] = (string) self::text( (string) $value, 500 );
			}
		}

		return $meta;
	}

	/**
	 * A trimmed string cut to Send4's limit, or null when there is nothing.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Most characters Send4 keeps.
	 * @return string|null
	 */
	private static function text( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/**
	 * An ISO 3166-1 alpha-2 code, or null.
	 *
	 * @param mixed $country Country code.
	 * @return string|null
	 */
	private static function country( $country ) {
		$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';

		return 1 === preg_match( '/^[A-Z]{2}\z/', $country ) ? $country : null;
	}

	/**
	 * WooCommerce's formatted address, which is HTML with <br/>, on one line.
	 *
	 * @param string $address Formatted address.
	 * @return string
	 */
	private static function one_line( $address ) {
		$lines = preg_split( '/<br\s*\/?>/i', (string) $address );
		$lines = array_filter( array_map( 'trim', array_map( 'wp_strip_all_tags', is_array( $lines ) ? $lines : array() ) ) );

		return implode( ', ', $lines );
	}
}
