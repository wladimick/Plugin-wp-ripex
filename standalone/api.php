<?php
/**
 * Phase 09 standalone read-only API prototype.
 *
 * This endpoint intentionally uses SHORTINIT so wp-config.php and wpdb are
 * available without loading plugins, themes, WooCommerce, Elementor or the
 * normal WordPress lifecycle. It performs SELECT-only access to the current
 * legacy WooCommerce order model and accepts only short-lived ripex_admin
 * tokens minted by the normal authenticated RIPEX portal.
 */

$ripex_request_started = isset($_SERVER['REQUEST_TIME_FLOAT'])
  ? (float) $_SERVER['REQUEST_TIME_FLOAT']
  : microtime(true);

define('SHORTINIT', true);
$wp_load = dirname(__DIR__, 4) . '/wp-load.php';

if (!is_file($wp_load)) {
  http_response_code(500);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['success' => false, 'error' => 'bootstrap_unavailable']);
  exit;
}

require $wp_load;
$ripex_bootstrap_ms = (microtime(true) - $ripex_request_started) * 1000;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function ripex_lite_json($status, array $payload) {
  http_response_code((int) $status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function ripex_lite_b64url_decode($value) {
  $value = strtr((string) $value, '-_', '+/');
  $pad = strlen($value) % 4;
  if ($pad) $value .= str_repeat('=', 4 - $pad);
  return base64_decode($value, true);
}

function ripex_lite_secret() {
  if (!defined('AUTH_SALT') || !defined('SECURE_AUTH_SALT')) return '';
  return hash('sha256', AUTH_SALT . '|' . SECURE_AUTH_SALT . '|ripex-standalone-orders-v1', true);
}

function ripex_lite_validate_token($token) {
  $parts = explode('.', (string) $token);
  if (count($parts) !== 2) return null;

  $secret = ripex_lite_secret();
  if ($secret === '') return null;

  list($encoded, $signature_encoded) = $parts;
  $signature = ripex_lite_b64url_decode($signature_encoded);
  if (!is_string($signature)) return null;

  $expected = hash_hmac('sha256', $encoded, $secret, true);
  if (!hash_equals($expected, $signature)) return null;

  $json = ripex_lite_b64url_decode($encoded);
  if (!is_string($json)) return null;
  $payload = json_decode($json, true);
  if (!is_array($payload)) return null;

  $now = time();
  if (($payload['aud'] ?? '') !== 'ripex-standalone-orders-v1') return null;
  if (($payload['role'] ?? '') !== 'ripex_admin') return null;
  if ((int) ($payload['uid'] ?? 0) <= 0) return null;
  if ((int) ($payload['exp'] ?? 0) < $now) return null;
  if ((int) ($payload['iat'] ?? 0) > $now + 30) return null;

  return $payload;
}

function ripex_lite_header_token() {
  return isset($_SERVER['HTTP_X_RIPEX_TOKEN']) ? trim((string) $_SERVER['HTTP_X_RIPEX_TOKEN']) : '';
}

function ripex_lite_status_label($status) {
  $labels = [
    'wc-pending' => 'Pendiente de pago',
    'wc-processing' => 'Procesando',
    'wc-on-hold' => 'En espera',
    'wc-completed' => 'Completado',
    'wc-cancelled' => 'Cancelado',
    'wc-refunded' => 'Reembolsado',
    'wc-failed' => 'Fallido',
    'wc-checkout-draft' => 'Borrador',
  ];
  return $labels[$status] ?? preg_replace('/^wc-/', '', (string) $status);
}

function ripex_lite_format_date($mysql_date) {
  $ts = strtotime((string) $mysql_date);
  return $ts ? date('d/m/y H:i', $ts) : '';
}

function ripex_lite_meta_map(array $rows, $id_field) {
  $map = [];
  foreach ($rows as $row) {
    $id = isset($row->{$id_field}) ? (int) $row->{$id_field} : 0;
    if (!$id) continue;
    $key = isset($row->meta_key) ? (string) $row->meta_key : '';
    if ($key === '') continue;
    $map[$id][$key] = isset($row->meta_value) ? (string) $row->meta_value : '';
  }
  return $map;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  ripex_lite_json(405, ['success' => false, 'error' => 'method_not_allowed']);
}

$token_payload = ripex_lite_validate_token(ripex_lite_header_token());
if (!$token_payload) {
  ripex_lite_json(401, ['success' => false, 'error' => 'invalid_or_expired_token']);
}

global $wpdb;
if (!isset($wpdb) || !is_object($wpdb)) {
  ripex_lite_json(500, ['success' => false, 'error' => 'database_unavailable']);
}

$page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
$page = max(1, min(100, $page));
$per_page = 30;
$offset = ($page - 1) * $per_page;

$posts = $wpdb->posts;
$postmeta = $wpdb->postmeta;
$users = $wpdb->users;
$usermeta = $wpdb->usermeta;
$order_items = $wpdb->prefix . 'woocommerce_order_items';

$total = (int) $wpdb->get_var(
  "SELECT COUNT(1)
   FROM {$posts}
   WHERE post_type = 'shop_order'
     AND post_status LIKE 'wc-%'"
);
$total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;

$order_rows = $wpdb->get_results(
  $wpdb->prepare(
    "SELECT ID, post_date, post_status
     FROM {$posts}
     WHERE post_type = 'shop_order'
       AND post_status LIKE 'wc-%%'
     ORDER BY post_date DESC, ID DESC
     LIMIT %d OFFSET %d",
    $per_page,
    $offset
  )
);

$order_ids = [];
$order_base = [];
foreach ((array) $order_rows as $row) {
  $id = (int) $row->ID;
  if (!$id) continue;
  $order_ids[] = $id;
  $order_base[$id] = $row;
}

if (empty($order_ids)) {
  ripex_lite_json(200, [
    'success' => true,
    'data' => [
      'orders' => [],
      'page' => $page,
      'total_pages' => $total_pages,
      'total' => $total,
      'role' => 'ripex_admin',
    ],
    'performance' => [
      'bootstrap_ms' => round($ripex_bootstrap_ms, 1),
      'request_ms' => round((microtime(true) - $ripex_request_started) * 1000, 1),
      'db_queries' => isset($wpdb->num_queries) ? (int) $wpdb->num_queries : null,
      'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 1),
    ],
  ]);
}

$id_placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
$order_meta_keys = [
  '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_email',
  '_customer_user', '_payment_method_title', '_order_total', '_order_currency',
  '_ripex_rut_empresa', '_ripex_razon_social', '_ripex_giro', '_ripex_vendedor',
  '_ripex_exported_to_bodega', '_order_number'
];
$key_placeholders = implode(',', array_fill(0, count($order_meta_keys), '%s'));
$meta_sql =
  "SELECT post_id, meta_key, meta_value
   FROM {$postmeta}
   WHERE post_id IN ({$id_placeholders})
     AND meta_key IN ({$key_placeholders})";
$meta_args = array_merge($order_ids, $order_meta_keys);
$order_meta_rows = $wpdb->get_results($wpdb->prepare($meta_sql, ...$meta_args));
$order_meta = ripex_lite_meta_map((array) $order_meta_rows, 'post_id');

$customer_ids = [];
$guest_emails = [];
foreach ($order_ids as $order_id) {
  $meta = $order_meta[$order_id] ?? [];
  $customer_id = (int) ($meta['_customer_user'] ?? 0);
  if ($customer_id > 0) {
    $customer_ids[$customer_id] = true;
  } else {
    $email = strtolower(trim((string) ($meta['_billing_email'] ?? '')));
    if ($email !== '') $guest_emails[$email] = true;
  }
}

$email_user_ids = [];
if (!empty($guest_emails)) {
  $emails = array_keys($guest_emails);
  $email_placeholders = implode(',', array_fill(0, count($emails), '%s'));
  $user_rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT ID, user_email FROM {$users} WHERE user_email IN ({$email_placeholders})",
      ...$emails
    )
  );
  foreach ((array) $user_rows as $row) {
    $email_user_ids[strtolower((string) $row->user_email)] = (int) $row->ID;
    $customer_ids[(int) $row->ID] = true;
  }
}

$user_meta = [];
if (!empty($customer_ids)) {
  $customer_id_list = array_keys($customer_ids);
  $customer_placeholders = implode(',', array_fill(0, count($customer_id_list), '%d'));
  $afreg_keys = [
    'afreg_additional_42210',
    'afreg_additional_42208',
    'afreg_additional_42209',
    'afreg_additional_42207'
  ];
  $afreg_placeholders = implode(',', array_fill(0, count($afreg_keys), '%s'));
  $user_meta_sql =
    "SELECT user_id, meta_key, meta_value
     FROM {$usermeta}
     WHERE user_id IN ({$customer_placeholders})
       AND meta_key IN ({$afreg_placeholders})";
  $user_meta_args = array_merge($customer_id_list, $afreg_keys);
  $user_meta_rows = $wpdb->get_results($wpdb->prepare($user_meta_sql, ...$user_meta_args));
  $user_meta = ripex_lite_meta_map((array) $user_meta_rows, 'user_id');
}

$shipping = [];
$shipping_sql =
  "SELECT oi.order_id, oi.order_item_name
   FROM {$order_items} oi
   INNER JOIN (
     SELECT order_id, MIN(order_item_id) AS first_item_id
     FROM {$order_items}
     WHERE order_item_type = 'shipping'
       AND order_id IN ({$id_placeholders})
     GROUP BY order_id
   ) first_shipping ON first_shipping.first_item_id = oi.order_item_id";
$shipping_rows = $wpdb->get_results($wpdb->prepare($shipping_sql, ...$order_ids));
foreach ((array) $shipping_rows as $row) {
  $shipping[(int) $row->order_id] = trim((string) $row->order_item_name);
}

$orders = [];
foreach ($order_ids as $order_id) {
  $base = $order_base[$order_id];
  $meta = $order_meta[$order_id] ?? [];

  $customer_id = (int) ($meta['_customer_user'] ?? 0);
  if ($customer_id <= 0) {
    $email = strtolower(trim((string) ($meta['_billing_email'] ?? '')));
    $customer_id = $email !== '' ? (int) ($email_user_ids[$email] ?? 0) : 0;
  }
  $um = $customer_id > 0 ? ($user_meta[$customer_id] ?? []) : [];

  $first = trim((string) ($meta['_billing_first_name'] ?? ''));
  $last = trim((string) ($meta['_billing_last_name'] ?? ''));
  $company = trim((string) ($meta['_billing_company'] ?? ''));
  $customer = trim($first . ' ' . $last);
  if ($customer === '') $customer = $company;

  $rut = trim((string) ($um['afreg_additional_42210'] ?? ''));
  if ($rut === '') $rut = trim((string) ($meta['_ripex_rut_empresa'] ?? ''));

  $razon = trim((string) ($um['afreg_additional_42208'] ?? ''));
  if ($razon === '') $razon = trim((string) ($meta['_ripex_razon_social'] ?? ''));
  if ($razon === '') $razon = $company;

  $vendedor = trim((string) ($um['afreg_additional_42207'] ?? ''));
  if ($vendedor === '') $vendedor = trim((string) ($meta['_ripex_vendedor'] ?? ''));

  $status = (string) $base->post_status;
  $number = trim((string) ($meta['_order_number'] ?? ''));
  if ($number === '') $number = (string) $order_id;

  $orders[] = [
    'id' => $order_id,
    'number' => $number,
    'date' => ripex_lite_format_date($base->post_date),
    'customer' => $customer,
    'company' => $razon,
    'rut' => $rut,
    'status' => preg_replace('/^wc-/', '', $status),
    'status_label' => ripex_lite_status_label($status),
    'payment' => trim((string) ($meta['_payment_method_title'] ?? '')),
    'vendedor' => $vendedor,
    'exported' => !empty($meta['_ripex_exported_to_bodega']),
    'total' => isset($meta['_order_total']) ? (float) $meta['_order_total'] : null,
    'currency' => trim((string) ($meta['_order_currency'] ?? '')) ?: 'CLP',
    'shipping' => $shipping[$order_id] ?? '',
  ];
}

ripex_lite_json(200, [
  'success' => true,
  'data' => [
    'orders' => $orders,
    'page' => $page,
    'total_pages' => $total_pages,
    'total' => $total,
    'role' => 'ripex_admin',
    'read_model' => 'legacy_shop_order',
  ],
  'performance' => [
    'bootstrap_ms' => round($ripex_bootstrap_ms, 1),
    'request_ms' => round((microtime(true) - $ripex_request_started) * 1000, 1),
    'db_queries' => isset($wpdb->num_queries) ? (int) $wpdb->num_queries : null,
    'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 1),
  ],
]);
