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
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
check(ADC_Cookie_Scanner::match_known('wordpress_test_cookie') !== null, 'exact match failed');
check(ADC_Cookie_Scanner::match_known('wordpress_logged_in_abc') !== null, 'wordpress wildcard failed');
$ga = ADC_Cookie_Scanner::match_known('_ga_ABC123');
check($ga !== null && $ga['provider'] === 'Google Analytics' && $ga['category'] === 'statistics', 'GA4 wildcard failed');
check(ADC_Cookie_Scanner::record(array('valid_cookie', 'bad name', str_repeat('a', 129)), 'browser') === 1, 'record validation failed');
$result = ADC_Cookie_Scanner::save_to_inventory(array(array('name'=>'valid_cookie','category'=>'bogus','provider'=>'','purpose'=>'','duration'=>'')));
check($result['saved'] === 0 && count($result['errors']) === 1, 'invalid category was accepted');
// Storage layer (ADC_Consent_Banner::sanitize_inventory) accepts [A-Za-z0-9_.-]{1,64} (dots allowed, e.g. Matomo _pk_id.*):
// the scanner must accept dotted names and reject the rest with an error instead of silently dropping them.
$result = ADC_Cookie_Scanner::save_to_inventory(array(array('name'=>'_pk_id.1.1fff','category'=>'statistics','provider'=>'','purpose'=>'','duration'=>'')));
check($result['saved'] === 1 && count($result['errors']) === 0, 'dotted cookie name was not accepted');
$result = ADC_Cookie_Scanner::save_to_inventory(array(array('name'=>str_repeat('a',65),'category'=>'marketing','provider'=>'','purpose'=>'','duration'=>'')));
check($result['saved'] === 0 && count($result['errors']) === 1, 'over-64-char cookie name was not rejected (silent drop on save)');
echo "Cookie scanner tests passed.\n";
