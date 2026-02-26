<?php

class JC_MH_Sender_Service {

    public static function send_one(int $invoice_id): array {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            $inv = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM wp_jc_invoices WHERE id=%d FOR UPDATE", $invoice_id)
            );

            if (!$inv) {
                throw new Exception("Invoice not found");
            }

            if ($inv->mh_status === 'SENT') {
                $wpdb->query('COMMIT');
                return ['success' => true, 'mh_status' => 'SENT', 'already_sent' => true];
            }

            // increment attempts
            $attempts = (int)$inv->mh_attempts + 1;

            // TODO: Build JSON based on your schema + items
            // You already have this logic. Plug it here.
            // Example:
            // $payload = JC_MH_Json_Builder::build_from_invoice($invoice_id);
            // $resp = your_existing_send_function($payload);

            $resp = self::send_to_mh_using_existing_stack($invoice_id); // implement below
            $estado        = $resp['estado'] ?? null;           // PROCESADO / RECHAZADO
            $codigoGen     = $resp['codigoGeneracion'] ?? null;
            $selloRecibido = $resp['selloRecibido'] ?? null;

            $ok = ($estado === 'PROCESADO' && !empty($selloRecibido));
            $mh_status = $ok ? 'SENT' : (($estado === 'RECHAZADO') ? 'REJECTED' : 'FAILED');


              $wpdb->update('wp_jc_invoices', [
                'mh_status' => $mh_status,
                'mh_attempts' => $attempts,
                'mh_last_attempt_at' => current_time('mysql'),
                'mh_last_error' => $resp['ok'] ? null : ($resp['error'] ?? 'Unknown error'),
                'mh_response' => wp_json_encode($resp, JSON_UNESCAPED_UNICODE),
                'mh_uuid' => $resp['ok'] ? ($resp['uuid'] ?? null) : null,
            ], ['id' => $invoice_id]);

            $wpdb->query('COMMIT');

            if (class_exists('JC_Audit_Service')) {
                JC_Audit_Service::log([
                    'action' => $resp['ok'] ? 'mh_send_success' : 'mh_send_failed',
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice_id,
                    'register_id' => (int)$inv->register_id,
                    'document_type' => $inv->document_type,
                    'message' => $resp['ok'] ? 'Sent to MH' : 'Send failed',
                    'meta' => [
                        'mh_status' => $mh_status,
                        'attempts' => $attempts,
                        'error' => $resp['ok'] ? null : ($resp['error'] ?? null),
                    ],
                ]);
            }

            return ['success' => (bool)$resp['ok'], 'mh_status' => $mh_status, 'error' => ($resp['error'] ?? null)];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'mh_status' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    public static function batch_send(int $limit = 50): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM wp_jc_invoices
                 WHERE mh_status IN ('PENDING','FAILED')
                 ORDER BY COALESCE(mh_last_attempt_at, issued_at) ASC
                 LIMIT %d",
                $limit
            )
        );

        $sent = 0; $failed = 0;

        foreach ($ids as $id) {
            $r = self::send_one((int)$id);
            if (!empty($r['success'])) $sent++;
            else $failed++;
        }

        return ['processed' => count($ids), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * ADAPTER: Replace this body with your existing implementation.
     * Return array like:
     * ['ok'=>true, 'uuid'=>'...', 'raw'=>...]
     * or
     * ['ok'=>false, 'error'=>'...', 'rejected'=>true/false, 'raw'=>...]
     */
    private static function send_to_mh_using_existing_stack(int $invoice_id): array {

        if (!class_exists('WC_DTE_SV')) {
            return ['estado' => 'FAILED', 'descripcionMsg' => 'WC_DTE_SV plugin not active'];
        }
        
        $dte = new WC_DTE_SV();
        $resp = $dte->send_dte_for_jc_invoice($invoice_id);
        
        return is_array($resp)
            ? $resp
            : ['estado' => 'FAILED', 'descripcionMsg' => 'Invalid MH response'];
    }
}