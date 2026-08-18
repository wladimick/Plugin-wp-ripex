<?php
/**
 * Validaciones RIPEX.
 *
 * Este archivo carga validaciones separadas para no mezclar lógica nueva
 * con el core del portal.
 */

if (!defined('ABSPATH')) exit;

require_once RIPEX_PORTAL_PATH . 'includes/validacion-rut.php';
require_once RIPEX_PORTAL_PATH . 'includes/validacion-ciudad.php';

final class RIPEX_Portal_Validaciones {
  private static $instance = null;

  public static function instance() {
    if (self::$instance === null) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    RIPEX_Portal_Validacion_Rut::instance();
    RIPEX_Portal_Validacion_Ciudad::instance();

    add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 99);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets'], 99);
  }

  public function enqueue_frontend_assets() {
    if (is_admin()) return;
    if (!$this->should_load_frontend()) return;

    wp_enqueue_style(
      'ripex-validaciones',
      RIPEX_PORTAL_URL . 'assets/css/validaciones.css',
      [],
      RIPEX_PORTAL_VERSION
    );

    wp_enqueue_script(
      'ripex-validaciones',
      RIPEX_PORTAL_URL . 'assets/js/validaciones.js',
      [],
      RIPEX_PORTAL_VERSION,
      true
    );

    $this->localize();
  }

  /**
   * Decide si las validaciones deben cargarse en el frontend.
   *
   * Por defecto solo se cargan donde tienen sentido: páginas del portal RIPEX
   * (que contienen sus shortcodes) y la página de cuenta/registro de WooCommerce.
   * Esto evita que el CSS/JS y el listado de comunas se carguen en TODAS las
   * páginas públicas del sitio.
   *
   * Se puede ajustar con el filtro 'ripex_validaciones_should_load' (devuelve bool)
   * si el formulario de registro vive en otra URL.
   */
  private function should_load_frontend() {
    $should = false;

    // Página de cuenta / registro de WooCommerce.
    if (function_exists('is_account_page') && is_account_page()) {
      $should = true;
    }

    // Páginas del portal que usan los shortcodes del plugin.
    if (!$should && is_singular() && class_exists('Ripex_Portal')) {
      $post = get_post();
      if ($post && !empty($post->post_content)) {
        if (has_shortcode($post->post_content, Ripex_Portal::SHORTCODE_PORTAL) ||
            has_shortcode($post->post_content, Ripex_Portal::SHORTCODE_CREATE)) {
          $should = true;
        }
      }
    }

    return (bool) apply_filters('ripex_validaciones_should_load', $should);
  }

  public function enqueue_admin_assets($hook) {
    if (!in_array($hook, ['user-edit.php', 'profile.php', 'user-new.php'], true)) return;

    wp_enqueue_style(
      'ripex-validaciones',
      RIPEX_PORTAL_URL . 'assets/css/validaciones.css',
      [],
      RIPEX_PORTAL_VERSION
    );

    wp_enqueue_script(
      'ripex-validaciones',
      RIPEX_PORTAL_URL . 'assets/js/validaciones.js',
      [],
      RIPEX_PORTAL_VERSION,
      true
    );

    $this->localize();
  }

  private function localize() {
    wp_localize_script('ripex-validaciones', 'RIPEX_VALIDACIONES', [
      'rutField' => '#afreg_additional_42210',
      'cityField' => '#billing_city',
      'cities' => RIPEX_Portal_Validacion_Ciudad::cities(),
      'messages' => [
        'rutRequired' => 'Ingresa un RUT.',
        'rutInvalid' => 'RUT inválido. Revisa el número y el dígito verificador.',
        'rutValid' => 'RUT válido.',
        'cityRequired' => 'Selecciona una ciudad/comuna.',
        'cityInvalid' => 'Ciudad/comuna no válida. Selecciona una opción de la lista.',
        'cityValid' => 'Ciudad/comuna válida.',
        'noCityResults' => 'No se encontraron ciudades.',
      ],
    ]);
  }
}

RIPEX_Portal_Validaciones::instance();
