<?php
/**
 * Alerts — turns detected problems into notifications.
 *
 * Listens for the decoupled `cronvitals_job_problem` action fired by the
 * Monitor and delivers:
 *   - An email to the configured alert address, rate-limited to at most one
 *     message per job per hour so a flapping job can't spam.
 *   - A dismissible admin dashboard notice, per job, that reappears if the
 *     same job fails again after being dismissed.
 *
 * Additional notification channels (Slack, generic webhook, weekly summary)
 * live in the premium-only CronVitals_Pro class and attach via the
 * `cronvitals_problem_notified` filter, so the free build ships none of that
 * code.
 *
 * @package CronVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CronVitals_Alerts.
 */
class CronVitals_Alerts {

	/** @var CronVitals_Logger */
	protected $logger;

	/** @var CronVitals_License */
	protected $license;

	/**
	 * Option key storing the current per-job problem state (for the notice).
	 */
	const OPTION_PROBLEMS = 'cronvitals_active_problems';

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'cronvitals_job_problem', array( $this, 'handle_problem' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'render_dashboard_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_dismiss' ) );
	}

	/**
	 * Respond to a detected job problem.
	 *
	 * @param string $hook    Hook name.
	 * @param string $status  One of the Logger STATUS_* constants.
	 * @param array  $context Extra data: scheduled_time, overdue_seconds, message.
	 * @return void
	 */
	public function handle_problem( $hook, $status, $context = array() ) {
		$hook    = (string) $hook;
		$context = is_array( $context ) ? $context : array();

		// Always record for the dashboard notice (not rate-limited — it is
		// idempotent state, not a message).
		$this->record_problem( $hook, $status, $context );

		if ( $this->is_rate_limited( $hook ) ) {
			return;
		}

		$sent_any = $this->send_email( $hook, $status, $context );

		/**
		 * Extension point for additional notification channels. Premium channels
		 * (Slack, generic webhook) attach here via CronVitals_Pro and return true
		 * if they dispatched, so the shared rate-limit window opens only when a
		 * message was actually sent. The free build has no listener.
		 *
		 * @param bool   $sent_any Whether a channel has sent so far.
		 * @param string $hook     Hook name.
		 * @param string $status   Status.
		 * @param array  $context  Context.
		 */
		$sent_any = (bool) apply_filters( 'cronvitals_problem_notified', $sent_any, $hook, $status, $context );

		if ( $sent_any ) {
			$this->mark_rate_limited( $hook );
		}
	}

	/**
	 * Send the email alert for a problem.
	 *
	 * @param string $hook    Hook name.
	 * @param string $status  Status.
	 * @param array  $context Context.
	 * @return bool Whether wp_mail accepted the message.
	 */
	protected function send_email( $hook, $status, $context ) {
		$to = $this->alert_email();
		if ( empty( $to ) ) {
			return false;
		}

		$site       = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$status_lbl = ( CronVitals_Logger::STATUS_FAILED === $status )
			? __( 'failed', 'cronvitals' )
			: __( 'missed its scheduled run', 'cronvitals' );

		$subject = sprintf(
			/* translators: 1: site name, 2: cron hook name. */
			__( '[%1$s] CronVitals alert: %2$s', 'cronvitals' ),
			$site,
			sanitize_text_field( $hook ) // Hook comes from third-party code; strip CR/LF to prevent header injection.
		);

		$scheduled = isset( $context['scheduled_time'] ) ? $context['scheduled_time'] : null;
		$overdue   = $this->overdue_string( $context );

		$lines = array();
		/* translators: 1: cron hook name, 2: status phrase (failed / missed its scheduled run). */
		$lines[] = sprintf( __( 'The scheduled job "%1$s" %2$s.', 'cronvitals' ), $hook, $status_lbl );
		$lines[] = '';
		/* translators: %s: hook name. */
		$lines[] = sprintf( __( 'Hook: %s', 'cronvitals' ), $hook );
		$lines[] = sprintf(
			/* translators: %s: formatted scheduled date/time. */
			__( 'Scheduled time: %s', 'cronvitals' ),
			$scheduled ? $this->format_time( $scheduled ) : __( 'unknown', 'cronvitals' )
		);

		if ( $overdue ) {
			/* translators: %s: human-readable duration, e.g. "20 mins". */
			$lines[] = sprintf( __( 'Overdue by: %s', 'cronvitals' ), $overdue );
		}

		if ( ! empty( $context['message'] ) ) {
			/* translators: %s: error detail. */
			$lines[] = sprintf( __( 'Details: %s', 'cronvitals' ), $context['message'] );
		}

		$lines[] = '';
		$lines[] = __( 'Manage monitored jobs:', 'cronvitals' );
		$lines[] = admin_url( 'tools.php?page=cronvitals' );

		$body = implode( "\n", $lines );

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Resolve the alert email, falling back to the admin address. Public so the
	 * premium CronVitals_Pro class can reuse it.
	 *
	 * @return string
	 */
	public function alert_email() {
		$email = CronVitals::get_setting( 'alert_email' );
		if ( ! is_email( $email ) ) {
			$email = get_option( 'admin_email' );
		}
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Whether the given hook is currently within its cool-down window.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	protected function is_rate_limited( $hook ) {
		return (bool) get_transient( $this->rate_limit_key( $hook ) );
	}

	/**
	 * Start the cool-down window for a hook (one hour).
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	protected function mark_rate_limited( $hook ) {
		set_transient( $this->rate_limit_key( $hook ), time(), HOUR_IN_SECONDS );
	}

	/**
	 * Transient key for a hook's rate limit. Hashed to stay within the key
	 * length limit regardless of hook name.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	protected function rate_limit_key( $hook ) {
		return 'cronvitals_alert_' . md5( $hook );
	}

	/**
	 * Record/refresh a job's problem state for the dashboard notice.
	 *
	 * @param string $hook    Hook name.
	 * @param string $status  Status.
	 * @param array  $context Context.
	 * @return void
	 */
	protected function record_problem( $hook, $status, $context ) {
		$problems = $this->get_problems();

		$problems[ $hook ] = array(
			'status'         => $status,
			'scheduled_time' => isset( $context['scheduled_time'] ) ? $context['scheduled_time'] : null,
			'message'        => isset( $context['message'] ) ? $context['message'] : '',
			'time'           => time(),
		);

		// Guard against unbounded growth: keep the 100 most-recent problems.
		if ( count( $problems ) > 100 ) {
			uasort(
				$problems,
				static function ( $a, $b ) {
					return $b['time'] <=> $a['time'];
				}
			);
			$problems = array_slice( $problems, 0, 100, true );
		}

		update_option( self::OPTION_PROBLEMS, $problems, false );
	}

	/**
	 * Get the raw problem-state map.
	 *
	 * @return array
	 */
	protected function get_problems() {
		$problems = get_option( self::OPTION_PROBLEMS, array() );
		return is_array( $problems ) ? $problems : array();
	}

	/**
	 * Problems that should currently be shown (not dismissed since they last
	 * occurred). A fresh failure after a dismissal reappears because its
	 * `time` moves past the dismissal timestamp.
	 *
	 * @return array Map of hook => problem data.
	 */
	public function get_visible_problems() {
		$problems  = $this->get_problems();
		$dismissed = get_option( CRONVITALS_OPTION_DISMISSED, array() );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();

		$visible = array();
		foreach ( $problems as $hook => $data ) {
			$dismissed_at = isset( $dismissed[ $hook ] ) ? (int) $dismissed[ $hook ] : 0;
			if ( empty( $data['time'] ) || $data['time'] > $dismissed_at ) {
				$visible[ $hook ] = $data;
			}
		}

		return $visible;
	}

	/**
	 * Render the admin dashboard notice for failed/missed jobs.
	 *
	 * @return void
	 */
	public function render_dashboard_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$visible = $this->get_visible_problems();
		if ( empty( $visible ) ) {
			return;
		}

		echo '<div class="notice notice-error cronvitals-notice">';
		echo '<p><strong>' . esc_html__( 'CronVitals detected problems with scheduled jobs:', 'cronvitals' ) . '</strong></p>';
		echo '<ul style="list-style:disc;margin-left:20px;">';

		foreach ( $visible as $hook => $data ) {
			$status = ( CronVitals_Logger::STATUS_FAILED === $data['status'] )
				? esc_html__( 'failed', 'cronvitals' )
				: esc_html__( 'missed', 'cronvitals' );

			$dismiss_url = wp_nonce_url(
				add_query_arg(
					array( 'cronvitals_dismiss' => rawurlencode( $hook ) ),
					admin_url()
				),
				'cronvitals_dismiss_' . $hook
			);

			echo '<li>';
			printf(
				/* translators: 1: cron hook name, 2: status word (failed/missed). */
				esc_html__( '%1$s — %2$s', 'cronvitals' ),
				'<code>' . esc_html( $hook ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $status )
			);
			echo ' <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'cronvitals' ) . '</a>';
			echo '</li>';
		}

		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=cronvitals' ) ) . '">' . esc_html__( 'View CronVitals', 'cronvitals' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Handle a per-job dismissal link click.
	 *
	 * @return void
	 */
	public function maybe_handle_dismiss() {
		if ( empty( $_GET['cronvitals_dismiss'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hook = sanitize_text_field( wp_unslash( $_GET['cronvitals_dismiss'] ) );

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cronvitals_dismiss_' . $hook ) ) {
			return;
		}

		$dismissed = get_option( CRONVITALS_OPTION_DISMISSED, array() );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();
		$dismissed[ $hook ] = time();
		update_option( CRONVITALS_OPTION_DISMISSED, $dismissed, false );

		wp_safe_redirect( remove_query_arg( array( 'cronvitals_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Format a timestamp in the site's timezone and date/time format. Public so
	 * the premium CronVitals_Pro class can reuse it.
	 *
	 * @param int|string $time Unix timestamp or datetime string.
	 * @return string
	 */
	public function format_time( $time ) {
		$timestamp = is_numeric( $time ) ? (int) $time : strtotime( (string) $time );
		if ( ! $timestamp ) {
			return (string) $time;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, $timestamp );
	}

	/**
	 * Produce a human-readable "overdue by" string from context. Public so the
	 * premium CronVitals_Pro class can reuse it.
	 *
	 * @param array $context Context.
	 * @return string Empty string if not determinable.
	 */
	public function overdue_string( $context ) {
		if ( ! empty( $context['overdue_seconds'] ) ) {
			$now = time();
			return human_time_diff( $now - (int) $context['overdue_seconds'], $now );
		}

		if ( ! empty( $context['scheduled_time'] ) ) {
			$scheduled = is_numeric( $context['scheduled_time'] )
				? (int) $context['scheduled_time']
				: strtotime( (string) $context['scheduled_time'] );

			if ( $scheduled && $scheduled < time() ) {
				return human_time_diff( $scheduled, time() );
			}
		}

		return '';
	}
}
