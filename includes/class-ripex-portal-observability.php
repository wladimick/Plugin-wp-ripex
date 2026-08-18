<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 08 — opt-in RIPEX AJAX observability.
 *
 * Disabled by default. When RIPEX_PORTAL_OBSERVABILITY is true, RIPEX portal
 * AJAX requests emit one compact, non-sensitive performance line at shutdown.
 * No request payload, user/customer/order/product IDs, names, RUTs or emails
 * are recorded.
 */
final class Ripex_Portal_Observability {
  private static $active = false;
  private static $emitted = false;
  private static $action = '';
  private static $started_at = 0.0;
  private static $queries_at_boot = 0;
  private static $memory_at_boot = 0;
  private static $cache = [];

  public static function bootstrap() {
    if (self::$active) return;
    if (!self::enabled()) return;

    $action = self::requested_action();
    if ($action === '') return;

    self::$active = true;
    self::$action = $action;
    self::$started_at = isset($_SERVER['REQUEST_TIME_FLOAT'])
      ? (float) $_SERVER['REQUEST_TIME_FLOAT']
      : microtime(true);
    self::$queries_at_boot = function_exists('get_num_queries') ? (int) get_num_queries() : 0;
    self::$memory_at_boot = memory_get_usage(true);

    // WordPress normally runs its shutdown action even when wp_send_json() exits.
    // The native shutdown fallback keeps the metric available for abnormal exits.
    add_action('shutdown', [__CLASS__, 'emit'], PHP_INT_MAX);
    register_shutdown_function([__CLASS__, 'emit']);
  }

  private static function enabled() {
    $enabled = defined('RIPEX_PORTAL_OBSERVABILITY') && RIPEX_PORTAL_OBSERVABILITY;
    return (bool) apply_filters('ripex_portal_observability_enabled', $enabled);
  }

  private static function requested_action() {
    if (!defined('DOING_AJAX') || !DOING_AJAX) return '';
    if (!isset($_REQUEST['action'])) return '';

    $action = sanitize_key(wp_unslash($_REQUEST['action']));
    if (strpos($action, 'ripex_portal_') !== 0) return '';
    return $action;
  }

  /**
   * Cache components may report hit/miss/bypass/off without exposing cache keys.
   */
  public static function mark_cache($component, $status) {
    if (!self::$active) return;

    $component = sanitize_key((string) $component);
    $status = sanitize_key((string) $status);
    if ($component === '') return;
    if (!in_array($status, ['hit', 'miss', 'bypass', 'off'], true)) return;

    self::$cache[$component] = $status;
  }

  private static function current_role() {
    if (!function_exists('wp_get_current_user')) return 'unknown';
    $user = wp_get_current_user();
    if (!$user || empty($user->roles) || !is_array($user->roles)) return 'anonymous';

    foreach (['ripex_admin', 'ripex_vendedor', 'ripex_bodeguero'] as $ripex_role) {
      if (in_array($ripex_role, $user->roles, true)) return $ripex_role;
    }

    return sanitize_key((string) reset($user->roles)) ?: 'other';
  }

  private static function fatal_summary() {
    $last = error_get_last();
    if (!$last || empty($last['type'])) return null;

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (defined('E_RECOVERABLE_ERROR')) $fatal_types[] = E_RECOVERABLE_ERROR;
    if (!in_array((int) $last['type'], $fatal_types, true)) return null;

    // Do not log message/file/line because third-party messages can contain data.
    return (int) $last['type'];
  }

  public static function emit() {
    if (!self::$active || self::$emitted) return;
    self::$emitted = true;

    $duration_ms = max(0, (microtime(true) - self::$started_at) * 1000);
    $peak_bytes = memory_get_peak_usage(true);
    $current_bytes = memory_get_usage(true);
    $query_now = function_exists('get_num_queries') ? (int) get_num_queries() : self::$queries_at_boot;

    $metric = [
      'action' => self::$action,
      'role' => self::current_role(),
      'duration_ms' => round($duration_ms, 1),
      'peak_memory_mib' => round($peak_bytes / 1048576, 1),
      'memory_delta_mib' => round(max(0, $current_bytes - self::$memory_at_boot) / 1048576, 1),
      'queries_since_ripex_boot' => max(0, $query_now - self::$queries_at_boot),
      'http_status' => function_exists('http_response_code') ? (int) http_response_code() : 0,
      'cache' => self::$cache,
    ];

    $fatal_type = self::fatal_summary();
    if ($fatal_type !== null) $metric['fatal_type'] = $fatal_type;

    // Best-effort browser visibility. wp_send_json() may already have committed
    // headers, so the structured log line below remains the authoritative source.
    if (!headers_sent()) {
      header('X-Ripex-Perf-Duration-Ms: ' . $metric['duration_ms']);
      header('X-Ripex-Perf-Peak-MiB: ' . $metric['peak_memory_mib']);
      header('X-Ripex-Perf-Queries: ' . $metric['queries_since_ripex_boot']);
      if (!empty(self::$cache)) {
        $parts = [];
        foreach (self::$cache as $component => $status) $parts[] = $component . '=' . $status;
        header('X-Ripex-Perf-Cache: ' . implode(',', $parts));
      }
      header('Server-Timing: ripex;dur=' . $metric['duration_ms']);
    }

    if (!defined('RIPEX_PORTAL_OBSERVABILITY_LOG') || RIPEX_PORTAL_OBSERVABILITY_LOG) {
      error_log('[RIPEX PERF] ' . wp_json_encode($metric, JSON_UNESCAPED_SLASHES));
    }
  }
}

Ripex_Portal_Observability::bootstrap();
