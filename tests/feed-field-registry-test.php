<?php
/**
 * Offline tests for ADC_Feed_Field_Registry.
 *
 * Runs without WordPress: only the gettext helper and the registry class are needed.
 *
 * Usage: php tests/feed-field-registry-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('__')) {
    /**
     * Minimal gettext stand-in: the Romanian source string is the output.
     *
     * @param string $text   Source string.
     * @param string $domain Text domain (unused).
     * @return string
     */
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

require_once __DIR__ . '/../includes/feeds/class-adc-feed-field-registry.php';

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition to assert.
 * @param string $message   Failure description.
 * @return void
 */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Check a default_source against the source vocabulary of ADC_Feed_Params.
 *
 * @param array{type:string,value:string} $source Source descriptor.
 * @return bool True when the parameter layer would accept it.
 */
function source_is_valid(array $source): bool
{
    $native = array(
        'title', 'description', 'short_description', 'sku', 'permalink', 'regular_price', 'sale_price',
        'price', 'image', 'gallery', 'brand', 'stock_status', 'stock_qty', 'weight', 'category_path',
        'feed_item_id', 'gtin', 'mpn', 'condition',
    );
    $value = (string) $source['value'];

    return match ((string) $source['type']) {
        'native' => in_array($value, $native, true),
        'meta' => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1
            && preg_match('/(?:^_wp_|session|password|token|secret|key|api)/i', $value) !== 1,
        'attribute', 'taxonomy' => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1,
        'constant', 'template' => $value !== '',
        'category_map' => $value !== '',
        default => false,
    };
}

$registry = ADC_Feed_Field_Registry::class;
$channels = array('google', 'meta', 'tiktok');
$allowed_types = array('text', 'number', 'price', 'url', 'enum', 'boolean', 'list');
$allowed_sources = array('native', 'meta', 'attribute', 'taxonomy', 'constant', 'template', 'category_map');
$allowed_keys = array('key', 'label', 'required', 'type', 'enum', 'max_length', 'help', 'default_source', 'repeatable');

// 1. Channel list.
check($registry::channels() === $channels, 'channels() returns google, meta, tiktok in display order');

// 2. Every documented attribute exists per channel (spec §1.2, §2.2, §3.2).
$documented = array(
    'google' => array(
        'id', 'title', 'structured_title', 'description', 'structured_description', 'link', 'image_link',
        'additional_image_link', 'video_link', 'mobile_link', 'virtual_model_link', 'warranty',
        'cost_of_goods_sold', 'expiration_date', 'availability', 'availability_date', 'price', 'sale_price',
        'sale_price_effective_date', 'unit_pricing_measure', 'unit_pricing_base_measure',
        'auto_pricing_min_price', 'installment', 'subscription_cost', 'loyalty_program',
        'maximum_retail_price', 'finance', 'lease', 'google_product_category', 'product_type', 'brand',
        'gtin', 'mpn', 'identifier_exists', 'condition', 'adult', 'age_group', 'gender', 'color', 'size',
        'size_type', 'size_system', 'material', 'pattern', 'item_group_id', 'item_group_title', 'multipack',
        'is_bundle', 'certification', 'energy_efficiency_class', 'energy_efficiency_class_min',
        'energy_efficiency_class_max', 'product_detail', 'product_highlight', 'question_and_answer',
        'related_product', 'popularity_rank', 'document_link', 'lifestyle_image_link', 'short_title',
        'product_length', 'product_width', 'product_height', 'product_weight', 'ads_redirect',
        'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3', 'custom_label_4',
        'promotion_id', 'excluded_destination', 'included_destination', 'shopping_ads_excluded_country',
        'pause', 'external_seller_id', 'shipping', 'shipping_weight', 'shipping_length', 'shipping_width',
        'shipping_height', 'max_handling_time', 'min_handling_time', 'ships_from_country',
        'handling_cutoff_time', 'minimum_order_value', 'free_shipping_threshold', 'carrier_shipping',
        'carrier_price', 'carrier_transit_time', 'shipping_label', 'shipping_transit_business_days',
        'shipping_handling_business_days', 'return',
    ),
    'meta' => array(
        'id', 'title', 'description', 'rich_text_description', 'availability', 'condition', 'price', 'link',
        'image_link', 'additional_image_link', 'video_url', 'google_product_category',
        'fb_product_category', 'brand', 'gtin', 'mpn', 'item_group_id', 'color', 'size', 'age_group',
        'gender', 'material', 'pattern', 'additional_variant_attribute', 'sale_price',
        'sale_price_effective_date', 'vendor_id', 'unit_price', 'expiration_date', 'status',
        'internal_label', 'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3',
        'custom_label_4', 'custom_number_0', 'custom_number_1', 'custom_number_2', 'custom_number_3',
        'custom_number_4', 'product_type', 'mobile_link', 'applink', 'shipping', 'shipping_weight',
        'disabled_capabilities', 'quantity_to_sell_on_facebook', 'native_commerce', 'product_disclosures',
        'origin_country', 'importer_name', 'importer_address', 'manufacturer_info', 'wa_compliance_category',
    ),
    'tiktok' => array(
        'sku_id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link',
        'brand', 'google_product_category', 'product_type', 'video_link', 'additional_image_link',
        'age_group', 'color', 'gender', 'item_group_id', 'material', 'pattern', 'size', 'sale_price',
        'sale_price_effective_date', 'shipping', 'shipping_weight', 'gtin', 'mpn', 'tax', 'ios_url',
        'android_url', 'ios_app_store_id', 'ios_app_store_name', 'android_package', 'android_app_name',
        'merchant_brand', 'product_his_eval', 'custom_label_0', 'custom_label_1', 'custom_label_2',
        'custom_label_3', 'custom_label_4',
    ),
);

foreach ($channels as $channel) {
    $fields = $registry::fields($channel);
    check($fields !== array(), "{$channel}: fields() is not empty");
    $missing = array_values(array_diff($documented[$channel], array_keys($fields)));
    check($missing === array(), "{$channel}: every documented attribute exists (missing: " . implode(', ', $missing) . ')');
    $extra = array_values(array_diff(array_keys($fields), $documented[$channel]));
    check($extra === array(), "{$channel}: no attribute outside the documented set (extra: " . implode(', ', $extra) . ')');

    // 3. Field shape, key naming and referenced value validity.
    foreach ($fields as $key => $field) {
        check($key === $field['key'], "{$channel}.{$key}: array key matches the field key");
        check(preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1, "{$channel}.{$key}: key is snake_case");
        check(is_bool($field['required']), "{$channel}.{$key}: required is a boolean");
        check(in_array($field['type'], $allowed_types, true), "{$channel}.{$key}: type is supported");
        check(is_string($field['label']) && $field['label'] !== '', "{$channel}.{$key}: label is non-empty");
        check($field['label'] !== $key, "{$channel}.{$key}: label is translated, not the raw key");
        check(is_string($field['help']) && $field['help'] !== '', "{$channel}.{$key}: help is non-empty");
        check(str_ends_with($field['help'], '.'), "{$channel}.{$key}: help is a sentence (ends with a period)");
        $help_chars = preg_match_all('/./us', $field['help']);
        check(false !== $help_chars && $help_chars <= 200, "{$channel}.{$key}: help stays short");

        if (array_key_exists('enum', $field)) {
            check(is_array($field['enum']) && $field['enum'] !== array(), "{$channel}.{$key}: enum is a non-empty list");
            check(count(array_unique($field['enum'])) === count($field['enum']), "{$channel}.{$key}: enum values are unique");
            foreach ($field['enum'] as $value) {
                check(is_string($value) && $value !== '', "{$channel}.{$key}: enum values are non-empty strings");
            }
            check(in_array($field['type'], array('enum', 'list'), true), "{$channel}.{$key}: enum only appears on enum/list types");
        }

        if (array_key_exists('max_length', $field)) {
            check(is_int($field['max_length']) && $field['max_length'] > 0, "{$channel}.{$key}: max_length is a positive integer");
        }

        if (array_key_exists('repeatable', $field)) {
            check($field['repeatable'] === true, "{$channel}.{$key}: repeatable is only set to true");
        }

        if ($field['required']) {
            check(array_key_exists('default_source', $field), "{$channel}.{$key}: required field has default_source");
            check(is_array($field['default_source']), "{$channel}.{$key}: default_source is an array");
            check(array_keys($field['default_source']) === array('type', 'value'), "{$channel}.{$key}: default_source has type and value");
            check(in_array($field['default_source']['type'], $allowed_sources, true), "{$channel}.{$key}: default_source type is supported");
            check($field['default_source']['value'] !== '', "{$channel}.{$key}: default_source value is non-empty");
            check(source_is_valid($field['default_source']), "{$channel}.{$key}: default_source value is valid for its type");
        }

        $unknown = array_values(array_diff(array_keys($field), $allowed_keys));
        check($unknown === array(), "{$channel}.{$key}: no unknown keys (" . implode(', ', $unknown) . ')');
    }
}

// 4. Required sets are exactly the documented ones (spec §1.2, §2.2, §3.2).
$expected_required = array(
    'google' => array('id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'brand'),
    'meta' => array('id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'additional_image_link', 'google_product_category', 'brand'),
    'tiktok' => array('sku_id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'),
);
foreach ($expected_required as $channel => $expected) {
    $actual = $registry::required_keys($channel);
    $expected_sorted = $expected;
    $actual_sorted = $actual;
    sort($expected_sorted);
    sort($actual_sorted);
    check($actual_sorted === $expected_sorted, "{$channel}: required keys match the documented required set (" . implode(', ', $actual) . ')');
    check(count($actual) === count(array_unique($actual)), "{$channel}: required keys are unique");
}

// 5. Enums and limits match the specification verbatim.
check($registry::get('google', 'availability')['enum'] === array('in_stock', 'out_of_stock', 'preorder', 'backorder'), 'google: availability has the four documented values');
check($registry::get('google', 'condition')['enum'] === array('new', 'refurbished', 'used'), 'google: condition has three values');
check($registry::get('google', 'age_group')['enum'] === array('newborn', 'infant', 'toddler', 'kids', 'adult'), 'google: age_group has five values');
check($registry::get('google', 'identifier_exists')['enum'] === array('yes', 'no'), 'google: identifier_exists is yes/no');
check($registry::get('google', 'size_type')['enum'] === array('regular', 'petite', 'maternity', 'big', 'tall', 'plus'), 'google: size_type has six values');
check($registry::get('meta', 'availability')['enum'] === array('in stock', 'out of stock'), 'meta: availability has exactly two US English values');
check($registry::get('meta', 'age_group')['enum'] === array('adult', 'all ages', 'teen', 'kids', 'toddler', 'infant', 'newborn'), 'meta: age_group matches the documented order');
check($registry::get('meta', 'gender')['enum'] === array('female', 'male', 'unisex'), 'meta: gender has three values');
check($registry::get('meta', 'status')['enum'] === array('active', 'archived'), 'meta: status is the renamed visibility enum');
check($registry::get('tiktok', 'availability')['enum'] === array('in stock', 'available for order', 'preorder', 'out of stock', 'discontinued'), 'tiktok: availability has five space-separated values');
check($registry::get('tiktok', 'condition')['enum'] === array('new', 'refurbished', 'used'), 'tiktok: condition has three values');
check($registry::get('tiktok', 'gender')['enum'] === array('male', 'female', 'unisex'), 'tiktok: gender has three values');

check($registry::get('google', 'id')['max_length'] === 50, 'google: id limit is 50');
check($registry::get('google', 'title')['max_length'] === 150, 'google: title limit is 150');
check($registry::get('google', 'description')['max_length'] === 5000, 'google: description limit is 5000');
check($registry::get('google', 'brand')['max_length'] === 70, 'google: brand limit is 70');
check($registry::get('google', 'product_type')['max_length'] === 750, 'google: product_type limit is 750');
check($registry::get('google', 'custom_label_0')['max_length'] === 100, 'google: custom_label limit is 100');
check($registry::get('google', 'item_group_id')['max_length'] === 50, 'google: item_group_id limit is 50');
check($registry::get('google', 'gtin')['max_length'] === 50, 'google: gtin limit is 50');
check($registry::get('google', 'mpn')['max_length'] === 70, 'google: mpn limit is 70');
check($registry::get('meta', 'id')['max_length'] === 100, 'meta: id limit is 100');
check($registry::get('meta', 'title')['max_length'] === 200, 'meta: title limit is 200');
check($registry::get('meta', 'description')['max_length'] === 9999, 'meta: description limit is 9999');
check($registry::get('meta', 'brand')['max_length'] === 100, 'meta: brand limit is 100');
check($registry::get('meta', 'mpn')['max_length'] === 100, 'meta: mpn limit is 100');
check($registry::get('meta', 'item_group_id')['max_length'] === 100, 'meta: item_group_id limit is 100');
check($registry::get('meta', 'internal_label')['max_length'] === 110, 'meta: internal_label limit is 110 characters per label');
check($registry::get('meta', 'custom_label_0')['max_length'] === 100, 'meta: custom_label limit is 100');
check($registry::get('meta', 'additional_image_link')['required'] === true, 'meta: additional_image_link is required in the official table');
check($registry::get('tiktok', 'custom_label_0')['max_length'] === 100, 'tiktok: custom_label limit is 100');

// 6. Types and default sources for the documented mappings.
check($registry::get('google', 'price')['type'] === 'price', 'google: price is a price field');
check($registry::get('google', 'link')['type'] === 'url', 'google: link is a url field');
check($registry::get('google', 'image_link')['type'] === 'url', 'google: image_link is a url field');
check($registry::get('google', 'additional_image_link')['type'] === 'list', 'google: additional_image_link is a list');
check($registry::get('meta', 'internal_label')['type'] === 'list', 'meta: internal_label is a list, not numbered slots');
check($registry::get('meta', 'native_commerce')['type'] === 'boolean', 'meta: native_commerce is a boolean');
check($registry::get('tiktok', 'shipping_weight')['type'] === 'text', 'tiktok: shipping_weight carries value plus unit');

$expected_sources = array(
    array('google', 'id', 'native', 'feed_item_id'),
    array('google', 'title', 'native', 'title'),
    array('google', 'description', 'native', 'description'),
    array('google', 'price', 'native', 'regular_price'),
    array('google', 'link', 'native', 'permalink'),
    array('google', 'image_link', 'native', 'image'),
    array('google', 'additional_image_link', 'native', 'gallery'),
    array('google', 'availability', 'native', 'stock_status'),
    array('google', 'brand', 'native', 'brand'),
    array('google', 'product_type', 'native', 'category_path'),
    array('google', 'google_product_category', 'category_map', 'google_product_category'),
    array('google', 'gtin', 'meta', '_adc_feed_gtin'),
    array('google', 'condition', 'constant', 'new'),
    array('meta', 'id', 'native', 'feed_item_id'),
    array('meta', 'title', 'native', 'title'),
    array('meta', 'price', 'native', 'regular_price'),
    array('meta', 'availability', 'native', 'stock_status'),
    array('meta', 'additional_image_link', 'native', 'gallery'),
    array('meta', 'condition', 'native', 'condition'),
    array('meta', 'google_product_category', 'category_map', 'google_product_category'),
    array('tiktok', 'sku_id', 'native', 'sku'),
    array('tiktok', 'title', 'native', 'title'),
    array('tiktok', 'price', 'native', 'regular_price'),
    array('tiktok', 'condition', 'native', 'condition'),
    array('tiktok', 'link', 'native', 'permalink'),
    array('tiktok', 'image_link', 'native', 'image'),
    array('tiktok', 'brand', 'native', 'brand'),
);
foreach ($expected_sources as $case) {
    $source = $registry::get($case[0], $case[1])['default_source'] ?? null;
    check(
        is_array($source) && $source['type'] === $case[2] && $source['value'] === $case[3],
        "{$case[0]}.{$case[1]}: default_source is {$case[2]}/{$case[3]}"
    );
}

// 7. Labels are Romanian.
check($registry::get('google', 'condition')['label'] === 'Condiție', 'google: condition label is Romanian');
check($registry::get('google', 'shipping')['label'] === 'Livrare', 'google: shipping label is Romanian');
check($registry::get('meta', 'internal_label')['label'] === 'Etichete interne', 'meta: internal_label label is Romanian');
check($registry::get('meta', 'additional_image_link')['label'] === 'Imagini secundare', 'meta: additional_image_link label is Romanian');
check($registry::get('tiktok', 'sku_id')['label'] === 'SKU', 'tiktok: sku_id label is SKU');
check($registry::get('tiktok', 'product_his_eval')['help'] !== '' && str_contains($registry::get('tiktok', 'product_his_eval')['help'], 'productHisEval'), 'tiktok: help names the platform field for the renamed key');

// 8. Never-verified limits are flagged in help, not hidden.
$never_verified = 0;
foreach ($channels as $channel) {
    foreach ($registry::fields($channel) as $field) {
        if (preg_match('/neverificat/i', $field['help']) === 1) {
            ++$never_verified;
        }
    }
}
check($never_verified >= 1, 'help texts flag the never-verified TikTok label limit');

// 9. Accessors behave like simple lookups.
check($registry::get('google', 'title') === $registry::fields('google')['title'], 'get() returns the same definition as fields()');
check($registry::get('google', 'inexistent') === null, 'get() returns null for an unknown key');
check($registry::get('olx', 'title') === null, 'get() returns null for an unsupported channel');
check($registry::fields('olx') === array(), 'fields() returns an empty map for an unsupported channel');
check($registry::required_keys('olx') === array(), 'required_keys() returns an empty list for an unsupported channel');
check($registry::fields('  GOOGLE ') === $registry::fields('google'), 'channel slugs are trimmed and case-insensitive');
check($registry::fields('google') !== array(), 'google catalogue is non-empty');

// 10. Defaults from the registry are directly consumable by the parameter layer.
$params_file = __DIR__ . '/../includes/feeds/class-adc-feed-params.php';
if (is_file($params_file) && !class_exists('ADC_Feed_Params')) {
    require_once $params_file;
}
if (class_exists('ADC_Feed_Params') && method_exists('ADC_Feed_Params', 'defaults_for')) {
    foreach ($channels as $channel) {
        $defaults = ADC_Feed_Params::defaults_for($channel);
        check(
            count($defaults) === count($registry::required_keys($channel)),
            "{$channel}: defaults_for() builds one row per required field"
        );
        $param_errors = ADC_Feed_Params::validate($channel, $defaults);
        check($param_errors === array(), "{$channel}: registry defaults validate in ADC_Feed_Params (" . json_encode($param_errors) . ')');
    }
}

echo "feed-field-registry-test: PASS\n";
