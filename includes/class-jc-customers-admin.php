<?php
defined('ABSPATH') || exit;

if (!class_exists('JC_Customers_Admin')) {

    class JC_Customers_Admin {

        /**
         * Admin page slug.
         */
        const PAGE_SLUG = 'jc-pos-customers';

        /**
         * Parent menu slug.
         */
        const PARENT_SLUG = 'jc-pos';

        /**
         * Keep validation errors in the same request
         * so the form can stay filled.
         */
        private static $request_error_code = '';

        /**
         * Keep posted form values in memory for the same request.
         */
        private static $request_form_data = [];

        /**
         * Boot hooks.
         */
        public static function init(): void {
            add_action('admin_menu', [__CLASS__, 'register_menu'], 99);
            add_action('admin_init', [__CLASS__, 'handle_actions']);
        }

        /**
         * Register submenu page.
         */
        public static function register_menu(): void {
            add_submenu_page(
                self::PARENT_SLUG,
                'Customers',
                'Customers',
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render_page']
            );
        }

        /**
         * Handle admin actions for this page.
         */
        public static function handle_actions(): void {
            if (!is_admin()) {
                return;
            }

            $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';

            if ($page !== self::PAGE_SLUG) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            if (
                isset($_SERVER['REQUEST_METHOD']) &&
                strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST'
            ) {
                $action = isset($_POST['jc_customer_action']) ? sanitize_key(wp_unslash($_POST['jc_customer_action'])) : '';

                if ($action === 'save_customer') {
                    self::handle_save_customer();
                }
            }
        }

        /**
         * Save customer form.
         *
         * Validation errors do NOT redirect.
         * This keeps the form values on screen so the user can fix them.
         *
         * Success DOES redirect to the view page.
         */
        private static function handle_save_customer(): void {
            check_admin_referer('jc_save_customer', 'jc_customer_nonce');

            self::$request_error_code = '';
            self::$request_form_data  = [];

            if (!self::customers_table_exists()) {
                self::$request_error_code = 'missing_table';
                return;
            }

            $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;

            $data = [
                'id'         => $customer_id,
                'wp_user_id' => isset($_POST['wp_user_id']) ? absint($_POST['wp_user_id']) : null,
                'first_name' => isset($_POST['first_name']) ? wp_unslash($_POST['first_name']) : '',
                'last_name'  => isset($_POST['last_name']) ? wp_unslash($_POST['last_name']) : '',
                'company'    => isset($_POST['company']) ? wp_unslash($_POST['company']) : '',
                'nrc'        => isset($_POST['nrc']) ? wp_unslash($_POST['nrc']) : '',
                'nit'        => isset($_POST['nit']) ? wp_unslash($_POST['nit']) : '',
                'address'    => isset($_POST['address']) ? wp_unslash($_POST['address']) : '',
                'city'       => isset($_POST['city']) ? wp_unslash($_POST['city']) : '',
                'phone'      => isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '',
                'email'      => isset($_POST['email']) ? wp_unslash($_POST['email']) : '',
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ];

            // Keep posted values available if validation fails.
            self::$request_form_data = $data;

            $has_name = trim((string) $data['first_name']) !== '' || trim((string) $data['last_name']) !== '';
            $has_company = trim((string) $data['company']) !== '';

            if (!$has_name && !$has_company) {
                self::$request_error_code = 'name_required';
                return;
            }

            // Duplicate NRC check.
            if (trim((string) $data['nrc']) !== '') {
                $existing_by_nrc = JC_Customer_Service::get_customer_by_nrc((string) $data['nrc']);

                if ($existing_by_nrc && (int) $existing_by_nrc['id'] !== $customer_id) {
                    self::$request_error_code = 'duplicate_nrc';
                    return;
                }
            }

            // Duplicate NIT check.
            if (trim((string) $data['nit']) !== '') {
                $existing_by_nit = JC_Customer_Service::get_customer_by_nit((string) $data['nit']);

                if ($existing_by_nit && (int) $existing_by_nit['id'] !== $customer_id) {
                    self::$request_error_code = 'duplicate_nit';
                    return;
                }
            }

            $saved_id = JC_Customer_Service::save_customer($data);

            if ($saved_id <= 0) {
                // Catch DB unique key failures as a fallback.
                global $wpdb;

                $db_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';

                if (
                    stripos($db_error, 'uk_jc_customers_nrc') !== false ||
                    stripos($db_error, "for key 'uk_jc_customers_nrc'") !== false ||
                    stripos($db_error, 'Duplicate entry') !== false && stripos($db_error, 'nrc') !== false
                ) {
                    self::$request_error_code = 'duplicate_nrc';
                } elseif (
                    stripos($db_error, 'uk_jc_customers_nit') !== false ||
                    stripos($db_error, "for key 'uk_jc_customers_nit'") !== false ||
                    stripos($db_error, 'Duplicate entry') !== false && stripos($db_error, 'nit') !== false
                ) {
                    self::$request_error_code = 'duplicate_nit';
                } else {
                    self::$request_error_code = 'save_failed';
                }

                return;
            }

            // Clear cached form values on success.
            self::$request_form_data = [];
            self::$request_error_code = '';

            wp_safe_redirect(self::page_url([
                'action'      => 'view',
                'customer_id' => $saved_id,
                'jc_notice'   => 'saved',
            ]));
            exit;
        }

        /**
         * Render the customers admin page.
         */
        public static function render_page(): void {
            if (!current_user_can('manage_options')) {
                wp_die('You do not have permission to access this page.');
            }

            $page_slug      = self::PAGE_SLUG;
            $table_ready    = self::customers_table_exists();
            $invoices_ready = self::invoices_have_customer_id();

            $search      = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
            $action      = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : 'list';
            $customer_id = isset($_GET['customer_id']) ? absint($_GET['customer_id']) : 0;

            if (!in_array($action, ['list', 'view', 'edit', 'new'], true)) {
                $action = 'list';
            }

            // If there was a validation failure in this same request, keep the form open.
            if (!empty(self::$request_form_data)) {
                $posted_id   = isset(self::$request_form_data['id']) ? (int) self::$request_form_data['id'] : 0;
                $customer_id = $posted_id > 0 ? $posted_id : 0;
                $action      = $customer_id > 0 ? 'edit' : 'new';
            }

            $notice_code = isset($_GET['jc_notice']) ? sanitize_key(wp_unslash($_GET['jc_notice'])) : '';
            $error_code  = self::$request_error_code !== ''
                ? self::$request_error_code
                : (isset($_GET['jc_error']) ? sanitize_key(wp_unslash($_GET['jc_error'])) : '');

            $notice_message = self::get_notice_message($notice_code);
            $error_message  = self::get_error_message($error_code);

            $customers         = [];
            $customer          = null;
            $customer_invoices = [];

            if ($table_ready) {
                $customers = JC_Customer_Service::find_customers($search, 50);

                if ($customer_id > 0) {
                    $customer = JC_Customer_Service::get_customer($customer_id);

                    if (!$customer && in_array($action, ['view', 'edit'], true) && empty(self::$request_form_data)) {
                        $error_message = 'Customer not found.';
                    }

                    if ($customer && $action === 'view' && $invoices_ready) {
                        $customer_invoices = JC_Customer_Service::get_customer_invoices($customer_id, 100);
                    }
                }

                // Merge posted values back into the form after validation errors.
                if (!empty(self::$request_form_data)) {
                    $customer = is_array($customer)
                        ? array_merge($customer, self::$request_form_data)
                        : self::$request_form_data;
                }
            } else {
                $error_message = 'The customers table does not exist yet. Create wp_jc_customers first in phpMyAdmin.';
            }

            $template_path = dirname(__DIR__) . '/admin/page-customers.php';

            if (file_exists($template_path)) {
                include $template_path;
                return;
            }

            self::render_fallback_page([
                'notice_message' => $notice_message,
                'error_message'  => $error_message,
                'table_ready'    => $table_ready,
            ]);
        }

        /**
         * Fallback page renderer.
         */
        private static function render_fallback_page(array $data): void {
            $notice_message = isset($data['notice_message']) ? (string) $data['notice_message'] : '';
            $error_message  = isset($data['error_message']) ? (string) $data['error_message'] : '';
            $table_ready    = !empty($data['table_ready']);
            ?>
            <div class="wrap">
                <h1>Customers</h1>

                <?php if ($notice_message !== '') : ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php echo esc_html($notice_message); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($error_message !== '') : ?>
                    <div class="notice notice-error">
                        <p><?php echo esc_html($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!$table_ready) : ?>
                    <p>The customers table does not exist yet.</p>
                <?php else : ?>
                    <p>Template file not found. Expected: <code>admin/page-customers.php</code></p>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Build page URL.
         */
        public static function page_url(array $args = []): string {
            $url = admin_url('admin.php?page=' . self::PAGE_SLUG);

            $args = array_filter(
                $args,
                static function ($value) {
                    return $value !== null && $value !== '';
                }
            );

            if (!empty($args)) {
                $url = add_query_arg($args, $url);
            }

            return $url;
        }

        /**
         * Check if customers table exists.
         */
        private static function customers_table_exists(): bool {
            global $wpdb;

            $table = $wpdb->prefix . 'jc_customers';

            $found = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            );

            return $found === $table;
        }

        /**
         * Check if invoices table already has customer_id.
         */
        private static function invoices_have_customer_id(): bool {
            global $wpdb;

            $table = $wpdb->prefix . 'jc_invoices';

            $table_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            );

            if ($table_exists !== $table) {
                return false;
            }

            $column = $wpdb->get_var(
                $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'customer_id')
            );

            return !empty($column);
        }

        /**
         * Success notice messages.
         */
        private static function get_notice_message(string $code): string {
            switch ($code) {
                case 'saved':
                    return 'Customer saved successfully.';
                default:
                    return '';
            }
        }

        /**
         * Error messages.
         */
        private static function get_error_message(string $code): string {
            switch ($code) {
                case 'missing_table':
                    return 'The customers table does not exist yet.';
                case 'name_required':
                    return 'Enter at least a customer name or company name.';
                case 'duplicate_nrc':
                    return 'Another customer already uses that NRC.';
                case 'duplicate_nit':
                    return 'Another customer already uses that NIT.';
                case 'save_failed':
                    return 'Could not save the customer.';
                default:
                    return '';
            }
        }
    }
}