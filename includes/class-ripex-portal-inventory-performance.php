<?php
if (!defined('ABSPATH')) exit;

/**
 * Inventory performance bridge for RIPEX Portal.
 *
 * Phase 03 replaces the up-to-500 product/variation materialization used by
 * ajax_get_products() with true backend pagination. Search/category/sort are
 * resolved before WooCommerce objects are created, and only the requested page
 * is hydrated into WC_Product instances.
 */
final class Ripex_Portal_Inventory_Performance {
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

  /**
   * Query one inventory page using WordPress product storage + Woo lookup table.
   *
   * Current 1.5.14 semantics intentionally preserved:
   * - no category: published products + variations;
   * - category selected: parent products only, exactly like the existing tax query;
   * - search: title/content/excerpt OR SKU;
   * - sort options: name, SKU, stock asc/desc.
   */
  private function query_inventory_page($search, $category, $orderby, $page, $per_page) {
    global $wpdb;

    $search = trim((string) $search);
    $category = trim((string) $category);
    $page = max(1, (int) $page);
    $per_page = max(20, min(100, (int) $per_page));

    $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
    $where = ["p.post_status = 'publish'"];
    $params = [];

    if ($category !== '') {
      // The historic implementation changed post_type to product when category
      // filtering was enabled, so variations are intentionally excluded here.
      $where[] = "p.post_type = 'product'";
      $where[] = "EXISTS (
        SELECT 1
        FROM {$wpdb->term_relationships} tr
        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
        WHERE tr.object_id = p.ID
          AND tt.taxonomy = 'product_cat'
          AND t.slug = %s
      )";
      $params[] = $category;
    } else {
      $where[] = "p.post_type IN ('product','product_variation')";
    }

    if ($search !== '') {
      $like = '%' . $wpdb->esc_like($search) . '%';
      $where[] = "(
        p.post_title LIKE %s
        OR p.post_content LIKE %s
        OR p.post_excerpt LIKE %s
        OR COALESCE(l.sku, '') LIKE %s
      )";
      array_push($params, $like, $like, $like, $like);
    }

    $where_sql = implode(' AND ', $where);

    // Keep null/unmanaged stock at the end for both stock directions, matching
    // the former PHP sorter which substituted +/- PHP_INT values.
    switch ($orderby) {
      case 'name_desc':
        $order_sql = 'p.post_title DESC, p.ID DESC';
        break;
      case 'sku_asc':
        $order_sql = "COALESCE(l.sku, '') ASC, p.post_title ASC, p.ID ASC";
        break;
      case 'sku_desc':
        $order_sql = "COALESCE(l.sku, '') DESC, p.post_title ASC, p.ID ASC";
        break;
      case 'stock_asc':
        $order_sql = 'CASE WHEN l.stock_quantity IS NULL THEN 1 ELSE 0 END ASC, l.stock_quantity ASC, p.post_title ASC, p.ID ASC';
        break;
      case 'stock_desc':
        $order_sql = 'CASE WHEN l.stock_quantity IS NULL THEN 1 ELSE 0 END ASC, l.stock_quantity DESC, p.post_title ASC, p.ID ASC';
        break;
      case 'name_asc':
      default:
        $order_sql = 'p.post_title ASC, p.ID ASC';
        break;
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $count_sql = "SELECT COUNT(1)
      FROM {$wpdb->posts} p
      LEFT JOIN {$lookup_table} l ON l.product_id = p.ID
      WHERE {$where_sql}";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $total = empty($params)
      ? (int) $wpdb->get_var($count_sql)
      : (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;
    if ($total_pages > 0 && $page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $list_params = $params;
    $list_params[] = $per_page;
    $list_params[] = $offset;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $list_sql = "SELECT p.ID
      FROM {$wpdb->posts} p
      LEFT JOIN {$lookup_table} l ON l.product_id = p.ID
      WHERE {$where_sql}
      ORDER BY {$order_sql}
      LIMIT %d OFFSET %d";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($list_sql, ...$list_params)));

    return [
      'ids' => $ids,
      'total' => $total,
      'page' => $page,
      'total_pages' => $total_pages,
      'per_page' => $per_page,
    ];
  }

  /** Build the exact inventory row contract expected by portal.js. */
  private function build_rows(array $product_ids) {
    $product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
    if (empty($product_ids)) return [];

    // Warm post meta for just the requested page. Parent products are warmed
    // below as soon as variation IDs are known.
    update_meta_cache('post', $product_ids);

    $products = [];
    $parent_ids = [];
    foreach ($product_ids as $product_id) {
      $product = wc_get_product($product_id);
      if (!$product || !is_a($product, 'WC_Product')) continue;
      $products[$product_id] = $product;
      if ($product->is_type('variation') && $product->get_parent_id()) {
        $parent_ids[(int) $product->get_parent_id()] = true;
      }
    }

    if (!empty($parent_ids)) {
      update_meta_cache('post', array_keys($parent_ids));
    }

    $term_object_ids = [];
    foreach ($products as $product) {
      $parent_id = $product->is_type('variation') ? (int) $product->get_parent_id() : 0;
      $term_object_ids[] = $parent_id ?: (int) $product->get_id();
    }
    $term_object_ids = array_values(array_unique(array_filter($term_object_ids)));
    if (!empty($term_object_ids)) {
      update_object_term_cache($term_object_ids, 'product');
    }

    $rows = [];
    // Preserve database page order even though products are cached by ID.
    foreach ($product_ids as $product_id) {
      if (empty($products[$product_id])) continue;
      $product = $products[$product_id];

      $parent_id = $product->is_type('variation') ? (int) $product->get_parent_id() : 0;
      $cat_source_id = $parent_id ?: (int) $product->get_id();
      $category_names = [];
      $terms = get_the_terms($cat_source_id, 'product_cat');
      if (!is_wp_error($terms) && !empty($terms)) {
        foreach ($terms as $term) $category_names[] = $term->name;
      }

      $sku = $product->get_sku();
      $name = $product->get_name();
      if ($product->is_type('variation') && $parent_id) {
        $parent = wc_get_product($parent_id);
        if ($parent) {
          $name = $parent->get_name() . ' — ' . wc_get_formatted_variation($product, true, false, true);
        }
      }

      $stock = $product->get_stock_quantity();
      $status = $product->get_stock_status();

      $rows[] = [
        'id' => (int) $product->get_id(),
        'sku' => (string) $sku,
        'name' => wp_strip_all_tags($name),
        'stock' => is_null($stock) ? '' : (string) $stock,
        'stock_raw' => is_null($stock) ? null : (int) $stock,
        'status' => (string) $status,
        'status_label' => (string) $this->portal_call('stock_label', $status),
        'categories' => implode(', ', $category_names),
      ];
    }

    return $rows;
  }

  public function ajax_get_products() {
    $this->portal_call('check_ajax_access');
    $this->portal_call('require_wc_or_die');

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
    $orderby = isset($_POST['orderby']) ? sanitize_text_field(wp_unslash($_POST['orderby'])) : 'name_asc';
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $per_page = 60;

    $allowed_orderby = ['name_asc','name_desc','sku_asc','sku_desc','stock_asc','stock_desc'];
    if (!in_array($orderby, $allowed_orderby, true)) $orderby = 'name_asc';

    $query = $this->query_inventory_page($search, $category, $orderby, $page, $per_page);
    $rows = $this->build_rows($query['ids']);

    $this->json_ok([
      'products' => $rows,
      'categories' => (array) $this->portal_call('inventory_categories'),
      'page' => (int) $query['page'],
      'per_page' => (int) $query['per_page'],
      'total' => (int) $query['total'],
      'total_pages' => (int) $query['total_pages'],
    ]);
  }

  /** Load the Phase 03 UI only on pages where the base portal script is active. */
  public function enqueue_inventory_script() {
    if (!wp_script_is('ripex-portal-js', 'enqueued')) return;

    wp_enqueue_script(
      'ripex-inventory-pagination-js',
      RIPEX_PORTAL_URL . 'assets/js/inventory-pagination.js',
      ['ripex-portal-js'],
      RIPEX_PORTAL_VERSION,
      true
    );
  }
}

add_action('plugins_loaded', function() {
  $portal = Ripex_Portal::instance();
  remove_action('wp_ajax_ripex_portal_get_products', [$portal, 'ajax_get_products']);

  $bridge = Ripex_Portal_Inventory_Performance::instance($portal);
  add_action('wp_ajax_ripex_portal_get_products', [$bridge, 'ajax_get_products']);
  add_action('wp_enqueue_scripts', [$bridge, 'enqueue_inventory_script'], 50);
}, 40);
