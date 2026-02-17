<?php
/**
 * Custom WooCommerce price template
 * Shows variant price when ?mg_type parameter is present
 * 
 * This template is located at: formemockup/woocommerce/single-product/price.php
 */

if (!defined('ABSPATH')) {
    exit;
}

global $product;

// Get variant price if mg_type is set
$display_price = $product->get_price();
$price_html = '';

if (isset($_GET['mg_type']) && class_exists('MG_Virtual_Variant_Manager')) {
    $requested_type = sanitize_text_field($_GET['mg_type']);
    $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
    
    if (!empty($config) && !empty($config['types']) && isset($config['types'][$requested_type])) {
        $type_data = $config['types'][$requested_type];
        
        // Use variant price if available
        if (isset($type_data['price']) && $type_data['price'] > 0) {
            $display_price = (float) $type_data['price'];
        }
    }
}

// Generate price HTML with correct price
if ($display_price) {
    $currency_symbol = get_woocommerce_currency_symbol();
    $formatted_price = number_format($display_price, 0, '', ' ');
    
    $price_html = '<p class="price">';
    $price_html .= '<span class="woocommerce-Price-amount amount">';
    $price_html .= '<bdi>' . $formatted_price . '&nbsp;';
    $price_html .= '<span class="woocommerce-Price-currencySymbol">' . $currency_symbol . '</span>';
    $price_html .= '</bdi></span>';
    $price_html .= '</p>';
}

echo $price_html;
