<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
interface ADC_Module_Interface { public static function boot(): void; }
final class ADC_Settings { public static function get(string $group,string $key,mixed $default=null): mixed { return $default; } }
final class FakeAnalyticsDb
{
    public string $prefix='wp_'; public string $last='';
    public function prepare(string $sql,mixed ...$args): string { $this->last=$sql; return $sql; }
    public function query(string $sql): int|false { $this->last=$sql; return 1; }
    public function get_var(string $sql): mixed { $this->last=$sql; return null; }
}
$GLOBALS['wpdb']=new FakeAnalyticsDb();
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-schema.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-store.php';
require_once __DIR__ . '/../../includes/analytics/class-adc-analytics-retention.php';
function check(bool $condition,string $message): void { if(!$condition) { fwrite(STDERR,"FAIL: $message\n"); exit(1); } }
check(ADC_Analytics_Schema::table(ADC_Analytics_Schema::LEDGER)==='wp_adc_analytics_order_ledger','fixed-prefix table');
try { ADC_Analytics_Schema::table('not_a_table'); check(false,'table allowlist'); } catch (InvalidArgumentException) {}
check(!ADC_Analytics_Store::increment_traffic('2024-02-30',12,'direct','page',1),'invalid date rejected');
check(!ADC_Analytics_Store::increment_traffic('2024-02-29',24,'direct','page',1),'invalid hour rejected');
check(!ADC_Analytics_Store::increment_traffic('2024-02-29',12,'direct','https://x',1),'dimension allowlist');
check(ADC_Analytics_Store::increment_traffic('2024-02-29',12,'direct','page',1),'valid traffic increment');
check(str_contains($GLOBALS['wpdb']->last,'ON DUPLICATE KEY UPDATE views = views + VALUES(views)'),'atomic increment SQL');
check(!ADC_Analytics_Store::increment_campaign('2024-02-29','secret-source','paid','campaign'),'campaign remains inert');
check(!ADC_Analytics_Store::apply_commerce_metric('2024-02-29','usd','','orders_paid',0,1),'currency validation');
check(!ADC_Analytics_Store::apply_commerce_metric('2024-02-29','USD','','arbitrary',0,1),'metric allowlist');
check(ADC_Analytics_Store::apply_commerce_metric('2024-02-29','USD','','orders_paid',0,1),'valid commerce increment');
check(!ADC_Analytics_Store::claim_ledger(0,'order_paid','','USD',0,'2024-02-29','2024-02-29 00:00:00'),'invalid ledger ID');
ADC_Analytics_Store::delete_day('2024-02-29');
check(str_contains($GLOBALS['wpdb']->last,'DELETE FROM %i WHERE stat_date=%s'),'day deletion SQL bounded');
check(ADC_Analytics_Store::purge(ADC_Analytics_Schema::LEDGER,'2024-02-29')===0,'ledger cannot purge below commerce retention');
check(ADC_Analytics_Store::purge(ADC_Analytics_Schema::CAMPAIGNS,'2024-02-29')===1,'campaigns table key accepted for purge (B5)');
echo "storage-test: PASS\n";
