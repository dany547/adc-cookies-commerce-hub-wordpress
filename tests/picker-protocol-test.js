'use strict';

/*
 * Offline test for assets/js/picker-protocol.js (visual selector wire format, §7.2) plus the
 * static XSS / origin rules of the two sides (§7.5): no HTML sinks, no wildcard postMessage.
 * Exit code 0 = all checks passed; 1 = at least one failure (listed).
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ASSETS = path.join(__dirname, '..', 'assets', 'js');
const P = require(path.join(ASSETS, 'picker-protocol.js'));

const CH = 'ab'.repeat(16);
const RECT = { x: 1, y: 2, w: 30, h: 40 };
const SUMMARY = { tag: 'a', id: 'buy', classes: ['btn'], role: 'link', text: 'Cumpără', rect: RECT };
const CANDIDATE = { selector: 'a.btn', score: 60, count: 1, strategy: 'class', scope: 'unique' };
const PICK = { summary: SUMMARY, candidates: [CANDIDATE], notice: '' };

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

function env(type, payload, ch = CH, seq = 1) {
  return P.make(type, ch, seq, payload);
}
function expectReason(msg, dir, reason, expected) {
  const r = P.validate(msg, dir, expected);
  assert.equal(r.ok, false, 'expected rejection (' + reason + ')');
  assert.equal(r.reason, reason);
}

/* ---------- validate: envelope ---------- */

test('ns greșit → bad_ns', () => {
  const m = env('ready', { href_path: '/' }); m.ns = 'other';
  expectReason(m, P.DIR_TO_ADMIN, 'bad_ns');
});

test('v greșit → bad_version', () => {
  const m = env('ready', { href_path: '/' }); m.v = 2;
  expectReason(m, P.DIR_TO_ADMIN, 'bad_version');
});

test('tip necunoscut → unknown_type', () => {
  // Built by hand: make() rightly refuses unknown types.
  const m = { ns: P.NS, v: P.V, type: 'explode', ch: CH, seq: 1, payload: {} };
  expectReason(m, P.DIR_TO_ADMIN, 'unknown_type');
});

test('direcție greșită (init trimis către admin) → wrong_direction', () => {
  expectReason(env('init', { ch: CH, mode: 'select', viewport: 'desktop' }), P.DIR_TO_ADMIN, 'wrong_direction');
});

test('ch greșit (nu hex32) → bad_channel_format', () => {
  expectReason(env('ready', { href_path: '/' }, 'AB'.repeat(16)), P.DIR_TO_ADMIN, 'bad_channel_format');
  expectReason(env('ready', { href_path: '/' }, 'ab'.repeat(15)), P.DIR_TO_ADMIN, 'bad_channel_format');
});

test('ch diferit de canalul așteptat → channel_mismatch', () => {
  expectReason(env('ready', { href_path: '/' }, 'cd'.repeat(16)), P.DIR_TO_ADMIN, 'channel_mismatch', { ch: CH, seq: 0 });
});

test('seq necrescător → seq_not_increasing', () => {
  expectReason(env('ready', { href_path: '/' }, CH, 5), P.DIR_TO_ADMIN, 'seq_not_increasing', { ch: CH, seq: 5 });
  expectReason(env('ready', { href_path: '/' }, CH, 4), P.DIR_TO_ADMIN, 'seq_not_increasing', { ch: CH, seq: 5 });
});

test('seq invalid (0, negativ, float) → bad_seq', () => {
  for (const seq of [0, -1, 1.5]) {
    expectReason(env('ready', { href_path: '/' }, CH, seq), P.DIR_TO_ADMIN, 'bad_seq');
  }
});

test('payload care nu e obiect → bad_payload_object', () => {
  const m = env('ready', { href_path: '/' }); m.payload = 'text';
  expectReason(m, P.DIR_TO_ADMIN, 'bad_payload_object');
});

test('highlight cu selector 201 caractere → payload_schema (200 acceptat)', () => {
  expectReason(env('highlight', { selector: 'a'.repeat(201) }), P.DIR_TO_FRAME, 'payload_schema', { ch: CH, seq: 0 });
  const ok = P.validate(env('highlight', { selector: 'a'.repeat(200) }), P.DIR_TO_FRAME, { ch: CH, seq: 0 });
  assert.equal(ok.ok, true, 'selector of exactly 200 must be accepted');
});

test('test_result cu count negativ → payload_schema', () => {
  expectReason(env('test_result', { req: 1, valid: true, count: -1, matches_picked: false }), P.DIR_TO_ADMIN, 'payload_schema');
});

test('payload peste 16 KB → too_large', () => {
  expectReason(env('ready', { href_path: 'x'.repeat(17 * 1024) }), P.DIR_TO_ADMIN, 'too_large');
});

test('hello fără ch acceptat', () => {
  const m = { ns: P.NS, v: P.V, type: 'hello', seq: 1, payload: { href_path: '/checkout/' } };
  const r = P.validate(m, P.DIR_TO_ADMIN, { ch: CH, seq: 0 });
  assert.equal(r.ok, true, JSON.stringify(r));
  assert.equal(r.type, 'hello');
});

test("hello cu ch gol ('') acceptat", () => {
  assert.equal(P.validate(env('hello', { href_path: '/' }, ''), P.DIR_TO_ADMIN).ok, true);
});

test('hello cu chei extra în payload → payload_schema', () => {
  expectReason(env('hello', { href_path: '/', extra: 1 }, ''), P.DIR_TO_ADMIN, 'payload_schema');
});

test('mode_changed cu mod inexistent în frame (unfreeze) → payload_schema', () => {
  expectReason(env('mode_changed', { mode: 'unfreeze' }), P.DIR_TO_ADMIN, 'payload_schema');
});

test('pick cu 0 sau 6 candidați → payload_schema', () => {
  expectReason(env('pick', { summary: SUMMARY, candidates: [], notice: '' }), P.DIR_TO_ADMIN, 'payload_schema');
  const six = Array.from({ length: 6 }, () => CANDIDATE);
  expectReason(env('pick', { summary: SUMMARY, candidates: six, notice: '' }), P.DIR_TO_ADMIN, 'payload_schema');
});

test('pick cu strategie necunoscută → payload_schema', () => {
  const bad = Object.assign({}, CANDIDATE, { strategy: 'xpath' });
  expectReason(env('pick', { summary: SUMMARY, candidates: [bad], notice: '' }), P.DIR_TO_ADMIN, 'payload_schema');
});

test('summary cu tag invalid → payload_schema', () => {
  const bad = Object.assign({}, SUMMARY, { tag: 'img onerror' });
  expectReason(env('hover', { summary: bad }), P.DIR_TO_ADMIN, 'payload_schema');
});

test('clear acceptă doar payload gol', () => {
  assert.equal(P.validate(env('clear', {}), P.DIR_TO_FRAME, { ch: CH, seq: 0 }).ok, true);
  expectReason(env('clear', { x: 1 }), P.DIR_TO_FRAME, 'payload_schema');
});

/* ---------- make + validate round-trip ---------- */

test('make() → JSON → validate() round-trip pentru fiecare tip', () => {
  const samples = [
    ['hello', '', { href_path: '/a' }, P.DIR_TO_ADMIN],
    ['ready', CH, { href_path: '/a', title_len: 12 }, P.DIR_TO_ADMIN],
    ['hover', CH, { summary: SUMMARY }, P.DIR_TO_ADMIN],
    ['pick', CH, PICK, P.DIR_TO_ADMIN],
    ['nav', CH, { href_path: '/b' }, P.DIR_TO_ADMIN],
    ['blocked', CH, { reason: 'external_link' }, P.DIR_TO_ADMIN],
    ['mode_changed', CH, { mode: 'frozen' }, P.DIR_TO_ADMIN],
    ['test_result', CH, { req: 3, valid: true, count: 2, matches_picked: true }, P.DIR_TO_ADMIN],
    ['error', CH, { code: 'internal' }, P.DIR_TO_ADMIN],
    ['init', CH, { ch: CH, mode: 'select', viewport: 'mobile' }, P.DIR_TO_FRAME],
    ['set_mode', CH, { mode: 'freeze' }, P.DIR_TO_FRAME],
    ['freeze_in', CH, { seconds: 3 }, P.DIR_TO_FRAME],
    ['test_selector', CH, { req: 1, selector: 'a.btn' }, P.DIR_TO_FRAME],
    ['highlight', CH, { selector: 'a.btn' }, P.DIR_TO_FRAME],
    ['clear', CH, {}, P.DIR_TO_FRAME],
  ];
  for (const [type, ch, payload, dir] of samples) {
    const wire = JSON.parse(JSON.stringify(P.make(type, ch, 1, payload)));
    const r = P.validate(wire, dir, { ch: ch || CH, seq: 0 });
    assert.equal(r.ok, true, `${type}: ${r.reason}`);
    assert.equal(r.type, type);
  }
});

test('make() respinge tipuri necunoscute', () => {
  assert.throws(() => P.make('explode', CH, 1, {}), TypeError);
});

/* ---------- static checks on the two sides (§7.5) ---------- */

const SINK = /innerHTML|insertAdjacentHTML|outerHTML|document\.write/;
const WILDCARD_POST = /postMessage\s*\([^;]*,\s*['"]\*['"]\s*\)/;

for (const file of ['admin-picker.js', 'picker-frame.js']) {
  test(`${file}: fără innerHTML/insertAdjacentHTML/outerHTML/document.write`, () => {
    const src = fs.readFileSync(path.join(ASSETS, file), 'utf8');
    const hit = src.match(SINK);
    assert.equal(hit, null, `sink found: ${hit && hit[0]}`);
  });

  test(`${file}: niciun postMessage(…, '*')`, () => {
    const src = fs.readFileSync(path.join(ASSETS, file), 'utf8');
    assert.equal(WILDCARD_POST.test(src), false, 'wildcard postMessage target found');
  });
}

/* ---------- runner ---------- */

let failures = 0;
for (const t of tests) {
  try {
    t.fn();
    console.log('ok   - ' + t.name);
  } catch (err) {
    failures += 1;
    console.log('FAIL - ' + t.name + '\n       ' + String(err && err.message).split('\n').join('\n       '));
  }
}
console.log(`\n${tests.length - failures}/${tests.length} passed`);
if (failures) process.exitCode = 1;
