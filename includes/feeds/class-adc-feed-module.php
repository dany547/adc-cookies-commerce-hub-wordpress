<?php
/**
 * Feed module bootstrap and settings registration.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Bootstraps the WooCommerce-dependent feed foundation. */
final class ADC_Feed_Module implements ADC_Module_Interface
{
    /** Action Scheduler group reserved for feed generation jobs. */
    public const AS_GROUP = 'adc-feeds';

    /** Determine whether WooCommerce has loaded. */
    public static function woocommerce_active(): bool
    {
        return class_exists('WooCommerce');
    }

    /** Report XML runtime, storage writeability, and informational HTTP probe status. */
    public static function requirements(): array
    {
        $directory = class_exists('ADC_Feed_Store') ? ADC_Feed_Store::prepare_storage_directory() : '';
        $probe = class_exists('ADC_Feed_Store')
            ? ADC_Feed_Store::storage_autotest()
            : array('status' => 'storage_unavailable', 'ok' => false, 'warning' => true, 'exposed' => false);

        $probe_label = match ($probe['status'] ?? '') {
            'storage_exposed' => __('Verificare informativă: testul HTTP a returnat tokenul; protecția snapshot-urilor nu este confirmată.', 'adc-marketing'),
            'storage_guard_executed' => __('Verificare informativă: testul HTTP nu a returnat tokenul; protecția nu este confirmată complet.', 'adc-marketing'),
            'storage_probe_unavailable' => __('Verificare informativă, neconfirmată: testul HTTP nu a putut fi finalizat.', 'adc-marketing'),
            default => __('Verificare informativă, neconfirmată: testul HTTP nu este disponibil.', 'adc-marketing'),
        };

        $wc_version = defined('WC_VERSION') ? (string) WC_VERSION : '';
        if ('' === $wc_version && isset($GLOBALS['woocommerce']) && is_object($GLOBALS['woocommerce'])) {
            $wc_version = (string) ($GLOBALS['woocommerce']->version ?? '');
        }

        return array(
            array('code' => 'woocommerce_active', 'label' => __('WooCommerce este activ.', 'adc-marketing'), 'ok' => self::woocommerce_active()),
            array('code' => 'woocommerce_version', 'label' => __('WooCommerce 9.5 sau o versiune mai nouă este disponibil.', 'adc-marketing'), 'ok' => '' !== $wc_version && version_compare($wc_version, '9.5', '>=')),
            array('code' => 'xmlreader', 'label' => __('Extensia PHP XMLReader este disponibilă.', 'adc-marketing'), 'ok' => class_exists('XMLReader')), 
            array('code' => 'xmlwriter', 'label' => __('Extensia PHP XMLWriter este disponibilă.', 'adc-marketing'), 'ok' => class_exists('XMLWriter')),
            array('code' => 'storage_writable', 'label' => __('Directorul persistent adc-feeds poate fi scris.', 'adc-marketing'), 'ok' => '' !== $directory && is_writable($directory)),
            array(
                'code' => 'storage_autotest',
                'status' => (string) ($probe['status'] ?? 'storage_probe_unavailable'),
                'label' => $probe_label,
                'ok' => true,
                'warning' => (bool) ($probe['warning'] ?? false),
            ),
        );
    }

    /** Register feed hooks only when WooCommerce is available. */
    public static function boot(): void
    {
        if (!self::woocommerce_active()) {
            return;
        }

        add_action('adc_register_settings', array(self::class, 'register_settings'));
        add_action('plugins_loaded', array(ADC_Feed_Schema::class, 'maybe_upgrade'), 25);
        ADC_Feed_Scheduler::boot();
        if (class_exists('ADC_Feed_Rest')) {
            ADC_Feed_Rest::boot();
        }
        ADC_Feed_Delivery::boot();
    }

    /** Register the opt-in feed settings group. */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'feeds',
            array(
                'enabled'    => false,
                'chunk_size' => 200,
            ),
            array(self::class, 'sanitize_settings')
        );
    }

    /**
     * Sanitize the complete settings group.
     *
     * @param array<string,mixed> $input Raw group values.
     * @param array<string,mixed> $current Current complete values.
     * @return array{enabled:bool,chunk_size:int}
     */
    public static function sanitize_settings(array $input, array $current): array
    {
        $merged = array_merge(
            array('enabled' => false, 'chunk_size' => 200),
            $current,
            $input
        );
        $enabled = in_array($merged['enabled'], array(true, 1, '1', 'yes', 'on'), true);
        $chunk_size = filter_var($merged['chunk_size'], FILTER_VALIDATE_INT);
        $chunk_size = false === $chunk_size ? 200 : max(100, min(500, $chunk_size));

        return array(
            'enabled'    => $enabled,
            'chunk_size' => $chunk_size,
        );
    }
}
