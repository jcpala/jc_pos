<?php
if (!defined('ABSPATH')) exit;

class JC_WC_Register_Fields {

  public static function init() {
    add_action('woocommerce_product_options_general_product_data', [self::class, 'fields']);
    add_action('woocommerce_admin_process_product_object', [self::class, 'save']);
  }

  public static function fields() {
    global $wpdb;
    $t_menus = $wpdb->prefix . 'jc_pos_menus';

    $menus = $wpdb->get_results("SELECT id, name FROM $t_menus WHERE is_active=1 ORDER BY sort_order ASC, id ASC", ARRAY_A);

    echo '<div class="options_group">';

    // Checkbox
    woocommerce_wp_checkbox([
      'id'          => '_jc_pos_show_in_register',
      'label'       => 'Show in Register',
      'description' => 'If checked, this product can be sold in the POS Register.',
    ]);

    // Dropdown
    echo '<p class="form-field _jc_pos_menu_id_field">';
    echo '<label for="_jc_pos_menu_id">Register Menu</label>';
    echo '<select id="_jc_pos_menu_id" name="_jc_pos_menu_id">';
    echo '<option value="">— Select menu —</option>';
    foreach ($menus as $m) {
      $val = (string)$m['id'];
      echo '<option value="'.esc_attr($val).'">'.esc_html($m['name']).'</option>';
    }
    echo '</select>';
    echo '<span class="description">Which menu card this product appears under.</span>';
    echo '</p>';

    echo '</div>';
  }

  public static function save($product) {

    $show = isset($_POST['_jc_pos_show_in_register']) ? 'yes' : 'no';
    $menu_id = isset($_POST['_jc_pos_menu_id']) ? (int)$_POST['_jc_pos_menu_id'] : 0;

    // Save product meta
    $product->update_meta_data('_jc_pos_show_in_register', $show);
    $product->update_meta_data('_jc_pos_menu_id', $menu_id > 0 ? $menu_id : '');

    // ---- Sync POS mapping table ----
    global $wpdb;
    $t_map = $wpdb->prefix . 'jc_pos_menu_products';
    $product_id = $product->get_id();

    // Remove existing mappings for this product
    $wpdb->delete($t_map, ['product_id' => $product_id], ['%d']);

    // Add new mapping if enabled
    if ($show === 'yes' && $menu_id > 0) {
        $wpdb->query($wpdb->prepare("
            INSERT INTO $t_map (menu_id, product_id, sort_order)
            VALUES (%d, %d, 0)
        ", $menu_id, $product_id));
    }
}

}