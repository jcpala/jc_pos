<?php
if (!defined('ABSPATH')) exit;

// Load page implementations
require_once __DIR__ . '/page-sizes.php';
require_once __DIR__ . '/page-addons.php';
require_once __DIR__ . '/page-menus.php';
require_once __DIR__ . '/page-invoices.php';
require_once __DIR__ . '/page-register-sessions.php';



add_action('admin_menu', function () {
  add_menu_page(
    'JC POS', 'JC POS', 'manage_options',
    'jc-pos', 'jc_pos_admin_sizes_page',
    'dashicons-store', 56
  );

  add_submenu_page('jc-pos', 'Sizes', 'Sizes', 'manage_options', 'jc-pos',        'jc_pos_admin_sizes_page');
  add_submenu_page('jc-pos', 'Add-ons','Add-ons','manage_options','jc-pos-addons','jc_pos_admin_addons_page');
  add_submenu_page('jc-pos', 'Menus',  'Menus',  'manage_options','jc-pos-menus', 'jc_pos_admin_menus_page');
  add_submenu_page('jc-pos', 'Invoices', 'Invoices', 'manage_options', 'jc-pos-invoices', 'jc_pos_admin_invoices_page');
  add_submenu_page('jc-pos', 'Register Sessions', 'Register Sessions', 'manage_options', 'jc-pos-sessions', 'jc_pos_admin_sessions_page');
//   add_submenu_page(
//     'jc-correlativos',      // parent slug
//     'Invoices',
//     'Invoices',
//     'manage_options',       // capability
//     'jc-invoices',          // menu slug (string, NOT a URL)
//     [self::class, 'page_invoices']
// );




});

// Hide WP notices only on our pages
add_action('admin_head', function () {
  $screen = function_exists('get_current_screen') ? get_current_screen() : null;
  if (!$screen) return;
  if (strpos($screen->id, 'jc-pos') === false) return;

  echo '<style>
    .notice, .update-nag, .updated, .error, .is-dismissible { display:none !important; }
  </style>';
});

function jc_pos_notice($msg, $type='success') {
  echo '<div class="notice notice-'.esc_attr($type).'"><p>'.esc_html($msg).'</p></div>';
}