<?php
/**
 * Read-only report façade for operational analytics.
 *
 * This class contains no SQL: every number comes from ADC_Analytics_Store, and
 * every gate comes from ADC_Analytics_Settings. It produces explicit, stable
 * array shapes for the dashboard, the charts and the CSV export, so the three
 * surfaces can never disagree about filters or small-cell suppression.
 *
 * Hard rules implemented here:
 * - dates are validated strictly (Y-m-d, real dates) and the range is bounded;
 * - amounts are summed only inside a single currency, never converted;
 * - page/product/channel filters are applied once, for every consumer;
 * - small cells are suppressed with complementary suppression so a stored
 *   total cannot be used to difference out a single hidden row;
 * - previous-period comparisons are only produced when there is evidence of
 *   data for the previous range;
 * - traffic is gated off by Settings in this release, so traffic report shapes
 *   are returned empty with an explicit, translatable reason.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Aggregation, suppression and range policy for operational analytics.
 */
final class ADC_Analytics_Reports
{
    /** Maximum inclusive span of a report range, in days. */
    public const MAX_DAYS = 366;

    /** Default span when no valid preset/range is requested. */
    public const DEFAULT_DAYS = 30;

    /** Hard cap on rows read from the Store for one report. */
    private const MAX_ROWS = 20000;

    /** Hard cap on the day-by-day reconciliation loop (each day is a WC query). */
    public const MAX_RECONCILIATION_DAYS = 31;

    /** Allowed presets, in days. */
    private const PRESETS = array('7' => 7, '30' => 30, '90' => 90, '365' => 365);

    /** Allowed page types (mirrors the Store allowlist). */
    private const PAGE_TYPES = array(
        'home', 'blog', 'post', 'page', 'product', 'product_cat', 'product_tag',
        'category', 'tag', 'shop', 'archive', 'other',
    );

    /** Allowed referer channels (mirrors the Store allowlist). */
    private const CHANNELS = array('direct', 'unknown', 'search', 'social', 'referral', 'internal');

    /** Commerce metrics that are counts (with amount fallback for processors that store counts in `amount`). */
    private const COUNT_METRICS = array('orders_created', 'orders_paid', 'units');

    /**
     * Build a validated, bounded reporting range plus its previous period.
     *
     * @param string $preset '7'|'30'|'90'|'365'|'custom' (anything else falls back to 30 days).
     * @param string $from   Y-m-d start for a custom range.
     * @param string $to     Y-m-d end for a custom range.
     * @return array{preset:string,from:string,to:string,days:int,prev_from:string,prev_to:string,prev_complete:bool,valid:bool,errors:string[],clamped:bool,max_days:int,timezone:string}
     */
    public static function range(string $preset, string $from = '', string $to = ''): array
    {
        $errors   = array();
        $clamped  = false;
        $today    = self::today();
        $preset   = trim($preset);
        $from     = trim($from);
        $to       = trim($to);

        if ($preset === 'custom' || $from !== '' || $to !== '') {
            if (!self::valid_date($from) || !self::valid_date($to)) {
                $errors[] = 'invalid_range';
                $preset   = 'custom';
                $from     = self::shift_days($today, -(self::DEFAULT_DAYS - 1));
                $to       = $today;
            }
            if ($from > $to) {
                $errors[] = 'reversed_range';
                [$from, $to] = [$to, $from];
            }
            if ($to > $today) {
                $errors[] = 'future_end';
                $to       = $today;
            }
            if ($from > $to) {
                $from = $to;
            }
            $preset = 'custom';
        } elseif (isset(self::PRESETS[$preset])) {
            $days = self::PRESETS[$preset];
            $to   = $today;
            $from = self::shift_days($today, -($days - 1));
        } else {
            $errors[] = 'unknown_preset';
            $preset   = (string) self::DEFAULT_DAYS;
            $to       = $today;
            $from     = self::shift_days($today, -(self::DEFAULT_DAYS - 1));
        }

        $days = self::days_between($from, $to);
        if ($days > self::MAX_DAYS) {
            $errors[] = 'range_clamped';
            $clamped  = true;
            $from     = self::shift_days($to, -(self::MAX_DAYS - 1));
            $days     = self::MAX_DAYS;
        }

        $prev_to   = self::shift_days($from, -1);
        $prev_from = self::shift_days($prev_to, -($days - 1));

        return array(
            'preset'        => $preset,
            'from'          => $from,
            'to'            => $to,
            'days'          => $days,
            'prev_from'     => $prev_from,
            'prev_to'       => $prev_to,
            'prev_complete' => $errors === array(),
            'valid'         => !in_array('invalid_range', $errors, true) && !in_array('reversed_range', $errors, true),
            'errors'        => $errors,
            'clamped'       => $clamped,
            'max_days'      => self::MAX_DAYS,
            'timezone'      => self::timezone_name(),
        );
    }

    /**
     * Headline KPIs for one range and one currency.
     *
     * Financial values are zero (not null) when the range has no data; AOV and
     * refund rate are null when their denominator is zero.
     *
     * The optional filters apply to the estimated traffic card only; they never
     * touch financial values, which always come from orders. The one exception
     * is the units tile: a product_id filter narrows it to that product's
     * complete per-day rows (still currency-separated, never top-N sums).
     *
     * @param array<string,mixed> $range    Result of self::range().
     * @param string              $currency Uppercase ISO code, no conversion.
     * @param array<string,mixed> $filters  Optional page_type/object_id/channel/product_id.
     * @return array<string,mixed>
     */
    public static function kpis(array $range, string $currency, array $filters = array()): array
    {
        $currency       = strtoupper(trim($currency));
        $currency_valid = self::valid_currency($currency);
        $from           = (string) ($range['from'] ?? '');
        $to             = (string) ($range['to'] ?? '');
        $commerce_on    = self::commerce_enabled();
        $traffic_on     = self::traffic_enabled();
        $min_cell       = self::min_cell_count();

        $totals = $currency_valid ? self::commerce_totals($from, $to, $currency) : self::empty_commerce_totals();

        $normalized    = self::normalize_filters($filters);
        $traffic_views = null;
        $traffic_note  = (string) self::gate_reason('traffic');
        if ($traffic_on) {
            $read          = self::read_traffic($from, $to, $normalized);
            $traffic_views = 0;
            foreach ($read['rows'] as $row) {
                $traffic_views += (int) ($row['views'] ?? 0);
            }
        }

        $orders_paid = (int) $totals['orders_paid'];
        $gross       = (float) $totals['gross_revenue'];

        $units = null;
        if ($currency_valid) {
            // Complete-window sold units (see window_units): never a top-N or
            // truncated-row sum; a product_id filter narrows to that product's
            // complete per-day rows.
            $units = self::window_units($from, $to, $currency, (int) $normalized['product_id']);
        }

        $kpis = array(
            'from'              => $from,
            'to'                => $to,
            'days'              => (int) ($range['days'] ?? 0),
            'currency'          => $currency,
            'currency_valid'    => $currency_valid,
            'paid_statuses'     => array_map('strval', ADC_Analytics_Settings::paid_statuses()),
            'orders_created'    => (int) $totals['orders_created'],
            'orders_paid'       => $orders_paid,
            'gross_revenue'     => self::round6($gross),
            'net_revenue'       => self::round6((float) $totals['net_revenue']),
            'tax'               => self::round6((float) $totals['tax']),
            'shipping'          => self::round6((float) $totals['shipping']),
            'discounts'         => self::round6((float) $totals['discounts']),
            'refunds'           => self::round6((float) $totals['refunds']),
            'units'             => $units,
            'aov'               => $orders_paid > 0 ? self::round6($gross / $orders_paid) : null,
            'refund_rate'       => $gross > 0.0 ? round((float) $totals['refunds'] / $gross, 6) : null,
            'traffic_views'     => $traffic_views,
            'traffic_estimated' => true,
            'gates'             => array('commerce' => $commerce_on, 'traffic' => $traffic_on),
            'traffic_available' => $traffic_on,
            'traffic_reason'    => $traffic_on ? '' : $traffic_note,
            'min_cell'          => $min_cell,
            'small_sample'      => $min_cell > 0 && $orders_paid > 0 && $orders_paid < $min_cell,
            'filters'           => array(
                'page_type'  => (string) $normalized['page_type'],
                'object_id'  => (int) $normalized['object_id'],
                'product_id' => (int) $normalized['product_id'],
                'channel'    => (string) $normalized['channel'],
                'valid'      => (bool) $normalized['valid'],
                'ignored'    => (array) $normalized['ignored'],
            ),
            'notes'             => self::kpi_notes($commerce_on, $traffic_on, $currency_valid),
        );

        $kpis['comparison'] = self::comparison($range, $currency, $kpis);

        return $kpis;
    }

    /**
     * Complete-window sold units for the units tile.
     *
     * Definition: GROSS units from paid orders (the per-day `units` aggregate
     * count); refunds do NOT decrement them — refunded units are reported
     * separately (`refund_units`) so gross/net/refunded stay three distinct
     * facts. Without a product filter the value comes from the complete
     * per-currency/day `units` rows (never a top-N sum); with a product_id
     * filter it comes from that product's complete per-day rows.
     */
    private static function window_units(string $from, string $to, string $currency, int $product_id = 0): int
    {
        $units = 0;
        if ($product_id > 0) {
            foreach (self::safe_rows(static fn(): array => ADC_Analytics_Store::product_rows($from, $to, $currency, 100)) as $row) {
                if ((int) ($row['product_id'] ?? 0) === $product_id) {
                    $units += (int) ($row['units'] ?? 0);
                }
            }

            return $units;
        }

        foreach (self::safe_rows(static fn(): array => ADC_Analytics_Store::commerce_rows($from, $to, $currency)) as $row) {
            if ('units' === (string) ($row['metric'] ?? '')) {
                $units += (int) ($row['count'] ?? 0);
            }
        }

        return $units;
    }

    /**
     * Daily traffic series. Empty with a reason while the traffic gate is closed.
     *
     * @param array<string,mixed> $range   Result of self::range().
     * @param array<string,mixed> $filters Optional page_type/object_id/channel/product_id.
     * @return array<string,mixed>
     */
    public static function traffic_series(array $range, array $filters = array()): array
    {
        $normalized = self::normalize_filters($filters);
        $from       = (string) ($range['from'] ?? '');
        $to         = (string) ($range['to'] ?? '');
        $empty      = self::empty_breakdown(1, $normalized);
        $empty['from'] = $from;
        $empty['to']   = $to;

        if (!self::traffic_enabled()) {
            $empty['reason'] = (string) self::gate_reason('traffic');

            return $empty;
        }

        $read     = self::read_traffic($from, $to, $normalized);
        $by_day   = array();
        $total    = 0;
        foreach ($read['rows'] as $row) {
            $date            = (string) ($row['stat_date'] ?? '');
            $views           = (int) ($row['views'] ?? 0);
            $by_day[$date]   = ($by_day[$date] ?? 0) + $views;
            $total          += $views;
        }
        ksort($by_day);

        $rows = array();
        foreach (self::each_day($from, $to) as $date) {
            $rows[] = array('date' => $date, 'views' => (int) ($by_day[$date] ?? 0), 'suppressed' => false);
        }

        $suppressed = self::suppress_rows($rows, 'views', array('views'), self::min_cell_count(), $total);

        return array_merge($empty, array(
            'available'         => true,
            'reason'            => '',
            'rows'              => $suppressed['rows'],
            'views_total'       => $suppressed['total_suppressed'] ? null : $suppressed['total'],
            'suppressed_count'  => $suppressed['suppressed_count'],
            'complementary'     => $suppressed['complementary'],
            'total_suppressed'  => $suppressed['total_suppressed'],
            'truncated'         => $read['truncated'],
            'row_count'         => count($suppressed['rows']),
        ));
    }

    /**
     * Pages with the most views. Labels are safe internal identifiers: this
     * report never reads a page title, URL, query string or author.
     *
     * @param array<string,mixed> $range   Result of self::range().
     * @param array<string,mixed> $filters Optional page_type/object_id/channel/product_id.
     * @param int                 $limit   1..100.
     * @return array<string,mixed>
     */
    public static function top_pages(array $range, array $filters = array(), int $limit = 20): array
    {
        $normalized = self::normalize_filters($filters);
        $limit      = max(1, min(100, $limit));
        $from       = (string) ($range['from'] ?? '');
        $to         = (string) ($range['to'] ?? '');
        $empty      = self::empty_breakdown($limit, $normalized);
        $empty['from'] = $from;
        $empty['to']   = $to;

        if (!self::traffic_enabled()) {
            $empty['reason'] = (string) self::gate_reason('traffic');

            return $empty;
        }

        $read  = self::read_traffic($from, $to, $normalized);
        $pages = array();
        $total = 0;
        foreach ($read['rows'] as $row) {
            $page_type = (string) ($row['page_type'] ?? 'other');
            $object_id = (int) ($row['object_id'] ?? 0);
            $key       = $page_type . '|' . $object_id;
            $views     = (int) ($row['views'] ?? 0);
            if (!isset($pages[$key])) {
                $pages[$key] = array(
                    'page_type' => $page_type,
                    'object_id' => $object_id,
                    'label'     => self::page_label($page_type, $object_id),
                    'views'     => 0,
                    'suppressed' => false,
                );
            }
            $pages[$key]['views'] += $views;
            $total                += $views;
        }

        usort($pages, static function (array $a, array $b): int {
            return ($b['views'] <=> $a['views']) ?: strcmp((string) $a['label'], (string) $b['label']);
        });
        $top = array_slice(array_values($pages), 0, $limit);

        $suppressed = self::suppress_rows($top, 'views', array('views'), self::min_cell_count(), $total);

        return array_merge($empty, array(
            'available'        => true,
            'reason'           => '',
            'rows'             => $suppressed['rows'],
            'views_total'      => $suppressed['total_suppressed'] ? null : $suppressed['total'],
            'suppressed_count' => $suppressed['suppressed_count'],
            'complementary'    => $suppressed['complementary'],
            'total_suppressed' => $suppressed['total_suppressed'],
            'truncated'        => $read['truncated'],
            'row_count'        => count($suppressed['rows']),
            'distinct_pages'   => count($pages),
        ));
    }

    /**
     * Views per referer channel.
     *
     * @param array<string,mixed> $range   Result of self::range().
     * @param array<string,mixed> $filters Optional channel/page_type/object_id filter.
     * @return array<string,mixed>
     */
    public static function channel_distribution(array $range, array $filters = array()): array
    {
        $normalized = self::normalize_filters($filters);
        $from       = (string) ($range['from'] ?? '');
        $to         = (string) ($range['to'] ?? '');
        $empty      = self::empty_breakdown(0, $normalized);
        $empty['from'] = $from;
        $empty['to']   = $to;

        if (!self::traffic_enabled()) {
            $empty['reason'] = (string) self::gate_reason('traffic');

            return $empty;
        }

        $read     = self::read_traffic($from, $to, $normalized);
        $by_channel = array();
        $total    = 0;
        foreach ($read['rows'] as $row) {
            $channel                 = (string) ($row['channel'] ?? 'unknown');
            $views                   = (int) ($row['views'] ?? 0);
            $by_channel[$channel]    = ($by_channel[$channel] ?? 0) + $views;
            $total                  += $views;
        }

        $rows = array();
        foreach (self::CHANNELS as $channel) {
            if (!isset($by_channel[$channel])) {
                continue;
            }
            $rows[] = array(
                'channel'    => $channel,
                'label'      => self::channel_label($channel),
                'views'      => (int) $by_channel[$channel],
                'share'      => $total > 0 ? round($by_channel[$channel] / $total, 6) : null,
                'suppressed' => false,
            );
        }
        usort($rows, static fn(array $a, array $b): int => $b['views'] <=> $a['views']);

        $suppressed = self::suppress_rows($rows, 'views', array('views', 'share'), self::min_cell_count(), $total);

        return array_merge($empty, array(
            'available'        => true,
            'reason'           => '',
            'rows'             => $suppressed['rows'],
            'views_total'      => $suppressed['total_suppressed'] ? null : $suppressed['total'],
            'suppressed_count' => $suppressed['suppressed_count'],
            'complementary'    => $suppressed['complementary'],
            'total_suppressed' => $suppressed['total_suppressed'],
            'truncated'        => $read['truncated'],
            'row_count'        => count($suppressed['rows']),
        ));
    }

    /**
     * Daily commerce series for one currency.
     *
     * @param array<string,mixed> $range    Result of self::range().
     * @param string              $currency Uppercase ISO code; never mixed.
     * @return array<string,mixed>
     */
    public static function commerce_series(array $range, string $currency): array
    {
        $currency       = strtoupper(trim($currency));
        $currency_valid = self::valid_currency($currency);
        $from           = (string) ($range['from'] ?? '');
        $to             = (string) ($range['to'] ?? '');
        $empty          = array(
            'available'        => false,
            'reason'           => '',
            'from'             => $from,
            'to'               => $to,
            'currency'         => $currency,
            'currency_valid'   => $currency_valid,
            'paid_statuses'    => array_map('strval', ADC_Analytics_Settings::paid_statuses()),
            'rows'             => array(),
            'totals'           => null,
            'suppressed_count' => 0,
            'complementary'    => false,
            'total_suppressed' => false,
            'min_cell'         => self::min_cell_count(),
            'truncated'        => false,
            'row_count'        => 0,
        );

        if (!$currency_valid) {
            $empty['reason'] = (string) __('Moneda selectată nu este validă.', 'adc-marketing');

            return $empty;
        }
        if (!self::commerce_enabled()) {
            $empty['reason'] = (string) self::gate_reason('commerce');

            return $empty;
        }

        $read = self::read_commerce($from, $to, $currency);

        $by_day = array();
        foreach (self::each_day($from, $to) as $date) {
            $by_day[$date] = array(
                'date'           => $date,
                'orders_created' => 0,
                'orders_paid'    => 0,
                'gross_revenue'  => 0.0,
                'refunds'        => 0.0,
                'net_revenue'    => 0.0,
                'suppressed'     => false,
            );
        }
        foreach ($read['totals_by_day'] as $date => $bucket) {
            if (!isset($by_day[$date])) {
                continue;
            }
            $by_day[$date]['orders_created'] = (int) $bucket['orders_created'];
            $by_day[$date]['orders_paid']    = (int) $bucket['orders_paid'];
            $by_day[$date]['gross_revenue']  = self::round6((float) $bucket['gross_revenue']);
            $by_day[$date]['refunds']        = self::round6((float) $bucket['refunds']);
            $by_day[$date]['net_revenue']    = self::round6((float) $bucket['gross_revenue'] - (float) $bucket['refunds']);
        }

        $rows       = array_values($by_day);
        $total_paid = (int) $read['totals']['orders_paid'];
        $suppressed = self::suppress_rows(
            $rows,
            'orders_paid',
            array('orders_paid', 'orders_created', 'gross_revenue', 'refunds', 'net_revenue'),
            self::min_cell_count(),
            $total_paid
        );

        $totals = null;
        if (!$suppressed['total_suppressed']) {
            $totals = array(
                'orders_created' => (int) $read['totals']['orders_created'],
                'orders_paid'    => (int) $read['totals']['orders_paid'],
                'gross_revenue'  => self::round6((float) $read['totals']['gross_revenue']),
                'refunds'        => self::round6((float) $read['totals']['refunds']),
                'net_revenue'    => self::round6((float) $read['totals']['net_revenue']),
            );
        }

        return array_merge($empty, array(
            'available'        => true,
            'reason'           => '',
            'rows'             => $suppressed['rows'],
            'totals'           => $totals,
            'suppressed_count' => $suppressed['suppressed_count'],
            'complementary'    => $suppressed['complementary'],
            'total_suppressed' => $suppressed['total_suppressed'],
            'truncated'        => $read['truncated'],
            'row_count'        => count($suppressed['rows']),
        ));
    }

    /**
     * Top products for one currency. Titles are not stored nor fetched here:
     * the dashboard may resolve `product_id` to a title at render time.
     *
     * @param array<string,mixed> $range    Result of self::range().
     * @param string              $currency Uppercase ISO code.
     * @param int                 $limit    1..100.
     * @param array<string,mixed> $filters  Optional product_id filter (unknown keys are ignored).
     * @return array<string,mixed>
     */
    public static function top_products(array $range, string $currency, int $limit = 20, array $filters = array()): array
    {
        $currency       = strtoupper(trim($currency));
        $currency_valid = self::valid_currency($currency);
        $limit          = max(1, min(100, $limit));
        $from           = (string) ($range['from'] ?? '');
        $to             = (string) ($range['to'] ?? '');
        $min_cell       = self::min_cell_count();
        $empty          = array(
            'available'        => false,
            'reason'           => '',
            'from'             => $from,
            'to'               => $to,
            'currency'         => $currency,
            'currency_valid'   => $currency_valid,
            'rows'             => array(),
            'totals'           => null,
            'suppressed_count' => 0,
            'complementary'    => false,
            'total_suppressed' => false,
            'min_cell'         => $min_cell,
            'truncated'        => false,
            'limit'            => $limit,
            'row_count'        => 0,
            'distinct_products' => 0,
        );

        if (!$currency_valid) {
            $empty['reason'] = (string) __('Moneda selectată nu este validă.', 'adc-marketing');

            return $empty;
        }
        if (!self::commerce_enabled()) {
            $empty['reason'] = (string) self::gate_reason('commerce');

            return $empty;
        }

        $normalized  = self::normalize_filters($filters);
        $read        = self::read_products($from, $to, $currency, (int) $normalized['product_id']);
        $units_total = (int) $read['sums']['units'];

        $aggregate = array_values($read['aggregate']);
        usort($aggregate, static fn(array $a, array $b): int => ($b['units'] <=> $a['units']) ?: ($a['product_id'] <=> $b['product_id']));
        $top = array_slice($aggregate, 0, $limit);

        $rows = array();
        foreach ($top as $row) {
            $rows[] = array(
                'product_id'   => (int) $row['product_id'],
                'label'        => self::product_label((int) $row['product_id']),
                'units'        => (int) $row['units'],
                'net'          => self::round6((float) $row['items_net']),
                'tax'          => self::round6((float) $row['items_tax']),
                'refund_units' => (int) $row['refund_units'],
                'refund_net'   => self::round6((float) $row['refund_net']),
                'suppressed'   => false,
            );
        }

        $suppressed = self::suppress_rows(
            $rows,
            'units',
            array('units', 'net', 'tax', 'refund_units', 'refund_net'),
            $min_cell,
            $units_total
        );

        $totals = null;
        if (!$suppressed['total_suppressed']) {
            $totals = array(
                'units'        => $units_total,
                'net'          => self::round6((float) $read['sums']['items_net']),
                'tax'          => self::round6((float) $read['sums']['items_tax']),
                'refund_units' => (int) $read['sums']['refund_units'],
                'refund_net'   => self::round6((float) $read['sums']['refund_net']),
            );
        }

        return array_merge($empty, array(
            'available'        => true,
            'reason'           => '',
            'rows'             => $suppressed['rows'],
            'totals'           => $totals,
            'suppressed_count' => $suppressed['suppressed_count'],
            'complementary'    => $suppressed['complementary'],
            'total_suppressed' => $suppressed['total_suppressed'],
            'truncated'        => $read['truncated'],
            'row_count'        => count($suppressed['rows']),
            'distinct_products' => count($aggregate),
        ));
    }

    /**
     * Live current-state order counts, straight from WooCommerce.
     *
     * One bounded call per status (`wc_orders_count`), never per order row; no
     * order IDs, customer data or line items are read.
     *
     * @return array{available:bool,reason:string,counts:array<string,int>,total:int,statuses:string[],source:string,as_of:string}
     */
    public static function current_order_counts(): array
    {
        $result = array(
            'available' => false,
            'reason'    => '',
            'counts'    => array(),
            'total'     => 0,
            'statuses'  => array(),
            'source'    => 'woocommerce',
            'as_of'     => self::today(),
        );

        if (!function_exists('wc_get_order_statuses') || !function_exists('wc_orders_count')) {
            $result['reason'] = (string) __('WooCommerce nu este disponibil.', 'adc-marketing');

            return $result;
        }

        $statuses = array_keys((array) wc_get_order_statuses());
        if ($statuses === array()) {
            $result['reason'] = (string) __('Nu există statusuri de comandă.', 'adc-marketing');

            return $result;
        }

        $total = 0;
        foreach (array_slice($statuses, 0, 20) as $status) {
            $status = (string) preg_replace('/^wc-/', '', (string) $status);
            if ($status === '' || !preg_match('/^[a-z0-9_-]{1,20}$/D', $status)) {
                continue;
            }
            $count = (int) wc_orders_count($status);
            $result['counts'][$status] = $count;
            $result['statuses'][]      = $status;
            $total                    += $count;
        }

        $result['available'] = $result['counts'] !== array();
        $result['total']     = $total;
        if (!$result['available']) {
            $result['reason'] = (string) __('Statusurile de comandă nu au putut fi citite.', 'adc-marketing');
        }

        return $result;
    }

    /**
     * Day-by-day comparison between the aggregates and the orders themselves.
     *
     * The recomputation lives in ADC_Analytics_Reconciler (one WC query set per
     * day), so this façade only bounds the loop and shapes the answer.
     *
     * @param array<string,mixed> $range    Result of self::range().
     * @param string              $currency Uppercase ISO code.
     * @return array{available:bool,reason:string,currency:string,days:array<int,array{date:string,diff:array<string,float>|null,warn:bool}>,max_diff:float,warn:bool,max_days:int}
     */
    public static function reconciliation(array $range, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        $from     = (string) ($range['from'] ?? '');
        $to       = (string) ($range['to'] ?? '');
        $result   = array(
            'available' => false,
            'reason'    => '',
            'currency'  => $currency,
            'days'      => array(),
            'max_diff'  => 0.0,
            'warn'      => false,
            'max_days'  => self::MAX_RECONCILIATION_DAYS,
        );

        if (!self::commerce_enabled()) {
            $result['reason'] = (string) self::gate_reason('commerce');

            return $result;
        }
        if (!self::valid_currency($currency)) {
            $result['reason'] = (string) __('Moneda selectată nu este validă.', 'adc-marketing');

            return $result;
        }
        if (!class_exists('ADC_Analytics_Reconciler')) {
            $result['reason'] = (string) __('Reconcilierea nu este disponibilă în această instalare.', 'adc-marketing');

            return $result;
        }
        if (!self::valid_date($from) || !self::valid_date($to) || $from > $to) {
            $result['reason'] = (string) __('Intervalul de date nu este valid.', 'adc-marketing');

            return $result;
        }

        $days = self::each_day($from, $to);
        if (count($days) > self::MAX_RECONCILIATION_DAYS) {
            $result['reason'] = (string) sprintf(
                /* translators: %d: maximum number of days that can be reconciled at once. */
                __('Reconcilierea este limitată la %d zile per cerere.', 'adc-marketing'),
                self::MAX_RECONCILIATION_DAYS
            );

            return $result;
        }

        $max = 0.0;
        $warn = false;
        foreach ($days as $date) {
            try {
                $diff = (array) ADC_Analytics_Reconciler::compare_day($date, $currency);
            } catch (Throwable $error) {
                $result['reason'] = (string) __('Reconcilierea a eșuat pentru intervalul selectat.', 'adc-marketing');

                return $result;
            }
            $day_max = 0.0;
            foreach ($diff as $value) {
                $day_max = max($day_max, abs((float) $value));
            }
            $day_warn                  = $day_max > 0.01;
            $warn                      = $warn || $day_warn;
            $max                       = max($max, $day_max);
            $result['days'][]          = array('date' => $date, 'diff' => $diff, 'warn' => $day_warn);
        }

        $result['available'] = true;
        $result['max_diff']  = self::round6($max);
        $result['warn']      = $warn;

        return $result;
    }

    /**
     * Currencies that can be selected safely.
     *
     * The store currency always comes first. Other currencies are detected from
     * one bounded, most-recent-order query (no aggregation, no customer data).
     *
     * @return array{available:bool,reason:string,base:string,currencies:string[],partial:bool,source:string}
     */
    public static function available_currencies(): array
    {
        $base   = function_exists('get_woocommerce_currency') ? strtoupper((string) get_woocommerce_currency()) : '';
        $base   = self::valid_currency($base) ? $base : '';
        $result = array(
            'available'  => $base !== '',
            'reason'     => '',
            'base'       => $base,
            'currencies' => $base !== '' ? array($base) : array(),
            'partial'    => true,
            'source'     => 'woocommerce',
        );

        if (!$result['available']) {
            $result['reason'] = (string) __('Moneda magazinului nu este disponibilă.', 'adc-marketing');

            return $result;
        }

        if (!function_exists('wc_get_orders')) {
            $result['reason'] = (string) __('WooCommerce nu este disponibil pentru detectarea monedelor.', 'adc-marketing');

            return $result;
        }

        try {
            $orders = wc_get_orders(array(
                'limit'   => 50,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
                'status'  => 'any',
            ));
        } catch (Throwable $error) {
            $result['reason'] = (string) __('Monedele secundare nu au putut fi detectate.', 'adc-marketing');

            return $result;
        }

        foreach ((array) $orders as $order) {
            if (!is_object($order) || !method_exists($order, 'get_currency')) {
                continue;
            }
            $code = strtoupper((string) $order->get_currency());
            if (self::valid_currency($code) && !in_array($code, $result['currencies'], true)) {
                $result['currencies'][] = $code;
            }
        }

        return $result;
    }

    /**
     * Limitations that must be shown next to the numbers (Romanian UI text).
     *
     * @param array<string,mixed> $range Result of self::range().
     * @return array<int,array{code:string,severity:string,text:string}>
     */
    public static function warnings(array $range): array
    {
        $warnings = array();
        $add      = static function (string $code, string $severity, string $text) use (&$warnings): void {
            $warnings[] = array('code' => $code, 'severity' => $severity, 'text' => $text);
        };

        if (!self::commerce_enabled()) {
            $add('commerce_disabled', 'info', (string) self::gate_reason('commerce'));
        }
        if (!self::traffic_enabled()) {
            $add('traffic_disabled', 'info', (string) self::gate_reason('traffic'));
        }

        $add('cache_undercount', 'warning', (string) __('Paginile servite din cache sau CDN nu sunt numărate: valorile de trafic sunt subestimate.'));
        $add('bots', 'warning', (string) __('Traficul automatizat este filtrat, dar filtrul nu este perfect: pot rămâne vizite false sau pot lipsi vizite reale.'));
        $add('missing_referrer', 'info', (string) __('Lipsa referrer-ului este raportată ca „direct”: o parte din traficul „direct” poate veni din alte surse.'));
        $add('no_unique_visitors', 'warning', (string) __('Nu se calculează vizitatori unici: module nu stochează identificatori, cookie-uri sau adrese IP.'));
        $add('no_attribution', 'warning', (string) __('Comenzile nu sunt atribuite surselor de trafic: nu există conversie pe canal și nicio legătură între vizite și comenzi.'));
        $add('no_abandonment', 'info', (string) __('Rata de abandon al coșului este indisponibilă: nu există evenimente de început de checkout.'));
        $add('single_currency', 'info', (string) __('Sumele sunt calculate doar într-o singură monedă, fără conversie. Nu se însumează monede diferite.'));
        $add('small_cell_policy', 'info', (string) sprintf(
            /* translators: %d: minimum cell count. */
            __('Celulele cu valori sub %d sunt ascunse, inclusiv prin suprimare complementară, ca să nu poată fi deduse prin diferență.'),
            self::min_cell_count()
        ));
        $add('timezone', 'info', (string) sprintf(
            /* translators: %s: site timezone name. */
            __('Datele sunt zile locale ale site-ului (%s), calculate pe fusul orar al site-ului.'),
            self::timezone_name()
        ));

        if (($range['prev_complete'] ?? false) === false) {
            $add('previous_incomplete', 'info', (string) __('Intervalul anterior este incomplet; comparația cu perioada precedentă nu este afișată.'));
        }
        if (($range['clamped'] ?? false) === true) {
            $add('range_clamped', 'info', (string) sprintf(
                /* translators: %d: maximum number of days. */
                __('Intervalul a fost limitat la %d de zile.'),
                self::MAX_DAYS
            ));
        }

        return $warnings;
    }

    /**
     * Minimum cell count used by every report, chart and export.
     */
    public static function min_cell_count(): int
    {
        return ADC_Analytics_Settings::min_cell_count();
    }

    /**
     * Suppress small cells and apply complementary suppression.
     *
     * A row whose counting metric is in (0, min_cell) is hidden. If exactly one
     * row would be hidden, the smallest remaining visible row is hidden too, so
     * that the published total cannot be used to difference the hidden value
     * out. The total itself is withheld when it is itself a small cell.
     *
     * @param array<int,array<string,mixed>> $rows       Breakdown rows.
     * @param string                         $count_key  Counting metric key.
     * @param string[]                       $value_keys Keys nulled for hidden rows.
     * @param int                            $min_cell   Threshold (0 disables suppression).
     * @param int|null                       $total      Breakdown total (defaults to the sum of rows).
     * @return array{rows:array<int,array<string,mixed>>,suppressed_count:int,complementary:bool,total:int,total_suppressed:bool,min_cell:int}
     */
    public static function suppress_rows(array $rows, string $count_key, array $value_keys, int $min_cell, ?int $total = null): array
    {
        $rows       = array_values($rows);
        $sum        = 0;
        $suppressed = 0;
        foreach ($rows as $index => $row) {
            $value            = (int) ($row[$count_key] ?? 0);
            $hidden           = $min_cell > 0 && $value > 0 && $value < $min_cell;
            $rows[$index]['suppressed'] = $hidden;
            $sum             += $value;
            $suppressed      += $hidden ? 1 : 0;
        }
        $total ??= $sum;

        $complementary = false;
        if ($suppressed === 1) {
            $candidate = null;
            foreach ($rows as $index => $row) {
                if (($row['suppressed'] ?? false) === true || (int) ($row[$count_key] ?? 0) <= 0) {
                    continue;
                }
                if ($candidate === null || (int) $row[$count_key] < (int) $rows[$candidate][$count_key]) {
                    $candidate = $index;
                }
            }
            if ($candidate !== null) {
                $rows[$candidate]['suppressed'] = true;
                $suppressed++;
                $complementary = true;
            }
        }

        foreach ($rows as $index => $row) {
            if (($row['suppressed'] ?? false) !== true) {
                continue;
            }
            foreach ($value_keys as $key) {
                if (array_key_exists($key, $rows[$index])) {
                    $rows[$index][$key] = null;
                }
            }
        }

        $total_suppressed = ($min_cell > 0 && $total > 0 && $total < $min_cell) || ($suppressed === 1);

        return array(
            'rows'             => $rows,
            'suppressed_count' => $suppressed,
            'complementary'    => $complementary,
            'total'            => $total,
            'total_suppressed' => $total_suppressed,
            'min_cell'         => $min_cell,
        );
    }

    /**
     * Normalize optional filters. Unknown values are dropped, never passed to
     * the Store, and reported through `filters_valid`.
     *
     * @param array<string,mixed> $filters Raw filters.
     * @return array{page_type:string,object_id:int,product_id:int,channel:string,valid:bool,ignored:string[]}
     */
    public static function normalize_filters(array $filters = array()): array
    {
        $normalized = array(
            'page_type'  => '',
            'object_id'  => 0,
            'product_id' => 0,
            'channel'    => '',
            'valid'      => true,
            'ignored'    => array(),
        );

        foreach ($filters as $key => $value) {
            switch ((string) $key) {
                case 'page_type':
                    $candidate = is_string($value) ? strtolower(trim($value)) : '';
                    if (in_array($candidate, self::PAGE_TYPES, true)) {
                        $normalized['page_type'] = $candidate;
                    } else {
                        $normalized['ignored'][] = 'page_type';
                        $normalized['valid']     = false;
                    }
                    break;

                case 'channel':
                    $candidate = is_string($value) ? strtolower(trim($value)) : '';
                    if (in_array($candidate, self::CHANNELS, true)) {
                        $normalized['channel'] = $candidate;
                    } else {
                        $normalized['ignored'][] = 'channel';
                        $normalized['valid']     = false;
                    }
                    break;

                case 'object_id':
                case 'product_id':
                    $candidate = is_numeric($value) ? (int) $value : 0;
                    if ($candidate >= 0 && $candidate <= PHP_INT_MAX) {
                        $normalized[(string) $key] = $candidate;
                    } else {
                        $normalized['ignored'][] = (string) $key;
                        $normalized['valid']     = false;
                    }
                    break;

                default:
                    $normalized['ignored'][] = (string) $key;
                    $normalized['valid']     = false;
            }
        }

        return $normalized;
    }

    /**
     * Read and filter traffic rows. Store filters are limited to page_type and
     * object_id; channel and product filters are applied in memory.
     *
     * @param string              $from       Y-m-d.
     * @param string              $to         Y-m-d.
     * @param array<string,mixed> $normalized Result of self::normalize_filters().
     * @return array{rows:array<int,array<string,mixed>>,truncated:bool}
     */
    private static function read_traffic(string $from, string $to, array $normalized): array
    {
        $store_filters = array();
        if (($normalized['page_type'] ?? '') !== '') {
            $store_filters['page_type'] = (string) $normalized['page_type'];
        }
        if ((int) ($normalized['object_id'] ?? 0) > 0) {
            $store_filters['object_id'] = (int) $normalized['object_id'];
        }
        if ((int) ($normalized['product_id'] ?? 0) > 0) {
            $store_filters['object_id'] = (int) $normalized['product_id'];
        }

        $rows      = self::safe_rows(static fn(): array => ADC_Analytics_Store::traffic_rows($from, $to, $store_filters));
        $truncated = count($rows) > self::MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        $channel = (string) ($normalized['channel'] ?? '');
        if ($channel !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (string) ($row['channel'] ?? '') === $channel
            ));
        }

        return array('rows' => $rows, 'truncated' => $truncated);
    }

    /**
     * Read commerce rows for a currency and reduce them to totals.
     *
     * @param string $from     Y-m-d.
     * @param string $to       Y-m-d.
     * @param string $currency Uppercase ISO code.
     * @return array{totals:array<string,mixed>,totals_by_day:array<string,array<string,mixed>>,truncated:bool}
     */
    private static function read_commerce(string $from, string $to, string $currency): array
    {
        // No status filter: the metric name carries the semantics (orders_created,
        // orders_paid, order_total, refund_total, status_reached:<status>) and the
        // processor writes most metrics with an empty order_status column.
        $rows      = self::safe_rows(static fn(): array => ADC_Analytics_Store::commerce_rows($from, $to, $currency));
        $truncated = count($rows) > self::MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        $amounts = array();
        $counts  = array();
        foreach ($rows as $row) {
            $metric = (string) ($row['metric'] ?? '');
            if ($metric === '') {
                continue;
            }
            $amounts[$metric] = ($amounts[$metric] ?? 0.0) + (float) ($row['amount'] ?? 0);
            $counts[$metric]  = ($counts[$metric] ?? 0) + (int) ($row['count'] ?? 0);
        }

        $count_for = static function (string $metric) use ($amounts, $counts): int {
            if (($counts[$metric] ?? 0) > 0) {
                return (int) $counts[$metric];
            }

            return (int) round((float) ($amounts[$metric] ?? 0));
        };

        $totals = array(
            'orders_created' => $count_for('orders_created'),
            'orders_paid'    => $count_for('orders_paid'),
            'gross_revenue'  => (float) ($amounts['order_total'] ?? 0),
            'refunds'        => abs((float) ($amounts['refund_total'] ?? 0)),
            'tax'            => (float) ($amounts['tax_total'] ?? 0),
            'shipping'       => (float) ($amounts['shipping_total'] ?? 0) + (float) ($amounts['shipping_tax'] ?? 0),
            'discounts'      => (float) ($amounts['discount_total'] ?? 0) + (float) ($amounts['discount_tax'] ?? 0),
        );
        $totals['net_revenue'] = (float) $totals['gross_revenue'] - (float) $totals['refunds'];

        $by_day = array();
        foreach ($rows as $row) {
            $date = (string) ($row['stat_date'] ?? '');
            $metric = (string) ($row['metric'] ?? '');
            if ($date === '' || $metric === '') {
                continue;
            }
            $by_day[$date] ??= array(
                'orders_created' => 0,
                'orders_paid'    => 0,
                'gross_revenue'  => 0.0,
                'refunds'        => 0.0,
            );
            switch ($metric) {
                case 'order_total':
                    $by_day[$date]['gross_revenue'] += (float) ($row['amount'] ?? 0);
                    break;
                case 'refund_total':
                    $by_day[$date]['refunds'] += abs((float) ($row['amount'] ?? 0));
                    break;
                case 'orders_created':
                case 'orders_paid':
                    $by_day[$date][$metric] += ((int) ($row['count'] ?? 0) > 0)
                        ? (int) $row['count']
                        : (int) round((float) ($row['amount'] ?? 0));
                    break;
            }
        }

        return array('totals' => $totals, 'totals_by_day' => $by_day, 'truncated' => $truncated);
    }

    /**
     * Read product rows for a currency and aggregate per product id.
     *
     * @param string $from       Y-m-d.
     * @param string $to         Y-m-d.
     * @param string $currency   Uppercase ISO code.
     * @param int    $only_product_id Optional single-product filter (0 = all).
     * @return array{aggregate:array<int,array<string,mixed>>,sums:array<string,mixed>,truncated:bool}
     */
    private static function read_products(string $from, string $to, string $currency, int $only_product_id = 0): array
    {
        $rows      = self::safe_rows(static fn(): array => ADC_Analytics_Store::product_rows($from, $to, $currency, 100));
        $truncated = count($rows) > self::MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        $aggregate = array();
        $sums      = array('units' => 0, 'items_net' => 0.0, 'items_tax' => 0.0, 'refund_units' => 0, 'refund_net' => 0.0);
        foreach ($rows as $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            if ($product_id < 1 || ($only_product_id > 0 && $product_id !== $only_product_id)) {
                continue;
            }
            $aggregate[$product_id] ??= array(
                'product_id'   => $product_id,
                'units'        => 0,
                'items_net'    => 0.0,
                'items_tax'    => 0.0,
                'refund_units' => 0,
                'refund_net'   => 0.0,
            );
            foreach (array('units', 'refund_units') as $key) {
                $value = (int) ($row[$key] ?? 0);
                $aggregate[$product_id][$key] += $value;
                $sums[$key]                   += $value;
            }
            foreach (array('items_net', 'items_tax', 'refund_net') as $key) {
                $value = (float) ($row[$key] ?? 0);
                $aggregate[$product_id][$key] += $value;
                $sums[$key]                   += $value;
            }
        }

        return array('aggregate' => $aggregate, 'sums' => $sums, 'truncated' => $truncated);
    }

    /**
     * Commerce totals for a range, used by the KPI block.
     *
     * @param string $from     Y-m-d.
     * @param string $to       Y-m-d.
     * @param string $currency Uppercase ISO code.
     * @return array<string,mixed>
     */
    private static function commerce_totals(string $from, string $to, string $currency): array
    {
        if (!self::commerce_enabled()) {
            return self::empty_commerce_totals();
        }
        $read = self::read_commerce($from, $to, $currency);

        return $read['totals'];
    }

    /**
     * Previous-period comparison, produced only with concrete availability
     * evidence (the gate is open and the previous range has at least one row).
     *
     * @param array<string,mixed> $range Result of self::range().
     * @param string              $currency Uppercase ISO code.
     * @param array<string,mixed> $current Current KPI block.
     * @return array{available:bool,reason:string,previous:array<string,mixed>|null,delta:array<string,mixed>|null,evidence:string}
     */
    private static function comparison(array $range, string $currency, array $current): array
    {
        $empty = array(
            'available' => false,
            'reason'    => '',
            'previous'  => null,
            'delta'     => null,
            'evidence'  => 'none',
        );

        if (($range['prev_complete'] ?? false) !== true) {
            $empty['reason'] = (string) __('Intervalul anterior este incomplet.', 'adc-marketing');

            return $empty;
        }
        if (!self::commerce_enabled()) {
            $empty['reason'] = (string) self::gate_reason('commerce');

            return $empty;
        }
        if (($current['currency_valid'] ?? false) !== true) {
            $empty['reason'] = (string) __('Moneda selectată nu este validă.', 'adc-marketing');

            return $empty;
        }

        $prev_from = (string) ($range['prev_from'] ?? '');
        $prev_to   = (string) ($range['prev_to'] ?? '');
        $rows      = self::safe_rows(static fn(): array => ADC_Analytics_Store::commerce_rows($prev_from, $prev_to, $currency));
        if ($rows === array()) {
            $empty['reason'] = (string) __('Nu există date pentru perioada anterioară.', 'adc-marketing');

            return $empty;
        }

        $read     = self::read_commerce($prev_from, $prev_to, $currency);
        $previous = array(
            'from'           => $prev_from,
            'to'             => $prev_to,
            'orders_created' => (int) $read['totals']['orders_created'],
            'orders_paid'    => (int) $read['totals']['orders_paid'],
            'gross_revenue'  => self::round6((float) $read['totals']['gross_revenue']),
            'net_revenue'    => self::round6((float) $read['totals']['net_revenue']),
            'refunds'        => self::round6((float) $read['totals']['refunds']),
        );

        $delta = array();
        foreach (array('orders_created', 'orders_paid', 'gross_revenue', 'net_revenue', 'refunds') as $key) {
            $delta[$key] = self::round6((float) ($current[$key] ?? 0) - (float) $previous[$key]);
        }

        return array(
            'available' => true,
            'reason'    => '',
            'previous'  => $previous,
            'delta'     => $delta,
            'evidence'  => 'store_rows_present',
        );
    }

    /**
     * Customer-facing notes about what is estimated, exact or unavailable.
     *
     * @param bool $commerce_on   Commerce gate state.
     * @param bool $traffic_on    Traffic gate state.
     * @param bool $currency_valid Currency validity.
     * @return string[]
     */
    private static function kpi_notes(bool $commerce_on, bool $traffic_on, bool $currency_valid): array
    {
        $notes = array();
        $notes[] = (string) __('Valorile financiare provin din comenzi WooCommerce, nu din trafic.', 'adc-marketing');
        if (!$commerce_on) {
            $notes[] = (string) self::gate_reason('commerce');
        }
        if (!$traffic_on) {
            $notes[] = (string) self::gate_reason('traffic');
        }
        if (!$currency_valid) {
            $notes[] = (string) __('Sumele nu sunt afișate: moneda selectată nu este validă.', 'adc-marketing');
        }
        $notes[] = (string) __('Vizitatori unici, conversie și atribuire sunt indisponibile prin design.', 'adc-marketing');

        return $notes;
    }

    /**
     * Empty breakdown envelope with stable keys.
     *
     * @param int                 $limit     Requested row limit (0 when not applicable).
     * @param array<string,mixed> $normalized Normalized filters.
     * @return array<string,mixed>
     */
    private static function empty_breakdown(int $limit, array $normalized): array
    {
        return array(
            'available'        => false,
            'reason'           => '',
            'from'             => '',
            'to'               => '',
            'filters'          => array(
                'page_type'  => (string) ($normalized['page_type'] ?? ''),
                'object_id'  => (int) ($normalized['object_id'] ?? 0),
                'product_id' => (int) ($normalized['product_id'] ?? 0),
                'channel'    => (string) ($normalized['channel'] ?? ''),
                'valid'      => (bool) ($normalized['valid'] ?? true),
                'ignored'    => (array) ($normalized['ignored'] ?? array()),
            ),
            'granularity'      => 'day',
            'rows'             => array(),
            'views_total'      => null,
            'suppressed_count' => 0,
            'complementary'    => false,
            'total_suppressed' => false,
            'min_cell'         => self::min_cell_count(),
            'truncated'        => false,
            'row_count'        => 0,
            'limit'            => $limit,
        );
    }

    /**
     * Empty commerce totals (used when the gate is closed or the currency is invalid).
     *
     * @return array<string,mixed>
     */
    private static function empty_commerce_totals(): array
    {
        return array(
            'orders_created' => 0,
            'orders_paid'    => 0,
            'gross_revenue'  => 0.0,
            'refunds'        => 0.0,
            'tax'            => 0.0,
            'shipping'       => 0.0,
            'discounts'      => 0.0,
            'net_revenue'    => 0.0,
        );
    }

    /**
     * Run a Store read, converting storage failures into an empty result.
     *
     * @param callable $read Store read callback.
     * @return array<int,array<string,mixed>>
     */
    private static function safe_rows(callable $read): array
    {
        try {
            $rows = $read();
        } catch (Throwable $error) {
            return array();
        }

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    /**
     * Whether the commerce gate is open.
     */
    private static function commerce_enabled(): bool
    {
        return ADC_Analytics_Settings::commerce_enabled();
    }

    /**
     * Whether the traffic gate is open (always false in this release).
     */
    private static function traffic_enabled(): bool
    {
        return ADC_Analytics_Settings::traffic_enabled();
    }

    /**
     * Romanian reason for a closed gate.
     */
    private static function gate_reason(string $feature): string
    {
        $status = ADC_Analytics_Settings::legal_gate_status($feature);

        return (string) ($status['reason'] ?? '');
    }

    /**
     * Safe label for a page dimension (never a title, path or query string).
     */
    private static function page_label(string $page_type, int $object_id): string
    {
        $labels = array(
            'home'        => __('Prima pagină', 'adc-marketing'),
            'blog'        => __('Blog', 'adc-marketing'),
            'post'        => __('Articol', 'adc-marketing'),
            'page'        => __('Pagină', 'adc-marketing'),
            'product'     => __('Produs', 'adc-marketing'),
            'product_cat' => __('Categorie produse', 'adc-marketing'),
            'product_tag' => __('Etichetă produse', 'adc-marketing'),
            'category'    => __('Categorie', 'adc-marketing'),
            'tag'         => __('Etichetă', 'adc-marketing'),
            'shop'        => __('Magazin', 'adc-marketing'),
            'archive'     => __('Arhivă', 'adc-marketing'),
            'other'       => __('Altă pagină', 'adc-marketing'),
        );
        $label = (string) ($labels[$page_type] ?? $labels['other']);

        return $object_id > 0 ? sprintf('%s #%d', $label, $object_id) : $label;
    }

    /**
     * Safe label for a product dimension (internal id only).
     */
    private static function product_label(int $product_id): string
    {
        /* translators: %d: WooCommerce product ID. */
        return sprintf(__('Produs #%d', 'adc-marketing'), $product_id);
    }

    /**
     * Romanian label for a channel slug.
     */
    private static function channel_label(string $channel): string
    {
        $labels = array(
            'direct'   => __('Direct', 'adc-marketing'),
            'unknown'  => __('Necunoscut', 'adc-marketing'),
            'search'   => __('Motivare: căutare', 'adc-marketing'),
            'social'   => __('Social', 'adc-marketing'),
            'referral' => __('Recomandare (referral)', 'adc-marketing'),
            'internal' => __('Intern', 'adc-marketing'),
        );

        return (string) ($labels[$channel] ?? $labels['unknown']);
    }

    /**
     * Site-local "today" as Y-m-d.
     */
    private static function today(): string
    {
        if (function_exists('current_time')) {
            $today = (string) current_time('Y-m-d');
            if (self::valid_date($today)) {
                return $today;
            }
        }

        return gmdate('Y-m-d');
    }

    /**
     * Site timezone name for display.
     */
    private static function timezone_name(): string
    {
        if (function_exists('wp_timezone_string')) {
            $name = (string) wp_timezone_string();
            if ($name !== '') {
                return $name;
            }
        }

        return (string) date_default_timezone_get();
    }

    /**
     * Strict Y-m-d validation (rejects 2024-02-30, 2024-2-3 and empty values).
     */
    private static function valid_date(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Strict ISO-4217-style code check (3 uppercase letters).
     */
    private static function valid_currency(string $currency): bool
    {
        return (bool) preg_match('/^[A-Z]{3}$/D', $currency);
    }

    /**
     * Shift a Y-m-d date by N days (no DST-time arithmetic on dates).
     */
    private static function shift_days(string $date, int $days): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false) {
            return $date;
        }

        return $parsed->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Inclusive day count between two Y-m-d dates.
     */
    private static function days_between(string $from, string $to): int
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end   = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false) {
            return 0;
        }

        return (int) $start->diff($end)->days + 1;
    }

    /**
     * Every Y-m-d day in an inclusive range (bounded by MAX_DAYS).
     *
     * @return string[]
     */
    private static function each_day(string $from, string $to): array
    {
        if (!self::valid_date($from) || !self::valid_date($to) || $from > $to) {
            return array();
        }

        $days  = array();
        $count = min(self::days_between($from, $to), self::MAX_DAYS);
        for ($index = 0; $index < $count; $index++) {
            $days[] = self::shift_days($from, $index);
        }

        return $days;
    }

    /**
     * Round a money-like value to 6 decimals (storage precision).
     */
    private static function round6(float $value): float
    {
        return round($value, 6);
    }
}
