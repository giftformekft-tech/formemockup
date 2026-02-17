<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Modifies WooCommerce product price/title server-side.
 * Runs at template_redirect (before wp_head) so gtag gets correct price.
 */
class MG_Server_Side_Price {

    private static $variant_price = null;
    private static $type_label = null;
    private static $loaded = false;

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // template_redirect = before wp_head, all WC functions available
        add_action('template_redirect', array(__CLASS__, 'setup_overrides'), 5);
    }

    public static function setup_overrides() {
        try {
            if (!is_product()) {
                return;
            }

            self::load_config();

            if (self::$variant_price === null && self::$type_label === null) {
                return;
            }

            // Get or create product object
            global $product, $post;
            if (!$product && $post) {
                $product = wc_get_product($post->ID);
            }
            if (!$product) {
                return;
            }

            // Directly modify product object
            if (self::$variant_price !== null) {
                $product->set_price(self::$variant_price);
                $product->set_regular_price(self::$variant_price);
            }
            if (self::$type_label) {
                $raw_name = get_post_field('post_title', $product->get_id());
                $product->set_name($raw_name . ' - ' . self::$type_label);
            }

            // Backup filters (return pre-computed values only, zero DB calls)
            if (self::$variant_price !== null) {
                add_filter('woocommerce_product_get_price', array(__CLASS__, 'return_price'), 1, 2);
                add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'return_price'), 1, 2);
                add_filter('woocommerce_get_price_html', array(__CLASS__, 'return_price_html'), 1, 2);
            }
            if (self::$type_label) {
                add_filter('the_title', array(__CLASS__, 'return_title'), 1, 2);
                add_filter('document_title_parts', array(__CLASS__, 'return_doc_title'), 999);
                add_filter('wpseo_title', array(__CLASS__, 'return_seo_title'), 999);
                add_filter('rank_math/frontend/title', array(__CLASS__, 'return_seo_title'), 999);
            }

        } catch (Exception $e) {
            // Silently fail - don't crash the site
            error_log('MG Server Side Price Error: ' . $e->getMessage());
        }
    }

    private static function load_config() {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        global $post;
        if (!$post) {
            return;
        }
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return;
        }
        if (!function_exists('wc_get_product')) {
            return;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $product = wc_get_product($post->ID);
        if (!$product) {
            return;
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            return;
        }

        $type_data = $config['types'][$requested_type];

        if (isset($type_data['price']) && $type_data['price'] > 0) {
            self::$variant_price = (float) $type_data['price'];
        }

        self::$type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;
    }

    // --- Simple return filters (safe, no DB) ---

    public static function return_price($price, $product) {
        return self::$variant_price !== null ? self::$variant_price : $price;
    }

    public static function return_price_html($html, $product) {
        if (self::$variant_price === null) {
            return $html;
        }
        $symbol = get_woocommerce_currency_symbol();
        $formatted = number_format(self::$variant_price, 0, '', ' ');
        return '<span class="woocommerce-Price-amount amount"><bdi>' . $formatted . '&nbsp;<span class="woocommerce-Price-currencySymbol">' . $symbol . '</span></bdi></span>';
    }

    public static function return_title($title, $id = null) {
        if (!self::$type_label) {
            return $title;
        }
        if (!is_product() || !in_the_loop() || !is_main_query()) {
            return $title;
        }
        if (strpos($title, ' - ' . self::$type_label) === false) {
            return $title . ' - ' . self::$type_label;
        }
        return $title;
    }

    public static function return_doc_title($parts) {
        if (!self::$type_label || !is_product()) {
            return $parts;
        }
        if (isset($parts['title']) && strpos($parts['title'], ' - ' . self::$type_label) === false) {
            $parts['title'] .= ' - ' . self::$type_label;
        }
        return $parts;
    }

    public static function return_seo_title($title) {
        if (!self::$type_label || !is_product()) {
            return $title;
        }
        if (strpos($title, self::$type_label) === false) {
            $title .= ' - ' . self::$type_label;
        }
        return $title;
    }
}
