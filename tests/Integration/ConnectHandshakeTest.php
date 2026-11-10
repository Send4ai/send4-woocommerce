<?php
/**
 * The Connect button, end to end on the plugin's side.
 *
 * @package Send4WC
 */

/**
 * What matters here is that the two short-lived values from leg 1 are kept,
 * presented once at redemption and then dropped — and that a code which did not
 * start on this store cannot be redeemed at all.
 */
class Send4WC_Connect_Handshake_Test extends Send4WC_Test_Case {

	/**
	 * Scripted registration response.
	 *
	 * @return void
	 */
	private function queueRegistration() {
		$this->http->queue(
			Send4WC_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://app.send4.ai/connect/wordpress/wpconnect_abc123',
				)
			)
		);
	}

	/**
	 * Leg 1 introduces the store and returns what leg 3 needs to redeem the code.
	 *
	 * @return void
	 */
	public function test_registering_keeps_the_pair_needed_to_redeem_later() {
		$this->queueRegistration();

		$registration = ( new Send4WC_Api_Client() )->register_site();

		$this->assertSame( 'wpconnect_abc123', $registration['connect_id'] );
		$this->assertSame( 'registration-secret', $registration['connect_secret'] );

		$body = $this->http->lastBody();
		$this->assertSame( 'wordpress', $body['platform'] );
		$this->assertSame( untrailingslashit( home_url() ), $body['site_url'] );
		// Send4 redirects the browser straight back to this URL, so it has to
		// be the screen that redeems the code, not wherever the plugin used to
		// keep its settings.
		$this->assertStringContainsString( 'admin.php?page=' . Send4WC_Admin::CONNECTION_SLUG, $body['admin_url'] );
	}

	/**
	 * Send4 keeps one connection per plugin on a site, so registering says
	 * which plugin is connecting. Without it Send4 would take this for the
	 * forms plugin, and connecting the store would disconnect the forms.
	 *
	 * The literal is asserted as well as the constant: it is the contract
	 * Send4 matches on, not a value this plugin is free to change.
	 *
	 * @return void
	 */
	public function test_registering_says_which_plugin_is_connecting() {
		$this->queueRegistration();

		( new Send4WC_Api_Client() )->register_site();

		$this->assertSame( 'send4-for-woocommerce', Send4WC_Api_Client::PLUGIN );
		$this->assertSame( 'send4-for-woocommerce', $this->http->lastBody()['plugin'] );
		$this->assertStringEndsWith( '/api/connect/register', $this->http->lastRequest()['url'] );
	}

	/**
	 * A refusal reaches the admin in Send4's own words.
	 *
	 * @return void
	 */
	public function test_a_failed_registration_is_reported_not_swallowed() {
		$this->http->queue(
			Send4WC_Http_Recorder::response( 422, array( 'message' => 'admin_url must be on the same site as site_url.' ) )
		);

		$result = ( new Send4WC_Api_Client() )->register_site();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'same site', $result->get_error_message() );
	}

	/**
	 * Leg 3 presents the one-time code together with both halves from leg 1.
	 *
	 * @return void
	 */
	public function test_the_exchange_presents_both_halves() {
		$this->http->queue(
			Send4WC_Http_Recorder::response(
				200,
				array(
					'client_id'     => 'client_x',
					'client_secret' => 'secret_y',
				)
			)
		);

		$credentials = ( new Send4WC_Api_Client() )->exchange_code( 'wpconnect_abc123', 'registration-secret', 'one-time-code' );

		$this->assertSame( 'client_x', $credentials['client_id'] );

		$body = $this->http->lastBody();
		$this->assertSame( 'wpconnect_abc123', $body['connect_id'] );
		$this->assertSame( 'registration-secret', $body['connect_secret'] );
		$this->assertSame( 'one-time-code', $body['code'] );
	}

	/**
	 * The approval link is followed by the administrator's browser, so a reply
	 * pointing anywhere but Send4 is refused at the API client — before it can
	 * reach a redirect, and whoever the caller is.
	 *
	 * @return void
	 */
	public function test_registration_refuses_an_approval_link_that_is_not_send4() {
		$this->http->queue(
			Send4WC_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://evil.example/approve',
				)
			)
		);

		$result = ( new Send4WC_Api_Client() )->register_site();

		$this->assertWPError( $result );
		$this->assertSame( 'send4wc_register_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'not on Send4', $result->get_error_message() );
	}
}
