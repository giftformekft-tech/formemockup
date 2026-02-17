<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Overrides WooCommerce product price on server-side based on URL parameters.
 * This ensures Google bot sees the correct variant price BEFORE JavaScript runs.
 */
class MG_Server_Side_Price {

    public static function init() {
        // Override product price based on URL parameter
        add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_product_price'), 10, 2);
        add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_product_price'), 10, 2);
    }

    /**
     * Override product price based on mg_type URL parameter
     * 
     * @param float $price Original price
     * @param WC_Product $product Product object
     * @return float Modified price
     */
    public static function override_product_price($price, $product) {
        // Only on product pages
        if (!is_product()) {
            return $price;
        }

        // Check for mg_type parameter
        if (!isset($_GET['mg_type'])) {
            return $price;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);

        // Get virtual variant config
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return $price;
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        
        if (empty($config) || empty($config['types'])) {
            return $price;
        }

        // Check if requested type exists
        if (!isset($config['types'][$requested_type])) {
            return $price;
        }

        $type_data = $config['types'][$requested_type];

        // Return variant price if available
        if (isset($type_data['price']) && $type_data['price'] > 0) {
            return (float) $type_data['price'];
        }

        return $price;
    }
}
