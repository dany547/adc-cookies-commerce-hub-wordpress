<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
interface ADC_Module_Interface { public static function boot(): void; }
$GLOBALS['feed_module_hooks'] = array();
$GLOBALS['feed_module_settings'] = null;
function add_action(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['feed_module_hooks'][] = array($hook, $callback, $priority, $accepted_args);
}
function add_filter(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['feed_module_hooks'][] = array($hook, $callback, $priority, $accepted_args);
}
function __(string $text, string $domain = ''): string { return $text; }
final class ADC_Feed_Store
{
    public static function prepare_storage_directory(): string { return sys_get_temp_dir(); }
    public static function storage_autotest(): array
    {
        $status = $GLOBALS['feed_module_storage_status'] ?? 'storage_guard_executed';
        $exposed = 'storage_exposed' === $status;
        return array('status' => $status, 'ok' => true, 'warning' => 'storage_guard_executed' !== $status, 'exposed' => $exposed);
    }
}
final class ADC_Settings
{
    public static function register_group(string $group, array $defaults, callable $sanitize, array $secret_keys = array()): void
    {
        $GLOBALS['feed_module_settings'] = compact('group', 'defaults', 'sanitize');
    }
}
require_once __DIR__ . '/../includes/feeds/class-adc-feed-module.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-rest.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-scheduler.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-delivery.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$requirements = ADC_Feed_Module::requirements();
check(count($requirements) === 6 && array_column($requirements, 'code') === array('woocommerce_active', 'woocommerce_version', 'xmlreader', 'xmlwriter', 'storage_writable', 'storage_autotest'), 'requirements expose WooCommerce, runtime, writable storage, and informational autotest');
check(!$requirements[0]['ok'] && !$requirements[1]['ok'], 'WooCommerce active and minimum version are reported separately when absent');
check($requirements[2]['ok'] === class_exists('XMLReader'), 'XMLReader requirement reports missing extension separately');
check($requirements[3]['ok'] === class_exists('XMLWriter'), 'XMLWriter requirement reports missing extension separately');
$GLOBALS['feed_module_storage_status'] = 'storage_exposed';
$exposed_requirements = ADC_Feed_Module::requirements();
check($exposed_requirements[5]['code'] === 'storage_autotest' && $exposed_requirements[5]['ok']
    && $exposed_requirements[5]['warning'] && $exposed_requirements[5]['status'] === 'storage_exposed'
    && str_contains($exposed_requirements[5]['label'], 'Verificare informativă'), 'exposed PHP content is an explicitly informational warning with a stable UI contract');
$GLOBALS['feed_module_storage_status'] = 'storage_probe_unavailable';
$unavailable_requirements = ADC_Feed_Module::requirements();
check($unavailable_requirements[5]['ok'] && $unavailable_requirements[5]['warning']
    && $unavailable_requirements[5]['status'] === 'storage_probe_unavailable'
    && str_contains($unavailable_requirements[5]['label'], 'neconfirmată'), 'nonconclusive probe warnings are explicitly unverified and do not block generation');
check(!ADC_Feed_Module::woocommerce_active(), 'WooCommerce is absent in isolated test');
ADC_Feed_Module::boot();
check($GLOBALS['feed_module_hooks'] === array(), 'boot registers no hooks without WooCommerce');
eval('class WooCommerce {}');
check(ADC_Feed_Module::woocommerce_active(), 'WooCommerce class enables the module');
$GLOBALS['woocommerce'] = (object) array('version' => '9.5.0');
$wc_ready = ADC_Feed_Module::requirements();
check($wc_ready[0]['ok'] && $wc_ready[1]['ok'], 'requirements accept active WooCommerce 9.5');
$GLOBALS['woocommerce']->version = '9.4.9';
check(!ADC_Feed_Module::requirements()[1]['ok'], 'requirements reject WooCommerce versions below 9.5');
ADC_Feed_Module::boot();
check(count($GLOBALS['feed_module_hooks']) === 12, 'active module registers settings, schema, scheduler, REST and delivery hooks');
check($GLOBALS['feed_module_hooks'][0][0] === 'adc_register_settings', 'settings registration hook');
check($GLOBALS['feed_module_hooks'][1][0] === 'plugins_loaded', 'schema upgrade hook');
check((bool) array_filter($GLOBALS['feed_module_hooks'], static fn(array $hook): bool => 'rest_api_init' === $hook[0]), 'feed REST routes hook is registered');
check((bool) array_filter($GLOBALS['feed_module_hooks'], static fn(array $hook): bool => ADC_Feed_Scheduler::JOB_HOOK === $hook[0]), 'feed generation job hook is registered');
check((bool) array_filter($GLOBALS['feed_module_hooks'], static fn(array $hook): bool => 'template_redirect' === $hook[0]), 'public feed delivery hook is registered');
ADC_Feed_Module::register_settings();
$registered = $GLOBALS['feed_module_settings'];
check($registered['group'] === 'feeds', 'registers feeds settings group');
check($registered['defaults'] === array('enabled' => false, 'chunk_size' => 200), 'safe defaults');
$sanitize = $registered['sanitize'];
check($sanitize(array('enabled' => '1', 'chunk_size' => '1000'), array()) === array('enabled' => true, 'chunk_size' => 500), 'boolean and upper chunk bound sanitized');
check($sanitize(array('enabled' => 'false', 'chunk_size' => '50'), array()) === array('enabled' => false, 'chunk_size' => 100), 'false-like input and lower chunk bound sanitized');
check($sanitize(array('enabled' => true, 'chunk_size' => 'invalid'), array()) === array('enabled' => true, 'chunk_size' => 200), 'invalid chunk value falls back to default');

echo "feed-module-test: PASS\n";
