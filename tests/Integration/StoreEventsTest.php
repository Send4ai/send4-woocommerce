<?php
/**
 * The order hooks: which status changes leave the store, and how.
 *
 * @package Send4WC
 */

/**
 * Send4WC_Store_Events against a real WooCommerce.
 */
class Send4WC_Store_Events_Test extends Send4WC_Test_Case {

	/**
	 * A status a flow starts on queues the order — as a reference, not a copy.
	 *
	 * The queue row holds the order id and the topic, never the buyer's name,
	 * phone or address: the event is built from the order when it is sent.
	 *
	 * @return void
	 */
	public function test_a_status_a_flow_starts_on_queues_a_reference_to_the_order() {
		$order = $this->makeOrder();

		$order->update_status( 'processing' );

		$row       = $this->onlyQueueRow();
		$reference = Send4WC_Queue::envelope( $row );

		$this->assertSame( 'order.status.processing', $row->topic );
		$this->assertSame( 'woo:' . $order->get_id() . ':order.status.processing', $row->event_id );
		$this->assertSame( (string) $order->get_id(), $reference['order']['id'] );
		$this->assertTrue( $reference['full'] );
		$this->assertStringNotContainsString( '861234567', (string) $row->envelope );
		$this->assertStringNotContainsString( 'Jonas', (string) $row->envelope );
		$this->assertStringNotContainsString( 'Gedimino', (string) $row->envelope );
	}

	/**
	 * The status change itself never waits on Send4: it can be a checkout.
	 *
	 * @return void
	 */
	public function test_the_status_change_makes_no_request() {
		$order = $this->makeOrder();

		$order->update_status( 'processing' );

		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * A queued event asks Action Scheduler to send it within seconds, and the
	 * every-minute event is the safety net and the retry.
	 *
	 * @return void
	 */
	public function test_a_queued_event_is_scheduled_to_send_now() {
		$order = $this->makeOrder();

		$order->update_status( 'processing' );

		$this->assertTrue( as_has_scheduled_action( Send4WC_Delivery::ASYNC_HOOK, array(), 'send4wc' ) );
		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
	}

	/**
	 * A cancellation no flow starts on still goes, because it has to end the
	 * run its order started — but only as a lifecycle event.
	 *
	 * @return void
	 */
	public function test_a_cancellation_is_queued_as_a_lifecycle_event() {
		$order = $this->makeOrder();

		$order->update_status( 'cancelled' );

		$row = $this->onlyQueueRow();

		$this->assertSame( 'order.status.cancelled', $row->topic );
		$this->assertFalse( Send4WC_Queue::envelope( $row )['full'] );
	}

	/**
	 * A status Send4 did not ask for never leaves the store.
	 *
	 * @return void
	 */
	public function test_a_status_send4_did_not_ask_for_is_not_queued() {
		$order = $this->makeOrder();

		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );

		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * A store's own status ("ready for delivery") is a topic like any other.
	 *
	 * WooCommerce stores at most 20 characters of status, `wc-` included, so a
	 * real custom status is 17 characters or fewer.
	 *
	 * @return void
	 */
	public function test_a_custom_status_is_a_topic() {
		register_post_status( 'wc-ready-delivery', array( 'label' => 'Ready for delivery' ) );
		add_filter(
			'wc_order_statuses',
			static function ( $statuses ) {
				$statuses['wc-ready-delivery'] = 'Ready for delivery';

				return $statuses;
			}
		);
		$this->wantTopics( array( 'order.status.ready-delivery' ) );

		$order = $this->makeOrder();
		$order->update_status( 'ready-delivery' );

		$this->assertSame( 'order.status.ready-delivery', $this->onlyQueueRow()->topic );
	}

	/**
	 * A disconnected store queues nothing: there is nowhere to send it.
	 *
	 * @return void
	 */
	public function test_a_disconnected_store_queues_nothing() {
		Send4WC_Settings::disconnect();
		$order = $this->makeOrder();

		$order->update_status( 'processing' );

		$this->assertSame( array(), $this->queueRows() );
	}

	/**
	 * Disconnecting forgets the topic lists too, so a reconnect to another
	 * workspace never sends on the old one's lists.
	 *
	 * @return void
	 */
	public function test_disconnecting_forgets_the_topics() {
		Send4WC_Settings::disconnect();

		$topics = Send4WC_Settings::store_topics();

		$this->assertSame( array(), $topics['start_topics'] );
		$this->assertSame( array(), $topics['lifecycle_topics'] );
	}

	/**
	 * `woocommerce_order_status_changed` does not fire for an order saved
	 * straight into its first status; `woocommerce_new_order` covers it.
	 *
	 * @return void
	 */
	public function test_an_order_created_straight_into_a_status_is_queued_once() {
		$order = wc_create_order( array( 'status' => 'processing' ) );

		$rows = $this->queueRows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'woo:' . $order->get_id() . ':order.status.processing', $rows[0]->event_id );
	}

	/**
	 * An order that comes back to the same status before the first event is
	 * sent is still one event, as it is one receipt in Send4.
	 *
	 * @return void
	 */
	public function test_the_same_status_twice_is_one_row() {
		$order = $this->makeOrder();

		$order->update_status( 'processing' );
		$order->update_status( 'on-hold' );
		$order->update_status( 'processing' );

		$this->assertCount( 1, $this->queueRows() );
	}

	/**
	 * A refund object is not an order a buyer waits on. A full refund still
	 * reaches the order itself, as `refunded`.
	 *
	 * @return void
	 */
	public function test_a_refund_object_is_ignored_but_the_refunded_order_is_not() {
		$order = $this->makeOrder();
		$order->update_status( 'completed' );

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $order->get_total(),
			)
		);

		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertFalse( Send4WC_Store_Events::capture( $refund->get_id(), 'refunded' ) );
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'order.status.refunded', $this->onlyQueueRow()->topic );
	}

	/**
	 * Nothing the hook does may break the order, whatever goes wrong inside it.
	 *
	 * @return void
	 */
	public function test_a_failure_inside_the_hook_never_breaks_the_status_change() {
		add_filter(
			'pre_option_' . Send4WC_Settings::TOPICS_OPTION,
			static function () {
				throw new RuntimeException( 'database went away' );
			}
		);
		$order = $this->makeOrder();

		$order->update_status( 'processing' );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), $this->queueRows() );
		$this->assertSame( 'An order event could not be queued.', Send4WC_Logger::entries()[0]['message'] );
	}

	/**
	 * End to end: the status change, the queue, the worker, the POST, the
	 * delivered row gone.
	 *
	 * @return void
	 */
	public function test_an_order_reaches_send4_with_the_buyers_details() {
		$order = $this->makeOrder();
		$order->update_status( 'processing' );

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['delivered'] );
		$this->assertSame( array(), $this->queueRows() );

		$posts = $this->http->requestsTo( '/api/integrations/wordpress/store-events' );
		$this->assertCount( 1, $posts );

		$event = $posts[0]['body'];
		$this->assertSame( 'order.status.processing', $event['event']['topic'] );
		$this->assertSame( (string) $order->get_id(), $event['order']['id'] );
		$this->assertSame( 'processing', $event['order']['status'] );
		$this->assertSame( '861234567', $event['customer']['phone_raw'] );
		$this->assertSame( 'LT', $event['customer']['country'] );
		$this->assertSame( 'delivered', Send4WC_Settings::delivery_status()['status'] );
	}
}
