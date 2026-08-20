<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 09 prototype — launch a read-only standalone RIPEX orders view.
 *
 * The prototype is disabled by default and must be explicitly enabled with:
 *   define('RIPEX_PORTAL_STANDALONE_PROTOTYPE', true);
 *
 * The normal WordPress request is used only to authenticate the current
 * ripex_admin and mint a short-lived signed token. The standalone HTML and
 * read-only API then run without the theme, Elementor, WooCommerce or plugins.
 */
final class Ripex_Portal_Standalone_Prototype {
  const TOKEN_AUDIENCE = 'ripex-standalone-orders-v1';
  const TOKEN_TTL = 300;

  private static $bootstrapped = false;

  public static function bootstrap() {
    if (self::$bootstrapped) return;
    self::$bootstrapped = true;

    add_action('wp_ajax_ripex_portal_standalone_token', [__CLASS__, 'ajax_token']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_launcher'], 90);
  }

  public static function enabled() {
    $enabled = defined('RIPEX_PORTAL_STANDALONE_PROTOTYPE') && RIPEX_PORTAL_STANDALONE_PROTOTYPE;
    return (bool) apply_filters('ripex_portal_standalone_prototype_enabled', $enabled);
  }

  private static function current_role() {
    if (!is_user_logged_in()) return '';
    $user = wp_get_current_user();
    $roles = isset($user->roles) && is_array($user->roles) ? $user->roles : [];
    foreach (['ripex_admin', 'ripex_vendedor', 'ripex_bodeguero'] as $role) {
      if (in_array($role, $roles, true)) return $role;
    }
    return '';
  }

  private static function is_portal_page() {
    if (!is_singular()) return false;
    global $post;
    return $post && isset($post->post_content) && has_shortcode($post->post_content, Ripex_Portal::SHORTCODE_PORTAL);
  }

  private static function b64url_encode($value) {
    return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
  }

  private static function token_secret() {
    if (!defined('AUTH_SALT') || !defined('SECURE_AUTH_SALT')) return '';
    return hash('sha256', AUTH_SALT . '|' . SECURE_AUTH_SALT . '|' . self::TOKEN_AUDIENCE, true);
  }

  private static function issue_token($user_id, $role) {
    $secret = self::token_secret();
    if ($secret === '') return '';

    $now = time();
    $payload = [
      'aud' => self::TOKEN_AUDIENCE,
      'uid' => (int) $user_id,
      'role' => (string) $role,
      'iat' => $now,
      'exp' => $now + self::TOKEN_TTL,
      'ver' => defined('RIPEX_PORTAL_VERSION') ? RIPEX_PORTAL_VERSION : null,
    ];

    $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return '';

    $encoded = self::b64url_encode($json);
    $signature = hash_hmac('sha256', $encoded, $secret, true);
    return $encoded . '.' . self::b64url_encode($signature);
  }

  public static function enqueue_launcher() {
    if (!self::enabled()) return;
    if (!self::is_portal_page()) return;
    if (self::current_role() !== 'ripex_admin') return;

    wp_enqueue_script(
      'ripex-standalone-launch',
      RIPEX_PORTAL_URL . 'assets/js/standalone-launch.js',
      [],
      RIPEX_PORTAL_VERSION,
      true
    );

    wp_localize_script('ripex-standalone-launch', 'RIPEX_STANDALONE_PROTOTYPE', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce(Ripex_Portal::NONCE_ACTION),
      'standaloneUrl' => RIPEX_PORTAL_URL . 'standalone/index.php',
    ]);
  }

  public static function ajax_token() {
    if (!self::enabled()) {
      wp_send_json_error(['message' => 'El prototipo standalone está desactivado.'], 409);
    }
    if (!is_user_logged_in() || self::current_role() !== 'ripex_admin') {
      wp_send_json_error(['message' => 'No autorizado.'], 403);
    }
    if (!check_ajax_referer(Ripex_Portal::NONCE_ACTION, 'nonce', false)) {
      wp_send_json_error(['message' => 'Nonce inválido.'], 403);
    }

    $token = self::issue_token(get_current_user_id(), 'ripex_admin');
    if ($token === '') {
      wp_send_json_error(['message' => 'No se pudo generar el token del prototipo.'], 500);
    }

    wp_send_json_success([
      'token' => $token,
      'expires_in' => self::TOKEN_TTL,
      'standalone_url' => RIPEX_PORTAL_URL . 'standalone/index.php',
    ]);
  }
}

Ripex_Portal_Standalone_Prototype::bootstrap();
