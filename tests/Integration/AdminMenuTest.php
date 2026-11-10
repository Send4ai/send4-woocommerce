<?php
/**
 * The Send4 menu entry.
 *
 * @package Send4WC
 */

/**
 * One screen, WooCommerce → Send4, behind manage_woocommerce, so the shop
 * manager who looks after the orders can connect the store and see what was
 * sent. The connect return leg lands on that same screen and is redeemed
 * before any output.
 */
class Send4WC_Admin_Menu_Test extends Send4WC_Test_Case {

	/**
	 * Build the menu the way wp-admin does, as the given role.
	 *
	 * WooCommerce registers its own menu on admin_menu before ours, so the
	 * parent is put in place first, under the title given.
	 *
	 * @param string $role         Role to run as.
	 * @param string $parent_title Title of the WooCommerce menu.
	 * @return Send4WC_Admin
	 */
	private function buildMenuAs( $role, $parent_title = 'WooCommerce' ) {
		global $menu, $submenu, $_registered_pages, $_parent_pages, $admin_page_hooks;

		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		$_parent_pages     = array();
		$admin_page_hooks  = array();

		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		add_menu_page( $parent_title, $parent_title, 'edit_others_shop_orders', 'woocommerce', null, '', '55.5' );

		$admin = new Send4WC_Admin();
		$admin->register_page();

		return $admin;
	}

	/**
	 * The slugs registered under a parent, in order.
	 *
	 * @param string $parent_slug Parent slug.
	 * @return array<int,string>
	 */
	private function submenuSlugs( $parent_slug ) {
		global $submenu;

		if ( ! isset( $submenu[ $parent_slug ] ) ) {
			return array();
		}

		return wp_list_pluck( $submenu[ $parent_slug ], 2 );
	}

	/**
	 * The forms plugin `send4` already owns a top-level "Send4" menu, and a
	 * second one on the same site would be one too many. So the store's screen
	 * is one entry under WooCommerce, with no screens of its own below it.
	 *
	 * @return void
	 */
	public function test_woocommerce_send4_is_the_only_menu_entry() {
		$this->buildMenuAs( 'administrator' );

		global $menu, $_registered_pages;

		$this->assertNotContains(
			Send4WC_Admin::PAGE_SLUG,
			wp_list_pluck( $menu, 2 ),
			'Send4 for WooCommerce must not add a top-level menu beside the forms plugin\'s.'
		);

		$this->assertCount(
			1,
			array_keys( $this->submenuSlugs( 'woocommerce' ), Send4WC_Admin::PAGE_SLUG, true ),
			'The screen is registered under WooCommerce, once.'
		);
		$this->assertSame( array(), $this->submenuSlugs( Send4WC_Admin::PAGE_SLUG ), 'The screen has no sub-screens.' );

		$ours = array_filter(
			array_keys( $_registered_pages ),
			static function ( $hook ) {
				return false !== strpos( (string) $hook, 'send4' );
			}
		);

		$this->assertSame(
			array( 'woocommerce_page_' . Send4WC_Admin::PAGE_SLUG ),
			array_values( $ours ),
			'Only the one screen the plugin serves is registered: no forms, mapping or connection screens.'
		);
	}

	/**
	 * The return leg of the connect handshake lands on CONNECTION_SLUG, so it
	 * has to be the slug of the screen that exists.
	 *
	 * @return void
	 */
	public function test_the_connection_is_made_on_the_same_screen() {
		$this->assertSame( Send4WC_Admin::PAGE_SLUG, Send4WC_Admin::CONNECTION_SLUG );
	}

	/**
	 * The store's orders are a shop manager's business, so the screen is
	 * behind WooCommerce's own capability rather than manage_options.
	 *
	 * @return void
	 */
	public function test_the_screen_requires_manage_woocommerce() {
		$this->buildMenuAs( 'administrator' );

		global $submenu;

		$this->assertSame( 'manage_woocommerce', Send4WC_Admin::CAPABILITY );

		$entries = array_values(
			array_filter(
				$submenu['woocommerce'],
				static function ( $item ) {
					return Send4WC_Admin::PAGE_SLUG === $item[2];
				}
			)
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( Send4WC_Admin::CAPABILITY, $entries[0][1], 'The screen is not behind manage_woocommerce.' );
	}

	/**
	 * A shop manager has manage_woocommerce but not manage_options, and is the
	 * person who looks after the orders, so the entry is theirs and the screen
	 * opens for them.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_can_open_it() {
		$admin = $this->buildMenuAs( 'shop_manager' );

		$this->assertFalse( current_user_can( 'manage_options' ), 'The point of the test is a user without manage_options.' );
		$this->assertTrue( current_user_can( Send4WC_Admin::CAPABILITY ) );
		$this->assertContains( Send4WC_Admin::PAGE_SLUG, $this->submenuSlugs( 'woocommerce' ) );

		$this->http->queueToken();
		$this->http->queueHandshake();

		ob_start();

		try {
			$admin->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'Send4 for WooCommerce', $html );
		$this->assertStringContainsString( 'Connected · Baldai UAB', $html );
		$this->assertStringContainsString( 'Test connection', $html );
	}

	/**
	 * add_submenu_page() refuses to register for a user without the
	 * capability, and the screen turns away anybody who types its URL anyway.
	 *
	 * @return void
	 */
	public function test_an_editor_cannot_reach_the_send4_screen() {
		$admin = $this->buildMenuAs( 'editor' );

		$this->assertFalse( current_user_can( Send4WC_Admin::CAPABILITY ) );
		$this->assertNotContains(
			Send4WC_Admin::PAGE_SLUG,
			$this->submenuSlugs( 'woocommerce' ),
			'add_submenu_page() refuses to register for a user without the capability.'
		);

		ob_start();

		try {
			$admin->render_page();
			$this->fail( 'An editor was shown the Send4 screen.' );
		} catch ( WPDieException $died ) {
			$this->assertSame( 'You do not have permission to configure Send4.', $died->getMessage() );
		} finally {
			ob_end_clean();
		}

		$this->assertSame( 0, $this->http->requestCount(), 'Nothing is asked of Send4 on an editor\'s behalf.' );
	}

	/**
	 * Run something and report where it tried to redirect to, if anywhere.
	 *
	 * The handlers end in exit, as every redirect in wp-admin does, so the
	 * filter throws to unwind out of them before that line is reached.
	 *
	 * @param callable $run What to run.
	 * @return string|null
	 */
	private function captureRedirect( callable $run ) {
		$spy = static function ( $location ) {
			throw new Send4WC_Redirected( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			$run();

			return null;
		} catch ( Send4WC_Redirected $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}
	}

	/**
	 * Send4 sends the browser back to the admin_url the store registered, so
	 * that has to be the screen that redeems the code. There is no older
	 * Settings URL to forward from.
	 *
	 * @return void
	 */
	public function test_send4_is_told_to_return_to_the_screen_that_redeems_the_code() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4wc_start_connect' );

		$this->http->queue(
			Send4WC_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://app.send4.ai/connect/wordpress/wpconnect_abc123',
				)
			)
		);

		$this->captureRedirect(
			static function () {
				( new Send4WC_Admin() )->handle_start_connect();
			}
		);

		$registration = $this->http->requestsTo( '/api/connect/register' );

		$this->assertCount( 1, $registration );
		$this->assertSame( admin_url( 'admin.php?page=' . Send4WC_Admin::CONNECTION_SLUG ), $registration[0]['body']['admin_url'] );
		$this->assertSame( 'send4-for-woocommerce', $registration[0]['body']['plugin'], 'Send4 keeps the forms plugin\'s connection apart from this one.' );
	}

	/**
	 * The forms plugin carries its own code back to its own screen. A code on
	 * any screen but ours is left alone, and nothing is sent to Send4.
	 *
	 * @return void
	 */
	public function test_a_code_on_another_screen_is_left_alone() {
		global $pagenow;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Send4WC_Settings::update_connection(
			array(
				'connect_id'     => 'wpconnect_abc123',
				'connect_secret' => 'shhh',
			)
		);

		$pagenow                    = 'admin.php';
		$_GET['page']               = 'send4-connection';
		$_GET['send4wc_code']       = 'code-abc';
		$_GET['send4wc_connect_id'] = 'wpconnect_abc123';

		$destination = $this->captureRedirect(
			static function () {
				( new Send4WC_Admin() )->maybe_complete_connect();
			}
		);

		$this->assertNull( $destination );
		$this->assertSame( 0, $this->http->requestCount() );
		$this->assertSame( 'wpconnect_abc123', Send4WC_Settings::connection()['connect_id'], 'The pending connection is still there to redeem.' );
	}

	/**
	 * wp-admin prints admin-header.php before it calls a page's render
	 * callback, so a redirect from inside the renderer cannot send Location and
	 * the exit that follows leaves the shop manager looking at a blank page.
	 * The connect return leg therefore has to be redeemed on admin_init.
	 *
	 * @return void
	 */
	public function test_the_connect_return_leg_is_redeemed_before_any_output() {
		global $pagenow;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Send4WC_Settings::update_connection(
			array(
				'connect_id'     => 'wpconnect_abc123',
				'connect_secret' => 'shhh',
			)
		);

		$pagenow                    = 'admin.php';
		$_GET['page']               = Send4WC_Admin::CONNECTION_SLUG;
		$_GET['send4wc_code']       = 'code-abc';
		$_GET['send4wc_connect_id'] = 'wpconnect_abc123';

		$this->http->queue(
			Send4WC_Http_Recorder::response(
				200,
				array(
					'client_id'     => 'minted-id',
					'client_secret' => 'minted-secret',
				)
			)
		);
		$this->http->queueToken();
		$this->http->queueHandshake();
		// The store inventory push that follows a connect.
		$this->http->queueToken();
		$this->http->queueStoreSaved();

		$destination = $this->captureRedirect(
			static function () {
				( new Send4WC_Admin() )->maybe_complete_connect();
			}
		);

		$this->assertNotNull( $destination, 'admin_init must redeem the code, not the renderer.' );
		$this->assertStringContainsString( 'send4wc_notice=success', $destination );

		$connection = Send4WC_Settings::connection();
		$this->assertSame( 'minted-id', $connection['client_id'] );
		$this->assertSame( '', $connection['connect_id'], 'The one-shot pair must not survive redemption.' );
	}

	/**
	 * And the renderer must stay inert for it, or the blank screen comes back.
	 *
	 * @return void
	 */
	public function test_rendering_the_screen_never_redirects() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Send4WC_Settings::update_connection(
			array(
				'connect_id'     => 'wpconnect_abc123',
				'connect_secret' => 'shhh',
			)
		);

		$_GET['page']               = Send4WC_Admin::CONNECTION_SLUG;
		$_GET['send4wc_code']       = 'code-abc';
		$_GET['send4wc_connect_id'] = 'wpconnect_abc123';

		$this->http->queueToken();
		$this->http->queueHandshake();

		$admin = new Send4WC_Admin();

		$destination = $this->captureRedirect(
			static function () use ( $admin ) {
				ob_start();

				try {
					$admin->render_page();
				} finally {
					ob_end_clean();
				}
			}
		);

		$this->assertNull(
			$destination,
			'Redirecting from a render callback cannot send Location, and the exit after it blanks the screen.'
		);
		$this->assertSame( array(), $this->http->requestsTo( '/api/connect/exchange' ), 'The renderer must not redeem the code.' );
	}

	/**
	 * WordPress names a submenu's screen after the parent's *translated* menu
	 * title, so under a translated WooCommerce menu the screen's hook is not
	 * woocommerce_page_send4wc. Screen detection is built from the hook
	 * add_submenu_page() answered with, never from the slugs, so the
	 * stylesheet and the body class still find the screen.
	 *
	 * @return void
	 */
	public function test_the_screen_is_recognised_when_the_woocommerce_menu_title_is_translated() {
		$admin = $this->buildMenuAs( 'administrator', 'Parduotuvė' );

		$hook = get_plugin_page_hookname( Send4WC_Admin::PAGE_SLUG, 'woocommerce' );

		$this->assertNotSame( 'woocommerce_page_' . Send4WC_Admin::PAGE_SLUG, $hook, 'The translation should have changed the hook name.' );

		wp_dequeue_style( 'send4wc-admin' );

		$admin->enqueue( 'woocommerce_page_' . Send4WC_Admin::PAGE_SLUG );
		$this->assertFalse( wp_style_is( 'send4wc-admin', 'enqueued' ), 'A screen id that is not ours gets no stylesheet.' );

		$admin->enqueue( $hook );
		$this->assertTrue( wp_style_is( 'send4wc-admin', 'enqueued' ), 'The screen lost its stylesheet.' );

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		set_current_screen( $hook );
		$this->assertStringContainsString( 'send4wc-screen', $admin->body_class( '' ) );

		set_current_screen( 'dashboard' );
		$this->assertStringNotContainsString( 'send4wc-screen', $admin->body_class( '' ) );
	}

	/**
	 * page_url() is the one place admin URLs are built, so the connect flow,
	 * the plugins screen's Settings link and the views cannot drift apart.
	 *
	 * @return void
	 */
	public function test_page_url_points_at_the_one_screen() {
		$admin = new Send4WC_Admin();

		$this->assertStringContainsString( 'admin.php?page=' . Send4WC_Admin::PAGE_SLUG, $admin->page_url() );
		$this->assertStringContainsString( 'send4wc_notice=success', $admin->page_url( array( 'send4wc_notice' => 'success' ) ) );

		$links = $admin->action_links( array() );

		$this->assertCount( 1, $links );
		$this->assertStringContainsString( esc_url( $admin->page_url() ), $links[0] );
		$this->assertStringContainsString( '>Settings<', $links[0] );
	}

	/**
	 * A notice is something the plugin says, not something a URL can make it
	 * say. With the text in the query string anybody could link a shop
	 * manager to the Send4 screen carrying an error, or a success, of their
	 * own choosing, in the plugin's voice.
	 *
	 * @return void
	 */
	public function test_a_notice_cannot_be_planted_through_the_url() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['send4wc_notice']  = 'error';
		$_GET['send4wc_message'] = 'Your licence has expired. Re-enter your card at evil.example to keep sending.';

		$this->assertNull( ( new Send4WC_Admin() )->current_notice() );
	}

	/**
	 * Nor can a crafted query string take the screen down: rawurldecode() on
	 * an array is a TypeError on PHP 8, and older code called it on whatever
	 * arrived under the message parameter.
	 *
	 * @return void
	 */
	public function test_an_array_in_the_query_string_is_not_a_fatal_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['send4wc_notice']  = array( 'success' );
		$_GET['send4wc_message'] = array( 'x' );

		$this->assertNull( ( new Send4WC_Admin() )->current_notice() );
	}

	/**
	 * The real thing: a handler's outcome reaches the screen it redirects to,
	 * once, and only for the user whose action it was.
	 *
	 * @return void
	 */
	public function test_a_handler_outcome_is_shown_once_to_the_user_who_caused_it() {
		$actor     = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$bystander = self::factory()->user->create( array( 'role' => 'shop_manager' ) );

		wp_set_current_user( $actor );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4wc_clear_log' );

		$destination = $this->captureRedirect(
			static function () {
				( new Send4WC_Admin() )->handle_clear_log();
			}
		);

		$this->assertNotNull( $destination );
		$this->assertStringContainsString( 'send4wc_notice=success', $destination );
		$this->assertStringNotContainsString( 'send4wc_message', $destination, 'The text must not travel in the URL.' );
		$this->assertStringNotContainsString( 'cleared', $destination );

		$_GET['send4wc_notice'] = 'success';

		// Another shop manager following the same link sees nothing.
		wp_set_current_user( $bystander );
		$this->assertNull( ( new Send4WC_Admin() )->current_notice() );

		wp_set_current_user( $actor );
		$notice = ( new Send4WC_Admin() )->current_notice();

		$this->assertSame( 'success', $notice['type'] );
		$this->assertSame( 'Activity log cleared.', $notice['message'] );

		$this->assertNull( ( new Send4WC_Admin() )->current_notice(), 'A notice is shown once; a reload does not repeat it.' );
	}

	/**
	 * The Connect button follows the approval link the API hands back, so the
	 * API, or anything able to answer as it, must not be able to send the
	 * shop manager anywhere but Send4.
	 *
	 * @return void
	 */
	public function test_the_connect_button_refuses_an_approval_link_that_is_not_send4() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4wc_start_connect' );

		$this->http->queue(
			Send4WC_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://evil.example/approve',
				)
			)
		);

		$destination = $this->captureRedirect(
			static function () {
				( new Send4WC_Admin() )->handle_start_connect();
			}
		);

		$this->assertNotNull( $destination );
		$this->assertStringNotContainsString( 'evil.example', $destination );
		$this->assertStringContainsString( 'send4wc_notice=error', $destination );
		$this->assertStringContainsString( 'wp-admin', $destination, 'The shop manager stays on this site.' );

		$this->assertSame( '', Send4WC_Settings::connection()['connect_id'], 'A refused registration leaves nothing to redeem.' );
	}

	/**
	 * And a genuine one is followed, so the check above is not "never leave".
	 *
	 * @return void
	 */
	public function test_the_connect_button_follows_a_send4_approval_link() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'send4wc_start_connect' );

		$this->http->queue(
			Send4WC_Http_Recorder::response(
				201,
				array(
					'connect_id'     => 'wpconnect_abc123',
					'connect_secret' => 'registration-secret',
					'authorize_url'  => 'https://app.send4.ai/connect/wordpress/wpconnect_abc123',
				)
			)
		);

		$destination = $this->captureRedirect(
			static function () {
				( new Send4WC_Admin() )->handle_start_connect();
			}
		);

		$this->assertSame( 'https://app.send4.ai/connect/wordpress/wpconnect_abc123', $destination );
		$this->assertSame( 'wpconnect_abc123', Send4WC_Settings::connection()['connect_id'] );
	}
}

/**
 * Thrown in place of the exit that follows every wp-admin redirect.
 */
class Send4WC_Redirected extends Exception {

	/**
	 * Where the redirect was headed.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Remember the target.
	 *
	 * @param string $location Redirect target.
	 */
	public function __construct( $location ) {
		parent::__construct( 'Redirected to ' . $location );

		$this->location = $location;
	}
}
