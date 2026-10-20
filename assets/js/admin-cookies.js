(() => {
  'use strict';
  const root = document.querySelector('.adc-cookie-scanner');
  if (!root) return;
  const config = { url: root.dataset.ajaxUrl, nonce: root.dataset.nonce };
  const status = document.getElementById('adc-cookie-scan-status');
  const request = async (action, data = {}) => {
    const body = new URLSearchParams({ action, nonce: config.nonce });
    Object.entries(data).forEach(([key, value]) => body.append(key, typeof value === 'string' ? value : JSON.stringify(value)));
    const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || 'Eroare');
    return json.data;
  };
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
      status.textContent = 'Scanare finalizată. Cookie-uri noi: ' + added + '.'; location.reload();
    } catch (error) { status.textContent = error.message || 'Scanarea a eșuat.'; }
    finally { button.disabled = false; }
  });
  root.addEventListener('change', (event) => { if (event.target.matches('.adc-cookie-category')) { const row = event.target.closest('tr'); row.querySelector('.adc-cookie-save').disabled = !event.target.value; row.dataset.category = event.target.value; } });
  root.addEventListener('click', async (event) => {
    const button = event.target.closest('.adc-cookie-save, .adc-cookie-status, .adc-cookie-copy'); if (!button) return;
    if (button.classList.contains('adc-cookie-copy')) { await navigator.clipboard.writeText(button.dataset.copy); button.textContent = 'Copiat'; return; }
    const row = button.closest('tr'); button.disabled = true;
    try {
      if (button.classList.contains('adc-cookie-save')) {
        await request('adc_cookie_save', { row: { name: button.dataset.name, category: row.querySelector('.adc-cookie-category').value, provider: row.querySelector('.adc-cookie-provider').value, purpose: row.querySelector('.adc-cookie-purpose').value, duration: row.querySelector('.adc-cookie-duration').value } });
      } else {
        await request('adc_cookie_status', { name: button.dataset.name, status: button.dataset.status });
      }
      location.reload();
    } catch (error) { button.disabled = false; status.textContent = error.message || 'Operația a eșuat.'; }
  });
  root.querySelectorAll('[data-filter]').forEach((button) => button.addEventListener('click', () => {
    const filter = button.dataset.filter;
    root.querySelectorAll('tbody tr').forEach((row) => { row.hidden = !(filter === 'all' || (filter === 'new' && row.dataset.status === 'new') || (filter === 'ignored' && row.dataset.status === 'ignored') || row.dataset.category === filter); });
  }));
})();
