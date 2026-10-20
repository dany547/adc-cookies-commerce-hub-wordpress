<?php

declare(strict_types=1);

// Standalone regression test for consent-safe GTM defaults.
define('ABSPATH', __DIR__ . '/');
interface ADC_Module_Interface {}
$GLOBALS['adc_test_settings'] = array();
$GLOBALS['adc_test_updates'] = array();
function add_action(...$args): void {}
function add_filter(...$args): void {}
function sanitize_text_field(string $value): string { return $value; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function esc_url_raw(string $value): string { return $value; }
function wp_http_validate_url(string $value): bool { return true; }
function wp_json_encode($value, int $flags = 0): string|false { return json_encode($value, $flags); }
function wp_print_inline_script_tag(string $js): void { echo '<script>' . $js . '</script>'; }
function esc_url(string $value): string { return $value; }
function esc_attr__ (string $value, string $domain): string { return $value; }
function __(string $value, string $domain): string { return $value; }
final class ADC_Settings {
    public static function get(string $group, string $key, $default = null) {
        return array_key_exists($key, $GLOBALS['adc_test_settings'])
            ? $GLOBALS['adc_test_settings'][$key]
            : $default;
    }
    public static function update(string $group, array $value): void {
        $GLOBALS['adc_test_updates'][$group] = $value;
    }
}
interface ADC_Admin_Tab_Interface { public function save(array $post): array; }
require __DIR__ . '/../includes/tracking/class-adc-gtm.php';
require __DIR__ . '/../includes/admin/trait-adc-admin-help-content.php';
require __DIR__ . '/../includes/admin/class-adc-admin-tab-events.php';

function check(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

check(ADC_Gtm::MODE_AFTER === ADC_Gtm::defaults()['mode'], 'defaults use after_consent');
check(ADC_Gtm::MODE_AFTER === ADC_Gtm::sanitize(array('mode' => 'bogus'), array())['mode'], 'invalid mode sanitizes to after_consent');
check(ADC_Gtm::MODE_ALWAYS === ADC_Gtm::sanitize(array('mode' => ADC_Gtm::MODE_ALWAYS), array())['mode'], 'sanitize preserves explicit always mode');
$GLOBALS['adc_test_settings'] = array();
check(ADC_Gtm::MODE_AFTER === ADC_Gtm::mode(), 'absent mode resolves to after_consent');
$GLOBALS['adc_test_settings'] = array('mode' => 'invalid');
check(ADC_Gtm::MODE_AFTER === ADC_Gtm::mode(), 'invalid stored mode resolves to after_consent');
$GLOBALS['adc_test_settings'] = array('mode' => ADC_Gtm::MODE_ALWAYS, 'container_id' => 'GTM-ABC123');
check(ADC_Gtm::MODE_ALWAYS === ADC_Gtm::mode(), 'explicit always mode remains supported');
$GLOBALS['adc_test_updates'] = array();
$admin_tab = new ADC_Admin_Tab_Events();
$admin_tab->save(array('gtm' => array('container_id' => '')));
check(ADC_Gtm::MODE_AFTER === $GLOBALS['adc_test_updates']['gtm']['mode'], 'admin save defaults a missing mode to after_consent');
$admin_tab->save(array('gtm' => array('mode' => ADC_Gtm::MODE_ALWAYS)));
check(ADC_Gtm::MODE_ALWAYS === $GLOBALS['adc_test_updates']['gtm']['mode'], 'admin save preserves explicit always mode');
$GLOBALS['adc_test_settings'] = array('mode' => ADC_Gtm::MODE_AFTER, 'container_id' => 'GTM-ABC123');
ob_start(); ADC_Gtm::print_snippet(); $snippet = ob_get_clean();
check('' === $snippet, 'after_consent mode emits no head snippet');
ob_start(); ADC_Gtm::print_noscript(); $noscript = ob_get_clean();
check('' === $noscript, 'after_consent mode emits no noscript iframe');
$GLOBALS['adc_test_settings']['mode'] = ADC_Gtm::MODE_ALWAYS;
ob_start(); ADC_Gtm::print_snippet(); $alwaysSnippet = ob_get_clean();
check('' !== $alwaysSnippet, 'always mode emits head snippet for compatibility');
ob_start(); ADC_Gtm::print_noscript(); $alwaysNoscript = ob_get_clean();
check('' !== $alwaysNoscript, 'always mode emits noscript iframe for compatibility');
$GLOBALS['adc_test_settings']['mode'] = ADC_Gtm::MODE_AFTER;
$config = ADC_Gtm::filter_config(array());
check(ADC_Gtm::MODE_AFTER === $config['gtm']['mode'], 'frontend config publishes after_consent mode');

echo "PASS: GTM consent defaults\n";
