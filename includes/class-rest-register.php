<?php
if (!defined('ABSPATH')) exit;

class JC_REST_Register {

    public static function init() {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes() {

        register_rest_route('jc-pos/v1', '/products', [
            'methods'  => 'GET',
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'callback' => [self::class, 'products'],
        ]);

        register_rest_route('jc-pos/v1', '/checkout', [
            'methods'  => 'POST',
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'callback' => [self::class, 'checkout'],
            'args' => [
                'cart' => ['required' => true],
            ]
        ]);
    }

    public static function products(WP_REST_Request $req) {
        global $wpdb;
    
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
        $args = [
            'status'  => 'publish',
            'limit'   => 100,
            'orderby' => 'menu_order',
            'order'   => 'ASC',
            'type'    => ['simple', 'variable'],
        ];
    
        if ($search !== '') $args['search'] = $search;
    
        $products = wc_get_products($args);
    
        $out = [];
        foreach ($products as $p) {
    
            $item = [
                'id'    => $p->get_id(),
                'name'  => $p->get_name(),
                'price' => (float)$p->get_price(), // base
                'image' => wp_get_attachment_image_url($p->get_image_id(), 'medium') ?: '',
                'type'  => $p->get_type(),
                'sizes' => [], // we’ll fill for variable
            ];
    
            // If product is variable, return variation options for Size attribute
            if ($p->is_type('variable')) {
                //$variation_ids = $p->get_children();
                $variation_ids = isset($line['variation_id']) ? (int)$line['variation_id'] : 0;
    
                foreach ($variation_ids as $vid) {
                    $v = wc_get_product($vid);
                    if (!$v || !$v->is_type('variation')) continue;
    
                    if (!$v->is_purchasable() || !$v->is_in_stock()) continue;
    
                    $attrs = $v->get_attributes(); // e.g. ['attribute_pa_size' => '16-oz']
    
                    // Find size value (supports pa_size or "size")
                    $size = '';
                    foreach ($attrs as $k => $val) {
                        $k = strtolower((string)$k);
                        if (strpos($k, 'size') !== false) {
                            $size = (string)$val;
                            break;
                        }
                    }
    
                    // Only include variations that actually have a size attribute
                    if ($size === '') continue;
    
                    $item['sizes'][] = [
                        'variation_id' => $variation_id ?: null,
                        'label'        => $size,               // “16 oz”
                        'price'        => (float)$v->get_price(), // exact price for that size
                    ];
                }
    
                // If no sizes found, keep empty; UI can fallback
            } else {
                // Simple product: treat as single “default size” option
                $item['sizes'][] = [
                    'variation_id' => null,
                    'label'        => 'Default',
                    'price'        => (float)$p->get_price(),
                ];
            }
    
            $out[] = $item;
        }
    
        // Pull toppings from DB (active only)
        $toppings = $wpdb->get_results(
            "SELECT id, name, price
             FROM wp_jc_toppings
             WHERE is_active=1
             ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
    
        // Normalize for JS
        $toppings_out = array_map(function($t){
            return [
                'id' => (int)$t['id'],
                'name' => (string)$t['name'],
                'price' => (float)$t['price'],
            ];
        }, $toppings ?: []);
    
        return rest_ensure_response([
            'products' => $out,
            'toppings' => $toppings_out,
        ]);
    }

    public static function checkout(WP_REST_Request $req) {
        $body = $req->get_json_params();

        $cart = $body['cart'] ?? [];
        if (!is_array($cart) || empty($cart)) {
            return new WP_REST_Response(['success'=>false, 'error'=>'Cart is empty'], 400);
        }

        $discount_type = sanitize_text_field($body['discount_type'] ?? 'none');
        $discount_value = (float) ($body['discount_value'] ?? 0);

        // Resolve register/store
        $register_id = (int) get_option('jc_current_register_id', 0);
        if ($register_id <= 0) {
            return new WP_REST_Response(['success'=>false, 'error'=>'Register not configured (jc_current_register_id)'], 400);
        }

        global $wpdb;
        $store_id = (int) $wpdb->get_var($wpdb->prepare("SELECT store_id FROM wp_jc_registers WHERE id=%d", $register_id));

        // Build items for your JC invoice tables
        $items = [];
        foreach ($cart as $line) {
            $product_id = (int)($line['product_id'] ?? 0);
            $qty = (float)($line['qty'] ?? 1);
            $unit_price = (float)($line['unit_price'] ?? 0);
            $name = sanitize_text_field($line['name'] ?? 'Item');

            if ($product_id <= 0 || $qty <= 0) continue;

            $items[] = [
                'product_id' => $product_id,
                'product_name' => $name,
                'quantity' => $qty,
                'unit_price' => $unit_price, // assume gross for CF in POS; keep consistent
                'tax_rate' => 13.0,
                'meta' => $line['meta'] ?? null, // size/flavor/toppings
            ];
        }

        if (empty($items)) {
            return new WP_REST_Response(['success'=>false, 'error'=>'No valid items'], 400);
        }

        // Apply discount at invoice-level (MVP: just store it; your invoice service can compute totals)
        $payload = [
            'store_id' => $store_id,
            'register_id' => $register_id,
            'document_type' => 'CONSUMIDOR_FINAL',
            'items' => $items,
            'discount_type' => $discount_type,
            'discount_value' => $discount_value,
        ];

        // Create invoice (your existing service)
        if (!class_exists('JC_Invoice_Service')) {
            return new WP_REST_Response(['success'=>false, 'error'=>'JC_Invoice_Service not loaded'], 500);
        }

        $result = JC_Invoice_Service::create_invoice($payload);

        if (empty($result['success'])) {
            return new WP_REST_Response(['success'=>false, 'error'=>$result['error'] ?? 'Create invoice failed'], 500);
        }

        $invoice_id = (int)$result['invoice_id'];

        // Best-effort MH send
        $mh = null;
        if (class_exists('JC_MH_Sender_Service')) {
            $mh = JC_MH_Sender_Service::send_one($invoice_id);
        }

        return rest_ensure_response([
            'success' => true,
            'invoice_id' => $invoice_id,
            'ticket_number' => $result['ticket_number'] ?? null,
            'mh' => $mh,
        ]);
    }
}