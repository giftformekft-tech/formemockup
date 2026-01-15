<?php
/*
Plugin Name: Mockup Generator – FAST WebP SAFE
Description: WebP kimenet (alfa megőrzés), 100× bulk, szín × nézet mockup, és biztonságos hibakezelés (nincs fatal).
Version: 1.2.100
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
        'includes/class-bulk-queue.php',
        'includes/class-cart-name-cleaner.php',
        'includes/class-cart-pricing.php',
        'admin/class-admin-page.php',
        'admin/class-settings-page.php',
        'admin/class-product-settings-page.php',
        'admin/class-variant-display-page.php',
        'admin/upload-handler.php',
        'admin/bulk-handler.php',
        'admin/class-custom-fields-page.php',
        'admin/class-mockup-maintenance-page.php',
        'admin/class-surcharge-options-page.php',
        'includes/class-generator.php',
        'includes/class-product-creator.php',
        'includes/class-custom-fields-manager.php',
        'includes/class-custom-fields-frontend.php',
        'includes/class-size-selection.php',
        'includes/class-delivery-estimate.php',
        'includes/class-mockup-maintenance.php',
        'includes/class-variant-maintenance.php',
        'includes/class-variant-display-manager.php',
        'includes/class-product-image-performance.php',
        'includes/class-surcharge-manager.php',
        'includes/class-surcharge-frontend.php',
        'includes/class-design-gallery.php',
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
        if (class_exists('MG_Custom_Fields_Page')) {
            MG_Custom_Fields_Page::add_submenu_page();
        }
        if (class_exists('MG_Mockup_Maintenance_Page')) {
            MG_Mockup_Maintenance_Page::add_submenu_page();
        }
        if (class_exists('MG_Variant_Display_Page')) {
            MG_Variant_Display_Page::add_submenu_page();
        }
        if (class_exists('MG_Surcharge_Options_Page')) {
            MG_Surcharge_Options_Page::add_submenu_page();
        }
        });

    add_action('admin_enqueue_scripts', function($hook){
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab  = isset($_GET['mg_tab']) ? sanitize_key(wp_unslash($_GET['mg_tab'])) : '';
        $is_shell = ($hook === 'toplevel_page_mockup-generator') || ($page === 'mockup-generator');

        if (strpos($hook, 'mockup-generator') !== false || $is_shell) {
            wp_enqueue_style('mg-admin', plugins_url('assets/css/admin.css', __FILE__), [], '1.2.55');
            wp_enqueue_script('mg-admin', plugins_url('assets/js/admin.js', __FILE__), ['jquery'], '1.2.55', true);
            wp_localize_script('mg-admin', 'MG_AJAX', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mg_ajax_nonce')
            ));
        }

        $needs_maintenance_assets = ($hook === 'mockup-generator_page_mockup-generator-maintenance') || ($is_shell && $tab === 'regenerate');
        if ($needs_maintenance_assets) {
            $maintenance_css = plugin_dir_path(__FILE__) . 'assets/css/mockup-maintenance.css';
            $maintenance_js  = plugin_dir_path(__FILE__) . 'assets/js/mockup-maintenance.js';
            wp_enqueue_style(
                'mg-mockup-maintenance',
                plugins_url('assets/css/mockup-maintenance.css', __FILE__),
                [],
                file_exists($maintenance_css) ? filemtime($maintenance_css) : '1.0.0'
            );
            wp_enqueue_script(
                'mg-mockup-maintenance',
                plugins_url('assets/js/mockup-maintenance.js', __FILE__),
                [],
                file_exists($maintenance_js) ? filemtime($maintenance_js) : '1.0.0',
                true
            );
            wp_localize_script('mg-mockup-maintenance', 'MG_MOCKUP_MAINTENANCE', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mg_ajax_nonce'),
            ));
        }

        if ($is_shell && $tab === 'variants' && class_exists('MG_Variant_Display_Page')) {
            MG_Variant_Display_Page::enqueue_assets($hook);
        }
    });

    if (class_exists('MG_Custom_Fields_Frontend')) {
        MG_Custom_Fields_Frontend::init();
    }
    if (class_exists('MG_Size_Selection')) {
        MG_Size_Selection::init();
    }
    if (class_exists('MG_Delivery_Estimate')) {
        MG_Delivery_Estimate::init();
    }
    if (class_exists('MG_Cart_Name_Cleaner')) {
        MG_Cart_Name_Cleaner::init();
    }
    if (class_exists('MG_Cart_Pricing')) {
        MG_Cart_Pricing::init();
    }
    if (class_exists('MG_Mockup_Maintenance')) {
        MG_Mockup_Maintenance::init();
    }
    if (class_exists('MG_Variant_Maintenance')) {
        MG_Variant_Maintenance::init();
    }
    if (class_exists('MG_Variant_Display_Manager')) {
        MG_Variant_Display_Manager::init();
    }
    if (class_exists('MG_Product_Image_Performance')) {
        MG_Product_Image_Performance::init();
    }
    if (class_exists('MG_Surcharge_Frontend')) {
        MG_Surcharge_Frontend::init();
    }
    if (class_exists('MG_Design_Gallery')) {
        MG_Design_Gallery::init();
    }
}, 20);

if (class_exists('MG_Variant_Display_Page')) {
    add_action('admin_enqueue_scripts', array('MG_Variant_Display_Page', 'enqueue_assets'));
}
