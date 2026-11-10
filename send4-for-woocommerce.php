<?php
/**
 * Plugin Name:          Send4 for WooCommerce
 * Plugin URI:           https://send4.ai
 * Description:          Texts each buyer when their order reaches a status you choose, such as "ready for delivery", and books the delivery or installation slot over SMS with Send4's AI.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Send4
 * Author URI:           https://send4.ai
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          send4-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.2
 * WC tested up to:      11.2
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

define( 'SEND4WC_VERSION', '0.1.0' );
define( 'SEND4WC_PLUGIN_FILE', __FILE__ );
define( 'SEND4WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEND4WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The Send4 API the plugin talks to.
 *
 * Overridable with a SEND4WC_API_BASE_URL constant in wp-config.php (used by the
 * development containers) or, failing that, the
 * send4wc_api_base_url option. Production sites need neither.
 */
if ( ! defined( 'SEND4WC_API_BASE_URL' ) ) {
	define( 'SEND4WC_API_BASE_URL', '' );
}

require_once SEND4WC_PLUGIN_DIR . 'includes/class-send4wc-autoloader.php';
Send4WC_Autoloader::register();

register_activation_hook( __FILE__, array( 'Send4WC_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Send4WC_Installer', 'deactivate' ) );

/**
 * The plugin instance.
 *
 * @return Send4WC_Plugin
 */
function send4wc() {
	return Send4WC_Plugin::instance();
}

send4wc()->boot();
