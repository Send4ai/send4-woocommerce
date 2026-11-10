<?php
/**
 * Shared setup for the Send4 for WooCommerce suite.
 *
 * @package Send4WC
 */

/**
 * A connected store with a clean queue, a clean log and no real HTTP.
 *
 * Send4 wants `processing` (a flow starts on it) and `cancelled` / `refunded`
 * (they end runs), unless a test says otherwise.
 */
abstract class Send4WC_Test_Case extends WP_UnitTestCase {

	/**
	 * The HTTP double.
	 *
	 * @var Send4WC_Http_Recorder
	 */
	protected $http;

	/**
	 * Give every test a connected store and a scripted network.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->http = new Send4WC_Http_Recorder();
		$this->http->hook();

		delete_option( Send4WC_Settings::STATUS_OPTION );
		delete_option( Send4WC_Settings::SYNC_OPTION );
		delete_option( Send4WC_Settings::BASE_URL_OPTION );
		delete_option( Send4WC_Logger::OPTION );
		delete_transient( Send4WC_Api_Client::TOKEN_TRANSIENT );
		delete_transient( Send4WC_Api_Client::SIGN_IN_COOLDOWN );
		delete_transient( Send4WC_Admin::HANDSHAKE_CACHE );

		Send4WC_Settings::update_connection(
			array(
				'client_id'     => 'test-client-id',
				'client_secret' => 'test-client-secret',
				'client_name'   => 'Baldai UAB',
				'connected_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->wantTopics( array( 'order.status.processing' ) );

		$this->emptyQueue();
	}

	/**
	 * Undo the HTTP double.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->http->unhook();

		Send4WC_Settings::disconnect();
		delete_option( Send4WC_Settings::STATUS_OPTION );
		delete_option( Send4WC_Settings::BASE_URL_OPTION );
		$this->emptyQueue();

		parent::tear_down();
	}

	/**
	 * Set the topics Send4 asked for, as a handshake would.
	 *
	 * @param string[] $start     Topics a flow starts on.
	 * @param string[] $lifecycle Topics that end runs.
	 * @return void
	 */
	protected function wantTopics( array $start, array $lifecycle = array( 'order.status.cancelled', 'order.status.refunded' ) ) {
		Send4WC_Settings::update_store_topics(
			array(
				'start_topics'     => $start,
				'lifecycle_topics' => $lifecycle,
			)
		);
	}

	/**
	 * A store order with a buyer, an address and one line item.
	 *
	 * Built the way a checkout builds one — saved as pending first, then filled
	 * in — so it does not itself reach a topic the test cares about.
	 *
	 * @param array $billing Billing fields to override.
	 * @return WC_Order
	 */
	protected function makeOrder( array $billing = array() ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Sofa Oslo' );
		$product->set_sku( 'SOFA-OSLO-' . wp_generate_password( 6, false ) );
		$product->set_regular_price( '899' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_address(
			array_merge(
				array(
					'first_name' => 'Jonas',
					'last_name'  => 'Jonaitis',
					'email'      => 'jonas@example.test',
					'phone'      => '861234567',
					'country'    => 'LT',
					'city'       => 'Vilnius',
					'address_1'  => 'Gedimino pr. 1',
					'postcode'   => '01103',
				),
				$billing
			),
			'billing'
		);
		$order->set_address(
			array(
				'first_name' => 'Jonas',
				'last_name'  => 'Jonaitis',
				'country'    => 'LT',
				'city'       => 'Vilnius',
				'address_1'  => 'Gedimino pr. 1',
				'postcode'   => '01103',
			),
			'shipping'
		);
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Bring a queue row's next attempt forward so process_queue() picks it up.
	 *
	 * @param object $row Queue row.
	 * @return void
	 */
	protected function makeDue( $row ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Send4WC_Queue::table(),
			array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'id' => (int) $row->id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Empty the queue table.
	 *
	 * The table lives outside the per-test transaction (it is created once, in
	 * the bootstrap), and MySQL commits the transaction when DDL runs, so rows
	 * are cleared explicitly rather than trusted to the rollback.
	 *
	 * @return void
	 */
	protected function emptyQueue() {
		global $wpdb;

		$table = Send4WC_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Every queue row, oldest first.
	 *
	 * @return array<int,object>
	 */
	protected function queueRows() {
		global $wpdb;

		$table = Send4WC_Queue::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The single queue row, failing the test when there is not exactly one.
	 *
	 * @return object
	 */
	protected function onlyQueueRow() {
		$rows = $this->queueRows();

		$this->assertCount( 1, $rows, 'Expected exactly one queue row.' );

		return $rows[0];
	}

	/**
	 * Queue a reference by hand, the way the order hook would.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $topic Topic.
	 * @param bool     $full  Whether a flow starts on it.
	 * @return object The queue row.
	 */
	protected function queueEvent( WC_Order $order, $topic = 'order.status.processing', $full = true ) {
		$this->assertTrue( Send4WC_Delivery::queue( Send4WC_Order_Payload::reference( $order, $topic, $full ), $topic ) );

		return $this->onlyQueueRow();
	}
}
