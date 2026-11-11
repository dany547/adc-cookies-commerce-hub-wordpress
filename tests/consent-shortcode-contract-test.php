<?php
/** Run: php tests/consent-shortcode-contract-test.php (offline, no WordPress database). */
declare(strict_types=1);
define('ABSPATH', '/fixture/');
require dirname(__DIR__) . '/includes/core/interface-adc-module.php';
final class ADC_Settings
{
    public static array $values = array();
    public static function get(string $group, string $key, mixed $default = null): mixed { return self::$values[$group][$key] ?? $default; }
    public static function all(string $group): array { return self::$values[$group] ?? array(); }
}
function __(string $text, string $domain = ''): string { return $text; }
function add_action(string $hook, callable $callback, int $priority = 10): void {}
function add_filter(string $hook, callable $callback, int $priority = 10): void {}
function add_shortcode(string $tag, callable $callback): void { $GLOBALS['adc_shortcodes'][$tag] = $callback; }
function shortcode_atts(array $defaults, array $atts, string $shortcode = ''): array { return array_merge($defaults, $atts); }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function get_permalink(int $page_id): string|false { return $GLOBALS['adc_test_permalinks'][$page_id] ?? false; }
function get_privacy_policy_url(): string|false { return $GLOBALS['adc_test_privacy_url'] ?? false; }
require dirname(__DIR__) . '/includes/consent/class-adc-consent-banner.php';
function check(bool $ok, string $label): void
{
    if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
ADC_Consent_Banner::boot();
check(isset($GLOBALS['adc_shortcodes']['adc_cookie_preferences']), 'cookie preferences shortcode registered');
check(isset($GLOBALS['adc_shortcodes']['adc_consent_settings']), 'consent settings shortcode registered');
$callback = $GLOBALS['adc_shortcodes']['adc_cookie_preferences'];
ADC_Settings::$values = array('consent' => array('enabled' => true));
$GLOBALS['adc_test_permalinks'] = array();
$GLOBALS['adc_test_privacy_url'] = false;
$html = $callback();
check(str_contains($html, 'href="#"'), 'missing cookie and privacy pages fall back to the fragment URL');
check(str_contains($html, '>Setări cookie-uri</a>'), 'default label rendered');
$custom = $callback(array('label' => '<script>alert("x")</script> & Preferințe'));
check(!str_contains($custom, '<script>'), 'custom label markup is escaped');
check(str_contains($custom, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; Preferințe'), 'custom label text safely rendered');
$alias = $GLOBALS['adc_shortcodes']['adc_consent_settings'];
check($alias(array('label' => 'Gestionează')) === $callback(array('label' => 'Gestionează')), 'both shortcode tags share rendered contract');
$GLOBALS['adc_test_privacy_url'] = 'https://example.test/privacy?from=policy&x="quoted"';
check(str_contains($callback(), 'href="https://example.test/privacy?from=policy&amp;x=&quot;quoted&quot;"'), 'privacy policy URL is escaped and used when cookie page is unavailable');
ADC_Settings::$values['banner']['cookie_page_id'] = 42;
$GLOBALS['adc_test_permalinks'][42] = 'https://example.test/cookies?a=1&b=2';
check(str_contains($callback(), 'href="https://example.test/cookies?a=1&amp;b=2"'), 'configured cookie page permalink takes precedence over privacy policy URL');
ADC_Settings::$values['consent']['enabled'] = false;
check($callback() === '', 'disabled preferences shortcode renders nothing');
check($alias() === '', 'disabled alias shortcode renders nothing');
