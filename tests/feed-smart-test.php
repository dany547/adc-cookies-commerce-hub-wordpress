<?php
/** Offline tests for bounded Smart Feed selection and safe empty/error behavior. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value)); }
function wc_get_products(array $query): array { return array_values(array_intersect($query['include'] ?? array(), $GLOBALS['smart_eligible_ids'] ?? array())); }
final class ADC_Settings
{
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return $GLOBALS['smart_stats_enabled'] ?? $default;
    }
}
final class ADC_Product_Stats
{
    public static array $data = array();
    public static function top(string $metric, int $days, int $limit): array
    {
        return array_slice(self::$data[$metric] ?? array(), 0, min(500, $limit));
    }
    public static function purchases_for_products(int $days, array $product_ids): array
    {
        $ids = array_fill_keys($product_ids, true);
        $values = array();
        foreach (self::$data['purchases'] ?? array() as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if (isset($ids[$id])) { $values[$id] = (int) ($row['value'] ?? 0); }
        }
        return $values;
    }
}
require __DIR__ . '/../includes/feeds/class-adc-feed-smart.php';

function smart_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}
function smart_rule(array $overrides = array()): array
{
    return array_merge(array('metric' => 'views', 'mode' => 'top_n', 'period_days' => 30, 'limit' => 10), $overrides);
}

$GLOBALS['smart_stats_enabled'] = false;
$disabled = ADC_Feed_Smart::select(smart_rule());
smart_check($disabled['error'] === 'smart_stats_disabled' && $disabled['items'] === array(), 'disabled statistics fail safely without selecting products');
$GLOBALS['smart_stats_enabled'] = true;
$GLOBALS['smart_eligible_ids'] = array(11, 12, 13);
ADC_Product_Stats::$data = array(
    'views' => array(array('product_id' => 11, 'value' => 90), array('product_id' => 12, 'value' => 70), array('product_id' => 13, 'value' => 40)),
    'purchases' => array(array('product_id' => 11, 'value' => 8)),
    'add_to_cart' => array(array('product_id' => 13, 'value' => 12), array('product_id' => 12, 'value' => 3)),
);
$top = ADC_Feed_Smart::select(smart_rule(array('metric' => 'add_to_cart', 'limit' => 1)));
smart_check($top['error'] === '' && $top['items'] === array(array('product_id' => 13, 'metric' => 'add_to_cart', 'value' => 12)), 'top-N selects the highest aggregate values within the requested limit');
$GLOBALS['smart_eligible_ids'] = array(12);
$filtered_top = ADC_Feed_Smart::select(smart_rule(array('limit' => 1)), array('categories' => array('eligible'), 'in_stock_only' => true));
smart_check(array_column($filtered_top['items'], 'product_id') === array(12), 'category/stock/publication eligibility is applied before truncating to N');
$GLOBALS['smart_eligible_ids'] = array(11, 12, 13);
$threshold = ADC_Feed_Smart::select(smart_rule(array('mode' => 'min_value', 'threshold' => 50)));
smart_check(array_column($threshold['items'], 'product_id') === array(11, 12), 'minimum-value rule filters by the configured threshold');
$low = ADC_Feed_Smart::select(smart_rule(array('mode' => 'low_conversion', 'limit' => 5)));
smart_check(array_column($low['items'], 'product_id') === array(12, 13)
    && array_column($low['items'], 'metric') === array('views', 'views'), 'low conversion means view-ranked products excluding top purchasers');
$empty = ADC_Feed_Smart::select(smart_rule(array('metric' => 'begin_checkout')));
smart_check($empty['error'] === 'smart_stats_no_data' && $empty['items'] === array(), 'suppressed or unavailable aggregates produce no fallback to all products');
$bad = ADC_Feed_Smart::select(smart_rule(array('period_days' => 365)));
smart_check($bad['error'] === 'smart_rule_invalid', 'unsupported rule periods are refused');
$many = array();
$GLOBALS['smart_eligible_ids'] = range(1, 520);
for ($id = 1; $id <= 520; ++$id) {
    $many[] = array('product_id' => $id, 'value' => 1000 - $id);
}
ADC_Product_Stats::$data['views'] = $many;
$capped = ADC_Feed_Smart::select(smart_rule(array('limit' => 500)));
smart_check(count($capped['items']) === 500 && $capped['warning'] === 'smart_stats_limit_reached', 'top selection supports the documented 500-item ceiling and warns when the ceiling is reached');
smart_check(ADC_Feed_Smart::label_for_rule(smart_rule(array('mode' => 'low_conversion'))) === 'views_low_conversion_30d_max2pct', 'rule label is stable and suitable for a parameter constant');
echo "feed-smart-test: PASS\n";
