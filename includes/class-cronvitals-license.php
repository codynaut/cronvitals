<?php
/**
 * License — Pro-tier gating, backed by the Freemius SDK.
 *
 * Freemius owns license activation, validation, renewals, and Pro update
 * delivery. This class is a thin adapter so the rest of the plugin can keep
 * asking a simple question (`is_pro()`) without knowing anything about
 * Freemius. Every accessor is defensive: if the SDK isn't available for any
 * reason, we fall back to the free tier rather than erroring.
 *
 * Note: the free build enforces no feature caps — core monitoring, unlimited
 * jobs, and full history are available to everyone. `is_pro()` only unlocks
 * the physically-separate premium channels (Slack/webhook/heartbeat/AS/CSV).
 *
 * @package CronVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CronVitals_License.
 */
class CronVitals_License {

	/**
	 * Register hooks. Nothing to do — Freemius handles the license lifecycle.
	 *
	 * @return void
	 */
	public function hooks() {}

	/**
	 * Whether the Pro tier is unlocked (active paid license or trial).
	 *
	 * @return bool
	 */
	public function is_pro() {
		return function_exists( 'cronvitals_fs' ) && cronvitals_fs()->can_use_premium_code();
	}

	/**
	 * Upgrade/pricing URL — the Freemius in-plugin checkout.
	 *
	 * Points at the trial whenever one is still available, because the plain
	 * upgrade URL renders four "Upgrade Now" buttons and no trial offer at all.
	 * Freemius only starts promoting the trial 24 hours after activation (see
	 * _add_trial_notice() in the SDK), and until it does, nothing appends the
	 * `trial=true` the pricing page needs. The readme advertises a no-card
	 * trial, so a visitor who comes looking on day one has to be able to find
	 * it rather than being shown a price list.
	 *
	 * @return string
	 */
	public function purchase_url() {
		if ( ! function_exists( 'cronvitals_fs' ) ) {
			return admin_url( 'tools.php?page=cronvitals' );
		}

		$fs = cronvitals_fs();

		if ( ! $this->is_trial_available() ) {
			return $fs->get_upgrade_url();
		}

		return $fs->pricing_url( WP_FS__PERIOD_ANNUALLY, true );
	}

	/**
	 * Whether a free trial can still be started on this site.
	 *
	 * Someone already paying, already in a trial, or who has used their trial
	 * should see the normal checkout — offering it again is noise.
	 *
	 * @return bool
	 */
	public function is_trial_available() {
		if ( ! function_exists( 'cronvitals_fs' ) ) {
			return false;
		}

		$fs = cronvitals_fs();

		return ! $fs->is_paying_or_trial() && ! $fs->is_trial_utilized();
	}
}
