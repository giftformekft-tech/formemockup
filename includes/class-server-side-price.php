<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Registers custom WooCommerce template location and overrides title.
 * Safe backend approach - no WooCommerce internals modified.
 */
class MG_Server_Side_Price {

    public static function init() {
        // Register our custom WooCommerce template directory
        add_filter('woocommerce_locate_template', array(__CLASS__, 'locate_template'), 10, 3);
        
        if (isset($_GET['mg_type'])) {
            // Override page title safely
            add_filter('the_title', array(__CLASS__, 'override_title'), 10, 2);
            add_filter('document_title_parts', array(__CLASS__, 'override_doc_title'), 999);
            add_filter('wpseo_title', array(__CLASS__, 'override_seo_title'), 999);
            add_filter('rank_math/frontend/title', array(__CLASS__, 'override_seo_title'), 999);
        }
    }

    /**
     * Tell WooCommerce to look for templates in our plugin folder
     */
    public static function locate_template($template, $template_name, $template_path) {
        // Our custom template directory
        $plugin_template = plugin_dir_path(__FILE__) . '../woocommerce/' . $template_name;
        
        // If our custom template exists, use it
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
        
        return $template;
    }

    /**
     * Get variant label for current request
     */
    private static function get_variant_label() {
        static $label = null;
        if ($label !== null) {
            return $label;
        }

        if (!is_product() || !isset($_GET['mg_type'])) {
            $label = false;
            return false;
        }

        global $post;
        if (!$post || !class_exists('MG_Virtual_Variant_Manager') || !function_exists('wc_get_product')) {
            $label = false;
            return false;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $product = wc_get_product($post->ID);
        if (!$product) {
            $label = false;
            return false;
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            $label = false;
            return false;
        }

        $type_data = $config['types'][$requested_type];
        $label = isset($type_data['label']) ? $type_data['label'] : $requested_type;
        
        return $label;
    }

    /**
     * Override page title (h1)
     */
    public static function override_title($title, $id = null) {
        $label = self::get_variant_label();
        if (!$label) {
            return $title;
        }

        if (!is_product() || !in_the_loop() || !is_main_query()) {
            return $title;
        }

        if (strpos($title, ' - ' . $label) === false) {
            return $title . ' - ' . $label;
        }
        
        return $title;
    }

    /**
     * Override document <title>
     */
    public static function override_doc_title($parts) {
        $label = self::get_variant_label();
        if (!$label) {
            return $parts;
        }

        if (isset($parts['title']) && strpos($parts['title'], ' - ' . $label) === false) {
            $parts['title'] .= ' - ' . $label;
        }
        
        return $parts;
    }

    /**
     * Override SEO title
     */
    public static function override_seo_title($title) {
        $label = self::get_variant_label();
        if (!$label) {
            return $title;
        }

        if (strpos($title, $label) === false) {
            $title .= ' - ' . $label;
        }
        
        return $title;
    }
}
