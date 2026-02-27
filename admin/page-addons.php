<?php
if (!defined('ABSPATH')) exit;

function jc_pos_admin_addons_page() {
  global $wpdb;
  $table = $wpdb->prefix . 'jc_addons';

  if (!empty($_POST['jc_pos_action']) && $_POST['jc_pos_action'] === 'save_addon') {
    check_admin_referer('jc_pos_save_addon');

    $id = (int)($_POST['id'] ?? 0);
    $name = sanitize_text_field($_POST['name'] ?? '');
    $addon_type = sanitize_text_field($_POST['addon_type'] ?? 'TOPPING');
    $price = (float)($_POST['price'] ?? 0);
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active = !empty($_POST['is_active']) ? 1 : 0;

    $allowed = ['TOPPING','SYRUP','OTHER'];
    if ($name === '' || !in_array($addon_type, $allowed, true)) {
      jc_pos_notice('Invalid add-on data.', 'error');
    } else {
      $data = [
        'name' => $name,
        'addon_type' => $addon_type,
        'price' => $price,
        'is_active' => $is_active,
        'sort_order' => $sort_order,
      ];
      $formats = ['%s','%s','%f','%d','%d'];

      if ($id > 0) {
        $wpdb->update($table, $data, ['id'=>$id], $formats, ['%d']);
        jc_pos_notice('Add-on updated.');
      } else {
        $wpdb->insert($table, $data, $formats);
        jc_pos_notice('Add-on added.');
      }
    }
  }

  if (!empty($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    check_admin_referer('jc_pos_delete_addon_'.$id);
    $wpdb->delete($table, ['id'=>$id], ['%d']);
    jc_pos_notice('Add-on deleted.');
  }

  $edit = null;
  if (!empty($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", (int)$_GET['id']));
  }

  $filter = sanitize_text_field($_GET['type'] ?? '');
  $where = '';
  if (in_array($filter, ['TOPPING','SYRUP','OTHER'], true)) {
    $where = $wpdb->prepare("WHERE addon_type=%s", $filter);
  }

  $rows = $wpdb->get_results("SELECT * FROM $table $where ORDER BY addon_type ASC, sort_order ASC, id ASC");

  echo '<div class="wrap"><h1>Add-ons</h1>';

  $base = admin_url('admin.php?page=jc-pos-addons');
  echo '<p>
    <a class="button" href="'.esc_url($base).'">All</a>
    <a class="button" href="'.esc_url($base.'&type=TOPPING').'">Perlas</a>
    <a class="button" href="'.esc_url($base.'&type=SYRUP').'">Saborizantes</a>
    <a class="button" href="'.esc_url($base.'&type=OTHER').'">Other</a>
  </p>';

  echo '<h2>'.($edit ? 'Edit Add-on' : 'Add Add-on').'</h2>';
  echo '<form method="post">';
  wp_nonce_field('jc_pos_save_addon');
  echo '<input type="hidden" name="jc_pos_action" value="save_addon" />';
  echo '<input type="hidden" name="id" value="'.esc_attr($edit->id ?? 0).'" />';

  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label>Name</label></th><td><input type="text" name="name" class="regular-text" required value="'.esc_attr($edit->name ?? '').'"></td></tr>';

  $type = $edit->addon_type ?? 'TOPPING';
  echo '<tr><th><label>Type</label></th><td>
    <select name="addon_type">
      <option value="TOPPING" '.selected($type,'TOPPING',false).'>TOPPING (Perlas)</option>
      <option value="SYRUP" '.selected($type,'SYRUP',false).'>SYRUP (Saborizante)</option>
      <option value="OTHER" '.selected($type,'OTHER',false).'>OTHER</option>
    </select>
  </td></tr>';

  echo '<tr><th><label>Price</label></th><td><input type="number" step="0.01" name="price" value="'.esc_attr($edit->price ?? '0.00').'"></td></tr>';
  echo '<tr><th><label>Sort order</label></th><td><input type="number" name="sort_order" value="'.esc_attr($edit->sort_order ?? 0).'"></td></tr>';

  $checkedActive = (!isset($edit->is_active) || (int)$edit->is_active === 1) ? 'checked' : '';
  echo '<tr><th><label>Active</label></th><td><label><input type="checkbox" name="is_active" value="1" '.$checkedActive.'> Is active</label></td></tr>';
  echo '</tbody></table>';

  submit_button($edit ? 'Update Add-on' : 'Add Add-on');
  echo '</form>';

  echo '<hr><h2>All Add-ons</h2>';
  echo '<table class="widefat striped"><thead><tr>
    <th>ID</th><th>Name</th><th>Type</th><th>Price</th><th>Active</th><th>Sort</th><th>Actions</th>
  </tr></thead><tbody>';

  foreach ($rows as $r) {
    $editUrl = admin_url('admin.php?page=jc-pos-addons&action=edit&id='.$r->id);
    $delUrl  = wp_nonce_url(admin_url('admin.php?page=jc-pos-addons&action=delete&id='.$r->id), 'jc_pos_delete_addon_'.$r->id);

    echo '<tr>
      <td>'.(int)$r->id.'</td>
      <td>'.esc_html($r->name).'</td>
      <td>'.esc_html($r->addon_type).'</td>
      <td>$'.number_format((float)$r->price, 2).'</td>
      <td>'.(((int)$r->is_active===1)?'✅':'').'</td>
      <td>'.(int)$r->sort_order.'</td>
      <td>
        <a class="button button-small" href="'.esc_url($editUrl).'">Edit</a>
        <a class="button button-small button-link-delete" href="'.esc_url($delUrl).'" onclick="return confirm(\'Delete this add-on?\')">Delete</a>
      </td>
    </tr>';
  }

  echo '</tbody></table></div>';
}