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
        ?>
        <h2><?php esc_html_e('GA4 — Measurement Protocol (server-side)', 'adc-marketing'); ?></h2>
        <p class="description">
            <?php esc_html_e('Trimite evenimentele către Google Analytics 4 prin Measurement Protocol, direct din server. Evenimentele pleacă doar după consimțământul pentru categoria „statistici”; datele de identificare sunt criptate SHA-256, iar cheia API este stocată criptată și nu se mai afișează după salvare.', 'adc-marketing'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Trimitere server (Measurement Protocol)', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled_server" value="1" <?php checked(!empty($s['enabled_server'])); ?> />
                        <?php esc_html_e('Activează trimiterea evenimentelor server-side către GA4', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-ga4-browser-mode"><?php esc_html_e('Mod etichetă în browser', 'adc-marketing'); ?></label></th>
                <td>
                    <select id="adc-ga4-browser-mode" name="browser_mode">
                        <option value="off" <?php selected('off', (string) $s['browser_mode']); ?>><?php esc_html_e('Dezactivat — fără gtag în browser', 'adc-marketing'); ?></option>
                        <option value="gtm" <?php selected('gtm', (string) $s['browser_mode']); ?>><?php esc_html_e('Gestionat prin Google Tag Manager (recomandat)', 'adc-marketing'); ?></option>
                        <option value="direct" <?php selected('direct', (string) $s['browser_mode']); ?>><?php esc_html_e('Încărcare directă gtag — doar după consimțământul de statistici', 'adc-marketing'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-ga4-measurement-id"><?php esc_html_e('Measurement ID', 'adc-marketing'); ?></label></th>
                <td>
                    <input type="text" id="adc-ga4-measurement-id" name="measurement_id" value="<?php echo esc_attr((string) $s['measurement_id']); ?>" class="regular-text" maxlength="20" autocomplete="off" spellcheck="false" />
                    <p class="description"><?php esc_html_e('Format G-XXXXXXX, din Admin → Data Streams. Este necesar pentru trimiterea server-side.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-ga4-api-secret"><?php esc_html_e('Cheie API (Measurement Protocol)', 'adc-marketing'); ?></label></th>
                <td>
                    <input type="password" id="adc-ga4-api-secret" name="api_secret" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />
                    <?php if ($has_secret) : ?>
                        <p class="description"><?php esc_html_e('••••• (salvat) — lasă câmpul gol pentru a păstra cheia curentă.', 'adc-marketing'); ?></p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('Cheia generată în Data Streams → Measurement Protocol → Create. Este stocată criptat pe server. Poate fi suprascrisă prin constanta ADC_GA4_API_SECRET în wp-config.php.', 'adc-marketing'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-ga4-endpoint-region"><?php esc_html_e('Regiune colectare', 'adc-marketing'); ?></label></th>
                <td>
                    <select id="adc-ga4-endpoint-region" name="endpoint_region">
                        <option value="eu" <?php selected('eu', (string) $s['endpoint_region']); ?>><?php esc_html_e('Uniunea Europeană (recomandat — region1.google-analytics.com)', 'adc-marketing'); ?></option>
                        <option value="global" <?php selected('global', (string) $s['endpoint_region']); ?>><?php esc_html_e('Global (www.google-analytics.com)', 'adc-marketing'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-ga4-purchase-channel"><?php esc_html_e('Canal pentru evenimentul «purchase»', 'adc-marketing'); ?></label></th>
                <td>
                    <select id="adc-ga4-purchase-channel" name="purchase_channel">
                        <option value="browser" <?php selected('browser', (string) $s['purchase_channel']); ?>><?php esc_html_e('Browser (recomandat — gtag / GTM)', 'adc-marketing'); ?></option>
                        <option value="server" <?php selected('server', (string) $s['purchase_channel']); ?>><?php esc_html_e('Server (Measurement Protocol)', 'adc-marketing'); ?></option>
                    </select>
                    <?php if ('server' === (string) $s['purchase_channel']) : ?>
                        <p class="description" style="color:#e2401c;">
                            <?php esc_html_e('Atenție: GA4 nu dispune de deduplificare automată între gtag și Measurement Protocol. Cu canalul „Server”, achizițiile se raportează doar din server; eticheta GA4 din GTM trebuie configurată să NU mai trimită purchase (de exemplu printr-o condiție de tip custom event), altfel achizițiile se vor dubla.', 'adc-marketing'); ?>
                        </p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('GA4 nu deduplică automat între browser și server, achiziția se raportează dintr-un singur canal. „refund” rămâne întotdeauna server-side.', 'adc-marketing'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Opțiuni avansate', 'adc-marketing'); ?></th>
                <td>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="fallback_client_id" value="1" <?php checked(!empty($s['fallback_client_id'])); ?> />
                        <?php esc_html_e('Folosește identificatorul de consimțământ (cid) când lipsește client_id din browser', 'adc-marketing'); ?>
                    </label>
                    <p class="description" style="margin-bottom:10px;"><?php esc_html_e('Fără această opțiune, evenimentele fără client_id (cookie _ga neatins) nu se trimit. Cu opțiunea activă, evenimentul se raportează sub un client nou (sesiunea GA4 nu se leagă de cea din browser). Identificatorul de consimțământ (cid) se folosește DOAR dacă vizitatorul a acordat consimțământul de statistici; pentru vizitatorii care au refuzat cookie-urile, evenimentele fără client_id nu se trimit (fără identificatori persistenți).', 'adc-marketing'); ?></p>

                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="send_when_denied" value="1" <?php checked(!empty($s['send_when_denied'])); ?> />
                        <?php esc_html_e('Trimite evenimente server-side chiar și fără consimțământ de statistici (anonimizat, fără date de identificare)', 'adc-marketing'); ?>
                    </label>
                    <p class="description" style="margin-bottom:10px;"><?php esc_html_e('Dezactivat implicit. Activați doar dacă politica dumneavoastră de confidențialitate permite numărarea anonimă fără cookie-uri; niciun date de identificare nu se trimite în acest mod, iar identificatorul de consimțământ (cid) nu se folosește ca substitute pentru client_id atunci când statisticile sunt refuzate.', 'adc-marketing'); ?></p>

                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="debug" value="1" <?php checked(!empty($s['debug'])); ?> />
                        <?php esc_html_e('Mod depanare (trimite prin endpoint-ul de validare; evenimentele NU ajung în rapoarte)', 'adc-marketing'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Folosit pentru verificări; dezactivați în producție.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Testare conexiune', 'adc-marketing'); ?></th>
                <td>
                    <p>
                        <button type="button" class="button" id="adc-ga4-test"><?php esc_html_e('Testează conexiunea', 'adc-marketing'); ?></button>
                        <span class="adc-test-result" id="adc-ga4-test-result" aria-live="polite"></span>
                    </p>
                    <p class="description"><?php esc_html_e('Trimite un eveniment sintetic prin endpoint-ul de validare (/debug/mp/collect). Necesită Measurement ID și cheie API salvate.', 'adc-marketing'); ?></p>
                </td>
            </tr>
        </table>

        <p class="description">
            <?php esc_html_e('Notă: identificatorul client (client_id) este luat din cookie-ul _ga capturat la momentul comenzii, doar cu consimțământ de statistici. Pentru transport first-party (Server GTM) folosiți tabul „Server GTM”.', 'adc-marketing'); ?>
        </p>
        <?php
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
