<?php
/**
 * Cookie inventory table (inside the preferences modal).
 *
 * Variables: $config (see ADC_Consent_Banner::view_config()).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<details class="adc-consent-cookies">
    <summary><?php esc_html_e('Lista cookie-urilor', 'adc-marketing'); ?></summary>
    <?php if (empty($config['inventory'])) : ?>
        <p><?php esc_html_e('Nu există cookie-uri înregistrate.', 'adc-marketing'); ?></p>
    <?php else : ?>
        <div class="adc-consent-table-wrap">
            <table class="adc-consent-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Nume', 'adc-marketing'); ?></th>
                        <th scope="col"><?php esc_html_e('Categorie', 'adc-marketing'); ?></th>
                        <th scope="col"><?php esc_html_e('Furnizor', 'adc-marketing'); ?></th>
                        <th scope="col"><?php esc_html_e('Scop', 'adc-marketing'); ?></th>
                        <th scope="col"><?php esc_html_e('Durată', 'adc-marketing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($config['inventory'] as $row) : ?>
                        <tr>
                            <td><code><?php echo esc_html($row['name']); ?></code></td>
                            <td><?php echo esc_html($row['category']); ?></td>
                            <td><?php echo esc_html($row['provider']); ?></td>
                            <td><?php echo esc_html($row['purpose']); ?></td>
                            <td><?php echo esc_html($row['duration']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</details>
