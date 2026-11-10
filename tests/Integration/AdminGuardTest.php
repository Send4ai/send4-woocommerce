<?php
/**
 * Every admin-post handler checks the capability, then the nonce, itself.
 *
 * @package Send4WC
 */

/**
 * Each handler makes both checks in its own body rather than through one
 * helper. A helper always made them too, but Plugin Check only sees a nonce
 * check written in the same function that reads $_POST, and the WordPress.org
 * sandbox reported every handler of the forms plugin as processing form data
 * without one for exactly that reason. These tests pin down that the
 * order is the capability first, then the nonce, and that a refused request
 * does nothing to the store's connection, its order statuses, its queue or
 * its log.
 */
class Send4WC_Admin_Guard_Test extends Send4WC_Test_Case {

	/**
	 * Clear the request superglobals the handlers read.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Every admin-post action the plugin registers.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function handlerProvider() {
		$actions = array(
			'send4wc_start_connect',
			'send4wc_disconnect',
			'send4wc_test',
			'send4wc_sync_store',
			'send4wc_run_queue',
			'send4wc_requeue',
			'send4wc_clear_log',
		);

		return array_combine( $actions, array_map( static fn ( $action ) => array( $action ), $actions ) );
	}

	/**
	 * The handler method an admin-post action is wired to.
	 *
	 * @param string $action Admin-post action.
	 * @return string
	 */
	private function methodFor( $action ) {
		return 'handle_' . substr( $action, strlen( 'send4wc_' ) );
	}

	/**
	 * Run one handler with this nonce and report the wp_die() message.
	 *
	 * @param string $action Admin-post action.
	 * @param string $nonce  Value of _wpnonce.
	 * @return string The message wp_die() was called with.
	 */
	private function dieMessageOf( $action, $nonce ) {
		$_POST    = array( '_wpnonce' => $nonce );
		$_REQUEST = $_POST;

		$method = $this->methodFor( $action );

		try {
			( new Send4WC_Admin() )->$method();
		} catch ( WPDieException $died ) {
			return $died->getMessage();
		}

		$this->fail( "{$action} went ahead without a valid request." );

		return '';
	}

	/**
	 * Everything a refused request must leave exactly as it was.
	 *
	 * @return array<string,mixed>
	 */
	private function storeState() {
		return array(
			'connection' => Send4WC_Settings::connection(),
			'topics'     => Send4WC_Settings::store_topics(),
			'sync'       => Send4WC_Settings::store_sync(),
			'log'        => Send4WC_Logger::entries(),
			'queue'      => $this->queueRows(),
		);
	}

	/**
	 * A store with something in every place a handler could touch: a log
	 * entry to clear and a dead-lettered order event to resend.
	 *
	 * @return void
	 */
	private function seedTheStore() {
		Send4WC_Logger::log( 'info', 'Order event delivered to Send4.' );

		$row = $this->queueEvent( $this->makeOrder() );
		Send4WC_Queue::record_permanent_failure( $row, 'Send4 refused the order event (status 400).' );
	}

	/**
	 * Each handler is wired to its admin-post hook.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_the_handler_is_registered( $action ) {
		$admin = new Send4WC_Admin();
		$admin->boot();

		$this->assertNotFalse( has_action( "admin_post_{$action}", array( $admin, $this->methodFor( $action ) ) ) );
	}

	/**
	 * The list above is every admin-post hook the plugin adds, so a handler
	 * added later cannot slip past these tests by not being in it. None of
	 * them is open to a visitor who is not logged in.
	 *
	 * @return void
	 */
	public function test_the_list_is_every_handler_the_plugin_registers() {
		global $wp_filter;

		( new Send4WC_Admin() )->boot();

		$registered = array();

		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( 1 === preg_match( '/^admin_post_(nopriv_)?send4wc_/', (string) $hook ) ) {
				$registered[] = substr( (string) $hook, strlen( 'admin_post_' ) );
			}
		}

		$expected = array_keys( $this->handlerProvider() );

		sort( $registered );
		sort( $expected );

		$this->assertSame( $expected, $registered );
	}

	/**
	 * A shop manager with a forged nonce is turned away, and nothing happens.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_a_forged_nonce_is_refused( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$this->seedTheStore();

		$before = $this->storeState();

		$message = $this->dieMessageOf( $action, 'forged' );

		$this->assertStringContainsString( 'The link you followed has expired.', $message );
		$this->assertSame( array(), $this->http->requests(), 'Nothing is sent to Send4.' );
		$this->assertEquals( $before, $this->storeState(), 'The store is left as it was.' );
	}

	/**
	 * Somebody who may not run the store is refused even with a valid nonce.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_a_user_without_the_capability_is_refused( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->seedTheStore();

		$before = $this->storeState();

		$message = $this->dieMessageOf( $action, wp_create_nonce( $action ) );

		$this->assertSame( 'You do not have permission to configure Send4.', $message );
		$this->assertSame( array(), $this->http->requests(), 'Nothing is sent to Send4.' );
		$this->assertEquals( $before, $this->storeState(), 'The store is left as it was.' );
	}

	/**
	 * The capability is checked before the nonce: someone without it sees the
	 * permission message, never the expired-link one.
	 *
	 * @dataProvider handlerProvider
	 *
	 * @param string $action Admin-post action.
	 * @return void
	 */
	public function test_the_capability_is_checked_before_the_nonce( $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 'You do not have permission to configure Send4.', $this->dieMessageOf( $action, 'forged' ) );
	}
}
