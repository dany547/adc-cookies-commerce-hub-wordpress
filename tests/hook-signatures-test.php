<?php
/**
 * Strict runtime hook-contract smoke, without WordPress, a database or HTTP.
 * Fixtures mirror WP core / WC public hook arguments, not callback reflection.
 * Every registered hook must have a fixture; every callback is really invoked.
 * Admin endpoints are exercised on denied capability and invalid nonce paths.
 * This is not a substitute for integration tests with real WP/WC.
 */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('ABSPATH', '/adc-hook-test-no-wordpress/');
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('EP_ROOT', 64);
define('EP_PAGES', 4096);
define('ARRAY_A', 'ARRAY_A');

final class ADC_Test_Stop extends RuntimeException {}
class WP_User {
    public int $ID = 23;
    public string $user_login = 'user';
    public string $user_email = 'user@example.invalid';
    public array $roles = array('customer');
}
class WP_Post { public int $ID = 42; }
class WP {}
class WP_Error {
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null) {}
}
class WP_REST_Server {
    public const READABLE = 'GET';
    public const CREATABLE = 'POST';
}
class WP_REST_Request {
    public function get_header(string $name): string { return ''; }
    public function get_body(): string { return '{}'; }
    public function get_param(string $name): mixed { return null; }
    public function get_json_params(): array { return array(); }
    public function get_params(): array { return array(); }
}
class WP_REST_Response {
    public function __construct(public mixed $data = null, public int $status = 200) {}
    public function header(string $name, string $value): void {}
}
class WC_Order {
    public array $meta = array();
    public function get_id(): int { return 42; }
    public function get_meta(string $key, bool $single = true): mixed { return $this->meta[$key] ?? ''; }
    public function update_meta_data(string $key, mixed $value): void { $this->meta[$key] = $value; }
    public function delete_meta_data(string $key): void { unset($this->meta[$key]); }
    public function save(): int { return 42; }
    public function get_customer_id(): int { return 0; }
    public function get_status(): string { return 'pending'; }
    public function get_refunds(): array { return array(); }
    public function get_currency(): string { return 'RON'; }
}
class WC_Order_Refund extends WC_Order {}
class WC_Product {
    public function get_id(): int { return 42; }
    public function get_parent_id(): int { return 0; }
    public function get_name(): string { return 'Produs test'; }
    public function get_category_ids(): array { return array(); }
}
class WC_Product_Variation extends WC_Product {
    public function get_variation_attributes(): array { return array('attribute_pa_color' => 'blue'); }
}
class WC_Cart {
    public array $cart_contents = array();
    public array $removed_cart_contents = array();
    public function get_cart_item(string $key): array { return $this->cart_contents[$key] ?? array(); }
}
class WC_Session {
    public array $data = array();
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function __unset(string $key): void { unset($this->data[$key]); }
}
class WooCommerce {
    public ?WC_Session $session = null;
    public ?WC_Cart $cart = null;
}
final class ADC_Test_DB {
    public string $prefix = 'wp_';
    public string $usermeta = 'wp_usermeta';
    public int $insert_id = 1;
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_results(string $sql, mixed $output = null): array { return array(); }
    public function get_col(string $sql): array { return array(); }
    public function get_var(string $sql): mixed { return null; }
    public function get_row(string $sql, mixed $output = null): mixed { return null; }
    public function query(string $sql): int { return 0; }
    public function update(mixed ...$args): int { return 0; }
    public function delete(mixed ...$args): int { return 0; }
    public function insert(mixed ...$args): int { return 1; }
}
$GLOBALS['wpdb'] = new ADC_Test_DB();
$GLOBALS['adc_test_hooks'] = array();
$GLOBALS['adc_test_registration_sites'] = array();
$GLOBALS['adc_test_options'] = array('active_plugins' => array('woocommerce/woocommerce.php'), 'adc_db_version' => '1', 'adc_analytics_db_version' => '1');
$GLOBALS['adc_test_meta'] = array();
$GLOBALS['adc_test_cap'] = false;
$GLOBALS['adc_test_nonce'] = false;
$GLOBALS['adc_test_routes'] = array();
$GLOBALS['adc_test_checks'] = array();
$_POST = array('tab' => 'marketing', '_adc_nonce' => 'invalid');
$GLOBALS['adc_test_wc'] = new WooCommerce();

function add_action(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool {
    $GLOBALS['adc_test_hooks'][$hook][$priority][] = array($callback, $accepted_args);
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2) as $frame) {
        if (isset($frame['file'], $frame['line']) && $frame['file'] !== __FILE__) {
            $GLOBALS['adc_test_registration_sites'][$frame['file'] . ':' . $frame['line']] = true;
        }
    }
    return true;
}
function add_filter(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool { return add_action($hook, $callback, $priority, $accepted_args); }
function do_action(string $hook, mixed ...$args): void {
    $groups = $GLOBALS['adc_test_hooks'][$hook] ?? array();
    ksort($groups);
    foreach ($groups as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    $groups = $GLOBALS['adc_test_hooks'][$hook] ?? array();
    ksort($groups);
    foreach ($groups as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge(array($value), $args), 0, $accepted));
        }
    }
    return $value;
}
function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://example.invalid/plugin/'; }
function plugin_basename(string $file): string { return 'adc-marketing/' . basename($file); }
function register_activation_hook(string $file, callable $callback): void { add_action('activate_adc-marketing/adc-marketing.php', $callback); }
function register_deactivation_hook(string $file, callable $callback): void { add_action('deactivate_adc-marketing/adc-marketing.php', $callback); }
function load_plugin_textdomain(mixed ...$args): bool { return true; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['adc_test_options'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['adc_test_options'][$key] = $value; return true; }
function get_user_meta(int $id, string $key, bool $single = false): mixed { return $GLOBALS['adc_test_meta'][$id][$key] ?? ($single ? '' : array()); }
function update_user_meta(int $id, string $key, mixed $value): bool { $GLOBALS['adc_test_meta'][$id][$key] = $value; return true; }
function delete_user_meta(int $id, string $key): bool { unset($GLOBALS['adc_test_meta'][$id][$key]); return true; }
function get_current_user_id(): int { return 0; }
function is_user_logged_in(): bool { return false; }
function get_userdata(int $id): WP_User|false { return new WP_User(); }
function get_user_by(string $field, mixed $value): WP_User|false { return false; }
function current_user_can(string $cap, mixed ...$args): bool { $GLOBALS['adc_test_checks']['cap'] = true; return $GLOBALS['adc_test_cap']; }
function is_admin(): bool { return true; }
function __return_true(): bool { return true; }
function get_current_screen(): mixed { return null; }
function do_shortcode(string $text): string { return $text; }
function add_menu_page(mixed ...$args): string { return 'toplevel_page_adc-marketing'; }
function wp_print_inline_script_tag(string $script, array $attributes = array()): void {}
function wp_parse_url(string $url, int $component = -1): array|string|int|false|null { return parse_url($url, $component); }
function is_front_page(): bool { return false; }
function is_home(): bool { return false; }
function is_product(): bool { return false; }
function is_product_category(): bool { return false; }
function is_product_tag(): bool { return false; }
function is_shop(): bool { return false; }
function is_singular(mixed $type = ''): bool { return false; }
function is_archive(): bool { return false; }
function is_page(mixed $page = ''): bool { return false; }
function is_ssl(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function wp_has_consent(string $category = ''): bool { return false; }
function wp_unslash(mixed $value): mixed { return $value; }
function sanitize_text_field(mixed $value): string { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? ''; }
function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $value): string { return esc_html($value); }
function esc_url(string $value): string { return $value; }
function esc_url_raw(string $value): string { return $value; }
function esc_html__(string $text, string $domain = 'default'): string { return esc_html($text); }
function esc_attr__(string $text, string $domain = 'default'): string { return esc_attr($text); }
function esc_html_e(string $text, string $domain = 'default'): void { echo esc_html($text); }
function esc_attr_e(string $text, string $domain = 'default'): void { echo esc_attr($text); }
function wp_kses_post(string $value): string { return $value; }
function selected(mixed $value, mixed $expected, bool $echo = true): string { return ''; }
function checked(mixed $value, mixed $expected = true, bool $echo = true): string { return ''; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function wp_salt(string $scheme = 'auth'): string { return 'offline-test-only-not-a-credential'; }
function wp_generate_uuid4(): string { return '12345678-1234-4234-8234-123456789abc'; }
function wp_die(mixed ...$args): never { throw new ADC_Test_Stop('wp_die'); }
function wp_send_json_error(mixed ...$args): never { throw new ADC_Test_Stop('json_error'); }
function wp_send_json_success(mixed ...$args): never { throw new RuntimeException('Unexpected successful endpoint'); }
function wp_verify_nonce(mixed $nonce, string $action): bool { $GLOBALS['adc_test_checks']['nonce'] = true; return $GLOBALS['adc_test_nonce']; }
function check_admin_referer(string $action, string $field = '_wpnonce'): int { $GLOBALS['adc_test_checks']['nonce'] = true; if (!$GLOBALS['adc_test_nonce']) { throw new ADC_Test_Stop('nonce'); } return 1; }
function check_ajax_referer(string $action, mixed $field = false, bool $stop = true): int { return check_admin_referer($action); }
function wp_nonce_field(mixed ...$args): string { return ''; }
function wp_create_nonce(string $action): string { return 'offline-nonce'; }
function register_setting(mixed ...$args): void {}
function add_rewrite_endpoint(mixed ...$args): void {}
function add_shortcode(string $name, callable $callback): void {}
function wp_next_scheduled(mixed ...$args): int|false { return 1; }
function wp_schedule_event(mixed ...$args): bool { return true; }
function wp_schedule_single_event(mixed ...$args): bool { return true; }
function wp_clear_scheduled_hook(mixed ...$args): int { return 0; }
function flush_rewrite_rules(bool $hard = true): void {}
function get_transient(string $key): mixed { return false; }
function set_transient(string $key, mixed $value, int $expiration = 0): bool { return true; }
function delete_transient(string $key): bool { return true; }
function wp_privacy_add_additional_data(mixed ...$args): void {}
function wp_add_privacy_policy_content(mixed ...$args): void {}
function wp_enqueue_style(mixed ...$args): void {}
function wp_enqueue_script(mixed ...$args): void {}
function wp_register_script(mixed ...$args): void {}
function wp_register_style(mixed ...$args): void {}
function wp_localize_script(mixed ...$args): bool { return true; }
function wp_add_inline_script(mixed ...$args): bool { return true; }
function wp_add_inline_style(mixed ...$args): bool { return true; }
function wp_script_is(mixed ...$args): bool { return false; }
function wp_style_is(mixed ...$args): bool { return false; }
function home_url(string $path = ''): string { return 'https://example.invalid' . $path; }
function admin_url(string $path = ''): string { return home_url('/wp-admin/' . $path); }
function rest_url(string $path = ''): string { return home_url('/wp-json/' . $path); }
function wp_get_privacy_policy_url(): string { return home_url('/privacy/'); }
function get_privacy_policy_url(): string { return wp_get_privacy_policy_url(); }
function get_locale(): string { return 'ro_RO'; }
function get_bloginfo(string $show = ''): string { return 'Offline'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string { return gmdate($format, $timestamp ?? time()); }
function current_time(string $type, bool $gmt = false): string|int { return 'timestamp' === $type ? time() : gmdate('Y-m-d H:i:s'); }
function wc_get_order(mixed $id): WC_Order|false { return false; }
function wc_get_orders(mixed ...$args): array { return array(); }
function wc_get_product(mixed $id): WC_Product|false { return false; }
function WC(): WooCommerce { return $GLOBALS['adc_test_wc']; }
function get_woocommerce_currency(): string { return 'RON'; }
function wc_get_price_decimals(): int { return 2; }
function wc_get_price_including_tax(WC_Product $product, array $args = array()): float { return 10.0 * ($args['qty'] ?? 1); }
function wc_get_price_excluding_tax(WC_Product $product, array $args = array()): float { return 10.0 * ($args['qty'] ?? 1); }
function wc_attribute_label(string $name, mixed $product = ''): string { return 'Culoare'; }
function taxonomy_exists(string $taxonomy): bool { return false; }
function wc_is_rest_api_request(): bool { return false; }
function is_account_page(): bool { return false; }
function is_checkout(): bool { return false; }
function is_cart(): bool { return false; }
function is_search(): bool { return false; }
function is_order_received_page(): bool { return false; }
function is_wc_endpoint_url(string $endpoint = ''): bool { return false; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function register_rest_route(string $namespace, string $route, array $args): bool { $GLOBALS['adc_test_routes'][$namespace . $route] = $args; return true; }
function wp_safe_remote_get(mixed ...$args): never { throw new RuntimeException('HTTP forbidden in offline hook tests'); }
function wp_remote_get(mixed ...$args): never { throw new RuntimeException('HTTP forbidden in offline hook tests'); }
function wp_remote_post(mixed ...$args): never { throw new RuntimeException('HTTP forbidden in offline hook tests'); }

require dirname(__DIR__) . '/adc-marketing.php';
// Resolve all shipped declarations through the real autoloader, including
// classes the feature gates or registry might otherwise silently skip.
$registration_sites = array();
$sources = array_merge(array(dirname(__DIR__) . '/adc-marketing.php'), glob(dirname(__DIR__) . '/includes/*.php') ?: array(), glob(dirname(__DIR__) . '/includes/*/*.php') ?: array());
foreach ($sources as $source) {
    $code = '';
    foreach (token_get_all((string) file_get_contents($source)) as $token) {
        if (is_array($token) && T_STRING === $token[0] && in_array($token[1], array('add_action', 'add_filter'), true)) {
            $registration_sites[] = $source . ':' . $token[2];
        }
        $code .= is_array($token) ? (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING), true) ? ' ' : $token[1]) : $token;
    }
    preg_match_all('/\\b(class|interface|trait)\\s+(ADC_\\w+)/', $code, $declarations, PREG_SET_ORDER);
    foreach ($declarations as [, $kind, $name]) {
        // The analytics stats trait shares the dashboard class file by design.
        if ('ADC_Admin_Analytics_Stats' === $name) { class_exists('ADC_Admin_Tab_Dashboard'); }
        $exists = match ($kind) { 'interface' => interface_exists($name), 'trait' => trait_exists($name), default => class_exists($name) };
        if (!$exists) { throw new RuntimeException('Unresolvable declaration: ' . $name); }
    }
    preg_match_all('/\\b(ADC_\\w+)::(\\w+)\\s*\\(/', $code, $calls, PREG_SET_ORDER);
    foreach ($calls as [, $class, $method]) {
        if (!method_exists($class, $method)) { throw new RuntimeException('Missing static method: ' . $class . '::' . $method); }
        $reflection = new ReflectionMethod($class, $method);
        if (!$reflection->isStatic() || !$reflection->isPublic()) { throw new RuntimeException('Invalid static call: ' . $class . '::' . $method); }
    }
}
// Boot the complete plugin, including conditional WC and Consent API hooks.
do_action('plugins_loaded');
do_action('init');
ADC_Admin_Tab_Dashboard::boot();
ADC_Admin_Tab_Analytics::boot();
// Register the inactive-WC notice branch too, without touching a real site.
$GLOBALS['adc_test_options']['active_plugins'] = array();
adc_marketing_init();
$GLOBALS['adc_test_options']['active_plugins'] = array('woocommerce/woocommerce.php');

foreach ($registration_sites as $site) {
    if (empty($GLOBALS['adc_test_registration_sites'][$site])) {
        throw new RuntimeException('Unexercised add_action/add_filter site: ' . $site);
    }
}

$user = new WP_User();
$order = new WC_Order();
$cart = new WC_Cart();
$fixtures = array(
    'plugins_loaded' => array(), 'before_woocommerce_init' => array(),
    'admin_notices' => array(), 'admin_menu' => array(''), 'admin_head' => array(),
    'admin_init' => array(), 'admin_enqueue_scripts' => array('other_page'),
    'in_footer' => array(), 'wp_enqueue_scripts' => array(), 'wp_head' => array(),
    'wp_footer' => array(), 'wp_body_open' => array(), 'init' => array(),
    'rest_api_init' => array(null), 'wp' => array(new WP()), 'template_redirect' => array(),
    'wp_login' => array('user', $user), 'user_register' => array(23, array('user_login' => 'user')),
    'delete_user' => array(23, null, $user),
    'show_user_profile' => array($user), 'edit_user_profile' => array($user),
    'personal_options_update' => array(23), 'edit_user_profile_update' => array(23),
    'restrict_manage_posts' => array('shop_order', 'top'),
    'manage_edit-shop_order_columns' => array(array('order_status' => 'Status')),
    'manage_woocommerce_page_wc-orders_columns' => array(array('order_status' => 'Status')),
    'manage_shop_order_posts_custom_column' => array('adc_marketing_consent', 42),
    'manage_woocommerce_page_wc-orders_custom_column' => array('adc_marketing_consent', $order),
    'woocommerce_order_list_table_restrict_manage_orders' => array('shop_order', 'top'),
    'woocommerce_order_list_table_prepare_items_query_args' => array(array()),
    'woocommerce_admin_order_data_after_order_details' => array($order),
    'woocommerce_review_order_before_submit' => array(),
    'woocommerce_checkout_update_order_meta' => array(42, array()),
    'woocommerce_checkout_create_order' => array($order, array()),
    'woocommerce_store_api_checkout_order_processed' => array($order),
    'woocommerce_account_menu_items' => array(array('edit-account' => 'Cont'), array()),
    'woocommerce_account_marketing-consent_endpoint' => array(''),
    'woocommerce_checkout_update_order_review' => array('billing_email=user%40example.invalid'),
    'woocommerce_created_customer' => array(23, array(), false),
    'woocommerce_add_to_cart' => array('cart-key', 42, 2, 0, array(), array()),
    'woocommerce_remove_cart_item' => array('cart-key', $cart),
    'woocommerce_cart_item_removed' => array('cart-key', $cart),
    'woocommerce_add_to_cart_fragments' => array(array()),
    'woocommerce_payment_complete' => array(42, ''),
    'woocommerce_order_status_changed' => array(42, 'pending', 'processing', $order),
    'woocommerce_order_refunded' => array(42, 43), 'woocommerce_new_order' => array(42, $order),
    'wp_privacy_personal_data_exporters' => array(array()),
    'wp_privacy_personal_data_erasers' => array(array()), 'site_status_tests' => array(array()),
    'adc_marketing_default_consent' => array('no'), 'adc_register_settings' => array(),
    'adc_frontend_config' => array(array()), 'adc_admin_tabs' => array(array()),
    'adc_integrations' => array(array()),
    'adc_track_event' => array(new ADC_Event('login', 'offline-event', time(), 'server')),
    'adc_consent_withdrawn' => array(ADC_Consent_State::denied(), array('marketing')),
    'wp_get_consent_type' => array(false),
    'wp_consent_api_registered_' . ADC_MARKETING_PLUGIN_BASENAME => array(false),
    'update_plugins_github.com' => array(false, array(), 'other/plugin.php', array('ro_RO')),
    'plugins_api' => array(false, 'plugin_information', (object) array('slug' => 'other')),
    'upgrader_pre_download' => array(false, 'https://example.invalid/plugin.zip', new stdClass(), array()),
    'http_headers_useragent' => array('WordPress', 'https://example.invalid/'),
    ADC_Dispatcher::JOB_HOOK => array(0), ADC_Consent_Privacy::CRON_HOOK => array(),
    ADC_Retention::CRON_HOOK => array(), ADC_Retention::ERASE_HOOK => array(0),
    ADC_Analytics_Retention::HOOK => array(),
    ADC_Analytics_Commerce::JOB_HOOK => array('placed', 0, 0, '2026-01-01', 0),
);

// Activation executes schema installation (requires WP dbDelta), covered by
// analytics integration tests rather than pretending dbDelta is a no-op.
$activation = 'activate_adc-marketing/adc-marketing.php';
$fixtures['deactivate_adc-marketing/adc-marketing.php'] = array(false);
$failed = 0;
$count = 0;
ob_start();
foreach ($GLOBALS['adc_test_hooks'] as $hook => $groups) {
    foreach ($groups as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $name = is_array($callback) ? (is_object($callback[0]) ? get_class($callback[0]) : $callback[0]) . '::' . $callback[1] : (is_string($callback) ? $callback : 'closure');
            try {
                if (!is_callable($callback)) { throw new RuntimeException('Callback not callable'); }
                if ($hook === $activation) { continue; }
                $endpoint = str_starts_with($hook, 'admin_post_') || str_starts_with($hook, 'wp_ajax_');
                if (!$endpoint && !array_key_exists($hook, $fixtures)) { throw new RuntimeException('Missing real hook fixture: ' . $hook); }
                $args = array_slice($fixtures[$hook] ?? array(), 0, $accepted);
                if ($endpoint) {
                    foreach (array(array(false, true), array(true, false)) as [$cap, $nonce]) {
                        $GLOBALS['adc_test_cap'] = $cap;
                        $GLOBALS['adc_test_nonce'] = $nonce;
                        $GLOBALS['adc_test_checks'] = array();
                        try {
                            $callback(...$args);
                            throw new RuntimeException('Endpoint did not reject denied capability/nonce');
                        } catch (ADC_Test_Stop $stop) {
                            // Only deliberate WP denial is expected; never swallow Error/TypeError.
                        }
                        $required = $cap ? 'nonce' : 'cap';
                        if (empty($GLOBALS['adc_test_checks'][$required])) {
                            throw new RuntimeException('Missing endpoint guard: ' . $required);
                        }
                    }
                    $GLOBALS['adc_test_cap'] = false;
                } else {
                    $callback(...$args);
                }
                ++$count;
            } catch (Throwable $error) {
                ++$failed;
                fwrite(STDERR, "FAIL {$hook} {$name}: " . $error->getMessage() . "\n");
            }
        }
    }
}
// REST is intentionally public (anonymous visitors); HMAC guards run inside
// callbacks, not as WP capability checks. Missing tokens must fail closed.
$rest_count = 0;
foreach ($GLOBALS['adc_test_routes'] as $route => $endpoints) {
    if (isset($endpoints['callback'])) { $endpoints = array($endpoints); }
    foreach ($endpoints as $endpoint) {
        if (!is_array($endpoint) || !isset($endpoint['callback'])) { continue; }
        try {
            if (!isset($endpoint['permission_callback']) || !is_callable($endpoint['permission_callback'])) {
                throw new RuntimeException('Missing REST permission callback');
            }
            ($endpoint['permission_callback'])(new WP_REST_Request());
            $response = ($endpoint['callback'])(new WP_REST_Request());
            if (!$response instanceof WP_Error || ($response->data['status'] ?? 0) !== 403) {
                throw new RuntimeException('REST did not reject missing token');
            }
            ++$rest_count;
        } catch (Throwable $error) {
            ++$failed;
            fwrite(STDERR, 'FAIL REST ' . $route . ': ' . $error->getMessage() . "\n");
        }
    }
}

function adc_test_assert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    // WP user_login is not an ID, even if it happens to contain digits.
    $now = time();
    $state = static fn(int $ts, int $policy = 1, bool $marketing = true): ADC_Consent_State => new ADC_Consent_State(true, false, $marketing, true, $ts, $policy, '12345678-1234-4234-8234-123456789abc', 'banner');
    $meta_key = ADC_Consent_Privacy::USER_META;
    $set_cookie = static function (?ADC_Consent_State $value): void {
        unset($_COOKIE[ADC_Consent::COOKIE_NAME]);
        if (null !== $value) { $_COOKIE[ADC_Consent::COOKIE_NAME] = $value->to_cookie_value(); }
    };
    $set_meta = static function (?ADC_Consent_State $value) use ($user, $meta_key): void {
        unset($GLOBALS['adc_test_meta'][$user->ID][$meta_key]);
        if (null !== $value) { update_user_meta($user->ID, $meta_key, wp_json_encode($value->to_array())); }
    };
    $cookie = $state($now - 10);
    $older = $state($now - 100, 1, false);
    $set_cookie($cookie);
    $set_meta($older);
    do_action('wp_login', '999', $user);
    adc_test_assert(get_user_meta($user->ID, $meta_key, true) === wp_json_encode($cookie->to_array()), 'Newer cookie must hydrate authenticated user ID');
    adc_test_assert(!isset($GLOBALS['adc_test_meta'][999]), 'Never hydrate username as user ID');
    $set_cookie($older);
    $set_meta($cookie);
    do_action('wp_login', 'user', $user);
    adc_test_assert(get_user_meta($user->ID, $meta_key, true) === wp_json_encode($cookie->to_array()), 'Newer meta must win over older cookie');
    $set_cookie($cookie);
    $set_meta(null);
    do_action('wp_login', 'user', $user);
    adc_test_assert(get_user_meta($user->ID, $meta_key, true) === wp_json_encode($cookie->to_array()), 'Cookie-only login must create user meta');
    $set_cookie(null);
    $set_meta($cookie);
    do_action('wp_login', 'user', $user);
    adc_test_assert(get_user_meta($user->ID, $meta_key, true) === wp_json_encode($cookie->to_array()), 'Meta-only login must preserve decision');
    $set_meta(null);
    $set_cookie($state($now - 400 * DAY_IN_SECONDS));
    do_action('wp_login', 'user', $user);
    adc_test_assert('' === get_user_meta($user->ID, $meta_key, true), 'Expired cookie must not hydrate');
    $GLOBALS['adc_test_options']['adc_settings_consent'] = array('policy_version' => 2);
    $set_cookie($cookie);
    do_action('wp_login', 'user', $user);
    adc_test_assert('' === get_user_meta($user->ID, $meta_key, true), 'Old-policy cookie must not hydrate');
    unset($GLOBALS['adc_test_options']['adc_settings_consent']);
    $set_cookie(null);

    // WC accepts float stock amounts, even while analytics collection is OFF.
    do_action('woocommerce_add_to_cart', 'fractional', 42, 1.5, 0, array(), array());

    // Exercise real product/variation building, not just signature early returns.
    $item = ADC_Event_Builder::product_to_item(new WC_Product_Variation());
    adc_test_assert(str_contains($item['item_variant'] ?? '', 'Culoare'), 'Variation attribute label must resolve via WooCommerce API');
    $GLOBALS['adc_test_wc']->session = new WC_Session();
    ADC_Pending_Events::clear();
    $entry = array('data' => new WC_Product(), 'product_id' => 42, 'quantity' => 1);
    $cart->cart_contents['before'] = $entry;
    do_action('woocommerce_remove_cart_item', 'before', $cart);
    unset($cart->cart_contents['before']);
    $cart->removed_cart_contents['before'] = $entry;
    do_action('woocommerce_cart_item_removed', 'before', $cart);
    $events = ADC_Pending_Events::pull();
    adc_test_assert(count($events) === 1, 'Pre/post WC removal hooks must emit exactly one event');
    $cart->removed_cart_contents['after-only'] = $entry;
    do_action('woocommerce_cart_item_removed', 'after-only', $cart);
    adc_test_assert(ADC_Pending_Events::has(), 'Post-removal callback must read removed_cart_contents');
    $fragments = apply_filters('woocommerce_add_to_cart_fragments', array());
    adc_test_assert(isset($fragments['adc_events']) && !ADC_Pending_Events::has(), 'Correct WC fragments filter must consume queued events');
} catch (Throwable $error) {
    ++$failed;
    fwrite(STDERR, 'FAIL regression: ' . $error->getMessage() . "\n");
}
ob_end_clean();
fwrite(STDOUT, "Hook runtime: {$count} callbacks, {$rest_count} REST callbacks, " . count($registration_sites) . " registration sites, {$failed} failures\n");
exit($failed > 0 ? 1 : 0);
