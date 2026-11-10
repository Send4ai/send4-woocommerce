<?php
/**
 * How the plugin asks WordPress to make a request, rather than what it asks for.
 *
 * @package Send4WC
 */

/**
 * Three settings on every outbound call are load-bearing, and all three are
 * invisible in the response:
 *
 * - redirection => 0, because following a redirect hands the bearer token to
 *   whoever the redirect names, and an unauthenticated Laravel route answers
 *   302 to /login — which, followed, looks like a perfectly good 200 with an
 *   HTML body;
 * - sslverify => true, because the request carries a credential and a buyer's
 *   name, phone and address;
 * - a timeout that is chosen, not WordPress's default, so a Send4 outage holds
 *   a queue run or an admin screen for seconds rather than a TCP timeout.
 *
 * None of them changes the outcome of a scripted test, so each is asserted here
 * directly against the arguments WP_Http was handed.
 */
class Send4WC_Request_Hardening_Test extends Send4WC_Test_Case {

	/**
	 * Assert the hardening on every request recorded so far.
	 *
	 * @param int $expected How many requests there should be.
	 * @return void
	 */
	private function assertEveryRequestIsHardened( $expected ) {
		$requests = $this->http->requests();

		$this->assertCount( $expected, $requests );

		foreach ( $requests as $index => $request ) {
			$where = 'request ' . $index . ' (' . $request['url'] . ')';

			$this->assertSame(
				0,
				$request['args']['redirection'],
				$where . ' must not follow redirects: a redirect would hand the bearer token to whoever it names.'
			);
			$this->assertTrue(
				$request['args']['sslverify'],
				$where . ' must verify TLS: it carries a credential and a buyer\'s details.'
			);
			$this->assertTrue( $request['args']['blocking'], $where . ' must wait for the answer.' );
		}
	}

	/**
	 * The order-event path: token exchange, the event POST and the queue
	 * retry that follows a failure.
	 *
	 * @return void
	 */
	public function test_every_request_on_the_order_event_path_is_hardened() {
		$row = $this->queueEvent( $this->makeOrder() );

		$this->http->queueToken();
		$this->http->queue500();

		Send4WC_Delivery::process_queue();

		$this->makeDue( $row );
		$this->http->queue202();

		Send4WC_Delivery::process_queue();

		// Token, POST, retry POST — the retry reuses the cached token.
		$this->assertEveryRequestIsHardened( 3 );
		$this->assertCount( 2, $this->http->requestsTo( '/api/integrations/wordpress/store-events' ) );
	}

	/**
	 * The admin-facing connection test.
	 *
	 * @return void
	 */
	public function test_every_request_on_the_handshake_path_is_hardened() {
		$this->http->queueToken();
		$this->http->queueHandshake();

		( new Send4WC_Api_Client() )->handshake();

		$this->assertEveryRequestIsHardened( 2 );
	}

	/**
	 * And the store inventory push, which carries the bearer token too.
	 *
	 * @return void
	 */
	public function test_every_request_on_the_store_push_path_is_hardened() {
		$this->http->queueToken();
		$this->http->queueStoreSaved();

		$this->assertTrue( Send4WC_Store_Sync::push_inventory() );

		$this->assertEveryRequestIsHardened( 2 );
		$this->assertSame( 'PUT', $this->http->lastRequest()['method'] );
	}

	/**
	 * Somebody asked for the connection test, and an operator would rather
	 * wait a little than be told "timed out".
	 *
	 * The literal is asserted as well as the constant: a test written only
	 * against the constant would follow it wherever somebody moved it.
	 *
	 * @return void
	 */
	public function test_the_interactive_path_uses_the_longer_timeout() {
		$this->assertSame( 10, Send4WC_Api_Client::TIMEOUT_INTERACTIVE );
		$this->assertGreaterThan( Send4WC_Api_Client::TIMEOUT_SCREEN, Send4WC_Api_Client::TIMEOUT_INTERACTIVE );

		$this->http->queueToken();
		$this->http->queueHandshake();

		( new Send4WC_Api_Client() )->handshake();

		foreach ( $this->http->requests() as $request ) {
			$this->assertSame( Send4WC_Api_Client::TIMEOUT_INTERACTIVE, $request['args']['timeout'] );
		}
	}

	/**
	 * The queue worker is not on a buyer's request: the checkout only queued
	 * the event, so the worker can afford the longer timeout.
	 *
	 * @return void
	 */
	public function test_the_queue_worker_uses_the_longer_timeout() {
		$row = $this->queueEvent( $this->makeOrder() );

		$this->http->queueToken();
		$this->http->queue500();

		Send4WC_Delivery::process_queue();

		$this->makeDue( $row );
		$this->http->queue202();

		Send4WC_Delivery::process_queue();

		$this->assertSame( 3, $this->http->requestCount() );

		foreach ( $this->http->requests() as $request ) {
			$this->assertSame(
				Send4WC_Api_Client::TIMEOUT_INTERACTIVE,
				$request['args']['timeout'],
				$request['url'] . ' was made by the queue worker.'
			);
		}
	}

	/**
	 * A 302 is not a success. Read as one it would silently drop the order
	 * event and tell the operator everything is fine.
	 *
	 * @return void
	 */
	public function test_a_redirect_is_transient_and_keeps_the_event() {
		$order = $this->makeOrder();
		$this->queueEvent( $order );

		$this->http->queueToken();
		$this->http->queue302();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['delivered'] );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertStringContainsString( 'redirect', $row->last_error );
		$this->assertSame( (string) $order->get_id(), Send4WC_Queue::envelope( $row )['order']['id'] );
		$this->assertSame( 'queued', Send4WC_Settings::delivery_status()['status'] );
	}

	/**
	 * Neither is a 200 that is not this API talking: a caching layer, a captive
	 * portal or a maintenance page can all answer 200 with HTML.
	 *
	 * @dataProvider nonJsonSuccessProvider
	 *
	 * @param int $code Status code the imposter answered with.
	 * @return void
	 */
	public function test_a_success_that_is_not_json_is_transient( $code ) {
		$this->queueEvent( $this->makeOrder() );

		$this->http->queueToken();
		$this->http->queueNonJson( $code );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['delivered'] );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 1, (int) $row->attempts );
		$this->assertSame( 'queued', Send4WC_Settings::delivery_status()['status'] );
	}

	/**
	 * Both codes the client treats as success.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function nonJsonSuccessProvider() {
		return array(
			'200' => array( 200 ),
			'202' => array( 202 ),
		);
	}
}
