<?php
/**
 * Google Merchant Center feed item validator.
 *
 * Every rule and every limit below comes from docs/research/feeds-platform-specs.md
 * §1 (Google Merchant Center, verified 2026-11-07): §1.2 attribute tables, §1.3
 * custom labels and §1.4 the 2026–2027 schedule. Conditional rules (marked ⚠️ in
 * that document) and anything the research could not verify are reported as
 * warnings, never as errors.
 *
 * The class is pure: it reads one normalized catalog row (no WordPress queries,
 * no HTTP, no filesystem) and returns findings, so it can run in generation
 * pipelines and in tests outside a WordPress bootstrap.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Validates one normalized feed row against the Google Merchant Center profile. */
final class ADC_Feed_Validator_Google
{
    /** Finding levels. */
    public const LEVEL_ERROR   = 'error';
    public const LEVEL_WARNING = 'warning';

    /** id max 50 characters (spec §1.2 A, §0 D15). */
    public const ID_MAX_LENGTH = 50;

    /** title max 150 characters (spec §1.2 A). */
    public const TITLE_MAX_LENGTH = 150;

    /** description max 5000 characters (spec §1.2 A). */
    public const DESCRIPTION_MAX_LENGTH = 5000;

    /** brand max 70 characters (spec §1.2 D). */
    public const BRAND_MAX_LENGTH = 70;

    /** mpn max 70 alphanumeric characters (spec §1.2 D). */
    public const MPN_MAX_LENGTH = 70;

    /** gtin max 50 characters overall, max 14 per value (spec §1.2 D). */
    public const GTIN_MAX_LENGTH = 50;

    /** gtin per-value limit (spec §1.2 D). */
    public const GTIN_VALUE_MAX_LENGTH = 14;

    /** product_type max 750 characters (spec §1.2 C). */
    public const PRODUCT_TYPE_MAX_LENGTH = 750;

    /** item_group_id max 50 characters (spec §1.2 E, §0 D15). */
    public const ITEM_GROUP_ID_MAX_LENGTH = 50;

    /** custom_label_0..4 max 100 characters each (spec §1.2 F, §1.3). */
    public const CUSTOM_LABEL_MAX_LENGTH = 100;

    /** Up to five custom label slots per product (spec §1.3). */
    public const CUSTOM_LABEL_SLOTS = 5;

    /** Up to 10 additional_image_link values (spec §1.2 A). */
    public const ADDITIONAL_IMAGES_MAX = 10;

    /** additional_image_link max 2000 characters (spec §1.2 A). */
    public const ADDITIONAL_IMAGE_URL_MAX_LENGTH = 2000;

    /** Hard minimum image side in pixels; smaller images are rejected. */
    public const IMAGE_MIN_SIDE = 100;

    /** Announced 500×500 requirement: warnings now, enforcement 2027-01-31 (spec §1.4). */
    public const IMAGE_UPCOMING_MIN_SIDE = 500;

    /** Accepted availability values (spec §1.2 B). */
    public const AVAILABILITY_VALUES = array('in_stock', 'out_of_stock', 'preorder', 'backorder');

    /** Accepted condition values (spec §1.2 E). */
    public const CONDITION_VALUES = array('new', 'refurbished', 'used');

    /** Accepted identifier_exists values (spec §1.2 D). */
    public const IDENTIFIER_EXISTS_VALUES = array('yes', 'no');

    /** Countries where shipping is required for Shopping ads and free listings (spec §1.2 G). */
    public const SHIPPING_REQUIRED_COUNTRIES = array(
        'AU',
        'AT',
        'BE',
        'CA',
        'CZ',
        'FR',
        'DE',
        'IN',
        'IE',
        'IL',
        'IT',
        'NZ',
        'JP',
        'NL',
        'RO',
        'KR',
        'ES',
        'CH',
        'UK',
        'US',
    );

    /** Documented upper bound for a shipping price, in local currency (spec §1.2 G). */
    public const SHIPPING_PRICE_MAX = 1000;

    /** availability_date max 25 characters (spec §1.2 B). */
    public const AVAILABILITY_DATE_MAX_LENGTH = 25;

    /**
     * Validate one normalized catalog row.
     *
     * The row keys are the normalized catalog contract: feed_item_id, item_group_id,
     * title, description, link, image_link, additional_image_links[], availability,
     * price, sale_price, currency, condition, brand, gtin, mpn,
     * google_product_category, product_type, weight, attributes, shipping[],
     * custom_labels[].
     *
     * Known optional extensions used when present: availability_date,
     * image_width, image_height.
     *
     * @param array<string,mixed> $item   Normalized catalog row.
     * @param array<string,mixed> $config Optional: country (default RO), currency
     *                                    fallback, shipping_required bool override.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    public static function validate(array $item, array $config = array()): array
    {
        $country = strtoupper((string) self::text($config['country'] ?? 'RO'));
        if ($country === '') {
            $country = 'RO';
        }

        return array_merge(
            self::validate_identity($item),
            self::validate_title($item),
            self::validate_description($item),
            self::validate_link($item),
            self::validate_images($item),
            self::validate_price($item, $config),
            self::validate_availability($item),
            self::validate_condition($item),
            self::validate_identifiers($item),
            self::validate_taxonomy($item),
            self::validate_shipping($item, $country, $config),
            self::validate_custom_labels($item),
            self::validate_measurements($item)
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
     * id and item_group_id: presence and documented length limits.
     *
     * item_group_id is not required for a Romanian store (§0 D4), so a missing
     * value is not reported; only its 50-character limit is enforced.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_identity(array $item): array
    {
        $findings = array();
        $id       = self::text($item['feed_item_id'] ?? null);

        if ($id === '') {
            $findings[] = self::error(
                'feed_item_id',
                'id_missing',
                __('Lipsește ID-ul produsului (id), cerut de Google pentru fiecare ofertă.', 'adc-marketing')
            );
        } elseif (self::length($id) > self::ID_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'feed_item_id',
                'id_too_long',
                sprintf(
                    __('ID-ul produsului are %1$d caractere; Google acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($id),
                    self::ID_MAX_LENGTH
                )
            );
        }

        $group = self::text($item['item_group_id'] ?? null);
        if ($group !== '' && self::length($group) > self::ITEM_GROUP_ID_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'item_group_id',
                'item_group_id_too_long',
                sprintf(
                    __('item_group_id are %1$d caractere; Google acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($group),
                    self::ITEM_GROUP_ID_MAX_LENGTH
                )
            );
        }

        return $findings;
    }

    /**
     * title: required, max 150 characters, plain text (spec §1.2 A).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_title(array $item): array
    {
        $findings = array();
        $title    = self::text($item['title'] ?? null);

        if ($title === '') {
            $findings[] = self::error(
                'title',
                'title_missing',
                __('Lipsește titlul produsului (title), cerut de Google.', 'adc-marketing')
            );

            return $findings;
        }

        if (self::length($title) > self::TITLE_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'title',
                'title_too_long',
                sprintf(
                    __('Titlul are %1$d caractere; Google acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($title),
                    self::TITLE_MAX_LENGTH
                )
            );
        }

        if (!self::is_plain_text($title)) {
            $findings[] = self::warning(
                'title',
                'title_not_plain_text',
                __('Titlul conține HTML sau rânduri multiple; Google cere text simplu.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * description: required, max 5000 characters, plain text (spec §1.2 A).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_description(array $item): array
    {
        $findings    = array();
        $description = self::text($item['description'] ?? null);

        if ($description === '') {
            $findings[] = self::error(
                'description',
                'description_missing',
                __('Lipsește descrierea produsului (description), cerută de Google.', 'adc-marketing')
            );

            return $findings;
        }

        if (self::length($description) > self::DESCRIPTION_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'description',
                'description_too_long',
                sprintf(
                    __('Descrierea are %1$d caractere; Google acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($description),
                    self::DESCRIPTION_MAX_LENGTH
                )
            );
        }

        if (!self::is_plain_text($description)) {
            $findings[] = self::warning(
                'description',
                'description_not_plain_text',
                __('Descrierea conține HTML; Google cere text simplu, fără marcaj.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * link: required, http(s) URL of the real product page (spec §1.2 A).
     *
     * The specification requires a valid URL, not https specifically, so a plain
     * http link is a recommendation only.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_link(array $item): array
    {
        $findings = array();
        $link     = self::text($item['link'] ?? null);

        if ($link === '') {
            $findings[] = self::error(
                'link',
                'link_missing',
                __('Lipsește linkul către pagina produsului (link), cerut de Google.', 'adc-marketing')
            );

            return $findings;
        }

        $scheme = self::url_scheme($link);
        if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array($scheme, array('http', 'https'), true)) {
            $findings[] = self::error(
                'link',
                'link_invalid',
                __('Linkul produsului trebuie să fie un URL absolut cu schemă http sau https.', 'adc-marketing')
            );

            return $findings;
        }

        if ($scheme === 'http') {
            $findings[] = self::warning(
                'link',
                'link_https_recommended',
                __('Linkul produsului folosește http; recomandăm https pentru pagina de destinație.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * image_link and additional_image_links: required main image, http(s) URLs,
     * up to 10 additional images, 100×100 hard minimum, 500×500 from 2027 (§1.2 A, §1.4).
     *
     * Dimensions are only checked when the normalized row exposes image_width and
     * image_height; the crawler owns the real download.
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
                __('Lipsește imaginea principală (image_link), cerută de Google.', 'adc-marketing')
            );
        } elseif (!self::is_http_url($image_link)) {
            $findings[] = self::error(
                'image_link',
                'image_link_invalid',
                __('Imaginea principală trebuie să fie un URL absolut cu schemă http sau https.', 'adc-marketing')
            );
        } else {
            $findings = array_merge($findings, self::validate_image_dimensions($item));
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

        if (empty($additional)) {
            $findings[] = self::warning(
                'additional_image_links',
                'additional_images_missing',
                __('Nu există imagini secundare; Google le recomandă pentru prezentarea produsului.', 'adc-marketing')
            );
        }

        if (count($additional) > self::ADDITIONAL_IMAGES_MAX) {
            /* translators: 1: number of submitted images, 2: maximum allowed number. */
            $findings[] = self::error(
                'additional_image_links',
                'additional_images_too_many',
                sprintf(
                    __('Sunt %1$d imagini secundare; Google acceptă maximum %2$d.', 'adc-marketing'),
                    count($additional),
                    self::ADDITIONAL_IMAGES_MAX
                )
            );
        }

        $index = 0;
        foreach ($additional as $url) {
            ++$index;
            $value = self::text($url);

            if ($value === '' || !self::is_http_url($value)) {
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

            if (self::length($value) > self::ADDITIONAL_IMAGE_URL_MAX_LENGTH) {
                /* translators: 1: position of the image, 2: current length, 3: maximum allowed length. */
                $findings[] = self::error(
                    'additional_image_links',
                    'additional_image_url_too_long',
                    sprintf(
                        __('URL-ul imaginii secundare %1$d are %2$d caractere; Google acceptă maximum %3$d.', 'adc-marketing'),
                        $index,
                        self::length($value),
                        self::ADDITIONAL_IMAGE_URL_MAX_LENGTH
                    )
                );
            }
        }

        return $findings;
    }

    /**
     * Image size checks: 100×100 is the hard minimum, 500×500 is announced for 2027-01-31.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_image_dimensions(array $item): array
    {
        $width  = self::positive_int($item['image_width'] ?? null);
        $height = self::positive_int($item['image_height'] ?? null);
        if ($width === 0 || $height === 0) {
            return array();
        }

        $shortest = min($width, $height);

        if ($shortest < self::IMAGE_MIN_SIDE) {
            /* translators: 1: image width, 2: image height, 3: minimum allowed side. */
            return array(self::error(
                'image_link',
                'image_too_small',
                sprintf(
                    __('Imaginea are %1$d×%2$d px; Google cere minimum %3$d×%3$d px.', 'adc-marketing'),
                    $width,
                    $height,
                    self::IMAGE_MIN_SIDE
                )
            ));
        }

        if ($shortest < self::IMAGE_UPCOMING_MIN_SIDE) {
            /* translators: 1: image width, 2: image height, 3: minimum side enforced from 2027. */
            return array(self::warning(
                'image_link',
                'image_below_upcoming_minimum',
                sprintf(
                    __('Imaginea are %1$d×%2$d px; din 31.01.2027 Google va cere minimum %3$d×%3$d px.', 'adc-marketing'),
                    $width,
                    $height,
                    self::IMAGE_UPCOMING_MIN_SIDE
                )
            ));
        }

        return array();
    }

    /**
     * price, sale_price and currency (spec §1.2 B).
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
                __('Lipsește prețul (price), cerut de Google.', 'adc-marketing')
            );
        } elseif (!$has_price) {
            $findings[] = self::error(
                'price',
                'price_invalid',
                __('Prețul trebuie să fie un număr pozitiv, cu punct decimal.', 'adc-marketing')
            );
        }

        $sale = $item['sale_price'] ?? null;
        if ($sale !== null && $sale !== '') {
            $sale_ok = is_numeric($sale) && (float) $sale > 0;

            if (!$sale_ok) {
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
                        'sale_price_above_price',
                        sprintf(
                            __('Prețul redus (%1$s) este mai mare decât prețul de listă (%2$s).', 'adc-marketing'),
                            self::amount($sale_value),
                            self::amount($price_value)
                        )
                    );
                } elseif (abs($sale_value - $price_value) < 0.00001) {
                    $findings[] = self::warning(
                        'sale_price',
                        'sale_price_equals_price',
                        __('Prețul redus este egal cu prețul de listă; trimite sale_price doar la o reducere reală.', 'adc-marketing')
                    );
                }
            }
        }

        $currency = strtoupper((string) self::text($item['currency'] ?? $config['currency'] ?? ''));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $findings[] = self::error(
                'currency',
                'currency_invalid',
                __('Moneda lipsește sau nu este un cod ISO 4217 din trei litere mari (de exemplu RON).', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * availability: enum plus availability_date for preorder (spec §1.2 B).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_availability(array $item): array
    {
        $findings     = array();
        $availability = self::text($item['availability'] ?? null);

        if ($availability === '') {
            $findings[] = self::error(
                'availability',
                'availability_missing',
                __('Lipsește disponibilitatea (availability), cerută de Google.', 'adc-marketing')
            );

            return $findings;
        }

        if (!in_array($availability, self::AVAILABILITY_VALUES, true)) {
            /* translators: %s: submitted availability value. */
            $findings[] = self::error(
                'availability',
                'availability_invalid',
                sprintf(
                    __('Disponibilitatea „%s” nu este acceptată; Google acceptă in_stock, out_of_stock, preorder sau backorder.', 'adc-marketing'),
                    $availability
                )
            );

            return $findings;
        }

        if ($availability !== 'preorder') {
            return $findings;
        }

        $date = self::text($item['availability_date'] ?? null);
        if ($date === '') {
            $findings[] = self::error(
                'availability_date',
                'availability_date_required',
                __('Pentru precomandă (preorder), Google cere availability_date.', 'adc-marketing')
            );

            return $findings;
        }

        if (self::length($date) > self::AVAILABILITY_DATE_MAX_LENGTH || !self::is_iso8601_datetime($date)) {
            $findings[] = self::error(
                'availability_date',
                'availability_date_invalid',
                __('availability_date trebuie să fie în format ISO 8601 (AAAA-LL-ZZThh:mm[+hhmm]), maximum 25 de caractere.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * condition: optional for new products, required when used or refurbished (spec §1.2 E).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_condition(array $item): array
    {
        $condition = self::text($item['condition'] ?? null);
        if ($condition === '' || in_array($condition, self::CONDITION_VALUES, true)) {
            return array();
        }

        /* translators: %s: submitted condition value. */
        return array(self::error(
            'condition',
            'condition_invalid',
            sprintf(
                __('Condiția „%s” nu este acceptată; Google acceptă new, refurbished sau used.', 'adc-marketing'),
                $condition
            )
        ));
    }

    /**
     * brand plus the gtin/mpn identifier rules and identifier_exists (spec §1.2 D).
     *
     * brand is required for new products (movies, books and musical recordings are the
     * documented exceptions); a product that declares identifier_exists=no has no
     * brand/GTIN/MPN by definition, so brand is not demanded for it.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_identifiers(array $item): array
    {
        $findings          = array();
        $identifier_exists = strtolower((string) self::text($item['identifier_exists'] ?? ''));
        $gtin              = self::text($item['gtin'] ?? null);
        $mpn               = self::text($item['mpn'] ?? null);

        if ($identifier_exists !== '' && !in_array($identifier_exists, self::IDENTIFIER_EXISTS_VALUES, true)) {
            $findings[] = self::error(
                'identifier_exists',
                'identifier_exists_invalid',
                __('identifier_exists acceptă doar yes sau no.', 'adc-marketing')
            );
        }

        $brand = self::text($item['brand'] ?? null);
        if ($brand === '') {
            if ($identifier_exists !== 'no') {
                $findings[] = self::error(
                    'brand',
                    'brand_missing',
                    __('Lipsește brandul, cerut de Google pentru produsele noi (excepții: filme, cărți, muzică).', 'adc-marketing')
                );
            }
        } elseif (self::length($brand) > self::BRAND_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum allowed length. */
            $findings[] = self::error(
                'brand',
                'brand_too_long',
                sprintf(
                    __('Brandul are %1$d caractere; Google acceptă maximum %2$d.', 'adc-marketing'),
                    self::length($brand),
                    self::BRAND_MAX_LENGTH
                )
            );
        }

        if ($gtin !== '') {
            $findings = array_merge($findings, self::validate_gtin($gtin));
        }

        if ($mpn !== '') {
            if (self::length($mpn) > self::MPN_MAX_LENGTH) {
                /* translators: 1: current length, 2: maximum allowed length. */
                $findings[] = self::error(
                    'mpn',
                    'mpn_too_long',
                    sprintf(
                        __('MPN-ul are %1$d caractere; Google acceptă maximum %2$d.', 'adc-marketing'),
                        self::length($mpn),
                        self::MPN_MAX_LENGTH
                    )
                );
            }

            if (preg_match('/^[A-Za-z0-9 ._\-\/]+$/', $mpn) !== 1) {
                $findings[] = self::warning(
                    'mpn',
                    'mpn_not_alphanumeric',
                    __('MPN-ul conține caractere nealfanumerice; Google recomandă doar litere, cifre, spații și cratime.', 'adc-marketing')
                );
            }
        }

        if (
            $identifier_exists !== 'no'
            && self::text($item['gtin'] ?? null) === ''
            && self::text($item['mpn'] ?? null) === ''
        ) {
            $findings[] = self::error(
                'gtin',
                'identifier_missing',
                __('Produsul nu are nici GTIN, nici MPN; completează unul dintre ele sau setează identifier_exists=no.', 'adc-marketing')
            );
        }

        return $findings;
    }

    /**
     * GTIN syntax, length and check digit (spec §1.2 D: UPC-12, EAN-13, JAN, ISBN-13, ITF-14).
     *
     * Multiple GTINs may be submitted comma-separated; each value is validated.
     *
     * @param string $gtin Raw gtin attribute.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_gtin(string $gtin): array
    {
        $findings = array();

        if (self::length($gtin) > self::GTIN_MAX_LENGTH) {
            /* translators: 1: current length, 2: maximum overall length, 3: maximum digits per value. */
            $findings[] = self::error(
                'gtin',
                'gtin_too_long',
                sprintf(
                    __('GTIN-ul are %1$d caractere; Google acceptă maximum %2$d caractere, cu maximum %3$d cifre per valoare.', 'adc-marketing'),
                    self::length($gtin),
                    self::GTIN_MAX_LENGTH,
                    self::GTIN_VALUE_MAX_LENGTH
                )
            );
        }

        $values = array_filter(array_map('trim', explode(',', $gtin)), static fn(string $value): bool => $value !== '');
        if ($values === array()) {
            return $findings;
        }

        foreach ($values as $value) {
            if (!ctype_digit($value)) {
                $findings[] = self::error(
                    'gtin',
                    'gtin_not_numeric',
                    __('GTIN-ul trebuie să conțină doar cifre.', 'adc-marketing')
                );
                continue;
            }

            $digits = strlen($value);
            if ($digits > self::GTIN_VALUE_MAX_LENGTH) {
                /* translators: 1: current digit count, 2: maximum allowed digits. */
                $findings[] = self::error(
                    'gtin',
                    'gtin_too_long',
                    sprintf(
                        __('GTIN-ul are %1$d cifre; Google acceptă maximum %2$d cifre per valoare.', 'adc-marketing'),
                        $digits,
                        self::GTIN_VALUE_MAX_LENGTH
                    )
                );
                continue;
            }

            if (!in_array($digits, array(8, 12, 13, 14), true)) {
                /* translators: %d: current digit count. */
                $findings[] = self::error(
                    'gtin',
                    'gtin_invalid_length',
                    sprintf(
                        __('GTIN-ul are %d cifre; sunt acceptate doar 8, 12, 13 sau 14 cifre.', 'adc-marketing'),
                        $digits
                    )
                );
                continue;
            }

            if (!self::gtin_checksum_ok($value)) {
                /* translators: %s: submitted GTIN value. */
                $findings[] = self::error(
                    'gtin',
                    'gtin_invalid_checksum',
                    sprintf(
                        __('GTIN-ul „%s” nu trece verificarea cifrei de control.', 'adc-marketing'),
                        $value
                    )
                );
            }
        }

        return $findings;
    }

    /**
     * google_product_category (optional but recommended) and product_type (spec §1.2 C).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_taxonomy(array $item): array
    {
        $findings   = array();
        $category   = self::text($item['google_product_category'] ?? null);
        $type       = self::text($item['product_type'] ?? null);

        if ($category === '') {
            $findings[] = self::warning(
                'google_product_category',
                'google_product_category_missing',
                __('Lipsește google_product_category; Google îl recomandă și în practică este necesar pentru aprobare.', 'adc-marketing')
            );
        } elseif (!ctype_digit($category) && !str_contains($category, ' > ')) {
            $findings[] = self::warning(
                'google_product_category',
                'google_product_category_format',
                __('google_product_category nu pare a fi un ID numeric sau o cale „A > B > C” din taxonomia Google.', 'adc-marketing')
            );
        }

        if ($type !== '') {
            if (self::length($type) > self::PRODUCT_TYPE_MAX_LENGTH) {
                /* translators: 1: current length, 2: maximum allowed length. */
                $findings[] = self::error(
                    'product_type',
                    'product_type_too_long',
                    sprintf(
                        __('Câmpul product_type are %1$d caractere; Google acceptă maximum %2$d.', 'adc-marketing'),
                        self::length($type),
                        self::PRODUCT_TYPE_MAX_LENGTH
                    )
                );
            }

            if (!str_contains($type, ' > ')) {
                $findings[] = self::warning(
                    'product_type',
                    'product_type_format',
                    __('Câmpul product_type nu folosește formatul „A > B > C”.', 'adc-marketing')
                );
            }
        }

        return $findings;
    }

    /**
     * shipping: required for Shopping ads and free listings in Romania (spec §1.2 G).
     *
     * @param array<string,mixed> $item    Normalized row.
     * @param string              $country Target country, ISO 3166 alpha-2.
     * @param array<string,mixed> $config  Validator configuration.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_shipping(array $item, string $country, array $config): array
    {
        $findings = array();
        $shipping = $item['shipping'] ?? array();

        if (!is_array($shipping)) {
            return array(self::error(
                'shipping',
                'shipping_invalid',
                __('Atributul shipping trebuie să fie o listă de zone de livrare.', 'adc-marketing')
            ));
        }

        $required = array_key_exists('shipping_required', $config)
            ? (bool) $config['shipping_required']
            : in_array($country, self::SHIPPING_REQUIRED_COUNTRIES, true);

        if ($shipping === array()) {
            if ($required) {
                $findings[] = self::error(
                    'shipping',
                    'shipping_required',
                    __('Lipsește atributul shipping; pentru România Google îl cere la Shopping ads și free listings.', 'adc-marketing')
                );
            }

            return $findings;
        }

        $index = 0;
        foreach ($shipping as $zone) {
            ++$index;

            if (!is_array($zone)) {
                /* translators: %d: position of the shipping zone. */
                $findings[] = self::error(
                    'shipping',
                    'shipping_invalid',
                    sprintf(
                        __('Zona de livrare %d nu este o listă de sub-atribute.', 'adc-marketing'),
                        $index
                    )
                );
                continue;
            }

            $zone_country = strtoupper((string) self::text($zone['country'] ?? null));
            if (preg_match('/^[A-Z]{2}$/', $zone_country) !== 1) {
                /* translators: %d: position of the shipping zone. */
                $findings[] = self::error(
                    'shipping',
                    'shipping_country_missing',
                    sprintf(
                        __('Zona de livrare %d nu are țara (country) în format ISO 3166 cu două litere.', 'adc-marketing'),
                        $index
                    )
                );
            }

            if (!array_key_exists('price', $zone) || $zone['price'] === null || $zone['price'] === '') {
                /* translators: %d: position of the shipping zone. */
                $findings[] = self::warning(
                    'shipping',
                    'shipping_price_missing',
                    sprintf(
                        __('Zona de livrare %d nu are preț; Google îl așteaptă pentru afișarea costului de livrare.', 'adc-marketing'),
                        $index
                    )
                );
                continue;
            }

            $price = $zone['price'];
            if (!is_numeric($price) || (float) $price < 0 || (float) $price > self::SHIPPING_PRICE_MAX) {
                /* translators: 1: position of the shipping zone, 2: maximum allowed shipping price. */
                $findings[] = self::error(
                    'shipping',
                    'shipping_price_out_of_range',
                    sprintf(
                        __('Prețul livrării din zona %1$d trebuie să fie un număr între 0 și %2$d.', 'adc-marketing'),
                        $index,
                        self::SHIPPING_PRICE_MAX
                    )
                );
            }
        }

        return $findings;
    }

    /**
     * custom_label_0..4: up to five slots, max 100 characters each (spec §1.2 F, §1.3).
     *
     * The documented limit of 1.000 unique values per slot applies per account, so it
     * cannot be verified from a single row.
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_custom_labels(array $item): array
    {
        $labels = $item['custom_labels'] ?? array();

        if (!is_array($labels)) {
            return array(self::error(
                'custom_labels',
                'custom_labels_invalid',
                __('Etichetele custom trebuie trimise ca listă de valori text.', 'adc-marketing')
            ));
        }

        $findings = array();

        if (count($labels) > self::CUSTOM_LABEL_SLOTS) {
            /* translators: 1: number of submitted labels, 2: maximum allowed slots. */
            $findings[] = self::error(
                'custom_labels',
                'custom_labels_too_many',
                sprintf(
                    __('Sunt %1$d etichete custom; Google acceptă maximum %2$d (custom_label_0 … custom_label_4).', 'adc-marketing'),
                    count($labels),
                    self::CUSTOM_LABEL_SLOTS
                )
            );
        }

        $slot = 0;
        foreach ($labels as $label) {
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
                ++$slot;
                continue;
            }

            $value = trim((string) $label);
            if ($value !== '' && self::length($value) > self::CUSTOM_LABEL_MAX_LENGTH) {
                /* translators: 1: custom label slot number, 2: current length, 3: maximum allowed length. */
                $findings[] = self::error(
                    'custom_labels',
                    'custom_label_too_long',
                    sprintf(
                        __('Eticheta custom_label_%1$d are %2$d caractere; Google acceptă maximum %3$d.', 'adc-marketing'),
                        $slot,
                        self::length($value),
                        self::CUSTOM_LABEL_MAX_LENGTH
                    )
                );
            }

            ++$slot;
        }

        return $findings;
    }

    /**
     * shipping_weight: optional, but must be a positive number in consistent units (spec §1.2 G).
     *
     * @param array<string,mixed> $item Normalized row.
     * @return array<int,array{level:string,field:string,code:string,message:string}>
     */
    private static function validate_measurements(array $item): array
    {
        $weight = $item['weight'] ?? null;
        if ($weight === null || $weight === '') {
            return array();
        }

        if (!is_numeric($weight) || (float) $weight <= 0) {
            return array(self::warning(
                'weight',
                'weight_invalid',
                __('Greutatea trebuie să fie un număr pozitiv, în unități consistente pentru produs.', 'adc-marketing')
            ));
        }

        return array();
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
     * Detect markup or line breaks in a field Google expects as plain text.
     *
     * @param string $value Text to inspect.
     * @return bool True when the text is plain.
     */
    private static function is_plain_text(string $value): bool
    {
        return preg_match('/<[^>]+>/', $value) !== 1 && strpbrk($value, "\r\n") === false;
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

    /**
     * Verify the GTIN check digit for GTIN-8/12/13/14 values.
     *
     * @param string $gtin Digits only.
     * @return bool True when the check digit matches.
     */
    private static function gtin_checksum_ok(string $gtin): bool
    {
        $digits = str_split($gtin);
        if (count($digits) < 8) {
            return false;
        }

        $check  = (int) array_pop($digits);
        $sum    = 0;
        $weight = 3;

        for ($index = count($digits) - 1; $index >= 0; --$index) {
            $sum   += ((int) $digits[$index]) * $weight;
            $weight = 4 - $weight;
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }

    /**
     * Verify an ISO 8601 date-time as documented for availability_date.
     *
     * @param string $value Candidate value.
     * @return bool True when the value matches YYYY-MM-DDThh:mm[+hhmm].
     */
    private static function is_iso8601_datetime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?:[+-]\d{4})?$/', $value) === 1;
    }
}
