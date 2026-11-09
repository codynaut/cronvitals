<?php
/**
 * Logger — owns the custom log table and all reads/writes to it.
 *
 * Every cron run CronVitals observes is recorded here: which hook, when it was
 * scheduled, when it actually ran, and whether it succeeded, was missed, or
 * failed. All queries use $wpdb prepared statements.
 *
 * @package CronVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CronVitals_Logger.
 */
class CronVitals_Logger {

	/**
	 * Recognized run statuses.
	 */
	const STATUS_SUCCESS = 'success';
	const STATUS_MISSED  = 'missed';
	const STATUS_FAILED  = 'failed';
	const STATUS_RUNNING = 'running';

	/**
	 * Fully-prefixed log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . CRONVITALS_LOG_TABLE;
	}

	/**
	 * Create (or update) the log table via dbDelta.
	 *
	 * Called on activation. Safe to run repeatedly — dbDelta only applies the
	 * diff. Stores the schema version so future upgrades can gate migrations.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, lowercase types,
		// one field per line, and a KEY on each column we filter/sort by.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hook varchar(191) NOT NULL DEFAULT '',
			args_hash varchar(32) NOT NULL DEFAULT '',
			schedule varchar(100) NOT NULL DEFAULT '',
			scheduled_time datetime DEFAULT NULL,
			run_time datetime DEFAULT NULL,
			duration float DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'success',
			message text NULL,
			overdue_seconds bigint(20) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY hook (hook),
			KEY status (status),
			KEY scheduled_time (scheduled_time),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// The table may have just come into existence, so drop any cached
		// "missing" answer taken earlier in this request.
		unset( self::$table_exists_cache[ $table_name ] );

		update_option( CRONVITALS_OPTION_DB_VERSION, CRONVITALS_DB_VERSION );
	}

	/**
	 * Per-request cache of the table-existence check, keyed by full table name.
	 *
	 * Keying by name rather than using a plain boolean matters on multisite:
	 * switch_to_blog() changes $wpdb->prefix, so each site gets its own entry
	 * instead of inheriting the previous site's answer.
	 *
	 * @var array<string,bool>
	 */
	protected static $table_exists_cache = array();

	/**
	 * Whether the log table currently exists.
	 *
	 * Guards every read/write so the plugin degrades gracefully rather than
	 * throwing DB errors if the table is missing (e.g. activation was skipped).
	 * Cached per request — this used to fire a SHOW TABLES ahead of every single
	 * read and write, which on the Logs screen alone meant half a dozen wasted
	 * round trips per page load.
	 *
	 * @return bool
	 */
	public function table_exists() {
		global $wpdb;

		$table_name = self::table_name();

		if ( isset( self::$table_exists_cache[ $table_name ] ) ) {
			return self::$table_exists_cache[ $table_name ];
		}

		// esc_like matters here: `_` is a LIKE wildcard, so an unescaped
		// wp_cronvitals_log can match a similarly-named neighbouring table.
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);

		self::$table_exists_cache[ $table_name ] = ( $found === $table_name );

		return self::$table_exists_cache[ $table_name ];
	}

	/**
	 * Insert a log row.
	 *
	 * @param array $data {
	 *     Row data. Unknown keys are ignored.
	 *
	 *     @type string      $hook            Cron hook name.
	 *     @type string      $args_hash       md5 of the event args (to disambiguate).
	 *     @type string      $schedule        Recurrence slug (e.g. 'hourly') or '' for single events.
	 *     @type string|int  $scheduled_time  When the event was due (datetime string or timestamp).
	 *     @type string|int  $run_time        When it actually ran.
	 *     @type float       $duration        Execution time in seconds.
	 *     @type string      $status          One of the STATUS_* constants.
	 *     @type string      $message         Optional detail (error text, etc.).
	 *     @type int         $overdue_seconds How late the run was, in seconds.
	 * }
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function log( array $data ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$row = array(
			'hook'            => isset( $data['hook'] ) ? substr( (string) $data['hook'], 0, 191 ) : '',
			'args_hash'       => isset( $data['args_hash'] ) ? substr( (string) $data['args_hash'], 0, 32 ) : '',
			'schedule'        => isset( $data['schedule'] ) ? substr( (string) $data['schedule'], 0, 100 ) : '',
			'scheduled_time'  => isset( $data['scheduled_time'] ) ? self::to_mysql_time( $data['scheduled_time'] ) : null,
			'run_time'        => isset( $data['run_time'] ) ? self::to_mysql_time( $data['run_time'] ) : null,
			'duration'        => isset( $data['duration'] ) ? (float) $data['duration'] : null,
			'status'          => isset( $data['status'] ) ? substr( (string) $data['status'], 0, 20 ) : self::STATUS_SUCCESS,
			'message'         => isset( $data['message'] ) ? (string) $data['message'] : null,
			'overdue_seconds' => isset( $data['overdue_seconds'] ) ? (int) $data['overdue_seconds'] : null,
			'created_at'      => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s' );

		$result = $wpdb->insert( self::table_name(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ( false === $result ) ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Fetch log rows, most recent first.
	 *
	 * @param array $args {
	 *     Optional query args.
	 *
	 *     @type string $hook     Restrict to a single hook.
	 *     @type string $status   Restrict to a single status.
	 *     @type string $search   LIKE match against hook and message.
	 *     @type int    $per_page Rows per page (default 20). Use 0 for no limit.
	 *     @type int    $page     1-based page number (default 1).
	 *     @type string $orderby  Column to sort by (whitelisted).
	 *     @type string $order    ASC or DESC (default DESC).
	 * }
	 * @return array Array of row objects.
	 */
	public function get_logs( array $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$defaults = array(
			'hook'     => '',
			'status'   => '',
			'search'   => '',
			'after_id' => 0,
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'id',
			'order'    => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		list( $where_sql, $where_values ) = $this->build_where( $args );

		$orderby = $this->sanitize_orderby( $args['orderby'] );
		$order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$table = self::table_name();
		$sql   = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order}";

		$per_page = (int) $args['per_page'];
		if ( $per_page > 0 ) {
			$page   = max( 1, (int) $args['page'] );
			$offset = ( $page - 1 ) * $per_page;
			$sql   .= ' LIMIT %d OFFSET %d';
			$where_values[] = $per_page;
			$where_values[] = $offset;
		}

		// $sql contains only plugin-controlled identifiers plus %s/%d
		// placeholders; all user values flow through $where_values.
		if ( ! empty( $where_values ) ) {
			$sql = $wpdb->prepare( $sql, $where_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Count log rows matching the given filter args (ignores pagination).
	 *
	 * @param array $args Same filter args as get_logs().
	 * @return int
	 */
	public function count_logs( array $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		list( $where_sql, $where_values ) = $this->build_where( $args );

		$table = self::table_name();
		$sql   = "SELECT COUNT(*) FROM {$table} {$where_sql}";

		if ( ! empty( $where_values ) ) {
			$sql = $wpdb->prepare( $sql, $where_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Row counts grouped by status across the whole table.
	 *
	 * The logs screen renders one filter link per status, each labelled with a
	 * count. Asking for those one at a time meant a COUNT(*) per link on top of
	 * the one the list itself needs — five full index scans per page view, on a
	 * table that holds a row per cron run. One grouped query answers all four.
	 *
	 * Deliberately unwindowed, unlike status_counts(): these counts label
	 * filters over the entire table, so they have to agree with the total the
	 * list table reports.
	 *
	 * @return array Map of status => count.
	 */
	public function count_by_status() {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$table = self::table_name();

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Get the most recent log row for a given hook (optionally by status).
	 *
	 * @param string $hook   Hook name.
	 * @param string $status Optional status filter.
	 * @return object|null
	 */
	public function get_last_run( $hook, $status = '' ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return null;
		}

		$table = self::table_name();

		if ( '' !== $status ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE hook = %s AND status = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hook,
				$status
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE hook = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hook
			);
		}

		return $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether a row already exists for a given hook + scheduled time + status.
	 *
	 * Used by the monitor to avoid logging the same missed occurrence twice on
	 * successive scans.
	 *
	 * @param string     $hook           Hook name.
	 * @param string|int $scheduled_time Scheduled time (datetime string or timestamp).
	 * @param string     $status         Status to match.
	 * @return bool
	 */
	public function has_log( $hook, $scheduled_time, $status ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE hook = %s AND scheduled_time = %s AND status = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hook,
				self::to_mysql_time( $scheduled_time ),
				$status
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ! empty( $found );
	}

	/**
	 * Delete log rows older than the retention window.
	 *
	 * Deletes in bounded batches rather than one unbounded statement. This
	 * plugin writes a row per cron run, and with Action Scheduler monitoring on
	 * a busy store that is easily six figures of rows over the retention
	 * window — a single DELETE across all of them locks the table and can time
	 * out the request it runs in. Anything left over is picked up by the next
	 * scan five minutes later, so the backlog still drains.
	 *
	 * @param int $days     Number of days to keep. Rows older than this are purged.
	 * @param int $max_rows Ceiling on rows removed in one call.
	 * @return int|false Rows deleted, or false on failure.
	 */
	public function prune( $days, $max_rows = 50000 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		$days = max( 1, (int) $days );

		// Cutoff computed in site time to match created_at (current_time).
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$table   = self::table_name();
		$batch   = 1000;
		$deleted = 0;

		do {
			$removed = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					$batch
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( false === $removed ) {
				return ( $deleted > 0 ) ? $deleted : false;
			}

			$deleted += (int) $removed;
		} while ( (int) $removed === $batch && $deleted < $max_rows );

		return $deleted;
	}

	/**
	 * Delete every row in the table (used by "clear logs" admin action).
	 *
	 * @return int|false
	 */
	public function clear() {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		$table = self::table_name();
		return $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Aggregate counts by status within a recent window — used by the weekly
	 * summary email and dashboard stats.
	 *
	 * @param int $days Look-back window in days.
	 * @return array Map of status => count.
	 */
	public function status_counts( $days = 7 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$days   = max( 1, (int) $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$table  = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Overview totals within a window: total runs, per-status counts, and the
	 * reliability as a percentage (0-100).
	 *
	 * Reliability counts successful runs against every expected occurrence —
	 * successes, failures, AND misses — because a missed run is a reliability
	 * failure, not a non-event. "Runs" still reports actual executions only.
	 *
	 * @param int $days Look-back window in days.
	 * @return array {
	 *     @type int   $total       Success + failed rows (actual executions).
	 *     @type int   $success     Successful runs.
	 *     @type int   $failed      Failed runs.
	 *     @type int   $missed      Missed occurrences.
	 *     @type float $reliability Successful runs / all expected occurrences.
	 * }
	 */
	public function overview_stats( $days = 7 ) {
		$counts  = $this->status_counts( $days );
		$success = isset( $counts[ self::STATUS_SUCCESS ] ) ? (int) $counts[ self::STATUS_SUCCESS ] : 0;
		$failed  = isset( $counts[ self::STATUS_FAILED ] ) ? (int) $counts[ self::STATUS_FAILED ] : 0;
		$missed  = isset( $counts[ self::STATUS_MISSED ] ) ? (int) $counts[ self::STATUS_MISSED ] : 0;

		$runs     = $success + $failed;
		$expected = $success + $failed + $missed;
		$rate     = $expected > 0 ? ( $success / $expected ) * 100 : 100.0;

		return array(
			'total'       => $runs,
			'success'     => $success,
			'failed'      => $failed,
			'missed'      => $missed,
			'reliability' => round( $rate, 1 ),
		);
	}

	/**
	 * Per-hook reliability breakdown within a window.
	 *
	 * @param int $days Look-back window in days.
	 * @return array List of row objects with per-hook aggregates.
	 */
	public function per_hook_stats( $days = 7 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$days   = max( 1, (int) $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$table  = self::table_name();

		$sql = $wpdb->prepare(
			"SELECT hook,
				SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS success,
				SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS failed,
				SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS missed,
				AVG(duration) AS avg_duration,
				MAX(run_time) AS last_run
			FROM {$table}
			WHERE created_at >= %s
			GROUP BY hook
			ORDER BY (failed + missed) DESC, hook ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::STATUS_SUCCESS,
			self::STATUS_FAILED,
			self::STATUS_MISSED,
			$cutoff
		);

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a WHERE clause and its bound values from filter args.
	 *
	 * @param array $args Filter args.
	 * @return array [ string $where_sql, array $values ].
	 */
	protected function build_where( array $args ) {
		$clauses = array();
		$values  = array();

		if ( ! empty( $args['hook'] ) ) {
			$clauses[] = 'hook = %s';
			$values[]  = $args['hook'];
		}

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $args['status'];
		}

		// Keyset pagination. Walking a large table with LIMIT/OFFSET can skip or
		// repeat rows when the retention prune deletes underneath the cursor;
		// anchoring on the last id seen cannot.
		if ( ! empty( $args['after_id'] ) ) {
			$clauses[] = 'id > %d';
			$values[]  = (int) $args['after_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$clauses[] = '( hook LIKE %s OR message LIKE %s )';
			$values[]  = $like;
			$values[]  = $like;
		}

		$where_sql = empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses );

		return array( $where_sql, $values );
	}

	/**
	 * Whitelist the orderby column to prevent SQL injection via sort params.
	 *
	 * @param string $orderby Requested column.
	 * @return string Safe column name.
	 */
	protected function sanitize_orderby( $orderby ) {
		$allowed = array( 'id', 'hook', 'schedule', 'scheduled_time', 'run_time', 'status', 'duration', 'created_at' );

		return in_array( $orderby, $allowed, true ) ? $orderby : 'id';
	}

	/**
	 * Normalize a time value (timestamp int or datetime string) to MySQL
	 * datetime in site time.
	 *
	 * @param string|int $time Timestamp or datetime string.
	 * @return string|null
	 */
	public static function to_mysql_time( $time ) {
		if ( empty( $time ) ) {
			return null;
		}

		if ( is_numeric( $time ) ) {
			// Treat integers as Unix timestamps; convert to site-local time.
			// get_date_from_gmt() is used rather than an offset arithmetic
			// because gmt_offset is a float (half-hour zones such as +5:30
			// truncate to 5 under an int cast) and because it honours the
			// site's timezone string, including DST.
			return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $time ), 'Y-m-d H:i:s' );
		}

		return (string) $time;
	}
}
