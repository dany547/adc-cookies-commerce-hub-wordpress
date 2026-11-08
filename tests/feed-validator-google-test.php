<?php
/**
 * Offline tests for ADC_Feed_Validator_Google.
 *
 * Runs without WordPress: only the gettext helper and the validator class are needed.
 *
 * Usage: php tests/feed-validator-google-test.php
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

require_once __DIR__ . '/../includes/feeds/class-adc-feed-validator-google.php';

/** Sentinel used to remove a key from the fixture row. */
const ADC_TEST_UNSET = '__adc_unset__';

/**
 * Build a normalized catalog row; overrides patch or remove single keys.
 *
 * @param array<string,mixed> $overrides Key => value, or ADC_TEST_UNSET to drop.
 * @return array<string,mixed>
 */
function google_item(array $overrides = array()): array
{
    $item = array(
        'feed_item_id'            => '12345',
        'item_group_id'           => '900',
        'title'                   => 'Ceai verde organic 100 g',
        'description'             => 'Ceai verde organic, 100 g, ambalaj resigilabil.',
        'link'                    => 'https://example.test/produs/ceai-verde',
        'image_link'              => 'https://example.test/img/ceai-verde.jpg',
        'additional_image_links'  => array('https://example.test/img/ceai-verde-2.jpg'),
        'availability'            => 'in_stock',
        'price'                   => 49.99,
        'sale_price'              => 39.99,
        'currency'                => 'RON',
        'condition'               => 'new',
        'brand'                   => 'Adcelerum',
        'gtin'                    => '4006381333931',
        'mpn'                     => 'CV-100',
        'google_product_category' => 'Food, Beverages & Tobacco > Beverages > Tea & Coffee',
        'product_type'            => 'Băuturi > Ceaiuri',
        'weight'                  => 0.15,
        'attributes'              => array(),
        'shipping'                => array(array('country' => 'RO', 'price' => 19.99)),
        'custom_labels'           => array('top_seller'),
    );

    foreach ($overrides as $key => $value) {
        if ($value === ADC_TEST_UNSET) {
            unset($item[$key]);
            continue;
        }

        $item[$key] = $value;
    }

    return $item;
}

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
 * Extract finding codes.
 *
 * @param array<int,array<string,string>> $findings Findings.
 * @return string[]
 */
function finding_codes(array $findings): array
{
    return array_map(static fn(array $finding): string => (string) $finding['code'], $findings);
}

/**
 * Find the level reported for one code.
 *
 * @param array<int,array<string,string>> $findings Findings.
 * @param string $code Finding code.
 * @return string|null Level, or null when the code is absent.
 */
function level_of(array $findings, string $code): ?string
{
    foreach ($findings as $finding) {
        if ($finding['code'] === $code) {
            return $finding['level'];
        }
    }

    return null;
}

/**
 * Find the field reported for one code.
 *
 * @param array<int,array<string,string>> $findings Findings.
 * @param string $code Finding code.
 * @return string|null Field, or null when the code is absent.
 */
function field_of(array $findings, string $code): ?string
{
    foreach ($findings as $finding) {
        if ($finding['code'] === $code) {
            return $finding['field'];
        }
    }

    return null;
}

/**
 * Assert that one code is reported at the expected level.
 *
 * @param array<int,array<string,string>> $findings Findings.
 * @param string $code Expected code.
 * @param string $level Expected level.
 * @param string $message Failure description.
 * @return void
 */
function expect_finding(array $findings, string $code, string $level, string $message): void
{
    $actual = level_of($findings, $code);
    if ($actual !== $level) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL: %s (expected %s for %s, got %s; codes: %s)\n",
                $message,
                $level,
                $code,
                $actual ?? 'none',
                implode(', ', finding_codes($findings))
            )
        );
        exit(1);
    }
}

/**
 * Assert that a code is absent.
 *
 * @param array<int,array<string,string>> $findings Findings.
 * @param string $code Forbidden code.
 * @param string $message Failure description.
 * @return void
 */
function expect_absent(array $findings, string $code, string $message): void
{
    if (level_of($findings, $code) !== null) {
        fwrite(STDERR, "FAIL: {$message} ({$code} was reported)\n");
        exit(1);
    }
}

/**
 * Assert a row produces no findings at all.
 *
 * @param array<int,array<string,string>> $findings Findings.
 * @param string $message Failure description.
 * @return void
 */
function expect_clean(array $findings, string $message): void
{
    if ($findings !== array()) {
        fwrite(
            STDERR,
            sprintf("FAIL: %s (codes: %s)\n", $message, implode(', ', finding_codes($findings)))
        );
        exit(1);
    }
}

$validator = ADC_Feed_Validator_Google::class;

// 1. The complete row is clean and every finding shape is stable.
$base = google_item();
expect_clean($validator::validate($base), 'un produs complet valid nu produce constatări');
check(
    $validator::summarize(array()) === array('errors' => 0, 'warnings' => 0),
    'summarize raportează zero erori și zero avertismente pentru o listă goală'
);

$mixed = $validator::validate(google_item(array('feed_item_id' => '', 'google_product_category' => ADC_TEST_UNSET)));
check(
    $validator::summarize($mixed) === array('errors' => 1, 'warnings' => 1),
    'summarize numără separat erorile și avertismentele'
);
foreach ($mixed as $finding) {
    check(array_keys($finding) === array('level', 'field', 'code', 'message'), 'fiecare constatare are exact cheile level, field, code, message');
    check(in_array($finding['level'], array('error', 'warning'), true), 'nivelul este error sau warning');
    check(is_string($finding['field']) && $finding['field'] !== '', 'field este un șir nevid');
    check(is_string($finding['code']) && $finding['code'] !== '', 'code este un șir nevid');
    check(is_string($finding['message']) && $finding['message'] !== '', 'mesajul este un text românesc nevid');
}
check(field_of($mixed, 'google_product_category_missing') === 'google_product_category', 'constatarea indică atributul normalizat');
check(field_of($mixed, 'id_missing') === 'feed_item_id', 'lipsa id-ului este raportată pe feed_item_id');

// 2. id: required, 50 characters maximum.
expect_finding($validator::validate(google_item(array('feed_item_id' => ADC_TEST_UNSET))), 'id_missing', 'error', 'id lipsă este eroare');
expect_absent($validator::validate(google_item(array('feed_item_id' => str_repeat('7', 50)))), 'id_too_long', 'id de 50 caractere este acceptat');
expect_finding($validator::validate(google_item(array('feed_item_id' => str_repeat('7', 51)))), 'id_too_long', 'error', 'id de 51 caractere este eroare');

// 3. item_group_id: 50 characters maximum, never required for RO.
expect_absent($validator::validate(google_item(array('item_group_id' => ADC_TEST_UNSET))), 'item_group_id_too_long', 'item_group_id lipsă nu este raportat pentru RO');
expect_absent($validator::validate(google_item(array('item_group_id' => str_repeat('9', 50)))), 'item_group_id_too_long', 'item_group_id de 50 caractere este acceptat');
expect_finding($validator::validate(google_item(array('item_group_id' => str_repeat('9', 51)))), 'item_group_id_too_long', 'error', 'item_group_id de 51 caractere este eroare');

// 4. title: required, 150 characters maximum, plain text.
expect_finding($validator::validate(google_item(array('title' => ADC_TEST_UNSET))), 'title_missing', 'error', 'titlu lipsă este eroare');
expect_absent($validator::validate(google_item(array('title' => str_repeat('ș', 150)))), 'title_too_long', 'titlul de 150 caractere cu diacritice este acceptat');
expect_finding($validator::validate(google_item(array('title' => str_repeat('ș', 151)))), 'title_too_long', 'error', 'titlul de 151 caractere cu diacritice este eroare');
expect_finding($validator::validate(google_item(array('title' => 'Ceai <b>verde</b>'))), 'title_not_plain_text', 'warning', 'HTML în titlu este avertisment');
expect_finding($validator::validate(google_item(array('title' => "Ceai verde\norganic"))), 'title_not_plain_text', 'warning', 'rând nou în titlu este avertisment');

// 5. description: required, 5000 characters maximum, plain text.
expect_finding($validator::validate(google_item(array('description' => ADC_TEST_UNSET))), 'description_missing', 'error', 'descriere lipsă este eroare');
expect_absent($validator::validate(google_item(array('description' => str_repeat('d', 5000)))), 'description_too_long', 'descrierea de 5000 caractere este acceptată');
expect_finding($validator::validate(google_item(array('description' => str_repeat('d', 5001)))), 'description_too_long', 'error', 'descrierea de 5001 caractere este eroare');
expect_finding($validator::validate(google_item(array('description' => '<p>Ceai verde</p>'))), 'description_not_plain_text', 'warning', 'HTML în descriere este avertisment');

// 6. link: required, absolute http(s) URL; https is a recommendation, not a requirement.
expect_finding($validator::validate(google_item(array('link' => ADC_TEST_UNSET))), 'link_missing', 'error', 'link lipsă este eroare');
expect_finding($validator::validate(google_item(array('link' => 'pagina-produs'))), 'link_invalid', 'error', 'link relativ este eroare');
expect_finding($validator::validate(google_item(array('link' => 'ftp://example.test/produs'))), 'link_invalid', 'error', 'link ftp este eroare');
expect_finding($validator::validate(google_item(array('link' => 'http://example.test/produs'))), 'link_https_recommended', 'warning', 'link http este doar avertisment');

// 7. image_link and additional_image_links.
expect_finding($validator::validate(google_item(array('image_link' => ADC_TEST_UNSET))), 'image_link_missing', 'error', 'imagine principală lipsă este eroare');
expect_finding($validator::validate(google_item(array('image_link' => 'ceai.jpg'))), 'image_link_invalid', 'error', 'imagine principală fără URL absolut este eroare');
expect_finding($validator::validate(google_item(array('image_width' => 99, 'image_height' => 300))), 'image_too_small', 'error', 'imaginea de 99 px lățime este eroare');
expect_finding($validator::validate(google_item(array('image_width' => 400, 'image_height' => 500))), 'image_below_upcoming_minimum', 'warning', 'imaginea sub 500 px este avertisment pentru 2027');
expect_absent($validator::validate(google_item(array('image_width' => 100, 'image_height' => 100))), 'image_too_small', 'imaginea de 100×100 px nu este sub minimul dur');
expect_clean($validator::validate(google_item(array('image_width' => 500, 'image_height' => 500))), 'imaginea de 500×500 px nu produce constatări');
expect_absent($validator::validate(google_item(array('image_width' => 0, 'image_height' => 0))), 'image_too_small', 'dimensiunile necunoscute nu sunt raportate');

$ten_images = array();
for ($i = 1; $i <= 10; ++$i) {
    $ten_images[] = 'https://example.test/img/ceai-' . $i . '.jpg';
}
$eleven_images = $ten_images;
$eleven_images[] = 'https://example.test/img/ceai-11.jpg';
expect_finding($validator::validate(google_item(array('additional_image_links' => array()))), 'additional_images_missing', 'warning', 'lipsa imaginilor secundare este avertisment');
expect_absent($validator::validate(google_item(array('additional_image_links' => $ten_images))), 'additional_images_too_many', '10 imagini secundare sunt acceptate');
expect_finding($validator::validate(google_item(array('additional_image_links' => $eleven_images))), 'additional_images_too_many', 'error', '11 imagini secundare sunt eroare');
expect_finding(
    $validator::validate(google_item(array('additional_image_links' => array('https://example.test/ok.jpg', 'https://example.test/ok-2.jpg', 'nu-e-url')))),
    'additional_image_invalid',
    'error',
    'imagine secundară fără URL absolut este eroare'
);
expect_finding(
    $validator::validate(google_item(array('additional_image_links' => array('https://example.test/' . str_repeat('a', 1990) . '.jpg')))),
    'additional_image_url_too_long',
    'error',
    'URL de imagine secundară peste 2000 caractere este eroare'
);

// 8. price, sale_price, currency.
expect_finding($validator::validate(google_item(array('price' => ADC_TEST_UNSET))), 'price_missing', 'error', 'preț lipsă este eroare');
expect_finding($validator::validate(google_item(array('price' => 0))), 'price_invalid', 'error', 'preț zero este eroare');
expect_finding($validator::validate(google_item(array('price' => '15,00'))), 'price_invalid', 'error', 'preț cu virgulă zecimală este eroare');
expect_finding($validator::validate(google_item(array('sale_price' => 59.99))), 'sale_price_above_price', 'error', 'preț redus peste prețul de listă este eroare');
expect_finding($validator::validate(google_item(array('sale_price' => 49.99))), 'sale_price_equals_price', 'warning', 'preț redus egal cu prețul de listă este avertisment');
expect_finding($validator::validate(google_item(array('sale_price' => 0))), 'sale_price_invalid', 'error', 'preț redus zero este eroare');
expect_clean($validator::validate(google_item(array('sale_price' => ADC_TEST_UNSET))), 'lipsa sale_price este acceptată');
expect_finding($validator::validate(google_item(array('currency' => ADC_TEST_UNSET))), 'currency_invalid', 'error', 'monedă lipsă este eroare');
expect_finding($validator::validate(google_item(array('currency' => 'RO'))), 'currency_invalid', 'error', 'cod de monedă din două litere este eroare');
expect_clean($validator::validate(google_item(array('currency' => ADC_TEST_UNSET)), array('currency' => 'RON')), 'moneda din config este folosită ca fallback');

// 9. availability and availability_date.
expect_finding($validator::validate(google_item(array('availability' => ADC_TEST_UNSET))), 'availability_missing', 'error', 'disponibilitate lipsă este eroare');
expect_finding($validator::validate(google_item(array('availability' => 'in stock'))), 'availability_invalid', 'error', 'disponibilitate cu spațiu este eroare');
expect_clean($validator::validate(google_item(array('availability' => 'backorder'))), 'backorder este o valoare acceptată');
expect_clean($validator::validate(google_item(array('availability' => 'out_of_stock'))), 'out_of_stock este o valoare acceptată');
expect_finding($validator::validate(google_item(array('availability' => 'preorder'))), 'availability_date_required', 'error', 'precomandă fără dată este eroare');
expect_clean(
    $validator::validate(google_item(array('availability' => 'preorder', 'availability_date' => '2026-12-01T10:00+0200'))),
    'precomandă cu dată ISO 8601 este acceptată'
);
expect_finding(
    $validator::validate(google_item(array('availability' => 'preorder', 'availability_date' => '01.12.2026'))),
    'availability_date_invalid',
    'error',
    'precomandă cu dată ne-ISO este eroare'
);

// 10. condition.
expect_clean($validator::validate(google_item(array('condition' => ADC_TEST_UNSET))), 'condition lipsă este acceptat pentru produse noi');
expect_clean($validator::validate(google_item(array('condition' => 'refurbished'))), 'condition refurbished este acceptat');
expect_finding($validator::validate(google_item(array('condition' => 'nou'))), 'condition_invalid', 'error', 'condition în română este eroare de enum');

// 11. brand, gtin, mpn and identifier_exists.
expect_finding($validator::validate(google_item(array('brand' => ADC_TEST_UNSET))), 'brand_missing', 'error', 'brand lipsă este eroare');
expect_absent($validator::validate(google_item(array('brand' => str_repeat('B', 70)))), 'brand_too_long', 'brandul de 70 caractere este acceptat');
expect_finding($validator::validate(google_item(array('brand' => str_repeat('B', 71)))), 'brand_too_long', 'error', 'brandul de 71 caractere este eroare');
expect_absent($validator::validate(google_item(array('gtin' => '96385074'))), 'gtin_invalid_checksum', 'GTIN-8 valid este acceptat');
expect_absent($validator::validate(google_item(array('gtin' => '036000291452'))), 'gtin_invalid_checksum', 'UPC-12 valid este acceptat');
expect_absent($validator::validate(google_item(array('gtin' => '10012345000017'))), 'gtin_invalid_checksum', 'GTIN-14 valid este acceptat');
expect_finding($validator::validate(google_item(array('gtin' => '4006381333932'))), 'gtin_invalid_checksum', 'error', 'GTIN cu cifră de control greșită este eroare');
expect_finding($validator::validate(google_item(array('gtin' => '40063813339AB'))), 'gtin_not_numeric', 'error', 'GTIN cu litere este eroare');
expect_finding($validator::validate(google_item(array('gtin' => '40063813339'))), 'gtin_invalid_length', 'error', 'GTIN de 11 cifre este eroare');
expect_finding($validator::validate(google_item(array('gtin' => '400638133393100'))), 'gtin_too_long', 'error', 'GTIN de 15 cifre este eroare');
expect_finding(
    $validator::validate(google_item(array('gtin' => ADC_TEST_UNSET, 'mpn' => ADC_TEST_UNSET))),
    'identifier_missing',
    'error',
    'produs fără GTIN și fără MPN este eroare'
);
expect_clean(
    $validator::validate(google_item(array('gtin' => ADC_TEST_UNSET, 'mpn' => ADC_TEST_UNSET, 'identifier_exists' => 'no'))),
    'identifier_exists=no permite lipsa brand/GTIN/MPN'
);
expect_finding($validator::validate(google_item(array('identifier_exists' => 'maybe'))), 'identifier_exists_invalid', 'error', 'identifier_exists invalid este eroare');
expect_absent($validator::validate(google_item(array('gtin' => ADC_TEST_UNSET, 'mpn' => str_repeat('M', 70)))), 'mpn_too_long', 'MPN de 70 caractere este acceptat');
expect_finding($validator::validate(google_item(array('gtin' => ADC_TEST_UNSET, 'mpn' => str_repeat('M', 71)))), 'mpn_too_long', 'error', 'MPN de 71 caractere este eroare');
expect_finding($validator::validate(google_item(array('gtin' => ADC_TEST_UNSET, 'mpn' => 'CV%100±'))), 'mpn_not_alphanumeric', 'warning', 'MPN cu simboluri este avertisment');

// 12. Google taxonomy and product_type.
expect_finding($validator::validate(google_item(array('google_product_category' => ADC_TEST_UNSET))), 'google_product_category_missing', 'warning', 'categoria Google lipsă este avertisment, nu eroare');
expect_absent($validator::validate(google_item(array('google_product_category' => '2271'))), 'google_product_category_format', 'ID numeric de categorie este acceptat');
expect_finding($validator::validate(google_item(array('google_product_category' => 'Pantofi'))), 'google_product_category_format', 'warning', 'categorie fără ID sau cale este avertisment');
expect_absent($validator::validate(google_item(array('product_type' => 'A > ' . str_repeat('B', 746)))), 'product_type_too_long', 'product_type de 750 caractere este acceptat');
expect_finding($validator::validate(google_item(array('product_type' => 'A > ' . str_repeat('B', 747)))), 'product_type_too_long', 'error', 'product_type de 751 caractere este eroare');
expect_finding($validator::validate(google_item(array('product_type' => 'Băuturi/Ceaiuri'))), 'product_type_format', 'warning', 'product_type fără separator „ > ” este avertisment');

// 13. shipping: required for RO, zone country and price bounds.
expect_finding($validator::validate(google_item(array('shipping' => array()))), 'shipping_required', 'error', 'shipping lipsă este eroare pentru România');
expect_clean($validator::validate(google_item(array('shipping' => array())), array('country' => 'ZZ')), 'shipping lipsă nu este eroare pentru o țară fără cerință');
expect_finding(
    $validator::validate(google_item(array('shipping' => array())), array('country' => 'ZZ', 'shipping_required' => true)),
    'shipping_required',
    'error',
    'shipping_required din config forțează eroarea'
);
expect_finding($validator::validate(google_item(array('shipping' => array(array('price' => 10.0))))), 'shipping_country_missing', 'error', 'zona fără country este eroare');
expect_absent($validator::validate(google_item(array('shipping' => array(array('country' => 'RO', 'price' => 1000))))), 'shipping_price_out_of_range', 'prețul de livrare 1000 este acceptat la graniță');
expect_finding($validator::validate(google_item(array('shipping' => array(array('country' => 'RO', 'price' => 1001))))), 'shipping_price_out_of_range', 'error', 'prețul de livrare 1001 este eroare');
expect_finding($validator::validate(google_item(array('shipping' => array(array('country' => 'RO'))))), 'shipping_price_missing', 'warning', 'zona fără preț este avertisment');
expect_finding($validator::validate(google_item(array('shipping' => 'RO:19.99'))), 'shipping_invalid', 'error', 'shipping ne-listă este eroare');

// 14. custom labels: five slots, 100 characters each.
$six_labels = array_fill(0, 6, 'x');
expect_absent($validator::validate(google_item(array('custom_labels' => array_fill(0, 5, 'x')))), 'custom_labels_too_many', 'cinci etichete custom sunt acceptate');
expect_finding($validator::validate(google_item(array('custom_labels' => $six_labels))), 'custom_labels_too_many', 'error', 'șase etichete custom sunt eroare');
expect_absent($validator::validate(google_item(array('custom_labels' => array(str_repeat('ș', 100))))), 'custom_label_too_long', 'eticheta de 100 caractere cu diacritice este acceptată');
expect_finding($validator::validate(google_item(array('custom_labels' => array(str_repeat('ș', 101))))), 'custom_label_too_long', 'error', 'eticheta de 101 caractere cu diacritice este eroare');
expect_finding($validator::validate(google_item(array('custom_labels' => array('ok', array('bad'))))), 'custom_label_invalid', 'error', 'etichetă custom care nu este text este eroare');
expect_clean($validator::validate(google_item(array('custom_labels' => array()))), 'lista goală de etichete este acceptată');

// 15. weight is optional, but must be usable when present.
expect_clean($validator::validate(google_item(array('weight' => ADC_TEST_UNSET))), 'greutate lipsă este acceptată');
expect_finding($validator::validate(google_item(array('weight' => 'grea'))), 'weight_invalid', 'warning', 'greutate ne-numerică este avertisment');
expect_finding($validator::validate(google_item(array('weight' => -1))), 'weight_invalid', 'warning', 'greutate negativă este avertisment');

// 16. Conditional and never-verified rules stay warnings, and an empty row fails closed.
$row_without_conditional = google_item(array('google_product_category' => ADC_TEST_UNSET));
$only_warnings = $validator::validate($row_without_conditional);
check($validator::summarize($only_warnings)['errors'] === 0, 'regulile condiționate (⚠️) produc doar avertismente');
expect_absent($only_warnings, 'certification_missing', 'certification (EPREL) nu este raportat: condiționat, nu verificabil per rând');
expect_absent($only_warnings, 'unit_pricing_measure_missing', 'unit_pricing_measure nu este raportat: condiționat, nu verificabil per rând');

$empty_row = $validator::validate(array());
$empty_summary = $validator::summarize($empty_row);
check($empty_summary['errors'] === 11, 'un rând complet gol produce 11 erori (' . $empty_summary['errors'] . ')');
check($empty_summary['warnings'] === 2, 'categoria și imaginile secundare lipsă sunt avertismente');
check(count($empty_row) === 13, 'un rând complet gol produce 13 constatări');

echo "feed-validator-google-test: PASS\n";
