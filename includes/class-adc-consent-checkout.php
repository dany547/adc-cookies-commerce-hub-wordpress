<?php
/**
 * Checkout consent handler
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checkout consent class
 */
class ADC_Consent_Checkout
{
    /**
     * Plugin instance
     *
     * @var ADC_Consent_Checkout|null
     */
    private static ?ADC_Consent_Checkout $instance = null;

    /**
     * Get plugin instance
     *
     * @return ADC_Consent_Checkout
     */
    public static function get_instance(): ADC_Consent_Checkout
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void
    {
        // Known gap (2.0): the block checkout (Store API) does not render this checkbox yet.
        // Documented in GDPR-COMPLIANCE.md and README.txt.

        // Display checkbox on checkout
        add_action('woocommerce_review_order_before_submit', [$this, 'render_consent_checkbox']);

        // Save consent to order meta
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_order_consent']);

        // Save consent to user meta (for logged-in users)
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_user_consent']);

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Render consent checkbox on checkout
     */
    public function render_consent_checkbox(): void
    {
        $consent_message = get_option('adc_marketing_consent_message', adc_marketing_get_default_consent_message());
        $processed_message = adc_marketing_process_consent_message($consent_message);

        ?>
        <div class="adc-consent-checkbox-wrapper">
            <p class="form-row">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" 
                           class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" 
                           name="adc_marketing_consent" 
                           id="adc_marketing_consent" 
                           value="1" />
                    <span class="adc-consent-label">
                        <?php echo wp_kses_post($processed_message); ?>
                    </span>
                </label>
            </p>
        </div>
        <?php
    }


    /**
     * Save consent to order meta
     *
     * @param int $order_id Order ID
     */
    public function save_order_consent(int $order_id): void
    {
        // GDPR: Only save 'yes' if checkbox is explicitly checked, otherwise 'no'
        $consent = $this->consent_from_post();

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Store consent status
        $order->update_meta_data('_adc_marketing_consent', $consent);

        // GDPR: Always store consent message version for audit trail (even if consent is 'no')
        $consent_message = (string) get_option('adc_marketing_consent_message', adc_marketing_get_default_consent_message());
        $order->update_meta_data('_adc_consent_text_version', $consent_message);

        // Proof of what was shown: SHA-256 of the exact text (2.0, docs/ARCHITECTURE.md §7).
        $order->update_meta_data('_adc_consent_text_hash', hash('sha256', $consent_message));

        // GDPR: Store consent date for audit trail
        $order->update_meta_data('_adc_marketing_consent_date', time());

        // GDPR: Keep only a keyed hash of the IP for new orders (no raw IP).
        // Legacy `_adc_consent_ip_address` values are left in place until erasure.
        $ip_hash = $this->hashed_client_ip();
        if ('' !== $ip_hash) {
            $order->update_meta_data('_adc_consent_ip_hash', $ip_hash);
        }

        $order->save();
    }

    /**
     * Save consent to user meta (for logged-in users)
     *
     * @param int $order_id Order ID
     */
    public function save_user_consent(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return; // Guest checkout
        }

        $consent = $this->consent_from_post();

        update_user_meta($user_id, '_adc_marketing_consent', $consent);
        update_user_meta($user_id, '_adc_marketing_consent_date', time());
        update_user_meta($user_id, '_adc_marketing_consent_source', 'checkout');

        // If consent is yes, also set individual preferences to yes
        if ('yes' === $consent) {
            update_user_meta($user_id, '_adc_marketing_email_consent', 'yes');
            update_user_meta($user_id, '_adc_marketing_sms_consent', 'yes');
            update_user_meta($user_id, '_adc_marketing_email_consent_date', time());
            update_user_meta($user_id, '_adc_marketing_sms_consent_date', time());
        }
    }

    /**
     * Read the checkbox from the checkout POST.
     *
     * The checkbox is optional by design (GDPR: no forced consent), so anything
     * other than an explicit '1' is stored as 'no'. WooCommerce verifies the
     * checkout nonce before firing the hooks that call this.
     *
     * @return string 'yes' or 'no'.
     */
    private function consent_from_post(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checkout nonce verified by WooCommerce.
        $value = isset($_POST['adc_marketing_consent']) ? sanitize_text_field(wp_unslash((string) $_POST['adc_marketing_consent'])) : '';

        // No explicit choice: apply the default-consent filter (GDPR guard in
        // ADC_GDPR_Compliance::ensure_explicit_consent() forces 'no' — unchecked by default).
        if ('' === $value) {
            $default = (string) apply_filters('adc_marketing_default_consent', 'no');

            return 'yes' === $default ? 'yes' : 'no';
        }

        return '1' === $value ? 'yes' : 'no';
    }

    /**
     * Keyed hash of the client IP (HMAC-SHA256 with the auth salt), or '' when unavailable.
     *
     * @return string 64 hex characters or ''.
     */
    private function hashed_client_ip(): string
    {
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return '';
        }

        $ip = sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_assets(): void
    {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'adc-marketing-checkout',
            ADC_MARKETING_PLUGIN_URL . 'assets/css/checkout-consent.css',
            [],
            ADC_MARKETING_VERSION
        );

        wp_enqueue_script(
            'adc-marketing-checkout',
            ADC_MARKETING_PLUGIN_URL . 'assets/js/checkout-consent.js',
            [],
            ADC_MARKETING_VERSION,
            true
        );
    }
}

