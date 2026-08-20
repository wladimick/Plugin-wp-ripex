(function(){
  'use strict';

  var state = {
    token: '',
    page: 1,
    totalPages: 1,
    loading: false
  };

  function getTokenFromHash(){
    var raw = window.location.hash || '';
    var match = raw.match(/(?:^#|&)token=([^&]+)/);
    if (!match) return '';
    try {
      return decodeURIComponent(match[1]);
    } catch (e) {
      return '';
    }
  }

  function clearTokenFromUrl(){
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, document.title, window.location.pathname + window.location.search);
    }
  }

  function escapeHtml(value){
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatMoney(value, currency){
    if (value == null || value === '') return '—';
    var numeric = Number(value);
    if (!Number.isFinite(numeric)) return escapeHtml(value);
    try {
      return new Intl.NumberFormat('es-CL', {
        style: 'currency',
        currency: currency || 'CLP',
        maximumFractionDigits: 0
      }).format(numeric);
    } catch (e) {
      return '$' + Math.round(numeric).toLocaleString('es-CL');
    }
  }

  function setLoading(loading){
    state.loading = loading;
    var loadingEl = document.getElementById('rp-lite-loading');
    var refresh = document.getElementById('rp-lite-refresh');
    if (loadingEl) loadingEl.style.display = loading ? '' : 'none';
    if (refresh) refresh.disabled = loading;
  }

  function renderMetrics(perf, data){
    var el = document.getElementById('rp-lite-metrics');
    if (!el) return;

    if (!perf) {
      el.textContent = 'Sin métricas del endpoint.';
      return;
    }

    el.textContent = [
      'API total: ' + Number(perf.request_ms || 0).toFixed(1) + ' ms',
      'SHORTINIT: ' + Number(perf.bootstrap_ms || 0).toFixed(1) + ' ms',
      'queries: ' + (perf.db_queries == null ? '—' : perf.db_queries),
      'PHP peak: ' + Number(perf.peak_memory_mib || 0).toFixed(1) + ' MiB',
      'pedidos: ' + Number((data && data.total) || 0)
    ].join(' · ');
  }

  function renderRows(data){
    var tbody = document.querySelector('#rp-lite-orders-table tbody');
    var empty = document.getElementById('rp-lite-empty');
    if (!tbody) return;

    var orders = data && Array.isArray(data.orders) ? data.orders : [];
    tbody.innerHTML = orders.map(function(order){
      return '<tr>' +
        '<td>#' + escapeHtml(order.number || order.id) + '</td>' +
        '<td>' + escapeHtml(order.date) + '</td>' +
        '<td>' + escapeHtml(order.customer) + '</td>' +
        '<td>' + escapeHtml(order.company) + '</td>' +
        '<td>' + escapeHtml(order.rut) + '</td>' +
        '<td>' + escapeHtml(order.status_label || order.status) + '</td>' +
        '<td>' + escapeHtml(order.payment) + '</td>' +
        '<td>' + escapeHtml(order.vendedor) + '</td>' +
        '<td>' + (order.exported ? 'Exportado' : 'Pendiente') + '</td>' +
        '<td>' + formatMoney(order.total, order.currency) + '</td>' +
        '<td>' + escapeHtml(order.shipping) + '</td>' +
      '</tr>';
    }).join('');

    if (empty) empty.style.display = orders.length ? 'none' : '';

    state.page = Number(data && data.page) || 1;
    state.totalPages = Number(data && data.total_pages) || 0;

    var info = document.getElementById('rp-lite-page-info');
    if (info) info.textContent = state.totalPages > 0
      ? 'Página ' + state.page + ' de ' + state.totalPages
      : 'Sin resultados';

    var prev = document.getElementById('rp-lite-prev');
    var next = document.getElementById('rp-lite-next');
    if (prev) prev.disabled = state.loading || state.page <= 1;
    if (next) next.disabled = state.loading || state.totalPages === 0 || state.page >= state.totalPages;
  }

  function apiRequest(page){
    var fd = new FormData();
    fd.append('page', String(page));

    return fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Ripex-Token': state.token
      },
      body: fd,
      cache: 'no-store'
    }).then(function(response){
      return response.text().then(function(text){
        var json;
        try {
          json = JSON.parse(text);
        } catch (e) {
          throw new Error('Respuesta JSON inválida (' + response.status + ').');
        }
        if (!response.ok || !json.success) {
          var code = json && json.error ? json.error : 'request_failed';
          throw new Error(code);
        }
        return json;
      });
    });
  }

  function load(page){
    if (state.loading) return;
    setLoading(true);

    apiRequest(page).then(function(result){
      renderRows(result.data || {});
      renderMetrics(result.performance || null, result.data || {});
    }).catch(function(error){
      var metrics = document.getElementById('rp-lite-metrics');
      if (metrics) metrics.textContent = 'Error: ' + error.message + '. Vuelve al portal actual y abre nuevamente el prototipo.';
      if (error.message === 'invalid_or_expired_token') {
        state.token = '';
      }
    }).finally(function(){
      setLoading(false);
      var prev = document.getElementById('rp-lite-prev');
      var next = document.getElementById('rp-lite-next');
      if (prev) prev.disabled = state.page <= 1;
      if (next) next.disabled = state.totalPages === 0 || state.page >= state.totalPages;
    });
  }

  function install(){
    state.token = getTokenFromHash();
    clearTokenFromUrl();

    if (!state.token) {
      var metrics = document.getElementById('rp-lite-metrics');
      if (metrics) metrics.textContent = 'Acceso no iniciado. Vuelve al portal actual y usa “Probar portal liviano”.';
      return;
    }

    var refresh = document.getElementById('rp-lite-refresh');
    var prev = document.getElementById('rp-lite-prev');
    var next = document.getElementById('rp-lite-next');

    if (refresh) refresh.addEventListener('click', function(){ load(state.page); });
    if (prev) prev.addEventListener('click', function(){ if (state.page > 1) load(state.page - 1); });
    if (next) next.addEventListener('click', function(){ if (state.page < state.totalPages) load(state.page + 1); });

    load(1);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
  } else {
    install();
  }
})();
