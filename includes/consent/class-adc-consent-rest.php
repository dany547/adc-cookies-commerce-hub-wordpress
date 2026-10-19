<?php
/**
 * Public REST endpoints of the consent engine (docs/ARCHITECTURE.md §2.2, §6).
 *
 * Namespace `adc/v1`, route `/consent`:
 *   GET  -> 200 {state} (hydration for logged-in cross-device; never renews the cookie, D16)
 *   POST -> 200 {state} + Set-Cookie adc_consent (persist a decision; logs + fires actions)
 *
 * Permission is deliberately `__return_true`: pages are cacheable, so WP
 * nonces would be stale by the time a visitor decides. Protection instead is:
 * strict arg validation, 4 KB body cap, per-IP rate limit (30 req/min) and the
 * light weekly HMAC token (X-ADC-Token, issued in adcConfig.token) which a
 * cross-origin attacker cannot read (same-origin policy) nor send (CORS
 * preflight). Every response is `Cache-Control: no-store`.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST transport of the consent engine.
 */
final class ADC_Consent_Rest
{
    /**
     * REST namespace.
     */
    public const NAMESPACE = 'adc/v1';

    /**
     * Maximum accepted request body size (bytes).
     */
    public const BODY_MAX_BYTES = 4096;

    /**
     * Rate-limit window (seconds) and request cap per window/IP/route.
     */
    public const RATE_WINDOW = 60;

    public const RATE_MAX = 30;

    /**
     * Token period (seconds): one week (floor(time()/604800)); the previous
     * period's token is also accepted to survive page-cache staleness.
     */
    public const TOKEN_PERIOD = 604800;

    /**
     * Register the /consent routes.
     */
    public static function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/consent',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array(self::class, 'handle_get'),
                    // Public by design — see the file header; no user data is disclosed
                    // (the response only mirrors the caller's own consent cookie).
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array(self::class, 'handle_post'),
                    // Public by design — see the file header; CSRF-proof via X-ADC-Token.
                    'permission_callback' => '__return_true',
                    'args'                => self::post_args(),
                ),
            )
        );
    }

    /**
     * GET /consent — current state (cookie -> newer user meta -> denied).
     * Deliberately does NOT set/refresh the cookie (no sliding expiry, D16).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = self::deny_token($request);
        if ($denied instanceof WP_Error) {
            return $denied;
        }

        $limited = self::rate_limit_error($request);
        if ($limited instanceof WP_Error) {
            return $limited;
        }

        $response = new WP_REST_Response(
            array('state' => ADC_Consent::current()->to_array()),
            200
        );
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * POST /consent — persist a decision. Body: {preferences,statistics,
     * marketing: 0|1, source, policy:int} (at least one category required).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_post(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = self::deny_token($request);
        if ($denied instanceof WP_Error) {
            return $denied;
        }

        $limited = self::rate_limit_error($request);
        if ($limited instanceof WP_Error) {
            return $limited;
        }

        if (strlen($request->get_body()) > self::BODY_MAX_BYTES) {
            return self::bad_request('adc_body_too_large');
        }

        $has_category = false;
        foreach (array('preferences', 'statistics', 'marketing') as $key) {
            if (null !== $request->get_param($key)) {
                $has_category = true;
                break;
            }
        }
        if (!$has_category) {
            return self::bad_request('adc_missing_categories');
        }

        $categories = array(
            'preferences' => 1 === (int) $request->get_param('preferences'),
            'statistics'  => 1 === (int) $request->get_param('statistics'),
            'marketing'   => 1 === (int) $request->get_param('marketing'),
        );

        $source = (string) ($request->get_param('source') ?? 'api');
        if (!in_array($source, ADC_Consent_State::SOURCES, true)) {
            $source = 'api';
        }

        // GPC (Sec-GPC: 1, §2.2): when honour_gpc is on, marketing can never be
        // granted by such a request. When the downgrade bites, the decision is
        // recorded with source `gpc` so the audit trail shows what happened.
        $gpc = self::gpc_from_request($request);
        if ($gpc && $categories['marketing']) {
            $categories['marketing'] = false;
            $source = 'gpc';
        }

        $policy  = $request->get_param('policy');
        $action  = (string) $request->get_param('action');

        $ctx = array(
            'gpc'    => $gpc,
            'ip'     => self::remote_ip(),
            'ua'     => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'policy' => is_numeric($policy) ? (int) $policy : ADC_Consent::policy_version(),
        );

        if (in_array($action, array('grant', 'update', 'withdraw', 'reset', 'policy'), true)) {
            $ctx['action'] = $action; // Explicit log action (L2: withdrawal must not rely on inference).
        }

        // D18 proof of consent: persist the hash of the banner texts shown to
        // this visitor (warms the append-only adc_consent_texts option).
        $ctx['text_hash'] = class_exists('ADC_Consent_Banner') ? ADC_Consent_Banner::current_text_hash() : '';

        $state = ADC_Consent::record($categories, $source, $ctx);

        $response = new WP_REST_Response(array('state' => $state->to_array()), 200);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Light HMAC token issued to adcConfig.token (§2.2).
     *
     * @return string
     */
    public static function token(): string
    {
        return hash_hmac('sha256', (string) floor(time() / self::TOKEN_PERIOD), (string) wp_salt('nonce'));
    }

    /**
     * Route argument definitions (strict validation per arg, §6).
     *
     * @return array
     */
    private static function post_args(): array
    {
        $flag = static function (mixed $value): bool {
            return in_array($value, array(0, 1, '0', '1', true, false), true);
        };

        return array(
            'preferences' => array(
                'required'         => false,
                'validate_callback' => $flag,
                'sanitize_callback' => static fn (mixed $value): int => (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0),
            ),
            'statistics'  => array(
                'required'         => false,
                'validate_callback' => $flag,
                'sanitize_callback' => static fn (mixed $value): int => (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0),
            ),
            'marketing'   => array(
                'required'         => false,
                'validate_callback' => $flag,
                'sanitize_callback' => static fn (mixed $value): int => (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0),
            ),
            'source'      => array(
                'required'          => false,
                'default'           => 'api',
                'validate_callback' => static fn (mixed $value): bool => is_string($value) && in_array($value, ADC_Consent_State::SOURCES, true),
                'sanitize_callback' => static fn (mixed $value): string => sanitize_key((string) $value),
            ),
            'policy'      => array(
                'required'          => false,
                'validate_callback' => static fn (mixed $value): bool => is_numeric($value) && (int) $value >= 0 && (int) $value <= 100000,
                'sanitize_callback' => static fn (mixed $value): int => (int) $value,
            ),
            'action'      => array(
                'required'          => false,
                'validate_callback' => static fn (mixed $value): bool => is_string($value) && in_array($value, array('grant', 'update', 'withdraw', 'reset', 'policy'), true),
                'sanitize_callback' => static fn (mixed $value): string => sanitize_key((string) $value),
            ),
        );
    }

    /**
     * Reject requests without a valid current/previous-period token.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_Error|null
     */
    private static function deny_token(WP_REST_Request $request): ?WP_Error
    {
        $token = (string) $request->get_header('x-adc-token');
        if ('' === $token) {
            return new WP_Error('adc_token_missing', 'Token lipsă.', array('status' => 403));
        }

        $period = (int) floor(time() / self::TOKEN_PERIOD);
        $valid  = array(
            hash_hmac('sha256', (string) $period, (string) wp_salt('nonce')),
            hash_hmac('sha256', (string) ($period - 1), (string) wp_salt('nonce')),
        );

        foreach ($valid as $candidate) {
            if (hash_equals($candidate, $token)) {
                return null;
            }
        }

        return new WP_Error('adc_token_invalid', 'Token nevalid.', array('status' => 403));
    }

    /**
     * Per-IP/per-route rate limit: transient adc_rl_{sha256(ip|route)},
     * 30 requests per minute (§2.2).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_Error|null
     */
    private static function rate_limit_error(WP_REST_Request $request): ?WP_Error
    {
        $ip    = self::remote_ip();
        $route = 'consent:' . $request->get_method();

        $key   = 'adc_rl_' . substr(hash('sha256', $ip . '|' . $route), 0, 32);
        $count = (int) get_transient($key);

        if ($count >= self::RATE_MAX) {
            return new WP_Error('adc_rate_limited', 'Prea multe cereri.', array('status' => 429));
        }

        set_transient($key, $count + 1, self::RATE_WINDOW);

        return null;
    }

    /**
     * GPC signal (Sec-GPC request header) when `consent.honor_gpc` is on (§2.2).
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    private static function gpc_from_request(WP_REST_Request $request): bool
    {
        if (!ADC_Consent::honor_gpc()) {
            return false;
        }

        $header = $request->get_header('sec-gpc');

        return null !== $header && in_array(trim((string) $header), array('1', 'true'), true);
    }

    /**
     * Client IP (REMOTE_ADDR only — never a spoofable forwarding header).
     *
     * @return string
     */
    private static function remote_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return is_string($ip) ? substr($ip, 0, 45) : '';
    }

    /**
     * Standardised 400 response.
     *
     * @param string $code Machine-readable error code.
     * @return WP_Error
     */
    private static function bad_request(string $code): WP_Error
    {
        return new WP_Error($code, 'Cerere nevalidă.', array('status' => 400));
    }
}
