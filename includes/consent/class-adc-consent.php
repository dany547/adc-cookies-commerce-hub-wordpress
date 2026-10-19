<?php
/**
 * Consent engine facade — single source of truth for the cookie consent state
 * (docs/ARCHITECTURE.md §0, §2.2, §3.5; D13, D16, D17).
 *
 * One state, one writer, many readers: the banner/modal/REST record decisions
 * here; the dispatcher re-checks here at SEND time (effective()). Channel
 * consents (_adc_marketing_*) are a different store and never mixed in.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public API of the consent engine.
 */
final class ADC_Consent implements ADC_Module_Interface
{
    /**
     * First-party consent record cookie name.
     */
    public const COOKIE_NAME = 'adc_consent';

    /**
     * Static boot guard (idempotency, ADC_Module_Interface).
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Register every hook of the consent engine (idempotent).
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Settings group 'consent' (§4) — registered on the C1 action.
        add_action('adc_register_settings', array(self::class, 'register_settings'));

        // Publish the `consent` block of window.adcConfig (C2-owned keys, §2.3).
        add_filter('adc_frontend_config', array(self::class, 'frontend_config'));

        // REST routes /adc/v1/consent.
        add_action('rest_api_init', array(ADC_Consent_Rest::class, 'register_routes'));

        // Order snapshot (classic + Store API/blocks), see §3.4.
        add_action('woocommerce_checkout_create_order', array(self::class, 'snapshot_to_order'), 20);
        add_action('woocommerce_store_api_checkout_order_processed', array(self::class, 'snapshot_via_store_api'));

        // Cookie <-> user-meta hydration on login (newest ts wins).
        add_action('wp_login', array(self::class, 'hydrate_on_login'), 10, 2);

        // WP privacy exporter/eraser + delete_user anonymisation + retention cron.
        ADC_Consent_Privacy::boot();

        // WP Consent API bridge (D17): declare compliance; opt-in type only
        // while our CMP is enabled (the API returns true for everything when
        // no CMP sets a type).
        if (function_exists('wp_has_consent')) {
            add_filter('wp_consent_api_registered_' . ADC_MARKETING_PLUGIN_BASENAME, '__return_true');
            add_filter(
                'wp_get_consent_type',
                static function (mixed $type): mixed {
                    return self::enabled() ? 'optin' : $type;
                }
            );
        }
    }

    /**
     * Current request state: cookie -> (logged-in) user meta if newer ->
     * denied(). Stale policy version or expired decision (cookie_days, D16)
     * counts as undecided. Never creates a cid.
     *
     * @return ADC_Consent_State
     */
    public static function current(): ADC_Consent_State
    {
        $policy = self::policy_version();
        $now    = time();
        $days   = ADC_Consent_Mode::cookie_days();

        $candidates = array(
            self::state_from_request_cookie(),
            is_user_logged_in() ? self::state_from_user_meta(get_current_user_id()) : null,
        );

        $best = null;
        foreach ($candidates as $candidate) {
            if (null === $candidate || !$candidate->decided) {
                continue;
            }
            if ($candidate->policy < $policy) {
                continue; // Policy text changed since the decision => re-prompt.
            }
            if ($candidate->ts <= 0 || ($candidate->ts + $days * 86400) < $now) {
                continue; // Expired choice (D16, never auto-renewed).
            }
            if (null === $best || $candidate->ts > $best->ts) {
                $best = $candidate;
            }
        }

        return $best ?? ADC_Consent_State::denied();
    }

    /**
     * Gate a category against the current (or a provided) state.
     *
     * @param string                $category necessary|preferences|statistics|marketing.
     * @param ADC_Consent_State|null $state   State to test; null = current().
     * @return bool
     */
    public static function allows(string $category, ?ADC_Consent_State $state = null): bool
    {
        return ($state ?? self::current())->allows($category);
    }

    /**
     * Current policy version (`consent.policy_version`).
     *
     * @return int
     */
    public static function policy_version(): int
    {
        return max(1, (int) ADC_Settings::get('consent', 'policy_version', 1));
    }

    /**
     * Whether the consent manager (banner/CMP) is switched on.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool) ADC_Settings::get('consent', 'enabled', false);
    }

    /**
     * Whether the Global Privacy Control signal is honoured (§2.2).
     *
     * @return bool
     */
    public static function honor_gpc(): bool
    {
        return (bool) ADC_Settings::get('consent', 'honor_gpc', true);
    }

    /**
     * Publish the C2-owned `consent` block of window.adcConfig (§2.3):
     * cookie name, lifetime, policy version, GPC flag, optional cookie
     * domain, Consent Mode category mapping and the cookie inventory.
     * Cache-safe: settings only, never per-visitor data.
     *
     * @param array $config Base configuration.
     * @return array
     */
    public static function frontend_config(array $config): array
    {
        $config['consent'] = array(
            'cookie'    => self::COOKIE_NAME,
            'days'      => ADC_Consent_Mode::cookie_days(),
            'policy'    => self::policy_version(),
            'gpc'       => self::honor_gpc(),
            'domain'    => (string) ADC_Settings::get('consent', 'cookie_domain', ''),
            'gcm'       => ADC_Consent_Mode::map(),
            'inventory' => ADC_Consent_Banner::inventory(),
        );

        return $config;
    }

    /**
     * Persist a decision: validate -> log row -> user meta (logged in) ->
     * Set-Cookie (if headers not sent) -> fire actions (§2.2, §3.5).
     *
     * @param array $categories ['preferences'=>bool,'statistics'=>bool,'marketing'=>bool].
     * @param string $source    banner|modal|api|gpc|account|checkout.
     * @param array  $ctx       Optional: cid, policy, user_id, order_id, text_hash,
     *                          gpc (bool), ip, ua, action (grant|update|withdraw|reset|policy).
     * @return ADC_Consent_State The recorded state (decided=true, fresh cid when none).
     */
    public static function record(array $categories, string $source, array $ctx = array()): ADC_Consent_State
    {
        if (!in_array($source, ADC_Consent_State::SOURCES, true)) {
            $source = 'api';
        }

        $prior_cid = isset($ctx['cid']) && ADC_Consent_State::is_uuid((string) $ctx['cid'])
            ? (string) $ctx['cid']
            : '';
        if ('' === $prior_cid) {
            $cookie_state = self::state_from_request_cookie();
            $prior_cid    = null !== $cookie_state ? $cookie_state->cid : '';
        }

        $prev   = '' !== $prior_cid ? self::history_state($prior_cid) : null;
        $prev   = $prev ?? ADC_Consent_State::denied();
        $cid    = '' !== $prior_cid ? $prior_cid : (string) wp_generate_uuid4();
        $policy = isset($ctx['policy']) && is_numeric($ctx['policy'])
            ? max(1, (int) $ctx['policy'])
            : self::policy_version();

        $state = new ADC_Consent_State(
            !empty($categories['preferences']),
            !empty($categories['statistics']),
            !empty($categories['marketing']),
            true,
            time(),
            $policy,
            $cid,
            $source,
        );

        $revoked = array();
        foreach (array('preferences', 'statistics', 'marketing') as $category) {
            if ($prev->allows($category) && !$state->allows($category)) {
                $revoked[] = $category;
            }
        }

        $action = self::resolve_action($state, $prev, $ctx);

        $user_id = isset($ctx['user_id']) && (int) $ctx['user_id'] > 0
            ? (int) $ctx['user_id']
            : get_current_user_id();

        $ip = isset($ctx['ip']) ? (string) $ctx['ip'] : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $ua = isset($ctx['ua']) ? (string) $ctx['ua'] : (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        ADC_Consent_Store::insert(
            array(
                'cid'              => $cid,
                'user_id'          => $user_id,
                'order_id'         => isset($ctx['order_id']) ? (int) $ctx['order_id'] : 0,
                'action'           => $action,
                'cat_preferences'  => $state->preferences ? 1 : 0,
                'cat_statistics'   => $state->statistics ? 1 : 0,
                'cat_marketing'    => $state->marketing ? 1 : 0,
                'policy_version'   => $state->policy,
                'text_hash'        => isset($ctx['text_hash']) ? (string) $ctx['text_hash'] : '',
                'source'           => $source,
                'gpc'              => !empty($ctx['gpc']) ? 1 : 0,
                'ip_hash'          => '' !== $ip ? hash_hmac('sha256', $ip, (string) wp_salt('auth')) : '',
                'ua_hash'          => '' !== $ua ? hash_hmac('sha256', $ua, (string) wp_salt('auth')) : '',
                'created_at'       => gmdate('Y-m-d H:i:s'),
            )
        );

        if ($user_id > 0) {
            update_user_meta($user_id, ADC_Consent_Privacy::USER_META, (string) wp_json_encode($state->to_array()));
        }

        self::emit_cookie($state);

        do_action('adc_consent_changed', $state, $prev->decided ? $prev : null, $source);

        if (!empty($revoked)) {
            do_action('adc_consent_withdrawn', $state, $revoked);
        }

        return $state;
    }

    /**
     * Consent Mode v2 signals for a state (§2.2 table).
     *
     * @param ADC_Consent_State|null $state State; null = defaults (all denied).
     * @return array<string, string>
     */
    public static function gcm_signals(?ADC_Consent_State $state = null): array
    {
        return ADC_Consent_Mode::signals($state ?? self::current());
    }

    /**
     * Snapshot the current state onto the order
     * (_adc_consent_snapshot JSON + _adc_consent_cid). No-op when already
     * set. Runs on woocommerce_checkout_create_order (prio 20; WC saves the
     * order right after) — HPOS-safe CRUD only.
     *
     * @param WC_Order $order Order being created.
     */
    public static function snapshot_to_order(WC_Order $order): void
    {
        if ('' !== (string) $order->get_meta('_adc_consent_snapshot', true)) {
            return; // Already snapshotted (idempotent).
        }

        $state = self::current();

        $order->update_meta_data('_adc_consent_snapshot', (string) wp_json_encode($state->to_array()));
        $order->update_meta_data('_adc_consent_cid', $state->cid);
    }

    /**
     * Store API / block checkout counterpart: the order is already saved when
     * `woocommerce_store_api_checkout_order_processed` fires, so this wrapper
     * persists the snapshot explicitly.
     *
     * @param mixed $order WC_Order (tolerates an order id from older WC).
     */
    public static function snapshot_via_store_api(mixed $order): void
    {
        if (is_numeric($order)) {
            $order = wc_get_order((int) $order);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        if ('' !== (string) $order->get_meta('_adc_consent_snapshot', true)) {
            return;
        }

        self::snapshot_to_order($order);
        $order->save();
    }

    /**
     * State snapshotted on an order, or null when none/invalid.
     *
     * @param int|WC_Order $order Order id or object.
     * @return ADC_Consent_State|null
     */
    public static function for_order(int|WC_Order $order): ?ADC_Consent_State
    {
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order);
        }

        if (!$order instanceof WC_Order) {
            return null;
        }

        $json = (string) $order->get_meta('_adc_consent_snapshot', true);
        if ('' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? ADC_Consent_State::from_array($decoded) : null;
    }

    /**
     * Effective consent at SEND time (D13): snapshot AND latest-log — a
     * withdrawal after the order always wins, a later grant never upgrades a
     * purchase.
     *
     * @param string                $cid      Consent id of the visitor/order.
     * @param ADC_Consent_State|null $fallback Order/event snapshot.
     * @return ADC_Consent_State
     */
    public static function effective(string $cid, ?ADC_Consent_State $fallback = null): ADC_Consent_State
    {
        $latest = self::history_state($cid);

        if (null === $latest) {
            return $fallback ?? ADC_Consent_State::denied();
        }

        if (null === $fallback) {
            return $latest;
        }

        return new ADC_Consent_State(
            $fallback->preferences && $latest->preferences,
            $fallback->statistics && $latest->statistics,
            $fallback->marketing && $latest->marketing,
            $fallback->decided && $latest->decided,
            max($fallback->ts, $latest->ts),
            $latest->policy,
            '' !== $cid ? $cid : $latest->cid,
            $latest->source,
        );
    }

    /**
     * Cookie <-> user-meta hydration on wp_login: newest decided ts wins and
     * is written to both sides.
     *
     * @param int         $user_id Logging-in user id.
     * @param WP_User|null $user   User object (unused, hook signature).
     */
    public static function hydrate_on_login(int $user_id, ?WP_User $user = null): void
    {
        $days   = ADC_Consent_Mode::cookie_days();
        $policy = self::policy_version();
        $now    = time();

        $valid = static function (ADC_Consent_State $state) use ($days, $policy, $now): bool {
            return $state->decided
                && $state->policy >= $policy
                && $state->ts > 0
                && ($state->ts + $days * 86400) >= $now;
        };

        $cookie = self::state_from_request_cookie();
        $meta   = self::state_from_user_meta($user_id);

        $cookie_ok = ($cookie instanceof ADC_Consent_State) && $valid($cookie) ? $cookie : null;
        $meta_ok   = ($meta instanceof ADC_Consent_State) && $valid($meta) ? $meta : null;

        $winner = $cookie_ok;
        if (null === $winner || (null !== $meta_ok && $meta_ok->ts > $winner->ts)) {
            $winner = $meta_ok;
        }

        if (null === $winner) {
            return;
        }

        if (null === $meta_ok || $meta_ok->to_array() !== $winner->to_array()) {
            update_user_meta($user_id, ADC_Consent_Privacy::USER_META, (string) wp_json_encode($winner->to_array()));
        }

        if (null === $cookie_ok || $cookie_ok->to_array() !== $winner->to_array()) {
            self::emit_cookie($winner);
        }
    }

    /**
     * Register the `consent` settings group (§4) on `adc_register_settings`.
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'consent',
            self::settings_defaults(),
            array(self::class, 'sanitize_settings')
        );
    }

    /**
     * Defaults of the `consent` group (§4).
     *
     * @return array<string, mixed>
     */
    public static function settings_defaults(): array
    {
        return array(
            'enabled'                 => false,
            'policy_version'          => 1,
            'cookie_days'             => 180,
            'cookie_domain'           => '',
            'honor_gpc'               => true,
            'regional_defaults'       => array(),
            'wait_for_update'         => 500,
            'url_passthrough'         => true,
            'ads_data_redaction'      => true,
            'log_retention_months'    => 36,
            'delete_data_on_uninstall' => false,
        );
    }

    /**
     * Sanitize the `consent` group; always returns a complete, typed array.
     * `reask` (bool) is a one-shot admin checkbox ("Cere din nou
     * consimțământul") that bumps the policy version (§4).
     *
     * @param array $input   Raw input.
     * @param array $current Currently stored values.
     * @return array
     */
    public static function sanitize_settings(array $input, array $current): array
    {
        $defaults = self::settings_defaults();
        $merged   = array_merge($defaults, is_array($current) ? $current : array(), $input);

        $policy = max(1, (int) $merged['policy_version']);
        if (!empty($input['reask'])) {
            $policy = max(1, (int) (is_array($current) ? ($current['policy_version'] ?? 1) : 1)) + 1;
        }

        $domain = strtolower(preg_replace('/[^A-Za-z0-9.\-_]/', '', (string) $merged['cookie_domain']) ?? '');
        $domain = trim($domain, '.');

        return array(
            'enabled'                  => (bool) $merged['enabled'],
            'policy_version'           => $policy,
            'cookie_days'              => max(30, min(365, (int) $merged['cookie_days'])), // D16: clamp 30–365.
            'cookie_domain'            => substr($domain, 0, 191),
            'honor_gpc'                => (bool) $merged['honor_gpc'],
            'regional_defaults'        => ADC_Consent_Mode::sanitize_regional_defaults(
                is_array($merged['regional_defaults']) ? $merged['regional_defaults'] : array()
            ),
            'wait_for_update'          => max(0, min(5000, (int) $merged['wait_for_update'])),
            'url_passthrough'          => (bool) $merged['url_passthrough'],
            'ads_data_redaction'       => (bool) $merged['ads_data_redaction'],
            'log_retention_months'     => max(1, min(120, (int) $merged['log_retention_months'])),
            'delete_data_on_uninstall' => (bool) $merged['delete_data_on_uninstall'],
        );
    }

    /**
     * Parse the `adc_consent` cookie of the current request (null when
     * absent/malformed). Never creates a cid.
     *
     * @return ADC_Consent_State|null
     */
    private static function state_from_request_cookie(): ?ADC_Consent_State
    {
        if (!isset($_COOKIE[self::COOKIE_NAME]) || !is_string($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed by the strict codec below.
        return ADC_Consent_State::from_cookie_value(wp_unslash($_COOKIE[self::COOKIE_NAME]));
    }

    /**
     * Decode the `_adc_cookie_consent` user meta (null when absent/invalid).
     *
     * @param int $user_id User id.
     * @return ADC_Consent_State|null
     */
    private static function state_from_user_meta(int $user_id): ?ADC_Consent_State
    {
        $raw = get_user_meta($user_id, ADC_Consent_Privacy::USER_META, true);

        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? ADC_Consent_State::from_array($decoded) : null;
    }

    /**
     * Newest consent_log row for a cid as a state (send-time re-evaluation
     * and withdrawal detection; the log is append-only history).
     *
     * @param string $cid Consent id.
     * @return ADC_Consent_State|null
     */
    private static function history_state(string $cid): ?ADC_Consent_State
    {
        if (!ADC_Consent_State::is_uuid($cid)) {
            return null;
        }

        $row = ADC_Consent_Store::latest_for_cid($cid);
        if (null === $row) {
            return null;
        }

        $ts = isset($row['created_at']) ? strtotime($row['created_at'] . ' UTC') : false;

        return new ADC_Consent_State(
            !empty($row['cat_preferences']),
            !empty($row['cat_statistics']),
            !empty($row['cat_marketing']),
            true,
            false !== $ts ? (int) $ts : 0,
            max(1, (int) ($row['policy_version'] ?? 1)),
            (string) ($row['cid'] ?? $cid),
            (string) ($row['source'] ?? ''),
        );
    }

    /**
     * Classify the log action: ctx override wins; first decision = 'grant';
     * only revocations = 'withdraw'; anything else = 'update'.
     *
     * @param ADC_Consent_State $state New state.
     * @param ADC_Consent_State $prev  Previous state from the log history.
     * @param array             $ctx   Record context.
     * @return string grant|update|withdraw|reset|policy.
     */
    private static function resolve_action(ADC_Consent_State $state, ADC_Consent_State $prev, array $ctx): string
    {
        $override = isset($ctx['action']) ? (string) $ctx['action'] : '';
        if (in_array($override, array('grant', 'update', 'withdraw', 'reset', 'policy'), true)) {
            return $override;
        }

        if (!$prev->decided) {
            return 'grant';
        }

        $revoked = false;
        $granted = false;
        foreach (array('preferences', 'statistics', 'marketing') as $category) {
            $was = $prev->allows($category);
            $now = $state->allows($category);
            $revoked = $revoked || ($was && !$now);
            $granted = $granted || (!$was && $now);
        }

        return ($revoked && !$granted) ? 'withdraw' : 'update';
    }

    /**
     * Emit the `Set-Cookie` header for a decision (first-party; Path=/,
     * SameSite=Lax, Secure on https, Max-Age = cookie_days*86400; not
     * HttpOnly so the JS side can read it immediately). No-op when headers
     * are already sent (page cache).
     *
     * @param ADC_Consent_State $state State to persist.
     */
    private static function emit_cookie(ADC_Consent_State $state): void
    {
        if (headers_sent()) {
            return;
        }

        $days   = ADC_Consent_Mode::cookie_days();
        $domain = (string) ADC_Settings::get('consent', 'cookie_domain', '');

        $cookie = self::COOKIE_NAME . '=' . $state->to_cookie_value()
            . '; Path=/'
            . '; Max-Age=' . ($days * 86400)
            . '; SameSite=Lax';

        if ('' !== $domain) {
            $cookie .= '; Domain=' . $domain;
        }

        if (is_ssl()) {
            $cookie .= '; Secure';
        }

        header('Set-Cookie: ' . $cookie, false);
    }
}
