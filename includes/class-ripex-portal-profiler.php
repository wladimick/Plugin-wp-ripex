<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 08.2 — segmented timing profiler for RIPEX AJAX requests.
 *
 * The profiler is opt-in through RIPEX_PORTAL_OBSERVABILITY and records only
 * timing/query/memory counters at fixed WordPress lifecycle markers. It never
 * records payloads, IDs, search terms, SQL text, paths or user-entered data.
 */
final class Ripex_Portal_Profiler {
  private static $active = false;
  private static $action = '';
  private static $request_started_at = 0.0;
  private static $markers = [];

  public static function bootstrap() {
    if (self::$active) return;
    if (!self::enabled()) return;

    $action = self::requested_action();
    if ($action === '') return;

    self::$active = true;
    self::$action = $action;
    self::$request_started_at = isset($_SERVER['REQUEST_TIME_FLOAT'])
      ? (float) $_SERVER['REQUEST_TIME_FLOAT']
      : microtime(true);

    self::mark('ripex_boot');

    // Lifecycle markers run at the end of their respective hooks so they include
    // the work performed by normal callbacks registered at lower priorities.
    add_action('plugins_loaded', function() { self::mark('plugins_loaded'); }, PHP_INT_MAX);
    add_action('init', function() { self::mark('init'); }, PHP_INT_MAX);
    add_action('wp_loaded', function() { self::mark('wp_loaded'); }, PHP_INT_MAX);
    add_action('admin_init', function() { self::mark('admin_init'); }, PHP_INT_MAX);

    // The RIPEX AJAX action marker runs before the real endpoint callback.
    add_action('wp_ajax_' . $action, function() { self::mark('ajax_callback'); }, 0);
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

    // Status/clear/export control traffic must not contaminate measurements.
    // perf_ping is intentionally included because it is the Phase 08.2 minimal
    // authenticated endpoint used to measure the common WordPress/RIPEX floor.
    if (strpos($action, 'ripex_portal_perf_') === 0 && $action !== 'ripex_portal_perf_ping') {
      return '';
    }

    return $action;
  }

  private static function mark($name) {
    if (!self::$active || isset(self::$markers[$name])) return;

    $now = microtime(true);
    $queries = function_exists('get_num_queries') ? (int) get_num_queries() : 0;

    self::$markers[$name] = [
      'at_ms' => round(max(0, ($now - self::$request_started_at) * 1000), 1),
      'queries_total' => $queries,
      'memory_mib' => round(memory_get_usage(true) / 1048576, 1),
    ];
  }

  public static function active() {
    return self::$active;
  }

  public static function snapshot() {
    if (!self::$active) return null;

    self::mark('shutdown');

    $markers = self::$markers;
    $timeline = ['request_start' => ['at_ms' => 0.0]] + $markers;
    $order = [
      'request_start',
      'ripex_boot',
      'plugins_loaded',
      'init',
      'wp_loaded',
      'admin_init',
      'ajax_callback',
      'shutdown',
    ];

    $segments = [];
    $query_deltas = [];
    for ($i = 0; $i < count($order) - 1; $i++) {
      $from = $order[$i];
      $to = $order[$i + 1];
      if (!isset($timeline[$from], $timeline[$to])) continue;

      $segment = $from . '_to_' . $to;
      $segments[$segment] = round(
        max(0, (float) $timeline[$to]['at_ms'] - (float) $timeline[$from]['at_ms']),
        1
      );

      if (isset($timeline[$from]['queries_total'], $timeline[$to]['queries_total'])) {
        $query_deltas[$segment] = max(
          0,
          (int) $timeline[$to]['queries_total'] - (int) $timeline[$from]['queries_total']
        );
      }
    }

    $ajax_at = isset($markers['ajax_callback']['at_ms']) ? (float) $markers['ajax_callback']['at_ms'] : null;
    $shutdown_at = isset($markers['shutdown']['at_ms']) ? (float) $markers['shutdown']['at_ms'] : null;

    return [
      'markers' => $markers,
      'segments_ms' => $segments,
      'query_deltas' => $query_deltas,
      'request_to_ajax_callback_ms' => $ajax_at !== null ? round($ajax_at, 1) : null,
      'ajax_callback_to_shutdown_ms' => ($ajax_at !== null && $shutdown_at !== null)
        ? round(max(0, $shutdown_at - $ajax_at), 1)
        : null,
      'request_total_ms' => $shutdown_at !== null ? round($shutdown_at, 1) : null,
    ];
  }
}

Ripex_Portal_Profiler::bootstrap();
