<?php
/**
 * What the Send4 screen shows, gathered in one place so the view only prints.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the screen's data from the options, the queue and the handshake.
 */
class Send4WC_Admin_Dashboard {

	/** Queue rows listed on the screen. */
	const RECENT_ROWS = 10;

	/** Log entries listed on the screen. */
	const RECENT_LOG = 15;

	/**
	 * Everything the screen needs.
	 *
	 * @param array|WP_Error|null $handshake Handshake result, null when disconnected.
	 * @return array<string,mixed>
	 */
	public static function build( $handshake ) {
		$state      = Send4WC_Admin::connection_state( $handshake );
		$topics     = Send4WC_Settings::store_topics();
		$has_queue  = Send4WC_Queue::table_exists();
		$statuses   = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$labels     = array();
		$phone_rule = function_exists( 'wc_get_page_id' ) ? Send4WC_Store_Inventory::phone_required() : array(
			'classic' => null,
			'block'   => null,
		);

		foreach ( $statuses as $key => $label ) {
			$slug            = 0 === strpos( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key;
			$labels[ $slug ] = wp_strip_all_tags( (string) $label );
		}

		return array(
			'state'          => $state,
			'account'        => 'connected' === $state && isset( $handshake['client']['name'] ) ? (string) $handshake['client']['name'] : '',
			'start_topics'   => self::describe( $topics['start_topics'], $labels ),
			'ending_topics'  => self::describe( array_values( array_diff( $topics['lifecycle_topics'], $topics['start_topics'] ) ), $labels ),
			'topics_at'      => $topics['refreshed_at'],
			'store_sync'     => Send4WC_Settings::store_sync(),
			'phone_optional' => false === $phone_rule['classic'] || false === $phone_rule['block'],
			'counts'         => $has_queue ? Send4WC_Queue::counts() : array(
				Send4WC_Queue::STATUS_PENDING   => 0,
				Send4WC_Queue::STATUS_FAILED    => 0,
				Send4WC_Queue::STATUS_PERMANENT => 0,
			),
			'has_queue'      => $has_queue,
			'rows'           => $has_queue ? Send4WC_Queue::all( self::RECENT_ROWS ) : array(),
			'delivery'       => Send4WC_Settings::delivery_status(),
			'log'            => array_slice( Send4WC_Logger::entries(), 0, self::RECENT_LOG ),
		);
	}

	/**
	 * Topics as the store's own status names.
	 *
	 * @param string[]             $topics `order.status.<status>` topics.
	 * @param array<string,string> $labels Status slug => label.
	 * @return array<int,array{topic:string,status:string,label:string}>
	 */
	private static function describe( array $topics, array $labels ) {
		$out = array();

		foreach ( $topics as $topic ) {
			$status = substr( $topic, strlen( Send4WC_Order_Payload::TOPIC_PREFIX ) );

			$out[] = array(
				'topic'  => $topic,
				'status' => $status,
				// A status Send4 knows but this store does not (deleted, or the
				// plugin that added it is off) is named by its slug.
				'label'  => isset( $labels[ $status ] ) ? $labels[ $status ] : $status,
			);
		}

		return $out;
	}
}
