<?php
/**
 * Option storage. Every read/write of plugin state goes through here.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors over the options the plugin owns.
 */
class Send4WC_Settings {

	const CONNECTION_OPTION = 'send4wc_connection';
	const TOPICS_OPTION     = 'send4wc_store_topics';
	const STATUS_OPTION     = 'send4wc_delivery_status';
	const SYNC_OPTION       = 'send4wc_store_sync';
	const BASE_URL_OPTION   = 'send4wc_api_base_url';
	const DEFAULT_BASE_URL  = 'https://app.send4.ai';

	/**
	 * Connection settings: client id, secret, and what the last handshake said.
	 *
	 * @return array{client_id:string,client_secret:string,client_name:string,connected_at:string,connect_id:string,connect_secret:string}
	 */
	public static function connection() {
		$stored = get_option( self::CONNECTION_OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'client_name'    => '',
				'connected_at'   => '',
				// Only live between starting a connection and redeeming it.
				'connect_id'     => '',
				'connect_secret' => '',
			)
		);
	}

	/**
	 * Persist connection settings, merging over what is stored.
	 *
	 * @param array $values Partial connection settings.
	 * @return void
	 */
	public static function update_connection( array $values ) {
		update_option( self::CONNECTION_OPTION, array_merge( self::connection(), $values ), false );
	}

	/**
	 * Forget the credentials and everything derived from them.
	 *
	 * @return void
	 */
	public static function disconnect() {
		delete_option( self::CONNECTION_OPTION );
		// No topics, nothing sent: a disconnected store must not queue orders
		// it can never deliver.
		delete_option( self::TOPICS_OPTION );
		delete_option( self::SYNC_OPTION );
		Send4WC_Api_Client::forget_token();
	}

	/**
	 * Could this be a Send4 API client secret?
	 *
	 * Send4 issues `secret_` and 48 letters and digits. The check is a little
	 * wider than that, letters, digits, "_" and "-", 16 to 255 of them, so a
	 * future Send4 key never locks out a site still running this version.
	 * What it never admits is whitespace, markup or anything else
	 * sanitize_text_field() would change.
	 *
	 * @param mixed $secret Candidate secret.
	 * @return bool
	 */
	public static function is_client_secret( $secret ) {
		return is_string( $secret ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{16,255}\z/', $secret );
	}

	/**
	 * True when both halves of the credential are present.
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		$connection = self::connection();

		return '' !== $connection['client_id'] && '' !== $connection['client_secret'];
	}

	/**
	 * The API root, without a trailing slash.
	 *
	 * Constant wins over option so a container can pin it without touching the
	 * database, and the settings screen shows (read-only) where it points.
	 *
	 * @return string
	 */
	public static function base_url() {
		if ( defined( 'SEND4WC_API_BASE_URL' ) && '' !== SEND4WC_API_BASE_URL ) {
			return untrailingslashit( SEND4WC_API_BASE_URL );
		}

		$option = get_option( self::BASE_URL_OPTION, '' );

		if ( is_string( $option ) && '' !== $option ) {
			return untrailingslashit( $option );
		}

		return self::DEFAULT_BASE_URL;
	}

	/**
	 * True when the base URL is pinned by a constant and must not be edited.
	 *
	 * @return bool
	 */
	public static function base_url_is_locked() {
		return defined( 'SEND4WC_API_BASE_URL' ) && '' !== SEND4WC_API_BASE_URL;
	}

	/**
	 * Is the configured base URL somewhere we are willing to send a bearer token?
	 *
	 * Every request carries a credential, and each order event carries a
	 * buyer's name, phone and address. A writable destination would therefore
	 * be an exfiltration and SSRF primitive for any other plugin's option-write
	 * bug, so the destination is allow-listed rather than merely validated.
	 *
	 * @param string|null $url URL to check, defaults to the configured one.
	 * @return bool
	 */
	public static function base_url_is_allowed( $url = null ) {
		$url   = null === $url ? self::base_url() : (string) $url;
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return self::is_send4wc_host( $host );
		}

		// Plain HTTP is for development only, where there is no TLS to verify.
		return 'http' === $scheme && self::is_development_host( $host );
	}

	/**
	 * Is this somewhere we are willing to send the administrator's browser?
	 *
	 * The Connect button ends in a redirect to whatever authorize_url the API
	 * answered with. No credential travels with the browser, so the concern is
	 * not exfiltration but where the administrator lands: a redirect to an
	 * arbitrary host is a phishing primitive, and the API is trusted with the
	 * store's orders, not with the administrator's browser. So the destination
	 * is checked the way the API destination is, rather than taken on faith.
	 *
	 * Broader than base_url_is_allowed() on purpose. A development Laravel app
	 * is reached by the container over plain HTTP, but sends the browser to its
	 * own https://*.local address, and that has to keep working. Userinfo is
	 * refused outright: "https://app.send4.ai@evil.com" parses one way in PHP
	 * and another in a browser, and a check that reads it differently from
	 * the thing that follows it is not a check.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function authorize_url_is_allowed( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return self::is_send4wc_host( $host ) || self::is_development_host( $host );
		}

		return 'http' === $scheme && self::is_development_host( $host );
	}

	/**
	 * Either send4.ai itself or one of its subdomains. The match is on a label
	 * boundary, so notsend4.ai and send4.ai.evil.com both fail.
	 *
	 * @param string $host Lower-cased hostname.
	 * @return bool
	 */
	private static function is_send4wc_host( $host ) {
		return 'send4.ai' === $host || self::host_ends_with( $host, '.send4.ai' );
	}

	/**
	 * A developer's own machine, as a browser or a container would name it.
	 *
	 * @param string $host Lower-cased hostname.
	 * @return bool
	 */
	private static function is_development_host( $host ) {
		$local = array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' );

		return in_array( $host, $local, true ) || self::host_ends_with( $host, '.local' );
	}

	/**
	 * Suffix match on a hostname.
	 *
	 * @param string $host   Hostname.
	 * @param string $suffix Suffix including the leading dot.
	 * @return bool
	 */
	private static function host_ends_with( $host, $suffix ) {
		$length = strlen( $suffix );

		return strlen( $host ) > $length && substr( $host, -$length ) === $suffix;
	}

	/**
	 * The order topics Send4 asked for at the last handshake.
	 *
	 * Cached in an option, because the status change that needs them can happen
	 * inside a checkout, which must never wait on Send4. Refreshed on connect,
	 * by the hourly store sync and whenever an admin opens the dashboard.
	 *
	 * @return array{start_topics:string[],lifecycle_topics:string[],refreshed_at:string}
	 */
	public static function store_topics() {
		$stored = get_option( self::TOPICS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'start_topics'     => Send4WC_Api_Client::topics( $stored['start_topics'] ?? array() ),
			'lifecycle_topics' => Send4WC_Api_Client::topics( $stored['lifecycle_topics'] ?? array() ),
			'refreshed_at'     => isset( $stored['refreshed_at'] ) ? (string) $stored['refreshed_at'] : '',
		);
	}

	/**
	 * Remember the topic lists from a handshake.
	 *
	 * Autoloaded: the order hook reads it on every status change.
	 *
	 * @param array{start_topics?:mixed,lifecycle_topics?:mixed} $store The handshake's `store` block.
	 * @return void
	 */
	public static function update_store_topics( array $store ) {
		update_option(
			self::TOPICS_OPTION,
			array(
				'start_topics'     => Send4WC_Api_Client::topics( $store['start_topics'] ?? array() ),
				'lifecycle_topics' => Send4WC_Api_Client::topics( $store['lifecycle_topics'] ?? array() ),
				'refreshed_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			true
		);
	}

	/**
	 * The outcome of the last order event Send4 answered, or that failed.
	 *
	 * @return array{status:string,at:string,error:string}
	 */
	public static function delivery_status() {
		$stored = get_option( self::STATUS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'status' => isset( $stored['status'] ) ? (string) $stored['status'] : '',
			'at'     => isset( $stored['at'] ) ? (string) $stored['at'] : '',
			'error'  => isset( $stored['error'] ) ? (string) $stored['error'] : '',
		);
	}

	/**
	 * Record the outcome of an order event, for the dashboard.
	 *
	 * Never carries the order's contents: the error is the plugin's or Send4's
	 * own wording.
	 *
	 * @param string $status delivered, duplicate, unrouted, queued or failed.
	 * @param string $error  Detail, '' for none.
	 * @return void
	 */
	public static function record_delivery( $status, $error = '' ) {
		update_option(
			self::STATUS_OPTION,
			array(
				'status' => (string) $status,
				'at'     => gmdate( 'Y-m-d H:i:s' ),
				'error'  => (string) $error,
			),
			false
		);
	}

	/**
	 * When the store's inventory last reached Send4, and why the last try failed.
	 *
	 * @return array{at:string,error:string}
	 */
	public static function store_sync() {
		$stored = get_option( self::SYNC_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'at'    => isset( $stored['at'] ) ? (string) $stored['at'] : '',
			'error' => isset( $stored['error'] ) ? (string) $stored['error'] : '',
		);
	}

	/**
	 * Record a store sync: success clears the error, a failure keeps the last success.
	 *
	 * @param string $error '' when the sync succeeded.
	 * @return void
	 */
	public static function record_store_sync( $error = '' ) {
		$current = self::store_sync();

		update_option(
			self::SYNC_OPTION,
			array(
				'at'    => '' === $error ? gmdate( 'Y-m-d H:i:s' ) : $current['at'],
				'error' => (string) $error,
			),
			false
		);
	}
}
