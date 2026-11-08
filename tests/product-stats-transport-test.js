'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../assets/js/product-stats.js'), 'utf8');
const calls = [];
const listeners = {};
const context = {
    window: {
        adcConsent: { has: (category) => category === 'statistics' },
        adcProductStats: { endpoint: '/wp-json/adc/v1/product-stats/event', ajaxUrl: '/wp-admin/admin-ajax.php', token: 't', productId: 12 },
        fetch(url, options) {
            calls.push({ url, options });
            return Promise.resolve({ status: url.indexOf('wp-json') !== -1 ? 401 : 204 });
        }
    },
    document: { addEventListener(name, callback) { listeners[name] = callback; } },
    Number,
    URLSearchParams,
    JSON
};
context.window.fetch = context.window.fetch.bind(context.window);
vm.runInNewContext(source, context);
setImmediate(() => {
    assert.equal(calls.length, 2, '401 REST response retries once through admin-ajax');
    assert.match(calls[1].url, /admin-ajax\.php/);
    assert.match(calls[1].options.body, /action=adc_product_stats/);
    assert.match(source, /var restBlocked = false;/, 'REST blocking is scoped to the current page');
    assert.match(source, /if \(restBlocked\)/, 'subsequent events skip REST after a blocked response');
    console.log('product-stats-transport-test: PASS');
});
