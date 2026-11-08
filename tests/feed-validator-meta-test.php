<?php
/**
 * Offline tests for ADC_Feed_Validator_Meta.
 *
 * Runs without WordPress: only the gettext helper and the validator class are needed.
 *
 * Usage: php tests/feed-validator-meta-test.php
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

require_once __DIR__ . '/../includes/feeds/class-adc-feed-validator-meta.php';

/** Sentinel used to remove a key from the fixture row. */
const ADC_TEST_UNSET = '__adc_unset__';

/**
 * Build a normalized catalog row; overrides patch or remove single keys.
 *
 * @param array<string,mixed> $overrides Key => value, or ADC_TEST_UNSET to drop.
 * @return array<string,mixed>
 */
function meta_item(array $overrides = array()): array
{
    $item = array(
        'feed_item_id'              => '12345',
        'item_group_id'             => '900',
        'title'                     => 'Ceai verde organic 100 g',
        'description'               => 'Ceai verde organic, 100 g, ambalaj resigilabil.',
        'link'                      => 'https://example.test/produs/ceai-verde',
        'image_link'                => 'https://example.test/img/ceai-verde.jpg',
        'additional_image_links'    => array('https://example.test/img/ceai-verde-2.jpg'),
        'availability'              => 'in stock',
        'price'                     => 49.99,
        'sale_price'                => 39.99,
        'sale_price_effective_date' => '2026-11-01T00:00+02:00/2026-11-30T23:59+02:00',
        'currency'                  => 'RON',
        'condition'                 => 'new',
        'brand'                     => 'Adcelerum',
        'gtin'                      => '4006381333931',
        'mpn'                       => 'CV-100',
        'google_product_category'   => 'Food, Beverages & Tobacco > Beverages',
        'product_type'              => 'Băuturi > Ceaiuri',
        'weight'                    => '0.15 kg',
        'attributes'                => array('color' => 'verde'),
        'shipping'                  => array(array('country' => 'RO', 'price' => 19.99)),
        'custom_labels'             => array('top_seller'),
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
 * Find the message reported for one code.
 *
 * @param array<int,array<string,string>> $findings Findings.
 * @param string $code Finding code.
 * @return string|null Message, or null when the code is absent.
 */
function message_of(array $findings, string $code): ?string
{
    foreach ($findings as $finding) {
        if ($finding['code'] === $code) {
            return $finding['message'];
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

/**
 * Validate a patched row with the default Meta configuration.
 *
 * @param array<string,mixed> $overrides Row patches.
 * @param array<string,mixed> $config    Validator configuration.
 * @return array<int,array<string,string>>
 */
function meta_validate(array $overrides = array(), array $config = array()): array
{
    return ADC_Feed_Validator_Meta::validate(meta_item($overrides), $config);
}

$smart_config = array('smart_labels' => array('top_seller', 'trending_7d'));

// 1. The complete row is clean and every finding shape is stable.
expect_clean(ADC_Feed_Validator_Meta::validate(meta_item(), $smart_config), 'un produs complet valid nu produce constatări');
check(
    ADC_Feed_Validator_Meta::summarize(array()) === array('errors' => 0, 'warnings' => 0),
    'summarize raportează zero erori și zero avertismente pentru o listă goală'
);

$mixed = meta_validate(array('feed_item_id' => '', 'title' => str_repeat('ș', 70)));
check(
    ADC_Feed_Validator_Meta::summarize($mixed) === array('errors' => 1, 'warnings' => 1),
    'summarize numără separat erorile și avertismentele'
);
foreach ($mixed as $finding) {
    check(array_keys($finding) === array('level', 'field', 'code', 'message'), 'fiecare constatare are exact cheile level, field, code, message');
    check(in_array($finding['level'], array('error', 'warning'), true), 'nivelul este error sau warning');
    check(is_string($finding['field']) && $finding['field'] !== '', 'field este un șir nevid');
    check(is_string($finding['code']) && $finding['code'] !== '', 'code este un șir nevid');
    check(is_string($finding['message']) && $finding['message'] !== '', 'mesajul este un text românesc nevid');
}

// 2. id: required, 100 characters maximum.
expect_finding(meta_validate(array('feed_item_id' => ADC_TEST_UNSET)), 'id_missing', 'error', 'id lipsă este eroare');
expect_absent(meta_validate(array('feed_item_id' => str_repeat('7', 100))), 'id_too_long', 'id de 100 caractere este acceptat');
expect_finding(meta_validate(array('feed_item_id' => str_repeat('7', 101))), 'id_too_long', 'error', 'id de 101 caractere este eroare');

// 3. title: required, 200 characters maximum, 65 recommended.
expect_finding(meta_validate(array('title' => ADC_TEST_UNSET)), 'title_missing', 'error', 'titlu lipsă este eroare');
expect_absent(meta_validate(array('title' => str_repeat('ș', 200))), 'title_too_long', 'titlul de 200 caractere cu diacritice este acceptat');
expect_finding(meta_validate(array('title' => str_repeat('ș', 201))), 'title_too_long', 'error', 'titlul de 201 caractere cu diacritice este eroare');
expect_absent(meta_validate(array('title' => str_repeat('a', 65))), 'title_above_recommended', 'titlul de 65 caractere nu depășește recomandarea');
expect_finding(meta_validate(array('title' => str_repeat('a', 66))), 'title_above_recommended', 'warning', 'titlul de 66 caractere depășește recomandarea de 65');

// 4. description: required, 9999 characters maximum, plain text.
expect_finding(meta_validate(array('description' => ADC_TEST_UNSET)), 'description_missing', 'error', 'descriere lipsă este eroare');
expect_absent(meta_validate(array('description' => str_repeat('d', 9999))), 'description_too_long', 'descrierea de 9999 caractere este acceptată');
expect_finding(meta_validate(array('description' => str_repeat('d', 10000))), 'description_too_long', 'error', 'descrierea de 10000 caractere este eroare');
expect_finding(meta_validate(array('description' => '<p>Ceai verde</p>')), 'description_contains_html', 'warning', 'HTML în descriere este avertisment');
expect_finding(meta_validate(array('description' => 'Vezi https://example.test pentru detalii')), 'description_contains_links', 'warning', 'link în descriere este avertisment');
expect_finding(meta_validate(array('description' => 'CEAI VERDE ORGANIC 100 G')), 'description_all_caps', 'warning', 'descriere integral cu majuscule este avertisment');

// 5. link: required http(s); http is accepted, foreign domains are flagged.
expect_finding(meta_validate(array('link' => ADC_TEST_UNSET)), 'link_missing', 'error', 'link lipsă este eroare');
expect_finding(meta_validate(array('link' => 'pagina-produs')), 'link_invalid', 'error', 'link relativ este eroare');
expect_clean(meta_validate(array('link' => 'http://example.test/produs/ceai')), 'link http este acceptat de Meta');
expect_finding(
    meta_validate(array('link' => 'https://altmagazin.test/produs/ceai'), array('site_host' => 'example.test')),
    'link_domain_mismatch',
    'warning',
    'link pe alt domeniu decât magazinul este avertisment'
);
expect_absent(
    meta_validate(array('link' => 'https://example.test/produs/ceai'), array('site_host' => 'example.test')),
    'link_domain_mismatch',
    'link pe domeniul magazinului este acceptat'
);

// 6. image_link: required, JPEG/PNG, at least 500×500.
expect_finding(meta_validate(array('image_link' => ADC_TEST_UNSET)), 'image_link_missing', 'error', 'imagine principală lipsă este eroare');
expect_finding(meta_validate(array('image_link' => 'ceai.jpg')), 'image_link_invalid', 'error', 'imagine fără URL absolut este eroare');
expect_finding(meta_validate(array('image_link' => 'https://example.test/img/ceai.gif')), 'image_link_format', 'warning', 'imagine GIF este avertisment de format');
expect_finding(meta_validate(array('image_width' => 499, 'image_height' => 600)), 'image_too_small', 'error', 'imaginea de 499 px este sub minimul de 500');
expect_clean(meta_validate(array('image_width' => 500, 'image_height' => 500)), 'imaginea de 500×500 px nu produce constatări');

// 7. additional_image_link is required, up to 50 URLs of max 2000 characters.
expect_finding(meta_validate(array('additional_image_links' => array())), 'additional_images_missing', 'warning', 'lipsa imaginilor secundare este avertisment');
$fifty = array();
for ($i = 1; $i <= 50; ++$i) {
    $fifty[] = 'https://example.test/img/ceai-' . $i . '.jpg';
}
$fifty_one = $fifty;
$fifty_one[] = 'https://example.test/img/ceai-51.jpg';
expect_absent(meta_validate(array('additional_image_links' => $fifty)), 'additional_images_too_many', '50 imagini secundare sunt acceptate');
expect_finding(meta_validate(array('additional_image_links' => $fifty_one)), 'additional_images_too_many', 'error', '51 imagini secundare sunt eroare');
expect_finding(
    meta_validate(array('additional_image_links' => array('https://example.test/ok.jpg', 'nu-e-url'))),
    'additional_image_invalid',
    'error',
    'imagine secundară fără URL absolut este eroare'
);
expect_finding(
    meta_validate(array('additional_image_links' => array('https://example.test/' . str_repeat('a', 1990) . '.jpg'))),
    'additional_image_url_too_long',
    'error',
    'URL de imagine secundară peste 2000 caractere este eroare'
);

// 8. price, sale_price, currency and the effective-date period.
expect_finding(meta_validate(array('price' => ADC_TEST_UNSET)), 'price_missing', 'error', 'preț lipsă este eroare');
expect_finding(meta_validate(array('price' => '49,99 RON')), 'price_format', 'error', 'preț cu virgulă și monedă în același șir este eroare de format');
expect_finding(meta_validate(array('price' => 0)), 'price_invalid', 'error', 'preț zero este eroare');
expect_finding(meta_validate(array('currency' => ADC_TEST_UNSET)), 'currency_missing', 'error', 'monedă lipsă este eroare');
expect_finding(meta_validate(array('currency' => 'RO')), 'currency_invalid', 'error', 'cod de monedă din două litere este eroare');
expect_finding(meta_validate(array('sale_price' => 59.99)), 'sale_price_not_lower', 'error', 'preț redus peste prețul plin este eroare');
expect_finding(meta_validate(array('sale_price' => 49.99)), 'sale_price_equals_price', 'warning', 'preț redus egal cu prețul plin este avertisment');
expect_clean(meta_validate(array('sale_price' => ADC_TEST_UNSET)), 'lipsa sale_price este acceptată');
expect_finding(
    meta_validate(array('sale_price_effective_date' => ADC_TEST_UNSET)),
    'sale_price_effective_date_missing',
    'warning',
    'reducere fără perioadă de valabilitate este avertisment'
);
expect_finding(
    meta_validate(array('sale_price_effective_date' => '01.11.2026-30.11.2026')),
    'sale_price_effective_date_invalid',
    'warning',
    'perioadă de reducere în alt format este avertisment'
);

// 9. availability: exactly two US English values.
expect_finding(meta_validate(array('availability' => ADC_TEST_UNSET)), 'availability_missing', 'error', 'disponibilitate lipsă este eroare');
expect_clean(meta_validate(array('availability' => 'out of stock')), 'out of stock este acceptat');
expect_finding(meta_validate(array('availability' => 'in_stock')), 'availability_needs_mapping', 'warning', 'tokenul Google-like in_stock cere mapare');
expect_finding(meta_validate(array('availability' => 'preorder')), 'availability_invalid', 'error', 'preorder nu poate fi reprezentat la Meta');

// 10. condition is required for Meta.
expect_finding(meta_validate(array('condition' => ADC_TEST_UNSET)), 'condition_missing', 'error', 'condiție lipsă este eroare la Meta');
expect_clean(meta_validate(array('condition' => 'used')), 'condition used este acceptat');
expect_finding(meta_validate(array('condition' => 'nou')), 'condition_invalid', 'error', 'condition în română este eroare de enum');

// 11. identifiers: brand/gtin/mpn and one category.
expect_finding(
    meta_validate(array('brand' => ADC_TEST_UNSET, 'gtin' => ADC_TEST_UNSET, 'mpn' => ADC_TEST_UNSET)),
    'identifier_missing',
    'error',
    'produs fără brand, GTIN și MPN este eroare'
);
expect_clean(meta_validate(array('brand' => ADC_TEST_UNSET)), 'brand lipsă este acceptat când există GTIN și MPN');
expect_finding(meta_validate(array('brand' => str_repeat('B', 101))), 'brand_too_long', 'error', 'brandul de 101 caractere este eroare');
expect_finding(meta_validate(array('mpn' => str_repeat('M', 101))), 'mpn_too_long', 'error', 'MPN de 101 caractere este eroare');
expect_finding(meta_validate(array('gtin' => '4006381-333931')), 'gtin_format', 'warning', 'GTIN cu cratimă este avertisment');
expect_finding(meta_validate(array('gtin' => '40063813339')), 'gtin_length', 'warning', 'GTIN de 11 cifre este avertisment');
expect_finding(
    meta_validate(array('google_product_category' => ADC_TEST_UNSET)),
    'category_missing',
    'warning',
    'lipsa ambelor categorii (Google și Facebook) este avertisment'
);
expect_absent(
    meta_validate(array('google_product_category' => ADC_TEST_UNSET, 'fb_product_category' => 'Vehicles')),
    'category_missing',
    'fb_product_category acoperă cerința de categorie'
);
expect_finding(meta_validate(array('product_type' => str_repeat('B', 751))), 'product_type_too_long', 'error', 'product_type de 751 caractere este eroare');

// 12. Variant attributes and their documented limits/enums.
expect_absent(meta_validate(array('item_group_id' => str_repeat('9', 100))), 'item_group_id_too_long', 'item_group_id de 100 caractere este acceptat');
expect_finding(meta_validate(array('item_group_id' => str_repeat('9', 101))), 'item_group_id_too_long', 'error', 'item_group_id de 101 caractere este eroare');
expect_finding(meta_validate(array('attributes' => array('color' => str_repeat('v', 201)))), 'color_too_long', 'error', 'culoarea de 201 caractere este eroare');
expect_finding(meta_validate(array('attributes' => array('size' => str_repeat('v', 201)))), 'size_too_long', 'error', 'mărimea de 201 caractere este eroare');
expect_finding(meta_validate(array('attributes' => array('material' => str_repeat('v', 201)))), 'material_too_long', 'error', 'materialul de 201 caractere este eroare');
expect_finding(meta_validate(array('attributes' => array('pattern' => str_repeat('v', 101)))), 'pattern_too_long', 'error', 'pattern de 101 caractere este eroare');
expect_finding(meta_validate(array('attributes' => array('age_group' => 'teens'))), 'age_group_invalid', 'error', 'age_group invalid este eroare');
expect_finding(meta_validate(array('attributes' => array('gender' => 'other'))), 'gender_invalid', 'error', 'gender invalid este eroare');
expect_clean(meta_validate(array('attributes' => array('age_group' => 'all ages', 'gender' => 'unisex'))), 'age_group și gender valide nu produc constatări');

// 13. internal_label: 110 characters, 5.000 per product, lowercase.
expect_absent(ADC_Feed_Validator_Meta::validate(meta_item(), array('smart_labels' => array(str_repeat('l', 110)))), 'internal_label_too_long', 'eticheta internă de 110 caractere este acceptată');
expect_finding(
    ADC_Feed_Validator_Meta::validate(meta_item(), array('smart_labels' => array(str_repeat('l', 111)))),
    'internal_label_too_long',
    'error',
    'eticheta internă de 111 caractere este eroare'
);
expect_finding(
    ADC_Feed_Validator_Meta::validate(meta_item(), array('smart_labels' => array('Top_Seller'))),
    'internal_label_not_lowercase',
    'warning',
    'eticheta internă cu majuscule este avertisment (Meta stochează lowercase)'
);
expect_finding(
    ADC_Feed_Validator_Meta::validate(meta_item(), array('smart_labels' => array('top seller'))),
    'internal_label_separator',
    'warning',
    'eticheta internă cu spații este avertisment de separator'
);
expect_finding(
    ADC_Feed_Validator_Meta::validate(meta_item(), array('smart_labels' => array_fill(0, 5001, 'x'))),
    'internal_labels_too_many',
    'error',
    'peste 5.000 de etichete interne este eroare'
);
expect_finding(
    ADC_Feed_Validator_Meta::validate(meta_item(), array('smart_labels' => array(array('bad')))),
    'internal_label_invalid',
    'error',
    'etichetă internă care nu este text este eroare'
);
expect_finding(
    ADC_Feed_Validator_Meta::validate(meta_item(), array('smart_labels' => 'top_seller')),
    'internal_labels_invalid',
    'error',
    'smart_labels care nu este listă este eroare'
);

// 14. custom_label_[0-4]: five slots, 100 characters.
$six_labels = array_fill(0, 6, 'x');
expect_absent(meta_validate(array('custom_labels' => array_fill(0, 5, 'x'))), 'custom_labels_too_many', 'cinci etichete custom sunt acceptate');
expect_finding(meta_validate(array('custom_labels' => $six_labels)), 'custom_labels_too_many', 'error', 'șase etichete custom sunt eroare');
expect_absent(meta_validate(array('custom_labels' => array(str_repeat('ș', 100)))), 'custom_label_too_long', 'eticheta de 100 caractere cu diacritice este acceptată');
expect_finding(meta_validate(array('custom_labels' => array(str_repeat('ș', 101)))), 'custom_label_too_long', 'error', 'eticheta de 101 caractere cu diacritice este eroare');
expect_finding(meta_validate(array('custom_labels' => array('ok', array('bad')))), 'custom_label_invalid', 'error', 'etichetă custom care nu este text este eroare');
expect_clean(
    meta_validate(array('custom_labels' => array(0 => '', 1 => '', 2 => '', 3 => '', 4 => ''))),
    'sloturile custom goale din catalog nu produc constatări'
);
$sparse_labels = meta_validate(array('custom_labels' => array(0 => '', 1 => '', 2 => 'x', 3 => '', 4 => str_repeat('ș', 101))));
$sparse_message = message_of($sparse_labels, 'custom_label_too_long');
check(
    null !== $sparse_message && str_contains($sparse_message, 'custom_label_4'),
    'mesajul indică slotul real din catalog (custom_label_4), nu poziția compactată'
);

// 15. shipping and weight are optional, so their problems are warnings.
expect_clean(meta_validate(array('shipping' => array())), 'lipsa shipping nu este raportată la Meta');
expect_finding(meta_validate(array('shipping' => array(array('country' => 'RO')))), 'shipping_zone_incomplete', 'warning', 'zonă fără preț este avertisment');
expect_finding(meta_validate(array('shipping' => 'RO:19.99')), 'shipping_invalid', 'warning', 'shipping ne-listă este avertisment');
expect_finding(meta_validate(array('weight' => '0.15')), 'weight_unit_missing', 'warning', 'greutate fără unitate este avertisment');
expect_finding(meta_validate(array('weight' => 'grea kg')), 'weight_invalid', 'warning', 'greutate ne-numerică este avertisment');
expect_clean(meta_validate(array('weight' => ADC_TEST_UNSET)), 'lipsa greutății este acceptată');

// 16. status (renamed from visibility).
expect_clean(meta_validate(array('status' => 'archived')), 'status archived este acceptat');
expect_finding(meta_validate(array('status' => 'hidden')), 'status_invalid', 'error', 'status necunoscut este eroare');

// 17. An empty row fails closed with the documented Meta required set.
$empty_row     = ADC_Feed_Validator_Meta::validate(array());
$empty_summary = ADC_Feed_Validator_Meta::summarize($empty_row);
check($empty_summary['errors'] === 10, 'un rând complet gol produce 10 erori (' . $empty_summary['errors'] . ')');
check($empty_summary['warnings'] === 2, 'categoria și imaginile secundare lipsă sunt avertismente');
check(count($empty_row) === 12, 'un rând complet gol produce 12 constatări');

echo "feed-validator-meta-test: PASS\n";
