<?php
/** Run: php tests/banner-settings-test.php (offline, no WordPress database). */
declare(strict_types=1);
define('ABSPATH', '/fixture/');
require dirname(__DIR__) . '/includes/core/interface-adc-module.php';
final class ADC_Settings
{
    public static array $banner = array();
    public static function get(string $group, string $key, mixed $default = null): mixed { return $default; }
    public static function all(string $group): array { return self::$banner; }
}
function __(string $text, string $domain = ''): string { return $text; }
function sanitize_text_field(string $text): string { return strip_tags($text); }
function absint(mixed $value): int { return abs((int) $value); }
function wp_kses_post(string $value): string { return $value; }
function do_shortcode(string $value): string { return $value; }
function get_privacy_policy_url(): string { return ''; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['adc_test_options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = true): bool { $GLOBALS['adc_test_options'][$key] = $value; return true; }
require dirname(__DIR__) . '/includes/consent/class-adc-consent-banner.php';
function check(bool $ok, string $label): void
{
    if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
$defaults = ADC_Consent_Banner::defaults();
check(($defaults['button_alignment'] ?? null) === 'start', 'backwards-compatible alignment default');
check($defaults['reject_label'] === 'Acceptă doar cele necesare', 'essential-only default label');
$orders = ADC_Consent_Banner::button_orders();
check(count($orders) === 6, 'all six action orders available');
foreach ($orders as $slug => $order) {
    $settings = ADC_Consent_Banner::sanitize(array('button_order' => $slug), $defaults);
    $buttons = ADC_Consent_Banner::action_buttons($settings);
    check(array_column($buttons, 'action') === $order['actions'], "DOM order: $slug");
    $keys = array_column($buttons, 'action'); sort($keys);
    check($keys === array('accept', 'customize', 'reject'), "all actions exactly once: $slug");
}
foreach (array('start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end') as $alignment => $css) {
    $settings = ADC_Consent_Banner::sanitize(array('button_alignment' => $alignment), $defaults);
    check($settings['button_alignment'] === $alignment, "alignment saves: $alignment");
    ADC_Settings::$banner = $settings;
    $method = new ReflectionMethod(ADC_Consent_Banner::class, 'view_config');
    $config = $method->invoke(null);
    check(str_contains($config['style'], '--adc-actions-justify:' . $css . ';'), "frontend alignment: $alignment");
}
$invalid = ADC_Consent_Banner::sanitize(array('button_order' => 'reject', 'button_alignment' => 'end;color:red'), $defaults);
check($invalid['button_order'] === ADC_Consent_Banner::DEFAULT_BUTTON_ORDER, 'invalid order falls back safely');
check($invalid['button_alignment'] === 'start', 'invalid alignment rejected');
foreach (array('Refuză tot', 'Reject all') as $legacy) {
    $settings = array_replace($defaults, array('reject_label' => $legacy));
    check(ADC_Consent_Banner::reject_label($settings) === $defaults['reject_label'], 'legacy default resolves to essential-only copy');
    $settings = ADC_Consent_Banner::sanitize(array('reject_label' => $legacy), $defaults);
    check($settings['reject_label'] === $defaults['reject_label'], 'legacy label normalized on save');
}
$custom = array_replace($defaults, array('reject_label' => 'Doar cookie-urile de bază'));
check(ADC_Consent_Banner::reject_label($custom) === $custom['reject_label'], 'custom label preserved');
$buttons = ADC_Consent_Banner::action_buttons($custom);
check(in_array('reject', array_column($buttons, 'action'), true), 'essential choice retains reject action semantics');
ADC_Settings::$banner = array_replace($defaults, array('reject_label' => 'Refuză tot'));
$config = (new ReflectionMethod(ADC_Consent_Banner::class, 'view_config'))->invoke(null);
$hash = ADC_Consent_Banner::current_text_hash();
$proof = get_option(ADC_Consent_Banner::TEXTS_OPTION)[$hash];
check($proof['texts']['labels'] === $config['labels'], 'consent proof labels match displayed labels');
check($proof['texts']['labels']['reject'] === $defaults['reject_label'], 'legacy default proof records essential-only label');
check(hash('sha256', (string) wp_json_encode($proof['texts'], JSON_UNESCAPED_UNICODE)) === $hash, 'consent text proof hash matches stored exact text');

// ---- Banner tab: cookie summary + preserved inventory (list moved to the Cookies tab) ----

require dirname(__DIR__) . '/includes/admin/interface-adc-admin-tab.php';
require dirname(__DIR__) . '/includes/admin/trait-adc-admin-help-content.php';
require dirname(__DIR__) . '/includes/admin/class-adc-admin-tab-banner.php';

$rows = array(
    array('name' => '_ga', 'category' => 'statistics', 'provider' => 'Google', 'purpose' => '', 'duration' => '2 ani', 'wildcard' => false),
    array('name' => '_gid', 'category' => 'statistics', 'provider' => 'Google', 'purpose' => '', 'duration' => '1 zi', 'wildcard' => false),
    array('name' => 'woocommerce_cart_hash', 'category' => 'necessary', 'provider' => 'Magazin', 'purpose' => '', 'duration' => 'sesiune', 'wildcard' => false),
    array('name' => '_fbp', 'category' => '', 'provider' => '', 'purpose' => '', 'duration' => '', 'wildcard' => false),
    array('name' => '', 'category' => 'marketing'),
    'not a row',
);
$summary = ADC_Admin_Tab_Banner::inventory_summary($rows);
check($summary['total'] === 4, 'summary counts only rows with a name');
check($summary['categorized'] === 3, 'summary counts categorised cookies');
check($summary['uncategorized'] === 1, 'summary counts cookies without a valid category');
check($summary['by_category'] === array('necessary' => 1, 'preferences' => 0, 'statistics' => 2, 'marketing' => 0), 'summary buckets every known category');
check(ADC_Admin_Tab_Banner::inventory_summary(array())['total'] === 0, 'empty inventory summarises to zero');
check(
    ADC_Admin_Tab_Banner::inventory_summary(array(array('name' => 'x_cookie', 'category' => 'bogus')))['categorized'] === 0,
    'unknown categories are reported as uncategorised'
);

// Saving the Banner without the (now removed) textarea keeps the stored rows:
// ADC_Consent_Banner::sanitize() only replaces the inventory when it is posted.
$stored = array_replace($defaults, array('inventory' => $rows));
$saved  = ADC_Consent_Banner::sanitize(array('title' => 'Titlu nou', 'shadow' => '1'), $stored);
check($saved['title'] === 'Titlu nou', 'banner save applies the posted fields');
// A save that does not post the list must keep the stored rows in place (the
// regression would replace them with the built-in defaults).
$kept = array_slice($saved['inventory'], 0, count($rows));
check(array_column($kept, 'name') === array_column($rows, 'name'), 'banner save keeps the stored cookie rows');
check(array_column($kept, 'category') === array_column($rows, 'category'), 'banner save keeps the stored cookie categories');

// Structural guard: the tab renders three collapsible sections and no editable
// cookie textarea; the list now lives in the "cookies" tab it links to.
$source = (string) file_get_contents(dirname(__DIR__) . '/includes/admin/class-adc-admin-tab-banner.php');
check(substr_count($source, 'data-adc-banner-section="') === 3, 'three collapsible sections');
check(str_contains($source, "data-adc-banner-section=\"content\" open"), 'first section is open by default');
check(!str_contains($source, 'name="adc_banner[inventory]"'), 'cookie list textarea removed from the Banner');
check(str_contains($source, "'tab'  => 'cookies'"), 'summary links to the cookies tab');
