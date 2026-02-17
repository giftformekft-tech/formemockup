<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Overrides WooCommerce product price AND title on server-side based on URL parameters.
 * Uses static cache and recursion guard to prevent infinite loops.
 */
class MG_Server_Side_Price {

    private static $variant_price = null;
    private static $type_label = null;
    private static $loading = false;  // Recursion guard
    private static $loaded = false;

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // Load config ONCE early, before any filters fire
        add_action('template_redirect', array(__CLASS__, 'preload_config'), 0);
    }

    /**
     * Preload variant config once, then attach filters
     */
    public static function preload_config() {
        if (!is_product()) {
            return;
        }

        // Load config NOW (no filters attached yet, so no recursion possible)
        self::load_config_safe();

        if (self::$variant_price === null && self::$type_label === null) {
            return; // Nothing to override
        }

        // NOW attach filters (config is already loaded, won't call get_price again)
        if (self::$variant_price !== null) {
            add_filter('woocommerce_product_get_price', array(__CLASS__, 'return_variant_price'), 1, 2);
            add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'return_variant_price'), 1, 2);
            add_filter('woocommerce_get_price_html', array(__CLASS__, 'override_price_html'), 1, 2);
        }

        if (self::$type_label !== null) {
            add_filter('woocommerce_product_get_name', array(__CLASS__, 'return_variant_title'), 1, 2);
            add_filter('the_title', array(__CLASS__, 'return_page_title'), 1, 2);
            add_filter('document_title_parts', array(__CLASS__, 'override_document_title'), 999);
            add_filter('wpseo_title', array(__CLASS__, 'append_type_to_seo'), 999);
            add_filter('rank_math/frontend/title', array(__CLASS__, 'append_type_to_seo'), 999);
        }
    }

    /**
     * Load variant config safely WITHOUT triggering any WooCommerce price/name getters
     */
    private static function load_config_safe() {
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

        // Get config - this may internally call get_price(), but our filter is NOT attached yet
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

        // Store variant price
        if (isset($type_data['price']) && $type_data['price'] > 0) {
            self::$variant_price = (float) $type_data['price'];
        }

        // Store type label
        self::$type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;

        self::$loading = false;
        self::$loaded = true;
    }

    // ---- Simple return filters (no DB calls, no recursion risk) ----

    public static function return_variant_price($price, $product) {
        if (self::$variant_price !== null) {
            return self::$variant_price;
        }
        return $price;
    }

    public static function override_price_html($html, $product) {
        if (self::$variant_price === null) {
            return $html;
        }
        // Build clean price HTML
        $currency = get_woocommerce_currency_symbol();
        $formatted = number_format(self::$variant_price, 0, '', ' ');
        return '<span class="woocommerce-Price-amount amount"><bdi>' . $formatted . '&nbsp;<span class="woocommerce-Price-currencySymbol">' . $currency . '</span></bdi></span>';
    }

    public static function return_variant_title($name, $product) {
        if (self::$type_label && strpos($name, ' - ' . self::$type_label) === false) {
            return $name . ' - ' . self::$type_label;
        }
        return $name;
    }

    public static function return_page_title($title, $id = null) {
        if (!is_product() || !in_the_loop() || !is_main_query()) {
            return $title;
        }
        if (self::$type_label && strpos($title, ' - ' . self::$type_label) === false) {
            return $title . ' - ' . self::$type_label;
        }
        return $title;
    }

    public static function override_document_title($parts) {
        if (!is_product()) {
            return $parts;
        }
        if (self::$type_label && isset($parts['title']) && strpos($parts['title'], ' - ' . self::$type_label) === false) {
            $parts['title'] .= ' - ' . self::$type_label;
        }
        return $parts;
    }

    public static function append_type_to_seo($title) {
        if (!is_product()) {
            return $title;
        }
        if (self::$type_label && strpos($title, ' - ' . self::$type_label) === false) {
            // Insert before site name separator
            $title .= ' - ' . self::$type_label;
        }
        return $title;
    }
}
