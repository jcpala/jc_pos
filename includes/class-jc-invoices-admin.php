<?php

class JC_Invoices_Admin {

    /**
     * Change this if your parent menu slug is different.
     * If you see front-end 404 when clicking the menu, this is the first thing to fix.
     */
    //private const PARENT_SLUG = 'jc-correlativos';
    private const PARENT_SLUG = 'class-jc-invoices-admin.php';
    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu() {
        // Visible list page
        add_submenu_page(
            self::PARENT_SLUG,
            'Invoices',
            'Invoices',
            'manage_options',
            'jc-invoices',
            [self::class, 'page_invoices']
        );

        // Hidden receipt page
        add_submenu_page(
            null,
            'Invoice Receipt',
            'Invoice Receipt',
            'manage_options',
            'jc-invoice-receipt',
            [self::class, 'page_receipt']
        );
    }

    private static function t(string $name): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private static function str_from_get(string $key): string {
        // Never return null
        return isset($_GET[$key]) ? (string)sanitize_text_field((string)$_GET[$key]) : '';
    }

    private static function int_from_get(string $key): int {
        return isset($_GET[$key]) ? (int)$_GET[$key] : 0;
    }

    public static function page_invoices() {
        if (!current_user_can('manage_options')) wp_die('No permission.');
        global $wpdb;

        $tbl_invoices  = self::t('jc_invoices');
        $tbl_registers = self::t('jc_registers');

        // Filters (strings always, never null)
        $doc    = self::str_from_get('doc_type');
        $status = self::str_from_get('status');
        $mh     = self::str_from_get('mh_status');
        $from   = self::str_from_get('date_from'); // YYYY-MM-DD
        $to     = self::str_from_get('date_to');   // YYYY-MM-DD
        $q      = self::str_from_get('q');
        $reg    = self::int_from_get('register_id');

        $page     = max(1, self::int_from_get('paged'));
        $per_page = 50;
        $offset   = ($page - 1) * $per_page;

        // Whitelists
        $allowed_doc    = ['CONSUMIDOR_FINAL','CREDITO_FISCAL','NOTA_CREDITO'];
        $allowed_status = ['ISSUED','VOIDED','REFUNDED'];
        $allowed_mh     = ['PENDING','SENT','FAILED','REJECTED'];

        if ($doc !== '' && !in_array($doc, $allowed_doc, true)) $doc = '';
        if ($status !== '' && !in_array($status, $allowed_status, true)) $status = '';
        if ($mh !== '' && !in_array($mh, $allowed_mh, true)) $mh = '';

        // WHERE
        $where  = "WHERE 1=1 ";
        $params = [];

        if ($doc !== '') {
            $where .= "AND i.document_type = %s ";
            $params[] = $doc;
        }
        if ($status !== '') {
            $where .= "AND i.status = %s ";
            $params[] = $status;
        }
        if ($mh !== '') {
            $where .= "AND i.mh_status = %s ";
            $params[] = $mh;
        }
        if ($reg > 0) {
            $where .= "AND i.register_id = %d ";
            $params[] = $reg;
        }

        if ($from !== '') {
            $where .= "AND DATE(i.issued_at) >= %s ";
            $params[] = $from;
        }
        if ($to !== '') {
            $where .= "AND DATE(i.issued_at) <= %s ";
            $params[] = $to;
        }

        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where .= "AND (
                CAST(i.ticket_number AS CHAR) = %s
                OR CAST(i.id AS CHAR) = %s
                OR i.customer_name LIKE %s
                OR i.customer_nit LIKE %s
                OR i.mh_codigo_generacion LIKE %s
            ) ";
            $params[] = $q;
            $params[] = $q;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Dropdown registers
        $registers = $wpdb->get_results("SELECT id, register_name FROM {$tbl_registers} ORDER BY register_name ASC");

        // Count
        $sql_count  = "SELECT COUNT(*) FROM {$tbl_invoices} i {$where}";
        $total_rows = (int)$wpdb->get_var($wpdb->prepare($sql_count, ...$params));
        $total_pages = max(1, (int)ceil($total_rows / $per_page));

        // Rows
        $sql_rows = "
            SELECT
                i.id, i.store_id, i.register_id, i.document_type, i.ticket_number,
                i.customer_name, i.customer_nit,
                i.subtotal, i.tax_amount, i.total,
                i.payment_method, i.cash_paid, i.card_paid,
                i.status, i.mh_status, i.mh_attempts,
                i.mh_codigo_generacion,
                i.issued_at
            FROM {$tbl_invoices} i
            {$where}
            ORDER BY i.issued_at DESC
            LIMIT %d OFFSET %d
        ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql_rows, ...array_merge($params, [$per_page, $offset]))
        );

        // Base args for pagination (strings only)
        $base_args = [
            'page'        => 'jc-invoices',
            'doc_type'    => (string)$doc,
            'status'      => (string)$status,
            'mh_status'   => (string)$mh,
            'register_id' => (string)$reg,
            'date_from'   => (string)$from,
            'date_to'     => (string)$to,
            'q'           => (string)$q,
        ];

        $reset_url = admin_url('admin.php?page=jc-invoices');
        ?>
        <div class="wrap">
            <h1>Invoices</h1>

            <form method="get" style="margin:10px 0; display:flex; flex-wrap:wrap; gap:10px; align-items:end;">
                <input type="hidden" name="page" value="jc-invoices">

                <div>
                    <label><strong>Doc</strong></label><br>
                    <select name="doc_type">
                        <option value="">All</option>
                        <?php foreach ($allowed_doc as $t): ?>
                            <option value="<?= esc_attr($t) ?>" <?= selected($doc, $t, false) ?>><?= esc_html($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label><strong>Status</strong></label><br>
                    <select name="status">
                        <option value="">All</option>
                        <?php foreach ($allowed_status as $s): ?>
                            <option value="<?= esc_attr($s) ?>" <?= selected($status, $s, false) ?>><?= esc_html($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label><strong>MH</strong></label><br>
                    <select name="mh_status">
                        <option value="">All</option>
                        <?php foreach ($allowed_mh as $s): ?>
                            <option value="<?= esc_attr($s) ?>" <?= selected($mh, $s, false) ?>><?= esc_html($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label><strong>Register</strong></label><br>
                    <select name="register_id">
                        <option value="0">All</option>
                        <?php foreach ($registers as $r): ?>
                            <option value="<?= (int)$r->id ?>" <?= selected($reg, (int)$r->id, false) ?>><?= esc_html($r->register_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label><strong>From</strong></label><br>
                    <input type="date" name="date_from" value="<?= esc_attr($from) ?>">
                </div>

                <div>
                    <label><strong>To</strong></label><br>
                    <input type="date" name="date_to" value="<?= esc_attr($to) ?>">
                </div>

                <div style="min-width:240px;">
                    <label><strong>Search</strong></label><br>
                    <input type="text" name="q" value="<?= esc_attr($q) ?>" placeholder="Ticket, ID, NIT, Name, CodigoGen" style="width:240px;">
                </div>

                <div>
                    <button class="button button-primary">Filter</button>
                    <a class="button" href="<?= esc_url($reset_url) ?>">Reset</a>
                </div>
            </form>

            <p style="margin:10px 0;">
                <strong>Total:</strong> <?= (int)$total_rows ?> &nbsp; | &nbsp;
                <strong>Page:</strong> <?= (int)$page ?> / <?= (int)$total_pages ?>
            </p>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Issued</th>
                    <th>Doc</th>
                    <th>Register</th>
                    <th>Ticket</th>
                    <th>Total</th>
                    <th>Pay</th>
                    <th>Status</th>
                    <th>MH</th>
                    <th>Attempts</th>
                    <th>CodigoGeneracion</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11">No invoices found.</td></tr>
                <?php else: foreach ($rows as $row):
                    $receipt_url = admin_url('admin.php?page=jc-invoice-receipt&invoice_id='.(int)$row->id);
                    $cash_paid = (float)($row->cash_paid ?? 0);
                    $card_paid = (float)($row->card_paid ?? 0);
                ?>
                    <tr>
                        <td><a href="<?= esc_url($receipt_url) ?>"><strong>#<?= (int)$row->id ?></strong></a></td>
                        <td><?= esc_html((string)$row->issued_at) ?></td>
                        <td><?= esc_html((string)$row->document_type) ?></td>
                        <td><?= (int)$row->register_id ?></td>
                        <td><a href="<?= esc_url($receipt_url) ?>"><?= (int)$row->ticket_number ?></a></td>
                        <td><?= esc_html(number_format((float)$row->total, 2)) ?></td>
                        <td>
                            <?= esc_html((string)($row->payment_method ?? '')) ?>
                            <br><small>
                                C: <?= esc_html(number_format($cash_paid, 2)) ?> |
                                K: <?= esc_html(number_format($card_paid, 2)) ?>
                            </small>
                        </td>
                        <td><?= esc_html((string)$row->status) ?></td>
                        <td><?= esc_html((string)$row->mh_status) ?></td>
                        <td><?= (int)$row->mh_attempts ?></td>
                        <td style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?= esc_html((string)($row->mh_codigo_generacion ?: '-')) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1):
                $prev = max(1, $page - 1);
                $next = min($total_pages, $page + 1);

                $base_url = admin_url('admin.php');
                $url_prev = add_query_arg(array_merge($base_args, ['paged' => (string)$prev]), $base_url);
                $url_next = add_query_arg(array_merge($base_args, ['paged' => (string)$next]), $base_url);
            ?>
                <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
                    <a class="button <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= esc_url($url_prev) ?>">« Prev</a>
                    <span>Page <?= (int)$page ?> of <?= (int)$total_pages ?></span>
                    <a class="button <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= esc_url($url_next) ?>">Next »</a>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    public static function page_receipt() {
        if (!current_user_can('manage_options')) wp_die('No permission.');
        global $wpdb;

        $tbl_invoices = self::t('jc_invoices');
        $tbl_items    = self::t('jc_invoice_items');

        $invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
        if ($invoice_id <= 0) wp_die('Missing invoice_id.');

        $inv = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tbl_invoices} WHERE id=%d LIMIT 1", $invoice_id),
            ARRAY_A
        );
        if (!$inv) wp_die('Invoice not found.');

        $items = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tbl_items} WHERE invoice_id=%d ORDER BY id ASC", $invoice_id),
            ARRAY_A
        );

        // Back URL (never null)
        $ref = wp_get_referer();
        $back_url = (is_string($ref) && $ref !== '') ? $ref : admin_url('admin.php?page=jc-invoices');

        // Re-send MH link (uses your existing handler in JC_MH_Queue_Admin)
        $resend_url = wp_nonce_url(
            admin_url('admin-post.php?action=jc_mh_retry_invoice&invoice_id='.(int)$invoice_id),
            'jc_mh_retry_invoice_' . (int)$invoice_id
        );

        $cash_paid = (float)($inv['cash_paid'] ?? 0);
        $card_paid = (float)($inv['card_paid'] ?? 0);

        ?>
        <div class="wrap">
            <p>
                <a class="button" href="<?= esc_url((string)$back_url) ?>">← Back</a>

                <?php if (($inv['mh_status'] ?? '') !== 'SENT'): ?>
                    <a class="button button-secondary" href="<?= esc_url($resend_url) ?>"
                       onclick="return confirm('Re-send this invoice to MH now?')">
                        Re-send to MH
                    </a>
                <?php endif; ?>

                <button class="button button-primary" onclick="window.print()">Print</button>
            </p>

            <div id="jc-receipt" style="background:#fff; padding:16px; max-width:720px;">
                <h2 style="margin-top:0;">Receipt</h2>

                <table class="widefat" style="margin-bottom:12px;">
                    <tbody>
                    <tr><th style="width:200px;">Invoice ID</th><td>#<?= (int)$inv['id'] ?></td></tr>
                    <tr><th>Ticket</th><td><?= (int)($inv['ticket_number'] ?? 0) ?></td></tr>
                    <tr><th>Document Type</th><td><?= esc_html((string)($inv['document_type'] ?? '')) ?></td></tr>
                    <tr><th>Store / Register</th><td><?= (int)($inv['store_id'] ?? 0) ?> / <?= (int)($inv['register_id'] ?? 0) ?></td></tr>
                    <tr><th>Issued</th><td><?= esc_html((string)($inv['issued_at'] ?? '')) ?></td></tr>
                    <tr><th>Status</th><td><?= esc_html((string)($inv['status'] ?? '')) ?></td></tr>
                    <tr><th>Payment</th>
                        <td>
                            <?= esc_html((string)($inv['payment_method'] ?? '')) ?>
                            (Cash <?= esc_html(number_format($cash_paid, 2)) ?> /
                             Card <?= esc_html(number_format($card_paid, 2)) ?>)
                        </td>
                    </tr>
                    </tbody>
                </table>

                <h3>Customer</h3>
                <table class="widefat" style="margin-bottom:12px;">
                    <tbody>
                    <tr><th style="width:200px;">Name</th><td><?= esc_html((string)($inv['customer_name'] ?: 'CONSUMIDOR FINAL')) ?></td></tr>
                    <tr><th>NIT</th><td><?= esc_html((string)($inv['customer_nit'] ?: '-')) ?></td></tr>
                    <tr><th>Address</th><td><?= esc_html((string)($inv['customer_address'] ?: '-')) ?></td></tr>
                    </tbody>
                </table>

                <h3>Items</h3>
                <table class="widefat striped" style="margin-bottom:12px;">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Unit</th>
                        <th style="text-align:right;">Tax</th>
                        <th style="text-align:right;">Line Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="6">No items found.</td></tr>
                    <?php else: foreach ($items as $idx => $it): ?>
                        <tr>
                            <td><?= (int)($idx + 1) ?></td>
                            <td><?= esc_html((string)($it['product_name'] ?? '')) ?></td>
                            <td style="text-align:right;"><?= esc_html((string)($it['quantity'] ?? '')) ?></td>
                            <td style="text-align:right;"><?= esc_html(number_format((float)($it['unit_price'] ?? 0), 2)) ?></td>
                            <td style="text-align:right;"><?= esc_html(number_format((float)($it['tax_amount'] ?? 0), 2)) ?></td>
                            <td style="text-align:right;"><?= esc_html(number_format((float)($it['line_total'] ?? 0), 2)) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <h3>Totals</h3>
                <table class="widefat" style="margin-bottom:12px;">
                    <tbody>
                    <tr><th style="width:200px;">Subtotal</th><td><?= esc_html(number_format((float)($inv['subtotal'] ?? 0), 2)) ?></td></tr>
                    <tr><th>Tax</th><td><?= esc_html(number_format((float)($inv['tax_amount'] ?? 0), 2)) ?></td></tr>
                    <tr><th>Total</th><td><strong><?= esc_html(number_format((float)($inv['total'] ?? 0), 2)) ?></strong></td></tr>
                    </tbody>
                </table>

                <h3>MH</h3>
                <table class="widefat">
                    <tbody>
                    <tr><th style="width:200px;">MH Status</th><td><?= esc_html((string)($inv['mh_status'] ?? '-')) ?></td></tr>
                    <tr><th>Estado</th><td><?= esc_html((string)($inv['mh_estado'] ?? '-')) ?></td></tr>
                    <tr><th>Código Generación</th><td><?= esc_html((string)($inv['mh_codigo_generacion'] ?? '-')) ?></td></tr>
                    <tr><th>Sello Recibido</th><td><?= esc_html((string)($inv['mh_sello_recibido'] ?? '-')) ?></td></tr>
                    <tr><th>Código Msg</th><td><?= esc_html((string)($inv['mh_codigo_msg'] ?? '-')) ?></td></tr>
                    <tr><th>Descripción</th><td><?= esc_html((string)(($inv['mh_descripcion_msg'] ?? '') ?: ($inv['mh_last_error'] ?? '-') )) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
        @media print {
            body * { visibility: hidden; }
            #jc-receipt, #jc-receipt * { visibility: visible; }
            #jc-receipt { position: absolute; left: 0; top: 0; width: 100%; }
            .wrap > p { display:none; }
        }
        </style>
        <?php
    }
}