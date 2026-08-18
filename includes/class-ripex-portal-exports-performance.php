<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 06 — export performance bridge.
 *
 * Keeps the existing AJAX/CSV contracts while removing the two remaining
 * unbounded order-object collections from vendor and date-range exports.
 */
final class Ripex_Portal_Exports_Performance {
  private static $instance = null;
  private $portal;
  const BATCH_SIZE = 100;

  public static function instance($portal = null) {
    if (self::$instance === null) {
      self::$instance = new self($portal ?: Ripex_Portal::instance());
    }
    return self::$instance;
  }

  private function __construct($portal) {
    $this->portal = $portal;
  }

  private function portal_call($method, ...$args) {
    return (function($method, $args) {
      return $this->{$method}(...$args);
    })->call($this->portal, $method, $args);
  }

  private function json_ok($data) {
    wp_send_json_success($data);
  }

  private function json_err($message) {
    wp_send_json_error(['message' => $message]);
  }

  private function csv_stream(array $header) {
    $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
    if (!$stream) return false;
    $this->write_csv_row($stream, $header);
    return $stream;
  }

  private function write_csv_row($stream, array $row) {
    $escaped = array_map(function($value) {
      $value = str_replace('"', '""', (string) $value);
      return '"' . $value . '"';
    }, $row);

    fwrite($stream, implode(',', $escaped) . "\r\n");
  }

  private function stream_base64($stream) {
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    return base64_encode((string) $csv);
  }

  /**
   * Preserve ajax_export_my_orders() semantics:
   * an order is "created by" the seller when either _ripex_seller_id matches
   * or the legacy shop_order post_author matches. Candidate IDs are resolved
   * without first loading every WooCommerce order object.
   *
   * This intentionally remains a legacy-post lookup until the dedicated HPOS
   * compatibility phase replaces all direct order post/postmeta access.
   */
  private function vendor_created_order_ids_page($vendor_user_id, $limit, $offset) {
    global $wpdb;

    $vendor_user_id = (int) $vendor_user_id;
    $limit = max(1, (int) $limit);
    $offset = max(0, (int) $offset);

    $sql = $wpdb->prepare(
      "SELECT candidates.ID
       FROM (
         SELECT p.ID, p.post_date
         FROM {$wpdb->posts} p
         WHERE p.post_type = 'shop_order'
           AND p.post_author = %d

         UNION

         SELECT p.ID, p.post_date
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type = 'shop_order'
           AND pm.meta_key = '_ripex_seller_id'
           AND CAST(pm.meta_value AS UNSIGNED) = %d
       ) AS candidates
       ORDER BY candidates.post_date DESC, candidates.ID DESC
       LIMIT %d OFFSET %d",
      $vendor_user_id,
      $vendor_user_id,
      $limit,
      $offset
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return array_map('intval', (array) $wpdb->get_col($sql));
  }

  public function ajax_export_my_orders() {
    $this->portal_call('check_ajax_access');
    $this->portal_call('require_wc_or_die');

    $role = (string) $this->portal_call('current_user_role_key');
    $user_id = get_current_user_id();
    if ($role !== 'ripex_vendedor') {
      $this->json_err('No autorizado para exportar.');
      return;
    }

    $stream = $this->csv_stream([
      'Pedido','Fecha','Cliente','Rut','Razón Social','Giro','Vendedor','Creador',
      'Estado','Método de pago','Transporte','Dirección','Total'
    ]);
    if (!$stream) {
      $this->json_err('No se pudo preparar la exportación.');
      return;
    }

    $offset = 0;
    do {
      $ids = $this->vendor_created_order_ids_page($user_id, self::BATCH_SIZE, $offset);
      if (empty($ids)) break;

      foreach ($ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order')) continue;

        // Exact legacy semantic check remains as defense in depth.
        if (!$this->portal_call('vendor_created_filter', $order, $user_id)) continue;

        $cid = (int) $this->portal_call('get_customer_user_id_from_order', $order);
        $af = (array) $this->portal_call('get_customer_afreg', $order, $cid);
        $rut = $af['rut'] ?? '';
        $razon = !empty($af['razon_social']) ? $af['razon_social'] : $order->get_billing_company();
        $giro = $af['giro'] ?? '';
        $vendor = $af['vendedor'] ?? '';
        $creator = (string) $this->portal_call('get_order_creator_label', $order);

        $shipping_title = '';
        $shipping_items = $order->get_items('shipping');
        if (!empty($shipping_items)) {
          $first = current($shipping_items);
          if ($first && method_exists($first, 'get_method_title')) {
            $shipping_title = (string) $this->portal_call('clean_transport_label', (string) $first->get_method_title());
          }
        }

        $address_lines = (array) $this->portal_call('clean_address_lines', $order->get_formatted_shipping_address());
        if (empty($address_lines)) {
          $address_lines = (array) $this->portal_call('clean_address_lines', $order->get_formatted_billing_address());
        }
        $address_csv = (string) $this->portal_call('join_address_for_csv', $address_lines);

        $this->write_csv_row($stream, [
          $order->get_order_number(),
          $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/y H:i') : '',
          trim($order->get_formatted_billing_full_name()) ?: $order->get_billing_company(),
          $rut,
          $razon,
          $giro,
          $vendor,
          $creator,
          wc_get_order_status_name($order->get_status()),
          $order->get_payment_method_title(),
          $shipping_title,
          $address_csv,
          $order->get_total(),
        ]);
      }

      $offset += count($ids);
      if (count($ids) < self::BATCH_SIZE) break;
    } while (true);

    $this->json_ok([
      'filename' => 'mis-pedidos-' . $user_id . '.csv',
      'csv' => $this->stream_base64($stream),
    ]);
  }

  public function ajax_export_orders_by_date() {
    $this->portal_call('check_ajax_access');
    $this->portal_call('require_wc_or_die');

    $role = (string) $this->portal_call('current_user_role_key');
    if (!in_array($role, ['ripex_bodeguero', 'ripex_admin'], true)) {
      $this->json_err('No autorizado para exportar.');
      return;
    }

    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    $only_pending_export = !empty($_POST['only_pending_export']);

    if (!$date_from || !$date_to) {
      $this->json_err('Debes indicar fecha desde y hasta.');
      return;
    }

    $start_ts = strtotime($date_from . ' 00:00:00');
    $end_ts = strtotime($date_to . ' 23:59:59');
    if (!$start_ts || !$end_ts || $start_ts > $end_ts) {
      $this->json_err('Rango de fechas inválido.');
      return;
    }

    $stream = $this->csv_stream([
      'Pedido','Fecha','Rut','Vendedor','Razón Social','Giro','Dirección',
      'Transporte','SKU','Producto','Cantidad','Stock'
    ]);
    if (!$stream) {
      $this->json_err('No se pudo preparar la exportación.');
      return;
    }

    $selected_order_ids = [];
    $page = 1;
    $max_pages = 1;

    do {
      $query = new WC_Order_Query([
        'limit' => self::BATCH_SIZE,
        'page' => $page,
        'paginate' => true,
        'orderby' => 'date',
        'order' => 'ASC',
        'return' => 'objects',
        'date_created' => $date_from . ' 00:00:00...' . $date_to . ' 23:59:59',
      ]);
      $result = $query->get_orders();

      if (is_object($result) && isset($result->orders)) {
        $orders = (array) $result->orders;
        $max_pages = max(1, (int) ($result->max_num_pages ?? 1));
      } else {
        $orders = is_array($result) ? $result : [];
        $max_pages = $page;
      }

      foreach ($orders as $order) {
        if (!$order || !is_a($order, 'WC_Order')) continue;

        // Defense against timezone/data-store differences: preserve the exact
        // legacy inclusive timestamp rule as a second check.
        $dt = $order->get_date_created();
        if (!$dt) continue;
        $ts = $dt->getTimestamp();
        if ($ts < $start_ts || $ts > $end_ts) continue;

        $already_exported = (bool) $order->get_meta('_ripex_exported_to_bodega');
        if ($only_pending_export && $already_exported) continue;

        $rows = (array) $this->portal_call('export_order_rows_for_batch', $order);
        if (empty($rows)) continue;

        foreach ($rows as $row) {
          $this->write_csv_row($stream, (array) $row);
        }
        $selected_order_ids[] = (int) $order->get_id();
      }

      $page++;
    } while ($page <= $max_pages);

    if (empty($selected_order_ids)) {
      fclose($stream);
      $this->json_err('No se encontraron pedidos para exportar en ese rango.');
      return;
    }

    // Preserve the legacy exported flag, but re-hydrate one order at a time
    // instead of retaining the complete selected object set in memory.
    foreach ($selected_order_ids as $order_id) {
      $order = wc_get_order($order_id);
      if (!$order) continue;
      $order->update_meta_data('_ripex_exported_to_bodega', 1);
      $order->save();
    }

    $this->json_ok([
      'filename' => 'pedidos-' . $date_from . '-a-' . $date_to . '.csv',
      'csv' => $this->stream_base64($stream),
      'count_orders' => count($selected_order_ids),
    ]);
  }
}

add_action('plugins_loaded', function() {
  $portal = Ripex_Portal::instance();
  $bridge = Ripex_Portal_Exports_Performance::instance($portal);

  remove_action('wp_ajax_ripex_portal_export_my_orders', [$portal, 'ajax_export_my_orders']);
  remove_action('wp_ajax_ripex_portal_export_orders_by_date', [$portal, 'ajax_export_orders_by_date']);

  add_action('wp_ajax_ripex_portal_export_my_orders', [$bridge, 'ajax_export_my_orders']);
  add_action('wp_ajax_ripex_portal_export_orders_by_date', [$bridge, 'ajax_export_orders_by_date']);
}, 50);
