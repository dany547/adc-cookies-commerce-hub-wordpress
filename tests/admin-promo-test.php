<?php
/**
 * Promo banner: visible until dismissed, CTA opens adcelerum.ro in a new tab
 * as a followed link (no rel=nofollow), dismissal stored per user with nonce.
 *
 * Usage: php tests/admin-promo-test.php
 */

declare(strict_types=1);

define('ABSPATH', '/x/');
define('ADC_MARKETING_PLUGIN_DIR', dirname(__DIR__) . '/');

$GLOBALS['meta'] = array();
function __(string $t, string $d = 'default'): string { return $t; }
function esc_html__(string $t, string $d = 'default'): string { return $t; }
function esc_html_e(string $t, string $d = 'default'): void { echo htmlspecialchars($t, ENT_QUOTES); }
function esc_attr_e(string $t, string $d = 'default'): void { echo htmlspecialchars($t, ENT_QUOTES); }
function esc_url(string $u): string { return htmlspecialchars($u, ENT_QUOTES); }
function admin_url(string $p = ''): string { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_url(string $u, string $a): string { return $u . '&_wpnonce=nonce-' . $a; }
function current_user_can(string $c): bool { return true; }
function get_current_user_id(): int { return 7; }
function get_user_meta(int $id, string $k, bool $s = false): mixed { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_user_meta(int $id, string $k, mixed $v): bool { $GLOBALS['meta'][$id][$k] = $v; return true; }
function add_action(mixed ...$a): bool { $GLOBALS['actions'][] = $a[0]; return true; }

require ADC_MARKETING_PLUGIN_DIR . 'includes/admin/class-adc-admin-promo.php';

$failed = 0;
function check(bool $ok, string $label): void { global $failed; echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n"; $failed += $ok ? 0 : 1; }

ADC_Admin_Promo::boot();
check(in_array('admin_post_adc_dismiss_promo', $GLOBALS['actions'] ?? array(), true), 'dismiss endpoint registered on admin_post_adc_dismiss_promo');

ob_start();
ADC_Admin_Promo::render();
$html = (string) ob_get_clean();

check(str_contains($html, 'Dorești o soluție personalizată pentru afacerea ta?'), 'marketing headline rendered');
check((bool) preg_match('~<a class="adc-promo__cta" href="https://adcelerum\.ro/" target="_blank" rel="noopener">~', $html), 'CTA → https://adcelerum.ro/ in a new tab with rel="noopener"');
check(!str_contains($html, 'nofollow') && !str_contains($html, 'noreferrer'), 'CTA is a followed link (no nofollow/noreferrer)');
check(str_contains($html, '(se deschide într-o filă nouă)'), 'new-tab announcement for screen readers');
check(str_contains($html, 'action=adc_dismiss_promo&amp;_wpnonce=nonce-adc_dismiss_promo'), 'dismiss link carries the adc_dismiss_promo nonce');
check(str_contains($html, 'aria-hidden="true"></span>') && str_contains($html, 'adc-promo__aurora'), 'aurora layer is decorative (aria-hidden)');

update_user_meta(7, ADC_Admin_Promo::META_KEY, '1');
ob_start();
ADC_Admin_Promo::render();
check('' === trim((string) ob_get_clean()), 'dismissed user sees nothing');

exit($failed > 0 ? 1 : 0);
