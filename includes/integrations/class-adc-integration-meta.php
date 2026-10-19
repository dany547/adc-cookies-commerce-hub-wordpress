<?php
/**
 * Meta Conversions API (CAPI) + Meta Pixel integration (docs/ARCHITECTURE.md §1.6, §2.1).
 *
 * Contract refs: §9 D1 (shared event_id), D2 (Graph v26.0, Pixel/Dataset ID),
 * D3 (access_token as form field, one event per request), D4 (7-day age
 * guard), D11 (required minimums, no LDU/opt_out), D12 (Purchase value =
 * order total), D15 (pixel loaded only after marketing consent — JS side),
 * U1 (no refund — no standard Meta event).
 *
 * This class maps + sends only: consent gating and retries stay in
 * ADC_Dispatcher. Raw IP/UA/fbp/fbc are the documented CAPI exceptions and
 * are taken exclusively from the order-time attribution capture, which the
 * consent snapshot already gated (ADC_Order_Context).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta (Facebook) CAPI integration.
 */
final class ADC_Integration_Meta implements ADC_Integration_Interface, ADC_Module_Interface
{
    /**
     * Meta rejects any event older than 7 days (D4 / PLATFORMS §2.3).
     */
    private const MAX_EVENT_AGE = 7 * DAY_IN_SECONDS;

    /**
     * Canonical GA4 name → Meta CAPI event name (§9.1a, authoritative map).
     *
     * @var array<string, string>
     */
    private const EVENT_MAP = array(
        'view_item'        => 'ViewContent',
        'add_to_cart'      => 'AddToCart',
        'begin_checkout'   => 'InitiateCheckout',
        'add_payment_info' => 'AddPaymentInfo',
        'purchase'         => 'Purchase',
        'sign_up'          => 'CompleteRegistration',
        'generate_lead'    => 'Lead',
        'search'           => 'Search',
    );

    /**
     * Boot the module: settings group, pipeline registration, front-end
     * pixel config and the direct-mode pixel loader enqueue.
     */
    public static function boot(): void
    {
        add_action('adc_register_settings', array(self::class, 'register_settings'));
        add_filter('adc_integrations', array(self::class, 'register_integration'));
        add_filter('adc_frontend_config', array(self::class, 'frontend_config'));
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue_assets'));
    }

    /**
     * Register the `meta` settings group (§4; access_token sealed at rest).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'meta',
            array(
                'enabled_server'  => false,
                'browser_mode'    => self::default_browser_mode(),
                'pixel_id'        => '',
                'access_token'    => '',
                'test_event_code' => '',
                'api_version'     => 'v26.0', // D2: latest verified Graph version (PLATFORMS §2.1).
                'action_source'   => 'website',
            ),
            array(self::class, 'sanitize_settings'),
            array('access_token')
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
     * The access_token passes through raw here; ADC_Settings::update seals
     * it and keeps the stored value on empty input.
     *
     * @param array $input   Raw input.
     * @param array $current Current stored values.
     * @return array
     */
    public static function sanitize_settings(array $input, array $current): array
    {
        $mode = (string) ($input['browser_mode'] ?? '');

        $pixel = preg_replace('/[^0-9A-Za-z]/', '', (string) ($input['pixel_id'] ?? '')) ?? '';
        $test  = preg_replace('/[^0-9A-Za-z]/', '', (string) ($input['test_event_code'] ?? '')) ?? '';
        $ver   = (string) ($input['api_version'] ?? '');

        return array(
            'enabled_server'  => !empty($input['enabled_server']),
            'browser_mode'    => in_array($mode, array('off', 'gtm', 'direct'), true) ? $mode : 'off',
            'pixel_id'        => substr($pixel, 0, 30),
            'access_token'    => trim((string) ($input['access_token'] ?? '')),
            'test_event_code' => substr($test, 0, 40),
            'api_version'     => 1 === preg_match('/^v\d+\.\d+$/', $ver) ? $ver : 'v26.0',
            'action_source'   => 'website', // Fixed per D11.
        );
    }

    /**
     * Platform id (also the settings group).
     */
    public static function id(): string
    {
        return 'meta';
    }

    /**
     * Admin label (RO).
     */
    public function label(): string
    {
        return __('Meta (CAPI + Pixel)', 'adc-marketing');
    }

    /**
     * Consent category required before anything is sent or loaded.
     */
    public function required_category(): string
    {
        return 'marketing';
    }

    /**
     * Server sending enabled AND credentials present.
     */
    public function is_enabled(): bool
    {
        return (bool) ADC_Settings::get('meta', 'enabled_server', false)
            && '' !== (string) ADC_Settings::get('meta', 'pixel_id', '')
            && '' !== ADC_Settings::secret('meta', 'access_token');
    }

    /**
     * No supersedes: Meta is independent of GTM server containers.
     *
     * @return string[]
     */
    public function supersedes(): array
    {
        return array();
    }

    /**
     * Canonical events this integration handles. U1: no refund (Meta has no
     * standard refund event). page_view only when C4's server_page_view is
     * explicitly turned on (§9.1a).
     *
     * @param string $event_name Canonical event name.
     * @return bool
     */
    public function supports(string $event_name): bool
    {
        if ('page_view' === $event_name) {
            return (bool) ADC_Settings::get('events', 'server_page_view', false);
        }

        return isset(self::EVENT_MAP[$event_name]);
    }

    /**
     * Map + HTTP (no consent/retry logic here).
     *
     * @param ADC_Event         $event   Canonical event.
     * @param ADC_Consent_State $consent Consent state re-checked at send time.
     * @return ADC_Send_Result
     */
    public function send(ADC_Event $event, ADC_Consent_State $consent): ADC_Send_Result
    {
        if (!$this->is_enabled()) {
            return ADC_Send_Result::skipped('disabled');
        }

        // Defence in depth: never build anything without marketing consent.
        if (!$consent->allows('marketing')) {
            return ADC_Send_Result::skipped('consent_denied');
        }

        // D4: an event older than 7 days fails the whole request — dead
        // without HTTP instead.
        if ($event->time > 0 && (time() - $event->time) > self::MAX_EVENT_AGE) {
            return ADC_Send_Result::dead(0, 'event_too_old');
        }

        $payload = $this->build_event_payload($event);
        if (null === $payload) {
            return ADC_Send_Result::skipped('insufficient_user_data');
        }

        return $this->post(array($payload), '');
    }

    /**
     * Admin "Test connection": one synthetic Purchase routed through the
     * Test Events endpoint (test_event_code). Uses only synthetic hashed
     * values — never real customer data, and never sent without a test
     * event code (so nothing can leak into production statistics).
     *
     * @return ADC_Send_Result
     */
    public function test(): ADC_Send_Result
    {
        if ('' === (string) ADC_Settings::get('meta', 'pixel_id', '')) {
            return ADC_Send_Result::skipped('pixel_id_missing');
        }

        if ('' === ADC_Settings::secret('meta', 'access_token')) {
            return ADC_Send_Result::skipped('access_token_missing');
        }

        $test_code = (string) ADC_Settings::get('meta', 'test_event_code', '');
        if ('' === $test_code) {
            return ADC_Send_Result::skipped('test_event_code_missing');
        }

        $event = new ADC_Event(
            name: 'purchase',
            event_id: 'adc_test_' . bin2hex(random_bytes(8)),
            time: time(),
            origin: 'server',
            source_url: home_url('/'),
            currency: function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            value: 1.0,
            items: array(array('item_id' => 'adc-test', 'item_name' => 'ADC connection test', 'quantity' => 1, 'price' => 1.0)),
            order_id: 0,
            user: new ADC_User_Data(
                em: hash('sha256', 'adc-marketing-connection-test'),
                external_id: hash('sha256', 'adc-marketing-connection-test')
            ),
            attribution: array('user_agent' => 'ADC-Marketing-Test/2.0'),
            cid: '',
            consent: array(),
            params: array('order_total' => 1.0, 'method' => 'adc_test'),
        );

        $payload = $this->build_event_payload($event);

        return null === $payload
            ? ADC_Send_Result::skipped('insufficient_user_data')
            : $this->post(array($payload), $test_code);
    }

    /**
     * Merged into adcConfig.pixels.meta — no secrets (§2.1).
     *
     * @return array{mode: string, id?: string}
     */
    public function browser_config(): array
    {
        $mode = (string) ADC_Settings::get('meta', 'browser_mode', 'off');

        if (!in_array($mode, array('gtm', 'direct'), true)) {
            return array('mode' => 'off');
        }

        return array(
            'mode' => $mode,
            'id'   => (string) ADC_Settings::get('meta', 'pixel_id', ''),
        );
    }

    /**
     * `adc_frontend_config` filter: owns adcConfig.pixels.meta (§2.3).
     *
     * @param array $config Front-end config.
     * @return array
     */
    public static function frontend_config(array $config): array
    {
        $pixels       = isset($config['pixels']) && is_array($config['pixels']) ? $config['pixels'] : array();
        $pixels['meta'] = (new self())->browser_config();
        $config['pixels'] = $pixels;

        return $config;
    }

    /**
     * Enqueue the direct-mode pixel loader (assets/js/pixels/meta.js).
     * `off` is never enqueued; `gtm` loads it as a documented no-op so the
     * loader can stay registered (GTM owns the tag).
     */
    public static function enqueue_assets(): void
    {
        if (defined('ADC_MARKETING_DISABLE_TRACKING') && ADC_MARKETING_DISABLE_TRACKING) {
            return;
        }

        $mode = (string) ADC_Settings::get('meta', 'browser_mode', 'off');
        if (!in_array($mode, array('gtm', 'direct'), true)) {
            return;
        }

        // Deps resolve only to what exists today; adc-datalayer (C4) joins
        // automatically once registered.
        $deps = array();
        foreach (array('adc-datalayer', 'adc-consent') as $dep) {
            if (wp_script_is($dep, 'registered')) {
                $deps[] = $dep;
            }
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $url     = defined('ADC_MARKETING_PLUGIN_URL')
            ? rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/js/pixels/meta.js'
            : '';

        if ('' === $url) {
            return;
        }

        wp_enqueue_script('adc-pixel-meta', $url, $deps, $version, array('in_footer' => true, 'strategy' => 'defer'));
        ADC_Frontend_Config::attach('adc-pixel-meta');
    }

    /* ---------------------------------------------------------------------
     * Payload mapping (PLATFORMS §2.3–§2.6, §9 D11/D12).
     * ------------------------------------------------------------------- */

    /**
     * Build the single-event CAPI payload (one event per request. Meta drops
     * invalid events and accepts valid ones carrying an event_id; only an
     * event_time older than 7 days fails the whole request, D3).
     *
     * @param ADC_Event $event Canonical event.
     * @return array<string, mixed>|null Null when the identity data is
     *                                   below the Meta baseline (D11).
     */
    private function build_event_payload(ADC_Event $event): ?array
    {
        $user_data = $this->build_user_data($event);
        if (null === $user_data) {
            return null; // insufficient_user_data (D11) — no retry.
        }

        $payload = array(
            'event_name'       => self::EVENT_MAP[$event->name] ?? $event->name,
            'event_time'       => $event->time,
            'event_id'         => $event->event_id, // D1: shared with the browser push.
            'action_source'    => 'website',
            'event_source_url' => '' !== $event->source_url ? $event->source_url : home_url('/'),
            'user_data'        => $user_data,
        );

        $custom_data = $this->build_custom_data($event);
        if (array() !== $custom_data) {
            $payload['custom_data'] = $custom_data;
        }

        // D11: no data_processing_options (LDU) and no opt_out — LDU is a
        // US-state mechanism; absent marketing consent means no send at all.

        return $payload;
    }

    /**
     * user_data: hashed identity (only when the caller allowed marketing —
     * enforced by send()) plus the unhashed CAPI exceptions fbp/fbc/
     * client_ip_address/client_user_agent, all from the order-time capture.
     *
     * @param ADC_Event $event Canonical event.
     * @return array<string, string>|null Null below the D11 baseline.
     */
    private function build_user_data(ADC_Event $event): ?array
    {
        $out = array();

        if (null !== $event->user) {
            foreach ($event->user->to_array() as $key => $value) {
                if (is_string($value) && '' !== $value) {
                    $out[$key] = $value; // Already SHA-256 (Meta normalisation).
                }
            }
        }

        $attr = $event->attribution;

        // PLATFORMS §2.4: fbp/fbc/client_ip/UA are never hashed.
        foreach (array('fbp', 'fbc') as $key) {
            $value = isset($attr[$key]) ? trim((string) $attr[$key]) : '';
            if ('' !== $value) {
                $out[$key] = $value;
            }
        }

        $ip = isset($attr['client_ip']) ? trim((string) $attr['client_ip']) : '';
        if ('' !== $ip && 1 === preg_match('/^[0-9A-Fa-f:.]{7,45}$/', $ip)) {
            $out['client_ip_address'] = $ip;
        }

        $ua = isset($attr['user_agent']) ? trim((string) $attr['user_agent']) : '';
        if ('' !== $ua) {
            $out['client_user_agent'] = substr($ua, 0, 500);
        }

        // D11 baseline: the event is invalid unless at least one of
        // em/ph/external_id/fbp/fbc is present.
        foreach (array('em', 'ph', 'external_id', 'fbp', 'fbc') as $key) {
            if (isset($out[$key]) && '' !== $out[$key]) {
                return $out;
            }
        }

        return null;
    }

    /**
     * custom_data per the official required/recommended pattern (§2.6).
     * D12: Purchase value = order total incl. tax/fees/shipping, carried by
     * params['order_total'].
     *
     * @param ADC_Event $event Canonical event.
     * @return array<string, mixed>
     */
    private function build_custom_data(ADC_Event $event): array
    {
        $out = array();

        $ids      = array();
        $contents = array();
        $num      = 0;

        foreach ($event->items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id       = isset($item['item_id']) ? (string) $item['item_id'] : '';
            $quantity = isset($item['quantity']) ? max(1, (int) $item['quantity']) : 1;

            if ('' !== $id) {
                $ids[]      = $id;
                $contents[] = array('id' => $id, 'quantity' => $quantity);
            }

            $num += $quantity;
        }

        if (array() !== $ids) {
            $out['content_ids']  = $ids;
            $out['contents']     = $contents;
            $out['content_type'] = 'product';
        }

        if ($num > 0) {
            $out['num_items'] = $num;
        }

        $value = $this->event_value($event);
        if (null !== $value) {
            $out['value'] = $value;
        }

        $currency = '' !== $event->currency ? $event->currency : (function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '');
        if ('' !== $currency) {
            $out['currency'] = $currency;
        }

        if ('search' === $event->name && isset($event->params['search_term'])) {
            $out['search_string'] = substr((string) $event->params['search_term'], 0, 100);
        }

        return $out;
    }

    /**
     * Monetised value: Purchase uses the order total (D12), other events the
     * canonical GA4 value.
     *
     * @param ADC_Event $event Canonical event.
     * @return float|null
     */
    private function event_value(ADC_Event $event): ?float
    {
        if ('purchase' === $event->name && isset($event->params['order_total'])) {
            return (float) $event->params['order_total'];
        }

        return $event->value;
    }

    /* ---------------------------------------------------------------------
     * HTTP (PLATFORMS §2.1–§2.2, §2.8; D3).
     * ------------------------------------------------------------------- */

    /**
     * POST one request to the CAPI endpoint. access_token travels in the
     * body (form field), never in the URL, so tokens stay out of logs.
     *
     * @param array<int, array<string, mixed>> $events       Meta event payloads.
     * @param string                           $test_code    Test Events code ('' for production).
     * @return ADC_Send_Result
     */
    private function post(array $events, string $test_code): ADC_Send_Result
    {
        $pixel_id = (string) ADC_Settings::get('meta', 'pixel_id', '');
        $version  = (string) ADC_Settings::get('meta', 'api_version', 'v26.0');
        $token    = ADC_Settings::secret('meta', 'access_token');

        $url  = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($pixel_id) . '/events';
        $data = wp_json_encode($events, JSON_UNESCAPED_UNICODE);

        $fields = array(
            'data'         => is_string($data) ? $data : '[]',
            'access_token' => $token,
        );
        if ('' !== $test_code) {
            $fields['test_event_code'] = $test_code;
        }

        $response = wp_remote_post(
            $url,
            array(
                'timeout'     => 10,
                'redirection' => 0, // §6: no redirects.
                'sslverify'   => true,
                'blocking'    => true,
                'headers'     => array('Content-Type' => 'application/x-www-form-urlencoded'),
                'body'        => http_build_query($fields, '', '&'),
            )
        );

        return $this->interpret($response);
    }

    /**
     * Map the HTTP response to an ADC_Send_Result (retry 429/5xx + network
     * errors, dead otherwise). Messages are scrubbed by ADC_Send_Result.
     *
     * @param array<string, mixed>|WP_Error $response wp_remote_post result.
     * @return ADC_Send_Result
     */
    private function interpret($response): ADC_Send_Result
    {
        if (is_wp_error($response)) {
            ADC_Logger::log('warning', 'Meta CAPI request failed: ' . $response->get_error_message());
            return ADC_Send_Result::retry(0, 'http_error');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = substr((string) wp_remote_retrieve_body($response), 0, 4096);

        if ($code >= 200 && $code < 300) {
            ADC_Logger::log('debug', 'Meta CAPI send ok', array('http_code' => $code));
            return ADC_Send_Result::sent($code);
        }

        $message = $this->error_message($body);

        if (429 === $code || $code >= 500) {
            ADC_Logger::log('warning', 'Meta CAPI retryable failure', array('http_code' => $code));
            return ADC_Send_Result::retry($code, $message);
        }

        ADC_Logger::log('error', 'Meta CAPI permanent failure', array('http_code' => $code));
        return ADC_Send_Result::dead($code, $message);
    }

    /**
     * Extract the platform error message from a JSON body (scrubbed later).
     *
     * @param string $body Response body (already capped).
     * @return string
     */
    private function error_message(string $body): string
    {
        if ('' === $body) {
            return '';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = $decoded['error']['message'] ?? ($decoded['message'] ?? null);
            if (is_string($error) && '' !== $error) {
                return $error;
            }
        }

        return 'http_' . strlen($body);
    }

    /**
     * §4 default browser mode: gtm when a GTM container id is configured,
     * else off.
     */
    private static function default_browser_mode(): string
    {
        return '' !== (string) ADC_Settings::get('gtm', 'container_id', '') ? 'gtm' : 'off';
    }
}
