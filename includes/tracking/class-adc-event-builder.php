<?php
/**
 * Canonical event builder shared by browser and server origins (docs/ARCHITECTURE.md §3, §8).
 *
 * One builder guarantees identical GA4 item/value semantics on both sides
 * (§8 value_mode). Never emits raw PII: user fields come from
 * ADC_Advanced_Matching (hashed) and only with marketing consent.
 *
 * @package ADC_Marketing
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds ADC_Event objects and GA4-shaped dataLayer pushes.
 */
final class ADC_Event_Builder
{
    /**
     * Build the server-origin purchase event for an order (§3.4).
     *
     * event_id = ADC_Event_Id::for_order( $order_id ) — shared with the
     * browser push on the thank-you page so platforms dedup (§2.4, D6).
     *
     * @param WC_Order $order  Order (HPOS-safe CRUD only).
     * @param string   $origin 'server' (pipeline) or 'browser' (thank-you push).
     * @return ADC_Event|null Null when the order cannot be loaded/has no lines.
     */
    public static function from_order(WC_Order $order, string $origin = 'server'): ?ADC_Event
    {
        $order_id = $order->get_id();
        if ($order_id <= 0) {
            return null;
        }

        $currency = $order->get_currency() ?: (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON');
        $items    = self::items_from_order($order);
        $mode     = self::value_mode();

        $tax     = (float) $order->get_total_tax();
        $shipping = (float) $order->get_shipping_total();
        $value   = 'incl_tax' === $mode
            ? self::sum_items_incl_tax($order)
            : self::sum_items_excl_tax($order);

        $coupons = array();
        foreach ($order->get_coupon_codes() as $code) {
            $coupons[] = (string) $code;
        }

        $state = class_exists('ADC_Consent') ? ADC_Consent::for_order($order) : null;
        $cid   = (string) $order->get_meta('_adc_consent_cid');
        if ('' === $cid && null !== $state) {
            $cid = $state->cid;
        }

        $user = null;
        if (class_exists('ADC_Advanced_Matching') && self::marketing_allowed($state)) {
            $user = ADC_Advanced_Matching::from_order($order);
            if (class_exists('ADC_Consent')) {
                $session_user = ADC_Advanced_Matching::from_session();
                $user         = $user->merge($session_user); // order billing wins (§3.4).
            }
        }

        $attribution = array();
        if (class_exists('ADC_Order_Context')) {
            $attribution = ADC_Order_Context::read($order);
        }

        $params = array(
            'transaction_id' => (string) $order_id,
            'tax'            => $tax,
            'shipping'       => $shipping,
            'order_total'    => (float) $order->get_total(), // D12: Meta/TikTok Purchase value.
        );
        if (!empty($coupons)) {
            $params['coupon'] = implode(',', $coupons);
        }

        // M1: server-origin purchase events must not carry the order-received
        // URL — it embeds the secret order key (capability URL). Send a
        // non-sensitive checkout URL instead; the key URL is kept only for
        // browser origin, where it is already in the visitor's address bar.
        $source_url = 'browser' === $origin
            ? (string) $order->get_checkout_order_received_url()
            : (function_exists('wc_get_checkout_url') ? (string) wc_get_checkout_url() : home_url('/'));

        return new ADC_Event(
            'purchase',
            ADC_Event_Id::for_order($order_id),
            time(),
            $origin,
            $source_url,
            $currency,
            $value,
            $items,
            $order_id,
            $user,
            $attribution,
            $cid,
            null !== $state ? $state->to_array() : array(),
            $params
        );
    }

    /**
     * GA4 item array for a single product (§2.4 ITEM schema).
     *
     * item_id honours events.item_id_source (id|sku|gla; falls back to the
     * product id when the source value is empty). Price semantics follow
     * events.value_mode. Applies the `adc_event_items` filter.
     *
     * @param WC_Product $product Product or variation.
     * @param array      $context qty, index, item_list_id, item_list_name, discount, coupon.
     * @return array<string, mixed>
     */
    public static function product_to_item(WC_Product $product, array $context = array()): array
    {
        $qty = max(1, (int) ($context['qty'] ?? 1));
        $mode = self::value_mode();

        $price = 'incl_tax' === $mode
            ? (float) wc_get_price_including_tax($product, array('qty' => $qty))
            : (float) wc_get_price_excluding_tax($product, array('qty' => $qty));

        $item = array(
            'item_id'   => self::item_id_for($product),
            'item_name' => (string) $product->get_name(),
            'price'     => $price,
            'quantity'  => $qty,
        );

        $brand = self::brand_name($product);
        if ('' !== $brand) {
            $item['item_brand'] = $brand;
        }

        foreach (self::category_path($product) as $i => $category) {
            if ($i > 4) {
                break; // GA4 supports item_category..item_category5.
            }
            $item[0 === $i ? 'item_category' : 'item_category' . ($i + 1)] = $category;
        }

        $variant = self::variant_label($product);
        if ('' !== $variant) {
            $item['item_variant'] = $variant;
        }

        if (isset($context['discount']) && (float) $context['discount'] > 0) {
            $item['discount'] = (float) $context['discount'];
        }
        if (!empty($context['coupon'])) {
            $item['coupon'] = (string) $context['coupon'];
        }
        if (isset($context['index'])) {
            $item['index'] = (int) $context['index'];
        }
        if (!empty($context['item_list_id'])) {
            $item['item_list_id'] = (string) $context['item_list_id'];
        }
        if (!empty($context['item_list_name'])) {
            $item['item_list_name'] = (string) $context['item_list_name'];
        }

        $items = apply_filters('adc_event_items', array($item), $product);
        $items = is_array($items) ? $items : array($item);

        return is_array($items[0] ?? null) ? $items[0] : $item;
    }

    /**
     * Items for every order line (HPOS-safe: WC_Order CRUD only).
     *
     * @param WC_Order $order Order.
     * @return array<int, array<string, mixed>>
     */
    public static function items_from_order(WC_Order $order): array
    {
        $mode  = self::value_mode();
        $items = array();
        $index = 0;

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $qty      = max(1, (int) $item->get_quantity());
            $subtotal = (float) $item->get_subtotal(); // ex-tax, before line discount.
            $total    = (float) $item->get_total();    // ex-tax, after discount.
            $tax_total = (float) $item->get_total_tax();

            $price = 'incl_tax' === $mode
                ? ($total + $tax_total) / $qty
                : $total / $qty;

            $product = $item->get_product();
            if ($product instanceof WC_Product) {
                $entry = self::product_to_item(
                    $product,
                    array(
                        'qty'     => $qty,
                        'index'   => $index,
                        'coupon'  => implode(',', $order->get_coupon_codes()),
                        'discount' => 'incl_tax' === $mode
                            ? ($subtotal - $total) + $tax_total
                            : ($subtotal - $total),
                    )
                );
                $entry['price']    = round($price, wc_get_price_decimals());
                $entry['quantity'] = $qty;
            } else {
                // Deleted product: keep the line so totals stay reconcilable.
                $entry = array(
                    'item_id'   => (string) $item->get_name(),
                    'item_name' => (string) $item->get_name(),
                    'price'     => round($price, wc_get_price_decimals()),
                    'quantity'  => $qty,
                    'index'     => $index,
                );
            }

            $items[] = $entry;
            ++$index;
        }

        return $items;
    }

    /**
     * Page-embedded cart payload for the cart/checkout pages (§3.1 footer).
     * Those pages are never page-cached (cart cookies bypass cache), so the
     * per-visitor cart data is safe to embed. No PII: products and totals only.
     *
     * @return array|null {currency, value, count, coupon, items} or null without a cart.
     */
    public static function cart_page_data(): ?array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return null;
        }

        $cart     = WC()->cart;
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'RON';
        $mode     = self::value_mode();
        $items    = array();
        $refs     = array();
        $value    = 0.0;
        $index    = 0;

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'] ?? null;
            if (!$product instanceof WC_Product) {
                continue;
            }

            $qty  = max(1, (int) ($cart_item['quantity'] ?? 1));
            $item = self::product_to_item($product, array('qty' => $qty, 'index' => $index));
            $items[] = $item;
            $refs[]  = array(
                'product_id'   => (int) ($cart_item['product_id'] ?? $product->get_id()),
                'variation_id' => (int) ($cart_item['variation_id'] ?? 0),
                'qty'          => $qty,
            );
            $value += ((float) $item['price']) * $qty;
            ++$index;
        }

        if (empty($items)) {
            return null;
        }

        $coupons = array();
        if (function_exists('WC') && is_array($cart->get_applied_coupons())) {
            foreach ($cart->get_applied_coupons() as $code) {
                $coupons[] = (string) $code;
            }
        }

        return array(
            'currency' => $currency,
            'value'    => round($value, wc_get_price_decimals()),
            'count'    => (int) $cart->get_cart_contents_count(),
            'coupon'   => implode(',', $coupons),
            'items'    => $items,
            // Catalog ids for the begin_checkout mirror body (§2.2 /event items[]),
            // kept out of the GA4 `items` schema.
            'refs'     => $refs,
        );
    }

    /**
     * Build the GA4-shaped dataLayer push object for an event (§2.4, U9).
     *
     * Caller MUST push `{ecommerce:null}` immediately before this object when
     * the push carries an `ecommerce` key (clear-before-push, one style only).
     * Adds D12 adc.order_total and D6 adc.purchase_channel markers.
     * Applies the `adc_datalayer_event` filter (array $push, ADC_Event).
     *
     * @param ADC_Event $event      Canonical event.
     * @param array     $extra_adc  Extra keys for the `adc` metadata object.
     * @return array<string, mixed>
     */
    public static function to_datalayer_push(ADC_Event $event, array $extra_adc = array()): array
    {
        $push = array(
            'event'    => $event->name,
            'event_id' => $event->event_id,
        );

        $has_ecommerce = !empty($event->items)
            || null !== $event->value
            || isset($event->params['transaction_id']);

        $ecommerce_keys = array('transaction_id', 'tax', 'shipping', 'coupon', 'affiliation');

        if ($has_ecommerce) {
            $ecommerce = array();
            if ('' !== $event->currency) {
                $ecommerce['currency'] = $event->currency;
            }
            if (null !== $event->value) {
                $ecommerce['value'] = $event->value;
            }
            if (!empty($event->items)) {
                $ecommerce['items'] = $event->items;
            }
            foreach ($ecommerce_keys as $key) {
                if (isset($event->params[$key]) && '' !== $event->params[$key]) {
                    $ecommerce[$key] = $event->params[$key];
                }
            }
            $push['ecommerce'] = $ecommerce;
        }

        // Remaining params (search_term, method, shipping_tier, refund_id, …)
        // ride at the top level of the pushed object — GTM Custom Event
        // triggers read them directly (§2.4, PLATFORMS §8.1: always include
        // `event` and put params where the trigger expects them).
        foreach ($event->params as $key => $value) {
            if (in_array($key, $ecommerce_keys, true) && isset($push['ecommerce'])) {
                continue; // Already placed inside ecommerce.
            }
            if (in_array($key, array('order_total', 'purchase_channel'), true) || $value === '' || null === $value) {
                continue; // adc namespace markers, handled below.
            }
            $push[$key] = $value;
        }

        $adc = array_merge(
            array(
                'origin'    => $event->origin,
                'page_type' => self::page_type(),
            ),
            $extra_adc
        );

        // D12: expose the order total for Meta/TikTok GTM tag mapping.
        if (isset($event->params['order_total'])) {
            $adc['order_total'] = $event->params['order_total'];
        }
        // D6: which channel GA4 purchase uses (browser|server, C7 ga4 group).
        if ('purchase' === $event->name) {
            $adc['purchase_channel'] = (string) ADC_Settings::get('ga4', 'purchase_channel', 'browser');
        }

        $push['adc'] = $adc;

        $filtered = apply_filters('adc_datalayer_event', $push, $event);
        return is_array($filtered) ? $filtered : $push;
    }

    /**
     * Current page type for adc.page_type (§2.4 enum).
     *
     * @return string
     */
    public static function page_type(): string
    {
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return 'order_received';
        }
        if (function_exists('is_cart') && is_cart()) {
            return 'cart';
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return 'checkout';
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return 'account';
        }
        if (function_exists('is_search') && is_search()) {
            return 'search';
        }
        if (function_exists('is_product') && is_product()) {
            return 'product';
        }
        if (function_exists('is_shop') && is_shop()) {
            return 'category'; // Shop is a product archive (view_item_list loop).
        }
        if (function_exists('is_product_category') && (is_product_category() || is_product_tag())) {
            return 'category';
        }
        if (is_front_page()) {
            return 'home';
        }
        if (is_singular('post')) {
            return 'post';
        }
        if (is_page()) {
            return 'page';
        }

        return 'home';
    }

    /**
     * Events group: whether an event is enabled in settings.
     *
     * @param string $name Event name.
     * @return bool
     */
    public static function event_enabled(string $name): bool
    {
        return (bool) ADC_Settings::get('events', $name, true);
    }

    /**
     * Configured value semantics (events.value_mode).
     *
     * @return string
     */
    public static function value_mode(): string
    {
        $mode = (string) ADC_Settings::get('events', 'value_mode', 'excl_tax_excl_shipping');
        return in_array($mode, array('excl_tax_excl_shipping', 'incl_tax'), true) ? $mode : 'excl_tax_excl_shipping';
    }

    /**
     * Resolved item id per events.item_id_source (id|sku|gla), fallback product id.
     *
     * @param WC_Product $product Product/variation.
     * @return string
     */
    private static function item_id_for(WC_Product $product): string
    {
        $source = (string) ADC_Settings::get('events', 'item_id_source', 'id');
        $id     = '';

        if ('sku' === $source) {
            $id = (string) $product->get_sku();
        } elseif ('gla' === $source) {
            $gla = $product->get_meta('_wc_gla_id');
            $id  = is_string($gla) ? $gla : '';
        }

        if ('' === $id) {
            $id = (string) ($product->get_id() ?: $product->get_parent_id());
        }

        return $id;
    }

    /**
     * Brand name from events.brand_taxonomy (default product_brand).
     *
     * @param WC_Product $product Product/variation.
     * @return string '' when absent.
     */
    private static function brand_name(WC_Product $product): string
    {
        $taxonomy = sanitize_key((string) ADC_Settings::get('events', 'brand_taxonomy', 'product_brand'));
        if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
            return '';
        }

        // Tax terms live on the parent product for variations.
        $term_id = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
        $terms   = get_the_terms($term_id, $taxonomy);
        if (is_array($terms) && !empty($terms)) {
            return (string) reset($terms)->name;
        }

        return '';
    }

    /**
     * Category ancestor chain (top-level first), max 5 levels (GA4).
     *
     * @param WC_Product $product Product/variation.
     * @return string[]
     */
    private static function category_path(WC_Product $product): array
    {
        $cat_ids = $product->get_category_ids();
        if (empty($cat_ids) && $product instanceof WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            $cat_ids = $parent instanceof WC_Product ? $parent->get_category_ids() : array();
        }
        if (empty($cat_ids)) {
            return array();
        }

        $ancestors = get_ancestors((int) $cat_ids[0], 'product_cat', 'taxonomy');
        $chain     = array_reverse($ancestors);
        $chain[]   = (int) $cat_ids[0];

        $names = array();
        foreach ($chain as $term_id) {
            $term = get_term((int) $term_id, 'product_cat');
            if ($term instanceof WP_Term) {
                $names[] = (string) $term->name;
            }
        }

        return $names;
    }

    /**
     * Variation attribute label ("M / Albastru") or ''.
     *
     * @param WC_Product $product Product/variation.
     * @return string
     */
    private static function variant_label(WC_Product $product): string
    {
        if (!$product instanceof WC_Product_Variation) {
            return '';
        }

        $parts = array();
        foreach ($product->get_variation_attributes() as $name => $value) {
            $label = wc_get_attribute_taxonomy_label(str_replace('pa_', '', (string) $name));
            if (empty($label)) {
                $label = (string) $name;
            }
            $parts[] = trim($label . ': ' . (string) $value);
        }

        return implode(' / ', $parts);
    }

    /**
     * Sum of order item totals, ex-tax (default value_mode).
     *
     * @param WC_Order $order Order.
     * @return float
     */
    private static function sum_items_excl_tax(WC_Order $order): float
    {
        $sum = 0.0;
        foreach ($order->get_items() as $item) {
            $sum += (float) $item->get_total();
        }

        return round($sum, wc_get_price_decimals());
    }

    /**
     * Sum of order item totals, incl-tax (value_mode=incl_tax).
     *
     * @param WC_Order $order Order.
     * @return float
     */
    private static function sum_items_incl_tax(WC_Order $order): float
    {
        $sum = 0.0;
        foreach ($order->get_items() as $item) {
            $sum += (float) $item->get_total() + (float) $item->get_total_tax();
        }

        return round($sum, wc_get_price_decimals());
    }

    /**
     * Snapshot marketing consent allows attaching hashed user data (§2.1).
     *
     * @param ADC_Consent_State|null $state Order consent snapshot.
     * @return bool
     */
    private static function marketing_allowed(?ADC_Consent_State $state): bool
    {
        if (null === $state) {
            return false;
        }

        return $state->allows('marketing');
    }
}
