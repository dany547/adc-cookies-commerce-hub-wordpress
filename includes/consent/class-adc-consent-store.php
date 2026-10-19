<?php
/**
 * Repository over {prefix}adc_consent_log (docs/ARCHITECTURE.md §4).
 *
 * The consent proof log: append-only rows keyed by cid. This class (together
 * with ADC_Installer and ADC_Outbox) is the only place allowed to run SQL,
 * always through $wpdb->prepare() — documented exception to "no direct DB".
 * All timestamps are UTC.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consent log storage. Never stores raw PII (ip/ua arrive pre-hashed).
 */
final class ADC_Consent_Store
{
    /**
     * Insert one consent log row.
     *
     * @param array $row Row data: cid, user_id, order_id, action, cat_preferences,
     *                   cat_statistics, cat_marketing, policy_version, text_hash,
     *                   source, gpc, ip_hash, ua_hash, created_at (UTC 'Y-m-d H:i:s').
     * @return int Insert id (0 on failure).
     */
    public static function insert(array $row): int
    {
        global $wpdb;

        $table = self::table();

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (cid, user_id, order_id, action, cat_preferences, cat_statistics, cat_marketing,
                     policy_version, text_hash, source, gpc, ip_hash, ua_hash, created_at)
                 VALUES (%s, %d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) ($row['cid'] ?? ''),
                (int) ($row['user_id'] ?? 0),
                (int) ($row['order_id'] ?? 0),
                (string) ($row['action'] ?? 'update'),
                empty($row['cat_preferences']) ? 0 : 1,
                empty($row['cat_statistics']) ? 0 : 1,
                empty($row['cat_marketing']) ? 0 : 1,
                max(1, (int) ($row['policy_version'] ?? 1)),
                substr((string) ($row['text_hash'] ?? ''), 0, 64),
                substr((string) ($row['source'] ?? ''), 0, 24),
                empty($row['gpc']) ? 0 : 1,
                substr((string) ($row['ip_hash'] ?? ''), 0, 64),
                substr((string) ($row['ua_hash'] ?? ''), 0, 64),
                (string) ($row['created_at'] ?? gmdate('Y-m-d H:i:s'))
            )
        );

        return false === $result ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Newest log row for a cid (drives send-time re-evaluation and
     * withdrawal detection). All timestamps are UTC.
     *
     * @param string $cid Consent id.
     * @return array|null Raw row or null when none exists.
     */
    public static function latest_for_cid(string $cid): ?array
    {
        global $wpdb;

        if (!ADC_Consent_State::is_uuid($cid)) {
            return null;
        }

        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE cid = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $cid
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Consent history for one user (privacy exporter / admin lookup).
     *
     * @param int $user_id WordPress user id.
     * @param int $limit   Max rows (newest first).
     * @return array<int, array> Raw rows.
     */
    public static function for_user(int $user_id, int $limit = 100): array
    {
        global $wpdb;

        if ($user_id <= 0) {
            return array();
        }

        $table  = self::table();
        $limit  = max(1, min(1000, $limit));
        $result = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $user_id,
                $limit
            ),
            ARRAY_A
        );

        return is_array($result) ? $result : array();
    }

    /**
     * Anonymise log rows of a deleted user (user_id=0; cid kept as proof,
     * see docs/ARCHITECTURE.md §6).
     *
     * @param int $user_id Deleted user id.
     * @return int Affected rows.
     */
    public static function anonymize_user(int $user_id): int
    {
        global $wpdb;

        if ($user_id <= 0) {
            return 0;
        }

        $table = self::table();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET user_id = 0 WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $user_id
            )
        );
    }

    /**
     * Erase every log row of a user (GDPR erasure request; the whole row —
     * including the cid — goes, not just the user link).
     *
     * @param int $user_id User id.
     * @return int Deleted rows.
     */
    public static function delete_for_user(int $user_id): int
    {
        global $wpdb;

        if ($user_id <= 0) {
            return 0;
        }

        $table = self::table();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $user_id
            )
        );
    }

    /**
     * Purge rows older than $months (retention cron,
     * `consent.log_retention_months`, default 36 — a policy number, U4).
     *
     * @param int $months Retention window in months.
     * @return int Deleted rows.
     */
    public static function purge(int $months): int
    {
        global $wpdb;

        $months = max(1, min(120, $months));
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $months . ' months') ?: time());

        $table = self::table();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $cutoff
            )
        );
    }

    /**
     * Consent log table name (prefix + fixed literal, no user input).
     *
     * @return string
     */
    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'adc_consent_log';
    }
}
