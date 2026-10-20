<?php
/**
 * Admin tab "Integrări ▸ Server GTM" (docs/ARCHITECTURE.md §2.1, §4; §9 D20).
 *
 * Settings for the `sgtm` group (registered by ADC_Integration_Sgtm).
 * The feature is inert unless a valid https server container URL is stored.
 * `mode=loader` (default) only switches the GTM/gtag transport to the
 * first-party URL (ADC_Gtm::loader_url()); `mode=mp_forward` is EXPERIMENTAL
 * (not verified in PLATFORMS) and hidden behind an explicit warning. The
 * "Test connection" button calls the shared admin-AJAX endpoint
 * `adc_test_integration` (nonce `adc_admin`, cap `manage_options`).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server GTM integration admin tab.
 */
final class ADC_Admin_Tab_Sgtm implements ADC_Admin_Tab_Interface, ADC_Module_Interface
{
    use ADC_Admin_Help_Content;

    /**
     * Whether boot() already registered the admin hooks.
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Boot the module: enqueue the inline test-connection wiring on the
     * plugin admin page.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'), 20);
    }

    /**
     * Attach the test-button config + script to the shell handle (enqueued
     * by ADC_Admin_Shell on this page only).
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('toplevel_page_' . ADC_Admin_Shell::PAGE_SLUG !== $hook_suffix
            || !current_user_can('manage_options')
            || !wp_script_is('adc-admin-shell', 'registered')
        ) {
            return;
        }

        $config = wp_json_encode(
            array(
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('adc_admin'),
                'platform' => 'sgtm',
                'i18n'     => array(
                    'testing' => __('Se testează conexiunea…', 'adc-marketing'),
                    'ok'      => __('Conexiune reușită', 'adc-marketing'),
                    'failed'  => __('Conexiunea a eșuat', 'adc-marketing'),
                    'error'   => __('Eroare de rețea — încearcă din nou.', 'adc-marketing'),
                ),
            ),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        if (!is_string($config) || '' === $config) {
            return;
        }

        wp_add_inline_script('adc-admin-shell', 'window.adcSgtmTest = ' . $config . ';', 'before');
        wp_add_inline_script(
            'adc-admin-shell',
            '(function(){var b=document.getElementById("adc-sgtm-test"),o=document.getElementById("adc-sgtm-test-result");if(!b||!o||!window.adcSgtmTest){return}var c=window.adcSgtmTest,i=c.i18n;b.addEventListener("click",function(){b.disabled=true;o.textContent=i.testing;o.className="adc-test-result";var f=new FormData();f.append("action","adc_test_integration");f.append("platform",c.platform);f.append("nonce",c.nonce);fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json()}).then(function(j){var d=j&&j.data?j.data:{},ok=!!(j&&j.success&&d.status==="sent");o.textContent=(ok?i.ok:i.failed)+(d.message?" — "+d.message:"");o.className="adc-test-result "+(ok?"adc-test-ok":"adc-test-fail")}).catch(function(){o.textContent=i.error;o.className="adc-test-result adc-test-fail"}).finally(function(){b.disabled=false})});})();'
        );
    }

    /**
     * Tab slug.
     */
    public function slug(): string
    {
        return 'sgtm';
    }

    /**
     * Tab label (RO).
     */
    public function label(): string
    {
        return __('Server GTM', 'adc-marketing');
    }

    /**
     * Rendered inside the "integrations" sub-nav.
     */
    public function group(): string
    {
        return 'integrations';
    }

    /**
     * Sort order (§2.1: sgtm 50).
     */
    public function order(): int
    {
        return 50;
    }

    /**
     * Render the tab inner HTML (no form tag, no nonce — the shell wraps them).
     */
    public function render(): void
    {
        $s          = ADC_Settings::all('sgtm');
        $has_secret = '' !== ADC_Settings::secret('sgtm', 'api_secret');
        $url        = ADC_Integration_Sgtm::server_container_url();
        $provider_hint = '' !== $url && 1 === preg_match('/\.run\.app$/i', (string) parse_url($url, PHP_URL_HOST));

        ADC_Admin_Help::section(
            __('Server GTM — transport first-party', 'adc-marketing'),
            __('Ce face această secțiune: face ca etichetele de măsurare să fie încărcate de pe domeniul dumneavoastră, nu de pe domeniile Google.', 'adc-marketing'),
            'adc-help-sgtm',
            $this->help_sgtm()
        );

        echo '<div class="adc-help-options adc-settings-section">';

        ADC_Admin_Help::row(
            __('Activare', 'adc-marketing'),
            '<label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($s['enabled']), false, false) . ' /> ' . esc_html__('Activează Server GTM (transport first-party)', 'adc-marketing') . '</label>',
            __('Pornește transportul; rămâne inactiv până salvați un URL valid de container server.', 'adc-marketing'),
            null,
            'adc-help-sgtm-2',
            $this->help_sgtm()
        );

        $url_control = '<input type="url" id="adc-sgtm-url" name="server_container_url" value="' . esc_attr((string) $s['server_container_url']) . '" class="regular-text code" placeholder="https://analytics.exemplu.ro" autocomplete="off" spellcheck="false" />';
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
            __('Mod', 'adc-marketing'),
            '<select id="adc-sgtm-mode" name="mode">'
                . '<option value="loader" ' . selected('loader', (string) $s['mode'], false) . '>' . esc_html__('Transport (recomandat) — gtag/GTM își trimit evenimentele prin containerul server', 'adc-marketing') . '</option>'
                . '<option value="mp_forward" ' . selected('mp_forward', (string) $s['mode'], false) . '>' . esc_html__('Experimental: forward MP din server către container', 'adc-marketing') . '</option>'
            . '</select>',
            __('„Transport” schimbă doar încărcarea etichetei pe domeniul propriu; „forward MP” (experimental) redirecționează și trimiterile GA4 de pe server.', 'adc-marketing'),
            'adc-sgtm-mode',
            'adc-help-sgtm-mode',
            $this->help_sgtm()
        );

        ADC_Admin_Help::row(
            __('Measurement ID (opțional)', 'adc-marketing'),
            '<input type="text" id="adc-sgtm-measurement-id" name="measurement_id" value="' . esc_attr((string) $s['measurement_id']) . '" class="regular-text" maxlength="20" autocomplete="off" spellcheck="false" />',
            __('Suprascrie Measurement ID-ul din tabul GA4, doar în modul experimental „forward MP”.', 'adc-marketing'),
            'adc-sgtm-measurement-id'
        );

        $secret_desc = $has_secret
            ? __('••••• (salvat) — lasă câmpul gol pentru a păstra cheia curentă.', 'adc-marketing')
            : __('Necesară doar în modul experimental, dacă containerul o cere; se stochează criptat.', 'adc-marketing');
        ADC_Admin_Help::row(
            __('Cheie API (opțional)', 'adc-marketing'),
            '<input type="password" id="adc-sgtm-api-secret" name="api_secret" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />',
            $secret_desc,
            'adc-sgtm-api-secret'
        );

        ADC_Admin_Help::row(
            __('Testare conexiune', 'adc-marketing'),
            '<p><button type="button" class="button" id="adc-sgtm-test">' . esc_html__('Testează conexiunea', 'adc-marketing') . '</button> <span class="adc-test-result" id="adc-sgtm-test-result" aria-live="polite"></span></p>',
            __('În modul „Transport” confirmă configurarea URL-ului; în modul experimental trimite un eveniment sintetic în container.', 'adc-marketing')
        );

        echo '</div>';

        echo '<p class="description adc-section-intro">' . esc_html__('În containerul GTM (Server), clientul GA4 este preinstalat — nu sunt necesare modificări de cod în container pentru ca evenimentele gtag să ajungă la el.', 'adc-marketing') . '</p>';
    }

    /**
     * Save via ADC_Settings::update (sanitizer in ADC_Integration_Sgtm).
     * The integration falls back to the `gtm` group URL (ADC_Gtm) when this
     * group stores none, so the two never diverge at send time (D20).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        $input = array(
            'enabled'              => !empty($post['enabled']),
            'server_container_url' => (string) ($post['server_container_url'] ?? ''),
            'mode'                 => (string) ($post['mode'] ?? ''),
            'measurement_id'       => (string) ($post['measurement_id'] ?? ''),
            'api_secret'           => (string) ($post['api_secret'] ?? ''),
        );

        $stored = ADC_Settings::update('sgtm', $input);

        $notices = array(
            array(
                'type'    => 'success',
                'message' => __('Setările Server GTM au fost salvate.', 'adc-marketing'),
            ),
        );

        $url = (string) ($stored['server_container_url'] ?? '');
        if ('' !== trim((string) ($input['server_container_url'] ?? '')) && '' === $url) {
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
