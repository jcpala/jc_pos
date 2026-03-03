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
        // Only load on our page
        if ($hook !== 'toplevel_page_jc-register') return;

        wp_enqueue_style(
            'jc-register',
            plugins_url('../assets/register.css', __FILE__),
            [],
            '1.0.0'
        );

  echo '<style>
  /* Hide admin notices + the WP footer only on Register page */
  .notice, .update-nag, .updated, .error, .is-dismissible { display:none !important; }
  #wpfooter { display:none !important; }
</style>';

        wp_add_inline_style('jc-register', '
  /* Hide WP admin clutter only on Register page */
  #wpbody-content > .notice,
  #wpbody-content > .update-nag,
  #wpbody-content > .updated,
  #wpbody-content > .error,
  #wpbody-content > .is-dismissible { display:none !important; }

  #wpfooter { display:none !important; }
');

        wp_enqueue_script(
            'jc-register',
            plugins_url('../assets/register.js', __FILE__),
            ['wp-api-fetch'],
            '1.0.0',
            true
        );

        wp_localize_script('jc-register', 'JC_POS', [
            'apiBase' => esc_url_raw( rest_url('jc-pos/v1') ),
            'nonce'   => wp_create_nonce('wp_rest'),
          ]);
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }
        ?>


<div id="jc-pos-app" class="jc-pos">
  <div class="jc-pos-left">
    <div class="jc-pos-toolbar">
      <input id="jc-search" class="jc-input" placeholder="Search products..." />
      <button id="jc-refresh" class="button">Refresh</button>
    </div>

    <div id="jc-menus" class="jc-menus"></div>
    <div id="jc-products" class="jc-grid"></div>
  </div>

  <div class="jc-pos-right">
  <h2 class="jc-cart-title">Cart</h2>

  <!-- SCROLL AREA -->
  <div id="jc-cart" class="jc-cart-canvas"></div>

  <!-- FOOTER (PINNED) -->
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

<?php
$register_customers = class_exists('JC_Customer_Service')
    ? JC_Customer_Service::find_customers('', 200)
    : [];
?>

<script type="application/json" id="jc-pos-customer-index">
<?php echo wp_json_encode($register_customers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>

<div id="jc-register-customer-box" style="margin-top:14px;padding:12px;border:1px solid #dcdcde;border-radius:6px;background:#fff;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;">
        <strong>Customer</strong>
        <a
            href="<?php echo esc_url(JC_Customers_Admin::page_url(['action' => 'new'])); ?>"
            target="_blank"
            rel="noopener noreferrer"
            class="button button-small"
        >Add New</a>
    </div>

    <p id="jc-customer-requirement-text" style="margin:0 0 10px 0;color:#50575e;font-size:12px;">
        Optional for Consumidor Final. Required for Crédito Fiscal.
    </p>

    <div style="display:flex;gap:8px;align-items:flex-start;">
        <input
            type="text"
            id="jc-customer-search"
            class="regular-text"
            placeholder="Search by name, company, NRC, NIT, phone, email"
            style="flex:1;min-width:0;"
        >
        <button type="button" id="jc-customer-search-btn" class="button">Search</button>
    </div>

    <div id="jc-customer-results" style="display:none;margin-top:8px;max-height:180px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;background:#fff;"></div>

    <div id="jc-customer-error" style="display:none;margin-top:8px;color:#d63638;font-size:12px;font-weight:600;"></div>

    <div id="jc-selected-customer" style="display:none;margin-top:10px;padding:10px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
            <div>
                <div id="jc-selected-customer-name" style="font-weight:600;"></div>
                <div id="jc-selected-customer-meta" style="margin-top:4px;color:#50575e;font-size:12px;line-height:1.4;"></div>
            </div>
            <button type="button" id="jc-clear-customer" class="button button-small">Clear</button>
        </div>
    </div>

    <!-- Hidden fields for later invoice submit integration -->
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
       <button type="button" id="jc-complete-sale" class="button button-primary">Complete Sale</button>
    </div>

    <div id="jc-result" class="jc-result"></div>
  </div>
</div>
            <!-- Modal -->
            <div id="jc-modal" class="jc-modal jc-hidden" aria-hidden="true">
                <div class="jc-modal-card">
                    <div class="jc-modal-header">
                        <h3 id="jc-modal-title">Customize</h3>
                        <button id="jc-modal-close" class="button">X</button>
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
                        <button id="jc-add-to-cart" class="button button-primary">Add to cart</button>
                    </div>
                </div>
            </div>
<!-- Receipt Modal -->
<div id="jc-receipt-modal" class="jc-modal jc-hidden" aria-hidden="true">
  <div class="jc-modal-card" style="width:520px; max-width:95vw;">
    <div class="jc-modal-header">
      <h3>Receipt</h3>
      <button id="jc-receipt-close" class="button">X</button>
    </div>

    <div class="jc-modal-body" id="jc-receipt-body"></div>

    <div class="jc-modal-footer" style="display:flex; gap:8px; justify-content:flex-end;">
      <button id="jc-receipt-print" class="button">Print</button>
      <button id="jc-receipt-done" class="button button-primary">Done</button>
    </div>
  </div>
</div>


        </div>
        <?php
    }
}
