<?php

class JC_Correlativo_Notices {

    public static function init() {
        add_action('admin_notices', [self::class, 'low_ticket_notice']);
    }

    public static function low_ticket_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        $threshold = (int) get_option('jc_low_ticket_threshold', 100);

        // Find ACTIVE correlativos that are low
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.*, r.register_name
                 FROM wp_jc_correlativos c
                 JOIN wp_jc_registers r ON r.id = c.register_id
                 WHERE c.status = 'ACTIVE'
                   AND (c.range_end - c.current_number + 1) <= %d
                 ORDER BY (c.range_end - c.current_number + 1) ASC",
                $threshold
            )
        );

        if (empty($rows)) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>JC POS:</strong> Low correlativo numbers remaining:</p><ul style="margin-left:20px;">';

        foreach ($rows as $row) {
            $remaining = (int)$row->range_end - (int)$row->current_number + 1;
            if ($remaining < 0) $remaining = 0;

            $doc = esc_html($row->document_type);
            $reg = esc_html($row->register_name);
            $cor = esc_html($row->correlativo_number);

            echo "<li><strong>{$reg}</strong> – {$doc} – Correlativo {$cor}: <strong>{$remaining}</strong> remaining</li>";
        }

        echo '</ul><p>Go to <a href="' . esc_url(admin_url('admin.php?page=jc-correlativos')) . '">Correlativos</a> to add a new range before it runs out.</p></div>';
    }
}