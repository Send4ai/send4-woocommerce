<?php
/**
 * What the store has, for Send4's flow builder.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * The snapshot sent to `PUT /api/integrations/wordpress/store`.
 *
 * Send4 offers these statuses and categories on a WooCommerce trigger, and
 * warns when the checkout lets a buyer leave the phone empty: such orders can
 * never be texted. No order and no customer is in here.
 */
class Send4WC_Store_Inventory {

	/** Send4's limits. */
	const MAX_STATUSES = 50;
	const MAX_TERMS    = 500;

	/**
	 * The whole snapshot.
	 *
	 * @return array{statuses:array,product_categories:array,product_tags:array,phone_required:array,hpos:bool,woocommerce_version:string|null,environment:array}
	 */
	public static function build() {
		return array(
			'statuses'            => self::statuses(),
			'product_categories'  => self::terms( 'product_cat' ),
			'product_tags'        => self::terms( 'product_tag' ),
			'phone_required'      => self::phone_required(),
			'hpos'                => self::hpos(),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : null,
			// Send4 reads it before it changes anything an old version of this
			// plugin depends on. An older Send4 ignores the key.
			'environment'         => Send4WC_Environment::report(),
		);
	}

	/**
	 * Every order status, the store's own included, as `processing`, not `wc-processing`.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public static function statuses() {
		$statuses = array();

		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = 0 === strpos( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key;

			if ( '' === Send4WC_Order_Payload::topic( $slug ) ) {
				continue;
			}

			$statuses[] = array(
				'key'   => $slug,
				'label' => (string) self::cut( wp_strip_all_tags( (string) $label ), 191 ),
			);

			if ( count( $statuses ) >= self::MAX_STATUSES ) {
				break;
			}
		}

		return $statuses;
	}

	/**
	 * A product taxonomy's terms, empty ones included.
	 *
	 * @param string $taxonomy product_cat or product_tag.
	 * @return array<int,array{slug:string,name:string}>
	 */
	public static function terms( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::MAX_TERMS,
				'orderby'    => 'name',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$out = array();

		foreach ( $terms as $term ) {
			$out[] = array(
				'slug' => (string) self::cut( $term->slug, 191 ),
				'name' => (string) self::cut( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), 191 ),
			);
		}

		return $out;
	}

	/**
	 * Whether the checkout in use makes the buyer give a phone number.
	 *
	 * Classic and Block checkout share one setting since WooCommerce moved the
	 * block's own attributes into `woocommerce_checkout_phone_field`. Until that
	 * setting is saved, each checkout has its own default: the classic one
	 * requires a phone, the block one does not. The checkout not in use is null.
	 *
	 * @return array{classic:bool|null,block:bool|null}
	 */
	public static function phone_required() {
		$block      = self::uses_block_checkout();
		$visibility = get_option( 'woocommerce_checkout_phone_field', '' );

		if ( ! is_string( $visibility ) || '' === $visibility ) {
			$visibility = $block ? 'optional' : 'required';
		}

		$required = 'required' === $visibility;

		return array(
			'classic' => $block ? null : $required,
			'block'   => $block ? $required : null,
		);
	}

	/**
	 * Does the checkout page use the Checkout block?
	 *
	 * @return bool
	 */
	private static function uses_block_checkout() {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;

		return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
	}

	/**
	 * Are orders stored in WooCommerce's own tables (HPOS)?
	 *
	 * @return bool
	 */
	private static function hpos() {
		$util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';

		return class_exists( $util ) && (bool) call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * Cut a string to Send4's limit.
	 *
	 * @param string $value Value.
	 * @param int    $max   Most characters.
	 * @return string
	 */
	private static function cut( $value, $max ) {
		$value = trim( (string) $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
