<?php
require_once plugin_dir_path(__FILE__) . 'class-audit-service.php';
require_once plugin_dir_path(__FILE__) . 'class-correlativo-service.php';

require_once plugin_dir_path(__FILE__) . 'class-correlativo-service.php';
class JC_Invoice_Service {

    public static function create_invoice($data) {
        global $wpdb;
    
        /*
        $data structure expected:
    
        [
            'store_id'      => int,
            'register_id'   => int,
            'document_type' => 'CONSUMIDOR_FINAL' | 'CREDITO_FISCAL' | 'NOTA_CREDITO',
            'customer_name' => string|null,
            'customer_nit'  => string|null,
            'customer_address' => string|null,
    
            // NEW (optional, defaults to CASH/full total if omitted):
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
    
            // 1️⃣ Lock correlativo (FOR UPDATE)
            $correlativo = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM wp_jc_correlativos
                     WHERE register_id = %d
                       AND document_type = %s
                       AND is_active = 1
                     LIMIT 1
                     FOR UPDATE",
                    (int)$data['register_id'],
                    (string)$data['document_type']
                )
            );
    
            if (!$correlativo) {
                throw new Exception("No active correlativo found.");
            }
    
            if ((int)$correlativo->current_number > (int)$correlativo->range_end) {
                throw new Exception("Correlativo exhausted.");
            }
    
            $ticket_number = (int)$correlativo->current_number;
    
            // 2️⃣ Calculate totals
            $subtotal = 0.0;
            $tax_total = 0.0;
            $items_calculated = [];
    
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception("Invoice must include items.");
            }
    
            foreach ($data['items'] as $item) {
                $qty = (float)$item['quantity'];
                $unit = (float)$item['unit_price'];
                $tax_rate = (float)$item['tax_rate'];
    
                if ($qty <= 0) {
                    throw new Exception("Invalid item quantity.");
                }
                if ($unit < 0) {
                    throw new Exception("Invalid item unit price.");
                }
                if ($tax_rate < 0) {
                    throw new Exception("Invalid item tax rate.");
                }
    
                $line_subtotal = $qty * $unit;
                $line_tax = ($line_subtotal * $tax_rate) / 100.0;
                $line_total = $line_subtotal + $line_tax;
    
                $subtotal += $line_subtotal;
                $tax_total += $line_tax;
    
                $items_calculated[] = [
                    'product_name' => (string)$item['product_name'],
                    'quantity'     => $qty,
                    'unit_price'   => $unit,
                    'tax_rate'     => $tax_rate,
                    'tax_amount'   => $line_tax,
                    'line_total'   => $line_total
                ];
            }
    
            $grand_total = $subtotal + $tax_total;
    
            // 2.5️⃣ Payment normalization (NEW)
            $payment_method = isset($data['payment_method'])
                ? strtoupper(trim((string)$data['payment_method']))
                : 'CASH';
    
            $allowed_methods = ['CASH','CARD','MIXED'];
            if (!in_array($payment_method, $allowed_methods, true)) {
                $payment_method = 'CASH';
            }
    
            $cash_paid = isset($data['cash_paid']) ? (float)$data['cash_paid'] : 0.0;
            $card_paid = isset($data['card_paid']) ? (float)$data['card_paid'] : 0.0;
    
            // If caller didn't send payment fields at all -> back-compat default (all cash)
            $caller_sent_payment =
                array_key_exists('payment_method', $data) ||
                array_key_exists('cash_paid', $data) ||
                array_key_exists('card_paid', $data);
    
            if (!$caller_sent_payment) {
                $payment_method = 'CASH';
                $cash_paid = (float)$grand_total;
                $card_paid = 0.0;
            } else {
                if ($payment_method === 'CASH') {
                    $cash_paid = (float)$grand_total;
                    $card_paid = 0.0;
                } elseif ($payment_method === 'CARD') {
                    $cash_paid = 0.0;
                    $card_paid = (float)$grand_total;
                } else { // MIXED
                    if ($cash_paid < 0 || $card_paid < 0) {
                        throw new Exception("Invalid payment amounts.");
                    }
                    $sum = $cash_paid + $card_paid;
                    if (abs($sum - (float)$grand_total) > 0.01) {
                        throw new Exception("cash_paid + card_paid must equal invoice total.");
                    }
                }
            }
    
            // 3️⃣ Insert invoice (NEW columns included)
            $wpdb->insert('wp_jc_invoices', [
                'store_id'         => (int)$data['store_id'],
                'register_id'      => (int)$data['register_id'],
                'correlativo_id'   => (int)$correlativo->id,
                'document_type'    => (string)$data['document_type'],
                'ticket_number'    => (int)$ticket_number,
                'customer_name'    => $data['customer_name'] ?? null,
                'customer_nit'     => $data['customer_nit'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'subtotal'         => $subtotal,
                'tax_amount'       => $tax_total,
                'total'            => $grand_total,
    
                // ✅ NEW
                'payment_method'   => $payment_method,
                'cash_paid'        => $cash_paid,
                'card_paid'        => $card_paid,
    
                'status'           => 'ISSUED',
                'issued_at'        => current_time('mysql')
            ]);
    
            $invoice_id = (int)$wpdb->insert_id;
            if (!$invoice_id) {
                throw new Exception("Failed to create invoice.");
            }
    
            // 4️⃣ Insert items
            foreach ($items_calculated as $item) {
                $wpdb->insert('wp_jc_invoice_items', [
                    'invoice_id'   => $invoice_id,
                    'product_name' => $item['product_name'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'tax_rate'     => $item['tax_rate'],
                    'tax_amount'   => $item['tax_amount'],
                    'line_total'   => $item['line_total']
                ]);
            }
    
            // 5️⃣ Increment correlativo
            $wpdb->update(
                'wp_jc_correlativos',
                ['current_number' => $ticket_number + 1],
                ['id' => (int)$correlativo->id]
            );
    
            $wpdb->query('COMMIT');
            if (class_exists('JC_Invoice_Queue_Service')) {
                JC_Invoice_Queue_Service::ensure_pending(
                    (int)$invoice_id,
                    (int)$data['store_id'],
                    (int)$data['register_id'],
                    (string)$data['document_type']
                );
            }
            return [
                'success' => true,
                'invoice_id' => $invoice_id,
                'ticket_number' => $ticket_number,
                'correlativo_number' => $correlativo->correlativo_number
            ];
    
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }


    public static function void_invoice(int $invoice_id, string $reason = ''): array {
        global $wpdb;
    
        try {
            $wpdb->query('START TRANSACTION');
    
            $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_jc_invoices WHERE id=%d FOR UPDATE", $invoice_id));
            if (!$inv) throw new Exception("Invoice not found.");
    
            if ($inv->status !== 'ISSUED') throw new Exception("Only ISSUED invoices can be voided.");
    
            $wpdb->update('wp_jc_invoices', [
                'status' => 'VOIDED'
            ], ['id' => $invoice_id], ['%s'], ['%d']);
    
            $wpdb->query('COMMIT');
           
    
            JC_Audit_Service::log([
                'action'       => 'invoice_voided',
                'entity_type'  => 'invoice',
                'entity_id'    => $invoice_id,
                'store_id'     => (int)$inv->store_id,
                'register_id'  => (int)$inv->register_id,
                'document_type'=> $inv->document_type,
                'message'      => 'Invoice voided',
                'meta' => [
                    'ticket_number' => (int)$inv->ticket_number,
                    'reason' => $reason,
                ],
            ]);
    
            return ['success' => true];
    
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
    
            JC_Audit_Service::log([
                'action' => 'invoice_void_failed',
                'entity_type' => 'invoice',
                'entity_id' => $invoice_id,
                'message' => 'Failed to void invoice',
                'meta' => ['error' => $e->getMessage(), 'reason' => $reason],
            ]);
    
            return ['success' => false, 'error' => $e->getMessage()];
        }
        JC_Audit_Service::log([
            'action' => 'credit_note_issued',
            'entity_type' => 'invoice',
            'entity_id' => $credit_note_invoice_id,
            'register_id' => $register_id,
            'document_type' => 'NOTA_CREDITO',
            'message' => 'Issued credit note',
            'meta' => [
              'original_invoice_id' => $original_invoice_id,
              'original_ticket_number' => $original_ticket_number,
            ],
          ]);
    }
}