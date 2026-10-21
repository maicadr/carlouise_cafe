(function () {
  'use strict';
  const $ = s => document.querySelector(s);
  const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let cart = [];            // {id, name, price, qty, note}
  let cat = 0, term = '';
  let busy = false;

  function renderGrid() {
    const list = PRODUCTS.filter(p => (!cat || +p.category_id === cat) && p.name.toLowerCase().includes(term));
    $('#grid').innerHTML = list.map(p => `<button type="button" class="pos-product" data-id="${p.id}">
      ${p.image ? `<img src="${IMG_BASE}${encodeURIComponent(p.image)}" alt="">` : ''}
      <span class="p-name">${esc(p.name)}</span><span class="p-price">${peso(p.price)}</span></button>`).join('');
    $('#noProducts').classList.toggle('d-none', list.length > 0);
  }
  function renderCart() {
    const box = $('#cartLines');
    if (!cart.length) box.innerHTML = '<div class="text-muted text-center py-4 small">Tap a product to add it.</div>';
    else box.innerHTML = cart.map((l, i) => `<div class="cart-line">
      <div class="d-flex justify-content-between"><span class="fw-semibold">${esc(l.name)}</span><span>${peso(l.price * l.qty)}</span></div>
      <div class="d-flex align-items-center gap-1 mt-1">
        <button type="button" class="btn btn-outline-secondary qty-btn" data-act="dec" data-i="${i}" aria-label="Decrease">−</button>
        <span class="px-2 fw-bold">${l.qty}</span>
        <button type="button" class="btn btn-outline-secondary qty-btn" data-act="inc" data-i="${i}" aria-label="Increase">+</button>
        <input class="form-control form-control-sm ms-2" data-note="${i}" maxlength="255" placeholder="Item note" value="${esc(l.note)}">
        <button type="button" class="btn btn-sm text-danger" data-act="del" data-i="${i}" aria-label="Remove"><i class="bi bi-x-lg"></i></button>
      </div></div>`).join('');
    $('#grand').textContent = peso(cart.reduce((s, l) => s + l.price * l.qty, 0));
  }
  function typeChanged() {
    const t = document.querySelector('input[name=order_type]:checked').value;
    $('#tableBox').classList.toggle('d-none', t !== 'dine-in');
    $('#delivery_address').classList.toggle('d-none', t !== 'delivery');
  }

  $('#grid').addEventListener('click', e => {
    const b = e.target.closest('.pos-product'); if (!b) return;
    const p = PRODUCTS.find(x => x.id == b.dataset.id);
    const ex = cart.find(l => l.id == p.id);
    if (ex) { if (ex.qty < 99) ex.qty++; } else cart.push({ id: +p.id, name: p.name, price: +p.price, qty: 1, note: '' });
    renderCart();
  });
  $('#cartLines').addEventListener('click', e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const i = +b.dataset.i;
    if (b.dataset.act === 'inc' && cart[i].qty < 99) cart[i].qty++;
    if (b.dataset.act === 'dec') { cart[i].qty--; if (cart[i].qty < 1) cart.splice(i, 1); }
    if (b.dataset.act === 'del') cart.splice(i, 1);
    renderCart();
  });
  $('#cartLines').addEventListener('input', e => { if (e.target.dataset.note !== undefined) cart[+e.target.dataset.note].note = e.target.value; });
  $('#catPills').addEventListener('click', e => {
    const b = e.target.closest('[data-cat]'); if (!b) return;
    cat = +b.dataset.cat;
    document.querySelectorAll('#catPills .btn').forEach(x => { x.classList.toggle('btn-dark', x === b); x.classList.toggle('btn-outline-dark', x !== b); });
    renderGrid();
  });
  $('#pSearch').addEventListener('input', e => { term = e.target.value.trim().toLowerCase(); renderGrid(); });
  document.querySelectorAll('input[name=order_type]').forEach(r => r.addEventListener('change', typeChanged));
  $('#clearCart').addEventListener('click', () => { if (!cart.length || confirm('Clear all items from this order?')) { cart = []; renderCart(); } });

  async function save(after) {
    if (busy) return;                       // block repeated clicks
    const err = $('#posError'); err.classList.add('d-none');
    const type = document.querySelector('input[name=order_type]:checked').value;
    if (!cart.length) { err.textContent = 'Add at least one product.'; err.classList.remove('d-none'); return; }
    if (type === 'dine-in' && !$('#table_id').value) { err.textContent = 'Select a table for dine-in.'; err.classList.remove('d-none'); return; }
    if (type === 'delivery' && !$('#delivery_address').value.trim()) { err.textContent = 'Enter the delivery address.'; err.classList.remove('d-none'); return; }
    busy = true;
    const btns = [$('#saveBtn'), $('#savePayBtn')]; btns.forEach(b => b.disabled = true);
    document.getElementById('loading').classList.add('show');
    try {
      const r = await postJSON(POS_URL, {
        cart: JSON.stringify(cart.map(l => ({ id: l.id, qty: l.qty, note: l.note }))),
        order_type: type, table_id: $('#table_id').value, customer_name: $('#customer_name').value,
        delivery_address: $('#delivery_address').value, notes: $('#notes').value, client_token: TOKEN, after: after,
        override_stock: $('#override') && $('#override').checked ? '1' : '0'
      });
      if (r.ok) { location.href = r.redirect; return; }
      err.textContent = r.error; err.classList.remove('d-none');
    } catch (e) {
      err.textContent = 'Network problem. If the order does not appear in Orders, send it again - duplicates are prevented automatically.';
      err.classList.remove('d-none');
    }
    document.getElementById('loading').classList.remove('show');
    busy = false; btns.forEach(b => b.disabled = false);
  }
  $('#saveBtn').addEventListener('click', () => save('kitchen'));
  $('#savePayBtn').addEventListener('click', () => save('pay'));

  renderGrid(); renderCart(); typeChanged();
})();
