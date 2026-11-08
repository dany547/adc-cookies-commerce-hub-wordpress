<?php
/** Meta Commerce Catalog product mapping. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Maps normalized products to Meta catalog fields. */
final class ADC_Feed_Profile_Meta
{
    public static function fields(): array
    {
        return array('id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'additional_image_link', 'google_product_category', 'fb_product_category', 'brand', 'gtin', 'mpn', 'item_group_id', 'product_type', 'sale_price', 'sale_price_effective_date', 'internal_label', 'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3', 'custom_label_4', 'color', 'size', 'gender', 'age_group', 'material', 'pattern', 'shipping_weight');
    }

    public static function map_item(array $item, array $config = array()): array
    {
        $mapped = array(
            'id' => (string) ($item['feed_item_id'] ?? ''),
            'title' => self::text($item['title'] ?? ''),
            'description' => self::text($item['description'] ?? ''),
            'availability' => in_array(($item['availability'] ?? ''), array('in stock', 'out of stock'), true)
                ? (string) $item['availability']
                : (in_array(($item['availability'] ?? ''), array('in_stock', 'backorder', 'preorder'), true) ? 'in stock' : 'out of stock'),
            'condition' => in_array(($item['condition'] ?? 'new'), array('new', 'refurbished', 'used'), true) ? (string) ($item['condition'] ?? 'new') : 'new',
            'price' => self::money($item),
            'link' => self::text($item['link'] ?? ''),
            'image_link' => self::text($item['image_link'] ?? ''),
        );
        foreach (array('brand', 'gtin', 'mpn', 'item_group_id', 'google_product_category', 'product_type', 'sale_price_effective_date') as $key) {
            $value = self::text($item[$key] ?? '');
            if ('' !== $value) {
                $mapped[$key] = $value;
            }
        }
        if ('' === self::text($mapped['brand'] ?? '') && '' !== self::text($config['meta_brand'] ?? '')) {
            $mapped['brand'] = self::text($config['meta_brand']);
        }
        $category = self::text($config['fb_product_category'] ?? '');
        if ('' !== self::text($item['google_product_category'] ?? '')) {
            $mapped['google_product_category'] = self::text($item['google_product_category']);
        } elseif ('' !== $category) {
            $mapped['fb_product_category'] = $category;
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
            $mapped['additional_image_link'] = implode(',', array_slice($images, 0, 50));
        }
        foreach (range(0, 4) as $slot) {
            $value = self::text($item['custom_labels'][$slot] ?? '');
            if ('' !== $value) {
                $mapped['custom_label_' . $slot] = self::truncate($value, 100);
            }
        }
        $labels = $config['smart_labels'] ?? array();
        if (is_array($labels)) {
            $labels = array_values(array_unique(array_filter(array_map(static function (mixed $label): string {
                $value = strtolower(trim(strip_tags(is_scalar($label) ? (string) $label : '')));
                return function_exists('mb_substr') ? mb_substr($value, 0, 110) : substr($value, 0, 110);
            }, $labels))));
            if (!empty($labels)) {
                $mapped['internal_label'] = $labels;
            }
        }
        $weight = self::weight_with_unit($item['weight'] ?? '');
        if ('' !== $weight) {
            $mapped['weight'] = $weight; // Normalized value for the Meta validator.
            $mapped['shipping_weight'] = $weight;
        }
        foreach (array('color', 'size', 'gender', 'age_group', 'material', 'pattern') as $key) {
            $value = self::text($item['attributes'][$key] ?? $item['attributes']['pa_' . $key] ?? '');
            if ('' !== $value) {
                $mapped[$key] = $value;
            }
        }
        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : array();
        if (class_exists('ADC_Feed_Params')) {
            $extra = ADC_Feed_Params::serialize_extras($extra, 'meta', $item);
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
        return $timestamp > 0 ? gmdate('Y-m-d\\TH:iP', $timestamp) : '';
    }

    private static function truncate(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
