<?php
/**
 * WP-CLI commands: `wp send4wc …`.
 *
 * For the development stack and for support, without a browser. ⚠️ A key
 * connected here must be one Send4 minted for THIS store's connection: store
 * events from a hand-made API key are refused (409), because Send4 cannot tell
 * which store the key belongs to.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the Send4 connection, the store sync and the order-event queue.
 */
class Send4WC_Cli {

	/**
	 * Register with WP-CLI.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'send4wc', __CLASS__ );
	}

	/**
	 * Verify API credentials against Send4, then store them.
	 *
	 * Credentials that fail the check are not stored, and neither is --url-base:
	 * whatever the site was connected with before, and wherever, stays in place.
	 *
	 * ## OPTIONS
	 *
	 * --client-id=<id>
	 * : The API client ID from your Send4 dashboard.
	 *
	 * --client-secret=<secret>
	 * : The API client secret. Pass - to read it from STDIN instead, which keeps
	 * : it out of the process arguments on a shared or logged host.
	 *
	 * [--url-base=<url>]
	 * : Override the Send4 URL. Ignored when SEND4WC_API_BASE_URL is defined.
	 *
	 * ## EXAMPLES
	 *
	 *     wp send4wc connect --client-id=abc --client-secret=shh
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function connect( $args, $assoc_args ) {
		unset( $args );

		$client_id     = isset( $assoc_args['client-id'] ) ? (string) $assoc_args['client-id'] : '';
		$client_secret = isset( $assoc_args['client-secret'] ) ? (string) $assoc_args['client-secret'] : '';

		if ( '-' === $client_secret ) {
			$client_secret = trim( (string) file_get_contents( 'php://stdin' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		if ( '' === $client_id || '' === $client_secret ) {
			WP_CLI::error( 'Both --client-id and --client-secret are required.' );
		}

		// Refused, never sent: anything else was not a Send4 secret to begin with.
		if ( ! Send4WC_Settings::is_client_secret( $client_secret ) ) {
			WP_CLI::error( 'That is not a Send4 client secret. Copy it again from the key in Send4.' );
		}

		$url_base = ! empty( $assoc_args['url-base'] ) && ! Send4WC_Settings::base_url_is_locked()
			? untrailingslashit( (string) $assoc_args['url-base'] )
			: '';

		// Checked here for the sake of the message. The request layer refuses
		// such a destination too, but cannot say what an acceptable one looks like.
		if ( '' !== $url_base && ! Send4WC_Settings::base_url_is_allowed( $url_base ) ) {
			WP_CLI::error( sprintf( '%s is not a Send4 address. Use https://*.send4.ai, or http:// on a local development host.', $url_base ) );
		}

		// The same routine as the screen: the pair is proven before it is
		// stored, and a connect reports the store to the (possibly new)
		// workspace straight away. The destination goes in with the pair rather
		// than being saved first: saved on its own, a refused pair left the
		// credentials that did work talking to the wrong environment.
		$had_credentials = Send4WC_Settings::has_credentials();
		$result          = Send4WC_Admin::establish_connection( $client_id, $client_secret, $url_base );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error(
				$result->get_error_message() . ' ' . (
					$had_credentials
						? 'The new credentials were not saved; this site is still using the ones it had.'
						: 'The credentials were not saved.'
				)
			);
		}

		$name = isset( $result['client']['name'] ) ? (string) $result['client']['name'] : '';

		WP_CLI::success(
			sprintf(
				'Connected to %s at %s. Flows start on %d order status(es).',
				$name,
				Send4WC_Settings::base_url(),
				isset( $result['store']['start_topics'] ) ? count( $result['store']['start_topics'] ) : 0
			)
		);
	}

	/**
	 * Run the connection test and print which order statuses Send4 wants.
	 *
	 * @return void
	 */
	public function handshake() {
		$client = new Send4WC_Api_Client();
		$result = $client->handshake();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		Send4WC_Settings::update_store_topics( $result['store'] );

		WP_CLI::log( 'Account: ' . ( isset( $result['client']['name'] ) ? $result['client']['name'] : '(unknown)' ) );

		$rows = array();

		foreach ( $result['store']['start_topics'] as $topic ) {
			$rows[] = array(
				'topic' => $topic,
				'sent'  => 'starts a flow (order details)',
			);
		}

		foreach ( array_diff( $result['store']['lifecycle_topics'], $result['store']['start_topics'] ) as $topic ) {
			$rows[] = array(
				'topic' => $topic,
				'sent'  => 'ends runs (number and status only)',
			);
		}

		if ( empty( $result['store']['start_topics'] ) ) {
			WP_CLI::warning( 'No flow starts on an order status, so no order is sent.' );
		}

		if ( ! empty( $rows ) ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'topic', 'sent' ) );
		}
	}

	/**
	 * Ask Send4 which statuses it wants and send it the store's statuses and categories.
	 *
	 * ## EXAMPLES
	 *
	 *     wp send4wc sync_store
	 *
	 * @return void
	 */
	public function sync_store() {
		if ( ! Send4WC_Settings::has_credentials() ) {
			WP_CLI::error( 'This store is not connected to Send4.' );
		}

		$result = Send4WC_Store_Sync::sync();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$inventory = Send4WC_Store_Inventory::build();

		WP_CLI::success(
			sprintf(
				'Synced: %d status(es), %d categor(ies), %d tag(s) sent; Send4 wants %d topic(s).',
				count( $inventory['statuses'] ),
				count( $inventory['product_categories'] ),
				count( $inventory['product_tags'] ),
				count( Send4WC_Settings::store_topics()['start_topics'] )
			)
		);
	}

	/**
	 * Show what is waiting in the queue.
	 *
	 * @return void
	 */
	public function queue() {
		if ( ! Send4WC_Queue::table_exists() ) {
			WP_CLI::error( 'The Send4 queue table is missing. Deactivate and reactivate the plugin.' );
		}

		$rows = array();

		foreach ( Send4WC_Queue::all( 50 ) as $row ) {
			$rows[] = array(
				'id'           => $row->id,
				'event_id'     => $row->event_id,
				'topic'        => $row->topic,
				'status'       => $row->status,
				'attempts'     => $row->attempts,
				'next_attempt' => $row->next_attempt_at,
				'last_error'   => (string) $row->last_error,
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'The queue is empty.' );

			return;
		}

		WP_CLI\Utils\format_items(
			'table',
			$rows,
			array( 'id', 'event_id', 'topic', 'status', 'attempts', 'next_attempt', 'last_error' )
		);
	}

	/**
	 * Send every queued order event that is due.
	 *
	 * @return void
	 */
	public function run_queue() {
		$summary = Send4WC_Delivery::process_queue( 100 );

		WP_CLI::success(
			sprintf(
				'Attempted %d, delivered %d, still failing %d.',
				$summary['attempted'],
				$summary['delivered'],
				$summary['failed']
			)
		);
	}

	/**
	 * Put failed order events back on the queue.
	 *
	 * For after the cause is fixed: a key replaced, a reconnect, the destination
	 * corrected. Only events queued under the current Send4 connection are
	 * resent; another account's are never sent to this one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp send4wc requeue && wp send4wc run_queue
	 *
	 * @return void
	 */
	public function requeue() {
		if ( ! Send4WC_Settings::has_credentials() ) {
			WP_CLI::error( 'This store is not connected to Send4.' );
		}

		if ( ! Send4WC_Queue::table_exists() ) {
			WP_CLI::error( 'The Send4 queue table is missing. Deactivate and reactivate the plugin.' );
		}

		$moved = Send4WC_Delivery::requeue();

		WP_CLI::success( sprintf( 'Requeued %d failed order event(s). They are due now; run `wp send4wc run_queue` to send them immediately.', $moved ) );
	}

	/**
	 * Print a one-screen summary of the plugin's state.
	 *
	 * @return void
	 */
	public function status() {
		$connection = Send4WC_Settings::connection();
		$counts     = Send4WC_Queue::table_exists() ? Send4WC_Queue::counts() : array();
		$topics     = Send4WC_Settings::store_topics();
		$sync       = Send4WC_Settings::store_sync();

		WP_CLI::log( 'Send4 URL:     ' . Send4WC_Settings::base_url() );
		WP_CLI::log( 'Client ID:     ' . ( '' !== $connection['client_id'] ? $connection['client_id'] : '(not set)' ) );
		WP_CLI::log( 'Account:       ' . ( '' !== $connection['client_name'] ? $connection['client_name'] : '(not connected)' ) );
		WP_CLI::log( 'WooCommerce:   ' . ( defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : 'not active' ) );
		WP_CLI::log( 'Start topics:  ' . ( empty( $topics['start_topics'] ) ? '(none)' : implode( ', ', $topics['start_topics'] ) ) );
		WP_CLI::log( 'End topics:    ' . ( empty( $topics['lifecycle_topics'] ) ? '(none)' : implode( ', ', $topics['lifecycle_topics'] ) ) );
		WP_CLI::log( 'Topics as of:  ' . ( '' !== $topics['refreshed_at'] ? $topics['refreshed_at'] . ' UTC' : 'never' ) );
		WP_CLI::log( 'Store synced:  ' . ( '' !== $sync['at'] ? $sync['at'] . ' UTC' : 'never' ) . ( '' !== $sync['error'] ? ' (last try: ' . $sync['error'] . ')' : '' ) );
		WP_CLI::log( 'Queue table:   ' . ( Send4WC_Queue::table_exists() ? Send4WC_Queue::table() : 'MISSING' ) );

		foreach ( $counts as $status => $total ) {
			WP_CLI::log( sprintf( '  %-16s %d', $status, $total ) );
		}

		$next = wp_next_scheduled( Send4WC_Cron::HOOK );
		WP_CLI::log( 'Next retry:    ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled' ) );
	}
}
