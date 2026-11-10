<?php
/**
 * Conflict detector: finds other tracking / consent code that fights with the hub.
 *
 * Two detection layers, both read-only (detection NEVER disables anything —
 * docs/ARCHITECTURE.md §7.11):
 *
 * 1. Server layer (no HTTP): active plugins matched against a fingerprint table
 *    (CMP, GTM loaders, tag injectors, GA4 / Meta / TikTok pixels) plus a few
 *    class/constant probes for installs whose folder name does not match.
 * 2. Page layer (one cached fetch of the home page): extracts the GTM / gtag /
 *    Meta / TikTok ids actually present in the HTML, counts dataLayer resets
 *    and looks for foreign CMP markup. Own output is subtracted first, so the
 *    plugin does not report itself as a conflict.
 *
 * The report is cached in a 12 h transient and can be regenerated from the
 * "Conflicte" tab (`ADC_Admin_Tab_Conflicts`).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects tracking/consent conflicts and explains what to do about them.
 */
final class ADC_Conflict_Detector
{
    /**
     * Transient holding the cached report.
     */
    public const TRANSIENT = 'adc_conflict_report';

    /**
     * Previous (v1) transient of the crude Logs-tab scan, cleared on rescan.
     */
    public const LEGACY_TRANSIENT = 'adc_conflict_scan';

    /**
     * Cache lifetime in seconds (12 h).
     */
    public const TTL = 43200;

    /**
     * Severity levels.
     */
    public const CONFLICT = 'conflict';
    public const WARNING  = 'warning';
    public const INFO     = 'info';

    /**
     * Known third-party plugins: path needle => [label, domain].
     *
     * Domain: cmp | gtm | injector | ga4 | meta | tiktok.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const KNOWN_PLUGINS = array(
        // Consent management platforms.
        'complianz'                          => array('Complianz', 'cmp'),
        'cookiebot'                          => array('Cookiebot', 'cmp'),
        'cookie-law-info'                    => array('CookieYes', 'cmp'),
        'gdpr-cookie-compliance'             => array('GDPR Cookie Compliance', 'cmp'),
        'cookie-notice'                      => array('Cookie Notice', 'cmp'),
        'cookieadmin'                        => array('CookieAdmin', 'cmp'),
        'iubenda'                            => array('iubenda', 'cmp'),
        'borlabs-cookie'                     => array('Borlabs Cookie', 'cmp'),
        'tarteaucitron'                      => array('tarteaucitron.js', 'cmp'),
        'wpl-cookie-consent'                 => array('WP-Legal Cookie Consent', 'cmp'),
        // Tag managers and code injectors.
        'duracelltomi-google-tag-manager'    => array('GTM4WP', 'gtm'),
        'google-site-kit'                    => array('Site Kit by Google', 'gtm'),
        'google-tag-manager'                 => array('Google Tag Manager plugin', 'gtm'),
        'insert-headers-and-footers'         => array('WPCode', 'injector'),
        'wpcode'                             => array('WPCode', 'injector'),
        'code-snippets'                      => array('Code Snippets', 'injector'),
        'header-footer-code-manager'         => array('Header Footer Code Manager', 'injector'),
        'header-and-footer-scripts'          => array('Head & Footer Code', 'injector'),
        'wp-headers-and-footers'             => array('Head & Footer Code', 'injector'),
        // GA4.
        'google-analytics-for-wordpress'     => array('MonsterInsights', 'ga4'),
        'google-analytics-dashboard-for-wp'  => array('ExactMetrics', 'ga4'),
        'woocommerce-google-analytics-integration' => array('Google Analytics for WooCommerce', 'ga4'),
        'ga-google-analytics'                => array('GA Google Analytics', 'ga4'),
        // Meta pixel.
        'pixelyoursite'                      => array('PixelYourSite', 'meta'),
        'facebook-for-woocommerce'           => array('Facebook for WooCommerce', 'meta'),
        'official-facebook-pixel'             => array('Official Facebook Pixel', 'meta'),
        'pixel-caffeine'                     => array('Pixel Caffeine', 'meta'),
        'facebook-conversion-pixel'          => array('Facebook Conversion Pixel', 'meta'),
        // TikTok pixel.
        'tiktok-for-business'                => array('TikTok for Business', 'tiktok'),
        'tiktok-pixel'                       => array('TikTok Pixel', 'tiktok'),
    );

    /**
     * CMP fingerprints looked for in the page HTML: needle => label.
     *
     * @var array<string, string>
     */
    private const CMP_MARKERS = array(
        'cmplz'         => 'Complianz',
        'cookiebot'     => 'Cookiebot',
        'cookieyes'     => 'CookieYes',
        'borlabs'       => 'Borlabs Cookie',
        'iubenda'       => 'iubenda',
        'tarteaucitron' => 'tarteaucitron.js',
        'moove_gdpr'    => 'GDPR Cookie Compliance',
        // No ultra-short needles ('cli_', 'cky-'): two-char prefixes matched
        // theme classes (cli_wrap, cky-grid) and produced false CookieYes
        // hits. Longer vendor needles stay.
    );

    /* ---------------------------------------------------------------------
     * Public API.
     * ------------------------------------------------------------------- */

    /**
     * Cached report, generated on first read (or after a rescan).
     *
     * @param bool $force Ignore the cache and scan again.
     * @return array{generated: int, page: array{ok: bool, error: string}, plugins: array, own: array, signals: array, findings: array, summary: array}
     */
    public static function report(bool $force = false): array
    {
        if (!$force) {
            $cached = self::cached();
            if (null !== $cached) {
                return $cached;
            }
        }

        $page    = self::scan_page();
        $signals = self::html_signals((string) ($page['html'] ?? ''));
        $own     = self::own();
        $plugins = self::detected_plugins();

        $findings = self::analyze(array(
            'plugins' => $plugins,
            'own'     => $own,
            'signals' => $signals,
            'page'    => array(
                'ok' => (bool) ($page['ok'] ?? false),
            ),
        ));

        $report = array(
            'generated' => time(),
            'page'      => array(
                'ok'    => (bool) ($page['ok'] ?? false),
                'error' => (string) ($page['error'] ?? ''),
            ),
            'plugins'   => $plugins,
            'own'       => $own,
            'signals'   => $signals,
            'findings'  => $findings,
            'summary'   => self::summary($findings),
        );

        set_transient(self::TRANSIENT, $report, self::TTL);
        delete_transient(self::LEGACY_TRANSIENT);

        return $report;
    }

    /**
     * Cached report, or null when absent/expired.
     *
     * @return array|null
     */
    public static function cached(): ?array
    {
        $cached = get_transient(self::TRANSIENT);
        if (!is_array($cached) || !isset($cached['findings']) || !is_array($cached['findings'])) {
            return null;
        }

        return $cached;
    }

    /**
     * Drop the cached report (next read scans again).
     */
    public static function clear_cache(): void
    {
        delete_transient(self::TRANSIENT);
        delete_transient(self::LEGACY_TRANSIENT);
    }

    /**
     * Count findings per severity.
     *
     * @param array<int, array{severity: string}> $findings Findings list.
     * @return array{conflict: int, warning: int, info: int, total: int}
     */
    public static function summary(array $findings): array
    {
        $summary = array(
            self::CONFLICT => 0,
            self::WARNING  => 0,
            self::INFO     => 0,
            'total'        => 0,
        );

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? self::INFO);
            if (!isset($summary[$severity])) {
                $severity = self::INFO;
            }
            ++$summary[$severity];
            ++$summary['total'];
        }

        return $summary;
    }

    /* ---------------------------------------------------------------------
     * Detection layer 1 — installed plugins.
     * ------------------------------------------------------------------- */

    /**
     * Active plugin files (site-wide plugins included on multisite).
     *
     * @return string[]
     */
    public static function active_plugin_files(): array
    {
        $active = get_option('active_plugins', array());
        $files  = is_array($active) ? array_values(array_filter($active, 'is_string')) : array();

        if (function_exists('is_multisite') && is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', array());
            if (is_array($network)) {
                $files = array_merge($files, array_values(array_filter(array_keys($network), 'is_string')));
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Known third-party plugins that are active (folder/constant fingerprints).
     *
     * @return array<int, array{file: string, label: string, domain: string}>
     */
    public static function detected_plugins(): array
    {
        $found = array();
        $seen  = array();

        foreach (self::active_plugin_files() as $file) {
            $needle = strtolower($file);
            $best   = '';
            foreach (array_keys(self::KNOWN_PLUGINS) as $candidate) {
                if (str_contains($needle, $candidate) && strlen($candidate) > strlen($best)) {
                    $best = $candidate;
                }
            }

            if ('' === $best || isset($seen[$best])) {
                continue;
            }

            $seen[$best] = true;
            $found[]     = array(
                'file'   => $file,
                'label'  => self::KNOWN_PLUGINS[$best][0],
                'domain' => self::KNOWN_PLUGINS[$best][1],
            );
        }

        // Class/constant probes catch installs whose folder name does not match.
        foreach (self::constant_probes() as $probe) {
            $key = $probe['label'];
            if (isset($seen[$key])) {
                continue;
            }
            if (!empty($probe['constant']) && defined((string) $probe['constant'])) {
                $seen[$key] = true;
                $found[]    = array('file' => '', 'label' => $key, 'domain' => (string) $probe['domain']);
                continue;
            }
            if (!empty($probe['class']) && class_exists((string) $probe['class'])) {
                $seen[$key] = true;
                $found[]    = array('file' => '', 'label' => $key, 'domain' => (string) $probe['domain']);
            }
        }

        return $found;
    }

    /**
     * Constant/class probes for plugins that may live in an unexpected folder.
     *
     * @return array<int, array{label: string, domain: string, constant?: string, class?: string}>
     */
    private static function constant_probes(): array
    {
        return array(
            array('label' => 'GTM4WP', 'domain' => 'gtm', 'constant' => 'GTM4WP_VERSION'),
            array('label' => 'Site Kit by Google', 'domain' => 'gtm', 'constant' => 'GOOGLESITEKIT_VERSION'),
            array('label' => 'PixelYourSite', 'domain' => 'meta', 'class' => 'PixelYourSite'),
            array('label' => 'MonsterInsights', 'domain' => 'ga4', 'constant' => 'MONSTERINSIGHTS_VERSION'),
            array('label' => 'Complianz', 'domain' => 'cmp', 'constant' => 'CMPLZ_VERSION'),
            array('label' => 'Cookiebot', 'domain' => 'cmp', 'class' => 'Cookiebot_WP'),
        );
    }

    /* ---------------------------------------------------------------------
     * Own footprint.
     * ------------------------------------------------------------------- */

    /**
     * What this hub itself emits, so the scan can subtract it from the page.
     *
     * @return array{banner: bool, gtm_id: string, gtm_mode: string, sgtm: bool, platforms: array<string, string>, ga4_id: string, meta_id: string, tiktok_id: string}
     */
    public static function own(): array
    {
        $platforms = array();
        foreach (array('ga4', 'meta', 'tiktok') as $platform) {
            $mode = class_exists('ADC_Settings')
                ? (string) ADC_Settings::get($platform, 'browser_mode', 'off')
                : 'off';
            $platforms[$platform] = in_array($mode, array('off', 'gtm', 'direct'), true) ? $mode : 'off';
        }

        $string = static function (string $group, string $key) : string {
            if (!class_exists('ADC_Settings')) {
                return '';
            }
            $value = ADC_Settings::get($group, $key, '');
            return is_scalar($value) ? (string) $value : '';
        };

        return array(
            'banner'    => class_exists('ADC_Consent_Banner') && ADC_Consent_Banner::is_enabled(),
            // container_id() already returns '' when the web container is off.
            'gtm_id'    => class_exists('ADC_Gtm') ? ADC_Gtm::container_id() : '',
            'gtm_mode'  => class_exists('ADC_Gtm') ? ADC_Gtm::mode() : 'after_consent',
            'sgtm'      => !empty($string('sgtm', 'enabled')),
            'platforms' => $platforms,
            'ga4_id'    => strtoupper($string('ga4', 'measurement_id')),
            'meta_id'   => $string('meta', 'pixel_id'),
            'tiktok_id' => $string('tiktok', 'pixel_code'),
        );
    }

    /* ---------------------------------------------------------------------
     * Detection layer 2 — the rendered page.
     * ------------------------------------------------------------------- */

    /**
     * Fetch the home page once (read-only, never cached beyond the report).
     *
     * @param string $url Optional URL override (tests / staging).
     * @return array{html: string, ok: bool, error: string}
     */
    public static function scan_page(string $url = ''): array
    {
        $url = '' !== $url ? $url : home_url('/');

        if (!function_exists('wp_remote_get') || !function_exists('home_url')) {
            return array('html' => '', 'ok' => false, 'error' => 'wp_remote_get');
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 5,
                'redirection' => 0,
                'sslverify'   => true,
                // Defense in depth (security revalidation 2.6.1): cap the
                // body at 2 MB so a pathologically large homepage cannot
                // exhaust memory during an admin-triggered scan.
                'limit_response_size' => 2097152,
                'user-agent'  => 'ADC-eCOMM-Hub-Conflict-Scan/1.0; ' . home_url('/'),
            )
        );

        if (is_wp_error($response)) {
            return array('html' => '', 'ok' => false, 'error' => (string) $response->get_error_code());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return array('html' => '', 'ok' => false, 'error' => 'HTTP ' . $code);
        }

        $html = (string) wp_remote_retrieve_body($response);

        return array('html' => $html, 'ok' => '' !== $html, 'error' => '' !== $html ? '' : 'empty');
    }

    /**
     * Extract the tracking signals from a page HTML string.
     *
     * Pure function (no WordPress calls) so the offline tests can assert on it.
     *
     * @param string $html Page HTML.
     * @return array{gtm_ids: array<string, int>, ga4_ids: array<string, int>, meta_ids: array<string, int>, tiktok_ids: array<string, int>, datalayer_resets: int, cmp: array<string, int>}
     */
    public static function html_signals(string $html): array
    {
        $signals = array(
            'gtm_ids'           => array(),
            'ga4_ids'           => array(),
            'meta_ids'          => array(),
            'tiktok_ids'        => array(),
            'datalayer_resets'  => 0,
            'cmp'               => array(),
        );

        if ('' === trim($html)) {
            return $signals;
        }

        // HTML comments are not live markup: a `<!-- gtm.js?id=GTM-X -->`
        // left by a template or a commented-out CMP block must not raise a
        // foreign-container or CMP finding. Strip them before extraction.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';

        // GTM: scan each loader URL fragment, then pick the id= parameter.
        // 1200-char window: real snippets carry long query strings
        // (gtm.js?id=...&l=dataLayer&amp;cx=c&amp;...); 300 missed ids placed
        // further back in the same fragment.
        if (preg_match_all('/gtm\.js[^"\'<>\s]{0,1200}/i', $html, $matches)) {
            foreach ($matches[0] as $fragment) {
                if (preg_match('/[?&](?:amp;)?id=(GTM-[A-Za-z0-9]+)/i', $fragment, $id)) {
                    $signals['gtm_ids'][strtoupper($id[1])] = ($signals['gtm_ids'][strtoupper($id[1])] ?? 0) + 1;
                }
            }
        }

        // gtag.js ids (GA4 measurement ids and Google Ads conversions).
        if (preg_match_all('/gtag\/js[^"\'<>\s]{0,1200}/i', $html, $matches)) {
            foreach ($matches[0] as $fragment) {
                if (preg_match('/[?&](?:amp;)?id=([A-Z]{1,3}-[A-Z0-9]+)/i', $fragment, $id)) {
                    $key = strtoupper($id[1]);
                    $signals['ga4_ids'][$key] = ($signals['ga4_ids'][$key] ?? 0) + 1;
                }
            }
        }
        if (preg_match_all('/gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]([A-Z]{1,3}-[A-Z0-9]+)[\'"]/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $key = strtoupper($id);
                $signals['ga4_ids'][$key] = ($signals['ga4_ids'][$key] ?? 0) + 1;
            }
        }

        // Meta pixel ids (fbq('init', '1234567890')).
        if (preg_match_all('/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{6,})[\'"]/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $signals['meta_ids'][$id] = ($signals['meta_ids'][$id] ?? 0) + 1;
            }
        }

        // TikTok pixel ids (ttq.load('ABCDEF...')).
        if (preg_match_all('/ttq\.(?:load|init)\(\s*[\'"]([A-Za-z0-9]{8,})[\'"]/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $signals['tiktok_ids'][$id] = ($signals['tiktok_ids'][$id] ?? 0) + 1;
            }
        }

        // Explicit dataLayer resets lose everything pushed before them
        // (`window.dataLayer = window.dataLayer || []` does not match).
        $signals['datalayer_resets'] = (int) preg_match_all('/(?:\bwindow\.)?\bdataLayer\s*=\s*\[\s*\]/', $html);

        $lower = strtolower($html);
        foreach (self::CMP_MARKERS as $needle => $label) {
            $count = substr_count($lower, $needle);
            if ($count > 0) {
                $signals['cmp'][$label] = ($signals['cmp'][$label] ?? 0) + $count;
            }
        }

        return $signals;
    }

    /* ---------------------------------------------------------------------
     * Rules.
     * ------------------------------------------------------------------- */

    /**
     * Turn the detected context into a ranked list of findings.
     *
     * Pure function: no HTTP, no writes, no global state.
     *
     * @param array{plugins: array, own: array, signals: array, page?: array{ok: bool}} $context Detection context ('page' optional; ok=false suppresses page-absence findings).
     * @return array<int, array{id: string, severity: string, title: string, detail: string, action: string}>
     */
    public static function analyze(array $context): array
    {
        $plugins = isset($context['plugins']) && is_array($context['plugins']) ? $context['plugins'] : array();
        $own     = isset($context['own']) && is_array($context['own']) ? $context['own'] : array();
        $signals = isset($context['signals']) && is_array($context['signals']) ? $context['signals'] : array();

        // A failed page scan says nothing about what the page loads: when
        // 'page' is in the context with ok=false, page-absence findings
        // (own_gtm_missing) are suppressed — the meta line already reports
        // the read failure. Absent 'page' keeps older contexts behaving as
        // a successful scan.
        $page_ok = !(isset($context['page']) && is_array($context['page']) && empty($context['page']['ok']));

        $own += array(
            'banner'    => false,
            'gtm_id'    => '',
            'gtm_mode'  => 'after_consent',
            'platforms' => array('ga4' => 'off', 'meta' => 'off', 'tiktok' => 'off'),
            'ga4_id'    => '',
            'meta_id'   => '',
            'tiktok_id' => '',
        );

        $findings = array();

        $labels = static function (string $domain) use ($plugins): array {
            $out = array();
            foreach ($plugins as $plugin) {
                if ((string) ($plugin['domain'] ?? '') === $domain) {
                    $out[] = (string) ($plugin['label'] ?? '');
                }
            }
            return array_values(array_unique(array_filter($out)));
        };

        $cmp_plugins = $labels('cmp');
        $gtm_plugins = $labels('gtm');
        $injectors   = $labels('injector');
        $cmp_reported = false;

        // (1) Another consent management platform.
        if (array() !== $cmp_plugins) {
            $names = implode(', ', $cmp_plugins);
            if (!empty($own['banner'])) {
                $cmp_reported = true;
                $findings[]   = array(
                    'id'       => 'other_cmp_active',
                    'severity' => self::CONFLICT,
                    'title'    => __('Al doilea instrument de consimțământ este activ', 'adc-marketing'),
                    'detail'   => sprintf(
                        /* translators: %s: plugin names, comma separated. */
                        __('%s gestionează deja bannerul și consimțământul în acest site. Cu bannerul propriu pornit rulează două CMP-uri: vizitatorul vede două bannere, iar etichetele depind de două stări de consimțământ care pot să nu fie sincronizate.', 'adc-marketing'),
                        $names
                    ),
                    'action'   => __('Păstrați un singur CMP: fie opriți bannerul propriu din tabul Banner, fie dezactivați celălalt plugin. Evenimentele trebuie să pornească dintr-o singură sursă de consimțământ.', 'adc-marketing'),
                );
            } else {
                $cmp_reported = true;
                $findings[]   = array(
                    'id'       => 'other_cmp_inactive',
                    'severity' => self::INFO,
                    'title'    => __('Alt instrument de consimțământ detectat', 'adc-marketing'),
                    'detail'   => sprintf(
                        /* translators: %s: plugin names, comma separated. */
                        __('%s este activ, dar bannerul propriu este oprit — cele două nu se suprapun.', 'adc-marketing'),
                        $names
                    ),
                    'action'   => '',
                );
            }
        }

        // (2) Another GTM loader.
        if (array() !== $gtm_plugins) {
            $names = implode(', ', $gtm_plugins);
            if ('' !== (string) $own['gtm_id']) {
                $findings[] = array(
                    'id'       => 'other_gtm_loader',
                    'severity' => self::CONFLICT,
                    'title'    => __('Al doilea încărcător Google Tag Manager', 'adc-marketing'),
                    'detail'   => sprintf(
                        /* translators: 1: plugin names, 2: own GTM container id. */
                        __('%1$s poate încărca propriul container GTM, iar containerul propriu (%2$s) se încarcă în paralel. Rezultatul tipic: page_view și evenimentele de comerț numărate de două ori.', 'adc-marketing'),
                        $names,
                        (string) $own['gtm_id']
                    ),
                    'action'   => __('Alegeți o singură cale: lăsați containerul în grija pluginului propriu (și opriți încărcarea în celălalt plugin) sau goliți ID-ul containerului propriu și folosiți-l pe al lor.', 'adc-marketing'),
                );
            } else {
                $findings[] = array(
                    'id'       => 'other_gtm_loader_only',
                    'severity' => self::INFO,
                    'title'    => __('Alt instrument care încarcă GTM', 'adc-marketing'),
                    'detail'   => sprintf(
                        /* translators: %s: plugin names, comma separated. */
                        __('%s este activ. Containerul propriu este oprit, deci nu există suprapunere.', 'adc-marketing'),
                        $names
                    ),
                    'action'   => '',
                );
            }
        }

        // (3) Code injectors: they can carry any hardcoded snippet.
        if (array() !== $injectors) {
            $findings[] = array(
                'id'       => 'code_injector_active',
                'severity' => self::INFO,
                'title'    => __('Cod injectat manual detectat', 'adc-marketing'),
                'detail'   => sprintf(
                    /* translators: %s: plugin names, comma separated. */
                    __('%s permite adăugarea de cod în site. Verificați dacă fragmentele GTM/gtag/pixel sunt introduse și acolo, nu doar în acest plugin.', 'adc-marketing'),
                    implode(', ', $injectors)
                ),
                'action'   => '',
            );
        }

        // (4) Pixel plugins per platform, weighted by our own browser mode.
        foreach (array('ga4' => 'GA4', 'meta' => 'Meta', 'tiktok' => 'TikTok') as $platform => $platform_label) {
            $names = $labels($platform);
            if (array() === $names) {
                continue;
            }

            $mode = (string) ($own['platforms'][$platform] ?? 'off');
            $list = implode(', ', $names);

            if ('direct' === $mode) {
                $findings[] = array(
                    'id'       => 'other_' . $platform . '_direct',
                    'severity' => self::CONFLICT,
                    'title'    => sprintf(
                        /* translators: %s: platform name. */
                        __('%s este trimis de două ori din browser', 'adc-marketing'),
                        $platform_label
                    ),
                    'detail'   => sprintf(
                        /* translators: 1: plugin names, 2: platform name. */
                        __('%1$s trimite deja evenimente %2$s direct din browser, iar pluginul propriu este setat pe „direct” pentru %2$s. Aceleași conversii pot fi raportate de două ori.', 'adc-marketing'),
                        $list,
                        $platform_label
                    ),
                    'action'   => sprintf(
                        /* translators: %s: platform name. */
                        __('Puneți %s pe „prin GTM” (o singură etichetă în container) sau dezactivați-l dacă pixelul rămâne în grija celuilalt plugin.', 'adc-marketing'),
                        $platform_label
                    ),
                );
            } elseif ('gtm' === $mode) {
                $findings[] = array(
                    'id'       => 'other_' . $platform . '_gtm',
                    'severity' => self::WARNING,
                    'title'    => sprintf(
                        /* translators: %s: platform name. */
                        __('%s poate fi raportat de două ori', 'adc-marketing'),
                        $platform_label
                    ),
                    'detail'   => sprintf(
                        /* translators: 1: plugin names, 2: platform name. */
                        __('%1$s este activ și încarcă %2$s. Dacă aceeași etichetă %2$s există și în containerul propriu, evenimentele se dublează.', 'adc-marketing'),
                        $list,
                        $platform_label
                    ),
                    'action'   => __('Verificați containerul GTM: păstrați o singură etichetă pentru platformă.', 'adc-marketing'),
                );
            } else {
                $findings[] = array(
                    'id'       => 'other_' . $platform . '_off',
                    'severity' => self::INFO,
                    'title'    => sprintf(
                        /* translators: %s: platform name. */
                        __('%s este gestionat de alt plugin', 'adc-marketing'),
                        $platform_label
                    ),
                    'detail'   => sprintf(
                        /* translators: 1: plugin names, 2: platform name. */
                        __('%1$s încarcă %2$s, iar pluginul propriu are %2$s oprit — nu se suprapune.', 'adc-marketing'),
                        $list,
                        $platform_label
                    ),
                    'action'   => '',
                );
            }
        }

        // (5) Containers found in the page HTML that are not ours.
        $own_gtm    = (string) $own['gtm_id'];
        $gtm_ids    = isset($signals['gtm_ids']) && is_array($signals['gtm_ids']) ? $signals['gtm_ids'] : array();
        $foreign    = array();
        foreach (array_keys($gtm_ids) as $id) {
            if ($id !== $own_gtm) {
                $foreign[] = $id;
            }
        }

        if (array() !== $foreign) {
            $findings[] = array(
                'id'       => 'foreign_gtm_container',
                'severity' => self::CONFLICT,
                'title'    => __('Container GTM străin în pagină', 'adc-marketing'),
                'detail'   => sprintf(
                    /* translators: %s: comma separated GTM container ids. */
                    __('În HTML-ul primei pagini apar containere GTM pe care pluginul nu le cunoaște: %s. Ele înseamnă un snippet hardcodat în temă sau în alt plugin, care rulează în paralel cu containerul propriu.', 'adc-marketing'),
                    implode(', ', $foreign)
                ),
                'action'   => __('Scoateți snippet-ul din temă/plugin sau renunțați la containerul propriu, apoi rescanați.', 'adc-marketing'),
            );
        }

        if ('' !== $own_gtm && isset($gtm_ids[$own_gtm])) {
            $count = (int) $gtm_ids[$own_gtm];

            if ($count > 1) {
                $findings[] = array(
                    'id'       => 'duplicate_own_gtm',
                    'severity' => self::CONFLICT,
                    'title'    => __('Containerul propriu se încarcă de două ori', 'adc-marketing'),
                    'detail'   => sprintf(
                        /* translators: 1: GTM container id, 2: number of occurrences. */
                        _n('Containerul %1$s apare de %2$d ori în HTML-ul paginii.', 'Containerul %1$s apare de %2$d ori în HTML-ul paginii.', $count, 'adc-marketing'),
                        $own_gtm,
                        $count
                    ),
                    'action'   => __('Păstrați un singur încărcător: opriți snippet-ul hardcodat sau încărcarea din celălalt plugin.', 'adc-marketing'),
                );
            } elseif ('after_consent' === (string) $own['gtm_mode']) {
                $findings[] = array(
                    'id'       => 'gtm_before_consent',
                    'severity' => self::CONFLICT,
                    'title'    => __('Containerul GTM se încarcă înainte de consimțământ', 'adc-marketing'),
                    'detail'   => sprintf(
                        /* translators: %s: GTM container id. */
                        __('Modul „doar după consimțământ” este activ, dar containerul %s apare deja în HTML-ul paginii — semn de snippet hardcodat în temă sau în alt plugin. Etichetele pot porni fără consimțământ.', 'adc-marketing'),
                        $own_gtm
                    ),
                    'action'   => __('Eliminați snippet-ul hardcodat; lăsați încărcarea exclusiv pluginului, după consimțământ.', 'adc-marketing'),
                );
            }
        } elseif ('' !== $own_gtm && 'always_with_defaults' === (string) $own['gtm_mode'] && array() === $foreign && $page_ok) {
            $findings[] = array(
                'id'       => 'own_gtm_missing',
                'severity' => self::WARNING,
                'title'    => __('Containerul propriu nu apare în pagină', 'adc-marketing'),
                'detail'   => sprintf(
                    /* translators: %s: GTM container id. */
                    __('Containerul %s este configurat, dar codul GTM nu apare în HTML-ul primei pagini. Verificați un cache/CDN sau o eroare de randare a temei.', 'adc-marketing'),
                    $own_gtm
                ),
                'action'   => __('Goliți cache-ul site-ului și rescanați.', 'adc-marketing'),
            );
        }

        // (6) Platform ids present in the page, per browser mode.
        $pixels = array(
            'ga4'    => array('label' => 'GA4', 'own_key' => 'ga4_id', 'ids_key' => 'ga4_ids'),
            'meta'   => array('label' => 'Meta', 'own_key' => 'meta_id', 'ids_key' => 'meta_ids'),
            'tiktok' => array('label' => 'TikTok', 'own_key' => 'tiktok_id', 'ids_key' => 'tiktok_ids'),
        );

        foreach ($pixels as $platform => $spec) {
            $ids = isset($signals[$spec['ids_key']]) && is_array($signals[$spec['ids_key']]) ? $signals[$spec['ids_key']] : array();
            if (array() === $ids) {
                continue;
            }

            $mode  = (string) ($own['platforms'][$platform] ?? 'off');
            $found = implode(', ', array_keys($ids));
            $label = $spec['label'];

            if ('direct' === $mode) {
                $findings[] = array(
                    'id'       => 'page_' . $platform . '_duplicate',
                    'severity' => self::CONFLICT,
                    'title'    => sprintf(
                        /* translators: %s: platform name. */
                        __('%s apare în pagină și prin altă cale', 'adc-marketing'),
                        $label
                    ),
                    'detail'   => sprintf(
                        /* translators: 1: platform name, 2: comma separated ids. */
                        __('Pagina încarcă %1$s (%2$s), iar pluginul propriu este setat pe „direct” pentru %1$s: același pixel este inițializat de două ori.', 'adc-marketing'),
                        $label,
                        $found
                    ),
                    'action'   => sprintf(
                        /* translators: %s: platform name. */
                        __('Păstrați o singură sursă pentru %s (pluginul propriu sau celălalt) și rescanați.', 'adc-marketing'),
                        $label
                    ),
                );
            } elseif ('gtm' === $mode || 'off' === $mode) {
                $findings[] = array(
                    'id'       => 'page_' . $platform . '_external',
                    'severity' => 'gtm' === $mode ? self::WARNING : self::INFO,
                    'title'    => sprintf(
                        /* translators: %s: platform name. */
                        __('%s este încărcat de altundeva', 'adc-marketing'),
                        $label
                    ),
                    'detail'   => sprintf(
                        /* translators: 1: platform name, 2: comma separated ids. */
                        __('Pagina conține inițializarea %1$s (%2$s) din altă sursă. Pluginul propriu are %1$s pe „%3$s” — verificați să nu rulați aceeași etichetă de două ori.', 'adc-marketing'),
                        $label,
                        $found,
                        'gtm' === $mode ? __('prin GTM', 'adc-marketing') : __('oprit', 'adc-marketing')
                    ),
                    'action'   => '',
                );
            }
        }

        // (7) dataLayer resets destroy earlier pushes.
        $resets = (int) ($signals['datalayer_resets'] ?? 0);
        if ($resets > 0) {
            $findings[] = array(
                'id'       => 'datalayer_reset',
                'severity' => self::WARNING,
                'title'    => __('dataLayer este reinițializat', 'adc-marketing'),
                'detail'   => sprintf(
                    /* translators: %d: number of resets. */
                    _n('Pagina golește variabila dataLayer (%d reinițializare). Evenimentele trimise înainte de acel punct se pierd.', 'Pagina golește variabila dataLayer (%d reinițializări). Evenimentele trimise înainte de acel punct se pierd.', $resets, 'adc-marketing'),
                    $resets
                ),
                'action'   => __('Căutați `dataLayer = []` în snippet-urile altor pluginuri/temă și înlocuiți-l cu `window.dataLayer = window.dataLayer || []`.', 'adc-marketing'),
            );
        }

        // (8) Foreign CMP markup when another CMP plugin was not detected
        // (theme or hardcoded banner).
        $cmp_markers = isset($signals['cmp']) && is_array($signals['cmp']) ? $signals['cmp'] : array();
        if (!$cmp_reported && array() !== $cmp_markers && !empty($own['banner'])) {
            $findings[] = array(
                'id'       => 'other_cmp_markup',
                'severity' => self::CONFLICT,
                'title'    => __('Al doilea banner de consimțământ în pagină', 'adc-marketing'),
                'detail'   => sprintf(
                    /* translators: %s: comma separated CMP names found in the HTML. */
                    __('HTML-ul primei pagini conține cod de la %s, deci un al doilea banner rulează în browser, deși bannerul propriu este pornit.', 'adc-marketing'),
                    implode(', ', array_keys($cmp_markers))
                ),
                'action'   => __('Păstrați un singur banner de consimțământ (vezi și tabul Banner).', 'adc-marketing'),
            );
        }

        return self::sort_findings($findings);
    }

    /**
     * Sort findings: conflicts first, then warnings, then info.
     *
     * @param array<int, array{severity: string}> $findings Findings list.
     * @return array<int, array{severity: string}>
     */
    private static function sort_findings(array $findings): array
    {
        $weight = array(self::CONFLICT => 0, self::WARNING => 1, self::INFO => 2);

        usort(
            $findings,
            static function (array $a, array $b) use ($weight): int {
                $left  = $weight[(string) ($a['severity'] ?? self::INFO)] ?? 2;
                $right = $weight[(string) ($b['severity'] ?? self::INFO)] ?? 2;

                return $left <=> $right;
            }
        );

        return $findings;
    }
}
