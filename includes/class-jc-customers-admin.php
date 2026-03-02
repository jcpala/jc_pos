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
         *
         * IMPORTANT:
         * If your main JC POS menu uses a different slug, change this value.
         */
        const PARENT_SLUG = 'jc-pos';

        /**
         * Boot hooks.
         */
        public static function init(): void {
            add_action('admin_menu', [__CLASS__, 'register_menu']);
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

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $action = isset($_POST['jc_customer_action']) ? sanitize_key(wp_unslash($_POST['jc_customer_action'])) : '';

                if ($action === 'save_customer') {
                    self::handle_save_customer();
                }
            }
        }

        /**
         * Save customer form.
         */
        private static function handle_save_customer(): void {
            check_admin_referer('jc_save_customer', 'jc_customer_nonce');

            if (!self::customers_table_exists()) {
                wp_safe_redirect(self::page_url([
                    'jc_error' => 'missing_table',
                ]));
                exit;
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

            $has_name = trim((string) $data['first_name']) !== '' || trim((string) $data['last_name']) !== '';
            $has_company = trim((string) $data['company']) !== '';

            if (!$has_name && !$has_company) {
                wp_safe_redirect(self::page_url([
                    'action'      => $customer_id > 0 ? 'edit' : 'new',
                    'customer_id' => $customer_id > 0 ? $customer_id : null,
                    'jc_error'    => 'name_required',
                ]));
                exit;
            }

            // Prevent duplicate NRC.
            if (!empty($data['nrc'])) {
                $existing_by_nrc = JC_Customer_Service::get_customer_by_nrc((string) $data['nrc']);

                if ($existing_by_nrc && (int) $existing_by_nrc['id'] !== $customer_id) {
                    wp_safe_redirect(self::page_url([
                        'action'      => $customer_id > 0 ? 'edit' : 'new',
                        'customer_id' => $customer_id > 0 ? $customer_id : null,
                        'jc_error'    => 'duplicate_nrc',
                    ]));
                    exit;
                }
            }

            // Prevent duplicate NIT.
            if (!empty($data['nit'])) {
                $existing_by_nit = JC_Customer_Service::get_customer_by_nit((string) $data['nit']);

                if ($existing_by_nit && (int) $existing_by_nit['id'] !== $customer_id) {
                    wp_safe_redirect(self::page_url([
                        'action'      => $customer_id > 0 ? 'edit' : 'new',
                        'customer_id' => $customer_id > 0 ? $customer_id : null,
                        'jc_error'    => 'duplicate_nit',
                    ]));
                    exit;
                }
            }

            $saved_id = JC_Customer_Service::save_customer($data);

            if ($saved_id <= 0) {
                wp_safe_redirect(self::page_url([
                    'action'      => $customer_id > 0 ? 'edit' : 'new',
                    'customer_id' => $customer_id > 0 ? $customer_id : null,
                    'jc_error'    => 'save_failed',
                ]));
                exit;
            }

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

            $page_slug = self::PAGE_SLUG;
            $table_ready = self::customers_table_exists();
            $invoices_ready = self::invoices_have_customer_id();

            $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
            $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : 'list';
            $customer_id = isset($_GET['customer_id']) ? absint($_GET['customer_id']) : 0;

            if (!in_array($action, ['list', 'view', 'edit', 'new'], true)) {
                $action = 'list';
            }

            $notice_message = self::get_notice_message(
                isset($_GET['jc_notice']) ? sanitize_key(wp_unslash($_GET['jc_notice'])) : ''
            );

            $error_message = self::get_error_message(
                isset($_GET['jc_error']) ? sanitize_key(wp_unslash($_GET['jc_error'])) : ''
            );

            $customers = [];
            $customer = null;
            $customer_invoices = [];

            if ($table_ready) {
                $customers = JC_Customer_Service::find_customers($search, 50);

                if ($customer_id > 0) {
                    $customer = JC_Customer_Service::get_customer($customer_id);

                    if (!$customer && in_array($action, ['view', 'edit'], true)) {
                        $error_message = 'Customer not found.';
                    }

                    if ($customer && $action === 'view' && $invoices_ready) {
                        $customer_invoices = JC_Customer_Service::get_customer_invoices($customer_id, 100);
                    }
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
                'page_slug'        => $page_slug,
                'search'           => $search,
                'action'           => $action,
                'customer_id'      => $customer_id,
                'customers'        => $customers,
                'customer'         => $customer,
                'customer_invoices'=> $customer_invoices,
                'notice_message'   => $notice_message,
                'error_message'    => $error_message,
                'table_ready'      => $table_ready,
                'invoices_ready'   => $invoices_ready,
            ]);
        }

        /**
         * Fallback page renderer.
         *
         * This lets you test the page even before admin/page-customers.php exists.
         */
        private static function render_fallback_page(array $data): void {
            $page_slug         = $data['page_slug'];
            $search            = $data['search'];
            $action            = $data['action'];
            $customers         = $data['customers'];
            $customer          = $data['customer'];
            $customer_invoices = $data['customer_invoices'];
            $notice_message    = $data['notice_message'];
            $error_message     = $data['error_message'];
            $table_ready       = $data['table_ready'];
            $invoices_ready    = $data['invoices_ready'];

    
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
                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
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
                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
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