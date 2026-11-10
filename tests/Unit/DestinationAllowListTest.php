<?php
/**
 * The destination allow-list: where the plugin is willing to send a credential.
 *
 * @package Send4WC
 */

/**
 * Send4WC_Settings::base_url_is_allowed() is a security control, not a validator.
 *
 * Order events are sent by the server, from the queue worker, each carrying a
 * bearer token and a buyer's name, phone and delivery address. Any other
 * plugin's option-write bug, and any compromised shop manager, would otherwise
 * be able to point those requests at a host of their choosing and collect both
 * the credential and every order. So the question these tests ask is never
 * "is this a URL?" but "would we hand this host our token?".
 */
class Send4WC_Destination_Allow_List_Test extends Send4WC_Test_Case {

	/**
	 * Destinations that are Send4, or a development machine.
	 *
	 * @dataProvider allowedProvider
	 *
	 * @param string $url Destination.
	 * @return void
	 */
	public function test_it_allows_send4_and_local_development( $url ) {
		$this->assertTrue(
			Send4WC_Settings::base_url_is_allowed( $url ),
			$url . ' should be an allowed destination.'
		);
	}

	/**
	 * Every destination the plugin must be willing to send to.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function allowedProvider() {
		return array(
			'production'             => array( 'https://app.send4.ai' ),
			'any Send4 subdomain'    => array( 'https://anything.send4.ai' ),
			'the apex domain'        => array( 'https://send4.ai' ),
			'a local docker-compose' => array( 'http://localhost:8090' ),
			'the host from Docker'   => array( 'http://host.docker.internal:8080' ),
		);
	}

	/**
	 * Destinations that must never receive a token.
	 *
	 * @dataProvider refusedProvider
	 *
	 * @param string $url    Destination.
	 * @param string $reason Why it is refused, for the failure message.
	 * @return void
	 */
	public function test_it_refuses_everything_else( $url, $reason ) {
		$this->assertFalse(
			Send4WC_Settings::base_url_is_allowed( $url ),
			$url . ' must be refused: ' . $reason
		);
	}

	/**
	 * The attacks and accidents the allow-list exists for.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function refusedProvider() {
		return array(
			'somebody else entirely'              => array(
				'https://evil.com',
				'it is not Send4 at all',
			),
			'a suffix that only looks like Send4' => array(
				'https://app.send4.ai.evil.com',
				'the Send4 name is a label of somebody else\'s domain',
			),
			'the cloud metadata endpoint'         => array(
				'http://169.254.169.254',
				'that is the SSRF target every cloud host has',
			),
			'plain http to production'            => array(
				'http://app.send4.ai',
				'there is no TLS to protect the credential in flight',
			),
			'a scheme that is not http'           => array(
				'ftp://app.send4.ai',
				'wp_remote_request would not speak it and it is not a Send4 API',
			),
			'not a URL at all'                    => array(
				'this is not a url',
				'it has neither a scheme nor a host',
			),
		);
	}

	/**
	 * A hostname that merely contains "send4.ai" is not a Send4 host: the match
	 * is on the dotted suffix, so `notsend4.ai` and `send4.ai.evil.com` both go.
	 *
	 * @return void
	 */
	public function test_the_suffix_match_is_on_a_label_boundary() {
		$this->assertFalse( Send4WC_Settings::base_url_is_allowed( 'https://notsend4.ai' ) );
		$this->assertFalse( Send4WC_Settings::base_url_is_allowed( 'https://send4.ai.evil.com' ) );
		$this->assertTrue( Send4WC_Settings::base_url_is_allowed( 'https://app.eu.send4.ai' ) );
	}

	/**
	 * With nothing configured the plugin points at production, which is allowed.
	 *
	 * @return void
	 */
	public function test_the_default_destination_is_allowed() {
		$this->assertSame( Send4WC_Settings::DEFAULT_BASE_URL, Send4WC_Settings::base_url() );
		$this->assertTrue( Send4WC_Settings::base_url_is_allowed() );
	}

	/**
	 * And the configured destination is what gets checked, not the default.
	 *
	 * @return void
	 */
	public function test_it_checks_the_configured_destination() {
		update_option( Send4WC_Settings::BASE_URL_OPTION, 'https://evil.com', false );

		$this->assertSame( 'https://evil.com', Send4WC_Settings::base_url() );
		$this->assertFalse( Send4WC_Settings::base_url_is_allowed() );
	}

	/**
	 * The other direction: where the Connect button may send the shop manager.
	 *
	 * No token travels with the browser, so this is about phishing rather than
	 * exfiltration, and it is a little broader than the API destination — a
	 * development app is reached over plain HTTP but approves over https on
	 * its own .local name.
	 *
	 * @dataProvider allowedApprovalLinkProvider
	 *
	 * @param string $url Approval link.
	 * @return void
	 */
	public function test_it_follows_an_approval_link_on_send4_or_a_development_machine( $url ) {
		$this->assertTrue( Send4WC_Settings::authorize_url_is_allowed( $url ), $url . ' should be followed.' );
	}

	/**
	 * Every approval link the plugin must be willing to follow.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function allowedApprovalLinkProvider() {
		return array(
			'production'                       => array( 'https://app.send4.ai/connect/wordpress/wpconnect_abc123' ),
			'a Send4 subdomain'                => array( 'https://app-staging.send4.ai/connect/wordpress/x' ),
			'a development app over https'     => array( 'https://app.send4.local/connect/wordpress/x' ),
			'a development app over http'      => array( 'http://localhost:8080/connect/wordpress/x' ),
			'the host as a container names it' => array( 'http://host.docker.internal:8080/connect/wordpress/x' ),
		);
	}

	/**
	 * Approval links that must never become a redirect.
	 *
	 * @dataProvider refusedApprovalLinkProvider
	 *
	 * @param string $url    Approval link.
	 * @param string $reason Why it is refused, for the failure message.
	 * @return void
	 */
	public function test_it_refuses_to_send_the_shop_manager_anywhere_else( $url, $reason ) {
		$this->assertFalse( Send4WC_Settings::authorize_url_is_allowed( $url ), $url . ' must be refused: ' . $reason );
	}

	/**
	 * The phishing shapes the check exists for.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function refusedApprovalLinkProvider() {
		return array(
			'somebody else entirely'     => array( 'https://evil.example/approve', 'it is not Send4' ),
			'a look-alike'               => array( 'https://app.send4.ai.evil.example/approve', 'send4.ai is a label of somebody else\'s domain' ),
			'plain http to production'   => array( 'http://app.send4.ai/approve', 'a downgrade nobody asked for' ),
			'Send4 as the userinfo'      => array( 'https://app.send4.ai@evil.example/approve', 'a browser lands on evil.example' ),
			'userinfo in front of Send4' => array( 'https://evil.example@app.send4.ai/approve', 'userinfo has no business in an approval link' ),
			'a javascript: URL'          => array( 'javascript:alert(1)', 'not a place' ),
			'a data: URL'                => array( 'data:text/html,<script>alert(1)</script>', 'not a place either' ),
			'scheme-relative'            => array( '//evil.example/approve', 'it inherits whatever scheme the browser has' ),
			'a bare path'                => array( '/wp-admin/', 'there is no host to check' ),
			'not a URL at all'           => array( 'approve me', 'neither scheme nor host' ),
		);
	}
}
