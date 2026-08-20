<?php
/**
 * Plugin Name: RIPEX Portal (Roles & Pedidos)
 * Description: Portal moderno (frontend) para RIPEX con roles: ripex_admin, ripex_vendedor, ripex_bodeguero. Redirección post-login, bloqueo wp-admin, listado de pedidos, detalle, creación (vendedor/admin) y exportación.
 * Version: 1.5.16
 * Author: Tibox / Wladimick
 * Text Domain: ripex-portal
 */

if (!defined('ABSPATH')) exit;

define('RIPEX_PORTAL_VERSION', '1.5.16');
define('RIPEX_PORTAL_PATH', plugin_dir_path(__FILE__));
define('RIPEX_PORTAL_URL', plugin_dir_url(__FILE__));

// Phase 08/08.1/08.2 are intentionally loaded first so an explicitly enabled
// staging capture sees the RIPEX request from plugin bootstrap onward. All are
// no-ops unless RIPEX_PORTAL_OBSERVABILITY is true.
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-profiler.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-observability-store.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-observability.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-roles-lifecycle.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-cache-performance.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-performance.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-orders-performance.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-orders-search-performance.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-inventory-performance.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-customers-performance.php';
require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal-exports-performance.php';
require_once RIPEX_PORTAL_PATH . 'includes/validaciones.php';

register_activation_hook(__FILE__, ['Ripex_Portal', 'activate']);
register_deactivation_hook(__FILE__, ['Ripex_Portal', 'deactivate']);

add_action('plugins_loaded', function() {
  Ripex_Portal::instance();
});
