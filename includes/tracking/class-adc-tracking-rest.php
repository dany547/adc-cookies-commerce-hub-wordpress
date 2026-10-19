<?php
/**
 * Public tracking REST routes: /adc/v1/event and /adc/v1/identify
 * (docs/ARCHITECTURE.md §2.2 REST table, §3.1, §3.3).
 *
 * The `__return_true` permission callback is deliberate: pages are cacheable,
 * so WP nonces would go stale. Protection = strict arg validation, 4 KB body
 * cap, per-IP+route rate limit (30/min), weekly HMAC `X-ADC-Token`, consent
 * cookie checks and `Cache-Control: no-store` responses.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Browser-origin event mirror + advanced-matching identify endpoint.
 */
final class ADC_Tracking_Rest implements ADC_Module_Interface
{
    /**
     * Rate limit: requests per minute per (ip, route).
     */
    private const RATE_LIMIT = 30;

    /**
     * Max request body bytes.
     */
    private const MAX_BODY = 4096;

    /**
     * Event names a browser may emit (server-origin events excluded:
     * purchase, refund, sign_up, login are never accepted here).
     *
     * @var string[]
     */
    private const BROWSER_EVENTS = array(
        'page_view', 'view_item_list', 'select_item', 'view_item', 'add_to_cart',
        'remove_from_cart', 'view_cart', 'begin_checkout', 'add_shipping_info',
        'add_payment_info', 'search', 'generate_lead',
    );

    /**
     * Whitelisted `params` keys accepted from the browser.
     *
     * @var string[]
     */
    private const PARAM_KEYS = array('coupon', 'search_term', 'method', 'item_list_id', 'item_list_name');

    /**
     * Boot the module.
     */
    public static function boot(): void
    {
        add_action('rest_api_init', array(self::class, 'register_routes'));
    }

    /**
     * Register the two POST routes.
     */
    public static function register_routes(): void
    {
        // Deliberately public (see file header): validation + rate limit + token + consent.
        register_rest_route(
            'adc/v1',
            '/event',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array(self::class, 'handle_event'),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'event_id' => array('type' => 'string', 'required' => true),
                    'name'     => array('type' => 'string', 'required' => true),
                ),
            )
        );

        register_rest_route(
            'adc/v1',
            '/identify',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array(self::class, 'handle_identify'),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'source' => array('type' => 'string', 'required' => true),
                ),
            )
        );
    }

    /**
     * POST /event: rebuild the event from catalog ids and hand it to the
     * dispatcher (202; nothing is sent synchronously).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_event(WP_REST_Request $request)
    {
        $guard = self::guard($request, 'event');
        if (is_wp_error($guard)) {
            return $guard;
        }

        $body = self::json_body($request);
        if (is_wp_error($body)) {
            return $body;
        }

        $event_id = (string) ($body['event_id'] ?? '');
        $name     = (string) ($body['name'] ?? '');

        if (!ADC_Event_Id::is_valid($event_id)) {
            return self::error('invalid_event_id', 400);
        }

        if ('' !== $name && 0 === strpos($name, 'custom_')) {
            // custom_* events allowed.
        } elseif (!in_array($name, self::BROWSER_EVENTS, true)) {
            return self::error('invalid_event_name', 400);
        }

        // Consent cookie must allow at least one non-necessary category (§2.2).
        $consent = self::consent();
        if (null === $consent || (!$consent->allows('statistics') && !$consent->allows('marketing'))) {
            return self::error('consent_required', 403);
        }

        $items = self::rebuild_items($body['items'] ?? array());
        $value = self::value_from_items($items);

        $consent_snapshot = $consent->to_array();
        $attribution      = self::browser_attribution($consent);

        $event = new ADC_Event(
            name: $name,
            event_id: $event_id,
            time: time(),
            origin: 'browser',
            source_url: self::source_url((string) ($body['source_url'] ?? '')),
            currency: function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            value: $value,
            items: $items,
            order_id: 0,
            user: self::session_user($consent),
            attribution: $attribution,
            cid: $consent->cid,
            consent: $consent_snapshot,
            params: self::sanitize_params($body['params'] ?? array()),
        );

        // `adc_user_data` filter contract (§2.5): ADC_User_Data, ADC_Event.
        if (null !== $event->user) {
            $filtered = apply_filters('adc_user_data', $event->user, $event);
            if ($filtered instanceof ADC_User_Data && !$filtered->is_empty() && $filtered !== $event->user) {
                $event = ADC_Event::from_array(array_merge($event->to_array(), array('user' => $filtered->to_array())));
            }
        }

        do_action('adc_track_event', $event);

        $response = rest_ensure_response(new stdClass());
        $response->set_status(202);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * POST /identify: normalise + hash the typed fields, remember in the
     * session (hashes only), return the hashed user_data (200). 403
     * `consent_required` when marketing is not granted. Never reveals
     * whether an e-mail belongs to an account.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_identify(WP_REST_Request $request)
    {
        $guard = self::guard($request, 'identify');
        if (is_wp_error($guard)) {
            return $guard;
        }

        $body = self::json_body($request);
        if (is_wp_error($body)) {
            return $body;
        }

        $source = sanitize_key((string) ($body['source'] ?? ''));
        if (!in_array($source, array('checkout', 'form', 'account'), true)) {
            return self::error('invalid_source', 400);
        }

        $consent = self::consent();
        if (null === $consent || !$consent->allows('marketing')) {
            return self::error('consent_required', 403);
        }

        if (!ADC_Advanced_Matching::enabled()) {
            return self::error('matching_disabled', 400);
        }

        $user_id = get_current_user_id();

        if ('account' === $source) {
            if ($user_id <= 0) {
                return self::error('not_logged_in', 400);
            }

            $user_data = ADC_Advanced_Matching::from_user($user_id);
        } else {
            $typed = ADC_Advanced_Matching::from_array(
                array(
                    'em'      => self::field($body, 'email', 254),
                    'ph'      => self::field($body, 'phone', 30),
                    'fn'      => self::field($body, 'first_name', 100),
                    'ln'      => self::field($body, 'last_name', 100),
                    'ct'      => self::field($body, 'city', 100),
                    'st'      => self::field($body, 'state', 100),
                    'zp'      => self::field($body, 'postcode', 20),
                    'country' => self::field($body, 'country', 2),
                ),
                self::field($body, 'country', 2)
            );

            // A logged-in user's record still wins for fields they didn't type.
            $user_data = $user_id > 0
                ? ADC_Advanced_Matching::from_user($user_id)->merge($typed)
                : $typed;

            if ($user_data->is_empty()) {
                return self::error('no_valid_fields', 400);
            }
        }

        $user_data = ADC_Advanced_Matching::with_cid_external_id($user_data, $consent->cid);
        ADC_Advanced_Matching::remember($user_data);

        $response = rest_ensure_response(array('user_data' => $user_data->to_array()));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /* ---------------------------------------------------------------------
     * Shared guards.
     * ------------------------------------------------------------------- */

    /**
     * Kill-switch, rate limit and token check shared by both routes.
     *
     * @param WP_REST_Request $request Request.
     * @param string          $route   Route slug (rate-limit bucket).
     * @return true|WP_Error
     */
    private static function guard(WP_REST_Request $request, string $route)
    {
        if (defined('ADC_MARKETING_DISABLE_TRACKING') && ADC_MARKETING_DISABLE_TRACKING) {
            return self::error('tracking_disabled', 503);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!self::rate_limit_ok($ip, $route)) {
            return self::error('rate_limited', 429);
        }

        if (!self::token_ok((string) $request->get_header('X-ADC-Token'))) {
            return self::error('invalid_token', 403);
        }

        return true;
    }

    /**
     * Transient-based rate limit: adc_rl_{sha256(ip|route)}, 30 req/min (§2.2).
     *
     * @param string $ip    Client IP.
     * @param string $route Route slug.
     * @return bool
     */
    private static function rate_limit_ok(string $ip, string $route): bool
    {
        $key   = 'adc_rl_' . hash('sha256', $ip . '|' . $route);
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * Weekly HMAC token: current or previous week accepted.
     *
     * @param string $token X-ADC-Token header value.
     * @return bool
     */
    private static function token_ok(string $token): bool
    {
        if ('' === $token) {
            return false;
        }

        $week = (int) floor(time() / WEEK_IN_SECONDS);

        foreach (array($week, $week - 1) as $candidate) {
            if (hash_equals(hash_hmac('sha256', (string) $candidate, wp_salt('nonce')), $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decoded JSON body, <= 4 KB.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    private static function json_body(WP_REST_Request $request)
    {
        $raw = (string) $request->get_body();

        if (strlen($raw) > self::MAX_BODY) {
            return self::error('payload_too_large', 413);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::error('invalid_json', 400);
        }

        return $decoded;
    }

    /**
     * Consent state from the cookie (null when the engine is absent/malformed).
     *
     * @return ADC_Consent_State|null
     */
    private static function consent(): ?ADC_Consent_State
    {
        if (!class_exists('ADC_Consent')) {
            return null;
        }

        return ADC_Consent::current();
    }

    /**
     * WP_Error with a JSON-safe, PII-free message.
     *
     * @param string $code   Error code.
     * @param int    $status HTTP status.
     * @return WP_Error
     */
    private static function error(string $code, int $status): WP_Error
    {
        return new WP_Error($code, $code, array('status' => $status));
    }

    /* ---------------------------------------------------------------------
     * Event mapping helpers.
     * ------------------------------------------------------------------- */

    /**
     * Rebuild items/prices from catalog ids; client prices are ignored (§2.2).
     *
     * @param mixed $raw_items Client-supplied items list.
     * @return array<int, array<string, mixed>> GA4 item objects.
     */
    private static function rebuild_items($raw_items): array
    {
        if (!is_array($raw_items) || !function_exists('wc_get_product')) {
            return array();
        }

        $id_source  = (string) ADC_Settings::get('events', 'item_id_source', 'id');
        $value_mode = (string) ADC_Settings::get('events', 'value_mode', 'excl_tax_excl_shipping');

        $items = array();
        foreach (array_slice($raw_items, 0, 200) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $product = self::product_from_item($raw, $id_source);
            if (null === $product) {
                continue;
            }

            $qty     = max(1, (int) ($raw['qty'] ?? 1));
            $price   = 'incl_tax' === $value_mode
                ? (float) wc_get_price_including_tax($product)
                : (float) wc_get_price_excluding_tax($product);

            $item = array(
                'item_id'   => 'sku' === $id_source && $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
                'item_name' => mb_substr($product->get_name(), 0, 100),
                'price'     => $price,
                'quantity'  => $qty,
            );

            $brand = self::product_brand($product);
            if ('' !== $brand) {
                $item['item_brand'] = $brand;
            }

            $category = self::product_category($product);
            if ('' !== $category) {
                $item['item_category'] = $category;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Hashed session identity for browser events; only with marketing
     * consent, plus the stable cid external_id.
     *
     * @param ADC_Consent_State $consent Current state.
     * @return ADC_User_Data|null
     */
    private static function session_user(ADC_Consent_State $consent): ?ADC_User_Data
    {
        if (!$consent->allows('marketing') || !class_exists('ADC_Advanced_Matching')) {
            return null;
        }

        $user = ADC_Advanced_Matching::from_session();
        if (null === $user) {
            return null;
        }

        $user = ADC_Advanced_Matching::with_cid_external_id($user, $consent->cid);

        return $user->is_empty() ? null : $user;
    }

    /**
     * Resolve a product from one client item (id or variation_id; SKU lookup
     * when item_id_source is sku).
     *
     * @param array  $raw       Client item.
     * @param string $id_source events.item_id_source.
     * @return WC_Product|null
     */
    private static function product_from_item(array $raw, string $id_source): ?WC_Product
    {
        $id  = absint($raw['id'] ?? 0);
        $vid = absint($raw['variation_id'] ?? 0);

        $product = null;
        if ($vid > 0) {
            $product = wc_get_product($vid);
        }

        if (!$product instanceof WC_Product && $id > 0) {
            $product = wc_get_product($id);
        }

        if (!$product instanceof WC_Product && 'sku' === $id_source) {
            $sku     = sanitize_text_field((string) ($raw['id'] ?? ''));
            $by_sku  = '' !== $sku && function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
            $product = $by_sku > 0 ? wc_get_product($by_sku) : null;
        }

        // A variation resolves to its price but keeps the parent name/id semantics.
        return $product instanceof WC_Product ? $product : null;
    }

    /**
     * Σ price × quantity (GA4 value, D12).
     *
     * @param array $items Rebuilt items.
     * @return float|null
     */
    private static function value_from_items(array $items): ?float
    {
        if (array() === $items) {
            return null;
        }

        $value = 0.0;
        foreach ($items as $item) {
            $value += (float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 1);
        }

        return round($value, 2);
    }

    /**
     * Brand from the configured taxonomy (events.brand_taxonomy).
     *
     * @param WC_Product $product Product.
     * @return string
     */
    private static function product_brand(WC_Product $product): string
    {
        $taxonomy = (string) ADC_Settings::get('events', 'brand_taxonomy', 'product_brand');
        if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
            return '';
        }

        $terms = get_the_terms($product->get_id(), $taxonomy);

        return (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) ? $terms[0]->name : '';
    }

    /**
     * First product_cat term name.
     *
     * @param WC_Product $product Product.
     * @return string
     */
    private static function product_category(WC_Product $product): string
    {
        $terms = get_the_terms($product->get_id(), 'product_cat');

        return (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) ? $terms[0]->name : '';
    }

    /**
     * Browser attribution from the request: client_ip + user_agent ONLY when
     * marketing consent exists (§9.3 C5); never stored client-side values.
     *
     * @param ADC_Consent_State $consent Current state.
     * @return array<string, string>
     */
    private static function browser_attribution(ADC_Consent_State $consent): array
    {
        if (!$consent->allows('marketing')) {
            return array();
        }

        $attr = array();
        $ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua   = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        if ('' !== $ip) {
            $attr['client_ip'] = substr($ip, 0, 45);
        }
        if ('' !== $ua) {
            $attr['user_agent'] = mb_substr($ua, 0, 500);
        }

        return $attr;
    }

    /**
     * Whitelisted string params.
     *
     * @param mixed $raw_params Client params.
     * @return array<string, string>
     */
    private static function sanitize_params($raw_params): array
    {
        if (!is_array($raw_params)) {
            return array();
        }

        $out = array();
        foreach (self::PARAM_KEYS as $key) {
            if (isset($raw_params[$key]) && is_scalar($raw_params[$key])) {
                $value = substr(sanitize_text_field((string) $raw_params[$key]), 0, 100);
                if ('' !== $value) {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Validated same-site source URL.
     *
     * @param string $raw Client URL.
     * @return string
     */
    private static function source_url(string $raw): string
    {
        $raw = substr(trim($raw), 0, 500);
        if ('' === $raw) {
            return '';
        }

        $validated = wp_http_validate_url($raw);

        return (false !== $validated && str_starts_with($validated, home_url())) ? esc_url_raw($validated) : '';
    }

    /**
     * Scalar string field, length-capped.
     *
     * @param array  $body   Body array.
     * @param string $key    Field key.
     * @param int    $max    Max length.
     * @return string
     */
    private static function field(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? '';

        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }
}
