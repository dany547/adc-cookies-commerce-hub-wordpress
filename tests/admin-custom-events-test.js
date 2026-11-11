/* Offline checks for the custom-event admin editor (assets/js/admin-custom-events.js).
 * Spec: docs/CUSTOM-EVENTS.md §6.3, §6.7, §8.4 (C8). Run: node tests/admin-custom-events-test.js
 * Uses a minimal fake DOM (no jsdom). Supports the selector subset the script uses:
 * tag, .class, [attr], [attr="v"], [attr^="v"], [attr$="v"], :checked, descendant and comma lists. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const SCRIPT = path.join(__dirname, '..', 'assets', 'js', 'admin-custom-events.js');
const api = require(SCRIPT);

/* ---------- Minimal fake DOM ---------- */

const TOKEN = /\.([\w-]+)|\[([\w-]+)(?:(\$=|\^=|=)"([^"]*)")?\]|:checked/y;

function parseCompound(text) {
  const parts = [];
  const tag = /^[a-zA-Z][\w-]*/.exec(text);
  let i = 0;
  if (tag) {
    parts.push({ kind: 'tag', value: tag[0].toLowerCase() });
    i = tag[0].length;
  }
  while (i < text.length) {
    TOKEN.lastIndex = i;
    const m = TOKEN.exec(text);
    if (!m) throw new Error('unsupported selector part in: ' + text);
    if (m[1] !== undefined) parts.push({ kind: 'class', value: m[1] });
    else if (m[2] !== undefined) parts.push({ kind: 'attr', name: m[2], op: m[3] || null, value: m[4] });
    else parts.push({ kind: 'checked' });
    i = TOKEN.lastIndex;
  }
  return parts;
}

function parseSelectorList(selector) {
  return selector.split(',').map((alt) => alt.trim().split(/\s+/).map(parseCompound));
}

function matchCompound(node, parts) {
  return parts.every((p) => {
    if (p.kind === 'tag') return node.tagName === p.value;
    if (p.kind === 'class') return (node.getAttribute('class') || '').split(/\s+/).includes(p.value);
    if (p.kind === 'checked') return Boolean(node.checked);
    const v = node.getAttribute(p.name);
    if (v === null) return false;
    if (p.op === null) return true;
    if (p.op === '=') return v === p.value;
    if (p.op === '^=') return v.startsWith(p.value);
    return v.endsWith(p.value);
  });
}

function matchComplex(node, compounds) {
  if (!matchCompound(node, compounds[compounds.length - 1])) return false;
  let idx = compounds.length - 2;
  let ancestor = node.parentNode;
  while (idx >= 0 && ancestor) {
    if (ancestor.nodeType === 1 && matchCompound(ancestor, compounds[idx])) idx--;
    ancestor = ancestor.parentNode;
  }
  return idx < 0;
}

function matchesSelector(node, selector) {
  return parseSelectorList(selector).some((compounds) => matchComplex(node, compounds));
}

function descendantsMatching(root, selector) {
  const lists = parseSelectorList(selector);
  const out = [];
  (function walk(node) {
    node.children.forEach((child) => {
      if (lists.some((compounds) => matchComplex(child, compounds))) out.push(child);
      walk(child);
    });
  })(root);
  return out;
}

class FakeElement {
  constructor(doc, tag) {
    this.ownerDocument = doc;
    this.tagName = tag.toLowerCase();
    this.nodeType = 1;
    this.attrs = {};
    this.children = [];
    this.parentNode = null;
    this.listeners = {};
    this.hidden = false;
    this.disabled = false;
    this.checked = false;
    this.focusCount = 0;
    this.selectionStart = 0;
    this.selectionEnd = 0;
    this._value = undefined;
    this._text = '';
  }
  get value() { return this._value !== undefined ? this._value : (this.attrs.value !== undefined ? this.attrs.value : ''); }
  set value(v) { this._value = String(v); }
  get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
  set textContent(v) { this.children = []; this._text = String(v); }
  get className() { return this.attrs.class || ''; }
  set className(v) { this.attrs.class = String(v); }
  get firstChild() { return this.children[0] || null; }
  get nextElementSibling() {
    if (!this.parentNode) return null;
    return this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null;
  }
  setAttribute(name, v) { this.attrs[name] = String(v); }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
  removeAttribute(name) { delete this.attrs[name]; }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  removeChild(child) {
    this.children = this.children.filter((c) => c !== child);
    child.parentNode = null;
    return child;
  }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  focus() { this.focusCount++; this.ownerDocument.activeElement = this; }
  setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; }
  matches(selector) { return matchesSelector(this, selector); }
  closest(selector) {
    let node = this;
    while (node && node.nodeType === 1) {
      if (matchesSelector(node, selector)) return node;
      node = node.parentNode;
    }
    return null;
  }
  contains(other) {
    let node = other;
    while (node) {
      if (node === this) return true;
      node = node.parentNode;
    }
    return false;
  }
  querySelector(selector) { return descendantsMatching(this, selector)[0] || null; }
  querySelectorAll(selector) { return descendantsMatching(this, selector); }
}

class FakeDocument {
  constructor() {
    this.listeners = {};
    this.activeElement = null;
    this.body = new FakeElement(this, 'body');
  }
  createElement(tag) { return new FakeElement(this, tag); }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  querySelector(selector) { return this.body.querySelector(selector); }
  querySelectorAll(selector) { return this.body.querySelectorAll(selector); }
}

/** Dispatch with bubbling: element → ancestors → body → document. */
function dispatch(target, type) {
  const event = {
    type,
    target,
    defaultPrevented: false,
    preventDefault() { this.defaultPrevented = true; },
    stopPropagation() {},
  };
  let node = target;
  while (node) {
    (node.listeners[type] || []).forEach((fn) => fn(event));
    node = node.parentNode;
  }
  (target.ownerDocument.listeners[type] || []).forEach((fn) => fn(event));
  return event;
}

function el(doc, tag, attrs, text) {
  const node = doc.createElement(tag);
  Object.entries(attrs || {}).forEach(([k, v]) => node.setAttribute(k, v));
  if (text !== undefined) node.textContent = text;
  return node;
}

/* ---------- Fixtures ---------- */

const TRIGGER_TYPES = ['click', 'form_success', 'visible', 'scroll_depth', 'timer'];

/** Mirrors render_editor() in class-adc-admin-tab-custom-events.php: every server-rendered row has a remove button. */
function makeRow(doc, index, key, value) {
  const row = el(doc, 'tr', { class: 'adc-ce-param' });
  const keyCell = el(doc, 'td');
  keyCell.appendChild(el(doc, 'label', { class: 'screen-reader-text', for: `adc-ce-param-key-${index}` }, 'Cheie parametru'));
  keyCell.appendChild(el(doc, 'input', { id: `adc-ce-param-key-${index}`, type: 'text', name: `rule[params][${index}][key]`, value: key, maxlength: '40' }));
  const valueCell = el(doc, 'td');
  valueCell.appendChild(el(doc, 'label', { class: 'screen-reader-text', for: `adc-ce-param-value-${index}` }, 'Valoare parametru'));
  valueCell.appendChild(el(doc, 'input', { id: `adc-ce-param-value-${index}`, type: 'text', name: `rule[params][${index}][value]`, value, maxlength: '100' }));
  const actionCell = el(doc, 'td');
  actionCell.appendChild(el(doc, 'button', { type: 'button', class: 'button button-small adc-ce-remove-param', 'aria-label': `Șterge parametrul ${index + 1}` }, 'Șterge'));
  row.appendChild(keyCell);
  row.appendChild(valueCell);
  row.appendChild(actionCell);
  return row;
}

/** Editor page: real class names and name attributes from §6.3–§6.4. */
function buildEditor(opts) {
  const o = Object.assign({ type: 'click', params: [], consent: 'statistics', platforms: [], configured: ['ga4', 'sgtm', 'meta'], search: '', ruleNew: false }, opts || {});
  const doc = new FakeDocument();
  const form = el(doc, 'form');
  doc.body.appendChild(form);
  const container = el(doc, 'div', { class: 'adc-ce', 'data-ce-view': 'editor', 'data-ce-rule-new': o.ruleNew ? '1' : '0' });
  form.appendChild(container);

  const errors = el(doc, 'div', { class: 'adc-ce-errors', role: 'alert' });
  errors.hidden = true;
  container.appendChild(errors);

  TRIGGER_TYPES.forEach((t) => {
    const radio = el(doc, 'input', { type: 'radio', name: 'rule[trigger][type]', value: t });
    radio.checked = t === o.type;
    container.appendChild(radio);
  });
  TRIGGER_TYPES.forEach((t) => {
    const fieldset = el(doc, 'fieldset', { class: 'adc-ce-trigger', 'data-ce-trigger': t });
    fieldset.hidden = t !== o.type;
    container.appendChild(fieldset);
  });
  const selectorField = el(doc, 'div', { 'data-ce-selector-field': '' });
  selectorField.hidden = o.type !== 'click';
  selectorField.appendChild(el(doc, 'input', { type: 'text', name: 'rule[trigger][selector]' }));
  container.appendChild(selectorField);

  const preset = el(doc, 'select', { name: 'rule[trigger][preset]' });
  preset.value = '';
  container.appendChild(preset);
  const formPreset = el(doc, 'select', { name: 'rule[trigger][form_preset]' });
  formPreset.value = 'cf7';
  container.appendChild(formPreset);
  container.appendChild(el(doc, 'input', { type: 'number', name: 'rule[trigger][seconds]', value: '' }));

  [['once', true], ['every', false]].forEach(([v, checked]) => {
    const radio = el(doc, 'input', { type: 'radio', name: 'rule[fire]', value: v });
    radio.checked = checked;
    container.appendChild(radio);
  });

  const paramsSection = el(doc, 'div', { class: 'adc-ce-params' });
  const table = el(doc, 'table');
  const tbody = el(doc, 'tbody');
  o.params.forEach((p, i) => tbody.appendChild(makeRow(doc, i, p.key, p.value)));
  table.appendChild(tbody);
  paramsSection.appendChild(table);
  paramsSection.appendChild(el(doc, 'button', { type: 'button', class: 'button adc-ce-add-param' }, 'Adaugă parametru'));
  container.appendChild(paramsSection);

  const tokens = el(doc, 'div', { class: 'adc-ce-tokens' });
  // Mirrors the token loop in render_editor(): every token is rendered, with its allowed trigger types.
  const TOKEN_TYPES = { '{{click_text}}': ['click'], '{{page_path}}': TRIGGER_TYPES, '{{scroll_percent}}': ['scroll_depth'] };
  Object.keys(TOKEN_TYPES).forEach((t) => {
    const button = el(doc, 'button', { type: 'button', 'data-ce-token': t, 'data-ce-token-types': TOKEN_TYPES[t].join(' ') }, t);
    button.hidden = TOKEN_TYPES[t].indexOf(o.type) === -1;
    tokens.appendChild(button);
  });
  container.appendChild(tokens);

  container.appendChild(el(doc, 'input', { name: 'rule[label]', type: 'text', value: 'Telefon header' }));
  container.appendChild(el(doc, 'input', { name: 'rule[name_suffix]', type: 'text', value: 'phone_header' }));

  ['statistics', 'marketing'].forEach((c) => {
    const radio = el(doc, 'input', { type: 'radio', name: 'rule[consent_category]', value: c });
    radio.checked = c === o.consent;
    container.appendChild(radio);
  });

  container.appendChild(el(doc, 'input', { type: 'checkbox', name: 'rule[destinations][server_mirror]', value: '1' }));
  ['ga4', 'sgtm', 'meta'].forEach((p) => {
    const label = el(doc, 'label');
    const input = el(doc, 'input', { type: 'checkbox', name: 'rule[destinations][platforms][]', value: p });
    input.checked = o.platforms.indexOf(p) !== -1;
    input.disabled = o.configured.indexOf(p) === -1;
    label.appendChild(input);
    container.appendChild(label);
    if (input.disabled) {
      container.appendChild(el(doc, 'span', { class: 'description' }, 'Integrarea nu este configurată.'));
    }
  });

  container.appendChild(el(doc, 'pre', { class: 'adc-ce-example' }));

  const root = { document: doc, location: { search: o.search }, confirmAnswer: true, confirmCalls: 0 };
  root.confirm = function () { root.confirmCalls++; return root.confirmAnswer; };
  return { doc, root, form, container, tbody, paramsSection, add: container.querySelector('.adc-ce-add-param') };
}

/** List page: rows with toggle / duplicate / delete buttons (§6.2). */
function buildList() {
  const doc = new FakeDocument();
  const container = el(doc, 'div', { class: 'adc-ce', 'data-ce-view': 'list' });
  doc.body.appendChild(container);
  const table = el(doc, 'table');
  const row = el(doc, 'tr', { class: 'adc-ce-row', 'data-ce-id': 'ce_00000001' });
  const cell = el(doc, 'td');
  cell.appendChild(el(doc, 'button', { type: 'submit', name: 'adc_ce_op', value: 'toggle-off:ce_00000001' }, 'Oprește'));
  cell.appendChild(el(doc, 'button', { type: 'submit', name: 'adc_ce_op', value: 'duplicate:ce_00000001' }, 'Duplică'));
  cell.appendChild(el(doc, 'button', { type: 'submit', name: 'adc_ce_op', value: 'delete:ce_00000001' }, 'Șterge'));
  row.appendChild(cell);
  table.appendChild(row);
  container.appendChild(table);
  const root = { document: doc, location: { search: '' }, confirmAnswer: false, confirmCalls: 0 };
  root.confirm = function () { root.confirmCalls++; return root.confirmAnswer; };
  return { doc, root, container, row };
}

function freshInit(page) {
  api.init(page.root);
  return page;
}

/* ---------- Harness ---------- */

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

/* ---------- Static checks ---------- */

check('static: no HTML-injection or eval sinks', () => {
  const src = fs.readFileSync(SCRIPT, 'utf8');
  const banned = /innerHTML|insertAdjacentHTML|outerHTML|document\.write|eval\(|new Function/;
  assert.strictEqual(banned.test(src), false, 'forbidden sink found: ' + (src.match(banned) || [''])[0]);
});

check('static: file stays within the 400-line budget (§6.3)', () => {
  const lines = fs.readFileSync(SCRIPT, 'utf8').split('\n').length;
  assert.ok(lines <= 400, 'admin-custom-events.js has ' + lines + ' lines');
});

/* ---------- Type switching ---------- */

check('setTriggerType shows only the fieldset of the chosen type and sets hidden', () => {
  const page = buildEditor({ type: 'click' });
  api.setTriggerType(page.container, 'form_success');
  const fieldset = (t) => page.container.querySelector(`[data-ce-trigger="${t}"]`);
  assert.strictEqual(fieldset('form_success').hidden, false);
  TRIGGER_TYPES.filter((t) => t !== 'form_success').forEach((t) => {
    assert.strictEqual(fieldset(t).hidden, true, t + ' must be hidden');
  });
  assert.strictEqual(page.container.querySelector('[data-ce-selector-field]').hidden, true, 'selector hidden for form_success');
  api.setTriggerType(page.container, 'visible');
  assert.strictEqual(page.container.querySelector('[data-ce-selector-field]').hidden, false, 'selector shown for visible');
  assert.strictEqual(api.setTriggerType(page.container, 'bogus'), false, 'unknown type must be refused');
});

check('changing the trigger radio in the DOM switches fieldsets via the change listener', () => {
  const page = freshInit(buildEditor({ type: 'click' }));
  const timerRadio = page.container.querySelector('input[name="rule[trigger][type]"][value="timer"]');
  timerRadio.checked = true;
  dispatch(timerRadio, 'change');
  assert.strictEqual(page.container.querySelector('[data-ce-trigger="timer"]').hidden, false);
  assert.strictEqual(page.container.querySelector('[data-ce-trigger="click"]').hidden, true);
});

check('frequency is disabled for visible, scroll_depth and timer, and forced to once', () => {
  const page = buildEditor({ type: 'click' });
  const fire = page.container.querySelectorAll('input[name="rule[fire]"]');
  api.setTriggerType(page.container, 'visible');
  fire.forEach((input) => assert.strictEqual(input.disabled, true, 'fire input must be disabled for visible'));
  assert.strictEqual(fire[0].checked, true, 'once must be selected for visible');
  api.setTriggerType(page.container, 'scroll_depth');
  assert.strictEqual(fire[0].disabled, true);
  api.setTriggerType(page.container, 'timer');
  assert.strictEqual(fire[1].disabled, true);
  api.setTriggerType(page.container, 'click');
  fire.forEach((input) => assert.strictEqual(input.disabled, false, 'fire inputs must be enabled for click'));
});

check('presets from ?preset= apply the trigger type and its fields on load', () => {
  const tel = freshInit(buildEditor({ type: 'form_success', search: '?preset=tel', ruleNew: true }));
  assert.strictEqual(tel.container.querySelector('input[name="rule[trigger][type]"][value="click"]').checked, true);
  assert.strictEqual(tel.container.querySelector('[name="rule[trigger][preset]"]').value, 'tel');
  const timer = freshInit(buildEditor({ search: '?preset=timer', ruleNew: true }));
  assert.strictEqual(timer.container.querySelector('[name="rule[trigger][seconds]"]').value, '30');
  assert.strictEqual(timer.container.querySelector('[data-ce-trigger="timer"]').hidden, false);
});

check('a preset from the URL is ignored when editing an existing rule', () => {
  const page = freshInit(buildEditor({ type: 'click', ruleNew: false, search: '?preset=timer' }));
  assert.strictEqual(page.container.querySelector('input[name="rule[trigger][type]"][value="click"]').checked, true, 'type must stay click');
  assert.strictEqual(page.container.querySelector('input[name="rule[trigger][type]"][value="timer"]').checked, false, 'timer must not be applied');
  assert.strictEqual(page.container.querySelector('[data-ce-trigger="timer"]').hidden, true, 'timer fields must stay hidden');
  assert.strictEqual(page.container.querySelector('[name="rule[trigger][seconds]"]').value, '', 'seconds must not be forced to 30');
});

check('changing the trigger type shows only the tokens allowed for it', () => {
  const page = freshInit(buildEditor({ type: 'click' }));
  const token = (t) => page.container.querySelector(`[data-ce-token="${t}"]`);
  assert.strictEqual(token('{{click_text}}').hidden, false, 'click_text must show for click');
  assert.strictEqual(token('{{scroll_percent}}').hidden, true, 'scroll_percent must hide for click');
  const scroll = page.container.querySelector('input[name="rule[trigger][type]"][value="scroll_depth"]');
  scroll.checked = true;
  dispatch(scroll, 'change');
  assert.strictEqual(token('{{click_text}}').hidden, true, 'click_text must hide for scroll_depth');
  assert.strictEqual(token('{{scroll_percent}}').hidden, false, 'scroll_percent must show for scroll_depth');
  assert.strictEqual(token('{{page_path}}').hidden, false, 'page_path is allowed for every type');
});

check('server-rendered parameter rows each carry a remove button', () => {
  const page = freshInit(buildEditor({ params: [{ key: 'a', value: '1' }, { key: 'b', value: '2' }] }));
  assert.strictEqual(page.tbody.querySelectorAll('.adc-ce-param').length, 2);
  assert.strictEqual(page.tbody.querySelectorAll('.adc-ce-remove-param').length, 2, 'every existing row needs a remove button');
});

/* ---------- Parameter rows ---------- */

check('reindexParams renumbers rule[params][i] after a middle row is removed', () => {
  const page = buildEditor({ params: [{ key: 'a', value: '1' }, { key: 'b', value: '2' }, { key: 'c', value: '3' }] });
  page.tbody.removeChild(page.tbody.children[1]);
  const count = api.reindexParams(page.tbody);
  assert.strictEqual(count, 2);
  const keys = page.tbody.querySelectorAll('input[name$="[key]"]').map((i) => i.getAttribute('name'));
  assert.deepStrictEqual(keys, ['rule[params][0][key]', 'rule[params][1][key]']);
  const values = page.tbody.querySelectorAll('input[name$="[value]"]').map((i) => i.getAttribute('name'));
  assert.deepStrictEqual(values, ['rule[params][0][value]', 'rule[params][1][value]']);
});

check('add button appends a row numbered after the last one', () => {
  const page = freshInit(buildEditor({ params: [{ key: 'a', value: '1' }, { key: 'b', value: '2' }] }));
  dispatch(page.add, 'click');
  const rows = page.tbody.querySelectorAll('.adc-ce-param');
  assert.strictEqual(rows.length, 3);
  assert.strictEqual(rows[2].querySelector('input[name$="[key]"]').getAttribute('name'), 'rule[params][2][key]');
  assert.strictEqual(rows[2].querySelector('input[name$="[value]"]').getAttribute('name'), 'rule[params][2][value]');
});

check('removing the first row via its button renumbers the rest from 0', () => {
  const page = freshInit(buildEditor({ params: [{ key: 'a', value: '1' }, { key: 'b', value: '2' }] }));
  const removeFirst = page.tbody.querySelectorAll('.adc-ce-remove-param')[0];
  dispatch(removeFirst, 'click');
  const rows = page.tbody.querySelectorAll('.adc-ce-param');
  assert.strictEqual(rows.length, 1);
  assert.strictEqual(rows[0].querySelector('input[name$="[key]"]').getAttribute('name'), 'rule[params][0][key]');
  assert.strictEqual(rows[0].querySelector('input[name$="[key]"]').value, 'b');
});

check('max 10 parameter rows: canAddParam, and the add button disables at the limit', () => {
  assert.strictEqual(api.canAddParam(9), true);
  assert.strictEqual(api.canAddParam(10), false);
  const page = freshInit(buildEditor({ params: Array.from({ length: 9 }, (_, i) => ({ key: 'k' + i, value: 'v' })) }));
  assert.strictEqual(page.add.disabled, false, 'add must stay enabled at 9 rows');
  dispatch(page.add, 'click');
  assert.strictEqual(page.tbody.querySelectorAll('.adc-ce-param').length, 10, 'the 10th row must be added');
  dispatch(page.add, 'click');
  assert.strictEqual(page.tbody.querySelectorAll('.adc-ce-param').length, 10, 'an 11th row must never be added');
  assert.strictEqual(page.add.disabled, true, 'add must be disabled once the cap is hit');
  const full = freshInit(buildEditor({ params: Array.from({ length: 10 }, (_, i) => ({ key: 'k' + i, value: 'v' })) }));
  assert.strictEqual(full.add.disabled, true, 'add must start disabled with 10 rows');
});

/* ---------- Token insertion ---------- */

check('token button inserts at the caret of the focused parameter field', () => {
  const page = freshInit(buildEditor({ params: [{ key: 'pagina', value: 'ab' }] }));
  const valueInput = page.tbody.querySelector('input[name$="[value]"]');
  valueInput.value = 'ab';
  valueInput.selectionStart = 1;
  valueInput.selectionEnd = 1;
  dispatch(valueInput, 'focusin');
  const token = page.container.querySelector('[data-ce-token="{{page_path}}"]');
  dispatch(token, 'click');
  assert.strictEqual(valueInput.value, 'a{{page_path}}b');
  assert.strictEqual(valueInput.selectionStart, 1 + '{{page_path}}'.length, 'caret must sit after the token');
});

/* ---------- Selector ---------- */

check('setSelector fills the selector field and moves focus to it', () => {
  const page = buildEditor({ type: 'click' });
  const field = page.doc.querySelector('[name="rule[trigger][selector]"]');
  assert.strictEqual(api.setSelector(page.root, '.buy-now'), true);
  assert.strictEqual(field.value, '.buy-now');
  assert.strictEqual(page.doc.activeElement, field, 'focus must move to the selector field');
  assert.strictEqual(field.focusCount, 1);
  api.setSelector(page.root, null);
  assert.strictEqual(field.value, '', 'null must clear the field');
});

check('window.adcCustomEventsAdmin.setSelector is exposed in the browser build', () => {
  const page = buildEditor({ type: 'click' });
  global.window = page.root;
  try {
    delete require.cache[require.resolve(SCRIPT)];
    require(SCRIPT);
    assert.strictEqual(typeof page.root.adcCustomEventsAdmin.setSelector, 'function');
    page.root.adcCustomEventsAdmin.setSelector('#hero a');
    assert.strictEqual(page.doc.querySelector('[name="rule[trigger][selector]"]').value, '#hero a');
  } finally {
    delete global.window;
    delete require.cache[require.resolve(SCRIPT)];
  }
});

/* ---------- Destinations and example ---------- */

check('Meta is blocked for the statistics category and enabled for marketing', () => {
  const page = freshInit(buildEditor({ consent: 'statistics' }));
  const meta = page.container.querySelector('input[name="rule[destinations][platforms][]"][value="meta"]');
  assert.strictEqual(meta.disabled, true, 'meta must be disabled under statistics');
  const statistics = page.container.querySelector('input[name="rule[consent_category]"][value="statistics"]');
  const marketing = page.container.querySelector('input[name="rule[consent_category]"][value="marketing"]');
  statistics.checked = false; // fake DOM does not enforce radio-group exclusivity
  marketing.checked = true;
  dispatch(marketing, 'change');
  assert.strictEqual(meta.disabled, false, 'meta must be enabled under marketing');
});

check('a routed platform forces the Server checkbox on and disables it', () => {
  const page = freshInit(buildEditor({ platforms: ['ga4'] }));
  const server = page.container.querySelector('input[name="rule[destinations][server_mirror]"]');
  assert.strictEqual(server.checked, true, 'server must be checked when a platform is routed');
  assert.strictEqual(server.disabled, true, 'server must be disabled when a platform is routed');
});

check('unconfigured platforms stay disabled', () => {
  const page = freshInit(buildEditor({ configured: ['ga4'], consent: 'marketing' }));
  const sgtm = page.container.querySelector('input[name="rule[destinations][platforms][]"][value="sgtm"]');
  assert.strictEqual(sgtm.disabled, true, 'sgtm must stay disabled when not configured');
});

check('example pre shows the event name built from the name suffix', () => {
  const page = freshInit(buildEditor({ params: [{ key: 'pagina', value: '/' }] }));
  const example = page.container.querySelector('.adc-ce-example');
  assert.ok(example.textContent.indexOf('"custom_phone_header"') !== -1, example.textContent);
  assert.ok(example.textContent.indexOf('"pagina": "/"') !== -1, example.textContent);
});

/* ---------- List page and confirmation ---------- */

check('delete asks for confirmation and cancels when declined', () => {
  const page = buildList();
  api.init(page.root);
  const del = page.container.querySelector('button[value^="delete:"]');
  const event = dispatch(del, 'click');
  assert.strictEqual(page.root.confirmCalls, 1, 'delete must call confirm()');
  assert.strictEqual(event.defaultPrevented, true, 'declined delete must be cancelled');
});

check('toggle and duplicate do not ask for confirmation', () => {
  const page = buildList();
  api.init(page.root);
  dispatch(page.container.querySelector('button[value^="toggle-off:"]'), 'click');
  dispatch(page.container.querySelector('button[value^="duplicate:"]'), 'click');
  assert.strictEqual(page.root.confirmCalls, 0);
});

check('submitting with an empty label is blocked and shows the Romanian error', () => {
  const page = freshInit(buildEditor());
  page.container.querySelector('[name="rule[label]"]').value = '   ';
  const event = dispatch(page.form, 'submit');
  assert.strictEqual(event.defaultPrevented, true, 'empty label must block submit');
  const summary = page.container.querySelector('.adc-ce-errors');
  assert.strictEqual(summary.hidden, false, 'error summary must be shown');
  assert.strictEqual(summary.textContent.indexOf('Denumirea internă este obligatorie.') !== -1, true);
  assert.strictEqual(summary.focusCount, 1, 'focus must move to the error summary');
  assert.strictEqual(page.container.querySelector('[name="rule[label]"]').getAttribute('aria-invalid'), 'true');
});

console.log('admin-custom-events JS tests passed (' + passed + ' checks).');
