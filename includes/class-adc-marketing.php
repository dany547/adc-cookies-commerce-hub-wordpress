<?php
/**
 * Main plugin class
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main ADC Marketing class: legacy boot (1.0 behaviour, unchanged) +
 * 2.0 module registry (docs/ARCHITECTURE.md §1.1).
 */
final class ADC_Marketing
{
    /**
     * Plugin instance
     *
     * @var ADC_Marketing|null
     */
    private static ?ADC_Marketing $instance = null;

    /**
     * Get plugin instance
     *
     * @return ADC_Marketing
     */
    public static function get_instance(): ADC_Marketing
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies(): void
    {
        require_once ADC_MARKETING_PLUGIN_DIR . 'includes/functions.php';
        require_once ADC_MARKETING_PLUGIN_DIR . 'includes/class-adc-gdpr-compliance.php';
        require_once ADC_MARKETING_PLUGIN_DIR . 'includes/class-adc-consent-checkout.php';
        require_once ADC_MARKETING_PLUGIN_DIR . 'includes/class-adc-consent-admin.php';
        require_once ADC_MARKETING_PLUGIN_DIR . 'includes/class-adc-myaccount-marketing.php';
        require_once ADC_MARKETING_PLUGIN_DIR . 'includes/class-adc-marketing-export.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void
    {
        // Initialize GDPR compliance first
        ADC_GDPR_Compliance::get_instance();

        // Initialize components
        ADC_Consent_Checkout::get_instance();
        ADC_Consent_Admin::get_instance();
        ADC_MyAccount_Marketing::get_instance();
        ADC_Marketing_Export::get_instance();

        // Boot the 2.0 modules that exist yet (others are created by later chunks).
        self::boot_modules();
    }

    /**
     * Boot every known 2.0 module class that exists and implements
     * ADC_Module_Interface. Classes from chunks not built yet are skipped
     * silently (class_exists guards; no fatal when missing).
     */
    private static function boot_modules(): void
    {
        $modules = array(
            // C1 — core + admin shell.
            'ADC_Settings',
            'ADC_Installer',
            'ADC_Admin_Shell',
            // C2 — consent engine.
            'ADC_Consent',
            // C3 — banner UI.
            'ADC_Consent_Banner',
            'ADC_Admin_Tab_Banner',
            // C4 — dataLayer & events.
            'ADC_Datalayer',
            'ADC_Gtm',
            'ADC_Event_Builder',
            'ADC_Event_Hooks',
            'ADC_Pending_Events',
            'ADC_Admin_Tab_Events',
            // C5 — pipeline, matching, logs.
            'ADC_Dispatcher',
            'ADC_Outbox',
            'ADC_Tracking_Rest',
            'ADC_Advanced_Matching',
            'ADC_Order_Context',
            'ADC_Retention',
            'ADC_Admin_Tab_Matching',
            'ADC_Admin_Tab_Logs',
            // C6 — Meta + TikTok.
            'ADC_Integration_Meta',
            'ADC_Integration_Tiktok',
            'ADC_Admin_Tab_Meta',
            'ADC_Admin_Tab_Tiktok',
            // C7 — GA4 + sGTM.
            'ADC_Integration_Ga4',
            'ADC_Integration_Sgtm',
            'ADC_Admin_Tab_Ga4',
            'ADC_Admin_Tab_Sgtm',
            // C8 — legacy compat.
            'ADC_Channel_Consent',
            'ADC_Admin_Tab_Marketing',
            // Operational analytics (PRIVACY_ANALYTICS.md): commerce reporting
            // (gate + ack, default OFF), inert traffic/interactions, retention,
            // schema lifecycle; boots its own submodules.
            'ADC_Operational_Analytics',
            // Self-hosted updates from GitHub Releases (README.md "Release
            // pipeline'). Boots only once class-adc-github-updater.php lands;
            // the class_exists guard above makes this entry safe meanwhile.
            'ADC_Github_Updater',
        );

        foreach ($modules as $module) {
            if (!class_exists($module)) {
                continue; // Not built yet (or removed) — boot only what exists.
            }

            if (is_subclass_of($module, ADC_Module_Interface::class)) {
                $module::boot();
            }
        }
    }
}

