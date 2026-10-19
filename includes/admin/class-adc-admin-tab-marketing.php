<?php
/**
 * "Mesaje marketing & Export" tab of the ADC Marketing admin shell.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consent message editor, CSV export and GDPR status (docs/ARCHITECTURE.md §7, item 3).
 *
 * The shell wraps render() in its own <form>, so this tab must not output a
 * form tag. Export actions are plain links to admin-post.php carrying a nonce;
 * the date range is appended by admin-export.js.
 */
final class ADC_Admin_Tab_Marketing implements ADC_Admin_Tab_Interface
{
    /**
     * Option holding the checkout consent message (source of truth, unchanged since 1.0).
     */
    private const MESSAGE_OPTION = 'adc_marketing_consent_message';

    /**
     * Tab slug (URL: admin.php?page=adc-marketing&tab=marketing).
     *
     * @return string
     */
    public function slug(): string
    {
        return 'marketing';
    }

    /**
     * Tab label shown in the navigation.
     *
     * @return string
     */
    public function label(): string
    {
        return __('Mesaje marketing & Export', 'adc-marketing');
    }

    /**
     * Top-level tab (not part of the integrations sub-nav).
     *
     * @return string
     */
    public function group(): string
    {
        return '';
    }

    /**
     * Sort order in the navigation.
     *
     * @return int
     */
    public function order(): int
    {
        return 80;
    }

    /**
     * Render the message editor, export controls and GDPR status table.
     */
    public function render(): void
    {
        $message = (string) get_option(self::MESSAGE_OPTION, adc_marketing_get_default_consent_message());
        $compliance = ADC_GDPR_Compliance::get_instance()->verify_compliance();

        ?>
        <h2><?php esc_html_e('Mesaj consimțământ la finalizarea comenzii', 'adc-marketing'); ?></h2>
        <p><?php esc_html_e('Personalizați mesajul de consimțământ afișat la finalizarea comenzii.', 'adc-marketing'); ?></p>
        <?php
        wp_editor(
            $message,
            self::MESSAGE_OPTION,
            array(
                'textarea_name' => self::MESSAGE_OPTION,
                'textarea_rows' => 5,
                'media_buttons' => false,
                'teeny'         => true,
                'tinymce'       => array(
                    'toolbar1' => 'bold,italic,underline,link,unlink',
                    'toolbar2' => '',
                ),
            )
        );
        ?>
        <p class="description"><?php esc_html_e('Puteți folosi shortcode-ul [privacy-policy-link] pentru link-ul către Politica de Confidențialitate.', 'adc-marketing'); ?></p>

        <hr>

        <h2><?php esc_html_e('Export bază de date consimțământ', 'adc-marketing'); ?></h2>
        <p><?php esc_html_e('Exportați utilizatorii care au sau nu au consimțit la marketing.', 'adc-marketing'); ?></p>

        <div class="adc-export-form" id="adc-export-form">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="adc_export_start_date"><?php esc_html_e('Data început', 'adc-marketing'); ?></label>
                    </th>
                    <td>
                        <input type="date" id="adc_export_start_date" name="adc_export_start_date" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="adc_export_end_date"><?php esc_html_e('Data sfârșit', 'adc-marketing'); ?></label>
                    </th>
                    <td>
                        <input type="date" id="adc_export_end_date" name="adc_export_end_date" class="regular-text" />
                    </td>
                </tr>
            </table>

            <p>
                <a class="button button-primary"
                   data-adc-export="1"
                   href="<?php echo esc_url($this->export_url('adc_export_consented_users')); ?>">
                    <?php esc_html_e('Exportă utilizatori care au consimțit', 'adc-marketing'); ?>
                </a>
                <a class="button"
                   data-adc-export="1"
                   href="<?php echo esc_url($this->export_url('adc_export_non_consented_users')); ?>">
                    <?php esc_html_e('Exportă utilizatori care NU au consimțit', 'adc-marketing'); ?>
                </a>
            </p>
        </div>

        <hr>

        <h2><?php esc_html_e('Status Conformitate GDPR', 'adc-marketing'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Politică de Confidențialitate', 'adc-marketing'); ?></th>
                <td>
                    <?php if ($compliance['privacy_policy_set']) : ?>
                        <span style="color: #46b450;">✓</span> <?php esc_html_e('Setată', 'adc-marketing'); ?>
                        <?php if (!empty($compliance['privacy_policy_url'])) : ?>
                            <a href="<?php echo esc_url((string) $compliance['privacy_policy_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Vezi pagina', 'adc-marketing'); ?></a>
                        <?php endif; ?>
                    <?php else : ?>
                        <span style="color: #dc3232;">✗</span> <?php esc_html_e('Nu este setată', 'adc-marketing'); ?>
                        <a href="<?php echo esc_url(admin_url('options-privacy.php')); ?>"><?php esc_html_e('Setează acum', 'adc-marketing'); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Consimțământ opțional', 'adc-marketing'); ?></th>
                <td>
                    <span style="color: #46b450;">✓</span> <?php esc_html_e('Da - checkbox-ul este opțional', 'adc-marketing'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Consimțământ explicit', 'adc-marketing'); ?></th>
                <td>
                    <span style="color: #46b450;">✓</span> <?php esc_html_e('Da - checkbox-ul este nebifat implicit', 'adc-marketing'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Retragere consimțământ', 'adc-marketing'); ?></th>
                <td>
                    <span style="color: #46b450;">✓</span> <?php esc_html_e('Da - utilizatorii pot retrage consimțământul din contul lor', 'adc-marketing'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Portabilitate date', 'adc-marketing'); ?></th>
                <td>
                    <span style="color: #46b450;">✓</span> <?php esc_html_e('Da - funcție de export disponibilă, inclusiv în uneltele de export ale WordPress', 'adc-marketing'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Ștergere date', 'adc-marketing'); ?></th>
                <td>
                    <span style="color: #46b450;">✓</span> <?php esc_html_e('Da - datele sunt șterse automat la ștergerea utilizatorului sau la cererea de ștergere', 'adc-marketing'); ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the consent message. The shell has already checked the nonce and capability.
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        $message = isset($post[self::MESSAGE_OPTION]) ? wp_kses_post((string) $post[self::MESSAGE_OPTION]) : '';
        update_option(self::MESSAGE_OPTION, $message);

        return array(
            array(
                'type'    => 'success',
                'message' => __('Setările au fost salvate cu succes.', 'adc-marketing'),
            ),
        );
    }

    /**
     * Build an export URL for admin-post.php with the export nonce attached.
     *
     * @param string $action admin-post action name.
     * @return string
     */
    private function export_url(string $action): string
    {
        $url = add_query_arg('action', $action, admin_url('admin-post.php'));

        return wp_nonce_url($url, 'adc_export_nonce', 'adc_export_nonce');
    }
}
