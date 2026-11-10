<?php
/**
 * Token exchange, caching, and what happens when a token is revoked.
 *
 * @package Send4WC
 */

/**
 * Sign-in is rate limited to ten attempts a minute, so a store that signed in
 * for every order event would spend its budget on nothing. These tests pin the
 * behaviour that keeps a store from fighting itself: cache the token, re-exchange
 * only on a 401, and only once.
 */
class Send4WC_Token_Test extends Send4WC_Test_Case {

	/**
	 * The event Send4 would receive for an order reaching processing.
	 *
	 * @return array
	 */
	private function event() {
		$event = Send4WC_Order_Payload::build(
			Send4WC_Order_Payload::reference( $this->makeOrder(), 'order.status.processing', true )
		);

		$this->assertIsArray( $event );

		return $event;
	}

	/**
	 * The token lands in the transient with the expiry the API reported.
	 *
	 * @return void
	 */
	public function test_it_caches_the_token_with_its_expiry() {
		$this->http->queueToken( 'first-token', 86400 );

		$before = time();
		$token  = ( new Send4WC_Api_Client() )->token();
		$after  = time();

		$this->assertSame( 'first-token', $token );

		$cached = get_transient( Send4WC_Api_Client::TOKEN_TRANSIENT );

		$this->assertIsArray( $cached );
		$this->assertSame( 'first-token', $cached['token'] );
		$this->assertGreaterThanOrEqual( $before + 86400, $cached['expires_at'] );
		$this->assertLessThanOrEqual( $after + 86400, $cached['expires_at'] );

		$this->assertSame( 1, $this->http->requestCount() );
		$this->assertStringEndsWith( '/api/v2/auth/token', $this->http->requests()[0]['url'] );
		$this->assertSame(
			array(
				'client_id'     => 'test-client-id',
				'client_secret' => 'test-client-secret',
			),
			$this->http->requests()[0]['body']
		);
	}

	/**
	 * A cached token is reused rather than exchanged again.
	 *
	 * @return void
	 */
	public function test_it_reuses_a_cached_token() {
		$this->http->queueToken( 'first-token' );

		$client = new Send4WC_Api_Client();

		$this->assertSame( 'first-token', $client->token() );
		$this->assertSame( 'first-token', $client->token() );
		$this->assertSame( 'first-token', ( new Send4WC_Api_Client() )->token() );
		$this->assertSame( 1, $this->http->requestCount() );
	}

	/**
	 * A token inside the safety margin is replaced before it can fail a POST.
	 *
	 * The first token comes from a real exchange, so it is cached as this
	 * connection's own: the only reason left to replace it is that it is about
	 * to expire.
	 *
	 * @return void
	 */
	public function test_it_replaces_a_token_that_is_about_to_expire() {
		$this->assertLessThan( Send4WC_Api_Client::TOKEN_SAFETY_MARGIN, 120 );

		$this->http->queueToken( 'nearly-dead', 120 );
		$this->http->queueToken( 'fresh-token' );

		$this->assertSame( 'nearly-dead', ( new Send4WC_Api_Client() )->token() );
		$this->assertSame( 'fresh-token', ( new Send4WC_Api_Client() )->token() );
		$this->assertSame( 2, $this->http->requestCount() );
	}

	/**
	 * A sign-in that outlives a reconnect must not serve the new connection.
	 *
	 * The exchange is a network call, up to ten seconds of one, and a reconnect
	 * can land inside it: the credentials change, the cache is cleared, and then
	 * the old request comes back and writes the account the store has just left
	 * into the cache that was cleared for it. Nothing asked whose token that
	 * was, so every order event for the next day went out under it.
	 *
	 * The filter below is that other request: it runs while the exchange is on
	 * the wire, which is the only moment the ordering can be staged in one process.
	 *
	 * @return void
	 */
	public function test_a_sign_in_that_outlives_a_reconnect_does_not_serve_the_new_connection() {
		$reconnected = false;
		$reconnect   = static function ( $preempt, $args, $url ) use ( &$reconnected ) {
			unset( $args );

			if ( ! $reconnected && '/api/v2/auth/token' === substr( $url, -18 ) ) {
				$reconnected = true;

				Send4WC_Settings::update_connection(
					array(
						'client_id'     => 'workspace-b',
						'client_secret' => 'secret-b',
					)
				);
				Send4WC_Api_Client::forget_token();
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $reconnect, 5, 3 );

		$this->http->queueToken( 'token-of-the-account-it-left' );

		// The request that was already under way still gets its own answer.
		$this->assertSame( 'token-of-the-account-it-left', ( new Send4WC_Api_Client() )->token() );

		remove_filter( 'pre_http_request', $reconnect, 5 );

		// Queued after the reconnect, so it is workspace B's order event.
		$this->queueEvent( $this->makeOrder() );

		$this->http->queueToken( 'token-of-workspace-b' );
		$this->http->queue202();

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['delivered'] );

		$exchanges = $this->http->requestsTo( '/api/v2/auth/token' );
		$events    = $this->http->requestsTo( '/api/integrations/wordpress/store-events' );

		$this->assertCount( 2, $exchanges, 'The stale token must cost the new connection a sign-in of its own.' );
		$this->assertSame( 'workspace-b', $exchanges[1]['body']['client_id'] );
		$this->assertCount( 1, $events );
		$this->assertSame( 'Bearer token-of-workspace-b', $events[0]['headers']['Authorization'] );
	}

	/**
	 * Nor is a token sent anywhere but the API that issued it. `wp send4wc
	 * connect --url-base` moves a store between Send4 environments, and
	 * production's bearer token is nothing staging should ever be shown.
	 *
	 * @return void
	 */
	public function test_a_cached_token_is_not_sent_to_a_different_destination() {
		$this->http->queueToken( 'production-token' );

		$this->assertSame( 'production-token', ( new Send4WC_Api_Client() )->token() );

		update_option( Send4WC_Settings::BASE_URL_OPTION, 'https://staging.send4.ai', false );

		$this->http->queueToken( 'staging-token' );

		$this->assertSame( 'staging-token', ( new Send4WC_Api_Client() )->token() );

		$exchanges = $this->http->requestsTo( '/api/v2/auth/token' );

		$this->assertCount( 2, $exchanges );
		$this->assertStringStartsWith( 'https://staging.send4.ai/', $exchanges[1]['url'] );
	}

	/**
	 * A token cached without saying whose it is is not trusted: it costs one
	 * sign-in, once.
	 *
	 * @return void
	 */
	public function test_a_token_cached_without_its_owner_is_exchanged_again() {
		set_transient(
			Send4WC_Api_Client::TOKEN_TRANSIENT,
			array(
				'token'      => 'cached-without-an-owner',
				'expires_at' => time() + 86400,
			),
			86400
		);

		$this->http->queueToken( 'fresh-token' );

		$this->assertSame( 'fresh-token', ( new Send4WC_Api_Client() )->token() );
		$this->assertSame( 'fresh-token', ( new Send4WC_Api_Client() )->token(), 'And the replacement is cached as usual.' );
		$this->assertSame( 1, $this->http->requestCount() );
	}

	/**
	 * A revoked token costs one re-exchange and one replay, and then succeeds.
	 *
	 * @return void
	 */
	public function test_a_401_triggers_exactly_one_re_exchange_and_a_replay() {
		$this->http->queueToken( 'stale-token' );
		$this->http->queue( Send4WC_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->http->queueToken( 'fresh-token' );
		$this->http->queue202( true, 4242 );

		$result = ( new Send4WC_Api_Client() )->send_store_event( $this->event() );

		$this->assertSame( Send4WC_Api_Result::DELIVERED, $result->outcome );
		$this->assertSame( 4242, $result->run_id );

		$this->assertSame( 4, $this->http->requestCount() );
		$this->assertCount( 2, $this->http->requestsTo( '/api/v2/auth/token' ) );

		$events = $this->http->requestsTo( '/api/integrations/wordpress/store-events' );

		$this->assertCount( 2, $events );
		$this->assertSame( 'Bearer stale-token', $events[0]['headers']['Authorization'] );
		$this->assertSame( 'Bearer fresh-token', $events[1]['headers']['Authorization'] );

		// The replay must carry the same event, or the retry is a second one.
		$this->assertSame( $events[0]['body'], $events[1]['body'] );
	}

	/**
	 * A second 401 is not chased: two exchanges in one send would be a loop.
	 *
	 * @return void
	 */
	public function test_it_does_not_chase_a_second_401() {
		$this->http->queueToken( 'stale-token' );
		$this->http->queue( Send4WC_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->http->queueToken( 'fresh-token' );
		$this->http->queue( Send4WC_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );

		$result = ( new Send4WC_Api_Client() )->send_store_event( $this->event() );

		$this->assertSame( Send4WC_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 401, $result->code );
		$this->assertSame( 4, $this->http->requestCount() );
	}

	/**
	 * When the credentials themselves have been revoked the order event is
	 * parked as a permanent failure, and no further exchange is attempted.
	 *
	 * @return void
	 */
	public function test_a_failed_re_exchange_parks_the_event_and_stops() {
		$queued = $this->queueEvent( $this->makeOrder() );

		$this->http->queueToken( 'stale-token' );
		$this->http->queue( Send4WC_Http_Recorder::response( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->http->queueTokenRejected( 401, 'invalid_client' );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 0, $summary['delivered'] );
		$this->assertSame( 1, $summary['failed'] );

		// Two exchanges: the first, and the one the 401 forced. Not a third.
		$this->assertCount( 2, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/store-events' ) );
		$this->assertSame( 3, $this->http->requestCount() );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status );
		$this->assertSame( $queued->event_id, $row->event_id );

		// And nothing retries it: process_queue only looks at pending rows.
		$this->assertSame(
			array(
				'attempted' => 0,
				'delivered' => 0,
				'failed'    => 0,
			),
			Send4WC_Delivery::process_queue()
		);
		$this->assertSame( 3, $this->http->requestCount() );
	}

	/**
	 * A token endpoint that is merely down is an outage, not a bad credential.
	 *
	 * This is the branch that decides whether a Send4 deploy costs a store its
	 * order events. Classifying it permanent would dead-letter every order that
	 * changed status during any ordinary outage, and there is nothing on the
	 * dashboard that would make that look different from a bad key. Every row
	 * would get the same answer, so the batch stops and leaves them as they are.
	 *
	 * @dataProvider tokenOutageProvider
	 *
	 * @param string $failure Which recorder helper stages the outage.
	 * @return void
	 */
	public function test_a_token_endpoint_outage_leaves_the_event_queued( $failure ) {
		$queued = $this->queueEvent( $this->makeOrder() );

		call_user_func( array( $this->http, $failure ) );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'] );
		$this->assertSame( 0, $summary['failed'] );

		// The exchange was attempted once and the event never went out.
		$this->assertCount( 1, $this->http->requestsTo( '/api/v2/auth/token' ) );
		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/store-events' ) );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( $queued->event_id, $row->event_id );
		$this->assertSame( 0, (int) $row->attempts, 'An outage of sign-in is not an attempt at the event.' );
		$this->assertSame( $queued->next_attempt_at, $row->next_attempt_at, 'Still due: the next run tries again.' );

		// Nothing was cached from a failed exchange.
		$this->assertFalse( get_transient( Send4WC_Api_Client::TOKEN_TRANSIENT ) );
	}

	/**
	 * The two ways a healthy API client meets an unhealthy Send4.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function tokenOutageProvider() {
		return array(
			'the endpoint answers 500'   => array( 'queue500' ),
			'the endpoint never answers' => array( 'queueTimeout' ),
		);
	}

	/**
	 * And once Send4 is back the queued event goes out, unchanged.
	 *
	 * @return void
	 */
	public function test_an_event_held_by_a_token_outage_is_delivered_on_the_next_pass() {
		$row = $this->queueEvent( $this->makeOrder() );

		$this->http->queue500();

		Send4WC_Delivery::process_queue();

		$this->http->queueToken( 'recovered-token' );
		$this->http->queue202( true, 4242 );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 1, $summary['attempted'] );
		$this->assertSame( 1, $summary['delivered'] );
		$this->assertSame( array(), $this->queueRows() );

		$events = $this->http->requestsTo( '/api/integrations/wordpress/store-events' );

		$this->assertCount( 1, $events );
		$this->assertSame( 'Bearer recovered-token', $events[0]['headers']['Authorization'] );

		// The same event, not a second one: Send4 dedupes on this id.
		$this->assertSame( $row->event_id, $events[0]['body']['event']['id'] );
	}

	/**
	 * Send4 rate limits sign-in attempts. Waiting is exactly the right answer,
	 * and the operator is told which wall was hit rather than "status 429".
	 *
	 * @return void
	 */
	public function test_a_rate_limited_exchange_says_so_and_stays_retryable() {
		$this->queueEvent( $this->makeOrder() );

		$this->http->queueTokenRejected( 429, 'too_many_requests' );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'] );
		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/store-events' ) );

		// The wording reaches the dashboard's recent activity.
		$entry = Send4WC_Logger::entries()[0];

		$this->assertSame( 'warning', $entry['level'] );
		$this->assertSame( '429', $entry['context']['code'] );
		$this->assertStringContainsString( 'rate limiting', $entry['context']['error'] );
		$this->assertStringContainsString( '10 per minute', $entry['context']['error'] );
	}

	/**
	 * Without credentials nothing is sent at all.
	 *
	 * @return void
	 */
	public function test_it_refuses_to_exchange_without_credentials() {
		$event = $this->event();

		Send4WC_Settings::disconnect();

		$result = ( new Send4WC_Api_Client() )->send_store_event( $event );

		$this->assertSame( Send4WC_Api_Result::PERMANENT, $result->outcome );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * The handshake reads the client and the order topics Send4 wants.
	 *
	 * @return void
	 */
	public function test_the_handshake_reports_the_client_and_its_topics() {
		$this->http->queueToken();
		$this->http->queueHandshake(
			array( 'order.status.processing' ),
			'Baldai UAB',
			array( 'order.status.cancelled', 'order.status.refunded' )
		);

		$handshake = ( new Send4WC_Api_Client() )->handshake();

		$this->assertIsArray( $handshake );
		$this->assertSame( array( 'client', 'store' ), array_keys( $handshake ), 'Nothing but the client and the store block.' );
		$this->assertSame( 'Baldai UAB', $handshake['client']['name'] );
		$this->assertSame( array( 'order.status.processing' ), $handshake['store']['start_topics'] );
		$this->assertSame( array( 'order.status.cancelled', 'order.status.refunded' ), $handshake['store']['lifecycle_topics'] );
	}

	/**
	 * The topic lists decide what leaves the store, so they are read strictly:
	 * only `order.status.<status>` in the shape Send4 accepts survives, once.
	 *
	 * @return void
	 */
	public function test_the_handshake_keeps_only_valid_order_topics() {
		$this->http->queueToken();
		$this->http->queueHandshake(
			array(
				'order.status.processing',
				'order.status.processing',
				'order.status.ready-for-delivery',
				'order.status.Processing',
				'order.status.',
				'order.status.-pending',
				'order.status.on hold',
				'order.created',
				'product.status.publish',
				'order.status.' . str_repeat( 'a', 61 ),
				42,
				null,
				array( 'order.status.completed' ),
			),
			'Baldai UAB',
			array( 'order.status.cancelled', 'customer.deleted', 'order.status.refunded' )
		);

		$handshake = ( new Send4WC_Api_Client() )->handshake();

		$this->assertSame(
			array( 'order.status.processing', 'order.status.ready-for-delivery' ),
			$handshake['store']['start_topics']
		);
		$this->assertSame(
			array( 'order.status.cancelled', 'order.status.refunded' ),
			$handshake['store']['lifecycle_topics']
		);
	}

	/**
	 * A topic that is valid only up to a trailing line break is not the topic,
	 * and is dropped like any other malformed one. (`$` in a PCRE pattern also
	 * matches just before a final "\n".)
	 *
	 * @return void
	 */
	public function test_a_topic_with_a_trailing_line_break_is_dropped() {
		$this->http->queueToken();
		$this->http->queueHandshake( array( "order.status.processing\n" ) );

		$handshake = ( new Send4WC_Api_Client() )->handshake();

		$this->assertSame( array(), $handshake['store']['start_topics'] );
	}

	/**
	 * A Send4 that sends no store block, or lists that are not lists, wants
	 * nothing: two empty lists, so no order leaves the store.
	 *
	 * @return void
	 */
	public function test_a_handshake_without_a_store_block_wants_nothing() {
		$this->http->queueToken();
		$this->http->queue(
			Send4WC_Http_Recorder::response(
				200,
				array( 'data' => array( 'client' => array( 'name' => 'Baldai UAB' ) ) )
			)
		);
		$this->http->queue(
			Send4WC_Http_Recorder::response(
				200,
				array(
					'data' => array(
						'client' => array( 'name' => 'Baldai UAB' ),
						'store'  => array(
							'start_topics'     => 'order.status.processing',
							'lifecycle_topics' => null,
						),
					),
				)
			)
		);

		$expected = array(
			'start_topics'     => array(),
			'lifecycle_topics' => array(),
		);

		$this->assertSame( $expected, ( new Send4WC_Api_Client() )->handshake()['store'] );
		$this->assertSame( $expected, ( new Send4WC_Api_Client() )->handshake()['store'] );
	}

	/**
	 * A client without the integrations.wordpress ability is told exactly that.
	 *
	 * @return void
	 */
	public function test_the_handshake_explains_a_missing_ability() {
		$this->http->queueToken();
		$this->http->queue403();

		$handshake = ( new Send4WC_Api_Client() )->handshake();

		$this->assertWPError( $handshake );
		$this->assertSame( 'send4wc_missing_ability', $handshake->get_error_code() );
	}
}
