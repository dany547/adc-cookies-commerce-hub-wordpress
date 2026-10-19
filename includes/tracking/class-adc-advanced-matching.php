<?php
/**
 * Advanced matching: normalisation, hashing and session identity
 * (docs/ARCHITECTURE.md §2.5, §3.3; PLATFORMS §2.4 normalisation, §9.1 D5).
 *
 * Raw PII exists only inside the request that supplied it. The WC session
 * holds hashes only. Every capture point is a no-op unless the marketing
 * cookie category is granted.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Identity normalisation + hashing + earliest-possible capture.
 */
final class ADC_Advanced_Matching implements ADC_Module_Interface
{
    /**
     * WC session key for the hashed identity ('adc_identity').
     */
    public const SESSION_KEY = 'adc_identity';

    /**
     * ISO-2 country → calling code map (compact; covers the common store
     * markets plus the official verification vectors). Default fallback is
     * the `matching.calling_code` setting (RO '40').
     *
     * @var array<string, string>
     */
    private const CALLING_CODES = array(
        'ro' => '40', 'de' => '49', 'at' => '43', 'ch' => '41', 'fr' => '33',
        'it' => '39', 'es' => '34', 'pt' => '351', 'nl' => '31', 'be' => '32',
        'bg' => '359', 'hu' => '36', 'pl' => '48', 'cz' => '420', 'sk' => '421',
        'si' => '386', 'hr' => '385', 'rs' => '381', 'gr' => '30', 'ie' => '353',
        'dk' => '45', 'se' => '46', 'no' => '47', 'fi' => '358', 'ee' => '372',
        'lv' => '371', 'lt' => '370', 'uk' => '44', 'gb' => '44', 'us' => '1',
        'ca' => '1', 'au' => '61', 'nz' => '64', 'tr' => '90', 'ua' => '380',
        'md' => '373', 'ru' => '7', 'il' => '972', 'ae' => '971', 'in' => '91',
        'cn' => '86', 'jp' => '81', 'kr' => '82', 'br' => '55', 'mx' => '52',
        'ar' => '54', 'cl' => '56', 'za' => '27', 'hu' => '36',
    );

    /**
     * Cache of the settings group (registered on adc_register_settings).
     *
     * @var bool
     */
    private static bool $hooks_done = false;

    /**
     * Boot the module: settings group, capture hooks, assets, frontend config.
     */
    public static function boot(): void
    {
        if (self::$hooks_done) {
            return; // Idempotent.
        }
        self::$hooks_done = true;

        add_action('adc_register_settings', array(self::class, 'register_settings'));

        // Earliest-possible server-side capture, JS-free (§3.3 A).
        add_action('woocommerce_checkout_update_order_review', array(self::class, 'capture_from_order_review'), 10, 1);
        add_action('woocommerce_created_customer', array(self::class, 'capture_from_new_customer'), 10, 1);
        add_action('wp_login', array(self::class, 'capture_from_login'), 10, 2);

        // Withdrawal clears the session identity on the next request (§3.5 step 8).
        add_action('adc_consent_withdrawn', array(self::class, 'clear_session_identity'), 10, 2);

        // Front-end config keys owned by C5 + identify.js enqueue.
        add_filter('adc_frontend_config', array(self::class, 'frontend_config'));
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue_assets'));
    }

    /**
     * Register the `matching` settings group (§4; datalayer_format per D21).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(
            'matching',
            array(
                'enabled'          => false,
                'require'          => 'marketing',
                'capture_checkout' => true,
                'capture_account'  => true,
                'form_selectors'   => '',
                'calling_code'     => '40',
                'send_to'          => array('meta', 'tiktok', 'ga4'),
                'use_external_id'  => true,
                'datalayer_format' => 'hashed',
            ),
            array(self::class, 'sanitize_settings'),
            array()
        );
    }

    /**
     * Sanitizer: fn(array $input, array $current): array (complete, typed).
     *
     * @param array $input   Raw input.
     * @param array $current Current stored values.
     * @return array
     */
    public static function sanitize_settings(array $input, array $current): array
    {
        $out = $current;

        foreach (array('enabled', 'capture_checkout', 'capture_account', 'use_external_id') as $flag) {
            $out[$flag] = !empty($input[$flag]);
        }

        $calling = preg_replace('/[^0-9]/', '', (string) ($input['calling_code'] ?? ''));
        $out['calling_code'] = substr($calling ?? '', 0, 5);

        $out['form_selectors'] = self::sanitize_form_selectors((string) ($input['form_selectors'] ?? ''));

        $send_to   = $input['send_to'] ?? array();
        $send_to   = is_array($send_to) ? $send_to : array();
        $out['send_to'] = array_values(array_intersect(
            array_map('sanitize_key', $send_to),
            array('meta', 'tiktok', 'ga4')
        ));

        $out['require']          = 'marketing'; // Fixed per §4.
        $out['datalayer_format'] = 'plain' === ($input['datalayer_format'] ?? '') ? 'plain' : 'hashed';

        return $out;
    }

    /**
     * One CSS selector per line; sanitise each, keep at most 20 lines.
     *
     * @param string $raw Raw textarea value.
     * @return string Sanitised, newline-separated.
     */
    private static function sanitize_form_selectors(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: array();
        $clean = array();

        foreach ($lines as $line) {
            $line = trim(sanitize_text_field($line));
            if ('' === $line || strlen($line) > 200) {
                continue;
            }
            // CSS selector characters only (letters, digits, spaces, # . : > ~ + [ ] = " ' _ - * ,).
            if (!preg_match('/^[A-Za-z0-9\s#\.\[:\]>~\+,"\'=_\-*%,]+$/', $line)) {
                continue;
            }
            $clean[] = $line;
            if (count($clean) >= 20) {
                break;
            }
        }

        return implode("\n", $clean);
    }

    /**
     * Front-end config keys owned by C5: rest, token, matching (§2.3).
     *
     * @param array $config Base config.
     * @return array
     */
    public static function frontend_config(array $config): array
    {
        $selectors = array_filter(array_map('trim', explode("\n", (string) ADC_Settings::get('matching', 'form_selectors', ''))));

        $config['rest']  = esc_url_raw(rest_url('adc/v1/'));
        $config['token'] = self::weekly_token();
        $config['matching'] = array(
            'enabled'         => self::enabled(),
            'require'         => 'marketing',
            'callingCode'     => (string) ADC_Settings::get('matching', 'calling_code', '40'),
            'selectors'       => array_values($selectors),
            'datalayerFormat' => ADC_Settings::get('matching', 'datalayer_format', 'hashed'),
        );

        return $config;
    }

    /**
     * Weekly HMAC token (current week), mirrored by ADC_Tracking_Rest validation.
     *
     * @return string
     */
    public static function weekly_token(): string
    {
        return hash_hmac('sha256', (string) floor(time() / WEEK_IN_SECONDS), wp_salt('nonce'));
    }

    /**
     * Enqueue identify.js when advanced matching is enabled and tracking
     * is not killed. Config attaches to this handle so the module is
     * self-contained even when the dataLayer module is off.
     */
    public static function enqueue_assets(): void
    {
        if (!self::enabled() || (defined('ADC_MARKETING_DISABLE_TRACKING') && ADC_MARKETING_DISABLE_TRACKING)) {
            return;
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $url     = defined('ADC_MARKETING_PLUGIN_URL')
            ? rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/js/identify.js'
            : '';

        if ('' === $url) {
            return;
        }

        wp_enqueue_script('adc-identify', $url, array(), $version, array('in_footer' => true, 'strategy' => 'defer'));
        ADC_Frontend_Config::attach('adc-identify');
    }

    /**
     * Whether advanced matching is on (the consent requirement is enforced
     * separately at every capture/send point).
     */
    public static function enabled(): bool
    {
        return (bool) ADC_Settings::get('matching', 'enabled', false);
    }

    /* ---------------------------------------------------------------------
     * Normalisation + hashing (PLATFORMS §2.4; D5 platform variants).
     * ------------------------------------------------------------------- */

    /**
     * Normalise one field. Platform variants (D5): meta → ph digits without
     * '+', em trim+lowercase; tiktok → ph '+E.164'; ga4 → ph '+E.164' plus
     * dot-stripped gmail/googlemail local parts.
     *
     * @param string $field   em|ph|fn|ln|ct|st|zp|country.
     * @param string $value   Raw value.
     * @param string $country Default country (ISO-2 or calling code) for phones.
     * @param string $platform meta|tiktok|ga4.
     * @return string Normalised value ('' when nothing valid).
     */
    public static function normalize(string $field, string $value, string $country = '', string $platform = 'meta'): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }

        switch ($field) {
            case 'em':
                $email = mb_strtolower($value, 'UTF-8');
                if ('ga4' === $platform) {
                    $email = self::ga4_email($email);
                }

                return $email;

            case 'ph':
                return self::normalize_phone($value, $country, $platform);

            case 'fn':
            case 'ln':
            case 'ct':
                // Lowercase, keep UTF-8 letters (Romanian diacritics), drop punctuation/digits/whitespace.
                $no_symbols = (string) preg_replace('/[^\p{L}\s]/u', '', $value);

                return (string) preg_replace('/\s+/u', '', mb_strtolower($no_symbols, 'UTF-8'));

            case 'st':
            case 'country':
                $letters = (string) preg_replace('/[^A-Za-z]/', '', $value);

                return substr(mb_strtolower($letters, 'UTF-8'), 0, 2);

            case 'zp':
                return str_replace(' ', '', mb_strtolower($value, 'UTF-8'));
        }

        return '';
    }

    /**
     * GA4 e-mail rule: gmail.com/googlemail.com local parts lose dots and
     * everything after '+'.
     *
     * @param string $email Lowercased e-mail.
     * @return string
     */
    private static function ga4_email(string $email): string
    {
        $at = strpos($email, '@');
        if (false === $at) {
            return $email;
        }

        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if ('gmail.com' === $domain || 'googlemail.com' === $domain) {
            $plus  = strpos($local, '+');
            $local = false === $plus ? $local : substr($local, 0, $plus);

            return str_replace('.', '', $local) . '@' . $domain;
        }

        return $email;
    }

    /**
     * Phone → E.164 digits. meta: no '+'; tiktok/ga4: '+' prefix.
     * Resolution order: explicit '+', '00' prefix, existing country code,
     * national number → country code (from $country or matching.calling_code)
     * + number without the leading trunk zero.
     *
     * @param string $value    Raw phone.
     * @param string $country  ISO-2 or calling code used when the number is national.
     * @param string $platform meta|tiktok|ga4.
     * @return string
     */
    private static function normalize_phone(string $value, string $country, string $platform): string
    {
        $explicit_plus = str_starts_with($value, '+');
        $digits        = (string) preg_replace('/\D+/', '', $value);

        if ('' === $digits) {
            return '';
        }

        if ($explicit_plus) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = substr($digits, 2);
        } else {
            $code = self::calling_code($country);
            if ('' !== $code && str_starts_with($digits, $code) && strlen($digits) > strlen($code)) {
                $e164 = $digits; // Already international.
            } else {
                $e164 = $code . ltrim($digits, '0');
            }
        }

        return ('meta' === $platform) ? $e164 : '+' . $e164;
    }

    /**
     * Calling code from an ISO-2 country, a numeric code, or the setting.
     *
     * @param string $country ISO-2 / numeric code ('' → setting).
     * @return string Digits ('' when unknown).
     */
    private static function calling_code(string $country): string
    {
        $country = strtolower(trim($country));
        if ('' !== $country && isset(self::CALLING_CODES[$country])) {
            return self::CALLING_CODES[$country];
        }

        if (preg_match('/^[0-9]{1,4}$/', $country)) {
            return $country;
        }

        return (string) ADC_Settings::get('matching', 'calling_code', '40');
    }

    /**
     * SHA-256 lowercase hex of a normalised value; '' for ''.
     *
     * @param string $normalized Normalised value.
     * @return string
     */
    public static function hash(string $normalized): string
    {
        return '' === $normalized ? '' : hash('sha256', $normalized);
    }

    /* ---------------------------------------------------------------------
     * Builders (raw → hashed; raw never leaves these calls).
     * ------------------------------------------------------------------- */

    /**
     * Raw field array → hashed ADC_User_Data.
     *
     * @param array  $raw     em, ph, fn, ln, ct, st, zp, country, external_id (raw).
     * @param string $country Default country for phone normalisation.
     * @return ADC_User_Data
     */
    public static function from_array(array $raw, string $country = ''): ADC_User_Data
    {
        $em = self::hash(self::normalize('em', (string) ($raw['em'] ?? ($raw['email'] ?? '')), '', 'meta'));
        $ph = self::hash(self::normalize('ph', (string) ($raw['ph'] ?? ($raw['phone'] ?? '')), $country, 'meta'));

        return new ADC_User_Data(
            '' !== $em ? $em : null,
            '' !== $ph ? $ph : null,
            self::maybe_hash_field('fn', (string) ($raw['fn'] ?? ($raw['first_name'] ?? ''))),
            self::maybe_hash_field('ln', (string) ($raw['ln'] ?? ($raw['last_name'] ?? ''))),
            self::maybe_hash_field('ct', (string) ($raw['ct'] ?? ($raw['city'] ?? ''))),
            self::maybe_hash_field('st', (string) ($raw['st'] ?? ($raw['state'] ?? ''))),
            self::maybe_hash_field('zp', (string) ($raw['zp'] ?? ($raw['postcode'] ?? ''))),
            self::maybe_hash_field('country', (string) ($raw['country'] ?? $country)),
            self::maybe_hash_external((string) ($raw['external_id'] ?? ''))
        );
    }

    /**
     * Hash a single simple field when non-empty.
     *
     * @param string $field Field key.
     * @param string $value Raw value.
     * @return string|null
     */
    private static function maybe_hash_field(string $field, string $value): ?string
    {
        $hash = self::hash(self::normalize($field, $value));

        return '' === $hash ? null : $hash;
    }

    /**
     * External id: SHA-256 of the raw value (TikTok vector: 'user_123').
     *
     * @param string $raw Raw external id.
     * @return string|null
     */
    private static function maybe_hash_external(string $raw): ?string
    {
        if (!self::use_external_id()) {
            return null;
        }

        $hash = self::hash(trim($raw));

        return '' === $hash ? null : $hash;
    }

    /**
     * Whether external_id computation is enabled.
     */
    private static function use_external_id(): bool
    {
        return (bool) ADC_Settings::get('matching', 'use_external_id', true);
    }

    /**
     * External id from a user id or a consent cid (D22): sha256(user_id or cid).
     *
     * @param int    $user_id 0 for guests.
     * @param string $cid     Consent id fallback for guests.
     * @return string|null
     */
    private static function external_id(int $user_id, string $cid = ''): ?string
    {
        if (!self::use_external_id()) {
            return null;
        }

        $raw = $user_id > 0 ? (string) $user_id : $cid;

        return '' === $raw ? null : self::hash($raw);
    }

    /**
     * Hashed identity from a WooCommerce order (billing data + external id).
     *
     * @param WC_Order $order Order object (HPOS-safe CRUD).
     * @return ADC_User_Data
     */
    public static function from_order(WC_Order $order): ADC_User_Data
    {
        $data = self::from_array(
            array(
                'em'          => (string) $order->get_billing_email(),
                'ph'          => (string) $order->get_billing_phone(),
                'fn'          => (string) $order->get_billing_first_name(),
                'ln'          => (string) $order->get_billing_last_name(),
                'ct'          => (string) $order->get_billing_city(),
                'st'          => (string) $order->get_billing_state(),
                'zp'          => (string) $order->get_billing_postcode(),
                'country'     => (string) $order->get_billing_country(),
            ),
            (string) $order->get_billing_country()
        );

        $cid = (string) $order->get_meta('_adc_consent_cid');

        return $data->merge(new ADC_User_Data(external_id: self::external_id((int) $order->get_customer_id(), $cid)));
    }

    /**
     * Hashed identity from a WP user record.
     *
     * @param int $user_id User id.
     * @return ADC_User_Data
     */
    public static function from_user(int $user_id): ADC_User_Data
    {
        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return new ADC_User_Data();
        }

        $country = (string) get_user_meta($user_id, 'billing_country', true);

        $data = self::from_array(
            array(
                'em'      => (string) ($user->user_email ?? ''),
                'ph'      => (string) get_user_meta($user_id, 'billing_phone', true),
                'fn'      => (string) get_user_meta($user_id, 'billing_first_name', true),
                'ln'      => (string) get_user_meta($user_id, 'billing_last_name', true),
                'ct'      => (string) get_user_meta($user_id, 'billing_city', true),
                'st'      => (string) get_user_meta($user_id, 'billing_state', true),
                'zp'      => (string) get_user_meta($user_id, 'billing_postcode', true),
                'country' => $country,
            ),
            $country
        );

        return $data->merge(new ADC_User_Data(external_id: self::external_id($user_id)));
    }

    /* ---------------------------------------------------------------------
     * Session identity (hashes only).
     * ------------------------------------------------------------------- */

    /**
     * Hashed identity stored in the WC session, or null.
     *
     * @return ADC_User_Data|null
     */
    public static function from_session(): ?ADC_User_Data
    {
        $session = self::session();
        if (null === $session) {
            return null;
        }

        $stored = $session->get(self::SESSION_KEY);
        if (!is_array($stored)) {
            return null;
        }

        $data = ADC_User_Data::from_array($stored);

        return $data->is_empty() ? null : $data;
    }

    /**
     * Merge hashed identity into the WC session (creates the customer
     * session cookie lazily). No raw values ever reach the session.
     *
     * @param ADC_User_Data $u Hashed identity.
     */
    public static function remember(ADC_User_Data $u): void
    {
        if ($u->is_empty()) {
            return;
        }

        $session = self::session();
        if (null === $session) {
            return;
        }

        $stored = $session->get(self::SESSION_KEY);
        $stored = is_array($stored) ? $stored : array();

        $merged = ADC_User_Data::from_array(array_merge($stored, $u->to_array()));
        if ($merged->is_empty()) {
            return;
        }

        $session->set(self::SESSION_KEY, $merged->to_array());

        if (method_exists($session, 'set_customer_session_cookie') && !headers_sent()) {
            $session->set_customer_session_cookie(true);
        }
    }

    /**
     * Append the consent cid as external_id when the identity has none
     * (guests keep one stable id per browser via the consent cookie).
     *
     * @param ADC_User_Data $u   Hashed identity.
     * @param string        $cid Consent id.
     * @return ADC_User_Data
     */
    public static function with_cid_external_id(ADC_User_Data $u, string $cid): ADC_User_Data
    {
        if (null !== $u->external_id || '' === $cid || !self::use_external_id()) {
            return $u;
        }

        return $u->merge(new ADC_User_Data(external_id: self::hash($cid)));
    }

    /**
     * WC session or null (CLI/cron/early requests).
     *
     * @return WC_Session|null
     */
    private static function session(): ?WC_Session
    {
        if (!function_exists('WC')) {
            return null;
        }

        $session = WC()->session ?? null;

        return ($session instanceof WC_Session) ? $session : null;
    }

    /* ---------------------------------------------------------------------
     * Capture hooks (all no-op unless marketing consent is granted).
     * ------------------------------------------------------------------- */

    /**
     * `woocommerce_checkout_update_order_review`: parse post_data on every
     * checkout field refresh (§3.3 A — server side, JS-free).
     *
     * @param string $posted_data Query-string style field data.
     */
    public static function capture_from_order_review($posted_data): void
    {
        if (!self::capture_allowed('capture_checkout')) {
            return;
        }

        $fields = array();
        parse_str((string) $posted_data, $fields);

        $raw = array(
            'em'      => (string) ($fields['billing_email'] ?? ''),
            'ph'      => (string) ($fields['billing_phone'] ?? ''),
            'fn'      => (string) ($fields['billing_first_name'] ?? ''),
            'ln'      => (string) ($fields['billing_last_name'] ?? ''),
            'ct'      => (string) ($fields['billing_city'] ?? ''),
            'st'      => (string) ($fields['billing_state'] ?? ''),
            'zp'      => (string) ($fields['billing_postcode'] ?? ''),
            'country' => (string) ($fields['billing_country'] ?? ''),
        );

        if ('' === $raw['em'] && '' === $raw['ph']) {
            return; // Nothing usable typed yet.
        }

        self::remember(self::from_array($raw, $raw['country']));
    }

    /**
     * `woocommerce_created_customer`: capture right after account creation.
     *
     * @param int $customer_id New customer id.
     */
    public static function capture_from_new_customer($customer_id): void
    {
        if (!self::capture_allowed('capture_account')) {
            return;
        }

        self::remember(self::from_user((int) $customer_id));
    }

    /**
     * `wp_login`: capture on login (marketing consent still required).
     *
     * @param string $user_login Login name.
     * @param WP_User $user      User object.
     */
    public static function capture_from_login($user_login, $user): void
    {
        if (!self::capture_allowed('capture_account')) {
            return;
        }

        $id = ($user instanceof WP_User) ? (int) $user->ID : 0;
        if ($id > 0) {
            self::remember(self::from_user($id));
        }
    }

    /**
     * Marketing consent gate shared by every capture point.
     *
     * @param string $setting capture_checkout|capture_account.
     * @return bool
     */
    private static function capture_allowed(string $setting): bool
    {
        if (!self::enabled() || !(bool) ADC_Settings::get('matching', $setting, true)) {
            return false;
        }

        return class_exists('ADC_Consent') && ADC_Consent::allows('marketing');
    }

    /**
     * `adc_consent_withdrawn`: drop the session identity when marketing is
     * revoked (effective from the next request, §3.5 step 8).
     *
     * @param ADC_Consent_State $new     New state.
     * @param string[]          $revoked Revoked categories.
     */
    public static function clear_session_identity($new, array $revoked = array()): void
    {
        if (array() !== $revoked && !in_array('marketing', $revoked, true)) {
            return; // Only a marketing withdrawal clears identity.
        }

        $session = self::session();
        if (null === $session) {
            return;
        }

        $session->set(self::SESSION_KEY, null);
    }
}
