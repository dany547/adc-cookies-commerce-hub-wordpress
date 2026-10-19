<?php
/**
 * Operational analytics module facade: single boot point and admin tab wiring.
 *
 * Booted by the plugin registry (includes/class-adc-marketing.php) during
 * `plugins_loaded`, so this works both on fresh activation and on an already
 * activated plugin (no re-activation required):
 * - ADC_Analytics_Schema      — table upgrade check on `plugins_loaded` 25.
 * - ADC_Analytics_Settings    — registers the `analytics` group for `init` 5.
 * - ADC_Analytics_Commerce    — WC hooks + async jobs (default OFF, gate + ack).
 * - ADC_Analytics_Interactions— inert (legal gates hard false this release).
 * - ADC_Analytics_Traffic     — inert (legal gate hard false this release).
 * - ADC_Analytics_Retention   — daily purge cron.
 * - ADC_Analytics_Csv         — admin-post export (cap + nonce).
 *
 * Commerce reporting is fully independent of traffic/CMP/pixels: enabling it
 * starts no banner, no cookie and no external transmission.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Boot facade for the operational analytics module.
 */
final class ADC_Operational_Analytics implements ADC_Module_Interface
{
	/**
	 * Boot every analytics submodule (idempotent, existence-guarded).
	 */
	public static function boot(): void
	{
		foreach (array(
			'ADC_Analytics_Schema',
			'ADC_Analytics_Settings',
			'ADC_Analytics_Commerce',
			'ADC_Analytics_Interactions',
			'ADC_Analytics_Traffic',
			'ADC_Analytics_Retention',
			'ADC_Analytics_Csv',
		) as $module) {
			if (class_exists($module) && is_subclass_of($module, ADC_Module_Interface::class)) {
				$module::boot();
			}
		}

		add_filter('adc_admin_tabs', array(self::class, 'register_tab'));
	}

	/**
	 * Add the analytics tab class to the admin shell tab list when the tab
	 * exists (the dashboard chunk may deliver it independently).
	 *
	 * @param string[] $tabs Tab class names.
	 * @return string[]
	 */
	public static function register_tab(array $tabs): array
	{
		if (!in_array('ADC_Admin_Tab_Analytics', $tabs, true) && class_exists('ADC_Admin_Tab_Analytics')) {
			$tabs[] = 'ADC_Admin_Tab_Analytics';
		}

		return $tabs;
	}

	/**
	 * Opt-in data wipe: drop analytics tables/options/schedules. Called by the
	 * existing uninstall path only (ADC_Installer::uninstall / Schema directly);
	 * without the site's explicit wipe opt-in nothing is deleted here.
	 */
	public static function uninstall(): void
	{
		if (class_exists('ADC_Analytics_Schema')) {
			ADC_Analytics_Schema::uninstall();
		}
	}
}
