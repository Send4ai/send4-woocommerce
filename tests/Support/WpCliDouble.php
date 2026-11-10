<?php
/**
 * A recording stand-in for WP-CLI, for testing Send4WC_Cli at runtime.
 *
 * WP-CLI is the environment the commands run in, never a dependency, so the
 * suite has no WP_CLI class of its own. This one records what a command said
 * and turns WP_CLI::error() — which exits in the real thing — into an
 * exception, so a test can assert that nothing happened after it.
 *
 * The one copy every CLI test loads with require_once. Each test file once
 * declared its own, and two such files in one run is a fatal "cannot declare
 * class". tests/stubs/wp-cli.php declares WP_CLI for PHPStan only and is
 * never loaded at runtime.
 *
 * @package Send4WC
 */

if ( ! class_exists( 'WP_CLI', false ) ) {

	/**
	 * The WP-CLI surface Send4WC_Cli uses.
	 */
	class WP_CLI {

		/**
		 * Everything printed, as array{0:string,1:string} of level and message.
		 *
		 * @var array<int,array{0:string,1:string}>
		 */
		public static $output = array();

		/**
		 * Register a command. Nothing to do here.
		 *
		 * @param string $name     Command name.
		 * @param mixed  $callable Implementation.
		 * @param array  $args     Registration arguments.
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = array() ) {
			unset( $name, $callable, $args );

			return true;
		}

		/**
		 * Print a message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( $message ) {
			self::$output[] = array( 'log', (string) $message );
		}

		/**
		 * Print a success message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ) {
			self::$output[] = array( 'success', (string) $message );
		}

		/**
		 * Print a warning.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( $message ) {
			self::$output[] = array( 'warning', (string) $message );
		}

		/**
		 * Print an error and halt, which here means throw.
		 *
		 * @param string $message Message.
		 * @param bool   $exit    Ignored: this always halts.
		 * @return void
		 * @throws Send4WC_Cli_Halted Always.
		 */
		public static function error( $message, $exit = true ) {
			unset( $exit );

			self::$output[] = array( 'error', (string) $message );

			throw new Send4WC_Cli_Halted( (string) $message );
		}

		/**
		 * The messages printed at one level, in order.
		 *
		 * @param string $level log|success|warning|error.
		 * @return string[]
		 */
		public static function printed( $level ) {
			$messages = array();

			foreach ( self::$output as $line ) {
				if ( $level === $line[0] ) {
					$messages[] = $line[1];
				}
			}

			return $messages;
		}
	}
}

if ( ! class_exists( 'Send4WC_Cli_Halted', false ) ) {

	/**
	 * Thrown in place of WP-CLI's exit on error.
	 */
	class Send4WC_Cli_Halted extends Exception {
	}
}
