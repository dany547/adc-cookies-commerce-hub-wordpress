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
  console.log('admin-feeds-render-test: PASS (all/categories/Smart summaries RO+EN; Smart selectors render)');
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
