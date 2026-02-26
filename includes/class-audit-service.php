<?php

class JC_Audit_Service {

    public static function log(array $entry): void {
        global $wpdb;

        $defaults = [
            'action'       => '',
            'entity_type'  => '',
            'entity_id'    => null,
            'user_id'      => get_current_user_id(),
            'store_id'     => null,
            'register_id'  => null,
            'document_type'=> null,
            'message'      => null,
            'meta'         => null,
            'ip_address'   => self::get_ip(),
        ];

        $entry = array_merge($defaults, $entry);

        // Ensure meta is stored as string
        if (is_array($entry['meta']) || is_object($entry['meta'])) {
            $entry['meta'] = wp_json_encode($entry['meta'], JSON_UNESCAPED_UNICODE);
        }

        $wpdb->insert('wp_jc_audit_log', [
            'action'        => sanitize_text_field($entry['action']),
            'entity_type'   => sanitize_text_field($entry['entity_type']),
            'entity_id'     => is_null($entry['entity_id']) ? null : (int)$entry['entity_id'],
            'user_id'       => (int)$entry['user_id'],
            'store_id'      => is_null($entry['store_id']) ? null : (int)$entry['store_id'],
            'register_id'   => is_null($entry['register_id']) ? null : (int)$entry['register_id'],
            'document_type' => is_null($entry['document_type']) ? null : sanitize_text_field($entry['document_type']),
            'message'       => is_null($entry['message']) ? null : sanitize_textarea_field($entry['message']),
            'meta'          => $entry['meta'],
            'ip_address'    => is_null($entry['ip_address']) ? null : sanitize_text_field($entry['ip_address']),
        ], [
            '%s','%s','%d','%d','%d','%d','%s','%s','%s','%s'
        ]);
    }

    private static function get_ip(): ?string {
        // Basic safe IP retrieval
        $keys = ['REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                return substr((string)$_SERVER[$k], 0, 45);
            }
        }
        return null;
    }
}