<?php
if (!defined('ABSPATH')) exit;

class JC_POS_Email_Admin_Actions
{
    public static function init(): void
    {
        add_action('admin_post_jc_pos_resend_email', [__CLASS__, 'resend_email']);
    }

    public static function resend_email(): void
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }

        $invoice_id = isset($_GET['invoice_id']) ? absint($_GET['invoice_id']) : 0;
        if ($invoice_id <= 0) {
            wp_die('Missing invoice id.');
        }

        check_admin_referer('jc_pos_resend_email_' . $invoice_id);

        if (!class_exists('JC_POS_Email_Service')) {
            wp_die('Email service not available.');
        }

        $service = new JC_POS_Email_Service();
        $result  = $service->send_documents_for_invoice($invoice_id);

        $notice = !empty($result['ok']) ? 'success' : 'error';
        $msg    = isset($result['message']) ? sanitize_text_field((string)$result['message']) : 'Unknown result.';

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=jc-pos-invoices');
        }

        $redirect = add_query_arg([
            'jc_email_notice' => $notice,
            'jc_email_msg'    => rawurlencode($msg),
        ], $redirect);

        wp_safe_redirect($redirect);
        exit;
    }
}