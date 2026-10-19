<?php
/**
 * TikTok Events API 2.0 + TikTok Pixel integration (docs/ARCHITECTURE.md §1.6, §2.1).
 *
 * Contract refs: §9 D1 (shared event_id), D4 (7-day age guard, conservative),
 * D10 (POST https://business-api.tiktok.com/open_api/v1.3/event/track/,
 * Access-Token header, JSON body, HTTP 200 with code != 0 is a failure),
 * D12 (Purchase value = order total), D15 (pixel hold/grant/revoke consent
 * flow — JS side), U3 (no refund — no standard TikTok web event).
 *
 * Hashing note (D5): TikTok wants the phone hashed as +E.164 while the
 * shared ADC_User_Data stores the Meta-variant (no +). When an order is
 * available the E.164 hash is recomputed from the raw billing phone inside
 * this call (raw never leaves, never logged); otherwise `phone` is omitted
 * rather than sent with a wrong hash. E-mail/external_id normalisation is
 * identical for Meta and TikTok, so those hashes are shared safely.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TikTok Events API integration.
 */
final class ADC_Integration_Tiktok implements ADC_Integration_Interface, ADC_Module_Interface
{
    /**
     * Conservative age guard (D4): TikTok documents no limit; use 7 days.
     */
    private const MAX_EVENT_AGE = 7 * DAY_IN_SECONDS;

    /**
     * Endpoint fixed by PLATFORMS §4.1 (Events API 2.0; never the sunset 1.0).
     */
    private const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    /**
     * Canonical GA4 name → TikTok event name (§9.1a, authoritative map).
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
     * Register the `tiktok` settings group (§4; access_token sealed at rest).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'tiktok',
            array(
                'enabled_server'  => false,
                'browser_mode'    => ADC_Settings::get('gtm', 'container_id', '') !== '' ? 'gtm' : 'off',
                'pixel_code'      => '',
                'access_token'    => '',
                'test_event_code' => '',
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
     *
     * @param array $input   Raw input.
     * @param array $current Current stored values.
     * @return array
     */
    public static function sanitize_settings(array $input, array $current): array
    {
        $mode  = (string) ($input['browser_mode'] ?? '');
        $pixel = preg_replace('/[^0-9A-Za-z]/', '', (string) ($input['pixel_code'] ?? '')) ?? '';
        $test  = preg_replace('/[^0-9A-Za-z]/', '', (string) ($input['test_event_code'] ?? '')) ?? '';

        return array(
            'enabled_server'  => !empty($input['enabled_server']),
            'browser_mode'    => in_array($mode, array('off', 'gtm', 'direct'), true) ? $mode : 'off',
            'pixel_code'      => substr($pixel, 0, 40),
            'access_token'    => trim((string) ($input['access_token'] ?? '')),
            'test_event_code' => substr($test, 0, 40),
        );
    }

    /**
     * Platform id (also the settings group).
     */
    public static function id(): string
    {
        return 'tiktok';
    }

    /**
     * Admin label (RO).
     */
    public function label(): string
    {
        return __('TikTok (Events API + Pixel)', 'adc-marketing');
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
        return (bool) ADC_Settings::get('tiktok', 'enabled_server', false)
            && '' !== (string) ADC_Settings::get('tiktok', 'pixel_code', '')
            && '' !== ADC_Settings::secret('tiktok', 'access_token');
    }

    /**
     * No supersedes: TikTok is independent of GTM server containers.
     *
     * @return string[]
     */
    public function supersedes(): array
    {
        return array();
    }

    /**
     * Canonical events this integration handles. U3: no refund (no standard
     * TikTok web event). page_view only when server_page_view is enabled.
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

        // D4 (conservative): drop events older than 7 days without HTTP.
        if ($event->time > 0 && (time() - $event->time) > self::MAX_EVENT_AGE) {
            return ADC_Send_Result::dead(0, 'event_too_old');
        }

        $event_body = $this->build_event_body($event);
        if (null === $event_body) {
            return ADC_Send_Result::skipped('insufficient_user_data');
        }

        return $this->post(array($event_body), '');
    }

    /**
     * Admin "Test connection": one synthetic Purchase carrying the
     * test_event_code. Synthetic hashed values only — never real customer
     * data, and never sent without a test event code.
     *
     * @return ADC_Send_Result
     */
    public function test(): ADC_Send_Result
    {
        if ('' === (string) ADC_Settings::get('tiktok', 'pixel_code', '')) {
            return ADC_Send_Result::skipped('pixel_code_missing');
        }

        if ('' === ADC_Settings::secret('tiktok', 'access_token')) {
            return ADC_Send_Result::skipped('access_token_missing');
        }

        $test_code = (string) ADC_Settings::get('tiktok', 'test_event_code', '');
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

        $event_body = $this->build_event_body($event);

        return null === $event_body
            ? ADC_Send_Result::skipped('insufficient_user_data')
            : $this->post(array($event_body), $test_code);
    }

    /**
     * Merged into adcConfig.pixels.tiktok — no secrets (§2.1).
     *
     * @return array{mode: string, id?: string}
     */
    public function browser_config(): array
    {
        $mode = (string) ADC_Settings::get('tiktok', 'browser_mode', 'off');

        if (!in_array($mode, array('gtm', 'direct'), true)) {
            return array('mode' => 'off');
        }

        return array(
            'mode' => $mode,
            'id'   => (string) ADC_Settings::get('tiktok', 'pixel_code', ''),
        );
    }

    /**
     * `adc_frontend_config` filter: owns adcConfig.pixels.tiktok (§2.3).
     *
     * @param array $config Front-end config.
     * @return array
     */
    public static function frontend_config(array $config): array
    {
        $pixels           = isset($config['pixels']) && is_array($config['pixels']) ? $config['pixels'] : array();
        $pixels['tiktok'] = (new self())->browser_config();
        $config['pixels'] = $pixels;

        return $config;
    }

    /**
     * Enqueue the direct-mode pixel loader (assets/js/pixels/tiktok.js).
     */
    public static function enqueue_assets(): void
    {
        if (defined('ADC_MARKETING_DISABLE_TRACKING') && ADC_MARKETING_DISABLE_TRACKING) {
            return;
        }

        $mode = (string) ADC_Settings::get('tiktok', 'browser_mode', 'off');
        if (!in_array($mode, array('gtm', 'direct'), true)) {
            return;
        }

        $deps = array();
        foreach (array('adc-datalayer', 'adc-consent') as $dep) {
            if (wp_script_is($dep, 'registered')) {
                $deps[] = $dep;
            }
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $url     = defined('ADC_MARKETING_PLUGIN_URL')
            ? rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/js/pixels/tiktok.js'
            : '';

        if ('' === $url) {
            return;
        }

        wp_enqueue_script('adc-pixel-tiktok', $url, $deps, $version, array('in_footer' => true, 'strategy' => 'defer'));
        ADC_Frontend_Config::attach('adc-pixel-tiktok');
    }

    /* ---------------------------------------------------------------------
     * Payload mapping (PLATFORMS §4.2–§4.5, §9 D10/D12).
     * ------------------------------------------------------------------- */

    /**
     * Build one TikTok event body (the `data[]` element).
     *
     * @param ADC_Event $event Canonical event.
     * @return array<string, mixed>|null Null when no usable identity exists.
     */
    private function build_event_body(ADC_Event $event): ?array
    {
        $user = $this->build_user($event);
        if (null === $user) {
            return null;
        }

        $body = array(
            'event'      => self::EVENT_MAP[$event->name] ?? $event->name,
            'event_time' => $event->time,
            'event_id'   => $event->event_id, // D1: shared with the browser push.
            'user'       => $user,
            'page'       => array(
                'url' => '' !== $event->source_url ? $event->source_url : home_url('/'),
            ),
        );

        $properties = $this->build_properties($event);
        if (array() !== $properties) {
            $body['properties'] = $properties;
        }

        return $body;
    }

    /**
     * `user` object: hashed email/external_id, phone re-hashed as +E.164
     * (D5) when an order is available, unhashed ttp/ttclid/ip/user_agent
     * from the order-time attribution (marketing-consent gated upstream).
     *
     * @param ADC_Event $event Canonical event.
     * @return array<string, string>|null Null when nothing usable (no email,
     *                                   external_id, ttp/ttclid/ip/ua).
     */
    private function build_user(ADC_Event $event): ?array
    {
        $out = array();

        if (null !== $event->user) {
            if (null !== $event->user->em && '' !== $event->user->em) {
                $out['email'] = $event->user->em; // Same normalisation for Meta and TikTok (D5).
            }
            if (null !== $event->user->external_id && '' !== $event->user->external_id) {
                $out['external_id'] = $event->user->external_id;
            }
        }

        // D5: TikTok phone = SHA-256 of +E.164. Recompute from the order so
        // we never send the Meta-variant hash; raw value stays in this scope.
        $phone = $this->tiktok_phone_hash($event);
        if ('' !== $phone) {
            $out['phone'] = $phone;
        }

        $attr = $event->attribution;

        // PLATFORMS §4.2/§4.3: ttp, ttclid, ip, user_agent are not hashed.
        foreach (array('ttp', 'ttclid') as $key) {
            $value = isset($attr[$key]) ? trim((string) $attr[$key]) : '';
            if ('' !== $value) {
                $out[$key] = substr($value, 0, 200);
            }
        }

        $ip = isset($attr['client_ip']) ? trim((string) $attr['client_ip']) : '';
        if ('' !== $ip && 1 === preg_match('/^[0-9A-Fa-f:.]{7,45}$/', $ip)) {
            $out['ip'] = $ip;
        }

        $ua = isset($attr['user_agent']) ? trim((string) $attr['user_agent']) : '';
        if ('' !== $ua) {
            $out['user_agent'] = substr($ua, 0, 500);
        }

        if (array() === $out) {
            return null;
        }

        return $out;
    }

    /**
     * SHA-256 of the TikTok-normalised (+E.164) billing phone, recomputed
     * from the order at send time. '' when no order/phone exists — the
     * shared session hash is the Meta variant and is deliberately NOT sent.
     *
     * @param ADC_Event $event Canonical event.
     * @return string Hashed value ('' when unavailable).
     */
    private function tiktok_phone_hash(ADC_Event $event): string
    {
        if ($event->order_id <= 0 || !class_exists('ADC_Advanced_Matching') || !function_exists('wc_get_order')) {
            return '';
        }

        $order = wc_get_order($event->order_id);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $raw     = trim((string) $order->get_billing_phone());
        $country = (string) $order->get_billing_country();

        if ('' === $raw) {
            return '';
        }

        $normalized = ADC_Advanced_Matching::normalize('ph', $raw, $country, 'tiktok');

        return ADC_Advanced_Matching::hash($normalized);
    }

    /**
     * `properties` object (§4.4 recommended fields). D12: Purchase value =
     * order total via params['order_total'].
     *
     * @param ADC_Event $event Canonical event.
     * @return array<string, mixed>
     */
    private function build_properties(ADC_Event $event): array
    {
        $out = array('content_type' => 'product');

        $contents = array();
        $quantity = 0;

        foreach ($event->items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id      = isset($item['item_id']) ? (string) $item['item_id'] : '';
            $item_qty = isset($item['quantity']) ? max(1, (int) $item['quantity']) : 1;

            if ('' !== $id) {
                $contents[] = array(
                    'content_id'   => $id,
                    'content_type' => 'product',
                    'quantity'     => $item_qty,
                );
            }

            $quantity += $item_qty;
        }

        if (array() !== $contents) {
            $out['contents'] = $contents;
        }

        if ($quantity > 0) {
            $out['quantity'] = $quantity;
        }

        $value = 'purchase' === $event->name && isset($event->params['order_total'])
            ? (float) $event->params['order_total']
            : $event->value;

        if (null !== $value) {
            $out['value'] = $value;
        }

        $currency = '' !== $event->currency ? $event->currency : (function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '');
        if ('' !== $currency) {
            $out['currency'] = $currency;
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * HTTP (PLATFORMS §4.1; D10).
     * ------------------------------------------------------------------- */

    /**
     * POST to the Events API 2.0 endpoint. Access-Token in the header
     * (never a URL parameter), JSON body, single event per request.
     *
     * @param array<int, array<string, mixed>> $events    TikTok event bodies.
     * @param string                           $test_code Test Events code ('' for production).
     * @return ADC_Send_Result
     */
    private function post(array $events, string $test_code): ADC_Send_Result
    {
        $payload = array(
            'event_source'    => 'web',
            'event_source_id' => (string) ADC_Settings::get('tiktok', 'pixel_code', ''),
            'data'            => $events,
        );

        if ('' !== $test_code) {
            $payload['test_event_code'] = $test_code;
        }

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);

        $response = wp_remote_post(
            self::ENDPOINT,
            array(
                'timeout'     => 10,
                'redirection' => 0,
                'sslverify'   => true,
                'blocking'    => true,
                'headers'     => array(
                    'Content-Type' => 'application/json',
                    'Access-Token' => ADC_Settings::secret('tiktok', 'access_token'),
                ),
                'body'        => is_string($json) ? $json : '{}',
            )
        );

        return $this->interpret($response);
    }

    /**
     * Map the response to an ADC_Send_Result. D10: HTTP 200 with
     * `code != 0` is a failure — success is `code: 0`.
     *
     * @param array<string, mixed>|WP_Error $response wp_remote_post result.
     * @return ADC_Send_Result
     */
    private function interpret($response): ADC_Send_Result
    {
        if (is_wp_error($response)) {
            ADC_Logger::log('warning', 'TikTok Events API request failed: ' . $response->get_error_message());
            return ADC_Send_Result::retry(0, 'http_error');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = substr((string) wp_remote_retrieve_body($response), 0, 4096);

        if (429 === $code || $code >= 500) {
            ADC_Logger::log('warning', 'TikTok Events API retryable failure', array('http_code' => $code));
            return ADC_Send_Result::retry($code, $this->api_message($body));
        }

        if ($code < 200 || $code >= 300) {
            ADC_Logger::log('error', 'TikTok Events API permanent failure', array('http_code' => $code));
            return ADC_Send_Result::dead($code, $this->api_message($body));
        }

        $decoded  = json_decode($body, true);
        $api_code = is_array($decoded) ? (int) ($decoded['code'] ?? -1) : -1;

        if (0 !== $api_code) {
            ADC_Logger::log('error', 'TikTok Events API rejected the event', array('api_code' => $api_code));
            return ADC_Send_Result::dead($code, $this->api_message($body));
        }

        ADC_Logger::log('debug', 'TikTok Events API send ok', array('http_code' => $code));
        return ADC_Send_Result::sent($code);
    }

    /**
     * Extract the API message from a TikTok response body (scrubbed later).
     *
     * @param string $body Response body (already capped).
     * @return string
     */
    private function api_message(string $body): string
    {
        if ('' === $body) {
            return '';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = $decoded['message'] ?? null;
            if (is_string($message) && '' !== $message) {
                return $message;
            }
        }

        return 'tiktok_code_unknown';
    }
}
