<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function home_url(string $path = ''): string { return 'https://shop.example' . $path; }
function __(string $text, string $domain = ''): string { return $text; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
require_once __DIR__ . '/../includes/feeds/class-adc-feed-field-registry.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-params.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-google.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-meta.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-tiktok.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-writer-rss.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-writer-csv.php';
function platform_writer_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$item = array(
    'feed_item_id' => 'meta-1', 'tracking_item_id' => 'meta-1', 'sku_id' => 'sku-1',
    'title' => 'Ceai, negru', 'description' => 'Ceai organic', 'link' => 'https://shop.example/ceai',
    'image_link' => 'https://shop.example/ceai.jpg', 'availability' => 'in_stock', 'price' => 25.5,
    'currency' => 'RON', 'condition' => 'new', 'brand' => 'Acme', 'weight' => '0.2', 'custom_labels' => array(),
    'additional_image_links' => array(), 'attributes' => array(),
);
foreach (array('meta' => ADC_Feed_Profile_Meta::class, 'tiktok' => ADC_Feed_Profile_Tiktok::class) as $channel => $profile) {
    $xml_path = sys_get_temp_dir() . '/adc-feed-platform-' . bin2hex(random_bytes(8)) . '.xml';
    $xml_writer = new ADC_Feed_Writer_Rss();
    platform_writer_check($xml_writer->open($xml_path, 'Catalog', false, $profile)
        && $xml_writer->write_item($item) && $xml_writer->close(), $channel . ' XML writes');
    $xml = file_get_contents($xml_path);
    $xml_fixture = file_get_contents(__DIR__ . '/fixtures/feeds/' . $channel . '.xml');
    platform_writer_check(is_string($xml) && is_string($xml_fixture) && trim($xml) === trim($xml_fixture), $channel . ' XML matches its golden fixture');
    unlink($xml_path);

    $csv_path = sys_get_temp_dir() . '/adc-feed-platform-' . bin2hex(random_bytes(8)) . '.csv';
    $csv_writer = new ADC_Feed_Writer_Csv();
    platform_writer_check($csv_writer->open($csv_path, $profile)
        && $csv_writer->write_item($item) && $csv_writer->close(), $channel . ' CSV writes with header');
    $csv = file_get_contents($csv_path);
    $csv_fixture = file_get_contents(__DIR__ . '/fixtures/feeds/' . $channel . '.csv');
    platform_writer_check(is_string($csv) && is_string($csv_fixture) && $csv === $csv_fixture, $channel . ' CSV matches its UTF-8 golden fixture');
    unlink($csv_path);
}

foreach (array(
    'google' => ADC_Feed_Profile_Google::class,
    'meta' => ADC_Feed_Profile_Meta::class,
    'tiktok' => ADC_Feed_Profile_Tiktok::class,
) as $channel => $profile) {
    $params = ADC_Feed_Params::defaults_for($channel);
    $params[] = array('field' => 'sale_price', 'source' => array('type' => 'constant', 'value' => '5'), 'transforms' => array(), 'enabled' => true);
    $mapped = ADC_Feed_Params::apply(array_replace($item, array('sale_price' => null)), $params, $channel);
    $serialized = $profile::map_item($mapped);
    platform_writer_check(($serialized['sale_price'] ?? '') === '5.00 RON', $channel . ' optional sale_price constant uses profile money serialization without duplicating currency');
}

$typed_meta_params = ADC_Feed_Params::defaults_for('meta');
foreach (array(
    array('native_commerce', 'true'),
    array('internal_label', 'seasonal'),
    array('status', 'active'),
) as [$field, $value]) {
    $typed_meta_params[] = array('field' => $field, 'source' => array('type' => 'constant', 'value' => $value), 'transforms' => array(), 'enabled' => true);
}
$typed_meta_item = ADC_Feed_Params::apply($item, $typed_meta_params, 'meta');
$typed_meta = ADC_Feed_Profile_Meta::map_item($typed_meta_item, array('smart_labels' => array('stored')));
platform_writer_check(($typed_meta['internal_label'] ?? array()) === array('seasonal', 'stored'), 'automatic Smart label is appended without replacing configured Meta labels');
platform_writer_check(($typed_meta['native_commerce'] ?? '') === 'yes', 'boolean extras normalize to the platform yes/no representation');
platform_writer_check(($typed_meta['internal_label'] ?? array()) === array('seasonal', 'stored'), 'list extras serialize as a list value and retain automatic labels');
platform_writer_check(($typed_meta['status'] ?? '') === 'active', 'enum extras remain scalar registry values');

$meta_params = ADC_Feed_Params::defaults_for('meta');
$meta_params[] = array('field' => 'color', 'source' => array('type' => 'constant', 'value' => 'Navy'), 'transforms' => array(), 'enabled' => true);
$mapped_params_item = ADC_Feed_Params::apply(array_replace($item, array('regular_price' => 25.5, 'sale_price' => null)), $meta_params, 'meta');
$parameter_xml_path = sys_get_temp_dir() . '/adc-feed-params-' . bin2hex(random_bytes(8)) . '.xml';
$parameter_xml = new ADC_Feed_Writer_Rss();
platform_writer_check($parameter_xml->open($parameter_xml_path, 'Catalog', false, ADC_Feed_Profile_Meta::class)
    && $parameter_xml->write_item($mapped_params_item) && $parameter_xml->close()
    && str_contains((string) file_get_contents($parameter_xml_path), '<g:color>Navy</g:color>'), 'XML writer emits a supported configured extra field');
unlink($parameter_xml_path);
$parameter_csv_path = sys_get_temp_dir() . '/adc-feed-params-' . bin2hex(random_bytes(8)) . '.csv';
$parameter_csv = new ADC_Feed_Writer_Csv();
platform_writer_check($parameter_csv->open($parameter_csv_path, ADC_Feed_Profile_Meta::class, array('params' => $meta_params))
    && $parameter_csv->write_item($mapped_params_item) && $parameter_csv->close(), 'CSV writer accepts configured parameters');
$parameter_csv_data = file_get_contents($parameter_csv_path);
platform_writer_check(is_string($parameter_csv_data) && str_contains($parameter_csv_data, 'color') && str_contains($parameter_csv_data, 'Navy'), 'CSV writer emits the configured field column and value');
unlink($parameter_csv_path);

$unsafe_path = sys_get_temp_dir() . '/adc-feed-csv-safe-' . bin2hex(random_bytes(8)) . '.csv';
$safe_writer = new ADC_Feed_Writer_Csv();
$unsafe_values = array('=1+1', "\t=1+1", "\x01+SUM(1,1)", "\u{00A0}@SUM(1,1)", "\x7F-1");
$default_safe_ok = $safe_writer->open($unsafe_path, ADC_Feed_Profile_Tiktok::class);
foreach ($unsafe_values as $unsafe_value) {
    $default_safe_ok = $default_safe_ok && $safe_writer->write_item(array_replace($item, array('extra' => array('color' => $unsafe_value))));
}
$default_safe_ok = $default_safe_ok && $safe_writer->close();
$unsafe_csv = file_get_contents($unsafe_path);
platform_writer_check($default_safe_ok && is_string($unsafe_csv), 'CSV writer defaults to spreadsheet-safe mode when the config key is missing');
foreach ($unsafe_values as $unsafe_value) {
    platform_writer_check(str_contains((string) $unsafe_csv, "'" . $unsafe_value), 'formula marker is prefixed after leading whitespace/control characters: ' . bin2hex($unsafe_value));
}
unlink($unsafe_path);
$optout_path = sys_get_temp_dir() . '/adc-feed-csv-optout-' . bin2hex(random_bytes(8)) . '.csv';
$optout_writer = new ADC_Feed_Writer_Csv();
platform_writer_check($optout_writer->open($optout_path, ADC_Feed_Profile_Tiktok::class, array('csv_excel_safe' => false))
    && $optout_writer->write_item(array_replace($item, array('title' => '=1+1'))) && $optout_writer->close(), 'an explicit csv_excel_safe=false remains an opt-out');
$optout_csv = file_get_contents($optout_path);
platform_writer_check(is_string($optout_csv) && str_contains($optout_csv, '=1+1') && !str_contains($optout_csv, "'=1+1"), 'explicit spreadsheet protection opt-out preserves raw value');
unlink($optout_path);
echo "feed-platform-writer-test: PASS\n";
