

<?php
if (!defined('ABSPATH')) exit;

class JC_DTE_Receipt_Service {

    public static function init(): void {
        add_action('admin_post_jc_dte_receipt', [__CLASS__, 'render_receipt']);
    }

    public static function render_receipt(): void {
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

        echo self::render_html_80mm($invoice_id, $data, $autoprint);
        exit;
    }

    private static function load_invoice_bundle(int $invoice_id): array {
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
            'invoice' => $inv ?: [],
            'items'   => $items ?: [],
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

    private static function build_display_rows(array $data): string {
        $rows = '';

        if (!empty($data['items'])) {
            foreach ($data['items'] as $it) {
                $qty       = self::h($it['quantity'] ?? 1);
                $desc      = self::h($it['product_name'] ?? '');
                $lineTotal = self::money($it['line_total'] ?? 0);

                $rows .= "
                <tr>
                    <td class='qty'>{$qty}</td>
                    <td class='desc'>{$desc}</td>
                    <td class='amt'>\${$lineTotal}</td>
                </tr>";
            }
        } else {
            $dteLines = is_array($data['dte']['cuerpoDocumento'] ?? null) ? $data['dte']['cuerpoDocumento'] : [];
            foreach ($dteLines as $line) {
                $qty  = self::h($line['cantidad'] ?? 1);
                $desc = self::h($line['descripcion'] ?? '');

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

                $rows .= "
                <tr>
                    <td class='qty'>{$qty}</td>
                    <td class='desc'>{$desc}</td>
                    <td class='amt'>\$" . self::money($lineTotal) . "</td>
                </tr>";
            }
        }

        if ($rows === '') {
            $rows = "<tr><td colspan='3' class='muted' style='text-align:center;'>Sin items</td></tr>";
        }

        return $rows;
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
            $html .= "<div class='kv'><span class='k'>Descuento</span><span class='v'>-\$" . self::money($discount) . "</span></div>";
        }

        if ($fee > 0.009) {
            $html .= "<div class='kv'><span class='k'>Cargo</span><span class='v'>\$" . self::money($fee) . "</span></div>";
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

    private static function render_html_80mm(int $invoice_id, array $data, bool $autoprint): string {
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
        $qrLibUrl = plugins_url('../../assets/qrcode.min.js', __FILE__);

        $tipoDte    = self::h($id['tipoDte'] ?? '');
        $docLabel = self::h(self::get_doc_label((string)($invoice['document_type'] ?? ''), (string)($id['tipoDte'] ?? '')));
        $logoUrl = content_url('uploads/2026/03/jc_company_logo.jpg');
        $numControl = self::h($id['numeroControl'] ?? '');
        $codGen     = self::h($id['codigoGeneracion'] ?? ($mh['codigoGeneracion'] ?? ''));
        $fecEmi     = self::h($id['fecEmi'] ?? '');
        $horEmi     = self::h($id['horEmi'] ?? '');
        $sello      = self::h($mh['selloRecibido'] ?? '');
        $estado     = self::h($mh['estado'] ?? ($mh['mh_status'] ?? ''));
        $codigoMsg  = self::h($mh['codigoMsg'] ?? '');
        $descMsg    = self::h($mh['descripcionMsg'] ?? ($mh['error'] ?? ''));

        $emNombre = self::h($em['nombreComercial'] ?? $em['nombre'] ?? 'TEAVO');
        $emNit    = self::h($em['nit'] ?? '');
        $emNrc    = self::h($em['nrc'] ?? '');
        $emDir    = self::h($em['direccion']['complemento'] ?? '');
        $emTel    = self::h($em['telefono'] ?? '');

        $rcNombre = self::h($rc['nombre'] ?? ($invoice['customer_name'] ?? 'CONSUMIDOR FINAL'));
        $rcNit    = self::h($rc['nit'] ?? ($rc['numDocumento'] ?? ($invoice['customer_nit'] ?? '')));
        $rcNrc    = self::h($rc['nrc'] ?? ($invoice['customer_nrc'] ?? ''));
        $rcDir    = self::h($rc['direccion']['complemento'] ?? ($invoice['customer_address'] ?? ''));
        $rcTel    = self::h($rc['telefono'] ?? ($invoice['customer_phone'] ?? ''));

        $rows = self::build_display_rows($data);
        $adjustmentsHtml = self::build_adjustments_html($rs, $invoice, $data['items'] ?? []);

        $totalValue = isset($rs['totalPagar'])
            ? (float)$rs['totalPagar']
            : (float)($invoice['total'] ?? 0);
        $totalPagar = self::money($totalValue);

        $totalIva   = self::money(self::calculate_display_iva($data, $showIva));
        $totalLetras = self::h($rs['totalLetras'] ?? '');
        $amount_received = round((float)($invoice['cash_paid'] ?? 0) + (float)($invoice['card_paid'] ?? 0), 2);
        $change_due = round(max(0, $amount_received - (float)($invoice['total'] ?? 0)), 2);


        $backUrlJs = json_encode(wp_get_referer() ?: admin_url('admin.php?page=jc-pos-invoices'));

        $autoPrintJs = $autoprint ? "
        setTimeout(function(){ window.print(); }, 500);
        window.onafterprint = function() {
          var fallbackUrl = {$backUrlJs};
          if (window.history.length > 1) {
            window.history.back();
          } else {
            window.location.href = fallbackUrl;
          }
        };
        " : "";

        return "<!doctype html>
        <html lang='es'>
        <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width,initial-scale=1'>
        <title>Receipt {$invoice_id}</title>
        <style>
          @page { size: 80mm auto; margin: 3mm; }
          body { margin:0; padding:0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color:#111; }
          .paper { width: 72mm; margin: 0 auto; padding: 2mm 0; }
          .center { text-align:center; }
          .small { font-size: 11px; line-height: 1.25; }
          .xs { font-size: 10px; line-height: 1.2; }
          hr { border:0; border-top:1px dashed #444; margin: 8px 0; }
          table { width:100%; border-collapse:collapse; }
          td { padding: 3px 0; vertical-align:top; font-size: 11px; }
          .qty { width: 9mm; }
          .amt { width: 18mm; text-align:right; white-space:nowrap; }
          .desc { padding: 0 2mm; }
          .muted { color:#444; }
          .kv { display:flex; justify-content:space-between; gap:8px; }
          .k { color:#333; }
          .v { text-align:right; }
          #qr { display:flex; justify-content:center; }
          .qrbox { margin-top: 8px; }
          .btnbar { display:flex; gap:8px; margin: 10px 0; }
          .btn { padding:6px 10px; border:1px solid #ccc; background:#fff; border-radius:8px; cursor:pointer; font-size:12px; }
          @media print { .no-print { display:none !important; } }
        </style>
        <script src='{$qrLibUrl}'></script>
        </head>
        <body>
          <div class='paper'>
            <div class='no-print btnbar'>
              <button class='btn' onclick='window.print()'>Print</button>
              <button class='btn' onclick='jcGoBack()'>Back</button>
            </div>
        
            <div class='center small'>
              " . ($logoUrl !== '' ? "<div style='margin-bottom:6px;'><img src='{$logoUrl}' alt='Logo' style='max-height:60px; max-width:190px;'></div>" : "") . "
              <!-- <div style='font-weight:700; font-size:13px;'>{$emNombre}</div> -->
              <div class='xs' style='font-weight:700; margin-top:4px;'>{$docLabel}</div>
              <div class='xs'>{$emDir}</div>
              <div class='xs'>Tel: {$emTel}</div>
              <div class='xs'>NIT: {$emNit}</div>
              <div class='xs'>NRC: {$emNrc}</div>
            </div>
        
            <hr>
        
            <div class='small'>
              <div class='kv'><span class='k'>Documento</span><span class='v'>{$docLabel}</span></div>
              <div class='kv'><span class='k'>Tipo DTE</span><span class='v'>{$tipoDte}</span></div>
              <div class='kv'><span class='k'>Fecha/Hora</span><span class='v'>{$fecEmi} {$horEmi}</span></div>
              <div class='kv'><span class='k'>Ticket</span><span class='v'>#" . self::h($invoice['ticket_number'] ?? '') . "</span></div>
            </div>
        
            <hr>
        
            <div class='small'>
              <div style='font-weight:700;'>Cliente</div>
              <div>{$rcNombre}</div>
              " . (($rcNit !== '' || $rcNrc !== '' || $rcDir !== '' || $rcTel !== '') ? "
                <div class='xs muted'>NIT: {$rcNit}  NRC: {$rcNrc}</div>
                <div class='xs muted'>{$rcDir}</div>
                <div class='xs muted'>Tel: {$rcTel}</div>
              " : "") . "
            </div>
        
            <hr>
        
            <table>
              <thead>
                <tr>
                  <td class='qty muted'>Cant</td>
                  <td class='desc muted'>Descripción</td>
                  <td class='amt muted'>Total</td>
                </tr>
              </thead>
              <tbody>
                {$rows}
              </tbody>
            </table>
        
            " . ($adjustmentsHtml !== '' ? "<hr><div class='small'>{$adjustmentsHtml}</div>" : "") . "
        
            <hr>
        
            <div class='small'>
              " . ($showIva ? "<div class='kv'><span class='k'>IVA</span><span class='v'>\${$totalIva}</span></div>" : "") . "
              <div class='kv' style='font-weight:700; font-size:12px;'>
                <span class='k'>TOTAL</span>
                <span class='v'>\${$totalPagar}</span>
              </div>
        
              " . (in_array(strtoupper((string)($invoice['payment_method'] ?? 'CASH')), ['CASH', 'MIXED'], true) ? "
                <div class='kv'><span class='k'>Recibido</span><span class='v'>\$" . self::money($amount_received) . "</span></div>
                <div class='kv'><span class='k'>Cambio</span><span class='v'>\$" . self::money($change_due) . "</span></div>
              " : "") . "
        
              " . ($totalLetras !== '' ? "<div class='xs muted' style='margin-top:6px;'>{$totalLetras}</div>" : "") . "
            </div>
        
            <hr>
        
            <div class='small'>
              <div class='kv'><span class='k'>CodGen</span><span class='v' style='max-width:44mm; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'>{$codGen}</span></div>
              <div class='kv'><span class='k'>Control</span><span class='v' style='max-width:44mm; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'>{$numControl}</span></div>
              <div class='kv'><span class='k'>Sello</span><span class='v' style='max-width:44mm; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'>{$sello}</span></div>
              <div class='kv'><span class='k'>MH</span><span class='v'>{$estado} {$codigoMsg}</span></div>
              " . ($descMsg !== '' ? "<div class='xs muted' style='margin-top:4px;'>{$descMsg}</div>" : "") . "
            </div>
        
            <div class='qrbox'>
              <div id='qr' style='display:none;'></div>
              <div class='center'>
                <img id='qrimg' alt='QR' style='display:none; width:160px; height:160px;' />
              </div>
            </div>
        
            <div class='center xs muted' style='margin-top:6px;'>Gracias por su compra</div>
          </div>
        
        <script>
        var jcReceiptBackUrl = {$backUrlJs};
        
        function jcGoBack() {
          if (window.history.length > 1) {
            window.history.back();
          } else {
            window.location.href = jcReceiptBackUrl;
          }
        }
        
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
              qrHolder.innerHTML = '<div class=\"xs muted\">' + msg + '</div>';
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
                width: 160,
                height: 160
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
