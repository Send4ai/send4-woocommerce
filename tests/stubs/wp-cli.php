<?php
/**
 * Hand-written WP-CLI stubs, for static analysis only.
 *
 * WP-CLI is not a Composer dependency of this plugin — it is the environment
 * the commands run inside — so its classes are only ever available at runtime,
 * behind the `defined( 'WP_CLI' ) && WP_CLI` guard in Send4WC_Plugin::boot().
 *
 * Only the surface Send4WC_Cli uses is declared.
 *
 * @see https://make.wordpress.org/cli/handbook/references/internal-api/
 * @package Send4WC
 */

/**
 * The WP-CLI runner.
 */
class WP_CLI {

	/**
	 * Register a command.
	 *
	 * @param string                $name     Command name.
	 * @param callable|object|string $callable Command implementation.
	 * @param array<string,mixed>   $args     Command arguments.
	 * @return bool
	 */
	public static function add_command( $name, $callable, $args = array() ) {}

	/**
	 * Print a message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( $message ) {}

	/**
	 * Print a success message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function success( $message ) {}

	/**
	 * Print a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function warning( $message ) {}

	/**
	 * Print an error and, unless told otherwise, halt.
	 *
	 * @param string $message Message.
	 * @param bool|int $exit  Exit code, or false to carry on.
	 * @return void
	 */
	public static function error( $message, $exit = true ) {}
}
