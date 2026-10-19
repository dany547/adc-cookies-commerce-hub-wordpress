<?php
/**
 * Attribution capture on order creation (docs/ARCHITECTURE.md §2.5, §3.4).
 *
 * Meta `_adc_attribution` (JSON) holds ONLY the fields the consent snapshot
 * permits: marketing ⇒ fbp, fbc, ttp, ttclid, gclid, client_ip, user_agent;
 * statistics ⇒ ga_client_id, ga_session_id; plus source_url. Raw IP/UA are
 * the documented CAPI exception (marketing consent required) and are removed
 * by ADC_Retention after 30 days / on erasure.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Captures platform attribution at the moment the order is created.
 */
final class ADC_Order_Context implements ADC_Module_Interface
{
    /**
     * Order meta key holding the JSON attribution snapshot.
     */
    public const META_KEY = '_adc_attribution';

    /**
     * Attribution retention window (days, §6).
     */
    public const RETENTION_DAYS = 30;

    /**
     * Fields allowed per consent category (fixed contract).
     *
     * @var array<string, string[]>
     */
    private const FIELDS_BY_CATEGORY = array(
        'marketing'  => array('fbp', 'fbc', 'ttp', 'ttclid', 'gclid', 'client_ip', 'user_agent'),
        'statistics' => array('ga_client_id', 'ga_session_id'),
    );

    /**
     * Boot the module: checkout (classic + blocks) capture hooks and the
     * per-order erasure schedule handled by ADC_Retention.
     */
    public static function boot(): void
    {
        // After ADC_Consent::snapshot_to_order (prio 20): the snapshot decides what may be stored.
        add_action('woocommerce_checkout_create_order', array(self::class, 'capture'), 30, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array(self::class, 'capture_from_store_api'), 30, 1);
    }

    /**
     * Capture attribution for a not-yet-saved order (classic checkout);
     * meta is written with the order.
     *
     * @param WC_Order $order Order being created.
     * @param mixed    $data  Posted checkout data (unused).
     */
    public static function capture($order, $data = null): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $allowed = self::allowed_fields($order);
        if (array() === $allowed) {
            return;
        }

        $attr = self::collect($allowed);

        if (array() === $attr) {
            return;
        }

        $order->update_meta_data(self::META_KEY, (string) wp_json_encode($attr));

        // The 30-day retention eraser is scheduled by ADC_Retention on
        // `woocommerce_new_order` (order id exists only after save).
    }

    /**
     * Store API / blocks counterpart (order already persisted).
     *
     * @param WC_Order $order Processed order.
     */
    public static function capture_from_store_api($order): void
    {
        if (!$order instanceof WC_Order || '' !== (string) $order->get_meta(self::META_KEY)) {
            return; // Nothing captured or already captured via classic hooks.
        }

        self::capture($order);

        // Inside create_order the order saves its meta; here we persist explicitly.
        if ('' !== (string) $order->get_meta(self::META_KEY)) {
            $order->save_meta_data();
        }    }

    /**
     * Read the stored attribution (whitelisted string fields only).
     *
     * @param WC_Order $order Order.
     * @return array<string, string>
     */
    public static function read(WC_Order $order): array
    {
        $raw = $order->get_meta(self::META_KEY);
        if (!is_string($raw) || '' === $raw) {
            return array();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }

        $allowed = array_merge(
            ...array_merge(array_values(self::FIELDS_BY_CATEGORY), array(array('source_url')))
        );
        $out     = array();
        foreach ($allowed as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                $out[$key] = (string) $decoded[$key];
            }
        }

        return $out;
    }

    /**
     * Fields the order's consent snapshot permits.
     *
     * @param WC_Order $order Order.
     * @return string[]
     */
    private static function allowed_fields(WC_Order $order): array
    {
        $snapshot = $order->get_meta('_adc_consent_snapshot');
        $state    = null;

        $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
        if (is_array($decoded) && class_exists('ADC_Consent_State')) {
            $state = new ADC_Consent_State(
                !empty($decoded['preferences']),
                !empty($decoded['statistics']),
                !empty($decoded['marketing']),
                !empty($decoded['decided']),
                (int) ($decoded['ts'] ?? 0),
                (int) ($decoded['policy'] ?? 0),
                (string) ($decoded['cid'] ?? ''),
                (string) ($decoded['source'] ?? '')
            );
        }

        // Without a snapshot or the consent engine, store nothing (safe default).
        if (null === $state) {
            return array();
        }

        $allowed = array();
        foreach (self::FIELDS_BY_CATEGORY as $category => $fields) {
            if ($state->allows($category)) {
                $allowed = array_merge($allowed, $fields);
            }
        }

        return $allowed;
    }

    /**
     * Collect the permitted values from cookies / request / referer.
     *
     * @param string[] $allowed Permitted field keys.
     * @return array<string, string>
     */
    private static function collect(array $allowed): array
    {
        $out = array();

        foreach ($allowed as $field) {
            $value = self::value_for($field);
            if ('' !== $value) {
                $out[$field] = $value;
            }
        }

        // source_url is always stored (not PII; the consent rule scopes identity fields only).
        $url = self::source_url();
        if ('' !== $url) {
            $out['source_url'] = $url;
        }

        // fbc synthesis from fbclid (PLATFORMS §2.5) only when marketing
        // consent allowed its capture and the cookie is absent (§9.2).
        if (in_array('fbc', $allowed, true) && !isset($out['fbc'])) {
            $fbc = self::synthesize_fbc();
            if ('' !== $fbc) {
                $out['fbc'] = $fbc;
            }
        }

        return $out;
    }

    /**
     * Single value for one field key.
     *
     * @param string $field Field key.
     * @return string
     */
    private static function value_for(string $field): string
    {
        switch ($field) {
            case 'fbp':
                return self::cookie('_fbp');
            case 'fbc':
                return self::cookie('_fbc');
            case 'ttp':
                return self::cookie('_ttp');
            case 'ttclid':
                return self::request_param('ttclid');
            case 'gclid':
                return self::request_param('gclid');
            case 'ga_client_id':
                return self::ga_client_id();
            case 'ga_session_id':
                return self::request_param('ga_session_id');
            case 'client_ip':
                return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            case 'user_agent':
                return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        }

        return '';
    }

    /**
     * Cookie value (unslashed, trimmed, capped).
     *
     * @param string $name Cookie name.
     * @return string
     */
    private static function cookie(string $name): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value format-checked below.
        $raw = isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? wp_unslash($_COOKIE[$name]) : '';
        $raw = trim($raw);

        return 1 === preg_match('/^[\w.\-=]{1,200}$/', $raw) ? $raw : '';
    }

    /**
     * Query/request param (GET or POST), capped to 200 chars.
     *
     * @param string $name Param name.
     * @return string
     */
    private static function request_param(string $name): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- passive attribution read.
        $raw = isset($_REQUEST[$name]) && is_string($_REQUEST[$name]) ? sanitize_text_field(wp_unslash($_REQUEST[$name])) : '';

        return substr($raw, 0, 200);
    }

    /**
     * GA4 client id parsed from the `_ga` cookie (GA1.x.<client_id>).
     *
     * @return string
     */
    private static function ga_client_id(): string
    {
        $ga = self::cookie('_ga');
        if ('' === $ga) {
            return '';
        }

        $parts = explode('.', $ga);
        if (count($parts) >= 4) {
            $candidate = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];

            return 1 === preg_match('/^\d{6,12}\.\d{6,12}$/', $candidate) ? $candidate : '';
        }

        return '';
    }

    /**
     * Synthesize `fb.1.<ts>.<fbclid>` from the fbclid URL parameter.
     *
     * @return string
     */
    private static function synthesize_fbc(): string
    {
        $fbclid = self::request_param('fbclid');
        if ('' === $fbclid || !preg_match('/^[A-Za-z0-9_\-]{8,200}$/', $fbclid)) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- passive attribution read.
        $sub_index = isset($_COOKIE['fb_cs']) ? '2' : '1';

        return 'fb.' . $sub_index . '.' . (string) round(microtime(true) * 1000) . '.' . $fbclid;
    }

    /**
     * source_url: referer or current request URL (capped).
     *
     * @return string
     */
    private static function source_url(): string
    {
        $referer = wp_get_referer();
        $url     = '' !== $referer ? $referer : home_url((string) ($_SERVER['REQUEST_URI'] ?? ''));

        return substr(esc_url_raw($url), 0, 500);
    }
}
