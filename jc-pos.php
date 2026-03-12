<?php
/**
 * Plugin Name: JC POS System
 * Description: Custom Retail POS built on WooCommerce
 * Version: 1.0
 * Author: Juan Carlos
 * Company: Teavo
 */

if (!defined('ABSPATH')) exit;

// Dompdf bundled library.
$jc_pos_dompdf_autoload = __DIR__ . '/lib/dompdf/autoload.inc.php';
if (file_exists($jc_pos_dompdf_autoload)) {
    require_once $jc_pos_dompdf_autoload;
}


require_once plugin_dir_path(__FILE__) . 'includes/class-ticket-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-correlativo-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-invoice-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mh-sender-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-correlativo-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-audit-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-checkout-fields.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-fiscal-integration.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-register-fields.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-register-session-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-customer-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-jc-customers-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/email/class-jc-pos-smtp-service.php';

if (class_exists('JC_POS_SMTP_Service')) {
    JC_POS_SMTP_Service::init();
}

/*******************************************************************
 * 80mm receipt
 *******************************************************************/
require_once plugin_dir_path(__FILE__) . 'includes/services/dte/class-jc-dte-receipt-service.php';
if (class_exists('JC_DTE_Receipt_Service')) {
    JC_DTE_Receipt_Service::init();
}


// Add the PDF service require (adjust if your path differs)
require_once plugin_dir_path(__FILE__) . 'includes/services/dte/class-jc-dte-pdf-service.php';
if (class_exists('JC_DTE_Pdf_Service')) {
    JC_DTE_Pdf_Service::init();
}

JC_Customers_Admin::init();

add_action('init', ['JC_Correlativo_Admin', 'init']);
add_action('init', ['JC_WC_Checkout_Fields', 'init']);
add_action('init', ['JC_WC_Fiscal_Integration', 'init']);
JC_WC_Register_Fields::init();

add_action('admin_init', function () {
    if (get_option('jc_low_ticket_threshold') === false) {
        add_option('jc_low_ticket_threshold', 100);
    }
});

require_once plugin_dir_path(__FILE__) . 'includes/repositories/class-jc-pos-invoice-repository.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/email/class-jc-pos-mailer.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/email/class-jc-pos-email-template-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/email/class-jc-pos-smtp-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/services/email/class-jc-pos-email-service.php';

if (class_exists('JC_POS_SMTP_Service')) {
    JC_POS_SMTP_Service::init();
}

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

require_once plugin_dir_path(__FILE__) . 'includes/services/email/class-jc-pos-email-admin-actions.php';
if (class_exists('JC_POS_Email_Admin_Actions')) {
    JC_POS_Email_Admin_Actions::init();
}

/*************************************************************************************************
 * To Test email
 *************************************************************************************************/

 add_action('admin_init', function () {
    if (empty($_GET['jc_test_email_invoice'])) {
        return;
    }

    if (!current_user_can('manage_woocommerce')) {
        wp_die('No permission.');
    }

    $invoice_id = absint($_GET['jc_test_email_invoice']);

    if ($invoice_id <= 0) {
        wp_die('<h2>JC POS Email Test</h2><p>Missing or invalid invoice id.</p>');
    }

    echo '<div style="padding:20px;font-family:Arial,sans-serif;">';
    echo '<h2>JC POS Email Test</h2>';
    echo '<p><strong>Invoice ID:</strong> ' . (int) $invoice_id . '</p>';

    try {
        if (!class_exists('JC_POS_Email_Service')) {
            throw new Exception('JC_POS_Email_Service class not loaded.');
        }

        $service = new JC_POS_Email_Service();
        $result  = $service->send_documents_for_invoice($invoice_id);

        echo '<h3>Result</h3>';
        echo '<pre style="background:#f6f6f6;padding:12px;border:1px solid #ddd;">';
        print_r($result);
        echo '</pre>';
    } catch (Throwable $e) {
        echo '<h3 style="color:#b00020;">Caught Error</h3>';
        echo '<pre style="background:#fff0f0;padding:12px;border:1px solid #e0b4b4;">';
        echo esc_html($e->getMessage()) . "\n\n";
        echo esc_html($e->getFile()) . ':' . esc_html((string) $e->getLine());
        echo '</pre>';
    }

    echo '</div>';
    exit;
});