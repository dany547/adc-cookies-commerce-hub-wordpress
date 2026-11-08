<?php
/** Google Merchant Center RSS field mapping. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Maps normalized WooCommerce items to Google product-data attributes. */
final class ADC_Feed_Profile_Google
{
    public const NAMESPACE_URI = 'http://base.google.com/ns/1.0';

    /** Required RSS product fields and supported optional Google attributes. */
    public static function fields(): array
    {
        return array(
            'id', 'title', 'description', 'link', 'image_link', 'availability', 'price',
            'condition', 'brand', 'gtin', 'mpn', 'item_group_id', 'sale_price',
            'sale_price_effective_date', 'google_product_category', 'product_type',
            'additional_image_link', 'shipping', 'custom_label_0', 'custom_label_1',
            'custom_label_2', 'custom_label_3', 'custom_label_4', 'color', 'size',
            'gender', 'age_group', 'material', 'pattern', 'size_type', 'size_system',
            'identifier_exists', 'availability_date', 'shipping_weight', 'certification',
            'unit_pricing_measure', 'unit_pricing_base_measure',
        );
    }

    /** Validate country-dependent Google feed configuration before generation. */
    public static function validate_config(array $config): array
    {
        $errors = array();
        $country = strtoupper((string) ($config['country'] ?? self::store_country()));
        $shipping = $config['shipping'] ?? array();
        if ('RO' === $country && !self::has_shipping($shipping)) {
            $errors[] = 'shipping_required_for_ro';
        }
        foreach (self::shipping_rows($shipping) as $row) {
            $shipping_country = strtoupper((string) ($row['country'] ?? ''));
            if ('' === $shipping_country) {
                $errors[] = 'shipping_country_required';
            } elseif (!preg_match('/^[A-Z]{2}$/', $shipping_country)) {
                $errors[] = 'shipping_country_invalid';
            }
            if (isset($row['price']) && (!is_numeric($row['price']) || (float) $row['price'] < 0 || (float) $row['price'] > 1000)) {
                $errors[] = 'shipping_price_invalid';
            }
        }
        return array_values(array_unique($errors));
    }

    /** Report conditional attributes only when product metadata says they apply. */
    public static function diagnostics(array $item): array
    {
        $warnings = array();
        $certification = is_array($item['certification'] ?? null) ? $item['certification'] : array();
        $has_certification = !empty($certification['certification_authority'])
            && !empty($certification['certification_name'])
            && !empty($certification['certification_code']);
        if (!empty($item['certification_required']) && !$has_certification) {
            $warnings[] = 'certification_required_but_missing';
        } elseif (!empty($certification) && !$has_certification) {
            $warnings[] = 'certification_incomplete';
        }

        $has_measure = '' !== self::text($item['unit_pricing_measure'] ?? '');
        $has_base = '' !== self::text($item['unit_pricing_base_measure'] ?? '');
        if (!empty($item['unit_pricing_required']) && (!$has_measure || !$has_base)) {
            $warnings[] = 'unit_pricing_required_but_missing';
        } elseif ($has_measure !== $has_base) {
            $warnings[] = 'unit_pricing_incomplete';
        }
        return $warnings;
    }

    /** Convert one normalized item into Google attribute keys and scalar values. */
    public static function map_item(array $item): array
    {
        $mapped = array(
            'id'           => (string) ($item['feed_item_id'] ?? ''), // Preserve exact event ID semantics; XML escaping is the writer's job.
            'title'        => self::truncate(self::text($item['title'] ?? ''), 150),
            'description'  => self::truncate(self::text($item['description'] ?? ''), 5000),
            'link'         => self::text($item['link'] ?? ''),
            'image_link'   => self::text($item['image_link'] ?? ''),
            'availability' => self::availability((string) ($item['availability'] ?? '')),
            'price'        => self::money($item['price'] ?? null, (string) ($item['currency'] ?? '')),
            'condition'    => in_array(($item['condition'] ?? 'new'), array('new', 'used', 'refurbished'), true)
                ? (string) $item['condition']
                : 'new',
        );

        foreach (array('brand', 'gtin', 'mpn', 'item_group_id', 'google_product_category', 'product_type', 'availability_date', 'shipping_weight', 'unit_pricing_measure', 'unit_pricing_base_measure') as $key) {
            $value = self::text($item[$key] ?? '');
            if ('' !== $value) {
                $mapped[$key] = 'product_type' === $key ? self::truncate($value, 750) : $value;
            }
        }

        $sale_price = $item['sale_price'] ?? null;
        if (null !== $sale_price && '' !== (string) $sale_price) {
            $mapped['sale_price'] = self::money($sale_price, (string) ($item['currency'] ?? ''));
            $start = self::date($item['sale_start'] ?? '');
            $end = self::date($item['sale_end'] ?? '');
            if ('' !== $start && '' !== $end) {
                $mapped['sale_price_effective_date'] = $start . '/' . $end;
            }
        }

        $additional = array_slice(array_values(array_filter(array_map(
            static fn(mixed $url): string => self::text($url),
            is_array($item['additional_image_links'] ?? null) ? $item['additional_image_links'] : array()
        ))), 0, 10);
        if (!empty($additional)) {
            $mapped['additional_image_link'] = $additional;
        }

        foreach (array('color', 'size', 'gender', 'age_group', 'material', 'pattern', 'size_type', 'size_system') as $attribute) {
            $value = $item['attributes'][$attribute] ?? $item['attributes']['pa_' . $attribute] ?? '';
            if ('' !== self::text($value)) {
                $mapped[$attribute] = self::text($value);
            }
        }

        foreach (range(0, 4) as $slot) {
            $label = self::truncate(self::text($item['custom_labels'][$slot] ?? ''), 100);
            if ('' !== $label) {
                $mapped['custom_label_' . $slot] = $label;
            }
        }

        $certification = is_array($item['certification'] ?? null) ? $item['certification'] : array();
        $certification = array_intersect_key($certification, array_flip(array('certification_authority', 'certification_name', 'certification_code')));
        if (!empty($certification)) {
            $mapped['certification'] = array_map(static fn(mixed $value): string => self::text($value), $certification);
        }

        $mapped['identifier_exists'] = ('' === (string) ($mapped['gtin'] ?? '') && '' === (string) ($mapped['mpn'] ?? ''))
            ? 'no'
            : 'yes';
        $weight = self::text($item['weight'] ?? '');
        if ('' !== $weight && is_numeric($weight) && (float) $weight > 0) {
            $unit = function_exists('get_option') ? (string) get_option('woocommerce_weight_unit', 'kg') : 'kg';
            $mapped['shipping_weight'] = $weight . ' ' . ('' !== $unit ? $unit : 'kg');
        }
        $mapped['shipping'] = self::shipping_rows($item['shipping'] ?? array());
        foreach ($mapped['shipping'] as &$shipping) {
            if (isset($shipping['price']) && is_numeric($shipping['price'])) {
                $shipping['price'] = number_format((float) $shipping['price'], 2, '.', '') . ' ' . (string) ($item['currency'] ?? 'RON');
            }
        }
        unset($shipping);

        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : array();
        if (class_exists('ADC_Feed_Params')) {
            $extra = ADC_Feed_Params::serialize_extras($extra, 'google', $item);
        } else {
            $extra = array();
        }
        $mapped = array_merge($mapped, $extra);
        return $mapped;
    }

    /** @return array<int,array<string,string>> */
    public static function shipping_rows(mixed $shipping): array
    {
        if (!is_array($shipping) || empty($shipping)) {
            return array();
        }
        if (!array_is_list($shipping)) {
            $shipping = array($shipping);
        }
        $rows = array();
        foreach ($shipping as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = array();
            foreach (array('country', 'region', 'postal_code', 'location_id', 'service', 'price', 'min_handling_time', 'max_handling_time') as $key) {
                if (isset($row[$key]) && is_scalar($row[$key]) && '' !== (string) $row[$key]) {
                    $clean[$key] = (string) $row[$key];
                }
            }
            if (!empty($clean)) {
                $rows[] = $clean;
            }
        }
        return $rows;
    }

    private static function has_shipping(mixed $shipping): bool
    {
        return !empty(self::shipping_rows($shipping));
    }

    private static function store_country(): string
    {
        if (function_exists('wc_get_base_location')) {
            $location = wc_get_base_location();
            if (is_array($location) && !empty($location['country'])) {
                return strtoupper((string) $location['country']);
            }
        }
        return 'RO';
    }

    private static function availability(string $value): string
    {
        return in_array($value, array('in_stock', 'out_of_stock', 'preorder', 'backorder'), true)
            ? $value
            : 'out_of_stock';
    }

    private static function money(mixed $amount, string $currency): string
    {
        if (!is_numeric($amount) || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return '';
        }
        $decimals = function_exists('wc_get_price_decimals') ? max(0, min(6, (int) wc_get_price_decimals())) : 2;
        return number_format((float) $amount, $decimals, '.', '') . ' ' . $currency;
    }

    private static function date(mixed $value): string
    {
        if (!is_scalar($value) || '' === (string) $value) {
            return '';
        }
        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        return $timestamp ? gmdate('Y-m-d\TH:iP', $timestamp) : '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function truncate(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
