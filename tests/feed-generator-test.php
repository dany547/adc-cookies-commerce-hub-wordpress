<?php
declare(strict_types=1);

$generator_wp_root = sys_get_temp_dir() . '/adc-feed-wp-root-' . bin2hex(random_bytes(8));
$generator_upload_root = sys_get_temp_dir() . '/adc-feed-wp-uploads-' . bin2hex(random_bytes(8));
mkdir($generator_wp_root, 0775, true);
mkdir($generator_upload_root, 0775, true);
define('ABSPATH', $generator_wp_root . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
if (!defined('LIBXML_NONET')) { define('LIBXML_NONET', 2048); }
if (!defined('LIBXML_NOERROR')) { define('LIBXML_NOERROR', 32); }
if (!defined('LIBXML_NOWARNING')) { define('LIBXML_NOWARNING', 64); }
if (!function_exists('libxml_use_internal_errors')) {
    function libxml_use_internal_errors(?bool $use = null): bool { static $state = false; $old = $state; if (null !== $use) { $state = $use; } return $old; }
    function libxml_clear_errors(): void {}
    function libxml_get_errors(): array { return array(); }
}
if (!class_exists('XMLReader')) {
    // Streaming test double for hosts without ext-xmlreader; production still requires the native parser.
    final class XMLReader
    {
        public const DOC_TYPE = 10;
        public const ELEMENT = 1;
        public int $nodeType = 0;
        public int $depth = 0;
        public string $localName = '';
        private mixed $stream = null;
        private bool $root_emitted = false;
        private string $tail = '';
        public function open(string $uri, ?string $encoding = null, int $flags = 0): bool { $this->stream = fopen($uri, 'rb'); return is_resource($this->stream); }
        public function read(): bool
        {
            if (!is_resource($this->stream)) { return false; }
            while (!feof($this->stream)) {
                $chunk = fread($this->stream, 1024);
                if (!is_string($chunk)) { return false; }
                $scan = $this->tail . $chunk;
                if (str_contains($scan, '<!DOCTYPE')) { $this->nodeType = self::DOC_TYPE; return true; }
                if (!$this->root_emitted && preg_match('/<rss(?:\s|>)/', $scan)) {
                    $this->root_emitted = true; $this->nodeType = self::ELEMENT; $this->depth = 0; $this->localName = 'rss'; return true;
                }
                $this->tail = substr($scan, -8);
            }
            return false;
        }
        public function close(): bool { if (is_resource($this->stream)) { fclose($this->stream); } $this->stream = null; return true; }
    }
}
function __ (string $text, string $domain = ''): string { return $text; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value)); }
function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function home_url(string $path = ''): string { return 'https://shop.example' . $path; }
function wp_upload_dir(mixed $time = null, bool $create_dir = true): array { return array('basedir' => $GLOBALS['generator_upload_root'], 'baseurl' => 'https://shop.example/uploads'); }
function wp_remote_get(string $url, array $args = array()): array
{
    $path = $GLOBALS['generator_upload_root'] . '/adc-feeds/' . basename($url);
    $status = (int) ($GLOBALS['generator_http_status'] ?? 404);
    $body = 200 === $status && is_file($path) ? (string) file_get_contents($path) : '';
    if (str_ends_with($url, '.php') && str_contains($body, "<?php exit; ?>\n")) {
        $GLOBALS['generator_probe_exposed'] = true;
    }
    return array('response' => array('code' => $status), 'body' => $body);
}
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function is_wp_error(mixed $value): bool { return false; }
function get_woocommerce_currency(): string { return 'RON'; }
function wc_get_price_decimals(): int { return 2; }
function wc_get_price_to_display(object $product, array $args): float { return (float) $args['price']; }
function wp_date(string $format, ?int $timestamp = null): string { return date($format, $timestamp ?? time()); }
function wp_get_attachment_image_url(int $id, string $size = 'full'): string { return 'https://shop.example/uploads/' . $id . '.jpg'; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['generator_options'][$name] ?? $default; }
function maybe_serialize(mixed $value): string { return is_array($value) || is_object($value) ? serialize($value) : (string) $value; }
function wp_cache_delete(string $key, string $group = ''): bool { return true; }
function wp_schedule_single_event(int $timestamp, string $hook, array $args = array()): bool { $GLOBALS['generator_scheduled'][] = array($timestamp, $hook, $args); return true; }
function wp_next_scheduled(string $hook, array $args = array()): int|false { return false; }
function as_schedule_single_action(int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false): int
{
    $feed_id = (int) ($args[0] ?? 0);
    foreach ($GLOBALS['generator_as_actions'] as $action) {
        if ($action['feed_id'] === $feed_id && $action['status'] === 'pending') { return 0; }
    }
    $id = count($GLOBALS['generator_as_actions']) + 1;
    $GLOBALS['generator_as_actions'][] = array('id' => $id, 'feed_id' => $feed_id, 'status' => 'pending', 'timestamp' => $timestamp);
    return $id;
}
function as_get_scheduled_actions(array $args = array(), string $return_format = 'OBJECT'): array
{
    return array_values(array_map(
        static fn(array $action): int => $action['id'],
        array_filter($GLOBALS['generator_as_actions'], static fn(array $action): bool => $action['status'] === ($args['status'] ?? '') && $action['feed_id'] === (int) ($args['args'][0] ?? 0))
    ));
}
function add_option(string $name, mixed $value, string $deprecated = '', string|bool $autoload = 'yes'): bool
{
    if (array_key_exists($name, $GLOBALS['generator_options'])) return false;
    $GLOBALS['generator_options'][$name] = $value;
    return true;
}
function delete_option(string $name): bool { unset($GLOBALS['generator_options'][$name]); return true; }
function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0775, true); }
function set_transient(string $name, mixed $value, int $expiration = 0): bool { $GLOBALS['generator_transients'][$name] = $value; return true; }
function get_transient(string $name): mixed { return $GLOBALS['generator_transients'][$name] ?? false; }
function delete_transient(string $name): bool { unset($GLOBALS['generator_transients'][$name]); return true; }
function taxonomy_exists(string $taxonomy): bool { return true; }
function get_the_terms(int $id, string $taxonomy): array|false
{
    if ('product_brand' === $taxonomy) { return array((object) array('term_id' => 5, 'name' => 'Acme')); }
    return false;
}
function get_ancestors(int $id, string $taxonomy, string $resource_type): array { return array(); }
function get_term(int $id, string $taxonomy): object|false { return false; }
final class ADC_Feed_Scheduler { public const JOB_HOOK = 'adc_feed_generate'; }
final class ADC_Feed_Module { public const AS_GROUP = 'adc-feed'; }
final class ADC_Product_Stats
{
    public static function top(string $metric, int $days, int $limit): array
    {
        return array_slice($GLOBALS['generator_stats'][$metric] ?? array(), 0, min(100, $limit));
    }
}
final class ADC_Settings
{
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        if ('product_stats' === $group && 'enabled' === $key) return (bool) ($GLOBALS['generator_stats_enabled'] ?? false);
        if ('events' === $group && 'item_id_source' === $key) return $GLOBALS['generator_item_id_source'] ?? 'id';
        if ('feeds' === $group && 'chunk_size' === $key) return 100;
        if ('events' === $group && 'brand_taxonomy' === $key) return 'product_brand';
        return $default;
    }
}
final class GeneratorTestProduct
{
    public function __construct(private int $id = 7, private string $sku = 'tea-7') {}
    public function get_id(): int { return $this->id; }
    public function get_parent_id(): int { return 0; }
    public function get_status(): string { return 'publish'; }
    public function get_catalog_visibility(): string { return 'visible'; }
    public function is_type(mixed $type): bool { return false; }
    public function get_price(): string { return '25.50'; }
    public function get_regular_price(): string { return '25.50'; }
    public function get_sale_price(): string { return ''; }
    public function get_date_on_sale_from(): null { return null; }
    public function get_date_on_sale_to(): null { return null; }
    public function get_name(): string { return (in_array($GLOBALS['generator_product_mode'] ?? '', array('one-invalid', 'varied-invalid'), true) && 1 === $this->id) || ('varied-invalid' === ($GLOBALS['generator_product_mode'] ?? '') && 2 === $this->id) ? '' : 'Tea'; }
    public function get_sku(): string { return $this->sku; }
    public function get_meta(string $key, bool $single = true): string
    {
        return '_adc_feed_google_product_category' === $key ? (string) ($GLOBALS['generator_google_category'] ?? '') : '';
    }
    public function get_short_description(): string { return 'varied-invalid' === ($GLOBALS['generator_product_mode'] ?? '') && 3 === $this->id ? '' : 'Loose leaf tea'; }
    public function get_description(): string { return ''; }
    public function get_permalink(): string { return 'https://shop.example/product/tea'; }
    public function get_image_id(): int { return 70; }
    public function get_gallery_image_ids(): array { return 'meta' === ($GLOBALS['generator_product_mode'] ?? '') ? array(71) : array(); }
    public function get_stock_status(): string { return 'instock'; }
    public function is_on_backorder(int $quantity = 1): bool { return false; }
    public function get_weight(): string { return '0.2'; }
    public function is_on_sale(): bool { return false; }
    public function get_attributes(): array { return array(); }
    public function get_variation_attributes(): array { return array(); }
}
function wc_get_products(array $query): object
{
    if (!empty($GLOBALS['generator_mark_dirty'])) {
        $GLOBALS['generator_transients']['adc_feed_dirty_1'] = 1;
        $GLOBALS['generator_mark_dirty'] = false;
    }
    $ids = is_array($query['include'] ?? null)
        ? array_values(array_unique(array_filter(array_map('intval', $query['include']), static fn(int $id): bool => $id > 0)))
        : range(1, max(1, (int) ($GLOBALS['generator_product_count'] ?? 1)));
    $total = count($ids);
    $products = array();
    $start = (int) ($query['offset'] ?? 0);
    $end = min($total, $start + (int) ($query['limit'] ?? 100));
    for ($index = $start; $index < $end; ++$index) {
        $id = $ids[$index];
        $mode = $GLOBALS['generator_product_mode'] ?? '';
        $sku = 'duplicate' === $mode || ('cross-page-duplicate' === $mode && in_array($id, array(1, 101), true))
            ? 'same-sku'
            : 'tea-' . $id;
        if ('long' === $mode) { $sku = str_repeat('X', 60); }
        $products[] = new GeneratorTestProduct($id, $sku);
    }
    return (object) array('products' => $products, 'total' => $total);
}
function wc_get_product(int $id): mixed { return null; }
final class GeneratorTestDb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    public int $option_updates = 0;
    public bool $fail_snapshot_insert = false;
    public bool $fail_progress_update = false;
    public array $progress_updates = array();
    public ?array $transaction_backup = null;
    public array $temporary_ids = array();
    public array $tables = array('wp_adc_feeds' => array(), 'wp_adc_feed_runs' => array(), 'wp_adc_feed_snapshots' => array());
    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = preg_replace('/%[dsf]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }
    public function insert(string $table, array $data, array $formats = array()): int|false
    {
        if ('wp_adc_feed_snapshots' === $table && $this->fail_snapshot_insert) { return false; }
        $this->insert_id++;
        $data['id'] = $this->insert_id;
        $this->tables[$table][] = $data;
        return 1;
    }
    public function get_row(string $query, mixed $output = null): ?array
    {
        preg_match('/FROM ([^ ]+)/', $query, $table_match);
        $table = $table_match[1] ?? '';
        if ('wp_adc_feed_runs' === $table) {
            $rows = $this->tables[$table] ?? array();
            return empty($rows) ? null : $rows[count($rows) - 1];
        }
        if (preg_match('/WHERE id = (\d+)/', $query, $match)) {
            foreach ($this->tables[$table] ?? array() as $row) { if ((int) $row['id'] === (int) $match[1]) return $row; }
        }
        return null;
    }
    public function get_col(string $query): array
    {
        preg_match('/WHERE feed_id = (\d+)/', $query, $where);
        $feed_id = (int) ($where[1] ?? 0);
        return array_values(array_map(static fn(array $row): string => (string) $row['file_path'], array_filter(
            $this->tables['wp_adc_feed_snapshots'] ?? array(),
            static fn(array $row): bool => (int) ($row['feed_id'] ?? 0) === $feed_id
        )));
    }
    public function get_results(string $query, mixed $output = null): array
    {
        preg_match('/FROM ([^ ]+)/', $query, $match);
        $table = $match[1] ?? '';
        $rows = $this->tables[$table] ?? array();
        if (preg_match('/WHERE feed_id = (\d+)/', $query, $where)) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => (int) ($row['feed_id'] ?? 0) === (int) $where[1]));
        }
        return $rows;
    }
    public function update(string $table, array $data, array $where, array $formats = array(), array $where_formats = array()): int|false
    {
        if ($this->options === $table) {
            ++$this->option_updates;
            $name = (string) ($where['option_name'] ?? '');
            $expected = (string) ($where['option_value'] ?? '');
            if (!isset($GLOBALS['generator_options'][$name]) || maybe_serialize($GLOBALS['generator_options'][$name]) !== $expected) { return 0; }
            $GLOBALS['generator_options'][$name] = unserialize((string) $data['option_value'], array('allowed_classes' => false));
            return 1;
        }
        if ('wp_adc_feed_runs' === $table && array_key_exists('products_examined', $data)
            && !array_key_exists('status', $data) && !array_key_exists('finished_at', $data)) {
            if ($this->fail_progress_update) { return false; }
            $this->progress_updates[] = array(
                'data' => $data,
                'status' => $this->tables[$table][count($this->tables[$table]) - 1]['status'] ?? '',
            );
        }
        $changed = 0;
        if (!isset($this->tables[$table])) { return 0; }
        foreach ($this->tables[$table] as &$row) {
            $matches = true;
            foreach ($where as $key => $value) { if (($row[$key] ?? null) != $value) $matches = false; }
            if ($matches) { $row = array_merge($row, $data); ++$changed; }
        }
        unset($row);
        return $changed;
    }
    public function delete(string $table, array $where, array $formats = array()): int|false
    {
        if ($this->options === $table) {
            $name = (string) ($where['option_name'] ?? '');
            $expected = (string) ($where['option_value'] ?? '');
            if (!isset($GLOBALS['generator_options'][$name]) || maybe_serialize($GLOBALS['generator_options'][$name]) !== $expected) { return 0; }
            unset($GLOBALS['generator_options'][$name]);
            return 1;
        }
        $before = count($this->tables[$table] ?? array());
        $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? array(), static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) { if (($row[$key] ?? null) != $value) { return true; } }
            return false;
        }));
        return $before - count($this->tables[$table]);
    }
    public function query(string $sql): int|false
    {
        if (str_starts_with($sql, 'START TRANSACTION')) { $this->transaction_backup = $this->tables; return 1; }
        if ('COMMIT' === $sql) { $this->transaction_backup = null; return 1; }
        if ('ROLLBACK' === $sql) { if (null !== $this->transaction_backup) { $this->tables = $this->transaction_backup; } $this->transaction_backup = null; return 1; }
        if (preg_match('/(?:CREATE TEMPORARY TABLE|DROP TEMPORARY TABLE IF EXISTS) `([A-Za-z0-9_]+)`/', $sql, $match)) {
            if (str_starts_with($sql, 'DROP')) { unset($this->temporary_ids[$match[1]]); }
            else { $this->temporary_ids[$match[1]] = array(); }
            return 1;
        }
        if (preg_match('/INSERT IGNORE INTO `([A-Za-z0-9_]+)` \(item_id_hash\) VALUES \\(\'((?:\\\\.|[^\'])*)\'\\)/', $sql, $match)) {
            $table = $match[1];
            $id = stripslashes($match[2]);
            if (!isset($this->temporary_ids[$table])) { return false; }
            if (isset($this->temporary_ids[$table][$id])) { return 0; }
            $this->temporary_ids[$table][$id] = true;
            return 1;
        }
        return 1;
    }
}
function generator_has_diag(array $rows, string $code): bool
{
    foreach ($rows as $row) {
        if (is_array($row) && ($row['code'] ?? '') === $code && (int) ($row['count'] ?? 0) > 0) { return true; }
    }
    return false;
}
function generator_diag_count(array $rows, string $code): int
{
    foreach ($rows as $row) {
        if (is_array($row) && ($row['code'] ?? '') === $code) { return (int) ($row['count'] ?? 0); }
    }
    return 0;
}
function generator_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$GLOBALS['generator_upload_root'] = $generator_upload_root;
$GLOBALS['generator_http_status'] = 200;
$GLOBALS['generator_probe_exposed'] = false;
$GLOBALS['generator_transients'] = array();
$GLOBALS['generator_options'] = array();
$GLOBALS['generator_scheduled'] = array();
$GLOBALS['generator_as_actions'] = array();
$GLOBALS['generator_product_count'] = 250;
$GLOBALS['generator_product_mode'] = '';
$GLOBALS['generator_item_id_source'] = 'id';
$GLOBALS['generator_stats_enabled'] = false;
$GLOBALS['generator_stats'] = array();
$GLOBALS['generator_mark_dirty'] = false;
$GLOBALS['wpdb'] = new GeneratorTestDb();
require_once __DIR__ . '/../includes/feeds/class-adc-feed-schema.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-channels.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-store.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-catalog.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-google.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-meta.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-tiktok.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-writer-rss.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-writer-csv.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-validator-google.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-validator-meta.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-validator-tiktok.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-smart.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-generator.php';

$id = ADC_Feed_Store::create(array(
    'name' => 'Test feed',
    'type' => 'standard',
    'channel' => 'google',
    'format' => 'xml',
    'status' => 'active',
    'config' => array(
        'scope' => 'all',
        'categories' => array(),
        'in_stock_only' => false,
        'google' => array('shipping_country' => 'RO', 'shipping_price' => '15.00', 'shipping_service' => 'Courier', 'condition' => 'new'),
    ),
));
$GLOBALS['generator_options']['adc_feed_lock_' . $id] = array('token' => str_repeat('b', 32), 'expires' => time() - 1);
$result = ADC_Feed_Generator::run($id);
generator_check($result['ok'] === true && !empty($GLOBALS['generator_probe_exposed']), 'valid catalog generation continues despite an informational exposed-storage probe');
generator_check($GLOBALS['wpdb']->option_updates >= 5 && !isset($GLOBALS['generator_options']['adc_feed_lock_' . $id]), 'expired lease is atomically taken over, renewed between pages, and released by its token');
generator_check($result['examined'] === 250 && $result['exported'] === 250, 'generation paginates across multiple 100-product WooCommerce pages');
generator_check(count($GLOBALS['wpdb']->progress_updates) >= 3
    && array_slice(array_column($GLOBALS['wpdb']->progress_updates, 'data'), -3) === array(
        array('products_examined' => 100, 'products_exported' => 100),
        array('products_examined' => 200, 'products_exported' => 200),
        array('products_examined' => 250, 'products_exported' => 250),
    )
    && array_slice(array_column($GLOBALS['wpdb']->progress_updates, 'status'), -3) === array('running', 'running', 'running'),
    'run counters persist actual per-chunk progress while the run is still running');
$progress_snapshot_path = $GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'][0]['file_path'];
$GLOBALS['generator_product_count'] = 1;
$GLOBALS['wpdb']->fail_progress_update = true;
$progress_failure = ADC_Feed_Generator::run($id);
$GLOBALS['wpdb']->fail_progress_update = false;
generator_check(!$progress_failure['ok'] && generator_has_diag($progress_failure['errors'], 'run_progress_update_failed')
    && end($GLOBALS['wpdb']->tables['wp_adc_feed_runs'])['status'] === 'failed'
    && $GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'][0]['file_path'] === $progress_snapshot_path,
    'failed progress persistence fails the run without publishing a replacement snapshot');
$GLOBALS['generator_product_count'] = 250;
$snapshots = $GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'];
$original_snapshot = $snapshots[0];
generator_check(count($snapshots) === 1 && $snapshots[0]['is_current'] === 1, 'snapshot is marked current only after publication');
$stored_snapshot = file_get_contents($snapshots[0]['file_path']);
$guard = "<?php exit; ?>\n";
$xml = is_string($stored_snapshot) ? substr($stored_snapshot, strlen($guard)) : false;
generator_check(is_file($snapshots[0]['file_path']) && is_string($xml) && str_starts_with($stored_snapshot, $guard)
    && $snapshots[0]['checksum'] === hash('sha256', $xml) && (int) $snapshots[0]['bytes'] === strlen($xml), 'PHP exit guard protects the snapshot; checksum and byte count cover XML only');
$direct_output = array();
$direct_status = -1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($snapshots[0]['file_path']), $direct_output, $direct_status);
generator_check(0 === $direct_status && array() === $direct_output, 'direct PHP access exits before exposing snapshot XML');
generator_check((bool) preg_match('/[a-f0-9]{32}\.php$/', $snapshots[0]['file_path']) && !str_contains($snapshots[0]['file_path'], ADC_Feed_Store::get($id)['slug']), 'snapshot filename is 32 random hex characters with a PHP extension');
generator_check(!str_starts_with(realpath($snapshots[0]['file_path']), realpath(ABSPATH) . DIRECTORY_SEPARATOR), 'snapshots are stored outside the WordPress document root');
$storage = dirname($snapshots[0]['file_path']);
generator_check(is_file($storage . '/index.php') && !file_exists($storage . '/.htaccess') && !file_exists($storage . '/web.config'), 'snapshot storage does not create server configuration sidecars');
$runs = $GLOBALS['wpdb']->tables['wp_adc_feed_runs'];
generator_check(($runs[0]['status'] ?? '') === 'complete' && (int) $runs[0]['products_exported'] === 250, 'generation run is finalized with its counts');
generator_check(is_string($xml) && str_contains($xml, '<g:id>1</g:id>') && str_contains($xml, '<g:shipping>'), 'published RSS contains Google attributes and shipping');
// Simulate the snapshot DB write failing after the candidate file is complete.
$GLOBALS['generator_product_count'] = 2;
$GLOBALS['wpdb']->fail_snapshot_insert = true;
$failed_publish = ADC_Feed_Generator::run($id);
$GLOBALS['wpdb']->fail_snapshot_insert = false;
$snapshots = $GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'];
generator_check(false === $failed_publish['ok'] && count($snapshots) === 1
    && $snapshots[0]['file_path'] === $original_snapshot['file_path'] && 1 === $snapshots[0]['is_current']
    && is_file($original_snapshot['file_path']), 'failed replacement preserves the prior guarded PHP snapshot');

$admin_htaccess = "# administrator-owned\nRequire all granted\n";
file_put_contents($storage . '/.htaccess', $admin_htaccess);
$storage_run = ADC_Feed_Generator::run($id);
generator_check($storage_run['ok'] && file_get_contents($storage . '/.htaccess') === $admin_htaccess,
    'generation does not depend on or modify administrator .htaccess directives');

// A live token records the busy run and guarantees future work through Action Scheduler.
$GLOBALS['generator_as_actions'] = array(array('id' => 70, 'feed_id' => $id, 'status' => 'running', 'timestamp' => time()));
$GLOBALS['generator_options']['adc_feed_lock_' . $id] = array('token' => str_repeat('a', 32), 'expires' => time() + 3600);
$busy = ADC_Feed_Generator::run($id);
unset($GLOBALS['generator_options']['adc_feed_lock_' . $id]);
generator_check(false === $busy['ok'] && generator_has_diag($busy['errors'], 'feed_busy')
    && count(array_filter($GLOBALS['generator_as_actions'], static fn(array $action): bool => $action['status'] === 'pending')) === 1,
    'running Action Scheduler job does not satisfy the future-job guarantee for feed_busy');
generator_check(generator_has_diag(ADC_Feed_Store::last_run($id)['errors'] ?? array(), 'feed_busy'), 'feed_busy is persisted in the latest run');
$GLOBALS['generator_as_actions'] = array(array('id' => 71, 'feed_id' => $id, 'status' => 'pending', 'timestamp' => time()));
$GLOBALS['generator_options']['adc_feed_lock_' . $id] = array('token' => str_repeat('c', 32), 'expires' => time() + 3600);
$busy_pending = ADC_Feed_Generator::run($id);
unset($GLOBALS['generator_options']['adc_feed_lock_' . $id]);
generator_check(generator_has_diag($busy_pending['errors'], 'feed_busy') && count($GLOBALS['generator_as_actions']) === 1,
    'an existing pending Action Scheduler job satisfies the future-job guarantee without duplication');
// A catalog mutation while the lock is held is re-read after release and retried.
$GLOBALS['generator_mark_dirty'] = true;
$dirty_run = ADC_Feed_Generator::run($id);
generator_check($dirty_run['ok'] && !empty($GLOBALS['generator_as_actions'])
    && (int) get_transient('adc_feed_retry_' . $id) > 0, 'dirty flag queues a bounded Action Scheduler retry after successful publication');
$GLOBALS['generator_as_actions'] = array(array('id' => 72, 'feed_id' => $id, 'status' => 'running', 'timestamp' => time()));
$GLOBALS['generator_transients']['adc_feed_retry_' . $id] = 7;
$GLOBALS['generator_options']['adc_feed_lock_' . $id] = array('token' => str_repeat('d', 32), 'expires' => time() + 3600);
$busy_exhausted = ADC_Feed_Generator::run($id);
unset($GLOBALS['generator_options']['adc_feed_lock_' . $id]);
generator_check(generator_has_diag($busy_exhausted['errors'], 'feed_busy_retry_exhausted')
    && generator_has_diag(ADC_Feed_Store::last_run($id)['errors'] ?? array(), 'feed_busy_retry_exhausted')
    && count($GLOBALS['generator_as_actions']) === 1, 'busy retries stop at the attempt cap and expose the failure in last_run');

// Duplicate IDs after SKU mapping and IDs truncated away from tracking are run-fatal.
$GLOBALS['generator_product_count'] = 101;
$GLOBALS['generator_product_mode'] = 'cross-page-duplicate';
$GLOBALS['generator_item_id_source'] = 'sku';
$duplicate = ADC_Feed_Generator::run($id);
generator_check(false === $duplicate['ok'] && generator_has_diag($duplicate['errors'], 'duplicate_feed_item_id')
    && $GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'][0]['file_path'] === $original_snapshot['file_path'], 'duplicate Google IDs straddling catalog pages fail without replacing the current snapshot');
$GLOBALS['generator_product_count'] = 1;
$GLOBALS['generator_product_mode'] = 'long';
$tracking_mismatch = ADC_Feed_Generator::run($id);
generator_check(false === $tracking_mismatch['ok'] && generator_has_diag($tracking_mismatch['errors'], 'id_incompatible')
    && $GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'][0]['file_path'] === $original_snapshot['file_path'], 'Google IDs over the channel limit fail before publication');

// Generate platform feeds through the same guarded snapshot pipeline.
// Item-level validation errors are optionally excluded, while feed-level failures remain fatal.
$GLOBALS['generator_product_count'] = 2;
$GLOBALS['generator_product_mode'] = 'one-invalid';
$GLOBALS['generator_item_id_source'] = 'id';
$exclude_result = ADC_Feed_Generator::run($id);
$excluded_warning = array_values(array_filter($exclude_result['warnings'], static fn(array $row): bool => $row['code'] === 'invalid_products_excluded'));
generator_check($exclude_result['ok'] && $exclude_result['examined'] === 2 && $exclude_result['exported'] === 1
    && count($excluded_warning) === 1 && (int) $excluded_warning[0]['count'] === 1
    && generator_has_diag($exclude_result['warnings'], 'title_missing')
    && !generator_has_diag($exclude_result['errors'], 'title_missing'),
    'exclude_invalid publishes valid rows and reports exclusion count and validation reason as warnings');
$strict_id = ADC_Feed_Store::create(array(
    'name' => 'Strict feed', 'channel' => 'google', 'format' => 'xml', 'status' => 'active',
    'config' => array('scope' => 'all', 'exclude_invalid' => false, 'google' => array('shipping_country' => 'RO', 'shipping_price' => '0.00', 'shipping_service' => 'Standard', 'condition' => 'new')),
));
$GLOBALS['generator_product_count'] = 1;
$strict_result = ADC_Feed_Generator::run($strict_id);
generator_check(!$strict_result['ok'] && generator_has_diag($strict_result['errors'], 'title_missing'),
    'exclude_invalid false preserves strict per-item validation failure behavior');
$GLOBALS['generator_product_mode'] = 'one-invalid';
$empty_result = ADC_Feed_Generator::run($id);
generator_check(!$empty_result['ok'] && generator_has_diag($empty_result['errors'], 'no_valid_products'),
    'generation fails clearly when exclusion leaves no valid products');
$GLOBALS['generator_product_mode'] = 'varied-invalid';
$GLOBALS['generator_product_count'] = 3;
$all_invalid_reasons = ADC_Feed_Generator::run($id);
generator_check(!$all_invalid_reasons['ok'] && generator_has_diag($all_invalid_reasons['errors'], 'no_valid_products')
    && generator_diag_count($all_invalid_reasons['warnings'], 'invalid_products_excluded') === 3
    && generator_diag_count($all_invalid_reasons['warnings'], 'title_missing') === 2
    && generator_diag_count($all_invalid_reasons['warnings'], 'description_missing') === 1,
    'all-invalid generation reports no_valid_products and aggregates exclusion count plus repeated and distinct validation reasons');
$GLOBALS['generator_product_mode'] = 'meta';
$GLOBALS['generator_product_count'] = 1;
$GLOBALS['generator_google_category'] = '123';
$GLOBALS['generator_google_category'] = '';
$meta_id = ADC_Feed_Store::create(array(
    'name' => 'Meta CSV', 'channel' => 'meta', 'format' => 'csv', 'status' => 'active',
    'config' => array('scope' => 'all', 'meta' => array('condition' => 'new', 'brand' => 'Acme', 'internal_labels' => array('summer'), 'default_category' => 'Apparel & Accessories')),
));
$meta_result = ADC_Feed_Generator::run($meta_id);
$meta_snapshot = end($GLOBALS['wpdb']->tables['wp_adc_feed_snapshots']);
$meta_payload = is_array($meta_snapshot) ? file_get_contents($meta_snapshot['file_path']) : false;
generator_check($meta_result['ok'] && $meta_result['exported'] === 1 && is_string($meta_payload)
    && str_starts_with($meta_payload, $guard) && str_contains($meta_payload, "id,title,description")
    && str_contains($meta_payload, 'Acme') && str_contains($meta_payload, 'Apparel & Accessories')
    && !generator_has_diag($meta_result['warnings'], 'weight_unit_missing')
    && !generator_has_diag($meta_result['warnings'], 'category_missing'),
    'Meta CSV applies the configured default category before validation and has no missing-category warning');

$GLOBALS['generator_google_category'] = '123';
$GLOBALS['generator_product_mode'] = '';
$tiktok_id = ADC_Feed_Store::create(array(
    'name' => 'TikTok XML', 'channel' => 'tiktok', 'format' => 'xml', 'status' => 'active',
    'config' => array('scope' => 'all', 'tiktok' => array('condition' => 'new', 'brand' => 'Acme')),
));
$tiktok_result = ADC_Feed_Generator::run($tiktok_id);
$tiktok_snapshot = end($GLOBALS['wpdb']->tables['wp_adc_feed_snapshots']);
$tiktok_payload = is_array($tiktok_snapshot) ? file_get_contents($tiktok_snapshot['file_path']) : false;
generator_check($tiktok_result['ok'] && $tiktok_result['exported'] === 1 && is_string($tiktok_payload)
    && str_starts_with($tiktok_payload, $guard) && str_contains($tiktok_payload, '<g:sku_id>tea-1</g:sku_id>')
    && !generator_has_diag($tiktok_result['warnings'], 'shipping_weight_format'),
    'TikTok XML uses sku_id and a unit-qualified shipping weight without per-row unit warnings');

$aggregated = array('errors' => array(), 'warnings' => array(), 'examined' => 99, 'exported' => 99);
$add_diagnostic = new ReflectionMethod(ADC_Feed_Generator::class, 'add_diagnostic');
$add_diagnostic->setAccessible(true);
for ($i = 0; $i < 60; ++$i) {
    $args = array(&$aggregated, 'warnings', 'repeated_warning');
    $add_diagnostic->invokeArgs(null, $args);
}
$args = array(&$aggregated, 'warnings', 'late_warning');
$add_diagnostic->invokeArgs(null, $args);
for ($i = 0; $i < 120; ++$i) {
    $args = array(&$aggregated, 'warnings', 'distinct_warning_' . $i);
    $add_diagnostic->invokeArgs(null, $args);
}
$aggregate_run = $GLOBALS['wpdb']->insert('wp_adc_feed_runs', array('feed_id' => $id, 'started_at' => current_time('mysql', true), 'finished_at' => null, 'status' => 'running'));
$finish_run = new ReflectionMethod(ADC_Feed_Generator::class, 'finish_run');
$finish_run->setAccessible(true);
$finish_run->invoke(null, $GLOBALS['wpdb'], 'wp_adc_feed_runs', $GLOBALS['wpdb']->insert_id, 'complete', $aggregated, '');
$stored_aggregate = ADC_Feed_Store::last_run($id);
generator_check(count($aggregated['warnings']) <= 100
    && generator_has_diag($stored_aggregate['warnings'] ?? array(), 'repeated_warning')
    && (int) array_values(array_filter($stored_aggregate['warnings'], static fn(array $row): bool => $row['code'] === 'repeated_warning'))[0]['count'] === 60
    && generator_has_diag($stored_aggregate['warnings'] ?? array(), 'late_warning'), 'generator aggregates duplicates before capping, preserves late codes, and bounds distinct diagnostic memory through Store');

$GLOBALS['generator_product_count'] = 1;
$GLOBALS['generator_stats_enabled'] = true;
$GLOBALS['generator_stats'] = array('purchases' => array(array('product_id' => 1, 'value' => 12)));
$smart_id = ADC_Feed_Store::create(array(
    'name' => 'Smart feed', 'channel' => 'google', 'format' => 'xml', 'status' => 'active',
    'config' => array(
        'scope' => 'smart', 'categories' => array(),
        'smart_rule' => array('metric' => 'purchases', 'mode' => 'top_n', 'period_days' => 30, 'limit' => 20),
        'google' => array('shipping_country' => 'RO', 'shipping_price' => '0.00', 'shipping_service' => 'Standard', 'condition' => 'new'),
    ),
));
$smart_run = ADC_Feed_Generator::run($smart_id);
$smart_snapshot = array_values(array_filter($GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'], static fn(array $row): bool => (int) $row['feed_id'] === $smart_id))[0] ?? array();
$GLOBALS['generator_stats_enabled'] = false;
$smart_disabled = ADC_Feed_Generator::run($smart_id);
$smart_after_disabled = array_values(array_filter($GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'], static fn(array $row): bool => (int) $row['feed_id'] === $smart_id))[0] ?? array();
generator_check($smart_run['ok'] && $smart_run['exported'] === 1 && !empty($smart_snapshot['file_path'])
    && !$smart_disabled['ok'] && generator_has_diag($smart_disabled['errors'], 'smart_stats_disabled')
    && ($smart_after_disabled['file_path'] ?? '') === $smart_snapshot['file_path'] && is_file($smart_snapshot['file_path']),
    'Smart generation selects ranked products and disabled statistics fail without replacing the last snapshot');

$private_storage = ADC_Feed_Store::storage_directory();
foreach (array_merge(glob($private_storage . '/*') ?: array(), glob($private_storage . '/.*') ?: array()) as $test_file) {
    if (is_file($test_file) && !in_array(basename($test_file), array('.', '..'), true)) { unlink($test_file); }
}
@rmdir($private_storage);
@rmdir($generator_upload_root);
@rmdir($generator_wp_root);
$text_length = new ReflectionMethod(ADC_Feed_Generator::class, 'text_length');
$text_length->setAccessible(true);
generator_check($text_length->invoke(null, 'café') === 4, 'ID length uses Unicode characters rather than UTF-8 bytes');
generator_check(in_array('feed_output_invalid', ADC_Feed_Generator::diagnostic_codes(), true), 'generator exposes its diagnostic identifiers for REST whitelisting');
generator_check(in_array('run_progress_update_failed', ADC_Feed_Generator::diagnostic_codes(), true), 'progress persistence failure is exposed for REST diagnostic whitelisting');
echo "feed-generator-test: PASS\n";
