<?php
/**
 * Single Product Title - Custom Template for Variant Titles
 *
 * This template is located at: astra-child/woocommerce/single-product/title.php
 * 
 * Displays product title with variant type label when ?mg_type URL parameter is present.
 * Falls back to default product title otherwise.
 *
 * @package Astra Child
 */

if (!defined('ABSPATH')) {
    exit;
}

global $product;

// Get base product title
$product_title = get_the_title();

// Check if variant type is specified in URL
if (isset($_GET['mg_type']) && class_exists('MG_Virtual_Variant_Manager')) {
    $requested_type = sanitize_text_field($_GET['mg_type']);
    $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
    
    // If valid variant configuration exists
    if (!empty($config) && !empty($config['types']) && isset($config['types'][$requested_type])) {
        $type_data = $config['types'][$requested_type];
        $type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;
        
        // Append type label to title
        $product_title .= ' - ' . $type_label;
    }
}

// Output title
the_title('<h1 class="product_title entry-title">', '</h1>');

// Also update document title for SEO
if (isset($_GET['mg_type']) && !empty($type_label)) {
    add_filter('document_title_parts', function($parts) use ($type_label) {
        if (isset($parts['title']) && strpos($parts['title'], $type_label) === false) {
            $parts['title'] .= ' - ' . $type_label;
        }
        return $parts;
    }, 999);
}
