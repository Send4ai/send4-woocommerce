<?php
/**
 * The WP-CLI commands that touch the queue, the topics and the destination.
 *
 * @package Send4WC
 */

namespace WP_CLI\Utils {

	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {

		/**
		 * WP-CLI's table printer, which the suite has no copy of: records the
		 * table as a `table` line of JSON in the WP_CLI double's output.
		 *
		 * @param string                         $format Output format.
		 * @param array<int,array<string,mixed>> $items  Rows.
		 * @param array<int,string>|string       $fields Columns.
		 * @return void
		 */
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$output[] = array(
				'table',
				(string) wp_json_encode(
					array(
						'format' => $format,
						'fields' => $fields,
						'items'  => array_values( $items ),
					)
				),
			);
		}
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/Support/WpCliDouble.php';

	/**
	 * `wp send4wc` is how the development stack and support configure the
	 * plugin, so it gets the same guarantees as the dashboard.
	 */
	class Send4WC_Cli_Test extends Send4WC_Test_Case {

		/**
		 * A made-up secret in the shape Send4 issues.
		 *
		 * @var string
		 */
		const SECRET = 'secret_NotARealSecretNotARealSecretNotARealSecret000000';

		/**
		 * Start every test with no recorded output.
		 *
		 * @return void
		 */
		public function set_up() {
			parent::set_up();

			WP_CLI::$output = array();
		}

		/**
		 * The last thing a command printed.
		 *
		 * @return array{0:string,1:string}
		 */
		private function lastOutput() {
			$this->assertNotEmpty( WP_CLI::$output, 'The command printed nothing.' );

			return WP_CLI::$output[ count( WP_CLI::$output ) - 1 ];
		}

		/**
		 * The rows of the one table a command printed.
		 *
		 * @return array<int,array<string,string>>
		 */
		private function onlyTable() {
			$tables = WP_CLI::printed( 'table' );

			$this->assertCount( 1, $tables, 'Expected exactly one table.' );

			return json_decode( $tables[0], true )['items'];
		}

		/**
		 * A destination outside the allow-list is refused before anything is
		 * stored. The request layer would refuse every call to it anyway, but
		 * only after the credentials had been saved beside it.
		 *
		 * @return void
		 */
		public function test_connect_refuses_a_url_that_is_not_send4_and_stores_nothing() {
			$before = Send4WC_Settings::connection();

			try {
				( new Send4WC_Cli() )->connect(
					array(),
					array(
						'client-id'     => 'new-client',
						'client-secret' => self::SECRET,
						'url-base'      => 'https://evil.com',
					)
				);
				$this->fail( 'connect should have stopped with an error.' );
			} catch ( Send4WC_Cli_Halted $halted ) {
				$this->assertStringContainsString( 'is not a Send4 address', $halted->getMessage() );
			}

			$this->assertFalse( get_option( Send4WC_Settings::BASE_URL_OPTION, false ) );
			$this->assertSame( $before, Send4WC_Settings::connection() );
			$this->assertSame( 0, $this->http->requestCount() );
		}

		/**
		 * An allowed override is still stored and used.
		 *
		 * @return void
		 */
		public function test_connect_stores_an_allowed_url() {
			$this->http->queueToken();
			$this->http->queueHandshake();
			$this->http->queueToken();
			$this->http->queueStoreSaved();

			( new Send4WC_Cli() )->connect(
				array(),
				array(
					'client-id'     => 'new-client',
					'client-secret' => self::SECRET,
					'url-base'      => 'https://staging.send4.ai/',
				)
			);

			$this->assertSame( 'https://staging.send4.ai', get_option( Send4WC_Settings::BASE_URL_OPTION ) );
			$this->assertSame( 'success', $this->lastOutput()[0] );
		}

		/**
		 * `wp send4wc requeue` puts this connection's dead letters back in line.
		 *
		 * @return void
		 */
		public function test_requeue_moves_failed_rows_back_to_pending() {
			$row = $this->queueEvent( $this->makeOrder() );
			Send4WC_Queue::record_permanent_failure( $row, 'missing ability' );
			Send4WC_Cron::unschedule();

			$this->assertSame( Send4WC_Queue::STATUS_PERMANENT, $this->onlyQueueRow()->status );

			( new Send4WC_Cli() )->requeue();

			$this->assertSame( Send4WC_Queue::STATUS_PENDING, $this->onlyQueueRow()->status );
			$this->assertSame( 'success', $this->lastOutput()[0] );
			$this->assertStringContainsString( 'Requeued 1', $this->lastOutput()[1] );
			$this->assertNotFalse( wp_next_scheduled( Send4WC_Cron::HOOK ) );
		}

		/**
		 * Without a connection there is nothing to requeue them for.
		 *
		 * @return void
		 */
		public function test_requeue_refuses_when_not_connected() {
			Send4WC_Settings::disconnect();

			$this->expectException( Send4WC_Cli_Halted::class );

			( new Send4WC_Cli() )->requeue();
		}

		/**
		 * `wp send4wc handshake` says which statuses leave the store and with
		 * what, and remembers the lists for the order hook straight away. A
		 * status in both lists is said once, as the one that starts a flow,
		 * because that is the event that goes.
		 *
		 * @return void
		 */
		public function test_handshake_prints_the_topics_and_remembers_them() {
			$this->wantTopics( array(), array() );

			$this->http->queueToken();
			$this->http->queueHandshake(
				array( 'order.status.processing' ),
				'Baldai UAB',
				array( 'order.status.processing', 'order.status.cancelled' )
			);

			( new Send4WC_Cli() )->handshake();

			$this->assertContains( 'Account: Baldai UAB', WP_CLI::printed( 'log' ) );
			$this->assertSame( array(), WP_CLI::printed( 'warning' ) );
			$this->assertSame(
				array(
					array(
						'topic' => 'order.status.processing',
						'sent'  => 'starts a flow (order details)',
					),
					array(
						'topic' => 'order.status.cancelled',
						'sent'  => 'ends runs (number and status only)',
					),
				),
				$this->onlyTable()
			);

			$topics = Send4WC_Settings::store_topics();

			$this->assertSame( array( 'order.status.processing' ), $topics['start_topics'] );
			$this->assertSame( array( 'order.status.processing', 'order.status.cancelled' ), $topics['lifecycle_topics'] );
		}

		/**
		 * When no flow starts on any status, the command says that nothing
		 * leaves the store rather than printing an empty table.
		 *
		 * @return void
		 */
		public function test_handshake_warns_when_no_flow_starts_on_a_status() {
			$this->http->queueToken();
			$this->http->queueHandshake( array(), 'Baldai UAB', array() );

			( new Send4WC_Cli() )->handshake();

			$this->assertSame( array( 'No flow starts on an order status, so no order is sent.' ), WP_CLI::printed( 'warning' ) );
			$this->assertSame( array(), WP_CLI::printed( 'table' ) );
			$this->assertSame( array(), Send4WC_Settings::store_topics()['start_topics'] );
		}

		/**
		 * A handshake that fails leaves the topics the store already had: an
		 * outage must not switch every order event off.
		 *
		 * @return void
		 */
		public function test_a_failed_handshake_keeps_the_topics() {
			$this->http->queueToken();
			$this->http->queue500();

			try {
				( new Send4WC_Cli() )->handshake();
				$this->fail( 'handshake should have stopped with an error.' );
			} catch ( Send4WC_Cli_Halted $halted ) {
				$this->assertStringContainsString( '500', $halted->getMessage() );
			}

			$this->assertSame( array( 'order.status.processing' ), Send4WC_Settings::store_topics()['start_topics'] );
		}

		/**
		 * `wp send4wc run_queue` sends what is due and says how it went.
		 *
		 * @return void
		 */
		public function test_run_queue_sends_what_is_due() {
			$this->queueEvent( $this->makeOrder() );

			$this->http->queueToken();
			$this->http->queue202();

			( new Send4WC_Cli() )->run_queue();

			$this->assertSame(
				array( 'success', 'Attempted 1, delivered 1, still failing 0.' ),
				$this->lastOutput()
			);
			$this->assertSame( array(), $this->queueRows() );
		}

		/**
		 * `wp send4wc sync_store` refreshes the topics and pushes the inventory.
		 *
		 * @return void
		 */
		public function test_sync_store_refreshes_the_topics_and_pushes_the_inventory() {
			$this->http->queueToken();
			$this->http->queueHandshake( array( 'order.status.processing', 'order.status.completed' ) );
			$this->http->queueStoreSaved();

			( new Send4WC_Cli() )->sync_store();

			$this->assertCount( 1, $this->http->requestsTo( '/api/integrations/wordpress/store' ) );
			$this->assertSame( 'success', $this->lastOutput()[0] );
			$this->assertStringContainsString( 'Send4 wants 2 topic(s)', $this->lastOutput()[1] );
			$this->assertSame(
				array( 'order.status.processing', 'order.status.completed' ),
				Send4WC_Settings::store_topics()['start_topics']
			);
		}

		/**
		 * Without a connection there is nothing to sync with, and nothing is asked.
		 *
		 * @return void
		 */
		public function test_sync_store_refuses_when_not_connected() {
			Send4WC_Settings::disconnect();

			$this->expectException( Send4WC_Cli_Halted::class );

			try {
				( new Send4WC_Cli() )->sync_store();
			} finally {
				$this->assertSame( 0, $this->http->requestCount() );
			}
		}
	}
}
