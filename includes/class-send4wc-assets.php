<?php
/**
 * Where the plugin's own scripts and stylesheets are served from.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asset URLs that change when the plugin does.
 *
 * WordPress busts caches with `?ver=`. A CDN set to ignore the query string —
 * a Cloudflare caching level, and the default on several hosts — never sees
 * that: a client's site went on serving 0.1.0's phone-field.js for a month-long
 * TTL after the plugin was updated, and nobody with access to the site could
 * purge it. A different file name is a different URL to every cache there is.
 *
 * bin/build-zip.sh writes a copy of each asset with the version in its name,
 * `phone-field.0.1.2.js` beside `phone-field.js`. A checkout has no copies, so
 * development serves the plain files.
 */
class Send4WC_Assets {

	/**
	 * The URL for one of the plugin's own files.
	 *
	 * @param string $path Path from the plugin root, e.g. 'public/js/phone-field.js'.
	 * @return string
	 */
	public static function url( $path ) {
		return SEND4WC_PLUGIN_URL . self::resolve( $path, SEND4WC_PLUGIN_DIR, SEND4WC_VERSION );
	}

	/**
	 * The versioned path when that file was shipped, the plain path otherwise.
	 *
	 * @param string $path    Path from the plugin root.
	 * @param string $dir     Plugin root, with a trailing slash.
	 * @param string $version Plugin version.
	 * @return string
	 */
	public static function resolve( $path, $dir, $version ) {
		$versioned = self::versioned( $path, $version );

		return file_exists( $dir . $versioned ) ? $versioned : $path;
	}

	/**
	 * `public/js/phone-field.js` to `public/js/phone-field.0.1.2.js`.
	 *
	 * @param string $path    Path from the plugin root.
	 * @param string $version Plugin version.
	 * @return string
	 */
	public static function versioned( $path, $version ) {
		$dot = strrpos( $path, '.' );

		if ( false === $dot ) {
			return $path;
		}

		return substr( $path, 0, $dot ) . '.' . $version . substr( $path, $dot );
	}
}
