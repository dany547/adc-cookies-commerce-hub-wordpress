<?php
/**
 * Admin features handler: order and user consent display, order list columns
 * and filters (legacy posts storage and HPOS).
 *
 * The admin page itself (message editor, export, GDPR status) lives in
 * ADC_Admin_Tab_Marketing since 2.0 (docs/ARCHITECTURE.md §7, item 3).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin consent class
 */
class ADC_Consent_Admin
{
    /**
     * Plugin instance
     *
     * @var ADC_Consent_Admin|null
     */
    private static ?ADC_Consent_Admin $instance = null;

    /**
     * Get plugin instance
     *
     * @return ADC_Consent_Admin
     */
    public static function get_instance(): ADC_Consent_Admin
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
        // Admin menu (now owned by ADC_Admin_Shell; see add_admin_menu()).
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Admin settings (option registration only; the form is in ADC_Admin_Tab_Marketing).
        add_action('admin_init', [$this, 'init_settings']);

        // Order admin display
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'display_order_consent']);

        // User profile display
        add_action('show_user_profile', [$this, 'display_user_profile_consent']);
        add_action('edit_user_profile', [$this, 'display_user_profile_consent']);

        // Save user profile consent
        add_action('personal_options_update', [$this, 'save_user_profile_consent']);
        add_action('edit_user_profile_update', [$this, 'save_user_profile_consent']);

        // Order list columns: legacy posts storage, then HPOS (wc-orders screen).
        add_filter('manage_edit-shop_order_columns', [$this, 'add_order_consent_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'display_order_consent_column'], 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_order_consent_column']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'display_order_consent_column'], 10, 2);

        // Order list filter: legacy posts storage, then HPOS.
        add_action('restrict_manage_posts', [$this, 'add_order_consent_filter']);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'add_hpos_order_consent_filter']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'filter_hpos_orders_query']);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Menu registration. Since 2.0 the "ADC Marketing" menu is registered by
     * ADC_Admin_Shell, so this hook intentionally adds nothing.
     */
    public function add_admin_menu(): void
    {
        // Intentionally empty: see ADC_Admin_Shell::register_menu().
    }

    /**
     * Register the consent message option. The editor and sanitisation live in
     * ADC_Admin_Tab_Marketing; this only keeps the option registered for the Settings API.
     */
    public function init_settings(): void
    {
        register_setting(
            'adc_marketing_settings_group',
            'adc_marketing_consent_message',
            [
                'type' => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'default' => adc_marketing_get_default_consent_message(),
            ]
        );
    }

    /**
     * Display consent in order admin
     *
     * @param WC_Order $order Order object
     */
    public function display_order_consent($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $consent = $order->get_meta('_adc_marketing_consent');
        $consent_status = 'yes' === $consent ? __('Da', 'adc-marketing') : __('Nu', 'adc-marketing');
        $consent_date = (int) $order->get_meta('_adc_marketing_consent_date');
        $consent_text = $order->get_meta('_adc_consent_text_version');

        ?>
        <div class="order_data_column">
            <h3><?php esc_html_e('Consimțământ Marketing', 'adc-marketing'); ?></h3>
            <p>
                <strong><?php esc_html_e('Status:', 'adc-marketing'); ?></strong>
                <?php echo esc_html($consent_status); ?>
            </p>
            <?php if ($consent_date > 0) : ?>
                <p>
                    <strong><?php esc_html_e('Data:', 'adc-marketing'); ?></strong>
                    <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $consent_date)); ?>
                </p>
            <?php endif; ?>
            <?php if ($consent_text) : ?>
                <p>
                    <strong><?php esc_html_e('Text consimțământ:', 'adc-marketing'); ?></strong><br>
                    <?php echo wp_kses_post((string) $consent_text); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Display consent in user profile
     *
     * @param WP_User $user User object
     */
    public function display_user_profile_consent($user): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $consent = get_user_meta($user->ID, '_adc_marketing_consent', true);
        $consent_date = (int) get_user_meta($user->ID, '_adc_marketing_consent_date', true);
        $email_consent = get_user_meta($user->ID, '_adc_marketing_email_consent', true);
        $sms_consent = get_user_meta($user->ID, '_adc_marketing_sms_consent', true);

        ?>
        <h2><?php esc_html_e('Consimțământ Marketing', 'adc-marketing'); ?></h2>
        <table class="form-table">
            <tr>
                <th>
                    <label><?php esc_html_e('Consimțământ general', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <select name="adc_marketing_consent">
                        <option value="no" <?php selected($consent, 'no'); ?>><?php esc_html_e('Nu', 'adc-marketing'); ?></option>
                        <option value="yes" <?php selected($consent, 'yes'); ?>><?php esc_html_e('Da', 'adc-marketing'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>
                    <label><?php esc_html_e('Consimțământ e-mail', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <select name="adc_marketing_email_consent">
                        <option value="no" <?php selected($email_consent, 'no'); ?>><?php esc_html_e('Nu', 'adc-marketing'); ?></option>
                        <option value="yes" <?php selected($email_consent, 'yes'); ?>><?php esc_html_e('Da', 'adc-marketing'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>
                    <label><?php esc_html_e('Consimțământ SMS', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <select name="adc_marketing_sms_consent">
                        <option value="no" <?php selected($sms_consent, 'no'); ?>><?php esc_html_e('Nu', 'adc-marketing'); ?></option>
                        <option value="yes" <?php selected($sms_consent, 'yes'); ?>><?php esc_html_e('Da', 'adc-marketing'); ?></option>
                    </select>
                </td>
            </tr>
            <?php if ($consent_date > 0) : ?>
                <tr>
                    <th>
                        <label><?php esc_html_e('Data consimțământ', 'adc-marketing'); ?></label>
                    </th>
                    <td>
                        <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $consent_date)); ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * Save user profile consent. WordPress verifies the user-edit nonce before
     * firing personal_options_update / edit_user_profile_update.
     *
     * @param int $user_id User ID
     */
    public function save_user_profile_consent(int $user_id): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $consent = $this->yes_no_from_post('adc_marketing_consent');
        $email_consent = $this->yes_no_from_post('adc_marketing_email_consent');
        $sms_consent = $this->yes_no_from_post('adc_marketing_sms_consent');

        update_user_meta($user_id, '_adc_marketing_consent', $consent);
        update_user_meta($user_id, '_adc_marketing_email_consent', $email_consent);
        update_user_meta($user_id, '_adc_marketing_sms_consent', $sms_consent);
        update_user_meta($user_id, '_adc_marketing_consent_date', time());
    }

    /**
     * Add consent column to orders list (legacy and HPOS screens share this filter shape).
     *
     * @param array<string, string> $columns Existing columns
     * @return array<string, string>
     */
    public function add_order_consent_column(array $columns): array
    {
        $new_columns = [];
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ('order_status' === $key) {
                $new_columns['adc_marketing_consent'] = __('Consimțământ Marketing', 'adc-marketing');
            }
        }
        return $new_columns;
    }

    /**
     * Display consent in orders list column.
     *
     * Legacy screens pass a post ID or WP_Post; the HPOS screen passes a WC_Order.
     *
     * @param string $column  Column name
     * @param mixed  $subject WC_Order, WP_Post or post ID
     */
    public function display_order_consent_column(string $column, mixed $subject): void
    {
        if ('adc_marketing_consent' !== $column) {
            return;
        }

        if ($subject instanceof WC_Order) {
            $order = $subject;
        } else {
            $order = wc_get_order($subject instanceof WP_Post ? $subject->ID : (int) $subject);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $consent = $order->get_meta('_adc_marketing_consent');
        if ('yes' === $consent) {
            echo '<span style="color: #46b450; font-size: 18px;">✓</span>';
        }
    }

    /**
     * Legacy posts list: add the consent filter dropdown.
     *
     * @param string $post_type Post type
     */
    public function add_order_consent_filter(string $post_type): void
    {
        if ('shop_order' !== $post_type) {
            return;
        }

        $this->render_consent_filter_select();
    }

    /**
     * HPOS orders list: add the consent filter dropdown.
     */
    public function add_hpos_order_consent_filter(): void
    {
        $this->render_consent_filter_select();
    }

    /**
     * Filter HPOS order list queries by consent status (WC_Order_Query meta_query).
     *
     * @param array<string, mixed> $query_args Query args for the HPOS list table
     * @return array<string, mixed>
     */
    public function filter_hpos_orders_query(array $query_args): array
    {
        $filter = $this->current_consent_filter();

        if ('' !== $filter) {
            $query_args['meta_query'][] = [
                'key' => '_adc_marketing_consent',
                'value' => $filter,
                'compare' => '=',
            ];
        }

        return $query_args;
    }

    /**
     * Enqueue admin assets on the ADC Marketing page only.
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets(string $hook): void
    {
        if ('toplevel_page_adc-marketing' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'adc-marketing-admin',
            ADC_MARKETING_PLUGIN_URL . 'assets/css/admin-settings.css',
            [],
            ADC_MARKETING_VERSION
        );

        wp_enqueue_script(
            'adc-marketing-admin',
            ADC_MARKETING_PLUGIN_URL . 'assets/js/admin-export.js',
            [],
            ADC_MARKETING_VERSION,
            [
                'in_footer' => true,
                'strategy' => 'defer',
            ]
        );

        // UI copy is supplied by PHP so the script stays translation-free.
        wp_localize_script(
            'adc-marketing-admin',
            'adcExportI18n',
            [
                'confirmRange' => __('Sunteți sigur că doriți să exportați utilizatorii pentru perioada selectată?', 'adc-marketing'),
            ]
        );
    }

    /**
     * Render the consent filter select used by both order list screens.
     */
    private function render_consent_filter_select(): void
    {
        $current_filter = $this->current_consent_filter();

        ?>
        <select name="adc_marketing_consent_filter" id="adc_marketing_consent_filter">
            <option value=""><?php esc_html_e('Toate comenzile', 'adc-marketing'); ?></option>
            <option value="yes" <?php selected($current_filter, 'yes'); ?>>
                <?php esc_html_e('Cu consimțământ marketing', 'adc-marketing'); ?>
            </option>
            <option value="no" <?php selected($current_filter, 'no'); ?>>
                <?php esc_html_e('Fără consimțământ marketing', 'adc-marketing'); ?>
            </option>
        </select>
        <?php
    }

    /**
     * Read the consent list filter from the query string (read-only).
     *
     * @return string 'yes', 'no' or '' when no valid filter is set.
     */
    private function current_consent_filter(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, no state change.
        $value = isset($_GET['adc_marketing_consent_filter']) ? sanitize_text_field(wp_unslash((string) $_GET['adc_marketing_consent_filter'])) : '';

        return in_array($value, ['yes', 'no'], true) ? $value : '';
    }

    /**
     * Read a yes/no checkbox-style select from the profile form.
     *
     * @param string $key POST field name.
     * @return string 'yes' or 'no'.
     */
    private function yes_no_from_post(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by WordPress before this hook.
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : 'no';

        return 'yes' === $value ? 'yes' : 'no';
    }
}
