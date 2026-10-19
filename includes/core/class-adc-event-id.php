<?php
/**
 * Event id generation (docs/ARCHITECTURE.md §2.1).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical event_id helpers shared by browser and server sides.
 */
final class ADC_Event_Id
{
    /**
     * Random server-origin id: 'adc_' + 16 hex chars (non-deterministic).
     *
     * @return string
     */
    public static function random(): string
    {
        return 'adc_' . bin2hex(random_bytes(8));
    }

    /**
     * Deterministic id for an order event: '{event}.{order_id}' (e.g. purchase.1234).
     *
     * @param int    $order_id WooCommerce order id.
     * @param string $event    Event name (sanitised to [a-z0-9_]).
     * @return string
     */
    public static function for_order(int $order_id, string $event = 'purchase'): string
    {
        $event = preg_replace('/[^a-z0-9_]/', '', strtolower($event)) ?? '';

        return ($event ?: 'event') . '.' . $order_id;
    }

    /**
     * Validate an event id: 8-64 chars of [A-Za-z0-9_.-].
     *
     * @param string $id Candidate id.
     * @return bool
     */
    public static function is_valid(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_.\-]{8,64}$/', $id);
    }
}
