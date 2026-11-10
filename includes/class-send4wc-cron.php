<?php
/**
 * WP-Cron wiring for the retry queue.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * One recurring event, every minute, that drains due queue rows.
 *
 * It exists only while something is pending: scheduled on enqueue and on
 * requeue, dropped by Send4WC_Delivery::process_queue() once the queue is empty.
 */
class Send4WC_Cron {

	const HOOK     = 'send4wc_process_queue';
	const SCHEDULE = 'send4wc_minute';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Register the one-minute interval.
	 *
	 * A minute is deliberate: the first retry is a minute after a failed
	 * submission, and anything coarser would make that promise a lie.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		// Another plugin may read the schedules before init (wp_get_schedules()
		// from its own plugins_loaded code), and translating then earns a
		// `_load_textdomain_just_in_time` notice on the customer's site. The
		// name is only ever shown in cron-inspector plugins, well after init.
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 60,
			'display'  => did_action( 'init' ) ? __( 'Every minute (Send4)', 'send4-for-woocommerce' ) : 'Every minute (Send4)',
		);

		return $schedules;
	}

	/**
	 * Schedule the event if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Schedule the event only when there is something for it to deliver.
	 *
	 * The safety net for the every-minute event, which is otherwise scheduled
	 * only by an enqueue or a requeue and drops itself once the queue is empty.
	 *
	 * @return void
	 */
	public static function ensure_scheduled_if_pending() {
		if ( Send4WC_Queue::table_exists() && Send4WC_Queue::has_pending() ) {
			self::ensure_scheduled();
		}
	}

	/**
	 * Remove the event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run() {
		Send4WC_Delivery::process_queue();
	}
}
