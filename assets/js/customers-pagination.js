(function(){
  'use strict';

  let currentPage = 1;
  let requestSerial = 0;
  let initialized = false;

  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  function escapeHtml(value){
    return (value ?? '').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }

  function money(amount){
    try {
      return new Intl.NumberFormat('es-CL', {
        style:'currency', currency:'CLP', maximumFractionDigits:0
      }).format(Number(amount || 0));
    } catch (error) {
      return amount;
    }
  }

  async function post(action, data){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', RIPEX_PORTAL.nonce);
    Object.keys(data || {}).forEach(key => fd.append(key, data[key]));

    const response = await fetch(RIPEX_PORTAL.ajaxUrl, {
      method:'POST', credentials:'same-origin', body:fd
    });
    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch (error) {
      console.error('RIPEX clientes: respuesta AJAX inválida');
      return { success:false, data:{ message:'Respuesta inválida del servidor.' } };
    }
  }

  function detachLegacyListeners(selector){
    const element = $(selector);
    if (!element || !element.parentNode) return element;
    const clone = element.cloneNode(true);
    element.parentNode.replaceChild(clone, element);
    return clone;
  }

  function switchToCustomers(){
    $$('.rp-tab').forEach(tab => tab.classList.toggle('rp-tab-active', tab.dataset.tab === 'customers'));
    $$('.rp-panel').forEach(panel => panel.classList.toggle('rp-panel-active', panel.dataset.panel === 'customers'));

    const orderDrawer = $('#rp-order-drawer');
    if (orderDrawer) orderDrawer.setAttribute('aria-hidden', 'true');
    const historyDrawer = $('#rp-customer-history-drawer');
    if (historyDrawer) historyDrawer.setAttribute('aria-hidden', 'true');
  }

  function setLoading(show){
    const loading = $('#rp-customers-loading');
    const empty = $('#rp-customers-empty');
    const tbody = $('#rp-customers-table tbody');
    if (empty) empty.style.display = 'none';
    if (loading) {
      loading.style.display = show ? 'block' : 'none';
      if (show) loading.textContent = 'Cargando clientes...';
    }
    if (show && tbody) {
      tbody.innerHTML = Array.from({length:7}).map(() => `
        <tr class="rp-skeleton-row">
          <td><span class="rp-skeleton-line rp-skeleton-1"></span></td>
          <td><span class="rp-skeleton-line rp-skeleton-2"></span></td>
          <td><span class="rp-skeleton-line rp-skeleton-3"></span></td>
          <td><span class="rp-skeleton-line rp-skeleton-1"></span></td>
          <td><span class="rp-skeleton-line rp-skeleton-2"></span></td>
        </tr>
      `).join('');
    }
  }

  function ensurePagination(){
    let nav = $('#rp-customers-pagination');
    if (nav) return nav;

    const wrap = $('#rp-customers-table')?.closest('.rp-table-wrap');
    if (!wrap) return null;

    nav = document.createElement('nav');
    nav.className = 'rp-pagination';
    nav.id = 'rp-customers-pagination';
    nav.setAttribute('aria-label', 'Navegación de clientes');
    nav.style.display = 'none';
    nav.innerHTML = `
      <button class="rp-btn rp-btn-pager" id="rp-customers-prev" aria-label="Página anterior">← Anterior</button>
      <span class="rp-pagination-info" id="rp-customers-page-info"></span>
      <button class="rp-btn rp-btn-pager" id="rp-customers-next" aria-label="Página siguiente">Siguiente →</button>
    `;
    wrap.appendChild(nav);

    $('#rp-customers-prev')?.addEventListener('click', () => {
      if (currentPage > 1) loadCustomers(currentPage - 1);
    });
    $('#rp-customers-next')?.addEventListener('click', () => {
      const totalPages = Number(nav.dataset.totalPages || 0);
      if (currentPage < totalPages) loadCustomers(currentPage + 1);
    });

    return nav;
  }

  function renderPagination(page, totalPages, total){
    const nav = ensurePagination();
    if (!nav) return;

    currentPage = Math.max(1, Number(page) || 1);
    totalPages = Math.max(0, Number(totalPages) || 0);
    total = Math.max(0, Number(total) || 0);
    nav.dataset.totalPages = String(totalPages);

    const info = $('#rp-customers-page-info');
    const prev = $('#rp-customers-prev');
    const next = $('#rp-customers-next');
    if (info) {
      info.textContent = totalPages > 0
        ? `Página ${currentPage} de ${totalPages} · ${total} clientes`
        : `${total} clientes`;
    }
    if (prev) prev.disabled = currentPage <= 1;
    if (next) next.disabled = totalPages === 0 || currentPage >= totalPages;
    nav.style.display = total > 0 ? 'flex' : 'none';
  }

  function populateSelect(selector, values, emptyLabel){
    const select = $(selector);
    if (!select || select.tagName !== 'SELECT') return;

    const current = select.value || '';
    const normalized = value => (value || '').toString().trim().toLowerCase();
    const seen = new Set();
    const unique = [];

    (Array.isArray(values) ? values : []).forEach(value => {
      const label = (value || '').toString().trim();
      if (!label) return;
      const key = normalized(label);
      if (seen.has(key)) return;
      seen.add(key);
      unique.push(label);
    });

    unique.sort((a,b) => a.localeCompare(b, 'es', { sensitivity:'base' }));
    select.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>` + unique.map(value =>
      `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`
    ).join('');

    if (current === '' || unique.some(value => normalized(value) === normalized(current))) {
      select.value = current;
    } else {
      select.value = '';
    }
  }

  function renderRows(customers){
    const tbody = $('#rp-customers-table tbody');
    if (!tbody) return;

    tbody.innerHTML = customers.map(customer => `
      <tr>
        <td class="rp-customer-name-cell">
          <div><strong>${escapeHtml(customer.name || '—')}</strong></div>
          ${customer.email ? `<div class="rp-small">${escapeHtml(customer.email)}</div>` : ''}
        </td>
        <td class="rp-customer-business-cell">
          <div><strong>${escapeHtml(customer.razon_social || '—')}</strong></div>
          ${customer.giro ? `<div class="rp-small">${escapeHtml(customer.giro)}</div>` : ''}
        </td>
        <td>${escapeHtml(customer.city || '—')}<div class="rp-small">${escapeHtml(customer.region || '')}</div></td>
        <td>${escapeHtml(customer.rut || '—')}</td>
        <td><button class="rp-icon-action" data-rp-customer-history="${escapeHtml(customer.id)}" title="Historial" aria-label="Historial"><span class="rp-action-ico">📊</span></button></td>
      </tr>
    `).join('');

    $$('[data-rp-customer-history]').forEach(button => {
      button.addEventListener('click', () => openCustomerHistory(button.dataset.rpCustomerHistory));
    });
  }

  function renderSimpleList(rows, type){
    if (!rows || !rows.length) return '<div class="rp-empty-inline">Sin datos.</div>';
    return `<div class="rp-mini-table">${rows.map(row => {
      if (type === 'product') {
        return `<div class="rp-mini-table-row"><div><strong>${escapeHtml(row.name || '—')}</strong><div class="rp-small">${escapeHtml(row.sku || '')}</div></div><div>${escapeHtml(String(row.qty || 0))} uds.</div><div>${money(row.revenue || 0)}</div></div>`;
      }
      if (type === 'period') {
        return `<div class="rp-mini-table-row"><div><strong>${escapeHtml(row.label || '—')}</strong></div><div>${escapeHtml(String(row.orders || 0))} pedidos</div><div>${money(row.revenue || 0)}</div></div>`;
      }
      return `<div class="rp-mini-table-row"><div><strong>${escapeHtml(row.name || '—')}</strong></div><div>${escapeHtml(String(row.qty || 0))} uds.</div><div>${money(row.revenue || 0)}</div></div>`;
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
    return `<div class="rp-order-history-list">${rows.map(order => `
      <div class="rp-order-history-card">
        <div class="rp-order-history-head">
          <div><strong>Pedido #${escapeHtml(order.number || order.id)}</strong><div class="rp-small">${escapeHtml(order.date || '')} · ${escapeHtml(order.status_label || '')}</div></div>
          <div class="rp-order-history-total">${money(order.total || 0)}</div>
        </div>
        <div class="rp-mini-table">${(order.items || []).map(item => `<div class="rp-mini-table-row"><div><strong>${escapeHtml(item.name || '—')}</strong><div class="rp-small">${escapeHtml(item.sku || '')}</div></div><div>x${escapeHtml(String(item.qty || 0))}</div><div>${money(item.revenue || 0)}</div></div>`).join('')}</div>
      </div>
    `).join('')}</div>`;
  }

  async function openCustomerHistory(customerId){
    const drawer = $('#rp-customer-history-drawer');
    const content = $('#rp-customer-history-content');
    const title = $('#rp-customer-history-title');
    const sub = $('#rp-customer-history-sub');
    if (!drawer || !content) return;

    content.innerHTML = '<div class="rp-loading" style="display:block;">Cargando historial...</div>';
    if (title) title.textContent = 'Historial de cliente';
    if (sub) sub.textContent = '';
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('rp-scroll-locked');

    const result = await post('ripex_portal_get_customer_history', { customer_id:customerId });
    if (!result.success) {
      content.innerHTML = `<div class="rp-empty">${escapeHtml(result.data?.message || 'No se pudo cargar el historial.')}</div>`;
      return;
    }

    const customer = result.data.customer || {};
    const summary = result.data.summary || {};
    if (title) title.textContent = customer.name || 'Historial de cliente';
    if (sub) sub.textContent = [customer.email, customer.rut].filter(Boolean).join(' · ');

    content.innerHTML = `
      <div class="rp-customer-history-toolbar">
        <div>
          <strong>${escapeHtml(customer.razon_social || customer.name || 'Cliente')}</strong>
          <div class="rp-small">${[customer.name, customer.rut, customer.email].filter(Boolean).map(escapeHtml).join(' · ')}</div>
        </div>
      </div>
      <div class="rp-kv rp-customer-history-kv">
        <div><div class="rp-k">Razón social</div><div class="rp-v">${escapeHtml(customer.razon_social || '—')}</div></div>
        <div><div class="rp-k">RUT</div><div class="rp-v">${escapeHtml(customer.rut || '—')}</div></div>
        <div><div class="rp-k">Giro</div><div class="rp-v">${escapeHtml(customer.giro || '—')}</div></div>
        <div><div class="rp-k">Ciudad</div><div class="rp-v">${escapeHtml(customer.city || '—')}</div></div>
        <div><div class="rp-k">Vendedor</div><div class="rp-v">${escapeHtml(customer.vendedor || '—')}</div></div>
      </div>
      <div class="rp-kpi-grid rp-history-kpis">
        <div class="rp-kpi-card"><div class="rp-kpi-label">Pedidos</div><div class="rp-kpi-value">${escapeHtml(String(summary.orders || 0))}</div></div>
        <div class="rp-kpi-card"><div class="rp-kpi-label">Monto total</div><div class="rp-kpi-value">${money(summary.revenue || 0)}</div></div>
        <div class="rp-kpi-card"><div class="rp-kpi-label">Ticket promedio</div><div class="rp-kpi-value">${money(summary.avg || 0)}</div></div>
      </div>
      <div class="rp-history-comparison-grid">
        ${renderComparisonBox('Comparación mensual', result.data.comparisons?.month || {})}
        ${renderComparisonBox('Comparación anual', result.data.comparisons?.year || {})}
      </div>
      <div class="rp-report-grid rp-customer-history-grid">
        <div class="rp-report-card rp-report-card-wide"><div class="rp-report-head"><h3>Compras separadas</h3><span class="rp-small">Últimos pedidos del cliente</span></div>${renderOrderHistoryRows(result.data.orders || [])}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Productos comprados</h3></div>${renderSimpleList(result.data.products || [], 'product')}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Tipos de productos</h3></div>${renderSimpleList(result.data.categories || [], 'category')}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Montos por mes</h3></div>${renderSimpleList(result.data.months || [], 'period')}</div>
        <div class="rp-report-card"><div class="rp-report-head"><h3>Montos por año</h3></div>${renderSimpleList(result.data.years || [], 'period')}</div>
      </div>`;
  }

  async function loadCustomers(page = 1){
    const serial = ++requestSerial;
    const refresh = $('#rp-refresh-customers');
    const search = $('#rp-customer-list-search')?.value || '';
    const city = $('#rp-customer-city-filter')?.value || '';
    const vendor = $('#rp-customer-vendor-filter')?.value || '';

    setLoading(true);
    if (refresh) {
      refresh.disabled = true;
      refresh.dataset.originalText = refresh.dataset.originalText || refresh.textContent;
      refresh.textContent = 'Actualizando...';
    }

    const result = await post('ripex_portal_get_customers', {
      search, city, vendor, page:Math.max(1, Number(page) || 1)
    });

    if (serial !== requestSerial) return;
    setLoading(false);
    if (refresh) {
      refresh.disabled = false;
      refresh.textContent = refresh.dataset.originalText || 'Actualizar';
    }

    const tbody = $('#rp-customers-table tbody');
    const empty = $('#rp-customers-empty');
    if (!result.success) {
      if (tbody) tbody.innerHTML = '';
      if (empty) {
        empty.textContent = result.data?.message || 'No se pudieron cargar clientes.';
        empty.style.display = 'block';
      }
      renderPagination(1, 0, 0);
      return;
    }

    const data = result.data || {};
    populateSelect('#rp-customer-city-filter', data.cities || [], 'Cualquier ciudad');
    populateSelect('#rp-customer-vendor-filter', data.vendors || [], 'Todos los vendedores');

    const customers = Array.isArray(data.customers) ? data.customers : [];
    renderRows(customers);
    renderPagination(data.page || 1, data.total_pages || 0, data.total ?? data.count ?? 0);

    if (empty) {
      empty.textContent = 'No hay clientes para mostrar.';
      empty.style.display = customers.length ? 'none' : 'block';
    }
  }

  function bindCustomers(){
    if (initialized || typeof RIPEX_PORTAL === 'undefined') return;

    const tab = detachLegacyListeners('.rp-tab[data-tab="customers"]');
    const refresh = detachLegacyListeners('#rp-refresh-customers');
    const search = detachLegacyListeners('#rp-customer-list-search');
    const city = detachLegacyListeners('#rp-customer-city-filter');
    const vendor = detachLegacyListeners('#rp-customer-vendor-filter');

    if (!tab || !search) return;
    initialized = true;
    ensurePagination();

    tab.addEventListener('click', () => {
      switchToCustomers();
      loadCustomers(1);
    });
    refresh?.addEventListener('click', () => loadCustomers(currentPage));
    search.addEventListener('keydown', event => {
      if (event.key === 'Enter') loadCustomers(1);
    });
    city?.addEventListener('change', () => loadCustomers(1));
    vendor?.addEventListener('change', () => loadCustomers(1));
  }

  function initAfterPortal(){
    setTimeout(bindCustomers, 0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAfterPortal);
  } else {
    initAfterPortal();
  }
})();
