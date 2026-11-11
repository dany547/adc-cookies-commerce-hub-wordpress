<?php
/** Daily, anonymous per-product aggregate counters. */
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

/**
 * Stores only daily product totals; visitor, session, order and request identifiers are never persisted.
 */
final class ADC_Product_Stats implements ADC_Module_Interface
{
    private const METRICS = ['views', 'add_to_cart', 'begin_checkout', 'purchases'];
    private const TOKEN_PERIOD = 86400;
    private const RATE_LIMIT_PER_BUCKET = 600;
    private const CHECKOUT_SESSION_FLAG = 'adc_product_stats_begin_checkout_counted';
    private const PURCHASE_RETRY_HOOK = 'adc_product_stats_retry_purchase';
    private const PURCHASE_MAX_RETRIES = 3;
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) { return; }
        self::$booted = true;
        add_action('admin_init', [self::class, 'register_privacy_policy_content']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_ajax_adc_product_stats', [self::class, 'handle_ajax_event']);
        add_action('wp_ajax_nopriv_adc_product_stats', [self::class, 'handle_ajax_event']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_view_script']);
        add_action('woocommerce_add_to_cart', [self::class, 'on_add_to_cart'], 20, 6);
        add_action('template_redirect', [self::class, 'on_checkout_page']);
        add_action('woocommerce_checkout_create_order', [self::class, 'capture_checkout_consent'], 30, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'capture_store_api_consent'], 30, 1);
        add_action('woocommerce_checkout_order_processed', [self::class, 'reset_checkout_session_flag'], 10, 1);
        add_action('woocommerce_thankyou', [self::class, 'reset_checkout_session_flag'], 10, 1);
        add_action(self::PURCHASE_RETRY_HOOK, [self::class, 'retry_purchase'], 10, 2);
        add_action('woocommerce_payment_complete', [self::class, 'on_payment_complete'], 20, 1);
        add_action('woocommerce_order_status_changed', [self::class, 'on_order_status_changed'], 20, 4);
    }

    /** Add an informational draft to the WordPress privacy-policy editor when available. */
    public static function register_privacy_policy_content(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) { return; }
        wp_add_privacy_policy_content(
            __('Statistici per produs (ADC Marketing)', 'adc-marketing'),
            '<p>' . esc_html(self::privacy_policy_text()) . '</p>'
        );
    }

    /** Build the current-language copy text using the configured retention period. */
    public static function privacy_policy_text(): string
    {
        $retention = max(30, min(730, (int) ADC_Settings::get('product_stats', 'retention_days', 180)));
        return sprintf(
            __('Dacă ați activat statisticile per produs, ADC Marketing numără zilnic vizualizările produselor, adăugările în coș, începuturile finalizării comenzii și achizițiile. Aceste agregate pot fi folosite în viitor pentru dezvoltări Smart Feed și clasamente de produse planificate sau opționale; acestea nu sunt prezentate aici ca funcții curente. Colectarea are loc numai după consimțământul vizitatorului pentru categoria „statistics”; achizițiile sunt numărate numai dacă acel consimțământ a fost salvat la finalizarea comenzii. Tabelul de statistici păstrează ziua, produsul și numărătorile, nu adrese IP sau identificatori de vizitator. Tokenul tehnic se rotește zilnic și este acceptat și în ziua următoare, dar nu dovedește că a avut loc o interacțiune; nu se folosește un ID de vizitator. Începutul finalizării este numărat cel mult o dată în sesiunea WooCommerce existentă. Agregatele se păstrează %d zile, apoi sunt șterse automat. Acest text este un șablon informativ: operatorul trebuie să îl verifice și să îl valideze cu DPO/consilierul juridic pentru magazinul său înainte de publicare; nu reprezintă o afirmație de conformitate.', 'adc-marketing'),
            $retention
        );
    }

    public static function register_routes(): void
    {
        register_rest_route('adc/v1', '/product-stats/event', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'handle_event'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function enqueue_view_script(): void
    {
        if ((class_exists('ADC_Picker') && ADC_Picker::active()) || !self::enabled() || !function_exists('is_product') || !is_product()) { return; }
        $product_id = (int) get_queried_object_id();
        if (!self::is_published_product($product_id)) { return; }
        $relative = 'assets/js/product-stats.js';
        $path = defined('ADC_MARKETING_PLUGIN_DIR') ? ADC_MARKETING_PLUGIN_DIR . $relative : dirname(__DIR__, 2) . '/' . $relative;
        $url = defined('ADC_MARKETING_PLUGIN_URL') ? ADC_MARKETING_PLUGIN_URL . $relative : plugins_url($relative, dirname(__DIR__, 2) . '/adc-marketing.php');
        $version = is_readable($path) ? (string) filemtime($path) : (defined('ADC_MARKETING_VERSION') ? ADC_MARKETING_VERSION : '1');
        wp_enqueue_script('adc-product-stats', $url, ['adc-consent'], $version, ['in_footer' => true, 'strategy' => 'defer']);
        wp_add_inline_script('adc-product-stats', 'window.adcProductStats = ' . wp_json_encode([
            'endpoint' => rest_url('adc/v1/product-stats/event'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'token' => self::page_token(),
            'productId' => $product_id,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    public static function handle_event(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = [];
        foreach (['metric', 'product_id', 'token'] as $key) {
            $params[$key] = $request->get_param($key);
        }
        return self::process_event($params, (string) $request->get_body());
    }

    /** Public anonymous AJAX fallback for sites that block anonymous REST requests. */
    public static function handle_ajax_event(): void
    {
        if (!function_exists('status_header')) { return; }
        $response = self::process_event($_POST, (string) http_build_query($_POST));
        if ($response instanceof WP_Error) {
            $data = $response->get_error_data();
            status_header(is_array($data) ? (int) ($data['status'] ?? 400) : 400);
        } else {
            status_header(204);
        }
        header('Cache-Control: no-store');
        exit;
    }

    /** Shared validation, consent, HMAC, and throttling for both transports. */
    public static function process_event(array $params, string $body): WP_REST_Response|WP_Error
    {
        $response = static function (): WP_REST_Response {
            $empty = new WP_REST_Response(null, 204);
            $empty->header('Cache-Control', 'no-store');
            return $empty;
        };
        if (!self::can_collect()) { return $response(); }
        if (strlen($body) > 1024) { return self::rest_error('adc_product_stats_payload', 400); }
        $metric = is_scalar($params['metric'] ?? null) ? (string) $params['metric'] : '';
        $raw_product_id = $params['product_id'] ?? null;
        if (!in_array($metric, ['view'], true) || !is_numeric($raw_product_id) || (string) (int) $raw_product_id !== (string) $raw_product_id) {
            return $response();
        }
        $product_id = self::canonical_product_id((int) $raw_product_id);
        if ($product_id < 1) { return $response(); }
        $token = is_scalar($params['token'] ?? null) ? (string) $params['token'] : '';
        if (!self::valid_token($token)) { return self::rest_error('adc_product_stats_token', 403); }
        $mapped = 'views';
        if (!self::take_rate_limit($mapped, $product_id)) { return $response(); }
        self::increment($mapped, $product_id);
        return $response();
    }

    public static function on_add_to_cart(string $cart_item_key, int $product_id, int|float $quantity, int $variation_id = 0, array $variation = [], array $cart_item_data = []): void
    {
        unset($cart_item_key, $quantity, $variation, $cart_item_data);
        if (!self::can_collect()) { return; }
        $id = self::canonical_product_id($variation_id > 0 ? $variation_id : $product_id);
        if ($id > 0) { self::increment('add_to_cart', $id); }
    }

    public static function on_checkout_page(): void
    {
        if ((class_exists('ADC_Picker') && ADC_Picker::active()) || !self::can_collect() || !function_exists('is_checkout') || !is_checkout() || (function_exists('is_order_received_page') && is_order_received_page()) || !function_exists('WC')) {
            return;
        }
        $wc = WC();
        if (empty($wc->cart) || empty($wc->session) || !method_exists($wc->session, 'get') || !method_exists($wc->session, 'set') || $wc->session->get(self::CHECKOUT_SESSION_FLAG)) {
            return;
        }
        $ids = [];
        foreach ((array) $wc->cart->get_cart() as $item) {
            if (!is_array($item)) { continue; }
            $raw_id = (int) ($item['variation_id'] ?? 0);
            if ($raw_id < 1) { $raw_id = (int) ($item['product_id'] ?? 0); }
            $id = self::canonical_product_id($raw_id);
            if ($id > 0) { $ids[$id] = true; }
        }
        if ($ids === []) { return; }
        foreach (array_keys($ids) as $id) {
            if (!self::increment('begin_checkout', (int) $id)) { return; }
        }
        $wc->session->set(self::CHECKOUT_SESSION_FLAG, true);
    }

    public static function capture_checkout_consent(WC_Order $order): void
    {
        if (self::enabled() && self::has_statistics_consent()) {
            $order->update_meta_data('_adc_stats_consent', '1');
        } else {
            $order->delete_meta_data('_adc_stats_consent');
        }
    }

    public static function capture_store_api_consent(mixed $order): void
    {
        if (is_numeric($order)) { $order = wc_get_order((int) $order); }
        if (!$order instanceof WC_Order) { return; }
        self::capture_checkout_consent($order);
        $order->save();
    }

    /** Payment webhooks have no visitor cookie; purchase collection uses the saved checkout consent snapshot. */
    public static function on_payment_complete(mixed $order_id): void
    {
        $order = is_numeric($order_id) ? wc_get_order((int) $order_id) : $order_id;
        if (!$order instanceof WC_Order || !method_exists($order, 'is_paid') || !$order->is_paid()) { return; }
        // Match the status-transition allowlist: a paid callback outside configured purchase statuses is intentionally ignored.
        if (!self::is_eligible_purchase_status((string) $order->get_status())) { return; }
        self::count_paid_order($order);
    }

    /** Retry a failed purchase claim/count; the order-meta claim keeps duplicate jobs idempotent. */
    public static function retry_purchase(int $order_id, int $retry = 1): void
    {
        $order = wc_get_order($order_id);
        if (
            $order instanceof WC_Order
            && self::is_eligible_purchase_status((string) $order->get_status())
            && (
                (method_exists($order, 'is_paid') && $order->is_paid())
                || (method_exists($order, 'get_total') && (float) $order->get_total() <= 0)
            )
        ) {
            self::count_paid_order($order, max(1, $retry));
        }
    }

    /** Clear the per-checkout-session guard after checkout completes, allowing a later cart to count. */
    public static function reset_checkout_session_flag(mixed $order_id = null): void
    {
        unset($order_id);
        if (!function_exists('WC')) { return; }
        $session = WC()->session ?? null;
        if (is_object($session) && method_exists($session, 'set')) {
            $session->set(self::CHECKOUT_SESSION_FLAG, false);
        }
    }

    private static function is_eligible_purchase_status(string $status): bool
    {
        $paid_statuses = ADC_Analytics_Settings::paid_statuses();
        $configured = ADC_Settings::get('events', 'purchase_statuses', ['processing', 'completed']);
        $configured = is_array($configured) ? array_map('sanitize_key', $configured) : ['processing', 'completed'];
        return in_array(sanitize_key($status), array_intersect($configured, $paid_statuses), true);
    }

    public static function on_order_status_changed(int $order_id, string $from, string $to, mixed $order = null): void
    {
        unset($from);
        if (!self::is_eligible_purchase_status($to)) { return; }
        if (!$order instanceof WC_Order) { $order = wc_get_order($order_id); }
        if (!$order instanceof WC_Order) { return; }
        $paid = method_exists($order, 'is_paid') && $order->is_paid();
        $free = method_exists($order, 'get_total') && (float) $order->get_total() <= 0;
        if ($paid || $free) { self::count_paid_order($order); }
    }

    private static function count_paid_order(WC_Order $order, int $retry = 0): void
    {
        if (!self::enabled() || (string) $order->get_meta('_adc_stats_consent', true) !== '1') { return; }
        global $wpdb;
        $order_id = (int) $order->get_id();
        if ($order_id < 1) { return; }
        // Serialize competing WooCommerce callbacks; meta CRUD remains HPOS-compatible.
        $lock = 'adc_ps_' . substr(hash('sha256', (string) $order_id), 0, 48);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            self::schedule_purchase_retry($order_id, $retry);
            return;
        }
        $claim_order = null;
        $transaction = false;
        try {
            $claim_order = wc_get_order($order_id);
            if (!$claim_order instanceof WC_Order || (string) $claim_order->get_meta('_adc_stats_consent', true) !== '1' || (string) $claim_order->get_meta('_adc_product_stats_purchased', true) !== '') {
                return;
            }
            if ($wpdb->query('START TRANSACTION') === false) {
                self::schedule_purchase_retry($order_id, $retry);
                return;
            }
            $transaction = true;
            $claim_order->add_meta_data('_adc_product_stats_purchased', '1', true);
            if (property_exists($wpdb, 'last_error')) { $wpdb->last_error = ''; }
            $claim_order->save_meta_data();
            if (property_exists($wpdb, 'last_error') && $wpdb->last_error !== '') {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                $claim_order->delete_meta_data('_adc_product_stats_purchased');
                $claim_order->save_meta_data();
                self::schedule_purchase_retry($order_id, $retry);
                return;
            }
            $ids = [];
            foreach ($claim_order->get_items('line_item') as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product_id')) { continue; }
                $product_id = self::canonical_product_id((int) $item->get_product_id());
                if ($product_id > 0) { $ids[$product_id] = true; }
            }
            foreach (array_keys($ids) as $product_id) {
                if (!self::increment('purchases', (int) $product_id)) {
                    $wpdb->query('ROLLBACK');
                    $transaction = false;
                    $claim_order->delete_meta_data('_adc_product_stats_purchased');
                    $claim_order->save_meta_data();
                    self::schedule_purchase_retry($order_id, $retry);
                    return;
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                $claim_order->delete_meta_data('_adc_product_stats_purchased');
                $claim_order->save_meta_data();
                self::schedule_purchase_retry($order_id, $retry);
                return;
            }
            $transaction = false;
        } finally {
            try {
                if ($transaction) {
                    $wpdb->query('ROLLBACK');
                    if ($claim_order instanceof WC_Order) {
                        $claim_order->delete_meta_data('_adc_product_stats_purchased');
                        $claim_order->save_meta_data();
                    }
                    self::schedule_purchase_retry($order_id, $retry);
                }
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    private static function schedule_purchase_retry(int $order_id, int $retry): void
    {
        if ($retry >= self::PURCHASE_MAX_RETRIES) { return; }
        $next_retry = $retry + 1;
        $timestamp = time() + (30 * (2 ** ($next_retry - 1)));
        $args = [$order_id, $next_retry];
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, self::PURCHASE_RETRY_HOOK, $args, 'adc', true);
            return;
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event($timestamp, self::PURCHASE_RETRY_HOOK, $args);
        }
    }

    private static function can_collect(): bool
    {
        return self::enabled() && self::has_statistics_consent();
    }

    private static function has_statistics_consent(): bool
    {
        return class_exists('ADC_Consent') && ADC_Consent::allows('statistics');
    }

    /** The public page token is a short-lived request gate, not proof of a visitor interaction. */
    private static function page_token(): string
    {
        $day = (string) floor(time() / self::TOKEN_PERIOD);
        return hash_hmac('sha256', $day, (string) wp_salt('nonce'));
    }

    private static function valid_token(string $token): bool
    {
        if ($token === '') { return false; }
        $current_day = (int) floor(time() / self::TOKEN_PERIOD);
        $salt = (string) wp_salt('nonce');
        return hash_equals(hash_hmac('sha256', (string) $current_day, $salt), $token)
            || hash_equals(hash_hmac('sha256', (string) ($current_day - 1), $salt), $token);
    }

    /** Limit each metric/product/minute bucket without storing visitor identifiers. */
    private static function take_rate_limit(string $metric, int $product_id): bool
    {
        $minute = (string) floor(time() / 60);
        $key = 'adc_product_stats_rl_' . $metric . '_' . $product_id . '_' . $minute;
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_PER_BUCKET) { return false; }
        set_transient($key, $count + 1, 70);
        return true;
    }

    private static function rest_error(string $code, int $status): WP_Error
    {
        return new WP_Error($code, __('Cerere invalidă.', 'adc-marketing'), ['status' => $status]);
    }

    private static function canonical_product_id(int $product_id): int
    {
        if ($product_id < 1 || !function_exists('wc_get_product')) { return 0; }
        $product = wc_get_product($product_id);
        if (!$product) { return 0; }
        if (method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0) {
            $product_id = (int) $product->get_parent_id();
        }
        return self::is_published_product($product_id) ? $product_id : 0;
    }

    private static function is_published_product(int $product_id): bool
    {
        if ($product_id < 1) { return false; }
        $post = function_exists('get_post') ? get_post($product_id) : null;
        return is_object($post) && ($post->post_type ?? '') === 'product' && ($post->post_status ?? '') === 'publish';
    }

    /**
     * Increment one allowlisted metric atomically when the admin has enabled collection.
     * Internal storage primitive only: it does NOT check visitor consent; every collection caller must enforce consent first.
     */
    public static function increment(string $metric, int $product_id, ?string $day = null): bool
    {
        global $wpdb;
        if (!in_array($metric, self::METRICS, true) || $product_id < 1 || !self::enabled()) {
            return false;
        }
        $day = $day ?? (function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d'));
        if (!self::valid_day($day)) {
            return false;
        }
        $table = ADC_Analytics_Schema::table(ADC_Analytics_Schema::PRODUCT_STATS);
        $sql = $wpdb->prepare(
            "INSERT INTO %i (day,product_id,{$metric}) VALUES (%s,%d,1) ON DUPLICATE KEY UPDATE {$metric} = {$metric} + 1",
            $table,
            $day,
            $product_id
        );
        return $wpdb->query($sql) !== false;
    }

    /**
     * Return ranked aggregates while suppressing low-count product cells.
     * @return array<int,array{product_id:int,value:int}>
     */
    public static function top(string $metric, int $days, int $limit): array
    {
        global $wpdb;
        if (!in_array($metric, self::METRICS, true) || !self::enabled()) {
            return [];
        }
        $days = max(1, min(730, $days));
        $limit = max(1, min(500, $limit));
        $from = function_exists('wp_date')
            ? wp_date('Y-m-d', time() - (($days - 1) * 86400))
            : gmdate('Y-m-d', time() - (($days - 1) * 86400));
        $threshold = ADC_Analytics_Settings::min_cell_count();
        $table = ADC_Analytics_Schema::table(ADC_Analytics_Schema::PRODUCT_STATS);
        $sql = $wpdb->prepare(
            "SELECT product_id, SUM({$metric}) AS value FROM %i WHERE day >= %s GROUP BY product_id HAVING value >= %d ORDER BY value DESC, product_id ASC LIMIT {$limit}",
            $table,
            $from,
            $threshold
        );
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        return array_map(static fn(array $row): array => array(
            'product_id' => (int) $row['product_id'],
            'value' => (int) $row['value'],
        ), $rows);
    }

    /** Return only unsuppressed purchase aggregates for a bounded candidate set. */
    public static function purchases_for_products(int $days, array $product_ids): array
    {
        global $wpdb;
        if (!self::enabled()) { return array(); }
        $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 500) { return array(); }
        $days = max(1, min(730, $days));
        $from = function_exists('wp_date')
            ? wp_date('Y-m-d', time() - (($days - 1) * 86400))
            : gmdate('Y-m-d', time() - (($days - 1) * 86400));
        $table = ADC_Analytics_Schema::table(ADC_Analytics_Schema::PRODUCT_STATS);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT product_id, SUM(purchases) AS value FROM %i WHERE day >= %s AND product_id IN ({$placeholders}) GROUP BY product_id HAVING value >= %d",
            ...array_merge(array($table, $from), $ids, array(ADC_Analytics_Settings::min_cell_count()))
        );
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        $values = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $id = (int) ($row['product_id'] ?? 0);
                $value = (int) ($row['value'] ?? 0);
                if ($id > 0 && $value > 0) { $values[$id] = $value; }
            }
        }
        return $values;
    }

    /** Purge rows older than the validated retention period. */
    public static function purge(int $retention_days): int
    {
        global $wpdb;
        $retention_days = max(30, min(730, $retention_days));
        $before = (function_exists('wp_date') ? wp_date('Y-m-d', strtotime('-' . $retention_days . ' days', current_time('timestamp'))) : gmdate('Y-m-d', strtotime('-' . $retention_days . ' days')));
        $table = ADC_Analytics_Schema::table(ADC_Analytics_Schema::PRODUCT_STATS);
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE day < %s', $table, $before));
    }

    private static function enabled(): bool
    {
        return class_exists('ADC_Settings') && (bool) ADC_Settings::get('product_stats', 'enabled', false);
    }

    private static function valid_day(string $day): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $date !== false && $date->format('Y-m-d') === $day;
    }
}
