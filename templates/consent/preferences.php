<?php
/**
 * Cookie preferences modal: one switch per category, accept/save/reject in the
 * same reading order as the banner, accept and reject with equal weight.
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
<div id="adc-consent-modal" class="adc-consent-modal" role="dialog" aria-modal="true" aria-labelledby="adc-consent-modal-title" hidden>
    <div class="adc-consent-modal__dialog">
        <div class="adc-consent-modal__header">
            <h2 id="adc-consent-modal-title" class="adc-consent-modal__title"><?php esc_html_e('Setări cookie-uri', 'adc-marketing'); ?></h2>
            <button type="button" class="adc-consent-btn adc-consent-btn--icon" data-adc-action="close" aria-label="<?php esc_attr_e('Închide', 'adc-marketing'); ?>">&times;</button>
        </div>
        <p class="adc-consent-modal__intro"><?php esc_html_e('Alegeți categoriile de cookie-uri pe care le acceptați. Cookie-urile necesare nu pot fi dezactivate.', 'adc-marketing'); ?></p>

        <ul class="adc-consent-categories">
            <?php foreach ($config['categories'] as $category) : ?>
                <?php $input_id = 'adc-cat-' . $category['key']; ?>
                <li class="adc-consent-category">
                    <div class="adc-consent-category__head">
                        <label class="adc-consent-category__label" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($category['label']); ?></label>
                        <input type="checkbox" id="<?php echo esc_attr($input_id); ?>" data-adc-category="<?php echo esc_attr($category['key']); ?>"<?php if ('necessary' === $category['key']) : ?> checked disabled<?php endif; ?> />
                    </div>
                    <p class="adc-consent-category__desc"><?php echo esc_html($category['description']); ?></p>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="adc-consent-modal__actions">
            <button type="button" class="adc-consent-btn adc-consent-btn--equal" data-adc-action="accept"><?php echo esc_html($config['labels']['accept']); ?></button>
            <button type="button" class="adc-consent-btn adc-consent-btn--secondary" data-adc-action="save"><?php echo esc_html($config['labels']['save']); ?></button>
            <button type="button" class="adc-consent-btn adc-consent-btn--equal" data-adc-action="reject"><?php echo esc_html($config['labels']['reject']); ?></button>
        </div>

        <?php ADC_Template::render('consent/cookie-list.php', array('config' => $config)); ?>
    </div>
</div>
