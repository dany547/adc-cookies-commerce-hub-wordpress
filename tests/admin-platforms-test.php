<?php
/**
 * Offline runtime test for the read-only "Integrări ▸ Platforme" tab
 * (docs/ARCHITECTURE.md §2.1): matrix rows + the five computed warnings,
 * across the scenarios the tab promises to detect.
 *
 * Usage: php tests/admin-platforms-test.php
 *
 * Scenarios covered:
 *   S1 all off                                -> rows off, zero warnings
 *   S2 healthy GTM-managed platforms          -> ok rows, zero warnings
 *   S3 W1: 'gtm' mode, container off/no ID    -> browser_gtm_container_off
 *   S4 W2: GA4 direct + GTM active (Meta direct stays clean)
 *                                             -> browser_double_delivery (ga4 only)
 *   S5 W3: server on without credentials      -> server_missing_credentials
 *   S6 W4: ga4 purchase via server + browser  -> purchase_double_channel
 *      (negative: purchase server + browser off -> no warning)
 *   S7 W5: Server GTM on without URL          -> sgtm_missing_url
 *   S8 server ready (id + secret present)     -> no W3, server_ok true
 *   S9 contract: read-only tab (save [], shows_save_button false, slug/group/order)
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

if (!interface_exists('ADC_Module_Interface')) {
    eval('interface ADC_Module_Interface {}');
}

// --- Minimal WordPress stubs (same model as tests/gtm-consent-test.php). ---
$GLOBALS['adc_test_settings'] = array(); // group => key => value

function __(string $value, string $domain = 'default'): string
{
    unset($domain);
    return $value;
}

function add_action(...$args): void {}
function add_filter(...$args): void {}
function sanitize_text_field(string $value): string
{
    return trim($value);
}
function wp_http_validate_url(string $value): bool
{
    return (bool) preg_match('#^https://[^\s]+$#i', $value);
}

final class ADC_Settings_Test_Store
{
    public static function all(string $group): array
    {
        $defaults = array(
            'meta'   => array('enabled_server' => false, 'browser_mode' => 'off', 'pixel_id' => '', 'access_token' => ''),
            'ga4'    => array('enabled_server' => false, 'browser_mode' => 'off', 'measurement_id' => '', 'api_secret' => '', 'purchase_channel' => 'browser'),
            'tiktok' => array('enabled_server' => false, 'browser_mode' => 'off', 'pixel_code' => '', 'access_token' => ''),
            'gtm'    => array('web_enabled' => false, 'container_id' => ''),
            'sgtm'   => array('enabled' => false, 'server_container_url' => ''),
        );

        return array_merge(
            $defaults[$group] ?? array(),
            $GLOBALS['adc_test_settings'][$group] ?? array()
        );
    }

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = self::all($group);
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function secret(string $group, string $key): string
    {
        return (string) ($GLOBALS['adc_test_settings'][$group][$key] ?? '');
    }
}

class_alias(ADC_Settings_Test_Store::class, 'ADC_Settings');

// --- Real plugin classes under test (ADC_Gtm + ADC_Integration_Sgtm + the tab). ---
require __DIR__ . '/../includes/core/interface-adc-integration.php';
require __DIR__ . '/../includes/tracking/class-adc-gtm.php';
require __DIR__ . '/../includes/integrations/class-adc-integration-sgtm.php';
require __DIR__ . '/../includes/admin/interface-adc-admin-tab.php';
require __DIR__ . '/../includes/admin/class-adc-admin-tab-platforms.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function warning_ids(array $snap): array
{
    return array_map(static fn (array $w): string => (string) $w['id'], $snap['warnings']);
}

function reset_store(): void
{
    $GLOBALS['adc_test_settings'] = array();
}

// --- S9: tab contract (read-only overview, first in Integrări). ---
$tab = new ADC_Admin_Tab_Platforms();
check('platforms' === $tab->slug(), 'tab slug is platforms');
check('integrations' === $tab->group(), 'tab belongs to the integrations group');
check(25 === $tab->order(), 'tab order 25 sits before Meta (30)');
check(false === $tab->shows_save_button(), 'read-only tab hides the save bar');
check(array() === $tab->save(array()), 'save() returns an empty notice list');

// --- S1: everything off -> rows off, no warnings. ---
reset_store();
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(array() === $snap['warnings'], 'S1: no warnings when everything is off');
foreach (array('meta', 'ga4', 'tiktok') as $slug) {
    check('off' === $snap['rows'][$slug]['status']['state'], "S1: {$slug} status off");
    check('off' === $snap['rows'][$slug]['browser_mode'], "S1: {$slug} browser off");
    check(false === $snap['rows'][$slug]['server_enabled'], "S1: {$slug} server off");
}
check(false === $snap['gtm_active'], 'S1: GTM container inactive without id');

// --- S2: healthy GTM-managed setup -> no warnings, rows active. ---
reset_store();
$GLOBALS['adc_test_settings']['gtm']  = array('web_enabled' => true, 'container_id' => 'GTM-ABC123');
$GLOBALS['adc_test_settings']['meta'] = array('browser_mode' => 'gtm');
$GLOBALS['adc_test_settings']['ga4']  = array('browser_mode' => 'gtm');
$GLOBALS['adc_test_settings']['tiktok'] = array('browser_mode' => 'gtm');
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(array() === $snap['warnings'], 'S2: no warnings for a healthy GTM-managed setup');
check(true === $snap['gtm_active'], 'S2: GTM container counts as active');
foreach (array('meta', 'ga4', 'tiktok') as $slug) {
    check('ok' === $snap['rows'][$slug]['status']['state'], "S2: {$slug} status ok");
    check(true === $snap['rows'][$slug]['browser_ok'], "S2: {$slug} browser ok via GTM");
}

// --- S3: W1 — 'gtm' mode while the container is off / has no id. ---
reset_store();
$GLOBALS['adc_test_settings']['gtm']  = array('web_enabled' => false, 'container_id' => 'GTM-ABC123');
$GLOBALS['adc_test_settings']['meta'] = array('browser_mode' => 'gtm');
$snap = ADC_Admin_Tab_Platforms::snapshot();
$ids  = warning_ids($snap);
check(in_array('browser_gtm_container_off', $ids, true), 'S3: W1 raised for container off');
$w = $snap['warnings'][array_search('browser_gtm_container_off', $ids, true)];
check('meta' === $w['platform'], 'S3: W1 points at Meta');
check('gtm' === $w['fix_tab'], 'S3: W1 links to the GTM tab');
check('bad' === $snap['rows']['meta']['status']['state'], 'S3: Meta status inactive with reason');
check('' !== $snap['rows']['meta']['status']['label'] && 'Dezactivat' !== $snap['rows']['meta']['status']['label'], 'S3: status carries a text reason');

reset_store();
$GLOBALS['adc_test_settings']['gtm']  = array('web_enabled' => true, 'container_id' => '');
$GLOBALS['adc_test_settings']['meta'] = array('browser_mode' => 'gtm');
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(in_array('browser_gtm_container_off', warning_ids($snap), true), 'S3b: W1 raised when the container id is missing');

// --- S4: W2 — GA4 direct + GTM active; Meta direct stays clean. ---
reset_store();
$GLOBALS['adc_test_settings']['gtm']    = array('web_enabled' => true, 'container_id' => 'GTM-ABC123');
$GLOBALS['adc_test_settings']['ga4']    = array('browser_mode' => 'direct');
$GLOBALS['adc_test_settings']['meta']   = array('browser_mode' => 'direct');
$GLOBALS['adc_test_settings']['tiktok'] = array('browser_mode' => 'off');
$snap = ADC_Admin_Tab_Platforms::snapshot();
$ids  = warning_ids($snap);
check(in_array('browser_double_delivery', $ids, true), 'S4: W2 raised for GA4 direct + GTM active');
$w = $snap['warnings'][array_search('browser_double_delivery', $ids, true)];
check('ga4' === $w['platform'] && 'ga4' === $w['fix_tab'], 'S4: W2 fixes in the GA4 tab');
check(!in_array('browser_gtm_container_off', $ids, true), 'S4: no W1 while the container is active');
$meta_dup = array_filter(
    $snap['warnings'],
    static fn (array $w): bool => 'browser_double_delivery' === $w['id'] && 'meta' === $w['platform']
);
check(array() === $meta_dup, 'S4: Meta direct + GA4 GTM stays clean (no duplication warning for Meta)');

// --- S5: W3 — server enabled without credentials. ---
reset_store();
$GLOBALS['adc_test_settings']['meta'] = array('enabled_server' => true);
$snap = ADC_Admin_Tab_Platforms::snapshot();
$ids  = warning_ids($snap);
check(in_array('server_missing_credentials', $ids, true), 'S5: W3 raised for Meta server without credentials');
$w = $snap['warnings'][array_search('server_missing_credentials', $ids, true)];
check('meta' === $w['platform'] && 'meta' === $w['fix_tab'], 'S5: W3 fixes in the Meta tab');
check('bad' === $snap['rows']['meta']['status']['state'], 'S5: Meta status inactive while only the broken channel is on');

reset_store();
$GLOBALS['adc_test_settings']['ga4'] = array('enabled_server' => true, 'measurement_id' => 'G-ABC123456');
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(in_array('server_missing_credentials', warning_ids($snap), true), 'S5b: GA4 server needs both Measurement ID and API key');

// --- S6: W4 — ga4 purchase via server while the browser channel is live. ---
reset_store();
$GLOBALS['adc_test_settings']['gtm'] = array('web_enabled' => true, 'container_id' => 'GTM-ABC123');
$GLOBALS['adc_test_settings']['ga4'] = array('browser_mode' => 'gtm', 'purchase_channel' => 'server');
$snap = ADC_Admin_Tab_Platforms::snapshot();
$ids  = warning_ids($snap);
check(in_array('purchase_double_channel', $ids, true), 'S6: W4 raised for purchase server + browser GA4 active');
$w = $snap['warnings'][array_search('purchase_double_channel', $ids, true)];
check('ga4' === $w['fix_tab'], 'S6: W4 fixes in the GA4 tab');

reset_store();
$GLOBALS['adc_test_settings']['ga4'] = array('browser_mode' => 'off', 'purchase_channel' => 'server');
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(!in_array('purchase_double_channel', warning_ids($snap), true), 'S6b: no W4 when the browser GA4 channel is off');

reset_store();
$GLOBALS['adc_test_settings']['gtm'] = array('web_enabled' => true, 'container_id' => 'GTM-ABC123');
$GLOBALS['adc_test_settings']['ga4'] = array('browser_mode' => 'gtm', 'purchase_channel' => 'browser');
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(!in_array('purchase_double_channel', warning_ids($snap), true), 'S6c: no W4 for the default browser purchase channel');

// --- S7: W5 — Server GTM enabled without a URL. ---
reset_store();
$GLOBALS['adc_test_settings']['sgtm'] = array('enabled' => true, 'server_container_url' => '');
$snap = ADC_Admin_Tab_Platforms::snapshot();
$ids  = warning_ids($snap);
check(in_array('sgtm_missing_url', $ids, true), 'S7: W5 raised for Server GTM without URL');
$w = $snap['warnings'][array_search('sgtm_missing_url', $ids, true)];
check('gtm' === $w['fix_tab'], 'S7: W5 links to the unified GTM tab');

reset_store();
$GLOBALS['adc_test_settings']['sgtm'] = array('enabled' => true, 'server_container_url' => 'https://analytics.exemplu.ro');
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(!in_array('sgtm_missing_url', warning_ids($snap), true), 'S7b: valid server URL silences W5');

// --- S8: server ready (id + secret) -> no W3, server_ok true. ---
reset_store();
$GLOBALS['adc_test_settings']['meta'] = array(
    'enabled_server' => true,
    'browser_mode'   => 'off',
    'pixel_id'       => '123456789',
    'access_token'   => 'sealed-token',
);
$snap = ADC_Admin_Tab_Platforms::snapshot();
check(array() === $snap['warnings'], 'S8: no warnings for a fully configured Meta server channel');
check(true === $snap['rows']['meta']['server_ok'], 'S8: Meta server_ok true');
check('ok' === $snap['rows']['meta']['status']['state'], 'S8: Meta status ok');

// --- S8b: partial setup — browser ok + broken server -> warn state. ---
reset_store();
$GLOBALS['adc_test_settings']['meta'] = array(
    'enabled_server' => true,
    'browser_mode'   => 'direct',
    'pixel_id'       => '',
    'access_token'   => '',
);
$snap = ADC_Admin_Tab_Platforms::snapshot();
$ids  = warning_ids($snap);
check('warn' === $snap['rows']['meta']['status']['state'], 'S8b: Meta status warn when one channel works');
check(in_array('server_missing_credentials', $ids, true), 'S8b: W3 raised alongside the working browser channel');
check(true === $snap['rows']['meta']['browser_ok'], 'S8b: browser channel still counts as ok');

echo "OK adc-admin-tab-platforms: 9 scenario groups passed\n";
exit(0);
