<?php
if (!defined('ABSPATH')) exit;

use Mpdf\Mpdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class JC_DTE_Pdf_Service {

  public static function register_routes() {
    add_action('admin_post_jc_dte_pdf', [self::class, 'download_pdf']);
  }

  public static function download_pdf() {
    if (!current_user_can('manage_woocommerce')) {
      wp_die('No permission.');
    }

    $invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
    if ($invoice_id <= 0) wp_die('Missing invoice_id');

    global $wpdb;
    $t = $wpdb->prefix . 'jc_invoices';

    $inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $invoice_id), ARRAY_A);
    if (!$inv) wp_die('Invoice not found');

    // 1) Load DTE JSON (prefer DB field if you store it; otherwise load from file)
    // If you already store unsigned json path, use it. For now, try file path convention:
    $folder = strtoupper($inv['document_type'] ?? 'CONSUMIDOR_FINAL');
    $base_dir = WP_CONTENT_DIR . '/uploads/dte/' . $folder;
    $unsigned_path = $base_dir . "/unsigned-invoice-{$invoice_id}.json";

    $dte = null;
    if (file_exists($unsigned_path)) {
      $dte = json_decode(file_get_contents($unsigned_path), true);
    }

    // 2) MH response (your row has mh_response and also extracted fields)
    $mh = [];
    if (!empty($inv['mh_response'])) {
      $mh = json_decode($inv['mh_response'], true);
      if (!is_array($mh)) $mh = [];
    }

    // 3) Build official QR URL from DTE (source of truth)
    $ambiente = (string)($dte['identificacion']['ambiente'] ?? ($mh['ambiente'] ?? '00'));
    $codGen   = (string)($dte['identificacion']['codigoGeneracion'] ?? ($mh['codigoGeneracion'] ?? ''));
    $fechaEmi = (string)($dte['identificacion']['fecEmi'] ?? '');

    $qrUrl = '';
    if ($codGen && $fechaEmi) {
      $qrUrl = "https://admin.factura.gob.sv/consultaPublica?ambiente=" . rawurlencode($ambiente)
            . "&codGen=" . rawurlencode($codGen)
            . "&fechaEmi=" . rawurlencode($fechaEmi);
    }

    // 4) QR as base64 PNG
    $qrDataUri = '';
    if ($qrUrl) {
      $options = new QROptions([
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'scale'      => 6,
      ]);
      $png = (new QRCode($options))->render($qrUrl);
      $qrDataUri = 'data:image/png;base64,' . base64_encode($png);
    }

    // 5) Build HTML (Ticket PDF v1)
    $html = self::render_ticket_html($inv, $dte, $mh, $qrDataUri, $qrUrl);

    // 6) Render PDF
    $vendor = plugin_dir_path(__FILE__) . '../../vendor/autoload.php';
    if (file_exists($vendor)) require_once $vendor;
    else wp_die('Missing vendor/autoload.php. Run composer install.');

    $mpdf = new Mpdf([
      'mode' => 'utf-8',
      'format' => 'A4',
      'margin_left' => 10,
      'margin_right' => 10,
      'margin_top' => 10,
      'margin_bottom' => 10,
    ]);

    $mpdf->WriteHTML($html);

    $filename = "DTE-{$invoice_id}.pdf";
    $mpdf->Output($filename, 'D');
    exit;
  }

  private static function render_ticket_html(array $inv, ?array $dte, array $mh, string $qrDataUri, string $qrUrl): string {
    $em = $dte['emisor'] ?? [];
    $id = $dte['identificacion'] ?? [];
    $rc = $dte['receptor'] ?? [];
    $res = $dte['resumen'] ?? [];

    $estado = esc_html((string)($mh['estado'] ?? ($inv['mh_estado'] ?? '')));
    $sello  = esc_html((string)($mh['selloRecibido'] ?? ($inv['mh_sello_recibido'] ?? '')));

    $rowsHtml = '';
    $items = $dte['cuerpoDocumento'] ?? [];
    if (is_array($items)) {
      foreach ($items as $it) {
        $qty  = esc_html((string)($it['cantidad'] ?? 1));
        $desc = esc_html((string)($it['descripcion'] ?? ''));
        $p    = number_format((float)($it['precioUni'] ?? 0), 2);
        $rowsHtml .= "<tr><td>{$qty}x {$desc}</td><td style='text-align:right'>\${$p}</td></tr>";
      }
    }

    $total = number_format((float)($res['totalPagar'] ?? ($inv['total'] ?? 0)), 2);

    $qrBlock = '';
    if ($qrDataUri) {
      $qrBlock = "<div style='text-align:center;margin-top:10px'>
                    <img src='{$qrDataUri}' style='width:140px;height:140px' />
                  </div>";
    } elseif ($qrUrl) {
      $qrBlock = "<div style='font-size:10px;word-break:break-all;margin-top:10px'>{$qrUrl}</div>";
    }

    // Note: manual also reminds “Sello de Recepción” must be included in versión legible.
    $numeroControl = esc_html((string)($id['numeroControl'] ?? ''));
    $codigoGen     = esc_html((string)($id['codigoGeneracion'] ?? ''));
    $fecEmi        = esc_html((string)($id['fecEmi'] ?? ''));
    $horEmi        = esc_html((string)($id['horEmi'] ?? ''));

    $emName = esc_html((string)($em['nombreComercial'] ?? $em['nombre'] ?? ''));
    $emNit  = esc_html((string)($em['nit'] ?? ''));
    $emNrc  = esc_html((string)($em['nrc'] ?? ''));
    $cli    = esc_html((string)($rc['nombre'] ?? 'CONSUMIDOR FINAL'));

    return "
      <style>
        body{ font-family: DejaVu Sans, sans-serif; font-size:12px; }
        .box{ border:1px solid #dcdcde; border-radius:10px; padding:10px; }
        .muted{ color:#50575e; font-size:11px; }
        table{ width:100%; border-collapse:collapse; }
        td{ padding:4px 0; vertical-align:top; }
        hr{ border:0; border-top:1px dashed #999; margin:10px 0; }
      </style>

      <div class='box'>
        <div style='display:flex; justify-content:space-between; align-items:flex-start; gap:10px;'>
          <div>
            <div style='font-size:14px; font-weight:700;'>{$emName}</div>
            <div class='muted'>NIT: {$emNit} &nbsp; NRC: {$emNrc}</div>
            <div class='muted'>Cliente: {$cli}</div>
            <div class='muted'>Fecha: {$fecEmi} {$horEmi}</div>
            <div class='muted'>Control: {$numeroControl}</div>
            <div class='muted'>CodGen: {$codigoGen}</div>
          </div>
          <div style='text-align:right; min-width:160px;'>
            <div class='muted'><strong>MH:</strong> {$estado}</div>
            <div class='muted' style='margin-top:4px;'>Sello:</div>
            <div style='font-size:10px; word-break:break-all;'>{$sello}</div>
          </div>
        </div>

        <hr/>

        <table>{$rowsHtml}</table>

        <hr/>

        <div style='display:flex; justify-content:space-between; font-size:14px;'>
          <strong>Total</strong><strong>\${$total}</strong>
        </div>

        {$qrBlock}

        <div style='margin-top:12px; text-align:center;'>Gracias por su compra</div>
      </div>
    ";
  }
}