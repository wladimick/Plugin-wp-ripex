<?php
if (!defined('ABSPATH')) exit;

/**
 * Phase 08.1/08.2 — bounded observability capture + secure JSON export.
 *
 * Metrics are kept in a non-autoload WordPress option while observability is
 * explicitly enabled. No public JSON file is written to uploads. A ripex_admin
 * can export the current capture through an authenticated AJAX action; the
 * browser then downloads the generated JSON file locally.
 */
final class Ripex_Portal_Observability_Store {
  const OPTION_BUFFER = 'ripex_portal_observability_buffer_v1';
  const SCHEMA_VERSION = 2;
  const DEFAULT_MAX_SAMPLES = 500;

  private static $bootstrapped = false;

  public static function bootstrap() {
    if (self::$bootstrapped) return;
    self::$bootstrapped = true;

    add_action('wp_ajax_ripex_portal_perf_status', [__CLASS__, 'ajax_status']);
    add_action('wp_ajax_ripex_portal_perf_clear', [__CLASS__, 'ajax_clear']);
    add_action('wp_ajax_ripex_portal_perf_export', [__CLASS__, 'ajax_export']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_ui'], 80);
  }

  public static function enabled() {
    $enabled = defined('RIPEX_PORTAL_OBSERVABILITY') && RIPEX_PORTAL_OBSERVABILITY;
    return (bool) apply_filters('ripex_portal_observability_enabled', $enabled);
  }

  private static function max_samples() {
    $max = defined('RIPEX_PORTAL_OBSERVABILITY_MAX_SAMPLES')
      ? (int) RIPEX_PORTAL_OBSERVABILITY_MAX_SAMPLES
      : self::DEFAULT_MAX_SAMPLES;
    $max = (int) apply_filters('ripex_portal_observability_max_samples', $max);
    return max(50, min(5000, $max));
  }

  private static function empty_buffer() {
    return [
      'schema_version' => self::SCHEMA_VERSION,
      'session_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ripex-', true),
      'started_at_utc' => gmdate('c'),
      'updated_at_utc' => null,
      'max_samples' => self::max_samples(),
      'total_captured' => 0,
      'dropped_samples' => 0,
      'samples' => [],
    ];
  }

  private static function load_buffer() {
    $buffer = get_option(self::OPTION_BUFFER, null);
    if (!is_array($buffer) || empty($buffer['samples']) && !isset($buffer['total_captured'])) {
      return self::empty_buffer();
    }

    $buffer['schema_version'] = self::SCHEMA_VERSION;
    $buffer['max_samples'] = self::max_samples();
    $buffer['samples'] = isset($buffer['samples']) && is_array($buffer['samples']) ? array_values($buffer['samples']) : [];
    $buffer['total_captured'] = isset($buffer['total_captured']) ? (int) $buffer['total_captured'] : count($buffer['samples']);
    $buffer['dropped_samples'] = isset($buffer['dropped_samples']) ? (int) $buffer['dropped_samples'] : 0;
    return $buffer;
  }

  private static function persist_buffer(array $buffer) {
    // Explicit false keeps the potentially-growing capture out of autoload.
    update_option(self::OPTION_BUFFER, $buffer, false);
  }

  public static function append(array $metric) {
    if (!self::enabled()) return;

    $buffer = self::load_buffer();
    $metric['captured_at_utc'] = gmdate('c');
    $buffer['samples'][] = $metric;
    $buffer['total_captured'] = (int) $buffer['total_captured'] + 1;
    $buffer['updated_at_utc'] = $metric['captured_at_utc'];

    $max = self::max_samples();
    $count = count($buffer['samples']);
    if ($count > $max) {
      $drop = $count - $max;
      $buffer['samples'] = array_slice($buffer['samples'], $drop);
      $buffer['dropped_samples'] = (int) $buffer['dropped_samples'] + $drop;
    }

    self::persist_buffer($buffer);
  }

  private static function current_role() {
    if (!function_exists('wp_get_current_user')) return '';
    $user = wp_get_current_user();
    if (!$user || empty($user->roles) || !is_array($user->roles)) return '';
    foreach (['ripex_admin', 'ripex_vendedor', 'ripex_bodeguero'] as $role) {
      if (in_array($role, $user->roles, true)) return $role;
    }
    return '';
  }

  private static function authorize_ajax() {
    if (!self::enabled()) {
      wp_send_json_error(['message' => 'La observabilidad RIPEX está desactivada.'], 409);
    }
    if (!is_user_logged_in() || self::current_role() !== 'ripex_admin') {
      wp_send_json_error(['message' => 'No autorizado.'], 403);
    }
    if (!class_exists('Ripex_Portal') || !check_ajax_referer(Ripex_Portal::NONCE_ACTION, 'nonce', false)) {
      wp_send_json_error(['message' => 'Nonce inválido.'], 403);
    }
  }

  public static function ajax_status() {
    self::authorize_ajax();
    $buffer = self::load_buffer();
    wp_send_json_success([
      'count' => count($buffer['samples']),
      'total_captured' => (int) $buffer['total_captured'],
      'dropped_samples' => (int) $buffer['dropped_samples'],
      'started_at_utc' => (string) $buffer['started_at_utc'],
      'updated_at_utc' => $buffer['updated_at_utc'],
      'max_samples' => (int) $buffer['max_samples'],
    ]);
  }

  public static function ajax_clear() {
    self::authorize_ajax();
    $buffer = self::empty_buffer();
    self::persist_buffer($buffer);
    wp_send_json_success([
      'count' => 0,
      'started_at_utc' => $buffer['started_at_utc'],
      'session_id' => $buffer['session_id'],
    ]);
  }

  private static function autoload_context() {
    if (!function_exists('wp_load_alloptions')) return null;

    $alloptions = wp_load_alloptions();
    if (!is_array($alloptions)) return null;

    // Only aggregate size/count are exported; option names and values are not.
    $serialized = serialize($alloptions);
    return [
      'count' => count($alloptions),
      'serialized_bytes' => strlen($serialized),
      'serialized_mib' => round(strlen($serialized) / 1048576, 3),
    ];
  }

  private static function opcache_context() {
    if (!function_exists('opcache_get_status')) {
      return ['available' => false];
    }

    $status = @opcache_get_status(false);
    if (!is_array($status)) {
      return ['available' => false];
    }

    $memory = isset($status['memory_usage']) && is_array($status['memory_usage']) ? $status['memory_usage'] : [];
    $interned = isset($status['interned_strings_usage']) && is_array($status['interned_strings_usage'])
      ? $status['interned_strings_usage']
      : [];
    $stats = isset($status['opcache_statistics']) && is_array($status['opcache_statistics'])
      ? $status['opcache_statistics']
      : [];

    return [
      'available' => true,
      'enabled' => !empty($status['opcache_enabled']),
      'cache_full' => !empty($status['cache_full']),
      'restart_pending' => !empty($status['restart_pending']),
      'restart_in_progress' => !empty($status['restart_in_progress']),
      'memory_mib' => [
        'used' => isset($memory['used_memory']) ? round((float) $memory['used_memory'] / 1048576, 2) : null,
        'free' => isset($memory['free_memory']) ? round((float) $memory['free_memory'] / 1048576, 2) : null,
        'wasted' => isset($memory['wasted_memory']) ? round((float) $memory['wasted_memory'] / 1048576, 2) : null,
        'wasted_pct' => isset($memory['current_wasted_percentage']) ? round((float) $memory['current_wasted_percentage'], 2) : null,
      ],
      'interned_strings_mib' => [
        'buffer' => isset($interned['buffer_size']) ? round((float) $interned['buffer_size'] / 1048576, 2) : null,
        'used' => isset($interned['used_memory']) ? round((float) $interned['used_memory'] / 1048576, 2) : null,
        'free' => isset($interned['free_memory']) ? round((float) $interned['free_memory'] / 1048576, 2) : null,
      ],
      'statistics' => [
        'num_cached_scripts' => isset($stats['num_cached_scripts']) ? (int) $stats['num_cached_scripts'] : null,
        'num_cached_keys' => isset($stats['num_cached_keys']) ? (int) $stats['num_cached_keys'] : null,
        'max_cached_keys' => isset($stats['max_cached_keys']) ? (int) $stats['max_cached_keys'] : null,
        'hits' => isset($stats['hits']) ? (int) $stats['hits'] : null,
        'misses' => isset($stats['misses']) ? (int) $stats['misses'] : null,
        'hit_rate' => isset($stats['opcache_hit_rate']) ? round((float) $stats['opcache_hit_rate'], 3) : null,
        'oom_restarts' => isset($stats['oom_restarts']) ? (int) $stats['oom_restarts'] : null,
        'hash_restarts' => isset($stats['hash_restarts']) ? (int) $stats['hash_restarts'] : null,
        'manual_restarts' => isset($stats['manual_restarts']) ? (int) $stats['manual_restarts'] : null,
      ],
    ];
  }

  private static function runtime_context() {
    global $wp_version;

    return [
      'ripex_plugin_version' => defined('RIPEX_PORTAL_VERSION') ? RIPEX_PORTAL_VERSION : null,
      'wordpress_version' => isset($wp_version) ? (string) $wp_version : null,
      'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : null,
      'php_version' => PHP_VERSION,
      'php_sapi' => PHP_SAPI,
      'wordpress_environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : null,
      'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : null,
      'external_object_cache' => function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : null,
      'savequeries' => defined('SAVEQUERIES') && SAVEQUERIES,
      'autoload_options' => self::autoload_context(),
      'opcache' => self::opcache_context(),
    ];
  }

  private static function cache_signature($cache) {
    if (!is_array($cache) || empty($cache)) return 'none';
    ksort($cache);
    $parts = [];
    foreach ($cache as $component => $status) {
      $parts[] = sanitize_key((string) $component) . '=' . sanitize_key((string) $status);
    }
    return implode(',', $parts);
  }

  private static function percentile(array $values, $ratio) {
    $values = array_values(array_filter(array_map('floatval', $values), 'is_finite'));
    if (empty($values)) return null;
    sort($values, SORT_NUMERIC);
    if (count($values) === 1) return $values[0];

    $position = (count($values) - 1) * max(0, min(1, (float) $ratio));
    $lower = (int) floor($position);
    $upper = (int) ceil($position);
    if ($lower === $upper) return $values[$lower];
    $weight = $position - $lower;
    return $values[$lower] + (($values[$upper] - $values[$lower]) * $weight);
  }

  private static function average(array $values) {
    $values = array_values(array_filter(array_map('floatval', $values), 'is_finite'));
    if (empty($values)) return null;
    return array_sum($values) / count($values);
  }

  private static function summarize(array $samples) {
    $groups = [];
    $fatal_count = 0;
    $http_error_count = 0;

    foreach ($samples as $sample) {
      if (!is_array($sample)) continue;
      $action = sanitize_key((string) ($sample['action'] ?? 'unknown')) ?: 'unknown';
      $role = sanitize_key((string) ($sample['role'] ?? 'unknown')) ?: 'unknown';
      $cache_signature = self::cache_signature($sample['cache'] ?? []);
      $key = $action . '|' . $role . '|' . $cache_signature;

      if (!isset($groups[$key])) {
        $groups[$key] = [
          'action' => $action,
          'role' => $role,
          'cache' => $cache_signature,
          'duration' => [],
          'peak_memory' => [],
          'queries' => [],
          'request_to_ajax' => [],
          'ajax_to_shutdown' => [],
          'http_errors' => 0,
          'fatals' => 0,
        ];
      }

      $groups[$key]['duration'][] = (float) ($sample['duration_ms'] ?? 0);
      $groups[$key]['peak_memory'][] = (float) ($sample['peak_memory_mib'] ?? 0);
      $groups[$key]['queries'][] = (float) ($sample['queries_since_ripex_boot'] ?? 0);

      if (isset($sample['profile']['request_to_ajax_callback_ms']) && is_numeric($sample['profile']['request_to_ajax_callback_ms'])) {
        $groups[$key]['request_to_ajax'][] = (float) $sample['profile']['request_to_ajax_callback_ms'];
      }
      if (isset($sample['profile']['ajax_callback_to_shutdown_ms']) && is_numeric($sample['profile']['ajax_callback_to_shutdown_ms'])) {
        $groups[$key]['ajax_to_shutdown'][] = (float) $sample['profile']['ajax_callback_to_shutdown_ms'];
      }

      $status = (int) ($sample['http_status'] ?? 0);
      if ($status >= 400) {
        $groups[$key]['http_errors']++;
        $http_error_count++;
      }
      if (isset($sample['fatal_type'])) {
        $groups[$key]['fatals']++;
        $fatal_count++;
      }
    }

    $rows = [];
    foreach ($groups as $group) {
      $profile = null;
      if (!empty($group['request_to_ajax']) || !empty($group['ajax_to_shutdown'])) {
        $profile = [
          'request_to_ajax_callback_ms' => empty($group['request_to_ajax']) ? null : [
            'avg' => round((float) self::average($group['request_to_ajax']), 1),
            'median' => round((float) self::percentile($group['request_to_ajax'], 0.50), 1),
            'p95' => round((float) self::percentile($group['request_to_ajax'], 0.95), 1),
            'max' => round((float) max($group['request_to_ajax']), 1),
          ],
          'ajax_callback_to_shutdown_ms' => empty($group['ajax_to_shutdown']) ? null : [
            'avg' => round((float) self::average($group['ajax_to_shutdown']), 1),
            'median' => round((float) self::percentile($group['ajax_to_shutdown'], 0.50), 1),
            'p95' => round((float) self::percentile($group['ajax_to_shutdown'], 0.95), 1),
            'max' => round((float) max($group['ajax_to_shutdown']), 1),
          ],
        ];
      }

      $row = [
        'action' => $group['action'],
        'role' => $group['role'],
        'cache' => $group['cache'],
        'samples' => count($group['duration']),
        'duration_ms' => [
          'avg' => round((float) self::average($group['duration']), 1),
          'median' => round((float) self::percentile($group['duration'], 0.50), 1),
          'p95' => round((float) self::percentile($group['duration'], 0.95), 1),
          'max' => round((float) max($group['duration']), 1),
        ],
        'peak_memory_mib' => [
          'avg' => round((float) self::average($group['peak_memory']), 1),
          'max' => round((float) max($group['peak_memory']), 1),
        ],
        'queries_since_ripex_boot' => [
          'avg' => round((float) self::average($group['queries']), 1),
          'max' => (int) max($group['queries']),
        ],
        'http_errors' => (int) $group['http_errors'],
        'fatals' => (int) $group['fatals'],
      ];
      if ($profile !== null) $row['profile'] = $profile;
      $rows[] = $row;
    }

    usort($rows, function($a, $b) {
      $cmp = strcmp($a['action'], $b['action']);
      if ($cmp !== 0) return $cmp;
      $cmp = strcmp($a['role'], $b['role']);
      if ($cmp !== 0) return $cmp;
      return strcmp($a['cache'], $b['cache']);
    });

    return [
      'retained_samples' => count($samples),
      'http_error_samples' => $http_error_count,
      'fatal_samples' => $fatal_count,
      'groups' => $rows,
    ];
  }

  public static function ajax_export() {
    self::authorize_ajax();
    $buffer = self::load_buffer();
    $samples = $buffer['samples'];

    $payload = [
      'schema_version' => self::SCHEMA_VERSION,
      'generated_at_utc' => gmdate('c'),
      'capture' => [
        'session_id' => $buffer['session_id'],
        'started_at_utc' => $buffer['started_at_utc'],
        'updated_at_utc' => $buffer['updated_at_utc'],
        'max_samples' => (int) $buffer['max_samples'],
        'total_captured' => (int) $buffer['total_captured'],
        'retained_samples' => count($samples),
        'dropped_samples' => (int) $buffer['dropped_samples'],
      ],
      'runtime' => self::runtime_context(),
      'summary' => self::summarize($samples),
      'samples' => $samples,
    ];

    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      wp_send_json_error(['message' => 'No se pudo generar el JSON de métricas.'], 500);
    }

    $stamp = gmdate('Ymd-His');
    wp_send_json_success([
      'filename' => 'ripex-performance-' . $stamp . '.json',
      'json_base64' => base64_encode($json),
      'count' => count($samples),
    ]);
  }

  public static function enqueue_ui() {
    if (!self::enabled()) return;
    if (!is_user_logged_in() || self::current_role() !== 'ripex_admin') return;
    if (!wp_script_is('ripex-portal-js', 'enqueued')) return;

    wp_enqueue_script(
      'ripex-observability-export-js',
      RIPEX_PORTAL_URL . 'assets/js/observability-export.js',
      ['ripex-portal-js'],
      RIPEX_PORTAL_VERSION,
      true
    );
  }
}

Ripex_Portal_Observability_Store::bootstrap();
