<?php
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>RIPEX Portal liviano — prototipo</title>
  <link rel="stylesheet" href="../assets/css/portal.css?v=1.5.17">
  <link rel="stylesheet" href="../assets/css/standalone.css?v=1.5.17">
</head>
<body>
  <div class="rp-app rp-standalone-app" data-page="standalone-prototype">
    <header class="rp-topbar">
      <div class="rp-topbar-left">
        <div class="rp-topbar-title">
          <div class="rp-title">RIPEX Portal liviano</div>
          <div class="rp-subtitle">Prototipo read-only · Phase 09</div>
        </div>
      </div>
      <div class="rp-topbar-right">
        <a class="rp-btn" href="/portal-pedidos/">Volver al portal actual</a>
      </div>
    </header>

    <main class="rp-main">
      <section class="rp-panel rp-panel-active">
        <div class="rp-panel-head">
          <div>
            <h2 class="rp-h2">Pedidos</h2>
            <div class="rp-small">Lectura directa del modelo legacy de WooCommerce, sin theme/Elementor/plugins.</div>
          </div>
          <div class="rp-actions">
            <button class="rp-btn rp-btn-primary" id="rp-lite-refresh" type="button">Actualizar</button>
          </div>
        </div>

        <div class="rp-lite-metrics" id="rp-lite-metrics" aria-live="polite">
          Esperando primera medición…
        </div>

        <div class="rp-table-wrap">
          <table class="rp-table" id="rp-lite-orders-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Empresa</th>
                <th>RUT</th>
                <th>Estado</th>
                <th>Método de pago</th>
                <th>Vendedor</th>
                <th>Exportación</th>
                <th>Total</th>
                <th>Envío</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
          <div class="rp-loading" id="rp-lite-loading" style="display:none;">Cargando…</div>
          <div class="rp-empty" id="rp-lite-empty" style="display:none;">No hay pedidos para mostrar.</div>
        </div>

        <nav class="rp-pagination" id="rp-lite-pagination" aria-label="Navegación de páginas">
          <button class="rp-btn rp-btn-pager" id="rp-lite-prev" type="button">← Anterior</button>
          <span class="rp-pagination-info" id="rp-lite-page-info">—</span>
          <button class="rp-btn rp-btn-pager" id="rp-lite-next" type="button">Siguiente →</button>
        </nav>
      </section>
    </main>

    <footer class="rp-footer">
      <span>RIPEX Portal v1.5.17 · prototipo standalone read-only</span>
    </footer>
  </div>

  <script src="../assets/js/standalone-app.js?v=1.5.17"></script>
</body>
</html>
