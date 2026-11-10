<?php
/**
 * Maps Send4WC_* class names onto includes/. No Composer at runtime.
 *
 * The prefix does not start with `Send4_`, the forms plugin's, so neither
 * plugin's autoloader can ever load the other's classes.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classmap-style autoloader following the WordPress file naming convention.
 */
class Send4WC_Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve a class name to a file under includes/.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Send4WC_' ) ) {
			return;
		}

		$file = SEND4WC_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
