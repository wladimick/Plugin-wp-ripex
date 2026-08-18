<?php
if (!defined('ABSPATH')) exit;

class Ripex_Portal {

  private static $instance = null;
  private static $suspend_portal_order_emails = false;

  // Caché por request: evita recalcular las labels del mismo vendedor en cada
  // iteración de los bucles de pedidos/clientes (patrón N+1).
  private $vendor_labels_cache = [];

  const OPTION_PORTAL_PAGE_ID = 'ripex_portal_page_id';
  const OPTION_CREATE_PAGE_ID = 'ripex_portal_create_page_id';

  const SHORTCODE_PORTAL = 'ripex_portal';
  const SHORTCODE_CREATE = 'ripex_portal_create_order';

  const NONCE_ACTION = 'ripex_portal_nonce_action';

  public static function instance() {
    if (self::$instance === null) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    self::ensure_roles_caps();
    add_shortcode(self::SHORTCODE_PORTAL, [$this, 'render_portal_shortcode']);
    add_shortcode(self::SHORTCODE_CREATE, [$this, 'render_create_shortcode']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_credit_fallback_assets'], 30);

    add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);
    add_action('admin_init', [$this, 'block_wp_admin_for_portal_roles']);
    add_filter('show_admin_bar', [$this, 'maybe_hide_admin_bar']);
    add_filter('map_meta_cap', [$this, 'map_vendor_order_meta_caps'], 10, 4);
    add_action('admin_bar_menu', [$this, 'add_admin_bar_portal_link'], 100);
    add_action('admin_head', [$this, 'output_admin_bar_portal_styles']);
    add_action('wp_head', [$this, 'output_admin_bar_portal_styles']);
    // Ciudad ahora se valida con includes/validacion-ciudad.php y assets/js/validaciones.js.
    add_filter('woocommerce_email_enabled_new_order', [$this, 'maybe_disable_portal_order_emails'], 10, 2);
    add_filter('woocommerce_email_enabled_customer_processing_order', [$this, 'maybe_disable_portal_order_emails'], 10, 2);
    add_filter('woocommerce_email_enabled_customer_on_hold_order', [$this, 'maybe_disable_portal_order_emails'], 10, 2);
    add_filter('woocommerce_email_enabled_customer_invoice', [$this, 'maybe_disable_portal_order_emails'], 10, 2);

    // Transport label cleanup
    add_filter('woocommerce_order_item_shipping_get_method_title', [$this, 'clean_transport_label'], 20, 2);
    add_filter('woocommerce_shipping_rate_label', [$this, 'clean_transport_label'], 20, 2);
    add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'clean_transport_label_from_html'], 20, 2);

    // Pago a crédito en checkout: usa gateway cheque, visible solo con plazo crédito activo.
    // También forzamos la disponibilidad runtime de cheque para usuarios con crédito,
    // incluso si el método está desactivado globalmente en WooCommerce.
    add_filter('option_woocommerce_cheque_settings', [$this, 'maybe_enable_cheque_gateway_settings_for_credit'], 20, 1);
    add_filter('woocommerce_payment_gateways', [$this, 'ensure_cheque_gateway_registered'], 9999, 1);
    add_filter('woocommerce_available_payment_gateways', [$this, 'filter_credit_payment_gateway'], 9999, 1);
    add_filter('woocommerce_gateway_title', [$this, 'rename_cheque_gateway_title'], 30, 2);
    add_filter('woocommerce_gateway_description', [$this, 'rename_cheque_gateway_description'], 30, 2);
    add_action('woocommerce_checkout_create_order', [$this, 'set_checkout_credit_order_title'], 30, 2);

    // Búsqueda pública de productos: para códigos tipo MAN/OEM con slash,
    // priorizar coincidencias exactas por SKU, título o metas de producto.
    add_action('pre_get_posts', [$this, 'refine_public_product_code_search'], 20);

    // AJAX
    add_action('wp_ajax_ripex_portal_get_orders', [$this, 'ajax_get_orders']);
    add_action('wp_ajax_ripex_portal_get_order',  [$this, 'ajax_get_order']);
    add_action('wp_ajax_ripex_portal_get_products', [$this, 'ajax_get_products']);
    add_action('wp_ajax_ripex_portal_get_reports', [$this, 'ajax_get_reports']);
    add_action('wp_ajax_ripex_portal_get_customers', [$this, 'ajax_get_customers']);
    add_action('wp_ajax_ripex_portal_get_customer_history', [$this, 'ajax_get_customer_history']);
    add_action('wp_ajax_ripex_portal_get_carts', [$this, 'ajax_get_carts']);
    add_action('wp_ajax_ripex_portal_close_cart', [$this, 'ajax_close_cart']);
    add_action('wp_ajax_ripex_portal_export_order', [$this, 'ajax_export_order']);
    add_action('wp_ajax_ripex_portal_export_orders_by_date', [$this, 'ajax_export_orders_by_date']);
    add_action('wp_ajax_ripex_portal_export_selected_orders', [$this, 'ajax_export_selected_orders']);
    add_action('wp_ajax_ripex_portal_export_my_orders', [$this, 'ajax_export_my_orders']);

    // Create order
    add_action('wp_ajax_ripex_portal_get_shipping_methods', [$this, 'ajax_get_shipping_methods']);
    add_action('wp_ajax_ripex_portal_search_products', [$this, 'ajax_search_products']);
    add_action('wp_ajax_ripex_portal_search_customers', [$this, 'ajax_search_customers']);
    add_action('wp_ajax_ripex_portal_get_customer', [$this, 'ajax_get_customer']);
    add_action('wp_ajax_ripex_portal_create_order', [$this, 'ajax_create_order']);
    add_action('wp_ajax_ripex_portal_update_order', [$this, 'ajax_update_order']);

    // Herramienta admin de emergencia para revisar/regularizar stock de pedidos portal.
    add_action('admin_menu', [$this, 'register_stock_repair_page']);
  }

  /* =========================
   * Activation / Roles / Pages
   * ========================= */
  public static function activate() {
    self::create_roles();
    self::maybe_create_pages();
    flush_rewrite_rules();
  }
  public static function deactivate() { flush_rewrite_rules(); }

  public static function create_roles() {
    if (!get_role('ripex_admin')) add_role('ripex_admin', 'Ripex Admin', ['read' => true]);
    if (!get_role('ripex_vendedor')) add_role('ripex_vendedor', 'Ripex Vendedor', ['read' => true]);
    if (!get_role('ripex_bodeguero')) add_role('ripex_bodeguero', 'Ripex Bodeguero', ['read' => true]);
    self::ensure_roles_caps();
  }

  public static function ensure_roles_caps() {
    $admin_role = get_role('ripex_admin');
    if (!$admin_role) $admin_role = add_role('ripex_admin', 'Ripex Admin', ['read' => true]);

    if ($admin_role) {
      $caps = [
        'read',
        'edit_shop_orders',
        'edit_others_shop_orders',
        'edit_private_shop_orders',
        'edit_published_shop_orders',
        'publish_shop_orders',
        'delete_shop_orders',
        'delete_private_shop_orders',
        'delete_published_shop_orders',
        'delete_others_shop_orders',
        'read_private_shop_orders',
        'edit_shop_order',
        'read_shop_order',
        'delete_shop_order',
        'manage_woocommerce',
        'view_woocommerce_reports',
        'edit_products',
        'edit_others_products',
        'edit_private_products',
        'edit_published_products',
        'publish_products',
        'delete_products',
        'delete_private_products',
        'delete_published_products',
        'delete_others_products',
        'read_private_products',
        'edit_product',
        'read_product',
        'delete_product',
        'list_users',
      ];
      foreach ($caps as $cap) $admin_role->add_cap($cap);

      $shop_manager = get_role('shop_manager');
      if ($shop_manager && !empty($shop_manager->capabilities) && is_array($shop_manager->capabilities)) {
        foreach ($shop_manager->capabilities as $cap => $grant) {
          if ($grant) $admin_role->add_cap($cap);
        }
      }
    }

    $vendor_role = get_role('ripex_vendedor');
    if (!$vendor_role) $vendor_role = add_role('ripex_vendedor', 'Ripex Vendedor', ['read' => true]);
    if ($vendor_role) {
      $caps = [
        'read',
        'edit_shop_orders',
        'edit_published_shop_orders',
        'edit_private_shop_orders',
        'read_private_shop_orders',
        'edit_shop_order',
        'read_shop_order',
      ];
      foreach ($caps as $cap) $vendor_role->add_cap($cap);
    }

    $bodega_role = get_role('ripex_bodeguero');
    if (!$bodega_role) $bodega_role = add_role('ripex_bodeguero', 'Ripex Bodeguero', ['read' => true]);
    if ($bodega_role) $bodega_role->add_cap('read');
  }

  public static function maybe_create_pages() {
    // Portal page
    $portal_id = (int) get_option(self::OPTION_PORTAL_PAGE_ID, 0);
    if ($portal_id && get_post($portal_id)) {
      wp_update_post(['ID'=>$portal_id,'post_name'=>'portal-pedidos','post_title'=>'Portal Pedidos']);
    } else {
      $by_slug = get_page_by_path('portal-pedidos');
      if ($by_slug && $by_slug->ID) {
        $portal_id = (int)$by_slug->ID;
        update_option(self::OPTION_PORTAL_PAGE_ID, $portal_id);
      } else {
        $portal_id = wp_insert_post([
          'post_title'   => 'Portal Pedidos',
          'post_name'    => 'portal-pedidos',
          'post_status'  => 'publish',
          'post_type'    => 'page',
          'post_content' => '[' . self::SHORTCODE_PORTAL . ']',
        ]);
        if (!is_wp_error($portal_id) && $portal_id) update_option(self::OPTION_PORTAL_PAGE_ID, (int)$portal_id);
      }
    }

    // Create order child page
    $create_id = (int) get_option(self::OPTION_CREATE_PAGE_ID, 0);
    if ($create_id && get_post($create_id)) {
      wp_update_post(['ID'=>$create_id,'post_name'=>'crear-pedido','post_title'=>'Crear Pedido','post_parent'=>(int)$portal_id]);
      return;
    }

    // find by path (child)
    $by_path = get_page_by_path('portal-pedidos/crear-pedido');
    if ($by_path && $by_path->ID) {
      update_option(self::OPTION_CREATE_PAGE_ID, (int)$by_path->ID);
      wp_update_post(['ID'=>(int)$by_path->ID,'post_parent'=>(int)$portal_id]);
      return;
    }

    // create child
    if ($portal_id && get_post($portal_id)) {
      $create_id = wp_insert_post([
        'post_title'   => 'Crear Pedido',
        'post_name'    => 'crear-pedido',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => (int)$portal_id,
        'post_content' => '[' . self::SHORTCODE_CREATE . ']',
      ]);
      if (!is_wp_error($create_id) && $create_id) update_option(self::OPTION_CREATE_PAGE_ID, (int)$create_id);
    }
  }

  /* =========================
   * Helpers
   * ========================= */
  private function current_user_role_key() {
    if (!is_user_logged_in()) return '';
    $u = wp_get_current_user();
    $roles = isset($u->roles) ? (array)$u->roles : [];
    foreach (['ripex_admin','ripex_vendedor','ripex_bodeguero'] as $r) {
      if (in_array($r, $roles, true)) return $r;
    }
    return !empty($roles) ? (string)$roles[0] : '';
  }

  private function is_portal_role() {
    return in_array($this->current_user_role_key(), ['ripex_admin','ripex_vendedor','ripex_bodeguero'], true);
  }

  private function can_create_order() {
    return in_array($this->current_user_role_key(), ['ripex_admin','ripex_vendedor'], true);
  }

  private function portal_url() {
    $page_id = (int) get_option(self::OPTION_PORTAL_PAGE_ID, 0);
    if ($page_id && get_post($page_id)) return get_permalink($page_id);
    return home_url('/portal-pedidos/');
  }

  private function create_url() {
    $page_id = (int) get_option(self::OPTION_CREATE_PAGE_ID, 0);
    if ($page_id && get_post($page_id)) return get_permalink($page_id);
    return home_url('/portal-pedidos/crear-pedido/');
  }

  private function require_wc_or_die() {
    if (!class_exists('WooCommerce')) wp_send_json_error(['message' => 'WooCommerce no está activo.']);
  }

  private function json_ok($data) { wp_send_json_success($data); }
  private function json_err($msg, $extra = []) { wp_send_json_error(array_merge(['message' => $msg], $extra)); }

  private function check_ajax_access() {
    if (!is_user_logged_in()) $this->json_err('No autorizado.');
    if (!$this->is_portal_role()) $this->json_err('No autorizado (rol).');
    if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) $this->json_err('Nonce inválido.');
  }

  private function clean_address_lines($formatted) {
    $s = (string)$formatted;
    if ($s === '') return [];
    $s = preg_replace('/<br\\s*\\/?>/i', "\n", $s);
    $s = wp_strip_all_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = preg_split('/\r?\n/', $s);
    $lines = array_values(array_filter(array_map('trim', $lines)));
    return $lines;
  }

  private function join_address_for_csv($lines) {
    if (!is_array($lines)) return '';
    return implode(', ', array_map(function($v){ return trim((string)$v); }, $lines));
  }

  private function get_customer_user_id_from_order($order) {
    $cid = (int) $order->get_customer_id();
    if ($cid) return $cid;
    $email = $order->get_billing_email();
    if ($email) {
      $u = get_user_by('email', $email);
      if ($u && !empty($u->ID)) return (int)$u->ID;
    }
    return 0;
  }

  private function order_meta_text($order, $key) {
    $v = $order ? $order->get_meta($key) : '';
    return is_string($v) ? trim($v) : '';
  }


  private function normalize_vendor_label($value) {
    $value = is_string($value) ? $value : '';
    $value = trim(wp_strip_all_tags($value));
    if ($value === '') return '';
    $value = remove_accents($value);
    $value = strtolower(preg_replace('/\s+/', ' ', $value));
    return trim($value);
  }

  private function get_vendor_match_labels($vendor_user_id) {
    $vendor_user_id = (int) $vendor_user_id;
    if (isset($this->vendor_labels_cache[$vendor_user_id])) {
      return $this->vendor_labels_cache[$vendor_user_id];
    }

    $vendor = get_user_by('id', $vendor_user_id);
    if (!$vendor) {
      $this->vendor_labels_cache[$vendor_user_id] = [];
      return [];
    }

    $labels = [];
    $full_name = trim((string) $vendor->first_name . ' ' . (string) $vendor->last_name);

    $candidates = [
      $vendor->display_name ?? '',
      $vendor->user_email ?? '',
      $vendor->user_login ?? '',
      $full_name,
      get_user_meta($vendor_user_id, 'nickname', true),
      get_user_meta($vendor_user_id, 'ripex_vendor_label', true),
    ];

    foreach ($candidates as $candidate) {
      $norm = $this->normalize_vendor_label($candidate);
      if ($norm !== '') $labels[$norm] = true;
    }

    $result = array_keys($labels);
    $this->vendor_labels_cache[$vendor_user_id] = $result;
    return $result;
  }

  private function customer_assigned_to_vendor($customer_id, $vendor_user_id) {
    $assigned = get_user_meta($customer_id, 'afreg_additional_42207', true);
    $assigned_norm = $this->normalize_vendor_label($assigned);
    if ($assigned_norm === '') return false;

    $vendor_labels = $this->get_vendor_match_labels($vendor_user_id);
    if (empty($vendor_labels)) return false;

    return in_array($assigned_norm, $vendor_labels, true);
  }


  private function chile_cities() {
    return [
      'Arica','Iquique','Alto Hospicio','Antofagasta','Calama','Copiapó','Vallenar','La Serena','Coquimbo','Ovalle','Valparaíso','Viña del Mar','Quilpué','Villa Alemana','San Antonio','Santiago','Providencia','Las Condes','Ñuñoa','Maipú','Puente Alto','La Florida','San Bernardo','Rancagua','San Fernando','Curicó','Talca','Linares','Chillán','Los Ángeles','Concepción','Talcahuano','Coronel','Temuco','Padre Las Casas','Villarrica','Valdivia','Osorno','Puerto Montt','Castro','Coyhaique','Punta Arenas','Santa Cruz','Rengo','Molina','Teno','Maule','Romeral','San Javier','Constitución','Parral','Cauquenes','San Clemente','Longaví','Colbún','Retiro','Chanco','Pelluhue','Hualañé','Licantén','Vichuquén','Sagrada Familia','Rauco','Talcahuano','Penco','Tomé','Lota','Arauco','Cañete','Nacimiento','Mulchén','Angol','Victoria','Lautaro','Nueva Imperial','Pitrufquén','Freire','Loncoche','La Unión','Río Bueno','Frutillar','Ancud','Quellón'
    ];
  }

  public function output_city_datalist_script() {
    if (is_admin()) return;
    $cities = $this->chile_cities();
    if (empty($cities)) return;
    ?>
    <datalist id="rp-city-list-global">
      <?php foreach ($cities as $city): ?><option value="<?php echo esc_attr($city); ?>"></option><?php endforeach; ?>
    </datalist>
    <script>
    (function(){
      function attachRipexCityList(){
        var inputs = Array.prototype.slice.call(document.querySelectorAll('input'));
        inputs.forEach(function(input){
          var name = (input.getAttribute('name') || '').toLowerCase();
          var id = (input.id || '').toLowerCase();
          var ph = (input.getAttribute('placeholder') || '').toLowerCase();
          if (id === 'rp-billing-city' || name.indexOf('city') !== -1 || id.indexOf('city') !== -1 || ph.indexOf('ciudad') !== -1 || ph.indexOf('comuna') !== -1) {
            input.setAttribute('list', 'rp-city-list-global');
          }
        });
      }
      if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attachRipexCityList); else attachRipexCityList();
      document.addEventListener('change', attachRipexCityList, true);
    })();
    </script>
    <?php
  }

  private function user_city_value($user_id) {
    $keys = ['billing_city', 'shipping_city', 'afreg_additional_city', 'city'];
    foreach ($keys as $key) {
      $v = get_user_meta($user_id, $key, true);
      if (is_string($v) && trim($v) !== '') return trim($v);
    }
    return '';
  }

  private function user_region_value($user_id) {
    $keys = ['billing_state', 'shipping_state', 'afreg_additional_state', 'region'];
    foreach ($keys as $key) {
      $v = get_user_meta($user_id, $key, true);
      if (is_string($v) && trim($v) !== '') return trim($v);
    }
    return '';
  }

  private function get_customer_afreg($order, $user_id) {
    $rut   = $user_id ? get_user_meta($user_id, 'afreg_additional_42210', true) : '';
    $razon = $user_id ? get_user_meta($user_id, 'afreg_additional_42208', true) : '';
    $giro  = $user_id ? get_user_meta($user_id, 'afreg_additional_42209', true) : '';
    $vend  = $user_id ? get_user_meta($user_id, 'afreg_additional_42207', true) : '';

    $rut   = is_string($rut) ? trim($rut) : '';
    $razon = is_string($razon) ? trim($razon) : '';
    $giro  = is_string($giro) ? trim($giro) : '';
    $vend  = is_string($vend) ? trim($vend) : '';

    if ($order) {
      if ($rut === '')   $rut   = $this->order_meta_text($order, '_ripex_rut_empresa');
      if ($razon === '') $razon = $this->order_meta_text($order, '_ripex_razon_social');
      if ($giro === '')  $giro  = $this->order_meta_text($order, '_ripex_giro');
      if ($vend === '')  $vend  = $this->order_meta_text($order, '_ripex_vendedor');
    }

    return ['rut' => $rut, 'razon_social' => $razon, 'giro' => $giro, 'vendedor' => $vend];
  }


  private function get_order_creator_label($order) {
    if (!$order || !is_a($order, 'WC_Order')) return '—';

    $seller_id = (int) $order->get_meta('_ripex_seller_id');
    if ($seller_id) {
      $u = get_user_by('id', $seller_id);
      if ($u) return $u->display_name ?: $u->user_login;
    }

    $created_via = $this->order_meta_text($order, '_created_via');
    $customer_id = (int) $order->get_customer_id();

    if (in_array($created_via, ['checkout', 'store-api'], true)) {
      if ($customer_id) {
        $cu = get_user_by('id', $customer_id);
        if ($cu) return $cu->display_name ?: $cu->user_login;
      }
      $billing_name = trim($order->get_formatted_billing_full_name());
      if ($billing_name !== '') return $billing_name;
      if ($order->get_billing_email()) return (string) $order->get_billing_email();
    }

    if ($customer_id && !$seller_id) {
      $cu = get_user_by('id', $customer_id);
      if ($cu && $created_via === '') return $cu->display_name ?: $cu->user_login;
    }

    $post = get_post($order->get_id());
    if ($post && !empty($post->post_author)) {
      $au = get_user_by('id', (int)$post->post_author);
      if ($au) return $au->display_name ?: $au->user_login;
    }

    $billing_name = trim($order->get_formatted_billing_full_name());
    if ($billing_name !== '') return $billing_name;

    if ($order->get_billing_email()) return (string) $order->get_billing_email();

    return '—';
  }



  private function is_order_exported($order) {
    if (!$order || !is_a($order, 'WC_Order')) return false;
    return (bool) $order->get_meta('_ripex_bodega_exported');
  }

  private function mark_orders_exported($order_ids) {
    $role = $this->current_user_role_key();
    if ($role !== 'ripex_bodeguero') return;
    foreach ((array) $order_ids as $order_id) {
      $order_id = absint($order_id);
      if (!$order_id) continue;
      $order = wc_get_order($order_id);
      if (!$order) continue;
      $order->update_meta_data('_ripex_bodega_exported', '1');
      $order->update_meta_data('_ripex_bodega_exported_at', current_time('mysql'));
      $order->update_meta_data('_ripex_bodega_exported_by', get_current_user_id());
      $order->save();
    }
  }

  private function get_order_export_bundle($order) {
    if (!$order || !is_a($order, 'WC_Order')) return null;

    $shipping_title = '';
    $shipping_items = $order->get_items('shipping');
    if (!empty($shipping_items)) {
      $first = current($shipping_items);
      if ($first && method_exists($first, 'get_method_title')) $shipping_title = $this->clean_transport_label((string) $first->get_method_title());
    }

    $cid = $this->get_customer_user_id_from_order($order);
    $af = $this->get_customer_afreg($order, $cid);
    $rut = $af['rut'] ?? '';
    $razon = ($af['razon_social'] ?? '') ?: $order->get_billing_company();
    $giro = $af['giro'] ?? '';

    $addr_lines = $this->clean_address_lines($order->get_formatted_shipping_address());
    if (empty($addr_lines)) $addr_lines = $this->clean_address_lines($order->get_formatted_billing_address());
    $billing_name = trim($order->get_formatted_billing_full_name());
    if (!empty($addr_lines) && $billing_name !== '') {
      $first = trim((string) $addr_lines[0]);
      if (mb_strtolower($first) === mb_strtolower($billing_name)) array_shift($addr_lines);
    }

    $items = [];
    foreach ($order->get_items() as $item) {
      if (!is_a($item, 'WC_Order_Item_Product')) continue;
      $product = $item->get_product();
      $items[] = [
        'transport' => $shipping_title,
        'sku' => $product ? (string) $product->get_sku() : '',
        'product' => $item->get_name(),
        'qty' => (int) $item->get_quantity(),
        'stock' => $product ? $product->get_stock_quantity() : '',
      ];
    }

    return [
      'id' => $order->get_id(),
      'number' => $order->get_order_number(),
      'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y H:i') : '',
      'rut' => $rut,
      'razon_social' => $razon,
      'giro' => $giro,
      'direccion' => $this->join_address_for_csv($addr_lines),
      'items' => $items,
    ];
  }

  private function can_edit_order($order, $role, $user_id) {
    if (!$order || !is_a($order, 'WC_Order')) return false;
    if ($role === 'ripex_admin') return true;

    if ($role === 'ripex_vendedor') {
      // Regla RIPEX actualizada: el vendedor puede editar sus pedidos,
      // incluso si están procesando. Solo se bloquea edición cuando el
      // pedido ya está completado.
      if ($order->get_status() === 'completed') return false;
      return $this->vendor_mine_filter($order, $user_id);
    }

    return false;
  }

  private function vendor_created_filter($order, $vendor_user_id) {
    if (!$order || !is_a($order, 'WC_Order')) return false;

    $seller = (int) $order->get_meta('_ripex_seller_id');
    if ($seller && $seller === (int)$vendor_user_id) return true;

    $post = get_post($order->get_id());
    if ($post && (int)$post->post_author === (int)$vendor_user_id) return true;

    return false;
  }

  private function vendor_mine_filter($order, $vendor_user_id) {
    if (!$order || !is_a($order, 'WC_Order')) return false;

    $vendor_user_id = (int) $vendor_user_id;
    $vendor_labels = $this->get_vendor_match_labels($vendor_user_id);

    // Regla 1: si existe _ripex_seller_id, manda ese valor.
    $seller_raw = trim((string) $order->get_meta('_ripex_seller_id'));
    if ($seller_raw !== '') {
      return ((int) $seller_raw === $vendor_user_id);
    }

    // Regla 2: si existe _ripex_vendedor, manda ese valor.
    $order_vendor = $this->normalize_vendor_label($this->order_meta_text($order, '_ripex_vendedor'));
    if ($order_vendor !== '') {
      return (!empty($vendor_labels) && in_array($order_vendor, $vendor_labels, true));
    }

    // Regla 3: fallback por cliente asignado solo cuando no hay vendedor explícito.
    $cid = $this->get_customer_user_id_from_order($order);
    if ($cid && $this->customer_assigned_to_vendor($cid, $vendor_user_id)) return true;

    // No usar post_author como vendedor comercial: mezcla pedidos entre vendedores.
    return false;
  }


  public function maybe_disable_portal_order_emails($enabled, $order = null) {
    if (self::$suspend_portal_order_emails) return false;
    return $enabled;
  }

  private function trigger_portal_order_emails($order) {
    if (!$order || !is_a($order, 'WC_Order') || !function_exists('WC')) return;

    $mailer = WC()->mailer();
    if (!$mailer || !method_exists($mailer, 'get_emails')) return;

    $emails = $mailer->get_emails();
    if (!is_array($emails)) return;

    if (isset($emails['WC_Email_New_Order'])) {
      $emails['WC_Email_New_Order']->trigger($order->get_id(), $order);
    }

    $status = $order->get_status();
    if ($status === 'processing' && isset($emails['WC_Email_Customer_Processing_Order'])) {
      $emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id(), $order);
    } elseif ($status === 'on-hold' && isset($emails['WC_Email_Customer_On_Hold_Order'])) {
      $emails['WC_Email_Customer_On_Hold_Order']->trigger($order->get_id(), $order);
    }
  }

  /* =========================
   * Redirect / Block admin
   * ========================= */
  public function login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (!$user || is_wp_error($user)) return $redirect_to;

    $roles = isset($user->roles) ? (array)$user->roles : [];
    $is_portal = array_intersect($roles, ['ripex_admin','ripex_vendedor','ripex_bodeguero']);

    if (!empty($is_portal) && !in_array('administrator', $roles, true)) return $this->portal_url();
    return $redirect_to;
  }

  public function block_wp_admin_for_portal_roles() {
    if (!is_user_logged_in()) return;
    if (wp_doing_ajax()) return;

    $u = wp_get_current_user();
    $roles = isset($u->roles) ? (array)$u->roles : [];
    if (!array_intersect($roles, ['ripex_admin','ripex_vendedor','ripex_bodeguero'])) return;
    if (in_array('administrator', $roles, true)) return;

    // ripex_admin can access wp-admin backend
    if (in_array('ripex_admin', $roles, true)) return;

    // ripex_vendedor can only access edit screen for their own orders
    if (in_array('ripex_vendedor', $roles, true)) {
      $is_order_edit = (
        isset($_GET['post'], $_GET['action']) &&
        $_GET['action'] === 'edit' &&
        get_post_type((int) $_GET['post']) === 'shop_order'
      );
      if ($is_order_edit) {
        $order = wc_get_order((int) $_GET['post']);
        if ($order && $this->can_edit_order($order, 'ripex_vendedor', get_current_user_id())) return;
      }
    }

    wp_safe_redirect($this->portal_url());
    exit;
  }


  public function map_vendor_order_meta_caps($caps, $cap, $user_id, $args) {
    if (!in_array($cap, ['edit_post', 'read_post'], true)) return $caps;

    $post_id = isset($args[0]) ? absint($args[0]) : 0;
    if (!$post_id) return $caps;
    if (get_post_type($post_id) !== 'shop_order') return $caps;

    $user = get_user_by('id', $user_id);
    if (!$user || empty($user->roles)) return $caps;

    $roles = (array) $user->roles;
    if (in_array('administrator', $roles, true) || in_array('ripex_admin', $roles, true)) return $caps;

    if (in_array('ripex_vendedor', $roles, true)) {
      $order = wc_get_order($post_id);
      if ($order && $this->vendor_mine_filter($order, $user_id)) {
        if ($cap === 'read_post') return ['read'];
        if ($cap === 'edit_post' && $this->can_edit_order($order, 'ripex_vendedor', $user_id)) return ['read'];
        return ['do_not_allow'];
      }
    }

    return $caps;
  }

  public function add_admin_bar_portal_link($wp_admin_bar) {
    if (!is_user_logged_in()) return;
    if ($this->current_user_role_key() !== 'ripex_admin') return;
    if (!is_admin()) return;

    $wp_admin_bar->add_node([
      'id' => 'ripex-portal-pedidos',
      'title' => '<span class="ab-icon">🛒</span><span class="ab-label">Portal pedidos</span>',
      'href' => $this->portal_url(),
      'meta' => ['class' => 'ripex-portal-admin-link']
    ]);
  }

  public function output_admin_bar_portal_styles() {
    if (!is_user_logged_in()) return;
    if ($this->current_user_role_key() !== 'ripex_admin') return;
    if (!is_admin()) return;
    echo '<style>#wp-admin-bar-ripex-portal-pedidos>a{background:#0121ff!important;color:#fff!important;font-weight:700}#wp-admin-bar-ripex-portal-pedidos>a:hover{background:#1d4ed8!important;color:#fff!important}#wp-admin-bar-ripex-portal-pedidos .ab-icon{margin-right:6px}</style>';
  }

  public function maybe_hide_admin_bar($show) {
    if (!is_user_logged_in()) return $show;
    return $this->is_portal_role() ? false : $show;
  }



  private function is_product_code_like_search($term) {
    $term = is_string($term) ? trim($term) : '';
    if ($term === '') return false;

    // Códigos tipo W610/82, HU612/2X, WK12009, C30185, OEM/MAN, etc.
    return (bool) preg_match('/[A-Za-z]*\d{2,}[A-Za-z0-9\-\/\.]*\d*/', $term);
  }

  private function public_product_code_search_ids($term) {
    global $wpdb;

    $term = trim((string) $term);
    if ($term === '') return [];

    $like = '%' . $wpdb->esc_like($term) . '%';

    // Buscar por SKU, título y metas visibles de producto. Limitado para no impactar catálogo.
    $sql = $wpdb->prepare(
      "SELECT DISTINCT p.ID
       FROM {$wpdb->posts} p
       LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
       WHERE p.post_type IN ('product','product_variation')
         AND p.post_status = 'publish'
         AND (
           p.post_title LIKE %s
           OR (pm.meta_key = '_sku' AND pm.meta_value LIKE %s)
           OR (pm.meta_key NOT LIKE '\_%' AND pm.meta_value LIKE %s)
         )
       ORDER BY
         CASE
           WHEN pm.meta_key = '_sku' AND pm.meta_value = %s THEN 0
           WHEN p.post_title LIKE %s THEN 1
           WHEN pm.meta_key = '_sku' AND pm.meta_value LIKE %s THEN 2
           ELSE 3
         END,
         p.post_title ASC
       LIMIT 80",
      $like, $like, $like, $term, $like, $like
    );

    $ids = array_map('intval', (array) $wpdb->get_col($sql));
    if (empty($ids)) return [];

    // Si el match es una variación, incluir su padre para que WooCommerce lo liste en frontend.
    $expanded = [];
    foreach ($ids as $id) {
      $expanded[$id] = true;
      $parent = (int) wp_get_post_parent_id($id);
      if ($parent) $expanded[$parent] = true;
    }

    return array_keys($expanded);
  }

  public function refine_public_product_code_search($query) {
    if (is_admin() || !$query || !$query->is_main_query() || !$query->is_search()) return;

    $post_type = $query->get('post_type');
    $is_product_search = false;
    if ($post_type === 'product') $is_product_search = true;
    if (is_array($post_type) && in_array('product', $post_type, true)) $is_product_search = true;
    if (!$is_product_search && function_exists('is_shop') && (is_shop() || is_product_taxonomy())) $is_product_search = true;
    if (!$is_product_search) return;

    $term = (string) $query->get('s');
    if (!$this->is_product_code_like_search($term)) return;

    $ids = $this->public_product_code_search_ids($term);
    if (empty($ids)) return;

    $query->set('post__in', $ids);
    $query->set('orderby', 'post__in');
  }

  /* =========================
   * Assets
   * ========================= */
  public function enqueue_assets() {
    if (!is_singular()) return;

    global $post;
    if (!$post) return;

    $is_portal = has_shortcode($post->post_content, self::SHORTCODE_PORTAL);
    $is_create = has_shortcode($post->post_content, self::SHORTCODE_CREATE);

    if (!$is_portal && !$is_create) return;

    wp_enqueue_style('ripex-portal-css', RIPEX_PORTAL_URL . 'assets/css/portal.css', [], RIPEX_PORTAL_VERSION);

    $extra_css = "
      header#site-header{display:none!important;}
      .elementor.elementor-40855.elementor-location-footer{display:none!important;}
      div#content-wrap{max-width:95%!important;width:100%!important;}
    ";
    wp_add_inline_style('ripex-portal-css', $extra_css);

    if ($is_portal) wp_enqueue_script('ripex-portal-js', RIPEX_PORTAL_URL . 'assets/js/portal.js', [], RIPEX_PORTAL_VERSION, true);
    if ($is_portal || $is_create) wp_enqueue_script('ripex-create-js', RIPEX_PORTAL_URL . 'assets/js/create-order.js', [], RIPEX_PORTAL_VERSION, true);

    $data = [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(self::NONCE_ACTION),
      'role'    => $this->current_user_role_key(),
      'portalUrl' => $this->portal_url(),
      'createUrl' => $this->create_url(),
      'logoUrl' => esc_url_raw('https://ripex.cl/portal/wp-content/uploads/2024/02/logo-ripex-negro.png'),
    ];

    if ($is_portal) wp_localize_script('ripex-portal-js', 'RIPEX_PORTAL', $data);
    if ($is_portal || $is_create) wp_localize_script('ripex-create-js', 'RIPEX_PORTAL', $data);
  }


  public function enqueue_checkout_credit_fallback_assets() {
    if (is_admin() && !wp_doing_ajax()) return;
    if (!function_exists('is_checkout') || !is_checkout()) return;

    $credit_user_id = $this->credit_user_id_from_checkout_context();
    $credit_term = $credit_user_id ? $this->credit_term_raw($credit_user_id) : '';
    $credit_enabled = $credit_user_id && $this->is_credit_term_active($credit_term);

    // Este fallback solo dibuja la opción si el usuario logueado realmente tiene crédito.
    // La validación fuerte sigue estando en woocommerce_available_payment_gateways.
    if (!$credit_enabled) return;

    wp_enqueue_script(
      'ripex-checkout-credit-fallback',
      RIPEX_PORTAL_URL . 'assets/js/checkout-credit.js',
      ['jquery'],
      RIPEX_PORTAL_VERSION,
      true
    );

    wp_localize_script('ripex-checkout-credit-fallback', 'RIPEX_CHECKOUT_CREDIT', [
      'enabled' => true,
      'methodId' => 'cheque',
      'title' => $this->credit_gateway_title_for_user($credit_user_id),
      'description' => 'Pago a crédito habilitado para tu cuenta.',
    ]);

    wp_register_style('ripex-checkout-credit-fallback-css', false, [], RIPEX_PORTAL_VERSION);
    wp_enqueue_style('ripex-checkout-credit-fallback-css');
    wp_add_inline_style('ripex-checkout-credit-fallback-css', '
      .ripex-credit-fallback-added.payment_method_cheque{display:block!important;visibility:visible!important;opacity:1!important;}
      .ripex-credit-fallback-added label{cursor:pointer;}
      .ripex-credit-fallback-added .payment_box{display:block;}
    ');
  }

  /* =========================
   * UI: Portal
   * ========================= */
  public function render_portal_shortcode($atts = []) {
    if (!is_user_logged_in()) {
      $login_url = wp_login_url($this->portal_url());
      return '<div class="rp-wrap"><div class="rp-card"><h2>Acceso requerido</h2><p>Debes iniciar sesión para ver el portal.</p><a class="rp-btn rp-btn-primary" href="'.esc_url($login_url).'">Iniciar sesión</a></div></div>';
    }
    if (!$this->is_portal_role()) {
      return '<div class="rp-wrap"><div class="rp-card"><h2>Sin permisos</h2><p>Tu usuario no tiene permisos para acceder al portal.</p></div></div>';
    }

    $user = wp_get_current_user();
    $role = $this->current_user_role_key();
    $can_create = $this->can_create_order();
    $is_vendor = ($role === 'ripex_vendedor');
    $show_payment_col = ($role !== 'ripex_bodeguero');
    $is_portal_admin = ($role === 'ripex_admin');

    ob_start(); ?>
      <div class="rp-app" data-role="<?php echo esc_attr($role); ?>" data-page="portal">
        <header class="rp-topbar">
          <div class="rp-topbar-left">
            <img class="rp-logo" src="<?php echo esc_url('https://ripex.cl/portal/wp-content/uploads/2024/02/logo-ripex-negro.png'); ?>" alt="RIPEX">
            <div class="rp-topbar-title">
              <div class="rp-title">Portal Pedidos</div>
              <div class="rp-subtitle"><?php echo esc_html($user->display_name); ?> · <?php echo esc_html($this->role_label($role)); ?></div>
            </div>
          </div>
          <div class="rp-topbar-right">
            <button type="button" class="rp-btn rp-font-btn" id="rp-font-decrease" aria-label="Disminuir letra">A-</button>
            <button type="button" class="rp-btn rp-font-btn" id="rp-font-increase" aria-label="Aumentar letra">A+</button>
            <?php if ($is_portal_admin): ?>
              <a class="rp-btn rp-btn-primary" href="<?php echo esc_url(admin_url()); ?>" target="_blank" rel="noopener">Ir al panel</a>
            <?php endif; ?>
            <a class="rp-link" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Salir</a>
          </div>
        </header>

        <nav class="rp-tabs">
          <button class="rp-tab rp-tab-active" data-tab="orders">Pedidos</button>
          <button class="rp-tab" data-tab="inventory">Inventario</button>
          <?php if (in_array($role, ['ripex_admin','ripex_vendedor'], true)): ?>
            <button class="rp-tab" data-tab="reports">Reportes</button>
            <button class="rp-tab" data-tab="customers">Clientes</button>
            <button class="rp-tab" data-tab="carts">Carritos</button>
          <?php endif; ?>
        </nav>

        <main class="rp-main">
          <section class="rp-panel rp-panel-active" data-panel="orders">
            <div class="rp-panel-head">
              <h2 class="rp-h2">Pedidos</h2>

              <div class="rp-actions">
                <div class="rp-actions-row rp-orders-filters-row">
                  <input class="rp-input" type="search" id="rp-order-search" placeholder="Buscar pedido">
                  <select class="rp-select" id="rp-order-status">
                    <option value="">Todos los estados</option>
                    <option value="wc-processing">Procesando</option>
                    <option value="wc-on-hold">En espera</option>
                    <option value="wc-completed">Completado</option>
                    <option value="wc-cancelled">Cancelado</option>
                    <option value="wc-pending">Pendiente</option>
                    <option value="wc-failed">Fallido</option>
                  </select>
                  <input class="rp-input rp-input-sm" type="date" id="rp-order-date-from">
                  <input class="rp-input rp-input-sm" type="date" id="rp-order-date-to">
                  <button class="rp-btn rp-btn-primary" id="rp-refresh-orders">Actualizar</button>
                  <button class="rp-btn" id="rp-export-selected-pdf" type="button">Exportar seleccionados PDF</button>
                </div>

                <div class="rp-actions-row rp-orders-actions-row">
                  <?php if ($is_vendor): ?>
                    <div class="rp-subtabs" role="tablist" aria-label="Filtro pedidos">
                      <button class="rp-subtab rp-subtab-active" data-scope="mine">Mis pedidos</button>
                      <button class="rp-subtab" data-scope="all">Todos</button>
                    </div>
                  <?php endif; ?>

                  <div class="rp-colmenu-wrap">
                    <button class="rp-btn" id="rp-columns-toggle" type="button">Ocultar columnas</button>
                    <div class="rp-colmenu" id="rp-columns-menu" style="display:none;"></div>
                  </div>

                  <?php if ($can_create): ?>
                    <button class="rp-btn rp-btn-create" id="rp-new-order">Crear pedido</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>

<div class="rp-table-wrap">
              <table class="rp-table" id="rp-orders-table">
                <thead>
                  <tr>
                    <th data-col="select" class="rp-col-select"><input type="checkbox" id="rp-select-all-orders" aria-label="Seleccionar todos"></th>
                    <th data-col="id">#</th>
                    <th data-col="fecha">Fecha</th>
                    <th data-col="cliente">Cliente</th>
                    <th data-col="estado">Estado</th>
                    <th class="rp-col-payment" data-col="metodo_pago" style="<?php echo $show_payment_col ? '' : 'display:none;'; ?>">Método de pago</th>
                    <th data-col="vendedor">Vendedor</th>
                    <th data-col="exportacion">Exportación</th>
                    <th class="rp-col-total" data-col="total">Total</th>
                    <th data-col="envio">Envío</th>
                    <th data-col="acciones"></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>

              <div class="rp-empty" id="rp-orders-empty" style="display:none;">No hay pedidos para mostrar.</div>
              <div class="rp-loading" id="rp-orders-loading" style="display:none;">Cargando...</div>

              <nav class="rp-pagination" id="rp-orders-pagination" aria-label="Navegación de páginas" style="display:none;">
                <button class="rp-btn rp-btn-pager" id="rp-orders-prev" aria-label="Página anterior">← Anterior</button>
                <span class="rp-pagination-info" id="rp-orders-page-info"></span>
                <button class="rp-btn rp-btn-pager" id="rp-orders-next" aria-label="Página siguiente">Siguiente →</button>
              </nav>
            </div>

          
            <?php if ($can_create): ?>
            <div class="rp-modal" id="rp-new-order-modal" aria-hidden="true">
              <div class="rp-modal-backdrop" id="rp-new-order-close"></div>
              <div class="rp-modal-body">
                <div class="rp-modal-head">
                  <div>
                    <div class="rp-drawer-title" id="rp-modal-title">Detalles de Pedido #Nuevo</div>
                    <div class="rp-small" id="rp-modal-subtitle">Completa los datos y crea el pedido</div>
                  </div>
                  <button class="rp-icon-btn" id="rp-new-order-x" aria-label="Cerrar">×</button>
                </div>

                <div class="rp-modal-content">
                  <div class="rp-meta-row">
                    <div class="rp-meta">
                      <div class="rp-k">Fecha y hora</div>
                      <div class="rp-v" id="rp-now">—</div>
                    </div>
                    <div class="rp-meta">
                      <div class="rp-k">Estado</div>
                      <div class="rp-v">
                        <select class="rp-select rp-select-sm" id="rp-order-status-create">
                          <option value="pending">Pendiente de pago</option>
                          <option value="on-hold" selected>En espera</option>
                          <option value="processing">Procesando</option>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div class="rp-section">
                    <h3>Cliente</h3>

                    <div class="rp-form-row">
                      <input class="rp-input" id="rp-customer-search" placeholder="Buscar cliente por nombre, email o RUT..." autocomplete="off">
                      <div class="rp-suggest" id="rp-customer-suggest" style="display:none;"></div>
                    </div>

                    <div class="rp-customer-chip" id="rp-customer-chip" style="display:none;">
                      <div>
                        <div class="rp-chip-title" id="rp-customer-name">—</div>
                        <div class="rp-small" id="rp-customer-email">—</div>
                      </div>
                      <button class="rp-btn rp-btn-light" id="rp-toggle-customer">Desplegar información de cliente</button>
                    </div>

                    <div class="rp-customer-box" id="rp-customer-box" style="display:none;">
                      <div class="rp-form-grid">
                        <div>
                          <label class="rp-label">Nombre</label>
                          <input class="rp-input" id="rp-billing-first" placeholder="Nombre">
                        </div>
                        <div>
                          <label class="rp-label">Apellido</label>
                          <input class="rp-input" id="rp-billing-last" placeholder="Apellido">
                        </div>
                        <div>
                          <label class="rp-label">Teléfono</label>
                          <input class="rp-input" id="rp-billing-phone" placeholder="+56 9 ...">
                        </div>
                        <div>
                          <label class="rp-label">Email</label>
                          <input class="rp-input" id="rp-billing-email" placeholder="cliente@correo.cl" disabled>
                        </div>

                        <div>
                          <label class="rp-label">RUT empresa</label>
                          <input class="rp-input" id="rp-rut-empresa" placeholder="RUT empresa">
                        </div>
                        <div>
                          <label class="rp-label">Razón social</label>
                          <input class="rp-input" id="rp-razon-social" placeholder="Razón social">
                        </div>
                        <div>
                          <label class="rp-label">Giro</label>
                          <input class="rp-input" id="rp-giro" placeholder="Giro">
                        </div>
                        <div>
                          <label class="rp-label">Vendedor</label>
                          <input class="rp-input" id="rp-vendedor" placeholder="Vendedor">
                        </div>
                        <div>
                          <label class="rp-label">País</label>
                          <input class="rp-input" id="rp-billing-country" value="CL">
                        </div>

                        <div>
                          <label class="rp-label">Dirección</label>
                          <input class="rp-input" id="rp-billing-address1" placeholder="Calle y número">
                        </div>
                        <div>
                          <label class="rp-label">Comuna/Ciudad</label>
                          <input class="rp-input" id="rp-billing-city" list="rp-city-list-global" placeholder="Comuna / Ciudad">
                        </div>
                        <div>
                          <label class="rp-label">Región</label>
                          <input class="rp-input" id="rp-billing-state" placeholder="Región">
                        </div>
                        <div>
                          <label class="rp-label">Código postal</label>
                          <input class="rp-input" id="rp-billing-postcode" placeholder="0000000">
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="rp-section">
                    <h3>Envío y pago</h3>
                    <div class="rp-form-grid">
                      <div>
                        <label class="rp-label">Transporte</label>
                        <select class="rp-select" id="rp-shipping-method"></select>
                      </div>
                      <div>
                        <label class="rp-label">Método de pago</label>
                        <select class="rp-select" id="rp-payment-method">
                          <option value="bacs">Transferencia bancaria</option>
                          <option value="cheque">Pago a crédito</option>
                          <option value="cod">Contra entrega</option>
                        </select>
                      </div>
                      <div id="rp-credit-term-wrap" style="display:none;">
                        <label class="rp-label">Plazo crédito</label>
                        <select class="rp-select" id="rp-credit-term">
                          <option value="">Según cliente</option>
                          <option value="30 días">30 días</option>
                          <option value="45 días">45 días</option>
                          <option value="60 días">60 días</option>
                          <option value="90 días">90 días</option>
                        </select>
                      </div>
                      <div>
                        <label class="rp-label">Nota del pedido</label>
                        <input class="rp-input" id="rp-order-note" placeholder="Observaciones (opcional)">
                      </div>
                    </div>
                  </div>

                  <div class="rp-section">
                    <h3>Productos</h3>

                    <div class="rp-box">
                      <div class="rp-form-row">
                        <input class="rp-input" id="rp-product-search" placeholder="Buscar por SKU o nombre..." autocomplete="off">
                        <button class="rp-btn" id="rp-add-product">Agregar</button>
                      </div>
                      <div class="rp-suggest" id="rp-product-suggest" style="display:none;"></div>

                      <div class="rp-list" id="rp-new-order-items">
                        <div class="rp-li"><div class="rp-small">Agrega productos para crear el pedido.</div><div></div></div>
                      </div>
                    </div>
                  </div>

                  <div class="rp-order-summary">
                    <span>Sub-total productos</span>
                    <span id="rp-items-count" class="rp-items-count-badge">0 ítems</span>
                    <strong id="rp-items-subtotal">$0</strong>
                  </div>

                  <div class="rp-section rp-actions-right">
                    <button class="rp-btn" id="rp-cancel-create">Cancelar</button>
                    <button class="rp-btn rp-btn-primary" id="rp-create-order">Crear pedido</button>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>


          </section>

          <section class="rp-panel" data-panel="inventory">
            <div class="rp-panel-head">
              <h2 class="rp-h2">Inventario (solo lectura)</h2>
              <div class="rp-actions">
                <div class="rp-actions-row">
                  <input class="rp-input" type="search" id="rp-product-search-inv" placeholder="Buscar SKU, producto o descripción...">
                  <select class="rp-select" id="rp-inventory-category">
                    <option value="">Todas las categorías</option>
                  </select>
                  <select class="rp-select" id="rp-inventory-orderby">
                    <option value="name_asc">Nombre A-Z</option>
                    <option value="name_desc">Nombre Z-A</option>
                    <option value="sku_asc">SKU ascendente</option>
                    <option value="sku_desc">SKU descendente</option>
                    <option value="stock_asc">Stock menor a mayor</option>
                    <option value="stock_desc">Stock mayor a menor</option>
                  </select>
                  <button class="rp-btn rp-btn-primary" id="rp-refresh-products">Actualizar</button>
                </div>
              </div>
            </div>

            
            <?php if (in_array($role, ['ripex_bodeguero','ripex_admin'], true)): ?>
            <div class="rp-bulk-export-card" id="rp-bulk-export-card">
              <div class="rp-bulk-export-head">
                <strong>Exportación masiva por fecha</strong>
                <span class="rp-small">Para exportar pedidos del día o por rango sin seleccionarlos uno a uno.</span>
              </div>
              <div class="rp-bulk-export-grid">
                <div>
                  <label class="rp-label">Desde</label>
                  <input class="rp-input rp-input-sm" type="date" id="rp-bulk-date-from">
                </div>
                <div>
                  <label class="rp-label">Hasta</label>
                  <input class="rp-input rp-input-sm" type="date" id="rp-bulk-date-to">
                </div>
                <div class="rp-bulk-export-check">
                  <label class="rp-checkline">
                    <input type="checkbox" id="rp-bulk-only-pending-export" checked>
                    <span>Solo no exportados</span>
                  </label>
                </div>
                <div class="rp-bulk-export-actions">
                  <button class="rp-btn rp-btn-primary" id="rp-export-range-csv" type="button">Exportar rango CSV</button>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <div class="rp-table-wrap">
              <table class="rp-table" id="rp-products-table">
                <thead>
                  <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Stock</th>
                    <th>Estado</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
              <div class="rp-empty" id="rp-products-empty" style="display:none;">No hay productos para mostrar.</div>
              <div class="rp-loading" id="rp-products-loading" style="display:none;">Cargando...</div>
            </div>
          </section>

          <?php if (in_array($role, ['ripex_admin','ripex_vendedor'], true)): ?>
          <section class="rp-panel" data-panel="reports">
            <div class="rp-panel-head">
              <h2 class="rp-h2">Reportes</h2>
              <div class="rp-actions">
                <div class="rp-actions-row">
                  <input class="rp-input rp-input-sm" type="date" id="rp-report-date-from">
                  <input class="rp-input rp-input-sm" type="date" id="rp-report-date-to">
                  <div class="rp-colmenu-wrap">
                    <button class="rp-btn" id="rp-kpis-toggle" type="button">Indicadores</button>
                    <div class="rp-colmenu" id="rp-kpis-menu" style="display:none;"></div>
                  </div>
                  <button class="rp-btn" id="rp-export-reports-pdf" type="button">Descargar PDF</button>
                  <button class="rp-btn rp-btn-primary" id="rp-refresh-reports">Actualizar reportes</button>
                </div>
              </div>
            </div>

            <div class="rp-report-loading" id="rp-reports-loading" style="display:none;">Cargando reportes...</div>

            <div class="rp-kpi-grid" id="rp-kpi-grid">
              <div class="rp-kpi-card" data-kpi-card="revenue"><div class="rp-kpi-label">Ventas</div><div class="rp-kpi-value" id="rp-kpi-revenue">—</div><div class="rp-kpi-sub" id="rp-kpi-revenue-sub">—</div></div>
              <div class="rp-kpi-card" data-kpi-card="orders"><div class="rp-kpi-label">Pedidos</div><div class="rp-kpi-value" id="rp-kpi-orders">—</div><div class="rp-kpi-sub">Pedidos en rango</div></div>
              <div class="rp-kpi-card" data-kpi-card="avg_ticket"><div class="rp-kpi-label">Ticket promedio</div><div class="rp-kpi-value" id="rp-kpi-avg">—</div><div class="rp-kpi-sub">Promedio por pedido vendido</div></div>
              <div class="rp-kpi-card" data-kpi-card="customers"><div class="rp-kpi-label">Clientes únicos</div><div class="rp-kpi-value" id="rp-kpi-customers">—</div><div class="rp-kpi-sub">Compradores únicos</div></div>
              <div class="rp-kpi-card" data-kpi-card="items"><div class="rp-kpi-label">Unidades vendidas</div><div class="rp-kpi-value" id="rp-kpi-items">—</div><div class="rp-kpi-sub">Suma de cantidades</div></div>
              <div class="rp-kpi-card" data-kpi-card="stock"><div class="rp-kpi-label">Stock total visible</div><div class="rp-kpi-value" id="rp-kpi-stock">—</div><div class="rp-kpi-sub">Inventario publicado</div></div>
              <div class="rp-kpi-card" data-kpi-card="active_sellers"><div class="rp-kpi-label">Vendedores activos</div><div class="rp-kpi-value" id="rp-kpi-sellers">—</div><div class="rp-kpi-sub">Con ventas en el rango</div></div>
              <div class="rp-kpi-card" data-kpi-card="low_stock_count"><div class="rp-kpi-label">Stock crítico</div><div class="rp-kpi-value" id="rp-kpi-low-stock">—</div><div class="rp-kpi-sub">Productos con stock bajo</div></div>
            </div>

            <div class="rp-report-grid">
              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Ventas por día</h3>
                  <span class="rp-small">Tendencia del período</span>
                </div>
                <div id="rp-chart-sales-line"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Ventas por vendedor</h3>
                  <span class="rp-small">Ranking por monto vendido</span>
                </div>
                <div id="rp-chart-sellers"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Pedidos por estado</h3>
                  <span class="rp-small">Distribución operativa</span>
                </div>
                <div id="rp-chart-statuses"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Ventas por transporte</h3>
                  <span class="rp-small">Métodos de despacho más usados</span>
                </div>
                <div id="rp-chart-transports"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Top productos vendidos</h3>
                  <span class="rp-small">Cantidad vendida y stock actual</span>
                </div>
                <div id="rp-chart-top-products"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Top empresas / razón social</h3>
                  <span class="rp-small">Ranking por ventas</span>
                </div>
                <div id="rp-chart-top-companies"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Clientes inactivos</h3>
                  <span class="rp-small">Sin compras en 60+ días</span>
                </div>
                <div id="rp-report-inactive-customers"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Productos sin movimiento</h3>
                  <span class="rp-small">Sin ventas en el rango</span>
                </div>
                <div id="rp-report-no-movement"></div>
              </div>

              <div class="rp-report-card">
                <div class="rp-report-head">
                  <h3>Alertas de stock</h3>
                  <span class="rp-small">Productos con stock bajo</span>
                </div>
                <div id="rp-report-low-stock"></div>
              </div>
            </div>
          </section>
          <?php endif; ?>

          <?php if (in_array($role, ['ripex_admin','ripex_vendedor'], true)): ?>
          <section class="rp-panel" data-panel="customers">
            <div class="rp-panel-head">
              <h2 class="rp-h2">Clientes asignados</h2>
              <div class="rp-actions">
                <div class="rp-actions-row">
                  <input class="rp-input" type="search" id="rp-customer-list-search" placeholder="Buscar cliente, RUT o razón social">
                  <?php if ($role === 'ripex_admin'): ?>
                    <select class="rp-select" id="rp-customer-vendor-filter"><option value="">Todos los vendedores</option></select>
                  <?php endif; ?>
                  <select class="rp-select" id="rp-customer-city-filter"><option value="">Cualquier ciudad</option></select>
                  <button class="rp-btn rp-btn-primary" id="rp-refresh-customers">Actualizar clientes</button>
                </div>
              </div>
            </div>
            <div class="rp-table-wrap">
              <table class="rp-table rp-customers-table" id="rp-customers-table">
                <thead><tr><th>Nombre</th><th>Razón social</th><th>Ciudad</th><th>RUT</th><th></th></tr></thead>
                <tbody></tbody>
              </table>
              <div class="rp-empty" id="rp-customers-empty" style="display:none;">No hay clientes para mostrar.</div>
              <div class="rp-loading" id="rp-customers-loading" style="display:none;">Cargando clientes...</div>
            </div>
          </section>

          <section class="rp-panel" data-panel="carts">
            <div class="rp-panel-head">
              <h2 class="rp-h2">Carritos activos</h2>
              <div class="rp-actions">
                <div class="rp-actions-row">
                  <button class="rp-btn rp-btn-primary" id="rp-refresh-carts">Actualizar carritos</button>
                  <span class="rp-small">Permite revisar carritos con productos y cerrarlos como pedido en espera.</span>
                </div>
              </div>
            </div>
            <div class="rp-table-wrap">
              <table class="rp-table rp-carts-table" id="rp-carts-table">
                <thead><tr><th>Cliente</th><th>Contacto</th><th>Ítems</th><th>Total estimado</th><th>Última actividad</th><th></th></tr></thead>
                <tbody></tbody>
              </table>
              <div class="rp-empty" id="rp-carts-empty" style="display:none;">No hay carritos activos para mostrar.</div>
              <div class="rp-loading" id="rp-carts-loading" style="display:none;">Cargando carritos...</div>
            </div>
          </section>
          <?php endif; ?>
        </main>

        <!-- Drawer global de pedido/carrito: independiente y fuera de todos los paneles -->
        <div class="rp-drawer" id="rp-order-drawer" aria-hidden="true">
          <div class="rp-drawer-backdrop" id="rp-drawer-close"></div>
          <div class="rp-drawer-body">
            <div class="rp-drawer-head">
              <div>
                <div class="rp-drawer-title" id="rp-order-title">Pedido</div>
                <div class="rp-drawer-sub" id="rp-order-subtitle"></div>
              </div>
              <button class="rp-icon-btn" id="rp-drawer-x" aria-label="Cerrar">×</button>
            </div>
            <div class="rp-drawer-content" id="rp-order-content"></div>
          </div>
        </div>

        <!-- Drawer de historial de cliente: independiente, fuera de todos los paneles -->
        <div class="rp-drawer" id="rp-customer-history-drawer" aria-hidden="true">
          <div class="rp-drawer-backdrop" id="rp-customer-history-backdrop"></div>
          <div class="rp-drawer-body">
            <div class="rp-drawer-head">
              <div>
                <div class="rp-drawer-title" id="rp-customer-history-title">Historial de cliente</div>
                <div class="rp-drawer-sub" id="rp-customer-history-sub"></div>
              </div>
              <button class="rp-icon-btn" id="rp-customer-history-close" aria-label="Cerrar">×</button>
            </div>
            <div class="rp-drawer-content" id="rp-customer-history-content"></div>
          </div>
        </div>

        <footer class="rp-footer">
          <span>RIPEX Portal v<?php echo esc_html(RIPEX_PORTAL_VERSION); ?></span>
        </footer>
      </div>
    <?php
    return ob_get_clean();
  }

  /* =========================
   * UI: Create Order Page
   * ========================= */
  public function render_create_shortcode($atts = []) {
    if (!is_user_logged_in()) {
      $login_url = wp_login_url($this->create_url());
      return '<div class="rp-wrap"><div class="rp-card"><h2>Acceso requerido</h2><p>Debes iniciar sesión para crear pedidos.</p><a class="rp-btn rp-btn-primary" href="'.esc_url($login_url).'">Iniciar sesión</a></div></div>';
    }
    if (!$this->is_portal_role() || !$this->can_create_order()) {
      return '<div class="rp-wrap"><div class="rp-card"><h2>Sin permisos</h2><p>Tu usuario no tiene permisos para crear pedidos.</p></div></div>';
    }

    $user = wp_get_current_user();
    $role = $this->current_user_role_key();
    $is_portal_admin = ($role === 'ripex_admin');

    ob_start(); ?>
      <div class="rp-app" data-role="<?php echo esc_attr($role); ?>" data-page="create">
        <header class="rp-topbar">
          <div class="rp-topbar-left">
            <img class="rp-logo" src="<?php echo esc_url('https://ripex.cl/portal/wp-content/uploads/2024/02/logo-ripex-negro.png'); ?>" alt="RIPEX">
            <div class="rp-topbar-title">
              <div class="rp-title" id="rp-create-title">Detalles de Pedido #Nuevo</div>
              <div class="rp-subtitle"><?php echo esc_html($user->display_name); ?> · <?php echo esc_html($this->role_label($role)); ?></div>
            </div>
          </div>
          <div class="rp-topbar-right">
            <button type="button" class="rp-btn rp-font-btn" id="rp-font-decrease" aria-label="Disminuir letra">A-</button>
            <button type="button" class="rp-btn rp-font-btn" id="rp-font-increase" aria-label="Aumentar letra">A+</button>
            <?php if ($is_portal_admin): ?>
              <a class="rp-btn rp-btn-primary" href="<?php echo esc_url(admin_url()); ?>" target="_blank" rel="noopener">Ir al panel</a>
            <?php endif; ?>
            <a class="rp-btn" href="<?php echo esc_url($this->portal_url()); ?>">Volver</a>
          </div>
        </header>

        <main class="rp-main">
          <section class="rp-panel rp-panel-active">
            <div class="rp-meta-row">
              <div class="rp-meta">
                <div class="rp-k">Fecha y hora</div>
                <div class="rp-v" id="rp-now">—</div>
              </div>
              <div class="rp-meta">
                <div class="rp-k">Estado</div>
                <div class="rp-v">
                  <select class="rp-select rp-select-sm" id="rp-order-status-create">
                    <option value="pending">Pendiente de pago</option>
                    <option value="on-hold">En espera</option>
                    <option value="processing">Procesando</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="rp-section">
              <h3>Cliente</h3>

              <div class="rp-form-row">
                <input class="rp-input" id="rp-customer-search" placeholder="Buscar cliente por nombre, email o RUT..." autocomplete="off">
                <div class="rp-suggest" id="rp-customer-suggest" style="display:none;"></div>
              </div>

              <div class="rp-customer-chip" id="rp-customer-chip" style="display:none;">
                <div>
                  <div class="rp-chip-title" id="rp-customer-name">—</div>
                  <div class="rp-small" id="rp-customer-email">—</div>
                </div>
                <button class="rp-btn rp-btn-light" id="rp-toggle-customer">Desplegar información de cliente</button>
              </div>

              <div class="rp-customer-box" id="rp-customer-box" style="display:none;">
                <div class="rp-form-grid">
                  <div>
                    <label class="rp-label">Nombre</label>
                    <input class="rp-input" id="rp-billing-first" placeholder="Nombre">
                  </div>
                  <div>
                    <label class="rp-label">Apellido</label>
                    <input class="rp-input" id="rp-billing-last" placeholder="Apellido">
                  </div>
                  <div>
                    <label class="rp-label">Teléfono</label>
                    <input class="rp-input" id="rp-billing-phone" placeholder="+56 9 ...">
                  </div>
                  <div>
                    <label class="rp-label">Email</label>
                    <input class="rp-input" id="rp-billing-email" placeholder="cliente@correo.cl" disabled>
                  </div>

                  <div>
                    <label class="rp-label">Razón social</label>
                    <input class="rp-input" id="rp-razon-social" placeholder="Razón social">
                  </div>
                  <div>
                    <label class="rp-label">Giro</label>
                    <input class="rp-input" id="rp-giro" placeholder="Giro">
                  </div>
                  <div>
                    <label class="rp-label">Vendedor</label>
                    <input class="rp-input" id="rp-vendedor" placeholder="Vendedor">
                  </div>
                  <div>
                    <label class="rp-label">País</label>
                    <input class="rp-input" id="rp-billing-country" value="CL">
                  </div>

                  <div>
                    <label class="rp-label">Dirección</label>
                    <input class="rp-input" id="rp-billing-address1" placeholder="Calle y número">
                  </div>
                  <div>
                    <label class="rp-label">Comuna/Ciudad</label>
                    <input class="rp-input" id="rp-billing-city" list="rp-city-list-global" placeholder="Comuna / Ciudad">
                  </div>
                  <div>
                    <label class="rp-label">Región</label>
                    <input class="rp-input" id="rp-billing-state" placeholder="Región">
                  </div>
                  <div>
                    <label class="rp-label">Código postal</label>
                    <input class="rp-input" id="rp-billing-postcode" placeholder="0000000">
                  </div>
                </div>
              </div>
            </div>

            <div class="rp-section">
              <h3>Envío y pago</h3>
              <div class="rp-form-grid">
                <div>
                  <label class="rp-label">Transporte</label>
                  <select class="rp-select" id="rp-shipping-method"></select>
                </div>
                <div>
                  <label class="rp-label">Método de pago</label>
                  <select class="rp-select" id="rp-payment-method">
                    <option value="bacs">Transferencia bancaria</option>
                    <option value="cheque">Pago a crédito</option>
                    <option value="cod">Contra entrega</option>
                  </select>
                </div>
                <div id="rp-credit-term-wrap" style="display:none;">
                  <label class="rp-label">Plazo crédito</label>
                  <select class="rp-select" id="rp-credit-term">
                    <option value="">Según cliente</option>
                    <option value="30 días">30 días</option>
                    <option value="45 días">45 días</option>
                    <option value="60 días">60 días</option>
                    <option value="90 días">90 días</option>
                  </select>
                </div>
                <div>
                  <label class="rp-label">Nota del pedido</label>
                  <input class="rp-input" id="rp-order-note" placeholder="Observaciones (opcional)">
                </div>
              </div>
            </div>

            <div class="rp-section">
              <h3>Productos</h3>

              <div class="rp-box">
                <div class="rp-form-row">
                  <input class="rp-input" id="rp-product-search" placeholder="Buscar por SKU o nombre..." autocomplete="off">
                  <button class="rp-btn" id="rp-add-product">Agregar</button>
                </div>
                <div class="rp-suggest" id="rp-product-suggest" style="display:none;"></div>

                <div class="rp-list" id="rp-new-order-items">
                  <div class="rp-li"><div class="rp-small">Agrega productos para crear el pedido.</div><div></div></div>
                </div>
              </div>
            </div>

            <div class="rp-order-summary">
              <span>Sub-total productos</span>
              <span id="rp-items-count" class="rp-items-count-badge">0 ítems</span>
              <strong id="rp-items-subtotal">$0</strong>
            </div>

            <div class="rp-section rp-actions-right">
              <button class="rp-btn" id="rp-cancel-create">Cancelar</button>
              <button class="rp-btn rp-btn-primary" id="rp-create-order">Crear pedido</button>
            </div>

          </section>
        </main>

        <footer class="rp-footer">
          <span>RIPEX Portal v<?php echo esc_html(RIPEX_PORTAL_VERSION); ?></span>
        </footer>
      </div>
    <?php
    return ob_get_clean();
  }

  private function role_label($role) {
    switch ($role) {
      case 'ripex_admin': return 'Admin';
      case 'ripex_vendedor': return 'Vendedor';
      case 'ripex_bodeguero': return 'Bodeguero';
      default: return 'Usuario';
    }
  }

  /**
   * Devuelve el array de IDs de pedidos que pertenecen a un vendedor.
   *
   * Un pedido "pertenece" al vendedor si:
   *   (a) _ripex_seller_id == $vendor_user_id  (pedido creado desde el portal)
   *   (b) post_author == $vendor_user_id        (pedido creado desde wp-admin)
   *   (c) _ripex_vendedor contiene alguna de las etiquetas normalizadas del vendedor
   *   (d) el cliente del pedido está asignado al vendedor (afreg_additional_42207)
   *
   * Se calculan UNA VEZ con consultas SQL directas, evitando el patrón N+1
   * de cargar todos los pedidos como objetos WC_Order para filtrarlos.
   * El resultado se memoiza por request en $vendor_labels_cache para que si
   * se llama dos veces no repita las queries.
   */
  private function get_vendor_order_ids($vendor_user_id) {
    $vendor_user_id = (int) $vendor_user_id;
    $cache_key = 'order_ids_' . $vendor_user_id;
    if (isset($this->vendor_labels_cache[$cache_key])) {
      return $this->vendor_labels_cache[$cache_key];
    }

    global $wpdb;
    $vendor_labels = $this->get_vendor_match_labels($vendor_user_id);

    if (empty($vendor_labels)) {
      $this->vendor_labels_cache[$cache_key] = [];
      return [];
    }

    // ── PASO 1: obtener todos los shop_order con sus metas de vendedor ────────
    // Se usan las metas explícitas con prioridad estricta. Esto evita que un
    // vendedor vea pedidos de otro por fallback de cliente o por post_author.
    $rows = $wpdb->get_results("
      SELECT
        p.ID AS order_id,
        MAX(CASE WHEN pm.meta_key = '_ripex_seller_id' THEN pm.meta_value END) AS seller_id,
        MAX(CASE WHEN pm.meta_key = '_ripex_vendedor'  THEN pm.meta_value END) AS vendedor_label,
        MAX(CASE WHEN pm.meta_key = '_customer_user'   THEN pm.meta_value END) AS customer_user
      FROM {$wpdb->posts} p
      LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
        AND pm.meta_key IN ('_ripex_seller_id','_ripex_vendedor','_customer_user')
      WHERE p.post_type = 'shop_order'
      GROUP BY p.ID
    ");

    // ── PASO 2: clientes asignados a este vendedor para fallback ──────────────
    // Importante: los valores guardados en afreg_additional_42207 pueden venir
    // con mayúsculas, tildes o espacios. Por eso NO usamos IN SQL con labels
    // normalizadas; normalizamos en PHP y comparamos.
    $assigned_customer_ids = [];
    $customer_vendor_rows = $wpdb->get_results(
      "SELECT user_id, meta_value
       FROM {$wpdb->usermeta}
       WHERE meta_key = 'afreg_additional_42207' AND meta_value <> ''"
    );

    foreach ((array) $customer_vendor_rows as $cv) {
      $assigned_norm = $this->normalize_vendor_label((string) $cv->meta_value);
      if ($assigned_norm !== '' && in_array($assigned_norm, $vendor_labels, true)) {
        $assigned_customer_ids[(int) $cv->user_id] = true;
      }
    }

    // ── PASO 3: aplicar reglas de prioridad estricta ──────────────────────────
    $ids = [];
    foreach ((array) $rows as $row) {
      $order_id     = (int) $row->order_id;
      $seller_id    = trim((string) ($row->seller_id ?? ''));
      $vend_label   = $this->normalize_vendor_label((string) ($row->vendedor_label ?? ''));
      $customer_uid = (int) ($row->customer_user ?? 0);

      // Regla 1 — _ripex_seller_id tiene prioridad absoluta.
      if ($seller_id !== '') {
        if ((int) $seller_id === $vendor_user_id) {
          $ids[$order_id] = true;
        }
        continue;
      }

      // Regla 2 — _ripex_vendedor tiene segunda prioridad absoluta.
      if ($vend_label !== '') {
        if (in_array($vend_label, $vendor_labels, true)) {
          $ids[$order_id] = true;
        }
        continue;
      }

      // Regla 3 — fallback por cliente asignado SOLO si no hay vendedor explícito.
      if ($customer_uid > 0 && isset($assigned_customer_ids[$customer_uid])) {
        $ids[$order_id] = true;
      }
    }

    $result = array_keys($ids);
    $this->vendor_labels_cache[$cache_key] = $result;
    return $result;
  }

  /* =========================
   * AJAX: Orders list + detail
   * ========================= */
  public function ajax_get_orders() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    $user_id = get_current_user_id();

    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $scope  = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : '';
    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to   = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    $page      = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $per_page  = 30;

    $orders = [];
    $total = 0;
    $total_pages = 1;
    $vendor_order_ids = null;

    // Vendedores: SIEMPRE se filtra en backend. No se permite scope=all.
    // Evitamos depender de WC_Order_Query include/post__in porque en algunas
    // versiones/data stores puede ignorarse y mostrar pedidos globales.
    if ($role === 'ripex_vendedor') {
      $vendor_order_ids = array_map('intval', $this->get_vendor_order_ids($user_id));

      if (empty($vendor_order_ids)) {
        $this->json_ok([
          'orders'      => [],
          'role'        => $role,
          'scope'       => 'mine',
          'page'        => 1,
          'total_pages' => 0,
          'total'       => 0,
        ]);
        return;
      }

      global $wpdb;

      $where = [];
      $params = [];

      $placeholders = implode(',', array_fill(0, count($vendor_order_ids), '%d'));
      $where[] = "p.ID IN ($placeholders)";
      $params = array_merge($params, $vendor_order_ids);

      $where[] = "p.post_type = 'shop_order'";

      if ($status) {
        $status_key = (strpos($status, 'wc-') === 0) ? $status : 'wc-' . $status;
        $where[] = "p.post_status = %s";
        $params[] = $status_key;
      }

      if ($date_from) {
        $where[] = "p.post_date >= %s";
        $params[] = $date_from . ' 00:00:00';
      }

      if ($date_to) {
        $where[] = "p.post_date <= %s";
        $params[] = $date_to . ' 23:59:59';
      }

      $where_sql = implode(' AND ', $where);

      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $count_sql = "SELECT COUNT(1) FROM {$wpdb->posts} p WHERE $where_sql";
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

      $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;
      $offset = ($page - 1) * $per_page;

      $list_params = $params;
      $list_params[] = $per_page;
      $list_params[] = $offset;

      // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $list_sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE $where_sql ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d";
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
      $page_ids = $wpdb->get_col($wpdb->prepare($list_sql, ...$list_params));

      foreach ((array) $page_ids as $oid) {
        $order = wc_get_order((int) $oid);
        if ($order && is_a($order, 'WC_Order') && $this->vendor_mine_filter($order, $user_id)) {
          $orders[] = $order;
        }
      }

      $scope = 'mine';
    } else {
      // Admin y bodeguero mantienen WC_Order_Query normal.
      $args = [
        'limit'    => $per_page,
        'page'     => $page,
        'paginate' => true,
        'orderby'  => 'date',
        'order'    => 'DESC',
        'return'   => 'objects',
      ];

      if ($status) {
        $args['status'] = [ $status ];
      }

      if ($date_from) $args['date_created'] = '>=' . $date_from . ' 00:00:00';
      if ($date_from && $date_to) $args['date_created'] = $date_from . ' 00:00:00...' . $date_to . ' 23:59:59';
      elseif ($date_to) $args['date_created'] = '<=' . $date_to . ' 23:59:59';

      $q      = new WC_Order_Query($args);
      $result = $q->get_orders();

      if (is_object($result) && isset($result->orders)) {
        $orders      = (array) $result->orders;
        $total       = (int)   $result->total;
        $total_pages = (int)   $result->max_num_pages;
      } else {
        $orders      = is_array($result) ? $result : [];
        $total       = count($orders);
        $total_pages = 1;
      }
    }

    $rows = [];
    foreach ($orders as $o) {
      if (!$o || !is_a($o, 'WC_Order')) continue;

      // Doble seguridad: un vendedor nunca recibe pedidos fuera de su scope,
      // incluso si una consulta previa cambiara en WooCommerce.
      if ($role === 'ripex_vendedor' && !$this->vendor_mine_filter($o, $user_id)) {
        continue;
      }

      $cid = $this->get_customer_user_id_from_order($o);
      $af  = $this->get_customer_afreg($o, $cid);

      if ($search) {
        $hay = strtolower(
          '#' . $o->get_id() . ' ' .
          $o->get_formatted_billing_full_name() . ' ' .
          $o->get_billing_company() . ' ' .
          $o->get_billing_email() . ' ' .
          ($af['rut'] ?? '') . ' ' .
          ($af['razon_social'] ?? '') . ' ' .
          ($af['giro'] ?? '') . ' ' .
          ($af['vendedor'] ?? '') . ' ' .
          $o->get_payment_method_title()
        );
        if (strpos($hay, strtolower($search)) === false) continue;
      }

      $shipping_title = '';
      $shipping_items = $o->get_items('shipping');
      if (!empty($shipping_items)) {
        $first = current($shipping_items);
        if ($first && method_exists($first, 'get_method_title')) {
          $shipping_title = $this->clean_transport_label((string) $first->get_method_title());
        }
      }

      $total_order = $o->get_total();
      $currency    = $o->get_currency();
      if ($role === 'ripex_bodeguero') { $total_order = null; $currency = null; }

      $razon    = $af['razon_social'] ?: $o->get_billing_company();
      $can_edit = $this->can_edit_order($o, $role, $user_id);

      $rows[] = [
        'id'           => $o->get_id(),
        'number'       => $o->get_order_number(),
        'date'         => $o->get_date_created() ? $o->get_date_created()->date_i18n('d/m/y H:i') : '',
        'customer'     => trim($o->get_formatted_billing_full_name()) ?: $o->get_billing_company(),
        'company'      => $razon,
        'rut'          => $af['rut'] ?? '',
        'status'       => $o->get_status(),
        'status_label' => wc_get_order_status_name($o->get_status()),
        'payment'      => $o->get_payment_method_title(),
        'vendedor'     => $af['vendedor'] ?? '',
        'can_edit'     => $can_edit,
        'exported'     => $this->is_order_exported($o),
        'total'        => $total_order,
        'currency'     => $currency,
        'shipping'     => $shipping_title,
      ];
    }

    $this->json_ok([
      'orders'      => $rows,
      'role'        => $role,
      'scope'       => ($role === 'ripex_vendedor') ? 'mine' : ($scope ?: 'all'),
      'page'        => $page,
      'total_pages' => $total_pages,
      'total'       => $total,
    ]);
  }

  public function ajax_get_order() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) $this->json_err('Pedido inválido.');

    $order = wc_get_order($order_id);
    if (!$order) $this->json_err('Pedido no encontrado.');

    $items = [];
    foreach ($order->get_items() as $item) {
      if (!is_a($item,'WC_Order_Item_Product')) continue;
      $product = $item->get_product();
      $sku = $product ? $product->get_sku() : '';
      $stock = $product ? $product->get_stock_quantity() : null;
      $qty = (int) $item->get_quantity();
      $line_total = (float) $item->get_total();
      $unit_price = $qty > 0 ? ($line_total / $qty) : 0;

      $row = [
        'product_id' => $product ? $product->get_id() : 0,
        'name'       => $item->get_name(),
        'sku'        => $sku,
        'qty'        => $qty,
        'stock'      => $stock,
        'unit_price' => $unit_price,
        'line_total' => $line_total,
      ];
      if ($role !== 'ripex_bodeguero') $row['total'] = $line_total;
      $items[] = $row;
    }

    $shipping_title = '';
    $shipping_key = '';
    $shipping_items = $order->get_items('shipping');
    if (!empty($shipping_items)) {
      $first = current($shipping_items);
      if ($first && method_exists($first,'get_method_title')) $shipping_title = $this->clean_transport_label((string)$first->get_method_title());
      if ($first) {
        $method_id = method_exists($first, 'get_method_id') ? (string) $first->get_method_id() : '';
        $instance_id = method_exists($first, 'get_instance_id') ? (int) $first->get_instance_id() : 0;
        if ($method_id !== '') $shipping_key = $method_id . ':' . $instance_id;
      }
    }

    $billing_lines = $this->clean_address_lines($order->get_formatted_billing_address());
    $shipping_lines = $this->clean_address_lines($order->get_formatted_shipping_address());
    if (empty($shipping_lines)) $shipping_lines = $billing_lines;

    $cid = $this->get_customer_user_id_from_order($order);
    $af = $this->get_customer_afreg($order, $cid);

    $data = [
      'id'=>$order->get_id(),
      'number'=>$order->get_order_number(),
      'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y H:i') : '',
      'status'=>$order->get_status(),
      'status_label'=>wc_get_order_status_name($order->get_status()),
      'payment'=>$order->get_payment_method_title(),
      'payment_method_id'=>$order->get_payment_method(),
      'shipping'=>$shipping_title,
      'shipping_key'=>$shipping_key,
      'customer_id'=>(int) $order->get_customer_id(),
      'billing'=>[
        'name'=>$order->get_formatted_billing_full_name(),
        'email'=>$order->get_billing_email(),
        'phone'=>$order->get_billing_phone(),
        'first_name'=>$order->get_billing_first_name(),
        'last_name'=>$order->get_billing_last_name(),
        'country'=>$order->get_billing_country(),
        'address_1'=>$order->get_billing_address_1(),
        'city'=>$order->get_billing_city(),
        'state'=>$order->get_billing_state(),
        'postcode'=>$order->get_billing_postcode(),
        'address_lines'=>$billing_lines,
      ],
      'shipping_address_lines'=>$shipping_lines,
      'afreg'=>$af,
      'items'=>$items,
      'notes'=>$order->get_customer_note(),
      'can_export'=>in_array($role, ['ripex_bodeguero','ripex_admin','ripex_vendedor'], true),
      'can_edit'=>$this->can_edit_order($order, $role, get_current_user_id()),
      'exported'=>$this->is_order_exported($order),
    ];

    if ($role !== 'ripex_bodeguero') {
      $data['totals'] = [
        'subtotal'=>(float)$order->get_subtotal(),
        'shipping'=>(float)$order->get_shipping_total(),
        'discount'=>(float)$order->get_discount_total(),
        'total'=>(float)$order->get_total(),
        'currency'=>$order->get_currency(),
      ];
    }

    // Plazo crédito guardado en el pedido
    $ct = $this->order_meta_text($order, '_ripex_credit_term');
    $data['credit_term'] = is_string($ct) ? trim($ct) : '';

    $this->json_ok(['order'=>$data,'role'=>$role]);
  }

  /* =========================
   * AJAX: Inventory
   * ========================= */

  private function normalize_text_key($value) {
    $value = is_string($value) ? trim(wp_strip_all_tags($value)) : '';
    if ($value === '') return '';
    $value = remove_accents($value);
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]/', '', $value);
  }

  private function credit_checkout_context() {
    if (is_admin() && !wp_doing_ajax()) return false;

    if (function_exists('is_checkout') && is_checkout()) return true;

    // Checkout clásico: update_order_review / checkout suelen pasar por wc-ajax.
    if (isset($_REQUEST['wc-ajax'])) {
      $wc_ajax = sanitize_text_field(wp_unslash($_REQUEST['wc-ajax']));
      if (in_array($wc_ajax, ['update_order_review', 'checkout'], true)) return true;
    }

    // Algunos flujos del checkout de WooCommerce Blocks usan REST.
    if (defined('REST_REQUEST') && REST_REQUEST) return true;

    // Durante AJAX de WooCommerce también puede necesitar evaluarse el gateway.
    if (wp_doing_ajax()) return true;

    return false;
  }

  private function checkout_posted_billing_email() {
    $email = '';

    // En update_order_review de WooCommerce clásico los campos vienen serializados
    // dentro de post_data, no como $_POST['billing_email'] directo.
    if (isset($_POST['post_data'])) {
      $post_data = wp_unslash($_POST['post_data']);
      if (is_string($post_data) && $post_data !== '') {
        $parsed = [];
        parse_str($post_data, $parsed);
        if (!empty($parsed['billing_email'])) {
          $email = sanitize_email($parsed['billing_email']);
        }
      }
    }

    if (!$email && isset($_POST['billing_email'])) {
      $email = sanitize_email(wp_unslash($_POST['billing_email']));
    }

    if (!$email && function_exists('WC') && WC()->customer && method_exists(WC()->customer, 'get_billing_email')) {
      $email = sanitize_email((string) WC()->customer->get_billing_email());
    }

    return ($email && is_email($email)) ? $email : '';
  }

  private function credit_user_id_from_checkout_context() {
    $current_user_id = (int) get_current_user_id();

    // Si un admin/shop manager está probando el checkout con el correo de un cliente,
    // permitimos resolver el crédito por billing_email. Esto evita que el admin sin
    // crédito bloquee visualmente el método durante pruebas internas.
    $posted_email = $this->checkout_posted_billing_email();
    $posted_user_id = 0;
    if ($posted_email) {
      $posted_user = get_user_by('email', $posted_email);
      if ($posted_user) $posted_user_id = (int) $posted_user->ID;
    }

    if ($current_user_id) {
      if (
        $posted_user_id &&
        $posted_user_id !== $current_user_id &&
        (current_user_can('manage_woocommerce') || current_user_can('manage_options') || current_user_can('ripex_admin'))
      ) {
        return $posted_user_id;
      }

      return $current_user_id;
    }

    // RIPEX no permite compra anónima. Por seguridad no habilitamos crédito a usuarios
    // no logueados solo por escribir un correo en checkout.
    return 0;
  }

  private function credit_term_raw($user_id = 0) {
    $user_id = $user_id ? (int)$user_id : get_current_user_id();
    if (!$user_id) return '';
    $term = get_user_meta($user_id, 'afreg_additional_46135', true);
    return is_string($term) ? trim($term) : '';
  }

  private function is_credit_term_active($term) {
    $term = is_string($term) ? trim($term) : '';
    if ($term === '') return false;

    $key = $this->normalize_text_key($term);
    if ($key === '') return false;

    $inactive = ['desactivado','sincredito','nocredito','noaplica','noaplicable','ninguno','none','false','0'];
    return !in_array($key, $inactive, true);
  }

  private function current_user_has_credit_enabled() {
    $user_id = $this->credit_user_id_from_checkout_context();
    if (!$user_id) $user_id = get_current_user_id();
    return $this->is_credit_term_active($this->credit_term_raw($user_id));
  }

  private function credit_gateway_title_for_user($user_id = 0) {
    if (!$user_id) $user_id = $this->credit_user_id_from_checkout_context();
    if (!$user_id) $user_id = get_current_user_id();
    $term = $this->credit_term_raw($user_id);
    if (!$this->is_credit_term_active($term)) return 'Pago a crédito';
    return 'Pago a crédito (' . $term . ')';
  }

  public function ensure_cheque_gateway_registered($methods) {
    if (!is_array($methods)) return $methods;

    // WooCommerce normalmente ya registra WC_Gateway_Cheque. Esto es solo un respaldo
    // para instalaciones donde otro plugin haya filtrado el gateway antes del checkout.
    $has_cheque = false;
    foreach ($methods as $method) {
      if ($method === 'WC_Gateway_Cheque') {
        $has_cheque = true;
        break;
      }
      if (is_object($method) && isset($method->id) && $method->id === 'cheque') {
        $has_cheque = true;
        break;
      }
    }

    if (!$has_cheque) {
      $methods[] = 'WC_Gateway_Cheque';
    }

    return $methods;
  }

  private function get_cheque_gateway_instance() {
    if (!function_exists('WC') || !WC()->payment_gateways()) return null;

    $all_gateways = WC()->payment_gateways()->payment_gateways();
    if (isset($all_gateways['cheque'])) {
      return $all_gateways['cheque'];
    }

    if (!class_exists('WC_Gateway_Cheque') && function_exists('WC')) {
      $file = WC()->plugin_path() . '/includes/gateways/cheque/class-wc-gateway-cheque.php';
      if (file_exists($file)) {
        include_once $file;
      }
    }

    if (class_exists('WC_Gateway_Cheque')) {
      return new WC_Gateway_Cheque();
    }

    return null;
  }

  public function maybe_enable_cheque_gateway_settings_for_credit($settings) {
    if (!$this->credit_checkout_context()) return $settings;
    if (!$this->current_user_has_credit_enabled()) return $settings;

    if (!is_array($settings)) $settings = [];

    // Solo modifica la disponibilidad en runtime para checkout. No guarda cambios en WooCommerce.
    $settings['enabled'] = 'yes';
    $settings['title'] = $this->credit_gateway_title_for_user();
    $settings['description'] = 'Pago a crédito habilitado para tu cuenta.';
    $settings['instructions'] = 'Pedido ingresado con pago a crédito.';

    return $settings;
  }

  public function filter_credit_payment_gateway($gateways) {
    if (is_admin() && !wp_doing_ajax()) return $gateways;

    $credit_user_id = $this->credit_user_id_from_checkout_context();

    // RIPEX no permite compra anónima: el crédito solo aplica a usuarios registrados/identificables.
    if (!$credit_user_id) {
      if (isset($gateways['cheque'])) unset($gateways['cheque']);
      return $gateways;
    }

    $has_credit = $this->is_credit_term_active($this->credit_term_raw($credit_user_id));

    // Si el usuario no tiene plazo crédito activo, ocultar cheque/Pago a crédito.
    if (!$has_credit) {
      if (isset($gateways['cheque'])) unset($gateways['cheque']);
      return $gateways;
    }

    // Si WooCommerce no incluyó cheque en disponibles, recuperarlo/instanciarlo.
    // Esto cubre el caso en que "Pagos con cheque" esté desactivado globalmente
    // u otro filtro lo haya quitado antes.
    if (!isset($gateways['cheque'])) {
      $cheque_gateway = $this->get_cheque_gateway_instance();
      if ($cheque_gateway) {
        $gateways['cheque'] = $cheque_gateway;
      }
    }

    if (isset($gateways['cheque'])) {
      // Solo en runtime, no cambia la configuración global del gateway.
      $gateways['cheque']->enabled = 'yes';
      $gateways['cheque']->title = $this->credit_gateway_title_for_user($credit_user_id);
      $gateways['cheque']->method_title = 'Pago a crédito';
      $gateways['cheque']->description = 'Pago a crédito habilitado para tu cuenta.';
      if (property_exists($gateways['cheque'], 'instructions')) {
        $gateways['cheque']->instructions = 'Pedido ingresado con pago a crédito.';
      }
    }

    return $gateways;
  }

  public function rename_cheque_gateway_title($title, $gateway_id) {
    if ($gateway_id !== 'cheque') return $title;
    $user_id = $this->credit_user_id_from_checkout_context();
    if (!$user_id || !$this->is_credit_term_active($this->credit_term_raw($user_id))) return $title;
    return $this->credit_gateway_title_for_user($user_id);
  }

  public function rename_cheque_gateway_description($description, $gateway_id) {
    if ($gateway_id !== 'cheque') return $description;
    $user_id = $this->credit_user_id_from_checkout_context();
    if (!$user_id || !$this->is_credit_term_active($this->credit_term_raw($user_id))) return $description;
    return 'Pago a crédito habilitado para tu cuenta.';
  }

  public function set_checkout_credit_order_title($order, $data) {
    if (!$order || !is_a($order, 'WC_Order')) return;
    if ($order->get_payment_method() !== 'cheque') return;

    $customer_id = (int)$order->get_customer_id();
    $term = $this->credit_term_raw($customer_id ?: get_current_user_id());
    if (!$this->is_credit_term_active($term)) return;

    $order->update_meta_data('_ripex_credit_term', $term);
    $order->update_meta_data('_payment_method_title', 'Pago a crédito (' . $term . ')');
  }

  public function clean_transport_label_from_html($label, $context = null) {
    return $this->clean_transport_label($label, $context);
  }

  public function clean_transport_label($label, $context = null) {
    if (!is_string($label) || $label === '') return $label;

    $clean = $label;
    $patterns = [
      '/\s*-\s*Chile\b/i',
      '/\s*–\s*Chile\b/i',
      '/\s*—\s*Chile\b/i',
      '/\s*\|\s*Chile\b/i',
      '/\s*\(\s*Chile\s*\)/i',
    ];

    foreach ($patterns as $pattern) {
      $clean = preg_replace($pattern, '', $clean);
    }

    $clean = preg_replace('/\s{2,}/', ' ', $clean);
    return trim($clean);
  }

  private function inventory_categories() {
    $terms = get_terms([
      'taxonomy' => 'product_cat',
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
    ]);

    if (is_wp_error($terms) || empty($terms)) return [];

    $out = [];
    foreach ($terms as $term) {
      $out[] = [
        'id' => (int) $term->term_id,
        'slug' => (string) $term->slug,
        'name' => (string) $term->name,
      ];
    }
    return $out;
  }

  private function inventory_collect_ids($args) {
    $q = new WP_Query($args);
    $ids = [];
    foreach ((array) $q->posts as $id) {
      $ids[(int) $id] = true;
    }
    return $ids;
  }

  private function inventory_query_products($search = '', $category = '') {
    $search = trim((string) $search);
    $category = trim((string) $category);
    $ids = [];

    $base_args = [
      'post_type' => ['product', 'product_variation'],
      'post_status' => 'publish',
      'posts_per_page' => 500,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
      'no_found_rows' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ];

    if ($category !== '') {
      $base_args['post_type'] = ['product'];
      $base_args['tax_query'] = [[
        'taxonomy' => 'product_cat',
        'field' => 'slug',
        'terms' => $category,
      ]];
    }

    if ($search === '') {
      $ids = $ids + $this->inventory_collect_ids($base_args);
    } else {
      $sku_args = $base_args;
      $sku_args['meta_query'] = [[
        'key' => '_sku',
        'value' => $search,
        'compare' => 'LIKE',
      ]];
      $ids = $ids + $this->inventory_collect_ids($sku_args);

      $title_args = $base_args;
      $title_args['s'] = $search;
      $ids = $ids + $this->inventory_collect_ids($title_args);
    }

    $products = [];
    foreach (array_keys($ids) as $id) {
      $product = wc_get_product($id);
      if ($product && is_a($product, 'WC_Product')) $products[] = $product;
    }

    return $products;
  }

  private function inventory_sort_rows($rows, $orderby) {
    usort($rows, function($a, $b) use ($orderby) {
      switch ($orderby) {
        case 'name_desc':
          return strcasecmp($b['name'], $a['name']);
        case 'sku_asc':
          return strcasecmp($a['sku'], $b['sku']);
        case 'sku_desc':
          return strcasecmp($b['sku'], $a['sku']);
        case 'stock_asc':
          $sa = is_null($a['stock_raw']) ? PHP_INT_MAX : (int) $a['stock_raw'];
          $sb = is_null($b['stock_raw']) ? PHP_INT_MAX : (int) $b['stock_raw'];
          return $sa <=> $sb;
        case 'stock_desc':
          $sa = is_null($a['stock_raw']) ? PHP_INT_MIN : (int) $a['stock_raw'];
          $sb = is_null($b['stock_raw']) ? PHP_INT_MIN : (int) $b['stock_raw'];
          return $sb <=> $sa;
        case 'name_asc':
        default:
          return strcasecmp($a['name'], $b['name']);
      }
    });

    return $rows;
  }

  public function ajax_get_products() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
    $orderby = isset($_POST['orderby']) ? sanitize_text_field(wp_unslash($_POST['orderby'])) : 'name_asc';

    $products = $this->inventory_query_products($search, $category);
    $rows = [];

    foreach ($products as $p) {
      if (!$p || !is_a($p, 'WC_Product')) continue;

      $product_id = $p->get_id();
      $parent_id = $p->is_type('variation') ? $p->get_parent_id() : 0;
      $cat_source_id = $parent_id ?: $product_id;
      $category_names = [];
      $terms = get_the_terms($cat_source_id, 'product_cat');
      if (!is_wp_error($terms) && !empty($terms)) {
        foreach ($terms as $term) $category_names[] = $term->name;
      }

      $sku = $p->get_sku();
      $name = $p->get_name();
      if ($p->is_type('variation') && $parent_id) {
        $parent = wc_get_product($parent_id);
        if ($parent) $name = $parent->get_name() . ' — ' . wc_get_formatted_variation($p, true, false, true);
      }

      $stock = $p->get_stock_quantity();
      $status = $p->get_stock_status();

      $rows[] = [
        'id' => $product_id,
        'sku' => (string) $sku,
        'name' => wp_strip_all_tags($name),
        'stock' => is_null($stock) ? '' : (string) $stock,
        'stock_raw' => is_null($stock) ? null : (int) $stock,
        'status' => $status,
        'status_label' => $this->stock_label($status),
        'categories' => implode(', ', $category_names),
      ];
    }

    $rows = $this->inventory_sort_rows($rows, $orderby);

    $this->json_ok([
      'products' => array_values($rows),
      'categories' => $this->inventory_categories(),
    ]);
  }

  private function stock_label($status) {
    switch ($status) {
      case 'instock': return 'En stock';
      case 'outofstock': return 'Sin stock';
      case 'onbackorder': return 'Pedido pendiente';
      default: return $status;
    }
  }

  /* =========================
   * AJAX: Shipping methods
   * ========================= */
  public function ajax_get_shipping_methods() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $methods = [];
    if (class_exists('WC_Shipping_Zones')) {
      $zones = WC_Shipping_Zones::get_zones();
      $zones[] = ['id' => 0];

      foreach ($zones as $z) {
        $zone_id = isset($z['id']) ? (int)$z['id'] : 0;
        $zone = new WC_Shipping_Zone($zone_id);
        $zone_name = method_exists($zone, 'get_zone_name') ? $zone->get_zone_name() : 'Zona';

        foreach ((array)$zone->get_shipping_methods() as $m) {
          if (!is_object($m)) continue;
          if (!isset($m->enabled) || $m->enabled !== 'yes') continue;
          $method_id = isset($m->id) ? (string)$m->id : '';
          $instance_id = isset($m->instance_id) ? (int)$m->instance_id : 0;
          $title = method_exists($m, 'get_title') ? (string)$m->get_title() : $method_id;
          $methods[] = ['key' => $method_id . ':' . $instance_id, 'title' => $this->clean_transport_label($title), 'zone' => $zone_name];
        }
      }
    }

    $this->json_ok(['shipping_methods' => $methods]);
  }

  private function product_search_collect_ids($args) {
    $q = new WP_Query($args);
    $ids = [];
    if (!empty($q->posts)) {
      foreach ($q->posts as $id) {
        $ids[(int) $id] = true;
      }
    }
    return $ids;
  }

  private function product_search_category_term_ids($term) {
    $out = [];
    $terms = get_terms([
      'taxonomy' => 'product_cat',
      'hide_empty' => false,
      'search' => $term,
      'number' => 20,
    ]);
    if (!is_wp_error($terms) && !empty($terms)) {
      foreach ($terms as $t) $out[] = (int) $t->term_id;
    }
    return array_values(array_unique($out));
  }

  private function product_search_row($product) {
    if (!$product || !is_a($product, 'WC_Product')) return null;

    $product_id = $product->get_id();
    $parent_id = $product->is_type('variation') ? $product->get_parent_id() : 0;
    $cat_source_id = $parent_id ?: $product_id;
    $category_names = [];
    $terms = get_the_terms($cat_source_id, 'product_cat');
    if (!is_wp_error($terms) && !empty($terms)) {
      foreach ($terms as $term) $category_names[] = $term->name;
    }

    $name = $product->get_name();
    if ($product->is_type('variation') && $parent_id) {
      $parent = wc_get_product($parent_id);
      if ($parent) $name = $parent->get_name() . ' — ' . wc_get_formatted_variation($product, true, false, true);
    }

    $price_value = (float) wc_get_price_to_display($product);
    $stock_qty = $product->get_stock_quantity();

    return [
      'id' => $product_id,
      'sku' => (string) $product->get_sku(),
      'name' => wp_strip_all_tags($name),
      'stock' => is_null($stock_qty) ? '' : $stock_qty,
      'stock_status' => $product->get_stock_status(),
      'price' => $price_value,
      'price_html' => wp_strip_all_tags(wc_price($price_value)),
      'categories' => implode(', ', $category_names),
    ];
  }

  /* =========================
   * AJAX: Products search
   * ========================= */
  public function ajax_search_products() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
    $term = trim($term);
    if (mb_strlen($term) < 2) $this->json_ok(['products' => []]);

    $limit = isset($_POST['limit']) ? min(80, max(10, absint($_POST['limit']))) : 50;
    $ids = [];

    $base_args = [
      'post_type' => ['product', 'product_variation'],
      'post_status' => 'publish',
      'posts_per_page' => 120,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
      'no_found_rows' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ];

    // 1) SKU exacto tiene prioridad.
    $sku_exact_args = $base_args;
    $sku_exact_args['meta_query'] = [[
      'key' => '_sku',
      'value' => $term,
      'compare' => '=',
    ]];
    $ids = $ids + $this->product_search_collect_ids($sku_exact_args);

    // 2) SKU parcial.
    $sku_like_args = $base_args;
    $sku_like_args['meta_query'] = [[
      'key' => '_sku',
      'value' => $term,
      'compare' => 'LIKE',
    ]];
    $ids = $ids + $this->product_search_collect_ids($sku_like_args);

    // 3) Nombre / descripción.
    $title_args = $base_args;
    $title_args['s'] = $term;
    $ids = $ids + $this->product_search_collect_ids($title_args);

    // 4) Categoría relacionada.
    $cat_ids = $this->product_search_category_term_ids($term);
    if (!empty($cat_ids)) {
      $cat_args = $base_args;
      $cat_args['post_type'] = ['product'];
      $cat_args['tax_query'] = [[
        'taxonomy' => 'product_cat',
        'field' => 'term_id',
        'terms' => $cat_ids,
      ]];
      $ids = $ids + $this->product_search_collect_ids($cat_args);
    }

    $rows = [];
    foreach (array_keys($ids) as $product_id) {
      if (count($rows) >= $limit) break;
      $product = wc_get_product($product_id);
      $row = $this->product_search_row($product);
      if (!$row) continue;
      $rows[] = $row;
    }

    $this->json_ok(['products' => $rows]);
  }

  /* =========================
   * AJAX: Customers search + get
   * ========================= */
  public function ajax_search_customers() {
    $this->check_ajax_access();
    if (!$this->can_create_order()) $this->json_err('No autorizado para crear pedidos.');

    $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
    if (strlen($term) < 2) $this->json_ok(['customers' => []]);

    $role = $this->current_user_role_key();
    $current_user_id = get_current_user_id();

    $found = [];

    $q1 = new WP_User_Query([
      'search' => '*' . esc_attr($term) . '*',
      'search_columns' => ['user_email','display_name','user_login'],
      'number' => 50,
      'fields' => ['ID','display_name','user_email'],
    ]);
    foreach ((array) $q1->get_results() as $u) {
      $found[(int) $u->ID] = $u;
    }

    $q2 = new WP_User_Query([
      'number' => 50,
      'fields' => ['ID','display_name','user_email'],
      'meta_query' => [[
        'key' => 'afreg_additional_42210',
        'value' => $term,
        'compare' => 'LIKE',
      ]],
    ]);
    foreach ((array) $q2->get_results() as $u) {
      $found[(int) $u->ID] = $u;
    }

    $out = [];
    foreach ($found as $u) {
      $customer_id = (int) $u->ID;

      if ($role === 'ripex_vendedor' && !$this->customer_assigned_to_vendor($customer_id, $current_user_id)) {
        continue;
      }

      $rut = get_user_meta($customer_id, 'afreg_additional_42210', true);
      $rut = is_string($rut) ? trim($rut) : '';
      $out[] = [
        'id' => $customer_id,
        'name' => (string) $u->display_name,
        'email' => (string) $u->user_email,
        'rut' => $rut
      ];
    }

    $this->json_ok(['customers' => array_slice($out, 0, 20)]);
  }

  public function ajax_get_customer() {
    $this->check_ajax_access();
    if (!$this->can_create_order()) $this->json_err('No autorizado para crear pedidos.');

    $cid = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
    if (!$cid) $this->json_err('Cliente inválido.');

    $role = $this->current_user_role_key();
    $current_user_id = get_current_user_id();
    if ($role === 'ripex_vendedor' && !$this->customer_assigned_to_vendor($cid, $current_user_id)) {
      $this->json_err('Este cliente no está asociado a tu usuario vendedor.');
    }

    $u = get_user_by('id', $cid);
    if (!$u || empty($u->ID)) $this->json_err('Cliente no encontrado.');

    $data = [
      'id' => (int)$u->ID,
      'name' => (string)$u->display_name,
      'email' => (string)$u->user_email,
      'billing_first_name' => (string)get_user_meta($cid, 'billing_first_name', true),
      'billing_last_name'  => (string)get_user_meta($cid, 'billing_last_name', true),
      'billing_phone'      => (string)get_user_meta($cid, 'billing_phone', true),
      'billing_country'    => (string)get_user_meta($cid, 'billing_country', true),
      'billing_address_1'  => (string)get_user_meta($cid, 'billing_address_1', true),
      'billing_city'       => (string)get_user_meta($cid, 'billing_city', true),
      'billing_state'      => (string)get_user_meta($cid, 'billing_state', true),
      'billing_postcode'   => (string)get_user_meta($cid, 'billing_postcode', true),
    ];

    $af = $this->get_customer_afreg(null, $cid);
    $data['rut'] = $af['rut'];
    $data['razon_social'] = $af['razon_social'];
    $data['giro'] = $af['giro'];
    $data['vendedor'] = $af['vendedor'];

    $term = get_user_meta($cid, 'afreg_additional_46135', true);
    $term = is_string($term) ? trim($term) : '';
    $data['credit_term'] = $term;

    $this->json_ok(['customer' => $data]);
  }

  /* =========================
   * AJAX: Create order
   * ========================= */
  public function ajax_create_order() {
    $this->check_ajax_access();
    $this->require_wc_or_die();
    if (!$this->can_create_order()) $this->json_err('No autorizado para crear pedidos.');

    $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
    if (!$customer_id) $this->json_err('Debes seleccionar un cliente (no hay compra invitado).');

    $u = get_user_by('id', $customer_id);
    if (!$u || empty($u->ID)) $this->json_err('Cliente no encontrado.');

    $role = $this->current_user_role_key();
    $current_user_id = get_current_user_id();
    if ($role === 'ripex_vendedor' && !$this->customer_assigned_to_vendor($customer_id, $current_user_id)) {
      $this->json_err('No puedes crear pedidos para clientes no asociados a tu vendedor.');
    }

    $rut   = isset($_POST['rut_empresa']) ? sanitize_text_field(wp_unslash($_POST['rut_empresa'])) : '';
    $email = (string)$u->user_email;
    if ($rut === '') {
      $rut_meta = get_user_meta($customer_id, 'afreg_additional_42210', true);
      $rut = is_string($rut_meta) ? trim($rut_meta) : '';
    }

    $status = isset($_POST['order_status']) ? sanitize_text_field(wp_unslash($_POST['order_status'])) : 'on-hold';
    $status = in_array($status, ['pending','on-hold','processing'], true) ? $status : 'on-hold';

    $billing = [
      'first_name' => isset($_POST['billing_first_name']) ? sanitize_text_field(wp_unslash($_POST['billing_first_name'])) : '',
      'last_name'  => isset($_POST['billing_last_name']) ? sanitize_text_field(wp_unslash($_POST['billing_last_name'])) : '',
      'phone'      => isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '',
      'address_1'  => isset($_POST['billing_address_1']) ? sanitize_text_field(wp_unslash($_POST['billing_address_1'])) : '',
      'city'       => isset($_POST['billing_city']) ? sanitize_text_field(wp_unslash($_POST['billing_city'])) : '',
      'state'      => isset($_POST['billing_state']) ? sanitize_text_field(wp_unslash($_POST['billing_state'])) : '',
      'postcode'   => isset($_POST['billing_postcode']) ? sanitize_text_field(wp_unslash($_POST['billing_postcode'])) : '',
      'country'    => isset($_POST['billing_country']) ? sanitize_text_field(wp_unslash($_POST['billing_country'])) : 'CL',
    ];

    $rut   = isset($_POST['rut_empresa']) ? sanitize_text_field(wp_unslash($_POST['rut_empresa'])) : '';
    $razon = isset($_POST['razon_social']) ? sanitize_text_field(wp_unslash($_POST['razon_social'])) : '';
    $giro  = isset($_POST['giro']) ? sanitize_text_field(wp_unslash($_POST['giro'])) : '';
    $vend  = isset($_POST['vendedor']) ? sanitize_text_field(wp_unslash($_POST['vendedor'])) : '';

    $payment = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : 'bacs';
    $shipping_key = isset($_POST['shipping_key']) ? sanitize_text_field(wp_unslash($_POST['shipping_key'])) : '';
    $credit_term = isset($_POST['credit_term']) ? sanitize_text_field(wp_unslash($_POST['credit_term'])) : '';
    $note = isset($_POST['order_note']) ? sanitize_text_field(wp_unslash($_POST['order_note'])) : '';

    $items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items = json_decode($items_json, true);
    if (!is_array($items) || empty($items)) $this->json_err('Debes agregar al menos 1 producto.');

    try {
      self::$suspend_portal_order_emails = true;
      $order = wc_create_order(['customer_id'=>$customer_id, 'status'=>'pending', 'created_via'=>'ripex_portal']);

      $order->set_billing_email($email);
      $order->set_billing_first_name($billing['first_name']);
      $order->set_billing_last_name($billing['last_name']);
      $order->set_billing_phone($billing['phone']);
      $order->set_billing_address_1($billing['address_1']);
      $order->set_billing_city($billing['city']);
      $order->set_billing_state($billing['state']);
      $order->set_billing_postcode($billing['postcode']);
      $order->set_billing_country($billing['country']);
      if ($razon) $order->set_billing_company($razon);

      if ($note) $order->set_customer_note($note);

      if ($rut)   $order->update_meta_data('_ripex_rut_empresa', $rut);
      if ($razon) $order->update_meta_data('_ripex_razon_social', $razon);
      if ($giro)  $order->update_meta_data('_ripex_giro', $giro);
      if ($vend)  $order->update_meta_data('_ripex_vendedor', $vend);

      foreach ($items as $it) {
        $pid = isset($it['product_id']) ? absint($it['product_id']) : 0;
        $qty = isset($it['qty']) ? max(1, (int)$it['qty']) : 1;
        if (!$pid) continue;
        $product = wc_get_product($pid);
        if ($product) $order->add_product($product, $qty);
      }

      if ($shipping_key) {
        $title = '';
        if (class_exists('WC_Shipping_Zones')) {
          $zones = WC_Shipping_Zones::get_zones(); $zones[]=['id'=>0];
          foreach ($zones as $z) {
            $zone = new WC_Shipping_Zone((int)($z['id'] ?? 0));
            foreach ((array)$zone->get_shipping_methods() as $m) {
              if (!is_object($m)) continue;
              if (!isset($m->enabled) || $m->enabled !== 'yes') continue;
              $key = (string)$m->id . ':' . (int)$m->instance_id;
              if ($key === $shipping_key) { $title = method_exists($m,'get_title') ? (string)$m->get_title() : (string)$m->id; break 2; }
            }
          }
        }
        if ($title) {
          $ship = new WC_Order_Item_Shipping();
          $ship->set_method_title($title);
          $ship->set_method_id(explode(':', $shipping_key)[0]);
          if (method_exists($ship, 'set_instance_id')) $ship->set_instance_id((int)explode(':',$shipping_key)[1]);
          $ship->set_total(0);
          $order->add_item($ship);
        }
      }

      $gateways = (WC()->payment_gateways) ? WC()->payment_gateways->payment_gateways() : [];
      if (isset($gateways[$payment])) $order->set_payment_method($gateways[$payment]);
      else { $order->update_meta_data('_payment_method', $payment); $order->update_meta_data('_payment_method_title', $payment); }

      if ($payment === 'cheque') {
        $term = $credit_term;
        if ($term === '') {
          $term = get_user_meta($customer_id, 'afreg_additional_46135', true);
          $term = is_string($term) ? trim($term) : '';
        }
        if ($term && mb_strtolower($term) !== 'desactivado') {
          $order->update_meta_data('_ripex_credit_term', $term);
          $order->update_meta_data('_payment_method_title', 'Pago a crédito (' . $term . ')');
        } else {
          $order->update_meta_data('_payment_method_title', 'Pago a crédito');
        }
      }

      $order->update_meta_data('_ripex_seller_id', get_current_user_id());
      $order->update_meta_data('_ripex_portal_order', 'yes');
      if (method_exists($order, 'set_created_via')) $order->set_created_via('ripex_portal');

      $order->calculate_totals(true);
      $order->save();

      // Importante: cambiar el estado DESPUÉS de agregar productos.
      // Antes se creaba el pedido directamente en on-hold/processing, WooCommerce
      // podía intentar descontar stock cuando aún no existían líneas de producto.
      if ($status !== $order->get_status()) {
        $order->update_status($status, 'Pedido creado desde Portal RIPEX.');
      }
      $this->ensure_portal_order_stock_reduced($order, 'create_order');

      wp_update_post([
        'ID' => $order->get_id(),
        'post_author' => get_current_user_id(),
      ]);

      self::$suspend_portal_order_emails = false;
      $this->trigger_portal_order_emails($order);

      $this->json_ok(['order_id'=>$order->get_id(),'order_number'=>$order->get_order_number()]);
    } catch (Exception $e) {
      self::$suspend_portal_order_emails = false;
      $this->json_err('No se pudo crear el pedido.', ['detail'=>$e->getMessage()]);
    }
  }

  /* =========================
   * AJAX: Export CSV
   * ========================= */


  public function ajax_update_order() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    $user_id = get_current_user_id();

    if (!in_array($role, ['ripex_admin', 'ripex_vendedor'], true)) $this->json_err('No autorizado para editar pedidos.');

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) $this->json_err('Pedido inválido.');

    $order = wc_get_order($order_id);
    if (!$order) $this->json_err('Pedido no encontrado.');

    if (!$this->can_edit_order($order, $role, $user_id)) $this->json_err('No tienes permisos para editar este pedido.');

    $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
    if (!$customer_id) $this->json_err('Debes seleccionar un cliente.');

    $u = get_user_by('id', $customer_id);
    if (!$u || empty($u->ID)) $this->json_err('Cliente no encontrado.');

    $rut   = isset($_POST['rut_empresa']) ? sanitize_text_field(wp_unslash($_POST['rut_empresa'])) : '';
    $email = (string) $u->user_email;
    if ($rut === '') {
      $rut_meta = get_user_meta($customer_id, 'afreg_additional_42210', true);
      $rut = is_string($rut_meta) ? trim($rut_meta) : '';
    }
    $status = isset($_POST['order_status']) ? sanitize_text_field(wp_unslash($_POST['order_status'])) : $order->get_status();
    $status = in_array($status, ['pending','on-hold','processing'], true) ? $status : $order->get_status();

    $billing = [
      'first_name' => isset($_POST['billing_first_name']) ? sanitize_text_field(wp_unslash($_POST['billing_first_name'])) : '',
      'last_name'  => isset($_POST['billing_last_name']) ? sanitize_text_field(wp_unslash($_POST['billing_last_name'])) : '',
      'phone'      => isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '',
      'address_1'  => isset($_POST['billing_address_1']) ? sanitize_text_field(wp_unslash($_POST['billing_address_1'])) : '',
      'city'       => isset($_POST['billing_city']) ? sanitize_text_field(wp_unslash($_POST['billing_city'])) : '',
      'state'      => isset($_POST['billing_state']) ? sanitize_text_field(wp_unslash($_POST['billing_state'])) : '',
      'postcode'   => isset($_POST['billing_postcode']) ? sanitize_text_field(wp_unslash($_POST['billing_postcode'])) : '',
      'country'    => isset($_POST['billing_country']) ? sanitize_text_field(wp_unslash($_POST['billing_country'])) : 'CL',
    ];

    $razon = isset($_POST['razon_social']) ? sanitize_text_field(wp_unslash($_POST['razon_social'])) : '';
    $giro  = isset($_POST['giro']) ? sanitize_text_field(wp_unslash($_POST['giro'])) : '';
    $vend  = isset($_POST['vendedor']) ? sanitize_text_field(wp_unslash($_POST['vendedor'])) : '';

    $payment = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : 'bacs';
    $shipping_key = isset($_POST['shipping_key']) ? sanitize_text_field(wp_unslash($_POST['shipping_key'])) : '';
    $credit_term = isset($_POST['credit_term']) ? sanitize_text_field(wp_unslash($_POST['credit_term'])) : '';
    $note = isset($_POST['order_note']) ? sanitize_text_field(wp_unslash($_POST['order_note'])) : '';

    $items_json = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
    $items = json_decode($items_json, true);
    if (!is_array($items) || empty($items)) $this->json_err('Debes agregar al menos 1 producto.');

    try {
      // Si el pedido fue manejado por el portal y ya tenía stock descontado,
      // primero devolvemos el stock anterior antes de reemplazar líneas.
      // Esto evita descuadres al editar cantidades/productos desde el portal.
      if ($this->portal_order_stock_reduced($order) && $order->get_meta('_ripex_portal_stock_managed') === 'yes') {
        $this->restore_portal_order_stock($order, 'update_order_before_replace');
        $order = wc_get_order($order_id);
      }

      // remove current line items and shipping
      foreach ($order->get_items() as $item_id => $item) {
        $order->remove_item($item_id);
      }
      foreach ($order->get_items('shipping') as $item_id => $item) {
        $order->remove_item($item_id);
      }

      if (method_exists($order, 'set_customer_id')) $order->set_customer_id($customer_id);
      $order->set_status($status);
      $order->set_billing_email($email);
      $order->set_billing_first_name($billing['first_name']);
      $order->set_billing_last_name($billing['last_name']);
      $order->set_billing_phone($billing['phone']);
      $order->set_billing_address_1($billing['address_1']);
      $order->set_billing_city($billing['city']);
      $order->set_billing_state($billing['state']);
      $order->set_billing_postcode($billing['postcode']);
      $order->set_billing_country($billing['country']);
      $order->set_customer_note($note);
      if ($razon) $order->set_billing_company($razon);

      $order->update_meta_data('_ripex_rut_empresa', $rut);
      $order->update_meta_data('_ripex_razon_social', $razon);
      $order->update_meta_data('_ripex_giro', $giro);
      $order->update_meta_data('_ripex_vendedor', $vend);

      foreach ($items as $it) {
        $pid = isset($it['product_id']) ? absint($it['product_id']) : 0;
        $qty = isset($it['qty']) ? max(1, (int) $it['qty']) : 1;
        if (!$pid) continue;
        $product = wc_get_product($pid);
        if ($product) $order->add_product($product, $qty);
      }

      if ($shipping_key) {
        $title = '';
        if (class_exists('WC_Shipping_Zones')) {
          $zones = WC_Shipping_Zones::get_zones(); $zones[] = ['id'=>0];
          foreach ($zones as $z) {
            $zone = new WC_Shipping_Zone((int) ($z['id'] ?? 0));
            foreach ((array) $zone->get_shipping_methods() as $m) {
              if (!is_object($m)) continue;
              if (!isset($m->enabled) || $m->enabled !== 'yes') continue;
              $key = (string) $m->id . ':' . (int) $m->instance_id;
              if ($key === $shipping_key) { $title = method_exists($m,'get_title') ? $this->clean_transport_label((string) $m->get_title()) : (string) $m->id; break 2; }
            }
          }
        }
        if ($title) {
          $ship = new WC_Order_Item_Shipping();
          $ship->set_method_title($title);
          $ship->set_method_id(explode(':', $shipping_key)[0]);
          if (method_exists($ship, 'set_instance_id')) $ship->set_instance_id((int) explode(':', $shipping_key)[1]);
          $ship->set_total(0);
          $order->add_item($ship);
        }
      }

      $gateways = (WC()->payment_gateways) ? WC()->payment_gateways->payment_gateways() : [];
      if (isset($gateways[$payment])) $order->set_payment_method($gateways[$payment]);
      else {
        $order->update_meta_data('_payment_method', $payment);
        $order->update_meta_data('_payment_method_title', $payment);
      }

      if ($payment === 'cheque') {
        $term = $credit_term;
        if ($term === '') {
          $term = get_user_meta($customer_id, 'afreg_additional_46135', true);
          $term = is_string($term) ? trim($term) : '';
        }
        if ($term && mb_strtolower($term) !== 'desactivado') {
          $order->update_meta_data('_ripex_credit_term', $term);
          $order->update_meta_data('_payment_method_title', 'Pago a crédito (' . $term . ')');
        } else {
          $order->update_meta_data('_payment_method_title', 'Pago a crédito');
        }
      } else {
        $order->delete_meta_data('_ripex_credit_term');
      }

      $order->update_meta_data('_ripex_portal_order', 'yes');
      if (method_exists($order, 'set_created_via') && !$order->get_created_via()) $order->set_created_via('ripex_portal');
      $order->calculate_totals(true);
      $order->save();
      $this->ensure_portal_order_stock_reduced($order, 'update_order');

      $this->json_ok(['order_id' => $order->get_id(), 'order_number' => $order->get_order_number()]);
    } catch (Exception $e) {
      $this->json_err('No se pudo actualizar el pedido.', ['detail' => $e->getMessage()]);
    }
  }

  public function ajax_export_selected_orders() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    if (!in_array($role, ['ripex_bodeguero', 'ripex_admin', 'ripex_vendedor'], true)) $this->json_err('No autorizado para exportar.');

    $format = isset($_POST['format']) ? sanitize_text_field(wp_unslash($_POST['format'])) : 'csv';
    $ids_raw = isset($_POST['order_ids']) ? wp_unslash($_POST['order_ids']) : '[]';
    $order_ids = json_decode($ids_raw, true);
    if (!is_array($order_ids) || empty($order_ids)) $this->json_err('Debes seleccionar al menos un pedido.');

    $valid_orders = [];
    $csv_lines = [];
    $csv_lines[] = ['Pedido','Fecha','Rut','Razón Social','Giro','Dirección','Transporte','SKU','Producto','Cantidad','Stock'];

    foreach ($order_ids as $order_id) {
      $order_id = absint($order_id);
      if (!$order_id) continue;
      $order = wc_get_order($order_id);
      if (!$order) continue;

      if ($role === 'ripex_vendedor' && !$this->vendor_mine_filter($order, get_current_user_id())) continue;

      $bundle = $this->get_order_export_bundle($order);
      if (!$bundle) continue;
      $valid_orders[] = $bundle;

      foreach ((array) $bundle['items'] as $item) {
        $csv_lines[] = [
          $bundle['number'],
          $bundle['date'],
          $bundle['rut'],
          $bundle['razon_social'],
          $bundle['giro'],
          $bundle['direccion'],
          $item['transport'],
          $item['sku'],
          $item['product'],
          $item['qty'],
          is_null($item['stock']) ? '' : $item['stock'],
        ];
      }
    }

    if (empty($valid_orders)) $this->json_err('No hay pedidos válidos para exportar.');

    $this->mark_orders_exported(array_map(function($o){ return $o['id']; }, $valid_orders));

    if ($format === 'pdf') {
      $this->json_ok(['orders' => $valid_orders]);
    }

    $csv = '';
    foreach ($csv_lines as $row) {
      $escaped = array_map(function($v){
        $v = (string) $v;
        $v = str_replace('"', '""', $v);
        return '"' . $v . '"';
      }, $row);
      $csv .= implode(',', $escaped) . "
";
    }

    $this->json_ok([
      'filename' => 'pedidos-seleccionados-' . current_time('Ymd-His') . '.csv',
      'csv' => base64_encode($csv)
    ]);
  }

  public function ajax_export_my_orders() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    $user_id = get_current_user_id();
    if ($role !== 'ripex_vendedor') $this->json_err('No autorizado para exportar.');

    $q = new WC_Order_Query([
      'limit'    => -1,
      'paginate' => false,
      'orderby'  => 'date',
      'order'    => 'DESC',
      'return'   => 'objects',
    ]);
    $orders = $q->get_orders();
    if (!is_array($orders)) $orders = [];

    $lines = [];
    $lines[] = ['Pedido','Fecha','Cliente','Rut','Razón Social','Giro','Vendedor','Creador','Estado','Método de pago','Transporte','Dirección','Total'];

    foreach ($orders as $order) {
      if (!$order || !is_a($order, 'WC_Order')) continue;
      if (!$this->vendor_created_filter($order, $user_id)) continue;

      $cid = $this->get_customer_user_id_from_order($order);
      $af = $this->get_customer_afreg($order, $cid);
      $rut   = $af['rut'] ?: '';
      $razon = $af['razon_social'] ?: $order->get_billing_company();
      $giro  = $af['giro'] ?: '';
      $vend  = $af['vendedor'] ?: '';
      $creator = $this->get_order_creator_label($order);

      $shipping_title = '';
      $shipping_items = $order->get_items('shipping');
      if (!empty($shipping_items)) {
        $first = current($shipping_items);
        if ($first && method_exists($first, 'get_method_title')) $shipping_title = $this->clean_transport_label((string)$first->get_method_title());
      }

      $addr_lines = $this->clean_address_lines($order->get_formatted_shipping_address());
      if (empty($addr_lines)) $addr_lines = $this->clean_address_lines($order->get_formatted_billing_address());
      $addr_csv = $this->join_address_for_csv($addr_lines);

      $lines[] = [
        $order->get_order_number(),
        $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/y H:i') : '',
        trim($order->get_formatted_billing_full_name()) ?: $order->get_billing_company(),
        $rut,
        $razon,
        $giro,
        $vend,
        $creator,
        wc_get_order_status_name($order->get_status()),
        $order->get_payment_method_title(),
        $shipping_title,
        $addr_csv,
        $order->get_total(),
      ];
    }

    $csv = '';
    foreach ($lines as $row) {
      $escaped = array_map(function($v){
        $v = (string)$v;
        $v = str_replace('"', '""', $v);
        return '"' . $v . '"';
      }, $row);
      $csv .= implode(',', $escaped) . "\r\n";
    }

    $this->json_ok([
      'filename' => 'mis-pedidos-' . $user_id . '.csv',
      'csv'      => base64_encode($csv)
    ]);
  }


  public function ajax_get_reports() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    $current_user_id = get_current_user_id();
    if (!in_array($role, ['ripex_admin','ripex_vendedor'], true)) $this->json_err('No autorizado para ver reportes.');

    $date_from     = isset($_POST['date_from'])     ? sanitize_text_field(wp_unslash($_POST['date_from']))     : '';
    $date_to       = isset($_POST['date_to'])       ? sanitize_text_field(wp_unslash($_POST['date_to']))       : '';
    $force_refresh = !empty($_POST['force_refresh']);

    if (!$date_from) $date_from = gmdate('Y-m-d', strtotime('-30 days'));
    if (!$date_to)   $date_to   = gmdate('Y-m-d');

    // Caché solo para admin (10 min). Vendedor siempre recalcula porque su
    // alcance es acotado y sus datos cambian con frecuencia.
    // La clave incluye rol + user_id + rango para no mezclar datos entre usuarios.
    $use_cache     = ($role === 'ripex_admin') && !$force_refresh;
    $transient_key = 'ripex_rep_' . md5($role . '_' . $current_user_id . '_' . $date_from . '_' . $date_to);

    if ($use_cache) {
      $cached = get_transient($transient_key);
      if ($cached !== false) {
        $this->json_ok($cached);
        return;
      }
    }

    $start_ts = strtotime($date_from . ' 00:00:00');
    $end_ts   = strtotime($date_to . ' 23:59:59');
    $now_ts   = time();
    $inactive_cutoff = strtotime('-60 days', $now_ts);

    $revenue_statuses = ['processing', 'completed', 'on-hold'];

    // Pasar fecha al query: evita materializar pedidos fuera del rango en memoria.
    // El historial de clientes inactivos necesita todos los pedidos históricos de
    // revenue_statuses, por eso usamos solo date_from como límite inferior amplio
    // (los inactivos son quienes NO compraron en los últimos 60 días).
    $q = new WC_Order_Query([
      'limit'    => -1,
      'paginate' => false,
      'orderby'  => 'date',
      'order'    => 'ASC',
      'return'   => 'objects',
    ]);
    $orders = $q->get_orders();
    if (!is_array($orders)) $orders = [];

    $sales_by_day = [];
    $top_products = [];
    $seller_sales = [];
    $transport_sales = [];
    $status_counts = [];
    $company_sales = [];

    $total_revenue = 0.0;
    $orders_count = 0;
    $revenue_orders = 0;
    $items_sold = 0;
    $customer_keys_in_range = [];
    $sold_product_ids = [];

    $customer_history = [];

    foreach ($orders as $order) {
      if (!$order || !is_a($order, 'WC_Order')) continue;
      if ($role === 'ripex_vendedor' && !$this->vendor_mine_filter($order, $current_user_id)) continue;

      $dt = $order->get_date_created();
      if (!$dt) continue;

      $ts = $dt->getTimestamp();
      $status = $order->get_status();

      // customer history across all revenue statuses
      if (in_array($status, $revenue_statuses, true)) {
        $cid = (int) $order->get_customer_id();
        $email = trim((string) $order->get_billing_email());
        $billing_name = trim($order->get_formatted_billing_full_name());
        $customer_label = $billing_name !== '' ? $billing_name : ($email !== '' ? $email : ('Cliente #' . $order->get_id()));
        $hist_key = $cid ? ('id:' . $cid) : ($email !== '' ? ('email:' . strtolower($email)) : ('name:' . strtolower($customer_label)));
        if (!isset($customer_history[$hist_key])) {
          $customer_history[$hist_key] = [
            'name' => $customer_label,
            'email' => $email,
            'last_ts' => $ts,
            'orders' => 0,
            'revenue' => 0.0,
          ];
        }
        $customer_history[$hist_key]['orders'] += 1;
        $customer_history[$hist_key]['revenue'] += (float) $order->get_total();
        if ($ts > $customer_history[$hist_key]['last_ts']) $customer_history[$hist_key]['last_ts'] = $ts;
      }

      if ($ts < $start_ts || $ts > $end_ts) continue;

      $orders_count++;
      if (!isset($status_counts[$status])) {
        $status_counts[$status] = [
          'name' => wc_get_order_status_name($status),
          'count' => 0,
        ];
      }
      $status_counts[$status]['count'] += 1;

      $email = trim((string) $order->get_billing_email());
      $billing_name = trim($order->get_formatted_billing_full_name());
      $customer_label = $billing_name !== '' ? $billing_name : ($email !== '' ? $email : ('Cliente #' . $order->get_id()));
      $customer_key = $email !== '' ? strtolower($email) : strtolower($customer_label);
      $customer_keys_in_range[$customer_key] = true;

      if (!in_array($status, $revenue_statuses, true)) continue;

      $revenue_orders++;
      $total = (float) $order->get_total();
      $total_revenue += $total;

      $day_key = $dt->date_i18n('Y-m-d');
      if (!isset($sales_by_day[$day_key])) $sales_by_day[$day_key] = 0.0;
      $sales_by_day[$day_key] += $total;

      $cid = $this->get_customer_user_id_from_order($order);
      $af = $this->get_customer_afreg($order, $cid);

      $seller_label = trim((string) ($af['vendedor'] ?? ''));
      if ($seller_label === '') $seller_label = $this->get_order_creator_label($order);
      if ($seller_label === '') $seller_label = 'Sin asignar';
      if (!isset($seller_sales[$seller_label])) {
        $seller_sales[$seller_label] = ['name' => $seller_label, 'orders' => 0, 'revenue' => 0.0];
      }
      $seller_sales[$seller_label]['orders'] += 1;
      $seller_sales[$seller_label]['revenue'] += $total;

      $shipping_title = 'Sin transporte';
      $shipping_items = $order->get_items('shipping');
      if (!empty($shipping_items)) {
        $first = current($shipping_items);
        if ($first && method_exists($first, 'get_method_title')) {
          $title = trim((string) $first->get_method_title());
          if ($title !== '') $shipping_title = $title;
        }
      }
      if (!isset($transport_sales[$shipping_title])) {
        $transport_sales[$shipping_title] = ['name' => $shipping_title, 'orders' => 0, 'revenue' => 0.0];
      }
      $transport_sales[$shipping_title]['orders'] += 1;
      $transport_sales[$shipping_title]['revenue'] += $total;

      $company = trim((string) ($af['razon_social'] ?? ''));
      if ($company === '') $company = trim((string) $order->get_billing_company());
      if ($company === '') $company = $customer_label;
      if (!isset($company_sales[$company])) {
        $company_sales[$company] = ['name' => $company, 'orders' => 0, 'revenue' => 0.0];
      }
      $company_sales[$company]['orders'] += 1;
      $company_sales[$company]['revenue'] += $total;

      foreach ($order->get_items() as $item) {
        if (!is_a($item, 'WC_Order_Item_Product')) continue;

        $product = $item->get_product();
        $pid = $product ? (int) $product->get_id() : 0;
        if ($pid) $sold_product_ids[$pid] = true;

        $name = $item->get_name();
        $qty = (int) $item->get_quantity();
        $line_total = (float) $item->get_total();
        $items_sold += $qty;

        $stock = '';
        $sku = '';
        if ($product) {
          $stock_qty = $product->get_stock_quantity();
          $stock = is_null($stock_qty) ? '' : (int) $stock_qty;
          $sku = (string) $product->get_sku();
        }

        $key = $pid ?: md5($name . '|' . $sku);
        if (!isset($top_products[$key])) {
          $top_products[$key] = [
            'id' => $pid,
            'name' => $name,
            'sku' => $sku,
            'qty' => 0,
            'revenue' => 0.0,
            'stock' => $stock,
          ];
        }
        $top_products[$key]['qty'] += $qty;
        $top_products[$key]['revenue'] += $line_total;
        if ($stock !== '') $top_products[$key]['stock'] = $stock;
      }
    }

    // line chart fill
    $sales_series = [];
    $cursor = $start_ts;
    while ($cursor <= $end_ts) {
      $key = gmdate('Y-m-d', $cursor);
      $sales_series[] = [
        'date' => $key,
        'label' => gmdate('d/m', $cursor),
        'value' => isset($sales_by_day[$key]) ? (float) $sales_by_day[$key] : 0.0,
      ];
      $cursor = strtotime('+1 day', $cursor);
    }

    $top_products = array_values($top_products);
    usort($top_products, function($a, $b){
      if ($a['qty'] === $b['qty']) return $b['revenue'] <=> $a['revenue'];
      return $b['qty'] <=> $a['qty'];
    });
    $top_products = array_slice($top_products, 0, 10);

    $seller_sales = array_values($seller_sales);
    usort($seller_sales, function($a, $b){ return $b['revenue'] <=> $a['revenue']; });
    $seller_sales = array_slice($seller_sales, 0, 10);

    $transport_sales = array_values($transport_sales);
    usort($transport_sales, function($a, $b){ return $b['revenue'] <=> $a['revenue']; });
    $transport_sales = array_slice($transport_sales, 0, 10);

    $status_counts = array_values($status_counts);
    usort($status_counts, function($a, $b){ return $b['count'] <=> $a['count']; });

    $company_sales = array_values($company_sales);
    usort($company_sales, function($a, $b){ return $b['revenue'] <=> $a['revenue']; });
    $company_sales = array_slice($company_sales, 0, 10);

    // inventory scans
    $low_stock = [];
    $no_movement = [];
    $stock_total_visible = 0;

    $pq = new WC_Product_Query([
      'status' => 'publish',
      'limit' => -1,
      'return' => 'objects',
    ]);
    $products = $pq->get_products();
    if (!is_array($products)) $products = [];

    foreach ($products as $product) {
      if (!$product || !is_a($product, 'WC_Product')) continue;

      $stock_qty = $product->get_stock_quantity();
      if (!is_null($stock_qty)) $stock_total_visible += (int) $stock_qty;

      $manage = $product->get_manage_stock();
      if ($manage && !is_null($stock_qty) && (int) $stock_qty <= 10) {
        $low_stock[] = [
          'id' => $product->get_id(),
          'name' => $product->get_name(),
          'sku' => $product->get_sku(),
          'stock' => (int) $stock_qty,
          'status' => $product->get_stock_status(),
        ];
      }

      if (!isset($sold_product_ids[(int) $product->get_id()])) {
        $no_movement[] = [
          'id' => $product->get_id(),
          'name' => $product->get_name(),
          'sku' => $product->get_sku(),
          'stock' => is_null($stock_qty) ? '' : (int) $stock_qty,
        ];
      }
    }

    usort($low_stock, function($a, $b){
      return $a['stock'] <=> $b['stock'];
    });
    $low_stock = array_slice($low_stock, 0, 12);

    usort($no_movement, function($a, $b){
      $sa = is_numeric($a['stock']) ? (int) $a['stock'] : -1;
      $sb = is_numeric($b['stock']) ? (int) $b['stock'] : -1;
      return $sb <=> $sa;
    });
    $no_movement = array_slice($no_movement, 0, 12);

    // inactive customers
    $inactive_customers = [];
    foreach ($customer_history as $row) {
      if ($row['last_ts'] <= $inactive_cutoff) {
        $inactive_customers[] = [
          'name' => $row['name'],
          'email' => $row['email'],
          'days' => (int) floor(($now_ts - $row['last_ts']) / DAY_IN_SECONDS),
          'revenue' => (float) $row['revenue'],
          'orders' => (int) $row['orders'],
          'last_date' => gmdate('d/m/Y', $row['last_ts']),
        ];
      }
    }
    usort($inactive_customers, function($a, $b){
      if ($a['days'] === $b['days']) return $b['revenue'] <=> $a['revenue'];
      return $b['days'] <=> $a['days'];
    });
    $inactive_customers = array_slice($inactive_customers, 0, 12);

    $avg_ticket = $revenue_orders > 0 ? ($total_revenue / $revenue_orders) : 0.0;

    $result = [
      'filters' => [
        'date_from' => $date_from,
        'date_to'   => $date_to,
      ],
      'kpis' => [
        'revenue' => $total_revenue,
        'orders' => $orders_count,
        'avg_ticket' => $avg_ticket,
        'customers' => count($customer_keys_in_range),
        'items' => $items_sold,
        'stock' => $stock_total_visible,
        'active_sellers' => count($seller_sales),
        'low_stock_count' => count($low_stock),
      ],
      'charts' => [
        'sales_series' => $sales_series,
        'top_products' => $top_products,
        'seller_sales' => $seller_sales,
        'transport_sales' => $transport_sales,
        'status_counts' => $status_counts,
        'company_sales' => $company_sales,
      ],
      'tables' => [
        'low_stock' => $low_stock,
        'inactive_customers' => $inactive_customers,
        'no_movement' => $no_movement,
      ],
    ];

    // Guardar en caché solo para admin (vendedor siempre recalcula).
    if ($role === 'ripex_admin') {
      set_transient($transient_key, $result, 10 * MINUTE_IN_SECONDS);
    }

    $this->json_ok($result);
  }



  public function ajax_get_customers() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    if (!in_array($role, ['ripex_admin','ripex_vendedor'], true)) $this->json_err('No autorizado para ver clientes.');

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $city   = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
    $vendor_filter = isset($_POST['vendor']) ? sanitize_text_field(wp_unslash($_POST['vendor'])) : '';
    $user_id = get_current_user_id();

    $found = [];

    // Consulta principal liviana: clientes por nombre/email/login.
    $args = [
      'number'  => 300,
      'fields'  => ['ID','display_name','user_email'],
      'orderby' => 'display_name',
      'order'   => 'ASC',
    ];

    // Admin ve todos los clientes comerciales RIPEX: customer + mayoristas.
    // En RIPEX los clientes reales suelen estar en default_wholesaler.
    if ($role === 'ripex_admin') {
      $args['role__in'] = ['customer', 'default_wholesaler', 'super-mayorista'];
      $args['number'] = 1000;
    }

    if ($role === 'ripex_vendedor') {
      $labels = $this->get_vendor_match_labels($user_id);
      $or = ['relation' => 'OR'];
      foreach ($labels as $label) {
        $or[] = ['key' => 'afreg_additional_42207', 'value' => $label, 'compare' => '='];
      }
      if (count($or) === 1) $or[] = ['key'=>'afreg_additional_42207','value'=>'__none__','compare'=>'='];
      $args['meta_query'] = $or;
    }

    if ($search !== '') {
      $args['search'] = '*' . esc_attr($search) . '*';
      $args['search_columns'] = ['user_email','display_name','user_login'];
    }

    $q = new WP_User_Query($args);
    foreach ((array) $q->get_results() as $u) {
      $found[(int) $u->ID] = $u;
    }

    // Si se busca por RUT / razón social / giro, sumar coincidencias por meta.
    if ($search !== '') {
      $mq = ['relation'=>'OR'];
      foreach (['afreg_additional_42210','afreg_additional_42208','afreg_additional_42209'] as $key) {
        $mq[] = ['key'=>$key,'value'=>$search,'compare'=>'LIKE'];
      }
      $q2_args = ['number'=>300,'fields'=>['ID','display_name','user_email'],'meta_query'=>$mq];
      if ($role === 'ripex_admin') { $q2_args['role__in'] = ['customer', 'default_wholesaler', 'super-mayorista']; $q2_args['number'] = 1000; }
      $q2 = new WP_User_Query($q2_args);
      foreach ((array)$q2->get_results() as $u) $found[(int)$u->ID] = $u;
    }

    // Fallback admin: sumar usuarios con metadatos comerciales RIPEX aunque no tengan rol customer.
    if ($role === 'ripex_admin') {
      $fallback_mq = ['relation'=>'OR'];
      foreach (['afreg_additional_42210','afreg_additional_42208','afreg_additional_42209','afreg_additional_42207','billing_city'] as $key) {
        $fallback_mq[] = ['key'=>$key,'value'=>'','compare'=>'!='];
      }
      $q3 = new WP_User_Query(['number'=>1000,'fields'=>['ID','display_name','user_email'],'meta_query'=>$fallback_mq,'orderby'=>'display_name','order'=>'ASC']);
      foreach ((array)$q3->get_results() as $u) $found[(int)$u->ID] = $u;
    }

    $rows = [];
    $cities_available = [];
    $vendors_available = [];
    $city_filter_key = $this->normalize_text_key($city);
    $vendor_filter_key = $this->normalize_text_key($vendor_filter);

    foreach ($found as $u) {
      $cid = (int)$u->ID;
      if ($role === 'ripex_vendedor' && !$this->customer_assigned_to_vendor($cid, $user_id)) continue;

      $customer_city = $this->user_city_value($cid);
      $customer_city_key = $this->normalize_text_key($customer_city);
      if ($customer_city !== '') {
        $cities_available[$customer_city_key ?: $customer_city] = $customer_city;
      }

      if ($city_filter_key !== '' && $customer_city_key !== $city_filter_key) continue;

      $af = $this->get_customer_afreg(null, $cid);
      $customer_vendor = (string)($af['vendedor'] ?? '');
      $customer_vendor_key = $this->normalize_text_key($customer_vendor);
      if ($customer_vendor !== '') {
        $vendors_available[$customer_vendor_key ?: $customer_vendor] = $customer_vendor;
      }

      if ($role === 'ripex_admin' && $vendor_filter_key !== '' && $customer_vendor_key !== $vendor_filter_key) continue;

      $hay = strtolower($u->display_name . ' ' . $u->user_email . ' ' . $af['rut'] . ' ' . $af['razon_social'] . ' ' . $af['giro'] . ' ' . $customer_city . ' ' . $customer_vendor);
      if ($search !== '' && strpos($hay, strtolower($search)) === false) continue;
      $rows[] = [
        'id' => $cid,
        'name' => $u->display_name,
        'email' => $u->user_email,
        'phone' => get_user_meta($cid, 'billing_phone', true),
        'city' => $customer_city,
        'region' => $this->user_region_value($cid),
        'rut' => $af['rut'],
        'razon_social' => $af['razon_social'],
        'giro' => $af['giro'],
        'vendedor' => $customer_vendor,
      ];
    }

    usort($rows, function($a, $b){ return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); });
    $cities_available = array_values(array_filter(array_unique(array_values($cities_available))));
    usort($cities_available, function($a, $b){ return strcasecmp($a, $b); });
    $vendors_available = array_values(array_filter(array_unique(array_values($vendors_available))));
    usort($vendors_available, function($a, $b){ return strcasecmp($a, $b); });

    $this->json_ok(['customers'=>array_slice($rows,0,1000),'role'=>$role, 'count'=>count($rows), 'cities'=>$cities_available, 'vendors'=>$vendors_available]);
  }

  public function ajax_get_customer_history() {
    $this->check_ajax_access();
    $this->require_wc_or_die();
    $role = $this->current_user_role_key();
    if (!in_array($role, ['ripex_admin','ripex_vendedor'], true)) $this->json_err('No autorizado.');

    $cid = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
    if (!$cid) $this->json_err('Cliente inválido.');
    if ($role === 'ripex_vendedor' && !$this->customer_assigned_to_vendor($cid, get_current_user_id())) $this->json_err('Cliente no asociado a tu vendedor.');

    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to   = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';

    $u = get_user_by('id', $cid);
    if (!$u) $this->json_err('Cliente no encontrado.');
    $af = $this->get_customer_afreg(null, $cid);

    $args = [
      'limit'       => -1,
      'customer_id' => $cid,
      'status'      => ['processing','completed','on-hold'],
      'orderby'     => 'date',
      'order'       => 'DESC',
      'return'      => 'objects',
    ];

    if ($date_from && $date_to) {
      $args['date_created'] = $date_from . ' 00:00:00...' . $date_to . ' 23:59:59';
    } elseif ($date_from) {
      $args['date_created'] = '>=' . $date_from . ' 00:00:00';
    } elseif ($date_to) {
      $args['date_created'] = '<=' . $date_to . ' 23:59:59';
    }

    $orders = wc_get_orders($args);
    if (!is_array($orders)) $orders = [];

    $total = 0.0;
    $orders_count = 0;
    $products = [];
    $months = [];
    $years = [];
    $categories = [];
    $order_rows = [];

    $now = current_time('timestamp');
    $current_month = wp_date('Y-m', $now);
    $previous_month = wp_date('Y-m', strtotime('-1 month', $now));
    $current_year = wp_date('Y', $now);
    $previous_year = wp_date('Y', strtotime('-1 year', $now));

    foreach ($orders as $order) {
      if (!$order || !is_a($order,'WC_Order')) continue;
      $orders_count++;
      $amount = (float)$order->get_total();
      $total += $amount;
      $dt = $order->get_date_created();
      $mkey = $dt ? $dt->date_i18n('Y-m') : 'sin-fecha';
      $ykey = $dt ? $dt->date_i18n('Y') : 'sin-fecha';

      if (!isset($months[$mkey])) $months[$mkey] = ['label'=>$mkey,'orders'=>0,'revenue'=>0.0];
      if (!isset($years[$ykey])) $years[$ykey] = ['label'=>$ykey,'orders'=>0,'revenue'=>0.0];
      $months[$mkey]['orders']++;
      $months[$mkey]['revenue'] += $amount;
      $years[$ykey]['orders']++;
      $years[$ykey]['revenue'] += $amount;

      $order_items = [];
      foreach ($order->get_items() as $item) {
        if (!is_a($item,'WC_Order_Item_Product')) continue;
        $product = $item->get_product();
        $pid = $product ? (int)$product->get_id() : 0;
        $sku = $product ? (string)$product->get_sku() : '';
        $qty = (int)$item->get_quantity();
        $line_total = (float)$item->get_total();
        $key = $pid ?: md5($item->get_name().'|'.$sku);

        if (!isset($products[$key])) $products[$key] = ['name'=>$item->get_name(),'sku'=>$sku,'qty'=>0,'revenue'=>0.0];
        $products[$key]['qty'] += $qty;
        $products[$key]['revenue'] += $line_total;

        $order_items[] = [
          'name' => $item->get_name(),
          'sku' => $sku,
          'qty' => $qty,
          'revenue' => $line_total,
        ];

        if ($product) {
          $terms = get_the_terms($product->get_id(), 'product_cat');
          if (!is_wp_error($terms) && $terms) {
            foreach ($terms as $term) {
              if (!isset($categories[$term->name])) $categories[$term->name] = ['name'=>$term->name,'qty'=>0,'revenue'=>0.0];
              $categories[$term->name]['qty'] += $qty;
              $categories[$term->name]['revenue'] += $line_total;
            }
          }
        }
      }

      $order_rows[] = [
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'date' => $dt ? $dt->date_i18n('d/m/Y H:i') : '',
        'status' => $order->get_status(),
        'status_label' => wc_get_order_status_name($order->get_status()),
        'payment' => $order->get_payment_method_title(),
        'total' => $amount,
        'items' => $order_items,
      ];
    }

    $products = array_values($products); usort($products, function($a,$b){return $b['qty'] <=> $a['qty'];});
    $categories = array_values($categories); usort($categories, function($a,$b){return $b['qty'] <=> $a['qty'];});
    krsort($months); krsort($years);

    $month_values = [
      'current' => $months[$current_month] ?? ['label'=>$current_month,'orders'=>0,'revenue'=>0.0],
      'previous' => $months[$previous_month] ?? ['label'=>$previous_month,'orders'=>0,'revenue'=>0.0],
    ];
    $year_values = [
      'current' => $years[$current_year] ?? ['label'=>$current_year,'orders'=>0,'revenue'=>0.0],
      'previous' => $years[$previous_year] ?? ['label'=>$previous_year,'orders'=>0,'revenue'=>0.0],
    ];

    $month_values['delta'] = [
      'orders' => (int)$month_values['current']['orders'] - (int)$month_values['previous']['orders'],
      'revenue' => (float)$month_values['current']['revenue'] - (float)$month_values['previous']['revenue'],
    ];
    $year_values['delta'] = [
      'orders' => (int)$year_values['current']['orders'] - (int)$year_values['previous']['orders'],
      'revenue' => (float)$year_values['current']['revenue'] - (float)$year_values['previous']['revenue'],
    ];

    $display_name = trim((string)$u->display_name);
    $razon_social = $af['razon_social'] ?: get_user_meta($cid, 'billing_company', true);
    $customer_label = trim($display_name . ' · ' . $razon_social . ' · ' . ($af['rut'] ?? ''));

    $this->json_ok([
      'customer'=>[
        'id'=>$cid,
        'name'=>$display_name,
        'label'=>$customer_label,
        'email'=>$u->user_email,
        'phone'=>get_user_meta($cid,'billing_phone',true),
        'city'=>$this->user_city_value($cid),
        'region'=>$this->user_region_value($cid),
        'rut'=>$af['rut'],
        'razon_social'=>$af['razon_social'],
        'giro'=>$af['giro'],
        'vendedor'=>$af['vendedor']
      ],
      'filters' => ['date_from'=>$date_from, 'date_to'=>$date_to],
      'summary'=>['orders'=>$orders_count,'revenue'=>$total,'avg'=>$orders_count?($total/$orders_count):0],
      'comparisons'=>['month'=>$month_values,'year'=>$year_values],
      'orders'=>array_slice($order_rows,0,40),
      'products'=>array_slice($products,0,30),
      'categories'=>array_slice($categories,0,20),
      'months'=>array_values($months),
      'years'=>array_values($years),
    ]);
  }

  private function estimate_cart_last_activity_ts($session_expiry) {
    $session_expiry = (int)$session_expiry;
    if ($session_expiry <= 0) return 0;

    // WooCommerce usa expiración de sesión; no siempre guarda updated_at.
    // Se estima última actividad restando la vida estándar de sesión (48h).
    $lifetime = (int) apply_filters('ripex_portal_cart_session_lifetime', 48 * HOUR_IN_SECONDS);
    $last = $session_expiry - $lifetime;
    if ($last <= 0 || $last > current_time('timestamp') + DAY_IN_SECONDS) $last = $session_expiry;
    return $last;
  }

  private function parse_wc_session_row($row) {
    $data = maybe_unserialize($row->session_value ?? '');
    if (!is_array($data)) return null;
    $cart = $data['cart'] ?? [];
    if (is_string($cart)) $cart = maybe_unserialize($cart);
    if (!is_array($cart) || empty($cart)) return null;
    $customer = $data['customer'] ?? [];
    if (is_string($customer)) $customer = maybe_unserialize($customer);
    if (!is_array($customer)) $customer = [];

    $customer_id = 0;
    if (isset($customer['id'])) $customer_id = absint($customer['id']);
    if (!$customer_id && isset($row->session_key) && ctype_digit((string)$row->session_key)) $customer_id = absint($row->session_key);

    $items = [];
    $total = 0.0;
    $count = 0;
    foreach ($cart as $cart_item) {
      if (!is_array($cart_item)) continue;
      $pid = isset($cart_item['product_id']) ? absint($cart_item['product_id']) : 0;
      $vid = isset($cart_item['variation_id']) ? absint($cart_item['variation_id']) : 0;
      $qty = isset($cart_item['quantity']) ? (int)$cart_item['quantity'] : 0;
      $product = wc_get_product($vid ?: $pid);
      if (!$product || $qty <= 0) continue;
      $price = (float) wc_get_price_to_display($product);
      $items[] = [
        'product_id'=>$product->get_id(),
        'name'=>$product->get_name(),
        'sku'=>$product->get_sku(),
        'qty'=>$qty,
        'price'=>$price,
        'subtotal'=>$price*$qty,
        'stock'=>is_null($product->get_stock_quantity()) ? '' : (int)$product->get_stock_quantity(),
      ];
      $total += $price*$qty;
      $count += $qty;
    }
    if (empty($items)) return null;

    $name = 'Cliente sin identificar';
    $email = '';
    if ($customer_id) {
      $u = get_user_by('id',$customer_id);
      if ($u) { $name = $u->display_name; $email = $u->user_email; }
    }
    if (!$email && !empty($customer['email'])) $email = (string)$customer['email'];
    if (!empty($customer['first_name']) || !empty($customer['last_name'])) $name = trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')) ?: $name;

    $last_activity_ts = $this->estimate_cart_last_activity_ts((int)$row->session_expiry);

    return [
      'session_id'=>(int)$row->session_id,
      'session_key'=>(string)$row->session_key,
      'customer_id'=>$customer_id,
      'customer_name'=>$name,
      'customer_email'=>$email,
      'items'=>$items,
      'items_count'=>$count,
      'total'=>$total,
      'expiry'=>(int)$row->session_expiry,
      'last_activity_ts'=>$last_activity_ts,
      'last_activity_label'=>$last_activity_ts ? wp_date('d-m-Y, H:i:s', $last_activity_ts) : '',
    ];
  }

  /**
   * Devuelve los IDs de usuarios (clientes) asignados a un vendedor.
   * Consulta directa a usermeta para evitar N+1 al iterar carritos.
   * Se memoiza por request en vendor_labels_cache.
   */
  private function get_vendor_customer_ids($vendor_user_id) {
    $vendor_user_id = (int) $vendor_user_id;
    $cache_key = 'customer_ids_' . $vendor_user_id;
    if (isset($this->vendor_labels_cache[$cache_key])) {
      return $this->vendor_labels_cache[$cache_key];
    }

    $labels = $this->get_vendor_match_labels($vendor_user_id);
    if (empty($labels)) {
      $this->vendor_labels_cache[$cache_key] = [];
      return [];
    }

    global $wpdb;
    $rows = $wpdb->get_results(
      "SELECT DISTINCT user_id, meta_value FROM {$wpdb->usermeta}
       WHERE meta_key = 'afreg_additional_42207' AND meta_value <> ''"
    );

    $ids = [];
    foreach ((array)$rows as $row) {
      $assigned = $this->normalize_vendor_label((string)$row->meta_value);
      if ($assigned !== '' && in_array($assigned, $labels, true)) {
        $ids[(int)$row->user_id] = true;
      }
    }

    $result = array_keys($ids);
    $this->vendor_labels_cache[$cache_key] = $result;
    return $result;
  }

  /**
   * Resuelve el nombre más legible para un carrito dado el customer_id.
   * Prioridad: razón social > billing name > display_name > email > "Cliente #ID"
   */
  private function resolve_cart_customer_name($customer_id, $cart_data = []) {
    $customer_id = (int) $customer_id;
    if (!$customer_id) {
      return $cart_data['customer_name'] ?? 'Cliente sin identificar';
    }

    // 1. Razón social (campo comercial RIPEX).
    $razon = get_user_meta($customer_id, 'afreg_additional_42208', true);
    if (!empty(trim((string) $razon))) return trim((string) $razon);

    // 2. Nombre de facturación.
    $first = trim((string) get_user_meta($customer_id, 'billing_first_name', true));
    $last  = trim((string) get_user_meta($customer_id, 'billing_last_name', true));
    $billing_name = trim("$first $last");
    if ($billing_name !== '') return $billing_name;

    // 3. display_name del usuario.
    $u = get_user_by('id', $customer_id);
    if ($u && !empty($u->display_name)) return $u->display_name;

    // 4. Email del usuario.
    if ($u && !empty($u->user_email)) return $u->user_email;

    // 5. Fallback.
    return 'Cliente #' . $customer_id;
  }



  private function cart_payment_methods_for_customer($customer_id) {
    $customer_id = (int)$customer_id;
    $methods = [
      ['id'=>'bacs', 'title'=>'Transferencia bancaria directa'],
    ];

    $term = $customer_id ? $this->credit_term_raw($customer_id) : '';
    if ($this->is_credit_term_active($term)) {
      $methods[] = ['id'=>'cheque', 'title'=>'Pago a crédito (' . $term . ')', 'credit_term'=>$term];
    }

    return $methods;
  }

  private function add_shipping_item_to_order_from_key($order, $shipping_key) {
    $shipping_key = sanitize_text_field((string)$shipping_key);
    if (!$order || !is_a($order, 'WC_Order') || $shipping_key === '') return false;

    $title = '';
    $method_id = '';
    $instance_id = 0;

    if (class_exists('WC_Shipping_Zones')) {
      $zones = WC_Shipping_Zones::get_zones();
      $zones[] = ['id'=>0];
      foreach ($zones as $z) {
        $zone = new WC_Shipping_Zone((int) ($z['id'] ?? 0));
        foreach ((array) $zone->get_shipping_methods() as $m) {
          if (!is_object($m)) continue;
          if (!isset($m->enabled) || $m->enabled !== 'yes') continue;
          $key = (string) $m->id . ':' . (int) $m->instance_id;
          if ($key === $shipping_key) {
            $method_id = (string)$m->id;
            $instance_id = (int)$m->instance_id;
            $title = method_exists($m,'get_title') ? $this->clean_transport_label((string)$m->get_title()) : $method_id;
            break 2;
          }
        }
      }
    }

    if (!$title || !$method_id) return false;

    $ship = new WC_Order_Item_Shipping();
    $ship->set_method_title($title);
    $ship->set_method_id($method_id);
    if (method_exists($ship, 'set_instance_id')) $ship->set_instance_id($instance_id);
    $ship->set_total(0);
    $order->add_item($ship);
    return true;
  }

  public function ajax_get_carts() {
    $this->check_ajax_access(); $this->require_wc_or_die();
    $role = $this->current_user_role_key();
    if (!in_array($role, ['ripex_admin','ripex_vendedor'], true)) $this->json_err('No autorizado.');

    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_sessions';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) $this->json_ok(['carts' => []]);

    // Filtrar en SQL: solo sesiones cuya session_key sea un número entero > 0
    // (WooCommerce asigna el user_id como session_key para usuarios registrados).
    // Esto descarta sesiones anónimas antes de deserializar, evitando procesar
    // cientos de sesiones que nunca podrían cursarse como pedido desde el portal.
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT session_id, session_key, session_value, session_expiry
       FROM {$table}
       WHERE session_expiry > %d
         AND session_key REGEXP '^[0-9]+$'
         AND CAST(session_key AS UNSIGNED) > 0
       ORDER BY session_expiry DESC
       LIMIT 150",
      time()
    ));

    // Para vendedor: pre-calcular IDs de clientes asignados (evita N+1).
    $vendor_customer_ids = null;
    if ($role === 'ripex_vendedor') {
      $vendor_customer_ids = $this->get_vendor_customer_ids(get_current_user_id());
    }

    $out = [];
    foreach ((array) $rows as $row) {
      $cart = $this->parse_wc_session_row($row);
      if (!$cart || !$cart['customer_id']) continue;

      if ($vendor_customer_ids !== null) {
        if (!in_array($cart['customer_id'], $vendor_customer_ids, true)) continue;
      }

      // Asegurar nombre legible con prioridad definida.
      $cart['customer_name'] = $this->resolve_cart_customer_name($cart['customer_id'], $cart);
      $af = $this->get_customer_afreg(null, (int)$cart['customer_id']);
      $cart['customer_rut'] = $af['rut'];
      $cart['customer_razon_social'] = $af['razon_social'];
      $cart['customer_giro'] = $af['giro'];
      $cart['customer_vendedor'] = $af['vendedor'];
      $cart['customer_city'] = $this->user_city_value((int)$cart['customer_id']);
      $cart['customer_region'] = $this->user_region_value((int)$cart['customer_id']);
      $cart['payment_methods'] = $this->cart_payment_methods_for_customer((int)$cart['customer_id']);
      $cart['can_credit'] = count($cart['payment_methods']) > 1;

      $out[] = $cart;
    }

    $this->json_ok(['carts' => array_slice($out, 0, 100)]);
  }

  public function ajax_close_cart() {
    $this->check_ajax_access();
    $this->require_wc_or_die();
    $role = $this->current_user_role_key();
    if (!in_array($role, ['ripex_admin','ripex_vendedor'], true)) $this->json_err('No autorizado.');

    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    if (!$session_id) $this->json_err('Carrito inválido.');

    $shipping_key = isset($_POST['shipping_key']) ? sanitize_text_field(wp_unslash($_POST['shipping_key'])) : '';
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : 'bacs';
    if ($shipping_key === '') $this->json_err('Debes seleccionar un transporte.');
    if (!in_array($payment_method, ['bacs','cheque'], true)) $payment_method = 'bacs';

    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_sessions';
    $row = $wpdb->get_row($wpdb->prepare("SELECT session_id, session_key, session_value, session_expiry FROM {$table} WHERE session_id = %d", $session_id));
    if (!$row) $this->json_err('Carrito no encontrado.');
    $cart = $this->parse_wc_session_row($row);
    if (!$cart) $this->json_err('Carrito sin productos.');

    $customer_id = (int)$cart['customer_id'];
    if (!$customer_id) $this->json_err('El carrito no tiene cliente identificado.');
    if ($role === 'ripex_vendedor' && !$this->customer_assigned_to_vendor($customer_id, get_current_user_id())) $this->json_err('Cliente no asociado a tu vendedor.');

    if ($payment_method === 'cheque' && !$this->is_credit_term_active($this->credit_term_raw($customer_id))) {
      $this->json_err('El cliente no tiene pago a crédito activo.');
    }

    $u = get_user_by('id',$customer_id);
    $order = wc_create_order(['customer_id'=>$customer_id, 'created_via'=>'ripex_portal_cart']);
    if (is_wp_error($order) || !$order) $this->json_err('No se pudo crear el pedido desde el carrito.');

    foreach ($cart['items'] as $item) {
      $product = wc_get_product((int)$item['product_id']);
      if ($product) $order->add_product($product, max(1,(int)$item['qty']));
    }

    $billing = [
      'first_name'=>get_user_meta($customer_id,'billing_first_name',true) ?: ($u ? $u->display_name : ''),
      'last_name'=>get_user_meta($customer_id,'billing_last_name',true),
      'company'=>get_user_meta($customer_id,'billing_company',true),
      'email'=>$u ? $u->user_email : $cart['customer_email'],
      'phone'=>get_user_meta($customer_id,'billing_phone',true),
      'address_1'=>get_user_meta($customer_id,'billing_address_1',true),
      'address_2'=>get_user_meta($customer_id,'billing_address_2',true),
      'city'=>$this->user_city_value($customer_id),
      'state'=>$this->user_region_value($customer_id),
      'postcode'=>get_user_meta($customer_id,'billing_postcode',true),
      'country'=>'CL',
    ];
    $order->set_address($billing, 'billing');
    $order->set_address($billing, 'shipping');

    $af = $this->get_customer_afreg(null, $customer_id);
    $order->update_meta_data('_ripex_rut_empresa', $af['rut']);
    $order->update_meta_data('_ripex_razon_social', $af['razon_social']);
    $order->update_meta_data('_ripex_giro', $af['giro']);
    $order->update_meta_data('_ripex_vendedor', $af['vendedor']);
    if ($role === 'ripex_vendedor') $order->update_meta_data('_ripex_seller_id', get_current_user_id());
    $order->update_meta_data('_ripex_portal_order', 'yes');
    if (method_exists($order, 'set_created_via')) $order->set_created_via('ripex_portal_cart');

    if (!$this->add_shipping_item_to_order_from_key($order, $shipping_key)) {
      $this->json_err('No se pudo agregar el transporte seleccionado.');
    }

    $gateways = (WC()->payment_gateways) ? WC()->payment_gateways->payment_gateways() : [];
    if (isset($gateways[$payment_method])) {
      $order->set_payment_method($gateways[$payment_method]);
    } else {
      $order->update_meta_data('_payment_method', $payment_method);
      $order->update_meta_data('_payment_method_title', $payment_method);
    }

    if ($payment_method === 'cheque') {
      $term = $this->credit_term_raw($customer_id);
      $order->update_meta_data('_ripex_credit_term', $term);
      $order->update_meta_data('_payment_method_title', $this->credit_gateway_title_for_user($customer_id));
    }

    $order->calculate_totals();
    $order->update_status('on-hold', 'Pedido creado desde carrito activo en Portal RIPEX.');
    $order->save();
    $this->ensure_portal_order_stock_reduced($order, 'close_cart');

    $wpdb->delete($table, ['session_id'=>$session_id], ['%d']);
    $this->json_ok(['order_id'=>$order->get_id(),'order_number'=>$order->get_order_number()]);
  }

  /* =========================
   * Stock handling / Repair
   * ========================= */
  private function stock_reducing_order_statuses() {
    return ['on-hold', 'processing', 'completed'];
  }

  private function order_status_reduces_stock($order) {
    if (!$order || !is_a($order, 'WC_Order')) return false;
    return in_array($order->get_status(), $this->stock_reducing_order_statuses(), true);
  }

  private function portal_order_has_product_items($order) {
    if (!$order || !is_a($order, 'WC_Order')) return false;
    foreach ($order->get_items('line_item') as $item) {
      if (is_a($item, 'WC_Order_Item_Product') && (int)$item->get_quantity() > 0) return true;
    }
    return false;
  }

  private function portal_order_stock_reduced($order) {
    if (!$order || !is_a($order, 'WC_Order')) return false;
    $order_id = $order->get_id();
    try {
      $data_store = WC_Data_Store::load('order');
      if ($data_store && method_exists($data_store, 'get_stock_reduced')) {
        return (bool) $data_store->get_stock_reduced($order_id);
      }
    } catch (Exception $e) {}
    return get_post_meta($order_id, '_order_stock_reduced', true) === 'yes';
  }

  private function portal_set_order_stock_reduced($order, $reduced) {
    if (!$order || !is_a($order, 'WC_Order')) return;
    $order_id = $order->get_id();
    try {
      $data_store = WC_Data_Store::load('order');
      if ($data_store && method_exists($data_store, 'set_stock_reduced')) {
        $data_store->set_stock_reduced($order_id, (bool)$reduced);
        return;
      }
    } catch (Exception $e) {}
    update_post_meta($order_id, '_order_stock_reduced', $reduced ? 'yes' : 'no');
  }

  private function ensure_portal_order_stock_reduced($order, $source = '') {
    if (!$order || !is_a($order, 'WC_Order')) return false;
    if (!$this->order_status_reduces_stock($order)) return false;
    if (!$this->portal_order_has_product_items($order)) return false;

    $order_id = $order->get_id();

    // Si WooCommerce ya lo considera reducido, no descontamos otra vez.
    if (!$this->portal_order_stock_reduced($order)) {
      if (function_exists('wc_maybe_reduce_stock_levels')) {
        wc_maybe_reduce_stock_levels($order_id);
      } elseif (function_exists('wc_reduce_stock_levels')) {
        wc_reduce_stock_levels($order_id);
      }
    }

    $order = wc_get_order($order_id);
    if ($order && $this->portal_order_stock_reduced($order)) {
      $order->update_meta_data('_ripex_portal_stock_managed', 'yes');
      $order->update_meta_data('_ripex_portal_stock_checked_at', current_time('mysql'));
      if ($source) $order->update_meta_data('_ripex_portal_stock_checked_source', sanitize_key($source));
      $order->save();
      return true;
    }

    return false;
  }

  private function restore_portal_order_stock($order, $source = '') {
    if (!$order || !is_a($order, 'WC_Order')) return false;
    if (!$this->portal_order_stock_reduced($order)) return false;
    if (!function_exists('wc_increase_stock_levels')) return false;

    $order_id = $order->get_id();
    wc_increase_stock_levels($order_id);

    $order = wc_get_order($order_id);
    if ($order) {
      $order->update_meta_data('_ripex_portal_stock_restored_at', current_time('mysql'));
      if ($source) $order->update_meta_data('_ripex_portal_stock_restored_source', sanitize_key($source));
      $order->save();
    }
    return true;
  }

  public function register_stock_repair_page() {
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) return;
    add_management_page(
      'RIPEX Regularizar Stock',
      'RIPEX Regularizar Stock',
      'manage_woocommerce',
      'ripex-regularizar-stock',
      [$this, 'render_stock_repair_page']
    );
  }

  public function render_stock_repair_page() {
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) wp_die('No autorizado');
    $results = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ripex_stock_repair_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ripex_stock_repair_nonce'])), 'ripex_stock_repair')) {
      $ids_raw = isset($_POST['order_ids']) ? sanitize_textarea_field(wp_unslash($_POST['order_ids'])) : '';
      $confirm = !empty($_POST['confirm_no_stock_discount']);
      $order_ids = array_filter(array_map('absint', preg_split('/[\s,;]+/', $ids_raw)));
      $order_ids = array_values(array_unique($order_ids));

      if (!$confirm) {
        $results[] = ['type'=>'error','message'=>'Debes confirmar que esos pedidos no descontaron inventario antes de regularizar.'];
      } elseif (empty($order_ids)) {
        $results[] = ['type'=>'error','message'=>'Ingresa al menos un ID de pedido.'];
      } else {
        foreach ($order_ids as $order_id) {
          $order = wc_get_order($order_id);
          if (!$order) { $results[] = ['type'=>'error','message'=>'Pedido #' . $order_id . ': no encontrado.']; continue; }
          if (!$this->order_status_reduces_stock($order)) { $results[] = ['type'=>'warning','message'=>'Pedido #' . $order->get_order_number() . ': estado ' . $order->get_status() . ' no descuenta inventario.']; continue; }
          if (!$this->portal_order_has_product_items($order)) { $results[] = ['type'=>'warning','message'=>'Pedido #' . $order->get_order_number() . ': no tiene productos.']; continue; }

          // Uso manual: forzamos el flag a no reducido porque el usuario confirma que no descontó.
          $this->portal_set_order_stock_reduced($order, false);
          if (function_exists('wc_reduce_stock_levels')) {
            wc_reduce_stock_levels($order->get_id());
          } elseif (function_exists('wc_maybe_reduce_stock_levels')) {
            wc_maybe_reduce_stock_levels($order->get_id());
          }
          $order = wc_get_order($order->get_id());
          if ($order) {
            $order->update_meta_data('_ripex_portal_stock_managed', 'yes');
            $order->update_meta_data('_ripex_portal_stock_repaired_at', current_time('mysql'));
            $order->add_order_note('RIPEX: inventario regularizado manualmente desde herramienta del portal.');
            $order->save();
          }
          $results[] = ['type'=>'success','message'=>'Pedido #' . ($order ? $order->get_order_number() : $order_id) . ': stock regularizado.'];
        }
      }
    }

    echo '<div class="wrap"><h1>RIPEX Regularizar Stock</h1>';
    echo '<p>Usa esta herramienta solo para pedidos del portal que ya confirmaste que <strong>no descontaron inventario</strong>. No es automática para evitar descuentos duplicados.</p>';
    foreach ($results as $r) {
      $class = $r['type'] === 'success' ? 'notice-success' : ($r['type'] === 'warning' ? 'notice-warning' : 'notice-error');
      echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($r['message']) . '</p></div>';
    }
    echo '<form method="post" style="max-width:760px;background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:8px;">';
    wp_nonce_field('ripex_stock_repair', 'ripex_stock_repair_nonce');
    echo '<h2>Pedidos a regularizar</h2>';
    echo '<p><label for="order_ids"><strong>IDs de pedido</strong> separados por coma, espacio o salto de línea.</label></p>';
    echo '<textarea id="order_ids" name="order_ids" rows="6" style="width:100%;font-family:monospace;" placeholder="47137, 47110"></textarea>';
    echo '<p><label><input type="checkbox" name="confirm_no_stock_discount" value="1"> Confirmo que estos pedidos no descontaron inventario y deben descontarse ahora.</label></p>';
    echo '<p><button class="button button-primary">Regularizar stock de pedidos indicados</button></p>';
    echo '</form>';
    echo '<p><strong>Importante:</strong> desde esta versión, los pedidos nuevos del portal se crean primero con estado pendiente, luego se agregan los productos y recién después se cambia el estado para que WooCommerce descuente stock correctamente.</p>';
    echo '</div>';
  }

  private function export_order_rows_for_batch($order) {
    if (!$order || !is_a($order, 'WC_Order')) return [];

    $shipping_title = '';
    $shipping_items = $order->get_items('shipping');
    if (!empty($shipping_items)) {
      $first = current($shipping_items);
      if ($first && method_exists($first,'get_method_title')) $shipping_title = $this->clean_transport_label((string)$first->get_method_title());
    }

    $cid = $this->get_customer_user_id_from_order($order);
    $af = $this->get_customer_afreg($order, $cid);

    $rut   = $cid ? get_user_meta($cid, 'afreg_additional_42210', true) : '';
    $razon = $af['razon_social'] ?: $order->get_billing_company();
    $giro  = $af['giro'] ?: '';
    $vend  = $af['vendedor'] ?: '';

    $addr_lines = $this->clean_address_lines($order->get_formatted_shipping_address());
    if (empty($addr_lines)) $addr_lines = $this->clean_address_lines($order->get_formatted_billing_address());
    $addr_csv = $this->join_address_for_csv($addr_lines);

    $rows = [];
    foreach ($order->get_items() as $item) {
      if (!is_a($item,'WC_Order_Item_Product')) continue;
      $product = $item->get_product();
      $sku = $product ? $product->get_sku() : '';
      $stock = $product ? $product->get_stock_quantity() : '';

      $rows[] = [
        $order->get_order_number(),
        $order->get_date_created() ? $order->get_date_created()->date_i18n('d-m-Y H:i') : '',
        $rut,
        $vend,
        $razon,
        $giro,
        $addr_csv,
        $shipping_title,
        $sku,
        $item->get_name(),
        (int)$item->get_quantity(),
        is_null($stock) ? '' : $stock,
      ];
    }
    return $rows;
  }


  public function ajax_export_orders_by_date() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    if (!in_array($role, ['ripex_bodeguero','ripex_admin'], true)) $this->json_err('No autorizado para exportar.');

    $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $date_to   = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '';
    $only_pending_export = !empty($_POST['only_pending_export']);

    if (!$date_from || !$date_to) $this->json_err('Debes indicar fecha desde y hasta.');

    $start_ts = strtotime($date_from . ' 00:00:00');
    $end_ts   = strtotime($date_to . ' 23:59:59');
    if (!$start_ts || !$end_ts || $start_ts > $end_ts) $this->json_err('Rango de fechas inválido.');

    $q = new WC_Order_Query([
      'limit'    => -1,
      'paginate' => false,
      'orderby'  => 'date',
      'order'    => 'ASC',
      'return'   => 'objects',
    ]);
    $orders = $q->get_orders();
    if (!is_array($orders)) $orders = [];

    $lines = [];
    $lines[] = ['Pedido','Fecha','Rut','Vendedor','Razón Social','Giro','Dirección','Transporte','SKU','Producto','Cantidad','Stock'];

    $selected_orders = [];
    foreach ($orders as $order) {
      if (!$order || !is_a($order,'WC_Order')) continue;
      $dt = $order->get_date_created();
      if (!$dt) continue;
      $ts = $dt->getTimestamp();
      if ($ts < $start_ts || $ts > $end_ts) continue;

      $already_exported = (bool) $order->get_meta('_ripex_exported_to_bodega');
      if ($only_pending_export && $already_exported) continue;

      $rows = $this->export_order_rows_for_batch($order);
      if (!empty($rows)) {
        foreach ($rows as $r) $lines[] = $r;
        $selected_orders[] = $order;
      }
    }

    if (count($lines) <= 1) $this->json_err('No se encontraron pedidos para exportar en ese rango.');

    foreach ($selected_orders as $order) {
      $order->update_meta_data('_ripex_exported_to_bodega', 1);
      $order->save();
    }

    $csv = '';
    foreach ($lines as $row) {
      $escaped = array_map(function($v){
        $v = (string)$v;
        $v = str_replace('"', '""', $v);
        return '"' . $v . '"';
      }, $row);
      $csv .= implode(',', $escaped) . "\r\n";
    }

    $filename = 'pedidos-' . $date_from . '-a-' . $date_to . '.csv';
    $this->json_ok([
      'filename' => $filename,
      'csv' => base64_encode($csv),
      'count_orders' => count($selected_orders),
    ]);
  }

  public function ajax_export_order() {
    $this->check_ajax_access();
    $this->require_wc_or_die();

    $role = $this->current_user_role_key();
    if (!in_array($role, ['ripex_bodeguero','ripex_admin','ripex_vendedor'], true)) $this->json_err('No autorizado para exportar.');

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) $this->json_err('Pedido inválido.');

    $order = wc_get_order($order_id);
    if (!$order) $this->json_err('Pedido no encontrado.');

    $shipping_title = '';
    $shipping_items = $order->get_items('shipping');
    if (!empty($shipping_items)) {
      $first = current($shipping_items);
      if ($first && method_exists($first,'get_method_title')) $shipping_title = $this->clean_transport_label((string)$first->get_method_title());
    }

    $cid = $this->get_customer_user_id_from_order($order);
    $af = $this->get_customer_afreg($order, $cid);

    $rut   = $af['rut'] ?: '';
    $razon = $af['razon_social'] ?: $order->get_billing_company();
    $giro  = $af['giro'] ?: '';
    $vend  = $af['vendedor'] ?: '';

    $addr_lines = $this->clean_address_lines($order->get_formatted_shipping_address());
    if (empty($addr_lines)) $addr_lines = $this->clean_address_lines($order->get_formatted_billing_address());
    $addr_csv = $this->join_address_for_csv($addr_lines);

    $lines = [];
    $lines[] = ['Pedido','Fecha','Rut','Vendedor','Razón Social','Giro','Dirección','Transporte','SKU','Producto','Cantidad','Stock'];

    foreach ($order->get_items() as $item) {
      if (!is_a($item,'WC_Order_Item_Product')) continue;
      $product = $item->get_product();
      $sku = $product ? $product->get_sku() : '';
      $stock = $product ? $product->get_stock_quantity() : '';

      $lines[] = [
        $order->get_order_number(),
        $order->get_date_created() ? $order->get_date_created()->date_i18n('d-m-Y H:i') : '',
        $rut,
        $vend,
        $razon,
        $giro,
        $addr_csv,
        $shipping_title,
        $sku,
        $item->get_name(),
        (int)$item->get_quantity(),
        is_null($stock) ? '' : $stock,
      ];
    }

    $csv = '';
    foreach ($lines as $row) {
      $escaped = array_map(function($v){
        $v = (string)$v;
        $v = str_replace('"', '""', $v);
        return '"' . $v . '"';
      }, $row);
      $csv .= implode(',', $escaped) . "\r\n";
    }

    $this->mark_orders_exported([$order->get_id()]);
    $this->json_ok(['filename'=>'pedido-' . $order->get_order_number() . '.csv','csv'=>base64_encode($csv)]);
  }
}
