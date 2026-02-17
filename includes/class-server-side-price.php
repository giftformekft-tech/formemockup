<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Directly modifies WooCommerce product object before template renders.
 * Sets price and name on the product object itself, so ALL subsequent
 * reads get the correct variant data. No filters, no recursion risk.
 */
class MG_Server_Side_Price {

    private static $type_label = null;

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // Modify product object BEFORE template renders
        add_action('woocommerce_before_single_product', array(__CLASS__, 'modify_product_object'), 1);
        
        // Override page <h1> title
        add_filter('the_title', array(__CLASS__, 'override_page_title'), 1, 2);
        
        // Override <title> tag in head
        add_filter('document_title_parts', array(__CLASS__, 'override_document_title'), 999);
        add_filter('wpseo_title', array(__CLASS__, 'override_seo_title'), 999);
        add_filter('rank_math/frontend/title', array(__CLASS__, 'override_seo_title'), 999);
    }

    /**
     * Directly modify the WooCommerce product object
     */
    public static function modify_product_object() {
        global $product;
        if (!$product || !class_exists('MG_Virtual_Variant_Manager')) {
            return;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);

        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            return;
        }

        $type_data = $config['types'][$requested_type];

        // Directly set price on product object
        if (isset($type_data['price']) && $type_data['price'] > 0) {
            $product->set_price((float) $type_data['price']);
            $product->set_regular_price((float) $type_data['price']);
        }

        // Directly set name on product object
        self::$type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;
        $product->set_name($product->get_data()['name'] . ' - ' . self::$type_label);
    }

    /**
     * Override page <h1> title
     */
    public static function override_page_title($title, $id = null) {
        if (!self::$type_label || !is_product() || !in_the_loop() || !is_main_query()) {
            return $title;
        }
        if (strpos($title, ' - ' . self::$type_label) === false) {
            return $title . ' - ' . self::$type_label;
        }
        return $title;
    }

    /**
     * Override document <title> tag
     */
    public static function override_document_title($parts) {
        if (!self::$type_label || !is_product()) {
            return $parts;
        }
        if (isset($parts['title']) && strpos($parts['title'], ' - ' . self::$type_label) === false) {
            $parts['title'] .= ' - ' . self::$type_label;
        }
        return $parts;
    }

    /**
     * Override SEO title (Yoast, Rank Math)
     */
    public static function override_seo_title($title) {
        if (!self::$type_label || !is_product()) {
            return $title;
        }
        if (strpos($title, self::$type_label) === false) {
            $title .= ' - ' . self::$type_label;
        }
        return $title;
    }
}
