<?php
/**
 * What each answer to an order-event POST means for the event.
 *
 * @package Send4WC
 */

/**
 * The classification decides between "deleted because Send4 has it", "kept
 * and retried" and "kept and parked", so a wrong answer here either loses an
 * order event — the one that should have texted a buyer or ended a run — or
 * retries a hopeless one for a day and a half.
 */
class Send4WC_Response_Classification_Test extends Send4WC_Test_Case {

	/**
	 * The event every classification sends, built once per test.
	 *
	 * @var array|null
	 */
	private $event = null;

	/**
	 * The event Send4 would receive for an order reaching processing.
	 *
	 * @return array
	 */
	private function event() {
		if ( null === $this->event ) {
			$this->event = Send4WC_Order_Payload::build(
				Send4WC_Order_Payload::reference( $this->makeOrder(), 'order.status.processing', true )
			);
		}

		$this->assertIsArray( $this->event );

		return $this->event;
	}

	/**
	 * Send once and return the classification.
	 *
	 * @param array $response The scripted answer.
	 * @return Send4WC_Api_Result
	 */
	private function classify( array $response ) {
		$event = $this->event();

		$this->http->queueToken();
		$this->http->queue( $response );

		return ( new Send4WC_Api_Client() )->send_store_event( $event );
	}

	/**
	 * A 2xx that is not this API's answer is not an acceptance.
	 *
	 * `{"maintenance":true}` used to default to status "accepted", routed
	 * false: UNROUTED, which is final, so the queue deleted the only copy.
	 *
	 * @dataProvider notAStoreEventResponseProvider
	 *
	 * @param int   $code HTTP status.
	 * @param array $body Response body.
	 * @return void
	 */
	public function test_a_success_that_is_not_a_store_event_response_is_transient( $code, array $body ) {
		$result = $this->classify( Send4WC_Http_Recorder::response( $code, $body ) );

		$this->assertSame( Send4WC_Api_Result::TRANSIENT, $result->outcome );
		$this->assertFalse( $result->is_final() );
	}

	/**
	 * Bodies a proxy, a load balancer or a half-deployed app might send.
	 *
	 * @return array<string,array{0:int,1:array}>
	 */
	public function notAStoreEventResponseProvider() {
		return array(
			'maintenance flag'        => array( 200, array( 'maintenance' => true ) ),
			'status without routed'   => array( 202, array( 'status' => 'accepted' ) ),
			'routed without status'   => array( 202, array( 'routed' => false ) ),
			'unknown status'          => array(
				202,
				array(
					'status' => 'ok',
					'routed' => true,
				),
			),
			'routed is not a boolean' => array(
				202,
				array(
					'status' => 'accepted',
					'routed' => 'yes',
				),
			),
			'rejected'                => array(
				200,
				array(
					'status' => 'rejected',
					'routed' => false,
				),
			),
		);
	}

	/**
	 * The real answers still mean what they meant.
	 *
	 * @return void
	 */
	public function test_the_real_answers_are_final() {
		$delivered = $this->classify(
			Send4WC_Http_Recorder::response(
				202,
				array(
					'status' => 'accepted',
					'routed' => true,
					'run_id' => 77,
				)
			)
		);

		$this->assertSame( Send4WC_Api_Result::DELIVERED, $delivered->outcome );
		$this->assertSame( 77, $delivered->run_id );

		delete_transient( Send4WC_Api_Client::TOKEN_TRANSIENT );

		$this->assertSame(
			Send4WC_Api_Result::UNROUTED,
			$this->classify(
				Send4WC_Http_Recorder::response(
					202,
					array(
						'status' => 'accepted',
						'routed' => false,
					)
				)
			)->outcome
		);

		delete_transient( Send4WC_Api_Client::TOKEN_TRANSIENT );

		$this->assertSame(
			Send4WC_Api_Result::DUPLICATE,
			$this->classify( Send4WC_Http_Recorder::response( 202, array( 'status' => 'duplicate' ) ) )->outcome
		);
	}

	/**
	 * An accepted event that started nothing says why on the dashboard: a
	 * buyer who opted out reads differently from a status no flow starts on.
	 *
	 * @return void
	 */
	public function test_an_unrouted_event_says_why() {
		$this->http->queueToken();
		$this->http->queue202( false, null, 'lead_opted_out' );
		$this->http->queue202( false, null );

		$client = new Send4WC_Api_Client();

		$opted_out = $client->send_store_event( $this->event() );
		$no_flow   = $client->send_store_event( $this->event() );

		$this->assertSame( Send4WC_Api_Result::UNROUTED, $opted_out->outcome );
		$this->assertStringContainsString( 'opted out', $opted_out->message );
		$this->assertSame( Send4WC_Api_Result::UNROUTED, $no_flow->outcome );
		$this->assertStringContainsString( 'No flow starts on this status', $no_flow->message );
	}

	/**
	 * The end-to-end version of the first test: a queued order event answered
	 * with a maintenance page stays on the queue.
	 *
	 * @return void
	 */
	public function test_a_queued_event_is_not_deleted_by_a_maintenance_answer() {
		$this->queueEvent( $this->makeOrder() );

		$this->http->queueToken();
		$this->http->queue( Send4WC_Http_Recorder::response( 200, array( 'maintenance' => true ) ) );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['delivered'] );
		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
	}

	/**
	 * The run this event would end is in the middle of answering the buyer, so
	 * Send4 wrote nothing and asked for the event again shortly. That is a
	 * retry at the time it named, never a refusal.
	 *
	 * @return void
	 */
	public function test_a_busy_conversation_asks_for_a_retry_after() {
		$this->http->queueToken();
		$this->http->queueRetry503( 15 );

		$result = ( new Send4WC_Api_Client() )->send_store_event( $this->event() );

		$this->assertSame( Send4WC_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 503, $result->code );
		$this->assertSame( 15, $result->retry_after );
		$this->assertStringContainsString( 'busy', $result->message );
	}

	/**
	 * On the queue, the wait Send4 named is honoured when it is longer than
	 * the backoff would have been.
	 *
	 * @return void
	 */
	public function test_a_queued_event_waits_as_long_as_a_busy_conversation_asks() {
		$this->queueEvent( $this->makeOrder() );

		$this->http->queueToken();
		$this->http->queueRetry503( 900 );

		$before = time();
		Send4WC_Delivery::process_queue();

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 1, (int) $row->attempts );
		$this->assertGreaterThan( Send4WC_Queue::delay_for_attempt( 1 ), 900 );
		$this->assertEqualsWithDelta( $before + 900, strtotime( $row->next_attempt_at . ' UTC' ), 5 );
	}

	/**
	 * A 503 that is not that answer is an ordinary outage, retried on the
	 * backoff with no wait of Send4's own.
	 *
	 * @return void
	 */
	public function test_a_plain_503_is_an_outage() {
		$result = $this->classify( Send4WC_Http_Recorder::response( 503, array( 'message' => 'Service Unavailable' ) ) );

		$this->assertSame( Send4WC_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 0, $result->retry_after );
		$this->assertStringNotContainsString( 'busy', $result->message );
	}

	/**
	 * A key made by hand, rather than by connecting this store, does not tell
	 * Send4 which store an order belongs to. Every event would get the
	 * same 409, so it is parked at once with the fix in the words.
	 *
	 * @return void
	 */
	public function test_a_409_is_permanent_and_says_to_reconnect() {
		$this->http->queueToken();
		$this->http->queue409();

		$result = ( new Send4WC_Api_Client() )->send_store_event( $this->event() );

		$this->assertSame( Send4WC_Api_Result::PERMANENT, $result->outcome );
		$this->assertSame( 409, $result->code );
		$this->assertSame( 'This API key was not created by connecting a site.', $result->message );

		delete_transient( Send4WC_Api_Client::TOKEN_TRANSIENT );

		$silent = $this->classify( Send4WC_Http_Recorder::response( 409, '' ) );

		$this->assertSame( Send4WC_Api_Result::PERMANENT, $silent->outcome );
		$this->assertStringContainsString( 'Reconnect', $silent->message );
	}

	/**
	 * A client error other than timeout, too-early or rate limiting gets the
	 * same answer on every retry, so it is parked at once rather than retried
	 * ten times over a day and a half.
	 *
	 * @dataProvider permanentClientErrorProvider
	 *
	 * @param int $code HTTP status.
	 * @return void
	 */
	public function test_a_hopeless_client_error_is_permanent( $code ) {
		$result = $this->classify( Send4WC_Http_Recorder::response( $code, array( 'message' => 'No.' ) ) );

		$this->assertSame( Send4WC_Api_Result::PERMANENT, $result->outcome );
		$this->assertSame( $code, $result->code );
		$this->assertSame( 'No.', $result->message, 'What Send4 said is kept for the dashboard.' );
	}

	/**
	 * Statuses no retry can change.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function permanentClientErrorProvider() {
		return array(
			'400' => array( 400 ),
			'404' => array( 404 ),
			'405' => array( 405 ),
			'409' => array( 409 ),
			'410' => array( 410 ),
			'413' => array( 413 ),
		);
	}

	/**
	 * Without a message the status is named instead.
	 *
	 * @return void
	 */
	public function test_a_hopeless_client_error_without_a_message_names_the_status() {
		$result = $this->classify( Send4WC_Http_Recorder::response( 404, '' ) );

		$this->assertSame( Send4WC_Api_Result::PERMANENT, $result->outcome );
		$this->assertStringContainsString( '404', $result->message );
	}

	/**
	 * These three are about timing, not about the event.
	 *
	 * @dataProvider retryableClientErrorProvider
	 *
	 * @param int $code HTTP status.
	 * @return void
	 */
	public function test_a_timing_client_error_is_transient( $code ) {
		$this->assertSame(
			Send4WC_Api_Result::TRANSIENT,
			$this->classify( Send4WC_Http_Recorder::response( $code, array() ) )->outcome
		);
	}

	/**
	 * Client errors a later attempt can fix.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function retryableClientErrorProvider() {
		return array(
			'408' => array( 408 ),
			'425' => array( 425 ),
			'429' => array( 429 ),
		);
	}

	/**
	 * A 429's Retry-After is carried on the result, in either of its forms.
	 *
	 * @dataProvider retryAfterProvider
	 *
	 * @param callable $header   Builds the header value.
	 * @param int      $expected Seconds expected.
	 * @return void
	 */
	public function test_a_rate_limit_carries_its_retry_after( $header, $expected ) {
		$response            = Send4WC_Http_Recorder::response( 429, array( 'message' => 'Too Many Attempts.' ) );
		$response['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => $header(),
		);

		$result = $this->classify( $response );

		$this->assertSame( Send4WC_Api_Result::TRANSIENT, $result->outcome );
		$this->assertEqualsWithDelta( $expected, $result->retry_after, 5 );
	}

	/**
	 * Seconds, an HTTP date, and nonsense.
	 *
	 * @return array<string,array{0:callable,1:int}>
	 */
	public function retryAfterProvider() {
		return array(
			'seconds'            => array(
				static function () {
					return '120';
				},
				120,
			),
			'HTTP date'          => array(
				static function () {
					return gmdate( 'D, d M Y H:i:s', time() + 900 ) . ' GMT';
				},
				900,
			),
			'a date in the past' => array(
				static function () {
					return gmdate( 'D, d M Y H:i:s', time() - 900 ) . ' GMT';
				},
				0,
			),
			'garbage'            => array(
				static function () {
					return 'soon';
				},
				0,
			),
			'absurd'             => array(
				static function () {
					return '99999999';
				},
				DAY_IN_SECONDS,
			),
		);
	}

	/**
	 * A rate-limited sign-in carries it too, since sign-in is the endpoint
	 * Send4 actually rate limits.
	 *
	 * @return void
	 */
	public function test_a_rate_limited_sign_in_carries_its_retry_after() {
		$event = $this->event();

		$response            = Send4WC_Http_Recorder::response( 429, array( 'error' => 'too_many_requests' ) );
		$response['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => '45',
		);
		$this->http->queue( $response );

		$result = ( new Send4WC_Api_Client() )->send_store_event( $event );

		$this->assertSame( Send4WC_Api_Result::TRANSIENT, $result->outcome );
		$this->assertSame( 45, $result->retry_after );
	}
}
