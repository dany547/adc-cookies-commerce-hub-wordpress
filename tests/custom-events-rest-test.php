<?php
/**
 * Offline checks for the custom_* branch of ADC_Tracking_Rest::handle_event (§5.1)
 * and ADC_Custom_Events::routes_to(). Stubs only: no WordPress, DB or HTTP.
 * Spec: docs/CUSTOM-EVENTS.md §5.1, §4.4, §8.4 (C4).
 */
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', '/offline/');
define('MINUTE_IN_SECONDS', 60);
define('WEEK_IN_SECONDS', 604800);

function __(string $text, string $domain = ''): string { return $text; }
function sanitize_text_field(string $value): string { return trim($value); }
function wp_salt(string $scheme = 'auth'): string { return 'test-salt'; }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl = 0): bool { $GLOBALS['transients'][$key] = $value; return true; }
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool { return true; }
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool { return true; }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
function do_action(string $hook, mixed ...$args): void { if ('adc_track_event' === $hook) { $GLOBALS['events'][] = $args[0]; } }
function rest_ensure_response(mixed $data): ADC_Test_Response { return new ADC_Test_Response(); }
function is_wp_error(mixed $thing): bool { return $thing instanceof WP_Error; }

interface ADC_Module_Interface { public static function boot(): void; }

final class ADC_Test_Response
{
    public int $status = 200;
    public array $headers = array();
    public function set_status(int $status): void { $this->status = $status; }
    public function header(string $key, string $value): void { $this->headers[$key] = $value; }
}

final class WP_Error
{
    public function __construct(private string $code, private string $message = '', private array $data = array()) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}

final class WP_REST_Request
{
    public function __construct(private string $body, private array $headers = array()) {}
    public function get_body(): string { return $this->body; }
    public function get_header(string $name): ?string { return $this->headers[$name] ?? null; }
}

final class ADC_Settings
{
    public static function all(string $group): array { return $GLOBALS['settings'][$group] ?? array(); }
    public static function register_group(string $group, array $defaults, callable $sanitize): void {}
}

final class ADC_Event_Id
{
    public static function is_valid(string $id): bool { return 1 === preg_match('/^[A-Za-z0-9_.\-]{8,64}$/', $id); }
}

/** Minimal consent state: exposes allows(), cid and to_array(). */
final class ADC_Consent_State
{
    public function __construct(private array $categories = array(), public string $cid = 'cid-test') {}
    public function allows(string $category): bool { return in_array($category, $this->categories, true); }
    public function to_array(): array { return array('categories' => $this->categories); }
}

final class ADC_Consent
{
    public static function current(): ADC_Consent_State { return $GLOBALS['consent'] ?? new ADC_Consent_State(); }
    public static function allows(string $category, ?ADC_Consent_State $state = null): bool
    {
        return ($state ?? self::current())->allows($category);
    }
}

/** Captures named constructor arguments so tests can inspect the built event. */
final class ADC_Event
{
    public mixed $user = null;
    public array $args = array();
    public function __construct(mixed ...$args) { $this->args = $args; $this->user = $args['user'] ?? null; }
    public function __get(string $name): mixed { return $this->args[$name] ?? null; }
}

require dirname(__DIR__) . '/includes/tracking/class-adc-custom-event-rule.php';
require dirname(__DIR__) . '/includes/tracking/class-adc-custom-events.php';
require dirname(__DIR__) . '/includes/tracking/class-adc-tracking-rest.php';

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$token = hash_hmac('sha256', (string) (int) floor(time() / WEEK_IN_SECONDS), 'test-salt');
$event_id = 'evt-abcdef123456';

/** Reset per-test globals and set the stored rules. */
function setup_rules(array $rules): void
{
    $GLOBALS['transients'] = array();
    $GLOBALS['events'] = array();
    $GLOBALS['consent'] = new ADC_Consent_State(array('statistics'));
    $GLOBALS['settings'] = array('custom_events' => array('schema_version' => 1, 'revision' => 1, 'rules' => $rules));
}

function valid_rule(array $overrides = array()): array
{
    // destinations is replaced wholesale: recursive merge would keep default list entries.
    $destinations = $overrides['destinations'] ?? null;
    unset($overrides['destinations']);
    $raw = array_replace_recursive(array(
        'label' => 'Click telefon header',
        'name' => 'custom_phone_click',
        'trigger' => array('type' => 'click', 'preset' => 'tel'),
        'params' => array(
            array('key' => 'link_location', 'value' => 'header'),
            array('key' => 'link_url', 'value' => '{{click_url}}'),
        ),
        'value' => 25,
        'currency' => 'RON',
        'destinations' => array('server_mirror' => true, 'platforms' => array('ga4')),
    ), $overrides);
    if (null !== $destinations) {
        $raw['destinations'] = $destinations;
    }
    $result = ADC_Custom_Event_Rule::validate($raw);
    check(null !== $result['rule'], 'setup: rule must validate: ' . implode(' | ', $result['errors']));
    return $result['rule'];
}

/** Send a browser event; returns the response code or error code. */
function send(string $name, array $params, array $extra = array(), string $ip = '203.0.113.7'): array
{
    global $token;
    $_SERVER['REMOTE_ADDR'] = $ip;
    $body = array_merge(array('event_id' => 'evt-abcdef123456', 'name' => $name, 'params' => $params), $extra);
    $request = new WP_REST_Request(json_encode($body, JSON_THROW_ON_ERROR), array('X-ADC-Token' => $token));
    $result = ADC_Tracking_Rest::handle_event($request);
    if ($result instanceof WP_Error) {
        return array('error' => $result->get_error_code(), 'status' => $result->get_error_data()['status'] ?? 0);
    }
    return array('status' => $result->status);
}

$rule = valid_rule();
$rule_disabled = valid_rule(array('name' => 'custom_disabled_evt', 'enabled' => false));
$rule_no_mirror = valid_rule(array('name' => 'custom_no_mirror', 'destinations' => array('server_mirror' => false, 'platforms' => array())));
$rule_stats = valid_rule(array('name' => 'custom_stats_evt', 'consent_category' => 'statistics'));
$rule_marketing = valid_rule(array('name' => 'custom_mkt_evt', 'consent_category' => 'marketing', 'destinations' => array('server_mirror' => true, 'platforms' => array())));
check(false === $rule_disabled['enabled'], 'setup: disabled rule must keep enabled=false');

setup_rules(array($rule, $rule_disabled, $rule_no_mirror, $rule_stats, $rule_marketing));

// 1. custom_x without adc_rule_id -> 400.
check(array('error' => 'invalid_event_name', 'status' => 400) === send('custom_phone_click', array()), 'missing adc_rule_id must return 400');

// 2. Disabled rule -> 400.
check(array('error' => 'invalid_event_name', 'status' => 400) === send('custom_disabled_evt', array('adc_rule_id' => $rule_disabled['id'])), 'disabled rule must return 400');

// 3. Name differs from the rule -> 400.
check(array('error' => 'invalid_event_name', 'status' => 400) === send('custom_other', array('adc_rule_id' => $rule['id'])), 'name mismatch must return 400');

// 4. server_mirror=false -> 400.
check(array('error' => 'invalid_event_name', 'status' => 400) === send('custom_no_mirror', array('adc_rule_id' => $rule_no_mirror['id'])), 'server_mirror=false must return 400');

// 5. Statistics rule without statistics consent -> 403.
$GLOBALS['consent'] = new ADC_Consent_State(array());
check(array('error' => 'consent_required', 'status' => 403) === send('custom_stats_evt', array('adc_rule_id' => $rule_stats['id'])), 'missing statistics consent must return 403');
check(array('error' => 'consent_required', 'status' => 403) === send('custom_mkt_evt', array('adc_rule_id' => $rule_marketing['id'])), 'marketing rule without marketing consent must return 403');
$GLOBALS['consent'] = new ADC_Consent_State(array('statistics'));

// 6. Unknown client param keys are ignored; static values cannot be overridden.
$GLOBALS['events'] = array();
$response = send('custom_phone_click', array('adc_rule_id' => $rule['id'], 'link_url' => 'https://x.ro/a', 'evil' => 'x', 'link_location' => 'hacked'));
check(array('status' => 202) === $response, 'valid custom event must return 202, got ' . json_encode($response));
$event = $GLOBALS['events'][0] ?? null;
check($event instanceof ADC_Event, 'valid custom event must reach adc_track_event');
check(!array_key_exists('evil', $event->params), 'unknown client param must be ignored');
check('header' === $event->params['link_location'], 'static param value must not be overridden by the client');

// 7. Token-valued param is taken from the client; a personal value is scrubbed.
check('https://x.ro/a' === $event->params['link_url'], 'token param must be taken from the client');
$GLOBALS['events'] = array();
send('custom_phone_click', array('adc_rule_id' => $rule['id'], 'link_url' => 'mailto:ion@example.ro'));
check('[email]' === $GLOBALS['events'][0]->params['link_url'], 'client-supplied e-mail must be scrubbed to [email]');

// 8. value and currency come only from the rule, never from the client body.
$GLOBALS['events'] = array();
send('custom_phone_click', array('adc_rule_id' => $rule['id'], 'link_url' => 'https://x.ro'), array('value' => 999, 'currency' => 'USD'));
$event = $GLOBALS['events'][0];
check(25.0 === $event->value, 'value must come from the rule (got ' . var_export($event->value, true) . ')');
check('RON' === $event->currency, 'currency must come from the rule');
check(array() === $event->items, 'custom events must not carry items');

// 9. Non-custom events keep their current behaviour (view_item).
$GLOBALS['events'] = array();
$response = send('view_item', array('coupon' => 'SAVE10'));
check(array('status' => 202) === $response, 'view_item must still return 202, got ' . json_encode($response));
$event = $GLOBALS['events'][0];
check('view_item' === $event->name && 'SAVE10' === $event->params['coupon'], 'view_item must keep browser params whitelist');

// 10. routes_to(): true only for an enabled, mirrored rule with the platform.
check(true === ADC_Custom_Events::routes_to('custom_phone_click', 'ga4'), 'routes_to must be true for ga4');
check(false === ADC_Custom_Events::routes_to('custom_phone_click', 'meta'), 'routes_to must be false for an unrouted platform');
check(false === ADC_Custom_Events::routes_to('custom_disabled_evt', 'ga4'), 'routes_to must be false for a disabled rule');
check(false === ADC_Custom_Events::routes_to('custom_no_mirror', 'ga4'), 'routes_to must be false without server_mirror');

// 11. Truncation to 100 is UTF-8 safe. Byte-based substr(…, 0, 100) would cut
//     the second byte of a 2-byte character here, leaving invalid UTF-8 that
//     json_encode (and therefore the GA4/Meta sender) rejects.
$diacritics_rule = array('params' => array(array('key' => 'link_text', 'value' => '{{click_text}}')));
$long_diacritics = str_repeat('ă ', 60); // 120 characters, 180 bytes: truncation must happen
$truncated = ADC_Custom_Events::server_params($diacritics_rule, array('link_text' => $long_diacritics))['link_text'];
check(1 === preg_match('//u', $truncated), 'truncated 100-char value must be valid UTF-8');
check(100 === preg_match_all('/./us', $truncated), 'value must be truncated to exactly 100 characters');
check(false !== json_encode(array('link_text' => $truncated)), 'truncated value must survive json_encode');

// 99 x 'a' + 'ă' has no spaces, so scrub() turns the opaque run into [id]; the
// result must still be valid UTF-8 and encodable.
$opaque = ADC_Custom_Events::server_params($diacritics_rule, array('link_text' => str_repeat('a', 99) . 'ă'))['link_text'];
check(1 === preg_match('//u', $opaque) && false !== json_encode(array('link_text' => $opaque)), 'opaque 99a+ă value must stay valid UTF-8');

echo "Custom events REST tests passed.\n";
