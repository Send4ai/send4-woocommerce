<?php
/**
 * What an order event does when the configured destination is not Send4.
 *
 * @package Send4WC
 */

/**
 * The allow-list has to hold on the wire, not just in a pure function.
 *
 * Send4WC_Api_Client::request() checks it before wp_remote_request, so a store
 * whose send4wc_api_base_url option has been pointed somewhere else — by
 * another plugin's option-write bug, or by a compromised administrator — makes
 * no outbound request at all. Neither the bearer token nor the buyer's details
 * leave the store. That is the whole control, and it is worth exactly nothing
 * if it is only checked in a unit test that never reaches the HTTP layer.
 */
class Send4WC_Bad_Destination_Test extends Send4WC_Test_Case {

	/**
	 * Point the plugin somewhere it must refuse to go.
	 *
	 * @param string $url Destination.
	 * @return void
	 */
	private function pointAt( $url ) {
		update_option( Send4WC_Settings::BASE_URL_OPTION, $url, false );

		$this->assertSame( $url, Send4WC_Settings::base_url(), 'The test did not manage to move the destination.' );
	}

	/**
	 * Not one packet: no token exchange, no order-event POST.
	 *
	 * Scripted responses are queued deliberately. If the allow-list ever stops
	 * refusing, the request will succeed rather than blow up on an unscripted
	 * call, and only the request count will say so — which is what makes this
	 * an assertion about the control instead of an assertion about the double.
	 *
	 * @return void
	 */
	public function test_a_refused_destination_produces_no_http_request_at_all() {
		$this->pointAt( 'https://evil.com' );
		$this->queueEvent( $this->makeOrder() );

		$this->http->queueToken();
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame(
			0,
			$this->http->requestCount(),
			'A destination outside the allow-list must never be contacted, not even to exchange a token.'
		);

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 0, $summary['delivered'], 'Nothing was sent, so nothing can be delivered.' );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertStringContainsString( 'https://evil.com', $this->onlyQueueRow()->last_error );
	}

	/**
	 * The order event is kept rather than dropped: refusing to send is not the
	 * same as throwing a buyer's order away, and an operator can still fix the
	 * URL and requeue it. It just is not retried on a timer.
	 *
	 * @return void
	 */
	public function test_the_order_event_is_kept_on_the_queue_with_the_reason() {
		$this->pointAt( 'https://evil.com' );

		$queued = $this->queueEvent( $this->makeOrder() );

		Send4WC_Delivery::process_queue();

		$row = $this->onlyQueueRow();

		$this->assertStringContainsString( 'is not a Send4 address', $row->last_error );
		$this->assertSame( $queued->envelope, $row->envelope, 'The reference is kept as it was queued.' );
		$this->assertSame( 'failed', Send4WC_Settings::delivery_status()['status'] );

		/*
		 * Dead-lettered rather than retried: a destination outside the
		 * allow-list is a configuration mistake, not an outage, and no amount
		 * of waiting makes it allowed. The row stays so the order event is not
		 * lost and the settings screen can show why.
		 */
		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
	}

	/**
	 * Cron does not pick the row up again at all, because a dead-lettered row
	 * is not due for anything. Belt and braces: even if it did, the refusal
	 * lives in the request layer, so the cron path — which builds its own
	 * client — would refuse too rather than reaching evil.com.
	 *
	 * @return void
	 */
	public function test_the_dead_lettered_row_is_never_retried() {
		$this->pointAt( 'https://evil.com' );
		$this->queueEvent( $this->makeOrder() );

		Send4WC_Delivery::process_queue();

		$this->makeDue( $this->onlyQueueRow() );

		$this->http->queueToken();
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'], 'A dead-lettered row is not due.' );
		$this->assertSame( 0, $summary['delivered'] );
		$this->assertSame( 0, $this->http->requestCount() );

		// The order event is still there for an operator to look at.
		$row = $this->onlyQueueRow();
		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
		$this->assertStringContainsString( 'is not a Send4 address', $row->last_error );
	}

	/**
	 * The handshake the settings screen runs is refused for the same reason, so
	 * an operator who mistypes the URL is told rather than quietly probed.
	 *
	 * @return void
	 */
	public function test_the_connection_test_is_refused_too() {
		$this->pointAt( 'http://169.254.169.254' );

		$handshake = ( new Send4WC_Api_Client() )->handshake();

		$this->assertWPError( $handshake );
		$this->assertSame( 'send4wc_bad_destination', $handshake->get_error_code() );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * An allowed destination is still reached, so the refusals above are the
	 * allow-list working rather than the HTTP double being broken.
	 *
	 * @return void
	 */
	public function test_an_allowed_destination_is_still_reached() {
		$this->pointAt( 'https://staging.send4.ai' );
		$this->queueEvent( $this->makeOrder() );

		$this->http->queueToken();
		$this->http->queue202( true, 4242 );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['delivered'] );
		$this->assertSame( 2, $this->http->requestCount() );
		$this->assertStringStartsWith( 'https://staging.send4.ai/', $this->http->requests()[0]['url'] );
		$this->assertStringStartsWith( 'https://staging.send4.ai/', $this->http->requests()[1]['url'] );
	}
}
