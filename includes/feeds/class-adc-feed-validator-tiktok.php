<?php
/**
 * TikTok Commerce Product Catalog feed item validator.
 *
 * Every rule and limit below comes from docs/research/feeds-platform-specs.md §3
 * (TikTok Commerce Product Catalog, verified 2026-11-07): the nine required
 * attributes, the 27 optional attributes and their enums/limits. TikTok is not a
 * Google alias: availability has five space-separated values, google_product_category
 * and product_type support three levels, and image hosting has an explicit
 * accepted/not-accepted list. Rules the research marks NEVERIFICAT, and heuristics
 * that cannot be proven from one row (image format by extension), are warnings.
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

/** Validates one normalized feed row against the TikTok Commerce Product Catalog profile. */
final class ADC_Feed_Validator_Tiktok
{
    /** Finding levels. */
    public const LEVEL_ERROR   = 'error';
    public const LEVEL_WARNING = 'warning';

    /** The nine attributes TikTok rejects the item for (spec §3.2). */
    public const REQUIRED_FIELDS = array(
        'sku_id',
        'title',
        'description',
        'availability',
        'condition',
        'price',
        'link',
        'image_link',
        'brand',
    );

    /** Five accepted availability values, with spaces (spec §3.2). */
    public const AVAILABILITY_VALUES = array('in stock', 'available for order', 'preorder', 'out of stock', 'discontinued');

    /** Catalog tokens that still need a channel mapping before serialization. */
    public const AVAILABILITY_MAPPABLE = array('in_stock', 'out_of_stock', 'backorder');

    /** Accepted condition values (spec §3.2). */
    public const CONDITION_VALUES = array('new', 'refurbished', 'used');

    /** Accepted age_group values (spec §3.2). */
    public const AGE_GROUP_VALUES = array('newborn', 'infant', 'toddler', 'kids', 'adult');

    /** Accepted gender values (spec §3.2). */
    public const GENDER_VALUES = array('male', 'female', 'unisex');

    /** Documented GTIN families: UPC-12, EAN-13/JAN/ISBN-13, ITF-14 (spec §3.2). */
    public const GTIN_LENGTHS = array(12, 13, 14);

    /** Up to 10 additional_image_link values (spec §3.2). */
    public const ADDITIONAL_IMAGES_MAX = 10;

    /** image_link minimum side for a 1:1 image (spec §3.2). */
    public const IMAGE_MIN_SIDE = 500;

    /** custom_label_0..4 max 100 characters each (spec §3.2). */
    public const CUSTOM_LABEL_MAX_LENGTH = 100;

    /** Five custom label slots (spec §3.2). */
    public const CUSTOM_LABEL_SLOTS = 5;

    /** Only three category levels are supported (spec §3.2). */
    public const CATEGORY_MAX_LEVELS = 3;

    /** Hosting TikTok explicitly does not accept for image_link (spec §3.2). */
    public const BLOCKED_IMAGE_HOSTS = array(
        'drive.google.com',
        'docs.google.com',
        'flickr.com',
        'pinterest.com',
        'pinimg.com',
        '500px.com',
        'imgbb.com',
        'smugmug.com',
        'dropbox.com',
    );

    /** Promotional wording that is not allowed in a title (spec §3.2). */
    public const TITLE_PROMO_TERMS = array(
        'free shipping',
        'wholesale',
        'gratis',
        'transport gratuit',
        'livrare gratuită',
        'reducere',
        'discount',
    );

    /**
     * Validate one normalized catalog row.
     *
     * The row keys follow the normalized catalog contract shared by all channels:
     * feed_item_id, item_group_id, title, description, link, image_link,
     * additional_image_links[], availability, price, sale_price, currency,
     * condition, brand, gtin, mpn, google_product_category, product_type, weight,
     * attributes, shipping[], custom_labels[].
     *
     * Known optional extensions used when present: sku_id, video_link, tax,
     * sale_price_effective_date, sale_start, sale_end, image_width, image_height.
     *
     * @param array<string,mixed> $item   Normalized catalog row.
     * @param array<string,mixed> $config Optional: currency fallback.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    public static function validate(array $item, array $config = array()): array
    {
        return array_merge(
            self::validate_sku_id($item),
            self::validate_title($item),
            self::validate_description($item),
            self::validate_availability($item),
            self::validate_condition($item),
            self::validate_price($item, $config),
            self::validate_links($item),
            self::validate_images($item),
            self::validate_brand($item),
            self::validate_identifiers($item),
            self::validate_taxonomy($item),
            self::validate_variants($item),
            self::validate_labels($item),
            self::validate_video($item),
            self::validate_shipping($item),
            self::validate_measurements($item),
            self::validate_tax($item)
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
     * sku_id: required, unique, falls back to the catalog id (spec §3.2).
     *
     * Duplicate detection needs the whole exported catalog, so only presence and
     * valid UTF-8 are enforced per row.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_sku_id(array $item): array
    {
        $sku = self::text($item['sku_id'] ?? $item['feed_item_id'] ?? null);

        if ($sku === '') {
            return array(self::error(
                'sku_id',
                'sku_id_missing',
                __('Lipsește sku_id, primul câmp cerut de TikTok.', 'adc-marketing')
            ));
        }

        if (preg_match('//u', $sku) !== 1) {
            return array(self::error(
                'sku_id',
                'sku_id_invalid_encoding',
                __('sku_id conține caractere unicode nevalide; TikTok cere text UTF-8 valid.', 'adc-marketing')
            ));
        }

        return array();
    }

    /**
     * title: required, no promotional wording, no emoji (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_title(array $item): array
    {
        $title = self::text($item['title'] ?? null);

        if ($title === '') {
            return array(self::error(
                'title',
                'title_missing',
                __('Lipsește titlul produsului (title), cerut de TikTok.', 'adc-marketing')
            ));
        }

        $findings = array();
        $lower    = self::lower($title);
        foreach (self::TITLE_PROMO_TERMS as $term) {
            if (str_contains($lower, $term)) {
                /* translators: %s: promotional term found in the title. */
                $findings[] = self::warning(
                    'title',
                    'title_promotional',
                    sprintf(
                        __('Titlul conține text promoțional („%s”); TikTok cere titlu fără mesaje promoționale.', 'adc-marketing'),
                        $term
                    )
                );
                break;
            }
        }

        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $title) === 1) {
            $findings[] = self::warning(
                'title',
                'title_emoji',
                __('Titlul conține emoji; TikTok cere titlu fără emoji.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * description: required, no documented length limit (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_description(array $item): array
    {
        if (self::text($item['description'] ?? null) !== '') {
            return array();
        }

        return array(self::error(
            'description',
            'description_missing',
            __('Lipsește descrierea produsului (description), cerută de TikTok.', 'adc-marketing')
        ));
    }

    /**
     * availability: one of the five documented values, with spaces (spec §3.2).
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
                __('Lipsește disponibilitatea (availability), cerută de TikTok.', 'adc-marketing')
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
                    __('Disponibilitatea „%s” trebuie mapată la valorile TikTok: in stock, available for order, preorder, out of stock sau discontinued.', 'adc-marketing'),
                    $availability
                )
            ));
        }

        /* translators: %s: current availability value. */
        return array(self::error(
            'availability',
            'availability_invalid',
            sprintf(
                __('Disponibilitatea „%s” nu este acceptată; TikTok cere in stock, available for order, preorder, out of stock sau discontinued.', 'adc-marketing'),
                $availability
            )
        ));
    }

    /**
     * condition: required, new/refurbished/used (spec §3.2).
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
                __('Lipsește condiția produsului (condition), cerută de TikTok.', 'adc-marketing')
            ));
        }

        if (!in_array($condition, self::CONDITION_VALUES, true)) {
            /* translators: %s: submitted condition value. */
            return array(self::error(
                'condition',
                'condition_invalid',
                sprintf(
                    __('Condiția „%s” nu este acceptată; TikTok acceptă new, refurbished sau used.', 'adc-marketing'),
                    $condition
                )
            ));
        }

        return array();
    }

    /**
     * price and sale_price: number with the catalog country currency (spec §3.2).
     *
     * TikTok takes the currency from the target country of the catalog, so a missing
     * currency is a warning while a malformed one is an error.
     *
     * @param array<string,mixed> $item   Normalized row.
     * @param array<string,mixed> $config Validator configuration.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_price(array $item, array $config): array
    {
        $findings = array();
        $price    = $item['price'] ?? null;

        if ($price === null || $price === '') {
            $findings[] = self::error(
                'price',
                'price_missing',
                __('Lipsește prețul (price), cerut de TikTok.', 'adc-marketing')
            );
        } elseif (!is_numeric($price) || (float) $price <= 0) {
            $findings[] = self::error(
                'price',
                'price_invalid',
                __('Prețul trebuie să fie un număr pozitiv, cu punct decimal.', 'adc-marketing')
            );
        }

        $currency = strtoupper(self::text($item['currency'] ?? $config['currency'] ?? ''));
        if ($currency === '') {
            $findings[] = self::warning(
                'currency',
                'currency_missing',
                __('Moneda lipsește; TikTok o ia din țara țintă a catalogului, dar o trimitem explicit pentru claritate.', 'adc-marketing')
            );
        } elseif (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $findings[] = self::error(
                'currency',
                'currency_invalid',
                __('Moneda trebuie să fie un cod ISO 4217 din trei litere mari (de exemplu RON).', 'adc-marketing')
            );
        }

        $sale = $item['sale_price'] ?? null;
        if ($sale === null || $sale === '') {
            return $findings;
        }

        if (!is_numeric($sale) || (float) $sale <= 0) {
            $findings[] = self::error(
                'sale_price',
                'sale_price_invalid',
                __('Prețul redus (sale_price) trebuie să fie un număr pozitiv, cu punct decimal.', 'adc-marketing')
            );

            return $findings;
        }

        if (is_numeric($price) && (float) $sale >= (float) $price) {
            /* translators: 1: sale price, 2: list price. */
            $findings[] = self::warning(
                'sale_price',
                'sale_price_not_lower',
                sprintf(
                    __('Prețul redus (%1$s) nu este mai mic decât prețul plin (%2$s); overlay-ul de reducere nu se va aplica.', 'adc-marketing'),
                    self::amount((float) $sale),
                    self::amount((float) $price)
                )
            );
        }

        $effective = self::text($item['sale_price_effective_date'] ?? '');
        if ($effective !== '' && !self::is_effective_period($effective)) {
            $findings[] = self::warning(
                'sale_price_effective_date',
                'sale_price_effective_date_invalid',
                __('sale_price_effective_date trebuie să aibă formatul AAAA-LL-ZZThh:mm/AAAA-LL-ZZThh:mm.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * link: required, active landing page URL (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_links(array $item): array
    {
        $link = self::text($item['link'] ?? null);

        if ($link === '') {
            return array(self::error(
                'link',
                'link_missing',
                __('Lipsește linkul către pagina produsului (link), cerut de TikTok.', 'adc-marketing')
            ));
        }

        if (!self::is_http_url($link)) {
            return array(self::error(
                'link',
                'link_invalid',
                __('Linkul produsului trebuie să fie un URL absolut cu schemă http sau https.', 'adc-marketing')
            ));
        }

        return array();
    }

    /**
     * image_link: required, at least 500×500, JPG/PNG, accepted hosting (spec §3.2).
     *
     * additional_image_link accepts up to 10 URLs with the same image rules.
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
                __('Lipsește imaginea principală (image_link), cerută de TikTok.', 'adc-marketing')
            );
        } elseif (!self::is_http_url($image_link)) {
            $findings[] = self::error(
                'image_link',
                'image_link_invalid',
                __('Imaginea principală trebuie să fie un URL absolut cu schemă http sau https.', 'adc-marketing')
            );
        } else {
            $host = strtolower((string) parse_url($image_link, PHP_URL_HOST));
            foreach (self::BLOCKED_IMAGE_HOSTS as $blocked) {
                if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                    /* translators: %s: hosting domain rejected by TikTok. */
                    $findings[] = self::error(
                        'image_link',
                        'image_link_host_not_allowed',
                        sprintf(
                            __('Imaginea principală este găzduită pe %s; TikTok nu acceptă acest hosting pentru imagini.', 'adc-marketing'),
                            $blocked
                        )
                    );
                    break;
                }
            }

            if (!self::has_image_extension($image_link)) {
                $findings[] = self::warning(
                    'image_link',
                    'image_link_format',
                    __('URL-ul imaginii nu se termină în .jpg, .jpeg sau .png; TikTok cere linkuri JPG sau PNG.', 'adc-marketing')
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
                        __('Imaginea are %1$d×%2$d px; TikTok cere minimum %3$d×%3$d px pentru formatul 1:1.', 'adc-marketing'),
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

        if (count($values) > self::ADDITIONAL_IMAGES_MAX) {
            /* translators: 1: number of submitted images, 2: maximum allowed number. */
            $findings[] = self::error(
                'additional_image_links',
                'additional_images_too_many',
                sprintf(
                    __('Sunt %1$d imagini secundare; TikTok acceptă maximum %2$d.', 'adc-marketing'),
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

            if (!self::has_image_extension($url)) {
                /* translators: %d: position of the image in the additional images list. */
                $findings[] = self::warning(
                    'additional_image_links',
                    'additional_image_format',
                    sprintf(
                        __('URL-ul imaginii secundare %d nu se termină în .jpg, .jpeg sau .png; TikTok cere linkuri JPG sau PNG.', 'adc-marketing'),
                        $index
                    )
                );
            }
        }

        return $findings;
    }

    /**
     * brand: required (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_brand(array $item): array
    {
        if (self::text($item['brand'] ?? null) !== '') {
            return array();
        }

        return array(self::error(
            'brand',
            'brand_missing',
            __('Lipsește brandul, cerut de TikTok pentru fiecare produs.', 'adc-marketing')
        ));
    }

    /**
     * gtin, mpn and item_group_id are optional for TikTok (spec §3.2).
     *
     * A GTIN that is present must still be a documented family without spaces or
     * hyphens; because the attribute is optional, a malformed value is a warning.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_identifiers(array $item): array
    {
        $gtin = self::text($item['gtin'] ?? null);
        if ($gtin === '') {
            return array();
        }

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
                    __('GTIN-ul are %d cifre; TikTok documentează UPC-12, EAN-13, JAN, ISBN-13 și ITF-14.', 'adc-marketing'),
                    strlen($gtin)
                )
            ));
        }

        return array();
    }

    /**
     * google_product_category and product_type support three levels (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_taxonomy(array $item): array
    {
        $findings = array();

        $category = self::text($item['google_product_category'] ?? null);
        if ($category !== '' && substr_count($category, ' > ') + 1 > self::CATEGORY_MAX_LEVELS) {
            /* translators: 1: number of levels, 2: maximum supported levels. */
            $findings[] = self::warning(
                'google_product_category',
                'google_product_category_depth',
                sprintf(
                    __('Categoria google_product_category are %1$d niveluri; TikTok afișează doar primele %2$d.', 'adc-marketing'),
                    substr_count($category, ' > ') + 1,
                    self::CATEGORY_MAX_LEVELS
                )
            );
        }

        $type = self::text($item['product_type'] ?? null);
        if ($type !== '' && substr_count($type, ' > ') + 1 > self::CATEGORY_MAX_LEVELS) {
            /* translators: 1: number of levels, 2: maximum supported levels. */
            $findings[] = self::warning(
                'product_type',
                'product_type_depth',
                sprintf(
                    __('Câmpul product_type are %1$d niveluri; TikTok indică doar primele %2$d.', 'adc-marketing'),
                    substr_count($type, ' > ') + 1,
                    self::CATEGORY_MAX_LEVELS
                )
            );
        }

        return $findings;
    }

    /**
     * age_group and gender enums from the optional attribute list (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_variants(array $item): array
    {
        $findings   = array();
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : array();

        $age_group = self::lower(self::text($attributes['age_group'] ?? null));
        if ($age_group !== '' && !in_array($age_group, self::AGE_GROUP_VALUES, true)) {
            /* translators: %s: submitted age group value. */
            $findings[] = self::error(
                'attributes.age_group',
                'age_group_invalid',
                sprintf(
                    __('age_group „%s” nu este acceptat; TikTok acceptă newborn, infant, toddler, kids sau adult.', 'adc-marketing'),
                    $age_group
                )
            );
        }

        $gender = self::lower(self::text($attributes['gender'] ?? null));
        if ($gender !== '' && !in_array($gender, self::GENDER_VALUES, true)) {
            /* translators: %s: submitted gender value. */
            $findings[] = self::error(
                'attributes.gender',
                'gender_invalid',
                sprintf(
                    __('gender „%s” nu este acceptat; TikTok acceptă male, female sau unisex.', 'adc-marketing'),
                    $gender
                )
            );
        }

        return $findings;
    }

    /**
     * custom_label_0..4: up to five slots of max 100 characters (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_labels(array $item): array
    {
        $custom = $item['custom_labels'] ?? array();

        if (!is_array($custom)) {
            return array(self::error(
                'custom_labels',
                'custom_labels_invalid',
                __('Etichetele custom trebuie trimise ca listă de valori text.', 'adc-marketing')
            ));
        }

        $findings = array();
        $values   = array();
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
                    __('Sunt %1$d etichete custom; TikTok acceptă maximum %2$d (custom_label_0 … custom_label_4).', 'adc-marketing'),
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
                        __('Eticheta custom_label_%1$d are %2$d caractere; TikTok acceptă maximum %3$d.', 'adc-marketing'),
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
     * video_link: optional, must be a direct http(s) URL with a video extension (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_video(array $item): array
    {
        $video = self::text($item['video_link'] ?? null);
        if ($video === '') {
            return array();
        }

        if (!self::is_http_url($video)) {
            return array(self::warning(
                'video_link',
                'video_link_invalid',
                __('video_link trebuie să fie un link direct la fișier, nu un URL de pagină sau de platformă video.', 'adc-marketing')
            ));
        }

        if (str_contains(self::lower($video), 'youtube.com') || str_contains(self::lower($video), 'youtu.be')) {
            return array(self::warning(
                'video_link',
                'video_link_platform',
                __('video_link indică spre YouTube; TikTok cere link direct la fișierul video.', 'adc-marketing')
            ));
        }

        return array();
    }

    /**
     * shipping: optional COUNTRY:STATE:SHIPPING_TYPE:PRICE:SERVICE zones (spec §3.2).
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

        if (is_string($shipping)) {
            $zones = array_filter(array_map('trim', explode(';', $shipping)), static fn(string $zone): bool => $zone !== '');
            $findings = array();
            foreach ($zones as $index => $zone) {
                if (count(explode(':', $zone)) < 5) {
                    /* translators: %d: position of the shipping zone. */
                    $findings[] = self::warning(
                        'shipping',
                        'shipping_zone_incomplete',
                        sprintf(
                            __('Zona de livrare %d trebuie să aibă formatul Țară:Stat:TipLivrare:Preț:Serviciu.', 'adc-marketing'),
                            $index + 1
                        )
                    );
                }
            }

            return $findings;
        }

        if (!is_array($shipping)) {
            return array(self::warning(
                'shipping',
                'shipping_invalid',
                __('shipping trebuie trimis ca listă de zone Țară:Stat:TipLivrare:Preț:Serviciu.', 'adc-marketing')
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
     * shipping_weight: optional value plus unit, for example "0.3 lb" (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_measurements(array $item): array
    {
        $weight = self::text($item['weight'] ?? null);
        if ($weight === '') {
            return array();
        }

        if (!preg_match('/^\d+(?:[.,]\d+)?\s*(?:lb|oz|g|kg)$/i', $weight)) {
            return array(self::warning(
                'weight',
                'shipping_weight_format',
                __('Greutatea trebuie scrisă ca valoare plus unitate (de exemplu „0.3 lb”, „500 g”).', 'adc-marketing')
            ));
        }

        return array();
    }

    /**
     * tax: optional decimal rate, for example "0.05" (spec §3.2).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_tax(array $item): array
    {
        $tax = self::text($item['tax'] ?? null);
        if ($tax === '' || (is_numeric($tax) && (float) $tax >= 0)) {
            return array();
        }

        return array(self::warning(
            'tax',
            'tax_invalid',
            __('tax trebuie să fie o cotă numerică, de exemplu 0.05.', 'adc-marketing')
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
     * @return bool True for YYYY-MM-DDThh:mm/YYYY-MM-DDThh:mm.
     */
    private static function is_effective_period(string $value): bool
    {
        $stamp = '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}';

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
