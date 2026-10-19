/**
 * ADC Marketing — GA4 gtag loader, direct mode (docs/ARCHITECTURE.md §2.3).
 *
 * Contract (docs/research/PLATFORMS.md §3; §9 D6, D20, U9):
 * - mode 'direct': gtag.js is loaded ONLY inside
 *   adcConsent.whenAllowed('statistics') — without statistics consent the
 *   tag is never loaded at all.
 * - mode 'gtm': this loader is a no-op (GTM owns the tag); 'off': the
 *   script is never enqueued server-side.
 * - Consent Mode updates are handled centrally by consent-manager.js
 *   (gtag('consent','update',…)) — this loader never touches consent.
 * - E-commerce events are forwarded from the single dataLayer source as
 *   gtag('event', name, params), flattening the {ecommerce:…} wrapper
 *   (U9: one style; the wrapper stays in dataLayer for GTM consumers).
 * - D6: when pixels.ga4.purchase_channel === 'server', browser `purchase`
 *   pushes are skipped (GA4 has no MP<->gtag dedup; transaction_id only
 *   guards retries).
 * - D20: when pixels.sgtm.serverUrl is set, the gtag config receives
 *   server_container_url so hits go first-party through the sGTM container.
 * - send_page_view is disabled: page_view arrives through the dataLayer
 *   source like every other event (one event source, one event_id).
 * - Advanced matching (adc_identify) is NOT forwarded to gtag here: the
 *   browser Enhanced Conversions setup belongs in the GTM tag (pre-hashed
 *   acceptance by gtag is unverified per D21); the server sends hashed
 *   user_data with its own events where consent allows (D8).
 */
(function () {
    'use strict';

    var CFG = (window.adcConfig && window.adcConfig.pixels && window.adcConfig.pixels.ga4) || null;

    if (!CFG || CFG.mode !== 'direct' || !CFG.id) {
        return; // 'off' / missing id: nothing to do. 'gtm': GTM owns the tag.
    }

    var consent = window.adcConsent;

    if (!consent || typeof consent.whenAllowed !== 'function') {
        return; // No consent manager, no tag — never load without consent.
    }

    var WATCH_FLAG = '__adcPixelGa4Watch';
    var loaded = false;

    consent.whenAllowed('statistics', function () {
        load();
    });

    /* ------------------------------------------------------------------ */
    /* gtag bootstrap                                                      */
    /* ------------------------------------------------------------------ */

    function load() {
        if (loaded) {
            return;
        }
        loaded = true;

        stubGtag();

        var config = { send_page_view: false };

        // D20: server_container_url only when the admin supplied one; a
        // wrong value silently blackholes analytics, so never guess.
        var sgtm = (window.adcConfig && window.adcConfig.pixels && window.adcConfig.pixels.sgtm) || null;
        if (sgtm && sgtm.mode !== 'off' && typeof sgtm.serverUrl === 'string' && sgtm.serverUrl) {
            config.server_container_url = sgtm.serverUrl;
        }

        window.gtag('config', CFG.id, config);

        injectScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(CFG.id));

        watchDataLayer();
    }

    /**
     * Standard gtag queue stub so calls made before gtag.js arrives are
     * replayed by the real library.
     */
    function stubGtag() {
        if (typeof window.gtag === 'function') {
            return;
        }

        window.dataLayer = window.dataLayer || [];
        window.gtag = function () {
            window.dataLayer.push(arguments);
        };
        window.gtag('js', new Date());
    }

    function injectScript(src) {
        var script = document.createElement('script');
        script.type = 'text/javascript';
        script.async = true;
        script.src = src;

        var first = document.getElementsByTagName('script')[0];
        if (first && first.parentNode) {
            first.parentNode.insertBefore(script, first);
        } else {
            document.head.appendChild(script);
        }
    }

    /* ------------------------------------------------------------------ */
    /* dataLayer subscription (single event source)                        */
    /* ------------------------------------------------------------------ */

    function dataLayer() {
        var name = (window.adcConfig && window.adcConfig.gtm && window.adcConfig.gtm.datalayerName) || 'dataLayer';
        if (!Array.isArray(window[name])) {
            window[name] = [];
        }
        return window[name];
    }

    function watchDataLayer() {
        var rows = dataLayer();

        if (rows[WATCH_FLAG]) {
            return;
        }

        // Events pushed before gtag finished loading (e.g. page_view or a
        // purchase on the order-received page) are processed now.
        rows.slice().forEach(process);

        var originalPush = rows.push;
        rows.push = function () {
            for (var i = 0; i < arguments.length; i++) {
                process(arguments[i]);
            }
            return originalPush.apply(rows, arguments);
        };

        try {
            Object.defineProperty(rows, WATCH_FLAG, { value: true, enumerable: false });
        } catch (error) {
            rows[WATCH_FLAG] = true;
        }
    }

    function process(push) {
        if (!push || typeof push.event !== 'string') {
            return;
        }

        if (push.event === 'purchase' && CFG.purchase_channel === 'server') {
            return; // D6: purchase is reported server-side only.
        }

        if (!isGa4Event(push.event)) {
            return; // Plugin metadata events (adc_*), consent updates, …
        }

        track(push);
    }

    /**
     * GA4 standard catalogue (§9.1a). adc_* namespace events are never
     * forwarded to the vendor tag.
     */
    function isGa4Event(name) {
        var events = {
            page_view: 1,
            view_item_list: 1,
            select_item: 1,
            view_item: 1,
            add_to_cart: 1,
            remove_from_cart: 1,
            view_cart: 1,
            begin_checkout: 1,
            add_shipping_info: 1,
            add_payment_info: 1,
            purchase: 1,
            refund: 1,
            search: 1,
            sign_up: 1,
            login: 1,
            generate_lead: 1
        };
        // custom_* names pass through to GA4 as custom events.
        return !!events[name] || name.indexOf('custom_') === 0;
    }

    /* ------------------------------------------------------------------ */
    /* Event forwarding (flattens the ecommerce wrapper; U9)               */
    /* ------------------------------------------------------------------ */

    function track(push) {
        var ecom = push.ecommerce || {};
        var params = {};

        if (typeof ecom.currency === 'string' && ecom.currency) {
            params.currency = ecom.currency;
        }
        if (typeof ecom.value === 'number') {
            params.value = ecom.value;
        }
        if (Array.isArray(ecom.items) && ecom.items.length) {
            params.items = ecom.items;
        }

        ['transaction_id', 'tax', 'shipping', 'coupon', 'affiliation'].forEach(function (key) {
            if (typeof ecom[key] === 'string' && ecom[key]) {
                params[key] = ecom[key];
            } else if (typeof ecom[key] === 'number') {
                params[key] = ecom[key];
            }
        });

        if (push.event === 'search' && push.params && typeof push.params.search_term === 'string') {
            params.search_term = push.params.search_term.slice(0, 100);
        }

        if (push.event === 'sign_up' && push.params && typeof push.params.method === 'string') {
            params.method = push.params.method;
        }

        if (push.event === 'add_payment_info' && push.params && typeof push.params.method === 'string') {
            params.payment_type = push.params.method;
        }

        window.gtag('event', push.event, params);
    }
})();
