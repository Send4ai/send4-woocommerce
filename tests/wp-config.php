<?php
/**
 * The wp-config the WordPress test suite runs against.
 *
 * wp-phpunit loads this through WP_PHPUNIT__TESTS_CONFIG, which phpunit.xml.dist
 * sets. Everything that varies between a laptop, the tests container and CI
 * comes from WP_TESTS_DB_* so this file itself never needs editing.
 *
 * @package Send4WC
 */

/**
 * Read an environment variable, falling back to a default.
 *
 * @param string $name    Variable name.
 * @param string $default Value to use when unset or empty.
 * @return string
 */
function send4wc_tests_env( $name, $default ) {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $default : $value;
}

define( 'DB_HOST', send4wc_tests_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_NAME', send4wc_tests_env( 'WP_TESTS_DB_NAME', 'wp_test' ) );
define( 'DB_USER', send4wc_tests_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', send4wc_tests_env( 'WP_TESTS_DB_PASSWORD', 'root' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'ABSPATH', dirname( __DIR__ ) . '/tests/wordpress/' );

/*
 * Run the whole suite against a network install when WP_TESTS_MULTISITE is set.
 *
 * The plugin's multisite code exists because a network activation otherwise
 * gives exactly one subsite a queue table and the rest silently lose leads, so
 * that path needs a leg of its own rather than a unit test of the loop.
 * wp-phpunit reads this constant before it decides how to install WordPress.
 */
if ( '1' === send4wc_tests_env( 'WP_TESTS_MULTISITE', '0' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Send4 plugin tests' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );

/*
 * The API client refuses to send anywhere that is not a Send4 address, so the
 * suite has to look like production rather than like a made-up test host.
 *
 * The constant is deliberately left empty rather than pinned to that address.
 * A constant wins over the send4wc_api_base_url option and cannot be changed once
 * PHP is running, so pinning it would make the destination allow-list — the
 * plugin's SSRF and credential-exfiltration control — untestable end to end.
 * Empty, Send4WC_Settings::base_url() falls through to its production default,
 * which is the same https://app.send4.ai, and a test that needs a refused
 * destination can set the option.
 */
define( 'SEND4WC_API_BASE_URL', send4wc_tests_env( 'SEND4WC_TESTS_API_BASE_URL', '' ) );
