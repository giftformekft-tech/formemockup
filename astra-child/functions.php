<?php
/**
 * Astra Child Theme functions
 *
 * @package Astra Child
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Enqueue parent and child theme styles
 */
function astra_child_enqueue_styles() {
    // Enqueue parent Astra theme stylesheet
    wp_enqueue_style('astra-parent-style', get_template_directory_uri() . '/style.css');
    
    // Enqueue child theme stylesheet
    wp_enqueue_style(
        'astra-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array('astra-parent-style'),
        wp_get_theme()->get('Version')
    );
}
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles', 15);

/**
 * Override WooCommerce price display for variant products
 */
function astra_child_custom_price_display() {
    // Only on single product pages with mg_type parameter
    if (!is_product() || !isset($_GET['mg_type'])) {
        return;
    }
    
    if (!class_exists('MG_Virtual_Variant_Manager')) {
        return;
    }
    
    global $product;
    if (!$product) {
        return;
    }
    
    $requested_type = sanitize_text_field($_GET['mg_type']);
    $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
    
    if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
        return;
    }
    
    // Remove default WooCommerce price hooks
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
    
    // Add our custom price display at the same position
    add_action('woocommerce_single_product_summary', 'astra_child_display_variant_price', 10);
}
add_action('woocommerce_before_single_product', 'astra_child_custom_price_display', 1);

/**
 * Display variant price
 */
function astra_child_display_variant_price() {
    if (!isset($_GET['mg_type']) || !class_exists('MG_Virtual_Variant_Manager')) {
        return;
    }
    
    global $product;
    $requested_type = sanitize_text_field($_GET['mg_type']);
    $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
    
    if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
        return;
    }
    
    $type_data = $config['types'][$requested_type];
    
    // Get variant price
    $variant_price = isset($type_data['price']) && $type_data['price'] > 0 
        ? (float) $type_data['price'] 
        : (float) $product->get_price();
    
    // Format and display
    $currency_symbol = get_woocommerce_currency_symbol();
    $formatted_price = number_format($variant_price, 0, '', ' ');
    
    echo '<p class="price">';
    echo '<span class="woocommerce-Price-amount amount">';
    echo '<bdi>' . $formatted_price . '&nbsp;<span class="woocommerce-Price-currencySymbol">' . $currency_symbol . '</span></bdi>';
    echo '</span>';
    echo '</p>';
}

/**
 * Override product title for variants
 */
function astra_child_variant_title($title, $id = null) {
    if (!is_product() || !in_the_loop() || !is_main_query()) {
        return $title;
    }
    
    if (!isset($_GET['mg_type']) || !class_exists('MG_Virtual_Variant_Manager')) {
        return $title;
    }
    
    global $product;
    if (!$product) {
        return $title;
    }
    
    $requested_type = sanitize_text_field($_GET['mg_type']);
    $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
    
    if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
        return $title;
    }
    
    $type_data = $config['types'][$requested_type];
    $type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;
    
    if (strpos($title, ' - ' . $type_label) === false) {
        return $title . ' - ' . $type_label;
    }
    
    return $title;
}
add_filter('the_title', 'astra_child_variant_title', 10, 2);

/**
 * Theme setup
 */
function astra_child_theme_setup() {
    // Add WooCommerce support if not already present
    if (!current_theme_supports('woocommerce')) {
        add_theme_support('woocommerce');
    }
}
add_action('after_setup_theme', 'astra_child_theme_setup');
