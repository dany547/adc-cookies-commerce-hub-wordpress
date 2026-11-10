<?php
/**
 * Offline checks for the read-only "Setări" index tab: it must expose every
 * settings area exactly once, never index itself, compute per-area state from
 * the stored settings only, and stay out of the save flow.
 *
 * Usage: php tests/admin-settings-tab-test.php
 */

declare(strict_types=1);

define('ABSPATH', '/x/');

$GLOBALS['adc_settings'] = array();

function __(string $text, string $domain = 'default'): string { return $text; }
function esc_html__(string $text, string $domain = 'default'): string { return $text; }
function esc_html(string $text): string { return $text; }
function esc_attr(string $text): string { return $text; }
function esc_url(string $url): string { return $url; }
function admin_url(string $path = ''): string { return 'https://shop.test/wp-admin/' . $path; }
function add_action(mixed ...$args): bool { return true; }
function checked(mixed $a, mixed $b = true, bool $echo = true): string { return $a === $b ? 'checked' : ''; }

final class ADC_Settings
{
    public static function all(string $group): array { return $GLOBALS['adc_settings'][$group] ?? array(); }
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return $GLOBALS['adc_settings'][$group][$key] ?? $default;
    }
}

final class ADC_Gtm
{
    public static function web_enabled(): bool { return (bool) ($GLOBALS['adc_settings']['gtm']['web_enabled'] ?? true); }
    public static function container_id(): string
    {
        $on = (bool) ($GLOBALS['adc_settings']['gtm']['web_enabled'] ?? true);
        $id = (string) ($GLOBALS['adc_settings']['gtm']['container_id'] ?? '');
        return $on ? $id : '';
    }
}

final class ADC_Consent_Banner
{
    public static function is_enabled(): bool { return (bool) ($GLOBALS['adc_settings']['consent']['enabled'] ?? false); }
}

final class ADC_Integration_Sgtm
{
    public static function server_container_url(): string
    {
        return (string) ($GLOBALS['adc_settings']['sgtm']['server_container_url'] ?? '');
    }
}

/** Minimal shell double: nav groups + tab labels, as the real shell exposes them. */
final class ADC_Admin_Shell
{
    public const PAGE_SLUG = 'adc-marketing';

    public static function nav_groups(): array
    {
        return array(
            'dashboard'    => array('label' => 'Prezentare generală', 'tabs' => array('dashboard', 'settings')),
            'consent'      => array('label' => 'Cookies', 'tabs' => array('banner', 'cookies', 'marketing')),
            'measurement'  => array('label' => 'Măsurare', 'tabs' => array('gtm', 'events', 'matching')),
            'integrations' => array('label' => 'Platforme', 'tabs' => array('platforms', 'meta', 'ga4', 'tiktok')),
            'logs'         => array('label' => 'Jurnale', 'tabs' => array('logs', 'conflicts')),
        );
    }

    public static function tabs(): array
    {
        $labels = array(
            'dashboard' => 'Prezentare generală',
            'settings'  => 'Setări',
            'banner'    => 'Banner consimțământ',
            'cookies'   => 'Scanner cookie-uri',
            'marketing' => 'Marketing (checkout)',
            'gtm'       => 'Google Tag Manager',
            'events'    => 'Evenimente',
            'matching'  => 'Potrivire avansată',
            'platforms' => 'Platforme',
            'meta'      => 'Meta',
            'ga4'       => 'GA4',
            'tiktok'    => 'TikTok',
            'logs'      => 'Jurnale',
            'conflicts' => 'Conflicte',
        );

        $tabs = array();
        foreach ($labels as $slug => $label) {
            $tabs[$slug] = new class($slug, $label) {
                public function __construct(private string $slug_value, private string $label_value) {}
                public function slug(): string { return $this->slug_value; }
                public function label(): string { return $this->label_value; }
            };
        }

        return $tabs;
    }
}

interface ADC_Admin_Tab_Interface {}
define('ADC_ADMIN_HELP_LOADED', true);

require dirname(__DIR__) . '/includes/admin/class-adc-admin-tab-settings.php';

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

$tab = new ADC_Admin_Tab_Settings();

check('settings' === $tab->slug(), 'slug is "settings"');
check('Setări' === $tab->label(), 'label is "Setări"');
check(false === $tab->shows_save_button(), 'read-only: the shell hides the save bar');
check(array() === $tab->save(array('gtm' => array('container_id' => 'GTM-X'))), 'save() writes nothing');
check('https://adcelerum.ro/contact/' === ADC_Admin_Tab_Settings::SUPPORT_URL, 'Pro Support points at the Adcelerum contact page');

$rows = ADC_Admin_Tab_Settings::rows();
$slugs = array_column($rows, 'slug');
check(!in_array('settings', $slugs, true), 'the index never lists itself');
check(count($slugs) === count(array_unique($slugs)), 'every settings area appears exactly once');

$expected = array('dashboard', 'banner', 'cookies', 'marketing', 'gtm', 'events', 'matching', 'platforms', 'meta', 'ga4', 'tiktok', 'logs', 'conflicts');
check($slugs === $expected, 'rows follow the navigation order: ' . implode(', ', $slugs));

$groups = array_column($rows, 'group');
check('Prezentare generală' === $groups[0] && 'Cookies' === $groups[1], 'each row carries its navigation group label');

$labels = array_column($rows, 'label');
check(in_array('Meta', $labels, true) && in_array('Conflicte', $labels, true), 'row labels come from the tab classes');

// --- State computation ---------------------------------------------------
$GLOBALS['adc_settings'] = array(
    'consent' => array('enabled' => true),
    'gtm'     => array('web_enabled' => true, 'container_id' => 'GTM-ABC123'),
    'sgtm'    => array('enabled' => true, 'server_container_url' => ''),
    'meta'    => array('browser_mode' => 'direct'),
    'ga4'     => array('browser_mode' => 'gtm'),
    'tiktok'  => array('browser_mode' => 'off', 'enabled_server' => false),
    'matching' => array('enabled' => true),
);
check('on' === ADC_Admin_Tab_Settings::state_for('banner'), 'banner state follows the consent group');
check('on' === ADC_Admin_Tab_Settings::state_for('gtm'), 'GTM is on when the container is enabled with a valid id');
check('partial' === ADC_Admin_Tab_Settings::state_for('sgtm'), 'Server GTM enabled without a URL is "partial"');
check('on' === ADC_Admin_Tab_Settings::state_for('meta'), 'Meta in direct mode is on');
check('on' === ADC_Admin_Tab_Settings::state_for('ga4'), 'GA4 via an active GTM container is on');
check('off' === ADC_Admin_Tab_Settings::state_for('tiktok'), 'TikTok with no channel is off');
check('on' === ADC_Admin_Tab_Settings::state_for('matching'), 'advanced matching follows its own switch');
check(null === ADC_Admin_Tab_Settings::state_for('logs'), 'content tabs have no state');

$GLOBALS['adc_settings']['gtm']['container_id'] = '';
check('off' === ADC_Admin_Tab_Settings::state_for('gtm'), 'GTM without a container id is off');
$GLOBALS['adc_settings']['ga4']['browser_mode'] = 'gtm';
check('partial' === ADC_Admin_Tab_Settings::state_for('ga4'), 'GA4 declared "gtm" without a live container is "partial"');

$GLOBALS['adc_settings']['consent']['enabled'] = false;
check('off' === ADC_Admin_Tab_Settings::state_for('banner'), 'banner off follows the consent switch');

exit($failed > 0 ? 1 : 0);
