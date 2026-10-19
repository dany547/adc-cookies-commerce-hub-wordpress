<?php
/**
 * Activation / upgrade / uninstall routines and DB tables (dbDelta).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin installer: tables, db version, migrations, activation lifecycle.
 */
final class ADC_Installer implements ADC_Module_Interface
{
    /**
     * Plugin-owned options outside the `adc_settings_*` family.
     *
     * @var string[]
     */
    private const OPTIONS = [
        'adc_consent_texts',
        'adc_db_version',
        'adc_migrations',
        'adc_marketing_consent_message',
    ];

    /**
     * Scheduled hooks owned by the plugin (WP-Cron and Action Scheduler).
     *
     * @var string[]
     */
    private const CRON_HOOKS = [
        'adc_consent_cleanup',
        'adc_outbox_cleanup',
        'adc_erase_attribution',
        'adc_dispatch_job',
    ];

    /**
     * Action Scheduler group used by the dispatcher and retention jobs.
     */
    private const AS_GROUP = 'adc-marketing';

    /**
     * User meta keys written by the plugin.
     *
     * @var string[]
     */
    private const USER_META_KEYS = [
        '_adc_marketing_consent',
        '_adc_marketing_consent_date',
        '_adc_marketing_consent_source',
        '_adc_marketing_email_consent',
        '_adc_marketing_email_consent_date',
        '_adc_marketing_sms_consent',
        '_adc_marketing_sms_consent_date',
        '_adc_cookie_consent',
    ];

    /**
     * Order meta keys written by the plugin (legacy raw IP included).
     *
     * @var string[]
     */
    private const ORDER_META_KEYS = [
        '_adc_attribution',
        '_adc_consent_cid',
        '_adc_consent_ip_address',
        '_adc_consent_ip_hash',
        '_adc_consent_snapshot',
        '_adc_consent_text_hash',
        '_adc_consent_text_version',
        '_adc_marketing_consent',
        '_adc_marketing_consent_date',
        '_adc_marketing_consent_source',
        '_adc_purchase_pushed',
        '_adc_purchase_sent',
    ];

    /**
     * Boot the module: run the upgrade routine on plugins_loaded.
     */
    public static function boot(): void
    {
        // Registry boots during plugins_loaded (priority 10); priority 20 still runs in the same pass.
        add_action('plugins_loaded', array(self::class, 'maybe_upgrade'), 20);
    }

    /**
     * Plugin activation: create tables, record db version, (re)register the
     * legacy My Account endpoint and flush rewrite rules.
     */
    public static function activate(): void
    {
        self::create_tables();
        update_option('adc_db_version', self::db_version());
        self::record_migration('db_' . self::db_version());

        // Operational analytics tables (own versioned lifecycle, never bumps adc_db_version).
        if (class_exists('ADC_Analytics_Schema')) {
            ADC_Analytics_Schema::activate();
        }

        // Keep 1.0 behaviour: marketing-consent endpoint must exist after activation.
        add_rewrite_endpoint('marketing-consent', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Compare `adc_db_version` with the current one and upgrade if needed.
     * Never touches options/metas that already exist (zero-change upgrade, §7).
     */
    public static function maybe_upgrade(): void
    {
        $current = (string) get_option('adc_db_version', '0');
        if ($current === self::db_version()) {
            return;
        }

        self::create_tables();
        update_option('adc_db_version', self::db_version());
        self::record_migration('db_' . self::db_version());
    }

    /**
     * Opt-in data wipe, called from uninstall.php only when
     * `consent.delete_data_on_uninstall` is enabled. Removes the two tables, every
     * plugin option and transient, the scheduled hooks, and the plugin's user and
     * order meta. Without the opt-in, uninstall.php keeps all consent data.
     */
    public static function uninstall(): void
    {
        global $wpdb;

        // Table names are $wpdb->prefix + fixed literals; no user input involved.
        foreach (array('adc_consent_log', 'adc_event_outbox') as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // Operational analytics tables/schedules (own schema lifecycle), same opt-in.
        if (class_exists('ADC_Analytics_Schema')) {
            ADC_Analytics_Schema::uninstall();
        }

        // Options and transients (stored as options). Transients cover adc_conflict_scan,
        // adc_notices_{user} and the adc_rl_* rate-limit counters.
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                'adc\_settings\_%',
                '\_transient\_adc\_%',
                '\_transient\_timeout\_adc\_%',
                '\_site\_transient\_adc\_%'
            )
        );

        $option_names = array_merge(is_array($option_names) ? $option_names : array(), self::OPTIONS);
        foreach ($option_names as $name) {
            delete_option((string) $name);
        }

        // Scheduled events: WP-Cron (any args) and Action Scheduler (plugin group).
        foreach (self::CRON_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);

            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, null, self::AS_GROUP);
            }
        }

        // User meta: a null-object delete removes the key for every user.
        foreach (self::USER_META_KEYS as $meta_key) {
            delete_metadata('user', 0, $meta_key, '', true);
        }

        // Order meta works with both storage modes, without depending on WooCommerce being loaded.
        $placeholders = implode(',', array_fill(0, count(self::ORDER_META_KEYS), '%s'));

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
                ...self::ORDER_META_KEYS
            )
        );

        // HPOS order meta table, only when it exists.
        $hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
        if ($hpos_meta_table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table))) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$hpos_meta_table} WHERE meta_key IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
                    ...self::ORDER_META_KEYS
                )
            );
        }
    }

    /**
     * Current db version.
     *
     * @return string
     */
    public static function db_version(): string
    {
        return defined('ADC_MARKETING_DB_VERSION') ? (string) ADC_MARKETING_DB_VERSION : '1';
    }

    /**
     * Create/upgrade the 2.0 tables with dbDelta.
     */
    private static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$wpdb->prefix}adc_consent_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cid char(36) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(16) NOT NULL,
  cat_preferences tinyint(1) NOT NULL DEFAULT 0,
  cat_statistics tinyint(1) NOT NULL DEFAULT 0,
  cat_marketing tinyint(1) NOT NULL DEFAULT 0,
  policy_version int(10) unsigned NOT NULL DEFAULT 1,
  text_hash char(64) NOT NULL DEFAULT '',
  source varchar(24) NOT NULL DEFAULT '',
  gpc tinyint(1) NOT NULL DEFAULT 0,
  ip_hash char(64) NOT NULL DEFAULT '',
  ua_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY cid (cid),
  KEY user_id (user_id),
  KEY order_id (order_id),
  KEY created_at (created_at)
) {$charset_collate};

CREATE TABLE {$wpdb->prefix}adc_event_outbox (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(64) NOT NULL,
  platform varchar(16) NOT NULL,
  event_name varchar(40) NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cid char(36) NOT NULL DEFAULT '',
  status varchar(24) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  payload longtext NULL,
  http_code smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT '',
  scheduled_at datetime NOT NULL,
  sent_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_platform (event_id, platform),
  KEY status_sched (status, scheduled_at),
  KEY cid (cid),
  KEY order_id (order_id)
) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Record a finished migration id in the `adc_migrations` option.
     *
     * @param string $id Migration id.
     */
    private static function record_migration(string $id): void
    {
        $done = get_option('adc_migrations', array());
        $done = is_array($done) ? $done : array();

        if (!in_array($id, $done, true)) {
            $done[] = $id;
            update_option('adc_migrations', $done, 'yes');
        }
    }
}
