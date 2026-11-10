<?php

declare(strict_types=1);

// Standalone regression test for the GPC read-time marketing clamp.
// With consent.honor_gpc on and `Sec-GPC: 1` on the request, a previously
// stored m=1 must be denied at READ time (ADC_Consent::current(), the
// server-rendered Consent Mode head script and the JS effective state),
// not only when a new decision is written. The stored cookie/user meta is
// never rewritten. No WordPress required.
define('ABSPATH', __DIR__ . '/');

interface ADC_Module_Interface {}

$GLOBALS['adc_gpc_settings'] = array();
$GLOBALS['adc_gpc_logged_in'] = false;
$GLOBALS['adc_gpc_user_id'] = 0;
$GLOBALS['adc_gpc_user_meta'] = '';

final class ADC_Settings
{
    public static function get(string $group, string $key, $default = null)
    {
        return array_key_exists($key, $GLOBALS['adc_gpc_settings'])
            ? $GLOBALS['adc_gpc_settings'][$key]
            : $default;
    }
}

final class ADC_Consent_Privacy
{
    public const USER_META = '_adc_cookie_consent';
}

function add_action(...$args): void {}
function add_filter(...$args): void {}
function is_user_logged_in(): bool { return (bool) $GLOBALS['adc_gpc_logged_in']; }
function get_current_user_id(): int { return (int) $GLOBALS['adc_gpc_user_id']; }
function get_user_meta(int $user_id, string $key, bool $single = false) { return $GLOBALS['adc_gpc_user_meta']; }
function get_option(string $name, $default = false) { return array(); }
function sanitize_text_field(string $value): string { return $value; }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); }
function wp_json_encode($value, int $flags = 0): string|false { return json_encode($value, $flags); }

require __DIR__ . '/../includes/consent/class-adc-consent-state.php';
require __DIR__ . '/../includes/consent/class-adc-consent-mode.php';
require __DIR__ . '/../includes/consent/class-adc-consent.php';
require __DIR__ . '/../includes/tracking/class-adc-datalayer.php';

function check(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

function gpc_settings(array $settings): void {
    $GLOBALS['adc_gpc_settings'] = $settings;
}

function reset_request(): void {
    $_COOKIE = array();
    $_SERVER = array();
    $GLOBALS['adc_gpc_logged_in'] = false;
    $GLOBALS['adc_gpc_user_id'] = 0;
    $GLOBALS['adc_gpc_user_meta'] = '';
}

function grant_cookie(): string {
    $state = new ADC_Consent_State(true, true, true, true, time(), 1, '123e4567-e89b-42d3-a456-426614174000', 'banner');
    return $state->to_cookie_value();
}

function store_granted_cookie(): string {
    $raw = grant_cookie();
    $_COOKIE[ADC_Consent::COOKIE_NAME] = $raw;
    return $raw;
}

// 1. Stored m=1 + Sec-GPC: 1 + honour_gpc on -> marketing denied on read.
reset_request();
gpc_settings(array('honor_gpc' => true));
$stored = store_granted_cookie();
$_SERVER['HTTP_SEC_GPC'] = '1';

$state = ADC_Consent::current();
check(!$state->allows('marketing'), 'GPC clamps a previously granted marketing consent on read');
check($state->source === 'gpc', 'effective source is gpc when the clamp bites');
check($state->allows('statistics'), 'statistics consent unaffected by GPC');
check($state->allows('preferences'), 'preferences consent unaffected by GPC');
check($state->decided && $state->ts > 0, 'the decision stays decided (no re-prompt)');
check($state->cid === '123e4567-e89b-42d3-a456-426614174000', 'cid preserved by the clamp');
check($_COOKIE[ADC_Consent::COOKIE_NAME] === $stored, 'the stored cookie is never rewritten at read time');

$signals = ADC_Consent::gcm_signals();
check($signals['ad_storage'] === 'denied' && $signals['ad_user_data'] === 'denied' && $signals['ad_personalization'] === 'denied', 'server Consent Mode ad signals denied under GPC');
check($signals['analytics_storage'] === 'granted', 'analytics_storage stays granted for s=1 under GPC');
check($signals['functionality_storage'] === 'granted' && $signals['personalization_storage'] === 'granted', 'preferences signals unaffected by GPC');

// 2. honour_gpc on but no Sec-GPC header -> behaviour unchanged (granted).
reset_request();
store_granted_cookie();
$state = ADC_Consent::current();
check($state->allows('marketing'), 'marketing granted without a GPC header');
check($state->source === 'banner', 'source preserved without a GPC header');
check(ADC_Consent::gcm_signals()['ad_storage'] === 'granted', 'ad_storage granted without a GPC header');

// 3. Sec-GPC header present but honour_gpc off -> granted (setting contract).
reset_request();
gpc_settings(array('honor_gpc' => false));
$_SERVER['HTTP_SEC_GPC'] = '1';
store_granted_cookie();
check(ADC_Consent::current()->allows('marketing'), 'honour_gpc off ignores the GPC header');
check(ADC_Consent::gcm_signals()['ad_storage'] === 'granted', 'ad_storage granted when honour_gpc is off');

// 4. Non-"1" header values are not an opt-out (matches the analytics convention).
reset_request();
gpc_settings(array('honor_gpc' => true));
$_SERVER['HTTP_SEC_GPC'] = '0';
store_granted_cookie();
check(ADC_Consent::current()->allows('marketing'), 'Sec-GPC: 0 does not clamp');

// 5. The clamp covers the logged-in user-meta fallback too.
reset_request();
gpc_settings(array('honor_gpc' => true));
$_SERVER['HTTP_SEC_GPC'] = '1';
$GLOBALS['adc_gpc_logged_in'] = true;
$GLOBALS['adc_gpc_user_id'] = 7;
$GLOBALS['adc_gpc_user_meta'] = (string) json_encode(array(
    'preferences' => true, 'statistics' => true, 'marketing' => true,
    'decided' => true, 'ts' => time(), 'policy' => 1,
    'cid' => '123e4567-e89b-42d3-a456-426614174000', 'source' => 'account',
));
$state = ADC_Consent::current();
check(!$state->allows('marketing'), 'GPC clamps even when user meta says marketing granted');
check($state->source === 'gpc', 'user-meta clamp records the effective gpc source');
check($state->allows('statistics') && $state->allows('preferences'), 'user-meta statistics/preferences unaffected');

// 6. No downgrade bite -> source untouched; undecided stays denied either way.
reset_request();
gpc_settings(array('honor_gpc' => true));
$_SERVER['HTTP_SEC_GPC'] = '1';
$denied_marketing = new ADC_Consent_State(true, true, false, true, time(), 1, '123e4567-e89b-42d3-a456-426614174000', 'banner');
$_COOKIE[ADC_Consent::COOKIE_NAME] = $denied_marketing->to_cookie_value();
$state = ADC_Consent::current();
check($state->source === 'banner', 'source is not rewritten when the clamp does not bite');
reset_request();
gpc_settings(array('honor_gpc' => true));
$_SERVER['HTTP_SEC_GPC'] = '1';
check(!ADC_Consent::current()->allows('marketing'), 'GPC on an undecided request stays denied');

// 7. Head bootstrap: GPC clamp baked in only when honour_gpc is on.
reset_request();
gpc_settings(array('honor_gpc' => true));
$js_gpc = ADC_Consent_Mode::render_head_script();
check(strpos($js_gpc, 'navigator.globalPrivacyControl===true') !== false, 'head bootstrap reads the GPC signal at runtime');
check(strpos($js_gpc, 'c.m==="1"&&!gp') !== false, 'head bootstrap marketing read is clamped by GPC');
check(strpos($js_gpc, '(gp&&c.m==="1")?"gpc"') !== false, 'head bootstrap records the gpc source when clamped');
check(strpos($js_gpc, 'g.ad_storage=g.ad_user_data=g.ad_personalization=st.marketing') !== false, 'ad signals still follow the clamped state');

gpc_settings(array('honor_gpc' => false));
$js_off = ADC_Consent_Mode::render_head_script();
check(strpos($js_off, 'globalPrivacyControl') === false, 'head bootstrap is GPC-free when honour_gpc is off');
check(strpos($js_off, 'marketing:c.m==="1",decided:true,') !== false, 'honour_gpc off keeps the pre-fix marketing read byte-identical');
check(strpos($js_off, 'source:c.src||""') !== false, 'honour_gpc off keeps the pre-fix source read byte-identical');
check(strpos($js_off, 'g.ad_storage=g.ad_user_data=g.ad_personalization=st.marketing?"granted":"denied";') !== false, 'honour_gpc off keeps the pre-fix signal update byte-identical');

// 7b. Fallback head script (used while C2 is unavailable): same GPC read
// clamp as C2 — the built-in cookie read must deny the marketing/ad
// signals under navigator.globalPrivacyControl, not only apply it to new
// decisions.
$fb = new ReflectionMethod('ADC_Datalayer', 'fallback_consent_script');
$fb->setAccessible(true);
$render_fb = static function (array $settings) use ($fb): string {
    gpc_settings($settings);
    $one = $fb->invoke(null);
    check(is_string($one) && '' !== $one, 'fallback consent script renders');
    return (string) $one;
};
$fb_gpc = $render_fb(array('honor_gpc' => true));
check(strpos($fb_gpc, 'navigator.globalPrivacyControl===true') !== false, 'fallback script reads the GPC signal at runtime');
check(strpos($fb_gpc, 'p.m==="1"&&!gp') !== false, 'fallback script clamps the stored marketing read by GPC');
check(substr_count($fb_gpc, 'p.m==="1"&&!gp') === 2, 'fallback clamp covers both the ad signals and the effective state');
check(strpos($fb_gpc, '(gp&&p.m==="1")?"gpc"') !== false, 'fallback script records the gpc source when clamped');
$fb_off = $render_fb(array('honor_gpc' => false));
check(strpos($fb_off, 'globalPrivacyControl') === false, 'fallback script is GPC-free when honour_gpc is off');
check(strpos($fb_off, 'u.ad_storage=u.ad_user_data=u.ad_personalization=p.m==="1"?"granted":"denied";') !== false, 'honour_gpc off keeps the pre-fix fallback bytes identical');
check(strpos($fb_off, 'marketing:p.m==="1",decided:true,') !== false, 'honour_gpc off keeps the pre-fix fallback marketing read');
check(strpos($fb_off, 'source:p.src||""') !== false, 'honour_gpc off keeps the pre-fix fallback source read');

// 8. Static check: the JS effective state applies the same read clamp.
$manager = (string) file_get_contents(__DIR__ . '/../assets/js/consent-manager.js');
$start = strpos($manager, 'function loadState(');
check(false !== $start, 'loadState() exists in consent-manager.js');
$end = strpos($manager, 'function writeCookie(', (int) $start);
check(false !== $end, 'writeCookie() follows loadState()');
$region = substr($manager, (int) $start, (int) $end - (int) $start);
check(strpos($region, "gpcActive() && parsed.categories.marketing") !== false, 'loadState() clamps stored marketing under active GPC');
check(strpos($region, "parsed.categories.marketing = false;") !== false, 'loadState() denies marketing in the effective state');
check(strpos($region, "parsed.source = 'gpc';") !== false, 'loadState() records the gpc source when clamping');
check(strpos($region, 'writeCookie') === false, 'loadState() never rewrites the stored cookie');

// 9. Runtime JS check (when node is available): stored m=1 + GPC -> pixels
// see marketing denied through the public adcConsent contract.
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ('' !== $node) {
    $script = <<<'JS'
'use strict';
const assert = require('assert/strict');
const fs = require('fs');
const vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');
const uuid = '123e4567-e89b-42d3-a456-426614174000';
const ts = Math.floor(Date.now() / 1000);
const cookieValue = encodeURIComponent(`v=1&p=1&s=1&m=1&pv=1&ts=${ts}&cid=${uuid}&src=banner`);
function harness(consent, gpcSignal) {
    const jar = new Map([['adc_consent', cookieValue]]);
    const doc = {
        get cookie() { return [...jar].map(([k, v]) => `${k}=${v}`).join('; '); },
        set cookie(_value) { throw new Error('loadState must not rewrite the cookie'); },
        dispatchEvent() {},
        addEventListener() {}
    };
    const win = {
        location: { protocol: 'https:', hostname: 'shop.example.com' },
        adcConfig: { consent: Object.assign({ days: 180, policy: 1, inventory: [] }, consent) },
        crypto: { randomUUID: () => uuid },
        dataLayer: []
    };
    vm.runInNewContext(src, {
        window: win, document: doc, navigator: { globalPrivacyControl: gpcSignal },
        CustomEvent: function (type, init) { this.type = type; this.detail = init && init.detail; },
        Date, Promise, JSON, Math, parseInt, isFinite, Array, String, Object, RegExp, Uint8Array,
        encodeURIComponent, decodeURIComponent, Error
    }, { filename: 'consent-manager.js' });
    return win.adcConsent;
}
const gpc = harness({ gpc: true }, true);
assert.equal(gpc.get().categories.marketing, false, 'JS effective state denies stored marketing under GPC');
assert.equal(gpc.get().source, 'gpc', 'JS effective source is gpc when clamped');
assert.equal(gpc.has('marketing'), false, 'whenAllowed(marketing) never fires under GPC');
assert.equal(gpc.gcm().ad_storage, 'denied', 'JS gcm ad_storage denied under GPC');
assert.equal(gpc.get().categories.statistics, true, 'JS statistics unaffected');
assert.equal(gpc.get().categories.preferences, true, 'JS preferences unaffected');
assert.equal(harness({ gpc: true }, false).get().categories.marketing, true, 'no signal keeps stored marketing');
assert.equal(harness({ gpc: false }, true).get().categories.marketing, true, 'honour_gpc off ignores the signal');
console.log('PASS: consent-manager GPC read clamp');
JS;
    $tmp = (string) tempnam(sys_get_temp_dir(), 'adc-gpc-');
    file_put_contents($tmp, $script);
    $out = array();
    $code = 0;
    exec($node . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg(__DIR__ . '/../assets/js/consent-manager.js') . ' 2>&1', $out, $code);
    unlink($tmp);
    check(0 === $code, 'node runtime check: ' . trim(implode("\n", $out)));
} else {
    echo "SKIP: node not available for the JS runtime check\n";
}

echo "PASS: GPC read-time marketing clamp\n";
