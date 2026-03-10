<?php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'class-audit-service.php';
require_once plugin_dir_path(__FILE__) . 'class-correlativo-service.php';

class JC_Invoice_Service {

    /**
     * Cache invoice table columns.
     */
    private static $invoice_columns_cache = null;

    /**
     * Build prefixed table name.
     */
    private static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    /**
     * Get available columns from invoices table.
     */
    private static function get_invoice_columns(): array {
        global $wpdb;

        if (is_array(self::$invoice_columns_cache)) {
            return self::$invoice_columns_cache;
        }

        $table = self::table('jc_invoices');
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);

        $cols = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!empty($row['Field'])) {
                    $cols[] = (string) $row['Field'];
                }
            }
        }

        self::$invoice_columns_cache = $cols;
        return $cols;
    }

    /**
     * Check whether a given invoices column exists.
     */
    private static function invoice_has_column(string $column): bool {
        return in_array($column, self::get_invoice_columns(), true);
    }

    /**
     * Build invoice insert data, writing optional customer columns only if present.
     */
    private static function build_invoice_insert_row(
        array $data,
        object $correlativo,
        int $ticket_number,
        int $session_id,
        float $subtotal,
        float $tax_total,
        float $grand_total,
        string $payment_method,
        float $cash_paid,
        float $card_paid
    ): array {
        $customer_name = isset($data['customer_name']) ? (string) $data['customer_name'] : '';
        $customer_company = isset($data['customer_company']) ? (string) $data['customer_company'] : '';

        if ($customer_name === '' && $customer_company !== '') {
            $customer_name = $customer_company;
        }

        $row = [
            'store_id'         => (int) $data['store_id'],
            'register_id'      => (int) $data['register_id'],
            'session_id'       => $session_id ?: null,
            'correlativo_id'   => (int) $correlativo->id,
            'document_type'    => (string) $data['document_type'],
            'ticket_number'    => $ticket_number,
            'customer_name'    => $customer_name !== '' ? $customer_name : null,
            'customer_nit'     => isset($data['customer_nit']) && $data['customer_nit'] !== '' ? (string) $data['customer_nit'] : null,
            'customer_address' => isset($data['customer_address']) && $data['customer_address'] !== '' ? (string) $data['customer_address'] : null,
            'subtotal'         => $subtotal,
            'tax_amount'       => $tax_total,
            'total'            => $grand_total,
            'payment_method'   => $payment_method,
            'cash_paid'        => $cash_paid,
            'card_paid'        => $card_paid,
            'status'           => 'ISSUED',
            'issued_at'        => current_time('mysql'),
        ];

        // Optional linkage + snapshot fields (only if columns exist).
        if (self::invoice_has_column('customer_id')) {
            $row['customer_id'] = !empty($data['customer_id']) ? (int) $data['customer_id'] : null;
        }

        if (self::invoice_has_column('customer_company')) {
            $row['customer_company'] = $customer_company !== '' ? $customer_company : null;
        }

        if (self::invoice_has_column('customer_nrc')) {
            $row['customer_nrc'] = isset($data['customer_nrc']) && $data['customer_nrc'] !== '' ? (string) $data['customer_nrc'] : null;
        }

        if (self::invoice_has_column('customer_city')) {
            $row['customer_city'] = isset($data['customer_city']) && $data['customer_city'] !== '' ? (string) $data['customer_city'] : null;
        }

        if (self::invoice_has_column('customer_phone')) {
            $row['customer_phone'] = isset($data['customer_phone']) && $data['customer_phone'] !== '' ? (string) $data['customer_phone'] : null;
        }

        if (self::invoice_has_column('customer_email')) {
            $row['customer_email'] = isset($data['customer_email']) && $data['customer_email'] !== '' ? (string) $data['customer_email'] : null;
        }

        return $row;
    }

    public static function create_invoice($data) {
        global $wpdb;

        $t_correlativos      = self::table('jc_correlativos');
        $t_invoices          = self::table('jc_invoices');
        $t_invoice_items     = self::table('jc_invoice_items');
        $t_register_sessions = self::table('jc_register_sessions');

        /*
        $data structure expected:

        [
            'store_id'      => int,
            'register_id'   => int,
            'document_type' => 'CONSUMIDOR_FINAL' | 'CREDITO_FISCAL' | 'NOTA_CREDITO',

            // customer linkage + snapshot (all optional except where validated before this point)
            'customer_id'      => int|null,
            'customer_name'    => string|null,
            'customer_company' => string|null,
            'customer_nrc'     => string|null,
            'customer_nit'     => string|null,
            'customer_address' => string|null,
            'customer_city'    => string|null,
            'customer_phone'   => string|null,
            'customer_email'   => string|null,

            // optional payment
            // 'payment_method' => 'CASH'|'CARD'|'MIXED',
            // 'cash_paid'      => 0.00,
            // 'card_paid'      => 0.00,

            'items' => [
                [
                    'product_name' => '',
                    'quantity'     => 1,
                    'unit_price'   => 10.00,
                    'tax_rate'     => 13.00
                ]
            ]
        ]
        */

        try {
            $wpdb->query('START TRANSACTION');

            // 1) Lock correlativo
            $correlativo = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT *
                     FROM {$t_correlativos}
                     WHERE register_id = %d
                       AND document_type = %s
                       AND is_active = 1
                     LIMIT 1
                     FOR UPDATE",
                    (int) $data['register_id'],
                    (string) $data['document_type']
                )
            );

            if (!$correlativo) {
                throw new Exception('No active correlativo found.');
            }

            if ((int) $correlativo->current_number > (int) $correlativo->range_end) {
                throw new Exception('Correlativo exhausted.');
            }

            $ticket_number = (int) $correlativo->current_number;

            // 2) Calculate totals
            $subtotal = 0.0;
            $tax_total = 0.0;
            $items_calculated = [];

            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception('Invoice must include items.');
            }
            foreach ($data['items'] as $item) {
                $qty      = (float) ($item['quantity'] ?? 0);
                $unit     = (float) ($item['unit_price'] ?? 0);
                $tax_rate = (float) ($item['tax_rate'] ?? 0);
            
                if ($qty <= 0) {
                    throw new Exception('Invalid item quantity.');
                }
            
                if ($unit < 0) {
                    throw new Exception('Invalid item unit price.');
                }
            
                if ($tax_rate < 0) {
                    throw new Exception('Invalid item tax rate.');
                }
            
                $docType = (string) $data['document_type'];
            
                /*
                 * In your POS, the entered unit_price is the FINAL customer price.
                 * So for BOTH CF and CCF, split gross price into base + IVA.
                 *
                 * Example:
                 *   final = 5.00
                 *   base  = 4.42
                 *   iva   = 0.58
                 *   total = 5.00
                 */
                if (in_array($docType, ['CONSUMIDOR_FINAL', 'CREDITO_FISCAL'], true)) {
                    $unit_base = ($tax_rate > 0)
                        ? ($unit / (1 + ($tax_rate / 100.0)))
                        : $unit;
            
                    $unit_tax = $unit - $unit_base;
            
                    $line_subtotal = round($qty * $unit_base, 2);
                    $line_tax      = round($qty * $unit_tax, 2);
                    $line_total    = round($qty * $unit, 2);
                } else {
                    // Keep old behavior for other document types unless needed later.
                    $line_subtotal = round($qty * $unit, 2);
                    $line_tax      = round(($line_subtotal * $tax_rate) / 100.0, 2);
                    $line_total    = round($line_subtotal + $line_tax, 2);
                }
            
                $subtotal  += $line_subtotal;
                $tax_total += $line_tax;
            
                $items_calculated[] = [
                    'product_name' => (string) ($item['product_name'] ?? 'Item'),
                    'quantity'     => $qty,
                    'unit_price'   => round($unit, 2),
                    'tax_rate'     => round($tax_rate, 2),
                    'tax_amount'   => $line_tax,
                    'line_total'   => $line_total,
                ];
            }
            
            $subtotal    = round($subtotal, 2);
            $tax_total   = round($tax_total, 2);
            $grand_total = round($subtotal + $tax_total, 2);


// 2.5) Payment normalization
$payment_method = isset($data['payment_method'])
    ? strtoupper(trim((string) $data['payment_method']))
    : 'CASH';

$allowed_methods = ['CASH', 'CARD', 'MIXED'];
if (!in_array($payment_method, $allowed_methods, true)) {
    $payment_method = 'CASH';
}

$has_cash_paid = isset($data['cash_paid']) && trim((string) $data['cash_paid']) !== '';
$has_card_paid = isset($data['card_paid']) && trim((string) $data['card_paid']) !== '';

$cash_paid = $has_cash_paid ? round((float) $data['cash_paid'], 2) : 0.0;
$card_paid = $has_card_paid ? round((float) $data['card_paid'], 2) : 0.0;

$caller_sent_amounts = $has_cash_paid || $has_card_paid;

if (!$caller_sent_amounts) {
    // No real tendered amounts came in, so default to exact payment.
    if ($payment_method === 'CARD') {
        $cash_paid = 0.0;
        $card_paid = round((float) $grand_total, 2);
    } else {
        // CASH and MIXED without amounts fall back to exact cash
        $payment_method = ($payment_method === 'MIXED') ? 'CASH' : $payment_method;
        $cash_paid = round((float) $grand_total, 2);
        $card_paid = 0.0;
    }
} else {
    if ($cash_paid < 0 || $card_paid < 0) {
        throw new Exception('Invalid payment amounts.');
    }

    if ($payment_method === 'CASH') {
        if ($cash_paid + 0.0001 < (float) $grand_total) {
            throw new Exception('Cash received cannot be less than invoice total.');
        }
        $card_paid = 0.0;

    } elseif ($payment_method === 'CARD') {
        $cash_paid = 0.0;

        if (abs($card_paid - (float) $grand_total) > 0.01) {
            throw new Exception('Card payment must equal invoice total.');
        }

    } else { // MIXED
        $sum_paid = round($cash_paid + $card_paid, 2);

        if ($sum_paid + 0.0001 < (float) $grand_total) {
            throw new Exception('Cash + card cannot be less than invoice total.');
        }
    }
}

$total_paid = round($cash_paid + $card_paid, 2);
$change_due = round(max(0, $total_paid - (float) $grand_total), 2);


            // 2.6) Find open register session
            $session_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$t_register_sessions}
                     WHERE register_id = %d
                       AND is_open = 1
                     ORDER BY opened_at DESC
                     LIMIT 1",
                    (int) $data['register_id']
                )
            );

            if (!$session_id) {
                throw new Exception('No open register session for this register. Please open the register first.');
            }

            // 3) Insert invoice
            $invoice_row = self::build_invoice_insert_row(
                $data,
                $correlativo,
                $ticket_number,
                $session_id,
                $subtotal,
                $tax_total,
                $grand_total,
                $payment_method,
                $cash_paid,
                $card_paid
            );

            $inserted = $wpdb->insert($t_invoices, $invoice_row);

            if ($inserted === false) {
                $db_error = (string) $wpdb->last_error;
                throw new Exception($db_error !== '' ? $db_error : 'Failed to create invoice.');
            }

            $invoice_id = (int) $wpdb->insert_id;
            if ($invoice_id <= 0) {
                throw new Exception('Failed to create invoice.');
            }

            // 4) Insert items
            foreach ($items_calculated as $item) {
                $item_inserted = $wpdb->insert($t_invoice_items, [
                    'invoice_id'   => $invoice_id,
                    'product_name' => $item['product_name'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'tax_rate'     => $item['tax_rate'],
                    'tax_amount'   => $item['tax_amount'],
                    'line_total'   => $item['line_total'],
                ]);

                if ($item_inserted === false) {
                    $db_error = (string) $wpdb->last_error;
                    throw new Exception($db_error !== '' ? $db_error : 'Failed to create invoice item.');
                }
            }

            // 5) Increment correlativo
            $updated = $wpdb->update(
                $t_correlativos,
                ['current_number' => $ticket_number + 1],
                ['id' => (int) $correlativo->id],
                ['%d'],
                ['%d']
            );

            if ($updated === false) {
                $db_error = (string) $wpdb->last_error;
                throw new Exception($db_error !== '' ? $db_error : 'Failed to update correlativo.');
            }

            $wpdb->query('COMMIT');

            if (class_exists('JC_Invoice_Queue_Service')) {
                JC_Invoice_Queue_Service::ensure_pending(
                    (int) $invoice_id,
                    (int) $data['store_id'],
                    (int) $data['register_id'],
                    (string) $data['document_type']
                );
            }

            return [
                'success'            => true,
                'invoice_id'         => $invoice_id,
                'ticket_number'      => $ticket_number,
                'correlativo_number' => $correlativo->correlativo_number,
                'grand_total'        => $grand_total,
                'total_paid'         => $total_paid,
                'change_due'         => $change_due,
            ];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    public static function void_invoice(int $invoice_id, string $reason = ''): array {
        global $wpdb;

        $t_invoices = self::table('jc_invoices');

        try {
            $wpdb->query('START TRANSACTION');

            $inv = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$t_invoices} WHERE id=%d FOR UPDATE",
                    $invoice_id
                )
            );

            if (!$inv) {
                throw new Exception('Invoice not found.');
            }

            if ($inv->status !== 'ISSUED') {
                throw new Exception('Only ISSUED invoices can be voided.');
            }

            $updated = $wpdb->update(
                $t_invoices,
                ['status' => 'VOIDED'],
                ['id' => $invoice_id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                $db_error = (string) $wpdb->last_error;
                throw new Exception($db_error !== '' ? $db_error : 'Failed to void invoice.');
            }

            $wpdb->query('COMMIT');

            JC_Audit_Service::log([
                'action'        => 'invoice_voided',
                'entity_type'   => 'invoice',
                'entity_id'     => $invoice_id,
                'store_id'      => (int) $inv->store_id,
                'register_id'   => (int) $inv->register_id,
                'document_type' => $inv->document_type,
                'message'       => 'Invoice voided',
                'meta'          => [
                    'ticket_number' => (int) $inv->ticket_number,
                    'reason'        => $reason,
                ],
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            JC_Audit_Service::log([
                'action'      => 'invoice_void_failed',
                'entity_type' => 'invoice',
                'entity_id'   => $invoice_id,
                'message'     => 'Failed to void invoice',
                'meta'        => [
                    'error'  => $e->getMessage(),
                    'reason' => $reason,
                ],
            ]);

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}