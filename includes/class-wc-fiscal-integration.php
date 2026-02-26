<?php

class JC_WC_Fiscal_Integration {

    public static function init() {
        // Fires when payment is completed for an order
        add_action('woocommerce_payment_complete', [self::class, 'on_payment_complete'], 20, 1);

        // Optional fallback for cash/manual methods that might not trigger payment_complete:
        add_action('woocommerce_order_status_processing', [self::class, 'maybe_issue_on_status'], 20, 1);
    }

    public static function on_payment_complete($order_id) {
        self::issue_fiscal_if_needed((int)$order_id);
    }

    public static function maybe_issue_on_status($order_id) {
        // Some payment methods move directly to processing; this is a safety net.
        self::issue_fiscal_if_needed((int)$order_id);
    }

    private static function issue_fiscal_if_needed(int $order_id): void {
        if ($order_id <= 0) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Prevent duplicates
        $existing_invoice_id = $order->get_meta('_jc_invoice_id', true);
        if ($existing_invoice_id) {
            return; // already issued
        }

        // Ensure register configured
        $register_id = (int) get_option('jc_current_register_id', 0);
        if ($register_id <= 0) {
            // Log failure (no register configured)
            if (class_exists('JC_Audit_Service')) {
                JC_Audit_Service::log([
                    'action' => 'invoice_issue_failed',
                    'entity_type' => 'order',
                    'entity_id' => $order_id,
                    'message' => 'No register configured (jc_current_register_id missing)',
                    'meta' => ['order_id' => $order_id],
                ]);
            }
            return;
        }

        // Derive store_id from register
        global $wpdb;
        $store_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT store_id FROM wp_jc_registers WHERE id=%d", $register_id)
        );

        // Document type from checkout
        $doc_type = $order->get_meta('_jc_document_type', true);
        if (!$doc_type) $doc_type = 'CONSUMIDOR_FINAL';

        // Build invoice items from WC order lines
        $items = [];
        foreach ($order->get_items() as $item) {
            $qty = (float) $item->get_quantity();

            // Woo stores totals excluding tax and tax separately per line
            $line_subtotal_ex_tax = (float) $item->get_total();     // ex tax
            $line_tax_total       = (float) $item->get_total_tax(); // tax
            $unit_price_ex_tax    = ($qty > 0) ? ($line_subtotal_ex_tax / $qty) : 0.0;

            // If you have a fixed VAT rate, you can store it.
            // Otherwise compute effective rate from line totals.
            $tax_rate = 0.0;
            if ($line_subtotal_ex_tax > 0) {
                $tax_rate = ($line_tax_total / $line_subtotal_ex_tax) * 100;
            }

            $items[] = [
                'product_name' => $item->get_name(),
                'quantity'     => $qty,
                'unit_price'   => $unit_price_ex_tax,
                'tax_rate'     => $tax_rate,
            ];
        }

        // Prepare fiscal data
        $payload = [
            'store_id'         => $store_id,
            'register_id'      => $register_id,
            'document_type'    => $doc_type,
            'customer_name'    => $order->get_meta('_jc_customer_name', true),
            'customer_nit'     => $order->get_meta('_jc_customer_nit', true),
            'customer_address' => $order->get_meta('_jc_customer_address', true),
            'items'            => $items,
        ];

        // Issue invoice atomically
        $result = JC_Invoice_Service::create_invoice($payload);

        if (!empty($result['success'])) {
            // Save reference to WC order meta
            $order->update_meta_data('_jc_invoice_id', (int)$result['invoice_id']);
            $order->update_meta_data('_jc_ticket_number', (int)$result['ticket_number']);
            $order->update_meta_data('_jc_correlativo_number', (string)$result['correlativo_number']);
            $order->save();
        } else {
            // Add an order note + log
            $err = $result['error'] ?? 'Unknown error';
            $order->add_order_note('Fiscal issuance failed: ' . $err);

            if (class_exists('JC_Audit_Service')) {
                JC_Audit_Service::log([
                    'action' => 'invoice_issue_failed',
                    'entity_type' => 'order',
                    'entity_id' => $order_id,
                    'register_id' => $register_id,
                    'document_type' => $doc_type,
                    'message' => 'Failed issuing fiscal invoice from WooCommerce order',
                    'meta' => ['error' => $err],
                ]);
            }
        }
        // Best-effort: send to MH immediately (does NOT block sale if MH is down)
            if (class_exists('JC_MH_Sender_Service')) {
            JC_MH_Sender_Service::send_one((int)$result['invoice_id']);
}
    }
}