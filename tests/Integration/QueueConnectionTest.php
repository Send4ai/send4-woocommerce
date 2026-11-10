<?php
/**
 * Queue rows belong to the Send4 connection they were queued under.
 *
 * @package Send4WC
 */

/**
 * Send4 dedupes an order event per API client, so a row queued while connected
 * to workspace A and sent after reconnecting to workspace B is an order in B's
 * account. These tests pin down that it never is, and that the rows which were
 * dead-lettered can be brought back once the cause is fixed.
 */
class Send4WC_Queue_Connection_Test extends Send4WC_Test_Case {

	const STORE_EVENTS = '/api/integrations/wordpress/store-events';

	/**
	 * Log in as someone who may configure Send4, with a clean request.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clear the request superglobals the admin handlers read.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Queue an order event, the way the order hook would, and give it a status
	 * and an owner.
	 *
	 * @param string      $status    Row status.
	 * @param string|null $client_id Owning client, null for the current connection.
	 * @return object The row.
	 */
	private function seed( $status = Send4WC_Queue::STATUS_PENDING, $client_id = null ) {
		global $wpdb;

		$reference = Send4WC_Order_Payload::reference( $this->makeOrder(), 'order.status.processing', true );
		$event_id  = $reference['event']['id'];
		$owner     = null === $client_id ? Send4WC_Queue::current_client_id() : $client_id;

		$this->assertTrue( Send4WC_Delivery::queue( $reference, 'order.status.processing' ) );

		$row = Send4WC_Queue::find_by_event_id( $event_id, Send4WC_Queue::current_client_id() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Send4WC_Queue::table(),
			array(
				'status'    => $status,
				'client_id' => $owner,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->makeDue( $row );

		return Send4WC_Queue::find_by_event_id( $event_id, $owner );
	}

	/**
	 * Switch the store to a different API client, as a reconnect would.
	 *
	 * @param string $client_id New client id.
	 * @return void
	 */
	private function reconnectAs( $client_id ) {
		Send4WC_Settings::update_connection(
			array(
				'client_id'     => $client_id,
				'client_secret' => 'another-secret',
			)
		);
		Send4WC_Api_Client::forget_token();
	}

	/**
	 * Run an admin-post handler and return where it redirected.
	 *
	 * @param string $action Nonce action, also the handler's suffix.
	 * @return string
	 */
	private function post( $action ) {
		$_POST    = array( '_wpnonce' => wp_create_nonce( $action ) );
		$_REQUEST = $_POST;

		$spy = static function ( $location ) {
			throw new Send4WC_Queue_Connection_Redirect( $location );
		};

		add_filter( 'wp_redirect', $spy );

		try {
			$method = 'handle_' . substr( $action, strlen( 'send4wc_' ) );
			( new Send4WC_Admin() )->$method();
		} catch ( Send4WC_Queue_Connection_Redirect $redirect ) {
			return rawurldecode( $redirect->location );
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}

		$this->fail( 'The handler always redirects.' );

		return '';
	}

	/**
	 * Every row records the client it was queued under.
	 *
	 * @return void
	 */
	public function test_a_row_records_the_client_it_was_queued_under() {
		$this->assertSame( 'test-client-id', $this->seed()->client_id );
	}

	/**
	 * The failure this whole column exists for.
	 *
	 * @return void
	 */
	public function test_a_row_queued_under_another_client_is_never_sent() {
		$this->seed();
		$this->reconnectAs( 'workspace-b' );

		$this->http->fallback( Send4WC_Http_Recorder::response( 202, array( 'status' => 'accepted', 'routed' => true ) ) );

		$summary = Send4WC_Delivery::process_queue();

		$this->assertSame( 0, $summary['attempted'] );
		$this->assertCount( 0, $this->http->requestsTo( self::STORE_EVENTS ) );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $row->status, 'Quarantined as a dead letter, so retention removes it.' );
		$this->assertStringContainsString( 'different Send4 account', $row->last_error );
		$this->assertSame( 'test-client-id', $row->client_id, 'It still says whose it was.' );
	}

	/**
	 * Disconnecting leaves the queue as it is — every row already says whose
	 * it is — so connecting to a different workspace afterwards cannot send the
	 * old account's orders there.
	 *
	 * @return void
	 */
	public function test_disconnecting_then_connecting_another_workspace_does_not_send_the_old_rows() {
		$this->seed();

		$this->post( 'send4wc_disconnect' );

		$row = $this->onlyQueueRow();

		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $row->status );
		$this->assertSame( 'test-client-id', $row->client_id );

		$this->reconnectAs( 'workspace-b' );
		$this->http->fallback( Send4WC_Http_Recorder::response( 202, array( 'status' => 'accepted', 'routed' => true ) ) );

		Send4WC_Delivery::process_queue();

		$this->assertCount( 0, $this->http->requestsTo( self::STORE_EVENTS ) );
		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );
	}

	/**
	 * ...while reconnecting the same API client still delivers them.
	 *
	 * @return void
	 */
	public function test_reconnecting_the_same_client_still_delivers_the_queue() {
		$row = $this->seed();

		$this->post( 'send4wc_disconnect' );
		$this->reconnectAs( 'test-client-id' );

		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4WC_Delivery::process_queue()['delivered'] );
		$this->assertSame( $row->event_id, $this->http->lastBody()['event']['id'] );
	}

	/**
	 * Disconnect is not the only way out of a connection. Pasting another
	 * account's key, coming back from the Connect button, or `wp send4wc
	 * connect` replaces the credentials while the store is still connected. An
	 * order queued before that belongs to the account it was queued for, and is
	 * not delivered to whichever account was connected next.
	 *
	 * @return void
	 */
	public function test_connecting_over_a_live_connection_leaves_the_old_rows_unsent() {
		$this->seed();

		$this->http->queueToken( 'workspace-b-token' );
		$this->http->queueHandshake( array(), 'Workspace B' );
		// The store inventory every new connection starts with.
		$this->http->queueToken( 'workspace-b-token' );
		$this->http->queueStoreSaved();

		$this->assertIsArray( Send4WC_Admin::establish_connection( 'workspace-b', 'another-secret' ) );

		$this->assertSame( 'workspace-b', Send4WC_Settings::connection()['client_id'] );
		$this->assertSame( 'test-client-id', $this->onlyQueueRow()->client_id, 'The order event belongs to the account it was queued for.' );

		$this->http->fallback( Send4WC_Http_Recorder::response( 202, array( 'status' => 'accepted', 'routed' => true ) ) );

		Send4WC_Delivery::process_queue();

		$this->assertCount( 0, $this->http->requestsTo( self::STORE_EVENTS ) );
		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );
	}

	/**
	 * A credential that is refused changes nothing, the queue included: the row
	 * stays with the connection that is still stored, and is delivered under it.
	 *
	 * @return void
	 */
	public function test_a_refused_credential_leaves_the_queue_alone() {
		$row = $this->seed();

		$this->http->queueTokenRejected( 401 );

		$this->assertWPError( Send4WC_Admin::establish_connection( 'typo-id', 'typo-secret' ) );
		$this->assertSame( 'test-client-id', Send4WC_Settings::connection()['client_id'] );

		$after = $this->onlyQueueRow();

		$this->assertSame( 'test-client-id', $after->client_id );
		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $after->status );

		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4WC_Delivery::process_queue()['delivered'] );
		$this->assertSame( $row->event_id, $this->http->lastBody()['event']['id'] );
	}

	/**
	 * Requeue moves this connection's dead letters back to pending, with a
	 * fresh set of attempts, due now — and nobody else's.
	 *
	 * @return void
	 */
	public function test_requeue_revives_this_connections_dead_letters_only() {
		$permanent = $this->seed( Send4WC_Queue::STATUS_PERMANENT );
		$failed    = $this->seed( Send4WC_Queue::STATUS_FAILED );
		$theirs    = $this->seed( Send4WC_Queue::STATUS_PERMANENT, 'workspace-b' );
		$retrying  = $this->seed();

		Send4WC_Cron::unschedule();

		$this->assertSame( 2, Send4WC_Delivery::requeue() );

		foreach ( array( $permanent, $failed ) as $row ) {
			$after = Send4WC_Queue::find_by_event_id( $row->event_id, 'test-client-id' );

			$this->assertSame( Send4WC_Queue::STATUS_PENDING, $after->status );
			$this->assertSame( 0, (int) $after->attempts );
			$this->assertLessThanOrEqual( time(), strtotime( $after->next_attempt_at . ' UTC' ) );
		}

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, Send4WC_Queue::find_by_event_id( $theirs->event_id, 'workspace-b' )->status );
		$this->assertSame( Send4WC_Queue::STATUS_PENDING, Send4WC_Queue::find_by_event_id( $retrying->event_id, 'test-client-id' )->status );

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ), 'Requeued rows need the worker scheduled.' );
	}

	/**
	 * A requeued row goes out on the next run, as the same event.
	 *
	 * @return void
	 */
	public function test_a_requeued_row_is_delivered_on_the_next_run() {
		$row = $this->seed( Send4WC_Queue::STATUS_PERMANENT );

		Send4WC_Delivery::requeue();

		$this->http->queueToken();
		$this->http->queue202();

		$this->assertSame( 1, Send4WC_Delivery::process_queue()['delivered'] );
		$this->assertSame( $row->event_id, $this->http->lastBody()['event']['id'] );
	}

	/**
	 * The dashboard button.
	 *
	 * @return void
	 */
	public function test_the_requeue_admin_action_reports_what_it_moved() {
		$this->seed( Send4WC_Queue::STATUS_PERMANENT );
		$this->seed( Send4WC_Queue::STATUS_FAILED );

		$location = $this->post( 'send4wc_requeue' );

		$this->assertStringContainsString( 'send4wc_notice=success', $location );
		$this->assertStringContainsString( '2 failed order events will be resent', $this->noticeAt( $location ) );
		$this->assertSame( 2, Send4WC_Queue::counts()[ Send4WC_Queue::STATUS_PENDING ] );
	}

	/**
	 * It is registered, and guarded like every other admin-post action.
	 *
	 * @return void
	 */
	public function test_the_requeue_admin_action_needs_the_nonce() {
		$admin = new Send4WC_Admin();
		$admin->boot();

		$this->assertNotFalse( has_action( 'admin_post_send4wc_requeue', array( $admin, 'handle_requeue' ) ) );

		$this->seed( Send4WC_Queue::STATUS_PERMANENT );

		$_POST    = array( '_wpnonce' => 'forged' );
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );

		try {
			$admin->handle_requeue();
		} finally {
			$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );
		}
	}

	/**
	 * The same order event queued again after a reconnect is the new account's
	 * own row. Reviving the old account's row instead would send, under the new
	 * account, a row that belongs to the old one.
	 *
	 * @return void
	 */
	public function test_an_event_queued_again_under_another_account_gets_its_own_row() {
		$reference = Send4WC_Order_Payload::reference( $this->makeOrder(), 'order.status.processing', true );
		$event_id  = $reference['event']['id'];

		$their_id = Send4WC_Queue::enqueue( $reference, 'order.status.processing' );
		Send4WC_Queue::record_permanent_failure( Send4WC_Queue::find_by_event_id( $event_id, 'test-client-id' ), 'missing ability' );

		$this->reconnectAs( 'client-b' );

		$our_id = Send4WC_Queue::enqueue( $reference, 'order.status.processing' );

		$this->assertIsInt( $our_id );
		$this->assertNotSame( $their_id, $our_id );

		$their_row = Send4WC_Queue::find_by_event_id( $event_id, 'test-client-id' );
		$our_row   = Send4WC_Queue::find_by_event_id( $event_id, 'client-b' );

		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $their_row->status, 'The old account\'s dead letter is not revived.' );
		$this->assertSame( 'missing ability', $their_row->last_error );
		$this->assertSame( 'client-b', $our_row->client_id );
		$this->assertSame( Send4WC_Queue::STATUS_PENDING, $our_row->status );

		$this->makeDue( $our_row );
		$this->http->queueToken();
		$this->http->queue202();

		Send4WC_Delivery::process_queue();

		$sent = $this->http->requestsTo( self::STORE_EVENTS );

		$this->assertCount( 1, $sent );
		$this->assertSame( $event_id, $sent[0]['body']['event']['id'] );
		$this->assertNull( Send4WC_Queue::find_by_event_id( $event_id, 'client-b' ), 'Ours was delivered.' );
		$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, Send4WC_Queue::find_by_event_id( $event_id, 'test-client-id' )->status );
	}

	/**
	 * The notice the screen at this URL would show.
	 *
	 * Read the way the screen reads it rather than out of the URL, so the test
	 * does not care whether the text travels in the query string or in a
	 * per-user transient behind a marker.
	 *
	 * @param string $location Redirect location.
	 * @return string
	 */
	private function noticeAt( $location ) {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		$saved = $_GET;

		wp_parse_str( $query, $_GET );

		try {
			$notice = ( new Send4WC_Admin() )->current_notice();
		} finally {
			$_GET = $saved;
		}

		return is_array( $notice ) ? $notice['message'] : '';
	}
}

/**
 * Thrown in place of the exit that follows every wp-admin redirect.
 *
 * This file's own, rather than AdminMenuTest's Send4WC_Redirected, so the file
 * runs on its own as well as in the suite.
 */
class Send4WC_Queue_Connection_Redirect extends Exception {

	/**
	 * Where the redirect was headed.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Constructor.
	 *
	 * @param string $location Redirect target.
	 */
	public function __construct( $location ) {
		parent::__construct( 'Redirected to ' . $location );

		$this->location = $location;
	}
}
