<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 05 — RIPEX roles/capabilities lifecycle.
 *
 * Ripex_Portal 1.5.14 reconciles all role capabilities from its constructor,
 * which means every frontend/AJAX/admin request repeats dozens of add_cap()
 * calls and shop_manager capability copying even when nothing changed.
 *
 * This bridge keeps the existing portal hook contract but creates the portal
 * singleton without invoking that constructor. Role capabilities are migrated
 * once per capability signature (and repaired if critical roles/caps are
 * missing), while normal requests only perform cheap in-memory checks.
 */
final class Ripex_Portal_Roles_Lifecycle {
  const OPTION_SCHEMA = 'ripex_portal_roles_schema_signature';
  const SCHEMA_VERSION = '2026-08-18-v1';

  private static $bootstrapped = false;

  public static function bootstrap() {
    if (self::$bootstrapped) return;
    self::$bootstrapped = true;

    $portal = self::new_portal_without_constructor();
    if (!$portal) {
      // Conservative fallback: preserve legacy behavior if Reflection is not
      // available for any reason rather than leaving the plugin uninitialized.
      Ripex_Portal::instance();
      return;
    }

    self::install_portal_singleton($portal);
    self::register_portal_hooks($portal);

    // WooCommerce/other plugins have loaded by this point, so shop_manager's
    // final capability set is available for the signature comparison.
    add_action('plugins_loaded', [__CLASS__, 'maybe_migrate_roles'], 1);

    // The main plugin activation callback still calls create_roles() and
    // ensure_roles_caps(). Marking the signature here prevents a duplicate
    // migration on the request immediately following activation.
    if (defined('RIPEX_PORTAL_PATH')) {
      register_activation_hook(
        RIPEX_PORTAL_PATH . 'ripex-portal.php',
        [__CLASS__, 'mark_schema_current']
      );
    }
  }

  private static function new_portal_without_constructor() {
    if (!class_exists('ReflectionClass')) return null;

    try {
      $reflection = new ReflectionClass('Ripex_Portal');
      return $reflection->newInstanceWithoutConstructor();
    } catch (Throwable $e) {
      return null;
    }
  }

  private static function install_portal_singleton($portal) {
    $reflection = new ReflectionClass('Ripex_Portal');
    $property = $reflection->getProperty('instance');
    if (method_exists($property, 'setAccessible')) $property->setAccessible(true);
    $property->setValue(null, $portal);
  }

  /**
   * Exact hook contract from the Ripex_Portal 1.5.15 constructor, excluding
   * only self::ensure_roles_caps(). Keep this list in sync if the constructor
   * gains or loses hooks in future versions.
   */
  private static function register_portal_hooks($portal) {
    add_shortcode(Ripex_Portal::SHORTCODE_PORTAL, [$portal, 'render_portal_shortcode']);
    add_shortcode(Ripex_Portal::SHORTCODE_CREATE, [$portal, 'render_create_shortcode']);

    add_action('wp_enqueue_scripts', [$portal, 'enqueue_assets']);
    add_action('wp_enqueue_scripts', [$portal, 'enqueue_checkout_credit_fallback_assets'], 30);

    add_filter('login_redirect', [$portal, 'login_redirect'], 10, 3);
    add_action('admin_init', [$portal, 'block_wp_admin_for_portal_roles']);
    add_filter('show_admin_bar', [$portal, 'maybe_hide_admin_bar']);
    add_filter('map_meta_cap', [$portal, 'map_vendor_order_meta_caps'], 10, 4);
    add_action('admin_bar_menu', [$portal, 'add_admin_bar_portal_link'], 100);
    add_action('admin_head', [$portal, 'output_admin_bar_portal_styles']);
    add_action('wp_head', [$portal, 'output_admin_bar_portal_styles']);

    add_filter('woocommerce_email_enabled_new_order', [$portal, 'maybe_disable_portal_order_emails'], 10, 2);
    add_filter('woocommerce_email_enabled_customer_processing_order', [$portal, 'maybe_disable_portal_order_emails'], 10, 2);
    add_filter('woocommerce_email_enabled_customer_on_hold_order', [$portal, 'maybe_disable_portal_order_emails'], 10, 2);
    add_filter('woocommerce_email_enabled_customer_invoice', [$portal, 'maybe_disable_portal_order_emails'], 10, 2);

    add_filter('woocommerce_order_item_shipping_get_method_title', [$portal, 'clean_transport_label'], 20, 2);
    add_filter('woocommerce_shipping_rate_label', [$portal, 'clean_transport_label'], 20, 2);
    add_filter('woocommerce_cart_shipping_method_full_label', [$portal, 'clean_transport_label_from_html'], 20, 2);

    add_filter('option_woocommerce_cheque_settings', [$portal, 'maybe_enable_cheque_gateway_settings_for_credit'], 20, 1);
    add_filter('woocommerce_payment_gateways', [$portal, 'ensure_cheque_gateway_registered'], 9999, 1);
    add_filter('woocommerce_available_payment_gateways', [$portal, 'filter_credit_payment_gateway'], 9999, 1);
    add_filter('woocommerce_gateway_title', [$portal, 'rename_cheque_gateway_title'], 30, 2);
    add_filter('woocommerce_gateway_description', [$portal, 'rename_cheque_gateway_description'], 30, 2);
    add_action('woocommerce_checkout_create_order', [$portal, 'set_checkout_credit_order_title'], 30, 2);

    add_action('pre_get_posts', [$portal, 'refine_public_product_code_search'], 20);

    add_action('wp_ajax_ripex_portal_get_orders', [$portal, 'ajax_get_orders']);
    add_action('wp_ajax_ripex_portal_get_order', [$portal, 'ajax_get_order']);
    add_action('wp_ajax_ripex_portal_get_products', [$portal, 'ajax_get_products']);
    add_action('wp_ajax_ripex_portal_get_reports', [$portal, 'ajax_get_reports']);
    add_action('wp_ajax_ripex_portal_get_customers', [$portal, 'ajax_get_customers']);
    add_action('wp_ajax_ripex_portal_get_customer_history', [$portal, 'ajax_get_customer_history']);
    add_action('wp_ajax_ripex_portal_get_carts', [$portal, 'ajax_get_carts']);
    add_action('wp_ajax_ripex_portal_close_cart', [$portal, 'ajax_close_cart']);
    add_action('wp_ajax_ripex_portal_export_order', [$portal, 'ajax_export_order']);
    add_action('wp_ajax_ripex_portal_export_orders_by_date', [$portal, 'ajax_export_orders_by_date']);
    add_action('wp_ajax_ripex_portal_export_selected_orders', [$portal, 'ajax_export_selected_orders']);
    add_action('wp_ajax_ripex_portal_export_my_orders', [$portal, 'ajax_export_my_orders']);

    add_action('wp_ajax_ripex_portal_get_shipping_methods', [$portal, 'ajax_get_shipping_methods']);
    add_action('wp_ajax_ripex_portal_search_products', [$portal, 'ajax_search_products']);
    add_action('wp_ajax_ripex_portal_search_customers', [$portal, 'ajax_search_customers']);
    add_action('wp_ajax_ripex_portal_get_customer', [$portal, 'ajax_get_customer']);
    add_action('wp_ajax_ripex_portal_create_order', [$portal, 'ajax_create_order']);
    add_action('wp_ajax_ripex_portal_update_order', [$portal, 'ajax_update_order']);

    add_action('admin_menu', [$portal, 'register_stock_repair_page']);
  }

  /**
   * Include the current shop_manager grants in the signature because RIPEX
   * admin historically inherits them. If WooCommerce (or another plugin)
   * changes that role, the next request performs one reconciliation.
   */
  private static function desired_signature() {
    $shop_caps = [];
    $shop_manager = get_role('shop_manager');
    if ($shop_manager && is_array($shop_manager->capabilities)) {
      foreach ($shop_manager->capabilities as $cap => $grant) {
        if ($grant) $shop_caps[] = (string) $cap;
      }
    }
    sort($shop_caps, SORT_STRING);

    return implode('|', [
      self::SCHEMA_VERSION,
      'plugin:' . (defined('RIPEX_PORTAL_VERSION') ? RIPEX_PORTAL_VERSION : 'unknown'),
      'shop:' . md5(implode('|', $shop_caps)),
    ]);
  }

  /** Cheap safety check so deleted/corrupted critical roles self-heal. */
  private static function roles_need_repair() {
    $admin = get_role('ripex_admin');
    $vendor = get_role('ripex_vendedor');
    $warehouse = get_role('ripex_bodeguero');

    if (!$admin || !$vendor || !$warehouse) return true;

    $admin_required = ['read', 'manage_woocommerce', 'edit_shop_orders', 'edit_products', 'list_users'];
    foreach ($admin_required as $cap) {
      if (empty($admin->capabilities[$cap])) return true;
    }

    $vendor_required = ['read', 'edit_shop_orders', 'edit_shop_order', 'read_shop_order'];
    foreach ($vendor_required as $cap) {
      if (empty($vendor->capabilities[$cap])) return true;
    }

    return empty($warehouse->capabilities['read']);
  }

  public static function maybe_migrate_roles() {
    $desired = self::desired_signature();
    $current = (string) get_option(self::OPTION_SCHEMA, '');

    if ($current === $desired && !self::roles_need_repair()) return;

    Ripex_Portal::ensure_roles_caps();
    update_option(self::OPTION_SCHEMA, $desired);
  }

  public static function mark_schema_current() {
    update_option(self::OPTION_SCHEMA, self::desired_signature());
  }
}

// Bootstrap immediately after the original class is loaded, before any
// plugins_loaded callback can request the normal singleton constructor.
Ripex_Portal_Roles_Lifecycle::bootstrap();
