<?php
/**
 * My Account marketing preferences handler
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * My Account marketing class
 */
class ADC_MyAccount_Marketing
{
    /**
     * Plugin instance
     *
     * @var ADC_MyAccount_Marketing|null
     */
    private static ?ADC_MyAccount_Marketing $instance = null;

    /**
     * Get plugin instance
     *
     * @return ADC_MyAccount_Marketing
     */
    public static function get_instance(): ADC_MyAccount_Marketing
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
        // Add endpoint
        add_action('init', [$this, 'add_endpoint']);

        // Add menu item
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);

        // Display endpoint content
        add_action('woocommerce_account_marketing-consent_endpoint', [$this, 'endpoint_content']);

        // Handle form submission
        add_action('wp', [$this, 'save_preferences']);

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add rewrite endpoint
     */
    public function add_endpoint(): void
    {
        add_rewrite_endpoint('marketing-consent', EP_ROOT | EP_PAGES);
    }

    /**
     * Add menu item
     *
     * @param array<string, string> $items Menu items
     * @return array<string, string>
     */
    public function add_menu_item(array $items): array
    {
        $new_items = [];
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            if ('edit-account' === $key) {
                $new_items['marketing-consent'] = __('Marketing', 'adc-marketing');
            }
        }
        return $new_items;
    }

    /**
     * Display endpoint content
     */
    public function endpoint_content(): void
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $email_consent = get_user_meta($user_id, '_adc_marketing_email_consent', true);
        $sms_consent = get_user_meta($user_id, '_adc_marketing_sms_consent', true);

        // If no individual preferences set, check main consent
        if (empty($email_consent) && empty($sms_consent)) {
            $main_consent = get_user_meta($user_id, '_adc_marketing_consent', true);
            if ('yes' === $main_consent) {
                $email_consent = 'yes';
                $sms_consent = 'yes';
            }
        }

        // Success flag after the PRG redirect. Rendered by the template: WooCommerce has
        // already printed its notices by the time the endpoint content runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag after redirect.
        $updated = isset($_GET['updated']) && '1' === $_GET['updated'];

        // Render through ADC_Template so themes can override the markup at
        // {theme}/adc-marketing/myaccount/marketing-consent.php.
        ADC_Template::render(
            'myaccount/marketing-consent.php',
            array(
                'user_id'       => $user_id,
                'email_consent' => $email_consent,
                'sms_consent'   => $sms_consent,
                'updated'       => $updated,
            )
        );
    }

    /**
     * Save user preferences
     */
    public function save_preferences(): void
    {
        if (!isset($_POST['adc_save_marketing_preferences'])) {
            return;
        }

        if (!isset($_POST['adc_marketing_preferences_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['adc_marketing_preferences_nonce'])), 'adc_marketing_preferences')) {
            wc_add_notice(__('Eroare de securitate. Vă rugăm să încercați din nou.', 'adc-marketing'), 'error');
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $email_consent = isset($_POST['adc_marketing_email_consent']) && 'yes' === $_POST['adc_marketing_email_consent'] ? 'yes' : 'no';
        $sms_consent = isset($_POST['adc_marketing_sms_consent']) && 'yes' === $_POST['adc_marketing_sms_consent'] ? 'yes' : 'no';

        // Save individual preferences
        update_user_meta($user_id, '_adc_marketing_email_consent', $email_consent);
        update_user_meta($user_id, '_adc_marketing_sms_consent', $sms_consent);

        // Update timestamps
        if ('yes' === $email_consent) {
            update_user_meta($user_id, '_adc_marketing_email_consent_date', time());
        }
        if ('yes' === $sms_consent) {
            update_user_meta($user_id, '_adc_marketing_sms_consent_date', time());
        }

        // Update main consent: yes if at least one is checked, no if both unchecked
        $main_consent = ('yes' === $email_consent || 'yes' === $sms_consent) ? 'yes' : 'no';
        update_user_meta($user_id, '_adc_marketing_consent', $main_consent);
        update_user_meta($user_id, '_adc_marketing_consent_date', time());

        // Redirect to prevent resubmission
        wp_safe_redirect(wc_get_endpoint_url('marketing-consent', '', wc_get_page_permalink('myaccount')) . '?updated=1');
        exit;
    }

    /**
     * Enqueue assets
     */
    public function enqueue_assets(): void
    {
        if (!is_account_page()) {
            return;
        }

        wp_enqueue_style(
            'adc-marketing-myaccount',
            ADC_MARKETING_PLUGIN_URL . 'assets/css/myaccount-marketing.css',
            [],
            ADC_MARKETING_VERSION
        );
    }
}

