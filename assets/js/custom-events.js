/* ADC custom-event runtime. Privacy-safe, cache-safe, and intentionally dependency-free. */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root && root.document) api.init(root);
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  var DOWNLOAD_EXT = /\.(pdf|zip|rar|7z|doc|docx|xls|xlsx|ppt|pptx|csv|txt|odt|ods|mp3|mp4|epub)$/i;
  var TOKEN_RE = /\{\{([^{}]+)\}\}/g;
  var STABLE_ID_PREFIX = /^(elementor-|e-|ui-id-|react-|ember\d|yui_|mce_|ext-gen|__next|radix-|headlessui-|wpforms-\d|wpcf7-f\d|gform_\d|select2-|swiper-wrapper-)/i;

  function normalizeText(value) {
    var text = String(value == null ? '' : value).toLowerCase();
    if (text.normalize) text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    return text.replace(/\s+/g, ' ').trim();
  }

  function scrub(value) {
    var text = String(value == null ? '' : value);
    text = text.replace(/[^\s@]+@[^\s@]+\.[^\s@]+/g, '[email]');
    text = text.replace(/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/gi, '[iban]');
    text = text.replace(/\d(?:[\d\s\-.()]*\d)?/g, function (part) {
      return part.replace(/\D/g, '').length >= 7 ? '[nr]' : part;
    });
    return text.replace(/\b[A-Za-z0-9_-]{32,}\b/g, '[id]');
  }

  function parseUrl(value, base) {
    try { return new URL(String(value || ''), base || 'https://invalid.local/'); }
    catch (error) { return null; }
  }

  function classifyHref(href, currentHost) {
    var url = parseUrl(href, 'https://' + String(currentHost || 'invalid.local') + '/');
    if (!url) return '';
    var protocol = url.protocol.toLowerCase();
    var host = url.hostname.toLowerCase().replace(/^www\./, '');
    if (protocol === 'tel:') return 'tel';
    if (protocol === 'mailto:') return 'mailto';
    if (protocol === 'whatsapp:') return 'whatsapp';
    if (host === 'wa.me' || host === 'api.whatsapp.com' || host === 'web.whatsapp.com') return 'whatsapp';
    if (url.protocol === 'http:' || url.protocol === 'https:') {
      if (url.pathname.toLowerCase().split('/').pop().match(DOWNLOAD_EXT) || url.pathname.toLowerCase().match(DOWNLOAD_EXT)) return 'download';
      var current = String(currentHost || '').toLowerCase().replace(/^www\./, '').split(':')[0];
      return host && current && host !== current ? 'outbound' : '';
    }
    return '';
  }

  function matchesPage(filter, path) {
    filter = filter || {};
    var mode = filter.mode || 'all';
    var pathname = String(path || '/').split(/[?#]/)[0] || '/';
    var paths = Array.isArray(filter.paths) ? filter.paths : [];
    var found = paths.some(function (pattern) {
      var escaped = String(pattern).replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
      try { return new RegExp('^' + escaped + '$').test(pathname); } catch (error) { return false; }
    });
    if (mode === 'include') return found;
    if (mode === 'exclude') return !found;
    return true;
  }

  function scrollPercent(scrollY, innerHeight, scrollHeight) {
    var height = Math.max(Number(scrollHeight) || 0, 1);
    return ((Number(scrollY) || 0) + (Number(innerHeight) || 0)) / height * 100;
  }

  function resolveParams(params, ctx) {
    ctx = ctx || {};
    var tokens = ctx.tokens || ctx;
    var output = {};
    (Array.isArray(params) ? params : []).forEach(function (pair) {
      if (!Array.isArray(pair) || pair.length < 2) return;
      var key = String(pair[0]);
      var value = String(pair[1] == null ? '' : pair[1]).replace(TOKEN_RE, function (match, name) {
        var token = '{{' + name + '}}';
        return Object.prototype.hasOwnProperty.call(tokens, token) ? String(tokens[token] == null ? '' : tokens[token]) : '';
      });
      output[key] = scrub(value).slice(0, 100);
    });
    return output;
  }

  function compileRules(rules, doc) {
    var list = Array.isArray(rules) ? rules : [];
    return list.map(function (rule) {
      var compiled = Object.assign({}, rule, { disabled: false, selector: null });
      var selector = rule && rule.trigger && rule.trigger.selector;
      if (selector) {
        try {
          var fragment = doc && doc.createDocumentFragment ? doc.createDocumentFragment() : null;
          if (!fragment || typeof fragment.querySelector !== 'function') throw new Error('selector_context_unavailable');
          fragment.querySelector(selector);
          compiled.selector = selector;
        } catch (error) {
          compiled.disabled = true;
          compiled.selector = null;
        }
      }
      return compiled;
    });
  }

  function shouldFire(rule, state, elementKey, now) {
    state = state || {};
    now = Number(now) || Date.now();
    var id = String(rule && rule.id || '');
    if (!id) return false;
    if (rule.fire !== 'every') {
      state.fired = state.fired || Object.create(null);
      if (state.fired[id]) return false;
      state.fired[id] = true;
      return true;
    }
    state.lastFire = state.lastFire || Object.create(null);
    var key = id + ':' + String(elementKey == null ? '' : elementKey);
    if (now - (state.lastFire[key] || 0) < 1000) return false;
    state.lastFire[key] = now;
    return true;
  }

  function isStableId(id) {
    id = String(id || '');
    if (id.length < 2 || id.length > 40 || /\d{4,}/.test(id) || /^[0-9]/.test(id) || STABLE_ID_PREFIX.test(id)) return false;
    if (/[0-9a-f]{6,}/i.test(id) && /\d/.test(id)) return false;
    return !/[0-9a-f]{8}-[0-9a-f]{4}-/i.test(id);
  }

  function closestSafe(element, selector) {
    if (!element || !selector) return null;
    try { return typeof element.closest === 'function' ? element.closest(selector) : null; }
    catch (error) { return null; }
  }

  function matchesSafe(element, selector) {
    if (!element || !selector) return false;
    try { return typeof element.matches === 'function' && element.matches(selector); }
    catch (error) { return false; }
  }

  function clickText(element) {
    if (!element) return '';
    try {
      if (closestSafe(element, 'input[type="password"],input[type="email"],input[type="tel"],input[type="text"],input[type="search"],input[type="number"],textarea,select,[contenteditable=""],[contenteditable="true"]')) return '';
      var text = String(element.innerText || '').replace(/\s+/g, ' ').trim();
      if (!text && element.getAttribute) text = String(element.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim();
      if (!text && element.tagName && String(element.tagName).toLowerCase() === 'input' && /^(submit|button)$/i.test(element.type || '')) text = String(element.value || '');
      return scrub(text).slice(0, 100);
    } catch (error) { return ''; }
  }

  function hrefData(element, location) {
    var raw = '';
    try { raw = String(element.href || element.getAttribute('href') || ''); } catch (error) { return { type: '', url: '', domain: '', ext: '' }; }
    var kind = classifyHref(raw, location.host);
    var url = parseUrl(raw, location.href);
    if (!url) return { type: kind, url: '', domain: '', ext: '' };
    if (kind === 'tel' || kind === 'mailto' || kind === 'whatsapp') return { type: kind, url: kind, domain: '', ext: '' };
    var path = url.pathname || '';
    var match = path.toLowerCase().match(/\.([a-z0-9]+)$/);
    return {
      type: kind,
      url: scrub(url.origin + path).slice(0, 200),
      domain: url.hostname.toLowerCase().replace(/^www\./, ''),
      ext: match ? match[1].toLowerCase() : ''
    };
  }

  function init(win) {
    var config = win.adcConfig || {};
    var custom = config.customEvents;
    if (!custom || custom.v !== 1 || !Array.isArray(custom.rules)) return;
    var doc = win.document;
    var debug = function (error, id) {
      if (win.adcTrack && win.adcTrack.debug && win.console && win.console.warn) win.console.warn('[adc-custom-events]', id || '', error);
    };
    var rules = compileRules(custom.rules, doc).filter(function (rule) {
      if (rule.disabled) debug('invalid selector', rule.id);
      return !rule.disabled && rule.id && rule.name;
    });
    var fired = Object.create(null), lastFire = Object.create(null), elementIds = new WeakMap(), nextElementId = 1;
    var scrollSeen = Object.create(null), timers = Object.create(null), timerReached = Object.create(null), visibleCounts = Object.create(null), visibleTimers = Object.create(null), visibleNodes = Object.create(null), visibleTriggered = Object.create(null);
    var rafPending = false, mutationTimer = null, lastScroll = 0;

    function consentOk(category) {
      if (category === 'marketing' && config.consent && config.consent.gpc === true && win.navigator && win.navigator.globalPrivacyControl === true) return false;
      if (!win.adcConsent) return true;
      try { return typeof win.adcConsent.has === 'function' && win.adcConsent.has(category); } catch (error) { return false; }
    }
    function mirrorOk(category) {
      try { return !!win.adcConsent && typeof win.adcConsent.has === 'function' && win.adcConsent.has(category); }
      catch (error) { return false; }
    }
    function onCurrentPage(rule) {
      return matchesPage(rule.page_filter, win.location.pathname);
    }
    function tokenValues(rule, context) {
      context = context || {};
      var values = Object.create(null);
      values['{{page_path}}'] = scrub(String(win.location.pathname || '').slice(0, 200));
      if (context.element) {
        var el = context.element;
        values['{{click_text}}'] = clickText(el);
        var href = hrefData(el, win.location);
        values['{{click_url}}'] = href.url;
        values['{{link_domain}}'] = href.domain;
        values['{{file_ext}}'] = href.ext;
        var elementId = '';
        try { elementId = isStableId(el.id) ? el.id : ''; } catch (error) { elementId = ''; }
        values['{{element_id}}'] = scrub(elementId);
      }
      if (context.formId !== undefined) values['{{form_id}}'] = scrub(String(context.formId || ''));
      if (context.scrollPercent !== undefined) values['{{scroll_percent}}'] = String(context.scrollPercent);
      if (context.timerSeconds !== undefined) values['{{timer_seconds}}'] = String(context.timerSeconds);
      return values;
    }
    function fire(rule, context) {
      try {
        if (!onCurrentPage(rule) || !consentOk(rule.category)) return null;
        context = context || {};
        var elementKey = '';
        if (context.element) {
          if (!elementIds.has(context.element)) elementIds.set(context.element, nextElementId++);
          elementKey = elementIds.get(context.element);
        }
        var key = rule.id + (rule.fire === 'every' ? ':' + elementKey : '');
        var onceKey = rule.id + (context.onceKey ? ':' + context.onceKey : '');
        var now = Date.now();
        if (rule.fire !== 'every' && fired[onceKey]) return null;
        if (rule.fire === 'every' && now - (lastFire[key] || 0) < 1000) return null;
        var params = resolveParams(rule.params, { tokens: tokenValues(rule, context) });
        params.adc_rule_id = rule.id;
        if (rule.value !== null && rule.value !== undefined) { params.value = Number(rule.value); params.currency = String(rule.currency || ''); }
        var data = { params: params };
        if (!win.adcTrack || typeof win.adcTrack.event !== 'function') return null;
        var id = win.adcTrack.event(rule.name, data, { mirror: rule.mirror === true && mirrorOk(rule.category) });
        if (id) {
          if (rule.fire !== 'every') fired[onceKey] = true;
          else lastFire[key] = now;
        }
        return id;
      } catch (error) { debug(error, rule && rule.id); return null; }
    }

    var clickRules = rules.filter(function (r) { return r.trigger.type === 'click'; });
    function onClick(event) {
      try {
        if (!event.isTrusted) return;
        var target = event.target;
        if (event.type === 'auxclick' && !closestSafe(target, 'a[href]')) return;
        clickRules.forEach(function (rule) {
          try {
            var trigger = rule.trigger || {};
            var anchor = closestSafe(target, 'a[href]');
            var element = trigger.selector ? closestSafe(target, trigger.selector) : anchor;
            if (!element || (trigger.selector && !matchesSafe(element, trigger.selector) && !closestSafe(target, trigger.selector))) return;
            if (trigger.preset) {
              if (!anchor) return;
              var kind = classifyHref(anchor.href || anchor.getAttribute('href'), win.location.host);
              var downloadAttr = trigger.preset === 'download' && anchor.hasAttribute && anchor.hasAttribute('download');
              if (kind !== trigger.preset && !downloadAttr) return;
            }
            var text = normalizeText(clickText(element));
            if (trigger.match_text && !text.includes(normalizeText(trigger.match_text))) return;
            if (trigger.match_href) {
              var url = parseUrl(anchor ? anchor.href : element.href, win.location.href);
              var safeHref = url ? (url.origin + url.pathname).toLowerCase() : '';
              if (!safeHref.includes(String(trigger.match_href).toLowerCase())) return;
            }
            fire(rule, { element: anchor || element });
          } catch (error) { debug(error, rule.id); }
        });
      } catch (error) { debug(error); }
    }
    if (clickRules.length) {
      doc.addEventListener('click', onClick, { capture: true, passive: true });
      doc.addEventListener('auxclick', function (event) { try { if (event.button === 1) onClick(event); } catch (error) { debug(error); } }, { capture: true, passive: true });
    }

    var formRules = rules.filter(function (r) { return r.trigger.type === 'form_success'; });
    var formGroups = Object.create(null);
    function formIdMatch(rule, id) { return !rule.trigger.form_id || String(id || '') === String(rule.trigger.form_id); }
    formRules.forEach(function (rule) {
      var preset = rule.trigger.form_preset;
      if (!formGroups[preset]) formGroups[preset] = [];
      formGroups[preset].push(rule);
    });
    function fireFormRules(preset, id) {
      (formGroups[preset] || []).forEach(function (rule) {
        try { if (formIdMatch(rule, id)) fire(rule, { formId: id }); } catch (error) { debug(error, rule.id); }
      });
    }
    Object.keys(formGroups).forEach(function (preset) {
      if (preset === 'cf7') {
        doc.addEventListener('wpcf7mailsent', function (event) {
          try { var id = event.detail && event.detail.contactFormId; fireFormRules(preset, id); } catch (error) { debug(error); }
        });
      } else if (win.jQuery && (preset === 'wpforms' || preset === 'elementor' || preset === 'woo_coupon')) {
        try {
          if (preset === 'wpforms') win.jQuery(doc).on('wpformsAjaxSubmitSuccess', function (event) {
            try { var id = event.target && event.target.dataset ? event.target.dataset.formid : ''; fireFormRules(preset, id); } catch (error) { debug(error); }
          });
          if (preset === 'elementor') win.jQuery(doc).on('submit_success', '.elementor-form', function (event) {
            try { var form = event.target; var widget = closestSafe(form, '[data-id]'); var id = widget && widget.dataset ? widget.dataset.id : ''; fireFormRules(preset, id); } catch (error) { debug(error); }
          });
          if (preset === 'woo_coupon') win.jQuery(doc.body).on('applied_coupon applied_coupon_in_checkout', function () { try { fireFormRules(preset, ''); } catch (error) { debug(error); } });
        } catch (error) { debug(error); }
      } else if (preset !== 'cf7') debug('jQuery unavailable for ' + preset, formGroups[preset][0].id);
    });

    var visibleRules = rules.filter(function (r) { return r.trigger.type === 'visible'; });
    var observers = Object.create(null);
    function observeVisible() {
      visibleRules.forEach(function (rule) {
        if (!win.IntersectionObserver || fired[rule.id] || (visibleCounts[rule.id] || 0) >= 50) return;
        try {
          var threshold = String(rule.trigger.threshold);
          if (!observers[threshold]) observers[threshold] = new win.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              visibleRules.forEach(function (candidate) {
                if (String(candidate.trigger.threshold) !== threshold || fired[candidate.id] || visibleTriggered[candidate.id]) return;
                try {
                  if (candidate.selector && !matchesSafe(entry.target, candidate.selector)) return;
                  if (entry.isIntersecting && entry.intersectionRatio >= Number(candidate.trigger.threshold)) {
                    if (!visibleTimers[candidate.id]) visibleTimers[candidate.id] = new WeakMap();
                    var ruleTimers = visibleTimers[candidate.id];
                    var old = ruleTimers.get(entry.target);
                    if (old) win.clearTimeout(old);
                    var timer = win.setTimeout(function () { try { if (!fired[candidate.id] && !visibleTriggered[candidate.id]) { visibleTriggered[candidate.id] = true; fire(candidate, { element: entry.target }); } } catch (error) { debug(error, candidate.id); } }, Number(candidate.trigger.min_visible_ms) || 0);
                    ruleTimers.set(entry.target, timer);
                  } else {
                    var ruleTimers = visibleTimers[candidate.id];
                    var pending = ruleTimers && ruleTimers.get(entry.target);
                    if (pending) win.clearTimeout(pending);
                  }
                } catch (error) { debug(error, candidate.id); }
              });
            });
          }, { threshold: Number(rule.trigger.threshold) });
          var selector = rule.selector;
          if (!selector) return;
          var nodes = [];
          try { nodes = Array.prototype.slice.call(doc.querySelectorAll(selector)); } catch (error) { debug(error, rule.id); }
          if (!visibleNodes[rule.id]) visibleNodes[rule.id] = new WeakSet();
          nodes.forEach(function (node) {
            if (visibleCounts[rule.id] >= 50 || visibleNodes[rule.id].has(node)) return;
            try {
              observers[threshold].observe(node);
              visibleNodes[rule.id].add(node);
              visibleCounts[rule.id] = (visibleCounts[rule.id] || 0) + 1;
            } catch (error) { debug(error, rule.id); }
          });
        } catch (error) { debug(error, rule.id); }
      });
    }
    if (visibleRules.length && win.IntersectionObserver) {
      observeVisible();
      if (win.MutationObserver && doc.body) {
        try {
          var mutationObserver = new win.MutationObserver(function () {
            try { if (mutationTimer) win.clearTimeout(mutationTimer); mutationTimer = win.setTimeout(observeVisible, 250); } catch (error) { debug(error); }
          });
          mutationObserver.observe(doc.body, { childList: true, subtree: true });
        } catch (error) { debug(error); }
      }
    }

    var scrollRules = rules.filter(function (r) { return r.trigger.type === 'scroll_depth'; });
    function onScroll(event) {
      try {
        if (event && event.isTrusted === false) return;
        var now = Date.now();
        if (now - lastScroll < 200 || rafPending) return;
        lastScroll = now;
        rafPending = true;
        win.requestAnimationFrame(function () {
          rafPending = false;
          try {
            var pct = scrollPercent(win.scrollY || win.pageYOffset, win.innerHeight, doc.documentElement.scrollHeight);
            scrollRules.forEach(function (rule) {
              (rule.trigger.depths || []).slice().sort(function (a, b) { return a - b; }).forEach(function (depth) {
                var key = rule.id + ':' + depth;
                if (!scrollSeen[key] && pct >= Number(depth)) { scrollSeen[key] = true; fire(rule, { scrollPercent: depth, onceKey: 'scroll_' + depth }); }
              });
            });
          } catch (error) { debug(error); }
        });
      } catch (error) { debug(error); }
    }
    if (scrollRules.length) win.addEventListener('scroll', onScroll, { passive: true });

    var timerRules = rules.filter(function (r) { return r.trigger.type === 'timer'; });
    if (timerRules.length) {
      win.setInterval(function () {
        try {
          if (doc.visibilityState !== 'visible') return;
          timerRules.forEach(function (rule) {
            if (timerReached[rule.id]) return;
            timers[rule.id] = (timers[rule.id] || 0) + 1;
            if (timers[rule.id] >= Number(rule.trigger.seconds) && !fired[rule.id]) {
              timerReached[rule.id] = true;
              fire(rule, { timerSeconds: Number(rule.trigger.seconds) });
            }
          });
        } catch (error) { debug(error); }
      }, 1000);
    }

    win.addEventListener('pageshow', function (event) {
      try { if (event.persisted) { fired = Object.create(null); scrollSeen = Object.create(null); timers = Object.create(null); timerReached = Object.create(null); visibleTriggered = Object.create(null); } }
      catch (error) { debug(error); }
    });

    win.adcCustomEvents = {
      version: 1,
      active: function () { return rules.filter(function (r) { return !r.disabled; }).map(function (r) { return r.id; }); }
    };
  }

  return {
    compileRules: compileRules,
    matchesPage: matchesPage,
    classifyHref: classifyHref,
    clickText: clickText,
    resolveParams: resolveParams,
    scrub: scrub,
    normalizeText: normalizeText,
    scrollPercent: scrollPercent,
    shouldFire: shouldFire,
    isStableId: isStableId,
    init: init
  };
});
