<?php
if (!defined('ABSPATH')) exit;

class JC_Register_Admin {

    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu() {
        add_menu_page(
            'Register',
            'Register',
            'manage_woocommerce',
            'jc-register',
            [self::class, 'page'],
            'dashicons-screenoptions',
            55
        );
    }

    public static function assets($hook) {
        if ($hook !== 'toplevel_page_jc-register') {
            return;
        }
    
        $css_path = plugin_dir_path(__FILE__) . '../assets/register.css';
        $js_path  = plugin_dir_path(__FILE__) . '../assets/register.js';
        $qr_path  = plugin_dir_path(__FILE__) . '../assets/qrcode.min.js';
        
    
        wp_enqueue_style(
            'jc-register',
            plugins_url('../assets/register.css', __FILE__),
            [],
            file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0'
        );
    
        wp_add_inline_style('jc-register', '
            #wpbody-content > .notice,
            #wpbody-content > .update-nag,
            #wpbody-content > .updated,
            #wpbody-content > .error,
            #wpbody-content > .is-dismissible { display:none !important; }
            #wpfooter { display:none !important; }
        ');
    
        // ✅ QR lib
        wp_enqueue_script(
            'jc-qrcode',
            plugins_url('../assets/qrcode.min.js', __FILE__),
            [],
            file_exists($qr_path) ? (string) filemtime($qr_path) : '1.0.0',
            true
        );
    
        wp_enqueue_script(
            'jc-register',
            plugins_url('../assets/register.js', __FILE__),
            ['wp-api-fetch', 'jc-qrcode'], // ✅ depends on qr lib
            file_exists($js_path) ? (string) filemtime($js_path) : '1.0.0',
            true
        );
    
        wp_localize_script('jc-register', 'JC_POS', [
            'apiBase' => esc_url_raw(rest_url('jc-pos/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }

        $register_customers = class_exists('JC_Customer_Service')
            ? JC_Customer_Service::find_customers('', 200)
            : [];
        ?>
        <div id="jc-pos-app" class="jc-pos">
            <div class="jc-pos-left">
                <div class="jc-pos-toolbar">
                    <input id="jc-search" class="jc-input" placeholder="Search products..." />
                    <button id="jc-refresh" class="button" type="button">Refresh</button>
                </div>

                <div id="jc-menus" class="jc-menus"></div>
                <div id="jc-products" class="jc-grid"></div>
            </div>

            <div class="jc-pos-right">
                <h2 class="jc-cart-title">Cart</h2>

                <div id="jc-cart" class="jc-cart-canvas"></div>

                <div class="jc-cart-footer">
                    <div class="jc-totals">
                        <div class="jc-row">
                            <strong>Subtotal</strong>
                            <span id="jc-subtotal">$0.00</span>
                        </div>

                        <div class="jc-row jc-row-controls">
                            <div class="jc-label">Discount</div>
                            <div class="jc-control-inline">
                                <select id="jc-discount-type" class="jc-input">
                                    <option value="none">None</option>
                                    <option value="percent">%</option>
                                    <option value="amount">$</option>
                                </select>
                                <input id="jc-discount-value" class="jc-input" type="number" step="0.01" value="0" />
                            </div>
                        </div>

                        <div class="jc-row jc-row-controls">
                            <div class="jc-label">Fee</div>
                            <div class="jc-control-inline">
                                <input id="jc-fee-label" class="jc-input" placeholder="Reason (optional)" />
                                <input id="jc-fee-value" class="jc-input" type="number" step="0.01" value="0" />
                            </div>
                        </div>

                        <div class="jc-row jc-row-total">
                            <strong>Total</strong>
                            <span id="jc-total">$0.00</span>
                        </div>

                        <!-- ✅ CASH RECEIVED + CHANGE -->
                        <div class="jc-row jc-row-controls">
                            <div class="jc-label">Cash received</div>
                            <div class="jc-control-inline">
                                <input id="jc-cash-received" class="jc-input" type="number" step="0.01" min="0" value="0" />
                                <button id="jc-cash-exact" class="button" type="button">Exact</button>
                            </div>
                        </div>

                        <div class="jc-row">
                            <strong>Change</strong>
                            <span id="jc-change-due">$0.00</span>
                        </div>
                    </div>

                    <div class="jc-docwrap" style="display:flex;gap:10px;align-items:center;margin:10px 0;">
                        <strong>Documento:</strong>

                        <label style="display:flex;gap:6px;align-items:center;">
                            <input type="radio" name="jc_doc_type" value="CONSUMIDOR_FINAL" checked>
                            Consumidor Final
                        </label>

                        <label style="display:flex;gap:6px;align-items:center;">
                            <input type="radio" name="jc_doc_type" value="CREDITO_FISCAL">
                            Crédito Fiscal
                        </label>

                        <span id="jc-doc-badge" style="margin-left:auto;font-size:12px;opacity:.75;"></span>
                    </div>

                    <script type="application/json" id="jc-pos-customer-index">
                        <?php echo wp_json_encode($register_customers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                    </script>

                    <div id="jc-register-customer-box">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;">
                            <strong>Customer</strong>
                            <a
                                href="<?php echo esc_url(class_exists('JC_Customers_Admin') ? JC_Customers_Admin::page_url(['action' => 'new']) : admin_url()); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="button button-small"
                            >Add New</a>
                        </div>

                        <p id="jc-customer-requirement-text" style="margin:0 0 10px 0;color:#50575e;font-size:12px;">
                            Optional for Consumidor Final. Required for Crédito Fiscal.
                        </p>

                        <div class="jc-customer-search-row" style="display:flex;gap:8px;align-items:flex-start;">
                            <input
                                type="text"
                                id="jc-customer-search"
                                class="regular-text"
                                placeholder="Search by name, company, NRC, NIT, phone, email"
                                style="flex:1;min-width:0;"
                            >
                            <button type="button" id="jc-customer-search-btn" class="button">Search</button>
                        </div>

                        <div id="jc-customer-results" style="display:none;"></div>
                        <div id="jc-customer-error" style="display:none;margin-top:8px;color:#d63638;font-size:12px;font-weight:600;"></div>

                        <div id="jc-selected-customer" style="display:none;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                                <div>
                                    <div id="jc-selected-customer-name" style="font-weight:600;"></div>
                                    <div id="jc-selected-customer-meta" style="margin-top:4px;color:#50575e;font-size:12px;line-height:1.4;"></div>
                                </div>
                                <button type="button" id="jc-clear-customer" class="button button-small">Clear</button>
                            </div>
                        </div>

                        <!-- Hidden fields used by register.js checkout payload -->
                        <input type="hidden" id="jc-sale-customer-id" value="">
                        <input type="hidden" id="jc-sale-customer-name" value="">
                        <input type="hidden" id="jc-sale-customer-company" value="">
                        <input type="hidden" id="jc-sale-customer-nrc" value="">
                        <input type="hidden" id="jc-sale-customer-nit" value="">
                        <input type="hidden" id="jc-sale-customer-address" value="">
                        <input type="hidden" id="jc-sale-customer-city" value="">
                        <input type="hidden" id="jc-sale-customer-phone" value="">
                        <input type="hidden" id="jc-sale-customer-email" value="">
                    </div>

                    <div class="jc-actions">
                      <button type="button" id="jc-checkout" class="button button-primary">Complete Sale</button>
                    </div>
                    </div>
                    <div id="jc-result" class="jc-result"></div>
                </div>
            </div>
        </div>

        <!-- Product Modal -->
        <div id="jc-modal" class="jc-modal jc-hidden" aria-hidden="true">
            <div class="jc-modal-card">
                <div class="jc-modal-header">
                    <h3 id="jc-modal-title">Customize</h3>
                    <button id="jc-modal-close" class="button" type="button">X</button>
                </div>

                <div class="jc-modal-body">
                    <label>Size</label>
                    <select id="jc-opt-size" class="jc-input"></select>

                    <label>Flavor</label>
                    <select id="jc-opt-flavor" class="jc-input"></select>

                    <label>Toppings</label>
                    <div id="jc-opt-toppings" class="jc-toppings"></div>

                    <div class="jc-modal-total">
                        <strong>Item Total:</strong> <span id="jc-item-total">$0.00</span>
                    </div>
                </div>

                <div class="jc-modal-footer">
                    <button id="jc-add-to-cart" class="button button-primary" type="button">Add to cart</button>
                </div>
            </div>
        </div>

        <!-- Receipt Modal -->
<!-- Receipt Modal -->
<div id="jc-receipt-modal" class="jc-modal jc-hidden" aria-hidden="true">
  <div class="jc-modal-card" style="width:520px; max-width:95vw;">
    <div class="jc-modal-header">
      <h3>Receipt</h3>
      <button id="jc-receipt-close" class="button" type="button">X</button>
    </div>

    <div class="jc-modal-body">
      <!-- Receipt HTML gets injected here -->
      <div id="jc-receipt-body"></div>

      <!-- MH box (employee view / debug) -->
      <div id="jc-mh-box" style="margin-top:10px; padding:10px; border:1px solid #dcdcde; border-radius:10px; background:#fff;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
          <strong>MH Status</strong>
          <span id="jc-mh-pill" style="padding:3px 8px; border-radius:999px; font-size:12px;"></span>
        </div>

        <div id="jc-mh-summary" style="margin-top:6px; font-size:12px; color:#50575e;"></div>

        <!-- ✅ QR area (what you’ll keep in production) -->
        <div id="jc-mh-qr-wrap" style="margin-top:10px; display:none;">
          <div style="font-size:12px; color:#50575e; margin-bottom:6px;">QR (selloRecibido)</div>
          <div id="jc-mh-qr" style="display:flex; justify-content:center;"></div>
        </div>

        <!-- Debug details (hide in production if you want) -->
        <details id="jc-mh-details" style="margin-top:8px;">
          <summary style="cursor:pointer;">Details</summary>
          <pre id="jc-mh-json" style="white-space:pre-wrap; margin:8px 0 0; max-height:160px; overflow:auto;"></pre>
        </details>
      </div>
    </div>

    <div class="jc-modal-footer" style="display:flex; gap:8px; justify-content:flex-end; align-items:center;">
      <button id="jc-receipt-print" class="button" type="button">Print</button>
      <button id="jc-receipt-new-sale" class="button button-primary" type="button">New Sale</button>
      <button id="jc-receipt-close-btn" class="button" type="button">Close</button>
    </div>
  </div>
</div>
        <?php
    }
}