<?php
/** Feed configuration persistence. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Sanitized CRUD access to the feed configuration table. */
final class ADC_Feed_Store
{
    private const TYPES = array('standard', 'smart');
    private const CHANNELS = array('google', 'meta', 'tiktok', 'olx', 'custom');
    private const FORMATS = array('rss', 'xml', 'atom', 'tsv', 'txt', 'csv', 'json', 'custom');
    private const STATUSES = array('draft', 'active', 'disabled');
    private const CONFIG_VERSION = 3;

    /** Insert a validated feed and return its database ID, or 0 on failure. */
    public static function create(array $data): int
    {
        global $wpdb;

        $feed = self::sanitize_feed($data, true);
        if ('' === $feed['name'] || '' === $feed['format']) {
            return 0;
        }

        try {
            $config_json = self::encode_config($feed['config']);
        } catch (UnexpectedValueException) {
            return 0;
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            ADC_Feed_Schema::table(ADC_Feed_Schema::FEEDS),
            array(
                'slug'        => self::new_slug(),
                'name'        => $feed['name'],
                'type'        => $feed['type'],
                'channel'     => $feed['channel'],
                'format'      => $feed['format'],
                'config_json' => $config_json,
                'status'      => $feed['status'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return false === $inserted ? 0 : (int) $wpdb->insert_id;
    }

    /** Retrieve one feed using the public row shape. */
    public static function get(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . ADC_Feed_Schema::table(ADC_Feed_Schema::FEEDS) . ' WHERE id = %d LIMIT 1', $id),
            ARRAY_A
        );

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Return the latest run in the UI/REST contract shape.
     *
     * @return array{status:string,started_at:string,finished_at:string,examined:int,exported:int,errors:array,warnings:array}|null
     */
    public static function last_run(int $feed_id): ?array
    {
        global $wpdb;

        if ($feed_id <= 0) {
            return null;
        }
        $table = ADC_Feed_Schema::table(ADC_Feed_Schema::RUNS);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE feed_id = %d ORDER BY id DESC LIMIT 1", $feed_id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }

        return array(
            'status' => (string) ($row['status'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'examined' => (int) ($row['products_examined'] ?? 0),
            'exported' => (int) ($row['products_exported'] ?? 0),
            'errors' => self::decode_summary($row['error_summary'] ?? ''),
            'warnings' => self::decode_summary($row['warning_summary'] ?? ''),
        );
    }

    /** Retrieve every feed in stable order. */
    public static function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            'SELECT * FROM ' . ADC_Feed_Schema::table(ADC_Feed_Schema::FEEDS) . ' ORDER BY id ASC',
            ARRAY_A
        );
        if (!is_array($rows)) {
            return array();
        }

        return array_values(array_map(static fn(array $row): array => self::hydrate($row), $rows));
    }

    /** Update only editable feed fields; ID and slug are never mass-assigned. */
    public static function update(int $id, array $data): bool
    {
        global $wpdb;

        $current = self::get($id);
        if (null === $current || $id <= 0) {
            return false;
        }

        $feed = self::sanitize_feed(array_merge($current, $data), false);
        if ('' === $feed['name'] || '' === $feed['format']) {
            return false;
        }

        try {
            $config_json = self::encode_config($feed['config']);
        } catch (UnexpectedValueException) {
            return false;
        }

        $updated = $wpdb->update(
            ADC_Feed_Schema::table(ADC_Feed_Schema::FEEDS),
            array(
                'name'        => $feed['name'],
                'type'        => $feed['type'],
                'channel'     => $feed['channel'],
                'format'      => $feed['format'],
                'config_json' => $config_json,
                'status'      => $feed['status'],
                'updated_at'  => current_time('mysql', true),
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        return false !== $updated;
    }

    /** Delete one feed and only its feed-owned run/snapshot rows and files. */
    public static function delete(int $id): bool
    {
        global $wpdb;

        $feed = self::get($id);
        if (null === $feed) {
            return false;
        }

        $snapshot_table = ADC_Feed_Schema::table(ADC_Feed_Schema::SNAPSHOTS);
        $paths = $wpdb->get_col(
            $wpdb->prepare("SELECT file_path FROM $snapshot_table WHERE feed_id = %d", $id)
        );
        $deleted_runs = $wpdb->delete(ADC_Feed_Schema::table(ADC_Feed_Schema::RUNS), array('feed_id' => $id), array('%d'));
        if (false === $deleted_runs) {
            return false;
        }
        $deleted_snapshots = $wpdb->delete($snapshot_table, array('feed_id' => $id), array('%d'));
        if (false === $deleted_snapshots) {
            return false;
        }
        $deleted = $wpdb->delete(ADC_Feed_Schema::table(ADC_Feed_Schema::FEEDS), array('id' => $id), array('%d'));
        if (false === $deleted || $deleted <= 0) {
            return false;
        }
        foreach (is_array($paths) ? $paths : array() as $path) {
            self::delete_snapshot_file((string) $path);
        }

        return true;
    }

    /** Set a validated feed status. */
    public static function set_status(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true) || null === self::get($id)) {
            return false;
        }

        return self::update($id, array('status' => $status));
    }

    /** Replace the public opaque URL slug and return it, or an empty string. */
    public static function regenerate_slug(int $id): string
    {
        global $wpdb;

        if ($id <= 0 || null === self::get($id)) {
            return '';
        }

        $slug = self::new_slug();
        $feed_table = ADC_Feed_Schema::table(ADC_Feed_Schema::FEEDS);
        $snapshot_table = ADC_Feed_Schema::table(ADC_Feed_Schema::SNAPSHOTS);
        $paths = $wpdb->get_col(
            $wpdb->prepare("SELECT file_path FROM $snapshot_table WHERE feed_id = %d", $id)
        );
        if (!is_array($paths) || false === $wpdb->query('START TRANSACTION')) {
            return '';
        }
        $updated = $wpdb->update(
            $feed_table,
            array('slug' => $slug, 'updated_at' => current_time('mysql', true)),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
        $deleted = false === $updated ? false : $wpdb->delete($snapshot_table, array('feed_id' => $id), array('%d'));
        $committed = false === $deleted ? false : $wpdb->query('COMMIT');
        if (false === $updated || false === $deleted || false === $committed) {
            $wpdb->query('ROLLBACK');
            return '';
        }

        foreach (is_array($paths) ? $paths : array() as $path) {
            self::delete_snapshot_file((string) $path);
        }
        return $slug;
    }

    /** Persistent feed storage under the WordPress uploads directory. */
    public static function storage_directory(): string
    {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }
        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }
        $base = rtrim((string) $uploads['basedir'], '/\\');
        $directory = $base . DIRECTORY_SEPARATOR . 'adc-feeds';
        return is_link($base) || is_link($directory) ? '' : $directory;
    }

    /** Create persistent storage; guarded PHP snapshots do not depend on server config files. */
    public static function prepare_storage_directory(): string
    {
        $directory = self::storage_directory();
        if ('' === $directory) {
            return '';
        }
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return '';
        }
        if (is_link($directory)) {
            return '';
        }
        @chmod($directory, 0755);
        $real = realpath($directory);
        if (false === $real || !is_dir($real) || !is_writable($real)) {
            return '';
        }
        $index = $real . DIRECTORY_SEPARATOR . 'index.php';
        $guard = "<?php exit; ?>\n";
        if (is_link($index) || (file_exists($index) && !is_file($index))) {
            return '';
        }
        $existing = is_file($index) ? @file_get_contents($index) : false;
        if (false === $existing && file_exists($index)) {
            return '';
        }
        if (!is_string($existing) || !str_starts_with($existing, '<?php')) {
            if (is_string($existing) && '' !== $existing && '<?php // Silence is golden. ?>' !== trim($existing)) {
                return '';
            }
            if (false === file_put_contents($index, $guard, LOCK_EX)) {
                return '';
            }
        }
        return $real;
    }

    /** Informational GET probe that checks whether a PHP snapshot guard is actually executed. */
    public static function storage_autotest(): array
    {
        $directory = self::prepare_storage_directory();
        if ('' === $directory || !function_exists('wp_remote_get') || !function_exists('wp_upload_dir')) {
            return array('status' => 'storage_probe_unavailable', 'ok' => true, 'warning' => true, 'exposed' => false);
        }
        $uploads = wp_upload_dir(null, false);
        try {
            $filename = bin2hex(random_bytes(16)) . '.php';
            $token = bin2hex(random_bytes(24));
        } catch (Throwable) {
            return array('status' => 'storage_probe_unavailable', 'ok' => true, 'warning' => true, 'exposed' => false);
        }
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $contents = "<?php exit; ?>\n" . $token;
        if (!is_array($uploads) || empty($uploads['baseurl'])) {
            return array('status' => 'storage_probe_unavailable', 'ok' => true, 'warning' => true, 'exposed' => false);
        }
        if (false === file_put_contents($path, $contents, LOCK_EX)) {
            @unlink($path);
            return array('status' => 'storage_probe_unavailable', 'ok' => true, 'warning' => true, 'exposed' => false);
        }
        @chmod($path, 0644);
        try {
            $response = wp_remote_get(rtrim((string) $uploads['baseurl'], '/') . '/adc-feeds/' . rawurlencode($filename), array(
                'timeout' => 3,
                'redirection' => 0,
                'limit_response_size' => 4096,
                'headers' => array('Cache-Control' => 'no-cache'),
            ));
        } catch (Throwable) {
            $response = null;
        }
        $body = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        $exposed = str_contains($body, $token);
        $deleted = !file_exists($path) || @unlink($path);
        $http_status = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        if (!$deleted || (function_exists('is_wp_error') && is_wp_error($response))
            || $http_status < 200 || $http_status >= 300) {
            return array('status' => 'storage_probe_unavailable', 'ok' => true, 'warning' => true, 'exposed' => false);
        }
        return array(
            'status' => $exposed ? 'storage_exposed' : 'storage_guard_executed',
            'ok' => true,
            'warning' => $exposed,
            'exposed' => $exposed,
        );
    }

    /** Remove managed snapshot and temporary files before dropping feed tables; sidecar config is never touched. */
    public static function delete_all_snapshot_files(): bool
    {
        global $wpdb;

        $table = ADC_Feed_Schema::table(ADC_Feed_Schema::SNAPSHOTS);
        $paths = $wpdb->get_col("SELECT file_path FROM $table");
        if (!is_array($paths)) {
            return false;
        }
        foreach ($paths as $path) {
            if (!self::delete_snapshot_file((string) $path)) {
                return false;
            }
        }
        foreach (self::managed_storage_directories() as $directory) {
            if ('' === $directory || !is_dir($directory)) {
                continue;
            }
            $files = array_merge(
                glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: array(),
                glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.*.php') ?: array(),
                glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.probe') ?: array()
            );
            foreach (array_unique($files) as $path) {
                if (!preg_match('/^\\.?[a-f0-9]{32,}(?:\\.php|\\.probe)$/', basename($path))) {
                    continue;
                }
                if (!@unlink($path) && file_exists($path)) {
                    return false;
                }
            }
            $index = $directory . DIRECTORY_SEPARATOR . 'index.php';
            if (is_file($index) && !is_link($index)) {
                $contents = @file_get_contents($index);
                if (is_string($contents) && str_starts_with($contents, '<?php exit; ?>')) {
                    @unlink($index);
                }
            }
            @rmdir($directory);
        }
        return true;
    }

    /** Decode legacy string summaries or structured counts into a bounded, aggregated list. */
    private static function decode_summary(mixed $summary): array
    {
        $decoded = is_string($summary) ? json_decode($summary, true) : null;
        if (!is_array($decoded)) {
            return array();
        }
        $counts = array();
        foreach ($decoded as $item) {
            $code = is_array($item) ? ($item['code'] ?? null) : $item;
            $count = is_array($item) ? max(1, (int) ($item['count'] ?? 1)) : 1;
            if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/', $code)) {
                $code = 'unknown_diagnostic';
            }
            if (!isset($counts[$code]) && count($counts) >= 99) {
                $code = 'unknown_diagnostic';
            }
            $counts[$code] = ($counts[$code] ?? 0) + $count;
        }
        $result = array();
        foreach ($counts as $code => $count) {
            $result[] = array('code' => (string) $code, 'count' => $count);
        }
        return $result;
    }

    /** Generate a high-entropy, URL-safe slug. */
    public static function new_slug(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Normalize an incoming feed into a complete typed record. */
    private static function sanitize_feed(array $data, bool $creating): array
    {
        $name = self::text($data['name'] ?? '');
        $type = self::one_of($data['type'] ?? 'standard', self::TYPES, 'standard');
        $channel = self::one_of($data['channel'] ?? 'google', self::CHANNELS, 'google');
        $format = self::one_of($data['format'] ?? 'rss', self::FORMATS, 'rss');
        $channel_spec = ADC_Feed_Channels::get($channel);
        if (null !== $channel_spec && !in_array($format, $channel_spec['formats'], true)) {
            $format = '';
        }
        $status = self::one_of($data['status'] ?? ($creating ? 'draft' : 'draft'), self::STATUSES, 'draft');
        $raw_config = is_array($data['config'] ?? null) ? $data['config'] : array();
        $config_version = (int) ($raw_config['schema_version'] ?? 0);
        $config = self::migrate_config(self::sanitize_config($raw_config), $config_version);
        if (is_array($data['params'] ?? null)) {
            $params_config = self::sanitize_config(array('params' => $data['params']));
            if (is_array($params_config['params'] ?? null)) {
                $config['params'] = $params_config['params'];
            }
        }
        $config['schema_version'] = self::CONFIG_VERSION;

        return array(
            'name'    => function_exists('mb_substr') ? mb_substr($name, 0, 191) : substr($name, 0, 191),
            'type'    => $type,
            'channel' => $channel,
            'format'  => $format,
            'status'  => $status,
            'config'  => $config,
        );
    }

    /** Apply safe defaults when reading older JSON config versions without changing its database row. */
    private static function migrate_config(array $config, int $version): array
    {
        if ($version < 3) {
            $scope = $config['scope'] ?? 'all';
            if (!in_array($scope, array('all', 'filtered', 'smart'), true)) {
                $scope = 'invalid';
                $config['categories'] = array();
            }
            $config['scope'] = $scope;
            $refresh = $config['refresh'] ?? 'manual';
            $config['refresh'] = in_array($refresh, array('manual', 'hourly', 'daily', 'weekly'), true) ? $refresh : 'manual';
            if ('smart' === $config['scope'] && !is_array($config['smart_rule'] ?? null)) {
                $config['scope'] = 'invalid';
                unset($config['smart_rule']);
            }
        }
        unset($config['schema_version']);
        return $config;
    }

    /** Decode a database row into the stable API shape. */
    private static function hydrate(array $row): array
    {
        $config = json_decode((string) ($row['config_json'] ?? ''), true);
        if (!is_array($config)) {
            $config = array('scope' => 'invalid');
        }
        $config_version = (int) ($config['schema_version'] ?? 0);
        $config = self::migrate_config(self::sanitize_config($config), $config_version);
        $channel = (string) ($row['channel'] ?? 'google');
        if (empty($config['params']) && class_exists('ADC_Feed_Params')) {
            $defaults = ADC_Feed_Params::defaults_for($channel);
            if (!empty($defaults)) {
                $config['params'] = $defaults;
            }
        }

        return array(
            'id'         => (int) ($row['id'] ?? 0),
            'slug'       => (string) ($row['slug'] ?? ''),
            'name'       => (string) ($row['name'] ?? ''),
            'type'       => (string) ($row['type'] ?? 'standard'),
            'channel'    => (string) ($row['channel'] ?? 'google'),
            'format'     => (string) ($row['format'] ?? 'rss'),
            'config'     => $config,
            'status'     => (string) ($row['status'] ?? 'draft'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        );
    }

    /** Recursively retain only JSON-safe, bounded config values. */
    private static function sanitize_config(array $config, int $depth = 0): array
    {
        if ($depth >= 8) {
            return array();
        }

        $clean = array();
        $count = 0;
        foreach ($config as $key => $value) {
            if (++$count > 200) {
                break;
            }
            $safe_key = is_int($key) ? $key : self::key((string) $key);
            if ('' === (string) $safe_key || 'schema_version' === $safe_key) {
                continue;
            }

            if (is_array($value)) {
                $clean[$safe_key] = self::sanitize_config($value, $depth + 1);
            } elseif (is_bool($value) || is_int($value)) {
                $clean[$safe_key] = $value;
            } elseif (is_float($value) && is_finite($value)) {
                $clean[$safe_key] = $value;
            } elseif (is_string($value)) {
                $clean[$safe_key] = function_exists('sanitize_text_field')
                    ? sanitize_text_field($value)
                    : trim(strip_tags($value));
            }
        }

        return $clean;
    }

    private static function encode_config(array $config): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json) || strlen($json) > 65535) {
            throw new UnexpectedValueException('feed_config_unencodable');
        }
        return $json;
    }

    private static function one_of(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function text(mixed $value): string
    {
        return function_exists('sanitize_text_field')
            ? sanitize_text_field(is_scalar($value) ? (string) $value : '')
            : trim(strip_tags(is_scalar($value) ? (string) $value : ''));
    }

    private static function key(string $value): string
    {
        return function_exists('sanitize_key') ? sanitize_key($value) : strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value));
    }

    /** Resolve a guarded PHP snapshot in a managed feed-storage directory. */
    public static function managed_snapshot_file(string $path): string
    {
        if (!preg_match('/^[a-f0-9]{32,}\\.php$/', basename($path))) {
            return '';
        }
        $file = realpath($path);
        if (false === $file || !is_file($file) || !is_readable($file)) {
            return '';
        }
        return self::is_in_managed_storage($file) ? $file : '';
    }

    /** Delete a managed guarded PHP snapshot, never an arbitrary stored path. */
    public static function delete_snapshot_file(string $path): bool
    {
        if ('' === $path) {
            return false;
        }
        $file = realpath($path);
        if (false === $file || !file_exists($file)) {
            return true;
        }
        if ('' === self::managed_snapshot_file($path)) {
            return false;
        }
        return @unlink($file) || !file_exists($file);
    }

    /** Directories used by the current and previous feed-storage implementations. */
    private static function managed_storage_directories(): array
    {
        $directories = array(self::storage_directory(), self::previous_private_storage_directory());
        return array_values(array_unique(array_filter($directories, 'is_string')));
    }

    private static function is_in_managed_storage(string $file): bool
    {
        foreach (self::managed_storage_directories() as $directory) {
            $base = realpath($directory);
            if (false !== $base && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }
        return false;
    }

    private static function previous_private_storage_directory(): string
    {
        if (!function_exists('sys_get_temp_dir') || !defined('ABSPATH')) {
            return '';
        }
        $temporary_root = realpath(sys_get_temp_dir());
        $document_root = realpath(ABSPATH);
        if (false === $temporary_root || false === $document_root) {
            return '';
        }
        return $temporary_root . DIRECTORY_SEPARATOR . 'adc-marketing-private-' . substr(hash('sha256', $document_root), 0, 32)
            . DIRECTORY_SEPARATOR . 'feeds';
    }

}
