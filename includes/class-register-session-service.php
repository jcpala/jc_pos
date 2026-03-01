<?php
if (!defined('ABSPATH')) exit;

class JC_Register_Session_Service {

  private static function tbl_sessions($wpdb) { return $wpdb->prefix . 'jc_register_sessions'; }
  private static function tbl_moves($wpdb)    { return $wpdb->prefix . 'jc_register_cash_movements'; }
  private static function tbl_invoices($wpdb) { return $wpdb->prefix . 'jc_invoices'; }

  public static function get_open_session(int $register_id): ?array {
    global $wpdb;
    $t = self::tbl_sessions($wpdb);

    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$t} WHERE register_id=%d AND is_open=1 LIMIT 1", $register_id),
      ARRAY_A
    );
    return $row ?: null;
  }

  public static function open_session(int $store_id, int $register_id, int $user_id, float $opening_cash, string $notes=''): array {
    global $wpdb;
    $t = self::tbl_sessions($wpdb);

    $opening_cash = round(max(0, $opening_cash), 2);

    $wpdb->query('START TRANSACTION');
    try {
      $existing = $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$t} WHERE register_id=%d AND is_open=1 LIMIT 1 FOR UPDATE", $register_id)
      );
      if ($existing) {
        throw new Exception("There is already an open session for this register.");
      }

      $wpdb->insert($t, [
        'store_id'      => $store_id,
        'register_id'   => $register_id,
        'user_id'       => $user_id,
        'opened_at'     => current_time('mysql'),
        'opening_cash'  => $opening_cash,
        'notes'         => $notes,
        'is_open'       => 1,
      ], ['%d','%d','%d','%s','%f','%s','%d']);

      $id = (int)$wpdb->insert_id;
      if (!$id) throw new Exception("Failed to open session.");

      $wpdb->query('COMMIT');
      return ['success' => true, 'session_id' => $id];
    } catch (Throwable $e) {
      $wpdb->query('ROLLBACK');
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  public static function add_movement(int $session_id, int $store_id, int $register_id, int $user_id, string $type, float $amount, string $reason=''): array {
    global $wpdb;
    $ts = self::tbl_sessions($wpdb);
    $tm = self::tbl_moves($wpdb);

    $allowed = ['cash_in','cash_out','drop','change_added'];
    if (!in_array($type, $allowed, true)) {
      return ['success' => false, 'error' => 'Invalid movement type.'];
    }
    $amount = round(max(0, $amount), 2);
    if ($amount <= 0) {
      return ['success' => false, 'error' => 'Amount must be > 0.'];
    }

    $wpdb->query('START TRANSACTION');
    try {
      $open = $wpdb->get_row(
        $wpdb->prepare("SELECT id FROM {$ts} WHERE id=%d AND is_open=1 FOR UPDATE", $session_id),
        ARRAY_A
      );
      if (!$open) throw new Exception("Session not open.");

      $wpdb->insert($tm, [
        'session_id'  => $session_id,
        'store_id'    => $store_id,
        'register_id' => $register_id,
        'user_id'     => $user_id,
        'type'        => $type,
        'amount'      => $amount,
        'reason'      => $reason,
        'created_at'  => current_time('mysql'),
      ], ['%d','%d','%d','%d','%s','%f','%s','%s']);

      if (!$wpdb->insert_id) throw new Exception("Failed to add movement.");

      $wpdb->query('COMMIT');
      return ['success' => true];
    } catch (Throwable $e) {
      $wpdb->query('ROLLBACK');
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  public static function compute_summary(int $session_id): array {
    global $wpdb;

    $ts = self::tbl_sessions($wpdb);
    $tm = self::tbl_moves($wpdb);
    $ti = self::tbl_invoices($wpdb);

    $s = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$ts} WHERE id=%d LIMIT 1", $session_id),
        ARRAY_A
    );
    if (!$s) {
        return ['success' => false, 'error' => 'Session not found'];
    }

    // 1) Sales totals for THIS session (authoritative from invoice table)
    // - total_sales uses invoice.total (includes IVA for Consumidor Final)
    // - cash_sales/card_sales use cash_paid/card_paid (works for MIXED too)
    $sales = $wpdb->get_row(
        $wpdb->prepare("
            SELECT
                COALESCE(SUM(total), 0)     AS total_sales,
                COALESCE(SUM(cash_paid), 0) AS cash_sales,
                COALESCE(SUM(card_paid), 0) AS card_sales
            FROM {$ti}
            WHERE session_id = %d
              AND status = 'ISSUED'
        ", $session_id),
        ARRAY_A
    );

    if (!$sales) {
        $sales = ['total_sales' => 0, 'cash_sales' => 0, 'card_sales' => 0];
    }

    // 2) Cash movements grouped by type
    $moves = $wpdb->get_results(
        $wpdb->prepare("
            SELECT type, COALESCE(SUM(amount),0) AS amt
            FROM {$tm}
            WHERE session_id=%d
            GROUP BY type
        ", $session_id),
        ARRAY_A
    );

    $byType = ['cash_in'=>0.0,'cash_out'=>0.0,'drop'=>0.0,'change_added'=>0.0];
    foreach ($moves as $m) {
        $t = (string)($m['type'] ?? '');
        if (isset($byType[$t])) {
            $byType[$t] = (float)$m['amt'];
        }
    }

    // 3) Expected cash formula
    $opening_cash = (float)($s['opening_cash'] ?? 0);
    $cash_sales   = (float)($sales['cash_sales'] ?? 0);

    $expected = $opening_cash
        + $cash_sales
        + $byType['cash_in']
        + $byType['change_added']
        - $byType['cash_out']
        - $byType['drop'];

    return [
        'success' => true,
        'session' => $s,
        'sales'   => [
            'total_sales' => (float)$sales['total_sales'],
            'cash_sales'  => (float)$sales['cash_sales'],
            'card_sales'  => (float)$sales['card_sales'],
        ],
        'moves'   => $byType,
        'expected_cash' => round((float)$expected, 2),
    ];
}

  public static function close_session(int $session_id, int $user_id, float $closing_cash_counted, string $notes=''): array {
    global $wpdb;
    $ts = self::tbl_sessions($wpdb);

    $closing_cash_counted = round(max(0, $closing_cash_counted), 2);

    $wpdb->query('START TRANSACTION');
    try {
      $s = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$ts} WHERE id=%d FOR UPDATE", $session_id),
        ARRAY_A
      );
      if (!$s) throw new Exception("Session not found.");
      if ((int)$s['is_open'] !== 1) throw new Exception("Session already closed.");

      $summary = self::compute_summary($session_id);
      if (empty($summary['success'])) throw new Exception($summary['error'] ?? 'Failed to compute summary.');

      $expected = (float)$summary['expected_cash'];
      $diff = round($closing_cash_counted - $expected, 2);

      $sales = $summary['sales'];

      $wpdb->update($ts, [
        'closed_at'            => current_time('mysql'),
        'is_open'              => 0,
        'closing_cash_counted' => $closing_cash_counted,
        'expected_cash'        => $expected,
        'cash_difference'      => $diff,
        'notes'                => $notes !== '' ? $notes : ($s['notes'] ?? ''),

        // cache
        'total_sales' => (float)$sales['total_sales'],
        'cash_sales'  => (float)$sales['cash_sales'],
        'card_sales'  => (float)$sales['card_sales'],
      ], ['id' => $session_id], ['%s','%d','%f','%f','%f','%s','%f','%f','%f'], ['%d']);

      $wpdb->query('COMMIT');

      return ['success' => true, 'expected_cash' => $expected, 'difference' => $diff];
    } catch (Throwable $e) {
      $wpdb->query('ROLLBACK');
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }
}