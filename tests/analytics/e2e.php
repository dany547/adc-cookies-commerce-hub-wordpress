<?php
/**
 * Independent E2E validation for operational analytics (backend / privacy / DB).
 *
 * DISPOSABLE environments only. Refuses to run unless ADCVAL_DISPOSABLE=1 and the
 * WordPress install lives on a loopback host. Real SQL (MariaDB/InnoDB), real WC CRUD,
 * real Action Scheduler, real HTTP (Store API / Classic AJAX / admin-post). Nothing is
 * mocked; where something is simulated (e.g. gateway IPN = payment_complete()) the
 * line says so.
 *
 * Run:  ADCVAL_DISPOSABLE=1 php e2e.php --wp=/path/to/wordpress --base=http://127.0.0.1:9478 [--group=a,b]
 * Groups: env gates lifecycle dedupe rollback concurrency retention privacy http checkout ui bench uninstall
 * (`uninstall` is never in the default set; it wipes the disposable plugin data.)
 * Workers (internal): --worker=<name> --payload=<json>
 *
 * Output: PASS/FAIL/SKIP per assertion, summary, exit 1 on any FAIL. No credential is printed.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

$adcval_args = array();
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$k, $v] = explode('=', substr($arg, 2), 2);
        $adcval_args[$k] = $v;
    }
}

if ('1' !== getenv('ADCVAL_DISPOSABLE')) {
    fwrite(STDERR, "REFUSED: set ADCVAL_DISPOSABLE=1 (disposable harness only).\n");
    exit(2);
}
$adcval_wp = rtrim((string) ($adcval_args['wp'] ?? getenv('ADCVAL_WP') ?: ''), '/');
if ('' === $adcval_wp || !is_file($adcval_wp . '/wp-load.php')) {
    fwrite(STDERR, "REFUSED: --wp=<path to disposable WordPress> required.\n");
    exit(2);
}
$adcval_base = rtrim((string) ($adcval_args['base'] ?? 'http://127.0.0.1:9478'), '/');
$adcval_host = (string) parse_url($adcval_base, PHP_URL_HOST);
if (!in_array($adcval_host, array('127.0.0.1', 'localhost'), true)) {
    fwrite(STDERR, "REFUSED: base URL must be loopback.\n");
    exit(2);
}

$_SERVER['HTTP_HOST']   = $adcval_host . ':' . (string) parse_url($adcval_base, PHP_URL_PORT);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $adcval_wp . '/wp-load.php';

if (!defined('DB_HOST') || !str_contains((string) DB_HOST, 'adcval-sock')) {
    fwrite(STDERR, "REFUSED: DB_HOST is not the adcval disposable socket.\n");
    exit(2);
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/* ---------------------------------------------------------------- harness */

final class AdcVal
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static int $skip = 0;
    public static string $group = '';

    public static function ok(bool $cond, string $name, string $evidence = ''): bool
    {
        if ($cond) {
            self::$pass++;
            echo 'PASS [' . self::$group . '] ' . $name . ('' !== $evidence ? " ({$evidence})" : '') . "\n";
        } else {
            self::$fail++;
            echo 'FAIL [' . self::$group . '] ' . $name . ('' !== $evidence ? " ({$evidence})" : '') . "\n";
        }
        return $cond;
    }

    public static function skip(string $name, string $why): void
    {
        self::$skip++;
        echo 'SKIP [' . self::$group . '] ' . $name . ' -- ' . $why . "\n";
    }

    public static function info(string $text): void
    {
        echo 'INFO [' . self::$group . '] ' . $text . "\n";
    }
}

function adcval_settings(array $s): void
{
    update_option('adc_settings_analytics', array_merge(ADC_Analytics_Settings::defaults(), $s));
}

function adcval_gate_on(): void
{
    adcval_settings(array('enabled_commerce' => true, 'commerce_ack' => true));
}

/** compare_day() that reports an exception as a FAIL instead of aborting the run. */
function adcval_compare(string $day, string $currency): array
{
    try {
        return ADC_Analytics_Reconciler::compare_day($day, $currency);
    } catch (Throwable $e) {
        AdcVal::ok(false, 'compare_day(' . $day . ',' . $currency . ') did not throw', substr($e->getMessage(), 0, 150));
        return array('__error' => 999.0);
    }
}

/** Unique past day per call (inside the 730-day default retention) so reconciliation sees only this run's orders. */
function adcval_runday(): string
{
    $n = (int) get_option('adcval_run_counter', 0) + 1;
    update_option('adcval_run_counter', $n, false);
    return wp_date('Y-m-d', time() - (30 + ($n % 600)) * DAY_IN_SECONDS);
}

function adcval_dated(WC_Order $o, string $day, bool $paid): WC_Order
{
    $when = new WC_DateTime($day . ' 12:00:00', new DateTimeZone('UTC'));
    $o->set_date_created($when);
    if ($paid) {
        $o->set_status('processing');
        $o->set_date_paid($when);
    }
    $o->save();
    return $o;
}

/** Max |difference| of compare_day deltas against an earlier baseline (isolates leftovers from earlier runs). */
function adcval_delta(array $base, array $now): float
{
    $max = 0.0;
    foreach ($now as $k => $v) {
        $max = max($max, abs((float) $v - (float) ($base[$k] ?? 0)));
    }
    return $max;
}

/** Delta check on a busy day; honest SKIP when the long-lived disposable DB exceeds the reconciler's 2000-order bound. */
function adcval_delta_check(string $name, array $base, string $day, string $cur): void
{
    try {
        $now = ADC_Analytics_Reconciler::compare_day($day, $cur);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'too many orders')) {
            AdcVal::skip($name, 'day ' . $day . ' holds more than the 2000-order reconciler bound in this long-lived disposable DB (bound refuses, as designed); isolated-day reconciliation covers it');
            return;
        }
        AdcVal::ok(false, $name . ' did not throw', substr($e->getMessage(), 0, 150));
        return;
    }
    $d = adcval_delta($base, $now);
    AdcVal::ok($d < 0.01, $name, (string) $d);
}

function adcval_base_or_null(string $day, string $cur): ?array
{
    try {
        return ADC_Analytics_Reconciler::compare_day($day, $cur);
    } catch (Throwable $e) {
        return null;
    }
}

function adcval_t(string $key): string
{
    return ADC_Analytics_Schema::table($key);
}

function adcval_reset_tables(): void
{
    global $wpdb;
    foreach (array('COMMERCE', 'PRODUCTS', 'LEDGER', 'TRAFFIC', 'INTERACTIONS', 'CAMPAIGNS') as $c) {
        $wpdb->query('TRUNCATE TABLE ' . adcval_t(constant('ADC_Analytics_Schema::' . $c)));
    }
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('', array(), ADC_Analytics_Commerce::GROUP);
    }
}

/** @return array<int,object> pending analytics actions as [id => action] */
function adcval_pending(): array
{
    $out = array();
    $ids = ActionScheduler::store()->query_actions(array(
        'hook'     => ADC_Analytics_Commerce::JOB_HOOK,
        'status'   => ActionScheduler_Store::STATUS_PENDING,
        'per_page' => -1,
    ));
    foreach ($ids as $id) {
        $out[(int) $id] = ActionScheduler::store()->fetch_action((string) $id);
    }
    return $out;
}

/** Run every pending analytics action through the real Action Scheduler runner. */
function adcval_drain(): int
{
    $n = 0;
    foreach (array_keys(adcval_pending()) as $id) {
        ActionScheduler::runner()->process_action((int) $id, 'adcval');
        $n++;
    }
    return $n;
}

function adcval_metric(string $date, string $cur, string $metric, string $status = ''): array
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT amount, count FROM ' . adcval_t(ADC_Analytics_Schema::COMMERCE) . ' WHERE stat_date=%s AND currency=%s AND order_status=%s AND metric=%s',
        $date,
        $cur,
        $status,
        $metric
    ), ARRAY_A);
    return array('amount' => (float) ($row['amount'] ?? 0), 'count' => (int) ($row['count'] ?? 0));
}

function adcval_ledger_count(int $order_id, string $key): int
{
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER) . ' WHERE order_id=%d AND event_key=%s',
        $order_id,
        $key
    ));
}

function adcval_products(): array
{
    static $p = null;
    if (null === $p) {
        $p = array();
        foreach (array(array('ADCVAL Alpha', '10.00'), array('ADCVAL Beta', '25.50')) as [$name, $price]) {
            $prod = new WC_Product_Simple();
            $prod->set_name($name);
            $prod->set_regular_price($price);
            $prod->set_status('publish');
            $prod->set_virtual(true);
            $prod->save();
            $p[] = $prod;
        }
    }
    return $p;
}

/**
 * Synthetic order. Totals are set explicitly so expectations are independent of
 * the plugin: items = 2xAlpha(10) + 1xBeta(25.50) = 45.50 net, ship 5.00,
 * cart tax 1.90, shipping tax 0.95, discount 2.00 (+0.38 tax).
 */
function adcval_order(string $currency = 'RON', array $opts = array()): WC_Order
{
    [$a, $b] = adcval_products();
    $order = wc_create_order(array('status' => (string) ($opts['status'] ?? 'pending')));
    $order->set_currency($currency);
    $order->set_billing_email('adcval-' . wp_generate_password(8, false) . '@example.test');
    $order->set_billing_first_name('Synthetic');
    $order->add_product($a, 2);
    $order->add_product($b, 1);
    $ship = new WC_Order_Item_Shipping();
    $ship->set_method_title('Flat');
    $ship->set_total('5.00');
    $order->add_item($ship);
    $order->set_cart_tax(1.90);
    $order->set_shipping_total(5.00);
    $order->set_shipping_tax(0.95);
    $order->set_discount_total(2.00);
    $order->set_discount_tax(0.38);
    $order->set_total((float) ($opts['total'] ?? 52.35));
    $order->save();
    return $order;
}

function adcval_http(string $method, string $path, array $o = array()): array
{
    global $adcval_base;
    $ch = curl_init(str_starts_with($path, 'http') ? $path : $adcval_base . $path);
    $hdr = array();
    foreach (($o['headers'] ?? array()) as $k => $v) {
        $hdr[] = $k . ': ' . $v;
    }
    $resp_headers = array();
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $o['follow'] ?? false,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => $hdr,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$resp_headers): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $resp_headers[strtolower(trim($k))][] = trim($v);
            }
            return strlen($line);
        },
    ));
    if (isset($o['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $o['cookie']);
    }
    if (isset($o['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']);
    }
    if (isset($o['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']);
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => $body, 'headers' => $resp_headers);
}

/** @return array{cookie:string,logged_in:string} real session-backed auth cookies for a user (never printed) */
function adcval_session(int $user_id): array
{
    static $cache = array();
    if (!isset($cache[$user_id])) {
        $exp   = time() + 3600;
        $token = WP_Session_Tokens::get_instance($user_id)->create($exp);
        $auth  = wp_generate_auth_cookie($user_id, $exp, 'auth', $token);
        $li    = wp_generate_auth_cookie($user_id, $exp, 'logged_in', $token);
        $cache[$user_id] = array(
            'cookie'    => 'wordpress_' . COOKIEHASH . '=' . rawurlencode($auth) . '; wordpress_logged_in_' . COOKIEHASH . '=' . rawurlencode($li),
            'logged_in' => $li,
        );
    }
    return $cache[$user_id];
}

function adcval_cookie(int $user_id): string
{
    return adcval_session($user_id)['cookie'];
}

function adcval_user(string $role): int
{
    $login = 'adcval_' . $role;
    $u = get_user_by('login', $login);
    if ($u) {
        return (int) $u->ID;
    }
    $id = wp_insert_user(array(
        'user_login' => $login,
        'user_pass'  => bin2hex(random_bytes(16)),
        'user_email' => $login . '@example.test',
        'role'       => $role,
    ));
    return (int) $id;
}

function adcval_nonce_for(int $user_id, string $action): string
{
    $_COOKIE[LOGGED_IN_COOKIE] = adcval_session($user_id)['logged_in']; // nonce is bound to the session token
    wp_set_current_user($user_id);
    $n = wp_create_nonce($action);
    wp_set_current_user(0);
    unset($_COOKIE[LOGGED_IN_COOKIE]);
    return $n;
}

function adcval_php(array $args): array
{
    global $adcval_wp, $adcval_base;
    $ph = getenv('ADCVAL_PHP') ?: (dirname($adcval_wp) . '/php.sh');
    $cmd = array($ph, __FILE__, '--wp=' . $adcval_wp, '--base=' . $adcval_base);
    foreach ($args as $k => $v) {
        $cmd[] = '--' . $k . '=' . $v;
    }
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array('ADCVAL_DISPOSABLE' => '1', 'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')));
    return array($proc, $pipes);
}

/** @return string[] debug.log plus WooCommerce log files that may receive plugin output */
function adcval_logfiles(): array
{
    $files = array(WP_DEBUG_LOG);
    foreach (glob(WP_CONTENT_DIR . '/uploads/wc-logs/*.log') ?: array() as $f) {
        $files[] = $f;
    }
    return $files;
}

/** @return array<string,int> byte offsets per log file */
function adcval_logsize(): array
{
    $o = array();
    foreach (adcval_logfiles() as $f) {
        $o[$f] = is_file($f) ? (int) filesize($f) : 0;
    }
    return $o;
}

function adcval_log_since(array $offsets): string
{
    $out = '';
    foreach (adcval_logfiles() as $f) {
        if (!is_file($f)) {
            continue;
        }
        $h = fopen($f, 'rb');
        fseek($h, $offsets[$f] ?? 0);
        $out .= (string) stream_get_contents($h);
        fclose($h);
    }
    return $out;
}

/* ---------------------------------------------------------------- workers */

if (isset($adcval_args['worker'])) {
    $p = json_decode((string) $adcval_args['payload'], true);
    $start = (float) ($p['start'] ?? 0);
    while (microtime(true) < $start) {
        usleep(200);
    }
    $t0 = microtime(true);
    switch ($adcval_args['worker']) {
        case 'process':
            foreach ($p['events'] as $e) {
                ADC_Analytics_Commerce::process((string) $e[0], (int) $e[1], (int) ($e[2] ?? 0), (string) ($e[3] ?? ''), 0);
            }
            break;
        case 'holdlock':
            $got = ADC_Analytics_Store::lock_day((string) $p['date'], 0);
            file_put_contents((string) $p['flag'], $got ? 'held' : 'refused');
            $until = microtime(true) + (float) $p['seconds'];
            while (microtime(true) < $until) {
                usleep(20000);
            }
            ADC_Analytics_Store::unlock_day((string) $p['date']);
            break;
        case 'rebuild':
            $r = ADC_Analytics_Reconciler::rebuild_day((string) $p['date']);
            echo 'RESULT ' . json_encode(array('ok' => $r['ok'], 'reason' => $r['reason'])) . "\n";
            break;
        case 'lock_probe':
            echo 'RESULT ' . json_encode(array('lock' => ADC_Analytics_Store::lock_day((string) $p['date'], (int) $p['timeout']))) . "\n";
            break;
    }
    echo 'RESULT ' . json_encode(array('elapsed' => round(microtime(true) - $t0, 4))) . "\n";
    exit(0);
}

/* ---------------------------------------------------------------- run */

$all    = array('env', 'gates', 'lifecycle', 'dedupe', 'rollback', 'concurrency', 'retention', 'privacy', 'http', 'checkout', 'ui', 'bench');
$groups = isset($adcval_args['group']) ? explode(',', (string) $adcval_args['group']) : $all;
$saved_settings = get_option('adc_settings_analytics', false);
$saved_tz       = array(get_option('timezone_string'), get_option('gmt_offset'));

$today = (string) wp_date('Y-m-d');

// Optional storage switch: --hpos=on|off (compatibility mode keeps both tables in sync).
if (isset($adcval_args['hpos'])) {
    $want = 'on' === $adcval_args['hpos'];
    $ds = wc_get_container()->get(Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
    $ds->create_database_tables();
    update_option('woocommerce_custom_orders_table_data_sync_enabled', 'yes');
    for ($i = 0; $i < 100; $i++) {
        $ids = $ds->get_next_batch_to_process(100);
        if (!$ids) {
            break;
        }
        $ds->process_batch($ids);
    }
    update_option('woocommerce_custom_orders_table_enabled', $want ? 'yes' : 'no');
    $actual = OrderUtil::custom_orders_table_usage_is_enabled();
    echo 'INFO [setup] HPOS requested ' . ($want ? 'on' : 'off') . ', actual ' . ($actual ? 'on' : 'off') . ", sync=yes\n";
    if ($actual !== $want) {
        fwrite(STDERR, "Could not switch order storage.\n");
        exit(2);
    }
}

// Harness fixture: WC 11 ships new stores in coming-soon mode, which replaces every public page.
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_store_pages_only', 'no');

/* ===== env ===== */
if (in_array('env', $groups, true)) {
    AdcVal::$group = 'env';
    global $wpdb;
    $ver = (string) $wpdb->get_var('SELECT VERSION()');
    AdcVal::info('PHP ' . PHP_VERSION . ' WP ' . get_bloginfo('version') . ' WC ' . WC_VERSION . ' ADC ' . (defined('ADC_MARKETING_VERSION') ? ADC_MARKETING_VERSION : '?') . ' DB ' . $ver . ' AS ' . ActionScheduler_Versions::instance()->latest_version());
    AdcVal::info('HPOS storage in use: ' . (OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off') . '; sync: ' . (string) get_option('woocommerce_custom_orders_table_data_sync_enabled'));
    AdcVal::ok(false !== stripos($ver, 'mariadb') || version_compare($ver, '8.0', '>='), 'real MySQL-family server', $ver);
    AdcVal::ok(get_class($wpdb) === 'wpdb', 'wpdb is the native MySQL driver (not SQLite shim)');
    AdcVal::ok('1' === (string) $wpdb->get_var("SELECT GET_LOCK('adcval_env',0)"), 'GET_LOCK available');
    $wpdb->query("SELECT RELEASE_LOCK('adcval_env')");
    foreach (array('TRAFFIC', 'INTERACTIONS', 'COMMERCE', 'PRODUCTS', 'LEDGER', 'CAMPAIGNS') as $c) {
        $t = adcval_t(constant('ADC_Analytics_Schema::' . $c));
        $engine = (string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=%s', $t));
        AdcVal::ok('InnoDB' === $engine, "table {$t} InnoDB", $engine);
    }
    foreach (array('COMMERCE', 'PRODUCTS') as $c) {
        $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . adcval_t(constant('ADC_Analytics_Schema::' . $c)));
        AdcVal::ok(array() === preg_grep('/order_id|email|user|ip|name|phone/i', $cols), "aggregate {$c} has no id/PII-like column", implode(',', $cols));
    }
    $idx = $wpdb->get_results('SHOW INDEX FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER), ARRAY_A);
    $uniq = array_filter($idx, static fn($r) => 'order_event' === $r['Key_name'] && '0' === (string) $r['Non_unique']);
    AdcVal::ok(2 === count($uniq), 'ledger UNIQUE(order_id,event_key) present');
    AdcVal::ok(class_exists('ADC_Analytics_Commerce') && has_action('woocommerce_new_order', array('ADC_Analytics_Commerce', 'on_new_order')) !== false, 'commerce hooks registered');
    // Re-running the installer (re-activation / upgrade path) on existing tables.
    $keys = array('TRAFFIC', 'INTERACTIONS', 'COMMERCE', 'PRODUCTS', 'LEDGER', 'CAMPAIGNS');
    $shape = static function () use ($wpdb, $keys): array {
        $o = array();
        foreach ($keys as $c) {
            $t = adcval_t(constant('ADC_Analytics_Schema::' . $c));
            $o[$c] = (string) $wpdb->get_var("SELECT GROUP_CONCAT(CONCAT(column_name,':',column_type,':',is_nullable,':',IFNULL(column_default,'NULL'),':',extra) ORDER BY ordinal_position SEPARATOR '|') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='" . esc_sql($t) . "'");
        }
        return $o;
    };
    $before_shape = $shape();
    $log0 = adcval_logsize();
    ADC_Analytics_Schema::install();
    $errs = array_values(array_filter(explode("\n", adcval_log_since($log0)), static fn($l) => str_contains($l, 'WordPress database error')));
    AdcVal::ok(array() === $errs, 'Schema::install() on existing tables emits no SQL errors (dbDelta idempotent)', count($errs) . ' errors; first: ' . substr((string) ($errs[0] ?? ''), 0, 160));
    AdcVal::ok($before_shape === $shape(), 'Schema::install() leaves table structure unchanged');
    $lint = 0;
    $bad = array();
    foreach (glob(dirname(__DIR__, 2) . '/includes/analytics/*.php') ?: array() as $f) {
        $lint++;
        try {
            token_get_all((string) file_get_contents($f), TOKEN_PARSE);
        } catch (ParseError $e) {
            $bad[] = basename($f);
        }
    }
    AdcVal::ok(array() === $bad && $lint > 0, 'analytics files parse on this PHP', $lint . ' files');
}

/* ===== gates ===== */
if (in_array('gates', $groups, true)) {
    AdcVal::$group = 'gates';
    // Raw-stored toggles (bypassing sanitize) must not open hard-off gates.
    update_option('adc_settings_analytics', array(
        'enabled_traffic' => true, 'traffic_utm_enabled' => true, 'enabled_add_to_cart' => true, 'enabled_begin_checkout' => true,
        'enabled_commerce' => true, 'commerce_ack' => true,
    ));
    AdcVal::ok(!ADC_Analytics_Settings::traffic_enabled() && !ADC_Analytics_Settings::utm_enabled() && !ADC_Analytics_Settings::add_to_cart_enabled() && !ADC_Analytics_Settings::begin_checkout_enabled(), 'traffic/utm/add_to_cart/begin_checkout hard OFF with raw stored toggles true');
    $san = ADC_Analytics_Settings::sanitize(array('enabled_traffic' => '1', 'traffic_utm_enabled' => '1', 'enabled_add_to_cart' => '1', 'enabled_begin_checkout' => '1'), array());
    AdcVal::ok(false === $san['enabled_traffic'] && false === $san['traffic_utm_enabled'] && false === $san['enabled_add_to_cart'] && false === $san['enabled_begin_checkout'], 'sanitize forces hard-off toggles false');
    AdcVal::ok(false === ADC_Analytics_Settings::legal_gate_status('traffic')['enabled'], 'legal_gate_status(traffic) closed');

    delete_option('adc_settings_analytics');
    AdcVal::ok(false === ADC_Analytics_Settings::commerce_enabled(), 'commerce default OFF (no option)');
    adcval_settings(array('enabled_commerce' => true, 'commerce_ack' => false));
    AdcVal::ok(false === ADC_Analytics_Settings::commerce_enabled(), 'commerce OFF when enabled without ack');
    adcval_settings(array('enabled_commerce' => false, 'commerce_ack' => true));
    AdcVal::ok(false === ADC_Analytics_Settings::commerce_enabled(), 'commerce OFF when ack without enable');

    // No enqueue / no rows while gated, real WC order lifecycle.
    adcval_reset_tables();
    foreach (array(array(false, false), array(true, false), array(false, true)) as [$en, $ack]) {
        adcval_settings(array('enabled_commerce' => $en, 'commerce_ack' => $ack));
        $o = adcval_order();
        $o->set_status('processing');
        $o->save();
        $o->payment_complete();
        wc_create_refund(array('order_id' => $o->get_id(), 'amount' => 1.0));
    }
    global $wpdb;
    AdcVal::ok(0 === count(adcval_pending()), 'gated: zero analytics jobs enqueued across create/pay/refund', (string) count(adcval_pending()));
    AdcVal::ok(0 === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER)), 'gated: ledger empty');

    // Gate re-checked at process time.
    adcval_gate_on();
    $o = adcval_order();
    $o->set_status('processing');
    $o->save();
    $enq = count(adcval_pending());
    AdcVal::ok($enq > 0, 'gate on: hooks enqueue jobs', (string) $enq);
    adcval_settings(array('enabled_commerce' => true, 'commerce_ack' => false));
    adcval_drain();
    AdcVal::ok(0 === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER)), 'gate closed before drain: jobs run but write nothing (re-check at process time)');
    adcval_gate_on();
    adcval_settings(array());
    AdcVal::ok('commerce-disabled' === ADC_Analytics_Reconciler::rebuild_day($today)['reason'], 'rebuild_day refuses when gate closed');
}

/* ===== lifecycle ===== */
if (in_array('lifecycle', $groups, true)) {
    AdcVal::$group = 'lifecycle';
    adcval_reset_tables();
    adcval_gate_on();
    global $wpdb;
    $base_today = adcval_base_or_null($today, 'RON');
    $hp = OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts';
    AdcVal::info("order storage: {$hp}");

    $o = adcval_order('RON');
    $oid = $o->get_id();
    $o->set_status('on-hold');
    $o->save();
    $o->set_status('processing');
    $o->save();
    $o->payment_complete('adcval-txn');            // SIMULATED gateway confirmation (real WC API)
    $o->set_status('completed');
    $o->save();
    $o->set_status('processing');
    $o->save();
    $o->set_status('completed');
    $o->save();
    $pending = adcval_pending();
    $mins = array_map(static fn($a) => $a->get_schedule()->get_date()->getTimestamp() - time(), $pending);
    AdcVal::ok(count($pending) > 0 && min($mins) >= 50, 'jobs are delayed (async), nothing applied inline', 'jobs=' . count($pending) . ' min_delay_s=' . min($mins ?: array(0)));
    AdcVal::ok(0 === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER)), 'no ledger writes before drain (checkout/payment request does not write)');
    $ran = adcval_drain();
    AdcVal::ok($ran > 0, 'Action Scheduler runner drained jobs', (string) $ran);
    AdcVal::ok(0 === count(adcval_pending()), 'queue empty after drain (no retries needed)');

    AdcVal::ok(1 === adcval_ledger_count($oid, 'order_created'), 'order_created ledger = 1');
    AdcVal::ok(1 === adcval_ledger_count($oid, 'order_paid'), 'order_paid ledger = 1 despite 2 paid-status entries + payment_complete');
    $d = wp_date('Y-m-d', (int) $o->get_date_paid()->getTimestamp());
    AdcVal::ok(1 === adcval_metric($d, 'RON', 'orders_paid')['count'], 'orders_paid = 1 on paid date', $d);
    AdcVal::ok(1 === adcval_metric($d, 'RON', 'orders_created')['count'] || 1 === adcval_metric(wp_date('Y-m-d', (int) $o->get_date_created()->getTimestamp()), 'RON', 'orders_created')['count'], 'orders_created = 1');
    $exp = array('order_total' => 52.35, 'tax_total' => 2.85, 'shipping_total' => 5.0, 'shipping_tax' => 0.95, 'discount_total' => 2.0, 'discount_tax' => 0.38, 'items_net' => 45.5);
    $fresh = wc_get_order($oid);
    AdcVal::ok(abs((float) $fresh->get_total_tax() - 2.85) < 1e-6, 'fixture sanity: reloaded order tax 2.85 (storage round trip)', $hp);
    foreach ($exp as $m => $v) {
        $a = adcval_metric($d, 'RON', $m)['amount'];
        AdcVal::ok(abs($a - $v) < 0.000001, "metric {$m} = {$v}", (string) $a);
    }
    AdcVal::ok(3 === adcval_metric($d, 'RON', 'units')['count'], 'units = 3', (string) adcval_metric($d, 'RON', 'units')['count']);
    foreach (array('on-hold', 'processing', 'completed') as $st) {
        $c = (int) adcval_metric($d, 'RON', 'status_reached', $st)['count'];
        AdcVal::ok(1 === $c, "status_reached:{$st} counted once for first reach", (string) $c);
    }

    // Partial refunds (two) with line items, then replay.
    [$a, $b] = adcval_products();
    $items = $fresh->get_items();
    $alpha = null;
    foreach ($items as $it) {
        if ((int) $it->get_product_id() === $a->get_id()) {
            $alpha = $it;
        }
    }
    $r1 = wc_create_refund(array('order_id' => $oid, 'amount' => 11.0, 'line_items' => array($alpha->get_id() => array('qty' => 1, 'refund_total' => 10.0, 'refund_tax' => array()))));
    $r2 = wc_create_refund(array('order_id' => $oid, 'amount' => 3.5));
    AdcVal::ok($r1 instanceof WC_Order_Refund && $r2 instanceof WC_Order_Refund, 'two real refunds created');
    adcval_drain();
    $rd = wp_date('Y-m-d', (int) $r1->get_date_created()->getTimestamp());
    AdcVal::ok(abs(adcval_metric($rd, 'RON', 'refund_total')['amount'] - 14.5) < 1e-6, 'refund_total = 14.50 (partial refunds accumulate)', (string) adcval_metric($rd, 'RON', 'refund_total')['amount']);
    AdcVal::ok(1 === adcval_ledger_count($oid, 'refund:' . $r1->get_id()) && 1 === adcval_ledger_count($oid, 'refund:' . $r2->get_id()), 'one ledger row per refund id');
    $prow = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . adcval_t(ADC_Analytics_Schema::PRODUCTS) . ' WHERE currency=%s AND product_id=%d', 'RON', $a->get_id()), ARRAY_A);
    AdcVal::ok($prow && 2 === (int) $prow['units'] && 1 === (int) $prow['refund_units'] && abs((float) $prow['refund_net'] - 10.0) < 1e-6, 'product row: units 2, refund_units 1, refund_net 10', json_encode($prow));
    AdcVal::ok(1 === adcval_metric($d, 'RON', 'orders_paid')['count'], 'refund did not change paid count');

    // Reconciliation: this run's contribution to (aggregates - orders) is zero (baseline isolates leftover orders from earlier runs).
    if (null === $base_today) {
        AdcVal::skip('compare_day delta vs baseline after lifecycle (busy day)', 'today holds more than the 2000-order reconciler bound in this long-lived disposable DB; isolated-day reconciliation below covers it');
    } else {
        adcval_delta_check('compare_day delta vs baseline is zero after lifecycle', $base_today, $today, 'RON');
    }

    // Second currency separation.
    $e = adcval_order('EUR', array('total' => 52.35));
    $e->set_status('processing');
    $e->save();
    adcval_drain();
    AdcVal::ok(abs(adcval_metric($d, 'EUR', 'order_total')['amount'] - 52.35) < 1e-6 && abs(adcval_metric($d, 'RON', 'order_total')['amount'] - 52.35) < 1e-6, 'EUR and RON buckets separate, not mixed');

    // Cancel / failed orders: created counted, never paid.
    $c = adcval_order('RON');
    $c->set_status('cancelled');
    $c->save();
    $f = adcval_order('RON');
    $f->set_status('failed');
    $f->save();
    adcval_drain();
    AdcVal::ok(0 === adcval_ledger_count($c->get_id(), 'order_paid') && 0 === adcval_ledger_count($f->get_id(), 'order_paid'), 'cancelled/failed never counted as paid');

    // Date boundary in a non-UTC site timezone (Bucharest = UTC+3 in October).
    update_option('timezone_string', 'Europe/Bucharest');
    update_option('gmt_offset', 0);
    $cases = array(
        array('2026-10-14 21:30:00', '2026-10-15'),   // 00:30 local next day
        array('2026-10-14 20:59:59', '2026-10-14'),   // 23:59:59 local
        array('2026-10-14 21:00:00', '2026-10-15'),   // exactly local midnight
    );
    foreach ($cases as [$utc, $local]) {
        $b = adcval_order('RON');
        $b->set_date_created(new WC_DateTime($utc, new DateTimeZone('UTC')));
        $b->set_date_paid(new WC_DateTime($utc, new DateTimeZone('UTC')));
        $b->set_status('processing');
        $b->save();
        adcval_drain();
        $got = adcval_metric($local, 'RON', 'orders_paid')['count'];
        $ledger = (string) $wpdb->get_var($wpdb->prepare('SELECT stat_date FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER) . " WHERE order_id=%d AND event_key='order_paid'", $b->get_id()));
        AdcVal::ok($ledger === $local, "paid {$utc} UTC lands on site-local {$local}", $ledger);
    }
    update_option('timezone_string', (string) $saved_tz[0]);
    update_option('gmt_offset', $saved_tz[1]);

    // Reconciliation / rebuild on an isolated synthetic day (do not trust the claim: corrupt, detect, repair).
    $rday = adcval_runday();
    $ro = array();
    foreach (array(true, true, true, false) as $paid) {
        $ro[] = adcval_dated(adcval_order('RON'), $rday, $paid);
    }
    [$ra, $rb_] = adcval_products();
    $rrf = wc_create_refund(array('order_id' => $ro[0]->get_id(), 'amount' => 7.25));
    $rrf->set_date_created(new WC_DateTime($rday . ' 13:00:00', new DateTimeZone('UTC')));
    $rrf->save();
    adcval_drain();
    $diff = adcval_compare($rday, 'RON');
    $max = 0.0;
    foreach ($diff as $v) {
        $max = max($max, abs((float) $v));
    }
    AdcVal::ok($max < 0.01, "compare_day({$rday}) zero diff on an isolated day (3 paid, 1 pending, 1 refund)", (string) $max . ' paid=' . adcval_metric($rday, 'RON', 'orders_paid')['count'] . ' created=' . adcval_metric($rday, 'RON', 'orders_created')['count']);
    AdcVal::ok(3 === adcval_metric($rday, 'RON', 'orders_paid')['count'] && 4 === adcval_metric($rday, 'RON', 'orders_created')['count'], 'isolated day counts: paid 3, created 4');
    $before = adcval_metric($rday, 'RON', 'order_total')['amount'];
    $wpdb->query($wpdb->prepare('UPDATE ' . adcval_t(ADC_Analytics_Schema::COMMERCE) . " SET amount = amount + 777 WHERE stat_date=%s AND currency='RON' AND metric='order_total' AND order_status=''", $rday));
    $diff = adcval_compare($rday, 'RON');
    AdcVal::ok(abs((float) ($diff['order_total'] ?? 0) - 777.0) < 1e-6, 'compare_day detects injected corruption (+777)', (string) ($diff['order_total'] ?? 'n/a'));
    $rb = ADC_Analytics_Reconciler::rebuild_day($rday);
    AdcVal::ok($rb['ok'], 'rebuild_day ok', json_encode(array($rb['reason'], $rb['orders'], $rb['products'])));
    AdcVal::ok(abs(adcval_metric($rday, 'RON', 'order_total')['amount'] - $before) < 1e-6, 'rebuild restored order_total from orders', (string) adcval_metric($rday, 'RON', 'order_total')['amount']);
    AdcVal::ok(3 === adcval_metric($rday, 'RON', 'orders_paid')['count'] && abs(adcval_metric($rday, 'RON', 'refund_total')['amount'] - 7.25) < 1e-6, 'rebuild kept counts and refund_total');
    AdcVal::ok(1 === adcval_ledger_count($ro[0]->get_id(), 'order_paid'), 'rebuild left ledger intact');
    $max = 0.0;
    foreach (adcval_compare($rday, 'RON') as $v) {
        $max = max($max, abs((float) $v));
    }
    AdcVal::ok($max < 0.01, 'compare_day zero diff after rebuild', (string) $max);
    // Documented v1 limit: refund deleted after counting -> repair via rebuild.
    $r3 = wc_create_refund(array('order_id' => $ro[1]->get_id(), 'amount' => 1.0));
    $r3->set_date_created(new WC_DateTime($rday . ' 14:00:00', new DateTimeZone('UTC')));
    $r3->save();
    adcval_drain();
    $with = adcval_metric($rday, 'RON', 'refund_total')['amount'];
    $r3->delete(true);
    $still = adcval_metric($rday, 'RON', 'refund_total')['amount'];
    AdcVal::ok(abs($still - $with) < 1e-6 && abs($with - 8.25) < 1e-6, 'deleted refund NOT auto-reversed (documented v1 behaviour)', "{$with} -> {$still}");
    ADC_Analytics_Reconciler::rebuild_day($rday);
    $fixed = adcval_metric($rday, 'RON', 'refund_total')['amount'];
    AdcVal::ok(abs($fixed - 7.25) < 1e-6, 'rebuild_day repairs the deleted refund', (string) $fixed);
    // Day window edges (site-local): an order at 23:59:59 and one at 00:00:00 next day belong to different days.
    adcval_runday(); // skip one day so edge+1 is guaranteed empty
    $edge = adcval_runday();
    $nextday = wp_date('Y-m-d', strtotime($edge . ' +1 day'));
    foreach (array(array($edge . ' 23:59:59', $edge), array($nextday . ' 00:00:00', $nextday)) as [$ts, $expect]) {
        $x = adcval_order('RON');
        $when = new WC_DateTime($ts, new DateTimeZone('UTC'));
        $x->set_date_created($when);
        $x->set_date_paid($when);
        $x->set_status('processing');
        $x->save();
    }
    adcval_drain();
    $m1 = adcval_metric($edge, 'RON', 'orders_paid')['count'];
    $m2 = adcval_metric($nextday, 'RON', 'orders_paid')['count'];
    AdcVal::ok(1 === $m1 && 1 === $m2, 'orders at 23:59:59 and 00:00:00 land on adjacent days', "{$m1}/{$m2}");
    foreach (array($edge, $nextday) as $dday) {
        $max = 0.0;
        foreach (adcval_compare($dday, 'RON') as $v) {
            $max = max($max, abs((float) $v));
        }
        AdcVal::ok($max < 0.01, "compare_day({$dday}) edge day zero diff (reconciler window matches job date)", (string) $max);
    }

    // Bounds.
    AdcVal::ok('invalid-date' === ADC_Analytics_Reconciler::rebuild_day(wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS))['reason'], 'rebuild refuses future date');
    AdcVal::ok('beyond-retention' === ADC_Analytics_Reconciler::rebuild_day('2001-01-01')['reason'], 'rebuild refuses beyond retention');
    AdcVal::ok('invalid-date' === ADC_Analytics_Reconciler::rebuild_day('2026-02-31')['reason'], 'rebuild refuses impossible date');
}

/* ===== dedupe ===== */
if (in_array('dedupe', $groups, true)) {
    AdcVal::$group = 'dedupe';
    adcval_reset_tables();
    adcval_gate_on();
    global $wpdb;
    $o = adcval_order('RON');
    $o->set_status('processing');
    $o->set_date_paid(time());
    $o->save();
    $id = $o->get_id();
    for ($i = 0; $i < 6; $i++) {
        ADC_Analytics_Commerce::process('order_paid', $id);
        ADC_Analytics_Commerce::process('order_created', $id);
    }
    AdcVal::ok(1 === adcval_metric($today, 'RON', 'orders_paid')['count'], '6 sequential replays => orders_paid 1');
    AdcVal::ok(abs(adcval_metric($today, 'RON', 'order_total')['amount'] - 52.35) < 1e-6, '6 replays => order_total once');
    // Hostile / malformed events never write.
    $n0 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER));
    foreach (array(array('order_paid', 0), array('order_paid', 999999999), array('bogus', $id), array('refund:abc', $id), array('refund:999999', $id), array("order_paid' OR 1=1 --", $id), array('status_reached:' . str_repeat('x', 40), $id)) as [$k, $oid]) {
        ADC_Analytics_Commerce::process($k, $oid);
    }
    $n1 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER));
    AdcVal::ok($n0 === $n1, 'malformed/unknown events claim no ledger rows', "{$n0} -> {$n1}");
    // Refund attributed to the wrong order is refused.
    $o2 = adcval_order('RON');
    $o2->set_status('processing');
    $o2->save();
    $rf = wc_create_refund(array('order_id' => $id, 'amount' => 2.0));
    ADC_Analytics_Commerce::process('refund:' . $rf->get_id(), $o2->get_id(), $rf->get_id());
    AdcVal::ok(0 === adcval_ledger_count($o2->get_id(), 'refund:' . $rf->get_id()), 'refund of order A cannot be claimed under order B');
    // Unpaid payment_complete soft-skip, then later paid transition counts once.
    $o3 = adcval_order('RON');
    ADC_Analytics_Commerce::process('order_paid', $o3->get_id());
    AdcVal::ok(0 === adcval_ledger_count($o3->get_id(), 'order_paid'), 'pending order with no paid date is not counted paid');
    $o3->set_status('completed');
    $o3->save();
    ADC_Analytics_Commerce::process('order_paid', $o3->get_id());
    ADC_Analytics_Commerce::process('order_paid', $o3->get_id());
    AdcVal::ok(1 === adcval_ledger_count($o3->get_id(), 'order_paid'), 'later paid transition counts once');
    // Blocks checkout-draft (WooCommerce's abandoned-cart status) is not a placed order; counted once when it becomes one.
    $dr = adcval_order('RON', array('status' => 'checkout-draft'));
    adcval_drain();
    AdcVal::ok('checkout-draft' === wc_get_order($dr->get_id())->get_status() && 0 === adcval_ledger_count($dr->get_id(), 'order_created'), 'checkout-draft order is not counted as orders_created', 'ledger=' . adcval_ledger_count($dr->get_id(), 'order_created'));
    AdcVal::ok(0 === adcval_ledger_count($dr->get_id(), 'status_reached:checkout-draft'), 'draft status fact is not recorded');
    $dr->set_status('on-hold');
    $dr->save();
    adcval_drain();
    AdcVal::ok(1 === adcval_ledger_count($dr->get_id(), 'order_created'), 'draft->placed transition counts orders_created exactly once', 'ledger=' . adcval_ledger_count($dr->get_id(), 'order_created'));
    $dr->set_status('processing');
    $dr->save();
    $dr->set_status('on-hold');
    $dr->save();
    adcval_drain();
    AdcVal::ok(1 === adcval_ledger_count($dr->get_id(), 'order_created'), 'later status changes never recount orders_created');
    $ad = adcval_order('RON', array('status' => 'auto-draft'));
    adcval_drain();
    AdcVal::ok(0 === adcval_ledger_count($ad->get_id(), 'order_created'), 'auto-draft order not counted');
    // Refund objects are never aggregated as orders.
    ADC_Analytics_Commerce::process('order_created', $rf->get_id());
    AdcVal::ok(0 === adcval_ledger_count($rf->get_id(), 'order_created'), 'refund id is never aggregated as an order');
}

/* ===== rollback (real InnoDB, injected DB-level fault via trigger) ===== */
if (in_array('rollback', $groups, true)) {
    AdcVal::$group = 'rollback';
    adcval_reset_tables();
    adcval_gate_on();
    global $wpdb;
    $agg = adcval_t(ADC_Analytics_Schema::COMMERCE);
    $trg = 'adcval_fail_trg';
    $wpdb->query("DROP TRIGGER IF EXISTS {$trg}");
    $ok = $wpdb->query("CREATE TRIGGER {$trg} BEFORE INSERT ON {$agg} FOR EACH ROW BEGIN IF NEW.currency = 'CHF' AND NEW.metric = 'order_total' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'adcval injected fault'; END IF; END");
    AdcVal::ok(false !== $ok, 'fault-injection trigger installed on disposable aggregate table');
    $o = adcval_order('CHF');
    $o->set_status('processing');
    $o->set_date_paid(time());
    $o->save();
    $id = $o->get_id();
    as_unschedule_all_actions('', array(), ADC_Analytics_Commerce::GROUP);
    $log0 = adcval_logsize();
    ADC_Analytics_Commerce::process('order_paid', $id, 0, '', 0);
    AdcVal::ok(0 === adcval_ledger_count($id, 'order_paid'), 'DB fault mid-transaction: ledger claim rolled back with aggregates');
    global $wpdb;
    $left = $wpdb->get_results($wpdb->prepare('SELECT stat_date,metric,amount,count FROM ' . adcval_t(ADC_Analytics_Schema::COMMERCE) . ' WHERE currency=%s', 'CHF'), ARRAY_A);
    AdcVal::ok(array() === $left, 'DB fault: no partial aggregate rows left for the faulted order', json_encode($left));
    AdcVal::ok(0 === adcval_metric($today, 'CHF', 'orders_paid')['count'] && 0.0 === adcval_metric($today, 'CHF', 'order_total')['amount'], 'DB fault: orders_paid/order_total metric lookups both zero (re-read through the metric helper)');
    $retry = adcval_pending();
    $att = array();
    foreach ($retry as $a) {
        $args = $a->get_args();
        $att[] = (int) ($args[4] ?? -1);
    }
    AdcVal::ok(array(1) === $att, 'transient failure re-queued with attempt=1', json_encode($att));
    AdcVal::ok('0' === (string) $wpdb->get_var("SELECT IS_USED_LOCK('" . esc_sql($wpdb->prefix . 'adc_analytics_day_' . $today) . "')") || null === $wpdb->get_var("SELECT IS_USED_LOCK('" . esc_sql($wpdb->prefix . 'adc_analytics_day_' . $today) . "')"), 'day lock released after rollback');
    // Retry ladder end: attempt 4 is the last, no further enqueue.
    as_unschedule_all_actions('', array(), ADC_Analytics_Commerce::GROUP);
    ADC_Analytics_Commerce::process('order_paid', $id, 0, '', 4);
    AdcVal::ok(0 === count(adcval_pending()), 'ladder exhausted at attempt 5: dropped (no new job)');
    AdcVal::ok(str_contains(adcval_log_since($log0), 'dropped after 5 attempts'), 'drop is logged without order id/PII');
    AdcVal::ok(!str_contains(adcval_log_since($log0), (string) $o->get_billing_email()), 'log carries no billing email');
    // Fault removed: replay applies exactly once (claim was not stranded).
    $wpdb->query("DROP TRIGGER IF EXISTS {$trg}");
    ADC_Analytics_Commerce::process('order_paid', $id, 0, '', 1);
    ADC_Analytics_Commerce::process('order_paid', $id, 0, '', 1);
    AdcVal::ok(1 === adcval_ledger_count($id, 'order_paid') && 1 === adcval_metric($today, 'CHF', 'orders_paid')['count'] && abs(adcval_metric($today, 'CHF', 'order_total')['amount'] - 52.35) < 1e-6, 'after fault cleared: applied exactly once');
    // Reconciler rollback: failure during replace keeps previous aggregates.
    adcval_reset_tables();
    $o = adcval_order('CHF');
    $o->set_status('processing');
    $o->set_date_paid(time());
    $o->save();
    $other = adcval_order('RON');
    $other->set_status('processing');
    $other->set_date_paid(time());
    $other->save();
    ADC_Analytics_Commerce::process('order_paid', $other->get_id());
    $before = adcval_metric($today, 'RON', 'order_total')['amount'];
    $wpdb->query("CREATE TRIGGER {$trg} BEFORE INSERT ON {$agg} FOR EACH ROW BEGIN IF NEW.currency = 'CHF' AND NEW.metric = 'order_total' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'adcval injected fault'; END IF; END");
    $rb = ADC_Analytics_Reconciler::rebuild_day($today);
    $wpdb->query("DROP TRIGGER IF EXISTS {$trg}");
    AdcVal::ok(false === $rb['ok'], 'rebuild with DB fault reports not ok', $rb['reason']);
    AdcVal::ok(abs(adcval_metric($today, 'RON', 'order_total')['amount'] - $before) < 1e-6, 'rebuild fault: previous aggregates preserved (atomic replace rolled back)', (string) adcval_metric($today, 'RON', 'order_total')['amount']);
    AdcVal::ok(null === $wpdb->get_var("SELECT TRIGGER_NAME FROM information_schema.triggers WHERE trigger_name='{$trg}'"), 'fault trigger removed (fixture clean)');
}

/* ===== concurrency (real parallel PHP processes against one InnoDB) ===== */
if (in_array('concurrency', $groups, true)) {
    AdcVal::$group = 'concurrency';
    adcval_reset_tables();
    adcval_gate_on();
    global $wpdb;
    $N = 8;
    $o = adcval_order('RON');
    $o->set_status('processing');
    $o->set_date_paid(time());
    $o->save();
    $id = $o->get_id();
    $r = wc_create_refund(array('order_id' => $id, 'amount' => 4.0));
    as_unschedule_all_actions('', array(), ADC_Analytics_Commerce::GROUP);
    $start = microtime(true) + 3.0;
    $procs = array();
    for ($i = 0; $i < $N; $i++) {
        $procs[] = adcval_php(array('worker' => 'process', 'payload' => json_encode(array('start' => $start, 'events' => array(array('order_created', $id), array('order_paid', $id), array('refund:' . $r->get_id(), $id, $r->get_id()))))));
    }
    $outs = array();
    foreach ($procs as [$p, $pipes]) {
        $outs[] = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
    }
    $times = array();
    foreach ($outs as $o_) {
        if (preg_match('/"elapsed":([0-9.]+)/', $o_, $m)) {
            $times[] = (float) $m[1];
        }
    }
    AdcVal::ok(count($times) === $N, "{$N} real worker processes completed", 'elapsed_s=' . implode(',', $times));
    $dd = wp_date('Y-m-d', (int) wc_get_order($id)->get_date_paid()->getTimestamp());
    AdcVal::ok(1 === adcval_ledger_count($id, 'order_paid') && 1 === adcval_ledger_count($id, 'order_created') && 1 === adcval_ledger_count($id, 'refund:' . $r->get_id()), "{$N}-way simultaneous duplicate: one ledger row per event");
    AdcVal::ok(1 === adcval_metric($dd, 'RON', 'orders_paid')['count'] && abs(adcval_metric($dd, 'RON', 'order_total')['amount'] - 52.35) < 1e-6 && abs(adcval_metric($dd, 'RON', 'refund_total')['amount'] - 4.0) < 1e-6, 'aggregates counted exactly once under contention', 'paid=' . adcval_metric($dd, 'RON', 'orders_paid')['count']);

    // Many distinct orders on an isolated day, 4 workers each handling a slice (+ cross-worker duplicates): totals must be exact.
    adcval_reset_tables();
    $cday = adcval_runday();
    $ids = array();
    for ($i = 0; $i < 24; $i++) {
        $x = adcval_dated(adcval_order('RON'), $cday, true);
        $ids[] = $x->get_id();
    }
    as_unschedule_all_actions('', array(), ADC_Analytics_Commerce::GROUP);
    $start = microtime(true) + 3.0;
    $procs = array();
    foreach (array_chunk($ids, 6) as $slice) {
        $ev = array(array('order_paid', $ids[0]));
        foreach ($slice as $sid) {
            $ev[] = array('order_paid', $sid);
            $ev[] = array('order_created', $sid);
        }
        $procs[] = adcval_php(array('worker' => 'process', 'payload' => json_encode(array('start' => $start, 'events' => $ev))));
    }
    foreach ($procs as [$p, $pipes]) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
    }
    AdcVal::ok(24 === adcval_metric($cday, 'RON', 'orders_paid')['count'], '24 orders over 4 parallel workers: orders_paid = 24', (string) adcval_metric($cday, 'RON', 'orders_paid')['count']);
    AdcVal::ok(abs(adcval_metric($cday, 'RON', 'order_total')['amount'] - 24 * 52.35) < 1e-6, 'order_total = 24 x 52.35 exactly', (string) adcval_metric($cday, 'RON', 'order_total')['amount']);
    AdcVal::ok(24 === adcval_metric($cday, 'RON', 'orders_created')['count'], 'orders_created = 24');
    $max = 0.0;
    foreach (adcval_compare($cday, 'RON') as $v) {
        $max = max($max, abs((float) $v));
    }
    AdcVal::ok($max < 0.01, 'compare_day zero diff on the isolated day after parallel apply', (string) $max);

    // Day lock serialization: another PROCESS holds the day lock.
    $flag = tempnam(sys_get_temp_dir(), 'adcval');
    [$hp, $hpipes] = adcval_php(array('worker' => 'holdlock', 'payload' => json_encode(array('start' => microtime(true), 'date' => $today, 'flag' => $flag, 'seconds' => 6))));
    $t = microtime(true);
    while ('held' !== (string) @file_get_contents($flag) && microtime(true) - $t < 15) {
        usleep(100000);
    }
    AdcVal::ok('held' === (string) @file_get_contents($flag), 'external process holds the day lock');
    AdcVal::ok(false === ADC_Analytics_Store::lock_day($today, 0), 'second process: lock_day refused while held (GET_LOCK serializes)');
    $rb = ADC_Analytics_Reconciler::rebuild_day($today);
    AdcVal::ok(false === $rb['ok'], 'rebuild_day does not run through a held day lock', $rb['reason']);
    $other = adcval_order('RON');
    $other->set_status('processing');
    $other->set_date_paid(time());
    $other->save();
    $t1 = microtime(true);
    ADC_Analytics_Commerce::process('order_paid', $other->get_id(), 0, '', 0);
    AdcVal::ok(0 === adcval_ledger_count($other->get_id(), 'order_paid'), 'job refused (not applied) while lock held; claim not stranded');
    AdcVal::ok(count(adcval_pending()) >= 1, 'refused job re-queued for retry');
    stream_get_contents($hpipes[1]);
    stream_get_contents($hpipes[2]);
    proc_close($hp);
    ADC_Analytics_Commerce::process('order_paid', $other->get_id(), 0, '', 1);
    AdcVal::ok(1 === adcval_ledger_count($other->get_id(), 'order_paid'), 'after lock released the retry applies');
    unlink($flag);
    // Different days do not block each other.
    $held = ADC_Analytics_Store::lock_day('2026-01-01', 0);
    [$pp, $ppipes] = adcval_php(array('worker' => 'lock_probe', 'payload' => json_encode(array('start' => microtime(true), 'date' => '2026-01-02', 'timeout' => 0))));
    $out = stream_get_contents($ppipes[1]);
    proc_close($pp);
    ADC_Analytics_Store::unlock_day('2026-01-01');
    AdcVal::ok($held && str_contains($out, '"lock":true'), 'lock is per stat_date (other day not blocked)');
    // Multisite isolation (prefix-scoped lock names): safe single-site proof only.
    $name_a = $wpdb->prefix . 'adc_analytics_day_' . $today;
    $name_b = 'wp_2_adc_analytics_day_' . $today;
    AdcVal::ok($name_a !== $name_b && '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name_b)), 'lock names embed table prefix (per-site isolation by construction)');
    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name_b));
    AdcVal::skip('real multisite network (sites 1 and 2 data isolation)', 'harness is single-site; converting to multisite not done in this pass');
}

/* ===== retention ===== */
if (in_array('retention', $groups, true)) {
    AdcVal::$group = 'retention';
    adcval_reset_tables();
    update_option('adc_settings_analytics', ADC_Analytics_Settings::sanitize(array('enabled_commerce' => true, 'commerce_ack' => true, 'retention_commerce_days' => 90, 'retention_ledger_days' => 200, 'retention_traffic_days' => 30), array()));
    AdcVal::ok(ADC_Analytics_Settings::retention_days('ledger') >= ADC_Analytics_Settings::retention_days('commerce'), 'ledger retention clamped >= commerce', ADC_Analytics_Settings::retention_days('ledger') . '/' . ADC_Analytics_Settings::retention_days('commerce'));
    global $wpdb;
    $old = wp_date('Y-m-d', time() - 400 * DAY_IN_SECONDS);
    $mid = wp_date('Y-m-d', time() - 100 * DAY_IN_SECONDS);
    $new = $today;
    foreach (array($old, $mid, $new) as $d) {
        ADC_Analytics_Store::apply_commerce_metric($d, 'RON', '', 'orders_paid', 0.0, 1);
        ADC_Analytics_Store::apply_product_metric($d, 'RON', 7, array('units' => 1));
        ADC_Analytics_Store::claim_ledger(900000 + (int) substr($d, 8), 'order_paid', 'processing', 'RON', 1.0, $d, $d . ' 10:00:00');
        $wpdb->insert(adcval_t(ADC_Analytics_Schema::TRAFFIC), array('stat_date' => $d, 'stat_hour' => 1, 'channel' => 'direct', 'page_type' => 'home', 'object_id' => 0, 'views' => 3, 'updated_at' => gmdate('Y-m-d H:i:s')));
    }
    ADC_Analytics_Retention::run();
    $cnt = static fn(string $k, string $d): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . adcval_t($k) . ' WHERE stat_date=%s', $d));
    AdcVal::ok(0 === $cnt(ADC_Analytics_Schema::COMMERCE, $old) && 0 === $cnt(ADC_Analytics_Schema::PRODUCTS, $old), 'commerce/products older than retention purged');
    AdcVal::ok(0 === $cnt(ADC_Analytics_Schema::COMMERCE, $mid) && 1 === $cnt(ADC_Analytics_Schema::COMMERCE, $new), 'purge boundary: 100d purged at 90d retention, today kept');
    AdcVal::ok(0 === $cnt(ADC_Analytics_Schema::TRAFFIC, $old), 'traffic older than retention purged');
    AdcVal::ok(0 === $cnt(ADC_Analytics_Schema::LEDGER, $old) && 1 === $cnt(ADC_Analytics_Schema::LEDGER, $mid) && 1 === $cnt(ADC_Analytics_Schema::LEDGER, $new), 'ledger purged by its own (>= commerce) window: 100d kept while commerce purged', 'old=' . $cnt(ADC_Analytics_Schema::LEDGER, $old) . ' mid=' . $cnt(ADC_Analytics_Schema::LEDGER, $mid));
    AdcVal::ok(0 === ADC_Analytics_Store::purge('bogus_table', $today) && 0 === ADC_Analytics_Store::purge(ADC_Analytics_Schema::COMMERCE, 'not-a-date'), 'purge rejects unknown table and bad date');
    ADC_Analytics_Retention::schedule();
    AdcVal::ok(false !== wp_next_scheduled(ADC_Analytics_Retention::HOOK), 'daily cleanup is scheduled', (string) wp_next_scheduled(ADC_Analytics_Retention::HOOK));
    // Campaign table is part of retention scope per Retention::run().
    $wpdb->insert(adcval_t(ADC_Analytics_Schema::CAMPAIGNS), array('stat_date' => $old, 'source' => 's', 'medium' => 'm', 'campaign' => 'c', 'views' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')));
    ADC_Analytics_Retention::run();
    AdcVal::ok(0 === $cnt(ADC_Analytics_Schema::CAMPAIGNS, $old), 'campaign table older than retention purged', 'rows=' . $cnt(ADC_Analytics_Schema::CAMPAIGNS, $old));
    adcval_reset_tables();
}

/* ===== privacy ===== */
if (in_array('privacy', $groups, true)) {
    AdcVal::$group = 'privacy';
    global $wpdb;
    adcval_reset_tables();
    // Raw-stored toggles true: front-end traffic must still write nothing.
    update_option('adc_settings_analytics', array('enabled_traffic' => true, 'traffic_utm_enabled' => true, 'enabled_add_to_cart' => true, 'enabled_begin_checkout' => true, 'enabled_commerce' => true, 'commerce_ack' => true));
    [$a] = adcval_products();
    $jar = tempnam(sys_get_temp_dir(), 'adcval');
    $urls = array('/', '/?utm_source=x&utm_medium=y&utm_campaign=z', '/?add-to-cart=' . $a->get_id(), '/?p=' . $a->get_id() . '&utm_source=q', '/shop/', '/cart/', '/checkout/');
    $ext = array();
    foreach ($urls as $u) {
        $r = adcval_http('GET', $u, array('jar' => $jar, 'follow' => true, 'headers' => array('Referer' => 'https://example.org/from?x=1')));
        preg_match_all('#(?:src|href)=["\'](https?://[^"\']+)#i', $r['body'], $m);
        foreach ($m[1] as $link) {
            $h = (string) parse_url($link, PHP_URL_HOST);
            if ('' !== $h && !in_array($h, array('127.0.0.1', 'localhost'), true) && !str_contains($link, 'w.org') && !str_contains($link, 'woocommerce.com')) {
                $ext[$h] = true;
            }
        }
        AdcVal::ok($r['code'] < 500, "GET {$u} no server error", (string) $r['code']);
    }
    foreach (array(ADC_Analytics_Schema::TRAFFIC, ADC_Analytics_Schema::INTERACTIONS, ADC_Analytics_Schema::CAMPAIGNS) as $k) {
        AdcVal::ok(0 === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t($k)), "no rows in {$k} after front-end hits with utm/referer/add-to-cart and raw toggles true");
    }
    $ext_with = array_keys($ext);

    // Cookies: A/B plugin on vs off on anonymous front end.
    $hosts_seen = array();
    $cookies = static function () use (&$hosts_seen): array {
        $names = array();
        foreach (array('/', '/shop/', '/cart/', '/checkout/') as $u) {
            $r = adcval_http('GET', $u, array('follow' => true));
            preg_match_all('#(?:src|href|action)=["\'](https?://[^"\']+)#i', $r['body'], $mh);
            foreach ($mh[1] as $link) {
                $hh = (string) parse_url($link, PHP_URL_HOST);
                if ('' !== $hh && !in_array($hh, array('127.0.0.1', 'localhost'), true)) {
                    $hosts_seen[$hh] = true;
                }
            }
            foreach ($r['headers']['set-cookie'] ?? array() as $c) {
                $names[explode('=', $c, 2)[0]] = true;
            }
        }
        ksort($names);
        return array_keys($names);
    };
    $hosts_seen = array();
    $with = $cookies();
    $hosts_with = array_keys($hosts_seen);
    $hosts_seen = array();
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    deactivate_plugins('adc-marketing/adc-marketing.php', true);
    $without = $cookies();
    $hosts_without = array_keys($hosts_seen);
    $act = activate_plugin('adc-marketing/adc-marketing.php');
    $norm = static fn(array $c): array => array_values(array_map(static fn($n) => preg_replace('/[0-9a-f]{32}/', 'H', $n), $c));
    $diff = array_diff($norm($with), $norm($without));
    AdcVal::ok(array() === $diff && !is_wp_error($act), 'plugin adds no cookies vs plugin deactivated', 'with=' . implode('|', $with) . ' without=' . implode('|', $without) . ' extra=' . implode('|', $diff));
    AdcVal::ok(array() === array_diff($hosts_with, $hosts_without) && array() === array_diff($ext_with, $hosts_without), 'plugin adds no external host to front-end HTML vs deactivated', 'with=' . implode('|', $hosts_with) . ' without=' . implode('|', $hosts_without));
    AdcVal::ok(array() === preg_grep('/adc|analytic|_ga|_fb/i', $with), 'no analytics-looking cookie name on anonymous visit');

    // No analytics script/beacon from this plugin in the front end.
    $home = adcval_http('GET', '/', array('follow' => true))['body'];
    AdcVal::ok(!preg_match('#sendBeacon|adc-analytics|adc_analytics|analytics-dashboard#i', $home), 'front-end HTML contains no analytics beacon/script');
    // Static scan: analytics module has no front-end JS, no HTTP client, no cookie/storage API.
    $bad = array();
    foreach (glob(dirname(__DIR__, 2) . '/includes/analytics/*.php') ?: array() as $f) {
        $src = (string) file_get_contents($f);
        foreach (array('wp_remote_', 'curl_', 'setcookie', 'localStorage', 'sessionStorage', 'sendBeacon', 'file_get_contents(\'http', 'wp_enqueue_script') as $needle) {
            if (str_contains($src, $needle)) {
                $bad[] = basename($f) . ':' . $needle;
            }
        }
    }
    AdcVal::ok(array() === $bad, 'analytics includes: no HTTP client / cookie / storage / enqueue_script', implode(',', $bad));

    // Outbound requests during a full commerce lifecycle.
    $http_calls = array();
    $hook = static function ($pre, $args, $url) use (&$http_calls) {
        $http_calls[] = (string) $url;
        return new WP_Error('adcval_blocked', 'blocked');
    };
    add_filter('pre_http_request', $hook, 1, 3);
    adcval_gate_on();
    $o = adcval_order('RON');
    $o->set_status('processing');
    $o->save();
    $o->payment_complete();
    wc_create_refund(array('order_id' => $o->get_id(), 'amount' => 1.0));
    adcval_drain();
    remove_filter('pre_http_request', $hook, 1);
    $ours = array_filter($http_calls, static fn($u) => !preg_match('#woocommerce|wordpress\.org|w\.org|wc-|automattic#i', $u));
    AdcVal::ok(array() === $ours, 'lifecycle + drain makes no outbound HTTP request of its own', implode(',', array_map(static fn($u) => (string) parse_url($u, PHP_URL_HOST), $http_calls)));

    // PII scan across analytics tables and options.
    $mail = (string) $o->get_billing_email();
    $dump = '';
    foreach (array('COMMERCE', 'PRODUCTS', 'LEDGER', 'TRAFFIC', 'INTERACTIONS', 'CAMPAIGNS') as $c) {
        $dump .= json_encode($wpdb->get_results('SELECT * FROM ' . adcval_t(constant('ADC_Analytics_Schema::' . $c)), ARRAY_A));
    }
    $dump .= json_encode(get_option('adc_settings_analytics'));
    AdcVal::ok(!str_contains($dump, $mail) && !preg_match('/@example\.test|Synthetic|127\.0\.0\.1|Mozilla/i', $dump), 'analytics storage holds no email/name/IP/user-agent');
    $ledger_cols = $wpdb->get_col('SHOW COLUMNS FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER));
    AdcVal::ok(array() === array_diff($ledger_cols, array('id', 'order_id', 'event_key', 'status', 'currency', 'amount', 'stat_date', 'occurred_at', 'created_at')), 'ledger columns limited to id/order/event/status/currency/amount/dates', implode(',', $ledger_cols));
    unlink($jar);
}

/* ===== http: authorization / nonce / CSRF / CSV guard / no public stats ===== */
if (in_array('http', $groups, true)) {
    AdcVal::$group = 'http';
    adcval_gate_on();
    adcval_reset_tables();
    $o = adcval_order('RON');
    $o->set_status('processing');
    $o->set_date_paid(time());
    $o->save();
    ADC_Analytics_Commerce::process('order_paid', $o->get_id());
    ADC_Analytics_Commerce::process('order_created', $o->get_id());
    $admin = (int) (get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'))[0] ?? 1);
    $wired = false !== has_action('admin_post_adc_analytics_export');
    AdcVal::ok($wired, 'CSV export handler is registered by the real plugin boot (admin_post_adc_analytics_export)', $wired ? 'yes' : 'NO: ADC_Analytics_Csv is not booted');
    $cust  = adcval_user('customer');
    $sub   = adcval_user('subscriber');
    $q = static fn(array $a): string => '/wp-admin/admin-post.php?' . http_build_query($a);
    $nonce = adcval_nonce_for($admin, ADC_Analytics_Csv::NONCE);
    $base = array('action' => ADC_Analytics_Csv::ACTION, 'report' => 'kpis', 'preset' => '30', 'currency' => 'RON');

    $r = adcval_http('GET', '/wp-admin/index.php', array('cookie' => adcval_cookie($admin)));
    AdcVal::ok(200 === $r['code'], 'control: generated admin session cookie is accepted by wp-admin', (string) $r['code']);
    $r = adcval_http('GET', '/wp-admin/index.php', array('cookie' => adcval_cookie($sub)));
    AdcVal::ok(in_array($r['code'], array(200, 302), true) && !str_contains($r['body'], 'wp-login.php?redirect_to'), 'control: subscriber session accepted (reaches profile/dashboard)', (string) $r['code']);
    $r = adcval_http('GET', $q($base));
    AdcVal::ok(!str_contains(implode(' ', $r['headers']['content-type'] ?? array()), 'csv') && !str_contains($r['body'], 'orders_paid'), 'anonymous: no CSV, no data', (string) $r['code']);
    $r = adcval_http('GET', $q($base + array('_wpnonce' => adcval_nonce_for($cust, ADC_Analytics_Csv::NONCE))), array('cookie' => adcval_cookie($cust)));
    AdcVal::ok(403 === $r['code'], 'customer with own valid nonce: 403 (capability checked first)', (string) $r['code']);
    $r = adcval_http('GET', $q($base + array('_wpnonce' => adcval_nonce_for($sub, ADC_Analytics_Csv::NONCE))), array('cookie' => adcval_cookie($sub)));
    AdcVal::ok(403 === $r['code'], 'subscriber: 403', (string) $r['code']);
    $r = adcval_http('GET', $q($base), array('cookie' => adcval_cookie($admin)));
    AdcVal::ok(in_array($r['code'], array(403), true) && !str_contains(implode(' ', $r['headers']['content-type'] ?? array()), 'csv'), 'admin without nonce: refused, no CSV', (string) $r['code']);
    $r = adcval_http('GET', $q($base + array('_wpnonce' => 'deadbeef00')), array('cookie' => adcval_cookie($admin)));
    AdcVal::ok(403 === $r['code'] && !str_contains(implode(' ', $r['headers']['content-type'] ?? array()), 'csv'), 'admin with forged nonce: 403', (string) $r['code']);
    $r = adcval_http('GET', $q($base + array('_wpnonce' => adcval_nonce_for($admin, 'adc_export_nonce'))), array('cookie' => adcval_cookie($admin)));
    AdcVal::ok(403 === $r['code'], 'admin with a nonce of a DIFFERENT action (cross-feature CSRF): 403', (string) $r['code']);
    $r = adcval_http('GET', $q(array_merge($base, array('report' => 'users', '_wpnonce' => $nonce))), array('cookie' => adcval_cookie($admin)));
    AdcVal::ok(400 === $r['code'], 'unknown report refused (allowlist)', (string) $r['code']);
    foreach (array('../../etc/passwd', "kpis' OR 1=1--", 'kpis;DROP TABLE x') as $evil) {
        $r = adcval_http('GET', $q(array_merge($base, array('report' => $evil, '_wpnonce' => $nonce))), array('cookie' => adcval_cookie($admin)));
        AdcVal::ok(400 === $r['code'], 'report injection string refused: ' . substr($evil, 0, 14), (string) $r['code']);
    }
    $log0 = adcval_logsize();
    $r = adcval_http('GET', $q(array_merge($base, array('currency' => "RON' OR '1'='1", 'product_id' => '1 UNION SELECT user_pass FROM wp_users', 'channel' => '<script>', '_wpnonce' => $nonce))), array('cookie' => adcval_cookie($admin)));
    AdcVal::ok($r['code'] < 500 && !str_contains($r['body'], 'user_pass') && !preg_match('/\$P\$|\$wp\$/', $r['body']) && !str_contains(adcval_log_since($log0), 'WordPress database error'), 'SQLi-style params: no leak, no DB error', (string) $r['code']);
    $reports = ADC_Analytics_Csv::allowed_reports();
    $okc = 0;
    foreach ($reports as $rep) {
        $r = adcval_http('GET', $q(array_merge($base, array('report' => $rep, '_wpnonce' => $nonce))), array('cookie' => adcval_cookie($admin)));
        $ct = implode(' ', $r['headers']['content-type'] ?? array());
        $cd = implode(' ', $r['headers']['content-disposition'] ?? array());
        if (200 === $r['code'] && str_contains($ct, 'csv') && str_contains($cd, 'attachment') && !str_contains($r['body'], '@example.test') && !preg_match('/order_id/i', $r['body'])) {
            $okc++;
        } else {
            AdcVal::info("report {$rep}: code={$r['code']} ct={$ct}");
        }
    }
    AdcVal::ok(count($reports) === $okc, 'authorized export streams CSV attachment for every allowlisted report, no PII/order_id', "{$okc}/" . count($reports));
    $r = adcval_http('GET', $q(array_merge($base, array('_wpnonce' => $nonce, 'report' => 'kpis'))), array('cookie' => adcval_cookie($admin)));
    AdcVal::info('CSV Cache-Control: ' . implode(' ', $r['headers']['cache-control'] ?? array()));
    // Formula injection in cells.
    foreach (array('=1+1', '+SUM(A1)', '-2+3', '@cmd', "\t=1", ' =1+1', "\r-1") as $v) {
        $c = ADC_Analytics_Csv::cell($v);
        AdcVal::ok(str_starts_with($c, "'") || !preg_match('/^[ \t\r\n]*[=+\-@]/', $c), 'CSV cell neutralised: ' . json_encode($v), json_encode($c));
    }
    // Gate closed: export must not hand out data.
    adcval_settings(array());
    $r = adcval_http('GET', $q(array_merge($base, array('_wpnonce' => $nonce))), array('cookie' => adcval_cookie($admin)));
    AdcVal::info('export with commerce gate closed: code=' . $r['code'] . ' bytes=' . strlen($r['body']) . ' contains_orders_paid=' . (str_contains($r['body'], 'orders_paid') ? 'yes' : 'no'));
    adcval_gate_on();

    // Settings save endpoint (admin shell): authz + CSRF only (UI flow is validated later).
    $save = '/wp-admin/admin-post.php?action=adc_save_tab&tab=analytics';
    $r = adcval_http('POST', $save, array('body' => array('enabled_commerce' => '1'), 'cookie' => adcval_cookie($sub)));
    AdcVal::ok(403 === $r['code'], 'settings save as subscriber: 403', (string) $r['code']);
    $r = adcval_http('POST', $save, array('body' => array('enabled_commerce' => '1'), 'cookie' => adcval_cookie($admin)));
    AdcVal::ok(403 === $r['code'], 'settings save as admin without nonce: 403', (string) $r['code']);
    $r = adcval_http('POST', $save, array('body' => array('enabled_commerce' => '1', '_adc_nonce' => adcval_nonce_for($admin, 'adc_save_tab_ga4')), 'cookie' => adcval_cookie($admin)));
    AdcVal::ok(403 === $r['code'], 'settings save with nonce of another tab: 403', (string) $r['code']);
    $r = adcval_http('GET', $save, array('cookie' => adcval_cookie($admin)));
    AdcVal::ok($r['code'] >= 400, 'settings save via GET refused', (string) $r['code']);
    // Admin page access.
    $r = adcval_http('GET', '/wp-admin/admin.php?page=adc-marketing&tab=analytics', array('cookie' => adcval_cookie($sub)));
    AdcVal::ok(in_array($r['code'], array(302, 403), true) && !str_contains($r['body'], 'orders_paid'), 'analytics admin tab not reachable by subscriber', (string) $r['code']);
    $r = adcval_http('GET', '/wp-admin/admin.php?page=adc-marketing&tab=analytics', array('cookie' => adcval_cookie($cust)));
    AdcVal::ok(in_array($r['code'], array(302, 403), true), 'analytics admin tab not reachable by customer', (string) $r['code']);
    $r = adcval_http('GET', '/wp-admin/admin.php?page=adc-marketing&tab=analytics');
    AdcVal::ok(302 === $r['code'] && str_contains(implode(' ', $r['headers']['location'] ?? array()), 'wp-login'), 'analytics admin tab redirects anonymous to login', (string) $r['code']);
    // No public statistics read: REST + admin-ajax.
    $routes = array_keys(rest_get_server()->get_routes());
    $hit = array_filter($routes, static fn($x) => (bool) preg_match('#analytic#i', $x) && !str_contains($x, 'wc-analytics'));
    AdcVal::ok(array() === $hit, 'no REST route exposes analytics (wc-analytics is WooCommerce core)', implode(',', $hit));
    AdcVal::info('pre-existing /adc/v1 routes (consent/events, not analytics): ' . implode(',', array_filter($routes, static fn($x) => str_starts_with($x, '/adc/'))));
    global $wp_filter;
    $nopriv = array_filter(array_keys($wp_filter), static fn($h) => str_starts_with($h, 'wp_ajax_nopriv_') && preg_match('/adc|analytic/i', $h));
    AdcVal::ok(array() === $nopriv, 'no wp_ajax_nopriv_* analytics action', implode(',', $nopriv));
    $nopriv_post = array_filter(array_keys($wp_filter), static fn($h) => str_starts_with($h, 'admin_post_nopriv_') && preg_match('/analytic/i', $h));
    AdcVal::ok(array() === $nopriv_post, 'no admin_post_nopriv_* analytics action');
    $r = adcval_http('GET', '/wp-json/', array());
    AdcVal::ok(!preg_match('#adc[^"]{0,40}analytic|analytic[^"]{0,40}adc#i', $r['body']), 'REST index does not advertise ADC analytics');
    $r = adcval_http('GET', '/wp-admin/admin-ajax.php?action=adc_analytics_kpis');
    AdcVal::ok(!str_contains($r['body'], 'orders_paid') && !str_contains($r['body'], 'order_total'), 'admin-ajax anonymous analytics probe returns no stats', (string) $r['code']);
}

/* ===== checkout (real Classic AJAX + Store API/Blocks over HTTP) ===== */
if (in_array('checkout', $groups, true)) {
    AdcVal::$group = 'checkout';
    global $wpdb;
    adcval_gate_on();
    adcval_reset_tables();
    [$a] = adcval_products();
    update_option('woocommerce_bacs_settings', array('enabled' => 'yes', 'title' => 'BACS'));
    update_option('woocommerce_enable_guest_checkout', 'yes');
    update_option('woocommerce_calc_taxes', 'no');
    update_option('woocommerce_currency', 'RON');
    $saved_checkout = (int) get_option('woocommerce_checkout_page_id');
    $classic = wp_insert_post(array('post_title' => 'adcval classic checkout', 'post_name' => 'adcval-classic-checkout', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[woocommerce_checkout]'));
    update_option('woocommerce_checkout_page_id', $classic);
    $jar = tempnam(sys_get_temp_dir(), 'adcval');
    $log0 = adcval_logsize();
    $base_k = adcval_base_or_null($today, 'RON');
    adcval_http('GET', '/?add-to-cart=' . $a->get_id(), array('jar' => $jar, 'follow' => true));
    $page = adcval_http('GET', '/?page_id=' . $classic, array('jar' => $jar, 'follow' => true));
    $has_nonce = (bool) preg_match('/name="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"/', $page['body'], $m);
    AdcVal::ok($has_nonce, 'Classic checkout page rendered with process-checkout nonce', (string) $page['code']);
    if (!$has_nonce) {
        AdcVal::info('classic page text: ' . substr(trim(preg_replace('/\\s+/', ' ', wp_strip_all_tags(preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', '', $page['body'])))), 0, 300));
    }
    $orders_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER));
    $form = array(
        'billing_first_name' => 'Synthetic', 'billing_last_name' => 'Buyer', 'billing_country' => 'RO', 'billing_address_1' => 'Str Test 1',
        'billing_city' => 'Bucuresti', 'billing_state' => 'B', 'billing_postcode' => '010101', 'billing_phone' => '0700000000',
        'billing_email' => 'adcval-classic@example.test', 'payment_method' => 'bacs', 'terms' => 'on', 'terms-field' => '1',
        'woocommerce-process-checkout-nonce' => $m[1] ?? '', '_wp_http_referer' => '/', 'ship_to_different_address' => '0',
    );
    $t0 = microtime(true);
    $r = adcval_http('POST', '/?wc-ajax=checkout', array('jar' => $jar, 'body' => http_build_query($form), 'headers' => array('Content-Type' => 'application/x-www-form-urlencoded')));
    $dt = round((microtime(true) - $t0) * 1000);
    $j = json_decode(substr($r['body'], (int) strpos($r['body'], '{')), true);
    AdcVal::ok(is_array($j) && 'success' === ($j['result'] ?? ''), 'Classic checkout (real wc-ajax=checkout) placed order', 'ms=' . $dt . ' ' . substr(wp_strip_all_tags((string) ($j['messages'] ?? '')), 0, 120));
    $classic_id = 0;
    if (is_array($j) && !empty($j['redirect']) && preg_match('/order-received\/(\d+)/', (string) $j['redirect'], $mm)) {
        $classic_id = (int) $mm[1];
    }
    if (!$classic_id) {
        $classic_id = (int) (wc_get_orders(array('billing_email' => 'adcval-classic@example.test', 'limit' => 1, 'return' => 'ids'))[0] ?? 0);
    }
    AdcVal::ok($classic_id > 0, 'Classic order id resolved');
    $ledger_inline = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER));
    AdcVal::ok($ledger_inline === $orders_before, 'Classic checkout request wrote nothing to analytics (enqueue only)', "{$orders_before}->{$ledger_inline}");
    $pend = adcval_pending();
    AdcVal::ok(count($pend) > 0, 'Classic checkout enqueued async analytics job(s)', (string) count($pend));
    // Real async drain through AS HTTP loopback runner? use in-process runner on those actions.
    adcval_drain();
    AdcVal::ok($classic_id > 0 && 1 === adcval_ledger_count($classic_id, 'order_created'), 'Classic order_created applied after drain');
    AdcVal::ok($classic_id > 0 && 0 === adcval_ledger_count($classic_id, 'order_paid'), 'bacs on-hold order not counted as paid');
    $co = wc_get_order($classic_id);
    AdcVal::info('classic order status: ' . ($co ? $co->get_status() : 'n/a') . ' currency: ' . ($co ? $co->get_currency() : 'n/a'));
    if ($co) {
        $co->payment_complete('adcval-ipn');        // SIMULATED bank confirmation (IPN) through real WC API
        adcval_drain();
        AdcVal::ok(1 === adcval_ledger_count($classic_id, 'order_paid'), 'Classic order paid via simulated IPN counted once');
        wc_create_refund(array('order_id' => $classic_id, 'amount' => 1.5));
        adcval_drain();
    }

    // Blocks: Store API.
    update_option('woocommerce_checkout_page_id', $saved_checkout);
    $cart = adcval_http('GET', '/wp-json/wc/store/v1/cart');
    $token = $cart['headers']['cart-token'][0] ?? '';
    $nonce = $cart['headers']['nonce'][0] ?? '';
    AdcVal::ok('' !== $token, 'Store API issued Cart-Token', (string) $cart['code']);
    $h = array('Cart-Token' => $token, 'Nonce' => $nonce, 'Content-Type' => 'application/json');
    $add = adcval_http('POST', '/wp-json/wc/store/v1/cart/add-item', array('headers' => $h, 'body' => json_encode(array('id' => $a->get_id(), 'quantity' => 2))));
    AdcVal::ok(in_array($add['code'], array(200, 201), true), 'Store API add-item', (string) $add['code']);
    $drafts_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . adcval_t(ADC_Analytics_Schema::LEDGER) . " WHERE event_key='order_created'");
    $ck = adcval_http('PUT', '/wp-json/wc/store/v1/checkout?__experimental_calc_totals=true', array('headers' => $h, 'body' => json_encode(array('payment_method' => 'bacs'))));
    $draft_id = (int) (json_decode($ck['body'], true)['order_id'] ?? 0);
    if (!$draft_id) {
        $dr = wc_get_orders(array('status' => 'checkout-draft', 'limit' => 1, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'DESC'));
        $draft_id = (int) ($dr[0] ?? 0);
    }
    AdcVal::info('Store API PUT /checkout draft order id: ' . $draft_id . ' status: ' . ($draft_id ? (string) wc_get_order($draft_id)->get_status() : 'n/a'));
    adcval_drain();
    if (!$draft_id) {
        AdcVal::skip('abandoned Blocks draft order not counted', 'Store API PUT /checkout returned code ' . $ck['code'] . ' without a draft order id; scenario not reproduced');
    }
    if ($draft_id) {
        $draft_status = (string) wc_get_order($draft_id)->get_status();
        AdcVal::ok('checkout-draft' !== $draft_status || 0 === adcval_ledger_count($draft_id, 'order_created'), 'abandoned Blocks draft order (status ' . $draft_status . ') is NOT counted as orders_created', 'ledger_rows=' . adcval_ledger_count($draft_id, 'order_created'));
    }
    $body = array(
        'billing_address' => array('first_name' => 'Synthetic', 'last_name' => 'Blocks', 'address_1' => 'Str Test 2', 'city' => 'Bucuresti', 'state' => 'B', 'postcode' => '010101', 'country' => 'RO', 'email' => 'adcval-blocks@example.test', 'phone' => '0700000001'),
        'payment_method' => 'bacs', 'payment_data' => array(), 'customer_note' => '',
    );
    $t0 = microtime(true);
    $r = adcval_http('POST', '/wp-json/wc/store/v1/checkout', array('headers' => $h, 'body' => json_encode($body)));
    $dt = round((microtime(true) - $t0) * 1000);
    $jb = json_decode($r['body'], true);
    AdcVal::ok(200 === $r['code'] && !empty($jb['order_id']), 'Blocks Store API checkout placed order', 'ms=' . $dt . ' code=' . $r['code'] . ' ' . (isset($jb['message']) ? substr((string) $jb['message'], 0, 100) : ''));
    $bid = (int) ($jb['order_id'] ?? 0);
    if ($bid) {
        adcval_drain();
        AdcVal::ok(1 === adcval_ledger_count($bid, 'order_created'), 'Blocks order_created applied after drain', 'status=' . wc_get_order($bid)->get_status());
        AdcVal::ok(0 === adcval_ledger_count($bid, 'order_paid'), 'Blocks on-hold order not counted as paid');
        wc_get_order($bid)->payment_complete('adcval-ipn');       // SIMULATED
        adcval_drain();
        AdcVal::ok(1 === adcval_ledger_count($bid, 'order_paid'), 'Blocks order paid via simulated IPN counted once');
    }
    // Reconcile against real orders: this flow's contribution is zero.
    if (null === $base_k) {
        AdcVal::skip('compare_day delta after Classic+Blocks lifecycle (busy day)', 'today holds more than the 2000-order reconciler bound in this long-lived disposable DB; per-order ledger/aggregate assertions above still apply');
    } else {
        adcval_delta_check('compare_day delta vs baseline zero after Classic+Blocks lifecycle', $base_k, $today, 'RON');
    }
    $new_log = adcval_log_since($log0);
    AdcVal::ok(!str_contains($new_log, 'ADC_Analytics') || !preg_match('/(Fatal|Uncaught).*ADC_Analytics/', $new_log), 'no PHP fatal/uncaught from analytics during checkout flows');
    update_option('woocommerce_checkout_page_id', $saved_checkout);
    @unlink($jar);
}

/* ===== ui: real admin settings form + CSV exactly as the dashboard links hand them out ===== */
if (in_array('ui', $groups, true)) {
    AdcVal::$group = 'ui';
    global $wpdb;
    $admin = (int) (get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'))[0] ?? 1);
    $sub   = adcval_user('subscriber');
    $page_url = '/wp-admin/admin.php?page=adc-marketing&tab=analytics';
    $fetch = static fn(): array => adcval_http('GET', $page_url, array('cookie' => adcval_cookie($admin)));
    $form_of = static function (string $html): array {
        $d = new DOMDocument();
        @$d->loadHTML($html);
        $x = new DOMXPath($d);
        $out = array('nonce' => '', 'fields' => array());
        foreach ($x->query('//form[.//input[@name="_adc_nonce"] and .//input[@name="tab"][@value="analytics"]]//input') as $i) {
            $out['fields'][$i->getAttribute('name')] = $i->getAttribute('value');
        }
        $out['nonce'] = (string) ($out['fields']['_adc_nonce'] ?? '');
        return $out;
    };
    $sendform = static function (array $fields, int $user = 0) use ($admin): array {
        return adcval_http('POST', '/wp-admin/admin-post.php', array('cookie' => adcval_cookie($user ?: $admin), 'body' => http_build_query($fields)));
    };
    $opt = static function (): array {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('adc_settings_analytics', 'options');
        return (array) get_option('adc_settings_analytics', array());
    };

    $r = $fetch();
    AdcVal::ok(200 === $r['code'] && str_contains($r['body'], 'data-adc-analytics'), 'analytics tab renders for admin', (string) $r['code']);
    $f = $form_of($r['body']);
    AdcVal::ok('' !== $f['nonce'], 'settings form carries a nonce');
    $names = implode(',', array_keys($f['fields']));
    AdcVal::ok(!preg_match('/enabled_traffic|traffic_utm_enabled|enabled_add_to_cart|enabled_begin_checkout/', $names), 'settings form exposes no switch for hard-off collection', $names);
    $base = array('action' => 'adc_save_tab', 'tab' => 'analytics', '_adc_nonce' => $f['nonce']);
    $save_ok = static fn(array $r_): bool => in_array($r_['code'], array(302, 303), true);

    // Finance gating through the real form.
    adcval_settings(array());
    $r = $sendform($base + array('analytics' => array('enabled_commerce' => '1')));
    AdcVal::info('after enable-only POST: code=' . $r['code'] . ' loc=' . implode('', $r['headers']['location'] ?? array()) . ' stored=' . json_encode(array_intersect_key($opt(), array_flip(array('enabled_commerce', 'commerce_ack', 'paid_statuses', 'retention_commerce_days', 'min_cell_count')))) . ' body=' . substr(trim(wp_strip_all_tags($r['body'])), 0, 120));
    AdcVal::ok($save_ok($r) && !(array() !== $opt() && ADC_Analytics_Settings::commerce_enabled()) && !empty($opt()['enabled_commerce']), 'form: enable WITHOUT ack saved but commerce stays OFF', (string) $r['code']);
    $r = $sendform($base + array('analytics' => array('enabled_commerce' => '1', 'commerce_ack' => '1')));
    $opt();
    AdcVal::ok($save_ok($r) && ADC_Analytics_Settings::commerce_enabled(), 'form: enable + ack turns commerce ON');
    $r = $sendform($base + array('analytics' => array('commerce_ack' => '1')));
    AdcVal::ok($save_ok($r) && !(array() !== $opt() && ADC_Analytics_Settings::commerce_enabled()), 'form: unchecking enable turns commerce OFF');
    // Hard-off cannot be forced by crafted fields.
    $r = $sendform($base + array('analytics' => array('enabled_commerce' => '1', 'commerce_ack' => '1', 'enabled_traffic' => '1', 'traffic_utm_enabled' => '1', 'enabled_add_to_cart' => '1', 'enabled_begin_checkout' => '1', 'traffic_legal_basis' => 'lia', 'traffic_legal_reference' => 'DPO-1', 'traffic_approved_at' => '2026-01-01')));
    $o = $opt();
    AdcVal::ok(!ADC_Analytics_Settings::traffic_enabled() && !ADC_Analytics_Settings::utm_enabled() && !ADC_Analytics_Settings::add_to_cart_enabled() && !ADC_Analytics_Settings::begin_checkout_enabled() && empty($o['enabled_traffic']) && empty($o['traffic_utm_enabled']) && empty($o['enabled_add_to_cart']) && empty($o['enabled_begin_checkout']), 'crafted POST cannot open traffic/utm/add_to_cart/begin_checkout (even legal basis+reference+date supplied)');
    // Clamping / allowlists / XSS.
    $r = $sendform($base + array('analytics' => array('enabled_commerce' => '1', 'commerce_ack' => '1', 'retention_commerce_days' => '-5', 'retention_ledger_days' => '99999', 'retention_traffic_days' => '1', 'min_cell_count' => '1', 'paid_statuses' => array('refunded', 'pending', 'processing'), 'traffic_legal_reference' => '<script>alert(1)</script>"><img src=x onerror=alert(2)>')));
    $o = $opt();
    AdcVal::ok($o['retention_commerce_days'] >= 90 && $o['retention_commerce_days'] <= 3650 && $o['retention_ledger_days'] >= $o['retention_commerce_days'] && $o['retention_ledger_days'] <= 1095 + 3650 && $o['retention_traffic_days'] >= 30 && $o['min_cell_count'] >= 3, 'form: out-of-range retention/min cell clamped', json_encode(array($o['retention_commerce_days'], $o['retention_ledger_days'], $o['retention_traffic_days'], $o['min_cell_count'])));
    AdcVal::ok(array('processing') === array_values($o['paid_statuses']), 'form: paid_statuses restricted to allowlist', json_encode($o['paid_statuses']));
    $html = $fetch()['body'];
    AdcVal::ok(!str_contains($html, '<script>alert(1)') && !str_contains($html, 'onerror=alert(2)') && !str_contains($html, '<img src=x'), 'stored XSS payload is not rendered raw on the tab', substr((string) ($o['traffic_legal_reference'] ?? ''), 0, 40));
    // CSRF / authz on the real endpoint.
    $before = $opt();
    $r = $sendform(array('action' => 'adc_save_tab', 'tab' => 'analytics', 'analytics' => array('enabled_commerce' => '1', 'min_cell_count' => '50')));
    AdcVal::ok(403 === $r['code'] && $before === $opt(), 'POST without nonce: 403 and settings unchanged', (string) $r['code']);
    $r = $sendform($base + array('analytics' => array('min_cell_count' => '50')), $sub);
    AdcVal::ok(403 === $r['code'] && $before === $opt(), 'subscriber POST with admin nonce: 403 and settings unchanged', (string) $r['code']);
    $r = adcval_http('POST', '/wp-admin/admin-post.php', array('body' => http_build_query($base + array('analytics' => array('min_cell_count' => '50')))));
    AdcVal::ok(in_array($r['code'], array(400, 403, 302), true) && $before === $opt(), 'anonymous POST: settings unchanged', (string) $r['code']);

    // Restore seed-like state, then use the links exactly as the dashboard renders them.
    update_option('adc_settings_analytics', ADC_Analytics_Settings::sanitize(array('enabled_commerce' => true, 'commerce_ack' => true, 'min_cell_count' => 5), array()));
    adcval_reset_tables();
    $day0 = wp_date('Y-m-d', time() - 3 * DAY_IN_SECONDS);
    $mk = static function (int $n, string $day, string $cur): array {
        $ids = array();
        for ($i = 0; $i < $n; $i++) {
            $o_ = adcval_dated(adcval_order($cur), $day, true);
            $ids[] = $o_->get_id();
        }
        return $ids;
    };
    $mk(6, $day0, wc_get_base_location()['country'] ? get_woocommerce_currency() : 'RON');   // above threshold
    $mk(7, wp_date('Y-m-d', time() - 4 * DAY_IN_SECONDS), get_woocommerce_currency());                  // second above-threshold day (complementary suppression hides the smallest one)
    $thin = wp_date('Y-m-d', time() - 5 * DAY_IN_SECONDS);
    $mk(2, $thin, get_woocommerce_currency());                                                  // below threshold
    adcval_drain();
    $shop = get_woocommerce_currency();
    $html = $fetch()['body'];
    preg_match_all('#href="([^"]*admin-post\.php\?action=adc_analytics_export[^"]*)"#', $html, $mm);
    $links = array_values(array_unique(array_map(static fn($u) => html_entity_decode($u, ENT_QUOTES), $mm[1])));
    AdcVal::ok(count($links) >= 3, 'dashboard renders export links', (string) count($links));
    $csv = array();
    foreach ($links as $u) {
        parse_str((string) parse_url($u, PHP_URL_QUERY), $qs);
        $r = adcval_http('GET', $u, array('cookie' => adcval_cookie($admin)));
        $b = $r['body'];
        $ct = implode(' ', $r['headers']['content-type'] ?? array());
        $cd = implode(' ', $r['headers']['content-disposition'] ?? array());
        $rep = (string) ($qs['report'] ?? '');
        AdcVal::ok(200 === $r['code'] && str_contains($ct, 'text/csv') && str_contains($cd, 'attachment') && 'nosniff' === ($r['headers']['x-content-type-options'][0] ?? ''), "export {$rep}: 200 text/csv attachment nosniff", $r['code'] . ' ' . $cd);
        AdcVal::ok("\xEF\xBB\xBF" === substr($b, 0, 3), "export {$rep}: UTF-8 BOM");
        AdcVal::ok(!preg_match('/<\/?(html|body|div|script|table|form)\b|<!DOCTYPE|wp-admin|Fatal error|Warning:|Notice:/i', $b), "export {$rep}: no HTML/PHP-error tail");
        $lines = preg_split('/\r?\n/', trim(substr($b, 3)));
        $semi = array_filter($lines, static fn($l) => str_contains($l, ';'));
        AdcVal::ok(count($semi) === count($lines) && !str_contains($lines[0], ','), "export {$rep}: semicolon-delimited rows", count($lines) . ' lines');
        AdcVal::ok(!preg_match('/@example\.test|order_id|billing|Synthetic|\b127\.0\.0\.1\b/i', $b), "export {$rep}: no identifiers/PII");
        $csv[$rep] = array($qs, $lines);
    }
    // Exactly what the user downloads (default currency link) must equal the DB for the same filters.
    $sum = static fn(string $metric, string $col): float => (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(' . $col . '),0) FROM ' . adcval_t(ADC_Analytics_Schema::COMMERCE) . ' WHERE currency=%s AND metric=%s AND order_status=%s AND stat_date BETWEEN %s AND %s', $shop, $metric, '', wp_date('Y-m-d', time() - 29 * DAY_IN_SECONDS), wp_date('Y-m-d')));
    if (isset($csv['kpis'])) {
        $kv = array();
        foreach ($csv['kpis'][1] as $l) {
            $c = str_getcsv($l, ';', '"', '');
            $kv[$c[0]] = $c[1] ?? '';
        }
        $num = static fn(string $v): float => (float) str_replace(',', '.', $v);
        AdcVal::ok($shop === ($kv['Monedă'] ?? ''), 'kpis export (dashboard link, default currency) carries the shop currency', 'got "' . ($kv['Monedă'] ?? '') . '"');
        AdcVal::ok((int) $sum('orders_paid', 'count') === (int) ($kv['Comenzi plătite'] ?? -1), 'kpis export paid orders == DB', 'csv=' . ($kv['Comenzi plătite'] ?? 'n/a') . ' db=' . $sum('orders_paid', 'count'));
        AdcVal::ok(abs($sum('order_total', 'amount') - $num($kv['Venit brut'] ?? '-1')) < 0.01, 'kpis export gross revenue == DB', 'csv=' . ($kv['Venit brut'] ?? 'n/a') . ' db=' . $sum('order_total', 'amount'));
    } else {
        AdcVal::ok(false, 'kpis export link present on dashboard');
    }
    if (isset($csv['commerce_series'])) {
        $vis = 0;
        $leak = array();
        foreach ($csv['commerce_series'][1] as $l) {
            $c = str_getcsv($l, ';', '"', '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $c[0] ?? '')) {
                continue;
            }
            $paid_db = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(count),0) FROM ' . adcval_t(ADC_Analytics_Schema::COMMERCE) . " WHERE stat_date=%s AND currency=%s AND metric='orders_paid'", $c[0], $shop));
            if ($paid_db >= 5 && '' !== ($c[3] ?? '')) {
                $vis++;
                if ((string) $paid_db !== ($c[3] ?? '')) {
                    $leak[] = $c[0] . ' shown ' . ($c[3] ?? '') . ' db ' . $paid_db;
                }
            } elseif ($paid_db > 0 && '' !== ($c[3] ?? '')) {
                $leak[] = $c[0] . ' below-threshold value exposed: ' . $c[3];
            }
        }
        AdcVal::ok(array() === $leak && $vis >= 1, 'commerce_series export (dashboard link): shown days equal DB, below-threshold days blank (complementary suppression may hide more)', implode(' | ', $leak) . " visible={$vis}");
    } else {
        AdcVal::ok(false, 'commerce_series export link present on dashboard');
    }
    if (isset($csv['top_products'])) {
        $bad = array();
        foreach ($csv['top_products'][1] as $l) {
            $c = str_getcsv($l, ';', '"', '');
            if (!ctype_digit((string) ($c[0] ?? ''))) {
                continue;
            }
            $u_db = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(units),0) FROM ' . adcval_t(ADC_Analytics_Schema::PRODUCTS) . ' WHERE product_id=%d AND currency=%s', (int) $c[0], $shop));
            if ($u_db >= 5 && (string) $u_db !== ($c[3] ?? '')) {
                $bad[] = $c[0] . ' csv ' . ($c[3] ?? '') . ' db ' . $u_db;
            }
            if ($u_db < 5 && '' !== ($c[3] ?? '')) {
                $bad[] = $c[0] . ' below threshold exposed ' . ($c[3] ?? '');
            }
        }
        AdcVal::ok(array() === $bad, 'top_products export (dashboard link): units equal DB, below-threshold suppressed', implode(' | ', $bad));
    }
    // Rendered page parity (HTML as the browser gets it): KPI + chart numbers vs DB, same default filters.
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $text = preg_replace('/\s+/', ' ', $d->textContent);
    $paid_db = (int) $sum('orders_paid', 'count');
    AdcVal::ok(1 === preg_match('/Comenzi plătite ' . $paid_db . '\b/u', $text), 'rendered KPI "Comenzi plătite" == DB', 'db=' . $paid_db);
    $gross_db = $sum('order_total', 'amount');
    AdcVal::ok(str_contains($text, number_format($gross_db, 2, '.', ',')), 'rendered KPI gross revenue == DB', number_format($gross_db, 2, '.', ','));
    // --- final-recheck regressions -------------------------------------------------------------
    $page_text = static function (string $url) use ($admin): string {
        $h = adcval_http('GET', $url, array('cookie' => adcval_cookie($admin)))['body'];
        $dd = new DOMDocument();
        @$dd->loadHTML('<?xml encoding="utf-8"?>' . $h);
        return (string) preg_replace('/\s+/', ' ', $dd->textContent);
    };
    $win_from = wp_date('Y-m-d', time() - 29 * DAY_IN_SECONDS);
    $win_to   = wp_date('Y-m-d');
    $db_units = static fn(string $cur, int $pid = 0): int => $pid
        ? (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(units),0) FROM ' . adcval_t(ADC_Analytics_Schema::PRODUCTS) . ' WHERE currency=%s AND product_id=%d AND stat_date BETWEEN %s AND %s', $cur, $pid, $win_from, $win_to))
        : (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(count),0) FROM ' . adcval_t(ADC_Analytics_Schema::COMMERCE) . " WHERE currency=%s AND metric='units' AND stat_date BETWEEN %s AND %s", $cur, $win_from, $win_to));
    $txt = $page_text($page_url);
    $u_db = $db_units($shop);
    AdcVal::ok($u_db > 0 && 1 === preg_match('/Bucăți vândute ' . $u_db . '\b/u', $txt), 'units tile equals DB (complete window, default currency)', 'db=' . $u_db);
    [$pa, $pb] = adcval_products();
    foreach (array($pa, $pb) as $pr) {
        $pu = $db_units($shop, $pr->get_id());
        $t2 = $page_text($page_url . '&product=' . $pr->get_id());
        AdcVal::ok($pu >= 5 ? 1 === preg_match('/Bucăți vândute ' . $pu . '\b/u', $t2) : !preg_match('/Bucăți vândute ' . $pu . '\b/u', $t2), 'units tile with product filter ' . $pr->get_id() . ' equals products_daily (or hidden when under threshold)', 'db=' . $pu);
    }
    AdcVal::ok(1 === preg_match('/Comenzi plătite ' . (int) $sum('orders_paid', 'count') . '\b/u', $page_text($page_url . '&product=' . $pa->get_id() . '&channel=search')), 'channel/product dimensions do not change paid-order financials (no attribution)');
    // Reconciliation collapsed, stylesheet once.
    $raw = adcval_http('GET', $page_url, array('cookie' => adcval_cookie($admin)))['body'];
    AdcVal::ok(1 === preg_match_all('#admin-analytics\.css#', $raw), 'admin-analytics.css referenced exactly once', (string) preg_match_all('#admin-analytics\.css#', $raw));
    $dd = new DOMDocument();
    @$dd->loadHTML($raw);
    $xx = new DOMXPath($dd);
    $recon = $xx->query('//section[.//h3[contains(.,"Recalculare")]]//details');
    AdcVal::ok($recon->length >= 1 && !$recon->item(0)->hasAttribute('open'), 'reconciliation table is a collapsed <details>', (string) $recon->length);
    AdcVal::ok($xx->query('//section[.//h3[contains(.,"Recalculare")]]//*[@tabindex="0"]')->length >= 1 || $recon->length >= 1, 'reconciliation scroll region keyboard reachable (summary/tabindex)');
    // Currency variants through the same export links the page renders.
    $link = static function (string $report, array $extra) use ($links): string {
        foreach ($links as $u) {
            parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
            if (($q['report'] ?? '') === $report) {
                $q = array_merge($q, $extra);
                return '/wp-admin/admin-post.php?' . http_build_query($q);
            }
        }
        return '';
    };
    $kv = static function (string $body): array {
        $o = array();
        foreach (preg_split('/\r?\n/', trim(substr($body, 3))) as $l) {
            $c = str_getcsv($l, ';', '"', '');
            $o[$c[0]] = $c[1] ?? '';
        }
        return $o;
    };
    $eur_order = adcval_dated(adcval_order('EUR'), $day0, true);
    adcval_drain();
    $fetch_csv = static fn(string $u): array => adcval_http('GET', $u, array('cookie' => adcval_cookie($admin)));
    $r = $fetch_csv($link('kpis', array()));
    $k0 = $kv($r['body']);
    AdcVal::ok($shop === ($k0['Monedă'] ?? '') && (int) $sum('orders_paid', 'count') === (int) ($k0['Comenzi plătite'] ?? -1), 'default-currency kpis CSV from page link == DB', json_encode(array($k0['Monedă'] ?? '', $k0['Comenzi plătite'] ?? '')));
    $r = $fetch_csv($link('kpis', array('currency' => 'EUR')));
    $ke = $kv($r['body']);
    $eur_db = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(count),0) FROM ' . adcval_t(ADC_Analytics_Schema::COMMERCE) . " WHERE currency='EUR' AND metric='orders_paid' AND stat_date BETWEEN %s AND %s", $win_from, $win_to));
    AdcVal::ok(200 === $r['code'] && 'EUR' === ($ke['Monedă'] ?? '') && $eur_db === (int) ($ke['Comenzi plătite'] ?? -1) && $eur_db >= 1, 'explicit EUR kpis CSV == DB', 'csv=' . ($ke['Comenzi plătite'] ?? '') . ' db=' . $eur_db);
    $r = $fetch_csv($link('kpis', array('currency' => strtolower($shop))));
    AdcVal::ok(200 === $r['code'] && $shop === ($kv($r['body'])['Monedă'] ?? ''), 'lowercase explicit currency accepted');
    foreach (array('RO', 'ROON', "RON'--", '12$') as $bad) {
        $r = $fetch_csv($link('kpis', array('currency' => $bad)));
        AdcVal::ok(400 === $r['code'] && !str_contains($r['body'], 'Comenzi plătite'), 'malformed currency ' . json_encode($bad) . ' denied 400, no fake zeros', (string) $r['code']);
    }
    // A tags-only value sanitises to empty = "not supplied": must then be the real default-currency data, never zeros.
    $r = $fetch_csv($link('kpis', array('currency' => '<b>')));
    $kb = $kv($r['body']);
    AdcVal::ok(400 === $r['code'] || ($shop === ($kb['Monedă'] ?? '') && (int) $sum('orders_paid', 'count') === (int) ($kb['Comenzi plătite'] ?? -1)), 'tags-only currency is denied or falls back to the real default data (no fake zeros)', (string) $r['code']);
    $r = $fetch_csv($link('top_products', array()));
    AdcVal::ok(200 === $r['code'] && !str_contains($r['body'], 'Moneda selectată nu este validă') && str_contains($r['body'], (string) $pa->get_id()), 'default-currency top_products CSV carries real rows', substr(trim(substr($r['body'], 3)), 0, 60));
    $r = $fetch_csv($link('reconciliation', array()));
    AdcVal::ok(200 === $r['code'] && !str_contains($r['body'], 'Moneda selectată nu este validă'), 'default-currency reconciliation CSV valid');
    $r = $fetch_csv($link('commerce_series', array('channel' => 'search')));
    $r2 = $fetch_csv($link('commerce_series', array()));
    AdcVal::ok($r['body'] === $r2['body'], 'commerce_series CSV identical with and without channel dimension (no attribution)');
    // Page itself: no cookie, no external host, no storage API, scripts same-origin only.
    $pg = $fetch();
    $cn = static fn(array $h): array => array_values(array_unique(array_map(static fn($c) => explode('=', $c, 2)[0], $h['set-cookie'] ?? array())));
    $core = adcval_http('GET', '/wp-admin/index.php', array('cookie' => adcval_cookie($admin)));
    AdcVal::ok(array() === array_diff($cn($pg['headers']), $cn($core['headers'])), 'dashboard sets no cookie beyond what core wp-admin sets on its own dashboard', 'tab=' . implode('|', $cn($pg['headers'])) . ' core=' . implode('|', $cn($core['headers'])));
    preg_match_all('#<script[^>]+src=["\']([^"\']+)#i', $pg['body'], $sm);
    $ext_scripts = array_filter($sm[1], static fn($u) => str_starts_with($u, 'http') && !str_contains($u, '127.0.0.1'));
    AdcVal::ok(array() === $ext_scripts, 'dashboard loads no external script', implode(',', $ext_scripts));
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/admin-analytics.js');
    $js = preg_replace('#/\*.*?\*/|(?<![:"\'])//[^\n]*#s', '', $js);
    AdcVal::ok(!preg_match('/localStorage|sessionStorage|document\.cookie|XMLHttpRequest|fetch\(|sendBeacon|navigator\.sendBeacon|new Image\(/', $js), 'admin-analytics.js has no storage/cookie/network API');
    adcval_reset_tables();
}

/* ===== bench (bounded, synthetic; numbers recorded, no claims) ===== */
if (in_array('bench', $groups, true)) {
    AdcVal::$group = 'bench';
    global $wpdb;
    $N = 200;
    adcval_reset_tables();
    $ids = array();
    adcval_settings(array());
    $bday = adcval_runday();
    for ($i = 0; $i < $N; $i++) {
        $ids[] = adcval_dated(adcval_order('RON'), $bday, true)->get_id();
    }
    // enqueue overhead: gate closed vs open (hook handler only)
    $time = static function (callable $f) use ($ids): float {
        $t = microtime(true);
        foreach ($ids as $id) {
            $f($id);
        }
        return (microtime(true) - $t) / count($ids) * 1000;
    };
    $off = $time(static fn($id) => ADC_Analytics_Commerce::on_new_order($id));
    adcval_gate_on();
    as_unschedule_all_actions('', array(), ADC_Analytics_Commerce::GROUP);
    $on = $time(static fn($id) => ADC_Analytics_Commerce::on_new_order($id));
    $on2 = $time(static fn($id) => ADC_Analytics_Commerce::on_status_changed($id, 'pending', 'processing'));
    AdcVal::info(sprintf('enqueue overhead per hook call: gate off %.3f ms, gate on (new_order) %.3f ms, gate on (status_changed: 2 jobs) %.3f ms; n=%d', $off, $on, $on2, $N));
    AdcVal::ok($on < 50, 'enqueue overhead under 50 ms per hook call (loose sanity bound)', sprintf('%.2f ms', $on));
    // process throughput single worker
    adcval_reset_tables();
    $t = microtime(true);
    foreach ($ids as $id) {
        ADC_Analytics_Commerce::process('order_created', $id);
        ADC_Analytics_Commerce::process('order_paid', $id);
    }
    $el = microtime(true) - $t;
    AdcVal::info(sprintf('1 worker: %d events in %.2fs = %.1f events/s', $N * 2, $el, $N * 2 / $el));
    AdcVal::ok($N === adcval_metric($bday, 'RON', 'orders_paid')['count'], 'bench aggregate exact: orders_paid = ' . $N);
    // 4 parallel workers, interleaved with duplicates
    adcval_reset_tables();
    $start = microtime(true) + 3.0;
    $procs = array();
    foreach (array_chunk($ids, (int) ceil($N / 4)) as $slice) {
        $ev = array();
        foreach ($slice as $sid) {
            $ev[] = array('order_created', $sid);
            $ev[] = array('order_paid', $sid);
        }
        $procs[] = adcval_php(array('worker' => 'process', 'payload' => json_encode(array('start' => $start, 'events' => $ev))));
    }
    $t = microtime(true);
    foreach ($procs as [$p, $pipes]) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
    }
    $wall = microtime(true) - max($t, $start);
    AdcVal::info(sprintf('4 workers: %d events wall %.2fs (includes WP bootstrap per worker)', $N * 2, max($wall, 0.0001)));
    AdcVal::ok($N === adcval_metric($bday, 'RON', 'orders_paid')['count'] && abs(adcval_metric($bday, 'RON', 'order_total')['amount'] - $N * 52.35) < 1e-4, 'parallel bench aggregate exact, no lock-refusal loss (jobs retry otherwise)', (string) adcval_metric($bday, 'RON', 'orders_paid')['count']);
    $t = microtime(true);
    $rb = ADC_Analytics_Reconciler::rebuild_day($bday);
    AdcVal::ok($rb['ok'], sprintf('rebuild_day over %d orders ok (%.2fs)', $N, microtime(true) - $t), 'reason=' . $rb['reason']);
}

/* ===== uninstall (destructive on the DISPOSABLE db only) ===== */
if (isset($adcval_args['group']) && in_array('uninstall', $groups, true)) {
    AdcVal::$group = 'uninstall';
    global $wpdb;
    $has = static fn(string $t): bool => (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
    ADC_Analytics_Schema::install();
    adcval_gate_on();
    $o = adcval_order('RON');
    $o->set_status('processing');
    $o->save();
    $plugin_dir = dirname($adcval_wp) . '/wp/wp-content/plugins/adc-marketing';
    $uninstall = is_file(WP_PLUGIN_DIR . '/adc-marketing/uninstall.php') ? WP_PLUGIN_DIR . '/adc-marketing/uninstall.php' : $plugin_dir . '/uninstall.php';
    $consent = (array) get_option('adc_settings_consent', array());
    // 1. No opt-in: data is kept.
    update_option('adc_settings_consent', array_merge($consent, array('delete_data_on_uninstall' => false)));
    define('WP_UNINSTALL_PLUGIN', 'adc-marketing/adc-marketing.php');
    ob_start();
    include $uninstall;
    ob_end_clean();
    AdcVal::ok($has(adcval_t(ADC_Analytics_Schema::LEDGER)) && $has(adcval_t(ADC_Analytics_Schema::COMMERCE)), 'uninstall WITHOUT opt-in keeps analytics tables');
    AdcVal::ok(false !== get_option('adc_settings_analytics', false), 'uninstall WITHOUT opt-in keeps analytics settings');
    // 2. Opt-in: everything analytics goes.
    update_option('adc_settings_consent', array_merge($consent, array('delete_data_on_uninstall' => true)));
    $pending_before = count(adcval_pending());
    ob_start();
    include $uninstall;
    ob_end_clean();
    $left = array();
    foreach (array('TRAFFIC', 'INTERACTIONS', 'COMMERCE', 'PRODUCTS', 'LEDGER', 'CAMPAIGNS') as $c) {
        if ($has(adcval_t(constant('ADC_Analytics_Schema::' . $c)))) {
            $left[] = $c;
        }
    }
    AdcVal::ok(array() === $left, 'uninstall WITH opt-in drops all analytics tables', implode(',', $left));
    AdcVal::ok(false === get_option('adc_settings_analytics', false) && false === get_option('adc_analytics_db_version', false), 'opt-in uninstall removes analytics options');
    $left_opts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'adc\\_analytics\\_%'");
    AdcVal::ok(0 === $left_opts, 'no adc_analytics_* options remain', (string) $left_opts);
    AdcVal::ok(0 === count(adcval_pending()) && false === wp_next_scheduled('adc_analytics_cleanup'), 'opt-in uninstall unschedules analytics jobs and cleanup', "pending_before={$pending_before}");
    AdcVal::ok(null !== wc_get_order($o->get_id()) && false !== wc_get_order($o->get_id()), 'WooCommerce orders are untouched by uninstall');
    ADC_Analytics_Schema::install(); // leave harness usable
    update_option('adc_settings_consent', $consent);
}

/* ---------------------------------------------------------------- restore + summary */
if (false !== $saved_settings) {
    update_option('adc_settings_analytics', $saved_settings);
}
update_option('timezone_string', (string) $saved_tz[0]);
update_option('gmt_offset', $saved_tz[1]);

echo sprintf("SUMMARY pass=%d fail=%d skip=%d\n", AdcVal::$pass, AdcVal::$fail, AdcVal::$skip);
exit(AdcVal::$fail > 0 ? 1 : 0);
