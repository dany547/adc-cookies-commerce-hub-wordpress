<?php
/**
 * Standalone contract test for ADC_Analytics_Csv (no WordPress, no PHPUnit).
 *
 * Checks the report allowlist, capability + nonce handling, unknown-report
 * denial, the formula guard (including whitespace before = + - @ TAB CR),
 * suppression pass-through, absence of individual identifiers, audit logging
 * and the row cap.
 *
 * Run: php tests/analytics/csv-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

interface ADC_Module_Interface { public static function boot(): void; }

$GLOBALS['adc_csv_state'] = array(
    'can_manage' => true,
    'nonce_ok'   => true,
    'nonce'      => '',
    'nonce_seen' => null,
    'died'       => null,
    'logger'     => array(),
    'user_id'    => 7,
    'rows'       => 3,
);

if (!function_exists('get_woocommerce_currency')) {
    function get_woocommerce_currency(): string
    {
        return 'RON';
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = null): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = null): string
    {
        return $text;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($value));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) $GLOBALS['adc_csv_state']['can_manage'];
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) $GLOBALS['adc_csv_state']['user_id'];
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        return $url . '?' . http_build_query($args);
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action): string
    {
        return $url . '&_wpnonce=stub-nonce';
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): void
    {
        $GLOBALS['adc_csv_state']['nonce_seen'] = $action;
        if ((bool) $GLOBALS['adc_csv_state']['nonce_ok'] !== true) {
            wp_die('Security check failed', '', array('response' => 403));
        }
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message = '', string $title = '', array $args = array()): void
    {
        $GLOBALS['adc_csv_state']['died'] = array('message' => $message, 'response' => (int) ($args['response'] ?? 0));
        throw new RuntimeException('wp_die');
    }
}

/** Test double for the scrubbed logger. */
final class ADC_Logger
{
    public static function log(string $level, string $message, array $context = array()): void
    {
        $GLOBALS['adc_csv_state']['logger'][] = array('level' => $level, 'message' => $message, 'context' => $context);
    }
}

/** Test double for the report façade. */
final class ADC_Analytics_Reports
{
    public static function range(string $preset, string $from = '', string $to = ''): array
    {
        return array(
            'preset'        => $preset,
            'from'          => '2026-01-01',
            'to'            => '2026-01-31',
            'days'          => 31,
            'prev_from'     => '2025-12-01',
            'prev_to'       => '2025-12-31',
            'prev_complete' => true,
            'valid'         => true,
            'errors'        => array(),
            'clamped'       => false,
            'max_days'      => 366,
            'timezone'      => 'Europe/Bucharest',
        );
    }

    public static function kpis(array $range, string $currency, array $filters = array()): array
    {
        return array(
            'from'              => $range['from'],
            'to'                => $range['to'],
            'currency'          => strtoupper($currency),
            'orders_created'    => 12,
            'orders_paid'       => 9,
            'gross_revenue'     => 1234.5,
            'refunds'           => 34.5,
            'net_revenue'       => 1200.0,
            'tax'               => 200.0,
            'shipping'          => 15.0,
            'discounts'         => 5.0,
            'aov'               => 137.166667,
            'refund_rate'       => 0.027946,
            'traffic_views'     => null,
            'small_sample'      => false,
            'comparison'        => array('available' => false, 'reason' => 'none'),
        );
    }

    public static function traffic_series(array $range, array $filters = array()): array
    {
        $count = (int) $GLOBALS['adc_csv_state']['rows'];
        $rows  = array();
        for ($i = 0; $i < $count; $i++) {
            $rows[] = array('date' => sprintf('2026-01-%02d', $i + 1), 'views' => 10 + $i, 'suppressed' => false);
        }

        return array(
            'available'        => true,
            'reason'           => '',
            'rows'             => $rows,
            'truncated'        => false,
            'suppressed_count' => 0,
            'total_suppressed' => false,
        );
    }

    public static function top_pages(array $range, array $filters = array(), int $limit = 20): array
    {
        return array(
            'available'        => true,
            'reason'           => '',
            'rows'             => array(
                array('page_type' => 'page', 'object_id' => 42, 'label' => 'Pagină #42', 'views' => 90, 'suppressed' => false),
                array('page_type' => 'product', 'object_id' => 7, 'label' => 'Produs #7', 'views' => null, 'suppressed' => true),
            ),
            'truncated'        => false,
            'suppressed_count' => 1,
            'total_suppressed' => false,
        );
    }

    public static function channel_distribution(array $range, array $filters = array()): array
    {
        return array(
            'available'        => true,
            'reason'           => '',
            'rows'             => array(
                array('channel' => 'direct', 'label' => 'Direct', 'views' => 80, 'share' => 0.8, 'suppressed' => false),
                array('channel' => 'social', 'label' => 'Social', 'views' => 20, 'share' => 0.2, 'suppressed' => false),
            ),
            'truncated'        => false,
            'total_suppressed' => false,
        );
    }

    public static function commerce_series(array $range, string $currency): array
    {
        return array(
            'available'        => true,
            'reason'           => '',
            'currency'         => strtoupper($currency),
            'rows'             => array(
                array('date' => '2026-01-01', 'orders_created' => 2, 'orders_paid' => 1, 'gross_revenue' => 100.0, 'refunds' => 0.0, 'net_revenue' => 100.0, 'suppressed' => false),
                array('date' => '2026-01-02', 'orders_created' => null, 'orders_paid' => null, 'gross_revenue' => null, 'refunds' => null, 'net_revenue' => null, 'suppressed' => true),
            ),
            'totals'           => null,
            'truncated'        => false,
            'total_suppressed' => true,
        );
    }

    public static function top_products(array $range, string $currency, int $limit = 20, array $filters = array()): array
    {
        return array(
            'available'        => true,
            'reason'           => '',
            'currency'         => strtoupper($currency),
            'rows'             => array(
                array(
                    'product_id'   => 7,
                    'label'        => '=HYPERLINK("http://evil.test")',
                    'units'        => 12,
                    'net'          => 500.0,
                    'tax'          => 50.0,
                    'refund_units' => 1,
                    'refund_net'   => 20.0,
                    'suppressed'   => false,
                ),
            ),
            'truncated'        => false,
            'total_suppressed' => false,
        );
    }

    public static function current_order_counts(): array
    {
        return array('available' => true, 'reason' => '', 'counts' => array('processing' => 4, 'completed' => 6), 'total' => 10);
    }

    public static function reconciliation(array $range, string $currency): array
    {
        return array(
            'available' => true,
            'reason'    => '',
            'currency'  => strtoupper($currency),
            'days'      => array(
                array('date' => '2026-01-01', 'diff' => array('order_total' => 0.02), 'warn' => true),
            ),
        );
    }
}

final class StubDie extends RuntimeException
{
}

require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-csv.php';

$failures = 0;
$checks   = 0;

function check(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function reset_state(): void
{
    $GLOBALS['adc_csv_state']['can_manage'] = true;
    $GLOBALS['adc_csv_state']['nonce_ok']   = true;
    $GLOBALS['adc_csv_state']['died']       = null;
    $GLOBALS['adc_csv_state']['logger']     = array();
    $GLOBALS['adc_csv_state']['nonce_seen'] = null;
    $GLOBALS['adc_csv_state']['rows']       = 3;
    $_GET = array();
}

/**
 * Run handle_export() and capture any CSV output.
 *
 * @return array{output:string,died:?array,threw:?Throwable}
 */
function run_export(): array
{
    $output = '';
    $threw  = null;
    ob_start();
    try {
        ADC_Analytics_Csv::handle_export();
    } catch (Throwable $error) {
        $threw = $error;
    }
    $output = (string) ob_get_clean();

    return array('output' => $output, 'died' => $GLOBALS['adc_csv_state']['died'], 'threw' => $threw);
}

// --- 1. Allowlist ------------------------------------------------------------

$reports = ADC_Analytics_Csv::allowed_reports();
check($reports !== array(), 'report allowlist is not empty');
foreach (array('kpis', 'traffic_series', 'top_pages', 'channel_distribution', 'commerce_series', 'top_products', 'current_counts', 'reconciliation') as $report) {
    check(in_array($report, $reports, true), 'allowlisted report: ' . $report);
}
foreach (array('users', 'orders', 'customers', 'raw', '../etc/passwd', 'kpis; DROP TABLE') as $bad) {
    check(!in_array($bad, $reports, true), 'not allowlisted: ' . $bad);
}
check(ADC_Analytics_Csv::NONCE === 'adc_analytics_export_nonce', 'nonce action matches the contract');

// --- 2. Formula guard -------------------------------------------------------

$guarded = array(
    '=1+1',
    ' =1+1',
    '   =1+1',
    "\t=1+1",
    "\r-1",
    '+40712345678',
    '-5',
    '@SUM(A1:A2)',
    " \n@cmd",
);
foreach ($guarded as $value) {
    $cell = ADC_Analytics_Csv::cell($value);
    check(str_starts_with($cell, "'"), 'formula guard applies to: ' . json_encode($value));
    check(!preg_match('/^[\s]*[=+\-@]/', $cell), 'guarded cell does not start with a formula character');
}

check(ADC_Analytics_Csv::cell('Pagină #42') === 'Pagină #42', 'plain text left untouched');
check(ADC_Analytics_Csv::cell('1,5') === '1,5', 'number-like text left untouched');
check(ADC_Analytics_Csv::cell("linia 1\nlinia 2") === 'linia 1 linia 2', 'line breaks flattened into one cell');
check(!str_contains(ADC_Analytics_Csv::cell("a\rb\0c"), "\r"), 'CR and NUL removed');
check(ADC_Analytics_Csv::cell('x') !== '' && strlen(ADC_Analytics_Csv::cell(str_repeat('a', 800))) === 500, 'cell length capped');
check(str_starts_with(ADC_Analytics_Csv::cell("'=1"), "'=1"), "already-quoted value stays literal");

// --- 3. Build: filenames and headers ---------------------------------------

foreach ($reports as $report) {
    $built = ADC_Analytics_Csv::build($report, array('currency' => 'USD', 'preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'));
    check($built['report'] === $report, 'build reports its own report key: ' . $report);
    check($built['filename'] === 'analytics-' . $report . '-2026-01-01-2026-01-31.csv', 'filename format: ' . $report);
    check(!preg_match('#[/\\\\:;*?"<>|]#', $built['filename']), 'filename has no path/control characters: ' . $report);
    check($built['header'] !== array(), 'header present: ' . $report);
    foreach ($built['rows'] as $row) {
        check(count($row) === count($built['header']), 'row width matches header: ' . $report);
        foreach ($row as $cell) {
            check(!preg_match('/^[\s]*[=+\-@]/', (string) $cell), 'no built cell starts with a formula character: ' . $report);
        }
    }
}

$unknown = ADC_Analytics_Csv::build('orders', array('from' => '2026-01-01', 'to' => '2026-01-31'));
check($unknown['header'] === array() && $unknown['rows'] === array(), 'unknown report builds nothing');

// --- 4. No individual identifiers anywhere ---------------------------------

$pii = array('email', 'e-mail', 'telefon', 'phone', 'prenume', 'nume', 'order_id', 'customer', 'ip_address', 'user_agent', 'url', 'permalink');
foreach ($reports as $report) {
    $built  = ADC_Analytics_Csv::build($report, array('currency' => 'USD', 'from' => '2026-01-01', 'to' => '2026-01-31'));
    $header = strtolower(implode(' ', $built['header']));
    foreach ($pii as $needle) {
        check(!str_contains($header, $needle), 'no ' . $needle . ' column in ' . $report);
    }
    $body = strtolower(json_encode($built['rows'], JSON_UNESCAPED_UNICODE));
    check(!preg_match('/[\w.+-]+@[\w-]+\.[a-z]{2,}/', $body), 'no e-mail address in ' . $report);
    check(!str_contains($body, 'http://') && !str_contains($body, 'https://'), 'no URL in ' . $report);
}

// --- 5. Suppression pass-through --------------------------------------------

$commerce = ADC_Analytics_Csv::build('commerce_series', array('currency' => 'USD', 'from' => '2026-01-01', 'to' => '2026-01-31'));
$commerce_text = json_encode($commerce['rows'], JSON_UNESCAPED_UNICODE);
check(str_contains((string) $commerce_text, 'eșantion mic'), 'suppressed row is labelled in the CSV');
check(str_contains((string) $commerce_text, 'ascuns'), 'suppressed total is labelled, not printed as a number');

$products = ADC_Analytics_Csv::build('top_products', array('currency' => 'USD', 'from' => '2026-01-01', 'to' => '2026-01-31'));
check(str_contains(json_encode($products['rows']), "'=HYPERLINK"), 'hostile product label is neutralised');

// --- 6. stream() output -----------------------------------------------------

reset_state();
ob_start();
ADC_Analytics_Csv::stream('top_pages', array('from' => '2026-01-01', 'to' => '2026-01-31'));
$csv = (string) ob_get_clean();
check(str_starts_with($csv, "\xEF\xBB\xBF"), 'UTF-8 BOM written');
check(str_contains($csv, 'Pagină #42'), 'data written to the stream');
check(str_contains($csv, ';'), 'semicolon separator used');
check(substr_count($csv, "\n") >= 3, 'note row plus two data rows written');
check(!preg_match('/^[^;]*[=+\-@]/m', $csv), 'no streamed cell starts with a formula character');

reset_state();
$GLOBALS['adc_csv_state']['rows'] = 900;
ob_start();
ADC_Analytics_Csv::stream('traffic_series', array('from' => '2026-01-01', 'to' => '2026-01-31'));
$csv = (string) ob_get_clean();
$lines = substr_count(trim($csv), "\n") + 1;
check($lines <= 503, 'streamed rows are capped (header + note + 500)');

// --- 7. handle_export(): capability, nonce, allowlist, audit ----------------

reset_state();
$GLOBALS['adc_csv_state']['can_manage'] = false;
$result = run_export();
check($result['died'] !== null && $result['died']['response'] === 403, 'no capability -> 403');
check($result['output'] === '', 'no capability -> no CSV output');
check(($GLOBALS['adc_csv_state']['nonce_seen'] ?? null) === null, 'capability checked before the nonce');
$last = end($GLOBALS['adc_csv_state']['logger']);
check(is_array($last) && $last['context']['event'] === 'export_denied_capability', 'denied export audited');

reset_state();
$GLOBALS['adc_csv_state']['nonce_ok'] = false;
$_GET = array('report' => 'kpis');
$result = run_export();
check($result['died'] !== null && $result['died']['response'] === 403, 'bad nonce -> 403');
check($GLOBALS['adc_csv_state']['nonce_seen'] === ADC_Analytics_Csv::NONCE, 'expected nonce action verified');
check($result['output'] === '', 'bad nonce -> no CSV output');

reset_state();
$_GET = array('report' => 'orders', 'from' => '2026-01-01', 'to' => '2026-01-31');
$result = run_export();
check($result['died'] !== null && $result['died']['response'] === 400, 'unknown report -> 400');
check($result['output'] === '', 'unknown report -> no CSV output');
$last = end($GLOBALS['adc_csv_state']['logger']);
check(is_array($last) && $last['context']['event'] === 'export_denied_unknown_report', 'unknown report audited');
check(isset($last['context']['user_id']) && $last['context']['user_id'] === 7, 'audit carries the actor id');

reset_state();
$_GET = array('report' => 'kpis', 'from' => '2026-01-01', 'to' => '2026-01-31', 'currency' => 'USD');
$result = run_export();
check($result['died'] === null, 'allowed export does not die');
check(str_starts_with($result['output'], "\xEF\xBB\xBF"), 'allowed export streams CSV');
$last = end($GLOBALS['adc_csv_state']['logger']);
check(is_array($last) && $last['context']['event'] === 'export', 'allowed export audited');
check($last['context']['report'] === 'kpis' && $last['context']['from'] === '2026-01-01', 'audit carries report and range');
check($last['context']['currency'] === 'USD', 'audit carries the currency');
check(!isset($last['context']['email']) && !isset($last['context']['order_id']), 'audit has no PII fields');
check(!str_contains(strtolower(json_encode($last['context'])), '@'), 'audit context has no e-mail address');

reset_state();
$_GET = array('report' => 'kpis', 'currency' => 'U=SD');
$result = run_export();
$last = end($GLOBALS['adc_csv_state']['logger']);
check(is_array($last) && $last['context']['event'] === 'export_denied_invalid_currency', 'malformed currency audited as rejected');
check($result['died'] !== null && $result['died']['response'] === 400, 'malformed currency rejected (400, no zero output)');

reset_state();
$_GET = array('report' => 'kpis', 'page_type' => 'product', 'channel' => 'search', 'product_id' => '7');
$result = run_export();
check(str_starts_with($result['output'], "\xEF\xBB\xBF"), 'filtered export still streams');

// --- 7b. Currency parity (default / explicit / invalid) ---------------------

reset_state();
$default_built = ADC_Analytics_Csv::build('kpis', array('preset' => '30', 'from' => '2026-01-01', 'to' => '2026-01-31'));
$default_csv = json_encode($default_built);
check(str_contains($default_csv, 'RON'), 'empty currency defaults to the store currency (RON), never a zero context');

reset_state();
$_GET = array('report' => 'kpis', 'preset' => '30', 'from' => '2026-01-01', 'to' => '2026-01-31');
$result = run_export();
check($result['died'] === null, 'default-currency export does not die');
check(str_contains($result['output'], 'RON'), 'default-currency export streams the store currency');

reset_state();
$eur_built = ADC_Analytics_Csv::build('kpis', array('currency' => 'eur', 'preset' => '30', 'from' => '2026-01-01', 'to' => '2026-01-31'));
check(str_contains(json_encode($eur_built), 'EUR'), 'explicit currency is normalized and passed through');

reset_state();
$invalid_built = ADC_Analytics_Csv::build('kpis', array('currency' => 'ZZ1', 'preset' => '30', 'from' => '2026-01-01', 'to' => '2026-01-31'));
$invalid_csv = json_encode($invalid_built);
check(str_contains($invalid_csv, 'Moned'), 'explicit invalid currency yields a reason row');
check(!preg_match('/[0-9]0\.00/', (string) $invalid_csv), 'invalid currency fabricates no zero KPI rows');

reset_state();
$_GET = array('report' => 'kpis', 'currency' => 'ZZ1', 'preset' => '30', 'from' => '2026-01-01', 'to' => '2026-01-31');
$result = run_export();
check($result['died'] !== null && $result['died']['response'] === 400, 'explicit invalid currency is rejected (400)');

// --- 8. Export URL ---------------------------------------------------------

$url = ADC_Analytics_Csv::export_url('top_pages', array('from' => '2026-01-01', 'to' => '2026-01-31', 'currency' => 'USD'));
check(str_contains($url, 'action=adc_analytics_export'), 'export URL carries the action');
check(str_contains($url, 'report=top_pages'), 'export URL carries the report');
check(str_contains($url, '_wpnonce='), 'export URL carries a nonce');
check(!str_contains($url, 'page_type='), 'absent filters are not invented');

$url_filters = ADC_Analytics_Csv::export_url('top_pages', array('page_type' => 'product', 'channel' => 'search', 'product_id' => 7));
check(str_contains($url_filters, 'page_type=product') && str_contains($url_filters, 'channel=search'), 'filters carried into the export URL');
check(str_contains($url_filters, 'product_id=7'), 'product filter carried into the export URL');

if ($failures > 0) {
    fwrite(STDERR, "csv-test: {$failures} failure(s) of {$checks}\n");
    exit(1);
}

echo "csv-test: PASS ({$checks} checks)\n";
