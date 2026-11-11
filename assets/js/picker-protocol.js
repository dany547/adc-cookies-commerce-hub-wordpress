/**
 * ADC Marketing — visual picker message protocol (v1).
 *
 * Pure functions only: no DOM access, no message sending, no storage.
 * Sending and origin/source checks are done by the frame (picker-frame.js)
 * and the admin panel (admin-picker.js); this module only builds envelopes
 * and validates their shape against the schema in docs/CUSTOM-EVENTS.md §7.2.
 *
 * Envelope: { ns: 'adc-picker', v: 1, type, ch, seq, payload }
 *   - ch: 32 lowercase hex chars, or '' for 'hello' (channel not yet assigned).
 *   - seq: positive integer, strictly increasing per direction.
 *
 * Exposed as window.adcPickerProtocol and, under Node, module.exports.
 */
(function (root) {
    'use strict';

    var NS = 'adc-picker';
    var V = 1;

    var DIR_TO_ADMIN = 'to_admin';
    var DIR_TO_FRAME = 'to_frame';

    var MAX_BYTES = 16 * 1024;
    var MAX_STR = 200;
    var MAX_SELECTOR = 200;

    var TYPES_TO_ADMIN = ['hello', 'ready', 'hover', 'pick', 'nav', 'blocked', 'mode_changed', 'test_result', 'error'];
    var TYPES_TO_FRAME = ['init', 'set_mode', 'freeze_in', 'test_selector', 'highlight', 'clear'];

    var STRATEGIES = ['id', 'test_attr', 'data_attr', 'aria', 'name', 'href', 'pattern', 'class', 'anchored', 'nth'];
    var SCOPES = ['unique', 'similar'];
    var NOTICES = ['', 'shadow', 'iframe', 'svg', 'canvas'];
    var BLOCK_REASONS = ['external_link', 'form_submit', 'download'];
    var MODES_FRAME = ['select', 'navigate', 'frozen'];
    var MODES_SET = ['select', 'navigate', 'freeze', 'unfreeze'];
    var MODES_INIT = ['select', 'navigate'];
    var VIEWPORTS = ['desktop', 'tablet', 'mobile'];
    var ERROR_CODES = ['selector_invalid', 'internal'];

    var RE_HEX32 = /^[0-9a-f]{32}$/;
    var RE_TAG = /^[a-z][a-z0-9-]{0,30}$/;

    function isPlainObject(v) {
        if (v === null || typeof v !== 'object' || Array.isArray(v)) {
            return false;
        }
        var proto = Object.getPrototypeOf(v);
        return proto === Object.prototype || proto === null;
    }

    function isInt(v) {
        return typeof v === 'number' && Number.isInteger(v);
    }

    function isIntRange(v, min, max) {
        return isInt(v) && v >= min && v <= max;
    }

    function isStr(v, max) {
        return typeof v === 'string' && v.length <= max;
    }

    function isOneOf(v, list) {
        return list.indexOf(v) !== -1;
    }

    /** Ensures object has exactly the allowed keys (no extras). */
    function hasOnlyKeys(obj, keys) {
        var own = Object.keys(obj);
        for (var i = 0; i < own.length; i++) {
            if (keys.indexOf(own[i]) === -1) {
                return false;
            }
        }
        return true;
    }

    function hasKeys(obj, keys) {
        for (var i = 0; i < keys.length; i++) {
            if (!Object.prototype.hasOwnProperty.call(obj, keys[i])) {
                return false;
            }
        }
        return true;
    }

    /* ---------- sub-schemas (§7.2) ---------- */

    function isRect(r) {
        return isPlainObject(r) && hasOnlyKeys(r, ['x', 'y', 'w', 'h']) && hasKeys(r, ['x', 'y', 'w', 'h']) &&
            isInt(r.x) && isInt(r.y) && isInt(r.w) && isInt(r.h);
    }

    function isSummary(s) {
        if (!isPlainObject(s) || !hasOnlyKeys(s, ['tag', 'id', 'classes', 'role', 'text', 'rect'])) {
            return false;
        }
        if (!hasKeys(s, ['tag', 'id', 'classes', 'role', 'text', 'rect'])) {
            return false;
        }
        if (typeof s.tag !== 'string' || !RE_TAG.test(s.tag)) {
            return false;
        }
        if (!isStr(s.id, 60) || !isStr(s.role, 30) || !isStr(s.text, 60)) {
            return false;
        }
        if (!Array.isArray(s.classes) || s.classes.length > 5) {
            return false;
        }
        for (var i = 0; i < s.classes.length; i++) {
            if (!isStr(s.classes[i], 60)) {
                return false;
            }
        }
        return isRect(s.rect);
    }

    function isCandidate(c) {
        return isPlainObject(c) &&
            hasOnlyKeys(c, ['selector', 'score', 'count', 'strategy', 'scope']) &&
            hasKeys(c, ['selector', 'score', 'count', 'strategy', 'scope']) &&
            isStr(c.selector, MAX_SELECTOR) &&
            isIntRange(c.score, 0, 100) &&
            isIntRange(c.count, 1, 9999) &&
            isOneOf(c.strategy, STRATEGIES) &&
            isOneOf(c.scope, SCOPES);
    }

    /* ---------- payload schemas per type (§7.2 table) ---------- */

    /** Each validator returns true when payload matches; keys must match exactly. */
    var SCHEMAS = {
        hello: function (p) {
            return hasOnlyKeys(p, ['href_path']) && hasKeys(p, ['href_path']) && isStr(p.href_path, MAX_STR);
        },
        init: function (p) {
            return hasOnlyKeys(p, ['ch', 'mode', 'viewport']) && hasKeys(p, ['ch', 'mode', 'viewport']) &&
                typeof p.ch === 'string' && RE_HEX32.test(p.ch) &&
                isOneOf(p.mode, MODES_INIT) && isOneOf(p.viewport, VIEWPORTS);
        },
        // Handshake text (§7.2 step 3) also sends title_len; accepted as optional.
        ready: function (p) {
            if (!hasKeys(p, ['href_path']) || !isStr(p.href_path, MAX_STR)) {
                return false;
            }
            var extra = Object.keys(p).filter(function (k) { return k !== 'href_path' && k !== 'title_len'; });
            if (extra.length > 0) {
                return false;
            }
            return !Object.prototype.hasOwnProperty.call(p, 'title_len') || isIntRange(p.title_len, 0, 100000);
        },
        hover: function (p) {
            return hasOnlyKeys(p, ['summary']) && hasKeys(p, ['summary']) && isSummary(p.summary);
        },
        pick: function (p) {
            return hasOnlyKeys(p, ['summary', 'candidates', 'notice']) &&
                hasKeys(p, ['summary', 'candidates', 'notice']) &&
                isSummary(p.summary) &&
                Array.isArray(p.candidates) && p.candidates.length >= 1 && p.candidates.length <= 5 &&
                p.candidates.every(isCandidate) &&
                isOneOf(p.notice, NOTICES);
        },
        nav: function (p) {
            return hasOnlyKeys(p, ['href_path']) && hasKeys(p, ['href_path']) && isStr(p.href_path, MAX_STR);
        },
        blocked: function (p) {
            return hasOnlyKeys(p, ['reason']) && hasKeys(p, ['reason']) && isOneOf(p.reason, BLOCK_REASONS);
        },
        mode_changed: function (p) {
            return hasOnlyKeys(p, ['mode']) && hasKeys(p, ['mode']) && isOneOf(p.mode, MODES_FRAME);
        },
        test_result: function (p) {
            return hasOnlyKeys(p, ['req', 'valid', 'count', 'matches_picked']) &&
                hasKeys(p, ['req', 'valid', 'count', 'matches_picked']) &&
                isInt(p.req) && p.req >= 0 &&
                typeof p.valid === 'boolean' &&
                isIntRange(p.count, 0, 9999) &&
                typeof p.matches_picked === 'boolean';
        },
        error: function (p) {
            return hasOnlyKeys(p, ['code']) && hasKeys(p, ['code']) && isOneOf(p.code, ERROR_CODES);
        },
        set_mode: function (p) {
            return hasOnlyKeys(p, ['mode']) && hasKeys(p, ['mode']) && isOneOf(p.mode, MODES_SET);
        },
        freeze_in: function (p) {
            return hasOnlyKeys(p, ['seconds']) && hasKeys(p, ['seconds']) && isIntRange(p.seconds, 1, 5);
        },
        test_selector: function (p) {
            return hasOnlyKeys(p, ['req', 'selector']) && hasKeys(p, ['req', 'selector']) &&
                isInt(p.req) && p.req >= 0 &&
                isStr(p.selector, MAX_SELECTOR);
        },
        highlight: function (p) {
            return hasOnlyKeys(p, ['selector']) && hasKeys(p, ['selector']) && isStr(p.selector, MAX_SELECTOR);
        },
        clear: function (p) {
            return Object.keys(p).length === 0;
        }
    };

    /** Maps each known message type to its direction. */
    var DIRECTION_OF = {};
    TYPES_TO_ADMIN.forEach(function (t) { DIRECTION_OF[t] = DIR_TO_ADMIN; });
    TYPES_TO_FRAME.forEach(function (t) { DIRECTION_OF[t] = DIR_TO_FRAME; });

    function byteLength(str) {
        if (typeof TextEncoder !== 'undefined') {
            return new TextEncoder().encode(str).length;
        }
        return unescape(encodeURIComponent(str)).length;
    }

    /**
     * Validate an incoming or outgoing message.
     *
     * @param {*} msg       Parsed envelope (already JSON-decoded).
     * @param {string} dir  'to_admin' | 'to_frame'.
     * @param {{ch?: string, seq?: number}} [expected]
     *        ch: channel the receiver is bound to (ignored for 'hello').
     *        seq: last seq accepted in this direction; msg.seq must be greater.
     * @return {{ok: true, type: string, payload: object} | {ok: false, reason: string}}
     */
    function validate(msg, dir, expected) {
        var exp = isPlainObject(expected) ? expected : {};

        if (!isPlainObject(msg)) {
            return { ok: false, reason: 'not_object' };
        }
        var serialized;
        try {
            serialized = JSON.stringify(msg);
        } catch (e) {
            return { ok: false, reason: 'not_serializable' };
        }
        if (byteLength(serialized) > MAX_BYTES) {
            return { ok: false, reason: 'too_large' };
        }
        if (msg.ns !== NS) {
            return { ok: false, reason: 'bad_ns' };
        }
        if (msg.v !== V) {
            return { ok: false, reason: 'bad_version' };
        }
        if (dir !== DIR_TO_ADMIN && dir !== DIR_TO_FRAME) {
            return { ok: false, reason: 'bad_direction_arg' };
        }
        if (typeof msg.type !== 'string' || !Object.prototype.hasOwnProperty.call(DIRECTION_OF, msg.type)) {
            return { ok: false, reason: 'unknown_type' };
        }
        if (DIRECTION_OF[msg.type] !== dir) {
            return { ok: false, reason: 'wrong_direction' };
        }
        if (msg.type !== 'hello') {
            if (typeof msg.ch !== 'string' || !RE_HEX32.test(msg.ch)) {
                return { ok: false, reason: 'bad_channel_format' };
            }
            if (typeof exp.ch === 'string' && msg.ch !== exp.ch) {
                return { ok: false, reason: 'channel_mismatch' };
            }
        }
        if (!isInt(msg.seq) || msg.seq < 1) {
            return { ok: false, reason: 'bad_seq' };
        }
        if (isInt(exp.seq) && msg.seq <= exp.seq) {
            return { ok: false, reason: 'seq_not_increasing' };
        }
        if (!isPlainObject(msg.payload)) {
            return { ok: false, reason: 'bad_payload_object' };
        }
        if (!SCHEMAS[msg.type](msg.payload)) {
            return { ok: false, reason: 'payload_schema' };
        }

        return { ok: true, type: msg.type, payload: msg.payload };
    }

    /**
     * Build an envelope. Does not validate; callers pass a payload that matches
     * the schema for the type (checked by validate()).
     *
     * @param {string} type
     * @param {string} ch   32 lowercase hex, or '' for 'hello'.
     * @param {number} seq  positive integer.
     * @param {object} payload
     * @return {{ns: string, v: number, type: string, ch: string, seq: number, payload: object}}
     */
    function make(type, ch, seq, payload) {
        if (!Object.prototype.hasOwnProperty.call(DIRECTION_OF, type)) {
            throw new TypeError('adcPickerProtocol.make: unknown type ' + String(type));
        }
        return { ns: NS, v: V, type: type, ch: ch, seq: seq, payload: payload };
    }

    var api = {
        NS: NS,
        V: V,
        DIR_TO_ADMIN: DIR_TO_ADMIN,
        DIR_TO_FRAME: DIR_TO_FRAME,
        MAX_BYTES: MAX_BYTES,
        TYPES_TO_ADMIN: TYPES_TO_ADMIN.slice(),
        TYPES_TO_FRAME: TYPES_TO_FRAME.slice(),
        make: make,
        validate: validate
    };

    root.adcPickerProtocol = api;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
