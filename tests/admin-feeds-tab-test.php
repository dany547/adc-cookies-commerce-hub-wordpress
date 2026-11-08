<?php
/**
 * Catalog ▸ Feeduri tab: the three WooCommerce states (missing, inactive,
 * active), the install/activate links with their nonces, and hiding of the
 * action buttons for users without the matching capability.
 *
 * WooCommerce's state is decided by whether class WooCommerce exists, which
 * cannot be undone in one PHP process. The runner therefore starts one child
 * process per state; the child renders the tab with and without capabilities.
 *
 * Usage: php tests/admin-feeds-tab-test.php
 */

declare(strict_types=1);

const ADC_FEEDS_TEST_STATES = array('missing', 'inactive', 'active', 'active-storage-unverified', 'active-no-requirements');

// ---------------------------------------------------------------------------
// Runner: start one child per state and aggregate the results.
// ---------------------------------------------------------------------------
if (!isset($argv[1])) {
    $failed = 0;
    foreach (ADC_FEEDS_TEST_STATES as $state) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($state) . ' 2>&1';
        $lines = array();
        $code  = 0;
        exec($cmd, $lines, $code);
        foreach ($lines as $line) {
            echo $line . "\n";
        }
        if (0 !== $code) {
            $failed++;
        }
    }
    echo $failed > 0 ? "FAILED: {$failed} state(s)\n" : "All feeds tab states passed.\n";
    exit($failed > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Child: render one state.
// ---------------------------------------------------------------------------
$state = (string) $argv[1];
if (!in_array($state, ADC_FEEDS_TEST_STATES, true)) {
    fwrite(STDERR, "Unknown state: {$state}\n");
    exit(2);
}

define('ABSPATH', '/x/');
define('ADC_MARKETING_PLUGIN_DIR', dirname(__DIR__) . '/');

$GLOBALS['adc_feeds_state'] = $state;
$GLOBALS['adc_feeds_caps']  = array('install_plugins' => true, 'activate_plugins' => true);

function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html__(string $text, string $domain = 'default'): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function self_admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function admin_url(string $path = ''): string { return self_admin_url($path); }
function do_action(string $hook, mixed ...$args): void { unset($hook, $args); }
function get_option(string $key, mixed $default = false): mixed { unset($key); return $default; }
function wp_nonce_url(string $url, string $action): string { return $url . '&_wpnonce=NONCE:' . $action; }
function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . $path; }
function wp_create_nonce(string $action): string { return 'REST-NONCE:' . $action; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
{
    $GLOBALS['adc_feeds_inline_scripts'][] = array('handle' => $handle, 'data' => $data, 'position' => $position);
    return true;
}
$GLOBALS['adc_feeds_terms'] = array((object) array('term_id' => 12, 'name' => 'Încălțăminte'), (object) array('term_id' => 15, 'name' => 'Accesorii'));
function get_terms(array $args): array { return $GLOBALS['adc_feeds_terms']; }
function wc_get_attribute_taxonomies(): array { return array((object) array('attribute_name' => 'culoare', 'attribute_label' => 'Culoare')); }
function get_object_taxonomies(string $object, string $output): array
{
    return array(
        'product_cat' => (object) array('label' => 'Categorii'),
        'pa_culoare'  => (object) array('label' => 'Culoare'),
        'product_tag' => (object) array('label' => 'Etichete'),
    );
}
function current_user_can(string $cap): bool { return (bool) ($GLOBALS['adc_feeds_caps'][$cap] ?? false); }function get_plugins(): array
{
    return 'missing' === $GLOBALS['adc_feeds_state']
        ? array()
        : array('woocommerce/woocommerce.php' => array('Name' => 'WooCommerce'));
}

$is_active = str_starts_with($state, 'active');
if ($is_active) {
    eval('class WooCommerce {}');
}

require ADC_MARKETING_PLUGIN_DIR . 'includes/core/class-adc-autoloader.php';
ADC_Autoloader::register();
if (!interface_exists('ADC_Module_Interface')) {
    eval('interface ADC_Module_Interface {}');
}

// Stand-in for the feed module's requirements (the real module is not changed here).
// Only the active state with requirements gets it; the other states must not see it.
if (in_array($state, array('active', 'active-storage-unverified'), true)) {
    eval('final class ADC_Feed_Module {
        public static function requirements(): array {
            $unverified = "active-storage-unverified" === $GLOBALS["adc_feeds_state"];
            return array(
                array("code" => "xmlreader", "label" => "XMLReader", "ok" => true),
                array("code" => "xmlwriter", "label" => "XMLWriter <i>x</i>", "ok" => false),
                array("code" => "storage", "label" => "Stocare privată", "ok" => true),
                $unverified
                    ? array("code" => "storage_autotest", "status" => "storage_probe_unavailable", "label" => "Verificarea automată nu a putut fi finalizată.", "ok" => true, "warning" => true)
                    : array("code" => "storage_autotest", "status" => "storage_guard_executed", "label" => "Protecția snapshot-urilor a fost verificată automat.", "ok" => true, "warning" => false),
            );
        }
    }');
} elseif ('active-no-requirements' === $state) {
    eval('final class ADC_Feed_Module {}');
}

// Channel registry stand-in (feed-core contract): Google, Meta and TikTok are available.
if ($is_active) {
    eval('final class ADC_Feed_Channels {
        public static function all(): array {
            return array(
                "google" => array("label" => "Google Shopping", "formats" => array("xml")),
                "meta"   => array("label" => "Meta", "formats" => array("csv", "xml")),
                "tiktok" => array("label" => "TikTok", "formats" => array("csv", "xml")),
            );
        }
        public static function get(string $slug): ?array { return self::all()[$slug] ?? null; }
    }');
}

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

/**
 * Render the tab with the given capabilities and return its HTML.
 *
 * @param array<string, bool> $caps Capability map.
 */
function render_feeds(array $caps): string
{
    $GLOBALS['adc_feeds_caps'] = $caps;
    ob_start();
    (new ADC_Admin_Tab_Feeds())->render();
    return (string) ob_get_clean();
}

$INSTALL = 'update.php?action=install-plugin&plugin=woocommerce&_wpnonce=NONCE:install-plugin_woocommerce';
$ACTIVATE = 'plugins.php?action=activate&plugin=woocommerce/woocommerce.php&_wpnonce=NONCE:activate-plugin_woocommerce/woocommerce.php';
$DETAILS = 'plugin-install.php?tab=plugin-information&plugin=woocommerce';

$all  = render_feeds(array('install_plugins' => true, 'activate_plugins' => true));
$none = render_feeds(array());

// Metadata common to every state.
check((new ADC_Admin_Tab_Feeds())->slug() === 'feeds', "[{$state}] slug is feeds");
check((new ADC_Admin_Tab_Feeds())->group() === 'catalog', "[{$state}] group is catalog");
check((new ADC_Admin_Tab_Feeds())->label() === 'Catalog', "[{$state}] label is Catalog");
check((new ADC_Admin_Tab_Feeds())->shows_save_button() === false, "[{$state}] read-only: no save bar");
check(str_contains($all, 'class="adc-box adc-box--light adc-feeds"'), "[{$state}] content sits in the light box card");
check(!str_contains($all, '<form') && !str_contains($none, '<form'), "[{$state}] no nested <form>");
check(!str_contains($all, '<script') && !str_contains($all, '<?php'), "[{$state}] no stray script or PHP output");

switch ($state) {
    case 'missing':
        check(str_contains($all, 'Catalogul necesită WooCommerce'), '[missing] explains WooCommerce is required');
        check(str_contains($all, 'button button-primary" href="https://example.test/wp-admin/' . htmlspecialchars($INSTALL, ENT_QUOTES, 'UTF-8') . '"'), '[missing] primary install link carries the install-plugin nonce');
        check(str_contains($all, 'Instalează WooCommerce'), '[missing] install button label');
        check(str_contains($all, 'href="https://example.test/wp-admin/' . htmlspecialchars($DETAILS, ENT_QUOTES, 'UTF-8') . '"'), '[missing] details link points at the plugin directory info screen');
        check(!str_contains($all, 'Activează WooCommerce'), '[missing] no activate button');
        check(!str_contains($none, 'Instalează WooCommerce') && str_contains($none, 'Detalii'), '[missing] without install_plugins: no install button, details link stays');
        check(str_contains($none, 'Doar un administrator cu drept de instalare'), '[missing] without install_plugins: explanation shown');
        break;

    case 'inactive':
        check(str_contains($all, 'WooCommerce este instalat, dar inactiv'), '[inactive] explains WooCommerce is installed but inactive');
        check(str_contains($all, 'button button-primary" href="https://example.test/wp-admin/' . htmlspecialchars($ACTIVATE, ENT_QUOTES, 'UTF-8') . '"'), '[inactive] activate link carries the activate-plugin nonce');
        check(str_contains($all, 'Activează WooCommerce'), '[inactive] activate button label');
        check(!str_contains($all, 'Instalează WooCommerce'), '[inactive] no install button');
        check(!str_contains($none, 'plugins.php?action=activate'), '[inactive] without activate_plugins: no activate link');
        check(str_contains($none, 'Cere unui administrator cu drept de activare'), '[inactive] without activate_plugins: explanation shown');
        break;

    case 'active':
    case 'active-storage-unverified':
    case 'active-no-requirements':
        if ('active-no-requirements' === $state) {
            check(!str_contains($all, 'Cerințe Catalog lipsă') && !str_contains($all, 'adc-feeds__reqs-list'), '[active-no-requirements] no critical warning when requirements are unavailable');
        } else {
            check(!str_contains($all, 'adc-feeds__reqs-list') && !str_contains($all, 'XMLReader'), "[{$state}] full requirements checklist is moved out of Catalog");
            check(str_contains($all, 'Cerințe Catalog lipsă: XMLWriter') && str_contains($all, 'tab=logs'), "[{$state}] compact critical-requirement warning links to Logs");
            check(!str_contains($all, 'Protecția snapshot-urilor a fost verificată automat.') && !str_contains($all, 'Verificarea automată nu a putut fi finalizată.'), "[{$state}] HTTP probe details stay out of Catalog");
        }
        foreach (array('Google', 'Meta', 'TikTok', 'OLX', 'personalizat') as $platform) {
            check(str_contains($all, $platform), "[active] description mentions {$platform}");
        }
        check(str_contains($all, 'data-adc-feeds'), '[active] JS mount point present');
        $localized = $GLOBALS['adc_feeds_inline_scripts'][0] ?? array();
        check(($localized['handle'] ?? '') === 'adc-admin-feeds' && ($localized['position'] ?? '') === 'before', '[active] PHP gettext strings are attached before the feed script');
        check(str_contains((string) ($localized['data'] ?? ''), 'feed_ui_001') && str_contains((string) ($localized['data'] ?? ''), 'feed_ui_155'), '[active] localized strings cover channel settings and product labels');
        check(preg_match('/data-channels="([^"]*)"/', $all, $ch) === 1, "[{$state}] channel list attribute present");
        $channel_rows = json_decode(html_entity_decode($ch[1] ?? '', ENT_QUOTES, 'UTF-8'), true);
        $by_id = array();
        foreach (is_array($channel_rows) ? $channel_rows : array() as $row) {
            $by_id[$row['id']] = $row;
        }
        check(($by_id['meta']['available'] ?? false) === true && ($by_id['meta']['formats'] ?? array()) === array('csv', 'xml'), "[{$state}] meta is available with its registry formats");
        check(($by_id['tiktok']['available'] ?? false) === true, "[{$state}] tiktok is available");
        check(($by_id['olx']['available'] ?? true) === false && ($by_id['custom']['available'] ?? true) === false, "[{$state}] olx and custom stay in curând");
        check(($by_id['google']['label'] ?? '') === 'Google Shopping', "[{$state}] google label comes from the registry");
        check(preg_match('/data-categories="([^"]*)"/', $all, $cm) === 1, "[{$state}] category list attribute present");
        $category_rows = json_decode(html_entity_decode($cm[1] ?? '', ENT_QUOTES, 'UTF-8'), true);
        check($category_rows === array(array('id' => 12, 'name' => 'Încălțăminte'), array('id' => 15, 'name' => 'Accesorii')), "[{$state}] WooCommerce categories carry id and name");
        check(!str_contains($all, 'Deocamdată este disponibil'), "[{$state}] the availability sentence is current");
        check(str_contains($all, 'Sunt disponibile feedurile pentru Google Shopping, Meta și TikTok'), "[{$state}] the availability sentence names Google, Meta and TikTok");
        check(preg_match('/data-sources="([^"]*)"/', $all, $sm) === 1, "[{$state}] parameter source attribute present");
        $sources = json_decode(html_entity_decode($sm[1] ?? '', ENT_QUOTES, 'UTF-8'), true);
        check(is_array($sources) && in_array(array('value' => 'name', 'label' => 'Nume'), $sources['native'] ?? array(), true), "[{$state}] native sources include the product name");
        check(in_array(array('value' => 'pa_culoare', 'label' => 'Culoare'), $sources['attribute'] ?? array(), true), "[{$state}] attribute sources use pa_ names");
        $taxonomy_values = array_column($sources['taxonomy'] ?? array(), 'value');
        check($taxonomy_values === array('product_cat', 'product_tag') && !in_array('pa_culoare', $taxonomy_values, true), "[{$state}] taxonomy sources exclude attributes");
        check(!str_contains($all, 'în afara webrootului') && !str_contains($all, 'Nginx nu aplică'), '[active] the wrong webroot claim is gone');

        // Diagnostic labels travel to the JS as JSON in a data attribute.
        check(preg_match('/data-diagnostic-labels="([^"]*)"/', $all, $m) === 1, '[active] diagnostic label map attribute present');
        $labels = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8'), true);
        $codes = array(
            // Generator.
            'invalid_feed_id', 'feed_not_found', 'feed_disabled', 'unsupported_feed_profile', 'feed_busy',
            'feed_busy_retry_failed', 'feed_busy_retry_exhausted', 'feed_dirty_retry_failed', 'feed_dirty_retry_exhausted',
            'feed_id_index_failed', 'feed_generation_failed', 'tracking_id_incompatible', 'duplicate_feed_item_id',
            'google_validator_api_missing', 'google_validation_failed',
            // Google profile.
            'shipping_required_for_ro', 'shipping_country_required', 'shipping_country_invalid', 'shipping_price_invalid',
            'certification_required_but_missing', 'certification_incomplete', 'unit_pricing_required_but_missing', 'unit_pricing_incomplete',
            // Google validator.
            'id_missing', 'id_too_long', 'item_group_id_too_long', 'title_missing', 'title_too_long', 'title_not_plain_text',
            'description_missing', 'description_too_long', 'description_not_plain_text', 'link_missing', 'link_invalid',
            'link_https_recommended', 'image_link_missing', 'image_link_invalid', 'image_too_small', 'image_below_upcoming_minimum',
            'additional_images_invalid', 'additional_images_too_many', 'additional_image_invalid', 'additional_image_url_too_long',
            'price_missing', 'price_invalid', 'sale_price_invalid', 'sale_price_above_price', 'sale_price_equals_price',
            'currency_invalid', 'availability_missing', 'availability_invalid', 'availability_date_required',
            'availability_date_invalid', 'condition_invalid', 'identifier_exists_invalid', 'identifier_missing',
            'brand_missing', 'brand_too_long', 'mpn_too_long', 'mpn_not_alphanumeric', 'gtin_too_long', 'gtin_not_numeric',
            'gtin_invalid_length', 'gtin_invalid_checksum', 'google_product_category_missing', 'google_product_category_format',
            'product_type_too_long', 'product_type_format', 'shipping_invalid', 'shipping_required', 'shipping_country_missing',
            'shipping_price_missing', 'shipping_price_out_of_range', 'custom_labels_invalid', 'custom_labels_too_many',
            'custom_label_invalid', 'custom_label_too_long', 'weight_invalid',
            // Generator output.
            'unknown_diagnostic', 'field_registry_unavailable', 'param_enabled_invalid', 'param_field_duplicate', 'param_field_unsupported', 'param_required_missing', 'param_row_invalid', 'param_source_invalid', 'param_transform_invalid', 'param_transforms_invalid', 'unsupported_channel', 'params_invalid', 'tiktok_market_eligibility_requires_confirmation', 'meta_file_size_limit_unverified', 'id_incompatible', 'feed_output_invalid', 'smart_stats_disabled', 'smart_stats_unavailable', 'smart_stats_no_data', 'smart_stats_limit_reached', 'smart_rule_invalid', 'smart_variations_limit_reached', 'smart_no_products', 'no_valid_products', 'invalid_products_excluded',
            // Meta and TikTok validators.
            'additional_image_format', 'additional_images_missing', 'age_group_invalid', 'availability_needs_mapping', 'category_missing', 'color_too_long',
            'condition_missing', 'currency_missing', 'description_all_caps', 'description_contains_html', 'description_contains_links', 'gender_invalid',
            'google_product_category_depth', 'gtin_format', 'gtin_length', 'image_link_format', 'image_link_host_not_allowed', 'internal_label_invalid',
            'internal_label_not_lowercase', 'internal_label_separator', 'internal_label_too_long', 'internal_labels_invalid', 'internal_labels_too_many', 'link_domain_mismatch',
            'material_too_long', 'pattern_too_long', 'price_format', 'product_type_depth', 'sale_price_effective_date_invalid', 'sale_price_effective_date_missing',
            'sale_price_not_lower', 'shipping_weight_format', 'shipping_zone_incomplete', 'shipping_zone_invalid', 'size_too_long', 'sku_id_invalid_encoding',
            'sku_id_missing', 'status_invalid', 'tax_invalid', 'title_above_recommended', 'title_emoji', 'title_promotional',
            'video_link_invalid', 'video_link_platform', 'weight_unit_missing',
        );
        check(is_array($labels), '[active] label map decodes from the data attribute');
        $missing = array_values(array_filter($codes, static fn (string $code): bool => !isset($labels[$code]) || '' === trim((string) $labels[$code])));
        check($missing === array(), '[active] every generator, profile and validator code has a Romanian label' . ($missing === array() ? '' : ': missing ' . implode(', ', $missing)));
        check(array_diff(array_keys($labels ?? array()), $codes) === array(), '[active] the map has no code that the expected list does not know');
        check(!str_contains($all, 'Diagnostic necunoscut'), '[active] fallback text is produced by JS, not in the markup');
        check(str_contains($all, 'data-rest-url="https://example.test/wp-json/adc/v1/feeds"'), '[active] REST URL points at adc/v1/feeds');
        check(str_contains($all, 'data-nonce="REST-NONCE:wp_rest"'), '[active] REST nonce is wp_rest');
        check(str_contains($all, 'aria-live="polite"'), '[active] status region is announced');
        check(!str_contains($all, '<button') && !str_contains($all, 'button-primary'), '[active] buttons are built by JS, not in the markup');
        check(!str_contains($all, 'plugin-install') && !str_contains($all, 'plugins.php?action=activate') && !str_contains($all, 'update.php?action=install-plugin'), '[active] no install/activate links');
        check(!str_contains($all, 'Nu ai creat încă niciun feed'), '[active] empty-state title is rendered by JS, not in markup');
        break;
}

$code = $failed > 0 ? 1 : 0;
echo $code === 0 ? "[{$state}] all checks passed\n" : "[{$state}] {$failed} check(s) failed\n";
exit($code);
