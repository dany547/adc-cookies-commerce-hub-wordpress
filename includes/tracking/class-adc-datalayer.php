<?php
/**
 * dataLayer bootstrap: head scripts, page context, server-event delivery (§3.1, §2.4).
 *
 * Print order in <head> (wp_head priority 0), MUST hold:
 *   consent-default inline script (ADC_Consent_Mode or built-in fallback)
 *   → adc_consent_init push → GTM snippet (ADC_Gtm).
 * Footer prints the cache-safe page context and the queued server-origin
 * events; datalayer.js then pushes them with the server's event_id.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end dataLayer orchestrator (head/footer output + script enqueues).
 */
final class ADC_Datalayer implements ADC_Module_Interface
{
    /**
     * Per-request footer queue (server-origin events with a page target,
     * e.g. the thank-you purchase push). Separate from the WC session queue.
     *
     * @var ADC_Event[]
     */
    private static array $footer_queue = array();

    /**
     * Boot the module.
     */
    public static function boot(): void
    {
        if (ADC_Gtm::disabled()) {
            return; // Kill switch: no dataLayer/GTM/pixel output at all.
        }

        add_action('wp_head', array(self::class, 'print_head'), 0);
        add_action('wp_footer', array(self::class, 'print_footer'), 5);
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue_assets'), 20); // After C2/C3 script registration (prio 10).
        add_filter('adc_frontend_config', array(self::class, 'filter_config'));
    }

    /**
     * Queue a server-origin event for THIS request's footer (uncached pages
     * only, e.g. order-received). Never mirrored by the browser.
     *
     * @param ADC_Event $event Canonical event (server event_id).
     */
    public static function queue_footer(ADC_Event $event): void
    {
        self::$footer_queue[] = $event;
    }

    /**
     * Head output: consent-default script → GTM snippet (§2.4 push order).
     * The byte order is what makes Consent Mode defaults precede gtm.js.
     */
    public static function print_head(): void
    {
        if (ADC_Gtm::disabled()) {
            return;
        }

        $script = class_exists('ADC_Consent_Mode')
            ? (string) ADC_Consent_Mode::render_head_script()
            : self::fallback_consent_script();

        if ('' !== $script) {
            if (str_contains($script, '<script')) {
                // C2 returned full markup — print as-is.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted plugin-generated script block.
                echo $script;
            } else {
                wp_print_inline_script_tag($script);
            }
        }

        // MUST follow the consent script (§3.1 / C4 verification order check).
        if (class_exists('ADC_Gtm')) {
            ADC_Gtm::print_snippet();
        }
    }

    /**
     * Footer output: page context JSON + queued server events (§3.1).
     */
    public static function print_footer(): void
    {
        if (ADC_Gtm::disabled()) {
            return;
        }

        $context = self::page_context();

        // L3: cart/checkout/order-received footers embed per-visitor data
        // (cart JSON, purchase push) — forbid page caching on those pages
        // regardless of how the cache plugin treats WC cart cookies.
        if (
            in_array((string) ($context['page_type'] ?? ''), array('cart', 'checkout', 'order_received'), true)
            && !defined('DONOTCACHEPAGE')
        ) {
            define('DONOTCACHEPAGE', true);
        }

        if (!empty($context)) {
            $json = wp_json_encode($context, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($json)) {
                wp_print_inline_script_tag('window.adcPageData = ' . $json . ';');
            }
        }

        $events = ADC_Pending_Events::pull(); // Session queue (consume once, §2.4).
        foreach (self::$footer_queue as $event) {
            $events[] = $event;
        }

        $pushes = self::to_pushes($events);
        if (!empty($pushes)) {
            // A page embedding server-origin events must not be page-cached,
            // or a later visitor would replay them (§3.1 cache-safety).
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            $json = wp_json_encode($pushes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($json)) {
                wp_print_inline_script_tag('window.adcServerEvents = ' . $json . ';');
            }
        }
    }

    /**
     * Convert raw/serialised server events into ready-to-push dataLayer
     * objects via ADC_Event_Builder::to_datalayer_push (applies the
     * `adc_datalayer_event` filter — C8 adds channel opt-ins here).
     *
     * @param array $events ADC_Event[] or serialised arrays.
     * @return array<int, array<string, mixed>>
     */
    public static function to_pushes(array $events): array
    {
        $pushes = array();
        foreach ($events as $raw) {
            $event = $raw instanceof ADC_Event ? $raw : ADC_Event::from_array(is_array($raw) ? $raw : array());
            if ('' === $event->name || '' === $event->event_id) {
                continue;
            }
            $pushes[] = ADC_Event_Builder::to_datalayer_push($event);
        }

        return $pushes;
    }

    /**
     * Enqueue datalayer.js + events.js (both defer; events depends on
     * datalayer, datalayer on adc-consent when the banner chunk is present).
     */
    public static function enqueue_assets(): void
    {
        if (ADC_Gtm::disabled()) {
            return;
        }

        $version = defined('ADC_MARKETING_VERSION') ? (string) ADC_MARKETING_VERSION : null;
        $base    = defined('ADC_MARKETING_PLUGIN_URL') ? rtrim(ADC_MARKETING_PLUGIN_URL, '/') . '/assets/js/' : '';

        $consent_deps = wp_script_is('adc-consent', 'registered') ? array('adc-consent') : array();

        wp_register_script('adc-datalayer', $base . 'datalayer.js', $consent_deps, $version, array(
            'in_footer' => false,
            'strategy'  => 'defer',
        ));
        wp_register_script('adc-events', $base . 'events.js', array('adc-datalayer'), $version, array(
            'in_footer' => false,
            'strategy'  => 'defer',
        ));

        // Exactly one window.adcConfig copy: attach to whichever handle is first.
        if (wp_script_is('adc-consent', 'registered')) {
            ADC_Frontend_Config::attach('adc-consent');
        } else {
            ADC_Frontend_Config::attach('adc-datalayer');
        }

        wp_enqueue_script('adc-datalayer');
        wp_enqueue_script('adc-events');
    }

    /**
     * Add the C4-owned `events` key to window.adcConfig (§2.3).
     *
     * @param array $config Frontend config.
     * @return array
     */
    public static function filter_config(array $config): array
    {
        $events = ADC_Settings::all('events');

        $flags = array();
        foreach (self::event_names() as $name) {
            $flags[$name] = (bool) ($events[$name] ?? true);
        }

        $config['events'] = array_merge(
            $flags,
            array(
                'server_page_view' => (bool) ($events['server_page_view'] ?? false),
                'itemIdSource'     => (string) ($events['item_id_source'] ?? 'id'),
                'valueMode'        => (string) ($events['value_mode'] ?? 'excl_tax_excl_shipping'),
                // D6: which channel GA4 purchase uses — surfaced so browser
                // pushes can carry the adc.purchase_channel marker.
                'purchase_channel' => in_array(
                    (string) ADC_Settings::get('ga4', 'purchase_channel', 'browser'),
                    array('browser', 'server'),
                    true
                ) ? (string) ADC_Settings::get('ga4', 'purchase_channel', 'browser') : 'browser',
            )
        );

        return $config;
    }

    /**
     * Every catalogue event name the events group toggles (§2.4 table).
     *
     * @return string[]
     */
    private static function event_names(): array
    {
        return array(
            'view_item_list',
            'select_item',
            'view_item',
            'add_to_cart',
            'remove_from_cart',
            'view_cart',
            'begin_checkout',
            'add_shipping_info',
            'add_payment_info',
            'purchase',
            'refund',
            'search',
            'sign_up',
            'login',
            'generate_lead',
        );
    }

    /**
     * Cache-safe page context for this render (§2.4 adc_page payload plus
     * embedded item/list/cart data). Catalog pages carry catalog data only;
     * cart/checkout pages are never page-cached (WC cart cookies bypass cache).
     *
     * @return array<string, mixed>
     */
    private static function page_context(): array
    {
        $context = array(
            'page_type' => ADC_Event_Builder::page_type(),
            'language'  => str_replace('_', '-', (string) get_locale()),
            'currency'  => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON',
            'logged_in' => is_user_logged_in(),
        );

        $page_type = (string) $context['page_type'];

        if ('product' === $page_type) {
            $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : false;
            if ($product instanceof WC_Product) {
                $context['item']     = ADC_Event_Builder::product_to_item($product, array('qty' => 1));
                $context['item_ref'] = array(
                    'product_id'  => $product->get_id(),
                    'variation_id' => $product instanceof WC_Product_Variation ? $product->get_id() : 0,
                );
            }

            return $context;
        }

        if (in_array($page_type, array('cart', 'checkout'), true)) {
            $cart = ADC_Event_Builder::cart_page_data();
            if (null !== $cart) {
                $context['cart'] = $cart;
            }

            return $context;
        }

        if (in_array($page_type, array('category', 'search'), true)) {
            $list = self::loop_context($page_type);
            if (null !== $list) {
                $context['lists'] = array($list);
            }
        }

        return $context;
    }

    /**
     * Main loop items for archive/search pages (catalog data only, ≤ 20).
     * `links` maps permalink => item index so events.js can wire select_item
     * without theme-provided data attributes.
     *
     * @param string $page_type 'category' or 'search'.
     * @return array<string, mixed>|null
     */
    private static function loop_context(string $page_type): ?array
    {
        global $wp_query;

        if (!$wp_query instanceof WP_Query || empty($wp_query->posts)) {
            return null;
        }

        if ('search' === $page_type) {
            $list_id   = 'search-results';
            $list_name = (string) get_search_query();
        } elseif (function_exists('is_product_category') && is_product_category()) {
            $term      = get_queried_object();
            $list_id   = $term instanceof WP_Term ? (string) $term->term_id : 'category';
            $list_name = $term instanceof WP_Term ? (string) $term->name : '';
        } else {
            $list_id   = 'shop';
            $list_name = (string) get_the_title((int) get_option('page_on_front'));
            if ('' === $list_name) {
                $list_name = 'Shop';
            }
        }

        $items = array();
        $links = array();

        foreach (array_slice($wp_query->posts, 0, 20) as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : false;
            if (!$product instanceof WC_Product) {
                continue;
            }

            $index = count($items);
            $items[] = ADC_Event_Builder::product_to_item(
                $product,
                array(
                    'qty'            => 1,
                    'index'          => $index,
                    'item_list_id'   => $list_id,
                    'item_list_name' => $list_name,
                )
            );

            $permalink = get_permalink($post);
            if (is_string($permalink)) {
                $links[$permalink] = $index;
            }
        }

        if (empty($items)) {
            return null;
        }

        return array(
            'list_id'   => $list_id,
            'list_name' => $list_name,
            'items'     => $items,
            'links'     => $links,
        );
    }

    /**
     * Built-in consent-default head script used only while C2
     * (ADC_Consent_Mode::render_head_script) is not available: mirrors §3.1
     * inline#1 — dataLayer/gtag init, consent defaults denied, optional
     * cookie-based consent update, then an UNCONDITIONAL adc_consent_init
     * push (undecided state when no valid cookie) — so the verified push
     * order (adc_consent_init before gtm.js) holds standalone too.
     *
     * @return string JavaScript (no <script> tags).
     */
    private static function fallback_consent_script(): string
    {
        $wait   = (int) ADC_Settings::get('consent', 'wait_for_update', 500);
        $policy = (int) ADC_Settings::get('consent', 'policy_version', 1);
        $passth = (bool) ADC_Settings::get('consent', 'url_passthrough', true);
        $redact = (bool) ADC_Settings::get('consent', 'ads_data_redaction', true);
        $cookie = 'adc_consent'; // Fixed cookie name per §2.2.

        $static = array(
            'defaults' => array(
                'ad_storage'              => 'denied',
                'ad_user_data'            => 'denied',
                'ad_personalization'      => 'denied',
                'analytics_storage'       => 'denied',
                'functionality_storage'   => 'denied',
                'personalization_storage' => 'denied',
                'security_storage'        => 'granted',
                'wait_for_update'         => $wait,
            ),
            'cookie'   => $cookie,
            'policy'   => $policy,
            'passthru' => $passth,
            'redact'   => $redact,
        );

        $json = wp_json_encode($static, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) {
            return '';
        }

        return 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.gtag=gtag;'
            . 'gtag("consent","default",' . $json . '.defaults);'
            . '(function(s){if(s.passthru)gtag("set","url_passthrough",true);if(s.redact)gtag("set","ads_data_redaction",true);'
            . 'var u={analytics_storage:"denied",ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",'
            . 'functionality_storage:"denied",personalization_storage:"denied",security_storage:"granted"};'
            . 'var c={preferences:false,statistics:false,marketing:false,decided:false,ts:0,policy:s.policy,source:""};'
            . 'var m=document.cookie.match("(?:^|;\\s*)"+s.cookie+"=([^;]*)");'
            . 'if(m){try{var p={};decodeURIComponent(m[1]).split("&").forEach(function(kv){var a=kv.split("=");p[a[0]]=a[1];});'
            . 'if(p.v==="1"&&p.cid&&parseInt(p.pv||"0",10)>=s.policy){'
            . 'u.analytics_storage=p.s==="1"?"granted":"denied";'
            . 'u.ad_storage=u.ad_user_data=u.ad_personalization=p.m==="1"?"granted":"denied";'
            . 'u.functionality_storage=u.personalization_storage=p.p==="1"?"granted":"denied";'
            . 'gtag("consent","update",u);'
            . 'c={preferences:p.p==="1",statistics:p.s==="1",marketing:p.m==="1",decided:true,'
            . 'ts:parseInt(p.ts||"0",10),policy:parseInt(p.pv||"0",10),source:p.src||""};}}catch(e){}}'
            . 'dataLayer.push({event:"adc_consent_init",adc_consent:c,gcm:u});})(' . $json . ');';
    }
}
