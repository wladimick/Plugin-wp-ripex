<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 08/08.2 — opt-in RIPEX AJAX observability.
 *
 * Disabled by default. When RIPEX_PORTAL_OBSERVABILITY is true, RIPEX portal
 * AJAX requests emit one compact, non-sensitive performance metric at shutdown.
 * No request payload, user/customer/order/product IDs, names, RUTs, emails,
 * search terms, SQL text or filesystem paths are recorded.
 */
final class Ripex_Portal_Observability {
  private static $active = false;
  private static $emitted = false;
  private static $action = '';
  private static $started_at = 0.0;
  private static $queries_at_boot = 0;
  private static $memory_at_boot = 0;
  private static $sql_time_at_boot = null;
  private static $cache = [];

  public static function bootstrap() {
    if (self::$active) return;
    if (!self::enabled()) return;

    $action = self::requested_action();
    if ($action === '') return;

    self::$active = true;
    self::$action = $action;

    // REQUEST_TIME_FLOAT is the PHP request start, so duration_ms includes the
    // common WordPress/plugin bootstrap before RIPEX itself is loaded. Phase
    // 08.2 markers split that duration into lifecycle segments.
    self::$started_at = isset($_SERVER['REQUEST_TIME_FLOAT'])
      ? (float) $_SERVER['REQUEST_TIME_FLOAT']
      : microtime(true);
    self::$queries_at_boot = function_exists('get_num_queries') ? (int) get_num_queries() : 0;
    self::$memory_at_boot = memory_get_usage(true);
    self::$sql_time_at_boot = self::saved_query_time_ms();

    // Minimal authenticated diagnostic endpoint. It performs only the standard
    // WordPress/RIPEX bootstrap plus login/role/nonce checks and a JSON success.
    add_action('wp_ajax_ripex_portal_perf_ping', [__CLASS__, 'ajax_perf_ping']);

    // Cache probes run before the real endpoint callback. A hit is primed through
    // the matching dynamic pre_transient filter so the actual endpoint consumes
    // the already-read value instead of performing a second cache lookup.
    if ($action === 'ripex_portal_get_reports') {
      add_action('wp_ajax_ripex_portal_get_reports', [__CLASS__, 'probe_report_cache'], 1);
    } elseif ($action === 'ripex_portal_get_products') {
      add_action('wp_ajax_ripex_portal_get_products', [__CLASS__, 'probe_inventory_categories_cache'], 1);
    }

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

    // Phase 08.1 control requests are intentionally excluded so checking,
    // clearing or exporting the buffer never pollutes that buffer. The Phase
    // 08.2 perf_ping action is the intentional exception.
    if (strpos($action, 'ripex_portal_perf_') === 0 && $action !== 'ripex_portal_perf_ping') {
      return '';
    }

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

  public static function ajax_perf_ping() {
    if (!is_user_logged_in() || self::current_role() !== 'ripex_admin') {
      wp_send_json_error(['message' => 'No autorizado.'], 403);
    }
    if (!class_exists('Ripex_Portal') || !check_ajax_referer(Ripex_Portal::NONCE_ACTION, 'nonce', false)) {
      wp_send_json_error(['message' => 'Nonce inválido.'], 403);
    }

    wp_send_json_success(['ok' => true]);
  }

  private static function prime_transient($key, $component) {
    $key = (string) $key;
    if ($key === '') {
      self::mark_cache($component, 'off');
      return false;
    }

    $value = get_transient($key);
    if ($value === false) {
      self::mark_cache($component, 'miss');
      return false;
    }

    self::mark_cache($component, 'hit');
    add_filter('pre_transient_' . $key, function($pre) use ($value) {
      return $value;
    }, 1, 1);
    return true;
  }

  public static function probe_report_cache() {
    if (!class_exists('Ripex_Portal_Cache_Performance')) {
      self::mark_cache('reports_result', 'off');
      self::mark_cache('inactive_customers', 'off');
      return;
    }

    $role = self::current_role();
    if (!in_array($role, ['ripex_admin', 'ripex_vendedor'], true)) return;

    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    if (!$date_from) $date_from = gmdate('Y-m-d', strtotime('-30 days'));
    if (!$date_to) $date_to = gmdate('Y-m-d');

    $force_refresh = !empty($_POST['force_refresh']);
    if ($force_refresh) {
      self::mark_cache('reports_result', 'bypass');
      self::mark_cache('inactive_customers', 'bypass');
      return;
    }

    $user_id = get_current_user_id();
    $report_key = Ripex_Portal_Cache_Performance::key(
      'reports-result',
      [$role, (int) $user_id, $date_from, $date_to],
      ['orders', 'products', 'customers']
    );

    // A full report hit returns before the endpoint ever needs the component
    // cache, so stop here to avoid adding an observational lookup of our own.
    if (self::prime_transient($report_key, 'reports_result')) return;

    $inactive_key = Ripex_Portal_Cache_Performance::key(
      'reports-inactive-customers',
      [$role, (int) $user_id],
      ['orders', 'customers']
    );
    self::prime_transient($inactive_key, 'inactive_customers');
  }

  public static function probe_inventory_categories_cache() {
    if (!class_exists('Ripex_Portal_Cache_Performance')) {
      self::mark_cache('inventory_categories', 'off');
      return;
    }

    $key = Ripex_Portal_Cache_Performance::key(
      'inventory-categories',
      [],
      ['categories']
    );
    self::prime_transient($key, 'inventory_categories');
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

  private static function saved_query_time_ms() {
    if (!defined('SAVEQUERIES') || !SAVEQUERIES) return null;

    global $wpdb;
    if (!$wpdb || !isset($wpdb->queries) || !is_array($wpdb->queries)) return null;

    $seconds = 0.0;
    foreach ($wpdb->queries as $query) {
      if (is_array($query) && isset($query[1]) && is_numeric($query[1])) {
        $seconds += (float) $query[1];
      }
    }
    return $seconds * 1000;
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
      'queries_before_ripex_boot' => max(0, self::$queries_at_boot),
      'queries_since_ripex_boot' => max(0, $query_now - self::$queries_at_boot),
      'queries_total' => max(0, $query_now),
      'http_status' => function_exists('http_response_code') ? (int) http_response_code() : 0,
      'cache' => self::$cache,
    ];

    if (class_exists('Ripex_Portal_Profiler') && Ripex_Portal_Profiler::active()) {
      $profile = Ripex_Portal_Profiler::snapshot();
      if (is_array($profile)) $metric['profile'] = $profile;
    }

    $sql_time_now = self::saved_query_time_ms();
    if (self::$sql_time_at_boot !== null && $sql_time_now !== null) {
      $metric['sql_time_ms_since_ripex_boot'] = round(max(0, $sql_time_now - self::$sql_time_at_boot), 1);
    }

    $fatal_type = self::fatal_summary();
    if ($fatal_type !== null) $metric['fatal_type'] = $fatal_type;

    // Persist the same minimized metric in the bounded Phase 08.1 buffer.
    // Failure to persist must never break the real RIPEX AJAX response.
    if (class_exists('Ripex_Portal_Observability_Store')) {
      try {
        Ripex_Portal_Observability_Store::append($metric);
      } catch (Throwable $e) {
        // Intentionally silent: observability is best effort only.
      }
    }

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
