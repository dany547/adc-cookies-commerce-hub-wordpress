<?php
/**
 * Front-end configuration bootstrap (window.adcConfig).
 *
 * The base payload is cache-safe: identical HTML for all visitors (no nonces,
 * no user-specific data). Modules extend it via the `adc_frontend_config` filter.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds and attaches window.adcConfig.
 */
final class ADC_Frontend_Config
{
    /**
     * Handles that already received the inline config.
     *
     * @var array<string, bool>
     */
    private static array $attached = array();

    /**
     * Build the base configuration and apply the adc_frontend_config filter.
     *
     * @return array
     */
    public static function build(): array
    {
        $base = array(
            'version' => defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : '',
            'restUrl' => esc_url_raw(rest_url('adc/v1/')),
            'locale'   => get_locale(),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON',
        );

        $filtered = apply_filters('adc_frontend_config', $base);

        return is_array($filtered) ? $filtered : $base;
    }

    /**
     * Attach `window.adcConfig = {...};` before a registered script. Idempotent.
     *
     * @param string $script_handle Registered WP script handle.
     */
    public static function attach(string $script_handle): void
    {
        if (isset(self::$attached[$script_handle])) {
            return;
        }

        if (!wp_script_is($script_handle, 'registered')) {
            return; // Not registered yet — allow a later call to attach (idempotency preserved).
        }

        $json = wp_json_encode(self::build(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json) || '' === $json) {
            return;
        }

        wp_add_inline_script($script_handle, 'window.adcConfig = ' . $json . ';', 'before');
        self::$attached[$script_handle] = true;
    }
}
