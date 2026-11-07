'use strict';
// Structure checks for the admin shell nav + spacing system (no browser needed).
const fs = require('fs');
const path = require('path');
const css = (f) => fs.readFileSync(path.join(__dirname, '..', 'assets', 'css', f), 'utf8');
const shell = css('admin-shell.css');
let failed = 0;
function ok(cond, msg) { if (!cond) { failed++; console.error('FAIL ' + msg); } }

ok(/container:\s*adc-nav\s*\/\s*inline-size/.test(shell), 'nav is a size container');
ok(/@container adc-nav \(max-width: 640px\)/.test(shell), 'mid-width container query');
ok(/@container adc-nav \(max-width: 520px\)[\s\S]*overflow-x: auto/.test(shell), 'narrow scrollable line');
ok(/min-height: 44px/.test(shell), '44px touch targets');
ok(/\.adc-admin-group a:focus-visible/.test(shell), 'group pills have visible focus');
ok(/--adc-space-0: 4px/.test(shell) && /--adc-space-4: 32px/.test(shell), 'spacing tokens declared');
ok(/> \* \+ \* \{\s*margin-block-start: var\(--adc-space-2\)/.test(shell), 'owl rule on flow containers');
for (const f of ['admin-help.css', 'admin-banner.css', 'admin-settings.css']) {
  ok(!/\.adc-settings-section \{\s*margin-top/.test(css(f)), f + ' has no ad-hoc .adc-settings-section margin');
}
const js = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'admin-shell.js'), 'utf8');
ok(/initNavScroll/.test(js) && /has-more-end/.test(js), 'nav scroll/fade script present');
if (failed) { process.exit(1); }
console.log('admin-shell layout structure OK');
