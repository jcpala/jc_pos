<?php
if (!defined('ABSPATH')) exit;

function jc_pos_admin_sizes_page() {
  global $wpdb;
  $table = $wpdb->prefix . 'jc_size_rules';

  if (!empty($_POST['jc_pos_action']) && $_POST['jc_pos_action'] === 'save_size') {
    check_admin_referer('jc_pos_save_size');

    $id = (int)($_POST['id'] ?? 0);
    $label = sanitize_text_field($_POST['label'] ?? '');
    $price_delta = (float)($_POST['price_delta'] ?? 0);
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $is_default = !empty($_POST['is_default']) ? 1 : 0;

    if ($label === '') {
      jc_pos_notice('Label is required.', 'error');
    } else {
      if ($is_default) {
        $wpdb->query("UPDATE $table SET is_default=0");
      }

      $data = [
        'label' => $label,
        'price_delta' => $price_delta,
        'is_default' => $is_default,
        'is_active' => $is_active,
        'sort_order' => $sort_order,
      ];
      $formats = ['%s','%f','%d','%d','%d'];

      if ($id > 0) {
        $wpdb->update($table, $data, ['id'=>$id], $formats, ['%d']);
        jc_pos_notice('Size updated.');
      } else {
        $wpdb->insert($table, $data, $formats);
        jc_pos_notice('Size added.');
      }
    }
  }

  if (!empty($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    check_admin_referer('jc_pos_delete_size_'.$id);
    $wpdb->delete($table, ['id'=>$id], ['%d']);
    jc_pos_notice('Size deleted.');
  }

  $edit = null;
  if (!empty($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", (int)$_GET['id']));
  }

  $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC, id ASC");

  echo '<div class="wrap"><h1>Sizes</h1>';

  echo '<h2>'.($edit ? 'Edit Size' : 'Add Size').'</h2>';
  echo '<form method="post">';
  wp_nonce_field('jc_pos_save_size');
  echo '<input type="hidden" name="jc_pos_action" value="save_size" />';
  echo '<input type="hidden" name="id" value="'.esc_attr($edit->id ?? 0).'" />';

  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label>Label</label></th><td><input type="text" name="label" class="regular-text" required value="'.esc_attr($edit->label ?? '').'"></td></tr>';
  echo '<tr><th><label>Price delta</label></th><td><input type="number" step="0.01" name="price_delta" value="'.esc_attr($edit->price_delta ?? '0.00').'"></td></tr>';
  echo '<tr><th><label>Sort order</label></th><td><input type="number" name="sort_order" value="'.esc_attr($edit->sort_order ?? 0).'"></td></tr>';

  $checkedActive = (!isset($edit->is_active) || (int)$edit->is_active === 1) ? 'checked' : '';
  $checkedDefault= (isset($edit->is_default) && (int)$edit->is_default === 1) ? 'checked' : '';

  echo '<tr><th><label>Active</label></th><td><label><input type="checkbox" name="is_active" value="1" '.$checkedActive.'> Is active</label></td></tr>';
  echo '<tr><th><label>Default</label></th><td><label><input type="checkbox" name="is_default" value="1" '.$checkedDefault.'> Default size</label></td></tr>';
  echo '</tbody></table>';

  submit_button($edit ? 'Update Size' : 'Add Size');
  echo '</form>';

  echo '<hr><h2>All Sizes</h2>';
  echo '<table class="widefat striped"><thead><tr>
    <th>ID</th><th>Label</th><th>Delta</th><th>Default</th><th>Active</th><th>Sort</th><th>Actions</th>
  </tr></thead><tbody>';

  foreach ($rows as $r) {
    $editUrl = admin_url('admin.php?page=jc-pos&action=edit&id='.$r->id);
    $delUrl  = wp_nonce_url(admin_url('admin.php?page=jc-pos&action=delete&id='.$r->id), 'jc_pos_delete_size_'.$r->id);

    echo '<tr>
      <td>'.(int)$r->id.'</td>
      <td>'.esc_html($r->label).'</td>
      <td>$'.number_format((float)$r->price_delta, 2).'</td>
      <td>'.(((int)$r->is_default===1)?'✅':'').'</td>
      <td>'.(((int)$r->is_active===1)?'✅':'').'</td>
      <td>'.(int)$r->sort_order.'</td>
      <td>
        <a class="button button-small" href="'.esc_url($editUrl).'">Edit</a>
        <a class="button button-small button-link-delete" href="'.esc_url($delUrl).'" onclick="return confirm(\'Delete this size?\')">Delete</a>
      </td>
    </tr>';
  }

  echo '</tbody></table></div>';
}