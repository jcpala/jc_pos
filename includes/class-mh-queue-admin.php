<?php

class JC_MH_Queue_Admin {

    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_jc_mh_batch_send', [self::class, 'handle_batch_send']);
        add_action('admin_post_jc_mh_retry_invoice', [self::class, 'handle_retry_one']);
    }

    public static function menu() {
        add_submenu_page(
            'jc-correlativos',
            'MH Queue',
            'MH Queue',
            'manage_options',
            'jc-mh-queue',
            [self::class, 'page']
        );
    }

    public static function page() {
        if (!current_user_can('manage_options')) wp_die('No permission.');
        global $wpdb;

        $status = isset($_GET['mh_status']) ? sanitize_text_field($_GET['mh_status']) : 'PENDING';
        $doc    = isset($_GET['doc_type']) ? sanitize_text_field($_GET['doc_type']) : '';
        $reg    = isset($_GET['register_id']) ? (int)$_GET['register_id'] : 0;

        $allowed_status = ['PENDING','FAILED','REJECTED','SENT'];
        if (!in_array($status, $allowed_status, true)) $status = 'PENDING';

        $where = "WHERE mh_status = %s";
        $params = [$status];

        if ($doc !== '') {
            $where .= " AND document_type = %s";
            $params[] = $doc;
        }
        if ($reg > 0) {
            $where .= " AND register_id = %d";
            $params[] = $reg;
        }

        $sql = $wpdb->prepare(
            "SELECT id, document_type, register_id, ticket_number, correlativo_id, total,
                    mh_status, mh_attempts, mh_last_attempt_at, mh_last_error, issued_at
             FROM wp_jc_invoices
             $where
             ORDER BY COALESCE(mh_last_attempt_at, issued_at) ASC
             LIMIT 200",
            ...$params
        );

        $rows = $wpdb->get_results($sql);
        $registers = $wpdb->get_results("SELECT id, register_name FROM wp_jc_registers ORDER BY register_name ASC");

        $batch_url = wp_nonce_url(
            admin_url('admin-post.php?action=jc_mh_batch_send&mh_status=' . urlencode($status)),
            'jc_mh_batch_send'
        );

        ?>
        <div class="wrap">
            <h1>MH Queue</h1>

            <form method="get" style="margin:10px 0;">
                <input type="hidden" name="page" value="jc-mh-queue">

                <label><strong>Status:</strong></label>
                <select name="mh_status">
                    <?php foreach (['PENDING','FAILED','REJECTED','SENT'] as $s): ?>
                        <option value="<?= esc_attr($s) ?>" <?= selected($status, $s, false) ?>><?= esc_html($s) ?></option>
                    <?php endforeach; ?>
                </select>

                <label style="margin-left:10px;"><strong>Doc:</strong></label>
                <select name="doc_type">
                    <option value="">All</option>
                    <?php foreach (['CONSUMIDOR_FINAL','CREDITO_FISCAL','NOTA_CREDITO'] as $t): ?>
                        <option value="<?= esc_attr($t) ?>" <?= selected($doc, $t, false) ?>><?= esc_html($t) ?></option>
                    <?php endforeach; ?>
                </select>

                <label style="margin-left:10px;"><strong>Register:</strong></label>
                <select name="register_id">
                    <option value="0">All</option>
                    <?php foreach ($registers as $r): ?>
                        <option value="<?= (int)$r->id ?>" <?= selected($reg, (int)$r->id, false) ?>><?= esc_html($r->register_name) ?></option>
                    <?php endforeach; ?>
                </select>

                <button class="button">Filter</button>
            </form>

            <p>
                <a class="button button-primary" href="<?= esc_url($batch_url) ?>"
                   onclick="return confirm('Run batch send for current filtered status?')">
                   Run batch send
                </a>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Issued</th>
                        <th>Type</th>
                        <th>Register</th>
                        <th>Ticket</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Last attempt</th>
                        <th>Last error</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11">No invoices found.</td></tr>
                <?php else: foreach ($rows as $row):
                    $retry = wp_nonce_url(
                        admin_url('admin-post.php?action=jc_mh_retry_invoice&invoice_id='.(int)$row->id),
                        'jc_mh_retry_invoice_' . (int)$row->id
                    );
                ?>
                    <tr>
                        <td><?= (int)$row->id ?></td>
                        <td><?= esc_html($row->issued_at) ?></td>
                        <td><?= esc_html($row->document_type) ?></td>
                        <td><?= (int)$row->register_id ?></td>
                        <td><?= (int)$row->ticket_number ?></td>
                        <td><?= esc_html($row->total) ?></td>
                        <td><?= esc_html($row->mh_status) ?></td>
                        <td><?= (int)$row->mh_attempts ?></td>
                        <td><?= esc_html($row->mh_last_attempt_at ?: '-') ?></td>
                        <td><?= esc_html($row->mh_last_error ?: '') ?></td>
                        <td><a class="button button-small" href="<?= esc_url($retry) ?>">Retry</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_batch_send() {
        if (!current_user_can('manage_options')) wp_die('No permission.');
        check_admin_referer('jc_mh_batch_send');

        $result = JC_MH_Sender_Service::batch_send(50);

        if (class_exists('JC_Audit_Service')) {
            JC_Audit_Service::log([
                'action' => 'mh_batch_send',
                'entity_type' => 'system',
                'message' => 'Batch send executed',
                'meta' => $result,
            ]);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=jc-mh-queue'));
        exit;
    }

    public static function handle_retry_one() {
        if (!current_user_can('manage_options')) wp_die('No permission.');

        $invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
        check_admin_referer('jc_mh_retry_invoice_' . $invoice_id);

        JC_MH_Sender_Service::send_one($invoice_id);

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=jc-mh-queue'));
        exit;
    }
}