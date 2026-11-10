<?php
/**
 * The Send4 admin menu.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * The whole wp-admin surface: one screen under WooCommerce.
 */
class Send4WC_Admin {

	const PAGE_SLUG         = 'send4wc';
	const CONNECTION_SLUG   = 'send4wc';
	const CAPABILITY        = 'manage_woocommerce';
	const HANDSHAKE_CACHE   = 'send4wc_handshake';
	const HANDSHAKE_CACHE_S = 300;
	const NOTICE_CACHE_S    = 60;

	/**
	 * What a pending notice's transient is called, before the user id. A
	 * constant so the uninstall test can plant one: uninstall.php has to sweep
	 * these up by prefix, as it cannot know who was half way through a redirect.
	 */
	const NOTICE_PREFIX = 'send4wc_notice_';

	/**
	 * How long a failed handshake is remembered.
	 *
	 * Much shorter than a success, so a recovered API shows up within a minute,
	 * but long enough that clicking between screens during an outage does not
	 * wait on the network once per click.
	 */
	const HANDSHAKE_FAILURE_S = 60;

	/**
	 * The key a remembered failure is stored under inside the handshake cache.
	 * No handshake answer carries it, so the two cannot be mistaken for each
	 * other.
	 */
	const HANDSHAKE_FAILURE_KEY = 'send4wc_failure';

	/**
	 * The hook suffix WordPress gave our screen, keyed `page`.
	 *
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_complete_connect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SEND4WC_PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_post_send4wc_start_connect', array( $this, 'handle_start_connect' ) );
		add_action( 'admin_post_send4wc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_send4wc_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_send4wc_sync_store', array( $this, 'handle_sync_store' ) );
		add_action( 'admin_post_send4wc_run_queue', array( $this, 'handle_run_queue' ) );
		add_action( 'admin_post_send4wc_requeue', array( $this, 'handle_requeue' ) );
		add_action( 'admin_post_send4wc_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the screen, under WooCommerce.
	 *
	 * One page under WooCommerce rather than a top-level menu: the forms plugin
	 * `send4` already owns a "Send4" menu, and two of them on one site would
	 * be one too many.
	 *
	 * @return void
	 */
	public function register_page() {
		$this->hooks = array();

		$this->hooks['page'] = (string) add_submenu_page(
			'woocommerce',
			__( 'Send4', 'send4-for-woocommerce' ),
			__( 'Send4', 'send4-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// add_submenu_page() answers false for a user without the capability.
		$this->hooks = array_filter( $this->hooks );
	}

	/**
	 * Redeem the one-time code Send4 sends the browser back with.
	 *
	 * It arrives as a GET on the Connection screen rather than through
	 * admin-post.php, because Send4 is redirecting a browser, not posting a
	 * form. There is no WP nonce to check and none is needed: the exchange also
	 * requires the registration secret, which never left this site, so a forged
	 * link carrying somebody else's code cannot produce a credential.
	 *
	 * On admin_init and not in render_connection(), because every path out of
	 * here ends in a redirect and wp-admin prints admin-header.php before it
	 * calls a page's render callback. Redirecting from the renderer means
	 * wp_redirect() cannot send Location — "headers already sent" — and the
	 * exit that follows truncates the page, so the admin gets a blank screen
	 * whether the connection succeeded or failed. admin_init runs before a
	 * byte of output.
	 *
	 * @return void
	 */
	public function maybe_complete_connect() {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'admin.php' !== $pagenow || ! isset( $_GET['page'] ) || self::CONNECTION_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( empty( $_GET['send4wc_code'] ) || empty( $_GET['send4wc_connect_id'] ) ) {
			return;
		}

		// Not wp_die(): someone without the capability cannot reach the screen
		// anyway, and WordPress says so in its own words a moment later.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->complete_connect(
			sanitize_text_field( wp_unslash( $_GET['send4wc_code'] ) ),
			sanitize_text_field( wp_unslash( $_GET['send4wc_connect_id'] ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'send4-for-woocommerce' ) . '</a>';

		return $links;
	}

	/**
	 * Stylesheet for the Send4 screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! $this->is_send4wc_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style( 'send4wc-admin', Send4WC_Assets::url( 'admin/css/admin.css' ), array(), SEND4WC_VERSION );
	}

	/**
	 * Mark our own screens so the stylesheet can restyle the content area
	 * without reaching into any other plugin's page.
	 *
	 * @param string $classes Space separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = get_current_screen();

		if ( null !== $screen && $this->is_send4wc_screen( $screen->id ) ) {
			$classes .= ' send4wc-screen';
		}

		return $classes;
	}

	/**
	 * Is this hook suffix / screen id one of ours?
	 *
	 * @param string $hook_suffix Hook suffix or screen id.
	 * @return bool
	 */
	private function is_send4wc_screen( $hook_suffix ) {
		return '' !== (string) $hook_suffix && in_array( (string) $hook_suffix, $this->hooks, true );
	}

	/**
	 * URL of one of our screens.
	 *
	 * @param array  $args Extra query args.
	 * @param string $slug Page slug, defaulting to the dashboard.
	 * @return string
	 */
	public function page_url( array $args = array(), $slug = self::PAGE_SLUG ) {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The one screen: connection, what Send4 wants from the store, the queue.
	 *
	 * Opening it refreshes the topic list when the cached handshake has gone
	 * stale, so a flow switched on in Send4 starts receiving orders as soon as
	 * somebody looks, rather than at the next hourly sync.
	 *
	 * @return void
	 */
	public function render_page() {
		$this->guard();

		$connection = Send4WC_Settings::connection();
		$handshake  = Send4WC_Settings::has_credentials() ? $this->handshake( false ) : null;
		$data       = Send4WC_Admin_Dashboard::build( $handshake );
		$admin      = $this;

		require SEND4WC_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Every screen starts by proving the caller may be here.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to configure Send4.', 'send4-for-woocommerce' ) );
		}
	}

	/**
	 * Handshake result, cached briefly so opening a screen is cheap.
	 *
	 * Failures are cached too, for less time. Without that, every screen asked
	 * again during an outage and waited out the timeout before it could draw —
	 * and a page drawn from the cache is only fast if the cache ever holds
	 * something.
	 *
	 * An unforced call is a screen being drawn, so it waits less than an
	 * explicit Test connection does.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error
	 */
	public function handshake( $force = false ) {
		if ( ! $force ) {
			$cached = self::cached_handshake();

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$client = new Send4WC_Api_Client( $force ? Send4WC_Api_Client::TIMEOUT_INTERACTIVE : Send4WC_Api_Client::TIMEOUT_SCREEN );
		$result = $client->handshake();

		self::remember_handshake( $result );

		return $result;
	}

	/**
	 * The last handshake result, if one is still fresh, without asking again.
	 *
	 * @return array|WP_Error|null Null when nothing is cached.
	 */
	public static function cached_handshake() {
		$cached = get_transient( self::HANDSHAKE_CACHE );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		if ( ! isset( $cached[ self::HANDSHAKE_FAILURE_KEY ] ) ) {
			return $cached;
		}

		$failure = $cached[ self::HANDSHAKE_FAILURE_KEY ];

		if ( ! is_array( $failure ) || empty( $failure['code'] ) ) {
			return null;
		}

		return new WP_Error(
			(string) $failure['code'],
			isset( $failure['message'] ) ? (string) $failure['message'] : '',
			isset( $failure['data'] ) ? $failure['data'] : ''
		);
	}

	/**
	 * Cache a handshake result for the screens.
	 *
	 * One key for both outcomes, so a failed Test connection replaces a cached
	 * success rather than leaving the screens contradicting the notice.
	 *
	 * @param array|WP_Error $result Handshake result.
	 * @return void
	 */
	private static function remember_handshake( $result ) {
		if ( ! is_wp_error( $result ) ) {
			set_transient( self::HANDSHAKE_CACHE, $result, self::HANDSHAKE_CACHE_S );

			// Every successful handshake is the freshest word on which order
			// statuses Send4 wants, so the order hook reads it from here.
			if ( isset( $result['store'] ) && is_array( $result['store'] ) ) {
				Send4WC_Settings::update_store_topics( $result['store'] );
			}

			return;
		}

		// Stored as plain values rather than the object, which a transient
		// would have to unserialize back into a class.
		set_transient(
			self::HANDSHAKE_CACHE,
			array(
				self::HANDSHAKE_FAILURE_KEY => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				),
			),
			self::HANDSHAKE_FAILURE_S
		);
	}

	/**
	 * Which of the connection states a screen should describe.
	 *
	 * - disconnected: no credentials; nothing is sent.
	 * - unreachable:  credentials, but Send4 did not answer (or answered with
	 *                 a server error); order events are queued and retried.
	 * - rejected:     Send4 answered and refused the credentials; order events
	 *                 are refused too, not retried, so the copy has to differ.
	 * - unchecked:    credentials, and no handshake result to hand.
	 * - connected:    the handshake answered.
	 *
	 * "Rejected" mirrors what Send4WC_Api_Client treats as a permanent failure
	 * for an order event, so this screen and the delivery path agree about
	 * whether an event is waiting or lost.
	 *
	 * @param array|WP_Error|null $handshake Handshake result.
	 * @return string
	 */
	public static function connection_state( $handshake ) {
		if ( ! Send4WC_Settings::has_credentials() ) {
			return 'disconnected';
		}

		if ( is_array( $handshake ) ) {
			return 'connected';
		}

		if ( ! is_wp_error( $handshake ) ) {
			return 'unchecked';
		}

		$code = $handshake->get_error_code();
		$data = $handshake->get_error_data();

		if ( in_array( $code, array( 'send4wc_missing_ability', 'send4wc_bad_destination', 'send4wc_not_connected' ), true ) ) {
			return 'rejected';
		}

		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 'send4wc_token_rejected' === $code && in_array( $status, array( 400, 401 ), true ) ) {
			return 'rejected';
		}

		return 'unreachable';
	}

	/**
	 * What a failing connection means for the store's order events, in words.
	 *
	 * @param string $state A connection_state() value.
	 * @return string Empty for states that are not failures.
	 */
	public static function outage_message( $state ) {
		if ( 'unreachable' === $state ) {
			return __( 'Send4 could not be reached just now. Order events are being queued and will be sent automatically once it answers again.', 'send4-for-woocommerce' );
		}

		if ( 'rejected' === $state ) {
			return __( 'Send4 refused this store\'s connection. Order events are not being delivered until it is fixed: test the connection again, or reconnect.', 'send4-for-woocommerce' );
		}

		return '';
	}

	/**
	 * Drop the cached handshake, whatever it says.
	 *
	 * @return void
	 */
	public static function forget_handshake() {
		delete_transient( self::HANDSHAKE_CACHE );
	}

	/**
	 * Prove a credential works, and only then make it this site's.
	 *
	 * The one routine behind both ways in — coming back from the Connect
	 * button, and `wp send4wc connect` — because "connected" has to
	 * mean the same thing each way: the handshake answered, so the credential
	 * is real AND carries the ability.
	 *
	 * The candidate is tried in memory first. Saving it before the handshake,
	 * as this used to, meant a mistyped secret replaced a working one, and
	 * every order event after that was refused and dead-lettered until somebody
	 * noticed. On failure nothing is written at all.
	 *
	 * That includes where the API is. `wp send4wc connect --url-base` used to
	 * save the new destination before trying the pair, so a refused pair left
	 * the site's working credentials pointed at the wrong environment. The
	 * destination is now tried and saved with the pair it belongs to.
	 *
	 * @param string $client_id     Candidate client ID.
	 * @param string $client_secret Candidate client secret.
	 * @param string $base_url      Candidate API root, '' to keep the configured one.
	 *                              The caller has checked it is an allowed address
	 *                              and that no constant pins the destination.
	 * @return array|WP_Error The handshake on success.
	 */
	public static function establish_connection( $client_id, $client_secret, $base_url = '' ) {
		$base_url = (string) $base_url;
		$result   = Send4WC_Api_Client::with_credentials( $client_id, $client_secret, Send4WC_Api_Client::TIMEOUT_INTERACTIVE, $base_url )->handshake();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( '' !== $base_url ) {
			update_option( Send4WC_Settings::BASE_URL_OPTION, untrailingslashit( $base_url ), false );
		}

		Send4WC_Settings::update_connection(
			array(
				'client_id'     => (string) $client_id,
				'client_secret' => (string) $client_secret,
				'client_name'   => isset( $result['client']['name'] ) ? (string) $result['client']['name'] : '',
				'connected_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		// The cached token belongs to whatever credential was stored before;
		// sending it on behalf of this one would be wrong either way.
		Send4WC_Api_Client::forget_token();
		self::remember_handshake( $result );

		// Tell the workspace what this store has — its order statuses and
		// product categories — so its flow builder can offer them straight
		// away. A failure here does not undo the connection: the hourly sync
		// retries.
		Send4WC_Store_Sync::push_inventory();

		return $result;
	}

	/**
	 * Connect with a candidate credential and say how it went.
	 *
	 * Always redirects, back to the Connection screen the admin acted on.
	 *
	 * @param string $client_id     Candidate client ID.
	 * @param string $client_secret Candidate client secret.
	 * @return void
	 */
	private function confirm_connection( $client_id, $client_secret ) {
		$had_credentials = Send4WC_Settings::has_credentials();
		$result          = self::establish_connection( $client_id, $client_secret );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice(
				'error',
				$had_credentials
					/* translators: %s: why Send4 refused, e.g. "Send4 rejected these credentials." */
					? sprintf( __( '%s The new credentials were not saved; this site is still using the ones it had.', 'send4-for-woocommerce' ), $result->get_error_message() )
					/* translators: %s: why Send4 refused, e.g. "Send4 rejected these credentials." */
					: sprintf( __( '%s The credentials were not saved.', 'send4-for-woocommerce' ), $result->get_error_message() ),
				array(),
				self::CONNECTION_SLUG
			);
		}

		$this->redirect_with_notice(
			'success',
			/* translators: %s: Send4 account name. */
			sprintf( __( 'Connected to %s.', 'send4-for-woocommerce' ), isset( $result['client']['name'] ) ? (string) $result['client']['name'] : '' ),
			array(),
			self::CONNECTION_SLUG
		);
	}

	/**
	 * Start a connection: introduce this site to Send4 and send the admin there
	 * to approve it.
	 *
	 * @return void
	 */
	public function handle_start_connect() {
		$this->require_capability();
		check_admin_referer( 'send4wc_start_connect' );

		$client       = new Send4WC_Api_Client();
		$registration = $client->register_site();

		if ( is_wp_error( $registration ) ) {
			$this->redirect_with_notice( 'error', $registration->get_error_message(), array(), self::CONNECTION_SLUG );
		}

		Send4WC_Settings::update_connection(
			array(
				'connect_id'     => $registration['connect_id'],
				'connect_secret' => $registration['connect_secret'],
			)
		);

		// Off to Send4 to approve. wp_redirect rather than wp_safe_redirect:
		// the whole point is to leave this site. The destination is not taken
		// on faith, though — register_site() has already refused anything that
		// is not a Send4 or development address, because the API is trusted
		// with the store's orders, not with where the administrator's browser
		// ends up.
		wp_redirect( $registration['authorize_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Finish a connection that Send4 just approved.
	 *
	 * Always redirects. See maybe_complete_connect(), which is the only caller
	 * and where the capability check and the reasoning about nonces live.
	 *
	 * @param string $code       The one-time code from the redirect.
	 * @param string $connect_id The connection the code belongs to.
	 * @return void
	 */
	private function complete_connect( $code, $connect_id ) {
		$connection = Send4WC_Settings::connection();

		if ( '' === $connection['connect_id'] || $connection['connect_id'] !== $connect_id ) {
			$this->redirect_with_notice(
				'error',
				__( 'That connection did not start here. Try connecting again.', 'send4-for-woocommerce' ),
				array(),
				self::CONNECTION_SLUG
			);
		}

		$client      = new Send4WC_Api_Client();
		$credentials = $client->exchange_code( $connection['connect_id'], $connection['connect_secret'], $code );

		if ( is_wp_error( $credentials ) ) {
			$this->redirect_with_notice( 'error', $credentials->get_error_message(), array(), self::CONNECTION_SLUG );
		}

		Send4WC_Settings::update_connection(
			array(
				// One-shot: keeping them would only ever be a replay target.
				'connect_id'     => '',
				'connect_secret' => '',
			)
		);

		$this->confirm_connection( $credentials['client_id'], $credentials['client_secret'] );
	}

	/**
	 * Re-run the handshake.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->require_capability();
		check_admin_referer( 'send4wc_test' );

		$result = $this->handshake( true );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message(), array(), self::CONNECTION_SLUG );
		}

		$statuses = isset( $result['store']['start_topics'] ) ? count( $result['store']['start_topics'] ) : 0;

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: 1: Send4 account name, 2: number of order statuses. */
				_n(
					'Connected to %1$s. A flow starts on %2$d order status.',
					'Connected to %1$s. Flows start on %2$d order statuses.',
					$statuses,
					'send4-for-woocommerce'
				),
				isset( $result['client']['name'] ) ? $result['client']['name'] : '',
				$statuses
			),
			array(),
			self::CONNECTION_SLUG
		);
	}

	/**
	 * Forget the credentials.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->require_capability();
		check_admin_referer( 'send4wc_disconnect' );

		Send4WC_Settings::disconnect();
		self::forget_handshake();

		$this->redirect_with_notice( 'success', __( 'Disconnected from Send4. No order is sent until this store connects again.', 'send4-for-woocommerce' ), array(), self::CONNECTION_SLUG );
	}

	/**
	 * Ask Send4 again which order statuses it wants, and send it the store's
	 * statuses and categories, now rather than at the next hourly sync.
	 *
	 * @return void
	 */
	public function handle_sync_store() {
		$this->require_capability();
		check_admin_referer( 'send4wc_sync_store' );

		if ( ! Send4WC_Settings::has_credentials() ) {
			$this->redirect_with_notice( 'error', __( 'Connect this store to Send4 before syncing it.', 'send4-for-woocommerce' ) );
		}

		$result = Send4WC_Store_Sync::sync();

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		self::forget_handshake();

		$this->redirect_with_notice(
			'success',
			__( 'Synced. Send4 has this store\'s order statuses and categories, and the store knows which statuses to send.', 'send4-for-woocommerce' )
		);
	}

	/**
	 * Drain the queue now.
	 *
	 * @return void
	 */
	public function handle_run_queue() {
		$this->require_capability();
		check_admin_referer( 'send4wc_run_queue' );

		$summary = Send4WC_Delivery::process_queue();

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: 1: attempted count, 2: delivered count. */
				_n(
					'Sent %1$s queued order event; %2$s delivered.',
					'Sent %1$s queued order events; %2$s delivered.',
					(int) $summary['attempted'],
					'send4-for-woocommerce'
				),
				number_format_i18n( (int) $summary['attempted'] ),
				number_format_i18n( (int) $summary['delivered'] )
			)
		);
	}

	/**
	 * Empty the activity log.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		$this->require_capability();
		check_admin_referer( 'send4wc_clear_log' );

		Send4WC_Logger::clear();

		$this->redirect_with_notice( 'success', __( 'Activity log cleared.', 'send4-for-woocommerce' ) );
	}

	/**
	 * Put dead-lettered order events back on the queue.
	 *
	 * For after the cause has been fixed — a key replaced, a reconnect, the
	 * destination corrected — which the plugin has no way to notice.
	 *
	 * @return void
	 */
	public function handle_requeue() {
		$this->require_capability();
		check_admin_referer( 'send4wc_requeue' );

		if ( ! Send4WC_Settings::has_credentials() ) {
			$this->redirect_with_notice( 'error', __( 'Connect this store to Send4 before resending failed order events.', 'send4-for-woocommerce' ) );
		}

		$moved = Send4WC_Delivery::requeue();

		if ( 0 === $moved ) {
			$this->redirect_with_notice( 'success', __( 'There were no failed order events for this Send4 account to resend.', 'send4-for-woocommerce' ) );
		}

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: %d: number of order events. */
				_n( '%d failed order event will be resent within a minute.', '%d failed order events will be resent within a minute.', $moved, 'send4-for-woocommerce' ),
				$moved
			)
		);
	}

	/**
	 * The capability check every admin-post handler makes first.
	 *
	 * Each handler then calls check_admin_referer() itself, on the next line.
	 * That check used to live in here too, and the nonce was always verified,
	 * but Plugin Check only sees a nonce check written in the same function
	 * that reads $_POST, so a wrapper reads to it, and to a reviewer, as none.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to configure Send4.', 'send4-for-woocommerce' ) );
		}
	}

	/**
	 * Redirect back to one of our screens carrying a notice.
	 *
	 * The text travels in a short-lived transient keyed to the current user —
	 * the arrangement core uses for settings_errors — and the URL carries only
	 * a marker. Carrying the text in the URL, as this used to, meant anybody
	 * could link an administrator to a Send4 screen showing a notice of their
	 * choosing, in the plugin's own voice; escaping stops markup, not lies.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @param array  $args    Extra query args.
	 * @param string $slug    Page slug to land on.
	 * @return void
	 */
	private function redirect_with_notice( $type, $message, array $args = array(), $slug = self::PAGE_SLUG ) {
		$type = 'success' === $type ? 'success' : 'error';

		set_transient(
			self::notice_key(),
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			self::NOTICE_CACHE_S
		);

		$url = $this->page_url( array_merge( $args, array( 'send4wc_notice' => $type ) ), $slug );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The notice carried on the current request, if any.
	 *
	 * One-shot: reading it removes it, so a reload does not repeat an outcome
	 * that is no longer news.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public function current_notice() {
		// The marker only says a notice may be waiting; nothing in the URL is
		// rendered. A marker with nothing stored behind it — a forged link, or
		// one older than the transient — shows nothing at all.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['send4wc_notice'] ) ) {
			return null;
		}

		$notice = get_transient( self::notice_key() );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) || ! is_string( $notice['message'] ) ) {
			return null;
		}

		delete_transient( self::notice_key() );

		return array(
			'type'    => isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error',
			'message' => $notice['message'],
		);
	}

	/**
	 * Where the current user's pending notice is kept.
	 *
	 * Per user, so two administrators cannot read each other's outcomes; per
	 * site by virtue of being a transient.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return self::NOTICE_PREFIX . get_current_user_id();
	}

	/**
	 * Human readable label for a stored delivery status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public function status_label( $status ) {
		switch ( $status ) {
			case 'delivered':
				return __( 'Delivered', 'send4-for-woocommerce' );
			case 'duplicate':
				return __( 'Already received', 'send4-for-woocommerce' );
			case 'unrouted':
				return __( 'Delivered, no flow started', 'send4-for-woocommerce' );
			case 'queued':
				return __( 'Queued for retry', 'send4-for-woocommerce' );
			case 'failed':
				return __( 'Failed', 'send4-for-woocommerce' );
			default:
				return __( 'No order events yet', 'send4-for-woocommerce' );
		}
	}

	/**
	 * Which badge tone a stored delivery status deserves.
	 *
	 * @param string $status Stored status.
	 * @return string One of ok|info|warn|bad|neutral.
	 */
	public function status_tone( $status ) {
		switch ( $status ) {
			case 'delivered':
				return 'ok';
			case 'duplicate':
				return 'info';
			case 'unrouted':
				return 'info';
			case 'queued':
				return 'warn';
			case 'failed':
				return 'bad';
			default:
				return 'neutral';
		}
	}

	/**
	 * A stored UTC timestamp in the site's own date and time format and zone.
	 *
	 * @param string $utc Timestamp as stored, Y-m-d H:i:s in UTC.
	 * @return string Empty when there is nothing readable to show.
	 */
	public function format_utc( $utc ) {
		$stamp = '' === (string) $utc ? false : strtotime( $utc . ' UTC' );

		if ( false === $stamp ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp );
	}

	/**
	 * Print a nonce for one of the several small forms on a screen.
	 *
	 * Core's wp_nonce_field() prints id="_wpnonce" — and a referer field with an id
	 * of its own — every time it is called, so a screen with more than one
	 * form ends up with duplicate ids. check_admin_referer() needs only the
	 * field's name, and the handlers redirect to a fixed screen rather than to
	 * the referer.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	public function nonce_field( $action ) {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	}
}
