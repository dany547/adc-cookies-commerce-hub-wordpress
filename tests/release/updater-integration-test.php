<?php
/**
 * Real-WordPress integration runner for the GitHub release updater.
 *
 * NOT part of CI: it needs a disposable WordPress install (wp-config.php, DB,
 * WooCommerce active, this plugin installed as an older version) and a fixture
 * directory generated from a built release. It exits 0 with "SKIP" when the
 * environment variables are not set, so it is safe to leave in the tree.
 *
 * HTTP is simulated at WordPress' own boundary (`pre_http_request`), so the
 * real core hooks run: wp_update_plugins() -> update_plugins_github.com,
 * plugins_api, upgrader_pre_download, Plugin_Upgrader::upgrade().
 *
 * Env:
 *   ADC_WP_ROOT      WordPress directory containing wp-load.php
 *   ADC_FIXTURE_DIR  directory holding one sub-directory per scenario
 *                    (api.json, _status, adc-marketing-X.Y.Z.zip, update-manifest.json, SHA256SUMS)
 *   ADC_SCENARIO     scenario sub-directory name (or "none" for no fixture)
 *   ADC_MODE         setup | check | upgrade | frontend | real
 *   ADC_REQ_LOG      JSON-lines file for the recorded outbound requests
 *
 * Prints one JSON object per run.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

$root     = getenv('ADC_WP_ROOT') ?: '';
$fixtures = getenv('ADC_FIXTURE_DIR') ?: '';
$scenario = getenv('ADC_SCENARIO') ?: '';
$mode     = getenv('ADC_MODE') ?: 'check';
$req_log  = getenv('ADC_REQ_LOG') ?: '';

if ('' === $root || !is_file($root . '/wp-load.php')) {
    echo "SKIP updater-integration-test: ADC_WP_ROOT not set\n";
    exit(0);
}

if ('frontend' !== $mode) {
    define('WP_ADMIN', true); // is_admin() === true, as in wp-admin / cron update checks.
}
$_SERVER['HTTP_HOST']   = 'adcupd.test';
$_SERVER['REQUEST_URI'] = '/wp-admin/';

require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

const ADC_IT_BASENAME = 'adc-marketing/adc-marketing.php';
const ADC_IT_REPO     = '/dany547/adc-cookies-commerce-hub-wordpress';

/**
 * @return array<string, mixed>
 */
function adc_it_response(string $body, int $code = 200): array
{
    return array(
        'headers'  => array(),
        'body'     => $body,
        'response' => array('code' => $code, 'message' => ''),
        'cookies'  => array(),
        'filename' => null,
    );
}

$log = static function (array $entry) use ($req_log): void {
    if ('' !== $req_log) {
        file_put_contents($req_log, json_encode($entry) . "\n", FILE_APPEND);
    }
};

if ('real' !== $mode && 'setup' !== $mode) {
    add_filter('pre_http_request', static function ($pre, $args, $url) use ($fixtures, $scenario, $log) {
        $p    = wp_parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        $path = (string) ($p['path'] ?? '');
        $hdr  = is_array($args['headers'] ?? null) ? $args['headers'] : array();
        $log(array(
            'url'         => $url,
            'ua'          => $args['user-agent'] ?? null,
            'hdr_ua'      => $hdr['User-Agent'] ?? null,
            'hdr_keys'    => array_keys($hdr),
            'has_cookies' => !empty($args['cookies']),
            'has_auth'    => isset($hdr['Authorization']) || isset($hdr['authorization']),
            'sslverify'   => $args['sslverify'] ?? null,
            'redirection' => $args['redirection'] ?? null,
            'limit'       => $args['limit_response_size'] ?? null,
        ));
        if ('api.wordpress.org' === $host) {
            return adc_it_response('{"plugins":[],"translations":[],"no_update":[]}');
        }
        $dir = $fixtures . '/' . $scenario;
        if ('api.github.com' === $host && ADC_IT_REPO . '/releases/latest' === preg_replace('#^/repos#', '', $path)) {
            if (!is_file($dir . '/api.json')) {
                return adc_it_response('{"message":"Not Found"}', 404);
            }
            return adc_it_response((string) file_get_contents($dir . '/api.json'), (int) trim((string) file_get_contents($dir . '/_status')));
        }
        if ('github.com' === $host && preg_match('#^' . preg_quote(ADC_IT_REPO, '#') . '/releases/download/[^/]+/([A-Za-z0-9._-]+)$#', $path, $m) && is_file($dir . '/' . $m[1])) {
            return adc_it_response((string) file_get_contents($dir . '/' . $m[1]));
        }
        return new WP_Error('adc_it_blocked', 'unexpected outbound request: ' . $url);
    }, 10, 3);
}

wp_set_current_user(1);
$result = array('scenario' => $scenario, 'mode' => $mode);

if ('setup' === $mode) {
    $r                 = activate_plugin('woocommerce/woocommerce.php');
    $result['wc']      = is_wp_error($r) ? $r->get_error_message() : 'active';
    $r                 = activate_plugin(ADC_IT_BASENAME);
    $result['adc']     = is_wp_error($r) ? $r->get_error_message() : 'active';
    update_option('adc_canary_option', 'canary-v1', false);
    update_user_meta(1, '_adc_marketing_consent', 'yes');
    update_user_meta(1, 'adc_canary_meta', 'canary-v1');
    @mkdir(WP_CONTENT_DIR . '/uploads', 0755, true);
    file_put_contents(WP_CONTENT_DIR . '/uploads/adc-canary.txt', 'canary-v1');
    echo json_encode($result) . "\n";
    exit(0);
}

if ('1' === getenv('ADC_FORCE_PCLZIP') && class_exists('ADC_Github_Updater')) {
    // Exercise the PclZip inspection fallback on hosts that do have ext-zip.
    $prop = new ReflectionProperty('ADC_Github_Updater', 'prefer_ziparchive');
    $prop->setValue(null, false);
    $result['reader'] = 'pclzip';
}

delete_site_transient('update_plugins');
if ('keepcache' !== getenv('ADC_CACHE')) {
    delete_site_transient('adc_updater_cache');
}
wp_update_plugins();
$t   = get_site_transient('update_plugins');
$upd = is_object($t) && isset($t->response[ADC_IT_BASENAME]) ? $t->response[ADC_IT_BASENAME] : null;

$result['offered']        = null !== $upd;
$result['update_fields']  = null === $upd ? null : array_keys((array) $upd);
$result['new_version']    = $upd->new_version ?? null;
$result['package']        = $upd->package ?? null;
$result['autoupdate_set'] = null !== $upd && isset($upd->autoupdate);
$result['other_in_resp']  = is_object($t) && isset($t->response['other-gh/other-gh.php']);
$result['installed']      = get_plugin_data(WP_PLUGIN_DIR . '/' . ADC_IT_BASENAME, false, false)['Version'];

if ('upgrade' === $mode) {
    $skin     = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $res      = $upgrader->upgrade(ADC_IT_BASENAME);
    $result['upgrade_result'] = is_wp_error($res) ? 'WP_Error:' . $res->get_error_code() : var_export($res, true);
    $errs                     = $skin->get_upgrade_messages();
    $result['upgrade_msgs']   = array_slice(array_map('wp_strip_all_tags', $errs), -3);
    $wperr                    = $skin->result;
    $result['skin_result']    = is_wp_error($wperr) ? $wperr->get_error_code() . '|' . implode(',', array_keys($wperr->errors)) : gettype($wperr);
    wp_clean_plugins_cache(true);
    $result['installed_after']  = get_plugin_data(WP_PLUGIN_DIR . '/' . ADC_IT_BASENAME, false, false)['Version'];
    $result['dirs_in_plugins']  = array_values(array_diff(scandir(WP_PLUGIN_DIR), array('.', '..', 'index.php')));
    $result['active_after']     = is_plugin_active(ADC_IT_BASENAME);
    $result['canary_option']    = get_option('adc_canary_option');
    $result['canary_meta']      = get_user_meta(1, 'adc_canary_meta', true);
    $result['consent_meta']     = get_user_meta(1, '_adc_marketing_consent', true);
    $result['canary_file']      = (string) @file_get_contents(WP_CONTENT_DIR . '/uploads/adc-canary.txt');
}

echo json_encode($result) . "\n";
