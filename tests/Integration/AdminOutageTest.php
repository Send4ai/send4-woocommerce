<?php
/**
 * The Send4 screen while Send4 is down, and connecting without losing a
 * working credential.
 *
 * @package Send4WC
 */

/**
 * During an outage the screen must stay fast, say what is actually happening
 * to the store's order events, and keep the way out (Test connection) in
 * reach. The order statuses Send4 asked for must survive the outage, or the
 * store stops queueing the very events the outage is holding up. And a new
 * credential must prove itself before it replaces one that works.
 */
class Send4WC_Admin_Outage_Test extends Send4WC_Test_Case {

	/**
	 * Send4WC_Redirected is declared beside the menu tests. Loaded here, once
	 * the suite is built, so that this file also runs on its own without the
	 * menu tests joining it.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once __DIR__ . '/AdminMenuTest.php';
	}

	/**
	 * Run as a shop manager.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
	}

	/**
	 * Undo the request globals a handler test sets.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Run an admin-post handler as wp-admin would, with a valid nonce, and
	 * report where it redirected.
	 *
	 * @param string $action The admin-post action, also the nonce action.
	 * @param array  $post   Posted fields.
	 * @return string|null
	 */
	private function post( $action, array $post = array() ) {
		$_POST                = $post;
		$_REQUEST             = $post;
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );

		$admin  = new Send4WC_Admin();
		$method = 'handle_' . preg_replace( '/^send4wc_/', '', $action );

		return $this->captureRedirect(
			static function () use ( $admin, $method ) {
				$admin->$method();
			}
		);
	}

	/**
	 * Run something and report where it tried to redirect to.
	 *
	 * @param callable $run What to run.
	 * @return string|null
	 */
	private function captureRedirect( callable $run ) {
		$spy = static function ( $location ) {
			throw new Send4WC_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			$run();

			return null;
		} catch ( Send4WC_Redirected $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}
	}

	/**
	 * The page= a redirect lands on.
	 *
	 * @param string|null $location Redirect target.
	 * @return string
	 */
	private function pageOf( $location ) {
		$this->assertNotNull( $location, 'Expected a redirect.' );

		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return isset( $query['page'] ) ? (string) $query['page'] : '';
	}

	/**
	 * The screen's markup.
	 *
	 * @return string
	 */
	private function render() {
		$admin = new Send4WC_Admin();

		ob_start();

		try {
			$admin->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Send4 answering nothing at all, however many times it is asked.
	 *
	 * @return void
	 */
	private function sendFourIsDown() {
		$this->http->fallback( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	}

	/**
	 * A handshake Send4 answered, as the screen caches it.
	 *
	 * @param string $name Account name.
	 * @return void
	 */
	private function rememberConnected( $name = 'Baldai UAB' ) {
		set_transient(
			Send4WC_Admin::HANDSHAKE_CACHE,
			array(
				'client' => array( 'name' => $name ),
				'store'  => Send4WC_Settings::store_topics(),
			),
			Send4WC_Admin::HANDSHAKE_CACHE_S
		);
	}

	// ── The handshake cache ─────────────────────────────────────────────────

	/**
	 * The screen used to ask again on every visit during an outage and wait
	 * out the timeout before it could draw.
	 *
	 * @return void
	 */
	public function test_a_failed_handshake_is_remembered_for_a_minute() {
		$this->http->queueTimeout();

		$admin = new Send4WC_Admin();

		$this->assertWPError( $admin->handshake( false ) );
		$this->assertSame( 1, $this->http->requestCount() );

		// Nothing queued: another request would fail the recorder anyway, but
		// the count says it outright.
		$again = $admin->handshake( false );

		$this->assertWPError( $again );
		$this->assertSame( 'http_request_failed', $again->get_error_code() );
		$this->assertSame( 1, $this->http->requestCount(), 'A cached failure must not be asked again.' );

		$expires = (int) get_option( '_transient_timeout_' . Send4WC_Admin::HANDSHAKE_CACHE );
		$this->assertLessThanOrEqual( time() + Send4WC_Admin::HANDSHAKE_FAILURE_S, $expires );
	}

	/**
	 * The error's details survive the cache, since the screen classifies on
	 * them.
	 *
	 * @return void
	 */
	public function test_a_remembered_failure_keeps_its_status() {
		$this->http->queueTokenRejected( 401 );

		( new Send4WC_Admin() )->handshake( false );

		$cached = Send4WC_Admin::cached_handshake();

		$this->assertWPError( $cached );
		$this->assertSame( 'send4wc_token_rejected', $cached->get_error_code() );
		$this->assertSame( 'rejected', Send4WC_Admin::connection_state( $cached ) );
	}

	/**
	 * Nobody asked for the handshake a screen makes while it draws, so it gives
	 * up sooner than one a button press asked for.
	 *
	 * @return void
	 */
	public function test_drawing_the_screen_waits_less_than_a_button_press() {
		$this->http->queueTimeout();

		( new Send4WC_Admin() )->handshake( false );

		$this->assertSame( Send4WC_Api_Client::TIMEOUT_SCREEN, $this->http->requests()[0]['args']['timeout'] );
		$this->assertLessThan( Send4WC_Api_Client::TIMEOUT_INTERACTIVE, Send4WC_Api_Client::TIMEOUT_SCREEN );
	}

	/**
	 * Test connection is the shop manager asking, so it neither trusts the
	 * cache nor gives up early.
	 *
	 * @return void
	 */
	public function test_test_connection_ignores_a_remembered_failure() {
		$this->http->queueTimeout();
		( new Send4WC_Admin() )->handshake( false );

		$this->http->reset();
		$this->http->queueToken();
		$this->http->queueHandshake();

		$destination = $this->post( 'send4wc_test' );

		$this->assertStringContainsString( 'send4wc_notice=success', $destination );
		$this->assertSame( Send4WC_Api_Client::TIMEOUT_INTERACTIVE, $this->http->requests()[0]['args']['timeout'] );
		$this->assertIsArray( Send4WC_Admin::cached_handshake(), 'The success replaces the remembered failure.' );
	}

	/**
	 * Otherwise the notice would say it failed while the screen still said
	 * "Connected" for up to five minutes.
	 *
	 * @return void
	 */
	public function test_a_failed_test_replaces_a_remembered_success() {
		$this->rememberConnected();
		$this->http->queueTimeout();

		$this->post( 'send4wc_test' );

		$this->assertWPError( Send4WC_Admin::cached_handshake() );
	}

	/**
	 * Every handshake Send4 answers is the freshest word on which order
	 * statuses it wants, so the order hook reads the lists it stored.
	 *
	 * @return void
	 */
	public function test_a_successful_handshake_also_stores_the_order_statuses() {
		$this->http->queueToken();
		$this->http->queueHandshake( array( 'order.status.ready-for-delivery' ), 'Baldai UAB', array( 'order.status.cancelled' ) );

		$this->assertIsArray( ( new Send4WC_Admin() )->handshake( false ) );

		$topics = Send4WC_Settings::store_topics();

		$this->assertSame( array( 'order.status.ready-for-delivery' ), $topics['start_topics'] );
		$this->assertSame( array( 'order.status.cancelled' ), $topics['lifecycle_topics'] );
		$this->assertNotSame( '', $topics['refreshed_at'] );
	}

	/**
	 * An outage must not cost the store its list. If a failed handshake
	 * emptied it, no order would be queued until Send4 answered again, and
	 * the queue exists for precisely that time.
	 *
	 * @return void
	 */
	public function test_a_failed_handshake_keeps_the_last_order_statuses() {
		$this->wantTopics( array( 'order.status.processing' ), array( 'order.status.cancelled' ) );
		$before = Send4WC_Settings::store_topics();

		$this->sendFourIsDown();

		( new Send4WC_Admin() )->handshake( false );
		$this->post( 'send4wc_test' );

		$this->assertSame( $before, Send4WC_Settings::store_topics() );
	}

	/**
	 * The states the screen describes. An outage queues order events and
	 * retries them; a refusal is not retried, so the screen has to tell them
	 * apart the way the delivery path does.
	 *
	 * @return void
	 */
	public function test_it_tells_an_outage_from_a_refusal() {
		$this->assertSame( 'unreachable', Send4WC_Admin::connection_state( new WP_Error( 'http_request_failed', 'timed out' ) ) );
		$this->assertSame( 'unreachable', Send4WC_Admin::connection_state( new WP_Error( 'send4wc_token_rejected', 'x', array( 'status' => 500 ) ) ) );
		$this->assertSame( 'unreachable', Send4WC_Admin::connection_state( new WP_Error( 'send4wc_token_rejected', 'x', array( 'status' => 429 ) ) ) );
		$this->assertSame( 'unreachable', Send4WC_Admin::connection_state( new WP_Error( 'send4wc_handshake_failed', 'x' ) ) );
		$this->assertSame( 'rejected', Send4WC_Admin::connection_state( new WP_Error( 'send4wc_token_rejected', 'x', array( 'status' => 401 ) ) ) );
		$this->assertSame( 'rejected', Send4WC_Admin::connection_state( new WP_Error( 'send4wc_token_rejected', 'x', array( 'status' => 400 ) ) ) );
		$this->assertSame( 'rejected', Send4WC_Admin::connection_state( new WP_Error( 'send4wc_missing_ability', 'x' ) ) );
		$this->assertSame( 'rejected', Send4WC_Admin::connection_state( new WP_Error( 'send4wc_bad_destination', 'x' ) ) );
		$this->assertSame( 'connected', Send4WC_Admin::connection_state( array( 'client' => array() ) ) );
		$this->assertSame( 'unchecked', Send4WC_Admin::connection_state( null ) );

		Send4WC_Settings::disconnect();

		$this->assertSame( 'disconnected', Send4WC_Admin::connection_state( new WP_Error( 'http_request_failed', 'timed out' ) ) );
	}

	// ── What the screen says during an outage ───────────────────────────────

	/**
	 * Order events ARE being queued during an outage, so anything that read
	 * as "not connected" or "nothing is sent" would be false. The statuses
	 * the store sends are still listed, because they come from the stored
	 * list rather than from the handshake that just failed.
	 *
	 * @return void
	 */
	public function test_the_screen_says_order_events_are_queued_during_an_outage() {
		$this->sendFourIsDown();

		$html = $this->render();

		$this->assertStringContainsString( 'Order events are being queued', $html );
		$this->assertStringContainsString( 'cURL error 28', $html );
		$this->assertStringContainsString( 'Send4 unreachable', $html, 'The app bar pill says the same.' );
		$this->assertStringContainsString( 'Starts a flow, with the order details', $html );
		$this->assertStringNotContainsString( 'Not connected', $html );
		$this->assertStringNotContainsString( 'Available once your account is connected', $html );
		$this->assertStringNotContainsString( 'no order is sent', $html );
	}

	/**
	 * The fix during an outage is to test again, not to connect again:
	 * connecting mints a new credential while the stored one may be fine.
	 *
	 * @return void
	 */
	public function test_the_screen_keeps_test_and_disconnect_during_an_outage() {
		$this->sendFourIsDown();

		$html = $this->render();

		$this->assertStringContainsString( 'Test connection', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
		$this->assertStringNotContainsString( 'Connect with Send4', $html );
	}

	/**
	 * A refused credential is a different story: order events are refused
	 * too, and reconnecting is a real fix.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_says_nothing_is_delivered_and_offers_to_reconnect() {
		$this->http->fallback( Send4WC_Http_Recorder::response( 401, array( 'error_description' => 'The client credentials are invalid.' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'not being delivered', $html );
		$this->assertStringNotContainsString( 'being queued', $html );
		$this->assertStringContainsString( 'Connection refused', $html );
		$this->assertStringContainsString( 'Test connection', $html );
		$this->assertStringContainsString( 'Connect with Send4', $html );
	}

	/**
	 * A handshake still fresh in the cache is the answer: drawing the screen
	 * again within five minutes does not go to the network.
	 *
	 * @return void
	 */
	public function test_the_screen_draws_from_a_fresh_cache_without_the_network() {
		$this->rememberConnected( 'Baldai UAB' );

		$html = $this->render();

		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertStringContainsString( 'Connected · Baldai UAB', $html );
	}

	/**
	 * With no credential there is nothing to ask Send4, so a store that is not
	 * connected draws at once.
	 *
	 * @return void
	 */
	public function test_a_disconnected_store_draws_without_the_network() {
		Send4WC_Settings::disconnect();

		$html = $this->render();

		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertStringContainsString( 'Not connected', $html );
		$this->assertStringContainsString( 'Connect with Send4', $html );
		$this->assertStringContainsString( 'Available once your account is connected', $html );
	}

	/**
	 * What a failed Test connection said reaches the screen it lands on, as
	 * an alert, once.
	 *
	 * @return void
	 */
	public function test_the_outcome_of_a_test_is_shown_once_on_the_screen() {
		$this->http->queueTimeout();

		$destination = $this->post( 'send4wc_test' );

		wp_parse_str( (string) wp_parse_url( $destination, PHP_URL_QUERY ), $query );
		$_GET = $query;

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/send4wc-notice--error" role="alert">\s*<p>cURL error 28: Operation timed out<\/p>/', $html );

		$this->assertStringNotContainsString( 'role="alert"', $this->render(), 'A reload does not repeat it.' );
	}

	/**
	 * The screen has up to seven small forms. Core's wp_nonce_field() would
	 * print id="_wpnonce" in every one of them.
	 *
	 * @return void
	 */
	public function test_several_forms_on_one_screen_do_not_repeat_an_id() {
		// A dead-lettered event to resend, a waiting one to send, and a log.
		$dead = $this->queueEvent( $this->makeOrder() );
		Send4WC_Queue::record_permanent_failure( $dead, 'Send4 refused the order event (status 400).' );

		$this->assertTrue(
			Send4WC_Delivery::queue( Send4WC_Order_Payload::reference( $this->makeOrder(), 'order.status.processing', true ), 'order.status.processing' )
		);

		$states = array(
			'refused'   => function () {
				$this->http->fallback( Send4WC_Http_Recorder::response( 401, array() ) );
			},
			'connected' => function () {
				$this->rememberConnected();
			},
		);

		foreach ( $states as $state => $arrange ) {
			$arrange();

			$html = $this->render();

			preg_match_all( '/name="action" value="([^"]+)"/', $html, $actions );

			$this->assertGreaterThanOrEqual( 5, count( $actions[1] ), "The {$state} screen should have several forms, or this proves nothing." );
			$this->assertSame( count( $actions[1] ), substr_count( $html, 'name="_wpnonce"' ), "Every form on the {$state} screen carries a nonce." );
			$this->assertLessThanOrEqual( 1, substr_count( $html, 'id="_wpnonce"' ), "The {$state} screen repeats id=\"_wpnonce\"." );
			$this->assertLessThanOrEqual( 1, substr_count( $html, 'id="_wp_http_referer"' ), "The {$state} screen repeats id=\"_wp_http_referer\"." );
		}

		$this->assertContains( 'send4wc_run_queue', $actions[1] );
		$this->assertContains( 'send4wc_requeue', $actions[1] );
		$this->assertContains( 'send4wc_clear_log', $actions[1] );
	}

	/**
	 * check_admin_referer() reads the field by name, so dropping the id must
	 * not cost the nonce.
	 *
	 * @return void
	 */
	public function test_the_id_less_nonce_still_verifies() {
		$admin = new Send4WC_Admin();

		ob_start();
		$admin->nonce_field( 'send4wc_test' );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([^"]+)"/', $html );
		preg_match( '/value="([^"]+)"/', $html, $match );

		$this->assertNotFalse( wp_verify_nonce( $match[1], 'send4wc_test' ) );
	}

	// ── Connecting ──────────────────────────────────────────────────────────

	/**
	 * A refused candidate used to replace the working credential before the
	 * handshake ran, so every later order event was refused and
	 * dead-lettered. Now nothing is written until the candidate is proven.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_does_not_replace_the_working_one() {
		$topics = Send4WC_Settings::store_topics();

		$this->http->queueTokenRejected( 401 );

		$result = Send4WC_Admin::establish_connection( 'typo-id', 'secret_typo' . str_repeat( 'x', 44 ) );

		$this->assertWPError( $result );

		$connection = Send4WC_Settings::connection();
		$this->assertSame( 'test-client-id', $connection['client_id'] );
		$this->assertSame( 'test-client-secret', $connection['client_secret'] );
		$this->assertSame( 'Baldai UAB', $connection['client_name'], 'The working connection is left exactly as it was.' );
		$this->assertSame( $topics, Send4WC_Settings::store_topics(), 'And so are the order statuses it sends.' );
		$this->assertSame( array(), $this->http->requestsTo( '/api/integrations/wordpress/store' ), 'Nothing is pushed for a credential that failed.' );

		// And it really was the candidate that was tried.
		$this->assertSame( 'typo-id', $this->http->requests()[0]['body']['client_id'] );
	}

	/**
	 * The stored credential's cached token would make any candidate look valid.
	 *
	 * @return void
	 */
	public function test_a_candidate_signs_in_for_itself_rather_than_borrowing_the_cached_token() {
		set_transient(
			Send4WC_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'token-of-the-stored-credential',
				'expires_at' => time() + DAY_IN_SECONDS,
			),
			DAY_IN_SECONDS
		);
		$this->http->queueTokenRejected( 401 );

		$result = Send4WC_Admin::establish_connection( 'new-id', 'new-secret' );

		$this->assertWPError( $result );
		$this->assertStringEndsWith( '/api/v2/auth/token', $this->http->requests()[0]['url'] );
		$this->assertSame(
			'token-of-the-stored-credential',
			get_transient( Send4WC_Api_Client::TOKEN_TRANSIENT )['token'],
			'A failed candidate must not disturb the stored credential\'s token either.'
		);
	}

	/**
	 * A proven credential is saved, the old token goes, and the workspace,
	 * possibly a different one, is told about the store at once rather than
	 * at the next hourly sync.
	 *
	 * @return void
	 */
	public function test_a_proven_credential_is_stored_and_the_old_token_dropped() {
		set_transient(
			Send4WC_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'token-of-the-old-credential',
				'expires_at' => time() + DAY_IN_SECONDS,
			),
			DAY_IN_SECONDS
		);
		$this->http->queueToken( 'candidate-token' );
		$this->http->queueHandshake( array( 'order.status.ready-for-delivery' ), 'New Workspace' );
		// The store inventory push that follows a connect.
		$this->http->queueToken( 'stored-token' );
		$this->http->queueStoreSaved();

		$result = Send4WC_Admin::establish_connection( 'new-id', 'new-secret' );

		$this->assertIsArray( $result );

		$connection = Send4WC_Settings::connection();
		$this->assertSame( 'new-id', $connection['client_id'] );
		$this->assertSame( 'new-secret', $connection['client_secret'] );
		$this->assertSame( 'New Workspace', $connection['client_name'] );

		$token = get_transient( Send4WC_Api_Client::TOKEN_TRANSIENT );
		$this->assertNotSame( 'token-of-the-old-credential', is_array( $token ) ? $token['token'] : '' );

		$this->assertSame( array( 'order.status.ready-for-delivery' ), Send4WC_Settings::store_topics()['start_topics'], 'The new workspace\'s statuses are the ones sent from now on.' );

		$pushes = $this->http->requestsTo( '/api/integrations/wordpress/store' );
		$this->assertCount( 1, $pushes );
		$this->assertSame( 'PUT', $pushes[0]['method'] );
		$this->assertSame( 'Bearer stored-token', $pushes[0]['headers']['Authorization'] );
		$this->assertNotSame( '', Send4WC_Settings::store_sync()['at'] );

		$this->assertSame( 'New Workspace', Send4WC_Admin::cached_handshake()['client']['name'] );
	}

	/**
	 * The Connect button's return leg goes through the same routine, so the
	 * one-shot pair is still dropped when the new credential fails.
	 *
	 * @return void
	 */
	public function test_a_failed_return_leg_keeps_the_old_credential_and_drops_the_one_shot_pair() {
		global $pagenow;

		Send4WC_Settings::update_connection(
			array(
				'connect_id'     => 'wpconnect_abc123',
				'connect_secret' => 'shhh',
			)
		);

		$pagenow                    = 'admin.php';
		$_GET['page']               = Send4WC_Admin::CONNECTION_SLUG;
		$_GET['send4wc_code']       = 'code-abc';
		$_GET['send4wc_connect_id'] = 'wpconnect_abc123';

		$this->http->queue(
			Send4WC_Http_Recorder::response(
				200,
				array(
					'client_id'     => 'minted-id',
					'client_secret' => 'minted-secret',
				)
			)
		);
		$this->http->queueTimeout();

		$admin       = new Send4WC_Admin();
		$destination = $this->captureRedirect(
			static function () use ( $admin ) {
				$admin->maybe_complete_connect();
			}
		);

		$this->assertStringContainsString( 'send4wc_notice=error', $destination );
		$this->assertSame( Send4WC_Admin::CONNECTION_SLUG, $this->pageOf( $destination ) );

		$connection = Send4WC_Settings::connection();
		$this->assertSame( 'test-client-id', $connection['client_id'] );
		$this->assertSame( '', $connection['connect_id'] );
	}

	// ── Where the buttons land ──────────────────────────────────────────────

	/**
	 * Every button is on the one screen, and every handler has to bring the
	 * shop manager back to it, beside the result and the button to try again.
	 * A handler still naming one of the forms plugin's old screens would land
	 * on "Sorry, you are not allowed to access this page."
	 *
	 * @return void
	 */
	public function test_every_action_returns_to_the_screen() {
		$this->http->queueToken();
		$this->http->queueHandshake();
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_test' ) ), 'Test, succeeding' );

		$this->http->queueTimeout();
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_test' ) ), 'Test, failing' );

		$this->http->queueTimeout();
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_sync_store' ) ), 'Sync now, failing' );

		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_run_queue' ) ), 'Send waiting events now' );
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_requeue' ) ), 'Resend failed events' );
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_clear_log' ) ), 'Clear the log' );

		$this->http->queue500();
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_start_connect' ) ), 'Connect with Send4, failing' );

		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_disconnect' ) ), 'Disconnect' );

		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_sync_store' ) ), 'Sync now, disconnected' );
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, $this->pageOf( $this->post( 'send4wc_requeue' ) ), 'Resend failed events, disconnected' );
	}

	// ── Small print ─────────────────────────────────────────────────────────

	/**
	 * Every outcome Send4WC_Settings::record_delivery() stores has words and a
	 * tone of its own; anything else reads as nothing yet, never as a slug.
	 *
	 * @return void
	 */
	public function test_every_delivery_outcome_has_a_label_and_a_tone() {
		$admin = new Send4WC_Admin();

		$expected = array(
			'delivered' => array( 'Delivered', 'ok' ),
			'duplicate' => array( 'Already received', 'info' ),
			'unrouted'  => array( 'Delivered, no flow started', 'info' ),
			'queued'    => array( 'Queued for retry', 'warn' ),
			'failed'    => array( 'Failed', 'bad' ),
			''          => array( 'No order events yet', 'neutral' ),
			'new-thing' => array( 'No order events yet', 'neutral' ),
		);

		foreach ( $expected as $status => $words ) {
			$this->assertSame( $words[0], $admin->status_label( $status ), 'Label for "' . $status . '"' );
			$this->assertSame( $words[1], $admin->status_tone( $status ), 'Tone for "' . $status . '"' );
		}
	}

	/**
	 * Times are stored in UTC and shown in the site's own format and zone.
	 *
	 * @return void
	 */
	public function test_timestamps_are_shown_in_the_sites_own_format_and_zone() {
		update_option( 'timezone_string', 'Europe/Vilnius' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		// 12:00 UTC is 15:00 in Vilnius in summer.
		$this->assertSame( '2026-07-01 15:00', ( new Send4WC_Admin() )->format_utc( '2026-07-01 12:00:00' ) );
		$this->assertSame( '', ( new Send4WC_Admin() )->format_utc( '' ) );
	}
}
