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
        ?>
        <h2><?php esc_html_e('TikTok — Events API & Pixel', 'adc-marketing'); ?></h2>
        <p class="description">
            <?php esc_html_e('Trimite evenimentele către TikTok Events API 2.0 (server) și încarcă pixelul TikTok în browser. Evenimentele pornesc doar după consimțământul pentru categoria „marketing”; e-mailul și telefonul sunt trimise criptate SHA-256, iar tokenul de acces este stocat criptat și nu se mai afișează după salvare.', 'adc-marketing'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Trimitere server (Events API)', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled_server" value="1" <?php checked(!empty($s['enabled_server'])); ?> />
                        <?php esc_html_e('Activează trimiterea evenimentelor server-side către TikTok Events API', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-tiktok-browser-mode"><?php esc_html_e('Mod pixel în browser', 'adc-marketing'); ?></label></th>
                <td>
                    <select id="adc-tiktok-browser-mode" name="browser_mode">
                        <option value="off" <?php selected('off', (string) $s['browser_mode']); ?>><?php esc_html_e('Dezactivat — fără pixel în browser', 'adc-marketing'); ?></option>
                        <option value="gtm" <?php selected('gtm', (string) $s['browser_mode']); ?>><?php esc_html_e('Gestionat prin Google Tag Manager (recomandat)', 'adc-marketing'); ?></option>
                        <option value="direct" <?php selected('direct', (string) $s['browser_mode']); ?>><?php esc_html_e('Încărcare directă — doar după consimțământul de marketing', 'adc-marketing'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-tiktok-pixel-code"><?php esc_html_e('Cod pixel', 'adc-marketing'); ?></label></th>
                <td>
                    <input type="text" id="adc-tiktok-pixel-code" name="pixel_code" value="<?php echo esc_attr((string) $s['pixel_code']); ?>" class="regular-text" maxlength="40" autocomplete="off" />
                    <p class="description"><?php esc_html_e('Pixel Code din TikTok Events Manager (doar cifre și litere).', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-tiktok-access-token"><?php esc_html_e('Token de acces', 'adc-marketing'); ?></label></th>
                <td>
                    <input type="password" id="adc-tiktok-access-token" name="access_token" value="" class="regular-text code" autocomplete="new-password" spellcheck="false" />
                    <?php if ($has_token) : ?>
                        <p class="description"><?php esc_html_e('••••• (salvat) — lasă câmpul gol pentru a păstra tokenul curent.', 'adc-marketing'); ?></p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('Access-Token generat în TikTok Events Manager. Este stocat criptat pe server. Poate fi suprascris prin constanta ADC_TIKTOK_ACCESS_TOKEN în wp-config.php.', 'adc-marketing'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-tiktok-test-code"><?php esc_html_e('Cod eveniment test', 'adc-marketing'); ?></label></th>
                <td>
                    <input type="text" id="adc-tiktok-test-code" name="test_event_code" value="<?php echo esc_attr((string) $s['test_event_code']); ?>" class="regular-text" maxlength="40" autocomplete="off" />
                    <?php if ('' !== (string) $s['test_event_code']) : ?>
                        <p class="description" style="color:#e2401c;"><?php esc_html_e('Atenție: cu un cod de test activ, evenimentele ajung în Test Events — șterge codul în producție.', 'adc-marketing'); ?></p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('Codul de test din TikTok Events Manager, folosit doar pentru testarea conexiunii. Lasă-l gol în producție.', 'adc-marketing'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Testare conexiune', 'adc-marketing'); ?></th>
                <td>
                    <p>
                        <button type="button" class="button" id="adc-tiktok-test"><?php esc_html_e('Testează conexiunea', 'adc-marketing'); ?></button>
                        <span class="adc-test-result" id="adc-tiktok-test-result" aria-live="polite"></span>
                    </p>
                    <p class="description"><?php esc_html_e('Trimite un eveniment sintetic către Test Events. Necesită cod pixel, token și cod de eveniment test salvate.', 'adc-marketing'); ?></p>
                </td>
            </tr>
        </table>
        <?php
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
