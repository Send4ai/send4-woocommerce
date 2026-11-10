<?php
/**
 * The constants send4-for-woocommerce.php defines at runtime, for static analysis only.
 *
 * PHPStan analyses each file on its own, so the constants the plugin bootstrap
 * defines are invisible to it. Declaring them here is what stops every use of
 * SEND4WC_PLUGIN_DIR reading as an undefined constant.
 *
 * SEND4WC_API_BASE_URL is deliberately absent: its value is environment-specific,
 * so phpstan.neon.dist lists it under dynamicConstantNames instead. Pinning a
 * value here would make Send4WC_Settings' "is it overridden?" checks look like
 * dead code.
 *
 * @package Send4WC
 */

define( 'SEND4WC_VERSION', '0.1.0' );
define( 'SEND4WC_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/send4-for-woocommerce.php' );
define( 'SEND4WC_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'SEND4WC_PLUGIN_URL', 'https://example.org/wp-content/plugins/send4-for-woocommerce/' );

// ABSPATH is deliberately not defined here: szepeviktor/phpstan-wordpress owns
// it, pins it to '/' in its own bootstrap and lists it as a dynamic constant.
// Redefining it would be ignored and would emit a notice mid-analysis.
