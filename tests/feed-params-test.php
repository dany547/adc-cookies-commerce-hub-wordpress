<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function __(string $text, string $domain = ''): string { return $text; }
function taxonomy_exists(string $taxonomy): bool { return 'product_brand' === $taxonomy; }
function wc_get_price_decimals(): int { return 2; }

final class ADC_Feed_Field_Registry
{
    public static function channels(): array { return array('google', 'meta', 'tiktok'); }
    public static function fields(string $channel): array
    {
        $required = match ($channel) {
            'google' => array('id', 'title', 'description', 'link', 'image_link', 'availability', 'price'),
            'meta' => array('id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'additional_image_link', 'google_product_category'),
            'tiktok' => array('sku_id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link'),
            default => array(),
        };
        $fields = array();
        foreach ($required as $key) {
            $source = match ($key) {
                'id' => array('type' => 'native', 'value' => 'feed_item_id'),
                'sku_id' => array('type' => 'native', 'value' => 'sku'),
                'availability' => array('type' => 'native', 'value' => 'stock_status'),
                'condition' => array('type' => 'native', 'value' => 'condition'),
                'price' => array('type' => 'native', 'value' => 'regular_price'),
                'link' => array('type' => 'native', 'value' => 'permalink'),
                'image_link' => array('type' => 'native', 'value' => 'image'),
                'additional_image_link' => array('type' => 'native', 'value' => 'gallery'),
                'google_product_category' => array('type' => 'category_map', 'value' => 'google_product_category'),
                default => array('type' => 'native', 'value' => $key),
            };
            $fields[$key] = array('key' => $key, 'required' => true, 'type' => in_array($key, array('availability', 'condition'), true) ? 'enum' : (str_contains($key, 'image') && 'image_link' !== $key ? 'list' : 'text'), 'enum' => 'availability' === $key ? match ($channel) { 'meta' => array('in stock', 'out of stock'), 'tiktok' => array('in stock', 'available for order', 'preorder', 'out of stock', 'discontinued'), default => array('in_stock', 'out_of_stock', 'preorder', 'backorder') } : ('condition' === $key ? array('new', 'refurbished', 'used') : array()), 'max_length' => 'title' === $key ? 150 : 0, 'default_source' => $source);
            if (0 === $fields[$key]['max_length']) { unset($fields[$key]['max_length']); }
            if (empty($fields[$key]['enum'])) { unset($fields[$key]['enum']); }
        }
        $fields['additional_image_link'] ??= array('key' => 'additional_image_link', 'required' => false, 'type' => 'list', 'repeatable' => true);
        $fields['additional_image_link']['repeatable'] = true;
        $fields['product_type'] = array('key' => 'product_type', 'required' => false, 'type' => 'text', 'max_length' => 750);
        $fields['video_link'] = array('key' => 'video_link', 'required' => false, 'type' => 'url', 'repeatable' => true);
        $fields['taxonomy_test'] = array('key' => 'taxonomy_test', 'required' => false, 'type' => 'text');
        return $fields;
    }
    public static function required_keys(string $channel): array
    {
        return array_values(array_map(static fn(array $field): string => $field['key'], array_filter(self::fields($channel), static fn(array $field): bool => !empty($field['required']))));
    }
    public static function get(string $channel, string $key): ?array { return self::fields($channel)[$key] ?? null; }
}
final class ADC_Feed_Channels
{
    public static function get(string $channel): ?array
    {
        return in_array($channel, ADC_Feed_Field_Registry::channels(), true)
            ? array('profile' => 'Params_Preview_Profile', 'validator' => 'Params_Preview_Validator')
            : null;
    }
}
final class ADC_Feed_Catalog
{
    public static function items(array $config, int $offset, int $limit): array
    {
        $GLOBALS['params_preview_catalog_calls'] = ($GLOBALS['params_preview_catalog_calls'] ?? 0) + 1;
        return array(array(
            'feed_item_id' => 'p-1', 'sku_id' => 'sku-1', 'title' => 'Green tea', 'description' => 'Leaf tea',
            'link' => 'https://shop.test/tea', 'image_link' => 'https://shop.test/tea.jpg',
            'additional_image_links' => array('https://shop.test/tea-2.jpg'), 'availability' => 'in_stock',
            'price' => 10, 'sale_price' => null, 'currency' => 'RON', 'condition' => 'new', 'brand' => 'TeaCo',
            'google_product_category' => '123', 'attributes' => array(),
            '_source_values' => array('feed_item_id' => 'p-1', 'sku' => 'sku-1', 'title' => 'Green tea', 'description' => 'Leaf tea',
                'permalink' => 'https://shop.test/tea', 'image' => 'https://shop.test/tea.jpg', 'gallery' => array('https://shop.test/tea-2.jpg'),
                'regular_price' => 10, 'stock_status' => 'in_stock', 'brand' => 'TeaCo', 'category_map' => '123'),
        ));
    }
}
final class Params_Preview_Profile
{
    public static function map_item(array $item, array $config = array()): array
    {
        return array_merge(array('title' => $item['title'] ?? '', 'id' => $item['feed_item_id'] ?? ''), $item['extra'] ?? array());
    }
}
final class Params_Preview_Validator
{
    public static function validate(array $item, array $config = array()): array
    {
        return array(array('level' => 'warning', 'field' => 'preview', 'code' => 'preview_warning', 'message' => 'warning'));
    }
}
require_once __DIR__ . '/../includes/feeds/class-adc-feed-params.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-generator.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-store.php';
function params_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

foreach (ADC_Feed_Field_Registry::channels() as $channel) {
    $defaults = ADC_Feed_Params::defaults_for($channel);
    params_check(array_column($defaults, 'field') === ADC_Feed_Field_Registry::required_keys($channel), $channel . ' defaults include exactly each required registry field');
    params_check(ADC_Feed_Params::validate($channel, $defaults) === array(), $channel . ' defaults validate');
}
params_check(array_column(ADC_Feed_Params::native_sources(), 'value') === array(
    'title', 'description', 'short_description', 'sku', 'permalink', 'regular_price', 'sale_price', 'price',
    'image', 'gallery', 'brand', 'stock_status', 'stock_qty', 'weight', 'category_path', 'feed_item_id', 'gtin', 'mpn', 'condition',
), 'native source list exposed to REST matches the PHP parameter registry');

$rest_style = ADC_Feed_Params::defaults_for('meta');
$rest_style[] = array('field' => 'product_type', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(array('op' => 'replace', 'arg' => 'red→blue'), array('op' => 'map', 'arg' => '{"blue":"navy"}')), 'enabled' => true);
params_check(ADC_Feed_Params::validate('meta', $rest_style) === array(), 'REST-style string transform arguments and native aliases validate');

$google = ADC_Feed_Params::defaults_for('google');
foreach ($google as &$row) {
    if ('title' === $row['field']) {
        $row['source'] = array('type' => 'constant', 'value' => '<b> Tea </b>');
        $row['transforms'] = array(
            array('op' => 'strip_html'), array('op' => 'trim'), array('op' => 'uppercase'),
            array('op' => 'prefix', 'arg' => 'Offer: '), array('op' => 'suffix', 'arg' => '!'),
        );
    }
}
unset($row);
$google[] = array('field' => 'product_type', 'source' => array('type' => 'template', 'value' => '{title} - {sku}'), 'transforms' => array(), 'enabled' => true);
$applied = ADC_Feed_Params::apply(array('feed_item_id' => 'p-1', 'title' => 'original', 'availability' => 'in_stock', 'attributes' => array(), '_source_values' => array('sku' => 'sku-1', 'title' => 'Green tea')), $google, 'google');
params_check(($applied['title'] ?? '') === 'Offer: TEA!', 'ordered transforms run without evaluating code and required values override the normalized item');
params_check(($applied['extra']['product_type'] ?? '') === 'Green tea - sku-1', 'safe native placeholders are expanded in a template and optional values land in extra');
$source_values = array(
    'attribute:pa_color' => 'Green', 'taxonomy:product_brand' => 'TeaCo', 'meta:_adc_feed_tagline' => 'Herbal',
    'category_map' => '1234', 'title' => 'Green tea', 'sku' => 'sku-1',
);
foreach (array(
    array('attribute', 'pa_color', 'Green'), array('taxonomy', 'product_brand', 'TeaCo'),
    array('meta', '_adc_feed_tagline', 'Herbal'), array('category_map', 'google_product_category', '1234'),
) as [$source_type, $source_value, $expected_value]) {
    $params = ADC_Feed_Params::defaults_for('google');
    $params[] = array('field' => 'product_type', 'source' => array('type' => $source_type, 'value' => $source_value), 'transforms' => array(), 'enabled' => true);
    $source_result = ADC_Feed_Params::apply(array('attributes' => array(), '_source_values' => $source_values), $params, 'google');
    params_check(($source_result['extra']['product_type'] ?? '') === $expected_value, 'resolves declared ' . $source_type . ' source from the normalized catalog row');
}
$params = ADC_Feed_Params::defaults_for('google');
$params[] = array('field' => 'product_type', 'source' => array('type' => 'constant', 'value' => str_repeat('x', 751)), 'transforms' => array(), 'enabled' => true);
$oversized = ADC_Feed_Params::apply(array('_source_values' => array()), $params, 'google');
params_check(in_array('param_value_too_long', $oversized['param_warnings'] ?? array(), true), 'registry field maximum length is checked at application time');

$duplicate = $google;
$duplicate[] = array('field' => 'title', 'source' => array('type' => 'native', 'value' => 'title'), 'transforms' => array(), 'enabled' => true);
params_check(in_array('param_field_duplicate', array_column(ADC_Feed_Params::validate('google', $duplicate), 'code'), true), 'non-repeatable duplicate parameter is rejected');
$repeatable = ADC_Feed_Params::defaults_for('meta');
$repeatable[] = array('field' => 'additional_image_link', 'source' => array('type' => 'native', 'value' => 'gallery'), 'transforms' => array(), 'enabled' => true);
params_check(ADC_Feed_Params::validate('meta', $repeatable) === array(), 'registry repeatable fields allow duplicate parameter rows');

foreach (array('_wp_nonce', 'user_password', 'api_secret', 'access_token', 'session_id', 'private_key') as $blocked_key) {
    $rows = ADC_Feed_Params::defaults_for('google');
    $rows[] = array('field' => 'product_type', 'source' => array('type' => 'meta', 'value' => $blocked_key), 'transforms' => array(), 'enabled' => true);
    params_check(in_array('param_source_invalid', array_column(ADC_Feed_Params::validate('google', $rows), 'code'), true), 'credential-like product meta key is denied: ' . $blocked_key);
}
$bad_template = ADC_Feed_Params::defaults_for('google');
$bad_template[] = array('field' => 'product_type', 'source' => array('type' => 'template', 'value' => '{title}{secret}'), 'transforms' => array(), 'enabled' => true);
params_check(in_array('param_source_invalid', array_column(ADC_Feed_Params::validate('google', $bad_template), 'code'), true), 'template placeholders outside the native allowlist are rejected');
$bad_taxonomy = ADC_Feed_Params::defaults_for('google');
$bad_taxonomy[] = array('field' => 'product_type', 'source' => array('type' => 'taxonomy', 'value' => 'missing_taxonomy'), 'transforms' => array(), 'enabled' => true);
params_check(in_array('param_source_invalid', array_column(ADC_Feed_Params::validate('google', $bad_taxonomy), 'code'), true), 'taxonomy source must name an existing taxonomy');

$hydrate = new ReflectionMethod(ADC_Feed_Store::class, 'hydrate');
$hydrate->setAccessible(true);
$legacy_feed = $hydrate->invoke(null, array('id' => 15, 'channel' => 'meta', 'config_json' => '{"scope":"all","meta":{"condition":"used"}}'));
params_check(array_column($legacy_feed['config']['params'] ?? array(), 'field') === ADC_Feed_Field_Registry::required_keys('meta'), 'legacy feed config receives implicit required parameter defaults on read');
foreach (array('used', 'refurbished') as $condition) {
    $legacy_item = array('condition' => $condition, '_source_values' => array());
    $before_hydration = ADC_Feed_Params::apply($legacy_item, ADC_Feed_Params::defaults_for('meta'), 'meta');
    $after_hydration = ADC_Feed_Params::apply($legacy_item, $legacy_feed['config']['params'], 'meta');
    params_check(($before_hydration['condition'] ?? '') === $condition && ($after_hydration['condition'] ?? '') === $condition,
        'Meta ' . $condition . ' condition is preserved before and after legacy parameter hydration');
}
$config_version = (new ReflectionClass(ADC_Feed_Store::class))->getConstant('CONFIG_VERSION');
params_check($config_version === 3, 'feed config schema version is bumped for Smart Feed settings');
$sanitize_config = new ReflectionMethod(ADC_Feed_Store::class, 'sanitize_config');
$sanitize_config->setAccessible(true);
$sanitized = $sanitize_config->invoke(null, array('params' => array(array('field' => 'title', 'transforms' => array(array('op' => 'replace', 'arg' => array('from' => 'a', 'to' => 'b')))))));
params_check(($sanitized['params'][0]['transforms'][0]['arg']['from'] ?? '') === 'a', 'nested transform arguments survive recursive config sanitization');

$preview_config = array('channel' => 'google', 'params' => ADC_Feed_Params::defaults_for('google'));
$preview_config['params'][] = array('field' => 'product_type', 'source' => array('type' => 'constant', 'value' => 'Organic'), 'transforms' => array(), 'enabled' => true);
$before_preview = (int) ($GLOBALS['params_preview_catalog_calls'] ?? 0);
$preview = ADC_Feed_Generator::preview($preview_config, 2);
params_check(count($preview['rows']) === 1 && ($preview['rows'][0]['product_type'] ?? '') === 'Organic', 'preview returns mapped sample rows with optional fields');
params_check(in_array('preview_warning', $preview['warnings'], true) && (int) $GLOBALS['params_preview_catalog_calls'] === $before_preview + 1, 'preview includes validator warnings and only reads catalog data without generating a snapshot');

echo "feed-params-test: PASS\n";
