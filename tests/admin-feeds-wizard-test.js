'use strict';

// Pure logic of the Catalog ▸ Feeduri wizard and REST client (no DOM needed).
const assert = require('assert');
const feeds = require('../assets/js/admin-feeds.js');

const {
  emptyWizard,
  parseCategories,
  validateStep,
  validateAll,
  buildPayload,
  buildPatchPayload,
  wizardFromFeed,
  messageFor,
  apiRequest,
  copyText,
  statusLabel,
} = feeds;

let passed = 0;
function test(label, fn) {
  return Promise.resolve()
    .then(fn)
    .then(() => { passed++; console.log('OK   ' + label); })
    .catch((err) => { console.error('FAIL ' + label + '\n     ' + err.message); process.exitCode = 1; });
}

// A complete, valid wizard state.
function valid(overrides) {
  return Object.assign(emptyWizard(), { name: 'Feed principal' }, overrides || {});
}

// Fake Response with the parts apiRequest uses.
function response(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status: status,
    json: () => (body === undefined ? Promise.reject(new Error('not json')) : Promise.resolve(body)),
  };
}

(async () => {
  // --- Step 1: destination and name ------------------------------------------
  await test('edit load releases busy state before rendering enabled navigation and action controls', () => {
    let busy = true;
    let rendered;
    feeds.completeEditLoad({ feed: { id: 9 } }, () => { busy = false; }, (feed) => {
      rendered = {
        feedId: feed.id,
        continueDisabled: busy,
        cancelDisabled: busy,
        stepButtonsDisabled: busy,
        stepClickHandlerRuns: !busy,
      };
    });
    assert.deepStrictEqual(rendered, {
      feedId: 9,
      continueDisabled: false,
      cancelDisabled: false,
      stepButtonsDisabled: false,
      stepClickHandlerRuns: true,
    });
  });

  await test('step 1: Google with a name is valid', () => {
    assert.deepStrictEqual(validateStep(valid(), 1), []);
  });
  await test('step 1: OLX is listed but cannot be picked yet', () => {
    const errors = validateStep(valid({ channel: 'olx' }), 1);
    assert.strictEqual(errors.length, 1);
    assert.match(errors[0], /disponibilă/);
  });
  await test('step 1: Meta with csv is valid; a format outside the channel list is refused', () => {
    assert.deepStrictEqual(validateStep(valid({ channel: 'meta', format: 'csv' }), 1), []);
    const errors = validateStep(valid({ channel: 'meta', format: 'tsv' }), 1);
    assert.strictEqual(errors.length, 1);
    assert.match(errors[0], /format/);
  });
  await test('step 1: blank name rejected, whitespace is trimmed first', () => {
    assert.strictEqual(validateStep(valid({ name: '   ' }), 1).length, 1);
    assert.strictEqual(validateStep(valid({ name: 'a'.repeat(121) }), 1).length, 1);
    assert.deepStrictEqual(validateStep(valid({ name: '  Feed  ' }), 1), []);
  });

  // --- Step 2: products -------------------------------------------------------
  await test('Feed nou resets a modified wizard draft to fresh defaults', () => {
    const draft = feeds.resetWizardDraft();
    assert.deepStrictEqual(draft, emptyWizard());
    assert.strictEqual(draft.excludeInvalid, true);
    assert.strictEqual(draft.name, '');
    assert.strictEqual(draft.params, null);
  });
  await test('step 2: all products needs no categories', () => {
    assert.deepStrictEqual(validateStep(valid({ scope: 'all', categories: '' }), 2), []);
  });
  await test('step 2: filtered scope needs at least one slug', () => {
    assert.strictEqual(validateStep(valid({ scope: 'filtered', categories: ' , ' }), 2).length, 1);
  });
  await test('step 2: smart scope accepts optional categories and validates its bounded rule', () => {
    assert.deepStrictEqual(validateStep(valid({ scope: 'smart', categories: '', smartMode: 'low_conversion', smartMetric: 'views' }), 2), []);
    assert.strictEqual(validateStep(valid({ scope: 'smart', smartMode: 'min_value', smartMetric: 'views', smartThreshold: 0 }), 2).length, 1);
    assert.strictEqual(validateStep(valid({ scope: 'smart', smartLimit: 501 }), 2).length, 1);
  });
  await test('step 2: categories must be lowercase slugs', () => {
    assert.strictEqual(validateStep(valid({ scope: 'filtered', categories: 'Încălțăminte' }), 2).length, 1);
    assert.strictEqual(validateStep(valid({ scope: 'filtered', categories: 'bad slug' }), 2).length, 1);
    assert.deepStrictEqual(validateStep(valid({ scope: 'filtered', categories: 'incaltaminte, accesorii-2' }), 2), []);
  });
  await test('step 2: more than 50 categories rejected', () => {
    const many = Array.from({ length: 51 }, (_, i) => 'cat-' + i).join(',');
    assert.strictEqual(validateStep(valid({ scope: 'filtered', categories: many }), 2).length, 1);
  });
  await test('parseCategories trims, lowercases, drops empties and duplicates', () => {
    assert.deepStrictEqual(parseCategories(' A, a ,b,,B '), ['a', 'b']);
    assert.deepStrictEqual(parseCategories(undefined), []);
  });

  // --- Step 3: Google settings -------------------------------------------------
  await test('step 3: defaults (RO, 0.00, Standard, new) are valid', () => {
    assert.deepStrictEqual(validateStep(valid(), 3), []);
  });
  await test('step 3: country is normalized to two uppercase letters', () => {
    assert.deepStrictEqual(validateStep(valid({ country: ' ro ' }), 3), []);
    assert.strictEqual(validateStep(valid({ country: 'ROU' }), 3).length, 1);
  });
  await test('step 3: price needs a dot and at most two decimals', () => {
    assert.deepStrictEqual(validateStep(valid({ price: '15' }), 3), []);
    assert.deepStrictEqual(validateStep(valid({ price: '15.5' }), 3), []);
    assert.strictEqual(validateStep(valid({ price: '15,00' }), 3).length, 1);
    assert.strictEqual(validateStep(valid({ price: '1.234' }), 3).length, 1);
    assert.strictEqual(validateStep(valid({ price: '-3' }), 3).length, 1);
  });
  await test('step 3: service and condition are checked', () => {
    assert.strictEqual(validateStep(valid({ service: '' }), 3).length, 1);
    assert.strictEqual(validateStep(valid({ condition: 'broken' }), 3).length, 1);
  });

  // --- Payload matches ADC_Feed_Rest::validate_create() ----------------------
  await test('buildPayload produces the REST contract shape', () => {
    const payload = buildPayload(valid({
      scope: 'filtered',
      categories: 'Accesorii, incaltaminte',
      inStock: true,
      country: 'ro',
      price: '9.90',
      service: '  Curier  ',
      condition: 'refurbished',
    }));
    assert.deepStrictEqual(payload, {
      name: 'Feed principal',
      channel: 'google',
      type: 'standard',
      format: 'xml',
      config: {
        scope: 'filtered',
        categories: ['accesorii', 'incaltaminte'],
        in_stock_only: true,
        csv_excel_safe: false,
        exclude_invalid: true,
        refresh: 'manual',
        google: {
          shipping_country: 'RO',
          shipping_price: '9.90',
          shipping_service: 'Curier',
          condition: 'refurbished',
          default_category: '',
          category_map: {},
        },
      },
    });
  });
  await test('create → GET → edit → PATCH preserves category, Excel safety, invalid policy, and mappings', () => {
    const created = buildPayload(valid({
      channel: 'meta', format: 'csv', brand: 'ADC Shop', defaultCategory: 'Apparel',
      csvExcelSafe: true, excludeInvalid: false,
      params: [{ field: 'title', source: { type: 'native', value: 'name' }, transforms: [], enabled: true }],
    }), [
      { id: 'meta', label: 'Meta', available: true, formats: ['csv', 'xml'] },
    ]);
    const getFeed = {
      id: 12, name: created.name, channel: created.channel, format: created.format,
      config: Object.assign({}, created.config, { params: created.params }), params: created.params,
    };
    const wizard = wizardFromFeed(getFeed, [{ id: 'meta', label: 'Meta', available: true, formats: ['csv', 'xml'] }]);
    wizard.params = getFeed.params;
    const patch = buildPatchPayload(wizard, [{ id: 'meta', label: 'Meta', available: true, formats: ['csv', 'xml'] }]);
    assert.strictEqual(wizard.brand, 'ADC Shop');
    assert.strictEqual(patch.config.meta.default_category, 'Apparel');
    assert.strictEqual(patch.config.csv_excel_safe, true);
    assert.strictEqual(patch.config.exclude_invalid, false);
    assert.deepStrictEqual(patch.params, created.params);
    assert.strictEqual(patch.channel, undefined);
    assert.strictEqual(patch.format, undefined);
  });

  await test('buildPayload sends Smart rule and automatic editable label mapping', () => {
    const state = valid({ scope: 'smart', smartMetric: 'purchases', smartMode: 'top_n', smartDays: 30, smartLimit: 50,
      refresh: 'daily', params: [], fields: [{ key: 'custom_label_0' }] });
    const payload = buildPayload(state);
    assert.deepStrictEqual(payload.config.smart_rule, { metric: 'purchases', mode: 'top_n', period_days: 30, limit: 50 });
    assert.strictEqual(payload.config.refresh, 'daily');
    assert.strictEqual(payload.params[0].field, 'custom_label_0');
    assert.strictEqual(payload.params[0].source.type, 'constant');
    assert.strictEqual(payload.params[0].source.value, 'purchases_30d');
  });
  await test('buildPayload drops categories when scope is all and sends exclude_invalid default', () => {
    const payload = buildPayload(valid({ scope: 'all', categories: 'x,y' }));
    assert.deepStrictEqual(payload.config.categories, []);
    assert.strictEqual(payload.config.exclude_invalid, true);
    assert.strictEqual(buildPayload(valid({ excludeInvalid: false })).config.exclude_invalid, false);
  });
  await test('validateAll reports the first failing step too and duplicate messages render once', () => {
    assert.strictEqual(validateAll(valid({ price: 'abc', scope: 'filtered', categories: '' })).length, 2);
    assert.deepStrictEqual(validateAll(valid()), []);
    assert.deepStrictEqual(feeds.uniqueMessages(['aceeași eroare', 'aceeași eroare', 'altă eroare']), ['aceeași eroare', 'altă eroare']);
  });

  // --- Error messages ---------------------------------------------------------
  await test('messageFor: network, expired, unavailable and server message', () => {
    assert.match(messageFor(0, undefined), /conexiunea/);
    assert.match(messageFor(403, null), /Sesiunea a expirat/);
    assert.match(messageFor(503, null), /nu este disponibil/);
    assert.strictEqual(messageFor(400, { message: 'Numele feedului este obligatoriu.' }), 'Numele feedului este obligatoriu.');
    assert.match(messageFor(500, null), /eroare/);
  });

  // --- REST client ------------------------------------------------------------
  await test('apiRequest sends the nonce, method and JSON body', async () => {
    let seen = null;
    const fetchMock = (url, init) => { seen = { url, init }; return Promise.resolve(response(200, { ok: 1 })); };
    const data = await apiRequest(fetchMock, '/wp-json/adc/v1/feeds', 'POST', 'NONCE-1', { name: 'x' });
    assert.deepStrictEqual(data, { ok: 1 });
    assert.strictEqual(seen.url, '/wp-json/adc/v1/feeds');
    assert.strictEqual(seen.init.method, 'POST');
    assert.strictEqual(seen.init.headers['X-WP-Nonce'], 'NONCE-1');
    assert.strictEqual(seen.init.headers['Content-Type'], 'application/json');
    assert.strictEqual(seen.init.body, JSON.stringify({ name: 'x' }));
    assert.strictEqual(seen.init.credentials, 'same-origin');
  });
  await test('apiRequest GET has no body or content type', async () => {
    let seen = null;
    await apiRequest((url, init) => { seen = init; return Promise.resolve(response(200, { items: [] })); }, '/x', 'GET', 'n');
    assert.strictEqual(seen.body, undefined);
    assert.strictEqual(seen.headers['Content-Type'], undefined);
  });
  await test('apiRequest rejects with the REST message and status', async () => {
    const fetchMock = () => Promise.resolve(response(400, { code: 'adc_feed_invalid_name', message: 'Numele feedului este obligatoriu.' }));
    await assert.rejects(apiRequest(fetchMock, '/x', 'POST', 'n', {}), (err) => {
      assert.strictEqual(err.message, 'Numele feedului este obligatoriu.');
      assert.strictEqual(err.status, 400);
      return true;
    });
  });
  await test('apiRequest: non-JSON 503 becomes the generic unavailable message', async () => {
    const fetchMock = () => Promise.resolve(response(503));
    await assert.rejects(apiRequest(fetchMock, '/x', 'GET', 'n'), (err) => {
      assert.match(err.message, /nu este disponibil/);
      assert.strictEqual(err.status, 503);
      return true;
    });
  });
  await test('apiRequest: fetch failure becomes a network error (status 0)', async () => {
    const fetchMock = () => Promise.reject(new TypeError('Failed to fetch'));
    await assert.rejects(apiRequest(fetchMock, '/x', 'GET', 'n'), (err) => {
      assert.match(err.message, /conexiunea/);
      assert.strictEqual(err.status, 0);
      return true;
    });
  });

  // --- Copy URL -------------------------------------------------------------
  await test('copyText resolves true when the clipboard accepts the text', async () => {
    let copied = null;
    const ok = await copyText('https://example.test/adc-feed/abc.xml', { writeText: (t) => { copied = t; return Promise.resolve(); } });
    assert.strictEqual(ok, true);
    assert.strictEqual(copied, 'https://example.test/adc-feed/abc.xml');
  });
  await test('copyText resolves false when the clipboard rejects or is missing', async () => {
    assert.strictEqual(await copyText('x', { writeText: () => Promise.reject(new Error('denied')) }), false);
    assert.strictEqual(await copyText('x', undefined), false);
  });

  await test('statusLabel and channelLabel use Romanian labels', () => {
    assert.strictEqual(statusLabel('active'), 'Activ');
    assert.strictEqual(statusLabel('disabled'), 'Dezactivat');
    assert.strictEqual(feeds.channelLabel('google'), 'Google Shopping');
    assert.strictEqual(feeds.CHANNELS.filter((c) => c.available).length, 3);
  });

  // --- Meta and TikTok ------------------------------------------------------
  const channels = [
    { id: 'google', label: 'Google Shopping', available: true, formats: ['xml'] },
    { id: 'meta', label: 'Meta', available: true, formats: ['csv', 'xml'] },
    { id: 'tiktok', label: 'TikTok', available: true, formats: ['csv', 'xml'] },
    { id: 'olx', label: 'OLX', available: false, formats: [] },
  ];
  const meta = (overrides) => valid(Object.assign({ channel: 'meta', format: 'csv', brand: 'ADC Shop' }, overrides || {}));

  await test('step 3 meta: brand and condition are required, price is not asked', () => {
    assert.deepStrictEqual(validateStep(meta({ price: 'abc', country: 'XX' }), 3, channels), []);
    assert.strictEqual(validateStep(meta({ brand: '   ' }), 3, channels).length, 1);
    assert.strictEqual(validateStep(meta({ condition: 'broken' }), 3, channels).length, 1);
  });
  await test('step 3 meta: at most 5 internal labels, each at most 110 characters', () => {
    assert.deepStrictEqual(validateStep(meta({ labels: 'vara, promo, nou, top, 2026' }), 3, channels), []);
    assert.strictEqual(validateStep(meta({ labels: 'a,b,c,d,e,f' }), 3, channels).length, 1);
    assert.strictEqual(validateStep(meta({ labels: 'x'.repeat(111) }), 3, channels).length, 1);
  });
  await test('step 3 tiktok: brand required, no labels allowed to block it', () => {
    const tiktok = valid({ channel: 'tiktok', format: 'csv', brand: 'ADC', labels: 'a,b,c,d,e,f,g' });
    assert.deepStrictEqual(validateStep(tiktok, 3, channels), []);
    assert.strictEqual(validateStep(Object.assign({}, tiktok, { brand: '' }), 3, channels).length, 1);
  });
  await test('parseInternalLabels: trimmed, lowercase, unique, no empties', () => {
    assert.deepStrictEqual(feeds.parseInternalLabels(' Vara, ,VARA,#Promo '), ['vara', '#promo']);
    assert.deepStrictEqual(feeds.parseInternalLabels(undefined), []);
  });
  await test('buildPayload meta: only the meta block, labels parsed, csv_excel_safe on csv', () => {
    const payload = buildPayload(meta({ labels: 'Vara, promo', condition: 'new', csvExcelSafe: true }), channels);
    assert.strictEqual(payload.channel, 'meta');
    assert.strictEqual(payload.format, 'csv');
    assert.deepStrictEqual(payload.config.meta, { condition: 'new', brand: 'ADC Shop', internal_labels: ['vara', 'promo'], default_category: '', category_map: {} });
    assert.strictEqual(payload.config.google, undefined);
    assert.strictEqual(payload.config.tiktok, undefined);
    assert.strictEqual(payload.config.csv_excel_safe, true);
  });
  await test('buildPayload: csv_excel_safe is forced to false for a non-csv format', () => {
    const payload = buildPayload(meta({ format: 'xml', csvExcelSafe: true }), channels);
    assert.strictEqual(payload.config.csv_excel_safe, false);
  });
  await test('buildPayload tiktok: only the tiktok block', () => {
    const payload = buildPayload(valid({ channel: 'tiktok', format: 'csv', brand: 'ADC', condition: 'used' }), channels);
    assert.deepStrictEqual(payload.config.tiktok, { condition: 'used', brand: 'ADC', default_category: '', category_map: {} });
    assert.strictEqual(payload.config.meta, undefined);
    assert.strictEqual(payload.config.google, undefined);
  });
  await test('parseChannels: reads the server list, rejects junk', () => {
    const raw = JSON.stringify([{ id: 'meta', label: 'Meta', available: true, formats: ['csv', 'xml'] }, { id: 'olx', label: 'OLX', available: false, formats: [] }]);
    const parsed = feeds.parseChannels(raw);
    assert.strictEqual(parsed.length, 2);
    assert.strictEqual(parsed[0].available, true);
    assert.deepStrictEqual(parsed[0].formats, ['csv', 'xml']);
    assert.strictEqual(feeds.parseChannels('{"not":"a list"}'), null);
    assert.strictEqual(feeds.parseChannels('nope'), null);
    assert.strictEqual(feeds.parseChannels('[]'), null);
  });
  await test('formatLabel and channelLabel with a server list', () => {
    assert.strictEqual(feeds.formatLabel('csv'), 'CSV');
    assert.strictEqual(feeds.formatLabel('xml'), 'XML (RSS)');
    assert.strictEqual(feeds.formatLabel('json'), 'JSON');
    assert.strictEqual(feeds.channelLabel('tiktok', channels), 'TikTok');
  });

  // --- Categories: default and per WooCommerce category -------------------------
  await test('parseCategoryList: keeps integer ids and names, drops junk', () => {
    assert.deepStrictEqual(feeds.parseCategoryList('[{"id":12,"name":"Pantofi"},{"id":"x","name":"y"},{"id":0,"name":"z"}]'), [{ id: 12, name: 'Pantofi' }]);
    assert.deepStrictEqual(feeds.parseCategoryList('nope'), []);
    assert.deepStrictEqual(feeds.parseCategoryList(null), []);
  });
  await test('normalize: empty map values are dropped, values trimmed', () => {
    const n = feeds.normalize(valid({ categoryMap: { 12: '  Pantofi sport ', 15: '   ', 18: '' } }));
    assert.deepStrictEqual(n.categoryMap, { 12: 'Pantofi sport' });
  });
  await test('step 3: default and mapped categories are limited to 750 characters', () => {
    assert.deepStrictEqual(validateStep(valid({ defaultCategory: 'a'.repeat(750) }), 3, channels), []);
    assert.strictEqual(validateStep(valid({ defaultCategory: 'a'.repeat(751) }), 3, channels).length, 1);
    assert.strictEqual(validateStep(valid({ categoryMap: { 7: 'b'.repeat(751) } }), 3, channels).length, 1);
  });
  await test('buildPayload: default category and map go into the chosen block only', () => {
    const payload = buildPayload(valid({ channel: 'meta', format: 'csv', brand: 'ADC', defaultCategory: ' Apparel ', categoryMap: { 7: 'Shoes', 8: '' } }), channels);
    assert.deepStrictEqual(payload.config.meta.default_category, 'Apparel');
    assert.deepStrictEqual(payload.config.meta.category_map, { 7: 'Shoes' });
    assert.strictEqual(payload.config.google, undefined);
  });

  // --- Parameter table -----------------------------------------------------------
  const fields = [
    { key: 'title', label: 'Titlu', required: true, help: 'Titlul produsului', default_source: { type: 'native', value: 'name' } },
    { key: 'color', label: 'Culoare', required: false, help: 'Culoarea', default_source: null },
    { key: 'gender', label: 'Gen', required: false, help: 'Genul', default_source: null },
  ];
  const defaults = [{ field: 'title', source: { type: 'native', value: 'name' }, transforms: [{ op: 'trim', arg: '' }], enabled: true }];

  await test('mergeSourceLists: REST native registry overrides fallback; missing registry keeps fallback', () => {
    const fallback = { native: [{ value: 'name', label: 'Legacy name' }], attribute: [], taxonomy: [] };
    assert.deepStrictEqual(feeds.mergeSourceLists(fallback, { sources: { native: [{ value: 'title', label: 'Titlu' }] } }).native,
      [{ value: 'title', label: 'Titlu' }]);
    assert.deepStrictEqual(feeds.mergeSourceLists(fallback, {}).native, fallback.native);
  });
  await test('CSV spreadsheet protection is enabled by default in the wizard', () => {
    assert.strictEqual(emptyWizard().csvExcelSafe, true);
    assert.strictEqual(feeds.buildPayload(valid(), channels).config.csv_excel_safe, false, 'non-CSV formats still send false');
  });
  await test('parseSourceLists: keeps value/label pairs per kind, drops junk', () => {
    const parsed = feeds.parseSourceLists(JSON.stringify({ native: [{ value: 'name', label: 'Nume' }, { value: '' }], attribute: [{ value: 'pa_culoare', label: 'Culoare' }], taxonomy: 'x' }));
    assert.deepStrictEqual(parsed.native, [{ value: 'name', label: 'Nume' }]);
    assert.deepStrictEqual(parsed.attribute, [{ value: 'pa_culoare', label: 'Culoare' }]);
    assert.deepStrictEqual(parsed.taxonomy, []);
    assert.deepStrictEqual(feeds.parseSourceLists('nope'), { native: [], attribute: [], taxonomy: [] });
  });
  await test('sourceChoices: lists for native, attribute and taxonomy; free text otherwise', () => {
    const lists = { native: [{ value: 'name', label: 'Nume' }], attribute: [], taxonomy: [{ value: 'product_cat', label: 'Categorii' }] };
    assert.strictEqual(feeds.sourceChoices('native', lists).length, 1);
    assert.strictEqual(feeds.sourceChoices('attribute', lists), null);
    assert.strictEqual(feeds.sourceChoices('meta', lists), null);
  });
  await test('initialParams: defaults kept, a required field without a row is added and enabled', () => {
    const rows = feeds.initialParams([], fields);
    assert.deepStrictEqual(rows.map((r) => r.field), ['title']);
    assert.strictEqual(rows[0].enabled, true);
    assert.deepStrictEqual(rows[0].source, { type: 'native', value: 'name' });
    const withDefaults = feeds.initialParams(defaults.map((d) => Object.assign({}, d, { enabled: false })), fields);
    assert.strictEqual(withDefaults[0].enabled, true, 'required rows are always enabled');
  });
  await test('fieldOptions: fields used by other rows are disabled, the own field is not', () => {
    const rows = [{ field: 'title' }, { field: 'color' }];
    const options = feeds.fieldOptions(fields, rows, 'color');
    assert.strictEqual(options.find((o) => o.key === 'title').disabled, true);
    assert.strictEqual(options.find((o) => o.key === 'color').disabled, false);
    assert.strictEqual(options.find((o) => o.key === 'gender').disabled, false);
  });
  await test('validateParams: required field must be present and enabled; sources and transforms checked', () => {
    assert.deepStrictEqual(feeds.validateParams(feeds.initialParams(defaults, fields), fields), []);
    assert.strictEqual(feeds.validateParams([], fields).length, 1, 'missing required field');
    assert.strictEqual(feeds.validateParams([{ field: 'title', source: { type: 'native', value: 'name' }, transforms: [], enabled: false }], fields).length, 1, 'disabled required field');
    assert.strictEqual(feeds.validateParams([{ field: 'title', source: { type: 'native', value: '  ' }, transforms: [], enabled: true }], fields).length, 1, 'empty source value');
    assert.strictEqual(feeds.validateParams([{ field: 'title', source: { type: 'sql', value: 'x' }, transforms: [], enabled: true }], fields).length, 1, 'unknown source type');
    assert.strictEqual(feeds.validateParams([{ field: 'title', source: { type: 'native', value: 'name' }, transforms: [{ op: 'eval', arg: '' }], enabled: true }], fields).length, 1, 'unknown transform');
    assert.deepStrictEqual(feeds.validateParams([{ field: 'title', source: { type: 'category_map', value: '' }, transforms: [], enabled: true }], fields), [], 'category_map needs no value');
  });
  await test('normalizeParams: values trimmed, rows without a field dropped', () => {
    const out = feeds.normalizeParams([
      { field: 'color', source: { type: 'attribute', value: ' pa_culoare ' }, transforms: [{ op: 'prefix', arg: ' x ' }], enabled: false },
      { field: '', source: { type: 'native', value: 'name' }, transforms: [], enabled: true },
    ]);
    assert.deepStrictEqual(out, [{ field: 'color', source: { type: 'attribute', value: 'pa_culoare' }, transforms: [{ op: 'prefix', arg: 'x' }], enabled: false }]);
  });
  await test('buildPayload: params only when the table is loaded; validateStep reports them', () => {
    const loaded = valid({ params: feeds.initialParams(defaults, fields), fields: fields });
    assert.deepStrictEqual(feeds.normalizeParams(loaded.params)[0].field, 'title');
    assert.strictEqual(buildPayload(loaded, channels).params.length, 1);
    assert.strictEqual(buildPayload(valid(), channels).params, undefined);
    assert.strictEqual(validateStep(valid({ params: [], fields: fields }), 3, channels).length, 1, 'required field missing is a step-3 error');
  });
  await test('apiRequest: rejected request keeps the error codes of the body', async () => {
    const fetchMock = () => Promise.resolve(response(422, { code: 'adc_feed_invalid_params', message: 'Parametrii feedului nu sunt valizi.', data: { status: 422, errors: ['title_missing'] } }));
    await assert.rejects(apiRequest(fetchMock, '/x', 'POST', 'n', {}), (err) => {
      assert.deepStrictEqual(err.errors, ['title_missing']);
      return true;
    });
  });

  // --- Last run and diagnostics ----------------------------------------------
  await test('formatRun: no run gives null (the card shows "Nicio rulare încă")', () => {
    assert.strictEqual(feeds.formatRun(null), null);
    assert.strictEqual(feeds.formatRun(undefined), null);
  });
  await test('formatRun: status labels and tones', () => {
    assert.strictEqual(feeds.formatRun({ status: 'success' }).tone, 'ok');
    assert.strictEqual(feeds.formatRun({ status: 'complete' }).statusLabel, 'Finalizată');
    assert.strictEqual(feeds.formatRun({ status: 'complete' }).tone, 'ok');
    assert.strictEqual(feeds.formatRun({ status: 'failed' }).statusLabel, 'Eșuată');
    assert.strictEqual(feeds.formatRun({ status: 'failed' }).tone, 'error');
    assert.strictEqual(feeds.formatRun({ status: 'weird' }).statusLabel, 'Necunoscută');
    assert.strictEqual(feeds.formatRun({ status: 'weird' }).tone, 'neutral');
  });
  await test('formatRun: counters are numbers, missing counters become 0', () => {
    const view = feeds.formatRun({ status: 'success', examined: '12', exported: 9, started_at: '2026-11-07 10:00:00' });
    assert.strictEqual(view.examined, 12);
    assert.strictEqual(view.exported, 9);
    assert.strictEqual(view.startedAt, '2026-11-07 10:00');
    assert.strictEqual(feeds.formatRun({ status: 'success' }).exported, 0);
  });
  await test('formatRun: at most 10 errors and 10 warnings shown, the rest counted', () => {
    const many = Array.from({ length: 12 }, (_, i) => ({ code: 'code_' + i, count: 1 }));
    const view = feeds.formatRun({ status: 'failed', errors: many, warnings: many.slice(0, 3) });
    assert.strictEqual(feeds.MAX_MESSAGES, 10);
    assert.strictEqual(view.errors.length, 10);
    assert.strictEqual(view.errorsHidden, 2);
    assert.strictEqual(view.warnings.length, 3);
    assert.strictEqual(view.warningsHidden, 0);
  });
  await test('limitItems tolerates a missing or non-array list', () => {
    assert.deepStrictEqual(feeds.limitItems(undefined, 10), { shown: [], hidden: 0 });
    assert.deepStrictEqual(feeds.limitItems('x', 10), { shown: [], hidden: 0 });
    assert.deepStrictEqual(feeds.limitItems(['a', 'b', 'c'], 2), { shown: ['a', 'b'], hidden: 1 });
  });
  await test('formatRun: rows get their Romanian label from the localized map', () => {
    const labels = { feed_busy: 'Feedul este ocupat.' };
    const view = feeds.formatRun({ status: 'failed', errors: [{ code: 'feed_busy', count: 2 }] }, labels);
    assert.deepStrictEqual(view.errors, [{ code: 'feed_busy', count: 2, label: 'Feedul este ocupat.' }]);
  });
  await test('diagnosticLabel: unknown code falls back to a text that names the code', () => {
    assert.strictEqual(feeds.diagnosticLabel('brand_new_code', {}), 'Diagnostic necunoscut (brand_new_code)');
    assert.strictEqual(feeds.diagnosticLabel('feed_busy', { feed_busy: '' }), 'Diagnostic necunoscut (feed_busy)');
    assert.strictEqual(feeds.diagnosticLabel('feed_busy', undefined), 'Diagnostic necunoscut (feed_busy)');
  });
  await test('diagnosticRows: one row per code with summed counts', () => {
    const rows = feeds.diagnosticRows([
      { code: 'gtin_invalid_length', count: 2 },
      { code: 'gtin_invalid_length', count: 3 },
      { code: 'title_missing' },
    ], {});
    assert.deepStrictEqual(rows.map((r) => [r.code, r.count]), [['gtin_invalid_length', 5], ['title_missing', 1]]);
  });
  await test('diagnosticRows: codes that are not identifiers (paths, markup, numbers) are dropped', () => {
    const rows = feeds.diagnosticRows([
      { code: '/var/www/x.xml', count: 1 },
      { code: '<b>x</b>', count: 1 },
      { code: 'ok_code', count: 1 },
      'plain string',
      null,
    ], {});
    assert.deepStrictEqual(rows.map((r) => r.code), ['ok_code']);
  });
  await test('diagnosticRows: codes named like Object prototype members do not collide', () => {
    const rows = feeds.diagnosticRows([{ code: 'constructor', count: 1 }, { code: 'constructor', count: 1 }], {});
    assert.strictEqual(rows.length, 1);
    assert.strictEqual(rows[0].count, 2);
    assert.match(rows[0].label, /necunoscut/);
  });
  await test('parseLabels: reads a JSON object and falls back to {} on bad input', () => {
    assert.deepStrictEqual(feeds.parseLabels('{"feed_busy":"Ocupat"}'), { feed_busy: 'Ocupat' });
    assert.deepStrictEqual(feeds.parseLabels('not json'), {});
    assert.deepStrictEqual(feeds.parseLabels('[1,2]'), {});
    assert.deepStrictEqual(feeds.parseLabels(null), {});
  });

  await test('runStatusLabel maps known statuses and falls back for unknown ones', () => {
    assert.strictEqual(feeds.runStatusLabel('running'), 'În curs');
    assert.strictEqual(feeds.runStatusLabel('toString'), 'Necunoscută');
  });

  // --- Progress polling: fake clock, no real timers ---------------------------
  const flush = () => new Promise((resolve) => setImmediate(resolve));

  // Timers are a Map; advance() fires due timers in time order and lets promise chains settle.
  function fakeClock() {
    let now = 0;
    let nextHandle = 1;
    const timers = new Map();
    return {
      now: () => now,
      setTimer: (fn, ms) => { const h = nextHandle++; timers.set(h, { fn, at: now + ms }); return h; },
      clearTimer: (h) => { timers.delete(h); },
      pending: () => timers.size,
      async advance(ms) {
        const end = now + ms;
        for (;;) {
          let next = null;
          for (const entry of timers) {
            if (entry[1].at <= end && (!next || entry[1].at < next[1].at)) next = entry;
          }
          if (!next) break;
          timers.delete(next[0]);
          now = next[1].at;
          next[1].fn();
          await flush();
        }
        now = end;
      },
    };
  }

  // responses[n-1] answers the n-th list read; the last entry repeats.
  function pollHarness(responses, opts) {
    const clock = fakeClock();
    const log = { fetches: 0, updates: [], errors: [] };
    let active = true;
    const poller = feeds.createRunPoller({
      now: clock.now,
      setTimer: clock.setTimer,
      clearTimer: clock.clearTimer,
      fetchList: () => {
        log.fetches++;
        const n = log.fetches;
        return Promise.resolve().then(() => {
          const answer = responses[Math.min(n, responses.length) - 1];
          if (answer instanceof Error) throw answer;
          return answer;
        });
      },
      isActive: () => active,
      onUpdate: (list) => log.updates.push(list),
      onError: (err) => log.errors.push(err.message),
      windowMs: opts && opts.windowMs,
    });
    return { clock, log, poller, setActive: (v) => { active = v; } };
  }

  const runOf = (status, startedAt, examined, exported) => ({
    status: status, started_at: startedAt, examined: examined, exported: exported,
  });
  const STARTED_A = '2026-11-11 10:00:00';
  const STARTED_B = '2026-11-11 10:05:00';
  const feedWith = (id, run) => ({ id: id, name: 'Feed', last_run: run });

  await test('poller constants: window covers the 30 s debounce, interval is shorter than the window', () => {
    assert.ok(feeds.POLL_WINDOW_MS >= 40000, 'window must cover debounce plus slack');
    assert.ok(feeds.POLL_INTERVAL_MS > 0 && feeds.POLL_INTERVAL_MS < feeds.POLL_WINDOW_MS);
    assert.ok(feeds.RUNNING_CAP_MS > feeds.POLL_WINDOW_MS);
  });

  await test('poller: idle list (no running run, no request) schedules nothing', () => {
    const h = pollHarness([[feedWith(1, runOf('success', STARTED_A, 4, 4))]]);
    h.poller.observe([feedWith(1, runOf('success', STARTED_A, 4, 4))]);
    assert.strictEqual(h.clock.pending(), 0);
    assert.strictEqual(h.poller.isPolling(), false);
  });

  await test('poller: running feed is polled each interval, redraws only on change, stops and clears its timer on finish', async () => {
    const h = pollHarness([
      [feedWith(7, runOf('running', STARTED_A, 5, 3))],   // unchanged: no redraw
      [feedWith(7, runOf('running', STARTED_A, 6, 4))],   // counts changed: redraw
      [feedWith(7, runOf('success', STARTED_A, 9, 9))],   // finished: stop
    ]);
    h.poller.observe([feedWith(7, runOf('running', STARTED_A, 5, 3))]);
    assert.strictEqual(h.clock.pending(), 1);
    await h.clock.advance(feeds.POLL_INTERVAL_MS * 3);
    assert.strictEqual(h.log.fetches, 3);
    assert.strictEqual(h.log.updates.length, 2);
    assert.strictEqual(h.log.updates[0][0].last_run.examined, 6);
    assert.strictEqual(h.log.updates[1][0].last_run.status, 'success');
    assert.strictEqual(h.clock.pending(), 0, 'no timer left after the run finished');
    assert.strictEqual(h.poller.isPolling(), false);
  });

  await test('poller: after a regeneration request, waits for a new run and stops once it finishes', async () => {
    const h = pollHarness([
      [feedWith(3, runOf('success', STARTED_A, 2, 2))],   // not started yet
      [feedWith(3, runOf('running', STARTED_B, 0, 0))],   // new run started
      [feedWith(3, runOf('success', STARTED_B, 5, 5))],   // new run finished
    ]);
    h.poller.observe([feedWith(3, runOf('success', STARTED_A, 2, 2))]);
    h.poller.request(feedWith(3, runOf('success', STARTED_A, 2, 2)));
    await h.clock.advance(feeds.POLL_INTERVAL_MS * 3);
    assert.strictEqual(h.log.fetches, 3);
    assert.strictEqual(h.log.updates.length, 2);
    assert.strictEqual(h.clock.pending(), 0);
  });

  await test('poller: a request that never changes the run stops at the window and leaves no timer', async () => {
    const same = [feedWith(4, runOf('success', STARTED_A, 1, 1))];
    const h = pollHarness([same], { windowMs: 12000 });
    h.poller.observe(same); // as after a list load; sets the baseline signature
    h.poller.request(same[0]);
    await h.clock.advance(60000);
    const fetchesAtStop = h.log.fetches;
    assert.ok(fetchesAtStop >= 3 && fetchesAtStop <= 5, 'polls for the window only, got ' + fetchesAtStop);
    assert.strictEqual(h.clock.pending(), 0);
    await h.clock.advance(60000);
    assert.strictEqual(h.log.fetches, fetchesAtStop, 'no fetch after the window');
    assert.strictEqual(h.log.updates.length, 0, 'unchanged list never redraws');
  });

  await test('poller: four consecutive failed reads (three retries at 3/6/12 s) report once, stop, and leave no timer', async () => {
    const h = pollHarness([
      [feedWith(5, runOf('running', STARTED_A, 1, 0))],
      new Error('Rețeaua nu răspunde.'),
    ]);
    assert.deepStrictEqual(feeds.POLL_RETRY_DELAYS_MS, [3000, 6000, 12000]);
    h.poller.observe([feedWith(5, runOf('running', STARTED_A, 1, 0))]);
    // Timeline: read 1 at 3 s (running), failures at 6 s, 9 s, 15 s and 27 s; the last one is final.
    await h.clock.advance(60000);
    assert.deepStrictEqual(h.log.errors, ['Rețeaua nu răspunde.']);
    assert.strictEqual(h.log.fetches, 5, 'one good read plus four failed reads, no more');
    assert.strictEqual(h.clock.pending(), 0);
    await h.clock.advance(60000);
    assert.strictEqual(h.log.fetches, 5, 'no fetch after the final failure');
    assert.strictEqual(h.log.errors.length, 1, 'notice shown once');
  });

  await test('poller: transient failures recover, reset the count, keep the poll alive, and show no notice', async () => {
    const h = pollHarness([
      [feedWith(5, runOf('running', STARTED_A, 1, 0))],   // 3 s: unchanged
      new Error('a'),                                    // 6 s: failure 1, retry in 3 s
      new Error('b'),                                    // 9 s: failure 2, retry in 6 s
      [feedWith(5, runOf('running', STARTED_A, 2, 0))],  // 15 s: recovered, redraw, poll every 3 s
      new Error('c'),                                    // 18 s: failure 1 again (count was reset)
      new Error('d'),                                    // 21 s: failure 2
      new Error('e'),                                    // 27 s: failure 3, retry in 12 s
      new Error('f'),                                    // 39 s: failure 4, final
    ]);
    h.poller.observe([feedWith(5, runOf('running', STARTED_A, 1, 0))]);
    await h.clock.advance(60000);
    assert.strictEqual(h.log.fetches, 8);
    assert.strictEqual(h.log.updates.length, 1, 'recovered read is applied once');
    assert.deepStrictEqual(h.log.errors, ['f'], 'only the final failure is reported');
    assert.strictEqual(h.clock.pending(), 0);
  });

  await test('poller: a transient failure keeps a pending regeneration request alive until its run finishes', async () => {
    const same = feedWith(12, runOf('success', STARTED_A, 2, 2));
    const h = pollHarness([
      new Error('offline'),                                         // failure, retry in 3 s
      [feedWith(12, runOf('running', STARTED_B, 0, 0))],           // new run started after recovery
      [feedWith(12, runOf('success', STARTED_B, 3, 3))],           // finished
    ]);
    h.poller.observe([same]);
    h.poller.request(same);
    assert.strictEqual(h.clock.pending(), 1);
    await h.clock.advance(feeds.POLL_INTERVAL_MS);      // 3 s: failure
    assert.strictEqual(h.log.errors.length, 0, 'no notice for a retried failure');
    assert.strictEqual(h.clock.pending(), 1, 'retry timer scheduled');
    await h.clock.advance(feeds.POLL_RETRY_DELAYS_MS[0] + feeds.POLL_INTERVAL_MS * 2);
    assert.strictEqual(h.log.fetches, 3);
    assert.strictEqual(h.log.updates.length, 2);
    assert.strictEqual(h.clock.pending(), 0, 'request resolved and no timer left');
  });

  await test('poller: stop() during a retry wait clears the retry timer and nothing fires afterwards', async () => {
    const h = pollHarness([[feedWith(13, runOf('running', STARTED_A, 1, 0))], new Error('x')]);
    h.poller.observe([feedWith(13, runOf('running', STARTED_A, 1, 0))]);
    await h.clock.advance(feeds.POLL_INTERVAL_MS * 2); // first failure, retry timer pending
    assert.strictEqual(h.clock.pending(), 1);
    h.poller.stop();
    assert.strictEqual(h.clock.pending(), 0);
    const fetchesAtStop = h.log.fetches;
    await h.clock.advance(120000);
    assert.strictEqual(h.log.fetches, fetchesAtStop);
    assert.strictEqual(h.log.errors.length, 0, 'teardown is silent');
  });

  await test('poller: when the list is no longer shown, the next tick stops without fetching or redrawing', async () => {
    const h = pollHarness([[feedWith(6, runOf('running', STARTED_A, 1, 0))]]);
    h.poller.observe([feedWith(6, runOf('running', STARTED_A, 1, 0))]);
    h.setActive(false);
    await h.clock.advance(feeds.POLL_INTERVAL_MS * 2);
    assert.strictEqual(h.log.fetches, 0);
    assert.strictEqual(h.log.updates.length, 0);
    assert.strictEqual(h.clock.pending(), 0);
  });

  await test('poller: a superseded (null) response neither redraws nor schedules a timer', async () => {
    const h = pollHarness([[feedWith(8, runOf('running', STARTED_A, 1, 0))], null]);
    h.poller.observe([feedWith(8, runOf('running', STARTED_A, 1, 0))]);
    await h.clock.advance(feeds.POLL_INTERVAL_MS * 2);
    assert.strictEqual(h.log.updates.length, 0);
    assert.strictEqual(h.clock.pending(), 0);
  });

  await test('poller: stop() clears a pending timer immediately', async () => {
    const h = pollHarness([[feedWith(9, runOf('running', STARTED_A, 1, 0))]]);
    h.poller.observe([feedWith(9, runOf('running', STARTED_A, 1, 0))]);
    assert.strictEqual(h.clock.pending(), 1);
    h.poller.stop();
    assert.strictEqual(h.clock.pending(), 0);
    await h.clock.advance(feeds.POLL_INTERVAL_MS * 2);
    assert.strictEqual(h.log.fetches, 0);
  });

  await test('poller: a run that stays "running" past the cap stops polling instead of looping forever', async () => {
    const h = pollHarness([[feedWith(10, runOf('running', STARTED_A, 1, 0))]]);
    h.poller.observe([feedWith(10, runOf('running', STARTED_A, 1, 0))]);
    await h.clock.advance(feeds.RUNNING_CAP_MS + feeds.POLL_INTERVAL_MS);
    assert.strictEqual(h.clock.pending(), 0);
    const fetchesAtStop = h.log.fetches;
    await h.clock.advance(feeds.POLL_INTERVAL_MS * 5);
    assert.strictEqual(h.log.fetches, fetchesAtStop);
  });

  // --- Google taxonomy picker: URLs, bounded file read, selection, search -------
  await test('taxonomySearchUrl: trims, encodes, sends limit 50, and returns null for an empty query', () => {
    assert.strictEqual(feeds.taxonomySearchUrl('/r', '   '), null);
    assert.strictEqual(feeds.taxonomySearchUrl('/r', ' rochii & pantofi '), '/r/taxonomy?q=rochii%20%26%20pantofi&limit=50');
  });

  await test('taxonomySearchUrl: query is clamped to the 400-byte server limit without splitting characters', () => {
    const url = feeds.taxonomySearchUrl('/r', 'ș'.repeat(300));
    const q = decodeURIComponent(url.split('?q=')[1].split('&')[0]);
    assert.ok(feeds.utf8Length(q) <= feeds.TAX_QUERY_MAX_BYTES, 'clamped to 400 bytes');
    assert.ok(q.length > 0 && [...q].every((ch) => ch === 'ș'), 'no broken characters');
  });

  await test('readTaxonomyFile: oversized file is refused before any FileReader is created', async () => {
    let created = 0;
    class Spy { constructor() { created++; } }
    await assert.rejects(feeds.readTaxonomyFile({ size: feeds.TAX_MAX_BYTES + 1 }, Spy), (err) => err.code === 'too_large');
    assert.strictEqual(created, 0);
  });

  await test('readTaxonomyFile: missing file, missing FileReader and read errors are coded failures', async () => {
    await assert.rejects(feeds.readTaxonomyFile(null, function () {}), (err) => err.code === 'no_file');
    await assert.rejects(feeds.readTaxonomyFile({ size: 5 }, undefined), (err) => err.code === 'unsupported');
    class Broken { readAsText() { setImmediate(() => this.onerror()); } }
    await assert.rejects(feeds.readTaxonomyFile({ size: 5 }, Broken), (err) => err.code === 'read_failed');
  });

  await test('readTaxonomyFile: reads the text of a file within the bound', async () => {
    class Ok { readAsText(file, enc) { this.result = 'taxonomy ' + file.name + ' ' + enc; setImmediate(() => this.onload()); } }
    const text = await feeds.readTaxonomyFile({ name: 'x.txt', size: 9 }, Ok);
    assert.strictEqual(text, 'taxonomy x.txt UTF-8');
  });

  await test('readTaxonomyFile: decoded text over the bound is refused even when the file size looked fine', async () => {
    class Big { readAsText() { this.result = 'a'.repeat(feeds.TAX_MAX_BYTES + 1); setImmediate(() => this.onload()); } }
    await assert.rejects(feeds.readTaxonomyFile({ size: 10 }, Big), (err) => err.code === 'too_large');
  });

  await test('applyTaxonomySelection: a numeric ID goes into the default category or the chosen category map', () => {
    const w = valid({ defaultCategory: 'Legacy > Text', categoryMap: { 15: 'Old path' } });
    assert.strictEqual(feeds.applyTaxonomySelection(w, 'default', { id: '3917', path: 'A > B' }), true);
    assert.strictEqual(w.defaultCategory, '3917');
    assert.strictEqual(feeds.applyTaxonomySelection(w, 'map:15', { id: 4001, path: 'C' }), true);
    assert.strictEqual(w.categoryMap[15], '4001');
  });

  await test('applyTaxonomySelection: non-numeric values and unknown targets leave the wizard untouched (legacy kept)', () => {
    const w = valid({ defaultCategory: 'Legacy > Text', categoryMap: {} });
    assert.strictEqual(feeds.applyTaxonomySelection(w, 'default', { id: '12 > 3', path: 'x' }), false);
    assert.strictEqual(feeds.applyTaxonomySelection(w, 'other', { id: '3917', path: 'x' }), false);
    assert.strictEqual(feeds.applyTaxonomySelection(w, 'default', null), false);
    assert.strictEqual(w.defaultCategory, 'Legacy > Text');
    assert.deepStrictEqual(w.categoryMap, {});
  });

  await test('createTaxonomySearch: rapid input sends one request for the last query after the debounce', async () => {
    const clock = fakeClock();
    const sent = [];
    const states = [];
    const search = feeds.createTaxonomySearch({
      setTimer: clock.setTimer, clearTimer: clock.clearTimer,
      search: (q) => { sent.push(q); return Promise.resolve({ version: 'v', items: [{ id: '1', path: q }] }); },
      onState: (s) => states.push(s.status),
    });
    search.input('ro'); search.input('roch'); search.input('rochii');
    assert.strictEqual(clock.pending(), 1, 'one pending timer');
    await clock.advance(feeds.TAX_DEBOUNCE_MS);
    await flush();
    assert.deepStrictEqual(sent, ['rochii']);
    assert.strictEqual(states[states.length - 1], 'ok');
    assert.strictEqual(clock.pending(), 0);
  });

  await test('createTaxonomySearch: an answer to an older query never replaces the newer one', async () => {
    const resolvers = {};
    let last = null;
    const search = feeds.createTaxonomySearch({
      setTimer: (fn) => { fn(); return 1; }, clearTimer: () => {},
      search: (q) => new Promise((resolve) => { resolvers[q] = resolve; }),
      onState: (s) => { last = s; },
    });
    search.input('old');
    search.input('new');
    await flush(); // search() runs in a microtask, so both resolvers exist only after this
    resolvers.new({ version: 'v', items: [{ id: '2', path: 'new' }] });
    await flush();
    resolvers.old({ version: 'v', items: [{ id: '1', path: 'old' }] });
    await flush();
    assert.deepStrictEqual(last.items.map((i) => i.path), ['new']);
  });

  await test('createTaxonomySearch: empty input cancels the pending search; cancel() ignores in-flight answers', async () => {
    const clock = fakeClock();
    let calls = 0;
    const search = feeds.createTaxonomySearch({
      setTimer: clock.setTimer, clearTimer: clock.clearTimer,
      search: () => { calls++; return Promise.resolve({ version: 'v', items: [] }); },
      onState: () => {},
    });
    search.input('abc');
    search.input('   ');
    assert.strictEqual(clock.pending(), 0, 'blank input clears the timer');
    await clock.advance(feeds.TAX_DEBOUNCE_MS * 2);
    assert.strictEqual(calls, 0);
    search.input('abc');
    await clock.advance(feeds.TAX_DEBOUNCE_MS);
    search.cancel();
    await flush();
    assert.strictEqual(clock.pending(), 0);
  });

  await test('createTaxonomySearch: no imported taxonomy is "empty"; a failed request is "error", never "ok"', async () => {
    const states = [];
    const empty = feeds.createTaxonomySearch({
      setTimer: (fn) => { fn(); return 1; }, clearTimer: () => {},
      search: () => Promise.resolve({ version: null, items: [] }),
      onState: (s) => states.push(s.status),
    });
    empty.input('x');
    await flush();
    assert.strictEqual(states[states.length - 1], 'empty');
    const failing = feeds.createTaxonomySearch({
      setTimer: (fn) => { fn(); return 1; }, clearTimer: () => {},
      search: () => Promise.reject(new Error('Serviciul nu este disponibil.')),
      onState: (s) => { states.push(s.status); states.message = s.message; },
    });
    failing.input('x');
    await flush();
    assert.strictEqual(states[states.length - 1], 'error');
    assert.strictEqual(states.message, 'Serviciul nu este disponibil.');
  });

  await test('taxonomy request contract: GET carries q and limit; POST sends JSON content; 503 is a failure, not success', async () => {
    const seen = [];
    const fetchImpl = async (url, init) => {
      seen.push({ url, init });
      if (init.method === 'POST') return response(503, undefined);
      return response(200, { version: 'v', items: [] });
    };
    const got = await feeds.apiRequest(fetchImpl, '/r/taxonomy?q=a&limit=50', 'GET', 'n');
    assert.deepStrictEqual(got, { version: 'v', items: [] });
    assert.strictEqual(seen[0].init.body, undefined, 'GET has no body');
    await assert.rejects(feeds.apiRequest(fetchImpl, '/r/taxonomy', 'POST', 'n', { content: 'Google_Product_Taxonomy_Version: x' }));
    assert.strictEqual(JSON.parse(seen[1].init.body).content, 'Google_Product_Taxonomy_Version: x');
  });

  console.log(`admin-feeds-wizard-test: ${process.exitCode ? 'FAIL' : 'PASS'} (${passed} checks)`);
})();
