<?php
/**
 * Offline checks for ADC_Custom_Event_Rule (validate, scrub, migrate, to_frontend).
 * Spec: docs/CUSTOM-EVENTS.md §2.2, §2.3, §4.3, §8.4 (C4).
 */
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', '/offline/');
function __(string $text, string $domain = ''): string { return $text; }
function sanitize_text_field(string $value): string { return trim($value); }

require dirname(__DIR__) . '/includes/tracking/class-adc-custom-event-rule.php';

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

/** Base raw rule: a valid click/tel rule; $overrides merged shallowly into it. */
function raw_rule(array $overrides = array()): array
{
    $base = array(
        'label' => 'Click telefon header',
        'name' => 'custom_phone_click',
        'trigger' => array('type' => 'click', 'preset' => 'tel'),
    );
    return array_replace_recursive($base, $overrides);
}

/** @return list<string> Validation errors for a raw rule. */
function errors_of(array $raw, array $others = array()): array
{
    return ADC_Custom_Event_Rule::validate($raw, $others)['errors'];
}

function is_valid_raw(array $raw, array $others = array()): bool
{
    $result = ADC_Custom_Event_Rule::validate($raw, $others);
    return null !== $result['rule'] && array() === $result['errors'];
}

// 1. Minimal valid rule for every trigger type.
check(is_valid_raw(raw_rule()), 'click/tel minimal rule should be valid');
check(is_valid_raw(raw_rule(array('trigger' => array('type' => 'click', 'preset' => '', 'selector' => 'a.btn')))), 'click with selector should be valid');
check(is_valid_raw(raw_rule(array('trigger' => array('type' => 'form_success', 'form_preset' => 'cf7')))), 'form_success minimal should be valid');
check(is_valid_raw(raw_rule(array('trigger' => array('type' => 'visible', 'selector' => '.hero')))), 'visible minimal should be valid');
check(is_valid_raw(raw_rule(array('trigger' => array('type' => 'scroll_depth', 'depths' => array(25, 50))))), 'scroll_depth minimal should be valid');
check(is_valid_raw(raw_rule(array('trigger' => array('type' => 'timer', 'seconds' => 30)))), 'timer minimal should be valid');

// 2. Invalid names.
foreach (array('custom_', 'custom_A', 'custom_1x', 'phone_click', str_repeat('custom_a', 1) . str_repeat('b', 34)) as $bad) {
    check(array() !== errors_of(raw_rule(array('name' => $bad))), "name '$bad' should be rejected");
}
check(strlen('custom_' . str_repeat('b', 33)) === 40 && is_valid_raw(raw_rule(array('name' => 'custom_' . str_repeat('b', 33)))), '40-char name should be accepted');
check(array() !== errors_of(raw_rule(array('name' => 'custom_' . str_repeat('b', 34)))), '41-char name should be rejected');

// 3. Duplicate name against another rule.
$other = ADC_Custom_Event_Rule::validate(raw_rule(), array())['rule'];
check(null !== $other, 'setup: other rule must validate');
check(array() !== errors_of(raw_rule(array('label' => 'Alt')), array($other)), 'duplicate name should be rejected');

// 4. Invalid selectors.
foreach (array('<script>', 'a{color}', 'a;b', str_repeat('a', 201), 'div[data-x', 'div)') as $bad) {
    $raw = raw_rule(array('trigger' => array('type' => 'visible', 'selector' => $bad)));
    check(array() !== errors_of($raw), 'selector ' . substr($bad, 0, 20) . ' should be rejected');
}

// 5. Scroll and timer bounds.
check(array() !== errors_of(raw_rule(array('trigger' => array('type' => 'scroll_depth', 'depths' => array())))), 'empty depths should be rejected');
check(array() !== errors_of(raw_rule(array('trigger' => array('type' => 'timer', 'seconds' => 4)))), 'timer 4s should be rejected');
check(array() !== errors_of(raw_rule(array('trigger' => array('type' => 'timer', 'seconds' => 1801)))), 'timer 1801s should be rejected');
check(is_valid_raw(raw_rule(array('trigger' => array('type' => 'timer', 'seconds' => 1800)))), 'timer 1800s should be accepted');

// 6. Page paths: must start with '/' and carry no query.
check(array() !== errors_of(raw_rule(array('page_filter' => array('mode' => 'include', 'paths' => array('shop'))))), 'path without slash should be rejected');
check(array() !== errors_of(raw_rule(array('page_filter' => array('mode' => 'include', 'paths' => array('/a?b=1'))))), 'path with query should be rejected');
check(is_valid_raw(raw_rule(array('page_filter' => array('mode' => 'include', 'paths' => array('/produse/*'))))), 'wildcard path should be accepted');

// 7. Parameters: count, reserved keys, token availability.
$eleven = array();
for ($i = 0; $i < 11; $i++) {
    $eleven[] = array('key' => 'p' . $i, 'value' => 'v');
}
check(array() !== errors_of(raw_rule(array('params' => $eleven))), '11 parameters should be rejected');
foreach (array('email', 'gtm_x', 'adc_rule_id') as $reserved) {
    check(array() !== errors_of(raw_rule(array('params' => array(array('key' => $reserved, 'value' => 'x'))))), "reserved key '$reserved' should be rejected");
}
check(array() !== errors_of(raw_rule(array('params' => array(array('key' => 'form', 'value' => '{{form_id}}'))))), '{{form_id}} on click should be rejected');
check(is_valid_raw(raw_rule(array('params' => array(array('key' => 'link_url', 'value' => '{{click_url}}'))))), '{{click_url}} on click should be accepted');

// 8. Value requires currency; meta requires marketing; platforms force server_mirror.
check(array() !== errors_of(raw_rule(array('value' => 10, 'currency' => ''))), 'value without currency should be rejected');
check(is_valid_raw(raw_rule(array('value' => 10, 'currency' => 'RON'))), 'value with currency should be accepted');
check(array() !== errors_of(raw_rule(array('consent_category' => 'statistics', 'destinations' => array('platforms' => array('meta'))))), 'meta with statistics should be rejected');
$forced = ADC_Custom_Event_Rule::validate(raw_rule(array('destinations' => array('server_mirror' => false, 'platforms' => array('ga4')))))['rule'];
check(null !== $forced && true === $forced['destinations']['server_mirror'], 'non-empty platforms must force server_mirror=true');

// 9. Sanitized defaults are always present.
$clean = ADC_Custom_Event_Rule::validate(raw_rule())['rule'];
check(null !== $clean && 'once' === $clean['fire'] && 'statistics' === $clean['consent_category'] && false === $clean['destinations']['server_mirror'], 'defaults: fire=once, category=statistics, server_mirror=false');

// 10. scrub(): eight vectors (§4.3).
$scrub_cases = array(
    'ion@example.ro' => '[email]',
    'Sună 0722 123 456 acum' => 'Sună [nr] acum',
    '+40-722-123-456' => '+[nr]',
    '1800101123456' => '[nr]',
    'RO49AAAA1B31007593840000' => '[iban]',
    'abcdefghijklmnopqrstuvwxyzABCDEFGHIJ' => '[id]',
    'Bună ziua, vă mulțumesc' => 'Bună ziua, vă mulțumesc',
    'Cumpără acum, livrare în 2 zile' => 'Cumpără acum, livrare în 2 zile',
);
foreach ($scrub_cases as $input => $expected) {
    $input = (string) $input; // numeric-looking keys become ints in PHP arrays
    $actual = ADC_Custom_Event_Rule::scrub($input);
    check($actual === $expected, "scrub('$input') = '$actual', expected '$expected'");
}
check(1 === preg_match('/^\+\[nr\]$/', ADC_Custom_Event_Rule::scrub('+40-722-123-456')), 'scrub must not leave digit runs behind');

// 11. migrate(): missing schema version -> 1, idempotent, future version -> locked.
$legacy = ADC_Custom_Event_Rule::migrate(array('rules' => array()));
check(1 === $legacy['schema_version'], 'missing schema_version must migrate to 1');
$once = ADC_Custom_Event_Rule::migrate(array('schema_version' => 1, 'revision' => 4, 'rules' => array(raw_rule())));
check($once === ADC_Custom_Event_Rule::migrate($once), 'migrate must be idempotent');
check(1 === count($once['rules']) && 4 === $once['revision'], 'migrate keeps valid rule and revision');
$future = ADC_Custom_Event_Rule::migrate(array('schema_version' => 99, 'revision' => 3, 'rules' => array(raw_rule())));
check(true === ($future['locked'] ?? false) && array() === $future['rules'], 'schema 99 must be locked with no rules');

// 12. to_frontend(): no label, no platforms.
$frontend = ADC_Custom_Event_Rule::to_frontend($clean);
$encoded = json_encode($frontend, JSON_THROW_ON_ERROR);
check(!array_key_exists('label', $frontend) && !str_contains($encoded, 'Click telefon header'), 'to_frontend must not expose label');
check(!str_contains($encoded, 'platforms') && array_key_exists('mirror', $frontend), 'to_frontend must not expose platforms, only mirror flag');

/** Raw rule with an explicit trigger (merged over the click/tel base). */
function trigger_raw(array $trigger, array $overrides = array()): array
{
    return raw_rule(array_replace_recursive(array('trigger' => $trigger), $overrides));
}

// 13. MAX_RULES = 50: a 51st rule is refused by validate() and dropped by migrate().
$many = array();
for ($i = 1; $i <= 51; $i++) {
    $many[] = raw_rule(array('name' => sprintf('custom_e%02d', $i), 'id' => sprintf('ce_%08x', $i)));
}
$pool50 = ADC_Custom_Event_Rule::migrate(array('rules' => array_slice($many, 0, 50)))['rules'];
check(50 === count($pool50), 'setup: 50 valid rules must be stored');
check(array() !== ADC_Custom_Event_Rule::validate(raw_rule(array('name' => 'custom_extra')), $pool50)['errors'], 'the 51st rule must be refused (MAX_RULES=50)');
check(array() === ADC_Custom_Event_Rule::validate(raw_rule(array('name' => 'custom_extra')), array_slice($pool50, 0, 49))['errors'], 'the 50th rule must still be accepted');
$capped = ADC_Custom_Event_Rule::migrate(array('rules' => $many))['rules'];
check(50 === count($capped) && !in_array('custom_e51', array_column($capped, 'name'), true), 'migrate must keep 50 rules and drop the 51st');

// 14. MAX_VISIBLE_RULES = 20: enabled visible rules are capped; disabled ones are not counted.
$visible_raws = array();
for ($i = 1; $i <= 21; $i++) {
    $visible_raws[] = trigger_raw(array('type' => 'visible', 'selector' => '.hero'), array('name' => sprintf('custom_v%02d', $i), 'id' => sprintf('ce_%08x', 0x100 + $i)));
}
$visible20 = ADC_Custom_Event_Rule::migrate(array('rules' => $visible_raws))['rules'];
check(20 === count($visible20) && !in_array('custom_v21', array_column($visible20, 'name'), true), 'migrate must keep at most 20 enabled visible rules');
check(array() !== ADC_Custom_Event_Rule::validate($visible_raws[20], $visible20)['errors'], 'the 21st enabled visible rule must be refused');
check(array() === ADC_Custom_Event_Rule::validate($visible_raws[20], array_slice($visible20, 0, 19))['errors'], 'the 20th enabled visible rule must be accepted');
$disabled_others = array_map(static fn(array $r): array => array_replace($r, array('enabled' => false)), $visible20);
check(array() === ADC_Custom_Event_Rule::validate($visible_raws[20], $disabled_others)['errors'], 'disabled visible rules must not count towards MAX_VISIBLE_RULES');

// 15. Visibility threshold: 10%..100%, snapped to 5% steps.
check(array() !== errors_of(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'threshold' => 0.09))), 'threshold 0.09 must be rejected');
check(array() !== errors_of(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'threshold' => 1.01))), 'threshold 1.01 must be rejected');
check(is_valid_raw(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'threshold' => 0.1))), 'threshold 0.1 must be accepted');
check(is_valid_raw(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'threshold' => 1.0))), 'threshold 1.0 must be accepted');
$snapped = ADC_Custom_Event_Rule::validate(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'threshold' => 0.52)))['rule'];
check(null !== $snapped && abs($snapped['trigger']['threshold'] - 0.5) < 1e-9, 'threshold 0.52 must snap to 0.5');

// 16. Minimum visibility time: 0..10000 ms.
check(array() !== errors_of(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'min_visible_ms' => -1))), 'min_visible_ms -1 must be rejected');
check(array() !== errors_of(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'min_visible_ms' => 10001))), 'min_visible_ms 10001 must be rejected');
check(is_valid_raw(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'min_visible_ms' => 10000))), 'min_visible_ms 10000 must be accepted');
check(is_valid_raw(trigger_raw(array('type' => 'visible', 'selector' => '.hero', 'min_visible_ms' => 0))), 'min_visible_ms 0 must be accepted');

// 17. Scroll depths: only 25/50/75/90; de-duplicated and sorted.
check(array() !== errors_of(trigger_raw(array('type' => 'scroll_depth', 'depths' => array(30)))), 'depth 30 must be rejected');
check(array() !== errors_of(trigger_raw(array('type' => 'scroll_depth', 'depths' => array(25, 26)))), 'depth 26 must be rejected');
check(array() !== errors_of(trigger_raw(array('type' => 'scroll_depth', 'depths' => array('abc')))), 'non-numeric depth must be rejected');
$depths = ADC_Custom_Event_Rule::validate(trigger_raw(array('type' => 'scroll_depth', 'depths' => array(50, 25, 25))))['rule'];
check(null !== $depths && array(25, 50) === $depths['trigger']['depths'], 'depths must be de-duplicated and sorted');

// 18. Page filter: at most 10 paths.
$eleven_paths = array();
$ten_paths = array();
for ($i = 1; $i <= 11; $i++) {
    $eleven_paths[] = '/p' . $i;
    if ($i <= 10) {
        $ten_paths[] = '/p' . $i;
    }
}
check(array() !== errors_of(raw_rule(array('page_filter' => array('mode' => 'include', 'paths' => $eleven_paths)))), '11 page paths must be rejected');
check(is_valid_raw(raw_rule(array('page_filter' => array('mode' => 'include', 'paths' => $ten_paths)))), '10 page paths must be accepted');

// 19. fire is forced to 'once' for visible, scroll_depth and timer; click keeps 'every'.
$forced_visible = ADC_Custom_Event_Rule::validate(trigger_raw(array('type' => 'visible', 'selector' => '.hero'), array('fire' => 'every')))['rule'];
$forced_scroll = ADC_Custom_Event_Rule::validate(trigger_raw(array('type' => 'scroll_depth', 'depths' => array(25)), array('fire' => 'every')))['rule'];
$forced_timer = ADC_Custom_Event_Rule::validate(trigger_raw(array('type' => 'timer', 'seconds' => 10), array('fire' => 'every')))['rule'];
$kept_click = ADC_Custom_Event_Rule::validate(raw_rule(array('fire' => 'every')))['rule'];
check('once' === ($forced_visible['fire'] ?? null) && 'once' === ($forced_scroll['fire'] ?? null) && 'once' === ($forced_timer['fire'] ?? null), 'visible/scroll_depth/timer must be forced to fire=once');
check('every' === ($kept_click['fire'] ?? null), 'click rules must keep fire=every');

// 20. Reserved GA4 parameter names added in §4.3 are refused; ordinary names are not.
foreach (array('session_id', 'engagement_time_msec', 'page_location', 'page_referrer', 'debug_mode') as $reserved) {
    check(array() !== errors_of(raw_rule(array('params' => array(array('key' => $reserved, 'value' => 'x'))))), "reserved GA4 key '$reserved' must be rejected");
}
check(is_valid_raw(raw_rule(array('params' => array(array('key' => 'campaign', 'value' => 'toamna'))))), 'ordinary parameter key must be accepted');

echo "Custom event rule tests passed.\n";
