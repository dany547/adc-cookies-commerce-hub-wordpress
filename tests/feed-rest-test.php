<?php
/**
 * ADC_Feed_Rest: permissions (capability + REST nonce), strict validation,
 * whitelisted responses, route handlers and the manual regeneration rate limit.
 *
 * ADC_Feed_Store and ADC_Feed_Generator are mocked here, so the test runs
 * without the real feed engine. Usage: php tests/feed-rest-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

// ---------------------------------------------------------------------------
// Minimal WordPress stand-ins.
// ---------------------------------------------------------------------------
final class WP_Error
{
    public function __construct(public string $code, public string $message = '', public array $data = array()) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}

final class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200) {}
}

final class WP_REST_Server
{
    public const READABLE  = 'GET';
    public const CREATABLE = 'POST';
    public const EDITABLE  = 'POST, PUT, PATCH';
    public const DELETABLE = 'DELETE';
}

final class WP_REST_Request
{
    public function __construct(private array $params = array(), private array $headers = array()) {}
    public function get_params(): array { return $this->params; }
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    public function get_header(string $key): ?string { return $this->headers[$key] ?? null; }
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function __(string $text, string $domain = 'default'): string
{
    if (!empty($GLOBALS['record_translation_locale'])) {
        $GLOBALS['translation_locales'][] = $GLOBALS['locale'] ?? '';
        return '[' . ($GLOBALS['locale'] ?? '') . '] ' . $text;
    }
    return $text;
}
function switch_to_user_locale(int $user_id): bool
{
    $GLOBALS['locale_switch_user_ids'][] = $user_id;
    if (false === ($GLOBALS['switch_locale_result'] ?? true)) {
        return false;
    }
    $GLOBALS['locale_stack'][] = $GLOBALS['locale'] ?? '';
    $GLOBALS['locale'] = 'user_ro_RO';
    return true;
}
function restore_previous_locale(): bool
{
    $GLOBALS['restore_locale_calls']++;
    $GLOBALS['locale'] = array_pop($GLOBALS['locale_stack']);
    return true;
}
function sanitize_text_field(string $text): string { return trim(strip_tags($text)); }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_\\-]/', '', $value)); }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function get_current_user_id(): int { return 7; }
$GLOBALS['locale'] = 'site_en_US';
$GLOBALS['locale_stack'] = array();
$GLOBALS['locale_switch_user_ids'] = array();
$GLOBALS['restore_locale_calls'] = 0;
$GLOBALS['translation_locales'] = array();
$GLOBALS['switch_locale_result'] = true;
function current_user_can(string $cap): bool { return (bool) ($GLOBALS['caps'][$cap] ?? false); }
function wp_verify_nonce(string $nonce, string $action): bool { return 'valid-nonce' === $nonce && 'wp_rest' === $action; }
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool { $GLOBALS['transients'][$key] = $value; return true; }
function add_action(string $hook, callable|array|string $callback, int $priority = 10): void {}
function register_rest_route(string $ns, string $route, array $args): bool
{
    $GLOBALS['routes'][$ns . $route] = $args;
    return true;
}

// ---------------------------------------------------------------------------
// Mocks for the feed engine (contract: feed-core).
// ---------------------------------------------------------------------------
final class ADC_Feed_Store
{
    public static array $rows = array();
    public static int $next_id = 1;
    public static function create(array $data): int { $id = self::$next_id++; self::$rows[$id] = $data + array('id' => $id, 'slug' => 'slug' . $id); return $id; }
    public static function get(int $id): ?array { return self::$rows[$id] ?? null; }
    public static function all(): array { return array_values(self::$rows); }
    public static function update(int $id, array $data): bool { if (!isset(self::$rows[$id])) { return false; } self::$rows[$id] = $data + self::$rows[$id]; return true; }
    public static function delete(int $id): bool { unset(self::$rows[$id]); return true; }
    public static function set_status(int $id, string $st): bool { if (!isset(self::$rows[$id])) { return false; } self::$rows[$id]['status'] = $st; return true; }
    public static function regenerate_slug(int $id): string { self::$rows[$id]['slug'] = 'fresh' . $id; return 'fresh' . $id; }
    public static array $runs = array();
    public static function last_run(int $id): ?array { return self::$runs[$id] ?? null; }
}

final class ADC_Feed_Generator
{
    public static array $requested = array();
    public static function request(int $feed_id): bool { self::$requested[] = $feed_id; return true; }
    public static function diagnostic_codes(): array { return array('generator_only_code'); }
    public static array $previews = array();
    public static function preview(array $config, int $n = 5): array
    {
        self::$previews[] = $n;
        return array(
            'rows' => array_fill(0, min($n, 3), array('title' => 'Pantof', 'price' => '12.00')),
            'selection' => array(array('product_id' => 17, 'metric' => 'views', 'value' => 24), array('product_id' => -1, 'metric' => 'views', 'value' => 24)),
            'warnings' => array('id_incompatible'),
        );
    }
}

// Parameter registry, defaults and validation (contracts of feed-core and feed-val).
final class ADC_Feed_Field_Registry
{
    public static bool $throw = false;
    public static bool $smart_label = false;
    public static function fields(string $channel): array
    {
        if (self::$throw) {
            throw new RuntimeException('registry failure');
        }
        $fields = array(
            'title'  => array('key' => 'title', 'label' => __('Titlu', 'adc-marketing'), 'required' => true, 'type' => 'string', 'max_length' => 150, 'help' => __('Titlul produsului', 'adc-marketing'), 'default_source' => array('type' => 'native', 'value' => 'name')),
            'color'  => array('key' => 'color', 'label' => __('Culoare', 'adc-marketing'), 'required' => false, 'type' => 'string', 'help' => __('Culoarea produsului', 'adc-marketing')),
            'gender' => array('key' => 'gender', 'label' => 'Gen', 'required' => false, 'type' => 'enum', 'enum' => array('male', 'female', 'unisex')),
        );
        if (self::$smart_label) {
            $fields['custom_label_0'] = array('key' => 'custom_label_0', 'label' => 'Etichetă', 'required' => false, 'type' => 'string');
        }
        return $fields;
    }
}
final class ADC_Feed_Params
{
    public static function defaults_for(string $channel): array
    {
        return array(array('field' => 'title', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(), 'enabled' => true));
    }
    public static function native_sources(): array
    {
        return array(array('value' => 'title', 'label' => __('Titlu', 'adc-marketing')), array('value' => 'feed_item_id', 'label' => __('ID produs', 'adc-marketing')));
    }
    public static function validate(string $channel, array $params): array
    {
        // Contract: a list of error rows, empty when the parameters are valid.
        foreach ($params as $row) {
            if ('bad_field' === $row['field']) {
                return array(array('code' => 'title_too_long', 'row' => 0));
            }
        }
        return in_array('title', array_column($params, 'field'), true) ? array() : array(array('code' => 'title_missing'));
    }
}

// Channel registry (contract of feed-core): available destinations and their formats.
final class ADC_Feed_Channels
{
    public static function all(): array
    {
        return array(
            'google' => array('label' => 'Google Shopping', 'formats' => array('xml')),
            'meta'   => array('label' => 'Meta', 'formats' => array('csv', 'xml')),
            'tiktok' => array('label' => 'TikTok', 'formats' => array('csv', 'xml')),
        );
    }
    public static function get(string $slug): ?array { return self::all()[$slug] ?? null; }
}

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

require __DIR__ . '/../includes/feeds/class-adc-feed-smart.php';
require __DIR__ . '/../includes/feeds/class-adc-feed-rest.php';

$GLOBALS['caps'] = array('manage_options' => true);
$GLOBALS['transients'] = array();
$GLOBALS['routes'] = array();
// Requests with a valid wp_rest nonce, as the browser sends them.
function req(array $params = array()): WP_REST_Request { return new WP_REST_Request($params, array('x_wp_nonce' => 'valid-nonce')); }


$good_config = array(
    'scope'         => 'filtered',
    'categories'    => array('incaltaminte', 'accesorii'),
    'in_stock_only' => true,
    'google'        => array(
        'shipping_country' => 'RO',
        'shipping_price'   => '15.00',
        'shipping_service' => 'Curier standard',
        'condition'        => 'new',
    ),
);
$good_create = array(
    'name'    => 'Feed Google principal',
    'channel' => 'google',
    'type'    => 'standard',
    'format'  => 'xml',
    'config'  => $good_config,
);

// --- Route registration ----------------------------------------------------
ADC_Feed_Rest::register_routes();
$expected_routes = array(
    'adc/v1/feeds'                    => array('GET', 'POST'),
    'adc/v1/feeds/(?P<id>\d+)'        => array('GET', 'POST, PUT, PATCH', 'DELETE'),
    'adc/v1/feeds/(?P<id>\d+)/regenerate'      => array('POST'),
    'adc/v1/feeds/(?P<id>\d+)/regenerate-slug' => array('POST'),
);
foreach ($GLOBALS['routes'] as $route => $endpoints) {
    foreach ($endpoints as $endpoint) {
        check($endpoint['callback'] instanceof Closure, "locale wrapper applied to {$route}");
    }
}
foreach ($expected_routes as $route => $methods) {
    check(isset($GLOBALS['routes'][$route]), "route registered: {$route}");
    $got = array_map(static fn (array $r): string => (string) $r['methods'], $GLOBALS['routes'][$route] ?? array());
    check($got === $methods, "methods for {$route}");
    foreach ($GLOBALS['routes'][$route] ?? array() as $endpoint) {
        check(is_callable($endpoint['permission_callback']), "permission_callback set on {$route}");
    }
}

// REST callbacks resolve gettext in the requesting user's locale and always restore the caller's locale.
$fields_callback = $GLOBALS['routes']['adc/v1/feeds/fields'][0]['callback'];
$restores_before = $GLOBALS['restore_locale_calls'];
$GLOBALS['record_translation_locale'] = true;
$localized_fields = $fields_callback(req(array('channel' => 'google')));
$GLOBALS['record_translation_locale'] = false;
check(
    $localized_fields instanceof WP_REST_Response && '[user_ro_RO] Titlu' === $localized_fields->data['items'][0]['label'],
    'fields endpoint formats registry labels in the current user locale'
);
check(
    end($GLOBALS['locale_switch_user_ids']) === 7
    && $GLOBALS['locale'] === 'site_en_US'
    && $GLOBALS['restore_locale_calls'] === $restores_before + 1
    && $GLOBALS['locale_stack'] === array(),
    'fields endpoint restores the prior locale after returning'
);

$preview_callback = $GLOBALS['routes']['adc/v1/feeds/preview'][0]['callback'];
$GLOBALS['record_translation_locale'] = true;
$localized_preview_error = $preview_callback(req(array('channel' => 'unknown')));
$GLOBALS['record_translation_locale'] = false;
check(
    $localized_preview_error instanceof WP_Error && str_starts_with($localized_preview_error->message, '[user_ro_RO]')
    && $GLOBALS['locale'] === 'site_en_US',
    'preview validation messages use the user locale and restore the prior locale'
);

$restores_before = $GLOBALS['restore_locale_calls'];
ADC_Feed_Field_Registry::$throw = true;
try {
    $fields_callback(req(array('channel' => 'google')));
} catch (RuntimeException) {
    // The wrapper must restore locale even when a handler throws.
}
ADC_Feed_Field_Registry::$throw = false;
check(
    $GLOBALS['locale'] === 'site_en_US' && $GLOBALS['restore_locale_calls'] === $restores_before + 1,
    'REST callback restores locale in finally after exceptions'
);

$permission_callback = $GLOBALS['routes']['adc/v1/feeds/fields'][0]['permission_callback'];
$GLOBALS['caps']['manage_options'] = false;
$GLOBALS['record_translation_locale'] = true;
$localized_denial = $permission_callback(req());
$GLOBALS['record_translation_locale'] = false;
$GLOBALS['caps']['manage_options'] = true;
check(
    $localized_denial instanceof WP_Error && str_starts_with($localized_denial->message, '[user_ro_RO]')
    && $GLOBALS['locale'] === 'site_en_US',
    'permission errors are localized and restore the prior locale'
);

$GLOBALS['switch_locale_result'] = false;
$restores_before = $GLOBALS['restore_locale_calls'];
$fields_callback(req(array('channel' => 'google')));
check(
    $GLOBALS['locale'] === 'site_en_US' && $GLOBALS['restore_locale_calls'] === $restores_before,
    'REST callback does not pop locale state when WordPress reports no switch'
);
$GLOBALS['switch_locale_result'] = true;

// --- Permissions: capability and nonce ------------------------------------
$can = array(ADC_Feed_Rest::class, 'can_manage');
$GLOBALS['caps'] = array('manage_options' => false);
$r = $can(new WP_REST_Request(array(), array('x_wp_nonce' => 'valid-nonce')));
check(is_wp_error($r) && $r->get_error_data()['status'] === 403, 'without manage_options: 403');

$GLOBALS['caps'] = array('manage_options' => true);
$r = $can(new WP_REST_Request(array(), array()));
check(is_wp_error($r) && 'adc_feed_bad_nonce' === $r->get_error_code(), 'missing X-WP-Nonce: rejected');

$r = $can(new WP_REST_Request(array(), array('x_wp_nonce' => 'stale')));
check(is_wp_error($r) && 'adc_feed_bad_nonce' === $r->get_error_code(), 'wrong nonce: rejected');

$r = $can(new WP_REST_Request(array(), array('x_wp_nonce' => 'valid-nonce')));
check($r === true, 'manage_options + valid wp_rest nonce: allowed');

// --- Service availability (503 when the engine is not loaded) --------------
// The mocks are declared above, so the engine appears loaded from here on.
check(ADC_Feed_Rest::is_valid_id('12') && !ADC_Feed_Rest::is_valid_id('0') && !ADC_Feed_Rest::is_valid_id('1x'), 'route id must be a positive integer');

// --- validate_create: happy path and strictness ----------------------------
$clean = ADC_Feed_Rest::validate_create($good_create);
check(is_array($clean) && $clean['status'] === 'active' && $clean['channel'] === 'google', 'valid create payload is accepted');
check(is_array($clean) && $clean['config']['google']['shipping_price'] === '15.00', 'price kept as decimal string');
check(is_array($clean) && $clean['config']['exclude_invalid'] === true, 'exclude_invalid defaults to true');
$bad = static fn (array $overrides): mixed => ADC_Feed_Rest::validate_create(array_replace_recursive($good_create, $overrides));
check(is_wp_error($bad(array('config' => array_merge($good_config, array('exclude_invalid' => 'yes'))))), 'exclude_invalid must be boolean');
check(is_wp_error($bad(array('unknown' => 1))), 'unknown top-level field rejected');
check(is_wp_error(ADC_Feed_Rest::validate_create(array_merge($good_create, array('file_path' => '/etc/x')))), 'file path field rejected');
check(is_wp_error($bad(array('channel' => 'nope'))) && $bad(array('channel' => 'nope'))->get_error_code() === 'adc_feed_invalid_channel', 'unknown channel: 400');
$olx = $bad(array('channel' => 'olx'));
check(is_wp_error($olx) && $olx->get_error_code() === 'adc_feed_channel_unavailable' && $olx->get_error_data()['status'] === 422, 'olx is listed but not available yet: 422');
$meta_with_google = $bad(array('channel' => 'meta'));
check(is_wp_error($meta_with_google) && $meta_with_google->get_error_code() === 'adc_feed_invalid_config', 'meta feed cannot carry the google block');
check(is_wp_error($bad(array('type' => 'custom'))), 'type other than standard rejected');
check(is_wp_error($bad(array('format' => 'tsv'))), 'format other than xml rejected');
check(is_wp_error($bad(array('name' => '   '))), 'blank name rejected');
check(is_wp_error($bad(array('name' => str_repeat('a', 121)))), 'name longer than 120 characters rejected');
check(ADC_Feed_Rest::validate_create(array_merge($good_create, array('name' => '<b>Feed</b>')))['name'] === 'Feed', 'name is sanitized (tags stripped)');
check(is_wp_error($bad(array('config' => array('scope' => 'everything')))), 'unknown scope rejected');
$smart_config = array('scope' => 'smart', 'categories' => array(), 'in_stock_only' => false, 'refresh' => 'daily',
    'smart_rule' => array('metric' => 'purchases', 'mode' => 'top_n', 'period_days' => 30, 'limit' => 50),
    'google' => array('shipping_country' => 'RO', 'shipping_price' => '0.00', 'shipping_service' => 'Standard', 'condition' => 'new'));
ADC_Feed_Field_Registry::$smart_label = true;
$smart_create = ADC_Feed_Rest::validate_create(array('name' => 'Smart', 'channel' => 'google', 'format' => 'xml', 'config' => $smart_config));
check(is_array($smart_create) && $smart_create['config']['scope'] === 'smart' && $smart_create['config']['refresh'] === 'daily', 'smart config accepts a strict rule and cadence');
$smart_label = array_values(array_filter($smart_create['params'] ?? array(), static fn(array $row): bool => ($row['field'] ?? '') === 'custom_label_0'));
check(count($smart_label) === 1 && $smart_label[0]['source'] === array('type' => 'constant', 'value' => 'purchases_30d'), 'smart config automatically adds an editable constant label mapping');
ADC_Feed_Field_Registry::$smart_label = false;
check(is_wp_error(ADC_Feed_Rest::validate_create(array('name' => 'Bad smart', 'channel' => 'google', 'format' => 'xml', 'config' => array_replace($smart_config, array('smart_rule' => array_merge($smart_config['smart_rule'], array('script' => 'x'))))))), 'smart config rejects unknown rule keys');
check(is_wp_error(ADC_Feed_Rest::validate_create(array('name' => 'Bad cadence', 'channel' => 'google', 'format' => 'xml', 'config' => array_replace($smart_config, array('refresh' => 'every-minute'))))), 'smart config rejects unsupported refresh cadence');
$low_rule = $smart_config;
$low_rule['smart_rule'] = array('metric' => 'purchases', 'mode' => 'low_conversion', 'period_days' => 30, 'limit' => 50);
check(is_wp_error(ADC_Feed_Rest::validate_create(array('name' => 'Bad low conversion', 'channel' => 'google', 'format' => 'xml', 'config' => $low_rule))), 'low-conversion rule requires views ranking');
$low_rule['smart_rule'] = array('metric' => 'views', 'mode' => 'low_conversion', 'period_days' => 30, 'limit' => 50);
$low_valid = ADC_Feed_Rest::validate_create(array('name' => 'Low conversion', 'channel' => 'google', 'format' => 'xml', 'config' => $low_rule));
check(is_array($low_valid) && ($low_valid['config']['smart_rule']['conversion_percent'] ?? null) === 2, 'low-conversion defaults to an explicit configurable 2% threshold');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('scope' => 'all'))))), 'scope all with categories rejected (ambiguous)');
$filtered_empty = $good_create;
$filtered_empty['config'] = $good_config;
$filtered_empty['config']['categories'] = array();
check(is_wp_error(ADC_Feed_Rest::validate_create($filtered_empty)), 'filtered scope without categories is rejected rather than widened to all products');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('categories' => array('Bad Slug!')))))), 'category slug must be lowercase slug');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('categories' => array_fill(0, 51, 'a')))))), 'more than 50 categories rejected');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('in_stock_only' => 'yes'))))), 'in_stock_only must be boolean');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('extra' => true))))), 'unknown config key rejected');
check(is_wp_error($bad(array('config' => array('google' => array('shipping_country' => 'ro'))))), 'country must be two uppercase letters');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('shipping_price' => '1.234'))))))), 'price with three decimals rejected');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('shipping_price' => '-5'))))))), 'negative price rejected');
check(is_wp_error($bad(array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('condition' => 'broken'))))))), 'condition enum enforced');
check(is_wp_error($bad(array('config' => 'string'))), 'config must be an object');
$price_num = ADC_Feed_Rest::validate_create(array_replace_recursive($good_create, array('config' => array('google' => array('shipping_price' => 9.5)))));
check(is_array($price_num) && $price_num['config']['google']['shipping_price'] === '9.50', 'numeric price normalized to two decimals');

// --- Meta and TikTok: channel block, formats, labels, csv option ------------
$meta_config = array(
    'scope' => 'all', 'categories' => array(), 'in_stock_only' => false, 'csv_excel_safe' => true,
    'meta'  => array('condition' => 'new', 'brand' => 'ADC Shop', 'internal_labels' => array('Vara', '#promo', 'vara')),
);
$meta_create = array('name' => 'Meta RO', 'channel' => 'meta', 'format' => 'csv', 'config' => $meta_config);
$meta_ok = ADC_Feed_Rest::validate_create($meta_create);
$meta_without_csv_flag = $meta_config;
unset($meta_without_csv_flag['csv_excel_safe']);
$meta_csv_safe_default = ADC_Feed_Rest::validate_create(array('name' => 'Meta default safe', 'channel' => 'meta', 'format' => 'csv', 'config' => $meta_without_csv_flag));
check(is_array($meta_csv_safe_default) && $meta_csv_safe_default['config']['csv_excel_safe'] === true, 'meta CSV defaults to spreadsheet formula protection when config key is missing');
check(is_array($meta_ok) && $meta_ok['format'] === 'csv' && $meta_ok['channel'] === 'meta', 'meta: csv format from the channel list is accepted');
check(is_array($meta_ok) && $meta_ok['config']['meta']['internal_labels'] === array('vara', '#promo'), 'meta: labels lowercased and deduplicated');
check(is_array($meta_ok) && $meta_ok['config']['csv_excel_safe'] === true && $meta_ok['config']['exclude_invalid'] === true
    && !array_key_exists('google', $meta_ok['config']), 'meta: only the meta block is stored, CSV protection and invalid-item exclusion kept');
$meta_default_format = ADC_Feed_Rest::validate_create(array('name' => 'M', 'channel' => 'meta', 'config' => array_merge($meta_config, array('csv_excel_safe' => false))));
check(is_array($meta_default_format) && $meta_default_format['format'] === 'csv', 'meta: without a format the first channel format is used');

// Top-level replacement only: callers pass a complete config (list values must not merge by index).
$meta_bad = static fn (array $overrides): mixed => ADC_Feed_Rest::validate_create(array_merge($meta_create, $overrides));
check(is_wp_error($meta_bad(array('format' => 'tsv'))) && $meta_bad(array('format' => 'tsv'))->get_error_code() === 'adc_feed_format_not_allowed' && $meta_bad(array('format' => 'tsv'))->get_error_data()['status'] === 422, 'meta: a format outside the channel list is refused (422)');
$with_meta = static fn (array $meta_overrides): array => array_merge($meta_config, array('meta' => array_merge($meta_config['meta'], $meta_overrides)));
$csv_on_xml = $meta_bad(array('config' => array_merge($meta_config, array('csv_excel_safe' => true)), 'format' => 'xml'));
check(is_wp_error($csv_on_xml) && $csv_on_xml->get_error_code() === 'adc_feed_invalid_config', 'csv_excel_safe is refused for a non-csv format');
$six_labels = $meta_bad(array('config' => $with_meta(array('internal_labels' => array('a', 'b', 'c', 'd', 'e', 'f')))));
check(is_wp_error($six_labels) && $six_labels->get_error_code() === 'adc_feed_invalid_labels', 'meta: more than 5 internal labels refused');
$long_label = $meta_bad(array('config' => $with_meta(array('internal_labels' => array(str_repeat('a', 111))))));
check(is_wp_error($long_label) && $long_label->get_error_code() === 'adc_feed_invalid_label', 'meta: label longer than 110 characters refused');
$markup_label = $meta_bad(array('config' => $with_meta(array('internal_labels' => array('<b>x</b>')))));
check(is_array($markup_label) && $markup_label['config']['meta']['internal_labels'] === array('x'), 'meta: markup in a label is stripped before validation');
$no_brand = $meta_bad(array('config' => $with_meta(array('brand' => ''))));
check(is_wp_error($no_brand) && $no_brand->get_error_code() === 'adc_feed_invalid_brand', 'meta: brand is required');
$bad_condition = $meta_bad(array('config' => $with_meta(array('condition' => 'broken'))));
check(is_wp_error($bad_condition) && $bad_condition->get_error_code() === 'adc_feed_invalid_condition', 'meta: condition enum enforced');
$with_tiktok = $meta_bad(array('config' => array_merge($meta_config, array('tiktok' => array('condition' => 'new', 'brand' => 'x')))));
check(is_wp_error($with_tiktok) && $with_tiktok->get_error_code() === 'adc_feed_invalid_config', 'meta feed cannot carry the tiktok block');

$tiktok_create = array(
    'name' => 'TikTok RO', 'channel' => 'tiktok', 'format' => 'csv',
    'config' => array('scope' => 'all', 'categories' => array(), 'in_stock_only' => true, 'csv_excel_safe' => false,
        'tiktok' => array('condition' => 'refurbished', 'brand' => 'ADC Shop')),
);
$tiktok_ok = ADC_Feed_Rest::validate_create($tiktok_create);
check(is_array($tiktok_ok) && $tiktok_ok['config']['tiktok']['brand'] === 'ADC Shop' && $tiktok_ok['format'] === 'csv', 'tiktok: accepted with condition and brand');
$tiktok_no_brand = ADC_Feed_Rest::validate_create(array_replace_recursive($tiktok_create, array('config' => array('tiktok' => array('brand' => '')))));
check(is_wp_error($tiktok_no_brand) && $tiktok_no_brand->get_error_code() === 'adc_feed_invalid_brand', 'tiktok: empty brand refused');
check(is_wp_error(ADC_Feed_Rest::validate_create(array_replace_recursive($tiktok_create, array('config' => array('meta' => array('condition' => 'new', 'brand' => 'x')))))), 'tiktok feed cannot carry the meta block');

// Channel list: formats and availability come from the registry only.
$channels = ADC_Feed_Rest::available_channels();
check(array_keys($channels) === array('google', 'meta', 'tiktok') && $channels['meta']['formats'] === array('csv', 'xml'), 'available_channels: google, meta, tiktok with their formats');
check(ADC_Feed_Rest::channel_info('olx') === null, 'channel_info: olx is not available');

// URL carries the extension of the feed's format.
ADC_Feed_Store::$rows = array();
$meta_id = ADC_Feed_Store::create($meta_ok + array('status' => 'active'));
$meta_get = ADC_Feed_Rest::handle_get(req(array('id' => (string) $meta_id)));
check(str_ends_with((string) $meta_get->data['feed']['url'], '/adc-feed/slug' . $meta_id . '.csv'), 'get: meta URL ends with the csv extension');
check($meta_get->data['feed']['format'] === 'csv' && $meta_get->data['feed']['config']['meta']['brand'] === 'ADC Shop', 'get: meta feed re-validated with its channel and format');
$meta_update = ADC_Feed_Rest::handle_update(req(array('id' => (string) $meta_id, 'config' => array_merge($meta_config, array('csv_excel_safe' => false)))));
check($meta_update instanceof WP_REST_Response && $meta_update->data['feed']['config']['csv_excel_safe'] === false, 'update: meta config validated against the stored channel and format');
$meta_update_csv_on_xml = ADC_Feed_Rest::validate_update(array('config' => $meta_config), 'meta', 'xml');
check(is_wp_error($meta_update_csv_on_xml) && $meta_update_csv_on_xml->get_error_code() === 'adc_feed_invalid_config', 'update: csv_excel_safe is refused when the stored format is not csv');
ADC_Feed_Store::$rows = array();
ADC_Feed_Store::$next_id = 1;
ADC_Feed_Generator::$requested = array();

// --- validate_update --------------------------------------------------------
check(ADC_Feed_Rest::validate_update(array()) === array(), 'empty update is an empty change set');
check(is_wp_error(ADC_Feed_Rest::validate_update(array('status' => 'deleted'))), 'status outside active|disabled rejected');
check(ADC_Feed_Rest::validate_update(array('status' => 'disabled')) === array('status' => 'disabled'), 'status change accepted');
check(is_wp_error(ADC_Feed_Rest::validate_update(array('slug' => 'x'))), 'slug cannot be set directly');
check(ADC_Feed_Rest::validate_update(array('name' => 'Nou'))['name'] === 'Nou', 'rename accepted');

// Callback-level guard: a handler refuses a request without permission even when
// the route's permission_callback is skipped (defence in depth).
$GLOBALS['caps'] = array('manage_options' => false);
$denied = ADC_Feed_Rest::handle_list(new WP_REST_Request());
check(is_wp_error($denied) && $denied->get_error_data()['status'] === 403, 'handler: list refuses without manage_options');
$GLOBALS['caps'] = array('manage_options' => true);
$denied = ADC_Feed_Rest::handle_create(new WP_REST_Request(array('name' => 'x')));
check(is_wp_error($denied) && 'adc_feed_bad_nonce' === $denied->get_error_code(), 'handler: create refuses without nonce');
ADC_Feed_Store::$rows = array();

// --- Handlers ---------------------------------------------------------------
$list = ADC_Feed_Rest::handle_list(req());
check($list instanceof WP_REST_Response && $list->data === array('items' => array()), 'list: empty store returns no items');

$created = ADC_Feed_Rest::handle_create(req($good_create));
check($created instanceof WP_REST_Response && $created->status === 201, 'create: 201');
check($created->data['queued'] === true && ADC_Feed_Generator::$requested === array(1), 'create: first generation queued for the new id');
check(!isset($created->data['feed']['file_path']) && !array_key_exists('secret', $created->data['feed']), 'create response carries no paths or secrets');
check($created->data['feed']['url'] === 'https://example.test/adc-feed/slug1.xml', 'create response includes the public URL');

$bad_create = ADC_Feed_Rest::handle_create(req(array_merge($good_create, array('name' => ''))));
check(is_wp_error($bad_create) && $bad_create->get_error_data()['status'] === 400, 'create: invalid body yields 400 and writes nothing');
check(count(ADC_Feed_Store::$rows) === 1, 'create: store untouched by the invalid body');

$got = ADC_Feed_Rest::handle_get(req(array('id' => '1')));
check($got instanceof WP_REST_Response && $got->data['feed']['name'] === 'Feed Google principal', 'get: returns the feed');
check(is_array($got->data['feed']['config']['params'] ?? null) && $got->data['feed']['params'] === $got->data['feed']['config']['params'], 'get: hydrated mappings are preserved in config and the compatibility params field');
$stored_config = ADC_Feed_Store::$rows[1]['config'];
ADC_Feed_Store::$rows[1]['config'] = array_merge($good_config, array('scope' => 'legacy-scope', 'private_path' => '/private/file'));
$legacy_get = ADC_Feed_Rest::handle_get(req(array('id' => '1')));
check($legacy_get instanceof WP_REST_Response && $legacy_get->data['feed']['config']['scope'] === 'legacy-scope', 'get: known stored config survives current validation failure');
check(!isset($legacy_get->data['feed']['config']['private_path']), 'get: invalid config fallback still drops unknown fields');
ADC_Feed_Store::$rows[1]['config'] = $stored_config;
$missing = ADC_Feed_Rest::handle_get(req(array('id' => '99')));
check(is_wp_error($missing) && $missing->get_error_data()['status'] === 404, 'get: unknown id is 404');

$patched = ADC_Feed_Rest::handle_update(req(array('id' => '1', 'status' => 'disabled')));
check($patched instanceof WP_REST_Response && $patched->data['feed']['status'] === 'disabled' && false === $patched->data['queued'], 'update: disabling does not queue generation');
$disabled_config = ADC_Feed_Rest::handle_update(req(array('id' => '1', 'config' => $good_config)));
check($disabled_config instanceof WP_REST_Response && false === $disabled_config->data['queued'], 'config edit while disabled is saved without scheduling an unusable run');
$reactivated = ADC_Feed_Rest::handle_update(req(array('id' => '1', 'status' => 'active')));
check($reactivated instanceof WP_REST_Response && true === $reactivated->data['queued'] && ADC_Feed_Generator::$requested === array(1, 1), 'reactivation queues a fresh generation');
$config_patch = ADC_Feed_Rest::handle_update(req(array('id' => '1', 'config' => $good_config)));
check($config_patch instanceof WP_REST_Response && true === $config_patch->data['queued'], 'active config PATCH queues a fresh generation');
$nothing = ADC_Feed_Rest::handle_update(req(array('id' => '1')));
check(is_wp_error($nothing) && $nothing->get_error_code() === 'adc_feed_nothing_to_update', 'update: empty body is 400');
$patch_missing = ADC_Feed_Rest::handle_update(req(array('id' => '42', 'name' => 'x')));
check(is_wp_error($patch_missing) && $patch_missing->get_error_data()['status'] === 404, 'update: unknown id is 404');

$regen = ADC_Feed_Rest::handle_regenerate(req(array('id' => '1')));
check($regen instanceof WP_REST_Response && $regen->status === 202 && $regen->data['queued'] === true, 'regenerate: 202, queued not synchronous');

$slug = ADC_Feed_Rest::handle_regenerate_slug(req(array('id' => '1')));
check($slug instanceof WP_REST_Response && $slug->data['slug'] === 'fresh1' && str_ends_with($slug->data['url'], '/adc-feed/fresh1.xml') && true === $slug->data['queued'], 'regenerate-slug: returns the new URL and queues a replacement snapshot');

// Rate limit: 10 manual regenerations per minute, then 429, then a fresh window.
$GLOBALS['transients'] = array();
$limited = null;
for ($i = 1; $i <= ADC_Feed_Rest::REGEN_MAX + 1; $i++) {
    $res = ADC_Feed_Rest::handle_regenerate(req(array('id' => '1')));
    if (is_wp_error($res)) {
        $limited = $res;
        break;
    }
}
check(is_wp_error($limited) && $limited->get_error_data()['status'] === 429, 'regenerate: 11th request in a minute is 429');
// Age the window instead of moving the clock (time() is native and cannot be stubbed).
$GLOBALS['transients']['adc_feed_regen_7']['started'] = 0;
$after = ADC_Feed_Rest::handle_regenerate(req(array('id' => '1')));
check($after instanceof WP_REST_Response, 'regenerate: budget resets after the window');

$deleted = ADC_Feed_Rest::handle_delete(req(array('id' => '1')));
check($deleted instanceof WP_REST_Response && $deleted->data['deleted'] === true, 'delete: removes the feed');
check(is_wp_error(ADC_Feed_Rest::handle_delete(req(array('id' => '1')))), 'delete: second delete is 404');

// --- Last run in GET /feeds/{id} and GET /feeds -------------------------------
ADC_Feed_Store::$runs = array();
$run_feed = ADC_Feed_Store::create($good_create);
$no_run = ADC_Feed_Rest::handle_get(req(array('id' => (string) $run_feed)));
check($no_run instanceof WP_REST_Response && array_key_exists('last_run', $no_run->data['feed']) && $no_run->data['feed']['last_run'] === null, 'get: last_run is null before any run');

ADC_Feed_Store::$runs[$run_feed] = array(
    'status'      => 'failed',
    'started_at'  => '2026-11-07 10:00:00',
    'finished_at' => '2026-11-07 10:00:09',
    'examined'    => 12,
    'exported'    => 9,
    'errors'      => array(
        array('code' => 'feed_busy', 'count' => 2),
        array('code' => 'Bad Path /var/x'),
        'duplicate_feed_item_id',
        array('code' => 'feed_busy', 'count' => 1),
        42,
    ),
    'warnings'    => array_map(static fn (int $i): string => 'warn_' . $i, range(1, 60)),
    'file_path'   => '/var/www/secret.xml',
    'trace'       => 'stack trace',
);
$with_run = ADC_Feed_Rest::handle_get(req(array('id' => (string) $run_feed)));
$lr = $with_run->data['feed']['last_run'] ?? null;
check(is_array($lr) && $lr['status'] === 'failed' && $lr['examined'] === 12 && $lr['exported'] === 9, 'get: last_run carries status and counters');
check(is_array($lr) && $lr['errors'] === array(
    array('code' => 'feed_busy', 'count' => 3),
    array('code' => 'unknown_diagnostic', 'count' => 2),
    array('code' => 'duplicate_feed_item_id', 'count' => 1),
), 'get: diagnostics are whitelisted {code,count} rows with unknown values normalized');
check(is_array($lr) && $lr['warnings'] === array(array('code' => 'unknown_diagnostic', 'count' => 60)), 'get: duplicate unknown warnings are combined before applying the cap');
check(is_array($lr) && $lr['warnings'] === ADC_Feed_Rest::format_run(array('warnings' => array_map(static fn (int $i): string => 'warn_' . $i, range(1, 60))))['warnings'], 'format_run: unknown diagnostic aggregation is deterministic');
check(is_array($lr) && !array_key_exists('file_path', $lr) && !array_key_exists('trace', $lr)
    && !str_contains(json_encode($lr), '/var/x'), 'get: last_run drops paths and arbitrary diagnostic text');
check(!str_contains(json_encode($with_run->data), '/var/www'), 'get: no filesystem path reaches the JSON');

$listed = ADC_Feed_Rest::handle_list(req());
$item = array_values(array_filter($listed->data['items'], static fn (array $i): bool => $i['id'] === $run_feed))[0] ?? array();
check(($item['last_run']['status'] ?? '') === 'failed', 'list: each item carries its last_run');

// Invalid UTF-8 in a message must not break the JSON response.
ADC_Feed_Store::$runs[$run_feed]['errors'] = array("feed\xC3\x28bad", array('code' => "bad\xC3\x28"));
$bad_utf8 = ADC_Feed_Rest::handle_get(req(array('id' => (string) $run_feed)));
check(is_string(json_encode($bad_utf8->data)) && json_last_error() === JSON_ERROR_NONE, 'get: malformed UTF-8 in a message still yields valid JSON');
ADC_Feed_Store::$runs = array();

// --- Parameters: registry, defaults, validation, preview, update -------------------
ADC_Feed_Store::$rows = array();
ADC_Feed_Store::$next_id = 1;
ADC_Feed_Generator::$requested = array();
$routes_ok = isset($GLOBALS['routes']['adc/v1/feeds/fields']) && isset($GLOBALS['routes']['adc/v1/feeds/preview']);
check($routes_ok, 'routes: fields and preview are registered');

$fields_res = ADC_Feed_Rest::handle_fields(req(array('channel' => 'google')));
check($fields_res instanceof WP_REST_Response && $fields_res->status === 200, 'fields: 200 for an available channel');
check(array_column($fields_res->data['items'], 'key') === array('title', 'color', 'gender'), 'fields: registry keys in order');
check($fields_res->data['items'][0]['required'] === true && $fields_res->data['items'][0]['default_source'] === array('type' => 'native', 'value' => 'name'), 'fields: required flag and default source kept');
check($fields_res->data['items'][2]['enum'] === array('male', 'female', 'unisex'), 'fields: enum kept');
check(array_column($fields_res->data['sources']['native'], 'value') === array('title', 'feed_item_id'), 'fields: native source choices come from ADC_Feed_Params');
check(count($fields_res->data['defaults']) === 1 && $fields_res->data['defaults'][0]['field'] === 'title', 'fields: defaults come from defaults_for()');
$fields_olx = ADC_Feed_Rest::handle_fields(req(array('channel' => 'olx')));
check(is_wp_error($fields_olx) && $fields_olx->get_error_data()['status'] === 422, 'fields: unavailable channel is 422');
$fields_nope = ADC_Feed_Rest::handle_fields(req(array('channel' => 'nope')));
check(is_wp_error($fields_nope) && $fields_nope->get_error_code() === 'adc_feed_invalid_channel', 'fields: unknown channel is 400');
check(is_wp_error(ADC_Feed_Rest::handle_fields(new WP_REST_Request(array('channel' => 'google'), array()))), 'fields: nonce required');

$params_ok = array(
    array('field' => 'title', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(array('op' => 'trim', 'arg' => '')), 'enabled' => true),
    array('field' => 'color', 'source' => array('type' => 'attribute', 'value' => 'pa_culoare'), 'transforms' => array(), 'enabled' => false),
);
$p_create = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => $params_ok)));
check(is_array($p_create) && $p_create['params'] === $params_ok, 'params: a valid list is stored as given');
$p_default = ADC_Feed_Rest::validate_create($good_create);
check(is_array($p_default) && $p_default['params'][0]['field'] === 'title', 'params: an absent list gets the defaults');
$p_source = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => array(array('field' => 'title', 'source' => array('type' => 'sql', 'value' => 'x'), 'transforms' => array(), 'enabled' => true)))));
check(is_wp_error($p_source) && $p_source->get_error_code() === 'adc_feed_invalid_param', 'params: unknown source type refused');
$p_op = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => array(array('field' => 'title', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(array('op' => 'eval', 'arg' => 'x')), 'enabled' => true)))));
check(is_wp_error($p_op), 'params: unknown transform refused');
$p_product = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => array(array('field' => 'title', 'source' => array('type' => 'product', 'value' => 'title'), 'transforms' => array(), 'enabled' => true)))));
check(is_array($p_product) && $p_product['params'][0]['source'] === array('type' => 'native', 'value' => 'title'), 'params: registry type product is read as native');
$p_dup = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => array($params_ok[0], $params_ok[0]))));
check(is_wp_error($p_dup) && $p_dup->get_error_code() === 'adc_feed_duplicate_param', 'params: the same field twice refused');
$p_extra = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => array(array('field' => 'title', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(), 'enabled' => true, 'sql' => 'x')))));
check(is_wp_error($p_extra), 'params: unknown row key refused');
$p_many = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => array_fill(0, 101, $params_ok[1]))));
check(is_wp_error($p_many), 'params: more than 100 rows refused');
$p_registry = ADC_Feed_Rest::validate_create(array_merge($good_create, array('params' => array(array('field' => 'bad_field', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(), 'enabled' => true)))));
check(is_wp_error($p_registry) && $p_registry->get_error_code() === 'adc_feed_invalid_params' && $p_registry->get_error_data()['errors'] === array('title_too_long'), 'params: registry rejection is 422 with codes only');

$GLOBALS['transients'] = array();
ADC_Feed_Generator::$previews = array();
$pv = ADC_Feed_Rest::handle_preview(req(array('channel' => 'google', 'format' => 'xml', 'config' => $good_config, 'n' => 3)));
check($pv instanceof WP_REST_Response && $pv->status === 200 && count($pv->data['rows']) === 3, 'preview: the requested number of rows');
check($pv->data['warnings'] === array(array('code' => 'id_incompatible', 'count' => 1)), 'preview: warnings are codes with counts');
check(!str_contains(json_encode($pv->data), '/var/www') && ADC_Feed_Generator::$previews === array(3), 'preview: generator called with n and response contains only safe feed data');
check($pv->data['selection'] === array(array('product_id' => 17, 'metric' => 'views', 'value' => 24)), 'preview: only valid metric values are returned for the smart-selection table');
$pv_default = ADC_Feed_Rest::handle_preview(req(array('channel' => 'google', 'config' => $good_config)));
check($pv_default instanceof WP_REST_Response && end(ADC_Feed_Generator::$previews) === 5, 'preview: n defaults to 5');
$pv_big = ADC_Feed_Rest::handle_preview(req(array('channel' => 'google', 'config' => $good_config, 'n' => 11)));
check(is_wp_error($pv_big) && $pv_big->get_error_code() === 'adc_feed_invalid_preview', 'preview: n above 10 refused');
$pv_zero = ADC_Feed_Rest::handle_preview(req(array('channel' => 'google', 'config' => $good_config, 'n' => 0)));
check(is_wp_error($pv_zero), 'preview: n of 0 refused');
$pv_cfg = ADC_Feed_Rest::handle_preview(req(array('channel' => 'google', 'config' => 'x')));
check(is_wp_error($pv_cfg) && $pv_cfg->get_error_code() === 'adc_feed_invalid_config', 'preview: invalid config refused before generation');
$pv_params = ADC_Feed_Rest::handle_preview(req(array('channel' => 'google', 'config' => $good_config, 'params' => array(array('field' => 'bad_field', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(), 'enabled' => true)))));
check(is_wp_error($pv_params) && $pv_params->get_error_data()['status'] === 422, 'preview: invalid params refused');
check(is_wp_error(ADC_Feed_Rest::handle_preview(new WP_REST_Request(array('channel' => 'google', 'config' => $good_config), array()))), 'preview: nonce required');
$GLOBALS['transients'] = array();
$preview_limited = null;
for ($i = 1; $i <= ADC_Feed_Rest::PREVIEW_LIMIT + 1; $i++) {
    $r = ADC_Feed_Rest::handle_preview(req(array('channel' => 'google', 'config' => $good_config, 'n' => 1)));
    if (is_wp_error($r)) {
        $preview_limited = $r;
        break;
    }
}
check(is_wp_error($preview_limited) && $preview_limited->get_error_data()['status'] === 429, 'preview: the call after the budget is 429');

ADC_Feed_Store::$rows = array();
ADC_Feed_Store::$next_id = 1;
$pid = ADC_Feed_Store::create($p_default + array('status' => 'active'));
$pu = ADC_Feed_Rest::handle_update(req(array('id' => (string) $pid, 'params' => $params_ok)));
check($pu instanceof WP_REST_Response && $pu->data['queued'] === true, 'update: changing params on an active feed queues generation');
check(ADC_Feed_Store::$rows[$pid]['params'] === $params_ok, 'update: validated params are stored');
$pu_bad = ADC_Feed_Rest::handle_update(req(array('id' => (string) $pid, 'params' => array(array('field' => 'bad_field', 'source' => array('type' => 'native', 'value' => 'name'), 'transforms' => array(), 'enabled' => true)))));
check(is_wp_error($pu_bad) && $pu_bad->get_error_data()['status'] === 422, 'update: invalid params refused');
$pg = ADC_Feed_Rest::handle_get(req(array('id' => (string) $pid)));
check($pg->data['feed']['params'] === $params_ok, 'get: stored params returned');
ADC_Feed_Store::$rows = array();
ADC_Feed_Store::$next_id = 1;

// --- Category fields: default_category and category_map in Google, Meta and TikTok ---
$cat_google = $good_config;
$cat_google['google']['default_category'] = 'Încălțăminte > Pantofi';
$cat_google['google']['category_map'] = array(12 => 'Pantofi sport', 15 => '  ', 18 => 'Sandale');
$cat_ok = ADC_Feed_Rest::validate_create(array_merge($good_create, array('config' => $cat_google)));
check(is_array($cat_ok) && $cat_ok['config']['google']['default_category'] === 'Încălțăminte > Pantofi', 'categories: google default category kept');
check(is_array($cat_ok) && $cat_ok['config']['google']['category_map'] === array(12 => 'Pantofi sport', 18 => 'Sandale'), 'categories: map kept, empty values dropped');

$cat_meta = array_merge($meta_config, array('meta' => array_merge($meta_config['meta'], array('default_category' => 'Apparel', 'category_map' => array(7 => 'Shoes')))));
$cat_meta_ok = ADC_Feed_Rest::validate_create(array('name' => 'M cat', 'channel' => 'meta', 'format' => 'csv', 'config' => $cat_meta));
check(is_array($cat_meta_ok) && $cat_meta_ok['config']['meta']['category_map'] === array(7 => 'Shoes') && $cat_meta_ok['config']['meta']['default_category'] === 'Apparel', 'categories: meta block carries default and map');
$cat_tiktok = ADC_Feed_Rest::validate_create(array('name' => 'T cat', 'channel' => 'tiktok', 'format' => 'xml', 'config' => array(
    'scope' => 'all', 'categories' => array(), 'in_stock_only' => false, 'csv_excel_safe' => false,
    'tiktok' => array('condition' => 'new', 'brand' => 'ADC', 'default_category' => '', 'category_map' => array(3 => 'Fashion')),
)));
check(is_array($cat_tiktok) && $cat_tiktok['config']['tiktok']['category_map'] === array(3 => 'Fashion') && $cat_tiktok['config']['tiktok']['default_category'] === '', 'categories: tiktok block carries map, empty default allowed');

$cat_bad_key = ADC_Feed_Rest::validate_create(array_merge($good_create, array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('category_map' => array('abc' => 'X')))))))) ;
check(is_wp_error($cat_bad_key) && $cat_bad_key->get_error_code() === 'adc_feed_invalid_category_map', 'categories: non-numeric term id refused');
$cat_zero = ADC_Feed_Rest::validate_create(array_merge($good_create, array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('category_map' => array(0 => 'X')))))))) ;
check(is_wp_error($cat_zero), 'categories: term id 0 refused');
$cat_long = ADC_Feed_Rest::validate_create(array_merge($good_create, array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('default_category' => str_repeat('a', 751)))))))) ;
check(is_wp_error($cat_long) && $cat_long->get_error_code() === 'adc_feed_invalid_category', 'categories: default longer than 750 refused');
$cat_type = ADC_Feed_Rest::validate_create(array_merge($good_create, array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('default_category' => 5)))))));
check(is_wp_error($cat_type), 'categories: non-text default refused');
$cat_too_many = ADC_Feed_Rest::validate_create(array_merge($good_create, array('config' => array_merge($good_config, array('google' => array_merge($good_config['google'], array('category_map' => array_combine(range(1, 501), array_fill(0, 501, 'x')))))))));
check(is_wp_error($cat_too_many), 'categories: more than 500 mapped categories refused');

// Diagnostic codes: the static list, the generator list (when provided) and unknown codes.
$codes_run = ADC_Feed_Rest::format_run(array('errors' => array('id_incompatible', 'generator_only_code', 'feed_output_invalid', 'not_a_real_code')));
check(array_column($codes_run['errors'], 'code') === array('id_incompatible', 'generator_only_code', 'feed_output_invalid', 'unknown_diagnostic'), 'run diagnostics: generator codes pass through, unknown ones collapse');

// --- Meta and TikTok diagnostic codes reach the client as codes ---------------
$new_codes = ADC_Feed_Rest::format_run(array('errors' => array('color_too_long', 'sale_price_not_lower', 'sku_id_missing', 'feed_params_invalid', 'param_type_mismatch', 'param_value_too_long', 'made_up_code')));
check(array_column($new_codes['errors'], 'code') === array('color_too_long', 'sale_price_not_lower', 'sku_id_missing', 'feed_params_invalid', 'param_type_mismatch', 'param_value_too_long', 'unknown_diagnostic'), 'run diagnostics: Meta/TikTok and parameter codes pass through, unknown codes become unknown_diagnostic');

// --- Response whitelist -----------------------------------------------------
$formatted = ADC_Feed_Rest::format_feed(array(
    'id' => 3, 'slug' => 'abc', 'name' => 'N', 'type' => 'standard', 'channel' => 'google', 'format' => 'xml',
    'status' => 'active', 'config' => $good_config, 'created_at' => 'c', 'updated_at' => 'u',
    'file_path' => '/var/www/x.xml', 'secret' => 'hunter2', 'stack' => 'trace',
));
check(!array_key_exists('file_path', $formatted) && !array_key_exists('secret', $formatted) && !array_key_exists('stack', $formatted), 'format_feed drops non-whitelisted fields');
check(!str_contains(json_encode($formatted), '/var/www'), 'no filesystem path leaks into JSON');

// --- Engine missing: 503 ----------------------------------------------------
// The mocks stay loaded for the whole run, so check the service helper directly
// with a class name that does not exist.
$service = new ReflectionMethod(ADC_Feed_Rest::class, 'service');
$service->setAccessible(true);
$unavailable = $service->invoke(null, 'ADC_Feed_Does_Not_Exist');
check(is_wp_error($unavailable) && $unavailable->get_error_data()['status'] === 503, 'missing engine class yields 503, not a fatal');

echo $failed > 0 ? "feed-rest-test: FAILED {$failed}\n" : "feed-rest-test: PASS\n";
exit($failed > 0 ? 1 : 0);
