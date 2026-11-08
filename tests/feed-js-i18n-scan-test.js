'use strict';

// Romanian UI literals must live only in the explicit Node fallback object.
const fs = require('fs');
const path = require('path');
const assert = require('assert');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin-feeds.js'), 'utf8');
const start = source.indexOf('var FEED_STRINGS_DEFAULT = {');
const end = source.indexOf('\n    };', start);
assert(start !== -1 && end !== -1, 'feed string fallback object is present');
const outsideFallback = source.slice(0, start) + source.slice(end + '\n    };'.length);
const stringLiterals = outsideFallback.match(/(['"])(?:\\.|(?!\1)[^\\])*?\1/g) || [];
const diacritics = /[ăâîșțĂÂÎȘȚ]/;
const leaked = stringLiterals.filter((literal) => diacritics.test(literal));
if (leaked.length) {
  console.error('Romanian literals found outside FEED_STRINGS_DEFAULT:', leaked.join('\n'));
  process.exit(1);
}
const php = fs.readFileSync(path.join(__dirname, '../includes/admin/class-adc-admin-tab-feeds.php'), 'utf8');
const fallbackKeys = Array.from(source.matchAll(/\"(feed_ui_\d+)\"\s*:/g), (match) => match[1]).sort();
const localizedKeys = Array.from(php.matchAll(/\"(feed_ui_\d+)\"\s*=>/g), (match) => match[1]).sort();
const usedKeys = Array.from(source.matchAll(/\bt\('(feed_ui_\d+)'\)/g), (match) => match[1]);
assert.deepStrictEqual(localizedKeys, fallbackKeys, 'PHP gettext map matches the JS fallback keys');
assert(usedKeys.every((key) => fallbackKeys.includes(key)), 'every t(key) lookup has a fallback and PHP translation');
console.log('Feed JS Romanian-literal scan and gettext-key parity passed.');
