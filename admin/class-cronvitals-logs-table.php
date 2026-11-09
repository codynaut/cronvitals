<?php
/**
 * Logs list table — recent cron run history from the custom table.
 *
 * Supports status filtering, search, sortable columns, and pagination, all
 * delegated to the Logger's prepared queries.
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
 * Class CronVitals_Logs_Table.
 */
class CronVitals_Logs_Table extends WP_List_Table {

	/** @var CronVitals_Logger */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param CronVitals_Logger $logger Logger instance.
	 */
	public function __construct( $logger ) {
		$this->logger = $logger;

		parent::__construct(
			array(
				'singular' => 'cron_log',
				'plural'   => 'cron_logs',
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
			'status'         => __( 'Status', 'cronvitals' ),
			'hook'           => __( 'Hook', 'cronvitals' ),
			'schedule'       => __( 'Schedule', 'cronvitals' ),
			'scheduled_time' => __( 'Scheduled', 'cronvitals' ),
			'run_time'       => __( 'Ran', 'cronvitals' ),
			'duration'       => __( 'Duration', 'cronvitals' ),
			'message'        => __( 'Details', 'cronvitals' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'status'         => array( 'status', false ),
			'hook'           => array( 'hook', false ),
			'scheduled_time' => array( 'scheduled_time', false ),
			'run_time'       => array( 'run_time', false ),
			'duration'       => array( 'duration', false ),
		);
	}

	/**
	 * Status-filter views above the table.
	 *
	 * @return array
	 */
	protected function get_views() {
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$base = add_query_arg(
			array(
				'page' => 'cronvitals',
				'tab'  => 'logs',
			),
			admin_url( 'tools.php' )
		);

		$statuses = array(
			''                                => __( 'All', 'cronvitals' ),
			CronVitals_Logger::STATUS_SUCCESS  => __( 'Success', 'cronvitals' ),
			CronVitals_Logger::STATUS_MISSED   => __( 'Missed', 'cronvitals' ),
			CronVitals_Logger::STATUS_FAILED   => __( 'Failed', 'cronvitals' ),
		);

		// One grouped query for every link's count. Counting per status meant a
		// separate full scan per link.
		$counts = $this->logger->count_by_status();
		$total  = array_sum( $counts );

		$views = array();
		foreach ( $statuses as $key => $label ) {
			$url   = $key ? add_query_arg( 'status', $key, $base ) : remove_query_arg( 'status', $base );
			$class = ( $current === $key ) ? ' class="current"' : '';
			$count = $key ? ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) : $total;

			$views[ $key ? $key : 'all' ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$class, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	/**
	 * Prepare items with filters, sorting, and pagination.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$per_page = $this->get_items_per_page( 'cronvitals_logs_per_page', 20 );
		$paged    = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$args = array(
			'status'   => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id',
			'order'    => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
			'per_page' => $per_page,
			'page'     => $paged,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total = $this->logger->count_logs( $args );
		$this->items = $this->logger->get_logs( $args );

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
	 * @param object $item        Row object.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Status column with badge.
	 *
	 * @param object $item Row object.
	 * @return string
	 */
	public function column_status( $item ) {
		return CronVitals_Admin::status_badge( $item->status );
	}

	/**
	 * Hook column.
	 *
	 * @param object $item Row object.
	 * @return string
	 */
	public function column_hook( $item ) {
		return '<code>' . esc_html( $item->hook ) . '</code>';
	}

	/**
	 * Schedule column.
	 *
	 * @param object $item Row object.
	 * @return string
	 */
	public function column_schedule( $item ) {
		if ( '' === $item->schedule || 'single' === $item->schedule ) {
			return esc_html__( 'One-time', 'cronvitals' );
		}
		return esc_html( $item->schedule );
	}

	/**
	 * Scheduled-time column.
	 *
	 * @param object $item Row object.
	 * @return string
	 */
	public function column_scheduled_time( $item ) {
		return $this->format_mysql( $item->scheduled_time );
	}

	/**
	 * Run-time column.
	 *
	 * @param object $item Row object.
	 * @return string
	 */
	public function column_run_time( $item ) {
		return $this->format_mysql( $item->run_time );
	}

	/**
	 * Duration column.
	 *
	 * @param object $item Row object.
	 * @return string
	 */
	public function column_duration( $item ) {
		if ( null === $item->duration || '' === $item->duration ) {
			return '&mdash;';
		}
		/* translators: %s: seconds. */
		return esc_html( sprintf( __( '%ss', 'cronvitals' ), number_format_i18n( (float) $item->duration, 3 ) ) );
	}

	/**
	 * Details/message column.
	 *
	 * @param object $item Row object.
	 * @return string
	 */
	public function column_message( $item ) {
		if ( empty( $item->message ) ) {
			return '&mdash;';
		}
		return '<span class="description">' . esc_html( $item->message ) . '</span>';
	}

	/**
	 * Empty-state message.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No cron run history yet.', 'cronvitals' );
	}

	/**
	 * Format a MySQL datetime string (site time) for display.
	 *
	 * @param string|null $mysql Datetime string.
	 * @return string
	 */
	protected function format_mysql( $mysql ) {
		if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
			return '&mdash;';
		}

		$ts = strtotime( $mysql );
		if ( ! $ts ) {
			return esc_html( $mysql );
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return esc_html( wp_date( $format, $ts ) );
	}
}
