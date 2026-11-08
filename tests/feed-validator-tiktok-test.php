<?php
/**
 * Offline tests for ADC_Feed_Validator_Tiktok.
 *
 * Runs without WordPress: only the gettext helper and the validator class are needed.
 *
 * Usage: php tests/feed-validator-tiktok-test.php
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

require_once __DIR__ . '/../includes/feeds/class-adc-feed-validator-tiktok.php';

/** Sentinel used to remove a key from the fixture row. */
const ADC_TEST_UNSET = '__adc_unset__';

/**
 * Build a normalized catalog row; overrides patch or remove single keys.
 *
 * @param array<string,mixed> $overrides Key => value, or ADC_TEST_UNSET to drop.
 * @return array<string,mixed>
 */
function tiktok_item(array $overrides = array()): array
{
    $item = array(
        'feed_item_id'            => '12345',
        'sku_id'                  => 'CV-100',
        'item_group_id'           => '900',
        'title'                   => 'Ceai verde organic 100 g',
        'description'             => 'Ceai verde organic, 100 g, ambalaj resigilabil.',
        'link'                    => 'https://example.test/produs/ceai-verde',
        'image_link'              => 'https://example.test/img/ceai-verde.png',
        'additional_image_links'  => array('https://example.test/img/ceai-verde-2.jpg'),
        'availability'            => 'in stock',
        'price'                   => 49.99,
        'sale_price'              => 39.99,
        'currency'                => 'RON',
        'condition'               => 'new',
        'brand'                   => 'Adcelerum',
        'gtin'                    => '4006381333931',
        'mpn'                     => 'CV-100',
        'google_product_category' => 'Food, Beverages & Tobacco > Beverages > Tea',
        'product_type'            => 'Băuturi > Ceaiuri',
        'weight'                  => '0.3 lb',
        'attributes'              => array('gender' => 'unisex', 'age_group' => 'adult'),
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
 * Validate a patched row.
 *
 * @param array<string,mixed> $overrides Row patches.
 * @param array<string,mixed> $config    Validator configuration.
 * @return array<int,array<string,string>>
 */
function tiktok_validate(array $overrides = array(), array $config = array()): array
{
    return ADC_Feed_Validator_Tiktok::validate(tiktok_item($overrides), $config);
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

$validator = ADC_Feed_Validator_Tiktok::class;

// 1. The complete row is clean and every finding shape is stable.
expect_clean(tiktok_validate(), 'un produs complet valid nu produce constatări');
check(
    $validator::summarize(array()) === array('errors' => 0, 'warnings' => 0),
    'summarize raportează zero erori și zero avertismente pentru o listă goală'
);

$mixed = tiktok_validate(array('brand' => '', 'title' => 'Ceai 🌿'));
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

// 2. The nine required attributes fail closed.
$empty_row     = $validator::validate(array());
$empty_summary = $validator::summarize($empty_row);
check($empty_summary['errors'] === 9, 'un rând complet gol produce 9 erori, cele nouă câmpuri obligatorii (' . $empty_summary['errors'] . ')');
check($empty_summary['warnings'] === 1, 'un rând complet gol produce 1 avertisment, moneda implicită (' . $empty_summary['warnings'] . ')');
foreach (array('sku_id_missing', 'title_missing', 'description_missing', 'availability_missing', 'condition_missing', 'price_missing', 'link_missing', 'image_link_missing', 'brand_missing') as $code) {
    expect_finding($empty_row, $code, 'error', 'rândul gol raportează ' . $code);
}

// 3. sku_id: required, falls back to the catalog id, valid UTF-8.
expect_finding(tiktok_validate(array('sku_id' => ADC_TEST_UNSET, 'feed_item_id' => ADC_TEST_UNSET)), 'sku_id_missing', 'error', 'sku_id lipsă este eroare');
expect_clean(tiktok_validate(array('sku_id' => ADC_TEST_UNSET)), 'sku_id cade pe feed_item_id fără constatări');
expect_finding(tiktok_validate(array('sku_id' => "CV-\xC3\x28")), 'sku_id_invalid_encoding', 'error', 'sku_id cu UTF-8 invalid este eroare');

// 4. title: required, no promotional wording, no emoji.
expect_finding(tiktok_validate(array('title' => ADC_TEST_UNSET)), 'title_missing', 'error', 'titlu lipsă este eroare');
expect_finding(tiktok_validate(array('title' => 'Ceai verde - Transport gratuit')), 'title_promotional', 'warning', 'titlu promoțional este avertisment');
expect_finding(tiktok_validate(array('title' => 'Ceai verde Free Shipping')), 'title_promotional', 'warning', 'formulare promoțională engleză este avertisment');
expect_finding(tiktok_validate(array('title' => 'Ceai verde 🌿 100 g')), 'title_emoji', 'warning', 'emoji în titlu este avertisment');
expect_clean(tiktok_validate(array('title' => 'Ceai verde organic 100 g')), 'titlu curat este acceptat');

// 5. description is required.
expect_finding(tiktok_validate(array('description' => ADC_TEST_UNSET)), 'description_missing', 'error', 'descriere lipsă este eroare');

// 6. availability: five space-separated values, Google tokens need mapping.
expect_clean(tiktok_validate(array('availability' => 'available for order')), 'available for order este acceptat');
expect_clean(tiktok_validate(array('availability' => 'preorder')), 'preorder este acceptat');
expect_clean(tiktok_validate(array('availability' => 'discontinued')), 'discontinued este acceptat');
expect_finding(tiktok_validate(array('availability' => 'in_stock')), 'availability_needs_mapping', 'warning', 'tokenul in_stock cere mapare la „in stock”');
expect_finding(tiktok_validate(array('availability' => 'backorder')), 'availability_needs_mapping', 'warning', 'backorder cere mapare la „available for order”');
expect_finding(tiktok_validate(array('availability' => 'instock')), 'availability_invalid', 'error', 'valoare necunoscută de disponibilitate este eroare');

// 7. condition: required enum.
expect_finding(tiktok_validate(array('condition' => ADC_TEST_UNSET)), 'condition_missing', 'error', 'condiție lipsă este eroare');
expect_clean(tiktok_validate(array('condition' => 'refurbished')), 'condition refurbished este acceptat');
expect_finding(tiktok_validate(array('condition' => 'nou')), 'condition_invalid', 'error', 'condition în română este eroare de enum');

// 8. price, sale_price and currency.
expect_finding(tiktok_validate(array('price' => ADC_TEST_UNSET)), 'price_missing', 'error', 'preț lipsă este eroare');
expect_finding(tiktok_validate(array('price' => '49,99')), 'price_invalid', 'error', 'preț cu virgulă zecimală este eroare');
expect_finding(tiktok_validate(array('currency' => ADC_TEST_UNSET)), 'currency_missing', 'warning', 'monedă lipsă este avertisment la TikTok');
expect_finding(tiktok_validate(array('currency' => 'RONN')), 'currency_invalid', 'error', 'cod de monedă cu patru litere este eroare');
expect_finding(tiktok_validate(array('sale_price' => 0)), 'sale_price_invalid', 'error', 'preț redus zero este eroare');
expect_finding(tiktok_validate(array('sale_price' => 49.99)), 'sale_price_not_lower', 'warning', 'preț redus egal cu prețul plin este avertisment');
expect_clean(tiktok_validate(array('sale_price' => ADC_TEST_UNSET)), 'lipsa sale_price este acceptată');
expect_clean(
    tiktok_validate(array('sale_price_effective_date' => '2026-11-01T00:00/2026-11-30T23:59')),
    'perioada de reducere în format TikTok este acceptată'
);
expect_finding(
    tiktok_validate(array('sale_price_effective_date' => '01.11.2026-30.11.2026')),
    'sale_price_effective_date_invalid',
    'warning',
    'perioada de reducere în alt format este avertisment'
);

// 9. link.
expect_finding(tiktok_validate(array('link' => ADC_TEST_UNSET)), 'link_missing', 'error', 'link lipsă este eroare');
expect_finding(tiktok_validate(array('link' => 'pagina-produs')), 'link_invalid', 'error', 'link relativ este eroare');

// 10. image_link: required, JPG/PNG, 500×500, accepted hosting.
expect_finding(tiktok_validate(array('image_link' => ADC_TEST_UNSET)), 'image_link_missing', 'error', 'imagine principală lipsă este eroare');
expect_finding(tiktok_validate(array('image_link' => 'ceai.png')), 'image_link_invalid', 'error', 'imagine fără URL absolut este eroare');
expect_finding(
    tiktok_validate(array('image_link' => 'https://drive.google.com/file/d/abc/view')),
    'image_link_host_not_allowed',
    'error',
    'hosting respins explicit de TikTok (Google Drive) este eroare'
);
expect_finding(
    tiktok_validate(array('image_link' => 'https://example.test/img/ceai.gif')),
    'image_link_format',
    'warning',
    'imagine GIF este avertisment de format'
);
expect_finding(tiktok_validate(array('image_width' => 400, 'image_height' => 400)), 'image_too_small', 'error', 'imaginea de 400×400 px este sub minimul de 500');
expect_clean(tiktok_validate(array('image_width' => 500, 'image_height' => 500)), 'imaginea de 500×500 px nu produce constatări');

// 11. additional_image_link: up to 10 URLs, JPG/PNG.
$ten = array();
for ($i = 1; $i <= 10; ++$i) {
    $ten[] = 'https://example.test/img/ceai-' . $i . '.jpg';
}
$eleven = $ten;
$eleven[] = 'https://example.test/img/ceai-11.jpg';
expect_absent(tiktok_validate(array('additional_image_links' => $ten)), 'additional_images_too_many', '10 imagini secundare sunt acceptate');
expect_finding(tiktok_validate(array('additional_image_links' => $eleven)), 'additional_images_too_many', 'error', '11 imagini secundare sunt eroare');
expect_finding(tiktok_validate(array('additional_image_links' => array('nu-e-url'))), 'additional_image_invalid', 'error', 'imagine secundară fără URL absolut este eroare');
expect_finding(tiktok_validate(array('additional_image_links' => array('https://example.test/img/ceai.webp'))), 'additional_image_format', 'warning', 'imagine secundară non-JPG/PNG este avertisment');

// 12. brand is required.
expect_finding(tiktok_validate(array('brand' => '')), 'brand_missing', 'error', 'brand gol este eroare');

// 13. gtin is optional but must use a documented family.
expect_clean(tiktok_validate(array('gtin' => ADC_TEST_UNSET)), 'lipsa GTIN este acceptată la TikTok');
expect_finding(tiktok_validate(array('gtin' => '40063813339AB')), 'gtin_format', 'warning', 'GTIN cu litere este avertisment');
expect_finding(tiktok_validate(array('gtin' => '40063813339')), 'gtin_length', 'warning', 'GTIN de 11 cifre este avertisment');

// 14. Only three category levels are supported.
expect_absent(tiktok_validate(), 'google_product_category_depth', 'categoria pe trei niveluri este acceptată');
expect_finding(
    tiktok_validate(array('google_product_category' => 'A > B > C > D')),
    'google_product_category_depth',
    'warning',
    'categoria pe patru niveluri este avertisment'
);
expect_finding(
    tiktok_validate(array('product_type' => 'A > B > C > D')),
    'product_type_depth',
    'warning',
    'product_type pe patru niveluri este avertisment'
);

// 15. Variant enums.
expect_clean(tiktok_validate(array('attributes' => array('age_group' => 'kids', 'gender' => 'female'))), 'age_group și gender valide nu produc constatări');
expect_finding(tiktok_validate(array('attributes' => array('age_group' => 'teens'))), 'age_group_invalid', 'error', 'age_group invalid este eroare');
expect_finding(tiktok_validate(array('attributes' => array('gender' => 'other'))), 'gender_invalid', 'error', 'gender invalid este eroare');

// 16. custom_label_0..4: five slots, 100 characters each.
expect_finding(tiktok_validate(array('custom_labels' => array_fill(0, 6, 'x'))), 'custom_labels_too_many', 'error', 'șase etichete custom sunt eroare');
expect_absent(tiktok_validate(array('custom_labels' => array(str_repeat('ș', 100)))), 'custom_label_too_long', 'eticheta de 100 caractere cu diacritice este acceptată');
expect_finding(tiktok_validate(array('custom_labels' => array(str_repeat('ș', 101)))), 'custom_label_too_long', 'error', 'eticheta de 101 caractere cu diacritice este eroare');
expect_finding(tiktok_validate(array('custom_labels' => array('ok', array('bad')))), 'custom_label_invalid', 'error', 'etichetă custom care nu este text este eroare');
expect_clean(
    tiktok_validate(array('custom_labels' => array(0 => '', 1 => '', 2 => '', 3 => '', 4 => ''))),
    'sloturile custom goale din catalog nu produc constatări'
);
$sparse_labels  = tiktok_validate(array('custom_labels' => array(0 => '', 1 => '', 2 => 'x', 3 => '', 4 => str_repeat('ș', 101))));
$sparse_message = message_of($sparse_labels, 'custom_label_too_long');
check(
    null !== $sparse_message && str_contains($sparse_message, 'custom_label_4'),
    'mesajul indică slotul real din catalog (custom_label_4), nu poziția compactată'
);

// 17. video_link: direct file, not a platform page.
expect_clean(tiktok_validate(array('video_link' => 'https://example.test/v/ceai.mp4')), 'video_link direct este acceptat');
expect_finding(tiktok_validate(array('video_link' => 'https://www.youtube.com/watch?v=abc')), 'video_link_platform', 'warning', 'link YouTube este avertisment');
expect_finding(tiktok_validate(array('video_link' => 'ceai.mp4')), 'video_link_invalid', 'warning', 'video_link fără URL absolut este avertisment');

// 18. shipping and weight formats.
expect_clean(tiktok_validate(array('shipping' => 'RO:RO:standard:19.99:curier')), 'zona de livrare în format TikTok este acceptată');
expect_finding(tiktok_validate(array('shipping' => 'RO:19.99')), 'shipping_zone_incomplete', 'warning', 'zona de livrare incompletă este avertisment');
expect_finding(tiktok_validate(array('shipping' => array(array('country' => 'RO')))), 'shipping_zone_incomplete', 'warning', 'zona de livrare fără preț este avertisment');
expect_clean(tiktok_validate(array('weight' => '500 g')), 'greutatea cu unitate este acceptată');
expect_finding(tiktok_validate(array('weight' => '0.3')), 'shipping_weight_format', 'warning', 'greutatea fără unitate este avertisment');

// 19. tax is optional but numeric when present.
expect_clean(tiktok_validate(array('tax' => '0.05')), 'tax numeric este acceptat');
expect_finding(tiktok_validate(array('tax' => '0,05')), 'tax_invalid', 'warning', 'tax cu virgulă zecimală este avertisment');

echo "feed-validator-tiktok-test: PASS\n";
