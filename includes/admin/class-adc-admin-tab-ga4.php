<?php
/**
 * Admin tab "Integrări ▸ GA4" (docs/ARCHITECTURE.md §2.1, §4; §9 D6, D7, D8).
 *
 * Settings for the `ga4` group (registered by ADC_Integration_Ga4). The
 * api_secret is sealed via ADC_Secrets and never echoed back: the field is
 * always empty and shows "••••• (salvat)" while a secret exists. The
 * "Test connection" button calls the shared admin-AJAX endpoint
 * `adc_test_integration` (nonce `adc_admin`, cap `manage_options`, owned by
 * the Logs tab / C5) and GA4 always validates through the debug endpoint.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GA4 integration admin tab.
 */
final class ADC_Admin_Tab_Ga4 implements ADC_Admin_Tab_Interface, ADC_Module_Interface
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
                'platform' => 'ga4',
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

        wp_add_inline_script('adc-admin-shell', 'window.adcGa4Test = ' . $config . ';', 'before');
        wp_add_inline_script(
            'adc-admin-shell',
            '(function(){var b=document.getElementById("adc-ga4-test"),o=document.getElementById("adc-ga4-test-result");if(!b||!o||!window.adcGa4Test){return}var c=window.adcGa4Test,i=c.i18n;b.addEventListener("click",function(){b.disabled=true;o.textContent=i.testing;o.className="adc-test-result";var f=new FormData();f.append("action","adc_test_integration");f.append("platform",c.platform);f.append("nonce",c.nonce);fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json()}).then(function(j){var d=j&&j.data?j.data:{},ok=!!(j&&j.success&&d.status==="sent");o.textContent=(ok?i.ok:i.failed)+(d.message?" — "+d.message:"");o.className="adc-test-result "+(ok?"adc-test-ok":"adc-test-fail")}).catch(function(){o.textContent=i.error;o.className="adc-test-result adc-test-fail"}).finally(function(){b.disabled=false})});})();'
        );
    }

    /**
     * Tab slug.
     */
    public function slug(): string
    {
        return 'ga4';
    }

    /**
     * Tab label (RO).
     */
    public function label(): string
    {
        return __('GA4', 'adc-marketing');
    }

    /**
     * Rendered inside the "integrations" sub-nav.
     */
    public function group(): string
    {
        return 'integrations';
    }

    /**
     * Sort order (§2.1: ga4 40).
     */
    public function order(): int
    {
        return 40;
    }

    /**
     * Render the tab inner HTML (no form tag, no nonce — the shell wraps them).
     */
    public function render(): void
    {
        $s         = ADC_Settings::all('ga4');
        $has_secret = '' !== ADC_Settings::secret('ga4', 'api_secret');

        ADC_Admin_Help::section(
            __('GA4 — Measurement Protocol (server-side)', 'adc-marketing'),
            __('Ce face această secțiune: trimite evenimentele direct de pe serverul dumneavoastră către Google Analytics 4, mai fiabil decât o etichetă din browser.', 'adc-marketing'),
            'adc-help-ga4-data',
            $this->help_ga4_data()
        );

        echo '<div class="adc-help-options adc-settings-section">';

        ADC_Admin_Help::row(
            __('Trimitere server (Measurement Protocol)', 'adc-marketing'),
            '<label><input type="checkbox" name="enabled_server" value="1" ' . checked(!empty($s['enabled_server']), false, false) . ' /> ' . esc_html__('Activează trimiterea evenimentelor server-side către GA4', 'adc-marketing') . '</label>',
            __('Pornește/oprește complet trimiterea de pe server către GA4; cu opțiunea oprită, evenimentele rămân doar în browser.', 'adc-marketing')
        );

        $browser_mode_options = array(
            'off'    => __('Dezactivat — fără gtag în browser', 'adc-marketing'),
            'gtm'    => __('Gestionat prin Google Tag Manager (recomandat)', 'adc-marketing'),
            'direct' => __('Încărcare directă gtag — doar după consimțământul de statistici', 'adc-marketing'),
        );
        $browser_control = '<select id="adc-ga4-browser-mode" name="browser_mode">';
        foreach ($browser_mode_options as $value => $caption) {
            $browser_control .= '<option value="' . esc_attr($value) . '" ' . selected($value, (string) $s['browser_mode'], false) . '>' . esc_html($caption) . '</option>';
        }
        $browser_control .= '</select>';
        ADC_Admin_Help::row(
            __('Mod etichetă în browser', 'adc-marketing'),
            $browser_control,
            __('Unde rulează eticheta GA4 din browser: deloc, prin GTM (recomandat) sau încărcată direct de plugin după consimțământ.', 'adc-marketing'),
            'adc-ga4-browser-mode'
        );

        ADC_Admin_Help::row(
            __('Measurement ID', 'adc-marketing'),
            '<input type="text" id="adc-ga4-measurement-id" name="measurement_id" value="' . esc_attr((string) $s['measurement_id']) . '" class="regular-text" maxlength="20" autocomplete="off" spellcheck="false" />',
            __('ID-ul fluxului de date (G-XXXXXXX) în care Google înregistrează evenimentele trimise de server.', 'adc-marketing'),
            'adc-ga4-measurement-id'
        );

        $secret_desc = $has_secret
            ? __('••••• (salvat) — lasă câmpul gol pentru a păstra cheia curentă.', 'adc-marketing')
            : __('Cheia generată în Data Streams → Measurement Protocol → Create; se stochează criptat și nu se mai afișează după salvare.', 'adc-marketing');
        ADC_Admin_Help::row(
            __('Cheie API (Measurement Protocol)', 'adc-marketing'),
            '<input type="password" id="adc-ga4-api-secret" name="api_secret" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />',
            $secret_desc,
            'adc-ga4-api-secret'
        );

        ADC_Admin_Help::row(
            __('Regiune colectare', 'adc-marketing'),
            '<select id="adc-ga4-endpoint-region" name="endpoint_region">'
                . '<option value="eu" ' . selected('eu', (string) $s['endpoint_region'], false) . '>' . esc_html__('Uniunea Europeană (recomandat — region1.google-analytics.com)', 'adc-marketing') . '</option>'
                . '<option value="global" ' . selected('global', (string) $s['endpoint_region'], false) . '>' . esc_html__('Global (www.google-analytics.com)', 'adc-marketing') . '</option>'
            . '</select>',
            __('Ce server Google primește datele; regiunea UE ține procesarea cât mai aproape de legislația europeană.', 'adc-marketing'),
            'adc-ga4-endpoint-region'
        );

        $purchase_desc = '';
        if ('server' === (string) $s['purchase_channel']) {
            $purchase_desc = __('Atenție: cu canalul „Server”, configurați eticheta GA4 din GTM să NU mai trimită purchase, altfel achizițiile se dublează.', 'adc-marketing');
        }
        ADC_Admin_Help::row(
            __('Canal pentru evenimentul «purchase»', 'adc-marketing'),
            '<select id="adc-ga4-purchase-channel" name="purchase_channel">'
                . '<option value="browser" ' . selected('browser', (string) $s['purchase_channel'], false) . '>' . esc_html__('Browser (recomandat — gtag / GTM)', 'adc-marketing') . '</option>'
                . '<option value="server" ' . selected('server', (string) $s['purchase_channel'], false) . '>' . esc_html__('Server (Measurement Protocol)', 'adc-marketing') . '</option>'
            . '</select>',
            '' !== $purchase_desc ? $purchase_desc : __('Din ce parte se raportează achiziția: browser sau server (NU ambele — GA4 nu deduplică). „refund” rămâne mereu server-side.', 'adc-marketing'),
            'adc-ga4-purchase-channel',
            'adc-help-ga4-purchase-channel',
            '<p>' . esc_html__('GA4 nu elimină duplicatele dintre evenimentele trimise din browser (gtag/GTM) și cele trimise prin Measurement Protocol: dacă ambele surse trimit „purchase”, fiecare comandă apare de două ori în rapoarte și venitul este umflat.', 'adc-marketing') . '</p><p>' . esc_html__('De aceea alegeți UN SINGUR canal pentru purchase. Recomandat: Browser, cu eticheta prin GTM. Dacă alegeți Server, opriți trimiterea purchase din tag-ul GTM (condiție de custom event) și verificați dublarea în DebugView.', 'adc-marketing') . '</p>'
        );

        echo '</div>';

        ADC_Admin_Help::section(
            __('Opțiuni avansate', 'adc-marketing'),
            __('Ce face această secțiune: trei cazuri limită — cum identificăm evenimentele fără cookie de browser și ce se trimite când vizitatorul refuză.', 'adc-marketing')
        );

        echo '<div class="adc-help-options adc-settings-section">';

        ADC_Admin_Help::row(
            __('Identificator de rezervă (client_id)', 'adc-marketing'),
            '<label><input type="checkbox" name="fallback_client_id" value="1" ' . checked(!empty($s['fallback_client_id']), false, false) . ' /> ' . esc_html__('Folosește identificatorul de consimțământ (cid) când lipsește client_id din browser', 'adc-marketing') . '</label>',
            __('Evenimentele fără cookie _ga se pot trimite mai departe, dar sub un identificator nou — sesiunile nu se mai leagă de cele din browser.', 'adc-marketing'),
            null,
            'adc-help-ga4-fallback',
            '<p>' . esc_html__('Un eveniment GA4 are nevoie de client_id pentru a fi atribuit unei sesiuni. Când vizitatorul nu a acceptat cookie-urile, _ga lipsește:', 'adc-marketing') . '</p><ul><li>' . esc_html__('Opțiune dezactivată: evenimentul fără client_id NU se trimite (rapoartele subestimează vizitatorii care refuză).', 'adc-marketing') . '</li><li>' . esc_html__('Opțiune activată: se folosește cid-ul (identificatorul de consimțământ) ca înlocuitor, dar DOAR dacă vizitatorul a acordat statistici; cei care au refuză nu primesc niciun identificator persistent.', 'adc-marketing') . '</li></ul><p>' . esc_html__('Sesiunea GA4 rezultată nu se corelează cu cea din browser — bun pentru numărătoare, slab pentru rapoartele de utilizator.', 'adc-marketing') . '</p>'
        );

        ADC_Admin_Help::row(
            __('Trimitere chiar și fără consimțământ', 'adc-marketing'),
            '<label><input type="checkbox" name="send_when_denied" value="1" ' . checked(!empty($s['send_when_denied']), false, false) . ' /> ' . esc_html__('Trimite evenimente server-side chiar și fără consimțământ de statistici (anonimizat, fără date de identificare)', 'adc-marketing') . '</label>',
            __('Numberă și vizitatorii care au refuzat cookie-urile, fără niciun identificator; activați doar dacă politica de confidențialitate permite explicit.', 'adc-marketing'),
            null,
            'adc-help-ga4-denied',
            '<p>' . esc_html__('Implicit, refuzul categoriei „statistici” oprește complet trimiterea către GA4. Cu această opțiune activă, evenimentele se trimit anonim: fără client_id, fără cid, fără date de identificare — rămân doar numele evenimentului, valoarea și contextul.', 'adc-marketing') . '</p><p>' . esc_html__('Pentru vizitatorii care au refuzat, nu se folosește NICIUN identificator persistent. Activați doar dacă politica dumneavoastră de confidențialitate descrie măsurarea anonimă fără cookie-uri; altfel, lăsați opțiunea oprită.', 'adc-marketing') . '</p>'
        );

        ADC_Admin_Help::row(
            __('Mod depanare', 'adc-marketing'),
            '<label><input type="checkbox" name="debug" value="1" ' . checked(!empty($s['debug']), false, false) . ' /> ' . esc_html__('Trimite prin endpoint-ul de validare; evenimentele NU ajung în rapoarte', 'adc-marketing') . '</label>',
            __('Test fără poluarea rapoartelor: evenimentele merg în DebugView-ul Google, nu în rapoartele reale — dezactivați în producție.', 'adc-marketing')
        );

        echo '</div>';

        echo '<div class="adc-help-options adc-settings-section">';
        ADC_Admin_Help::row(
            __('Testare conexiune', 'adc-marketing'),
            '<p><button type="button" class="button" id="adc-ga4-test">' . esc_html__('Testează conexiunea', 'adc-marketing') . '</button> <span class="adc-test-result" id="adc-ga4-test-result" aria-live="polite"></span></p>',
            __('Trimite un eveniment sintetic prin endpoint-ul de validare Google; necesită Measurement ID și cheie API salvate.', 'adc-marketing')
        );
        echo '</div>';

        echo '<p class="description adc-section-intro">' . esc_html__('Notă: client_id este luat din cookie-ul _ga capturat la momentul comenzii, doar cu consimțământ de statistici. Pentru transport first-party (Server GTM) folosiți tabul „Server GTM”.', 'adc-marketing') . '</p>';
    }

    /**
     * Save via ADC_Settings::update (sanitizer in ADC_Integration_Ga4).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        $input = array(
            'enabled_server'     => !empty($post['enabled_server']),
            'browser_mode'       => (string) ($post['browser_mode'] ?? ''),
            'measurement_id'     => (string) ($post['measurement_id'] ?? ''),
            'api_secret'         => (string) ($post['api_secret'] ?? ''),
            'send_when_denied'   => !empty($post['send_when_denied']),
            'debug'              => !empty($post['debug']),
            'fallback_client_id' => !empty($post['fallback_client_id']),
            'endpoint_region'    => (string) ($post['endpoint_region'] ?? ''),
            'purchase_channel'   => (string) ($post['purchase_channel'] ?? ''),
        );

        $stored = ADC_Settings::update('ga4', $input);

        $notices = array(
            array(
                'type'    => 'success',
                'message' => __('Setările GA4 au fost salvate.', 'adc-marketing'),
            ),
        );

        if ('' === (string) ($stored['measurement_id'] ?? '') && '' !== trim((string) ($input['measurement_id'] ?? ''))) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Measurement ID invalid — se acceptă formatul G-XXXXXXX.', 'adc-marketing'),
            );
        }

        if ('server' === (string) ($stored['purchase_channel'] ?? '')) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Achizițiile se raportează din server. Configurați eticheta GA4 din GTM să NU mai trimită evenimentul purchase, altfel achizițiile se vor dubla (GA4 nu deduplică între gtag și server).', 'adc-marketing'),
            );
        }

        if (!empty($stored['debug'])) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Modul depanare este activ: evenimentele se verifică prin endpoint-ul de validare și NU ajung în rapoarte. Dezactivați-l în producție.', 'adc-marketing'),
            );
        }

        if (!empty($stored['send_when_denied'])) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Trimiterea fără consimțământ de statistici este activă — asigurați-vă că politica de confidențialitate permite acest lucru.', 'adc-marketing'),
            );
        }

        return $notices;
    }
}
