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
        // Check if URL parameter exists early
        if (isset($_GET['mg_type'])) {
            // Run VERY early to prevent caching
            add_action('template_redirect', array(__CLASS__, 'clear_product_cache'), 1);
            
            // Disable product meta cache for this request
            add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_product_price'), 5, 2);
            add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_product_price'), 5, 2);
            
            // Also disable caching
            add_filter('woocommerce_cache_helper_get_transient_version', '__return_empty_string');
        } else {
            // Normal priority when no parameter
            add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_product_price'), 10, 2);
            add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_product_price'), 10, 2);
        }
    }
    
    /**
     * Clear product cache early
     */
    public static function clear_product_cache() {
        if (!is_product()) {
            return;
        }
        
        global $post;
        if (!$post) {
            return;
        }
        
        // Clear WooCommerce product cache
        wp_cache_delete('product-' . $post->ID, 'products');
        wp_cache_delete($post->ID, 'product_meta');
        
        // Clear transients
        delete_transient('wc_product_' . $post->ID);
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
