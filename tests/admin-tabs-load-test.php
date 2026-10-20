<?php
/**
 * Autoload smoke: every admin tab class (and the traits they use) must resolve
 * through ADC_Autoloader. `php -l` cannot catch a missing trait/class.
 *
 * Usage: php tests/admin-tabs-load-test.php
 */

declare(strict_types=1);

define('ABSPATH', '/x/');
define('ADC_MARKETING_PLUGIN_DIR', dirname(__DIR__) . '/');
require ADC_MARKETING_PLUGIN_DIR . 'includes/core/class-adc-autoloader.php';
ADC_Autoloader::register();
if (!interface_exists('ADC_Module_Interface')) {
    eval('interface ADC_Module_Interface {}');
}

$failed = 0;
foreach (glob(ADC_MARKETING_PLUGIN_DIR . 'includes/admin/class-adc-admin-tab-*.php') ?: array() as $file) {
    $slug  = substr(basename($file, '.php'), strlen('class-adc-admin-tab-'));
    $class = 'ADC_Admin_Tab_' . str_replace(' ', '_', ucwords(str_replace('-', ' ', $slug)));
    try {
        $ok = class_exists($class);
    } catch (Throwable $e) {
        $ok = false;
        fwrite(STDERR, $class . ': ' . $e->getMessage() . "\n");
    }
    echo ($ok ? 'OK      ' : 'MISSING ') . $class . "\n";
    $failed += $ok ? 0 : 1;
}
exit($failed > 0 ? 1 : 0);
