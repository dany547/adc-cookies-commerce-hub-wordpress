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
    public static function update(string $group, array $value): array {
        // Merge mirrors the real ADC_Settings::update semantics: the partial
        // "clear legacy gtm.server_container_url" write must not clobber the
        // rest of the group saved moments earlier.
        $GLOBALS['adc_test_settings'] = array_merge($GLOBALS['adc_test_settings'], $value);
        $GLOBALS['adc_test_updates'][$group] = array_merge($GLOBALS['adc_test_updates'][$group] ?? array(), $value);
        return $GLOBALS['adc_test_settings'];
    }
}
interface ADC_Admin_Tab_Interface { public function save(array $post): array; }
require __DIR__ . '/../includes/tracking/class-adc-gtm.php';
require __DIR__ . '/../includes/admin/trait-adc-admin-help-content.php';
require __DIR__ . '/../includes/admin/class-adc-admin-tab-gtm.php';
require __DIR__ . '/../includes/admin/class-adc-admin-tab-events.php';

function check(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

check(ADC_Gtm::MODE_AFTER === ADC_Gtm::defaults()['mode'], 'defaults use after_consent');
check(ADC_Gtm::MODE_AFTER === ADC_Gtm::sanitize(array('mode' => 'bogus'), array())['mode'], 'invalid mode sanitizes to after_consent');
check(ADC_Gtm::MODE_ALWAYS === ADC_Gtm::sanitize(array('mode' => ADC_Gtm::MODE_ALWAYS), array())['mode'], 'sanitize preserves explicit always mode');

// Partial writes must never wipe the rest of the group. The unified GTM tab
// clears the legacy gtm.server_container_url right after saving the card, so a
// sanitize() that reset absent keys used to drop container_id (status badge
// showed "Inactiv: lipsește ID-ul containerului"), the load mode and the env
// values on every save.
$stored_gtm = array(
    'container_id'   => 'GTM-ABC123',
    'mode'           => ADC_Gtm::MODE_ALWAYS,
    'datalayer_name' => 'dl',
    'env_auth'       => 'A',
    'env_preview'    => 'env-1',
    'web_enabled'    => true,
);
$after_cleanup = ADC_Gtm::sanitize(array('server_container_url' => ''), $stored_gtm);
check('GTM-ABC123' === $after_cleanup['container_id'], 'partial write keeps the stored container id');
check(ADC_Gtm::MODE_ALWAYS === $after_cleanup['mode'], 'partial write keeps the stored load mode');
check('dl' === $after_cleanup['datalayer_name'], 'partial write keeps the stored dataLayer name');
check('A' === $after_cleanup['env_auth'] && 'env-1' === $after_cleanup['env_preview'], 'partial write keeps the stored GTM env values');
check(true === $after_cleanup['web_enabled'], 'partial write keeps the stored web switch');
check('' === ADC_Gtm::sanitize(array('container_id' => ''), $stored_gtm)['container_id'], 'an explicit empty container id still clears it');
check('GTM-ABC123' === ADC_Gtm::sanitize(array('container_id' => 'GTM-BAD!'), $stored_gtm)['container_id'], 'invalid container id keeps the stored value');
check('GTM-LOWER9' === ADC_Gtm::sanitize(array('container_id' => ' gtm-lower9 '), $stored_gtm)['container_id'], 'container id is trimmed and uppercased');
$GLOBALS['adc_test_settings'] = array();
check(ADC_Gtm::MODE_AFTER === ADC_Gtm::mode(), 'absent mode resolves to after_consent');
$GLOBALS['adc_test_settings'] = array('mode' => 'invalid');
check(ADC_Gtm::MODE_AFTER === ADC_Gtm::mode(), 'invalid stored mode resolves to after_consent');
$GLOBALS['adc_test_settings'] = array('mode' => ADC_Gtm::MODE_ALWAYS, 'container_id' => 'GTM-ABC123');
check(ADC_Gtm::MODE_ALWAYS === ADC_Gtm::mode(), 'explicit always mode remains supported');
$GLOBALS['adc_test_updates'] = array();
$admin_tab = new ADC_Admin_Tab_Gtm();
$admin_tab->save(array('gtm' => array('container_id' => '')));
check(ADC_Gtm::MODE_AFTER === $GLOBALS['adc_test_updates']['gtm']['mode'], 'admin save defaults a missing mode to after_consent');
$admin_tab->save(array('gtm' => array('mode' => ADC_Gtm::MODE_ALWAYS)));
check(ADC_Gtm::MODE_ALWAYS === $GLOBALS['adc_test_updates']['gtm']['mode'], 'admin save preserves explicit always mode');

// Unified tab saves both groups and keeps the sgtm group the single URL source.
$GLOBALS['adc_test_updates'] = array();
$GLOBALS['adc_test_settings'] = array();
$admin_tab->save(array(
    'gtm'  => array('web_enabled' => '1', 'container_id' => 'GTM-ABC123', 'mode' => ADC_Gtm::MODE_ALWAYS),
    'sgtm' => array('enabled' => '1', 'server_container_url' => 'https://analytics.exemplu.ro', 'mode' => 'loader'),
));
check(true === ($GLOBALS['adc_test_updates']['sgtm']['enabled'] ?? null), 'unified GTM tab saves the sgtm group');
check('https://analytics.exemplu.ro' === ($GLOBALS['adc_test_updates']['sgtm']['server_container_url'] ?? ''), 'unified GTM tab saves the server container URL');
check(ADC_Gtm::MODE_ALWAYS === ($GLOBALS['adc_test_updates']['gtm']['mode'] ?? ''), 'unified GTM tab saves the web container settings');
check('' === ($GLOBALS['adc_test_updates']['gtm']['server_container_url'] ?? ''), 'legacy gtm URL copy is cleared on save (single source: sgtm group)');

// Invalid container id still warns; success notice stays first.
$GLOBALS['adc_test_updates'] = array();
$GLOBALS['adc_test_settings'] = array();
$notices = $admin_tab->save(array('gtm' => array('container_id' => 'GTM-INVALID!')));
check('success' === ($notices[0]['type'] ?? ''), 'unified GTM tab returns a success notice first');
$warnings = array_filter(is_array($notices) ? $notices : array(), static fn(array $n): bool => 'warning' === ($n['type'] ?? ''));
check(count($warnings) >= 1, 'invalid GTM container id raises a warning notice');

// Experimental mp_forward mode keeps its warning notice.
$GLOBALS['adc_test_updates'] = array();
$GLOBALS['adc_test_settings'] = array();
$notices = $admin_tab->save(array('sgtm' => array('enabled' => '1', 'server_container_url' => 'https://s.example', 'mode' => 'mp_forward')));
check('mp_forward' === ($GLOBALS['adc_test_updates']['sgtm']['mode'] ?? ''), 'unified GTM tab writes the experimental mode');
$has_experimental = false;
foreach ((is_array($notices) ? $notices : array()) as $notice) {
    if (('warning' === ($notice['type'] ?? '')) && str_contains((string) ($notice['message'] ?? ''), 'experimental')) {
        $has_experimental = true;
    }
}
check($has_experimental, 'mp_forward mode raises the experimental warning notice');

// Events tab no longer touches the gtm group (merged into the GTM tab).
$events_tab = new ADC_Admin_Tab_Events();
check(false === str_contains($events_tab->label(), 'GTM'), 'events tab label no longer mentions GTM');
$GLOBALS['adc_test_updates'] = array();
$GLOBALS['adc_test_settings'] = array();
$events_tab->save(array('events' => array('purchase' => '1')));
check(!isset($GLOBALS['adc_test_updates']['gtm']), 'events tab save no longer writes the gtm group');
check(isset($GLOBALS['adc_test_updates']['events']), 'events tab save still writes the events group');
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
