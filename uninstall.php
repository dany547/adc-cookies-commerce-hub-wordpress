<?php
/**
 * Uninstall script.
 *
 * Keeps the 1.0 behaviour: the legacy consent-message option is removed and
 * rewrite rules flushed; user meta and order meta are kept by default (GDPR).
 * Additionally (2.0), when the site opted in via
 * `consent.delete_data_on_uninstall` (adc_settings_consent), ADC_Installer::uninstall()
 * wipes the two plugin tables, all plugin options and transients, scheduled hooks,
 * and the plugin's user and order meta.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('adc_marketing_consent_message');

// Flush rewrite rules
flush_rewrite_rules();

// Note: user meta and order meta are kept unless the site opted in below.
// This preserves consent records for GDPR compliance by default.

// --- 2.0 opt-in data wipe (tables, options, transients, schedules, user and order meta) ---
$adc_consent_settings = get_option('adc_settings_consent', array());
$adc_wipe_enabled     = is_array($adc_consent_settings)
    && !empty($adc_consent_settings['delete_data_on_uninstall']);

if ($adc_wipe_enabled) {
    require_once __DIR__ . '/includes/core/class-adc-autoloader.php';
    ADC_Autoloader::register();

    if (class_exists('ADC_Installer')) {
        ADC_Installer::uninstall();
    }
}
