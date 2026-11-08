<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/schema-wp/');
define('ARRAY_A', 'ARRAY_A');
interface ADC_Module_Interface { public static function boot(): void; }
final class SchemaDb
{
    public string $prefix = 'wp_';
    public array $indexes = [];
    public array $queries = [];
    public bool $alter_succeeds = true;
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function get_var(string $sql): mixed { return 'wp_adc_analytics_product_stats'; }
    public function get_col(string $sql): array { return ['day', 'product_id', 'views', 'add_to_cart', 'begin_checkout', 'purchases']; }
    public function get_results(string $sql, mixed $format): array { return $this->indexes; }
    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'ALTER TABLE')) {
            if (!$this->alter_succeeds) { return false; }
            $this->indexes = [
                ['Key_name' => 'day_product', 'Non_unique' => '0', 'Seq_in_index' => '1', 'Column_name' => 'day'],
                ['Key_name' => 'day_product', 'Non_unique' => '0', 'Seq_in_index' => '2', 'Column_name' => 'product_id'],
            ];
        }
        return 1;
    }
}
$GLOBALS['wpdb'] = new SchemaDb();
$GLOBALS['options'] = [];
function dbDelta(string $sql): array { return []; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['options'][$key] = $value; return true; }
function check_schema(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
require_once __DIR__ . '/../includes/analytics/class-adc-analytics-schema.php';

check_schema(ADC_Analytics_Schema::install(), 'missing UNIQUE index is repaired and schema install succeeds');
check_schema(count(array_filter($GLOBALS['wpdb']->queries, static fn(string $query): bool => str_starts_with($query, 'ALTER TABLE'))) === 1, 'migration attempts ALTER when the unique index is missing');
check_schema(($GLOBALS['options'][ADC_Analytics_Schema::OPTION_DB_VERSION] ?? '') === ADC_Analytics_Schema::DB_VERSION, 'schema version saved only after index verification');

$GLOBALS['wpdb']->indexes = [];
$GLOBALS['wpdb']->alter_succeeds = false;
unset($GLOBALS['options'][ADC_Analytics_Schema::OPTION_DB_VERSION]);
check_schema(!ADC_Analytics_Schema::install(), 'failed index repair fails migration');
check_schema(!isset($GLOBALS['options'][ADC_Analytics_Schema::OPTION_DB_VERSION]), 'failed index repair does not advance schema version');
echo "product-stats-schema-test: PASS\n";
