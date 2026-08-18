<?php
/**
 * Plugin Name: RIPEX Portal (Roles & Pedidos)
 * Description: Portal moderno (frontend) para RIPEX con roles: ripex_admin, ripex_vendedor, ripex_bodeguero. Redirección post-login, bloqueo wp-admin, listado de pedidos, detalle, creación (vendedor/admin) y exportación.
 * Version: 1.5.14
 * Author: Tibox / Wladimick
 * Text Domain: ripex-portal
 */

if (!defined('ABSPATH')) exit;

define('RIPEX_PORTAL_VERSION', '1.5.14');
define('RIPEX_PORTAL_PATH', plugin_dir_path(__FILE__));
define('RIPEX_PORTAL_URL', plugin_dir_url(__FILE__));

require_once RIPEX_PORTAL_PATH . 'includes/class-ripex-portal.php';
require_once RIPEX_PORTAL_PATH . 'includes/validaciones.php';

register_activation_hook(__FILE__, ['Ripex_Portal', 'activate']);
register_deactivation_hook(__FILE__, ['Ripex_Portal', 'deactivate']);

add_action('plugins_loaded', function() {
  Ripex_Portal::instance();
});
