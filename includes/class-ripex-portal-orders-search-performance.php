<?php
if (!defined('ABSPATH')) exit;

/**
 * Orders search performance/correctness bridge for RIPEX Portal.
 *
 * Phase 2 moves order search before pagination. The 1.5.14 implementation
 * paginated first and then searched only the 30 materialized orders, so valid
 * matches on later pages could appear to not exist.
 *
 * No-search requests are delegated unchanged to the Phase 1 orders bridge.
 * Search requests resolve a targeted legacy-order candidate set, apply the
 * existing status/date/vendor rules, validate the exact historic PHP search
 * semantics, and only then paginate/materialize the final 30 rows.
 *
 * HPOS migration is intentionally deferred to the dedicated HPOS phase.
 */
final class Ripex_Portal_Orders_Search_Performance {
  private static $instance = null;
  private $portal;
  private $phase1;

  public static function instance($portal = null, $phase1 = null) {
    if (self::$instance === null) {
      $portal = $portal ?: Ripex_Portal::instance();
      $phase1 = $phase1 ?: Ripex_Portal_Orders_Performance::instance($portal);
      self::$instance = new self($portal, $phase1);
    }
    return self::$instance;
  }

  private function __construct($portal, $phase1) {
    $this->portal = $portal;
    $this->phase1 = $phase1;
  }

  /** Invoke an existing private Ripex_Portal helper in portal object scope. */
  private function portal_call($method, ...$args) {
    return (function($method, $args) {
      return $this->{$method}(...$args);
    })->call($this->portal, $method, $args);
  }

  private function json_ok($data) {
    wp_send_json_success($data);
  }

  /**
   * Find broad order candidates using only fields used by the historic search.
   *
   * AFREG precedence matches get_customer_afreg(): current customer usermeta
   * wins; order snapshot metadata is used only when that usermeta is empty.
   * Guest/legacy orders whose _customer_user is empty can still resolve an
   * existing customer by billing email, matching get_customer_user_id_from_order.
   */
  private function search_candidate_order_ids($search) {
    $search = trim((string) $search);
    if ($search === '') return [];

    global $wpdb;
    $like = '%' . $wpdb->esc_like($search) . '%';

    $sql = $wpdb->prepare(
      "SELECT p.ID
       FROM {$wpdb->posts} p
       LEFT JOIN (
         SELECT post_id,
           MAX(CASE WHEN meta_key = '_billing_first_name' THEN meta_value END) AS billing_first,
           MAX(CASE WHEN meta_key = '_billing_last_name' THEN meta_value END) AS billing_last,
           MAX(CASE WHEN meta_key = '_billing_company' THEN meta_value END) AS billing_company,
           MAX(CASE WHEN meta_key = '_billing_email' THEN meta_value END) AS billing_email,
           MAX(CASE WHEN meta_key = '_customer_user' THEN meta_value END) AS customer_user,
           MAX(CASE WHEN meta_key = '_payment_method_title' THEN meta_value END) AS payment_title,
           MAX(CASE WHEN meta_key = '_ripex_rut_empresa' THEN meta_value END) AS ripex_rut,
           MAX(CASE WHEN meta_key = '_ripex_razon_social' THEN meta_value END) AS ripex_razon,
           MAX(CASE WHEN meta_key = '_ripex_giro' THEN meta_value END) AS ripex_giro,
           MAX(CASE WHEN meta_key = '_ripex_vendedor' THEN meta_value END) AS ripex_vendedor
         FROM {$wpdb->postmeta}
         WHERE meta_key IN (
           '_billing_first_name','_billing_last_name','_billing_company','_billing_email',
           '_customer_user','_payment_method_title','_ripex_rut_empresa',
           '_ripex_razon_social','_ripex_giro','_ripex_vendedor'
         )
         GROUP BY post_id
       ) om ON om.post_id = p.ID
       LEFT JOIN {$wpdb->users} eu
         ON CAST(COALESCE(om.customer_user, '0') AS UNSIGNED) = 0
        AND eu.user_email = om.billing_email
       LEFT JOIN (
         SELECT user_id,
           MAX(CASE WHEN meta_key = 'afreg_additional_42210' THEN meta_value END) AS rut,
           MAX(CASE WHEN meta_key = 'afreg_additional_42208' THEN meta_value END) AS razon,
           MAX(CASE WHEN meta_key = 'afreg_additional_42209' THEN meta_value END) AS giro,
           MAX(CASE WHEN meta_key = 'afreg_additional_42207' THEN meta_value END) AS vendedor
         FROM {$wpdb->usermeta}
         WHERE meta_key IN (
           'afreg_additional_42210','afreg_additional_42208',
           'afreg_additional_42209','afreg_additional_42207'
         )
         GROUP BY user_id
       ) um ON um.user_id = CASE
         WHEN CAST(COALESCE(om.customer_user, '0') AS UNSIGNED) > 0
           THEN CAST(om.customer_user AS UNSIGNED)
         ELSE eu.ID
       END
       WHERE p.post_type = 'shop_order'
         AND CONCAT_WS(' ',
           CONCAT('#', p.ID),
           TRIM(CONCAT_WS(' ', COALESCE(om.billing_first, ''), COALESCE(om.billing_last, ''))),
           COALESCE(om.billing_company, ''),
           COALESCE(om.billing_email, ''),
           COALESCE(NULLIF(um.rut, ''), om.ripex_rut, ''),
           COALESCE(NULLIF(um.razon, ''), om.ripex_razon, ''),
           COALESCE(NULLIF(um.giro, ''), om.ripex_giro, ''),
           COALESCE(NULLIF(um.vendedor, ''), om.ripex_vendedor, ''),
           COALESCE(om.payment_title, '')
         ) LIKE %s
       ORDER BY p.post_date DESC, p.ID DESC",
      $like
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return array_map('intval', (array) $wpdb->get_col($sql));
  }

  /** Apply order status/date filters before any WooCommerce object is created. */
  private function filter_candidate_ids(array $candidate_ids, $status, $date_from, $date_to) {
    $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
    if (empty($candidate_ids)) return [];

    global $wpdb;
    $where = ["p.post_type = 'shop_order'"];
    $params = [];

    $placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
    $where[] = "p.ID IN ($placeholders)";
    $params = array_merge($params, $candidate_ids);

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
    $sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE $where_sql ORDER BY p.post_date DESC, p.ID DESC";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, ...$params)));
  }

  /** Exact 1.5.14 PHP search semantics, used before pagination for parity. */
  private function order_matches_search($order, $search, &$af = null) {
    if (!$order || !is_a($order, 'WC_Order')) return false;

    $customer_id = (int) $this->portal_call('get_customer_user_id_from_order', $order);
    $af = (array) $this->portal_call('get_customer_afreg', $order, $customer_id);

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

    return strpos($hay, strtolower((string) $search)) !== false;
  }

  private function build_order_row($order, $role, $user_id, $af = null) {
    if (!is_array($af)) {
      $customer_id = (int) $this->portal_call('get_customer_user_id_from_order', $order);
      $af = (array) $this->portal_call('get_customer_afreg', $order, $customer_id);
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

    return [
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
      'can_edit' => (bool) $this->portal_call('can_edit_order', $order, $role, $user_id),
      'exported' => (bool) $this->portal_call('is_order_exported', $order),
      'total' => $total_order,
      'currency' => $currency,
      'shipping' => $shipping_title,
    ];
  }

  public function ajax_get_orders() {
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

    // Preserve Phase 1 exactly when no search is requested.
    if ($search === '') {
      $this->phase1->ajax_get_orders();
      return;
    }

    $this->portal_call('check_ajax_access');
    $this->portal_call('require_wc_or_die');

    $role = (string) $this->portal_call('current_user_role_key');
    $user_id = get_current_user_id();
    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
    $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : '';
    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $per_page = 30;

    // 1) Search candidate IDs in SQL using only historic searchable fields.
    // 2) Apply status/date in SQL.
    // 3) Validate exact PHP search + vendor scope BEFORE pagination.
    $candidate_ids = $this->search_candidate_order_ids($search);
    $candidate_ids = $this->filter_candidate_ids($candidate_ids, $status, $date_from, $date_to);

    $matching_ids = [];
    foreach ($candidate_ids as $order_id) {
      $order = wc_get_order((int) $order_id);
      if (!$order || !is_a($order, 'WC_Order')) continue;

      if ($role === 'ripex_vendedor' && !$this->portal_call('vendor_mine_filter', $order, $user_id)) {
        unset($order);
        continue;
      }

      $af = null;
      if (!$this->order_matches_search($order, $search, $af)) {
        unset($order);
        continue;
      }

      $matching_ids[] = (int) $order_id;
      unset($order);
    }

    $total = count($matching_ids);
    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;
    $offset = ($page - 1) * $per_page;
    $page_ids = array_slice($matching_ids, $offset, $per_page);

    $rows = [];
    foreach ($page_ids as $order_id) {
      $order = wc_get_order((int) $order_id);
      if (!$order || !is_a($order, 'WC_Order')) continue;

      // Defense in depth remains active on the final response page.
      if ($role === 'ripex_vendedor' && !$this->portal_call('vendor_mine_filter', $order, $user_id)) {
        continue;
      }

      $af = null;
      if (!$this->order_matches_search($order, $search, $af)) continue;
      $rows[] = $this->build_order_row($order, $role, $user_id, $af);
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

/** Replace the Phase 1 orders callback only after it has been registered. */
add_action('plugins_loaded', function() {
  $portal = Ripex_Portal::instance();
  $phase1 = Ripex_Portal_Orders_Performance::instance($portal);
  remove_action('wp_ajax_ripex_portal_get_orders', [$phase1, 'ajax_get_orders']);
  add_action(
    'wp_ajax_ripex_portal_get_orders',
    [Ripex_Portal_Orders_Search_Performance::instance($portal, $phase1), 'ajax_get_orders']
  );
}, 30);
