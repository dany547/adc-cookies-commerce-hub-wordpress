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

  console.log(`admin-feeds-wizard-test: ${process.exitCode ? 'FAIL' : 'PASS'} (${passed} checks)`);
})();
