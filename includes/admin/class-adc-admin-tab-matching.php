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
    use ADC_Admin_Help_Content;

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
        ADC_Admin_Help::section(
            __('Potrivire avansată', 'adc-marketing'),
            __('Ce face această secțiune: pregătește datele de contact ale clienților (hash) pentru ca platformele de reclamă să atribuie comenzile campaniilor corect.', 'adc-marketing'),
            'adc-help-matching',
            $this->help_matching()
        );

        echo '<div class="adc-help-options">';

        ADC_Admin_Help::row(
            __('Activare potrivire avansată', 'adc-marketing'),
            '<label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($s['enabled']), false, false) . ' /> ' . esc_html__('Activează potrivirea avansată (implicit dezactivat)', 'adc-marketing') . '</label>',
            __('Pornește întregul mecanism de colectare și hash; cu opțiunea oprită, niciuna dintre setările de mai jos nu are efect.', 'adc-marketing'),
            null,
            'adc-help-matching-enabled',
            $this->help_matching()
        );

        ADC_Admin_Help::row(
            __('Colectare la checkout', 'adc-marketing'),
            '<label><input type="checkbox" name="capture_checkout" value="1" ' . checked(!empty($s['capture_checkout']), false, false) . ' /> ' . esc_html__('Captează e-mailul și telefonul din câmpurile de checkout (la fiecare reîmprospătare a datelor)', 'adc-marketing') . '</label>',
            __('Citește câmpurile pe măsură ce clientul le completează; fără această bifă, datele se iau doar din comanda plasată.', 'adc-marketing')
        );

        ADC_Admin_Help::row(
            __('Colectare din cont / autentificare', 'adc-marketing'),
            '<label><input type="checkbox" name="capture_account" value="1" ' . checked(!empty($s['capture_account']), false, false) . ' /> ' . esc_html__('Captează datele din contul de utilizator la crearea contului și la autentificare', 'adc-marketing') . '</label>',
            __('Permite potrivirea evenitmentelor și din afara checkout-ului (cont nou, login), nu doar de la comandă.', 'adc-marketing')
        );

        ADC_Admin_Help::row(
            __('Prefix telefonic implicit', 'adc-marketing'),
            '<input type="text" id="adc-matching-calling-code" name="calling_code" value="' . esc_attr((string) $s['calling_code']) . '" class="small-text" maxlength="5" />',
            __('Cu ce prefix de țară se completează numerele de telefon scrise fără „+” înainte de hash (ex.: 40 pentru România).', 'adc-marketing'),
            'adc-matching-calling-code'
        );

        ADC_Admin_Help::row(
            __('Selectoare formulare', 'adc-marketing'),
            '<textarea id="adc-matching-form-selectors" name="form_selectors" rows="5" class="large-text code">' . esc_textarea((string) $s['form_selectors']) . '</textarea>',
            __('Formulare externe (newsletter, contact) care, la trimitere, declanșează evenimentul „generate_lead” și captarea datelor de identificare.', 'adc-marketing'),
            'adc-matching-form-selectors'
        );

        $platforms = '';
        foreach (self::SEND_TO_LABELS as $platform => $label) {
            $platforms .= '<label class="adc-help-choice"><input type="checkbox" name="send_to[]" value="' . esc_attr($platform) . '" ' . checked(in_array($platform, (array) $s['send_to'], true), false, false) . ' /> ' . esc_html($label) . '</label>';
        }
        ADC_Admin_Help::row(
            __('Platforme destinație', 'adc-marketing'),
            $platforms,
            __('Doar platformele bifate primesc hash-urile de identificare; celelalte primesc evenimentele fără aceste date.', 'adc-marketing'),
            null,
            'adc-help-matching-send-to',
            $this->help_matching()
        );

        ADC_Admin_Help::row(
            __('Identificator extern', 'adc-marketing'),
            '<label><input type="checkbox" name="use_external_id" value="1" ' . checked(!empty($s['use_external_id']), false, false) . ' /> ' . esc_html__('Trimite un identificator extern criptat (sha256 al ID-ului de utilizator sau al cid-ului de consimțământ)', 'adc-marketing') . '</label>',
            __('Adaugă un ID stabil, criptat (util pentru public custom-uri pe Meta/TikTok); se trimite doar hash-ul, nu ID-ul brut.', 'adc-marketing')
        );

        $datalayer_control = '<select id="adc-matching-datalayer-format" name="datalayer_format">'
            . '<option value="hashed" ' . selected('hashed', (string) $s['datalayer_format'], false) . '>' . esc_html__('Criptat (sha256_*) — implicit', 'adc-marketing') . '</option>'
            . '<option value="plain" ' . selected('plain', (string) $s['datalayer_format'], false) . '>' . esc_html__('Text clar (tag-ul GTM criptează)', 'adc-marketing') . '</option>'
            . '</select>';
        if ('plain' === (string) $s['datalayer_format']) {
            $datalayer_control .= '<div class="notice notice-warning inline" style="margin: 4px 0 0;"><p>' . esc_html__('Atenție: formatul „text clar” expune e-mailul, telefonul și numele în text necifrat în dataLayer, unde orice tag GTM sau script terț de pe pagină le poate citi. Recomandăm formatul „criptat (sha256_*)”.', 'adc-marketing') . '</p></div>';
        }
        ADC_Admin_Help::row(
            __('Format în dataLayer', 'adc-marketing'),
            $datalayer_control,
            __('În ce formă apar datele în stratul de date din browser: hash (implicit, sigur) sau text clar (doar cu consimțământ de marketing, criptate de tag).', 'adc-marketing'),
            'adc-matching-datalayer-format'
        );

        echo '</div>';
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
