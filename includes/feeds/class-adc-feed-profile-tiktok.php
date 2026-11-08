<?php
/** TikTok Catalog product mapping. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Maps normalized products to TikTok catalog fields. */
final class ADC_Feed_Profile_Tiktok
{
    public static function fields(): array
    {
        return array('sku_id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand', 'additional_image_link', 'google_product_category', 'product_type', 'item_group_id', 'gtin', 'mpn', 'color', 'size', 'gender', 'material', 'pattern', 'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3', 'custom_label_4', 'sale_price', 'sale_price_effective_date', 'shipping_weight');
    }

    public static function map_item(array $item, array $config = array()): array
    {
        $availability = match ((string) ($item['availability'] ?? '')) {
            'in_stock' => 'in stock',
            'backorder' => 'available for order',
            'preorder' => 'preorder',
            'out_of_stock' => 'out of stock',
            'in stock' => 'in stock',
            'available for order' => 'available for order',
            'preorder' => 'preorder',
            'out of stock' => 'out of stock',
            'discontinued' => 'discontinued',
            default => 'out of stock',
        };
        $mapped = array(
            'sku_id' => (string) ($item['sku_id'] ?? $item['feed_item_id'] ?? ''),
            'title' => self::text($item['title'] ?? ''),
            'description' => self::text($item['description'] ?? ''),
            'availability' => $availability,
            'condition' => in_array(($item['condition'] ?? 'new'), array('new', 'refurbished', 'used'), true) ? (string) ($item['condition'] ?? 'new') : 'new',
            'price' => self::money($item),
            'link' => self::text($item['link'] ?? ''),
            'image_link' => self::text($item['image_link'] ?? ''),
            'brand' => '' !== self::text($item['brand'] ?? '') ? self::text($item['brand']) : self::text($config['tiktok_brand'] ?? ''),
        );
        foreach (array('google_product_category', 'product_type', 'item_group_id', 'gtin', 'mpn', 'color', 'size', 'gender', 'material', 'pattern') as $key) {
            $value = self::text($item['attributes'][$key] ?? $item[$key] ?? '');
            if ('' !== $value) {
                $mapped[$key] = $value;
            }
        }
        if (is_numeric($item['sale_price'] ?? null) && preg_match('/^[A-Z]{3}$/', (string) ($item['currency'] ?? ''))) {
            $mapped['sale_price'] = number_format((float) $item['sale_price'], 2, '.', '') . ' ' . (string) $item['currency'];
        }
        $start = self::date($item['sale_start'] ?? '');
        $end = self::date($item['sale_end'] ?? '');
        if ('' !== $start && '' !== $end) {
            $mapped['sale_price_effective_date'] = $start . '/' . $end;
        }
        $images = array_values(array_filter(array_map(array(self::class, 'text'), (array) ($item['additional_image_links'] ?? array()))));
        if (!empty($images)) {
            $mapped['additional_image_link'] = implode(',', array_slice($images, 0, 10));
        }
        $weight = self::weight_with_unit($item['weight'] ?? '');
        if ('' !== $weight) {
            $mapped['weight'] = $weight; // Normalized value for the TikTok validator.
            $mapped['shipping_weight'] = $weight;
        }
        foreach (range(0, 4) as $slot) {
            $value = self::text($item['custom_labels'][$slot] ?? '');
            if ('' !== $value) {
                $mapped['custom_label_' . $slot] = self::truncate($value, 100);
            }
        }
        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : array();
        if (class_exists('ADC_Feed_Params')) {
            $extra = ADC_Feed_Params::serialize_extras($extra, 'tiktok', $item);
        } else {
            $extra = array();
        }
        $mapped = array_merge($mapped, $extra);
        return $mapped;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function money(array $item): string
    {
        $amount = $item['price'] ?? null;
        $currency = (string) ($item['currency'] ?? '');
        return is_numeric($amount) && preg_match('/^[A-Z]{3}$/', $currency)
            ? number_format((float) $amount, 2, '.', '') . ' ' . $currency
            : '';
    }

    private static function weight_with_unit(mixed $weight): string
    {
        if (!is_numeric($weight) || (float) $weight <= 0) {
            return '';
        }
        $unit = function_exists('get_option') ? strtolower((string) get_option('woocommerce_weight_unit', 'kg')) : 'kg';
        $unit = array('lbs' => 'lb', 'pound' => 'lb', 'pounds' => 'lb', 'gram' => 'g', 'grams' => 'g', 'kilogram' => 'kg', 'kilograms' => 'kg', 'ounce' => 'oz', 'ounces' => 'oz')[$unit] ?? $unit;
        return in_array($unit, array('lb', 'oz', 'g', 'kg'), true) ? (string) $weight . ' ' . $unit : '';
    }

    private static function date(mixed $value): string
    {
        if (!is_scalar($value) || '' === (string) $value) {
            return '';
        }
        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        return $timestamp > 0 ? gmdate('Y-m-d\\TH:i', $timestamp) : '';
    }

    private static function truncate(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
