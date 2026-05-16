<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Price_Override
 * 
 * Intercepts product price and name on the frontend.
 * 1. If 'mg_type' URL parameter is present (product page), overrides price for Google bots.
 * 2. On cart/checkout pages, ensures external plugins (like GLA) see the correct cart item price.
 */
class MG_Price_Override {

    /** Recursion guard for cart price override */
    private static $in_cart_override = false;

    public static function init() {
        // Only run on frontend
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Product page price override (when mg_type URL parameter is present)
        if (isset($_GET['mg_type'])) {
            add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_price'), 99, 2);
            add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_price'), 99, 2);
            add_filter('woocommerce_product_variation_get_price', array(__CLASS__, 'override_price'), 99, 2);
            add_filter('woocommerce_product_variation_get_regular_price', array(__CLASS__, 'override_price'), 99, 2);
            add_filter('woocommerce_product_get_name', array(__CLASS__, 'override_name'), 99, 2);
        }

        // Cart/checkout price override for external plugins (e.g. Google Listings & Ads)
        // Priority 100 to run after other price filters
        add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_cart_item_price'), 100, 2);
        add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_cart_item_price'), 100, 2);

        // Cart/checkout name override for external plugins
        add_filter('woocommerce_product_get_name', array(__CLASS__, 'override_cart_item_name'), 100, 2);
    }

    /**
     * Override the price based on URL parameters (product page only)
     */
    public static function override_price($price, $product) {
        if (is_admin() || !isset($_GET['mg_type'])) {
            return $price;
        }

        $new_price = self::calculate_price_from_url();
        if ($new_price !== null && $new_price > 0) {
            return $new_price;
        }

        return $price;
    }

    /**
     * Override price on cart/checkout pages using stored cart item data.
     * Uses mg_custom_fields_base_price (which contains the FULL calculated price)
     * directly from the cart array, avoiding any filter recursion.
     */
    public static function override_cart_item_price($price, $product) {
        // Recursion guard
        if (self::$in_cart_override) {
            return $price;
        }

        // Only on frontend
        if (is_admin()) {
            return $price;
        }

        // Only on cart/checkout pages
        if (!function_exists('is_cart') || !function_exists('is_checkout')) {
            return $price;
        }
        if (!is_cart() && !is_checkout()) {
            return $price;
        }

        // Need cart to be available
        if (!WC()->cart) {
            return $price;
        }

        self::$in_cart_override = true;

        $product_id = $product->get_id();
        
        // Access cart_contents directly to avoid triggering get_cart() hooks
        $cart_contents = WC()->cart->cart_contents;
        
        foreach ($cart_contents as $cart_item) {
            $item_product_id = isset($cart_item['product_id']) ? $cart_item['product_id'] : 0;
            $item_variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
            
            if ($item_product_id == $product_id || $item_variation_id == $product_id) {
                // Read the stored price directly (no filter call, just array access)
                if (isset($cart_item['mg_custom_fields_base_price']) && is_numeric($cart_item['mg_custom_fields_base_price'])) {
                    $stored_price = floatval($cart_item['mg_custom_fields_base_price']);
                    if ($stored_price > 0) {
                        self::$in_cart_override = false;
                        return $stored_price;
                    }
                }
            }
        }

        self::$in_cart_override = false;
        return $price;
    }

    /**
     * Override product name on cart/checkout pages by appending the product type label.
     * This ensures external plugins (like GLA) see the correct variant name.
     */
    public static function override_cart_item_name($name, $product) {
        // Recursion guard (reuse same flag)
        if (self::$in_cart_override) {
            return $name;
        }

        if (is_admin()) {
            return $name;
        }

        if (!function_exists('is_cart') || !function_exists('is_checkout')) {
            return $name;
        }
        if (!is_cart() && !is_checkout()) {
            return $name;
        }

        if (!WC()->cart) {
            return $name;
        }

        self::$in_cart_override = true;

        $product_id = $product->get_id();
        $cart_contents = WC()->cart->cart_contents;

        foreach ($cart_contents as $cart_item) {
            $item_product_id = isset($cart_item['product_id']) ? $cart_item['product_id'] : 0;
            $item_variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;

            if ($item_product_id == $product_id || $item_variation_id == $product_id) {
                if (!empty($cart_item['mg_product_type'])) {
                    $type_slug = sanitize_title($cart_item['mg_product_type']);
                    $type_label = self::get_type_label_from_catalog($type_slug);
                    if ($type_label && strpos($name, ' - ' . $type_label) === false) {
                        self::$in_cart_override = false;
                        return $name . ' - ' . $type_label;
                    }
                }
            }
        }

        self::$in_cart_override = false;
        return $name;
    }

    /**
     * Get type label from catalog
     */
    private static function get_type_label_from_catalog($type_slug) {
        if (!class_exists('MG_Variant_Display_Manager')) {
            return ucfirst(str_replace('-', ' ', $type_slug));
        }
        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        if (isset($catalog[$type_slug]['label'])) {
            return $catalog[$type_slug]['label'];
        }
        return ucfirst(str_replace('-', ' ', $type_slug));
    }

    /**
     * Override the product name based on URL parameters
     */
    public static function override_name($name, $product) {
        if (is_admin() || !isset($_GET['mg_type'])) {
            return $name;
        }

        $type_key = sanitize_text_field($_GET['mg_type']);
        $label = self::get_type_label($type_key);

        if ($label) {
            if (strpos($name, $label) === false) {
                return $name . ' - ' . $label;
            }
        }

        return $name;
    }

    /**
     * Calculate price based strictly on URL parameters
     */
    private static function calculate_price_from_url() {
        if (!function_exists('mgsc_compute_variant_price') || !function_exists('mgsc_get_size_surcharge')) {
            $surcharge_file = dirname(__DIR__) . '/includes/variant-surcharge-applier.php';
            if (file_exists($surcharge_file)) {
                require_once $surcharge_file;
            } else {
                return null;
            }
        }
        
        $type_key = sanitize_text_field($_GET['mg_type']);
        $color_slug = isset($_GET['mg_color']) ? sanitize_text_field($_GET['mg_color']) : '';
        $size_slug = isset($_GET['mg_size']) ? sanitize_text_field($_GET['mg_size']) : '';

        $price = mgsc_compute_variant_price($type_key, $color_slug);
        if ($price === null) {
            return null;
        }

        if ($size_slug) {
            $size_surcharge = mgsc_get_size_surcharge($type_key, $size_slug);
            $price += $size_surcharge;
        }

        return $price;
    }

    /**
     * Get the label for a given type key from the global catalog
     */
    private static function get_type_label($type_key) {
        if (!function_exists('mg_get_global_catalog')) {
             $catalog_file = dirname(__DIR__) . '/includes/global-catalog.php';
             if (file_exists($catalog_file)) {
                 require_once $catalog_file;
             } else {
                 return null;
             }
        }

        $catalog = mg_get_global_catalog();
        if (isset($catalog[$type_key]) && isset($catalog[$type_key]['label'])) {
            return $catalog[$type_key]['label'];
        }
        
        return null;
    }
}
