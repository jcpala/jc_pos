<?php
/**
 * Plugin Name: JC POS System
 * Description: Custom Retail POS built on WooCommerce
 * Version: 1.0
 * Author: Juan Carlos
 * Company: Teavo
 */

 if (!defined('ABSPATH')) exit;

 add_action('plugins_loaded', function () {
    if (!is_admin()) return;

    $file = plugin_dir_path(__FILE__) . 'class-jc-invoices-admin.php';
    if (file_exists($file)) {
        require_once $file;
        if (class_exists('JC_Invoices_Admin')) {
            JC_Invoices_Admin::init();
        }
    }
}, 1);

require_once plugin_dir_path(__FILE__) . 'includes/class-ticket-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-correlativo-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-invoice-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mh-sender-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-correlativo-admin.php';
add_action('init', ['JC_Correlativo_Admin', 'init']);

require_once plugin_dir_path(__FILE__) . 'includes/class-audit-service.php';

require_once plugin_dir_path(__FILE__) . 'includes/class-wc-checkout-fields.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-fiscal-integration.php';
require_once __DIR__ . '/includes/class-wc-register-fields.php';
JC_WC_Register_Fields::init();
require_once plugin_dir_path(__FILE__) . 'includes/class-jc-invoices-admin.php';
JC_Invoices_Admin::init(); 


add_action('init', ['JC_WC_Checkout_Fields', 'init']);
add_action('init', ['JC_WC_Fiscal_Integration', 'init']);


add_action('admin_init', function () {
    if (get_option('jc_low_ticket_threshold') === false) {
        add_option('jc_low_ticket_threshold', 100); // default warning level
    }
});

require_once plugin_dir_path(__FILE__) . 'includes/class-correlativo-notices.php';
add_action('init', ['JC_Correlativo_Notices', 'init']);

require_once plugin_dir_path(__FILE__) . 'includes/class-wc-order-admin-panel.php';
add_action('init', ['JC_WC_Order_Admin_Panel', 'init']);

require_once plugin_dir_path(__FILE__) . 'includes/class-mh-queue-admin.php';
add_action('init', ['JC_MH_Queue_Admin', 'init']);

require_once plugin_dir_path(__FILE__) . 'includes/class-register-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-rest-register.php';

add_action('init', ['JC_Register_Admin', 'init']);
add_action('init', ['JC_REST_Register', 'init']);



if (is_admin()) {
    require_once __DIR__ . '/admin/jc-pos-admin.php';
  }

