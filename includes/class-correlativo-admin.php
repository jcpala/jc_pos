<?php
if (!defined('ABSPATH')) exit;

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

    private static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    private static function redirect_with_notice(string $type, string $code): void {
        $args = ['page' => 'jc-correlativos'];

        if ($type === 'success') {
            $args['jc_notice'] = $code;
        } else {
            $args['jc_error'] = $code;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function set_status(int $id, string $status, int $is_active): void {
        global $wpdb;

        $wpdb->update(
            self::table('jc_correlativos'),
            ['status' => $status, 'is_active' => $is_active],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );
    }

    private static function get_correlativo(int $id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table('jc_correlativos') . " WHERE id = %d",
                $id
            )
        );
    }

    private static function notice_message(string $code): string {
        switch ($code) {
            case 'activated':
                return 'Correlativo activated successfully.';
            case 'retired':
                return 'Correlativo retired.';
            default:
                return '';
        }
    }

    private static function error_message(string $code): string {
        switch ($code) {
            case 'invalid_nonce':
                return 'Security check failed (invalid nonce).';
            case 'not_found':
                return 'Correlativo not found.';
            case 'activate_only_pending':
                return 'Only PENDING correlativos can be activated.';
            case 'retire_only_active_pending':
                return 'Only ACTIVE or PENDING correlativos can be retired.';
            case 'unknown_action':
                return 'Unknown action.';
            case 'activate_failed':
                return 'Failed to activate correlativo.';
            default:
                return '';
        }
    }

    private static function handle_row_action(): void {
        if (!isset($_GET['jc_action'], $_GET['cid'])) {
            return;
        }

        global $wpdb;

        $action = sanitize_text_field(wp_unslash($_GET['jc_action']));
        $cid    = (int) $_GET['cid'];

        if (
            !isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_GET['_wpnonce'])),
                "jc_correlativo_action_{$action}_{$cid}"
            )
        ) {
            self::redirect_with_notice('error', 'invalid_nonce');
        }

        $row = self::get_correlativo($cid);

        if (!$row) {
            self::redirect_with_notice('error', 'not_found');
        }

        $t_correlativos = self::table('jc_correlativos');

        if ($action === 'activate') {
            if ($row->status !== 'PENDING') {
                self::redirect_with_notice('error', 'activate_only_pending');
            }

            $active = null;
            $previous_active_new_status = null;

            $wpdb->query('START TRANSACTION');

            try {
                $active = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT *
                         FROM {$t_correlativos}
                         WHERE register_id = %d
                           AND document_type = %s
                           AND status = 'ACTIVE'
                         LIMIT 1
                         FOR UPDATE",
                        (int) $row->register_id,
                        (string) $row->document_type
                    )
                );

                if ($active) {
                    $previous_active_new_status =
                        ((int) $active->current_number > (int) $active->range_end)
                            ? 'EXHAUSTED'
                            : 'RETIRED';

                    self::set_status((int) $active->id, $previous_active_new_status, 0);
                }

                self::set_status($cid, 'ACTIVE', 1);

                $wpdb->query('COMMIT');

                JC_Audit_Service::log([
                    'action'        => 'correlativo_activate',
                    'entity_type'   => 'correlativo',
                    'entity_id'     => $cid,
                    'register_id'   => (int) $row->register_id,
                    'document_type' => $row->document_type,
                    'message'       => 'Activated correlativo',
                    'meta'          => [
                        'activated_id'                => $cid,
                        'previous_active_id'          => $active ? (int) $active->id : null,
                        'previous_active_new_status'  => $active ? $previous_active_new_status : null,
                    ],
                ]);

                self::redirect_with_notice('success', 'activated');

            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');

                JC_Audit_Service::log([
                    'action'        => 'correlativo_activate_failed',
                    'entity_type'   => 'correlativo',
                    'entity_id'     => $cid,
                    'register_id'   => (int) $row->register_id,
                    'document_type' => $row->document_type,
                    'message'       => 'Failed to activate correlativo',
                    'meta'          => [
                        'error' => $e->getMessage(),
                    ],
                ]);

                self::redirect_with_notice('error', 'activate_failed');
            }
        }

        if ($action === 'retire') {
            if (!in_array($row->status, ['ACTIVE', 'PENDING'], true)) {
                self::redirect_with_notice('error', 'retire_only_active_pending');
            }

            self::set_status($cid, 'RETIRED', 0);

            JC_Audit_Service::log([
                'action'        => 'correlativo_retire',
                'entity_type'   => 'correlativo',
                'entity_id'     => $cid,
                'register_id'   => (int) $row->register_id,
                'document_type' => $row->document_type,
                'message'       => 'Retired correlativo',
                'meta'          => [
                    'status_from' => $row->status,
                    'status_to'   => 'RETIRED',
                ],
            ]);

            self::redirect_with_notice('success', 'retired');
        }

        self::redirect_with_notice('error', 'unknown_action');
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        global $wpdb;

        self::handle_row_action();

        $messages = [];
        $errors = [];

        $notice_code = isset($_GET['jc_notice']) ? sanitize_text_field(wp_unslash($_GET['jc_notice'])) : '';
        $error_code  = isset($_GET['jc_error']) ? sanitize_text_field(wp_unslash($_GET['jc_error'])) : '';

        $notice_message = self::notice_message($notice_code);
        $error_message  = self::error_message($error_code);

        if ($notice_message !== '') {
            $messages[] = $notice_message;
        }

        if ($error_message !== '') {
            $errors[] = $error_message;
        }

        $t_registers    = self::table('jc_registers');
        $t_correlativos = self::table('jc_correlativos');
        $t_audit        = self::table('jc_audit_log');

        // ---------- Save threshold ----------
        if (isset($_POST['jc_save_threshold'])) {
            check_admin_referer('jc_save_threshold_action', 'jc_save_threshold_nonce');

            $threshold = isset($_POST['jc_low_ticket_threshold']) ? (int) $_POST['jc_low_ticket_threshold'] : 100;
            if ($threshold < 1) {
                $threshold = 1;
            }

            update_option('jc_low_ticket_threshold', $threshold);

            JC_Audit_Service::log([
                'action'      => 'threshold_update',
                'entity_type' => 'settings',
                'entity_id'   => null,
                'message'     => 'Updated low ticket warning threshold',
                'meta'        => ['threshold' => $threshold],
            ]);

            $messages[] = "Threshold saved: {$threshold}";
        }

        // ---------- Add correlativo ----------
        if (isset($_POST['jc_add_correlativo'])) {
            check_admin_referer('jc_add_correlativo_action', 'jc_add_correlativo_nonce');

            $register_id        = isset($_POST['register_id']) ? (int) $_POST['register_id'] : 0;
            $document_type      = isset($_POST['document_type']) ? sanitize_text_field(wp_unslash($_POST['document_type'])) : '';
            $correlativo_number = isset($_POST['correlativo_number']) ? sanitize_text_field(wp_unslash($_POST['correlativo_number'])) : '';
            $range_start        = isset($_POST['range_start']) ? (int) $_POST['range_start'] : 0;
            $range_end          = isset($_POST['range_end']) ? (int) $_POST['range_end'] : 0;
            $issue_date         = isset($_POST['issue_date']) ? sanitize_text_field(wp_unslash($_POST['issue_date'])) : '';

            $allowed_types = ['CONSUMIDOR_FINAL', 'CREDITO_FISCAL', 'NOTA_CREDITO'];

            if ($register_id <= 0) $errors[] = 'Register is required.';
            if (!in_array($document_type, $allowed_types, true)) $errors[] = 'Invalid document type.';
            if ($correlativo_number === '') $errors[] = 'Correlativo number is required.';
            if ($range_start <= 0) $errors[] = 'Range start must be greater than 0.';
            if ($range_end <= 0) $errors[] = 'Range end must be greater than 0.';
            if ($range_start > $range_end) $errors[] = 'Range start cannot be greater than range end.';
            if (!$issue_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) $errors[] = 'Issue date must be YYYY-MM-DD.';

            if (empty($errors)) {
                $reg_exists = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM {$t_registers} WHERE id = %d", $register_id)
                );

                if ($reg_exists === 0) {
                    $errors[] = 'Selected register does not exist.';
                }
            }

            if (empty($errors)) {
                $overlap = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM {$t_correlativos}
                         WHERE register_id = %d
                           AND document_type = %s
                           AND (%d <= range_end AND %d >= range_start)",
                        $register_id,
                        $document_type,
                        $range_start,
                        $range_end
                    )
                );

                if ($overlap > 0) {
                    $errors[] = 'This range overlaps an existing correlativo for the same register and document type.';
                }
            }

            if (empty($errors)) {
                $inserted = $wpdb->insert(
                    $t_correlativos,
                    [
                        'register_id'        => $register_id,
                        'document_type'      => $document_type,
                        'correlativo_number' => $correlativo_number,
                        'range_start'        => $range_start,
                        'range_end'          => $range_end,
                        'current_number'     => $range_start,
                        'issue_date'         => $issue_date,
                        'status'             => 'PENDING',
                        'is_active'          => 0,
                    ],
                    ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d']
                );

                if ($inserted) {
                    $new_id = (int) $wpdb->insert_id;

                    JC_Audit_Service::log([
                        'action'        => 'correlativo_add',
                        'entity_type'   => 'correlativo',
                        'entity_id'     => $new_id,
                        'register_id'   => $register_id,
                        'document_type' => $document_type,
                        'message'       => 'Added correlativo as PENDING',
                        'meta'          => [
                            'correlativo_number' => $correlativo_number,
                            'range_start'        => $range_start,
                            'range_end'          => $range_end,
                            'issue_date'         => $issue_date,
                            'current_number'     => $range_start,
                        ],
                    ]);

                    $messages[] = 'Correlativo added as PENDING.';
                } else {
                    $errors[] = 'Database error: could not insert correlativo.';
                }
            }
        }

        $registers = $wpdb->get_results(
            "SELECT id, register_name FROM {$t_registers} ORDER BY register_name ASC"
        ) ?: [];

        $threshold = (int) get_option('jc_low_ticket_threshold', 100);

        foreach ($messages as $m) {
            echo "<div class='notice notice-success'><p>" . esc_html($m) . "</p></div>";
        }

        foreach ($errors as $e) {
            echo "<div class='notice notice-error'><p>" . esc_html($e) . "</p></div>";
        }
        ?>
        <div class="wrap">
            <h1>Correlativos</h1>

            <h2>Low Ticket Warning</h2>
            <form method="post" style="margin: 10px 0;">
                <?php wp_nonce_field('jc_save_threshold_action', 'jc_save_threshold_nonce'); ?>
                <label><strong>Warn when remaining tickets ≤</strong></label>
                <input type="number" name="jc_low_ticket_threshold" value="<?php echo (int) $threshold; ?>" min="1" style="width:120px;">
                <button class="button">Save</button>
                <input type="hidden" name="jc_save_threshold" value="1">
            </form>

            <hr>

            <h2>Add Correlativo (PENDING)</h2>
            <form method="post">
                <?php wp_nonce_field('jc_add_correlativo_action', 'jc_add_correlativo_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th>Register</th>
                        <td>
                            <select name="register_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($registers as $r) : ?>
                                    <option value="<?php echo (int) $r->id; ?>">
                                        <?php echo esc_html($r->register_name); ?>
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

            <h2>Existing Correlativos</h2>
            <table class="widefat striped">
                <thead>
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
                </thead>
                <tbody>
                <?php
                $rows = $wpdb->get_results("
                    SELECT c.*, r.register_name
                    FROM {$t_correlativos} c
                    JOIN {$t_registers} r ON r.id = c.register_id
                    ORDER BY c.created_at DESC
                ") ?: [];

                foreach ($rows as $row) :
                    $remaining = (int) $row->range_end - (int) $row->current_number + 1;
                    if ($remaining < 0) $remaining = 0;

                    $low_style = '';
                    if ($row->status === 'ACTIVE' && $remaining <= $threshold) {
                        $low_style = 'style="font-weight:bold;"';
                    }

                    $actions = [];

                    if ($row->status === 'PENDING') {
                        $nonce = wp_create_nonce("jc_correlativo_action_activate_{$row->id}");
                        $url = add_query_arg([
                            'page'     => 'jc-correlativos',
                            'jc_action'=> 'activate',
                            'cid'      => (int) $row->id,
                            '_wpnonce' => $nonce,
                        ], admin_url('admin.php'));

                        $actions[] = '<a class="button button-small" href="' . esc_url($url) . '">Activate</a>';
                    }

                    if (in_array($row->status, ['ACTIVE', 'PENDING'], true)) {
                        $nonce = wp_create_nonce("jc_correlativo_action_retire_{$row->id}");
                        $url = add_query_arg([
                            'page'     => 'jc-correlativos',
                            'jc_action'=> 'retire',
                            'cid'      => (int) $row->id,
                            '_wpnonce' => $nonce,
                        ], admin_url('admin.php'));

                        $actions[] = '<a class="button button-small" href="' . esc_url($url) . '" onclick="return confirm(\'Retire this correlativo?\')">Retire</a>';
                    }

                    $actions_html = empty($actions) ? '-' : implode(' ', $actions);
                    ?>
                    <tr>
                        <td><?php echo esc_html($row->register_name); ?></td>
                        <td><?php echo esc_html($row->document_type); ?></td>
                        <td><?php echo esc_html($row->correlativo_number); ?></td>
                        <td><?php echo (int) $row->range_start; ?> - <?php echo (int) $row->range_end; ?></td>
                        <td><?php echo (int) $row->current_number; ?></td>
                        <td <?php echo $low_style; ?>><?php echo (int) $remaining; ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
                        <td><?php echo $actions_html; ?></td>
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
                    FROM {$t_audit} l
                    LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                    ORDER BY l.id DESC
                    LIMIT 100
                ") ?: [];

                foreach ($logs as $log) :
                    $entity = esc_html($log->entity_type) . ($log->entity_id ? ' #' . (int) $log->entity_id : '');
                    ?>
                    <tr>
                        <td><?php echo esc_html($log->created_at); ?></td>
                        <td><?php echo esc_html($log->display_name ?: ('User #' . (int) $log->user_id)); ?></td>
                        <td><?php echo esc_html($log->action); ?></td>
                        <td><?php echo $entity; ?></td>
                        <td><?php echo $log->register_id ? (int) $log->register_id : '-'; ?></td>
                        <td><?php echo $log->document_type ? esc_html($log->document_type) : '-'; ?></td>
                        <td><?php echo esc_html($log->message ?: ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}