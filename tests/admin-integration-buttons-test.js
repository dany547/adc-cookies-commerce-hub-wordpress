/* Regression: WordPress prints ALL 'before' configs before ANY 'after' handlers.
 * Run: node tests/admin-integration-buttons-test.js
 * Uses actual PHP enqueue callbacks; no network or real credentials.
 */
'use strict';
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const vm = require('node:vm');
const path = require('node:path');

const fixture = `<?php
 define('ABSPATH', '/fixture/');
 define('ADC_MARKETING_PLUGIN_DIR', getcwd() . '/');
 require 'includes/core/class-adc-autoloader.php';
 ADC_Autoloader::register();
 function current_user_can($cap) { return true; }
 function wp_script_is($handle, $state) { return true; }
 function admin_url($path) { return 'https://example.invalid/' . $path; }
 function wp_create_nonce($action) { return 'fixture-nonce'; }
 function __($text, $domain) { return $text; }
 function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
 $scripts = ['before' => [], 'after' => []];
 function wp_add_inline_script($handle, $script, $position = 'after') {
     $GLOBALS['scripts'][$position][] = $script;
 }
 foreach (['Meta', 'Tiktok', 'Ga4', 'Sgtm'] as $name) {
     $class = 'ADC_Admin_Tab_' . $name;
     $class::enqueue_assets('toplevel_page_adc-marketing');
 }
 echo json_encode($scripts);
`;
const php = spawnSync('php', [], {
    cwd: path.resolve(__dirname, '..'), input: fixture, encoding: 'utf8'
});
assert.equal(php.status, 0, php.stderr);
const scripts = JSON.parse(php.stdout);
assert.ok(scripts.before.length >= 4, 'all integration configs must be enqueued');

(async () => {
    for (const platform of ['meta', 'tiktok', 'ga4', 'sgtm']) {
        const handlers = [];
        const requests = [];
        const result = { textContent: '', className: '' };
        const button = { disabled: false, addEventListener: (name, fn) => {
            assert.equal(name, 'click'); handlers.push(fn);
        } };
        const context = {
            window: {},
            document: { getElementById: (id) => {
                if (id === `adc-${platform}-test`) return button;
                if (id === `adc-${platform}-test-result`) return result;
                return null;
            } },
            FormData,
            fetch: async (url, options) => {
                requests.push({ url, options });
                return { json: async () => ({ success: true, data: { status: 'sent' } }) };
            }
        };
        vm.createContext(context);
        for (const script of [...scripts.before, ...scripts.after]) {
            vm.runInContext(script, context);
        }
        assert.equal(handlers.length, 1, `${platform}: exactly one click handler`);
        handlers[0]();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(requests.length, 1);
        assert.equal(requests[0].options.body.get('platform'), platform,
            `${platform}: button must not test another integration`);
        assert.equal(requests[0].options.body.get('action'), 'adc_test_integration');
        assert.equal(requests[0].options.body.get('nonce'), 'fixture-nonce');
        assert.equal(button.disabled, false);
        assert.match(result.className, /adc-test-ok/);
        console.log(`PASS ${platform}: own platform, nonce, result and button recovery`);
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
