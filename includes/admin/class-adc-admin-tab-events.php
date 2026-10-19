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
    /**
     * GTM container pattern (§4 validation rules).
     */
    private const GTM_PATTERN = '/^GTM-[A-Z0-9]+$/';

    /**
     * Toggleable catalogue events (§2.4 table), in display order.
     *
     * @var string[]
     */
    private const EVENT_LABELS = array(
        'view_item_list'    => 'view_item_list — listă de produse',
        'select_item'       => 'select_item — selectare produs din listă',
        'view_item'         => 'view_item — vizualizare produs',
        'add_to_cart'       => 'add_to_cart — adăugare în coș',
        'remove_from_cart'  => 'remove_from_cart — eliminare din coș',
        'view_cart'         => 'view_cart — vizualizare coș',
        'begin_checkout'    => 'begin_checkout — începere finalizare',
        'add_shipping_info' => 'add_shipping_info — informații livrare',
        'add_payment_info'  => 'add_payment_info — informații plată',
        'purchase'          => 'purchase — comandă plasată',
        'refund'            => 'refund — rambursare',
        'search'            => 'search — căutare',
        'sign_up'           => 'sign_up — cont nou',
        'login'             => 'login — autentificare',
        'generate_lead'     => 'generate_lead — lead (formular)',
    );

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
        ?>
        <h2><?php esc_html_e('Google Tag Manager', 'adc-marketing'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="adc_gtm_container_id"><?php esc_html_e('ID container GTM', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="text" id="adc_gtm_container_id" name="gtm[container_id]"
                           class="regular-text" placeholder="GTM-XXXXXXX"
                           value="<?php echo esc_attr((string) ($gtm['container_id'] ?? '')); ?>" />
                    <p class="description"><?php esc_html_e('Format: GTM-XXXXXXX. Lăsați gol pentru a dezactiva încărcarea containerului.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Mod încărcare', 'adc-marketing'); ?></th>
                <td>
                    <select name="gtm[mode]">
                        <option value="always_with_defaults" <?php selected(($gtm['mode'] ?? '') === 'always_with_defaults'); ?>>
                            <?php esc_html_e('Întotdeauna (cu seturi implicite de consimțământ)', 'adc-marketing'); ?>
                        </option>
                        <option value="after_consent" <?php selected(($gtm['mode'] ?? '') === 'after_consent'); ?>>
                            <?php esc_html_e('Doar după acordarea consimțământului', 'adc-marketing'); ?>
                        </option>
                    </select>
                    <p class="description"><?php esc_html_e('Modul „după consimțământ” încarcă containerul la prima categorie acordată (statistici/marketing).', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_gtm_server_url"><?php esc_html_e('URL server GTM (sGTM)', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="url" id="adc_gtm_server_url" name="gtm[server_container_url]"
                           class="regular-text" placeholder="https://sgtm.exemplu.ro"
                           value="<?php echo esc_attr((string) ($gtm['server_container_url'] ?? '')); ?>" />
                    <p class="description"><?php esc_html_e('Opțional, doar https. Folosit pentru loader-ul first-party sGTM.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_gtm_datalayer"><?php esc_html_e('Nume dataLayer', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="text" id="adc_gtm_datalayer" name="gtm[datalayer_name]"
                           class="regular-text" value="<?php echo esc_attr((string) ($gtm['datalayer_name'] ?? 'dataLayer')); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_gtm_env_auth"><?php esc_html_e('Mediu: auth', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="text" id="adc_gtm_env_auth" name="gtm[env_auth]"
                           class="regular-text" value="<?php echo esc_attr((string) ($gtm['env_auth'] ?? '')); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_gtm_env_preview"><?php esc_html_e('Mediu: preview', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="text" id="adc_gtm_env_preview" name="gtm[env_preview]"
                           class="regular-text" value="<?php echo esc_attr((string) ($gtm['env_preview'] ?? '')); ?>" />
                    <p class="description"><?php esc_html_e('Ambele valori de mediu sunt necesare pentru a activa parametrii gtm_auth/gtm_preview.', 'adc-marketing'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Evenimente urmărite', 'adc-marketing'); ?></h2>
        <p class="description"><?php esc_html_e('Bifați evenimentele pe care pluginul trebuie să le genereze. Evenimentele dezactivate nu sunt împinse în dataLayer și nu sunt trimise către server.', 'adc-marketing'); ?></p>
        <table class="form-table" role="presentation">
            <?php foreach (self::EVENT_LABELS as $key => $label) : ?>
                <tr>
                    <td style="width:1px;padding-right:8px;">
                        <input type="checkbox" id="adc_event_<?php echo esc_attr($key); ?>"
                               name="events[<?php echo esc_attr($key); ?>]" value="1"
                               <?php checked(!empty($events[$key])); ?> />
                    </td>
                    <td>
                        <label for="adc_event_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td style="width:1px;padding-right:8px;">
                    <input type="checkbox" id="adc_event_server_page_view"
                           name="events[server_page_view]" value="1"
                           <?php checked(!empty($events['server_page_view'])); ?> />
                </td>
                <td>
                    <label for="adc_event_server_page_view"><?php esc_html_e('server_page_view — page_view și pe server (deconectat implicit, recomandat off)', 'adc-marketing'); ?></label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Opțiuni evenimente', 'adc-marketing'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Sursă item_id', 'adc-marketing'); ?></th>
                <td>
                    <select name="events[item_id_source]">
                        <option value="id" <?php selected(($events['item_id_source'] ?? '') === 'id'); ?>><?php esc_html_e('ID produs WooCommerce', 'adc-marketing'); ?></option>
                        <option value="sku" <?php selected(($events['item_id_source'] ?? '') === 'sku'); ?>><?php esc_html_e('SKU', 'adc-marketing'); ?></option>
                        <option value="gla" <?php selected(($events['item_id_source'] ?? '') === 'gla'); ?>><?php esc_html_e('Google Listings & Ads (ID GLA)', 'adc-marketing'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Semantica valorii (value)', 'adc-marketing'); ?></th>
                <td>
                    <select name="events[value_mode]">
                        <option value="excl_tax_excl_shipping" <?php selected(($events['value_mode'] ?? '') === 'excl_tax_excl_shipping'); ?>>
                            <?php esc_html_e('Preț fără taxe, fără livrare (recomandat GA4)', 'adc-marketing'); ?>
                        </option>
                        <option value="incl_tax" <?php selected(($events['value_mode'] ?? '') === 'incl_tax'); ?>>
                            <?php esc_html_e('Preț cu taxe incluse', 'adc-marketing'); ?>
                        </option>
                    </select>
                    <p class="description"><?php esc_html_e('Totalul comenzii (cu tot cu taxe și livrare) este trimis separat ca adc.order_total pentru Meta/TikTok.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Statusuri care declanșează purchase (server)', 'adc-marketing'); ?></th>
                <td>
                    <?php
                    $statuses      = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
                    $selected_statuses = (array) ($events['purchase_statuses'] ?? array('processing', 'completed'));
                    foreach ($statuses as $status_key => $status_label) {
                        $slug = (string) preg_replace('/^wc-/', '', (string) $status_key);
                        ?>
                        <label style="display:block;margin-bottom:4px;">
                            <input type="checkbox" name="events[purchase_statuses][]" value="<?php echo esc_attr($slug); ?>"
                                <?php checked(in_array($slug, $selected_statuses, true)); ?> />
                            <?php echo esc_html((string) $status_label); ?>
                        </label>
                        <?php
                    }
                    ?>
                    <p class="description"><?php esc_html_e('Se declanșează la marcare ca plătit (woocommerce_payment_complete) sau la schimbarea în unul dintre aceste statusuri.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_events_delay"><?php esc_html_e('Întârziere trimitere purchase (secunde)', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="number" id="adc_events_delay" name="events[server_delay_seconds]"
                           min="0" max="600" step="1"
                           value="<?php echo esc_attr((string) ($events['server_delay_seconds'] ?? 60)); ?>" />
                    <p class="description"><?php esc_html_e('Implicit 60 — lasă gateway-ul să confirme statusul înainte de trimiterea către platforme.', 'adc-marketing'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Roluri excluse din tracking', 'adc-marketing'); ?></th>
                <td>
                    <?php
                    $role_names      = function_exists('wp_roles') ? wp_roles()->get_names() : array();
                    $excluded_roles  = (array) ($events['exclude_roles'] ?? array('administrator', 'shop_manager'));
                    foreach ($role_names as $role_slug => $role_name) {
                        ?>
                        <label style="display:block;margin-bottom:4px;">
                            <input type="checkbox" name="events[exclude_roles][]" value="<?php echo esc_attr((string) $role_slug); ?>"
                                <?php checked(in_array((string) $role_slug, $excluded_roles, true)); ?> />
                            <?php echo esc_html((string) $role_name); ?>
                        </label>
                        <?php
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_events_brand_taxonomy"><?php esc_html_e('Taxonomie brand (item_brand)', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="text" id="adc_events_brand_taxonomy" name="events[brand_taxonomy]"
                           class="regular-text" value="<?php echo esc_attr((string) ($events['brand_taxonomy'] ?? 'product_brand')); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_events_sent_days"><?php esc_html_e('Păstrare rânduri sent (zile)', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="number" id="adc_events_sent_days" name="events[outbox_sent_days]"
                           min="1" max="365" step="1"
                           value="<?php echo esc_attr((string) ($events['outbox_sent_days'] ?? 14)); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="adc_events_dead_days"><?php esc_html_e('Păstrare rânduri dead (zile)', 'adc-marketing'); ?></label>
                </th>
                <td>
                    <input type="number" id="adc_events_dead_days" name="events[outbox_dead_days]"
                           min="1" max="365" step="1"
                           value="<?php echo esc_attr((string) ($events['outbox_dead_days'] ?? 30)); ?>" />
                </td>
            </tr>
        </table>
        <?php
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
