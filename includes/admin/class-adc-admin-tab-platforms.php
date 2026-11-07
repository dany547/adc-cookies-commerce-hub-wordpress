<?php
/**
 * Admin tab "Integrări ▸ Platforme" — read-only overview matrix.
 *
 * One page that answers, for Meta, GA4 and TikTok at once:
 * - how the pixel is loaded in the visitor's browser (off / through GTM /
 *   direct), read from each platform's own settings, with a link to the
 *   platform tab for editing;
 * - whether server-side sending is on;
 * - the computed overall state (active / inactive + reason / off).
 *
 * Below the matrix, warnings are COMPUTED from the stored settings (never
 * stored themselves):
 * 1. a platform set to browser_mode 'gtm' while the web GTM container is
 *    off or has no container id (the pixel never loads);
 * 2. GA4 loaded directly while the web GTM container is active — the
 *    container usually also carries the GA4 tag, so events can double.
 *    Platforms declared 'gtm' cannot also be 'direct' (single select), and
 *    Meta/TikTok declared 'direct' have no container-carried declaration,
 *    so this fires for GA4 only;
 * 3. server sending enabled without the platform's credentials;
 * 4. ga4.purchase_channel 'server' while the browser GA4 channel is
 *    effectively active (purchase can be counted twice — D6);
 * 5. Server GTM enabled without a server container URL.
 *
 * The tab saves nothing: render() prints no form fields and save() returns
 * an empty notice list (the shell skips the save bar for it).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only platforms overview tab (matrix + computed warnings).
 */
final class ADC_Admin_Tab_Platforms implements ADC_Admin_Tab_Interface
{
    /**
     * Platform slug => Romanian label (settings group === slug).
     *
     * @var array<string, string>
     */
    private const PLATFORMS = array(
        'meta'   => 'Meta',
        'ga4'    => 'GA4',
        'tiktok' => 'TikTok',
    );

    /**
     * Human label for tabs warnings/links point at.
     *
     * @var array<string, string>
     */
    private const TAB_LABELS = array(
        'gtm'   => 'Google Tag Manager',
        'meta'  => 'Meta',
        'ga4'   => 'GA4',
        'tiktok' => 'TikTok',
    );

    /**
     * Tab slug.
     *
     * @return string
     */
    public function slug(): string
    {
        return 'platforms';
    }

    /**
     * Tab label (RO).
     *
     * @return string
     */
    public function label(): string
    {
        return __('Platforme', 'adc-marketing');
    }

    /**
     * Nav placement: first tab of the "Integrări" group (driven by
     * ADC_Admin_Shell::NAV_GROUPS).
     *
     * @return string
     */
    public function group(): string
    {
        return 'integrations';
    }

    /**
     * Sort order: before Meta (30) inside the integrations listing.
     *
     * @return int
     */
    public function order(): int
    {
        return 25;
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
     * Nothing to save (read-only overview).
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
     * Compute the matrix rows + warnings from the stored settings.
     *
     * Pure settings read (no URLs, no HTML) so tests can assert on it.
     *
     * @return array{
     *     gtm_active: bool,
     *     sgtm_enabled: bool,
     *     sgtm_url: string,
     *     rows: array<string, array{label: string, browser_mode: string, browser_ok: bool, server_enabled: bool, server_ok: bool, status: array{state: string, label: string, reasons: string[]}}>,
     *     warnings: array<int, array{id: string, platform: string, fix_tab: string, text: string}>
     * }
     */
    public static function snapshot(): array
    {
        $gtm_active   = ADC_Gtm::web_enabled() && '' !== ADC_Gtm::container_id();
        $sgtm_enabled = !empty(ADC_Settings::get('sgtm', 'enabled', false));
        $sgtm_url     = class_exists('ADC_Integration_Sgtm') ? ADC_Integration_Sgtm::server_container_url() : '';

        $rows     = array();
        $warnings = array();

        foreach (self::PLATFORMS as $slug => $label) {
            $s              = ADC_Settings::all($slug);
            $mode           = (string) ($s['browser_mode'] ?? 'off');
            $server_enabled = !empty($s['enabled_server']);
            if (!in_array($mode, array('off', 'gtm', 'direct'), true)) {
                $mode = 'off';
            }

            $creds     = self::credentials_status($slug, $s);
            $server_ok = $server_enabled && $creds['ok'];
            // Effective browser channel: direct always loads; 'gtm' needs a live container.
            $browser_ok = 'direct' === $mode || ('gtm' === $mode && $gtm_active);

            $reasons    = array();
            $channel_on = false;
            $channel_ok = false;

            if ('gtm' === $mode) {
                $channel_on = true;
                if ($gtm_active) {
                    $channel_ok = true;
                } else {
                    $reasons[] = __('containerul GTM web este oprit sau fără ID', 'adc-marketing');
                }
            } elseif ('direct' === $mode) {
                $channel_on = true;
                $channel_ok = true;
            }

            if ($server_enabled) {
                $channel_on = true;
                if ($creds['ok']) {
                    $channel_ok = true;
                } else {
                    /* translators: %s: names of the missing credentials. */
                    $reasons[] = sprintf(__('lipsesc datele de conectare server (%s)', 'adc-marketing'), $creds['label']);
                }
            }

            if (!$channel_on) {
                $status = array('state' => 'off', 'label' => __('Dezactivat', 'adc-marketing'), 'reasons' => array());
            } elseif (empty($reasons)) {
                $status = array('state' => 'ok', 'label' => __('Activ', 'adc-marketing'), 'reasons' => array());
            } elseif ($channel_ok) {
                /* translators: %s: list of issues found in the settings. */
                $status = array('state' => 'warn', 'label' => sprintf(__('Activ, cu atenționări: %s', 'adc-marketing'), implode('; ', $reasons)), 'reasons' => $reasons);
            } else {
                /* translators: %s: list of issues found in the settings. */
                $status = array('state' => 'bad', 'label' => sprintf(__('Inactiv: %s', 'adc-marketing'), implode('; ', $reasons)), 'reasons' => $reasons);
            }

            $rows[$slug] = array(
                'label'          => $label,
                'browser_mode'   => $mode,
                'browser_ok'     => $browser_ok,
                'server_enabled' => $server_enabled,
                'server_ok'      => $server_ok,
                'status'         => $status,
            );

            // (1) browser_mode 'gtm' but the web container is off / has no id.
            if ('gtm' === $mode && !$gtm_active) {
                /* translators: %s: platform name (Meta, GA4, TikTok). */
                $warnings[] = array(
                    'id'       => 'browser_gtm_container_off',
                    'platform' => $slug,
                    'fix_tab'  => 'gtm',
                    'text'     => sprintf(__('Pixelul %1$s este setat să se încarce prin Google Tag Manager, dar containerul GTM web este oprit sau nu are un ID salvat — până atunci pixelul nu se încarcă deloc.', 'adc-marketing'), $label),
                );
            }

            // (2) GA4 direct while an active GTM container (which normally
            // also carries the GA4 tag) is loaded on the same pages.
            if ('ga4' === $slug && 'direct' === $mode && $gtm_active) {
                $warnings[] = array(
                    'id'       => 'browser_double_delivery',
                    'platform' => 'ga4',
                    'fix_tab'  => 'ga4',
                    'text'     => __('GA4 se încarcă direct în browser, iar containerul GTM web este activ. Dacă eticheta GA4 există și în container, evenimentele ajung la Google de două ori — păstrează o singură cale de încărcare pentru GA4.', 'adc-marketing'),
                );
            }

            // (3) server sending on, but the platform credentials are missing.
            if ($server_enabled && !$creds['ok']) {
                /* translators: 1: platform name, 2: names of the missing credentials. */
                $warnings[] = array(
                    'id'       => 'server_missing_credentials',
                    'platform' => $slug,
                    'fix_tab'  => $slug,
                    'text'     => sprintf(__('Trimiterea de pe server pentru %1$s este activată, dar lipsesc datele de conectare (%2$s) — până le completezi, evenimentele nu se trimit.', 'adc-marketing'), $label, $creds['label']),
                );
            }

            // (4) purchase from the server while the browser GA4 channel is live.
            if ('ga4' === $slug && 'server' === (string) ($s['purchase_channel'] ?? 'browser') && $browser_ok) {
                $warnings[] = array(
                    'id'       => 'purchase_double_channel',
                    'platform' => 'ga4',
                    'fix_tab'  => 'ga4',
                    'text'     => __('Comenzile GA4 (purchase) se trimit din server, dar și pixelul GA4 din browser este activ — comenzile pot fi numărate de două ori. Lasă o singură cale pentru comenzi.', 'adc-marketing'),
                );
            }
        }

        // (5) Server GTM enabled without a server container URL.
        if ($sgtm_enabled && '' === $sgtm_url) {
            $warnings[] = array(
                'id'       => 'sgtm_missing_url',
                'platform' => 'sgtm',
                'fix_tab'  => 'gtm',
                'text'     => __('Server GTM este activat, dar lipsește URL-ul containerului server — transportul prin propriul domeniu nu poate funcționa.', 'adc-marketing'),
            );
        }

        return array(
            'gtm_active'   => $gtm_active,
            'sgtm_enabled' => $sgtm_enabled,
            'sgtm_url'     => $sgtm_url,
            'rows'         => $rows,
            'warnings'     => $warnings,
        );
    }

    /**
     * Whether the platform's server credentials are present.
     *
     * @param string $slug Platform slug (settings group).
     * @param array  $s    Platform settings (ADC_Settings::all).
     * @return array{ok: bool, label: string}
     */
    private static function credentials_status(string $slug, array $s): array
    {
        $check = static function (string $id, string $secret, string $label): array {
            return array('ok' => '' !== trim($id) && '' !== $secret, 'label' => $label);
        };

        return match ($slug) {
            'meta'   => $check((string) ($s['pixel_id'] ?? ''), ADC_Settings::secret('meta', 'access_token'), __('ID pixel și token de acces', 'adc-marketing')),
            'ga4'    => $check((string) ($s['measurement_id'] ?? ''), ADC_Settings::secret('ga4', 'api_secret'), __('Measurement ID și cheie API', 'adc-marketing')),
            'tiktok' => $check((string) ($s['pixel_code'] ?? ''), ADC_Settings::secret('tiktok', 'access_token'), __('Pixel Code și token de acces', 'adc-marketing')),
            default  => array('ok' => false, 'label' => ''),
        };
    }

    /**
     * Render the tab inner HTML (no form tag, no nonce, no inputs — the
     * shell wraps read-only tabs too, minus the save bar).
     */
    public function render(): void
    {
        $snap = self::snapshot();

        ADC_Admin_Help::section(
            __('Matrice platforme', 'adc-marketing'),
            '',
            'adc-help-platforms-overview',
            '<p>' . esc_html__('Browser = modul de încărcare a pixelului în paginile vizitate: oprit, gestionat prin containerul GTM web sau încărcat direct de plugin. Server = trimiterea de evenimente direct către platformă, de pe server, pentru vizitatorii cu consimțământ de marketing. Stare = rezultatul celor două, calculat din setări.', 'adc-marketing') . '</p>'
        );

        // --- Matrix ---------------------------------------------------------
        echo '<div class="adc-platforms-scroll">';
        echo '<table class="adc-platforms-matrix">';
        echo '<thead><tr>'
            . '<th scope="col">' . esc_html__('Platformă', 'adc-marketing') . '</th>'
            . '<th scope="col">' . esc_html__('Browser', 'adc-marketing') . '</th>'
            . '<th scope="col">' . esc_html__('Server', 'adc-marketing') . '</th>'
            . '<th scope="col">' . esc_html__('Stare', 'adc-marketing') . '</th>'
            . '</tr></thead>';
        echo '<tbody>';

        foreach ($snap['rows'] as $slug => $row) {
            $edit_url = admin_url('admin.php?page=' . ADC_Admin_Shell::PAGE_SLUG . '&tab=' . rawurlencode((string) $slug));

            echo '<tr>';
            echo '<th scope="row">' . esc_html((string) $row['label']) . '</th>';

            // Browser column: read-only mode + link to the platform tab.
            echo '<td><span class="adc-platforms-mode adc-platforms-mode--' . esc_attr((string) $row['browser_mode']) . '">'
                . esc_html(self::browser_mode_label((string) $row['browser_mode']))
                . '</span> <a class="adc-platforms-edit" href="' . esc_url($edit_url) . '">'
                . esc_html__('Modifică', 'adc-marketing')
                . '</a></td>';

            // Server column: on/off with text (never colour alone).
            echo '<td><span class="adc-platforms-server adc-platforms-server--' . ($row['server_enabled'] ? 'on' : 'off') . '">'
                . '<span class="adc-platforms-dot" aria-hidden="true">' . ($row['server_enabled'] ? '✓' : '–') . '</span>'
                . ($row['server_enabled'] ? esc_html__('Activ', 'adc-marketing') : esc_html__('Inactiv', 'adc-marketing'))
                . '</span></td>';

            // State column: computed, icon + text.
            $status = $row['status'];
            echo '<td><span class="adc-platforms-status adc-platforms-status--' . esc_attr((string) $status['state']) . '">'
                . '<span class="adc-platforms-dot" aria-hidden="true">' . esc_html(self::state_icon((string) $status['state'])) . '</span> '
                . esc_html((string) $status['label'])
                . '</span></td>';

            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // --- Computed warnings ----------------------------------------------
        ADC_Admin_Help::section(
            __('De verificat', 'adc-marketing'),
            __('Situații din setările actuale care pot duce la date lipsă sau numărate dublu. Fiecare mesaj arată tabul unde poți interveni.', 'adc-marketing')
        );

        if (empty($snap['warnings'])) {
            echo '<p class="adc-platforms-all-clear"><span class="adc-platforms-dot" aria-hidden="true">✓</span> '
                . esc_html__('Nicio problemă detectată în setările actuale.', 'adc-marketing')
                . '</p>';
            return;
        }

        echo '<ul class="adc-platforms-warnings">';
        foreach ($snap['warnings'] as $warning) {
            $fix_tab  = (string) ($warning['fix_tab'] ?? '');
            $fix_url  = admin_url('admin.php?page=' . ADC_Admin_Shell::PAGE_SLUG . '&tab=' . rawurlencode($fix_tab));
            $fix_name = (string) (self::TAB_LABELS[$fix_tab] ?? $fix_tab);

            echo '<li class="adc-platforms-warning">';
            echo '<span class="adc-platforms-dot" aria-hidden="true">!</span> ';
            echo '<span class="adc-platforms-warning-text">' . esc_html((string) $warning['text']) . '</span> ';
            /* translators: %s: admin tab name. */
            echo '<a class="adc-platforms-warning-fix" href="' . esc_url($fix_url) . '">' . esc_html(sprintf(__('Deschide tabul %s', 'adc-marketing'), $fix_name)) . '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * Romanian label for a browser_mode value.
     *
     * @param string $mode off|gtm|direct.
     * @return string
     */
    private static function browser_mode_label(string $mode): string
    {
        return match ($mode) {
            'gtm'    => __('prin GTM', 'adc-marketing'),
            'direct' => __('Direct', 'adc-marketing'),
            default  => __('Dezactivat', 'adc-marketing'),
        };
    }

    /**
     * Glyph for a computed state (the text label carries the meaning; the
     * glyph only reinforces it).
     *
     * @param string $state ok|warn|bad|off.
     * @return string
     */
    private static function state_icon(string $state): string
    {
        return match ($state) {
            'ok'   => '✓',
            'warn' => '!',
            'bad'  => '✕',
            default => '–',
        };
    }
}
