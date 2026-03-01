<?php
if (!defined('ABSPATH')) exit;

function jc_pos_admin_sessions_page() {
  if (!current_user_can('manage_options')) {
    wp_die('No permission.');
  }

  if (!class_exists('JC_Register_Session_Service')) {
    echo '<div class="wrap"><h1>Register Sessions</h1><p><strong>Error:</strong> JC_Register_Session_Service not loaded.</p></div>';
    return;
  }

  global $wpdb;

  $tbl_registers = $wpdb->prefix . 'jc_registers';
  $tbl_sessions  = $wpdb->prefix . 'jc_register_sessions';
  $tbl_moves     = $wpdb->prefix . 'jc_register_cash_movements';

  // URL builder (avoid add_query_arg surprises)
  $base_admin = admin_url('admin.php');
  $build_url = function(array $args) use ($base_admin) {
    foreach ($args as $k => $v) $args[$k] = (string)$v;
    return $base_admin . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
  };

  $registers = $wpdb->get_results("SELECT id, register_name FROM {$tbl_registers} ORDER BY register_name ASC");

  // Selected register
  $register_id = isset($_GET['register_id']) ? (int)$_GET['register_id'] : 0;
  if ($register_id <= 0 && !empty($registers)) {
    $register_id = (int)$registers[0]->id;
  }

  $store_id = 1; // if you later add stores, change here
  $user_id  = get_current_user_id();

  // Handle actions
  $notice = '';
  $notice_type = 'success';

  // Print view
  $view = isset($_GET['view']) ? sanitize_text_field((string)$_GET['view']) : '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['jc_action']) ? sanitize_text_field((string)$_POST['jc_action']) : '';
    check_admin_referer('jc_sessions_action');

    if ($action === 'open') {
      $opening_cash = isset($_POST['opening_cash']) ? (float)$_POST['opening_cash'] : 0;
      $notes = isset($_POST['notes']) ? sanitize_textarea_field((string)$_POST['notes']) : '';

      $r = JC_Register_Session_Service::open_session($store_id, $register_id, $user_id, $opening_cash, $notes);
      if (!empty($r['success'])) {
        $notice = "Session opened (#{$r['session_id']}).";
      } else {
        $notice = $r['error'] ?? 'Failed to open session.';
        $notice_type = 'error';
      }

    } elseif ($action === 'move') {
      $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
      $type   = isset($_POST['type']) ? sanitize_text_field((string)$_POST['type']) : '';
      $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
      $reason = isset($_POST['reason']) ? sanitize_text_field((string)$_POST['reason']) : '';

      $r = JC_Register_Session_Service::add_movement($session_id, $store_id, $register_id, $user_id, $type, $amount, $reason);
      if (!empty($r['success'])) {
        $notice = "Movement added.";
      } else {
        $notice = $r['error'] ?? 'Failed to add movement.';
        $notice_type = 'error';
      }

    } elseif ($action === 'close') {
      $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
      $counted = isset($_POST['closing_cash_counted']) ? (float)$_POST['closing_cash_counted'] : 0;
      $notes   = isset($_POST['close_notes']) ? sanitize_textarea_field((string)$_POST['close_notes']) : '';

      $r = JC_Register_Session_Service::close_session($session_id, $user_id, $counted, $notes);
      if (!empty($r['success'])) {
        $notice = "Session closed. Expected: " . number_format((float)$r['expected_cash'], 2) .
                  " | Difference: " . number_format((float)$r['difference'], 2);
      } else {
        $notice = $r['error'] ?? 'Failed to close session.';
        $notice_type = 'error';
      }
    }
  }

  // Open session for register
  $open = JC_Register_Session_Service::get_open_session($register_id);

  // Print Z report (must happen after we find session)
  if ($view === 'print' && $open) {
    $session_id = (int)$open['id'];
    $sum = JC_Register_Session_Service::compute_summary($session_id);
    $moves = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$tbl_moves} WHERE session_id=%d ORDER BY created_at ASC", $session_id),
      ARRAY_A
    );

    $expected = (float)($sum['expected_cash'] ?? 0);
    $sales = $sum['sales'] ?? ['total_sales'=>0,'cash_sales'=>0,'card_sales'=>0];
    $byType = $sum['moves'] ?? ['cash_in'=>0,'cash_out'=>0,'drop'=>0,'change_added'=>0];

    ?>
    <div class="wrap">
      <h1 style="display:none;">Z Report</h1>

      <div id="jc-zreport" style="background:#fff; padding:16px; max-width:760px;">
        <h2 style="text-align:center; margin:0;">Z Report</h2>
        <p style="text-align:center; margin:4px 0 12px;">
          Register #<?php echo (int)$register_id; ?> — Session #<?php echo (int)$session_id; ?>
        </p>

        <table class="widefat" style="margin-bottom:12px;">
          <tbody>
            <tr><th style="width:220px;">Opened</th><td><?php echo esc_html((string)$open['opened_at']); ?></td></tr>
            <tr><th>Opening Cash</th><td><?php echo esc_html(number_format((float)$open['opening_cash'], 2)); ?></td></tr>
            <tr><th>Total Sales</th><td><?php echo esc_html(number_format((float)$sales['total_sales'], 2)); ?></td></tr>
            <tr><th>Cash Sales</th><td><?php echo esc_html(number_format((float)$sales['cash_sales'], 2)); ?></td></tr>
            <tr><th>Card Sales</th><td><?php echo esc_html(number_format((float)$sales['card_sales'], 2)); ?></td></tr>
            <tr><th>Movements</th>
              <td>
                Cash In: <?php echo esc_html(number_format((float)$byType['cash_in'], 2)); ?> |
                Change Added: <?php echo esc_html(number_format((float)$byType['change_added'], 2)); ?><br>
                Cash Out: <?php echo esc_html(number_format((float)$byType['cash_out'], 2)); ?> |
                Drops: <?php echo esc_html(number_format((float)$byType['drop'], 2)); ?>
              </td>
            </tr>
            <tr><th>Expected Cash</th><td><strong><?php echo esc_html(number_format($expected, 2)); ?></strong></td></tr>
          </tbody>
        </table>

        <h3>Movement Log</h3>
        <table class="widefat striped" style="margin-bottom:12px;">
          <thead>
            <tr><th>Time</th><th>Type</th><th style="text-align:right;">Amount</th><th>Reason</th></tr>
          </thead>
          <tbody>
          <?php if (empty($moves)): ?>
            <tr><td colspan="4">No movements.</td></tr>
          <?php else: foreach ($moves as $m): ?>
            <tr>
              <td><?php echo esc_html((string)$m['created_at']); ?></td>
              <td><?php echo esc_html((string)$m['type']); ?></td>
              <td style="text-align:right;"><?php echo esc_html(number_format((float)$m['amount'], 2)); ?></td>
              <td><?php echo esc_html((string)($m['reason'] ?? '')); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>

        <p style="text-align:center; margin-top:20px;">— End of Report —</p>
      </div>
    </div>

    <script>
      window.onload = function(){ window.print(); };
    </script>

    <style>
      @media print {
        body * { visibility: hidden; }
        #jc-zreport, #jc-zreport * { visibility: visible; }
        #jc-zreport { position: absolute; left: 0; top: 0; width: 100%; }
        .wrap > *:not(#jc-zreport) { display:none; }
      }
    </style>
    <?php
    return;
  }

  // Compute summary for display
  $summary = null;
  $moves_list = [];
  if ($open) {
    $summary = JC_Register_Session_Service::compute_summary((int)$open['id']);
    $moves_list = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$tbl_moves} WHERE session_id=%d ORDER BY created_at DESC LIMIT 50", (int)$open['id']),
      ARRAY_A
    );
  }

  $page_url = $build_url(['page' => 'jc-pos-sessions', 'register_id' => (int)$register_id]);
  $print_url = $build_url(['page' => 'jc-pos-sessions', 'register_id' => (int)$register_id, 'view' => 'print']);

  ?>
  <div class="wrap">
    <h1>Register Sessions</h1>

    <?php if ($notice !== ''): ?>
      <div class="notice notice-<?php echo esc_attr($notice_type); ?>"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <form method="get" style="margin:10px 0; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
      <input type="hidden" name="page" value="jc-pos-sessions">
      <div>
        <label><strong>Register</strong></label><br>
        <select name="register_id">
          <?php foreach ($registers as $r): ?>
            <option value="<?php echo (int)$r->id; ?>" <?php selected($register_id, (int)$r->id); ?>>
              <?php echo esc_html($r->register_name); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button class="button">Switch</button>
        <a class="button" href="<?php echo esc_url($page_url); ?>">Refresh</a>
      </div>
    </form>

    <?php if (!$open): ?>
      <div style="background:#fff; border:1px solid #ccd0d4; padding:16px; border-radius:8px; max-width:720px;">
        <h2 style="margin-top:0;">Open Register</h2>
        <form method="post">
          <?php wp_nonce_field('jc_sessions_action'); ?>
          <input type="hidden" name="jc_action" value="open">

          <table class="form-table">
            <tr>
              <th><label>Opening Cash</label></th>
              <td><input type="number" step="0.01" min="0" name="opening_cash" value="0.00"></td>
            </tr>
            <tr>
              <th><label>Notes</label></th>
              <td><textarea name="notes" rows="3" style="width:100%;"></textarea></td>
            </tr>
          </table>

          <p><button class="button button-primary">Open Session</button></p>
        </form>
      </div>

    <?php else:
      $sid = (int)$open['id'];
      $expected = 0.0;
      $sales = ['total_sales'=>0,'cash_sales'=>0,'card_sales'=>0];
      $byType = ['cash_in'=>0,'cash_out'=>0,'drop'=>0,'change_added'=>0];

      if ($summary && !empty($summary['success'])) {
        $expected = (float)$summary['expected_cash'];
        $sales = $summary['sales'];
        $byType = $summary['moves'];
      }
    ?>

      <div style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="background:#fff; border:1px solid #ccd0d4; padding:16px; border-radius:8px; flex:1; min-width:320px;">
          <h2 style="margin-top:0;">Open Session</h2>
          <p><strong>Session:</strong> #<?php echo (int)$sid; ?></p>
          <p><strong>Opened:</strong> <?php echo esc_html((string)$open['opened_at']); ?></p>
          <p><strong>Opening Cash:</strong> <?php echo esc_html(number_format((float)$open['opening_cash'], 2)); ?></p>

          <hr>

          <p><strong>Total Sales:</strong> <?php echo esc_html(number_format((float)$sales['total_sales'], 2)); ?></p>
          <p><strong>Cash Sales:</strong> <?php echo esc_html(number_format((float)$sales['cash_sales'], 2)); ?></p>
          <p><strong>Card Sales:</strong> <?php echo esc_html(number_format((float)$sales['card_sales'], 2)); ?></p>

          <hr>

          <p><strong>Cash In:</strong> <?php echo esc_html(number_format((float)$byType['cash_in'], 2)); ?></p>
          <p><strong>Change Added:</strong> <?php echo esc_html(number_format((float)$byType['change_added'], 2)); ?></p>
          <p><strong>Cash Out:</strong> <?php echo esc_html(number_format((float)$byType['cash_out'], 2)); ?></p>
          <p><strong>Drops:</strong> <?php echo esc_html(number_format((float)$byType['drop'], 2)); ?></p>

          <hr>

          <p><strong>Expected Cash:</strong> <span style="font-size:18px;"><?php echo esc_html(number_format($expected, 2)); ?></span></p>

          <p>
            <a class="button" href="<?php echo esc_url($print_url); ?>">Print Z Report</a>
          </p>
        </div>

        <div style="background:#fff; border:1px solid #ccd0d4; padding:16px; border-radius:8px; flex:1; min-width:320px;">
          <h2 style="margin-top:0;">Add Movement</h2>

          <form method="post">
            <?php wp_nonce_field('jc_sessions_action'); ?>
            <input type="hidden" name="jc_action" value="move">
            <input type="hidden" name="session_id" value="<?php echo (int)$sid; ?>">

            <table class="form-table">
              <tr>
                <th><label>Type</label></th>
                <td>
                  <select name="type">
                    <option value="cash_in">Cash In</option>
                    <option value="change_added">Change Added</option>
                    <option value="cash_out">Cash Out</option>
                    <option value="drop">Drop</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th><label>Amount</label></th>
                <td><input type="number" step="0.01" min="0" name="amount" required></td>
              </tr>
              <tr>
                <th><label>Reason</label></th>
                <td><input type="text" name="reason" style="width:100%;" placeholder="Optional"></td>
              </tr>
            </table>

            <p><button class="button button-primary">Add Movement</button></p>
          </form>

          <hr>

          <h2 style="margin-top:0;">Close Register</h2>
          <form method="post" onsubmit="return confirm('Close this session?');">
            <?php wp_nonce_field('jc_sessions_action'); ?>
            <input type="hidden" name="jc_action" value="close">
            <input type="hidden" name="session_id" value="<?php echo (int)$sid; ?>">

            <table class="form-table">
              <tr>
                <th><label>Counted Cash</label></th>
                <td><input type="number" step="0.01" min="0" name="closing_cash_counted" required></td>
              </tr>
              <tr>
                <th><label>Notes</label></th>
                <td><textarea name="close_notes" rows="3" style="width:100%;"></textarea></td>
              </tr>
            </table>

            <p><button class="button button-primary">Close Session</button></p>
          </form>
        </div>
      </div>

      <div style="margin-top:16px; background:#fff; border:1px solid #ccd0d4; padding:16px; border-radius:8px;">
        <h2 style="margin-top:0;">Recent Movements (last 50)</h2>
        <table class="widefat striped">
          <thead>
            <tr><th>Time</th><th>Type</th><th style="text-align:right;">Amount</th><th>Reason</th></tr>
          </thead>
          <tbody>
            <?php if (empty($moves_list)): ?>
              <tr><td colspan="4">No movements yet.</td></tr>
            <?php else: foreach ($moves_list as $m): ?>
              <tr>
                <td><?php echo esc_html((string)$m['created_at']); ?></td>
                <td><?php echo esc_html((string)$m['type']); ?></td>
                <td style="text-align:right;"><?php echo esc_html(number_format((float)$m['amount'], 2)); ?></td>
                <td><?php echo esc_html((string)($m['reason'] ?? '')); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

  </div>
  <?php
}