<?php
/**
 * Server-origin event emission (docs/ARCHITECTURE.md §2.4, §3.2, §3.4, §7.2/D14).
 *
 * One origin per request: classic (non-REST) cart mutations emit here; the
 * Store API / block checkout paths are skipped (is_rest_api_request) and
 * events.js emits those client-side instead. Server events go two ways:
 *   - ADC_Pending_Events (WC session) → browser dataLayer with the SERVER id;
 *   - do_action('adc_track_event')    → ADC_Dispatcher (C5) → platforms.
 * purchase browser push is queued on the order-received page once per order
 * (meta _adc_purchase_pushed); server send is guarded by _adc_purchase_sent.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce hooks that produce server-origin tracking events.
 */
final class ADC_Event_Hooks implements ADC_Module_Interface
{
    /**
     * Deterministic ids already emitted this request (double-hook guard).
     *
     * @var array<string, bool>
     */
    private static array $emitted = array();

    /**
     * Cart item keys already removed this request (remove dedupe).
     *
     * @var array<string, bool>
     */
    private static array $removed = array();

    /**
     * Boot the module: register the `events` group, then the WC hooks.
     */
    public static function boot(): void
    {
        // Settings must register even under the kill switch (admin stays usable).
        add_action('adc_register_settings', array(self::class, 'register_settings'));

        if (ADC_Gtm::disabled()) {
            return; // Kill switch: no server emissions, no browser queues.
        }

        // Cart (classic; Store API skipped inside the callbacks — §3.2).
        add_action('woocommerce_add_to_cart', array(self::class, 'on_add_to_cart'), 10, 6);
        add_action('woocommerce_cart_item_removed', array(self::class, 'on_remove_from_cart'), 10, 2);
        add_action('woocommerce_remove_cart_item', array(self::class, 'on_remove_from_cart'), 10, 2);
        add_filter('woocommerce_add_to_cart_frag', array(self::class, 'filter_cart_fragment'));

        // purchase — D14 triggers: payment_complete + status change to a paid
        // status inside events.purchase_statuses; both funnel into one guarded
        // emitter (_adc_purchase_sent timestamp, one $order->save() per flow).
        add_action('woocommerce_payment_complete', array(self::class, 'on_payment_complete'));
        add_action('woocommerce_order_status_changed', array(self::class, 'on_order_status_changed'), 10, 3);

        // refund (GA4 only — integrations' supports() decides).
        add_action('woocommerce_order_refunded', array(self::class, 'on_refunded'), 10, 2);

        // Identity events (browser push on the NEXT full page via session queue).
        add_action('user_register', array(self::class, 'on_user_register'));
        add_action('wp_login', array(self::class, 'on_wp_login'), 10, 2);

        // Search results + order-received browser purchase push.
        add_action('wp', array(self::class, 'on_request'));
    }

    /**
     * Register the `events` settings group (§4 defaults).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'events',
            self::defaults(),
            array(self::class, 'sanitize_events')
        );
    }

    /**
     * Default events settings (§4).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return array(
            'view_item_list'     => true,
            'select_item'        => true,
            'view_item'          => true,
            'add_to_cart'        => true,
            'remove_from_cart'   => true,
            'view_cart'          => true,
            'begin_checkout'     => true,
            'add_shipping_info'  => true,
            'add_payment_info'   => true,
            'purchase'           => true,
            'refund'             => true,
            'search'             => true,
            'sign_up'            => true,
            'login'              => true,
            'generate_lead'      => true,
            'server_page_view'   => false,
            'item_id_source'     => 'id',
            'value_mode'         => 'excl_tax_excl_shipping',
            'purchase_statuses'  => array('processing', 'completed'),
            'server_delay_seconds' => 60,
            'exclude_roles'      => array('administrator', 'shop_manager'),
            'brand_taxonomy'     => 'product_brand',
            'outbox_sent_days'   => 14,
            'outbox_dead_days'   => 30,
        );
    }

    /**
     * Sanitise a full events-group input (complete typed array returned).
     *
     * @param array $input   Raw input.
     * @param array $current Currently stored values.
     * @return array<string, mixed>
     */
    public static function sanitize_events(array $input, array $current): array
    {
        $out = array_merge(self::defaults(), $current);

        $booleans = array(
            'view_item_list',
            'select_item',
            'view_item',
            'add_to_cart',
            'remove_from_cart',
            'view_cart',
            'begin_checkout',
            'add_shipping_info',
            'add_payment_info',
            'purchase',
            'refund',
            'search',
            'sign_up',
            'login',
            'generate_lead',
            'server_page_view',
        );
        foreach ($booleans as $key) {
            $out[$key] = !empty($input[$key]);
        }

        $source = (string) ($input['item_id_source'] ?? 'id');
        $out['item_id_source'] = in_array($source, array('id', 'sku', 'gla'), true) ? $source : 'id';

        $mode = (string) ($input['value_mode'] ?? 'excl_tax_excl_shipping');
        $out['value_mode'] = in_array($mode, array('excl_tax_excl_shipping', 'incl_tax'), true)
            ? $mode
            : 'excl_tax_excl_shipping';

        $known_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
        $statuses       = isset($input['purchase_statuses']) && is_array($input['purchase_statuses'])
            ? $input['purchase_statuses']
            : array();
        $clean          = array();
        foreach ($statuses as $status) {
            $status = sanitize_key((string) $status);
            if ('' !== $status && (empty($known_statuses) || isset($known_statuses['wc-' . $status]))) {
                $clean[] = $status;
            }
        }
        if (!empty($clean)) {
            $out['purchase_statuses'] = array_values(array_unique($clean));
        }

        $out['server_delay_seconds'] = max(0, min(600, (int) ($input['server_delay_seconds'] ?? 60)));

        $role_names = function_exists('wp_roles') ? wp_roles()->get_names() : array();
        $roles      = isset($input['exclude_roles']) && is_array($input['exclude_roles'])
            ? $input['exclude_roles']
            : array();
        $clean      = array();
        foreach ($roles as $role) {
            $role = sanitize_key((string) $role);
            if ('' !== $role && (empty($role_names) || isset($role_names[$role]))) {
                $clean[] = $role;
            }
        }
        $out['exclude_roles'] = array_values(array_unique($clean));

        $taxonomy = sanitize_key((string) ($input['brand_taxonomy'] ?? 'product_brand'));
        $out['brand_taxonomy'] = '' !== $taxonomy ? $taxonomy : 'product_brand';

        $out['outbox_sent_days'] = max(1, (int) ($input['outbox_sent_days'] ?? 14));
        $out['outbox_dead_days'] = max(1, (int) ($input['outbox_dead_days'] ?? 30));

        return $out;
    }

    /**
     * Classic add-to-cart (AJAX or full-page). Skips Store API / REST requests
     * so block adds are emitted browser-side only (§3.2 single-origin rule).
     *
     * @param string $cart_item_key     Cart item key.
     * @param int    $product_id        Product id.
     * @param int    $quantity          Quantity.
     * @param int    $variation_id      Variation id (0 for simple).
     * @param mixed  $variation         Variation data.
     * @param mixed  $cart_item_data    Extra cart item data.
     */
    public static function on_add_to_cart(
        string $cart_item_key,
        int $product_id,
        int $quantity,
        int $variation_id = 0,
        mixed $variation = null,
        mixed $cart_item_data = null
    ): void {
        unset($cart_item_key, $cart_item_data);

        if (self::is_rest_request() || !ADC_Event_Builder::event_enabled('add_to_cart')) {
            return;
        }
        if (self::current_user_excluded()) {
            return;
        }

        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
        if (!$product instanceof WC_Product) {
            return;
        }

        $event = self::build_cart_event('add_to_cart', $product, max(1, $quantity));
        if (null === $event) {
            return;
        }

        ADC_Pending_Events::push($event); // Browser dataLayer (same event_id).
        do_action('adc_track_event', $event); // Platform mirror via C5.
    }

    /**
     * Classic remove-from-cart. Browser dataLayer only — the catalogue gives
     * remove_from_cart no server mirror, so adc_track_event is NOT fired.
     * Both documented triggers are hooked with a per-request dedupe because
     * the two actions pass different argument shapes across WC versions.
     *
     * @param mixed $arg1 Cart item array (woocommerce_cart_item_removed) or key.
     * @param mixed $arg2 Cart item key or cart object.
     */
    public static function on_remove_from_cart(mixed $arg1, mixed $arg2 = null): void
    {
        if (self::is_rest_request() || !ADC_Event_Builder::event_enabled('remove_from_cart')) {
            return;
        }

        $item = is_array($arg1) ? $arg1 : (is_array($arg2) ? $arg2 : null);
        if (null === $item) {
            return; // Unknown shape — do not risk a double/empty emission.
        }

        $key = (string) ($item['key'] ?? '');
        if ('' === $key) {
            $key = (string) (is_string($arg1) ? $arg1 : (is_string($arg2) ? $arg2 : ''));
        }
        if ('' !== $key && !empty(self::$removed[$key])) {
            return; // Both hooks fired for one removal.
        }
        if ('' !== $key) {
            self::$removed[$key] = true;
        }

        if (self::current_user_excluded()) {
            return;
        }

        $product = $item['data'] ?? null;
        if (!$product instanceof WC_Product) {
            $product_id   = (int) ($item['product_id'] ?? 0);
            $variation_id = (int) ($item['variation_id'] ?? 0);
            $product      = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
        }
        if (!$product instanceof WC_Product) {
            return;
        }

        $event = self::build_cart_event('remove_from_cart', $product, max(1, (int) ($item['quantity'] ?? 1)));
        if (null === $event) {
            return;
        }

        ADC_Pending_Events::push($event); // Browser push only (mirror: no).
    }

    /**
     * Expose queued server events inside the classic add-to-cart AJAX JSON as
     * the `adc_events` fragment (§3.2). Value is a JSON string keyed by a
     * non-matching selector, so WooCommerce's fragment DOM swap no-ops while
     * events.js reads it from the XHR/fetch response. Not consumed here —
     * the footer pull (with browser-side event_id dedupe) stays the fallback.
     *
     * @param array<string, string> $fragments Cart fragments.
     * @return array<string, string>
     */
    public static function filter_cart_fragment(array $fragments): array
    {
        if (!ADC_Pending_Events::has()) {
            return $fragments;
        }

        // Consume the queue: the browser pushes these once from the XHR
        // response; the next page's footer pull then stays empty (event_id
        // dedupe in datalayer.js is the second safety net).
        $pushes = ADC_Datalayer::to_pushes(ADC_Pending_Events::pull());
        if (empty($pushes)) {
            return $fragments;
        }

        $json = wp_json_encode($pushes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (is_string($json)) {
            $fragments['adc_events'] = $json;
        }

        return $fragments;
    }

    /**
     * D14 trigger: gateway marked the order paid.
     *
     * @param int $order_id Order id.
     */
    public static function on_payment_complete(int $order_id): void
    {
        self::maybe_send_purchase($order_id);
    }

    /**
     * D14 trigger: status transition into a configured purchase status
     * (covers COD/bank transfer manual marking and block checkout).
     *
     * @param int    $order_id Order id.
     * @param string $from     Previous status (slug, no wc- prefix).
     * @param string $to       New status (slug, no wc- prefix).
     */
    public static function on_order_status_changed(int $order_id, string $from, string $to): void
    {
        unset($from);

        $statuses = (array) ADC_Settings::get('events', 'purchase_statuses', array('processing', 'completed'));
        if (!in_array($to, $statuses, true)) {
            return;
        }

        self::maybe_send_purchase($order_id);
    }

    /**
     * Server purchase emission, guarded by _adc_purchase_sent (unix ts) so
     * payment_complete + status_changed cannot double-send (D14).
     *
     * @param int $order_id Order id.
     */
    private static function maybe_send_purchase(int $order_id): void
    {
        if (!ADC_Event_Builder::event_enabled('purchase')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ('' !== (string) $order->get_meta('_adc_purchase_sent')) {
            return; // Already emitted.
        }

        $statuses = (array) ADC_Settings::get('events', 'purchase_statuses', array('processing', 'completed'));
        if (!in_array($order->get_status(), $statuses, true)) {
            return; // payment_complete can precede the status transition.
        }

        if (self::order_customer_excluded($order)) {
            return;
        }

        $event = ADC_Event_Builder::from_order($order, 'server');
        if (null === $event) {
            return;
        }

        // Mark BEFORE firing: the AS job re-reads the order later; the marker
        // only records emission, one save per flow (D14).
        $order->update_meta_data('_adc_purchase_sent', (string) time());
        $order->save();

        do_action('adc_track_event', $event); // Dispatcher schedules (now + server_delay_seconds).
    }

    /**
     * Server refund → adc_track_event only (no browser push; refund is
     * GA4-only per D9/U1/U3 — integration supports() decides).
     *
     * @param int $order_id  Order id.
     * @param int $refund_id Refund id.
     */
    public static function on_refunded(int $order_id, int $refund_id): void
    {
        if (!ADC_Event_Builder::event_enabled('refund')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        $event_id = ADC_Event_Id::for_order($refund_id, 'refund');
        if (self::already_emitted($event_id)) {
            return;
        }

        $amount = 0.0;
        foreach ($order->get_refunds() as $refund) {
            if ($refund->get_id() === $refund_id) {
                $amount = (float) $refund->get_amount();
                break;
            }
        }
        if ($amount <= 0) {
            return;
        }

        $state = class_exists('ADC_Consent') ? ADC_Consent::for_order($order) : null;

        $event = new ADC_Event(
            'refund',
            $event_id,
            time(),
            'server',
            '',
            $order->get_currency(),
            $amount,
            array(),
            $order_id,
            null,
            class_exists('ADC_Order_Context') ? ADC_Order_Context::read($order) : array(),
            (string) $order->get_meta('_adc_consent_cid'),
            null !== $state ? $state->to_array() : array(),
            array(
                'transaction_id' => (string) $order_id,
                'refund_id'      => $refund_id,
            )
        );

        do_action('adc_track_event', $event);
    }

    /**
     * sign_up — user_register fires for both WP-created and
     * WooCommerce-created customers (woocommerce_created_customer routes
     * through wp_create_user), so this single hook covers both triggers of
     * §2.4 without double emission. Browser push happens on the next full
     * page via the session queue.
     *
     * @param int $user_id User id.
     */
    public static function on_user_register(int $user_id): void
    {
        if (!ADC_Event_Builder::event_enabled('sign_up')) {
            return;
        }

        $event_id = ADC_Event_Id::for_order($user_id, 'sign_up');
        if (self::already_emitted($event_id) || self::user_excluded($user_id)) {
            return;
        }

        $state = class_exists('ADC_Consent') ? ADC_Consent::current() : null;
        $user  = null;
        if (null !== $state && $state->allows('marketing') && class_exists('ADC_Advanced_Matching')) {
            $user = ADC_Advanced_Matching::from_user($user_id);
        }

        $event = new ADC_Event(
            'sign_up',
            $event_id,
            time(),
            'server',
            '',
            function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON',
            null,
            array(),
            0,
            $user,
            array(),
            null !== $state ? $state->cid : '',
            null !== $state ? $state->to_array() : array(),
            array()
        );

        ADC_Pending_Events::push($event);
        do_action('adc_track_event', $event);
    }

    /**
     * login — server origin; browser pushes on the next full page (queue),
     * integrations filter platform support via supports().
     *
     * @param string    $login Username (unused).
     * @param WP_User   $user  Logged-in user.
     */
    public static function on_wp_login(string $login, WP_User $user): void
    {
        unset($login);

        if (!ADC_Event_Builder::event_enabled('login')) {
            return;
        }

        $event_id = ADC_Event_Id::for_order($user->ID, 'login');
        if (self::already_emitted($event_id) || self::user_excluded($user->ID)) {
            return;
        }

        $state = class_exists('ADC_Consent') ? ADC_Consent::current() : null;
        $user_data = null;
        if (null !== $state && $state->allows('marketing') && class_exists('ADC_Advanced_Matching')) {
            $user_data = ADC_Advanced_Matching::from_user($user->ID);
        }

        $event = new ADC_Event(
            'login',
            $event_id,
            time(),
            'server',
            '',
            function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON',
            null,
            array(),
            0,
            $user_data,
            array(),
            null !== $state ? $state->cid : '',
            null !== $state ? $state->to_array() : array(),
            array()
        );

        ADC_Pending_Events::push($event);
        do_action('adc_track_event', $event);
    }

    /**
     * Request-scoped emissions: search results (server origin, queue +
     * mirror) and the thank-you purchase browser push (same id as server).
     * Runs on `wp` — works for classic AND block checkout order-received.
     */
    public static function on_request(): void
    {
        if (function_exists('is_search') && is_search() && !is_admin()) {
            self::maybe_track_search();
        }

        if (function_exists('is_order_received_page') && is_order_received_page()) {
            self::maybe_queue_thankyou_purchase();
        }
    }

    /**
     * Search results event (random server id; browser pushes it from the
     * session queue in this same request's footer, never re-mirrored).
     */
    private static function maybe_track_search(): void
    {
        if (!ADC_Event_Builder::event_enabled('search') || self::current_user_excluded()) {
            return;
        }

        $term = trim((string) get_search_query());
        if ('' === $term) {
            return;
        }

        $state = class_exists('ADC_Consent') ? ADC_Consent::current() : null;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request URL for attribution.
        $uri = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');

        $event = new ADC_Event(
            'search',
            ADC_Event_Id::random(),
            time(),
            'server',
            home_url($uri),
            function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON',
            null,
            array(),
            0,
            null,
            array(),
            null !== $state ? $state->cid : '',
            null !== $state ? $state->to_array() : array(),
            array('search_term' => function_exists('wp_strip_all_tags') ? wp_strip_all_tags($term) : $term)
        );

        ADC_Pending_Events::push($event);
        do_action('adc_track_event', $event);
    }

    /**
     * Order-received browser purchase push: same deterministic event_id as
     * the server send (platform dedup §3.4), queued once per order
     * (meta _adc_purchase_pushed). Uncached page, so no replay risk.
     */
    private static function maybe_queue_thankyou_purchase(): void
    {
        if (!ADC_Event_Builder::event_enabled('purchase')) {
            return;
        }

        $order_id = (int) get_query_var('order-received');
        if ($order_id <= 0) {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public endpoint param, no state change.
            $order_id = isset($_GET['order-received']) ? (int) $_GET['order-received'] : 0;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        }

        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order) {
            return;
        }

        // H1: only a request entitled to this order may set the push marker
        // or receive the purchase payload — mirror
        // WC_Shortcode_Checkout::order_received(): the request must carry the
        // order's secret key, or a logged-in customer must own the order.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only capability check, no state change before it passes.
        $key     = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        $owned   = is_user_logged_in()
            && (int) $order->get_customer_id() > 0
            && (int) $order->get_customer_id() === get_current_user_id();
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (!$owned && !hash_equals((string) $order->get_order_key(), (string) $key)) {
            return;
        }

        if ('' !== (string) $order->get_meta('_adc_purchase_pushed')) {
            return; // Pushed once per order.
        }

        $event = ADC_Event_Builder::from_order($order, 'server');
        if (null === $event) {
            return;
        }

        $order->update_meta_data('_adc_purchase_pushed', (string) time());
        $order->save();

        ADC_Datalayer::queue_footer($event); // adcServerEvents → pushServerEvents (no re-mirror).
    }

    /**
     * Build a classic cart mutation event with session identity + consent.
     *
     * @param string     $name    add_to_cart|remove_from_cart.
     * @param WC_Product $product Product/variation.
     * @param int        $qty     Quantity.
     * @return ADC_Event|null
     */
    private static function build_cart_event(string $name, WC_Product $product, int $qty): ?ADC_Event
    {
        $items = ADC_Event_Builder::product_to_item($product, array('qty' => $qty));
        $mode  = ADC_Event_Builder::value_mode();
        $price = 'incl_tax' === $mode
            ? (float) wc_get_price_including_tax($product, array('qty' => $qty))
            : (float) wc_get_price_excluding_tax($product, array('qty' => $qty));

        $state = class_exists('ADC_Consent') ? ADC_Consent::current() : null;
        $user  = null;
        if (null !== $state && $state->allows('marketing') && class_exists('ADC_Advanced_Matching')) {
            $user = ADC_Advanced_Matching::from_session();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL for attribution.
        $uri = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');

        return new ADC_Event(
            $name,
            ADC_Event_Id::random(),
            time(),
            'server',
            home_url($uri),
            function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON',
            round($price * $qty, function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2),
            array($items),
            0,
            $user,
            array(),
            null !== $state ? $state->cid : '',
            null !== $state ? $state->to_array() : array(),
            array()
        );
    }

    /**
     * Whether this request is a REST/Store API call (blocks path — browser
     * emits instead, §3.2).
     *
     * @return bool
     */
    private static function is_rest_request(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return function_exists('wc_is_rest_api_request') && wc_is_rest_api_request();
    }

    /**
     * Current user has a role listed in events.exclude_roles.
     *
     * @return bool
     */
    private static function current_user_excluded(): bool
    {
        return self::user_excluded(get_current_user_id());
    }

    /**
     * User has an excluded role (0 = guest → never excluded).
     *
     * @param int $user_id User id.
     * @return bool
     */
    private static function user_excluded(int $user_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }

        $excluded = (array) ADC_Settings::get('events', 'exclude_roles', array('administrator', 'shop_manager'));
        if (empty($excluded)) {
            return false;
        }

        $user = get_userdata($user_id);
        return $user instanceof WP_User && !empty(array_intersect($excluded, (array) $user->roles));
    }

    /**
     * The order's customer (if any) has an excluded role — admin test orders
     * must not pollute the pipeline.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    private static function order_customer_excluded(WC_Order $order): bool
    {
        $customer_id = (int) $order->get_customer_id();
        return $customer_id > 0 && self::user_excluded($customer_id);
    }

    /**
     * Per-request dedupe by deterministic event id; also records the id so
     * later hooks in the same request skip it.
     *
     * @param string $event_id Event id.
     * @return bool True when this id was ALREADY emitted.
     */
    private static function already_emitted(string $event_id): bool
    {
        if (isset(self::$emitted[$event_id])) {
            return true;
        }

        self::$emitted[$event_id] = true;

        return false;
    }
}
