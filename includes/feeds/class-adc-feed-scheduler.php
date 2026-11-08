<?php
/** Feed generation scheduling and product-change hooks. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Routes queued generation work and debounces WooCommerce product changes. */
final class ADC_Feed_Scheduler
{
    public const JOB_HOOK = 'adc_feed_generate';
    public const REFRESH_HOOK = 'adc_feed_scheduled_refresh';
    private const REFRESH_INTERVALS = array('hourly' => 3600, 'daily' => 86400, 'weekly' => 604800);

    /** Register scheduler handlers and product-change signals. */
    public static function boot(): void
    {
        add_action(self::JOB_HOOK, array(self::class, 'generate'), 10, 1);
        add_action(self::REFRESH_HOOK, array(self::class, 'refresh'), 10, 1);
        add_action('save_post_product', array(self::class, 'on_product_saved'), 20, 3);
        add_action('save_post_product_variation', array(self::class, 'on_product_saved'), 20, 3);
        add_action('woocommerce_product_set_stock', array(self::class, 'on_stock_changed'), 20, 1);
        add_action('woocommerce_variation_set_stock', array(self::class, 'on_stock_changed'), 20, 1);
        add_action('woocommerce_product_object_updated_props', array(self::class, 'on_product_updated'), 20, 2);
    }

    /** Action Scheduler / WP-Cron job entry point. */
    public static function generate(int $feed_id): void
    {
        ADC_Feed_Generator::run($feed_id);
    }

    /** Run a recurring refresh only for a feed that is still active. */
    public static function refresh(int $feed_id): void
    {
        $feed = ADC_Feed_Store::get($feed_id);
        if (is_array($feed) && 'active' === ($feed['status'] ?? '')) {
            ADC_Feed_Generator::run($feed_id);
        }
    }

    /** Cancel every feed refresh when the plugin is deactivated. */
    public static function cancel_all_refreshes(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::REFRESH_HOOK, null, ADC_Feed_Module::AS_GROUP);
        }
    }

    /** Rebuild recurring refreshes from the currently active feed settings. */
    public static function rebuild_active_refreshes(): void
    {
        foreach (ADC_Feed_Store::all() as $feed) {
            if ('active' === ($feed['status'] ?? '')) {
                self::sync_refresh((int) ($feed['id'] ?? 0), 'active', (string) ($feed['config']['refresh'] ?? 'manual'));
            }
        }
    }

    /** Keep at most one recurring Action Scheduler job per feed. */
    public static function sync_refresh(int $feed_id, string $status, string $frequency): bool
    {
        if ($feed_id <= 0) {
            return false;
        }
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::REFRESH_HOOK, array($feed_id), ADC_Feed_Module::AS_GROUP);
        }
        if ('disabled' === $status || 'manual' === $frequency) {
            return true;
        }
        if (!isset(self::REFRESH_INTERVALS[$frequency]) || !function_exists('as_schedule_recurring_action')) {
            return false;
        }
        $interval = (int) self::REFRESH_INTERVALS[$frequency];
        $action_id = as_schedule_recurring_action(
            time() + $interval,
            $interval,
            self::REFRESH_HOOK,
            array($feed_id),
            ADC_Feed_Module::AS_GROUP,
            true
        );
        if (is_numeric($action_id) && (int) $action_id > 0) {
            return true;
        }
        return function_exists('as_has_scheduled_action')
            && (bool) as_has_scheduled_action(self::REFRESH_HOOK, array($feed_id), ADC_Feed_Module::AS_GROUP);
    }

    /** Mark active feed snapshots stale after a product or variation is saved. */
    public static function on_product_saved(int $post_id, mixed $post = null, bool $update = false): void
    {
        unset($update);
        if ($post_id <= 0 || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }
        self::request_active_feeds();
    }

    /** Mark snapshots stale when WooCommerce stock changes. */
    public static function on_stock_changed(mixed $product): void
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            self::request_active_feeds();
        }
    }

    /** Mark snapshots stale for material catalog-field changes, including prices. */
    public static function on_product_updated(mixed $product, mixed $updated_props = array()): void
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        $relevant = array(
            'name', 'status', 'catalog_visibility', 'regular_price', 'sale_price',
            'date_on_sale_from', 'date_on_sale_to', 'stock_status', 'stock_quantity',
            'manage_stock', 'image_id', 'gallery_image_ids', 'description',
            'short_description', 'category_ids', 'attributes', 'weight',
        );
        if (!is_array($updated_props) || empty(array_intersect($relevant, $updated_props))) {
            return;
        }
        self::request_active_feeds();
    }

    /** Enqueue one debounced refresh for each active feed. */
    public static function request_active_feeds(): void
    {
        foreach (ADC_Feed_Store::all() as $feed) {
            if ('active' === ($feed['status'] ?? '') && (int) ($feed['id'] ?? 0) > 0) {
                ADC_Feed_Generator::request((int) $feed['id']);
            }
        }
    }
}
