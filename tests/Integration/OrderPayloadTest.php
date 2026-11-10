<?php
/**
 * What an order event says.
 *
 * @package Send4WC
 */

/**
 * Send4WC_Order_Payload against a real WooCommerce order.
 *
 * The limits asserted here are Send4's own, from the store-events endpoint's
 * validation: a value over one would refuse the whole event.
 */
class Send4WC_Order_Payload_Test extends Send4WC_Test_Case {

	/**
	 * The event a flow starts on carries what the flow needs to text the
	 * buyer and book the slot.
	 *
	 * @return void
	 */
	public function test_a_start_event_carries_the_order_the_buyer_and_the_address() {
		$order = $this->makeOrder();
		$order->set_customer_note( 'Please call before you come.' );
		$order->save();

		$event = Send4WC_Order_Payload::build( Send4WC_Order_Payload::reference( $order, 'order.status.processing', true ) );

		$this->assertIsArray( $event );
		$this->assertSame( untrailingslashit( home_url() ), $event['site_url'] );
		$this->assertSame( 'order.status.processing', $event['event']['topic'] );
		$this->assertSame( 'woo:' . $order->get_id() . ':order.status.processing', $event['event']['id'] );
		$this->assertSame( (string) $order->get_id(), $event['order']['id'] );
		$this->assertSame( (string) $order->get_order_number(), $event['order']['number'] );
		$this->assertSame( 'processing', $event['order']['status'] );
		$this->assertSame( 'Please call before you come.', $event['order']['customer_note'] );
		$this->assertSame( $order->get_edit_order_url(), $event['order']['admin_url'] );
		$this->assertSame( 'Jonas', $event['customer']['first_name'] );
		$this->assertSame( 'Jonaitis', $event['customer']['last_name'] );
		$this->assertSame( 'jonas@example.test', $event['customer']['email'] );
		$this->assertSame( 'LT', $event['customer']['country'] );
		$this->assertSame( 'Vilnius', $event['shipping']['city'] );
		$this->assertSame( 'LT', $event['shipping']['country'] );
		$this->assertStringContainsString( 'Gedimino pr. 1', (string) $event['shipping']['formatted'] );
		$this->assertStringNotContainsString( '<br', (string) $event['shipping']['formatted'] );
		$this->assertSame( 'Sofa Oslo', $event['items'][0]['name'] );
		$this->assertSame( '1 × Sofa Oslo', $event['items_summary'] );
	}

	/**
	 * The phone goes as the buyer typed it. Send4 reads it in the billing
	 * country: `861234567` with LT is +37061234567 there, and only there.
	 *
	 * @return void
	 */
	public function test_the_phone_is_sent_as_typed() {
		$order = $this->makeOrder( array( 'phone' => '8 612 34567' ) );

		$event = Send4WC_Order_Payload::build( Send4WC_Order_Payload::reference( $order, 'order.status.processing', true ) );

		$this->assertSame( '8 612 34567', $event['customer']['phone_raw'] );
	}

	/**
	 * The status is the one the event is about, not wherever the order has
	 * moved on to since it was queued.
	 *
	 * @return void
	 */
	public function test_the_status_is_the_events_not_the_orders_current_one() {
		$order     = $this->makeOrder();
		$reference = Send4WC_Order_Payload::reference( $order, 'order.status.processing', true );
		$order->update_status( 'completed' );

		$event = Send4WC_Order_Payload::build( $reference );

		$this->assertSame( 'processing', $event['order']['status'] );
	}

	/**
	 * Built when it is sent: an address corrected after the status change goes
	 * as corrected.
	 *
	 * @return void
	 */
	public function test_the_event_is_built_from_the_order_as_it_is_now() {
		$order     = $this->makeOrder();
		$reference = Send4WC_Order_Payload::reference( $order, 'order.status.processing', true );
		$order->set_shipping_city( 'Kaunas' );
		$order->save();

		$event = Send4WC_Order_Payload::build( $reference );

		$this->assertSame( 'Kaunas', $event['shipping']['city'] );
	}

	/**
	 * A cancellation that only ends runs carries the number and the status,
	 * nothing about the buyer.
	 *
	 * @return void
	 */
	public function test_a_lifecycle_event_carries_only_the_number_and_status() {
		$order = $this->makeOrder();

		$event = Send4WC_Order_Payload::build( Send4WC_Order_Payload::reference( $order, 'order.status.cancelled', false ) );

		$this->assertSame( array( 'site_url', 'event', 'order' ), array_keys( $event ) );
		$this->assertSame(
			array(
				'id'     => (string) $order->get_id(),
				'number' => (string) $order->get_order_number(),
				'status' => 'cancelled',
			),
			$event['order']
		);
	}

	/**
	 * An order deleted before its event was sent: a flow cannot start on it,
	 * but a cancellation still has to end the runs it started.
	 *
	 * @return void
	 */
	public function test_a_deleted_order_fails_a_start_event_and_still_sends_a_lifecycle_one() {
		$order    = $this->makeOrder();
		$start    = Send4WC_Order_Payload::reference( $order, 'order.status.processing', true );
		$ending   = Send4WC_Order_Payload::reference( $order, 'order.status.cancelled', false );
		$order_id = $order->get_id();
		$order->delete( true );

		$failed = Send4WC_Order_Payload::build( $start );
		$sent   = Send4WC_Order_Payload::build( $ending );

		$this->assertWPError( $failed );
		$this->assertSame( 'send4wc_order_gone', $failed->get_error_code() );
		$this->assertSame( (string) $order_id, $sent['order']['id'] );
		$this->assertNull( $sent['order']['number'] );
		$this->assertSame( 'cancelled', $sent['order']['status'] );
	}

	/**
	 * A reference that is not one is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function test_an_unreadable_reference_is_refused() {
		$this->assertWPError( Send4WC_Order_Payload::build( array() ) );
		$this->assertWPError(
			Send4WC_Order_Payload::build(
				array(
					'event' => array( 'topic' => 'order.created' ),
					'order' => array( 'id' => '5' ),
				)
			)
		);
	}

	/**
	 * Only statuses Send4 can accept become topics.
	 *
	 * @return void
	 */
	public function test_a_topic_is_an_order_status_send4_accepts() {
		$this->assertSame( 'order.status.processing', Send4WC_Order_Payload::topic( 'processing' ) );
		$this->assertSame( 'order.status.on-hold', Send4WC_Order_Payload::topic( 'on-hold' ) );
		$this->assertSame( '', Send4WC_Order_Payload::topic( 'Ready Now' ) );
		$this->assertSame( '', Send4WC_Order_Payload::topic( '' ) );
	}

	/**
	 * Send4 keeps 20 items and refuses more; the summary still names them all.
	 *
	 * @return void
	 */
	public function test_items_stop_at_twenty_and_the_summary_names_every_one() {
		$order = $this->makeOrder();

		for ( $i = 1; $i <= 21; $i++ ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Chair ' . $i );
			$product->set_regular_price( '10' );
			$product->save();
			$order->add_product( $product, 2 );
		}

		$order->save();

		$event = Send4WC_Order_Payload::build( Send4WC_Order_Payload::reference( $order, 'order.status.processing', true ) );

		$this->assertCount( 20, $event['items'] );
		$this->assertStringContainsString( '2 × Chair 21', $event['items_summary'] );
	}

	/**
	 * A text over Send4's limit is cut, never sent whole to be refused.
	 *
	 * @return void
	 */
	public function test_a_long_note_is_cut_to_send4s_limit() {
		$order = $this->makeOrder();
		$order->set_customer_note( str_repeat( 'ą', 2100 ) );
		$order->save();

		$event = Send4WC_Order_Payload::build( Send4WC_Order_Payload::reference( $order, 'order.status.processing', true ) );

		$this->assertSame( 2000, mb_strlen( $event['order']['customer_note'] ) );
	}

	/**
	 * An item carries its product's categories and tags as slugs: that is what
	 * a flow's product filter matches on.
	 *
	 * @return void
	 */
	public function test_an_item_carries_its_categories_and_tags() {
		$category = wp_insert_term( 'Sofos', 'product_cat', array( 'slug' => 'sofos' ) );
		$tag      = wp_insert_term( 'Delivery', 'product_tag', array( 'slug' => 'delivery' ) );

		$product = new WC_Product_Simple();
		$product->set_name( 'Sofa Riga' );
		$product->set_regular_price( '500' );
		$product->set_category_ids( array( $category['term_id'] ) );
		$product->set_tag_ids( array( $tag['term_id'] ) );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$event = Send4WC_Order_Payload::build( Send4WC_Order_Payload::reference( $order, 'order.status.processing', true ) );

		$this->assertSame( array( 'sofos' ), $event['items'][0]['categories'] );
		$this->assertSame( array( 'delivery' ), $event['items'][0]['tags'] );
	}

	/**
	 * A delivery-date plugin's field reaches Send4 only when the store names it.
	 *
	 * @return void
	 */
	public function test_order_meta_is_sent_only_for_the_keys_a_store_names() {
		$order = $this->makeOrder();
		$order->update_meta_data( '_delivery_date', '2026-11-20' );
		$order->update_meta_data( '_internal_margin', '35%' );
		$order->save();
		$reference = Send4WC_Order_Payload::reference( $order, 'order.status.processing', true );

		$before = Send4WC_Order_Payload::build( $reference );

		add_filter(
			'send4wc_order_meta_keys',
			static function () {
				return array( '_delivery_date' );
			}
		);

		$after = Send4WC_Order_Payload::build( $reference );

		$this->assertArrayNotHasKey( 'meta', $before );
		$this->assertSame( array( '_delivery_date' => '2026-11-20' ), $after['meta'] );
	}
}
