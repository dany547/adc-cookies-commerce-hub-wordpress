/**
 * ADC DOM/event wiring (docs/ARCHITECTURE.md §2.4, §3.1, §3.2, U5–U7).
 *
 * Depends on adc-datalayer (adcTrack) — runs after it (defer, registered
 * deps). Pushes, in order: page_view → page-scoped ecommerce events from the
 * cache-safe footer context (adcPageData) → wires delegated listeners:
 *   - select_item clicks inside embedded loops,
 *   - classic XHR/fetch interception for the `adc_events` cart fragment
 *     (server-origin add_to_cart/remove_from_cart, never re-mirrored),
 *   - Store API/blocks: wc-blocks_added_to_cart / wc-blocks_removed_from_cart
 *     (browser origin, add_to_cart mirrors to /event),
 *   - add_shipping_info / add_payment_info on shipping/payment change
 *     (classic selects/radios + block checkout inputs),
 *   - generate_lead: NOT bound here — C5's identify.js owns the
 *     matching.form_selectors submit path (§3.3 D, U7: never auto-fired);
 *     adcTrack.event('generate_lead', …) remains for explicit calls.
 * Purchase on the order-received page arrives from the server footer queue
 * (single event_id with the pipeline), so it is NOT pushed here.
 * No PII is ever collected here — only ids, quantities and selection values.
 */
(function () {
  'use strict';

  if (!window.adcTrack) return;

  var track = window.adcTrack;
  var cfg = window.adcConfig || {};
  var eventsCfg = cfg.events || {};
  var data = window.adcPageData || null;
  var debug = track.debug === true;

  function log() {
    if (debug && window.console && console.debug) {
      var args = Array.prototype.slice.call(arguments);
      args.unshift('[adc:events]');
      console.debug.apply(console, args);
    }
  }

  function enabled(name) {
    return eventsCfg[name] !== false;
  }

  /** Catalog ids [{id, variation_id?, qty}] for the /event mirror body (§2.2). */
  function refsToMirrorItems(refs) {
    if (!Array.isArray(refs)) return [];
    return refs.map(function (r) {
      var out = { id: String(r.product_id), qty: Number(r.qty) || 1 };
      if (r.variation_id) out.variation_id = Number(r.variation_id);
      return out;
    });
  }

  function pushViewItem() {
    if (!enabled('view_item') || !data || !data.item) return;
    track.event('view_item', {
      items: [data.item],
      value: Number(data.item.price) || 0,
      currency: data.currency,
      mirrorItems: data.item_ref
        ? [refsToMirrorItems([{
            product_id: data.item_ref.product_id,
            variation_id: data.item_ref.variation_id,
            qty: 1
          }])[0]]
        : []
    });
  }

  function pushLists() {
    if (!enabled('view_item_list') || !data || !Array.isArray(data.lists)) return;
    data.lists.forEach(function (list) {
      if (!list || !list.items || !list.items.length) return;
      var value = list.items.reduce(function (sum, it) {
        return sum + (Number(it.price) || 0) * (Number(it.quantity) || 1);
      }, 0);
      track.event('view_item_list', {
        items: list.items,
        value: Math.round(value * 100) / 100,
        currency: data.currency
      });
    });
  }

  function pushViewCart() {
    if (!enabled('view_cart') || !data || data.page_type !== 'cart' || !data.cart) return;
    track.event('view_cart', {
      items: data.cart.items,
      value: Number(data.cart.value) || 0,
      currency: data.cart.currency,
      params: data.cart.coupon ? { coupon: data.cart.coupon } : {}
    });
  }

  function pushBeginCheckout() {
    if (!enabled('begin_checkout') || !data || data.page_type !== 'checkout' || !data.cart) return;
    var params = {};
    if (data.cart.coupon) params.coupon = data.cart.coupon;
    track.event('begin_checkout', {
      items: data.cart.items,
      value: Number(data.cart.value) || 0,
      currency: data.cart.currency,
      params: params,
      mirrorItems: refsToMirrorItems(data.cart.refs)
    });
  }

  /** select_item on product-link clicks inside embedded loops (no hijack). */
  function wireSelectItem() {
    if (!enabled('select_item') || !data || !Array.isArray(data.lists)) return;

    document.addEventListener('click', function (e) {
      var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!anchor) return;

      var rawHref = anchor.getAttribute('href') || '';
      var absHref = anchor.href || '';
      for (var i = 0; i < data.lists.length; i++) {
        var list = data.lists[i];
        if (!list.links) continue;
        var idx = list.links[rawHref];
        if (idx === undefined) idx = list.links[absHref];
        if (idx === undefined && absHref) {
          // Relative permalink or extra query string — match by path prefix.
          var keys = Object.keys(list.links);
          for (var k = 0; k < keys.length; k++) {
            if (keys[k] && absHref.indexOf(keys[k].split('?')[0]) === 0) {
              idx = list.links[keys[k]];
              break;
            }
          }
        }
        if (idx === undefined) continue;

        var item = list.items[idx];
        if (!item) return;
        track.event('select_item', {
          items: [item],
          currency: data.currency
        });
        return;
      }
    });
  }

  /**
   * Classic AJAX add/remove: intercept XHR/fetch responses for wc-ajax and
   * consume the `adc_events` fragment (ready pushes with the SERVER id —
   * pushServerEvents never mirrors them; the pipeline already did).
   */
  function interceptClassicFragments() {
    if (typeof XMLHttpRequest === 'undefined' || !XMLHttpRequest.prototype) return;

    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
      this.__adcUrl = String(url || '');
      return origOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
      var xhr = this;
      if ((xhr.__adcUrl || '').indexOf('wc-ajax=') !== -1) {
        xhr.addEventListener('load', function () {
          try {
            if (!xhr.responseText || xhr.responseText.indexOf('adc_events') === -1) return;
            var json = JSON.parse(xhr.responseText);
            var frag = json && json.fragments && json.fragments.adc_events;
            if (frag) {
              track.pushServerEvents(typeof frag === 'string' ? JSON.parse(frag) : frag);
            }
          } catch (err) {
            log('fragment parse failed', err);
          }
        });
      }
      return origSend.apply(this, arguments);
    };

    if (window.fetch) {
      var origFetch = window.fetch;
      window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var isWcAjax = url.indexOf('wc-ajax=') !== -1;
        return origFetch.apply(this, arguments).then(function (response) {
          if (isWcAjax) {
            try {
              response.clone().json().then(function (json) {
                var frag = json && json.fragments && json.fragments.adc_events;
                if (frag) {
                  track.pushServerEvents(typeof frag === 'string' ? JSON.parse(frag) : frag);
                }
              }).catch(function () { /* non-JSON response */ });
            } catch (err) { /* ignore */ }
          }
          return response;
        });
      };
    }
  }

  /**
   * Store API / block cart events (§3.2): server skipped emission for REST,
   * so the browser emits with a FRESH event_id and add_to_cart mirrors.
   */
  function wireBlockCartEvents() {
    function extract(detail) {
      if (!detail || typeof detail !== 'object') return null;
      var productId = detail.productId || detail.product_id || detail.id ||
        (detail.cartItem && (detail.cartItem.id || detail.cartItem.product_id)) ||
        (detail.product && detail.product.id);
      if (!productId) return null;
      var qty = Number(detail.quantity || detail.qty ||
        (detail.cartItem && detail.cartItem.quantity)) || 1;
      var variationId = Number(detail.variationId || detail.variation_id ||
        (detail.cartItem && detail.cartItem.variationId)) || 0;
      return { productId: Number(productId), qty: qty, variationId: variationId };
    }

    document.addEventListener('wc-blocks_added_to_cart', function (e) {
      if (!enabled('add_to_cart')) return;
      var ref = extract(e && e.detail);
      if (!ref) {
        log('wc-blocks_added_to_cart without productId', e && e.detail);
        return;
      }
      // Prefer full item + price from the embedded product page context.
      var item = null;
      if (data && data.item && data.item_ref && Number(data.item_ref.product_id) === ref.productId) {
        item = data.item;
      }
      if (!item) {
        item = { item_id: String(ref.productId), quantity: ref.qty };
        if (ref.variationId) item.item_id = String(ref.productId);
      } else {
        item = Object.assign({}, item, { quantity: ref.qty });
      }
      var mirrorItem = { id: String(ref.productId), qty: ref.qty };
      if (ref.variationId) mirrorItem.variation_id = ref.variationId;

      track.event('add_to_cart', {
        items: [item],
        quantity: ref.qty,
        value: Number(item.price) * ref.qty || undefined,
        currency: data && data.currency,
        mirrorItems: [mirrorItem]
      }, { mirror: true }); // §3.2 browser origin + beacon mirror.
    });

    document.addEventListener('wc-blocks_removed_from_cart', function (e) {
      if (!enabled('remove_from_cart')) return;
      var ref = extract(e && e.detail);
      if (!ref) return;
      track.event('remove_from_cart', {
        items: [{ item_id: String(ref.productId), quantity: ref.qty }],
        currency: data && data.currency
      }, { mirror: false }); // Catalogue: remove_from_cart has no server mirror.
    });
  }

  /**
   * add_shipping_info / add_payment_info on selection change (classic and
   * block checkout both render named inputs — U6: client-side only, browser
   * event_id). Deduped by value per page load so re-renders do not re-fire.
   */
  function wireCheckoutSelections() {
    var lastShipping = null;
    var lastPayment = null;

    document.addEventListener('change', function (e) {
      var el = e.target;
      if (!el || (!el.name && !el.id)) return;
      var identity = (el.name || '') + ' ' + (el.id || '');
      var value = String(el.value || '');
      // Blocks render shipping/payment radios without classic names — fall
      // back to the closest known container (U6: client-side only).
      var inShipping = el.closest && el.closest(
        '.wc-block-components-shipping-rates, [class*="shipping-rates"], [class*="shipping_method"]'
      );
      var inPayment = el.closest && el.closest('.wc-block-components-payment-methods');

      if (/shipping_method|shipping[-_]rates/i.test(identity) || inShipping) {
        if (!enabled('add_shipping_info') || !value || value === lastShipping) return;
        lastShipping = value;
        track.event('add_shipping_info', {
          params: { shipping_tier: value },
          currency: data && data.currency
        }, { mirror: false });
      } else if (/payment_method|payment[-_]method/i.test(identity) || inPayment) {
        if (!enabled('add_payment_info') || !value || value === lastPayment) return;
        lastPayment = value;
        track.event('add_payment_info', {
          params: { method: value },
          currency: data && data.currency
        }); // catalogue default mirror: yes.
      }
    }, true);
  }

  // --- Boot (defer, DOM already parsed) -----------------------------------
  if (enabled('page_view')) {
    track.event('page_view', {
      currency: data && data.currency
    }, { mirror: !!eventsCfg.server_page_view });
  }

  if (data) {
    if (data.page_type === 'product') pushViewItem();
    if (data.lists) pushLists();
    if (data.page_type === 'cart') pushViewCart();
    if (data.page_type === 'checkout') pushBeginCheckout();
  }

  wireSelectItem();
  interceptClassicFragments();
  wireBlockCartEvents();
  wireCheckoutSelections();

  log('events ready', data && data.page_type);
})();
