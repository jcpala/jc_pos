<?php
if (!defined('ABSPATH')) exit;

class JC_DTE_Pdf_Service {

    public static function register_routes(): void {
        self::init();
    }

    public static function init(): void {
        add_action('admin_post_jc_dte_pdf', [__CLASS__, 'download_pdf']);
    }

    public static function download_pdf(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No permission.');
        }

        $invoice_id = isset($_GET['invoice_id']) ? absint($_GET['invoice_id']) : 0;
        if ($invoice_id <= 0) {
            wp_die('Missing invoice_id.');
        }

        $autoprint = !empty($_GET['autoprint']);

        $data = self::load_invoice_bundle($invoice_id);

        if (empty($data['invoice'])) {
            wp_die('Invoice not found.');
        }

        if (empty($data['dte']) || !is_array($data['dte'])) {
            wp_die('DTE JSON not found for this invoice.');
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        echo self::render_html($invoice_id, $data, $autoprint);
        exit;
    }

    private static function load_invoice_bundle(int $invoice_id): array {
        global $wpdb;

        $t_inv   = $wpdb->prefix . 'jc_invoices';
        $t_items = $wpdb->prefix . 'jc_invoice_items';

        $inv = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t_inv} WHERE id=%d LIMIT 1", $invoice_id),
            ARRAY_A
        );

        $items = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t_items} WHERE invoice_id=%d ORDER BY id ASC", $invoice_id),
            ARRAY_A
        );

        $docType = strtoupper((string)($inv['document_type'] ?? 'UNKNOWN'));
        $folder = 'UNKNOWN';
        if ($docType === 'CONSUMIDOR_FINAL') $folder = 'CONSUMIDOR_FINAL';
        if ($docType === 'CREDITO_FISCAL')   $folder = 'CREDITO_FISCAL';
        if ($docType === 'NOTA_CREDITO')     $folder = 'NOTA_CREDITO';

        $baseDir = WP_CONTENT_DIR . '/uploads/dte/' . $folder;

        $unsignedPath = $baseDir . "/unsigned-invoice-{$invoice_id}.json";
        $mhPath       = $baseDir . "/mh-response-invoice-{$invoice_id}.json";

        $dte = null;
        if (file_exists($unsignedPath)) {
            $raw = file_get_contents($unsignedPath);
            $dte = json_decode($raw, true);
        }

        $mh = null;
        if (file_exists($mhPath)) {
            $raw = file_get_contents($mhPath);
            $mh = json_decode($raw, true);
        }

        if (!is_array($mh)) {
            $mh = [
                'estado'           => $inv['mh_estado'] ?? null,
                'codigoGeneracion' => $inv['mh_codigo_generacion'] ?? null,
                'selloRecibido'    => $inv['mh_sello_recibido'] ?? null,
                'codigoMsg'        => $inv['mh_codigo_msg'] ?? null,
                'descripcionMsg'   => $inv['mh_descripcion_msg'] ?? ($inv['mh_last_error'] ?? null),
                'ambiente'         => $inv['mh_ambiente'] ?? null,
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

    private static function build_qr_url(array $dte, array $mh): string {
        $id = $dte['identificacion'] ?? [];

        $ambiente = trim((string)($id['ambiente'] ?? ($mh['ambiente'] ?? '00'))) ?: '00';
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

    private static function h($v): string {
        return esc_html((string)$v);
    }

    private static function money($n): string {
        return number_format((float)$n, 2);
    }

    private static function f(array $source, string $key, float $default = 0.0): float {
        return isset($source[$key]) && $source[$key] !== '' ? (float)$source[$key] : $default;
    }

    private static function first_numeric(array $source, array $keys, float $default = 0.0): float {
        foreach ($keys as $key) {
            if (isset($source[$key]) && $source[$key] !== '' && is_numeric($source[$key])) {
                return (float)$source[$key];
            }
        }
        return $default;
    }

    private static function db_items_have_adjustments(array $items): bool {
        foreach ($items as $item) {
            $name = strtolower(trim((string)($item['product_name'] ?? '')));
            if ($name === '') {
                continue;
            }
            if (preg_match('/descuent|cargo|fee|ajuste|recargo|propina/i', $name)) {
                return true;
            }
        }
        return false;
    }

    private static function build_adjustments_html(array $rs, array $invoice, array $items): string {
        if (self::db_items_have_adjustments($items)) {
            return '';
        }

        $discount = 0.0;
        if (isset($rs['totalDescu'])) {
            $discount = (float)$rs['totalDescu'];
        } else {
            $discount = self::f($rs, 'descuGravada')
                + self::f($rs, 'descuExenta')
                + self::f($rs, 'descuNoSuj');
        }

        if ($discount <= 0) {
            $discount = self::first_numeric($invoice, ['discount_amount', 'descuento', 'discount_total'], 0.0);
        }

        $fee = self::first_numeric($rs, [
            'totalOtrosCargos',
            'otrosCargos',
            'otrosCargosTotal',
            'totalCargos',
            'cargo',
            'fee',
            'serviceFee',
            'service_fee',
        ], 0.0);

        if ($fee <= 0) {
            $fee = self::first_numeric($invoice, ['fee_amount', 'cargo', 'fee_total'], 0.0);
        }

        $html = '';

        if ($discount > 0.009) {
            $html .= "<div class='kv'><b>Descuento:</b> -$" . self::money($discount) . "</div>";
        }

        if ($fee > 0.009) {
            $html .= "<div class='kv'><b>Cargo:</b> $" . self::money($fee) . "</div>";
        }

        return $html;
    }

    private static function calculate_display_iva(array $data, bool $showIva): float {
        if (!$showIva) {
            return 0.0;
        }

        $dte = $data['dte'] ?? [];
        $rs  = $dte['resumen'] ?? [];

        if (isset($rs['totalIva'])) {
            return round((float)$rs['totalIva'], 2);
        }

        if (!empty($rs['tributos']) && is_array($rs['tributos'])) {
            $iva = 0.0;
            foreach ($rs['tributos'] as $tributo) {
                if (isset($tributo['valor'])) {
                    $iva += (float)$tributo['valor'];
                }
            }
            return round($iva, 2);
        }

        if (!empty($dte['cuerpoDocumento']) && is_array($dte['cuerpoDocumento'])) {
            $iva = 0.0;
            foreach ($dte['cuerpoDocumento'] as $line) {
                if (isset($line['ivaItem'])) {
                    $iva += (float)$line['ivaItem'];
                }
            }
            if ($iva > 0) {
                return round($iva, 2);
            }
        }

        return round((float)($data['invoice']['tax_amount'] ?? 0), 2);
    }

    private static function build_rows_html(array $data): string {
        $rowsHtml = '';
        $n = 1;

        if (!empty($data['items'])) {
            foreach ($data['items'] as $it) {
                $qty       = self::h($it['quantity'] ?? 1);
                $desc      = self::h($it['product_name'] ?? '');
                $unitPrice = self::money($it['unit_price'] ?? 0);
                $lineTotal = self::money($it['line_total'] ?? 0);

                $rowsHtml .= "
                    <tr>
                        <td class='c'>{$n}</td>
                        <td class='c'>{$qty}</td>
                        <td>{$desc}</td>
                        <td class='r'>\${$unitPrice}</td>
                        <td class='r'>\${$lineTotal}</td>
                    </tr>";
                $n++;
            }
        } else {
            $lines = is_array($data['dte']['cuerpoDocumento'] ?? null) ? $data['dte']['cuerpoDocumento'] : [];
            foreach ($lines as $line) {
                $qty  = self::h($line['cantidad'] ?? 1);
                $desc = self::h($line['descripcion'] ?? '');

                $unitPrice = (float)($line['precioUni'] ?? 0);

                $lineTotal = 0.0;
                if (isset($line['ventaGravada'])) {
                    $lineTotal = (float)$line['ventaGravada'];
                } elseif (isset($line['ventaNogravada'])) {
                    $lineTotal = (float)$line['ventaNogravada'];
                } elseif (isset($line['ventaExenta'])) {
                    $lineTotal = (float)$line['ventaExenta'];
                } elseif (isset($line['precioUni'], $line['cantidad'])) {
                    $lineTotal = (float)$line['precioUni'] * (float)$line['cantidad'];
                }

                $rowsHtml .= "
                    <tr>
                        <td class='c'>{$n}</td>
                        <td class='c'>{$qty}</td>
                        <td>{$desc}</td>
                        <td class='r'>\$" . self::money($unitPrice) . "</td>
                        <td class='r'>\$" . self::money($lineTotal) . "</td>
                    </tr>";
                $n++;
            }
        }

        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='5' class='c muted'>Sin items</td></tr>";
        }

        return $rowsHtml;
    }
    private static function get_doc_label(string $documentType, string $tipoDte = ''): string {
      $documentType = strtoupper(trim($documentType));
      $tipoDte = trim($tipoDte);
  
      if ($documentType === 'CONSUMIDOR_FINAL' || $tipoDte === '01') {
          return 'FACTURA';
      }
  
      if ($documentType === 'CREDITO_FISCAL' || $tipoDte === '03') {
          return 'COMPROBANTE DE CRÉDITO FISCAL';
      }
  
      if ($documentType === 'NOTA_CREDITO' || $tipoDte === '05') {
          return 'NOTA DE CRÉDITO';
      }
  
      return $tipoDte !== '' ? 'DTE ' . $tipoDte : $documentType;
  }


    private static function render_html(int $invoice_id, array $data, bool $autoprint): string {

      
        $tipoDte  = self::h($id['tipoDte'] ?? '');
        $docLabel = self::h(self::get_doc_label((string)($invoice['document_type'] ?? ''), (string)($id['tipoDte'] ?? '')));
        $invoice = $data['invoice'] ?? [];
        $dte     = $data['dte'] ?? [];
        $mh      = $data['mh'] ?: [];

        $documentType = strtoupper((string)($invoice['document_type'] ?? ''));
        $isCF         = ($documentType === 'CONSUMIDOR_FINAL');
        $showIva      = !$isCF;

        $id = $dte['identificacion'] ?? [];
        $em = $dte['emisor'] ?? [];
        $rc = $dte['receptor'] ?? [];
        $rs = $dte['resumen'] ?? [];

        $qrUrl   = self::build_qr_url($dte, $mh);
        $qrUrlJs = json_encode($qrUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $qrLibUrl = plugins_url('assets/qrcode.min.js', dirname(__DIR__, 2) . '/jc-pos.php');

        $docLabel = self::h(self::get_doc_label((string)($invoice['document_type'] ?? ''), (string)($id['tipoDte'] ?? '')));
        $logoUrl  = esc_url(content_url('uploads/2026/03/jc_company_logo.jpg'));
        $numControl    = self::h($id['numeroControl'] ?? '');
        $codGen        = self::h($id['codigoGeneracion'] ?? ($mh['codigoGeneracion'] ?? ''));
        $fecEmi        = self::h($id['fecEmi'] ?? '');
        $horEmi        = self::h($id['horEmi'] ?? '');
        $sello         = self::h($mh['selloRecibido'] ?? '');
        $estado        = self::h($mh['estado'] ?? ($invoice['mh_estado'] ?? ''));
        $codigoMsg     = self::h($mh['codigoMsg'] ?? '');
        $descMsg       = self::h($mh['descripcionMsg'] ?? ($invoice['mh_last_error'] ?? ''));

        $emNombre = self::h($em['nombreComercial'] ?? $em['nombre'] ?? 'TEAVO');
        $emNit    = self::h($em['nit'] ?? '');
        $emNrc    = self::h($em['nrc'] ?? '');
        $emAct    = self::h($em['descActividad'] ?? '');
        $emDir    = self::h($em['direccion']['complemento'] ?? '');
        $emTel    = self::h($em['telefono'] ?? '');
        $emMail   = self::h($em['correo'] ?? '');

        $rcNombre = self::h($rc['nombre'] ?? ($invoice['customer_name'] ?? 'CONSUMIDOR FINAL'));
        $rcNit    = self::h($rc['nit'] ?? ($rc['numDocumento'] ?? ($invoice['customer_nit'] ?? '')));
        $rcNrc    = self::h($rc['nrc'] ?? ($invoice['customer_nrc'] ?? ''));
        $rcDir    = self::h($rc['direccion']['complemento'] ?? ($invoice['customer_address'] ?? ''));
        $rcTel    = self::h($rc['telefono'] ?? ($invoice['customer_phone'] ?? ''));
        $rcMail   = self::h($rc['correo'] ?? ($invoice['customer_email'] ?? ''));

        $rowsHtml        = self::build_rows_html($data);
        $adjustmentsHtml = self::build_adjustments_html($rs, $invoice, $data['items'] ?? []);

        $subtotalValue = isset($invoice['subtotal'])
            ? (float)$invoice['subtotal']
            : (float)($rs['subTotal'] ?? ($rs['subTotalVentas'] ?? 0));

        $totalValue = isset($rs['totalPagar'])
            ? (float)$rs['totalPagar']
            : (float)($invoice['total'] ?? 0);

        $totalPagar  = self::money($totalValue);
        $subtotal    = self::money($subtotalValue);
        $totalIva    = self::money(self::calculate_display_iva($data, $showIva));
        $totalLetras = self::h($rs['totalLetras'] ?? '');

        $paymentMethod  = strtoupper((string)($invoice['payment_method'] ?? 'CASH'));
        $amountReceived = round((float)($invoice['cash_paid'] ?? 0) + (float)($invoice['card_paid'] ?? 0), 2);
        $changeDue      = round(max(0, $amountReceived - (float)($invoice['total'] ?? 0)), 2);

        $returnUrl = esc_url(admin_url('admin.php?page=jc-pos-invoices'));
        $autoPrintJs = $autoprint ? "
        setTimeout(function(){ window.print(); }, 500);
        window.onafterprint = function() {
          if (window.opener && !window.opener.closed) {
            window.close();
          } else {
            window.location.href = '{$returnUrl}';
          }
        };
        " : "";

        return "<!doctype html>
<html lang='es'>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1'>
  <title>DTE {$invoice_id}</title>

  <style>
    @page { size: A4; margin: 12mm; }
    body { font-family: Arial, sans-serif; color:#111; margin:0; }
    .no-print { margin-bottom: 10px; display:flex; gap:8px; }
    .btn { padding:8px 12px; border:1px solid #ccc; background:#fff; border-radius:8px; cursor:pointer; text-decoration:none; color:#111; }
    .wrap { border:2px solid #ddd; border-radius:14px; padding:14px; }
    .top { display:flex; gap:14px; align-items:flex-start; }
    .qr { width:160px; }
    .head { flex:1; }
    h1 { font-size:16px; margin:0 0 6px; text-align:center; }
    .sub { font-size:12px; text-align:center; color:#333; margin-bottom:10px; }
    .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px; }
    .grid3 { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-top:10px; }
    .box { border:1px solid #ddd; border-radius:10px; padding:10px; }
    .box h3 { margin:0 0 8px; font-size:12px; letter-spacing:.4px; color:#333; }
    .kv { font-size:12px; line-height:1.45; margin-bottom:4px; }
    .kv b { display:inline-block; min-width:140px; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { border-bottom:1px solid #eee; padding:8px 6px; font-size:12px; }
    th { background:#f6f7f7; text-align:left; }
    .c { text-align:center; }
    .r { text-align:right; }
    .muted { color:#666; }
    .badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; background:#eee; }
    .totals { margin-top:10px; display:grid; grid-template-columns: 1fr 280px; gap:10px; align-items:start; }
    .totals .box .kv b { min-width:120px; }
    .right-note { font-size:10px; color:#666; margin-top:6px; word-break:break-all; }
    @media print {
      .no-print { display:none !important; }
      body { margin:0; }
    }
  </style>

  <script src='{$qrLibUrl}'></script>
</head>
<body>

  <div class='no-print'>
    <button class='btn' onclick='window.print()'>Print / Save as PDF</button>
    <a class='btn' href='" . esc_url(admin_url('admin.php?page=jc-pos-invoices')) . "'>Back</a>
  </div>



" . ($logoUrl !== '' ? "<div style='text-align:center; margin-bottom:8px;'><img src='{$logoUrl}' alt='Logo' style='max-height:110px; max-width:270px;'></div>" : "") . "
<h1>DOCUMENTO TRIBUTARIO ELECTRÓNICO</h1>
<div class='sub' style='font-weight:700; font-size:14px;'>{$docLabel}</div>
<div class='sub'>Tipo DTE: {$tipoDte}</div>

    <div class='top'>
      <div class='qr box'>
        <div id='qr' style='display:none;'></div>
        <div style='text-align:center;'>
          <img id='qrimg' alt='QR' style='display:none; width:140px; height:140px;' />
        </div>
        <div class='right-note'>" . ($qrUrl ? self::h($qrUrl) : "QR no disponible") . "</div>
      </div>

      <div class='head box'>
        <h3>IDENTIFICACIÓN</h3>
        <div class='kv'><b>Invoice ID:</b> {$invoice_id}</div>
        <div class='kv'><b>Ticket:</b> " . self::h($invoice['ticket_number'] ?? '') . "</div>
        <div class='kv'><b>Código Generación:</b> {$codGen}</div>
        <div class='kv'><b>Número de Control:</b> {$numControl}</div>
        <div class='kv'><b>Sello de Recepción:</b> {$sello}</div>
        <div class='kv'><b>Fecha/Hora Emisión:</b> {$fecEmi} {$horEmi}</div>
        <div class='kv'><b>MH Estado:</b> {$estado} <span class='badge'>{$codigoMsg}</span></div>
        " . ($descMsg !== '' ? "<div class='kv'><b>Descripción MH:</b> {$descMsg}</div>" : "") . "
      </div>
    </div>

    <div class='grid2'>
      <div class='box'>
        <h3>EMISOR</h3>
        <div class='kv'><b>Nombre:</b> {$emNombre}</div>
        <div class='kv'><b>NIT:</b> {$emNit}</div>
        <div class='kv'><b>NRC:</b> {$emNrc}</div>
        <div class='kv'><b>Actividad:</b> {$emAct}</div>
        <div class='kv'><b>Dirección:</b> {$emDir}</div>
        <div class='kv'><b>Teléfono:</b> {$emTel}</div>
        <div class='kv'><b>Correo:</b> {$emMail}</div>
      </div>

      <div class='box'>
        <h3>RECEPTOR</h3>
        <div class='kv'><b>Nombre:</b> {$rcNombre}</div>
        <div class='kv'><b>NIT:</b> {$rcNit}</div>
        <div class='kv'><b>NRC:</b> {$rcNrc}</div>
        <div class='kv'><b>Dirección:</b> {$rcDir}</div>
        <div class='kv'><b>Teléfono:</b> {$rcTel}</div>
        <div class='kv'><b>Correo:</b> {$rcMail}</div>
      </div>
    </div>

    <div class='box' style='margin-top:10px;'>
      <h3>CUERPO</h3>
      <table>
        <thead>
          <tr>
            <th style='width:42px;' class='c'>#</th>
            <th style='width:70px;' class='c'>Cant</th>
            <th>Descripción</th>
            <th style='width:100px;' class='r'>P. Unit</th>
            <th style='width:100px;' class='r'>Venta</th>
          </tr>
        </thead>
        <tbody>
          {$rowsHtml}
        </tbody>
      </table>
    </div>

    <div class='totals'>
      <div class='box'>
        <h3>VALOR EN LETRAS</h3>
        <div class='kv'>{$totalLetras}</div>
      </div>

      <div class='box'>
        <h3>RESUMEN</h3>
        <div class='kv'><b>Subtotal:</b> \${$subtotal}</div>
        " . ($showIva ? "<div class='kv'><b>Total IVA:</b> \${$totalIva}</div>" : "") . "
        <div class='kv'><b>Total a Pagar:</b> <b>\${$totalPagar}</b></div>
        <div class='kv'><b>Forma de pago:</b> {$paymentMethod}</div>
        " . (in_array($paymentMethod, ['CASH', 'MIXED'], true) ? "
          <div class='kv'><b>Recibido:</b> $" . self::money($amountReceived) . "</div>
          <div class='kv'><b>Cambio:</b> $" . self::money($changeDue) . "</div>
        " : "") . "
        " . ($adjustmentsHtml !== '' ? $adjustmentsHtml : "") . "
      </div>
    </div>
  </div>

  <script>
    (function () {
      var qrText = {$qrUrlJs};
      var qrHolder = document.getElementById('qr');
      var qrImg = document.getElementById('qrimg');

      function doAutoPrint() {
        {$autoPrintJs}
      }

      function showFallback(msg) {
        if (qrHolder) {
          qrHolder.style.display = 'block';
          qrHolder.innerHTML = '<div style=\"font-size:10px;color:#666;\">' + msg + '</div>';
        }
        doAutoPrint();
      }

      function extractQrImage() {
        if (!qrHolder || !qrImg) return false;

        var canvas = qrHolder.querySelector('canvas');
        if (canvas) {
          try {
            qrImg.src = canvas.toDataURL('image/png');
            qrImg.style.display = 'inline-block';
            qrHolder.innerHTML = '';
            return true;
          } catch (e) {}
        }

        var innerImg = qrHolder.querySelector('img');
        if (innerImg && innerImg.src) {
          qrImg.src = innerImg.src;
          qrImg.style.display = 'inline-block';
          qrHolder.innerHTML = '';
          return true;
        }

        return false;
      }

      function renderQr(attemptsLeft) {
        if (!qrText) {
          showFallback('QR no disponible');
          return;
        }

        if (!qrHolder || !qrImg) {
          return;
        }

        if (typeof window.QRCode !== 'function') {
          if (attemptsLeft > 0) {
            setTimeout(function () {
              renderQr(attemptsLeft - 1);
            }, 200);
            return;
          }
          showFallback('QR no disponible');
          return;
        }

        qrHolder.innerHTML = '';

        try {
          new window.QRCode(qrHolder, {
            text: qrText,
            width: 140,
            height: 140
          });
        } catch (e) {
          showFallback('QR error');
          return;
        }

        setTimeout(function () {
          if (extractQrImage()) {
            doAutoPrint();
          } else if (attemptsLeft > 0) {
            setTimeout(function () {
              renderQr(attemptsLeft - 1);
            }, 200);
          } else {
            showFallback('QR error');
          }
        }, 400);
      }

      renderQr(10);
    })();
  </script>

</body>
</html>";
    }
}