<?php
/** Smart feed product selection backed by consent-gated product aggregates. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Selects bounded product IDs without collecting or inferring visitor-level data. */
final class ADC_Feed_Smart
{
    public const METRICS = array('views', 'add_to_cart', 'begin_checkout', 'purchases');
    public const MODES = array('top_n', 'min_value', 'low_conversion');
    public const PERIODS = array(7, 14, 30, 90);
    public const MAX_LIMIT = 500;
    private const STATS_LIMIT = 500;

    /**
     * Resolve a validated rule to eligible product IDs and aggregate values.
     * @return array{items:array<int,array{product_id:int,metric:string,value:int}>,error:string,warning:string}
     */
    public static function select(array $rule, array $eligibility = array()): array
    {
        $empty = array('items' => array(), 'error' => '', 'warning' => '');
        if (!class_exists('ADC_Settings') || !(bool) ADC_Settings::get('product_stats', 'enabled', false)) {
            $empty['error'] = 'smart_stats_disabled';
            return $empty;
        }
        if (!class_exists('ADC_Product_Stats') || !method_exists('ADC_Product_Stats', 'top')) {
            $empty['error'] = 'smart_stats_unavailable';
            return $empty;
        }

        if ([] !== array_diff(array_keys($rule), array('metric', 'mode', 'period_days', 'limit', 'threshold', 'conversion_percent'))) {
            $empty['error'] = 'smart_rule_invalid';
            return $empty;
        }
        $metric = (string) ($rule['metric'] ?? '');
        $mode = (string) ($rule['mode'] ?? '');
        $days = (int) ($rule['period_days'] ?? 0);
        $limit = (int) ($rule['limit'] ?? 0);
        $threshold = (int) ($rule['threshold'] ?? 0);
        if (!in_array($metric, self::METRICS, true) || !in_array($mode, self::MODES, true)
            || !in_array($days, self::PERIODS, true) || $limit < 1 || $limit > self::MAX_LIMIT
            || ('min_value' === $mode && ($threshold < 1 || !is_int($rule['threshold'] ?? null)))
            || ('low_conversion' === $mode && ('views' !== $metric
                || !is_int($rule['conversion_percent'] ?? 2)
                || (int) ($rule['conversion_percent'] ?? 2) < 0
                || (int) ($rule['conversion_percent'] ?? 2) > 100))
            || ('top_n' === $mode && (array_key_exists('threshold', $rule) || array_key_exists('conversion_percent', $rule)))
            || ('min_value' === $mode && array_key_exists('conversion_percent', $rule))) {
            $empty['error'] = 'smart_rule_invalid';
            return $empty;
        }

        $ranked = array();
        $truncated = false;
        if ('low_conversion' === $mode) {
            if (!method_exists('ADC_Product_Stats', 'purchases_for_products')) {
                $empty['error'] = 'smart_stats_unavailable';
                return $empty;
            }
            $views = self::ranked('views', $days, self::STATS_LIMIT);
            $purchases = ADC_Product_Stats::purchases_for_products($days, array_keys($views));
            $conversion_percent = max(0.0, min(100.0, (float) ($rule['conversion_percent'] ?? 2)));
            foreach ($views as $id => $value) {
                $purchases_value = (int) ($purchases[$id] ?? 0); // Suppressed purchase cells are absent and count as zero.
                if ($value > 0 && ($purchases_value / $value) * 100 <= $conversion_percent) {
                    $ranked[$id] = $value;
                }
            }
            $truncated = count($views) >= self::STATS_LIMIT;
            $metric = 'views';
        } else {
            $query_limit = self::STATS_LIMIT;
            $ranked = self::ranked($metric, $days, $query_limit);
            $stats_capped = count($ranked) >= self::STATS_LIMIT;
            if ('min_value' === $mode) {
                $ranked = array_filter($ranked, static fn(int $value): bool => $value >= $threshold);
            }
            $truncated = $truncated || $stats_capped;
        }

        $ranked = self::eligible_ranked($ranked, $eligibility);
        $ranked = array_slice($ranked, 0, $limit, true);

        if ($ranked === []) {
            $empty['error'] = 'smart_stats_no_data';
            if ($truncated) {
                $empty['warning'] = 'smart_stats_limit_reached';
            }
            return $empty;
        }
        foreach ($ranked as $id => $value) {
            $empty['items'][] = array('product_id' => (int) $id, 'metric' => $metric, 'value' => (int) $value);
        }
        if ($truncated) {
            $empty['warning'] = 'smart_stats_limit_reached';
        }
        return $empty;
    }

    /** Create the stable label text used by the editable parameter mapping row. */
    public static function label_for_rule(array $rule): string
    {
        $mode = (string) ($rule['mode'] ?? 'top_n');
        $metric = 'low_conversion' === $mode ? 'views_low_conversion' : (string) ($rule['metric'] ?? 'products');
        $days = (int) ($rule['period_days'] ?? 30);
        $suffix = 'min_value' === $mode
            ? '_min' . (int) ($rule['threshold'] ?? 1)
            : ('low_conversion' === $mode ? '_max' . (int) ($rule['conversion_percent'] ?? 2) . 'pct' : '');
        return substr(sanitize_key($metric . '_' . $days . 'd' . $suffix), 0, 100);
    }

    /** Apply publication, category, and stock eligibility before truncating to N. */
    private static function eligible_ranked(array $ranked, array $eligibility): array
    {
        if ($ranked === [] || !function_exists('wc_get_products')) {
            return $ranked;
        }
        $query = array('include' => array_keys($ranked), 'status' => 'publish', 'limit' => self::STATS_LIMIT, 'return' => 'ids');
        $categories = array_values(array_filter((array) ($eligibility['categories'] ?? array()), 'is_string'));
        if ($categories !== array()) { $query['category'] = $categories; }
        if (!empty($eligibility['in_stock_only'])) { $query['stock_status'] = 'instock'; }
        $products = wc_get_products($query);
        if (is_object($products) && isset($products->products) && is_array($products->products)) {
            $products = array_map(static fn(mixed $product): int => is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : (int) $product, $products->products);
        }
        if (!is_array($products)) { return array(); }
        $allowed = array_fill_keys(array_map('intval', $products), true);
        return array_intersect_key($ranked, $allowed);
    }

    /** Build selected ID => value, accepting only sane aggregate rows from the stats API. */
    private static function ranked(string $metric, int $days, int $limit): array
    {
        $rows = ADC_Product_Stats::top($metric, $days, $limit);
        $ranked = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['product_id'] ?? 0);
            $value = (int) ($row['value'] ?? 0);
            if ($id > 0 && $value > 0 && !array_key_exists($id, $ranked)) {
                $ranked[$id] = $value;
            }
        }
        return $ranked;
    }
}
