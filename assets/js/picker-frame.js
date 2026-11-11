(function (root) {
  'use strict';

  var doc = root.document;
  var config = root.adcPickerFrameConfig;
  var protocol = root.adcPickerProtocol;
  var selectorApi = root.adcPickerSelector;
  var panelOrigin = config && typeof config.panelOrigin === 'string' ? config.panelOrigin : '';
  var pickerToken = '';
  try { pickerToken = new root.URL(root.location.href).searchParams.get('adc_picker') || ''; } catch (_) {}
  var target = root.parent !== root ? root.parent : root.opener;
  var channel = '';
  var initialized = false;
  var outgoingSeq = 0;
  var incomingSeq = 0;
  var helloAttempts = 0;
  var helloTimer = 0;
  var mode = 'select';
  var resumeMode = 'select';
  var freezeTimer = 0;
  var hoverFrame = 0;
  var lastHover = 0;
  var lastHoverTarget = null;
  var pickedElement = null;
  var host = null;
  var shadow = null;
  var box = null;
  var label = null;
  var highlights = [];
  var frozenAnimations = [];

  if (!doc || !protocol || !selectorApi || !target || !panelOrigin || panelOrigin !== root.location.origin) {
    return;
  }

  function pathOnly() {
    try { return String(root.location.pathname || '/').slice(0, 200); } catch (_) { return '/'; }
  }

  function send(type, payload) {
    if (!initialized || protocol.TYPES_TO_ADMIN.indexOf(type) === -1) { return false; }
    try {
      outgoingSeq += 1;
      target.postMessage(protocol.make(type, channel, outgoingSeq, payload), panelOrigin);
      return true;
    } catch (_) { return false; }
  }

  function hello() {
    if (initialized || helloAttempts >= 10) { return; }
    helloAttempts += 1;
    try {
      outgoingSeq += 1;
      target.postMessage(protocol.make('hello', '', outgoingSeq, { href_path: pathOnly() }), panelOrigin);
    } catch (_) {}
    if (!initialized && helloAttempts < 10) { helloTimer = root.setTimeout(hello, 500); }
  }

  function stop(event) {
    try { event.preventDefault(); } catch (_) {}
    try { event.stopImmediatePropagation(); } catch (_) {}
  }

  function isOverlay(node) {
    try { return !!host && (node === host || host.contains(node)); } catch (_) { return false; }
  }

  function makeOverlay() {
    if (host) { return true; }
    try {
      host = doc.createElement('div');
      host.setAttribute('aria-hidden', 'true');
      host.setAttribute('data-adc-picker-overlay', '');
      host.style.cssText = 'all:initial!important;position:fixed!important;inset:0!important;z-index:2147483647!important;pointer-events:none!important;';
      shadow = host.attachShadow({ mode: 'closed' });
      var style = doc.createElement('style');
      style.textContent = ':host{all:initial;position:fixed;inset:0;z-index:2147483647;pointer-events:none;color:#fff;font:12px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.adc-box{position:fixed;display:none;box-sizing:border-box;border:2px solid #3b6ea5;background:rgba(59,110,165,.12);box-shadow:0 0 0 1px rgba(255,255,255,.8);pointer-events:none}.adc-label{position:absolute;inset-inline-start:-2px;inset-block-end:100%;max-width:min(420px,80vw);padding:3px 6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:#162237;color:#fff;border-radius:3px 3px 0 0}.adc-highlight{position:fixed;box-sizing:border-box;border:2px solid #f96004;background:rgba(249,96,4,.1);pointer-events:none}';
      shadow.appendChild(style);
      box = doc.createElement('div');
      box.className = 'adc-box';
      label = doc.createElement('span');
      label.className = 'adc-label';
      box.appendChild(label);
      shadow.appendChild(box);
      (doc.documentElement || doc.body).appendChild(host);
      doc.documentElement.classList.add('adc-picker-active');
      return true;
    } catch (_) {
      host = shadow = box = label = null;
      return false;
    }
  }

  function clearHighlights() {
    highlights.forEach(function (node) {
      try { node.remove(); } catch (_) { try { if (node.parentNode) { node.parentNode.removeChild(node); } } catch (__) {} }
    });
    highlights = [];
  }

  function clearSelection() {
    pickedElement = null;
    if (box) { box.style.display = 'none'; }
    clearHighlights();
  }

  function scrub(value) {
    var text = '';
    try { text = String(value == null ? '' : value); } catch (_) {}
    text = text.replace(/[^\s@]+@[^\s@]+\.[^\s@]+/g, '[email]');
    text = text.replace(/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/gi, '[iban]');
    text = text.replace(/\d(?:[\d\s\-.()]*\d)?/g, function (part) {
      return part.replace(/\D/g, '').length >= 7 ? '[nr]' : part;
    });
    return text.replace(/\b[A-Za-z0-9_-]{32,}\b/g, '[id]').replace(/\s+/g, ' ').trim().slice(0, 60);
  }

  function classesOf(element) {
    try { return Array.prototype.slice.call(element.classList || []).slice(0, 5).map(function (value) { return String(value).slice(0, 60); }); } catch (_) { return []; }
  }

  function summary(element) {
    var tag = '';
    try { tag = String(element.tagName || '').toLowerCase(); } catch (_) {}
    if (!/^[a-z][a-z0-9-]{0,30}$/.test(tag)) { tag = 'div'; }
    var id = '', role = '', text = '';
    try { id = String(element.id || '').slice(0, 60); } catch (_) {}
    try { role = String(element.getAttribute('role') || '').slice(0, 30); } catch (_) {}
    if (['input', 'select', 'textarea', 'option', 'form'].indexOf(tag) === -1) {
      try { text = scrub(element.textContent); } catch (_) {}
    }
    var rect = { x: 0, y: 0, w: 0, h: 0 };
    try {
      var r = element.getBoundingClientRect();
      rect = { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) };
    } catch (_) {}
    return { tag: tag, id: id, classes: classesOf(element), role: role, text: text, rect: rect };
  }

  function labelFor(element) {
    var value = String(element.tagName || '').toLowerCase();
    try { if (element.id) { value += '#' + String(element.id); } } catch (_) {}
    var classes = classesOf(element);
    if (classes.length) { value += '.' + classes.join('.'); }
    return value.slice(0, 60);
  }

  function show(element) {
    if (!box || !label) { return; }
    try {
      var r = element.getBoundingClientRect();
      box.style.left = Math.round(r.left) + 'px';
      box.style.top = Math.round(r.top) + 'px';
      box.style.width = Math.max(0, Math.round(r.width)) + 'px';
      box.style.height = Math.max(0, Math.round(r.height)) + 'px';
      box.style.display = 'block';
      label.textContent = labelFor(element);
    } catch (_) {}
  }

  function targetFromEvent(event) {
    var path = [], element = null, notice = '';
    try { path = typeof event.composedPath === 'function' ? event.composedPath() : []; } catch (_) {}
    try {
      var first = path[0];
      var firstRoot = first && typeof first.getRootNode === 'function' ? first.getRootNode() : null;
      if (firstRoot && firstRoot.host) { element = firstRoot.host; notice = 'shadow'; }
    } catch (_) {}
    if (!element) {
      for (var i = 0; i < path.length; i += 1) {
        if (path[i] && path[i].nodeType === 1 && !isOverlay(path[i])) { element = path[i]; break; }
      }
    }
    if (!element) { element = event.target; }
    try { while (element && element.nodeType !== 1) { element = element.parentElement; } } catch (_) { element = null; }
    if (!element || isOverlay(element)) { return { element: null, notice: '' }; }
    var tag = String(element.tagName || '').toLowerCase();
    if (!notice && tag === 'iframe') { notice = 'iframe'; }
    if (!notice && tag === 'svg') { notice = 'svg'; }
    if (!notice && (tag === 'canvas' || tag === 'video')) { notice = 'canvas'; }
    try {
      var needsHitTest = !element;
      if (element && notice !== 'shadow' && root.getComputedStyle) {
        needsHitTest = root.getComputedStyle(element).pointerEvents === 'none';
      }
      if (notice !== 'shadow' && needsHitTest && typeof event.clientX === 'number' && typeof doc.elementsFromPoint === 'function') {
        var under = doc.elementsFromPoint(event.clientX, event.clientY);
        for (var j = 0; j < under.length; j += 1) {
          if (under[j] && under[j].nodeType === 1 && !isOverlay(under[j])) {
            element = under[j];
            if (String(element.tagName || '').toLowerCase() === 'iframe') { notice = 'iframe'; }
            break;
          }
        }
      }
    } catch (_) {}
    try {
      var before = element;
      element = selectorApi.normalizeTarget(element);
      if (before !== element && String(before.tagName || '').toLowerCase() === 'svg' && notice !== 'shadow' && notice !== 'iframe') { notice = 'svg'; }
    } catch (_) { return { element: null, notice: notice }; }
    if (notice !== 'shadow' && notice !== 'iframe') {
      var finalTag = String(element.tagName || '').toLowerCase();
      if (finalTag === 'svg') { notice = 'svg'; }
      else if (finalTag === 'canvas' || finalTag === 'video') { notice = 'canvas'; }
    }
    return { element: element, notice: notice };
  }

  function pick(event) {
    try {
      var targetInfo = targetFromEvent(event);
      if (!targetInfo.element) { return; }
      var candidates = selectorApi.generate(targetInfo.element, { max: 5 });
      if (!Array.isArray(candidates) || candidates.length === 0) { send('error', { code: 'internal' }); return; }
      candidates = candidates.slice(0, 5);
      pickedElement = targetInfo.element;
      show(targetInfo.element);
      send('pick', { summary: summary(targetInfo.element), candidates: candidates, notice: targetInfo.notice });
    } catch (_) { send('error', { code: 'internal' }); }
  }

  function isEditable(targetNode) {
    try {
      var tag = String(targetNode && targetNode.tagName || '').toLowerCase();
      return tag === 'input' || tag === 'textarea' || tag === 'select' || !!(targetNode && targetNode.isContentEditable) || !!(targetNode && targetNode.closest && targetNode.closest('[contenteditable]'));
    } catch (_) { return false; }
  }

  function keyTarget(node) {
    if (node && node.nodeType === 1) { return node; }
    try { return node && node.parentElement; } catch (_) { return null; }
  }

  function syntheticPick(element) {
    if (!element) { return; }
    pick({ target: element, clientX: NaN, clientY: NaN, composedPath: function () { return [element]; } });
  }

  function onKeyDown(event) {
    if (!initialized || (mode !== 'select' && mode !== 'frozen') || event.ctrlKey || event.altKey || event.metaKey) { return; }
    var key = event.key;
    var editable = isEditable(keyTarget(event.target));
    if (editable && key !== 'Escape') { return; }
    if (key === 'Escape') {
      stop(event);
      if (mode === 'frozen') { setMode('select'); }
      else { clearSelection(); }
    } else if (key === 'F' || key === 'f') {
      if (!editable) { stop(event); enterFrozen(); }
    } else if (!editable && (key === 'Enter' || key === 'p' || key === 'P' || key === 'c' || key === 'C')) {
      stop(event);
      if (key === 'Enter') { syntheticPick(doc.activeElement); }
      else if ((key === 'p' || key === 'P') && pickedElement) { syntheticPick(pickedElement.parentElement); }
      else if ((key === 'c' || key === 'C') && pickedElement) { syntheticPick(pickedElement.firstElementChild); }
    }
  }

  function onPointerMove(event) {
    if (!initialized || (mode !== 'select' && mode !== 'frozen') || isOverlay(event.target) || hoverFrame) { return; }
    if (Date.now() - lastHover < 50) { return; }
    hoverFrame = root.requestAnimationFrame(function () {
      hoverFrame = 0;
      lastHover = Date.now();
      try {
        var result = targetFromEvent(event);
        if (!result.element || result.element === lastHoverTarget) { return; }
        lastHoverTarget = result.element;
        show(result.element);
        send('hover', { summary: summary(result.element) });
      } catch (_) { send('error', { code: 'internal' }); }
    });
  }

  function onFocus(event) {
    if (!initialized || (mode !== 'select' && mode !== 'frozen')) { return; }
    try {
      var element = keyTarget(event.target) || doc.activeElement;
      if (!element || isOverlay(element) || element === lastHoverTarget) { return; }
      lastHoverTarget = element;
      show(element);
      send('hover', { summary: summary(element) });
    } catch (_) { send('error', { code: 'internal' }); }
  }

  function withPickerParams(rawHref) {
    try {
      var url = new root.URL(rawHref, root.location.href);
      if (url.origin !== root.location.origin) { return null; }
      if (/^[a-f0-9]{32}$/.test(pickerToken)) {
        url.searchParams.set('adc_picker', pickerToken);
        url.searchParams.set('adc_ts', String(Date.now()));
      }
      return url;
    } catch (_) { return null; }
  }

  function linkForEvent(event) {
    var node = keyTarget(event.target);
    try { return node && node.closest ? node.closest('a[href]') : null; } catch (_) { return null; }
  }

  var DOWNLOAD_EXT = /\.(pdf|zip|rar|7z|doc|docx|xls|xlsx|ppt|pptx|csv|txt|odt|ods|mp3|mp4|epub)$/i;

  function onClick(event) {
    if (!initialized) { return; }
    if (mode === 'select' || mode === 'frozen') {
      stop(event);
      if (event.type === 'click') { pick(event); }
      return;
    }
    if (mode !== 'navigate' || event.type !== 'click') { return; }
    var link = linkForEvent(event);
    if (!link) { return; }
    try {
      var href = link.getAttribute('href') || '';
      var linkTarget = String(link.getAttribute('target') || '').toLowerCase();
      if (link.hasAttribute('download') || DOWNLOAD_EXT.test(href.split(/[?#]/)[0])) {
        stop(event); send('blocked', { reason: 'download' }); return;
      }
      if (linkTarget && linkTarget !== '_self') {
        stop(event); send('blocked', { reason: 'external_link' }); return;
      }
      var url = withPickerParams(href);
      if (!url) { stop(event); send('blocked', { reason: 'external_link' }); return; }
      stop(event);
      send('nav', { href_path: String(url.pathname || '/').slice(0, 200) });
      root.location.assign(url.href);
    } catch (_) { send('error', { code: 'internal' }); }
  }

  function onSubmit(event) {
    if (!initialized) { return; }
    if (mode === 'navigate') { stop(event); send('blocked', { reason: 'form_submit' }); }
    else if (mode === 'select' || mode === 'frozen') { stop(event); }
  }

  function freezePropagation(event) { try { event.stopImmediatePropagation(); } catch (_) {} }

  function enterFrozen() {
    if (mode === 'frozen') { return; }
    resumeMode = mode === 'navigate' ? 'navigate' : 'select';
    applyMode('frozen');
    try {
      frozenAnimations = typeof doc.getAnimations === 'function' ? doc.getAnimations() : [];
      frozenAnimations.forEach(function (animation) { try { animation.pause(); } catch (_) {} });
    } catch (_) { frozenAnimations = []; }
    ['mouseout', 'mouseleave', 'pointerout', 'pointerleave', 'blur', 'focusout', 'visibilitychange', 'resize'].forEach(function (type) { root.addEventListener(type, freezePropagation, true); });
    send('mode_changed', { mode: 'frozen' });
  }

  function leaveFrozen(nextMode) {
    ['mouseout', 'mouseleave', 'pointerout', 'pointerleave', 'blur', 'focusout', 'visibilitychange', 'resize'].forEach(function (type) { root.removeEventListener(type, freezePropagation, true); });
    frozenAnimations.forEach(function (animation) { try { animation.play(); } catch (_) {} });
    frozenAnimations = [];
    applyMode(nextMode || 'select');
  }

  function applyMode(nextMode) {
    mode = nextMode;
    if (doc.documentElement && doc.documentElement.classList) {
      if (mode === 'navigate') { doc.documentElement.classList.remove('adc-picker-active'); }
      else { doc.documentElement.classList.add('adc-picker-active'); }
    }
  }

  function setMode(value) {
    if (freezeTimer) { root.clearTimeout(freezeTimer); freezeTimer = 0; }
    if (value === 'unfreeze') { value = 'select'; }
    if (value === 'freeze') { enterFrozen(); return; }
    if (value !== 'select' && value !== 'navigate') { return; }
    if (mode === 'frozen') {
      leaveFrozen(value);
      send('mode_changed', { mode: mode });
    } else if (mode !== value) {
      applyMode(value);
      send('mode_changed', { mode: mode });
    }
  }

  function drawHighlights(query) {
    clearHighlights();
    var matches = Array.prototype.slice.call(doc.querySelectorAll(query));
    matches.slice(0, 200).forEach(function (element) {
      var r = element.getBoundingClientRect();
      var marker = doc.createElement('div');
      marker.setAttribute('aria-hidden', 'true');
      marker.className = 'adc-highlight';
      marker.style.cssText = 'left:' + Math.round(r.left) + 'px;top:' + Math.round(r.top) + 'px;width:' + Math.max(0, Math.round(r.width)) + 'px;height:' + Math.max(0, Math.round(r.height)) + 'px;';
      shadow.appendChild(marker);
      highlights.push(marker);
    });
    return matches;
  }

  function onMessage(event) {
    if (event.origin !== panelOrigin || event.source !== target) { return; }
    var checked;
    try {
      checked = protocol.validate(event.data, protocol.DIR_TO_FRAME, initialized ? { ch: channel, seq: incomingSeq } : { seq: incomingSeq });
    } catch (_) { return; }
    if (!checked || !checked.ok) { return; }
    var message = event.data;
    var payload = checked.payload;
    if (!initialized) {
      if (checked.type !== 'init' || message.ch !== payload.ch || !protocol.TYPES_TO_FRAME.includes(checked.type)) { return; }
      channel = payload.ch;
      incomingSeq = message.seq;
      initialized = true;
      if (helloTimer) { root.clearTimeout(helloTimer); helloTimer = 0; }
      applyMode(payload.mode);
      if (!makeOverlay()) { send('error', { code: 'internal' }); return; }
      send('ready', { href_path: pathOnly(), title_len: String(doc.title || '').length });
      bindActive();
      return;
    }
    incomingSeq = message.seq;
    try {
      if (checked.type === 'set_mode') { setMode(payload.mode); }
      else if (checked.type === 'freeze_in') {
        if (freezeTimer) { root.clearTimeout(freezeTimer); }
        freezeTimer = root.setTimeout(function () { freezeTimer = 0; enterFrozen(); }, payload.seconds * 1000);
      } else if (checked.type === 'test_selector') {
        var matches, valid = true;
        try { matches = doc.querySelectorAll(payload.selector); } catch (_) { valid = false; matches = []; }
        var count = valid ? Math.min(matches.length, 9999) : 0;
        var pickedMatches = false;
        try { pickedMatches = !!pickedElement && Array.prototype.indexOf.call(matches, pickedElement) !== -1; } catch (_) {}
        send('test_result', { req: payload.req, valid: valid, count: count, matches_picked: pickedMatches });
      } else if (checked.type === 'highlight') {
        try { drawHighlights(payload.selector); } catch (_) { clearHighlights(); send('error', { code: 'selector_invalid' }); }
      } else if (checked.type === 'clear') { clearSelection(); }
    } catch (_) { send('error', { code: 'internal' }); }
  }

  function bindActive() {
    root.addEventListener('pointermove', onPointerMove, true);
    root.addEventListener('focusin', onFocus, true);
    ['pointerdown', 'mousedown', 'mouseup', 'click', 'auxclick', 'dblclick', 'contextmenu', 'touchstart'].forEach(function (type) { root.addEventListener(type, onClick, { capture: true, passive: false }); });
    root.addEventListener('submit', onSubmit, true);
    root.addEventListener('keydown', onKeyDown, true);
    ['scroll', 'resize'].forEach(function (type) { root.addEventListener(type, function () { if (box && box.style.display === 'block' && pickedElement) { show(pickedElement); } }); });
  }

  root.addEventListener('message', onMessage, false);
  hello();
}(typeof window !== 'undefined' ? window : globalThis));
