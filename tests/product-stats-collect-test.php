<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
interface ADC_Module_Interface { public static function boot(): void; }
class WP_REST_Server { public const CREATABLE = 'POST'; }
class WP_Error { public function __construct(public string $code = '', public string $message = '', public array $data = []) {} }
class WP_REST_Response { public function __construct(public mixed $data = null, public int $status = 200) {} public function header(string $name, string $value): void {} }
class WP_REST_Request
{
    public function __construct(private array $params = [], private string $body = '{}') {}
    public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
    public function get_body(): string { return $this->body; }
}
final class ADC_Settings
{
    public static bool $enabled = false;
    public static int $retention = 180;
    public static array $purchase_statuses = ['processing', 'completed'];
    public static function get(string $group, string $key, mixed $default = null): mixed {
        if ($group === 'product_stats') { return match ($key) { 'enabled' => self::$enabled, 'retention_days' => self::$retention, default => $default }; }
        if ($group === 'events' && $key === 'purchase_statuses') { return self::$purchase_statuses; }
        if ($group === 'analytics' && $key === 'paid_statuses') { return ['processing', 'completed']; }
        return $default;
    }
}
final class ADC_Consent { public static bool $statistics = false; public static function allows(string $category): bool { return $category === 'statistics' && self::$statistics; } }
final class ADC_Consent_Rest { public const TOKEN_PERIOD = 604800; }
final class ADC_Analytics_Schema { public const PRODUCT_STATS = 'adc_analytics_product_stats'; public static function table(string $key): string { return 'wp_' . $key; } }
final class TestProductStatsDb
{
    public array $queries = [];
    public bool $fail_next_insert = false;
    public bool $fail_next_start = false;
    public bool $fail_next_commit = false;
    public bool $fail_lock = false;
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        if ($this->fail_next_insert && str_starts_with($sql, 'INSERT INTO')) { $this->fail_next_insert = false; return false; }
        if ($this->fail_next_start && $sql === 'START TRANSACTION') { $this->fail_next_start = false; return false; }
        if ($this->fail_next_commit && $sql === 'COMMIT') { $this->fail_next_commit = false; return false; }
        return 1;
    }
    public function get_var(string $sql): int { return str_contains($sql, 'GET_LOCK') && $this->fail_lock ? 0 : 1; }
}
class WC_Product
{
    public function __construct(private int $parent_id = 0) {}
    public function get_parent_id(): int { return $this->parent_id; }
}
class WC_Order_Item_Product
{
    public function __construct(private int $product_id) {}
    public function get_product_id(): int { return $this->product_id; }
}
class TestCart { public function get_cart(): array { return $GLOBALS['test_cart']; } }
class TestSession
{
    private array $data = [];
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
}
class TestWooCommerce { public TestCart $cart; public TestSession $session; public function __construct() { $this->cart = new TestCart(); $this->session = new TestSession(); } }
class WC_Order
{
    public array $meta = [];
    public string $status = 'processing';
    public int $save_count = 0;
    public array $item_ids = [31, 31, 32];
    public bool $paid = true;
    public float $total = 20.0;
    public function __construct(private int $id = 1) {}
    public function get_id(): int { return $this->id; }
    public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
    public function update_meta_data(string $key, mixed $value): void { $this->meta[$key] = $value; }
    public function add_meta_data(string $key, mixed $value, bool $unique = false): void { if (!$unique || !array_key_exists($key, $this->meta)) { $this->meta[$key] = $value; } }
    public function delete_meta_data(string $key): void { unset($this->meta[$key]); }
    public function save_meta_data(): void { $this->save_count++; }
    public function save(): int { $this->save_count++; return 1; }
    public function is_paid(): bool { return $this->paid; }
    public function get_status(): string { return $this->status; }
    public function get_total(): float { return $this->total; }
    public function get_items(string $type = ''): array { return array_map(static fn(int $id): WC_Order_Item_Product => new WC_Order_Item_Product($id), $this->item_ids); }
}
$GLOBALS['wpdb'] = new TestProductStatsDb();
$GLOBALS['test_transients'] = [];
$GLOBALS['test_transient_reads'] = 0;
$GLOBALS['scheduled_purchase_retries'] = [];
$GLOBALS['test_posts'] = [31 => ['post_type' => 'product', 'post_status' => 'publish'], 32 => ['post_type' => 'product', 'post_status' => 'publish'], 33 => ['post_type' => 'product', 'post_status' => 'publish'], 99 => ['post_type' => 'product', 'post_status' => 'draft']];
$GLOBALS['test_orders'] = [];
$GLOBALS['test_cart'] = [];
$GLOBALS['test_wc'] = new TestWooCommerce();
$GLOBALS['is_checkout'] = false;
$GLOBALS['privacy_policy_content'] = [];
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void { $GLOBALS['test_actions'][$hook] = $callback; }
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool { $GLOBALS['scheduled_purchase_retries'][] = [$timestamp, $hook, $args]; return true; }
function wp_date(string $format, ?int $timestamp = null): string { return gmdate($format, $timestamp ?? time()); }
function current_time(string $type): int|string { return $type === 'timestamp' ? time() : gmdate('Y-m-d'); }
function wp_salt(string $scheme = 'auth'): string { return 'offline'; }
function get_transient(string $key): mixed { $GLOBALS['test_transient_reads']++; return $GLOBALS['test_transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $expiration = 0): bool { $GLOBALS['test_transients'][$key] = $value; return true; }
function get_post(int $id): object|null { return isset($GLOBALS['test_posts'][$id]) ? (object) $GLOBALS['test_posts'][$id] : null; }
function wc_get_product(int $id): WC_Product|false { return get_post($id) ? new WC_Product($id === 33 ? 31 : 0) : false; }
function wc_get_order(int $id): WC_Order|false { return $GLOBALS['test_orders'][$id] ?? false; }
function is_checkout(): bool { return $GLOBALS['is_checkout']; }
function is_order_received_page(): bool { return false; }
function WC(): TestWooCommerce { return $GLOBALS['test_wc']; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? ''; }
function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function wp_add_privacy_policy_content(string $title, string $content): void { $GLOBALS['privacy_policy_content'][] = [$title, $content]; }
require_once __DIR__ . '/../includes/analytics/class-adc-product-stats.php';
require_once __DIR__ . '/../includes/analytics/class-adc-analytics-settings.php';
function check_collect(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

// Both collection gates precede endpoint throttling and checkout-meta writes.
$checkout_order = new WC_Order(400);
$checkout_order->meta['_adc_stats_consent'] = 'existing';
ADC_Product_Stats::capture_checkout_consent($checkout_order);
ADC_Product_Stats::capture_store_api_consent($checkout_order);
check_collect($checkout_order->get_meta('_adc_stats_consent') === '' && $checkout_order->save_count === 1, 'enabled=off deletes any prior checkout consent snapshot');
$token = hash_hmac('sha256', (string) floor(time() / 86400), 'offline');
$valid = static fn(string $metric, int $id): WP_REST_Request => new WP_REST_Request(['token' => $token, 'metric' => $metric, 'product_id' => $id]);
$before_queries = count($GLOBALS['wpdb']->queries);
$before_reads = $GLOBALS['test_transient_reads'];
ADC_Product_Stats::handle_event($valid('view', 31));
check_collect(count($GLOBALS['wpdb']->queries) === $before_queries && $GLOBALS['test_transient_reads'] === $before_reads && $GLOBALS['test_transients'] === [], 'enabled=off REST request writes no transient or metric');

ADC_Settings::$enabled = true;
ADC_Product_Stats::capture_checkout_consent($checkout_order);
check_collect($checkout_order->get_meta('_adc_stats_consent') === '', 'enabled but refused checkout deletes the consent snapshot');
$before_reads = $GLOBALS['test_transient_reads'];
ADC_Product_Stats::handle_event($valid('view', 31));
check_collect($GLOBALS['test_transient_reads'] === $before_reads && $GLOBALS['test_transients'] === [], 'refused consent does not consume rate-limit budget');
ADC_Consent::$statistics = true;
ADC_Product_Stats::capture_checkout_consent($checkout_order);
check_collect($checkout_order->get_meta('_adc_stats_consent') === '1', 'enabled checkout records granted statistics consent');
$before_reads = $GLOBALS['test_transient_reads'];
$invalid_token = ADC_Product_Stats::handle_event(new WP_REST_Request(['token' => 'invalid', 'metric' => 'view', 'product_id' => 31]));
check_collect($invalid_token instanceof WP_Error && ($invalid_token->data['status'] ?? 0) === 403 && $GLOBALS['test_transient_reads'] === $before_reads, 'invalid token is rejected without consuming the limiter');

// Token accepts the prior daily period; throttling buckets are metric + canonical product + minute.
$previous_token = hash_hmac('sha256', (string) (floor(time() / 86400) - 1), 'offline');
$previous_day_request = new WP_REST_Request(['token' => $previous_token, 'metric' => 'view', 'product_id' => 33]);
$response = ADC_Product_Stats::handle_event($previous_day_request);
$minute = (string) floor(time() / 60);
check_collect($response instanceof WP_REST_Response && isset($GLOBALS['test_transients']["adc_product_stats_rl_views_31_{$minute}"]), 'daily token allows previous day and canonicalizes a variation to its parent');
$ajax_token = hash_hmac('sha256', (string) floor(time() / 86400), 'offline');
$ajax_response = ADC_Product_Stats::process_event(['action' => 'adc_product_stats', 'metric' => 'view', 'product_id' => '32', 'token' => $ajax_token], http_build_query(['metric' => 'view', 'product_id' => '32', 'token' => $ajax_token]));
check_collect($ajax_response instanceof WP_REST_Response && isset($GLOBALS['test_transients']["adc_product_stats_rl_views_32_{$minute}"]), 'AJAX transport shares enabled/consent, daily HMAC, canonical validation, and rate limiting');
ADC_Product_Stats::boot();
check_collect(isset($GLOBALS['test_actions']['wp_ajax_nopriv_adc_product_stats']), 'anonymous AJAX fallback is registered');
$reads_after_view = $GLOBALS['test_transient_reads'];
ADC_Product_Stats::handle_event($valid('view', 32));
check_collect($GLOBALS['test_transient_reads'] === $reads_after_view + 1 && isset($GLOBALS['test_transients']["adc_product_stats_rl_views_32_{$minute}"]), 'rate limit uses an independent product bucket');
ADC_Product_Stats::handle_event($valid('view', 99));
check_collect($GLOBALS['test_transient_reads'] === $reads_after_view + 1, 'unpublished product rejected before rate limiting');
ADC_Product_Stats::handle_event($valid('begin_checkout', 31));
check_collect($GLOBALS['test_transient_reads'] === $reads_after_view + 1, 'REST rejects server-only checkout metrics before rate limiting');
$before_query_count = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::handle_event(new WP_REST_Request(['token' => $token, 'metric' => 'view', 'product_id' => 31], str_repeat('x', 1025)));
check_collect(count($GLOBALS['wpdb']->queries) === $before_query_count, 'oversized payload is rejected');

// Checkout is counted once in the existing WooCommerce session, even on refresh.
$GLOBALS['is_checkout'] = true;
$GLOBALS['test_cart'] = [['product_id' => 31], ['product_id' => 31], ['product_id' => 32]];
$before_checkout = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_checkout_page();
$after_first_checkout = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_checkout_page();
check_collect($after_first_checkout === $before_checkout + 2 && count($GLOBALS['wpdb']->queries) === $after_first_checkout, 'checkout counts distinct products only once per WooCommerce session');
ADC_Product_Stats::reset_checkout_session_flag(501);
$before_second_cart = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_checkout_page();
check_collect(count($GLOBALS['wpdb']->queries) === $before_second_cart + 2, 'order completion resets checkout dedupe for a new cart');
ADC_Product_Stats::reset_checkout_session_flag();
$GLOBALS['wpdb']->fail_next_insert = true;
$before_failed_checkout = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_checkout_page();
check_collect(count($GLOBALS['wpdb']->queries) === $before_failed_checkout + 1, 'checkout session flag stays unset when a metric insert fails');
$before_checkout_retry = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_checkout_page();
check_collect(count($GLOBALS['wpdb']->queries) === $before_checkout_retry + 2, 'checkout retries counting when increment previously failed');

// Paid order claims are transactional, HPOS CRUD-based, deduplicated, and parent-product distinct.
$order = new WC_Order(501);
$order->meta['_adc_stats_consent'] = '1';
$order->item_ids = [31, 31, 32, 33];
$GLOBALS['test_orders'][501] = $order;
$order->paid = false;
$before_unpaid = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_order_status_changed(501, 'pending', 'processing', $order);
check_collect($order->get_meta('_adc_product_stats_purchased') === '' && count($GLOBALS['wpdb']->queries) === $before_unpaid, 'unpaid non-free order is ignored');
$order->paid = true;
$order->status = 'processing';
$before_paid = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_order_status_changed(501, 'pending', 'processing', $order);
$after_paid = count($GLOBALS['wpdb']->queries);
$paid_queries = array_slice($GLOBALS['wpdb']->queries, $before_paid);
check_collect($order->get_meta('_adc_product_stats_purchased') === '1' && substr_count(implode("\n", $paid_queries), 'INSERT INTO') === 2 && in_array('START TRANSACTION', $paid_queries, true), 'paid purchase claims before increment and counts each distinct parent product once');
ADC_Product_Stats::on_payment_complete($order);
check_collect(count($GLOBALS['wpdb']->queries) === $after_paid, 'second paid hook does not duplicate purchase counts');

$free_order = new WC_Order(502);
$free_order->meta['_adc_stats_consent'] = '1';
$free_order->paid = false;
$free_order->total = 0;
$GLOBALS['test_orders'][502] = $free_order;
ADC_Product_Stats::on_order_status_changed(502, 'pending', 'processing', $free_order);
check_collect($free_order->get_meta('_adc_product_stats_purchased') === '1', 'free order is counted when it reaches an eligible paid status');
$free_retry_order = new WC_Order(509);
$free_retry_order->meta['_adc_stats_consent'] = '1';
$free_retry_order->paid = false;
$free_retry_order->total = 0;
$GLOBALS['test_orders'][509] = $free_retry_order;
$GLOBALS['wpdb']->fail_next_insert = true;
ADC_Product_Stats::on_order_status_changed(509, 'pending', 'processing', $free_retry_order);
ADC_Product_Stats::retry_purchase(509, 1);
check_collect($free_retry_order->get_meta('_adc_product_stats_purchased') === '1', 'automatic retry supports eligible zero-total orders');
$GLOBALS['scheduled_purchase_retries'] = [];

$unsupported_status_order = new WC_Order(505);
$unsupported_status_order->meta['_adc_stats_consent'] = '1';
$unsupported_status_order->status = 'on-hold';
$GLOBALS['test_orders'][505] = $unsupported_status_order;
$before_unsupported = count($GLOBALS['wpdb']->queries);
ADC_Product_Stats::on_payment_complete($unsupported_status_order);
check_collect(count($GLOBALS['wpdb']->queries) === $before_unsupported, 'payment-complete ignores paid status not configured for purchases');

$retry_order = new WC_Order(503);
$retry_order->meta['_adc_stats_consent'] = '1';
$retry_order->item_ids = [31, 32];
$GLOBALS['test_orders'][503] = $retry_order;
$GLOBALS['wpdb']->fail_next_insert = true;
ADC_Product_Stats::on_order_status_changed(503, 'pending', 'processing', $retry_order);
check_collect($retry_order->get_meta('_adc_product_stats_purchased') === '' && count($GLOBALS['scheduled_purchase_retries']) === 1, 'failed aggregate increment releases claim and schedules automatic retry');
ADC_Product_Stats::retry_purchase(503, 1);
check_collect($retry_order->get_meta('_adc_product_stats_purchased') === '1', 'scheduled purchase retry claims and counts the order');
$lock_retry_order = new WC_Order(506);
$lock_retry_order->meta['_adc_stats_consent'] = '1';
$GLOBALS['test_orders'][506] = $lock_retry_order;
$GLOBALS['wpdb']->fail_lock = true;
ADC_Product_Stats::on_payment_complete($lock_retry_order);
$GLOBALS['wpdb']->fail_lock = false;
check_collect(count($GLOBALS['scheduled_purchase_retries']) === 2, 'failed GET_LOCK schedules purchase retry');
$transaction_retry_order = new WC_Order(507);
$transaction_retry_order->meta['_adc_stats_consent'] = '1';
$GLOBALS['test_orders'][507] = $transaction_retry_order;
$GLOBALS['wpdb']->fail_next_start = true;
ADC_Product_Stats::on_payment_complete($transaction_retry_order);
check_collect(count($GLOBALS['scheduled_purchase_retries']) === 3, 'failed transaction start schedules purchase retry');
$commit_retry_order = new WC_Order(508);
$commit_retry_order->meta['_adc_stats_consent'] = '1';
$GLOBALS['test_orders'][508] = $commit_retry_order;
$GLOBALS['wpdb']->fail_next_commit = true;
ADC_Product_Stats::on_payment_complete($commit_retry_order);
check_collect(count($GLOBALS['scheduled_purchase_retries']) === 4, 'failed commit schedules purchase retry');
$retry_count_before_limit = count($GLOBALS['scheduled_purchase_retries']);
$GLOBALS['wpdb']->fail_next_commit = true;
ADC_Product_Stats::retry_purchase(508, 3);
check_collect(count($GLOBALS['scheduled_purchase_retries']) === $retry_count_before_limit, 'purchase retries stop after three scheduled attempts');
$retry_inserts_before_duplicate = substr_count(implode("\n", $GLOBALS['wpdb']->queries), 'INSERT INTO');
ADC_Product_Stats::retry_purchase(503, 1);
check_collect(substr_count(implode("\n", $GLOBALS['wpdb']->queries), 'INSERT INTO') === $retry_inserts_before_duplicate, 'duplicate retry cannot recount an already claimed order');

$denied_order = new WC_Order(504);
$denied_order->meta['_adc_stats_consent'] = '1';
ADC_Consent::$statistics = false;
ADC_Product_Stats::capture_checkout_consent($denied_order);
check_collect($denied_order->get_meta('_adc_stats_consent') === '', 'enabled but refused checkout removes the consent snapshot');
ADC_Consent::$statistics = true;
check_collect(str_contains(ADC_Product_Stats::privacy_policy_text(), 'dezvoltări Smart Feed') && str_contains(ADC_Product_Stats::privacy_policy_text(), 'nu dovedește'), 'privacy copy describes future product uses and token limitation');
ADC_Settings::$retention = 90;
check_collect(str_contains(ADC_Product_Stats::privacy_policy_text(), '90 zile'), 'privacy text refreshes when retention changes');
ADC_Product_Stats::register_privacy_policy_content();
check_collect(count($GLOBALS['privacy_policy_content']) === 1 && str_contains($GLOBALS['privacy_policy_content'][0][1], '90 zile'), 'WordPress privacy policy content is registered with current retention');
$analytics_template = file_get_contents(__DIR__ . '/../templates/admin/analytics-dashboard.php');
check_collect(is_string($analytics_template) && str_contains($analytics_template, 'name="product_stats[enabled]"') && str_contains($analytics_template, 'name="product_stats[retention_days]"'), 'product stats setting controls are rendered in analytics tab');
check_collect(str_contains($analytics_template, 'readonly') && str_contains($analytics_template, 'data-adc-copy="#adc-product-stats-privacy-copy"') && str_contains($analytics_template, 'nu înlocuiește și nu obține consimțământul'), 'privacy text is read-only, copyable, and warns consent is still required');
check_collect(str_contains($analytics_template, 'pot fi folosite în viitor') && !str_contains($analytics_template, 'folosim aceste agregate pentru Smart Feed'), 'UI describes Smart Feed and rankings as possible future uses');
echo "product-stats-collect-test: PASS\n";
