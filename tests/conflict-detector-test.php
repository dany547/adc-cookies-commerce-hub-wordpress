<?php
/**
 * Offline checks for ADC_Conflict_Detector: plugin fingerprints, page signals,
 * own-output attribution and the resulting severity rules. No WordPress, no
 * network — the pure functions are fed with fixtures.
 *
 * Usage: php tests/conflict-detector-test.php
 */

declare(strict_types=1);

define('ABSPATH', '/x/');

$GLOBALS['adc_options']   = array();
$GLOBALS['adc_transients'] = array();
$GLOBALS['adc_settings']  = array();

function __(string $text, string $domain = 'default'): string { return $text; }
function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return 1 === $number ? str_replace('%d', (string) $number, $single) : str_replace('%d', (string) $number, $plural);
}
function add_action(mixed ...$args): bool { return true; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['adc_options'][$key] ?? $default; }
function get_site_option(string $key, mixed $default = false): mixed { return $GLOBALS['adc_options']['network_' . $key] ?? $default; }
function get_transient(string $key): mixed { return $GLOBALS['adc_transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl = 0): bool { $GLOBALS['adc_transients'][$key] = $value; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['adc_transients'][$key]); return true; }
function is_multisite(): bool { return false; }
function home_url(string $path = '/'): string { return 'https://shop.test' . $path; }
function wp_remote_get(string $url, array $args = array()): mixed { $GLOBALS['adc_http_args'] = $args; return $GLOBALS['adc_http'] ?? array('response' => array('code' => 200), 'body' => ''); }
function is_wp_error(mixed $value): bool { return false; }
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }

final class ADC_Settings
{
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return $GLOBALS['adc_settings'][$group][$key] ?? $default;
    }
}

final class ADC_Gtm
{
    public static function container_id(): string { return (string) ($GLOBALS['adc_own']['gtm_id'] ?? ''); }
    public static function mode(): string { return (string) ($GLOBALS['adc_own']['gtm_mode'] ?? 'after_consent'); }
}

final class ADC_Consent_Banner
{
    public static function is_enabled(): bool { return (bool) ($GLOBALS['adc_own']['banner'] ?? false); }
}

interface ADC_Module_Interface {}

require dirname(__DIR__) . '/includes/core/class-adc-conflict-detector.php';

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

/**
 * Build one detection context.
 *
 * @param array<int, string> $plugin_domains Domains of the detected plugins.
 * @param array              $own            Own footprint overrides.
 * @param array              $signals        Page signals.
 * @return array
 */
function ctx(array $plugin_domains, array $own, array $signals): array
{
    $plugins = array();
    foreach ($plugin_domains as $i => $domain) {
        $plugins[] = array('file' => 'plugin-' . $i . '/plugin.php', 'label' => 'Plugin' . $i, 'domain' => $domain);
    }

    return array(
        'plugins' => $plugins,
        'own'     => $own + array(
            'banner'    => false,
            'gtm_id'    => '',
            'gtm_mode'  => 'after_consent',
            'sgtm'      => false,
            'platforms' => array('ga4' => 'off', 'meta' => 'off', 'tiktok' => 'off'),
            'ga4_id'    => '',
            'meta_id'   => '',
            'tiktok_id' => '',
        ),
        'signals' => $signals + array(
            'gtm_ids'          => array(),
            'ga4_ids'          => array(),
            'meta_ids'         => array(),
            'tiktok_ids'       => array(),
            'datalayer_resets' => 0,
            'cmp'              => array(),
        ),
    );
}

function ids(array $findings): array
{
    return array_map(static fn (array $f): string => (string) $f['id'], $findings);
}

function severity_of(array $findings, string $id): string
{
    foreach ($findings as $finding) {
        if ($finding['id'] === $id) {
            return (string) $finding['severity'];
        }
    }
    return '';
}

/* --- Hedge 1: clean site, nothing foreign ------------------------------- */
$clean = ADC_Conflict_Detector::analyze(ctx(array(), array('gtm_id' => 'GTM-AAA111', 'gtm_mode' => 'always_with_defaults'), array('gtm_ids' => array('GTM-AAA111' => 1))));
check(array() === $clean, 'clean site with only our own container produces no findings (own output is subtracted)');

/* --- Rule 1: second CMP ------------------------------------------------- */
$cmp_on = ADC_Conflict_Detector::analyze(ctx(array('cmp'), array('banner' => true), array()));
check('conflict' === severity_of($cmp_on, 'other_cmp_active'), 'another CMP + our banner on = conflict');
$cmp_off = ADC_Conflict_Detector::analyze(ctx(array('cmp'), array('banner' => false), array()));
check('info' === severity_of($cmp_off, 'other_cmp_inactive'), 'another CMP with our banner off is informational only');

/* --- Rule 2: second GTM loader ------------------------------------------ */
$gtm_both = ADC_Conflict_Detector::analyze(ctx(array('gtm'), array('gtm_id' => 'GTM-AAA111'), array()));
check('conflict' === severity_of($gtm_both, 'other_gtm_loader'), 'another GTM loader + our container = conflict');
$gtm_other = ADC_Conflict_Detector::analyze(ctx(array('gtm'), array('gtm_id' => ''), array()));
check('info' === severity_of($gtm_other, 'other_gtm_loader_only'), 'another GTM loader without our container is informational');

/* --- Rule 3: code injectors --------------------------------------------- */
$injector = ADC_Conflict_Detector::analyze(ctx(array('injector'), array(), array()));
check('info' === severity_of($injector, 'code_injector_active'), 'code injector plugin is reported as info');

/* --- Rule 4: platform plugins weighted by our browser mode -------------- */
$meta_direct = ADC_Conflict_Detector::analyze(ctx(array('meta'), array('platforms' => array('ga4' => 'off', 'meta' => 'direct', 'tiktok' => 'off')), array()));
check('conflict' === severity_of($meta_direct, 'other_meta_direct'), 'pixel plugin + our direct mode = conflict');
$meta_gtm = ADC_Conflict_Detector::analyze(ctx(array('meta'), array('platforms' => array('ga4' => 'off', 'meta' => 'gtm', 'tiktok' => 'off')), array()));
check('warning' === severity_of($meta_gtm, 'other_meta_gtm'), 'pixel plugin + our gtm mode = warning');
$meta_off = ADC_Conflict_Detector::analyze(ctx(array('meta'), array('platforms' => array('ga4' => 'off', 'meta' => 'off', 'tiktok' => 'off')), array()));
check('info' === severity_of($meta_off, 'other_meta_off'), 'pixel plugin with our Meta off is informational');

/* --- Rule 5: containers in the HTML ------------------------------------- */
$foreign = ADC_Conflict_Detector::analyze(ctx(array(), array('gtm_id' => 'GTM-AAA111', 'gtm_mode' => 'always_with_defaults'), array('gtm_ids' => array('GTM-AAA111' => 1, 'GTM-ZZZ999' => 1))));
check('conflict' === severity_of($foreign, 'foreign_gtm_container'), 'foreign GTM container in the page = conflict');
check(str_contains((string) ($foreign[0]['detail'] ?? ''), 'GTM-ZZZ999'), 'the foreign container id is named in the detail');

$duplicate = ADC_Conflict_Detector::analyze(ctx(array(), array('gtm_id' => 'GTM-AAA111', 'gtm_mode' => 'always_with_defaults'), array('gtm_ids' => array('GTM-AAA111' => 2))));
check('conflict' === severity_of($duplicate, 'duplicate_own_gtm'), 'own container twice in the page = conflict');

$before_consent = ADC_Conflict_Detector::analyze(ctx(array(), array('gtm_id' => 'GTM-AAA111', 'gtm_mode' => 'after_consent'), array('gtm_ids' => array('GTM-AAA111' => 1))));
check('conflict' === severity_of($before_consent, 'gtm_before_consent'), 'our container in the HTML under after_consent mode = conflict');

$missing = ADC_Conflict_Detector::analyze(ctx(array(), array('gtm_id' => 'GTM-AAA111', 'gtm_mode' => 'always_with_defaults'), array()));
check('warning' === severity_of($missing, 'own_gtm_missing'), 'configured container missing from the page = warning');

/* --- Rule 6: platform ids in the page ----------------------------------- */
$meta_page_conflict = ADC_Conflict_Detector::analyze(ctx(array(), array('platforms' => array('ga4' => 'off', 'meta' => 'direct', 'tiktok' => 'off')), array('meta_ids' => array('1234567890' => 2))));
check('conflict' === severity_of($meta_page_conflict, 'page_meta_duplicate'), 'Meta pixel in the page + our direct mode = conflict');
$ga4_page_info = ADC_Conflict_Detector::analyze(ctx(array(), array('platforms' => array('ga4' => 'off', 'meta' => 'off', 'tiktok' => 'off')), array('ga4_ids' => array('G-ABC123' => 1))));
check('info' === severity_of($ga4_page_info, 'page_ga4_external'), 'GA4 id in the page while ours is off is informational');
$ga4_page_warn = ADC_Conflict_Detector::analyze(ctx(array(), array('platforms' => array('ga4' => 'gtm', 'meta' => 'off', 'tiktok' => 'off')), array('ga4_ids' => array('G-ABC123' => 1))));
check('warning' === severity_of($ga4_page_warn, 'page_ga4_external'), 'GA4 id in the page while ours loads via GTM is a warning');

/* --- Rule 7: dataLayer resets ------------------------------------------- */
$reset = ADC_Conflict_Detector::analyze(ctx(array(), array(), array('datalayer_resets' => 2)));
check('warning' === severity_of($reset, 'datalayer_reset'), 'dataLayer resets = warning');
check(str_contains((string) ($reset[0]['detail'] ?? ''), '2'), 'the reset count appears in the detail');

/* --- Rule 8: foreign CMP markup in the page ----------------------------- */
$markup = ADC_Conflict_Detector::analyze(ctx(array(), array('banner' => true), array('cmp' => array('Complianz' => 3))));
check('conflict' === severity_of($markup, 'other_cmp_markup'), 'foreign CMP markup with our banner on = conflict');

/* --- Ordering + summary ------------------------------------------------- */
$ordered = ADC_Conflict_Detector::analyze(ctx(
    array('cmp', 'injector'),
    array('banner' => true, 'gtm_id' => 'GTM-AAA111', 'gtm_mode' => 'after_consent'),
    array('gtm_ids' => array('GTM-AAA111' => 1), 'datalayer_resets' => 1)
));
$severities = array_map(static fn (array $f): string => (string) $f['severity'], $ordered);
$first_info  = array_search('info', $severities, true);
$last_higher = max(array_search('conflict', $severities, true), array_search('warning', $severities, true));
check(false !== $first_info && $last_higher < $first_info, 'conflicts and warnings are listed before informational rows');
$summary = ADC_Conflict_Detector::summary($ordered);
check($summary['total'] === count($ordered) && $summary['conflict'] >= 1, 'summary counts every finding per severity');

/* --- html_signals: parsing fixtures ------------------------------------ */
$html = implode("\n", array(
    '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-AAA111&l=dataLayer"></script>',
    '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>',
    "gtag('config', 'G-XYZ789');",
    "fbq('init', '1122334455');",
    "ttq.load('CABC1234567890');",
    'window.dataLayer = window.dataLayer || [];',
    'dataLayer = [];',
    '<div class="cmplz-banner">Complianz</div>',
));
$signals = ADC_Conflict_Detector::html_signals($html);
check(isset($signals['gtm_ids']['GTM-AAA111']) && 1 === $signals['gtm_ids']['GTM-AAA111'], 'gtm.js id is extracted with its query parameters');
check(1 === ($signals['ga4_ids']['G-ABC123'] ?? 0) && 1 === ($signals['ga4_ids']['G-XYZ789'] ?? 0), 'gtag.js and gtag(config) ids are both extracted');
check(1 === ($signals['meta_ids']['1122334455'] ?? 0), 'Meta pixel id is extracted from fbq(init)');
check(1 === ($signals['tiktok_ids']['CABC1234567890'] ?? 0), 'TikTok pixel id is extracted from ttq.load');
check(1 === $signals['datalayer_resets'], 'only the explicit dataLayer reset is counted (the || pattern is ignored)');
check(isset($signals['cmp']['Complianz']), 'CMP markers are recognised in the page HTML');
$empty = ADC_Conflict_Detector::html_signals('');
check(
    array() === $empty['gtm_ids'] && array() === $empty['ga4_ids'] && array() === $empty['meta_ids']
    && array() === $empty['tiktok_ids'] && array() === $empty['cmp'] && 0 === $empty['datalayer_resets'],
    'empty HTML yields empty signals (no notices)'
);

/* --- 2.6.1 hardening: comments, long fragments, CMP needles, scan cap --- */

// HTML comments are not live markup: a commented-out loader must not
// produce a foreign-container conflict.
$commented = ADC_Conflict_Detector::html_signals('<!-- gtm.js?id=GTM-CMT1 --><div>shop</div>');
check(array() === $commented['gtm_ids'], 'a comment-only gtm.js snippet yields no gtm ids');
$commented_cmp = ADC_Conflict_Detector::html_signals('<!-- cookiebot banner --><div class="theme-grid">ok</div>');
check(array() === $commented_cmp['cmp'], 'CMP needles inside comments are not counted as live markup');
// A live snippet next to a commented one is still found.
$mixed = ADC_Conflict_Detector::html_signals('<!-- gtm.js?id=GTM-CMT1 --><script src="https://www.googletagmanager.com/gtm.js?id=GTM-LIVE2"></script>');
check(isset($mixed['gtm_ids']['GTM-LIVE2']) && !isset($mixed['gtm_ids']['GTM-CMT1']), 'live loader survives comment stripping');

// Real gtm.js URLs carry long query strings; the id must be found even when
// it sits more than 300 (but under 1200) characters after 'gtm.js'.
$pad = str_repeat('p=1&', 90); // 360 chars of query noise.
$long = '<script src="https://www.googletagmanager.com/gtm.js?' . $pad . 'id=GTM-LONG9&l=dataLayer"></script>';
$long_signals = ADC_Conflict_Detector::html_signals($long);
check(isset($long_signals['gtm_ids']['GTM-LONG9']), 'gtm.js id is found after a long query string (1200-char window)');

// The ultra-short CMP needles were removed: theme classes like cli_wrap or
// cky-grid must not register as CookieYes. The vendor needles still work.
$theme = ADC_Conflict_Detector::html_signals('<div class="cli_wrap cky-grid moove">theme markup</div>');
check(array() === $theme['cmp'], 'short theme-class prefixes no longer match as a CMP');
$vendor = ADC_Conflict_Detector::html_signals('<script src="https://cdn-cookieyes.com/assets/script.js"></script>');
check(isset($vendor['cmp']['CookieYes']), 'the cookieyes vendor needle still matches');

// scan_page caps the fetched body (defense in depth against oversized pages).
ADC_Conflict_Detector::scan_page('https://shop.test/');
check(2097152 === ($GLOBALS['adc_http_args']['limit_response_size'] ?? 0), 'scan_page sets limit_response_size (2 MB)');

/* --- 2.6.1 hardening: failed page scan must not blame the page ---------- */
$always_ctx = ctx(array(), array('gtm_id' => 'GTM-AAA111', 'gtm_mode' => 'always_with_defaults'), array());
$page_ok = ADC_Conflict_Detector::analyze($always_ctx + array('page' => array('ok' => true)));
check('warning' === severity_of($page_ok, 'own_gtm_missing'), 'successful scan without our container still warns');
$page_bad = ADC_Conflict_Detector::analyze($always_ctx + array('page' => array('ok' => false)));
check('' === severity_of($page_bad, 'own_gtm_missing'), 'failed page scan suppresses own_gtm_missing');
// No 'page' key at all keeps the legacy behaviour (warns) — old call sites
// and the offline context fixtures do not pass one.
check('warning' === severity_of(ADC_Conflict_Detector::analyze($always_ctx), 'own_gtm_missing'), 'context without page key keeps warning',
);
unset($GLOBALS['adc_http_args']);

/* --- Plugin fingerprints ------------------------------------------------- */
$GLOBALS['adc_options']['active_plugins'] = array(
    'duracelltomi-google-tag-manager/gtm4wp.php',
    'pixelyoursite-pro/pixelyoursite-pro.php',
    'complianz-gdpr/complianz.php',
    'woocommerce/woocommerce.php',
    'adc-marketing/adc-marketing.php',
);
$detected = ADC_Conflict_Detector::detected_plugins();
$labels   = array_column($detected, 'label');
$domains  = array_column($detected, 'domain');
check(array('GTM4WP', 'PixelYourSite', 'Complianz') === $labels, 'active plugin list maps to names: ' . implode(', ', $labels));
check(array('gtm', 'meta', 'cmp') === $domains, 'each detected plugin gets its domain');
check(3 === count($detected), 'unrelated plugins (WooCommerce, this plugin) are ignored');
$GLOBALS['adc_options']['active_plugins'] = array('custom-folder/google-tag-manager/init.php');
$single = ADC_Conflict_Detector::detected_plugins();
check('gtm' === ($single[0]['domain'] ?? ''), 'a GTM plugin in an unknown folder is still recognised by its path');

/* --- own(): reads the settings groups, no fatals ------------------------- */
$GLOBALS['adc_own'] = array('gtm_id' => 'GTM-OWN123', 'gtm_mode' => 'always_with_defaults', 'banner' => true);
$GLOBALS['adc_settings'] = array(
    'sgtm' => array('enabled' => true),
    'ga4'  => array('browser_mode' => 'direct', 'measurement_id' => 'g-abc123'),
    'meta' => array('browser_mode' => 'gtm', 'pixel_id' => '99887766'),
);
$own = ADC_Conflict_Detector::own();
check('GTM-OWN123' === $own['gtm_id'] && true === $own['banner'], 'own() exposes the live GTM id and banner state');
check('direct' === $own['platforms']['ga4'] && 'gtm' === $own['platforms']['meta'], 'own() exposes the per-platform browser modes');
check('G-ABC123' === $own['ga4_id'] && '99887766' === $own['meta_id'], 'own() normalises the configured platform ids');
check(true === $own['sgtm'], 'own() exposes the server container switch');

// 2.6.1: own() must read the group's real key `pixel_code` (§4.5), not the
// non-existent `pixel_id`.
$GLOBALS['adc_settings']['tiktok'] = array('browser_mode' => 'direct', 'pixel_code' => 'CTIKTOK1', 'pixel_id' => 'WRONGKEY');
$own_tiktok = ADC_Conflict_Detector::own();
check('CTIKTOK1' === $own_tiktok['tiktok_id'], 'own() reads tiktok.pixel_code, not pixel_id');

exit($failed > 0 ? 1 : 0);
