<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
interface ADC_Module_Interface { public static function boot(): void; }
$GLOBALS['feed_test_ddl'] = array();
$GLOBALS['feed_test_options'] = array();
$GLOBALS['feed_test_order'] = array();
$GLOBALS['feed_test_fail_file_cleanup'] = false;
$GLOBALS['feed_test_get_option_calls'] = 0;
final class ADC_Feed_Store
{
    public static function delete_all_snapshot_files(): bool
    {
        $GLOBALS['feed_test_order'][] = 'files';
        return !$GLOBALS['feed_test_fail_file_cleanup'];
    }
}
function dbDelta(string $sql): array { $GLOBALS['feed_test_ddl'][] = $sql; return array(); }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['feed_test_options'][$name] = $value; return true; }
function get_option(string $name, mixed $default = false): mixed
{
    ++$GLOBALS['feed_test_get_option_calls'];
    return $GLOBALS['feed_test_options'][$name] ?? $default;
}
function delete_option(string $name): bool { unset($GLOBALS['feed_test_options'][$name]); return true; }
final class FakeFeedSchemaDb
{
    public string $prefix = 'wp_';
    public array $queries = array();
    public int $show_columns_calls = 0;
    public bool $columns_ready = true;
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_results(string $sql, mixed $output = null): array
    {
        if (str_starts_with($sql, 'SHOW COLUMNS')) { ++$this->show_columns_calls; }
        if (!$this->columns_ready) { return array(); }
        $columns = array('id', 'feed_id', 'started_at', 'finished_at', 'status', 'products_examined', 'products_exported', 'error_summary', 'warning_summary', 'snapshot_hash');
        if (str_contains($sql, 'wp_adc_feeds')) { $columns = array('id', 'slug', 'name', 'type', 'channel', 'format', 'config_json', 'status', 'created_at', 'updated_at'); }
        if (str_contains($sql, 'wp_adc_feed_snapshots')) { $columns = array('id', 'feed_id', 'file_path', 'bytes', 'checksum', 'created_at', 'is_current'); }
        return array_map(static fn(string $column): array => array('Field' => $column), $columns);
    }
    public function query(string $sql): int { $this->queries[] = $sql; $GLOBALS['feed_test_order'][] = 'table'; return 1; }
}
$GLOBALS['wpdb'] = new FakeFeedSchemaDb();
require_once __DIR__ . '/../includes/feeds/class-adc-feed-schema.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

ADC_Feed_Schema::install();
$ddl = $GLOBALS['feed_test_ddl'];
check(count($ddl) === 3, 'schema creates exactly three feed tables');
foreach (array('wp_adc_feeds', 'wp_adc_feed_runs', 'wp_adc_feed_snapshots') as $table) {
    check((bool) array_filter($ddl, static fn(string $sql): bool => str_contains($sql, 'CREATE TABLE ' . $table . ' ')), 'creates ' . $table);
}
check(str_contains($ddl[0], 'UNIQUE KEY slug (slug)'), 'feed slug is unique');
check(str_contains($ddl[1], 'warning_summary text NULL'), 'feed runs persist warning summaries');
check(str_contains($ddl[0], "enum('standard','smart')") && str_contains($ddl[0], "enum('google','meta','tiktok','olx','custom')") && str_contains($ddl[0], "enum('draft','active','disabled')"), 'feed type, channel and status values are constrained');
check(get_option(ADC_Feed_Schema::OPTION_DB_VERSION) === ADC_Feed_Schema::DB_VERSION, 'schema version recorded');
$GLOBALS['feed_test_ddl'] = array();
update_option(ADC_Feed_Schema::OPTION_DB_VERSION, '1');
$GLOBALS['wpdb']->columns_ready = false;
ADC_Feed_Schema::maybe_upgrade();
check(count($GLOBALS['feed_test_ddl']) === 3 && get_option(ADC_Feed_Schema::OPTION_DB_VERSION) !== '2', 'failed column verification leaves the schema version unrecorded');
$GLOBALS['feed_test_ddl'] = array();
$GLOBALS['wpdb']->columns_ready = true;
ADC_Feed_Schema::maybe_upgrade();
check(count($GLOBALS['feed_test_ddl']) === 3 && get_option(ADC_Feed_Schema::OPTION_DB_VERSION) === '2', 'schema migration retries and records v2 after verification');
$GLOBALS['wpdb']->show_columns_calls = 0;
$GLOBALS['feed_test_get_option_calls'] = 0;
ADC_Feed_Schema::maybe_upgrade();
check($GLOBALS['feed_test_get_option_calls'] === 1 && $GLOBALS['wpdb']->show_columns_calls === 0
    && count($GLOBALS['feed_test_ddl']) === 3, 'current-version upgrade path makes one option lookup and skips SHOW COLUMNS/database writes');
check(ADC_Feed_Schema::uninstall(), 'uninstall succeeds after managed-file cleanup');
check($GLOBALS['feed_test_order'][0] === 'files' && $GLOBALS['feed_test_order'][1] === 'table', 'managed files are removed before the first feed table is dropped');
check(count($GLOBALS['wpdb']->queries) === 3, 'uninstall issues exactly three DROP TABLE statements');
check(count(array_filter($GLOBALS['wpdb']->queries, static fn(string $sql): bool => str_starts_with($sql, 'DROP TABLE IF EXISTS wp_adc_feed'))) === 3, 'uninstall drops only feed tables');
check(get_option(ADC_Feed_Schema::OPTION_DB_VERSION, null) === null, 'schema version removed');
check(!in_array('adc_settings_feeds', array_keys($GLOBALS['feed_test_options']), true), 'feed settings option removed');
$GLOBALS['feed_test_fail_file_cleanup'] = true;
$GLOBALS['wpdb']->queries = array();
check(!ADC_Feed_Schema::uninstall() && array() === $GLOBALS['wpdb']->queries, 'uninstall aborts without dropping tables if managed-file cleanup fails');

echo "feed-schema-test: PASS\n";
