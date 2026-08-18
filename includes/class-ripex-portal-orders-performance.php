<?php
if (!defined('ABSPATH')) exit;

/**
 * Orders-list performance bridge for RIPEX Portal.
 *
 * Phase 1 removes the all-orders GROUP BY/postmeta scan used to resolve a
 * vendor's commercial order scope. The visible behavior and strict ownership
 * precedence from 1.5.14 are preserved:
 *
 * 1. explicit _ripex_seller_id wins;
 * 2. otherwise explicit _ripex_vendedor wins;
 * 3. otherwise fall back to the customer's RIPEX vendor assignment.
 *
 * This phase intentionally leaves the existing search semantics unchanged;
 * backend search-before-pagination is handled in the following phase.
 */
final class Ripex_Portal_Orders_Performance {
  private static $instance = null;
  private $portal;
  private $vendor_order_ids_cache = [];

  public static function instance($portal = null) {
    if (self::$instance === null) {
      self::$instance = new self($portal ?: Ripex_Portal::instance());
    }
    return self::$instance;
  }

  private function __construct($portal) {
    $this->portal = $portal;
  }

  /** Invoke an existing private Ripex_Portal helper in the portal object scope. */
  private function portal_call($method, ...$args) {
    return (function($method, $args) {
      return $this->{$method}(...$args);
    })->call($this->portal, $method, $args);
  }

  private function json_ok($data) {
    wp_send_json_success($data);
  }

  /**
   * Resolve vendor order IDs without materializing/grouping every shop_order.
   *
   * The original implementation LEFT JOINed all order postmeta and grouped the
   * complete order table before deciding ownership. Here each precedence layer
   * touches only the relevant meta key and returns matching candidates.
   */
  private function get_vendor_order_ids($vendor_user_id) {
    $vendor_user_id = (int) $vendor_user_id;
    if (isset($this->vendor_order_ids_cache[$vendor_user_id])) {
      return $this->vendor_order_ids_cache[$vendor_user_id];
    }

    $vendor_labels = (array) $this->portal_call('get_vendor_match_labels', $vendor_user_id);
    if (empty($vendor_labels)) {
      $this->vendor_order_ids_cache[$vendor_user_id] = [];
      return [];
    }

    global $wpdb;
    $ids = [];

    // Priority 1: explicit seller. MAX(meta_value) mirrors the legacy grouped
    // query if duplicate metadata exists on an old order.
    $seller_sql = $wpdb->prepare(
      "SELECT sm.post_id
       FROM {$wpdb->postmeta} sm
       INNER JOIN {$wpdb->posts} p ON p.ID = sm.post_id AND p.post_type = 'shop_order'
       WHERE sm.meta_key = '_ripex_seller_id'
       GROUP BY sm.post_id
       HAVING MAX(sm.meta_value) <> ''
          AND CAST(MAX(sm.meta_value) AS UNSIGNED) = %d",
      $vendor_user_id
    );
    foreach ((array) $wpdb->get_col($seller_sql) as $order_id) {
      $ids[(int) $order_id] = true;
    }

    // Priority 2: explicit vendor label, but only on orders without an explicit
    // seller. We normalize only the much smaller _ripex_vendedor candidate set.
    $vendor_rows = $wpdb->get_results(
      "SELECT vm.post_id AS order_id, MAX(vm.meta_value) AS vendedor_label
       FROM {$wpdb->postmeta} vm
       INNER JOIN {$wpdb->posts} p ON p.ID = vm.post_id AND p.post_type = 'shop_order'
       WHERE vm.meta_key = '_ripex_vendedor'
         AND vm.meta_value <> ''
         AND NOT EXISTS (
           SELECT 1
           FROM {$wpdb->postmeta} sm
           WHERE sm.post_id = vm.post_id
             AND sm.meta_key = '_ripex_seller_id'
             AND sm.meta_value <> ''
         )
       GROUP BY vm.post_id"
    );

    foreach ((array) $vendor_rows as $row) {
      $normalized = (string) $this->portal_call('normalize_vendor_label', (string) ($row->vendedor_label ?? ''));
      if ($normalized !== '' && in_array($normalized, $vendor_labels, true)) {
        $ids[(int) $row->order_id] = true;
      }
    }

    // Customer assignment lookup is still normalized in PHP because historic
    // AFREG values can contain accents/case/spacing variations. This query is
    // limited to one usermeta key rather than joining it to every order.
    $assigned_customer_ids = [];
    $customer_vendor_rows = $wpdb->get_results(
      "SELECT user_id, meta_value
       FROM {$wpdb->usermeta}
       WHERE meta_key = 'afreg_additional_42207'
         AND meta_value <> ''"
    );

    foreach ((array) $customer_vendor_rows as $row) {
      $normalized = (string) $this->portal_call('normalize_vendor_label', (string) $row->meta_value);
      if ($normalized !== '' && in_array($normalized, $vendor_labels, true)) {
        $assigned_customer_ids[(int) $row->user_id] = true;
      }
    }

    // Priority 3: customer fallback only when neither explicit seller nor
    // explicit vendor label is present. Query only _customer_user rows for the
    // customers assigned to this vendor instead of grouping every order.
    if (!empty($assigned_customer_ids)) {
      $customer_ids = array_keys($assigned_customer_ids);
      $placeholders = implode(',', array_fill(0, count($customer_ids), '%d'));

      $fallback_sql =
        "SELECT cm.post_id
         FROM {$wpdb->postmeta} cm
         INNER JOIN {$wpdb->posts} p ON p.ID = cm.post_id AND p.post_type = 'shop_order'
         WHERE cm.meta_key = '_customer_user'
           AND NOT EXISTS (
             SELECT 1
             FROM {$wpdb->postmeta} sm
             WHERE sm.post_id = cm.post_id
               AND sm.meta_key = '_ripex_seller_id'
               AND sm.meta_value <> ''
           )
           AND NOT EXISTS (
             SELECT 1
             FROM {$wpdb->postmeta} vm
             WHERE vm.post_id = cm.post_id
               AND vm.meta_key = '_ripex_vendedor'
               AND vm.meta_value <> ''
           )
         GROUP BY cm.post_id
         HAVING CAST(MAX(cm.meta_value) AS UNSIGNED) IN ($placeholders)";

      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $fallback_ids = $wpdb->get_col($wpdb->prepare($fallback_sql, ...$customer_ids));
      foreach ((array) $fallback_ids as $order_id) {
        $ids[(int) $order_id] = true;
      }
    }

    $result = array_keys($ids);
    $this->vendor_order_ids_cache[$vendor_user_id] = $result;
    return $result;
  }

  /**
   * Same response contract and row-building behavior as Ripex_Portal 1.5.14.
   * Only the vendor ownership candidate query changes in this phase.
   */
  public function ajax_get_orders() {
    $this->portal_call('check_ajax_access');
    $this->portal_call('require_wc_or_die');

    $role = (string) $this->portal_call('current_user_role_key');
    $user_id = get_current_user_id();

    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : '';
    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $per_page = 30;

    $orders = [];
    $total = 0;
    $total_pages = 1;

    // Vendor scope is always server-side and scope=all is never honored.
    if ($role === 'ripex_vendedor') {
      $vendor_order_ids = array_map('intval', $this->get_vendor_order_ids($user_id));

      if (empty($vendor_order_ids)) {
        $this->json_ok([
          'orders' => [],
          'role' => $role,
          'scope' => 'mine',
          'page' => 1,
          'total_pages' => 0,
          'total' => 0,
        ]);
        return;
      }

      global $wpdb;
      $where = [];
      $params = [];

      $placeholders = implode(',', array_fill(0, count($vendor_order_ids), '%d'));
      $where[] = "p.ID IN ($placeholders)";
      $params = array_merge($params, $vendor_order_ids);
      $where[] = "p.post_type = 'shop_order'";

      if ($status) {
        $status_key = (strpos($status, 'wc-') === 0) ? $status : 'wc-' . $status;
        $where[] = 'p.post_status = %s';
        $params[] = $status_key;
      }
      if ($date_from) {
        $where[] = 'p.post_date >= %s';
        $params[] = $date_from . ' 00:00:00';
      }
      if ($date_to) {
        $where[] = 'p.post_date <= %s';
        $params[] = $date_to . ' 23:59:59';
      }

      $where_sql = implode(' AND ', $where);
      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $count_sql = "SELECT COUNT(1) FROM {$wpdb->posts} p WHERE $where_sql";
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
      $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;
      $offset = ($page - 1) * $per_page;

      $list_params = $params;
      $list_params[] = $per_page;
      $list_params[] = $offset;
      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $list_sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE $where_sql ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d";
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $page_ids = $wpdb->get_col($wpdb->prepare($list_sql, ...$list_params));

      foreach ((array) $page_ids as $order_id) {
        $order = wc_get_order((int) $order_id);
        if ($order && is_a($order, 'WC_Order') && $this->portal_call('vendor_mine_filter', $order, $user_id)) {
          $orders[] = $order;
        }
      }
      $scope = 'mine';
    } else {
      // Admin and warehouse preserve the original WooCommerce query path.
      $args = [
        'limit' => $per_page,
        'page' => $page,
        'paginate' => true,
        'orderby' => 'date',
        'order' => 'DESC',
        'return' => 'objects',
      ];

      if ($status) $args['status'] = [$status];
      if ($date_from) $args['date_created'] = '>=' . $date_from . ' 00:00:00';
      if ($date_from && $date_to) {
        $args['date_created'] = $date_from . ' 00:00:00...' . $date_to . ' 23:59:59';
      } elseif ($date_to) {
        $args['date_created'] = '<=' . $date_to . ' 23:59:59';
      }

      $result = (new WC_Order_Query($args))->get_orders();
      if (is_object($result) && isset($result->orders)) {
        $orders = (array) $result->orders;
        $total = (int) $result->total;
        $total_pages = (int) $result->max_num_pages;
      } else {
        $orders = is_array($result) ? $result : [];
        $total = count($orders);
        $total_pages = 1;
      }
    }

    $rows = [];
    foreach ($orders as $order) {
      if (!$order || !is_a($order, 'WC_Order')) continue;

      // Defense in depth: never expose a vendor order outside current rules.
      if ($role === 'ripex_vendedor' && !$this->portal_call('vendor_mine_filter', $order, $user_id)) {
        continue;
      }

      $customer_id = (int) $this->portal_call('get_customer_user_id_from_order', $order);
      $af = (array) $this->portal_call('get_customer_afreg', $order, $customer_id);

      // Search remains intentionally identical to 1.5.14 for Phase 1.
      if ($search) {
        $hay = strtolower(
          '#' . $order->get_id() . ' ' .
          $order->get_formatted_billing_full_name() . ' ' .
          $order->get_billing_company() . ' ' .
          $order->get_billing_email() . ' ' .
          ($af['rut'] ?? '') . ' ' .
          ($af['razon_social'] ?? '') . ' ' .
          ($af['giro'] ?? '') . ' ' .
          ($af['vendedor'] ?? '') . ' ' .
          $order->get_payment_method_title()
        );
        if (strpos($hay, strtolower($search)) === false) continue;
      }

      $shipping_title = '';
      $shipping_items = $order->get_items('shipping');
      if (!empty($shipping_items)) {
        $first = current($shipping_items);
        if ($first && method_exists($first, 'get_method_title')) {
          $shipping_title = (string) $this->portal_call('clean_transport_label', (string) $first->get_method_title());
        }
      }

      $total_order = $order->get_total();
      $currency = $order->get_currency();
      if ($role === 'ripex_bodeguero') {
        $total_order = null;
        $currency = null;
      }

      $razon = ($af['razon_social'] ?? '') ?: $order->get_billing_company();
      $can_edit = (bool) $this->portal_call('can_edit_order', $order, $role, $user_id);

      $rows[] = [
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/y H:i') : '',
        'customer' => trim($order->get_formatted_billing_full_name()) ?: $order->get_billing_company(),
        'company' => $razon,
        'rut' => $af['rut'] ?? '',
        'status' => $order->get_status(),
        'status_label' => wc_get_order_status_name($order->get_status()),
        'payment' => $order->get_payment_method_title(),
        'vendedor' => $af['vendedor'] ?? '',
        'can_edit' => $can_edit,
        'exported' => (bool) $this->portal_call('is_order_exported', $order),
        'total' => $total_order,
        'currency' => $currency,
        'shipping' => $shipping_title,
      ];
    }

    $this->json_ok([
      'orders' => $rows,
      'role' => $role,
      'scope' => ($role === 'ripex_vendedor') ? 'mine' : ($scope ?: 'all'),
      'page' => $page,
      'total_pages' => $total_pages,
      'total' => $total,
    ]);
  }
}

/** Replace only the original orders-list callback after portal registration. */
add_action('plugins_loaded', function() {
  $portal = Ripex_Portal::instance();
  remove_action('wp_ajax_ripex_portal_get_orders', [$portal, 'ajax_get_orders']);
  add_action(
    'wp_ajax_ripex_portal_get_orders',
    [Ripex_Portal_Orders_Performance::instance($portal), 'ajax_get_orders']
  );
}, 20);
