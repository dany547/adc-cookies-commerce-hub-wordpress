<?php
/**
 * Read-only access to the per-channel marketing preferences (e-mail, SMS).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Channel consent wrapper (docs/ARCHITECTURE.md §7, item 7).
 *
 * Reads the My Account preferences stored in user meta. It never writes:
 * cookie consent never touches `_adc_marketing_*` and these preferences never
 * change cookie consent.
 */
final class ADC_Channel_Consent
{
    /**
     * User meta key for e-mail marketing preference.
     */
    private const META_EMAIL = '_adc_marketing_email_consent';

    /**
     * User meta key for SMS marketing preference.
     */
    private const META_SMS = '_adc_marketing_sms_consent';

    /**
     * Whether a registered user opted in to a marketing channel.
     *
     * @param int    $user_id User ID (0 or negative means guest: returns false).
     * @param string $channel 'email' or 'sms'.
     * @return bool
     */
    public static function has(int $user_id, string $channel): bool
    {
        if ($user_id <= 0) {
            return false;
        }

        $meta_key = match ($channel) {
            'email' => self::META_EMAIL,
            'sms'   => self::META_SMS,
            default => '',
        };

        if ('' === $meta_key) {
            return false;
        }

        return 'yes' === get_user_meta($user_id, $meta_key, true);
    }
}
