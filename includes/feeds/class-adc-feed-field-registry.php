<?php
/**
 * Feed field registry: the per-channel attribute catalogue.
 *
 * Pure data plus simple accessors — no feed generation, no validation, no I/O.
 * Every channel lists all attributes documented in
 * docs/research/feeds-platform-specs.md for that platform (§1 Google, §2 Meta,
 * §3 TikTok), not only the required ones, so the admin wizard can render the
 * full mapping surface with real limits and enums.
 *
 * Labels and help texts are Romanian gettext literals built inside methods (never
 * class constants) so bin/i18n-build.py can extract them. Where the research
 * marks a limit as NEVERIFICAT, the help text says so.
 *
 * `default_source` uses the source vocabulary of ADC_Feed_Params, so the wizard can
 * turn a registry default into a parameter row without translating anything:
 * - native:   a WooCommerce product source (title, permalink, regular_price, image,
 *             gallery, stock_status, weight, category_path, feed_item_id, ...)
 * - meta:     a WooCommerce product meta key
 * - attribute / taxonomy: a product attribute or taxonomy
 * - category_map: the mapped Google taxonomy category
 * - constant / template: a fixed value, or a {placeholder} template
 *
 * Registration is deliberately not a default_source: shipping comes from the delivery
 * settings and the profile emits it as structured <g:shipping> rows, while a parameter
 * row would inject a single string. The required-level rule for Romania stays enforced
 * by the profile audit and by ADC_Feed_Validator_Google.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Per-channel attribute catalogue with documented limits and enums. */
final class ADC_Feed_Field_Registry
{
    /** Supported channels, in display order. */
    private const CHANNELS = array('google', 'meta', 'tiktok');

    /**
     * Supported channels.
     *
     * @return string[]
     */
    public static function channels(): array
    {
        return self::CHANNELS;
    }

    /**
     * All documented attributes of a channel, keyed by field key.
     *
     * @param string $channel Channel slug (google, meta, tiktok).
     * @return array<string,array{key:string,label:string,required:bool,type:string,enum?:string[],max_length?:int,help:string,default_source?:array{type:string,value:string},repeatable?:bool}>
     */
    public static function fields(string $channel): array
    {
        $channel = strtolower(trim($channel));

        return match ($channel) {
            'google' => self::google_fields(),
            'meta' => self::meta_fields(),
            'tiktok' => self::tiktok_fields(),
            default => array(),
        };
    }

    /**
     * Field keys a channel requires before it accepts the feed.
     *
     * @param string $channel Channel slug.
     * @return string[]
     */
    public static function required_keys(string $channel): array
    {
        $keys = array();
        foreach (self::fields($channel) as $field) {
            if (true === $field['required']) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    /**
     * One field definition, or null when the channel/key is unknown.
     *
     * @param string $channel Channel slug.
     * @param string $key     Field key.
     * @return array{key:string,label:string,required:bool,type:string,enum?:string[],max_length?:int,help:string,default_source?:array{type:string,value:string},repeatable?:bool}|null
     */
    public static function get(string $channel, string $key): ?array
    {
        $fields = self::fields($channel);

        return $fields[$key] ?? null;
    }

    /**
     * Build one field definition; optional keys are only emitted when set.
     *
     * @param string              $key      Stable snake_case field key.
     * @param string              $label    Romanian label.
     * @param bool                $required True when the platform rejects the item without it.
     * @param string              $type     One of self::TYPES.
     * @param array<string,mixed> $extra    enum, max_length, help, source, repeatable.
     * @return array{key:string,label:string,required:bool,type:string,enum?:string[],max_length?:int,help:string,default_source?:array{type:string,value:string},repeatable?:bool}
     */
    private static function row(string $key, string $label, bool $required, string $type, array $extra = array()): array
    {
        $row = array(
            'key'      => $key,
            'label'    => $label,
            'required' => $required,
            'type'     => $type,
        );

        if (isset($extra['enum'])) {
            $row['enum'] = array_values((array) $extra['enum']);
        }

        if (isset($extra['max_length'])) {
            $row['max_length'] = (int) $extra['max_length'];
        }

        $row['help'] = (string) ($extra['help'] ?? '');

        if (isset($extra['source']) && is_array($extra['source'])) {
            $row['default_source'] = array(
                'type'  => (string) ($extra['source'][0] ?? ''),
                'value' => (string) ($extra['source'][1] ?? ''),
            );
        }

        if (!empty($extra['repeatable'])) {
            $row['repeatable'] = true;
        }

        return $row;
    }

    /**
     * Google Merchant Center attribute catalogue (spec §1.2 A–G).
     *
     * Required for a Romanian store: id, title, description, link, image_link,
     * availability, price, brand and shipping (§1.2 G lists RO among the
     * countries where shipping is required for Shopping ads and free listings).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function google_fields(): array
    {
        return array(
            'id' => self::row('id', __('ID produs', 'adc-marketing'), true, 'text', array(
                'max_length' => 50,
                'help' => __('Stabil și unic în tot feedul, identic cu ID-ul trimis de tracking.', 'adc-marketing'),
                'source' => array('native', 'feed_item_id'),
            )),
            'title' => self::row('title', __('Titlu', 'adc-marketing'), true, 'text', array(
                'max_length' => 150,
                'help' => __('Numele produsului, text simplu, fără HTML.', 'adc-marketing'),
                'source' => array('native', 'title'),
            )),
            'structured_title' => self::row('structured_title', __('Titlu structurat', 'adc-marketing'), false, 'text', array(
                'max_length' => 150,
                'help' => __('Alternativă la titlu, cu digital_source_type și content; pentru asset-uri AI.', 'adc-marketing'),
            )),
            'description' => self::row('description', __('Descriere', 'adc-marketing'), true, 'text', array(
                'max_length' => 5000,
                'help' => __('Text simplu, fără marcaj HTML.', 'adc-marketing'),
                'source' => array('native', 'description'),
            )),
            'structured_description' => self::row('structured_description', __('Descriere structurată', 'adc-marketing'), false, 'text', array(
                'max_length' => 5000,
                'help' => __('Alternativă la descriere, cu digital_source_type și content.', 'adc-marketing'),
            )),
            'link' => self::row('link', __('Link produs', 'adc-marketing'), true, 'url', array(
                'help' => __('URL-ul paginii reale de produs.', 'adc-marketing'),
                'source' => array('native', 'permalink'),
            )),
            'image_link' => self::row('image_link', __('Imagine principală', 'adc-marketing'), true, 'url', array(
                'help' => __('Imagine accesibilă crawlerului; din 31.01.2027 se cere minimum 500×500 px.', 'adc-marketing'),
                'source' => array('native', 'image'),
            )),
            'additional_image_link' => self::row('additional_image_link', __('Imagini secundare', 'adc-marketing'), false, 'list', array(
                'help' => __('Până la 10 URL-uri, maximum 2000 de caractere fiecare.', 'adc-marketing'),
                'source' => array('native', 'gallery'),
                'repeatable' => true,
            )),
            'video_link' => self::row('video_link', __('Link video', 'adc-marketing'), false, 'url', array(
                'max_length' => 2000,
                'help' => __('Atribut acceptat din 2026; o eroare de video nu blochează oferta.', 'adc-marketing'),
            )),
            'mobile_link' => self::row('mobile_link', __('Link mobil', 'adc-marketing'), false, 'url', array(
                'max_length' => 2000,
                'help' => __('Versiunea mobilă a paginii de produs.', 'adc-marketing'),
            )),
            'virtual_model_link' => self::row('virtual_model_link', __('Link model virtual', 'adc-marketing'), false, 'url', array(
                'help' => __('Acceptat doar pentru Statele Unite.', 'adc-marketing'),
            )),
            'warranty' => self::row('warranty', __('Garanție', 'adc-marketing'), false, 'text', array(
                'help' => __('Sub-atribute duration și duration_unit (month sau year); doar dacă reglementarea locală o cere.', 'adc-marketing'),
            )),
            'cost_of_goods_sold' => self::row('cost_of_goods_sold', __('Cost mărfuri vândute', 'adc-marketing'), false, 'price', array(
                'help' => __('Preț plus monedă, folosit pentru raportarea marjei.', 'adc-marketing'),
            )),
            'expiration_date' => self::row('expiration_date', __('Dată expirare', 'adc-marketing'), false, 'text', array(
                'max_length' => 25,
                'help' => __('Format ISO 8601, maximum 25 de caractere.', 'adc-marketing'),
            )),
            'availability' => self::row('availability', __('Disponibilitate', 'adc-marketing'), true, 'enum', array(
                'enum' => array('in_stock', 'out_of_stock', 'preorder', 'backorder'),
                'help' => __('Trebuie să corespundă paginii de produs și checkout-ului.', 'adc-marketing'),
                'source' => array('native', 'stock_status'),
            )),
            'availability_date' => self::row('availability_date', __('Data disponibilității', 'adc-marketing'), false, 'text', array(
                'max_length' => 25,
                'help' => __('Cerută când disponibilitatea este preorder; ISO 8601, maximum 25 de caractere.', 'adc-marketing'),
            )),
            'price' => self::row('price', __('Preț', 'adc-marketing'), true, 'price', array(
                'help' => __('Preț public final, punct decimal plus cod ISO 4217.', 'adc-marketing'),
                'source' => array('native', 'regular_price'),
            )),
            'sale_price' => self::row('sale_price', __('Preț redus', 'adc-marketing'), false, 'price', array(
                'help' => __('Se trimite împreună cu prețul neredus.', 'adc-marketing'),
                'source' => array('native', 'sale_price'),
            )),
            'sale_price_effective_date' => self::row('sale_price_effective_date', __('Perioada prețului redus', 'adc-marketing'), false, 'text', array(
                'max_length' => 25,
                'help' => __('Format AAAA-LL-ZZThh:mm[+hhmm]/AAAA-LL-ZZThh:mm[+hhmm].', 'adc-marketing'),
            )),
            'unit_pricing_measure' => self::row('unit_pricing_measure', __('Preț pe unitate', 'adc-marketing'), false, 'text', array(
                'help' => __('Număr plus unitate; în UE poate fi cerut de legislația locală.', 'adc-marketing'),
            )),
            'unit_pricing_base_measure' => self::row('unit_pricing_base_measure', __('Unitate de referință', 'adc-marketing'), false, 'text', array(
                'help' => __('Întreg plus unitate, aceeași unitate ca măsura de preț.', 'adc-marketing'),
            )),
            'auto_pricing_min_price' => self::row('auto_pricing_min_price', __('Preț minim automat', 'adc-marketing'), false, 'price', array(
                'help' => __('Prag pentru reducerile automate.', 'adc-marketing'),
            )),
            'installment' => self::row('installment', __('Rate', 'adc-marketing'), false, 'text', array(
                'help' => __('Sub-atribute proprii; în afara scopului MVP.', 'adc-marketing'),
            )),
            'subscription_cost' => self::row('subscription_cost', __('Abonament', 'adc-marketing'), false, 'text', array(
                'help' => __('Sub-atribute proprii; în afara scopului MVP.', 'adc-marketing'),
            )),
            'loyalty_program' => self::row('loyalty_program', __('Program de loialitate', 'adc-marketing'), false, 'text', array(
                'help' => __('Sub-atribute proprii; în afara scopului MVP.', 'adc-marketing'),
            )),
            'maximum_retail_price' => self::row('maximum_retail_price', __('Preț maxim de vânzare', 'adc-marketing'), false, 'price', array(
                'help' => __('Acceptat doar pentru India.', 'adc-marketing'),
            )),
            'finance' => self::row('finance', __('Finanțare', 'adc-marketing'), false, 'text', array(
                'help' => __('Doar pentru Vehicle ads; în afara scopului MVP.', 'adc-marketing'),
            )),
            'lease' => self::row('lease', __('Leasing', 'adc-marketing'), false, 'text', array(
                'help' => __('Doar pentru Vehicle ads; în afara scopului MVP.', 'adc-marketing'),
            )),
            'google_product_category' => self::row('google_product_category', __('Categorie Google', 'adc-marketing'), false, 'text', array(
                'help' => __('ID numeric sau cale din taxonomia Google; recomandat pentru aprobare.', 'adc-marketing'),
                'source' => array('category_map', 'google_product_category'),
            )),
            'product_type' => self::row('product_type', __('Tip produs', 'adc-marketing'), false, 'text', array(
                'max_length' => 750,
                'help' => __('Categoria proprie a magazinului, format A > B > C.', 'adc-marketing'),
                'source' => array('native', 'category_path'),
            )),
            'brand' => self::row('brand', __('Brand', 'adc-marketing'), true, 'text', array(
                'max_length' => 70,
                'help' => __('Obligatoriu pentru produsele noi; excepții: filme, cărți, muzică.', 'adc-marketing'),
                'source' => array('native', 'brand'),
            )),
            'gtin' => self::row('gtin', __('GTIN', 'adc-marketing'), false, 'text', array(
                'max_length' => 50,
                'help' => __('Maximum 14 cifre per valoare; UPC, EAN, JAN, ISBN-13 sau ITF-14.', 'adc-marketing'),
                'source' => array('meta', '_adc_feed_gtin'),
            )),
            'mpn' => self::row('mpn', __('MPN', 'adc-marketing'), false, 'text', array(
                'max_length' => 70,
                'help' => __('Necesar doar dacă produsul nu are GTIN.', 'adc-marketing'),
                'source' => array('meta', '_adc_feed_mpn'),
            )),
            'identifier_exists' => self::row('identifier_exists', __('Identificatori existenți', 'adc-marketing'), false, 'enum', array(
                'enum' => array('yes', 'no'),
                'help' => __('no înseamnă că produsul nu are brand, GTIN sau MPN.', 'adc-marketing'),
            )),
            'condition' => self::row('condition', __('Condiție', 'adc-marketing'), false, 'enum', array(
                'enum' => array('new', 'refurbished', 'used'),
                'help' => __('Obligatorie pentru produsele used și refurbished.', 'adc-marketing'),
                'source' => array('constant', 'new'),
            )),
            'adult' => self::row('adult', __('Conținut pentru adulți', 'adc-marketing'), false, 'enum', array(
                'enum' => array('yes', 'no'),
                'help' => __('Obligatoriu când produsul este conținut pentru adulți.', 'adc-marketing'),
            )),
            'age_group' => self::row('age_group', __('Grupă de vârstă', 'adc-marketing'), false, 'enum', array(
                'enum' => array('newborn', 'infant', 'toddler', 'kids', 'adult'),
                'help' => __('Cerută condiționat, pentru apparel și free listings.', 'adc-marketing'),
            )),
            'gender' => self::row('gender', __('Gen', 'adc-marketing'), false, 'enum', array(
                'enum' => array('male', 'female', 'unisex'),
                'help' => __('Cerut condiționat, pentru apparel.', 'adc-marketing'),
            )),
            'color' => self::row('color', __('Culoare', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Maximum 40 de valori per culoare.', 'adc-marketing'),
            )),
            'size' => self::row('size', __('Mărime', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Cerută condiționat, pentru îmbrăcăminte și încălțăminte.', 'adc-marketing'),
            )),
            'size_type' => self::row('size_type', __('Tip mărime', 'adc-marketing'), false, 'enum', array(
                'enum' => array('regular', 'petite', 'maternity', 'big', 'tall', 'plus'),
                'help' => __('Maximum 2 valori.', 'adc-marketing'),
            )),
            'size_system' => self::row('size_system', __('Sistem de mărimi', 'adc-marketing'), false, 'text', array(
                'help' => __('Țara sistemului de mărimi.', 'adc-marketing'),
            )),
            'material' => self::row('material', __('Material', 'adc-marketing'), false, 'text', array(
                'help' => __('Cerut când materialul diferențiază variantele.', 'adc-marketing'),
            )),
            'pattern' => self::row('pattern', __('Model', 'adc-marketing'), false, 'text', array(
                'help' => __('Cerut când modelul diferențiază variantele.', 'adc-marketing'),
            )),
            'item_group_id' => self::row('item_group_id', __('ID grup variante', 'adc-marketing'), false, 'text', array(
                'max_length' => 50,
                'help' => __('Nu este impus pentru România; recomandat pentru variații.', 'adc-marketing'),
            )),
            'item_group_title' => self::row('item_group_title', __('Titlu grup variante', 'adc-marketing'), false, 'text', array(
                'help' => __('Titlul produsului părinte, pentru variante.', 'adc-marketing'),
            )),
            'multipack' => self::row('multipack', __('Multipack', 'adc-marketing'), false, 'number', array(
                'help' => __('Număr întreg; cerut condiționat pe anumite piețe și pentru free listings.', 'adc-marketing'),
            )),
            'is_bundle' => self::row('is_bundle', __('Pachet de produse', 'adc-marketing'), false, 'enum', array(
                'enum' => array('yes', 'no'),
                'help' => __('Marchează pachetele care conțin mai multe produse distincte.', 'adc-marketing'),
            )),
            'certification' => self::row('certification', __('Certificare (EPREL)', 'adc-marketing'), false, 'text', array(
                'help' => __('Format EC:EPREL:<cod>; obligatoriu pentru eticheta energetică UE când reglementarea o cere.', 'adc-marketing'),
                'source' => array('meta', '_adc_feed_eprel_code'),
            )),
            'energy_efficiency_class' => self::row('energy_efficiency_class', __('Clasă energetică', 'adc-marketing'), false, 'text', array(
                'help' => __('Doar CH, NO și UK; în UE se folosește certification.', 'adc-marketing'),
            )),
            'energy_efficiency_class_min' => self::row('energy_efficiency_class_min', __('Clasă energetică minimă', 'adc-marketing'), false, 'text', array(
                'help' => __('Doar CH, NO și UK.', 'adc-marketing'),
            )),
            'energy_efficiency_class_max' => self::row('energy_efficiency_class_max', __('Clasă energetică maximă', 'adc-marketing'), false, 'text', array(
                'help' => __('Doar CH, NO și UK.', 'adc-marketing'),
            )),
            'product_detail' => self::row('product_detail', __('Detaliu produs', 'adc-marketing'), false, 'text', array(
                'help' => __('Sub-atribute secțiune, atribut și valoare.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'product_highlight' => self::row('product_highlight', __('Punct forte produs', 'adc-marketing'), false, 'text', array(
                'help' => __('Până la 6 puncte forte scurte per produs.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'question_and_answer' => self::row('question_and_answer', __('Întrebare și răspuns', 'adc-marketing'), false, 'text', array(
                'help' => __('Sub-atribute question, answer, question_type.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'related_product' => self::row('related_product', __('Produs asociat', 'adc-marketing'), false, 'text', array(
                'help' => __('ID-uri de produse asociate, pentru recomandări.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'popularity_rank' => self::row('popularity_rank', __('Rang popularitate', 'adc-marketing'), false, 'number', array(
                'help' => __('Număr întreg, folosit ca semnal de sortare.', 'adc-marketing'),
            )),
            'document_link' => self::row('document_link', __('Link document', 'adc-marketing'), false, 'url', array(
                'help' => __('Sub-atribute link și format (de exemplu PDF).', 'adc-marketing'),
                'repeatable' => true,
            )),
            'lifestyle_image_link' => self::row('lifestyle_image_link', __('Imagine de lifestyle', 'adc-marketing'), false, 'url', array(
                'help' => __('Imagine în context de utilizare, pe lângă imaginea principală.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'short_title' => self::row('short_title', __('Titlu scurt', 'adc-marketing'), false, 'text', array(
                'max_length' => 150,
                'help' => __('Recomandat între 5 și 65 de caractere, doar pentru Demand Gen.', 'adc-marketing'),
            )),
            'product_length' => self::row('product_length', __('Lungime produs', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs.', 'adc-marketing'),
            )),
            'product_width' => self::row('product_width', __('Lățime produs', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs.', 'adc-marketing'),
            )),
            'product_height' => self::row('product_height', __('Înălțime produs', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs.', 'adc-marketing'),
            )),
            'product_weight' => self::row('product_weight', __('Greutate produs', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs.', 'adc-marketing'),
            )),
            'ads_redirect' => self::row('ads_redirect', __('Redirect anunț', 'adc-marketing'), false, 'url', array(
                'max_length' => 2000,
                'help' => __('URL de tracking pentru anunțuri, maximum 2000 de caractere.', 'adc-marketing'),
            )),
            'custom_label_0' => self::row('custom_label_0', __('Etichetă custom 0', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Maximum 1.000 de valori unice per cont pentru acest slot.', 'adc-marketing'),
            )),
            'custom_label_1' => self::row('custom_label_1', __('Etichetă custom 1', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Maximum 1.000 de valori unice per cont pentru acest slot.', 'adc-marketing'),
            )),
            'custom_label_2' => self::row('custom_label_2', __('Etichetă custom 2', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Maximum 1.000 de valori unice per cont pentru acest slot.', 'adc-marketing'),
            )),
            'custom_label_3' => self::row('custom_label_3', __('Etichetă custom 3', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Maximum 1.000 de valori unice per cont pentru acest slot.', 'adc-marketing'),
            )),
            'custom_label_4' => self::row('custom_label_4', __('Etichetă custom 4', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Maximum 1.000 de valori unice per cont pentru acest slot.', 'adc-marketing'),
            )),
            'promotion_id' => self::row('promotion_id', __('ID promoție', 'adc-marketing'), false, 'text', array(
                'max_length' => 50,
                'help' => __('Fără spații sau simboluri; leagă produsul de o promoție.', 'adc-marketing'),
            )),
            'excluded_destination' => self::row('excluded_destination', __('Destinații excluse', 'adc-marketing'), false, 'list', array(
                'enum' => array(
                    'Shopping_ads',
                    'Display_ads',
                    'Local_inventory_ads',
                    'Free_listings',
                    'Free_local_listings',
                    'Cloud_retail',
                    'Local_cloud_retail',
                    'Youtube_affiliate',
                    'Youtube_merchandise',
                ),
                'help' => __('Se exclude reciproc cu destinațiile incluse; excluderea are prioritate.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'included_destination' => self::row('included_destination', __('Destinații incluse', 'adc-marketing'), false, 'list', array(
                'enum' => array(
                    'Shopping_ads',
                    'Display_ads',
                    'Local_inventory_ads',
                    'Free_listings',
                    'Free_local_listings',
                    'Cloud_retail',
                    'Local_cloud_retail',
                    'Youtube_affiliate',
                    'Youtube_merchandise',
                ),
                'help' => __('Destinațiile în care apare produsul; exclude_le au prioritate.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'shopping_ads_excluded_country' => self::row('shopping_ads_excluded_country', __('Țară exclusă din Shopping ads', 'adc-marketing'), false, 'text', array(
                'help' => __('Cod de țară ISO 3166; se aplică doar pentru Shopping ads.', 'adc-marketing'),
            )),
            'pause' => self::row('pause', __('Pauză', 'adc-marketing'), false, 'enum', array(
                'enum' => array('yes', 'no'),
                'help' => __('Oprește temporar produsul fără a-l șterge din feed.', 'adc-marketing'),
            )),
            'external_seller_id' => self::row('external_seller_id', __('ID vânzător extern', 'adc-marketing'), false, 'text', array(
                'max_length' => 50,
                'help' => __('Doar pentru conturi marketplace, între 1 și 50 de caractere.', 'adc-marketing'),
            )),
            'shipping' => self::row('shipping', __('Livrare', 'adc-marketing'), false, 'text', array(
                'help' => __('Format Țară[:Regiune]:Serviciu:Preț; obligatoriu pentru România la Shopping ads și free listings, livrat din setările de livrare.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'shipping_weight' => self::row('shipping_weight', __('Greutate livrare', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs; cerut pentru tarifele calculate de curier.', 'adc-marketing'),
                'source' => array('native', 'weight'),
            )),
            'shipping_length' => self::row('shipping_length', __('Lungime colet', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs.', 'adc-marketing'),
            )),
            'shipping_width' => self::row('shipping_width', __('Lățime colet', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs.', 'adc-marketing'),
            )),
            'shipping_height' => self::row('shipping_height', __('Înălțime colet', 'adc-marketing'), false, 'number', array(
                'help' => __('Unități consistente per produs.', 'adc-marketing'),
            )),
            'max_handling_time' => self::row('max_handling_time', __('Timp maxim de procesare', 'adc-marketing'), false, 'number', array(
                'help' => __('În zile lucrătoare.', 'adc-marketing'),
            )),
            'min_handling_time' => self::row('min_handling_time', __('Timp minim de procesare', 'adc-marketing'), false, 'number', array(
                'help' => __('În zile lucrătoare.', 'adc-marketing'),
            )),
            'ships_from_country' => self::row('ships_from_country', __('Țara de expediere', 'adc-marketing'), false, 'text', array(
                'help' => __('Cod de țară ISO 3166.', 'adc-marketing'),
            )),
            'handling_cutoff_time' => self::row('handling_cutoff_time', __('Oră limită de procesare', 'adc-marketing'), false, 'text', array(
                'help' => __('Atribut nou din 2026: ora zilnică până la care comenzile intră în procesare.', 'adc-marketing'),
            )),
            'minimum_order_value' => self::row('minimum_order_value', __('Valoare minimă comandă', 'adc-marketing'), false, 'price', array(
                'help' => __('Atribut nou din 2026: pragul minim de comandă.', 'adc-marketing'),
            )),
            'free_shipping_threshold' => self::row('free_shipping_threshold', __('Prag livrare gratuită', 'adc-marketing'), false, 'price', array(
                'help' => __('Valoarea de la care livrarea devine gratuită.', 'adc-marketing'),
            )),
            'carrier_shipping' => self::row('carrier_shipping', __('Curier', 'adc-marketing'), false, 'text', array(
                'help' => __('Numele serviciului de curierat pentru tarifele calculate.', 'adc-marketing'),
            )),
            'carrier_price' => self::row('carrier_price', __('Preț curier', 'adc-marketing'), false, 'price', array(
                'help' => __('Costul serviciului de curierat.', 'adc-marketing'),
            )),
            'carrier_transit_time' => self::row('carrier_transit_time', __('Timp de tranzit', 'adc-marketing'), false, 'number', array(
                'help' => __('În zile, pentru tarifele calculate de curier.', 'adc-marketing'),
            )),
            'shipping_label' => self::row('shipping_label', __('Etichetă livrare', 'adc-marketing'), false, 'text', array(
                'help' => __('Leagă produsul de un grup de setări de livrare din cont.', 'adc-marketing'),
            )),
            'shipping_transit_business_days' => self::row('shipping_transit_business_days', __('Zile lucrătoare de tranzit', 'adc-marketing'), false, 'number', array(
                'help' => __('Număr de zile lucrătoare pentru tranzit.', 'adc-marketing'),
            )),
            'shipping_handling_business_days' => self::row('shipping_handling_business_days', __('Zile lucrătoare de procesare', 'adc-marketing'), false, 'number', array(
                'help' => __('Număr de zile lucrătoare pentru procesare.', 'adc-marketing'),
            )),
            'return' => self::row('return', __('Retur', 'adc-marketing'), false, 'text', array(
                'help' => __('Format Țară:fereastră:condiție:metodă:rambursare:schimb; recomandat la nivel de cont.', 'adc-marketing'),
                'repeatable' => true,
            )),
        );
    }

    /**
     * Meta Commerce Catalog attribute catalogue (spec §2.2).
     *
     * Required: id, title, description, availability, condition, price, link,
     * image_link, additional_image_link (the official table marks it Required),
     * brand and one of google_product_category / fb_product_category. Meta is not
     * a Google alias: availability has two US English values and segmentation
     * uses the internal_label list, not numbered custom label slots.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function meta_fields(): array
    {
        return array(
            'id' => self::row('id', __('ID produs', 'adc-marketing'), true, 'text', array(
                'max_length' => 100,
                'help' => __('Unic și identic cu content_id din Meta Pixel pentru dynamic ads.', 'adc-marketing'),
                'source' => array('native', 'feed_item_id'),
            )),
            'title' => self::row('title', __('Titlu', 'adc-marketing'), true, 'text', array(
                'max_length' => 200,
                'help' => __('Recomandat maximum 65 de caractere pentru anunțuri.', 'adc-marketing'),
                'source' => array('native', 'title'),
            )),
            'description' => self::row('description', __('Descriere', 'adc-marketing'), true, 'text', array(
                'max_length' => 9999,
                'help' => __('Text simplu: fără HTML, fără linkuri și fără majuscule integrale.', 'adc-marketing'),
                'source' => array('native', 'description'),
            )),
            'rich_text_description' => self::row('rich_text_description', __('Descriere cu formatare', 'adc-marketing'), false, 'text', array(
                'max_length' => 9999,
                'help' => __('HTML pe lista de taguri permise; nu se folosește în anunțuri.', 'adc-marketing'),
            )),
            'availability' => self::row('availability', __('Disponibilitate', 'adc-marketing'), true, 'enum', array(
                'enum' => array('in stock', 'out of stock'),
                'help' => __('Exact cele două valori Meta, în engleza americană.', 'adc-marketing'),
                'source' => array('native', 'stock_status'),
            )),
            'condition' => self::row('condition', __('Condiție', 'adc-marketing'), true, 'enum', array(
                'enum' => array('new', 'refurbished', 'used'),
                'help' => __('Obligatorie pentru fiecare articol din catalogul Meta.', 'adc-marketing'),
                'source' => array('native', 'condition'),
            )),
            'price' => self::row('price', __('Preț', 'adc-marketing'), true, 'price', array(
                'help' => __('Număr cu punct decimal urmat de codul ISO 4217, fără simbol.', 'adc-marketing'),
                'source' => array('native', 'regular_price'),
            )),
            'link' => self::row('link', __('Link produs', 'adc-marketing'), true, 'url', array(
                'help' => __('Pagină de produs pe domeniul magazinului.', 'adc-marketing'),
                'source' => array('native', 'permalink'),
            )),
            'image_link' => self::row('image_link', __('Imagine principală', 'adc-marketing'), true, 'url', array(
                'help' => __('JPEG sau PNG, minimum 500×500 px, maximum 8 MB.', 'adc-marketing'),
                'source' => array('native', 'image'),
            )),
            'additional_image_link' => self::row('additional_image_link', __('Imagini secundare', 'adc-marketing'), true, 'list', array(
                'help' => __('Marcat obligatoriu de Meta; până la 50 de URL-uri, maximum 2000 de caractere fiecare.', 'adc-marketing'),
                'source' => array('native', 'gallery'),
                'repeatable' => true,
            )),
            'video_url' => self::row('video_url', __('Link video', 'adc-marketing'), false, 'url', array(
                'help' => __('Până la 20 de linkuri directe la fișier, maximum 200 MB; nu YouTube.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'google_product_category' => self::row('google_product_category', __('Categorie Google', 'adc-marketing'), true, 'text', array(
                'help' => __('ID sau nume; necesar dacă nu se trimite fb_product_category.', 'adc-marketing'),
                'source' => array('category_map', 'google_product_category'),
            )),
            'fb_product_category' => self::row('fb_product_category', __('Categorie Facebook', 'adc-marketing'), false, 'text', array(
                'help' => __('Alternativă la categoria Google; una dintre cele două este obligatorie.', 'adc-marketing'),
            )),
            'brand' => self::row('brand', __('Brand', 'adc-marketing'), true, 'text', array(
                'max_length' => 100,
                'help' => __('Cel puțin unul dintre brand, GTIN sau MPN este obligatoriu.', 'adc-marketing'),
                'source' => array('native', 'brand'),
            )),
            'gtin' => self::row('gtin', __('GTIN', 'adc-marketing'), false, 'text', array(
                'help' => __('UPC, EAN, JAN, ISBN-13 sau ITF-14, fără spații sau cratime.', 'adc-marketing'),
            )),
            'mpn' => self::row('mpn', __('MPN', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Codul producătorului; alternativă la GTIN.', 'adc-marketing'),
            )),
            'item_group_id' => self::row('item_group_id', __('ID grup variante', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Obligatoriu pentru produsele cu variante; același ID pentru toate variantele.', 'adc-marketing'),
            )),
            'color' => self::row('color', __('Culoare', 'adc-marketing'), false, 'text', array(
                'max_length' => 200,
                'help' => __('Cuvinte, nu coduri hexazecimale.', 'adc-marketing'),
            )),
            'size' => self::row('size', __('Mărime', 'adc-marketing'), false, 'text', array(
                'max_length' => 200,
                'help' => __('Fără cuvântul „size” în valoare.', 'adc-marketing'),
            )),
            'age_group' => self::row('age_group', __('Grupă de vârstă', 'adc-marketing'), false, 'enum', array(
                'enum' => array('adult', 'all ages', 'teen', 'kids', 'toddler', 'infant', 'newborn'),
                'help' => __('Cerută când se aplică produsului.', 'adc-marketing'),
            )),
            'gender' => self::row('gender', __('Gen', 'adc-marketing'), false, 'enum', array(
                'enum' => array('female', 'male', 'unisex'),
                'help' => __('Cerut când se aplică produsului.', 'adc-marketing'),
            )),
            'material' => self::row('material', __('Material', 'adc-marketing'), false, 'text', array(
                'max_length' => 200,
                'help' => __('Cerut când se aplică produsului.', 'adc-marketing'),
            )),
            'pattern' => self::row('pattern', __('Model', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Cerut când se aplică produsului.', 'adc-marketing'),
            )),
            'additional_variant_attribute' => self::row('additional_variant_attribute', __('Atribut de variantă suplimentar', 'adc-marketing'), false, 'text', array(
                'help' => __('Format Etichetă:Valoare, de exemplu Scent:Fruity.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'sale_price' => self::row('sale_price', __('Preț redus', 'adc-marketing'), false, 'price', array(
                'help' => __('Mai mic decât prețul plin; se trimite doar când există reducere.', 'adc-marketing'),
                'source' => array('native', 'sale_price'),
            )),
            'sale_price_effective_date' => self::row('sale_price_effective_date', __('Perioada prețului redus', 'adc-marketing'), false, 'text', array(
                'help' => __('Format AAAA-LL-ZZThh:mm+hh:mm/AAAA-LL-ZZThh:mm+hh:mm; fără el reducerea rămâne până la ștergere.', 'adc-marketing'),
            )),
            'vendor_id' => self::row('vendor_id', __('ID furnizor', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Identificatorul furnizorului, opțional.', 'adc-marketing'),
            )),
            'unit_price' => self::row('unit_price', __('Preț pe unitate', 'adc-marketing'), false, 'text', array(
                'help' => __('Obiect cu value, currency și unit, serializat JSON.', 'adc-marketing'),
            )),
            'expiration_date' => self::row('expiration_date', __('Dată expirare', 'adc-marketing'), false, 'text', array(
                'help' => __('Format ISO 8601, AAAA-LL-ZZ.', 'adc-marketing'),
            )),
            'status' => self::row('status', __('Stare', 'adc-marketing'), false, 'enum', array(
                'enum' => array('active', 'archived'),
                'help' => __('Redenumit din visibility; numele vechi încă este acceptat.', 'adc-marketing'),
            )),
            'internal_label' => self::row('internal_label', __('Etichete interne', 'adc-marketing'), false, 'list', array(
                'max_length' => 110,
                'help' => __('Până la 5.000 de etichete per produs, stocate lowercase; potrivire pe valoare întreagă.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'custom_label_0' => self::row('custom_label_0', __('Etichetă custom 0', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Text pentru creative, nu pentru segmentare; segmentarea folosește internal_label.', 'adc-marketing'),
            )),
            'custom_label_1' => self::row('custom_label_1', __('Etichetă custom 1', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Text pentru creative, nu pentru segmentare.', 'adc-marketing'),
            )),
            'custom_label_2' => self::row('custom_label_2', __('Etichetă custom 2', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Text pentru creative, nu pentru segmentare.', 'adc-marketing'),
            )),
            'custom_label_3' => self::row('custom_label_3', __('Etichetă custom 3', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Text pentru creative, nu pentru segmentare.', 'adc-marketing'),
            )),
            'custom_label_4' => self::row('custom_label_4', __('Etichetă custom 4', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Text pentru creative, nu pentru segmentare.', 'adc-marketing'),
            )),
            'custom_number_0' => self::row('custom_number_0', __('Număr custom 0', 'adc-marketing'), false, 'number', array(
                'help' => __('Întreg între 0 și 4.294.967.295, filtrabil pe intervale.', 'adc-marketing'),
            )),
            'custom_number_1' => self::row('custom_number_1', __('Număr custom 1', 'adc-marketing'), false, 'number', array(
                'help' => __('Întreg între 0 și 4.294.967.295, filtrabil pe intervale.', 'adc-marketing'),
            )),
            'custom_number_2' => self::row('custom_number_2', __('Număr custom 2', 'adc-marketing'), false, 'number', array(
                'help' => __('Întreg între 0 și 4.294.967.295, filtrabil pe intervale.', 'adc-marketing'),
            )),
            'custom_number_3' => self::row('custom_number_3', __('Număr custom 3', 'adc-marketing'), false, 'number', array(
                'help' => __('Întreg între 0 și 4.294.967.295, filtrabil pe intervale.', 'adc-marketing'),
            )),
            'custom_number_4' => self::row('custom_number_4', __('Număr custom 4', 'adc-marketing'), false, 'number', array(
                'help' => __('Întreg între 0 și 4.294.967.295, filtrabil pe intervale.', 'adc-marketing'),
            )),
            'product_type' => self::row('product_type', __('Tip produs', 'adc-marketing'), false, 'text', array(
                'max_length' => 750,
                'help' => __('Categoria proprie a magazinului, format A > B > C.', 'adc-marketing'),
                'source' => array('native', 'category_path'),
            )),
            'mobile_link' => self::row('mobile_link', __('Link mobil', 'adc-marketing'), false, 'url', array(
                'help' => __('Versiunea mobilă a paginii de produs.', 'adc-marketing'),
            )),
            'applink' => self::row('applink', __('Applink', 'adc-marketing'), false, 'text', array(
                'help' => __('Sub-atribute applink.*; cerute pentru retargeting și deeplink.', 'adc-marketing'),
            )),
            'shipping' => self::row('shipping', __('Livrare', 'adc-marketing'), false, 'text', array(
                'help' => __('Format Țară:Regiune:Serviciu:Preț; 0.0 afișează overlay-ul de livrare gratuită.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'shipping_weight' => self::row('shipping_weight', __('Greutate livrare', 'adc-marketing'), false, 'number', array(
                'help' => __('Exprimată în lb, oz, g sau kg.', 'adc-marketing'),
                'source' => array('native', 'weight'),
            )),
            'disabled_capabilities' => self::row('disabled_capabilities', __('Capabilități dezactivate', 'adc-marketing'), false, 'list', array(
                'help' => __('Controlează vizibilitatea pe Shops, Marketplace, Instagram Product Tagging și Dynamic Ads.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'quantity_to_sell_on_facebook' => self::row('quantity_to_sell_on_facebook', __('Cantitate pentru Facebook', 'adc-marketing'), false, 'number', array(
                'help' => __('Doar pentru Shops; redenumit din inventory.', 'adc-marketing'),
            )),
            'native_commerce' => self::row('native_commerce', __('Comerț nativ', 'adc-marketing'), false, 'boolean', array(
                'help' => __('Cerut pentru Direct Checkout; acceptă da/nu.', 'adc-marketing'),
            )),
            'product_disclosures' => self::row('product_disclosures', __('Divulgări produs', 'adc-marketing'), false, 'text', array(
                'help' => __('Până la 20 per produs; sub-câmpuri content, title, symbol, jurisdictions.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'origin_country' => self::row('origin_country', __('Țara de origine', 'adc-marketing'), false, 'text', array(
                'help' => __('Cerut doar pentru India, la WhatsApp și Shops.', 'adc-marketing'),
            )),
            'importer_name' => self::row('importer_name', __('Nume importator', 'adc-marketing'), false, 'text', array(
                'help' => __('Cerut doar pentru India.', 'adc-marketing'),
            )),
            'importer_address' => self::row('importer_address', __('Adresă importator', 'adc-marketing'), false, 'text', array(
                'help' => __('Cerut doar pentru India.', 'adc-marketing'),
            )),
            'manufacturer_info' => self::row('manufacturer_info', __('Informații producător', 'adc-marketing'), false, 'text', array(
                'help' => __('Cerut doar pentru India.', 'adc-marketing'),
            )),
            'wa_compliance_category' => self::row('wa_compliance_category', __('Categorie conformitate WhatsApp', 'adc-marketing'), false, 'text', array(
                'help' => __('Cerut doar pentru India, la WhatsApp și Shops.', 'adc-marketing'),
            )),
        );
    }

    /**
     * TikTok Commerce Product Catalog attribute catalogue (spec §3.2).
     *
     * The nine required attributes are exactly those the platform rejects the
     * item for: sku_id, title, description, availability, condition, price, link,
     * image_link and brand. Availability has five space-separated values and
     * category paths are shown on three levels.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function tiktok_fields(): array
    {
        return array(
            'sku_id' => self::row('sku_id', __('SKU', 'adc-marketing'), true, 'text', array(
                'help' => __('Unic în catalog; duplicatele sunt ignorate, iar același ID actualizează produsul.', 'adc-marketing'),
                'source' => array('native', 'sku'),
            )),
            'title' => self::row('title', __('Titlu', 'adc-marketing'), true, 'text', array(
                'help' => __('Fără text promoțional (livrare gratuită, wholesale) și fără emoji.', 'adc-marketing'),
                'source' => array('native', 'title'),
            )),
            'description' => self::row('description', __('Descriere', 'adc-marketing'), true, 'text', array(
                'help' => __('Descrierea produsului; specificația nu publică o limită de lungime.', 'adc-marketing'),
                'source' => array('native', 'description'),
            )),
            'availability' => self::row('availability', __('Disponibilitate', 'adc-marketing'), true, 'enum', array(
                'enum' => array('in stock', 'available for order', 'preorder', 'out of stock', 'discontinued'),
                'help' => __('Cinci valori, cu spații — diferit de Google și Meta.', 'adc-marketing'),
                'source' => array('native', 'stock_status'),
            )),
            'condition' => self::row('condition', __('Condiție', 'adc-marketing'), true, 'enum', array(
                'enum' => array('new', 'refurbished', 'used'),
                'help' => __('Obligatorie pentru fiecare produs.', 'adc-marketing'),
                'source' => array('native', 'condition'),
            )),
            'price' => self::row('price', __('Preț', 'adc-marketing'), true, 'price', array(
                'help' => __('Monedă după țara țintă a catalogului, punct decimal.', 'adc-marketing'),
                'source' => array('native', 'regular_price'),
            )),
            'link' => self::row('link', __('Link produs', 'adc-marketing'), true, 'url', array(
                'help' => __('Pagină de destinație activă.', 'adc-marketing'),
                'source' => array('native', 'permalink'),
            )),
            'image_link' => self::row('image_link', __('Imagine principală', 'adc-marketing'), true, 'url', array(
                'help' => __('Minimum 500×500 px pentru formatul 1:1, link JPG sau PNG.', 'adc-marketing'),
                'source' => array('native', 'image'),
            )),
            'brand' => self::row('brand', __('Brand', 'adc-marketing'), true, 'text', array(
                'help' => __('Valoare reală a brandului; obligatoriu pentru fiecare produs.', 'adc-marketing'),
                'source' => array('native', 'brand'),
            )),
            'google_product_category' => self::row('google_product_category', __('Categorie Google', 'adc-marketing'), false, 'text', array(
                'help' => __('Doar primele 3 niveluri sunt afișate.', 'adc-marketing'),
                'source' => array('category_map', 'google_product_category'),
            )),
            'product_type' => self::row('product_type', __('Tip produs', 'adc-marketing'), false, 'text', array(
                'help' => __('Doar primele 3 niveluri sunt indicate.', 'adc-marketing'),
                'source' => array('native', 'category_path'),
            )),
            'video_link' => self::row('video_link', __('Link video', 'adc-marketing'), false, 'url', array(
                'help' => __('Recomandat 9:16, minimum 720×1280, cu sunet și subtitrări.', 'adc-marketing'),
            )),
            'additional_image_link' => self::row('additional_image_link', __('Imagini secundare', 'adc-marketing'), false, 'list', array(
                'help' => __('Până la 10 URL-uri JPG sau PNG, minimum 500×500 px pentru carusel.', 'adc-marketing'),
                'source' => array('native', 'gallery'),
                'repeatable' => true,
            )),
            'age_group' => self::row('age_group', __('Grupă de vârstă', 'adc-marketing'), false, 'enum', array(
                'enum' => array('newborn', 'infant', 'toddler', 'kids', 'adult'),
                'help' => __('Cu intervalele de vârstă explicate în descriere.', 'adc-marketing'),
            )),
            'color' => self::row('color', __('Culoare', 'adc-marketing'), false, 'text', array(
                'help' => __('Culoarea produsului, ca text.', 'adc-marketing'),
            )),
            'gender' => self::row('gender', __('Gen', 'adc-marketing'), false, 'enum', array(
                'enum' => array('male', 'female', 'unisex'),
                'help' => __('Genul căruia i se adresează produsul.', 'adc-marketing'),
            )),
            'item_group_id' => self::row('item_group_id', __('ID grup variante', 'adc-marketing'), false, 'text', array(
                'help' => __('Același ID pentru toate variantele produsului.', 'adc-marketing'),
            )),
            'material' => self::row('material', __('Material', 'adc-marketing'), false, 'text', array(
                'help' => __('Materialul produsului, ca text.', 'adc-marketing'),
            )),
            'pattern' => self::row('pattern', __('Model', 'adc-marketing'), false, 'text', array(
                'help' => __('Modelul produsului, ca text.', 'adc-marketing'),
            )),
            'size' => self::row('size', __('Mărime', 'adc-marketing'), false, 'text', array(
                'help' => __('Mărimea produsului, ca text.', 'adc-marketing'),
            )),
            'sale_price' => self::row('sale_price', __('Preț redus', 'adc-marketing'), false, 'price', array(
                'help' => __('Necesar pentru overlay-ul de preț redus, punct decimal.', 'adc-marketing'),
                'source' => array('native', 'sale_price'),
            )),
            'sale_price_effective_date' => self::row('sale_price_effective_date', __('Perioada prețului redus', 'adc-marketing'), false, 'text', array(
                'help' => __('Format AAAA-LL-ZZThh:mm/AAAA-LL-ZZThh:mm; TikTok încă nu îl folosește pentru expirare.', 'adc-marketing'),
            )),
            'shipping' => self::row('shipping', __('Livrare', 'adc-marketing'), false, 'text', array(
                'help' => __('Format Țară:Stat:TipLivrare:Preț:Serviciu, regiuni separate prin punct și virgulă.', 'adc-marketing'),
                'repeatable' => true,
            )),
            'shipping_weight' => self::row('shipping_weight', __('Greutate livrare', 'adc-marketing'), false, 'text', array(
                'help' => __('Valoare plus unitate, de exemplu 0.3 lb.', 'adc-marketing'),
                'source' => array('native', 'weight'),
            )),
            'gtin' => self::row('gtin', __('GTIN', 'adc-marketing'), false, 'text', array(
                'help' => __('UPC, EAN, JAN, ISBN-13 sau ITF-14.', 'adc-marketing'),
            )),
            'mpn' => self::row('mpn', __('MPN', 'adc-marketing'), false, 'text', array(
                'help' => __('Codul producătorului.', 'adc-marketing'),
            )),
            'tax' => self::row('tax', __('Taxă', 'adc-marketing'), false, 'number', array(
                'help' => __('Cotă numerică, de exemplu 0.05.', 'adc-marketing'),
            )),
            'ios_url' => self::row('ios_url', __('Link aplicație iOS', 'adc-marketing'), false, 'url', array(
                'help' => __('Schemă de app; necesară pentru retargeting și deeplink.', 'adc-marketing'),
            )),
            'android_url' => self::row('android_url', __('Link aplicație Android', 'adc-marketing'), false, 'url', array(
                'help' => __('Schemă de app; necesară pentru retargeting și deeplink.', 'adc-marketing'),
            )),
            'ios_app_store_id' => self::row('ios_app_store_id', __('ID App Store iOS', 'adc-marketing'), false, 'text', array(
                'help' => __('Identificatorul aplicației în App Store.', 'adc-marketing'),
            )),
            'ios_app_store_name' => self::row('ios_app_store_name', __('Nume App Store iOS', 'adc-marketing'), false, 'text', array(
                'help' => __('Numele aplicației în App Store.', 'adc-marketing'),
            )),
            'android_package' => self::row('android_package', __('Pachet Android', 'adc-marketing'), false, 'text', array(
                'help' => __('Numele pachetului aplicației Android.', 'adc-marketing'),
            )),
            'android_app_name' => self::row('android_app_name', __('Nume aplicație Android', 'adc-marketing'), false, 'text', array(
                'help' => __('Numele aplicației Android.', 'adc-marketing'),
            )),
            'merchant_brand' => self::row('merchant_brand', __('Brand magazin', 'adc-marketing'), false, 'text', array(
                'help' => __('Brandul magazinului, de exemplu numele afișat al acestuia.', 'adc-marketing'),
            )),
            'product_his_eval' => self::row('product_his_eval', __('Istoric evaluări', 'adc-marketing'), false, 'text', array(
                'help' => __('Câmpul productHisEval: număr de achiziții : număr de evaluări bune.', 'adc-marketing'),
            )),
            'custom_label_0' => self::row('custom_label_0', __('Etichetă custom 0', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Folosită la filtrele din product sets; numărul maxim de etichete per produs este neverificat.', 'adc-marketing'),
            )),
            'custom_label_1' => self::row('custom_label_1', __('Etichetă custom 1', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Folosită la filtrele din product sets; numărul maxim de etichete per produs este neverificat.', 'adc-marketing'),
            )),
            'custom_label_2' => self::row('custom_label_2', __('Etichetă custom 2', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Folosită la filtrele din product sets; numărul maxim de etichete per produs este neverificat.', 'adc-marketing'),
            )),
            'custom_label_3' => self::row('custom_label_3', __('Etichetă custom 3', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Folosită la filtrele din product sets; numărul maxim de etichete per produs este neverificat.', 'adc-marketing'),
            )),
            'custom_label_4' => self::row('custom_label_4', __('Etichetă custom 4', 'adc-marketing'), false, 'text', array(
                'max_length' => 100,
                'help' => __('Folosită la filtrele din product sets; numărul maxim de etichete per produs este neverificat.', 'adc-marketing'),
            )),
        );
    }
}
