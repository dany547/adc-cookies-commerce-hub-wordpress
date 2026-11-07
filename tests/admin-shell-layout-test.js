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
const inner = shell.match(/\.adc-admin-shell-inner \{[^}]*\}/);
ok(inner && !/max-width:\s*\d/.test(inner[0]), 'shell-inner has no fixed max-width');
ok(inner && /container:\s*adc-shell\s*\/\s*inline-size/.test(inner[0]) && /width:\s*100%/.test(inner[0]), 'shell-inner is a fluid size container');
ok(/@container adc-shell/.test(shell), 'container query on adc-shell');
const gtm = css('admin-gtm.css');
ok(/\.adc-gtm-card \{[^}]*min-width:\s*0/.test(gtm), 'gtm cards min-width:0');
ok(/\.adc-gtm-cards \{[^}]*grid-template-columns:[^;]*minmax\(min\(100%/.test(gtm), 'gtm cards fluid grid');
ok(/\.adc-admin-tagline \{[^}]*min-width:\s*0/.test(shell) && /\.adc-admin-tagline \{[^}]*flex:\s*1 1/.test(shell), 'tagline is flexible');
ok(/\.adc-sr-only \{/.test(shell), 'sr-only helper present');
ok(/> :is\(\.adc-admin-header, \.adc-promo, \.adc-admin-nav[^)]*\)[\s\S]{0,200}width:\s*100%/.test(shell), 'header/promo/nav/content share the fluid width');
const shellPhp = fs.readFileSync(path.join(__dirname, '..', 'includes', 'admin', 'class-adc-admin-shell.php'), 'utf8');
ok(/<h1 class="adc-sr-only screen-reader-text">/.test(shellPhp) && !/<h1 class="adc-admin-title"/.test(shellPhp), 'h1 is visually hidden; no visible plugin-name title');
ok(/admin_footer_text/.test(shellPhp) && /update_footer/.test(shellPhp), 'footer text filters scoped to plugin screen');
ok(/wp-header-end/.test(fs.readFileSync(path.join(__dirname, '..', 'includes', 'admin', 'class-adc-admin-shell.php'), 'utf8')), 'header ends notice zone');
for (const f of fs.readdirSync(path.join(__dirname, '..', 'assets', 'css')).filter((n) => /^admin-/.test(n))) {
  ok(!/(^|[^(\w-])max-width:\s*(1[0-9]{3}|[2-9][0-9]{3})px/m.test(css(f).replace(/@(media|container)[^{]*\{/g, '')), f + ' has no page-level fixed max-width');
}
const js = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'admin-shell.js'), 'utf8');
ok(/initNavScroll/.test(js) && /has-more-end/.test(js), 'nav scroll/fade script present');
if (failed) { process.exit(1); }
console.log('admin-shell layout structure OK');
