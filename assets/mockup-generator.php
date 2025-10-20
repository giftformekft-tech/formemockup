<?php
/*
Plugin Name: Mockup Generator – FAST WebP SAFE
Description: WebP kimenet (alfa megőrzés), 3× bulk, szín × nézet mockup, és biztonságos hibakezelés (nincs fatal).
Version: 1.2.4
Author: Shannon
*/
require_once __DIR__ . '/includes/type-description-applier.php';

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function(){
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><strong>Mockup Generator – FAST WebP SAFE:</strong> A WooCommerce nincs aktiválva.</p></div>';
        });
        return;
    }
    // SAFE includes
    $files = [
        'admin/class-admin-page.php',
        'admin/class-settings-page.php',
        'admin/class-product-settings-page.php',
        'admin/upload-handler.php',
        'admin/bulk-handler.php',
        'includes/class-generator.php',
        'includes/class-product-creator.php',
    ];
    foreach ($files as $rel) {
        $abs = plugin_dir_path(__FILE__) . $rel;
        if (!file_exists($abs)) {
            add_action('admin_notices', function() use ($rel){
                echo '<div class="notice notice-error"><p><strong>Mockup Generator – SAFE:</strong> Hiányzó fájl: '.esc_html($rel).'</p></div>';
            });
            return;
        }
        require_once $abs;
    }

    add_action('admin_menu', function() {
        MG_Admin_Page::add_menu_page();
        MG_Settings_Page::add_submenu_page();
        MG_Product_Settings_Page::register_dynamic_product_submenus();
    });

    add_action('admin_enqueue_scripts', function($hook){
        if (strpos($hook, 'mockup-generator') !== false) {
            wp_enqueue_style('mg-admin', plugins_url('assets/css/admin.css', __FILE__), [], '1.2.4');
            wp_enqueue_script('mg-admin', plugins_url('assets/js/admin.js', __FILE__), ['jquery'], '1.2.4', true);
            wp_localize_script('mg-admin', 'MG_AJAX', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mg_ajax_nonce')
            ));
        }
    });
}, 20);
