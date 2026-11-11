#!/usr/bin/env node
/**
 * Offline behavioural test for assets/js/admin-picker.js (visual selector, admin side).
 *
 * The script runs inside a vm context with a minimal fake DOM, a fake fetch
 * (AJAX token/revoke), a controlled timer clock and a fake frame window. The
 * frame side is simulated with the real picker-protocol.js envelopes.
 * Exit code 0 = all checks passed; 1 = at least one failure (listed).
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '..');
const SCRIPT_PATH = path.join(ROOT, 'assets/js/admin-picker.js');
const SRC = fs.readFileSync(SCRIPT_PATH, 'utf8');
const PROTOCOL_PATH = path.join(ROOT, 'assets/js/picker-protocol.js');

const HOME = 'https://wordpress.test';
const AJAX = 'https://wordpress.test/wp-admin/admin-ajax.php';
const NONCE = 'nonce-fixture';
const TOKEN = '0123456789abcdef0123456789abcdef'; // 32 lowercase hex, as class-adc-picker.php issues
const CH = '0102030405060708090a0b0c0d0e0f10';    // getRandomValues fixture: bytes 1..16 -> hex

// Distinct markers so assertions prove which string was rendered.
const L10N = {
    loading: 'LOADING', timeout: 'TIMEOUT', retry: 'RETRY', openPopup: 'POPUP', invalidPath: 'INVALID_PATH',
    invalid: 'INVALID', testing: 'TESTING', matchCount: '%s potriviri', zeroMatches: 'ZERO', notPicked: 'NOT_PICKED',
    testTimeout: 'TEST_TIMEOUT', noSelection: 'NOSEL', underCursor: 'Sub cursor', freeze: 'FREEZE',
    unfreeze: 'UNFREEZE', freezing: 'Îngheț în %s s', desktop: 'Desktop', tablet: 'Tabletă', mobile: 'Mobil',
    thisOnly: 'THIS', allSimilar: 'SIMILAR', similarWarning: 'SIMILAR_WARN', matches: '%s potriviri',
    strategy_id: 'după id', strategy_class: 'după clasă', idLabel: 'ID', classLabel: 'Clase',
    title: 'TITLE', close: 'CLOSE', useSelector: 'USE', clearSelection: 'CLEAR', test: 'TEST',
    load: 'LOAD', pathLabel: 'PATH', modeLabel: 'MODE', modeSelect: 'SEL', modeNavigate: 'NAV',
    editSelector: 'EDIT', candidates: 'CANDS', rateLimit: 'RATE', tokenError: 'TOKEN_ERR', renewError: 'RENEW_ERR',
    popupBlocked: 'POPUP_BLOCKED', blocked: 'BLOCKED', errorInternal: 'ERR_INTERNAL', confirmClose: 'CONFIRM?',
};

const flush = () => new Promise((resolve) => setImmediate(resolve));

/** Fake DOM ----------------------------------------------------------------- */

function parseSimple(selector) {
    const m = /^([a-zA-Z][\w-]*)?((?:\.[\w-]+)*)((?:\[[\w-]+(?:="[^"]*")?\])*)$/.exec(selector.trim());
    if (!m) { throw new Error('unsupported selector: ' + selector); }
    const tag = m[1] ? m[1].toLowerCase() : null;
    const classes = m[2] ? m[2].split('.').slice(1) : [];
    const attrs = [];
    const re = /\[([\w-]+)(?:="([^"]*)")?\]/g;
    let a;
    while ((a = re.exec(m[3]))) { attrs.push([a[1], a[2]]); }
    return (node) => {
        if (tag && node.tagName.toLowerCase() !== tag) { return false; }
        if (!classes.every((c) => node.className.split(/\s+/).includes(c))) { return false; }
        return attrs.every(([k, v]) => (v === undefined ? node.attrs[k] !== undefined : node.attrs[k] === v));
    };
}

class FakeNode {
    constructor(tag, doc) {
        this.tagName = tag.toUpperCase();
        this.doc = doc;
        this.children = [];
        this.parentNode = null;
        this.attrs = {};
        this.listeners = {};
        this._text = '';
        this.className = '';
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this.checked = false;
        this.id = '';
        this.type = '';
        this.name = '';
        this.open = false;
        this.style = {};
        if (tag === 'dialog') {
            this.showModal = () => { this.open = true; };
            this.close = () => { this.open = false; };
        }
        if (tag === 'iframe') {
            this.contentWindow = doc.env.frameWin;
            let src = '';
            Object.defineProperty(this, 'src', {
                enumerable: true,
                get: () => src,
                set: (v) => { src = String(v); doc.env.srcs.push(src); },
            });
        }
    }
    get tagNameLower() { return this.tagName.toLowerCase(); }
    get firstChild() { return this.children[0] || null; }
    get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
    set textContent(value) {
        this.children.forEach((c) => { c.parentNode = null; });
        this.children = [];
        this._text = value === null || value === undefined ? '' : String(value);
    }
    get classList() { return { contains: (c) => this.className.split(/\s+/).includes(c) }; }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
    setAttribute(name, value) { this.attrs[name] = String(value); }
    appendChild(child) {
        if (child.parentNode) { child.parentNode.removeChild(child); }
        child.parentNode = this;
        this.children.push(child);
        return child;
    }
    removeChild(child) {
        const i = this.children.indexOf(child);
        if (i >= 0) { this.children.splice(i, 1); child.parentNode = null; }
        return child;
    }
    remove() { if (this.parentNode) { this.parentNode.removeChild(this); } }
    focus() {}
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn); }
    dispatch(type, extra) {
        const event = Object.assign({ type, preventDefault() {}, stopPropagation() {}, stopImmediatePropagation() {} }, extra || {});
        (this.listeners[type] || []).slice().forEach((fn) => fn(event));
    }
    click() { this.dispatch('click'); }
    querySelectorAll(selector) {
        const preds = selector.split(',').map(parseSimple);
        const out = [];
        const walk = (node) => node.children.forEach((c) => {
            if (preds.some((p) => p(c))) { out.push(c); }
            walk(c);
        });
        walk(this);
        return out;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

class FakeFormData {
    constructor() { this.entries = []; }
    append(key, value) { this.entries.push([key, String(value)]); }
}

/** Environment --------------------------------------------------------------- */

function makeWindow(env, name) {
    return { name, postMessage(message, targetOrigin) { env.posts.push({ to: name, msg: message, origin: targetOrigin }); } };
}

function makeEnv(overrides) {
    const env = {
        posts: [], srcs: [], requests: [], timers: [], now: 0, nextId: 1, frameSeq: 0,
        token: TOKEN, confirmAnswer: true, listeners: {},
        opened: [], popupResult: null, tokenReply: null,
    };
    const doc = { env, activeElement: null };
    doc.createElement = (tag) => new FakeNode(tag, doc);
    doc.html = new FakeNode('html', doc);
    doc.body = new FakeNode('body', doc);
    doc.html.appendChild(doc.body);
    doc.querySelector = (s) => doc.html.querySelector(s);
    doc.querySelectorAll = (s) => doc.html.querySelectorAll(s);

    const container = new FakeNode('div', doc);
    container.className = 'adc-ce';
    container.setAttribute('data-ce-view', 'editor');
    container.setAttribute('data-ce-ajax', AJAX);
    container.setAttribute('data-ce-nonce', NONCE);
    container.setAttribute('data-home-origin', HOME);
    container.setAttribute('data-picker-available', '1');
    container.setAttribute('data-picker-home-path', '/shop/');
    const trigger = new FakeNode('button', doc);
    trigger.className = 'button adc-ce-picker';
    trigger.hidden = true;
    container.appendChild(trigger);
    doc.body.appendChild(container);

    env.doc = doc;
    env.container = container;
    env.trigger = trigger;
    env.frameWin = makeWindow(env, 'frame');
    env.otherWin = makeWindow(env, 'other');

    const win = {
        document: doc,
        location: { origin: HOME, pathname: '/shop/', search: '' },
        adcPickerL10n: Object.assign({}, L10N, overrides && overrides.l10n),
        FormData: FakeFormData,
        URL,
        confirm: () => env.confirmAnswer,
        crypto: { getRandomValues(bytes) { for (let i = 0; i < bytes.length; i++) { bytes[i] = i + 1; } return bytes; } },
        setTimeout(fn, ms) { const id = env.nextId++; env.timers.push({ id, at: env.now + (ms || 0), fn }); return id; },
        clearTimeout(id) { env.timers = env.timers.filter((t) => t.id !== id); },
        setInterval(fn, ms) { const id = env.nextId++; env.timers.push({ id, at: env.now + (ms || 0), fn, every: ms }); return id; },
        clearInterval(id) { env.timers = env.timers.filter((t) => t.id !== id); },
        open(url, name, features) { env.opened.push({ url, name, features }); return env.popupResult; },
        addEventListener(type, fn) { (env.listeners[type] = env.listeners[type] || []).push(fn); },
        removeEventListener(type, fn) { env.listeners[type] = (env.listeners[type] || []).filter((f) => f !== fn); },
        fetch(url, opts) {
            const data = Object.fromEntries(opts.body.entries);
            env.requests.push({ url, method: opts.method, data });
            if (data.action === 'adc_picker_token' && env.tokenReply) {
                return Promise.resolve({ status: env.tokenReply.status, json: () => Promise.resolve(env.tokenReply.body) });
            }
            const body = data.action === 'adc_picker_token'
                ? { success: true, data: { token: env.token, expires_in: 600 } }
                : { success: true, data: { revoked: true } };
            return Promise.resolve({ status: 200, json: () => Promise.resolve(body) });
        },
    };
    win.window = win;
    env.win = win;
    env.ctx = vm.createContext(win);
    // Load the protocol into the same realm as the admin script: picker-protocol.js
    // checks plain objects by prototype, so cross-realm envelopes would be rejected.
    vm.runInContext(fs.readFileSync(PROTOCOL_PATH, 'utf8'), env.ctx, { filename: PROTOCOL_PATH });
    env.protocol = win.adcPickerProtocol;

    env.advance = (ms) => {
        const end = env.now + ms;
        for (;;) {
            const due = env.timers.filter((t) => t.at <= end).sort((a, b) => a.at - b.at || a.id - b.id)[0];
            if (!due) { break; }
            env.timers = env.timers.filter((t) => t !== due);
            if (due.every) { env.timers.push(Object.assign({}, due, { at: due.at + due.every })); }
            env.now = due.at;
            due.fn();
        }
        env.now = end;
    };

    /** Deliver a message event to the admin window's message listeners. */
    env.deliver = (data, origin, source) => {
        (env.listeners.message || []).slice().forEach((fn) => fn({ data, origin, source }));
    };

    /** Build a valid frame->admin envelope (validated against the real protocol) and deliver it. */
    env.frameSend = (type, payload, opts) => {
        const o = opts || {};
        const isHello = type === 'hello';
        env.frameSeq += 1;
        // Clone the payload inside the context realm (see the protocol note in makeEnv).
        const contextPayload = vm.runInContext('JSON', env.ctx).parse(JSON.stringify(payload));
        const envelope = env.protocol.make(type, isHello ? '' : CH, env.frameSeq, contextPayload);
        const check = env.protocol.validate(envelope, env.protocol.DIR_TO_ADMIN, isHello ? {} : { ch: CH, seq: env.frameSeq - 1 });
        if (!check.ok) { throw new Error('fixture invalid: ' + type + ' ' + check.reason); }
        env.deliver(envelope, o.origin || HOME, o.source || env.frameWin);
    };

    env.q = (selector) => doc.querySelector(selector);
    env.qa = (selector) => doc.querySelectorAll(selector);
    env.frameMsgs = () => env.posts.filter((p) => p.to === 'frame');
    env.lastFrameMsg = () => env.frameMsgs().slice(-1)[0].msg;
    env.dialog = () => doc.querySelector('dialog.adc-pk-dialog');
    env.loadScript = () => vm.runInContext(SRC, env.ctx, { filename: SCRIPT_PATH });
    return env;
}

/** Common flows --------------------------------------------------------------- */

const SUMMARY = { tag: 'a', id: 'buy', classes: ['btn', 'wide'], role: 'link', text: 'Cumpără', rect: { x: 1, y: 2, w: 30, h: 40 } };
const PICK = {
    summary: SUMMARY,
    candidates: [
        { selector: '#buy', score: 90, count: 1, strategy: 'id', scope: 'unique' },
        { selector: 'a.btn', score: 50, count: 3, strategy: 'class', scope: 'similar' },
    ],
    notice: '',
};

async function openViaTrigger(env) {
    env.trigger.click();
    await flush();
}

async function openDirect(env, options) {
    env.win.adcPicker.open(options || {});
    await flush();
}

async function handshakeAndReady(env) {
    env.frameSend('hello', { href_path: '/shop/' });
    env.frameSend('ready', { href_path: '/shop/', title_len: 5 });
}

function radioFor(env, text) {
    return env.qa('input.adc-pk-candidate-radio').find((r) => r.parentNode.textContent.includes(text));
}

function noWildcardPost(env) {
    for (const p of env.posts) {
        assert.equal(p.origin, HOME, 'postMessage must target homeOrigin, never "*"');
    }
}

/** Tests ---------------------------------------------------------------------- */

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

test('open() cere token prin POST și deschide iframe-ul cu adc_picker și adc_ts', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    const req = env.requests[0];
    assert.ok(req, 'token request expected');
    assert.equal(req.method, 'POST');
    assert.equal(req.url, AJAX);
    assert.equal(req.data.action, 'adc_picker_token');
    assert.equal(req.data.nonce, NONCE);
    assert.ok(env.dialog() && env.dialog().open, 'dialog must be open');
    const src = new URL(env.srcs.at(-1));
    assert.equal(src.origin, HOME, 'iframe origin must equal homeOrigin');
    assert.equal(src.pathname, '/shop/');
    assert.equal(src.searchParams.get('adc_picker'), TOKEN);
    assert.match(src.searchParams.get('adc_ts'), /^\d+$/);
    noWildcardPost(env);
});

test('cale invalidă sau cu alt origin este respinsă (open și „Încărcare”)', async () => {
    const env = makeEnv();
    env.loadScript();
    await openDirect(env, { path: '//evil.example/' });
    assert.equal(env.requests.length, 0, 'no token for invalid path');
    assert.equal(env.dialog(), null, 'no dialog for invalid path');
    await openDirect(env, { path: 'https://evil.example/' });
    assert.equal(env.requests.length, 0, 'absolute URL path must be refused');

    const env2 = makeEnv();
    env2.loadScript();
    await openViaTrigger(env2);
    const before = env2.srcs.length;
    env2.q('.adc-pk-path').value = '//evil.example/';
    env2.q('.adc-pk-load').click();
    assert.equal(env2.srcs.length, before, 'iframe must not navigate to a foreign origin');
    assert.equal(env2.q('.adc-pk-status').textContent, L10N.invalidPath);
});

test('handshake: hello cu origin/source corecte → init cu același ch; greșite ignorate', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    assert.equal(env.frameMsgs().length, 0, 'nothing sent before hello');
    env.frameSend('hello', { href_path: '/shop/' }, { origin: 'https://evil.example' });
    env.frameSend('hello', { href_path: '/shop/' }, { source: env.otherWin });
    assert.equal(env.frameMsgs().length, 0, 'wrong origin/source hello must be ignored');
    env.frameSend('hello', { href_path: '/shop/' });
    const init = env.frameMsgs();
    assert.equal(init.length, 1, 'exactly one init after a valid hello');
    assert.equal(init[0].msg.type, 'init');
    assert.equal(init[0].msg.ch, CH);
    assert.equal(init[0].msg.payload.ch, CH);
    assert.equal(init[0].msg.payload.mode, 'select');
    assert.equal(init[0].msg.payload.viewport, 'desktop');
    assert.equal(init[0].origin, HOME);
    noWildcardPost(env);
});

test('ready: bara devine activă, calea se actualizează, timeout oprit; ready greșit ignorat', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    env.frameSend('hello', { href_path: '/shop/' });
    env.frameSend('ready', { href_path: '/shop/produs/', title_len: 5 }, { origin: 'https://evil.example' });
    assert.equal(env.q('.adc-pk-freeze').disabled, true, 'wrong-origin ready must not enable the bar');
    env.frameSend('ready', { href_path: '/shop/produs/', title_len: 5 });
    assert.equal(env.q('.adc-pk-freeze').disabled, false, 'bar active after ready');
    assert.equal(env.q('.adc-pk-path').value, '/shop/produs/', 'path updated from ready.href_path');
    env.advance(8000);
    assert.equal(env.q('.adc-pk-timeout').hidden, true, 'timeout must be cleared by ready');
    noWildcardPost(env);
});

test('pick → candidați în textContent; alegerea unui candidat trimite highlight', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    env.frameSend('pick', PICK);
    const cands = env.q('.adc-pk-candidates').textContent;
    assert.ok(cands.includes('#buy'), 'candidate #buy rendered');
    assert.ok(cands.includes('a.btn'), 'candidate a.btn rendered');
    assert.ok(cands.includes(L10N.similarWarning), 'similar candidate shows warning');
    assert.ok(env.q('.adc-pk-summary').textContent.includes('<a>'), 'summary tag rendered');
    assert.ok(env.q('.adc-pk-summary').textContent.includes('buy'), 'summary id rendered');
    const radio = radioFor(env, 'a.btn');
    assert.ok(radio, 'radio for a.btn exists');
    radio.checked = true;
    radio.dispatch('change');
    const last = env.lastFrameMsg();
    assert.equal(last.type, 'highlight');
    assert.equal(last.payload.selector, 'a.btn');
    assert.equal(env.posts.at(-1).origin, HOME);
    noWildcardPost(env);
});

test('test_selector cu req; test_result interpretat; req vechi ignorat; invalid → clear', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    env.frameSend('pick', PICK);
    env.q('.adc-pk-selector-input').value = 'a.btn';
    env.q('.adc-pk-test').click();
    const sent = env.lastFrameMsg();
    assert.equal(sent.type, 'test_selector');
    assert.equal(sent.payload.selector, 'a.btn');
    assert.equal(typeof sent.payload.req, 'number');
    env.frameSend('test_result', { req: sent.payload.req, valid: true, count: 3, matches_picked: true });
    assert.equal(env.lastFrameMsg().type, 'highlight', 'valid match triggers highlight');
    assert.equal(env.q('.adc-pk-test-result').textContent, '3 potriviri');
    env.frameSend('test_result', { req: sent.payload.req + 50, valid: true, count: 0, matches_picked: false });
    assert.equal(env.q('.adc-pk-test-result').textContent, '3 potriviri', 'stale req must be ignored');
    env.q('.adc-pk-test').click();
    const second = env.lastFrameMsg();
    env.frameSend('test_result', { req: second.payload.req, valid: false, count: 0, matches_picked: false });
    assert.equal(env.lastFrameMsg().type, 'clear', 'invalid selector sends clear');
    assert.equal(env.q('.adc-pk-test-result').textContent, L10N.invalid);
    noWildcardPost(env);
});

test('„Șterge selecția” trimite clear și golește panoul', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    env.frameSend('pick', PICK);
    env.q('.adc-pk-clear').click();
    assert.equal(env.lastFrameMsg().type, 'clear');
    assert.equal(JSON.stringify(env.lastFrameMsg().payload), '{}', 'clear carries an empty payload');
    assert.equal(env.q('.adc-pk-summary').textContent, L10N.noSelection);
    assert.equal(env.qa('input.adc-pk-candidate-radio').length, 0, 'candidates cleared');
});

test('„Folosește” apelează onPick o singură dată cu selectorul, închide și revocă tokenul', async () => {
    const env = makeEnv();
    env.loadScript();
    const picks = [];
    await openDirect(env, { onPick: (s) => picks.push(s) });
    await handshakeAndReady(env);
    env.frameSend('pick', PICK);
    radioFor(env, 'a.btn').checked = true;
    radioFor(env, 'a.btn').dispatch('change');
    const before = env.posts.length;
    const useButton = env.q('.adc-pk-use');
    useButton.click();
    useButton.click();
    assert.equal(picks.length, 1, 'onPick called exactly once');
    assert.equal(picks[0], 'a.btn', 'onPick receives the chosen selector');
    assert.equal(env.dialog(), null, 'dialog removed');
    const revoke = env.requests.find((r) => r.data.action === 'adc_picker_revoke');
    assert.ok(revoke, 'revoke request sent');
    assert.equal(revoke.method, 'POST');
    assert.equal(revoke.data.token, TOKEN);
    assert.equal(revoke.data.nonce, NONCE);
    assert.equal(env.posts.length, before, 'no protocol message on close');
    noWildcardPost(env);
});

test('„Închide” revocă tokenul, nu trimite mesaj de protocol și scoate listenerul', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    const before = env.posts.length;
    env.q('.adc-pk-close').click();
    assert.equal(env.dialog(), null, 'dialog removed');
    assert.ok(env.requests.some((r) => r.data.action === 'adc_picker_revoke' && r.data.token === TOKEN), 'token revoked');
    env.frameSend('hello', { href_path: '/shop/' });
    assert.equal(env.posts.length, before, 'no message after close, listener removed');
});

test('Esc în dialog închide; cu pick nefolosit cere confirmare', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    env.frameSend('pick', PICK);
    env.confirmAnswer = false;
    env.dialog().dispatch('keydown', { key: 'Escape' });
    assert.ok(env.dialog(), 'Esc with declined confirm keeps the dialog');
    env.confirmAnswer = true;
    env.dialog().dispatch('keydown', { key: 'Escape' });
    assert.equal(env.dialog(), null, 'Esc with confirm closes the dialog');
});

test('timeout 8 s fără ready → mesaj + Reîncearcă (iframe reîncărcat, timer repornit)', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    const srcCount = env.srcs.length;
    env.advance(7999);
    assert.equal(env.q('.adc-pk-timeout').hidden, true, 'no timeout before 8 s');
    env.advance(1);
    assert.equal(env.q('.adc-pk-timeout').hidden, false, 'timeout shown at 8 s');
    assert.equal(env.q('.adc-pk-status').textContent, L10N.timeout);
    assert.ok(env.q('.adc-pk-retry'), 'retry button present');
    env.q('.adc-pk-retry').click();
    assert.equal(env.srcs.length, srcCount + 1, 'retry reloads the iframe');
    assert.match(env.srcs.at(-1), /adc_picker=/);
    assert.equal(env.q('.adc-pk-timeout').hidden, true, 'retry hides the timeout box until the timer fires');
    env.advance(8000);
    assert.equal(env.q('.adc-pk-timeout').hidden, false, 'timer restarted by retry');
});

test('reînnoire token la 540 s: a doua cerere de token', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    env.advance(540000);
    await flush();
    const tokenRequests = env.requests.filter((r) => r.data.action === 'adc_picker_token');
    assert.equal(tokenRequests.length, 2, 'token renewed after 540 s');
});

test('hover → „Sub cursor”; mode_changed frozen → „Dezgheață” și viewport dezactivat', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    env.frameSend('hover', { summary: SUMMARY });
    assert.ok(env.q('.adc-pk-hover').textContent.includes('a#buy.btn.wide'), 'hover shows tag#id.class');
    env.frameSend('mode_changed', { mode: 'frozen' });
    assert.equal(env.q('.adc-pk-freeze').textContent, L10N.unfreeze);
    for (const b of env.qa('.adc-pk-viewport')) { assert.equal(b.disabled, true, 'viewport disabled while frozen'); }
});

test('Viewport: doar lățimea iframe-ului, fără mesaj și fără reîncărcare', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    const msgs = env.posts.length;
    const srcs = env.srcs.length;
    const tablet = env.qa('.adc-pk-viewport').find((b) => b.textContent === L10N.tablet);
    tablet.click();
    assert.equal(env.q('.adc-pk-iframe').style.width, '768px');
    assert.equal(env.posts.length, msgs, 'no protocol message for viewport change');
    assert.equal(env.srcs.length, srcs, 'no reload for viewport change');
});

test('static: fără innerHTML / insertAdjacentHTML / outerHTML / document.write / eval / new Function; fără postMessage cu "*"', () => {
    const forbidden = /innerHTML|insertAdjacentHTML|outerHTML|document\.write|eval\(|new Function/;
    const match = SRC.match(forbidden);
    assert.equal(match, null, 'forbidden API in admin-picker.js: ' + (match && match[0]));
    assert.doesNotMatch(SRC, /['"]\*['"]/, 'postMessage target origin must not be "*"');
});

/** Runner --------------------------------------------------------------------- */

const NEW_TOKEN = 'fedcba9876543210fedcba9876543210';

test('fereastră nouă: window.open întoarce null → mesajul „pop-up blocat”, fără pop-up și fără iframe', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    env.advance(8000);
    assert.equal(env.q('.adc-pk-timeout').hidden, false, 'timeout box shown before popup fallback');
    env.q('.adc-pk-open-popup').click();
    assert.equal(env.opened.length, 1, 'window.open called once');
    assert.equal(env.opened[0].name, 'adc-picker', 'popup window name');
    const popupUrl = new URL(env.opened[0].url);
    assert.equal(popupUrl.origin, HOME, 'popup URL on homeOrigin');
    assert.equal(popupUrl.searchParams.get('adc_picker'), TOKEN, 'popup URL carries the token');
    assert.equal(env.q('.adc-pk-status').textContent, L10N.popupBlocked, 'blocked-popup message shown');
    assert.ok(env.q('.adc-pk-iframe'), 'iframe kept when the popup was blocked');
    noWildcardPost(env);
});

test('fereastră nouă: popup deschis → iframe eliminat; popup.closed la 1 s → închidere + revocare, fără onPick', async () => {
    const env = makeEnv();
    env.loadScript();
    const picks = [];
    const popup = { closed: false, location: '', close() { this.closed = true; } };
    env.popupResult = popup;
    await openDirect(env, { onPick: (s) => picks.push(s) });
    env.advance(8000);
    env.q('.adc-pk-open-popup').click();
    assert.equal(env.opened.length, 1, 'window.open called');
    assert.equal(env.q('.adc-pk-iframe'), null, 'iframe removed once the popup is in use');
    assert.ok(env.dialog() && env.dialog().open, 'dialog stays open while the popup is open');

    env.advance(1000);
    assert.ok(env.dialog(), 'popup still open at 1 s: dialog kept');
    assert.equal(env.requests.filter((r) => r.data.action === 'adc_picker_revoke').length, 0, 'no revoke while popup is open');

    popup.closed = true;
    env.advance(1000);
    assert.equal(env.dialog(), null, 'closing the popup closes the selector');
    const revokes = env.requests.filter((r) => r.data.action === 'adc_picker_revoke');
    assert.equal(revokes.length, 1, 'token revoked exactly once');
    assert.equal(revokes[0].data.token, TOKEN, 'the open token is the one revoked');
    assert.deepEqual(picks, [], 'no selector delivered through onPick');
    noWildcardPost(env);
});

test('răspuns 429 la token → mesajul rateLimit (nu tokenError), fără încărcare a iframe-ului', async () => {
    const env = makeEnv();
    env.tokenReply = { status: 429, body: { success: false, data: { message: 'rate' } } };
    env.loadScript();
    await openViaTrigger(env);
    assert.equal(env.q('.adc-pk-status').textContent, L10N.rateLimit, 'rateLimit message');
    assert.notEqual(env.q('.adc-pk-status').textContent, L10N.tokenError, 'not the generic token error');
    assert.equal(env.srcs.length, 0, 'iframe not loaded without a token');
});

test('eroare token (403 sau răspuns fără token valid) → mesajul tokenError', async () => {
    const replies = [
        { status: 403, body: { success: false, data: { message: 'x' } } },
        { status: 200, body: { success: true, data: { token: 'XYZ' } } },
    ];
    for (const reply of replies) {
        const env = makeEnv();
        env.tokenReply = reply;
        env.loadScript();
        await openViaTrigger(env);
        assert.equal(env.q('.adc-pk-status').textContent, L10N.tokenError, 'tokenError for status ' + reply.status);
        assert.equal(env.srcs.length, 0, 'iframe not loaded without a valid token');
    }
});

test('reînnoire la 540 s: token nou în iframe, același ch, tokenul vechi revocat abia după ready', async () => {
    const env = makeEnv();
    env.loadScript();
    await openViaTrigger(env);
    await handshakeAndReady(env);
    const firstInit = env.frameMsgs().find((m) => m.msg.type === 'init');
    assert.equal(firstInit.msg.ch, CH, 'initial channel');

    env.token = NEW_TOKEN;
    env.advance(540000);
    await flush();
    const renewedSrc = new URL(env.srcs.at(-1));
    assert.equal(renewedSrc.searchParams.get('adc_picker'), NEW_TOKEN, 'iframe reloaded with the new token');
    assert.equal(env.requests.filter((r) => r.data.action === 'adc_picker_token').length, 2, 'second token request');
    assert.equal(env.requests.filter((r) => r.data.action === 'adc_picker_revoke').length, 0, 'old token not revoked before the new frame is ready');

    env.frameSend('hello', { href_path: '/shop/' });
    const reinit = env.frameMsgs().slice(-1)[0].msg;
    assert.equal(reinit.type, 'init', 'new frame gets init');
    assert.equal(reinit.payload.ch, CH, 'same channel after renewal');
    assert.equal(env.q('.adc-pk-timeout').hidden, true, 'no timeout during renewal');

    env.frameSend('ready', { href_path: '/shop/', title_len: 5 });
    const revokes = env.requests.filter((r) => r.data.action === 'adc_picker_revoke');
    assert.equal(revokes.length, 1, 'old token revoked after ready');
    assert.equal(revokes[0].data.token, TOKEN, 'the superseded token is the one revoked');
    assert.equal(env.q('.adc-pk-freeze').disabled, false, 'toolbar active after renewed ready');
    noWildcardPost(env);
});

(async () => {
    const failures = [];
    for (const t of tests) {
        try {
            await t.fn();
            console.log('ok   - ' + t.name);
        } catch (error) {
            failures.push({ name: t.name, error });
            console.log('FAIL - ' + t.name);
            console.log('       ' + String(error && error.message).split('\n').join('\n       '));
        }
    }
    if (failures.length) {
        console.log('\n' + failures.length + ' of ' + tests.length + ' tests failed in admin-picker-test.js');
        process.exitCode = 1;
        return;
    }
    console.log('\nadmin-picker-test: OK (' + tests.length + ' tests)');
})();
