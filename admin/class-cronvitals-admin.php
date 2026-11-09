<?php
/**
 * Admin — the Tools > CronVitals screen and everything behind it.
 *
 * Registers the menu, the settings (Options API), the jobs and logs list
 * tables, per-job toggles, the license form, and the Pro CSV export. Presents
 * three tabs: Jobs, Logs, Settings.
 *
 * @package CronVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CronVitals_Admin.
 */
class CronVitals_Admin {

	/** @var CronVitals_Logger */
	protected $logger;

	/** @var CronVitals_License */
	protected $license;

	/** @var CronVitals_Monitor */
	protected $monitor;

	/** @var CronVitals_Alerts */
	protected $alerts;

	/** @var CronVitals_Heartbeat */
	protected $heartbeat;

	/** @var string */
	protected $hook_suffix = '';

	/**
	 * Settings group + option page slug.
	 */
	const SETTINGS_GROUP = 'cronvitals_settings_group';
	const PAGE_SLUG      = 'cronvitals';

	/**
	 * Option holding when to next ask for a WP.org review: a Unix timestamp,
	 * or 'done' once the user has reviewed or declined for good.
	 */
	const REVIEW_OPTION = 'cronvitals_review_prompt';

	/**
	 * Where the review link sends people.
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/cronvitals/reviews/#new-post';

	/**
	 * Constructor.
	 *
	 * @param CronVitals_Logger  $logger  Logger instance.
	 * @param CronVitals_License $license License instance.
	 * @param CronVitals_Monitor   $monitor   Monitor instance.
	 * @param CronVitals_Alerts    $alerts    Alerts instance.
	 * @param CronVitals_Heartbeat $heartbeat Heartbeat instance.
	 */
	public function __construct( $logger, $license, $monitor, $alerts, $heartbeat ) {
		$this->logger    = $logger;
		$this->license   = $license;
		$this->monitor   = $monitor;
		$this->alerts    = $alerts;
		$this->heartbeat = $heartbeat;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'style_upgrade_menu_item' ) );
		add_action( 'admin_footer', array( $this, 'link_upgrade_menu_item_to_trial' ) );
		add_filter( 'plugin_action_links_' . CRONVITALS_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Restyle the Freemius "Upgrade" item under Tools > CronVitals.
	 *
	 * The SDK ships `.fs-submenu-item.pricing.upgrade-mode { color: #adff2f }`
	 * in its own common.css — a neon green that reads as someone else's branding
	 * sitting inside our menu. This renders it as a small pill in the WordPress
	 * admin blue instead.
	 *
	 * Printed inline on admin_head rather than added to admin.css because
	 * enqueue_assets() deliberately loads that file only on our own screen, while
	 * the admin menu is drawn on every page. admin_head also fires after
	 * admin_print_styles, so this lands later in the cascade than the SDK's rule.
	 *
	 * @return void
	 */
	public function style_upgrade_menu_item() {
		// Nothing to restyle when Freemius is absent or the item is hidden.
		if ( ! function_exists( 'cronvitals_fs' ) ) {
			return;
		}

		echo '<style id="cronvitals-menu-style">
			#adminmenu .wp-submenu a .fs-submenu-item.pricing {
				display: inline-block;
				padding: 1px 8px;
				border-radius: 9px;
				background: #2271b1;
				color: #fff;
				font-weight: 600;
			}
			/* The SDK prefixes every sub-item with "↳"; inside a pill it reads as a typo. */
			#adminmenu .wp-submenu a .fs-submenu-item.pricing:before {
				content: none;
			}
		</style>';
	}

	/**
	 * Point the Freemius "Upgrade" menu item at the trial.
	 *
	 * readme.txt advertises a no-card 14-day trial, but the pricing page only
	 * renders trial CTAs when `trial=true` is in the query string. The SDK adds
	 * that itself in _fix_start_trial_menu_item_url(), except _add_trial_notice()
	 * withholds the whole trial promotion until 24 hours after activation — so
	 * for a new user the thing the listing promises is missing at exactly the
	 * moment they go looking for it.
	 *
	 * This performs the same rewrite without the delay, and without switching on
	 * the SDK's sticky promotion notice, which would contradict the free tier's
	 * "no signup prompt on activation".
	 *
	 * Runs on admin_footer because the submenu markup has to exist first.
	 *
	 * @return void
	 */
	public function link_upgrade_menu_item_to_trial() {
		if ( ! function_exists( 'cronvitals_fs' ) || ! $this->license->is_trial_available() ) {
			return;
		}

		// The SDK composes the span's classes as "fs-submenu-item fs-sub <affix>
		// <menu_slug>", so the affix is what separates our Upgrade item from
		// another Freemius-powered plugin's.
		$affix = esc_js( cronvitals_fs()->get_unique_affix() );

		echo '<script id="cronvitals-menu-trial">
			(function () {
				var item = document.querySelector( ".fs-submenu-item.' . $affix . '.pricing" ),
					link = item ? item.closest( "a" ) : null;

				// Once the SDK begins its own 24-hour promotion, that script appends
				// the same parameter, so guard against ending up with it twice.
				if ( link && link.href.indexOf( "trial=true" ) === -1 ) {
					link.href += "&trial=true";
				}
			})();
		</script>';
	}

	/**
	 * Register the Tools > CronVitals menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_management_page(
			__( 'CronVitals', 'cronvitals' ),
			__( 'CronVitals', 'cronvitals' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'add_screen_options' ) );
		}
	}

	/**
	 * Register the per-page screen option for the logs table.
	 *
	 * @return void
	 */
	public function add_screen_options() {
		if ( 'logs' !== $this->current_tab() ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Logs per page', 'cronvitals' ),
				'default' => 20,
				'option'  => 'cronvitals_logs_per_page',
			)
		);
	}

	/**
	 * Enqueue admin CSS/JS only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'cronvitals-admin',
			CRONVITALS_URL . 'assets/css/admin.css',
			array(),
			CRONVITALS_VERSION
		);

		wp_enqueue_script(
			'cronvitals-admin',
			CRONVITALS_URL . 'assets/js/admin.js',
			array(),
			CRONVITALS_VERSION,
			true
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'cronvitals' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Register the settings option and its sanitizer.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			CRONVITALS_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => CronVitals::default_settings(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * Starts from the existing stored option so fields managed outside this
	 * form (notably `disabled_hooks`, driven by per-job toggles) are preserved.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = wp_parse_args( get_option( CRONVITALS_OPTION_SETTINGS, array() ), CronVitals::default_settings() );

		$clean = $current;

		if ( isset( $input['alert_email'] ) ) {
			$email                = sanitize_email( $input['alert_email'] );
			$clean['alert_email'] = is_email( $email ) ? $email : get_option( 'admin_email' );
		}

		if ( isset( $input['missed_threshold'] ) ) {
			$clean['missed_threshold'] = max( 1, min( 1440, (int) $input['missed_threshold'] ) );
		}

		$clean['monitoring_paused'] = empty( $input['monitoring_paused'] ) ? 0 : 1;

		if ( isset( $input['slack_webhook'] ) ) {
			$webhook                = trim( (string) $input['slack_webhook'] );
			$clean['slack_webhook'] = ( '' === $webhook || ! wp_http_validate_url( $webhook ) ) ? '' : esc_url_raw( $webhook );
		}

		if ( isset( $input['webhook_url'] ) ) {
			$hook_url             = trim( (string) $input['webhook_url'] );
			$clean['webhook_url'] = ( '' === $hook_url || ! wp_http_validate_url( $hook_url ) ) ? '' : esc_url_raw( $hook_url );
		}

		// An unchecked checkbox and a checkbox that was never on the page look
		// identical in $input — both are simply absent. The Pro checkboxes are
		// omitted entirely in the free build and rendered `disabled` (so never
		// submitted) without a licence, so keying off absence alone would reset
		// them on every save. The form emits this marker only when those fields
		// were both present and submittable.
		if ( ! empty( $input['pro_fields_present'] ) ) {
			$clean['monitor_action_scheduler'] = empty( $input['monitor_action_scheduler'] ) ? 0 : 1;
			$clean['heartbeat_enabled']        = empty( $input['heartbeat_enabled'] ) ? 0 : 1;
		}

		if ( isset( $input['heartbeat_url'] ) ) {
			$hb_url                = trim( (string) $input['heartbeat_url'] );
			$clean['heartbeat_url'] = ( '' === $hb_url || ! wp_http_validate_url( $hb_url ) ) ? '' : esc_url_raw( $hb_url );
		}

		if ( isset( $input['heartbeat_interval'] ) ) {
			$interval                    = (int) $input['heartbeat_interval'];
			$clean['heartbeat_interval'] = in_array( $interval, array( 5, 10, 15, 30 ), true ) ? $interval : 5;
		}

		if ( isset( $input['heartbeat_mode'] ) ) {
			$clean['heartbeat_mode'] = ( 'liveness' === $input['heartbeat_mode'] ) ? 'liveness' : 'health';
		}

		// disabled_hooks is driven by the per-job toggles, never this form.
		$clean['disabled_hooks'] = isset( $current['disabled_hooks'] ) ? (array) $current['disabled_hooks'] : array();

		return $clean;
	}

	/**
	 * Dispatch non-settings actions (toggles, scan, clear, license).
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) || self::PAGE_SLUG !== $_REQUEST['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_GET['cronvitals_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['cronvitals_action'] ) );

		switch ( $action ) {
			case 'toggle_job':
				$this->action_toggle_job();
				break;
			case 'scan_now':
				$this->action_scan_now();
				break;
			case 'clear_logs':
				$this->action_clear_logs();
				break;
			case 'heartbeat_test':
				$this->action_heartbeat_test();
				break;
			case 'run_now':
				$this->action_run_now();
				break;
			case 'review':
				$this->action_review();
				break;
		}
	}

	/**
	 * Record the answer to the review request.
	 *
	 * "rate" and "never" both retire the request for good; "later" asks again
	 * in 30 days. "rate" goes through here rather than linking straight to
	 * WordPress.org so the request doesn't reappear after they've left one.
	 *
	 * @return void
	 */
	protected function action_review() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cronvitals_review' ) ) {
			$this->redirect_back( 'overview' );
		}

		$answer = isset( $_GET['answer'] ) ? sanitize_key( wp_unslash( $_GET['answer'] ) ) : '';

		if ( 'later' === $answer ) {
			update_option( self::REVIEW_OPTION, time() + 30 * DAY_IN_SECONDS, false );
			$this->redirect_back( 'overview' );
		}

		update_option( self::REVIEW_OPTION, 'done', false );

		if ( 'rate' === $answer ) {
			// A fixed external URL, so wp_safe_redirect()'s host allowlist
			// would only get in the way.
			wp_redirect( self::REVIEW_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		$this->redirect_back( 'overview' );
	}

	/**
	 * Execute a job immediately and log the result.
	 *
	 * @return void
	 */
	protected function action_run_now() {
		$hook = isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '';

		if ( '' === $hook || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cronvitals_run_' . $hook ) ) {
			$this->redirect_back( 'jobs' );
		}

		$this->monitor->run_now( $hook );

		$this->set_notice(
			'success',
			sprintf(
				/* translators: %s: cron hook name. */
				__( 'Ran "%s". See the Logs tab for the result.', 'cronvitals' ),
				$hook
			)
		);
		$this->redirect_back( 'jobs' );
	}

	/**
	 * Enable/disable monitoring for a single hook.
	 *
	 * @return void
	 */
	protected function action_toggle_job() {
		$hook = isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '';

		if ( '' === $hook || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cronvitals_toggle_' . $hook ) ) {
			$this->redirect_back();
		}

		$toggle   = isset( $_GET['toggle'] ) ? sanitize_key( wp_unslash( $_GET['toggle'] ) ) : '';
		$settings = wp_parse_args( get_option( CRONVITALS_OPTION_SETTINGS, array() ), CronVitals::default_settings() );
		$disabled = (array) $settings['disabled_hooks'];

		if ( 'disable' === $toggle ) {
			if ( ! in_array( $hook, $disabled, true ) ) {
				$disabled[] = $hook;
			}
		} else { // enable.
			$disabled = array_values( array_diff( $disabled, array( $hook ) ) );
		}

		$settings['disabled_hooks'] = $disabled;
		update_option( CRONVITALS_OPTION_SETTINGS, $settings );

		$this->set_notice( 'success', __( 'Monitoring preference updated.', 'cronvitals' ) );
		$this->redirect_back( 'jobs' );
	}

	/**
	 * Trigger an immediate scan.
	 *
	 * @return void
	 */
	protected function action_scan_now() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cronvitals_scan_now' ) ) {
			$this->redirect_back();
		}

		$missed = $this->monitor->scan();

		$this->set_notice(
			'success',
			sprintf(
				/* translators: %d: number of newly missed jobs found. */
				_n( 'Scan complete. %d missed job found.', 'Scan complete. %d missed jobs found.', count( $missed ), 'cronvitals' ),
				count( $missed )
			)
		);

		$this->redirect_back( 'jobs' );
	}

	/**
	 * Clear the entire log history.
	 *
	 * @return void
	 */
	protected function action_clear_logs() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cronvitals_clear_logs' ) ) {
			$this->redirect_back();
		}

		$this->logger->clear();
		$this->set_notice( 'success', __( 'Log history cleared.', 'cronvitals' ) );
		$this->redirect_back( 'logs' );
	}

	/**
	 * Fire a test heartbeat ping and report the result.
	 *
	 * @return void
	 */
	protected function action_heartbeat_test() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'cronvitals_heartbeat_test' ) ) {
			$this->redirect_back( 'settings' );
		}

		if ( ! $this->license->is_pro() || ! $this->heartbeat ) {
			$this->set_notice( 'error', __( 'The heartbeat is a Pro feature.', 'cronvitals' ) );
			$this->redirect_back( 'settings' );
		}

		$result = $this->heartbeat->ping( true );

		if ( ! empty( $result['ok'] ) ) {
			$this->set_notice(
				'success',
				sprintf(
					/* translators: %s: up or down signal word. */
					__( 'Test heartbeat sent successfully (signalled "%s").', 'cronvitals' ),
					$result['state']
				)
			);
		} else {
			$this->set_notice(
				'error',
				sprintf(
					/* translators: %s: error detail. */
					__( 'Test heartbeat failed: %s', 'cronvitals' ),
					'' !== $result['message'] ? $result['message'] : sprintf( 'HTTP %d', (int) $result['code'] )
				)
			);
		}

		$this->redirect_back( 'settings' );
	}

	/**
	 * Render the whole admin page (tab dispatcher).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab  = $this->current_tab();
		$tabs = array(
			'overview' => __( 'Overview', 'cronvitals' ),
			'jobs'     => __( 'Jobs', 'cronvitals' ),
			'logs'     => __( 'Logs', 'cronvitals' ),
			'settings' => __( 'Settings', 'cronvitals' ),
		);

		echo '<div class="wrap cronvitals-wrap">';
		echo '<h1>' . esc_html__( 'CronVitals', 'cronvitals' ) . '</h1>';

		$this->render_notice();
		$this->render_review_request();
		$this->render_tier_banner();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url    = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $slug,
				),
				admin_url( 'tools.php' )
			);
			$active = ( $tab === $slug ) ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'jobs':
				$this->render_jobs_tab();
				break;
			case 'logs':
				$this->render_logs_tab();
				break;
			case 'settings':
				$this->render_settings_tab();
				break;
			default:
				$this->render_overview_tab();
				break;
		}

		echo '</div>';
	}

	/**
	 * Render the Overview tab — reliability stats at a glance, over the full
	 * retention window (same for every install).
	 *
	 * @return void
	 */
	protected function render_overview_tab() {
		$days     = CRONVITALS_LOG_DAYS;
		$stats    = $this->logger->overview_stats( $days );
		$per_hook = $this->logger->per_hook_stats( $days );

		echo '<p style="margin-top:1em;" class="description">';
		printf(
			/* translators: %d: number of days in the reporting window. */
			esc_html__( 'Reliability over the last %d days.', 'cronvitals' ),
			(int) $days
		);
		echo '</p>';

		$rate       = $stats['reliability'];
		$rate_class = ( $rate >= 99 ) ? 'ok' : ( ( $rate >= 90 ) ? 'warn' : 'fail' );

		$cards = array(
			array( 'label' => __( 'Reliability', 'cronvitals' ), 'value' => $rate . '%', 'class' => $rate_class ),
			array( 'label' => __( 'Runs', 'cronvitals' ), 'value' => number_format_i18n( $stats['total'] ), 'class' => 'info' ),
			array( 'label' => __( 'Failed', 'cronvitals' ), 'value' => number_format_i18n( $stats['failed'] ), 'class' => $stats['failed'] > 0 ? 'fail' : 'muted' ),
			array( 'label' => __( 'Missed', 'cronvitals' ), 'value' => number_format_i18n( $stats['missed'] ), 'class' => $stats['missed'] > 0 ? 'warn' : 'muted' ),
		);

		echo '<div class="cronvitals-cards">';
		foreach ( $cards as $card ) {
			echo '<div class="cronvitals-card cronvitals-card-' . esc_attr( $card['class'] ) . '">';
			echo '<span class="cronvitals-card-value">' . esc_html( $card['value'] ) . '</span>';
			echo '<span class="cronvitals-card-label">' . esc_html( $card['label'] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'By job', 'cronvitals' ) . '</h2>';

		if ( empty( $per_hook ) ) {
			echo '<p>' . esc_html__( 'No run history yet. Stats will appear once monitored jobs run.', 'cronvitals' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped cronvitals-jobs-stats">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Hook', 'cronvitals' ) . '</th>';
		echo '<th>' . esc_html__( 'Runs', 'cronvitals' ) . '</th>';
		echo '<th>' . esc_html__( 'Success', 'cronvitals' ) . '</th>';
		echo '<th>' . esc_html__( 'Failed', 'cronvitals' ) . '</th>';
		echo '<th>' . esc_html__( 'Missed', 'cronvitals' ) . '</th>';
		echo '<th>' . esc_html__( 'Reliability', 'cronvitals' ) . '</th>';
		echo '<th>' . esc_html__( 'Avg duration', 'cronvitals' ) . '</th>';
		echo '<th>' . esc_html__( 'Last run', 'cronvitals' ) . '</th>';
		echo '</tr></thead><tbody>';

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( $per_hook as $row ) {
			$success  = (int) $row->success;
			$failed   = (int) $row->failed;
			$missed   = (int) $row->missed;
			$runs     = $success + $failed;
			$expected = $success + $failed + $missed;
			$rate     = $expected > 0 ? round( ( $success / $expected ) * 100, 1 ) : 100.0;

			$row_class = ( $failed > 0 ) ? 'fail' : ( ( $missed > 0 ) ? 'warn' : 'ok' );

			echo '<tr>';
			echo '<td><code>' . esc_html( $row->hook ) . '</code></td>';
			echo '<td>' . esc_html( number_format_i18n( $runs ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $success ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $failed ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $missed ) ) . '</td>';
			echo '<td><span class="cronvitals-status cronvitals-status-' . esc_attr( $row_class ) . '">' . esc_html( $rate . '%' ) . '</span></td>';
			echo '<td>' . ( null === $row->avg_duration ? '&mdash;' : esc_html( number_format_i18n( (float) $row->avg_duration, 3 ) . 's' ) ) . '</td>';
			echo '<td>' . ( empty( $row->last_run ) ? '&mdash;' : esc_html( wp_date( $format, strtotime( $row->last_run ) ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the Jobs tab.
	 *
	 * @return void
	 */
	protected function render_jobs_tab() {
		$table = new CronVitals_Jobs_Table( $this->monitor, $this->license );
		$table->prepare_items();

		$scan_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'tab'              => 'jobs',
					'cronvitals_action' => 'scan_now',
				),
				admin_url( 'tools.php' )
			),
			'cronvitals_scan_now'
		);

		echo '<p style="margin-top:1em;">';
		echo '<a href="' . esc_url( $scan_url ) . '" class="button">' . esc_html__( 'Scan now', 'cronvitals' ) . '</a> ';
		echo '<span class="description">' . esc_html( $this->tier_summary() ) . '</span>';
		echo '</p>';

		if ( CronVitals::get_setting( 'monitoring_paused' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Monitoring is currently paused in Settings.', 'cronvitals' ) . '</p></div>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="jobs" />';
		$table->search_box( __( 'Search jobs', 'cronvitals' ), 'cronvitals-job-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Render the Logs tab.
	 *
	 * @return void
	 */
	protected function render_logs_tab() {
		$table = new CronVitals_Logs_Table( $this->logger );
		$table->prepare_items();

		$clear_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'tab'              => 'logs',
					'cronvitals_action' => 'clear_logs',
				),
				admin_url( 'tools.php' )
			),
			'cronvitals_clear_logs'
		);

		echo '<p style="margin-top:1em;">';
		echo '<a href="' . esc_url( $clear_url ) . '" class="button cronvitals-confirm" data-confirm="' . esc_attr__( 'Delete all log history? This cannot be undone.', 'cronvitals' ) . '">' . esc_html__( 'Clear logs', 'cronvitals' ) . '</a> ';

		if ( $this->license->is_pro() ) {
			$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=cronvitals_export_csv' ), 'cronvitals_export_csv' );
			echo '<a href="' . esc_url( $export_url ) . '" class="button">' . esc_html__( 'Export CSV', 'cronvitals' ) . '</a> ';
		} else {
			echo '<span class="description">' . esc_html__( 'CSV export is a Pro feature.', 'cronvitals' ) . '</span>';
		}
		echo '</p>';

		$table->views();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="logs" />';
		$table->search_box( __( 'Search logs', 'cronvitals' ), 'cronvitals-log-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Render the Settings tab (settings form + license).
	 *
	 * @return void
	 */
	protected function render_settings_tab() {
		$settings = wp_parse_args( get_option( CRONVITALS_OPTION_SETTINGS, array() ), CronVitals::default_settings() );
		$is_pro   = $this->license->is_pro();
		// Premium channel settings only exist in the premium build. In the free
		// build the underlying code is stripped, so we render an upsell instead
		// of dormant, disabled fields.
		$is_premium = CronVitals::is_premium_build();

		// options.php redirects back here with settings-updated=true, but core
		// only renders that confirmation automatically for screens parented to
		// options-general.php. This page lives under Tools, so without this the
		// user saves and sees no feedback at all.
		if ( ! empty( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error(
				'cronvitals_messages',
				'cronvitals_settings_saved',
				__( 'Settings saved.', 'cronvitals' ),
				'updated'
			);
		}
		settings_errors( 'cronvitals_messages' );

		echo '<form method="post" action="options.php" class="cronvitals-settings">';
		settings_fields( self::SETTINGS_GROUP );

		// Tells the sanitizer that the Pro checkboxes were on this page and
		// submittable, so an absent value means "unchecked" rather than
		// "never rendered". See sanitize_settings().
		if ( $is_premium && $is_pro ) {
			printf(
				'<input type="hidden" name="%s[pro_fields_present]" value="1" />',
				esc_attr( CRONVITALS_OPTION_SETTINGS )
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="cronvitals_alert_email">' . esc_html__( 'Alert email', 'cronvitals' ) . '</label></th><td>';
		printf(
			'<input type="email" id="cronvitals_alert_email" name="%s[alert_email]" value="%s" class="regular-text" />',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			esc_attr( $settings['alert_email'] )
		);
		echo '<p class="description">' . esc_html__( 'Where alerts are sent. Defaults to the site admin email.', 'cronvitals' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cronvitals_missed_threshold">' . esc_html__( 'Missed threshold (minutes)', 'cronvitals' ) . '</label></th><td>';
		printf(
			'<input type="number" min="1" max="1440" id="cronvitals_missed_threshold" name="%s[missed_threshold]" value="%s" class="small-text" />',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			esc_attr( $settings['missed_threshold'] )
		);
		echo '<p class="description">' . esc_html__( 'How long a job may run past its scheduled time before it is flagged as missed.', 'cronvitals' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Pause monitoring', 'cronvitals' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="%s[monitoring_paused]" value="1" %s /> %s</label>',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			checked( ! empty( $settings['monitoring_paused'] ), true, false ),
			esc_html__( 'Temporarily stop detecting and alerting on all jobs.', 'cronvitals' )
		);
		echo '</td></tr>';

		if ( $is_premium ) :

		echo '<tr><th scope="row"><label for="cronvitals_slack_webhook">' . esc_html__( 'Slack webhook URL', 'cronvitals' );
		if ( ! $is_pro ) {
			echo ' <span class="cronvitals-pro-tag">' . esc_html__( 'Pro', 'cronvitals' ) . '</span>';
		}
		echo '</label></th><td>';
		printf(
			'<input type="url" id="cronvitals_slack_webhook" name="%s[slack_webhook]" value="%s" class="regular-text" %s />',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			esc_attr( $settings['slack_webhook'] ),
			$is_pro ? '' : 'disabled'
		);
		echo '<p class="description">' . esc_html__( 'Pro: receive alerts in Slack via an incoming webhook.', 'cronvitals' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cronvitals_webhook_url">' . esc_html__( 'Generic webhook URL', 'cronvitals' );
		if ( ! $is_pro ) {
			echo ' <span class="cronvitals-pro-tag">' . esc_html__( 'Pro', 'cronvitals' ) . '</span>';
		}
		echo '</label></th><td>';
		printf(
			'<input type="url" id="cronvitals_webhook_url" name="%s[webhook_url]" value="%s" class="regular-text" %s />',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			esc_attr( $settings['webhook_url'] ),
			$is_pro ? '' : 'disabled'
		);
		echo '<p class="description">' . esc_html__( 'Pro: POST a JSON event to any endpoint (Zapier, Make, Discord, Teams, PagerDuty). Reshape via the cronvitals_webhook_payload filter.', 'cronvitals' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Action Scheduler', 'cronvitals' );
		if ( ! $is_pro ) {
			echo ' <span class="cronvitals-pro-tag">' . esc_html__( 'Pro', 'cronvitals' ) . '</span>';
		}
		echo '</th><td>';
		// Detect Action Scheduler directly (our adapter class is absent in the free build).
		$as_available = class_exists( 'ActionScheduler' ) && function_exists( 'as_get_scheduled_actions' );
		printf(
			'<label><input type="checkbox" name="%s[monitor_action_scheduler]" value="1" %s %s /> %s</label>',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			checked( ! empty( $settings['monitor_action_scheduler'] ), true, false ),
			$is_pro ? '' : 'disabled',
			esc_html__( 'Monitor WooCommerce / Action Scheduler background jobs.', 'cronvitals' )
		);
		if ( ! $as_available ) {
			echo '<p class="description">' . esc_html__( 'Action Scheduler was not detected on this site (installed by WooCommerce and many other plugins).', 'cronvitals' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Pro: capture success/failure of Action Scheduler jobs and detect stalled queues.', 'cronvitals' ) . '</p>';
		}
		echo '</td></tr>';

		// External heartbeat (Pro).
		$hb_pro = $is_pro ? '' : 'disabled';

		echo '<tr><th scope="row">' . esc_html__( 'External heartbeat', 'cronvitals' );
		if ( ! $is_pro ) {
			echo ' <span class="cronvitals-pro-tag">' . esc_html__( 'Pro', 'cronvitals' ) . '</span>';
		}
		echo '</th><td>';
		printf(
			'<label><input type="checkbox" name="%s[heartbeat_enabled]" value="1" %s %s /> %s</label>',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			checked( ! empty( $settings['heartbeat_enabled'] ), true, false ),
			$hb_pro, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static 'disabled' flag.
			esc_html__( 'Ping an external monitor so you are alerted even if this site goes down.', 'cronvitals' )
		);
		echo '<p class="description">' . esc_html__( 'Set up a free check at Healthchecks.io, Cronitor, or BetterStack and paste its ping URL below.', 'cronvitals' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cronvitals_heartbeat_url">' . esc_html__( 'Heartbeat URL', 'cronvitals' ) . '</label></th><td>';
		printf(
			'<input type="url" id="cronvitals_heartbeat_url" name="%s[heartbeat_url]" value="%s" class="regular-text" placeholder="https://hc-ping.com/your-uuid" %s />',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			esc_attr( $settings['heartbeat_url'] ),
			$hb_pro // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static 'disabled' flag.
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cronvitals_heartbeat_interval">' . esc_html__( 'Ping every', 'cronvitals' ) . '</label></th><td>';
		echo '<select id="cronvitals_heartbeat_interval" name="' . esc_attr( CRONVITALS_OPTION_SETTINGS ) . '[heartbeat_interval]" ' . esc_attr( $hb_pro ) . '>';
		foreach ( array( 5, 10, 15, 30 ) as $mins ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $mins,
				selected( (int) $settings['heartbeat_interval'], $mins, false ),
				/* translators: %d: number of minutes. */
				esc_html( sprintf( _n( '%d minute', '%d minutes', $mins, 'cronvitals' ), $mins ) )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Set the expected period on the monitor a little higher than this (e.g. 2x) to avoid false alarms.', 'cronvitals' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Heartbeat mode', 'cronvitals' ) . '</th><td>';
		$mode = $settings['heartbeat_mode'];
		printf(
			'<label style="display:block;margin-bottom:4px;"><input type="radio" name="%1$s[heartbeat_mode]" value="health" %2$s %3$s /> %4$s</label>',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			checked( 'health', $mode, false ),
			$hb_pro, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static 'disabled' flag.
			esc_html__( 'Health-aware — signal failure when jobs are missed/failed, so the monitor catches both a dead site and broken jobs.', 'cronvitals' )
		);
		printf(
			'<label style="display:block;"><input type="radio" name="%1$s[heartbeat_mode]" value="liveness" %2$s %3$s /> %4$s</label>',
			esc_attr( CRONVITALS_OPTION_SETTINGS ),
			checked( 'liveness', $mode, false ),
			$hb_pro, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static 'disabled' flag.
			esc_html__( 'Liveness only — just confirm the site is alive.', 'cronvitals' )
		);
		echo '</td></tr>';

		else :

		echo '<tr><th scope="row">' . esc_html__( 'More alert channels', 'cronvitals' ) . ' <span class="cronvitals-pro-tag">' . esc_html__( 'Pro', 'cronvitals' ) . '</span></th><td>';
		echo '<p class="description">';
		printf(
			/* translators: %s: upgrade link. */
			esc_html__( 'Pro adds Slack and generic webhook alerts, WooCommerce / Action Scheduler monitoring, an external heartbeat, CSV export, and a weekly summary. %s', 'cronvitals' ),
			'<a href="' . esc_url( $this->license->purchase_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade to Pro', 'cronvitals' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '</p></td></tr>';

		endif;

		echo '</tbody></table>';
		submit_button();
		echo '</form>';

		$this->render_heartbeat_status();
	}

	/**
	 * Render the heartbeat status + test-ping block (outside the settings form).
	 *
	 * @return void
	 */
	protected function render_heartbeat_status() {
		if ( ! $this->heartbeat || ! $this->license->is_pro() || ! CronVitals::get_setting( 'heartbeat_enabled' ) ) {
			return;
		}

		$status = $this->heartbeat->last_status();
		$next   = $this->heartbeat->next_ping();
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<hr /><h2>' . esc_html__( 'Heartbeat status', 'cronvitals' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Last ping', 'cronvitals' ) . '</th><td>';
		if ( empty( $status ) ) {
			echo '<span class="description">' . esc_html__( 'No ping sent yet.', 'cronvitals' ) . '</span>';
		} else {
			$badge = ! empty( $status['ok'] ) ? 'ok' : 'fail';
			$label = ! empty( $status['ok'] )
				/* translators: %s: up/down signal word. */
				? sprintf( __( 'Delivered (%s)', 'cronvitals' ), $status['state'] )
				: __( 'Failed', 'cronvitals' );
			echo '<span class="cronvitals-status cronvitals-status-' . esc_attr( $badge ) . '">' . esc_html( $label ) . '</span> ';
			echo '<span class="description">' . esc_html( wp_date( $format, (int) $status['time'] ) );
			if ( empty( $status['ok'] ) && ! empty( $status['message'] ) ) {
				echo ' — ' . esc_html( $status['message'] );
			}
			echo '</span>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Next ping', 'cronvitals' ) . '</th><td>';
		echo $next // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped below.
			? esc_html( wp_date( $format, (int) $next ) )
			: '<span class="description">' . esc_html__( 'Not scheduled.', 'cronvitals' ) . '</span>';
		echo '</td></tr>';

		echo '</tbody></table>';

		$test_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'tab'              => 'settings',
					'cronvitals_action' => 'heartbeat_test',
				),
				admin_url( 'tools.php' )
			),
			'cronvitals_heartbeat_test'
		);
		echo '<p><a href="' . esc_url( $test_url ) . '" class="button">' . esc_html__( 'Send test ping', 'cronvitals' ) . '</a></p>';
	}

	/**
	 * Ask for a WordPress.org review, once the plugin has had time to prove
	 * itself.
	 *
	 * Shown only on this screen, never site-wide: a week after the screen is
	 * first opened, and only once it has actually recorded runs, so the request
	 * comes with something to judge it on. Ratings are what the WordPress.org search
	 * ranks new plugins by, which makes this the main way new users find it.
	 *
	 * @return void
	 */
	protected function render_review_request() {
		$ask_after = get_option( self::REVIEW_OPTION );

		if ( false === $ask_after ) {
			add_option( self::REVIEW_OPTION, time() + WEEK_IN_SECONDS, '', false );
			return;
		}

		if ( 'done' === $ask_after || time() < (int) $ask_after ) {
			return;
		}

		$stats = $this->logger->overview_stats( CRONVITALS_LOG_DAYS );
		if ( empty( $stats['total'] ) ) {
			return;
		}

		$answer_url = function ( $answer ) {
			return wp_nonce_url(
				add_query_arg(
					array(
						'page'              => self::PAGE_SLUG,
						'cronvitals_action' => 'review',
						'answer'            => $answer,
					),
					admin_url( 'tools.php' )
				),
				'cronvitals_review'
			);
		};

		echo '<div class="notice notice-info inline cronvitals-review-request"><p>';
		printf(
			/* translators: 1: number of cron runs, 2: number of days. */
			esc_html( _n( 'CronVitals has recorded %1$s cron run on this site in the last %2$d days.', 'CronVitals has recorded %1$s cron runs on this site in the last %2$d days.', (int) $stats['total'], 'cronvitals' ) ),
			esc_html( number_format_i18n( $stats['total'] ) ),
			(int) CRONVITALS_LOG_DAYS
		);
		echo ' ' . esc_html__( 'If it has been useful, a short review on WordPress.org helps other site owners find it.', 'cronvitals' );
		echo '</p><p>';
		echo '<a href="' . esc_url( $answer_url( 'rate' ) ) . '" class="button button-primary" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Leave a review', 'cronvitals' ) . '</a> ';
		echo '<a href="' . esc_url( $answer_url( 'later' ) ) . '" class="button">' . esc_html__( 'Not now', 'cronvitals' ) . '</a> ';
		echo '<a href="' . esc_url( $answer_url( 'never' ) ) . '" class="button-link">' . esc_html__( "Don't ask again", 'cronvitals' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Render a Pro/Free banner at the top of the page.
	 *
	 * @return void
	 */
	protected function render_tier_banner() {
		if ( $this->license->is_pro() ) {
			return;
		}

		echo '<div class="notice notice-info inline cronvitals-tier-banner"><p>';
		printf(
			/* translators: %s: purchase URL link. */
			esc_html__( 'Unlimited job monitoring and email alerts are free. Pro adds Slack &amp; webhook alerts, WooCommerce / Action Scheduler monitoring, an external heartbeat, CSV export, and a weekly summary. %s', 'cronvitals' ),
			'<a href="' . esc_url( $this->license->purchase_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade to Pro', 'cronvitals' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '</p></div>';
	}

	/**
	 * One-line summary shown next to the jobs list.
	 *
	 * @return string
	 */
	protected function tier_summary() {
		return sprintf(
			/* translators: %d: retention days. */
			__( 'Unlimited jobs, %d-day history.', 'cronvitals' ),
			CRONVITALS_LOG_DAYS
		);
	}

	/**
	 * Current tab slug (whitelisted).
	 *
	 * @return string
	 */
	protected function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'overview', 'jobs', 'logs', 'settings' ), true ) ? $tab : 'overview';
	}

	/**
	 * Store a one-time admin notice in a transient.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message text.
	 * @return void
	 */
	protected function set_notice( $type, $message ) {
		set_transient(
			'cronvitals_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Render (and clear) a stored admin notice.
	 *
	 * @return void
	 */
	protected function render_notice() {
		$key    = 'cronvitals_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( empty( $notice ) || ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );

		$class = 'notice notice-' . ( in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info' );
		echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	/**
	 * Redirect back to the plugin page (optionally a given tab) and exit.
	 *
	 * @param string $tab Tab to land on.
	 * @return void
	 */
	protected function redirect_back( $tab = 'jobs' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $tab,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render a status badge. Shared by both list tables.
	 *
	 * @param string $status Status key.
	 * @return string HTML.
	 */
	public static function status_badge( $status ) {
		$map = array(
			'healthy'                        => array( 'ok', __( 'Healthy', 'cronvitals' ) ),
			CronVitals_Logger::STATUS_SUCCESS => array( 'ok', __( 'Success', 'cronvitals' ) ),
			CronVitals_Logger::STATUS_MISSED  => array( 'warn', __( 'Missed', 'cronvitals' ) ),
			CronVitals_Logger::STATUS_FAILED  => array( 'fail', __( 'Failed', 'cronvitals' ) ),
			CronVitals_Logger::STATUS_RUNNING => array( 'info', __( 'Running', 'cronvitals' ) ),
			'unmonitored'                    => array( 'muted', __( 'Not monitored', 'cronvitals' ) ),
		);

		$def = isset( $map[ $status ] ) ? $map[ $status ] : array( 'muted', ucfirst( $status ) );

		return '<span class="cronvitals-status cronvitals-status-' . esc_attr( $def[0] ) . '">' . esc_html( $def[1] ) . '</span>';
	}
}
