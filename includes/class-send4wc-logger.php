<?php
/**
 * A small ring buffer of recent deliveries, shown on the settings screen.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logs to the option ring buffer and, when WP_DEBUG is on, to the PHP error log.
 *
 * Buyer data is never written here: entries carry the order id, the event id, the
 * topic and the outcome, but no phone numbers, names, addresses or emails.
 */
class Send4WC_Logger {

	const OPTION = 'send4wc_log';
	const LIMIT  = 50;

	/**
	 * Append an entry.
	 *
	 * @param string $level   info|warning|error.
	 * @param string $message Message, already PII-free.
	 * @param array  $context Extra scalar context.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		$entry = array(
			'at'      => gmdate( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'message' => $message,
			'context' => array_map( array( __CLASS__, 'stringify' ), $context ),
		);

		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? $entries : array();

		array_unshift( $entries, $entry );
		update_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ), false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$suffix = empty( $entry['context'] ) ? '' : ' ' . wp_json_encode( $entry['context'] );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[send4wc] ' . $level . ': ' . $message . $suffix );
		}
	}

	/**
	 * Everything currently buffered, newest first.
	 *
	 * @return array<int,array>
	 */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Empty the buffer.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Coerce context values to short strings.
	 *
	 * @param mixed $value Context value.
	 * @return string
	 */
	private static function stringify( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		return substr( (string) wp_json_encode( $value ), 0, 200 );
	}
}
