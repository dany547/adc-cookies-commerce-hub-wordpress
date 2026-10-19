<?php
/**
 * GDPR Compliance handler: WordPress privacy exporter/eraser and user deletion cleanup.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GDPR Compliance class
 */
class ADC_GDPR_Compliance
{
    /**
     * Plugin instance
     *
     * @var ADC_GDPR_Compliance|null
     */
    private static ?ADC_GDPR_Compliance $instance = null;

    /**
     * Exporter / eraser identifier used in the WordPress privacy tools.
     */
    private const PRIVACY_GROUP = 'adc-marketing';

    /**
     * Eraser group for legacy raw IP addresses stored on orders by 1.0.
     */
    private const LEGACY_IP_GROUP = 'adc-marketing-legacy-ip';

    /**
     * Order meta key holding a raw IP address written by 1.0 (2.0 stores a keyed hash only).
     */
    private const LEGACY_IP_META = '_adc_consent_ip_address';

    /**
     * Orders processed per erasure page.
     */
    private const LEGACY_IP_PAGE_SIZE = 100;

    /**
     * User meta keys that hold marketing consent (removed on deletion and erasure).
     *
     * @var string[]
     */
    private const USER_META_KEYS = [
        '_adc_marketing_consent',
        '_adc_marketing_consent_date',
        '_adc_marketing_consent_source',
        '_adc_marketing_email_consent',
        '_adc_marketing_email_consent_date',
        '_adc_marketing_sms_consent',
        '_adc_marketing_sms_consent_date',
    ];

    /**
     * Get plugin instance
     *
     * @return ADC_GDPR_Compliance
     */
    public static function get_instance(): ADC_GDPR_Compliance
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
        // Handle user data deletion (Right to be forgotten)
        add_action('delete_user', [$this, 'handle_user_deletion']);

        // WordPress privacy tools: export (Data portability) and erase (on request).
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);

        // Add privacy policy check
        add_action('admin_notices', [$this, 'check_privacy_policy']);

        // Ensure consent is explicit (checkbox unchecked by default)
        add_filter('adc_marketing_default_consent', [$this, 'ensure_explicit_consent'], 10, 1);
    }

    /**
     * Handle user deletion - GDPR Right to be Forgotten
     *
     * @param int $user_id User ID being deleted
     */
    public function handle_user_deletion(int $user_id): void
    {
        foreach (self::USER_META_KEYS as $meta_key) {
            delete_user_meta($user_id, $meta_key);
        }
    }

    /**
     * Register the marketing-consent exporter with the WordPress privacy tools.
     *
     * @param array<string, array<string, mixed>> $exporters Registered exporters.
     * @return array<string, array<string, mixed>>
     */
    public function register_exporter(array $exporters): array
    {
        $exporters[self::PRIVACY_GROUP] = [
            'exporter_friendly_name' => __('ADC Marketing - consimțământ marketing', 'adc-marketing'),
            'callback' => [$this, 'export_personal_data'],
        ];

        return $exporters;
    }

    /**
     * Register the marketing-consent eraser with the WordPress privacy tools.
     *
     * @param array<string, array<string, mixed>> $erasers Registered erasers.
     * @return array<string, array<string, mixed>>
     */
    public function register_eraser(array $erasers): array
    {
        $erasers[self::PRIVACY_GROUP] = [
            'eraser_friendly_name' => __('ADC Marketing - consimțământ marketing', 'adc-marketing'),
            'callback' => [$this, 'erase_personal_data'],
        ];

        $erasers[self::LEGACY_IP_GROUP] = [
            'eraser_friendly_name' => __('ADC Marketing - adrese IP vechi din comenzi', 'adc-marketing'),
            'callback' => [$this, 'erase_legacy_ip'],
        ];

        return $erasers;
    }

    /**
     * Export a registered user's marketing consent data.
     *
     * Guest order consent is exported by WooCommerce together with the order itself.
     *
     * @param string $email_address Requester e-mail address.
     * @param int    $page          Pagination (unused: one item per user).
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function export_personal_data(string $email_address, int $page = 1): array
    {
        $user = get_user_by('email', $email_address);
        $items = [];

        if ($user instanceof WP_User) {
            $items[] = [
                'group_id' => self::PRIVACY_GROUP,
                'group_label' => __('Consimțământ marketing', 'adc-marketing'),
                'item_id' => 'adc-marketing-user-' . $user->ID,
                'data' => [
                    [
                        'name' => __('Consimțământ general', 'adc-marketing'),
                        'value' => $this->yes_no_label((string) get_user_meta($user->ID, '_adc_marketing_consent', true)),
                    ],
                    [
                        'name' => __('Data consimțământ general', 'adc-marketing'),
                        'value' => $this->format_timestamp(get_user_meta($user->ID, '_adc_marketing_consent_date', true)),
                    ],
                    [
                        'name' => __('Sursa consimțământ', 'adc-marketing'),
                        'value' => (string) get_user_meta($user->ID, '_adc_marketing_consent_source', true),
                    ],
                    [
                        'name' => __('Consimțământ e-mail', 'adc-marketing'),
                        'value' => $this->yes_no_label((string) get_user_meta($user->ID, '_adc_marketing_email_consent', true)),
                    ],
                    [
                        'name' => __('Data consimțământ e-mail', 'adc-marketing'),
                        'value' => $this->format_timestamp(get_user_meta($user->ID, '_adc_marketing_email_consent_date', true)),
                    ],
                    [
                        'name' => __('Consimțământ SMS', 'adc-marketing'),
                        'value' => $this->yes_no_label((string) get_user_meta($user->ID, '_adc_marketing_sms_consent', true)),
                    ],
                    [
                        'name' => __('Data consimțământ SMS', 'adc-marketing'),
                        'value' => $this->format_timestamp(get_user_meta($user->ID, '_adc_marketing_sms_consent_date', true)),
                    ],
                ],
            ];
        }

        return [
            'data' => $items,
            'done' => true,
        ];
    }

    /**
     * Erase a registered user's marketing consent meta on request.
     *
     * @param string $email_address Requester e-mail address.
     * @param int    $page          Pagination (unused: one item per user).
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
     */
    public function erase_personal_data(string $email_address, int $page = 1): array
    {
        $user = get_user_by('email', $email_address);
        $removed = false;

        if ($user instanceof WP_User) {
            foreach (self::USER_META_KEYS as $meta_key) {
                if (metadata_exists('user', $user->ID, $meta_key)) {
                    delete_user_meta($user->ID, $meta_key);
                    $removed = true;
                }
            }
        }

        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }

    /**
     * Erase legacy raw IP addresses from the requester's orders (registered and guest).
     *
     * Orders are matched by billing e-mail, paged so large histories stay within limits.
     *
     * @param string $email_address Requester e-mail address.
     * @param int    $page          Pagination page, starting at 1.
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
     */
    public function erase_legacy_ip(string $email_address, int $page = 1): array
    {
        $removed = false;
        $orders = [];
        $email = sanitize_email($email_address);

        // An empty e-mail would match every order without billing e-mail, so skip it.
        if (function_exists('wc_get_orders') && '' !== $email) {
            $orders = wc_get_orders(
                [
                    'billing_email' => $email,
                    'limit' => self::LEGACY_IP_PAGE_SIZE,
                    'paged' => max(1, $page),
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'return' => 'objects',
                ]
            );
        }

        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!$order instanceof WC_Order || '' === (string) $order->get_meta(self::LEGACY_IP_META, true)) {
                continue;
            }

            $order->delete_meta_data(self::LEGACY_IP_META);
            $order->save();
            $removed = true;
        }

        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => [],
            // A short page means there is nothing left to process.
            'done' => !is_array($orders) || count($orders) < self::LEGACY_IP_PAGE_SIZE,
        ];
    }

    /**
     * Check if privacy policy page is set
     */
    public function check_privacy_policy(): void
    {
        $screen = get_current_screen();
        if (!$screen || 'toplevel_page_adc-marketing' !== $screen->id) {
            return;
        }

        $privacy_page_id = get_option('wp_page_for_privacy_policy');
        if (!$privacy_page_id) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('GDPR Compliance:', 'adc-marketing'); ?></strong>
                    <?php esc_html_e('Pentru conformitate GDPR, vă rugăm să setați o pagină de Politică de Confidențialitate.', 'adc-marketing'); ?>
                    <a href="<?php echo esc_url(admin_url('options-privacy.php')); ?>">
                        <?php esc_html_e('Setări > Confidențialitate', 'adc-marketing'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Ensure consent is explicit (unchecked by default)
     *
     * @param string $default Default value
     * @return string
     */
    public function ensure_explicit_consent(string $default): string
    {
        // Always return 'no' to ensure checkbox is unchecked by default
        return 'no';
    }

    /**
     * Verify GDPR compliance requirements
     *
     * @return array<string, bool|string> Compliance status
     */
    public function verify_compliance(): array
    {
        $compliance = [
            'privacy_policy_set' => false,
            'consent_optional' => true,
            'consent_explicit' => true,
            'withdrawal_possible' => true,
            'data_portable' => true,
            'data_deletable' => true,
        ];

        // Check if privacy policy page is set
        $privacy_page_id = get_option('wp_page_for_privacy_policy');
        $compliance['privacy_policy_set'] = (bool) $privacy_page_id;

        // Check if privacy policy URL is valid
        if ($privacy_page_id) {
            $privacy_url = get_permalink($privacy_page_id);
            $compliance['privacy_policy_url'] = $privacy_url ?: '';
        } else {
            $compliance['privacy_policy_url'] = '';
        }

        return $compliance;
    }

    /**
     * Human-readable yes/no label for exported values.
     *
     * @param string $value Stored value ('yes', 'no' or '').
     * @return string
     */
    private function yes_no_label(string $value): string
    {
        if ('yes' === $value) {
            return __('Da', 'adc-marketing');
        }

        return 'no' === $value ? __('Nu', 'adc-marketing') : '';
    }

    /**
     * Format a stored Unix timestamp for an export, or '' when missing.
     *
     * @param mixed $value Stored value.
     * @return string
     */
    private function format_timestamp(mixed $value): string
    {
        $timestamp = (int) $value;
        if ($timestamp <= 0) {
            return '';
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}
