<?php
declare(strict_types=1);

require __DIR__ . '/../includes/feeds/class-adc-feed-taxonomy.php';

function taxonomy_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$valid = "# Google_Product_Taxonomy_Version: 2025-01-06\n"
    . "1 - Îmbrăcăminte și accesorii > Îmbrăcăminte\n"
    . "2 - Script <script>alert(1)</script> ca text\n";
$parsed = ADC_Feed_Taxonomy::parse($valid);
taxonomy_check($parsed['ok'] && $parsed['version'] === '2025-01-06'
    && $parsed['items'][0] === array('id' => '1', 'path' => 'Îmbrăcăminte și accesorii > Îmbrăcăminte'),
    'valid Romanian fixture returns its version and validated paths');
taxonomy_check($parsed['items'][1]['path'] === 'Script <script>alert(1)</script> ca text',
    'script-like path is preserved as text without any HTML emission');
$bom_parsed = ADC_Feed_Taxonomy::parse("\xEF\xBB\xBF" . $valid);
taxonomy_check($bom_parsed['ok'] && $bom_parsed['version'] === '2025-01-06', 'UTF-8 BOM is accepted only before the file header');
taxonomy_check(ADC_Feed_Taxonomy::search($parsed, 'ÎMBRĂCĂMINTE')[0]['id'] === '1',
    'search is case-insensitive for Romanian diacritics');

$bad_header = ADC_Feed_Taxonomy::parse("# wrong version\n1 - Path\n");
taxonomy_check(!$bad_header['ok'] && $bad_header['error'] === 'invalid_header' && $bad_header['items'] === array(),
    'malformed header returns a structured error and no partial items');
$duplicate = ADC_Feed_Taxonomy::parse("# Google_Product_Taxonomy_Version: 2025-01-06\n1 - First\n1 - Second\n");
taxonomy_check(!$duplicate['ok'] && $duplicate['error'] === 'duplicate_id' && $duplicate['items'] === array(),
    'duplicate IDs reject the full input without partial data');
$leading_zero = ADC_Feed_Taxonomy::parse("# Google_Product_Taxonomy_Version: 2025-01-06\n1 - First\n01 - Alias\n");
taxonomy_check(!$leading_zero['ok'] && $leading_zero['error'] === 'invalid_row' && $leading_zero['items'] === array(),
    'leading-zero numeric IDs are rejected to prevent duplicate aliases');
$invalid_utf8 = ADC_Feed_Taxonomy::parse("# Google_Product_Taxonomy_Version: 2025-01-06\n1 - \xC3\x28\n");
taxonomy_check(!$invalid_utf8['ok'] && $invalid_utf8['error'] === 'invalid_utf8', 'malformed UTF-8 is rejected');
$oversized = ADC_Feed_Taxonomy::parse(str_repeat('x', 2097153));
taxonomy_check(!$oversized['ok'] && $oversized['error'] === 'input_too_large', 'input above 2 MiB is rejected');
$too_many = "# Google_Product_Taxonomy_Version: 2025-01-06\n";
for ($index = 1; $index <= 10001; ++$index) {
    $too_many .= $index . " - Path " . $index . "\n";
}
$too_many_result = ADC_Feed_Taxonomy::parse($too_many);
taxonomy_check(!$too_many_result['ok'] && $too_many_result['error'] === 'too_many_rows'
    && $too_many_result['items'] === array(), 'more than 10000 rows reject the input without partial acceptance');
$search_input = "# Google_Product_Taxonomy_Version: 2025-01-06\n";
for ($index = 1; $index <= 60; ++$index) {
    $search_input .= $index . " - Categorie test " . $index . "\n";
}
$search_result = ADC_Feed_Taxonomy::search(ADC_Feed_Taxonomy::parse($search_input), 'CATEGORIE TEST', 60);
taxonomy_check(count($search_result) === 50 && $search_result[0]['id'] === '1' && $search_result[49]['id'] === '50',
    'search is case-insensitive and capped at 50 results');

echo "feed-taxonomy-test: PASS\n";
