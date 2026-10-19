<?php
/** Analytics schema lifecycle. */
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

final class ADC_Analytics_Schema implements ADC_Module_Interface
{
    public const DB_VERSION = '1';
    public const OPTION_DB_VERSION = 'adc_analytics_db_version';
    public const TRAFFIC = 'adc_analytics_traffic';
    public const INTERACTIONS = 'adc_analytics_interactions';
    public const COMMERCE = 'adc_analytics_commerce_daily';
    public const PRODUCTS = 'adc_analytics_products_daily';
    public const LEDGER = 'adc_analytics_order_ledger';
    public const CAMPAIGNS = 'adc_analytics_campaign_daily';
    private const TABLES = [self::TRAFFIC, self::INTERACTIONS, self::COMMERCE, self::PRODUCTS, self::LEDGER, self::CAMPAIGNS];

    public static function boot(): void { add_action('plugins_loaded', [self::class, 'maybe_upgrade'], 25); }
    public static function activate(): void { self::install(); }
    public static function maybe_upgrade(): void
    {
        if ((string) get_option(self::OPTION_DB_VERSION, '') !== self::DB_VERSION) { self::install(); }
    }
    public static function tables(): array { return self::TABLES; }
    public static function table(string $key): string
    {
        global $wpdb;
        if (!in_array($key, self::TABLES, true)) { throw new InvalidArgumentException('Unknown analytics table.'); }
        return $wpdb->prefix . $key;
    }
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        // dbDelta canonical formatting: every column and index on its own line,
        // two spaces after "PRIMARY KEY". Single-line definitions break dbDelta's
        // line-by-line diff on re-activation (invalid ALTER errors).
        $definitions = [
            self::TRAFFIC => "id bigint unsigned NOT NULL AUTO_INCREMENT,
stat_date date NOT NULL,
stat_hour tinyint unsigned NOT NULL DEFAULT 0,
channel varchar(16) NOT NULL DEFAULT 'unknown',
page_type varchar(24) NOT NULL DEFAULT 'other',
object_id bigint unsigned NOT NULL DEFAULT 0,
views bigint unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY bucket (stat_date,stat_hour,channel,page_type,object_id),
KEY stat_date (stat_date),
KEY page (page_type,object_id)",
            self::INTERACTIONS => "id bigint unsigned NOT NULL AUTO_INCREMENT,
stat_date date NOT NULL,
event_name varchar(32) NOT NULL,
count bigint unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY bucket (stat_date,event_name)",
            self::COMMERCE => "id bigint unsigned NOT NULL AUTO_INCREMENT,
stat_date date NOT NULL,
currency char(3) NOT NULL,
order_status varchar(20) NOT NULL DEFAULT '',
metric varchar(32) NOT NULL,
amount decimal(18,6) NOT NULL DEFAULT 0,
count bigint unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY bucket (stat_date,currency,order_status,metric),
KEY stat_date (stat_date),
KEY metric (metric)",
            self::PRODUCTS => "id bigint unsigned NOT NULL AUTO_INCREMENT,
stat_date date NOT NULL,
currency char(3) NOT NULL,
product_id bigint unsigned NOT NULL,
units bigint unsigned NOT NULL DEFAULT 0,
items_net decimal(18,6) NOT NULL DEFAULT 0,
items_tax decimal(18,6) NOT NULL DEFAULT 0,
refund_units bigint unsigned NOT NULL DEFAULT 0,
refund_net decimal(18,6) NOT NULL DEFAULT 0,
refund_tax decimal(18,6) NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY bucket (stat_date,currency,product_id),
KEY stat_date (stat_date),
KEY product (product_id)",
            // Extended ledger metadata preserves safe idempotency diagnostics; retention must not outrun commerce.
            self::LEDGER => "id bigint unsigned NOT NULL AUTO_INCREMENT,
order_id bigint unsigned NOT NULL,
event_key varchar(40) NOT NULL,
status varchar(20) NOT NULL DEFAULT '',
currency char(3) NOT NULL DEFAULT '',
amount decimal(18,6) NOT NULL DEFAULT 0,
stat_date date NOT NULL,
occurred_at datetime NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY order_event (order_id,event_key),
KEY stat_date (stat_date)",
            self::CAMPAIGNS => "id bigint unsigned NOT NULL AUTO_INCREMENT,
stat_date date NOT NULL,
source varchar(32) NOT NULL,
medium varchar(32) NOT NULL,
campaign varchar(32) NOT NULL,
views bigint unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY bucket (stat_date,source,medium,campaign)",
        ];
        foreach ($definitions as $key => $columns) { dbDelta('CREATE TABLE ' . self::table($key) . " (\n$columns\n) ENGINE=InnoDB $charset;"); }
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
    }
    public static function uninstall(): void
    {
        global $wpdb;
        foreach (self::TABLES as $key) { $wpdb->query('DROP TABLE IF EXISTS ' . self::table($key)); }
        delete_option('adc_settings_analytics');
        delete_option(self::OPTION_DB_VERSION);
        foreach (['adc_analytics_%'] as $pattern) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
        }
        wp_clear_scheduled_hook('adc_analytics_cleanup');
        wp_clear_scheduled_hook('adc_analytics_record'); // Commerce jobs (WP-Cron fallback lives outside the AS group).
        if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('', [], 'adc-analytics'); }
    }
}
