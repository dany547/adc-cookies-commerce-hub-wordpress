<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
interface ADC_Module_Interface { public static function boot(): void; }
$GLOBALS['installer_options'] = array();
$GLOBALS['installer_flushes'] = 0;
$GLOBALS['installer_rewrites'] = 0;
function add_action(string $hook, callable|array|string $callback, int $priority = 10): void { $GLOBALS['installer_hook'] = array($hook, $callback, $priority); }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['installer_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['installer_options'][$name] = $value; return true; }
function flush_rewrite_rules(): void { ++$GLOBALS['installer_flushes']; }
final class ADC_Feed_Schema { public const DB_VERSION = '2'; }
final class ADC_Feed_Delivery { public static function register_rewrite_rule(): void { ++$GLOBALS['installer_rewrites']; } }
require_once __DIR__ . '/../includes/core/class-adc-installer.php';
function installer_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
ADC_Installer::boot();
installer_check($GLOBALS['installer_hook'] === array('init', array(ADC_Installer::class, 'maybe_flush_feed_rewrite'), 99), 'rewrite upgrade runs late on init');
ADC_Installer::maybe_flush_feed_rewrite();
installer_check($GLOBALS['installer_flushes'] === 1 && $GLOBALS['installer_rewrites'] === 1
    && $GLOBALS['installer_options']['adc_feed_rewrite_version'] === '2', 'mismatched rewrite version registers rules, flushes once, then records version');
ADC_Installer::maybe_flush_feed_rewrite();
installer_check($GLOBALS['installer_flushes'] === 1 && $GLOBALS['installer_rewrites'] === 1, 'current rewrite version skips repeat flush');
$installer_source = file_get_contents(__DIR__ . '/../includes/core/class-adc-installer.php');
installer_check(is_string($installer_source) && str_contains($installer_source, "'_adc_stats_consent'") && str_contains($installer_source, "'_adc_product_stats_purchased'"), 'opt-in uninstall wipe includes product-stat order markers');
echo "installer-rewrite-test: PASS\n";
