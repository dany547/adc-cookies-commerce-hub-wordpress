<?php
/**
 * Secure server-side session and frontend mode for the visual event picker.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Provides short-lived, user-bound picker sessions and isolated frontend output. */
final class ADC_Picker implements ADC_Module_Interface
{
    public const QUERY_VAR = 'adc_picker';
    public const TTL = 600;

    private static ?bool $active = null;

    /** Register picker hooks; request conditions are evaluated only in callbacks. */
    public static function boot(): void
    {
        add_action('wp_ajax_adc_picker_token', array(self::class, 'ajax_token'));
        add_action('wp_ajax_adc_picker_revoke', array(self::class, 'ajax_revoke'));
        add_action('template_redirect', array(self::class, 'on_template_redirect'), 0);
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue'), 5);
        add_filter('show_admin_bar', array(self::class, 'filter_admin_bar'));
    }

    /** Whether admin and site URLs share the same scheme, host, and port. */
    public static function available(): bool
    {
        return self::origin((string) admin_url()) === self::origin((string) home_url());
    }

    /** Check the current request's token once, without exposing failure details. */
    public static function active(): bool
    {
        if (null !== self::$active) {
            return self::$active;
        }
        self::$active = false;
        if (!is_user_logged_in() || !current_user_can('manage_options') || !isset($_GET[self::QUERY_VAR]) || !is_string($_GET[self::QUERY_VAR])) {
            return false;
        }
        $token = (string) wp_unslash($_GET[self::QUERY_VAR]);
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }
        $record = get_transient('adc_picker_' . hash('sha256', $token));
        if (!is_array($record) || (int) ($record['exp'] ?? 0) <= time() || (int) ($record['uid'] ?? 0) !== get_current_user_id()) {
            return false;
        }
        self::$active = true;
        return true;
    }

    /** Issue a short-lived token to an authorized administrator. */
    public static function ajax_token(): void
    {
        check_ajax_referer('adc_custom_events', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nu aveți permisiunea necesară.', 'adc-marketing')), 403);
        }
        $uid = get_current_user_id();
        $key = 'adc_picker_rl_' . $uid;
        $count = (int) get_transient($key);
        if ($count >= 10) {
            wp_send_json_error(array('message' => __('Prea multe sesiuni de selecție. Încearcă din nou peste câteva minute.', 'adc-marketing')), 429);
        }
        set_transient($key, $count + 1, self::TTL);
        $token = bin2hex(random_bytes(16));
        set_transient('adc_picker_' . hash('sha256', $token), array('uid' => $uid, 'exp' => time() + self::TTL), self::TTL);
        wp_send_json_success(array('token' => $token, 'expires_in' => self::TTL));
    }

    /** Revoke a token belonging to the current administrator. */
    public static function ajax_revoke(): void
    {
        check_ajax_referer('adc_custom_events', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nu aveți permisiunea necesară.', 'adc-marketing')), 403);
        }
        $token = isset($_POST['token']) && is_string($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
        if (1 === preg_match('/^[a-f0-9]{32}$/', $token)) {
            $key = 'adc_picker_' . hash('sha256', $token);
            $record = get_transient($key);
            if (is_array($record) && (int) ($record['uid'] ?? 0) === get_current_user_id()) {
                delete_transient($key);
            }
        }
        wp_send_json_success(array('revoked' => true));
    }

    /** Apply privacy and framing headers only to a valid picker request. */
    public static function on_template_redirect(): void
    {
        if (!self::active()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
    }

    /** Enqueue available picker assets and provide the v1 frame configuration. */
    public static function enqueue(): void
    {
        if (!self::active()) {
            return;
        }
        $base = defined('ADC_MARKETING_PLUGIN_URL') ? rtrim((string) ADC_MARKETING_PLUGIN_URL, '/') . '/assets/' : '';
        $dir = defined('ADC_MARKETING_PLUGIN_DIR') ? rtrim((string) ADC_MARKETING_PLUGIN_DIR, '/\\') . '/assets/' : '';
        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $deps = array();
        foreach (array('picker-protocol.js', 'picker-selector.js', 'picker-frame.js') as $file) {
            $path = $dir . 'js/' . $file;
            if (!file_exists($path)) {
                continue;
            }
            $handle = 'adc-' . str_replace('.js', '', $file);
            wp_enqueue_script($handle, $base . 'js/' . $file, $deps, $version, array('in_footer' => true, 'strategy' => 'defer'));
            $deps[] = $handle;
        }
        $css = $dir . 'css/picker-frame.css';
        if (file_exists($css)) {
            wp_enqueue_style('adc-picker-frame', $base . 'css/picker-frame.css', array(), $version);
        }
        $config = array('panelOrigin' => self::origin((string) admin_url()));
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (is_string($json) && wp_script_is('adc-picker-frame', 'enqueued')) {
            wp_add_inline_script('adc-picker-frame', 'window.adcPickerFrameConfig = ' . $json . ';', 'before');
        }
    }

    /** Hide the admin bar only for the authorized picker view. */
    public static function filter_admin_bar(bool $show): bool
    {
        return self::active() ? false : $show;
    }

    /** Normalize a URL to its origin, including non-default ports. */
    private static function origin(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return strtolower((string) $parts['scheme'] . '://' . (string) $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : ''));
    }
}
