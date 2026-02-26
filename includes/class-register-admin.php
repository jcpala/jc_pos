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

        wp_enqueue_script(
            'jc-register',
            plugins_url('../assets/register.js', __FILE__),
            ['wp-api-fetch'],
            '1.0.0',
            true
        );

        wp_localize_script('jc-register', 'JC_POS', [
            'restPath' => '/jc-pos/v1',
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }

        echo '<div class="wrap"><h1>Register</h1></div>';

        // App shell (JS fills content)
        ?>
        <div id="jc-pos-app" class="jc-pos">
            <div class="jc-pos-left">
                <div class="jc-pos-toolbar">
                    <input id="jc-search" class="jc-input" placeholder="Search products..." />
                    <button id="jc-refresh" class="button">Refresh</button>
                </div>
                <div id="jc-products" class="jc-grid"></div>
            </div>

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
                        <select id="jc-opt-flavor" class="jc-input">
                            <option value="">Default</option>
                            <option value="Original">Original</option>
                            <option value="Matcha">Matcha</option>
                            <option value="Taro">Taro</option>
                        </select>

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