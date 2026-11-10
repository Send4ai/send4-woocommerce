<?php
/**
 * The outbox: an order event must never be lost because Send4 was deploying.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rows live in {prefix}send4wc_queue and are worked by WP-Cron.
 *
 * Every order event goes through here, never straight to Send4: the status
 * change that produces it can happen inside a buyer's checkout request, which
 * must not wait on a network call. A new row is due at once, and an async
 * action asks the worker to send it within seconds (Send4WC_Store_Events).
 */
class Send4WC_Queue {

	const STATUS_PENDING   = 'pending';
	const STATUS_FAILED    = 'failed';
	const STATUS_PERMANENT = 'failed_permanent';

	const MAX_ATTEMPTS = 10;

	/**
	 * Seconds a worker holds a row it has claimed.
	 *
	 * Comfortably longer than the worst single delivery (a 10s send, a 401,
	 * a 10s token exchange and a 10s replay), so a slow send is never claimed
	 * twice; short enough that a worker which died mid-send hands the row back
	 * within a couple of minutes.
	 */
	const LEASE_SECONDS = 120;

	/**
	 * Backoff in seconds after a failed attempt. The last value repeats until
	 * MAX_ATTEMPTS is reached, roughly 1.5 days after the event.
	 *
	 * @var int[]
	 */
	private static $backoff = array( 60, 300, 900, 3600, 21600 );

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'send4wc_queue';
	}

	/**
	 * Create the table. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta parses this itself rather than handing it to the database, and
		// it is picky: two spaces after PRIMARY KEY, uppercase KEY (never INDEX),
		// one column per line, no IF NOT EXISTS, no inline column-level UNIQUE.
		// TEXT columns may not carry a DEFAULT at all, and the epoch sentinel is
		// used instead of the zero date because NO_ZERO_DATE rejects the latter.
		//
		// client_id is the Send4 API client the row was queued under. Send4's
		// receipt is per client, so a row sent under a different client would
		// be an order in somebody else's workspace. The same goes for
		// uniqueness: one row per event *per client*.
		$sql = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	event_id varchar(191) NOT NULL,
	topic varchar(191) NOT NULL,
	client_id varchar(191) NOT NULL DEFAULT '',
	envelope longtext NOT NULL,
	attempts int(11) NOT NULL DEFAULT 0,
	status varchar(32) NOT NULL DEFAULT 'pending',
	next_attempt_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	last_error text NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY event_client (event_id,client_id),
	KEY status_next_attempt_at (status,next_attempt_at),
	KEY topic (topic)
) {$collate};";

		dbDelta( $sql );

		if ( ! self::table_exists() ) {
			// dbDelta compares against SHOW/DESCRIBE output, which not every
			// storage backend answers the way it expects. A plain create is a
			// cheap safety net; without the table every order event is lost. The
			// statement is the one dbDelta() was given, built only from the
			// table name and $wpdb->get_charset_collate(): no input reaches it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- creating the plugin's own table.
			$wpdb->query( str_replace( 'CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql ) );
		}
	}

	/**
	 * Does the table exist?
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		// esc_like matters here: the prefix and the table name both contain
		// underscores, which LIKE reads as single-character wildcards, so
		// without it this answers true for a table that merely resembles ours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Drop the table. Used by uninstall.php only.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	/**
	 * Put an order event on the queue, due at once.
	 *
	 * The event id and the client it is queued under are the unique key, so
	 * the same order reaching the same status twice before the first is sent
	 * is one delivery. What happens to a row already there for this account:
	 *
	 * - it is failed or failed_permanent: it goes back to pending with this
	 *   envelope and a fresh set of attempts, because the order has evidently
	 *   moved again and whatever dead-lettered it may have changed;
	 * - it is pending: it is left exactly as it is, and its id returned. The
	 *   event is built from the live order when it is sent anyway.
	 *
	 * @param array  $envelope The reference Send4WC_Order_Payload::reference() made:
	 *                         an order id and a topic, no personal data.
	 * @param string $topic    `order.status.<status>`.
	 * @return int|false Row id, or false when the row could not be written.
	 */
	public static function enqueue( array $envelope, $topic ) {
		global $wpdb;

		$now       = gmdate( 'Y-m-d H:i:s' );
		$event_id  = isset( $envelope['event']['id'] ) ? (string) $envelope['event']['id'] : '';
		$client_id = self::current_client_id();

		if ( '' === $event_id || '' === $client_id ) {
			return false;
		}

		$existing = self::find_by_event_id( $event_id, $client_id );

		if ( $existing ) {
			if ( in_array( $existing->status, array( self::STATUS_FAILED, self::STATUS_PERMANENT ), true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					self::table(),
					array(
						'envelope'        => wp_json_encode( $envelope ),
						'status'          => self::STATUS_PENDING,
						'attempts'        => 0,
						'next_attempt_at' => $now,
						'updated_at'      => $now,
						'last_error'      => '',
					),
					array( 'id' => (int) $existing->id ),
					array( '%s', '%s', '%d', '%s', '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					return false;
				}
			}

			return (int) $existing->id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'event_id'        => $event_id,
				'topic'           => (string) $topic,
				'client_id'       => $client_id,
				'envelope'        => wp_json_encode( $envelope ),
				'attempts'        => 0,
				'status'          => self::STATUS_PENDING,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
				'last_error'      => '',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			return (int) $wpdb->insert_id;
		}

		// Another request queued it for this account between the look-up and
		// the insert: that row is this event's.
		$existing = self::find_by_event_id( $event_id, $client_id );

		return $existing ? (int) $existing->id : false;
	}

	/**
	 * The API client id of the current connection, '' when disconnected.
	 *
	 * @return string
	 */
	public static function current_client_id() {
		$connection = Send4WC_Settings::connection();

		return (string) $connection['client_id'];
	}

	/**
	 * Take a due row for this worker, so an overlapping run cannot send it too.
	 *
	 * WP-Cron's lock lasts 60 seconds and a batch against a slow Send4 can take
	 * longer, so two workers holding the same batch is ordinary, not a corner
	 * case. The claim is one conditional UPDATE: it pushes next_attempt_at out
	 * by the lease, and only the worker whose UPDATE still matched the value it
	 * SELECTed wins. A worker that dies mid-send needs no cleanup — once the
	 * lease runs out the row is simply due again.
	 *
	 * @param object $row Row as returned by due().
	 * @return bool True when this worker now owns the row.
	 */
	public static function claim( $row ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET next_attempt_at = %s WHERE id = %d AND status = %s AND next_attempt_at = %s',
				$table,
				gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS ),
				(int) $row->id,
				self::STATUS_PENDING,
				(string) $row->next_attempt_at
			)
		);

		return 1 === (int) $claimed;
	}

	/**
	 * Dead-letter every pending row queued under a different API client.
	 *
	 * @param string $client_id The current connection's client id.
	 * @param string $error     Reason shown on the settings screen.
	 * @return int Rows quarantined.
	 */
	public static function quarantine_foreign( $client_id, $error ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$moved = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s, last_error = %s WHERE status = %s AND client_id <> %s',
				$table,
				self::STATUS_PERMANENT,
				gmdate( 'Y-m-d H:i:s' ),
				(string) $error,
				self::STATUS_PENDING,
				(string) $client_id
			)
		);

		return (int) $moved;
	}

	/**
	 * Put dead-lettered rows back in line for delivery.
	 *
	 * A permanent failure is only permanent until somebody fixes its cause: a
	 * revoked key replaced, a reconnect, a destination corrected.
	 * Without this the only way out was to wait for retention to delete them.
	 *
	 * Only rows belonging to the given client move: another workspace's orders
	 * must never be sent under this one.
	 *
	 * @param string $client_id The current connection's client id.
	 * @return int Rows requeued.
	 */
	public static function requeue( $client_id ) {
		global $wpdb;

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// attempts goes to 0, not 1: the next attempt is the first of a fresh
		// run of MAX_ATTEMPTS, and record_failure() counts from here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$moved = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, attempts = 0, next_attempt_at = %s, updated_at = %s WHERE status IN (%s, %s) AND client_id = %s',
				$table,
				self::STATUS_PENDING,
				$now,
				$now,
				self::STATUS_FAILED,
				self::STATUS_PERMANENT,
				(string) $client_id
			)
		);

		return (int) $moved;
	}

	/**
	 * Is anything still waiting to be delivered?
	 *
	 * @return bool
	 */
	public static function has_pending() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s LIMIT 1', $table, self::STATUS_PENDING ) );
	}

	/**
	 * Look a row up by its event id, for one API client.
	 *
	 * @param string $event_id  `woo:{order}:{topic}`.
	 * @param string $client_id Owning client.
	 * @return object|null
	 */
	public static function find_by_event_id( $event_id, $client_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %s AND client_id = %s LIMIT 1', $table, (string) $event_id, (string) $client_id )
		);
	}

	/**
	 * Rows that are due for another attempt.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,object>
	 */
	public static function due( $limit = 20 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d',
				$table,
				self::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' ),
				(int) $limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All rows, newest first, for the dashboard.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,object>
	 */
	public static function all( $limit = 50 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, (int) $limit ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows with an id above the given one, oldest first.
	 *
	 * A cursor rather than an offset, so the whole table can be walked in
	 * batches — including by a caller that deletes rows as it goes, which
	 * would shift an offset underneath itself.
	 *
	 * @param int $after_id Row id to start after; 0 for the beginning.
	 * @param int $limit    Maximum rows.
	 * @return array<int,object>
	 */
	public static function after( $after_id, $limit = 500 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, (int) $after_id, (int) $limit )
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rows sit in each status.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table  = self::table();
		$counts = array(
			self::STATUS_PENDING   => 0,
			self::STATUS_FAILED    => 0,
			self::STATUS_PERMANENT => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ) );

		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * The envelope stored on a row.
	 *
	 * @param object $row Queue row.
	 * @return array
	 */
	public static function envelope( $row ) {
		$decoded = json_decode( isset( $row->envelope ) ? $row->envelope : '', true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Record a failed attempt and schedule the next one.
	 *
	 * @param object $row         Queue row.
	 * @param string $error       Failure detail.
	 * @param int    $retry_after Seconds Send4 asked us to wait; the longer of this and the backoff wins.
	 * @return string The row's new status: pending, or failed once the attempts are used up.
	 */
	public static function record_failure( $row, $error, $retry_after = 0 ) {
		global $wpdb;

		$attempts = (int) $row->attempts + 1;
		$status   = $attempts >= self::MAX_ATTEMPTS ? self::STATUS_FAILED : self::STATUS_PENDING;
		$delay    = max( self::delay_for_attempt( $attempts ), (int) $retry_after );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'attempts'        => $attempts,
				'status'          => $status,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
				'last_error'      => (string) $error,
			),
			array( 'id' => (int) $row->id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return $status;
	}

	/**
	 * Flag a row that will never succeed as it stands.
	 *
	 * @param object $row   Queue row.
	 * @param string $error Failure detail.
	 * @return void
	 */
	public static function record_permanent_failure( $row, $error ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_PERMANENT,
				'attempts'   => (int) $row->attempts + 1,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_error' => (string) $error,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remove a row that has been delivered.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete every row. Used by the dashboard's "clear" action.
	 *
	 * @param string|null $status Limit to one status, or null for all.
	 * @return void
	 */
	public static function purge( $status = null ) {
		global $wpdb;

		$table = self::table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status = %s', $table, $status ) );
	}

	/**
	 * Delete rows in the given statuses older than N days.
	 *
	 * @param int      $days     Age in days.
	 * @param string[] $statuses Statuses to sweep.
	 * @return int Rows removed.
	 */
	public static function purge_older_than( $days, array $statuses ) {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return 0;
		}

		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
		$deleted = 0;

		// One statement per status rather than an IN list built at run time,
		// so every query is a fixed string with placeholders. There are at
		// most three statuses, and a sweep need not be atomic.
		foreach ( array_unique( array_map( 'strval', $statuses ) ) as $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted += (int) $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE status = %s AND updated_at < %s', $table, $status, $cutoff )
			);
		}

		return $deleted;
	}

	/**
	 * Delete pending rows nobody is working any more.
	 *
	 * A pending row normally ends as delivered or failed within retry_span(),
	 * but not on a site that was disconnected (process_queue() sends nothing
	 * without credentials) or whose cron stopped: those rows would otherwise
	 * wait for ever, and send a week-old order event the day the store reconnects.
	 *
	 * Both timestamps must be past the cutoff. created_at alone would delete a
	 * row an operator requeued yesterday; updated_at is what requeue and every
	 * attempt touch, and it can never be older than created_at.
	 *
	 * @param int $seconds Age in seconds.
	 * @return int Rows removed.
	 */
	public static function purge_stale_pending( $seconds ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND created_at < %s AND updated_at < %s',
				$table,
				self::STATUS_PENDING,
				$cutoff,
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/**
	 * Seconds from a row's first failure to its last scheduled attempt.
	 *
	 * The first failure schedules attempt 2 after delay_for_attempt(1), and so
	 * on until attempt MAX_ATTEMPTS: about a day and a third with the current
	 * schedule. Derived rather than written down so it follows the schedule.
	 *
	 * @return int
	 */
	public static function retry_span() {
		$span = 0;

		for ( $attempt = 1; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$span += self::delay_for_attempt( $attempt );
		}

		return $span;
	}

	/**
	 * Seconds to wait before attempt number N.
	 *
	 * @param int $attempts Attempts made so far.
	 * @return int
	 */
	public static function delay_for_attempt( $attempts ) {
		$index = max( 0, (int) $attempts - 1 );

		if ( $index >= count( self::$backoff ) ) {
			$index = count( self::$backoff ) - 1;
		}

		return self::$backoff[ $index ];
	}
}
