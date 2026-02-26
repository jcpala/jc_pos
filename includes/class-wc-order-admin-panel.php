<?php

class JC_WC_Order_Admin_Panel {

    public static function init() {
        add_action('add_meta_boxes', [self::class, 'add_box']);
        add_action('admin_post_jc_mh_retry_from_order', [self::class, 'handle_retry']);
        add_action('admin_post_jc_mh_mark_sent_from_order', [self::class, 'handle_mark_sent']);
    }

    public static function add_box() {
        add_meta_box(
            'jc_fiscal_box',
            'JC Fiscal (MH)',
            [self::class, 'render_box'],
            'shop_order',
            'side',
            'high'
        );
    }

    public static function render_box($post) {
        if (!current_user_can('manage_woocommerce')) {
            echo '<p>No permission.</p>';
            return;
        }

        $order = wc_get_order($post->ID);
        if (!$order) {
            echo '<p>Order not found.</p>';
            return;
        }

        $jc_invoice_id = (int) $order->get_meta('_jc_invoice_id', true);
        $doc_type      = (string) $order->get_meta('_jc_document_type', true);
        $ticket        = (string) $order->get_meta('_jc_ticket_number', true);
        $correlativo   = (string) $order->get_meta('_jc_correlativo_number', true);

        echo '<p><strong>JC Invoice ID:</strong> ' . esc_html($jc_invoice_id ?: '-') . '</p>';
        echo '<p><strong>Doc type:</strong> ' . esc_html($doc_type ?: '-') . '</p>';
        echo '<p><strong>Ticket:</strong> ' . esc_html($ticket ?: '-') . '</p>';
        echo '<p><strong>Correlativo:</strong> ' . esc_html($correlativo ?: '-') . '</p>';

        if (!$jc_invoice_id) {
            echo '<hr><p><em>No JC invoice linked to this order.</em></p>';
            return;
        }

        global $wpdb;
        $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_jc_invoices WHERE id=%d", $jc_invoice_id));

        if (!$inv) {
            echo '<hr><p><em>JC invoice not found in DB.</em></p>';
            return;
        }

        echo '<hr>';
        echo '<p><strong>MH Status:</strong> ' . esc_html($inv->mh_status) . '</p>';
        echo '<p><strong>Attempts:</strong> ' . esc_html((string)$inv->mh_attempts) . '</p>';
        echo '<p><strong>Last attempt:</strong> ' . esc_html($inv->mh_last_attempt_at ?: '-') . '</p>';
        if (!empty($inv->mh_last_error)) {
            echo '<p><strong>Last error:</strong><br><span style="color:#b32d2e;">' . esc_html($inv->mh_last_error) . '</span></p>';
        }

        $retry_url = wp_nonce_url(
            admin_url('admin-post.php?action=jc_mh_retry_from_order&order_id=' . (int)$post->ID),
            'jc_mh_retry_from_order_' . (int)$post->ID
        );

        echo '<p><a class="button button-primary" href="' . esc_url($retry_url) . '">Retry send to MH</a></p>';

        // Optional admin override (use only if you really need it)
        $mark_sent_url = wp_nonce_url(
            admin_url('admin-post.php?action=jc_mh_mark_sent_from_order&order_id=' . (int)$post->ID),
            'jc_mh_mark_sent_from_order_' . (int)$post->ID
        );

        echo '<p><a class="button" href="' . esc_url($mark_sent_url) . '" onclick="return confirm(\'Mark as SENT?\')">Mark as SENT</a></p>';
    }

    public static function handle_retry() {
        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }
        check_admin_referer('jc_mh_retry_from_order_' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order) wp_die('Order not found.');

        $invoice_id = (int) $order->get_meta('_jc_invoice_id', true);
        if ($invoice_id <= 0) wp_die('No JC invoice linked.');

        // Call your sender service (we’ll implement it to call your existing MH code)
        $res = JC_MH_Sender_Service::send_one((int)$invoice_id);

        // Add order note
        if (!empty($res['success'])) {
            $order->add_order_note('MH resend OK. Status=' . ($res['mh_status'] ?? 'SENT'));
        } else {
            $order->add_order_note('MH resend FAILED: ' . ($res['error'] ?? 'Unknown'));
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit'));
        exit;
    }

    public static function handle_mark_sent() {
        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }
        check_admin_referer('jc_mh_mark_sent_from_order_' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order) wp_die('Order not found.');

        $invoice_id = (int) $order->get_meta('_jc_invoice_id', true);
        if ($invoice_id <= 0) wp_die('No JC invoice linked.');

        global $wpdb;
        $wpdb->update('wp_jc_invoices', [
            'mh_status' => 'SENT',
            'mh_last_error' => null,
        ], ['id' => $invoice_id]);

        if (class_exists('JC_Audit_Service')) {
            JC_Audit_Service::log([
                'action' => 'mh_admin_mark_sent',
                'entity_type' => 'invoice',
                'entity_id' => $invoice_id,
                'message' => 'Admin marked invoice as SENT',
                'meta' => ['order_id' => $order_id],
            ]);
        }

        $order->add_order_note('Admin marked MH status as SENT.');

        wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit'));
        exit;
    }
}