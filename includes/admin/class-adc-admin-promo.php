<?php
/**
 * Dismissible Adcelerum promo banner under the admin page header.
 *
 * Shown on the plugin page only, to users with manage_options, until the user
 * dismisses it. Dismissal is stored per user (user meta) through
 * admin-post.php?action=adc_dismiss_promo (nonce `adc_dismiss_promo`), so it
 * works without JavaScript; assets/js/admin-shell.js hides it in place.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Promo banner: render + dismiss handler.
 */
final class ADC_Admin_Promo
{
    /**
     * User meta key holding the dismissal flag.
     */
    public const META_KEY = '_adc_promo_dismissed';

    /**
     * admin-post action / nonce action.
     */
    public const ACTION = 'adc_dismiss_promo';

    /**
     * CTA target (opens in a new tab, followed link).
     */
    private const CTA_URL = 'https://adcelerum.ro/';

    /**
     * Register the dismiss endpoint (called from ADC_Admin_Shell::boot()).
     */
    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, array(self::class, 'handle_dismiss'));
    }

    /**
     * Whether the current user should see the banner.
     *
     * @return bool
     */
    public static function is_visible(): bool
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        return '1' !== (string) get_user_meta(get_current_user_id(), self::META_KEY, true);
    }

    /**
     * Print the banner (no-op once dismissed).
     */
    public static function render(): void
    {
        if (!self::is_visible()) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::ACTION),
            self::ACTION
        );
        ?>
        <aside class="adc-promo" aria-labelledby="adc-promo-title" data-adc-promo>
            <span class="adc-promo__aurora" aria-hidden="true"></span>
            <div class="adc-promo__body">
                <p class="adc-promo__title" id="adc-promo-title"><?php esc_html_e('Dorești o soluție personalizată pentru afacerea ta?', 'adc-marketing'); ?></p>
                <p class="adc-promo__text"><?php esc_html_e('Echipa Adcelerum construiește integrări, automatizări și funcționalități pe măsura magazinului tău.', 'adc-marketing'); ?></p>
            </div>
            <a class="adc-promo__cta" href="<?php echo esc_url(self::CTA_URL); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Discută cu Adcelerum', 'adc-marketing'); ?>
                <span class="dashicons dashicons-external" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('(se deschide într-o filă nouă)', 'adc-marketing'); ?></span>
            </a>
            <a class="adc-promo__dismiss" href="<?php echo esc_url($dismiss_url); ?>" data-adc-promo-dismiss
               aria-label="<?php esc_attr_e('Ascunde acest mesaj', 'adc-marketing'); ?>">
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </a>
        </aside>
        <?php
    }

    /**
     * admin-post handler: remember the dismissal for this user, then go back.
     */
    public static function handle_dismiss(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nu aveți permisiunea necesară.', 'adc-marketing'), '', array('response' => 403));
        }

        check_admin_referer(self::ACTION);

        update_user_meta(get_current_user_id(), self::META_KEY, '1');

        $back = wp_get_referer();
        wp_safe_redirect(is_string($back) && '' !== $back ? $back : admin_url('admin.php?page=' . ADC_Admin_Shell::PAGE_SLUG));
        exit;
    }
}
