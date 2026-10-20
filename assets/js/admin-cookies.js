(() => {
  'use strict';
  const root = document.querySelector('.adc-cookie-scanner');
  if (!root) return;
  const config = { url: root.dataset.ajaxUrl, nonce: root.dataset.nonce };
  const status = document.getElementById('adc-cookie-scan-status');
  const storageKey = 'adc-cookie-drafts';
  const fields = ['category', 'provider', 'purpose', 'duration'];
  const rowData = (row) => Object.fromEntries(fields.map((field) => [field, row.querySelector('.adc-cookie-' + field).value]));
  const original = (row) => { try { return JSON.parse(row.dataset.original || '{}'); } catch (_) { return {}; } };
  const isDirty = (row) => fields.some((field) => String(rowData(row)[field] || '') !== String(original(row)[field] || ''));
  const rows = () => Array.from(root.querySelectorAll('tbody tr[data-name]'));
  const readDrafts = () => { try { return JSON.parse(sessionStorage.getItem(storageKey) || '{}'); } catch (_) { return {}; } };
  const writeDrafts = (drafts) => { try { sessionStorage.setItem(storageKey, JSON.stringify(drafts)); } catch (_) { /* Storage may be disabled. */ } };
  const stash = () => { const drafts = {}; rows().forEach((row) => { if (isDirty(row)) drafts[row.dataset.name] = rowData(row); }); writeDrafts(drafts); };
  let skippingUnload = false;
  const mark = (row) => { row.classList.toggle('adc-cookie-dirty', isDirty(row)); const save = row.querySelector('.adc-cookie-save'); save.disabled = !row.querySelector('.adc-cookie-category').value; updateBulk(); };
  const updateBulk = () => { const button = document.getElementById('adc-cookie-save-all'); if (button) button.disabled = !rows().some((row) => isDirty(row)); };
  rows().forEach((row) => {
    row.dataset.original = JSON.stringify(rowData(row));
    const draft = readDrafts()[row.dataset.name];
    if (draft && fields.some((field) => String(draft[field] || '') !== String(original(row)[field] || ''))) {
      fields.forEach((field) => { const input = row.querySelector('.adc-cookie-' + field); if (input) input.value = draft[field] ?? ''; });
      row.querySelector('.adc-cookie-draft')?.remove();
      const badge = document.createElement('span'); badge.className = 'adc-cookie-badge adc-cookie-draft'; badge.textContent = 'nesalvat'; row.querySelector('th code').after(badge);
    }
    mark(row);
  });
  const request = async (action, data = {}) => {
    const body = new URLSearchParams({ action, nonce: config.nonce });
    Object.entries(data).forEach(([key, value]) => body.append(key, typeof value === 'string' ? value : JSON.stringify(value)));
    const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || 'Eroare');
    return json.data;
  };
  const reload = () => { stash(); skippingUnload = true; location.reload(); };
  document.getElementById('adc-cookie-scan')?.addEventListener('click', async (event) => {
    const button = event.currentTarget; button.disabled = true; status.textContent = 'Se scanează…';
    let added = 0;
    try {
      const server = await request('adc_cookie_scan_server'); added += Number(server.new || 0);
      const names = new Set(document.cookie.split(';').map((part) => part.trim().split('=')[0]).filter(Boolean));
      const urls = JSON.parse(root.dataset.urls || '[]');
      for (const url of urls) {
        if (new URL(url, location.href).origin !== location.origin) continue;
        status.textContent = 'Se scanează: ' + url;
        const frame = document.createElement('iframe'); frame.hidden = true; frame.src = url;
        const loaded = new Promise((resolve) => { frame.onload = resolve; frame.onerror = resolve; });
        document.body.appendChild(frame); await Promise.race([loaded, new Promise((resolve) => setTimeout(resolve, 6000))]);
        try { frame.contentDocument.cookie.split(';').forEach((part) => { const name = part.trim().split('=')[0]; if (name) names.add(name); }); } catch (_) { /* Same-origin restrictions fail closed. */ }
        frame.remove();
      }
      const browser = await request('adc_cookie_scan_browser', { names: Array.from(names) }); added += Number(browser.new || 0);
      status.textContent = 'Scanare finalizată. Cookie-uri noi: ' + added + '.'; reload();
    } catch (error) { status.textContent = error.message || 'Scanarea a eșuat.'; }
    finally { button.disabled = false; }
  });
  root.addEventListener('input', (event) => { const row = event.target.closest('tr[data-name]'); if (row) mark(row); });
  root.addEventListener('change', (event) => { const row = event.target.closest('tr[data-name]'); if (row) { if (event.target.matches('.adc-cookie-category')) row.dataset.category = event.target.value; mark(row); } });
  document.getElementById('adc-cookie-accept-suggestions')?.addEventListener('click', () => {
    rows().forEach((row) => {
      const category = row.querySelector('.adc-cookie-category');
      if (!category.value && row.dataset.suggestionCategory) category.value = row.dataset.suggestionCategory;
      ['provider', 'purpose', 'duration'].forEach((field) => { const input = row.querySelector('.adc-cookie-' + field); if (!input.value) input.value = row.dataset['suggestion' + field[0].toUpperCase() + field.slice(1)] || ''; });
      row.dataset.category = category.value; mark(row);
    });
  });
  document.getElementById('adc-cookie-save-all')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    // Hand-edited rows count too: a row with no category yet takes the suggested one, if the database has it.
    const dirty = rows().filter((row) => isDirty(row));
    dirty.forEach((row) => { const category = row.querySelector('.adc-cookie-category'); if (!category.value && row.dataset.suggestionCategory) { category.value = row.dataset.suggestionCategory; row.dataset.category = category.value; mark(row); } });
    const pending = dirty.filter((row) => row.querySelector('.adc-cookie-category').value);
    const skipped = dirty.length - pending.length;
    if (!pending.length) { status.textContent = skipped ? 'Alegeți o categorie pentru ' + skipped + ' rânduri modificate, apoi salvați din nou.' : ''; return; }
    button.disabled = true; status.textContent = 'Se salvează…';
    try {
      // Chunked to stay under the server-side per-request cap (200 rows).
      const drafts = readDrafts(); let savedTotal = 0; const errors = [];
      for (let i = 0; i < pending.length; i += 100) {
        const chunk = pending.slice(i, i + 100);
        const result = await request('adc_cookie_save', { rows: chunk.map((row) => ({ name: row.dataset.name, ...rowData(row) })) });
        savedTotal += Number(result.saved || 0); errors.push(...(result.errors || []));
        chunk.forEach((row) => { delete drafts[row.dataset.name]; row.dataset.original = JSON.stringify(rowData(row)); row.classList.remove('adc-cookie-dirty'); });
      }
      writeDrafts(drafts);
      status.textContent = 'Salvate: ' + savedTotal + '. Erori: ' + errors.length + '.' + (skipped ? ' Fără categorie (omise): ' + skipped + '.' : ''); updateBulk();
    } catch (error) { status.textContent = error.message || 'Salvarea a eșuat.'; button.disabled = false; }
  });
  root.addEventListener('click', async (event) => {
    const button = event.target.closest('.adc-cookie-save, .adc-cookie-status, .adc-cookie-copy'); if (!button) return;
    if (button.classList.contains('adc-cookie-copy')) { await navigator.clipboard.writeText(button.dataset.copy); button.textContent = 'Copiat'; return; }
    const row = button.closest('tr'); button.disabled = true;
    try {
      if (button.classList.contains('adc-cookie-save')) {
        await request('adc_cookie_save', { row: { name: button.dataset.name, ...rowData(row) } });
        const drafts = readDrafts(); delete drafts[row.dataset.name]; writeDrafts(drafts); row.dataset.original = JSON.stringify(rowData(row));
      } else await request('adc_cookie_status', { name: button.dataset.name, status: button.dataset.status });
      reload();
    } catch (error) { button.disabled = false; status.textContent = error.message || 'Operația a eșuat.'; }
  });
  window.addEventListener('beforeunload', (event) => { stash(); if (!skippingUnload && rows().some(isDirty)) { event.preventDefault(); event.returnValue = ''; } });
  root.querySelectorAll('[data-filter]').forEach((button) => button.addEventListener('click', () => {
    const filter = button.dataset.filter;
    root.querySelectorAll('tbody tr').forEach((row) => { row.hidden = !(filter === 'all' || (filter === 'new' && row.dataset.status === 'new') || (filter === 'ignored' && row.dataset.status === 'ignored') || row.dataset.category === filter); });
  }));
})();
