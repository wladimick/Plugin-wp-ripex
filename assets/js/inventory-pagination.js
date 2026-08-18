(function(){
  'use strict';

  let currentPage = 1;
  let requestSerial = 0;
  let searchTimer = null;
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

  async function post(action, data){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', RIPEX_PORTAL.nonce);
    Object.keys(data || {}).forEach(key => fd.append(key, data[key]));
    const response = await fetch(RIPEX_PORTAL.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });
    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch (error) {
      console.error('RIPEX inventario: respuesta AJAX inválida');
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

  function switchToInventory(){
    $$('.rp-tab').forEach(tab => tab.classList.toggle('rp-tab-active', tab.dataset.tab === 'inventory'));
    $$('.rp-panel').forEach(panel => panel.classList.toggle('rp-panel-active', panel.dataset.panel === 'inventory'));
  }

  function ensurePagination(){
    let nav = $('#rp-products-pagination');
    if (nav) return nav;
    const wrap = $('#rp-products-table')?.closest('.rp-table-wrap');
    if (!wrap) return null;

    nav = document.createElement('nav');
    nav.className = 'rp-pagination';
    nav.id = 'rp-products-pagination';
    nav.setAttribute('aria-label', 'Navegación de inventario');
    nav.style.display = 'none';
    nav.innerHTML = `
      <button class="rp-btn rp-btn-pager" id="rp-products-prev" aria-label="Página anterior">← Anterior</button>
      <span class="rp-pagination-info" id="rp-products-page-info"></span>
      <button class="rp-btn rp-btn-pager" id="rp-products-next" aria-label="Página siguiente">Siguiente →</button>
    `;
    wrap.appendChild(nav);

    $('#rp-products-prev')?.addEventListener('click', () => {
      if (currentPage > 1) loadInventory(currentPage - 1);
    });
    $('#rp-products-next')?.addEventListener('click', () => {
      const totalPages = Number(nav.dataset.totalPages || 0);
      if (currentPage < totalPages) loadInventory(currentPage + 1);
    });
    return nav;
  }

  function setLoading(show){
    const loading = $('#rp-products-loading');
    const empty = $('#rp-products-empty');
    const tbody = $('#rp-products-table tbody');
    if (empty) empty.style.display = 'none';
    if (loading) {
      loading.style.display = show ? 'block' : 'none';
      if (show) loading.textContent = 'Cargando inventario...';
    }
    if (show && tbody) {
      tbody.innerHTML = Array.from({length:6}).map(() => `
        <tr class="rp-skeleton-row">
          <td><span class="rp-skeleton-line rp-skeleton-1"></span></td>
          <td><span class="rp-skeleton-line rp-skeleton-2"></span></td>
          <td><span class="rp-skeleton-line rp-skeleton-3"></span></td>
          <td><span class="rp-skeleton-line rp-skeleton-1"></span></td>
        </tr>
      `).join('');
    }
  }

  function populateCategories(categories){
    const select = $('#rp-inventory-category');
    if (!select || !Array.isArray(categories)) return;
    const current = select.value || '';
    select.innerHTML = '<option value="">Todas las categorías</option>' + categories.map(category => {
      const value = typeof category === 'object' ? (category.slug || '') : category;
      const label = typeof category === 'object' ? (category.name || value) : category;
      return `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`;
    }).join('');
    if (Array.from(select.options).some(option => option.value === current)) select.value = current;
  }

  function renderProducts(products){
    const tbody = $('#rp-products-table tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    const isPortalAdmin = RIPEX_PORTAL.role === 'ripex_admin';

    products.forEach(product => {
      const editUrl = `${RIPEX_PORTAL.ajaxUrl.replace('admin-ajax.php','')}post.php?post=${encodeURIComponent(product.id)}&action=edit`;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${escapeHtml(product.sku || '—')}</strong></td>
        <td>
          <div><strong>${escapeHtml(product.name || '')}</strong></div>
          <div class="rp-small">${escapeHtml(product.categories || 'Sin categoría')}</div>
        </td>
        <td>
          <div class="rp-stock-cell">
            <span>${escapeHtml(product.stock ?? '')}</span>
            ${isPortalAdmin ? `<a class="rp-icon-action" href="${editUrl}" target="_blank" rel="noopener" title="Editar producto" aria-label="Editar producto"><span class="rp-action-ico" aria-hidden="true">✎</span></a>` : ''}
          </div>
        </td>
        <td><span class="rp-badge">${escapeHtml(product.status_label || product.status || '')}</span></td>
      `;
      tbody.appendChild(tr);
    });
  }

  function renderPagination(page, totalPages, total){
    const nav = ensurePagination();
    if (!nav) return;
    currentPage = Math.max(1, Number(page) || 1);
    totalPages = Math.max(0, Number(totalPages) || 0);
    total = Math.max(0, Number(total) || 0);
    nav.dataset.totalPages = String(totalPages);

    const info = $('#rp-products-page-info');
    const prev = $('#rp-products-prev');
    const next = $('#rp-products-next');
    if (info) info.textContent = totalPages > 0 ? `Página ${currentPage} de ${totalPages} · ${total} productos` : `${total} productos`;
    if (prev) prev.disabled = currentPage <= 1;
    if (next) next.disabled = totalPages === 0 || currentPage >= totalPages;
    nav.style.display = total > 0 ? 'flex' : 'none';
  }

  async function loadInventory(page = 1){
    const serial = ++requestSerial;
    const refresh = $('#rp-refresh-products');
    const search = $('#rp-product-search-inv')?.value || '';
    const category = $('#rp-inventory-category')?.value || '';
    const orderby = $('#rp-inventory-orderby')?.value || 'name_asc';

    setLoading(true);
    if (refresh) {
      refresh.disabled = true;
      refresh.dataset.originalText = refresh.dataset.originalText || refresh.textContent;
      refresh.textContent = 'Actualizando...';
    }

    const result = await post('ripex_portal_get_products', {
      search,
      category,
      orderby,
      page: Math.max(1, Number(page) || 1)
    });

    if (serial !== requestSerial) return;
    setLoading(false);
    if (refresh) {
      refresh.disabled = false;
      refresh.textContent = refresh.dataset.originalText || 'Actualizar';
    }

    const tbody = $('#rp-products-table tbody');
    const empty = $('#rp-products-empty');
    if (!result.success) {
      if (tbody) tbody.innerHTML = '';
      if (empty) {
        empty.textContent = result.data?.message || 'No se pudo cargar el inventario.';
        empty.style.display = 'block';
      }
      renderPagination(1, 0, 0);
      return;
    }

    const data = result.data || {};
    populateCategories(data.categories || []);
    const products = Array.isArray(data.products) ? data.products : [];
    renderProducts(products);
    renderPagination(data.page || 1, data.total_pages || 0, data.total || 0);

    if (empty) {
      empty.textContent = 'No hay productos para mostrar.';
      empty.style.display = products.length ? 'none' : 'block';
    }
  }

  function bindInventory(){
    if (initialized || typeof RIPEX_PORTAL === 'undefined') return;

    const inventoryTab = detachLegacyListeners('.rp-tab[data-tab="inventory"]');
    const refresh = detachLegacyListeners('#rp-refresh-products');
    const category = detachLegacyListeners('#rp-inventory-category');
    const orderby = detachLegacyListeners('#rp-inventory-orderby');
    const search = detachLegacyListeners('#rp-product-search-inv');

    if (!inventoryTab || !search) return;
    initialized = true;
    ensurePagination();

    inventoryTab.addEventListener('click', () => {
      switchToInventory();
      loadInventory(1);
    });
    refresh?.addEventListener('click', () => loadInventory(currentPage));
    category?.addEventListener('change', () => loadInventory(1));
    orderby?.addEventListener('change', () => loadInventory(1));

    search.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        clearTimeout(searchTimer);
        loadInventory(1);
      }
    });
    search.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => loadInventory(1), 350);
    });
  }

  function initAfterPortal(){
    setTimeout(bindInventory, 0);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAfterPortal);
  else initAfterPortal();
})();
