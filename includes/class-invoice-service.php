<?php
require_once plugin_dir_path(__FILE__) . 'class-audit-service.php';
require_once plugin_dir_path(__FILE__) . 'class-correlativo-service.php';

require_once plugin_dir_path(__FILE__) . 'class-correlativo-service.php';
class JC_Invoice_Service {

    public static function create_invoice($data) {
        global $wpdb;
        
        
        
        // Lock correlativo (auto-switch if needed)
        $correlativo = JC_Correlativo_Service::get_active_correlativo_for_update(
        (int)$data['register_id'],
            $data['document_type']
        );

if (!$correlativo) {
    throw new Exception("No correlativo available.");
}

// Now safe to use
$ticket_number = (int)$correlativo->current_number;
        
        
        /*
        $data structure expected:

        [
            'store_id'      => int,
            'register_id'   => int,
            'document_type' => 'CONSUMIDOR_FINAL' | 'CREDITO_FISCAL' | 'NOTA_CREDITO',
            'customer_name' => string|null,
            'customer_nit'  => string|null,
            'customer_address' => string|null,
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

            // 1️⃣ Lock correlativo
            $correlativo = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM wp_jc_correlativos
                     WHERE register_id = %d
                     AND document_type = %s
                     AND is_active = 1
                     LIMIT 1
                     FOR UPDATE",
                    $data['register_id'],
                    $data['document_type']
                )
            );

            if (!$correlativo) {
                throw new Exception("No active correlativo found.");
            }

            if ($correlativo->current_number > $correlativo->range_end) {
                throw new Exception("Correlativo exhausted.");
            }

            $ticket_number = $correlativo->current_number;

            // 2️⃣ Calculate totals
            $subtotal = 0;
            $tax_total = 0;

            foreach ($data['items'] as $item) {

                $line_subtotal = $item['quantity'] * $item['unit_price'];
                $line_tax = ($line_subtotal * $item['tax_rate']) / 100;
                $line_total = $line_subtotal + $line_tax;

                $subtotal += $line_subtotal;
                $tax_total += $line_tax;

                $items_calculated[] = [
                    'product_name' => $item['product_name'],
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $item['unit_price'],
                    'tax_rate'     => $item['tax_rate'],
                    'tax_amount'   => $line_tax,
                    'line_total'   => $line_total
                ];
            }

            $grand_total = $subtotal + $tax_total;

            // 3️⃣ Insert invoice
            $wpdb->insert('wp_jc_invoices', [
                'store_id'      => $data['store_id'],
                'register_id'   => $data['register_id'],
                'correlativo_id'=> $correlativo->id,
                'document_type' => $data['document_type'],
                'ticket_number' => $ticket_number,
                'customer_name' => $data['customer_name'],
                'customer_nit'  => $data['customer_nit'],
                'customer_address' => $data['customer_address'],
                'subtotal'      => $subtotal,
                'tax_amount'    => $tax_total,
                'total'         => $grand_total,
                'status'        => 'ISSUED',
                'issued_at'     => current_time('mysql')
            ]);

            $invoice_id = $wpdb->insert_id;

            if (!$invoice_id) {
                throw new Exception("Failed to create invoice.");
            }

            // 4️⃣ Insert items
            foreach ($items_calculated as $item) {

                $wpdb->insert('wp_jc_invoice_items', [
                    'invoice_id'  => $invoice_id,
                    'product_name'=> $item['product_name'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'tax_rate'    => $item['tax_rate'],
                    'tax_amount'  => $item['tax_amount'],
                    'line_total'  => $item['line_total']
                ]);
            }

            // 5️⃣ Increment correlativo
            $wpdb->update(
                'wp_jc_correlativos',
                ['current_number' => $ticket_number + 1],
                ['id' => $correlativo->id]
            );

            $wpdb->query('COMMIT');

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