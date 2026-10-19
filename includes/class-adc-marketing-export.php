<?php
/**
 * CSV export of marketing consent (admin only).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Marketing export class
 */
class ADC_Marketing_Export
{
    /**
     * Plugin instance
     *
     * @var ADC_Marketing_Export|null
     */
    private static ?ADC_Marketing_Export $instance = null;

    /**
     * Get plugin instance
     *
     * @return ADC_Marketing_Export
     */
    public static function get_instance(): ADC_Marketing_Export
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void
    {
        add_action('admin_post_adc_export_consented_users', [$this, 'handle_export_consented_users']);
        add_action('admin_post_adc_export_non_consented_users', [$this, 'handle_export_non_consented_users']);
    }

    /**
     * Handle export of consented users
     */
    public function handle_export_consented_users(): void
    {
        check_admin_referer('adc_export_nonce', 'adc_export_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nu aveți permisiunea necesară.', 'adc-marketing'), '', ['response' => 403]);
        }

        $this->export_users('yes', $this->requested_date('adc_export_start_date'), $this->requested_date('adc_export_end_date'), 'utilizatori-consimtamant');
    }

    /**
     * Handle export of non-consented users
     */
    public function handle_export_non_consented_users(): void
    {
        check_admin_referer('adc_export_nonce', 'adc_export_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nu aveți permisiunea necesară.', 'adc-marketing'), '', ['response' => 403]);
        }

        $this->export_users('no', $this->requested_date('adc_export_start_date'), $this->requested_date('adc_export_end_date'), 'utilizatori-fara-consimtamant');
    }

    /**
     * Export users to CSV (semicolon-separated, UTF-8 with BOM).
     *
     * @param string $consent_status Consent status (yes/no)
     * @param string $start_date     Start date (Y-m-d) or ''
     * @param string $end_date       End date (Y-m-d) or ''
     * @param string $filename_prefix Filename prefix
     */
    private function export_users(string $consent_status, string $start_date, string $end_date, string $filename_prefix): void
    {
        $query_args = [
            'meta_key' => '_adc_marketing_consent',
            'meta_value' => $consent_status,
            'number' => -1,
        ];

        // Consent date range (site timezone, inclusive), applied to _adc_marketing_consent_date.
        $date_clauses = [];
        $start_ts = $this->day_timestamp($start_date, false);
        $end_ts = $this->day_timestamp($end_date, true);

        if (null !== $start_ts) {
            $date_clauses[] = [
                'key' => '_adc_marketing_consent_date',
                'value' => $start_ts,
                'compare' => '>=',
                'type' => 'NUMERIC',
            ];
        }

        if (null !== $end_ts) {
            $date_clauses[] = [
                'key' => '_adc_marketing_consent_date',
                'value' => $end_ts,
                'compare' => '<=',
                'type' => 'NUMERIC',
            ];
        }

        if ([] !== $date_clauses) {
            $query_args['meta_query'] = array_merge(['relation' => 'AND'], $date_clauses);
        }

        $users = get_users($query_args);

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment;filename=' . $filename_prefix . '-' . wp_date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // CSV headers
        fputcsv($output, ['Email', 'Telefon', 'Nume', 'Prenume'], ';');

        foreach ($users as $user) {
            $phone = (string) get_user_meta($user->ID, 'billing_phone', true);
            $first_name = (string) get_user_meta($user->ID, 'billing_first_name', true);
            $last_name = (string) get_user_meta($user->ID, 'billing_last_name', true);

            fputcsv($output, [
                $this->csv_cell($user->user_email),
                $this->csv_cell($phone),
                $this->csv_cell($last_name),
                $this->csv_cell($first_name),
            ], ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Read a Y-m-d date from the export link query string.
     *
     * @param string $key Query argument name.
     * @return string Valid Y-m-d date or ''.
     */
    private function requested_date(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked by check_admin_referer() in the caller.
        $value = isset($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : '';

        return $this->day_timestamp($value, false) === null ? '' : $value;
    }

    /**
     * Unix timestamp of the start (or end) of a Y-m-d day in the site timezone.
     *
     * @param string $date   Y-m-d date, or '' for no bound.
     * @param bool   $is_end True for 23:59:59 of that day.
     * @return int|null Timestamp, or null when no or an invalid date was given.
     */
    private function day_timestamp(string $date, bool $is_end): ?int
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            $day = new DateTimeImmutable($date . ($is_end ? ' 23:59:59' : ' 00:00:00'), wp_timezone());
        } catch (Exception $exception) {
            return null;
        }

        return $day->getTimestamp();
    }

    /**
     * Neutralise spreadsheet formula injection in a CSV cell.
     *
     * Covers the OWASP leading characters = + - @ TAB CR: phone numbers often
     * start with "+" and spreadsheets evaluate "+" and "-" as formulas too.
     *
     * @param string $value Cell value.
     * @return string
     */
    private function csv_cell(string $value): string
    {
        if ('' !== $value && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
