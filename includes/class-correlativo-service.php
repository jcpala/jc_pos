<?php

class JC_Correlativo_Service {

    /**
     * Returns an ACTIVE correlativo row locked FOR UPDATE.
     * If the active correlativo is exhausted, it will mark it EXHAUSTED and try to activate the next PENDING one.
     * Must be called inside an open DB transaction.
     */
    public static function get_active_correlativo_for_update(int $register_id, string $document_type) {
        global $wpdb;

        // 1) Lock current ACTIVE correlativo
        $active = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM wp_jc_correlativos
                 WHERE register_id = %d
                   AND document_type = %s
                   AND status = 'ACTIVE'
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE",
                $register_id,
                $document_type
            )
        );

        if (!$active) {
            // No active correlativo: try to activate a pending one
            return self::activate_next_pending_correlativo($register_id, $document_type);
        }

        // 2) If exhausted, retire it and activate next pending
        if ((int)$active->current_number > (int)$active->range_end) {
            self::mark_exhausted($active->id);
            return self::activate_next_pending_correlativo($register_id, $document_type);
        }

        return $active;
    }

    private static function mark_exhausted(int $correlativo_id): void {
        global $wpdb;
    
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT register_id, document_type, correlativo_number FROM wp_jc_correlativos WHERE id=%d", $correlativo_id)
        );
    
        $wpdb->update(
            'wp_jc_correlativos',
            ['status' => 'EXHAUSTED', 'is_active' => 0],
            ['id' => $correlativo_id],
            ['%s','%d'],
            ['%d']
        );
    
        if (class_exists('JC_Audit_Service')) {
            JC_Audit_Service::log([
                'action'       => 'correlativo_exhausted',
                'entity_type'  => 'correlativo',
                'entity_id'    => (int)$correlativo_id,
                'register_id'  => $row ? (int)$row->register_id : null,
                'document_type'=> $row ? $row->document_type : null,
                'message'      => 'Correlativo exhausted and deactivated',
                'meta' => [
                    'correlativo_number' => $row ? $row->correlativo_number : null
                ],
            ]);
        }
    }

    /**
     * Activates the next pending correlativo (oldest pending first, or you can switch to newest).
     * Locks the row FOR UPDATE before activating.
     */
    private static function activate_next_pending_correlativo(int $register_id, string $document_type) {
        global $wpdb;

        // Lock the next PENDING correlativo (choose policy: oldest first)
        $pending = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM wp_jc_correlativos
                 WHERE register_id = %d
                   AND document_type = %s
                   AND status = 'PENDING'
                 ORDER BY issue_date ASC, id ASC
                 LIMIT 1
                 FOR UPDATE",
                $register_id,
                $document_type
            )
        );

        if (!$pending) {
            throw new Exception(
                "No ACTIVE correlativo and no PENDING correlativo available for {$document_type}. " .
                "Please register a new correlativo in the admin."
            );
        }

        // Activate it
        $wpdb->update(
            'wp_jc_correlativos',
            [
                'status'    => 'ACTIVE',
                'is_active' => 1
            ],
            ['id' => $pending->id],
            ['%s','%d'],
            ['%d']
        );

        JC_Audit_Service::log([
            'action'       => 'correlativo_auto_switch',
            'entity_type'  => 'correlativo',
            'entity_id'    => (int)$pending->id,
            'register_id'  => (int)$register_id,
            'document_type'=> $document_type,
            'message'      => 'Auto-activated next pending correlativo',
            'meta' => [
                'activated_correlativo_number' => $pending->correlativo_number,
                'range_start' => (int)$pending->range_start,
                'range_end'   => (int)$pending->range_end,
                'current_number' => (int)$pending->current_number,
            ],
        ]);
        
        // Re-read it locked (optional but safe)
        $active_now = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM wp_jc_correlativos
                 WHERE id = %d
                 FOR UPDATE",
                $pending->id
            )
        );

        // If it is already exhausted (bad config), mark exhausted and try again
        if ((int)$active_now->current_number > (int)$active_now->range_end) {
            self::mark_exhausted((int)$active_now->id);
            return self::activate_next_pending_correlativo($register_id, $document_type);
        }

        return $active_now;
    }
}