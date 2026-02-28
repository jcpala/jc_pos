<?php
if (!defined('ABSPATH')) exit;

class JC_REST_Register {

  public static function init() {
    add_action('rest_api_init', [self::class, 'routes']);
  }

  public static function routes() {

    // Bootstrap: menus + sizes + addons grouped by type
    register_rest_route('jc-pos/v1', '/menu', [
      'methods'  => 'GET',
      'callback' => [self::class, 'menu_bootstrap'],
      'permission_callback' => function () {
        return is_user_logged_in() && current_user_can('manage_woocommerce');
      },
    ]);

    // Menu products
    register_rest_route('jc-pos/v1', '/menu/(?P<id>\d+)', [
      'methods'  => 'GET',
      'callback' => [self::class, 'menu_products'],
      'permission_callback' => function () {
        return is_user_logged_in() && current_user_can('manage_woocommerce');
      },
      'args' => [
        'id' => [
          'required' => true,
          'validate_callback' => function($param) {
            return is_numeric($param) && (int)$param > 0;
          }
        ]
      ],
    ]);

    // Checkout
    register_rest_route('jc-pos/v1', '/checkout', [
      'methods'  => 'POST',
      'callback' => [self::class, 'checkout'],
      'permission_callback' => function () {
        return is_user_logged_in() && current_user_can('manage_woocommerce');
      },
      'args' => [
        'cart' => ['required' => true],
      ],
    ]);
  }

  /**
   * GET /jc-pos/v1/menu
   * Returns: menus, sizes, addons grouped by addon_type.
   * Expected addon_type values: TOPPING, SYRUP, OTHER (anything else will be included too).
   */
  public static function menu_bootstrap(WP_REST_Request $req) {
    global $wpdb;

    $t_menus  = $wpdb->prefix . 'jc_pos_menus';
    $t_sizes  = $wpdb->prefix . 'jc_size_rules';
    $t_addons = $wpdb->prefix . 'jc_addons';

    // Menus
    $menus = $wpdb->get_results("
      SELECT id, name, wc_category_id, image_id, is_active, sort_order
      FROM $t_menus
      WHERE is_active = 1
      ORDER BY sort_order ASC, id ASC
    ", ARRAY_A);

    foreach ($menus as &$m) {
      $m['image_url'] = !empty($m['image_id'])
        ? wp_get_attachment_image_url((int)$m['image_id'], 'thumbnail')
        : null;
    }
    unset($m);

    // Sizes
    $sizes = $wpdb->get_results("
      SELECT id, label, price_delta, is_default, is_active, sort_order
      FROM $t_sizes
      WHERE is_active = 1
      ORDER BY sort_order ASC, id ASC
    ", ARRAY_A);

    // Addons
    $addons = $wpdb->get_results("
      SELECT id, name, addon_type, price, is_active, sort_order
      FROM $t_addons
      WHERE is_active = 1
      ORDER BY addon_type ASC, sort_order ASC, id ASC
    ", ARRAY_A);

    // Group addons by type (ensure TOPPING/SYRUP/OTHER exist)
    $byType = [
      'TOPPING' => [],
      'SYRUP'   => [],
      'OTHER'   => [],
    ];

    foreach ($addons as $a) {
      $type = strtoupper(trim($a['addon_type'] ?? ''));
      if ($type === '') $type = 'OTHER';
      if (!isset($byType[$type])) $byType[$type] = [];
      $byType[$type][] = [
        'id'         => (int)$a['id'],
        'name'       => (string)$a['name'],
        'addon_type' => (string)$type,
        'price'      => (float)$a['price'],
        'sort_order' => (int)($a['sort_order'] ?? 0),
        'is_active'  => (int)($a['is_active'] ?? 1),
      ];
    }

    return rest_ensure_response([
      'menus'  => $menus,
      'sizes'  => $sizes,
      'addons' => $byType,
    ]);
  }

  /**
   * GET /jc-pos/v1/menu/{id}
   * Returns products assigned to a menu from jc_pos_menu_products.
   */
  public static function menu_products(WP_REST_Request $req) {
    if (!function_exists('wc_get_product')) {
      return new WP_Error('no_woo', 'WooCommerce not active', ['status' => 500]);
    }

    global $wpdb;

    $menu_id = (int)$req['id'];

    $t_menus = $wpdb->prefix . 'jc_pos_menus';
    $t_map   = $wpdb->prefix . 'jc_pos_menu_products';

    $menu = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM $t_menus WHERE id=%d", $menu_id),
      ARRAY_A
    );
    if (!$menu) {
      return new WP_Error('not_found', 'Menu not found', ['status' => 404]);
    }

    $rows = $wpdb->get_results(
      $wpdb->prepare("
        SELECT product_id, sort_order
        FROM $t_map
        WHERE menu_id=%d
        ORDER BY sort_order ASC, product_id ASC
      ", $menu_id),
      ARRAY_A
    );

    $products = [];
    foreach ($rows as $r) {
      $pid = (int)($r['product_id'] ?? 0);
      if ($pid <= 0) continue;

      $p = wc_get_product($pid);
      if (!$p) continue;

      $products[] = [
        'id'         => $pid,
        'name'       => $p->get_name(),
        'price'      => (float)$p->get_price(),
        'image'      => wp_get_attachment_image_url($p->get_image_id(), 'medium'),
        'sort_order' => (int)($r['sort_order'] ?? 0),
      ];
    }

    return rest_ensure_response([
      'menu'     => $menu,
      'products' => $products,
    ]);
  }

  /**
   * POST /jc-pos/v1/checkout
   * Creates invoice via JC_Invoice_Service and sends to MH via JC_MH_Sender_Service (best-effort)
   */
  public static function checkout(WP_REST_Request $req) {
    $body = $req->get_json_params();

    $cart = $body['cart'] ?? [];
    if (!is_array($cart) || empty($cart)) {
      return new WP_REST_Response(['success' => false, 'error' => 'Cart is empty'], 400);
    }

    $discount_type  = sanitize_text_field($body['discount_type'] ?? 'none');
    $discount_value = (float)($body['discount_value'] ?? 0);

    // Optional fee support (if your JS sends it)
    $fee_label = sanitize_text_field($body['fee_label'] ?? '');
    $fee_value = (float)($body['fee_value'] ?? 0);

    // Resolve register/store
    $register_id = (int)get_option('jc_current_register_id', 0);
    if ($register_id <= 0) {
      return new WP_REST_Response(['success' => false, 'error' => 'Register not configured (jc_current_register_id)'], 400);
    }

    global $wpdb;
    $t_registers = $wpdb->prefix . 'jc_registers';

    $store_id = (int)$wpdb->get_var(
      $wpdb->prepare("SELECT store_id FROM $t_registers WHERE id=%d", $register_id)
    );

    if ($store_id <= 0) {
      return new WP_REST_Response(['success' => false, 'error' => 'Store not found for register'], 400);
    }

    // Build items
    $items = [];
    foreach ($cart as $line) {
      $product_id = (int)($line['product_id'] ?? 0);
      $qty        = (float)($line['qty'] ?? 1);
      $unit_price = (float)($line['unit_price'] ?? 0);
      $name       = sanitize_text_field($line['name'] ?? 'Item');

      if ($product_id <= 0 || $qty <= 0) continue;

      $items[] = [
        'product_id'   => $product_id,
        'product_name' => $name,
        'quantity'     => $qty,
        'unit_price'   => $unit_price,
        'tax_rate'     => 13.0,
        'meta'         => $line['meta'] ?? null, // size/flavor/toppings
      ];
    }

    if (empty($items)) {
      return new WP_REST_Response(['success' => false, 'error' => 'No valid items'], 400);
    }

    // Invoice payload
    $payload = [
      'store_id'        => $store_id,
      'register_id'     => $register_id,
      'document_type'   => 'CONSUMIDOR_FINAL',
      'items'           => $items,
      'discount_type'   => $discount_type,
      'discount_value'  => $discount_value,
      'fee_label'       => $fee_label,
      'fee_value'       => $fee_value,
    ];

    if (!class_exists('JC_Invoice_Service')) {
      return new WP_REST_Response(['success' => false, 'error' => 'JC_Invoice_Service not loaded'], 500);
    }

    $result = JC_Invoice_Service::create_invoice($payload);
    if (empty($result['success'])) {
      return new WP_REST_Response(['success' => false, 'error' => $result['error'] ?? 'Create invoice failed'], 500);
    }

    $invoice_id = (int)$result['invoice_id'];

    // Best-effort MH send
    $mh = ['mh_status' => 'NOT_ATTEMPTED'];

    if (class_exists('JC_MH_Sender_Service')) {
      try {
        $mh = JC_MH_Sender_Service::send_one($invoice_id);
        if (!is_array($mh)) {
          $mh = ['mh_status' => 'FAILED', 'error' => 'send_one() returned non-array'];
        }
      } catch (Throwable $e) {
        error_log('[JC POS] MH send exception: ' . $e->getMessage());
        $mh = ['mh_status' => 'FAILED', 'error' => $e->getMessage()];
      }
    } else {
      $mh = ['mh_status' => 'FAILED', 'error' => 'JC_MH_Sender_Service not loaded'];
    }

    return rest_ensure_response([
      'success'       => true,
      'invoice_id'    => $invoice_id,
      'ticket_number' => $result['ticket_number'] ?? null,
      'mh'            => $mh,
      // If your invoice service returns DTE JSON, you can pass it through too:
      // 'dte' => $result['dte'] ?? null,
    ]);
  }
}