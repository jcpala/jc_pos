<?php
if (!defined('ABSPATH')) exit;

class JC_REST_Register {

  public static function init() {
    add_action('rest_api_init', [self::class, 'routes']);
  }

  public static function routes() {

    register_rest_route('jc-pos/v1', '/menu', [
      'methods'  => 'GET',
      'callback' => [self::class, 'menu_bootstrap'],
      'permission_callback' => function () {
        return is_user_logged_in() && current_user_can('manage_woocommerce');
      },
    ]);

    register_rest_route('jc-pos/v1', '/menu/(?P<id>\d+)', [
      'methods'  => 'GET',
      'callback' => [self::class, 'menu_products'],
      'permission_callback' => function () {
        return is_user_logged_in() && current_user_can('manage_woocommerce');
      },
      'args' => [
        'id' => [
          'required' => true,
          'validate_callback' => function ($param) {
            return is_numeric($param) && (int) $param > 0;
          }
        ]
      ],
    ]);

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
   */
  public static function menu_bootstrap(WP_REST_Request $req) {
    global $wpdb;

    $t_menus  = $wpdb->prefix . 'jc_pos_menus';
    $t_sizes  = $wpdb->prefix . 'jc_size_rules';
    $t_addons = $wpdb->prefix . 'jc_addons';

    $menus = $wpdb->get_results("
      SELECT id, name, wc_category_id, image_id, is_active, sort_order
      FROM $t_menus
      WHERE is_active = 1
      ORDER BY sort_order ASC, id ASC
    ", ARRAY_A);

    foreach ($menus as &$m) {
      $m['image_url'] = !empty($m['image_id'])
        ? wp_get_attachment_image_url((int) $m['image_id'], 'thumbnail')
        : null;
    }
    unset($m);

    $sizes = $wpdb->get_results("
      SELECT id, label, price_delta, is_default, is_active, sort_order
      FROM $t_sizes
      WHERE is_active = 1
      ORDER BY sort_order ASC, id ASC
    ", ARRAY_A);

    $addons = $wpdb->get_results("
      SELECT id, name, addon_type, price, is_active, sort_order
      FROM $t_addons
      WHERE is_active = 1
      ORDER BY addon_type ASC, sort_order ASC, id ASC
    ", ARRAY_A);

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
        'id'         => (int) $a['id'],
        'name'       => (string) $a['name'],
        'addon_type' => (string) $type,
        'price'      => (float) $a['price'],
        'sort_order' => (int) ($a['sort_order'] ?? 0),
        'is_active'  => (int) ($a['is_active'] ?? 1),
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
   */
  public static function menu_products(WP_REST_Request $req) {
    if (!function_exists('wc_get_product')) {
      return new WP_Error('jc_no_woo', 'WooCommerce not active.', ['status' => 500]);
    }

    global $wpdb;

    $menu_id = (int) $req['id'];

    $t_menus = $wpdb->prefix . 'jc_pos_menus';
    $t_map   = $wpdb->prefix . 'jc_pos_menu_products';

    $menu = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM $t_menus WHERE id=%d", $menu_id),
      ARRAY_A
    );

    if (!$menu) {
      return new WP_Error('jc_menu_not_found', 'Menu not found.', ['status' => 404]);
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
      $pid = (int) ($r['product_id'] ?? 0);
      if ($pid <= 0) continue;

      $p = wc_get_product($pid);
      if (!$p) continue;

      $products[] = [
        'id'         => $pid,
        'name'       => $p->get_name(),
        'price'      => (float) $p->get_price(),
        'image'      => wp_get_attachment_image_url($p->get_image_id(), 'medium'),
        'sort_order' => (int) ($r['sort_order'] ?? 0),
      ];
    }

    return rest_ensure_response([
      'menu'     => $menu,
      'products' => $products,
    ]);
  }

  /**
   * POST /jc-pos/v1/checkout
   */
  public static function checkout(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (!is_array($body)) {
      $body = [];
    }

    $document_type = strtoupper(trim((string) ($body['document_type'] ?? 'CONSUMIDOR_FINAL')));
    $allowed_doc = ['CONSUMIDOR_FINAL', 'CREDITO_FISCAL'];
    if (!in_array($document_type, $allowed_doc, true)) {
      $document_type = 'CONSUMIDOR_FINAL';
    }

    $cart = $body['cart'] ?? [];
    if (!is_array($cart) || empty($cart)) {
      return new WP_Error('jc_cart_empty', 'Cart is empty.', ['status' => 400]);
    }

    $discount_type  = sanitize_text_field($body['discount_type'] ?? 'none');
    $discount_value = (float) ($body['discount_value'] ?? 0);
    $fee_label      = sanitize_text_field($body['fee_label'] ?? '');
    $fee_value      = (float) ($body['fee_value'] ?? 0);

    // Resolve register/store
    $register_id = (int) get_option('jc_current_register_id', 0);
    if ($register_id <= 0) {
      return new WP_Error('jc_missing_register', 'Register not configured (jc_current_register_id).', ['status' => 400]);
    }

    global $wpdb;
    $t_registers = $wpdb->prefix . 'jc_registers';

    $store_id = (int) $wpdb->get_var(
      $wpdb->prepare("SELECT store_id FROM {$t_registers} WHERE id=%d", $register_id)
    );

    if ($store_id <= 0) {
      return new WP_Error('jc_missing_store', 'Store not found for the selected register.', ['status' => 400]);
    }

    // ---------------- Customer data ----------------
    $customer_id = absint($body['customer_id'] ?? 0);

    $request_customer = [
      'customer_id'                    => $customer_id,
      'customer_name'                  => sanitize_text_field($body['customer_name'] ?? ''),
      'customer_company'               => sanitize_text_field($body['customer_company'] ?? ''),
      'customer_nombre_comercial'      => sanitize_text_field($body['customer_nombre_comercial'] ?? ''),
      'customer_nrc'                   => sanitize_text_field($body['customer_nrc'] ?? ''),
      'customer_nit'                   => sanitize_text_field($body['customer_nit'] ?? ''),
      'customer_address'               => sanitize_textarea_field($body['customer_address'] ?? ''),
      'customer_city'                  => sanitize_text_field($body['customer_city'] ?? ''),
      'customer_departamento_code'     => sanitize_text_field($body['customer_departamento_code'] ?? ''),
      'customer_municipio_code'        => sanitize_text_field($body['customer_municipio_code'] ?? ''),
      'customer_direccion_complemento' => sanitize_textarea_field($body['customer_direccion_complemento'] ?? ''),
      'customer_actividad_code'        => sanitize_text_field($body['customer_actividad_code'] ?? ''),
      'customer_actividad_desc'        => sanitize_text_field($body['customer_actividad_desc'] ?? ''),
      'customer_phone'                 => sanitize_text_field($body['customer_phone'] ?? ''),
      'customer_email'                 => sanitize_email($body['customer_email'] ?? ''),
    ];

    $resolved_customer = null;

    // Prefer authoritative data from customer table when customer_id is provided.
    if ($customer_id > 0 && class_exists('JC_Customer_Service')) {
      $customer_row = JC_Customer_Service::get_customer($customer_id);

      if (!$customer_row) {
        return new WP_Error('jc_customer_not_found', 'Selected customer was not found.', ['status' => 400]);
      }

      $resolved_name = class_exists('JC_Customer_Service')
        ? JC_Customer_Service::build_customer_name($customer_row)
        : trim(((string) ($customer_row['first_name'] ?? '')) . ' ' . ((string) ($customer_row['last_name'] ?? '')));

        $resolved_customer = [
          'customer_id'                    => (int) ($customer_row['id'] ?? 0),
          'customer_name'                  => $resolved_name,
          'customer_company'               => (string) ($customer_row['company'] ?? ''),
          'customer_nombre_comercial'      => (string) ($customer_row['nombre_comercial'] ?? ''),
          'customer_nrc'                   => (string) ($customer_row['nrc'] ?? ''),
          'customer_nit'                   => (string) ($customer_row['nit'] ?? ''),
          'customer_address'               => (string) ($customer_row['address'] ?? ''),
          'customer_city'                  => (string) ($customer_row['city'] ?? ''),
          'customer_departamento_code'     => (string) ($customer_row['departamento_code'] ?? ''),
          'customer_municipio_code'        => (string) ($customer_row['municipio_code'] ?? ''),
          'customer_direccion_complemento' => (string) ($customer_row['direccion_complemento'] ?? ''),
          'customer_actividad_code'        => (string) ($customer_row['actividad_economica_code'] ?? ''),
          'customer_actividad_desc'        => (string) ($customer_row['actividad_economica_desc'] ?? ''),
          'customer_phone'                 => (string) ($customer_row['phone'] ?? ''),
          'customer_email'                 => (string) ($customer_row['email'] ?? ''),
        ];
    } else {
      // Fallback: use data from request
      $fallback_name = $request_customer['customer_name'];
      if ($fallback_name === '' && $request_customer['customer_company'] !== '') {
        $fallback_name = $request_customer['customer_company'];
      }

      $resolved_customer = [
        'customer_id'                    => 0,
        'customer_name'                  => $fallback_name,
        'customer_company'               => $request_customer['customer_company'],
        'customer_nombre_comercial'      => $request_customer['customer_nombre_comercial'],
        'customer_nrc'                   => $request_customer['customer_nrc'],
        'customer_nit'                   => $request_customer['customer_nit'],
        'customer_address'               => $request_customer['customer_address'],
        'customer_city'                  => $request_customer['customer_city'],
        'customer_departamento_code'     => $request_customer['customer_departamento_code'],
        'customer_municipio_code'        => $request_customer['customer_municipio_code'],
        'customer_direccion_complemento' => $request_customer['customer_direccion_complemento'],
        'customer_actividad_code'        => $request_customer['customer_actividad_code'],
        'customer_actividad_desc'        => $request_customer['customer_actividad_desc'],
        'customer_phone'                 => $request_customer['customer_phone'],
        'customer_email'                 => $request_customer['customer_email'],
      ];
    }

    // Crédito Fiscal validation
    if ($document_type === 'CREDITO_FISCAL') {
      if ((int) $resolved_customer['customer_id'] <= 0) {
        return new WP_Error(
          'jc_cf_customer_required',
          'Crédito Fiscal requires a selected customer.',
          ['status' => 400]
        );
      }
    
      $missing = [];
    
      if (trim((string) $resolved_customer['customer_name']) === '') {
        $missing[] = 'name/company';
      }
    
      if (trim((string) $resolved_customer['customer_nrc']) === '') {
        $missing[] = 'NRC';
      }
    
      if (trim((string) $resolved_customer['customer_nit']) === '') {
        $missing[] = 'NIT';
      }
    
      if (trim((string) $resolved_customer['customer_email']) === '') {
        $missing[] = 'email';
      }
    
      if (trim((string) $resolved_customer['customer_departamento_code']) === '') {
        $missing[] = 'departamento';
      }
    
      if (trim((string) $resolved_customer['customer_municipio_code']) === '') {
        $missing[] = 'municipio';
      }
    
      if (trim((string) $resolved_customer['customer_direccion_complemento']) === '') {
        $missing[] = 'dirección complemento';
      }
    
      if (trim((string) $resolved_customer['customer_actividad_code']) === '') {
        $missing[] = 'actividad económica';
      }
    
      if (trim((string) $resolved_customer['customer_actividad_desc']) === '') {
        $missing[] = 'descripción actividad';
      }
    
      if (!empty($resolved_customer['customer_email']) && !is_email((string) $resolved_customer['customer_email'])) {
        $missing[] = 'valid email';
      }
    
      if (!empty($missing)) {
        return new WP_Error(
          'jc_cf_customer_incomplete',
          'Crédito Fiscal customer is incomplete. Missing: ' . implode(', ', $missing) . '.',
          [
            'status' => 400,
            'missing_fields' => $missing,
            'customer_id' => (int) $resolved_customer['customer_id'],
          ]
        );
      }
    }

    // Tax behavior
    $tax_rate = ($document_type === 'CREDITO_FISCAL') ? 13.0 : 0.0;

    $items = [];
    foreach ($cart as $line) {
      $product_id = (int) ($line['product_id'] ?? 0);
      $qty        = (float) ($line['qty'] ?? 1);
      $unit_price = (float) ($line['unit_price'] ?? 0);
      $name       = sanitize_text_field($line['name'] ?? 'Item');

      if ($product_id <= 0 || $qty <= 0) {
        continue;
      }

      $items[] = [
        'product_id'   => $product_id,
        'product_name' => $name,
        'quantity'     => $qty,
        'unit_price'   => $unit_price,
        'tax_rate'     => $tax_rate,
        'meta'         => $line['meta'] ?? null,
      ];
    }

    if (empty($items)) {
      return new WP_Error('jc_no_valid_items', 'No valid items in cart.', ['status' => 400]);
    }
  // Payment data: accept a few common field names from the POS frontend
$payment_method_raw =
$body['payment_method']
?? $body['paymentMethod']
?? $body['payment_type']
?? $body['paymentType']
?? 'CASH';

$payment_method = strtoupper(trim((string) $payment_method_raw));
if (!in_array($payment_method, ['CASH', 'CARD', 'MIXED'], true)) {
$payment_method = 'CASH';
}

$cash_paid_raw =
$body['cash_paid']
?? $body['cashPaid']
?? $body['cash_received']
?? $body['cashReceived']
?? $body['amount_received']
?? $body['amountReceived']
?? $body['received']
?? $body['tendered']
?? null;

$card_paid_raw =
$body['card_paid']
?? $body['cardPaid']
?? $body['card_amount']
?? $body['cardAmount']
?? null;

$cash_paid = ($cash_paid_raw !== null && trim((string) $cash_paid_raw) !== '')
? round((float) $cash_paid_raw, 2)
: null;

$card_paid = ($card_paid_raw !== null && trim((string) $card_paid_raw) !== '')
? round((float) $card_paid_raw, 2)
: null;

  $payload = [
    'store_id'        => $store_id,
    'register_id'     => $register_id,
    'document_type'   => $document_type,
    'items'           => $items,
    'discount_type'   => $discount_type,
    'discount_value'  => $discount_value,
    'fee_label'       => $fee_label,
    'fee_value'       => $fee_value,
  
    'payment_method'  => $payment_method,
    'cash_paid'       => $cash_paid,
    'card_paid'       => $card_paid,
  
    'customer_id'      => (int) $resolved_customer['customer_id'],
    'customer_name'    => $resolved_customer['customer_name'],
    'customer_company' => $resolved_customer['customer_company'],
    'customer_nrc'     => $resolved_customer['customer_nrc'],
    'customer_nit'     => $resolved_customer['customer_nit'],
    'customer_address' => $resolved_customer['customer_address'],
    'customer_city'    => $resolved_customer['customer_city'],
    'customer_phone'   => $resolved_customer['customer_phone'],
    'customer_email'   => $resolved_customer['customer_email'],
  ];

    if (!class_exists('JC_Invoice_Service')) {
      return new WP_Error('jc_invoice_service_missing', 'JC_Invoice_Service is not loaded.', ['status' => 500]);
    }

    $result = JC_Invoice_Service::create_invoice($payload);

    if (empty($result['success'])) {
      $msg = $result['error'] ?? 'Create invoice failed.';
      return new WP_Error('jc_invoice_create_failed', $msg, ['status' => 500]);
    }

    $invoice_id = (int) ($result['invoice_id'] ?? 0);

    $mh = ['mh_status' => 'NOT_ATTEMPTED'];

    if (class_exists('JC_MH_Sender_Service')) {
      try {
        $mh = JC_MH_Sender_Service::send_one($invoice_id);

        if (!is_array($mh)) {
          $mh = [
            'mh_status' => 'FAILED',
            'error'     => 'send_one() returned a non-array response.',
          ];
        }
      } catch (Throwable $e) {
        error_log('[JC POS] MH send exception: ' . $e->getMessage());
        $mh = [
          'mh_status' => 'FAILED',
          'error'     => $e->getMessage(),
        ];
      }
    } else {
      $mh = [
        'mh_status' => 'FAILED',
        'error'     => 'JC_MH_Sender_Service is not loaded.',
      ];
    }
    $unsigned_dte = null;
    $folder = $document_type; // or map to CONSUMIDOR_FINAL/CREDITO_FISCAL
    $path = WP_CONTENT_DIR . '/uploads/dte/' . $folder . '/unsigned-invoice-' . $invoice_id . '.json';
    if (file_exists($path)) {
      $unsigned_dte = json_decode(file_get_contents($path), true);
    }

  $pdf_url = admin_url('admin-post.php?action=jc_dte_pdf&invoice_id='.(int)$invoice_id.'&autoprint=1');
  $receipt_url = admin_url('admin-post.php?action=jc_dte_receipt&invoice_id='.(int)$invoice_id.'&autoprint=1');

  return rest_ensure_response([
    'success'       => true,
    'invoice_id'    => $invoice_id,
    'ticket_number' => $result['ticket_number'] ?? null,
    'document_type' => $document_type,
    'mh'            => $mh,
    'dte'           => $unsigned_dte,
    'pdf_url'       => $pdf_url,
    'receipt_url'   => $receipt_url,
  ]);
  }  
}