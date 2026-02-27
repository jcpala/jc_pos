<?php

class JC_MH_Sender_Service {

    public static function send_one(int $invoice_id): array {
        global $wpdb;
    
        wc_get_logger()->info("JC_MH_Sender_Service::send_one invoice={$invoice_id}", ['source' => 'jc-pos']);
    
        $wpdb->query('START TRANSACTION');
    
        try {
            $inv = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM wp_jc_invoices WHERE id=%d FOR UPDATE", $invoice_id)
            );
    
            if (!$inv) {
                throw new Exception("Invoice not found");
            }
    
            // If already sent, do nothing
            if (($inv->mh_status ?? '') === 'SENT') {
                $wpdb->query('COMMIT');
                return [
                    'mh_status' => 'SENT',
                    'already_sent' => true,
                    'estado' => $inv->mh_estado ?? null,
                    'selloRecibido' => $inv->mh_sello_recibido ?? null,
                    'codigoGeneracion' => $inv->mh_codigo_generacion ?? null,
                ];
            }
    
            $attempts = ((int)($inv->mh_attempts ?? 0)) + 1;
    
            // Call your working DTE stack (returns MH response array)
            $resp = self::send_to_mh_using_existing_stack($invoice_id);
    
            // Normalize MH fields
            $estado        = $resp['estado'] ?? null;                // PROCESADO / RECHAZADO
            $codigoGen     = $resp['codigoGeneracion'] ?? null;
            $selloRecibido = $resp['selloRecibido'] ?? null;
            $codigoMsg     = $resp['codigoMsg'] ?? null;
            $descMsg       = $resp['descripcionMsg'] ?? null;
    
            $ok = ($estado === 'PROCESADO' && !empty($selloRecibido));
    
            $mh_status = $ok
                ? 'SENT'
                : (($estado === 'RECHAZADO') ? 'REJECTED' : 'FAILED');
    
            // Store in DB (never depend on old $resp['ok'])
            $wpdb->update(
                'wp_jc_invoices',
                [
                    'mh_status'            => $mh_status,
                    'mh_attempts'          => $attempts,
                    'mh_last_attempt_at'   => current_time('mysql'),
                    'mh_last_error'        => $ok ? null : ($descMsg ?: 'MH send failed'),
                    'mh_response'          => wp_json_encode($resp, JSON_UNESCAPED_UNICODE),
    
                    // these columns you added:
                    'mh_codigo_generacion' => $codigoGen,
                    'mh_sello_recibido'    => $selloRecibido,
                    'mh_estado'            => $estado,
                    'mh_codigo_msg'        => $codigoMsg,
                    'mh_descripcion_msg'   => $descMsg,
                ],
                ['id' => $invoice_id]
            );
    
            $wpdb->query('COMMIT');
    
            // Audit (optional)
            if (class_exists('JC_Audit_Service')) {
                JC_Audit_Service::log([
                    'action' => $ok ? 'mh_send_success' : 'mh_send_failed',
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice_id,
                    'register_id' => (int)($inv->register_id ?? 0),
                    'document_type' => (string)($inv->document_type ?? ''),
                    'message' => $ok ? 'Sent to MH' : 'Send failed',
                    'meta' => [
                        'mh_status' => $mh_status,
                        'attempts' => $attempts,
                        'estado' => $estado,
                        'codigoMsg' => $codigoMsg,
                        'descripcionMsg' => $descMsg,
                    ],
                ]);
            }
    
            // Return a clean payload to POS UI
            return [
                'mh_status'        => $mh_status,
                'estado'           => $estado,
                'codigoGeneracion' => $codigoGen,
                'selloRecibido'    => $selloRecibido,
                'codigoMsg'        => $codigoMsg,
                'descripcionMsg'   => $descMsg,
            ];
    
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
    
            return [
                'mh_status' => 'FAILED',
                'error' => $e->getMessage(),
            ];
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


    private static function send_to_mh_using_existing_stack(int $invoice_id): array {

        if (!class_exists('WC_DTE_SV')) {
            return ['estado' => 'FAILED', 'descripcionMsg' => 'WC_DTE_SV plugin not active'];
        }
    
        // Log so we can confirm this path is hit
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info("Calling WC_DTE_SV::send_dte_for_jc_invoice invoice={$invoice_id}", ['source' => 'jc-pos']);
        }
    
        $dte = new WC_DTE_SV();
        $resp = $dte->send_dte_for_jc_invoice($invoice_id);
    
        return is_array($resp)
            ? $resp
            : ['estado' => 'FAILED', 'descripcionMsg' => 'Invalid MH response'];
    }
}