<?php
/**
 * `wp send4wc connect`.
 *
 * @package Send4WC
 */

require_once dirname( __DIR__ ) . '/Support/WpCliDouble.php';

/**
 * The CLI connects exactly as the Connect button does: the credential is
 * proven before it is stored, and the store's inventory goes to the workspace
 * straight away.
 */
class Send4WC_Cli_Connect_Test extends Send4WC_Test_Case {

	/**
	 * A made-up secret in the shape Send4 issues.
	 *
	 * @var string
	 */
	const SECRET = 'secret_NotARealSecretNotARealSecretNotARealSecret000000';

	/**
	 * A clean output log.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::$output = array();
	}

	/**
	 * Run the command, returning whether it halted.
	 *
	 * @param array $flags Flags.
	 * @return bool
	 */
	private function connect( array $flags ) {
		try {
			( new Send4WC_Cli() )->connect( array(), $flags );

			return false;
		} catch ( Send4WC_Cli_Halted $halted ) {
			return true;
		}
	}

	/**
	 * Script a successful connect: the candidate's sign-in and handshake, then
	 * the stored credential's sign-in and the inventory push.
	 *
	 * @param string   $client_name  The workspace's name.
	 * @param string[] $start_topics Topics a flow starts on.
	 * @return void
	 */
	private function queueConnect( $client_name, array $start_topics = array( 'order.status.processing' ) ) {
		$this->http->queueToken( 'candidate-token' );
		$this->http->queueHandshake( $start_topics, $client_name );
		$this->http->queueToken( 'stored-token' );
		$this->http->queueStoreSaved();
	}

	/**
	 * A refused credential never replaces the one that works.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_is_not_stored() {
		$this->http->queueTokenRejected( 401 );

		$this->assertTrue(
			$this->connect(
				array(
					'client-id'     => 'typo-id',
					'client-secret' => self::SECRET,
				)
			)
		);

		$this->assertSame( 'test-client-id', Send4WC_Settings::connection()['client_id'] );
		$this->assertStringContainsString( 'still using the ones it had', WP_CLI::$output[0][1] );
	}

	/**
	 * --url-base used to be saved before the credentials were tried. A typo in
	 * the secret then left the store's working credentials pointed at the other
	 * environment: nothing was "saved", and the connection was broken anyway.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_leaves_the_destination_alone() {
		$this->http->queueTokenRejected( 401 );

		$this->assertTrue(
			$this->connect(
				array(
					'client-id'     => 'typo-id',
					'client-secret' => self::SECRET,
					'url-base'      => 'https://staging.send4.ai',
				)
			)
		);

		$this->assertFalse( get_option( Send4WC_Settings::BASE_URL_OPTION, false ), 'The destination must not move unless the connection does.' );
		$this->assertSame( Send4WC_Settings::DEFAULT_BASE_URL, Send4WC_Settings::base_url() );
		$this->assertSame( 'test-client-id', Send4WC_Settings::connection()['client_id'] );

		// And the pair was tried where it was meant to be used, not against
		// the destination the store already had.
		$this->assertSame( 1, $this->http->requestCount() );
		$this->assertSame( 'https://staging.send4.ai/api/v2/auth/token', $this->http->requests()[0]['url'] );
	}

	/**
	 * When the pair is accepted, the destination it was proven against is saved
	 * with it, and everything after goes there.
	 *
	 * @return void
	 */
	public function test_a_new_destination_is_saved_with_the_credentials_proven_against_it() {
		$this->queueConnect( 'Staging Workspace' );

		$this->assertFalse(
			$this->connect(
				array(
					'client-id'     => 'staging-id',
					'client-secret' => self::SECRET,
					'url-base'      => 'https://staging.send4.ai/',
				)
			)
		);

		$this->assertSame( 'https://staging.send4.ai', Send4WC_Settings::base_url() );
		$this->assertSame( 'staging-id', Send4WC_Settings::connection()['client_id'] );
		$this->assertSame( 4, $this->http->requestCount() );

		foreach ( $this->http->requests() as $request ) {
			$this->assertStringStartsWith( 'https://staging.send4.ai/api/', $request['url'] );
		}
	}

	/**
	 * A connect tells the new workspace what the store has at once, and the
	 * dashboard and the order hook read the new workspace's answer, not the
	 * one cached for the workspace the store has just left.
	 *
	 * @return void
	 */
	public function test_connecting_reports_the_store_and_refreshes_the_screens() {
		$this->wantTopics( array( 'order.status.completed' ) );
		set_transient(
			Send4WC_Admin::HANDSHAKE_CACHE,
			array(
				'client' => array( 'name' => 'Old Workspace' ),
				'store'  => array(
					'start_topics'     => array( 'order.status.completed' ),
					'lifecycle_topics' => array(),
				),
			),
			300
		);

		$this->queueConnect( 'New Workspace' );

		$this->assertFalse(
			$this->connect(
				array(
					'client-id'     => 'new-id',
					'client-secret' => self::SECRET,
				)
			)
		);

		$this->assertSame( 'new-id', Send4WC_Settings::connection()['client_id'] );
		$this->assertSame( 'New Workspace', Send4WC_Settings::connection()['client_name'] );

		$pushes = $this->http->requestsTo( '/api/integrations/wordpress/store' );

		$this->assertCount( 1, $pushes );
		$this->assertSame( 'PUT', $pushes[0]['method'] );
		$this->assertSame( 'Bearer stored-token', $pushes[0]['headers']['Authorization'], 'Pushed as the stored credential, not the candidate.' );
		$this->assertArrayHasKey( 'statuses', $pushes[0]['body'] );
		$this->assertNotSame( '', Send4WC_Settings::store_sync()['at'] );

		$this->assertSame( 'New Workspace', Send4WC_Admin::cached_handshake()['client']['name'] );
		$this->assertSame( array( 'order.status.processing' ), Send4WC_Settings::store_topics()['start_topics'] );

		$this->assertSame( 'success', WP_CLI::$output[0][0] );
		$this->assertStringContainsString( 'Connected to New Workspace', WP_CLI::$output[0][1] );
		$this->assertStringContainsString( 'Flows start on 1 order status(es)', WP_CLI::$output[0][1] );
	}

	/**
	 * The inventory push is not part of proving the credential: when it fails
	 * the store is still connected, and the hourly sync tries again.
	 *
	 * @return void
	 */
	public function test_a_failed_store_push_does_not_undo_the_connection() {
		$this->http->queueToken( 'candidate-token' );
		$this->http->queueHandshake( array( 'order.status.processing' ), 'New Workspace' );
		$this->http->queueToken( 'stored-token' );
		$this->http->queue500();

		$this->assertFalse(
			$this->connect(
				array(
					'client-id'     => 'new-id',
					'client-secret' => self::SECRET,
				)
			)
		);

		$this->assertSame( 'new-id', Send4WC_Settings::connection()['client_id'] );
		$this->assertStringContainsString( '500', Send4WC_Settings::store_sync()['error'] );
		$this->assertSame( 'success', WP_CLI::$output[0][0] );
	}
}
