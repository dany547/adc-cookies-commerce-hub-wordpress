<?php
/**
 * Google Tag Manager settings, snippet and sGTM loader URL (docs/ARCHITECTURE.md §2.3, §4).
 *
 * Owns the `gtm` settings group (container_id, mode, server_container_url,
 * datalayer_name, env_auth, env_preview). The snippet is printed by
 * ADC_Datalayer::print_head() AFTER the consent-default inline script so
 * Consent Mode defaults always precede gtm.js (§3.1).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GTM container helpers: settings group, head snippet, noscript, loader URL.
 */
final class ADC_Gtm implements ADC_Module_Interface
{
    /**
     * Load the container on every page, right after the consent defaults.
     */
    public const MODE_ALWAYS = 'always_with_defaults';

    /**
     * Load the container from datalayer.js after the first non-necessary grant.
     */
    public const MODE_AFTER = 'after_consent';

    /**
     * Boot the module: settings register even under the kill switch (the
     * admin tab must keep working), output hooks do not.
     */
    public static function boot(): void
    {
        add_action('adc_register_settings', array(self::class, 'register_settings'));

        if (self::disabled()) {
            return;
        }

        add_action('wp_body_open', array(self::class, 'print_noscript'));
        add_filter('adc_frontend_config', array(self::class, 'filter_config'));
    }

    /**
     * Whether all tracking output is killed via the constant kill-switch.
     *
     * @return bool
     */
    public static function disabled(): bool
    {
        return defined('ADC_MARKETING_DISABLE_TRACKING') && ADC_MARKETING_DISABLE_TRACKING;
    }

    /**
     * Default values for the `gtm` group (§4).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return array(
            'container_id'         => '',
            'mode'                 => self::MODE_ALWAYS,
            'server_container_url' => '',
            'datalayer_name'       => 'dataLayer',
            'env_auth'             => '',
            'env_preview'          => '',
        );
    }

    /**
     * Register the `gtm` settings group.
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group('gtm', self::defaults(), array(self::class, 'sanitize'));
    }

    /**
     * Sanitise a full `gtm` group input (complete, typed array returned).
     *
     * @param array $input   Raw input.
     * @param array $current Currently stored values.
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, array $current): array
    {
        $out = array_merge(self::defaults(), $current);

        $id = strtoupper(trim(sanitize_text_field((string) ($input['container_id'] ?? ''))));
        if ('' === $id || 1 === preg_match('/^GTM-[A-Z0-9]+$/', $id)) {
            $out['container_id'] = $id;
        } // Invalid input keeps the stored value (the admin tab adds a warning notice).

        $mode = sanitize_key((string) ($input['mode'] ?? ''));
        $out['mode'] = in_array($mode, array(self::MODE_ALWAYS, self::MODE_AFTER), true)
            ? $mode
            : self::MODE_ALWAYS;

        $url = esc_url_raw(trim((string) ($input['server_container_url'] ?? '')));
        if ('' === $url) {
            $out['server_container_url'] = '';
        } elseif (self::is_valid_https_url($url)) {
            $out['server_container_url'] = rtrim($url, '/');
        }

        $datalayer = sanitize_text_field((string) ($input['datalayer_name'] ?? ''));
        $out['datalayer_name'] = 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $datalayer)
            ? $datalayer
            : 'dataLayer';

        $out['env_auth']    = sanitize_text_field((string) ($input['env_auth'] ?? ''));
        $out['env_preview'] = sanitize_text_field((string) ($input['env_preview'] ?? ''));

        return $out;
    }

    /**
     * Configured container id ('' when unset/invalid).
     *
     * @return string
     */
    public static function container_id(): string
    {
        $id = (string) ADC_Settings::get('gtm', 'container_id', '');
        return 1 === preg_match('/^GTM-[A-Z0-9]+$/', $id) ? $id : '';
    }

    /**
     * Configured load mode.
     *
     * @return string
     */
    public static function mode(): string
    {
        $mode = (string) ADC_Settings::get('gtm', 'mode', self::MODE_ALWAYS);
        return in_array($mode, array(self::MODE_ALWAYS, self::MODE_AFTER), true) ? $mode : self::MODE_ALWAYS;
    }

    /**
     * dataLayer variable name (gtm.datalayer_name, default 'dataLayer').
     *
     * @return string
     */
    public static function datalayer_name(): string
    {
        $name = (string) ADC_Settings::get('gtm', 'datalayer_name', 'dataLayer');
        return 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : 'dataLayer';
    }

    /**
     * Public gtm.js URL including environment parameters (empty without a container).
     *
     * @return string
     */
    public static function snippet_url(): string
    {
        $id = self::container_id();
        if ('' === $id) {
            return '';
        }

        $url = 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode($id);

        $auth = sanitize_text_field((string) ADC_Settings::get('gtm', 'env_auth', ''));
        $env  = sanitize_text_field((string) ADC_Settings::get('gtm', 'env_preview', ''));
        if ('' !== $auth && '' !== $env) {
            $url .= '&gtm_auth=' . rawurlencode($auth);
            $url .= '&gtm_preview=' . rawurlencode($env);
            $url .= '&gtm_cookies_win=x';
        }

        return $url;
    }

    /**
     * First-party sGTM loader URL (§4/D20): the container's own domain serves
     * gtm.js when a server_container_url is configured; otherwise the public
     * googletagmanager.com URL. Used by ADC_Integration_Sgtm (C7).
     *
     * @return string
     */
    public static function loader_url(): string
    {
        $id = self::container_id();
        if ('' === $id) {
            return '';
        }

        $server = self::server_container_url();
        if ('' !== $server) {
            $url = $server . '/gtm.js?id=' . rawurlencode($id);

            $auth = sanitize_text_field((string) ADC_Settings::get('gtm', 'env_auth', ''));
            $env  = sanitize_text_field((string) ADC_Settings::get('gtm', 'env_preview', ''));
            if ('' !== $auth && '' !== $env) {
                $url .= '&gtm_auth=' . rawurlencode($auth);
                $url .= '&gtm_preview=' . rawurlencode($env);
                $url .= '&gtm_cookies_win=x';
            }

            return $url;
        }

        return self::snippet_url();
    }

    /**
     * Validated sGTM server container URL ('' when unset/invalid).
     *
     * @return string
     */
    public static function server_container_url(): string
    {
        $url = (string) ADC_Settings::get('gtm', 'server_container_url', '');
        return self::is_valid_https_url($url) ? $url : '';
    }

    /**
     * Print the GTM snippet in <head> (mode=always_with_defaults only;
     * after_consent containers are loaded by datalayer.js on first grant).
     * Consent-default markup printed by ADC_Datalayer::print_head() precedes
     * this call, guaranteeing the required push order (§2.4, §3.1).
     */
    public static function print_snippet(): void
    {
        if (self::disabled() || self::MODE_ALWAYS !== self::mode()) {
            return;
        }

        $id  = self::container_id();
        $url = self::snippet_url();
        if ('' === $id || '' === $url) {
            return;
        }

        $name = self::datalayer_name();
        $src  = 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode($id);
        $auth = sanitize_text_field((string) ADC_Settings::get('gtm', 'env_auth', ''));
        $env  = sanitize_text_field((string) ADC_Settings::get('gtm', 'env_preview', ''));
        if ('' !== $auth && '' !== $env) {
            $src .= '&gtm_auth=' . rawurlencode($auth);
            $src .= '&gtm_preview=' . rawurlencode($env);
            $src .= '&gtm_cookies_win=x';
        }

        // CSP-nonce friendly (§6): all interpolation through wp_json_encode,
        // printed via wp_print_inline_script_tag.
        $js = '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":'
            . 'new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],'
            . 'j=d.createElement(s),dl=l!=="dataLayer"?"&l="+l:"";j.async=true;j.src='
            . wp_json_encode($src, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
            . 'f.parentNode.insertBefore(j,f);})(window,document,"script",'
            . wp_json_encode($name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ','
            . wp_json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';

        wp_print_inline_script_tag($js);
    }

    /**
     * GTM <noscript> fallback iframe in <body> (wp_body_open).
     */
    public static function print_noscript(): void
    {
        if (self::disabled() || self::MODE_ALWAYS !== self::mode()) {
            return;
        }

        $id = self::container_id();
        if ('' === $id) {
            return;
        }
        ?>
        <noscript>
            <iframe src="<?php echo esc_url('https://www.googletagmanager.com/ns.html?id=' . rawurlencode($id)); ?>"
                    height="0" width="0"
                    style="display:none;visibility:hidden"
                    title="<?php echo esc_attr__('Google Tag Manager', 'adc-marketing'); ?>"></iframe>
        </noscript>
        <?php
    }

    /**
     * Add the C4-owned `gtm` key to window.adcConfig (§2.3).
     *
     * @param array $config Frontend config.
     * @return array
     */
    public static function filter_config(array $config): array
    {
        $config['gtm'] = array(
            'id'           => self::container_id(),
            'mode'         => self::mode(),
            'serverUrl'    => self::server_container_url(),
            'datalayerName' => self::datalayer_name(),
            'snippetUrl'   => self::snippet_url(),
        );

        return $config;
    }

    /**
     * https-only + wp_http_validate_url (SSRF guard at save time, §6).
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    private static function is_valid_https_url(string $url): bool
    {
        if ('' === $url || !str_starts_with($url, 'https://')) {
            return false;
        }

        return (bool) wp_http_validate_url($url);
    }
}
