<?php
/**
 * A scripted stand-in for the WordPress HTTP API.
 *
 * @package Send4WC
 */

/**
 * Intercepts wp_remote_* through pre_http_request, hands back queued responses
 * and records what was asked for.
 *
 * The plugin's whole delivery contract is "what did we POST, and what did we do
 * with the answer", so the double records requests rather than merely counting
 * them: an assertion on the envelope is the point of most of these tests.
 */
class Send4WC_Http_Recorder {

	/**
	 * Responses still to be handed out, oldest first.
	 *
	 * @var array<int,array|WP_Error>
	 */
	private $responses = array();

	/**
	 * Every request seen, as
	 * array{url:string,method:string,headers:array,body:array|null,args:array}.
	 *
	 * `args` is the whole argument array WP_Http assembled, defaults and all.
	 * The plugin's request hardening — no redirects, TLS verified, a timeout a
	 * visitor can tolerate — lives entirely in those arguments, so a recorder
	 * that dropped them could not tell a hardened request from a naked one.
	 *
	 * @var array<int,array>
	 */
	private $requests = array();

	/**
	 * What to answer once the scripted responses run out.
	 *
	 * A hard failure rather than a default 200: an unexpected extra call is the
	 * bug most of these tests exist to catch.
	 *
	 * @var array|WP_Error|null
	 */
	private $fallback = null;

	/**
	 * Install the filter.
	 *
	 * @return void
	 */
	public function hook() {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Remove the filter.
	 *
	 * @return void
	 */
	public function unhook() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
	}

	/**
	 * The pre_http_request callback.
	 *
	 * @param false|array|WP_Error $preempt Whatever an earlier filter decided.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		unset( $preempt );

		$body = isset( $args['body'] ) ? $args['body'] : null;

		if ( is_string( $body ) ) {
			$decoded = json_decode( $body, true );
			$body    = is_array( $decoded ) ? $decoded : $body;
		}

		$this->requests[] = array(
			'url'     => $url,
			'method'  => isset( $args['method'] ) ? $args['method'] : 'GET',
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'    => $body,
			'args'    => is_array( $args ) ? $args : array(),
		);

		if ( ! empty( $this->responses ) ) {
			return array_shift( $this->responses );
		}

		if ( null !== $this->fallback ) {
			return $this->fallback;
		}

		return new WP_Error(
			'send4wc_test_unexpected_request',
			'Unexpected HTTP request to ' . $url . ' with no scripted response left.'
		);
	}

	/**
	 * Answer every unscripted request with this instead of failing.
	 *
	 * @param array|WP_Error $response Response.
	 * @return void
	 */
	public function fallback( $response ) {
		$this->fallback = $response;
	}

	/**
	 * Queue a raw response.
	 *
	 * @param array|WP_Error $response Response.
	 * @return void
	 */
	public function queue( $response ) {
		$this->responses[] = $response;
	}

	/**
	 * Queue a successful token exchange.
	 *
	 * @param string $token      Access token.
	 * @param int    $expires_in Lifetime in seconds.
	 * @return void
	 */
	public function queueToken( $token = 'test-token', $expires_in = 86400 ) {
		$this->queue(
			self::response(
				200,
				array(
					'access_token' => $token,
					'token_type'   => 'Bearer',
					'expires_in'   => $expires_in,
					'expires_at'   => gmdate( 'c', time() + $expires_in ),
				)
			)
		);
	}

	/**
	 * Queue a rejected token exchange.
	 *
	 * @param int    $code  HTTP status.
	 * @param string $error Error code the API returns.
	 * @return void
	 */
	public function queueTokenRejected( $code = 401, $error = 'invalid_client' ) {
		$this->queue(
			self::response(
				$code,
				array(
					'error'             => $error,
					'error_description' => 'The client credentials are invalid.',
				)
			)
		);
	}

	/**
	 * Queue an accepted order event.
	 *
	 * @param bool        $routed Did Send4 start a flow run?
	 * @param int|null    $run_id Flow run id.
	 * @param string|null $reason Why nothing was routed, e.g. `lead_opted_out`.
	 * @return void
	 */
	public function queue202( $routed = true, $run_id = 991, $reason = null ) {
		$body = array(
			'status'     => 'accepted',
			'routed'     => (bool) $routed,
			'ended_runs' => 0,
		);

		if ( $routed && null !== $run_id ) {
			$body['run_id'] = (int) $run_id;
			$body['held']   = false;
		}

		if ( null !== $reason ) {
			$body['reason'] = (string) $reason;
		}

		$this->queue( self::response( 202, $body ) );
	}

	/**
	 * Queue an order event Send4 has already received (same order, same status).
	 *
	 * @return void
	 */
	public function queueDuplicate() {
		$this->queue( self::response( 202, array( 'status' => 'duplicate' ) ) );
	}

	/**
	 * Queue Send4's "the run this event ends is mid-tick, come back" answer.
	 *
	 * @param int $retry_after Seconds in the Retry-After header.
	 * @return void
	 */
	public function queueRetry503( $retry_after = 15 ) {
		$response            = self::response( 503, array( 'status' => 'retry' ) );
		$response['headers'] = array(
			'content-type' => 'application/json',
			'retry-after'  => (string) $retry_after,
		);

		$this->queue( $response );
	}

	/**
	 * Queue the 409 a hand-made API key gets: Send4 cannot tell which store it is.
	 *
	 * @return void
	 */
	public function queue409() {
		$this->queue( self::response( 409, array( 'message' => 'This API key was not created by connecting a site.' ) ) );
	}

	/**
	 * Queue a rejected envelope.
	 *
	 * @param array $errors Validation error bag, field => messages.
	 * @return void
	 */
	public function queue422( array $errors = array( 'event.topic' => array( 'The event.topic field is required.' ) ) ) {
		$this->queue(
			self::response(
				422,
				array(
					'message' => 'The given data was invalid.',
					'errors'  => $errors,
				)
			)
		);
	}

	/**
	 * Queue a redirect.
	 *
	 * An unauthenticated route answers 302 to /login, so this is what a
	 * wrong base URL, a stale token or a firewall in the way actually looks
	 * like. The plugin sends with redirection => 0 precisely so that it sees
	 * the 302 rather than a 200 carrying somebody's login page.
	 *
	 * @param string $location Where the redirect points.
	 * @return void
	 */
	public function queue302( $location = 'https://app.send4.ai/login' ) {
		$response            = self::response( 302, '<!DOCTYPE html><title>Redirecting</title>' );
		$response['headers'] = array(
			'location'     => $location,
			'content-type' => 'text/html; charset=UTF-8',
		);

		$this->queue( $response );
	}

	/**
	 * Queue a 2xx whose body is not the JSON this API speaks.
	 *
	 * A caching layer, a "site under maintenance" page or a captive portal can
	 * all answer 200 with HTML.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return void
	 */
	public function queueNonJson( $code = 200, $body = '<!DOCTYPE html><title>Maintenance</title>' ) {
		$response            = self::response( $code, $body );
		$response['headers'] = array( 'content-type' => 'text/html; charset=UTF-8' );

		$this->queue( $response );
	}

	/**
	 * Queue a server error.
	 *
	 * @return void
	 */
	public function queue500() {
		$this->queue( self::response( 500, array( 'message' => 'Server Error' ) ) );
	}

	/**
	 * Queue a request that never completes.
	 *
	 * @return void
	 */
	public function queueTimeout() {
		$this->queue( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	}

	/**
	 * Queue a 403 from a client without the integrations.wordpress ability.
	 *
	 * @return void
	 */
	public function queue403() {
		$this->queue( self::response( 403, array( 'message' => 'This action is unauthorized.' ) ) );
	}

	/**
	 * Queue a handshake answer.
	 *
	 * @param string[] $start_topics     Topics a flow starts on.
	 * @param string   $client_name      Client name.
	 * @param string[] $lifecycle_topics Topics that end runs.
	 * @param array    $extra            More keys under `data`.
	 * @return void
	 */
	public function queueHandshake( array $start_topics = array( 'order.status.processing' ), $client_name = 'Baldai UAB', array $lifecycle_topics = array( 'order.status.cancelled', 'order.status.refunded' ), array $extra = array() ) {
		$this->queue(
			self::response(
				200,
				array(
					'data' => array_merge(
						array(
							'client' => array( 'name' => $client_name ),
							'flows'  => array(),
							'store'  => array(
								'start_topics'     => $start_topics,
								'lifecycle_topics' => $lifecycle_topics,
							),
						),
						$extra
					),
				)
			)
		);
	}

	/**
	 * Queue the 200 Send4 answers a store inventory push with.
	 *
	 * @return void
	 */
	public function queueStoreSaved() {
		$this->queue( self::response( 200, array( 'data' => array( 'statuses' => 0 ) ) ) );
	}

	/**
	 * How many requests have been made.
	 *
	 * @return int
	 */
	public function requestCount() {
		return count( $this->requests );
	}

	/**
	 * Every request seen.
	 *
	 * @return array<int,array>
	 */
	public function requests() {
		return $this->requests;
	}

	/**
	 * The decoded bodies of every request seen.
	 *
	 * @return array<int,mixed>
	 */
	public function bodies() {
		return array_map(
			static function ( $request ) {
				return $request['body'];
			},
			$this->requests
		);
	}

	/**
	 * The decoded body of the most recent request.
	 *
	 * @return mixed
	 */
	public function lastBody() {
		if ( empty( $this->requests ) ) {
			return null;
		}

		$last = $this->requests[ count( $this->requests ) - 1 ];

		return $last['body'];
	}

	/**
	 * The most recent request.
	 *
	 * @return array|null
	 */
	public function lastRequest() {
		if ( empty( $this->requests ) ) {
			return null;
		}

		return $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Requests whose URL ends with the given path.
	 *
	 * @param string $path Path below the API root.
	 * @return array<int,array>
	 */
	public function requestsTo( $path ) {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $path ) {
					return substr( $request['url'], -strlen( $path ) ) === $path;
				}
			)
		);
	}

	/**
	 * Forget every recorded request and every queued response.
	 *
	 * @return void
	 */
	public function reset() {
		$this->requests  = array();
		$this->responses = array();
		$this->fallback  = null;
	}

	/**
	 * Build a response array in the shape WP_Http returns.
	 *
	 * @param int   $code HTTP status code.
	 * @param mixed $body Body; arrays are JSON encoded.
	 * @return array
	 */
	public static function response( $code, $body = array() ) {
		return array(
			'response' => array(
				'code'    => (int) $code,
				'message' => get_status_header_desc( (int) $code ),
			),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
