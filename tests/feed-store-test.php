<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['store_test_options'] = array();
$GLOBALS['store_test_uploads'] = sys_get_temp_dir() . '/adc-feed-store-uploads-' . bin2hex(random_bytes(8));
mkdir($GLOBALS['store_test_uploads'] . '/adc-feeds', 0775, true);
function wp_upload_dir(mixed $time = null, bool $create_dir = true): array { return array('basedir' => $GLOBALS['store_test_uploads'], 'baseurl' => 'https://shop.example/uploads'); }
$GLOBALS['store_test_http_status'] = 200;
$GLOBALS['store_test_probe_body_override'] = '';
function wp_remote_get(string $url, array $args = array()): array
{
    $GLOBALS['store_test_probe_url'] = $url;
    $GLOBALS['store_test_probe_args'] = $args;
    $path = $GLOBALS['store_test_upload_dir'] . '/' . basename($url);
    $body = 200 === $GLOBALS['store_test_http_status'] && is_file($path) ? (string) file_get_contents($path) : '';
    if (isset($GLOBALS['store_test_probe_body_override'])) { $body = $GLOBALS['store_test_probe_body_override']; }
    return array('status' => $GLOBALS['store_test_http_status'], 'body' => $body);
}
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['status'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function is_wp_error(mixed $value): bool { return false; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value)); }
function current_time(string $type, bool $gmt = false): string { return '2026-11-07 12:00:00'; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
final class StoreTestDb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
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
        $this->insert_id++;
        $data['id'] = $this->insert_id;
        $this->tables[$table][] = $data;
        return 1;
    }
    public function get_row(string $query, mixed $output = null): ?array
    {
        preg_match('/FROM ([^ ]+)/', $query, $table_match);
        $table = $table_match[1] ?? '';
        $rows = $this->tables[$table] ?? array();
        if ('wp_adc_feed_runs' === $table) {
            return $rows[0] ?? null;
        }
        if (preg_match('/WHERE id = (\d+)/', $query, $match)) {
            foreach ($rows as $row) { if ((int) $row['id'] === (int) $match[1]) return $row; }
        }
        if (preg_match("/WHERE slug = '([^']+)'/", $query, $match)) {
            foreach ($rows as $row) { if (($row['slug'] ?? '') === stripslashes($match[1])) return $row; }
        }
        return null;
    }
    public function get_results(string $query, mixed $output = null): array
    {
        preg_match('/FROM ([^ ]+)/', $query, $match);
        return $this->tables[$match[1] ?? ''] ?? array();
    }
    public function get_col(string $query): array
    {
        $has_feed_filter = preg_match('/WHERE feed_id = (\d+)/', $query, $match);
        $feed_id = (int) ($match[1] ?? 0);
        $rows = $this->tables['wp_adc_feed_snapshots'] ?? array();
        if ($has_feed_filter) {
            $rows = array_filter($rows, static fn(array $row): bool => (int) ($row['feed_id'] ?? 0) === $feed_id);
        }
        return array_values(array_map(static fn(array $row): string => (string) ($row['file_path'] ?? ''), $rows));
    }
    public function update(string $table, array $data, array $where, array $formats = array(), array $where_formats = array()): int|false
    {
        $changed = 0;
        foreach ($this->tables[$table] as &$row) {
            $matches = true;
            foreach ($where as $key => $value) { if (($row[$key] ?? null) != $value) $matches = false; }
            if ($matches) { $row = array_merge($row, $data); ++$changed; }
        }
        unset($row);
        return $changed;
    }
    public function query(string $sql): int|false { return 1; }
    public function delete(string $table, array $where, array $formats = array()): int|false
    {
        $before = count($this->tables[$table] ?? array());
        $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? array(), static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) { if (($row[$key] ?? null) != $value) return true; }
            return false;
        }));
        return $before - count($this->tables[$table]);
    }
}
$GLOBALS['wpdb'] = new StoreTestDb();
require_once __DIR__ . '/../includes/feeds/class-adc-feed-schema.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-google.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-channels.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-store.php';
$GLOBALS['store_test_upload_dir'] = ADC_Feed_Store::storage_directory();
if (!is_dir($GLOBALS['store_test_upload_dir'])) {
    mkdir($GLOBALS['store_test_upload_dir'], 0775, true);
}
function store_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$id = ADC_Feed_Store::create(array(
    'name' => '  <b>Demo Feed</b>  ',
    'type' => 'standard',
    'channel' => 'google',
    'format' => 'xml',
    'status' => 'active',
    'slug' => 'attacker-chosen-slug',
    'config' => array('google' => array('shipping_country' => 'RO', 'shipping_price' => '15.00')),
    'params' => array(array('field' => 'title', 'source' => array('type' => 'constant', 'value' => 'Custom'), 'transforms' => array(array('op' => 'replace', 'arg' => array('from' => 'a', 'to' => 'b'))), 'enabled' => true)),
));
$feed = ADC_Feed_Store::get($id);
store_check($id === 1 && is_array($feed), 'create/get returns the feed');
$GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'] = array();
store_check((bool) preg_match('/^[a-f0-9]{32}$/', $feed['slug']), 'create generates an opaque random slug');
store_check($feed['slug'] !== 'attacker-chosen-slug', 'caller cannot choose the public slug');
store_check($feed['name'] === 'Demo Feed' && $feed['status'] === 'active', 'feed fields are sanitized and persisted');
store_check(!array_key_exists('schema_version', $feed['config']), 'internal config version is not exposed through the row shape');
$storage_ready = ADC_Feed_Store::prepare_storage_directory();
store_check($storage_ready === realpath($GLOBALS['store_test_upload_dir'])
    && !file_exists($storage_ready . '/.htaccess') && !file_exists($storage_ready . '/web.config')
    && file_get_contents($storage_ready . '/index.php') === "<?php exit; ?>\n", 'storage installs only an index guard and leaves server configuration files untouched');
file_put_contents($storage_ready . '/.htaccess', "# administrator directive\nRequire all granted\n");
$autotest = ADC_Feed_Store::storage_autotest();
store_check($autotest['ok'] && !$autotest['warning'] && $autotest['status'] === 'storage_guard_executed'
    && str_starts_with($GLOBALS['store_test_probe_url'], 'https://shop.example/uploads/adc-feeds/')
    && str_ends_with($GLOBALS['store_test_probe_url'], '.php')
    && ($GLOBALS['store_test_probe_args']['timeout'] ?? 0) === 3, 'storage autotest GET verifies that PHP executed the guard without blocking');
unset($GLOBALS['store_test_probe_body_override']);
$exposed = ADC_Feed_Store::storage_autotest();
store_check($exposed['status'] === 'storage_exposed' && $exposed['ok'] && $exposed['warning'] && $exposed['exposed'], 'a returned random token marks direct PHP content as exposed, informationally');
store_check(!file_exists($GLOBALS['store_test_upload_dir'] . '/' . basename($GLOBALS['store_test_probe_url'])), 'storage autotest removes its temporary PHP probe');
$GLOBALS['store_test_http_status'] = 403;
$unavailable = ADC_Feed_Store::storage_autotest();
store_check($unavailable['status'] === 'storage_probe_unavailable' && $unavailable['warning'] && $unavailable['ok'], 'non-success HTTP responses are non-blocking warnings');
$stored_config = json_decode($GLOBALS['wpdb']->tables['wp_adc_feeds'][0]['config_json'], true);
store_check(($stored_config['schema_version'] ?? null) === 2, 'config schema version is stored in JSON');
store_check(($stored_config['params'][0]['transforms'][0]['arg']['from'] ?? '') === 'a', 'top-level REST parameter rows are stored under config.params without losing nested transform arguments');
$GLOBALS['wpdb']->tables['wp_adc_feed_runs'][] = array(
    'id' => 3, 'feed_id' => $id, 'status' => 'complete', 'started_at' => '2026-11-07 10:00:00',
    'finished_at' => '2026-11-07 10:01:00', 'products_examined' => 12, 'products_exported' => 10,
    'error_summary' => '["feed_busy","feed_busy",{"code":"late_code","count":4}]', 'warning_summary' => '["missing_brand","missing_brand"]', 'snapshot_hash' => str_repeat('a', 64),
);
$last_run = ADC_Feed_Store::last_run($id);
store_check(is_array($last_run) && $last_run['status'] === 'complete'
    && $last_run['errors'] === array(array('code' => 'feed_busy', 'count' => 2), array('code' => 'late_code', 'count' => 4))
    && $last_run['warnings'] === array(array('code' => 'missing_brand', 'count' => 2))
    && $last_run['examined'] === 12 && $last_run['exported'] === 10, 'last_run aggregates legacy and structured summaries before REST delivery');
store_check(ADC_Feed_Store::update($id, array('slug' => 'changed', 'name' => 'Renamed')), 'editable fields update successfully');
store_check(ADC_Feed_Store::get($id)['slug'] === $feed['slug'], 'update does not mass-assign slug');
store_check(ADC_Feed_Store::set_status($id, 'disabled'), 'known status can be changed');
store_check(!ADC_Feed_Store::set_status($id, 'running'), 'unknown status is rejected');
$snapshot_path = $GLOBALS['store_test_upload_dir'] . '/' . bin2hex(random_bytes(16)) . '.php';
file_put_contents($snapshot_path, "<?php exit; ?>\n<rss/>");
$GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'][] = array('id' => 1, 'feed_id' => $id, 'file_path' => $snapshot_path);
$rotated = ADC_Feed_Store::regenerate_slug($id);
store_check((bool) preg_match('/^[a-f0-9]{32}$/', $rotated) && $rotated !== $feed['slug'], 'slug rotation returns a new opaque value');
store_check(array() === $GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'] && !is_file($snapshot_path), 'slug rotation removes snapshot rows and managed files');
store_check(count(ADC_Feed_Store::all()) === 1, 'all returns saved feeds');
store_check(ADC_Feed_Store::delete($id) && array() === ADC_Feed_Store::all(), 'delete removes only the feed rows');
$wipe_path = $GLOBALS['store_test_upload_dir'] . '/' . bin2hex(random_bytes(16)) . '.php';
file_put_contents($wipe_path, "<?php exit; ?>\n<rss/>");
$GLOBALS['wpdb']->tables['wp_adc_feed_snapshots'][] = array('id' => 99, 'feed_id' => 99, 'file_path' => $wipe_path);
$unrelated_path = $GLOBALS['store_test_upload_dir'] . '/do-not-delete.txt';
file_put_contents($unrelated_path, 'unrelated');
store_check(ADC_Feed_Store::delete_all_snapshot_files() && !is_file($wipe_path) && is_file($unrelated_path), 'opt-in wipe removes managed files but preserves unrelated uploads');
store_check(file_get_contents($GLOBALS['store_test_upload_dir'] . '/.htaccess') === "# administrator directive\nRequire all granted\n"
    && is_file($unrelated_path), 'wipe does not rewrite or delete administrator server directives');
unlink($unrelated_path);
store_check(0 === ADC_Feed_Store::create(array('name' => '')) , 'empty feed names are refused');
foreach (glob($GLOBALS['store_test_upload_dir'] . '/*') ?: array() as $path) { if (is_file($path)) { unlink($path); } }
foreach (glob($GLOBALS['store_test_upload_dir'] . '/.*') ?: array() as $path) { if (is_file($path)) { unlink($path); } }
foreach (glob($GLOBALS['store_test_upload_dir'] . '/*') ?: array() as $path) { if (is_file($path)) { unlink($path); } }
foreach (glob($GLOBALS['store_test_upload_dir'] . '/.*') ?: array() as $path) { if (is_file($path)) { unlink($path); } }
@rmdir($GLOBALS['store_test_upload_dir']);
@rmdir(dirname($GLOBALS['store_test_upload_dir']));
@rmdir($GLOBALS['store_test_uploads'] . '/adc-feeds');
@rmdir($GLOBALS['store_test_uploads']);

echo "feed-store-test: PASS\n";
