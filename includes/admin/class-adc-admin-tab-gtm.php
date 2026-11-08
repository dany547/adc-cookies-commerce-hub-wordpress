<?php
/**
 * Admin tab "Măsurare ▸ Google Tag Manager" (docs/ARCHITECTURE.md §2.1, §4; §9 D20).
 *
 * Unified settings UI for the `gtm` group (web container) and the `sgtm`
 * group (optional server container). Replaces the GTM section that used to
 * live in the Events tab and the dedicated "Server GTM" tab: one form, two
 * cards, a 3-step schema on top and a live status badge per card computed
 * from the stored settings.
 *
 * Legacy compatibility: the `sgtm` tab slug and the admin-AJAX test stay
 * functional — ADC_Admin_Tab_Sgtm remains a booted delegate that registers
 * the inline test-button wiring (element ids `adc-sgtm-test` /
 * `adc-sgtm-test-result`, endpoint `adc_test_integration` with
 * `platform=sgtm`, nonce `adc_admin`, cap `manage_options`), and this tab
 * renders the matching element ids. The server URL has a single source of
 * truth (the `sgtm` group); on save the legacy `gtm.server_container_url`
 * copy is cleared (D20).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Unified Google Tag Manager settings tab (web container + optional server).
 */
final class ADC_Admin_Tab_Gtm implements ADC_Admin_Tab_Interface
{
    use ADC_Admin_Help_Content;

    /**
     * GTM container id pattern (§4 validation rules; mirrors ADC_Gtm::sanitize).
     */
    private const GTM_PATTERN = '/^GTM-[A-Z0-9]+$/';

    /**
     * Tab slug.
     *
     * @return string
     */
    public function slug(): string
    {
        return 'gtm';
    }

    /**
     * Tab label (RO).
     *
     * @return string
     */
    public function label(): string
    {
        return __('Google Tag Manager', 'adc-marketing');
    }

    /**
     * Legacy interface value ('' = top-level); the nav placement is driven by
     * ADC_Admin_Shell::NAV_GROUPS (this tab sits in the "Măsurare" group).
     *
     * @return string
     */
    public function group(): string
    {
        return '';
    }

    /**
     * Tab order: between Analytics (75 within its own group list) and
     * Events (20) inside the "Măsurare" group listing.
     *
     * @return int
     */
    public function order(): int
    {
        return 15;
    }

    /**
     * Compute the per-card status shown next to the card title.
     *
     * @param bool $enabled Whether the feature switch is on.
     * @param bool $has_url Whether the required identifier/URL is present.
     * @param string $missing_label Reason shown when enabled but unconfigured.
     * @return array{state: string, label: string} state: active|inactive|off.
     */
    private static function card_status(bool $enabled, bool $has_url, string $missing_label): array
    {
        if (!$enabled) {
            return array('state' => 'off', 'label' => __('Dezactivat', 'adc-marketing'));
        }

        if (!$has_url) {
            return array('state' => 'inactive', 'label' => __('Inactiv', 'adc-marketing') . ': ' . $missing_label);
        }

        return array('state' => 'active', 'label' => __('Activ', 'adc-marketing'));
    }

    /**
     * Render one status badge (pre-escaped label via esc_html below).
     *
     * @param array{state: string, label: string} $status Card status.
     * @return string
     */
    private static function status_badge(array $status): string
    {
        return '<span class="adc-gtm-status adc-gtm-status--' . esc_attr($status['state']) . '">'
            . '<span class="dashicons ' . esc_attr('active' === $status['state'] ? 'dashicons-yes-alt' : ('inactive' === $status['state'] ? 'dashicons-warning' : 'dashicons-minus')) . '" aria-hidden="true"></span> '
            . esc_html($status['label'])
            . '</span>';
    }

    /**
     * Render the tab inner HTML (no form tag, no nonce — the shell wraps them).
     */
    public function render(): void
    {
        $gtm        = ADC_Settings::all('gtm');
        $sgtm       = ADC_Settings::all('sgtm');
        $has_secret = '' !== ADC_Settings::secret('sgtm', 'api_secret');
        $url        = ADC_Integration_Sgtm::server_container_url();
        // Legacy installs stored the URL in the gtm group: show the effective value.
        if ('' === (string) $sgtm['server_container_url'] && '' !== $url) {
            $sgtm['server_container_url'] = $url;
        }
        $provider_hint = '' !== $url && 1 === preg_match('/\.run\.app$/i', (string) parse_url($url, PHP_URL_HOST));

        $web_id        = strtoupper(trim((string) ($gtm['container_id'] ?? '')));
        $web_valid_id  = '' !== $web_id && 1 === preg_match(self::GTM_PATTERN, $web_id);
        $web_status    = self::card_status(!empty($gtm['web_enabled']), $web_valid_id, __('lipsește ID-ul containerului', 'adc-marketing'));
        $server_status = self::card_status(!empty($sgtm['enabled']), '' !== (string) $sgtm['server_container_url'], __('lipsește URL', 'adc-marketing'));

        // --- Summary status right under the page intro (text + icon per container). ---
        echo '<p class="adc-gtm-summary">'
            . '<span class="adc-gtm-summary__label">' . esc_html__('Stare acum:', 'adc-marketing') . '</span> '
            . self::status_badge(array(
                'state' => $web_status['state'],
                /* translators: %s: status label, e.g. "Activ". */
                'label' => sprintf(__('Container web — %s', 'adc-marketing'), $web_status['label']),
            ))
            . ' '
            . self::status_badge(array(
                'state' => $server_status['state'],
                /* translators: %s: status label, e.g. "Dezactivat". */
                'label' => sprintf(__('Server GTM — %s', 'adc-marketing'), $server_status['label']),
            ))
            . '</p>';

        // --- 3-step schema: Browser → web container → (optional) server. ---
        echo '<div class="adc-gtm-schema">';
        echo '<ol class="adc-gtm-schema-steps">';
        echo '<li class="adc-gtm-schema-step"><span class="adc-gtm-schema-step-num">1</span>'
            . '<span class="adc-gtm-schema-step-title">' . esc_html__('Browser', 'adc-marketing') . '</span>'
            . '<span class="adc-gtm-schema-step-desc">' . esc_html__('paginile deschise de vizitatori', 'adc-marketing') . '</span></li>';
        echo '<li class="adc-gtm-schema-step adc-gtm-schema-step--' . esc_attr($web_status['state']) . '"><span class="adc-gtm-schema-step-num">2</span>'
            . '<span class="adc-gtm-schema-step-title">' . esc_html__('Container web', 'adc-marketing') . '</span>'
            . '<span class="adc-gtm-schema-step-desc">' . esc_html__('codul GTM din site (GTM-…)', 'adc-marketing') . '</span></li>';
        echo '<li class="adc-gtm-schema-step adc-gtm-schema-step--optional adc-gtm-schema-step--' . esc_attr($server_status['state']) . '"><span class="adc-gtm-schema-step-num">3</span>'
            . '<span class="adc-gtm-schema-step-title">' . esc_html__('Container server (opțional)', 'adc-marketing') . '</span>'
            . '<span class="adc-gtm-schema-step-desc">' . esc_html__('etichetele trec prin propriul server', 'adc-marketing') . '</span></li>';
        echo '</ol>';
        echo '<p class="description adc-gtm-schema-note">'
            . esc_html__('Pe scurt: evenimentele pleacă din browserul vizitatorului spre containerul web. Dacă Server GTM este activat, ele trec mai întâi prin containerul server, apoi ajung la GA4, Meta sau TikTok conform setărilor din container.', 'adc-marketing')
            . '</p>';
        echo '</div>';

        ADC_Admin_Help::section(
            __('Cum se leagă cele două carduri', 'adc-marketing'),
            __('Containerul web se încarcă în site; containerul server este opțional și primește evenimentele de la cel web.', 'adc-marketing'),
            'adc-help-gtm-schema',
            $this->help_gtm_schema()
        );

        echo '<div class="adc-gtm-cards">';

        // ------------------------------------------------------------------
        // Card 1 — web container (`gtm` group).
        // ------------------------------------------------------------------
        echo '<section class="adc-gtm-card adc-gtm-card--web">';
        echo '<header class="adc-gtm-card-head"><h3>' . esc_html__('Container web', 'adc-marketing') . '</h3>' . self::status_badge($web_status) . '</header>';
        echo '<p class="adc-gtm-card-intro">' . esc_html__('Snippet-ul GTM inserat în paginile site-ului, în browserul vizitatorilor.', 'adc-marketing') . '</p>';

        echo '<div class="adc-help-options">';

        ADC_Admin_Help::row(
            __('Container web activ', 'adc-marketing'),
            '<label><input type="checkbox" id="adc_gtm_web_enabled" name="gtm[web_enabled]" value="1" ' . checked((bool) ($gtm['web_enabled'] ?? false), true, false) . ' /> ' . esc_html__('Activează containerul GTM web (în browserul vizitatorilor)', 'adc-marketing') . '</label>',
            __('Debifat, snippet-ul GTM nu se mai inserează în site, dar ID-ul rămâne salvat. Server GTM este independent și se configurează în cardul de lângă.', 'adc-marketing'),
            'adc_gtm_web_enabled'
        );

        ADC_Admin_Help::row(
            __('ID container GTM', 'adc-marketing'),
            '<input type="text" id="adc_gtm_container_id" name="gtm[container_id]" class="regular-text code" placeholder="GTM-XXXXXXX" value="' . esc_attr((string) ($gtm['container_id'] ?? '')) . '" />',
            __('Cu ID-ul salvat aici se inserează snippet-ul GTM în site; lăsat gol, containerul nu se încarcă deloc.', 'adc-marketing'),
            'adc_gtm_container_id',
            'adc-help-gtm-mode',
            $this->help_gtm_mode()
        );

        ADC_Admin_Help::row(
            __('Mod încărcare', 'adc-marketing'),
            '<select name="gtm[mode]">'
                . '<option value="always_with_defaults" ' . selected(($gtm['mode'] ?? '') === 'always_with_defaults', true, false) . '>' . esc_html__('Întotdeauna (cu seturi implicite de consimțământ)', 'adc-marketing') . '</option>'
                . '<option value="after_consent" ' . selected(($gtm['mode'] ?? '') === 'after_consent', true, false) . '>' . esc_html__('Doar după acordarea consimțământului', 'adc-marketing') . '</option>'
            . '</select>',
            __('Pentru blocare înainte de acord, alegeți „Doar după acordarea consimțământului”. Modul „Întotdeauna” încarcă GTM imediat; Consent Mode nu garantează blocarea tuturor etichetelor din container.', 'adc-marketing'),
            null,
            'adc-help-gtm-load-mode',
            $this->help_gtm_mode()
        );

        ADC_Admin_Help::row(
            __('Nume dataLayer', 'adc-marketing'),
            '<input type="text" id="adc_gtm_datalayer" name="gtm[datalayer_name]" class="regular-text" value="' . esc_attr((string) ($gtm['datalayer_name'] ?? 'dataLayer')) . '" />',
            __('Numele variabilei JS în care pluginul scrie evenimentele; schimbă-l doar dacă containerul dumneavoastră folosește alt nume.', 'adc-marketing'),
            'adc_gtm_datalayer'
        );

        ADC_Admin_Help::row(
            __('Mediu: auth', 'adc-marketing'),
            '<input type="text" id="adc_gtm_env_auth" name="gtm[env_auth]" class="regular-text" value="' . esc_attr((string) ($gtm['env_auth'] ?? '')) . '" />',
            __('Token-ul de acces al unui mediu GTM (Auth Preview), pentru testarea unei versiuni nepublicate a containerului.', 'adc-marketing'),
            'adc_gtm_env_auth'
        );

        ADC_Admin_Help::row(
            __('Mediu: preview', 'adc-marketing'),
            '<input type="text" id="adc_gtm_env_preview" name="gtm[env_preview]" class="regular-text" value="' . esc_attr((string) ($gtm['env_preview'] ?? '')) . '" />',
            __('Eticheta mediului de previzualizare (ex. env-123); ambele valori trebuie completate pentru a activa previzualizarea.', 'adc-marketing'),
            'adc_gtm_env_preview'
        );

        echo '</div>';
        echo '</section>';

        // ------------------------------------------------------------------
        // Card 2 — server container (`sgtm` group, optional).
        // ------------------------------------------------------------------
        echo '<section class="adc-gtm-card adc-gtm-card--server">';
        echo '<header class="adc-gtm-card-head"><h3>' . esc_html__('Server GTM (opțional)', 'adc-marketing') . '</h3>' . self::status_badge($server_status) . '</header>';
        echo '<p class="adc-gtm-card-intro">'
            . esc_html__('„Transport first-party” înseamnă că etichetele de măsurare se încarcă de pe domeniul dumneavoastră, nu de pe serverele Google — cookie-urile rămân ale site-ului și nu sunt blocate de browsere.', 'adc-marketing')
            . '</p>';

        echo '<div class="adc-help-options">';

        ADC_Admin_Help::row(
            __('Activare', 'adc-marketing'),
            '<label><input type="checkbox" id="adc_sgtm_enabled" name="sgtm[enabled]" value="1" ' . checked((bool) ($sgtm['enabled'] ?? false), true, false) . ' /> ' . esc_html__('Activează Server GTM (transport first-party)', 'adc-marketing') . '</label>',
            __('Pornește transportul; rămâne inactiv până salvați un URL valid de container server.', 'adc-marketing'),
            'adc_sgtm_enabled',
            'adc-help-sgtm',
            $this->help_sgtm()
        );

        $url_control = '<input type="url" id="adc-sgtm-url" name="sgtm[server_container_url]" value="' . esc_attr((string) $sgtm['server_container_url']) . '" class="regular-text code" placeholder="https://analytics.exemplu.ro" autocomplete="off" spellcheck="false" />';
        if ($provider_hint) {
            $url_control .= '<p class="description" style="color:#e2401c;">' . esc_html__('Atenție: folosiți un domeniu oferit de un provider implicit (ex. *.run.app) — în acest caz cookie-urile setate de container nu au beneficiu first-party (rămân cookie-uri JavaScript). Pentru avantaj complet folosiți un domeniu propriu.', 'adc-marketing') . '</p>';
        }
        ADC_Admin_Help::row(
            __('URL container server', 'adc-marketing'),
            $url_control,
            __('Adresa https a containerului server, pe domeniul propriu (ex. https://analytics.exemplu.ro); o valoare greșită oprește silențios colectarea.', 'adc-marketing'),
            'adc-sgtm-url'
        );

        ADC_Admin_Help::row(
            __('Mod loader / MP', 'adc-marketing'),
            '<select id="adc-sgtm-mode" name="sgtm[mode]">'
                . '<option value="loader" ' . selected('loader', (string) ($sgtm['mode'] ?? 'loader'), false) . '>' . esc_html__('Transport (recomandat) — gtag/GTM își trimit evenimentele prin containerul server', 'adc-marketing') . '</option>'
                . '<option value="mp_forward" ' . selected('mp_forward', (string) ($sgtm['mode'] ?? 'loader'), false) . '>' . esc_html__('Experimental: forward MP din server către container', 'adc-marketing') . '</option>'
            . '</select>',
            __('„Transport” schimbă doar încărcarea etichetei pe domeniul propriu; „forward MP” (experimental) redirecționează și trimiterile GA4 de pe server.', 'adc-marketing'),
            'adc-sgtm-mode',
            'adc-help-sgtm-mode',
            $this->help_sgtm()
        );

        ADC_Admin_Help::row(
            __('Measurement ID (opțional)', 'adc-marketing'),
            '<input type="text" id="adc-sgtm-measurement-id" name="sgtm[measurement_id]" value="' . esc_attr((string) ($sgtm['measurement_id'] ?? '')) . '" class="regular-text" maxlength="20" autocomplete="off" spellcheck="false" />',
            __('Suprascrie Measurement ID-ul din tabul GA4, doar în modul experimental „forward MP”.', 'adc-marketing'),
            'adc-sgtm-measurement-id'
        );

        $secret_desc = $has_secret
            ? __('••••• (salvat) — lasă câmpul gol pentru a păstra cheia curentă.', 'adc-marketing')
            : __('Necesară doar în modul experimental, dacă containerul o cere; se stochează criptat.', 'adc-marketing');
        ADC_Admin_Help::row(
            __('Cheie API (opțional)', 'adc-marketing'),
            '<input type="password" id="adc-sgtm-api-secret" name="sgtm[api_secret]" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />',
            $secret_desc,
            'adc-sgtm-api-secret'
        );

        ADC_Admin_Help::row(
            __('Testare conexiune', 'adc-marketing'),
            '<p><button type="button" class="button" id="adc-sgtm-test">' . esc_html__('Testează conexiunea', 'adc-marketing') . '</button> <span class="adc-test-result" id="adc-sgtm-test-result" aria-live="polite"></span></p>',
            __('În modul „Transport” confirmă configurarea URL-ului; în modul experimental trimite un eveniment sintetic în container.', 'adc-marketing')
        );

        echo '</div>';

        echo '<p class="description adc-gtm-note">' . esc_html__('În containerul GTM (Server), clientul GA4 este preinstalat — nu sunt necesare modificări de cod în container pentru ca evenimentele gtag să ajungă la el.', 'adc-marketing') . '</p>';
        echo '</section>';

        echo '</div>';
    }

    /**
     * Save both groups through ADC_Settings::update (typed sanitizers run
     * inside; unknown/invalid values fall back to stored/default).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        // --- Card 1: web container (`gtm` group). ---
        $keys     = array('container_id', 'mode', 'datalayer_name', 'env_auth', 'env_preview');
        $defaults = array(
            'container_id'   => '',
            'mode'           => ADC_Gtm::MODE_AFTER,
            'datalayer_name' => 'dataLayer',
            'env_auth'       => '',
            'env_preview'    => '',
        );

        $raw       = isset($post['gtm']) && is_array($post['gtm']) ? $post['gtm'] : array();
        $gtm_input = array();
        foreach ($keys as $key) {
            $gtm_input[$key] = isset($raw[$key]) && is_scalar($raw[$key]) ? (string) $raw[$key] : $defaults[$key];
        }
        $gtm_input['web_enabled'] = !empty($raw['web_enabled']) ? '1' : '0';

        $container = strtoupper(trim($gtm_input['container_id']));

        ADC_Settings::update('gtm', $gtm_input);

        // --- Card 2: server container (`sgtm` group, single source, D20). ---
        $sraw = isset($post['sgtm']) && is_array($post['sgtm']) ? $post['sgtm'] : array();
        $sgtm_input = array(
            'enabled'              => !empty($sraw['enabled']),
            'server_container_url' => isset($sraw['server_container_url']) && is_scalar($sraw['server_container_url']) ? (string) $sraw['server_container_url'] : '',
            'mode'                 => isset($sraw['mode']) && is_scalar($sraw['mode']) ? (string) $sraw['mode'] : '',
            'measurement_id'       => isset($sraw['measurement_id']) && is_scalar($sraw['measurement_id']) ? (string) $sraw['measurement_id'] : '',
            'api_secret'           => isset($sraw['api_secret']) && is_scalar($sraw['api_secret']) ? (string) $sraw['api_secret'] : '',
        );

        $stored = ADC_Settings::update('sgtm', $sgtm_input);

        // Single source of truth: drop the legacy copy from the gtm group.
        ADC_Settings::update('gtm', array('server_container_url' => ''));

        $notices = array(
            array(
                'type'    => 'success',
                'message' => __('Setările Google Tag Manager au fost salvate.', 'adc-marketing'),
            ),
        );

        if ('' !== $container && 1 !== preg_match(self::GTM_PATTERN, $container)) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('ID-ul containerului GTM nu este valid (format corect: GTM-XXXXXXX). Valoarea veche a fost păstrată.', 'adc-marketing'),
            );
        }

        $url = (string) ($stored['server_container_url'] ?? '');
        if ('' !== trim($sgtm_input['server_container_url']) && '' === $url) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('URL invalid — se acceptă doar adrese https valide.', 'adc-marketing'),
            );
        }

        if (!empty($stored['enabled']) && '' === $url) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Fără URL de container server, Server GTM rămâne inactiv.', 'adc-marketing'),
            );
        }

        if ('mp_forward' === (string) ($stored['mode'] ?? '')) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Modul experimental „forward MP” este activ: funcție neverificată oficial de documentația Google — testați în DebugView înainte de producție.', 'adc-marketing'),
            );
        }

        return $notices;
    }
}
