<?php
/**
 * Offline test for ADC_Picker session tokens (includes/tracking/class-adc-picker.php, §7.1.1).
 *
 * Stubs WordPress functions inline; ADC_Test_Stop marks the deliberate exits of
 * wp_send_json_* / check_ajax_referer. Exit code 0 = pass, non-zero = failure.
 */
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('ABSPATH', '/offline/');

final class ADC_Test_Stop extends RuntimeException {}
interface ADC_Module_Interface { public static function boot(): void; }

function __(string $text, string $domain = ''): string { return $text; }
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool { return true; }
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool { return true; }
function wp_unslash(mixed $value): mixed { return $value; }
function nocache_headers(): void {}
function admin_url(string $path = ''): string { return $GLOBALS['t_admin_url'] . '/wp-admin/' . $path; }
function home_url(string $path = ''): string { return $GLOBALS['t_home_url'] . $path; }
function wp_parse_url(string $url): array|false { return parse_url($url); }
function is_user_logged_in(): bool { return (bool) $GLOBALS['t_logged_in']; }
function get_current_user_id(): int { return (int) $GLOBALS['t_uid']; }
function current_user_can(string $capability): bool { return (bool) $GLOBALS['t_can']; }
function get_transient(string $key): mixed { return $GLOBALS['t_transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $expiration = 0): bool { $GLOBALS['t_transients'][$key] = $value; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['t_transients'][$key]); return true; }
function check_ajax_referer(string $action = '', string|false $query_arg = false, bool $die = true): int|false {
    if (empty($GLOBALS['t_nonce_ok'])) { throw new ADC_Test_Stop('nonce'); }
    return 1;
}
function wp_send_json_success(mixed $data = null, ?int $status_code = null): never {
    $GLOBALS['t_json'] = $data;
    throw new ADC_Test_Stop('success');
}
function wp_send_json_error(mixed $data = null, ?int $status_code = null): never {
    $GLOBALS['t_json_error_status'] = $status_code;
    throw new ADC_Test_Stop('json_error');
}

require dirname(__DIR__) . '/includes/tracking/class-adc-picker.php';

function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

function expect_stop(callable $fn, string $reason): void {
    try {
        $fn();
    } catch (ADC_Test_Stop $stop) {
        check($stop->getMessage() === $reason, "expected stop '$reason', got '" . $stop->getMessage() . "'");
        return;
    }
    throw new RuntimeException("expected stop '$reason', call returned normally");
}

/** Reset request state; the active() memo is per request, so clear it between checks. */
function reset_request(int $uid = 7, bool $logged_in = true, bool $can = true, bool $nonce_ok = true): void {
    $GLOBALS['t_transients'] = [];
    $GLOBALS['t_uid'] = $uid;
    $GLOBALS['t_logged_in'] = $logged_in;
    $GLOBALS['t_can'] = $can;
    $GLOBALS['t_nonce_ok'] = $nonce_ok;
    $GLOBALS['t_admin_url'] = 'https://example.test';
    $GLOBALS['t_home_url'] = 'https://example.test';
    $GLOBALS['t_json'] = null;
    $GLOBALS['t_json_error_status'] = null;
    $GLOBALS['_GET'] = [];
    $GLOBALS['_POST'] = [];
    (new ReflectionProperty(ADC_Picker::class, 'active'))->setValue(null, null);
}

function issue_token(): string {
    expect_stop(static fn () => ADC_Picker::ajax_token(), 'success');
    return (string) $GLOBALS['t_json']['token'];
}

function store_record(string $token, int $uid, int $exp): void {
    $GLOBALS['t_transients']['adc_picker_' . hash('sha256', $token)] = array('uid' => $uid, 'exp' => $exp);
}

function active_for(string $token): bool {
    (new ReflectionProperty(ADC_Picker::class, 'active'))->setValue(null, null);
    $GLOBALS['_GET'][ADC_Picker::QUERY_VAR] = $token;
    return ADC_Picker::active();
}

$tests = array();

// --- ajax_token: authorization ---
$tests['ajax_token fără capability → refuzat 403'] = static function (): void {
    reset_request(7, true, false, true);
    expect_stop(static fn () => ADC_Picker::ajax_token(), 'json_error');
    check($GLOBALS['t_json_error_status'] === 403, 'expected HTTP 403');
    check($GLOBALS['t_transients'] === [], 'no transient may be written for a denied request');
};

$tests['ajax_token cu nonce greșit → ADC_Test_Stop'] = static function (): void {
    reset_request(7, true, true, false);
    expect_stop(static fn () => ADC_Picker::ajax_token(), 'nonce');
    check($GLOBALS['t_transients'] === [], 'no transient may be written for a bad nonce');
};

// --- ajax_token: issuance ---
$tests['token emis: 32 hex, transient cu hash, fără token brut'] = static function (): void {
    reset_request();
    $token = issue_token();
    check(1 === preg_match('/^[a-f0-9]{32}$/', $token), 'token is not 32 lowercase hex');
    check(array_key_exists('adc_picker_' . hash('sha256', $token), $GLOBALS['t_transients']), 'hash-keyed transient missing');
    check(!array_key_exists('adc_picker_' . $token, $GLOBALS['t_transients']), 'raw token used as key');
    $serialized = serialize($GLOBALS['t_transients']);
    check(!str_contains($serialized, $token), 'raw token stored inside transient');
    check($GLOBALS['t_json']['expires_in'] === ADC_Picker::TTL, 'expires_in mismatch');
};

$tests['rate limit: a 11-a cerere → 429'] = static function (): void {
    reset_request();
    for ($i = 0; $i < 10; $i++) {
        issue_token();
    }
    expect_stop(static fn () => ADC_Picker::ajax_token(), 'json_error');
    check($GLOBALS['t_json_error_status'] === 429, 'expected HTTP 429 on the 11th request');
};

// --- active(): frontend validation ---
$tests['active() adevărat pentru token valid'] = static function (): void {
    reset_request(7);
    $token = str_repeat('a1', 16);
    store_record($token, 7, time() + 600);
    check(active_for($token) === true, 'valid token rejected');
};

$tests['active() fals pentru token expirat'] = static function (): void {
    reset_request(7);
    $token = str_repeat('b2', 16);
    store_record($token, 7, time() - 1);
    check(active_for($token) === false, 'expired token accepted');
};

$tests['active() fals pentru alt utilizator'] = static function (): void {
    reset_request(8);
    $token = str_repeat('c3', 16);
    store_record($token, 7, time() + 600);
    check(active_for($token) === false, 'token of another user accepted');
};

$tests['active() fals pentru format greșit'] = static function (): void {
    reset_request(7);
    $token = str_repeat('d4', 16);
    store_record($token, 7, time() + 600);
    foreach (array(strtoupper($token), substr($token, 1), $token . 'e', 'zz' . substr($token, 2), $token . "\n") as $bad) {
        check(active_for($bad) === false, "malformed token accepted: $bad");
    }
};

$tests['active() fals fără login'] = static function (): void {
    reset_request(7, false);
    $token = str_repeat('e5', 16);
    store_record($token, 7, time() + 600);
    check(active_for($token) === false, 'picker active without login');
};

$tests['active() fals fără capability manage_options'] = static function (): void {
    reset_request(7, true, false);
    $token = str_repeat('f6', 16);
    store_record($token, 7, time() + 600);
    check(active_for($token) === false, 'picker active without manage_options');
};

// --- available(): same-origin precondition ---
$tests['available() adevărat când originile coincid'] = static function (): void {
    reset_request();
    check(ADC_Picker::available() === true, 'same origin reported unavailable');
};

$tests['available() fals când host-ul diferă'] = static function (): void {
    reset_request();
    $GLOBALS['t_home_url'] = 'https://other.test';
    check(ADC_Picker::available() === false, 'different host reported available');
};

$tests['available() fals când schema diferă'] = static function (): void {
    reset_request();
    $GLOBALS['t_home_url'] = 'http://example.test';
    check(ADC_Picker::available() === false, 'different scheme reported available');
};

$tests['available() fals când portul diferă'] = static function (): void {
    reset_request();
    $GLOBALS['t_home_url'] = 'https://example.test:8443';
    check(ADC_Picker::available() === false, 'different port reported available');
};

// --- revoke ---
$tests['revoke șterge tokenul propriu și răspunde revoked'] = static function (): void {
    reset_request(7);
    $token = issue_token();
    $key = 'adc_picker_' . hash('sha256', $token);
    check(array_key_exists($key, $GLOBALS['t_transients']), 'precondition: token not stored');
    $GLOBALS['_POST'] = array('token' => $token);
    expect_stop(static fn () => ADC_Picker::ajax_revoke(), 'success');
    check(!array_key_exists($key, $GLOBALS['t_transients']), 'token not deleted by revoke');
    check($GLOBALS['t_json'] === array('revoked' => true), 'revoke must always answer revoked=true');
};

$tests['revoke nu șterge tokenul altui utilizator'] = static function (): void {
    reset_request(7);
    $token = str_repeat('9a', 16);
    store_record($token, 8, time() + 600);
    $GLOBALS['_POST'] = array('token' => $token);
    expect_stop(static fn () => ADC_Picker::ajax_revoke(), 'success');
    check(array_key_exists('adc_picker_' . hash('sha256', $token), $GLOBALS['t_transients']), 'foreign token was deleted');
};

$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "ok   - $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL - $name: {$e->getMessage()}\n";
    }
}
echo sprintf("\n%d/%d passed\n", count($tests) - $failed, count($tests));
exit($failed === 0 ? 0 : 1);
