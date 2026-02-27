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

        echo '<div class="wrap"><h1 style="display:none;"> Teavo Register</h1></div>';

        // App shell (JS fills content)
        ?>
<div class="jc-pos-left">
  <div class="jc-pos-toolbar">
    <input id="jc-search" class="jc-input" placeholder="Search products..." />
    <button id="jc-refresh" class="button">Refresh</button>
  </div>

  <!-- Menu pills -->
  <div id="jc-menus" class="jc-menus"></div>

  <!-- Products -->
  <div id="jc-products" class="jc-grid"></div>
</div>

<!-- NEW: menu pills -->
<div id="jc-menus" class="jc-menus"></div>

<div id="jc-products" class="jc-grid"></div>


            <div class="jc-pos-right">
                <h2>Cart</h2>
                <div id="jc-cart"></div>

                <div class="jc-totals">
                    <div><strong>Subtotal:</strong> <span id="jc-subtotal">$0.00</span></div>
                    <div><strong>Discount:</strong>
                        <select id="jc-discount-type" class="jc-input">
                            <option value="none">None</option>
                            <option value="percent">%</option>
                            <option value="amount">$</option>
                        </select>
                        <input id="jc-discount-value" class="jc-input" type="number" step="0.01" value="0" />
                    </div>
                    <div><strong>Fee:</strong>
                        <input id="jc-fee-label" class="jc-input" placeholder="Reason (optional)" />
                        <input id="jc-fee-value" class="jc-input" type="number" step="0.01" value="0" />
                    </div>
                    <div><strong>Total:</strong> <span id="jc-total">$0.00</span></div>
                </div>

                <div class="jc-actions">
                    <button id="jc-checkout" class="button button-primary button-large">Complete Sale</button>
                </div>

                <div id="jc-result" class="jc-result"></div>
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
        </div>
        <?php
    }
}