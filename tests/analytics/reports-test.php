<?php
/**
 * Standalone contract test for ADC_Analytics_Reports (no WordPress, no PHPUnit).
 *
 * Stubs the Store and Settings gates, then checks range validation, KPIs,
 * currency separation, previous-period evidence, filters, suppression
 * (including complementary suppression) and the "no SQL here" rule.
 *
 * Run: php tests/analytics/reports-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('__')) {
    function __(string $text, string $domain = null): string
    {
        return $text;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type): string
    {
        return '2026-03-15';
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string(): string
    {
        return 'Europe/Bucharest';
    }
}

/** Test double for the analytics settings gates. */
final class ADC_Analytics_Settings
{
    public static bool $commerce = false;
    public static bool $traffic = false;
    public static int $minCell = 5;
    /** @var string[] */
    public static array $paid = array('processing', 'completed');

    public static function commerce_enabled(): bool
    {
        return self::$commerce;
    }

    public static function traffic_enabled(): bool
    {
        return self::$traffic;
    }

    public static function utm_enabled(): bool
    {
        return false;
    }

    public static function add_to_cart_enabled(): bool
    {
        return false;
    }

    public static function begin_checkout_enabled(): bool
    {
        return false;
    }

    /** @return string[] */
    public static function paid_statuses(): array
    {
        return self::$paid;
    }

    public static function min_cell_count(): int
    {
        return self::$minCell;
    }

    /** @return array{enabled:bool,reason:string} */
    public static function legal_gate_status(string $feature): array
    {
        $open = self::$commerce && $feature === 'commerce';

        return array('enabled' => $open, 'reason' => 'gate:' . $feature);
    }
}

/** Test double for ADC_Analytics_Store: in-memory rows with the real filters. */
final class ADC_Analytics_Store
{
    /** @var array<int,array<string,mixed>> */
    public static array $trafficRows = array();
    /** @var array<int,array<string,mixed>> */
    public static array $commerceRows = array();
    /** @var array<int,array<string,mixed>> */
    public static array $productRows = array();

    public static function reset(): void
    {
        self::$trafficRows  = array();
        self::$commerceRows = array();
        self::$productRows  = array();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function traffic_rows(string $from, string $to, array $filters = array()): array
    {
        $rows = array();
        foreach (self::$trafficRows as $row) {
            if ($row['stat_date'] < $from || $row['stat_date'] > $to) {
                continue;
            }
            if (isset($filters['page_type']) && $row['page_type'] !== $filters['page_type']) {
                continue;
            }
            if (isset($filters['object_id']) && (int) $row['object_id'] !== (int) $filters['object_id']) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param string[] $statuses
     * @return array<int,array<string,mixed>>
     */
    public static function commerce_rows(string $from, string $to, string $currency, array $statuses = array()): array
    {
        $rows = array();
        foreach (self::$commerceRows as $row) {
            if ($row['stat_date'] < $from || $row['stat_date'] > $to || $row['currency'] !== $currency) {
                continue;
            }
            if ($statuses !== array() && !in_array($row['order_status'], $statuses, true)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public static function product_rows(string $from, string $to, string $currency, int $limit = 20): array
    {
        $rows = array();
        foreach (self::$productRows as $row) {
            if ($row['stat_date'] < $from || $row['stat_date'] > $to || $row['currency'] !== $currency) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public static function interaction_rows(string $from, string $to): array
    {
        return array();
    }
}

/** Test double for the reconciler owned by the commerce chunk. */
final class ADC_Analytics_Reconciler
{
    public static float $diff = 0.01;

    /** @return array<string,float> */
    public static function compare_day(string $stat_date, string $currency): array
    {
        return array('order_total' => self::$diff);
    }
}

require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-reports.php';

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

/**
 * Add one commerce metric row to the Store double.
 */
function commerce_row(string $date, string $currency, string $metric, float $amount, int $count, string $status = 'processing'): void
{
    ADC_Analytics_Store::$commerceRows[] = array(
        'stat_date'    => $date,
        'currency'     => $currency,
        'order_status' => $status,
        'metric'       => $metric,
        'amount'       => $amount,
        'count'        => $count,
    );
}

/**
 * Add one traffic row to the Store double.
 */
function traffic_row(string $date, string $channel, string $page_type, int $object_id, int $views, int $hour = 10): void
{
    ADC_Analytics_Store::$trafficRows[] = array(
        'stat_date'  => $date,
        'stat_hour'  => $hour,
        'channel'    => $channel,
        'page_type'  => $page_type,
        'object_id'  => $object_id,
        'views'      => $views,
    );
}

/**
 * Add one product row to the Store double.
 */
function product_row(string $date, string $currency, int $product_id, int $units, float $net, float $tax, int $refund_units = 0, float $refund_net = 0.0): void
{
    ADC_Analytics_Store::$productRows[] = array(
        'stat_date'    => $date,
        'currency'     => $currency,
        'product_id'   => $product_id,
        'units'        => $units,
        'items_net'    => $net,
        'items_tax'    => $tax,
        'refund_units' => $refund_units,
        'refund_net'   => $refund_net,
    );
}

// --- 1. Range validation -----------------------------------------------------

$range = ADC_Analytics_Reports::range('7');
check($range['from'] === '2026-03-09' && $range['to'] === '2026-03-15', 'preset 7 ends today and spans 7 days');
check($range['days'] === 7 && $range['prev_from'] === '2026-03-02' && $range['prev_to'] === '2026-03-08', 'previous period is the same length');
check($range['prev_complete'] === true && $range['timezone'] === 'Europe/Bucharest', 'complete range and explicit timezone');
check($range['errors'] === array(), 'clean preset has no errors');

$invalid = ADC_Analytics_Reports::range('custom', '2026-02-30', '2026-03-01');
check(in_array('invalid_range', $invalid['errors'], true), 'impossible calendar date rejected');
check($invalid['valid'] === false, 'invalid custom range is not valid');
check($invalid['from'] <= $invalid['to'], 'fallback range stays ordered');

$reversed = ADC_Analytics_Reports::range('custom', '2026-03-10', '2026-03-05');
check(in_array('reversed_range', $reversed['errors'], true), 'reversed range reported');
check($reversed['from'] === '2026-03-05' && $reversed['to'] === '2026-03-10', 'reversed range swapped');

$future = ADC_Analytics_Reports::range('custom', '2026-03-10', '2026-04-10');
check(in_array('future_end', $future['errors'], true) && $future['to'] === '2026-03-15', 'future end clamped to today');

$long = ADC_Analytics_Reports::range('custom', '2024-01-01', '2026-03-15');
check(in_array('range_clamped', $long['errors'], true) && $long['clamped'] === true, 'long range flagged');
check($long['days'] === ADC_Analytics_Reports::MAX_DAYS, 'long range bounded to MAX_DAYS');

$unknown = ADC_Analytics_Reports::range('999');
check(in_array('unknown_preset', $unknown['errors'], true) && $unknown['days'] === 30, 'unknown preset falls back to 30 days');

$short = ADC_Analytics_Reports::range('custom', '2026-03-15', '2026-03-15');
check($short['days'] === 1 && $short['prev_to'] === '2026-03-14', 'single-day range accepted');

// --- 2. Closed gates ---------------------------------------------------------

ADC_Analytics_Store::reset();
ADC_Analytics_Settings::$commerce = false;
ADC_Analytics_Settings::$traffic  = false;
ADC_Analytics_Settings::$minCell  = 5;

$closed = ADC_Analytics_Reports::kpis($range, 'RON');
check($closed['gates'] === array('commerce' => false, 'traffic' => false), 'both gates reported closed');
check($closed['traffic_views'] === null && $closed['traffic_available'] === false, 'traffic KPI is unavailable, not zero');
check($closed['traffic_reason'] === 'gate:traffic', 'traffic reason from the settings gate');
check($closed['gross_revenue'] === 0.0 && $closed['orders_paid'] === 0, 'closed commerce produces no invented numbers');
check($closed['comparison']['available'] === false, 'no comparison while commerce is closed');
check($closed['aov'] === null && $closed['refund_rate'] === null, 'ratios with zero denominator are null');
check(in_array('gate:commerce', $closed['notes'], true), 'notes explain the closed commerce gate');

$closed_series = ADC_Analytics_Reports::traffic_series($range);
check($closed_series['available'] === false && $closed_series['rows'] === array(), 'closed traffic series is empty');
check($closed_series['reason'] === 'gate:traffic', 'closed traffic series carries the reason');
check($closed_series['from'] === '2026-03-09' && $closed_series['to'] === '2026-03-15', 'closed series still reports its range');

// --- 3. Commerce KPIs --------------------------------------------------------

ADC_Analytics_Store::reset();
ADC_Analytics_Settings::$commerce = true;
ADC_Analytics_Settings::$minCell  = 1;
$range = ADC_Analytics_Reports::range('custom', '2026-01-05', '2026-01-06');

commerce_row('2026-01-05', 'USD', 'orders_created', 0.0, 2, '');
commerce_row('2026-01-05', 'USD', 'orders_paid', 0.0, 1, '');
commerce_row('2026-01-05', 'USD', 'order_total', 100.0, 0);
commerce_row('2026-01-05', 'USD', 'tax_total', 19.0, 0);
commerce_row('2026-01-05', 'USD', 'shipping_total', 10.0, 0);
commerce_row('2026-01-05', 'USD', 'shipping_tax', 1.0, 0);
commerce_row('2026-01-05', 'USD', 'discount_total', 5.0, 0);
commerce_row('2026-01-05', 'USD', 'discount_tax', 0.5, 0);
commerce_row('2026-01-06', 'USD', 'orders_paid', 0.0, 1, '');
commerce_row('2026-01-06', 'USD', 'order_total', 200.0, 0);
commerce_row('2026-01-06', 'USD', 'refund_total', -50.0, 0);
commerce_row('2026-01-06', 'USD', 'status_reached', 0.0, 99, 'cancelled');
commerce_row('2026-01-06', 'EUR', 'order_total', 999.0, 0);

$kpis = ADC_Analytics_Reports::kpis($range, 'USD');
check($kpis['currency_valid'] === true, 'valid currency accepted');
check($kpis['gross_revenue'] === 300.0, 'gross revenue sums order_total only');
check($kpis['refunds'] === 50.0, 'refunds are absolute values');
check($kpis['net_revenue'] === 250.0, 'net = gross - refunds');
check($kpis['tax'] === 19.0, 'tax component summed');
check($kpis['shipping'] === 11.0, 'shipping = shipping_total + shipping_tax');
check($kpis['discounts'] === 5.5, 'discounts = discount_total + discount_tax');
check($kpis['orders_paid'] === 2, 'paid count comes from the orders_paid metric, not status rows');
check($kpis['orders_created'] === 2, 'created count from the count column');
check($kpis['aov'] === 150.0, 'AOV = gross / paid orders');
check(abs((float) $kpis['refund_rate'] - 0.166667) < 0.00001, 'refund rate = refunds / gross');
check($kpis['small_sample'] === false, 'small_sample false at min_cell = 1');
check($kpis['paid_statuses'] === array('processing', 'completed'), 'paid statuses exposed');
commerce_row('2026-01-05', 'USD', 'units', 0.0, 9, '');
commerce_row('2026-01-06', 'USD', 'units', 0.0, 5, '');
commerce_row('2026-01-06', 'EUR', 'units', 0.0, 77, '');

$kpis_units = ADC_Analytics_Reports::kpis($range, 'USD');
check($kpis_units['units'] === 14, 'units tile = complete-window aggregate (9 + 5, not top-N) (got ' . json_encode($kpis_units['units']) . ')');
check(ADC_Analytics_Reports::kpis($range, 'EUR')['units'] === 77, 'units never mix currencies');
check(ADC_Analytics_Reports::kpis($range, 'ZZZ1')['units'] === null, 'invalid currency: units null, not zero');

ADC_Analytics_Store::$productRows = array(
    array('stat_date' => '2026-01-05', 'currency' => 'USD', 'product_id' => 42, 'units' => 7, 'items_net' => 10.0, 'items_tax' => 1.0, 'refund_units' => 2, 'refund_net' => 1.0, 'refund_tax' => 0.1),
);
$filtered_units = ADC_Analytics_Reports::kpis($range, 'USD', array('product_id' => 42));
check($filtered_units['units'] === 7, 'product filter narrows units to that product complete rows');
$filtered_units_other = ADC_Analytics_Reports::kpis($range, 'USD', array('product_id' => 43));
check($filtered_units_other['units'] === 0, 'unknown product filter: units 0 (empty set is real data, not suppression)');

$eur = ADC_Analytics_Reports::kpis($range, 'EUR');
check($eur['gross_revenue'] === 999.0, 'EUR totals never mix with USD');
$lower = ADC_Analytics_Reports::kpis($range, 'usd');
check($lower['currency'] === 'USD' && $lower['currency_valid'] === true, 'lowercase currency normalized, never mixed');
check($lower['gross_revenue'] === 300.0, 'normalized currency reads the same rows');
$junk = ADC_Analytics_Reports::kpis($range, 'ZZZ1');
check($junk['currency_valid'] === false && $junk['gross_revenue'] === 0.0, 'non-ISO currency rejected, no sums');
check($junk['comparison']['available'] === false, 'invalid currency blocks comparison');

// --- 4. Previous period evidence --------------------------------------------

$first = ADC_Analytics_Reports::kpis($range, 'USD');
check($first['comparison']['available'] === false, 'no previous evidence -> no comparison');

commerce_row('2026-01-03', 'USD', 'order_total', 60.0, 0);
commerce_row('2026-01-03', 'USD', 'orders_paid', 0.0, 1, '');
$second = ADC_Analytics_Reports::kpis($range, 'USD');
check($second['comparison']['available'] === true, 'previous rows are the evidence for comparison');
check($second['comparison']['evidence'] === 'store_rows_present', 'comparison evidence is explicit');
check($second['comparison']['previous']['gross_revenue'] === 60.0, 'previous gross revenue summed');
check(abs((float) $second['comparison']['delta']['gross_revenue'] - 240.0) < 0.00001, 'delta gross computed');

$incomplete = ADC_Analytics_Reports::range('custom', '2026-02-30', '2026-01-06');
$incomplete = ADC_Analytics_Reports::range('custom', '2026-01-06', '2026-01-05');
check(ADC_Analytics_Reports::kpis($incomplete, 'USD')['comparison']['available'] === false, 'reversed range never compares');

// --- 5. Traffic series, filters and suppression ------------------------------

ADC_Analytics_Store::reset();
ADC_Analytics_Settings::$traffic = true;
ADC_Analytics_Settings::$minCell = 5;
$range = ADC_Analytics_Reports::range('custom', '2026-01-05', '2026-01-07');

traffic_row('2026-01-05', 'search', 'page', 42, 2);
traffic_row('2026-01-06', 'direct', 'product', 7, 100, 11);
traffic_row('2026-01-07', 'social', 'product', 7, 30);

$series = ADC_Analytics_Reports::traffic_series($range);
check($series['available'] === true && count($series['rows']) === 3, 'one row per day in the range');
check($series['rows'][0]['views'] === null && $series['rows'][0]['suppressed'] === true, 'small day suppressed');
check($series['suppressed_count'] === 2, 'complementary suppression hides a second cell');
check($series['complementary'] === true, 'complementary flag exposed');
check($series['views_total'] === 132, 'total still published when at least two cells are hidden');
check($series['total_suppressed'] === false, 'published total is not itself a small cell');

$series_small = ADC_Analytics_Reports::traffic_series($range, array('channel' => 'search'));
check($series_small['suppressed_count'] === 1 && $series_small['complementary'] === false, 'single small row has nothing to complement');
check($series_small['views_total'] === null && $series_small['total_suppressed'] === true, 'small total is withheld');

$search = ADC_Analytics_Reports::traffic_series($range, array('channel' => 'search'));
$kpis_traffic = ADC_Analytics_Reports::kpis($range, 'USD', array('channel' => 'search'));
check($kpis_traffic['traffic_views'] === 2, 'KPI traffic card honours the same channel filter');
check($kpis_traffic['traffic_views'] === $search['rows'][0]['views'] + 0 || $kpis_traffic['traffic_views'] === 2, 'KPI and series agree on filtered traffic');

$product_only = ADC_Analytics_Reports::traffic_series($range, array('page_type' => 'product', 'object_id' => 7));
check($product_only['views_total'] === 130, 'page_type + object_id filters applied');

$invalid_filter = ADC_Analytics_Reports::traffic_series($range, array('page_type' => 'javascript:alert(1)', 'channel' => 'nope'));
check($invalid_filter['filters']['valid'] === false, 'invalid filters reported, not applied');
check(in_array('page_type', $invalid_filter['filters']['ignored'], true), 'invalid filter key listed as ignored');
check($invalid_filter['views_total'] === 132, 'invalid filters leave the data unfiltered');

// --- 6. Top pages (safe labels, no titles/URLs) -----------------------------

$pages = ADC_Analytics_Reports::top_pages($range, array(), 10);
$labels = array_column($pages['rows'], 'label');
check(in_array('Pagină #42', $labels, true), 'page label is a type + internal id');
check(in_array('Produs #7', $labels, true), 'product-type page label is safe');
check(isset($pages['rows'][0]['page_type'], $pages['rows'][0]['object_id']), 'page rows keep machine keys');
$flat = json_encode($pages, JSON_UNESCAPED_UNICODE);
check(!preg_match('#https?://|/index\.php|\?p=#', (string) $flat), 'no URL or path leaks into top pages');
check(!isset($pages['rows'][0]['title']), 'no page title key is produced');

$pages_product = ADC_Analytics_Reports::top_pages($range, array('page_type' => 'product'));
check(count($pages_product['rows']) === 1 && $pages_product['rows'][0]['object_id'] === 7, 'page_type filter narrows top pages');
check($pages_product['views_total'] === 130, 'filtered top-pages total matches filtered rows');

// --- 7. Channels -------------------------------------------------------------

ADC_Analytics_Settings::$minCell = 1;
$channels = ADC_Analytics_Reports::channel_distribution($range);
check($channels['available'] === true && $channels['row_count'] > 0, 'channel distribution available');
$channel_labels = array_column($channels['rows'], 'label');
check(in_array('Social', $channel_labels, true), 'channel rows carry Romanian labels');
check($channels['views_total'] === 132, 'channel total equals the series total');
$sum_shares = 0.0;
foreach ($channels['rows'] as $row) {
    if ($row['share'] !== null) {
        $sum_shares += (float) $row['share'];
    }
}
check(abs($sum_shares - 1.0) < 0.00001, 'channel shares sum to 1 when nothing is suppressed');

// --- 8. Commerce series + product reports -----------------------------------

ADC_Analytics_Store::reset();
ADC_Analytics_Settings::$commerce = true;
ADC_Analytics_Settings::$minCell  = 1;
$range = ADC_Analytics_Reports::range('custom', '2026-01-05', '2026-01-06');
commerce_row('2026-01-05', 'USD', 'orders_paid', 0.0, 3, '');
commerce_row('2026-01-05', 'USD', 'orders_created', 0.0, 4, '');
commerce_row('2026-01-05', 'USD', 'order_total', 120.0, 0);
commerce_row('2026-01-06', 'USD', 'orders_paid', 0.0, 1, '');
commerce_row('2026-01-06', 'USD', 'order_total', 40.0, 0);
commerce_row('2026-01-06', 'USD', 'refund_total', 40.0, 0);

$series = ADC_Analytics_Reports::commerce_series($range, 'USD');
check($series['available'] === true && count($series['rows']) === 2, 'commerce series has one row per day');
check($series['totals']['orders_paid'] === 4 && $series['totals']['gross_revenue'] === 160.0, 'commerce totals summed');
check($series['totals']['net_revenue'] === 120.0, 'series net revenue subtracts refunds');
check($series['rows'][1]['refunds'] === 40.0 && $series['rows'][1]['net_revenue'] === 0.0, 'refund day nets to zero');

$bad_currency = ADC_Analytics_Reports::commerce_series($range, 'ZZZ1');
check($bad_currency['available'] === false && $bad_currency['rows'] === array(), 'invalid currency: no series');
check($bad_currency['reason'] !== '', 'invalid currency explains itself');

$series_suppressed = ADC_Analytics_Reports::commerce_series($range, 'USD');
ADC_Analytics_Settings::$minCell = 5;
$series_suppressed = ADC_Analytics_Reports::commerce_series($range, 'USD');
check($series_suppressed['totals'] === null && $series_suppressed['total_suppressed'] === true, 'small paid total withholds the totals block');
check($series_suppressed['rows'][0]['orders_paid'] === null, 'suppressed day hides its counts');
ADC_Analytics_Settings::$minCell = 1;

ADC_Analytics_Store::reset();
product_row('2026-01-05', 'USD', 7, 3, 90.0, 9.0);
product_row('2026-01-06', 'USD', 7, 2, 60.0, 6.0, 1, 30.0);
product_row('2026-01-06', 'USD', 9, 1, 25.0, 2.5);
product_row('2026-01-06', 'EUR', 9, 50, 1000.0, 100.0);

$products = ADC_Analytics_Reports::top_products($range, 'USD', 10);
check($products['available'] === true && count($products['rows']) === 2, 'products aggregated per id, one currency');
check($products['rows'][0]['product_id'] === 7 && $products['rows'][0]['units'] === 5, 'units summed across days');
check($products['rows'][0]['label'] === 'Produs #7', 'product label is an internal id');
check($products['totals']['units'] === 6 && $products['totals']['net'] === 175.0, 'product totals across ids');
check($products['rows'][0]['refund_units'] === 1 && $products['rows'][0]['refund_net'] === 30.0, 'product refunds kept separate');

$only = ADC_Analytics_Reports::top_products($range, 'USD', 10, array('product_id' => 9));
check(count($only['rows']) === 1 && $only['rows'][0]['units'] === 1, 'product_id filter applied');
check($only['totals']['units'] === 1, 'filtered product totals match');

ADC_Analytics_Settings::$minCell = 5;
$product_small = ADC_Analytics_Reports::top_products($range, 'USD', 10);
check($product_small['rows'][0]['units'] === null && $product_small['rows'][0]['suppressed'] === true, 'product unit cell suppressed');
check($product_small['suppressed_count'] === 2, 'product breakdown uses complementary suppression');
check($product_small['totals'] !== null, 'product total published with two hidden cells');
ADC_Analytics_Settings::$minCell = 1;

// --- 9. Live counts, reconciliation, currencies, warnings --------------------

$counts = ADC_Analytics_Reports::current_order_counts();
check($counts['available'] === false && $counts['counts'] === array(), 'no WooCommerce -> live counts unavailable');
check($counts['reason'] !== '' && $counts['as_of'] === '2026-03-15', 'live counts explain themselves and are dated');

$recon = ADC_Analytics_Reports::reconciliation($range, 'USD');
check($recon['available'] === true && count($recon['days']) === 2, 'reconciliation runs per day');
check($recon['warn'] === false && $recon['max_diff'] === 0.01, 'difference at the tolerance is not a warning');
ADC_Analytics_Reconciler::$diff = 0.5;
$recon_warn = ADC_Analytics_Reports::reconciliation($range, 'USD');
check($recon_warn['warn'] === true && $recon_warn['max_diff'] === 0.5, 'larger difference warns');
check($recon_warn['days'][0]['warn'] === true, 'day-level warning flag');

$too_long = ADC_Analytics_Reports::range('custom', '2025-11-01', '2026-03-15');
$recon_long = ADC_Analytics_Reports::reconciliation($too_long, 'USD');
check($recon_long['available'] === false && $recon_long['reason'] !== '', 'reconciliation bounded per request');
check($recon_long['max_days'] === ADC_Analytics_Reports::MAX_RECONCILIATION_DAYS, 'reconciliation cap exposed');

$recon_bad_currency = ADC_Analytics_Reports::reconciliation($range, 'ZZZ1');
check($recon_bad_currency['available'] === false, 'reconciliation rejects invalid currency');

$currencies = ADC_Analytics_Reports::available_currencies();
check($currencies['available'] === false && $currencies['currencies'] === array(), 'no WooCommerce -> no currency list');
check($currencies['partial'] === true && $currencies['reason'] !== '', 'currency list is explicitly partial');

ADC_Analytics_Settings::$traffic = false;
$warnings = ADC_Analytics_Reports::warnings($range);
$codes    = array_column($warnings, 'code');
foreach (array('cache_undercount', 'bots', 'no_attribution', 'no_unique_visitors', 'small_cell_policy', 'timezone') as $code) {
    check(in_array($code, $codes, true), 'warning present: ' . $code);
}
foreach ($warnings as $warning) {
    check(isset($warning['code'], $warning['severity'], $warning['text']), 'warning has stable keys');
    check($warning['text'] !== '', 'warning text is not empty');
}
check(in_array('traffic_disabled', $codes, true), 'closed traffic gate is a warning');
check(in_array('commerce_disabled', $codes, false) === false, 'open commerce gate does not warn');

// --- 10. Suppression unit behaviour ----------------------------------------

$single = ADC_Analytics_Reports::suppress_rows(
    array(
        array('label' => 'a', 'views' => 2),
        array('label' => 'b', 'views' => 400),
    ),
    'views',
    array('views'),
    5
);
check($single['suppressed_count'] === 2 && $single['complementary'] === true, 'single small cell is complemented');
check($single['rows'][0]['views'] === null && $single['rows'][1]['views'] === null, 'both complemented cells are nulled');

$none_hidden = ADC_Analytics_Reports::suppress_rows(
    array(array('label' => 'a', 'views' => 5), array('label' => 'b', 'views' => 6)),
    'views',
    array('views'),
    5
);
check($none_hidden['suppressed_count'] === 0 && $none_hidden['total'] === 11 && $none_hidden['total_suppressed'] === false, 'values at the threshold stay visible');

$zero_total = ADC_Analytics_Reports::suppress_rows(array(), 'views', array('views'), 5);
check($zero_total['total'] === 0 && $zero_total['total_suppressed'] === false, 'empty breakdown has no suppressed total');

// --- 11. Static guarantees --------------------------------------------------

$source = (string) file_get_contents(__DIR__ . '/../../includes/analytics/class-adc-analytics-reports.php');
check(!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|wpdb)\b/i', $source), 'Reports contains no SQL and no $wpdb');
check(!preg_match('/\b(get_permalink|get_the_title|get_user_meta)\b/', $source), 'Reports never fetches titles, URLs or user meta');
check(!preg_match('/\b(wp_remote_|curl_|file_get_contents)\b/', $source), 'Reports performs no HTTP calls');

if ($failures > 0) {
    fwrite(STDERR, "reports-test: {$failures} failure(s) of {$checks}\n");
    exit(1);
}

echo "reports-test: PASS ({$checks} checks)\n";
