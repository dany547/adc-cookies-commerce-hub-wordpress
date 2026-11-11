'use strict';

/*
 * Offline test for assets/js/picker-selector.js (visual selector, §7.3).
 *
 * Runs the real generator against static HTML fixtures parsed by tests/support/fake-dom.js.
 * Each fixture sits inside <body>, so the body boundary logic is exercised as in a real page.
 * Exit code 0 = all checks passed; 1 = at least one failure (listed).
 */

const assert = require('node:assert/strict');
const path = require('node:path');
const { parseHTML } = require('./support/fake-dom.js');

const S = require(path.join(__dirname, '..', 'assets', 'js', 'picker-selector.js'));

/** Wrap a fixture in a full document so its top-level elements have <body> as parent. */
function page(html) {
  return parseHTML('<!doctype html><html><head></head><body>' + html + '</body></html>');
}

/** Structural invariants every returned candidate must satisfy. */
function checkCandidates(doc, el, candidates) {
  assert.ok(candidates.length > 0, 'no candidates returned');
  const target = S.normalizeTarget(el);
  for (const c of candidates) {
    assert.ok(c.count >= 1, 'count < 1 for ' + c.selector);
    assert.ok(c.selector.length <= 200, 'selector longer than 200: ' + c.selector);
    assert.equal(doc.querySelectorAll(c.selector).length, c.count, 'count mismatch for ' + c.selector);
    assert.ok(target.matches(c.selector), 'chosen element does not match ' + c.selector);
  }
}

const sel = (candidates) => candidates.map((c) => c.selector);
const first = (candidates) => candidates[0].selector;
const find = (candidates, selector) => candidates.find((c) => c.selector === selector);

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

/* ---------- §7.3.4: the 14 normative cases ---------- */

function caseTest(number, name, html, pick, expectFirst, extra) {
  test(`§7.3.4 caz ${number}: ${name}`, () => {
    const doc = page(html);
    const el = pick(doc);
    const candidates = S.generate(el);
    checkCandidates(doc, el, candidates);
    assert.equal(first(candidates), expectFirst, 'first candidate');
    if (extra) extra(doc, candidates);
  });
}

caseTest(1, 'link cu id stabil și tel:',
  '<a id="header-phone" href="tel:+40722000000">Sună</a>',
  (d) => d.querySelectorAll('a')[0],
  '#header-phone',
  (doc, cs) => {
    assert.equal(cs[0].score, 100);
    const tel = find(cs, 'a[href^="tel:"]');
    assert.ok(tel, 'missing a[href^="tel:"]');
    assert.equal(tel.strategy, 'pattern');
    assert.equal(tel.scope, 'similar');
    assert.equal(tel.count, 1);
    assert.ok(!cs.some((c) => c.strategy === 'href'), 'unexpected href candidate');
  });

caseTest(2, 'Elementor: span în buton fără href',
  '<div class="elementor-element elementor-element-3f4a2b1 elementor-widget elementor-widget-button" data-id="3f4a2b1" data-widget_type="button.default"><a class="elementor-button elementor-button-link"><span>Comandă</span></a></div>',
  (d) => d.querySelectorAll('span')[0],
  'a.elementor-button',
  (doc, cs) => {
    assert.equal(cs[0].score, 60);
    assert.equal(cs[0].scope, 'unique');
    assert.ok(!cs.some((c) => c.selector.includes('3f4a2b1')), 'Elementor generated id leaked');
    const anchored = find(cs, 'div[data-widget_type="button.default"] a');
    assert.ok(anchored, 'missing anchored widget selector');
    assert.equal(anchored.score, 46);
  });

caseTest(3, 'Blocksy: ancoră cu clasă de stare',
  '<div class="ct-header-cta" data-id="button"><a class="ct-button ct-active">Ofertă</a></div>',
  (d) => d.querySelectorAll('a')[0],
  'a.ct-button',
  (doc, cs) => {
    assert.equal(cs[0].score, 60);
    assert.ok(find(cs, 'div[data-id="button"] a'), 'missing div[data-id="button"] a');
    assert.ok(!cs.some((c) => c.selector.includes('ct-active')), 'state class leaked');
  });

{
  const items = Array.from({ length: 12 }, (_, i) =>
    `<a class="button add_to_cart_button ajax_add_to_cart product_type_simple" data-product_id="${i + 1}">P${i + 1}</a>`).join('');
  caseTest(4, 'Woo arhivă: al 3-lea produs',
    items,
    (d) => d.querySelectorAll('a')[2],
    'a[data-product_id="3"]',
    (doc, cs) => {
      assert.equal(cs[0].score, 85);
      assert.equal(cs[0].scope, 'unique');
      const similar = find(cs, 'a.add_to_cart_button');
      assert.ok(similar, 'missing a.add_to_cart_button');
      assert.equal(similar.scope, 'similar');
      assert.equal(similar.count, 12);
    });
}

caseTest(5, 'Woo produs: buton cu name',
  '<button name="add-to-cart" class="single_add_to_cart_button button alt">Adaugă</button>',
  (d) => d.querySelectorAll('button')[0],
  'button[name="add-to-cart"]',
  (doc, cs) => assert.equal(cs[0].score, 72));

caseTest(6, 'Emotion: clase css-* respinse, ancorare pe formular cu id',
  '<form id="contact"><button class="css-1x9f2 css-ab12cd">Trimite</button></form>',
  (d) => d.querySelectorAll('button')[0],
  '#contact button',
  (doc, cs) => {
    assert.equal(cs[0].score, 46);
    assert.ok(!cs.some((c) => c.selector.includes('css-')), 'css-in-js class leaked');
  });

caseTest(7, 'Gutenberg: clasele wp-element-button și wp-block-button__link',
  '<div class="wp-block-buttons is-layout-flex wp-container-core-buttons-is-layout-1"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Cumpără</a></div></div>',
  (d) => d.querySelectorAll('a')[0],
  'a.wp-element-button',
  (doc, cs) => {
    assert.equal(cs[0].score, 60);
    assert.equal(cs[0].scope, 'unique');
    assert.ok(find(cs, 'a.wp-block-button__link'), 'missing a.wp-block-button__link');
    assert.ok(!cs.some((c) => c.selector.includes('is-layout') || c.selector.includes('wp-container')), 'layout class leaked');
  });

caseTest(8, 'Meniu: href cu cale, fără id-ul de meniu',
  '<ul><li id="menu-item-123" class="menu-item menu-item-type-post_type current-menu-item"><a href="/contact/">Contact</a></li></ul>',
  (d) => d.querySelectorAll('a')[0],
  'a[href="/contact/"]',
  (doc, cs) => {
    assert.equal(cs[0].score, 70);
    assert.ok(!cs.some((c) => c.selector.includes('menu-item-123') || c.selector.includes('current-menu-item')), 'menu id/state leaked');
  });

caseTest(9, 'buton cu aria-label',
  '<button aria-label="Deschide coșul" class="ct-header-cart">Coș</button>',
  (d) => d.querySelectorAll('button')[0],
  'button[aria-label="Deschide coșul"]',
  (doc, cs) => {
    assert.equal(cs[0].score, 75);
    assert.ok(find(cs, 'button.ct-header-cart'), 'missing button.ct-header-cart');
  });

caseTest(10, 'aria-label cu telefon (scrub) → fără candidat aria; nth',
  '<a aria-label="Sună la 0722123456">Sună</a>',
  (d) => d.querySelectorAll('a')[0],
  'body > a',
  (doc, cs) => {
    assert.equal(cs[0].strategy, 'nth');
    assert.ok(!cs.some((c) => c.selector.includes('aria-label')), 'phone aria-label leaked');
    assert.equal(cs[0].count, 1);
  });

caseTest(11, 'cale nth-of-type până la body',
  '<div><div><span>X</span></div><div><span>Y</span></div></div>',
  (d) => d.querySelectorAll('span')[1],
  'body > div > div:nth-of-type(2) > span',
  (doc, cs) => {
    assert.equal(cs[0].strategy, 'nth');
    assert.equal(cs[0].score, 8);
    assert.equal(cs[0].count, 1);
  });

caseTest(12, 'data-testid',
  '<a data-testid="cta-hero" class="btn">Start</a>',
  (d) => d.querySelectorAll('a')[0],
  'a[data-testid="cta-hero"]',
  (doc, cs) => assert.equal(cs[0].score, 95));

caseTest(13, 'id cu caractere speciale (escape)',
  '<div id="form:1.a">Formular</div>',
  (d) => d.querySelectorAll('div')[0],
  '#form\\:1\\.a',
  (doc, cs) => {
    assert.equal(cs[0].score, 100);
    assert.equal(cs[0].count, 1);
  });

caseTest(14, 'SVG: <use> normalizează la buton',
  '<button class="search-toggle"><svg class="icon"><use/></svg></button>',
  (d) => d.querySelectorAll('use')[0],
  'button.search-toggle',
  (doc, cs) => {
    const doc2 = doc;
    const use = doc2.querySelectorAll('use')[0];
    assert.equal(S.normalizeTarget(use).tagName.toLowerCase(), 'button');
    assert.ok(!cs.some((c) => c.selector.includes('icon')), 'svg class leaked');
  });

/* ---------- §7.3.2 stability heuristics ---------- */

test('isStableId: pozitive și negative din §7.3.2', () => {
  for (const id of ['header-phone', 'contact', 'form:1.a', 'main-menu', 'site-header', 'search-form', 'deface-2']) {
    assert.equal(S.isStableId(id), true, 'expected stable: ' + id);
  }
  const unstable = [
    'x', 'post-12345', 'yui_3_17_2_1_1700000000', 'a1b2c3d4e5', '1abc', 'elementor-popup-modal',
    'e-abc', 'ui-id-3', 'react-select-2-input', 'ember12', 'mce_0', 'radix-a', 'menu-item-123',
    '550e8400-e29b-41d4-a716-446655440000', ':r1:', 'a'.repeat(41),
  ];
  for (const id of unstable) {
    assert.equal(S.isStableId(id), false, 'expected unstable: ' + id);
  }
});

test('isStableClass: pozitive și negative din §7.3.2', () => {
  const stable = [
    'elementor-button', 'elementor-button-link', 'elementor-widget-button', 'elementor-element',
    'ct-button', 'ct-header-cart', 'ct-header-cta', 'add_to_cart_button', 'single_add_to_cart_button',
    'product_type_simple', 'wp-block-button__link', 'wp-element-button', 'wp-block-cover__inner',
    'wp-block-buttons', 'menu-item', 'button', 'btn', 'search-toggle', 'icon',
  ];
  const unstable = [
    'elementor-element-3f4a2b1', 'elementor-element-3f4a', 'elementor-repeater-item-a1b2c3d', 'elementor-123',
    'e-con-inner', 'e-4f2a9c1', 'css-1x9f2', 'css-ab12cd', 'sc-bdVaJa', 'jsx-123456', 'svelte-1abc2d',
    '_1a2B3c', 'Button_root__a1B2c', 'wp-container-core-buttons-is-layout-1', 'is-layout-flex',
    'has-primary-color', 'wp-image-42', 'menu-item-123', 'menu-item-type-post_type', 'current-menu-item',
    'ct-active', 'active', 'is-open', 'swiper-slide-active', 'item-2024',
  ];
  for (const c of stable) assert.equal(S.isStableClass(c), true, 'expected stable: ' + c);
  for (const c of unstable) assert.equal(S.isStableClass(c), false, 'expected unstable: ' + c);
});

test('isStableAttr: pozitive și negative din §7.3.2', () => {
  const stable = [
    ['data-testid', 'cta-hero'], ['data-id', 'button'], ['data-id', 'logo'], ['data-widget_type', 'button.default'],
    ['data-product_id', '3'], ['aria-label', 'Deschide coșul'], ['title', 'Coș'], ['name', 'add-to-cart'],
  ];
  const unstable = [
    ['data-id', '3f4a2b1'], ['data-id', '3f4a'], ['data-product_id', '1234567'], ['data-action', 'a1b2c3d4'],
    ['aria-label', 'Sună la 0722123456'], ['placeholder', 'Caută'], ['data-foo', 'bar'], ['title', ''],
  ];
  for (const [n, v] of stable) assert.equal(S.isStableAttr(n, v), true, `expected stable: ${n}=${v}`);
  for (const [n, v] of unstable) assert.equal(S.isStableAttr(n, v), false, `expected unstable: ${n}=${v}`);
});

/* ---------- §7.3.3 escape vectors ---------- */

test('escape: vectorii normativi din §7.3.3', () => {
  const vectors = [
    ['header-phone', 'header-phone'],
    ['form:1.a', 'form\\:1\\.a'],
    ['1abc', '\\31 abc'],
    ['-1a', '-\\31 a'],
    ['-', '\\-'],
    ['a b', 'a\\ b'],
    ['a"b', 'a\\"b'],
    ['ț', 'ț'],
    ['a\u0000b', 'a�b'],
    ['a\u0001b', 'a\\1 b'],
  ];
  for (const [input, expected] of vectors) {
    assert.equal(S.escape(input), expected, 'escape(' + JSON.stringify(input) + ')');
  }
});

test('escape nu adaugă prefixul # sau . (îl pune generatorul)', () => {
  assert.equal(S.escape('main'), 'main');
  assert.ok(!S.escape('main').startsWith('#'));
  const doc = page('<div id="form:1.a">X</div>');
  const cs = S.generate(doc.querySelectorAll('div')[0]);
  assert.equal(cs[0].selector, '#form\\:1\\.a');
});

test('candidații pattern au scope similar chiar la count=1', () => {
  const doc = page('<a id="x" href="tel:+40722000000">Sună</a>');
  const cs = S.generate(doc.querySelectorAll('a')[0]);
  const pattern = cs.filter((c) => c.strategy === 'pattern');
  assert.ok(pattern.length >= 1, 'no pattern candidate');
  for (const c of pattern) {
    assert.equal(c.count, 1);
    assert.equal(c.scope, 'similar', 'pattern scope for ' + c.selector);
  }
});

/* ---------- §8.4: invariant on deep fixtures (8–10 levels, no stable hooks) ---------- */

const P = require(path.join(__dirname, '..', 'assets', 'js', 'picker-protocol.js'));
const CH = 'ab'.repeat(16);

/** Nest a leaf inside `depth` plain divs; `siblings` adds a same-tag sibling per level (forces nth-of-type). */
function nest(depth, leaf, siblings) {
  let html = leaf;
  for (let i = 0; i < depth; i += 1) {
    html = '<div>' + (siblings ? '<div>s</div>' : '') + html + '</div>';
  }
  return html;
}

const deepCases = [
  { name: 'adâncime 8, fără atribute', html: nest(8, '<span>X</span>', false) },
  { name: 'adâncime 9, fără atribute', html: nest(9, '<span>X</span>', false) },
  { name: 'adâncime 10, fără atribute', html: nest(10, '<span>X</span>', false) },
  { name: 'adâncime 9, frați de același tip', html: nest(9, '<span>X</span>', true) },
  { name: 'adâncime 10, strămoș cu id stabil la vârf', html: '<div id="site-wrap">' + nest(9, '<span>X</span>', false) + '</div>' },
  // Non-vacuous: a stable class within 4 levels of the target yields a real candidate.
  { name: 'adâncime 9, clasă stabilă aproape de țintă', html: nest(8, '<div class="promo-card"><span>X</span></div>', false), expectCandidates: true },
];

/**
 * §8.4 invariant: generate() returns [] (the frame then reports error:internal),
 * or every candidate has count ≥ 1, is ≤ 200 chars, and matches the chosen element.
 */
function assertDeepInvariant(doc, el, candidates, label) {
  const target = S.normalizeTarget(el);
  for (const c of candidates) {
    const hits = doc.querySelectorAll(c.selector);
    assert.ok(c.selector.length <= 200, label + ': selector longer than 200: ' + c.selector);
    assert.ok(c.count >= 1, label + ': count < 1 for ' + c.selector);
    assert.equal(hits.length, c.count, label + ': count mismatch for ' + c.selector);
    assert.ok(hits.includes(target), label + ': chosen element not matched by ' + c.selector);
  }
}

for (const deep of deepCases) {
  test('§8.4 adâncime: ' + deep.name, () => {
    const doc = page(deep.html);
    const el = doc.querySelectorAll('span')[0];
    const candidates = S.generate(el);
    assertDeepInvariant(doc, el, candidates, deep.name);
    if (deep.expectCandidates) {
      assert.ok(candidates.length >= 1, deep.name + ': expected at least one candidate');
    }
  });
}

test('§8.4 adâncime: payload pick din candidații reali trece adcPickerProtocol.validate (F→A, ok:true)', () => {
  const sources = [
    '<button name="add-to-cart" class="single_add_to_cart_button button alt">Adaugă</button>',
    ...deepCases.map((d) => d.html),
  ];
  let validated = 0;
  for (const html of sources) {
    const doc = page(html);
    const el = doc.querySelectorAll(html.includes('<button') ? 'button' : 'span')[0];
    const candidates = S.generate(el);
    if (candidates.length === 0) { continue; }
    const payload = {
      summary: { tag: el.tagName.toLowerCase(), id: '', classes: [], role: '', text: 'X', rect: { x: 0, y: 0, w: 10, h: 10 } },
      candidates: candidates.slice(0, 5).map((c) => ({ selector: c.selector, score: c.score, count: c.count, strategy: c.strategy, scope: c.scope })),
      notice: '',
    };
    const result = P.validate(P.make('pick', CH, 1, payload), P.DIR_TO_ADMIN, { ch: CH, seq: 0 });
    assert.equal(result.ok, true, 'pick payload rejected: ' + result.reason);
    validated += 1;
  }
  assert.ok(validated >= 1, 'no fixture produced candidates to validate');
});

/* ---------- runner ---------- */

let failures = 0;
for (const t of tests) {
  try {
    t.fn();
    console.log('ok   - ' + t.name);
  } catch (err) {
    failures += 1;
    console.log('FAIL - ' + t.name + '\n       ' + String(err && err.message).split('\n').join('\n       '));
  }
}
console.log(`\n${tests.length - failures}/${tests.length} passed`);
if (failures) process.exitCode = 1;
