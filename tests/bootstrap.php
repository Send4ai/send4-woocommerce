<?php
/**
 * PHPUnit bootstrap: a real WordPress with WooCommerce and the plugin loaded.
 *
 * @package Send4WC
 */

// First, because wp-phpunit's autoload file is what puts WP_PHPUNIT__DIR into
// the environment.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$send4wc_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $send4wc_tests_dir || '' === $send4wc_tests_dir ) {
	$send4wc_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( false === $send4wc_tests_dir || '' === $send4wc_tests_dir ) {
	echo 'Could not locate the WordPress test library. Run composer install.' . PHP_EOL;
	exit( 1 );
}

$send4wc_tests_dir = rtrim( $send4wc_tests_dir, '/\\' );

/**
 * Create the test database if the server has not got one yet.
 *
 * WordPress's own installer connects straight to DB_NAME and gives up if it is
 * missing. CI's database service is started with the database already created,
 * but the development compose file's is not (and it is shared with the running
 * site, so it cannot simply be renamed), which would otherwise make
 * `docker compose run tests` fail on a fresh checkout.
 *
 * Defaults match tests/wp-config.php.
 *
 * @return void
 */
function send4wc_tests_ensure_database() {
	if ( ! function_exists( 'mysqli_connect' ) ) {
		return;
	}

	$host     = getenv( 'WP_TESTS_DB_HOST' );
	$name     = getenv( 'WP_TESTS_DB_NAME' );
	$user     = getenv( 'WP_TESTS_DB_USER' );
	$password = getenv( 'WP_TESTS_DB_PASSWORD' );

	$host     = ( false === $host || '' === $host ) ? '127.0.0.1' : $host;
	$name     = ( false === $name || '' === $name ) ? 'wp_test' : $name;
	$user     = ( false === $user || '' === $user ) ? 'root' : $user;
	$password = ( false === $password || '' === $password ) ? 'root' : $password;

	$port = 3306;

	if ( false !== strpos( $host, ':' ) ) {
		list( $host, $port ) = explode( ':', $host, 2 );
		$port                = (int) $port;
	}

	$link = @mysqli_connect( $host, $user, $password, '', $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! $link ) {
		return;
	}

	mysqli_query( $link, 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '', $name ) . '`' );
	mysqli_close( $link );
}

send4wc_tests_ensure_database();

require_once $send4wc_tests_dir . '/includes/functions.php';

/**
 * Load the plugins under test.
 *
 * roots/wordpress ships no wp-content, so WooCommerce lives where Composer put
 * it (tests/plugins) rather than anywhere relative to ABSPATH.
 *
 * @return void
 */
function send4wc_tests_load_plugins() {
	require_once dirname( __FILE__ ) . '/plugins/woocommerce/woocommerce.php';
	require_once dirname( __DIR__ ) . '/send4-for-woocommerce.php';
}

tests_add_filter( 'muplugins_loaded', 'send4wc_tests_load_plugins' );

require_once $send4wc_tests_dir . '/includes/bootstrap.php';

// The tables have to exist before the first test, because every test runs
// inside a transaction that is rolled back: a dbDelta issued from inside a test
// would create the table and then lose it (and MySQL DDL would commit the
// surrounding transaction on the way through, which is worse). WooCommerce's
// install creates its order tables (HPOS) and Action Scheduler's.
WC_Install::install();
Send4WC_Installer::install();

// WooCommerce's roles are added by its install, after WordPress built the role
// list for this request.
$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
wp_roles();

require_once dirname( __FILE__ ) . '/Support/HttpRecorder.php';
require_once dirname( __FILE__ ) . '/Support/Send4TestCase.php';
