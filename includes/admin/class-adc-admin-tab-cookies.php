<?php
/** Admin interface for scanning and classifying cookies. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

/** Cookie scanner tab and its protected AJAX endpoints. */
final class ADC_Admin_Tab_Cookies implements ADC_Admin_Tab_Interface, ADC_Module_Interface
{
    private static bool $booted = false;
    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;
        add_action('admin_enqueue_scripts', array(self::class, 'enqueue_assets'));
        add_action('wp_ajax_adc_cookie_scan_server', array(self::class, 'ajax_scan_server'));
        add_action('wp_ajax_adc_cookie_scan_browser', array(self::class, 'ajax_scan_browser'));
        add_action('wp_ajax_adc_cookie_save', array(self::class, 'ajax_save'));
        add_action('wp_ajax_adc_cookie_status', array(self::class, 'ajax_status'));
    }
    public function slug(): string { return 'cookies'; }
    public function label(): string { return __('Scanner cookie-uri', 'adc-marketing'); }
    public function group(): string { return ''; }
    public function order(): int { return 15; }
    public function save(array $post): array { return array(); }
    public function shows_save_button(): bool { return false; }

    public static function enqueue_assets(string $hook_suffix): void
    {
        if ('toplevel_page_' . ADC_Admin_Shell::PAGE_SLUG !== $hook_suffix || !current_user_can('manage_options')) return;
        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $base = defined('ADC_MARKETING_PLUGIN_URL') ? rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/' : '';
        if ('' === $base) return;
        wp_enqueue_style('adc-admin-cookies', $base . 'css/admin-cookies.css', array(), $version);
        wp_enqueue_script('adc-admin-cookies', $base . 'js/admin-cookies.js', array(), $version, array('in_footer'=>true, 'strategy'=>'defer'));
    }

    public function render(): void
    {
        $check = ADC_Cookie_Policy::page_has_table();
        $urls = array(home_url('/'));
        if (function_exists('wc_get_page_permalink')) foreach (array('shop','cart','checkout') as $page) { $url = wc_get_page_permalink($page); if ($url) $urls[] = $url; }
        $categories = ADC_Settings::get('banner', 'category_labels', array());
        $i18n = array('necessary'=>__('Necesare','adc-marketing'),'preferences'=>__('Preferințe','adc-marketing'),'statistics'=>__('Statistici','adc-marketing'),'marketing'=>__('Marketing','adc-marketing'));
        $urls = array_values(array_unique(array_filter($urls, 'is_string')));
        ?>
        <div class="adc-cookie-scanner" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('adc_admin')); ?>" data-urls="<?php echo esc_attr((string) wp_json_encode($urls)); ?>">
            <?php if (($check['state'] ?? '') !== 'present') : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Informați vizitatorii: adăugați shortcode-ul [adc_cookie_table] în pagina Politicii de cookie-uri.', 'adc-marketing'); ?> <?php if (!empty($check['edit_url'])) : ?><a href="<?php echo esc_url($check['edit_url']); ?>"><?php esc_html_e('Editează pagina', 'adc-marketing'); ?></a><?php else : ?><?php esc_html_e('Setați pagina de cookie-uri în tabul Banner.', 'adc-marketing'); ?><?php endif; ?> <button type="button" class="button adc-cookie-copy" data-copy="[adc_cookie_table]"><?php esc_html_e('Copiază shortcode-ul', 'adc-marketing'); ?></button></p></div>
            <?php endif; ?>
            <p class="adc-cookie-toolbar"><button type="button" class="button button-primary" id="adc-cookie-scan"><?php esc_html_e('Scanează acum', 'adc-marketing'); ?></button> <button type="button" class="button" id="adc-cookie-save-all" disabled><?php esc_html_e('Salvează toate', 'adc-marketing'); ?></button> <button type="button" class="button" id="adc-cookie-accept-suggestions"><?php esc_html_e('Acceptă sugestiile', 'adc-marketing'); ?></button> <span id="adc-cookie-scan-status" aria-live="polite"></span></p>
            <p class="description"><?php esc_html_e('Cookie-urile setate de trackere apar în scanarea din browser numai dacă administratorul și-a acordat consimțământul pe site. Scanarea săptămânală din fundal vede doar cookie-urile setate de server.', 'adc-marketing'); ?></p>
            <div class="adc-cookie-filters" role="group" aria-label="<?php esc_attr_e('Filtrare cookie-uri', 'adc-marketing'); ?>">
                <?php foreach (array('all'=>__('Toate','adc-marketing'),'new'=>__('Necategorizate','adc-marketing'),'necessary'=>$i18n['necessary'],'preferences'=>$i18n['preferences'],'statistics'=>$i18n['statistics'],'marketing'=>$i18n['marketing'],'ignored'=>__('Ignorate','adc-marketing')) as $key=>$label) : ?><button type="button" class="button" data-filter="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button><?php endforeach; ?>
            </div>
            <?php $headings = array(__('Nume','adc-marketing'),__('Sursă','adc-marketing'),__('Ultima detectare','adc-marketing'),__('Categorie','adc-marketing'),__('Furnizor','adc-marketing'),__('Descriere','adc-marketing'),__('Durată de expirare','adc-marketing'),__('Acțiuni','adc-marketing')); ?>
            <table class="widefat striped adc-cookie-table"><thead><tr><?php foreach ($headings as $heading) : ?><th><?php echo esc_html($heading); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php $detected = ADC_Cookie_Scanner::detected(); usort($detected, static function (array $a, array $b): int { $an = ($a['status'] ?? '') === 'new'; $bn = ($b['status'] ?? '') === 'new'; if ($an !== $bn) return $an ? -1 : 1; $order = array_flip(ADC_Consent_Banner::CATEGORIES); return ($order[$a['inventory_row']['category'] ?? ''] ?? 99) <=> ($order[$b['inventory_row']['category'] ?? ''] ?? 99); }); foreach ($detected as $cookie) : $row = $cookie['inventory_row'] ?? array(); $suggestion = $cookie['suggestion'] ?? array(); $category = $row['category'] ?? ''; $provider = $row['provider'] ?? ($suggestion['provider'] ?? ''); $purpose = $row['purpose'] ?? ($suggestion['purpose'] ?? ''); $duration = $row['duration'] ?? ($suggestion['duration'] ?? ''); $status = (string) ($cookie['status'] ?? 'new'); ?>
                <tr data-name="<?php echo esc_attr((string) $cookie['name']); ?>" data-status="<?php echo esc_attr($status); ?>" data-category="<?php echo esc_attr((string) $category); ?>" data-suggestion-category="<?php echo esc_attr((string) ($suggestion['category'] ?? '')); ?>" data-suggestion-provider="<?php echo esc_attr((string) ($suggestion['provider'] ?? '')); ?>" data-suggestion-purpose="<?php echo esc_attr((string) ($suggestion['purpose'] ?? '')); ?>" data-suggestion-duration="<?php echo esc_attr((string) ($suggestion['duration'] ?? '')); ?>" data-suggestion-origin="<?php echo esc_attr((string) ($suggestion['origin'] ?? 'curated')); ?>"><th scope="row"><code><?php echo esc_html((string) $cookie['name']); ?></code> <?php if (!empty($suggestion)) : ?><span class="adc-cookie-badge adc-cookie-suggestion"><?php esc_html_e('sugestie', 'adc-marketing'); ?></span><?php endif; ?> <span class="adc-cookie-badge adc-cookie-state"><?php echo esc_html('new' === $status ? __('Necategorizat','adc-marketing') : ('ignored' === $status ? __('Ignorat','adc-marketing') : __('Clasificat','adc-marketing'))); ?></span></th><td data-label="<?php echo esc_attr($headings[1]); ?>"><?php echo esc_html((string) ($cookie['source'] ?? '')); ?></td><td data-label="<?php echo esc_attr($headings[2]); ?>"><?php echo esc_html(wp_date('Y-m-d H:i', (int) ($cookie['last_seen'] ?? 0))); ?></td><td data-label="<?php echo esc_attr($headings[3]); ?>"><select class="adc-cookie-category"><option value=""><?php esc_html_e('— alegeți —','adc-marketing'); ?></option><?php foreach (ADC_Consent_Banner::CATEGORIES as $cat) : ?><option value="<?php echo esc_attr($cat); ?>" <?php selected($category, $cat); ?>><?php echo esc_html((string) ($categories[$cat] ?? $i18n[$cat])); ?></option><?php endforeach; ?></select></td><td data-label="<?php echo esc_attr($headings[4]); ?>"><input class="adc-cookie-provider" type="text" value="<?php echo esc_attr((string) $provider); ?>" /></td><td data-label="<?php echo esc_attr($headings[5]); ?>"><input class="adc-cookie-purpose" type="text" value="<?php echo esc_attr((string) $purpose); ?>" /></td><td data-label="<?php echo esc_attr($headings[6]); ?>"><input class="adc-cookie-duration" type="text" value="<?php echo esc_attr((string) $duration); ?>" /></td><td data-label="<?php echo esc_attr($headings[7]); ?>"><button type="button" class="button button-small adc-cookie-save" data-name="<?php echo esc_attr((string) $cookie['name']); ?>" <?php disabled('' === $category); ?>><?php esc_html_e('Salvează','adc-marketing'); ?></button> <button type="button" class="button button-small adc-cookie-status" data-name="<?php echo esc_attr((string) $cookie['name']); ?>" data-status="<?php echo esc_attr('ignored' === $status ? 'new' : 'ignored'); ?>"><?php echo esc_html('ignored' === $status ? __('Restabilește','adc-marketing') : __('Ignoră','adc-marketing')); ?></button></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }
    private static function verify_request(): void
    {
        check_ajax_referer('adc_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>__('Nu aveți permisiunea necesară.','adc-marketing')), 403);
    }
    public static function ajax_scan_server(): void
    {
        self::verify_request(); $names = ADC_Cookie_Scanner::scan_headers(); $new = ADC_Cookie_Scanner::record($names, 'headers'); wp_send_json_success(array('new'=>$new,'count'=>count($names)));
    }
    public static function ajax_scan_browser(): void
    {
        self::verify_request(); $raw = isset($_POST['names']) ? wp_unslash($_POST['names']) : array();
        if (is_string($raw)) { $decoded = json_decode($raw, true); $raw = is_array($decoded) ? $decoded : array(); }
        $names = is_array($raw) ? array_map(static fn($v): string => sanitize_text_field((string) $v), $raw) : array(); $new = ADC_Cookie_Scanner::record($names, 'browser'); wp_send_json_success(array('new'=>$new));
    }
    public static function ajax_save(): void
    {
        self::verify_request();
        $rows = isset($_POST['rows']) ? wp_unslash($_POST['rows']) : null;
        if (is_string($rows)) { $decoded = json_decode($rows, true); $rows = is_array($decoded) ? $decoded : array(); }
        if (!is_array($rows)) {
            $row = isset($_POST['row']) ? wp_unslash($_POST['row']) : array();
            if (is_string($row)) { $decoded = json_decode($row, true); $row = is_array($decoded) ? $decoded : array(); }
            $rows = array(is_array($row) ? $row : array());
        }
        if (count($rows) > 200) wp_send_json_error(array('message' => __('Prea multe rânduri (maximum 200 per cerere).', 'adc-marketing')), 400);
        $result = ADC_Cookie_Scanner::save_to_inventory($rows);
        if ($result['saved'] < 1) wp_send_json_error(array('message'=>implode(' ', $result['errors'])), 400);
        wp_send_json_success($result);
    }
    public static function ajax_status(): void
    {
        self::verify_request(); $name = isset($_POST['name']) ? sanitize_text_field((string) wp_unslash($_POST['name'])) : ''; $status = isset($_POST['status']) ? sanitize_key((string) wp_unslash($_POST['status'])) : ''; if (!ADC_Cookie_Scanner::set_status($name, $status)) wp_send_json_error(array('message'=>__('Starea cookie-ului nu a putut fi actualizată.','adc-marketing')), 400); wp_send_json_success(array('status'=>$status));
    }
}
