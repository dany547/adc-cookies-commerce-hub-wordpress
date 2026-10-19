<?php
/**
 * GA4 Measurement Protocol integration (docs/ARCHITECTURE.md §1.6, §2.1).
 *
 * Contract refs: §9 D4 (48 h receive guard), D5 (GA4-specific hashing:
 * gmail dot-stripping, +E.164 phones, plain address fields read from the
 * order at send time), D6 (one purchase channel: browser|server), D7 (MP
 * credentials as query params, EU endpoint default, debug/validation
 * endpoint only in test()/debug), D8 (MP body requirements:
 * engagement_time_msec, session_id, timestamp_micros, explicit uppercase
 * consent block, user_data only with user_id + ad_user_data=GRANTED),
 * U2 (no documented MP<->gtag dedup: transaction_id guards retries only).
 *
 * This class maps + sends only: consent gating and retries stay in
 * ADC_Dispatcher. The URL carries measurement_id + api_secret (Google
 * requires them as query params) and is NEVER logged; ADC_Logger scrubs
 * long token-like strings as defence in depth.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Analytics 4 Measurement Protocol integration.
 */
final class ADC_Integration_Ga4 implements ADC_Integration_Interface, ADC_Module_Interface
{
    /**
     * GA4 MP only guarantees processing of events received within 48 hours
     * of their client timestamp; older events are dropped (D4).
     */
    private const MAX_EVENT_AGE = 48 * HOUR_IN_SECONDS;

    /**
     * Canonical events handled server-side (GA4 standard names; §9.1a).
     * page_view is gated by events.server_page_view; purchase by
     * ga4.purchase_channel (D6); refund is GA4-only (D9/U1/U3).
     *
     * @var string[]
     */
    private const EVENTS = array(
        'view_item_list',
        'select_item',
        'view_item',
        'add_to_cart',
        'remove_from_cart',
        'view_cart',
        'begin_checkout',
        'add_shipping_info',
        'add_payment_info',
        'refund',
        'search',
        'sign_up',
        'login',
        'generate_lead',
    );

    /**
     * GA4 item keys kept from the canonical item objects (PLATFORMS §3.4;
     * ≤ 10 custom params per item is satisfied because only standard keys
     * are forwarded).
     *
     * @var string[]
     */
    private const ITEM_KEYS = array(
        'item_id',
        'item_name',
        'affiliation',
        'coupon',
        'currency',
        'discount',
        'index',
        'item_brand',
        'item_category',
        'item_category2',
        'item_category3',
        'item_category4',
        'item_category5',
        'item_list_id',
        'item_list_name',
        'item_variant',
        'location_id',
        'price',
        'quantity',
    );

    /**
     * Boot the module: settings group, pipeline registration, front-end
     * pixel config and the direct-mode gtag loader enqueue.
     */
    public static function boot(): void
    {
        add_action('adc_register_settings', array(self::class, 'register_settings'));
        add_filter('adc_integrations', array(self::class, 'register_integration'));
        add_filter('adc_frontend_config', array(self::class, 'frontend_config'));
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue_assets'));
    }

    /**
     * Register the `ga4` settings group (§4; api_secret sealed at rest).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'ga4',
            array(
                'enabled_server'     => false,
                'browser_mode'       => self::default_browser_mode(),
                'measurement_id'     => '',
                'api_secret'         => '',
                'send_when_denied'   => false,
                'debug'              => false,
                'fallback_client_id' => false,
                'endpoint_region'    => 'eu',     // D7: EU collection endpoint by default.
                'purchase_channel'   => 'browser', // D6: one channel for purchase.
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
     * api_secret passes through raw; ADC_Settings::update seals it and
     * keeps the stored value on empty input.
     *
     * @param array $input   Raw input.
     * @param array $current Current stored values.
     * @return array
     */
    public static function sanitize_settings(array $input, array $current): array
    {
        $mode = (string) ($input['browser_mode'] ?? '');

        $mid = strtoupper(trim(sanitize_text_field((string) ($input['measurement_id'] ?? ''))));
        if ('' !== $mid && 1 !== preg_match('/^G-[A-Z0-9]+$/', $mid)) {
            $mid = ''; // Invalid measurement id: keep empty (the tab shows a warning).
        }

        $region = (string) ($input['endpoint_region'] ?? '');
        $channel = (string) ($input['purchase_channel'] ?? '');

        return array(
            'enabled_server'     => !empty($input['enabled_server']),
            'browser_mode'       => in_array($mode, array('off', 'gtm', 'direct'), true) ? $mode : 'off',
            'measurement_id'     => $mid,
            'api_secret'         => trim((string) ($input['api_secret'] ?? '')),
            'send_when_denied'   => !empty($input['send_when_denied']),
            'debug'              => !empty($input['debug']),
            'fallback_client_id' => !empty($input['fallback_client_id']),
            'endpoint_region'    => in_array($region, array('eu', 'global'), true) ? $region : 'eu',
            'purchase_channel'   => in_array($channel, array('browser', 'server'), true) ? $channel : 'browser',
        );
    }

    /**
     * Platform id (also the settings group).
     */
    public static function id(): string
    {
        return 'ga4';
    }

    /**
     * Admin label (RO).
     */
    public function label(): string
    {
        return __('GA4 (Measurement Protocol)', 'adc-marketing');
    }

    /**
     * Consent category required before anything is sent or loaded. With
     * `send_when_denied` enabled the admin explicitly opts into cookieless
     * server counting: no category gate applies (the consent block sent to
     * MP still reflects the real state, no user_data is ever attached
     * without marketing consent — D8 — and the cid fallback is refused
     * without statistics consent — M6, so no persistent identifier is set
     * for a denied visitor).
     */
    public function required_category(): string
    {
        return self::send_when_denied() ? 'necessary' : 'statistics';
    }

    /**
     * Server sending enabled AND credentials present (D7: both values travel
     * as query params, so both are required).
     */
    public function is_enabled(): bool
    {
        return (bool) ADC_Settings::get('ga4', 'enabled_server', false)
            && '' !== self::measurement_id()
            && '' !== ADC_Settings::secret('ga4', 'api_secret');
    }

    /**
     * GA4 has no supersedes relationships (only sGTM mp_forward replaces it).
     *
     * @return string[]
     */
    public function supersedes(): array
    {
        return array();
    }

    /**
     * Canonical events this integration handles. page_view only when C4's
     * server_page_view is on; purchase only when D6 routes it server-side.
     *
     * @param string $event_name Canonical event name.
     * @return bool
     */
    public function supports(string $event_name): bool
    {
        if ('page_view' === $event_name) {
            return (bool) ADC_Settings::get('events', 'server_page_view', false);
        }

        if ('purchase' === $event_name) {
            return 'server' === self::purchase_channel();
        }

        return in_array($event_name, self::EVENTS, true);
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

        // Defence in depth (matches the dispatcher gate unless the admin
        // explicitly enabled send_when_denied).
        if (!$consent->allows('statistics') && !self::send_when_denied()) {
            return ADC_Send_Result::skipped('consent_denied');
        }

        // D4: MP drops events older than 48 h — dead without HTTP.
        if ($event->time > 0 && (time() - $event->time) > self::MAX_EVENT_AGE) {
            return ADC_Send_Result::dead(0, 'event_too_old');
        }

        // D6: browser-channel purchase must never be duplicated server-side
        // (the setting may have changed after the job was queued).
        if ('purchase' === $event->name && 'server' !== self::purchase_channel()) {
            return ADC_Send_Result::skipped('purchase_browser_channel');
        }

        $body = $this->build_mp_payload($event, $consent);
        if (null === $body) {
            return ADC_Send_Result::skipped('no_client_id');
        }

        $debug = self::debug_enabled();

        return $this->post($body, self::endpoint($debug), $debug);
    }

    /**
     * Build the MP request body for one canonical event. Public because
     * ADC_Integration_Sgtm reuses it in `mp_forward` mode (D20).
     *
     * client_id comes from the order-time statistics capture (never
     * invented, PLATFORMS §3.3); with `fallback_client_id` enabled the
     * consent cid is used as a pseudonymous fallback, but ONLY when the
     * visitor granted statistics consent — the cid is a persistent
     * identifier and must never stand in for a client_id for a visitor
     * who denied/analytics-undecided cookies (M6: no consent-less
     * persistent tracking; such events are skipped as no_client_id).
     * user_data requires a user_id AND ad_user_data=GRANTED (D8): guest
     * purchases omit it and send the rest.
     *
     * @param ADC_Event         $event   Canonical event.
     * @param ADC_Consent_State $consent Consent state.
     * @return array<string, mixed>|null Null when no client_id is available.
     */
    public function build_mp_payload(ADC_Event $event, ADC_Consent_State $consent): ?array
    {
        $client_id = $this->client_id($event, $consent);
        if (null === $client_id) {
            return null;
        }

        $marketing = $consent->allows('marketing');

        $body = array(
            'client_id'        => $client_id,
            'timestamp_micros' => ($event->time > 0 ? $event->time : time()) * 1000000,
            // D8: explicit uppercase consent block on EVERY request so a
            // server event can never "upgrade" a denied browser state.
            'consent'          => array(
                'ad_user_data'       => $marketing ? 'GRANTED' : 'DENIED',
                'ad_personalization' => $marketing ? 'GRANTED' : 'DENIED',
            ),
            'events'           => array($this->build_event($event)),
        );

        // D8: user_data only when user_id is sent and ad_user_data is
        // GRANTED. user_id exists only on order events with a logged-in
        // customer; guests omit user_data entirely.
        if ($marketing && $event->order_id > 0) {
            $order = function_exists('wc_get_order') ? wc_get_order($event->order_id) : false;
            if ($order instanceof WC_Order && (int) $order->get_customer_id() > 0) {
                $user_data = $this->build_user_data($order);
                if (array() !== $user_data) {
                    $body['user_id']   = (string) $order->get_customer_id();
                    $body['user_data'] = $user_data;
                }
            }
        }

        // ip_override is a documented MP field; the raw IP only exists in
        // the order-time capture which the marketing snapshot already
        // gated (ADC_Order_Context), same posture as Meta/TikTok.
        $ip = isset($event->attribution['client_ip']) ? trim((string) $event->attribution['client_ip']) : '';
        if ($marketing && '' !== $ip && 1 === preg_match('/^[0-9A-Fa-f:.]{7,45}$/', $ip)) {
            $body['ip_override'] = $ip;
        }

        return $body;
    }

    /**
     * Admin "Test connection": one synthetic event through the VALIDATION
     * endpoint (D7: the production endpoint gives no feedback). Synthetic
     * identifiers only — never real customer data.
     *
     * @return ADC_Send_Result
     */
    public function test(): ADC_Send_Result
    {
        if ('' === self::measurement_id()) {
            return ADC_Send_Result::skipped('measurement_id_missing');
        }

        if ('' === ADC_Settings::secret('ga4', 'api_secret')) {
            return ADC_Send_Result::skipped('api_secret_missing');
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
            // Synthetic client id — fine for a validation-only request.
            attribution: array('ga_client_id' => '999999999.999999999'),
            cid: '',
            consent: array(),
            params: array('method' => 'adc_test'),
        );

        $body = $this->build_mp_payload($event, new ADC_Consent_State());
        if (null === $body) {
            return ADC_Send_Result::skipped('no_client_id');
        }

        return $this->post($body, self::endpoint(true), true);
    }

    /**
     * Merged into adcConfig.pixels.ga4 — no secrets (§2.1). purchase_channel
     * lets ga4.js skip browser purchase when D6 routes it server-side.
     *
     * @return array{mode: string, id?: string, purchase_channel?: string}
     */
    public function browser_config(): array
    {
        $mode = (string) ADC_Settings::get('ga4', 'browser_mode', 'off');

        if (!in_array($mode, array('gtm', 'direct'), true)) {
            return array('mode' => 'off');
        }

        return array(
            'mode'             => $mode,
            'id'               => self::measurement_id(),
            'purchase_channel' => self::purchase_channel(),
        );
    }

    /**
     * `adc_frontend_config` filter: owns adcConfig.pixels.ga4 (§2.3).
     *
     * @param array $config Front-end config.
     * @return array
     */
    public static function frontend_config(array $config): array
    {
        $pixels        = isset($config['pixels']) && is_array($config['pixels']) ? $config['pixels'] : array();
        $pixels['ga4'] = (new self())->browser_config();
        $config['pixels'] = $pixels;

        return $config;
    }

    /**
     * Enqueue the direct-mode gtag loader (assets/js/pixels/ga4.js).
     * `off` is never enqueued; `gtm` loads it as a documented no-op so the
     * loader can stay registered (GTM owns the tag).
     */
    public static function enqueue_assets(): void
    {
        if (defined('ADC_MARKETING_DISABLE_TRACKING') && ADC_MARKETING_DISABLE_TRACKING) {
            return;
        }

        $mode = (string) ADC_Settings::get('ga4', 'browser_mode', 'off');
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
            ? rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/js/pixels/ga4.js'
            : '';

        if ('' === $url) {
            return;
        }

        wp_enqueue_script('adc-pixel-ga4', $url, $deps, $version, array('in_footer' => true, 'strategy' => 'defer'));
        ADC_Frontend_Config::attach('adc-pixel-ga4');
    }

    /* ---------------------------------------------------------------------
     * Accessors (shared with ADC_Integration_Sgtm).
     * ------------------------------------------------------------------- */

    /**
     * Validated measurement id ('' when unset/invalid).
     *
     * @return string
     */
    public static function measurement_id(): string
    {
        $id = (string) ADC_Settings::get('ga4', 'measurement_id', '');

        return 1 === preg_match('/^G-[A-Z0-9]+$/', $id) ? $id : '';
    }

    /**
     * D6 purchase channel: 'browser' (default) or 'server'.
     *
     * @return string
     */
    public static function purchase_channel(): string
    {
        $channel = (string) ADC_Settings::get('ga4', 'purchase_channel', 'browser');

        return in_array($channel, array('browser', 'server'), true) ? $channel : 'browser';
    }

    /**
     * Whether anonymous server counting without statistics consent is
     * explicitly enabled (default off — GDPR-safe path).
     *
     * @return bool
     */
    public static function send_when_denied(): bool
    {
        return (bool) ADC_Settings::get('ga4', 'send_when_denied', false);
    }

    /**
     * Whether real sends go through the validation endpoint (admin debug).
     *
     * @return bool
     */
    public static function debug_enabled(): bool
    {
        return (bool) ADC_Settings::get('ga4', 'debug', false);
    }

    /**
     * MP endpoint for the configured region (D7); the validation endpoint
     * only ever serves test()/debug.
     *
     * @param bool $debug Use the validation server.
     * @return string
     */
    public static function endpoint(bool $debug = false): string
    {
        if ($debug) {
            return 'https://www.google-analytics.com/debug/mp/collect';
        }

        $region = (string) ADC_Settings::get('ga4', 'endpoint_region', 'eu');
        $base   = 'global' === $region
            ? 'https://www.google-analytics.com'
            : 'https://region1.google-analytics.com';

        return $base . '/mp/collect';
    }

    /* ---------------------------------------------------------------------
     * Payload mapping (PLATFORMS §3.3–§3.6; D5/D6/D8).
     * ------------------------------------------------------------------- */

    /**
     * Resolve the MP client_id. Order of preference: the statistics-consent
     * capture (ga_client_id), then the consent cid when the admin enabled
     * the documented fallback AND the visitor granted statistics consent
     * (M6: the cid is a persistent identifier — it must not track a
     * visitor who denied cookies; without consent the event is skipped as
     * no_client_id). PLATFORMS §3.3: never invent a client_id.
     *
     * @param ADC_Event         $event   Canonical event.
     * @param ADC_Consent_State $consent Consent state.
     * @return string|null Null when nothing usable exists.
     */
    private function client_id(ADC_Event $event, ADC_Consent_State $consent): ?string
    {
        $cid = isset($event->attribution['ga_client_id']) ? trim((string) $event->attribution['ga_client_id']) : '';

        if ('' !== $cid && 1 === preg_match('/^\d{4,12}\.\d{4,12}$/', $cid)) {
            return $cid;
        }

        if (
            $consent->allows('statistics')
            && (bool) ADC_Settings::get('ga4', 'fallback_client_id', false)
            && '' !== $event->cid
        ) {
            return $event->cid;
        }

        return null;
    }

    /**
     * The `events[0]` object: name + params (D8: engagement_time_msec and
     * session_id make the hit appear in real-time/reports; event_id is kept
     * as an informational param — U2: transaction_id is the only documented
     * purchase dedup key).
     *
     * @param ADC_Event $event Canonical event.
     * @return array<string, mixed>
     */
    private function build_event(ADC_Event $event): array
    {
        $params = array(
            'engagement_time_msec' => '100',
            'event_id'             => $event->event_id,
        );

        $session = isset($event->attribution['ga_session_id']) ? trim((string) $event->attribution['ga_session_id']) : '';
        if ('' !== $session && 1 === preg_match('/^\d+$/', $session)) {
            $params['session_id'] = $session;
        }

        if ('' !== $event->currency) {
            $params['currency'] = $event->currency;
        }

        if (null !== $event->value) {
            $params['value'] = $event->value;
        }

        if (!empty($event->items)) {
            $params['items'] = $this->sanitize_items($event->items);
        }

        foreach (array('tax', 'shipping', 'coupon', 'affiliation') as $key) {
            if (isset($event->params[$key]) && '' !== $event->params[$key] && null !== $event->params[$key]) {
                $params[$key] = $event->params[$key];
            }
        }

        // D6/U2: transaction_id on every purchase and refund (required for
        // refund; guards retries for purchase).
        if ('purchase' === $event->name || 'refund' === $event->name) {
            $transaction = isset($event->params['transaction_id']) ? trim((string) $event->params['transaction_id']) : '';
            if ('' === $transaction && $event->order_id > 0) {
                $transaction = (string) $event->order_id;
            }
            if ('' !== $transaction) {
                $params['transaction_id'] = $transaction;
            }
        }

        if ('search' === $event->name && isset($event->params['search_term'])) {
            $params['search_term'] = (string) $event->params['search_term'];
        }

        if ('sign_up' === $event->name && isset($event->params['method'])) {
            $params['method'] = (string) $event->params['method'];
        }

        if ('add_payment_info' === $event->name && isset($event->params['method'])) {
            $params['payment_type'] = (string) $event->params['method'];
        }

        return array(
            'name'   => $event->name,
            'params' => $this->sanitize_params($params),
        );
    }

    /**
     * MP user_data from the order, recomputed with the GA4 normalisation
     * (D5): email dot-stripped for gmail/googlemail, phone +E.164, names
     * hashed; city/region/postal_code/country PLAIN and read from the order
     * at send time (never stored in session/outbox). The shared session
     * hashes are the Meta variant and are deliberately NOT reused.
     *
     * @param WC_Order $order Order (HPOS-safe CRUD only).
     * @return array<string, mixed>
     */
    private function build_user_data(WC_Order $order): array
    {
        $country = (string) $order->get_billing_country();

        $out = array();

        $email = trim((string) $order->get_billing_email());
        if ('' !== $email) {
            $out['sha256_email_address'] = ADC_Advanced_Matching::hash(
                ADC_Advanced_Matching::normalize('em', $email, '', 'ga4')
            );
        }

        $phone = trim((string) $order->get_billing_phone());
        if ('' !== $phone) {
            $out['sha256_phone_number'] = ADC_Advanced_Matching::hash(
                ADC_Advanced_Matching::normalize('ph', $phone, $country, 'ga4')
            );
        }

        $address = array();

        $first = trim((string) $order->get_billing_first_name());
        if ('' !== $first) {
            $address['sha256_first_name'] = ADC_Advanced_Matching::hash(
                ADC_Advanced_Matching::normalize('fn', $first, '', 'ga4')
            );
        }

        $last = trim((string) $order->get_billing_last_name());
        if ('' !== $last) {
            $address['sha256_last_name'] = ADC_Advanced_Matching::hash(
                ADC_Advanced_Matching::normalize('ln', $last, '', 'ga4')
            );
        }

        // D5: plain (not hashed) address fields per the MP spec.
        $city = trim((string) $order->get_billing_city());
        if ('' !== $city) {
            $address['city'] = substr($city, 0, 100);
        }

        $state = trim((string) $order->get_billing_state());
        if ('' !== $state) {
            $address['region'] = substr($state, 0, 100);
        }

        $postcode = trim((string) $order->get_billing_postcode());
        if ('' !== $postcode) {
            $address['postal_code'] = substr($postcode, 0, 100);
        }

        if ('' !== $country) {
            $address['country'] = substr($country, 0, 100);
        }

        if (array() !== $address) {
            $out['address'] = $address;
        }

        return $out;
    }

    /**
     * Sanitise the params object: valid GA4 names (≤ 40 chars, start with a
     * letter), string values truncated to 100 chars, at most 25 params
     * (PLATFORMS §3.2 limits).
     *
     * @param array<string, mixed> $params Raw params.
     * @return array<string, mixed>
     */
    private function sanitize_params(array $params): array
    {
        $out = array();

        foreach ($params as $key => $value) {
            if (count($out) >= 25) {
                break;
            }

            $name = (string) $key;
            if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $name)) {
                continue;
            }

            if (is_string($value)) {
                $out[$name] = substr($value, 0, 100);
            } elseif (is_float($value) || is_int($value) || is_bool($value)) {
                $out[$name] = $value;
            } elseif (is_array($value)) {
                $out[$name] = $value; // items[] (already sanitised).
            }
        }

        return $out;
    }

    /**
     * Sanitise GA4 items: standard keys only, string values truncated to
     * 100 chars, at most 200 items (PLATFORMS §3.2/§3.4).
     *
     * @param array<int, mixed> $items Canonical GA4 items.
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_items(array $items): array
    {
        $out = array();

        foreach (array_slice(array_values($items), 0, 200) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $clean = array();
            foreach (self::ITEM_KEYS as $key) {
                if (!array_key_exists($key, $item)) {
                    continue;
                }

                $value = $item[$key];
                if (is_string($value)) {
                    $clean[$key] = substr($value, 0, 100);
                } elseif (is_float($value) || is_int($value)) {
                    $clean[$key] = $value;
                }
            }

            if (array() !== $clean) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * HTTP (PLATFORMS §3.1–§3.2; D7).
     * ------------------------------------------------------------------- */

    /**
     * POST one MP request. measurement_id + api_secret are query params
     * (Google requires them there); the URL is never logged anywhere.
     *
     * @param array<string, mixed> $body  MP request body.
     * @param string               $url   Endpoint (prod or validation).
     * @param bool                 $debug Whether the validation server is
     *                                    in use (response carries messages).
     * @return ADC_Send_Result
     */
    private function post(array $body, string $url, bool $debug): ADC_Send_Result
    {
        $query = array(
            'measurement_id' => self::measurement_id(),
            'api_secret'     => ADC_Settings::secret('ga4', 'api_secret'),
        );

        $json = wp_json_encode($body, JSON_UNESCAPED_UNICODE);

        $response = wp_remote_post(
            $url . '?' . http_build_query($query, '', '&'),
            array(
                'timeout'     => 10,
                'redirection' => 0, // §6: no redirects.
                'sslverify'   => true,
                'blocking'    => true,
                'headers'     => array('Content-Type' => 'application/json'),
                'body'        => is_string($json) ? $json : '{}',
            )
        );

        return $this->interpret($response, $debug);
    }

    /**
     * Map the response to an ADC_Send_Result. The production endpoint is
     * silent (2xx even for bad requests); the validation endpoint returns
     * validationMessages (empty = valid).
     *
     * @param array<string, mixed>|WP_Error $response wp_remote_post result.
     * @param bool                          $debug    Validation server used.
     * @return ADC_Send_Result
     */
    private function interpret($response, bool $debug): ADC_Send_Result
    {
        if (is_wp_error($response)) {
            ADC_Logger::log('warning', 'GA4 MP request failed: ' . $response->get_error_message());
            return ADC_Send_Result::retry(0, 'http_error');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = substr((string) wp_remote_retrieve_body($response), 0, 4096);

        if (429 === $code || $code >= 500) {
            ADC_Logger::log('warning', 'GA4 MP retryable failure', array('http_code' => $code));
            return ADC_Send_Result::retry($code, 'http_' . $code);
        }

        if ($code < 200 || $code >= 300) {
            ADC_Logger::log('error', 'GA4 MP permanent failure', array('http_code' => $code));
            return ADC_Send_Result::dead($code, $this->validation_message($body));
        }

        if ($debug) {
            $messages = $this->validation_messages($body);
            if (array() !== $messages) {
                ADC_Logger::log('error', 'GA4 MP validation rejected the event');
                return ADC_Send_Result::dead($code, implode('; ', array_slice($messages, 0, 3)));
            }
        }

        ADC_Logger::log('debug', 'GA4 MP send ok', array('http_code' => $code));
        return ADC_Send_Result::sent($code);
    }

    /**
     * Validation messages from a debug-endpoint response body.
     *
     * @param string $body Response body (already capped).
     * @return string[]
     */
    private function validation_messages(string $body): array
    {
        if ('' === $body) {
            return array();
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return array();
        }

        $raw = $decoded['validation_messages'] ?? ($decoded['validationMessages'] ?? array());
        if (!is_array($raw)) {
            return array();
        }

        $messages = array();
        foreach ($raw as $row) {
            if (is_string($row) && '' !== $row) {
                $messages[] = substr($row, 0, 200);
            } elseif (is_array($row)) {
                $text = $row['fieldPassthroughFailureMessage'] ?? ($row['description'] ?? '');
                if (is_string($text) && '' !== $text) {
                    $messages[] = substr($text, 0, 200);
                }
            }
        }

        return $messages;
    }

    /**
     * Best-effort platform error message from a non-2xx response.
     *
     * @param string $body Response body (already capped).
     * @return string
     */
    private function validation_message(string $body): string
    {
        $messages = $this->validation_messages($body);
        if (array() !== $messages) {
            return implode('; ', array_slice($messages, 0, 3));
        }

        return 'http_' . strlen($body);
    }

    /**
     * §4 default browser mode: gtm when a GTM container id is configured,
     * else off.
     *
     * @return string
     */
    private static function default_browser_mode(): string
    {
        return '' !== (string) ADC_Settings::get('gtm', 'container_id', '') ? 'gtm' : 'off';
    }
}
