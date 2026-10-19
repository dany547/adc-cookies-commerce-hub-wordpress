<?php
/**
 * Cookie banner, preferences modal and cookie-manager frontend (docs/ARCHITECTURE.md §1.3, §2.3, §4).
 *
 * Renders static markup only, identical for every visitor (cache-safe). The
 * visitor's decision is read client-side by consent-manager.js and is never
 * printed into the HTML.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the `banner` settings group, the frontend assets, the banner markup and
 * the cookie-preferences shortcodes.
 */
final class ADC_Consent_Banner implements ADC_Module_Interface
{
    /**
     * Script handle of the window.adcConsent API (loaded in <head>).
     */
    public const SCRIPT_HANDLE = 'adc-consent';

    /**
     * Script and style handle of the banner UI.
     */
    public const UI_HANDLE = 'adc-consent-banner';

    /**
     * Cookie categories in display order (fixed, no filter).
     */
    public const CATEGORIES = array('necessary', 'preferences', 'statistics', 'marketing');

    /** CSS color variables supported by banner themes. */
    public const COLOR_KEYS = array(
        'bg', 'text', 'muted', 'border', 'link', 'accept_bg', 'accept_text',
        'reject_bg', 'reject_text', 'customize_bg', 'customize_text'
    );

    /** Allowed banner layouts (legacy slugs remain valid). */
    public const LAYOUTS = array('bar_bottom', 'box_bottom_left', 'box_bottom_right', 'box_bottom_center', 'center_modal', 'bar_top', 'modal');

    /**
     * Default banner button order: Acceptă · Setări · Respinge (§4 `button_order`).
     */
    public const DEFAULT_BUTTON_ORDER = 'accept_customize_reject';

    /** Extra spacing after the Accept button, in px (clamped; CSS caps it further). */
    public const ACCEPT_GAP_MIN     = 0;
    public const ACCEPT_GAP_MAX     = 48;
    public const ACCEPT_GAP_DEFAULT = 16;

    /**
     * Audit thresholds for the Accept/Reject prominence check (see
     * prominence_audit()). 4.5:1 and 3:1 are the WCAG AA text / non-text limits;
     * 1.35:1 is the point under which a filled button no longer reads as a
     * separate control, and 4x is the accept-vs-reject visibility ratio that
     * turns "equal prominence" into a dark pattern.
     */
    public const AUDIT_TEXT_CONTRAST = 4.5;
    public const AUDIT_UI_CONTRAST   = 3.0;
    public const AUDIT_BLEND_CONTRAST = 1.35;
    public const AUDIT_PROMINENCE_GAP = 4.0;

    /** @return array<string, array{label: string, actions: string[]}> Slug => button order. */
    public static function button_orders(): array
    {
        return array(
            self::DEFAULT_BUTTON_ORDER => array(
                'label'   => __('Acceptă · Setări · Respinge (implicit)', 'adc-marketing'),
                'actions' => array('accept', 'customize', 'reject'),
            ),
            'customize_accept_reject' => array(
                'label'   => __('Setări · Acceptă · Respinge', 'adc-marketing'),
                'actions' => array('customize', 'accept', 'reject'),
            ),
            'accept_reject_customize' => array(
                'label'   => __('Acceptă · Respinge · Setări', 'adc-marketing'),
                'actions' => array('accept', 'reject', 'customize'),
            ),
        );
    }

    /**
     * Banner buttons in the configured order. The returned order is the DOM
     * order, so reading order, tab order and the visual order stay identical.
     *
     * @param array $settings Banner group values.
     * @return array<int, array{action: string, label: string, class: string}>
     */
    public static function action_buttons(array $settings): array
    {
        $orders = self::button_orders();
        $order  = (string) ($settings['button_order'] ?? self::DEFAULT_BUTTON_ORDER);
        $keys   = isset($orders[$order]) ? $orders[$order]['actions'] : $orders[self::DEFAULT_BUTTON_ORDER]['actions'];

        $classes = array(
            'accept'    => 'adc-consent-btn--equal',
            'reject'    => 'adc-consent-btn--equal',
            'customize' => 'adc-consent-btn--link',
        );
        $labels = array(
            'accept'    => (string) ($settings['accept_label'] ?? ''),
            'reject'    => (string) ($settings['reject_label'] ?? ''),
            'customize' => (string) ($settings['customize_label'] ?? ''),
        );

        $buttons = array();
        foreach ($keys as $key) {
            $buttons[] = array(
                'action' => $key,
                'label'  => $labels[$key],
                'class'  => $classes[$key],
            );
        }

        return $buttons;
    }

    /** @return array<string, string> */
    public static function layouts(): array
    {
        return array(
            'bar_bottom' => __('Bară jos (toată lățimea)', 'adc-marketing'),
            'box_bottom_left' => __('Casetă jos stânga', 'adc-marketing'),
            'box_bottom_right' => __('Casetă jos dreapta', 'adc-marketing'),
            'box_bottom_center' => __('Casetă jos centru', 'adc-marketing'),
            'center_modal' => __('Fereastră centrată', 'adc-marketing'),
            'bar_top' => __('Bară sus', 'adc-marketing'),
        );
    }

    /**
     * Preset colour catalogue.
     *
     * @param string $accent Brand accent for the "brand" preset; '' reads the stored value.
     * @return array<string, array{label: string, colors: array<string, string>, radius: int}>
     */
    public static function presets(string $accent = ''): array
    {
        $sets = array(
            'light' => array('#ffffff', '#172033', '#526078', '#d5dbe5', '#174ea6', '#174ea6', '#ffffff', '#354052', '#ffffff', '#ffffff', '#253149', 8),
            'dark' => array('#171923', '#f7f8fa', '#c2c7d0', '#454957', '#a9c7ff', '#a9c7ff', '#111827', '#d8dee9', '#111827', '#171923', '#f7f8fa', 8),
            'minimal' => array('#ffffff', '#111111', '#444444', '#777777', '#111111', '#111111', '#ffffff', '#444444', '#ffffff', '#ffffff', '#111111', 0),
            'contrast' => array('#ffffff', '#000000', '#222222', '#000000', '#0000a8', '#000000', '#ffffff', '#333333', '#ffffff', '#ffffff', '#000000', 2),
        );
        $result = array();
        foreach ($sets as $slug => $v) {
            $result[$slug] = array('label' => __(array('light' => 'Luminos', 'dark' => 'Întunecat', 'minimal' => 'Minimal (monocrom)', 'contrast' => 'Contrast ridicat (WCAG AAA)')[$slug], 'adc-marketing'), 'colors' => array_combine(self::COLOR_KEYS, array_slice($v, 0, 11)), 'radius' => $v[11]);
        }
        $accent  = self::normalize_hex($accent);
        $stored  = self::normalize_hex((string) ADC_Settings::get('banner', 'accent', '#2563eb'));
        $accent  = '' !== $accent ? $accent : ($stored ?: '#2563eb');
        $buttonText = self::contrast_ratio($accent, '#ffffff') >= self::contrast_ratio($accent, '#000000') ? '#ffffff' : '#000000';
        $brand = $result['light']['colors'];
        $brand['link'] = $accent;
        // Accept takes the brand colour; Refuză keeps the neutral light-preset
        // background, so the two choices stay distinguishable but equally prominent.
        $brand['accept_bg'] = $accent;
        $brand['accept_text'] = $buttonText;
        $result['brand'] = array('label' => __('Culoare brand', 'adc-marketing'), 'colors' => $brand, 'radius' => 8);
        return $result;
    }

    /**
     * Audit thresholds + Romanian findings, shared between prominence_audit()
     * and the admin live preview (exposed as `window.adcBannerCompliance`).
     *
     * Message placeholders per code: `reject_blends` = %1$s reject background,
     * %2$s banner background, %3$s ratio; `reject_faint`, `prominence_gap` and
     * `reject_illegible` = %1$s reject/accept side, %2$s the other side. The
     * reject side always has to stay strictly worse than the accept side before
     * anything is reported: a palette where both buttons are hard to read is a
     * contrast problem (see the WCAG warning under each colour group), not a
     * dark pattern.
     *
     * @return array{blendContrast: float, uiContrast: float, textContrast: float, prominenceGap: float, messages: array<string, string>}
     */
    public static function audit_rules(): array
    {
        return array(
            'blendContrast' => self::AUDIT_BLEND_CONTRAST,
            'uiContrast'    => self::AUDIT_UI_CONTRAST,
            'textContrast'  => self::AUDIT_TEXT_CONTRAST,
            'prominenceGap' => self::AUDIT_PROMINENCE_GAP,
            'messages'      => array(
                'indistinguishable' => __('Butoanele „Acceptă” și „Refuză” au exact aceleași culori, deci arată identic. Mențineți aceeași mărime, dar alegeți fundaluri diferite, ca vizitatorul să deosebească cele două opțiuni.', 'adc-marketing'),
                'reject_blends' => __('Fundalul butonului „Refuză” (%1$s) este practic identic cu fundalul bannerului (%2$s): contrast %3$s:1. Refuzul trebuie să fie la fel de vizibil și de ușor de apăsat ca acceptul — așa cum este setat acum, consimțământul poate fi considerat nevalid (dark pattern).', 'adc-marketing'),
                'reject_faint' => __('Butonul „Acceptă” se detașează de fundal (contrast %1$s:1), dar „Refuză” abia se vede (%2$s:1, sub pragul de %3$s:1). Alegeți un fundal mai contrastant pentru „Refuză”, fără să-i modificați mărimea.', 'adc-marketing'),
                'prominence_gap' => __('Diferență mare de prominență între „Acceptă” (%1$s:1 față de fundal) și „Refuză” (%2$s:1). GDPR cere ca refuzul să fie la fel de atractiv vizual ca acceptul; reduceți diferența de culori.', 'adc-marketing'),
                'reject_illegible' => __('Textul butonului „Refuză” are contrast %1$s:1 față de fundalul său, sub 4,5:1 și mai slab decât al lui „Acceptă” (%2$s:1). Refuzul devine greu de citit.', 'adc-marketing'),
            ),
        );
    }

    /**
     * GDPR / dark-pattern audit of the configured button colours.
     *
     * Accept and Refuză keep identical geometry in CSS (`.adc-consent-btn--equal`),
     * so the only way an admin can make refusal harder than acceptance is colour:
     * a „Refuză” painted in the banner background effectively disappears. These
     * rules measure exactly that and return findings for the admin screen and the
     * live preview. Only the refusal side is checked — de-emphasising „Acceptă”
     * is always conservative and therefore never a finding.
     *
     * @param array<string, string> $colors Colour map (bg, accept_*, reject_*).
     * @return array<int, array{code: string, keys: string[], args: array<int, string>, message: string}>
     */
    public static function prominence_audit(array $colors): array
    {
        $rules   = self::audit_rules();
        $hex     = array();
        $required = array('bg', 'accept_bg', 'reject_bg');

        foreach (array_merge($required, array('accept_text', 'reject_text')) as $key) {
            $hex[$key] = self::normalize_hex((string) ($colors[$key] ?? ''));
        }

        // Incomplete palette: nothing reliable to compare against.
        foreach ($required as $key) {
            if ('' === $hex[$key]) {
                return array();
            }
        }

        $findings = array();

        if ($hex['accept_bg'] === $hex['reject_bg'] && ('' === $hex['accept_text'] || '' === $hex['reject_text'] || $hex['accept_text'] === $hex['reject_text'])) {
            $findings[] = self::audit_finding('indistinguishable', array('accept_bg', 'reject_bg', 'accept_text', 'reject_text'), array(), $rules);

            return $findings;
        }

        $accept_vis = self::contrast_ratio($hex['accept_bg'], $hex['bg']);
        $reject_vis = self::contrast_ratio($hex['reject_bg'], $hex['bg']);

        if ($reject_vis < $rules['blendContrast']) {
            $findings[] = self::audit_finding('reject_blends', array('reject_bg', 'bg'), array($hex['reject_bg'], $hex['bg'], self::ratio_text($reject_vis)), $rules);
        } elseif ($reject_vis < $rules['uiContrast'] && $accept_vis >= $rules['uiContrast']) {
            $findings[] = self::audit_finding('reject_faint', array('reject_bg'), array(self::ratio_text($accept_vis), self::ratio_text($reject_vis), self::ratio_text($rules['uiContrast'])), $rules);
        } elseif ($reject_vis >= $rules['uiContrast'] && $accept_vis / $reject_vis >= $rules['prominenceGap']) {
            $findings[] = self::audit_finding('prominence_gap', array('reject_bg', 'accept_bg'), array(self::ratio_text($accept_vis), self::ratio_text($reject_vis)), $rules);
        }

        if ('' !== $hex['accept_text'] && '' !== $hex['reject_text']) {
            $accept_legible = self::contrast_ratio($hex['accept_text'], $hex['accept_bg']);
            $reject_legible = self::contrast_ratio($hex['reject_text'], $hex['reject_bg']);

            if ($reject_legible < $rules['textContrast'] && $reject_legible < $accept_legible) {
                $findings[] = self::audit_finding('reject_illegible', array('reject_text', 'reject_bg'), array(self::ratio_text($reject_legible), self::ratio_text($accept_legible)), $rules);
            }
        }

        return $findings;
    }

    /**
     * One formatted audit finding (message from the shared catalogue).
     *
     * @param string               $code  Finding code.
     * @param string[]             $keys  Colour keys to highlight.
     * @param array<int, string>   $args  sprintf arguments (also used by the live preview).
     * @param array                $rules Catalogue from audit_rules().
     * @return array{code: string, keys: string[], args: array<int, string>, message: string}
     */
    private static function audit_finding(string $code, array $keys, array $args, array $rules): array
    {
        $template = isset($rules['messages'][$code]) ? (string) $rules['messages'][$code] : $code;

        return array(
            'code'    => $code,
            'keys'    => $keys,
            'args'    => $args,
            'message' => $args ? vsprintf($template, $args) : $template,
        );
    }

    /** Contrast ratio as Romanian text ("1,4"). */
    public static function ratio_text(float $ratio): string
    {
        return number_format($ratio, 1, ',', '');
    }

    /**
     * Validated 6-digit lowercase hex, '' when the value is not a colour.
     * 3-digit shorthand (#fff) is expanded, so contrast maths never sees it.
     */
    public static function normalize_hex(string $value): string
    {
        $hex = strtolower(trim($value));

        if (1 === preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $hex, $m)) {
            $hex = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        return 1 === preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : '';
    }

    /** WCAG relative-luminance contrast ratio. */
    public static function contrast_ratio(string $foreground, string $background): float
    {
        $lum = static function (string $hex): float {
            $rgb = array_map(static function (int $c): float { $c /= 255; return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; }, sscanf($hex, '#%02x%02x%02x'));
            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };
        $a = $lum($foreground); $b = $lum($background);
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Option with the append-only history of shown banner texts (D18). Not autoloaded.
     */
    public const TEXTS_OPTION = 'adc_consent_texts';

    /**
     * Boot the module.
     */
    public static function boot(): void
    {
        add_action('adc_register_settings', array(self::class, 'register_settings'));
        add_filter('adc_frontend_config', array(self::class, 'frontend_config'));
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue_assets'));
        add_action('wp_footer', array(self::class, 'render_banner'), 20);
        add_shortcode('adc_cookie_preferences', array(self::class, 'shortcode_preferences'));
        add_shortcode('adc_consent_settings', array(self::class, 'shortcode_preferences'));
    }

    /**
     * Register the `banner` settings group (fired by ADC_Settings on init).
     */
    public static function register_settings(): void
    {
        ADC_Settings::register_group('banner', self::defaults(), array(self::class, 'sanitize'));
    }

    /**
     * Default `banner` settings group values.
     *
     * @return array
     */
    public static function defaults(): array
    {
        return array(
            'layout'                => 'bar_bottom',
            'button_order'          => self::DEFAULT_BUTTON_ORDER,
            'accept_gap'            => self::ACCEPT_GAP_DEFAULT,
            'preset'                => 'light',
            'radius'                => 8,
            'shadow'                => true,
            'overlay'               => true,
            'accent'                => '#2563eb',
            'title'                 => __('Folosim cookie-uri', 'adc-marketing'),
            'message'               => __('Folosim cookie-uri necesare pentru funcționarea site-ului. Cu acordul dumneavoastră, folosim și cookie-uri pentru statistici și marketing. Puteți accepta, refuza sau personaliza alegerile în orice moment.', 'adc-marketing'),
            'accept_label'          => __('Acceptă tot', 'adc-marketing'),
            'reject_label'          => __('Refuză tot', 'adc-marketing'),
            'customize_label'       => __('Personalizează', 'adc-marketing'),
            'save_label'            => __('Salvează alegerile', 'adc-marketing'),
            'category_labels'       => array(
                'necessary'   => __('Necesare', 'adc-marketing'),
                'preferences' => __('Preferințe', 'adc-marketing'),
                'statistics'  => __('Statistici', 'adc-marketing'),
                'marketing'   => __('Marketing', 'adc-marketing'),
            ),
            'category_descriptions' => array(
                'necessary'   => __('Necesare pentru funcționarea site-ului (coș, autentificare, securitate). Nu pot fi dezactivate.', 'adc-marketing'),
                'preferences' => __('Rețin setări precum limba sau moneda preferată.', 'adc-marketing'),
                'statistics'  => __('Ne ajută să înțelegem, în mod agregat, cum este folosit site-ul.', 'adc-marketing'),
                'marketing'   => __('Permit afișarea de reclame și promoții relevante, inclusiv prin platforme terțe.', 'adc-marketing'),
            ),
            'privacy_page_id'       => 0,
            'cookie_page_id'        => 0,
            'show_floating_button'  => true,
            'colors'                => self::presets()['light']['colors'],
            'inventory'             => self::default_inventory(),
        );
    }

    /**
     * Built-in cookie inventory (editable in the admin tab).
     *
     * @return array<int, array{name: string, category: string, provider: string, purpose: string, duration: string, wildcard: bool}>
     */
    public static function default_inventory(): array
    {
        return array(
            array(
                'name'     => '_ga',
                'category' => 'statistics',
                'provider' => 'Google Analytics',
                'purpose'  => __('Distinge vizitatorii unici.', 'adc-marketing'),
                'duration' => __('2 ani', 'adc-marketing'),
                'wildcard' => true,
            ),
            array(
                'name'     => '_gid',
                'category' => 'statistics',
                'provider' => 'Google Analytics',
                'purpose'  => __('Distinge vizitatorii unici în aceeași zi.', 'adc-marketing'),
                'duration' => __('24 de ore', 'adc-marketing'),
                'wildcard' => false,
            ),
            array(
                'name'     => '_fbp',
                'category' => 'marketing',
                'provider' => 'Meta',
                'purpose'  => __('Afișează reclame relevante pe platformele Meta.', 'adc-marketing'),
                'duration' => __('3 luni', 'adc-marketing'),
                'wildcard' => false,
            ),
            array(
                'name'     => '_fbc',
                'category' => 'marketing',
                'provider' => 'Meta',
                'purpose'  => __('Reține click-ul pe un anunț Meta.', 'adc-marketing'),
                'duration' => __('2 ani', 'adc-marketing'),
                'wildcard' => false,
            ),
            array(
                'name'     => '_ttp',
                'category' => 'marketing',
                'provider' => 'TikTok',
                'purpose'  => __('Măsoară eficiența reclamelor TikTok.', 'adc-marketing'),
                'duration' => __('13 luni', 'adc-marketing'),
                'wildcard' => false,
            ),
            array(
                'name'     => '_gcl_',
                'category' => 'marketing',
                'provider' => 'Google Ads',
                'purpose'  => __('Măsoară eficiența reclamelor Google.', 'adc-marketing'),
                'duration' => __('90 de zile', 'adc-marketing'),
                'wildcard' => true,
            ),
            array(
                'name'     => 'woocommerce_',
                'category' => 'necessary',
                'provider' => 'WooCommerce',
                'purpose'  => __('Păstrează coșul și datele comenzii.', 'adc-marketing'),
                'duration' => __('Sesiune sau 2 zile', 'adc-marketing'),
                'wildcard' => true,
            ),
            array(
                'name'     => 'wp_woocommerce_session_',
                'category' => 'necessary',
                'provider' => 'WooCommerce',
                'purpose'  => __('Identifică sesiunea de cumpărături.', 'adc-marketing'),
                'duration' => __('2 zile', 'adc-marketing'),
                'wildcard' => true,
            ),
            array(
                'name'     => 'adc_consent',
                'category' => 'necessary',
                'provider' => 'ADC Cookies & Commerce Hub',
                'purpose'  => __('Reține alegerile dumneavoastră privind cookie-urile.', 'adc-marketing'),
                'duration' => __('180 de zile', 'adc-marketing'),
                'wildcard' => false,
            ),
        );
    }

    /**
     * Sanitize the `banner` group. $input must be the complete form: checkbox
     * values are read as booleans, so a missing key means "off".
     *
     * @param array $input   Raw values from the admin tab (already unslashed).
     * @param array $current Currently stored values (defaults merged).
     * @return array Complete, typed group values.
     */
    public static function sanitize(array $input, array $current): array
    {
        $out = array_replace_recursive(self::defaults(), $current);

        $legacy_layout = (string) ($current['layout'] ?? 'bar_bottom');
        $out['layout'] = in_array($input['layout'] ?? '', self::LAYOUTS, true)
            ? (string) $input['layout']
            : (in_array($legacy_layout, self::LAYOUTS, true) ? $legacy_layout : 'bar_bottom');

        foreach (array('title', 'accept_label', 'reject_label', 'customize_label', 'save_label') as $key) {
            if (isset($input[$key])) {
                $out[$key] = sanitize_text_field((string) $input[$key]);
            }
        }

        $order = (string) ($input['button_order'] ?? $current['button_order'] ?? self::DEFAULT_BUTTON_ORDER);
        $out['button_order'] = array_key_exists($order, self::button_orders()) ? $order : self::DEFAULT_BUTTON_ORDER;

        $gap = $input['accept_gap'] ?? $current['accept_gap'] ?? self::ACCEPT_GAP_DEFAULT;
        $out['accept_gap'] = is_numeric($gap)
            ? max(self::ACCEPT_GAP_MIN, min(self::ACCEPT_GAP_MAX, (int) $gap))
            : self::ACCEPT_GAP_DEFAULT;

        if (isset($input['message'])) {
            $out['message'] = wp_kses_post((string) $input['message']);
        }

        foreach (array('category_labels', 'category_descriptions') as $key) {
            if (!isset($input[$key]) || !is_array($input[$key])) {
                continue;
            }
            foreach (self::CATEGORIES as $category) {
                if (isset($input[$key][$category])) {
                    $out[$key][$category] = sanitize_text_field((string) $input[$key][$category]);
                }
            }
        }

        $out['privacy_page_id']      = absint($input['privacy_page_id'] ?? $current['privacy_page_id']);
        $out['cookie_page_id']       = absint($input['cookie_page_id'] ?? $current['cookie_page_id']);
        $out['show_floating_button'] = (bool) ($input['show_floating_button'] ?? false);

        if (isset($input['colors']) && is_array($input['colors'])) {
            foreach (self::COLOR_KEYS as $key) {
                if (isset($input['colors'][$key])) {
                    $out['colors'][$key] = self::normalize_hex((string) $input['colors'][$key]) ?: ($current['colors'][$key] ?? self::presets()['light']['colors'][$key]);
                }
            }
            if (count(array_intersect(self::COLOR_KEYS, array_keys($input['colors']))) > 0) {
                $out['preset'] = 'custom';
            }
        }
        // Resolved before the preset bake, so "Culoare brand" uses the accent of this save (not the previous one).
        $accent_in = $input['accent'] ?? (isset($input['colors']['accent']) ? $input['colors']['accent'] : null) ?? ($current['accent'] ?? '#2563eb');
        $out['accent'] = self::normalize_hex((string) $accent_in) ?: '#2563eb';

        $presets = self::presets($out['accent']);
        $out['preset'] = in_array($input['preset'] ?? '', array_merge(array_keys($presets), array('custom')), true) ? (string) $input['preset'] : (string) ($current['preset'] ?? 'light');
        if ($out['preset'] !== 'custom' && isset($presets[$out['preset']])) {
            $out['colors'] = $presets[$out['preset']]['colors'];
            $out['radius'] = $presets[$out['preset']]['radius'];
        }
        $out['radius'] = max(0, min(24, (int) ($input['radius'] ?? $out['radius'] ?? 8)));
        $out['shadow'] = (bool) ($input['shadow'] ?? $current['shadow'] ?? true);
        $out['overlay'] = (bool) ($input['overlay'] ?? $current['overlay'] ?? true);

        if (isset($input['inventory']) && is_array($input['inventory'])) {
            $out['inventory'] = self::sanitize_inventory($input['inventory']);
        }

        return $out;
    }

    /**
     * Keep only valid inventory rows. A trailing `*` in the name means prefix match.
     *
     * @param array $rows Raw rows.
     * @return array
     */
    private static function sanitize_inventory(array $rows): array
    {
        $clean = array();

        foreach (array_slice($rows, 0, 100) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name     = trim((string) ($row['name'] ?? ''));
            $wildcard = (bool) ($row['wildcard'] ?? false);
            if (str_ends_with($name, '*')) {
                $name     = substr($name, 0, -1);
                $wildcard = true;
            }
            if (1 !== preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name)) {
                continue;
            }

            $category = (string) ($row['category'] ?? '');
            if (!in_array($category, self::CATEGORIES, true)) {
                continue;
            }

            $clean[] = array(
                'name'     => $name,
                'category' => $category,
                'provider' => sanitize_text_field((string) ($row['provider'] ?? '')),
                'purpose'  => sanitize_text_field((string) ($row['purpose'] ?? '')),
                'duration' => sanitize_text_field((string) ($row['duration'] ?? '')),
                'wildcard' => $wildcard,
            );
        }

        return $clean;
    }

    /**
     * Cookie inventory rows as stored (sanitised on save). Published under
     * `adcConfig.consent.inventory` (§2.3) and used by the client-side
     * cookie cleanup on withdrawal.
     *
     * @return array<int, array{name: string, category: string, provider: string, purpose: string, duration: string, wildcard: bool}>
     */
    public static function inventory(): array
    {
        $rows = ADC_Settings::get('banner', 'inventory', array());

        return is_array($rows) ? array_values($rows) : array();
    }

    /**
     * Add the `banner` block (and the token, when consent is on) to window.adcConfig.
     *
     * @param array $config Base configuration.
     * @return array
     */
    public static function frontend_config(array $config): array
    {
        if (!self::is_enabled()) {
            return $config;
        }

        $config['banner'] = self::client_config();

        if (!isset($config['token']) && class_exists('ADC_Consent_Rest')) {
            $config['token'] = ADC_Consent_Rest::token();
        }

        return $config;
    }

    /**
     * Enqueue the head script (window.adcConsent), the banner UI and its CSS.
     * Nothing is loaded when the banner is disabled.
     */
    public static function enqueue_assets(): void
    {
        if (!self::is_enabled()) {
            return;
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;

        wp_enqueue_style(self::UI_HANDLE, self::asset_url('css/consent-banner.css'), array(), $version);

        // consent-manager.js must run before any tag that depends on consent: head, deferred.
        wp_register_script(self::SCRIPT_HANDLE, self::asset_url('js/consent-manager.js'), array(), $version, false);
        wp_script_add_data(self::SCRIPT_HANDLE, 'strategy', 'defer');
        ADC_Frontend_Config::attach(self::SCRIPT_HANDLE);
        wp_enqueue_script(self::SCRIPT_HANDLE);

        wp_register_script(self::UI_HANDLE, self::asset_url('js/consent-banner.js'), array(self::SCRIPT_HANDLE), $version, true);
        wp_script_add_data(self::UI_HANDLE, 'strategy', 'defer');
        wp_enqueue_script(self::UI_HANDLE);
    }

    /**
     * Print the banner, the preferences modal and the reopen button (wp_footer).
     */
    public static function render_banner(): void
    {
        if (!self::is_enabled()) {
            return;
        }

        ADC_Template::render('consent/banner.php', array('config' => self::view_config()));
    }

    /**
     * [adc_cookie_preferences] / [adc_consent_settings]: link that reopens the preferences modal.
     * Works without JavaScript too (it points to the cookie page).
     *
     * @param array|string $atts Shortcode attributes (label).
     * @return string
     */
    public static function shortcode_preferences(array|string $atts = array()): string
    {
        if (!self::is_enabled()) {
            return '';
        }

        $atts = shortcode_atts(
            array('label' => __('Setări cookie-uri', 'adc-marketing')),
            is_array($atts) ? $atts : array(),
            'adc_cookie_preferences'
        );

        $url = self::page_url((int) ADC_Settings::get('banner', 'cookie_page_id', 0));

        return sprintf(
            '<a class="adc-consent-link" href="%1$s" data-adc-open-preferences>%2$s</a>',
            esc_url('' !== $url ? $url : '#'),
            esc_html((string) $atts['label'])
        );
    }

    /**
     * Hash of the banner texts currently configured. Stores the text set in
     * TEXTS_OPTION the first time it is seen (append-only proof of what was shown, D18).
     *
     * @return string sha256 hex.
     */
    public static function current_text_hash(): string
    {
        $settings = ADC_Settings::all('banner');

        $texts = array(
            'title'      => (string) $settings['title'],
            'message'    => (string) $settings['message'],
            'labels'     => array(
                'accept'     => (string) $settings['accept_label'],
                'reject'     => (string) $settings['reject_label'],
                'customize'  => (string) $settings['customize_label'],
                'save'       => (string) $settings['save_label'],
            ),
            'categories' => $settings['category_labels'],
            'descriptions' => $settings['category_descriptions'],
        );

        $hash    = hash('sha256', (string) wp_json_encode($texts, JSON_UNESCAPED_UNICODE));
        $history = get_option(self::TEXTS_OPTION, array());
        $history = is_array($history) ? $history : array();

        if (!isset($history[$hash])) {
            $history[$hash] = array(
                'ts'     => time(),
                'policy' => (int) ADC_Settings::get('consent', 'policy_version', 1),
                'texts'  => $texts,
            );
            update_option(self::TEXTS_OPTION, $history, false);
        }

        return $hash;
    }

    /**
     * Whether the banner (and therefore the CMP frontend) is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool
    {
        return (bool) ADC_Settings::get('consent', 'enabled', false);
    }

    /**
     * Values exposed to JavaScript as adcConfig.banner (no secrets).
     *
     * @return array
     */
    private static function client_config(): array
    {
        $settings  = ADC_Settings::all('banner');
        $inventory = is_array($settings['inventory']) ? array_values($settings['inventory']) : array();

        return array(
            'enabled'            => true,
            'layout'             => (string) $settings['layout'],
            'preset'             => (string) ($settings['preset'] ?? 'light'),
            'overlay'            => (bool) ($settings['overlay'] ?? true),
            'style'              => self::style_string($settings),
            'showFloatingButton' => (bool) $settings['show_floating_button'],
            'inventory'          => $inventory,
        );
    }

    /**
     * Template variables for consent/banner.php (and its partials).
     *
     * @return array
     */
    private static function view_config(): array
    {
        $settings = ADC_Settings::all('banner');

        $categories = array();
        foreach (self::CATEGORIES as $key) {
            $categories[] = array(
                'key'         => $key,
                'label'       => (string) ($settings['category_labels'][$key] ?? $key),
                'description' => (string) ($settings['category_descriptions'][$key] ?? ''),
            );
        }
        $labels_by_key = array_column($categories, 'label', 'key');

        $inventory = array();
        foreach (is_array($settings['inventory']) ? $settings['inventory'] : array() as $row) {
            $inventory[] = array(
                'name'     => $row['name'] . ($row['wildcard'] ? '*' : ''),
                'category' => (string) ($labels_by_key[$row['category']] ?? $row['category']),
                'provider' => (string) $row['provider'],
                'purpose'  => (string) $row['purpose'],
                'duration' => (string) $row['duration'],
            );
        }

        return array(
            'layout'       => (string) ($settings['layout'] ?? 'bar_bottom'),
            'preset'       => (string) ($settings['preset'] ?? 'light'),
            'overlay'      => (bool) ($settings['overlay'] ?? true),
            'style'        => self::style_string($settings),
            'title'        => (string) $settings['title'],
            'message_html' => wp_kses_post(do_shortcode((string) $settings['message'])),
            'actions'      => self::action_buttons($settings),
            'labels'       => array(
                'accept'    => (string) $settings['accept_label'],
                'reject'    => (string) $settings['reject_label'],
                'customize' => (string) $settings['customize_label'],
                'save'      => (string) $settings['save_label'],
            ),
            'categories'   => $categories,
            'inventory'    => $inventory,
            'show_fab'     => (bool) $settings['show_floating_button'],
            'privacy_url'  => self::privacy_url((int) $settings['privacy_page_id']),
        );
    }

    /** Build validated CSS variables for the banner root. */
    private static function style_string(array $settings): string
    {
        $colors = is_array($settings['colors'] ?? null) ? $settings['colors'] : array();
        $style = '';
        foreach (self::COLOR_KEYS as $key) {
            $value = self::normalize_hex((string) ($colors[$key] ?? ''));
            if ($value) {
                $style .= '--adc-' . str_replace('_', '-', $key) . ':' . $value . ';';
            }
        }
        $style .= '--adc-radius:' . max(0, min(24, (int) ($settings['radius'] ?? 8))) . 'px;';
        $style .= '--adc-accept-gap:' . self::accept_gap($settings) . 'px;';
        $style .= '--adc-shadow:' . (!empty($settings['shadow']) ? '0 8px 28px rgba(0,0,0,.18)' : 'none') . ';';
        return $style;
    }

    /**
     * Configured spacing after the Accept button, clamped to the allowed range.
     *
     * @param array $settings Banner group values.
     * @return int Pixels.
     */
    public static function accept_gap(array $settings): int
    {
        $gap = $settings['accept_gap'] ?? self::ACCEPT_GAP_DEFAULT;

        return is_numeric($gap) ? max(self::ACCEPT_GAP_MIN, min(self::ACCEPT_GAP_MAX, (int) $gap)) : self::ACCEPT_GAP_DEFAULT;
    }

    /**
     * Privacy policy URL: configured page, else the WordPress privacy page.
     *
     * @param int $page_id Configured page id (0 = WordPress default).
     * @return string
     */
    private static function privacy_url(int $page_id): string
    {
        return $page_id > 0 ? self::page_url($page_id) : (string) get_privacy_policy_url();
    }

    /**
     * Permalink of a page id, '' when unset or missing.
     *
     * @param int $page_id Page id.
     * @return string
     */
    private static function page_url(int $page_id): string
    {
        if ($page_id <= 0) {
            return '';
        }

        $url = get_permalink($page_id);

        return is_string($url) ? $url : '';
    }

    /**
     * Asset URL inside the plugin's assets/ directory.
     *
     * @param string $relative Path relative to assets/.
     * @return string
     */
    private static function asset_url(string $relative): string
    {
        if (defined('ADC_MARKETING_PLUGIN_URL')) {
            return rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/' . $relative;
        }

        return plugin_dir_url(dirname(__DIR__, 2) . '/adc-marketing.php') . 'assets/' . $relative;
    }
}
