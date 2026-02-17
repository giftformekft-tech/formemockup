<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Modifies WooCommerce product price/title at the earliest possible hook (wp).
 * Uses recursion guard for safe filter usage.
 */
class MG_Server_Side_Price {

    private static $variant_price = null;
    private static $type_label = null;
    private static $loaded = false;
    private static $loading = false;

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // wp hook = earliest point where is_product() and $post are available
        // This runs BEFORE wp_head, so gtag will get correct price
        add_action('wp', array(__CLASS__, 'setup_overrides'), 1);
    }

    /**
     * Setup all overrides at the wp hook (before wp_head)
     */
    public static function setup_overrides() {
        if (!is_product()) {
            return;
        }

        // Load config and set up values
        self::load_config();

        if (self::$variant_price === null && self::$type_label === null) {
            return;
        }

        // Modify the global product object directly
        global $product;
        if (!$product) {
            global $post;
            if ($post) {
                $product = wc_get_product($post->ID);
            }
        }

        if ($product) {
            if (self::$variant_price !== null) {
                $product->set_price(self::$variant_price);
                $product->set_regular_price(self::$variant_price);
            }
            if (self::$type_label) {
                $base_name = get_the_title($product->get_id());
                $product->set_name($base_name . ' - ' . self::$type_label);
            }
        }

        // Also add filters as backup (return pre-computed values only)
        if (self::$variant_price !== null) {
            add_filter('woocommerce_product_get_price', array(__CLASS__, 'return_variant_price'), 1, 2);
            add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'return_variant_price'), 1, 2);
            add_filter('woocommerce_get_price_html', array(__CLASS__, 'override_price_html'), 1, 2);
        }

        if (self::$type_label) {
            add_filter('the_title', array(__CLASS__, 'override_page_title'), 1, 2);
            add_filter('document_title_parts', array(__CLASS__, 'override_document_title'), 999);
            add_filter('wpseo_title', array(__CLASS__, 'override_seo_title'), 999);
            add_filter('rank_math/frontend/title', array(__CLASS__, 'override_seo_title'), 999);
        }
    }

    /**
     * Load config once, safely
     */
    private static function load_config() {
        if (self::$loaded || self::$loading) {
            return;
        }
        self::$loading = true;

        global $post;
        if (!$post || !class_exists('MG_Virtual_Variant_Manager')) {
            self::$loading = false;
            self::$loaded = true;
            return;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $product = wc_get_product($post->ID);
        if (!$product) {
            self::$loading = false;
            self::$loaded = true;
            return;
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);

        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            self::$loading = false;
            self::$loaded = true;
            return;
        }

        $type_data = $config['types'][$requested_type];

        if (isset($type_data['price']) && $type_data['price'] > 0) {
            self::$variant_price = (float) $type_data['price'];
        }

        self::$type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;

        self::$loading = false;
        self::$loaded = true;
    }

    // --- Simple return filters (no DB calls, safe) ---

    public static function return_variant_price($price, $product) {
        return self::$variant_price !== null ? self::$variant_price : $price;
    }

    public static function override_price_html($html, $product) {
        if (self::$variant_price === null) {
            return $html;
        }
        $currency = get_woocommerce_currency_symbol();
        $formatted = number_format(self::$variant_price, 0, '', ' ');
        return '<span class="woocommerce-Price-amount amount"><bdi>' . $formatted . '&nbsp;<span class="woocommerce-Price-currencySymbol">' . $currency . '</span></bdi></span>';
    }

    public static function override_page_title($title, $id = null) {
        if (!self::$type_label || !is_product() || !in_the_loop() || !is_main_query()) {
            return $title;
        }
        if (strpos($title, ' - ' . self::$type_label) === false) {
            return $title . ' - ' . self::$type_label;
        }
        return $title;
    }

    public static function override_document_title($parts) {
        if (!self::$type_label || !is_product()) {
            return $parts;
        }
        if (isset($parts['title']) && strpos($parts['title'], ' - ' . self::$type_label) === false) {
            $parts['title'] .= ' - ' . self::$type_label;
        }
        return $parts;
    }

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
