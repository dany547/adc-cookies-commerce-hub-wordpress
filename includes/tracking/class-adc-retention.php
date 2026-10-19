<?php
/**
 * Retention & privacy (docs/ARCHITECTURE.md §1.5, §6):
 * - daily cron `adc_outbox_cleanup`: purge sent rows after events.outbox_sent_days,
 *   dead/skipped rows after events.outbox_dead_days;
 * - order `_adc_attribution` meta removed 30 days after capture;
 * - WP privacy exporter + eraser for `_adc_attribution` on the user's orders.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Outbox purge, attribution expiry and privacy eraser.
 */
final class ADC_Retention implements ADC_Module_Interface
{
    /**
     * Daily cron hook (§2.5 hooks catalogue).
     */
    public const CRON_HOOK = 'adc_outbox_cleanup';

    /**
     * Single-order erasure hook (Action Scheduler / WP-Cron).
     */
    public const ERASE_HOOK = 'adc_erase_attribution';

    /**
     * Privacy exporter/eraser identifier.
     */
    public const PRIVACY_KEY = 'adc-marketing-attribution';

    /**
     * Boot the module: cron scheduling, per-order erasure hook, privacy tools.
     */
    public static function boot(): void
    {
        add_action(self::CRON_HOOK, array(self::class, 'run_cleanup'));
        add_action(self::ERASE_HOOK, array(self::class, 'erase_attribution'), 10, 1);

        add_action('init', array(self::class, 'schedule_cron'));
        add_action('woocommerce_new_order', array(self::class, 'schedule_attribution_erase'), 10, 1);

        add_filter('wp_privacy_personal_data_exporters', array(self::class, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(self::class, 'register_eraser'));
    }

    /**
     * Schedule the daily cleanup cron (idempotent).
     */
    public static function schedule_cron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Daily cleanup: outbox purge + expired attribution removal (sweep
     * covers orders whose scheduled erasure never ran, e.g. failed cron).
     */
    public static function run_cleanup(): void
    {
        $sent_days = max(1, (int) ADC_Settings::get('events', 'outbox_sent_days', 14));
        $dead_days = max(1, (int) ADC_Settings::get('events', 'outbox_dead_days', 30));

        $purged = ADC_Outbox::purge($sent_days, $dead_days);

        $erased = self::erase_expired_attribution();

        ADC_Logger::log('info', 'Retention cleanup finished.', array('outbox_purged' => $purged, 'attribution_erased' => $erased));
    }

    /**
     * Schedule the per-order attribution erasure (30 days after capture).
     * Public because ADC_Order_Context fires after order creation.
     *
     * @param int $order_id Order id.
     */
    public static function schedule_attribution_erase(int $order_id): void
    {
        if ($order_id <= 0) {
            return;
        }

        $when = time() + self::retention_window();

        if (class_exists('ActionScheduler')) {
            as_schedule_single_action($when, self::ERASE_HOOK, array('order_id' => $order_id), ADC_Dispatcher::GROUP);

            return;
        }

        wp_schedule_single_event($when, self::ERASE_HOOK, array('order_id' => $order_id));
    }

    /**
     * Erase `_adc_attribution` from one order (hook callback).
     *
     * @param int $order_id Order id.
     */
    public static function erase_attribution(int $order_id): void
    {
        $order = self::order($order_id);
        if (null === $order) {
            return;
        }

        if ('' === (string) $order->get_meta(ADC_Order_Context::META_KEY)) {
            return;
        }

        $order->delete_meta_data(ADC_Order_Context::META_KEY);
        $order->save_meta_data();
    }

    /**
     * Sweep: erase attribution from orders older than 30 days (HPOS-safe CRUD).
     *
     * @return int Erased orders.
     */
    private static function erase_expired_attribution(): int
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::retention_window());

        $orders = wc_get_orders(
            array(
                'limit'      => 100,
                'status'     => array('completed', 'processing', 'on-hold', 'cancelled', 'refunded', 'failed'),
                'date_created' => '<' . $cutoff,
                'return'     => 'objects',
            )
        );

        if (!is_array($orders)) {
            return 0;
        }

        $erased = 0;
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            if ('' === (string) $order->get_meta(ADC_Order_Context::META_KEY)) {
                continue;
            }

            $order->delete_meta_data(ADC_Order_Context::META_KEY);
            $order->save_meta_data();
            $erased++;
        }

        return $erased;
    }

    /**
     * Attribution retention window in seconds (§6: 30 days).
     *
     * @return int
     */
    private static function retention_window(): int
    {
        return ADC_Order_Context::RETENTION_DAYS * DAY_IN_SECONDS;
    }

    /* ---------------------------------------------------------------------
     * WP privacy tools (§6: exporter + eraser for _adc_attribution).
     * ------------------------------------------------------------------- */

    /**
     * Register the privacy exporter.
     *
     * @param array $exporters Existing exporters.
     * @return array
     */
    public static function register_exporter(array $exporters): array
    {
        $exporters[self::PRIVACY_KEY] = array(
            'exporter_friendly_name' => __('ADC Marketing — date de atribuire', 'adc-marketing'),
            'callback'               => array(self::class, 'export_data'),
        );

        return $exporters;
    }

    /**
     * Register the privacy eraser.
     *
     * @param array $erasers Existing erasers.
     * @return array
     */
    public static function register_eraser(array $erasers): array
    {
        $erasers[self::PRIVACY_KEY] = array(
            'eraser_friendly_name' => __('ADC Marketing — date de atribuire', 'adc-marketing'),
            'callback'             => array(self::class, 'erase_data'),
        );

        return $erasers;
    }

    /**
     * Orders belonging to the given user id.
     *
     * @param int $user_id User id.
     * @return int[] Order ids.
     */
    private static function order_ids_for_user(int $user_id): array
    {
        if ($user_id <= 0 || !function_exists('wc_get_orders')) {
            return array();
        }

        $orders = wc_get_orders(
            array(
                'limit'      => -1,
                'customer_id' => $user_id,
                'return'     => 'ids',
            )
        );

        return is_array($orders) ? array_map('intval', $orders) : array();
    }

    /**
     * Export attribution data for one user.
     *
     * @param string $email_address User e-mail.
     * @param int    $page          Pagination page.
     * @return array
     */
    public static function export_data(string $email_address, int $page = 1): array
    {
        $user = get_user_by('email', $email_address);
        if (!$user instanceof WP_User) {
            return array('data' => array(), 'done' => true);
        }

        $export_items = array();
        foreach (self::order_ids_for_user((int) $user->ID) as $order_id) {
            $order = self::order($order_id);
            if (null === $order) {
                continue;
            }

            $attr = ADC_Order_Context::read($order);
            if (array() === $attr) {
                continue;
            }

            $fields = array();
            foreach ($attr as $key => $value) {
                $fields[] = array('name' => $key, 'value' => $value);
            }

            $export_items[] = array(
                'group_id'    => 'adc_attribution',
                'group_label' => __('Atribuire marketing (comandă #' . $order_id . ')', 'adc-marketing'),
                'item_id'     => 'adc-attribution-' . $order_id,
                'data'        => $fields,
            );
        }

        return array(
            'data' => $export_items,
            'done' => true,
        );
    }

    /**
     * Erase attribution data for one user.
     *
     * @param string $email_address User e-mail.
     * @param int    $page          Pagination page.
     * @return array
     */
    public static function erase_data(string $email_address, int $page = 1): array
    {
        $user = get_user_by('email', $email_address);
        $removed = 0;
        $retained = array();

        if ($user instanceof WP_User) {
            foreach (self::order_ids_for_user((int) $user->ID) as $order_id) {
                $order = self::order($order_id);
                if (null === $order || '' === (string) $order->get_meta(ADC_Order_Context::META_KEY)) {
                    continue;
                }

                $order->delete_meta_data(ADC_Order_Context::META_KEY);
                $order->save_meta_data();
                $removed++;
            }
        }

        // Consent snapshots (_adc_consent_snapshot/_adc_consent_cid) are kept as
        // proof of consent — reported, not deleted (§6).
        if ($user instanceof WP_User) {
            $retained = array_map(
                static fn (int $id): string => __('Dovada consimțământului este păstrată pentru comanda #' . $id, 'adc-marketing'),
                self::order_ids_for_user((int) $user->ID)
            );
        }

        return array(
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => array(),
            'done'           => true,
        );
    }

    /**
     * HPOS-safe order lookup.
     *
     * @param int $order_id Order id.
     * @return WC_Order|null
     */
    private static function order(int $order_id): ?WC_Order
    {
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($order_id);

        return ($order instanceof WC_Order) ? $order : null;
    }
}
