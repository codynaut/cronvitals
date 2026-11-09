=== CronVitals – Cron Job Monitor & WP-Cron Failure Alerts ===
Contributors: codynaut
Tags: cron, wp-cron, cron jobs, missed schedule, action scheduler
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cron job monitoring for WordPress. Know when a WP-Cron job fails, misses its run, or dies on a PHP fatal, with the error and an email alert.

== Description ==

A scheduled post didn't publish. A subscription didn't renew. Stock didn't sync. WordPress never told you — WP-Cron fails silently, and by the time someone notices, it's a client email.

CronVitals is a monitor for WP-Cron. It watches every scheduled job on your site, records every run, and tells you when one breaks.

* **See the actual error, not just "something failed."** When a cron job dies on a PHP fatal, CronVitals catches it during shutdown and logs the message, file, and line — the thing that's normally lost because nobody was watching the request.
* **Catch jobs that never ran at all.** A configurable threshold (default 15 minutes past schedule) marks a job as missed, so a job that quietly stopped rescheduling shows up as a problem instead of just disappearing.
* **Answer "how long has this been broken?"** Every run is logged — job, scheduled time, actual run time, duration, pass/fail — with 30 days of history, a reliability score, and a per-job failure breakdown.

= How it works =

1. Activate. Every registered WP-Cron event is picked up automatically — no list to build, no jobs to register.
2. Set the alert email and the missed-run threshold under **Tools → CronVitals**. That's the whole setup.
3. You get an email (rate-limited, never a flood) and a dashboard notice when a job fails or runs late.

= What it actually does under the hood =

CronVitals attaches to each monitored hook at the outermost priorities to time the run, and registers a shutdown handler to catch fatals that kill the request mid-job. A separate five-minute scan compares each event's schedule against the clock to find misses. Runs are written to one custom table, pruned to 30 days. It does not modify, reschedule, or remove your cron events — it reads and records.

It works whether WP-Cron runs on page loads or a real system cron hits `wp-cron.php`, and it keeps working with `DISABLE_WP_CRON` set. Multisite-aware: each site gets its own table and settings, and sites created after network activation are set up automatically.

= Free vs Pro =

Everything above is free, on every site, with no caps — unlimited monitored jobs, full 30-day history, unlimited email alerts, and the reliability analytics.

Pro adds the alerting and integrations that teams need:

* **WooCommerce / Action Scheduler monitoring** — success and failure of Action Scheduler jobs, plus stalled-queue detection
* **Slack and generic webhooks** — point them at Discord, Teams, Telegram, PagerDuty, Zapier, or your own endpoint
* **External heartbeat** — your site pings an outside monitor (Healthchecks.io, Cronitor, BetterStack, or any URL) on an interval you set, so you're alerted even when the whole site is down. Health-aware mode signals failure when jobs break, not only when the server does.
* **CSV export** of the full run history
* **Weekly summary email**

Pro is $49 a year for a single site, $99 for 5, $199 for 25, and $349 for unlimited sites — with a 14-day trial that doesn't ask for a card.

= Privacy and requirements =

* The free plugin sends nothing anywhere. It starts in anonymous mode — no signup prompt on activation, no data to Freemius unless you opt in.
* Pro contacts Freemius for license activation and updates, and sends the heartbeat ping only to the URL you enter. Both are described in Third-Party Services below.
* Requires WordPress 5.8+ and PHP 7.4+. Multisite supported. Translation-ready.
* Uninstall drops the log table and removes every option, transient, and scheduled event it created.

== Third-Party Services ==

The free tier runs entirely on your server. The plugin connects to outside services only in the cases below.

1. **Freemius (licensing, updates & opt-in).** CronVitals uses Freemius, a third-party service, to handle Pro license activation, deliver Pro plugin updates, and process checkout. When you activate a Pro license or opt in, information such as your site URL, WordPress/PHP environment details, and (if you opt in) your admin email is sent to Freemius. Opt-in is optional and can be skipped; the free plugin functions without contacting Freemius until you choose to activate a license.
   * Service: https://freemius.com/
   * Terms of Service: https://freemius.com/terms/
   * Privacy Policy: https://freemius.com/privacy/

2. **External heartbeat (Pro, opt-in, off by default).** If you enable the heartbeat and provide a monitor URL, the plugin sends a periodic HTTP request to that URL so an outside service can alert you if your site stops responding. You choose the provider and the URL; no data beyond the ping (and, in health-aware mode, a success/fail signal) is sent. Common providers and their terms:
   * Healthchecks.io — https://healthchecks.io/ (terms: https://healthchecks.io/terms/, privacy: https://healthchecks.io/privacy/)
   * Cronitor — https://cronitor.io/ (terms: https://cronitor.io/terms, privacy: https://cronitor.io/privacy)
   * BetterStack — https://betterstack.com/ (terms: https://betterstack.com/terms, privacy: https://betterstack.com/privacy)

== Installation ==

1. Upload the `cronvitals` folder to `/wp-content/plugins/`, or install it through the Plugins screen in WordPress.
2. Activate the plugin.
3. Go to **Tools → CronVitals** to review your scheduled jobs and configure alerts.
4. (Optional) Activate a Pro license via the plugin's "Activate License" link to unlock Pro features.

For the most reliable monitoring, run WP-Cron from a real system cron: add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php` and schedule a server cron to hit `wp-cron.php`. CronVitals works either way, but a system cron gives deterministic scheduling.

== Frequently Asked Questions ==

= Will it slow down my site? =

No. Nothing runs on the front end. The monitoring adds two no-op hook callbacks per cron job during wp-cron.php requests, plus one scan every five minutes. Log writes are one row per cron run.

= How do I check whether WP-Cron is working? =

Activate CronVitals and open **Tools → CronVitals**. The Jobs tab lists every scheduled event with its last and next run, and the Overview tab shows how many runs succeeded, failed, or were missed. If jobs keep showing as missed, WP-Cron isn't being triggered: usually low traffic, `DISABLE_WP_CRON` set with no server cron to replace it, or a host blocking loopback requests to `wp-cron.php`.

= My scheduled post missed its schedule. Can CronVitals help? =

WordPress publishes scheduled posts through a WP-Cron event, so a "Missed schedule" post means that event didn't run on time. CronVitals won't publish the post for you, but it will email you when the event is missed and show whether it's one job or WP-Cron as a whole that has stopped, so you can fix the cause instead of republishing posts by hand.

= What's the difference between this and a cron manager like WP Crontrol? =

A cron manager lets you view, add, edit, and run events. CronVitals doesn't manage your events — it watches them over time, records every run, catches the PHP errors that kill them, and alerts you. They solve different problems and many sites run both.

= Does it work if WP-Cron is disabled or replaced by a system cron? =

Yes, and a real system cron gives you more accurate timing. CronVitals records runs however wp-cron.php is triggered, and the missed-job scan works either way.

= What happens to my data if I deactivate it? =

Deactivating only unschedules the plugin's own events — your log history and settings stay, so reactivating picks up where it left off. Uninstalling drops the log table and deletes every option and transient it created.

= Do I need an account or a license to use it? =

No. The free plugin works fully on activation with no account, no API key, and no signup prompt — it runs in anonymous mode and contacts nothing. A license is only needed for the Pro features.

= Does it send my data anywhere? =

The free version sends nothing. Pro contacts Freemius to validate your license and deliver updates, and — only if you turn it on and supply a URL — sends a periodic ping to the external monitor you chose. Job names, logs, and error messages never leave your database.

= Can it alert me if my entire site goes down? =

Yes, with the Pro external heartbeat. Because the alert comes from an outside monitor that expects a regular ping from your site, it fires even when WordPress itself can't run.

= Does it monitor WooCommerce jobs? =

WooCommerce runs most background work through Action Scheduler, which is a separate queue from WP-Cron. Pro monitors that queue, including stalled-queue detection. Free monitors the WP-Cron events WooCommerce registers.

== Screenshots ==

1. A failed job caught with the actual PHP fatal — message, file, and line — instead of silence.
2. Every scheduled job with its health status, next and last run, and a Run Now button.
3. Thirty days of run history, filterable by status, so you can see how long something's been broken.
4. Reliability score and per-job failure breakdown across the full log window.
5. The whole setup: where alerts go, and how long a job may run past schedule before it counts as missed.

== Changelog ==

= 1.0.1 =
* New: a one-time request for a WordPress.org review, shown only on the CronVitals screen, a week after you first open it and only once runs have been recorded. "Not now" asks again in 30 days; "Don't ask again" is permanent.
* New FAQ entries: how to check whether WP-Cron is working, and what to do about "Missed schedule" posts.
* Renamed to "CronVitals – Cron Job Monitor & WP-Cron Failure Alerts".
* Uninstall now also removes the review-request setting.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.1 =
Adds a one-time, dismissible review request on the CronVitals screen and two new FAQ entries. No changes to monitoring.

= 1.0.0 =
Initial release of CronVitals.
