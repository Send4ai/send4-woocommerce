<?php
/**
 * Keeping the store and Send4 in step.
 *
 * @package Send4WC
 */

/**
 * Send4WC_Store_Sync: the topic lists in, the inventory out.
 */
class Send4WC_Store_Sync_Test extends Send4WC_Test_Case {

	/**
	 * A sync asks Send4 which statuses it wants, keeps the answer for the order
	 * hook, and tells Send4 what the store has.
	 *
	 * @return void
	 */
	public function test_a_sync_refreshes_the_topics_and_pushes_the_inventory() {
		$this->wantTopics( array(), array() );
		$this->http->queueToken();
		$this->http->queueHandshake( array( 'order.status.processing', 'order.status.on-hold' ) );
		$this->http->queueStoreSaved();

		$result = Send4WC_Store_Sync::sync();

		$this->assertTrue( $result );

		$topics = Send4WC_Settings::store_topics();
		$this->assertSame( array( 'order.status.processing', 'order.status.on-hold' ), $topics['start_topics'] );
		$this->assertSame( array( 'order.status.cancelled', 'order.status.refunded' ), $topics['lifecycle_topics'] );
		$this->assertNotSame( '', $topics['refreshed_at'] );

		$pushes = $this->http->requestsTo( '/api/integrations/wordpress/store' );
		$this->assertCount( 1, $pushes );
		$this->assertSame( 'PUT', $pushes[0]['method'] );
		$this->assertContains( 'processing', wp_list_pluck( $pushes[0]['body']['statuses'], 'key' ) );
		$this->assertNotSame( '', Send4WC_Settings::store_sync()['at'] );
		$this->assertSame( '', Send4WC_Settings::store_sync()['error'] );
	}

	/**
	 * A topic Send4 could never accept is not trusted to decide what leaves the
	 * store.
	 *
	 * @return void
	 */
	public function test_only_order_status_topics_are_kept() {
		$this->http->queueToken();
		$this->http->queueHandshake( array( 'order.status.processing', 'checkout.abandoned', 'order.status.Bad Status', 42 ) );
		$this->http->queueStoreSaved();

		Send4WC_Store_Sync::sync();

		$this->assertSame( array( 'order.status.processing' ), Send4WC_Settings::store_topics()['start_topics'] );
	}

	/**
	 * Send4 down: the topics the store had are kept, and the error is shown.
	 *
	 * @return void
	 */
	public function test_an_unreachable_send4_keeps_the_last_topics() {
		$this->http->queueToken();
		$this->http->queue500();

		$result = Send4WC_Store_Sync::sync();

		$this->assertWPError( $result );
		$this->assertSame( array( 'order.status.processing' ), Send4WC_Settings::store_topics()['start_topics'] );
		$this->assertNotSame( '', Send4WC_Settings::store_sync()['error'] );
		$this->assertCount( 0, $this->http->requestsTo( '/api/integrations/wordpress/store' ) );
	}

	/**
	 * A push that fails says why, and keeps when the last good one was.
	 *
	 * @return void
	 */
	public function test_a_failed_push_keeps_the_last_successful_time() {
		$this->http->queueToken();
		$this->http->queueHandshake();
		$this->http->queueStoreSaved();
		Send4WC_Store_Sync::sync();
		$synced_at = Send4WC_Settings::store_sync()['at'];

		$this->http->queueHandshake();
		$this->http->queue409();

		$result = Send4WC_Store_Sync::sync();

		$this->assertWPError( $result );
		$this->assertSame( 'send4wc_no_install', $result->get_error_code() );
		$this->assertSame( $synced_at, Send4WC_Settings::store_sync()['at'] );
		$this->assertNotSame( '', Send4WC_Settings::store_sync()['error'] );
	}

	/**
	 * The hourly event on a disconnected store asks Send4 nothing.
	 *
	 * @return void
	 */
	public function test_the_hourly_event_does_nothing_remote_while_disconnected() {
		Send4WC_Settings::disconnect();

		Send4WC_Store_Sync::run();

		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * The hourly event is the safety net for the every-minute one, which drops
	 * itself whenever the queue empties.
	 *
	 * @return void
	 */
	public function test_the_hourly_event_re_arms_the_queue_when_something_waits() {
		$this->queueEvent( $this->makeOrder() );
		Send4WC_Cron::unschedule();
		$this->http->queueToken();
		$this->http->queueHandshake();
		$this->http->queueStoreSaved();

		Send4WC_Store_Sync::run();

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
	}

	/**
	 * A category added in the shop reaches Send4's builder within a minute, and a
	 * bulk edit is one push, not one per term.
	 *
	 * @return void
	 */
	public function test_a_catalogue_change_schedules_one_push() {
		wp_clear_scheduled_hook( Send4WC_Store_Sync::PUSH_HOOK );

		wp_insert_term( 'Lovos', 'product_cat' );
		wp_insert_term( 'Spintos', 'product_cat' );

		$this->assertNotFalse( wp_next_scheduled( Send4WC_Store_Sync::PUSH_HOOK ) );
		$this->assertCount( 1, send4wc_tests_cron_events( Send4WC_Store_Sync::PUSH_HOOK ) );
		$this->assertSame( 0, $this->http->requestCount() );
	}

	/**
	 * Connecting tells the (possibly new) workspace about the store at once.
	 *
	 * @return void
	 */
	public function test_connecting_pushes_the_inventory() {
		Send4WC_Settings::disconnect();
		// The candidate signs in for itself; the push after it signs in again,
		// because connecting drops whatever token was cached.
		$this->http->queueToken();
		$this->http->queueHandshake( array( 'order.status.processing' ), 'Baldai UAB' );
		$this->http->queueToken();
		$this->http->queueStoreSaved();

		$result = Send4WC_Admin::establish_connection( 'new-client', 'secret_' . str_repeat( 'a', 48 ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/store' ) );
		$this->assertSame( array( 'order.status.processing' ), Send4WC_Settings::store_topics()['start_topics'] );
	}
}

/**
 * Every scheduled run of a hook, across timestamps.
 *
 * @param string $hook Hook name.
 * @return array<int,array>
 */
function send4wc_tests_cron_events( $hook ) {
	$events = array();

	foreach ( (array) _get_cron_array() as $hooks ) {
		if ( isset( $hooks[ $hook ] ) ) {
			foreach ( $hooks[ $hook ] as $event ) {
				$events[] = $event;
			}
		}
	}

	return $events;
}
