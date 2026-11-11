<?php
/** Admin interface for custom tracking events. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Custom event rules list and editor. */
final class ADC_Admin_Tab_Custom_Events implements ADC_Admin_Tab_Interface, ADC_Module_Interface
{
    use ADC_Admin_Help_Content;

    private static bool $booted = false;

    /** Register the admin-only asset hook once. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
    }

    /** Enqueue this tab's assets only on its own screen. */
    public static function enqueue_assets(string $hook): void
    {
        if ('toplevel_page_' . ADC_Admin_Shell::PAGE_SLUG !== $hook
            || !current_user_can('manage_options')
            || !isset($_GET['tab'])
            || !is_scalar($_GET['tab'])
            || 'custom-events' !== sanitize_key((string) wp_unslash($_GET['tab']))) {
            return;
        }

        $base = defined('ADC_MARKETING_PLUGIN_URL') ? rtrim((string) ADC_MARKETING_PLUGIN_URL, '/') . '/assets/' : '';
        if ('' === $base) {
            return;
        }
        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        wp_enqueue_style(
            'adc-admin-custom-events',
            $base . 'css/admin-custom-events.css',
            array(),
            ADC_Admin_Shell::asset_version('css/admin-custom-events.css', $version)
        );
        wp_enqueue_script(
            'adc-admin-custom-events',
            $base . 'js/admin-custom-events.js',
            array(),
            ADC_Admin_Shell::asset_version('js/admin-custom-events.js', $version),
            array('in_footer' => true, 'strategy' => 'defer')
        );
        if (class_exists('ADC_Picker') && ADC_Picker::available()) {
            $protocol_file = 'js/picker-protocol.js';
            wp_enqueue_script(
                'adc-picker-protocol',
                $base . $protocol_file,
                array(),
                ADC_Admin_Shell::asset_version($protocol_file, $version),
                array('in_footer' => true, 'strategy' => 'defer')
            );
            $picker_file = 'js/admin-picker.js';
            wp_enqueue_script(
                'adc-admin-picker',
                $base . $picker_file,
                array('adc-picker-protocol', 'adc-admin-custom-events'),
                ADC_Admin_Shell::asset_version($picker_file, $version),
                array('in_footer' => true, 'strategy' => 'defer')
            );
            wp_localize_script('adc-admin-picker', 'adcPickerL10n', array(
                'title' => __('Selector vizual', 'adc-marketing'),
                'loading' => __('Se încarcă site-ul…', 'adc-marketing'),
                'timeout' => __('Selectorul nu a putut porni în această pagină. Cauze posibile: un plugin de cache servește o pagină veche sau serverul blochează afișarea în cadru (X-Frame-Options).', 'adc-marketing'),
                'retry' => __('Reîncearcă', 'adc-marketing'),
                'openPopup' => __('Deschide în fereastră nouă', 'adc-marketing'),
                'popupBlocked' => __('Browserul a blocat fereastra nouă. Permite ferestrele pop-up pentru acest site și încearcă din nou.', 'adc-marketing'),
                'close' => __('Închide', 'adc-marketing'),
                'pathLabel' => __('Cale', 'adc-marketing'),
                'load' => __('Încarcă', 'adc-marketing'),
                'desktop' => __('Desktop', 'adc-marketing'),
                'tablet' => __('Tabletă (768px)', 'adc-marketing'),
                'mobile' => __('Mobil (390px)', 'adc-marketing'),
                'modeLabel' => __('Mod', 'adc-marketing'),
                'modeSelect' => __('Selectează', 'adc-marketing'),
                'modeNavigate' => __('Navighează', 'adc-marketing'),
                'freeze' => __('Îngheață în 3 s', 'adc-marketing'),
                'unfreeze' => __('Dezgheață', 'adc-marketing'),
                'freezing' => __('Îngheț în %s s…', 'adc-marketing'),
                'underCursor' => __('Sub cursor', 'adc-marketing'),
                'noSelection' => __('Niciun element selectat.', 'adc-marketing'),
                'idLabel' => __('ID', 'adc-marketing'),
                'classLabel' => __('Clase', 'adc-marketing'),
                'authNote' => __('Vezi site-ul ca administrator autentificat; unele elemente pot diferi pentru vizitatori.', 'adc-marketing'),
                'navigationNote' => __('În modul Navighează, scripturile altor pluginuri pot trimite propriile statistici.', 'adc-marketing'),
                'frameTitle' => __('Previzualizare site pentru selecție', 'adc-marketing'),
                'scopeLabel' => __('Prioritatea selectorilor', 'adc-marketing'),
                'thisOnly' => __('Doar acest element', 'adc-marketing'),
                'allSimilar' => __('Toate elementele similare', 'adc-marketing'),
                'candidates' => __('Candidați de selector', 'adc-marketing'),
                'matches' => __('%s potriviri pe această pagină', 'adc-marketing'),
                'similarWarning' => __('Se potrivește cu toate elementele de acest tip, inclusiv cu cele adăugate ulterior. Regula se va declanșa pe oricare dintre ele.', 'adc-marketing'),
                'editSelector' => __('Editează selectorul', 'adc-marketing'),
                'test' => __('Testează', 'adc-marketing'),
                'clearSelection' => __('Șterge selecția', 'adc-marketing'),
                'useSelector' => __('Folosește acest selector', 'adc-marketing'),
                'invalid' => __('Selector nevalid.', 'adc-marketing'),
                'testTimeout' => __('Testul nu a primit răspuns. Reîncarcă pagina din previzualizare.', 'adc-marketing'),
                'matchCount' => __('%s potriviri', 'adc-marketing'),
                'zeroMatches' => __('0 potriviri', 'adc-marketing'),
                'notPicked' => __('Selectorul nu include elementul ales.', 'adc-marketing'),
                'blocked' => __('Linkurile externe și formularele sunt dezactivate în modul de selecție.', 'adc-marketing'),
                'errorInternal' => __('Nu s-a putut genera un selector pentru acest element. Alege alt element sau părintele lui (tasta P).', 'adc-marketing'),
                'invalidPath' => __('Calea introdusă nu este validă.', 'adc-marketing'),
                'confirmClose' => __('Ai o selecție nefolosită. Închizi selectorul?', 'adc-marketing'),
                'popupNote' => __('Previzualizarea este deschisă într-o fereastră nouă.', 'adc-marketing'),
                'rateLimit' => __('Prea multe sesiuni de selecție. Încearcă din nou peste câteva minute.', 'adc-marketing'),
                'tokenError' => __('Nu s-a putut porni selectorul vizual. Reîncarcă pagina și încearcă din nou.', 'adc-marketing'),
                'renewError' => __('Sesiunea selectorului nu a putut fi reînnoită. Închide și redeschide selectorul.', 'adc-marketing'),
                'rateLimited' => __('Ai atins limita de teste. Încearcă din nou într-o secundă.', 'adc-marketing'),
                'testing' => __('Se testează selectorul…', 'adc-marketing'),
                'notice_iframe' => __('Conținutul din interiorul unui cadru extern nu poate fi selectat. Poți folosi declanșatorul „Vizibil” pe cadrul întreg.', 'adc-marketing'),
                'notice_shadow' => __('Elementul este într-un Shadow DOM; a fost selectat elementul gazdă.', 'adc-marketing'),
                'notice_canvas' => __('Zonele din interiorul acestui element nu pot fi selectate separat; a fost selectat elementul întreg.', 'adc-marketing'),
                'notice_svg' => __('Ai ales o pictogramă; a fost selectat elementul care o conține.', 'adc-marketing'),
                'strategy_id' => __('după ID', 'adc-marketing'),
                'strategy_test_attr' => __('după atribut de test', 'adc-marketing'),
                'strategy_data_attr' => __('după atribut', 'adc-marketing'),
                'strategy_aria' => __('după text accesibil', 'adc-marketing'),
                'strategy_name' => __('după nume', 'adc-marketing'),
                'strategy_href' => __('după adresă de link', 'adc-marketing'),
                'strategy_pattern' => __('după tipar de link', 'adc-marketing'),
                'strategy_class' => __('după clasă', 'adc-marketing'),
                'strategy_anchored' => __('după elementul părinte', 'adc-marketing'),
                'strategy_nth' => __('după poziția în pagină', 'adc-marketing'),
            ));
            $picker_css = 'css/admin-picker.css';
            $picker_css_path = defined('ADC_MARKETING_PLUGIN_DIR') ? rtrim((string) ADC_MARKETING_PLUGIN_DIR, '/\\') . '/assets/' . $picker_css : '';
            if ('' !== $picker_css_path && file_exists($picker_css_path)) {
                wp_enqueue_style(
                    'adc-admin-picker',
                    $base . $picker_css,
                    array('adc-admin-custom-events'),
                    ADC_Admin_Shell::asset_version($picker_css, $version)
                );
            }
        }
    }

    /** Tab slug. */
    public function slug(): string
    {
        return 'custom-events';
    }

    /** Tab label. */
    public function label(): string
    {
        return __('Evenimente custom', 'adc-marketing');
    }

    /** Top-level navigation group. */
    public function group(): string
    {
        return '';
    }

    /** Tab ordering. */
    public function order(): int
    {
        return 22;
    }

    /** Show the save bar only when editing a rule. */
    public function shows_save_button(): bool
    {
        return $this->is_editor();
    }

    /** Report whether at least one rule is enabled. */
    public function page_status(): ?bool
    {
        $rules = ADC_Custom_Events::rules();
        if (array() === $rules) {
            return null;
        }
        foreach ($rules as $rule) {
            if (!empty($rule['enabled'])) {
                return true;
            }
        }
        return false;
    }

    /** Status labels used by the shell. */
    public function page_status_labels(): array
    {
        return array(__('Activ', 'adc-marketing'), __('Inactiv', 'adc-marketing'));
    }

    /** Determine the requested list/editor route from the URL. */
    private function is_editor(): bool
    {
        return isset($_GET['rule']) && is_scalar($_GET['rule']) && '' !== sanitize_text_field((string) wp_unslash($_GET['rule']));
    }

    /** Render list or editor inside the shell-owned form. */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = ADC_Custom_Events::settings();
        $rules = !empty($settings['locked']) ? array() : ADC_Custom_Events::rules();
        $view = $this->is_editor() ? 'editor' : 'list';
        $requested_rule = isset($_GET['rule']) && is_scalar($_GET['rule']) ? sanitize_text_field((string) wp_unslash($_GET['rule'])) : '';
        $is_new_rule = 'editor' === $view && 'new' === $requested_rule;
        $picker_available = class_exists('ADC_Picker') && is_callable(array('ADC_Picker', 'available'))
            ? (bool) call_user_func(array('ADC_Picker', 'available'))
            : false;
        $home_origin = (string) (wp_parse_url(home_url('/'), PHP_URL_SCHEME) . '://' . wp_parse_url(home_url('/'), PHP_URL_HOST));
        $port = wp_parse_url(home_url('/'), PHP_URL_PORT);
        if (is_int($port)) {
            $home_origin .= ':' . $port;
        }
        $ajax_url = admin_url('admin-ajax.php');
        $home_path = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/');
        $nonce = wp_create_nonce('adc_custom_events');
        ?>
        <div class="adc-ce" data-ce-view="<?php echo esc_attr($view); ?>" data-ce-rule-new="<?php echo esc_attr($is_new_rule ? '1' : '0'); ?>" data-ce-ajax="<?php echo esc_url($ajax_url); ?>" data-ce-nonce="<?php echo esc_attr($nonce); ?>" data-picker-available="<?php echo esc_attr($picker_available ? '1' : '0'); ?>" data-home-origin="<?php echo esc_attr($home_origin); ?>" data-picker-home-path="<?php echo esc_attr($home_path); ?>">
            <input type="hidden" name="revision" value="<?php echo esc_attr((string) ($settings['revision'] ?? 0)); ?>" />
            <?php if (!empty($settings['locked'])) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Regulile au fost salvate de o versiune mai nouă a pluginului. Actualizează pluginul înainte să le editezi.', 'adc-marketing'); ?></p></div>
            <?php elseif ('editor' === $view) : ?>
                <?php $this->render_editor($settings, $rules, $picker_available); ?>
            <?php else : ?>
                <?php $this->render_list($rules); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Save one editor or list operation using the shell nonce contract. */
    public function save(array $post): array
    {
        if (!current_user_can('manage_options')) {
            return $this->error(__('Nu aveți permisiunea necesară.', 'adc-marketing'));
        }
        $nonce = isset($post['_adc_nonce']) && is_string($post['_adc_nonce']) ? $post['_adc_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'adc_save_tab_custom-events')) {
            return $this->error(__('Securitatea formularului nu a putut fi verificată. Reîncarcă pagina și încearcă din nou.', 'adc-marketing'));
        }

        $stored = ADC_Custom_Events::settings();
        if (!empty($stored['locked'])) {
            return $this->error(__('Regulile au fost salvate de o versiune mai nouă a pluginului. Actualizează pluginul înainte să le editezi.', 'adc-marketing'));
        }
        $revision = isset($post['revision']) ? (int) $post['revision'] : -1;
        if ($revision !== (int) ($stored['revision'] ?? 0)) {
            return $this->error(__('Regulile au fost modificate între timp (de exemplu, în alt tab). Reîncarcă pagina și reaplică modificările.', 'adc-marketing'));
        }

        $rules = array_values($stored['rules'] ?? array());
        $operation = isset($post['adc_ce_op']) && is_string($post['adc_ce_op']) ? sanitize_text_field($post['adc_ce_op']) : '';
        if ('' !== $operation) {
            return $this->apply_operation($operation, $rules, $stored);
        }

        $raw = isset($post['rule']) && is_array($post['rule']) ? $post['rule'] : array();
        $existing_id = isset($raw['id']) && is_string($raw['id']) ? sanitize_text_field($raw['id']) : '';
        $existing_index = null;
        $others = array();
        foreach ($rules as $index => $rule) {
            if (($rule['id'] ?? '') === $existing_id && '' !== $existing_id) {
                $existing_index = $index;
            } else {
                $others[] = $rule;
            }
        }
        if (null === $existing_index && count($rules) >= ADC_Custom_Event_Rule::MAX_RULES) {
            return $this->error(__('Ai atins limita de 50 de reguli.', 'adc-marketing'));
        }

        $name_suffix = isset($raw['name_suffix']) && is_string($raw['name_suffix']) ? trim($raw['name_suffix']) : '';
        $raw['name'] = 'custom_' . $name_suffix;
        $raw['enabled'] = !empty($raw['enabled']);
        $raw['destinations'] = isset($raw['destinations']) && is_array($raw['destinations']) ? $raw['destinations'] : array();
        $raw['destinations']['server_mirror'] = !empty($raw['destinations']['server_mirror']);
        $raw['destinations']['platforms'] = isset($raw['destinations']['platforms']) && is_array($raw['destinations']['platforms']) ? $raw['destinations']['platforms'] : array();
        $raw['trigger'] = isset($raw['trigger']) && is_array($raw['trigger']) ? $raw['trigger'] : array();
        $raw['page_filter'] = isset($raw['page_filter']) && is_array($raw['page_filter']) ? $raw['page_filter'] : array();
        $raw['page_filter']['paths'] = $this->normalize_lines($raw['page_filter']['paths'] ?? array());
        $raw['params'] = $this->normalize_params($raw['params'] ?? array());
        $raw['trigger']['depths'] = isset($raw['trigger']['depths']) && is_array($raw['trigger']['depths']) ? $raw['trigger']['depths'] : array();
        if (null !== $existing_index) {
            $raw['id'] = $existing_id;
            $raw['created'] = (int) ($rules[$existing_index]['created'] ?? time());
        } else {
            $raw['id'] = ADC_Custom_Event_Rule::new_id();
            $raw['created'] = time();
        }
        $raw['updated'] = time();
        $validation = ADC_Custom_Event_Rule::validate($raw, $others);
        if (null === $validation['rule']) {
            $this->store_draft($raw);
            $notices = array_map(static fn(string $message): array => array('type' => 'error', 'message' => $message), array_slice($validation['errors'], 0, 10));
            $notices[] = array(
                'type' => 'error',
                'message' => sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(add_query_arg(array('page' => ADC_Admin_Shell::PAGE_SLUG, 'tab' => 'custom-events', 'rule' => 'draft'), admin_url('admin.php'))),
                    esc_html__('Reia modificările din editor.', 'adc-marketing')
                ),
            );
            return $notices;
        }

        if (null === $existing_index) {
            $rules[] = $validation['rule'];
        } else {
            $rules[$existing_index] = $validation['rule'];
        }
        if (!$this->persist($rules, (int) $stored['revision'] + 1)) {
            return $this->error(__('Modificările nu au putut fi salvate. Reîncarcă pagina și încearcă din nou.', 'adc-marketing'));
        }
        delete_transient($this->draft_key());
        return $this->success(__('Regula a fost salvată.', 'adc-marketing'));
    }

    /** Render the rules table or its empty state. */
    private function render_list(array $rules): void
    {
        ADC_Admin_Help::section(
            __('Reguli', 'adc-marketing'),
            __('Fiecare regulă trimite un eveniment când vizitatorul face acțiunea aleasă.', 'adc-marketing'),
            'custom_events_rules',
            $this->help_custom_events_rules()
        );
        $count = count($rules);
        $at_limit = $count >= ADC_Custom_Event_Rule::MAX_RULES;
        $add_url = add_query_arg(array('page' => ADC_Admin_Shell::PAGE_SLUG, 'tab' => 'custom-events', 'rule' => 'new'), admin_url('admin.php'));
        echo '<div class="adc-ce-toolbar"><a class="button button-primary" href="' . esc_url($add_url) . '"' . ($at_limit ? ' aria-disabled="true"' : '') . '>' . esc_html__('Adaugă regulă', 'adc-marketing') . '</a>';
        echo '<details class="adc-ce-presets"><summary class="button">' . esc_html__('Din presetare', 'adc-marketing') . '</summary><ul>';
        $presets = array(
            'tel' => __('Telefon', 'adc-marketing'), 'mailto' => __('E-mail', 'adc-marketing'), 'whatsapp' => __('WhatsApp', 'adc-marketing'),
            'download' => __('Descărcare fișier', 'adc-marketing'), 'outbound' => __('Link extern', 'adc-marketing'), 'cf7' => __('Formular CF7', 'adc-marketing'),
            'wpforms' => __('Formular WPForms', 'adc-marketing'), 'elementor' => __('Formular Elementor', 'adc-marketing'), 'scroll' => __('Adâncime de scroll', 'adc-marketing'), 'timer' => __('Timp pe pagină', 'adc-marketing'),
        );
        foreach ($presets as $preset => $label) {
            $url = add_query_arg(array('page' => ADC_Admin_Shell::PAGE_SLUG, 'tab' => 'custom-events', 'rule' => 'new', 'preset' => $preset), admin_url('admin.php'));
            echo '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        echo '</ul></details><span class="adc-ce-count">' . esc_html(sprintf(__('%1$d din 50 reguli', 'adc-marketing'), $count)) . '</span></div>';
        if ($at_limit) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Ai atins limita de 50 de reguli. Șterge sau combină reguli înainte să adaugi altele.', 'adc-marketing') . '</p></div>';
        }
        if (array() === $rules) {
            echo '<div class="adc-ce-empty"><p>' . esc_html__('Nu ai încă evenimente custom.', 'adc-marketing') . '</p><a class="button button-primary" href="' . esc_url($add_url) . '">' . esc_html__('Adaugă prima regulă', 'adc-marketing') . '</a></div>';
            return;
        }
        $headers = array(__('Regulă', 'adc-marketing'), __('Declanșator', 'adc-marketing'), __('Pagini', 'adc-marketing'), __('Destinații', 'adc-marketing'), __('Stare', 'adc-marketing'), __('Acțiuni', 'adc-marketing'));
        echo '<div class="adc-ce-table-box"><table class="widefat striped adc-ce-table"><thead><tr>';
        foreach ($headers as $header) {
            echo '<th scope="col">' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            $label = (string) ($rule['label'] ?? '');
            $edit_url = add_query_arg(array('page' => ADC_Admin_Shell::PAGE_SLUG, 'tab' => 'custom-events', 'rule' => $id), admin_url('admin.php'));
            echo '<tr class="adc-ce-row" data-ce-id="' . esc_attr($id) . '"><th scope="row"><a href="' . esc_url($edit_url) . '">' . esc_html($label) . '</a><br><code>' . esc_html((string) ($rule['name'] ?? '')) . '</code></th>';
            echo '<td>' . esc_html($this->trigger_label($rule)) . '</td><td>' . esc_html($this->page_label($rule)) . '</td><td>' . $this->destination_labels($rule) . '</td><td>' . ADC_Admin_Help::status(!empty($rule['enabled']), __('Activ', 'adc-marketing'), __('Oprit', 'adc-marketing')) . '</td><td class="adc-ce-actions">';
            echo '<a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Editează', 'adc-marketing') . '</a> ';
            echo '<button type="submit" class="button button-small" name="adc_ce_op" value="' . esc_attr((!empty($rule['enabled']) ? 'toggle-off:' : 'toggle-on:') . $id) . '">' . esc_html(!empty($rule['enabled']) ? __('Oprește', 'adc-marketing') : __('Pornește', 'adc-marketing')) . '<span class="screen-reader-text">' . esc_html(sprintf(__(' regula %s', 'adc-marketing'), $label)) . '</span></button> ';
            echo '<button type="submit" class="button button-small" name="adc_ce_op" value="' . esc_attr('duplicate:' . $id) . '">' . esc_html__('Duplică', 'adc-marketing') . '<span class="screen-reader-text">' . esc_html(sprintf(__(' regula %s', 'adc-marketing'), $label)) . '</span></button> ';
            echo '<button type="submit" class="button button-small" name="adc_ce_op" value="' . esc_attr('delete:' . $id) . '">' . esc_html__('Șterge', 'adc-marketing') . '<span class="screen-reader-text">' . esc_html(sprintf(__(' regula %s', 'adc-marketing'), $label)) . '</span></button>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Render the complete rule editor. */
    private function render_editor(array $settings, array $rules, bool $picker_available): void
    {
        $requested = isset($_GET['rule']) && is_scalar($_GET['rule']) ? sanitize_text_field((string) wp_unslash($_GET['rule'])) : 'new';
        $preset = isset($_GET['preset']) && is_scalar($_GET['preset']) ? sanitize_key((string) wp_unslash($_GET['preset'])) : '';
        $is_new_rule = 'new' === $requested;
        $draft = 'draft' === $requested ? get_transient($this->draft_key()) : false;
        $rule = is_array($draft) ? $draft : array();
        if (array() === $rule && 'new' !== $requested && 'draft' !== $requested) {
            foreach ($rules as $candidate) {
                if (($candidate['id'] ?? '') === $requested) {
                    $rule = $candidate;
                    break;
                }
            }
        }
        if ('new' === $requested && '' !== $preset) {
            $rule = $this->apply_preset($rule, $preset);
        }
        $rule = $this->editor_defaults($rule);
        $trigger = $rule['trigger'];
        $filter = $rule['page_filter'];
        $dest = $rule['destinations'];
        $params = $rule['params'];
        $back_url = add_query_arg(array('page' => ADC_Admin_Shell::PAGE_SLUG, 'tab' => 'custom-events'), admin_url('admin.php'));
        ?>
        <input type="hidden" name="rule[id]" value="<?php echo esc_attr((string) $rule['id']); ?>" />
        <div class="adc-ce-editor">
            <div class="adc-ce-errors" role="alert" hidden></div>
            <?php $this->editor_section(__('Eveniment', 'adc-marketing')); ?>
            <?php echo ADC_Admin_Help::icon('adc-help-custom-events-name', __('Nume eveniment', 'adc-marketing'), $this->help_custom_events_name()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output. ?>
            <?php $this->field(__('Denumire internă', 'adc-marketing'), 'adc-ce-label', 'rule[label]', (string) $rule['label'], __('O denumire ușor de recunoscut în lista regulilor.', 'adc-marketing')); ?>
            <div class="adc-ce-field"><label for="adc-ce-name"><?php esc_html_e('Nume eveniment', 'adc-marketing'); ?></label><div class="adc-ce-name"><span aria-hidden="true">custom_</span><input id="adc-ce-name" name="rule[name_suffix]" type="text" maxlength="33" value="<?php echo esc_attr(substr((string) $rule['name'], 7)); ?>" /></div><p class="description"><?php esc_html_e('Litere mici, cifre și _. Maxim 33 de caractere după custom_.', 'adc-marketing'); ?></p></div>
            <div class="adc-ce-field"><label for="adc-ce-enabled"><input id="adc-ce-enabled" type="checkbox" name="rule[enabled]" value="1" <?php checked(!empty($rule['enabled'])); ?> /> <?php esc_html_e('Activ', 'adc-marketing'); ?></label></div>

            <?php $this->editor_section(__('Declanșator', 'adc-marketing')); ?>
            <?php echo ADC_Admin_Help::icon('adc-help-custom-events-triggers', __('Declanșator', 'adc-marketing'), $this->help_custom_events_triggers()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output. ?>
            <fieldset class="adc-ce-trigger-choice"><legend><?php esc_html_e('Tipul declanșatorului', 'adc-marketing'); ?></legend><div role="radiogroup" aria-label="<?php esc_attr_e('Tipul declanșatorului', 'adc-marketing'); ?>">
                <?php foreach (array('click' => __('Click', 'adc-marketing'), 'form_success' => __('Formular', 'adc-marketing'), 'visible' => __('Vizibil', 'adc-marketing'), 'scroll_depth' => __('Scroll', 'adc-marketing'), 'timer' => __('Timp', 'adc-marketing')) as $type => $label) : ?><label class="button adc-ce-segment"><input type="radio" name="rule[trigger][type]" value="<?php echo esc_attr($type); ?>" <?php checked($trigger['type'] === $type); ?> /> <?php echo esc_html($label); ?></label><?php endforeach; ?>
            </div></fieldset>
            <?php $this->render_click_fields($trigger, $is_new_rule ? $preset : ''); ?>
            <div class="adc-ce-selector-field" data-ce-selector-field hidden><?php $this->field(__('Selector CSS', 'adc-marketing'), 'adc-ce-selector', 'rule[trigger][selector]', (string) $trigger['selector'], __('Folosește un selector CSS valid, de maximum 200 de caractere.', 'adc-marketing')); ?><?php if ($picker_available) : ?><button type="button" class="button adc-ce-picker" aria-haspopup="dialog"><?php esc_html_e('Alege vizual', 'adc-marketing'); ?></button><?php else : ?><p class="description adc-ce-picker-unavailable"><?php esc_html_e('Selectorul vizual funcționează doar când panoul de administrare și site-ul sunt pe același domeniu.', 'adc-marketing'); ?></p><?php endif; ?></div>
            <?php $this->render_form_fields($trigger); ?>
            <?php $this->render_visible_fields($trigger); ?>
            <?php $this->render_scroll_fields($trigger); ?>
            <?php $this->render_timer_fields($trigger); ?>

            <?php $this->editor_section(__('Frecvență și pagini', 'adc-marketing')); ?>
            <fieldset class="adc-ce-field"><legend><?php esc_html_e('Frecvență', 'adc-marketing'); ?></legend><?php foreach (array('once' => __('O dată pe pagină', 'adc-marketing'), 'every' => __('De fiecare dată', 'adc-marketing')) as $fire => $label) : ?><label><input type="radio" name="rule[fire]" value="<?php echo esc_attr($fire); ?>" <?php checked($rule['fire'] === $fire); ?> /> <?php echo esc_html($label); ?></label> <?php endforeach; ?><p class="description"><?php esc_html_e('Pentru Vizibil, Scroll și Timp frecvența este întotdeauna o dată pe pagină.', 'adc-marketing'); ?></p></fieldset>
            <fieldset class="adc-ce-field"><legend><?php esc_html_e('Pagini', 'adc-marketing'); ?></legend><?php foreach (array('all' => __('Toate', 'adc-marketing'), 'include' => __('Doar pe', 'adc-marketing'), 'exclude' => __('Exceptând', 'adc-marketing')) as $mode => $label) : ?><label><input type="radio" name="rule[page_filter][mode]" value="<?php echo esc_attr($mode); ?>" <?php checked($filter['mode'] === $mode); ?> /> <?php echo esc_html($label); ?></label> <?php endforeach; ?><label for="adc-ce-paths" class="screen-reader-text"><?php esc_html_e('Căi de pagină, câte una pe linie', 'adc-marketing'); ?></label><textarea id="adc-ce-paths" name="rule[page_filter][paths]" rows="4" maxlength="2000"><?php echo esc_textarea(implode("\n", $filter['paths'])); ?></textarea><p class="description"><?php esc_html_e('Introdu maximum 10 căi, câte una pe linie, fără parametri.', 'adc-marketing'); ?></p></fieldset>

            <?php $this->editor_section(__('Parametri', 'adc-marketing')); ?>
            <div class="adc-ce-params"><table class="widefat"><thead><tr><th scope="col"><?php esc_html_e('Cheie', 'adc-marketing'); ?></th><th scope="col"><?php esc_html_e('Valoare', 'adc-marketing'); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e('Acțiuni', 'adc-marketing'); ?></span></th></tr></thead><tbody>
                <?php foreach ($params as $index => $param) : ?><tr class="adc-ce-param"><td><label class="screen-reader-text" for="adc-ce-param-key-<?php echo esc_attr((string) $index); ?>"><?php esc_html_e('Cheie parametru', 'adc-marketing'); ?></label><input id="adc-ce-param-key-<?php echo esc_attr((string) $index); ?>" name="rule[params][<?php echo esc_attr((string) $index); ?>][key]" value="<?php echo esc_attr((string) $param['key']); ?>" maxlength="40" /></td><td><label class="screen-reader-text" for="adc-ce-param-value-<?php echo esc_attr((string) $index); ?>"><?php esc_html_e('Valoare parametru', 'adc-marketing'); ?></label><input id="adc-ce-param-value-<?php echo esc_attr((string) $index); ?>" name="rule[params][<?php echo esc_attr((string) $index); ?>][value]" value="<?php echo esc_attr((string) $param['value']); ?>" maxlength="100" /></td><td><button type="button" class="button button-small adc-ce-remove-param" aria-label="<?php echo esc_attr(sprintf(__('Șterge parametrul %d', 'adc-marketing'), $index + 1)); ?>"><?php esc_html_e('Șterge', 'adc-marketing'); ?></button></td></tr><?php endforeach; ?>
            </tbody></table><button type="button" class="button adc-ce-add-param"><?php esc_html_e('Adaugă parametru', 'adc-marketing'); ?></button></div>
            <?php echo ADC_Admin_Help::icon('adc-help-custom-events-tokens', __('Token-uri disponibile', 'adc-marketing'), $this->help_custom_events_tokens()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output. ?>
            <div class="adc-ce-tokens" aria-label="<?php esc_attr_e('Token-uri disponibile', 'adc-marketing'); ?>"><?php foreach (ADC_Custom_Event_Rule::tokens() as $token => $types) : ?><button type="button" class="button button-small" data-ce-token="<?php echo esc_attr($token); ?>" data-ce-token-types="<?php echo esc_attr(implode(' ', $types)); ?>" <?php echo in_array($trigger['type'], $types, true) ? '' : 'hidden'; ?>><?php echo esc_html($token); ?></button><?php endforeach; ?></div>
            <?php $this->field(__('Valoare', 'adc-marketing'), 'adc-ce-value', 'rule[value]', (string) ($rule['value'] ?? ''), __('Opțional; între 0 și 1.000.000.', 'adc-marketing'), 'number'); ?>
            <?php $this->field(__('Monedă', 'adc-marketing'), 'adc-ce-currency', 'rule[currency]', (string) $rule['currency'], __('Cod ISO 4217 din trei litere, de exemplu RON.', 'adc-marketing')); ?>

            <?php $this->editor_section(__('Consimțământ și destinații', 'adc-marketing')); ?>
            <?php echo ADC_Admin_Help::icon('adc-help-custom-events-destinations', __('Consimțământ și destinații', 'adc-marketing'), $this->help_custom_events_destinations()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output. ?>
            <fieldset class="adc-ce-field"><legend><?php esc_html_e('Categorie de consimțământ', 'adc-marketing'); ?></legend><?php foreach (array('statistics' => __('Statistici', 'adc-marketing'), 'marketing' => __('Marketing', 'adc-marketing')) as $category => $label) : ?><label><input type="radio" name="rule[consent_category]" value="<?php echo esc_attr($category); ?>" <?php checked($rule['consent_category'] === $category); ?> /> <?php echo esc_html($label); ?></label> <?php endforeach; ?></fieldset>
            <fieldset class="adc-ce-field"><legend><?php esc_html_e('Destinații', 'adc-marketing'); ?></legend><label><input type="checkbox" checked disabled /> <?php esc_html_e('dataLayer', 'adc-marketing'); ?></label> <input type="hidden" name="rule[destinations][server_mirror]" value="0" /><label><input type="checkbox" name="rule[destinations][server_mirror]" value="1" <?php checked(!empty($dest['server_mirror'])); ?> /> <?php esc_html_e('Server', 'adc-marketing'); ?></label>
                <?php foreach (array('ga4' => __('GA4', 'adc-marketing'), 'sgtm' => __('sGTM', 'adc-marketing'), 'meta' => __('Meta', 'adc-marketing')) as $platform => $label) : $configured = $this->platform_configured($platform); $checked = in_array($platform, $dest['platforms'], true); ?><label><input type="checkbox" name="rule[destinations][platforms][]" value="<?php echo esc_attr($platform); ?>" <?php checked($checked); ?> <?php disabled(!$configured || ('meta' === $platform && 'statistics' === $rule['consent_category'])); ?> /> <?php echo esc_html($label); ?></label><?php if (!$configured) : ?><span class="description"><?php esc_html_e('Integrarea nu este configurată.', 'adc-marketing'); ?></span><?php endif; ?><?php endforeach; ?>
                <p class="description"><?php esc_html_e('Meta cere categoria Marketing.', 'adc-marketing'); ?></p>
            </fieldset>

            <?php ADC_Admin_Help::advanced_start(__('Detalii tehnice', 'adc-marketing')); ?>
            <p><?php esc_html_e('ID regulă:', 'adc-marketing'); ?> <code><?php echo esc_html((string) $rule['id']); ?></code></p><pre class="adc-ce-example">dataLayer.push({ event: "<?php echo esc_html((string) $rule['name']); ?>" });</pre>
            <?php ADC_Admin_Help::advanced_end(); ?>
            <div class="adc-ce-editor-actions"><a class="button" href="<?php echo esc_url($back_url); ?>"><?php esc_html_e('Înapoi la listă', 'adc-marketing'); ?></a></div>
        </div>
        <?php
    }

    /** Render click-specific fields. */
    private function render_click_fields(array $trigger, string $preset): void
    {
        $selected = '' !== $preset ? $preset : $trigger['preset'];
        echo '<fieldset class="adc-ce-trigger" data-ce-trigger="click"><legend>' . esc_html__('Setări pentru click', 'adc-marketing') . '</legend>';
        echo '<label for="adc-ce-click-preset">' . esc_html__('Presetare', 'adc-marketing') . '</label><select id="adc-ce-click-preset" name="rule[trigger][preset]">';
        foreach (array('' => __('Fără', 'adc-marketing'), 'tel' => __('Telefon', 'adc-marketing'), 'mailto' => __('E-mail', 'adc-marketing'), 'whatsapp' => __('WhatsApp', 'adc-marketing'), 'download' => __('Descărcare fișier', 'adc-marketing'), 'outbound' => __('Link extern', 'adc-marketing')) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected === $value, true, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        $this->field(__('Filtru text', 'adc-marketing'), 'adc-ce-match-text', 'rule[trigger][match_text]', (string) $trigger['match_text'], __('Potrivește textul vizibil al linkului.', 'adc-marketing'));
        $this->field(__('Filtru link', 'adc-marketing'), 'adc-ce-match-href', 'rule[trigger][match_href]', (string) $trigger['match_href'], __('Potrivește o parte din adresa linkului, fără query.', 'adc-marketing'));
        echo '</fieldset>';
    }

    /** Render successful form submission fields. */
    private function render_form_fields(array $trigger): void
    {
        echo '<fieldset class="adc-ce-trigger" data-ce-trigger="form_success" hidden><legend>' . esc_html__('Setări pentru formular', 'adc-marketing') . '</legend><label for="adc-ce-form-preset">' . esc_html__('Tip formular', 'adc-marketing') . '</label><select id="adc-ce-form-preset" name="rule[trigger][form_preset]">';
        foreach (array('cf7' => __('CF7', 'adc-marketing'), 'wpforms' => __('WPForms', 'adc-marketing'), 'elementor' => __('Elementor', 'adc-marketing'), 'woo_coupon' => __('Cupon Woo', 'adc-marketing')) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($trigger['form_preset'] === $value, true, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        $this->field(__('ID formular', 'adc-marketing'), 'adc-ce-form-id', 'rule[trigger][form_id]', (string) $trigger['form_id'], __('Lasă necompletat pentru orice formular de acest tip.', 'adc-marketing'));
        echo '</fieldset>';
    }

    /** Render visibility trigger fields. */
    private function render_visible_fields(array $trigger): void
    {
        echo '<fieldset class="adc-ce-trigger" data-ce-trigger="visible" hidden><legend>' . esc_html__('Setări pentru vizibilitate', 'adc-marketing') . '</legend>';
        echo '<label for="adc-ce-threshold">' . esc_html__('Prag', 'adc-marketing') . '</label><select id="adc-ce-threshold" name="rule[trigger][threshold]">';
        // Pairs, not float keys: PHP truncates float array keys to int (0.25 → 0).
        $thresholds = array(array(0.1, '10%'), array(0.25, '25%'), array(0.5, '50%'), array(0.75, '75%'), array(1.0, '100%'));
        $stored_threshold = (float) $trigger['threshold'];
        $threshold_known = false;
        foreach ($thresholds as [$value, $label]) {
            // Compare with a tolerance: stored thresholds come from round(x / 0.05) * 0.05 and may carry float noise.
            $is_selected = abs($stored_threshold - $value) < 0.0001;
            $threshold_known = $threshold_known || $is_selected;
            echo '<option value="' . esc_attr((string) $value) . '" ' . selected($is_selected, true, false) . '>' . esc_html($label) . '</option>';
        }
        if (!$threshold_known) {
            $percent = rtrim(rtrim(number_format($stored_threshold * 100, 2, '.', ''), '0'), '.');
            $label = sprintf(__('%s%% (valoare existentă)', 'adc-marketing'), $percent);
            echo '<option value="' . esc_attr((string) $stored_threshold) . '" selected="selected">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        $this->field(__('Timp minim (ms)', 'adc-marketing'), 'adc-ce-min-visible', 'rule[trigger][min_visible_ms]', (string) $trigger['min_visible_ms'], __('Cât timp elementul trebuie să rămână vizibil.', 'adc-marketing'), 'number');
        echo '</fieldset>';
    }

    /** Render selected scroll thresholds. */
    private function render_scroll_fields(array $trigger): void
    {
        echo '<fieldset class="adc-ce-trigger" data-ce-trigger="scroll_depth" hidden><legend>' . esc_html__('Praguri de scroll', 'adc-marketing') . '</legend>';
        foreach (array(25, 50, 75, 90) as $depth) {
            echo '<label><input type="checkbox" name="rule[trigger][depths][]" value="' . esc_attr((string) $depth) . '" ' . checked(in_array($depth, $trigger['depths'], true), true, false) . ' /> ' . esc_html(sprintf(__('%d%%', 'adc-marketing'), $depth)) . '</label> ';
        }
        echo '</fieldset>';
    }

    /** Render timer duration. */
    private function render_timer_fields(array $trigger): void
    {
        echo '<fieldset class="adc-ce-trigger" data-ce-trigger="timer" hidden><legend>' . esc_html__('Setări pentru timp', 'adc-marketing') . '</legend>';
        $this->field(__('Durată (secunde)', 'adc-marketing'), 'adc-ce-seconds', 'rule[trigger][seconds]', (string) $trigger['seconds'], __('Valoarea trebuie să fie între 5 și 1800 de secunde.', 'adc-marketing'), 'number');
        echo '</fieldset>';
    }

    /** Render a section heading. */
    private function editor_section(string $title): void
    {
        echo '<h3 class="adc-ce-section-title">' . esc_html($title) . '</h3>';
    }

    /** Render an escaped text or numeric input. */
    private function field(string $label, string $id, string $name, string $value, string $description, string $type = 'text'): void
    {
        echo '<div class="adc-ce-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '" /><p class="description">' . esc_html($description) . '</p></div>';
    }

    /** Apply one list preset to a new editor draft. */
    private function apply_preset(array $rule, string $preset): array
    {
        $trigger = isset($rule['trigger']) && is_array($rule['trigger']) ? $rule['trigger'] : array();
        if (in_array($preset, ADC_Custom_Event_Rule::CLICK_PRESETS, true) && '' !== $preset) {
            $trigger['type'] = 'click';
            $trigger['preset'] = $preset;
        } elseif (in_array($preset, ADC_Custom_Event_Rule::FORM_PRESETS, true)) {
            $trigger['type'] = 'form_success';
            $trigger['form_preset'] = $preset;
        } elseif ('scroll' === $preset) {
            $trigger['type'] = 'scroll_depth';
            $trigger['depths'] = array(25, 50, 75, 90);
        } elseif ('timer' === $preset) {
            $trigger['type'] = 'timer';
            $trigger['seconds'] = 30;
        }
        $rule['trigger'] = $trigger;
        return $rule;
    }

    /** Normalize an editor rule with safe defaults. */
    private function editor_defaults(array $rule): array
    {
        $defaults = array(
            'id' => '', 'label' => '', 'name' => 'custom_', 'enabled' => true,
            'trigger' => array('type' => 'click', 'preset' => '', 'selector' => '', 'match_text' => '', 'match_href' => '', 'form_preset' => 'cf7', 'form_id' => '', 'threshold' => 0.5, 'min_visible_ms' => 0, 'depths' => array(25), 'seconds' => 30),
            'fire' => 'once', 'page_filter' => array('mode' => 'all', 'paths' => array()), 'params' => array(), 'value' => null, 'currency' => '',
            'consent_category' => 'statistics', 'destinations' => array('server_mirror' => false, 'platforms' => array()),
        );
        foreach (array('trigger', 'page_filter', 'destinations') as $key) {
            $rule[$key] = array_merge($defaults[$key], isset($rule[$key]) && is_array($rule[$key]) ? $rule[$key] : array());
        }
        return array_merge($defaults, $rule);
    }

    /** Display a short trigger summary in the table. */
    private function trigger_label(array $rule): string
    {
        $trigger = $rule['trigger'] ?? array();
        $type = (string) ($trigger['type'] ?? '');
        return match ($type) {
            'click' => __('Click', 'adc-marketing') . ('' !== (string) ($trigger['preset'] ?? '') ? ' · ' . (string) $trigger['preset'] . ':' : ''),
            'form_success' => sprintf(__('Formular · %s%s', 'adc-marketing'), strtoupper((string) ($trigger['form_preset'] ?? '')), '' !== (string) ($trigger['form_id'] ?? '') ? ' #' . (string) $trigger['form_id'] : ''),
            'visible' => sprintf(__('Vizibil · %s%%', 'adc-marketing'), (string) ((float) ($trigger['threshold'] ?? 0.5) * 100)),
            'scroll_depth' => __('Scroll · ', 'adc-marketing') . implode('/', array_map('intval', $trigger['depths'] ?? array())),
            'timer' => sprintf(__('Timp · %d s', 'adc-marketing'), (int) ($trigger['seconds'] ?? 0)),
            default => '',
        };
    }

    /** Display the page filter summary. */
    private function page_label(array $rule): string
    {
        $filter = $rule['page_filter'] ?? array();
        if ('include' === ($filter['mode'] ?? 'all')) {
            return sprintf(__('%d incluse', 'adc-marketing'), count($filter['paths'] ?? array()));
        }
        if ('exclude' === ($filter['mode'] ?? 'all')) {
            return sprintf(__('%d excluse', 'adc-marketing'), count($filter['paths'] ?? array()));
        }
        return __('Toate', 'adc-marketing');
    }

    /** Escape destination labels for table display. */
    private function destination_labels(array $rule): string
    {
        $labels = array(__('dataLayer', 'adc-marketing'));
        $dest = $rule['destinations'] ?? array();
        if (!empty($dest['server_mirror'])) {
            $labels[] = 'Server';
        }
        foreach (array('ga4' => 'GA4', 'sgtm' => 'sGTM', 'meta' => 'Meta') as $key => $label) {
            if (in_array($key, $dest['platforms'] ?? array(), true)) {
                $labels[] = $label;
            }
        }
        $html = '';
        foreach ($labels as $label) {
            $html .= '<span class="adc-ce-pill">' . esc_html($label) . '</span> ';
        }
        return $html;
    }

    /** Determine whether the requested server integration has configuration. */
    private function platform_configured(string $platform): bool
    {
        $values = ADC_Settings::all($platform);
        if ('ga4' === $platform) {
            return '' !== trim((string) ($values['measurement_id'] ?? ''))
                && '' !== ADC_Settings::secret('ga4', 'api_secret');
        }
        if ('meta' === $platform) {
            return '' !== trim((string) ($values['pixel_id'] ?? ''))
                && '' !== ADC_Settings::secret('meta', 'access_token');
        }
        if ('sgtm' === $platform) {
            return !empty($values['enabled']) && '' !== trim((string) ($values['server_container_url'] ?? ''));
        }
        return false;
    }

    /** Split textarea input into trimmed non-empty lines. */
    private function normalize_lines($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: array();
        }
        if (!is_array($value)) {
            return array();
        }
        return array_values(array_filter(array_map(static fn($line): string => trim((string) $line), $value), static fn(string $line): bool => '' !== $line));
    }

    /** Keep only well-shaped parameter rows for model validation. */
    private function normalize_params($value): array
    {
        if (!is_array($value)) {
            return array();
        }
        $params = array();
        foreach ($value as $param) {
            if (is_array($param)) {
                $params[] = array('key' => (string) ($param['key'] ?? ''), 'value' => (string) ($param['value'] ?? ''));
            }
        }
        return $params;
    }

    /** Apply a protected list operation and persist atomically through ADC_Settings. */
    private function apply_operation(string $operation, array $rules, array $stored): array
    {
        $parts = explode(':', $operation, 2);
        $action = sanitize_key((string) ($parts[0] ?? ''));
        $id = sanitize_text_field((string) ($parts[1] ?? ''));
        $index = null;
        foreach ($rules as $key => $rule) {
            if (($rule['id'] ?? '') === $id) {
                $index = $key;
                break;
            }
        }
        if (null === $index || !in_array($action, array('toggle-on', 'toggle-off', 'duplicate', 'delete'), true)) {
            return $this->error(__('Regula selectată nu a putut fi găsită.', 'adc-marketing'));
        }
        if ('delete' === $action) {
            array_splice($rules, $index, 1);
            $message = __('Regula a fost ștearsă.', 'adc-marketing');
        } elseif ('toggle-on' === $action || 'toggle-off' === $action) {
            $rules[$index]['enabled'] = 'toggle-on' === $action;
            $message = 'toggle-on' === $action ? __('Regula a fost pornită.', 'adc-marketing') : __('Regula a fost oprită.', 'adc-marketing');
        } else {
            if (count($rules) >= ADC_Custom_Event_Rule::MAX_RULES) {
                return $this->error(__('Ai atins limita de 50 de reguli.', 'adc-marketing'));
            }
            $copy = $rules[$index];
            $copy['id'] = ADC_Custom_Event_Rule::new_id();
            $copy['created'] = time();
            $copy['updated'] = time();
            $copy['enabled'] = false;
            $copy['name'] = $this->unique_copy_name((string) $copy['name'], $rules);
            $suffix = ' — ' . __('copie', 'adc-marketing');
            $copy['label'] = $this->truncate_utf8((string) $copy['label'], 80 - $this->utf8_length($suffix)) . $suffix;
            $rules[] = $copy;
            $message = __('Regula a fost duplicată (oprită).', 'adc-marketing');
        }
        if (!$this->persist($rules, (int) $stored['revision'] + 1)) {
            return $this->error(__('Modificările nu au putut fi salvate. Reîncarcă pagina și încearcă din nou.', 'adc-marketing'));
        }
        return $this->success($message);
    }

    /** Create an unused copy name within the schema's 40-character maximum. */
    private function unique_copy_name(string $name, array $rules): string
    {
        $base = substr($name, 0, 40 - strlen('_copy')) . '_copy';
        $candidate = $base;
        $suffix = 2;
        while ($this->name_in_use($candidate, $rules)) {
            $tail = '_copy' . $suffix++;
            $candidate = substr($name, 0, 40 - strlen($tail)) . $tail;
        }
        return $candidate;
    }

    /** Check whether a rule name is already used. */
    private function name_in_use(string $name, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (($rule['name'] ?? '') === $name) {
                return true;
            }
        }
        return false;
    }

    /** Persist rules and verify the sanitizer retained the complete list. */
    private function persist(array $rules, int $revision): bool
    {
        $expected = array_values($rules);
        ADC_Settings::update(ADC_Custom_Events::GROUP, array('rules' => $expected, 'revision' => $revision));
        $after = ADC_Custom_Events::settings();
        return (int) ($after['revision'] ?? -1) === $revision
            && count($after['rules'] ?? array()) === count($expected)
            && array_column($after['rules'] ?? array(), 'id') === array_column($expected, 'id');
    }

    /** Count UTF-8 characters without requiring mbstring. */
    private function utf8_length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        return preg_match_all('/./us', $value, $matches) ?: 0;
    }

    /** Truncate UTF-8 text without splitting a multibyte character. */
    private function truncate_utf8(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        if (preg_match_all('/./us', $value, $matches)) {
            return implode('', array_slice($matches[0], 0, $length));
        }
        return '';
    }

    /** Save failed editor input briefly for restoration. */
    private function store_draft(array $draft): void
    {
        set_transient($this->draft_key(), $draft, 60);
    }

    /** Build the per-user draft cache key. */
    private function draft_key(): string
    {
        return 'adc_ce_draft_' . get_current_user_id();
    }

    /** Build a uniform error notice. */
    private function error(string $message): array
    {
        return array(array('type' => 'error', 'message' => $message));
    }

    /** Build a uniform success notice. */
    private function success(string $message): array
    {
        return array(array('type' => 'success', 'message' => $message));
    }
}
