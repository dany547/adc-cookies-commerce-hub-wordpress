<?php
/**
 * WP left menu: "ADC eCOMM Hub" top-level page + one submenu per nav
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
function esc_url(string $url): string { return $url; }
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
check(($menu['menu_title'] ?? '') === 'ADC eCOMM Hub', 'top-level menu title is "ADC eCOMM Hub"');
check(($menu['page_title'] ?? '') === 'ADC eCOMM Hub', 'top-level page title is "ADC eCOMM Hub"');
check(($menu['slug'] ?? '') === 'adc-marketing', 'top-level page slug stays adc-marketing');

$expected = array(
    'Prezentare generală' => array('adc-marketing', 'dashboard'),
    'Consimțământ'        => array('admin.php?page=adc-marketing&tab=banner', 'banner'),
    'Măsurare'            => array(null, null), // first tab depends on which measurement tabs exist
    'Platforme'           => array('admin.php?page=adc-marketing&tab=platforms', 'platforms'),
    'Catalog'             => array('admin.php?page=adc-marketing&tab=feeds', 'feeds'),
    'Jurnale'             => array('admin.php?page=adc-marketing&tab=logs', 'logs'),
);

$subs = $GLOBALS['adc_submenu'];
check(count($subs) === count($expected), 'exactly ' . count($expected) . ' submenus registered (got ' . count($subs) . ')');
check(array_column($subs, 'menu_title') === array_keys($expected), 'submenu labels and order: ' . implode(' / ', array_column($subs, 'menu_title')));

foreach ($subs as $i => $sub) {
    check($sub['parent'] === 'adc-marketing' && $sub['cap'] === 'manage_options', $sub['menu_title'] . ': parent adc-marketing, cap manage_options');
    check($sub['page_title'] === 'ADC eCOMM Hub', $sub['menu_title'] . ': page title is the plugin name');
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

// Navigation membership after the IA regrouping (design review F6): GTM sits
// with the platforms it configures; the group keys and slugs are unchanged.
$groups = ADC_Admin_Shell::nav_groups();
check(array_keys($groups) === array('dashboard', 'consent', 'measurement', 'integrations', 'catalog', 'logs'), 'nav group keys and order unchanged: ' . implode(' / ', array_keys($groups)));
check(in_array('gtm', $groups['integrations']['tabs'], true), 'gtm belongs to the Platforme (integrations) group');
check(!in_array('gtm', $groups['measurement']['tabs'], true), 'gtm is no longer under Măsurare');
check($groups['measurement']['tabs'] === array_values(array_intersect(array('analytics', 'events', 'custom-events', 'matching'), $groups['measurement']['tabs'])), 'Măsurare keeps only the measurement tabs');
check($groups['consent']['tabs'] === array('banner', 'cookies', 'marketing'), 'Consimțământ group keeps banner / cookies / marketing');

// "Altele" fallback: any registered but unclaimed slug is collected, with one
// or many tabs (the sub-nav itself only renders for >1, per render_nav()).
$tabs_prop = (new ReflectionClass(ADC_Admin_Shell::class))->getProperty('tabs');
$tabs_prop->setAccessible(true);
$original_tabs = $tabs_prop->getValue();
$fake_tab = static fn (string $slug): ADC_Admin_Tab_Interface => new class($slug) implements ADC_Admin_Tab_Interface {
    public function __construct(private string $name) {}
    public function slug(): string { return $this->name; }
    public function label(): string { return strtoupper($this->name); }
    public function group(): string { return ''; }
    public function order(): int { return 999; }
    public function render(): void {}
    public function save(array $post): array { return array(); }
};
$tabs = $original_tabs;
$tabs['zz-ext-one'] = $fake_tab('zz-ext-one');
$tabs_prop->setValue(null, $tabs);
$groups = ADC_Admin_Shell::nav_groups();
check(isset($groups['others']) && $groups['others']['tabs'] === array('zz-ext-one') && 'Altele' === $groups['others']['label'], 'single unclaimed tab lands in the Altele group');
$tabs['zz-ext-two'] = $fake_tab('zz-ext-two');
$tabs_prop->setValue(null, $tabs);
$groups = ADC_Admin_Shell::nav_groups();
check(isset($groups['others']) && count($groups['others']['tabs']) === 2, 'multiple unclaimed tabs all land in the Altele group');
$tabs_prop->setValue(null, $original_tabs);

// Every tab's slug must keep its URL and highlight exactly its group's submenu.
$entries = ADC_Admin_Shell::menu_entries();
$tab_to_sub = array();
foreach ($entries as $entry) {
    foreach ($entry['tabs'] as $tab_slug) {
        $tab_to_sub[$tab_slug] = $entry['slug'];
    }
}
check(isset($tab_to_sub['dashboard'], $tab_to_sub['settings'], $tab_to_sub['banner'], $tab_to_sub['cookies'], $tab_to_sub['marketing'], $tab_to_sub['gtm'], $tab_to_sub['events'], $tab_to_sub['matching'], $tab_to_sub['platforms'], $tab_to_sub['meta'], $tab_to_sub['ga4'], $tab_to_sub['tiktok'], $tab_to_sub['feeds'], $tab_to_sub['logs'], $tab_to_sub['conflicts']), 'all known tab slugs are reachable from a submenu group');

$group_of = array(
    'dashboard' => 'adc-marketing',
    'settings' => 'adc-marketing',
    'banner' => 'admin.php?page=adc-marketing&tab=banner',
    'cookies' => 'admin.php?page=adc-marketing&tab=banner',
    'marketing' => 'admin.php?page=adc-marketing&tab=banner',
    'platforms' => 'admin.php?page=adc-marketing&tab=platforms',
    'gtm' => 'admin.php?page=adc-marketing&tab=platforms',
    'meta' => 'admin.php?page=adc-marketing&tab=platforms',
    'ga4' => 'admin.php?page=adc-marketing&tab=platforms',
    'tiktok' => 'admin.php?page=adc-marketing&tab=platforms',
    'feeds' => 'admin.php?page=adc-marketing&tab=feeds',
    'logs' => 'admin.php?page=adc-marketing&tab=logs',
    'conflicts' => 'admin.php?page=adc-marketing&tab=logs',
);
$measurement = $entries[2]['slug'] ?? '';
foreach (array('analytics', 'events', 'matching') as $m) {
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

// Plugin row (Plugins → Installed Plugins): "Setări" + bold "Pro Support".
$links = ADC_Admin_Shell::plugin_action_links(array('deactivate' => '<a href="#">Dezactivează</a>'));
check(isset($links['adc-settings']) && str_contains($links['adc-settings'], 'tab=settings'), 'plugin row: Setări links to the settings tab');
check(isset($links['adc-support']) && str_contains($links['adc-support'], 'https://adcelerum.ro/contact/'), 'plugin row: Pro Support links to the contact page');
check(str_contains($links['adc-support'], 'font-weight:600'), 'plugin row: Pro Support is bold');
check(str_contains($links['adc-support'], 'target="_blank"') && str_contains($links['adc-support'], 'rel="noopener"'), 'plugin row: Pro Support opens in a new tab safely');
check(isset($links['deactivate']), 'plugin row: WordPress own links are preserved');
check(array_keys($links)[0] === 'adc-settings' && array_keys($links)[1] === 'adc-support', 'plugin row: our links come first');
$shell_src = (string) file_get_contents(__DIR__ . '/../includes/admin/class-adc-admin-shell.php');
check(!str_contains($shell_src, 'adc-admin-group--support'), 'navigation: no Pro Support group item');
check(substr_count($shell_src, "esc_html__('Pro Support'") === 1, 'Pro Support is rendered in the shell only once: the plugin row link');
check(str_contains($shell_src, 'ADC_Admin_Promo'), 'promo banner class still wired in the shell');
$GLOBALS['adc_test_cap'] = true;
check(ADC_Admin_Shell::plugin_action_links(array()) !== array(), 'plugin row: links rendered for manage_options');

exit($failed > 0 ? 1 : 0);
