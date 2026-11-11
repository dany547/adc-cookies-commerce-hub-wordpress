<?php
/**
 * Admin tab "Setări" — read-only settings index.
 *
 * One page that lists every settings area of the hub in navigation order,
 * with the state computed from the stored values and a direct link to the
 * tab that owns the fields. Nothing is saved here: render() prints no form
 * controls, save() returns an empty notice list and the shell hides the
 * sticky save bar, so the tab can never overwrite another tab's settings.
 *
 * The state is a pure settings read (no network, no HTML fetching) so the
 * offline tests can assert on ADC_Admin_Tab_Settings::rows() directly.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings hub: every settings area with its computed state.
 */
final class ADC_Admin_Tab_Settings implements ADC_Admin_Tab_Interface
{
    /**
     * URL of the support/contact page (external, new tab).
     */
    public const SUPPORT_URL = 'https://adcelerum.ro/contact/';

    /**
     * Tab slug.
     *
     * @return string
     */
    public function slug(): string
    {
        return 'settings';
    }

    /**
     * Tab label (RO).
     *
     * @return string
     */
    public function label(): string
    {
        return __('Setări', 'adc-marketing');
    }

    /**
     * Legacy interface value ('' = top-level); nav placement comes from
     * ADC_Admin_Shell::NAV_GROUPS (this tab sits in the "Prezentare generală"
     * group, right after the dashboard).
     *
     * @return string
     */
    public function group(): string
    {
        return '';
    }

    /**
     * Sort order: after the dashboard (5), before the consent tabs.
     *
     * @return int
     */
    public function order(): int
    {
        return 10;
    }

    /**
     * Read-only tab: the shell hides the sticky save bar.
     *
     * @return bool
     */
    public function shows_save_button(): bool
    {
        return false;
    }

    /**
     * Nothing to save (index only).
     *
     * @param array $post wp_unslash($_POST) (unused).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        unset($post);
        return array();
    }

    /**
     * Index rows: one per settings tab, grouped by navigation group.
     *
     * Pure read of the stored settings; `state` is null for tabs that hold
     * content instead of switches.
     *
     * @return array<int, array{group: string, label: string, slug: string, state: ?string}>
     */
    public static function rows(): array
    {
        $groups = (class_exists('ADC_Admin_Shell') && method_exists('ADC_Admin_Shell', 'nav_groups'))
            ? ADC_Admin_Shell::nav_groups()
            : array();

        $tabs   = (class_exists('ADC_Admin_Shell') && method_exists('ADC_Admin_Shell', 'tabs'))
            ? ADC_Admin_Shell::tabs()
            : array();
        $rows   = array();

        foreach ($groups as $group) {
            $group_label = (string) ($group['label'] ?? '');
            foreach ((array) ($group['tabs'] ?? array()) as $slug) {
                $slug = (string) $slug;
                if ('settings' === $slug) {
                    continue; // Never index ourselves.
                }
                $tab     = $tabs[$slug] ?? null;
                $rows[]  = array(
                    'group' => $group_label,
                    'label' => null !== $tab ? $tab->label() : $slug,
                    'slug'  => $slug,
                    'state' => self::state_for($slug),
                );
            }
        }

        return $rows;
    }

    /**
     * Computed state of one settings area: 'on', 'off', 'partial' or null.
     *
     * Only areas that own a real switch are evaluated; content tabs return
     * null so the table does not pretend to know their state.
     *
     * @param string $slug Tab slug.
     * @return string|null
     */
    public static function state_for(string $slug): ?string
    {
        switch ($slug) {
            case 'banner':
                return class_exists('ADC_Consent_Banner') && ADC_Consent_Banner::is_enabled() ? 'on' : 'off';

            case 'gtm':
                if (!class_exists('ADC_Gtm')) {
                    return null;
                }
                return ADC_Gtm::web_enabled() && '' !== ADC_Gtm::container_id() ? 'on' : 'off';

            // Reserved: the sgtm UI was merged into the GTM tab, so rows()
            // never produces an 'sgtm' slug today. The branch stays for a
            // possible future dedicated tab; state_for('sgtm') is covered
            // by tests/admin-settings-tab-test.php.
            case 'sgtm':
                $enabled = !empty(ADC_Settings::get('sgtm', 'enabled', false));
                $url     = class_exists('ADC_Integration_Sgtm') ? ADC_Integration_Sgtm::server_container_url() : '';
                if (!$enabled) {
                    return 'off';
                }
                return '' !== (string) $url ? 'on' : 'partial';

            case 'meta':
            case 'ga4':
            case 'tiktok':
                $settings = ADC_Settings::all($slug);
                $browser  = (string) ($settings['browser_mode'] ?? 'off');
                $direct   = 'direct' === $browser;
                $via_gtm  = 'gtm' === $browser;
                $server   = !empty($settings['enabled_server']);
                if (!$direct && !$via_gtm && !$server) {
                    return 'off';
                }
                if ($via_gtm && !(class_exists('ADC_Gtm') && ADC_Gtm::web_enabled() && '' !== ADC_Gtm::container_id())) {
                    return 'partial';
                }
                return 'on';

            case 'matching':
                return !empty(ADC_Settings::get('matching', 'enabled', false)) ? 'on' : 'off';

            default:
                return null;
        }
    }

    /**
     * Render the index (no form fields, no save button).
     */
    public function render(): void
    {
        $rows = self::rows();

        ADC_Admin_Help::section(
            __('Toate setările, într-un singur loc', 'adc-marketing'),
            __('Starea este calculată din valorile salvate. Deschide zona de care ai nevoie — fiecare tab își salvează propriile câmpuri.', 'adc-marketing')
        );

        echo '<table class="widefat striped adc-settings-index">';
        echo '<thead><tr>'
            . '<th scope="col">' . esc_html__('Zonă', 'adc-marketing') . '</th>'
            . '<th scope="col">' . esc_html__('Stare', 'adc-marketing') . '</th>'
            . '<th scope="col"><span class="screen-reader-text">' . esc_html__('Deschide', 'adc-marketing') . '</span></th>'
            . '</tr></thead>';

        $current_group = '';
        echo '<tbody>';
        foreach ($rows as $row) {
            if ((string) $row['group'] !== $current_group) {
                $current_group = (string) $row['group'];
                echo '<tr class="adc-settings-index-group"><th scope="colgroup" colspan="3">'
                    . esc_html($current_group) . '</th></tr>';
            }

            $url   = admin_url('admin.php?page=' . ADC_Admin_Shell::PAGE_SLUG . '&tab=' . rawurlencode((string) $row['slug']));
            $state = $row['state'];

            echo '<tr>';
            echo '<th scope="row">' . esc_html((string) $row['label']) . '</th>';
            echo '<td>' . self::state_badge($state) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in state_badge().
            echo '<td><a class="button button-small" href="' . esc_url($url) . '">'
                . esc_html__('Deschide', 'adc-marketing') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * One state cell (icon + text, never colour alone).
     *
     * @param string|null $state on|off|partial|null.
     * @return string
     */
    private static function state_badge(?string $state): string
    {
        if (null === $state) {
            return '<span class="adc-settings-state adc-settings-state--na" aria-hidden="true">—</span>'
                . '<span class="screen-reader-text">' . esc_html__('Fără comutator', 'adc-marketing') . '</span>';
        }

        $map = array(
            'on'      => array('icon' => '✓', 'label' => __('Activ', 'adc-marketing')),
            'off'     => array('icon' => '–', 'label' => __('Dezactivat', 'adc-marketing')),
            'partial' => array('icon' => '!', 'label' => __('Configurare incompletă', 'adc-marketing')),
        );
        $entry = $map[$state] ?? $map['off'];

        return '<span class="adc-settings-state adc-settings-state--' . esc_attr($state) . '">'
            . '<span class="adc-settings-state-dot" aria-hidden="true">' . esc_html($entry['icon']) . '</span> '
            . esc_html($entry['label'])
            . '</span>';
    }
}
