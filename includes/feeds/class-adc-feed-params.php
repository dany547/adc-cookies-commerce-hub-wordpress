<?php
/** Declarative parameter sources and transforms for catalog feed fields. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Validates, resolves, and applies user-configured feed parameter rows. */
final class ADC_Feed_Params
{
    private const NATIVE_SOURCES = array(
        'title', 'description', 'short_description', 'sku', 'permalink', 'regular_price',
        'sale_price', 'price', 'image', 'gallery', 'brand', 'stock_status', 'stock_qty',
        'weight', 'category_path', 'feed_item_id', 'gtin', 'mpn', 'condition',
    );

    /** Native WooCommerce sources exposed to REST so clients use the PHP source registry. */
    public static function native_sources(): array
    {
        $labels = array(
            'title' => __('Nume', 'adc-marketing'),
            'description' => __('Descriere', 'adc-marketing'),
            'short_description' => __('Descriere scurtă', 'adc-marketing'),
            'sku' => __('SKU', 'adc-marketing'),
            'permalink' => __('Adresă produs', 'adc-marketing'),
            'regular_price' => __('Preț normal', 'adc-marketing'),
            'sale_price' => __('Preț redus', 'adc-marketing'),
            'price' => __('Preț', 'adc-marketing'),
            'image' => __('Imagine principală', 'adc-marketing'),
            'gallery' => __('Galerie', 'adc-marketing'),
            'brand' => __('Marcă', 'adc-marketing'),
            'stock_status' => __('Stare stoc', 'adc-marketing'),
            'stock_qty' => __('Cantitate în stoc', 'adc-marketing'),
            'weight' => __('Greutate', 'adc-marketing'),
            'category_path' => __('Categorii', 'adc-marketing'),
            'feed_item_id' => __('ID produs', 'adc-marketing'),
            'gtin' => __('GTIN', 'adc-marketing'),
            'mpn' => __('MPN', 'adc-marketing'),
            'condition' => __('Condiție', 'adc-marketing'),
        );
        $sources = array();
        foreach (self::NATIVE_SOURCES as $key) {
            $sources[] = array('value' => $key, 'label' => $labels[$key] ?? $key);
        }
        return $sources;
    }

    /** Construct required parameter rows from the field registry. */
    public static function defaults_for(string $channel): array
    {
        if (!class_exists('ADC_Feed_Field_Registry')) {
            return array();
        }
        $rows = array();
        foreach (ADC_Feed_Field_Registry::fields($channel) as $key => $field) {
            if (empty($field['required'])) {
                continue;
            }
            $source = $field['default_source'] ?? match ((string) $key) {
                'condition' => array('type' => 'native', 'value' => 'condition'),
                'shipping' => array('type' => 'constant', 'value' => ''),
                default => array('type' => 'native', 'value' => self::default_native((string) $key)),
            };
            if (is_string($source)) {
                $source = array('type' => 'native', 'value' => $source);
            }
            if (!is_array($source) || !isset($source['type'], $source['value'])) {
                $source = array('type' => 'native', 'value' => self::default_native((string) $key));
            }
            $rows[] = array(
                'field' => (string) $key,
                'source' => array('type' => (string) $source['type'], 'value' => $source['value']),
                'transforms' => array(),
                'enabled' => true,
            );
        }
        return $rows;
    }

    /** Validate channel fields, duplicate policy, source descriptors, and transforms. */
    public static function validate(string $channel, array $params): array
    {
        if (!class_exists('ADC_Feed_Field_Registry')) {
            return array(array('code' => 'field_registry_unavailable'));
        }
        $registry_fields = ADC_Feed_Field_Registry::fields($channel);
        if (empty($registry_fields)) {
            return array(array('code' => 'unsupported_channel'));
        }
        $errors = array();
        $seen = array();
        $present = array();
        foreach ($params as $index => $row) {
            if (!is_array($row) || !is_string($row['field'] ?? null)) {
                $errors[] = array('code' => 'param_row_invalid', 'row' => $index);
                continue;
            }
            $field = $row['field'];
            $definition = $registry_fields[$field] ?? null;
            if (!is_array($definition)) {
                $errors[] = array('code' => 'param_field_unsupported', 'row' => $index, 'field' => $field);
                continue;
            }
            if (isset($seen[$field]) && empty($definition['repeatable'])) {
                $errors[] = array('code' => 'param_field_duplicate', 'row' => $index, 'field' => $field);
            }
            $seen[$field] = true;
            if (!array_key_exists('enabled', $row) || !is_bool($row['enabled'])) {
                $errors[] = array('code' => 'param_enabled_invalid', 'row' => $index, 'field' => $field);
            }
            $source_errors = self::validate_source($row['source'] ?? null, $index, $field);
            $errors = array_merge($errors, $source_errors);
            $transform_errors = self::validate_transforms($row['transforms'] ?? null, $index, $field);
            $errors = array_merge($errors, $transform_errors);
            if (!empty($row['enabled'])) {
                $present[$field] = true;
            }
        }
        foreach (ADC_Feed_Field_Registry::required_keys($channel) as $field) {
            if (!isset($present[$field])) {
                $errors[] = array('code' => 'param_required_missing', 'field' => (string) $field);
            }
        }
        return $errors;
    }

    /** Apply enabled rows; required values override profile defaults and optional values go to extra. */
    public static function apply(array $item, array $params, string $channel): array
    {
        if (!class_exists('ADC_Feed_Field_Registry')) {
            return $item;
        }
        if (array() !== self::validate($channel, $params)) {
            $item['param_warnings'] = array('params_invalid');
            return $item;
        }
        $definitions = ADC_Feed_Field_Registry::fields($channel);
        $required = array_fill_keys(ADC_Feed_Field_Registry::required_keys($channel), true);
        $item['_required_params'] = is_array($item['_required_params'] ?? null) ? $item['_required_params'] : array();
        $item['extra'] = is_array($item['extra'] ?? null) ? $item['extra'] : array();
        $warnings = is_array($item['param_warnings'] ?? null) ? $item['param_warnings'] : array();
        foreach ($params as $row) {
            if (empty($row['enabled'])) {
                continue;
            }
            $field = (string) $row['field'];
            $source = $row['source'];
            $value = 'native' === $source['type'] && 'stock_status' === $source['value']
                ? self::channel_availability((string) ($item['availability'] ?? ''), $channel)
                : self::resolve_source($item, (string) $source['type'], $source['value']);
            $value = self::transform($value, $row['transforms']);
            $definition = $definitions[$field];
            if (!self::value_matches($value, $definition)) {
                $warnings[] = 'param_type_mismatch';
            }
            if (is_scalar($value) && isset($definition['max_length']) && self::length((string) $value) > (int) $definition['max_length']) {
                $warnings[] = 'param_value_too_long';
            }
            if (isset($required[$field])) {
                $item['_required_params'][$field] = $value;
                $canonical = self::canonical_field($field);
                if ('' !== $canonical && !('shipping' === $canonical && '' === $value)) {
                    $item[$canonical] = $value;
                }
            } else {
                if (!empty($definition['repeatable'])) {
                    $existing = $item['extra'][$field] ?? array();
                    $item['extra'][$field] = array_merge(
                        is_array($existing) ? $existing : array($existing),
                        is_array($value) ? $value : array($value)
                    );
                } else {
                    $item['extra'][$field] = $value;
                }
            }
        }
        $item['param_warnings'] = array_values(array_unique($warnings));
        return $item;
    }

    /** Publicly expose the same credential denylist to the WooCommerce catalog reader. */
    public static function valid_meta_source(string $key): bool
    {
        return self::valid_meta_key($key);
    }

    /** Filter and serialize optional values according to their registry type. */
    public static function serialize_extras(array $extra, string $channel, array $item): array
    {
        if (!class_exists('ADC_Feed_Field_Registry')) {
            return array();
        }
        $allowed = ADC_Feed_Field_Registry::fields($channel);
        $filtered = array();
        foreach ($extra as $field => $value) {
            if (!is_string($field) || !isset($allowed[$field]) || preg_match('/^[a-z][a-z0-9_]*$/', $field) !== 1) {
                continue;
            }
            $type = (string) ($allowed[$field]['type'] ?? 'text');
            if (is_array($value)) {
                $value = array_values(array_filter($value, static fn(mixed $part): bool => is_scalar($part) || null === $part));
            } elseif ('list' === $type && (is_scalar($value) || null === $value)) {
                $value = null === $value || '' === (string) $value ? array() : array($value);
            }
            if (null !== $value && !is_scalar($value) && !is_array($value)) {
                continue;
            }
            if ('price' === $type && is_scalar($value) && '' !== (string) $value) {
                $currency = (string) ($item['currency'] ?? '');
                if (preg_match('/^[-+]?(?:\\d+\\.?\\d*|\\.\\d+)\\s+[A-Z]{3}$/', trim((string) $value)) !== 1 && is_numeric($value)) {
                    $decimals = 'google' === $channel && function_exists('wc_get_price_decimals')
                        ? max(0, min(6, (int) wc_get_price_decimals()))
                        : 2;
                    $formatted = number_format((float) $value, $decimals, '.', '');
                    $value = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $formatted . ' ' . $currency : $formatted;
                }
            } elseif ('boolean' === $type && is_scalar($value)) {
                $normalized = strtolower(trim((string) $value));
                if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
                    $value = 'yes';
                } elseif (in_array($normalized, array('0', 'false', 'no', 'off'), true)) {
                    $value = 'no';
                }
            } elseif ('enum' === $type && is_scalar($value)) {
                $value = (string) $value;
            }
            $filtered[$field] = $value;
        }
        return $filtered;
    }

    /** Validate a source without evaluating any user-provided code. */
    private static function validate_source(mixed $source, int|string $index, string $field): array
    {
        if (!is_array($source) || !is_string($source['type'] ?? null) || !array_key_exists('value', $source)) {
            return array(array('code' => 'param_source_invalid', 'row' => $index, 'field' => $field));
        }
        $type = $source['type'];
        $value = $source['value'];
        $valid = match ($type) {
            'native' => is_string($value) && in_array($value, array_merge(self::NATIVE_SOURCES, array('id', 'name', 'image_url', 'gallery_urls', 'stock_quantity', 'categories')), true),
            'attribute' => is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1,
            'taxonomy' => is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1
                && function_exists('taxonomy_exists') && taxonomy_exists($value),
            'meta' => self::valid_meta_key($value),
            'constant', 'template' => is_string($value) && strlen($value) <= 5000,
            'category_map' => null === $value || (is_string($value) && strlen($value) <= 64),
            default => false,
        };
        if (!$valid || ('template' === $type && !self::valid_template($value))) {
            return array(array('code' => 'param_source_invalid', 'row' => $index, 'field' => $field));
        }
        return array();
    }

    private static function valid_meta_key(mixed $key): bool
    {
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) !== 1) {
            return false;
        }
        return preg_match('/(?:^_wp_|session|password|token|secret|key|api|credential|auth|private|bearer|nonce|cookie|access|refresh)/i', $key) !== 1;
    }

    private static function valid_template(string $template): bool
    {
        preg_match_all('/\{([^{}]+)\}/', $template, $matches);
        $remainder = preg_replace('/\{[^{}]*\}/', '', $template) ?? $template;
        if (str_contains($remainder, '{') || str_contains($remainder, '}')) {
            return false;
        }
        foreach ($matches[1] as $placeholder) {
            if (!in_array($placeholder, self::NATIVE_SOURCES, true)) {
                return false;
            }
        }
        return true;
    }

    private static function validate_transforms(mixed $transforms, int|string $index, string $field): array
    {
        if (!is_array($transforms) || count($transforms) > 20) {
            return array(array('code' => 'param_transforms_invalid', 'row' => $index, 'field' => $field));
        }
        $errors = array();
        foreach ($transforms as $transform) {
            if (!is_array($transform) || !is_string($transform['op'] ?? null)) {
                $errors[] = array('code' => 'param_transform_invalid', 'row' => $index, 'field' => $field);
                continue;
            }
            $op = $transform['op'];
            $arg = $transform['arg'] ?? null;
            $valid = match ($op) {
                'trim', 'strip_html', 'uppercase', 'lowercase', 'price_format' => null === $arg || is_scalar($arg),
                'truncate' => is_numeric($arg) && (int) $arg >= 0 && (int) $arg <= 10000,
                'prefix', 'suffix', 'default_if_empty' => is_scalar($arg) && strlen((string) $arg) <= 5000,
                'replace' => self::replace_parts($arg) !== null,
                'map' => null !== self::map_argument($arg),
                default => false,
            };
            if (!$valid) {
                $errors[] = array('code' => 'param_transform_invalid', 'row' => $index, 'field' => $field);
            }
        }
        return $errors;
    }

    private static function replace_parts(mixed $arg): ?array
    {
        if (is_array($arg)) {
            $from = $arg['from'] ?? $arg[0] ?? null;
            $to = $arg['to'] ?? $arg[1] ?? null;
            return is_scalar($from) && is_scalar($to) && strlen((string) $from) <= 5000 && strlen((string) $to) <= 5000
                ? array((string) $from, (string) $to)
                : null;
        }
        if (!is_string($arg)) {
            return null;
        }
        foreach (array('→', '=>') as $separator) {
            if (str_contains($arg, $separator)) {
                $parts = explode($separator, $arg, 2);
                return array($parts[0], $parts[1]);
            }
        }
        return null;
    }

    private static function map_argument(mixed $arg): ?array
    {
        if (is_array($arg)) {
            return count($arg) <= 200 ? $arg : null;
        }
        if (is_string($arg)) {
            $decoded = json_decode($arg, true);
            return is_array($decoded) && count($decoded) <= 200 ? $decoded : null;
        }
        return null;
    }

    private static function resolve_source(array $item, string $type, mixed $value): mixed
    {
        $sources = is_array($item['_source_values'] ?? null) ? $item['_source_values'] : array();
        return match ($type) {
            'native' => $sources[$value] ?? self::native_value($item, (string) $value),
            'attribute' => $sources['attribute:' . $value] ?? ($item['attributes'][$value] ?? ''),
            'taxonomy' => $sources['taxonomy:' . $value] ?? '',
            'meta' => $sources['meta:' . $value] ?? '',
            'constant' => $value,
            'template' => self::render_template($item, $sources, (string) $value),
            'category_map' => $sources['category_map'] ?? $item['google_product_category'] ?? $value ?? '',
            default => '',
        };
    }

    private static function native_value(array $item, string $key): mixed
    {
        $keys = array(
            'title' => 'title', 'description' => 'description', 'short_description' => 'description',
            'sku' => 'sku_id', 'permalink' => 'link', 'regular_price' => 'regular_price',
            'sale_price' => 'sale_price', 'price' => 'price', 'image' => 'image_link',
            'gallery' => 'additional_image_links', 'brand' => 'brand', 'stock_status' => 'stock_status',
            'stock_qty' => 'stock_qty', 'weight' => 'weight', 'category_path' => 'product_type',
            'feed_item_id' => 'feed_item_id', 'id' => 'feed_item_id', 'name' => 'title',
            'image_url' => 'image_link', 'gallery_urls' => 'additional_image_links',
            'stock_quantity' => 'stock_qty', 'categories' => 'product_type',
            'gtin' => 'gtin', 'mpn' => 'mpn', 'condition' => 'condition',
        );
        return $item[$keys[$key] ?? ''] ?? '';
    }

    private static function render_template(array $item, array $sources, string $template): string
    {
        return (string) preg_replace_callback('/\{([^{}]+)\}/', static function (array $match) use ($item, $sources): string {
            $key = $match[1];
            $value = $sources[$key] ?? self::native_value($item, $key);
            return is_scalar($value) ? (string) $value : (is_array($value) ? implode(', ', array_map('strval', $value)) : '');
        }, $template);
    }

    private static function transform(mixed $value, array $transforms): mixed
    {
        foreach ($transforms as $transform) {
            $arg = $transform['arg'] ?? null;
            $text = is_scalar($value) ? (string) $value : (is_array($value) ? implode(', ', array_map('strval', $value)) : '');
            switch ($transform['op']) {
                case 'trim': $value = trim($text); break;
                case 'strip_html': $value = trim(strip_tags($text)); break;
                case 'truncate': $value = self::truncate($text, (int) $arg); break;
                case 'uppercase': $value = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text); break;
                case 'lowercase': $value = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text); break;
                case 'prefix': $value = (string) $arg . $text; break;
                case 'suffix': $value = $text . (string) $arg; break;
                case 'default_if_empty': if ('' === trim($text)) { $value = $arg; } break;
                case 'replace': $parts = self::replace_parts($arg) ?? array('', ''); $value = str_replace($parts[0], $parts[1], $text); break;
                case 'map': $mapping = self::map_argument($arg) ?? array(); $value = $mapping[$text] ?? $value; break;
                case 'price_format': if (is_numeric($value)) { $value = number_format((float) $value, 2, '.', ''); } break;
            }
        }
        return $value;
    }

    private static function value_matches(mixed $value, array $definition): bool
    {
        if (null === $value || '' === $value) {
            return true;
        }
        $type = (string) ($definition['type'] ?? 'string');
        $scalar = is_scalar($value);
        if (in_array($type, array('array', 'list'), true)) {
            return is_array($value);
        }
        if (!$scalar || is_bool($value)) {
            return false;
        }
        if (in_array($type, array('number', 'integer', 'price'), true) && !is_numeric($value)) {
            return false;
        }
        if ('integer' === $type && (int) $value != (float) $value) {
            return false;
        }
        if ('url' === $type && (filter_var((string) $value, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url((string) $value, PHP_URL_SCHEME)), array('http', 'https'), true))) {
            return false;
        }
        if (isset($definition['enum']) && !in_array((string) $value, $definition['enum'], true)) {
            return false;
        }
        if (is_scalar($value) && isset($definition['max_length']) && self::length((string) $value) > (int) $definition['max_length']) {
            return false;
        }
        return true;
    }

    private static function truncate(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        $parts = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? implode('', array_slice($parts, 0, $limit)) : substr($value, 0, $limit);
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        if (function_exists('iconv_strlen')) {
            $length = iconv_strlen($value, 'UTF-8');
            if (false !== $length) {
                return $length;
            }
        }
        $matched = preg_match_all('/./us', $value, $matches);
        return false !== $matched ? $matched : strlen($value);
    }

    private static function default_native(string $field): string
    {
        $map = array('id' => 'feed_item_id', 'sku_id' => 'sku', 'title' => 'title', 'description' => 'description', 'link' => 'permalink', 'image_link' => 'image', 'price' => 'price', 'availability' => 'availability', 'brand' => 'brand');
        return $map[$field] ?? $field;
    }

    private static function canonical_field(string $field): string
    {
        return match ($field) {
            'id' => 'feed_item_id',
            'additional_image_link' => 'additional_image_links',
            default => $field,
        };
    }

    private static function channel_availability(string $availability, string $channel): string
    {
        return match ($channel) {
            'meta' => in_array($availability, array('in_stock', 'backorder', 'preorder'), true) ? 'in stock' : 'out of stock',
            'tiktok' => match ($availability) {
                'in_stock' => 'in stock',
                'backorder' => 'available for order',
                'preorder' => 'preorder',
                'discontinued' => 'discontinued',
                default => 'out of stock',
            },
            default => $availability,
        };
    }
}
