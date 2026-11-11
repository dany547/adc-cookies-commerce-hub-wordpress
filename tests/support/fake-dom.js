'use strict';

/** Minimal HTML fixture parser and CSS subset evaluator for picker selector tests. */
function parseHTML(html) {
  var nodes = [];
  function node(tag, attrs, parent) {
    var el = { tagName: tag.toUpperCase(), id: attrs.id || '', attributes: Object.keys(attrs).map(function (name) { return { name: name, value: attrs[name] }; }), parentElement: parent || null, children: [], ownerDocument: null };
    el.classList = (attrs.class || '').split(/\s+/).filter(Boolean);
    el.getAttribute = function (name) { var a = this.attributes.find(function (x) { return x.name === name; }); return a ? a.value : null; };
    el.matches = function (selector) { return matches(this, selector); };
    el.closest = function (selector) { var e = this; while (e) { if (e.matches(selector)) return e; e = e.parentElement; } return null; };
    if (parent) parent.children.push(el);
    nodes.push(el); return el;
  }
  var root = node('document', {}, null), stack = [root], token = /<!--[\s\S]*?-->|<![^>]*>|<\/?[A-Za-z][^>]*>/g, m;
  while ((m = token.exec(String(html)))) {
    var raw = m[0]; if (/^<!|^<!--/.test(raw)) continue;
    if (/^<\//.test(raw)) { var close = raw.slice(2).split(/[\s>]/)[0].toLowerCase(); while (stack.length > 1) { var popped = stack.pop(); if (popped.tagName.toLowerCase() === close) break; } continue; }
    var tag = raw.slice(1).match(/^([\w:-]+)/)[1].toLowerCase(), attrs = {}, re = /([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g, ar;
    var rest = raw.slice(1 + tag.length, -1); while ((ar = re.exec(rest))) attrs[ar[1].toLowerCase()] = ar[2] !== undefined ? ar[2] : ar[3] !== undefined ? ar[3] : ar[4] !== undefined ? ar[4] : '';
    var el = node(tag, attrs, stack[stack.length - 1]);
    if (!/^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/.test(tag) && !/\/$/.test(raw.slice(0, -1))) stack.push(el);
  }
  var doc = { location: { href: 'https://example.test/', origin: 'https://example.test' }, querySelectorAll: function (selector) { return nodes.filter(function (n) { return n !== root && n.tagName.toLowerCase() !== 'document' && matches(n, selector); }); } };
  nodes.forEach(function (n) { n.ownerDocument = doc; });
  doc.body = nodes.find(function (n) { return n.tagName.toLowerCase() === 'body'; }) || root;
  return doc;
}
function splitGroups(source) {
  var groups = [], start = 0, quote = '', bracket = 0, escaped = false;
  for (var i = 0; i < source.length; i += 1) {
    var ch = source.charAt(i);
    if (escaped) { escaped = false; continue; }
    if (ch === '\\') { escaped = true; continue; }
    if (quote) { if (ch === quote) quote = ''; continue; }
    if (bracket && (ch === '"' || ch === "'")) { quote = ch; continue; }
    if (ch === '[') bracket += 1; else if (ch === ']') bracket -= 1;
    else if (ch === ',' && !bracket) { groups.push(source.slice(start, i).trim()); start = i + 1; }
  }
  if (quote || bracket !== 0 || escaped) throw new SyntaxError('Invalid selector');
  groups.push(source.slice(start).trim()); return groups;
}
function tokenize(source) {
  var parts = [], token = '', bracket = 0, quote = '', escaped = false, pendingSpace = false;
  for (var i = 0; i < source.length; i += 1) {
    var ch = source.charAt(i);
    if (escaped) { token += ch; escaped = false; continue; }
    if (ch === '\\') {
      token += ch;
      var hex = source.slice(i + 1).match(/^[\da-f]{1,6}/i);
      if (hex) { token += hex[0]; i += hex[0].length; if (/\s/.test(source.charAt(i + 1))) { token += source.charAt(i + 1); i += 1; } }
      else escaped = true;
      continue;
    }
    if (quote) { token += ch; if (ch === quote) quote = ''; continue; }
    if (bracket && (ch === '"' || ch === "'")) { quote = ch; token += ch; continue; }
    if (ch === '[') bracket += 1; else if (ch === ']') bracket -= 1;
    if (!bracket && /\s/.test(ch)) { if (token) { parts.push(token); token = ''; } pendingSpace = true; continue; }
    if (!bracket && ch === '>') { if (token) parts.push(token); token = ''; if (parts.length && parts[parts.length - 1] !== '>') parts.push(' '); parts.push('>'); pendingSpace = false; continue; }
    if (pendingSpace && token) { parts.push(token); token = ''; parts.push(' '); } pendingSpace = false; token += ch;
  }
  if (quote || bracket || escaped) throw new SyntaxError('Invalid selector');
  if (token) parts.push(token);
  return parts.filter(function (part) { return part !== ''; }).filter(function (part, index, all) { return !(part === ' ' && (index === 0 || index === all.length - 1 || all[index - 1] === '>') || part === ' ' && all[index + 1] === '>'); });
}
function unescapeCss(source) {
  return source.replace(/\\([\da-f]{1,6})\s?|\\(.)/gi, function (_, hex, ch) { var code = hex ? parseInt(hex, 16) : 0; return hex ? String.fromCodePoint(code === 0 ? 0xfffd : code) : ch; });
}
function readIdent(source, start) {
  var i = start, raw = '';
  while (i < source.length) {
    var ch = source.charAt(i);
    if (ch === '\\') {
      raw += ch; i += 1;
      var hex = source.slice(i).match(/^[\da-f]{1,6}/i);
      if (hex) { raw += hex[0]; i += hex[0].length; if (/\s/.test(source.charAt(i))) { raw += source.charAt(i); i += 1; } }
      else if (i < source.length) { raw += source.charAt(i); i += 1; }
    } else if (/[\w-]/.test(ch) || ch.charCodeAt(0) >= 128) { raw += ch; i += 1; }
    else break;
  }
  if (!raw) throw new SyntaxError('Expected identifier');
  return { value: unescapeCss(raw), end: i };
}
function simple(el, source) {
  var s = source, i = 0, tag = '', nth = null, ids = [], classes = [], attrs = [];
  if (!s) throw new SyntaxError('Empty selector');
  var tagMatch = s.match(/^[a-z][a-z0-9-]*/i);
  if (tagMatch) { tag = tagMatch[0].toLowerCase(); i = tagMatch[0].length; }
  while (i < s.length) {
    var ch = s.charAt(i++);
    if (ch === '#' || ch === '.') { var ident = readIdent(s, i); (ch === '#' ? ids : classes).push(ident.value); i = ident.end; continue; }
    if (ch === '[') {
      var start = i, quote = '', escaped = false;
      while (i < s.length) { var ac = s.charAt(i); if (escaped) escaped = false; else if (ac === '\\') escaped = true; else if (quote) { if (ac === quote) quote = ''; } else if (ac === '"' || ac === "'") quote = ac; else if (ac === ']') break; i += 1; }
      if (i >= s.length) throw new SyntaxError('Unclosed attribute');
      var body = s.slice(start, i); i += 1;
      var attrMatch = body.match(/^([\w-]+)(?:(\^=|\*=|=)(["'])([\s\S]*)\3)?$/);
      if (!attrMatch) throw new SyntaxError('Invalid attribute');
      attrs.push({ name: attrMatch[1], op: attrMatch[2] || '', value: attrMatch[4] === undefined ? '' : unescapeCss(attrMatch[4]) }); continue;
    }
    if (ch === ':') {
      var pseudo = s.slice(i).match(/^nth-of-type\((\d+)\)/);
      if (!pseudo || nth !== null) throw new SyntaxError('Unsupported pseudo');
      nth = Number(pseudo[1]); i += pseudo[0].length; continue;
    }
    throw new SyntaxError('Unsupported selector syntax');
  }
  if (tag && el.tagName.toLowerCase() !== tag) return false;
  if (ids.some(function (id) { return el.id !== id; })) return false;
  if (classes.some(function (name) { return el.classList.indexOf(name) < 0; })) return false;
  if (nth !== null) { if (!el.parentElement) return false; var siblings = el.parentElement.children.filter(function (x) { return x.tagName === el.tagName; }); if (siblings.indexOf(el) + 1 !== nth) return false; }
  return attrs.every(function (a) { var value = el.getAttribute(a.name); if (!a.op) return value !== null; if (value === null) return false; return a.op === '=' ? value === a.value : a.op === '^=' ? value.indexOf(a.value) === 0 : value.indexOf(a.value) !== -1; });
}
function matches(el, selector) {
  return splitGroups(selector).some(function (group) {
    var parts = tokenize(group), current = el, i = parts.length - 1;
    if (!simple(current, parts[i--])) return false;
    while (i >= 0) {
      var combinator = parts[i--];
      if (combinator === '>') { if (i < 0 || !current.parentElement || !simple(current.parentElement, parts[i--])) return false; current = current.parentElement; }
      else { var found = false; while (current.parentElement) { current = current.parentElement; if (simple(current, combinator)) { found = true; break; } } if (!found) return false; }
    }
    return true;
  });
}
module.exports = { parseHTML: parseHTML };
