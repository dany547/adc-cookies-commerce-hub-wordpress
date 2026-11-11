(function (root) {
  'use strict';

  var TEST_ATTRS = ['data-testid', 'data-test', 'data-qa', 'data-cy', 'data-adc'];
  var DATA_ALLOW = ['data-id', 'data-widget_type', 'data-element_type', 'data-product_id', 'data-action', 'data-target', 'data-toggle', 'data-type', 'data-name', 'data-section', 'data-location', 'data-tab'];

  function text(value) { try { return String(value == null ? '' : value); } catch (_) { return ''; } }
  function escape(value) {
    var s = text(value);
    if (root.CSS && typeof root.CSS.escape === 'function') { try { return root.CSS.escape(s); } catch (_) {} }
    var out = '';
    for (var i = 0; i < s.length; i += 1) {
      var c = s.charCodeAt(i), ch = s.charAt(i);
      if (c === 0) { out += '\ufffd'; continue; }
      if ((c >= 1 && c <= 31) || c === 127) { out += '\\' + c.toString(16) + ' '; continue; }
      if ((i === 0 && c >= 48 && c <= 57) || (i === 1 && c >= 48 && c <= 57 && s.charAt(0) === '-')) { out += '\\' + c.toString(16) + ' '; continue; }
      if (i === 0 && ch === '-' && s.length === 1) { out += '\\-'; continue; }
      if (c >= 128 || ch === '-' || ch === '_' || /[0-9A-Za-z]/.test(ch)) { out += ch; continue; }
      out += '\\' + ch;
    }
    return out;
  }
  function escapeAttr(value) { return text(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"'); }
  function looksHash(value) { return (text(value).match(/[0-9a-f]{6,}/gi) || []).some(function (run) { return /\d/.test(run); }); }
  function isStableId(id) {
    var s = text(id);
    return s.length >= 2 && s.length <= 40 && !/\d{4,}/.test(s) && !looksHash(s) && !/^[0-9]/.test(s) && !/^(elementor-|e-|ui-id-|react-|ember\d|yui_|mce_|ext-gen|__next|radix-|headlessui-|wpforms-\d|wpcf7-f\d|gform_\d|select2-|swiper-wrapper-)/.test(s) && !/[0-9a-f]{8}-[0-9a-f]{4}-/i.test(s) && !/^(?:menu-item|post)-\d+$/.test(s) && !/^:r[0-9a-z]*:$/.test(s);
  }
  function isStableClass(value) {
    var s = text(value), cssModule = s.match(/__([A-Za-z0-9_-]{5})$/), generated = /^_[A-Za-z0-9]{5,}$/.test(s) && /[0-9A-Z]/.test(s.slice(1));
    return !!s && s.length <= 40 && !(/^css-[a-z0-9]+$/i.test(s) || /^sc-[A-Za-z0-9]+$/.test(s) || /^jsx-\d+$/.test(s) || /^svelte-[a-z0-9]+$/i.test(s) || /^emotion-/i.test(s) || generated || (cssModule && /[0-9A-Z]/.test(cssModule[1])) || /^elementor-element-[0-9a-f]{4,}$/.test(s) || /^elementor-repeater-item-/i.test(s) || /^elementor-\d+$/.test(s) || /^elementor-element-edit-mode$/.test(s) || /^e-con-inner$/.test(s) || /^e-[0-9a-f]{6,}$/i.test(s) || /^wp-container-/i.test(s) || /^wp-elements-[a-f0-9]+$/.test(s) || /^is-layout-/i.test(s) || /^wp-block-[a-z-]+-is-layout-/i.test(s) || /^has-[a-z0-9-]+-(color|background-color|font-size|gradient-background)$/i.test(s) || /^wp-image-\d+$/i.test(s) || /^attachment-/i.test(s) || /^size-/i.test(s) || /^post-\d+$/i.test(s) || /^page-id-\d+$/i.test(s) || /^postid-\d+$/i.test(s) || /^menu-item-\d+$/i.test(s) || /^menu-item-(type|object)-/i.test(s) || /^product_cat-/i.test(s) || /^product_tag-/i.test(s) || /^pa_/i.test(s) || /^(is-|has-)?(active|hover|focus|focused|open|opened|current|selected|visible|hidden|show|shown|loaded|loading|animated|sticky|expanded|collapsed)$/i.test(s) || /^current[-_]/i.test(s) || /^ct-active$/.test(s) || /^swiper-slide-(active|next|prev|visible|duplicate)/i.test(s) || /^animated$/.test(s) || /^fadeIn/i.test(s) || /^aos-/i.test(s) || /\d{3,}/.test(s));
  }
  function scrubbed(value) { var s = text(value); return !/[^\s@]+@[^\s@]+\.[^\s@]+/.test(s) && !/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i.test(s) && !/(?:\d[\s\-.()]*){7,}/.test(s) && !/\b[A-Za-z0-9_-]{32,}\b/.test(s); }
  function isStableAttr(name, value) {
    var n = text(name).toLowerCase(), v = text(value);
    if (!v || /[\u0000-\u001f\u007f]/.test(v)) return false;
    if (TEST_ATTRS.indexOf(n) !== -1) return v.length <= 60;
    if (DATA_ALLOW.indexOf(n) !== -1) return v.length <= 60 && !/\d{6,}/.test(v) && !looksHash(v) && !(n === 'data-id' && /^[0-9a-f]{4,}$/i.test(v));
    if (['aria-label', 'title', 'name', 'alt'].indexOf(n) !== -1) return v.length <= 60 && scrubbed(v);
    return false;
  }
  function matches(el, selector) { try { return !!el.matches(selector); } catch (_) { return false; } }
  function isInteractive(node) {
    var tag = text(node.tagName).toLowerCase();
    if (['a', 'button', 'summary', 'label'].indexOf(tag) !== -1) return true;
    if (tag === 'input' && ['submit', 'button'].indexOf(text(node.getAttribute('type')).toLowerCase()) !== -1) return true;
    var role = node.getAttribute('role'); if (role === 'button' || role === 'link') return true;
    if (node.getAttribute('onclick') !== null) return true;
    var tabindex = node.getAttribute('tabindex'); return tabindex !== null && text(tabindex).trim() !== '-1';
  }
  function normalizeTarget(el) {
    if (!el) return el;
    var node = el, scan = el, svg = null;
    while (scan) { if (text(scan.tagName).toLowerCase() === 'svg') { svg = scan; break; } scan = scan.parentElement; }
    if (svg) node = svg;
    var current = node;
    for (var i = 0; i <= 5 && current; i += 1) { if (text(current.tagName).toLowerCase() === 'body') break; if (isInteractive(current)) return current; current = current.parentElement; }
    return node;
  }
  function all(doc, selector) { try { return Array.prototype.slice.call(doc.querySelectorAll(selector)); } catch (_) { return []; } }
  function addCandidate(out, selector, strategy, score, doc, target) {
    if (!selector || selector.length > 200 || out.some(function (c) { return c.selector === selector; })) return;
    var found = all(doc, selector);
    if (!found.length || !matches(target, selector)) return;
    var count = found.length, stripped = selector.replace(/\[(?:\\.|"(?:\\.|[^"])*"|'(?:\\.|[^'])*'|[^\]])*\]/g, ''), combinators = (stripped.match(/\s*>\s*|\s+/g) || []).length;
    score -= combinators * 4;
    if (count > 50) score -= 20;
    out.push({ selector: selector, score: Math.max(0, Math.min(100, score)), count: count, strategy: strategy, scope: strategy === 'pattern' || count > 1 ? 'similar' : 'unique' });
  }
  function seg(node) {
    var tag = text(node.tagName).toLowerCase(), parent = node.parentElement, same = [], position = 0;
    if (parent && parent.children) for (var i = 0; i < parent.children.length; i += 1) if (text(parent.children[i].tagName).toLowerCase() === tag) { same.push(parent.children[i]); if (parent.children[i] === node) position = same.length; }
    return tag + (same.length > 1 ? ':nth-of-type(' + position + ')' : '');
  }
  function pathNth(el, maxDepth) {
    var parts = [], node = el, boundary = '', limit = Math.min(6, maxDepth || 6);
    while (node && text(node.tagName).toLowerCase() !== 'body') {
      var parent = node.parentElement;
      parts.unshift(seg(node));
      if (parent && text(parent.tagName).toLowerCase() === 'body') { boundary = 'body'; break; }
      if (parent && parent.id && isStableId(parent.id)) { boundary = '#' + escape(parent.id); break; }
      node = parent;
    }
    if (boundary === 'body' || boundary.charAt(0) === '#') return boundary + ' > ' + parts.slice(0, limit).join(' > ');
    return parts.slice(0, limit).join(' > ');
  }
  function stableClasses(el) {
    var classes = []; try { classes = Array.prototype.slice.call(el.classList || []); } catch (_) {}
    var doc = el.ownerDocument;
    return Array.from(new Set(classes)).filter(isStableClass).map(function (name) { return { name: name, count: all(doc, '.' + escape(name)).length }; }).sort(function (a, b) { return a.count - b.count || b.name.length - a.name.length || (a.name < b.name ? -1 : a.name > b.name ? 1 : 0); }).slice(0, 3).map(function (x) { return x.name; });
  }
  function generate(el, opts) {
    try {
      var t = normalizeTarget(el); if (!t || !t.ownerDocument) return [];
      var doc = t.ownerDocument, out = [], tag = text(t.tagName).toLowerCase();
      function attr(n) { try { return t.getAttribute(n); } catch (_) { return null; } }
      function attrSelector(node, names) {
        for (var i = 0; i < names.length; i += 1) { var name = names[i], value = node.getAttribute(name); if (value !== null && isStableAttr(name, value)) return text(node.tagName).toLowerCase() + '[' + name + '="' + escapeAttr(value) + '"]'; }
        return '';
      }
      if (t.id && isStableId(t.id)) addCandidate(out, '#' + escape(t.id), 'id', 100, doc, t);
      TEST_ATTRS.forEach(function (n) { var v = attr(n); if (v !== null && isStableAttr(n, v)) addCandidate(out, tag + '[' + n + '="' + escapeAttr(v) + '"]', 'test_attr', 95, doc, t); });
      DATA_ALLOW.forEach(function (n) { var v = attr(n); if (v !== null && isStableAttr(n, v)) addCandidate(out, tag + '[' + n + '="' + escapeAttr(v) + '"]', 'data_attr', 85, doc, t); });
      ['aria-label', 'title'].concat(tag === 'img' ? ['alt'] : []).forEach(function (n) { var v = attr(n); if (v !== null && isStableAttr(n, v)) addCandidate(out, tag + '[' + n + '="' + escapeAttr(v) + '"]', 'aria', 75, doc, t); });
      var name = attr('name');
      if (name !== null && ['input', 'select', 'textarea', 'button', 'form'].indexOf(tag) !== -1 && isStableAttr('name', name)) addCandidate(out, tag + '[name="' + escapeAttr(name) + '"]', 'name', 72, doc, t);
      if (tag === 'a') {
        var raw = attr('href') || '', hrefMatch = raw.match(/^https?:\/\/[^/?#]+/i), origin = doc.location && doc.location.origin;
        if (raw.charAt(0) === '/' && raw.slice(0, 2) !== '//' && !/[?#]/.test(raw)) addCandidate(out, 'a[href="' + escapeAttr(raw) + '"]', 'href', 70, doc, t);
        else if (hrefMatch && origin && hrefMatch[0].toLowerCase() === text(origin).toLowerCase() && !/[?#]/.test(raw)) addCandidate(out, 'a[href="' + escapeAttr(raw) + '"]', 'href', 70, doc, t);
        if (raw.indexOf('tel:') === 0) addCandidate(out, 'a[href^="tel:"]', 'pattern', 55, doc, t);
        else if (raw.indexOf('mailto:') === 0) addCandidate(out, 'a[href^="mailto:"]', 'pattern', 55, doc, t);
        else if (raw.indexOf('wa.me/') !== -1) addCandidate(out, 'a[href*="wa.me/"]', 'pattern', 55, doc, t);
      }
      var classes = stableClasses(t);
      for (var a = 0; a < classes.length; a += 1) addCandidate(out, tag + '.' + escape(classes[a]), 'class', 60, doc, t);
      for (var i = 0; i < classes.length; i += 1) for (var j = i + 1; j < classes.length; j += 1) addCandidate(out, tag + '.' + escape(classes[i]) + '.' + escape(classes[j]), 'class', 55, doc, t);
      var ancestor = t.parentElement, level = 0;
      while (ancestor && level < 4) {
        var atag = text(ancestor.tagName).toLowerCase();
        if (atag !== 'body' && atag !== 'html') {
          var ancestorSelector = ancestor.id && isStableId(ancestor.id) ? '#' + escape(ancestor.id) : attrSelector(ancestor, TEST_ATTRS) || attrSelector(ancestor, DATA_ALLOW);
          if (!ancestorSelector) { var ancestorClasses = Array.from(new Set(Array.prototype.slice.call(ancestor.classList || []))).filter(isStableClass); for (var ci = 0; ci < ancestorClasses.length; ci += 1) { var classSel = '.' + escape(ancestorClasses[ci]); if (all(doc, classSel).length === 1) { ancestorSelector = atag + classSel; break; } } }
          if (ancestorSelector) {
            addCandidate(out, ancestorSelector + ' ' + tag, 'anchored', 50, doc, t);
            if (classes[0]) addCandidate(out, ancestorSelector + ' ' + tag + '.' + escape(classes[0]), 'anchored', 50, doc, t);
            var depth = 0, cursor = t, direct = [];
            while (cursor && cursor !== ancestor) { direct.unshift(seg(cursor)); cursor = cursor.parentElement; depth += 1; }
            if (cursor === ancestor && all(doc, ancestorSelector + ' ' + tag).length > 1 && depth <= 3) addCandidate(out, ancestorSelector + ' > ' + direct.join(' > '), 'anchored', 50, doc, t);
            break;
          }
        }
        ancestor = ancestor.parentElement; level += 1;
      }
      var nthSelector = pathNth(t, 6);
      addCandidate(out, nthSelector, 'nth', 20, doc, t);
      var order = 0;
      out.forEach(function (candidate) { candidate._order = order++; });
      function sortCandidates(a, b) { return b.score - a.score || a.selector.length - b.selector.length || a._order - b._order; }
      out.sort(sortCandidates);
      var max = opts && Number.isInteger(opts.max) ? Math.max(1, Math.min(5, opts.max)) : 5;
      var unique = out.filter(function (c) { return c.scope === 'unique'; }).slice(0, 3), similar = out.filter(function (c) { return c.scope === 'similar'; }).slice(0, 2), result = unique.concat(similar);
      out.forEach(function (c) { if (result.length < max && result.indexOf(c) === -1) result.push(c); });
      result = result.slice(0, max).sort(sortCandidates);
      if (!result.length) {
        addCandidate(out, nthSelector, 'nth', 20, doc, t);
        if (!out.length) return [];
        result = out.slice(0, max);
      }
      result.forEach(function (candidate) { delete candidate._order; });
      return result;
    } catch (_) { return []; }
  }
  var api = { generate: generate, normalizeTarget: normalizeTarget, isStableId: isStableId, isStableClass: isStableClass, isStableAttr: isStableAttr, escape: escape };
  root.adcPickerSelector = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
}(typeof window !== 'undefined' ? window : globalThis));
