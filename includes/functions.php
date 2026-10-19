<?php
/**
 * Helper functions
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get privacy policy URL
 *
 * @return string
 */
function adc_marketing_get_privacy_policy_url(): string
{
    $privacy_page_id = get_option('wp_page_for_privacy_policy');
    
    if ($privacy_page_id) {
        return esc_url(get_permalink($privacy_page_id));
    }

    return '#';
}

/**
 * Process privacy policy shortcode
 *
 * @param array<string, mixed> $atts Shortcode attributes
 * @param string $content Shortcode content
 * @return string
 */
function adc_marketing_privacy_policy_link_shortcode(array $atts = [], string $content = ''): string
{
    $url = adc_marketing_get_privacy_policy_url();
    $link_text = !empty($content) ? $content : __('Politica de Confidențialitate', 'adc-marketing');
    
    return sprintf(
        '<a href="%s" target="_blank">%s</a>',
        esc_url($url),
        esc_html($link_text)
    );
}

// Register shortcode
add_shortcode('privacy-policy-link', 'adc_marketing_privacy_policy_link_shortcode');

/**
 * Get default consent message
 *
 * @return string
 */
function adc_marketing_get_default_consent_message(): string
{
    return sprintf(
        /* translators: %s: Privacy policy link shortcode */
        __('Sunt de acord să primesc e-mailuri de marketing și promoții prin SMS de la acest magazin. Am citit și sunt de acord cu [privacy-policy-link]Politica de Confidențialitate[/privacy-policy-link].', 'adc-marketing')
    );
}

/**
 * Process consent message with shortcodes
 *
 * @param string $message Raw message
 * @return string Processed message
 */
function adc_marketing_process_consent_message(string $message): string
{
    // Process shortcodes
    $message = do_shortcode($message);
    
    // Allow HTML
    return wp_kses_post($message);
}

/**
 * Check if user has marketing consent
 *
 * @param int $user_id User ID
 * @return bool
 */
function adc_marketing_user_has_consent(int $user_id): bool
{
    $consent = get_user_meta($user_id, '_adc_marketing_consent', true);
    return 'yes' === $consent;
}

/**
 * Check if order has marketing consent
 *
 * @param int $order_id Order ID
 * @return bool
 */
function adc_marketing_order_has_consent(int $order_id): bool
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }

    $consent = $order->get_meta('_adc_marketing_consent');
    return 'yes' === $consent;
}

