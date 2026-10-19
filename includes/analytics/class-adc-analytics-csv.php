<?php
/**
 * Admin CSV export for operational analytics.
 *
 * One admin-post entry point, a strict report allowlist, `manage_options` plus
 * `adc_analytics_export_nonce`, the same filters and small-cell suppression as
 * the dashboard, and a formula-injection guard that also covers leading
 * whitespace before `= + - @` and TAB/CR. The CSV never contains an individual
 * identifier: no customer, order, e-mail, phone, IP, URL or user agent.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allowlisted analytics CSV export.
 */
final class ADC_Analytics_Csv implements ADC_Module_Interface
{
    /** Nonce action for the export link. */
    public const NONCE = 'adc_analytics_export_nonce';

    /** admin-post action. */
    public const ACTION = 'adc_analytics_export';

    /** Reports that may be exported. */
    private const REPORTS = array(
        'kpis',
        'traffic_series',
        'top_pages',
        'channel_distribution',
        'commerce_series',
        'top_products',
        'current_counts',
        'reconciliation',
    );

    /** Maximum rows written to one file. */
    private const MAX_ROWS = 500;

    /**
     * Register the export handler. Idempotent: identical hook registrations
     * are deduplicated by WordPress, and the guard keeps double boots cheap.
     */
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('admin_post_' . self::ACTION, array(self::class, 'handle_export'));
    }

    /**
     * Allowlisted report names.
     *
     * @return string[]
     */
    public static function allowed_reports(): array
    {
        return self::REPORTS;
    }

    /**
     * Handle `admin-post.php?action=adc_analytics_export`.
     *
     * Capital check first, then nonce, then the report allowlist. Every attempt
     * is audited with safe metadata only (report, range, currency, user ID).
     */
    public static function handle_export(): void
    {
        if (!current_user_can('manage_options')) {
            self::audit('warning', 'export_denied_capability');

            if (function_exists('wp_die')) {
                wp_die(
                    esc_html__('Nu aveți permisiunea necesară.', 'adc-marketing'),
                    '',
                    array('response' => 403)
                );
            }

            return;
        }

        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::NONCE);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified by check_admin_referer() above.
        $report   = isset($_GET['report']) ? sanitize_key(wp_unslash((string) $_GET['report'])) : '';
        $from     = isset($_GET['from']) ? sanitize_text_field(wp_unslash((string) $_GET['from'])) : '';
        $to       = isset($_GET['to']) ? sanitize_text_field(wp_unslash((string) $_GET['to'])) : '';
        $currency = isset($_GET['currency']) ? sanitize_text_field(wp_unslash((string) $_GET['currency'])) : '';
        $preset   = isset($_GET['preset']) ? sanitize_text_field(wp_unslash((string) $_GET['preset'])) : '';
        $page_type = isset($_GET['page_type']) ? sanitize_text_field(wp_unslash((string) $_GET['page_type'])) : '';
        $channel   = isset($_GET['channel']) ? sanitize_text_field(wp_unslash((string) $_GET['channel'])) : '';
        $product   = isset($_GET['product_id']) ? (int) wp_unslash((string) $_GET['product_id']) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Currency parity with the dashboard: an empty filter means the shop
        // currency; an explicit INVALID code is rejected (never zero-filled).
        $currency = self::effective_currency($currency);
        if ('' === $currency) {
            self::audit('warning', 'export_denied_invalid_currency', array('report' => self::safe_text($report, 32)));

            if (function_exists('wp_die')) {
                wp_die(
                    esc_html__('Monedă lipsă sau invalidă pentru export.', 'adc-marketing'),
                    '',
                    array('response' => 400)
                );
            }

            return;
        }

        if (!in_array($report, self::REPORTS, true)) {
            self::audit('warning', 'export_denied_unknown_report', array('report' => self::safe_text($report, 32)));

            if (function_exists('wp_die')) {
                wp_die(
                    esc_html__('Raport necunoscut.', 'adc-marketing'),
                    '',
                    array('response' => 400)
                );
            }

            return;
        }

        $args = array(
            'preset'     => ($preset !== '') ? $preset : 'custom',
            'from'       => $from,
            'to'         => $to,
            'currency'   => $currency,
            'page_type'  => $page_type,
            'channel'    => $channel,
            'product_id' => $product,
        );

        $range = ADC_Analytics_Reports::range((string) $args['preset'], $from, $to);
        self::audit('info', 'export', array(
            'report'   => $report,
            'from'     => (string) $range['from'],
            'to'       => (string) $range['to'],
            'currency' => self::safe_text(strtoupper($currency), 3),
        ));

        self::stream($report, $args);
    }

    /**
     * Write one report as CSV to the current output.
     *
     * Kept separate from handle_export() so it can be exercised without HTTP.
     *
     * @param string              $report Allowlisted report key.
     * @param array<string,mixed> $args   preset, from, to, currency, page_type, channel, product_id, limit.
     */
    public static function stream(string $report, array $args): void
    {
        if (!in_array($report, self::REPORTS, true)) {
            return;
        }

        $built = self::build($report, $args);

        if (function_exists('header') && !headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment;filename=' . $built['filename']);
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-Content-Type-Options: nosniff');
        }

        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            return;
        }

        // UTF-8 BOM so Excel opens Romanian diacritics correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_map(array(self::class, 'cell'), $built['header']), ';', '"', '');

        $written = 0;
        foreach ($built['rows'] as $row) {
            if ($written >= self::MAX_ROWS) {
                break;
            }
            $cells = array();
            foreach ($row as $value) {
                $cells[] = self::cell(self::stringify($value));
            }
            fputcsv($handle, $cells, ';', '"', '');
            $written++;
        }

        fclose($handle);
    }

    /**
     * Build a report table (header, rows, filename) without any output.
     *
     * @param string              $report Allowlisted report key.
     * @param array<string,mixed> $args   See self::stream().
     * @return array{filename:string,header:string[],rows:array<int,string[]>,report:string,from:string,to:string}
     */
    public static function build(string $report, array $args): array
    {
        $preset  = isset($args['preset']) ? (string) $args['preset'] : 'custom';
        $from    = isset($args['from']) ? (string) $args['from'] : '';
        $to      = isset($args['to']) ? (string) $args['to'] : '';
        $currency = self::effective_currency(strtoupper(isset($args['currency']) ? (string) $args['currency'] : ''));
        $limit   = isset($args['limit']) ? (int) $args['limit'] : 50;
        $limit   = max(1, min(self::MAX_ROWS, $limit));
        $range   = ADC_Analytics_Reports::range($preset, $from, $to);
        $filters = array();
        foreach (array('page_type', 'channel', 'product_id') as $key) {
            if (isset($args[$key]) && $args[$key] !== '' && $args[$key] !== 0) {
                $filters[$key] = $args[$key];
            }
        }

        $table = array('header' => array(), 'rows' => array());

        if ('' === $currency) {
            // Explicit invalid currency: rejected context — one reason row, never
            // fabricated zero values.
            return array(
                'filename' => sprintf('analytics-%s-invalid-currency.csv', self::safe_text($report, 32)),
                'header'   => array(self::cell(__('Eroare', 'adc-marketing'))),
                'rows'     => array(array(self::cell(__('Monedă lipsă sau invalidă pentru export.', 'adc-marketing')))),
                'report'   => $report,
                'from'     => (string) $range['from'],
                'to'       => (string) $range['to'],
            );
        }

        switch ($report) {
            case 'kpis':
                $table = self::table_kpis($range, $currency, $filters);
                break;

            case 'traffic_series':
                $table = self::table_traffic_series($range, $filters);
                break;

            case 'top_pages':
                $table = self::table_top_pages($range, $filters, $limit);
                break;

            case 'channel_distribution':
                $table = self::table_channels($range, $filters);
                break;

            case 'commerce_series':
                $table = self::table_commerce_series($range, $currency);
                break;

            case 'top_products':
                $table = self::table_top_products($range, $currency, $limit, $filters);
                break;

            case 'current_counts':
                $table = self::table_current_counts();
                break;

            case 'reconciliation':
                $table = self::table_reconciliation($range, $currency);
                break;
        }

        $filename = sprintf('analytics-%s-%s-%s.csv', $report, (string) $range['from'], (string) $range['to']);

        $rows = array();
        foreach ($table['rows'] as $row) {
            $cells = array();
            foreach ((array) $row as $value) {
                $cells[] = self::cell(self::stringify($value));
            }
            $rows[] = $cells;
        }

        return array(
            'filename' => $filename,
            'header'   => array_map(array(self::class, 'cell'), $table['header']),
            'rows'     => $rows,
            'report'   => $report,
            'from'     => (string) $range['from'],
            'to'       => (string) $range['to'],
        );
    }

    /**
     * Effective export currency: empty means the shop currency; a non-empty
     * code is kept only when it is a clean 3-letter ISO shape ('' otherwise,
     * which callers reject instead of fabricating zero-filled tables).
     */
    private static function effective_currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if ('' === $currency && function_exists('get_woocommerce_currency')) {
            $currency = strtoupper(trim((string) get_woocommerce_currency()));
        }

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : '';
    }

    /**
     * admin-post URL for one report, with its nonce.
     *
     * @param string              $report Allowlisted report key.
     * @param array<string,mixed> $args   Extra query arguments (from, to, currency, page_type, channel, product_id).
     */
    public static function export_url(string $report, array $args = array()): string
    {
        $query = array('action' => self::ACTION, 'report' => $report);
        foreach (array('from', 'to', 'currency', 'page_type', 'channel', 'product_id', 'preset') as $key) {
            if (isset($args[$key]) && $args[$key] !== '' && $args[$key] !== 0) {
                $query[$key] = (string) $args[$key];
            }
        }

        if (!function_exists('admin_url')) {
            return '';
        }

        $url = add_query_arg($query, admin_url('admin-post.php'));

        return function_exists('wp_nonce_url') ? wp_nonce_url($url, self::NONCE) : $url;
    }

    /**
     * Neutralise spreadsheet formula injection and line breaks in one cell.
     *
     * Values whose first non-whitespace character is `= + - @` (or TAB/CR) are
     * prefixed with an apostrophe, which spreadsheets treat as literal text.
     * This also covers the common bypass ` =1+1`, `\t=1` and `\r-1`.
     *
     * @param string $value Raw cell value.
     */
    public static function cell(string $value): string
    {
        $guard = preg_match('/^[ \t\r\n]*[=+\-@\t\r]/', $value) === 1;

        $clean = (string) preg_replace('/[\r\n\0]+/u', ' ', $value);
        $clean = trim($clean);
        $clean = preg_match('/^.{0,500}/su', $clean, $matches) === 1 ? $matches[0] : '';

        return $guard ? "'" . $clean : $clean;
    }

    /**
     * KPI table (indicator / value pairs, explicitly labelled).
     *
     * @param array<string,mixed> $range    Reporting range.
     * @param string              $currency Currency code.
     * @param array<string,mixed> $filters  Dashboard filters (traffic card only).
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_kpis(array $range, string $currency, array $filters = array()): array
    {
        $kpis = ADC_Analytics_Reports::kpis($range, $currency, $filters);
        $rows = array(
            array(__('Interval început', 'adc-marketing'), (string) $kpis['from']),
            array(__('Interval sfârșit', 'adc-marketing'), (string) $kpis['to']),
            array(__('Monedă', 'adc-marketing'), (string) $kpis['currency']),
            array(__('Comenzi create', 'adc-marketing'), (string) (int) $kpis['orders_created']),
            array(__('Comenzi plătite', 'adc-marketing'), (string) (int) $kpis['orders_paid']),
            array(__('Venit brut', 'adc-marketing'), self::num((float) $kpis['gross_revenue'], 2)),
            array(__('Rambursări', 'adc-marketing'), self::num((float) $kpis['refunds'], 2)),
            array(__('Venit net', 'adc-marketing'), self::num((float) $kpis['net_revenue'], 2)),
            array(__('TVA', 'adc-marketing'), self::num((float) $kpis['tax'], 2)),
            array(__('Livrare', 'adc-marketing'), self::num((float) $kpis['shipping'], 2)),
            array(__('Reduceri', 'adc-marketing'), self::num((float) $kpis['discounts'], 2)),
            array(__('Valoare medie comandă', 'adc-marketing'), $kpis['aov'] === null ? '' : self::num((float) $kpis['aov'], 2)),
            array(__('Rata rambursărilor', 'adc-marketing'), $kpis['refund_rate'] === null ? '' : self::num((float) $kpis['refund_rate'] * 100, 2) . '%'),
            array(__('Cereri de pagină estimate', 'adc-marketing'), $kpis['traffic_views'] === null ? (string) __('indisponibil', 'adc-marketing') : (string) (int) $kpis['traffic_views']),
            array(__('Evaluare eșantion mic', 'adc-marketing'), $kpis['small_sample'] ? __('da', 'adc-marketing') : __('nu', 'adc-marketing')),
        );

        if (is_array($kpis['comparison']) && ($kpis['comparison']['available'] ?? false) === true) {
            $previous = (array) $kpis['comparison']['previous'];
            $rows[]   = array(__('Perioada anterioară: venit brut', 'adc-marketing'), self::num((float) $previous['gross_revenue'], 2));
            $rows[]   = array(__('Perioada anterioară: comenzi plătite', 'adc-marketing'), (string) (int) $previous['orders_paid']);
        } else {
            $rows[] = array(__('Perioada anterioară', 'adc-marketing'), __('indisponibil', 'adc-marketing'));
        }

        return array(
            'header' => array(__('Indicator', 'adc-marketing'), __('Valoare', 'adc-marketing')),
            'rows'   => $rows,
        );
    }

    /**
     * Traffic series table.
     *
     * @param array<string,mixed> $range   Reporting range.
     * @param array<string,mixed> $filters Report filters.
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_traffic_series(array $range, array $filters): array
    {
        $report = ADC_Analytics_Reports::traffic_series($range, $filters);
        $header = array(
            __('Data', 'adc-marketing'),
            __('Cereri de pagină estimate', 'adc-marketing'),
            __('Notă', 'adc-marketing'),
        );
        $rows = array(self::note_row($report, 3));

        foreach ((array) $report['rows'] as $row) {
            $rows[] = array(
                (string) $row['date'],
                $row['views'] === null ? '' : (string) (int) $row['views'],
                $row['suppressed'] ? __('eșantion mic', 'adc-marketing') : '',
            );
        }

        return array('header' => $header, 'rows' => $rows);
    }

    /**
     * Top pages table (internal identifiers only, no titles or URLs).
     *
     * @param array<string,mixed> $range   Reporting range.
     * @param array<string,mixed> $filters Report filters.
     * @param int                 $limit   Row limit.
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_top_pages(array $range, array $filters, int $limit): array
    {
        $report = ADC_Analytics_Reports::top_pages($range, $filters, $limit);
        $header = array(
            __('Tip pagină', 'adc-marketing'),
            __('ID obiect', 'adc-marketing'),
            __('Etichetă', 'adc-marketing'),
            __('Cereri de pagină estimate', 'adc-marketing'),
            __('Notă', 'adc-marketing'),
        );
        $rows = array(self::note_row($report, 5));

        foreach ((array) $report['rows'] as $row) {
            $rows[] = array(
                (string) $row['page_type'],
                (string) (int) $row['object_id'],
                (string) $row['label'],
                $row['views'] === null ? '' : (string) (int) $row['views'],
                $row['suppressed'] ? __('eșantion mic', 'adc-marketing') : '',
            );
        }

        return array('header' => $header, 'rows' => $rows);
    }

    /**
     * Channel distribution table.
     *
     * @param array<string,mixed> $range   Reporting range.
     * @param array<string,mixed> $filters Report filters.
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_channels(array $range, array $filters): array
    {
        $report = ADC_Analytics_Reports::channel_distribution($range, $filters);
        $header = array(
            __('Canal', 'adc-marketing'),
            __('Cereri de pagină estimate', 'adc-marketing'),
            __('Pondere', 'adc-marketing'),
            __('Notă', 'adc-marketing'),
        );
        $rows = array(self::note_row($report, 4));

        foreach ((array) $report['rows'] as $row) {
            $rows[] = array(
                (string) $row['label'],
                $row['views'] === null ? '' : (string) (int) $row['views'],
                $row['share'] === null ? '' : self::num((float) $row['share'] * 100, 2) . '%',
                $row['suppressed'] ? __('eșantion mic', 'adc-marketing') : '',
            );
        }

        return array('header' => $header, 'rows' => $rows);
    }

    /**
     * Commerce series table for one currency.
     *
     * @param array<string,mixed> $range    Reporting range.
     * @param string              $currency Currency code.
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_commerce_series(array $range, string $currency): array
    {
        $report = ADC_Analytics_Reports::commerce_series($range, $currency);
        $header = array(
            __('Data', 'adc-marketing'),
            __('Monedă', 'adc-marketing'),
            __('Comenzi create', 'adc-marketing'),
            __('Comenzi plătite', 'adc-marketing'),
            __('Venit brut', 'adc-marketing'),
            __('Rambursări', 'adc-marketing'),
            __('Venit net', 'adc-marketing'),
            __('Notă', 'adc-marketing'),
        );
        $rows = array(self::note_row($report, 8));

        foreach ((array) $report['rows'] as $row) {
            $rows[] = array(
                (string) $row['date'],
                (string) $report['currency'],
                $row['orders_created'] === null ? '' : (string) (int) $row['orders_created'],
                $row['orders_paid'] === null ? '' : (string) (int) $row['orders_paid'],
                $row['gross_revenue'] === null ? '' : self::num((float) $row['gross_revenue'], 2),
                $row['refunds'] === null ? '' : self::num((float) $row['refunds'], 2),
                $row['net_revenue'] === null ? '' : self::num((float) $row['net_revenue'], 2),
                $row['suppressed'] ? __('eșantion mic', 'adc-marketing') : '',
            );
        }

        $totals = $report['totals'];
        if (is_array($totals)) {
            $rows[] = array(
                __('Total', 'adc-marketing'),
                (string) $report['currency'],
                (string) (int) $totals['orders_created'],
                (string) (int) $totals['orders_paid'],
                self::num((float) $totals['gross_revenue'], 2),
                self::num((float) $totals['refunds'], 2),
                self::num((float) $totals['net_revenue'], 2),
                '',
            );
        } elseif ($report['available']) {
            $rows[] = array(
                __('Total', 'adc-marketing'),
                (string) $report['currency'],
                '', '', '', '', '',
                __('ascuns: eșantion mic', 'adc-marketing'),
            );
        }

        return array('header' => $header, 'rows' => $rows);
    }

    /**
     * Top products table for one currency (product IDs only, no titles).
     *
     * @param array<string,mixed> $range    Reporting range.
     * @param string              $currency Currency code.
     * @param int                 $limit    Row limit.
     * @param array<string,mixed> $filters  Dashboard filters (product_id only).
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_top_products(array $range, string $currency, int $limit, array $filters = array()): array
    {
        $report = ADC_Analytics_Reports::top_products($range, $currency, $limit, $filters);
        $header = array(
            __('ID produs', 'adc-marketing'),
            __('Etichetă', 'adc-marketing'),
            __('Monedă', 'adc-marketing'),
            __('Unități', 'adc-marketing'),
            __('Valoare netă', 'adc-marketing'),
            __('TVA', 'adc-marketing'),
            __('Unități rambursate', 'adc-marketing'),
            __('Valoare rambursată', 'adc-marketing'),
            __('Notă', 'adc-marketing'),
        );
        $rows = array(self::note_row($report, 9));

        foreach ((array) $report['rows'] as $row) {
            $rows[] = array(
                (string) (int) $row['product_id'],
                (string) $row['label'],
                (string) $report['currency'],
                $row['units'] === null ? '' : (string) (int) $row['units'],
                $row['net'] === null ? '' : self::num((float) $row['net'], 2),
                $row['tax'] === null ? '' : self::num((float) $row['tax'], 2),
                $row['refund_units'] === null ? '' : (string) (int) $row['refund_units'],
                $row['refund_net'] === null ? '' : self::num((float) $row['refund_net'], 2),
                $row['suppressed'] ? __('eșantion mic', 'adc-marketing') : '',
            );
        }

        return array('header' => $header, 'rows' => $rows);
    }

    /**
     * Live current-state order counts table.
     *
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_current_counts(): array
    {
        $report = ADC_Analytics_Reports::current_order_counts();
        $header = array(
            __('Status comandă', 'adc-marketing'),
            __('Comenzi', 'adc-marketing'),
        );
        $rows = array();

        if (($report['available'] ?? false) !== true) {
            $rows[] = array(__('indisponibil', 'adc-marketing'), '');

            return array('header' => $header, 'rows' => $rows);
        }

        foreach ((array) $report['counts'] as $status => $count) {
            $rows[] = array((string) $status, (string) (int) $count);
        }
        $rows[] = array(__('Total', 'adc-marketing'), (string) (int) $report['total']);

        return array('header' => $header, 'rows' => $rows);
    }

    /**
     * Reconciliation table (aggregate vs orders, per day).
     *
     * @param array<string,mixed> $range    Reporting range.
     * @param string              $currency Currency code.
     * @return array{header:string[],rows:array<int,string[]>}
     */
    private static function table_reconciliation(array $range, string $currency): array
    {
        $report = ADC_Analytics_Reports::reconciliation($range, $currency);
        $header = array(
            __('Data', 'adc-marketing'),
            __('Diferență', 'adc-marketing'),
            __('Avertisment', 'adc-marketing'),
        );
        $rows = array();

        if (($report['available'] ?? false) !== true) {
            $rows[] = array((string) __('indisponibil', 'adc-marketing'), '', (string) ($report['reason'] ?? ''));

            return array('header' => $header, 'rows' => $rows);
        }

        foreach ((array) $report['days'] as $day) {
            $max = 0.0;
            foreach ((array) ($day['diff'] ?? array()) as $value) {
                $max = max($max, abs((float) $value));
            }
            $rows[] = array(
                (string) $day['date'],
                self::num($max, 2),
                $day['warn'] ? __('atenție', 'adc-marketing') : '',
            );
        }

        return array('header' => $header, 'rows' => $rows);
    }

    /**
     * First CSV row explains why a breakdown is empty or partial. It carries no
     * numbers, so it cannot be used to difference suppressed cells.
     *
     * @param array<string,mixed> $report  Breakdown envelope.
     * @param int                 $columns Target column count.
     * @return string[]
     */
    private static function note_row(array $report, int $columns): array
    {
        $notes = array_filter(array(
            $report['reason'] ?? '',
            ($report['truncated'] ?? false) ? __('Raport trunchiat la limita de rânduri.', 'adc-marketing') : '',
            ($report['total_suppressed'] ?? false) ? __('Totalul este ascuns din cauza eșantionului mic.', 'adc-marketing') : '',
        ), static fn($note): bool => (string) $note !== '');

        $row   = array(__('Notă', 'adc-marketing'));
        $row[] = $notes !== array() ? implode(' ', array_map('strval', $notes)) : '';
        while (count($row) < max(2, $columns)) {
            $row[] = '';
        }

        return $row;
    }

    /**
     * Format a number for the Romanian CSV (comma decimal, no thousands).
     */
    private static function num(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '');
    }

    /**
     * Convert a mixed report value into a scalar string (no arrays, no objects).
     */
    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::num($value, 6);
        }
        if (is_string($value)) {
            return $value;
        }

        return '';
    }

    /**
     * Truncate/purge a value before it is written to the audit log.
     */
    private static function safe_text(string $value, int $max): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_\-\.]/', '', $value);

        return substr($value, 0, $max);
    }

    /**
     * Audit one export attempt with safe, non-PII metadata.
     *
     * @param string              $level   error|warning|info|debug.
     * @param string              $event   Event key.
     * @param array<string,mixed> $context Extra safe context.
     */
    private static function audit(string $level, string $event, array $context = array()): void
    {
        if (!class_exists('ADC_Logger')) {
            return;
        }

        $context['event']   = $event;
        $context['user_id'] = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        ADC_Logger::log($level, 'Analytics export: ' . $event, $context);
    }
}
