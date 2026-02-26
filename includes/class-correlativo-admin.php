<?php

class JC_Correlativo_Admin {

    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu() {
        add_menu_page(
            'Correlativos',
            'Correlativos',
            'manage_options',
            'jc-correlativos',
            [self::class, 'page'],
            'dashicons-media-spreadsheet',
            56
        );
    }
    private static function set_status(int $id, string $status, int $is_active): void {
        global $wpdb;
    
        $wpdb->update(
            'wp_jc_correlativos',
            ['status' => $status, 'is_active' => $is_active],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );
    }
    
    private static function get_correlativo(int $id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM wp_jc_correlativos WHERE id = %d", $id)
        );
    }
    public static function page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        global $wpdb;

        $messages = [];
        $errors = [];
        
// ---------- Handle row actions (Activate / Retire) ----------
if (isset($_GET['jc_action'], $_GET['cid'])) {

    $action = sanitize_text_field($_GET['jc_action']);
    $cid    = (int) $_GET['cid'];

    // Verify nonce
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], "jc_correlativo_action_{$action}_{$cid}")) {
        $errors[] = "Security check failed (invalid nonce).";
    } else {

        $row = self::get_correlativo($cid);

        if (!$row) {
            $errors[] = "Correlativo not found.";
        } else {

            if ($action === 'activate') {

                if ($row->status !== 'PENDING') {
                    $errors[] = "Only PENDING correlativos can be activated.";
                } else {

                    // Use transaction to avoid race conditions
                    $wpdb->query('START TRANSACTION');

                    try {
                        // Lock all correlativos for this register + type
                        $locked = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT id, status, current_number, range_end
                                 FROM wp_jc_correlativos
                                 WHERE register_id = %d
                                   AND document_type = %s
                                 FOR UPDATE",
                                (int)$row->register_id,
                                $row->document_type
                            )
                        );

                        // Retire current ACTIVE (if exists)
                        $active = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT *
                                 FROM wp_jc_correlativos
                                 WHERE register_id = %d
                                   AND document_type = %s
                                   AND status = 'ACTIVE'
                                 LIMIT 1
                                 FOR UPDATE",
                                (int)$row->register_id,
                                $row->document_type
                            )
                        );

                        if ($active) {
                            // If already out of range, mark as EXHAUSTED, else RETIRED
                            $newStatus = ((int)$active->current_number > (int)$active->range_end) ? 'EXHAUSTED' : 'RETIRED';
                            self::set_status((int)$active->id, $newStatus, 0);
                        }

                        // Activate selected correlativo
                        self::set_status($cid, 'ACTIVE', 1);
                        // Audit: invoice issued
JC_Audit_Service::log([
    'action'       => 'invoice_issued',
    'entity_type'  => 'invoice',
    'entity_id'    => (int) $invoice_id,
    'store_id'     => isset($data['store_id']) ? (int)$data['store_id'] : null,
    'register_id'  => (int) $data['register_id'],
    'document_type'=> $data['document_type'],
    'message'      => 'Issued fiscal document',
    'meta' => [
        'correlativo_id'     => (int) $correlativo->id,
        'correlativo_number' => $correlativo->correlativo_number ?? null,
        'ticket_number'      => (int) $ticket_number,
        'subtotal'           => (float) $subtotal,
        'tax_total'          => (float) $tax_total,
        'total'              => (float) $grand_total,
        'items_count'        => count($items_calculated),
        // optional: store buyer info for CF/CCF (don’t include sensitive beyond what’s required)
        'customer_name'      => $data['customer_name'] ?? null,
        'customer_nit'       => $data['customer_nit'] ?? null,
    ]
]);
                        $wpdb->query('COMMIT');
                        $messages[] = "Correlativo activated successfully.";
                        JC_Audit_Service::log([
                            'action' => 'correlativo_activate',
                            'entity_type' => 'correlativo',
                            'entity_id' => $cid,
                            'register_id' => (int)$row->register_id,
                            'document_type' => $row->document_type,
                            'message' => 'Activated correlativo',
                            'meta' => [
                                'activated_id' => $cid,
                                'previous_active_id' => $active ? (int)$active->id : null,
                                'previous_active_new_status' => $active ? $newStatus : null,
                            ],
                        ]);

                    } catch (Exception $e) {
                        $wpdb->query('ROLLBACK');
                        JC_Audit_Service::log([
                            'action'       => 'invoice_issue_failed',
                            'entity_type'  => 'invoice',
                            'entity_id'    => null,
                            'store_id'     => isset($data['store_id']) ? (int)$data['store_id'] : null,
                            'register_id'  => isset($data['register_id']) ? (int)$data['register_id'] : null,
                            'document_type'=> $data['document_type'] ?? null,
                            'message'      => 'Failed to issue fiscal document',
                            'meta' => [
                                'error' => $e->getMessage(),
                                'correlativo_id' => isset($correlativo->id) ? (int)$correlativo->id : null,
                            ]
                        ]);
                        $errors[] = "Failed to activate correlativo: " . $e->getMessage();
                    }
                }

            } elseif ($action === 'retire') {

                if (!in_array($row->status, ['ACTIVE','PENDING'], true)) {
                    $errors[] = "Only ACTIVE or PENDING correlativos can be retired.";
                } else {
                    // If it is active, retiring it will stop issuance for that type until another is activated
                    self::set_status($cid, 'RETIRED', 0);
                    $messages[] = "Correlativo retired.";
                    JC_Audit_Service::log([
                        'action' => 'correlativo_retire',
                        'entity_type' => 'correlativo',
                        'entity_id' => $cid,
                        'register_id' => (int)$row->register_id,
                        'document_type' => $row->document_type,
                        'message' => 'Retired correlativo',
                        'meta' => [
                            'status_from' => $row->status,
                            'status_to' => 'RETIRED',
                        ],
                    ]);
                }

            } else {
                $errors[] = "Unknown action.";
            }
        }
    }
            wp_safe_redirect(admin_url('admin.php?page=jc-correlativos&jc_msg=1'));
        exit;
}
        // ---------- Save threshold ----------
        if (isset($_POST['jc_save_threshold'])) {
            // Nonce check
            check_admin_referer('jc_save_threshold_action', 'jc_save_threshold_nonce');

            $threshold = isset($_POST['jc_low_ticket_threshold']) ? (int) $_POST['jc_low_ticket_threshold'] : 100;
            if ($threshold < 1) {
                $threshold = 1;
            }
            update_option('jc_low_ticket_threshold', $threshold);
            JC_Audit_Service::log([
                'action' => 'threshold_update',
                'entity_type' => 'settings',
                'entity_id' => null,
                'message' => 'Updated low ticket warning threshold',
                'meta' => ['threshold' => $threshold],
            ]);

            $messages[] = "Threshold saved: {$threshold}";
        }

        // ---------- Add correlativo ----------
        if (isset($_POST['jc_add_correlativo'])) {
            // Nonce check
            check_admin_referer('jc_add_correlativo_action', 'jc_add_correlativo_nonce');

            $register_id = isset($_POST['register_id']) ? (int) $_POST['register_id'] : 0;
            $document_type = isset($_POST['document_type']) ? sanitize_text_field($_POST['document_type']) : '';
            $correlativo_number = isset($_POST['correlativo_number']) ? sanitize_text_field($_POST['correlativo_number']) : '';
            $range_start = isset($_POST['range_start']) ? (int) $_POST['range_start'] : 0;
            $range_end = isset($_POST['range_end']) ? (int) $_POST['range_end'] : 0;
            $issue_date = isset($_POST['issue_date']) ? sanitize_text_field($_POST['issue_date']) : '';

            $allowed_types = ['CONSUMIDOR_FINAL', 'CREDITO_FISCAL', 'NOTA_CREDITO'];

            // Basic validation
            if ($register_id <= 0) $errors[] = "Register is required.";
            if (!in_array($document_type, $allowed_types, true)) $errors[] = "Invalid document type.";
            if ($correlativo_number === '') $errors[] = "Correlativo number is required.";
            if ($range_start <= 0) $errors[] = "Range start must be greater than 0.";
            if ($range_end <= 0) $errors[] = "Range end must be greater than 0.";
            if ($range_start > $range_end) $errors[] = "Range start cannot be greater than range end.";
            if (!$issue_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) $errors[] = "Issue date must be YYYY-MM-DD.";

            // Ensure register exists
            if (empty($errors)) {
                $reg_exists = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM wp_jc_registers WHERE id=%d", $register_id)
                );
                if ($reg_exists === 0) {
                    $errors[] = "Selected register does not exist.";
                }
            }

            // Prevent overlapping ranges for same register + type (across ALL correlativos)
            // Overlap test:
            // new_start <= existing_end AND new_end >= existing_start
            if (empty($errors)) {
                $overlap = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM wp_jc_correlativos
                         WHERE register_id = %d
                           AND document_type = %s
                           AND ( %d <= range_end AND %d >= range_start )",
                        $register_id,
                        $document_type,
                        $range_start,
                        $range_end
                    )
                );

                if ($overlap > 0) {
                    $errors[] = "This range overlaps an existing correlativo for the same register and document type.";
                }
            }

            // Insert if ok
            if (empty($errors)) {

                // current_number should be the NEXT number to issue.
                // So we start it at range_start.
                $inserted = $wpdb->insert('wp_jc_correlativos', [
                    'register_id'        => $register_id,
                    'document_type'      => $document_type,
                    'correlativo_number' => $correlativo_number,
                    'range_start'        => $range_start,
                    'range_end'          => $range_end,
                    'current_number'     => $range_start,
                    'issue_date'         => $issue_date,
                    'status'             => 'PENDING',
                    'is_active'          => 0
                ], [
                    '%d','%s','%s','%d','%d','%d','%s','%s','%d'
                ]);

                if ($inserted) {
                    $messages[] = "Correlativo added as PENDING.";
                    $new_id = (int) $wpdb->insert_id;

            JC_Audit_Service::log([
                'action' => 'correlativo_add',
                'entity_type' => 'correlativo',
                'entity_id' => $new_id,
                'register_id' => $register_id,
                'document_type' => $document_type,
                'message' => 'Added correlativo as PENDING',
            'meta' => [
                'correlativo_number' => $correlativo_number,
                'range_start' => $range_start,
                'range_end' => $range_end,
                'issue_date' => $issue_date,
                'current_number' => $range_start,
    ],
]);
                } else {
                    $errors[] = "Database error: could not insert correlativo.";
                }
            }
        }

        // Fetch registers
        $registers = $wpdb->get_results("SELECT id, register_name FROM wp_jc_registers ORDER BY register_name ASC");

        $threshold = (int) get_option('jc_low_ticket_threshold', 100);

        // Output notices
        foreach ($messages as $m) {
            echo "<div class='notice notice-success'><p>" . esc_html($m) . "</p></div>";
        }
        foreach ($errors as $e) {
            echo "<div class='notice notice-error'><p>" . esc_html($e) . "</p></div>";
        }

        ?>
        <div class="wrap">
            <h1>Correlativos</h1>

            <!-- Threshold form -->
            <h2>Low Ticket Warning</h2>
            <form method="post" style="margin: 10px 0;">
                <?php wp_nonce_field('jc_save_threshold_action', 'jc_save_threshold_nonce'); ?>
                <label><strong>Warn when remaining tickets ≤</strong></label>
                <input type="number" name="jc_low_ticket_threshold" value="<?= (int)$threshold ?>" min="1" style="width:120px;">
                <button class="button">Save</button>
                <input type="hidden" name="jc_save_threshold" value="1">
            </form>

            <hr>

            <!-- Add correlativo form -->
            <h2>Add Correlativo (PENDING)</h2>
            <form method="post">
                <?php wp_nonce_field('jc_add_correlativo_action', 'jc_add_correlativo_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th>Register</th>
                        <td>
                            <select name="register_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($registers as $r): ?>
                                    <option value="<?= (int)$r->id ?>">
                                        <?= esc_html($r->register_name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>Document Type</th>
                        <td>
                            <select name="document_type" required>
                                <option value="CONSUMIDOR_FINAL">Consumidor Final</option>
                                <option value="CREDITO_FISCAL">Crédito Fiscal</option>
                                <option value="NOTA_CREDITO">Nota de Crédito</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>Correlativo Number</th>
                        <td><input type="text" name="correlativo_number" required></td>
                    </tr>

                    <tr>
                        <th>Range Start</th>
                        <td><input type="number" name="range_start" required min="1"></td>
                    </tr>

                    <tr>
                        <th>Range End</th>
                        <td><input type="number" name="range_end" required min="1"></td>
                    </tr>

                    <tr>
                        <th>Issue Date</th>
                        <td><input type="date" name="issue_date" required></td>
                    </tr>
                </table>

                <input type="submit" name="jc_add_correlativo" class="button button-primary" value="Add Correlativo">
            </form>

            <hr>

            <!-- List correlativos -->
            <h2>Existing Correlativos</h2>
            <table class="widefat striped">
            <tr>
  <th>Register</th>
  <th>Type</th>
  <th>Correlativo</th>
  <th>Range</th>
  <th>Current (Next)</th>
  <th>Remaining</th>
  <th>Status</th>
  <th>Actions</th>
</tr>
                <tbody>
                <?php
                $rows = $wpdb->get_results("
                    SELECT c.*, r.register_name
                    FROM wp_jc_correlativos c
                    JOIN wp_jc_registers r ON r.id = c.register_id
                    ORDER BY c.created_at DESC
                ");

$threshold = (int) get_option('jc_low_ticket_threshold', 100);

foreach ($rows as $row):
    $remaining = (int)$row->range_end - (int)$row->current_number + 1;
    if ($remaining < 0) $remaining = 0;

    $low_style = '';
    if ($row->status === 'ACTIVE' && $remaining <= $threshold) {
        $low_style = 'style="font-weight:bold;"';
    }

    // Build action links with nonce
    $actions = [];

    if ($row->status === 'PENDING') {
        $nonce = wp_create_nonce("jc_correlativo_action_activate_{$row->id}");
        $url = add_query_arg([
            'page' => 'jc-correlativos',
            'jc_action' => 'activate',
            'cid' => (int)$row->id,
            '_wpnonce' => $nonce
        ], admin_url('admin.php'));
        $actions[] = '<a class="button button-small" href="' . esc_url($url) . '">Activate</a>';
    }

    if (in_array($row->status, ['ACTIVE','PENDING'], true)) {
        $nonce = wp_create_nonce("jc_correlativo_action_retire_{$row->id}");
        $url = add_query_arg([
            'page' => 'jc-correlativos',
            'jc_action' => 'retire',
            'cid' => (int)$row->id,
            '_wpnonce' => $nonce
        ], admin_url('admin.php'));
        $actions[] = '<a class="button button-small" href="' . esc_url($url) . '" onclick="return confirm(\'Retire this correlativo?\')">Retire</a>';
    }

    $actions_html = empty($actions) ? '-' : implode(' ', $actions);
?>
<tr>
    <td><?= esc_html($row->register_name) ?></td>
    <td><?= esc_html($row->document_type) ?></td>
    <td><?= esc_html($row->correlativo_number) ?></td>
    <td><?= (int)$row->range_start ?> - <?= (int)$row->range_end ?></td>
    <td><?= (int)$row->current_number ?></td>
    <td <?= $low_style ?>><?= (int)$remaining ?></td>
    <td><?= esc_html($row->status) ?></td>
    <td><?= $actions_html ?></td>
</tr>
            <?php endforeach; ?>
                </tbody>
            </table>
            <h2>Audit Log (Last 100)</h2>

            <table class="widefat striped">
            <thead>
            <tr>
                <th>Date</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Register</th>
                <th>Doc Type</th>
                <th>Message</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $logs = $wpdb->get_results("
            SELECT l.*, u.display_name
            FROM wp_jc_audit_log l
            LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
            ORDER BY l.id DESC
            LIMIT 100
        ");

    foreach ($logs as $log):
        $entity = esc_html($log->entity_type) . ($log->entity_id ? ' #' . (int)$log->entity_id : '');
    ?>
        <tr>
            <td><?= esc_html($log->created_at) ?></td>
            <td><?= esc_html($log->display_name ?: ('User #' . (int)$log->user_id)) ?></td>
            <td><?= esc_html($log->action) ?></td>
            <td><?= $entity ?></td>
            <td><?= $log->register_id ? (int)$log->register_id : '-' ?></td>
            <td><?= $log->document_type ? esc_html($log->document_type) : '-' ?></td>
            <td><?= esc_html($log->message ?: '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
        </div>
        <?php
    }
}