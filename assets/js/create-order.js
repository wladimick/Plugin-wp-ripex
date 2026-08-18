(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  // Evita disparar una llamada AJAX por cada tecla en los buscadores.
  function debounce(fn, wait){
    let t = null;
    return function(...args){
      if (t) clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  // Toast no bloqueante: aparece dentro/cerca del modal y desaparece solo.
  // No usa alert() ni interrumpe el foco del campo de cantidad.
  let _toastTimer = null;
  function showToast(msg, duration){
    duration = duration || 2000;
    // Busca contenedor del modal o del formulario de página completa.
    const anchor = $('#rp-new-order-modal') || $('#rp-portal-create-wrap') || document.body;
    let toast = anchor.querySelector('.rp-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'rp-toast';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      anchor.appendChild(toast);
    }
    toast.textContent = msg;
    toast.classList.add('rp-toast-visible');
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => toast.classList.remove('rp-toast-visible'), duration);
  }

  function setButtonLoading(btn, loading, label){
    if (!btn) return;
    if (loading){
      if (!btn.dataset.rpOriginalHtml) btn.dataset.rpOriginalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.classList.add('rp-btn-loading');
      btn.innerHTML = `<span class="rp-btn-spinner" aria-hidden="true"></span><span>${escapeHtml(label || 'Procesando...')}</span>`;
    } else {
      btn.disabled = false;
      btn.classList.remove('rp-btn-loading');
      if (btn.dataset.rpOriginalHtml){
        btn.innerHTML = btn.dataset.rpOriginalHtml;
        delete btn.dataset.rpOriginalHtml;
      }
    }
  }

  function suggestionLoadingHtml(label){
    return `<div class="rp-sg rp-sg-loading"><span class="rp-loader-spinner" aria-hidden="true"></span><strong>${escapeHtml(label || 'Buscando...')}</strong></div>`;
  }

  const state = {
    mode: 'create',
    editOrderId: null,
    customerId: null,
    selectedProduct: null,
    items: []
  };

  function isModalMode(){ return !!$('#rp-new-order-modal'); }

  // ── Scroll-lock + devolver foco al cerrar (consistente con portal.js) ──────
  let _modalLockedScroll = false;
  let _modalLastFocusedEl = null;
  function openModal(show){
    const m = $('#rp-new-order-modal');
    if (!m) return;
    const wasOpen = m.getAttribute('aria-hidden') === 'false';
    m.setAttribute('aria-hidden', show ? 'false' : 'true');
    if (show && !wasOpen) {
      _modalLastFocusedEl = document.activeElement;
      if (!_modalLockedScroll) { document.body.classList.add('rp-scroll-locked'); _modalLockedScroll = true; }
      const focusTarget = m.querySelector('.rp-icon-btn, button, [href], input, select, textarea');
      if (focusTarget) setTimeout(() => focusTarget.focus(), 50);
    } else if (!show && wasOpen) {
      if (_modalLockedScroll) { document.body.classList.remove('rp-scroll-locked'); _modalLockedScroll = false; }
      if (_modalLastFocusedEl && typeof _modalLastFocusedEl.focus === 'function') {
        setTimeout(() => _modalLastFocusedEl.focus(), 0);
      }
      _modalLastFocusedEl = null;
    }
  }
  function closeModal(){ openModal(false); }

  function escapeHtml(str){
    return (str ?? '').toString()
      .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
      .replaceAll('"','&quot;').replaceAll("'","&#039;");
  }

  function money(amount){
    const n = Number(amount || 0);
    try{
      return new Intl.NumberFormat('es-CL', { style:'currency', currency:'CLP', maximumFractionDigits:0 }).format(n);
    }catch(e){
      return n;
    }
  }

  function applyTableFontSize(size){
    const app = document.querySelector('.rp-app');
    if (!app) return;
    app.style.setProperty('--rp-table-font-size', `${size}px`);
  }

  function initFontControls(){
    const key = 'ripex_portal_table_font_size';
    let size = parseInt(localStorage.getItem(key) || '13', 10);
    if (Number.isNaN(size)) size = 13;
    size = Math.max(11, Math.min(18, size));
    applyTableFontSize(size);

    document.getElementById('rp-font-decrease')?.addEventListener('click', () => {
      size = Math.max(11, size - 1);
      localStorage.setItem(key, String(size));
      applyTableFontSize(size);
    });
    document.getElementById('rp-font-increase')?.addEventListener('click', () => {
      size = Math.min(18, size + 1);
      localStorage.setItem(key, String(size));
      applyTableFontSize(size);
    });
  }

  async function post(action, data){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', RIPEX_PORTAL.nonce);
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
    const res = await fetch(RIPEX_PORTAL.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
    return await res.json();
  }

  function setNow(dateObj = null){
    const el = $('#rp-now');
    if (!el) return;
    const d = dateObj instanceof Date && !Number.isNaN(dateObj.getTime()) ? dateObj : new Date();
    const pad = (n)=> String(n).padStart(2,'0');
    el.textContent = `${pad(d.getDate())}-${pad(d.getMonth()+1)}-${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  async function loadShippingMethods(selectedKey = ''){
    const sel = $('#rp-shipping-method');
    if (!sel) return;

    const j = await post('ripex_portal_get_shipping_methods', {});
    sel.innerHTML = '';
    if (!j.success){
      sel.innerHTML = '<option value="">—</option>';
      return;
    }
    const ms = j.data.shipping_methods || [];
    sel.innerHTML = '<option value="">— Seleccionar —</option>' + ms.map(m => `<option value="${escapeHtml(m.key)}">${escapeHtml(m.title)} — ${escapeHtml(m.zone)}</option>`).join('');
    if (selectedKey) sel.value = selectedKey;
  }

  function setCustomerBoxCollapsed(collapsed){
    const box = $('#rp-customer-box');
    const btn = $('#rp-toggle-customer');
    if (!box || !btn) return;
    box.style.display = collapsed ? 'none' : '';
    btn.textContent = collapsed ? 'Desplegar información de cliente' : 'Comprimir información de cliente';
  }

  function resetForm(){
    state.mode = 'create';
    state.editOrderId = null;
    state.customerId = null;
    state.selectedProduct = null;
    state.items = [];

    $('#rp-modal-title') && ($('#rp-modal-title').textContent = 'Detalles de Pedido #Nuevo');
    $('#rp-modal-subtitle') && ($('#rp-modal-subtitle').textContent = 'Completa los datos y crea el pedido');
    $('#rp-create-order') && ($('#rp-create-order').textContent = 'Crear pedido');
    $('#rp-order-status-create') && ($('#rp-order-status-create').value = 'on-hold');

    ['#rp-customer-search','#rp-billing-first','#rp-billing-last','#rp-billing-phone','#rp-billing-email','#rp-billing-address1','#rp-billing-city','#rp-billing-state','#rp-billing-postcode','#rp-rut-empresa','#rp-razon-social','#rp-giro','#rp-vendedor','#rp-order-note','#rp-product-search'].forEach(id=>{
      const el = $(id); if (el) el.value = '';
    });
    $('#rp-billing-country') && ($('#rp-billing-country').value = 'CL');
    $('#rp-payment-method') && ($('#rp-payment-method').value = 'bacs');
    $('#rp-credit-term') && ($('#rp-credit-term').value = '');
    $('#rp-shipping-method') && ($('#rp-shipping-method').value = '');
    $('#rp-customer-chip') && ($('#rp-customer-chip').style.display = 'none');
    $('#rp-customer-name') && ($('#rp-customer-name').textContent = '—');
    $('#rp-customer-email') && ($('#rp-customer-email').textContent = '—');
    setCustomerBoxCollapsed(true);
    refreshItems();
    toggleCreditFields();
    setNow();
  }

  async function searchCustomers(term){
    const sug = $('#rp-customer-suggest');
    if (!sug) return;

    if (!term || term.length < 2){
      sug.style.display = 'none';
      sug.innerHTML = '';
      return;
    }

    const j = await post('ripex_portal_search_customers', { term });
    if (!j.success){
      sug.style.display = 'none';
      sug.innerHTML = '';
      return;
    }

    const cs = j.data.customers || [];
    if (!cs.length){
      sug.style.display = 'none';
      sug.innerHTML = '';
      return;
    }

    sug.innerHTML = cs.map(c => `
      <div class="rp-sg" data-cid="${c.id}">
        <div class="rp-sg-top">
          <div class="rp-sg-name">${escapeHtml(c.name)}</div>
          <div class="rp-sg-sku">${escapeHtml(c.rut || 'Sin RUT')}</div>
        </div>
        <div class="rp-sg-bottom">
          <div class="rp-sg-hint">${escapeHtml(c.email)}</div>
          <div class="rp-sg-hint">Click para seleccionar</div>
        </div>
      </div>
    `).join('');
    sug.style.display = 'block';

    $$('[data-cid]', sug).forEach(el => el.addEventListener('click', async () => {
      const cid = Number(el.dataset.cid);
      await selectCustomer(cid);
      sug.style.display = 'none';
      sug.innerHTML = '';
      $('#rp-customer-search').value = '';
    }));
  }

  async function selectCustomer(customerId){
    const j = await post('ripex_portal_get_customer', { customer_id: customerId });
    if (!j.success){
      alert(j.data?.message || 'Error al cargar cliente.');
      return;
    }

    const c = j.data.customer;
    state.customerId = c.id;

    $('#rp-customer-chip').style.display = '';
    $('#rp-customer-name').textContent = c.name || '—';
    $('#rp-customer-email').textContent = (c.email || '—') + ((c.rut || '') ? ' · RUT: ' + c.rut : '');

    $('#rp-billing-first').value = c.billing_first_name || '';
    $('#rp-billing-last').value  = c.billing_last_name || '';
    $('#rp-billing-phone').value = c.billing_phone || '';
    $('#rp-billing-email').value = c.email || '';

    $('#rp-billing-country').value = c.billing_country || 'CL';
    $('#rp-billing-address1').value = c.billing_address_1 || '';
    $('#rp-billing-city').value = c.billing_city || '';
    $('#rp-billing-state').value = c.billing_state || '';
    $('#rp-billing-postcode').value = c.billing_postcode || '';

    $('#rp-rut-empresa').value = c.rut || '';
    $('#rp-razon-social').value = c.razon_social || '';
    $('#rp-giro').value = c.giro || '';
    $('#rp-vendedor').value = c.vendedor || '';

    const term = (c.credit_term || '').trim();
    if (term && term.toLowerCase() !== 'desactivado') $('#rp-credit-term').value = term;
    else $('#rp-credit-term').value = '';

    setCustomerBoxCollapsed(true);
  }

  function addProductToOrder(product, options = {}){
    if (!product || !product.product_id) return;

    const idx = state.items.findIndex(x => Number(x.product_id) === Number(product.product_id));
    if (idx >= 0) {
      state.items[idx].qty = Number(state.items[idx].qty || 1) + Number(product.qty || 1);
    } else {
      state.items.push({ ...product, qty: Number(product.qty || 1) });
    }

    state.selectedProduct = null;

    const addedIdx = state.items.findIndex(x => Number(x.product_id) === Number(product.product_id));

    const input = $('#rp-product-search');
    if (input) input.value = '';

    refreshItems();
    showToast('Producto agregado al carro');

    // Enfocar el campo cantidad del ítem agregado/actualizado
    setTimeout(() => {
      const box = $('#rp-new-order-items');
      const qtyInputs = $$('[data-qty]', box || document);
      const target = qtyInputs[addedIdx >= 0 ? addedIdx : qtyInputs.length - 1];
      if (target) { target.select(); target.focus(); }
    }, 30);
  }

  async function searchProducts(term){
    const sug = $('#rp-product-suggest');
    if (!sug) return;

    if (!term || term.length < 2){
      sug.style.display = 'none';
      sug.innerHTML = '';
      return;
    }

    sug.innerHTML = suggestionLoadingHtml('Buscando productos...');
    sug.style.display = 'block';

    const j = await post('ripex_portal_search_products', { term });
    if (!j.success){
      sug.style.display = 'none';
      sug.innerHTML = '';
      return;
    }

    const ps = j.data.products || [];
    if (!ps.length){
      sug.style.display = 'none';
      sug.innerHTML = '';
      return;
    }

    sug.innerHTML = ps.map(p => `
      <div class="rp-sg" data-pid="${p.id}" data-sku="${escapeHtml(p.sku || '')}" data-name="${escapeHtml(p.name || '')}" data-stock="${p.stock ?? ''}" data-price="${Number(p.price || 0)}">
        <div class="rp-sg-top">
          <div class="rp-sg-name">${escapeHtml(p.name)}</div>
          <div class="rp-sg-sku">${escapeHtml(p.sku || 'Sin SKU')}</div>
        </div>
        <div class="rp-sg-bottom">
          <div class="rp-sg-stock">Stock: ${escapeHtml(p.stock ?? '—')}${p.categories ? ' · ' + escapeHtml(p.categories) : ''}</div>
          <div class="rp-sg-price">${p.price_html || money(p.price || 0)}</div>
        </div>
      </div>
    `).join('');
    sug.style.display = 'block';

    $$('[data-pid]', sug).forEach(el => el.addEventListener('click', () => {
      const product = {
        product_id: Number(el.dataset.pid),
        sku: el.dataset.sku || '',
        name: el.dataset.name || '',
        stock: el.dataset.stock || '',
        price: Number(el.dataset.price || 0),
        qty: 1
      };
      sug.style.display = 'none';
      sug.innerHTML = '';
      addProductToOrder(product);
    }));
  }

  const ITEMS_MAX = 60;

  function updateItemsSubtotal(){
    const total = state.items.reduce((acc, it) => acc + (Number(it.price || 0) * Number(it.qty || 0)), 0);
    const el = document.getElementById('rp-items-subtotal');
    if (el) el.textContent = money(total);
  }

  function updateItemsCount(){
    const count = state.items.length;
    const el = document.getElementById('rp-items-count');
    if (!el) return;
    el.textContent = count + ' ítem' + (count !== 1 ? 's' : '');
    el.className = 'rp-items-count-badge' + (count >= ITEMS_MAX ? ' rp-items-count-over' : count >= 50 ? ' rp-items-count-warn' : '');
  }

  function refreshItems(){
    const box = $('#rp-new-order-items');
    if (!box) return;

    if (!state.items.length){
      box.innerHTML = `
        <table class="rp-items-table">
          <thead><tr>
            <th class="rp-it-name">Producto</th>
            <th class="rp-it-sku">SKU</th>
            <th class="rp-it-stock">Stock</th>
            <th class="rp-it-price">Precio unit.</th>
            <th class="rp-it-total">Total línea</th>
            <th class="rp-it-qty">Cantidad</th>
            <th class="rp-it-del"></th>
          </tr></thead>
          <tbody><tr><td colspan="7" class="rp-it-empty">Agrega productos para crear el pedido.</td></tr></tbody>
        </table>`;
      updateItemsSubtotal();
      updateItemsCount();
      return;
    }

    const rows = state.items.map((it, idx) => {
      const unitPrice = Number(it.price || 0);
      const lineTotal = unitPrice * Number(it.qty || 0);
      return `
        <tr>
          <td class="rp-it-name"><strong>${escapeHtml(it.name)}</strong></td>
          <td class="rp-it-sku rp-small">${escapeHtml(it.sku || '—')}</td>
          <td class="rp-it-stock rp-small">${escapeHtml(it.stock ?? '—')}</td>
          <td class="rp-it-price rp-small">${money(unitPrice)}</td>
          <td class="rp-it-total rp-small">${money(lineTotal)}</td>
          <td class="rp-it-qty">
            <input class="rp-input rp-it-qty-input" type="number" min="1" value="${it.qty}" data-qty="${idx}">
          </td>
          <td class="rp-it-del">
            <button class="rp-btn rp-btn-danger-sm" data-del="${idx}">✕</button>
          </td>
        </tr>`;
    }).join('');

    box.innerHTML = `
      <table class="rp-items-table">
        <thead><tr>
          <th class="rp-it-name">Producto</th>
          <th class="rp-it-sku">SKU</th>
          <th class="rp-it-stock">Stock</th>
          <th class="rp-it-price">Precio unit.</th>
          <th class="rp-it-total">Total línea</th>
          <th class="rp-it-qty">Cantidad</th>
          <th class="rp-it-del"></th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>`;

    $$('[data-del]', box).forEach(b => b.addEventListener('click', () => {
      const i = Number(b.dataset.del);
      state.items.splice(i, 1);
      refreshItems();
    }));

    $$('[data-qty]', box).forEach(inp => inp.addEventListener('change', () => {
      const i = Number(inp.dataset.qty);
      const q = Math.max(1, Number(inp.value || 1));
      state.items[i].qty = q;
      refreshItems();
    }));

    updateItemsSubtotal();
    updateItemsCount();
  }

  function toggleCreditFields(){
    const pm = $('#rp-payment-method')?.value || '';
    const wrap = $('#rp-credit-term-wrap');
    if (!wrap) return;
    wrap.style.display = (pm === 'cheque') ? '' : 'none';
  }

  async function openCreateOrderModal(){
    const btn = $('#rp-new-order');
    setButtonLoading(btn, true, 'Abriendo...');
    resetForm();
    await loadShippingMethods();
    setButtonLoading(btn, false);
    openModal(true);
  }

  async function openEditOrderModal(orderId){
    const editButton = document.querySelector(`[data-edit-order="${orderId}"]`);
    setButtonLoading(editButton, true, '');
    const j = await post('ripex_portal_get_order', { order_id: orderId });
    setButtonLoading(editButton, false);
    if (!j.success){
      alert(j.data?.message || 'No se pudo cargar el pedido.');
      return;
    }

    const o = j.data.order || {};
    if (!o.can_edit){
      alert('No tienes permisos para editar este pedido.');
      return;
    }

    resetForm();
    state.mode = 'edit';
    state.editOrderId = Number(orderId);
    state.customerId = Number(o.customer_id || 0);

    $('#rp-modal-title') && ($('#rp-modal-title').textContent = `Editar pedido #${o.number || orderId}`);
    $('#rp-modal-subtitle') && ($('#rp-modal-subtitle').textContent = 'Modifica los datos y guarda los cambios');
    $('#rp-create-order') && ($('#rp-create-order').textContent = 'Guardar cambios');

    $('#rp-order-status-create').value = o.status || 'on-hold';
    $('#rp-customer-chip').style.display = '';
    $('#rp-customer-name').textContent = o.billing?.name || '—';
    $('#rp-customer-email').textContent = (o.billing?.email || '—') + ((o.afreg?.rut || '') ? ' · RUT: ' + o.afreg.rut : '');

    $('#rp-billing-first').value = o.billing?.first_name || '';
    $('#rp-billing-last').value  = o.billing?.last_name || '';
    $('#rp-billing-phone').value = o.billing?.phone || '';
    $('#rp-billing-email').value = o.billing?.email || '';
    $('#rp-billing-country').value = o.billing?.country || 'CL';
    $('#rp-billing-address1').value = o.billing?.address_1 || '';
    $('#rp-billing-city').value = o.billing?.city || '';
    $('#rp-billing-state').value = o.billing?.state || '';
    $('#rp-billing-postcode').value = o.billing?.postcode || '';
    $('#rp-rut-empresa').value = o.afreg?.rut || '';
    $('#rp-razon-social').value = o.afreg?.razon_social || '';
    $('#rp-giro').value = o.afreg?.giro || '';
    $('#rp-vendedor').value = o.afreg?.vendedor || '';
    $('#rp-order-note').value = o.notes || '';
    $('#rp-payment-method').value = o.payment_method_id || 'bacs';
    // Restaurar plazo de crédito guardado en el pedido
    const savedTerm = (o.credit_term || '').trim();
    if (savedTerm && savedTerm.toLowerCase() !== 'desactivado') {
      $('#rp-credit-term').value = savedTerm;
    } else {
      $('#rp-credit-term').value = '';
    }
    setCustomerBoxCollapsed(true);
    toggleCreditFields();

    await loadShippingMethods(o.shipping_key || '');

    state.items = (o.items || []).map(it => ({
      product_id: Number(it.product_id || 0),
      sku: it.sku || '',
      name: it.name || '',
      stock: it.stock ?? '',
      price: Number(it.unit_price || 0),
      qty: Number(it.qty || 1)
    }));
    refreshItems();

    const d = o.date ? new Date(o.date.replace(' ', 'T')) : null;
    setNow(d);
    openModal(true);
  }

  // Valida RUT chileno formato 12345678-9 o 12.345.678-9
  function validarRutCL(rut) {
    if (!rut || typeof rut !== 'string') return false;
    const clean = rut.replace(/\./g, '').replace(/-/g, '').trim().toUpperCase();
    if (!/^\d{7,8}[0-9K]$/.test(clean)) return false;
    const body = clean.slice(0, -1);
    const dv   = clean.slice(-1);
    let sum = 0, mul = 2;
    for (let i = body.length - 1; i >= 0; i--) {
      sum += parseInt(body[i], 10) * mul;
      mul = mul === 7 ? 2 : mul + 1;
    }
    const rest = 11 - (sum % 11);
    const expected = rest === 11 ? '0' : rest === 10 ? 'K' : String(rest);
    return dv === expected;
  }

  async function submitOrder(){
    if (!state.customerId){
      alert('Debes seleccionar un cliente (no hay compra invitado).');
      return;
    }
    if (!state.items.length){
      alert('Debes agregar al menos 1 producto.');
      return;
    }

    // Validación límite ítems por factura
    if (state.items.length > ITEMS_MAX){
      alert(`Este pedido tiene ${state.items.length} ítems, superando el máximo de ${ITEMS_MAX} que admite una factura. Por favor crea un segundo pedido con los productos restantes.`);
      return;
    }

    // Validación RUT empresa (campo obligatorio si está visible y relleno)
    const rutVal = ($('#rp-rut-empresa')?.value || '').trim();
    if (rutVal !== '' && !validarRutCL(rutVal)) {
      alert('El RUT empresa ingresado no es válido. Verifica el formato (ej: 76543210-K).');
      $('#rp-rut-empresa')?.focus();
      return;
    }

    // Validación teléfono (formato chileno: +56 9 XXXX XXXX o variantes)
    const phoneVal = ($('#rp-billing-phone')?.value || '').trim();
    if (phoneVal !== '') {
      const phoneClean = phoneVal.replace(/[\s\-().+]/g, '');
      const validPhone = /^(56)?9\d{8}$/.test(phoneClean) || /^\d{7,9}$/.test(phoneClean);
      if (!validPhone) {
        alert('El teléfono ingresado no parece válido. Usa formato +56 9 XXXX XXXX o similar.');
        $('#rp-billing-phone')?.focus();
        return;
      }
    }

    const payload = {
      customer_id: String(state.customerId),
      order_status: $('#rp-order-status-create')?.value || 'on-hold',
      billing_first_name: ($('#rp-billing-first')?.value || '').trim(),
      billing_last_name: ($('#rp-billing-last')?.value || '').trim(),
      billing_phone: ($('#rp-billing-phone')?.value || '').trim(),
      billing_country: ($('#rp-billing-country')?.value || 'CL').trim(),
      billing_address_1: ($('#rp-billing-address1')?.value || '').trim(),
      billing_city: ($('#rp-billing-city')?.value || '').trim(),
      billing_state: ($('#rp-billing-state')?.value || '').trim(),
      billing_postcode: ($('#rp-billing-postcode')?.value || '').trim(),
      rut_empresa: ($('#rp-rut-empresa')?.value || '').trim(),
      razon_social: ($('#rp-razon-social')?.value || '').trim(),
      giro: ($('#rp-giro')?.value || '').trim(),
      vendedor: ($('#rp-vendedor')?.value || '').trim(),
      payment_method: $('#rp-payment-method')?.value || 'bacs',
      shipping_key: $('#rp-shipping-method')?.value || '',
      credit_term: ($('#rp-credit-term')?.value || '').trim(),
      order_note: ($('#rp-order-note')?.value || '').trim(),
      items: JSON.stringify(state.items.map(it => ({ product_id: it.product_id, qty: it.qty })))
    };

    const isEdit = state.mode === 'edit' && state.editOrderId;
    if (isEdit) payload.order_id = String(state.editOrderId);

    const btn = $('#rp-create-order');
    setButtonLoading(btn, true, isEdit ? 'Guardando...' : 'Creando pedido...');

    const j = await post(isEdit ? 'ripex_portal_update_order' : 'ripex_portal_create_order', payload);

    if (!j.success){
      alert(j.data?.message || (isEdit ? 'Error al actualizar pedido.' : 'Error al crear pedido.'));
      setButtonLoading(btn, false);
      return;
    }

    window.location.href = `${RIPEX_PORTAL.portalUrl}?order=${encodeURIComponent(j.data.order_id)}`;
  }

  function init(){
    setNow();
    setInterval(setNow, 30000);
    initFontControls();

    loadShippingMethods();
    toggleCreditFields();

    if (isModalMode()) {
      $('#rp-new-order')?.addEventListener('click', async () => { await openCreateOrderModal(); });
      $('#rp-new-order-close')?.addEventListener('click', closeModal);
      $('#rp-new-order-x')?.addEventListener('click', closeModal);

      document.addEventListener('click', async (e) => {
        const editBtn = e.target.closest('[data-edit-order]');
        if (!editBtn) return;
        e.preventDefault();
        await openEditOrderModal(editBtn.dataset.editOrder);
      });
    }

    $('#rp-payment-method')?.addEventListener('change', toggleCreditFields);

    $('#rp-customer-search')?.addEventListener('input', debounce((e)=> searchCustomers(e.target.value || ''), 350));
    $('#rp-toggle-customer')?.addEventListener('click', ()=>{
      const box = $('#rp-customer-box');
      const collapsed = box && box.style.display === 'none';
      setCustomerBoxCollapsed(!collapsed);
    });

    $('#rp-product-search')?.addEventListener('input', debounce((e)=> searchProducts(e.target.value || ''), 350));
    $('#rp-add-product')?.addEventListener('click', ()=>{
      if (!state.selectedProduct){
        alert('Primero selecciona un producto desde la lista.');
        return;
      }
      addProductToOrder(state.selectedProduct);
    });

    $('#rp-create-order')?.addEventListener('click', submitOrder);
    $('#rp-cancel-create')?.addEventListener('click', ()=>{ if (isModalMode()) closeModal(); else window.location.href = RIPEX_PORTAL.portalUrl; });

    refreshItems();
  }

  document.addEventListener('DOMContentLoaded', init);
})();