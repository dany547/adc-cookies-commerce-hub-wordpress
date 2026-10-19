/* ADC Marketing 2.0 — advanced matching identify (vanilla JS, no jQuery, defer).
 *
 * Contract (docs/ARCHITECTURE.md §2.3, §3.3, §2.4; D21):
 * - implements window.adcTrack.identify(fields, source) → Promise<{user_data, external_id}>;
 *   after a successful /identify it pushes the `adc_identify` event to the dataLayer.
 * - earliest-possible capture (client path B of §3.3):
 *     A) server side is JS-free (PHP hook on update_order_review);
 *     B) checkout e-mail/phone/name fields on blur/change (debounced 400 ms, regex-valid)
 *        → POST /identify {email, phone, first_name, last_name, country, source:'checkout'};
 *     C) logged-in users: one {source:'account'} call per session;
 *     D) configured form selectors → identify with source:'form' (+ generate_lead).
 * - everything is gated on window.adcConsent.whenAllowed('marketing'); without the
 *   consent manager nothing is sent (server rejects anyway: 403 consent_required).
 * - raw values only live inside this request: the server answers with SHA-256 hashes,
 *   which are what we push to the dataLayer (default format hashed; 'plain' per D21
 *   sends raw values to GTM only after marketing consent).
 */
(function () {
    'use strict';

    var CFG = window.adcConfig || {};
    var MATCHING = CFG.matching || {};

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var DEBOUNCE_MS = 400;
    var ACCOUNT_FLAG = 'adc_identify_account';
    var LAST_SENT = '';

    var CHECKOUT_SELECTORS = [
        'input[name="billing_email"]',
        '#billing_email',
        'input[name="billing_phone"]',
        '#billing_phone',
        'input[name="billing_first_name"]',
        '#billing_first_name',
        'input[name="billing_last_name"]',
        '#billing_last_name'
    ];

    function dataLayer() {
        var name = (CFG.gtm && CFG.gtm.datalayerName) || 'dataLayer';
        var dl = window[name];
        if (!Array.isArray(dl)) {
            dl = [];
            window[name] = dl;
        }
        return dl;
    }

    function debugLog() {
        if (CFG.debug && window.adcTrack && typeof window.adcTrack.debug === 'boolean' && window.adcTrack.debug) {
            window.console.log.apply(window.console, ['[adc identify]'].concat([].slice.call(arguments)));
        }
    }

    /* ------------------------------------------------------------------ */
    /* /identify request                                                   */
    /* ------------------------------------------------------------------ */

    function identify(fields, source) {
        return new Promise(function (resolve, reject) {
            if (!MATCHING.enabled) {
                reject(new Error('matching_disabled'));
                return;
            }
            if (!CFG.rest) {
                reject(new Error('no_rest_url'));
                return;
            }

            var payload = { source: source };
            ['email', 'phone', 'first_name', 'last_name', 'city', 'state', 'postcode', 'country'].forEach(function (key) {
                var value = fields && fields[key];
                if (typeof value === 'string' && value.trim() !== '') {
                    payload[key] = value.trim();
                }
            });

            fetch(CFG.rest + 'identify', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-ADC-Token': CFG.token || ''
                },
                body: JSON.stringify(payload)
            }).then(function (response) {
                if (403 === response.status) {
                    reject(new Error('consent_required'));
                    return null;
                }
                if (!response.ok) {
                    reject(new Error('http_' + response.status));
                    return null;
                }
                return response.json();
            }).then(function (json) {
                if (!json || !json.user_data) {
                    return;
                }
                var result = { user_data: json.user_data, external_id: json.user_data.external_id || '' };
                pushIdentifyEvent(result, payload);
                resolve(result);
            }).catch(function (err) {
                debugLog('identify failed', err && err.message);
                reject(err);
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* dataLayer push (§2.4 adc_identify)                                  */
    /* ------------------------------------------------------------------ */

    function pushIdentifyEvent(result, rawFields) {
        var hashed = result.user_data || {};
        var plain = MATCHING.datalayerFormat === 'plain'; // D21: plain only with marketing consent (inherent: /identify 403s otherwise).

        var ud = {};
        var address = {};

        if (plain) {
            if (rawFields.email) { ud.email = rawFields.email; }
            if (rawFields.phone) { ud.phone_number = rawFields.phone; }
            if (rawFields.first_name) { address.first_name = rawFields.first_name; }
            if (rawFields.last_name) { address.last_name = rawFields.last_name; }
        } else {
            if (hashed.em) { ud.sha256_email_address = hashed.em; }
            if (hashed.ph) { ud.sha256_phone_number = hashed.ph; }
            if (hashed.fn) { address.sha256_first_name = hashed.fn; }
            if (hashed.ln) { address.sha256_last_name = hashed.ln; }
        }

        if (Object.keys(address).length) {
            ud.address = address;
        }
        if (!Object.keys(ud).length) {
            return; // Nothing usable to push.
        }

        var push = { event: 'adc_identify', user_data: ud };
        if (result.external_id) {
            push.external_id = result.external_id;
        }

        dataLayer().push(push);
        debugLog('adc_identify pushed');
    }

    /* ------------------------------------------------------------------ */
    /* Field capture                                                       */
    /* ------------------------------------------------------------------ */

    function normalizePhone(raw) {
        return (raw || '').replace(/[^\d+]/g, '');
    }

    function collectCheckoutFields() {
        var fields = {};
        var email = document.querySelector('input[name="billing_email"], #billing_email, input[type="email"][autocomplete="email"]');
        var phone = document.querySelector('input[name="billing_phone"], #billing_phone, input[type="tel"]');
        var first = document.querySelector('input[name="billing_first_name"], #billing_first_name');
        var last = document.querySelector('input[name="billing_last_name"], #billing_last_name');

        if (email && EMAIL_RE.test((email.value || '').trim())) {
            fields.email = email.value.trim();
        }
        if (phone) {
            var normalized = normalizePhone(phone.value);
            if (normalized.replace(/\D/g, '').length >= 6) {
                fields.phone = normalized;
            }
        }
        if (first && first.value.trim()) { fields.first_name = first.value.trim(); }
        if (last && last.value.trim()) { fields.last_name = last.value.trim(); }

        return fields;
    }

    function signature(fields) {
        return JSON.stringify(fields);
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(fn, wait);
        };
    }

    var maybeSendCheckout = debounce(function () {
        var fields = collectCheckoutFields();
        if (!fields.email && !fields.phone) {
            return; // Regex-invalid or nothing typed yet (§3.3 B).
        }
        var sig = signature(fields);
        if (sig === LAST_SENT) {
            return;
        }
        LAST_SENT = sig;

        whenMarketingAllowed(function () {
            identify(fields, 'checkout').catch(function () { /* silent: consent or rate limit. */ });
        });
    }, DEBOUNCE_MS);

    function bindCheckoutFields() {
        CHECKOUT_SELECTORS.forEach(function (selector) {
            var nodes = document.querySelectorAll(selector);
            Array.prototype.forEach.call(nodes, function (node) {
                node.addEventListener('blur', maybeSendCheckout);
                node.addEventListener('change', maybeSendCheckout);
            });
        });
    }

    /* Logged-in users: one account-sourced identify per browser session (§3.3 C). */
    function maybeAccountIdentify() {
        var loggedIn = (document.body && document.body.classList.contains('logged-in')) ||
            Boolean(document.getElementById('wpadminbar'));
        if (!loggedIn) {
            return;
        }
        var sent;
        try {
            sent = window.sessionStorage.getItem(ACCOUNT_FLAG);
        } catch (e) {
            sent = '1'; // Storage unavailable: never retry within this page.
        }
        if (sent) {
            return;
        }
        try {
            window.sessionStorage.setItem(ACCOUNT_FLAG, '1');
        } catch (e) { /* ignore */ }

        whenMarketingAllowed(function () {
            identify({}, 'account').catch(function () { /* silent. */ });
        });
    }

    /* Form selectors (matching.form_selectors, one per line) → source 'form' + generate_lead (§3.3 D). */
    function bindConfiguredForms() {
        var selectors = MATCHING.selectors || [];
        selectors.forEach(function (selector) {
            var forms;
            try {
                forms = document.querySelectorAll(selector);
            } catch (e) {
                return; // Invalid selector from settings — never break the page.
            }
            Array.prototype.forEach.call(forms, function (node) {
                var form = node.tagName === 'FORM' ? node : node.closest('form');
                if (!form || form.dataset.adcIdentifyBound) {
                    return;
                }
                form.dataset.adcIdentifyBound = '1';
                form.addEventListener('submit', function () {
                    var fields = {};
                    Array.prototype.forEach.call(form.querySelectorAll('input[type="email"], input[type="tel"], input[name*="phone" i]'), function (input) {
                        var value = (input.value || '').trim();
                        if (!value) {
                            return;
                        }
                        if ('email' === input.type && EMAIL_RE.test(value)) {
                            fields.email = value;
                        } else if ('tel' === input.type || /phone/i.test(input.name || '')) {
                            var normalized = normalizePhone(value);
                            if (normalized.replace(/\D/g, '').length >= 6) {
                                fields.phone = normalized;
                            }
                        }
                    });

                    whenMarketingAllowed(function () {
                        identify(fields, 'form').catch(function () { /* silent. */ });
                        if (window.adcTrack && typeof window.adcTrack.event === 'function') {
                            window.adcTrack.event('generate_lead', {}, { origin: 'browser' });
                        }
                    });
                });
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Consent gate                                                        */
    /* ------------------------------------------------------------------ */

    function whenMarketingAllowed(cb) {
        if (window.adcConsent && typeof window.adcConsent.whenAllowed === 'function') {
            window.adcConsent.whenAllowed('marketing', cb);
            return;
        }
        // No consent manager: server would 403; skip silently.
        debugLog('consent manager unavailable');
    }

    /* ------------------------------------------------------------------ */
    /* Boot                                                                */
    /* ------------------------------------------------------------------ */

    function init() {
        if (!MATCHING.enabled) {
            return;
        }

        // Public API on adcTrack (C4 owns the object; we only add `identify`).
        window.adcTrack = window.adcTrack || {};
        window.adcTrack.identify = identify;

        bindCheckoutFields();
        maybeAccountIdentify();
        bindConfiguredForms();

        // Late-rendered checkout fields (blocks refresh their inputs).
        var observer = new MutationObserver(debounce(function () {
            bindCheckoutFields();
            bindConfiguredForms();
        }, 400));
        var target = document.body;
        if (target) {
            observer.observe(target, { childList: true, subtree: true });
        }
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
