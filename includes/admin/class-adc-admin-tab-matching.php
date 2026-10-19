<?php
/**
 * Admin tab "Potrivire avansată" (docs/ARCHITECTURE.md §2.1, §4).
 *
 * Settings for the `matching` group. The group itself is registered by
 * ADC_Advanced_Matching; this tab only renders + saves it through
 * ADC_Settings::update.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced matching admin tab.
 */
final class ADC_Admin_Tab_Matching implements ADC_Admin_Tab_Interface
{
    /**
     * Platforms that may receive matched (hashed) identity data.
     *
     * @var array<string, string>
     */
    private const SEND_TO_LABELS = array(
        'meta'   => 'Meta (CAPI)',
        'tiktok' => 'TikTok (Events API)',
        'ga4'    => 'GA4 (Measurement Protocol)',
    );

    /**
     * Tab slug.
     */
    public function slug(): string
    {
        return 'matching';
    }

    /**
     * Tab label (RO).
     */
    public function label(): string
    {
        return __('Potrivire avansată', 'adc-marketing');
    }

    /**
     * Top-level tab.
     */
    public function group(): string
    {
        return '';
    }

    /**
     * Sort order (§2.1: matching 70).
     */
    public function order(): int
    {
        return 70;
    }

    /**
     * Render the tab inner HTML (no form tag, no nonce — the shell wraps them).
     */
    public function render(): void
    {
        $s = ADC_Settings::all('matching');
        ?>
        <h2><?php esc_html_e('Potrivire avansată', 'adc-marketing'); ?></h2>
        <p class="description">
            <?php esc_html_e('Colectează date de identificare (e-mail, telefon, nume) imediat ce câmpurile de checkout sunt completate, le normalizează și le criptează SHA-256 înainte de a le trimite platformelor. Datele brute nu sunt niciodată stocate; sesiunea păstrează doar valori criptate. Trimiterea are loc doar cu consimțământ pentru categoria „marketing”.', 'adc-marketing'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Activare potrivire avansată', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?> />
                        <?php esc_html_e('Activează potrivirea avansată (implicit dezactivat)', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Colectare la checkout', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="capture_checkout" value="1" <?php checked(!empty($s['capture_checkout'])); ?> />
                        <?php esc_html_e('Captează e-mailul și telefonul din câmpurile de checkout (la fiecare reîmprospătare a datelor)', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Colectare din cont / autentificare', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="capture_account" value="1" <?php checked(!empty($s['capture_account'])); ?> />
                        <?php esc_html_e('Captează datele din contul de utilizator la crearea contului și la autentificare', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-matching-calling-code"><?php esc_html_e('Prefix telefonic implicit', 'adc-marketing'); ?></label></th>
                <td>
                    <input type="text" id="adc-matching-calling-code" name="calling_code" value="<?php echo esc_attr((string) $s['calling_code']); ?>" class="small-text" maxlength="5" />
                    <p class="description"><?php esc_html_e('Prefixul de țară folosit pentru normalizarea numerelor de telefon fără „+” (ex.: 40 pentru România).', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-matching-form-selectors"><?php esc_html_e('Selectoare formulare', 'adc-marketing'); ?></label></th>
                <td>
                    <textarea id="adc-matching-form-selectors" name="form_selectors" rows="5" class="large-text code"><?php echo esc_textarea((string) $s['form_selectors']); ?></textarea>
                    <p class="description"><?php esc_html_e('Selectoare CSS, câte unul pe linie, pentru formulare externe (newsletter, contact). La trimiterea acestora se emite și evenimentul „generate_lead”.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Platforme destinație', 'adc-marketing'); ?></th>
                <td>
                    <fieldset>
                        <?php foreach (self::SEND_TO_LABELS as $platform => $label) : ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="send_to[]" value="<?php echo esc_attr($platform); ?>" <?php checked(in_array($platform, (array) $s['send_to'], true)); ?> />
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e('Doar platformele bifate primesc datele de identificare criptate. Celelalte platforme primesc evenimentele fără aceste date.', 'adc-marketing'); ?></p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Identificator extern', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="use_external_id" value="1" <?php checked(!empty($s['use_external_id'])); ?> />
                        <?php esc_html_e('Trimite un identificator extern criptat (sha256 al ID-ului de utilizator sau al cid-ului de consimțământ)', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-matching-datalayer-format"><?php esc_html_e('Format în dataLayer', 'adc-marketing'); ?></label></th>
                <td>
                    <select id="adc-matching-datalayer-format" name="datalayer_format">
                        <option value="hashed" <?php selected('hashed', (string) $s['datalayer_format']); ?>><?php esc_html_e('Criptat (sha256_*) — implicit', 'adc-marketing'); ?></option>
                        <option value="plain" <?php selected('plain', (string) $s['datalayer_format']); ?>><?php esc_html_e('Text clar (tag-ul GTM criptează)', 'adc-marketing'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Formatul „text clar” transmite valorile necifrate în dataLayer doar după consimțământul de marketing; tag-ul Google le criptează. Implicit rămâne „criptat”.', 'adc-marketing'); ?></p>
                    <?php if ('plain' === (string) $s['datalayer_format']) : ?>
                        <div class="notice notice-warning inline" style="margin: 4px 0 0;">
                            <p><?php esc_html_e('Atenție: formatul „text clar” expune e-mailul, telefonul și numele în text necifrat în dataLayer, unde orice tag GTM sau script terț de pe pagină le poate citi. Recomandăm formatul „criptat (sha256_*)”.', 'adc-marketing'); ?></p>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save via ADC_Settings::update (sanitize callback in ADC_Advanced_Matching).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        $input = array(
            'enabled'          => !empty($post['enabled']),
            'capture_checkout' => !empty($post['capture_checkout']),
            'capture_account'  => !empty($post['capture_account']),
            'calling_code'     => (string) ($post['calling_code'] ?? ''),
            'form_selectors'   => (string) ($post['form_selectors'] ?? ''),
            'send_to'          => isset($post['send_to']) && is_array($post['send_to']) ? $post['send_to'] : array(),
            'use_external_id'  => !empty($post['use_external_id']),
            'datalayer_format' => (string) ($post['datalayer_format'] ?? ''),
        );

        ADC_Settings::update('matching', $input);

        $notices = array(
            array(
                'type'    => 'success',
                'message' => __('Setările de potrivire avansată au fost salvate.', 'adc-marketing'),
            ),
        );

        // D21/L5: selecting the plain format is an explicit opt-in — warn
        // that raw PII becomes readable by any tag/script on the page.
        if ('plain' === (string) ($input['datalayer_format'] ?? '')) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Ai activat formatul „text clar” în dataLayer: e-mailul, telefonul și numele vor fi expuse necifrate oricărui tag GTM sau script terț de pe pagină. Recomandăm revenirea la formatul „criptat”.', 'adc-marketing'),
            );
        }

        return $notices;
    }
}
