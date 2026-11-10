<?php
/**
 * What this site runs, as Send4 is told about it.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * The versions reported alongside the store inventory.
 *
 * WordPress.org cannot force an update, so once the plugin is listed every
 * version ever released can still be installed somewhere. Send4 reads these
 * before it changes anything an old version depends on, and before it raises
 * the minimum version it asks sites for. No visitor data is in here, only what
 * the site itself runs.
 */
class Send4WC_Environment {

	/**
	 * The object sent as `environment` on the store push.
	 *
	 * @return array{plugin_version:string,wp_version:string,php_version:string,wc_version:string|null,locale:string,multisite:bool}
	 */
	public static function report() {
		return array(
			'plugin_version' => SEND4WC_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'php_version'    => self::php_version(),
			'wc_version'     => self::woocommerce_version(),
			'locale'         => (string) get_locale(),
			'multisite'      => is_multisite(),
		);
	}

	/**
	 * PHP's own release number, without whatever the distribution appended.
	 *
	 * PHP_VERSION on a Debian or Ubuntu build reads like
	 * "8.3.14-1+ubuntu22.04.1+deb.sury.org+1", which is longer than the 32
	 * characters Send4 keeps, so it would arrive as nothing at all.
	 *
	 * @return string
	 */
	private static function php_version() {
		return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
	}

	/**
	 * WooCommerce's version when it is loaded here, else null.
	 *
	 * @return string|null
	 */
	private static function woocommerce_version() {
		if ( ! defined( 'WC_VERSION' ) ) {
			return null;
		}

		return '' !== WC_VERSION ? WC_VERSION : null;
	}
}
