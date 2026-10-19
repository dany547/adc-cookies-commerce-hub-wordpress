/**
 * ADC Marketing — TikTok Pixel loader, direct mode (docs/ARCHITECTURE.md §2.3).
 *
 * Contract (docs/research/PLATFORMS.md §4.5–§4.6; §9 D12, D15):
 * - mode 'direct': the pixel is loaded ONLY inside
 *   adcConsent.whenAllowed('marketing') — without marketing consent nothing
 *   TikTok-owned is created (no _ttp cookie, no queue flush).
 * - mode 'gtm': this loader is a no-op (and per TikTok must NOT call the
 *   consent APIs when a tag manager owns the tag); 'off': never enqueued.
 * - Default opt-out flow (§4.6): ttq.methods gains holdConsent /
 *   revokeConsent / grantConsent; ttq.holdConsent() runs BEFORE ttq.load();
 *   ttq.grantConsent() flushes the queue once marketing is allowed;
 *   ttq.revokeConsent() on withdrawal.
 * - E-commerce events are forwarded from the single dataLayer source with
 *   the SAME event_id the server sends (dedup key
 *   event_source_id + event + event_id, 48 h). Purchase value uses
 *   adc.order_total (D12).
 * - Advanced matching from `adc_identify` only when the dataLayer carries
 *   plain values (matching.datalayer_format = 'plain', reachable only with
 *   marketing consent); hashed-only identify data is skipped because
 *   pre-hashed ttq.identify keys are not documented (UNVERIFIED, §4.3) and
 *   a wrong key would double-hash.
 */
(function () {
    'use strict';

    var CFG = (window.adcConfig && window.adcConfig.pixels && window.adcConfig.pixels.tiktok) || null;

    if (!CFG || CFG.mode !== 'direct' || !CFG.id) {
        return; // 'off' / missing id: nothing to do. 'gtm': GTM owns the tag.
    }

    var consent = window.adcConsent;

    if (!consent || typeof consent.whenAllowed !== 'function') {
        return; // No consent manager, no pixel — never load without consent.
    }

    // Canonical GA4 name -> TikTok event name (§9.1a; no refund, U3).
    var MAPPED = {
        view_item: 'ViewContent',
        add_to_cart: 'AddToCart',
        begin_checkout: 'InitiateCheckout',
        add_payment_info: 'AddPaymentInfo',
        purchase: 'Purchase',
        sign_up: 'CompleteRegistration',
        generate_lead: 'Lead',
        search: 'Search',
        page_view: 'Pageview'
    };

    var WATCH_FLAG = '__adcPixelTiktokWatch';
    var loaded = false;

    consent.whenAllowed('marketing', function () {
        load();
    });

    consent.onChange(function (state) {
        if (!loaded || !state || typeof window.ttq !== 'object' || !window.ttq) {
            return;
        }

        if (state.categories.marketing) {
            if (typeof window.ttq.grantConsent === 'function') {
                window.ttq.grantConsent(); // Flushes the held queue (§4.6).
            }
        } else if (typeof window.ttq.revokeConsent === 'function') {
            window.ttq.revokeConsent();
        }
    });

    /* ------------------------------------------------------------------ */
    /* Pixel bootstrap (default opt-out flow, §4.6)                        */
    /* ------------------------------------------------------------------ */

    function load() {
        if (loaded) {
            return;
        }
        loaded = true;

        stubTtq();

        // holdConsent BEFORE load: events are queued, not sent (§4.6).
        window.ttq.holdConsent();
        injectScript(
            'https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=' +
            encodeURIComponent(CFG.id) + '&lib=ttq'
        );
        window.ttq.load(CFG.id);
        window.ttq.page();
        window.ttq.grantConsent(); // Marketing is granted here by contract.

        watchDataLayer();
    }

    /**
     * TikTok pixel queue stub extended with the documented consent methods,
     * so calls made before events.js arrives are replayed in order.
     */
    function stubTtq() {
        if (window.TiktokAnalyticsObject && window[window.TiktokAnalyticsObject]) {
            return; // Another loader already stubbed the queue.
        }

        var ttq = window.ttq = [];
        window.TiktokAnalyticsObject = 'ttq';

        ttq.methods = [
            'page', 'track', 'identify', 'instances', 'debug', 'on', 'off',
            'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie',
            'holdConsent', 'revokeConsent', 'grantConsent'
        ];

        ttq.setAndDefer = function (target, method) {
            target[method] = function () {
                target.push([method].concat(Array.prototype.slice.call(arguments, 0)));
            };
        };

        for (var i = 0; i < ttq.methods.length; i++) {
            ttq.setAndDefer(ttq, ttq.methods[i]);
        }

        ttq.instance = function (id) {
            var calls = ttq._i[id] || [];
            for (var n = 0; n < ttq.methods.length; n++) {
                calls[ttq.methods[n]] = ttq[ttq.methods[n]];
            }
            ttq._i[id] = calls;
            return ttq;
        };

        ttq.load = function (e) {
            var url = 'https://analytics.tiktok.com/i18n/pixel/events.js';
            ttq._i = ttq._i || {};
            ttq._i[e] = [];
            ttq._t = ttq._t || {};
            ttq._t[e] = +new Date();
            ttq._o = ttq._o || {};
            ttq._o[e] = {};
            injectScript(url + '?sdkid=' + e + '&lib=ttq');
        };
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
    /* Advanced matching (only with documented plain-value identify)       */
    /* ------------------------------------------------------------------ */

    function applyIdentify(push) {
        var ud = push && push.user_data;
        if (!ud) {
            return;
        }

        // Pre-hashed ttq.identify keys are undocumented (UNVERIFIED §4.3) —
        // only plain values are forwarded, which the identify pipeline
        // produces exclusively when marketing consent was granted (D21).
        var data = {};

        if (typeof ud.email === 'string' && ud.email) { data.email = ud.email; }
        if (typeof ud.phone_number === 'string' && ud.phone_number) { data.phone_number = ud.phone_number; }
        if (typeof push.external_id === 'string' && push.external_id) { data.external_id = push.external_id; }

        if (Object.keys(data).length && typeof window.ttq.identify === 'function') {
            window.ttq.identify(data);
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

        // Events pushed before the pixel finished loading are processed
        // now, with their original event_id, so dedup still applies.
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

        if (push.event === 'adc_identify') {
            applyIdentify(push);
            return;
        }

        var tiktokName = MAPPED[push.event];
        if (!tiktokName) {
            return; // Not a TikTok-mapped event (consent updates, adc_page, …).
        }

        track(tiktokName, push);
    }

    /* ------------------------------------------------------------------ */
    /* Event forwarding (PLATFORMS §4.4; D12)                              */
    /* ------------------------------------------------------------------ */

    function track(tiktokName, push) {
        var ecom = push.ecommerce || {};
        var props = { content_type: 'product' };
        var items = Array.isArray(ecom.items) ? ecom.items : [];

        if (items.length && push.event !== 'page_view' && push.event !== 'search') {
            var contents = [];
            var quantity = 0;

            items.forEach(function (item) {
                var id = item && typeof item.item_id === 'string' ? item.item_id : '';
                var qty = item && typeof item.quantity === 'number' && item.quantity > 0 ? item.quantity : 1;

                if (id) {
                    contents.push({ content_id: id, content_type: 'product', quantity: qty });
                }
                quantity += qty;
            });

            if (contents.length) {
                props.contents = contents;
            }
            if (quantity > 0) {
                props.quantity = quantity;
            }
        }

        // D12: Purchase value = order total via adc.order_total.
        var total = push.adc && typeof push.adc.order_total === 'number' ? push.adc.order_total : null;
        var value = push.event === 'purchase'
            ? (total !== null ? total : (typeof ecom.value === 'number' ? ecom.value : null))
            : (typeof ecom.value === 'number' ? ecom.value : null);

        if (value !== null) {
            props.value = value;
        }
        if (typeof ecom.currency === 'string' && ecom.currency) {
            props.currency = ecom.currency;
        }

        // Same event_id as the server event -> dedup key
        // event_source_id + event + event_id (§4.5).
        if (push.event_id) {
            window.ttq.track(tiktokName, props, { event_id: push.event_id });
        } else {
            window.ttq.track(tiktokName, props);
        }
    }
})();
