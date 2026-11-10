<?php
/**
 * Partial-write hardening for every ADC_Settings group sanitizer (2.6.1).
 *
 * Root cause class (the 2.6.0 GTM bug): a sanitizer that derives a key from
 * `$input['key'] ?? <default-literal>` RESETS the stored value whenever the
 * key is absent from the input. The contract now enforced here, through the
 * REAL ADC_Settings::update():
 *
 *   - a key ABSENT from the input keeps the stored value (per group merge
 *     of $current);
 *   - an EXPLICIT empty/0/false from the form still applies (clears).
 *
 * Admin forms are unaffected: every tab's save() builds a complete input
 * (unchecked boxes normalized to explicit false/empty), so what changes is
 * only the behaviour of hypothetical future partial programmatic writers.
 *
 * No WordPress, no network. Usage: php tests/settings-partial-write-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['adc_options'] = array();

// --- WordPress stubs ------------------------------------------------------
function __(string $text, string $domain = 'default'): string { return $text; }
function _n(string $single, string $plural, int $number, string $domain = 'default'): string { return 1 === $number ? $single : $plural; }
function esc_html__(string $text, string $domain = 'default'): string { return $text; }
function esc_attr__(string $text, string $domain = 'default'): string { return $text; }
function add_action(mixed ...$args): bool { return true; }
function add_filter(mixed ...$args): bool { return true; }
function do_action(mixed ...$args): void {}
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['adc_options'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['adc_options'][$key] = $value; return true; }
function sanitize_text_field(mixed $value): string { return trim(strip_tags((string) $value)); }
function sanitize_key(mixed $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? ''; }
function esc_url_raw(mixed $value): string { return (string) $value; }
function wp_http_validate_url(mixed $value): int|false { return str_starts_with((string) $value, 'https://') ? 443 : false; }
function absint(mixed $value): int { return abs((int) $value); }
function wp_kses_post(mixed $value): string { return (string) $value; }
function do_shortcode(string $value): string { return $value; }
function get_privacy_policy_url(): string { return ''; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }

// Deterministic seal/open: update() recognises the enc1: prefix and never
// re-seals stored secrets.
final class ADC_Secrets
{
    public static function seal(string $value): string { return '' === $value ? '' : 'enc1:' . $value; }
    public static function open(string $value): string { return str_starts_with($value, 'enc1:') ? substr($value, 5) : $value; }
}

// --- Real classes under test ----------------------------------------------
require dirname(__DIR__) . '/includes/core/interface-adc-module.php';
require dirname(__DIR__) . '/includes/core/interface-adc-integration.php';
require dirname(__DIR__) . '/includes/admin/interface-adc-admin-tab.php';
require dirname(__DIR__) . '/includes/admin/trait-adc-admin-help-content.php';
require dirname(__DIR__) . '/includes/core/class-adc-settings.php';
require dirname(__DIR__) . '/includes/consent/class-adc-consent-banner.php';
require dirname(__DIR__) . '/includes/integrations/class-adc-integration-sgtm.php';
require dirname(__DIR__) . '/includes/integrations/class-adc-integration-meta.php';
require dirname(__DIR__) . '/includes/integrations/class-adc-integration-ga4.php';
require dirname(__DIR__) . '/includes/integrations/class-adc-integration-tiktok.php';
require dirname(__DIR__) . '/includes/tracking/class-adc-advanced-matching.php';
require dirname(__DIR__) . '/includes/tracking/class-adc-event-hooks.php';
require dirname(__DIR__) . '/includes/admin/class-adc-admin-tab-logs.php';

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

/**
 * Per-group scenario: distinctive seed values (all valid for the sanitizer),
 * one single-key write with the value it must produce, and an explicit
 * empty/false write that must still apply (the documented clears).
 *
 * @return array<string, array{class: string, seed: array, single: array{0: string, 1: mixed, 2: mixed}, clear: array{0: string, 1: mixed}}>
 */
function scenarios(): array
{
    return array(
        'banner'   => array(
            'class'  => 'ADC_Consent_Banner',
            // preset=custom so the preset bake cannot rewrite colors/radius.
            'seed'   => array(
                'show_floating_button' => true,
                'title'                => 'Bannerul seed',
                'privacy_page_id'      => 9,
                'preset'               => 'custom',
            ),
            'single' => array('title', 'Titlu schimbat', 'Titlu schimbat'),
            'clear'  => array('show_floating_button', false),
        ),
        'sgtm'     => array(
            'class'  => 'ADC_Integration_Sgtm',
            'seed'   => array(
                'enabled'              => true,
                'server_container_url' => 'https://gtm.exemplu.ro',
                'mode'                 => 'mp_forward',
                'api_secret'           => 'seedsecret',
                'measurement_id'       => 'G-SEED123',
            ),
            'single' => array('mode', 'loader', 'loader'),
            'clear'  => array('server_container_url', ''),
        ),
        'meta'     => array(
            'class'  => 'ADC_Integration_Meta',
            'seed'   => array(
                'enabled_server'  => true,
                'browser_mode'    => 'direct',
                'pixel_id'        => '1234567890',
                'access_token'    => 'seedtoken',
                'test_event_code' => 'TESTCODE',
                'api_version'     => 'v25.0',
            ),
            'single' => array('api_version', 'v30.0', 'v30.0'),
            'clear'  => array('pixel_id', ''),
        ),
        'ga4'      => array(
            'class'  => 'ADC_Integration_Ga4',
            'seed'   => array(
                'enabled_server'     => true,
                'browser_mode'       => 'direct',
                'measurement_id'     => 'G-SEEDGA4',
                'api_secret'         => 'seedsecret',
                'send_when_denied'   => true,
                'debug'              => true,
                'fallback_client_id' => true,
                'endpoint_region'    => 'global',
                'purchase_channel'   => 'server',
            ),
            'single' => array('endpoint_region', 'eu', 'eu'),
            'clear'  => array('measurement_id', ''),
        ),
        'tiktok'   => array(
            'class'  => 'ADC_Integration_Tiktok',
            'seed'   => array(
                'enabled_server'  => true,
                'browser_mode'    => 'direct',
                'pixel_code'      => 'CTIKTOKSEED',
                'access_token'    => 'seedtoken',
                'test_event_code' => 'TESTTT',
            ),
            'single' => array('browser_mode', 'gtm', 'gtm'),
            'clear'  => array('pixel_code', ''),
        ),
        'matching' => array(
            'class'  => 'ADC_Advanced_Matching',
            'seed'   => array(
                'enabled'          => true,
                'capture_checkout' => false,
                'capture_account'  => false,
                'use_external_id'  => false,
                'calling_code'     => '1',
                'form_selectors'   => '.selector-unu',
                'send_to'          => array('ga4'),
                'datalayer_format' => 'plain',
            ),
            'single' => array('calling_code', '33', '33'),
            'clear'  => array('form_selectors', ''),
        ),
        'events'   => array(
            'class'  => 'ADC_Event_Hooks',
            'seed'   => array(
                'server_page_view'    => true,
                'view_item'           => false,
                'purchase'            => true,
                'item_id_source'      => 'sku',
                'value_mode'          => 'incl_tax',
                'server_delay_seconds' => 120,
                'exclude_roles'       => array('editor'),
                'purchase_statuses'   => array('cancelled'),
                'brand_taxonomy'      => 'brand_alt',
                'outbox_sent_days'    => 7,
                'outbox_dead_days'    => 21,
            ),
            'single' => array('value_mode', 'excl_tax_excl_shipping', 'excl_tax_excl_shipping'),
            'clear'  => array('purchase', false),
        ),
        'logs'     => array(
            'class'  => 'ADC_Admin_Tab_Logs',
            'seed'   => array(
                'level'         => 'debug',
                'debug_console' => true,
            ),
            'single' => array('level', 'info', 'info'),
            'clear'  => array('debug_console', false),
        ),
    );
}

foreach (scenarios() as $group => $case) {
    // Fresh option storage per group: register + seed through the real API.
    $GLOBALS['adc_options'] = array();
    $reflection             = new ReflectionClass(ADC_Settings::class);
    $groups_property        = $reflection->getProperty('groups');
    $groups_property->setAccessible(true);
    $registered             = $groups_property->getValue();
    unset($registered[$group]);
    $groups_property->setValue(null, $registered);

    call_user_func(array($case['class'], 'register_settings'));

    $full_seed = array_merge(ADC_Settings::all($group), $case['seed']);
    $seeded    = ADC_Settings::update($group, $full_seed);

    foreach ($case['seed'] as $key => $want) {
        $got = $seeded[$key] ?? null;
        // Secret keys are stored sealed; compare unsealed.
        if (is_string($got) && str_starts_with($got, 'enc1:')) {
            $got = ADC_Secrets::open($got);
        }
        check($want === $got, "$group: seed applied for '$key'");
    }

    // 1. Empty input (maximum partial write): nothing may move.
    $after_empty = ADC_Settings::update($group, array());
    check($seeded === $after_empty, "$group: update(group, []) keeps every stored key");

    // 2. Single-key write: only that key may change.
    [$single_key, $single_input, $single_expected] = $case['single'];
    $after_single = ADC_Settings::update($group, array($single_key => $single_input));
    check(
        ($after_single[$single_key] ?? null) === $single_expected,
        "$group: update(group, ['$single_key']) applies the new value"
    );
    foreach ($seeded as $key => $value) {
        if ($key === $single_key) {
            continue;
        }
        check(($after_single[$key] ?? null) === $value, "$group: single-key write keeps '$key'");
    }

    // 3. Explicit empty/false still applies where the group documents it.
    [$clear_key, $clear_value] = $case['clear'];
    $after_clear = ADC_Settings::update($group, array($clear_key => $clear_value));
    check(
        ($after_clear[$clear_key] ?? null) === $clear_value,
        "$group: explicit " . (true === (string) $clear_value ? "''" : var_export($clear_value, true)) . " still clears '$clear_key'"
    );
    $others = array_diff(array_keys($seeded), array($clear_key, $single_key));
    foreach ($others as $key) {
        check(($after_clear[$key] ?? null) === ($after_single[$key] ?? null), "$group: clear write keeps '$key'");
    }
}

// Extra documented semantics:
// - logs: an explicit but invalid level still falls back to 'error' (present
//   = typed), while absent keeps the stored value.
$GLOBALS['adc_options'] = array();
call_user_func(array('ADC_Admin_Tab_Logs', 'register_settings'));
ADC_Settings::update('logs', array('level' => 'debug', 'debug_console' => true));
$invalid = ADC_Settings::update('logs', array('level' => ''));
check('error' === $invalid['level'], 'logs: explicit empty level applies the typed fallback (error)');
check(true === $invalid['debug_console'], 'logs: explicit empty level keeps debug_console');

// - events: an explicit empty role list clears (the Events tab normalises
//   unselected multi-checkboxes to array(), so the form keeps clearing).
ADC_Settings::update('events', array('exclude_roles' => array('administrator', 'shop_manager'), 'purchase' => true));
$cleared_roles = ADC_Settings::update('events', array('exclude_roles' => array()));
check(array() === $cleared_roles['exclude_roles'], 'events: explicit empty exclude_roles clears');
check(true === $cleared_roles['purchase'], 'events: explicit empty exclude_roles keeps purchase');

echo (0 === $failed ? 'PASS' : "FAILED: $failed") . ": settings partial-write contract\n";
exit(0 === $failed ? 0 : 1);
