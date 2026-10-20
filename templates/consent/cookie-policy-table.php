<?php
/**
 * Overridable cookie-policy inventory table.
 *
 * Variables: $groups (category labels and rows), $intro (localized introduction).
 * Theme override: {theme}/adc-marketing/consent/cookie-policy-table.php.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="adc-cookie-policy-table">
    <p class="adc-cookie-policy-intro"><?php echo esc_html($intro); ?></p>
    <?php foreach ($groups as $group) : ?>
        <h3><?php echo esc_html($group['label']); ?></h3>
        <div class="adc-cookie-table-wrap">
            <table class="adc-cookie-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Nume', 'adc-marketing'); ?></th>
                        <th scope="col"><?php esc_html_e('Furnizor', 'adc-marketing'); ?></th>
                        <th scope="col"><?php esc_html_e('Scop', 'adc-marketing'); ?></th>
                        <th scope="col"><?php esc_html_e('Durată', 'adc-marketing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group['rows'] as $row) : ?>
                        <?php
                        $name = (string) ($row['name'] ?? '');
                        if (!empty($row['wildcard']) && !str_ends_with($name, '*')) {
                            $name .= '*';
                        }
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($name !== '' ? $name : '—'); ?></code></td>
                            <td><?php echo esc_html((string) ($row['provider'] ?? '') ?: '—'); ?></td>
                            <td><?php echo esc_html((string) ($row['purpose'] ?? '') ?: '—'); ?></td>
                            <td><?php echo esc_html((string) ($row['duration'] ?? '') ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>
