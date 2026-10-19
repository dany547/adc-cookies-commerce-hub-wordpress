/**
 * ADC Marketing — Meta Pixel loader, direct mode (docs/ARCHITECTURE.md §2.3).
 *
 * Contract (docs/research/PLATFORMS.md §2.9–§2.10; §9 D12, D15):
 * - mode 'direct': the pixel is loaded ONLY inside
 *   adcConsent.whenAllowed('marketing') — without marketing consent the
 *   pixel is never loaded at all, so no _fbp/_fbc cookie is ever created.
 * - mode 'gtm': this loader is a no-op (GTM owns the tag); 'off': the
 *   script is never enqueued server-side.
 * - On load: fbq('consent','grant') (D15 verified); on withdrawal:
 *   fbq('consent','revoke') behind a typeof guard (documented by Meta in
 *   its Pixel GDPR guidance) — cookie cleanup stays with the consent
 *   manager inventory.
 * - E-commerce events are forwarded from the single dataLayer source with
 *   the SAME event_id the server sends (platform dedup on
 *   event_name + event_id). Purchase value uses adc.order_total (D12).
 * - Advanced matching is initialised from `adc_identify` pushes using ONLY
 *   the pre-hashed sha256_* fields (never raw PII; plain-format values are
 *   ignored even when matching.datalayer_format is 'plain').
 */
(function () {
    'use strict';

    var CFG = (window.adcConfig && window.adcConfig.pixels && window.adcConfig.pixels.meta) || null;

    if (!CFG || CFG.mode !== 'direct' || !CFG.id) {
        return; // 'off' / missing id: nothing to do. 'gtm': GTM owns the tag.
    }

    var consent = window.adcConsent;

    if (!consent || typeof consent.whenAllowed !== 'function') {
        return; // No consent manager, no pixel — never load without consent.
    }

    // Canonical GA4 name -> Meta Pixel event name (§9.1a).
    var MAPPED = {
        view_item: 'ViewContent',
        add_to_cart: 'AddToCart',
        begin_checkout: 'InitiateCheckout',
        add_payment_info: 'AddPaymentInfo',
        purchase: 'Purchase',
        sign_up: 'CompleteRegistration',
        generate_lead: 'Lead',
        search: 'Search',
        page_view: 'PageView'
    };

    var WATCH_FLAG = '__adcPixelMetaWatch';
    var loaded = false;
    var identified = false;

    consent.whenAllowed('marketing', function () {
        load();
    });

    consent.onChange(function (state) {
        if (!loaded || !state) {
            return;
        }
        // D15: Meta documents fbq('consent','revoke'); call it behind a guard.
        if (!state.categories.marketing && typeof window.fbq === 'function') {
            window.fbq('consent', 'revoke');
        }
    });

    /* ------------------------------------------------------------------ */
    /* Pixel bootstrap                                                     */
    /* ------------------------------------------------------------------ */

    function load() {
        if (loaded) {
            return;
        }
        loaded = true;

        stubFbq();
        injectScript('https://connect.facebook.net/en_US/fbevents.js');
        window.fbq('init', CFG.id);
        applyMatching();
        window.fbq('consent', 'grant'); // D15: granted at load time by contract.
        watchDataLayer();
    }

    /**
     * Standard Meta pixel queue stub, so calls made before fbevents.js
     * arrives are replayed by the real library.
     */
    function stubFbq() {
        if (window.fbq) {
            return;
        }

        var fbq = function () {
            if (fbq.callMethod) {
                fbq.callMethod.apply(fbq, arguments);
            } else {
                fbq.queue.push(arguments);
            }
        };

        fbq.queue = [];
        fbq.loaded = true;
        fbq.version = '2.0';
        fbq.push = fbq;

        window.fbq = fbq;
        window._fbq = fbq;
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
    /* Advanced matching (hashed only)                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Extract the Meta advanced-matching fields from an `adc_identify`
     * push. Only sha256_* keys are accepted — the plugin never forwards raw
     * e-mail/phone to a platform (plain format values are ignored here).
     */
    function matchingFromPush(push) {
        var ud = push && push.user_data;
        if (!ud) {
            return null;
        }

        var out = {};
        var address = ud.address || {};

        if (typeof ud.sha256_email_address === 'string') { out.em = ud.sha256_email_address; }
        if (typeof ud.sha256_phone_number === 'string') { out.ph = ud.sha256_phone_number; }
        if (typeof address.sha256_first_name === 'string') { out.fn = address.sha256_first_name; }
        if (typeof address.sha256_last_name === 'string') { out.ln = address.sha256_last_name; }
        if (typeof push.external_id === 'string') { out.external_id = push.external_id; }

        return Object.keys(out).length ? out : null;
    }

    function applyMatching() {
        var rows = dataLayer();
        for (var i = 0; i < rows.length; i++) {
            var push = rows[i];
            if (push && push.event === 'adc_identify') {
                var matching = matchingFromPush(push);
                if (matching) {
                    // Documented hashed advanced-matching init; safe to re-call.
                    window.fbq('init', CFG.id, matching);
                    identified = true;
                }
            }
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

        // Events pushed before the pixel finished loading (e.g. purchase on
        // the order-received page) are processed now, with their original
        // event_id, so dedup still applies.
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
            var matching = matchingFromPush(push);
            if (matching && typeof window.fbq === 'function') {
                window.fbq('init', CFG.id, matching);
                identified = true;
            }
            return;
        }

        var metaName = MAPPED[push.event];
        if (!metaName) {
            return; // Not a Meta-mapped event (consent updates, adc_page, …).
        }

        track(metaName, push);
    }

    /* ------------------------------------------------------------------ */
    /* Event forwarding (PLATFORMS §2.6; D12)                              */
    /* ------------------------------------------------------------------ */

    function track(metaName, push) {
        var ecom = push.ecommerce || {};
        var data = {};
        var items = Array.isArray(ecom.items) ? ecom.items : [];

        if (typeof ecom.currency === 'string' && ecom.currency) {
            data.currency = ecom.currency;
        }

        // D12: Purchase value = order total (incl. tax/fees/shipping) via
        // the adc.order_total marker; other events use the GA4 value.
        if (push.event === 'purchase') {
            var total = push.adc && typeof push.adc.order_total === 'number' ? push.adc.order_total : null;
            var value = total !== null ? total : (typeof ecom.value === 'number' ? ecom.value : null);
            if (value !== null) {
                data.value = value;
            }
        } else if (typeof ecom.value === 'number') {
            data.value = ecom.value;
        }

        if (push.event !== 'page_view' && push.event !== 'search' && items.length) {
            var contentIds = [];
            var contents = [];
            var numItems = 0;

            items.forEach(function (item) {
                var id = item && typeof item.item_id === 'string' ? item.item_id : '';
                var quantity = item && typeof item.quantity === 'number' && item.quantity > 0 ? item.quantity : 1;

                if (id) {
                    contentIds.push(id);
                    contents.push({ id: id, quantity: quantity });
                }
                numItems += quantity;
            });

            if (contentIds.length) {
                data.content_ids = contentIds;
                data.contents = contents;
                data.content_type = 'product';
            }
            if (numItems > 0) {
                data.num_items = numItems;
            }
        }

        if (push.event === 'search' && push.params && typeof push.params.search_term === 'string') {
            data.search_string = push.params.search_term.slice(0, 100);
        }

        // Same event_id as the server event -> Meta dedups on name + id.
        if (push.event_id) {
            window.fbq('track', metaName, data, { eventID: push.event_id });
        } else {
            window.fbq('track', metaName, data);
        }
    }
})();
