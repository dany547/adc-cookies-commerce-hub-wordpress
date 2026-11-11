'use strict';

const assert = require('assert');
const feeds = require('../assets/js/admin-feeds.js');

class Node {
  constructor(tag, text) {
    this.tagName = tag.toUpperCase();
    this.attributes = {};
    this.children = [];
    this.listeners = {};
    this.parentNode = null;
    this._text = text || '';
    this.value = '';
    this.checked = false;
  }
  set textContent(value) { this.children = []; this._text = String(value); }
  get textContent() { return this._text + this.children.map((child) => child.textContent).join(''); }
  set className(value) { this.setAttribute('class', value); }
  get className() { return this.getAttribute('class') || ''; }
  setAttribute(key, value) {
    this.attributes[key] = String(value);
    if (key === 'value') this.value = String(value);
    if (key === 'checked') this.checked = true;
  }
  getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attributes, key) ? this.attributes[key] : null; }
  addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  focus() {}
  querySelector(selector) {
    if (selector.charAt(0) === '#') return this.find((node) => node.getAttribute('id') === selector.slice(1));
    return null;
  }
  find(predicate) {
    for (const child of this.children) {
      if (predicate(child)) return child;
      const nested = child.find(predicate);
      if (nested) return nested;
    }
    return null;
  }
  all(predicate, out = []) {
    for (const child of this.children) {
      if (predicate(child)) out.push(child);
      child.all(predicate, out);
    }
    return out;
  }
  change(value) {
    this.value = value;
    for (const callback of this.listeners.change || []) callback({ target: this });
  }
  click() { for (const callback of this.listeners.click || []) callback({ target: this }); }
}

const documentStub = {
  createElement: (tag) => new Node(tag),
  createTextNode: (text) => new Node('#text', text)
};
const root = new Node('div');
for (const [key, value] of Object.entries({
  'data-rest-url': '/wp-json/adc/v1/feeds',
  'data-nonce': 'test-nonce',
  'data-channels': JSON.stringify([
    { id: 'google', label: 'Google', formats: ['xml'], available: true }
  ]),
  'data-stats-enabled': '1',
  'data-stats-settings-url': '/wp-admin/admin.php?page=adc-marketing&tab=analytics'
})) root.setAttribute(key, value);

global.document = documentStub;
global.window = { fetch: async () => ({ ok: true, status: 200, json: async () => ({ items: [] }) }) };

const by = (predicate) => root.find(predicate);
const all = (predicate) => root.all(predicate);
const button = (label) => by((node) => node.tagName === 'BUTTON' && node.textContent === label);
const input = (name, value) => all((node) => node.tagName === 'INPUT' && node.getAttribute('name') === name && (value === undefined || node.getAttribute('value') === value))[0];
const select = (name) => by((node) => node.tagName === 'SELECT' && node.getAttribute('name') === name);

const english = {
  feed_ui_096: ' · in-stock only', feed_ui_097: ' · strict validation',
  feed_ui_127: 'All published products', feed_ui_152: 'Categories: ',
  feed_ui_153: ' · exclude invalid products', feed_ui_170: 'Manual',
  feed_ui_172: 'Daily', feed_ui_174: 'Purchases', feed_ui_175: 'Views',
  feed_ui_176: 'Add to cart', feed_ui_177: 'Checkout started',
  feed_ui_179: 'Top products', feed_ui_180: 'Minimum value',
  feed_ui_181: 'Low conversion (maximum 2%)',
  feed_ui_197: 'Smart: %s · %s · %s days · refresh %s',
  feed_ui_198: 'categories: %s'
};
const en = (key) => english[key] || key;
const scopes = [
  { scope: 'all', state: { scope: 'all' }, ro: 'Toate produsele publicate', en: 'All published products' },
  { scope: 'categories', state: { scope: 'filtered', categories: 'rochii, pantofi' }, ro: 'Categorii: rochii, pantofi', en: 'Categories: rochii, pantofi' },
  { scope: 'smart', state: { scope: 'smart', smartMetric: 'views', smartMode: 'top_n', smartDays: 30, smartLimit: 50, refresh: 'daily', categories: 'incaltaminte, accesorii' }, ro: 'Smart: Vizualizări · Top produse · 30 zile · reîmprospătare Zilnic · categorii: incaltaminte, accesorii', en: 'Smart: Views · Top products · 30 days · refresh Daily · categories: incaltaminte, accesorii' }
];
for (const scope of scopes) {
  const normalized = feeds.normalize(scope.state);
  assert(feeds.productSummary(normalized).startsWith(scope.ro), scope.scope + ' summary renders in Romanian');
  assert(feeds.productSummary(normalized, en).startsWith(scope.en), scope.scope + ' summary renders in English');
}

(async () => {
  feeds.mount(root);
  await new Promise((resolve) => setImmediate(resolve));
  button('Feed nou').click();
  const name = input('name');
  name.value = 'Smart feed test';
  name.listeners.input[0]({ target: name });
  button('Continuă').click();
  input('adc-feeds-scope', 'smart').change('smart');
  assert(root.children.length > 0, 'switching to Smart keeps the root mounted');

  const metric = select('smartMetric');
  assert(metric, 'metric select rendered');
  for (const value of ['purchases', 'views', 'add_to_cart', 'begin_checkout']) {
    metric.change(value);
    assert(root.children.length > 0 && select('smartMetric'), 'metric ' + value + ' renders without clearing the wizard');
  }
  for (const value of ['7', '14', '30', '90']) {
    select('smartDays').change(value);
    assert(root.children.length > 0 && select('smartDays'), 'period ' + value + ' renders without clearing the wizard');
  }
  for (const value of ['top_n', 'min_value', 'low_conversion']) {
    select('smartMode').change(value);
    assert(root.children.length > 0 && select('smartMode'), 'mode ' + value + ' renders without clearing the wizard');
  }
  // --- Google taxonomy picker: debounce, escaping, selection, import outcomes ---
  const flushAll = async () => { for (let i = 0; i < 6; i++) await new Promise((resolve) => setImmediate(resolve)); };
  const timers = new Map();
  let timerSeq = 0;
  const runTimers = () => { for (const [id, fn] of [...timers]) { timers.delete(id); fn(); } };
  const fetchCalls = [];
  let reply = () => ({ ok: true, status: 200, json: async () => ({ items: [], defaults: [] }) });
  const json = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
  global.window.setTimeout = (fn) => { const id = ++timerSeq; timers.set(id, fn); return id; };
  global.window.clearTimeout = (id) => { timers.delete(id); };
  global.window.fetch = async (url, init) => { fetchCalls.push({ url, init }); return reply(url, init); };
  const SAFE_ITEM = { id: '3917', path: 'Apparel & Accessories > <img src=x onerror=alert(1)> $& Clothing' };

  const taxRoot = new Node('div');
  for (const [key, value] of Object.entries({
    'data-rest-url': '/wp-json/adc/v1/feeds',
    'data-nonce': 'test-nonce',
    'data-channels': JSON.stringify([{ id: 'google', label: 'Google', formats: ['xml'], available: true }]),
    'data-categories': JSON.stringify([{ id: 15, name: 'Rochii' }]),
    'data-stats-enabled': '1',
    'data-stats-settings-url': '/wp-admin/admin.php?page=adc-marketing&tab=analytics'
  })) taxRoot.setAttribute(key, value);
  const byId = (id) => taxRoot.find((node) => node.getAttribute('id') === id);
  const buttonNamed = (label) => taxRoot.find((node) => node.tagName === 'BUTTON' && node.textContent === label);
  const hasText = (fragment) => taxRoot.all((node) => node.tagName === '#TEXT' || node.children.length === 0)
    .some((node) => node.textContent.includes(fragment));
  const resultButtons = () => taxRoot.all((node) => node.tagName === 'BUTTON' && /^3917 — /.test(node.textContent));

  reply = (url, init) => {
    if (init && init.method === 'POST') return json(200, { imported: true, version: '2021-09-21', count: 2 });
    if (url.indexOf('/taxonomy?q=') !== -1) return json(200, { version: '2021-09-21', items: [SAFE_ITEM] });
    return json(200, { items: [], defaults: [] });
  };
  feeds.mount(taxRoot);
  await flushAll();
  buttonNamed('Feed nou').click();
  const taxName = taxRoot.find((node) => node.tagName === 'INPUT' && node.getAttribute('name') === 'name');
  taxName.value = 'Taxonomy feed test';
  taxName.listeners.input[0]({ target: taxName });
  buttonNamed('Continuă').click();
  await flushAll();
  buttonNamed('Continuă').click();
  await flushAll();

  await (async () => {
    assert(byId('adc-feeds-taxonomy-q'), 'taxonomy search box renders for Google');
    assert(byId('adc-feeds-taxonomy-file'), 'file input renders');
    assert(byId('adc-feeds-taxonomy-target'), 'target select renders');
    assert.strictEqual(byId('adc-feeds-taxonomy-target').children.length, 2, 'default plus one WooCommerce category');
    assert.strictEqual(byId('adc-feeds-taxonomy-q').getAttribute('type'), 'search', 'native search control');
    console.log('OK   taxonomy panel renders with native controls and a target choice');
  })();

  await (async () => {
    const q = byId('adc-feeds-taxonomy-q');
    q.value = 'ap'; q.listeners.input[0]({ target: q });
    q.value = 'apparel'; q.listeners.input[0]({ target: q });
    assert.strictEqual(timers.size, 1, 'rapid input leaves exactly one pending search');
    runTimers();
    await flushAll();
    const searches = fetchCalls.filter((call) => call.url.indexOf('/taxonomy?q=') !== -1);
    assert.strictEqual(searches.length, 1, 'debounce sends one request for the last query');
    assert(searches[0].url.includes('q=apparel') && searches[0].url.includes('limit=50'), 'URL carries q and limit=50');
    const buttons = resultButtons();
    assert.strictEqual(buttons.length, 1, 'one result button rendered');
    assert.strictEqual(buttons[0].children.length, 0, 'path is text, not child markup');
    assert(buttons[0].textContent.includes('<img src=x onerror=alert(1)>'), 'markup shown literally');
    assert.strictEqual(taxRoot.all((node) => node.tagName === 'IMG').length, 0, 'no IMG element created');
    console.log('OK   search is debounced, sends q and limit=50, and escapes path text');
  })();

  await (async () => {
    resultButtons()[0].click();
    await flushAll();
    assert.strictEqual(byId('adc-feeds-defaultCategory').getAttribute('value'), '3917', 'picked numeric ID stored in default category');
    assert(byId('adc-feeds-taxonomy-selected').textContent.startsWith('Selectat: 3917 — '), 'selection shows ID and path as text');
    assert.strictEqual(byId('adc-feeds-taxonomy-q').getAttribute('value'), 'apparel', 'search query survives the redraw');
    console.log('OK   picking a result stores the numeric ID in the default category');
  })();

  await (async () => {
    const select = byId('adc-feeds-taxonomy-target');
    select.change('map:15');
    resultButtons()[0].click();
    await flushAll();
    assert.strictEqual(byId('adc-feeds-map-15').getAttribute('value'), '3917', 'ID stored in the chosen WooCommerce category map');
    console.log('OK   target choice writes the ID into that category map only');
  })();

  await (async () => {
    const def = byId('adc-feeds-defaultCategory');
    def.value = 'Legacy > Rochii'; def.listeners.input[0]({ target: def });
    const q = byId('adc-feeds-taxonomy-q');
    q.value = 'vest'; q.listeners.input[0]({ target: q });
    runTimers();
    await flushAll();
    assert.strictEqual(def.value, 'Legacy > Rochii', 'legacy free text kept while only searching');
    assert.strictEqual(byId('adc-feeds-taxonomy-q').value, 'vest', 'search text reflected in the box');
    console.log('OK   legacy free text is kept when only searching');
  })();

  await (async () => {
    const before = fetchCalls.length;
    buttonNamed('Importă taxonomia').click();
    await flushAll();
    assert(hasText('Alege mai întâi un fișier .txt.'), 'import without a file asks for one');
    assert.strictEqual(fetchCalls.length, before, 'no request without a file');
    console.log('OK   import without a file makes no request');
  })();

  await (async () => {
    const before = fetchCalls.length;
    const fileInput = byId('adc-feeds-taxonomy-file');
    fileInput.files = [{ name: 'big.txt', size: 2097153 }];
    fileInput.listeners.change[0]({ target: fileInput });
    buttonNamed('Importă taxonomia').click();
    await flushAll();
    assert(hasText('Fișierul depășește 2 MB'), 'oversized file is refused with the Romanian message');
    assert.strictEqual(fetchCalls.length, before, 'oversized file is never sent');
    console.log('OK   oversized file is refused before reading or uploading');
  })();

  await (async () => {
    const before = fetchCalls.length;
    const saved = global.window.FileReader;
    global.window.FileReader = undefined;
    const fileInput = byId('adc-feeds-taxonomy-file');
    fileInput.files = [{ name: 'ok.txt', size: 10 }];
    fileInput.listeners.change[0]({ target: fileInput });
    buttonNamed('Importă taxonomia').click();
    await flushAll();
    global.window.FileReader = saved;
    assert(hasText('Acest browser nu poate citi fișiere local'), 'missing FileReader is reported, not a success');
    assert.strictEqual(fetchCalls.length, before, 'no upload when the browser cannot read the file');
    console.log('OK   unsupported browser reports unavailable without uploading');
  })();

  await (async () => {
    class FakeReader {
      readAsText() { this.result = 'taxonomy text'; setImmediate(() => this.onload()); }
    }
    global.window.FileReader = FakeReader;
    const fileInput = byId('adc-feeds-taxonomy-file');
    fileInput.files = [{ name: 'taxonomy.txt', size: 12 }];
    fileInput.listeners.change[0]({ target: fileInput });
    const before = fetchCalls.length;
    buttonNamed('Importă taxonomia').click();
    await flushAll();
    const post = fetchCalls.slice(before).find((call) => call.init && call.init.method === 'POST');
    assert(post && post.url.endsWith('/feeds/taxonomy'), 'import POSTs to the protected taxonomy endpoint');
    assert.deepStrictEqual(JSON.parse(post.init.body), { content: 'taxonomy text' }, 'body is JSON with the file text');
    assert(hasText('Taxonomia a fost importată: versiunea 2021-09-21, 2 categorii.'), 'success is reported only after imported=true');
    console.log('OK   successful import POSTs JSON and reports success only on imported=true');
  })();

  await (async () => {
    class FakeReader {
      readAsText() { this.result = 'x'; setImmediate(() => this.onload()); }
    }
    global.window.FileReader = FakeReader;
    const saved = reply;
    reply = (url, init) => (init && init.method === 'POST' ? json(503, {}) : saved(url, init));
    const fileInput = byId('adc-feeds-taxonomy-file');
    fileInput.files = [{ name: 'taxonomy.txt', size: 1 }];
    fileInput.listeners.change[0]({ target: fileInput });
    buttonNamed('Importă taxonomia').click();
    await flushAll();
    reply = saved;
    assert(!hasText('Taxonomia a fost importată'), 'unavailable endpoint never shows a success message');
    console.log('OK   unavailable import (503) shows no false success');
  })();

  await (async () => {
    const saved = reply;
    reply = (url) => (url.indexOf('/taxonomy?q=') !== -1 ? json(200, { version: null, items: [] }) : saved(url));
    const q = byId('adc-feeds-taxonomy-q');
    q.value = 'none'; q.listeners.input[0]({ target: q });
    runTimers();
    await flushAll();
    assert(hasText('Nu există nicio taxonomie importată'), 'no imported taxonomy is reported as empty');
    reply = (url) => (url.indexOf('/taxonomy?q=') !== -1 ? json(500, { message: 'Eroare de test' }) : saved(url));
    q.value = 'boom'; q.listeners.input[0]({ target: q });
    runTimers();
    await flushAll();
    reply = saved;
    assert(hasText('Eroare de test'), 'search failure shows the server message');
    assert.strictEqual(resultButtons().length, 0, 'failed search shows no results');
    console.log('OK   empty taxonomy and failed search are reported, not shown as results');
  })();

  assert.strictEqual(timers.size, 0, 'no taxonomy timer left behind');

  console.log('admin-feeds-render-test: PASS (all/categories/Smart summaries RO+EN; Smart selectors render; taxonomy picker)');
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
