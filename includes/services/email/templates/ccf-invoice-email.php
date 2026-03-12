<?php if (!defined('ABSPATH')) exit; ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Factura electrónica</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; line-height: 1.5;">
    <div style="max-width: 700px; margin: 0 auto; padding: 20px;">
        <h2 style="margin-bottom: 10px;">Factura electrónica</h2>

        <p>Hola <?php echo esc_html($customer_name); ?>,</p>

        <p>
            Adjuntamos su factura electrónica correspondiente a su compra en
            <strong><?php echo esc_html($store_name); ?></strong>.
        </p>

        <table style="border-collapse: collapse; width: 100%; margin: 20px 0;">
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Número</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><?php echo esc_html($ticket_number); ?></td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Fecha</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><?php echo esc_html($issued_at); ?></td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Total</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><?php echo esc_html($total); ?></td>
            </tr>
        </table>

        <p>Gracias por su compra.</p>
    </div>
</body>
</html>