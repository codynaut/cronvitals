# CronVitals

Cron job monitoring for WordPress. It tells you when a WP-Cron job fails, misses its run or dies on a PHP fatal error, and shows you the error.

**Install:** [CronVitals on WordPress.org](https://wordpress.org/plugins/cronvitals/)

This repository holds the free plugin, the same build that ships on WordPress.org. The paid Pro features ship separately and are not included here. They are:
- Action Scheduler monitoring
- Slack and webhook alerts
- An external heartbeat
- CSV export
- A weekly summary email

## What it does

- **Logs every run.** It records each WP-Cron run with its scheduled time, actual run time, duration and result, keeps 30 days of history and gives each job a reliability score.
- **Catches fatal errors.** A shutdown handler catches PHP fatals that kill a cron request partway through, and records the message, file and line.
- **Flags missed runs.** A job that runs more than 15 minutes late counts as missed. The threshold can be changed.
- **Sends alerts.** It emails the configured address, at most once per job per hour, and shows a notice in the dashboard.
- **Only reads your schedule.** It never changes, reschedules or removes cron events. It works with page-load WP-Cron, with a real system cron and with `DISABLE_WP_CRON`.
- **Supports Multisite.** Each site gets its own table and settings.

## Code map

| File | Role |
|---|---|
| `cronvitals.php` | Bootstrap: constants, the Freemius SDK and component wiring |
| `includes/class-cronvitals-monitor.php` | Finds every WP-Cron event, wraps each monitored hook to time it and catch fatals, and scans for missed runs |
| `includes/class-cronvitals-logger.php` | The custom log table, with all queries prepared through `$wpdb` and 30-day pruning |
| `includes/class-cronvitals-alerts.php` | Turns detected problems into rate-limited emails and admin notices |
| `includes/class-cronvitals-license.php` | A thin adapter over Freemius, so the rest of the code only asks `is_pro()` |
| `admin/` | The Tools → CronVitals screen: settings and the jobs and logs list tables |

## Run from source

Requirements: WordPress 5.8+ and PHP 7.4+.

```sh
git clone https://github.com/codynaut/cronvitals.git
cd cronvitals
composer install   # pulls the Freemius SDK into vendor/
```

Copy the folder to `wp-content/plugins/cronvitals`, activate it, then open **Tools → CronVitals**.

## License

GPLv2 or later. See [LICENSE.txt](LICENSE.txt).
