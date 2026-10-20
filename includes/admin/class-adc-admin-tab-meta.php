<?php
/**
 * Admin tab "Integrări ▸ Meta" (docs/ARCHITECTURE.md §2.1, §4; §9 D2, D3, D11).
 *
 * Settings for the `meta` group (registered by ADC_Integration_Meta). The
 * access token is sealed via ADC_Secrets and never echoed back: the field is
 * always empty and shows "••••• (salvat)" next to it while a token exists.
 * The "Test connection" button calls the shared admin-AJAX endpoint
 * `adc_test_integration` (nonce `adc_admin`, cap `manage_options`, owned by
 * the Logs tab / C5).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta integration admin tab.
 */
final class ADC_Admin_Tab_Meta implements ADC_Admin_Tab_Interface, ADC_Module_Interface
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
                'platform' => 'meta',
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

        wp_add_inline_script('adc-admin-shell', 'window.adcIntegrationTest = ' . $config . ';', 'before');
        wp_add_inline_script(
            'adc-admin-shell',
            '(function(){var b=document.getElementById("adc-meta-test"),o=document.getElementById("adc-meta-test-result");if(!b||!o||!window.adcIntegrationTest){return}var c=window.adcIntegrationTest,i=c.i18n;b.addEventListener("click",function(){b.disabled=true;o.textContent=i.testing;o.className="adc-test-result";var f=new FormData();f.append("action","adc_test_integration");f.append("platform",c.platform);f.append("nonce",c.nonce);fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json()}).then(function(j){var d=j&&j.data?j.data:{},ok=!!(j&&j.success&&d.status==="sent");o.textContent=(ok?i.ok:i.failed)+(d.message?" — "+d.message:"");o.className="adc-test-result "+(ok?"adc-test-ok":"adc-test-fail")}).catch(function(){o.textContent=i.error;o.className="adc-test-result adc-test-fail"}).finally(function(){b.disabled=false})});})();'
        );
    }

    /**
     * Tab slug.
     */
    public function slug(): string
    {
        return 'meta';
    }

    /**
     * Tab label (RO).
     */
    public function label(): string
    {
        return __('Meta', 'adc-marketing');
    }

    /**
     * Rendered inside the "integrations" sub-nav.
     */
    public function group(): string
    {
        return 'integrations';
    }

    /**
     * Sort order (§2.1: meta 30).
     */
    public function order(): int
    {
        return 30;
    }

    /**
     * Render the tab inner HTML (no form tag, no nonce — the shell wraps them).
     */
    public function render(): void
    {
        $s        = ADC_Settings::all('meta');
        $has_token = '' !== ADC_Settings::secret('meta', 'access_token');

        ADC_Admin_Help::section(
            __('Meta — Conversions API & Pixel', 'adc-marketing'),
            __('Ce face această secțiune: conectează magazinul la Meta (Facebook/Instagram) pentru ca evenimentele — produse, coș, comenzi — să ajungă la reclame și publicuri.', 'adc-marketing'),
            'adc-help-meta-data',
            $this->help_meta_data()
        );

        echo '<div class="adc-help-options adc-settings-section">';

        ADC_Admin_Help::row(
            __('Trimitere server (CAPI)', 'adc-marketing'),
            '<label><input type="checkbox" name="enabled_server" value="1" ' . checked(!empty($s['enabled_server']), false, false) . ' /> ' . esc_html__('Activează trimiterea evenimentelor server-side către Conversions API', 'adc-marketing') . '</label>',
            __('Pornește expedierea de pe server; evenimentele pleacă doar pentru vizitatorii cu consimțământ de marketing bifat.', 'adc-marketing'),
            null,
            'adc-help-meta-data-2',
            $this->help_meta_data()
        );

        $browser_mode_options = array(
            'off'    => __('Dezactivat — fără pixel în browser', 'adc-marketing'),
            'gtm'    => __('Gestionat prin Google Tag Manager (recomandat)', 'adc-marketing'),
            'direct' => __('Încărcare directă — doar după consimțământul de marketing', 'adc-marketing'),
        );
        $browser_control = '<select id="adc-meta-browser-mode" name="browser_mode">';
        foreach ($browser_mode_options as $value => $caption) {
            $browser_control .= '<option value="' . esc_attr($value) . '" ' . selected($value, (string) $s['browser_mode'], false) . '>' . esc_html($caption) . '</option>';
        }
        $browser_control .= '</select>';
        ADC_Admin_Help::row(
            __('Mod pixel în browser', 'adc-marketing'),
            $browser_control,
            __('Cum se încarcă pixelul Meta în pagini: deloc, prin GTM (îl gestionați din container) sau direct de plugin, după consimțământ.', 'adc-marketing'),
            'adc-meta-browser-mode'
        );

        ADC_Admin_Help::row(
            __('Pixel / Dataset ID', 'adc-marketing'),
            '<input type="text" id="adc-meta-pixel-id" name="pixel_id" value="' . esc_attr((string) $s['pixel_id']) . '" class="regular-text" maxlength="30" autocomplete="off" />',
            __('Identificatorul contului țintă din Events Manager; evenimentele server-side se atașează de acest ID.', 'adc-marketing'),
            'adc-meta-pixel-id'
        );

        $token_desc = $has_token
            ? __('••••• (salvat) — lasă câmpul gol pentru a păstra tokenul curent.', 'adc-marketing')
            : __('Tokenul din Events Manager; se stochează criptat și nu se mai afișează după salvare.', 'adc-marketing');
        ADC_Admin_Help::row(
            __('Token de acces', 'adc-marketing'),
            '<input type="password" id="adc-meta-access-token" name="access_token" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />',
            $token_desc,
            'adc-meta-access-token'
        );

        ADC_Admin_Help::row(
            __('Versiune API Graph', 'adc-marketing'),
            '<input type="text" id="adc-meta-api-version" name="api_version" value="' . esc_attr((string) $s['api_version']) . '" class="small-text" maxlength="10" />',
            __('Versiunea API Meta folosită la trimitere (ex. v26.0); schimbați doar la cererea platformei.', 'adc-marketing'),
            'adc-meta-api-version'
        );

        ADC_Admin_Help::row(
            __('Cod eveniment test', 'adc-marketing'),
            '<input type="text" id="adc-meta-test-code" name="test_event_code" value="' . esc_attr((string) $s['test_event_code']) . '" class="regular-text" maxlength="40" autocomplete="off" />',
            '' !== (string) $s['test_event_code']
                ? __('Atenție: cu un cod de test activ, evenimentele nu mai ajung în rapoartele reale — ștergeți codul în producție.', 'adc-marketing')
                : __('Gol = evenimente reale. Completat, toate evenimentele se directionează doar în Test Events.', 'adc-marketing'),
            'adc-meta-test-code',
            'adc-help-meta-test-code',
            '<p>' . esc_html__('Codul de eveniment test (TEST123...) se generează în Meta Events Manager → Test Events. Cât timp este salvat aici, NICIUN eveniment nu mai este raportat ca producție — toate merg în fereastra de test, inclusiv comenzile reale.', 'adc-marketing') . '</p><p>' . esc_html__('Folosiți-l temporar pentru verificare, apoi ștergeți câmpul și salvați. Pluginul afișează un avertisment la fiecare salvare cât timp codul este activ.', 'adc-marketing') . '</p>'
        );

        ADC_Admin_Help::row(
            __('Testare conexiune', 'adc-marketing'),
            '<p><button type="button" class="button" id="adc-meta-test">' . esc_html__('Testează conexiunea', 'adc-marketing') . '</button> <span class="adc-test-result" id="adc-meta-test-result" aria-live="polite"></span></p>',
            __('Trimite un eveniment sintetic către Test Events; necesită Pixel/Dataset ID, token și cod de eveniment test salvate.', 'adc-marketing')
        );

        echo '</div>';

        echo '<p class="description adc-section-intro">' . esc_html__('Notă: Limited Data Use (data_processing_options) este un mecanism dedicat legilor americane privind confidențialitatea și nu este folosit; pentru UE/România poarta corectă este consimțământul — fără consimțământ, evenimentul nu se trimite deloc.', 'adc-marketing') . '</p>';
    }

    /**
     * Save via ADC_Settings::update (sanitizer in ADC_Integration_Meta).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        $input = array(
            'enabled_server'  => !empty($post['enabled_server']),
            'browser_mode'    => (string) ($post['browser_mode'] ?? ''),
            'pixel_id'        => (string) ($post['pixel_id'] ?? ''),
            'access_token'    => (string) ($post['access_token'] ?? ''),
            'test_event_code' => (string) ($post['test_event_code'] ?? ''),
            'api_version'     => (string) ($post['api_version'] ?? ''),
        );

        ADC_Settings::update('meta', $input);

        $notices = array(
            array(
                'type'    => 'success',
                'message' => __('Setările Meta au fost salvate.', 'adc-marketing'),
            ),
        );

        if ('' !== (string) ($input['test_event_code'] ?? '')) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Codul de eveniment test este activ — evenimentele Meta nu mai sunt ignorate. Șterge-l înainte de producție.', 'adc-marketing'),
            );
        }

        return $notices;
    }
}
