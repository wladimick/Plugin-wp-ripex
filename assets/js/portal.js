(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  // ── Helper de overlays (drawers/modal): scroll-lock + devolver foco ────────
  let _overlayLockCount = 0;
  let _lastFocusedEl = null;

  function lockBodyScroll(){
    if (_overlayLockCount === 0) document.body.classList.add('rp-scroll-locked');
    _overlayLockCount++;
  }
  function unlockBodyScroll(){
    _overlayLockCount = Math.max(0, _overlayLockCount - 1);
    if (_overlayLockCount === 0) document.body.classList.remove('rp-scroll-locked');
  }
  function openOverlay(panelEl){
    _lastFocusedEl = document.activeElement;
    lockBodyScroll();
    if (panelEl) {
      const focusTarget = panelEl.querySelector('[autofocus]') || panelEl.querySelector('.rp-icon-btn, button, [href], input, select, textarea');
      if (focusTarget) setTimeout(() => focusTarget.focus(), 50);
    }
  }
  function closeOverlay(){
    unlockBodyScroll();
    if (_lastFocusedEl && typeof _lastFocusedEl.focus === 'function') {
      setTimeout(() => _lastFocusedEl.focus(), 0);
    }
    _lastFocusedEl = null;
  }

  let currentScope = 'mine';
  let currentPage  = 1;   // página actual del listado de pedidos
  let selectedOrderIds = new Set();
  let lastCarts = [];
  let shippingMethodsCache = null;

  function badgeClass(status){
    const map = { 'processing':'processing','on-hold':'on-hold','completed':'completed','cancelled':'cancelled','pending':'pending','failed':'failed' };
    return map[status] || 'pending';
  }
  function money(amount, currency){
    if (amount === null || amount === undefined) return '—';
    try{
      const n = Number(amount);
      if (Number.isNaN(n)) return '—';
      return new Intl.NumberFormat('es-CL', { style:'currency', currency: currency || 'CLP', maximumFractionDigits: 0 }).format(n);
    } catch(e){ return amount; }
  }
  async function post(action, data){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', RIPEX_PORTAL.nonce);
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
    const res = await fetch(RIPEX_PORTAL.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
    const text = await res.text();
    try{
      return JSON.parse(text);
    }catch(e){
      console.error('RIPEX AJAX respuesta no JSON', action, text);
      return { success:false, data:{ message:'Respuesta inválida del servidor en '+action+'. Revisa debug.log o consola.' } };
    }
  }
  function setLoading(idLoading, idEmpty, show){
    const elL = $(idLoading), elE = $(idEmpty);
    if (elL) {
      elL.style.display = show ? 'block' : 'none';
      if (show) enhanceLoading(idLoading, getLoadingLabel(idLoading));
    }
    if (elE) elE.style.display = 'none';
  }
  function setEmpty(idEmpty, show){
    const elE = $(idEmpty);
    if (elE) elE.style.display = show ? 'block' : 'none';
  }

  // ── UI/UX loading states v1.5.12 ─────────────────────────────────────────
  let _rpToastTimer = null;
  let _rpProgressTimer = null;

  function getLoadingLabel(idLoading){
    const map = {
      '#rp-orders-loading':'Cargando pedidos...',
      '#rp-products-loading':'Cargando inventario...',
      '#rp-customers-loading':'Cargando clientes...',
      '#rp-carts-loading':'Cargando carritos...',
      '#rp-reports-loading':'Calculando reportes...'
    };
    return map[idLoading] || 'Cargando información...';
  }

  function loadingHtml(label, progress = null){
    const hasProgress = progress !== null && progress !== undefined;
    const pct = hasProgress ? Math.max(0, Math.min(100, Number(progress) || 0)) : 0;
    return `
      <div class="rp-loader-card" role="status" aria-live="polite">
        <span class="rp-loader-spinner" aria-hidden="true"></span>
        <div class="rp-loader-copy">
          <strong>${escapeHtml(label || 'Cargando información...')}</strong>
          <span>${hasProgress ? escapeHtml(String(pct)) + '% completado' : 'Esto puede tardar unos segundos.'}</span>
        </div>
        ${hasProgress ? `<div class="rp-loader-progress"><span style="width:${pct}%"></span></div>` : ``}
      </div>
    `;
  }

  function enhanceLoading(idLoading, label, progress){
    const el = document.querySelector(idLoading);
    if (!el) return;
    el.innerHTML = loadingHtml(label || getLoadingLabel(idLoading), progress);
  }

  function showUiToast(message, type = 'success', duration = 3000){
    let wrap = document.getElementById('rp-ui-toast-wrap');
    if (!wrap){
      wrap = document.createElement('div');
      wrap.id = 'rp-ui-toast-wrap';
      wrap.className = 'rp-ui-toast-wrap';
      document.body.appendChild(wrap);
    }
    wrap.innerHTML = `<div class="rp-ui-toast rp-ui-toast-${escapeHtml(type)}"><span>${type === 'error' ? '✕' : type === 'warn' ? '!' : '✓'}</span><strong>${escapeHtml(message || '')}</strong></div>`;
    clearTimeout(_rpToastTimer);
    _rpToastTimer = setTimeout(() => { wrap.innerHTML = ''; }, duration);
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

  function renderSkeletonRows(selector, cols, rows = 6){
    const tbody = document.querySelector(selector);
    if (!tbody) return;
    const count = Math.max(1, Number(cols) || 4);
    tbody.innerHTML = Array.from({length: rows}).map((_, r) => `
      <tr class="rp-skeleton-row">
        ${Array.from({length: count}).map((__, c) => `<td><span class="rp-skeleton-line rp-skeleton-${(c % 3) + 1}"></span></td>`).join('')}
      </tr>
    `).join('');
  }

  function renderReportSkeleton(){
    document.querySelectorAll('.rp-kpi-value').forEach(el => el.innerHTML = '<span class="rp-skeleton-line rp-skeleton-kpi"></span>');
    [
      'rp-chart-sales-line','rp-chart-sellers','rp-chart-statuses','rp-chart-transports',
      'rp-chart-top-products','rp-chart-top-companies','rp-report-inactive-customers',
      'rp-report-no-movement','rp-report-low-stock'
    ].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<div class="rp-skeleton-block"><span></span><span></span><span></span></div>';
    });
  }

  function startFakeProgress(idLoading, label){
    const el = document.querySelector(idLoading);
    if (!el) return;
    clearInterval(_rpProgressTimer);
    let pct = 12;
    enhanceLoading(idLoading, label, pct);
    _rpProgressTimer = setInterval(() => {
      pct = Math.min(92, pct + Math.max(2, Math.round((100 - pct) / 8)));
      enhanceLoading(idLoading, label, pct);
    }, 450);
  }

  function stopFakeProgress(idLoading, label){
    clearInterval(_rpProgressTimer);
    enhanceLoading(idLoading, label || 'Finalizando...', 100);
  }

  function showTableMessage(tbody, emptyEl, message){
    if (tbody) tbody.innerHTML = '';
    if (emptyEl){
      emptyEl.textContent = message || 'Sin datos para mostrar.';
      emptyEl.style.display = 'block';
    }
  }
  function switchTab(tab){
    $$('.rp-tab').forEach(b => b.classList.toggle('rp-tab-active', b.dataset.tab === tab));
    $$('.rp-panel').forEach(p => p.classList.toggle('rp-panel-active', p.dataset.panel === tab));
    // Cerrar drawers al cambiar de pestaña para evitar contaminación visual.
    openDrawer(false);
    openCustomerHistoryDrawer(false);
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
  function escapeHtml(str){
    return (str ?? '').toString()
      .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
      .replaceAll('"','&quot;').replaceAll("'","&#039;");
  }

  function getUrlParam(name){
    try{
      const u = new URL(window.location.href);
      return u.searchParams.get(name);
    } catch(e){ return null; }
  }


  function getOrderColumnConfig(role){
    const cols = [
      { key:'select', label:'Selección' },
      { key:'id', label:'#' },
      { key:'fecha', label:'Fecha' },
      { key:'cliente', label:'Cliente' },
      { key:'estado', label:'Estado' },
      { key:'metodo_pago', label:'Método de pago', roles:['ripex_admin','ripex_vendedor'] },
      { key:'vendedor', label:'Vendedor' },
      { key:'exportacion', label:'Exportación' },
      { key:'total', label:'Total', roles:['ripex_admin','ripex_vendedor'] },
      { key:'envio', label:'Envío' }
    ];
    return cols.filter(col => !col.roles || col.roles.includes(role));
  }

  function getColumnPrefsKey(){
    return `ripex_portal_order_columns_${RIPEX_PORTAL.role}`;
  }

  function getDefaultColumnPrefs(){
    const prefs = {};
    getOrderColumnConfig(RIPEX_PORTAL.role).forEach(col => { prefs[col.key] = true; });
    return prefs;
  }

  function getColumnPrefs(){
    try{
      const raw = sessionStorage.getItem(getColumnPrefsKey());
      const defaults = getDefaultColumnPrefs();
      if(!raw) return defaults;
      const parsed = JSON.parse(raw);
      return { ...defaults, ...parsed };
    }catch(e){
      return getDefaultColumnPrefs();
    }
  }

  function saveColumnPrefs(prefs){
    sessionStorage.setItem(getColumnPrefsKey(), JSON.stringify(prefs));
  }

  function applyOrderColumnVisibility(){
    const prefs = getColumnPrefs();
    getOrderColumnConfig(RIPEX_PORTAL.role).forEach(col => {
      const show = prefs[col.key] !== false;
      document.querySelectorAll(`[data-col="${col.key}"]`).forEach(el => {
        el.style.display = show ? '' : 'none';
      });
    });
  }

  function renderColumnMenu(){
    const menu = document.getElementById('rp-columns-menu');
    const btn = document.getElementById('rp-columns-toggle');
    if(!menu || !btn) return;

    const prefs = getColumnPrefs();
    menu.innerHTML = getOrderColumnConfig(RIPEX_PORTAL.role).map(col => `
      <label class="rp-colmenu-item">
        <input type="checkbox" data-col-toggle="${col.key}" ${prefs[col.key] !== false ? 'checked' : ''}>
        <span>${col.label}</span>
      </label>
    `).join('');

    menu.querySelectorAll('[data-col-toggle]').forEach(input => {
      input.addEventListener('change', () => {
        const next = getColumnPrefs();
        next[input.dataset.colToggle] = !!input.checked;
        saveColumnPrefs(next);
        applyOrderColumnVisibility();
      });
    });

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    });

    document.addEventListener('click', (e) => {
      if (!menu.contains(e.target) && e.target !== btn) menu.style.display = 'none';
    });

    applyOrderColumnVisibility();
  }

  async function exportMyOrders(){
    const btn = document.getElementById('rp-export-my-orders');
    if(btn){ btn.disabled = true; btn.textContent = 'Exportando...'; }

    const j = await post('ripex_portal_export_my_orders', {});
    if (!j.success){
      alert(j.data?.message || 'Error al exportar.');
      if(btn){ btn.disabled = false; btn.textContent = 'Exportar mis pedidos'; }
      return;
    }

    const filename = j.data.filename || 'mis-pedidos.csv';
    const csvBase64 = j.data.csv;
    const bytes = Uint8Array.from(atob(csvBase64), c => c.charCodeAt(0));
    const blob = new Blob([bytes], { type: 'text/csv;charset=utf-8;' });

    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();

    if(btn){ btn.disabled = false; btn.textContent = 'Exportar mis pedidos'; }
  }

  function getSelectedOrderIds(){
    return Array.from(selectedOrderIds).map(v => Number(v)).filter(Boolean);
  }

  function toggleOrderSelection(orderId, checked){
    const id = Number(orderId);
    if (!id) return;
    if (checked) selectedOrderIds.add(id);
    else selectedOrderIds.delete(id);
    syncSelectAllCheckbox();
  }

  function syncSelectAllCheckbox(){
    const all = document.getElementById('rp-select-all-orders');
    const rowChecks = Array.from(document.querySelectorAll('.rp-order-check'));
    if (!all) return;
    if (!rowChecks.length){
      all.checked = false;
      all.indeterminate = false;
      return;
    }
    const checkedCount = rowChecks.filter(ch => ch.checked).length;
    all.checked = checkedCount === rowChecks.length;
    all.indeterminate = checkedCount > 0 && checkedCount < rowChecks.length;
  }

  async function exportSelectedOrders(format){
    const ids = getSelectedOrderIds();
    if (!ids.length){
      alert('Debes seleccionar al menos un pedido.');
      return;
    }

    const action = format === 'pdf' ? 'Generando PDF...' : 'Exportando CSV...';
    const btn = document.getElementById(format === 'pdf' ? 'rp-export-selected-pdf' : 'rp-export-selected-csv');
    setButtonLoading(btn, true, action);

    const j = await post('ripex_portal_export_selected_orders', { order_ids: JSON.stringify(ids), format });
    if (!j.success){
      alert(j.data?.message || 'No se pudo exportar los pedidos seleccionados.');
      setButtonLoading(btn, false);
      showUiToast(j.data?.message || 'No se pudo exportar.', 'error');
      return;
    }

    if (format === 'csv'){
      const filename = j.data.filename || 'pedidos-seleccionados.csv';
      const csvBase64 = j.data.csv;
      const bytes = Uint8Array.from(atob(csvBase64), c => c.charCodeAt(0));
      const blob = new Blob([bytes], { type: 'text/csv;charset=utf-8;' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setButtonLoading(btn, false);
      showUiToast('CSV generado.');
      await loadOrders();
      return;
    }

    const orders = Array.isArray(j.data.orders) ? j.data.orders : [];
    if (!orders.length){
      alert('No hay pedidos válidos para exportar.');
      setButtonLoading(btn, false);
      showUiToast('No hay pedidos válidos para exportar.', 'warn');
      return;
    }

    const rowsPerPage = 48;
    const chunkItems = (items, size) => {
      const chunks = [];
      const safeItems = Array.isArray(items) ? items : [];
      for (let i = 0; i < safeItems.length; i += size) chunks.push(safeItems.slice(i, i + size));
      return chunks.length ? chunks : [[]];
    };

    const orderPages = orders.map(order => {
      const chunks = chunkItems(order.items || [], rowsPerPage);
      return chunks.map((items, pageIndex) => {
        const rows = items.map(item => `
          <tr>
            <td class="rp-pdf-transport-cell">${escapeHtml(item.transport || '—')}</td>
            <td class="rp-pdf-product">${escapeHtml(item.product || item.description || item.name || '—')}</td>
            <td class="rp-pdf-sku">${escapeHtml(item.sku || '—')}</td>
            <td class="rp-pdf-number">${escapeHtml(String(item.qty ?? '—'))}</td>
            <td class="rp-pdf-number">${escapeHtml(String(item.stock ?? '—'))}</td>
          </tr>
        `).join('') || '<tr><td colspan="5">Sin productos para mostrar.</td></tr>';

        const pageInfo = chunks.length > 1 ? ` · Página ${pageIndex + 1} de ${chunks.length}` : '';
        return `
          <section class="rp-pdf-page">
            <header class="rp-pdf-header">
              <div class="rp-pdf-left">
                <h1>Pedido #${escapeHtml(order.number || '')}</h1>
                <div class="rp-pdf-muted">Fecha: ${escapeHtml(order.date || '')}${escapeHtml(pageInfo)}</div>
              </div>
              <div class="rp-pdf-customer">
                <div><strong>Rut:</strong> ${escapeHtml(order.rut || '—')}</div>
                <div><strong>Razón social:</strong> ${escapeHtml(order.razon_social || '—')}</div>
                <div><strong>Giro:</strong> ${escapeHtml(order.giro || '—')}</div>
                <div><strong>Dirección:</strong> ${escapeHtml(order.direccion || '—')}</div>
              </div>
            </header>

            <table class="rp-pdf-table">
              <thead>
                <tr>
                  <th class="rp-pdf-transport">Transporte</th>
                  <th class="rp-pdf-product-head">Producto</th>
                  <th class="rp-pdf-sku-head">SKU</th>
                  <th class="rp-pdf-qty">Cantidad</th>
                  <th class="rp-pdf-stock">Stock</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </section>
        `;
      }).join('');
    }).join('');

    const w = window.open('', '_blank', 'width=1200,height=900');
    if (!w){
      alert('El navegador bloqueó la ventana emergente para generar el PDF.');
      setButtonLoading(btn, false);
      showUiToast('El navegador bloqueó la ventana emergente del PDF.', 'warn');
      return;
    }

    const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Pedidos RIPEX</title>
<style>
  @page{size:A4;margin:7mm;}
  *{box-sizing:border-box;}
  html,body{width:auto;height:auto;}
  body{font-family:Arial,Helvetica,sans-serif;color:#111827;background:#fff;margin:0;font-size:10.5px;}
  .rp-pdf-page{page-break-after:always;break-after:page;padding:0;}
  .rp-pdf-page:last-child{page-break-after:auto;break-after:auto;}
  .rp-pdf-header{display:grid;grid-template-columns:0.85fr 1.35fr;gap:10px;align-items:start;margin-bottom:5px;padding-bottom:4px;border-bottom:1.2px solid #111827;}
  .rp-pdf-left h1{margin:0;font-size:18px;line-height:1.05;}
  .rp-pdf-muted{margin-top:2px;color:#4b5563;font-size:10px;line-height:1.15;}
  .rp-pdf-customer{text-align:right;font-size:10px;line-height:1.3;word-break:break-word;}
  .rp-pdf-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:10px;}
  .rp-pdf-table thead{display:table-header-group;}
  .rp-pdf-table tr{page-break-inside:avoid;break-inside:avoid;}
  .rp-pdf-table th,.rp-pdf-table td{border:0.75px solid #111827;padding:2px 3.5px;vertical-align:middle;line-height:1.2;}
  .rp-pdf-table th{background:#111827;color:#fff;text-align:left;font-weight:700;font-size:10px;}
  .rp-pdf-transport{width:16%;}
  .rp-pdf-product-head{width:47%;}
  .rp-pdf-sku-head{width:18%;}
  .rp-pdf-qty{width:9%;text-align:right;}
  .rp-pdf-stock{width:10%;text-align:right;}
  .rp-pdf-transport-cell,.rp-pdf-sku{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .rp-pdf-product{overflow-wrap:anywhere;word-break:normal;}
  .rp-pdf-number{text-align:right;white-space:nowrap;}
  @media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact;}.rp-pdf-table th{background:#111827!important;color:#fff!important;}}
</style>
</head>
<body>${orderPages}</body>
</html>`;

    w.document.open();
    w.document.write(html);
    w.document.close();
    setTimeout(() => { w.focus(); w.print(); }, 500);
    setButtonLoading(btn, false);
    showUiToast('PDF listo para imprimir.');
    await loadOrders();
  }

  // Recarga el listado desde la página indicada (o desde currentPage si se omite).
  // Cualquier cambio de filtro llama a resetAndLoad() que primero resetea a p.1.
  async function loadOrders(pageOverride){
    const page = (typeof pageOverride === 'number') ? pageOverride : currentPage;
    setLoading('#rp-orders-loading', '#rp-orders-empty', true);
    setButtonLoading(document.getElementById('rp-refresh-orders'), true, 'Actualizando...');
    renderSkeletonRows('#rp-orders-table tbody', 11, 7);
    hidePagination();

    const status   = $('#rp-order-status')?.value || '';
    const search   = $('#rp-order-search')?.value || '';
    const date_from = $('#rp-order-date-from')?.value || '';
    const date_to   = $('#rp-order-date-to')?.value || '';
    const scope     = (RIPEX_PORTAL.role === 'ripex_vendedor') ? currentScope : '';

    const j = await post('ripex_portal_get_orders', { status, search, scope, date_from, date_to, page });
    setButtonLoading(document.getElementById('rp-refresh-orders'), false);

    const tbody = $('#rp-orders-table tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!j.success){
      setLoading('#rp-orders-loading', '#rp-orders-empty', false);
      setEmpty('#rp-orders-empty', true);
      $('#rp-orders-empty').textContent = j.data?.message || 'Error al cargar pedidos.';
      return;
    }

    const role       = j.data.role;
    const orders     = j.data.orders || [];
    const totalPages = j.data.total_pages || 1;
    const total      = j.data.total      || 0;
    currentPage      = j.data.page       || page;

    const showTotal   = role !== 'ripex_bodeguero';
    const showPayment = role !== 'ripex_bodeguero';

    $$('.rp-col-total').forEach(el => el.style.display = showTotal ? '' : 'none');
    $$('.rp-col-payment').forEach(el => el.style.display = showPayment ? '' : 'none');

    orders.forEach(o => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td data-col="select" class="rp-col-select"><input type="checkbox" class="rp-order-check" data-order-check="${o.id}" ${selectedOrderIds.has(Number(o.id)) ? 'checked' : ''}></td>
        <td data-col="id"><strong>#${escapeHtml(o.number)}</strong></td>
        <td data-col="fecha"><span class="rp-small">${escapeHtml(o.date || '')}</span></td>
        <td data-col="cliente">
          <div><strong>${escapeHtml(o.customer || '')}</strong></div>
          <div class="rp-small">${escapeHtml(o.company || '')}</div>
          ${o.rut ? `<div class="rp-small">RUT: ${escapeHtml(o.rut)}</div>` : ``}
        </td>
        <td data-col="estado"><span class="rp-badge ${badgeClass(o.status)}">${escapeHtml(o.status_label || o.status)}</span></td>
        <td class="rp-col-payment" data-col="metodo_pago">${showPayment ? escapeHtml(o.payment || '—') : ''}</td>
        <td data-col="vendedor">${escapeHtml(o.vendedor || '—')}</td>
        <td data-col="exportacion"><span class="rp-export-dot ${o.exported ? 'is-exported' : 'is-pending'}" title="${o.exported ? 'Exportado por bodega' : 'Aún no exportado'}"></span></td>
        <td class="rp-col-total" data-col="total">${showTotal ? money(o.total, o.currency) : '—'}</td>
        <td data-col="envio">${escapeHtml(o.shipping || '—')}</td>
        <td data-col="acciones">
          <div class="rp-row-actions">
            <button class="rp-icon-action" data-open-order="${o.id}" title="Ver" aria-label="Ver">
              <span class="rp-action-ico" aria-hidden="true">👁</span>
            </button>
            ${o.can_edit ? `<button class="rp-icon-action" data-edit-order="${o.id}" title="Editar" aria-label="Editar"><span class="rp-action-ico" aria-hidden="true">✎</span></button>` : ``}
          </div>
        </td>
      `;
      tbody.appendChild(tr);
    });

    setLoading('#rp-orders-loading', '#rp-orders-empty', false);
    setEmpty('#rp-orders-empty', orders.length === 0);
    renderPagination(currentPage, totalPages, total);

    applyOrderColumnVisibility();
    $$('[data-open-order]').forEach(btn => btn.addEventListener('click', () => openOrder(btn.dataset.openOrder)));
    $$('.rp-order-check').forEach(ch => ch.addEventListener('change', () => toggleOrderSelection(ch.dataset.orderCheck, ch.checked)));
    document.getElementById('rp-select-all-orders')?.addEventListener('change', (e) => {
      const checked = !!e.target.checked;
      $$('.rp-order-check').forEach(ch => { ch.checked = checked; toggleOrderSelection(ch.dataset.orderCheck, checked); });
      syncSelectAllCheckbox();
    });
    syncSelectAllCheckbox();
  }

  // Resetea a la primera página y recarga (se llama cuando cambia un filtro).
  function resetAndLoad(){
    currentPage = 1;
    loadOrders(1);
  }

  function hidePagination(){
    const nav = document.getElementById('rp-orders-pagination');
    if (nav) nav.style.display = 'none';
  }

  function renderPagination(page, totalPages, total){
    const nav  = document.getElementById('rp-orders-pagination');
    const info = document.getElementById('rp-orders-page-info');
    const prev = document.getElementById('rp-orders-prev');
    const next = document.getElementById('rp-orders-next');
    if (!nav || !info || !prev || !next) return;

    if (totalPages <= 1){
      nav.style.display = 'none';
      return;
    }

    info.textContent  = `Página ${page} de ${totalPages}  ·  ${total} pedidos`;
    prev.disabled     = page <= 1;
    next.disabled     = page >= totalPages;
    nav.style.display = 'flex';
  }

  function openDrawer(show){
    const drawer = $('#rp-order-drawer'); if (!drawer) return;
    const wasOpen = drawer.getAttribute('aria-hidden') === 'false';
    drawer.setAttribute('aria-hidden', show ? 'false' : 'true');
    if (show && !wasOpen) openOverlay(drawer);
    else if (!show && wasOpen) closeOverlay();
  }

  function bindDrawer(){
    const close = () => openDrawer(false);
    $('#rp-drawer-close')?.addEventListener('click', close);
    $('#rp-drawer-x')?.addEventListener('click', close);
    document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') close(); });
  }
  function linesToHtml(lines){
    if (!Array.isArray(lines) || !lines.length) return '—';
    return lines.map(l => escapeHtml(l)).join('<br>');
  }

  function optionHtml(value, label, selected=false){
    return `<option value="${escapeHtml(value)}" ${selected ? 'selected' : ''}>${escapeHtml(label)}</option>`;
  }

  async function getShippingMethods(){
    if (shippingMethodsCache) return shippingMethodsCache;
    const j = await post('ripex_portal_get_shipping_methods', {});
    shippingMethodsCache = (j.success && Array.isArray(j.data.shipping_methods)) ? j.data.shipping_methods : [];
    return shippingMethodsCache;
  }

  function openCartDrawer(show){ openDrawer(show); }

  function cartItemsHtml(items, full=false){
    const list = Array.isArray(items) ? items : [];
    const rows = full ? list : list.slice(0,3);
    if (!rows.length) return '<div class="rp-empty-inline">Sin productos.</div>';
    const html = rows.map(i => `
      <div class="rp-cart-item-row">
        <div>
          <strong>${escapeHtml(i.name || '—')}</strong>
          <div class="rp-small">SKU: ${escapeHtml(i.sku || '—')} · Stock: ${escapeHtml(i.stock ?? '—')}</div>
        </div>
        <div class="rp-cart-item-qty">x${escapeHtml(String(i.qty || 0))}</div>
        <div class="rp-cart-item-total">${money(i.subtotal || 0)}</div>
      </div>
    `).join('');
    const more = (!full && list.length > rows.length) ? `<div class="rp-small">+${list.length - rows.length} productos más</div>` : '';
    return `<div class="rp-cart-items-list">${html}${more}</div>`;
  }

  async function openCartDetail(sessionId){
    const cart = lastCarts.find(c => String(c.session_id) === String(sessionId));
    if (!cart) return alert('No se encontró el carrito en la vista actual. Actualiza carritos.');

    openCartDrawer(true);
    $('#rp-order-title').textContent = `Carrito de ${cart.customer_name || 'Cliente'}`;
    $('#rp-order-subtitle').textContent = `${cart.customer_email || ''} · ${cart.items_count || 0} ítems · ${money(cart.total || 0)}`;
    $('#rp-order-content').innerHTML = loadingHtml('Cargando detalle del carrito...', 35);

    const shipping = await getShippingMethods();
    const shippingOptions = ['<option value="">Seleccionar transporte</option>'].concat(shipping.map(m => optionHtml(m.key, `${m.title}${m.zone ? ' · '+m.zone : ''}`))).join('');
    const payments = Array.isArray(cart.payment_methods) && cart.payment_methods.length ? cart.payment_methods : [{id:'bacs', title:'Transferencia bancaria directa'}];
    const paymentOptions = payments.map((m, idx) => optionHtml(m.id, m.title, idx === 0)).join('');

    $('#rp-order-content').innerHTML = `
      <div class="rp-section rp-cart-detail">
        <h3>Cliente</h3>
        <div class="rp-kv">
          <div><div class="rp-k">Nombre / razón social</div><div class="rp-v">${escapeHtml(cart.customer_name || '—')}</div></div>
          <div><div class="rp-k">RUT</div><div class="rp-v">${escapeHtml(cart.customer_rut || '—')}</div></div>
          <div><div class="rp-k">Contacto</div><div class="rp-v">${escapeHtml(cart.customer_email || '—')}</div></div>
          <div><div class="rp-k">Vendedor</div><div class="rp-v">${escapeHtml(cart.customer_vendedor || '—')}</div></div>
        </div>
      </div>
      <div class="rp-section rp-cart-detail">
        <h3>Productos del carrito</h3>
        ${cartItemsHtml(cart.items || [], true)}
      </div>
      <div class="rp-section rp-cart-close-box">
        <h3>Cerrar carrito como pedido</h3>
        <div class="rp-form-grid-2">
          <label><span class="rp-k">Transporte</span><select class="rp-select" id="rp-cart-shipping-key">${shippingOptions}</select></label>
          <label><span class="rp-k">Tipo de pago</span><select class="rp-select" id="rp-cart-payment-method">${paymentOptions}</select></label>
        </div>
        <div class="rp-cart-close-actions">
          <button class="rp-btn" id="rp-cart-close-cancel" type="button">Cancelar</button>
          <button class="rp-btn rp-btn-primary" id="rp-cart-close-confirm" type="button">Cerrar como pedido</button>
        </div>
      </div>
    `;

    document.getElementById('rp-cart-close-cancel')?.addEventListener('click', () => openCartDrawer(false));
    document.getElementById('rp-cart-close-confirm')?.addEventListener('click', () => closeCart(sessionId));
  }

  async function openOrder(orderId){
    openDrawer(true);
    $('#rp-order-content').innerHTML = loadingHtml('Cargando detalle del pedido...', 45);

    const j = await post('ripex_portal_get_order', { order_id: orderId });

    if (!j.success){
      $('#rp-order-content').innerHTML = `<div class="rp-empty">${escapeHtml(j.data?.message || 'Error')}</div>`;
      return;
    }

    const role = j.data.role;
    const o = j.data.order;

    $('#rp-order-title').textContent = `Pedido #${o.number}`;
    $('#rp-order-subtitle').textContent = `${o.date} · ${o.status_label} · ${o.payment || ''}`;

    const billing = o.billing || {};
    const shippingLines = o.shipping_address_lines || [];
    const af = o.afreg || {};

    const addressLines = Array.isArray(shippingLines) ? [...shippingLines] : [];
    const billingNameNorm = (billing.name || '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (addressLines.length && billingNameNorm) {
      const firstNorm = (addressLines[0] || '').replace(/\s+/g, ' ').trim().toLowerCase();
      if (firstNorm === billingNameNorm) addressLines.shift();
    }
    const items = o.items || [];

    const showTotals = role !== 'ripex_bodeguero';
    const totals = o.totals || {};

    const itemsHtml = items.map(it => {
      const right = showTotals && it.total !== undefined ? money(it.total, totals.currency) : '';
      return `
        <div class="rp-li">
          <div>
            <div><strong>${escapeHtml(it.name)}</strong></div>
            <div class="rp-small">SKU: ${escapeHtml(it.sku || '—')} · Stock: ${escapeHtml(it.stock ?? '—')}</div>
          </div>
          <div style="text-align:right;">
            <div><strong>x${escapeHtml(it.qty)}</strong></div>
            ${right ? `<div class="rp-small">${right}</div>` : ''}
          </div>
        </div>
      `;
    }).join('');

    const exportBtn = (o.can_export)
      ? `<button class="rp-btn rp-btn-primary" id="rp-export-order" data-order="${o.id}">Exportar (Excel/CSV)</button>`
      : '';

    const totalsHtml = showTotals ? `
      <div class="rp-section">
        <h3>Totales</h3>
        <div class="rp-kv">
          <div><div class="rp-k">Subtotal</div><div class="rp-v">${money(totals.subtotal, totals.currency)}</div></div>
          <div><div class="rp-k">Envío</div><div class="rp-v">${money(totals.shipping, totals.currency)}</div></div>
          <div><div class="rp-k">Descuento</div><div class="rp-v">${money(totals.discount, totals.currency)}</div></div>
          <div><div class="rp-k">Total</div><div class="rp-v">${money(totals.total, totals.currency)}</div></div>
        </div>
      </div>
    ` : '';

    $('#rp-order-content').innerHTML = `
      <div class="rp-kv">
        <div><div class="rp-k">Cliente</div><div class="rp-v">${escapeHtml(billing.name || '—')}</div></div>
        <div><div class="rp-k">Rut</div><div class="rp-v">${escapeHtml(af.rut || '—')}</div></div>
        <div><div class="rp-k">Razón Social</div><div class="rp-v">${escapeHtml(af.razon_social || '—')}</div></div>
        <div><div class="rp-k">Giro</div><div class="rp-v">${escapeHtml(af.giro || '—')}</div></div>
        <div><div class="rp-k">Vendedor</div><div class="rp-v">${escapeHtml(af.vendedor || '—')}</div></div>
        <div><div class="rp-k">Exportación</div><div class="rp-v">${o.exported ? 'Exportado por bodega' : 'No exportado'}</div></div>
        <div><div class="rp-k">Dirección</div><div class="rp-v">${linesToHtml(addressLines)}</div></div>
        <div><div class="rp-k">Transporte</div><div class="rp-v">${escapeHtml(o.shipping || '—')}</div></div>
      </div>

      <div class="rp-section">
        <h3>Ítems</h3>
        <div class="rp-list">
          ${itemsHtml || '<div class="rp-li"><div>No hay ítems</div><div></div></div>'}
        </div>
      </div>

      ${totalsHtml}

      <div class="rp-section" style="display:flex; gap:10px; justify-content:flex-end;">
        ${exportBtn}
      </div>
    `;

    if (o.can_export) {
      $('#rp-export-order')?.addEventListener('click', async (e) => {
        const id = e.currentTarget.dataset.order;
        await exportOrder(id);
      });
    }
  }

  async function exportOrder(orderId){
    const btn = $('#rp-export-order');
    setButtonLoading(btn, true, 'Exportando...');

    const j = await post('ripex_portal_export_order', { order_id: orderId });

    if (!j.success){
      alert(j.data?.message || 'Error al exportar.');
      setButtonLoading(btn, false);
      showUiToast(j.data?.message || 'Error al exportar.', 'error');
      return;
    }

    const filename = j.data.filename || 'pedido.csv';
    const csvBase64 = j.data.csv;
    const bytes = Uint8Array.from(atob(csvBase64), c => c.charCodeAt(0));
    const blob = new Blob([bytes], { type: 'text/csv;charset=utf-8;' });

    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();

    setButtonLoading(btn, false);
    showUiToast('Exportación generada.');
  }

  function populateInventoryCategories(categories){
    const sel = document.getElementById('rp-inventory-category');
    if (!sel || sel.dataset.loaded === '1') return;

    const current = sel.value || '';
    sel.innerHTML = '<option value="">Todas las categorías</option>' + (categories || []).map(cat => {
      return `<option value="${escapeHtml(cat.slug || '')}">${escapeHtml(cat.name || '')}</option>`;
    }).join('');
    sel.value = current;
    sel.dataset.loaded = '1';
  }

  async function loadProductsInventory(){
    setLoading('#rp-products-loading', '#rp-products-empty', true);
    setButtonLoading(document.getElementById('rp-refresh-products'), true, 'Actualizando...');
    renderSkeletonRows('#rp-products-table tbody', 4, 6);
    const search = $('#rp-product-search-inv')?.value || '';
    const category = $('#rp-inventory-category')?.value || '';
    const orderby = $('#rp-inventory-orderby')?.value || 'name_asc';
    const j = await post('ripex_portal_get_products', { search, category, orderby });

    const tbody = $('#rp-products-table tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!j.success){
      setButtonLoading(document.getElementById('rp-refresh-products'), false);
      setLoading('#rp-products-loading', '#rp-products-empty', false);
      setEmpty('#rp-products-empty', true);
      $('#rp-products-empty').textContent = j.data?.message || 'Error al cargar inventario.';
      return;
    }

    populateInventoryCategories(j.data.categories || []);

    const products = j.data.products || [];
    const isPortalAdmin = RIPEX_PORTAL.role === 'ripex_admin';
    products.forEach(p => {
      const editUrl = `${RIPEX_PORTAL.ajaxUrl.replace('admin-ajax.php','')}post.php?post=${p.id}&action=edit`;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${escapeHtml(p.sku || '—')}</strong></td>
        <td>
          <div><strong>${escapeHtml(p.name || '')}</strong></div>
          <div class="rp-small">${escapeHtml(p.categories || 'Sin categoría')}</div>
        </td>
        <td>
          <div class="rp-stock-cell">
            <span>${escapeHtml(p.stock ?? '')}</span>
            ${isPortalAdmin ? `<a class="rp-icon-action" href="${editUrl}" target="_blank" rel="noopener" title="Editar producto" aria-label="Editar producto"><span class="rp-action-ico" aria-hidden="true">✎</span></a>` : ``}
          </div>
        </td>
        <td><span class="rp-badge">${escapeHtml(p.status_label || p.status || '')}</span></td>
      `;
      tbody.appendChild(tr);
    });

    setButtonLoading(document.getElementById('rp-refresh-products'), false);
    setLoading('#rp-products-loading', '#rp-products-empty', false);
    setEmpty('#rp-products-empty', products.length === 0);
  }

  function bindVendorSubtabs(){
    const subs = $$('.rp-subtab');
    if (!subs.length) return;

    currentScope = 'mine';
    subs.forEach(btn => btn.addEventListener('click', async () => {
      subs.forEach(x => x.classList.toggle('rp-subtab-active', x === btn));
      currentScope = btn.dataset.scope || 'mine';
      resetAndLoad();
    }));
  }


  function getReportKpisConfig(){
    return [
      { key:'revenue', label:'Ventas' },
      { key:'orders', label:'Pedidos' },
      { key:'avg_ticket', label:'Ticket promedio' },
      { key:'customers', label:'Clientes únicos' },
      { key:'items', label:'Unidades vendidas' },
      { key:'stock', label:'Stock total visible' },
      { key:'active_sellers', label:'Vendedores activos' },
      { key:'low_stock_count', label:'Stock crítico' },
    ];
  }

  function getReportPrefsKey(){
    return `ripex_portal_report_kpis_${RIPEX_PORTAL.role}`;
  }

  function getReportPrefs(){
    const defaults = {};
    getReportKpisConfig().forEach(k => defaults[k.key] = true);
    try{
      const raw = localStorage.getItem(getReportPrefsKey());
      if(!raw) return defaults;
      return { ...defaults, ...(JSON.parse(raw) || {}) };
    }catch(e){
      return defaults;
    }
  }

  function saveReportPrefs(prefs){
    localStorage.setItem(getReportPrefsKey(), JSON.stringify(prefs));
  }

  function applyReportKpiVisibility(){
    const prefs = getReportPrefs();
    Object.keys(prefs).forEach(key => {
      document.querySelectorAll(`[data-kpi-card="${key}"]`).forEach(el => {
        el.style.display = prefs[key] === false ? 'none' : '';
      });
    });
  }

  function renderKpiMenu(){
    const menu = document.getElementById('rp-kpis-menu');
    const btn = document.getElementById('rp-kpis-toggle');
    if(!menu || !btn) return;

    const prefs = getReportPrefs();
    menu.innerHTML = getReportKpisConfig().map(k => `
      <label class="rp-colmenu-item">
        <input type="checkbox" data-kpi-toggle="${k.key}" ${prefs[k.key] !== false ? 'checked' : ''}>
        <span>${k.label}</span>
      </label>
    `).join('');

    menu.querySelectorAll('[data-kpi-toggle]').forEach(input => {
      input.addEventListener('change', () => {
        const next = getReportPrefs();
        next[input.dataset.kpiToggle] = !!input.checked;
        saveReportPrefs(next);
        applyReportKpiVisibility();
      });
    });

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    });

    document.addEventListener('click', (e) => {
      if (!menu.contains(e.target) && e.target !== btn) menu.style.display = 'none';
    });

    applyReportKpiVisibility();
  }

  function setReportLoading(show){
    const el = document.getElementById('rp-reports-loading');
    if(el) {
      el.style.display = show ? 'block' : 'none';
      if (show) {
        startFakeProgress('#rp-reports-loading', 'Calculando indicadores...');
        renderReportSkeleton();
      } else {
        clearInterval(_rpProgressTimer);
      }
    }
  }

  function formatNumber(n){
    try{ return new Intl.NumberFormat('es-CL').format(Number(n || 0)); }catch(e){ return n; }
  }

  function renderLineChart(containerId, series){
    const el = document.getElementById(containerId);
    if(!el) return;
    const data = Array.isArray(series) ? series : [];
    if(!data.length){
      el.innerHTML = '<div class="rp-empty">Sin datos para el período.</div>';
      return;
    }

    const width = 760;
    const height = 220;
    const padX = 26;
    const padY = 20;
    const max = Math.max(...data.map(d => Number(d.value || 0)), 1);
    const stepX = data.length > 1 ? (width - padX*2) / (data.length - 1) : 0;

    const points = data.map((d, i) => {
      const x = padX + i * stepX;
      const y = height - padY - ((Number(d.value || 0) / max) * (height - padY*2));
      return { x, y, label:d.label, value:Number(d.value || 0) };
    });

    const polyline = points.map(p => `${p.x},${p.y}`).join(' ');
    const area = `0,${height-padY} ${points.map(p => `${p.x},${p.y}`).join(' ')} ${width},${height-padY}`;
    const ticks = [0, .25, .5, .75, 1].map(r => {
      const y = height - padY - (r * (height - padY*2));
      const val = max * r;
      return `<line x1="${padX}" y1="${y}" x2="${width-padX}" y2="${y}" class="rp-chart-grid"></line>
              <text x="0" y="${y+4}" class="rp-chart-axis">${money(val, 'CLP')}</text>`;
    }).join('');

    const circles = points.map(p => `<circle cx="${p.x}" cy="${p.y}" r="3.5" class="rp-chart-point"><title>${p.label}: ${money(p.value, 'CLP')}</title></circle>`).join('');
    const labels = points.filter((_,i)=> data.length <= 10 || i % Math.ceil(data.length / 8) === 0 || i===data.length-1)
      .map(p => `<text x="${p.x}" y="${height-2}" class="rp-chart-axis" text-anchor="middle">${p.label}</text>`).join('');

    el.innerHTML = `
      <svg viewBox="0 0 ${width} ${height}" class="rp-svg-chart" aria-label="Ventas por día">
        ${ticks}
        <polygon points="${area}" class="rp-chart-area"></polygon>
        <polyline points="${polyline}" class="rp-chart-line"></polyline>
        ${circles}
        ${labels}
      </svg>
    `;
  }

  function renderBarList(containerId, rows, options = {}){
    const el = document.getElementById(containerId);
    if(!el) return;
    const data = Array.isArray(rows) ? rows : [];
    if(!data.length){
      el.innerHTML = '<div class="rp-empty">Sin datos para el período.</div>';
      return;
    }
    const max = Math.max(...data.map(r => Number(r.value || r.revenue || r.qty || r.count || 0)), 1);
    const mode = options.mode || 'currency';
    const html = data.map(r => {
      const rawValue = Number(r.value ?? r.revenue ?? r.qty ?? r.count ?? 0);
      const width = Math.max(4, Math.round((rawValue / max) * 100));
      let labelRight = money(rawValue, 'CLP');
      if (mode === 'qty') labelRight = `${formatNumber(rawValue)} uds.`;
      if (mode === 'count') labelRight = `${formatNumber(rawValue)} pedidos`;
      const sub = r.sub || '';
      return `
        <div class="rp-bar-row">
          <div class="rp-bar-head">
            <div class="rp-bar-title">${escapeHtml(r.name || '—')}</div>
            <div class="rp-bar-value">${escapeHtml(labelRight)}</div>
          </div>
          ${sub ? `<div class="rp-bar-sub">${escapeHtml(sub)}</div>` : ``}
          <div class="rp-bar-track"><div class="rp-bar-fill" style="width:${width}%"></div></div>
        </div>
      `;
    }).join('');
    el.innerHTML = `<div class="rp-bar-list">${html}</div>`;
  }

  function renderLowStock(containerId, rows){
    const el = document.getElementById(containerId);
    if(!el) return;
    const data = Array.isArray(rows) ? rows : [];
    if(!data.length){
      el.innerHTML = '<div class="rp-empty">No hay alertas de stock bajo.</div>';
      return;
    }
    const html = `
      <div class="rp-mini-table">
        <div class="rp-mini-table-head">
          <span>Producto</span><span>SKU</span><span>Stock</span>
        </div>
        ${data.map(r => `
          <div class="rp-mini-table-row">
            <span>${escapeHtml(r.name || '—')}</span>
            <span>${escapeHtml(r.sku || '—')}</span>
            <span class="rp-stock-pill ${Number(r.stock || 0) <= 3 ? 'critical' : ''}">${escapeHtml(String(r.stock ?? '—'))}</span>
          </div>
        `).join('')}
      </div>
    `;
    el.innerHTML = html;
  }

  function renderInfoList(containerId, rows, type){
    const el = document.getElementById(containerId);
    if(!el) return;
    const data = Array.isArray(rows) ? rows : [];
    if(!data.length){
      el.innerHTML = '<div class="rp-empty">Sin datos para el período.</div>';
      return;
    }

    const html = data.map(r => {
      if (type === 'inactive') {
        return `
          <div class="rp-info-card">
            <div class="rp-info-head">
              <div class="rp-info-title">${escapeHtml(r.name || '—')}</div>
              <div class="rp-info-badge">${escapeHtml(String(r.days || 0))} días</div>
            </div>
            <div class="rp-info-sub">${escapeHtml(r.email || 'Sin email')} · Última compra: ${escapeHtml(r.last_date || '—')}</div>
            <div class="rp-info-sub">Pedidos: ${escapeHtml(String(r.orders || 0))} · Ventas históricas: ${escapeHtml(money(r.revenue || 0, 'CLP'))}</div>
          </div>
        `;
      }
      if (type === 'no_movement') {
        return `
          <div class="rp-info-card">
            <div class="rp-info-head">
              <div class="rp-info-title">${escapeHtml(r.name || '—')}</div>
              <div class="rp-info-badge">${escapeHtml(String(r.stock === '' ? '—' : r.stock))} stock</div>
            </div>
            <div class="rp-info-sub">${escapeHtml(r.sku || 'Sin SKU')}</div>
          </div>
        `;
      }
      return '';
    }).join('');

    el.innerHTML = `<div class="rp-info-list">${html}</div>`;
  }

  async function loadReports(forceRefresh){
    // Reportes disponibles para admin y vendedor (cada uno con su alcance).
    if (!['ripex_admin','ripex_vendedor'].includes(RIPEX_PORTAL.role)) return;
    const from = document.getElementById('rp-report-date-from')?.value || '';
    const to = document.getElementById('rp-report-date-to')?.value || '';

    // force_refresh=1 cuando el usuario pulsa "Actualizar", para ignorar caché.
    const payload = { date_from: from, date_to: to };
    if (forceRefresh) payload.force_refresh = 1;

    setButtonLoading(document.getElementById('rp-refresh-reports'), true, forceRefresh ? 'Recalculando...' : 'Cargando...');
    setReportLoading(true);
    const j = await post('ripex_portal_get_reports', payload);
    stopFakeProgress('#rp-reports-loading', 'Reportes listos');
    setReportLoading(false);
    setButtonLoading(document.getElementById('rp-refresh-reports'), false);

    const reportContainers = [
      'rp-chart-sales-line',
      'rp-chart-sellers',
      'rp-chart-statuses',
      'rp-chart-transports',
      'rp-chart-top-products',
      'rp-chart-top-companies',
      'rp-report-inactive-customers',
      'rp-report-no-movement',
      'rp-report-low-stock'
    ];

    if(!j.success){
      const err = j.data?.message || 'Error al cargar reportes.';
      reportContainers.forEach(id => {
        const el = document.getElementById(id);
        if(el) el.innerHTML = `<div class="rp-empty">${escapeHtml(err)}</div>`;
      });
      return;
    }

    const data = j.data || {};
    const filters = data.filters || {};
    const kpis = data.kpis || {};
    const charts = data.charts || {};
    const tables = data.tables || {};

    if(document.getElementById('rp-report-date-from') && filters.date_from) document.getElementById('rp-report-date-from').value = filters.date_from;
    if(document.getElementById('rp-report-date-to') && filters.date_to) document.getElementById('rp-report-date-to').value = filters.date_to;

    document.getElementById('rp-kpi-revenue').textContent = money(kpis.revenue || 0, 'CLP');
    document.getElementById('rp-kpi-revenue-sub').textContent = `${filters.date_from || ''} a ${filters.date_to || ''}`;
    document.getElementById('rp-kpi-orders').textContent = formatNumber(kpis.orders || 0);
    document.getElementById('rp-kpi-avg').textContent = money(kpis.avg_ticket || 0, 'CLP');
    document.getElementById('rp-kpi-customers').textContent = formatNumber(kpis.customers || 0);
    document.getElementById('rp-kpi-items').textContent = formatNumber(kpis.items || 0);
    document.getElementById('rp-kpi-stock').textContent = formatNumber(kpis.stock || 0);
    document.getElementById('rp-kpi-sellers').textContent = formatNumber(kpis.active_sellers || 0);
    document.getElementById('rp-kpi-low-stock').textContent = formatNumber(kpis.low_stock_count || 0);

    renderLineChart('rp-chart-sales-line', charts.sales_series || []);
    renderBarList('rp-chart-sellers', (charts.seller_sales || []).map(r => ({
      name: r.name,
      revenue: r.revenue,
      sub: `${formatNumber(r.orders || 0)} pedidos`
    })), { mode: 'currency' });
    renderBarList('rp-chart-statuses', (charts.status_counts || []).map(r => ({
      name: r.name,
      count: r.count,
      sub: 'Estado del pedido'
    })), { mode: 'count' });
    renderBarList('rp-chart-transports', (charts.transport_sales || []).map(r => ({
      name: r.name,
      revenue: r.revenue,
      sub: `${formatNumber(r.orders || 0)} pedidos`
    })), { mode: 'currency' });
    renderBarList('rp-chart-top-products', (charts.top_products || []).map(r => ({
      name: r.name,
      qty: r.qty,
      sub: `${r.sku || 'Sin SKU'} · Stock actual: ${r.stock === '' ? '—' : r.stock}`
    })), { mode: 'qty' });
    renderBarList('rp-chart-top-companies', (charts.company_sales || []).map(r => ({
      name: r.name,
      revenue: r.revenue,
      sub: `${formatNumber(r.orders || 0)} pedidos`
    })), { mode: 'currency' });

    renderInfoList('rp-report-inactive-customers', tables.inactive_customers || [], 'inactive');
    renderInfoList('rp-report-no-movement', tables.no_movement || [], 'no_movement');
    renderLowStock('rp-report-low-stock', tables.low_stock || []);
    applyReportKpiVisibility();
  }


  function sanitizeForPdfClone(root){
    if (!root) return root;
    root.querySelectorAll('button, .rp-colmenu, .rp-row-actions, #rp-columns-menu, #rp-kpis-menu').forEach(el => {
      if (el && el.parentNode) el.parentNode.removeChild(el);
    });
    root.querySelectorAll('input, select').forEach(el => {
      const span = document.createElement('span');
      span.className = 'rp-pdf-filter-value';
      span.textContent = el.value || '—';
      el.parentNode.replaceChild(span, el);
    });
    return root;
  }

  function getVisibleTableHtml(tableSelector){
    const table = document.querySelector(tableSelector);
    if (!table) return '<div class="rp-empty">Sin datos.</div>';

    const clone = table.cloneNode(true);
    const originalRows = document.querySelectorAll(tableSelector + ' tr');

    clone.querySelectorAll('tr').forEach((tr, rowIdx) => {
      const originalTr = originalRows[rowIdx];
      if (!originalTr) return;
      const cells = Array.from(tr.children);
      cells.forEach((cell, idx) => {
        const originalCell = originalTr.children[idx];
        if (originalCell && window.getComputedStyle(originalCell).display === 'none') {
          cell.remove();
        }
      });
    });

    // remove action column/buttons if still present
    clone.querySelectorAll('.rp-row-actions').forEach(el => el.remove());
    clone.querySelectorAll('thead tr').forEach(tr => {
      const last = tr.lastElementChild;
      if (last && last.textContent.trim() === '') last.remove();
    });
    clone.querySelectorAll('tbody tr').forEach(tr => {
      const last = tr.lastElementChild;
      if (last && last.textContent.trim() === '') last.remove();
    });

    return clone.outerHTML;
  }

  function buildPdfWindow(title, subtitle, bodyHtml){
    const w = window.open('', '_blank', 'width=1200,height=900');
    if (!w) {
      alert('El navegador bloqueó la ventana emergente para generar el PDF.');
      return null;
    }

    const docHtml = `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>${escapeHtml(title)}</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;color:#111827;margin:24px;}
  .pdf-wrap{max-width:1200px;margin:0 auto;}
  .pdf-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #e5e7eb;}
  .pdf-brand{display:flex;align-items:center;gap:14px;}
  .pdf-brand img{height:42px;width:auto;}
  .pdf-title{font-size:24px;font-weight:700;margin:0;}
  .pdf-subtitle{font-size:12px;color:#6b7280;margin-top:4px;}
  .pdf-date{font-size:12px;color:#6b7280;text-align:right;}
  .pdf-section{margin-top:14px;}
  table{width:100%;border-collapse:collapse;font-size:12px;}
  th,td{border:1px solid #e5e7eb;padding:8px 10px;vertical-align:top;text-align:left;}
  th{background:#f8fafc;font-weight:700;}
  .pdf-kpi-grid,.rp-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:14px;margin-bottom:14px;}
  .rp-kpi-card{border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff;}
  .rp-kpi-label{font-size:11px;color:#6b7280;font-weight:700;text-transform:uppercase;}
  .rp-kpi-value{font-size:22px;font-weight:700;margin-top:6px;}
  .rp-kpi-sub{font-size:11px;color:#6b7280;margin-top:6px;}
  .rp-report-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:14px;}
  .rp-report-card{border:1px solid #e5e7eb;border-radius:12px;padding:14px;break-inside:avoid;background:#fff;}
  .rp-report-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px;}
  .rp-report-head h3{margin:0;font-size:15px;}
  .rp-small,.rp-info-sub,.pdf-muted{font-size:11px;color:#6b7280;}
  .rp-bar-list,.rp-info-list{display:flex;flex-direction:column;gap:10px;}
  .rp-bar-head,.rp-info-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;}
  .rp-bar-title,.rp-info-title{font-weight:700;font-size:12px;}
  .rp-bar-value,.rp-info-badge{font-size:11px;font-weight:700;color:#1d4ed8;}
  .rp-bar-track{height:10px;background:#eef2ff;border-radius:999px;overflow:hidden;}
  .rp-bar-fill{height:100%;background:#0121ff;border-radius:999px;}
  .rp-mini-table{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;}
  .rp-mini-table-head,.rp-mini-table-row{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(0,1fr) 80px;gap:10px;padding:8px 10px;align-items:center;}
  .rp-mini-table-head{background:#f8fafc;font-size:11px;font-weight:700;color:#6b7280;}
  .rp-mini-table-row{border-top:1px solid #e5e7eb;font-size:12px;}
  .rp-stock-pill{display:inline-flex;justify-content:center;padding:4px 8px;border-radius:999px;background:#fffbeb;border:1px solid #fde68a;font-weight:700;color:#92400e;}
  .rp-stock-pill.critical{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
  .rp-svg-chart{width:100%;height:auto;}
  .rp-chart-grid{stroke:#e5e7eb;stroke-width:1;}
  .rp-chart-axis{fill:#64748b;font-size:10px;font-weight:700;}
  .rp-chart-area{fill:rgba(1,33,255,.08);}
  .rp-chart-line{fill:none;stroke:#0121ff;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;}
  .rp-chart-point{fill:#0121ff;}
  .rp-pdf-filter-value{display:inline-block;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:12px;min-width:100px;}
  .rp-info-card{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;}
  @media print{
    body{margin:12mm;}
    .pdf-wrap{max-width:none;}
    .rp-report-card,.rp-kpi-card,.rp-info-card{break-inside:avoid;}
  }
</style>
</head>
<body>
<div class="pdf-wrap">
  <div class="pdf-head">
    <div class="pdf-brand">
      <img src="${RIPEX_PORTAL.logoUrl}" alt="RIPEX">
      <div>
        <div class="pdf-title">${escapeHtml(title)}</div>
        <div class="pdf-subtitle">${escapeHtml(subtitle || '')}</div>
      </div>
    </div>
    <div class="pdf-date">Generado: ${new Date().toLocaleString('es-CL')}</div>
  </div>
  ${bodyHtml}
</div>
</body>
</html>`;
    w.document.open();
    w.document.write(docHtml);
    w.document.close();
    return w;
  }

  function exportOrdersPdf(){
    const title = 'Listado de pedidos';
    const search = document.getElementById('rp-order-search')?.value || 'Sin texto';
    const statusText = document.getElementById('rp-order-status')?.selectedOptions?.[0]?.textContent || 'Todos los estados';
    const scopeBtn = document.querySelector('.rp-subtab-active');
    const scopeText = scopeBtn ? scopeBtn.textContent.trim() : 'Todos';
    const tableHtml = getVisibleTableHtml('#rp-orders-table');

    const bodyHtml = `
      <div class="pdf-section">
        <div class="pdf-muted">Filtros aplicados</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;margin-bottom:14px;">
          <span class="rp-pdf-filter-value">Búsqueda: ${escapeHtml(search)}</span>
          <span class="rp-pdf-filter-value">Estado: ${escapeHtml(statusText)}</span>
          <span class="rp-pdf-filter-value">Vista: ${escapeHtml(scopeText)}</span>
        </div>
        ${tableHtml}
      </div>
    `;
    const w = buildPdfWindow(title, 'Exportación del listado actual de pedidos', bodyHtml);
    if (w) setTimeout(() => { w.focus(); w.print(); }, 500);
  }

  function exportReportsPdf(){
    const btn = document.getElementById('rp-export-reports-pdf');
    setButtonLoading(btn, true, 'Generando PDF...');
    const panel = document.querySelector('[data-panel="reports"]');
    if (!panel) { setButtonLoading(btn, false); return; }
    const clone = panel.cloneNode(true);
    sanitizeForPdfClone(clone);

    const title = 'Reporte comercial';
    const from = document.getElementById('rp-report-date-from')?.value || '';
    const to = document.getElementById('rp-report-date-to')?.value || '';
    const subtitle = from || to ? `Período: ${from || '—'} a ${to || '—'}` : 'Período actual';

    const bodyHtml = clone.innerHTML;
    const w = buildPdfWindow(title, subtitle, bodyHtml);
    if (w) setTimeout(() => { w.focus(); w.print(); }, 700);
    setTimeout(() => setButtonLoading(btn, false), 900);
  }


  async function exportOrdersByDateRange(){
    const from = document.getElementById('rp-bulk-date-from')?.value || '';
    const to = document.getElementById('rp-bulk-date-to')?.value || '';
    const onlyPending = document.getElementById('rp-bulk-only-pending-export')?.checked ? '1' : '';
    const btn = document.getElementById('rp-export-range-csv');

    if (!from || !to){
      alert('Debes seleccionar fecha desde y hasta.');
      return;
    }

    setButtonLoading(btn, true, 'Exportando...');

    const j = await post('ripex_portal_export_orders_by_date', {
      date_from: from,
      date_to: to,
      only_pending_export: onlyPending
    });

    if (!j.success){
      alert(j.data?.message || 'Error al exportar por rango.');
      setButtonLoading(btn, false);
      showUiToast(j.data?.message || 'Error al exportar por rango.', 'error');
      return;
    }

    const filename = j.data.filename || 'pedidos-rango.csv';
    const csvBase64 = j.data.csv;
    const bytes = Uint8Array.from(atob(csvBase64), c => c.charCodeAt(0));
    const blob = new Blob([bytes], { type: 'text/csv;charset=utf-8;' });

    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();

    showUiToast(`Se exportaron ${j.data.count_orders || 0} pedidos.`);
    setButtonLoading(btn, false);

    if (typeof loadOrders === 'function') loadOrders();
  }


  function populateCustomerCitySelect(cities){
    const select = document.getElementById('rp-customer-city-filter');
    if (!select || select.tagName !== 'SELECT') return;

    const current = select.value || '';
    const normalized = (value) => (value || '').toString().trim().toLowerCase();
    const unique = [];
    const seen = new Set();

    (Array.isArray(cities) ? cities : []).forEach(city => {
      const label = (city || '').toString().trim();
      if (!label) return;
      const key = normalized(label);
      if (seen.has(key)) return;
      seen.add(key);
      unique.push(label);
    });

    unique.sort((a,b) => a.localeCompare(b, 'es', { sensitivity:'base' }));

    const nextHtml = '<option value="">Cualquier ciudad</option>' + unique.map(city => `<option value="${escapeHtml(city)}">${escapeHtml(city)}</option>`).join('');
    if (select.innerHTML !== nextHtml) select.innerHTML = nextHtml;

    const hasCurrent = current === '' || unique.some(city => normalized(city) === normalized(current));
    select.value = hasCurrent ? current : '';
  }

  function populateCustomerVendorSelect(vendors){
    const select = document.getElementById('rp-customer-vendor-filter');
    if (!select || select.tagName !== 'SELECT') return;

    const current = select.value || '';
    const normalized = (value) => (value || '').toString().trim().toLowerCase();
    const unique = [];
    const seen = new Set();

    (Array.isArray(vendors) ? vendors : []).forEach(vendor => {
      const label = (vendor || '').toString().trim();
      if (!label) return;
      const key = normalized(label);
      if (seen.has(key)) return;
      seen.add(key);
      unique.push(label);
    });

    unique.sort((a,b) => a.localeCompare(b, 'es', { sensitivity:'base' }));

    const nextHtml = '<option value="">Todos los vendedores</option>' + unique.map(vendor => `<option value="${escapeHtml(vendor)}">${escapeHtml(vendor)}</option>`).join('');
    if (select.innerHTML !== nextHtml) select.innerHTML = nextHtml;

    const hasCurrent = current === '' || unique.some(vendor => normalized(vendor) === normalized(current));
    select.value = hasCurrent ? current : '';
  }

  async function loadCustomers(){
    const tbody = document.querySelector('#rp-customers-table tbody');
    const loading = document.getElementById('rp-customers-loading');
    const empty = document.getElementById('rp-customers-empty');
    if (!tbody) return;
    if (loading) { loading.style.display = 'block'; enhanceLoading('#rp-customers-loading', 'Cargando clientes...'); }
    setButtonLoading(document.getElementById('rp-refresh-customers'), true, 'Actualizando...');
    renderSkeletonRows('#rp-customers-table tbody', 5, 7);
    if (empty) empty.style.display = 'none';
    const search = document.getElementById('rp-customer-list-search')?.value || '';
    const city = document.getElementById('rp-customer-city-filter')?.value || '';
    const vendor = document.getElementById('rp-customer-vendor-filter')?.value || '';
    try{
      const j = await post('ripex_portal_get_customers', { search, city, vendor });
      setButtonLoading(document.getElementById('rp-refresh-customers'), false);
      if (loading) loading.style.display = 'none';
      if (!j.success){
        showTableMessage(tbody, empty, j.data?.message || 'No se pudieron cargar clientes.');
        return;
      }
      populateCustomerCitySelect(j.data.cities || []);
      populateCustomerVendorSelect(j.data.vendors || []);
      const rows = j.data.customers || [];
      tbody.innerHTML = rows.map(c => `
        <tr>
          <td class="rp-customer-name-cell">
            <div><strong>${escapeHtml(c.name || '—')}</strong></div>
            ${c.email ? `<div class="rp-small">${escapeHtml(c.email)}</div>` : ``}
          </td>
          <td class="rp-customer-business-cell">
            <div><strong>${escapeHtml(c.razon_social || '—')}</strong></div>
            ${c.giro ? `<div class="rp-small">${escapeHtml(c.giro)}</div>` : ``}
          </td>
          <td>${escapeHtml(c.city || '—')}<div class="rp-small">${escapeHtml(c.region || '')}</div></td>
          <td>${escapeHtml(c.rut || '—')}</td>
          <td><button class="rp-icon-action" data-customer-history="${c.id}" title="Historial" aria-label="Historial"><span class="rp-action-ico">📊</span></button></td>
        </tr>
      `).join('');
      if (empty){
        empty.textContent = 'No hay clientes para mostrar.';
        empty.style.display = rows.length ? 'none' : 'block';
      }
      document.querySelectorAll('[data-customer-history]').forEach(btn => btn.addEventListener('click', () => openCustomerHistory(btn.dataset.customerHistory)));
    }catch(err){
      console.error('RIPEX loadCustomers error', err);
      setButtonLoading(document.getElementById('rp-refresh-customers'), false);
      if (loading) loading.style.display = 'none';
      showTableMessage(tbody, empty, 'Error al cargar clientes. Revisa la consola o debug.log.');
    }
  }

  function renderSimpleList(rows, type){
    if (!rows || !rows.length) return '<div class="rp-empty-inline">Sin datos.</div>';
    return `<div class="rp-mini-table">${rows.map(r => {
      if (type === 'product') return `<div class="rp-mini-table-row"><div><strong>${escapeHtml(r.name || '—')}</strong><div class="rp-small">${escapeHtml(r.sku || '')}</div></div><div>${escapeHtml(String(r.qty || 0))} uds.</div><div>${money(r.revenue || 0)}</div></div>`;
      if (type === 'period') return `<div class="rp-mini-table-row"><div><strong>${escapeHtml(r.label || '—')}</strong></div><div>${escapeHtml(String(r.orders || 0))} pedidos</div><div>${money(r.revenue || 0)}</div></div>`;
      return `<div class="rp-mini-table-row"><div><strong>${escapeHtml(r.name || '—')}</strong></div><div>${escapeHtml(String(r.qty || 0))} uds.</div><div>${money(r.revenue || 0)}</div></div>`;
    }).join('')}</div>`;
  }

  function renderComparisonBox(title, data){
    const current = data?.current || {};
    const previous = data?.previous || {};
    const delta = data?.delta || {};
    const sign = Number(delta.revenue || 0) >= 0 ? '+' : '';
    return `
      <div class="rp-history-comparison-card">
        <h3>${escapeHtml(title)}</h3>
        <div class="rp-mini-table">
          <div class="rp-mini-table-row"><div><strong>${escapeHtml(current.label || 'Actual')}</strong></div><div>${escapeHtml(String(current.orders || 0))} pedidos</div><div>${money(current.revenue || 0)}</div></div>
          <div class="rp-mini-table-row"><div><strong>${escapeHtml(previous.label || 'Anterior')}</strong></div><div>${escapeHtml(String(previous.orders || 0))} pedidos</div><div>${money(previous.revenue || 0)}</div></div>
          <div class="rp-mini-table-row"><div><strong>Variación</strong></div><div>${sign}${escapeHtml(String(delta.orders || 0))} pedidos</div><div>${sign}${money(delta.revenue || 0)}</div></div>
        </div>
      </div>`;
  }

  function renderOrderHistoryRows(rows){
    if (!rows || !rows.length) return '<div class="rp-empty-inline">Sin compras en el rango.</div>';
    return `<div class="rp-order-history-list">${rows.map(o => `
      <div class="rp-order-history-card">
        <div class="rp-order-history-head">
          <div><strong>Pedido #${escapeHtml(o.number || o.id)}</strong><div class="rp-small">${escapeHtml(o.date || '')} · ${escapeHtml(o.status_label || '')}</div></div>
          <div class="rp-order-history-total">${money(o.total || 0)}</div>
        </div>
        <div class="rp-mini-table">${(o.items || []).map(i => `<div class="rp-mini-table-row"><div><strong>${escapeHtml(i.name || '—')}</strong><div class="rp-small">${escapeHtml(i.sku || '')}</div></div><div>x${escapeHtml(String(i.qty || 0))}</div><div>${money(i.revenue || 0)}</div></div>`).join('')}</div>
      </div>
    `).join('')}</div>`;
  }

  // ── Drawer de historial de cliente ──────────────────────────────────────────
  // Independiente del drawer de pedidos: usa #rp-customer-history-drawer.

  function openCustomerHistoryDrawer(show) {
    const drawer = document.getElementById('rp-customer-history-drawer');
    if (!drawer) return;
    const wasOpen = drawer.getAttribute('aria-hidden') === 'false';
    drawer.setAttribute('aria-hidden', show ? 'false' : 'true');
    if (show && !wasOpen) openOverlay(drawer);
    else if (!show && wasOpen) closeOverlay();
    if (!show) {
      // Limpiar contenido al cerrar para que no quede acumulado.
      const content = document.getElementById('rp-customer-history-content');
      if (content) content.innerHTML = '';
      const title = document.getElementById('rp-customer-history-title');
      if (title) title.textContent = 'Historial de cliente';
      const sub = document.getElementById('rp-customer-history-sub');
      if (sub) sub.textContent = '';
    }
  }

  function bindCustomerHistoryDrawer() {
    const close = () => openCustomerHistoryDrawer(false);
    document.getElementById('rp-customer-history-close')?.addEventListener('click', close);
    document.getElementById('rp-customer-history-backdrop')?.addEventListener('click', close);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') openCustomerHistoryDrawer(false);
    });
  }

  async function openCustomerHistory(customerId) {
    // Mostrar el drawer con estado de carga antes de lanzar el AJAX.
    const content = document.getElementById('rp-customer-history-content');
    const title   = document.getElementById('rp-customer-history-title');
    const sub     = document.getElementById('rp-customer-history-sub');
    if (content) content.innerHTML = '<div class="rp-loading" style="display:block;">Cargando historial...</div>';
    if (title) title.textContent = 'Historial de cliente';
    if (sub) sub.textContent = '';
    openCustomerHistoryDrawer(true);

    const j = await post('ripex_portal_get_customer_history', { customer_id: customerId });
    if (!j.success) {
      if (content) content.innerHTML = `<div class="rp-empty">${escapeHtml(j.data?.message || 'No se pudo cargar el historial.')}</div>`;
      return;
    }

    const c = j.data.customer || {};
    const s = j.data.summary  || {};

    if (title) title.textContent = c.name || 'Historial de cliente';
    if (sub)   sub.textContent   = [c.email, c.rut].filter(Boolean).join(' · ');

    if (content) content.innerHTML = `
      <div class="rp-customer-history-toolbar">
        <div>
          <strong>${escapeHtml(c.razon_social || c.name || 'Cliente')}</strong>
          <div class="rp-small">${[c.name, c.rut, c.email].filter(Boolean).map(escapeHtml).join(' · ')}</div>
        </div>
      </div>
      <div class="rp-kv rp-customer-history-kv">
        <div><div class="rp-k">Razón social</div><div class="rp-v">${escapeHtml(c.razon_social || '—')}</div></div>
        <div><div class="rp-k">RUT</div><div class="rp-v">${escapeHtml(c.rut || '—')}</div></div>
        <div><div class="rp-k">Giro</div><div class="rp-v">${escapeHtml(c.giro || '—')}</div></div>
        <div><div class="rp-k">Ciudad</div><div class="rp-v">${escapeHtml(c.city || '—')}</div></div>
        <div><div class="rp-k">Vendedor</div><div class="rp-v">${escapeHtml(c.vendedor || '—')}</div></div>
      </div>
      <div class="rp-kpi-grid rp-history-kpis">
        <div class="rp-kpi-card"><div class="rp-kpi-label">Pedidos</div><div class="rp-kpi-value">${escapeHtml(String(s.orders || 0))}</div></div>
        <div class="rp-kpi-card"><div class="rp-kpi-label">Monto total</div><div class="rp-kpi-value">${money(s.revenue || 0)}</div></div>
        <div class="rp-kpi-card"><div class="rp-kpi-label">Ticket promedio</div><div class="rp-kpi-value">${money(s.avg || 0)}</div></div>
      </div>
      <div class="rp-history-comparison-grid">
        ${renderComparisonBox('Comparación mensual', j.data.comparisons?.month || {})}
        ${renderComparisonBox('Comparación anual', j.data.comparisons?.year || {})}
      </div>
      <div class="rp-report-grid rp-customer-history-grid">
        <div class="rp-report-card rp-report-card-wide"><div class="rp-report-head"><h3>Compras separadas</h3><span class="rp-small">Últimos pedidos del cliente</span></div>${renderOrderHistoryRows(j.data.orders || [])}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Productos comprados</h3></div>${renderSimpleList(j.data.products || [], 'product')}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Tipos de productos</h3></div>${renderSimpleList(j.data.categories || [], 'category')}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Montos por mes</h3></div>${renderSimpleList(j.data.months || [], 'period')}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Montos por año</h3></div>${renderSimpleList(j.data.years || [], 'period')}</div>
      </div>`;
  }

  async function loadCarts(){
    const tbody = document.querySelector('#rp-carts-table tbody');
    const loading = document.getElementById('rp-carts-loading');
    const empty = document.getElementById('rp-carts-empty');
    if (!tbody) return;
    if (loading) { loading.style.display = 'block'; enhanceLoading('#rp-carts-loading', 'Cargando carritos...'); }
    setButtonLoading(document.getElementById('rp-refresh-carts'), true, 'Actualizando...');
    renderSkeletonRows('#rp-carts-table tbody', 6, 5);
    if (empty) empty.style.display = 'none';
    try{
      const j = await post('ripex_portal_get_carts', {});
      setButtonLoading(document.getElementById('rp-refresh-carts'), false);
      if (loading) loading.style.display = 'none';
      if (!j.success){
        showTableMessage(tbody, empty, j.data?.message || 'No se pudieron cargar carritos.');
        return;
      }
      const rows = j.data.carts || [];
      lastCarts = rows;
      tbody.innerHTML = rows.map(c => `
        <tr>
          <td><strong>${escapeHtml(c.customer_name || 'Cliente')}</strong><div class="rp-small">${c.customer_rut ? 'RUT: '+escapeHtml(c.customer_rut) : 'ID cliente: '+escapeHtml(String(c.customer_id || '—'))}</div></td>
          <td>${escapeHtml(c.customer_email || '—')}<div class="rp-small">${escapeHtml(c.customer_city || '')}</div></td>
          <td>${escapeHtml(String(c.items_count || 0))}<div class="rp-small">${(c.items || []).slice(0,3).map(i => escapeHtml(i.name)+' x'+escapeHtml(String(i.qty))).join('<br>')}</div>${(c.items || []).length > 3 ? `<div class="rp-small">+${(c.items || []).length - 3} productos más</div>` : ``}</td>
          <td>${money(c.total || 0)}</td>
          <td>${escapeHtml(c.last_activity_label || '—')}</td>
          <td><button class="rp-btn rp-btn-primary rp-btn-sm" data-view-cart="${c.session_id}">Ver / cerrar</button></td>
        </tr>
      `).join('');
      if (empty){
        empty.textContent = 'No hay carritos activos para mostrar.';
        empty.style.display = rows.length ? 'none' : 'block';
      }
      document.querySelectorAll('[data-view-cart]').forEach(btn => btn.addEventListener('click', () => openCartDetail(btn.dataset.viewCart)));
    }catch(err){
      console.error('RIPEX loadCarts error', err);
      setButtonLoading(document.getElementById('rp-refresh-carts'), false);
      if (loading) loading.style.display = 'none';
      showTableMessage(tbody, empty, 'Error al cargar carritos. Revisa la consola o debug.log.');
    }
  }

  async function closeCart(sessionId){
    const shippingKey = document.getElementById('rp-cart-shipping-key')?.value || '';
    const paymentMethod = document.getElementById('rp-cart-payment-method')?.value || 'bacs';

    if (!shippingKey) { alert('Debes seleccionar un transporte.'); return; }
    if (!confirm('¿Crear un pedido en espera desde este carrito y cerrarlo?')) return;

    const btn = document.getElementById('rp-cart-close-confirm');
    setButtonLoading(btn, true, 'Creando pedido...');
    const j = await post('ripex_portal_close_cart', { session_id: sessionId, shipping_key: shippingKey, payment_method: paymentMethod });
    setButtonLoading(btn, false);
    if (!j.success){ showUiToast(j.data?.message || 'No se pudo cerrar carrito.', 'error'); return; }
    showUiToast('Pedido creado: #' + (j.data.order_number || j.data.order_id));
    openCartDrawer(false);
    await loadCarts();
    await loadOrders();
  }

  function init(){
    $$('.rp-tab').forEach(btn => btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;
      switchTab(tab);
      if (tab === 'orders') loadOrders().catch(err => console.error('RIPEX orders tab error', err));
      if (tab === 'inventory') loadProductsInventory().catch(err => console.error('RIPEX inventory tab error', err));
      if (tab === 'reports') loadReports().catch(err => console.error('RIPEX reports tab error', err));
      if (tab === 'customers') loadCustomers().catch(err => console.error('RIPEX customers tab error', err));
      if (tab === 'carts') loadCarts().catch(err => console.error('RIPEX carts tab error', err));
    }));

    $('#rp-refresh-orders')?.addEventListener('click', resetAndLoad);
    $('#rp-order-status')?.addEventListener('change', resetAndLoad);
    $('#rp-order-date-from')?.addEventListener('change', resetAndLoad);
    $('#rp-order-date-to')?.addEventListener('change', resetAndLoad);
    $('#rp-order-search')?.addEventListener('keydown', (e)=>{ if(e.key==='Enter') resetAndLoad(); });

    // Navegación de páginas
    document.getElementById('rp-orders-prev')?.addEventListener('click', () => {
      if (currentPage > 1) loadOrders(currentPage - 1);
    });
    document.getElementById('rp-orders-next')?.addEventListener('click', () => {
      loadOrders(currentPage + 1);
    });

    $('#rp-refresh-products')?.addEventListener('click', loadProductsInventory);
    $('#rp-inventory-category')?.addEventListener('change', loadProductsInventory);
    $('#rp-inventory-orderby')?.addEventListener('change', loadProductsInventory);
    $('#rp-product-search-inv')?.addEventListener('keydown', (e)=>{ if(e.key==='Enter') loadProductsInventory(); });
    let rpInvTimer = null;
    $('#rp-product-search-inv')?.addEventListener('input', () => {
      clearTimeout(rpInvTimer);
      rpInvTimer = setTimeout(loadProductsInventory, 350);
    });

    bindDrawer();
    bindCustomerHistoryDrawer();
    bindVendorSubtabs();
    initFontControls();
    renderColumnMenu();
    renderKpiMenu();
    document.getElementById('rp-export-selected-pdf')?.addEventListener('click', () => exportSelectedOrders('pdf'));
    document.getElementById('rp-export-reports-pdf')?.addEventListener('click', exportReportsPdf);
    document.getElementById('rp-refresh-reports')?.addEventListener('click', () => loadReports(true));
    document.getElementById('rp-refresh-customers')?.addEventListener('click', loadCustomers);
    document.getElementById('rp-customer-list-search')?.addEventListener('keydown', (e)=>{ if(e.key==='Enter') loadCustomers(); });
    document.getElementById('rp-customer-city-filter')?.addEventListener('change', loadCustomers);
    document.getElementById('rp-customer-vendor-filter')?.addEventListener('change', loadCustomers);
    document.getElementById('rp-refresh-carts')?.addEventListener('click', loadCarts);

    const orderFromEl = document.getElementById('rp-order-date-from');
    const orderToEl = document.getElementById('rp-order-date-to');
    const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;

    const fromEl = document.getElementById('rp-report-date-from');
    const toEl = document.getElementById('rp-report-date-to');
    if (fromEl && toEl && !fromEl.value && !toEl.value) {
      const end = new Date();
      const start = new Date();
      start.setDate(end.getDate() - 30);
      fromEl.value = fmt(start);
      toEl.value = fmt(end);
    }

    const bulkFrom = document.getElementById('rp-bulk-date-from');
    const bulkTo = document.getElementById('rp-bulk-date-to');
    if (bulkFrom && bulkTo && !bulkFrom.value && !bulkTo.value) {
      const today = new Date();
      bulkFrom.value = fmt(today);
      bulkTo.value = fmt(today);
    }

    loadOrders().then(async ()=>{
      const created = getUrlParam('order');
      if (created) await openOrder(created);
    });
  }

  function initOnce(){
    if (document.documentElement.dataset.ripexPortalInit === '1') return;
    document.documentElement.dataset.ripexPortalInit = '1';
    init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOnce);
  } else {
    initOnce();
  }
})();