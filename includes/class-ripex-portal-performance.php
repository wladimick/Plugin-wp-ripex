<?php
if (!defined('ABSPATH')) exit;

/**
 * Performance bridge for RIPEX Portal.
 *
 * Replaces only the Reports AJAX callback while the main portal class is
 * progressively refactored. Existing private business helpers are invoked in
 * the scope of the Ripex_Portal singleton so seller/customer rules stay aligned
 * with the production 1.5.14 behavior.
 */
final class Ripex_Portal_Performance {
  private static $instance = null;
  private $portal;

  public static function instance($portal = null) {
    if (self::$instance === null) self::$instance = new self($portal ?: Ripex_Portal::instance());
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

  private function json_ok($data) { wp_send_json_success($data); }
  private function json_err($message, $extra = []) { wp_send_json_error(array_merge(['message' => $message], $extra)); }

  /** Iterate orders in bounded batches to cap peak PHP memory. */
  private function each_order(array $query_args, callable $consumer, $page_size = 100) {
    $offset = 0;
    $page_size = max(20, (int) $page_size);

    do {
      $args = $query_args;
      $args['limit'] = $page_size;
      $args['offset'] = $offset;
      $args['paginate'] = false;
      $args['return'] = 'objects';

      $orders = (new WC_Order_Query($args))->get_orders();
      if (!is_array($orders)) $orders = [];

      foreach ($orders as $order) {
        if ($order && is_a($order, 'WC_Order')) $consumer($order);
      }

      $count = count($orders);
      unset($orders);
      $offset += $page_size;
    } while ($count === $page_size);
  }

  /** Iterate published products in bounded batches to cap peak PHP memory. */
  private function each_product(callable $consumer, $page_size = 100) {
    $offset = 0;
    $page_size = max(20, (int) $page_size);

    do {
      $products = (new WC_Product_Query([
        'status' => 'publish',
        'limit' => $page_size,
        'offset' => $offset,
        'orderby' => 'id',
        'order' => 'ASC',
        'return' => 'objects',
      ]))->get_products();
      if (!is_array($products)) $products = [];

      foreach ($products as $product) {
        if ($product && is_a($product, 'WC_Product')) $consumer($product);
      }

      $count = count($products);
      unset($products);
      $offset += $page_size;
    } while ($count === $page_size);
  }

  public function ajax_get_reports() {
    $this->portal_call('check_ajax_access');
    $this->portal_call('require_wc_or_die');

    $role = (string) $this->portal_call('current_user_role_key');
    $current_user_id = get_current_user_id();
    if (!in_array($role, ['ripex_admin', 'ripex_vendedor'], true)) {
      $this->json_err('No autorizado para ver reportes.');
    }

    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    $force_refresh = !empty($_POST['force_refresh']);

    if (!$date_from) $date_from = gmdate('Y-m-d', strtotime('-30 days'));
    if (!$date_to) $date_to = gmdate('Y-m-d');

    $start_ts = strtotime($date_from . ' 00:00:00');
    $end_ts = strtotime($date_to . ' 23:59:59');
    if (!$start_ts || !$end_ts || $start_ts > $end_ts) {
      $this->json_err('Rango de fechas inválido.');
    }

    // Keep the exact cache contract from v1.5.14.
    $use_cache = ($role === 'ripex_admin') && !$force_refresh;
    $transient_key = 'ripex_rep_' . md5($role . '_' . $current_user_id . '_' . $date_from . '_' . $date_to);

    if ($use_cache) {
      $cached = get_transient($transient_key);
      if ($cached !== false) {
        $this->json_ok($cached);
        return;
      }
    }

    $now_ts = time();
    $inactive_cutoff = strtotime('-60 days', $now_ts);
    $revenue_statuses = ['processing', 'completed', 'on-hold'];

    $sales_by_day = [];
    $top_products = [];
    $seller_sales = [];
    $transport_sales = [];
    $status_counts = [];
    $company_sales = [];
    $customer_keys_in_range = [];
    $sold_product_ids = [];

    $total_revenue = 0.0;
    $orders_count = 0;
    $revenue_orders = 0;
    $items_sold = 0;

    // P0: only read orders inside the selected report range. The previous code
    // loaded the complete order history and filtered the range afterwards in PHP.
    $this->each_order([
      'date_created' => $date_from . ' 00:00:00...' . $date_to . ' 23:59:59',
      'orderby' => 'date',
      'order' => 'ASC',
    ], function($order) use (
      $role,
      $current_user_id,
      $revenue_statuses,
      &$sales_by_day,
      &$top_products,
      &$seller_sales,
      &$transport_sales,
      &$status_counts,
      &$company_sales,
      &$customer_keys_in_range,
      &$sold_product_ids,
      &$total_revenue,
      &$orders_count,
      &$revenue_orders,
      &$items_sold
    ) {
      if ($role === 'ripex_vendedor' && !$this->portal_call('vendor_mine_filter', $order, $current_user_id)) return;

      $dt = $order->get_date_created();
      if (!$dt) return;
      $status = $order->get_status();
      $orders_count++;

      if (!isset($status_counts[$status])) {
        $status_counts[$status] = ['name' => wc_get_order_status_name($status), 'count' => 0];
      }
      $status_counts[$status]['count']++;

      $email = trim((string) $order->get_billing_email());
      $billing_name = trim($order->get_formatted_billing_full_name());
      $customer_label = $billing_name !== '' ? $billing_name : ($email !== '' ? $email : ('Cliente #' . $order->get_id()));
      $customer_key = $email !== '' ? strtolower($email) : strtolower($customer_label);
      $customer_keys_in_range[$customer_key] = true;

      if (!in_array($status, $revenue_statuses, true)) return;

      $revenue_orders++;
      $total = (float) $order->get_total();
      $total_revenue += $total;

      $day_key = $dt->date_i18n('Y-m-d');
      if (!isset($sales_by_day[$day_key])) $sales_by_day[$day_key] = 0.0;
      $sales_by_day[$day_key] += $total;

      $cid = $this->portal_call('get_customer_user_id_from_order', $order);
      $af = $this->portal_call('get_customer_afreg', $order, $cid);

      $seller_label = trim((string) ($af['vendedor'] ?? ''));
      if ($seller_label === '') $seller_label = (string) $this->portal_call('get_order_creator_label', $order);
      if ($seller_label === '') $seller_label = 'Sin asignar';
      if (!isset($seller_sales[$seller_label])) {
        $seller_sales[$seller_label] = ['name' => $seller_label, 'orders' => 0, 'revenue' => 0.0];
      }
      $seller_sales[$seller_label]['orders']++;
      $seller_sales[$seller_label]['revenue'] += $total;

      $shipping_title = 'Sin transporte';
      $shipping_items = $order->get_items('shipping');
      if (!empty($shipping_items)) {
        $first = current($shipping_items);
        if ($first && method_exists($first, 'get_method_title')) {
          $title = trim((string) $first->get_method_title());
          if ($title !== '') $shipping_title = $title;
        }
      }
      if (!isset($transport_sales[$shipping_title])) {
        $transport_sales[$shipping_title] = ['name' => $shipping_title, 'orders' => 0, 'revenue' => 0.0];
      }
      $transport_sales[$shipping_title]['orders']++;
      $transport_sales[$shipping_title]['revenue'] += $total;

      $company = trim((string) ($af['razon_social'] ?? ''));
      if ($company === '') $company = trim((string) $order->get_billing_company());
      if ($company === '') $company = $customer_label;
      if (!isset($company_sales[$company])) {
        $company_sales[$company] = ['name' => $company, 'orders' => 0, 'revenue' => 0.0];
      }
      $company_sales[$company]['orders']++;
      $company_sales[$company]['revenue'] += $total;

      foreach ($order->get_items() as $item) {
        if (!is_a($item, 'WC_Order_Item_Product')) continue;

        $product = $item->get_product();
        $pid = $product ? (int) $product->get_id() : 0;
        if ($pid) $sold_product_ids[$pid] = true;

        $name = $item->get_name();
        $qty = (int) $item->get_quantity();
        $line_total = (float) $item->get_total();
        $items_sold += $qty;

        $stock = '';
        $sku = '';
        if ($product) {
          $stock_qty = $product->get_stock_quantity();
          $stock = is_null($stock_qty) ? '' : (int) $stock_qty;
          $sku = (string) $product->get_sku();
        }

        $key = $pid ?: md5($name . '|' . $sku);
        if (!isset($top_products[$key])) {
          $top_products[$key] = [
            'id' => $pid,
            'name' => $name,
            'sku' => $sku,
            'qty' => 0,
            'revenue' => 0.0,
            'stock' => $stock,
          ];
        }
        $top_products[$key]['qty'] += $qty;
        $top_products[$key]['revenue'] += $line_total;
        if ($stock !== '') $top_products[$key]['stock'] = $stock;
      }
    });

    // Historical customer data is still required by the inactive-customer table.
    // Preserve the same semantics but restrict to revenue statuses and process the
    // history in bounded batches so all orders are never resident simultaneously.
    $customer_history = [];
    $this->each_order([
      'status' => $revenue_statuses,
      'orderby' => 'date',
      'order' => 'ASC',
    ], function($order) use ($role, $current_user_id, &$customer_history) {
      if ($role === 'ripex_vendedor' && !$this->portal_call('vendor_mine_filter', $order, $current_user_id)) return;

      $dt = $order->get_date_created();
      if (!$dt) return;
      $ts = $dt->getTimestamp();
      $cid = (int) $order->get_customer_id();
      $email = trim((string) $order->get_billing_email());
      $billing_name = trim($order->get_formatted_billing_full_name());
      $customer_label = $billing_name !== '' ? $billing_name : ($email !== '' ? $email : ('Cliente #' . $order->get_id()));
      $hist_key = $cid ? ('id:' . $cid) : ($email !== '' ? ('email:' . strtolower($email)) : ('name:' . strtolower($customer_label)));

      if (!isset($customer_history[$hist_key])) {
        $customer_history[$hist_key] = [
          'name' => $customer_label,
          'email' => $email,
          'last_ts' => $ts,
          'orders' => 0,
          'revenue' => 0.0,
        ];
      }

      $customer_history[$hist_key]['orders']++;
      $customer_history[$hist_key]['revenue'] += (float) $order->get_total();
      if ($ts > $customer_history[$hist_key]['last_ts']) $customer_history[$hist_key]['last_ts'] = $ts;
    });

    $sales_series = [];
    $cursor = $start_ts;
    while ($cursor <= $end_ts) {
      $key = gmdate('Y-m-d', $cursor);
      $sales_series[] = [
        'date' => $key,
        'label' => gmdate('d/m', $cursor),
        'value' => isset($sales_by_day[$key]) ? (float) $sales_by_day[$key] : 0.0,
      ];
      $cursor = strtotime('+1 day', $cursor);
    }

    $top_products = array_values($top_products);
    usort($top_products, function($a, $b) {
      if ($a['qty'] === $b['qty']) return $b['revenue'] <=> $a['revenue'];
      return $b['qty'] <=> $a['qty'];
    });
    $top_products = array_slice($top_products, 0, 10);

    $seller_sales = array_values($seller_sales);
    usort($seller_sales, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });
    $seller_sales = array_slice($seller_sales, 0, 10);

    $transport_sales = array_values($transport_sales);
    usort($transport_sales, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });
    $transport_sales = array_slice($transport_sales, 0, 10);

    $status_counts = array_values($status_counts);
    usort($status_counts, function($a, $b) { return $b['count'] <=> $a['count']; });

    $company_sales = array_values($company_sales);
    usort($company_sales, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });
    $company_sales = array_slice($company_sales, 0, 10);

    $low_stock = [];
    $no_movement = [];
    $stock_total_visible = 0;

    $this->each_product(function($product) use (&$low_stock, &$no_movement, &$stock_total_visible, $sold_product_ids) {
      $stock_qty = $product->get_stock_quantity();
      if (!is_null($stock_qty)) $stock_total_visible += (int) $stock_qty;

      $manage = $product->get_manage_stock();
      if ($manage && !is_null($stock_qty) && (int) $stock_qty <= 10) {
        $low_stock[] = [
          'id' => $product->get_id(),
          'name' => $product->get_name(),
          'sku' => $product->get_sku(),
          'stock' => (int) $stock_qty,
          'status' => $product->get_stock_status(),
        ];
      }

      if (!isset($sold_product_ids[(int) $product->get_id()])) {
        $no_movement[] = [
          'id' => $product->get_id(),
          'name' => $product->get_name(),
          'sku' => $product->get_sku(),
          'stock' => is_null($stock_qty) ? '' : (int) $stock_qty,
        ];
      }
    });

    usort($low_stock, function($a, $b) { return $a['stock'] <=> $b['stock']; });
    $low_stock = array_slice($low_stock, 0, 12);

    usort($no_movement, function($a, $b) {
      $sa = is_numeric($a['stock']) ? (int) $a['stock'] : -1;
      $sb = is_numeric($b['stock']) ? (int) $b['stock'] : -1;
      return $sb <=> $sa;
    });
    $no_movement = array_slice($no_movement, 0, 12);

    $inactive_customers = [];
    foreach ($customer_history as $row) {
      if ($row['last_ts'] <= $inactive_cutoff) {
        $inactive_customers[] = [
          'name' => $row['name'],
          'email' => $row['email'],
          'days' => (int) floor(($now_ts - $row['last_ts']) / DAY_IN_SECONDS),
          'revenue' => (float) $row['revenue'],
          'orders' => (int) $row['orders'],
          'last_date' => gmdate('d/m/Y', $row['last_ts']),
        ];
      }
    }
    usort($inactive_customers, function($a, $b) {
      if ($a['days'] === $b['days']) return $b['revenue'] <=> $a['revenue'];
      return $b['days'] <=> $a['days'];
    });
    $inactive_customers = array_slice($inactive_customers, 0, 12);

    $avg_ticket = $revenue_orders > 0 ? ($total_revenue / $revenue_orders) : 0.0;

    $result = [
      'filters' => ['date_from' => $date_from, 'date_to' => $date_to],
      'kpis' => [
        'revenue' => $total_revenue,
        'orders' => $orders_count,
        'avg_ticket' => $avg_ticket,
        'customers' => count($customer_keys_in_range),
        'items' => $items_sold,
        'stock' => $stock_total_visible,
        'active_sellers' => count($seller_sales),
        'low_stock_count' => count($low_stock),
      ],
      'charts' => [
        'sales_series' => $sales_series,
        'top_products' => $top_products,
        'seller_sales' => $seller_sales,
        'transport_sales' => $transport_sales,
        'status_counts' => $status_counts,
        'company_sales' => $company_sales,
      ],
      'tables' => [
        'low_stock' => $low_stock,
        'inactive_customers' => $inactive_customers,
        'no_movement' => $no_movement,
      ],
    ];

    if ($role === 'ripex_admin') {
      set_transient($transient_key, $result, 10 * MINUTE_IN_SECONDS);
    }

    $this->json_ok($result);
  }
}

/** Replace the original report callback after Ripex_Portal registers AJAX. */
add_action('plugins_loaded', function() {
  $portal = Ripex_Portal::instance();
  remove_action('wp_ajax_ripex_portal_get_reports', [$portal, 'ajax_get_reports']);
  add_action('wp_ajax_ripex_portal_get_reports', [Ripex_Portal_Performance::instance($portal), 'ajax_get_reports']);
}, 20);
