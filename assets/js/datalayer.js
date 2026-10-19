/**
 * ADC dataLayer core — window.adcTrack (docs/ARCHITECTURE.md §2.3, §2.4).
 *
 * Loaded in <head> with defer, depends on adc-consent (when present).
 * Responsibilities:
 *   - single push path for browser-origin events (GA4 ecommerce shape,
 *     {ecommerce:null} clear-before-push, mandatory event_id on ecommerce
 *     events, `adc` metadata namespace);
 *   - server-event consumption (window.adcServerEvents from the footer and
 *     the adc_events cart fragment) with event_id dedupe — never mirrored;
 *   - mirroring browser events to POST {rest}event via keepalive fetch
 *     when consent allows statistics|marketing (and the event's
 *     catalogue mirror flag is on); browsers without fetch skip the
 *     mirror (sendBeacon cannot attach the X-ADC-Token header);
 *   - loading the GTM container in after_consent mode on the first granted
 *     non-necessary category.
 * No raw PII ever enters dataLayer (§6). Vanilla JS, no jQuery, defer.
 */
(function () {
  'use strict';

  var cfg = window.adcConfig || {};
  var gtmCfg = cfg.gtm || {};
  var eventsCfg = cfg.events || {};
  var dlName = gtmCfg.datalayerName || 'dataLayer';

  // dataLayer may already exist (consent head script) — never reset it.
  window[dlName] = window[dlName] || [];
  var dataLayer = window[dlName];

  // Mirror default per catalogue (§2.4 "Server mirror" column).
  var MIRROR_DEFAULT = {
    page_view: false, // only when events.server_page_view (checked dynamically)
    view_item_list: false,
    select_item: false,
    view_item: true,
    add_to_cart: true,
    remove_from_cart: false,
    view_cart: false,
    begin_checkout: true,
    add_shipping_info: false,
    add_payment_info: true,
    purchase: false, // browser purchase push is the server's event_id; server mirrors
    refund: false,
    search: false, // server-origin only
    sign_up: false,
    login: false,
    generate_lead: true
  };

  var debug = /[?&]adc_debug=1/.test(window.location.search) &&
    !!document.getElementById('wpadminbar');

  // event_id dedupe: same id never pushed twice (fragment + footer overlap,
  // Store API retries, double custom events).
  var pushedIds = Object.create(null);
  var gtmLoaded = false;

  function log() {
    if (debug && window.console && console.debug) {
      var args = Array.prototype.slice.call(arguments);
      args.unshift('[adc]');
      console.debug.apply(console, args);
    }
  }

  /** 'adc_' + 16 hex chars via crypto (browser-origin ids, §2.1). */
  function newEventId() {
    var bytes = new Uint8Array(8);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 8; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    var out = '';
    for (var j = 0; j < bytes.length; j++) {
      out += (bytes[j] + 0x100).toString(16).slice(1);
    }
    return 'adc_' + out;
  }

  /** Consent gate for mirrors — uses the C3 API, falls back to false. */
  function consentAllowsMirror() {
    if (!window.adcConsent || typeof window.adcConsent.has !== 'function') {
      return false;
    }
    return window.adcConsent.has('statistics') || window.adcConsent.has('marketing');
  }

  /** Per-event switch from adcConfig.events (§2.3). */
  function eventEnabled(name) {
    return eventsCfg[name] !== false;
  }

  function pushObject(obj) {
    dataLayer.push(obj);
    log('push', obj.event, obj.event_id || '', obj);
  }

  /**
   * Push an ecommerce event object with clear-before-push (U9: one style
   * only — ecommerce wrapper + {ecommerce:null} reset).
   */
  function pushEcommerce(name, ecommerce, adc) {
    dataLayer.push({ ecommerce: null });
    var obj = { event: name, ecommerce: ecommerce };
    if (adc) obj.adc = adc;
    pushObject(obj);
    return obj;
  }

  /**
   * Mirror a browser event to POST {rest}event (§2.2). Fire-and-forget:
   * keepalive fetch carries the JSON + X-ADC-Token header. Browsers without
   * fetch cannot authenticate (sendBeacon cannot set headers and the route
   * rejects tokenless bodies with 403), so the mirror is dropped loudly
   * instead of firing a request that is guaranteed to fail.
   * Mirrors are only attempted with statistics|marketing consent.
   */
  function mirror(name, eventId, payload) {
    if (!consentAllowsMirror()) {
      log('mirror skipped (no consent)', name);
      return;
    }
    var rest = cfg.rest;
    if (!rest) return;

    var body = {
      event_id: eventId,
      name: name,
      source_url: window.location.href,
      items: (payload && payload.mirrorItems) || [],
      params: (payload && payload.params) || {}
    };
    if (payload && typeof payload.value === 'number') body.value = payload.value;
    if (payload && payload.currency) body.currency = payload.currency;

    var json;
    try {
      json = JSON.stringify(body);
    } catch (e) {
      return;
    }
    if (json.length > 4096) {
      log('mirror skipped (payload too large)', name);
      return;
    }

    var url = rest + 'event';
    if (!window.fetch) {
      // No fetch ⇒ no X-ADC-Token header; sendBeacon cannot set headers and
      // the route fails closed (403 invalid_token), so drop the mirror with
      // a debug trace instead of a silent guaranteed failure.
      log('mirror skipped (fetch unavailable)', name);
      return;
    }
    var headers = { 'Content-Type': 'application/json' };
    if (cfg.token) headers['X-ADC-Token'] = cfg.token;
    try {
      window.fetch(url, {
        method: 'POST',
        headers: headers,
        body: json,
        keepalive: true,
        credentials: 'same-origin'
      }).catch(function () { /* network failure is never retried client-side */ });
    } catch (e) { /* ignore: mirror is fire-and-forget */ }
  }

  /**
   * adcTrack.event(name, data, opts) — the ONE browser push entry (§2.3).
   *
   * @param string name   GA4 canonical event name.
   * @param object data   {currency?, value?, items?, params?, mirrorItems?}
   *                       items = GA4 item objects; mirrorItems = catalog ids
   *                       [{id, variation_id?, qty}] for the /event rebuild.
   * @param object opts   {eventId?, mirror?:bool, origin?:'browser'}
   * @return string|null  The event_id used, or null when skipped.
   */
  function event(name, data, opts) {
    if (!name || !eventEnabled(name)) {
      log('event disabled', name);
      return null;
    }
    data = data || {};
    opts = opts || {};

    var eventId = opts.eventId || newEventId();
    if (pushedIds[eventId]) {
      log('event_id duplicate, skipped', eventId);
      return null;
    }
    pushedIds[eventId] = true;

    var pageData = window.adcPageData || {};
    var adc = { origin: opts.origin || 'browser', page_type: pageData.page_type || '' };

    var hasItems = !!(data.items && data.items.length);
    if (hasItems || typeof data.value === 'number') {
      var ecommerce = {};
      if (data.currency || pageData.currency) ecommerce.currency = data.currency || pageData.currency;
      if (typeof data.value === 'number') ecommerce.value = data.value;
      if (hasItems) ecommerce.items = data.items;
      if (data.params) {
        ['transaction_id', 'tax', 'shipping', 'coupon'].forEach(function (k) {
          if (data.params[k] !== undefined && data.params[k] !== '') ecommerce[k] = data.params[k];
        });
      }
      pushEcommerce(name, ecommerce, adc);
    } else {
      var obj = { event: name };
      if (data.params) {
        Object.keys(data.params).forEach(function (k) { obj[k] = data.params[k]; });
      }
      obj.adc = adc;
      pushObject(obj);
    }

    var wantMirror = typeof opts.mirror === 'boolean'
      ? opts.mirror
      : (name === 'page_view' ? !!eventsCfg.server_page_view : MIRROR_DEFAULT[name] === true);
    if (wantMirror) mirror(name, eventId, data);

    return eventId;
  }

  /**
   * adcTrack.pushServerEvents(list) — consume server-origin pushes (footer
   * window.adcServerEvents, adc_events cart fragment). Already carry the
   * SERVER event_id; NEVER mirrored (server pipeline handles platforms).
   * Accepts ADC_Datalayer::to_pushes() objects or raw ADC_Event::to_array().
   */
  function pushServerEvents(list) {
    if (!Array.isArray(list)) return;
    list.forEach(function (entry) {
      if (!entry || typeof entry !== 'object') return;

      var obj;
      if (entry.event && entry.event_id) {
        obj = entry; // Ready push from ADC_Event_Builder::to_datalayer_push().
      } else if (entry.name && entry.event_id) {
        obj = { event: entry.name, event_id: entry.event_id };
        var ec = {};
        if (entry.currency) ec.currency = entry.currency;
        if (entry.value !== null && entry.value !== undefined) ec.value = entry.value;
        if (entry.items && entry.items.length) ec.items = entry.items;
        var ecomKeys = ['transaction_id', 'tax', 'shipping', 'coupon', 'affiliation'];
        ecomKeys.forEach(function (k) {
          if (entry.params && entry.params[k] !== undefined && entry.params[k] !== '') ec[k] = entry.params[k];
        });
        if (ec.items || ec.value !== undefined) {
          obj.ecommerce = ec;
        }
        // Non-ecommerce params at top level (search_term, method, …).
        if (entry.params) {
          Object.keys(entry.params).forEach(function (k) {
            if (ecomKeys.indexOf(k) !== -1 && obj.ecommerce) return;
            if (k === 'order_total' || k === 'purchase_channel') return;
            if (entry.params[k] === '' || entry.params[k] === null) return;
            obj[k] = entry.params[k];
          });
        }
        obj.adc = { origin: entry.origin || 'server', page_type: (window.adcPageData || {}).page_type || '' };
        if (entry.params && entry.params.order_total !== undefined) obj.adc.order_total = entry.params.order_total;
        if (entry.name === 'purchase') {
          obj.adc.purchase_channel = eventsCfg.purchase_channel || 'browser';
        }
      } else {
        return;
      }

      if (pushedIds[obj.event_id]) {
        log('server event already pushed', obj.event_id);
        return;
      }
      pushedIds[obj.event_id] = true;

      if (obj.ecommerce) dataLayer.push({ ecommerce: null });
      pushObject(obj);
    });
  }

  /**
   * adcTrack.identify — implemented by C5's identify.js; this stub keeps
   * callers working before/without that chunk.
   */
  function identifyStub() {
    return Promise.resolve(null);
  }

  function loadGtm() {
    if (gtmLoaded) return;
    gtmLoaded = true;
    var src = gtmCfg.snippetUrl;
    if (!src && gtmCfg.id) {
      src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(gtmCfg.id);
    }
    if (!src) return;
    var s = document.createElement('script');
    s.async = true;
    s.src = src;
    (document.head || document.documentElement).appendChild(s);
    log('gtm loaded (after_consent)', gtmCfg.id);
  }

  function initGtmAfterConsent() {
    if (gtmCfg.mode !== 'after_consent' || !gtmCfg.id) return;
    if (!window.adcConsent || typeof window.adcConsent.whenAllowed !== 'function') return;
    // First granted non-necessary category loads the container (§3.1).
    window.adcConsent.whenAllowed('statistics', loadGtm);
    window.adcConsent.whenAllowed('marketing', loadGtm);
  }

  window.adcTrack = {
    version: 1,
    newEventId: newEventId,
    event: event,
    pushServerEvents: pushServerEvents,
    identify: identifyStub, // replaced by identify.js (C5)
    debug: debug,
    dataLayerName: dlName
  };

  // --- Boot order (§2.4): adc_page once per full page load (footer inline
  // adcPageData is available — defer runs after parsing), then queued
  // server-origin events, then GTM after_consent wiring. page_view and
  // DOM-derived events are pushed by events.js right after this file. ---
  var pageData = window.adcPageData || null;
  if (pageData && pageData.page_type) {
    pushObject({
      event: 'adc_page',
      page_type: pageData.page_type,
      language: pageData.language || '',
      currency: pageData.currency || '',
      logged_in: pageData.logged_in === true
    });
  }

  if (Array.isArray(window.adcServerEvents)) {
    var queuedServerEvents = window.adcServerEvents;
    window.adcServerEvents = null; // consume once
    // Push AFTER events.js pushed page_view: defer scripts run with
    // readyState 'interactive' but before DOMContentLoaded fires, so wait
    // for that event (fallback: run immediately only when already complete).
    var consumeServerEvents = function () {
      pushServerEvents(queuedServerEvents);
    };
    if (document.readyState === 'complete') {
      consumeServerEvents();
    } else {
      document.addEventListener('DOMContentLoaded', consumeServerEvents);
    }
  }

  initGtmAfterConsent();

  log('adcTrack ready', dlName, eventsCfg);
})();
