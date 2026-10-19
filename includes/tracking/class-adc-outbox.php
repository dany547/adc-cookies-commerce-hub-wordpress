<?php
/**
 * Repository over the adc_event_outbox table (docs/ARCHITECTURE.md §2.5, §4).
 *
 * Custom-table SQL with $wpdb->prepare is the documented exception to the
 * "no direct DB" rule (§6). All timestamps are UTC.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Outbox persistence: insert (duplicate-safe), fetch, status transitions,
 * listing for the Logs tab, counts and retention purge.
 */
final class ADC_Outbox
{
    /**
     * Known status values (skipped_disabled is added by §9.1 D20).
     */
    public const STATUSES = array('pending', 'sent', 'dead', 'skipped', 'skipped_no_consent', 'skipped_disabled');

    /**
     * Insert one job row. UNIQUE (event_id, platform) makes this duplicate-safe.
     *
     * @param ADC_Event $e            Canonical event (hashed identifiers only).
     * @param string    $platform     Platform id ('meta'|'ga4'|'sgtm'|'tiktok').
     * @param int       $scheduled_at Unix seconds for the first attempt.
     * @return int Row id, 0 when the row already exists (duplicate) or insert failed.
     */
    public static function insert(ADC_Event $e, string $platform, int $scheduled_at): int
    {
        global $wpdb;

        $table  = self::table();
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (event_id, platform, event_name, order_id, cid, status, attempts, payload, http_code, last_error, scheduled_at, sent_at, created_at)
                 VALUES (%s, %s, %s, %d, %s, %s, %d, %s, %d, %s, %s, NULL, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $e->event_id,
                sanitize_key($platform),
                sanitize_key($e->name),
                $e->order_id,
                substr($e->cid, 0, 36),
                'pending',
                0,
                wp_json_encode($e->to_array()),
                0,
                '',
                gmdate('Y-m-d H:i:s', max(0, $scheduled_at)),
                gmdate('Y-m-d H:i:s')
            )
        );

        if (null === $result || false === $result) {
            // Duplicate key (same event_id + platform already queued) is the expected benign case.
            if (false === stripos((string) $wpdb->last_error, 'duplicate')) {
                ADC_Logger::log('error', 'Outbox insert failed.', array('platform' => $platform));
            }

            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Fetch one job row.
     *
     * @param int $id Row id.
     * @return ADC_Outbox_Job|null
     */
    public static function get(int $id): ?ADC_Outbox_Job
    {
        global $wpdb;

        $table = self::table();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return ADC_Outbox_Job::from_row($row);
    }

    /**
     * Transition a job: status, http code, scrubbed error, optional payload clear.
     * Status 'sent' also stamps sent_at.
     *
     * @param int    $id            Row id.
     * @param string $status        New status (see STATUSES).
     * @param int    $http_code     Platform HTTP code.
     * @param string $error         Detail (scrubbed, stored <= 255 chars).
     * @param bool   $clear_payload NULL the payload after a successful send.
     */
    public static function mark(int $id, string $status, int $http_code = 0, string $error = '', bool $clear_payload = false): void
    {
        global $wpdb;

        if (!in_array($status, self::STATUSES, true)) {
            return;
        }

        $table  = self::table();
        $fields = array(
            'status'    => $status,
            'http_code' => max(0, min(65535, $http_code)),
            'last_error' => substr(ADC_Logger::scrub($error), 0, 255),
        );

        if ('sent' === $status) {
            $fields['sent_at'] = gmdate('Y-m-d H:i:s');
        }

        $placeholders = array('%s', '%d', '%s');
        if ('sent' === $status) {
            $placeholders[] = '%s';
        }

        $set = array();
        foreach (array_keys($fields) as $i => $key) {
            $set[] = "{$key} = {$placeholders[$i]}";
        }
        if ($clear_payload) {
            $set[] = 'payload = NULL';
        }

        $values   = array_values($fields);
        $values[] = $id;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET " . implode(', ', $set) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $values
            )
        );
    }

    /**
     * Reschedule a failed job: attempts+1, status back to pending, new time.
     *
     * @param int    $id        Row id.
     * @param int    $when      Unix seconds of the next attempt.
     * @param string $error     Scrubbed failure detail.
     * @param int    $http_code Platform HTTP code.
     */
    public static function schedule_retry(int $id, int $when, string $error, int $http_code): void
    {
        global $wpdb;

        $table = self::table();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = %s, attempts = attempts + 1, last_error = %s, http_code = %d, scheduled_at = %s
                 WHERE id = %d",
                'pending',
                substr(ADC_Logger::scrub($error), 0, 255),
                max(0, min(65535, $http_code)),
                gmdate('Y-m-d H:i:s', max(0, $when)),
                $id
            )
        );
    }

    /**
     * Reset a job for a manual retry (Logs tab): status pending, attempts 0.
     *
     * @param int $id Row id.
     * @return bool Whether a pending row now exists.
     */
    public static function reset_for_retry(int $id): bool
    {
        global $wpdb;

        $table = self::table();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, attempts = 0, last_error = '', http_code = 0, scheduled_at = %s WHERE status != 'sent' AND id = %d",
                'pending',
                gmdate('Y-m-d H:i:s'),
                $id
            )
        );

        return 'pending' === (self::get($id)->status ?? '');
    }

    /**
     * Recent rows for the Logs tab, newest first.
     *
     * @param int   $limit   Max rows.
     * @param array $filters Optional: status, platform, cid, order_id.
     * @return ADC_Outbox_Job[]
     */
    public static function recent(int $limit = 200, array $filters = array()): array
    {
        global $wpdb;

        $table = self::table();
        $where = array('1=1');
        $args  = array();

        if (!empty($filters['status']) && in_array((string) $filters['status'], self::STATUSES, true)) {
            $where[] = 'status = %s';
            $args[]  = (string) $filters['status'];
        }
        if (!empty($filters['platform'])) {
            $where[] = 'platform = %s';
            $args[]  = sanitize_key((string) $filters['platform']);
        }
        if (!empty($filters['cid'])) {
            $where[] = 'cid = %s';
            $args[]  = substr((string) $filters['cid'], 0, 36);
        }
        if (!empty($filters['order_id'])) {
            $where[] = 'order_id = %d';
            $args[]  = (int) $filters['order_id'];
        }

        $limit = max(1, min(500, $limit));
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                array_merge($args, array($limit))
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

        return array_map(static fn (array $row): ADC_Outbox_Job => ADC_Outbox_Job::from_row($row), $rows);
    }

    /**
     * Row count per status (zero-filled for known statuses).
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        global $wpdb;

        $counts = array_fill_keys(self::STATUSES, 0);

        $table = self::table();
        $rows  = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? '');
                if ('' !== $status) {
                    $counts[$status] = (int) ($row['n'] ?? 0);
                }
            }
        }

        return $counts;
    }

    /**
     * Retention purge: sent rows older than $sent_days, terminal rows older
     * than $dead_days. Pending rows are never purged.
     *
     * @param int $sent_days Days to keep sent rows.
     * @param int $dead_days Days to keep dead/skipped rows.
     * @return int Deleted rows.
     */
    public static function purge(int $sent_days, int $dead_days): int
    {
        global $wpdb;

        $table     = self::table();
        $sent_cut  = gmdate('Y-m-d H:i:s', time() - max(1, $sent_days) * DAY_IN_SECONDS);
        $dead_cut  = gmdate('Y-m-d H:i:s', time() - max(1, $dead_days) * DAY_IN_SECONDS);

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE (status = 'sent' AND created_at < %s)
                    OR (status IN ('dead','skipped','skipped_no_consent','skipped_disabled') AND created_at < %s)",
                $sent_cut,
                $dead_cut
            )
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Pending rows for one consent id (used by cancel_pending).
     *
     * @param string $cid Consent id.
     * @return ADC_Outbox_Job[]
     */
    public static function pending_for_cid(string $cid): array
    {
        global $wpdb;

        if ('' === $cid) {
            return array();
        }

        $table = self::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' AND cid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                substr($cid, 0, 36)
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

        return array_map(static fn (array $row): ADC_Outbox_Job => ADC_Outbox_Job::from_row($row), $rows);
    }

    /**
     * Fully-qualified table name.
     *
     * @return string
     */
    private static function table(): string
    {
        global $wpdb;

        return "{$wpdb->prefix}adc_event_outbox";
    }
}
