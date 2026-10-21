(function () {
  'use strict';
  const meta = n => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';
  window.CSRF = meta('csrf-token');
  window.APP_BASE = meta('app-base');

  // Native confirmation dialogs for sensitive actions: <form data-confirm="...">
  document.addEventListener('submit', function (e) {
    const f = e.target;
    if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) { e.preventDefault(); return; }
    if (f.dataset.ajax !== undefined) return;
    // block repeated clicks / double submissions
    if (f.dataset.submitted === '1') { e.preventDefault(); return; }
    f.dataset.submitted = '1';
    f.querySelectorAll('button[type=submit]').forEach(b => { b.disabled = true; });
    // re-enable if the page is restored from the back/forward cache
    setTimeout(() => { document.getElementById('loading')?.classList.add('show'); }, 400);
  }, true);
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-submitted]').forEach(f => { delete f.dataset.submitted; f.querySelectorAll('button[type=submit]').forEach(b => b.disabled = false); });
    document.getElementById('loading')?.classList.remove('show');
  });

  // Auto-dismiss success alerts
  setTimeout(() => document.querySelectorAll('.alert-success').forEach(a => bootstrap.Alert.getOrCreateInstance(a).close()), 6000);

  // Clock
  const clock = document.getElementById('clock');
  if (clock) setInterval(() => {
    clock.textContent = new Date().toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).replace(',', ' ·');
  }, 30000);

  // POST helper returning JSON
  window.postJSON = async function (url, data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.set('csrf', window.CSRF);
    const res = await fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': window.CSRF }, credentials: 'same-origin' });
    let json;
    try { json = await res.json(); } catch (_) { json = { ok: false, error: 'Unexpected server response.' }; }
    return json;
  };

  // Periodically refresh an element's HTML from the same URL (?partial=1)
  window.autoRefresh = function (selector, seconds, onUpdate) {
    const el = document.querySelector(selector);
    if (!el) return;
    setInterval(async function () {
      if (document.hidden || document.querySelector('.modal.show')) return;
      try {
        const u = new URL(location.href); u.searchParams.set('partial', '1');
        const res = await fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
        if (res.status === 401 || res.redirected) { location.reload(); return; }
        if (!res.ok) return;
        const html = await res.text();
        el.innerHTML = html;
        if (onUpdate) onUpdate(el);
      } catch (_) { /* offline - retry next tick */ }
    }, seconds * 1000);
  };

  // Fill edit modals from data-* JSON: <button data-modal="#m" data-json='{...}'>
  document.addEventListener('click', function (e) {
    const b = e.target.closest('[data-fill]');
    if (!b) return;
    const modal = document.querySelector(b.dataset.fill);
    if (!modal) return;
    const data = JSON.parse(b.dataset.json || '{}');
    modal.querySelectorAll('[name]').forEach(inp => {
      if (!(inp.name in data)) return;
      if (inp.type === 'checkbox') inp.checked = !!Number(data[inp.name]);
      else if (inp.type !== 'file' && inp.type !== 'password') inp.value = data[inp.name] ?? '';
    });
    modal.querySelectorAll('[data-text]').forEach(t => { if (t.dataset.text in data) t.textContent = data[t.dataset.text]; });
    modal.dispatchEvent(new CustomEvent('filled', { detail: data }));
  });
})();
