'use strict';

/*
 * Offline behavioural test for assets/js/picker-frame.js (visual selector, iframe side).
 *
 * The frame script is an IIFE that reads window.adcPickerFrameConfig and the protocol /
 * selector modules from its global. We run it inside a fresh vm context whose global is a
 * hand-built fake window (document from tests/support/fake-dom.js plus helpers defined here:
 * event dispatch, timers, rAF queue, shadow DOM, location). Messages from the admin side are
 * built with the real protocol module; messages the frame sends are JSON round-tripped into
 * the host realm before validation, because the protocol validator checks prototypes.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { parseHTML } = require('./support/fake-dom.js');
// Host-realm protocol module: used only to validate what the frame sends (after a JSON round-trip).
const P = require('../assets/js/picker-protocol.js');
// Engine matcher: parsed fixture nodes carry fake-dom's matches(); nodes made by createNode() reuse it.
const ENGINE_PROBE = parseHTML('<i></i>').querySelectorAll('i')[0];
function engineMatches(node, selector) { return ENGINE_PROBE.matches.call(node, selector); }

const ASSETS = path.join(__dirname, '..', 'assets', 'js');
const SRC_PATH = path.join(ASSETS, 'picker-frame.js');
const PROTOCOL_PATH = path.join(ASSETS, 'picker-protocol.js');
const SELECTOR_PATH = path.join(ASSETS, 'picker-selector.js');
const SRC = fs.readFileSync(SRC_PATH, 'utf8');
const PROTOCOL_SRC = fs.readFileSync(PROTOCOL_PATH, 'utf8');
const SELECTOR_SRC = fs.readFileSync(SELECTOR_PATH, 'utf8');

const PANEL = 'https://site.test';
const PATH_NAME = '/checkout/';
const TOKEN = '0123456789abcdef'.repeat(2);
const CH = 'ab'.repeat(16);

const FIXTURE = [
  '<!doctype html><html><head><title>Checkout</title></head><body>',
  '<main id="content"><div class="cart">',
  '<button data-testid="add-to-cart" class="btn primary">Adauga</button>',
  '<a href="/shop/?x=1" class="item">Produs</a>',
  '<a href="https://other.test/x" class="ext">Extern</a>',
  '<a href="/doc.pdf" class="pdf">Fisa</a>',
  '<a href="/shop/" target="_blank" class="new">Nou</a>',
  '<form action="/x" class="f"><input name="q" class="q"></form>',
  '</div></main></body></html>',
].join('');

// Same page plus a host element for an open shadow root (the button lives inside it).
const FIXTURE_SHADOW = FIXTURE.replace('</main>', '<div id="widget-host"></div></main>');

/* ---------- DOM helpers (test-local; fake-dom.js is read-only) ---------- */

function addClassHelpers(list) {
  Object.defineProperty(list, 'add', {
    value: function (...names) { names.forEach((n) => { if (!this.includes(n)) this.push(n); }); },
    enumerable: false,
  });
  Object.defineProperty(list, 'remove', {
    value: function (...names) { names.forEach((n) => { const i = this.indexOf(n); if (i !== -1) this.splice(i, 1); }); },
    enumerable: false,
  });
  return list;
}

function containsNode(root, other) {
  if (root === other) return true;
  const kids = (root.children || []).concat(root.shadowRoot ? root.shadowRoot.children : []);
  return kids.some((k) => containsNode(k, other));
}

function augment(node, doc) {
  node.nodeType = 1;
  node.ownerDocument = doc;
  node.children = node.children || [];
  node.attributes = node.attributes || [];
  node.classList = addClassHelpers(Array.isArray(node.classList) ? node.classList : []);
  node.style = node.style || {};
  node.id = node.id || '';
  node.isContentEditable = false;
  node.getAttribute = node.getAttribute || function (name) {
    const a = this.attributes.find((x) => x.name === name);
    return a ? a.value : null;
  };
  node.hasAttribute = function (name) { return this.attributes.some((x) => x.name === name); };
  node.setAttribute = function (name, value) {
    const a = this.attributes.find((x) => x.name === name);
    if (a) a.value = String(value); else this.attributes.push({ name, value: String(value) });
  };
  node.appendChild = function (child) { child.parentElement = this; this.children.push(child); return child; };
  node.remove = function () {
    const p = this.parentElement;
    if (p && p.children) p.children = p.children.filter((c) => c !== this);
    this.parentElement = null;
  };
  node.contains = function (other) { return containsNode(this, other); };
  node.getBoundingClientRect = function () { return { left: 0, top: 0, width: 10, height: 10 }; };
  node.attachShadow = function () {
    const shadowRoot = {
      host: this,
      children: [],
      appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    };
    this.shadowRoot = shadowRoot;
    return shadowRoot;
  };
  if (!node.closest) node.closest = () => null;
  if (!node.matches) node.matches = function (selector) { return engineMatches(this, selector); };
  return node;
}

function augmentTree(node, doc) {
  augment(node, doc);
  node.children.forEach((c) => augmentTree(c, doc));
}

function createNode(doc, tag) {
  return augment({ tagName: String(tag).toUpperCase(), id: '', attributes: [], children: [], parentElement: null, classList: [] }, doc);
}

function buildDoc(location, fixture) {
  const doc = parseHTML(fixture || FIXTURE);
  const html = doc.body.parentElement;
  augmentTree(doc.body, doc);
  augment(html, doc);
  doc.documentElement = html;
  doc.title = 'Checkout';
  doc.location = location;
  doc.activeElement = null;
  doc.animations = [];
  doc.getAnimations = () => doc.animations.slice();
  doc.createElement = (tag) => createNode(doc, tag);
  return doc;
}

function ancestry(el) {
  const out = [];
  let n = el;
  while (n && n.tagName !== 'DOCUMENT') { out.push(n); n = n.parentElement; }
  return out;
}

function makeEvent(type, target, extra) {
  const ev = Object.assign({ type, target, defaultPrevented: false, stopped: false }, extra || {});
  ev.composedPath = () => ancestry(target);
  ev.preventDefault = function () { this.defaultPrevented = true; };
  ev.stopImmediatePropagation = function () { this.stopped = true; };
  return ev;
}

/* ---------- world: fake window + vm execution of the frame script ---------- */

function makeWorld(opts) {
  opts = opts || {};
  const location = {
    origin: opts.origin || PANEL,
    pathname: PATH_NAME,
    search: '?adc_picker=' + TOKEN,
    assigned: [],
  };
  location.href = location.origin + location.pathname + location.search;
  location.assign = function (u) { this.assigned.push(String(u)); };
  const doc = buildDoc(location, opts.fixture);

  let clock = 10000;
  const sent = [];
  const listeners = [];
  const timers = [];
  let timerId = 0;
  const rafs = [];
  const target = {
    postMessage(msg, origin) { sent.push({ msg, origin, t: clock }); },
  };

  const win = {
    document: doc,
    location,
    Date: { now: () => clock },
    URL,
    parent: null,
    opener: opts.opener === 'target' ? target : null,
    addEventListener(type, fn) { listeners.push({ type, fn }); },
    removeEventListener(type, fn) {
      for (let i = listeners.length - 1; i >= 0; i -= 1) {
        if (listeners[i].type === type && listeners[i].fn === fn) listeners.splice(i, 1);
      }
    },
    setTimeout(fn, ms) { timerId += 1; timers.push({ id: timerId, at: clock + Number(ms || 0), fn }); return timerId; },
    clearTimeout(id) { const i = timers.findIndex((t) => t.id === id); if (i !== -1) timers.splice(i, 1); },
    requestAnimationFrame(fn) { rafs.push(fn); return rafs.length; },
  };
  win.window = win;
  win.parent = opts.parent === 'self' ? win : target;
  if ('config' in opts) win.adcPickerFrameConfig = opts.config;
  else win.adcPickerFrameConfig = { panelOrigin: PANEL };
  // Same realm for protocol, selector and frame, as in a browser page: the modules
  // self-register on window.adcPickerProtocol / adcPickerSelector when they run.
  vm.createContext(win);
  if (!opts.noProtocol) vm.runInContext(PROTOCOL_SRC, win, { filename: PROTOCOL_PATH });
  if (!opts.noSelector) vm.runInContext(SELECTOR_SRC, win, { filename: SELECTOR_PATH });
  vm.runInContext(SRC, win, { filename: SRC_PATH });
  // Admin-side messages must be created in the same realm as the frame's validator.
  const vmJSON = vm.runInContext('JSON.parse', win);
  const toVm = (value) => vmJSON(JSON.stringify(value));

  const w = {
    win, doc, sent, listeners, timers, rafs, target, location, inSeq: 0,
    get clock() { return clock; },
    advance(ms) {
      const end = clock + ms;
      for (;;) {
        let idx = -1;
        timers.forEach((t, i) => {
          if (t.at <= end && (idx === -1 || t.at < timers[idx].at || (t.at === timers[idx].at && t.id < timers[idx].id))) idx = i;
        });
        if (idx === -1) break;
        const due = timers.splice(idx, 1)[0];
        clock = Math.max(clock, due.at);
        due.fn();
      }
      clock = end;
    },
    flush() {
      const queue = rafs.splice(0);
      queue.forEach((fn) => fn());
      return queue.length;
    },
    dispatch(type, ev) {
      for (const l of listeners.slice()) {
        if (l.type !== type) continue;
        if (ev.stopped) break;
        l.fn.call(win, ev);
      }
      return ev;
    },
    message(data, origin, source) {
      return this.dispatch('message', makeEvent('message', null, {
        data: toVm(data),
        origin: origin === undefined ? PANEL : origin,
        source: source === undefined ? target : source,
      }));
    },
    admin(type, payload, o) {
      o = o || {};
      const seq = o.seq !== undefined ? o.seq : ++this.inSeq;
      const env = o.raw || win.adcPickerProtocol.make(type, CH, seq, payload);
      return this.message(env, o.origin, o.source);
    },
    click(el) { return this.dispatch('click', makeEvent('click', el)); },
    pointer(el) { return this.dispatch('pointermove', makeEvent('pointermove', el)); },
    key(el, key) { return this.dispatch('keydown', makeEvent('keydown', el, { key })); },
    outgoing(type) { return sent.filter((s) => s.msg && s.msg.type === type).map((s) => s.msg); },
    byTestId(id) { return doc.querySelectorAll('[data-testid="' + id + '"]')[0]; },
    byClass(c) { return doc.querySelectorAll('.' + c)[0]; },
    overlayHighlights() {
      const host = doc.documentElement.children.find((c) => c.attributes.some((a) => a.name === 'data-adc-picker-overlay'));
      if (!host || !host.shadowRoot) return 0;
      return host.shadowRoot.children.filter((c) => c.className === 'adc-highlight').length;
    },
  };
  return w;
}

function initWorld(w, mode) {
  w.admin('init', { ch: CH, mode: mode || 'select', viewport: 'desktop' }, { seq: 1 });
  w.inSeq = 1;
}

/** Every frame→admin message must be valid for the protocol, with strictly increasing seq and the exact panel origin. */
function outgoingValid(w) {
  let prev = 0;
  w.sent.forEach((s, i) => {
    assert.strictEqual(s.origin, PANEL, 'outgoing #' + i + ' targetOrigin');
    const plain = JSON.parse(JSON.stringify(s.msg));
    const expected = plain.type === 'hello' ? { seq: prev } : { ch: CH, seq: prev };
    const r = P.validate(plain, P.DIR_TO_ADMIN, expected);
    assert.ok(r.ok, 'outgoing #' + i + ' (' + plain.type + ') invalid: ' + (r.reason || '?'));
    prev = plain.seq;
  });
}

function lastMode(w) {
  const modes = w.outgoing('mode_changed');
  return modes.length ? modes[modes.length - 1].payload.mode : null;
}

/* ---------- test runner ---------- */

const tests = [];
function test(name, fn) { tests.push({ name, fn }); }

test('(1) inert when panelOrigin mismatches, config absent, parent===window without opener, protocol/selector missing', () => {
  const cases = [
    ['panelOrigin != location.origin', { config: { panelOrigin: 'https://evil.test' } }],
    ['config absent', { config: undefined }],
    ['parent === window, no opener', { parent: 'self' }],
    ['adcPickerProtocol missing', { noProtocol: true }],
    ['adcPickerSelector missing', { noSelector: true }],
  ];
  for (const [name, opts] of cases) {
    const w = makeWorld(opts);
    assert.strictEqual(w.listeners.length, 0, name + ': listeners registered');
    assert.strictEqual(w.sent.length, 0, name + ': messages sent');
    assert.strictEqual(w.timers.length, 0, name + ': timers scheduled');
    assert.strictEqual(w.click(w.byTestId('add-to-cart')).defaultPrevented, false, name + ': click intercepted');
  }
  // Positive control: parent===window but opener set → active, hello goes to the opener with panelOrigin.
  const ok = makeWorld({ parent: 'self', opener: 'target', config: { panelOrigin: PANEL, channel: 'f'.repeat(32) } });
  assert.strictEqual(ok.outgoing('hello').length, 1, 'opener control: hello sent');
  assert.strictEqual(ok.sent[0].origin, PANEL);
});

test('(2a) handshake: hello ch:"" with targetOrigin=panelOrigin, every 500 ms, max 10 attempts', () => {
  const w = makeWorld();
  assert.strictEqual(w.sent.length, 1, 'hello sent synchronously on load');
  const first = w.sent[0].msg;
  assert.strictEqual(first.type, 'hello');
  assert.strictEqual(first.ch, '');
  assert.strictEqual(first.payload.href_path, PATH_NAME);
  w.advance(500 * 30);
  const hellos = w.outgoing('hello');
  assert.strictEqual(hellos.length, 10, 'hello attempts (max 10)');
  w.sent.forEach((s, i) => {
    assert.strictEqual(s.origin, PANEL, 'hello #' + i + ' targetOrigin');
    if (i > 0) assert.strictEqual(s.t - w.sent[i - 1].t, 500, 'hello spacing #' + i);
  });
  assert.strictEqual(w.timers.length, 0, 'no timers left after 10 attempts');
  outgoingValid(w);
});

test('(2b) handshake: init accepted only with correct origin, source, ch; then ready with href_path; hello stops', () => {
  const w = makeWorld();
  w.advance(1000);
  assert.strictEqual(w.outgoing('hello').length, 3, 'three hellos before init');

  w.admin('init', { ch: CH, mode: 'select', viewport: 'desktop' }, { seq: 1, origin: 'https://evil.test' });
  w.admin('init', { ch: CH, mode: 'select', viewport: 'desktop' }, { seq: 1, source: { postMessage() {} } });
  w.admin('init', { ch: 'cd'.repeat(16), mode: 'select', viewport: 'desktop' }, { seq: 1 });
  w.admin('init', { ch: CH, mode: 'select', viewport: 'desktop' }, { seq: 0 });
  assert.strictEqual(w.outgoing('ready').length, 0, 'ignored inits must not produce ready');

  w.admin('init', { ch: CH, mode: 'select', viewport: 'desktop' }, { seq: 1 });
  const ready = w.outgoing('ready');
  assert.strictEqual(ready.length, 1, 'ready sent after valid init');
  assert.strictEqual(ready[0].ch, CH);
  assert.strictEqual(ready[0].payload.href_path, PATH_NAME);
  assert.strictEqual(ready[0].payload.title_len, 'Checkout'.length);

  w.advance(5000);
  assert.strictEqual(w.outgoing('hello').length, 3, 'hello stops after init');
  assert.strictEqual(w.timers.length, 0);
  outgoingValid(w);
});

test('(2c) invalid messages ignored: non-increasing seq, F→A type sent by admin, ch mismatch in payload', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  w.admin('set_mode', { mode: 'navigate' }, { seq: 1 }); // seq not increasing
  w.message({ ns: 'adc-picker', v: 1, type: 'hello', ch: '', seq: 2, payload: { href_path: '/' } }); // wrong direction
  assert.strictEqual(w.outgoing('mode_changed').length, 0, 'ignored set_mode must not change mode');
  w.click(w.byTestId('add-to-cart'));
  assert.strictEqual(w.outgoing('pick').length, 1, 'still in select: click picks');
  w.admin('set_mode', { mode: 'navigate' }, { seq: 2 });
  assert.strictEqual(lastMode(w), 'navigate', 'valid set_mode with increasing seq applied');
  outgoingValid(w);
});

test('(3) before init: no interception, no overlay, no hover scheduling', () => {
  const w = makeWorld();
  const btn = w.byTestId('add-to-cart');
  const c = w.click(btn);
  assert.strictEqual(c.defaultPrevented, false);
  assert.strictEqual(c.stopped, false);
  const k = w.key(btn, 'Escape');
  assert.strictEqual(k.defaultPrevented, false);
  w.pointer(btn);
  assert.strictEqual(w.rafs.length, 0, 'no rAF before init');
  assert.ok(!w.doc.documentElement.classList.includes('adc-picker-active'), 'no active class before init');
  assert.strictEqual(w.sent.filter((s) => s.msg.type !== 'hello').length, 0, 'nothing but hello before init');
});

test('(4) select: click → pick with valid candidates (F→A envelope); click blocked; mousedown blocked without pick', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  const btn = w.byTestId('add-to-cart');
  btn.textContent = 'Adauga 0712 345 678 a@b.co';

  const md = w.dispatch('mousedown', makeEvent('mousedown', btn));
  assert.ok(md.defaultPrevented && md.stopped, 'mousedown blocked');
  assert.strictEqual(w.outgoing('pick').length, 0, 'mousedown does not pick');

  const ev = w.dispatch('click', makeEvent('click', btn));
  assert.ok(ev.defaultPrevented, 'click preventDefault');
  assert.ok(ev.stopped, 'click stopImmediatePropagation');

  const picks = w.outgoing('pick');
  assert.strictEqual(picks.length, 1, 'one pick');
  const p = picks[0].payload;
  assert.strictEqual(p.summary.tag, 'button');
  assert.strictEqual(p.summary.text, 'Adauga [nr] [email]', 'summary text scrubbed');
  assert.ok(p.candidates.length >= 1 && p.candidates.length <= 5, 'candidates 1..5');
  p.candidates.forEach((c) => {
    assert.ok(w.doc.querySelectorAll(c.selector).includes(btn), 'candidate matches element: ' + c.selector);
  });
  assert.strictEqual(p.notice, '');
  outgoingValid(w);
});

test('(5) navigate: same-origin link → nav + location.assign; external / _blank / download / submit → blocked', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  w.admin('set_mode', { mode: 'navigate' });
  assert.strictEqual(lastMode(w), 'navigate');

  const same = w.click(w.byClass('item'));
  assert.ok(same.defaultPrevented, 'same-origin click handled');
  assert.strictEqual(w.outgoing('blocked').length, 0, 'same-origin not blocked');
  const nav = w.outgoing('nav');
  assert.strictEqual(nav.length, 1, 'nav sent');
  assert.strictEqual(nav[0].payload.href_path, '/shop/');
  assert.strictEqual(w.location.assigned.length, 1, 'location.assign called once');
  assert.ok(w.location.assigned[0].includes('adc_picker=' + TOKEN), 'token propagated');
  assert.ok(w.location.assigned[0].includes('adc_ts='), 'timestamp added');

  w.click(w.byClass('ext'));
  w.click(w.byClass('new'));
  w.click(w.byClass('pdf'));
  w.dispatch('submit', makeEvent('submit', w.byClass('f')));
  const reasons = w.outgoing('blocked').map((m) => m.payload.reason);
  assert.deepStrictEqual(reasons, ['external_link', 'external_link', 'download', 'form_submit']);
  assert.strictEqual(w.location.assigned.length, 1, 'blocked links do not navigate');
  outgoingValid(w);
});

test('(6) freeze: set_mode freeze, Esc → select, freeze_in timer, set_mode cancels timer, unfreeze, listeners restored', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  const btn = w.byTestId('add-to-cart');
  const anim = { paused: false, played: false, pause() { this.paused = true; }, play() { this.played = true; this.paused = false; } };
  w.doc.animations.push(anim);

  w.admin('set_mode', { mode: 'freeze' });
  assert.strictEqual(lastMode(w), 'frozen');
  assert.ok(anim.paused, 'animations paused on freeze');

  w.click(btn);
  assert.strictEqual(w.outgoing('pick').length, 1, 'frozen behaves like select for pick');

  let reached = 0;
  w.win.addEventListener('mouseout', () => { reached += 1; });
  w.dispatch('mouseout', makeEvent('mouseout', btn));
  assert.strictEqual(reached, 0, 'mouseout blocked while frozen');

  w.dispatch('keydown', makeEvent('keydown', btn, { key: 'Escape' }));
  assert.strictEqual(lastMode(w), 'select', 'Esc in frozen → select');
  assert.ok(anim.played, 'animations resumed');
  w.dispatch('mouseout', makeEvent('mouseout', btn));
  assert.strictEqual(reached, 1, 'mouseout passes again after unfreeze');

  w.admin('freeze_in', { seconds: 2 });
  w.advance(1999);
  assert.notStrictEqual(lastMode(w), 'frozen', 'not frozen before 2 s');
  w.advance(1);
  assert.strictEqual(lastMode(w), 'frozen', 'frozen at 2 s');
  w.dispatch('keydown', makeEvent('keydown', btn, { key: 'Escape' }));
  assert.strictEqual(lastMode(w), 'select');

  w.admin('freeze_in', { seconds: 2 });
  w.admin('set_mode', { mode: 'navigate' });
  w.advance(5000);
  assert.strictEqual(lastMode(w), 'navigate', 'set_mode cancels freeze timer');

  w.admin('set_mode', { mode: 'freeze' });
  w.admin('set_mode', { mode: 'unfreeze' });
  assert.strictEqual(lastMode(w), 'select', 'unfreeze → select');

  w.dispatch('keydown', makeEvent('keydown', btn, { key: 'F' }));
  assert.strictEqual(lastMode(w), 'frozen', 'F key freezes in select');
  outgoingValid(w);
});

test('(7) Esc in select clears selection and highlights locally, with no message sent', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  const btn = w.byTestId('add-to-cart');
  w.click(btn);
  w.admin('highlight', { selector: 'a' });
  assert.strictEqual(w.overlayHighlights(), 4, 'four link highlights');
  w.admin('test_selector', { req: 1, selector: 'button[data-testid="add-to-cart"]' });
  assert.strictEqual(w.outgoing('test_result').slice(-1)[0].payload.matches_picked, true);

  const before = w.sent.length;
  const e = w.dispatch('keydown', makeEvent('keydown', btn, { key: 'Escape' }));
  assert.ok(e.defaultPrevented && e.stopped, 'Esc intercepted');
  assert.strictEqual(w.sent.length, before, 'Esc sends nothing');
  assert.strictEqual(w.overlayHighlights(), 0, 'highlights removed');

  w.admin('test_selector', { req: 2, selector: 'button[data-testid="add-to-cart"]' });
  const r = w.outgoing('test_result').slice(-1)[0].payload;
  assert.strictEqual(r.matches_picked, false, 'selection forgotten');
  assert.strictEqual(r.count, 1, 'selector itself still matches');
  outgoingValid(w);
});

test('(8) test_selector / highlight / clear: counts, invalid selector handling, no drawing from test_selector', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  w.click(w.byTestId('add-to-cart'));

  w.admin('test_selector', { req: 1, selector: 'button[data-testid="add-to-cart"]' });
  let r = w.outgoing('test_result').slice(-1)[0].payload;
  assert.deepStrictEqual([r.req, r.valid, r.count, r.matches_picked], [1, true, 1, true]);

  w.admin('test_selector', { req: 2, selector: 'button[' });
  r = w.outgoing('test_result').slice(-1)[0].payload;
  assert.deepStrictEqual([r.req, r.valid, r.count, r.matches_picked], [2, false, 0, false], 'invalid selector → valid:false');

  w.admin('test_selector', { req: 3, selector: 'a' });
  r = w.outgoing('test_result').slice(-1)[0].payload;
  assert.deepStrictEqual([r.valid, r.count, r.matches_picked], [true, 4, false]);
  assert.strictEqual(w.overlayHighlights(), 0, 'test_selector draws nothing');

  w.admin('highlight', { selector: 'a' });
  assert.strictEqual(w.overlayHighlights(), 4, 'highlight draws 4 boxes');

  w.admin('highlight', { selector: '[[' });
  const err = w.outgoing('error').slice(-1)[0];
  assert.ok(err, 'error sent for invalid highlight selector');
  assert.strictEqual(err.payload.code, 'selector_invalid');
  assert.strictEqual(w.overlayHighlights(), 0, 'set left empty after invalid highlight');

  w.admin('highlight', { selector: 'a' });
  w.admin('clear', {});
  assert.strictEqual(w.overlayHighlights(), 0, 'clear removes highlights');
  w.admin('test_selector', { req: 4, selector: 'button[data-testid="add-to-cart"]' });
  r = w.outgoing('test_result').slice(-1)[0].payload;
  assert.strictEqual(r.matches_picked, false, 'clear forgets selection');
  outgoingValid(w);
});

test('(9) hover throttle: at most one rAF per frame, 50 ms spacing, select/frozen only', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  const btn = w.byTestId('add-to-cart');
  const link = w.byClass('item');

  for (let i = 0; i < 5; i += 1) w.pointer(btn);
  assert.strictEqual(w.rafs.length, 1, 'one rAF scheduled for five moves in one frame');
  w.flush();
  assert.strictEqual(w.outgoing('hover').length, 1, 'one hover after flush');
  assert.strictEqual(w.outgoing('hover')[0].payload.summary.tag, 'button');

  w.pointer(btn);
  assert.strictEqual(w.rafs.length, 0, 'within 50 ms: no new rAF');

  w.advance(60);
  w.pointer(link);
  assert.strictEqual(w.rafs.length, 1, 'new rAF after 60 ms');
  w.flush();
  assert.strictEqual(w.outgoing('hover').length, 2, 'second hover');
  assert.strictEqual(w.outgoing('hover')[1].payload.summary.tag, 'a');

  w.admin('set_mode', { mode: 'navigate' });
  w.advance(60);
  w.pointer(btn);
  assert.strictEqual(w.rafs.length, 0, 'no hover scheduling in navigate');
  outgoingValid(w);
});

test('(10) static: no innerHTML/insertAdjacentHTML/outerHTML/document.write/eval/new Function; no postMessage with "*"', () => {
  const forbidden = [/innerHTML/, /insertAdjacentHTML/, /outerHTML/, /document\.write/, /\beval\s*\(/, /new\s+Function/];
  forbidden.forEach((re) => assert.ok(!re.test(SRC), 'forbidden pattern found: ' + re));
  assert.ok(!SRC.includes("'*'") && !SRC.includes('"*"'), 'wildcard targetOrigin found');
  const calls = SRC.match(/postMessage\([^;]*/g) || [];
  assert.ok(calls.length >= 1, 'postMessage calls located');
  calls.forEach((c) => assert.ok(/panelOrigin\)/.test(c), 'postMessage without panelOrigin: ' + c));
});

test('(11) shadow DOM (open): click în shadow root → summary, candidați și notice pe elementul GAZDĂ', () => {
  const w = makeWorld({ fixture: FIXTURE_SHADOW });
  initWorld(w, 'select');
  const host = w.doc.querySelectorAll('#widget-host')[0];
  assert.ok(host, 'host element present in fixture');
  const shadowRoot = host.attachShadow({ mode: 'open' });
  assert.strictEqual(shadowRoot.host, host, 'ShadowRoot.host exposes the host');

  // Nodes made by createNode() must answer matches() from the fake-dom engine, not a constant false.
  const inner = w.doc.createElement('button');
  inner.classList.push('shadow-btn');
  shadowRoot.appendChild(inner);
  assert.ok(inner.matches('button.shadow-btn'), 'createNode node matches its own selector');
  assert.ok(!inner.matches('a'), 'createNode node rejects a different tag');

  inner.getRootNode = () => shadowRoot;
  const ev = makeEvent('click', inner);
  ev.composedPath = () => [inner, shadowRoot, host, w.doc.body, w.doc.documentElement];
  w.dispatch('click', ev);
  assert.ok(ev.defaultPrevented && ev.stopped, 'click inside shadow root is intercepted');

  const picks = w.outgoing('pick');
  assert.strictEqual(picks.length, 1, 'one pick from the shadow click');
  const p = picks[0].payload;
  assert.strictEqual(p.notice, 'shadow', 'notice marks the shadow host');
  assert.strictEqual(p.summary.tag, 'div', 'summary describes the host, not the inner button');
  assert.strictEqual(p.summary.id, 'widget-host');
  assert.strictEqual(p.candidates[0].selector, '#widget-host', 'first candidate is the host id');
  p.candidates.forEach((c) => {
    assert.ok(w.doc.querySelectorAll(c.selector).includes(host), 'candidate matches the host: ' + c.selector);
    assert.ok(!c.selector.includes('shadow-btn') && !/(^|\s)button/.test(c.selector), 'no inner-button candidate: ' + c.selector);
  });
  outgoingValid(w);
});

test('(12) navigate după pushState fără adc_picker: link intern same-origin → nav (nu blocked); extern → blocked external_link', () => {
  const w = makeWorld();
  initWorld(w, 'select');
  w.admin('set_mode', { mode: 'navigate' });
  // A filter/archive script rewrites the URL with pushState and drops adc_picker from location.search.
  w.location.search = '?filter=red';
  w.location.href = w.location.origin + w.location.pathname + '?filter=red';

  const internal = w.click(w.byClass('item'));
  assert.ok(internal.defaultPrevented, 'internal click handled');
  assert.strictEqual(w.outgoing('blocked').length, 0, 'internal same-origin link must not be reported as blocked');
  assert.deepStrictEqual(w.outgoing('nav').map((m) => m.payload.href_path), ['/shop/'], 'nav sent for internal link');
  assert.strictEqual(w.location.assigned.length, 1, 'internal link navigates');
  assert.ok(w.location.assigned[0].includes('adc_picker=' + TOKEN), 'token re-attached from the init-time copy');

  w.click(w.byClass('ext'));
  assert.deepStrictEqual(w.outgoing('blocked').map((m) => m.payload.reason), ['external_link'], 'external link blocked');
  assert.strictEqual(w.location.assigned.length, 1, 'external link does not navigate');
  outgoingValid(w);
});

/* ---------- run ---------- */

const failed = [];
for (const t of tests) {
  try {
    t.fn();
    console.log('OK   ' + t.name);
  } catch (e) {
    failed.push(t.name);
    console.log('FAIL ' + t.name + '\n     ' + (e && e.message ? e.message : String(e)));
  }
}
if (failed.length) {
  console.log(failed.length + ' of ' + tests.length + ' picker-frame tests failed.');
  process.exit(1);
}
console.log(tests.length + ' picker-frame tests passed.');
