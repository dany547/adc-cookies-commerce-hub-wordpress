'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/consent-manager.js'), 'utf8');
const uuid = '123e4567-e89b-42d3-a456-426614174000';

function harness(initialCookies, options = {}) {
    const jar = new Map(Object.entries(initialCookies));
    const listeners = {};
    const document = {
        get cookie() { return [...jar].map(([name, value]) => `${name}=${value}`).join('; '); },
        set cookie(value) {
            const [pair, ...attributes] = value.split(';').map((item) => item.trim());
            const index = pair.indexOf('=');
            const name = pair.slice(0, index);
            const content = pair.slice(index + 1);
            if (/expires=thu, 01 jan 1970/i.test(attributes.join('; '))) jar.delete(name);
            else jar.set(name, content);
        },
        dispatchEvent(event) { (listeners[event.type] || []).forEach((callback) => callback(event)); },
        addEventListener(type, callback) { (listeners[type] ||= []).push(callback); }
    };
    const window = {
        location: { protocol: 'https:', hostname: 'shop.example.com' },
        adcConfig: {
            consent: {
                days: 180,
                policy: 1,
                inventory: [
                    { name: '_ga', category: 'statistics' },
                    { name: '_fbp', category: 'marketing' }
                ],
                ...(options.consent || {})
            },
            restUrl: '/wp-json/adc/v1/'
        },
        crypto: { randomUUID: () => uuid },
        fetch: () => Promise.resolve({ ok: true }),
        dataLayer: []
    };
    const context = {
        window, document, navigator: { globalPrivacyControl: options.gpc === true },
        CustomEvent: function CustomEvent(type, init = {}) { this.type = type; this.detail = init.detail; },
        Date, Promise, JSON, Math, parseInt, isFinite, Array, String, Object, RegExp, Uint8Array
    };
    vm.runInNewContext(source, context, { filename: 'consent-manager.js' });
    return { api: window.adcConsent, jar };
}

const initial = {
    _ga: 'GA1.2.1.2', _fbp: 'fb.1.1.1', sbjs_session: 'current', sbjs_udata: 'old',
    adcnecessary: 'yes', 'woocommerce_items_in_cart': '1', 'wp_woocommerce_session_abc': 'session'
};
const { api, jar } = harness(initial);
const optional = ['_ga', '_fbp', 'sbjs_session', 'sbjs_udata'];
const necessary = ['adcnecessary', 'woocommerce_items_in_cart', 'wp_woocommerce_session_abc'];

(async () => {
    await api.rejectAll('banner');
    optional.forEach((name) => assert.equal(jar.has(name), false, `first Reject removes ${name}`));
    necessary.forEach((name) => assert.equal(jar.has(name), true, `first Reject preserves ${name}`));
    assert.equal(jar.has('adc_consent'), true, 'consent decision persists');

    jar.set('_ga', 'again');
    jar.set('sbjs_udata', 'again');
    await api.rejectAll('banner');
    assert.equal(jar.has('_ga'), false, 'repeated Reject still cleans statistics');
    assert.equal(jar.has('sbjs_udata'), false, 'repeated Reject still cleans Sourcebuster');

    const grantStats = harness({ _ga: 'keep', _fbp: 'clear', sbjs_session: 'clear', woocommerce_items_in_cart: '1' });
    await grantStats.api.set({ statistics: true, marketing: false }, 'banner');
    assert.equal(grantStats.jar.has('_ga'), true, 'statistics grant preserves statistics cookie');
    assert.equal(grantStats.jar.has('_fbp'), false, 'marketing denial clears marketing cookie');
    assert.equal(grantStats.jar.has('sbjs_session'), true, 'statistics grant preserves Sourcebuster');
    assert.equal(grantStats.jar.has('woocommerce_items_in_cart'), true, 'necessary Woo cart cookie preserved');
    await grantStats.api.withdraw();
    assert.equal(grantStats.jar.has('_ga'), false, 'withdraw clears newly denied statistics cookie');

    const gpc = harness({ _ga: 'clear', _fbp: 'clear', sbjs_udata: 'clear' }, { gpc: true, consent: { gpc: true } });
    await gpc.api.acceptAll('banner');
    assert.equal(gpc.api.get().source, 'gpc', 'GPC source semantics retained');
    assert.equal(gpc.jar.has('_ga'), true, 'GPC does not clear granted statistics');
    assert.equal(gpc.jar.has('_fbp'), false, 'GPC marketing denial clears marketing cookie');
    assert.equal(gpc.jar.has('sbjs_udata'), true, 'GPC with statistics granted preserves Sourcebuster');

    const grant = harness({ _ga: 'keep', _fbp: 'keep', sbjs_session: 'keep' });
    await grant.api.acceptAll('banner');
    assert.equal(grant.jar.has('_ga'), true, 'explicit statistics grant preserves cookie');
    assert.equal(grant.jar.has('_fbp'), true, 'explicit marketing grant preserves cookie');
    assert.equal(grant.jar.has('sbjs_session'), true, 'explicit statistics grant preserves Sourcebuster');

    console.log('PASS: consent cleanup');
})().catch((error) => { console.error(error); process.exitCode = 1; });
