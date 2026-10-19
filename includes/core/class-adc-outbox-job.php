<?php
/**
 * Outbox row DTO (docs/ARCHITECTURE.md §4 table adc_event_outbox).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable representation of one adc_event_outbox row.
 */
final class ADC_Outbox_Job
{
    /**
     * @param int         $id           Row id (0 before insert).
     * @param string      $event_id     Shared browser/server event id.
     * @param string      $platform     'meta'|'ga4'|'sgtm'|'tiktok'.
     * @param string      $event_name   GA4 canonical event name.
     * @param int         $order_id     WooCommerce order id (0 when none).
     * @param string      $cid          Consent id of the visitor/order.
     * @param string      $status       pending|sent|dead|skipped_no_consent.
     * @param int         $attempts     Delivery attempts so far.
     * @param array|null  $payload      ADC_Event::to_array() as array; NULL after 'sent'.
     * @param int         $http_code    Last platform HTTP code.
     * @param string      $last_error   Last scrubbed error (<= 255 chars).
     * @param string      $scheduled_at UTC MySQL datetime of the next attempt.
     * @param string|null $sent_at      UTC MySQL datetime when sent.
     * @param string      $created_at   UTC MySQL datetime of creation.
     */
    public function __construct(
        public readonly int $id = 0,
        public readonly string $event_id = '',
        public readonly string $platform = '',
        public readonly string $event_name = '',
        public readonly int $order_id = 0,
        public readonly string $cid = '',
        public readonly string $status = 'pending',
        public readonly int $attempts = 0,
        public readonly ?array $payload = null,
        public readonly int $http_code = 0,
        public readonly string $last_error = '',
        public readonly string $scheduled_at = '',
        public readonly ?string $sent_at = null,
        public readonly string $created_at = '',
    ) {
    }

    /**
     * Build from a $wpdb row; `payload` is JSON-decoded to an array.
     *
     * @param array $row Raw database row.
     * @return self
     */
    public static function from_row(array $row): self
    {
        $payload = null;
        if (isset($row['payload']) && is_string($row['payload']) && '' !== $row['payload']) {
            $decoded = json_decode($row['payload'], true);
            $payload = is_array($decoded) ? $decoded : null;
        } elseif (isset($row['payload']) && is_array($row['payload'])) {
            $payload = $row['payload'];
        }

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['event_id'] ?? ''),
            (string) ($row['platform'] ?? ''),
            (string) ($row['event_name'] ?? ''),
            (int) ($row['order_id'] ?? 0),
            (string) ($row['cid'] ?? ''),
            (string) ($row['status'] ?? 'pending'),
            (int) ($row['attempts'] ?? 0),
            $payload,
            (int) ($row['http_code'] ?? 0),
            (string) ($row['last_error'] ?? ''),
            (string) ($row['scheduled_at'] ?? ''),
            isset($row['sent_at']) && null !== $row['sent_at'] ? (string) $row['sent_at'] : null,
            (string) ($row['created_at'] ?? ''),
        );
    }

    /**
     * Row shape ready for insert/update (payload JSON-encoded).
     *
     * @return array
     */
    public function to_row(): array
    {
        return array(
            'event_id'     => $this->event_id,
            'platform'     => $this->platform,
            'event_name'   => $this->event_name,
            'order_id'     => $this->order_id,
            'cid'          => $this->cid,
            'status'       => $this->status,
            'attempts'     => $this->attempts,
            'payload'      => null === $this->payload ? null : wp_json_encode($this->payload),
            'http_code'    => $this->http_code,
            'last_error'   => $this->last_error,
            'scheduled_at' => $this->scheduled_at,
            'sent_at'      => $this->sent_at,
        );
    }
}
