<?php
/**
 * What the store tells Send4 it has.
 *
 * @package Send4WC
 */

/**
 * Send4WC_Store_Inventory against a real WooCommerce.
 */
class Send4WC_Store_Inventory_Test extends Send4WC_Test_Case {

	/**
	 * Reset the checkout settings each test touches.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'woocommerce_checkout_phone_field' );
		update_option( 'woocommerce_checkout_page_id', 0 );

		parent::tear_down();
	}

	/**
	 * Statuses go as `processing`, not `wc-processing`, the store's own included.
	 *
	 * @return void
	 */
	public function test_statuses_are_slugs_and_include_the_stores_own() {
		add_filter(
			'wc_order_statuses',
			static function ( $statuses ) {
				$statuses['wc-ready-delivery'] = 'Ready for delivery';

				return $statuses;
			}
		);

		$statuses = Send4WC_Store_Inventory::statuses();
		$keys     = wp_list_pluck( $statuses, 'key' );

		$this->assertContains( 'processing', $keys );
		$this->assertContains( 'cancelled', $keys );
		$this->assertContains( 'ready-delivery', $keys );
		$this->assertNotContains( 'wc-processing', $keys );
		$this->assertContains(
			array(
				'key'   => 'ready-delivery',
				'label' => 'Ready for delivery',
			),
			$statuses
		);
	}

	/**
	 * Categories and tags go with slug and name, empty ones included: a store
	 * sets up the flow before it has sold anything in a category.
	 *
	 * @return void
	 */
	public function test_categories_and_tags_include_empty_ones() {
		wp_insert_term( 'Virtuvės baldai', 'product_cat', array( 'slug' => 'virtuves-baldai' ) );
		wp_insert_term( 'Montavimas', 'product_tag', array( 'slug' => 'montavimas' ) );

		$inventory = Send4WC_Store_Inventory::build();

		$this->assertContains(
			array(
				'slug' => 'virtuves-baldai',
				'name' => 'Virtuvės baldai',
			),
			$inventory['product_categories']
		);
		$this->assertContains(
			array(
				'slug' => 'montavimas',
				'name' => 'Montavimas',
			),
			$inventory['product_tags']
		);
	}

	/**
	 * The classic checkout requires a phone unless the store changed that.
	 *
	 * @return void
	 */
	public function test_the_classic_checkout_requires_a_phone_by_default() {
		$this->assertSame(
			array(
				'classic' => true,
				'block'   => null,
			),
			Send4WC_Store_Inventory::phone_required()
		);

		update_option( 'woocommerce_checkout_phone_field', 'optional' );

		$this->assertFalse( Send4WC_Store_Inventory::phone_required()['classic'] );
	}

	/**
	 * The Checkout block leaves the phone optional unless the store changed
	 * that, which is why Send4 warns about it: such orders are never texted.
	 *
	 * @return void
	 */
	public function test_the_block_checkout_leaves_the_phone_optional_by_default() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page );

		$this->assertSame(
			array(
				'classic' => null,
				'block'   => false,
			),
			Send4WC_Store_Inventory::phone_required()
		);

		update_option( 'woocommerce_checkout_phone_field', 'required' );

		$this->assertTrue( Send4WC_Store_Inventory::phone_required()['block'] );
	}

	/**
	 * The rest of the snapshot: versions and storage, never an order or a buyer.
	 *
	 * @return void
	 */
	public function test_the_snapshot_carries_versions_and_no_order() {
		$this->makeOrder();

		$inventory = Send4WC_Store_Inventory::build();

		$this->assertSame( WC_VERSION, $inventory['woocommerce_version'] );
		$this->assertIsBool( $inventory['hpos'] );
		$this->assertSame( SEND4WC_VERSION, $inventory['environment']['plugin_version'] );
		$this->assertSame(
			array( 'statuses', 'product_categories', 'product_tags', 'phone_required', 'hpos', 'woocommerce_version', 'environment' ),
			array_keys( $inventory )
		);
		$this->assertStringNotContainsString( 'Jonas', (string) wp_json_encode( $inventory ) );
	}
}
