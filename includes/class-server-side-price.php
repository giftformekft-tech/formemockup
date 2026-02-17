<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Overrides WooCommerce product price AND title on server-side based on URL parameters.
 * Uses output buffering as a guaranteed catch-all to replace base price/title
 * in the final HTML before it reaches the browser.
 */
class MG_Server_Side_Price {

    private static $base_price = null;
    private static $variant_price = null;
    private static $base_title = null;
    private static $variant_title = null;
    private static $type_label = null;
    private static $config_loaded = false;

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // WooCommerce filters (primary method)
        add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_product_price'), 1, 2);
        add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_product_price'), 1, 2);
        add_filter('woocommerce_product_get_name', array(__CLASS__, 'override_product_title'), 1, 2);
        add_filter('the_title', array(__CLASS__, 'override_page_title'), 1, 2);
        
        // Override <title> tag in <head>
        add_filter('document_title_parts', array(__CLASS__, 'override_document_title'), 999);
        add_filter('wpseo_title', array(__CLASS__, 'override_seo_title'), 999);
        add_filter('rank_math/frontend/title', array(__CLASS__, 'override_seo_title'), 999);
        
        // Override WooCommerce price HTML directly  
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'override_price_html'), 1, 2);
        
        // Override Open Graph meta tags
        add_filter('wpseo_opengraph_title', array(__CLASS__, 'override_seo_title'), 999);
        add_filter('wpseo_opengraph_price', array(__CLASS__, 'override_og_price'), 999);
        
        // Output buffer as absolute last resort
        add_action('template_redirect', array(__CLASS__, 'start_output_buffer'), 0);
        
        // Clear WooCommerce cache
        add_action('template_redirect', array(__CLASS__, 'clear_product_cache'), 1);
    }

    /**
     * Load variant configuration (cached per request)
     */
    private static function load_config($product = null) {
        if (self::$config_loaded) {
            return self::$variant_price !== null;
        }
        self::$config_loaded = true;

        if (!$product) {
            global $product;
        }
        if (!$product && function_exists('wc_get_product')) {
            global $post;
            if ($post) {
                $product = wc_get_product($post->ID);
            }
        }
        if (!$product) {
            return false;
        }

        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return false;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);

        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            return false;
        }

        $type_data = $config['types'][$requested_type];

        // Store base values
        self::$base_price = (float) $product->get_data()['price'];  // Raw DB price, bypasses filters
        self::$base_title = $product->get_data()['name'];  // Raw DB name, bypasses filters

        // Store variant values
        if (isset($type_data['price']) && $type_data['price'] > 0) {
            self::$variant_price = (float) $type_data['price'];
        } else {
            self::$variant_price = self::$base_price;
        }

        self::$type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;
        self::$variant_title = self::$base_title . ' - ' . self::$type_label;

        return true;
    }

    /**
     * Clear product cache early
     */
    public static function clear_product_cache() {
        if (!is_product()) {
            return;
        }
        global $post;
        if ($post) {
            wp_cache_delete('product-' . $post->ID, 'products');
            wp_cache_delete($post->ID, 'product_meta');
            delete_transient('wc_product_' . $post->ID);
            // Force WooCommerce to re-read product
            wc_delete_product_transients($post->ID);
        }
    }

    /**
     * Start output buffering to catch ALL price/title references
     */
    public static function start_output_buffer() {
        if (!is_product()) {
            return;
        }
        ob_start(array(__CLASS__, 'process_output_buffer'));
    }

    /**
     * Process the output buffer - replace base price/title with variant values
     */
    public static function process_output_buffer($html) {
        if (!self::load_config()) {
            return $html;
        }

        if (self::$base_price === null || self::$variant_price === null) {
            return $html;
        }

        // Only modify if prices are actually different
        if (self::$base_price != self::$variant_price) {
            // Format prices for replacement
            $base_formatted = number_format(self::$base_price, 0, '', ' ');
            $variant_formatted = number_format(self::$variant_price, 0, '', ' ');
            
            // Also try with different formatting
            $base_formatted_dot = number_format(self::$base_price, 0, '', '.');
            $variant_formatted_dot = number_format(self::$variant_price, 0, '', '.');
            
            $base_formatted_comma = number_format(self::$base_price, 0, '', ',');
            $variant_formatted_comma = number_format(self::$variant_price, 0, '', ',');

            $base_plain = (string)(int)self::$base_price;
            $variant_plain = (string)(int)self::$variant_price;

            // Replace in price spans (WooCommerce format)
            // Match: <bdi>4 990&nbsp;<span ...>Ft</span></bdi>
            // Replace with variant price
            $html = preg_replace(
                '/(<span class="woocommerce-Price-amount[^"]*"[^>]*><bdi>)' . preg_quote($base_formatted, '/') . '(\s*&nbsp;)/u',
                '${1}' . $variant_formatted . '${2}',
                $html
            );
            
            // Also replace plain number format in price amount
            $html = preg_replace(
                '/(<span class="woocommerce-Price-amount[^"]*"[^>]*><bdi>)' . preg_quote($base_formatted_dot, '/') . '(\s*&nbsp;)/u',
                '${1}' . $variant_formatted . '${2}',
                $html
            );

            // Replace in gtag / analytics
            $html = str_replace(
                'value: ' . number_format(self::$base_price, 6, '.', ''),
                'value: ' . number_format(self::$variant_price, 6, '.', ''),
                $html
            );
            $html = str_replace(
                'price: ' . number_format(self::$base_price, 6, '.', ''),
                'price: ' . number_format(self::$variant_price, 6, '.', ''),
                $html
            );
            
            // Also handle integer price format in analytics
            $html = str_replace(
                'value: ' . $base_plain . '.000000',
                'value: ' . $variant_plain . '.000000',
                $html
            );
            $html = str_replace(
                'price: ' . $base_plain . '.000000',
                'price: ' . $variant_plain . '.000000',
                $html
            );
        }

        return $html;
    }

    /**
     * Override product price
     */
    public static function override_product_price($price, $product) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $price;
        }

        if (self::load_config($product) && self::$variant_price !== null) {
            return self::$variant_price;
        }

        return $price;
    }

    /**
     * Override WooCommerce price HTML directly
     */
    public static function override_price_html($html, $product) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $html;
        }

        if (!self::load_config($product) || self::$variant_price === null) {
            return $html;
        }

        // Replace price in the HTML
        if (self::$base_price != self::$variant_price) {
            $base_formatted = number_format(self::$base_price, 0, '', ' ');
            $variant_formatted = number_format(self::$variant_price, 0, '', ' ');
            $html = str_replace($base_formatted, $variant_formatted, $html);
        }

        return $html;
    }

    /**
     * Override product title/name
     */
    public static function override_product_title($name, $product) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $name;
        }

        if (self::load_config($product) && self::$type_label) {
            // Prevent double append
            if (strpos($name, ' - ' . self::$type_label) === false) {
                return $name . ' - ' . self::$type_label;
            }
        }

        return $name;
    }

    /**
     * Override page title (the_title filter)
     */
    public static function override_page_title($title, $id = null) {
        if (!is_product() || !in_the_loop() || !is_main_query()) {
            return $title;
        }

        if (!isset($_GET['mg_type'])) {
            return $title;
        }

        if (self::load_config() && self::$type_label) {
            if (strpos($title, ' - ' . self::$type_label) === false) {
                return $title . ' - ' . self::$type_label;
            }
        }

        return $title;
    }

    /**
     * Override document <title> tag
     */
    public static function override_document_title($title_parts) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $title_parts;
        }

        if (self::load_config() && self::$type_label && isset($title_parts['title'])) {
            if (strpos($title_parts['title'], ' - ' . self::$type_label) === false) {
                $title_parts['title'] .= ' - ' . self::$type_label;
            }
        }

        return $title_parts;
    }

    /**
     * Override SEO plugin title (Yoast, Rank Math)
     */
    public static function override_seo_title($title) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $title;
        }

        if (self::load_config() && self::$type_label) {
            if (strpos($title, ' - ' . self::$type_label) === false) {
                $title = str_replace(self::$base_title, self::$variant_title, $title);
                // If replacement didn't work, append
                if (strpos($title, self::$type_label) === false) {
                    $title .= ' - ' . self::$type_label;
                }
            }
        }

        return $title;
    }

    /**
     * Override Open Graph price
     */
    public static function override_og_price($price) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $price;
        }

        if (self::load_config() && self::$variant_price !== null) {
            return self::$variant_price;
        }

        return $price;
    }
}
