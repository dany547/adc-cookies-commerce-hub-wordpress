<?php
/**
 * Admin tab "Conflicte" — what else is tracking on this site, and what fights with it.
 *
 * Read-only view over ADC_Conflict_Detector: a status line (last scan, page
 * scan result), one row per finding with a severity badge, the explanation and
 * the short fix, plus the list of detected third-party plugins. The only
 * action is a manual rescan (`admin-post.php?action=adc_conflict_rescan`,
 * nonce action `adc_conflict_rescan`, cap manage_options).
 *
 * Detection never disables or rewrites another plugin's settings
 * (docs/ARCHITECTURE.md §7.11): the tab explains, the admin decides.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Conflicts tab (read-only, one rescan action).
 */
final class ADC_Admin_Tab_Conflicts implements ADC_Admin_Tab_Interface, ADC_Module_Interface
{
    /**
     * admin-post action and nonce action of the rescan link.
     */
    public const RESCAN_ACTION = 'adc_conflict_rescan';

    /**
     * Register the rescan endpoint.
     */
    public static function boot(): void
    {
        add_action('admin_post_' . self::RESCAN_ACTION, array(self::class, 'handle_rescan'));
    }

    /**
     * Tab slug.
     *
     * @return string
     */
    public function slug(): string
    {
        return 'conflicts';
    }

    /**
     * Tab label (RO).
     *
     * @return string
     */
    public function label(): string
    {
        return __('Conflicte', 'adc-marketing');
    }

    /**
     * Legacy interface value ('' = top-level); nav placement comes from
     * ADC_Admin_Shell::NAV_GROUPS (this tab sits in the "Jurnale" group).
     *
     * @return string
     */
    public function group(): string
    {
        return '';
    }

    /**
     * Sort order: after the logs tab (90) inside the same group listing.
     *
     * @return int
     */
    public function order(): int
    {
        return 95;
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
     * Nothing to save (read-only report).
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
     * Rescan handler: force a fresh report and redirect back to the tab.
     */
    public static function handle_rescan(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nu aveți permisiunea necesară.', 'adc-marketing'), '', array('response' => 403));
        }

        check_admin_referer(self::RESCAN_ACTION);

        ADC_Conflict_Detector::report(true);

        wp_safe_redirect(
            add_query_arg(
                array('tab' => 'conflicts', 'rescanned' => '1'),
                admin_url('admin.php?page=' . ADC_Admin_Shell::PAGE_SLUG)
            )
        );
        exit;
    }

    /**
     * Render the report.
     */
    public function render(): void
    {
        // Same guard as the Dashboard/Logs conflict sections (§4.3): a
        // partial upload must degrade to a message, not a fatal.
        if (!class_exists('ADC_Conflict_Detector')) {
            echo '<p class="description">' . esc_html__('Detectorul de conflicte nu este disponibil în această instalare.', 'adc-marketing') . '</p>';
            return;
        }

        $report   = ADC_Conflict_Detector::report();
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : array();
        $summary  = is_array($report['summary'] ?? null) ? $report['summary'] : ADC_Conflict_Detector::summary($findings);
        $plugins  = is_array($report['plugins'] ?? null) ? $report['plugins'] : array();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag.
        $rescanned = isset($_GET['rescanned']);

        ADC_Admin_Help::section(
            __('Ce rulează deja în site', 'adc-marketing'),
            __('Detectarea citește lista de pluginuri active și HTML-ul primei pagini, apoi compară cu ce emite acest plugin. Nu modifică și nu dezactivează nimic automat.', 'adc-marketing')
        );

        if ($rescanned) {
            echo '<div class="notice notice-success inline"><p>'
                . esc_html__('Scanarea a fost reluată.', 'adc-marketing')
                . '</p></div>';
        }

        echo '<div class="adc-conflicts-bar">';
        echo '<p class="adc-conflicts-summary">';
        echo self::summary_badges($summary); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in summary_badges().
        echo '</p>';

        $generated = (int) ($report['generated'] ?? 0);
        $scanned   = $generated > 0
            ? (function_exists('wp_date') ? wp_date('d.m.Y H:i', $generated) : gmdate('d.m.Y H:i', $generated))
            : '';

        echo '<p class="description adc-conflicts-meta">';
        if ('' !== $scanned) {
            /* translators: %s: date and time of the last scan. */
            echo esc_html(sprintf(__('Ultima scanare: %s.', 'adc-marketing'), $scanned)) . ' ';
        }

        $page = is_array($report['page'] ?? null) ? $report['page'] : array();
        if (empty($page['ok'])) {
            $reason = (string) ($page['error'] ?? '');
            echo '<span class="adc-conflicts-meta-warn">'
                /* translators: %s: short technical reason (HTTP code or error code). */
                . esc_html(sprintf(__('Prima pagină nu a putut fi citită (%s), deci doar pluginurile active au fost verificate.', 'adc-marketing'), '' !== $reason ? $reason : 'HTTP'))
                . '</span> ';
        } else {
            echo esc_html__('HTML-ul primei pagini a fost verificat.', 'adc-marketing') . ' ';
        }

        $rescan_url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::RESCAN_ACTION),
            self::RESCAN_ACTION
        );
        echo '<a class="button button-small" href="' . esc_url($rescan_url) . '">'
            . esc_html__('Rescanează', 'adc-marketing')
            . '</a>';
        echo '</p>';
        echo '</div>';

        // --- Findings -------------------------------------------------------
        ADC_Admin_Help::section(
            __('Suprapuneri detectate', 'adc-marketing'),
            __('Fiecare rând arată ce s-a găsit și ce ai de făcut. Conflictele pot produce evenimente dublate sau etichete încărcate fără consimțământ.', 'adc-marketing')
        );

        if (array() === $findings) {
            echo '<p class="adc-conflicts-clear"><span class="adc-conflicts-dot" aria-hidden="true">✓</span> '
                . esc_html__('Nicio suprapunere detectată.', 'adc-marketing')
                . '</p>';
        } else {
            echo '<table class="widefat striped adc-conflicts-table">';
            echo '<thead><tr>'
                . '<th scope="col">' . esc_html__('Găsit', 'adc-marketing') . '</th>'
                . '<th scope="col">' . esc_html__('Detaliu', 'adc-marketing') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($findings as $finding) {
                $severity = (string) ($finding['severity'] ?? ADC_Conflict_Detector::INFO);
                echo '<tr class="adc-conflicts-row adc-conflicts-row--' . esc_attr($severity) . '">';
                echo '<th scope="row">' . self::severity_badge($severity) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in severity_badge().
                    . '<span class="adc-conflicts-title">' . esc_html((string) ($finding['title'] ?? '')) . '</span></th>';
                echo '<td><p class="adc-conflicts-detail">' . esc_html((string) ($finding['detail'] ?? '')) . '</p>';
                if ('' !== (string) ($finding['action'] ?? '')) {
                    echo '<p class="adc-conflicts-action"><strong>' . esc_html__('Ce faci:', 'adc-marketing') . '</strong> '
                        . esc_html((string) $finding['action']) . '</p>';
                }
                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        // --- Detected plugins ----------------------------------------------
        ADC_Admin_Help::section(
            __('Pluginuri recunoscute în site', 'adc-marketing'),
            __('Lista este construită din pluginurile active; „Altele” poate conține cod hardcodat în temă, care apare doar în scanarea paginii.', 'adc-marketing')
        );

        if (array() === $plugins) {
            echo '<p class="description">' . esc_html__('Niciun plugin de tracking sau consimțământ cunoscut nu este activ.', 'adc-marketing') . '</p>';
        } else {
            echo '<ul class="adc-conflicts-plugins">';
            foreach ($plugins as $plugin) {
                echo '<li><strong>' . esc_html((string) ($plugin['label'] ?? '')) . '</strong> — '
                    . esc_html(self::domain_label((string) ($plugin['domain'] ?? '')))
                    . '</li>';
            }
            echo '</ul>';
        }

        echo '<p class="description adc-conflicts-note">'
            . esc_html__('Limitare: scanarea paginii vede doar HTML-ul livrat de server. Etichetele injectate exclusiv din JavaScript, după încărcare, nu apar aici.', 'adc-marketing')
            . '</p>';
    }

    /**
     * One severity badge (icon + text, never colour alone).
     *
     * @param string $severity conflict|warning|info.
     * @return string
     */
    private static function severity_badge(string $severity): string
    {
        $map = array(
            ADC_Conflict_Detector::CONFLICT => array('icon' => '!', 'label' => __('Conflict', 'adc-marketing')),
            ADC_Conflict_Detector::WARNING  => array('icon' => '!', 'label' => __('Atenționare', 'adc-marketing')),
            ADC_Conflict_Detector::INFO     => array('icon' => 'i', 'label' => __('Informativ', 'adc-marketing')),
        );
        $entry = $map[$severity] ?? $map[ADC_Conflict_Detector::INFO];

        return '<span class="adc-conflicts-badge adc-conflicts-badge--' . esc_attr($severity) . '">'
            . '<span class="adc-conflicts-dot" aria-hidden="true">' . esc_html($entry['icon']) . '</span> '
            . esc_html($entry['label'])
            . '</span>';
    }

    /**
     * Summary counts as badges.
     *
     * @param array{conflict?: int, warning?: int, info?: int, total?: int} $summary Counts.
     * @return string
     */
    private static function summary_badges(array $summary): string
    {
        $total = (int) ($summary['total'] ?? 0);
        if (0 === $total) {
            return '<span class="adc-conflicts-badge adc-conflicts-badge--ok">'
                . '<span class="adc-conflicts-dot" aria-hidden="true">✓</span> '
                . esc_html__('Fără suprapuneri', 'adc-marketing')
                . '</span>';
        }

        $parts = array();
        foreach (array(
            ADC_Conflict_Detector::CONFLICT => __('conflicte', 'adc-marketing'),
            ADC_Conflict_Detector::WARNING  => __('atenționări', 'adc-marketing'),
            ADC_Conflict_Detector::INFO     => __('informative', 'adc-marketing'),
        ) as $key => $label) {
            $count = (int) ($summary[$key] ?? 0);
            if ($count < 1) {
                continue;
            }
            $parts[] = '<span class="adc-conflicts-badge adc-conflicts-badge--' . esc_attr($key) . '">'
                . '<span class="adc-conflicts-dot" aria-hidden="true">' . esc_html('info' === $key ? 'i' : '!') . '</span> '
                . esc_html(sprintf('%d %s', $count, $label))
                . '</span>';
        }

        return implode(' ', $parts);
    }

    /**
     * Romanian label for a detection domain.
     *
     * @param string $domain cmp|gtm|injector|ga4|meta|tiktok.
     * @return string
     */
    private static function domain_label(string $domain): string
    {
        return match ($domain) {
            'cmp'      => __('banner de consimțământ', 'adc-marketing'),
            'gtm'      => __('Google Tag Manager', 'adc-marketing'),
            'injector' => __('cod injectat', 'adc-marketing'),
            'ga4'      => __('GA4', 'adc-marketing'),
            'meta'     => __('Meta', 'adc-marketing'),
            'tiktok'   => __('TikTok', 'adc-marketing'),
            default    => __('necunoscut', 'adc-marketing'),
        };
    }
}
