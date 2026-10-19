<?php
/**
 * Analytics settings group and legal gate status.
 *
 * Gate policy for this release: every traffic, UTM, add-to-cart and
 * begin-checkout collection gate is closed by code. A filled legal reference or
 * approval date is recorded for future review but never opens a gate, and no
 * constant, filter or option can bypass it. Only commerce reporting can be
 * enabled (enabled_commerce AND commerce_ack).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings group 'analytics' and the fail-closed legal gates.
 */
final class ADC_Analytics_Settings implements ADC_Module_Interface
{
    /** Settings group slug passed to ADC_Settings. */
    public const GROUP = 'analytics';

    /** Retention bounds in days. */
    private const RETENTION_TRAFFIC = array(30, 180);
    private const RETENTION_COMMERCE = array(90, 3650);
    private const RETENTION_LEDGER = array(30, 1095);

    /** Statuses that may be selected as paid. */
    private const PAID_STATUS_ALLOWLIST = array('processing', 'completed');

    /**
     * Hook the group registration into the settings lifecycle.
     */
    public static function boot(): void
    {
        add_action('adc_register_settings', array(self::class, 'register'));
    }

    /**
     * Register the analytics settings group.
     */
    public static function register(): void
    {
        ADC_Settings::register_group(self::GROUP, self::defaults(), array(self::class, 'sanitize'));
    }

    /**
     * Default values for every key. Traffic and interaction keys are kept as
     * draft controls for future legal review; they stay inert in this release.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return array(
            // A. Commerce reporting (operational).
            'enabled_commerce'               => false,
            'commerce_ack'                   => false,
            'paid_statuses'                  => array('processing', 'completed'),
            'retention_commerce_days'        => 730,
            'retention_ledger_days'          => 365,

            // B. Traffic counting (legal gate closed in this release).
            'enabled_traffic'                => false,
            'traffic_legal_basis'            => '',
            'traffic_legal_reference'        => '',
            'traffic_approved_at'            => '',
            'retention_traffic_days'         => 90,
            'traffic_utm_enabled'            => false,
            'traffic_utm_legal_reference'    => '',
            'traffic_utm_approved_at'        => '',
            'exclude_logged_in_admins'       => true,
            'bot_filter_enabled'             => true,
            'min_cell_count'                 => 5,

            // C. Interaction events (legal gate closed in this release).
            'enabled_add_to_cart'            => false,
            'add_to_cart_legal_reference'    => '',
            'add_to_cart_approved_at'        => '',
            'enabled_begin_checkout'         => false,
            'begin_checkout_legal_reference' => '',
            'begin_checkout_approved_at'     => '',
        );
    }

    /**
     * Sanitize the whole group into a complete, typed array.
     *
     * Effective collection toggles are forced to false here, so the stored
     * option never shows a collection switch as on while its gate is closed.
     *
     * @param array<string,mixed> $input   Raw input merged over the current values.
     * @param array<string,mixed> $current Currently stored values.
     * @return array<string,mixed>
     */
    public static function sanitize(array $input, array $current): array
    {
        $merged = array_merge(self::defaults(), $current, $input);

        $commerce_days = self::clamp_int($merged['retention_commerce_days'], self::RETENTION_COMMERCE);
        // Ledger must outlive commerce aggregates, otherwise order replay becomes possible.
        $ledger_days   = max(self::clamp_int($merged['retention_ledger_days'], self::RETENTION_LEDGER), $commerce_days);

        return array(
            'enabled_commerce'               => (bool) $merged['enabled_commerce'],
            'commerce_ack'                   => (bool) $merged['commerce_ack'],
            'paid_statuses'                  => self::sanitize_paid_statuses($merged['paid_statuses']),
            'retention_commerce_days'        => $commerce_days,
            'retention_ledger_days'          => $ledger_days,

            'enabled_traffic'                => false,
            'traffic_legal_basis'            => ($merged['traffic_legal_basis'] === 'lia') ? 'lia' : '',
            'traffic_legal_reference'        => self::sanitize_reference($merged['traffic_legal_reference']),
            'traffic_approved_at'            => self::sanitize_date($merged['traffic_approved_at']),
            'retention_traffic_days'         => self::clamp_int($merged['retention_traffic_days'], self::RETENTION_TRAFFIC),
            'traffic_utm_enabled'            => false,
            'traffic_utm_legal_reference'    => self::sanitize_reference($merged['traffic_utm_legal_reference']),
            'traffic_utm_approved_at'        => self::sanitize_date($merged['traffic_utm_approved_at']),
            'exclude_logged_in_admins'       => (bool) $merged['exclude_logged_in_admins'],
            'bot_filter_enabled'             => (bool) $merged['bot_filter_enabled'],
            'min_cell_count'                 => self::clamp_int($merged['min_cell_count'], array(3, 50)),

            'enabled_add_to_cart'            => false,
            'add_to_cart_legal_reference'    => self::sanitize_reference($merged['add_to_cart_legal_reference']),
            'add_to_cart_approved_at'        => self::sanitize_date($merged['add_to_cart_approved_at']),
            'enabled_begin_checkout'         => false,
            'begin_checkout_legal_reference' => self::sanitize_reference($merged['begin_checkout_legal_reference']),
            'begin_checkout_approved_at'     => self::sanitize_date($merged['begin_checkout_approved_at']),
        );
    }

    /**
     * Commerce reporting is on only when the feature is enabled and the admin
     * has acknowledged the definitions and retention.
     */
    public static function commerce_enabled(): bool
    {
        return self::flag('enabled_commerce') && self::flag('commerce_ack');
    }

    /**
     * Traffic counting is closed in this release. Legal reference and approval
     * date are recorded for review but do not open this gate.
     */
    public static function traffic_enabled(): bool
    {
        return false;
    }

    /**
     * UTM ingestion is closed in this release.
     */
    public static function utm_enabled(): bool
    {
        return false;
    }

    /**
     * Add-to-cart events are closed in this release.
     */
    public static function add_to_cart_enabled(): bool
    {
        return false;
    }

    /**
     * Begin-checkout has no verified server-side hook; always false.
     */
    public static function begin_checkout_enabled(): bool
    {
        return false;
    }

    /**
     * Sanitized paid-status allowlist for commerce aggregation.
     *
     * @return string[]
     */
    public static function paid_statuses(): array
    {
        $stored = ADC_Settings::get(self::GROUP, 'paid_statuses', array());

        return self::sanitize_paid_statuses($stored);
    }

    /**
     * Retention in days for one scope: traffic, commerce or ledger.
     * The ledger value is never shorter than the commerce value.
     */
    public static function retention_days(string $scope): int
    {
        return match ($scope) {
            'traffic'  => self::clamp_int(ADC_Settings::get(self::GROUP, 'retention_traffic_days', 90), self::RETENTION_TRAFFIC),
            'commerce' => self::clamp_int(ADC_Settings::get(self::GROUP, 'retention_commerce_days', 730), self::RETENTION_COMMERCE),
            'ledger'   => max(
                self::clamp_int(ADC_Settings::get(self::GROUP, 'retention_ledger_days', 365), self::RETENTION_LEDGER),
                self::retention_days('commerce')
            ),
            default    => 0,
        };
    }

    /**
     * Minimum cell count before a report value may be shown.
     */
    public static function min_cell_count(): int
    {
        return self::clamp_int(ADC_Settings::get(self::GROUP, 'min_cell_count', 5), array(3, 50));
    }

    /**
     * Gate status for one feature, with a Romanian reason for the admin UI.
     *
     * @return array{enabled:bool,reason:string}
     */
    public static function legal_gate_status(string $feature): array
    {
        switch ($feature) {
            case 'commerce':
                if (!self::flag('enabled_commerce')) {
                    return array('enabled' => false, 'reason' => __('Raportarea comercială este dezactivată.', 'adc-marketing'));
                }
                if (!self::flag('commerce_ack')) {
                    return array('enabled' => false, 'reason' => __('Confirmați definițiile și perioadele de retenție pentru a activa raportarea.', 'adc-marketing'));
                }

                return array('enabled' => true, 'reason' => __('Raportarea comercială este activă.', 'adc-marketing'));

            case 'traffic':
                return array('enabled' => false, 'reason' => __('Numărarea traficului este blocată: nu există încă o aprobare juridică finală (obiecție Art. 21 GDPR și evaluare ePrivacy). Referința și data introduse sunt doar notițe pentru analiza viitoare.', 'adc-marketing'));

            case 'utm':
                return array('enabled' => false, 'reason' => __('Parametrii UTM nu sunt colectați în această versiune.', 'adc-marketing'));

            case 'add_to_cart':
                return array('enabled' => false, 'reason' => __('Evenimentul de adăugare în coș este blocat până la aprobarea juridică și tehnică.', 'adc-marketing'));

            case 'begin_checkout':
                return array('enabled' => false, 'reason' => __('Evenimentul de început al plății nu are un hook server verificat.', 'adc-marketing'));

            default:
                return array('enabled' => false, 'reason' => __('Funcție necunoscută.', 'adc-marketing'));
        }
    }

    /**
     * Read a stored boolean toggle.
     */
    private static function flag(string $key): bool
    {
        return (bool) ADC_Settings::get(self::GROUP, $key, false);
    }

    /**
     * Clamp an integer into [min, max]; non-numeric input falls back to min.
     *
     * @param mixed                $value  Raw value.
     * @param array{0:int,1:int}   $bounds Inclusive bounds.
     */
    private static function clamp_int(mixed $value, array $bounds): int
    {
        return max($bounds[0], min($bounds[1], (int) $value));
    }

    /**
     * Keep only allowlisted paid statuses; fall back to the defaults when none remain.
     *
     * @return string[]
     */
    private static function sanitize_paid_statuses(mixed $value): array
    {
        $values = is_array($value) ? $value : array();
        $kept   = array_values(array_intersect(self::PAID_STATUS_ALLOWLIST, array_map('strval', $values)));

        return ($kept === array()) ? array('processing', 'completed') : $kept;
    }

    /**
     * Plain-text reference (document id, URL or label), length-limited.
     */
    private static function sanitize_reference(mixed $value): string
    {
        $text = sanitize_text_field((string) $value);

        // PCRE /u slices by characters without requiring the mbstring extension.
        return preg_match('/^.{0,200}/su', $text, $matches) === 1 ? $matches[0] : '';
    }

    /**
     * Accept only a real Y-m-d date that is not in the future (site timezone).
     */
    private static function sanitize_date(mixed $value): string
    {
        $text = (string) $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if (!$date || $date->format('Y-m-d') !== $text) {
            return '';
        }

        return ($text > current_time('Y-m-d')) ? '' : $text;
    }
}
