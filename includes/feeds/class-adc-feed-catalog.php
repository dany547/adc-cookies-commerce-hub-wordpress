<?php
/** WooCommerce product reader and normalized feed items. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Reads public WooCommerce products through the product CRUD/query APIs. */
final class ADC_Feed_Catalog
{
    private static int $last_examined = 0;
    private static bool $last_has_more = false;
    private static array $parent_cache = array();

    /**
     * Return one normalized, eligible page of product/feed-variation rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function items(array $config, int $offset, int $limit): array
    {
        self::$last_examined = 0;
        self::$last_has_more = false;
        if (!function_exists('wc_get_products')) {
            return array();
        }

        if ('filtered' === ($config['scope'] ?? '') && empty($config['categories'])) {
            return array(); // A filtered feed with no categories is never an all-products query.
        }

        $offset = max(0, $offset);
        $limit = max(1, min(500, $limit));
        $query = array(
            'status'   => 'publish',
            'type'     => array('simple', 'variation', 'external'),
            'limit'    => $limit,
            'offset'   => $offset,
            'paginate' => true,
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'return'   => 'objects',
        );
        if ('smart' === ($config['scope'] ?? '')) {
            $ids = is_array($config['smart_product_ids'] ?? null)
                ? array_values(array_unique(array_filter(array_map('intval', $config['smart_product_ids']), static fn(int $id): bool => $id > 0)))
                : array();
            if ($ids === []) {
                return array();
            }
            $query['include'] = $ids;
            $query['orderby'] = 'include';
        }
        if (in_array(($config['scope'] ?? ''), array('filtered', 'smart'), true) && !empty($config['categories'])) {
            $categories = is_array($config['categories'])
                ? array_values(array_filter($config['categories'], 'is_string'))
                : array();
            if ('filtered' === $config['scope'] && empty($categories)) {
                return array(); // A filtered feed with no categories is never an all-products query.
            }
            if ($categories !== []) {
                $query['category'] = $categories;
            }
        }
        if (!empty($config['in_stock_only'])) {
            $query['stock_status'] = 'instock';
        }
        $result = wc_get_products($query);

        if (is_object($result) && isset($result->products) && is_array($result->products)) {
            $products = $result->products;
            $total = isset($result->total) ? (int) $result->total : null;
            self::$last_has_more = null !== $total
                ? ($offset + count($products) < $total)
                : count($products) >= $limit;
        } else {
            $products = is_array($result) ? $result : array();
            self::$last_has_more = count($products) >= $limit;
        }
        self::$last_examined = count($products);

        $items = array();
        foreach ($products as $product) {
            if (!self::eligible($product)) {
                continue;
            }
            $item = self::normalize($product, $config);
            if (null !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** Clear parent-product lookups at the start of a feed generation or preview. */
    public static function reset_parent_cache(): void
    {
        self::$parent_cache = array();
    }

    /** Raw source row count read by the last items() call. */
    public static function last_examined(): int
    {
        return self::$last_examined;
    }

    /** Whether the last WooCommerce page indicates another source page. */
    public static function has_more(): bool
    {
        return self::$last_has_more;
    }

    private static function eligible(mixed $product): bool
    {
        if (!is_object($product) || !method_exists($product, 'get_id') || !method_exists($product, 'get_price')) {
            return false;
        }
        if (method_exists($product, 'get_status') && 'publish' !== $product->get_status()) {
            return false;
        }
        if (method_exists($product, 'get_catalog_visibility') && 'hidden' === $product->get_catalog_visibility()) {
            return false;
        }
        if (method_exists($product, 'is_type') && $product->is_type(array('variable', 'grouped'))) {
            return false; // Variable parents are not sellable feed rows; variations are queried separately.
        }

        $price = $product->get_price();
        if ('' === (string) $price || !is_numeric($price) || !is_finite((float) $price)) {
            return false;
        }
        if (method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0) {
            $parent = self::parent_product((int) $product->get_parent_id());
            if (is_object($parent)) {
                if (method_exists($parent, 'get_status') && 'publish' !== $parent->get_status()) {
                    return false;
                }
                if (method_exists($parent, 'get_catalog_visibility') && 'hidden' === $parent->get_catalog_visibility()) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Return a WooCommerce parent once per generation/preview run. */
    private static function parent_product(int $parent_id): mixed
    {
        if ($parent_id <= 0 || !function_exists('wc_get_product')) {
            return null;
        }
        if (!array_key_exists($parent_id, self::$parent_cache)) {
            self::$parent_cache[$parent_id] = wc_get_product($parent_id);
        }
        return self::$parent_cache[$parent_id];
    }

    /** Normalize one product object. */
    private static function normalize(object $product, array $config): ?array
    {
        $id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        if ($id <= 0) {
            return null;
        }

        $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $parent = $parent_id > 0 ? self::parent_product($parent_id) : null;
        $item_id = self::item_id($product);
        if ('' === $item_id) {
            return null;
        }

        $price = self::public_price($product, (float) $product->get_price());
        $regular = method_exists($product, 'get_regular_price') ? $product->get_regular_price() : '';
        $sale = method_exists($product, 'get_sale_price') ? $product->get_sale_price() : '';
        $sale_price = null;
        $sale_is_active = method_exists($product, 'is_on_sale') && $product->is_on_sale();
        if ($sale_is_active && '' !== (string) $sale && is_numeric($sale) && (float) $sale >= 0
            && '' !== (string) $regular && is_numeric($regular) && (float) $regular > (float) $sale) {
            // Both values use WooCommerce's same display/tax context.
            $price = self::public_price($product, (float) $regular);
            $sale_price = self::public_price($product, (float) $sale);
        }

        $stock_status = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : 'instock';
        $availability = method_exists($product, 'is_on_backorder') && $product->is_on_backorder(1)
            ? 'backorder'
            : match ($stock_status) {
            'outofstock' => 'out_of_stock',
            'onbackorder' => 'backorder',
                default => 'in_stock',
            };
        if (method_exists($product, 'get_meta') && 'preorder' === (string) $product->get_meta('_adc_feed_availability')) {
            $availability = 'preorder';
        }

        $category_product = $parent_id > 0 && is_object($parent) ? $parent : $product;
        $category_path = self::category_path($category_product);
        $taxonomy = (string) ADC_Settings::get('events', 'brand_taxonomy', 'product_brand');
        $brand = self::term_name($category_product, $taxonomy);
        $product_attributes = self::attributes($product);

        $images = self::images($product);
        $google_category = self::meta($product, array('_adc_feed_google_product_category'));
        if ('' === $google_category) {
            $google_category = self::meta($category_product, array('_adc_feed_google_product_category'));
        }
        if ('' === $google_category) {
            $google_category = self::mapped_category($category_product, $config);
        }
        if ('' === $google_category) {
            $google_category = self::text($config['default_category'] ?? '');
        }

        $gtin = self::truncate(self::meta($product, array('_adc_feed_gtin', '_global_unique_id', '_gtin', 'gtin')), 50);
        $mpn = self::truncate(self::meta($product, array('_adc_feed_mpn', '_mpn', 'mpn')), 70);
        $certification = self::certification($product);
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'RON';
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RON';
        }

        $labels = array();
        $configured_labels = is_array($config['custom_labels'] ?? null) ? $config['custom_labels'] : array();
        for ($slot = 0; $slot <= 4; ++$slot) {
            $value = $configured_labels[$slot] ?? $configured_labels['custom_label_' . $slot] ?? '';
            $labels[$slot] = self::truncate(self::text($value), 100);
        }

        $item = array(
            'product_id'              => $id,
            'feed_item_id'            => $item_id,
            'tracking_item_id'        => $item_id,
            'sku_id'                  => method_exists($product, 'get_sku') && '' !== (string) $product->get_sku() ? (string) $product->get_sku() : (string) $id,
            'item_group_id'           => $parent_id > 0 ? self::truncate((string) $parent_id, 50) : '',
            'title'                   => self::text(method_exists($product, 'get_name') ? $product->get_name() : ''),
            'description'             => self::description($product),
            'link'                    => self::url(method_exists($product, 'get_permalink') ? $product->get_permalink() : ''),
            'image_link'              => $images[0] ?? '',
            'additional_image_links'  => array_slice(array_values(array_unique(array_slice($images, 1))), 0, 10),
            'availability'            => $availability,
            'availability_date'        => self::meta($product, array('_adc_feed_availability_date')),
            'price'                    => round($price, self::price_decimals()),
            'sale_price'               => null === $sale_price ? null : round($sale_price, self::price_decimals()),
            'sale_start'               => self::date_value(method_exists($product, 'get_date_on_sale_from') ? $product->get_date_on_sale_from() : null),
            'sale_end'                 => self::date_value(method_exists($product, 'get_date_on_sale_to') ? $product->get_date_on_sale_to() : null),
            'currency'                 => $currency,
            'condition'                => self::condition($product, (string) ($config['condition'] ?? 'new')),
            'brand'                    => self::truncate($brand, 70),
            'gtin'                     => $gtin,
            'mpn'                      => $mpn,
            'identifier_exists'        => '' === $gtin && '' === $mpn ? 'no' : 'yes',
            'google_product_category'  => self::truncate($google_category, 750),
            'product_type'              => self::truncate(implode(' > ', $category_path), 750),
            'weight'                    => method_exists($product, 'get_weight') ? self::text($product->get_weight()) : '',
            'attributes'                => $product_attributes,
            'shipping'                  => self::shipping($config['shipping'] ?? array()),
            'custom_labels'             => $labels,
            'certification'             => $certification,
            'certification_required'    => 'yes' === self::meta($product, array('_adc_feed_certification_required')),
            'unit_pricing_measure'     => self::meta($product, array('_adc_feed_unit_measure', '_unit_pricing_measure', '_adc_feed_unit_pricing_measure')),
            'unit_pricing_base_measure' => self::meta($product, array('_adc_feed_unit_base_measure', '_unit_pricing_base_measure', '_adc_feed_unit_pricing_base_measure')),
            'unit_pricing_required'    => 'yes' === self::meta($product, array('_adc_feed_unit_pricing_required')),
        );

        $item['_source_values'] = self::parameter_sources($product, $item, $config);
        return $item;
    }

    /** Resolve only the declarative sources requested in the parameter rows. */
    private static function parameter_sources(object $product, array $item, array $config): array
    {
        $values = array();
        $params = is_array($config['params'] ?? null) ? $config['params'] : array();
        foreach ($params as $row) {
            if (!is_array($row) || empty($row['enabled']) || !is_array($row['source'] ?? null)) {
                continue;
            }
            $source = $row['source'];
            $type = (string) ($source['type'] ?? '');
            $key = $source['value'] ?? '';
            if (!is_string($key)) {
                continue;
            }
            if ('meta' === $type && ADC_Feed_Params::valid_meta_source($key)) {
                $values['meta:' . $key] = method_exists($product, 'get_meta') ? self::text($product->get_meta($key, true)) : '';
            } elseif ('taxonomy' === $type && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) === 1
                && function_exists('taxonomy_exists') && taxonomy_exists($key) && function_exists('get_the_terms')) {
                $terms = get_the_terms((int) $product->get_id(), $key);
                $values['taxonomy:' . $key] = is_array($terms)
                    ? implode(', ', array_values(array_filter(array_map(static fn(mixed $term): string => is_object($term) ? self::text($term->name ?? '') : '', $terms))))
                    : '';
            }
        }
        $values['short_description'] = method_exists($product, 'get_short_description')
            ? self::text(function_exists('wp_strip_all_tags') ? wp_strip_all_tags((string) $product->get_short_description()) : strip_tags((string) $product->get_short_description()))
            : (string) ($item['description'] ?? '');
        $values['regular_price'] = (string) ($item['price'] ?? '');
        $values['sale_price'] = (string) ($item['sale_price'] ?? '');
        $values['stock_qty'] = method_exists($product, 'get_stock_quantity') ? (string) $product->get_stock_quantity() : '';
        $values['stock_status'] = (string) ($item['availability'] ?? '');
        $values['sku'] = (string) ($item['sku_id'] ?? '');
        $values['permalink'] = (string) ($item['link'] ?? '');
        $values['image'] = (string) ($item['image_link'] ?? '');
        $values['gallery'] = (array) ($item['additional_image_links'] ?? array());
        $values['category_path'] = (string) ($item['product_type'] ?? '');
        $values['category_map'] = (string) ($item['google_product_category'] ?? '');
        foreach (array('title', 'description', 'price', 'brand', 'weight', 'feed_item_id', 'gtin', 'mpn') as $key) {
            $values[$key] = $item[$key] ?? '';
        }
        foreach (is_array($item['attributes'] ?? null) ? $item['attributes'] : array() as $key => $value) {
            $values['attribute:' . $key] = $value;
        }
        return $values;
    }

    /** Match ADC_Event_Builder::item_id_for() semantics (that method is private). */
    private static function item_id(object $product): string
    {
        $source = (string) ADC_Settings::get('events', 'item_id_source', 'id');
        $value = '';
        if ('sku' === $source && method_exists($product, 'get_sku')) {
            $value = (string) $product->get_sku();
        } elseif ('gla' === $source && method_exists($product, 'get_meta')) {
            $gla = $product->get_meta('_wc_gla_id');
            $value = is_string($gla) || is_numeric($gla) ? (string) $gla : '';
        }

        if ('' === $value) {
            $id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
            if (0 === $id && method_exists($product, 'get_parent_id')) {
                $id = (int) $product->get_parent_id();
            }
            $value = (string) $id;
        }

        return $value;
    }

    /** Read optional EU certification fields without imposing them on unrelated products. */
    private static function certification(object $product): array
    {
        $eprel_code = self::meta($product, array('_adc_feed_eprel_code'));
        if ('' !== $eprel_code) {
            return array(
                'certification_authority' => 'EC',
                'certification_name' => 'EPREL',
                'certification_code' => $eprel_code,
            );
        }
        $values = array(
            'certification_authority' => self::meta($product, array('_adc_feed_certification_authority')),
            'certification_name' => self::meta($product, array('_adc_feed_certification_name')),
            'certification_code' => self::meta($product, array('_adc_feed_certification_code')),
        );
        return array_filter($values, static fn(string $value): bool => '' !== $value);
    }

    private static function public_price(object $product, float $price): float
    {
        if (function_exists('wc_get_price_to_display')) {
            $price = (float) wc_get_price_to_display($product, array('price' => $price));
        }
        return max(0.0, $price);
    }

    private static function price_decimals(): int
    {
        return function_exists('wc_get_price_decimals') ? max(0, min(6, (int) wc_get_price_decimals())) : 2;
    }

    private static function description(object $product): string
    {
        $description = method_exists($product, 'get_short_description') ? (string) $product->get_short_description() : '';
        if ('' === trim($description) && method_exists($product, 'get_description')) {
            $description = (string) $product->get_description();
        }
        if (function_exists('wp_strip_all_tags')) {
            $description = wp_strip_all_tags($description, true);
        } else {
            $description = strip_tags($description);
        }
        return trim(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return string[] */
    private static function images(object $product): array
    {
        $ids = array();
        if (method_exists($product, 'get_image_id')) {
            $main = (int) $product->get_image_id();
            if ($main > 0) {
                $ids[] = $main;
            }
        }
        if (method_exists($product, 'get_gallery_image_ids')) {
            foreach ((array) $product->get_gallery_image_ids() as $image_id) {
                $image_id = (int) $image_id;
                if ($image_id > 0) {
                    $ids[] = $image_id;
                }
            }
        }

        $urls = array();
        foreach (array_unique($ids) as $image_id) {
            $url = function_exists('wp_get_attachment_image_url')
                ? wp_get_attachment_image_url($image_id, 'full')
                : (function_exists('wp_get_attachment_url') ? wp_get_attachment_url($image_id) : false);
            $url = self::url($url);
            if ('' !== $url) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** @return string[] */
    private static function category_path(object $product): array
    {
        if (!function_exists('get_the_terms')) {
            return array();
        }
        $product_id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        $terms = get_the_terms($product_id, 'product_cat');
        if (!is_array($terms) || empty($terms)) {
            return array();
        }
        $term = reset($terms);
        $ids = function_exists('get_ancestors') ? array_reverse(get_ancestors((int) $term->term_id, 'product_cat', 'taxonomy')) : array();
        $ids[] = (int) $term->term_id;
        $path = array();
        foreach ($ids as $term_id) {
            $row = function_exists('get_term') ? get_term((int) $term_id, 'product_cat') : null;
            if (is_object($row) && isset($row->name)) {
                $path[] = self::text($row->name);
            }
        }
        return $path;
    }

    /** Resolve configured category mapping using product category term IDs. */
    private static function mapped_category(object $product, array $config): string
    {
        $map = is_array($config['category_map'] ?? null) ? $config['category_map'] : array();
        if (empty($map) || !method_exists($product, 'get_category_ids')) {
            return '';
        }
        foreach ($product->get_category_ids() as $term_id) {
            $value = $map[(string) (int) $term_id] ?? $map[(int) $term_id] ?? '';
            if ('' !== self::text($value)) {
                return self::text($value);
            }
        }
        return '';
    }

    private static function term_name(object $product, string $taxonomy): string
    {
        if ('' === $taxonomy || !function_exists('taxonomy_exists') || !taxonomy_exists($taxonomy) || !function_exists('get_the_terms')) {
            return '';
        }
        $id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        $terms = get_the_terms($id, $taxonomy);
        if (!is_array($terms) || empty($terms)) {
            return '';
        }
        $term = reset($terms);
        return isset($term->name) ? self::text($term->name) : '';
    }

    private static function meta(object $product, array $keys): string
    {
        if (!method_exists($product, 'get_meta')) {
            return '';
        }
        foreach ($keys as $key) {
            $value = $product->get_meta($key, true);
            if (is_scalar($value) && '' !== trim((string) $value)) {
                return self::text($value);
            }
        }
        return '';
    }

    private static function condition(object $product, string $default): string
    {
        $value = self::meta($product, array('_adc_feed_condition', '_condition'));
        if (!in_array($value, array('new', 'used', 'refurbished'), true)) {
            $value = $default;
        }
        return in_array($value, array('new', 'used', 'refurbished'), true) ? $value : 'new';
    }

    /** @return array<string,string> */
    private static function attributes(object $product): array
    {
        $attributes = method_exists($product, 'get_attributes') ? (array) $product->get_attributes() : array();
        $output = array();
        foreach ($attributes as $key => $attribute) {
            $name = is_object($attribute) && method_exists($attribute, 'get_name') ? (string) $attribute->get_name() : (string) $key;
            if (is_object($attribute) && method_exists($attribute, 'get_options')) {
                $values = (array) $attribute->get_options();
            } elseif (is_scalar($attribute)) {
                $values = array((string) $attribute);
            } else {
                $values = array();
            }
            $taxonomy_attribute = str_starts_with($name, 'pa_');
            $values = array_values(array_filter(array_map(
                static fn(mixed $v): string => $taxonomy_attribute ? self::attribute_term_name($v, $name) : self::text($v),
                $values
            )));
            if ('' !== $name && !empty($values)) {
                $output[sanitize_key($name)] = implode(', ', $values);
            }
        }
        if (method_exists($product, 'get_variation_attributes')) {
            foreach ((array) $product->get_variation_attributes() as $key => $value) {
                if (is_scalar($value) && '' !== (string) $value) {
                    $attribute_key = preg_replace('/^attribute_/', '', (string) $key);
                    $attribute_value = str_starts_with((string) $attribute_key, 'pa_')
                        ? self::attribute_term_name($value, (string) $attribute_key)
                        : self::text($value);
                    if ('' !== $attribute_value) {
                        $output[sanitize_key($attribute_key)] = $attribute_value;
                    }
                }
            }
        }
        return $output;
    }

    /** Resolve taxonomy-backed attribute term IDs/slugs to human-readable term names. */
    private static function attribute_term_name(mixed $value, string $taxonomy): string
    {
        if (!function_exists('get_term') || !function_exists('taxonomy_exists') || !taxonomy_exists($taxonomy)) {
            return self::text($value);
        }
        $term = null;
        if (is_numeric($value)) {
            $term = get_term((int) $value, $taxonomy);
        } elseif (function_exists('get_term_by')) {
            $term = get_term_by('slug', (string) $value, $taxonomy);
        }
        return is_object($term) && isset($term->name) ? self::text($term->name) : self::text($value);
    }

    private static function shipping(mixed $shipping): array
    {
        if (!is_array($shipping)) {
            return array();
        }
        if (array_is_list($shipping)) {
            $result = array();
            foreach ($shipping as $row) {
                if (is_array($row)) {
                    $clean = self::shipping_row($row);
                    if (!empty($clean)) {
                        $result[] = $clean;
                    }
                }
            }
            return $result;
        }
        return self::shipping_row($shipping);
    }

    private static function shipping_row(array $shipping): array
    {
        $allowed = array('country', 'region', 'postal_code', 'location_id', 'service', 'price', 'min_handling_time', 'max_handling_time');
        $clean = array();
        foreach ($allowed as $key) {
            $value = $shipping[$key] ?? '';
            if (is_scalar($value) && '' !== (string) $value) {
                $clean[$key] = self::text($value);
            }
        }
        if (isset($clean['country'])) {
            $clean['country'] = strtoupper(substr($clean['country'], 0, 2));
        }
        return $clean;
    }

    private static function date_value(mixed $date): string
    {
        if (!is_object($date) || !method_exists($date, 'getTimestamp')) {
            return '';
        }
        $timestamp = (int) $date->getTimestamp();
        if ($timestamp <= 0) {
            return '';
        }
        return function_exists('wp_date') ? wp_date('Y-m-d\TH:iP', $timestamp) : date('Y-m-d\TH:iP', $timestamp);
    }

    private static function url(mixed $url): string
    {
        if (!is_scalar($url)) {
            return '';
        }
        $url = (string) $url;
        if (function_exists('esc_url_raw')) {
            return (string) esc_url_raw($url, array('http', 'https'));
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private static function text(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        return function_exists('sanitize_text_field') ? sanitize_text_field($text) : trim(strip_tags($text));
    }

    private static function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }
        if (function_exists('iconv_substr')) {
            $truncated = iconv_substr($value, 0, $length, 'UTF-8');
            if (is_string($truncated)) {
                return $truncated;
            }
        }
        if (1 === preg_match('//u', $value)) {
            preg_match_all('/./us', $value, $characters);
            return implode('', array_slice($characters[0], 0, $length));
        }
        return substr($value, 0, $length);
    }
}
