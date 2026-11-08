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

    /** Register scheduler handlers and product-change signals. */
    public static function boot(): void
    {
        add_action(self::JOB_HOOK, array(self::class, 'generate'), 10, 1);
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
