<?php

class JC_Ticket_Service {

    public static function generate_ticket_number($register_id, $document_type) {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        $correlativo = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM wp_jc_correlativos
                 WHERE register_id = %d
                 AND document_type = %s
                 AND is_active = 1
                 LIMIT 1
                 FOR UPDATE",
                $register_id,
                $document_type
            )
        );

        if (!$correlativo) {
            $wpdb->query('ROLLBACK');
            throw new Exception("No active correlativo found.");
        }

        if ($correlativo->current_number > $correlativo->range_end) {
            $wpdb->query('ROLLBACK');
            throw new Exception("Correlativo exhausted.");
        }

        $next_ticket = $correlativo->current_number;

        $wpdb->update(
            'wp_jc_correlativos',
            ['current_number' => $next_ticket + 1],
            ['id' => $correlativo->id]
        );

        $wpdb->query('COMMIT');

        return [
            'ticket_number' => $next_ticket,
            'correlativo_id' => $correlativo->id,
            'correlativo_number' => $correlativo->correlativo_number
        ];
    }
}