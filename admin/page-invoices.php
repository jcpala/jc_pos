<?php
if (!defined('ABSPATH')) exit;

function jc_pos_admin_invoices_page() {
  if (!current_user_can('manage_options')) {
    wp_die('No permission.');
  }

  global $wpdb;
  $tbl_invoices  = $wpdb->prefix . 'jc_invoices';
  $tbl_items     = $wpdb->prefix . 'jc_invoice_items';
  $tbl_registers = $wpdb->prefix . 'jc_registers';

  // If viewing receipt
  $view = isset($_GET['view']) ? sanitize_text_field((string)$_GET['view']) : '';
  $invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;

  // Base URL builder without add_query_arg
  $base_admin = admin_url('admin.php');
  $build_url = function(array $args) use ($base_admin) {
    foreach ($args as $k => $v) $args[$k] = (string)$v;
    return $base_admin . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
  };

  // Receipt page
  if ($view === 'receipt' && $invoice_id > 0) {

    $inv = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$tbl_invoices} WHERE id=%d LIMIT 1", $invoice_id),
      ARRAY_A
    );
    if (!$inv) wp_die('Invoice not found.');

    $items = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$tbl_items} WHERE invoice_id=%d ORDER BY id ASC", $invoice_id),
      ARRAY_A
    );

    $back_url = $build_url(['page' => 'jc-pos-invoices']);

    $cash_paid = (float)($inv['cash_paid'] ?? 0);
    $card_paid = (float)($inv['card_paid'] ?? 0);

    ?>
    <div class="wrap">
      <h1>Invoice Receipt</h1>

      
      <p>
        <a class="button" href="<?php echo esc_url($back_url); ?>">← Back</a>
        <button class="button button-primary" onclick="window.print()">Print</button>
      </p>

      <div id="jc-receipt" style="background:#fff; padding:16px; max-width:760px;">
      <div style="text-align:center; margin-bottom:12px;">
        <img src="<?= esc_url( get_option('jc_company_logo') ?: '' ) ?>" style="max-height:80px;">
        </div>
        <h2 style="text-align:center; margin-bottom:0;">
                <?= esc_html( get_option('jc_company_name', 'Teavo Bubble Milk Tea') ) ?>
            </h2>
        <p style="text-align:center; margin-top:0;">
        <?= esc_html( get_option('jc_company_address', '') ) ?>
        </p>
        <table class="widefat" style="margin-bottom:12px;">
          <tbody>
          <tr><th style="width:220px;">Invoice ID</th><td>#<?php echo (int)$inv['id']; ?></td></tr>
          <tr><th>Ticket</th><td><?php echo (int)($inv['ticket_number'] ?? 0); ?></td></tr>
          <tr><th>Document Type</th><td><?php echo esc_html((string)($inv['document_type'] ?? '')); ?></td></tr>
          <tr><th>Store / Register</th><td><?php echo (int)($inv['store_id'] ?? 0); ?> / <?php echo (int)($inv['register_id'] ?? 0); ?></td></tr>
          <tr><th>Issued</th><td><?php echo esc_html((string)($inv['issued_at'] ?? '')); ?></td></tr>
          <tr><th>Status</th><td><?php echo esc_html((string)($inv['status'] ?? '')); ?></td></tr>
          <tr><th>Payment</th>
            <td>
            <?php
$invoiceTotal   = (float)($inv['total'] ?? 0);
$amountReceived = round((float)($inv['cash_paid'] ?? 0) + (float)($inv['card_paid'] ?? 0), 2);
$changeDue      = round(max(0, $amountReceived - $invoiceTotal), 2);
$paymentMethod  = strtoupper((string)($inv['payment_method'] ?? 'CASH'));
?>
<div>
  <strong><?php echo esc_html($paymentMethod); ?></strong>
  <?php if (in_array($paymentMethod, ['CASH', 'MIXED'], true)) : ?>
    <br><small>Recibido: $<?php echo esc_html(number_format($amountReceived, 2)); ?></small>
    <br><small>Cambio: $<?php echo esc_html(number_format($changeDue, 2)); ?></small>
  <?php endif; ?>
</div>
            </td>
          </tr>
          </tbody>
        </table>

        <h3>Items</h3>
        <table class="widefat striped" style="margin-bottom:12px;">
          <thead>
            <tr>
              <th>#</th><th>Description</th>
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
              <td><?php echo (int)($idx + 1); ?></td>
              <td><?php echo esc_html((string)($it['product_name'] ?? '')); ?></td>
              <td style="text-align:right;"><?php echo esc_html((string)($it['quantity'] ?? '')); ?></td>
              <td style="text-align:right;"><?php echo esc_html(number_format((float)($it['unit_price'] ?? 0), 2)); ?></td>
              <td style="text-align:right;"><?php echo esc_html(number_format((float)($it['tax_amount'] ?? 0), 2)); ?></td>
              <td style="text-align:right;"><?php echo esc_html(number_format((float)($it['line_total'] ?? 0), 2)); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>

        <h3>Totals</h3>
        <table class="widefat" style="margin-bottom:12px;">
          <tbody>
          <tr><th style="width:220px;">Subtotal</th><td><?php echo esc_html(number_format((float)($inv['subtotal'] ?? 0), 2)); ?></td></tr>
          <tr><th>Tax</th><td><?php echo esc_html(number_format((float)($inv['tax_amount'] ?? 0), 2)); ?></td></tr>
          <tr><th>Total</th><td><strong><?php echo esc_html(number_format((float)($inv['total'] ?? 0), 2)); ?></strong></td></tr>
          </tbody>
        </table>

        <h3>Ministerio de Hacienda</h3>
        <table class="widefat">
  <tbody>
    <tr>
      <th style="width:220px;">MH Badge</th>
      <td>
        <span style="padding:4px 8px; border-radius:4px; background: <?php echo (($inv['mh_status'] ?? '') === 'SENT') ? '#d4edda' : '#f8d7da'; ?>;">
          <?php echo esc_html((string)($inv['mh_status'] ?? '-')); ?>
        </span>
      </td>
    </tr>
    <tr><th>Estado</th><td><?php echo esc_html((string)($inv['mh_estado'] ?? '-')); ?></td></tr>
    <tr><th>Código Generación</th><td><?php echo esc_html((string)($inv['mh_codigo_generacion'] ?? '-')); ?></td></tr>
    <tr><th>Sello Recibido</th><td><?php echo esc_html((string)($inv['mh_sello_recibido'] ?? '-')); ?></td></tr>
    <tr><th>Código Msg</th><td><?php echo esc_html((string)($inv['mh_codigo_msg'] ?? '-')); ?></td></tr>
    <tr><th>Descripción</th><td><?php echo esc_html((string)((($inv['mh_descripcion_msg'] ?? '') !== '') ? $inv['mh_descripcion_msg'] : ($inv['mh_last_error'] ?? '-'))); ?></td></tr>
  </tbody>
</table>
      </div>
    </div>

    <style>
    @media print {
      body * { visibility: hidden; }
      #jc-receipt, #jc-receipt * { visibility: visible; }
      #jc-receipt { position: absolute; left: 0; top: 0; width: 100%; }
      .wrap > p, .wrap > h1 { display:none; }
    }
    </style>
    <?php
    return;
  }

  // List page
  $doc    = isset($_GET['doc_type']) ? sanitize_text_field((string)$_GET['doc_type']) : '';
  $status = isset($_GET['status']) ? sanitize_text_field((string)$_GET['status']) : '';
  $mh     = isset($_GET['mh_status']) ? sanitize_text_field((string)$_GET['mh_status']) : '';
  $reg    = isset($_GET['register_id']) ? (int)$_GET['register_id'] : 0;
  $from   = isset($_GET['date_from']) ? sanitize_text_field((string)$_GET['date_from']) : '';
  $to     = isset($_GET['date_to']) ? sanitize_text_field((string)$_GET['date_to']) : '';
  $q      = isset($_GET['q']) ? sanitize_text_field((string)$_GET['q']) : '';

  $page     = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
  $per_page = 50;
  $offset   = ($page - 1) * $per_page;

  $allowed_doc    = ['CONSUMIDOR_FINAL','CREDITO_FISCAL','NOTA_CREDITO'];
  $allowed_status = ['ISSUED','VOIDED','REFUNDED'];
  $allowed_mh     = ['PENDING','SENT','FAILED','REJECTED'];

  if ($doc !== '' && !in_array($doc, $allowed_doc, true)) $doc = '';
  if ($status !== '' && !in_array($status, $allowed_status, true)) $status = '';
  if ($mh !== '' && !in_array($mh, $allowed_mh, true)) $mh = '';

  $where  = "WHERE 1=1 ";
  $params = [];

  if ($doc !== '') { $where .= "AND i.document_type=%s "; $params[] = $doc; }
  if ($status !== '') { $where .= "AND i.status=%s "; $params[] = $status; }
  if ($mh !== '') { $where .= "AND i.mh_status=%s "; $params[] = $mh; }
  if ($reg > 0) { $where .= "AND i.register_id=%d "; $params[] = $reg; }

  if ($from !== '') { $where .= "AND DATE(i.issued_at) >= %s "; $params[] = $from; }
  if ($to !== '') { $where .= "AND DATE(i.issued_at) <= %s "; $params[] = $to; }

  if ($q !== '') {
    $like = '%' . $wpdb->esc_like($q) . '%';
    $where .= "AND (
      CAST(i.ticket_number AS CHAR) = %s OR CAST(i.id AS CHAR) = %s
      OR i.customer_name LIKE %s OR i.customer_nit LIKE %s OR i.mh_codigo_generacion LIKE %s
    ) ";
    $params[] = $q; $params[] = $q;
    $params[] = $like; $params[] = $like; $params[] = $like;
  }

  $registers = $wpdb->get_results("SELECT id, register_name FROM {$tbl_registers} ORDER BY register_name ASC");

  $sql_count = "SELECT COUNT(*) FROM {$tbl_invoices} i {$where}";
  $total_rows = (int)$wpdb->get_var($wpdb->prepare($sql_count, ...$params));
  $total_pages = max(1, (int)ceil($total_rows / $per_page));

  $sql_rows = "
  SELECT i.id, i.register_id, i.document_type, i.ticket_number, i.total,
         i.payment_method, i.cash_paid, i.card_paid,
         i.status, i.mh_status, i.mh_attempts, i.mh_codigo_generacion, i.issued_at,
         i.customer_email, i.email_status, i.email_attempts, i.email_sent_at
  FROM {$tbl_invoices} i
  {$where}
  ORDER BY i.issued_at DESC
  LIMIT %d OFFSET %d
";
  $rows = $wpdb->get_results($wpdb->prepare($sql_rows, ...array_merge($params, [$per_page, $offset])));

  $reset_url = $build_url(['page' => 'jc-pos-invoices']);
  ?>
  <div class="wrap">
    <h1>Invoices</h1>
    <?php
$jc_email_notice = isset($_GET['jc_email_notice']) ? sanitize_text_field((string)$_GET['jc_email_notice']) : '';
$jc_email_msg    = isset($_GET['jc_email_msg']) ? sanitize_text_field((string)rawurldecode($_GET['jc_email_msg'])) : '';

if ($jc_email_notice !== '' && $jc_email_msg !== ''):
?>
  <div class="notice notice-<?php echo esc_attr($jc_email_notice === 'success' ? 'success' : 'error'); ?> is-dismissible">
    <p><?php echo esc_html($jc_email_msg); ?></p>
  </div>
<?php endif; ?>

    <form method="get" style="margin:10px 0; display:flex; flex-wrap:wrap; gap:10px; align-items:end;">
      <input type="hidden" name="page" value="jc-pos-invoices">

      <div>
        <label><strong>Doc</strong></label><br>
        <select name="doc_type">
          <option value="">All</option>
          <?php foreach ($allowed_doc as $t): ?>
            <option value="<?php echo esc_attr($t); ?>" <?php selected($doc, $t); ?>><?php echo esc_html($t); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label><strong>Status</strong></label><br>
        <select name="status">
          <option value="">All</option>
          <?php foreach ($allowed_status as $s): ?>
            <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html($s); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label><strong>MH</strong></label><br>
        <select name="mh_status">
          <option value="">All</option>
          <?php foreach ($allowed_mh as $s): ?>
            <option value="<?php echo esc_attr($s); ?>" <?php selected($mh, $s); ?>><?php echo esc_html($s); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label><strong>Register</strong></label><br>
        <select name="register_id">
          <option value="0">All</option>
          <?php foreach ($registers as $r): ?>
            <option value="<?php echo (int)$r->id; ?>" <?php selected($reg, (int)$r->id); ?>>
              <?php echo esc_html($r->register_name); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label><strong>From</strong></label><br>
        <input type="date" name="date_from" value="<?php echo esc_attr($from); ?>">
      </div>

      <div>
        <label><strong>To</strong></label><br>
        <input type="date" name="date_to" value="<?php echo esc_attr($to); ?>">
      </div>

      <div style="min-width:240px;">
        <label><strong>Search</strong></label><br>
        <input type="text" name="q" value="<?php echo esc_attr($q); ?>" style="width:240px;">
      </div>

      <div>
        <button class="button button-primary">Filter</button>
        <a class="button" href="<?php echo esc_url($reset_url); ?>">Reset</a>
      </div>
    </form>

    <p><strong>Total:</strong> <?php echo (int)$total_rows; ?> | <strong>Page:</strong> <?php echo (int)$page; ?> / <?php echo (int)$total_pages; ?></p>

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
  <th>Email</th>
  <th>Attempts</th>
  <th>CodigoGen</th>
  <th>Actions</th>
</tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="13">No invoices found.</td></tr>
      <?php else: foreach ($rows as $row):
      $resend_email_url = wp_nonce_url(
        admin_url('admin-post.php?action=jc_pos_resend_email&invoice_id=' . (int)$row->id),
        'jc_pos_resend_email_' . (int)$row->id
    );
    
    $email_status = strtoupper((string)($row->email_status ?? 'PENDING'));
    $customer_email = trim((string)($row->customer_email ?? ''));
    $email_attempts = (int)($row->email_attempts ?? 0);
    
    $email_bg = '#f6f7f7';
    $email_fg = '#1d2327';
    
    if ($email_status === 'SENT') {
        $email_bg = '#d1e7dd';
        $email_fg = '#0f5132';
    } elseif ($email_status === 'FAILED') {
        $email_bg = '#f8d7da';
        $email_fg = '#842029';
    } elseif ($email_status === 'SKIPPED') {
        $email_bg = '#e2e3e5';
        $email_fg = '#41464b';
    } elseif ($email_status === 'PENDING') {
        $email_bg = '#fff3cd';
        $email_fg = '#664d03';
    }
    
    $can_resend_email = ($customer_email !== '');
      $receipt_view_url = $build_url([
          'page' => 'jc-pos-invoices',
          'view' => 'receipt',
          'invoice_id' => (int)$row->id,
          
]);

$receipt_print_url = admin_url('admin-post.php?action=jc_dte_receipt&invoice_id=' . (int)$row->id);
$pdf_print_url     = admin_url('admin-post.php?action=jc_dte_pdf&invoice_id=' . (int)$row->id);

$cash_paid = (float)($row->cash_paid ?? 0);
$card_paid = (float)($row->card_paid ?? 0);
      ?>
        <tr>
        <td><a href="<?php echo esc_url($receipt_view_url); ?>"><strong>#<?php echo (int)$row->id; ?></strong></a></td>
          <td><?php echo esc_html((string)$row->issued_at); ?></td>
          <td><?php echo esc_html((string)$row->document_type); ?></td>
          <td><?php echo (int)$row->register_id; ?></td>
          <td><a href="<?php echo esc_url($receipt_view_url); ?>"><?php echo (int)$row->ticket_number; ?></a></td>
          <td><?php echo esc_html(number_format((float)$row->total, 2)); ?></td>
          <td>
            <?php echo esc_html((string)($row->payment_method ?? '')); ?><br>
            <small>C: <?php echo esc_html(number_format($cash_paid, 2)); ?> | K: <?php echo esc_html(number_format($card_paid, 2)); ?></small>
          </td>
          <td><?php echo esc_html((string)$row->status); ?></td>
          <td><?php echo esc_html((string)$row->mh_status); ?></td>
          <td>
  <span style="display:inline-block; padding:4px 8px; border-radius:4px; background:<?php echo esc_attr($email_bg); ?>; color:<?php echo esc_attr($email_fg); ?>;">
    <?php echo esc_html($email_status); ?>
  </span>
  <?php if ($customer_email !== ''): ?>
    <br><small><?php echo esc_html($customer_email); ?></small>
  <?php else: ?>
    <br><small>-</small>
  <?php endif; ?>
</td>
          <!-- <td><?php echo (int)$row->mh_attempts; ?></td> -->
          <td>
        <?php echo (int)$row->mh_attempts; ?>
            <br><small>E: <?php echo (int)$email_attempts; ?></small>
          </td>
          <td style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            <?php echo esc_html((string)($row->mh_codigo_generacion ?: '-')); ?>
          </td>
          <td>
          <td>
  <div style="display:flex; gap:6px; align-items:center;">
    <a class="button button-small jc-icon-btn"
       href="<?php echo esc_url($receipt_view_url); ?>"
       title="View receipt">
      <span class="dashicons dashicons-visibility" style="font-size:16px; width:16px; height:16px; line-height:1.2;"></span>
    </a>

    <a class="button button-small jc-icon-btn"
       href="<?php echo esc_url($receipt_print_url); ?>"
       title="Print receipt">
      <span class="dashicons dashicons-media-text" style="font-size:16px; width:16px; height:16px; line-height:1.2;"></span>
    </a>

    <a class="button button-small jc-icon-btn"
       href="<?php echo esc_url($pdf_print_url); ?>"
       title="Open PDF">
      <span class="dashicons dashicons-pdf" style="font-size:16px; width:16px; height:16px; line-height:1.2;"></span>
    </a>

    <?php if ($can_resend_email): ?>
      <a class="button button-small jc-icon-btn"
         href="<?php echo esc_url($resend_email_url); ?>"
         title="Resend email"
         onclick="return confirm('Resend this document by email?');">
        <span class="dashicons dashicons-email-alt" style="font-size:16px; width:16px; height:16px; line-height:1.2;"></span>
      </a>
    <?php else: ?>
      <span class="button button-small jc-icon-btn" title="No customer email" style="opacity:.45; cursor:not-allowed;">
        <span class="dashicons dashicons-email-alt" style="font-size:16px; width:16px; height:16px; line-height:1.2;"></span>
      </span>
    <?php endif; ?>
  </div>
</td>
</td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php if ($total_pages > 1):
      $prev_url = $build_url([
        'page' => 'jc-pos-invoices',
        'paged' => (string)max(1, $page - 1),
        'doc_type' => $doc, 'status' => $status, 'mh_status' => $mh, 'register_id' => (string)$reg,
        'date_from' => $from, 'date_to' => $to, 'q' => $q
      ]);
      $next_url = $build_url([
        'page' => 'jc-pos-invoices',
        'paged' => (string)min($total_pages, $page + 1),
        'doc_type' => $doc, 'status' => $status, 'mh_status' => $mh, 'register_id' => (string)$reg,
        'date_from' => $from, 'date_to' => $to, 'q' => $q
      ]);
    ?>
      <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
        <a class="button <?php echo ($page <= 1 ? 'disabled' : ''); ?>" href="<?php echo esc_url($prev_url); ?>">« Prev</a>
        <span>Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></span>
        <a class="button <?php echo ($page >= $total_pages ? 'disabled' : ''); ?>" href="<?php echo esc_url($next_url); ?>">Next »</a>
      </div>
    <?php endif; ?>

  </div>
  <?php
}