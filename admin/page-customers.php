<?php
defined('ABSPATH') || exit;

$current_error_code = isset($error_code) ? (string) $error_code : '';
$current_error_message = isset($error_message) ? (string) $error_message : '';

$is_name_error = ($current_error_code === 'name_required');
$is_nrc_error  = ($current_error_code === 'duplicate_nrc');
$is_nit_error  = ($current_error_code === 'duplicate_nit');

$show_top_error = !empty($current_error_message) && !in_array($action, ['new', 'edit'], true);
$show_form_error = !empty($current_error_message) && in_array($action, ['new', 'edit'], true);

$name_field_style = $is_name_error ? 'border-color:#d63638;' : '';
$nrc_field_style  = $is_nrc_error ? 'border-color:#d63638;' : '';
$nit_field_style  = $is_nit_error ? 'border-color:#d63638;' : '';
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Customers</h1>
    <a href="<?php echo esc_url(JC_Customers_Admin::page_url(['action' => 'new'])); ?>" class="page-title-action">Add New</a>
    <hr class="wp-header-end">

    <?php if (!empty($notice_message)) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($notice_message); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($show_top_error) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($current_error_message); ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($table_ready)) : ?>
        <?php return; ?>
    <?php endif; ?>

    <form method="get" style="margin: 15px 0;">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
        <input
            type="search"
            name="s"
            value="<?php echo esc_attr($search); ?>"
            placeholder="Search by name, company, NRC, NIT, phone, email"
            style="min-width: 320px;"
        >
        <button type="submit" class="button">Search</button>
        <a href="<?php echo esc_url(JC_Customers_Admin::page_url()); ?>" class="button">Reset</a>
    </form>

    <?php if ($action === 'new' || ($action === 'edit' && !empty($customer))) : ?>
        <?php
        $form_customer = is_array($customer) ? $customer : [
            'id'                       => 0,
            'wp_user_id'               => '',
            'tipo_persona'             => '',
            'first_name'               => '',
            'last_name'                => '',
            'company'                  => '',
            'nombre_comercial'         => '',
            'nrc'                      => '',
            'nit'                      => '',
            'actividad_economica_code' => '',
            'actividad_economica_desc' => '',
            'address'                  => '',
            'city'                     => '',
            'departamento_code'        => '',
            'municipio_code'           => '',
            'direccion_complemento'    => '',
            'phone'                    => '',
            'email'                    => '',
            'tipo_documento'           => '',
            'is_active'                => 1,
        ];
        ?>

        <div class="postbox" style="max-width: 980px; padding: 20px; margin-top: 20px;">
            <h2><?php echo esc_html($action === 'new' ? 'Add Customer' : 'Edit Customer'); ?></h2>

            <?php if ($show_form_error) : ?>
                <div class="notice notice-error" style="margin: 12px 0 15px 0; border-left: 4px solid #d63638; background: #fff;">
                    <p style="margin: 10px 12px; color: #d63638; font-weight: 600;">
                        <?php echo esc_html($current_error_message); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('jc_save_customer', 'jc_customer_nonce'); ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <input type="hidden" name="jc_customer_action" value="save_customer">
                <input type="hidden" name="customer_id" value="<?php echo esc_attr((string) ($form_customer['id'] ?? 0)); ?>">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="tipo_persona">Tipo Persona</label></th>
                            <td>
                                <select name="tipo_persona" id="tipo_persona">
                                    <option value="">Select</option>
                                    <option value="NATURAL" <?php selected((string)($form_customer['tipo_persona'] ?? ''), 'NATURAL'); ?>>Natural</option>
                                    <option value="JURIDICA" <?php selected((string)($form_customer['tipo_persona'] ?? ''), 'JURIDICA'); ?>>Jurídica</option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="first_name">
                                    First Name
                                    <?php if ($is_name_error) : ?><span style="color:#d63638;font-weight:700;">*</span><?php endif; ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="first_name" id="first_name" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['first_name'] ?? '')); ?>"
                                    style="<?php echo esc_attr($name_field_style); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="last_name">
                                    Last Name
                                    <?php if ($is_name_error) : ?><span style="color:#d63638;font-weight:700;">*</span><?php endif; ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="last_name" id="last_name" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['last_name'] ?? '')); ?>"
                                    style="<?php echo esc_attr($name_field_style); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="company">
                                    Company
                                    <?php if ($is_name_error) : ?><span style="color:#d63638;font-weight:700;">*</span><?php endif; ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="company" id="company" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['company'] ?? '')); ?>"
                                    style="<?php echo esc_attr($name_field_style); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="nombre_comercial">Nombre Comercial</label></th>
                            <td>
                                <input type="text" name="nombre_comercial" id="nombre_comercial" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['nombre_comercial'] ?? '')); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="nrc">
                                    NRC
                                    <?php if ($is_nrc_error) : ?><span style="color:#d63638;font-weight:700;">*</span><?php endif; ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="nrc" id="nrc" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['nrc'] ?? '')); ?>"
                                    style="<?php echo esc_attr($nrc_field_style); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="nit">
                                    NIT
                                    <?php if ($is_nit_error) : ?><span style="color:#d63638;font-weight:700;">*</span><?php endif; ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="nit" id="nit" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['nit'] ?? '')); ?>"
                                    style="<?php echo esc_attr($nit_field_style); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="actividad_economica_code">Actividad Económica</label></th>
                            <td>
                                <select name="actividad_economica_code" id="actividad_economica_code" class="regular-text">
                                    <option value="">Select activity</option>
                                    <?php foreach (($actividades_economicas ?? []) as $actividad) : ?>
                                        <option
                                            value="<?php echo esc_attr((string)$actividad['code']); ?>"
                                            data-desc="<?php echo esc_attr((string)$actividad['name']); ?>"
                                            <?php selected((string)($form_customer['actividad_economica_code'] ?? ''), (string)$actividad['code']); ?>
                                        >
                                            <?php echo esc_html((string)$actividad['code'] . ' - ' . (string)$actividad['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="actividad_economica_desc" id="actividad_economica_desc"
                                    value="<?php echo esc_attr((string)($form_customer['actividad_economica_desc'] ?? '')); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="departamento_code">Departamento</label></th>
                            <td>
                                <select name="departamento_code" id="departamento_code" class="regular-text">
                                    <option value="">Select department</option>
                                    <?php foreach (($departamentos ?? []) as $dep) : ?>
                                        <option value="<?php echo esc_attr((string)$dep['code']); ?>"
                                            <?php selected((string)($form_customer['departamento_code'] ?? ''), (string)$dep['code']); ?>>
                                            <?php echo esc_html((string)$dep['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="municipio_code">Municipio</label></th>
                            <td>
                                <select name="municipio_code" id="municipio_code" class="regular-text">
                                    <option value="">Select municipio</option>
                                    <?php foreach (($municipios ?? []) as $mun) : ?>
                                        <option value="<?php echo esc_attr((string)$mun['municipio_code']); ?>"
                                            data-departamento="<?php echo esc_attr((string)$mun['departamento_code']); ?>"
                                            <?php selected((string)($form_customer['municipio_code'] ?? ''), (string)$mun['municipio_code']); ?>>
                                            <?php echo esc_html((string)$mun['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="direccion_complemento">Dirección Complemento</label></th>
                            <td>
                                <textarea name="direccion_complemento" id="direccion_complemento" class="large-text" rows="3"><?php
                                    echo esc_textarea((string) ($form_customer['direccion_complemento'] ?? ''));
                                ?></textarea>
                                <p class="description">Use this field for district + detailed address.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="address">Legacy Address</label></th>
                            <td>
                                <textarea name="address" id="address" class="large-text" rows="2"><?php
                                    echo esc_textarea((string) ($form_customer['address'] ?? ''));
                                ?></textarea>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="city">Legacy City</label></th>
                            <td>
                                <input type="text" name="city" id="city" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['city'] ?? '')); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="phone">Phone</label></th>
                            <td>
                                <input type="text" name="phone" id="phone" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['phone'] ?? '')); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="email">Email</label></th>
                            <td>
                                <input type="email" name="email" id="email" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['email'] ?? '')); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="tipo_documento">Tipo Documento</label></th>
                            <td>
                                <input type="text" name="tipo_documento" id="tipo_documento" class="regular-text"
                                    value="<?php echo esc_attr((string) ($form_customer['tipo_documento'] ?? '')); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="wp_user_id">WP User ID</label></th>
                            <td>
                                <input type="number" name="wp_user_id" id="wp_user_id" class="small-text"
                                    value="<?php echo esc_attr((string) ($form_customer['wp_user_id'] ?? '')); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Active</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" value="1" <?php checked(!empty($form_customer['is_active'])); ?>>
                                    Customer is active
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Customer</button>
                    <a href="<?php echo esc_url(JC_Customers_Admin::page_url()); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>

        <script>
        (function() {
            var actividad = document.getElementById('actividad_economica_code');
            var actividadDesc = document.getElementById('actividad_economica_desc');
            if (actividad && actividadDesc) {
                var syncActividad = function() {
                    var opt = actividad.options[actividad.selectedIndex];
                    actividadDesc.value = opt ? (opt.getAttribute('data-desc') || '') : '';
                };
                actividad.addEventListener('change', syncActividad);
                syncActividad();
            }

            var dep = document.getElementById('departamento_code');
            var mun = document.getElementById('municipio_code');
            if (dep && mun) {
                var allOptions = [];
                for (var i = 0; i < mun.options.length; i++) {
                    allOptions.push({
                        value: mun.options[i].value,
                        text: mun.options[i].text,
                        dep: mun.options[i].getAttribute('data-departamento') || ''
                    });
                }

                var selectedMunicipio = mun.value;

                var filterMunicipios = function() {
                    var depValue = dep.value;
                    var currentValue = mun.value || selectedMunicipio;

                    mun.innerHTML = '';

                    var placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.text = 'Select municipio';
                    mun.appendChild(placeholder);

                    for (var j = 0; j < allOptions.length; j++) {
                        var item = allOptions[j];
                        if (item.value === '') {
                            continue;
                        }
                        if (depValue === '' || item.dep === depValue) {
                            var opt = document.createElement('option');
                            opt.value = item.value;
                            opt.text = item.text;
                            opt.setAttribute('data-departamento', item.dep);
                            if (item.value === currentValue) {
                                opt.selected = true;
                            }
                            mun.appendChild(opt);
                        }
                    }
                };

                dep.addEventListener('change', function() {
                    selectedMunicipio = '';
                    filterMunicipios();
                });

                filterMunicipios();
            }
        })();
        </script>
    <?php endif; ?>

    <?php if ($action === 'view' && !empty($customer)) : ?>
        <div class="postbox" style="max-width: 1000px; padding: 20px; margin-top: 20px;">
            <h2>
                <?php echo esc_html(JC_Customer_Service::build_customer_name($customer)); ?>
                <a href="<?php echo esc_url(JC_Customers_Admin::page_url([
                    'action'      => 'edit',
                    'customer_id' => (int) $customer['id'],
                ])); ?>" class="button" style="margin-left: 10px;">Edit</a>
            </h2>

            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr><td><strong>Company</strong></td><td><?php echo esc_html((string) ($customer['company'] ?? '')); ?></td></tr>
                    <tr><td><strong>Nombre Comercial</strong></td><td><?php echo esc_html((string) ($customer['nombre_comercial'] ?? '')); ?></td></tr>
                    <tr><td><strong>NRC</strong></td><td><?php echo esc_html((string) ($customer['nrc'] ?? '')); ?></td></tr>
                    <tr><td><strong>NIT</strong></td><td><?php echo esc_html((string) ($customer['nit'] ?? '')); ?></td></tr>
                    <tr><td><strong>Actividad Económica</strong></td><td><?php echo esc_html((string) ($customer['actividad_economica_code'] ?? '')); ?> - <?php echo esc_html((string) ($customer['actividad_economica_desc'] ?? '')); ?></td></tr>
                    <tr><td><strong>Departamento</strong></td><td><?php echo esc_html((string) ($customer['departamento_code'] ?? '')); ?></td></tr>
                    <tr><td><strong>Municipio</strong></td><td><?php echo esc_html((string) ($customer['municipio_code'] ?? '')); ?></td></tr>
                    <tr><td><strong>Dirección Complemento</strong></td><td><?php echo esc_html((string) ($customer['direccion_complemento'] ?? '')); ?></td></tr>
                    <tr><td><strong>Phone</strong></td><td><?php echo esc_html((string) ($customer['phone'] ?? '')); ?></td></tr>
                    <tr><td><strong>Email</strong></td><td><?php echo esc_html((string) ($customer['email'] ?? '')); ?></td></tr>
                </tbody>
            </table>

            <h3 style="margin-top: 25px;">Invoice History</h3>

            <?php if (empty($invoices_ready)) : ?>
                <p><em>The invoices table does not have the customer_id column yet.</em></p>
            <?php elseif (empty($customer_invoices)) : ?>
                <p><em>No invoices found for this customer.</em></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ticket</th>
                            <th>Document Type</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>MH Status</th>
                            <th>Store</th>
                            <th>Register</th>
                            <th>Issued At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customer_invoices as $invoice) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $invoice['id']); ?></td>
                                <td><?php echo esc_html((string) $invoice['ticket_number']); ?></td>
                                <td><?php echo esc_html((string) $invoice['document_type']); ?></td>
                                <td><?php echo esc_html(number_format((float) $invoice['total'], 2)); ?></td>
                                <td><?php echo esc_html((string) $invoice['status']); ?></td>
                                <td><?php echo esc_html((string) $invoice['mh_status']); ?></td>
                                <td><?php echo esc_html((string) ($invoice['store_name'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($invoice['register_name'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) $invoice['issued_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="postbox" style="max-width: 1200px; padding: 20px; margin-top: 20px;">
        <h2>Customer List</h2>

        <?php if (empty($customers)) : ?>
            <p><em>No customers found.</em></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Nombre Comercial</th>
                        <th>NRC</th>
                        <th>NIT</th>
                        <th>Actividad</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $row['id']); ?></td>
                            <td><?php echo esc_html(JC_Customer_Service::build_customer_name($row)); ?></td>
                            <td><?php echo esc_html((string) ($row['company'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['nombre_comercial'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['nrc'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['nit'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['actividad_economica_code'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['phone'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['email'] ?? '')); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(JC_Customers_Admin::page_url([
                                    'action'      => 'view',
                                    'customer_id' => (int) $row['id'],
                                ])); ?>">View</a>

                                <a class="button button-small" href="<?php echo esc_url(JC_Customers_Admin::page_url([
                                    'action'      => 'edit',
                                    'customer_id' => (int) $row['id'],
                                ])); ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>