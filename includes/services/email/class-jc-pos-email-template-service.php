<?php
if (!defined('ABSPATH')) exit;

class JC_POS_Email_Template_Service
{
    public function get_ccf_invoice_subject(array $invoice): string
    {
        $ticket_number = $this->get_invoice_number($invoice);
        $store_name    = $this->get_store_name();

        if ($ticket_number !== '') {
            return sprintf('Factura %s - %s', $ticket_number, $store_name);
        }

        return sprintf('Factura electrónica - %s', $store_name);
    }

    public function get_receipt_subject(array $invoice): string
    {
        $ticket_number = $this->get_invoice_number($invoice);
        $store_name    = $this->get_store_name();

        if ($ticket_number !== '') {
            return sprintf('Comprobante %s - %s', $ticket_number, $store_name);
        }

        return sprintf('Comprobante de compra - %s', $store_name);
    }

    public function render_ccf_invoice_email(array $invoice): string
    {
        return $this->render_template('ccf-invoice-email.php', [
            'invoice'       => $invoice,
            'customer_name' => $this->get_customer_name($invoice),
            'ticket_number' => $this->get_invoice_number($invoice),
            'store_name'    => $this->get_store_name(),
            'issued_at'     => $this->format_datetime($invoice['issued_at'] ?? ''),
            'total'         => $this->format_money($invoice['total'] ?? 0),
        ]);
    }

    public function render_receipt_email(array $invoice): string
    {
        return $this->render_template('receipt-email.php', [
            'invoice'       => $invoice,
            'customer_name' => $this->get_customer_name($invoice),
            'ticket_number' => $this->get_invoice_number($invoice),
            'store_name'    => $this->get_store_name(),
            'issued_at'     => $this->format_datetime($invoice['issued_at'] ?? ''),
            'total'         => $this->format_money($invoice['total'] ?? 0),
        ]);
    }

    protected function render_template(string $template_file, array $data = []): string
    {
        $template_path = dirname(__FILE__) . '/templates/' . $template_file;

        if (!file_exists($template_path)) {
            return '<p>No se pudo cargar la plantilla del correo.</p>';
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $template_path;
        return (string) ob_get_clean();
    }

    protected function get_customer_name(array $invoice): string
    {
        $name = trim((string) ($invoice['customer_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $company = trim((string) ($invoice['customer_company'] ?? ''));
        if ($company !== '') {
            return $company;
        }

        return 'Cliente';
    }

    protected function get_invoice_number(array $invoice): string
    {
        if (!empty($invoice['ticket_number'])) {
            return (string) $invoice['ticket_number'];
        }

        if (!empty($invoice['id'])) {
            return (string) $invoice['id'];
        }

        return '';
    }

    protected function get_store_name(): string
    {
        $name = get_bloginfo('name');
        return $name ? (string) $name : 'Mi negocio';
    }

    protected function format_datetime(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }

        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return $datetime;
        }

        return date_i18n('d/m/Y h:i A', $timestamp);
    }

    protected function format_money($amount): string
    {
        return '$' . number_format((float) $amount, 2);
    }
}