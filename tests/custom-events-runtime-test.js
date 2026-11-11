/* Offline checks for the pure helpers in assets/js/custom-events.js (node, no framework).
 * Spec: docs/CUSTOM-EVENTS.md §3.3, §4.3, §8.4 (C4). Run: node tests/custom-events-runtime-test.js */
'use strict';

const assert = require('assert');
const api = require('../assets/js/custom-events.js');

let passed = 0;
function check(name, fn) {
  try {
    fn();
    passed++;
  } catch (error) {
    console.error('FAIL ' + name + ': ' + error.message);
    process.exit(1);
  }
}

// classifyHref
check('classifyHref tel/mailto/whatsapp', () => {
  assert.strictEqual(api.classifyHref('tel:+40722123456', 'site.ro'), 'tel');
  assert.strictEqual(api.classifyHref('mailto:ion@example.ro', 'site.ro'), 'mailto');
  assert.strictEqual(api.classifyHref('https://wa.me/40722123456', 'site.ro'), 'whatsapp');
  assert.strictEqual(api.classifyHref('https://api.whatsapp.com/send?phone=40722', 'site.ro'), 'whatsapp');
});
check('classifyHref download ignores query', () => {
  assert.strictEqual(api.classifyHref('https://site.ro/files/fisa.pdf?x=1', 'site.ro'), 'download');
});
check('classifyHref outbound with and without www', () => {
  assert.strictEqual(api.classifyHref('https://other.ro/', 'www.site.ro'), 'outbound');
  assert.strictEqual(api.classifyHref('https://www.other.ro/', 'site.ro'), 'outbound');
});
check('classifyHref same host (www-insensitive) is not classified', () => {
  assert.strictEqual(api.classifyHref('https://site.ro/pagina', 'www.site.ro'), '');
  assert.strictEqual(api.classifyHref('https://www.site.ro/pagina', 'site.ro'), '');
});
check('classifyHref ignores javascript:', () => {
  assert.strictEqual(api.classifyHref('javascript:alert(1)', 'site.ro'), '');
});

// matchesPage
check('matchesPage all / include / exclude', () => {
  assert.strictEqual(api.matchesPage({ mode: 'all', paths: ['/x'] }, '/anything'), true);
  assert.strictEqual(api.matchesPage({ mode: 'include', paths: ['/produse/*'] }, '/produse/tricou'), true);
  assert.strictEqual(api.matchesPage({ mode: 'include', paths: ['/produse/*'] }, '/blog/post'), false);
  assert.strictEqual(api.matchesPage({ mode: 'exclude', paths: ['/checkout'] }, '/checkout'), false);
  assert.strictEqual(api.matchesPage({ mode: 'exclude', paths: ['/checkout'] }, '/shop'), true);
});
check('matchesPage wildcard does not match the bare prefix', () => {
  assert.strictEqual(api.matchesPage({ mode: 'include', paths: ['/blog/*'] }, '/blog'), false);
});
check('matchesPage ignores query and hash', () => {
  assert.strictEqual(api.matchesPage({ mode: 'include', paths: ['/checkout'] }, '/checkout?step=2#top'), true);
});

// resolveParams
check('resolveParams: unknown token -> empty string', () => {
  const out = api.resolveParams([['a', '{{page_path}}'], ['b', '{{unknown}}']], { '{{page_path}}': '/shop' });
  assert.deepStrictEqual(out, { a: '/shop', b: '' });
});
check('resolveParams: interpolation inside a value', () => {
  const out = api.resolveParams([['t', 'Buy {{click_text}} now']], { '{{click_text}}': 'Shirt' });
  assert.strictEqual(out.t, 'Buy Shirt now');
});
check('resolveParams: values truncated to 100 characters', () => {
  const out = api.resolveParams([['c', 'ab '.repeat(50)]], {}); // spaced text: an opaque 150-char run would be scrubbed to [id]
  assert.strictEqual(out.c.length, 100);
});
check('resolveParams: scrub is applied to resolved values', () => {
  const out = api.resolveParams([['u', '{{click_url}}']], { '{{click_url}}': 'mailto:ion@example.ro' });
  assert.strictEqual(out.u, '[email]');
});

// scrub (same vectors as tests/custom-event-rule-test.php)
const scrubCases = [
  ['ion@example.ro', '[email]'],
  ['Sună 0722 123 456 acum', 'Sună [nr] acum'],
  ['+40-722-123-456', '+[nr]'],
  ['1800101123456', '[nr]'],
  ['RO49AAAA1B31007593840000', '[iban]'],
  ['abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', '[id]'],
  ['Bună ziua, vă mulțumesc', 'Bună ziua, vă mulțumesc'],
];
for (const [input, expected] of scrubCases) {
  check('scrub(' + input + ')', () => assert.strictEqual(api.scrub(input), expected));
}

// normalizeText
check('normalizeText strips diacritics, lowercases, collapses spaces', () => {
  assert.strictEqual(api.normalizeText('  București   Ștefan  '), 'bucuresti stefan');
  assert.strictEqual(api.normalizeText('Încărcare Ţară'), 'incarcare tara');
});

// scrollPercent + short-page rule
check('scrollPercent basic', () => {
  assert.strictEqual(api.scrollPercent(0, 800, 2000), 40);
  assert.strictEqual(api.scrollPercent(1200, 800, 2000), 100);
});
check('scrollPercent on a short page is >= 100 at load (init() must wait for a real scroll event)', () => {
  assert.ok(api.scrollPercent(0, 800, 500) >= 100);
});

// shouldFire
check('shouldFire once: fires exactly once', () => {
  const rule = { id: 'ce_1a2b3c4d', fire: 'once' };
  const state = {};
  assert.strictEqual(api.shouldFire(rule, state, 'a', 1000), true);
  assert.strictEqual(api.shouldFire(rule, state, 'a', 90000), false);
});
check('shouldFire every: 1 s debounce per element key', () => {
  const rule = { id: 'ce_1a2b3c4d', fire: 'every' };
  const state = {};
  assert.strictEqual(api.shouldFire(rule, state, 'a', 5000), true);
  assert.strictEqual(api.shouldFire(rule, state, 'a', 5500), false);
  assert.strictEqual(api.shouldFire(rule, state, 'a', 6100), true);
  assert.strictEqual(api.shouldFire(rule, state, 'b', 6200), true);
});

// compileRules
function fakeDoc(throws) {
  return {
    createDocumentFragment() {
      return {
        querySelector() {
          if (throws) throw new SyntaxError('invalid selector');
          return null;
        },
      };
    },
  };
}
check('compileRules: invalid selector disables only that rule, no exception', () => {
  const rules = [{ id: 'ce_00000001', trigger: { type: 'click', selector: 'a[' } }];
  const [compiled] = api.compileRules(rules, fakeDoc(true));
  assert.strictEqual(compiled.disabled, true);
  assert.strictEqual(compiled.selector, null);
});
check('compileRules: valid selector keeps the rule enabled', () => {
  const rules = [{ id: 'ce_00000002', trigger: { type: 'visible', selector: '.hero' } }];
  const [compiled] = api.compileRules(rules, fakeDoc(false));
  assert.strictEqual(compiled.disabled, false);
  assert.strictEqual(compiled.selector, '.hero');
});
check('compileRules: rule without selector is enabled', () => {
  const [compiled] = api.compileRules([{ id: 'ce_00000003', trigger: { type: 'click', preset: 'tel' } }], fakeDoc(true));
  assert.strictEqual(compiled.disabled, false);
});

// ---------------------------------------------------------------------------
// init() on a minimal fake window/document: exercises the real listener and
// fire() path (not only the pure helpers above). Time is faked via Date.now,
// which fire() reads directly.
// ---------------------------------------------------------------------------
let clock = 1000;
const realNow = Date.now;
Date.now = () => clock;

/** Frontend-shaped rule (what ADC_Custom_Event_Rule::to_frontend() emits). */
function rule(name, id, trigger, extra) {
  return Object.assign({
    id: id,
    name: name,
    trigger: trigger,
    fire: 'once',
    page_filter: { mode: 'all', paths: [] },
    params: [],
    value: null,
    currency: '',
    category: 'statistics',
    mirror: false,
  }, extra || {});
}

/** Minimal element: `selectors` decides what closest()/matches() report. */
function fakeElement(id, selectors) {
  return {
    id: id,
    innerText: 'Buton',
    selectors: selectors || [],
    closest(sel) { return this.selectors.includes(sel) ? this : null; },
    matches(sel) { return this.selectors.includes(sel); },
    getAttribute() { return null; },
    hasAttribute() { return false; },
  };
}

function click(target) { return { type: 'click', isTrusted: true, target: target, button: 0 }; }

function fakeWindow(customRules) {
  const listeners = { doc: Object.create(null), win: Object.create(null) };
  const timers = [];
  const rafQueue = [];
  const observers = [];
  let timerId = 0;
  let rafCount = 0;
  const nodes = [];
  const doc = {
    body: null, // no MutationObserver rescans in these tests
    documentElement: { scrollHeight: 2000 },
    visibilityState: 'visible',
    createDocumentFragment() { return { querySelector() { return null; } }; },
    querySelectorAll(sel) { return nodes.filter((n) => n.selectors.includes(sel)); },
    addEventListener(type, fn) { (listeners.doc[type] = listeners.doc[type] || []).push(fn); },
  };
  const win = {
    adcConfig: { customEvents: { v: 1, rules: customRules } },
    document: doc,
    location: { pathname: '/', host: 'site.ro', href: 'https://site.ro/' },
    navigator: {},
    scrollY: 0,
    pageYOffset: 0,
    innerHeight: 800,
    console: { warn() {} },
    adcTrack: {
      debug: false,
      calls: [],
      event(name, data) { this.calls.push({ name: name, data: data }); return 'evt-' + this.calls.length; },
    },
    IntersectionObserver: class {
      constructor(cb, opts) { this.cb = cb; this.opts = opts; observers.push(this); }
      observe() {}
    },
    setTimeout(fn, ms) { const t = { id: ++timerId, fn: fn, at: clock + (Number(ms) || 0) }; timers.push(t); return t.id; },
    clearTimeout(id) { const i = timers.findIndex((t) => t.id === id); if (i >= 0) timers.splice(i, 1); },
    setInterval() { return 0; },
    requestAnimationFrame(fn) { rafCount++; rafQueue.push(fn); return rafCount; },
    addEventListener(type, fn) { (listeners.win[type] = listeners.win[type] || []).push(fn); },
  };
  return {
    win: win,
    doc: doc,
    nodes: nodes,
    observers: observers,
    listeners: listeners,
    emit(type, event) { (listeners.doc[type] || []).forEach((fn) => fn(event)); },
    emitWin(type, event) { (listeners.win[type] || []).forEach((fn) => fn(event)); },
    runRaf() { while (rafQueue.length) rafQueue.shift()(); },
    runTimers() {
      while (timers.length) {
        timers.sort((a, b) => a.at - b.at);
        const t = timers.shift();
        clock = Math.max(clock, t.at);
        t.fn();
      }
    },
    rafCount() { return rafCount; },
    names() { return win.adcTrack.calls.map((c) => c.name); },
  };
}

// (a) Two visible rules with the same threshold on the same element both emit.
check('visible: two rules sharing a threshold on one element both emit', () => {
  clock = 10000;
  const hero = fakeElement('hero', ['.hero']);
  const f = fakeWindow([
    rule('custom_hero_a', 'ce_0000000a', { type: 'visible', selector: '.hero', threshold: 0.5, min_visible_ms: 0 }),
    rule('custom_hero_b', 'ce_0000000b', { type: 'visible', selector: '.hero', threshold: 0.5, min_visible_ms: 0 }),
  ]);
  f.nodes.push(hero);
  api.init(f.win);
  assert.strictEqual(f.observers.length, 1, 'one observer per threshold');
  f.observers[0].cb([{ target: hero, isIntersecting: true, intersectionRatio: 0.6 }]);
  f.runTimers();
  assert.deepStrictEqual(f.names(), ['custom_hero_a', 'custom_hero_b']);
});

// (b) value/currency stay flat in params next to rule params and adc_rule_id.
check('click rule: value and currency are flat params beside rule params and adc_rule_id', () => {
  clock = 20000;
  const promo = fakeElement('promo', ['.promo']);
  const f = fakeWindow([
    rule('custom_promo_view', 'ce_0000000c', { type: 'click', preset: '', selector: '.promo' }, {
      value: 10,
      currency: 'RON',
      params: [['link_location', 'header'], ['campaign', 'toamna']],
    }),
  ]);
  api.init(f.win);
  f.emit('click', click(promo));
  assert.strictEqual(f.win.adcTrack.calls.length, 1, 'click must emit once');
  const call = f.win.adcTrack.calls[0];
  assert.strictEqual(call.name, 'custom_promo_view');
  assert.deepStrictEqual(call.data.params, {
    link_location: 'header',
    campaign: 'toamna',
    adc_rule_id: 'ce_0000000c',
    value: 10,
    currency: 'RON',
  });
});

// (c) scroll: rAF scheduling is throttled to at most one request per 200 ms.
check('scroll_depth: scroll handler throttled to 200 ms', () => {
  clock = 1000;
  const f = fakeWindow([rule('custom_scroll_a', 'ce_0000000d', { type: 'scroll_depth', depths: [25] })]);
  api.init(f.win);
  const scroll = () => f.emitWin('scroll', { isTrusted: true });
  scroll(); f.runRaf();                 // t=1000 -> scheduled
  assert.strictEqual(f.rafCount(), 1);
  clock = 1050; scroll(); f.runRaf();   // inside 200 ms -> dropped
  clock = 1100; scroll(); f.runRaf();
  clock = 1199; scroll(); f.runRaf();
  assert.strictEqual(f.rafCount(), 1, 'scrolls within 200 ms must not schedule rAF');
  clock = 1200; scroll(); f.runRaf();   // exactly 200 ms later -> scheduled
  assert.strictEqual(f.rafCount(), 2);
  assert.deepStrictEqual(f.names(), ['custom_scroll_a'], 'depth 25 fires once (40% scrolled)');
});

// (d) form_success: one document listener per preset; rules filtered by form_id.
check('form_success: one listener per preset, rules routed by form_id', () => {
  const f = fakeWindow([
    rule('custom_form_any', 'ce_0000000e', { type: 'form_success', form_preset: 'cf7', form_id: '' }),
    rule('custom_form_12', 'ce_0000000f', { type: 'form_success', form_preset: 'cf7', form_id: '12' }),
    rule('custom_form_99', 'ce_00000010', { type: 'form_success', form_preset: 'cf7', form_id: '99' }),
  ]);
  api.init(f.win);
  assert.strictEqual((f.listeners.doc.wpcf7mailsent || []).length, 1, 'exactly one wpcf7mailsent listener for three rules');
  f.emit('wpcf7mailsent', { detail: { contactFormId: 12 } });
  assert.deepStrictEqual(f.names(), ['custom_form_any', 'custom_form_12']);
});

// (e) once vs every on the real path: every debounces 1 s per element, once fires once.
check('click: once fires once, every debounces 1 s per element', () => {
  clock = 5000;
  const btn1 = fakeElement('b1', ['.btn']);
  const btn2 = fakeElement('b2', ['.btn']);
  const f = fakeWindow([
    rule('custom_once_btn', 'ce_00000011', { type: 'click', preset: '', selector: '.btn' }, { fire: 'once' }),
    rule('custom_every_btn', 'ce_00000012', { type: 'click', preset: '', selector: '.btn' }, { fire: 'every' }),
  ]);
  api.init(f.win);
  f.emit('click', click(btn1));          // t=5000: once + every fire
  clock = 5500; f.emit('click', click(btn1)); // blocked: once spent, every within 1 s
  clock = 6100; f.emit('click', click(btn1)); // every fires again (1.1 s later)
  clock = 6200; f.emit('click', click(btn2)); // every fires: different element key
  assert.deepStrictEqual(f.names(), ['custom_once_btn', 'custom_every_btn', 'custom_every_btn', 'custom_every_btn']);
});

// Restore the real clock for anything that follows.
Date.now = realNow;

console.log(`Custom events runtime tests passed (${passed} checks).`);
