<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
function home_url(string $path = ''): string { return 'https://shop.example' . $path; }
$GLOBALS['delivery_storage'] = sys_get_temp_dir() . '/adc-feed-delivery-' . bin2hex(random_bytes(8));
mkdir($GLOBALS['delivery_storage'], 0775, true);
final class ADC_Feed_Store
{
    public static function storage_directory(): string { return $GLOBALS['delivery_storage']; }
    public static function managed_snapshot_file(string $path): string
    {
        $file = realpath($path);
        return false !== $file && str_starts_with($file, $GLOBALS['delivery_storage'] . DIRECTORY_SEPARATOR)
            && preg_match('/^[a-f0-9]{32,}\\.php$/', basename($file)) && is_file($file) && is_readable($file) ? $file : '';
    }
}
$GLOBALS['delivery_hooks'] = array();
function add_action(string $hook, callable|array|string $callback, int $priority = 10): void { $GLOBALS['delivery_hooks'][] = $hook; }
function add_filter(string $hook, callable|array|string $callback, int $priority = 10): void { $GLOBALS['delivery_hooks'][] = $hook; }
final class DeliveryTestDb
{
    public string $prefix = 'wp_';
    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[dsf]/', is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
        }
        return $query;
    }
    public function get_row(string $query, mixed $output = null): ?array
    {
        if (str_contains($query, 'wp_adc_feeds')) {
            return array('id' => 9, 'slug' => str_repeat('a', 32), 'status' => 'active', 'channel' => 'google', 'format' => 'xml');
        }
        return array('id' => 2, 'file_path' => '/private/file.php', 'bytes' => 42, 'checksum' => str_repeat('b', 64), 'created_at' => '2026-11-07 12:00:00');
    }
}
$GLOBALS['wpdb'] = new DeliveryTestDb();
require_once __DIR__ . '/../includes/feeds/class-adc-feed-schema.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-profile-google.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-channels.php';
require_once __DIR__ . '/../includes/feeds/class-adc-feed-delivery.php';
function delivery_check(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$slug = str_repeat('a', 32);
delivery_check(ADC_Feed_Delivery::url($slug) === 'https://shop.example/adc-feed/' . $slug . '.xml', 'opaque public URL is canonical');
delivery_check(ADC_Feed_Delivery::url('not-a-secret') === '', 'invalid slugs are not exposed as URLs');
delivery_check(ADC_Feed_Delivery::url($slug, 'csv') === 'https://shop.example/adc-feed/' . $slug . '.csv', 'CSV feeds use the CSV URL extension');
$vars = ADC_Feed_Delivery::query_vars(array('existing'));
delivery_check(in_array(ADC_Feed_Delivery::QUERY_VAR, $vars, true), 'rewrite query variable is public to WordPress');
$resolved = ADC_Feed_Delivery::resolve($slug);
delivery_check(is_array($resolved) && $resolved['feed']['id'] === 9 && $resolved['snapshot']['bytes'] === 42, 'resolve selects active Google feed and current snapshot');
$headers = ADC_Feed_Delivery::response_headers($resolved['snapshot']);
delivery_check($headers['Content-Type'] === 'application/xml; charset=utf-8', 'content type declares UTF-8 XML');
delivery_check($headers['ETag'] === '"' . str_repeat('b', 64) . '"', 'XML checksum is exposed as an ETag');
delivery_check($headers['Content-Length'] === '42' && isset($headers['Last-Modified']), 'XML byte count and Last-Modified are retained for conditional delivery');
$csv_headers = ADC_Feed_Delivery::response_headers($resolved['snapshot'], 'csv');
delivery_check($csv_headers['Content-Type'] === 'text/csv; charset=utf-8', 'CSV delivery declares UTF-8 text/csv');
$etag_matches = new ReflectionMethod(ADC_Feed_Delivery::class, 'etag_matches');
delivery_check($etag_matches->invoke(null, '"' . str_repeat('b', 64) . '"', $headers['ETag']), 'matching ETag still triggers the existing 304 path');
delivery_check($headers['Cache-Control'] === 'public, max-age=300, must-revalidate', 'public response is revalidated regularly');
delivery_check($headers['X-Robots-Tag'] === 'noindex, nofollow', 'feed is excluded from search indexing');
$guard = "<?php exit; ?>\n";
$private_file = $GLOBALS['delivery_storage'] . '/' . bin2hex(random_bytes(16)) . '.php';
file_put_contents($private_file, $guard . '<rss/>');
$managed_file = new ReflectionMethod(ADC_Feed_Delivery::class, 'managed_file');
$managed_path = $managed_file->invoke(null, $private_file);
delivery_check($managed_path === realpath($private_file), 'delivery accepts guarded PHP snapshots only inside feed storage');
$offset = ADC_Feed_Delivery::snapshot_offset($private_file);
delivery_check($offset === strlen($guard), 'delivery skips the PHP guard before streaming XML');
$stream = fopen($private_file, 'rb');
fseek($stream, $offset);
delivery_check(stream_get_contents($stream) === '<rss/>', 'delivery payload after the guard is unchanged XML');
fclose($stream);
$raw_xml_file = $GLOBALS['delivery_storage'] . '/' . bin2hex(random_bytes(16)) . '.xml';
file_put_contents($raw_xml_file, '<rss/>');
delivery_check(false === ADC_Feed_Delivery::snapshot_offset($raw_xml_file)
    && '' === $managed_file->invoke(null, $raw_xml_file), 'delivery rejects raw XML and accepts only PHP snapshots with the exact guard');
$unguarded_php = $GLOBALS['delivery_storage'] . '/' . bin2hex(random_bytes(16)) . '.php';
file_put_contents($unguarded_php, '<rss/>');
delivery_check(false === ADC_Feed_Delivery::snapshot_offset($unguarded_php), 'PHP snapshots without the exact exit guard fail closed');
delivery_check($managed_file->invoke(null, __FILE__) === '', 'delivery refuses arbitrary files outside feed storage');
unlink($private_file);
unlink($raw_xml_file);
unlink($unguarded_php);
rmdir($GLOBALS['delivery_storage']);
ADC_Feed_Delivery::boot();
delivery_check(in_array('init', $GLOBALS['delivery_hooks'], true) && in_array('template_redirect', $GLOBALS['delivery_hooks'], true), 'endpoint hooks are registered');

echo "feed-delivery-test: PASS\n";
