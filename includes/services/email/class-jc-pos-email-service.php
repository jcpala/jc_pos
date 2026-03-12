<?php
if (!defined('ABSPATH')) exit;

class JC_POS_Email_Service
{
    protected $invoice_repository;
    protected $mailer;
    protected $template_service;
    protected $logger;

    public function __construct()
    {
        $this->invoice_repository = new JC_POS_Invoice_Repository();
        $this->mailer             = new JC_POS_Mailer();
        $this->template_service   = new JC_POS_Email_Template_Service();
        $this->logger             = class_exists('JC_POS_Logger') ? new JC_POS_Logger() : null;
    }

    public function send_documents_for_invoice(int $invoice_id): array
    {
        $invoice = $this->invoice_repository->get_by_id($invoice_id);

        if (!$invoice) {
            return $this->result(false, 'Invoice not found.', [
                'invoice_id' => $invoice_id,
            ]);
        }

        $document_type = strtoupper((string) ($invoice['document_type'] ?? ''));

        if ($document_type === 'CREDITO_FISCAL') {
            return $this->send_ccf_invoice_email($invoice);
        }

        if ($document_type === 'CONSUMIDOR_FINAL') {
            return $this->send_receipt_email($invoice);
        }

        return $this->mark_skipped_and_return(
            (int) $invoice['id'],
            'Document type not configured for email sending.',
            [
                'invoice_id'    => (int) $invoice['id'],
                'document_type' => $document_type,
            ]
        );
    }

    public function send_ccf_invoice_email(array $invoice): array
    {
        $invoice_id = (int) ($invoice['id'] ?? 0);
        $email      = $this->get_invoice_email($invoice);
        $doc_type   = (string) ($invoice['document_type'] ?? '');

        if ($invoice_id <= 0) {
            return $this->result(false, 'Invalid invoice id.');
        }

        if (!$this->can_send_to_email($email)) {
            return $this->mark_skipped_and_return(
                $invoice_id,
                'Customer email is missing or invalid.',
                [
                    'invoice_id'    => $invoice_id,
                    'document_type' => $doc_type,
                ]
            );
        }

        $attachment = $this->get_ccf_invoice_attachment_path($invoice);

        if ($attachment === '') {
            return $this->mark_failed_and_return(
                $invoice_id,
                'Invoice PDF attachment not found.',
                [
                    'invoice_id'    => $invoice_id,
                    'document_type' => $doc_type,
                    'to'            => $email,
                ]
            );
        }

        $subject = $this->template_service->get_ccf_invoice_subject($invoice);
        $message = $this->template_service->render_ccf_invoice_email($invoice);

        $this->invoice_repository->mark_email_pending($invoice_id);

        $mail_result = $this->mailer->send([
            'to'          => $email,
            'subject'     => $subject,
            'message'     => $message,
            'attachments' => [$attachment],
        ]);

        if (!empty($mail_result['ok'])) {
            $this->invoice_repository->register_email_success($invoice_id);

            $this->log('info', 'CCF invoice email sent.', [
                'invoice_id' => $invoice_id,
                'to'         => $email,
                'attachment' => $attachment,
                'subject'    => $subject,
            ]);

            return $this->result(true, 'CCF invoice email sent successfully.', [
                'invoice_id'    => $invoice_id,
                'document_type' => $doc_type,
                'to'            => $email,
                'attachment'    => $attachment,
                'subject'       => $subject,
            ]);
        }

        $error_message = (string) ($mail_result['message'] ?? 'Email sending failed.');
        $this->invoice_repository->register_email_failure($invoice_id, $error_message);

        $this->log('error', 'Failed sending CCF invoice email.', [
            'invoice_id' => $invoice_id,
            'to'         => $email,
            'attachment' => $attachment,
            'subject'    => $subject,
            'error'      => $error_message,
        ]);

        return $this->result(false, $error_message, [
            'invoice_id'    => $invoice_id,
            'document_type' => $doc_type,
            'to'            => $email,
            'attachment'    => $attachment,
            'subject'       => $subject,
        ]);
    }

    public function send_receipt_email(array $invoice): array
    {
        $invoice_id = (int) ($invoice['id'] ?? 0);
        $email      = $this->get_invoice_email($invoice);
        $doc_type   = (string) ($invoice['document_type'] ?? '');

        if ($invoice_id <= 0) {
            return $this->result(false, 'Invalid invoice id.');
        }

        if (!$this->can_send_to_email($email)) {
            return $this->mark_skipped_and_return(
                $invoice_id,
                'Customer email is missing or invalid.',
                [
                    'invoice_id'    => $invoice_id,
                    'document_type' => $doc_type,
                ]
            );
        }

        $attachment = $this->get_receipt_attachment_path($invoice);

        if ($attachment === '') {
            return $this->mark_failed_and_return(
                $invoice_id,
                'Receipt PDF attachment not found.',
                [
                    'invoice_id'    => $invoice_id,
                    'document_type' => $doc_type,
                    'to'            => $email,
                ]
            );
        }

        $subject = $this->template_service->get_receipt_subject($invoice);
        $message = $this->template_service->render_receipt_email($invoice);

        $this->invoice_repository->mark_email_pending($invoice_id);

        $mail_result = $this->mailer->send([
            'to'          => $email,
            'subject'     => $subject,
            'message'     => $message,
            'attachments' => [$attachment],
        ]);

        if (!empty($mail_result['ok'])) {
            $this->invoice_repository->register_email_success($invoice_id);

            $this->log('info', 'Receipt email sent.', [
                'invoice_id' => $invoice_id,
                'to'         => $email,
                'attachment' => $attachment,
                'subject'    => $subject,
            ]);

            return $this->result(true, 'Receipt email sent successfully.', [
                'invoice_id'    => $invoice_id,
                'document_type' => $doc_type,
                'to'            => $email,
                'attachment'    => $attachment,
                'subject'       => $subject,
            ]);
        }

        $error_message = (string) ($mail_result['message'] ?? 'Email sending failed.');
        $this->invoice_repository->register_email_failure($invoice_id, $error_message);

        $this->log('error', 'Failed sending receipt email.', [
            'invoice_id' => $invoice_id,
            'to'         => $email,
            'attachment' => $attachment,
            'subject'    => $subject,
            'error'      => $error_message,
        ]);

        return $this->result(false, $error_message, [
            'invoice_id'    => $invoice_id,
            'document_type' => $doc_type,
            'to'            => $email,
            'attachment'    => $attachment,
            'subject'       => $subject,
        ]);
    }

    protected function get_invoice_email(array $invoice): string
    {
        return !empty($invoice['customer_email'])
            ? sanitize_email((string) $invoice['customer_email'])
            : '';
    }

    protected function can_send_to_email(string $email): bool
    {
        return $email !== '' && is_email($email);
    }

    protected function get_ccf_invoice_attachment_path(array $invoice): string
    {
        $invoice_id = (int) ($invoice['id'] ?? 0);
        if ($invoice_id <= 0) {
            return '';
        }

        if (!class_exists('JC_DTE_Pdf_Service')) {
            return '';
        }

        $path = JC_DTE_Pdf_Service::ensure_pdf_file($invoice_id);
        return $this->validate_attachment_path($path);
    }

    protected function get_receipt_attachment_path(array $invoice): string
    {
        $invoice_id = (int) ($invoice['id'] ?? 0);
        if ($invoice_id <= 0) {
            return '';
        }

        if (!class_exists('JC_DTE_Receipt_Service')) {
            return '';
        }

        $path = JC_DTE_Receipt_Service::ensure_pdf_file($invoice_id);
        return $this->validate_attachment_path($path);
    }

    protected function validate_attachment_path(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (!file_exists($path)) {
            return '';
        }

        if (!is_readable($path)) {
            return '';
        }

        return $path;
    }

    protected function mark_skipped_and_return(int $invoice_id, string $reason, array $extra = []): array
    {
        if ($invoice_id > 0) {
            $this->invoice_repository->mark_email_skipped($invoice_id, $reason);
        }

        $this->log('info', 'Invoice email skipped.', array_merge([
            'invoice_id' => $invoice_id,
            'reason'     => $reason,
        ], $extra));

        return $this->result(false, $reason, $extra);
    }

    protected function mark_failed_and_return(int $invoice_id, string $reason, array $extra = []): array
    {
        if ($invoice_id > 0) {
            $this->invoice_repository->register_email_failure($invoice_id, $reason);
        }

        $this->log('error', 'Invoice email failed before send.', array_merge([
            'invoice_id' => $invoice_id,
            'reason'     => $reason,
        ], $extra));

        return $this->result(false, $reason, $extra);
    }

    protected function result(bool $ok, string $message, array $extra = []): array
    {
        return array_merge([
            'ok'      => $ok,
            'message' => $message,
        ], $extra);
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        if (!$this->logger) {
            return;
        }

        if (method_exists($this->logger, 'log')) {
            $this->logger->log($level, $message, $context);
            return;
        }

        if ($level === 'error' && method_exists($this->logger, 'error')) {
            $this->logger->error($message, $context);
            return;
        }

        if ($level === 'info' && method_exists($this->logger, 'info')) {
            $this->logger->info($message, $context);
        }
    }
}