<?php
if (!defined('ABSPATH')) exit;

use Dompdf\Dompdf;
use Dompdf\Options;

class JC_DTE_Receipt_Service
{
    public static function init(): void
    {
        add_action('admin_post_jc_dte_receipt', [__CLASS__, 'render_receipt']);
    }

    public static function get_download_url(int $invoice_id): string
    {
        if ($invoice_id <= 0) {
            return '';
        }

        return add_query_arg([
            'action'     => 'jc_dte_receipt',
            'invoice_id' => $invoice_id,
        ], admin_url('admin-post.php'));
    }

    public static function render_receipt(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }

        $invoice_id = isset($_GET['invoice_id']) ? absint($_GET['invoice_id']) : 0;
        if ($invoice_id <= 0) {
            wp_die('Missing invoice_id.');
        }

        self::stream_pdf($invoice_id);
    }

    public static function stream_pdf(int $invoice_id): void
    {
        $pdf_path = self::ensure_pdf_file($invoice_id);

        if ($pdf_path === '' || !file_exists($pdf_path)) {
            wp_die('Could not generate receipt PDF.');
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdf_path) . '"');
        header('Content-Length: ' . filesize($pdf_path));

        readfile($pdf_path);
        exit;
    }

    public static function ensure_pdf_file(int $invoice_id): string
    {
        return self::generate_pdf_file($invoice_id, false);
    }

    public static function generate_pdf_file(int $invoice_id, bool $force = false): string
    {
        if ($invoice_id <= 0) {
            return '';
        }

        $pdf_path = self::get_pdf_file_path($invoice_id);
        if ($pdf_path === '') {
            return '';
        }

        // During active development, always regenerate to avoid stale layout.
        if (file_exists($pdf_path)) {
            @unlink($pdf_path);
        }

        $data = self::load_invoice_bundle($invoice_id);
        if (empty($data['invoice'])) {
            return '';
        }

        if (empty($data['dte']) || !is_array($data['dte'])) {
            return '';
        }

        $html = self::render_html_80mm($invoice_id, $data);
        if (trim($html) === '') {
            return '';
        }

        $uploads_dte_root = WP_CONTENT_DIR . '/uploads/dte';
        if (!file_exists($uploads_dte_root)) {
            wp_mkdir_p($uploads_dte_root);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot($uploads_dte_root);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');

        // 80mm width receipt, long page height.
        $width_pt  = 226.77;  // 80mm
        $height_pt = 1200;    // long thermal-style page
        $dompdf->setPaper([0, 0, $width_pt, $height_pt], 'portrait');

        $dompdf->render();

        $output = $dompdf->output();
        if (!$output) {
            return '';
        }

        $written = file_put_contents($pdf_path, $output);

        return $written !== false ? $pdf_path : '';
    }

    public static function get_pdf_file_path(int $invoice_id): string
    {
        if ($invoice_id <= 0) {
            return '';
        }

        $data = self::load_invoice_bundle($invoice_id);
        if (empty($data['invoice'])) {
            return '';
        }

        $folder   = $data['folder'] ?? 'UNKNOWN';
        $base_dir = WP_CONTENT_DIR . '/uploads/dte/' . $folder . '/pdf';

        if (!file_exists($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        return trailingslashit($base_dir) . 'receipt-' . $invoice_id . '.pdf';
    }

    private static function load_invoice_bundle(int $invoice_id): array
    {
        global $wpdb;

        $t_inv   = $wpdb->prefix . 'jc_invoices';
        $t_items = $wpdb->prefix . 'jc_invoice_items';

        $inv = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t_inv} WHERE id = %d LIMIT 1", $invoice_id),
            ARRAY_A
        );

        $items = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t_items} WHERE invoice_id = %d ORDER BY id ASC", $invoice_id),
            ARRAY_A
        );

        $folder = self::get_doc_folder((string)($inv['document_type'] ?? ''));

        $base_dir      = WP_CONTENT_DIR . '/uploads/dte/' . $folder;
        $unsigned_path = $base_dir . '/unsigned-invoice-' . $invoice_id . '.json';
        $mh_path       = $base_dir . '/mh-response-invoice-' . $invoice_id . '.json';

        $dte = null;
        if (file_exists($unsigned_path)) {
            $raw = file_get_contents($unsigned_path);
            $dte = json_decode($raw, true);
        }

        $mh = null;
        if (file_exists($mh_path)) {
            $raw = file_get_contents($mh_path);
            $mh = json_decode($raw, true);
        }

        if (!is_array($mh)) {
            $mh = [
                'estado'           => $inv['mh_estado'] ?? null,
                'codigoGeneracion' => $inv['mh_codigo_generacion'] ?? null,
                'selloRecibido'    => $inv['mh_sello_recibido'] ?? null,
                'codigoMsg'        => $inv['mh_codigo_msg'] ?? null,
                'descripcionMsg'   => $inv['mh_descripcion_msg'] ?? ($inv['mh_last_error'] ?? null),
            ];
        }

        return [
            'invoice' => is_array($inv) ? $inv : [],
            'items'   => is_array($items) ? $items : [],
            'dte'     => is_array($dte) ? $dte : null,
            'mh'      => is_array($mh) ? $mh : null,
            'folder'  => $folder,
        ];
    }

    private static function get_doc_folder(string $document_type): string
    {
        $document_type = strtoupper(trim($document_type));

        if ($document_type === 'CONSUMIDOR_FINAL') {
            return 'CONSUMIDOR_FINAL';
        }

        if ($document_type === 'CREDITO_FISCAL') {
            return 'CREDITO_FISCAL';
        }

        if ($document_type === 'NOTA_CREDITO') {
            return 'NOTA_CREDITO';
        }

        return 'UNKNOWN';
    }

    private static function get_logo_data_uri(): string
    {
        $logo_path = WP_CONTENT_DIR . '/uploads/dte/assets/jc_company_logo.jpg';

        if (!file_exists($logo_path)) {
            return '';
        }

        if (!is_readable($logo_path)) {
            return '';
        }

        $contents = @file_get_contents($logo_path);
        if ($contents === false || $contents === '') {
            return '';
        }

        return 'data:image/jpeg;base64,' . base64_encode($contents);
    }

    private static function get_qr_data_uri(string $qr_url): string
    {
        $qr_url = trim($qr_url);
        if ($qr_url === '') {
            return '';
        }

        $qr_lib = dirname(dirname(dirname(__DIR__))) . '/lib/phpqrcode/qrlib.php';

        if (!file_exists($qr_lib)) {
            return '';
        }

        if (!class_exists('QRcode')) {
            require_once $qr_lib;
        }

        if (!class_exists('QRcode')) {
            return '';
        }

        ob_start();
        QRcode::png($qr_url, null, QR_ECLEVEL_M, 4, 1);
        $png_data = ob_get_clean();

        if ($png_data === false || $png_data === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($png_data);
    }

    private static function build_qr_url(array $dte, array $mh): string
    {
        $id = $dte['identificacion'] ?? [];

        $ambiente = trim((string)($id['ambiente'] ?? '00')) ?: '00';
        $codGen   = trim((string)($id['codigoGeneracion'] ?? ($mh['codigoGeneracion'] ?? '')));
        $fechaEmi = trim((string)($id['fecEmi'] ?? ''));

        if ($codGen === '' || $fechaEmi === '') {
            return '';
        }

        return 'https://admin.factura.gob.sv/consultaPublica'
            . '?ambiente=' . rawurlencode($ambiente)
            . '&codGen=' . rawurlencode($codGen)
            . '&fechaEmi=' . rawurlencode($fechaEmi);
    }

    private static function h($value): string
    {
        return esc_html((string)$value);
    }

    private static function money($value): string
    {
        return number_format((float)$value, 2);
    }

    private static function calculate_display_iva(array $data, bool $show_iva): float
    {
        if (!$show_iva) {
            return 0.0;
        }

        $dte = $data['dte'] ?? [];
        $rs  = $dte['resumen'] ?? [];

        if (isset($rs['totalIva']) && is_numeric($rs['totalIva'])) {
            return round((float)$rs['totalIva'], 2);
        }

        return round((float)($data['invoice']['tax_amount'] ?? 0), 2);
    }

    private static function build_display_rows(array $data): string
    {
        $rows = '';

        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $qty        = self::h($item['quantity'] ?? 1);
                $desc       = self::h($item['product_name'] ?? '');
                $line_total = self::money($item['line_total'] ?? 0);

                $rows .= '
                    <tr>
                        <td class="qty">' . $qty . '</td>
                        <td class="desc">' . $desc . '</td>
                        <td class="amt">$' . $line_total . '</td>
                    </tr>';
            }
        } else {
            $lines = is_array($data['dte']['cuerpoDocumento'] ?? null) ? $data['dte']['cuerpoDocumento'] : [];

            foreach ($lines as $line) {
                $qty  = self::h($line['cantidad'] ?? 1);
                $desc = self::h($line['descripcion'] ?? '');

                $line_total = 0.0;
                if (isset($line['ventaGravada'])) {
                    $line_total = (float)$line['ventaGravada'];
                } elseif (isset($line['ventaNogravada'])) {
                    $line_total = (float)$line['ventaNogravada'];
                } elseif (isset($line['ventaExenta'])) {
                    $line_total = (float)$line['ventaExenta'];
                } elseif (isset($line['precioUni'], $line['cantidad'])) {
                    $line_total = (float)$line['precioUni'] * (float)$line['cantidad'];
                }

                $rows .= '
                    <tr>
                        <td class="qty">' . $qty . '</td>
                        <td class="desc">' . $desc . '</td>
                        <td class="amt">$' . self::money($line_total) . '</td>
                    </tr>';
            }
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" style="text-align:center;">Sin items</td></tr>';
        }

        return $rows;
    }

    private static function render_html_80mm(int $invoice_id, array $data): string
    {
        $invoice = $data['invoice'] ?? [];
        $dte     = $data['dte'] ?? [];
        $mh      = $data['mh'] ?? [];

        $document_type = strtoupper((string)($invoice['document_type'] ?? ''));
        $is_cf         = ($document_type === 'CONSUMIDOR_FINAL');
        $show_iva      = !$is_cf;
        $receipt_note = $is_cf ? 'No es un documento fiscal' : '';
        $id = $dte['identificacion'] ?? [];
        $em = $dte['emisor'] ?? [];
        $rc = $dte['receptor'] ?? [];
        $rs = $dte['resumen'] ?? [];

        $logo_src       = self::get_logo_data_uri();
        $qr_url         = self::build_qr_url($dte, $mh);
        $qr_img         = self::get_qr_data_uri($qr_url);
        $ticket_num     = self::h($invoice['ticket_number'] ?? '');
        $fecha_emi      = self::h($id['fecEmi'] ?? '');
        $hora_emi       = self::h($id['horEmi'] ?? '');
        $codigo_gen     = self::h($id['codigoGeneracion'] ?? ($mh['codigoGeneracion'] ?? ''));
        $numero_control = self::h($id['numeroControl'] ?? '');
        $sello_recibido = self::h($mh['selloRecibido'] ?? ($invoice['mh_sello_recibido'] ?? ''));
        $mh_estado      = self::h($mh['estado'] ?? ($invoice['mh_estado'] ?? ''));
        $total_pagar    = self::money($rs['totalPagar'] ?? ($invoice['total'] ?? 0));
        $total_iva      = self::money(self::calculate_display_iva($data, $show_iva));
        $forma_pago     = self::h($invoice['payment_method'] ?? 'CASH');
        $recibido       = self::money(((float)($invoice['cash_paid'] ?? 0)) + ((float)($invoice['card_paid'] ?? 0)));
        $cambio         = self::money(max(0, (((float)($invoice['cash_paid'] ?? 0)) + ((float)($invoice['card_paid'] ?? 0))) - ((float)($invoice['total'] ?? 0))));
        $total_letras   = self::h($rs['totalLetras'] ?? '');
        $rows_html      = self::build_display_rows($data);

        $em_nombre = self::h($em['nombreComercial'] ?? ($em['nombre'] ?? 'TEAVO'));
        $em_dir    = self::h($em['direccion']['complemento'] ?? '');
        $em_tel    = self::h($em['telefono'] ?? '');

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Receipt</title>
    <style>
        @page {
            margin: 8px 8px 10px 8px;
        }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #111;
            line-height: 1.25;
        }
        .center {
            text-align: center;
        }
        .logo {
            max-width: 120px;
            max-height: 55px;
            height: auto;
        }
        .muted {
            color: #444;
        }
        .divider {
            border-top: 1px dashed #777;
            margin: 7px 0;
        }
        .block {
            page-break-inside: avoid;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta td {
            padding: 2px 0;
            vertical-align: top;
        }
        .meta td.label {
            width: 78px;
            font-weight: bold;
        }
        .items th,
        .items td {
            padding: 3px 0;
            border-bottom: 1px dotted #bbb;
            vertical-align: top;
        }
        .items th {
            text-align: left;
            font-weight: bold;
        }
        .qty {
            width: 32px;
        }
        .desc {
            width: auto;
            padding-right: 4px;
        }
        .amt {
            width: 56px;
            text-align: right;
            white-space: nowrap;
        }
        .totals td {
            padding: 2px 0;
        }
        .totals td.label {
            font-weight: bold;
        }
        .totals td.value {
            text-align: right;
            white-space: nowrap;
        }
        .qr-wrap {
            margin-top: 6px;
            text-align: center;
            page-break-inside: avoid;
        }
        .qr-label {
            font-size: 9px;
            margin-top: 3px;
        }

        .small {
            font-size: 10px;
        }
        .meta .small {
            word-break: break-word;
        }

        .strong {
            font-weight: bold;
        }
    </style>
</head>
<body>

    <div class="center block">
        <?php if ($logo_src !== ''): ?>
            <img src="<?php echo $logo_src; ?>" alt="Logo" class="logo">
        <?php endif; ?>
        <div class="strong" style="margin-top:4px;"><?php echo $em_nombre; ?></div>
        <?php if ($em_dir !== ''): ?>
            <div class="small"><?php echo $em_dir; ?></div>
        <?php endif; ?>
        <?php if ($em_tel !== ''): ?>
            <div class="small">Tel: <?php echo $em_tel; ?></div>
        <?php endif; ?>
    </div>

    <div class="divider"></div>

    <div class="center block">
    <div class="strong">DOCUMENTO TRIBUTARIO ELECTRÓNICO</div>
    <div class="strong">FACTURA</div>
    <?php if ($receipt_note !== ''): ?>
        <div class="small muted"><?php echo $receipt_note; ?></div>
    <?php endif; ?>
</div>

    <?php if ($qr_img !== ''): ?>
        <div class="qr-wrap">
            <img src="<?php echo $qr_img; ?>" alt="QR" style="width:95px; height:95px;">
            <div class="qr-label">Portal Ministerio de Hacienda</div>
        </div>
    <?php endif; ?>

    <div class="divider"></div>

    <div class="block">
    <table class="meta">
        <tr>
            <td class="label">Ticket:</td>
            <td><?php echo $ticket_num; ?></td>
        </tr>
        <tr>
            <td class="label">Fecha:</td>
            <td><?php echo $fecha_emi; ?> <?php echo $hora_emi; ?></td>
        </tr>
        <tr>
            <td class="label">Código Gen.:</td>
            <td></td>
        </tr>
        <tr>
            <td colspan="2" class="small"><?php echo $codigo_gen; ?></td>
        </tr>
        <tr>
            <td class="label">Núm. Control:</td>
            <td></td>
        </tr>
        <tr>
            <td colspan="2" class="small"><?php echo $numero_control; ?></td>
        </tr>
        <tr>
            <td class="label">Sello:</td>
            <td></td>
        </tr>
        <tr>
            <td colspan="2" class="small"><?php echo $sello_recibido; ?></td>
        </tr>
        <tr>
            <td class="label">Estado MH:</td>
            <td><?php echo $mh_estado; ?></td>
        </tr>
    </table>
</div>

    <div class="divider"></div>

    <div class="block">
        <table class="items">
            <thead>
                <tr>
                    <th class="qty">Cant</th>
                    <th class="desc">Descripción</th>
                    <th class="amt">Venta</th>
                </tr>
            </thead>
            <tbody>
                <?php echo $rows_html; ?>
            </tbody>
        </table>
    </div>

    <div class="divider"></div>

    <?php if ($total_letras !== ''): ?>
        <div class="block">
            <div class="strong">Valor en letras</div>
            <div class="small"><?php echo $total_letras; ?></div>
        </div>
        <div class="divider"></div>
    <?php endif; ?>

    <div class="block">
        <table class="totals">
            <tr>
                <td class="label">Total:</td>
                <td class="value">$<?php echo $total_pagar; ?></td>
            </tr>
            <?php if ($show_iva): ?>
                <tr>
                    <td class="label">IVA:</td>
                    <td class="value">$<?php echo $total_iva; ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td class="label">Forma pago:</td>
                <td class="value"><?php echo $forma_pago; ?></td>
            </tr>
            <tr>
                <td class="label">Recibido:</td>
                <td class="value">$<?php echo $recibido; ?></td>
            </tr>
            <tr>
                <td class="label">Cambio:</td>
                <td class="value">$<?php echo $cambio; ?></td>
            </tr>
        </table>
    </div>

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}