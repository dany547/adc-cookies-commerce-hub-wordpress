<?php
/**
 * Server-side traffic collector: exclusions, bot/prefetch detection, page
 * dimension and referer channel classification.
 *
 * Nothing is counted while ADC_Analytics_Settings::traffic_enabled() is false
 * (switch off, or the privacy confirmation missing). The collectors are pure and
 * testable. The collector never sets cookies, reads sessions, user IDs or IP
 * addresses, ingests UTM parameters, and never stores the user agent or the
 * referer URL: only daily aggregates per page type and channel are written.
 *
 * Privacy signals are honoured: a request sending `Sec-GPC: 1` or `DNT: 1` is
 * never counted, and the admin-configurable exclusions (logged-in
 * administrators, bot filtering) are applied when enabled.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Anonymous, aggregate-only traffic collector (gated, see class header).
 */
final class ADC_Analytics_Traffic implements ADC_Module_Interface
{
    /** Search engine hosts, exact match after removing a leading "www.". */
    private const SEARCH_HOSTS = array(
        'google.com',
        'google.ro',
        'bing.com',
        'duckduckgo.com',
        'search.yahoo.com',
    );

    /** Social network hosts, exact match after removing a leading "www.". */
    private const SOCIAL_HOSTS = array(
        'facebook.com',
        'm.facebook.com',
        'instagram.com',
        'linkedin.com',
        'tiktok.com',
        'pinterest.com',
        'x.com',
        'twitter.com',
    );

    /** Conservative crawler, headless and tool user-agent pattern. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|headless|preview|curl|wget|python|go-http|java\/|libwww|httpclient|monitor/i';

    /** Page types that may be stored. */
    private const PAGE_TYPES = array(
        'home', 'blog', 'post', 'page', 'product', 'product_cat', 'product_tag',
        'category', 'tag', 'shop', 'archive', 'other',
    );

    /**
     * Hook the collector on the frontend template stage.
     */
    public static function boot(): void
    {
        add_action('template_redirect', array(self::class, 'maybe_count'), 20);
    }

    /**
     * Count one page view when every rule passes. Silent on any failure.
     */
    public static function maybe_count(): void
    {
        if (!ADC_Analytics_Settings::traffic_enabled()) {
            return;
        }

        if (self::is_excluded_request()) {
            return;
        }

        if (self::bot_filter_enabled() && self::is_bot(self::user_agent())) {
            return;
        }

        $dimension = self::page_dimension();
        $referer   = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
        $site_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $channel   = self::channel_from_referer($referer, $site_host);

        $saved = ADC_Analytics_Store::increment_traffic(
            self::current_stat_date(),
            self::current_hour(),
            $channel,
            $dimension['page_type'],
            $dimension['object_id']
        );

        if (!$saved && class_exists('ADC_Logger')) {
            ADC_Logger::log('error', 'Analytics traffic write failed.');
        }
    }

    /**
     * True when the request must never be counted.
     */
    public static function is_excluded_request(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (is_feed() || is_robots() || is_trackback() || is_preview() || is_customize_preview()) {
            return true;
        }

        if (is_404() || is_search()) {
            return true;
        }

        if (self::is_account_or_commerce_request()) {
            return true;
        }

        if (self::exclude_admins_enabled() && current_user_can('manage_options')) {
            return true;
        }

        // Do Not Track / Global Privacy Control: the visitor opted out browser-wide.
        if (self::is_privacy_mode()) {
            return true;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'GET' || self::is_prefetch()) {
            return true;
        }

        if (self::has_query_string()) {
            return true;
        }

        if (self::is_non_html_request()) {
            return true;
        }

        return self::is_api_path();
    }

    /**
     * True when the visitor's browser signals privacy: `Sec-GPC: 1` (Global
     * Privacy Control) or `DNT: 1` (Do Not Track). Any other value, an empty
     * header or a missing header is not an opt-out.
     */
    public static function is_privacy_mode(): bool
    {
        foreach (array('HTTP_SEC_GPC', 'HTTP_DNT') as $key) {
            if (isset($_SERVER[$key]) && '1' === trim((string) wp_unslash($_SERVER[$key]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Raw user agent of the current request (inspected only, never stored).
     */
    private static function user_agent(): string
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    }

    /**
     * Bot filtering toggle; on unless the shop stored an explicit false.
     */
    private static function bot_filter_enabled(): bool
    {
        return (bool) ADC_Settings::get(ADC_Analytics_Settings::GROUP, 'bot_filter_enabled', true);
    }

    /**
     * Logged-in administrator exclusion; on unless stored off.
     */
    private static function exclude_admins_enabled(): bool
    {
        return (bool) ADC_Settings::get(ADC_Analytics_Settings::GROUP, 'exclude_logged_in_admins', true);
    }

    /**
     * Bot check on a user agent. An empty agent counts as a bot. The agent
     * is only inspected here and is never stored.
     */
    public static function is_bot(string $user_agent): bool
    {
        if ($user_agent === '') {
            return true;
        }

        return preg_match(self::BOT_PATTERN, $user_agent) === 1;
    }

    /**
     * True for browser prefetch or prerender requests.
     */
    public static function is_prefetch(): bool
    {
        $headers = array(
            isset($_SERVER['HTTP_SEC_PURPOSE']) ? (string) $_SERVER['HTTP_SEC_PURPOSE'] : '',
            isset($_SERVER['HTTP_PURPOSE']) ? (string) $_SERVER['HTTP_PURPOSE'] : '',
            isset($_SERVER['HTTP_X_PURPOSE']) ? (string) $_SERVER['HTTP_X_PURPOSE'] : '',
            isset($_SERVER['HTTP_X_MOZ']) ? (string) $_SERVER['HTTP_X_MOZ'] : '',
        );

        foreach ($headers as $value) {
            if (preg_match('/prefetch|prerender/i', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whitelisted page dimension for the current main query.
     *
     * @return array{page_type:string,object_id:int}
     */
    public static function page_dimension(): array
    {
        $other = array('page_type' => 'other', 'object_id' => 0);
        $id    = (int) get_queried_object_id();

        if (is_front_page()) {
            return array('page_type' => 'home', 'object_id' => 0);
        }

        if (is_home()) {
            return array('page_type' => 'blog', 'object_id' => 0);
        }

        if (function_exists('is_shop') && is_shop()) {
            return array('page_type' => 'shop', 'object_id' => 0);
        }

        if (is_singular('product')) {
            return ($id > 0) ? array('page_type' => 'product', 'object_id' => $id) : $other;
        }

        if (is_singular('post')) {
            return ($id > 0) ? array('page_type' => 'post', 'object_id' => $id) : $other;
        }

        if (is_singular('page')) {
            return ($id > 0) ? array('page_type' => 'page', 'object_id' => $id) : $other;
        }

        if (is_tax('product_cat')) {
            return self::term_dimension('product_cat');
        }

        if (is_tax('product_tag')) {
            return self::term_dimension('product_tag');
        }

        if (is_category()) {
            return self::term_dimension('category');
        }

        if (is_tag()) {
            return self::term_dimension('tag');
        }

        if (is_post_type_archive('product')) {
            return array('page_type' => 'shop', 'object_id' => 0);
        }

        if (is_archive()) {
            return array('page_type' => 'archive', 'object_id' => 0);
        }

        return $other;
    }

    /**
     * Classify a referer into one channel. Only the host is parsed and only
     * the channel label is returned, so no URL, path or query is retained.
     *
     * Returns one of: direct, unknown, internal, search, social, referral.
     */
    public static function channel_from_referer(string $referer, string $site_host): string
    {
        if (trim($referer) === '') {
            return 'direct';
        }

        $parts = wp_parse_url($referer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'unknown';
        }

        if (!in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)) {
            return 'unknown';
        }

        $host = self::normalize_host((string) $parts['host']);
        if ($host === '') {
            return 'unknown';
        }

        if ($host === self::normalize_host($site_host)) {
            return 'internal';
        }

        if (in_array($host, self::SEARCH_HOSTS, true)) {
            return 'search';
        }

        if (in_array($host, self::SOCIAL_HOSTS, true)) {
            return 'social';
        }

        return 'referral';
    }

    /**
     * UTM ingestion is not part of this release. Always returns empty values
     * and reads no query parameter.
     *
     * @return array{source:string,medium:string,campaign:string}
     */
    public static function campaign_dimension(): array
    {
        return array('source' => '', 'medium' => '', 'campaign' => '');
    }

    /**
     * Stat date in the site timezone, Y-m-d.
     */
    public static function current_stat_date(): string
    {
        return (string) wp_date('Y-m-d');
    }

    /**
     * Hour of day in the site timezone, 0-23.
     */
    public static function current_hour(): int
    {
        return (int) wp_date('G');
    }

    /**
     * True for account, cart, checkout, order and WooCommerce endpoint pages.
     */
    private static function is_account_or_commerce_request(): bool
    {
        $checks = array(
            'is_account_page',
            'is_cart',
            'is_checkout',
            'is_order_received_page',
            'is_checkout_pay_page',
            'is_add_payment_method_page',
            'is_wc_endpoint_url',
        );

        foreach ($checks as $check) {
            if (function_exists($check) && $check()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Excluded when the URL carries any query string.
     */
    private static function has_query_string(): bool
    {
        if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
            return true;
        }

        return isset($_SERVER['REQUEST_URI']) && str_contains((string) $_SERVER['REQUEST_URI'], '?');
    }

    /**
     * Excluded when the client sent an Accept header without text/html.
     */
    private static function is_non_html_request(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT']) || trim((string) $_SERVER['HTTP_ACCEPT']) === '') {
            return false;
        }

        return !str_contains((string) $_SERVER['HTTP_ACCEPT'], 'text/html');
    }

    /**
     * Excluded for REST, WooCommerce API and other API-style paths.
     */
    private static function is_api_path(): bool
    {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';

        return str_contains($path, '/wp-json') || str_contains($path, '/wc-api') || str_contains($path, '/wp-admin');
    }

    /**
     * Dimension for a taxonomy archive, with a deleted-object fallback.
     *
     * @return array{page_type:string,object_id:int}
     */
    private static function term_dimension(string $page_type): array
    {
        $term = get_queried_object();
        $id   = (is_object($term) && isset($term->term_id)) ? (int) $term->term_id : 0;

        return ($id > 0) ? array('page_type' => $page_type, 'object_id' => $id) : array('page_type' => 'other', 'object_id' => 0);
    }

    /**
     * Lowercase host, without a trailing dot or a leading "www.".
     */
    private static function normalize_host(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
