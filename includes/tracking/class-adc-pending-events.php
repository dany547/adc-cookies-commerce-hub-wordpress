<?php
/**
 * Server-origin events queued for browser delivery (docs/ARCHITECTURE.md §2.4, §3.2).
 *
 * Stored in the WooCommerce session (guest + logged-in identical). Delivered
 * to the browser via the `adc_events` cart fragment (classic AJAX add-to-cart)
 * or the inline footer script on the next full page; the browser pushes them
 * with the SERVER's event_id and never mirrors them (single origin, no double
 * counting). Session data contains hashed user fields only — no raw PII.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC-session backed queue of pending browser pushes.
 */
final class ADC_Pending_Events implements ADC_Module_Interface
{
    /**
     * WC session key.
     */
    public const SESSION_KEY = 'adc_pending_events';

    /**
     * Hard cap so a busy session can never bloat the session row.
     */
    public const MAX_EVENTS = 20;

    /**
     * Boot the module (no hooks needed; storage API only).
     */
    public static function boot(): void
    {
        // Storage-only helper; hooks live in ADC_Event_Hooks / ADC_Datalayer.
    }

    /**
     * Whether a WooCommerce session is available this request.
     *
     * @return bool
     */
    public static function available(): bool
    {
        return function_exists('WC') && isset(WC()->session) && null !== WC()->session;
    }

    /**
     * Queue one canonical event for browser delivery.
     *
     * @param ADC_Event $event Server-origin event (already has its event_id).
     * @return bool queued?
     */
    public static function push(ADC_Event $event): bool
    {
        if (!self::available()) {
            return false;
        }

        $events = self::all();
        $events[] = $event->to_array();

        // Drop oldest beyond the cap (browser only needs the freshest burst).
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }

        WC()->session->set(self::SESSION_KEY, $events);

        return true;
    }

    /**
     * All queued events (serialised form, ready for JSON delivery).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (!self::available()) {
            return array();
        }

        $events = WC()->session->get(self::SESSION_KEY, array());

        return is_array($events) ? $events : array();
    }

    /**
     * Consume the queue: return events and clear the session key so a given
     * server event is pushed to the dataLayer exactly once.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pull(): array
    {
        $events = self::all();
        self::clear();

        return $events;
    }

    /**
     * Clear the queue without consuming it (consent withdrawal, logout).
     */
    public static function clear(): void
    {
        if (!self::available()) {
            return;
        }

        WC()->session->set(self::SESSION_KEY, array());
    }

    /**
     * Whether events are queued.
     *
     * @return bool
     */
    public static function has(): bool
    {
        return !empty(self::all());
    }
}
