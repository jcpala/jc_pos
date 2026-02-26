<?php

class JC_WC_Checkout_Fields {

    public static function init() {
        add_filter('woocommerce_checkout_fields', [self::class, 'add_fields']);
        add_action('woocommerce_checkout_process', [self::class, 'validate_fields']);
        add_action('woocommerce_checkout_create_order', [self::class, 'save_fields'], 10, 2);
    }

    public static function add_fields($fields) {

        // Document type selector
        $fields['billing']['jc_document_type'] = [
            'type'     => 'select',
            'label'    => 'Tipo de documento',
            'required' => true,
            'options'  => [
                'CONSUMIDOR_FINAL' => 'Consumidor Final',
                'CREDITO_FISCAL'   => 'Crédito Fiscal',
            ],
            'priority' => 5
        ];

        // NIT (required for Crédito Fiscal)
        $fields['billing']['jc_customer_nit'] = [
            'type'     => 'text',
            'label'    => 'NIT',
            'required' => false,
            'priority' => 6
        ];

        // Customer / Company name (required for Crédito Fiscal)
        $fields['billing']['jc_customer_name'] = [
            'type'     => 'text',
            'label'    => 'Nombre / Razón social',
            'required' => false,
            'priority' => 7
        ];

        // Address (often required for fiscal docs)
        $fields['billing']['jc_customer_address'] = [
            'type'     => 'textarea',
            'label'    => 'Dirección',
            'required' => false,
            'priority' => 8
        ];

        return $fields;
    }

    public static function validate_fields() {
        $doc = isset($_POST['jc_document_type']) ? wc_clean($_POST['jc_document_type']) : '';

        if (!$doc) {
            wc_add_notice('Seleccione el tipo de documento.', 'error');
            return;
        }

        if ($doc === 'CREDITO_FISCAL') {
            $nit  = isset($_POST['jc_customer_nit']) ? wc_clean($_POST['jc_customer_nit']) : '';
            $name = isset($_POST['jc_customer_name']) ? wc_clean($_POST['jc_customer_name']) : '';

            if ($nit === '') {
                wc_add_notice('NIT es requerido para Crédito Fiscal.', 'error');
            }
            if ($name === '') {
                wc_add_notice('Nombre / Razón social es requerido para Crédito Fiscal.', 'error');
            }
        }
    }

    public static function save_fields($order, $data) {
        $doc = isset($_POST['jc_document_type']) ? wc_clean($_POST['jc_document_type']) : 'CONSUMIDOR_FINAL';

        $order->update_meta_data('_jc_document_type', $doc);
        $order->update_meta_data('_jc_customer_nit', isset($_POST['jc_customer_nit']) ? wc_clean($_POST['jc_customer_nit']) : '');
        $order->update_meta_data('_jc_customer_name', isset($_POST['jc_customer_name']) ? wc_clean($_POST['jc_customer_name']) : '');
        $order->update_meta_data('_jc_customer_address', isset($_POST['jc_customer_address']) ? wc_clean($_POST['jc_customer_address']) : '');
    }
}