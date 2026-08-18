<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer-directory performance bridge for RIPEX Portal.
 *
 * Phase 04 removes the repeated WP_User_Query(300/1000) + per-user metadata
 * pattern from ajax_get_customers(). The complete eligible customer scope is
 * resolved with lightweight ID/meta queries, filtered as scalar data, and only
 * one 50-row page is returned to the browser.
 */
final class Ripex_Portal_Customers_Performance {
  private static $instance = null;
  private $portal;

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

  private function first_non_empty(array $meta, array $keys) {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $meta)) continue;
      $value = is_string($meta[$key]) ? trim($meta[$key]) : '';
      if ($value !== '') return $value;
    }
    return '';
  }

  /**
   * Admin scope matches the historic union:
   * - users with customer/default_wholesaler/super-mayorista role; OR
   * - users with any RIPEX commercial metadata used by the old fallback query.
   */
  private function admin_customer_ids() {
    global $wpdb;

    $cap_key = $wpdb->prefix . 'capabilities';
    $role_customer = '%' . $wpdb->esc_like('"customer"') . '%';
    $role_wholesaler = '%' . $wpdb->esc_like('"default_wholesaler"') . '%';
    $role_super = '%' . $wpdb->esc_like('"super-mayorista"') . '%';

    $sql = $wpdb->prepare(
      "SELECT DISTINCT u.ID
       FROM {$wpdb->users} u
       WHERE EXISTS (
         SELECT 1
         FROM {$wpdb->usermeta} cap
         WHERE cap.user_id = u.ID
           AND cap.meta_key = %s
           AND (
             cap.meta_value LIKE %s
             OR cap.meta_value LIKE %s
             OR cap.meta_value LIKE %s
           )
       )
       OR EXISTS (
         SELECT 1
         FROM {$wpdb->usermeta} cm
         WHERE cm.user_id = u.ID
           AND cm.meta_key IN (
             'afreg_additional_42210',
             'afreg_additional_42208',
             'afreg_additional_42209',
             'afreg_additional_42207',
             'billing_city'
           )
           AND cm.meta_value <> ''
       )
       ORDER BY u.ID ASC",
      $cap_key,
      $role_customer,
      $role_wholesaler,
      $role_super
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return array_map('intval', (array) $wpdb->get_col($sql));
  }

  /** Resolve seller customers from the same normalized AFREG vendor rule. */
  private function vendor_customer_ids($vendor_user_id) {
    $vendor_user_id = (int) $vendor_user_id;
    $labels = (array) $this->portal_call('get_vendor_match_labels', $vendor_user_id);
    if (empty($labels)) return [];

    global $wpdb;
    $rows = $wpdb->get_results(
      "SELECT user_id, meta_value
       FROM {$wpdb->usermeta}
       WHERE meta_key = 'afreg_additional_42207'
         AND meta_value <> ''
       ORDER BY user_id ASC, umeta_id ASC"
    );

    $ids = [];
    foreach ((array) $rows as $row) {
      $normalized = (string) $this->portal_call('normalize_vendor_label', (string) $row->meta_value);
      if ($normalized !== '' && in_array($normalized, $labels, true)) {
        $ids[(int) $row->user_id] = true;
      }
    }

    return array_keys($ids);
  }

  /**
   * Load only scalar user/meta fields for the eligible scope.
   *
   * Metadata rows are ordered by umeta_id and only the first value per key is
   * retained, matching get_user_meta($id, $key, true) semantics more closely
   * than a MAX(CASE...) pivot when duplicate historical metadata exists.
   */
  private function load_customer_index(array $user_ids) {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    if (empty($user_ids)) return [];

    global $wpdb;
    $index = [];
    $chunk_size = 400;

    foreach (array_chunk($user_ids, $chunk_size) as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
      $sql = "SELECT ID, display_name, user_email
              FROM {$wpdb->users}
              WHERE ID IN ($placeholders)";
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $users = $wpdb->get_results($wpdb->prepare($sql, ...$chunk));
      foreach ((array) $users as $user) {
        $id = (int) $user->ID;
        $index[$id] = [
          'id' => $id,
          'name' => (string) $user->display_name,
          'email' => (string) $user->user_email,
          'meta' => [],
        ];
      }
    }

    $meta_keys = [
      'billing_phone',
      'billing_city', 'shipping_city', 'afreg_additional_city', 'city',
      'billing_state', 'shipping_state', 'afreg_additional_state', 'region',
      'afreg_additional_42210',
      'afreg_additional_42208',
      'afreg_additional_42209',
      'afreg_additional_42207',
    ];
    $meta_key_sql = "'" . implode("','", array_map('esc_sql', $meta_keys)) . "'";

    foreach (array_chunk($user_ids, $chunk_size) as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
      $sql = "SELECT user_id, meta_key, meta_value
              FROM {$wpdb->usermeta}
              WHERE user_id IN ($placeholders)
                AND meta_key IN ($meta_key_sql)
              ORDER BY user_id ASC, umeta_id ASC";
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $meta_rows = $wpdb->get_results($wpdb->prepare($sql, ...$chunk));

      foreach ((array) $meta_rows as $row) {
        $id = (int) $row->user_id;
        $key = (string) $row->meta_key;
        if (!isset($index[$id])) continue;
        if (!array_key_exists($key, $index[$id]['meta'])) {
          $index[$id]['meta'][$key] = (string) $row->meta_value;
        }
      }
    }

    return $index;
  }

  private function project_customer(array $row) {
    $meta = (array) ($row['meta'] ?? []);

    $city = $this->first_non_empty($meta, [
      'billing_city', 'shipping_city', 'afreg_additional_city', 'city',
    ]);
    $region = $this->first_non_empty($meta, [
      'billing_state', 'shipping_state', 'afreg_additional_state', 'region',
    ]);

    return [
      'id' => (int) $row['id'],
      'name' => (string) ($row['name'] ?? ''),
      'email' => (string) ($row['email'] ?? ''),
      'phone' => isset($meta['billing_phone']) ? (string) $meta['billing_phone'] : '',
      'city' => $city,
      'region' => $region,
      'rut' => isset($meta['afreg_additional_42210']) ? trim((string) $meta['afreg_additional_42210']) : '',
      'razon_social' => isset($meta['afreg_additional_42208']) ? trim((string) $meta['afreg_additional_42208']) : '',
      'giro' => isset($meta['afreg_additional_42209']) ? trim((string) $meta['afreg_additional_42209']) : '',
      'vendedor' => isset($meta['afreg_additional_42207']) ? trim((string) $meta['afreg_additional_42207']) : '',
    ];
  }

  public function ajax_get_customers() {
    $this->portal_call('check_ajax_access');
    $this->portal_call('require_wc_or_die');

    $role = (string) $this->portal_call('current_user_role_key');
    if (!in_array($role, ['ripex_admin', 'ripex_vendedor'], true)) {
      $this->json_err('No autorizado para ver clientes.');
      return;
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $city = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
    $vendor_filter = isset($_POST['vendor']) ? sanitize_text_field(wp_unslash($_POST['vendor'])) : '';
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $per_page = 50;
    $user_id = get_current_user_id();

    $eligible_ids = ($role === 'ripex_vendedor')
      ? $this->vendor_customer_ids($user_id)
      : $this->admin_customer_ids();

    if (empty($eligible_ids)) {
      $this->json_ok([
        'customers' => [],
        'role' => $role,
        'count' => 0,
        'cities' => [],
        'vendors' => [],
        'page' => 1,
        'per_page' => $per_page,
        'total' => 0,
        'total_pages' => 0,
      ]);
      return;
    }

    $index = $this->load_customer_index($eligible_ids);
    $city_filter_key = (string) $this->portal_call('normalize_text_key', $city);
    $vendor_filter_key = (string) $this->portal_call('normalize_text_key', $vendor_filter);
    $search_key = strtolower((string) $search);

    $cities_available = [];
    $vendors_available = [];
    $matching = [];

    foreach ($index as $raw) {
      $customer = $this->project_customer($raw);

      // Vendor scope was already resolved from normalized assignment labels;
      // keep the original helper as defense in depth for the final candidate.
      if ($role === 'ripex_vendedor' && !$this->portal_call('customer_assigned_to_vendor', $customer['id'], $user_id)) {
        continue;
      }

      $customer_city_key = (string) $this->portal_call('normalize_text_key', $customer['city']);
      if ($customer['city'] !== '') {
        $cities_available[$customer_city_key ?: $customer['city']] = $customer['city'];
      }

      if ($city_filter_key !== '' && $customer_city_key !== $city_filter_key) continue;

      $customer_vendor_key = (string) $this->portal_call('normalize_text_key', $customer['vendedor']);
      if ($customer['vendedor'] !== '') {
        $vendors_available[$customer_vendor_key ?: $customer['vendedor']] = $customer['vendedor'];
      }

      if ($role === 'ripex_admin' && $vendor_filter_key !== '' && $customer_vendor_key !== $vendor_filter_key) continue;

      if ($search_key !== '') {
        $hay = strtolower(
          $customer['name'] . ' ' .
          $customer['email'] . ' ' .
          $customer['rut'] . ' ' .
          $customer['razon_social'] . ' ' .
          $customer['giro'] . ' ' .
          $customer['city'] . ' ' .
          $customer['vendedor']
        );
        if (strpos($hay, $search_key) === false) continue;
      }

      $matching[] = $customer;
    }

    usort($matching, function($a, $b) {
      return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    $cities_available = array_values(array_filter(array_unique(array_values($cities_available))));
    usort($cities_available, function($a, $b) { return strcasecmp($a, $b); });
    $vendors_available = array_values(array_filter(array_unique(array_values($vendors_available))));
    usort($vendors_available, function($a, $b) { return strcasecmp($a, $b); });

    $total = count($matching);
    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;
    if ($total_pages > 0 && $page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;
    $page_rows = array_slice($matching, $offset, $per_page);

    $this->json_ok([
      'customers' => array_values($page_rows),
      'role' => $role,
      'count' => $total,
      'cities' => $cities_available,
      'vendors' => $vendors_available,
      'page' => $page,
      'per_page' => $per_page,
      'total' => $total,
      'total_pages' => $total_pages,
    ]);
  }

  public function enqueue_customers_script() {
    if (!wp_script_is('ripex-portal-js', 'enqueued')) return;

    wp_enqueue_script(
      'ripex-customers-pagination-js',
      RIPEX_PORTAL_URL . 'assets/js/customers-pagination.js',
      ['ripex-portal-js'],
      RIPEX_PORTAL_VERSION,
      true
    );
  }
}

add_action('plugins_loaded', function() {
  $portal = Ripex_Portal::instance();
  remove_action('wp_ajax_ripex_portal_get_customers', [$portal, 'ajax_get_customers']);

  $bridge = Ripex_Portal_Customers_Performance::instance($portal);
  add_action('wp_ajax_ripex_portal_get_customers', [$bridge, 'ajax_get_customers']);
  add_action('wp_enqueue_scripts', [$bridge, 'enqueue_customers_script'], 55);
}, 50);
