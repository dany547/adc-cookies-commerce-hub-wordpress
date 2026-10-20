<?php
/**
 * Discovers cookies set by this site and compares them with known cookies.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Cookie scanner and persistent detected-cookie inventory. */
final class ADC_Cookie_Scanner implements ADC_Module_Interface
{
    private const OPTION = 'adc_cookie_scan';
    private const CRON_HOOK = 'adc_cookie_scan_weekly';

    /** @return array<int, array{pattern: string, wildcard: bool, category: string, provider: string, purpose: string, duration: string}> */
    public static function known_cookies(): array
    {
        $rows = array();
        $add = static function (array $patterns, string $category, string $provider, string $purpose) use (&$rows): void {
            foreach ($patterns as $pattern) {
                $wildcard = str_ends_with($pattern, '*');
                $rows[] = array('pattern' => $pattern, 'wildcard' => $wildcard, 'category' => $category, 'provider' => $provider, 'purpose' => $purpose, 'duration' => '');
            }
        };
        // Purposes are literal gettext calls (a dynamic __($purpose) would be invisible
        // to bin/i18n-build.py and missing from the POT).
        $add(array('wordpress_logged_in_*','wordpress_sec_*','wordpress_test_cookie','wp-settings-*','wp-settings-time-*','comment_author_*','comment_author_email_*','comment_author_url_*','wp_lang','wordpress_*','PHPSESSID'), 'necessary', 'WordPress', __('Asigură autentificarea și funcționarea site-ului.', 'adc-marketing'));
        $add(array('woocommerce_cart_hash','woocommerce_items_in_cart','woocommerce_recently_viewed','wp_woocommerce_session_*','woocommerce_*','store_notice*','tk_*'), 'necessary', 'WooCommerce', __('Păstrează coșul și sesiunea de cumpărături.', 'adc-marketing'));
        $add(array('_fbp','_fbc','fr'), 'marketing', 'Meta', __('Măsoară și personalizează publicitatea Meta.', 'adc-marketing'));
        $add(array('_ga','_ga_*','_gid','_gat*'), 'statistics', 'Google Analytics', __('Măsoară utilizarea site-ului.', 'adc-marketing'));
        $add(array('_gcl_au','_gcl_aw','_gcl_dc','_gcl_*'), 'marketing', 'Google Ads', __('Măsoară eficiența publicității Google.', 'adc-marketing'));
        $add(array('NID','IDE','1P_JAR','AEC','SOCS','CONSENT'), 'marketing', 'Google', __('Reține setări și măsoară publicitatea Google.', 'adc-marketing'));
        $add(array('_ttp','_tt_enable_cookie','ttclid','tt_*'), 'marketing', 'TikTok', __('Măsoară eficiența publicității TikTok.', 'adc-marketing'));
        $add(array('adc_consent'), 'necessary', 'ADC Marketing', __('Reține alegerile privind cookie-urile.', 'adc-marketing'));
        return $rows;
    }

    /** Match exact known names first, then the longest wildcard prefix. */
    public static function match_known(string $name): ?array
    {
        $wildcards = array();
        foreach (self::known_cookies() as $row) {
            if (!$row['wildcard'] && $row['pattern'] === $name) return $row;
            if ($row['wildcard']) $wildcards[] = $row;
        }
        usort($wildcards, static fn(array $a, array $b): int => strlen($b['pattern']) <=> strlen($a['pattern']));
        foreach ($wildcards as $row) {
            if (str_starts_with($name, substr($row['pattern'], 0, -1))) return $row;
        }
        return null;
    }

    /** Scan same-host pages for Set-Cookie headers; cookie values are never retained. */
    public static function scan_headers(array $urls = array()): array
    {
        $home = home_url('/');
        $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
        $targets = array($home);
        if (function_exists('wc_get_page_permalink')) {
            foreach (array('shop', 'cart', 'checkout') as $page) {
                $url = wc_get_page_permalink($page);
                if (is_string($url) && '' !== $url) $targets[] = $url;
            }
        }
        foreach (array_slice($urls, 0, 5) as $url) {
            if (!is_string($url) || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== $host) continue;
            $targets[] = $url;
        }
        $names = array();
        foreach (array_unique($targets) as $url) {
            if (strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== $host) continue;
            $response = wp_remote_get($url, array('timeout' => 8, 'redirection' => 3, 'sslverify' => apply_filters('https_local_ssl_verify', false)));
            if (is_wp_error($response)) continue;
            foreach (wp_remote_retrieve_cookies($response) as $cookie) {
                $name = is_object($cookie) && isset($cookie->name) ? (string) $cookie->name : (is_array($cookie) ? (string) ($cookie['name'] ?? '') : '');
                if ('' !== $name) $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    /** Persist cookie names only and return count newly detected. */
    public static function record(array $names, string $source): int
    {
        if (!in_array($source, array('headers', 'browser', 'known'), true)) $source = 'headers';
        $data = get_option(self::OPTION, array());
        $cookies = is_array($data['cookies'] ?? null) ? $data['cookies'] : array();
        $count = 0; $now = time();
        foreach (array_slice(array_values(array_unique($names)), 0, 500) as $name) {
            if (!is_string($name) || 1 !== preg_match('/^[A-Za-z0-9_.%\-]{1,128}$/', $name)) continue;
            if (!isset($cookies[$name])) {
                $cookies[$name] = array('name' => $name, 'first_seen' => $now, 'last_seen' => $now, 'source' => $source, 'status' => self::inventory_match($name) ? 'classified' : 'new');
                $count++;
            } else {
                $cookies[$name]['last_seen'] = $now;
                $cookies[$name]['source'] = $source;
                if ('ignored' !== ($cookies[$name]['status'] ?? '')) $cookies[$name]['status'] = self::inventory_match($name) ? 'classified' : 'new';
            }
        }
        $data['last_scan'] = $now; $data['cookies'] = $cookies;
        update_option(self::OPTION, $data, false);
        return $count;
    }

    /** Detected cookies enriched with suggestions and user inventory matches. */
    public static function detected(): array
    {
        $data = get_option(self::OPTION, array()); $rows = array();
        foreach (($data['cookies'] ?? array()) as $name => $cookie) {
            $cookie['suggestion'] = self::match_known((string) $name);
            $cookie['inventory_row'] = self::inventory_match((string) $name);
            $rows[] = $cookie;
        }
        return $rows;
    }

    /** Update a detected-cookie status. */
    public static function set_status(string $name, string $status): bool
    {
        if (!in_array($status, array('ignored', 'new'), true)) return false;
        $data = get_option(self::OPTION, array());
        if (!isset($data['cookies'][$name])) return false;
        $data['cookies'][$name]['status'] = $status;
        return update_option(self::OPTION, $data, false);
    }

    /** Validate and save user-confirmed cookie inventory rows. */
    public static function save_to_inventory(array $rows): array
    {
        $saved = 0; $errors = array(); $inventory = ADC_Consent_Banner::inventory();
        foreach ($rows as $row) {
            if (!is_array($row)) { $errors[] = __('Rând invalid.', 'adc-marketing'); continue; }
            $name = trim((string) ($row['name'] ?? '')); $category = (string) ($row['category'] ?? '');
            // Same charset/length the `banner` storage layer accepts (ADC_Consent_Banner::sanitize_inventory);
            // a looser rule here would report success and then silently drop the row on save.
            if (1 !== preg_match('/^[A-Za-z0-9_.\-]{1,64}\*?$/', $name)) { $errors[] = __('Numele cookie-ului nu este valid.', 'adc-marketing'); continue; }
            if (!in_array($category, ADC_Consent_Banner::CATEGORIES, true)) { $errors[] = __('Categoria cookie-ului nu este validă.', 'adc-marketing'); continue; }
            $wildcard = str_ends_with($name, '*');
            $clean_name = $wildcard ? substr($name, 0, -1) : $name;
            $entry = array('name' => $clean_name, 'category' => $category, 'provider' => sanitize_text_field((string) ($row['provider'] ?? '')), 'purpose' => sanitize_text_field((string) ($row['purpose'] ?? '')), 'duration' => sanitize_text_field((string) ($row['duration'] ?? '')), 'wildcard' => $wildcard);
            $replaced = false;
            foreach ($inventory as $i => $existing) if (($existing['name'] ?? '') === $clean_name) { $entry['wildcard'] = $entry['wildcard'] || (bool) ($existing['wildcard'] ?? false); $inventory[$i] = $entry; $replaced = true; break; }
            if (!$replaced) $inventory[] = $entry;
            $saved++;
        }
        if ($saved > 0) {
            ADC_Settings::update('banner', array_merge(ADC_Settings::all('banner'), array('inventory' => $inventory)));
            $data = get_option(self::OPTION, array());
            foreach ($data['cookies'] ?? array() as $name => &$cookie) if (self::inventory_match((string) $name, $inventory)) $cookie['status'] = 'classified';
            unset($cookie); update_option(self::OPTION, $data, false);
        }
        return array('saved' => $saved, 'errors' => $errors);
    }

    /** Number of detected cookies awaiting classification. */
    public static function unclassified_count(): int
    {
        $count = 0; foreach (self::detected() as $row) if (($row['status'] ?? '') === 'new') $count++;
        return $count;
    }

    /** Register the weekly scan callback. */
    public static function boot(): void
    {
        add_action('init', array(self::class, 'schedule_weekly'));
        add_action(self::CRON_HOOK, array(self::class, 'run_weekly_scan'));
    }

    /** Schedule the scan once WordPress is initialized. */
    public static function schedule_weekly(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time(), 'weekly', self::CRON_HOOK);
    }

    /** Scan configured site pages from the weekly cron callback. */
    public static function run_weekly_scan(): void
    {
        self::record(self::scan_headers(), 'headers');
    }

    /** Remove the scheduled scan. */
    public static function unschedule(): void { wp_clear_scheduled_hook(self::CRON_HOOK); }

    /** Find exact or prefix-matching user inventory row. */
    private static function inventory_match(string $name, ?array $inventory = null): ?array
    {
        foreach ($inventory ?? ADC_Consent_Banner::inventory() as $row) {
            if (($row['name'] ?? '') === $name || (!empty($row['wildcard']) && str_starts_with($name, (string) ($row['name'] ?? '')))) return $row;
        }
        return null;
    }
}
