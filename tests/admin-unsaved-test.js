'use strict';

const assert = require('assert');
const { statesEqual } = require('../assets/js/admin-shell.js');

assert.strictEqual(statesEqual(['text', '1', '0', '#3b6ea5'], ['text', '1', '0', '#3b6ea5']), true);
assert.strictEqual(statesEqual(['changed'], ['original']), false);
assert.strictEqual(statesEqual(['text', '0'], ['text', '1']), false); // checkbox/radio state
assert.strictEqual(statesEqual(['#ffffff'], ['#000000']), false); // color input state
assert.strictEqual(statesEqual(['one'], ['one', 'two']), false);

console.log('admin-unsaved-test: PASS');
