<?php
/**
 * The plugin object: wiring, and nothing else.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the order hooks, the queue worker, the store sync, the admin screen and
 * the CLI commands.
 */
class Send4WC_Plugin {

	/**
	 * Singleton.
	 *
	 * @var Send4WC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * The admin screen, when we are in wp-admin.
	 *
	 * @var Send4WC_Admin|null
	 */
	private $admin = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Send4WC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), 20 );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'wp_initialize_site', array( 'Send4WC_Installer', 'on_new_site' ), 20 );

		Send4WC_Privacy::boot();
		Send4WC_Cron::boot();
		Send4WC_Store_Sync::boot();

		if ( is_admin() ) {
			$this->admin = new Send4WC_Admin();
			$this->admin->boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Send4WC_Cli::register();
		}
	}

	/**
	 * Tell WooCommerce which of its features this plugin works with.
	 *
	 * Orders are only ever read through wc_get_order() and its getters, so it
	 * works with WooCommerce's own order tables (HPOS) and with posts; it never
	 * touches the cart or checkout, so the Cart and Checkout blocks are fine.
	 * Without the declaration WooCommerce warns about the plugin on both.
	 *
	 * @return void
	 */
	public static function declare_compatibility() {
		$features = 'Automattic\WooCommerce\Utilities\FeaturesUtil';

		if ( ! class_exists( $features ) ) {
			return;
		}

		call_user_func( array( $features, 'declare_compatibility' ), 'custom_order_tables', SEND4WC_PLUGIN_FILE, true );
		call_user_func( array( $features, 'declare_compatibility' ), 'cart_checkout_blocks', SEND4WC_PLUGIN_FILE, true );
	}

	/**
	 * Once every plugin is loaded we know whether WooCommerce is here.
	 *
	 * The order hooks are registered on every request, not just admin or front
	 * end: orders change status in checkout, REST, payment callbacks and cron.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		Send4WC_Installer::maybe_upgrade();

		if ( class_exists( 'WooCommerce' ) ) {
			Send4WC_Store_Events::boot();
		}
	}

	/**
	 * Scheduled events, once translations can be loaded.
	 *
	 * There is no load_plugin_textdomain() call: since WordPress 4.6 the
	 * plugin's domain is loaded just in time from the language packs
	 * WordPress.org builds.
	 *
	 * @return void
	 */
	public function on_init() {
		// On init, deliberately: scheduling runs the `cron_schedules` filter,
		// whose display names are translated, and translating before init
		// earns a `_load_textdomain_just_in_time` notice.
		Send4WC_Installer::ensure_events();
	}
}
