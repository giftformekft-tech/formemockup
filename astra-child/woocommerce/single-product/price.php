<?php
/**
 * Single Product Price - Custom Template for Variant Prices
 *
 * This template is located at: astra-child/woocommerce/single-product/price.php
 * 
 * Displays variant price when ?mg_type URL parameter is present.
 * Falls back to default WooCommerce price otherwise.
 *
 * @package Astra Child
 */

if (!defined('ABSPATH')) {
    exit;
}

global $product;

// Check if variant type is specified in URL
if (isset($_GET['mg_type']) && class_exists('MG_Virtual_Variant_Manager')) {
    $requested_type = sanitize_text_field($_GET['mg_type']);
    $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
    
    // If valid variant configuration exists
    if (!empty($config) && !empty($config['types']) && isset($config['types'][$requested_type])) {
        $type_data = $config['types'][$requested_type];
        
        // Get variant price (or fall back to product price)
        $variant_price = isset($type_data['price']) && $type_data['price'] > 0 
            ? (float) $type_data['price'] 
            : (float) $product->get_price();
        
        // Get currency symbol
        $currency_symbol = get_woocommerce_currency_symbol();
        
        // Format price (Hungarian format: space as thousands separator)
        $formatted_price = number_format($variant_price, 0, '', ' ');
        
        // Output variant price HTML (matching WooCommerce default markup)
        ?>
        <p class="<?php echo esc_attr(apply_filters('woocommerce_product_price_class', 'price')); ?>">
            <span class="woocommerce-Price-amount amount">
                <bdi><?php echo $formatted_price; ?>&nbsp;<span class="woocommerce-Price-currencySymbol"><?php echo $currency_symbol; ?></span></bdi>
            </span>
        </p>
        <?php
        
        // Stop here - don't output default price
        return;
    }
}

// Default: Output standard WooCommerce price
echo $product->get_price_html();
