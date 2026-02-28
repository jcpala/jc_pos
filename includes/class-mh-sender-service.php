<?php

class JC_MH_Sender_Service {

    /**
     * Send a single invoice to MH using the existing DTE stack.
     * Always records attempts + last error in wp_jc_invoices.
     */
    public static function send_one(int $invoice_id): array {
        global $wpdb;

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info("JC_MH_Sender_Service::send_one invoice={$invoice_id}", ['source' => 'jc-pos']);
        }

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
                    'success'          => true,
                    'mh_status'        => 'SENT',
                    'already_sent'     => true,
                    'estado'           => $inv->mh_estado ?? null,
                    'selloRecibido'    => $inv->mh_sello_recibido ?? null,
                    'codigoGeneracion' => $inv->mh_codigo_generacion ?? null,
                    'codigoMsg'        => $inv->mh_codigo_msg ?? null,
                    'descripcionMsg'   => $inv->mh_descripcion_msg ?? null,
                ];
            }

            $attempts = ((int)($inv->mh_attempts ?? 0)) + 1;
            $now = current_time('mysql');

            // Call your working DTE stack (returns MH response array)
            $resp = self::send_to_mh_using_existing_stack($invoice_id);

            // Normalize MH fields
            $estado        = $resp['estado'] ?? null;                // PROCESADO / RECHAZADO / FAILED(?)
            $codigoGen     = $resp['codigoGeneracion'] ?? null;
            $selloRecibido = $resp['selloRecibido'] ?? null;
            $codigoMsg     = $resp['codigoMsg'] ?? null;
            $descMsg       = $resp['descripcionMsg'] ?? null;

            $ok = ($estado === 'PROCESADO' && !empty($selloRecibido));

            $mh_status = $ok
                ? 'SENT'
                : (($estado === 'RECHAZADO') ? 'REJECTED' : 'FAILED');

            $wpdb->update(
                'wp_jc_invoices',
                [
                    'mh_status'          => $mh_status,
                    'mh_attempts'        => $attempts,
                    'mh_last_attempt_at' => $now,
                    'mh_last_error'      => $ok ? null : ($descMsg ?: 'MH send failed'),
                    'mh_response'        => wp_json_encode($resp, JSON_UNESCAPED_UNICODE),

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

            return [
                'success'          => $ok,
                'mh_status'        => $mh_status,
                'estado'           => $estado,
                'codigoGeneracion' => $codigoGen,
                'selloRecibido'    => $selloRecibido,
                'codigoMsg'        => $codigoMsg,
                'descripcionMsg'   => $descMsg,
            ];

        } catch (Throwable $e) {
            // We want to persist the failure to the invoice row too.
            $wpdb->query('ROLLBACK');

            $now = current_time('mysql');

            // Best-effort update (no transaction now)
            try {
                $wpdb->query('START TRANSACTION');

                $inv = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM wp_jc_invoices WHERE id=%d FOR UPDATE", $invoice_id)
                );

                if ($inv) {
                    $attempts = ((int)($inv->mh_attempts ?? 0)) + 1;

                    $wpdb->update(
                        'wp_jc_invoices',
                        [
                            'mh_status'          => 'FAILED',
                            'mh_attempts'        => $attempts,
                            'mh_last_attempt_at' => $now,
                            'mh_last_error'      => $e->getMessage(),
                            'mh_response'        => wp_json_encode(['estado' => 'FAILED', 'descripcionMsg' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                            'mh_estado'          => 'FAILED',
                            'mh_descripcion_msg' => $e->getMessage(),
                        ],
                        ['id' => $invoice_id]
                    );
                }

                $wpdb->query('COMMIT');
            } catch (Throwable $ignored) {
                $wpdb->query('ROLLBACK');
            }

            return [
                'success'   => false,
                'mh_status' => 'FAILED',
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Batch send "unsent" invoices.
     * Includes PENDING/FAILED/REJECTED by default.
     */
    public static function batch_send(int $limit = 50): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM wp_jc_invoices
                 WHERE status = 'ISSUED'
                   AND mh_status IN ('PENDING','FAILED','REJECTED')
                 ORDER BY COALESCE(mh_last_attempt_at, issued_at) ASC
                 LIMIT %d",
                $limit
            )
        );

        $sent = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $r = self::send_one((int)$id);
            if (!empty($r['mh_status']) && $r['mh_status'] === 'SENT') $sent++;
            else $failed++;
        }

        return [
            'processed' => count($ids),
            'sent'      => $sent,
            'failed'    => $failed,
            'ids'       => array_map('intval', $ids),
        ];
    }

    private static function send_to_mh_using_existing_stack(int $invoice_id): array {
        if (!class_exists('WC_DTE_SV')) {
            return ['estado' => 'FAILED', 'descripcionMsg' => 'WC_DTE_SV plugin not active'];
        }

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