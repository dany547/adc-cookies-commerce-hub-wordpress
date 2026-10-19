<?php
/**
 * "Banner consimțământ" admin tab: banner texts, layout, colours, categories,
 * legal pages, cookie inventory and the consent on/off switch.
 *
 * The "Aspect și poziție" section (preset cards, position picker, colour
 * fields with wp-color-picker, live preview) reads the layout/preset/colour
 * catalogue from ADC_Consent_Banner when available (layouts(), presets(),
 * COLOR_KEYS — added in parallel by the banner-class owner) and falls back to
 * built-in defaults otherwise, so the tab keeps working on either side of
 * that change. Saving goes through the existing banner sanitizer untouched.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin tab for the cookie banner (docs/ARCHITECTURE.md §1.3, §2.1, §4).
 */
final class ADC_Admin_Tab_Banner implements ADC_Admin_Tab_Interface, ADC_Module_Interface
{
    /**
     * Whether boot() already registered the admin hooks.
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Fallback colour keys (contract §COLOR_KEYS), used only while
     * ADC_Consent_Banner::COLOR_KEYS does not exist yet.
     *
     * @var string[]
     */
    private const FALLBACK_COLOR_KEYS = array(
        'bg',
        'text',
        'muted',
        'border',
        'link',
        'accept_bg',
        'accept_text',
        'reject_bg',
        'reject_text',
        'customize_bg',
        'customize_text',
    );

    /**
     * Fallback layouts (slug => Romanian label), used only while
     * ADC_Consent_Banner::layouts() does not exist yet.
     *
     * @var array<string, string>
     */
    private const FALLBACK_LAYOUTS = array(
        'bar_bottom'       => 'Bară jos (toată lățimea)',
        'box_bottom_left'  => 'Casetă jos stânga',
        'box_bottom_right' => 'Casetă jos dreapta',
        'box_bottom_center' => 'Casetă jos centru',
        'center_modal'     => 'Fereastră centrată',
        'bar_top'          => 'Bară sus',
    );

    /**
     * Boot the module: enqueue the tab assets on the plugin admin page.
     * Idempotent; picked up automatically by ADC_Marketing::boot_modules().
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
    }

    /**
     * Enqueue colour picker, frontend banner CSS (for the live preview),
     * the tab stylesheet and the vanilla preview script.
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('toplevel_page_' . ADC_Admin_Shell::PAGE_SLUG !== $hook_suffix || !current_user_can('manage_options')) {
            return;
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $base    = self::asset_url('');
        if ('' === $base) {
            return;
        }

        wp_enqueue_style('wp-color-picker');

        // Frontend banner CSS, reused so the preview shows the real banner chrome.
        wp_enqueue_style('adc-consent-banner-preview', $base . 'css/consent-banner.css', array(), $version);

        wp_enqueue_style(
            'adc-admin-banner',
            $base . 'css/admin-banner.css',
            array('adc-consent-banner-preview', 'wp-color-picker'),
            $version
        );

        wp_register_script(
            'adc-admin-banner',
            $base . 'js/admin-banner.js',
            array('jquery', 'wp-color-picker'),
            $version,
            array(
                'in_footer' => true,
                'strategy'  => 'defer',
            )
        );

        $presets = wp_json_encode(self::banner_presets(self::stored_accent()));
        if (is_string($presets) && '' !== $presets) {
            wp_add_inline_script('adc-admin-banner', 'window.adcBannerPresets = ' . $presets . ';', 'before');
        }

        $rules = wp_json_encode(self::audit_rules());
        if (is_string($rules) && '' !== $rules) {
            wp_add_inline_script('adc-admin-banner', 'window.adcBannerCompliance = ' . $rules . ';', 'before');
        }

        wp_enqueue_script('adc-admin-banner');
    }

    /**
     * {@inheritDoc}
     */
    public function slug(): string
    {
        return 'banner';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('Banner consimțământ', 'adc-marketing');
    }

    /**
     * {@inheritDoc}
     */
    public function group(): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function order(): int
    {
        return 10;
    }

    /**
     * Render the tab fields (the shell provides the form and nonce).
     */
    public function render(): void
    {
        $banner  = ADC_Settings::all('banner');
        $consent = ADC_Settings::all('consent');

        $layout  = self::normalize_layout((string) ($banner['layout'] ?? 'bar_bottom'));
        $layouts = self::banner_layouts();
        $presets = self::banner_presets(self::stored_accent($banner));

        $preset_keys = array_keys($presets);
        $preset_keys[] = 'custom';
        $preset = (string) ($banner['preset'] ?? 'light');
        if (!in_array($preset, $preset_keys, true)) {
            $preset = 'light';
        }

        $colors = self::merged_colors($banner, $presets, $preset);
        $radius = self::clamped_radius($banner['radius'] ?? null, $presets, $preset);
        $gap    = self::clamped_gap($banner['accept_gap'] ?? null);
        $shadow = array_key_exists('shadow', $banner) ? (bool) $banner['shadow'] : true;
        $overlay = array_key_exists('overlay', $banner) ? (bool) $banner['overlay'] : true;
        ?>
        <h2><?php esc_html_e('Activare', 'adc-marketing'); ?></h2>
        <table class="form-table" role="presentation"><tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Banner cookie-uri', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="adc_consent[enabled]" value="1" <?php checked(!empty($consent['enabled'])); ?> />
                        <?php esc_html_e('Afișează bannerul și managerul de consimțământ pe site', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Versiunea politicii', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="adc_consent[policy_bump]" value="1" />
                        <?php esc_html_e('Cere din nou consimțământul (crește versiunea politicii)', 'adc-marketing'); ?>
                    </label>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %d: current policy version number. */
                            esc_html__('Versiunea curentă: %d. Bifați după ce modificați textele sau categoriile, ca vizitatorii să fie întrebați din nou.', 'adc-marketing'),
                            (int) ($consent['policy_version'] ?? 1)
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </tbody></table>

        <h2><?php esc_html_e('Texte', 'adc-marketing'); ?></h2>
        <table class="form-table" role="presentation"><tbody>
            <tr>
                <th scope="row"><label for="adc-banner-title"><?php esc_html_e('Titlu', 'adc-marketing'); ?></label></th>
                <td><input type="text" id="adc-banner-title" class="regular-text" name="adc_banner[title]" value="<?php echo esc_attr((string) ($banner['title'] ?? '')); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="adc-banner-message"><?php esc_html_e('Mesaj', 'adc-marketing'); ?></label></th>
                <td>
                    <textarea id="adc-banner-message" class="large-text" rows="4" name="adc_banner[message]"><?php echo esc_textarea((string) ($banner['message'] ?? '')); ?></textarea>
                    <p class="description"><?php esc_html_e('Puteți folosi [privacy-policy-link]Text[/privacy-policy-link] pentru link-ul către Politica de Confidențialitate.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <?php
            $buttons = array(
                'accept_label'    => __('Buton acceptare', 'adc-marketing'),
                'reject_label'    => __('Buton refuzare', 'adc-marketing'),
                'customize_label' => __('Buton personalizare', 'adc-marketing'),
                'save_label'      => __('Buton salvare alegeri', 'adc-marketing'),
            );
            foreach ($buttons as $key => $caption) :
                ?>
                <tr>
                    <th scope="row"><label for="adc-banner-<?php echo esc_attr($key); ?>"><?php echo esc_html($caption); ?></label></th>
                    <td><input type="text" id="adc-banner-<?php echo esc_attr($key); ?>" class="regular-text" name="adc_banner[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) ($banner[$key] ?? '')); ?>" /></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row"><?php esc_html_e('Pagini', 'adc-marketing'); ?></th>
                <td>
                    <p>
                        <label for="adc-banner-privacy-page"><?php esc_html_e('Politica de Confidențialitate', 'adc-marketing'); ?></label><br />
                        <?php
                        // wp_dropdown_pages() escapes its own output.
                        wp_dropdown_pages(array(
                            'id'               => 'adc-banner-privacy-page',
                            'name'             => 'adc_banner[privacy_page_id]',
                            'selected'         => (int) ($banner['privacy_page_id'] ?? 0),
                            'show_option_none' => __('— Pagina implicită WordPress —', 'adc-marketing'),
                            'option_none_value' => '0',
                        ));
                        ?>
                    </p>
                    <p>
                        <label for="adc-banner-cookie-page"><?php esc_html_e('Pagina de setări cookie-uri', 'adc-marketing'); ?></label><br />
                        <?php
                        wp_dropdown_pages(array(
                            'id'               => 'adc-banner-cookie-page',
                            'name'             => 'adc_banner[cookie_page_id]',
                            'selected'         => (int) ($banner['cookie_page_id'] ?? 0),
                            'show_option_none' => __('— Fără pagină dedicată —', 'adc-marketing'),
                            'option_none_value' => '0',
                        ));
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Buton flotant', 'adc-marketing'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="adc_banner[show_floating_button]" value="1" <?php checked((bool) ($banner['show_floating_button'] ?? false)); ?> />
                        <?php esc_html_e('Afișează butonul „Cookie-uri” pentru redeschiderea setărilor după decizie', 'adc-marketing'); ?>
                    </label>
                </td>
            </tr>
        </tbody></table>

        <div id="adc-banner-appearance">
            <h2><?php esc_html_e('Aspect și poziție', 'adc-marketing'); ?></h2>

            <h3><?php esc_html_e('Preset culori', 'adc-marketing'); ?></h3>
            <p class="description"><?php esc_html_e('Alegeți un preset pentru a completa automat culorile. Dacă modificați orice culoare, presetul trece pe „Particularizat”.', 'adc-marketing'); ?></p>
            <fieldset class="adc-preset-cards">
                <legend class="screen-reader-text"><?php esc_html_e('Preset culori', 'adc-marketing'); ?></legend>
                <?php foreach ($presets as $slug => $definition) : ?>
                    <label class="adc-preset-card">
                        <input type="radio" name="adc_banner[preset]" value="<?php echo esc_attr($slug); ?>" <?php checked($preset, $slug); ?> />
                        <span class="adc-preset-swatches" aria-hidden="true">
                            <span style="background:<?php echo esc_attr((string) ($definition['colors']['bg'] ?? '#ffffff')); ?>;border-color:<?php echo esc_attr((string) ($definition['colors']['border'] ?? '#dcdcde')); ?>"></span>
                            <span style="background:<?php echo esc_attr((string) ($definition['colors']['accept_bg'] ?? '#1d4ed8')); ?>"></span>
                            <span style="background:<?php echo esc_attr((string) ($definition['colors']['reject_bg'] ?? '#50575e')); ?>"></span>
                            <span style="background:<?php echo esc_attr((string) ($definition['colors']['text'] ?? '#1d2327')); ?>"></span>
                        </span>
                        <span class="adc-preset-label"><?php echo esc_html((string) $definition['label']); ?></span>
                    </label>
                <?php endforeach; ?>
                <label class="adc-preset-card adc-preset-card--custom">
                    <input type="radio" name="adc_banner[preset]" value="custom" <?php checked($preset, 'custom'); ?> />
                    <span class="adc-preset-swatches" aria-hidden="true">
                        <span style="background:<?php echo esc_attr($colors['bg']); ?>;border-color:<?php echo esc_attr($colors['border']); ?>"></span>
                        <span style="background:<?php echo esc_attr($colors['accept_bg']); ?>"></span>
                        <span style="background:<?php echo esc_attr($colors['reject_bg']); ?>"></span>
                        <span style="background:<?php echo esc_attr($colors['text']); ?>"></span>
                    </span>
                    <span class="adc-preset-label"><?php esc_html_e('Particularizat', 'adc-marketing'); ?></span>
                </label>
            </fieldset>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="adc-banner-color-accent"><?php esc_html_e('Culoare brand (accent)', 'adc-marketing'); ?></label></th>
                    <td>
                        <input type="text" id="adc-banner-color-accent" class="adc-color-field" name="adc_banner[colors][accent]" value="<?php echo esc_attr(self::stored_accent($banner)); ?>" data-default-color="#1d4ed8" />
                        <p class="description"><?php esc_html_e('Folosită de presetul „Culoare brand” pentru butonul Acceptă și pentru linkuri.', 'adc-marketing'); ?></p>
                    </td>
                </tr>
            </tbody></table>

            <h3><?php esc_html_e('Poziție și format', 'adc-marketing'); ?></h3>
            <fieldset class="adc-layout-picker">
                <legend class="screen-reader-text"><?php esc_html_e('Poziție și format', 'adc-marketing'); ?></legend>
                <?php foreach ($layouts as $slug => $caption) : ?>
                    <label class="adc-layout-thumb adc-layout-thumb--<?php echo esc_attr($slug); ?>">
                        <input type="radio" name="adc_banner[layout]" value="<?php echo esc_attr($slug); ?>" <?php checked($layout, $slug); ?> />
                        <span class="adc-screen" aria-hidden="true"><span class="adc-mock"></span></span>
                        <span class="adc-layout-label"><?php echo esc_html((string) $caption); ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <h3><?php esc_html_e('Ordinea și spațierea butoanelor', 'adc-marketing'); ?></h3>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="adc-banner-button-order"><?php esc_html_e('Ordinea butoanelor', 'adc-marketing'); ?></label></th>
                    <td>
                        <select id="adc-banner-button-order" name="adc_banner[button_order]">
                            <?php foreach (self::button_orders() as $slug => $definition) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" data-order="<?php echo esc_attr(implode(' ', (array) $definition['actions'])); ?>" <?php selected(self::stored_button_order($banner), $slug); ?>><?php echo esc_html((string) $definition['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Ordinea aleasă este și ordinea din codul HTML, deci Tab-ul și cititorul de ecran parcurg butoanele exact cum le vede vizitatorul. Butonul „Personalizează” poate fi redenumit în „Setări” din câmpul de mai sus.', 'adc-marketing'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="adc-banner-accept-gap"><?php esc_html_e('Spațiu după „Acceptă”', 'adc-marketing'); ?></label></th>
                    <td>
                        <input type="range" id="adc-banner-accept-gap" name="adc_banner[accept_gap]" min="0" max="48" step="2" value="<?php echo esc_attr((string) $gap); ?>" />
                        <output id="adc-banner-accept-gap-val" for="adc-banner-accept-gap"><?php echo esc_html($gap . 'px'); ?></output>
                        <p class="description"><?php esc_html_e('Separator între „Acceptă” și celelalte două butoane, pe aceeași linie. Maxim 48 de pixeli, iar bannerul reduce spațiul la 12% din lățimea rândului, ca linia să nu se rupă. Pe ecranele înguste butoanele se așază unul sub altul, iar spațiul devine vertical.', 'adc-marketing'); ?></p>
                    </td>
                </tr>
            </tbody></table>

            <h3><?php esc_html_e('Culori', 'adc-marketing'); ?></h3>
            <?php foreach (self::color_groups() as $group_id => $group) : ?>
                <?php
                $keys = array_values(array_intersect($group['keys'], self::color_keys()));
                if (empty($keys)) {
                    continue;
                }
                ?>
                <h4><?php echo esc_html($group['label']); ?></h4>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($keys as $key) : ?>
                        <tr>
                            <th scope="row"><label for="adc-banner-color-<?php echo esc_attr($key); ?>"><?php echo esc_html(self::color_label($key)); ?></label></th>
                            <td>
                                <input type="text" id="adc-banner-color-<?php echo esc_attr($key); ?>" class="adc-color-field" name="adc_banner[colors][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($colors[$key]); ?>" data-default-color="<?php echo esc_attr($colors[$key]); ?>" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php self::render_contrast_warning($group_id); ?>
            <?php endforeach; ?>
            <?php self::render_extra_colors($colors); ?>
            <?php self::render_compliance_panel($colors); ?>

            <h3><?php esc_html_e('Colțuri, umbră și fundal', 'adc-marketing'); ?></h3>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="adc-banner-radius"><?php esc_html_e('Rază colțuri', 'adc-marketing'); ?></label></th>
                    <td>
                        <input type="range" id="adc-banner-radius" name="adc_banner[radius]" min="0" max="24" step="1" value="<?php echo esc_attr((string) $radius); ?>" />
                        <output id="adc-banner-radius-val" for="adc-banner-radius"><?php echo esc_html((string) $radius . 'px'); ?></output>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Umbră', 'adc-marketing'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="adc-banner-shadow" name="adc_banner[shadow]" value="1" <?php checked($shadow); ?> />
                            <?php esc_html_e('Afișează umbra bannerului', 'adc-marketing'); ?>
                        </label>
                    </td>
                </tr>
                <tr class="adc-overlay-row"<?php echo 'center_modal' === $layout ? '' : ' hidden'; ?>>
                    <th scope="row"><?php esc_html_e('Fundal întunecat', 'adc-marketing'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="adc-banner-overlay" name="adc_banner[overlay]" value="1" <?php checked($overlay); ?> />
                            <?php esc_html_e('Întunecă fundalul paginii (doar pentru fereastra centrată)', 'adc-marketing'); ?>
                        </label>
                    </td>
                </tr>
            </tbody></table>

            <p class="description adc-equal-note"><?php esc_html_e('Butoanele „Acceptă” și „Refuză” au aceeași mărime, aceeași poziție în rând și aceeași greutate vizuală, conform GDPR; diferă doar culoarea. Ordinea implicită este Acceptă · Setări · Refuză.', 'adc-marketing'); ?></p>

            <h3><?php esc_html_e('Previzualizare live', 'adc-marketing'); ?></h3>
            <p>
                <button type="button" class="button" id="adc-banner-reset"><?php esc_html_e('Resetează la preset', 'adc-marketing'); ?></button>
            </p>
            <div class="adc-banner-preview-screen" id="adc-banner-preview-screen">
                <div class="adc-banner-preview-overlay" id="adc-banner-preview-overlay"<?php echo ('center_modal' === $layout && $overlay) ? '' : ' hidden'; ?>></div>
                <div id="adc-consent-banner" class="adc-consent-banner" data-layout="<?php echo esc_attr($layout); ?>" data-preset="<?php echo esc_attr($preset); ?>" style="<?php echo esc_attr(self::style_string($colors, $radius, $shadow, $gap)); ?>">
                    <div class="adc-consent-banner__inner">
                        <p class="adc-consent-banner__title" id="adc-banner-preview-title"><?php echo esc_html((string) ($banner['title'] ?? '')); ?></p>
                        <p class="adc-consent-banner__message" id="adc-banner-preview-message"><?php echo esc_html(wp_strip_all_tags((string) ($banner['message'] ?? ''))); ?></p>
                        <div class="adc-consent-banner__actions" id="adc-banner-preview-actions">
                            <?php foreach (self::preview_order($banner) as $action) : ?>
                                <button type="button" class="adc-consent-btn adc-consent-btn--<?php echo esc_attr($action); ?><?php echo 'customize' === $action ? '' : ' adc-consent-btn--equal'; ?>" data-adc-order="<?php echo esc_attr($action); ?>" id="adc-banner-preview-<?php echo esc_attr($action); ?>" tabindex="-1"><?php echo esc_html((string) ($banner[$action . '_label'] ?? '')); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <h2><?php esc_html_e('Categorii de cookie-uri', 'adc-marketing'); ?></h2>
        <p class="description"><?php esc_html_e('Categoria „Necesare” este întotdeauna activă. Celelalte categorii sunt dezactivate implicit.', 'adc-marketing'); ?></p>
        <table class="form-table" role="presentation"><tbody>
            <?php foreach (ADC_Consent_Banner::CATEGORIES as $category) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html((string) (($banner['category_labels'][$category] ?? $category))); ?></th>
                    <td>
                        <p>
                            <label>
                                <span class="screen-reader-text"><?php esc_html_e('Nume categorie', 'adc-marketing'); ?></span>
                                <input type="text" class="regular-text" name="adc_banner[category_labels][<?php echo esc_attr($category); ?>]" value="<?php echo esc_attr((string) (($banner['category_labels'][$category] ?? ''))); ?>" />
                            </label>
                        </p>
                        <p>
                            <label>
                                <span class="screen-reader-text"><?php esc_html_e('Descriere categorie', 'adc-marketing'); ?></span>
                                <textarea class="large-text" rows="2" name="adc_banner[category_descriptions][<?php echo esc_attr($category); ?>]"><?php echo esc_textarea((string) (($banner['category_descriptions'][$category] ?? ''))); ?></textarea>
                            </label>
                        </p>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>

        <h2><?php esc_html_e('Lista cookie-urilor', 'adc-marketing'); ?></h2>
        <p class="description">
            <?php esc_html_e('Un cookie pe linie: nume | categorie (necessary, preferences, statistics, marketing) | furnizor | scop | durată | prefix (da/nu). Pentru prefix, scrieți „da” sau adăugați * la nume.', 'adc-marketing'); ?>
        </p>
        <textarea class="large-text code" rows="12" name="adc_banner[inventory]"><?php echo esc_textarea(self::inventory_to_text(is_array($banner['inventory'] ?? null) ? $banner['inventory'] : array())); ?></textarea>
        <?php
    }

    /**
     * Save the tab (called by the shell after nonce and capability checks).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        if (!current_user_can('manage_options')) {
            return array(array('type' => 'error', 'message' => __('Nu aveți permisiunea necesară.', 'adc-marketing')));
        }

        $raw = isset($post['adc_banner']) && is_array($post['adc_banner']) ? $post['adc_banner'] : array();

        $input                         = $raw;
        $input['show_floating_button'] = isset($raw['show_floating_button']);
        $input['shadow']               = !empty($raw['shadow']);
        $input['overlay']              = !empty($raw['overlay']);
        $input['inventory']            = self::text_to_inventory((string) ($raw['inventory'] ?? ''));
        $stored                        = ADC_Settings::update('banner', $input);

        // Activation and policy version live in the consent group (owned by C2).
        $consent = isset($post['adc_consent']) && is_array($post['adc_consent']) ? $post['adc_consent'] : array();
        $current = ADC_Settings::all('consent');

        $current['enabled'] = !empty($consent['enabled']);
        $bumped             = !empty($consent['policy_bump']);
        if ($bumped) {
            $current['policy_version'] = (int) ($current['policy_version'] ?? 1) + 1;
        }
        ADC_Settings::update('consent', $current);

        $notices = array(
            array('type' => 'success', 'message' => __('Setările bannerului au fost salvate.', 'adc-marketing')),
        );

        // Warn when the saved palette makes refusal harder to see than acceptance.
        foreach (self::prominence_audit(is_array($stored['colors'] ?? null) ? $stored['colors'] : array()) as $finding) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => '<strong>' . esc_html__('Modificare posibil necompliantă:', 'adc-marketing') . '</strong> ' . esc_html((string) $finding['message']),
            );
        }

        if ($bumped) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('Versiunea politicii a fost crescută. Vizitatorii vor fi întrebați din nou.', 'adc-marketing'),
            );
        }

        return $notices;
    }

    /**
     * Layout catalogue: banner class first, built-in fallback second.
     *
     * @return array<string, string> Slug => Romanian label.
     */
    private static function banner_layouts(): array
    {
        if (class_exists('ADC_Consent_Banner') && method_exists('ADC_Consent_Banner', 'layouts')) {
            $layouts = ADC_Consent_Banner::layouts();
            if (is_array($layouts) && !empty($layouts)) {
                return $layouts;
            }
        }

        return self::FALLBACK_LAYOUTS;
    }

    /**
     * Colour keys: banner class constant first, built-in fallback second.
     *
     * @return string[]
     */
    private static function color_keys(): array
    {
        if (class_exists('ADC_Consent_Banner') && defined('ADC_Consent_Banner::COLOR_KEYS')) {
            $keys = ADC_Consent_Banner::COLOR_KEYS;
            if (is_array($keys) && !empty($keys)) {
                return array_values(array_map('strval', $keys));
            }
        }

        return self::FALLBACK_COLOR_KEYS;
    }

    /**
     * Preset catalogue: banner class first, built-in fallback second.
     *
     * @param string $accent Stored brand accent (used by the fallback "brand" preset).
     * @return array<string, array{label: string, colors: array<string, string>, radius: int}>
     */
    private static function banner_presets(string $accent): array
    {
        if (class_exists('ADC_Consent_Banner') && method_exists('ADC_Consent_Banner', 'presets')) {
            $presets = ADC_Consent_Banner::presets();
            if (is_array($presets) && !empty($presets)) {
                return $presets;
            }
        }

        return self::fallback_presets($accent);
    }

    /**
     * Built-in preset catalogue (fallback while the banner class grows presets()).
     *
     * @param string $accent Brand accent colour.
     * @return array<string, array{label: string, colors: array<string, string>, radius: int}>
     */
    private static function fallback_presets(string $accent): array
    {
        if (null === sanitize_hex_color($accent)) {
            $accent = '#1d4ed8';
        }

        return array(
            'light'    => array(
                'label'  => __('Luminos', 'adc-marketing'),
                'colors' => array(
                    'bg'            => '#ffffff',
                    'text'          => '#1d2327',
                    'muted'         => '#50575e',
                    'border'         => '#dcdcde',
                    'link'          => '#1d4ed8',
                    'accept_bg'     => '#1d4ed8',
                    'accept_text'   => '#ffffff',
                    'reject_bg'     => '#50575e',
                    'reject_text'   => '#ffffff',
                    'customize_bg'  => '#ffffff',
                    'customize_text' => '#1d2327',
                ),
                'radius' => 8,
            ),
            'dark'     => array(
                'label'  => __('Întunecat', 'adc-marketing'),
                'colors' => array(
                    'bg'            => '#1d2327',
                    'text'          => '#ffffff',
                    'muted'         => '#c3c4c7',
                    'border'         => '#3c434a',
                    'link'          => '#9ec1ff',
                    'accept_bg'     => '#3b82f6',
                    'accept_text'   => '#ffffff',
                    'reject_bg'     => '#6b7280',
                    'reject_text'   => '#ffffff',
                    'customize_bg'  => '#1d2327',
                    'customize_text' => '#ffffff',
                ),
                'radius' => 8,
            ),
            'brand'    => array(
                'label'  => __('Culoare brand', 'adc-marketing'),
                'colors' => array(
                    'bg'            => '#ffffff',
                    'text'          => '#1d2327',
                    'muted'         => '#50575e',
                    'border'         => '#dcdcde',
                    'link'          => $accent,
                    'accept_bg'     => $accent,
                    'accept_text'   => '#ffffff',
                    'reject_bg'     => '#50575e',
                    'reject_text'   => '#ffffff',
                    'customize_bg'  => '#ffffff',
                    'customize_text' => '#1d2327',
                ),
                'radius' => 8,
            ),
            'minimal'  => array(
                'label'  => __('Minimal (monocrom)', 'adc-marketing'),
                'colors' => array(
                    'bg'            => '#ffffff',
                    'text'          => '#000000',
                    'muted'         => '#555555',
                    'border'         => '#000000',
                    'link'          => '#000000',
                    'accept_bg'     => '#000000',
                    'accept_text'   => '#ffffff',
                    'reject_bg'     => '#000000',
                    'reject_text'   => '#ffffff',
                    'customize_bg'  => '#ffffff',
                    'customize_text' => '#000000',
                ),
                'radius' => 4,
            ),
            'contrast' => array(
                'label'  => __('Contrast ridicat (WCAG AAA)', 'adc-marketing'),
                'colors' => array(
                    'bg'            => '#000000',
                    'text'          => '#ffffff',
                    'muted'         => '#e5e5e5',
                    'border'         => '#ffffff',
                    'link'          => '#ffff00',
                    'accept_bg'     => '#ffff00',
                    'accept_text'   => '#000000',
                    'reject_bg'     => '#ffffff',
                    'reject_text'   => '#000000',
                    'customize_bg'  => '#000000',
                    'customize_text' => '#ffffff',
                ),
                'radius' => 4,
            ),
        );
    }

    /**
     * Colour field groups (contract order): Fundal și text / Buton Acceptă /
     * Buton Refuză / Buton Setări / Link.
     *
     * @return array<string, array{label: string, keys: string[]}>
     */
    private static function color_groups(): array
    {
        return array(
            'base'      => array(
                'label' => __('Fundal și text', 'adc-marketing'),
                'keys'  => array('bg', 'text', 'muted', 'border'),
            ),
            'accept'    => array(
                'label' => __('Buton Acceptă', 'adc-marketing'),
                'keys'  => array('accept_bg', 'accept_text'),
            ),
            'reject'    => array(
                'label' => __('Buton Refuză', 'adc-marketing'),
                'keys'  => array('reject_bg', 'reject_text'),
            ),
            'customize' => array(
                'label' => __('Buton Setări', 'adc-marketing'),
                'keys'  => array('customize_bg', 'customize_text'),
            ),
            'link'      => array(
                'label' => __('Link', 'adc-marketing'),
                'keys'  => array('link'),
            ),
        );
    }

    /**
     * Romanian label for one colour key.
     *
     * @param string $key Colour key.
     * @return string
     */
    private static function color_label(string $key): string
    {
        $labels = array(
            'bg'             => __('Fundal', 'adc-marketing'),
            'text'           => __('Text', 'adc-marketing'),
            'muted'          => __('Text secundar', 'adc-marketing'),
            'border'         => __('Bordură', 'adc-marketing'),
            'link'           => __('Link', 'adc-marketing'),
            'accept_bg'      => __('Fundal buton Acceptă', 'adc-marketing'),
            'accept_text'    => __('Text buton Acceptă', 'adc-marketing'),
            'reject_bg'      => __('Fundal buton Refuză', 'adc-marketing'),
            'reject_text'    => __('Text buton Refuză', 'adc-marketing'),
            'customize_bg'   => __('Fundal buton Setări', 'adc-marketing'),
            'customize_text' => __('Text buton Setări', 'adc-marketing'),
        );

        return $labels[$key] ?? $key;
    }

    /**
     * Contrast pair checked inside each colour group.
     *
     * @param string $group_id Group id from color_groups().
     * @return array{bg: string, fg: string}|null
     */
    private static function group_contrast_pair(string $group_id): ?array
    {
        $pairs = array(
            'base'      => array('bg' => 'bg', 'fg' => 'text'),
            'accept'    => array('bg' => 'accept_bg', 'fg' => 'accept_text'),
            'reject'    => array('bg' => 'reject_bg', 'fg' => 'reject_text'),
            'customize' => array('bg' => 'customize_bg', 'fg' => 'customize_text'),
        );

        return $pairs[$group_id] ?? null;
    }

    /**
     * Render the WCAG AA contrast warning placeholder for a colour group
     * (toggled live by admin-banner.js).
     *
     * @param string $group_id Group id from color_groups().
     */
    private static function render_contrast_warning(string $group_id): void
    {
        $pair = self::group_contrast_pair($group_id);
        if (null === $pair) {
            return;
        }
        ?>
        <p class="description adc-contrast-warning" id="adc-warn-<?php echo esc_attr($group_id); ?>" data-bg-key="<?php echo esc_attr($pair['bg']); ?>" data-fg-key="<?php echo esc_attr($pair['fg']); ?>" hidden>
            <?php esc_html_e('Contrastul dintre text și fundal este sub 4,5:1 (WCAG AA). Alegeți culori mai contrastante, ca textul să fie ușor de citit.', 'adc-marketing'); ?>
        </p>
        <?php
    }

    /**
     * Button order catalogue: banner class first, built-in fallback second.
     *
     * @return array<string, array{label: string, actions: string[]}>
     */
    private static function button_orders(): array
    {
        if (class_exists('ADC_Consent_Banner') && method_exists('ADC_Consent_Banner', 'button_orders')) {
            $orders = ADC_Consent_Banner::button_orders();
            if (is_array($orders) && !empty($orders)) {
                return $orders;
            }
        }

        return array(
            'accept_customize_reject' => array(
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
     * Stored button order, always a known slug.
     *
     * @param array $banner Banner settings.
     * @return string
     */
    private static function stored_button_order(array $banner): string
    {
        $order = (string) ($banner['button_order'] ?? 'accept_customize_reject');

        return array_key_exists($order, self::button_orders()) ? $order : 'accept_customize_reject';
    }

    /**
     * Action keys of the stored order, for the live preview markup.
     *
     * @param array $banner Banner settings.
     * @return string[]
     */
    private static function preview_order(array $banner): array
    {
        $orders = self::button_orders();

        return $orders[self::stored_button_order($banner)]['actions'];
    }

    /**
     * Stored accept spacing, clamped to the allowed range.
     *
     * @param mixed $stored Stored value.
     * @return int
     */
    private static function clamped_gap(mixed $stored): int
    {
        $min  = defined('ADC_Consent_Banner::ACCEPT_GAP_MIN') ? (int) ADC_Consent_Banner::ACCEPT_GAP_MIN : 0;
        $max  = defined('ADC_Consent_Banner::ACCEPT_GAP_MAX') ? (int) ADC_Consent_Banner::ACCEPT_GAP_MAX : 48;
        $default = defined('ADC_Consent_Banner::ACCEPT_GAP_DEFAULT') ? (int) ADC_Consent_Banner::ACCEPT_GAP_DEFAULT : 16;

        if (!is_numeric($stored)) {
            return $default;
        }

        return max($min, min($max, (int) $stored));
    }

    /**
     * Audit catalogue (thresholds + Romanian messages) from the banner class.
     *
     * @return array
     */
    private static function audit_rules(): array
    {
        if (class_exists('ADC_Consent_Banner') && method_exists('ADC_Consent_Banner', 'audit_rules')) {
            $rules = ADC_Consent_Banner::audit_rules();
            if (is_array($rules) && !empty($rules)) {
                return $rules;
            }
        }

        return array(
            'blendContrast' => 1.35,
            'uiContrast'    => 3.0,
            'textContrast'  => 4.5,
            'prominenceGap' => 4.0,
            'messages'      => array(),
        );
    }

    /**
     * Colour-prominence findings for the current palette (empty when the banner
     * class is unavailable).
     *
     * @param array<string, string> $colors Colour values.
     * @return array<int, array{code: string, keys: string[], args: array<int, string>, message: string}>
     */
    private static function prominence_audit(array $colors): array
    {
        if (!class_exists('ADC_Consent_Banner') || !method_exists('ADC_Consent_Banner', 'prominence_audit')) {
            return array();
        }

        $findings = ADC_Consent_Banner::prominence_audit($colors);

        return is_array($findings) ? $findings : array();
    }

    /**
     * "Poate fi o încălcare a GDPR" panel: findings for the stored palette, kept
     * live by admin-banner.js when an admin edits a colour. Rendered empty/hidden
     * when nothing is wrong, so the tab stays quiet for compliant palettes.
     *
     * @param array<string, string> $colors Colour values.
     */
    private static function render_compliance_panel(array $colors): void
    {
        $findings = self::prominence_audit($colors);
        ?>
        <div
            class="adc-compliance"
            id="adc-banner-compliance"
            <?php echo empty($findings) ? 'hidden' : ''; ?>
        >
            <h4 class="adc-compliance__title"><?php esc_html_e('Verificare conformitate: proeminența butoanelor', 'adc-marketing'); ?></h4>
            <ul class="adc-compliance__list" id="adc-banner-compliance-list">
                <?php foreach ($findings as $finding) : ?>
                    <li data-code="<?php echo esc_attr((string) $finding['code']); ?>"><?php echo esc_html((string) $finding['message']); ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="adc-compliance__hint"><?php esc_html_e('Modificați culorile, nu dimensiunea: „Acceptă” și „Refuză” trebuie să rămână la fel de vizibile și de ușor de apăsat.', 'adc-marketing'); ?></p>
        </div>
        <?php
    }

    /**
     * Render colour keys unknown to the fixed groups (forward-compat).
     *
     * @param array<string, string> $colors Merged colour values.
     */
    private static function render_extra_colors(array $colors): void
    {
        $known = array();
        foreach (self::color_groups() as $group) {
            foreach ($group['keys'] as $key) {
                $known[] = $key;
            }
        }

        $extra = array_diff(self::color_keys(), $known);
        if (empty($extra)) {
            return;
        }
        ?>
        <h4><?php esc_html_e('Alte culori', 'adc-marketing'); ?></h4>
        <table class="form-table" role="presentation"><tbody>
            <?php foreach ($extra as $key) : ?>
                <tr>
                    <th scope="row"><label for="adc-banner-color-<?php echo esc_attr($key); ?>"><?php echo esc_html(self::color_label($key)); ?></label></th>
                    <td>
                        <input type="text" id="adc-banner-color-<?php echo esc_attr($key); ?>" class="adc-color-field" name="adc_banner[colors][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($colors[$key] ?? '#ffffff'); ?>" data-default-color="<?php echo esc_attr($colors[$key] ?? '#ffffff'); ?>" />
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    /**
     * Stored brand accent, with a safe default. Reads the top-level `accent`
     * value first (what the sanitizer stores) and the legacy `colors.accent`
     * second; an empty or invalid value never reaches the form.
     *
     * @param array|null $banner Banner settings (read when omitted).
     * @return string Hex colour.
     */
    private static function stored_accent(?array $banner = null): string
    {
        if (null === $banner) {
            $banner = ADC_Settings::all('banner');
        }

        $candidates = array(
            (string) ($banner['accent'] ?? ''),
            (string) ($banner['colors']['accent'] ?? ''),
        );

        foreach ($candidates as $candidate) {
            $hex = sanitize_hex_color($candidate);
            if (null !== $hex && '' !== $hex) {
                return strtolower($hex);
            }
        }

        return '#2563eb';
    }

    /**
     * Stored colours merged over the selected preset (graceful migration of
     * old `bg/text/accent`-only values).
     *
     * @param array $banner  Stored banner settings.
     * @param array $presets Preset catalogue.
     * @param string $preset Selected preset slug.
     * @return array<string, string>
     */
    private static function merged_colors(array $banner, array $presets, string $preset): array
    {
        $base = array();
        if ('custom' !== $preset && isset($presets[$preset]['colors']) && is_array($presets[$preset]['colors'])) {
            $base = $presets[$preset]['colors'];
        } elseif (isset($presets['light']['colors']) && is_array($presets['light']['colors'])) {
            $base = $presets['light']['colors'];
        }

        $stored = (isset($banner['colors']) && is_array($banner['colors'])) ? $banner['colors'] : array();

        $merged = array();
        foreach (self::color_keys() as $key) {
            $value = (string) ($stored[$key] ?? ($base[$key] ?? '#ffffff'));
            $merged[$key] = null !== sanitize_hex_color($value) ? strtolower($value) : (string) ($base[$key] ?? '#ffffff');
        }

        return $merged;
    }

    /**
     * Stored radius clamped to 0–24, falling back to the preset radius.
     *
     * @param mixed  $stored  Stored radius value.
     * @param array  $presets Preset catalogue.
     * @param string $preset  Selected preset slug.
     * @return int
     */
    private static function clamped_radius(mixed $stored, array $presets, string $preset): int
    {
        if (is_numeric($stored)) {
            return max(0, min(24, (int) $stored));
        }

        if ('custom' !== $preset && isset($presets[$preset]['radius'])) {
            return max(0, min(24, (int) $presets[$preset]['radius']));
        }

        return 8;
    }

    /**
     * Map legacy layout slugs to the contract slugs.
     *
     * @param string $layout Stored layout slug.
     * @return string
     */
    private static function normalize_layout(string $layout): string
    {
        if ('modal' === $layout) {
            return 'center_modal';
        }

        if (array_key_exists($layout, self::banner_layouts())) {
            return $layout;
        }

        return 'bar_bottom';
    }

    /**
     * Inline CSS custom properties for the preview/banner root.
     *
     * @param array<string, string> $colors Colour values.
     * @param int                   $radius Corner radius in px.
     * @param bool                  $shadow Whether the shadow is enabled.
     * @param int                   $gap    Spacing after the Accept button in px.
     * @return string
     */
    private static function style_string(array $colors, int $radius, bool $shadow, int $gap = 16): string
    {
        $parts = array();
        foreach (self::color_keys() as $key) {
            $parts[] = '--adc-' . str_replace('_', '-', $key) . ':' . $colors[$key];
        }
        $parts[] = '--adc-radius:' . max(0, min(24, $radius)) . 'px';
        $parts[] = '--adc-accept-gap:' . self::clamped_gap($gap) . 'px';
        $parts[] = '--adc-shadow:' . ($shadow ? '0 10px 30px rgba(0, 0, 0, 0.25)' : 'none');

        return implode(';', $parts) . ';';
    }

    /**
     * Plugin asset base URL (constant-free for uninstall/CLI contexts).
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

    /**
     * Inventory rows to editable text (one cookie per line).
     *
     * @param array $rows Inventory rows.
     * @return string
     */
    private static function inventory_to_text(array $rows): string
    {
        $lines = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = implode(
                ' | ',
                array(
                    (string) ($row['name'] ?? '') . (!empty($row['wildcard']) ? '*' : ''),
                    (string) ($row['category'] ?? ''),
                    (string) ($row['provider'] ?? ''),
                    (string) ($row['purpose'] ?? ''),
                    (string) ($row['duration'] ?? ''),
                    !empty($row['wildcard']) ? 'da' : 'nu',
                )
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Editable text back to inventory rows. Malformed lines are skipped.
     *
     * @param string $text Textarea content.
     * @return array
     */
    private static function text_to_inventory(string $text): array
    {
        $rows = array();

        foreach (preg_split('/\R/', $text) ?: array() as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || '' === $parts[0]) {
                continue;
            }

            $rows[] = array(
                'name'     => $parts[0],
                'category' => $parts[1],
                'provider' => $parts[2] ?? '',
                'purpose'  => $parts[3] ?? '',
                'duration' => $parts[4] ?? '',
                'wildcard' => in_array(strtolower($parts[5] ?? ''), array('da', 'yes', '1'), true),
            );
        }

        return $rows;
    }
}
