<?php
/**
 * Monitor — the core of CronVitals.
 *
 * Responsibilities:
 *  - Enumerate every registered WP-Cron event (from the cron array).
 *  - Wrap each monitored hook to record when it runs, how long it took, and
 *    whether it succeeded or failed (fatals included, via a shutdown guard).
 *  - On a recurring scan, detect events that have blown past their scheduled
 *    time by more than the configured threshold and flag them as "missed".
 *  - Fire a decoupled `cronvitals_job_problem` action so the Alerts component
 *    can notify the admin without the Monitor knowing anything about email.
 *
 * Everything here is defensive: it never assumes WP-Cron is enabled. If
 * DISABLE_WP_CRON is set or a system cron drives wp-cron.php, the scan still
 * runs whenever wp-cron.php is invoked and detection still works.
 *
 * @package CronVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CronVitals_Monitor.
 */
class CronVitals_Monitor {

	/** @var CronVitals_Logger */
	protected $logger;

	/** @var CronVitals_License */
	protected $license;

	/** @var string[]|null */
	protected $monitored_hooks = null;

	/**
	 * Map of hook => scheduled timestamp for the occurrence due this request.
	 * Captured at load, before WP-Cron reschedules/removes the event.
	 *
	 * @var array<string,int>
	 */
	protected $due_map = array();

	/**
	 * Stack of runs currently in progress, innermost last.
	 *
	 * A monitored hook's callback can fire another monitored hook, so this has
	 * to nest: with a single slot the inner run would overwrite the outer one
	 * and the outer job would never be logged. Also read by the shutdown guard
	 * to detect a fatal that killed monitored callbacks mid-run.
	 *
	 * @var array[]
	 */
	protected $run_stack = array();

	/** @var bool */
	protected $shutdown_registered = false;

	/** @var bool True while a manual "Run now" is executing. */
	protected $manual_run = false;

	/**
	 * Constructor.
	 *
	 * @param CronVitals_Logger  $logger  Logger instance.
	 * @param CronVitals_License $license License instance.
	 */
	public function __construct( CronVitals_Logger $logger, CronVitals_License $license ) {
		$this->logger  = $logger;
		$this->license = $license;
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( CRONVITALS_CRON_SCAN, array( $this, 'scan' ) );

		// Attach run-tracking to monitored hooks. Done late enough that the
		// cron array is populated, but before wp-cron.php fires due events.
		//
		// Only worth doing where cron callbacks actually execute. An ordinary
		// front-end request calls wp_cron(), but that only *spawns* wp-cron.php
		// as a separate request — the callbacks never run here, so attaching
		// meant unserialising the cron array and registering a pair of hooks
		// per monitored job on every page view, for nothing.
		if ( $this->is_cron_context() ) {
			add_action( 'wp_loaded', array( $this, 'attach_run_tracking' ), 1 );
		}
	}

	/**
	 * Whether cron callbacks can fire in this request.
	 *
	 * ALTERNATE_WP_CRON is included unconditionally: it runs cron inline during
	 * a front-end request, so we cannot tell from the outside whether this is
	 * the one, and attaching is the safe answer.
	 *
	 * @return bool
	 */
	protected function is_cron_context() {
		if ( wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return true;
		}

		return false;
	}

	/**
	 * Return every registered WP-Cron event in a normalized shape.
	 *
	 * @return array List of event arrays: hook, args, args_hash, schedule,
	 *               interval, next_run (timestamp), all_runs (timestamps).
	 */
	public function get_events() {
		$cron = _get_cron_array();
		if ( empty( $cron ) || ! is_array( $cron ) ) {
			return array();
		}

		$events = array();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $instances ) {
				if ( ! is_array( $instances ) ) {
					continue;
				}

				foreach ( $instances as $sig => $data ) {
					$args     = isset( $data['args'] ) ? (array) $data['args'] : array();
					$schedule = isset( $data['schedule'] ) && $data['schedule'] ? $data['schedule'] : '';
					$interval = isset( $data['interval'] ) ? (int) $data['interval'] : 0;

					$key = $hook . '|' . $sig;

					if ( isset( $events[ $key ] ) ) {
						// Same recurring event scheduled at multiple future
						// timestamps — keep the earliest as next_run.
						$events[ $key ]['all_runs'][] = (int) $timestamp;
						if ( $timestamp < $events[ $key ]['next_run'] ) {
							$events[ $key ]['next_run'] = (int) $timestamp;
						}
						continue;
					}

					$events[ $key ] = array(
						'hook'      => $hook,
						'args'      => $args,
						'args_hash' => md5( maybe_serialize( $args ) ),
						'schedule'  => $schedule,
						'interval'  => $interval,
						'next_run'  => (int) $timestamp,
						'all_runs'  => array( (int) $timestamp ),
					);
				}
			}
		}

		return array_values( $events );
	}

	/**
	 * Build the admin-facing job list: one row per hook with derived status.
	 *
	 * @return array List of job arrays consumed by the jobs list table.
	 */
	public function get_jobs() {
		$events    = $this->get_events();
		$threshold = $this->threshold_seconds();
		$now       = time();
		$jobs      = array();

		$by_hook = array();
		foreach ( $events as $event ) {
			$hook = $event['hook'];
			if ( ! isset( $by_hook[ $hook ] ) ) {
				$by_hook[ $hook ] = $event;
			} elseif ( $event['next_run'] < $by_hook[ $hook ]['next_run'] ) {
				$by_hook[ $hook ]['next_run'] = $event['next_run'];
			}
		}

		foreach ( $by_hook as $hook => $event ) {
			$last     = $this->logger->get_last_run( $hook );
			$last_run = $last && $last->run_time ? $last->run_time : null;

			$jobs[] = array(
				'hook'       => $hook,
				'schedule'   => $event['schedule'] ? $event['schedule'] : 'single',
				'interval'   => $event['interval'],
				'next_run'   => $event['next_run'],
				'last_run'   => $last_run,
				'last_status'=> $last ? $last->status : '',
				'monitored'  => $this->is_monitored( $hook ),
				'status'     => $this->job_status( $hook, $event['next_run'], $now, $threshold, $last ),
			);
		}

		// Stable, predictable ordering for the jobs list.
		usort(
			$jobs,
			static function ( $a, $b ) {
				return strcmp( $a['hook'], $b['hook'] );
			}
		);

		return $jobs;
	}

	/**
	 * Derive a health status for a hook.
	 *
	 * @param string      $hook      Hook name.
	 * @param int         $next_run  Next scheduled timestamp.
	 * @param int         $now       Current timestamp.
	 * @param int         $threshold Missed threshold in seconds.
	 * @param object|null $last      Last log row for the hook.
	 * @return string One of healthy|missed|failed|unmonitored.
	 */
	protected function job_status( $hook, $next_run, $now, $threshold, $last ) {
		if ( ! $this->is_monitored( $hook ) ) {
			return 'unmonitored';
		}

		// A currently-overdue event outranks stale log history.
		if ( $next_run && $next_run < ( $now - $threshold ) ) {
			return CronVitals_Logger::STATUS_MISSED;
		}

		if ( $last && in_array( $last->status, array( CronVitals_Logger::STATUS_FAILED, CronVitals_Logger::STATUS_MISSED ), true ) ) {
			return $last->status;
		}

		return 'healthy';
	}

	/**
	 * The set of hooks we actively monitor, honoring the per-hook opt-out and
	 * the global pause switch.
	 *
	 * @return string[]
	 */
	public function get_monitored_hooks() {
		if ( null !== $this->monitored_hooks ) {
			return $this->monitored_hooks;
		}

		if ( CronVitals::get_setting( 'monitoring_paused' ) ) {
			$this->monitored_hooks = array();
			return $this->monitored_hooks;
		}

		$disabled = (array) CronVitals::get_setting( 'disabled_hooks', array() );

		$hooks = array();
		foreach ( $this->get_events() as $event ) {
			$hooks[ $event['hook'] ] = true;
		}
		$hooks = array_keys( $hooks );

		// CronVitals's own plumbing. The heartbeat belongs here too: monitoring
		// it means alerting about the very thing whose job is to detect misses.
		$own = array(
			CRONVITALS_CRON_SCAN,
			CRONVITALS_CRON_LICENSE,
			CRONVITALS_CRON_SUMMARY,
			CRONVITALS_CRON_HEARTBEAT,
		);
		$hooks = array_values(
			array_filter(
				$hooks,
				static function ( $hook ) use ( $disabled, $own ) {
					return ! in_array( $hook, $disabled, true ) && ! in_array( $hook, $own, true );
				}
			)
		);

		// Order by impact so the most consequential jobs surface first.
		$hooks = $this->sort_by_priority( $hooks );

		$this->monitored_hooks = $hooks;
		return $this->monitored_hooks;
	}

	/**
	 * Order hooks by monitoring impact (most important first).
	 *
	 * The default ranking front-loads WordPress core hooks whose failure has
	 * real, user-visible consequences — missed scheduled posts, skipped
	 * security/plugin updates, pings, privacy exports — ahead of low-stakes
	 * housekeeping. Unknown hooks (custom/plugin cron) fall in the middle and
	 * are ordered alphabetically for determinism.
	 *
	 * @param string[] $hooks Candidate hook names.
	 * @return string[] Sorted hook names.
	 */
	protected function sort_by_priority( array $hooks ) {
		/**
		 * Filter the hook priority list. Earlier entries sort to the top of the
		 * jobs view. Add your own high-value cron hooks (e.g.
		 * 'action_scheduler_run_queue', 'woocommerce_scheduled_sales').
		 *
		 * @param string[] $priority Ordered list of high-priority hook names.
		 */
		$priority = apply_filters(
			'cronvitals_hook_priority',
			array(
				'publish_future_post',              // Scheduled posts go live.
				'do_pings',                         // Outbound pingbacks.
				'wp_version_check',                 // Core update checks.
				'wp_update_plugins',                // Plugin update checks.
				'wp_update_themes',                 // Theme update checks.
				'wp_maybe_auto_update',             // Automatic background updates.
				'wp_scheduled_delete',              // Trash purge.
				'wp_scheduled_auto_draft_delete',   // Stale auto-drafts.
				'wp_privacy_delete_old_export_files', // GDPR export cleanup.
				'delete_expired_transients',        // Transient GC.
				'recovery_mode_clean_expired_keys', // Fatal-recovery keys.
				'wp_site_health_scheduled_check',   // Site Health.
				'wp_update_user_counts',            // Multisite user counts.
				'wp_delete_temp_updater_backups',   // Updater backups.
				'upgrader_scheduled_cleanup',       // Post-upgrade cleanup.
			)
		);

		// Map hook => rank. Known hooks keep their list position; unknown hooks
		// share a rank just past the known set so custom cron sorts after core
		// essentials but the two are still alphabetized among themselves.
		$rank    = array_flip( array_values( $priority ) );
		$unknown = count( $priority );

		usort(
			$hooks,
			static function ( $a, $b ) use ( $rank, $unknown ) {
				$ra = isset( $rank[ $a ] ) ? $rank[ $a ] : $unknown;
				$rb = isset( $rank[ $b ] ) ? $rank[ $b ] : $unknown;

				if ( $ra === $rb ) {
					return strcmp( $a, $b );
				}
				return ( $ra < $rb ) ? -1 : 1;
			}
		);

		return $hooks;
	}

	/**
	 * Whether a given hook is currently being monitored.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public function is_monitored( $hook ) {
		return in_array( $hook, $this->get_monitored_hooks(), true );
	}

	/**
	 * Attach start/finish tracking callbacks to every monitored hook.
	 *
	 * Runs on wp_loaded (priority 1) so registrations are in place before
	 * wp-cron.php fires due events later in the request.
	 *
	 * @return void
	 */
	public function attach_run_tracking() {
		// Snapshot which occurrence of each hook is due right now, before the
		// cron runner reschedules or removes it.
		$now = time();
		foreach ( $this->get_events() as $event ) {
			if ( $event['next_run'] <= $now ) {
				$hook = $event['hook'];
				if ( ! isset( $this->due_map[ $hook ] ) || $event['next_run'] < $this->due_map[ $hook ] ) {
					$this->due_map[ $hook ] = $event['next_run'];
				}
			}
		}

		foreach ( $this->get_monitored_hooks() as $hook ) {
			add_action( $hook, array( $this, 'on_run_start' ), PHP_INT_MIN, 20 );
			add_action( $hook, array( $this, 'on_run_finish' ), PHP_INT_MAX, 20 );
		}
	}

	/**
	 * Marks the start of a monitored hook's execution.
	 *
	 * @return void
	 */
	public function on_run_start() {
		// A manual "Run now" logs its own row; skip passive tracking for it.
		if ( $this->manual_run ) {
			return;
		}

		// current_filter() is the hook name we are running inside.
		$hook = current_filter();

		$args      = func_get_args();
		$args_hash = md5( maybe_serialize( $args ) );

		$this->run_stack[] = array(
			'hook'      => $hook,
			'args_hash' => $args_hash,
			'start'     => microtime( true ),
			'scheduled' => isset( $this->due_map[ $hook ] ) ? $this->due_map[ $hook ] : null,
		);

		$this->register_shutdown_guard();
	}

	/**
	 * Marks a monitored hook's successful completion and logs the run.
	 *
	 * @return void
	 */
	public function on_run_finish() {
		if ( $this->manual_run ) {
			return;
		}

		$hook = current_filter();

		// Only log if this finish pairs with a run we started. The innermost
		// open run is the one completing; anything else means we never saw the
		// matching start, so there is nothing to close.
		$open = end( $this->run_stack );
		if ( false === $open || $open['hook'] !== $hook ) {
			return;
		}

		$run      = array_pop( $this->run_stack );
		$duration = microtime( true ) - $run['start'];

		$this->logger->log(
			array(
				'hook'           => $hook,
				'args_hash'      => $run['args_hash'],
				'schedule'       => $this->schedule_for( $hook ),
				'scheduled_time' => $run['scheduled'],
				'run_time'       => time(),
				'duration'       => $duration,
				'status'         => CronVitals_Logger::STATUS_SUCCESS,
				'overdue_seconds'=> $this->overdue_for( $run['scheduled'] ),
			)
		);
	}

	/**
	 * Shutdown guard: if a monitored run was in progress and never finished, a
	 * fatal error killed it. Record it as a failed run and alert.
	 *
	 * @return void
	 */
	public function on_shutdown() {
		if ( empty( $this->run_stack ) ) {
			return;
		}

		$error = error_get_last();

		// Only treat as a failure if a fatal-class error is present. Otherwise
		// the request may simply have ended (e.g. redirect) mid-hook.
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		$is_fatal    = $error && in_array( $error['type'], $fatal_types, true );

		if ( ! $is_fatal ) {
			$this->run_stack = array();
			return;
		}

		$message = sprintf(
			'%s in %s on line %d',
			isset( $error['message'] ) ? $error['message'] : 'Fatal error',
			isset( $error['file'] ) ? $error['file'] : '?',
			isset( $error['line'] ) ? (int) $error['line'] : 0
		);

		// A fatal takes down every open frame, not just the innermost one, so
		// unwind the whole stack. Innermost first — that is where the fatal
		// actually happened, and it reads correctly in the log.
		$stack           = $this->run_stack;
		$this->run_stack = array();

		foreach ( array_reverse( $stack ) as $run ) {
			$this->logger->log(
				array(
					'hook'           => $run['hook'],
					'args_hash'      => $run['args_hash'],
					'schedule'       => $this->schedule_for( $run['hook'] ),
					'scheduled_time' => $run['scheduled'],
					'run_time'       => time(),
					'duration'       => microtime( true ) - $run['start'],
					'status'         => CronVitals_Logger::STATUS_FAILED,
					'message'        => $message,
					'overdue_seconds'=> $this->overdue_for( $run['scheduled'] ),
				)
			);

			// Notify — but never let alert code raise its own fatal in shutdown.
			try {
				do_action(
					'cronvitals_job_problem',
					$run['hook'],
					CronVitals_Logger::STATUS_FAILED,
					array(
						'scheduled_time' => $run['scheduled'],
						'message'        => $message,
					)
				);
			} catch ( \Throwable $e ) {
				// Swallow — we are already in a shutdown handler.
			}
		}
	}

	/**
	 * Register the shutdown guard exactly once per request.
	 *
	 * @return void
	 */
	protected function register_shutdown_guard() {
		if ( $this->shutdown_registered ) {
			return;
		}
		register_shutdown_function( array( $this, 'on_shutdown' ) );
		$this->shutdown_registered = true;
	}

	/**
	 * Execute a cron hook immediately (the "Run now" action) and log the run.
	 *
	 * Runs the hook's callbacks in the current request with the same args the
	 * scheduled event carries. Passive run-tracking is suppressed so exactly one
	 * row is logged, marked as a manual run. Works whether or not the hook is
	 * currently monitored.
	 *
	 * @param string $hook Hook name.
	 * @return bool True once the run has been logged.
	 */
	public function run_now( $hook ) {
		$hook = (string) $hook;
		$args = $this->args_for( $hook );

		$this->manual_run = true;
		$start            = microtime( true );
		$status           = CronVitals_Logger::STATUS_SUCCESS;
		$detail           = '';

		try {
			do_action_ref_array( $hook, $args );
		} catch ( \Throwable $e ) {
			$status = CronVitals_Logger::STATUS_FAILED;
			$detail = $e->getMessage();
		}

		$this->manual_run = false;

		$this->logger->log(
			array(
				'hook'      => $hook,
				'args_hash' => md5( maybe_serialize( $args ) ),
				'schedule'  => $this->schedule_for( $hook ),
				'run_time'  => time(),
				'duration'  => microtime( true ) - $start,
				'status'    => $status,
				'message'   => '' !== $detail ? 'Manual run: ' . $detail : __( 'Manual run.', 'cronvitals' ),
			)
		);

		return true;
	}

	/**
	 * Args registered for a hook's scheduled event (for run_now).
	 *
	 * @param string $hook Hook name.
	 * @return array
	 */
	protected function args_for( $hook ) {
		foreach ( $this->get_events() as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event['args'];
			}
		}
		return array();
	}

	/**
	 * Scan for missed jobs, log them, fire alerts, and prune old logs.
	 *
	 * Bound to CRONVITALS_CRON_SCAN (every 5 minutes) but also safe to call
	 * directly (e.g. a "scan now" admin action).
	 *
	 * @return array List of missed job descriptors detected this scan.
	 */
	public function scan() {
		$missed = array();

		// Detection is skipped while paused, but pruning still runs below.
		if ( ! CronVitals::get_setting( 'monitoring_paused' ) ) {
			$threshold = $this->threshold_seconds();
			$now       = time();

			foreach ( $this->get_events() as $event ) {
				$hook = $event['hook'];

				if ( ! $this->is_monitored( $hook ) ) {
					continue;
				}

				$scheduled = $event['next_run'];
				$overdue   = $now - $scheduled;

				// Not yet past the grace window — healthy.
				if ( $overdue < $threshold ) {
					continue;
				}

				// De-dupe: only log/alert once per scheduled occurrence.
				if ( $this->logger->has_log( $hook, $scheduled, CronVitals_Logger::STATUS_MISSED ) ) {
					continue;
				}

				$this->logger->log(
					array(
						'hook'           => $hook,
						'args_hash'      => $event['args_hash'],
						'schedule'       => $event['schedule'] ? $event['schedule'] : 'single',
						'scheduled_time' => $scheduled,
						'run_time'       => null,
						'status'         => CronVitals_Logger::STATUS_MISSED,
						'message'        => sprintf( 'Overdue by %s.', human_time_diff( $scheduled, $now ) ),
						'overdue_seconds'=> $overdue,
					)
				);

				$context = array(
					'scheduled_time'  => $scheduled,
					'overdue_seconds' => $overdue,
				);

				do_action( 'cronvitals_job_problem', $hook, CronVitals_Logger::STATUS_MISSED, $context );

				$missed[] = array( 'hook' => $hook ) + $context;
			}
		}

		// Retention pruning always runs, regardless of pause state.
		$this->logger->prune( CRONVITALS_LOG_DAYS );

		return $missed;
	}

	/**
	 * Missed threshold in seconds, derived from the settings (minutes).
	 *
	 * @return int
	 */
	public function threshold_seconds() {
		$minutes = (int) CronVitals::get_setting( 'missed_threshold', 15 );
		if ( $minutes < 1 ) {
			$minutes = 15;
		}
		return $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * Best-effort recurrence label for a hook from the current cron array.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	protected function schedule_for( $hook ) {
		foreach ( $this->get_events() as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event['schedule'] ? $event['schedule'] : 'single';
			}
		}
		return '';
	}

	/**
	 * Compute how overdue a run was relative to its scheduled time.
	 *
	 * @param int|null $scheduled Scheduled timestamp.
	 * @return int|null Seconds overdue (>= 0), or null if unknown.
	 */
	protected function overdue_for( $scheduled ) {
		if ( empty( $scheduled ) ) {
			return null;
		}
		return max( 0, time() - (int) $scheduled );
	}
}
