<?php
if (!defined('ABSPATH')) exit;

class JC_POS_Invoice_Repository {

    /**
     * @var wpdb
     */
    protected $db;

    /**
     * @var string
     */
    protected $table;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'jc_invoices';
    }

    /**
     * Get one invoice by ID.
     */
    public function get_by_id(int $invoice_id): ?array {
        if ($invoice_id <= 0) {
            return null;
        }

        $sql = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $invoice_id
        );

        $row = $this->db->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Check if invoice exists.
     */
    public function exists(int $invoice_id): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $sql = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE id = %d",
            $invoice_id
        );

        return ((int) $this->db->get_var($sql)) > 0;
    }

    /**
     * Get customer email from invoice.
     */
    public function get_customer_email(int $invoice_id): string {
        $invoice = $this->get_by_id($invoice_id);

        if (!$invoice) {
            return '';
        }

        return !empty($invoice['customer_email'])
            ? sanitize_email((string) $invoice['customer_email'])
            : '';
    }

    /**
     * Update customer email.
     */
    public function update_customer_email(int $invoice_id, string $email): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $email = sanitize_email($email);

        $updated = $this->db->update(
            $this->table,
            [
                'customer_email' => $email,
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Update email status.
     * Allowed: PENDING, SENT, FAILED, SKIPPED
     */
    public function update_email_status(int $invoice_id, string $status): bool {
        if ($invoice_id <= 0 || $status === '') {
            return false;
        }

        $status = strtoupper(sanitize_text_field($status));

        $allowed = ['PENDING', 'SENT', 'FAILED', 'SKIPPED'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $updated = $this->db->update(
            $this->table,
            [
                'email_status' => $status,
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Increment email attempts and set last attempt datetime.
     */
    public function increment_email_attempts(int $invoice_id): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $sql = $this->db->prepare(
            "UPDATE {$this->table}
             SET email_attempts = COALESCE(email_attempts, 0) + 1,
                 email_last_attempt_at = %s
             WHERE id = %d",
            current_time('mysql'),
            $invoice_id
        );

        $result = $this->db->query($sql);

        return $result !== false;
    }

    /**
     * Update last email error message.
     */
    public function update_email_error(int $invoice_id, string $error): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $updated = $this->db->update(
            $this->table,
            [
                'email_error' => sanitize_textarea_field($error),
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Clear stored email error.
     */
    public function clear_email_error(int $invoice_id): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $updated = $this->db->update(
            $this->table,
            [
                'email_error' => '',
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Set email sent datetime.
     */
    public function update_email_sent_at(int $invoice_id, ?string $datetime = null): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        if (empty($datetime)) {
            $datetime = current_time('mysql');
        }

        $updated = $this->db->update(
            $this->table,
            [
                'email_sent_at' => $datetime,
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Mark invoice email as pending.
     */
    public function mark_email_pending(int $invoice_id): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        return $this->db->update(
            $this->table,
            [
                'email_status' => 'PENDING',
            ],
            [
                'id' => $invoice_id,
            ],
            ['%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Mark invoice email as sent.
     */
    public function mark_email_sent(int $invoice_id): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $updated = $this->db->update(
            $this->table,
            [
                'email_status'          => 'SENT',
                'email_error'           => '',
                'email_sent_at'         => current_time('mysql'),
                'email_last_attempt_at' => current_time('mysql'),
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Mark invoice email as failed.
     */
    public function mark_email_failed(int $invoice_id, string $error = ''): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $updated = $this->db->update(
            $this->table,
            [
                'email_status'          => 'FAILED',
                'email_error'           => sanitize_textarea_field($error),
                'email_last_attempt_at' => current_time('mysql'),
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
                '%s',
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Mark invoice email as skipped.
     */
    public function mark_email_skipped(int $invoice_id, string $reason = ''): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $updated = $this->db->update(
            $this->table,
            [
                'email_status'          => 'SKIPPED',
                'email_error'           => sanitize_textarea_field($reason),
                'email_last_attempt_at' => current_time('mysql'),
            ],
            [
                'id' => $invoice_id,
            ],
            [
                '%s',
                '%s',
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated !== false;
    }

    /**
     * Register successful email attempt.
     */
    public function register_email_success(int $invoice_id): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $this->increment_email_attempts($invoice_id);
        return $this->mark_email_sent($invoice_id);
    }

    /**
     * Register failed email attempt.
     */
    public function register_email_failure(int $invoice_id, string $error = ''): bool {
        if ($invoice_id <= 0) {
            return false;
        }

        $this->increment_email_attempts($invoice_id);
        return $this->mark_email_failed($invoice_id, $error);
    }
}