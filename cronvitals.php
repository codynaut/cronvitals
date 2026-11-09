<?php

/**
 * Plugin Name:       CronVitals – Cron Job Monitor & WP-Cron Failure Alerts
 * Plugin URI:        https://cronvitals.com/
 * Description:       Cron job monitoring for WordPress. Know when a WP-Cron job fails, misses its run, or dies on a PHP fatal, with the error and an email alert.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            CronVitals
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cronvitals
 *
 *
 * @package CronVitals
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( function_exists( 'cronvitals_fs' ) ) {
    // Paid version taking over an already-loaded free version.
    cronvitals_fs()->set_basename( false, __FILE__ );
} else {
    if ( !function_exists( 'cronvitals_fs' ) ) {
        /**
         * Freemius SDK bootstrap. Handles licensing, Pro update delivery, and the
         * free/paid split. The `id` and `public_key` are public by design; the
         * secret key lives only in the local wp-config for dev testing, never here.
         */
        function cronvitals_fs() {
            global $cronvitals_fs;
            if ( !isset( $cronvitals_fs ) ) {
                require_once __DIR__ . '/vendor/freemius/wordpress-sdk/start.php';
                $cronvitals_fs = fs_dynamic_init( array(
                    'id'               => '33922',
                    'slug'             => 'cronvitals',
                    'premium_slug'     => 'cronvitals-premium',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_c7945715c60f01ea2f828bd54ff50',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Pro',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'enable_anonymous' => true,
                    'anonymous_mode'   => true,
                    'trial'            => array(
                        'days'               => 14,
                        'is_require_payment' => false,
                    ),
                    'menu'             => array(
                        'slug'    => 'cronvitals',
                        'support' => false,
                        'contact' => false,
                        'parent'  => array(
                            'slug' => 'tools.php',
                        ),
                    ),
                    'is_live'          => true,
                ) );
            }
            return $cronvitals_fs;
        }

        cronvitals_fs();
        do_action( 'cronvitals_fs_loaded' );
        // Freemius owns the uninstall lifecycle, so cleanup runs here rather
        // than in a standalone uninstall.php (which Freemius disallows).
        cronvitals_fs()->add_action( 'after_uninstall', 'cronvitals_uninstall_cleanup' );
        // Show annual plans at their annual price. The SDK defaults this to true
        // (see templates/pricing.php), which divides the yearly figure by twelve
        // and renders "$4.08 / mo — billed annually". These are yearly licences
        // bought off a yearly budget, and a price of $4.08 reads as calculated
        // rather than chosen.
        cronvitals_fs()->add_filter( 'pricing/show_annual_in_monthly', '__return_false' );
        // Point the SDK straight at our icon. Left to itself it globs for the
        // file under a directory derived from the SDK's own registered path,
        // and when that misses it falls back to the wordpress.org API — which
        // 404s until the plugin is actually published, leaving a generic plug
        // on the pricing and account screens. The filter takes an absolute
        // path and skips the guesswork.
        //
        // Built from plugin_basename() rather than __DIR__ on purpose. The SDK
        // turns this path into a URL with fs_asset_url(), which only recognises
        // paths under WP_CONTENT_DIR or WP_PLUGIN_DIR. PHP resolves symlinks and
        // junctions in __DIR__, so on a symlinked install it would hand back the
        // real path outside wp-content and the URL would come out malformed.
        // plugin_basename() is symlink-aware and always returns the plugins-
        // relative path.
        cronvitals_fs()->add_filter( 'plugin_icon', function () {
            return WP_PLUGIN_DIR . '/' . dirname( CRONVITALS_BASENAME ) . '/assets/img/cronvitals.png';
        } );
    }
    /**
     * Current plugin version.
     * Bump on release to trigger asset cache-busting and any migrations.
     */
    define( 'CRONVITALS_VERSION', '1.0.1' );
    /**
     * Plugin path/URL/file constants.
     */
    define( 'CRONVITALS_FILE', __FILE__ );
    define( 'CRONVITALS_PATH', plugin_dir_path( __FILE__ ) );
    define( 'CRONVITALS_URL', plugin_dir_url( __FILE__ ) );
    define( 'CRONVITALS_BASENAME', plugin_basename( __FILE__ ) );
    /**
     * Database schema version. Bump when the log table schema changes so the
     * activation routine (and future upgrade routine) can run dbDelta again.
     */
    define( 'CRONVITALS_DB_VERSION', '1.0.0' );
    /**
     * Option keys, all namespaced to avoid collisions.
     */
    define( 'CRONVITALS_OPTION_SETTINGS', 'cronvitals_settings' );
    define( 'CRONVITALS_OPTION_LICENSE', 'cronvitals_license' );
    define( 'CRONVITALS_OPTION_DB_VERSION', 'cronvitals_db_version' );
    define( 'CRONVITALS_OPTION_DISMISSED', 'cronvitals_dismissed_notices' );
    /**
     * Cron hook names owned by this plugin.
     */
    define( 'CRONVITALS_CRON_SCAN', 'cronvitals_scan_event' );
    define( 'CRONVITALS_CRON_LICENSE', 'cronvitals_license_check_event' );
    define( 'CRONVITALS_CRON_SUMMARY', 'cronvitals_weekly_summary_event' );
    define( 'CRONVITALS_CRON_HEARTBEAT', 'cronvitals_heartbeat_event' );
    /**
     * The custom log table name (without the $wpdb prefix). Use
     * CronVitals_Logger::table_name() to get the fully-prefixed name.
     */
    define( 'CRONVITALS_LOG_TABLE', 'cronvitals_log' );
    /**
     * How many days of run history to keep. Applies to every install — the plugin
     * imposes no tier-based caps on jobs monitored or history retained.
     */
    define( 'CRONVITALS_LOG_DAYS', 30 );
    /**
     * Main plugin bootstrap.
     *
     * Loads dependencies, wires up the activation/deactivation lifecycle, and
     * instantiates the runtime components. Intentionally defensive: nothing here
     * assumes WP-Cron is enabled, so the plugin keeps working if a real system
     * cron drives wp-cron.php or if DISABLE_WP_CRON is set.
     */
    final class CronVitals {
        /** @var CronVitals|null */
        private static $instance = null;

        /** @var CronVitals_Logger */
        public $logger;

        /** @var CronVitals_License */
        public $license;

        /** @var CronVitals_Monitor */
        public $monitor;

        /** @var CronVitals_Alerts */
        public $alerts;

        /** @var CronVitals_Action_Scheduler */
        public $action_scheduler;

        /** @var CronVitals_Heartbeat */
        public $heartbeat;

        /** @var CronVitals_Pro */
        public $pro;

        /** @var CronVitals_Admin */
        public $admin;

        /**
         * Retrieve the singleton instance.
         *
         * @return CronVitals
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
                self::$instance->includes();
                self::$instance->init();
            }
            return self::$instance;
        }

        /**
         * Private constructor — use instance().
         */
        private function __construct() {
        }

        /**
         * Whether this is the premium (paid) build. In the free build the Pro-only
         * files are stripped by Freemius, so this gates their loading/use.
         *
         * @return bool
         */
        public static function is_premium_build() {
            return function_exists( 'cronvitals_fs' ) && cronvitals_fs()->is__premium_only();
        }

        /**
         * Load class files.
         *
         * @return void
         */
        private function includes() {
            require_once CRONVITALS_PATH . 'includes/class-cronvitals-logger.php';
            require_once CRONVITALS_PATH . 'includes/class-cronvitals-license.php';
            require_once CRONVITALS_PATH . 'includes/class-cronvitals-monitor.php';
            require_once CRONVITALS_PATH . 'includes/class-cronvitals-alerts.php';
            // Pro-only components. Physically absent from the free build (stripped
            // via @fs_premium_only), so only load them in the premium build.
            if ( self::is_premium_build() ) {
                require_once CRONVITALS_PATH . 'includes/class-cronvitals-action-scheduler.php';
                require_once CRONVITALS_PATH . 'includes/class-cronvitals-heartbeat.php';
                require_once CRONVITALS_PATH . 'includes/class-cronvitals-pro.php';
            }
            if ( is_admin() ) {
                require_once CRONVITALS_PATH . 'admin/class-cronvitals-jobs-table.php';
                require_once CRONVITALS_PATH . 'admin/class-cronvitals-logs-table.php';
                require_once CRONVITALS_PATH . 'admin/class-cronvitals-admin.php';
            }
        }

        /**
         * Instantiate components and register runtime hooks.
         *
         * @return void
         */
        private function init() {
            $this->logger = new CronVitals_Logger();
            $this->license = new CronVitals_License();
            $this->monitor = new CronVitals_Monitor($this->logger, $this->license);
            $this->alerts = new CronVitals_Alerts($this->logger, $this->license);
            $this->monitor->hooks();
            $this->alerts->hooks();
            $this->license->hooks();
            // Pro-only components only exist in the premium build.
            if ( self::is_premium_build() ) {
                $this->action_scheduler = new CronVitals_Action_Scheduler($this->logger, $this->license);
                $this->heartbeat = new CronVitals_Heartbeat($this->license, $this->monitor);
                $this->pro = new CronVitals_Pro($this->logger, $this->license, $this->alerts);
                $this->action_scheduler->hooks();
                $this->heartbeat->hooks();
                $this->pro->hooks();
            }
            if ( is_admin() ) {
                $this->admin = new CronVitals_Admin(
                    $this->logger,
                    $this->license,
                    $this->monitor,
                    $this->alerts,
                    $this->heartbeat
                );
                $this->admin->hooks();
            }
        }

        /**
         * Return the plugin's default settings.
         *
         * Centralized so the settings screen, monitor, and alerts all agree on
         * defaults even before the option has been saved once.
         *
         * @return array
         */
        public static function default_settings() {
            return array(
                'alert_email'              => get_option( 'admin_email' ),
                'missed_threshold'         => 15,
                'monitoring_paused'        => 0,
                'slack_webhook'            => '',
                'webhook_url'              => '',
                'monitor_action_scheduler' => 1,
                'heartbeat_enabled'        => 0,
                'heartbeat_url'            => '',
                'heartbeat_interval'       => 5,
                'heartbeat_mode'           => 'health',
                'disabled_hooks'           => array(),
            );
        }

        /**
         * Get a single setting value with the default fallback.
         *
         * @param string $key     Setting key.
         * @param mixed  $default Optional explicit default.
         * @return mixed
         */
        public static function get_setting( $key, $default = null ) {
            $settings = wp_parse_args( get_option( CRONVITALS_OPTION_SETTINGS, array() ), self::default_settings() );
            if ( array_key_exists( $key, $settings ) ) {
                return $settings[$key];
            }
            return $default;
        }

    }

    /**
     * Install (or repair) the plugin's per-site footprint: the log table, the scan
     * event, and the default settings. Idempotent — safe to call repeatedly, and
     * safe to call inside switch_to_blog().
     *
     * @return void
     */
    function cronvitals_install_site() {
        require_once CRONVITALS_PATH . 'includes/class-cronvitals-logger.php';
        CronVitals_Logger::install_table();
        if ( !wp_next_scheduled( CRONVITALS_CRON_SCAN ) ) {
            wp_schedule_event( time() + 300, 'cronvitals_five_minutes', CRONVITALS_CRON_SCAN );
        }
        if ( false === get_option( CRONVITALS_OPTION_SETTINGS ) ) {
            add_option( CRONVITALS_OPTION_SETTINGS, CronVitals::default_settings() );
        }
    }

    /**
     * Activation handler.
     *
     * On a single site this installs directly. On a network activation it installs
     * across the network — but only up to a bounded number of sites, so activating
     * on a large multisite can't blow the request timeout. Any site not covered
     * here is installed lazily on its first admin/cron request by
     * cronvitals_maybe_upgrade(), so coverage is complete either way.
     *
     * @param bool $network_wide Whether the plugin is being network-activated.
     * @return void
     */
    function cronvitals_activate(  $network_wide = false  ) {
        if ( is_multisite() && $network_wide ) {
            $site_ids = get_sites( array(
                'fields' => 'ids',
                'number' => 200,
            ) );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                cronvitals_install_site();
                restore_current_blog();
            }
            return;
        }
        cronvitals_install_site();
    }

    register_activation_hook( __FILE__, 'cronvitals_activate' );
    /**
     * Install onto a newly created site when the plugin is network-active, so
     * subsites added after activation are monitored too.
     *
     * @param WP_Site $new_site The site that was just created.
     * @return void
     */
    function cronvitals_new_site(  $new_site  ) {
        if ( !function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( !is_plugin_active_for_network( CRONVITALS_BASENAME ) ) {
            return;
        }
        switch_to_blog( (int) $new_site->blog_id );
        cronvitals_install_site();
        restore_current_blog();
    }

    add_action( 'wp_initialize_site', 'cronvitals_new_site', 100 );
    /**
     * Schema/install guard.
     *
     * The activation hook only ever fires once, on one site, so it cannot be the
     * only path that builds the table: a schema change shipped in a later version
     * would never reach existing installs, and a multisite subsite that missed
     * activation would never get a table at all. This compares the stored schema
     * version against CRONVITALS_DB_VERSION on each admin/cron request and re-runs
     * the installer when they diverge.
     *
     * The common case costs one autoloaded option read and returns immediately.
     *
     * @return void
     */
    function cronvitals_maybe_upgrade() {
        if ( CRONVITALS_DB_VERSION === get_option( CRONVITALS_OPTION_DB_VERSION ) ) {
            return;
        }
        cronvitals_install_site();
    }

    add_action( 'admin_init', 'cronvitals_maybe_upgrade' );
    // Cron requests never reach admin_init, and they are exactly where the table
    // needs to exist, so run the same guard there.
    if ( wp_doing_cron() ) {
        add_action( 'init', 'cronvitals_maybe_upgrade' );
    }
    /**
     * Deactivation handler.
     *
     * Unschedules every cron event this plugin owns. Does NOT drop the table or
     * options — that only happens on uninstall so a deactivate/reactivate cycle
     * preserves history.
     *
     * @param bool $network_wide Whether the plugin is being network-deactivated.
     * @return void
     */
    function cronvitals_deactivate(  $network_wide = false  ) {
        if ( is_multisite() && $network_wide ) {
            // Unlike activation there is no lazy backstop for this, so walk the
            // whole network: an orphaned event left on a subsite would otherwise
            // keep rescheduling itself forever with nothing listening.
            $site_ids = get_sites( array(
                'fields' => 'ids',
                'number' => 0,
            ) );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                cronvitals_unschedule_all();
                restore_current_blog();
            }
            return;
        }
        cronvitals_unschedule_all();
    }

    register_deactivation_hook( __FILE__, 'cronvitals_deactivate' );
    /**
     * Clear every cron event this plugin owns on the current site.
     *
     * @return void
     */
    function cronvitals_unschedule_all() {
        $hooks = array(
            CRONVITALS_CRON_SCAN,
            CRONVITALS_CRON_LICENSE,
            CRONVITALS_CRON_SUMMARY,
            CRONVITALS_CRON_HEARTBEAT
        );
        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            while ( false !== $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
                $timestamp = wp_next_scheduled( $hook );
            }
        }
    }

    /**
     * Uninstall cleanup.
     *
     * Runs on Freemius's `after_uninstall` action (registered above) rather than a
     * standalone uninstall.php, which Freemius manages and disallows. Drops the log
     * table and removes every option/transient/meta and cron event this plugin
     * created. Freemius cleans up its own data separately.
     *
     * @return void
     */
    function cronvitals_uninstall_cleanup() {
        if ( is_multisite() ) {
            // Every site got its own table and options, so every site needs
            // cleaning. Uninstall is a one-off, deliberate action, so this walks
            // the whole network rather than leaving orphaned tables behind.
            $site_ids = get_sites( array(
                'fields' => 'ids',
                'number' => 0,
            ) );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                cronvitals_uninstall_site();
                restore_current_blog();
            }
            return;
        }
        cronvitals_uninstall_site();
    }

    /**
     * Remove this plugin's footprint from the current site.
     *
     * @return void
     */
    function cronvitals_uninstall_site() {
        global $wpdb;
        // Table name is a plugin constant, not user input; $wpdb->query does not
        // accept identifiers as prepared placeholders.
        $table_name = $wpdb->prefix . CRONVITALS_LOG_TABLE;
        $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $options = array(
            CRONVITALS_OPTION_SETTINGS,
            CRONVITALS_OPTION_LICENSE,
            CRONVITALS_OPTION_DB_VERSION,
            CRONVITALS_OPTION_DISMISSED,
            'cronvitals_active_problems',
            'cronvitals_heartbeat_status',
            'cronvitals_review_prompt'
        );
        foreach ( $options as $option ) {
            delete_option( $option );
        }
        // Sweep the transients created at runtime: the per-hook alert cool-downs
        // (cronvitals_alert_*) and the per-user admin notices (cronvitals_notice_*).
        // There is no API to delete transients by prefix, so this goes direct.
        // Note: with a persistent object cache these live in the cache rather than
        // the options table; they are short-lived and expire on their own there.
        $wpdb->query( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare( 
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->esc_like( '_transient_cronvitals_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_cronvitals_' ) . '%'
             )
         );
        // Per-user screen option for the logs table.
        delete_metadata(
            'user',
            0,
            'cronvitals_logs_per_page',
            '',
            true
        );
        cronvitals_unschedule_all();
    }

    /**
     * Register the custom cron schedules the plugin relies on.
     *
     * Registered on the filter (not only at activation) so the schedule is always
     * available to wp_next_scheduled/wp_schedule_event during normal runtime.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    function cronvitals_cron_schedules(  $schedules  ) {
        if ( !isset( $schedules['cronvitals_five_minutes'] ) ) {
            $schedules['cronvitals_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes (CronVitals)', 'cronvitals' ),
            );
        }
        // Heartbeat runs on its own configurable interval. wp_reschedule_event
        // reads this on each run, so changing the setting takes effect next cycle.
        $minutes = (int) CronVitals::get_setting( 'heartbeat_interval', 5 );
        $minutes = max( 1, min( 60, $minutes ) );
        if ( !isset( $schedules['cronvitals_heartbeat'] ) ) {
            $schedules['cronvitals_heartbeat'] = array(
                'interval' => $minutes * MINUTE_IN_SECONDS,
                'display'  => __( 'CronVitals Heartbeat interval', 'cronvitals' ),
            );
        }
        return $schedules;
    }

    add_filter( 'cron_schedules', 'cronvitals_cron_schedules' );
    /**
     * Boot the plugin.
     */
    CronVitals::instance();
}