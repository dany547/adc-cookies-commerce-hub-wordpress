<?php
/**
 * Cookie banner markup. Static and identical for every visitor; consent-banner.js
 * applies the visitor's decision on the client.
 *
 * Variables: $config (see ADC_Consent_Banner::view_config()).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$adc_layout      = (string) ($config['layout'] ?? 'bar_bottom');
$adc_preset      = (string) ($config['preset'] ?? 'custom');
$adc_style       = (string) ($config['style'] ?? '');
$adc_is_modal    = in_array($adc_layout, array('center_modal', 'modal'), true);
$adc_overlay     = isset($config['overlay']) ? (bool) $config['overlay'] : $adc_is_modal;
$adc_labels      = isset($config['labels']) && is_array($config['labels']) ? $config['labels'] : array();
$adc_actions     = isset($config['actions']) && is_array($config['actions']) ? $config['actions'] : array();

// Fallback for overridden templates without the configured order: Acceptă · Setări · Respinge.
if (empty($adc_actions)) {
    $adc_actions = array(
        array('action' => 'accept', 'class' => 'adc-consent-btn--equal', 'label' => (string) ($adc_labels['accept'] ?? '')),
        array('action' => 'customize', 'class' => 'adc-consent-btn--link', 'label' => (string) ($adc_labels['customize'] ?? '')),
        array('action' => 'reject', 'class' => 'adc-consent-btn--equal', 'label' => (string) ($adc_labels['reject'] ?? '')),
    );
}
?>
<?php /* Scope carries the same custom properties so the modal and the FAB inherit them. */ ?>
<div class="adc-consent-scope" style="<?php echo esc_attr($adc_style); ?>">
<div id="adc-consent-banner" class="adc-consent-banner adc-consent-banner--<?php echo esc_attr($adc_layout); ?>" data-layout="<?php echo esc_attr($adc_layout); ?>" data-preset="<?php echo esc_attr($adc_preset); ?>" data-overlay="<?php echo $adc_overlay ? '1' : '0'; ?>" style="<?php echo esc_attr($adc_style); ?>" role="dialog" aria-labelledby="adc-consent-banner-title" aria-describedby="adc-consent-banner-message" hidden>
    <div class="adc-consent-banner__inner">
        <h2 id="adc-consent-banner-title" class="adc-consent-banner__title"><?php echo esc_html($config['title']); ?></h2>
        <div id="adc-consent-banner-message" class="adc-consent-banner__message"><?php echo wp_kses_post($config['message_html']); ?></div>
        <div class="adc-consent-banner__actions">
            <?php foreach ($adc_actions as $adc_action) : ?>
                <button type="button" class="adc-consent-btn <?php echo esc_attr((string) $adc_action['class']); ?>" data-adc-action="<?php echo esc_attr((string) $adc_action['action']); ?>"<?php echo 'customize' === $adc_action['action'] ? ' aria-controls="adc-consent-modal"' : ''; ?>><?php echo esc_html((string) $adc_action['label']); ?></button>
            <?php endforeach; ?>
        </div>
        <?php if ('' !== $config['privacy_url']) : ?>
            <p class="adc-consent-banner__policy">
                <a href="<?php echo esc_url($config['privacy_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Politica de Confidențialitate', 'adc-marketing'); ?></a>
            </p>
        <?php endif; ?>
    </div>
</div>
<?php ADC_Template::render('consent/preferences.php', array('config' => $config)); ?>
<?php if ($config['show_fab']) : ?>
    <button type="button" class="adc-consent-fab" data-adc-open-preferences aria-controls="adc-consent-modal" hidden>
        <span aria-hidden="true">&#9776;</span>
        <span class="adc-consent-fab__label"><?php esc_html_e('Cookie-uri', 'adc-marketing'); ?></span>
    </button>
<?php endif; ?>
</div>
