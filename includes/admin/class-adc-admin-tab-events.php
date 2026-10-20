<?php
/**
 * Admin tab "Evenimente & GTM" (docs/ARCHITECTURE.md §4, §5 C4 row).
 *
 * Owns the UI for the `gtm` and `events` settings groups (registered by
 * ADC_Gtm / ADC_Event_Hooks). Inner HTML only — the shell wraps form+nonce
 * (ADC_Admin_Shell, nonce adc_save_tab_events).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Events & GTM settings tab.
 */
final class ADC_Admin_Tab_Events implements ADC_Admin_Tab_Interface
{
    use ADC_Admin_Help_Content;

    /**
     * GTM container pattern (§4 validation rules).
     */
    private const GTM_PATTERN = '/^GTM-[A-Z0-9]+$/';

    /**
     * Toggleable catalogue events (§2.4 table), in display order.
     *
     * Labels go through gettext, so this is a method rather than a class
     * constant (constants cannot call `__()`).
     *
     * @return array<string, string> Event slug => rendered label.
     */
    private static function event_labels(): array
    {
        return array(
            'view_item_list'    => __('view_item_list — listă de produse', 'adc-marketing'),
            'select_item'       => __('select_item — selectare produs din listă', 'adc-marketing'),
            'view_item'         => __('view_item — vizualizare produs', 'adc-marketing'),
            'add_to_cart'       => __('add_to_cart — adăugare în coș', 'adc-marketing'),
            'remove_from_cart'  => __('remove_from_cart — eliminare din coș', 'adc-marketing'),
            'view_cart'         => __('view_cart — vizualizare coș', 'adc-marketing'),
            'begin_checkout'    => __('begin_checkout — începere finalizare', 'adc-marketing'),
            'add_shipping_info' => __('add_shipping_info — informații livrare', 'adc-marketing'),
            'add_payment_info'  => __('add_payment_info — informații plată', 'adc-marketing'),
            'purchase'          => __('purchase — comandă plasată', 'adc-marketing'),
            'refund'            => __('refund — rambursare', 'adc-marketing'),
            'search'            => __('search — căutare', 'adc-marketing'),
            'sign_up'           => __('sign_up — cont nou', 'adc-marketing'),
            'login'             => __('login — autentificare', 'adc-marketing'),
            'generate_lead'     => __('generate_lead — lead (formular)', 'adc-marketing'),
        );
    }

    /**
     * Tab slug.
     *
     * @return string
     */
    public function slug(): string
    {
        return 'events';
    }

    /**
     * Tab label (RO).
     *
     * @return string
     */
    public function label(): string
    {
        return __('Evenimente & GTM', 'adc-marketing');
    }

    /**
     * Top-level group.
     *
     * @return string
     */
    public function group(): string
    {
        return '';
    }

    /**
     * Tab order (banner 10, events 20, …).
     *
     * @return int
     */
    public function order(): int
    {
        return 20;
    }

    /**
     * Render inner HTML (no <form>, no nonce — shell wraps them).
     */
    public function render(): void
    {
        $gtm    = ADC_Settings::all('gtm');
        $events = ADC_Settings::all('events');

        ADC_Admin_Help::section(
            __('Google Tag Manager', 'adc-marketing'),
            __('Ce face această secțiune: decide dacă și cum se încarcă containerul GTM în paginile site-ului, pe browserul vizitatorilor.', 'adc-marketing')
        );

        echo '<div class="adc-help-options adc-settings-section">';

        ADC_Admin_Help::row(
            __('ID container GTM', 'adc-marketing'),
            '<input type="text" id="adc_gtm_container_id" name="gtm[container_id]" class="regular-text" placeholder="GTM-XXXXXXX" value="' . esc_attr((string) ($gtm['container_id'] ?? '')) . '" />',
            __('Cu ID-ul salvat aici se inserează snippet-ul GTM în site; lăsat gol, containerul nu se încarcă deloc.', 'adc-marketing'),
            'adc_gtm_container_id',
            'adc-help-gtm-mode',
            $this->help_gtm_mode()
        );

        ADC_Admin_Help::row(
            __('Mod încărcare', 'adc-marketing'),
            '<select name="gtm[mode]">'
                . '<option value="always_with_defaults" ' . selected(($gtm['mode'] ?? '') === 'always_with_defaults', true, false) . '>' . esc_html__('Întotdeauna (cu seturi implicite de consimțământ)', 'adc-marketing') . '</option>'
                . '<option value="after_consent" ' . selected(($gtm['mode'] ?? '') === 'after_consent', true, false) . '>' . esc_html__('Doar după acordarea consimțământului', 'adc-marketing') . '</option>'
            . '</select>',
            __('Când browserul cere script-ul gtm.js: imediat (dar tag-urile rămân blocate de Consent Mode) sau abia după ce vizitatorul acceptă.', 'adc-marketing'),
            null,
            'adc-help-gtm-load-mode',
            $this->help_gtm_mode()
        );

        ADC_Admin_Help::row(
            __('URL server GTM (sGTM)', 'adc-marketing'),
            '<input type="url" id="adc_gtm_server_url" name="gtm[server_container_url]" class="regular-text" placeholder="https://sgtm.exemplu.ro" value="' . esc_attr((string) ($gtm['server_container_url'] ?? '')) . '" />',
            __('Adresa first-party configurată în tabul „Server GTM”; modificată aici, se suprascrie pentru toate etichetele.', 'adc-marketing'),
            'adc_gtm_server_url'
        );

        ADC_Admin_Help::row(
            __('Nume dataLayer', 'adc-marketing'),
            '<input type="text" id="adc_gtm_datalayer" name="gtm[datalayer_name]" class="regular-text" value="' . esc_attr((string) ($gtm['datalayer_name'] ?? 'dataLayer')) . '" />',
            __('Numele variabilei JS în care pluginul scrie evenimentele; schimbă-l doar dacă containerul dumneavoastră folosește alt nume.', 'adc-marketing'),
            'adc_gtm_datalayer'
        );

        ADC_Admin_Help::row(
            __('Mediu: auth', 'adc-marketing'),
            '<input type="text" id="adc_gtm_env_auth" name="gtm[env_auth]" class="regular-text" value="' . esc_attr((string) ($gtm['env_auth'] ?? '')) . '" />',
            __('Token-ul de acces al unui mediu GTM (Auth Preview), pentru testarea unei versiuni nepublicate a containerului.', 'adc-marketing'),
            'adc_gtm_env_auth'
        );

        ADC_Admin_Help::row(
            __('Mediu: preview', 'adc-marketing'),
            '<input type="text" id="adc_gtm_env_preview" name="gtm[env_preview]" class="regular-text" value="' . esc_attr((string) ($gtm['env_preview'] ?? '')) . '" />',
            __('Eticheta mediului de previzualizare (ex. env-123); ambele valori trebuie completate pentru a activa previzualizarea.', 'adc-marketing'),
            'adc_gtm_env_preview'
        );

        echo '</div>';

        ADC_Admin_Help::section(
            __('Evenimente urmărite', 'adc-marketing'),
            __('Ce face această secțiune: alege ce acțiuni ale vizitatorilor sunt măsurate și trimise către platforme.', 'adc-marketing'),
            'adc-help-events-list',
            $this->help_events_list()
        );

        $event_rows = '';
        foreach (self::event_labels() as $key => $label) {
            $event_rows .= '<label class="adc-help-choice"><input type="checkbox" id="adc_event_' . esc_attr($key) . '" name="events[' . esc_attr($key) . ']" value="1" ' . checked(!empty($events[$key]), true, false) . ' /> ' . esc_html($label) . '</label>';
        }
        $event_rows .= '<label class="adc-help-choice"><input type="checkbox" id="adc_event_server_page_view" name="events[server_page_view]" value="1" ' . checked(!empty($events['server_page_view']), true, false) . ' /> ' . esc_html__('server_page_view — page_view și pe server (deconectat implicit, recomandat off)', 'adc-marketing') . '</label>';

        echo '<div class="adc-help-options adc-settings-section">';
        ADC_Admin_Help::row(
            '',
            $event_rows,
            __('Evenimentele debifate nu apar în dataLayer și nu se trimit nici către Meta, GA4 sau TikTok — chiar dacă platformele sunt activate.', 'adc-marketing')
        );
        echo '</div>';

        ADC_Admin_Help::section(
            __('Opțiuni evenimente', 'adc-marketing'),
            __('Ce face această secțiune: ajustează cum se calculează și unde se înregistrează datele evenimentelor (identificatori, sume, statusuri, excepții).', 'adc-marketing')
        );

        echo '<div class="adc-help-options adc-settings-section">';

        ADC_Admin_Help::row(
            __('Sursă item_id', 'adc-marketing'),
            '<select name="events[item_id_source]">'
                . '<option value="id" ' . selected(($events['item_id_source'] ?? '') === 'id', true, false) . '>' . esc_html__('ID produs WooCommerce', 'adc-marketing') . '</option>'
                . '<option value="sku" ' . selected(($events['item_id_source'] ?? '') === 'sku', true, false) . '>' . esc_html__('SKU', 'adc-marketing') . '</option>'
                . '<option value="gla" ' . selected(($events['item_id_source'] ?? '') === 'gla', true, false) . '>' . esc_html__('Google Listings & Ads (ID GLA)', 'adc-marketing') . '</option>'
            . '</select>',
            __('Ce identificator al produsului ajunge în evenimente; trebuie să fie același folosit în feedul de catalog, altfel produsele nu se potrivesc.', 'adc-marketing'),
            null,
            'adc-help-item-id-source',
            $this->help_item_id_source()
        );

        ADC_Admin_Help::row(
            __('Semantica valorii (value)', 'adc-marketing'),
            '<select name="events[value_mode]">'
                . '<option value="excl_tax_excl_shipping" ' . selected(($events['value_mode'] ?? '') === 'excl_tax_excl_shipping', true, false) . '>' . esc_html__('Preț fără taxe, fără livrare (recomandat GA4)', 'adc-marketing') . '</option>'
                . '<option value="incl_tax" ' . selected(($events['value_mode'] ?? '') === 'incl_tax', true, false) . '>' . esc_html__('Preț cu taxe incluse', 'adc-marketing') . '</option>'
            . '</select>',
            __('Dacă sumele raportate per produs includ sau nu TVA-ul; totalul comenzii se trimite oricum separat pentru Meta/TikTok.', 'adc-marketing'),
            null,
            'adc-help-value-mode',
            $this->help_value_mode()
        );

        $statuses        = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
        $selected_statuses = (array) ($events['purchase_statuses'] ?? array('processing', 'completed'));
        $status_rows      = '';
        foreach ($statuses as $status_key => $status_label) {
            $slug = (string) preg_replace('/^wc-/', '', (string) $status_key);
            $status_rows .= '<label class="adc-help-choice"><input type="checkbox" name="events[purchase_statuses][]" value="' . esc_attr($slug) . '" ' . checked(in_array($slug, $selected_statuses, true), true, false) . ' /> ' . esc_html((string) $status_label) . '</label>';
        }
        ADC_Admin_Help::row(
            __('Statusuri care declanșează purchase (server)', 'adc-marketing'),
            $status_rows,
            __('Comenzile care intră în unul dintre aceste statusuri trimit evenimentul „purchase” către platforme; bifați-le pe cele pe care le considerați vânzări finale.', 'adc-marketing'),
            null,
            'adc-help-purchase-statuses',
            '<p>' . esc_html__('Evenimentul purchase (cea mai importantă conversie pentru reclame) este trimis de pe server când comanda este marcată plătită sau când ajunge la unul dintre statusurile bifate. Dacă bifați prea devreme (ex. pending), raportați comenzi neplătite; dacă bifați prea târziu, conversiile apar întârziat în rapoarte. Statusul „refunded” trimite evenimentul separat „refund”.', 'adc-marketing') . '</p>'
        );

        ADC_Admin_Help::row(
            __('Întârziere trimitere purchase (secunde)', 'adc-marketing'),
            '<input type="number" id="adc_events_delay" name="events[server_delay_seconds]" min="0" max="600" step="1" value="' . esc_attr((string) ($events['server_delay_seconds'] ?? 60)) . '" />',
            __('Câte secunde așteaptă pluginul înainte de trimitere, ca gateway-ul să apuce să confirme plata (implicit 60).', 'adc-marketing'),
            'adc_events_delay'
        );

        $role_names     = function_exists('wp_roles') ? wp_roles()->get_names() : array();
        $excluded_roles = (array) ($events['exclude_roles'] ?? array('administrator', 'shop_manager'));
        $role_rows      = '';
        foreach ($role_names as $role_slug => $role_name) {
            $role_rows .= '<label class="adc-help-choice"><input type="checkbox" name="events[exclude_roles][]" value="' . esc_attr((string) $role_slug) . '" ' . checked(in_array((string) $role_slug, $excluded_roles, true), true, false) . ' /> ' . esc_html((string) $role_name) . '</label>';
        }
        ADC_Admin_Help::row(
            __('Roluri excluse din tracking', 'adc-marketing'),
            $role_rows,
            __('Vizitatorii logați cu aceste roluri nu sunt măsurați deloc — ideal pentru a ține testele interne afară din rapoarte.', 'adc-marketing'),
            null,
            'adc-help-exclude-roles',
            $this->help_exclude_roles()
        );

        ADC_Admin_Help::row(
            __('Taxonomie brand (item_brand)', 'adc-marketing'),
            '<input type="text" id="adc_events_brand_taxonomy" name="events[brand_taxonomy]" class="regular-text" value="' . esc_attr((string) ($events['brand_taxonomy'] ?? 'product_brand')) . '" />',
            __('Numele taxonomiei WooCommerce din care se citește brandul produsului; apare în evenimente ca item_brand.', 'adc-marketing'),
            'adc_events_brand_taxonomy'
        );

        ADC_Admin_Help::row(
            __('Păstrare rânduri sent (zile)', 'adc-marketing'),
            '<input type="number" id="adc_events_sent_days" name="events[outbox_sent_days]" min="1" max="365" step="1" value="' . esc_attr((string) ($events['outbox_sent_days'] ?? 14)) . '" />',
            __('După câte zile se șterg automat din jurnal evenimentele deja trimise cu succes (implicit 14).', 'adc-marketing'),
            'adc_events_sent_days',
            'adc-help-outbox-retention',
            $this->help_outbox_retention()
        );

        ADC_Admin_Help::row(
            __('Păstrare rânduri dead (zile)', 'adc-marketing'),
            '<input type="number" id="adc_events_dead_days" name="events[outbox_dead_days]" min="1" max="365" step="1" value="' . esc_attr((string) ($events['outbox_dead_days'] ?? 30)) . '" />',
            __('După câte zile se șterg evenimentele eșuate definitiv; păstrați-le mai mult dacă reîncercați manual erorile (implicit 30).', 'adc-marketing'),
            'adc_events_dead_days',
            'adc-help-outbox-retention-dead',
            $this->help_outbox_retention()
        );

        echo '</div>';
    }

    /**
     * Save both groups through ADC_Settings::update (typed sanitizers run
     * inside; unknown/invalid values fall back to stored/default).
     *
     * @param array $post wp_unslash($_POST).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        $keys    = array('container_id', 'mode', 'server_container_url', 'datalayer_name', 'env_auth', 'env_preview');
        $defaults = array(
            'container_id'         => '',
            'mode'                 => 'always_with_defaults',
            'server_container_url' => '',
            'datalayer_name'       => 'dataLayer',
            'env_auth'             => '',
            'env_preview'          => '',
        );

        $raw    = isset($post['gtm']) && is_array($post['gtm']) ? $post['gtm'] : array();
        $gtm_input = array();
        foreach ($keys as $key) {
            $gtm_input[$key] = isset($raw[$key]) && is_scalar($raw[$key]) ? (string) $raw[$key] : $defaults[$key];
        }

        $container = strtoupper(trim($gtm_input['container_id']));
        $url       = trim($gtm_input['server_container_url']);

        $events_input = isset($post['events']) && is_array($post['events']) ? $post['events'] : array();

        ADC_Settings::update('gtm', $gtm_input);
        ADC_Settings::update('events', $events_input);

        $notices = array(
            array(
                'type'    => 'success',
                'message' => __('Setările de evenimente și GTM au fost salvate.', 'adc-marketing'),
            ),
        );

        if ('' !== $container && 1 !== preg_match(self::GTM_PATTERN, $container)) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('ID-ul containerului GTM nu este valid (format corect: GTM-XXXXXXX). Valoarea veche a fost păstrată.', 'adc-marketing'),
            );
        }

        if ('' !== $url && !str_starts_with($url, 'https://')) {
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('URL-ul serverului GTM trebuie să înceapă cu https://. Valoarea invalidă a fost ignorată.', 'adc-marketing'),
            );
        }

        return $notices;
    }
}
