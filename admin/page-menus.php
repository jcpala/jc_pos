<?php
if (!defined('ABSPATH')) exit;

add_action('admin_head', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return;
    if (strpos($screen->id, 'jc-pos') === false) return;
  
    echo '<style>
      .notice, .update-nag, .updated, .error, .is-dismissible { display:none !important; }
    </style>';
  });

function jc_pos_get_product_cats_for_dropdown() {
  $terms = get_terms([
    'taxonomy' => 'product_cat',
    'hide_empty' => false,
  ]);
  if (is_wp_error($terms)) return [];
  return $terms;
}

function jc_pos_admin_menus_page() {
  // Woo required
  if (!function_exists('wc_get_product')) {
    echo '<div class="wrap"><h1>Menus</h1><p><strong>WooCommerce is not active.</strong></p></div>';
    return;
  }

  global $wpdb;
  $t_menus = $wpdb->prefix.'jc_pos_menus';
  $t_map   = $wpdb->prefix.'jc_pos_menu_products';

  $sub = sanitize_text_field($_GET['sub'] ?? '');
  if ($sub === 'products' && !empty($_GET['menu_id'])) {
    jc_pos_admin_menu_products_page((int)$_GET['menu_id']);
    return;
  }

  if (!empty($_POST['jc_pos_action']) && $_POST['jc_pos_action'] === 'save_menu') {
    check_admin_referer('jc_pos_save_menu');

    $id = (int)($_POST['id'] ?? 0);
    $name = sanitize_text_field($_POST['name'] ?? '');
    $wc_category_id = (int)($_POST['wc_category_id'] ?? 0);
    $image_id = (isset($_POST['image_id']) && $_POST['image_id'] !== '') ? (int)$_POST['image_id'] : null;
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $sort_order = (int)($_POST['sort_order'] ?? 0);

    if ($name === '' || $wc_category_id <= 0) {
      jc_pos_notice('Name and Woo category are required.', 'error');
    } else {
      $data = [
        'name' => $name,
        'wc_category_id' => $wc_category_id,
        'image_id' => $image_id,
        'is_active' => $is_active,
        'sort_order' => $sort_order,
      ];
      $formats = ['%s','%d','%d','%d','%d'];

      if ($id > 0) {
        $wpdb->update($t_menus, $data, ['id'=>$id], $formats, ['%d']);
        jc_pos_notice('Menu updated.');
      } else {
        $wpdb->insert($t_menus, $data, $formats);
        jc_pos_notice('Menu added.');
      }
    }
  }

  if (!empty($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    check_admin_referer('jc_pos_delete_menu_'.$id);
    $wpdb->delete($t_menus, ['id'=>$id], ['%d']);
    jc_pos_notice('Menu deleted.');
  }

  $edit = null;
  if (!empty($_GET['action']) && $_GET['action'] === 'edit' && !empty($_GET['id'])) {
    $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_menus WHERE id=%d", (int)$_GET['id']));
  }

  $rows = $wpdb->get_results("SELECT * FROM $t_menus ORDER BY sort_order ASC, id ASC");
  $cats = jc_pos_get_product_cats_for_dropdown();

  echo '<div class="wrap"><h1>Menus (Cards)</h1>';

  echo '<h2>'.($edit ? 'Edit Menu Card' : 'Add Menu Card').'</h2>';
  echo '<form method="post">';
  wp_nonce_field('jc_pos_save_menu');
  echo '<input type="hidden" name="jc_pos_action" value="save_menu" />';
  echo '<input type="hidden" name="id" value="'.esc_attr($edit->id ?? 0).'" />';

  $img_id  = $edit->image_id ?? '';
  $img_url = $img_id ? wp_get_attachment_image_url((int)$img_id, 'thumbnail') : '';

  echo '<table class="form-table"><tbody>';

  echo '<tr><th><label>Name</label></th><td><input type="text" name="name" class="regular-text" required value="'.esc_attr($edit->name ?? '').'"></td></tr>';

  echo '<tr><th><label>Woo Category</label></th><td><select name="wc_category_id" required>';
  echo '<option value="">Select category…</option>';
  $selected_cat = (int)($edit->wc_category_id ?? 0);
  foreach ($cats as $term) {
    echo '<option value="'.(int)$term->term_id.'" '.selected($selected_cat, (int)$term->term_id, false).'>'.esc_html($term->name).'</option>';
  }
  echo '</select><p class="description">Used for grouping/labeling only. POS products come from explicit mapping.</p></td></tr>';

  echo '<tr><th><label>Image</label></th><td>
    <input type="hidden" id="jc_pos_menu_image_id" name="image_id" value="'.esc_attr($img_id).'">
    <div id="jc_pos_menu_image_preview" style="margin-bottom:8px;">'
      .($img_url ? '<img src="'.esc_url($img_url).'" style="max-width:120px;height:auto;border:1px solid #ddd;padding:4px;background:#fff;" />' : '<em>No image selected</em>')
    .'</div>
    <button type="button" class="button" id="jc_pos_pick_image">Choose image</button>
    <button type="button" class="button" id="jc_pos_clear_image">Clear</button>
  </td></tr>';

  $checkedActive = (!isset($edit->is_active) || (int)$edit->is_active === 1) ? 'checked' : '';
  echo '<tr><th><label>Active</label></th><td><label><input type="checkbox" name="is_active" value="1" '.$checkedActive.'> Is active</label></td></tr>';

  echo '<tr><th><label>Sort order</label></th><td><input type="number" name="sort_order" value="'.esc_attr($edit->sort_order ?? 0).'"></td></tr>';

  echo '</tbody></table>';

  submit_button($edit ? 'Update Menu' : 'Add Menu');
  echo '</form>';

  echo '<hr><h2>All Menu Cards</h2>';
  echo '<table class="widefat striped"><thead><tr>
    <th>ID</th><th>Name</th><th>Category</th><th>Image</th><th>Active</th><th>Sort</th><th>Products</th><th>Actions</th>
  </tr></thead><tbody>';

  foreach ($rows as $r) {
    $cat_name = '';
    $term = get_term((int)$r->wc_category_id, 'product_cat');
    if ($term && !is_wp_error($term)) $cat_name = $term->name;

    $thumb = '';
    if (!empty($r->image_id)) {
      $u = wp_get_attachment_image_url((int)$r->image_id, 'thumbnail');
      if ($u) $thumb = '<img src="'.esc_url($u).'" style="width:50px;height:auto;border:1px solid #ddd;background:#fff;padding:2px;" />';
    }

    $manageUrl = admin_url('admin.php?page=jc-pos-menus&sub=products&menu_id='.$r->id);
    $editUrl   = admin_url('admin.php?page=jc-pos-menus&action=edit&id='.$r->id);
    $delUrl    = wp_nonce_url(admin_url('admin.php?page=jc-pos-menus&action=delete&id='.$r->id), 'jc_pos_delete_menu_'.$r->id);

    $cnt = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_map WHERE menu_id=%d", (int)$r->id));

    echo '<tr>
      <td>'.(int)$r->id.'</td>
      <td>'.esc_html($r->name).'</td>
      <td>'.esc_html($cat_name).'</td>
      <td>'.$thumb.'</td>
      <td>'.(((int)$r->is_active===1)?'✅':'').'</td>
      <td>'.(int)$r->sort_order.'</td>
      <td><a class="button button-small" href="'.esc_url($manageUrl).'">Manage ('.$cnt.')</a></td>
      <td>
        <a class="button button-small" href="'.esc_url($editUrl).'">Edit</a>
        <a class="button button-small button-link-delete" href="'.esc_url($delUrl).'" onclick="return confirm(\'Delete this menu card? (It will also remove its product mapping.)\')">Delete</a>
      </td>
    </tr>';
  }

  echo '</tbody></table></div>';

  jc_pos_admin_media_picker_js();
}

function jc_pos_admin_media_picker_js() {
  wp_enqueue_media();
  echo "<script>
  (function($){
    let frame;
    $('#jc_pos_pick_image').on('click', function(e){
      e.preventDefault();
      if (frame) { frame.open(); return; }
      frame = wp.media({ title: 'Select image', button: { text: 'Use this image' }, multiple: false });
      frame.on('select', function(){
        const attachment = frame.state().get('selection').first().toJSON();
        $('#jc_pos_menu_image_id').val(attachment.id);
        const url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
        $('#jc_pos_menu_image_preview').html('<img src=\"'+url+'\" style=\"max-width:120px;height:auto;border:1px solid #ddd;padding:4px;background:#fff;\" />');
      });
      frame.open();
    });
    $('#jc_pos_clear_image').on('click', function(){
      $('#jc_pos_menu_image_id').val('');
      $('#jc_pos_menu_image_preview').html('<em>No image selected</em>');
    });
  })(jQuery);
  </script>";
}

function jc_pos_admin_menu_products_page(int $menu_id) {
  global $wpdb;
  $t_menus = $wpdb->prefix.'jc_pos_menus';
  $t_map   = $wpdb->prefix.'jc_pos_menu_products';

  $menu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_menus WHERE id=%d", $menu_id));
  if (!$menu) {
    echo '<div class="wrap"><h1>Menu not found</h1></div>';
    return;
  }

  if (!empty($_POST['jc_pos_action']) && $_POST['jc_pos_action']==='add_menu_product') {
    check_admin_referer('jc_pos_add_menu_product');

    $product_id = (int)($_POST['product_id'] ?? 0);
    if ($product_id <= 0 || !wc_get_product($product_id)) {
      jc_pos_notice('Invalid product.', 'error');
    } else {
      $max = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort_order),0) FROM $t_map WHERE menu_id=%d", $menu_id));
      $sort = $max + 10;
      $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t_map (menu_id, product_id, sort_order) VALUES (%d, %d, %d)", $menu_id, $product_id, $sort));
      jc_pos_notice('Product added to menu.');
    }
  }

  if (!empty($_GET['action']) && $_GET['action']==='remove' && !empty($_GET['product_id'])) {
    $pid = (int)$_GET['product_id'];
    check_admin_referer('jc_pos_remove_menu_product_'.$menu_id.'_'.$pid);
    $wpdb->delete($t_map, ['menu_id'=>$menu_id, 'product_id'=>$pid], ['%d','%d']);
    jc_pos_notice('Product removed.');
  }

  if (!empty($_GET['action']) && in_array($_GET['action'], ['up','down'], true) && !empty($_GET['product_id'])) {
    $pid = (int)$_GET['product_id'];
    check_admin_referer('jc_pos_reorder_'.$menu_id.'_'.$pid);

    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT product_id, sort_order
      FROM $t_map
      WHERE menu_id=%d
      ORDER BY sort_order ASC, product_id ASC
    ", $menu_id), ARRAY_A);

    $idx = -1;
    for ($i=0; $i<count($rows); $i++) {
      if ((int)$rows[$i]['product_id'] === $pid) { $idx = $i; break; }
    }
    $swapIdx = ($_GET['action']==='up') ? $idx-1 : $idx+1;
    if ($idx >= 0 && $swapIdx >= 0 && $swapIdx < count($rows)) {
      $a = $rows[$idx]; $b = $rows[$swapIdx];
      $wpdb->update($t_map, ['sort_order'=>(int)$b['sort_order']], ['menu_id'=>$menu_id,'product_id'=>(int)$a['product_id']], ['%d'], ['%d','%d']);
      $wpdb->update($t_map, ['sort_order'=>(int)$a['sort_order']], ['menu_id'=>$menu_id,'product_id'=>(int)$b['product_id']], ['%d'], ['%d','%d']);
      jc_pos_notice('Order updated.');
    }
  }

  $q = sanitize_text_field($_GET['q'] ?? '');
  $found = [];
  if ($q !== '') {
    $found_ids = wc_get_products(['status'=>'publish','limit'=>20,'s'=>$q,'return'=>'ids']);
    foreach ($found_ids as $pid) {
      $p = wc_get_product($pid);
      if ($p) $found[] = ['id'=>$pid, 'name'=>$p->get_name(), 'price'=>$p->get_price()];
    }
  }

  $mapped = $wpdb->get_results($wpdb->prepare("
    SELECT product_id, sort_order
    FROM $t_map
    WHERE menu_id=%d
    ORDER BY sort_order ASC, product_id ASC
  ", $menu_id), ARRAY_A);

  echo '<div class="wrap">';
  echo '<h1>Manage Products — '.esc_html($menu->name).'</h1>';
  echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=jc-pos-menus')).'">← Back to Menus</a></p>';

  echo '<h2>Add product</h2>';
  echo '<form method="get" style="margin-bottom:10px;">';
  echo '<input type="hidden" name="page" value="jc-pos-menus" />';
  echo '<input type="hidden" name="sub" value="products" />';
  echo '<input type="hidden" name="menu_id" value="'.(int)$menu_id.'" />';
  echo '<input type="text" name="q" value="'.esc_attr($q).'" placeholder="Search Woo products…" class="regular-text" />';
  submit_button('Search', 'secondary', '', false);
  echo '</form>';

  if ($q !== '') {
    echo '<table class="widefat striped"><thead><tr><th>Product</th><th>Price</th><th></th></tr></thead><tbody>';
    if (!$found) {
      echo '<tr><td colspan="3"><em>No products found.</em></td></tr>';
    } else {
      foreach ($found as $p) {
        echo '<tr>
          <td>'.esc_html($p['name']).' <code>#'.(int)$p['id'].'</code></td>
          <td>$'.number_format((float)$p['price'], 2).'</td>
          <td>
            <form method="post" style="margin:0;">
              '.wp_nonce_field('jc_pos_add_menu_product', '_wpnonce', true, false).'
              <input type="hidden" name="jc_pos_action" value="add_menu_product" />
              <input type="hidden" name="product_id" value="'.(int)$p['id'].'" />
              <button class="button button-small">Add to menu</button>
            </form>
          </td>
        </tr>';
      }
    }
    echo '</tbody></table>';
  }

  echo '<hr><h2>Products in this menu</h2>';
  echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Product</th><th>Price</th><th>Actions</th></tr></thead><tbody>';

  if (!$mapped) {
    echo '<tr><td colspan="4"><em>No products mapped yet.</em></td></tr>';
  } else {
    foreach ($mapped as $m) {
      $pid = (int)$m['product_id'];
      $prod = wc_get_product($pid);
      if (!$prod) continue;

      $upUrl   = wp_nonce_url(admin_url('admin.php?page=jc-pos-menus&sub=products&menu_id='.$menu_id.'&action=up&product_id='.$pid), 'jc_pos_reorder_'.$menu_id.'_'.$pid);
      $downUrl = wp_nonce_url(admin_url('admin.php?page=jc-pos-menus&sub=products&menu_id='.$menu_id.'&action=down&product_id='.$pid), 'jc_pos_reorder_'.$menu_id.'_'.$pid);
      $rmUrl   = wp_nonce_url(admin_url('admin.php?page=jc-pos-menus&sub=products&menu_id='.$menu_id.'&action=remove&product_id='.$pid), 'jc_pos_remove_menu_product_'.$menu_id.'_'.$pid);

      echo '<tr>
        <td>'.(int)$m['sort_order'].'</td>
        <td>'.esc_html($prod->get_name()).' <code>#'.$pid.'</code></td>
        <td>$'.number_format((float)$prod->get_price(), 2).'</td>
        <td>
          <a class="button button-small" href="'.esc_url($upUrl).'">↑</a>
          <a class="button button-small" href="'.esc_url($downUrl).'">↓</a>
          <a class="button button-small button-link-delete" href="'.esc_url($rmUrl).'" onclick="return confirm(\'Remove this product from menu?\')">Remove</a>
        </td>
      </tr>';
    }
  }

  echo '</tbody></table></div>';
}