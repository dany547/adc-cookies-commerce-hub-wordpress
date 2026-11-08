<?php
/**
 * Admin tab "Catalog ▸ Feeduri" — stage 0 (WooCommerce gate only).
 *
 * The Catalog module needs WooCommerce. Until the feed engine exists, this tab
 * shows one of three states inside a card:
 * 1. WooCommerce is not installed → explain, offer install (if allowed) and a
 *    details link to the WordPress.org plugin directory.
 * 2. WooCommerce is installed but inactive → offer activation (if allowed).
 * 3. WooCommerce is active → empty state; feed creation arrives in later stages.
 *
 * Detection is deliberately independent of ADC_Feed_Module (not built yet).
 * The tab has no form fields and saves nothing (the shell skips the save bar).
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Catalog ▸ Feeduri admin tab (WooCommerce gate + empty state).
 */
final class ADC_Admin_Tab_Feeds implements ADC_Admin_Tab_Interface
{
    /** Plugin file of WooCommerce, as WordPress keys it. */
    private const WC_PLUGIN_FILE = 'woocommerce/woocommerce.php';

    /** Plugin slug on WordPress.org (install and details links). */
    private const WC_SLUG = 'woocommerce';

    /**
     * Tab slug.
     *
     * @return string
     */
    public function slug(): string
    {
        return 'feeds';
    }

    /**
     * Tab label (RO).
     *
     * @return string
     */
    public function label(): string
    {
        return __('Catalog', 'adc-marketing');
    }

    /**
     * Nav group key (see ADC_Admin_Shell::NAV_GROUPS; the shell drives nav by slug).
     *
     * @return string
     */
    public function group(): string
    {
        return 'catalog';
    }

    /**
     * Sort order: after the measurement tabs (matching = 70).
     *
     * @return int
     */
    public function order(): int
    {
        return 75;
    }

    /**
     * Read-only stage: the shell hides the sticky save bar.
     *
     * @return bool
     */
    public function shows_save_button(): bool
    {
        return false;
    }

    /**
     * Nothing to save yet.
     *
     * @param array $post wp_unslash($_POST) (unused).
     * @return array<int, array{type: string, message: string}>
     */
    public function save(array $post): array
    {
        unset($post);
        return array();
    }

    /**
     * Render the state card (no <form>, no nonce: the shell wraps the tab).
     */
    public function render(): void
    {
        $state = self::woocommerce_state();

        echo '<div class="adc-box adc-box--light adc-feeds">';
        self::render_critical_requirements_notice(self::requirements());

        if ('missing' === $state) {
            $this->render_missing();
        } elseif ('inactive' === $state) {
            $this->render_inactive();
        } else {
            $this->render_active();
        }

        echo '</div>';
    }

    /**
     * State 1: WooCommerce is not installed.
     */
    private function render_missing(): void
    {
        $details_url = self::details_url();

        echo '<h3 class="adc-feeds__title">' . esc_html__('Catalogul necesită WooCommerce', 'adc-marketing') . '</h3>';
        echo '<p class="adc-feeds__text">' . esc_html__('Modulul Catalog citește produsele din WooCommerce. Instalează pluginul WooCommerce ca să creezi feeduri de produse.', 'adc-marketing') . '</p>';

        echo '<div class="adc-feeds__actions">';
        if (current_user_can('install_plugins')) {
            $install_url = wp_nonce_url(
                self_admin_url('update.php?action=install-plugin&plugin=' . self::WC_SLUG),
                'install-plugin_' . self::WC_SLUG
            );
            echo '<a class="button button-primary" href="' . esc_url($install_url) . '">'
                . esc_html__('Instalează WooCommerce', 'adc-marketing')
                . '</a>';
        }
        echo '<a class="adc-feeds__link" href="' . esc_url($details_url) . '">'
            . esc_html__('Detalii', 'adc-marketing')
            . '</a>';
        echo '</div>';

        if (!current_user_can('install_plugins')) {
            echo '<p class="adc-feeds__note">' . esc_html__('Doar un administrator cu drept de instalare poate instala WooCommerce.', 'adc-marketing') . '</p>';
        }
    }

    /**
     * State 2: WooCommerce is installed but inactive.
     */
    private function render_inactive(): void
    {
        echo '<h3 class="adc-feeds__title">' . esc_html__('WooCommerce este instalat, dar inactiv', 'adc-marketing') . '</h3>';
        echo '<p class="adc-feeds__text">' . esc_html__('Activează WooCommerce ca să poți crea feeduri de produse.', 'adc-marketing') . '</p>';

        echo '<div class="adc-feeds__actions">';
        if (current_user_can('activate_plugins')) {
            $activate_url = wp_nonce_url(
                self_admin_url('plugins.php?action=activate&plugin=' . self::WC_PLUGIN_FILE),
                'activate-plugin_' . self::WC_PLUGIN_FILE
            );
            echo '<a class="button button-primary" href="' . esc_url($activate_url) . '">'
                . esc_html__('Activează WooCommerce', 'adc-marketing')
                . '</a>';
        }
        echo '</div>';

        if (!current_user_can('activate_plugins')) {
            echo '<p class="adc-feeds__note">' . esc_html__('Cere unui administrator cu drept de activare să activeze WooCommerce.', 'adc-marketing') . '</p>';
        }
    }

    /** Feed wizard text supplied to the deferred UI through WordPress gettext. */
    private static function frontend_strings(): array
    {
        return array(
            "feed_ui_001" => __('Recondiționat', 'adc-marketing'),
            "feed_ui_002" => __('Destinație', 'adc-marketing'),
            "feed_ui_003" => __('Setări destinație', 'adc-marketing'),
            "feed_ui_004" => __('Verificare și publicare', 'adc-marketing'),
            "feed_ui_005" => __('Reușită', 'adc-marketing'),
            "feed_ui_006" => __('Eșuată', 'adc-marketing'),
            "feed_ui_007" => __('În curs', 'adc-marketing'),
            "feed_ui_008" => __('Finalizată', 'adc-marketing'),
            "feed_ui_009" => __('Nu s-a putut contacta serverul. Verifică conexiunea și încearcă din nou.', 'adc-marketing'),
            "feed_ui_010" => __('A apărut o eroare. Încearcă din nou.', 'adc-marketing'),
            "feed_ui_011" => __('Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.', 'adc-marketing'),
            "feed_ui_012" => __('Câmp nativ', 'adc-marketing'),
            "feed_ui_013" => __('Câmp personalizat (meta)', 'adc-marketing'),
            "feed_ui_014" => __('Valoare fixă', 'adc-marketing'),
            "feed_ui_015" => __('Șablon', 'adc-marketing'),
            "feed_ui_016" => __('Taie spațiile', 'adc-marketing'),
            "feed_ui_017" => __('Elimină HTML', 'adc-marketing'),
            "feed_ui_018" => __('Trunchiază', 'adc-marketing'),
            "feed_ui_019" => __('Adaugă prefix', 'adc-marketing'),
            "feed_ui_020" => __('Adaugă sufix', 'adc-marketing'),
            "feed_ui_021" => __('Valoare implicită dacă e gol', 'adc-marketing'),
            "feed_ui_022" => __('Înlocuiește', 'adc-marketing'),
            "feed_ui_023" => __('Format preț', 'adc-marketing'),
            "feed_ui_024" => __('” nu este validă.', 'adc-marketing'),
            "feed_ui_025" => __('Alege o destinație disponibilă.', 'adc-marketing'),
            "feed_ui_026" => __('Alege un format disponibil pentru destinația aleasă.', 'adc-marketing'),
            "feed_ui_027" => __('Numele feedului trebuie să aibă între 1 și 120 de caractere.', 'adc-marketing'),
            "feed_ui_028" => __('Alege ce produse includi în feed.', 'adc-marketing'),
            "feed_ui_029" => __('Adaugă cel puțin o categorie (slug-uri separate prin virgulă).', 'adc-marketing'),
            "feed_ui_030" => __('Poți adăuga cel mult 50 de categorii.', 'adc-marketing'),
            "feed_ui_031" => __('Categoriile trebuie scrise ca slug-uri: litere mici, cifre și cratime.', 'adc-marketing'),
            "feed_ui_032" => __('Alege condiția implicită a produselor.', 'adc-marketing'),
            "feed_ui_033" => __('Codul țării de livrare trebuie să aibă două litere (ex. RO).', 'adc-marketing'),
            "feed_ui_034" => __('Prețul de livrare trebuie scris cu punct, ex. 15.00.', 'adc-marketing'),
            "feed_ui_035" => __('Serviciul de livrare trebuie să aibă între 1 și 100 de caractere.', 'adc-marketing'),
            "feed_ui_036" => __('Marca implicită trebuie să aibă între 1 și 100 de caractere.', 'adc-marketing'),
            "feed_ui_037" => __('Poți adăuga cel mult 5 etichete interne.', 'adc-marketing'),
            "feed_ui_038" => __('Fiecare etichetă internă are cel mult 110 caractere.', 'adc-marketing'),
            "feed_ui_039" => __('Categoria implicită poate avea cel mult 750 de caractere.', 'adc-marketing'),
            "feed_ui_040" => __('Fiecare categorie mapată poate avea cel mult 750 de caractere.', 'adc-marketing'),
            "feed_ui_041" => __('Necunoscută', 'adc-marketing'),
            "feed_ui_042" => __('și încă ', 'adc-marketing'),
            "feed_ui_043" => __('Nicio rulare încă.', 'adc-marketing'),
            "feed_ui_044" => __(' Adresa publică: ', 'adc-marketing'),
            "feed_ui_045" => __('Nu ai creat încă niciun feed.', 'adc-marketing'),
            "feed_ui_046" => __('Apasă „Feed nou” ca să creezi primul feed de produse.', 'adc-marketing'),
            "feed_ui_047" => __('Dezactivează', 'adc-marketing'),
            "feed_ui_048" => __('Activează', 'adc-marketing'),
            "feed_ui_049" => __('Copiază', 'adc-marketing'),
            "feed_ui_050" => __('Copiază adresa feedului %s', 'adc-marketing'),
            "feed_ui_051" => __('Adresa feedului a fost copiată.', 'adc-marketing'),
            "feed_ui_052" => __('Adresa nu a putut fi copiată. Selecteaz-o manual.', 'adc-marketing'),
            "feed_ui_053" => __('Editează', 'adc-marketing'),
            "feed_ui_054" => __('Regenerează', 'adc-marketing'),
            "feed_ui_055" => __('Regenerarea a fost programată. Feedul se actualizează în câteva minute.', 'adc-marketing'),
            "feed_ui_056" => __('Starea feedului a fost actualizată.', 'adc-marketing'),
            "feed_ui_057" => __('Șterge', 'adc-marketing'),
            "feed_ui_058" => __('Ștergi feedul „', 'adc-marketing'),
            "feed_ui_059" => __('”? Adresa publică nu va mai funcționa.', 'adc-marketing'),
            "feed_ui_060" => __('Feedul a fost șters.', 'adc-marketing'),
            "feed_ui_061" => __('Editează feedul', 'adc-marketing'),
            "feed_ui_062" => __('Înapoi', 'adc-marketing'),
            "feed_ui_063" => __('Renunță', 'adc-marketing'),
            "feed_ui_064" => __('Continuă', 'adc-marketing'),
            "feed_ui_065" => __('Se salvează…', 'adc-marketing'),
            "feed_ui_066" => __('Salvează modificările', 'adc-marketing'),
            "feed_ui_067" => __('Publică feedul', 'adc-marketing'),
            "feed_ui_068" => __('Format fișier', 'adc-marketing'),
            "feed_ui_069" => __('Categorii (slug-uri separate prin virgulă)', 'adc-marketing'),
            "feed_ui_070" => __('Include doar produsele în stoc', 'adc-marketing'),
            "feed_ui_071" => __('Țara de livrare', 'adc-marketing'),
            "feed_ui_072" => __('Preț livrare', 'adc-marketing'),
            "feed_ui_073" => __('Folosește punctul ca separator, ex. 15.00.', 'adc-marketing'),
            "feed_ui_074" => __('Condiție implicită', 'adc-marketing'),
            "feed_ui_075" => __('Marcă implicită', 'adc-marketing'),
            "feed_ui_076" => __('Marca folosită când produsul nu are una.', 'adc-marketing'),
            "feed_ui_077" => __('Separate prin virgulă; cel mult 5 etichete, fiecare până la 110 caractere.', 'adc-marketing'),
            "feed_ui_078" => __('Folosește maparea categoriilor din Setări canal.', 'adc-marketing'),
            "feed_ui_079" => __('Șterge transformarea %s', 'adc-marketing'),
            "feed_ui_080" => __('Operație de transformare', 'adc-marketing'),
            "feed_ui_081" => __('Adaugă transformare', 'adc-marketing'),
            "feed_ui_082" => __('Tip de sursă', 'adc-marketing'),
            "feed_ui_083" => __('Sursă', 'adc-marketing'),
            "feed_ui_084" => __('Transformări', 'adc-marketing'),
            "feed_ui_085" => __('Toți parametrii disponibili sunt deja în tabel.', 'adc-marketing'),
            "feed_ui_086" => __('Adaugă parametru', 'adc-marketing'),
            "feed_ui_087" => __('Nu există produse de previzualizat.', 'adc-marketing'),
            "feed_ui_088" => __('Se încarcă parametrii…', 'adc-marketing'),
            "feed_ui_089" => __('Fiecare rând spune ce câmp al feedului completezi și de unde citește valoarea. Parametrii obligatorii nu pot fi șterși.', 'adc-marketing'),
            "feed_ui_090" => __('Se previzualizează…', 'adc-marketing'),
            "feed_ui_091" => __('Previzualizează', 'adc-marketing'),
            "feed_ui_092" => __('Setări canal', 'adc-marketing'),
            "feed_ui_093" => __('Categorie implicită', 'adc-marketing'),
            "feed_ui_094" => __('Folosită pentru produsele fără categorie mapată mai jos.', 'adc-marketing'),
            "feed_ui_095" => __('Nu există categorii de produse în WooCommerce; folosește categoria implicită.', 'adc-marketing'),
            "feed_ui_096" => __(' · doar produse în stoc', 'adc-marketing'),
            "feed_ui_097" => __(' · validare strictă', 'adc-marketing'),
            "feed_ui_098" => __('Feedul se generează în fundal. Adresa publică funcționează după prima generare reușită.', 'adc-marketing'),
            "feed_ui_100" => __("Format personalizat", 'adc-marketing'),
            "feed_ui_101" => __("Nou", 'adc-marketing'),
            "feed_ui_102" => __("Second-hand", 'adc-marketing'),
            "feed_ui_103" => __("Produse", 'adc-marketing'),
            "feed_ui_104" => __("Activ", 'adc-marketing'),
            "feed_ui_105" => __("Dezactivat", 'adc-marketing'),
            "feed_ui_106" => __('Diagnostic necunoscut (%s)', 'adc-marketing'),
            "feed_ui_107" => __("Erori", 'adc-marketing'),
            "feed_ui_108" => __("Avertismente", 'adc-marketing'),
            "feed_ui_109" => __(" caz", 'adc-marketing'),
            "feed_ui_110" => __(" cazuri", 'adc-marketing'),
            "feed_ui_111" => __("Ultima rulare: ", 'adc-marketing'),
            "feed_ui_112" => __(" din ", 'adc-marketing'),
            "feed_ui_113" => __(" produse exportate", 'adc-marketing'),
            "feed_ui_114" => __("Feedul a fost actualizat.", 'adc-marketing'),
            "feed_ui_115" => __("Feedul a fost creat.", 'adc-marketing'),
            "feed_ui_116" => __("Catalog", 'adc-marketing'),
            "feed_ui_117" => __("Feed nou", 'adc-marketing'),
            "feed_ui_118" => __(" · Actualizat: ", 'adc-marketing'),
            "feed_ui_119" => __("Transformarea pentru „", 'adc-marketing'),
            "feed_ui_120" => __("Parametrul „", 'adc-marketing'),
            "feed_ui_121" => __("” este obligatoriu.", 'adc-marketing'),
            "feed_ui_122" => __("Tipul sursei pentru „", 'adc-marketing'),
            "feed_ui_123" => __("” nu este valid.", 'adc-marketing'),
            "feed_ui_124" => __("Alege sursa pentru „", 'adc-marketing'),
            "feed_ui_125" => __("Produse incluse", 'adc-marketing'),
            "feed_ui_126" => __("Nume feed", 'adc-marketing'),
            "feed_ui_127" => __("Toate produsele publicate", 'adc-marketing'),
            "feed_ui_128" => __("Doar anumite categorii", 'adc-marketing'),
            "feed_ui_129" => __("incaltaminte, accesorii", 'adc-marketing'),
            "feed_ui_130" => __("Omite produsele invalide", 'adc-marketing'),
            "feed_ui_131" => __("Serviciu de livrare", 'adc-marketing'),
            "feed_ui_132" => __("Etichete interne", 'adc-marketing'),
            "feed_ui_133" => __("vara, promo", 'adc-marketing'),
            "feed_ui_134" => __("Export CSV compatibil cu Excel", 'adc-marketing'),
            "feed_ui_135" => __("Valoarea sursei", 'adc-marketing'),
            "feed_ui_136" => __("— alege —", 'adc-marketing'),
            "feed_ui_137" => __("Argument", 'adc-marketing'),
            "feed_ui_138" => __("Obligatoriu", 'adc-marketing'),
            "feed_ui_139" => __("Parametru", 'adc-marketing'),
            "feed_ui_140" => __("Activ", 'adc-marketing'),
            "feed_ui_141" => __("Parametru nou", 'adc-marketing'),
            "feed_ui_142" => __("Parametri feed", 'adc-marketing'),
            "feed_ui_143" => __("Nume", 'adc-marketing'),
            "feed_ui_144" => __("Format", 'adc-marketing'),
            "feed_ui_145" => __("Livrare", 'adc-marketing'),
            "feed_ui_146" => __("CSV compatibil Excel", 'adc-marketing'),
            "feed_ui_147" => __("Da", 'adc-marketing'),
            "feed_ui_148" => __("Nu", 'adc-marketing'),
            "feed_ui_149" => __("Parametri", 'adc-marketing'),
            "feed_ui_150" => __(" activi", 'adc-marketing'),
            "feed_ui_151" => __("Categorii mapate", 'adc-marketing'),
            "feed_ui_152" => __("Categorii: ", 'adc-marketing'),
            "feed_ui_153" => __(" · omit produsele invalide", 'adc-marketing'),
            "feed_ui_154" => __('Pentru categoria „%s”', 'adc-marketing'),
            "feed_ui_155" => __("Motorul de feeduri nu este disponibil.", 'adc-marketing'),
            "feed_ui_156" => __('Atribut WooCommerce', 'adc-marketing'),
            "feed_ui_157" => __('Taxonomie', 'adc-marketing'),
            "feed_ui_158" => __('Maparea categoriilor', 'adc-marketing'),
            "feed_ui_159" => __('Majuscule', 'adc-marketing'),
            "feed_ui_160" => __('Minuscule', 'adc-marketing'),
            "feed_ui_161" => __('Mapare valori', 'adc-marketing'),
            "feed_ui_162" => __('Categorii', 'adc-marketing'),
            "feed_ui_163" => __('Ștergi feedul „%s”? Adresa publică nu va mai funcționa.', 'adc-marketing'),
            "feed_ui_099" => __('în curând', 'adc-marketing')
        );
    }

    /**
     * State 3: WooCommerce is active; render the feed management UI.
     */
    private function render_active(): void
    {
        echo '<p class="adc-feeds__text">' . esc_html__('Feedurile trimit catalogul de produse către Google, Meta, TikTok, OLX sau către un format personalizat. Sunt disponibile feedurile pentru Google Shopping, Meta și TikTok; OLX și formatul personalizat urmează.', 'adc-marketing') . '</p>';
        $strings = wp_json_encode(self::frontend_strings());
        if (is_string($strings) && '' !== $strings && function_exists('wp_add_inline_script')) {
            wp_add_inline_script('adc-admin-feeds', 'window.ADC_FEEDS_STRINGS = ' . $strings . ';', 'before');
        }
        // Mount point for assets/js/admin-feeds.js: the list and the wizard are
        // built client-side through REST. Config travels as data attributes only.
        echo '<div class="adc-feeds__app" data-adc-feeds'
            . ' data-rest-url="' . esc_url(rest_url(ADC_Feed_Rest::NAMESPACE . '/feeds')) . '"'
            . ' data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '"'
            . ' data-diagnostic-labels="' . esc_attr((string) wp_json_encode(self::diagnostic_labels())) . '"'
            . ' data-channels="' . esc_attr((string) wp_json_encode(self::channel_rows())) . '"'
            . ' data-categories="' . esc_attr((string) wp_json_encode(self::category_rows())) . '"'
            . ' data-sources="' . esc_attr((string) wp_json_encode(self::source_lists())) . '"'
            . ' aria-live="polite">';
        echo '<p class="adc-feeds__status">' . esc_html__('Se încarcă feedurile…', 'adc-marketing') . '</p>';
        echo '<noscript><p class="adc-feeds__note">' . esc_html__('Activează JavaScript pentru a gestiona feedurile.', 'adc-marketing') . '</p></noscript>';
        echo '</div>';
    }

    /**
     * WooCommerce product categories for the category map: id and name, at most 200. Empty without WooCommerce.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function category_rows(): array
    {
        if (!function_exists('get_terms')) {
            return array();
        }

        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200));
        if (!is_array($terms)) {
            return array();
        }

        $rows = array();
        foreach ($terms as $term) {
            if (is_object($term) && isset($term->term_id, $term->name)) {
                $rows[] = array('id' => (int) $term->term_id, 'name' => (string) $term->name);
            }
        }

        return $rows;
    }

    /**
     * Sources offered by the parameter table: the native product fields (ADC_Feed_Rest::NATIVE_SOURCES),
     * the WooCommerce attributes (pa_*) and the product taxonomies. The last two are empty outside WooCommerce.
     *
     * @return array{native: array<int, array{value: string, label: string}>, attribute: array<int, array{value: string, label: string}>, taxonomy: array<int, array{value: string, label: string}>}
     */
    public static function source_lists(): array
    {
        $native_labels = array(
            'id'                => __('ID produs', 'adc-marketing'),
            'sku'               => __('SKU', 'adc-marketing'),
            'name'              => __('Nume', 'adc-marketing'),
            'short_description' => __('Descriere scurtă', 'adc-marketing'),
            'description'       => __('Descriere', 'adc-marketing'),
            'permalink'         => __('Adresă produs', 'adc-marketing'),
            'image_url'         => __('Imagine principală', 'adc-marketing'),
            'gallery_urls'      => __('Galerie', 'adc-marketing'),
            'regular_price'     => __('Preț normal', 'adc-marketing'),
            'sale_price'        => __('Preț redus', 'adc-marketing'),
            'stock_status'      => __('Stare stoc', 'adc-marketing'),
            'stock_quantity'    => __('Cantitate în stoc', 'adc-marketing'),
            'weight'            => __('Greutate', 'adc-marketing'),
            'categories'        => __('Categorii', 'adc-marketing'),
        );
        $native = array();
        foreach (ADC_Feed_Rest::NATIVE_SOURCES as $key) {
            $native[] = array('value' => $key, 'label' => $native_labels[$key] ?? $key);
        }

        $attributes = array();
        if (function_exists('wc_get_attribute_taxonomies')) {
            foreach ((array) wc_get_attribute_taxonomies() as $attribute) {
                if (is_object($attribute) && isset($attribute->attribute_name)) {
                    $attributes[] = array(
                        'value' => 'pa_' . (string) $attribute->attribute_name,
                        'label' => (string) ($attribute->attribute_label ?? $attribute->attribute_name),
                    );
                }
            }
        }

        $taxonomies = array();
        if (function_exists('get_object_taxonomies')) {
            foreach ((array) get_object_taxonomies('product', 'objects') as $name => $taxonomy) {
                if (is_string($name) && !str_starts_with($name, 'pa_') && is_object($taxonomy)) {
                    $taxonomies[] = array('value' => $name, 'label' => (string) ($taxonomy->label ?? $name));
                }
            }
        }

        return array(
            'native'    => $native,
            'attribute' => array_slice($attributes, 0, 100),
            'taxonomy'  => array_slice($taxonomies, 0, 100),
        );
    }

    /**
     * Every destination of the product, in wizard order, with its label, availability and
     * the formats the registry allows. Unavailable destinations stay listed as "în curând".
     *
     * @return array<int, array{id: string, label: string, available: bool, formats: array<int, string>}>
     */
    public static function channel_rows(): array
    {
        $defaults = array(
            'google' => 'Google Shopping',
            'meta'   => 'Meta',
            'tiktok' => 'TikTok',
            'olx'    => 'OLX',
            'custom' => __('Format personalizat', 'adc-marketing'),
        );
        $available = ADC_Feed_Rest::available_channels();

        $rows = array();
        foreach ($defaults as $id => $label) {
            $info   = $available[$id] ?? null;
            $rows[] = array(
                'id'        => $id,
                'label'     => null !== $info && '' !== $info['label'] ? $info['label'] : $label,
                'available' => null !== $info,
                'formats'   => null !== $info ? $info['formats'] : array(),
            );
        }

        return $rows;
    }

    /**
     * Romanian text for every diagnostic code the feed engine can report
     * (generator, Google profile and Google validator). Passed to the JS as
     * data-diagnostic-labels; codes missing here fall back to "Diagnostic necunoscut (cod)".
     *
     * @return array<string, string> code => label
     */
    public static function diagnostic_labels(): array
    {
        return array(
            // Generator.
            'no_valid_products'                => __('Nu există produse valide pentru publicarea feedului.', 'adc-marketing'),
            'invalid_products_excluded'         => __('%d produse omise: nu respectă cerințele destinației.', 'adc-marketing'),
            'invalid_feed_id'                  => __('ID-ul feedului nu este valid.', 'adc-marketing'),
            'feed_not_found'                   => __('Feedul nu a fost găsit.', 'adc-marketing'),
            'feed_disabled'                    => __('Feedul este dezactivat.', 'adc-marketing'),
            'unsupported_feed_profile'         => __('Profilul feedului nu este acceptat.', 'adc-marketing'),
            'feed_busy'                        => __('Feedul este deja în curs de generare.', 'adc-marketing'),
            'feed_busy_retry_failed'           => __('Nu s-a putut programa o nouă încercare pentru feedul ocupat.', 'adc-marketing'),
            'feed_busy_retry_exhausted'        => __('Numărul de încercări pentru feedul ocupat a fost epuizat.', 'adc-marketing'),
            'feed_dirty_retry_failed'          => __('Nu s-a putut programa regenerarea după o modificare.', 'adc-marketing'),
            'feed_dirty_retry_exhausted'       => __('Numărul de încercări pentru regenerare a fost epuizat.', 'adc-marketing'),
            'feed_id_index_failed'             => __('Indexul produselor din feed nu a putut fi construit.', 'adc-marketing'),
            'feed_generation_failed'           => __('Generarea feedului a eșuat dintr-un motiv neidentificat.', 'adc-marketing'),
            'tracking_id_incompatible'         => __('ID-ul de urmărire nu este compatibil cu formatul feedului.', 'adc-marketing'),
            'duplicate_feed_item_id'           => __('Același ID de produs apare de mai multe ori.', 'adc-marketing'),
            'google_validator_api_missing'     => __('Validatorul Google nu este disponibil pe acest site.', 'adc-marketing'),
            'google_validation_failed'         => __('Validarea Google nu a putut fi finalizată.', 'adc-marketing'),
            // Google profile.
            'shipping_required_for_ro'         => __('Pentru România este necesară configurarea livrării.', 'adc-marketing'),
            'shipping_country_required'        => __('Lipsește țara de livrare.', 'adc-marketing'),
            'shipping_country_invalid'         => __('Țara de livrare nu este validă.', 'adc-marketing'),
            'shipping_price_invalid'           => __('Prețul de livrare nu este valid.', 'adc-marketing'),
            'certification_required_but_missing' => __('Lipsește certificarea obligatorie a produsului.', 'adc-marketing'),
            'certification_incomplete'         => __('Certificarea produsului este incompletă.', 'adc-marketing'),
            'unit_pricing_required_but_missing' => __('Lipsește prețul pe unitate, obligatoriu pentru această categorie.', 'adc-marketing'),
            'unit_pricing_incomplete'          => __('Prețul pe unitate este incomplet.', 'adc-marketing'),
            // Google validator (per product).
            'id_missing'                       => __('Lipsește ID-ul produsului.', 'adc-marketing'),
            'id_too_long'                      => __('ID-ul produsului este prea lung.', 'adc-marketing'),
            'item_group_id_too_long'           => __('ID-ul grupului de variante este prea lung.', 'adc-marketing'),
            'title_missing'                    => __('Lipsește titlul produsului.', 'adc-marketing'),
            'title_too_long'                   => __('Titlul produsului este prea lung.', 'adc-marketing'),
            'title_not_plain_text'             => __('Titlul conține HTML sau text formatat.', 'adc-marketing'),
            'description_missing'              => __('Lipsește descrierea produsului.', 'adc-marketing'),
            'description_too_long'             => __('Descrierea produsului este prea lungă.', 'adc-marketing'),
            'description_not_plain_text'       => __('Descrierea conține HTML sau text formatat.', 'adc-marketing'),
            'link_missing'                     => __('Lipsește adresa produsului.', 'adc-marketing'),
            'link_invalid'                     => __('Adresa produsului nu este validă.', 'adc-marketing'),
            'link_https_recommended'           => __('Se recomandă o adresă HTTPS pentru produs.', 'adc-marketing'),
            'image_link_missing'               => __('Lipsește imaginea principală.', 'adc-marketing'),
            'image_link_invalid'               => __('Adresa imaginii principale nu este validă.', 'adc-marketing'),
            'image_too_small'                  => __('Imaginea principală este prea mică.', 'adc-marketing'),
            'image_below_upcoming_minimum'     => __('Imaginea este sub dimensiunea minimă care va fi cerută.', 'adc-marketing'),
            'additional_images_invalid'        => __('Imaginile suplimentare nu sunt valide.', 'adc-marketing'),
            'additional_images_too_many'       => __('Sunt prea multe imagini suplimentare.', 'adc-marketing'),
            'additional_image_invalid'         => __('O imagine suplimentară nu este validă.', 'adc-marketing'),
            'additional_image_url_too_long'    => __('Adresa unei imagini suplimentare este prea lungă.', 'adc-marketing'),
            'price_missing'                    => __('Lipsește prețul produsului.', 'adc-marketing'),
            'price_invalid'                    => __('Prețul produsului nu este valid.', 'adc-marketing'),
            'sale_price_invalid'               => __('Prețul promoțional nu este valid.', 'adc-marketing'),
            'sale_price_above_price'           => __('Prețul promoțional este mai mare decât prețul normal.', 'adc-marketing'),
            'sale_price_equals_price'          => __('Prețul promoțional este egal cu prețul normal.', 'adc-marketing'),
            'currency_invalid'                 => __('Moneda nu este validă.', 'adc-marketing'),
            'availability_missing'             => __('Lipsește disponibilitatea produsului.', 'adc-marketing'),
            'availability_invalid'             => __('Disponibilitatea produsului nu este validă.', 'adc-marketing'),
            'availability_date_required'       => __('Pentru precomandă este necesară data de disponibilitate.', 'adc-marketing'),
            'availability_date_invalid'        => __('Data de disponibilitate nu este validă.', 'adc-marketing'),
            'condition_invalid'                => __('Condiția produsului nu este validă.', 'adc-marketing'),
            'identifier_exists_invalid'        => __('Valoarea identificatorilor nu este validă.', 'adc-marketing'),
            'identifier_missing'               => __('Lipsește GTIN-ul sau identificatorul produsului.', 'adc-marketing'),
            'brand_missing'                    => __('Lipsește marca produsului.', 'adc-marketing'),
            'brand_too_long'                   => __('Marca produsului este prea lungă.', 'adc-marketing'),
            'mpn_too_long'                     => __('Codul MPN este prea lung.', 'adc-marketing'),
            'mpn_not_alphanumeric'             => __('Codul MPN conține caractere care nu sunt alfanumerice.', 'adc-marketing'),
            'gtin_too_long'                    => __('GTIN-ul este prea lung.', 'adc-marketing'),
            'gtin_not_numeric'                 => __('GTIN-ul trebuie să conțină doar cifre.', 'adc-marketing'),
            'gtin_invalid_length'              => __('GTIN-ul are o lungime nevalidă.', 'adc-marketing'),
            'gtin_invalid_checksum'            => __('GTIN-ul are cifra de control greșită.', 'adc-marketing'),
            'google_product_category_missing'  => __('Lipsește categoria de produs Google.', 'adc-marketing'),
            'google_product_category_format'   => __('Categoria de produs Google nu are formatul corect.', 'adc-marketing'),
            'product_type_too_long'            => __('Tipul de produs este prea lung.', 'adc-marketing'),
            'product_type_format'              => __('Tipul de produs nu are formatul corect.', 'adc-marketing'),
            'shipping_invalid'                 => __('Configurația livrării nu este validă.', 'adc-marketing'),
            'shipping_required'                => __('Livrarea este obligatorie pentru acest produs.', 'adc-marketing'),
            'shipping_country_missing'         => __('Lipsește țara pentru livrare.', 'adc-marketing'),
            'shipping_price_missing'           => __('Lipsește prețul de livrare.', 'adc-marketing'),
            'shipping_price_out_of_range'      => __('Prețul de livrare este în afara intervalului acceptat.', 'adc-marketing'),
            'custom_labels_invalid'            => __('Etichetele personalizate nu sunt valide.', 'adc-marketing'),
            'custom_labels_too_many'           => __('Sunt prea multe etichete personalizate.', 'adc-marketing'),
            'custom_label_invalid'             => __('O etichetă personalizată nu este validă.', 'adc-marketing'),
            'custom_label_too_long'            => __('O etichetă personalizată este prea lungă.', 'adc-marketing'),
            'weight_invalid'                   => __('Greutatea nu este validă.', 'adc-marketing'),
            // Generator output.
            'unknown_diagnostic'               => __('Un element al feedului nu este valid; verifică setările.', 'adc-marketing'),
            'field_registry_unavailable' => __('Registrul câmpurilor feedului nu este disponibil.', 'adc-marketing'),
            'param_enabled_invalid' => __('Starea „activ” a unui parametru nu este validă.', 'adc-marketing'),
            'param_field_duplicate' => __('Același parametru apare de două ori.', 'adc-marketing'),
            'param_field_unsupported' => __('Parametrul nu este acceptat pentru această destinație.', 'adc-marketing'),
            'param_required_missing' => __('Lipsește un parametru obligatoriu.', 'adc-marketing'),
            'param_row_invalid' => __('Un rând de parametri nu este valid.', 'adc-marketing'),
            'param_source_invalid' => __('Sursa unui parametru nu este validă.', 'adc-marketing'),
            'param_transform_invalid' => __('O transformare nu este validă.', 'adc-marketing'),
            'param_transforms_invalid' => __('Transformările unui parametru nu sunt valide.', 'adc-marketing'),
            'unsupported_channel' => __('Destinația nu este acceptată.', 'adc-marketing'),
            'params_invalid' => __('Parametrii feedului nu sunt valizi; previzualizarea nu a fost generată.', 'adc-marketing'),
            'tiktok_market_eligibility_requires_confirmation' => __('Eligibilitatea TikTok pe piața ta trebuie confirmată înainte de publicare.', 'adc-marketing'),
            'meta_file_size_limit_unverified'  => __('Limita de dimensiune a fișierului Meta nu a putut fi verificată; verifică manual înainte de publicare.', 'adc-marketing'),
            'id_incompatible'                  => __('ID-ul produsului nu este compatibil cu formatul canalului.', 'adc-marketing'),
            'feed_output_invalid'              => __('Fișierul generat nu este valid.', 'adc-marketing'),
            // Meta and TikTok validators.
            'additional_image_format' => __('O imagine suplimentară nu are un format acceptat (JPG sau PNG).', 'adc-marketing'),
            'additional_images_missing' => __('Lipsesc imaginile suplimentare, cerute de canal.', 'adc-marketing'),
            'age_group_invalid' => __('Grupa de vârstă nu are o valoare acceptată.', 'adc-marketing'),
            'availability_needs_mapping' => __('Disponibilitatea trebuie mapată la o valoare acceptată de canal.', 'adc-marketing'),
            'category_missing' => __('Lipsește categoria produsului.', 'adc-marketing'),
            'color_too_long' => __('Culoarea este prea lungă pentru acest canal.', 'adc-marketing'),
            'condition_missing' => __('Lipsește condiția produsului.', 'adc-marketing'),
            'currency_missing' => __('Lipsește moneda prețului.', 'adc-marketing'),
            'description_all_caps' => __('Descrierea este scrisă în întregime cu majuscule.', 'adc-marketing'),
            'description_contains_html' => __('Descrierea conține HTML; canalul acceptă doar text simplu.', 'adc-marketing'),
            'description_contains_links' => __('Descrierea conține linkuri, nepermise pentru acest canal.', 'adc-marketing'),
            'gender_invalid' => __('Genul nu are o valoare acceptată.', 'adc-marketing'),
            'google_product_category_depth' => __('Categoria Google are prea multe niveluri (maximum 3 acceptate).', 'adc-marketing'),
            'gtin_format' => __('GTIN-ul nu are un format acceptat.', 'adc-marketing'),
            'gtin_length' => __('GTIN-ul are o lungime nevalidă.', 'adc-marketing'),
            'image_link_format' => __('Imaginea principală nu are un format acceptat (JPG sau PNG).', 'adc-marketing'),
            'image_link_host_not_allowed' => __('Imaginea este găzduită pe un serviciu nepermis de canal.', 'adc-marketing'),
            'internal_label_invalid' => __('O etichetă internă nu este validă.', 'adc-marketing'),
            'internal_label_not_lowercase' => __('Etichetele interne trebuie scrise cu litere mici.', 'adc-marketing'),
            'internal_label_separator' => __('Eticheta internă conține un separator nepermis.', 'adc-marketing'),
            'internal_label_too_long' => __('O etichetă internă depășește lungimea maximă.', 'adc-marketing'),
            'internal_labels_invalid' => __('Lista de etichete interne nu este validă.', 'adc-marketing'),
            'internal_labels_too_many' => __('Sunt prea multe etichete interne pentru un produs.', 'adc-marketing'),
            'link_domain_mismatch' => __('Adresa produsului nu aparține domeniului magazinului.', 'adc-marketing'),
            'material_too_long' => __('Materialul este prea lung pentru acest canal.', 'adc-marketing'),
            'pattern_too_long' => __('Modelul este prea lung pentru acest canal.', 'adc-marketing'),
            'price_format' => __('Prețul nu are formatul cerut (număr cu punct zecimal).', 'adc-marketing'),
            'product_type_depth' => __('Tipul de produs are prea multe niveluri (maximum 3 acceptate).', 'adc-marketing'),
            'sale_price_effective_date_invalid' => __('Perioada de reducere nu este validă.', 'adc-marketing'),
            'sale_price_effective_date_missing' => __('Lipsește perioada de valabilitate a reducerii.', 'adc-marketing'),
            'sale_price_not_lower' => __('Prețul redus trebuie să fie mai mic decât prețul normal.', 'adc-marketing'),
            'shipping_weight_format' => __('Greutatea de livrare nu are un format acceptat (ex. 0.3 lb).', 'adc-marketing'),
            'shipping_zone_incomplete' => __('Zona de livrare este incompletă.', 'adc-marketing'),
            'shipping_zone_invalid' => __('Zona de livrare nu este validă.', 'adc-marketing'),
            'size_too_long' => __('Mărimea este prea lungă pentru acest canal.', 'adc-marketing'),
            'sku_id_invalid_encoding' => __('ID-ul SKU conține caractere nevalide.', 'adc-marketing'),
            'sku_id_missing' => __('Lipsește ID-ul SKU al produsului.', 'adc-marketing'),
            'status_invalid' => __('Starea produsului nu are o valoare acceptată.', 'adc-marketing'),
            'tax_invalid' => __('Taxa nu are o valoare validă.', 'adc-marketing'),
            'title_above_recommended' => __('Titlul depășește lungimea recomandată.', 'adc-marketing'),
            'title_emoji' => __('Titlul conține emoji, nepermise pentru acest canal.', 'adc-marketing'),
            'title_promotional' => __('Titlul conține text promoțional, nepermis pentru acest canal.', 'adc-marketing'),
            'video_link_invalid' => __('Adresa videoclipului nu este validă.', 'adc-marketing'),
            'video_link_platform' => __('Adresa videoclipului nu este acceptată de platformă.', 'adc-marketing'),
            'weight_unit_missing' => __('Lipsește unitatea de măsură pentru greutate.', 'adc-marketing'),
        );
    }

    /** Compact Catalog warning for missing critical requirements; full checklist lives in Logs. */
    private static function render_critical_requirements_notice(?array $items): void
    {
        if (null === $items) {
            return;
        }
        $critical_codes = array('woocommerce_active', 'woocommerce_version', 'xmlreader', 'xmlwriter', 'storage_writable');
        $missing = array();
        foreach ($items as $item) {
            if (in_array($item['code'], $critical_codes, true) && !$item['ok']) {
                $missing[] = $item['label'];
            }
        }
        if ($missing === array()) {
            return;
        }

        $logs_url = self_admin_url('admin.php?page=adc-marketing&tab=logs');
        echo '<p class="adc-feeds__notice adc-feeds__notice--warning">'
            . esc_html(sprintf(__('Cerințe Catalog lipsă: %s.', 'adc-marketing'), implode(', ', $missing)))
            . ' <a href="' . esc_url($logs_url) . '">' . esc_html__('Vezi detaliile în Jurnale.', 'adc-marketing') . '</a></p>';
    }

    /**
     * Requirement rows from ADC_Feed_Module::requirements(): code, status, label, ok, warning.
     * Null when the module does not provide them.
     *
     * @return array<int, array{code: string, status: string, label: string, ok: bool, warning: bool}>|null
     */
    private static function requirements(): ?array
    {
        if (!class_exists('ADC_Feed_Module') || !method_exists('ADC_Feed_Module', 'requirements')) {
            return null;
        }

        $rows = ADC_Feed_Module::requirements();
        $out  = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (is_array($row) && isset($row['label'])) {
                $out[] = array(
                    'code'    => (string) ($row['code'] ?? ''),
                    'status'  => (string) ($row['status'] ?? ''),
                    'label'   => (string) $row['label'],
                    'ok'      => (bool) ($row['ok'] ?? false),
                    'warning' => (bool) ($row['warning'] ?? false),
                );
            }
        }

        return $out;
    }

    /**
     * Which WooCommerce state applies: 'active', 'inactive' or 'missing'.
     *
     * @return string
     */
    private static function woocommerce_state(): string
    {
        if (self::woocommerce_loaded()) {
            return 'active';
        }

        return self::woocommerce_installed() ? 'inactive' : 'missing';
    }

    /**
     * WooCommerce is loaded in this request (its main class exists).
     *
     * @return bool
     */
    private static function woocommerce_loaded(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * WooCommerce's plugin file exists in the plugins directory.
     *
     * @return bool
     */
    private static function woocommerce_installed(): bool
    {
        if (!function_exists('get_plugins')) {
            $admin_file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($admin_file)) {
                require_once $admin_file;
            }
        }

        if (!function_exists('get_plugins')) {
            return false;
        }

        return isset(get_plugins()[self::WC_PLUGIN_FILE]);
    }

    /**
     * Details link to the WordPress.org plugin information screen.
     *
     * @return string
     */
    private static function details_url(): string
    {
        return self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . self::WC_SLUG);
    }
}
