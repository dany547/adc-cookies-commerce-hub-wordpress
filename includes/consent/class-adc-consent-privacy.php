<?php
/**
 * WP privacy exporter / eraser for consent data + retention cron
 * (docs/ARCHITECTURE.md §1.2, §6; D18).
 *
 * Exports/erases: adc_consent_log rows (by user), the _adc_cookie_consent
 * user meta and the order snapshot metas (_adc_consent_snapshot,
 * _adc_consent_cid) of the user's orders. Channel consents (_adc_marketing_*)
 * belong to the legacy GDPR class (C8); attribution erasure belongs to
 * ADC_Retention (C5). Consent log rows are purged after
 * `consent.log_retention_months` (default 36 — a configurable policy, U4).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consent privacy tools + retention.
 */
final class ADC_Consent_Privacy implements ADC_Module_Interface
{
    /**
     * Exporter key (unique among WP exporters).
     */
    public const EXPORTER_KEY = 'adc-marketing-consent';

    /**
     * Eraser key.
     */
    public const ERASER_KEY = 'adc-marketing-consent';

    /**
     * User meta holding the cookie consent state (JSON of state->to_array()).
     */
    public const USER_META = '_adc_cookie_consent';

    /**
     * Cron hook: daily consent-log purge.
     */
    public const CRON_HOOK = 'adc_consent_cleanup';

    /**
     * Boot the module: privacy tools, delete_user anonymisation, cron.
     */
    public static function boot(): void
    {
        add_filter('wp_privacy_personal_data_exporters', array(self::class, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(self::class, 'register_eraser'));

        // Log rows of a deleted user are anonymised (user_id=0), cid kept as
        // proof (docs/ARCHITECTURE.md §6). The legacy delete_user handler in
        // ADC_GDPR_Compliance keeps handling 1.0 behaviour.
        add_action('delete_user', array(self::class, 'anonymize_on_user_delete'));

        add_action(self::CRON_HOOK, array(self::class, 'run_retention'));
        self::schedule_cron();

        // WP Consent API (D17): list our own record cookie so other plugins
        // can show it; harmless no-op when the API plugin is not active.
        add_action('init', array(self::class, 'register_cookie_info'), 10);
    }

    /**
     * Add our exporter to the WP privacy tools registry.
     *
     * @param array $exporters Registered exporters.
     * @return array
     */
    public static function register_exporter(array $exporters): array
    {
        $exporters[self::EXPORTER_KEY] = array(
            'exporter_friendly_name' => __('ADC Marketing — consimțământ cookie', 'adc-marketing'),
            'callback'               => array(self::class, 'export_personal_data'),
        );

        return $exporters;
    }

    /**
     * Add our eraser to the WP privacy tools registry.
     *
     * @param array $erasers Registered erasers.
     * @return array
     */
    public static function register_eraser(array $erasers): array
    {
        $erasers[self::ERASER_KEY] = array(
            'eraser_friendly_name' => __('ADC Marketing — consimțământ cookie', 'adc-marketing'),
            'callback'             => array(self::class, 'erase_personal_data'),
        );

        return $erasers;
    }

    /**
     * Privacy exporter callback: consent log rows (with the exact banner text
     * shown, resolved from the `adc_consent_texts` option maintained by C3,
     * D18) + cookie-consent meta + order snapshots.
     *
     * @param string $email_address Requested e-mail.
     * @param int    $page          1-based page number.
     * @return array{data: array, items: array, done: bool}
     */
    public static function export_personal_data(string $email_address, int $page = 1): array
    {
        $data  = array();
        $items = array();
        $user  = get_user_by('email', sanitize_email($email_address));

        if ($user instanceof WP_User) {
            $user_id = (int) $user->ID;

            $meta_raw = get_user_meta($user_id, self::USER_META, true);
            if (is_string($meta_raw) && '' !== $meta_raw) {
                $decoded = json_decode($meta_raw, true);
                $data[]  = array(
                    'name'  => __('Preferințe cookie (ultima decizie)', 'adc-marketing'),
                    'value' => is_array($decoded) ? wp_json_encode($decoded, JSON_PRETTY_PRINT) : $meta_raw,
                );
            }

            foreach (ADC_Consent_Store::for_user($user_id, 500) as $row) {
                $items[] = array(
                    'group_id'    => 'adc-consent-log',
                    'group_label' => __('Jurnal consimțământ cookie', 'adc-marketing'),
                    'item_id'     => 'adc-consent-log-' . (int) ($row['id'] ?? 0),
                    'data'        => array(
                        array(
                            'name' => __('Data deciziei (UTC)', 'adc-marketing'),
                            'value' => (string) ($row['created_at'] ?? ''),
                        ),
                        array(
                            'name'  => __('Acțiune', 'adc-marketing'),
                            'value' => (string) ($row['action'] ?? ''),
                        ),
                        array(
                            'name'  => __('Categorii acordate', 'adc-marketing'),
                            'value' => sprintf(
                                'preferences=%d, statistics=%d, marketing=%d',
                                (int) ($row['cat_preferences'] ?? 0),
                                (int) ($row['cat_statistics'] ?? 0),
                                (int) ($row['cat_marketing'] ?? 0)
                            ),
                        ),
                        array(
                            'name'  => __('Versiune politică', 'adc-marketing'),
                            'value' => (string) ($row['policy_version'] ?? ''),
                        ),
                        array(
                            'name'  => __('Sursă', 'adc-marketing'),
                            'value' => (string) ($row['source'] ?? ''),
                        ),
                        array(
                            'name'  => __('Identificator consimțământ (cid)', 'adc-marketing'),
                            'value' => (string) ($row['cid'] ?? ''),
                        ),
                        array(
                            'name'  => __('Text afișat (hash)', 'adc-marketing'),
                            'value' => (string) ($row['text_hash'] ?? ''),
                        ),
                        array(
                            'name'  => __('Text afișat', 'adc-marketing'),
                            'value' => self::resolve_text_hash((string) ($row['text_hash'] ?? '')),
                        ),
                    ),
                );
            }

            foreach (self::order_ids_for_user($user_id) as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order instanceof WC_Order) {
                    continue;
                }

                $snapshot = (string) $order->get_meta('_adc_consent_snapshot', true);
                $cid      = (string) $order->get_meta('_adc_consent_cid', true);
                if ('' === $snapshot && '' === $cid) {
                    continue;
                }

                $items[] = array(
                    'group_id'    => 'adc-consent-orders',
                    'group_label' => __('Consimțământ la finalizarea comenzii', 'adc-marketing'),
                    'item_id'     => 'adc-consent-order-' . $order_id,
                    'data'        => array(
                        array(
                            'name'  => __('Comandă', 'adc-marketing'),
                            'value' => '#' . $order_id,
                        ),
                        array(
                            'name'  => __('Snapshot consimțământ', 'adc-marketing'),
                            'value' => $snapshot,
                        ),
                        array(
                            'name'  => __('Identificator consimțământ (cid)', 'adc-marketing'),
                            'value' => $cid,
                        ),
                    ),
                );
            }
        }

        return array(
            'data'  => $data,
            'items' => $items,
            'done'  => true,
        );
    }

    /**
     * Privacy eraser callback: deletes consent log rows, the cookie-consent
     * meta and the order snapshot metas (orders themselves are untouched).
     *
     * @param string $email_address Requested e-mail.
     * @param int    $page          1-based page number.
     * @return array{items_removed: int, items_retained: int, done: bool}
     */
    public static function erase_personal_data(string $email_address, int $page = 1): array
    {
        $removed = 0;
        $user    = get_user_by('email', sanitize_email($email_address));

        if ($user instanceof WP_User) {
            $user_id = (int) $user->ID;

            $removed += ADC_Consent_Store::delete_for_user($user_id);

            if (metadata_exists('user', $user_id, self::USER_META)) {
                delete_user_meta($user_id, self::USER_META);
                $removed++;
            }

            foreach (self::order_ids_for_user($user_id) as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order instanceof WC_Order) {
                    continue;
                }

                $changed = false;
                foreach (array('_adc_consent_snapshot', '_adc_consent_cid') as $key) {
                    if ('' !== (string) $order->get_meta($key, true)) {
                        $order->delete_meta_data($key);
                        $changed  = true;
                        $removed++;
                    }
                }
                if ($changed) {
                    $order->save();
                }
            }
        }

        return array(
            'items_removed'  => $removed,
            'items_retained' => 0,
            'done'           => true,
        );
    }

    /**
     * Anonymise consent log rows when a user is deleted (user_id=0).
     *
     * @param int $user_id Deleted user id.
     */
    public static function anonymize_on_user_delete(int $user_id): void
    {
        ADC_Consent_Store::anonymize_user($user_id);
    }

    /**
     * Daily cron: purge consent log rows past the retention window.
     */
    public static function run_retention(): void
    {
        $months = (int) ADC_Settings::get('consent', 'log_retention_months', 36);
        ADC_Consent_Store::purge($months);
    }

    /**
     * Register the plugin's own record cookie with the WP Consent API (D17),
     * category 'functional'.
     */
    public static function register_cookie_info(): void
    {
        if (!function_exists('wp_add_cookie_info')) {
            return;
        }

        wp_add_cookie_info(
            'adc_consent',
            'adc-marketing',
            'functional',
            __('Cookie înregistrare decizie', 'adc-marketing'),
            __('Stochează alegerea vizitatorului privind categoriile de cookie-uri.', 'adc-marketing')
        );
    }

    /**
     * Resolve a log row's text_hash to the exact banner text shown (D18).
     * The `adc_consent_texts` option (hash => {ts,policy,texts{…}}) is
     * append-only and maintained by the banner module (C3).
     *
     * @param string $hash sha256 hex of the rendered banner text set.
     * @return string Human-readable text or ''.
     */
    private static function resolve_text_hash(string $hash): string
    {
        if (64 !== strlen($hash)) {
            return '';
        }

        $texts = get_option('adc_consent_texts', array());
        if (!is_array($texts) || !isset($texts[$hash]) || !is_array($texts[$hash])) {
            return '';
        }

        $entry  = $texts[$hash];
        $lines  = array();
        $detail = is_array($entry['texts'] ?? null) ? $entry['texts'] : (is_array($entry) ? $entry : array());

        foreach ($detail as $key => $value) {
            if (is_string($value) && '' !== $value) {
                $lines[] = $key . ': ' . $value;
            }
        }

        return substr(implode(' | ', $lines), 0, 1000);
    }

    /**
     * Order ids of a user (customer id + billing e-mail fallback; HPOS-safe).
     *
     * @param int $user_id User id.
     * @return int[]
     */
    private static function order_ids_for_user(int $user_id): array
    {
        if (!function_exists('wc_get_orders')) {
            return array();
        }

        $orders = wc_get_orders(
            array(
                'customer_id' => $user_id,
                'limit'       => 500,
                'return'      => 'ids',
                'orderby'     => 'ID',
                'order'       => 'DESC',
            )
        );

        return is_array($orders) ? array_map('intval', $orders) : array();
    }

    /**
     * Schedule the daily retention cron when missing (idempotent).
     */
    private static function schedule_cron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }
}
