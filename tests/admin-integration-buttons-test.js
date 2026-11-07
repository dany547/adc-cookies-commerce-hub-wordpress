/* Regression: WordPress prints ALL 'before' configs before ANY 'after' handlers.
 * Run: node tests/admin-integration-buttons-test.js
 * Uses actual PHP enqueue callbacks; no network or real credentials.
 *
 * Also guards the connection-test payload: every "Testează conexiunea" button
 * must send the CURRENT (unsaved) values of its own tab fields as a JSON
 * `fields` object — allowlisted per platform, and a secret left empty in the
 * form must be omitted so the saved secret is used.
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

// Form field element ids per platform + the values the admin has typed (unsaved).
const platforms = {
    meta: {
        filled: {
            'adc-meta-pixel-id': '112233445566778',
            'adc-meta-test-code': 'TEST12345',
            'adc-meta-access-token': 'EAAB-secret-token'
        },
        empty: { 'adc-meta-access-token': '' },
        expected: {
            pixel_id: '112233445566778',
            test_event_code: 'TEST12345',
            access_token: 'EAAB-secret-token'
        },
        secret: 'access_token'
    },
    ga4: {
        filled: {
            'adc-ga4-measurement-id': 'G-ABC123XYZ',
            'adc-ga4-api-secret': 'ga4-api-secret'
        },
        empty: { 'adc-ga4-api-secret': '' },
        expected: { measurement_id: 'G-ABC123XYZ', api_secret: 'ga4-api-secret' },
        secret: 'api_secret'
    },
    tiktok: {
        filled: {
            'adc-tiktok-pixel-code': 'CABC123DEF',
            'adc-tiktok-test-code': 'TEST98765',
            'adc-tiktok-access-token': 'tt-access-token'
        },
        empty: { 'adc-tiktok-access-token': '' },
        expected: {
            pixel_code: 'CABC123DEF',
            test_event_code: 'TEST98765',
            access_token: 'tt-access-token'
        },
        secret: 'access_token'
    },
    sgtm: {
        filled: {
            'adc-sgtm-url': 'https://analytics.example.ro',
            'adc-sgtm-measurement-id': 'G-SGTM12345',
            'adc-sgtm-mode': 'mp_forward',
            'adc-sgtm-api-secret': 'sgtm-api-secret'
        },
        empty: { 'adc-sgtm-api-secret': '' },
        expected: {
            server_container_url: 'https://analytics.example.ro',
            measurement_id: 'G-SGTM12345',
            mode: 'mp_forward',
            api_secret: 'sgtm-api-secret'
        },
        secret: 'api_secret'
    }
};

function runPlatform(platform, values, fromForm) {
    const handlers = [];
    const requests = [];
    const result = { textContent: '', className: '' };
    const button = { disabled: false, addEventListener: (name, fn) => {
        assert.equal(name, 'click'); handlers.push(fn);
    } };
    const inputs = Object.assign({}, values);
    const context = {
        window: {},
        document: { getElementById: (id) => {
            if (id === `adc-${platform}-test`) return button;
            if (id === `adc-${platform}-test-result`) return result;
            if (Object.prototype.hasOwnProperty.call(inputs, id)) return { value: inputs[id] };
            return null;
        } },
        FormData,
        fetch: async (url, options) => {
            requests.push({ url, options });
            return { json: async () => ({ success: true, data: { status: 'sent', from_form: fromForm } }) };
        }
    };
    vm.createContext(context);
    for (const script of [...scripts.before, ...scripts.after]) {
        vm.runInContext(script, context);
    }
    assert.equal(handlers.length, 1, `${platform}: exactly one click handler`);
    handlers[0]();
    return new Promise(resolve => setImmediate(() => resolve({ requests, result, button })));
}

(async () => {
    for (const platform of ['meta', 'tiktok', 'ga4', 'sgtm']) {
        const spec = platforms[platform];
        const { requests, result, button } = await runPlatform(platform, spec.filled, true);

        assert.equal(requests.length, 1);
        const body = requests[0].options.body;
        assert.equal(body.get('platform'), platform,
            `${platform}: button must not test another integration`);
        assert.equal(body.get('action'), 'adc_test_integration');
        assert.equal(body.get('nonce'), 'fixture-nonce');

        const raw = body.get('fields');
        assert.ok(raw, `${platform}: unsaved form values must be sent`);
        const fields = JSON.parse(raw);
        assert.deepEqual(fields, spec.expected, `${platform}: current form values only`);
        assert.equal(button.disabled, false);
        assert.match(result.className, /adc-test-ok/);
        assert.match(result.textContent, /formular, fără salvare/,
            `${platform}: result must say the form values were used`);
        console.log(`PASS ${platform}: own platform, nonce, current form values, result and recovery`);

        // A secret left empty in the form is omitted → the saved secret is used.
        const emptyRun = await runPlatform(platform, spec.empty, true);
        const emptyFields = JSON.parse(emptyRun.requests[0].options.body.get('fields'));
        assert.equal(Object.prototype.hasOwnProperty.call(emptyFields, spec.secret), false,
            `${platform}: empty secret field must not override the saved secret`);
        assert.equal(emptyRun.requests[0].options.body.get('fields').includes('""'),
            true, `${platform}: non-secret empty values still mirror the form`);
        console.log(`PASS ${platform}: empty secret field keeps the saved secret`);

        // Server without form overrides: no "form values" note.
        const savedRun = await runPlatform(platform, spec.empty, false);
        assert.equal(savedRun.result.textContent.includes('formular, fără salvare'), false,
            `${platform}: no form-values note when the saved settings were tested`);
        console.log(`PASS ${platform}: result distinguishes saved settings from form values`);
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
