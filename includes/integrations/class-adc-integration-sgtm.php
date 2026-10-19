<?php
/**
 * Server-side GTM integration: first-party transport + optional MP forward
 * (docs/ARCHITECTURE.md §1.6, §2.1; §9 D20).
 *
 * Contract refs: D20 — `mode=loader` (default) only changes the GTM/gtag
 * transport (first-party loader URL via ADC_Gtm::loader_url(), exposed to
 * the browser through pixels.sgtm); `mode=mp_forward` (EXPERIMENTAL, off by
 * default) sends the GA4 MP payloads to the server container instead of the
 * public Google endpoint and supersedes the `ga4` server sends. PLATFORMS
 * §3.7 verifies only the gtag `server_container_url` transport (GA4 client
 * pre-installed in the container); sending MP payloads to the container root
 * is not documented there — mp_forward stays behind an explicit opt-in with
 * an admin warning.
 *
 * Feature inert unless a server_container_url is supplied: is_enabled()
 * requires a valid https URL (own `sgtm` group, falling back to the `gtm`
 * group's server_container_url). If the URL is removed later,
 * ADC_Dispatcher::process_job() re-checks is_enabled() ⇒ skipped_disabled.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side GTM integration (transport loader + experimental MP forward).
 */
final class ADC_Integration_Sgtm implements ADC_Integration_Interface, ADC_Module_Interface
{
    /**
     * GA4 MP backdating limit, shared with the ga4 integration (D4).
     */
    private const MAX_EVENT_AGE = 72 * HOUR_IN_SECONDS;

    /**
     * Boot the module: settings group, pipeline registration and the
     * front-end pixels.sgtm config.
     */
    public static function boot(): void
    {
        add_action('adc_register_settings', array(self::class, 'register_settings'));
        add_filter('adc_integrations', array(self::class, 'register_integration'));
        add_filter('adc_frontend_config', array(self::class, 'frontend_config'));
    }

    /**
     * Register the `sgtm` settings group (§4; api_secret sealed at rest).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'sgtm',
            array(
                'enabled'              => false,
                'server_container_url' => '',
                'mode'                 => 'loader', // loader | mp_forward (experimental).
                'api_secret'           => '',
                'measurement_id'       => '',
            ),
            array(self::class, 'sanitize_settings'),
            array('api_secret')
        );
    }

    /**
     * `adc_integrations` filter callback.
     *
     * @param string[] $classes Integration class names.
     * @return string[]
     */
    public static function register_integration(array $classes): array
    {
        $classes[] = self::class;

        return $classes;
    }

    /**
     * Sanitizer: fn(array $input, array $current): array (complete, typed).
     * SSRF guard at save (§6): https only + wp_http_validate_url, no path
     * normalisation beyond trailing-slash trimming. api_secret passes
     * through raw; ADC_Settings::update seals it.
     *
     * @param array $input   Raw input.
     * @param array $current Current stored values.
     * @return array
     */
    public static function sanitize_settings(array $input, array $current): array
    {
        $url = esc_url_raw(trim((string) ($input['server_container_url'] ?? '')));
        if ('' === $url) {
            $url = '';
        } elseif (!self::is_valid_https_url($url)) {
            $url = (string) ($current['server_container_url'] ?? ''); // Invalid input keeps the stored value.
        } else {
            $url = rtrim($url, '/');
        }

        $mode = (string) ($input['mode'] ?? '');

        $mid = strtoupper(trim(sanitize_text_field((string) ($input['measurement_id'] ?? ''))));
        if ('' !== $mid && 1 !== preg_match('/^G-[A-Z0-9]+$/', $mid)) {
            $mid = ''; // Falls back to ga4.measurement_id.
        }

        return array(
            'enabled'              => !empty($input['enabled']),
            'server_container_url' => $url,
            'mode'                 => in_array($mode, array('loader', 'mp_forward'), true) ? $mode : 'loader',
            'api_secret'           => trim((string) ($input['api_secret'] ?? '')),
            'measurement_id'       => $mid,
        );
    }

    /**
     * Platform id (also the settings group).
     */
    public static function id(): string
    {
        return 'sgtm';
    }

    /**
     * Admin label (RO).
     */
    public function label(): string
    {
        return __('Server GTM', 'adc-marketing');
    }

    /**
     * Consent category required for server sends (the container carries
     * GA4 events ⇒ statistics). In `loader` mode nothing is sent from the
     * server, so the gate is never reached.
     */
    public function required_category(): string
    {
        return 'statistics';
    }

    /**
     * Feature inert unless enabled AND a valid server container URL exists
     * (own group, falling back to the gtm group — D20: never guess a URL).
     */
    public function is_enabled(): bool
    {
        return (bool) ADC_Settings::get('sgtm', 'enabled', false)
            && '' !== self::server_container_url();
    }

    /**
     * D20: only `mp_forward` replaces the GA4 server sends; the default
     * `loader` mode is pure transport and keeps ga4 MP independent.
     *
     * @return string[]
     */
    public function supersedes(): array
    {
        if (!$this->is_enabled() || !self::is_mp_forward()) {
            return array();
        }

        return array('ga4');
    }

    /**
     * Canonical events handled server-side. `loader` mode handles nothing
     * (transport only); `mp_forward` mirrors the GA4 MP catalogue with the
     * same D6 purchase-channel rule.
     *
     * @param string $event_name Canonical event name.
     * @return bool
     */
    public function supports(string $event_name): bool
    {
        if (!self::is_mp_forward()) {
            return false; // Transport-only mode: no server events at all.
        }

        $ga4 = new ADC_Integration_Ga4();

        return $ga4->supports($event_name);
    }

    /**
     * Map + HTTP (no consent/retry logic here). `mp_forward` posts the
     * standard GA4 MP body to the server container root — the container's
     * pre-installed GA4 client accepts Measurement Protocol payloads
     * (EXPERIMENTAL per D20: not verified in PLATFORMS; admin opt-in with
     * warning). `loader` mode never sends.
     *
     * @param ADC_Event         $event   Canonical event.
     * @param ADC_Consent_State $consent Consent state re-checked at send time.
     * @return ADC_Send_Result
     */
    public function send(ADC_Event $event, ADC_Consent_State $consent): ADC_Send_Result
    {
        if (!self::is_mp_forward()) {
            return ADC_Send_Result::skipped('loader_mode');
        }

        if (!$this->is_enabled()) {
            return ADC_Send_Result::skipped('disabled');
        }

        if (!$consent->allows('statistics')) {
            return ADC_Send_Result::skipped('consent_denied');
        }

        // D4: same 72 h backdating guard as ga4 (the payload is GA4 MP).
        if ($event->time > 0 && (time() - $event->time) > self::MAX_EVENT_AGE) {
            return ADC_Send_Result::dead(0, 'event_too_old');
        }

        if ('purchase' === $event->name && 'server' !== ADC_Integration_Ga4::purchase_channel()) {
            return ADC_Send_Result::skipped('purchase_browser_channel');
        }

        $body = (new ADC_Integration_Ga4())->build_mp_payload($event, $consent);
        if (null === $body) {
            return ADC_Send_Result::skipped('no_client_id');
        }

        return $this->post($body);
    }

    /**
     * Admin "Test connection": in `mp_forward` mode one synthetic event is
     * posted to the container (HTTP code reported); `loader` mode has no
     * server sends, so the test reports the transport configuration instead
     * of firing an event.
     *
     * @return ADC_Send_Result
     */
    public function test(): ADC_Send_Result
    {
        $url = self::server_container_url();
        if ('' === $url) {
            return ADC_Send_Result::skipped('server_container_url_missing');
        }

        if (!self::is_mp_forward()) {
            // Loader mode: the first-party transport is exercised by the
            // browser, not the server. Report the configured loader URL so
            // the admin sees the wiring is complete.
            $loader = class_exists('ADC_Gtm') ? ADC_Gtm::loader_url() : '';

            return new ADC_Send_Result(
                'sent',
                200,
                '' !== $loader
                    ? sprintf(/* translators: %s: first-party loader URL. */ __('Transport configurat: %s', 'adc-marketing'), substr($loader, 0, 120))
                    : __('Transport configurat (fără container GTM — loader-ul public va fi folosit).', 'adc-marketing')
            );
        }

        $event = new ADC_Event(
            name: 'adc_connection_test',
            event_id: 'adc_test_' . bin2hex(random_bytes(8)),
            time: time(),
            origin: 'server',
            source_url: home_url('/'),
            currency: '',
            value: null,
            items: array(),
            order_id: 0,
            user: null,
            attribution: array('ga_client_id' => '999999999.999999999'),
            cid: '',
            consent: array(),
            params: array('method' => 'adc_test'),
        );

        $body = (new ADC_Integration_Ga4())->build_mp_payload($event, new ADC_Consent_State());
        if (null === $body) {
            return ADC_Send_Result::skipped('no_client_id');
        }

        return $this->post($body);
    }

    /**
     * Merged into adcConfig.pixels.sgtm — no secrets (§2.1). ga4.js direct
     * mode passes serverUrl as the gtag `server_container_url` (PLATFORMS
     * §3.7); loaderUrl is the first-party gtm.js URL from ADC_Gtm.
     *
     * @return array{mode: string, serverUrl?: string, loaderUrl?: string}
     */
    public function browser_config(): array
    {
        $url = self::server_container_url();
        if (!$this->is_enabled() || '' === $url) {
            return array('mode' => 'off');
        }

        $config = array(
            'mode'      => self::mode(),
            'serverUrl' => $url,
        );

        if (class_exists('ADC_Gtm')) {
            $loader = ADC_Gtm::loader_url();
            if ('' !== $loader) {
                $config['loaderUrl'] = $loader;
            }
        }

        return $config;
    }

    /**
     * `adc_frontend_config` filter: owns adcConfig.pixels.sgtm (§2.3).
     *
     * @param array $config Front-end config.
     * @return array
     */
    public static function frontend_config(array $config): array
    {
        $pixels         = isset($config['pixels']) && is_array($config['pixels']) ? $config['pixels'] : array();
        $pixels['sgtm'] = (new self())->browser_config();
        $config['pixels'] = $pixels;

        return $config;
    }

    /* ---------------------------------------------------------------------
     * Accessors.
     * ------------------------------------------------------------------- */

    /**
     * Configured mode: 'loader' (default, transport only) or 'mp_forward'
     * (experimental server sends).
     *
     * @return string
     */
    public static function mode(): string
    {
        $mode = (string) ADC_Settings::get('sgtm', 'mode', 'loader');

        return in_array($mode, array('loader', 'mp_forward'), true) ? $mode : 'loader';
    }

    /**
     * Whether the experimental MP-forward mode is active.
     *
     * @return bool
     */
    public static function is_mp_forward(): bool
    {
        return 'mp_forward' === self::mode();
    }

    /**
     * Validated server container URL: own `sgtm` value, falling back to the
     * `gtm` group (ADC_Gtm) so the two configured places never diverge at
     * send time. '' when unset/invalid.
     *
     * @return string
     */
    public static function server_container_url(): string
    {
        $own = (string) ADC_Settings::get('sgtm', 'server_container_url', '');
        if ('' !== $own) {
            return self::is_valid_https_url($own) ? $own : '';
        }

        return class_exists('ADC_Gtm') ? ADC_Gtm::server_container_url() : '';
    }

    /**
     * Measurement id for MP forward: own value, falling back to ga4 (§4).
     *
     * @return string
     */
    public static function measurement_id(): string
    {
        $own = (string) ADC_Settings::get('sgtm', 'measurement_id', '');
        if ('' !== $own && 1 === preg_match('/^G-[A-Z0-9]+$/', $own)) {
            return $own;
        }

        return ADC_Integration_Ga4::measurement_id();
    }

    /**
     * API secret for MP forward: own value, falling back to ga4's (§4:
     * "if container requires").
     *
     * @return string
     */
    public static function api_secret(): string
    {
        $own = ADC_Settings::secret('sgtm', 'api_secret');

        return '' !== $own ? $own : ADC_Settings::secret('ga4', 'api_secret');
    }

    /**
     * https-only + wp_http_validate_url (SSRF guard, §6).
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    public static function is_valid_https_url(string $url): bool
    {
        if ('' === $url || !str_starts_with($url, 'https://')) {
            return false;
        }

        return (bool) wp_http_validate_url($url);
    }

    /**
     * Send-time SSRF re-check (§6 "at save and at send"): re-resolve the
     * host and reject any answer containing a private, loopback,
     * link-local, CGNAT or otherwise reserved address (DNS rebinding
     * TOCTOU after the save-time wp_http_validate_url check). Both A
     * (gethostbynamel) and AAAA (dns_get_record) answers are inspected;
     * an IP-literal host must be public outright. A host that does not
     * resolve at all is left to the HTTP transport, which cannot connect
     * anyway and re-runs WP's reject_unsafe_urls validation.
     *
     * @param string $url Candidate URL (already save-time validated).
     * @return bool
     */
    private static function is_send_time_safe_url(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::ip_is_public($host);
        }

        $ips = gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (!self::ip_is_public($ip)) {
                    return false;
                }
            }
        }

        if (function_exists('dns_get_record')) {
            $rows = dns_get_record($host, DNS_AAAA);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $ip = isset($row['ipv6']) ? (string) $row['ipv6'] : '';
                    if ('' === $ip || !self::ip_is_public($ip)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Whether a resolved address is safe to connect to: public unicast
     * only (rejects loopback, private, link-local and reserved ranges via
     * the PHP filter flags, plus CGNAT 100.64.0.0/10 which they miss).
     *
     * @param string $ip IPv4 or IPv6 address.
     * @return bool
     */
    private static function ip_is_public(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $long = ip2long($ip);
        if (false !== $long && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
            return false; // CGNAT (RFC 6598) — not covered by the filter flags.
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * HTTP (EXPERIMENTAL — D20).
     * ------------------------------------------------------------------- */

    /**
     * POST the MP body to the server container root with the MP query
     * params. The URL never contains the secret in a logged context (the
     * URL itself is never logged). The container URL is re-validated at
     * send time (§6: "at save and at send") to close the DNS-rebinding
     * TOCTOU window of the save-time guard.
     *
     * @param array<string, mixed> $body MP request body.
     * @return ADC_Send_Result
     */
    private function post(array $body): ADC_Send_Result
    {
        $base = self::server_container_url();
        if ('' === $base || !self::is_send_time_safe_url($base)) {
            ADC_Logger::log('error', 'sGTM MP forward blocked: server container URL failed the send-time SSRF re-check');

            return ADC_Send_Result::dead(0, 'unsafe_server_container_url');
        }

        $query = array(
            'measurement_id' => self::measurement_id(),
            'api_secret'     => self::api_secret(),
        );

        $url  = $base . '/?' . http_build_query($query, '', '&');
        $json = wp_json_encode($body, JSON_UNESCAPED_UNICODE);

        $response = wp_remote_post(
            $url,
            array(
                'timeout'            => 5, // §6: 5 s for server-container calls.
                'redirection'        => 0,
                'sslverify'          => true,
                'blocking'           => true,
                'reject_unsafe_urls' => true, // wp_safe_remote_post equivalent: WP re-validates before connecting.
                'headers'            => array('Content-Type' => 'application/json'),
                'body'               => is_string($json) ? $json : '{}',
            )
        );

        return $this->interpret($response);
    }

    /**
     * Map the response to an ADC_Send_Result (429/5xx retry, 2xx sent).
     *
     * @param array<string, mixed>|WP_Error $response wp_remote_post result.
     * @return ADC_Send_Result
     */
    private function interpret($response): ADC_Send_Result
    {
        if (is_wp_error($response)) {
            ADC_Logger::log('warning', 'sGTM MP forward request failed: ' . $response->get_error_message());
            return ADC_Send_Result::retry(0, 'http_error');
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if (429 === $code || $code >= 500) {
            ADC_Logger::log('warning', 'sGTM MP forward retryable failure', array('http_code' => $code));
            return ADC_Send_Result::retry($code, 'http_' . $code);
        }

        if ($code < 200 || $code >= 300) {
            ADC_Logger::log('error', 'sGTM MP forward permanent failure', array('http_code' => $code));
            return ADC_Send_Result::dead($code, 'http_' . $code);
        }

        ADC_Logger::log('debug', 'sGTM MP forward send ok', array('http_code' => $code));
        return ADC_Send_Result::sent($code);
    }
}
