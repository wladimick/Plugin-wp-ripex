<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 07 — generation-based cache invalidation.
 *
 * Cache keys include small generation counters instead of trying to enumerate
 * and delete every transient produced by every filter/range/user combination.
 * A normal WooCommerce/user mutation bumps the relevant generation once per
 * request; old transients then become unreachable and expire naturally.
 */
final class Ripex_Portal_Cache_Performance {
  const OPTION_PREFIX = 'ripex_portal_cache_generation_';

  const REPORT_ADMIN_TTL = 600;
  const REPORT_VENDOR_TTL = 300;
  const REPORT_INACTIVE_TTL = 600;
  const INVENTORY_CATEGORIES_TTL = 3600;

  private static $bootstrapped = false;
  private static $bumped = [];

  public static function bootstrap() {
    if (self::$bootstrapped) return;
    self::$bootstrapped = true;

    // Orders: both CRUD/data-store hooks and legacy post saves are covered.
    add_action('woocommerce_new_order', [__CLASS__, 'invalidate_orders']);
    add_action('woocommerce_update_order', [__CLASS__, 'invalidate_orders']);
    add_action('woocommerce_order_status_changed', [__CLASS__, 'invalidate_orders'], 10, 4);
    add_action('woocommerce_refund_created', [__CLASS__, 'invalidate_orders']);
    add_action('woocommerce_refund_deleted', [__CLASS__, 'invalidate_orders']);
    add_action('save_post_shop_order', [__CLASS__, 'invalidate_orders'], 10, 3);

    // Product/stock changes affect Reportes stock/no-movement calculations.
    add_action('woocommerce_new_product', [__CLASS__, 'invalidate_products']);
    add_action('woocommerce_update_product', [__CLASS__, 'invalidate_products']);
    add_action('woocommerce_product_set_stock', [__CLASS__, 'invalidate_products']);
    add_action('woocommerce_variation_set_stock', [__CLASS__, 'invalidate_products']);
    add_action('save_post_product', [__CLASS__, 'invalidate_products'], 10, 3);
    add_action('save_post_product_variation', [__CLASS__, 'invalidate_products'], 10, 3);

    // Inventory category choices use hide_empty=false, so only taxonomy
    // create/edit/delete needs to invalidate that facet cache.
    add_action('created_product_cat', [__CLASS__, 'invalidate_categories']);
    add_action('edited_product_cat', [__CLASS__, 'invalidate_categories']);
    add_action('delete_product_cat', [__CLASS__, 'invalidate_categories']);

    // Customer/commercial metadata affects customer lists and report labels.
    add_action('profile_update', [__CLASS__, 'invalidate_customers'], 10, 2);
    add_action('user_register', [__CLASS__, 'invalidate_customers']);
    add_action('deleted_user', [__CLASS__, 'invalidate_customers']);
    add_action('added_user_meta', [__CLASS__, 'maybe_invalidate_customer_meta'], 10, 4);
    add_action('updated_user_meta', [__CLASS__, 'maybe_invalidate_customer_meta'], 10, 4);
    add_action('deleted_user_meta', [__CLASS__, 'maybe_invalidate_customer_meta'], 10, 4);
  }

  public static function generation($domain) {
    $domain = sanitize_key((string) $domain);
    if (!in_array($domain, ['orders', 'products', 'customers', 'categories'], true)) return 1;
    return max(1, (int) get_option(self::OPTION_PREFIX . $domain, 1));
  }

  public static function key($namespace, array $parts = [], array $domains = []) {
    $payload = [sanitize_key((string) $namespace), $parts];
    foreach ($domains as $domain) {
      $domain = sanitize_key((string) $domain);
      $payload[] = $domain . ':' . self::generation($domain);
    }
    return 'ripex_pc_' . md5(wp_json_encode($payload));
  }

  private static function bump($domain) {
    $domain = sanitize_key((string) $domain);
    if (!in_array($domain, ['orders', 'products', 'customers', 'categories'], true)) return;
    if (!empty(self::$bumped[$domain])) return;
    self::$bumped[$domain] = true;

    $option = self::OPTION_PREFIX . $domain;
    $next = self::generation($domain) + 1;
    update_option($option, $next, false);
  }

  public static function invalidate_orders() {
    self::bump('orders');
  }

  public static function invalidate_products() {
    self::bump('products');
  }

  public static function invalidate_customers() {
    self::bump('customers');
  }

  public static function invalidate_categories() {
    self::bump('categories');
  }

  public static function maybe_invalidate_customer_meta($meta_id, $user_id, $meta_key, $meta_value = null) {
    $relevant = [
      'afreg_additional_42210', // RUT
      'afreg_additional_42208', // razón social
      'afreg_additional_42209', // giro
      'afreg_additional_42207', // vendedor
      'afreg_additional_46135', // crédito
      'ripex_vendor_label',
      'nickname',
      'first_name',
      'last_name',
      'billing_first_name',
      'billing_last_name',
      'billing_company',
      'billing_phone',
      'billing_city',
      'shipping_city',
      'billing_state',
      'shipping_state',
    ];

    if (in_array((string) $meta_key, $relevant, true)) {
      self::bump('customers');
    }
  }
}

Ripex_Portal_Cache_Performance::bootstrap();
