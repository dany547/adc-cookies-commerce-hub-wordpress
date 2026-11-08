<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function home_url(string $path = ''): string { return 'https://shop.example' . $path; }
function wc_get_price_decimals(): int { return 2; }
function get_option(string $key, mixed $default = false): mixed { return 'woocommerce_weight_unit' === $key ? 'kg' : $default; }
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-google.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-writer-rss.php';
function writer_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$path = sys_get_temp_dir() . '/adc-feed-writer-' . bin2hex(random_bytes(8)) . '.xml';
$item = array(
    'feed_item_id' => 'sku-1',
    'title' => 'Cafe & Tea',
    'description' => 'Tea & coffee',
    'link' => 'https://shop.example/product/tea',
    'image_link' => 'https://shop.example/uploads/tea.jpg',
    'availability' => 'in_stock',
    'price' => 25.5,
    'currency' => 'RON',
    'condition' => 'new',
    'shipping' => array(array('country' => 'RO', 'service' => 'Curier', 'price' => '15.00')),
    'custom_labels' => array(),
    'additional_image_links' => array(),
);
$writer = new ADC_Feed_Writer_Rss();
writer_check($writer->open($path, 'Catalog Demo'), 'writer opens a streaming target');
writer_check($writer->write_item($item), 'normalized product writes successfully');
writer_check($writer->close(), 'writer closes and flushes');
$actual = file_get_contents($path);
$expected = file_get_contents(__DIR__ . '/fixtures/google-feed-rss.xml');
writer_check(is_string($actual) && is_string($expected) && trim($actual) === trim($expected), 'RSS output matches the XML fixture');
writer_check(str_contains((string) $actual, '<g:price>25.50 RON</g:price>'), 'Google price includes the ISO currency');
writer_check(str_contains((string) $actual, 'Cafe &amp; Tea'), 'XML special characters are escaped exactly once');
writer_check(ADC_Feed_Profile_Google::validate_config(array('country' => 'RO', 'shipping' => array())) === array('shipping_required_for_ro'), 'Romanian shipping configuration is required');
writer_check(ADC_Feed_Profile_Google::validate_config(array('country' => 'RO', 'shipping' => array('country' => 'RO', 'price' => '15.00'))) === array(), 'configured Romanian shipping passes profile validation');

$conditional_item = $item + array(
    'certification' => array(
        'certification_authority' => 'European_Commission',
        'certification_name' => 'EPREL',
        'certification_code' => '123456',
    ),
    'unit_pricing_measure' => '100 ml',
    'unit_pricing_base_measure' => '1 l',
);
$conditional_path = sys_get_temp_dir() . '/adc-feed-writer-conditional-' . bin2hex(random_bytes(8)) . '.xml';
$conditional_writer = new ADC_Feed_Writer_Rss();
writer_check($conditional_writer->open($conditional_path, 'Catalog Demo')
    && $conditional_writer->write_item($conditional_item)
    && $conditional_writer->close(), 'conditional Google attributes serialize');
$conditional_xml = file_get_contents($conditional_path);
writer_check(is_string($conditional_xml)
    && str_contains($conditional_xml, '<g:certification><g:certification_authority>European_Commission</g:certification_authority>')
    && str_contains($conditional_xml, '<g:unit_pricing_measure>100 ml</g:unit_pricing_measure>'), 'certification and unit-pricing attributes are emitted only when present');
unlink($conditional_path);

// This test owns a unique temp file.
unlink($path);
echo "feed-writer-test: PASS\n";
