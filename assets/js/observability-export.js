(function(){
  'use strict';

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
    } catch (e) {
      return { success: false, data: { message: 'Respuesta inválida del servidor.' } };
    }
  }

  function setBusy(button, busy, label){
    if (!button) return;
    if (busy) {
      if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
      button.disabled = true;
      button.textContent = label || 'Procesando...';
    } else {
      button.disabled = false;
      if (button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
        delete button.dataset.originalText;
      }
    }
  }

  function downloadBase64Json(base64, filename){
    const binary = atob(base64 || '');
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);

    const blob = new Blob([bytes], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || 'ripex-performance.json';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function formatStatus(data){
    const count = Number(data?.count || 0);
    const dropped = Number(data?.dropped_samples || 0);
    return dropped > 0
      ? `Métricas: ${count} · descartadas: ${dropped}`
      : `Métricas: ${count}`;
  }

  async function refreshStatus(statusEl){
    const result = await post('ripex_portal_perf_status', {});
    if (!result.success) {
      statusEl.textContent = 'Métricas: no disponibles';
      return;
    }
    statusEl.textContent = formatStatus(result.data);
    statusEl.title = result.data?.started_at_utc
      ? `Captura iniciada: ${result.data.started_at_utc}`
      : '';
  }

  async function runDiagnosticPings(button, statusEl){
    setBusy(button, true, 'Ping 0/5...');
    for (let i = 1; i <= 5; i++) {
      button.textContent = `Ping ${i}/5...`;
      const result = await post('ripex_portal_perf_ping', {});
      if (!result.success) {
        setBusy(button, false);
        window.alert(result.data?.message || 'No se pudo completar el ping diagnóstico.');
        await refreshStatus(statusEl);
        return;
      }
    }
    setBusy(button, false);
    await refreshStatus(statusEl);
  }

  function install(){
    if (document.getElementById('rp-perf-export-json')) return;

    const anchor = document.getElementById('rp-export-reports-pdf');
    if (!anchor || !anchor.parentElement) return;

    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.id = 'rp-perf-clear';
    clearBtn.className = 'rp-btn';
    clearBtn.textContent = 'Nueva medición';

    const pingBtn = document.createElement('button');
    pingBtn.type = 'button';
    pingBtn.id = 'rp-perf-ping';
    pingBtn.className = 'rp-btn';
    pingBtn.textContent = 'Ping diagnóstico ×5';
    pingBtn.title = 'Mide el piso común de WordPress/RIPEX con cinco solicitudes mínimas autenticadas.';

    const exportBtn = document.createElement('button');
    exportBtn.type = 'button';
    exportBtn.id = 'rp-perf-export-json';
    exportBtn.className = 'rp-btn';
    exportBtn.textContent = 'Descargar métricas JSON';

    const status = document.createElement('span');
    status.id = 'rp-perf-status';
    status.textContent = 'Métricas: …';
    status.style.fontSize = '12px';
    status.style.opacity = '0.75';
    status.style.alignSelf = 'center';
    status.style.whiteSpace = 'nowrap';

    const parent = anchor.parentElement;
    parent.insertBefore(clearBtn, anchor);
    parent.insertBefore(pingBtn, anchor);
    parent.insertBefore(exportBtn, anchor);
    parent.insertBefore(status, anchor);

    clearBtn.addEventListener('click', async () => {
      const confirmed = window.confirm('¿Iniciar una nueva medición? Se borrarán las métricas acumuladas de la sesión actual.');
      if (!confirmed) return;

      setBusy(clearBtn, true, 'Limpiando...');
      const result = await post('ripex_portal_perf_clear', {});
      setBusy(clearBtn, false);

      if (!result.success) {
        window.alert(result.data?.message || 'No se pudo iniciar una nueva medición.');
        return;
      }
      await refreshStatus(status);
    });

    pingBtn.addEventListener('click', async () => {
      await runDiagnosticPings(pingBtn, status);
    });

    exportBtn.addEventListener('click', async () => {
      setBusy(exportBtn, true, 'Generando JSON...');
      const result = await post('ripex_portal_perf_export', {});
      setBusy(exportBtn, false);

      if (!result.success) {
        window.alert(result.data?.message || 'No se pudo generar el archivo JSON.');
        return;
      }

      downloadBase64Json(result.data?.json_base64, result.data?.filename);
      await refreshStatus(status);
    });

    refreshStatus(status);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
  } else {
    install();
  }
})();
