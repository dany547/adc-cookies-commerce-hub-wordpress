<?php
/**
 * Custom event settings, frontend configuration, and server routing helpers.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Owns the custom_events settings group and frontend delivery. */
final class ADC_Custom_Events implements ADC_Module_Interface
{
    public const GROUP = 'custom_events';

    /** Whether hooks have already been registered. */
    private static bool $booted = false;

    /** Register module hooks exactly once. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('adc_register_settings', array(self::class, 'register_settings'));
        add_filter('adc_frontend_config', array(self::class, 'frontend_config'));
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue'), 25);
    }

    /** Register the settings group with the central settings repository. */
    public static function register_settings(): void
    {
        ADC_Settings::register_group(self::GROUP, self::defaults(), array(self::class, 'sanitize'));
    }

    /** Defaults for the versioned custom event settings group. */
    public static function defaults(): array
    {
        return array('schema_version' => ADC_Custom_Event_Rule::SCHEMA_VERSION, 'revision' => 0, 'rules' => array());
    }

    /**
     * Sanitize the group while preserving values for omitted keys.
     *
     * @param array<string, mixed> $input Incoming partial settings.
     * @param array<string, mixed> $current Current settings.
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, array $current): array
    {
        $stored = ADC_Custom_Event_Rule::migrate($current);
        if (!empty($stored['locked'])) {
            return $current;
        }
        $out = array_merge(self::defaults(), $stored);
        if (array_key_exists('schema_version', $input)) {
            $requested = (int) $input['schema_version'];
            if ($requested > ADC_Custom_Event_Rule::SCHEMA_VERSION) {
                return $current;
            }
            $out['schema_version'] = ADC_Custom_Event_Rule::SCHEMA_VERSION;
        }
        if (array_key_exists('revision', $input)) {
            $out['revision'] = max(0, (int) $input['revision']);
        }
        if (array_key_exists('rules', $input) && is_array($input['rules'])) {
            $rules = $input['rules'];
            $clean = array();
            $seen_ids = array();
            foreach (array_slice($rules, 0, ADC_Custom_Event_Rule::MAX_RULES) as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $raw_id = $raw['id'] ?? null;
                if (is_string($raw_id) && isset($seen_ids[$raw_id])) {
                    continue;
                }
                $result = ADC_Custom_Event_Rule::validate($raw, $clean);
                if (null === $result['rule']) {
                    continue;
                }
                $candidate = $result['rule'];
                $duplicate = false;
                foreach ($clean as $existing) {
                    if ($candidate['id'] === $existing['id'] || $candidate['name'] === $existing['name']) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) {
                    $clean[] = $candidate;
                    $seen_ids[$candidate['id']] = true;
                }
            }
            $out['rules'] = $clean;
        }
        return $out;
    }

    /** Read settings through ADC_Settings and normalize legacy storage. */
    public static function settings(): array
    {
        $raw = ADC_Settings::all(self::GROUP);
        $version = (int) ($raw['schema_version'] ?? ADC_Custom_Event_Rule::SCHEMA_VERSION);
        if ($version > ADC_Custom_Event_Rule::SCHEMA_VERSION) {
            return array(
                'schema_version' => $version,
                'revision' => max(0, (int) ($raw['revision'] ?? 0)),
                'rules' => array(),
                'locked' => true,
            );
        }
        return ADC_Custom_Event_Rule::migrate($raw);
    }

    /** @return list<array> Current normalized rules. */
    public static function rules(): array
    {
        $settings = self::settings();
        return !empty($settings['locked']) ? array() : array_values($settings['rules'] ?? array());
    }

    /** @return list<array> Enabled rules only. */
    public static function active_rules(): array
    {
        return array_values(array_filter(self::rules(), static fn(array $rule): bool => !empty($rule['enabled'])));
    }

    /** Find a rule by its opaque ID. */
    public static function rule(string $id): ?array
    {
        foreach (self::rules() as $rule) {
            if (($rule['id'] ?? '') === $id) {
                return $rule;
            }
        }
        return null;
    }

    /** Add active rule configuration to the cache-safe frontend payload. */
    public static function frontend_config(array $config): array
    {
        if (class_exists('ADC_Picker') && ADC_Picker::active()) {
            return $config;
        }
        $rules = self::active_rules();
        if (array() === $rules) {
            unset($config['customEvents']);
            return $config;
        }
        $config['customEvents'] = array(
            'v' => ADC_Custom_Event_Rule::SCHEMA_VERSION,
            'rules' => array_map(array(ADC_Custom_Event_Rule::class, 'to_frontend'), $rules),
        );
        return $config;
    }

    /** Enqueue the runtime only when the core dataLayer and active rules exist. */
    public static function enqueue(): void
    {
        if ((class_exists('ADC_Picker') && ADC_Picker::active()) || !wp_script_is('adc-datalayer', 'enqueued') || array() === self::active_rules()) {
            return;
        }
        if (class_exists('ADC_Picker') && is_callable(array('ADC_Picker', 'active')) && call_user_func(array('ADC_Picker', 'active'))) {
            return;
        }
        $base = defined('ADC_MARKETING_PLUGIN_URL') ? rtrim((string) ADC_MARKETING_PLUGIN_URL, '/') . '/assets/js/' : '';
        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        wp_enqueue_script('adc-custom-events', $base . 'custom-events.js', array('adc-datalayer'), $version, array(
            'in_footer' => true,
            'strategy' => 'defer',
        ));
    }

    /** Truncate a string without splitting a UTF-8 code point. */
    private static function truncate_utf8(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        if (1 === preg_match('/^.{0,' . $limit . '}/us', $value, $match)) {
            return $match[0];
        }
        $valid = wp_check_invalid_utf8($value, true);
        preg_match('/^.{0,' . $limit . '}/us', $valid, $match);
        return $match[0] ?? '';
    }

    /** Whether an enabled mirrored event is routed to the requested platform. */
    public static function routes_to(string $name, string $platform): bool
    {
        foreach (self::active_rules() as $rule) {
            if (($rule['name'] ?? '') === $name
                && !empty($rule['destinations']['server_mirror'])
                && in_array($platform, $rule['destinations']['platforms'] ?? array(), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rebuild event parameters from the saved rule, accepting only token values from the client.
     *
     * @param array<string, mixed> $rule Stored rule.
     * @param array<string, mixed> $client Untrusted client parameters.
     * @return array<string, string>
     */
    public static function server_params(array $rule, array $client): array
    {
        $params = array();
        foreach ($rule['params'] ?? array() as $param) {
            if (!is_array($param) || !isset($param['key']) || !is_string($param['key'])) {
                continue;
            }
            $key = $param['key'];
            $stored = (string) ($param['value'] ?? '');
            if (1 === preg_match('/\{\{[^{}]+\}\}/', $stored)) {
                if (!array_key_exists($key, $client) || !is_scalar($client[$key])) {
                    $params[$key] = '';
                    continue;
                }
                $value = sanitize_text_field((string) $client[$key]);
                $clean = ADC_Custom_Event_Rule::scrub($value);
                $params[$key] = self::truncate_utf8($clean, 100);
                continue;
            }
            $params[$key] = $stored;
        }
        return $params;
    }
}
