<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
interface ADC_Module_Interface { public static function boot(): void; }
final class ADC_Settings
{
    public static bool $enabled = false;
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return $group === 'product_stats' && $key === 'enabled' ? self::$enabled : $default;
    }
}
final class ADC_Analytics_Schema
{
    public const PRODUCT_STATS = 'adc_analytics_product_stats';
    public static function table(string $key): string { return 'wp_' . $key; }
}
require_once __DIR__ . '/../includes/analytics/class-adc-analytics-settings.php';
final class FakeProductStatsDb
{
    public string $last = '';
    public array $results = [];
    public function prepare(string $sql, mixed ...$args): string { $this->last = $sql; return $sql; }
    public function query(string $sql): int|false { $this->last = $sql; return 1; }
    public function get_results(string $sql, mixed $format): array { $this->last = $sql; return $this->results; }
}
function wp_date(string $format, ?int $timestamp = null): string { return gmdate($format, $timestamp ?? time()); }
function current_time(string $type): int|string { return $type === 'timestamp' ? time() : gmdate('Y-m-d'); }
$GLOBALS['wpdb'] = new FakeProductStatsDb();
require_once __DIR__ . '/../includes/analytics/class-adc-product-stats.php';
function check_product_stats(bool $ok, string $message): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$defaults = ADC_Analytics_Settings::product_stats_defaults();
check_product_stats($defaults === ['enabled' => false, 'retention_days' => 180], 'product stats default is opt-in off and 180 days');
$sanitized = ADC_Analytics_Settings::sanitize_product_stats(['enabled' => 'yes', 'retention_days' => 900], $defaults);
check_product_stats($sanitized === ['enabled' => true, 'retention_days' => 730], 'settings types and maximum retention sanitized');
$sanitized = ADC_Analytics_Settings::sanitize_product_stats(['retention_days' => 2], $defaults);
check_product_stats($sanitized['retention_days'] === 30 && $sanitized['enabled'] === false, 'minimum retention and omitted toggle are safe');
check_product_stats(!ADC_Product_Stats::increment('views', 7, '2026-01-01'), 'disabled collection rejected');
ADC_Settings::$enabled = true;
check_product_stats(!ADC_Product_Stats::increment('unknown', 7, '2026-01-01'), 'metric whitelist enforced');
check_product_stats(!ADC_Product_Stats::increment('views', 0, '2026-01-01'), 'invalid product rejected');
check_product_stats(!ADC_Product_Stats::increment('views', 7, '2026-02-30'), 'invalid date rejected');
check_product_stats(ADC_Product_Stats::increment('add_to_cart', 7, '2026-01-01'), 'valid increment accepted');
check_product_stats(str_contains($GLOBALS['wpdb']->last, 'ON DUPLICATE KEY UPDATE add_to_cart = add_to_cart + 1'), 'atomic metric update');
$GLOBALS['wpdb']->results = [['product_id' => '7', 'value' => '8']];
$top = ADC_Product_Stats::top('views', 14, 10);
check_product_stats($top === [['product_id' => 7, 'value' => 8]], 'top results typed');
check_product_stats(str_contains($GLOBALS['wpdb']->last, 'HAVING value >= %d'), 'suppression threshold applied');
$purchase_cells = ADC_Product_Stats::purchases_for_products(14, [7, 8]);
check_product_stats($purchase_cells === [7 => 8] && str_contains($GLOBALS['wpdb']->last, 'product_id IN (%d,%d)')
    && str_contains($GLOBALS['wpdb']->last, 'HAVING value >= %d'), 'candidate purchase reader limits IDs and omits suppressed cells');
check_product_stats(ADC_Product_Stats::purge(180) === 1, 'retention purge runs');
check_product_stats(str_contains($GLOBALS['wpdb']->last, 'DELETE FROM %i WHERE day < %s'), 'purge is date-bounded');
$increment_callers = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../includes', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $source = file_get_contents($file->getPathname());
    if (is_string($source) && preg_match('/(?:ADC_Product_Stats|self)::increment\s*\(/', $source)) {
        $increment_callers[] = str_replace('\\', '/', substr((string) realpath($file->getPathname()), strlen(dirname(__DIR__) . '/')));
    }
}
sort($increment_callers);
check_product_stats($increment_callers === ['includes/analytics/class-adc-product-stats.php'], 'increment() remains a consent-unchecked internal primitive with no collection caller outside its class');
echo "product-stats-test: PASS\n";
