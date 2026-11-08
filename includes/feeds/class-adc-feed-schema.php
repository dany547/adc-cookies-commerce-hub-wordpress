<?php
/** Feed database schema lifecycle. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Owns only the catalog-feed tables; analytics schema remains separate. */
final class ADC_Feed_Schema
{
    public const DB_VERSION = '2';
    public const OPTION_DB_VERSION = 'adc_feed_db_version';
    public const FEEDS = 'adc_feeds';
    public const RUNS = 'adc_feed_runs';
    public const SNAPSHOTS = 'adc_feed_snapshots';

    /** @var string[] */
    private const TABLES = array(self::FEEDS, self::RUNS, self::SNAPSHOTS);

    /** Install on activation. */
    public static function activate(): void
    {
        self::install();
    }

    /** Upgrade only when the stored schema version differs; current-version requests do no database introspection. */
    public static function maybe_upgrade(): void
    {
        $version = (string) get_option(self::OPTION_DB_VERSION, '');
        if ($version === self::DB_VERSION) {
            return;
        }
        self::install();
    }

    /** Get the feed table names. */
    public static function tables(): array
    {
        return self::TABLES;
    }

    /** Resolve a known table key to its prefixed name. */
    public static function table(string $key): string
    {
        global $wpdb;

        if (!in_array($key, self::TABLES, true)) {
            throw new InvalidArgumentException('Unknown feed table.');
        }

        return $wpdb->prefix . $key;
    }

    /** Create or update the feed tables via dbDelta. */
    public static function install(): void
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (!is_file($upgrade_file)) {
                return; // An isolated/non-WordPress bootstrap has no schema API.
            }
            require_once $upgrade_file;
        }
        $charset_collate = $wpdb->get_charset_collate();
        $definitions = array(
            self::FEEDS => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
slug varchar(64) NOT NULL,
name varchar(191) NOT NULL DEFAULT '',
type enum('standard','smart') NOT NULL DEFAULT 'standard',
channel enum('google','meta','tiktok','olx','custom') NOT NULL DEFAULT 'custom',
format varchar(16) NOT NULL DEFAULT '',
config_json longtext NOT NULL,
status enum('draft','active','disabled') NOT NULL DEFAULT 'draft',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug),
KEY status (status),
KEY type_channel (type,channel)",
            self::RUNS => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
feed_id bigint(20) unsigned NOT NULL,
started_at datetime NOT NULL,
finished_at datetime NULL,
status varchar(16) NOT NULL DEFAULT 'pending',
products_examined bigint(20) unsigned NOT NULL DEFAULT 0,
products_exported bigint(20) unsigned NOT NULL DEFAULT 0,
error_summary text NULL,
warning_summary text NULL,
snapshot_hash char(64) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
KEY feed_started (feed_id,started_at),
KEY status (status)",
            self::SNAPSHOTS => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
feed_id bigint(20) unsigned NOT NULL,
file_path text NOT NULL,
bytes bigint(20) unsigned NOT NULL DEFAULT 0,
checksum char(64) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
is_current tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY feed_current (feed_id,is_current),
KEY created_at (created_at)",
        );

        foreach ($definitions as $key => $columns) {
            dbDelta('CREATE TABLE ' . self::table($key) . " (\n$columns\n) $charset_collate;");
        }

        if (self::has_required_columns()) {
            update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
        }
    }

    /** Verify the database, not the dbDelta return value, before recording a schema version. */
    private static function has_required_columns(): bool
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return false;
        }
        $required = array(
            self::FEEDS => array('id', 'slug', 'name', 'type', 'channel', 'format', 'config_json', 'status', 'created_at', 'updated_at'),
            self::RUNS => array('id', 'feed_id', 'started_at', 'finished_at', 'status', 'products_examined', 'products_exported', 'error_summary', 'warning_summary', 'snapshot_hash'),
            self::SNAPSHOTS => array('id', 'feed_id', 'file_path', 'bytes', 'checksum', 'created_at', 'is_current'),
        );
        foreach ($required as $key => $columns) {
            $rows = $wpdb->get_results('SHOW COLUMNS FROM ' . self::table($key), ARRAY_A);
            if (!is_array($rows)) {
                return false;
            }
            $actual = array();
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['Field'])) {
                    $actual[] = (string) $row['Field'];
                }
            }
            if (array_diff($columns, $actual)) {
                return false;
            }
        }
        return true;
    }

    /** Drop only feed-owned tables and settings when the site's wipe opt-in is enabled. */
    public static function uninstall(): bool
    {
        global $wpdb;

        if (!class_exists('ADC_Feed_Store') || !ADC_Feed_Store::delete_all_snapshot_files()) {
            return false;
        }
        foreach (self::TABLES as $key) {
            if (false === $wpdb->query('DROP TABLE IF EXISTS ' . self::table($key))) {
                return false;
            }
        }

        delete_option('adc_settings_feeds');
        delete_option(self::OPTION_DB_VERSION);
        return true;
    }
}
