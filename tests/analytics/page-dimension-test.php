<?php
/**
 * Standalone test for page dimension, exclusions, bot/prefetch detection,
 * settings sanitization and the legal gate. Uses self-contained WordPress stubs.
 *
 * Run: php tests/analytics/page-dimension-test.php
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

/** Current test state for the conditional stubs below. */
$GLOBALS['adc_test'] = array();

/**
 * Read one stubbed conditional flag.
 */
function adc_test_flag(string $name): bool
{
    return !empty($GLOBALS['adc_test'][$name]);
}

function is_admin(): bool { return adc_test_flag('is_admin'); }
function wp_doing_ajax(): bool { return adc_test_flag('wp_doing_ajax'); }
function wp_doing_cron(): bool { return adc_test_flag('wp_doing_cron'); }
function is_feed(): bool { return adc_test_flag('is_feed'); }
function is_robots(): bool { return adc_test_flag('is_robots'); }
function is_trackback(): bool { return adc_test_flag('is_trackback'); }
function is_preview(): bool { return adc_test_flag('is_preview'); }
function is_customize_preview(): bool { return adc_test_flag('is_customize_preview'); }
function is_404(): bool { return adc_test_flag('is_404'); }
function is_search(): bool { return adc_test_flag('is_search'); }
function is_account_page(): bool { return adc_test_flag('is_account_page'); }
function is_cart(): bool { return adc_test_flag('is_cart'); }
function is_checkout(): bool { return adc_test_flag('is_checkout'); }
function is_order_received_page(): bool { return adc_test_flag('is_order_received_page'); }
function is_checkout_pay_page(): bool { return adc_test_flag('is_checkout_pay_page'); }
function is_add_payment_method_page(): bool { return adc_test_flag('is_add_payment_method_page'); }
function is_wc_endpoint_url(): bool { return adc_test_flag('is_wc_endpoint_url'); }
function is_front_page(): bool { return adc_test_flag('is_front_page'); }
function is_home(): bool { return adc_test_flag('is_home'); }
function is_shop(): bool { return adc_test_flag('is_shop'); }
function is_category(): bool { return adc_test_flag('is_category'); }
function is_tag(): bool { return adc_test_flag('is_tag'); }
function is_archive(): bool { return adc_test_flag('is_archive'); }
function current_user_can(string $capability): bool { return adc_test_flag($capability); }
function is_singular(string|array $post_types = ''): bool
{
    $want = $GLOBALS['adc_test']['singular'] ?? null;

    return $want !== null && ($post_types === '' || $post_types === $want);
}
function is_tax(string $taxonomy): bool { return ($GLOBALS['adc_test']['tax'] ?? null) === $taxonomy; }
function is_post_type_archive(string $post_type): bool { return ($GLOBALS['adc_test']['post_type_archive'] ?? null) === $post_type; }
function get_queried_object_id(): int { return (int) ($GLOBALS['adc_test']['queried_id'] ?? 0); }
function get_queried_object(): mixed { return $GLOBALS['adc_test']['queried_object'] ?? null; }
function wp_date(string $format): string { return $format === 'G' ? '14' : '2026-10-19'; }
function current_time(string $format): string { return '2026-10-19'; }
function home_url(): string { return 'https://example.ro/'; }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function wp_unslash(mixed $value): mixed { return $value; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function add_action(string $hook, callable $callback, int $priority = 10): void {}
function __(string $text, string $domain = ''): string { return $text; }

/**
 * Stand-in for the settings repository, backed by an array.
 */
final class ADC_Settings
{
    /** @var array<string,mixed> */
    public static array $stored = array();

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return self::$stored[$key] ?? $default;
    }

    public static function register_group(string $group, array $defaults, callable $sanitize, array $secret_keys = array()): void {}
}

/**
 * Stand-in for the store; counts calls so tests can prove nothing is written.
 */
final class ADC_Analytics_Store
{
    public static int $calls = 0;

    public static function increment_traffic(string $stat_date, int $hour, string $channel, string $page_type, int $object_id, int $views = 1): bool
    {
        ++self::$calls;

        return true;
    }
}

require_once __DIR__ . '/../../includes/core/interface-adc-module.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-settings.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-traffic.php';

$failures = 0;
$total    = 0;

/**
 * Assert strict equality and record the result.
 */
function adc_test_eq(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $total;
    ++$total;

    if ($expected !== $actual) {
        ++$failures;
        printf("FAIL %s: expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true));
    }
}

/**
 * Reset conditionals and request globals to a plain, countable GET page.
 */
function adc_test_reset(array $state = array()): void
{
    $GLOBALS['adc_test'] = $state;
    $_SERVER = array(
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI'    => '/produs/tricou/',
        'HTTP_ACCEPT'    => 'text/html,application/xhtml+xml',
        'HTTP_USER_AGENT'=> 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0',
    );
}

// --- Exclusions: a plain page is countable; each rule alone excludes it. ---
adc_test_reset();
adc_test_eq('plain GET page not excluded', false, ADC_Analytics_Traffic::is_excluded_request());

$excluded_flags = array(
    'is_admin', 'wp_doing_ajax', 'wp_doing_cron', 'is_feed', 'is_robots', 'is_trackback',
    'is_preview', 'is_customize_preview', 'is_404', 'is_search', 'is_account_page', 'is_cart',
    'is_checkout', 'is_order_received_page', 'is_checkout_pay_page', 'is_add_payment_method_page',
    'is_wc_endpoint_url', 'manage_options',
);
foreach ($excluded_flags as $flag) {
    adc_test_reset(array($flag => true));
    adc_test_eq("flag {$flag} excludes", true, ADC_Analytics_Traffic::is_excluded_request());
}

adc_test_reset();
$_SERVER['REQUEST_METHOD'] = 'POST';
adc_test_eq('POST excluded', true, ADC_Analytics_Traffic::is_excluded_request());

adc_test_reset();
$_SERVER['QUERY_STRING'] = 'page=2';
adc_test_eq('query string excluded', true, ADC_Analytics_Traffic::is_excluded_request());

adc_test_reset();
$_SERVER['REQUEST_URI'] = '/produs/tricou/?ref=1';
adc_test_eq('query in REQUEST_URI excluded', true, ADC_Analytics_Traffic::is_excluded_request());

adc_test_reset();
$_SERVER['HTTP_ACCEPT'] = 'application/json';
adc_test_eq('non-HTML accept excluded', true, ADC_Analytics_Traffic::is_excluded_request());

adc_test_reset();
$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
adc_test_eq('REST path excluded', true, ADC_Analytics_Traffic::is_excluded_request());

adc_test_reset();
$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
adc_test_eq('prefetch excluded via request check', true, ADC_Analytics_Traffic::is_excluded_request());

// --- Bot and prefetch detection ---
adc_test_eq('empty UA is bot', true, ADC_Analytics_Traffic::is_bot(''));
adc_test_eq('Googlebot is bot', true, ADC_Analytics_Traffic::is_bot('Mozilla/5.0 (compatible; Googlebot/2.1)'));
adc_test_eq('facebookexternalhit is bot', true, ADC_Analytics_Traffic::is_bot('facebookexternalhit/1.1'));
adc_test_eq('curl is bot', true, ADC_Analytics_Traffic::is_bot('curl/8.5.0'));
adc_test_eq('python client is bot', true, ADC_Analytics_Traffic::is_bot('python-requests/2.31'));
adc_test_eq('headless browser is bot', true, ADC_Analytics_Traffic::is_bot('Mozilla/5.0 HeadlessChrome/120'));
adc_test_eq('desktop Chrome not bot', false, ADC_Analytics_Traffic::is_bot('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'));

adc_test_reset();
adc_test_eq('no prefetch headers', false, ADC_Analytics_Traffic::is_prefetch());
$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
adc_test_eq('Sec-Purpose prefetch', true, ADC_Analytics_Traffic::is_prefetch());
adc_test_reset();
$_SERVER['HTTP_PURPOSE'] = 'prefetch';
adc_test_eq('Purpose prefetch', true, ADC_Analytics_Traffic::is_prefetch());
adc_test_reset();
$_SERVER['HTTP_X_PURPOSE'] = 'preview';
$_SERVER['HTTP_X_MOZ'] = 'prefetch';
adc_test_eq('X-Moz prefetch', true, ADC_Analytics_Traffic::is_prefetch());
adc_test_reset();
$_SERVER['HTTP_SEC_PURPOSE'] = 'prerender';
adc_test_eq('Sec-Purpose prerender', true, ADC_Analytics_Traffic::is_prefetch());

// --- Page dimension: whitelist and fallbacks ---
adc_test_reset(array('is_front_page' => true, 'is_home' => true));
adc_test_eq('front page is home', array('page_type' => 'home', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('is_home' => true));
adc_test_eq('posts page is blog', array('page_type' => 'blog', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('is_shop' => true, 'singular' => 'page', 'queried_id' => 7));
adc_test_eq('shop page is shop', array('page_type' => 'shop', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('singular' => 'product', 'queried_id' => 12));
adc_test_eq('product', array('page_type' => 'product', 'object_id' => 12), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('singular' => 'product', 'queried_id' => 0));
adc_test_eq('deleted product falls back to other', array('page_type' => 'other', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('singular' => 'post', 'queried_id' => 5));
adc_test_eq('post', array('page_type' => 'post', 'object_id' => 5), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('singular' => 'page', 'queried_id' => 9));
adc_test_eq('page', array('page_type' => 'page', 'object_id' => 9), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('singular' => 'attachment', 'queried_id' => 4));
adc_test_eq('attachment is other', array('page_type' => 'other', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('tax' => 'product_cat', 'queried_object' => (object) array('term_id' => 33)));
adc_test_eq('product_cat', array('page_type' => 'product_cat', 'object_id' => 33), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('tax' => 'product_tag', 'queried_object' => (object) array('term_id' => 4)));
adc_test_eq('product_tag', array('page_type' => 'product_tag', 'object_id' => 4), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('tax' => 'product_cat', 'queried_object' => null));
adc_test_eq('deleted product_cat falls back to other', array('page_type' => 'other', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('is_category' => true, 'queried_object' => (object) array('term_id' => 8)));
adc_test_eq('category', array('page_type' => 'category', 'object_id' => 8), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('is_tag' => true, 'queried_object' => (object) array('term_id' => 3)));
adc_test_eq('tag', array('page_type' => 'tag', 'object_id' => 3), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('post_type_archive' => 'product'));
adc_test_eq('product archive is shop', array('page_type' => 'shop', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset(array('is_archive' => true));
adc_test_eq('generic archive', array('page_type' => 'archive', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

adc_test_reset();
adc_test_eq('unknown context is other', array('page_type' => 'other', 'object_id' => 0), ADC_Analytics_Traffic::page_dimension());

// UTM is never ingested, even when present in the request.
adc_test_reset();
$_GET = array('utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'black');
adc_test_eq('campaign dimension always empty', array('source' => '', 'medium' => '', 'campaign' => ''), ADC_Analytics_Traffic::campaign_dimension());
$_GET = array();

// --- maybe_count: the gate is closed, so nothing is counted or written ---
adc_test_reset(array());
ADC_Settings::$stored = array('enabled_traffic' => true, 'traffic_legal_basis' => 'lia', 'traffic_legal_reference' => 'LIA-2026-01', 'traffic_approved_at' => '2026-01-01');
ADC_Analytics_Traffic::maybe_count();
adc_test_eq('maybe_count writes nothing while gate closed', 0, ADC_Analytics_Store::$calls);

// --- Gate and settings sanitization ---
ADC_Settings::$stored = array();
adc_test_eq('traffic gate closed by default', false, ADC_Analytics_Settings::traffic_enabled());
adc_test_eq('utm gate closed by default', false, ADC_Analytics_Settings::utm_enabled());
adc_test_eq('add_to_cart gate closed by default', false, ADC_Analytics_Settings::add_to_cart_enabled());
adc_test_eq('begin_checkout gate closed by default', false, ADC_Analytics_Settings::begin_checkout_enabled());
adc_test_eq('commerce off by default', false, ADC_Analytics_Settings::commerce_enabled());

// Every collection gate stays closed even when every stored field is filled in.
ADC_Settings::$stored = array(
    'enabled_traffic' => true, 'traffic_legal_basis' => 'lia', 'traffic_legal_reference' => 'LIA-1',
    'traffic_approved_at' => '2026-01-01', 'traffic_utm_enabled' => true, 'traffic_utm_legal_reference' => 'UTM-1',
    'traffic_utm_approved_at' => '2026-01-01', 'enabled_add_to_cart' => true, 'add_to_cart_legal_reference' => 'ATC-1',
    'add_to_cart_approved_at' => '2026-01-01', 'enabled_begin_checkout' => true,
    'begin_checkout_legal_reference' => 'BC-1', 'begin_checkout_approved_at' => '2026-01-01',
);
adc_test_eq('traffic gate ignores full input', false, ADC_Analytics_Settings::traffic_enabled());
adc_test_eq('utm gate ignores full input', false, ADC_Analytics_Settings::utm_enabled());
adc_test_eq('add_to_cart gate ignores full input', false, ADC_Analytics_Settings::add_to_cart_enabled());
adc_test_eq('begin_checkout gate ignores full input', false, ADC_Analytics_Settings::begin_checkout_enabled());

// Sanitization forces every effective collection toggle off.
$sanitized = ADC_Analytics_Settings::sanitize(
    array(
        'enabled_traffic' => true, 'traffic_legal_basis' => 'lia', 'traffic_legal_reference' => 'LIA-1',
        'traffic_approved_at' => '2026-01-01', 'traffic_utm_enabled' => true, 'enabled_add_to_cart' => true,
        'enabled_begin_checkout' => true,
    ),
    ADC_Analytics_Settings::defaults()
);
adc_test_eq('sanitize forces traffic off', false, $sanitized['enabled_traffic']);
adc_test_eq('sanitize forces utm off', false, $sanitized['traffic_utm_enabled']);
adc_test_eq('sanitize forces add_to_cart off', false, $sanitized['enabled_add_to_cart']);
adc_test_eq('sanitize forces begin_checkout off', false, $sanitized['enabled_begin_checkout']);
adc_test_eq('draft legal reference kept for review', 'LIA-1', $sanitized['traffic_legal_reference']);

// Future and malformed approval dates are dropped; a valid past date is kept.
$dated = ADC_Analytics_Settings::sanitize(array('traffic_approved_at' => '2099-01-01'), ADC_Analytics_Settings::defaults());
adc_test_eq('future date dropped', '', $dated['traffic_approved_at']);
$dated = ADC_Analytics_Settings::sanitize(array('traffic_approved_at' => '2026-02-30'), ADC_Analytics_Settings::defaults());
adc_test_eq('invalid calendar date dropped', '', $dated['traffic_approved_at']);
$dated = ADC_Analytics_Settings::sanitize(array('traffic_approved_at' => '2026-03-01'), ADC_Analytics_Settings::defaults());
adc_test_eq('valid past date kept', '2026-03-01', $dated['traffic_approved_at']);

// Commerce needs both the switch and the acknowledgement.
ADC_Settings::$stored = array('enabled_commerce' => true, 'commerce_ack' => false);
adc_test_eq('commerce needs ack', false, ADC_Analytics_Settings::commerce_enabled());
ADC_Settings::$stored = array('enabled_commerce' => true, 'commerce_ack' => true);
adc_test_eq('commerce on with switch and ack', true, ADC_Analytics_Settings::commerce_enabled());

// Ledger retention never shorter than commerce retention.
$retention = ADC_Analytics_Settings::sanitize(array('retention_commerce_days' => 730, 'retention_ledger_days' => 365), ADC_Analytics_Settings::defaults());
adc_test_eq('ledger raised to commerce', 730, $retention['retention_ledger_days']);
$retention = ADC_Analytics_Settings::sanitize(array('retention_commerce_days' => 100, 'retention_ledger_days' => 400), ADC_Analytics_Settings::defaults());
adc_test_eq('ledger kept when longer', 400, $retention['retention_ledger_days']);

// Clamps.
$clamped = ADC_Analytics_Settings::sanitize(array('retention_traffic_days' => 999, 'min_cell_count' => 1, 'retention_commerce_days' => 5), ADC_Analytics_Settings::defaults());
adc_test_eq('traffic retention clamped to 180', 180, $clamped['retention_traffic_days']);
adc_test_eq('min cell clamped to 3', 3, $clamped['min_cell_count']);
adc_test_eq('commerce retention clamped to 90', 90, $clamped['retention_commerce_days']);

// Paid statuses are an allowlist.
$paid = ADC_Analytics_Settings::sanitize(array('paid_statuses' => array('processing', 'cancelled', 'refunded')), ADC_Analytics_Settings::defaults());
adc_test_eq('paid statuses allowlisted', array('processing'), $paid['paid_statuses']);

// Unknown or closed features report Romanian reasons and stay disabled.
adc_test_eq('traffic status disabled', false, ADC_Analytics_Settings::legal_gate_status('traffic')['enabled']);
adc_test_eq('unknown feature disabled', false, ADC_Analytics_Settings::legal_gate_status('nope')['enabled']);

printf("%d page-dimension/gate checks, %d failure(s)\n", $total, $failures);
exit($failures === 0 ? 0 : 1);
