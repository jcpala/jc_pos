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

        $status = isset($_GET['mh_status']) ? sanitize_text_field($_GET['mh_status']) : 'UNSENT';
        $doc    = isset($_GET['doc_type']) ? sanitize_text_field($_GET['doc_type']) : '';
        $reg    = isset($_GET['register_id']) ? (int)$_GET['register_id'] : 0;
        $tbl_invoices  = $wpdb->prefix . 'jc_invoices';
        $tbl_registers = $wpdb->prefix . 'jc_registers';
        
        $allowed_status = ['UNSENT','PENDING','FAILED','REJECTED','SENT'];
        if (!in_array($status, $allowed_status, true)) $status = 'UNSENT';
        
        // Always limit to ISSUED invoices (avoid VOIDED/REFUNDED noise)
        $where = "WHERE status = 'ISSUED' ";
        $params = [];
        
        if ($status === 'UNSENT') {
            $where .= "AND mh_status IN ('PENDING','FAILED','REJECTED') ";
        } else {
            $where .= "AND mh_status = %s ";
            $params[] = $status;
        }
        
        if ($doc !== '') {
            $where .= "AND document_type = %s ";
            $params[] = $doc;
        }
        if ($reg > 0) {
            $where .= "AND register_id = %d ";
            $params[] = $reg;
        }

        
        $sql = "
            SELECT id, document_type, register_id, ticket_number, correlativo_id, total,
                   mh_status, mh_attempts, mh_last_attempt_at, mh_last_error, issued_at
            FROM {$tbl_invoices}
            $where
            ORDER BY COALESCE(mh_last_attempt_at, issued_at) ASC
            LIMIT 200
        ";
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        
        $rows = $wpdb->get_results($sql);
        $registers = $wpdb->get_results("SELECT id, register_name FROM {$tbl_registers} ORDER BY register_name ASC");

        $batch_url = wp_nonce_url(
            admin_url('admin-post.php?action=jc_mh_batch_send'
                . '&mh_status=' . urlencode($status)
                . '&doc_type=' . urlencode($doc)
                . '&register_id=' . urlencode((string)$reg)
            ),
            'jc_mh_batch_send'
        );

        ?>
        <div class="wrap">
            <h1>MH Queue</h1>
            <?php
$jc_mh_notice = isset($_GET['jc_mh_notice']) ? sanitize_text_field((string)$_GET['jc_mh_notice']) : '';
$jc_mh_msg    = isset($_GET['jc_mh_msg']) ? sanitize_text_field((string)rawurldecode($_GET['jc_mh_msg'])) : '';

if ($jc_mh_notice !== '' && $jc_mh_msg !== ''):
?>
    <div class="notice notice-<?php echo esc_attr($jc_mh_notice === 'success' ? 'success' : 'error'); ?> is-dismissible">
        <p><?php echo esc_html($jc_mh_msg); ?></p>
    </div>
<?php endif; ?>

            <form method="get" style="margin:10px 0;">
                <input type="hidden" name="page" value="jc-mh-queue">

                <label><strong>Status:</strong></label>
                <select name="mh_status">
                <?php foreach (['UNSENT','PENDING','FAILED','REJECTED','SENT'] as $s): ?>
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
                        <td> <?php if (in_array((string)$row->mh_status, ['PENDING', 'FAILED'], true)): ?>
                            <a class="button button-small" href="<?= esc_url($retry) ?>">Retry</a>
                        <?php else: ?>
                            <span style="opacity:.45;">—</span>
                        <?php endif; ?>
                        </td></td>
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

        $status = isset($_GET['mh_status']) ? sanitize_text_field($_GET['mh_status']) : 'UNSENT';
        $doc    = isset($_GET['doc_type']) ? sanitize_text_field($_GET['doc_type']) : '';
        $reg    = isset($_GET['register_id']) ? (int)$_GET['register_id'] : 0;
        
        // If your service supports filters, use them.
        // Otherwise, batch_send should default to unsent.
        if (method_exists('JC_MH_Sender_Service', 'batch_send_filtered')) {
            $result = JC_MH_Sender_Service::batch_send_filtered(50, $status, $doc, $reg);
        } else {
            $result = JC_MH_Sender_Service::batch_send(50);
        }

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
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }
    
        $invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
        if ($invoice_id <= 0) {
            wp_die('Missing invoice_id.');
        }
    
        check_admin_referer('jc_mh_retry_invoice_' . $invoice_id);
    
        global $wpdb;
        $tbl_invoices = $wpdb->prefix . 'jc_invoices';
    
        $invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, mh_status, ticket_number, document_type FROM {$tbl_invoices} WHERE id = %d LIMIT 1",
                $invoice_id
            ),
            ARRAY_A
        );
    
        if (!$invoice) {
            self::redirect_with_notice('error', 'Invoice not found.');
        }
    
        $mh_status = strtoupper((string)($invoice['mh_status'] ?? ''));
    
        if (!in_array($mh_status, ['PENDING', 'FAILED'], true)) {
            self::redirect_with_notice('error', 'This invoice cannot be re-submitted from its current MH status.');
        }
    
        try {
            if (!class_exists('JC_MH_Sender_Service') || !method_exists('JC_MH_Sender_Service', 'send_one')) {
                throw new Exception('MH sender service is not available.');
            }
    
            $result = JC_MH_Sender_Service::send_one($invoice_id);

            $email_result = null;
            
            if (
                is_array($result) &&
                !empty($result['mh_status']) &&
                $result['mh_status'] === 'SENT' &&
                class_exists('JC_POS_Email_Service')
            ) {
                $email_service = new JC_POS_Email_Service();
                $email_result = $email_service->send_documents_for_invoice($invoice_id);
            
                error_log('JC EMAIL AFTER MH RETRY invoice ' . $invoice_id . ': ' . print_r($email_result, true));
            }
            
            error_log('JC MH RETRY RESULT invoice ' . $invoice_id . ': ' . print_r($result, true));
            
            $ok  = false;
            $msg = 'MH re-submission finished.';
            
            if (is_array($result)) {
                if (isset($result['ok'])) {
                    $ok = (bool)$result['ok'];
                } elseif (isset($result['success'])) {
                    $ok = (bool)$result['success'];
                }
            
                if (!empty($result['message'])) {
                    $msg = (string)$result['message'];
                } elseif (!empty($result['error'])) {
                    $msg = (string)$result['error'];
                } elseif (!empty($result['descripcionMsg'])) {
                    $msg = (string)$result['descripcionMsg'];
                } elseif (!empty($result['mh_status']) && $result['mh_status'] === 'SENT') {
                    $msg = 'MH re-submission succeeded.';
                }
            } elseif ($result === true) {
                $ok = true;
                $msg = 'MH re-submission succeeded.';
            } elseif ($result === false) {
                $ok = false;
                $msg = 'MH re-submission returned false.';
            }
            
            if ($ok && is_array($email_result)) {
                if (!empty($email_result['ok'])) {
                    $msg .= ' Email sent successfully.';
                } else {
                    $email_msg = !empty($email_result['message']) ? (string)$email_result['message'] : 'Email was not sent.';
                    $msg .= ' Email result: ' . $email_msg;
                }
            }
    
            if (class_exists('JC_Audit_Service')) {
                JC_Audit_Service::log([
                    'action'      => 'mh_retry_one',
                    'entity_type' => 'invoice',
                    'entity_id'   => $invoice_id,
                    'message'     => $msg,
                    'meta'        => is_array($result) ? $result : ['result' => $result],
                ]);
            }
    
            self::redirect_with_notice($ok ? 'success' : 'error', $msg);
    
        } catch (Throwable $e) {
            error_log('JC MH RETRY ERROR invoice ' . $invoice_id . ': ' . $e->getMessage());
    
            if (class_exists('JC_Audit_Service')) {
                JC_Audit_Service::log([
                    'action'      => 'mh_retry_one_failed',
                    'entity_type' => 'invoice',
                    'entity_id'   => $invoice_id,
                    'message'     => $e->getMessage(),
                    'meta'        => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ]);
            }
    
            self::redirect_with_notice('error', $e->getMessage());
        }
    }
    
    private static function redirect_with_notice(string $type, string $message): void {
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=jc-mh-queue');
    
        $redirect = add_query_arg([
            'jc_mh_notice' => $type === 'success' ? 'success' : 'error',
            'jc_mh_msg'    => rawurlencode($message),
        ], $redirect);
    
        wp_safe_redirect($redirect);
        exit;
    }
}