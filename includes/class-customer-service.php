<?php
defined('ABSPATH') || exit;

if (!class_exists('JC_Customer_Service')) {

    class JC_Customer_Service {

        /**
         * Customers table name.
         */
        private static function customers_table(): string {
            global $wpdb;
            return $wpdb->prefix . 'jc_customers';
        }

        /**
         * Invoices table name.
         */
        private static function invoices_table(): string {
            global $wpdb;
            return $wpdb->prefix . 'jc_invoices';
        }

        /**
         * Stores table name.
         */
        private static function stores_table(): string {
            global $wpdb;
            return $wpdb->prefix . 'jc_stores';
        }

        /**
         * Registers table name.
         */
        private static function registers_table(): string {
            global $wpdb;
            return $wpdb->prefix . 'jc_registers';
        }

        /**
         * Normalize a text value to either clean string or null.
         */
        private static function normalize_text($value): ?string {
            if (!is_string($value)) {
                return null;
            }

            $value = trim($value);

            if ($value === '') {
                return null;
            }

            return sanitize_text_field($value);
        }

        /**
         * Normalize a textarea value to either clean string or null.
         */
        private static function normalize_textarea($value): ?string {
            if (!is_string($value)) {
                return null;
            }

            $value = trim($value);

            if ($value === '') {
                return null;
            }

            return sanitize_textarea_field($value);
        }

        /**
         * Normalize an email value to either clean string or null.
         */
        private static function normalize_email($value): ?string {
            if (!is_string($value)) {
                return null;
            }

            $value = trim($value);

            if ($value === '') {
                return null;
            }

            $email = sanitize_email($value);

            return $email !== '' ? $email : null;
        }

        /**
         * Build a display name from customer row.
         */
        public static function build_customer_name(array $customer): string {
            $first = isset($customer['first_name']) ? trim((string) $customer['first_name']) : '';
            $last  = isset($customer['last_name']) ? trim((string) $customer['last_name']) : '';

            $name = trim($first . ' ' . $last);

            if ($name !== '') {
                return $name;
            }

            if (!empty($customer['company'])) {
                return (string) $customer['company'];
            }

            return '';
        }
        public static function get_departamentos(): array {
            global $wpdb;
        
            $table = $wpdb->prefix . 'jc_cat_departamentos';
        
            $rows = $wpdb->get_results(
                "SELECT code, name
                 FROM {$table}
                 WHERE is_active = 1
                 ORDER BY name ASC",
                ARRAY_A
            );
        
            return is_array($rows) ? $rows : [];
        }
        
        public static function get_municipios_by_departamento(string $departamento_code = ''): array {
            global $wpdb;
        
            $table = $wpdb->prefix . 'jc_cat_municipios';
            $departamento_code = trim($departamento_code);
        
            if ($departamento_code === '') {
                $rows = $wpdb->get_results(
                    "SELECT departamento_code, municipio_code, name
                     FROM {$table}
                     WHERE is_active = 1
                     ORDER BY departamento_code ASC, name ASC",
                    ARRAY_A
                );
        
                return is_array($rows) ? $rows : [];
            }
        
            $sql = $wpdb->prepare(
                "SELECT departamento_code, municipio_code, name
                 FROM {$table}
                 WHERE is_active = 1
                   AND departamento_code = %s
                 ORDER BY name ASC",
                $departamento_code
            );
        
            $rows = $wpdb->get_results($sql, ARRAY_A);
        
            return is_array($rows) ? $rows : [];
        }
        
        public static function get_actividades_economicas(string $search = '', int $limit = 200): array {
            global $wpdb;
        
            $table = $wpdb->prefix . 'jc_cat_actividades_economicas';
            $limit = max(1, min(500, $limit));
            $search = trim($search);
        
            if ($search === '') {
                $sql = $wpdb->prepare(
                    "SELECT code, name
                     FROM {$table}
                     WHERE is_active = 1
                     ORDER BY name ASC
                     LIMIT %d",
                    $limit
                );
        
                $rows = $wpdb->get_results($sql, ARRAY_A);
                return is_array($rows) ? $rows : [];
            }
        
            $like = '%' . $wpdb->esc_like($search) . '%';
        
            $sql = $wpdb->prepare(
                "SELECT code, name
                 FROM {$table}
                 WHERE is_active = 1
                   AND (code LIKE %s OR name LIKE %s)
                 ORDER BY name ASC
                 LIMIT %d",
                $like,
                $like,
                $limit
            );
        
            $rows = $wpdb->get_results($sql, ARRAY_A);
        
            return is_array($rows) ? $rows : [];
        }


        /**
         * Create or update a customer.
         *
         * Returns:
         * - customer ID on success
         * - 0 on failure
         */
        public static function save_customer(array $data): int {
            global $wpdb;

            $table = self::customers_table();

            $record = [
                'wp_user_id'                => isset($data['wp_user_id']) && (int) $data['wp_user_id'] > 0 ? (int) $data['wp_user_id'] : null,
                'tipo_persona'              => self::normalize_text($data['tipo_persona'] ?? null),
                'first_name'                => self::normalize_text($data['first_name'] ?? null),
                'last_name'                 => self::normalize_text($data['last_name'] ?? null),
                'company'                   => self::normalize_text($data['company'] ?? null),
                'nombre_comercial'          => self::normalize_text($data['nombre_comercial'] ?? null),
                'nrc'                       => self::normalize_text($data['nrc'] ?? null),
                'nit'                       => self::normalize_text($data['nit'] ?? null),
                'actividad_economica_code'  => self::normalize_text($data['actividad_economica_code'] ?? null),
                'actividad_economica_desc'  => self::normalize_text($data['actividad_economica_desc'] ?? null),
                'address'                   => self::normalize_textarea($data['address'] ?? null),
                'city'                      => self::normalize_text($data['city'] ?? null),
                'departamento_code'         => self::normalize_text($data['departamento_code'] ?? null),
                'municipio_code'            => self::normalize_text($data['municipio_code'] ?? null),
                'direccion_complemento'     => self::normalize_textarea($data['direccion_complemento'] ?? null),
                'phone'                     => self::normalize_text($data['phone'] ?? null),
                'email'                     => self::normalize_email($data['email'] ?? null),
                'tipo_documento'            => self::normalize_text($data['tipo_documento'] ?? null),
                'is_active'                 => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
                'updated_at'                => current_time('mysql'),
            ];

            $formats = [
                '%d', // wp_user_id
                '%s', // tipo_persona
                '%s', // first_name
                '%s', // last_name
                '%s', // company
                '%s', // nombre_comercial
                '%s', // nrc
                '%s', // nit
                '%s', // actividad_economica_code
                '%s', // actividad_economica_desc
                '%s', // address
                '%s', // city
                '%s', // departamento_code
                '%s', // municipio_code
                '%s', // direccion_complemento
                '%s', // phone
                '%s', // email
                '%s', // tipo_documento
                '%d', // is_active
                '%s', // updated_at
            ];

            // Update existing customer.
            if (!empty($data['id'])) {
                $customer_id = (int) $data['id'];

                $updated = $wpdb->update(
                    $table,
                    $record,
                    ['id' => $customer_id],
                    $formats,
                    ['%d']
                );

                return ($updated !== false) ? $customer_id : 0;
            }

            // Insert new customer.
            $record['created_at'] = current_time('mysql');
            $formats[] = '%s';

            $inserted = $wpdb->insert($table, $record, $formats);

            if (!$inserted) {
                return 0;
            }

            return (int) $wpdb->insert_id;
        }

        /**
         * Search customers by NRC, NIT, company, first/last name, phone, or email.
         */
        public static function find_customers(string $search = '', int $limit = 20): array {
            global $wpdb;

            $table = self::customers_table();
            $limit = max(1, min(100, $limit));
            $search = trim($search);

            if ($search === '') {
                $sql = $wpdb->prepare(
                    "SELECT *
                     FROM {$table}
                     WHERE is_active = 1
                     ORDER BY id DESC
                     LIMIT %d",
                    $limit
                );

                $results = $wpdb->get_results($sql, ARRAY_A);

                return is_array($results) ? $results : [];
            }

            $like = '%' . $wpdb->esc_like($search) . '%';

            $sql = $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE is_active = 1
                   AND (
                        nrc LIKE %s
                        OR nit LIKE %s
                        OR company LIKE %s
                        OR first_name LIKE %s
                        OR last_name LIKE %s
                        OR phone LIKE %s
                        OR email LIKE %s
                        OR CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE %s
                   )
                 ORDER BY id DESC
                 LIMIT %d",
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $limit
            );

            $results = $wpdb->get_results($sql, ARRAY_A);

            return is_array($results) ? $results : [];
        }

        /**
         * Get one customer by ID.
         */
        public static function get_customer(int $customer_id): ?array {
            global $wpdb;

            if ($customer_id <= 0) {
                return null;
            }

            $table = self::customers_table();

            $sql = $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE id = %d
                 LIMIT 1",
                $customer_id
            );

            $row = $wpdb->get_row($sql, ARRAY_A);

            return is_array($row) ? $row : null;
        }

        /**
         * Get one customer by exact NRC.
         */
        public static function get_customer_by_nrc(string $nrc): ?array {
            global $wpdb;

            $nrc = trim($nrc);

            if ($nrc === '') {
                return null;
            }

            $table = self::customers_table();

            $sql = $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE nrc = %s
                 LIMIT 1",
                $nrc
            );

            $row = $wpdb->get_row($sql, ARRAY_A);

            return is_array($row) ? $row : null;
        }

        /**
         * Get one customer by exact NIT.
         */
        public static function get_customer_by_nit(string $nit): ?array {
            global $wpdb;

            $nit = trim($nit);

            if ($nit === '') {
                return null;
            }

            $table = self::customers_table();

            $sql = $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE nit = %s
                 LIMIT 1",
                $nit
            );

            $row = $wpdb->get_row($sql, ARRAY_A);

            return is_array($row) ? $row : null;
        }

        /**
         * Get invoices linked to a customer.
         *
         * NOTE:
         * This expects wp_jc_invoices.customer_id to already exist.
         */
        public static function get_customer_invoices(int $customer_id, int $limit = 50): array {
            global $wpdb;

            if ($customer_id <= 0) {
                return [];
            }

            $invoices_table  = self::invoices_table();
            $stores_table    = self::stores_table();
            $registers_table = self::registers_table();
            $limit           = max(1, min(200, $limit));

            $sql = $wpdb->prepare(
                "SELECT
                    i.id,
                    i.ticket_number,
                    i.document_type,
                    i.customer_id,
                    i.customer_name,
                    i.customer_nit,
                    i.total,
                    i.payment_method,
                    i.status,
                    i.mh_status,
                    i.issued_at,
                    s.name AS store_name,
                    r.register_name
                 FROM {$invoices_table} i
                 LEFT JOIN {$stores_table} s
                    ON s.id = i.store_id
                 LEFT JOIN {$registers_table} r
                    ON r.id = i.register_id
                 WHERE i.customer_id = %d
                 ORDER BY i.issued_at DESC, i.id DESC
                 LIMIT %d",
                $customer_id,
                $limit
            );

            $results = $wpdb->get_results($sql, ARRAY_A);

            return is_array($results) ? $results : [];
        }

        public static function nrc_exists(string $nrc, int $exclude_customer_id = 0): bool {
            global $wpdb;
        
            $nrc = trim($nrc);
        
            if ($nrc === '') {
                return false;
            }
        
            $table = self::customers_table();
        
            if ($exclude_customer_id > 0) {
                $sql = $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table}
                     WHERE TRIM(COALESCE(nrc, '')) = TRIM(%s)
                       AND id <> %d",
                    $nrc,
                    $exclude_customer_id
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table}
                     WHERE TRIM(COALESCE(nrc, '')) = TRIM(%s)",
                    $nrc
                );
            }
        
            return ((int) $wpdb->get_var($sql)) > 0;
        }
        
        public static function nit_exists(string $nit, int $exclude_customer_id = 0): bool {
            global $wpdb;
        
            $nit = trim($nit);
        
            if ($nit === '') {
                return false;
            }
        
            $table = self::customers_table();
        
            if ($exclude_customer_id > 0) {
                $sql = $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table}
                     WHERE TRIM(COALESCE(nit, '')) = TRIM(%s)
                       AND id <> %d",
                    $nit,
                    $exclude_customer_id
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table}
                     WHERE TRIM(COALESCE(nit, '')) = TRIM(%s)",
                    $nit
                );
            }
        
            return ((int) $wpdb->get_var($sql)) > 0;
        }

    }
}