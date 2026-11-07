<?php
/**
 * WP left menu: "Adcelerum Commerce Hub" top-level page + one submenu per nav
 * group, each linking to the group's first tab, and the parent_file /
 * submenu_file filters highlight the submenu that owns the open tab.
 *
 * Usage: php tests/admin-menu-test.php
 */

declare(strict_types=1);

define('ABSPATH', '/x/');
define('ADC_MARKETING_PLUGIN_DIR', dirname(__DIR__) . '/');
define('ADC_MARKETING_PLUGIN_URL', 'https://example.test/wp-content/plugins/adc-marketing/');

$GLOBALS['adc_menu']    = array();
$GLOBALS['adc_submenu'] = array();
$GLOBALS['adc_filters'] = array();

function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html__(string $text, string $domain = 'default'): string { return $text; }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['adc_filters'][$hook] ?? array() as $cb) {
        $value = $cb($value, ...$args);
    }
    return $value;
}
function add_filter(string $hook, callable $cb): bool { $GLOBALS['adc_filters'][$hook][] = $cb; return true; }
function add_action(mixed ...$args): bool { return true; }
function sanitize_key(string $key): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $key)); }
function wp_unslash(mixed $v): mixed { return $v; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg(array $args, string $url): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
function current_user_can(string $cap): bool { return true; }
function add_menu_page(string $page_title, string $menu_title, string $cap, string $slug, mixed $cb = '', string $icon = '', mixed $pos = null): string
{
    $GLOBALS['adc_menu'][] = compact('page_title', 'menu_title', 'cap', 'slug');
    return 'toplevel_page_' . $slug;
}
function add_submenu_page(string $parent, string $page_title, string $menu_title, string $cap, string $slug, mixed $cb = ''): string|false
{
    $GLOBALS['adc_submenu'][] = array('parent' => $parent, 'page_title' => $page_title, 'menu_title' => $menu_title, 'cap' => $cap, 'slug' => $slug, 'callback' => $cb);
    return $parent . '_page_' . $slug;
}

require ADC_MARKETING_PLUGIN_DIR . 'includes/core/class-adc-autoloader.php';
ADC_Autoloader::register();
if (!interface_exists('ADC_Module_Interface')) {
    eval('interface ADC_Module_Interface {}');
}

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

ADC_Admin_Shell::boot();
ADC_Admin_Shell::register_menu();

$menu = $GLOBALS['adc_menu'][0] ?? array();
check(($menu['menu_title'] ?? '') === 'Adcelerum Commerce Hub', 'top-level menu title is "Adcelerum Commerce Hub"');
check(($menu['page_title'] ?? '') === 'Adcelerum Commerce Hub', 'top-level page title is "Adcelerum Commerce Hub"');
check(($menu['slug'] ?? '') === 'adc-marketing', 'top-level page slug stays adc-marketing');

$expected = array(
    'Prezentare generală' => array('adc-marketing', 'dashboard'),
    'Consimțământ'        => array('admin.php?page=adc-marketing&tab=banner', 'banner'),
    'Măsurare'            => array(null, null), // first tab depends on which measurement tabs exist
    'Platforme'           => array('admin.php?page=adc-marketing&tab=platforms', 'platforms'),
    'Jurnale'             => array('admin.php?page=adc-marketing&tab=logs', 'logs'),
);

$subs = $GLOBALS['adc_submenu'];
check(count($subs) === count($expected), 'exactly ' . count($expected) . ' submenus registered (got ' . count($subs) . ')');
check(array_column($subs, 'menu_title') === array_keys($expected), 'submenu labels and order: ' . implode(' / ', array_column($subs, 'menu_title')));

foreach ($subs as $i => $sub) {
    check($sub['parent'] === 'adc-marketing' && $sub['cap'] === 'manage_options', $sub['menu_title'] . ': parent adc-marketing, cap manage_options');
    check($sub['page_title'] === 'Adcelerum Commerce Hub', $sub['menu_title'] . ': page title is the plugin name');
    [$slug] = $expected[$sub['menu_title']] ?? array(null);
    if (null !== $slug) {
        check($sub['slug'] === $slug, $sub['menu_title'] . ': links to ' . $slug);
    }
    if (0 === $i) {
        check(is_callable($sub['callback']), 'first submenu reuses the page slug with the page callback (no duplicate entry)');
    } else {
        check('' === $sub['callback'] && str_starts_with($sub['slug'], 'admin.php?page=adc-marketing&tab='), $sub['menu_title'] . ': plain link to the first tab, no extra page');
    }
}

// Every tab's slug must keep its URL and highlight exactly its group's submenu.
$entries = ADC_Admin_Shell::menu_entries();
$tab_to_sub = array();
foreach ($entries as $entry) {
    foreach ($entry['tabs'] as $tab_slug) {
        $tab_to_sub[$tab_slug] = $entry['slug'];
    }
}
check(isset($tab_to_sub['dashboard'], $tab_to_sub['banner'], $tab_to_sub['cookies'], $tab_to_sub['marketing'], $tab_to_sub['gtm'], $tab_to_sub['events'], $tab_to_sub['matching'], $tab_to_sub['platforms'], $tab_to_sub['meta'], $tab_to_sub['ga4'], $tab_to_sub['tiktok'], $tab_to_sub['logs']), 'all known tab slugs are reachable from a submenu group');

$group_of = array(
    'dashboard' => 'adc-marketing',
    'banner' => 'admin.php?page=adc-marketing&tab=banner',
    'cookies' => 'admin.php?page=adc-marketing&tab=banner',
    'marketing' => 'admin.php?page=adc-marketing&tab=banner',
    'platforms' => 'admin.php?page=adc-marketing&tab=platforms',
    'meta' => 'admin.php?page=adc-marketing&tab=platforms',
    'ga4' => 'admin.php?page=adc-marketing&tab=platforms',
    'tiktok' => 'admin.php?page=adc-marketing&tab=platforms',
    'logs' => 'admin.php?page=adc-marketing&tab=logs',
);
$measurement = $entries[2]['slug'] ?? '';
foreach (array('analytics', 'gtm', 'events', 'matching') as $m) {
    if (isset($tab_to_sub[$m])) {
        $group_of[$m] = $measurement;
    }
}
check(str_starts_with($measurement, 'admin.php?page=adc-marketing&tab='), 'Măsurare links to its first tab: ' . $measurement);

$GLOBALS['plugin_page'] = 'adc-marketing';
foreach ($group_of as $tab_slug => $sub_slug) {
    $_GET['tab'] = $tab_slug;
    $current     = apply_filters('submenu_file', null, 'adc-marketing');
    check($current === $sub_slug, "tab={$tab_slug}: submenu_file = {$sub_slug}");
    check(apply_filters('parent_file', 'adc-marketing') === 'adc-marketing', "tab={$tab_slug}: parent_file = adc-marketing");
    $matches = array_filter($subs, static fn (array $s): bool => $s['slug'] === $current);
    check(count($matches) === 1, "tab={$tab_slug}: exactly one submenu marked current");
}

$_GET['tab'] = 'does-not-exist';
check(apply_filters('submenu_file', null, 'adc-marketing') === 'adc-marketing', 'unknown tab falls back to the landing submenu');
unset($_GET['tab']);
check(apply_filters('submenu_file', null, 'adc-marketing') === 'adc-marketing', 'no tab = landing submenu');

$GLOBALS['plugin_page'] = 'woocommerce';
check(apply_filters('submenu_file', 'edit.php', 'woocommerce') === 'edit.php', 'other admin pages are left untouched (submenu_file)');
check(apply_filters('parent_file', 'woocommerce') === 'woocommerce', 'other admin pages are left untouched (parent_file)');

exit($failed > 0 ? 1 : 0);
