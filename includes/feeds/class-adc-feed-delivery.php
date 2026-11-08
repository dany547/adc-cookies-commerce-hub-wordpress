<?php
/** Public delivery endpoint for the latest valid Google feed snapshot. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Registers /adc-feed/{opaque-slug}.xml and streams the current snapshot. */
final class ADC_Feed_Delivery
{
    public const QUERY_VAR = 'adc_feed_slug';
    private const PATH_PATTERN = '^adc-feed/([a-f0-9]{32})\.(xml|csv)$';

    /** Register URL routing and delivery hooks. */
    public static function boot(): void
    {
        add_action('init', array(self::class, 'register_rewrite_rule'), 1);
        add_filter('query_vars', array(self::class, 'query_vars'));
        add_action('template_redirect', array(self::class, 'serve'), 0);
    }

    /** Register the endpoint and flush once as part of plugin activation. */
    public static function activate(): void
    {
        self::register_rewrite_rule();
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }
    }

    /** Register the non-public query var and rewrite rule. */
    public static function register_rewrite_rule(): void
    {
        if (function_exists('add_rewrite_tag')) {
            add_rewrite_tag('%' . self::QUERY_VAR . '%', '([a-f0-9]{32})');
            add_rewrite_tag('%adc_feed_format%', '(xml|csv)');
        }
        if (function_exists('add_rewrite_rule')) {
            add_rewrite_rule(self::PATH_PATTERN, 'index.php?' . self::QUERY_VAR . '=$matches[1]&adc_feed_format=$matches[2]', 'top');
        }
    }

    /** @param string[] $vars */
    public static function query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        $vars[] = 'adc_feed_format';
        return array_values(array_unique($vars));
    }

    /** Public canonical URL for a generated slug. */
    public static function url(string $slug, string $format = 'xml'): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $slug) || !in_array($format, array('xml', 'csv'), true)) {
            return '';
        }
        $url = '/adc-feed/' . $slug . '.' . ('rss' === $format ? 'xml' : $format);
        return function_exists('home_url') ? home_url($url) : $url;
    }

    /** Resolve an opaque slug to its active feed and current snapshot. */
    public static function resolve(string $slug): ?array
    {
        global $wpdb;

        if (!preg_match('/^[a-f0-9]{32}$/', $slug)) {
            return null;
        }
        $feeds = ADC_Feed_Schema::table(ADC_Feed_Schema::FEEDS);
        $feed = $wpdb->get_row(
            $wpdb->prepare("SELECT id, slug, status, channel, format FROM $feeds WHERE slug = %s LIMIT 1", $slug),
            ARRAY_A
        );
        $channel_spec = is_array($feed) ? ADC_Feed_Channels::get((string) ($feed['channel'] ?? '')) : null;
        if (!is_array($feed) || 'active' !== (string) ($feed['status'] ?? '') || null === $channel_spec
            || (!in_array((string) ($feed['format'] ?? ''), $channel_spec['formats'], true)
                && !('google' === ($feed['channel'] ?? '') && 'rss' === ($feed['format'] ?? '')))) {
            return null;
        }

        $snapshots = ADC_Feed_Schema::table(ADC_Feed_Schema::SNAPSHOTS);
        $snapshot = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, file_path, bytes, checksum, created_at FROM $snapshots WHERE feed_id = %d AND is_current = 1 ORDER BY id DESC LIMIT 1",
                (int) $feed['id']
            ),
            ARRAY_A
        );

        return array('feed' => $feed, 'snapshot' => is_array($snapshot) ? $snapshot : null);
    }

    /** Deterministic public response headers; exposed for isolated verification. */
    public static function response_headers(array $snapshot, string $format = 'xml'): array
    {
        $checksum = strtolower((string) ($snapshot['checksum'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            $checksum = '';
        }
        $timestamp = strtotime((string) ($snapshot['created_at'] ?? '') . ' UTC');
        $headers = array(
            'Content-Type'  => 'csv' === $format ? 'text/csv; charset=utf-8' : 'application/xml; charset=utf-8',
            'X-Robots-Tag'  => 'noindex, nofollow',
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        );
        if ('' !== $checksum) {
            $headers['ETag'] = '"' . $checksum . '"';
        }
        if (false !== $timestamp) {
            $headers['Last-Modified'] = gmdate(DATE_RFC7231, $timestamp);
        }
        if (isset($snapshot['bytes']) && (int) $snapshot['bytes'] >= 0) {
            $headers['Content-Length'] = (string) (int) $snapshot['bytes'];
        }
        return $headers;
    }

    /** Deliver the latest snapshot, with safe cache validators and fail-closed status codes. */
    public static function serve(): void
    {
        $slug = function_exists('get_query_var') ? (string) get_query_var(self::QUERY_VAR, '') : '';
        if ('' === $slug) {
            return;
        }

        $resolved = self::resolve($slug);
        if (null === $resolved) {
            self::send_error(404);
        }
        $snapshot = $resolved['snapshot'];
        if (!is_array($snapshot)) {
            self::send_error(503);
        }
        $path = self::managed_file((string) ($snapshot['file_path'] ?? ''));
        if ('' === $path) {
            self::send_error(503);
        }

        $offset = self::snapshot_offset($path);
        if (false === $offset) {
            self::send_error(503);
        }

        $requested_format = function_exists('get_query_var') ? (string) get_query_var('adc_feed_format', 'xml') : 'xml';
        $feed_format = (string) ($resolved['feed']['format'] ?? 'xml');
        $expected_format = 'csv' === $feed_format ? 'csv' : 'xml';
        if ($requested_format !== $expected_format) {
            self::send_error(404);
        }
        $headers = self::response_headers($snapshot, $expected_format);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        $etag = $headers['ETag'] ?? '';
        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])) : '';
        if ('' !== $etag && self::etag_matches($if_none_match, $etag)) {
            status_header(304);
            exit;
        }
        if ('' === $if_none_match && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'], $headers['Last-Modified'])) {
            $since = strtotime((string) wp_unslash($_SERVER['HTTP_IF_MODIFIED_SINCE']));
            $modified = strtotime($headers['Last-Modified']);
            if (false !== $since && false !== $modified && $since >= $modified) {
                status_header(304);
                exit;
            }
        }

        status_header(200);
        $stream = @fopen($path, 'rb');
        if (false === $stream || 0 !== fseek($stream, $offset)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            self::send_error(503);
        }
        fpassthru($stream);
        fclose($stream);
        exit;
    }

    /** Validate the exact PHP guard and return the XML payload offset; raw XML is never served. */
    public static function snapshot_offset(string $path): int|false
    {
        $guard = "<?php exit; ?>\n";
        if ('php' !== strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            return false;
        }
        $stream = @fopen($path, 'rb');
        if (false === $stream) {
            return false;
        }
        $prefix = fread($stream, strlen($guard));
        fclose($stream);
        return $prefix === $guard ? strlen($guard) : false;
    }

    private static function send_error(int $status): never
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (function_exists('status_header')) {
            status_header($status);
        }
        header('X-Robots-Tag: noindex, nofollow', true);
        exit;
    }

    private static function etag_matches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ('*' === $candidate || hash_equals($etag, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** Return only a generated snapshot from current or legacy feed storage. */
    private static function managed_file(string $path): string
    {
        if ('' === $path || !class_exists('ADC_Feed_Store') || !method_exists('ADC_Feed_Store', 'managed_snapshot_file')) {
            return '';
        }
        return ADC_Feed_Store::managed_snapshot_file($path);
    }
}
