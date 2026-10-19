<?php
/**
 * Canonical, platform-neutral event value object (docs/ARCHITECTURE.md §2.1).
 *
 * GA4 naming; JSON-serialisable; never holds raw PII (user fields are hashed).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable tracking event.
 */
final class ADC_Event
{
    /**
     * @param string     $name        GA4 canonical event name (page_view…purchase|identify|custom_*).
     * @param string     $event_id    Shared browser/server id (see ADC_Event_Id).
     * @param int        $time        Unix seconds.
     * @param string     $origin      'server' | 'browser'.
     * @param string     $source_url  Page URL the event originated from.
     * @param string     $currency    ISO 4217 currency.
     * @param float|null $value       Monetised value (null when not applicable).
     * @param array      $items       GA4 item objects (§2.4).
     * @param int        $order_id    WooCommerce order id (0 when none).
     * @param ADC_User_Data|null $user Hashed matching fields.
     * @param array      $attribution fbp, fbc, ttp, ttclid, gclid, ga_client_id, ga_session_id, client_ip, user_agent.
     * @param string     $cid         Consent id (uuid) of the visitor/order.
     * @param array      $consent     Snapshot: preferences, statistics, marketing (bool), ts (int), decided (bool).
     * @param array      $params      transaction_id, tax, shipping, coupon, search_term, method, …
     */
    public function __construct(
        public readonly string $name,
        public readonly string $event_id,
        public readonly int $time,
        public readonly string $origin,
        public readonly string $source_url = '',
        public readonly string $currency = '',
        public readonly ?float $value = null,
        public readonly array $items = array(),
        public readonly int $order_id = 0,
        public readonly ?ADC_User_Data $user = null,
        public readonly array $attribution = array(),
        public readonly string $cid = '',
        public readonly array $consent = array(),
        public readonly array $params = array(),
    ) {
    }

    /**
     * Serialise to a plain array (JSON-safe; ADC_User_Data becomes its hashed array).
     *
     * @return array
     */
    public function to_array(): array
    {
        return array(
            'name'       => $this->name,
            'event_id'   => $this->event_id,
            'time'       => $this->time,
            'origin'     => $this->origin,
            'source_url' => $this->source_url,
            'currency'   => $this->currency,
            'value'      => $this->value,
            'items'      => $this->items,
            'order_id'   => $this->order_id,
            'user'       => $this->user?->to_array(),
            'attribution' => $this->attribution,
            'cid'        => $this->cid,
            'consent'    => $this->consent,
            'params'     => $this->params,
        );
    }

    /**
     * Rebuild from a plain array (e.g. decoded outbox payload).
     *
     * @param array $data Serialised event.
     * @return self
     */
    public static function from_array(array $data): self
    {
        $user = null;
        if (isset($data['user']) && is_array($data['user'])) {
            $candidate = ADC_User_Data::from_array($data['user']);
            if (!$candidate->is_empty()) {
                $user = $candidate;
            }
        }

        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['event_id'] ?? ''),
            (int) ($data['time'] ?? 0),
            (string) ($data['origin'] ?? 'server'),
            (string) ($data['source_url'] ?? ''),
            (string) ($data['currency'] ?? ''),
            isset($data['value']) && null !== $data['value'] ? (float) $data['value'] : null,
            is_array($data['items'] ?? null) ? $data['items'] : array(),
            (int) ($data['order_id'] ?? 0),
            $user,
            is_array($data['attribution'] ?? null) ? $data['attribution'] : array(),
            (string) ($data['cid'] ?? ''),
            is_array($data['consent'] ?? null) ? $data['consent'] : array(),
            is_array($data['params'] ?? null) ? $data['params'] : array(),
        );
    }
}
