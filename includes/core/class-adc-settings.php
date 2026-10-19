<?php
/**
 * Grouped settings storage (docs/ARCHITECTURE.md §2.1, §4).
 *
 * One option per group (`adc_settings_<group>`), defaults merged with stored
 * values, per-group sanitize callback, secrets sealed at rest and overridable
 * by constants ADC_{GROUP}_{KEY} in wp-config.php.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grouped, sanitised, secret-aware settings repository.
 */
final class ADC_Settings implements ADC_Module_Interface
{
    /**
     * Registered groups: group => ['defaults' => array, 'sanitize' => callable, 'secret_keys' => array].
     *
     * @var array<string, array{defaults: array, sanitize: callable, secret_keys: string[]}>
     */
    private static array $groups = array();

    /**
     * Whether the `adc_register_settings` action has been fired already.
     *
     * @var bool
     */
    private static bool $register_fired = false;

    /**
     * Boot the module: fire `adc_register_settings` on init priority 5.
     */
    public static function boot(): void
    {
        add_action(
            'init',
            static function (): void {
                self::ensure_groups_registered();
            },
            5
        );
    }

    /**
     * Register a settings group (called on action `adc_register_settings`).
     *
     * @param string   $group        Group slug, e.g. 'consent', 'meta'.
     * @param array    $defaults     Default values per key.
     * @param callable $sanitize     fn(array $input, array $current): array — must return a complete, typed array.
     * @param string[] $secret_keys  Keys stored sealed (encrypted) at rest.
     */
    public static function register_group(string $group, array $defaults, callable $sanitize, array $secret_keys = array()): void
    {
        self::$groups[$group] = array(
            'defaults'    => $defaults,
            'sanitize'    => $sanitize,
            'secret_keys' => array_values(array_map('strval', $secret_keys)),
        );
    }

    /**
     * Get a single value (stored, falling back to group default, then $default).
     *
     * @param string $group   Group slug.
     * @param string $key     Key inside the group.
     * @param mixed  $default Fallback when the key is unknown.
     * @return mixed
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = self::all($group);
        return $all[$key] ?? $default;
    }

    /**
     * Full group values: defaults merged with stored option.
     *
     * @param string $group Group slug.
     * @return array
     */
    public static function all(string $group): array
    {
        self::ensure_groups_registered();

        $stored = get_option(self::option_name($group), array());
        $stored = is_array($stored) ? $stored : array();

        $defaults = self::$groups[$group]['defaults'] ?? array();

        return array_merge($defaults, $stored);
    }

    /**
     * Sanitise and persist a full group. Keeps the stored secret when the
     * incoming secret value is an empty string (UI never echoes secrets back).
     *
     * @param string $group Group slug.
     * @param array  $input Raw (already unslashed/sanitised by caller) input.
     * @return array Stored values after save.
     */
    public static function update(string $group, array $input): array
    {
        self::ensure_groups_registered();

        $current     = self::all($group);
        $meta        = self::$groups[$group] ?? null;
        $sanitize    = $meta['sanitize'] ?? null;
        $secret_keys = $meta['secret_keys'] ?? array();

        $sanitized = (null !== $sanitize && is_callable($sanitize))
            ? $sanitize($input, $current)
            : array_merge($current, $input);

        if (!is_array($sanitized)) {
            $sanitized = $current;
        }

        foreach ($secret_keys as $secret_key) {
            $incoming = $sanitized[$secret_key] ?? '';
            if (!is_string($incoming) || '' === $incoming) {
                // Empty input = "keep the saved secret" (constant override still wins on read).
                $sanitized[$secret_key] = $current[$secret_key] ?? '';
            } elseif (!str_starts_with($incoming, 'enc1:')) {
                // A brand-new plaintext secret: seal it at rest.
                $sanitized[$secret_key] = ADC_Secrets::seal($incoming);
            }
        }

        // Small groups autoload; 'banner' (large inventory/labels) does not (§4).
        $autoload = ('banner' === $group) ? 'no' : 'yes';
        update_option(self::option_name($group), $sanitized, $autoload);

        return self::all($group);
    }

    /**
     * Decrypted secret value. Constant ADC_{GROUP}_{KEY} (e.g. ADC_META_ACCESS_TOKEN)
     * overrides the stored value when defined and non-empty.
     *
     * @param string $group Group slug.
     * @param string $key   Secret key inside the group.
     * @return string Decrypted value, '' when absent.
     */
    public static function secret(string $group, string $key): string
    {
        $constant = 'ADC_' . strtoupper($group) . '_' . strtoupper($key);
        if (defined($constant)) {
            $value = constant($constant);
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        $stored = self::all($group)[$key] ?? '';
        if (!is_string($stored) || '' === $stored) {
            return '';
        }

        return ADC_Secrets::open($stored);
    }

    /**
     * Option name backing a group.
     *
     * @param string $group Group slug.
     * @return string
     */
    public static function option_name(string $group): string
    {
        return 'adc_settings_' . $group;
    }

    /**
     * Fire `adc_register_settings` exactly once (defensive for early reads).
     */
    private static function ensure_groups_registered(): void
    {
        if (self::$register_fired) {
            return;
        }

        self::$register_fired = true;
        do_action('adc_register_settings');
    }
}
