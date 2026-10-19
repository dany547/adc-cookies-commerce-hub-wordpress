/**
 * ADC Marketing consent manager: exposes window.adcConsent (docs/ARCHITECTURE.md §2.3).
 *
 * Loaded in <head> with defer, before any tag that depends on consent. It has no
 * DOM dependency. The decision lives in the first-party cookie adc_consent
 * (format §2.2) and is read here on the client, so the page HTML stays identical
 * for every visitor. Vanilla JS, no jQuery, no build step.
 */
(function () {
    'use strict';

    if (window.adcConsent) {
        return;
    }

    var COOKIE_NAME = 'adc_consent';
    var CATEGORIES = ['preferences', 'statistics', 'marketing'];
    var SOURCES = ['banner', 'modal', 'api', 'gpc', 'account', 'checkout'];
    var UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    // Consent Mode v2 signal mapping (§2.2); overridden by adcConfig.consent.gcm when present.
    var GCM_DEFAULT = {
        preferences: ['functionality_storage', 'personalization_storage'],
        statistics: ['analytics_storage'],
        marketing: ['ad_storage', 'ad_user_data', 'ad_personalization']
    };

    var listeners = [];
    var state = undecidedState();

    function noop() {}

    function config() {
        return window.adcConfig || {};
    }

    function consentConfig() {
        return config().consent || {};
    }

    function cookieDays() {
        var days = parseInt(consentConfig().days, 10);
        // D16: lifetime is clamped to 30-365 days and never renewed on read.
        return isFinite(days) && days >= 30 && days <= 365 ? days : 180;
    }

    function currentPolicy() {
        var policy = parseInt(consentConfig().policy, 10);
        return isFinite(policy) ? policy : 1;
    }

    function cookieDomain() {
        // consent.cookie_domain is optional (§2.2); the server sanitises it,
        // keep only what a cookie attribute may contain.
        var domain = String(consentConfig().domain || '').replace(/[^A-Za-z0-9.\-_]/g, '');

        return domain.replace(/^\.+/, '');
    }

    function gpcSignal() {
        return navigator.globalPrivacyControl === true;
    }

    /**
     * Global Privacy Control is active when honour_gpc is on (adcConfig.consent.gpc)
     * and the browser signals Sec-GPC (§2.2): marketing can then never be granted.
     */
    function gpcActive() {
        return consentConfig().gpc === true && gpcSignal();
    }

    function undecidedState() {
        return {
            categories: { necessary: true, preferences: false, statistics: false, marketing: false },
            decided: false,
            ts: 0,
            policy: 0,
            cid: '',
            source: ''
        };
    }

    function cloneState(source) {
        return {
            categories: {
                necessary: true,
                preferences: source.categories.preferences,
                statistics: source.categories.statistics,
                marketing: source.categories.marketing
            },
            decided: source.decided,
            ts: source.ts,
            policy: source.policy,
            cid: source.cid,
            source: source.source
        };
    }

    function bit(value) {
        return value ? 1 : 0;
    }

    /**
     * Parse the cookie value. Returns null for malformed input, an unknown
     * format version (v), or a bad cid, which all count as undecided.
     */
    function parseCookieValue(raw) {
        var parts;
        var params = {};
        var i;
        var eq;

        try {
            parts = decodeURIComponent(raw).split('&');
        } catch (error) {
            return null;
        }

        for (i = 0; i < parts.length; i++) {
            eq = parts[i].indexOf('=');
            if (eq > 0) {
                params[parts[i].slice(0, eq)] = parts[i].slice(eq + 1);
            }
        }

        if (params.v !== '1' || !UUID_PATTERN.test(params.cid || '')) {
            return null;
        }

        return {
            categories: {
                necessary: true,
                preferences: params.p === '1',
                statistics: params.s === '1',
                marketing: params.m === '1'
            },
            decided: true,
            ts: parseInt(params.ts, 10) || 0,
            policy: parseInt(params.pv, 10) || 0,
            cid: params.cid,
            source: SOURCES.indexOf(params.src) >= 0 ? params.src : ''
        };
    }

    function readCookie(name) {
        var pairs = document.cookie ? document.cookie.split(';') : [];
        var i;
        var pair;

        for (i = 0; i < pairs.length; i++) {
            pair = pairs[i].trim();
            if (pair.indexOf(name + '=') === 0) {
                return pair.slice(name.length + 1);
            }
        }

        return '';
    }

    function loadState() {
        var parsed = parseCookieValue(readCookie(COOKIE_NAME));

        // A decision made under an older policy version is treated as undecided (re-prompt).
        if (!parsed || parsed.policy < currentPolicy()) {
            return undecidedState();
        }

        return parsed;
    }

    function writeCookie(next) {
        var query = [
            'v=1',
            'p=' + bit(next.categories.preferences),
            's=' + bit(next.categories.statistics),
            'm=' + bit(next.categories.marketing),
            'pv=' + next.policy,
            'ts=' + next.ts,
            'cid=' + next.cid,
            'src=' + next.source
        ].join('&');
        var domain = cookieDomain();

        document.cookie = COOKIE_NAME + '=' + encodeURIComponent(query) +
            '; Path=/; Max-Age=' + (cookieDays() * 86400) +
            '; SameSite=Lax' + (domain ? '; Domain=' + domain : '') +
            (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function expireCookie(name, domain) {
        document.cookie = name + '=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT' +
            (domain ? '; Domain=' + domain : '');
    }

    function candidateDomains() {
        var host = window.location.hostname;
        var parts = host.split('.');
        var parent = parts.length > 2 ? parts.slice(1).join('.') : host;
        var domains = ['', host, '.' + host, parent, '.' + parent];

        return domains.filter(function (domain, index) {
            return domains.indexOf(domain) === index;
        });
    }

    function inventoryRows() {
        var rows = consentConfig().inventory;

        if (!Array.isArray(rows)) {
            rows = config().banner && Array.isArray(config().banner.inventory) ? config().banner.inventory : [];
        }

        return rows;
    }

    /**
     * Delete the cookies listed in the inventory for the revoked categories
     * (exact and prefix matches, current host and parent domain).
     */
    function removeCookiesFor(revoked) {
        var names = document.cookie ? document.cookie.split(';') : [];
        var rules = inventoryRows().filter(function (row) {
            return revoked.indexOf(row.category) >= 0;
        });
        var domains = candidateDomains();

        names.forEach(function (pair) {
            var name = pair.split('=')[0].trim();

            if (!name || name === COOKIE_NAME) {
                return;
            }

            rules.forEach(function (row) {
                var matches = row.wildcard ? name.indexOf(row.name) === 0 : name === row.name;

                if (matches) {
                    domains.forEach(function (domain) {
                        expireCookie(name, domain);
                    });
                }
            });
        });
    }

    function gcmSignals(s) {
        var map = consentConfig().gcm || GCM_DEFAULT;
        var signals = { security_storage: 'granted' };

        CATEGORIES.forEach(function (category) {
            var list = Array.isArray(map[category]) ? map[category] : GCM_DEFAULT[category];

            list.forEach(function (signal) {
                signals[signal] = s.categories[category] ? 'granted' : 'denied';
            });
        });

        return signals;
    }

    function gtagUpdate(next) {
        window.dataLayer = window.dataLayer || [];

        if (typeof window.gtag !== 'function') {
            window.gtag = function () {
                window.dataLayer.push(arguments);
            };
        }

        window.gtag('consent', 'update', gcmSignals(next));
    }

    function pushDataLayer(eventName, next) {
        window.dataLayer = window.dataLayer || [];
        // Flat payload, identical to the adc_consent_init shape (§2.4): one
        // consistent shape for GTM variables across init and update.
        window.dataLayer.push({
            event: eventName,
            adc_consent: {
                preferences: next.categories.preferences,
                statistics: next.categories.statistics,
                marketing: next.categories.marketing,
                decided: next.decided,
                ts: next.ts,
                policy: next.policy,
                source: next.source
            },
            gcm: gcmSignals(next)
        });
    }

    function notify(next, prev) {
        listeners.slice().forEach(function (callback) {
            try {
                callback(cloneState(next), cloneState(prev));
            } catch (error) {
                // One failing listener must not stop the others.
            }
        });

        document.dispatchEvent(new CustomEvent('adc:consent:change', {
            detail: { state: cloneState(next), prev: cloneState(prev) }
        }));
    }

    /**
     * Log the decision server-side. `action` is the optional explicit log
     * action (e.g. 'withdraw'); the server infers it from history when empty.
     * Retried once; never blocks the UI.
     */
    function sendDecision(next, attempt, action) {
        var cfg = config();
        var base = cfg.rest || cfg.restUrl;
        var headers = { 'Content-Type': 'application/json' };
        var body;

        if (!base || typeof window.fetch !== 'function') {
            return;
        }

        if (cfg.token) {
            headers['X-ADC-Token'] = cfg.token;
        }

        body = {
            preferences: bit(next.categories.preferences),
            statistics: bit(next.categories.statistics),
            marketing: bit(next.categories.marketing),
            source: next.source || 'api',
            policy: next.policy
        };

        if (typeof action === 'string' && action) {
            body.action = action;
        }

        window.fetch(String(base).replace(/\/*$/, '/') + 'consent', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(body),
            credentials: 'same-origin',
            keepalive: true
        }).then(function (response) {
            if (!response.ok && attempt < 1) {
                sendDecision(next, attempt + 1, action);
            }
        }).catch(function () {
            if (attempt < 1) {
                sendDecision(next, attempt + 1, action);
            }
        });
    }

    function newCid() {
        var bytes;
        var hex = '';
        var i;

        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
        bytes[8] = (bytes[8] & 0x3f) | 0x80; // RFC 4122 variant

        for (i = 0; i < 16; i++) {
            hex += (bytes[i] + 0x100).toString(16).slice(1);
            if (i === 3 || i === 5 || i === 7 || i === 9) {
                hex += '-';
            }
        }

        return hex;
    }

    function has(category) {
        if (category === 'necessary') {
            return true;
        }

        return state.decided && state.categories[category] === true;
    }

    function onChange(callback) {
        if (typeof callback !== 'function') {
            return noop;
        }

        listeners.push(callback);

        return function () {
            listeners = listeners.filter(function (item) {
                return item !== callback;
            });
        };
    }

    function whenAllowed(category, callback) {
        var unsubscribe;

        if (typeof callback !== 'function') {
            return noop;
        }

        if (has(category)) {
            callback();
            return noop;
        }

        unsubscribe = onChange(function () {
            if (has(category)) {
                unsubscribe();
                callback();
            }
        });

        return unsubscribe;
    }

    /**
     * Persist a decision. Order: cookie, Consent Mode update, dataLayer,
     * DOM event and listeners, server log, then cleanup of revoked cookies.
     * `action` is an optional explicit server log action (withdraw uses it).
     */
    function applyDecision(changes, source, action) {
        var prev = cloneState(state);
        var next = cloneState(state);
        var revoked = [];

        changes = changes || {};

        CATEGORIES.forEach(function (category) {
            var value = changes[category];

            if (typeof value !== 'undefined') {
                next.categories[category] = value === true || value === 1 || value === '1';
            }

            if (prev.categories[category] && !next.categories[category]) {
                revoked.push(category);
            }
        });

        // Global Privacy Control (§2.2): when the browser signals Sec-GPC and
        // honour_gpc is on, marketing is denied no matter what was requested;
        // the decision is recorded with source `gpc` for the audit trail.
        if (gpcActive() && next.categories.marketing) {
            next.categories.marketing = false;
            source = 'gpc';
            if (revoked.indexOf('marketing') < 0) {
                revoked.push('marketing');
            }
        }

        next.decided = true;
        next.ts = Math.floor(Date.now() / 1000);
        next.policy = currentPolicy();
        next.cid = UUID_PATTERN.test(prev.cid) ? prev.cid : newCid();
        next.source = SOURCES.indexOf(source) >= 0 ? source : 'api';
        state = next;

        writeCookie(next);
        gtagUpdate(next);
        pushDataLayer('adc_consent_update', next);
        notify(next, prev);
        sendDecision(next, 0, action);

        if (revoked.length) {
            removeCookiesFor(revoked);
        }

        return Promise.resolve(cloneState(next));
    }

    function set(changes, source) {
        return applyDecision(changes, source, '');
    }

    function acceptAll(source) {
        return set({ preferences: true, statistics: true, marketing: true }, source || 'api');
    }

    function rejectAll(source) {
        return set({ preferences: false, statistics: false, marketing: false }, source || 'api');
    }

    /**
     * Withdraw the decision: deny every optional category and log the
     * explicit 'withdraw' action server-side (audit trail, D18).
     */
    function withdraw() {
        var result = applyDecision({ preferences: false, statistics: false, marketing: false }, 'api', 'withdraw');

        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ event: 'adc_consent_withdraw' });

        return result;
    }

    function openPreferences() {
        if (!config().banner || !config().banner.enabled) {
            return;
        }

        document.dispatchEvent(new CustomEvent('adc:consent:open'));
    }

    /**
     * Clear the decision and re-prompt the visitor.
     */
    function reset() {
        var prev = cloneState(state);
        var domain = cookieDomain();

        expireCookie(COOKIE_NAME, '');
        if (domain) {
            expireCookie(COOKIE_NAME, domain); // Also clear the shared-domain cookie (M3).
        }
        state = undecidedState();
        notify(state, prev);

        return Promise.resolve(cloneState(state));
    }

    window.adcConsent = {
        version: 1,
        get: function () {
            return cloneState(state);
        },
        has: has,
        whenAllowed: whenAllowed,
        onChange: onChange,
        set: set,
        acceptAll: acceptAll,
        rejectAll: rejectAll,
        withdraw: withdraw,
        openPreferences: openPreferences,
        reset: reset,
        gcm: function () {
            return gcmSignals(state);
        }
    };

    state = loadState();

    document.dispatchEvent(new CustomEvent('adc:consent:ready', {
        detail: { state: cloneState(state) }
    }));
})();
