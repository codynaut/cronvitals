<?php
/**
 * Jobs list table — one row per registered WP-Cron hook.
 *
 * Shows the schedule, next/last run, derived health status, and a per-job
 * monitoring toggle. Data comes from the Monitor; this class only presents it.
 *
 * @package CronVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class CronVitals_Jobs_Table.
 */
class CronVitals_Jobs_Table extends WP_List_Table {

	/** @var CronVitals_Monitor */
	protected $monitor;

	/** @var CronVitals_License */
	protected $license;

	/**
	 * Constructor.
	 *
	 * @param CronVitals_Monitor $monitor Monitor instance.
	 * @param CronVitals_License $license License instance.
	 */
	public function __construct( $monitor, $license ) {
		$this->monitor = $monitor;
		$this->license = $license;

		parent::__construct(
			array(
				'singular' => 'cron_job',
				'plural'   => 'cron_jobs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'hook'      => __( 'Hook', 'cronvitals' ),
			'schedule'  => __( 'Schedule', 'cronvitals' ),
			'next_run'  => __( 'Next Run', 'cronvitals' ),
			'last_run'  => __( 'Last Run', 'cronvitals' ),
			'status'    => __( 'Status', 'cronvitals' ),
			'monitored' => __( 'Monitoring', 'cronvitals' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$jobs = $this->monitor->get_jobs();

		// Filter by the search term (hook, schedule, or status).
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$jobs   = array_values(
				array_filter(
					$jobs,
					static function ( $job ) use ( $needle ) {
						return false !== strpos( strtolower( $job['hook'] ), $needle )
							|| false !== strpos( strtolower( (string) $job['schedule'] ), $needle )
							|| false !== strpos( strtolower( (string) $job['status'] ), $needle );
					}
				)
			);
		}

		$per_page = 20;
		$current  = $this->get_pagenum();
		$total    = count( $jobs );

		$this->items = array_slice( $jobs, ( $current - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Hook column with the raw hook name.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_hook( $item ) {
		$hook = $item['hook'];
		$out  = '<code>' . esc_html( $hook ) . '</code>';

		// "Run now" — available for real jobs, not CronVitals's own plumbing.
		$own = array( CRONVITALS_CRON_SCAN, CRONVITALS_CRON_LICENSE, CRONVITALS_CRON_SUMMARY, CRONVITALS_CRON_HEARTBEAT );
		if ( ! in_array( $hook, $own, true ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'page'             => 'cronvitals',
						'tab'              => 'jobs',
						'cronvitals_action' => 'run_now',
						'hook'             => rawurlencode( $hook ),
					),
					admin_url( 'tools.php' )
				),
				'cronvitals_run_' . $hook
			);

			$out .= $this->row_actions(
				array(
					'run' => '<a href="' . esc_url( $url ) . '" class="cronvitals-confirm" data-confirm="' . esc_attr__( 'Run this job now? A heavy job may take a moment to finish.', 'cronvitals' ) . '">' . esc_html__( 'Run now', 'cronvitals' ) . '</a>',
				)
			);
		}

		return $out;
	}

	/**
	 * Schedule column — recurrence label plus interval.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_schedule( $item ) {
		if ( 'single' === $item['schedule'] || '' === $item['schedule'] ) {
			return esc_html__( 'One-time', 'cronvitals' );
		}

		$label = $item['schedule'];
		if ( ! empty( $item['interval'] ) ) {
			$label .= ' (' . human_time_diff( 0, (int) $item['interval'] ) . ')';
		}

		return esc_html( $label );
	}

	/**
	 * Next-run column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_next_run( $item ) {
		return $this->format_timestamp( $item['next_run'] );
	}

	/**
	 * Last-run column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_last_run( $item ) {
		if ( empty( $item['last_run'] ) ) {
			return '&mdash;';
		}

		// last_run is a MySQL datetime string in site time.
		$ts = strtotime( $item['last_run'] );
		return $ts ? esc_html( $this->format_datetime( $ts ) ) : '&mdash;';
	}

	/**
	 * Status column with a colored badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_status( $item ) {
		return CronVitals_Admin::status_badge( $item['status'] );
	}

	/**
	 * Monitoring toggle column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_monitored( $item ) {
		$hook = $item['hook'];

		// CronVitals's own plumbing events are not user-monitorable jobs. Must
		// stay in step with CronVitals_Monitor::get_monitored_hooks().
		$own = array(
			CRONVITALS_CRON_SCAN,
			CRONVITALS_CRON_LICENSE,
			CRONVITALS_CRON_SUMMARY,
			CRONVITALS_CRON_HEARTBEAT,
		);
		if ( in_array( $hook, $own, true ) ) {
			return '<span class="cronvitals-badge cronvitals-badge-off">' . esc_html__( 'System', 'cronvitals' ) . '</span>';
		}

		$disabled = (array) CronVitals::get_setting( 'disabled_hooks', array() );
		$is_off   = in_array( $hook, $disabled, true );

		$action = $is_off ? 'enable' : 'disable';
		$label  = $is_off ? __( 'Enable', 'cronvitals' ) : __( 'Disable', 'cronvitals' );
		$url    = $this->toggle_url( $hook, $action );

		$state = $is_off
			? '<span class="cronvitals-badge cronvitals-badge-off">' . esc_html__( 'Off', 'cronvitals' ) . '</span>'
			: '<span class="cronvitals-badge cronvitals-badge-on">' . esc_html__( 'On', 'cronvitals' ) . '</span>';

		return $state . ' <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Build a nonce-protected toggle URL for a hook.
	 *
	 * @param string $hook   Hook name.
	 * @param string $action enable|disable.
	 * @return string
	 */
	protected function toggle_url( $hook, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'             => 'cronvitals',
					'tab'              => 'jobs',
					'cronvitals_action' => 'toggle_job',
					'toggle'           => $action,
					'hook'             => rawurlencode( $hook ),
				),
				admin_url( 'tools.php' )
			),
			'cronvitals_toggle_' . $hook
		);
	}

	/**
	 * Message shown when no jobs exist.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No scheduled cron events found.', 'cronvitals' );
	}

	/**
	 * Format a Unix timestamp with a relative hint.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	protected function format_timestamp( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( ! $timestamp ) {
			return '&mdash;';
		}

		$now  = time();
		$diff = ( $timestamp >= $now )
			/* translators: %s: human-readable time difference. */
			? sprintf( __( 'in %s', 'cronvitals' ), human_time_diff( $now, $timestamp ) )
			/* translators: %s: human-readable time difference. */
			: sprintf( __( '%s ago', 'cronvitals' ), human_time_diff( $timestamp, $now ) );

		return esc_html( $this->format_datetime( $timestamp ) ) . '<br /><span class="description">' . esc_html( $diff ) . '</span>';
	}

	/**
	 * Format a timestamp in the site date/time format and timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	protected function format_datetime( $timestamp ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, $timestamp );
	}
}
