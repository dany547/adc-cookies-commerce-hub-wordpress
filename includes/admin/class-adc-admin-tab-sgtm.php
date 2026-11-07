<?php
/**
 * Legacy delegate for the former "Integrări ▸ Server GTM" tab (slug `sgtm`).
 *
 * The Server GTM settings UI moved into the unified tab ADC_Admin_Tab_Gtm
 * ("Google Tag Manager", Măsurare group); the class is no longer listed in
 * ADC_Admin_Shell::DEFAULT_TABS, so it renders no nav pill. It stays booted
 * and functional:
 *
 * - boot()/enqueue_assets() register the inline test-connection wiring the
 *   unified tab relies on (element ids `adc-sgtm-test` /
 *   `adc-sgtm-test-result`, endpoint `adc_test_integration` with
 *   `platform=sgtm`, nonce `adc_admin`, cap `manage_options`);
 * - render()/save() delegate to ADC_Admin_Tab_Gtm, so any code path (tests,
 *   `adc_admin_tabs` filter users, a direct `?tab=sgtm` consumer) that still
 *   instantiates this class behaves exactly like the unified tab.
 *
 * The `sgtm` settings group (registered by ADC_Integration_Sgtm) is
 * unchanged: the feature is inert unless a valid https server container URL
 * is stored; `mode=loader` (default) only switches the GTM/gtag transport to
 * the first-party URL (ADC_Gtm::loader_url()); `mode=mp_forward` is
 * EXPERIMENTAL and keeps its explicit admin warning (D20).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server GTM legacy delegate tab (settings live in ADC_Admin_Tab_Gtm).
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
                // Unsaved form fields sent with the test (secret: empty keeps the stored value).
                'fields'   => array(
                    'server_container_url' => array('id' => 'adc-sgtm-url'),
                    'measurement_id'       => array('id' => 'adc-sgtm-measurement-id'),
                    'mode'                 => array('id' => 'adc-sgtm-mode'),
                    'api_secret'           => array('id' => 'adc-sgtm-api-secret', 'secret' => true),
                ),
                'i18n'     => array(
                    'testing'  => __('Se testează conexiunea…', 'adc-marketing'),
                    'ok'       => __('Conexiune reușită', 'adc-marketing'),
                    'failed'   => __('Conexiunea a eșuat', 'adc-marketing'),
                    'error'    => __('Eroare de rețea — încearcă din nou.', 'adc-marketing'),
                    'fromForm' => __('s-au folosit valorile curente din formular, fără salvare', 'adc-marketing'),
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
            '(function(){var b=document.getElementById("adc-sgtm-test"),o=document.getElementById("adc-sgtm-test-result");if(!b||!o||!window.adcSgtmTest){return}var c=window.adcSgtmTest,i=c.i18n;function v(el){return el&&typeof el.value==="string"?el.value:""}b.addEventListener("click",function(){b.disabled=true;o.textContent=i.testing;o.className="adc-test-result";var f=new FormData(),p={},n=0,k,spec,val;f.append("action","adc_test_integration");f.append("platform",c.platform);f.append("nonce",c.nonce);for(k in c.fields){if(!Object.prototype.hasOwnProperty.call(c.fields,k)){continue}spec=c.fields[k];val=v(document.getElementById(spec.id));if(""===val&&spec.secret){continue}p[k]=val;n=n+1}if(n>0){f.append("fields",JSON.stringify(p))}fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",body:f}).then(function(r){return r.json()}).then(function(j){var d=j&&j.data?j.data:{},ok=!!(j&&j.success&&d.status==="sent");o.textContent=(ok?i.ok:i.failed)+(d.message?" — "+d.message:"")+(d.from_form?" — "+i.fromForm:"");o.className="adc-test-result "+(ok?"adc-test-ok":"adc-test-fail")}).catch(function(){o.textContent=i.error;o.className="adc-test-result adc-test-fail"}).finally(function(){b.disabled=false})});})();'
        );
    }

    /**
     * Tab slug (kept for backwards compatibility; the tab is not registered
     * in ADC_Admin_Shell::DEFAULT_TABS anymore — see the class docblock).
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
     * Former "integrations" group membership (legacy, unused by the nav).
     */
    public function group(): string
    {
        return 'integrations';
    }

    /**
     * Former sort order (legacy, unused while unregistered).
     */
    public function order(): int
    {
        return 50;
    }

    /**
     * Delegate to the unified GTM tab (same inner HTML, server card included).
     */
    public function render(): void
    {
        (new ADC_Admin_Tab_Gtm())->render();
    }

    /**
     * Delegate to the unified GTM tab save (both groups + legacy URL clear).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        return (new ADC_Admin_Tab_Gtm())->save($post);
    }
}
