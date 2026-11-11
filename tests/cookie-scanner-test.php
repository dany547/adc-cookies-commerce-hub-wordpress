<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', '/offline/');
function __(string $text, string $domain = ''): string { return $text; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['opts'][$name] ?? $default; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['opts'][$name] = $value; return true; }
function sanitize_text_field(string $value): string { return trim($value); }
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool { return true; }
function wp_clear_scheduled_hook(string $hook): int { return 0; }
function wp_next_scheduled(string $hook): int|false { return false; }
function wp_schedule_event(int $time, string $recurrence, string $hook): bool { return true; }
interface ADC_Module_Interface { public static function boot(): void; }
final class ADC_Consent_Banner { public const CATEGORIES = array('necessary','preferences','statistics','marketing'); public static function inventory(): array { return $GLOBALS['inventory'] ?? array(); } }
final class ADC_Settings { public static function all(string $group): array { return array('title'=>'untouched'); } public static function update(string $group, array $input): array { $GLOBALS['written'] = $input; return $input; } }
$GLOBALS['opts'] = array(); $GLOBALS['inventory'] = array();
require dirname(__DIR__) . '/includes/consent/class-adc-cookie-scanner.php';
if (isset($argv[1]) && $argv[1] === '--missing-data') {
    rename(dirname(__DIR__) . '/data/open-cookie-database.json', dirname(__DIR__) . '/data/open-cookie-database.json.test-hold');
    try { check(ADC_Cookie_Scanner::match_known('definitely_unknown') === null, 'missing data unexpectedly matched'); }
    finally { rename(dirname(__DIR__) . '/data/open-cookie-database.json.test-hold', dirname(__DIR__) . '/data/open-cookie-database.json'); }
    echo "Missing data guard passed.\n";
    exit(0);
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
check(ADC_Cookie_Scanner::match_known('wordpress_test_cookie') !== null, 'exact match failed');
check(ADC_Cookie_Scanner::match_known('wordpress_logged_in_abc') !== null, 'wordpress wildcard failed');
$ga = ADC_Cookie_Scanner::match_known('_ga_ABC123');
check($ga !== null && $ga['provider'] === 'Google Analytics' && $ga['category'] === 'statistics' && $ga['origin'] === 'curated', 'GA4 curated wildcard failed');
$ocd = ADC_Cookie_Scanner::match_known('_hjHasCachedUserAttributes');
check($ocd !== null && $ocd['origin'] === 'opencookie', 'Open Cookie Database exact lookup failed');
check(ADC_Cookie_Scanner::match_known('adc_test_unknown_cookie') === null, 'unknown cookie unexpectedly matched');
check(ADC_Cookie_Scanner::record(array('valid_cookie', 'bad name', str_repeat('a', 129)), 'browser') === 1, 'record validation failed');
$result = ADC_Cookie_Scanner::save_to_inventory(array(array('name'=>'valid_cookie','category'=>'bogus','provider'=>'','purpose'=>'','duration'=>'')));
check($result['saved'] === 0 && count($result['errors']) === 1, 'invalid category was accepted');
// Storage layer (ADC_Consent_Banner::sanitize_inventory) accepts [A-Za-z0-9_.-]{1,64} (dots allowed, e.g. Matomo _pk_id.*):
// the scanner must accept dotted names and reject the rest with an error instead of silently dropping them.
$result = ADC_Cookie_Scanner::save_to_inventory(array(array('name'=>'_pk_id.1.1fff','category'=>'statistics','provider'=>'','purpose'=>'','duration'=>'')));
check($result['saved'] === 1 && count($result['errors']) === 0, 'dotted cookie name was not accepted');
$result = ADC_Cookie_Scanner::save_to_inventory(array(array('name'=>str_repeat('a',65),'category'=>'marketing','provider'=>'','purpose'=>'','duration'=>'')));
check($result['saved'] === 0 && count($result['errors']) === 1, 'over-64-char cookie name was not rejected (silent drop on save)');
// Plugin cookies observed on the test site / documented by vendors (curated list).
foreach (array('tk_ai'=>'statistics','tk_or'=>'marketing','mc_cid'=>'marketing','_hjSession_123'=>'statistics','_clck'=>'statistics','__stripe_mid'=>'necessary','__cf_bm'=>'necessary','pll_language'=>'preferences','wf_loginalerted_abc'=>'necessary','_gac_UA1'=>'marketing') as $cookie => $category) {
    $row = ADC_Cookie_Scanner::match_known($cookie);
    check($row !== null && $row['category'] === $category, 'plugin cookie not classified as ' . $category . ': ' . $cookie);
}
check(ADC_Cookie_Scanner::match_known('mc_cid')['duration'] === '14 zile', 'duration missing for curated row');
echo "Cookie scanner tests passed.\n";
