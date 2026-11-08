<?php
/**
 * Meta Commerce Catalog feed item validator.
 *
 * Every rule and limit below comes from docs/research/feeds-platform-specs.md §2
 * (Meta Commerce Catalog, verified 2026-11-07): §2.2 attributes, §2.3 labels and
 * §2.4 renames. Rules the research could not verify, and heuristics that cannot
 * be proven from a single row (image format by file extension, domain ownership),
 * are reported as warnings. Meta is not a Google alias: availability uses the
 * two US English values, segmentation uses internal_label, and unique values are
 * lowercase at storage.
 *
 * The class is pure: it reads one normalized catalog row (no WordPress queries,
 * no HTTP, no filesystem) and returns findings, so it can run inside generation
 * pipelines and in tests outside a WordPress bootstrap.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Validates one normalized feed row against the Meta Commerce Catalog profile. */
final class ADC_Feed_Validator_Meta
{
    /** Finding levels. */
    public const LEVEL_ERROR   = 'error';
    public const LEVEL_WARNING = 'warning';

    /** id max 100 characters (spec §2.2). */
    public const ID_MAX_LENGTH = 100;

    /** title max 200 characters (spec §2.2). */
    public const TITLE_MAX_LENGTH = 200;

    /** Ads recommend a title of at most 65 characters (spec §2.2). */
    public const TITLE_RECOMMENDED_LENGTH = 65;

    /** description max 9999 characters (spec §2.2). */
    public const DESCRIPTION_MAX_LENGTH = 9999;

    /** brand max 100 characters (spec §2.2). */
    public const BRAND_MAX_LENGTH = 100;

    /** mpn max 100 characters (spec §2.2). */
    public const MPN_MAX_LENGTH = 100;

    /** item_group_id max 100 characters (spec §2.2). */
    public const ITEM_GROUP_ID_MAX_LENGTH = 100;

    /** product_type max 750 characters (spec §2.2). */
    public const PRODUCT_TYPE_MAX_LENGTH = 750;

    /** color max 200 characters (spec §2.2). */
    public const COLOR_MAX_LENGTH = 200;

    /** size max 200 characters (spec §2.2). */
    public const SIZE_MAX_LENGTH = 200;

    /** material max 200 characters (spec §2.2). */
    public const MATERIAL_MAX_LENGTH = 200;

    /** pattern max 100 characters (spec §2.2). */
    public const PATTERN_MAX_LENGTH = 100;

    /** internal_label: 110 characters per label (spec §2.2, §2.3). */
    public const INTERNAL_LABEL_MAX_LENGTH = 110;

    /** internal_label: up to 5.000 labels per product (spec §2.2, §2.3). */
    public const INTERNAL_LABELS_MAX = 5000;

    /** custom_label_[0-4] max 100 characters (spec §2.2). */
    public const CUSTOM_LABEL_MAX_LENGTH = 100;

    /** Five custom label slots (spec §2.2). */
    public const CUSTOM_LABEL_SLOTS = 5;

    /** Up to 50 additional_image_link values (spec §2.2). */
    public const ADDITIONAL_IMAGES_MAX = 50;

    /** additional_image_link max 2000 characters (spec §2.2). */
    public const ADDITIONAL_IMAGE_URL_MAX_LENGTH = 2000;

    /** image_link minimum side in pixels (spec §2.2). */
    public const IMAGE_MIN_SIDE = 500;

    /** Meta accepts exactly two availability values, in US English (spec §2.2, §0 D7). */
    public const AVAILABILITY_VALUES = array('in stock', 'out of stock');

    /** Google-style tokens that still need a mapping step before serialization. */
    public const AVAILABILITY_MAPPABLE = array('in_stock', 'out_of_stock');

    /** Accepted condition values (spec §2.2). */
    public const CONDITION_VALUES = array('new', 'refurbished', 'used');

    /** Accepted age_group values (spec §2.2). */
    public const AGE_GROUP_VALUES = array('adult', 'all ages', 'teen', 'kids', 'toddler', 'infant', 'newborn');

    /** Accepted gender values (spec §2.2). */
    public const GENDER_VALUES = array('female', 'male', 'unisex');

    /** Accepted status values (spec §2.2). */
    public const STATUS_VALUES = array('active', 'archived');

    /** Documented GTIN families: UPC-12, EAN-13/JAN/ISBN-13, ITF-14 (spec §2.2). */
    public const GTIN_LENGTHS = array(12, 13, 14);

    /**
     * Validate one normalized catalog row.
     *
     * The row keys follow the normalized catalog contract shared by all channels:
     * feed_item_id, item_group_id, title, description, link, image_link,
     * additional_image_links[], availability, price, sale_price, currency,
     * condition, brand, gtin, mpn, google_product_category, product_type, weight,
     * attributes, shipping[], custom_labels[].
     *
     * Known optional extensions used when present: fb_product_category,
     * sale_start, sale_end, sale_price_effective_date, image_width, image_height,
     * status.
     *
     * @param array<string,mixed> $item   Normalized catalog row.
     * @param array<string,mixed> $config Optional: smart_labels (Meta internal_label
     *                                    list), site_host, currency fallback.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    public static function validate(array $item, array $config = array()): array
    {
        return array_merge(
            self::validate_identity($item),
            self::validate_title($item),
            self::validate_description($item),
            self::validate_links($item, $config),
            self::validate_images($item),
            self::validate_price($item, $config),
            self::validate_availability($item),
            self::validate_condition($item),
            self::validate_identifiers($item),
            self::validate_variants($item),
            self::validate_labels($item, $config),
            self::validate_shipping($item),
            self::validate_weight($item),
            self::validate_status($item)
        );
    }

    /**
     * Count findings per level.
     *
     * @param array<int,array{level?:string}> $findings Findings from self::validate().
     * @return array{errors:int,warnings:int}
     */
    public static function summarize(array $findings): array
    {
        $summary = array(
            'errors'   => 0,
            'warnings' => 0,
        );

        foreach ($findings as $finding) {
            $level = is_array($finding) ? (string) ($finding['level'] ?? '') : '';
            if ($level === self::LEVEL_ERROR) {
                ++$summary['errors'];
            } elseif ($level === self::LEVEL_WARNING) {
                ++$summary['warnings'];
            }
        }

        return $summary;
    }

    /**
     * id: required, max 100 characters, must equal the Meta Pixel content_id.
     *
     * The content_id equality cannot be checked from one row (the pixel payload is
     * not available here); only presence and length are enforced.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_identity(array $item): array
    {
        $id = self::text($item['feed_item_id'] ?? null);

        if ($id === '') {
            return array(self::error(
                'feed_item_id',
                'id_missing',
                __('Lipsește ID-ul produsului (id), cerut de Meta pentru fiecare articol.', 'adc-marketing')
            ));
        }

        if (self::length($id) > self::ID_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            return array(self::error(
                'feed_item_id',
                'id_too_long',
                sprintf(
                    __('ID-ul produsului are %1$d caractere; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($id),
                    self::ID_MAX_LENGTH
                )
            ));
        }

        return array();
    }

    /**
     * title: required, max 200 characters, 65 is the recommended ads length (spec §2.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_title(array $item): array
    {
        $findings = array();
        $title    = self::text($item['title'] ?? null);

        if ($title === '') {
            return array(self::error(
                'title',
                'title_missing',
                __('Lipsește titlul produsului (title), cerut de Meta.', 'adc-marketing')
            ));
        }

        if (self::length($title) > self::TITLE_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'title',
                'title_too_long',
                sprintf(
                    __('Titlul are %1$d caractere; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($title),
                    self::TITLE_MAX_LENGTH
                )
            );
        }

        if (self::length($title) > self::TITLE_RECOMMENDED_LENGTH) {
            /* translators: 1: current length, 2: recommended length. */
            $findings[] = self::warning(
                'title',
                'title_above_recommended',
                sprintf(
                    __('Titlul are %1$d caractere; Meta recomandă maximum %2$d pentru anunțuri.', 'adc-marketing'),
                    self::length($title),
                    self::TITLE_RECOMMENDED_LENGTH
                )
            );
        }

        return $findings;
    }

    /**
     * description: required, max 9999 characters, plain text without links or ALL CAPS (spec §2.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_description(array $item): array
    {
        $findings    = array();
        $description = self::text($item['description'] ?? null);

        if ($description === '') {
            return array(self::error(
                'description',
                'description_missing',
                __('Lipsește descrierea produsului (description), cerută de Meta.', 'adc-marketing')
            ));
        }

        if (self::length($description) > self::DESCRIPTION_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'description',
                'description_too_long',
                sprintf(
                    __('Descrierea are %1$d caractere; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($description),
                    self::DESCRIPTION_MAX_LENGTH
                )
            );
        }

        if (preg_match('/<[^>]+>/', $description) === 1) {
            $findings[] = self::warning(
                'description',
                'description_contains_html',
                __('Descrierea conține HTML; Meta cere text simplu.', 'adc-marketing')
            );
        }

        if (preg_match('~https?://|www\.~i', $description) === 1) {
            $findings[] = self::warning(
                'description',
                'description_contains_links',
                __('Descrierea conține linkuri; Meta cere descriere fără linkuri.', 'adc-marketing')
            );
        }

        if (self::is_all_caps($description)) {
            $findings[] = self::warning(
                'description',
                'description_all_caps',
                __('Descrierea este scrisă integral cu majuscule; Meta cere text normal.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * link: required http(s) URL on the shop domain (spec §2.2).
     *
     * @param array<string,mixed> $item   Normalized row.
     * @param array<string,mixed> $config Validator configuration.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_links(array $item, array $config): array
    {
        $link = self::text($item['link'] ?? null);

        if ($link === '') {
            return array(self::error(
                'link',
                'link_missing',
                __('Lipsește linkul către pagina produsului (link), cerut de Meta.', 'adc-marketing')
            ));
        }

        if (!self::is_http_url($link)) {
            return array(self::error(
                'link',
                'link_invalid',
                __('Linkul produsului trebuie să fie un URL absolut cu schemă http sau https.', 'adc-marketing')
            ));
        }

        $site_host = strtolower(self::text($config['site_host'] ?? ''));
        $host      = strtolower((string) parse_url($link, PHP_URL_HOST));
        if ($site_host !== '' && $host !== '' && $host !== $site_host) {
            /* translators: 1: link host, 2: expected shop host. */
            return array(self::warning(
                'link',
                'link_domain_mismatch',
                sprintf(
                    __('Linkul produsului folosește domeniul %1$s, iar Meta așteaptă domeniul magazinului (%2$s).', 'adc-marketing'),
                    $host,
                    $site_host
                )
            ));
        }

        return array();
    }

    /**
     * image_link: required JPEG/PNG, at least 500×500, max 8 MB (spec §2.2).
     *
     * File format and byte size cannot be proven from a URL, so only the extension
     * is inspected and only as a warning. additional_image_link is marked Required
     * in the official table, with up to 50 URLs of max 2000 characters.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_images(array $item): array
    {
        $findings   = array();
        $image_link = self::text($item['image_link'] ?? null);

        if ($image_link === '') {
            $findings[] = self::error(
                'image_link',
                'image_link_missing',
                __('Lipsește imaginea principală (image_link), cerută de Meta.', 'adc-marketing')
            );
        } elseif (!self::is_http_url($image_link)) {
            $findings[] = self::error(
                'image_link',
                'image_link_invalid',
                __('Imaginea principală trebuie să fie un URL absolut cu schemă http sau https.', 'adc-marketing')
            );
        } else {
            if (!self::has_image_extension($image_link)) {
                $findings[] = self::warning(
                    'image_link',
                    'image_link_format',
                    __('URL-ul imaginii nu se termină în .jpg, .jpeg sau .png; Meta cere format JPEG sau PNG.', 'adc-marketing')
                );
            }

            $width  = self::positive_int($item['image_width'] ?? null);
            $height = self::positive_int($item['image_height'] ?? null);
            if ($width > 0 && $height > 0 && min($width, $height) < self::IMAGE_MIN_SIDE) {
                /* translators: 1: image width, 2: image height, 3: minimum allowed side. */
                $findings[] = self::error(
                    'image_link',
                    'image_too_small',
                    sprintf(
                        __('Imaginea are %1$d×%2$d px; Meta cere minimum %3$d×%3$d px.', 'adc-marketing'),
                        $width,
                        $height,
                        self::IMAGE_MIN_SIDE
                    )
                );
            }
        }

        $additional = $item['additional_image_links'] ?? array();
        if (!is_array($additional)) {
            $findings[] = self::error(
                'additional_image_links',
                'additional_images_invalid',
                __('Imaginile secundare trebuie trimise ca listă de URL-uri.', 'adc-marketing')
            );

            return $findings;
        }

        $values = array_values(array_filter(
            $additional,
            static fn(mixed $value): bool => self::text($value) !== ''
        ));

        if ($values === array()) {
            $findings[] = self::warning(
                'additional_image_links',
                'additional_images_missing',
                __('Meta marchează additional_image_link ca obligatoriu; adaugă cel puțin o imagine secundară.', 'adc-marketing')
            );

            return $findings;
        }

        if (count($values) > self::ADDITIONAL_IMAGES_MAX) {
            /* translators: 1: number of submitted images, 2: maximum allowed number. */
            $findings[] = self::error(
                'additional_image_links',
                'additional_images_too_many',
                sprintf(
                    __('Sunt %1$d imagini secundare; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    count($values),
                    self::ADDITIONAL_IMAGES_MAX
                )
            );
        }

        $index = 0;
        foreach ($values as $value) {
            ++$index;
            $url = self::text($value);

            if (!self::is_http_url($url)) {
                /* translators: %d: position of the image in the additional images list. */
                $findings[] = self::error(
                    'additional_image_links',
                    'additional_image_invalid',
                    sprintf(
                        __('Imaginea secundară %d nu este un URL absolut cu schemă http sau https.', 'adc-marketing'),
                        $index
                    )
                );
                continue;
            }

            if (self::length($url) > self::ADDITIONAL_IMAGE_URL_MAX_LENGTH) {
                /* translators: 1: position of the image, 2: current length, 3: maximum allowed length. */
                $findings[] = self::error(
                    'additional_image_links',
                    'additional_image_url_too_long',
                    sprintf(
                        __('URL-ul imaginii secundare %1$d are %2$d caractere; Meta acceptă maximum %3$d.', 'adc-marketing'),
                        $index,
                        self::length($url),
                        self::ADDITIONAL_IMAGE_URL_MAX_LENGTH
                    )
                );
            }
        }

        return $findings;
    }

    /**
     * price: required number + ISO 4217 currency, point decimal, no symbol (spec §2.2).
     *
     * @param array<string,mixed> $item   Normalized row.
     * @param array<string,mixed> $config Validator configuration.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_price(array $item, array $config): array
    {
        $findings  = array();
        $price     = $item['price'] ?? null;
        $has_price = is_numeric($price) && (float) $price > 0;

        if ($price === null || $price === '') {
            $findings[] = self::error(
                'price',
                'price_missing',
                __('Lipsește prețul (price), cerut de Meta în formatul „număr ISO 4217”.', 'adc-marketing')
            );
        } elseif (!is_numeric($price)) {
            $findings[] = self::error(
                'price',
                'price_format',
                __('Prețul trebuie să fie un număr cu punct decimal, fără virgulă și fără simbol de monedă.', 'adc-marketing')
            );
        } elseif (!$has_price) {
            $findings[] = self::error(
                'price',
                'price_invalid',
                __('Prețul trebuie să fie un număr pozitiv.', 'adc-marketing')
            );
        }

        $currency = strtoupper(self::text($item['currency'] ?? $config['currency'] ?? ''));
        if ($currency === '') {
            $findings[] = self::error(
                'currency',
                'currency_missing',
                __('Lipsește moneda; Meta o așteaptă lângă preț, ca cod ISO 4217.', 'adc-marketing')
            );
        } elseif (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $findings[] = self::error(
                'currency',
                'currency_invalid',
                __('Moneda trebuie să fie un cod ISO 4217 din trei litere mari (de exemplu RON).', 'adc-marketing')
            );
        }

        $sale = $item['sale_price'] ?? null;
        if ($sale !== null && $sale !== '') {
            if (!is_numeric($sale) || (float) $sale <= 0) {
                $findings[] = self::error(
                    'sale_price',
                    'sale_price_invalid',
                    __('Prețul redus (sale_price) trebuie să fie un număr pozitiv.', 'adc-marketing')
                );
            } elseif ($has_price) {
                $sale_value  = (float) $sale;
                $price_value = (float) $price;

                if ($sale_value > $price_value) {
                    /* translators: 1: sale price, 2: list price. */
                    $findings[] = self::error(
                        'sale_price',
                        'sale_price_not_lower',
                        sprintf(
                            __('Prețul redus (%1$s) este mai mare decât prețul plin (%2$s); Meta cere un preț redus mai mic.', 'adc-marketing'),
                            self::amount($sale_value),
                            self::amount($price_value)
                        )
                    );
                } elseif (abs($sale_value - $price_value) < 0.00001) {
                    $findings[] = self::warning(
                        'sale_price',
                        'sale_price_equals_price',
                        __('Prețul redus este egal cu prețul plin; trimite sale_price doar la o reducere reală.', 'adc-marketing')
                    );
                }
            }

            $effective = self::text($item['sale_price_effective_date'] ?? '');
            if ($effective === '') {
                $start = self::text($item['sale_start'] ?? '');
                $end   = self::text($item['sale_end'] ?? '');
                $effective = ($start !== '' && $end !== '') ? $start . '/' . $end : '';
            }

            if ($effective === '') {
                $findings[] = self::warning(
                    'sale_price_effective_date',
                    'sale_price_effective_date_missing',
                    __('Fără sale_price_effective_date, Meta ține reducerea până la ștergere; trimite perioada de valabilitate.', 'adc-marketing')
                );
            } elseif (!self::is_effective_period($effective)) {
                $findings[] = self::warning(
                    'sale_price_effective_date',
                    'sale_price_effective_date_invalid',
                    __('sale_price_effective_date trebuie să aibă formatul AAAA-LL-ZZThh:mm+hh:mm/AAAA-LL-ZZThh:mm+hh:mm.', 'adc-marketing')
                );
            }
        }

        return $findings;
    }

    /**
     * availability: required, exactly "in stock" or "out of stock" in US English (spec §2.2, §0 D7).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_availability(array $item): array
    {
        $availability = self::text($item['availability'] ?? null);

        if ($availability === '') {
            return array(self::error(
                'availability',
                'availability_missing',
                __('Lipsește disponibilitatea (availability), cerută de Meta.', 'adc-marketing')
            ));
        }

        if (in_array($availability, self::AVAILABILITY_VALUES, true)) {
            return array();
        }

        if (in_array($availability, self::AVAILABILITY_MAPPABLE, true)) {
            /* translators: %s: current availability value. */
            return array(self::warning(
                'availability',
                'availability_needs_mapping',
                sprintf(
                    __('Disponibilitatea „%s” trebuie scrisă în engleza americană cerută de Meta: „in stock” sau „out of stock”.', 'adc-marketing'),
                    $availability
                )
            ));
        }

        /* translators: %s: current availability value. */
        return array(self::error(
            'availability',
            'availability_invalid',
            sprintf(
                __('Disponibilitatea „%s” nu poate fi reprezentată la Meta; sunt acceptate doar „in stock” și „out of stock”.', 'adc-marketing'),
                $availability
            )
        ));
    }

    /**
     * condition: required, new/refurbished/used (spec §2.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_condition(array $item): array
    {
        $condition = self::text($item['condition'] ?? null);

        if ($condition === '') {
            return array(self::error(
                'condition',
                'condition_missing',
                __('Lipsește condiția produsului (condition), cerută de Meta.', 'adc-marketing')
            ));
        }

        if (!in_array($condition, self::CONDITION_VALUES, true)) {
            /* translators: %s: submitted condition value. */
            return array(self::error(
                'condition',
                'condition_invalid',
                sprintf(
                    __('Condiția „%s” nu este acceptată; Meta acceptă new, refurbished sau used.', 'adc-marketing'),
                    $condition
                )
            ));
        }

        return array();
    }

    /**
     * brand/gtin/mpn, one of brand or category, and identifier formats (spec §2.2).
     *
     * brand is required only when gtin and mpn are both missing: the footnote requires at
     * least one of brand/gtin/mpn. google_product_category or fb_product_category is
     * mandatory; this store supplies the Google taxonomy id.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_identifiers(array $item): array
    {
        $findings = array();
        $brand    = self::text($item['brand'] ?? null);
        $gtin     = self::text($item['gtin'] ?? null);
        $mpn      = self::text($item['mpn'] ?? null);

        if ($brand === '' && $gtin === '' && $mpn === '') {
            $findings[] = self::error(
                'brand',
                'identifier_missing',
                __('Produsul nu are nici brand, nici GTIN, nici MPN; Meta cere cel puțin unul dintre ele.', 'adc-marketing')
            );
        }

        if ($brand !== '' && self::length($brand) > self::BRAND_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'brand',
                'brand_too_long',
                sprintf(
                    __('Brandul are %1$d caractere; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($brand),
                    self::BRAND_MAX_LENGTH
                )
            );
        }

        if ($mpn !== '' && self::length($mpn) > self::MPN_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'mpn',
                'mpn_too_long',
                sprintf(
                    __('MPN-ul are %1$d caractere; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($mpn),
                    self::MPN_MAX_LENGTH
                )
            );
        }

        if ($gtin !== '') {
            $findings = array_merge($findings, self::validate_gtin($gtin));
        }

        $category    = self::text($item['google_product_category'] ?? null);
        $fb_category = self::text($item['fb_product_category'] ?? null);
        if ($category === '' && $fb_category === '') {
            $findings[] = self::warning(
                'google_product_category',
                'category_missing',
                __('Lipsește categoria: Meta cere google_product_category sau fb_product_category.', 'adc-marketing')
            );
        }

        $product_type = self::text($item['product_type'] ?? null);
        if ($product_type !== '' && self::length($product_type) > self::PRODUCT_TYPE_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'product_type',
                'product_type_too_long',
                sprintf(
                    __('Câmpul product_type are %1$d caractere; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($product_type),
                    self::PRODUCT_TYPE_MAX_LENGTH
                )
            );
        }

        return $findings;
    }

    /**
     * GTIN families without spaces or hyphens (spec §2.2).
     *
     * Meta documents the accepted families but not a check digit requirement, so a
     * malformed value is a warning: the item still publishes, matching degrades.
     *
     * @param string $gtin Raw gtin attribute.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_gtin(string $gtin): array
    {
        if (preg_match('/^[0-9]+$/', $gtin) !== 1) {
            return array(self::warning(
                'gtin',
                'gtin_format',
                __('GTIN-ul trebuie să conțină doar cifre, fără spații sau cratime.', 'adc-marketing')
            ));
        }

        if (!in_array(strlen($gtin), self::GTIN_LENGTHS, true)) {
            /* translators: %d: current digit count. */
            return array(self::warning(
                'gtin',
                'gtin_length',
                sprintf(
                    __('GTIN-ul are %d cifre; Meta documentează UPC-12, EAN-13, JAN, ISBN-13 și ITF-14.', 'adc-marketing'),
                    strlen($gtin)
                )
            ));
        }

        return array();
    }

    /**
     * Variant attributes: item_group_id, color, size, material, pattern, age_group, gender (spec §2.2).
     *
     * Missing values are not errors because applicability depends on the product
     * category, but present values must fit the documented length or enum.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_variants(array $item): array
    {
        $findings = array();
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : array();

        $group = self::text($item['item_group_id'] ?? null);
        if ($group !== '' && self::length($group) > self::ITEM_GROUP_ID_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'item_group_id',
                'item_group_id_too_long',
                sprintf(
                    __('item_group_id are %1$d caractere; Meta acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($group),
                    self::ITEM_GROUP_ID_MAX_LENGTH
                )
            );
        }

        $limits = array(
            'color'    => array(self::COLOR_MAX_LENGTH, 'Color'),
            'size'     => array(self::SIZE_MAX_LENGTH, 'Size'),
            'material' => array(self::MATERIAL_MAX_LENGTH, 'Material'),
            'pattern'  => array(self::PATTERN_MAX_LENGTH, 'Pattern'),
        );
        foreach ($limits as $key => $limit) {
            $value = self::text($attributes[$key] ?? null);
            if ($value === '' || self::length($value) <= $limit[0]) {
                continue;
            }

            /* translators: 1: attribute label, 2: current length, 3: maximum allowed length. */
            $findings[] = self::error(
                'attributes.' . $key,
                $key . '_too_long',
                sprintf(
                    __('%1$s are %2$d caractere; Meta acceptă maximum %3$d.', 'adc-marketing'),
                    $limit[1],
                    self::length($value),
                    $limit[0]
                )
            );
        }

        $age_group = strtolower(self::text($attributes['age_group'] ?? null));
        if ($age_group !== '' && !in_array($age_group, self::AGE_GROUP_VALUES, true)) {
            /* translators: %s: submitted age group value. */
            $findings[] = self::error(
                'attributes.age_group',
                'age_group_invalid',
                sprintf(
                    __('age_group „%s” nu este acceptat; Meta acceptă adult, all ages, teen, kids, toddler, infant sau newborn.', 'adc-marketing'),
                    $age_group
                )
            );
        }

        $gender = strtolower(self::text($attributes['gender'] ?? null));
        if ($gender !== '' && !in_array($gender, self::GENDER_VALUES, true)) {
            /* translators: %s: submitted gender value. */
            $findings[] = self::error(
                'attributes.gender',
                'gender_invalid',
                sprintf(
                    __('gender „%s” nu este acceptat; Meta acceptă female, male sau unisex.', 'adc-marketing'),
                    $gender
                )
            );
        }

        return $findings;
    }

    /**
     * internal_label and custom_label_[0-4] (spec §2.2, §2.3).
     *
     * Meta has one internal label list per product (no numbered slots), up to 5.000
     * labels of 110 characters each, stored lowercase. The Smart Feed rules produce
     * this list, so it is passed through config.smart_labels. Labels are matched on
     * their whole value, which is why the recommended separators are #, _, : and -.
     * custom_label_[0-4] stay creative text, not segmentation.
     *
     * @param array<string,mixed> $item   Normalized row.
     * @param array<string,mixed> $config Validator configuration.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_labels(array $item, array $config): array
    {
        $findings = array();
        $internal = $config['smart_labels'] ?? array();

        if (!is_array($internal)) {
            $findings[] = self::error(
                'internal_label',
                'internal_labels_invalid',
                __('Etichetele interne (internal_label) trebuie trimise ca listă de valori text.', 'adc-marketing')
            );
        } else {
            if (count($internal) > self::INTERNAL_LABELS_MAX) {
                /* translators: 1: number of labels, 2: maximum allowed labels. */
                $findings[] = self::error(
                    'internal_label',
                    'internal_labels_too_many',
                    sprintf(
                        __('Sunt %1$d etichete interne; Meta acceptă maximum %2$d per produs.', 'adc-marketing'),
                        count($internal),
                        self::INTERNAL_LABELS_MAX
                    )
                );
            }

            foreach ($internal as $label) {
                if (!is_string($label) && !is_int($label) && !is_float($label)) {
                    $findings[] = self::error(
                        'internal_label',
                        'internal_label_invalid',
                        __('O etichetă internă nu este o valoare text.', 'adc-marketing')
                    );
                    continue;
                }

                $value = self::text((string) $label);
                if ($value === '') {
                    continue;
                }

                if (self::length($value) > self::INTERNAL_LABEL_MAX_LENGTH) {
                    /* translators: 1: current length, 2: maximum allowed length. */
                    $findings[] = self::error(
                        'internal_label',
                        'internal_label_too_long',
                        sprintf(
                            __('Eticheta internă „%1$s…” are %2$d caractere; Meta acceptă maximum %3$d.', 'adc-marketing'),
                            self::shorten($value),
                            self::length($value),
                            self::INTERNAL_LABEL_MAX_LENGTH
                        )
                    );
                }

                if ($value !== self::lower($value)) {
                    $findings[] = self::warning(
                        'internal_label',
                        'internal_label_not_lowercase',
                        __('Eticheta internă nu este scrisă cu litere mici; Meta o stochează astfel, deci potrivirea în seturile de produse folosește varianta cu litere mici.', 'adc-marketing')
                    );
                }

                if (preg_match('/\s/', $value) === 1) {
                    $findings[] = self::warning(
                        'internal_label',
                        'internal_label_separator',
                        __('Eticheta internă conține spații; Meta recomandă separatorii #, _, : sau -.', 'adc-marketing')
                    );
                }
            }
        }

        $custom = $item['custom_labels'] ?? array();
        if (!is_array($custom)) {
            $findings[] = self::error(
                'custom_labels',
                'custom_labels_invalid',
                __('Etichetele custom trebuie trimise ca listă de valori text.', 'adc-marketing')
            );

            return $findings;
        }

        $values = array();
        foreach ($custom as $key => $value) {
            if (is_array($value) || self::text($value) !== '') {
                $values[$key] = $value;
            }
        }

        if (count($values) > self::CUSTOM_LABEL_SLOTS) {
            /* translators: 1: number of labels, 2: maximum allowed slots. */
            $findings[] = self::error(
                'custom_labels',
                'custom_labels_too_many',
                sprintf(
                    __('Sunt %1$d etichete custom; Meta acceptă maximum %2$d sloturi (custom_label_0 … custom_label_4).', 'adc-marketing'),
                    count($values),
                    self::CUSTOM_LABEL_SLOTS
                )
            );
        }

        $position = 0;
        foreach ($values as $key => $label) {
            $slot = is_int($key) ? $key : $position;
            ++$position;

            if (!is_string($label) && !is_int($label) && !is_float($label)) {
                /* translators: %d: custom label slot number. */
                $findings[] = self::error(
                    'custom_labels',
                    'custom_label_invalid',
                    sprintf(
                        __('Eticheta custom_label_%d nu este o valoare text.', 'adc-marketing'),
                        $slot
                    )
                );
                continue;
            }

            $value = self::text((string) $label);
            if ($value !== '' && self::length($value) > self::CUSTOM_LABEL_MAX_LENGTH) {
                /* translators: 1: custom label slot number, 2: current length, 3: maximum allowed length. */
                $findings[] = self::error(
                    'custom_labels',
                    'custom_label_too_long',
                    sprintf(
                        __('Eticheta custom_label_%1$d are %2$d caractere; Meta acceptă maximum %3$d.', 'adc-marketing'),
                        $slot,
                        self::length($value),
                        self::CUSTOM_LABEL_MAX_LENGTH
                    )
                );
            }
        }

        return $findings;
    }

    /**
     * shipping: optional Country:Region:Service:Price zones (spec §2.2).
     *
     * Meta does not require shipping; it only powers the shipping overlay, so every
     * problem here is a warning.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_shipping(array $item): array
    {
        $shipping = $item['shipping'] ?? array();
        if ($shipping === array() || $shipping === null) {
            return array();
        }

        if (!is_array($shipping)) {
            return array(self::warning(
                'shipping',
                'shipping_invalid',
                __('shipping trebuie trimis ca listă de zone Country:Region:Service:Price.', 'adc-marketing')
            ));
        }

        $findings = array();
        $index    = 0;
        foreach ($shipping as $zone) {
            ++$index;
            if (!is_array($zone)) {
                /* translators: %d: position of the shipping zone. */
                $findings[] = self::warning(
                    'shipping',
                    'shipping_zone_invalid',
                    sprintf(
                        __('Zona de livrare %d nu este o listă de sub-atribute.', 'adc-marketing'),
                        $index
                    )
                );
                continue;
            }

            $country = strtoupper(self::text($zone['country'] ?? null));
            $price   = $zone['price'] ?? null;
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || !is_numeric($price) || (float) $price < 0) {
                /* translators: %d: position of the shipping zone. */
                $findings[] = self::warning(
                    'shipping',
                    'shipping_zone_incomplete',
                    sprintf(
                        __('Zona de livrare %d trebuie să aibă țara ISO 3166 și un preț numeric nenegativ.', 'adc-marketing'),
                        $index
                    )
                );
            }
        }

        return $findings;
    }

    /**
     * shipping_weight: optional, expressed in lb/oz/g/kg (spec §2.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_weight(array $item): array
    {
        $weight = self::text($item['weight'] ?? null);
        if ($weight === '') {
            return array();
        }

        $parts = preg_split('/\s+/', $weight) ?: array();
        if (count($parts) === 1) {
            return array(self::warning(
                'weight',
                'weight_unit_missing',
                __('Greutatea nu are unitate; Meta așteaptă lb, oz, g sau kg lângă valoare.', 'adc-marketing')
            ));
        }

        if (!is_numeric($parts[0]) || (float) $parts[0] <= 0) {
            return array(self::warning(
                'weight',
                'weight_invalid',
                __('Greutatea trebuie să fie un număr pozitiv, urmat de unitatea lb, oz, g sau kg.', 'adc-marketing')
            ));
        }

        return array();
    }

    /**
     * status: optional active/archived (renamed from visibility, spec §2.2, §2.4).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_status(array $item): array
    {
        $status = strtolower(self::text($item['status'] ?? null));
        if ($status === '' || in_array($status, self::STATUS_VALUES, true)) {
            return array();
        }

        /* translators: %s: submitted status value. */
        return array(self::error(
            'status',
            'status_invalid',
            sprintf(
                __('status „%s” nu este acceptat; Meta acceptă active sau archived.', 'adc-marketing'),
                $status
            )
        ));
    }

    /**
     * Build an error finding.
     *
     * @param string $field   Normalized field name.
     * @param string $code    Stable machine code.
     * @param string $message Romanian message.
     * @return array{level:string,field:string,code:string,message:string}
     */
    private static function error(string $field, string $code, string $message): array
    {
        return self::finding(self::LEVEL_ERROR, $field, $code, $message);
    }

    /**
     * Build a warning finding.
     *
     * @param string $field   Normalized field name.
     * @param string $code    Stable machine code.
     * @param string $message Romanian message.
     * @return array{level:string,field:string,code:string,message:string}
     */
    private static function warning(string $field, string $code, string $message): array
    {
        return self::finding(self::LEVEL_WARNING, $field, $code, $message);
    }

    /**
     * Build one finding.
     *
     * @param string $level   error or warning.
     * @param string $field   Normalized field name.
     * @param string $code    Stable machine code.
     * @param string $message Romanian message.
     * @return array{level:string,field:string,code:string,message:string}
     */
    private static function finding(string $level, string $field, string $code, string $message): array
    {
        return array(
            'level'   => $level,
            'field'   => $field,
            'code'    => $code,
            'message' => $message,
        );
    }

    /**
     * Normalize a scalar attribute to a trimmed string.
     *
     * @param mixed $value Raw attribute.
     * @return string Empty string when the value is absent or not scalar.
     */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * Count characters, not bytes, so Romanian diacritics count as one character.
     *
     * @param string $value Text to measure.
     * @return int Character count.
     */
    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        $count = preg_match_all('/./us', $value);

        return false === $count ? strlen($value) : (int) $count;
    }

    /**
     * Shorten a value for a message.
     *
     * @param string $value Text to shorten.
     * @return string At most 20 characters.
     */
    private static function shorten(string $value): string
    {
        if (self::length($value) <= 20) {
            return $value;
        }

        $slice = function_exists('mb_substr') ? mb_substr($value, 0, 20, 'UTF-8') : substr($value, 0, 20);

        return (string) $slice;
    }

    /**
     * Lowercase a value without requiring mbstring.
     *
     * @param string $value Text to lowercase.
     * @return string Lowercased text.
     */
    private static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return (string) mb_strtolower($value, 'UTF-8');
        }

        $romanian = array(
            'Ă' => 'ă',
            'Â' => 'â',
            'Î' => 'î',
            'Ș' => 'ș',
            'Ț' => 'ț',
        );

        return strtr(strtolower($value), $romanian);
    }

    /**
     * Detect an ALL CAPS description.
     *
     * @param string $value Text to inspect.
     * @return bool True when the text has more than one letter and none lowercase.
     */
    private static function is_all_caps(string $value): bool
    {
        $has_letter = preg_match('/\p{L}/u', $value) === 1;
        if (!$has_letter) {
            return false;
        }

        return preg_match('/\p{Ll}/u', $value) !== 1;
    }

    /**
     * Read the lowercased scheme of a URL.
     *
     * @param string $url Candidate URL.
     * @return string Scheme or an empty string.
     */
    private static function url_scheme(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) ? strtolower($scheme) : '';
    }

    /**
     * Validate an absolutely addressable http(s) URL.
     *
     * @param string $url Candidate URL.
     * @return bool True when the URL is usable in a feed.
     */
    private static function is_http_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(self::url_scheme($url), array('http', 'https'), true);
    }

    /**
     * Detect a JPEG/PNG URL path.
     *
     * @param string $url Candidate image URL.
     * @return bool True when the path ends in .jpg, .jpeg or .png.
     */
    private static function has_image_extension(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('/\.(?:jpe?g|png)$/i', $path) === 1;
    }

    /**
     * Validate the sale_price_effective_date period format.
     *
     * @param string $value Candidate period.
     * @return bool True for YYYY-MM-DDThh:mm+hh:mm/YYYY-MM-DDThh:mm+hh:mm.
     */
    private static function is_effective_period(string $value): bool
    {
        $stamp = '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}[+-]\d{2}:\d{2}';

        return preg_match('~^' . $stamp . '/' . $stamp . '$~', $value) === 1;
    }

    /**
     * Format an amount with a point decimal separator.
     *
     * @param float $value Amount.
     * @return string Formatted amount.
     */
    private static function amount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Read a positive integer attribute.
     *
     * @param mixed $value Raw attribute.
     * @return int Positive integer, or 0 when unusable.
     */
    private static function positive_int(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }
}
