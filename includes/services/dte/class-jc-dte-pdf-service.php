<?php
if (!defined('ABSPATH')) exit;

use Dompdf\Dompdf;
use Dompdf\Options;

class JC_DTE_Pdf_Service
{
    public static function register_routes(): void
    {
        self::init();
    }

    public static function init(): void
    {
        add_action('admin_post_jc_dte_pdf', [__CLASS__, 'download_pdf']);
    }

    public static function get_download_url(int $invoice_id): string
    {
        if ($invoice_id <= 0) {
            return '';
        }

        return add_query_arg([
            'action'     => 'jc_dte_pdf',
            'invoice_id' => $invoice_id,
        ], admin_url('admin-post.php'));
    }

    public static function download_pdf(): void
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
            wp_die('Could not generate PDF.');
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

        if (!$force && file_exists($pdf_path) && is_readable($pdf_path)) {
            return $pdf_path;
        }

        $data = self::load_invoice_bundle($invoice_id);
        if (empty($data['invoice'])) {
            return '';
        }

        if (empty($data['dte']) || !is_array($data['dte'])) {
            return '';
        }

        $html = self::render_html($invoice_id, $data);
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
        $dompdf->setPaper('letter', 'portrait');
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

        return trailingslashit($base_dir) . 'invoice-' . $invoice_id . '.pdf';
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


    private static function get_logo_data_uri(): string
    {
        $logo_path = WP_CONTENT_DIR . '/uploads/dte/assets/jc_company_logo.jpg';

        if (!file_exists($logo_path) || !is_readable($logo_path)) {
            return '';
        }

        $contents = file_get_contents($logo_path);
        if ($contents === false || $contents === '') {
            return '';
        }

        $mime = 'image/jpeg';
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($logo_path);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
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

    private static function get_doc_label(string $document_type, string $tipo_dte = ''): string
    {
        $document_type = strtoupper(trim($document_type));
        $tipo_dte      = trim($tipo_dte);

        if ($document_type === 'CONSUMIDOR_FINAL' || $tipo_dte === '01') {
            return 'FACTURA';
        }

        if ($document_type === 'CREDITO_FISCAL' || $tipo_dte === '03') {
            return 'COMPROBANTE DE CRÉDITO FISCAL';
        }

        if ($document_type === 'NOTA_CREDITO' || $tipo_dte === '05') {
            return 'NOTA DE CRÉDITO';
        }

        return $tipo_dte !== '' ? 'DTE ' . $tipo_dte : $document_type;
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

    private static function build_rows_html(array $data): string
    {
        $rows = '';
        $n    = 1;

        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $qty        = self::h($item['quantity'] ?? 1);
                $desc       = self::h($item['product_name'] ?? '');
                $unit_price = self::money($item['unit_price'] ?? 0);
                $line_total = self::money($item['line_total'] ?? 0);

                $rows .= '
                    <tr>
                        <td class="c">' . $n . '</td>
                        <td class="c">' . $qty . '</td>
                        <td>' . $desc . '</td>
                        <td class="r">$' . $unit_price . '</td>
                        <td class="r">$' . $line_total . '</td>
                    </tr>';
                $n++;
            }
        } else {
            $lines = is_array($data['dte']['cuerpoDocumento'] ?? null) ? $data['dte']['cuerpoDocumento'] : [];

            foreach ($lines as $line) {
                $qty        = self::h($line['cantidad'] ?? 1);
                $desc       = self::h($line['descripcion'] ?? '');
                $unit_price = (float)($line['precioUni'] ?? 0);

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
                        <td class="c">' . $n . '</td>
                        <td class="c">' . $qty . '</td>
                        <td>' . $desc . '</td>
                        <td class="r">$' . self::money($unit_price) . '</td>
                        <td class="r">$' . self::money($line_total) . '</td>
                    </tr>';
                $n++;
            }
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="c">Sin items</td></tr>';
        }

        return $rows;
    }

    private static function render_html(int $invoice_id, array $data): string
    {
        $invoice = $data['invoice'] ?? [];
        $dte     = $data['dte'] ?? [];
        $mh      = $data['mh'] ?? [];

        $document_type = strtoupper((string)($invoice['document_type'] ?? ''));
        $is_cf         = ($document_type === 'CONSUMIDOR_FINAL');
        $show_iva      = !$is_cf;

        $id = $dte['identificacion'] ?? [];
        $em = $dte['emisor'] ?? [];
        $rc = $dte['receptor'] ?? [];
        $rs = $dte['resumen'] ?? [];

        $logo_src       = self::get_logo_data_uri();
        $qr_url         = self::build_qr_url($dte, $mh);
        $qr_img         = self::get_qr_data_uri($qr_url);
        $doc_label      = self::h(self::get_doc_label((string)($invoice['document_type'] ?? ''), (string)($id['tipoDte'] ?? '')));
        $tipo_dte       = self::h($id['tipoDte'] ?? '');
        $numero_control = self::h($id['numeroControl'] ?? '');
        $codigo_gen     = self::h($id['codigoGeneracion'] ?? ($mh['codigoGeneracion'] ?? ''));
        $fecha_emi      = self::h($id['fecEmi'] ?? '');
        $hora_emi       = self::h($id['horEmi'] ?? '');
        $sello_recibido = self::h($mh['selloRecibido'] ?? ($invoice['mh_sello_recibido'] ?? ''));
        $mh_estado      = self::h($mh['estado'] ?? ($invoice['mh_estado'] ?? ''));
        $mh_codigo_msg  = self::h($mh['codigoMsg'] ?? ($invoice['mh_codigo_msg'] ?? ''));
        $mh_descripcion = self::h($mh['descripcionMsg'] ?? ($invoice['mh_descripcion_msg'] ?? ($invoice['mh_last_error'] ?? '')));

        // Prefer legal person name first, then business name.
        $em_nombre = self::h($em['nombre'] ?? ($em['nombreComercial'] ?? 'TEAVO'));
        $em_nit    = self::h($em['nit'] ?? '');
        $em_nrc    = self::h($em['nrc'] ?? '');
        $em_act    = self::h($em['descActividad'] ?? '');
        $em_dir    = self::h($em['direccion']['complemento'] ?? '');
        $em_tel    = self::h($em['telefono'] ?? '');
        $em_mail   = self::h($em['correo'] ?? '');

        $rc_nombre = self::h($rc['nombre'] ?? ($invoice['customer_name'] ?? 'CONSUMIDOR FINAL'));
        $rc_nit    = self::h($rc['nit'] ?? ($rc['numDocumento'] ?? ($invoice['customer_nit'] ?? '')));
        $rc_nrc    = self::h($rc['nrc'] ?? ($invoice['customer_nrc'] ?? ''));
        $rc_dir    = self::h($rc['direccion']['complemento'] ?? ($invoice['customer_address'] ?? ''));
        $rc_tel    = self::h($rc['telefono'] ?? ($invoice['customer_phone'] ?? ''));
        $rc_mail   = self::h($rc['correo'] ?? ($invoice['customer_email'] ?? ''));

        $rows_html    = self::build_rows_html($data);
        $subtotal     = self::money($invoice['subtotal'] ?? ($rs['subTotal'] ?? 0));
        $total_pagar  = self::money($rs['totalPagar'] ?? ($invoice['total'] ?? 0));
        $total_iva    = self::money(self::calculate_display_iva($data, $show_iva));
        $total_letras = self::h($rs['totalLetras'] ?? '');
        $forma_pago   = self::h($invoice['payment_method'] ?? 'CASH');
        $recibido     = self::money(((float)($invoice['cash_paid'] ?? 0)) + ((float)($invoice['card_paid'] ?? 0)));
        $cambio       = self::money(max(0, (((float)($invoice['cash_paid'] ?? 0)) + ((float)($invoice['card_paid'] ?? 0))) - ((float)($invoice['total'] ?? 0))));
        $ticket_num   = self::h($invoice['ticket_number'] ?? '');


        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo $doc_label; ?></title>
    <style>

        .section,
        .qr-block,
        .header-table,
.items-table,
.summary-table {
    page-break-inside: avoid;
}

.summary-section {
    page-break-before: auto;
    page-break-inside: avoid;
}

.qr-block {
    margin-top: 10px;
    margin-bottom: 8px;
    page-break-inside: avoid;
}

.items-table thead {
    display: table-header-group;
}

.items-table tr {
    page-break-inside: avoid;
    page-break-after: auto;
}

.section-title {
    page-break-after: avoid;
}

        @page {
            margin: 18px 20px;
        }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #111;
            line-height: 1.35;
        }
        .header-table,
        .info-table,
        .items-table,
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-table td {
            vertical-align: top;
        }
        .logo {
            width: 140px;
            height: auto;
        }
        .title-box {
            text-align: center;
        }
        .title-box h1 {
            font-size: 16px;
            margin: 0 0 4px 0;
        }
        .title-box h2 {
            font-size: 13px;
            margin: 0 0 4px 0;
        }
        .muted {
            color: #444;
        }
        .section {
            border: 1px solid #cfcfcf;
            margin-top: 12px;
            padding: 10px 12px;
        }
        .section-title {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .info-table td {
            padding: 2px 4px;
            vertical-align: top;
        }
        .info-table td.label {
            width: 145px;
            font-weight: bold;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #d8d8d8;
            padding: 6px 7px;
        }
        .items-table th {
            background: #efefef;
            font-weight: bold;
        }
        .c {
            text-align: center;
        }
        .r {
            text-align: right;
        }
        .qr-url {
            border: 1px dashed #bdbdbd;
            padding: 8px;
            font-size: 9px;
            word-break: break-all;
            margin-top: 8px;
        }
        .summary-table td {
            padding: 3px 4px;
        }
        .summary-table td.label {
            font-weight: bold;
            width: 160px;
        }
    </style>
</head>
<body>

<table class="header-table">
    <tr>
        <td style="width: 160px; text-align: left;">
            <?php if ($logo_src !== ''): ?>
                <img src="<?php echo $logo_src; ?>" alt="Logo" class="logo">
            <?php endif; ?>
        </td>
        <td class="title-box">
            <h1>DOCUMENTO TRIBUTARIO ELECTRÓNICO</h1>
            <h2><?php echo $doc_label; ?></h2>
            <div class="muted">Tipo DTE: <?php echo $tipo_dte; ?></div>
        </td>
        <td style="width: 160px;"></td>
    </tr>
</table>

<?php if ($qr_img !== ''): ?>
    <div class="qr-block">
        <table style="border-collapse:collapse;">
            <tr>
                <td style="text-align:left; vertical-align:top;">
                    <img src="<?php echo $qr_img; ?>" alt="QR" style="width:105px; height:105px;">
                    <div style="font-size:10px; margin-top:4px; text-align:left;">
                        Portal Ministerio de Hacienda
                    </div>
                </td>
            </tr>
        </table>
    </div>
<?php endif; ?>

    <div class="section">
        <div class="section-title">Identificación</div>
        <table class="info-table">
            <tr>
            <tr>
    <td class="label">Ticket:</td>
    <td colspan="3"><?php echo $ticket_num; ?></td>
</tr>
<tr>
    <td class="label">Código Generación:</td>
    <td colspan="3"><?php echo $codigo_gen; ?></td>
</tr>
<tr>
    <td class="label">Número de Control:</td>
    <td colspan="3"><?php echo $numero_control; ?></td>
</tr>
            <tr>
                <td class="label">Sello de Recepción:</td>
                <td><?php echo $sello_recibido; ?></td>
                <td class="label">Fecha / Hora Emisión:</td>
                <td><?php echo $fecha_emi; ?> <?php echo $hora_emi; ?></td>
            </tr>
            <tr>
                <td class="label">MH Estado:</td>
                <td><?php echo $mh_estado; ?></td>
                <td class="label">Código MH:</td>
                <td><?php echo $mh_codigo_msg; ?></td>
            </tr>
            <tr>
                <td class="label">Descripción MH:</td>
                <td colspan="3"><?php echo $mh_descripcion; ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Emisor</div>
        <table class="info-table">
            <tr>
                <td class="label">Nombre:</td>
                <td><?php echo $em_nombre; ?></td>
                <td class="label">NIT:</td>
                <td><?php echo $em_nit; ?></td>
            </tr>
            <tr>
                <td class="label">NRC:</td>
                <td><?php echo $em_nrc; ?></td>
                <td class="label">Actividad:</td>
                <td><?php echo $em_act; ?></td>
            </tr>
            <tr>
                <td class="label">Dirección:</td>
                <td colspan="3"><?php echo $em_dir; ?></td>
            </tr>
            <tr>
                <td class="label">Teléfono:</td>
                <td><?php echo $em_tel; ?></td>
                <td class="label">Correo:</td>
                <td><?php echo $em_mail; ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Receptor</div>
        <table class="info-table">
            <tr>
                <td class="label">Nombre:</td>
                <td><?php echo $rc_nombre; ?></td>
                <td class="label">NIT:</td>
                <td><?php echo $rc_nit; ?></td>
            </tr>
            <tr>
                <td class="label">NRC:</td>
                <td><?php echo $rc_nrc; ?></td>
                <td class="label">Teléfono:</td>
                <td><?php echo $rc_tel; ?></td>
            </tr>
            <tr>
                <td class="label">Dirección:</td>
                <td colspan="3"><?php echo $rc_dir; ?></td>
            </tr>
            <tr>
                <td class="label">Correo:</td>
                <td colspan="3"><?php echo $rc_mail; ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Cuerpo del documento</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th style="width: 60px;">Cant</th>
                    <th>Descripción</th>
                    <th style="width: 90px;">P. Unit</th>
                    <th style="width: 90px;">Venta</th>
                </tr>
            </thead>
            <tbody>
                <?php echo $rows_html; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_letras !== ''): ?>
        <div class="section">
            <div class="section-title">Valor en letras</div>
            <div><?php echo $total_letras; ?></div>
        </div>
    <?php endif; ?>

    <div class="section summary-section">
    <div class="section-title">Resumen</div>
    <table class="summary-table">
            <tr>
                <td class="label">Subtotal:</td>
                <td>$<?php echo $subtotal; ?></td>
            </tr>
            <?php if ($show_iva): ?>
                <tr>
                    <td class="label">IVA:</td>
                    <td>$<?php echo $total_iva; ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td class="label">Total a pagar:</td>
                <td>$<?php echo $total_pagar; ?></td>
            </tr>
            <tr>
                <td class="label">Forma de pago:</td>
                <td><?php echo $forma_pago; ?></td>
            </tr>
            <tr>
                <td class="label">Recibido:</td>
                <td>$<?php echo $recibido; ?></td>
            </tr>
            <tr>
                <td class="label">Cambio:</td>
                <td>$<?php echo $cambio; ?></td>
            </tr>
        </table>
    </div>

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}