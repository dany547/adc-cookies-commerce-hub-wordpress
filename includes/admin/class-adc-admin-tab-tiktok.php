<?php
/**
 * Admin tab "Integrări ▸ TikTok" (docs/ARCHITECTURE.md §2.1, §4; §9 D10, D15).
 *
 * Settings for the `tiktok` group (registered by ADC_Integration_Tiktok).
 * Same secret/nonce conventions as the Meta tab; the "Test connection"
 * button calls the shared admin-AJAX endpoint `adc_test_integration`.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TikTok integration admin tab.
 */
final class ADC_Admin_Tab_Tiktok implements ADC_Admin_Tab_Interface, ADC_Module_Interface
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
                'platform' => 'tiktok',
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
            '(function(){var b=document.getElementById("adc-tiktok-test"),o=document.getElementById("adc-tiktok-test-result");if(!b||!o||!window.adcIntegrationTest){return}var c=window.adcIntegrationTest,i=c.i18n;b.addEventListener("click",function(){b.disabled=true;o.textContent=i.testing;o.className="adc-test-result";var f=new FormData();f.append("action","adc_test_integration");f.append("platform",c.platform);f.append("nonce",c.nonce);fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json()}).then(function(j){var d=j&&j.data?j.data:{},ok=!!(j&&j.success&&d.status==="sent");o.textContent=(ok?i.ok:i.failed)+(d.message?" — "+d.message:"");o.className="adc-test-result "+(ok?"adc-test-ok":"adc-test-fail")}).catch(function(){o.textContent=i.error;o.className="adc-test-result adc-test-fail"}).finally(function(){b.disabled=false})});})();'
        );
    }

    /**
     * Tab slug.
     */
    public function slug(): string
    {
        return 'tiktok';
    }

    /**
     * Tab label (RO).
     */
    public function label(): string
    {
        return __('TikTok', 'adc-marketing');
    }

    /**
     * Rendered inside the "integrations" sub-nav.
     */
    public function group(): string
    {
        return 'integrations';
    }

    /**
     * Sort order (§2.1: tiktok 60).
     */
    public function order(): int
    {
        return 60;
    }

    /**
     * Render the tab inner HTML (no form tag, no nonce — the shell wraps them).
     */
    public function render(): void
    {
        $s         = ADC_Settings::all('tiktok');
        $has_token = '' !== ADC_Settings::secret('tiktok', 'access_token');

        ADC_Admin_Help::section(
            __('TikTok — Events API & Pixel', 'adc-marketing'),
            __('Ce face această secțiune: trimite evenimentele de magazin către TikTok pentru optimizarea reclamelor și crearea de publicuri.', 'adc-marketing'),
            'adc-help-tiktok-data',
            $this->help_tiktok_data()
        );

        echo '<div class="adc-help-options adc-settings-section">';

        ADC_Admin_Help::row(
            __('Trimitere server (Events API)', 'adc-marketing'),
            '<label><input type="checkbox" name="enabled_server" value="1" ' . checked(!empty($s['enabled_server']), false, false) . ' /> ' . esc_html__('Activează trimiterea evenimentelor server-side către TikTok Events API', 'adc-marketing') . '</label>',
            __('Pornește expedierea de pe server; evenimentele pleacă doar pentru vizitatorii cu consimțământ de marketing bifat.', 'adc-marketing'),
            null,
            'adc-help-tiktok-data-2',
            $this->help_tiktok_data()
        );

        $browser_mode_options = array(
            'off'    => __('Dezactivat — fără pixel în browser', 'adc-marketing'),
            'gtm'    => __('Gestionat prin Google Tag Manager (recomandat)', 'adc-marketing'),
            'direct' => __('Încărcare directă — doar după consimțământul de marketing', 'adc-marketing'),
        );
        $browser_control = '<select id="adc-tiktok-browser-mode" name="browser_mode">';
        foreach ($browser_mode_options as $value => $caption) {
            $browser_control .= '<option value="' . esc_attr($value) . '" ' . selected($value, (string) $s['browser_mode'], false) . '>' . esc_html($caption) . '</option>';
        }
        $browser_control .= '</select>';
        ADC_Admin_Help::row(
            __('Mod pixel în browser', 'adc-marketing'),
            $browser_control,
            __('Cum se încarcă pixelul TikTok în pagini: deloc, prin GTM (îl gestionați din container) sau direct de plugin, după consimțământ.', 'adc-marketing'),
            'adc-tiktok-browser-mode'
        );

        ADC_Admin_Help::row(
            __('Cod pixel', 'adc-marketing'),
            '<input type="text" id="adc-tiktok-pixel-code" name="pixel_code" value="' . esc_attr((string) $s['pixel_code']) . '" class="regular-text" maxlength="40" autocomplete="off" />',
            __('Pixel Code din TikTok Events Manager; identifică contul în care ajung evenimentele.', 'adc-marketing'),
            'adc-tiktok-pixel-code'
        );

        $token_desc = $has_token
            ? __('••••• (salvat) — lasă câmpul gol pentru a păstra tokenul curent.', 'adc-marketing')
            : __('Access-Token din TikTok Events Manager; se stochează criptat și nu se mai afișează după salvare.', 'adc-marketing');
        ADC_Admin_Help::row(
            __('Token de acces', 'adc-marketing'),
            '<input type="password" id="adc-tiktok-access-token" name="access_token" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />',
            $token_desc,
            'adc-tiktok-access-token'
        );

        ADC_Admin_Help::row(
            __('Cod eveniment test', 'adc-marketing'),
            '<input type="text" id="adc-tiktok-test-code" name="test_event_code" value="' . esc_attr((string) $s['test_event_code']) . '" class="regular-text" maxlength="40" autocomplete="off" />',
            '' !== (string) $s['test_event_code']
                ? __('Atenție: cu un cod de test activ, evenimentele ajung în Test Events, nu în rapoartele reale — ștergeți codul în producție.', 'adc-marketing')
                : __('Gol = evenimente reale. Completat, evenimentele se directionează în Test Events pentru verificare.', 'adc-marketing'),
            'adc-tiktok-test-code',
            'adc-help-tiktok-test-code',
            '<p>' . esc_html__('Codul de test se generează în TikTok Events Manager → Test Events. Cât timp este salvat aici, evenimentele — inclusiv comenzile reale — apar doar în fereastra de test, nu în rapoartele campaniilor.', 'adc-marketing') . '</p><p>' . esc_html__('Folosiți-l temporar pentru verificare, apoi ștergeți câmpul și salvați; pluginul avertizează la fiecare salvare cât timp codul este activ.', 'adc-marketing') . '</p>'
        );

        ADC_Admin_Help::row(
            __('Testare conexiune', 'adc-marketing'),
            '<p><button type="button" class="button" id="adc-tiktok-test">' . esc_html__('Testează conexiunea', 'adc-marketing') . '</button> <span class="adc-test-result" id="adc-tiktok-test-result" aria-live="polite"></span></p>',
            __('Trimite un eveniment sintetic către Test Events; necesită cod pixel, token și cod de eveniment test salvate.', 'adc-marketing')
        );

        echo '</div>';
    }

    /**
     * Save via ADC_Settings::update (sanitizer in ADC_Integration_Tiktok).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        $input = array(
            'enabled_server'  => !empty($post['enabled_server']),
            'browser_mode'    => (string) ($post['browser_mode'] ?? ''),
            'pixel_code'      => (string) ($post['pixel_code'] ?? ''),
            'access_token'    => (string) ($post['access_token'] ?? ''),
            'test_event_code' => (string) ($post['test_event_code'] ?? ''),
        );

        ADC_Settings::update('tiktok', $input);

        $notices = array(
            array(
                'type'    => 'success',
                'message' => __('Setările TikTok au fost salvate.', 'adc-marketing'),
            ),
        );

        if ('' !== (string) ($input['test_event_code'] ?? '')) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Codul de eveniment test este activ — evenimentele TikTok ajung în Test Events. Șterge-l înainte de producție.', 'adc-marketing'),
            );
        }

        return $notices;
    }
}
