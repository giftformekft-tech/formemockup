<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Price_Override
 * 
 * Intercepts product price and name on the frontend if 'mg_type' URL parameter is present.
 * This ensures Google Merchant Center bots see the correct price and title for the specific virtual variant.
 */
class MG_Price_Override {

    public static function init() {
        // Only run on frontend and if mg_type is present
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Hook into price filters
        add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_price'), 99, 2);
        add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_price'), 99, 2);
        add_filter('woocommerce_product_variation_get_price', array(__CLASS__, 'override_price'), 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', array(__CLASS__, 'override_price'), 99, 2);

        // Fix for external plugins (e.g. Google Listings & Ads) fetching fresh product objects
        add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_cart_item_price'), 100, 2);
        add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_cart_item_price'), 100, 2);

        // Hook into name filter for Google Feed / Structured Data
        add_filter('woocommerce_product_get_name', array(__CLASS__, 'override_name'), 99, 2);
    }

    /**
     * Override the price based on URL parameters
     * 
     * @param string|float $price The current price
     * @param WC_Product $product The product object
     * @return string|float Modified price
     */
    public static function override_price($price, $product) {
        // Double check simply to be safe, though init() guards this too.
        if (is_admin() || !isset($_GET['mg_type'])) {
            return $price;
        }

        // We only want to override if we can calculate a valid new price
        $new_price = self::calculate_price_from_url();

        if ($new_price !== null && $new_price > 0) {
            return $new_price;
        }

        return $price;
    }

    /**
     * Override the product name based on URL parameters
     * Appends the variant type label to the product name.
     * 
     * @param string $name The current product name
     * @param WC_Product $product The product object
     * @return string Modified name
     */
    public static function override_name($name, $product) {
        if (is_admin() || !isset($_GET['mg_type'])) {
            return $name;
        }

        $type_key = sanitize_text_field($_GET['mg_type']);
        $label = self::get_type_label($type_key);

        if ($label) {
            // Avoid duplicate appending if theme or other plugins already added it
            if (strpos($name, $label) === false) {
                return $name . ' - ' . $label;
            }
        }

        return $name;
    }

    /**
     * Calculate price based strictly on URL parameters (mg_type, mg_color, mg_size)
     * using the global catalog / helper functions.
     * 
     * @return float|null The calculated price or null if calculation failed/invalid
     */
    private static function calculate_price_from_url() {
        // Dependencies
        if (!function_exists('mgsc_compute_variant_price') || !function_exists('mgsc_get_size_surcharge')) {
            $surcharge_file = dirname(__DIR__) . '/includes/variant-surcharge-applier.php';
            if (file_exists($surcharge_file)) {
                require_once $surcharge_file;
            } else {
                return null;
            }
        }
        
        // 1. Get parameters
        $type_key = sanitize_text_field($_GET['mg_type']);
        $color_slug = isset($_GET['mg_color']) ? sanitize_text_field($_GET['mg_color']) : '';
        $size_slug = isset($_GET['mg_size']) ? sanitize_text_field($_GET['mg_size']) : '';

        // 2. Compute Base + Color Surcharge
        $price = mgsc_compute_variant_price($type_key, $color_slug);

        if ($price === null) {
            return null; // Type not found
        }

        // 3. Add Size Surcharge if present
        if ($size_slug) {
            $size_surcharge = mgsc_get_size_surcharge($type_key, $size_slug);
            $price += $size_surcharge;
        }

        return $price;
    }

    /**
     * Get the label for a given type key from the global catalog
     * 
     * @param string $type_key
     * @return string|null
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
        
        return null; // Fallback: could return ucfirst($type_key) if desired, but better strict
    }

    /**
     * Override price if this product is currently in the cart with a modified price.
     * This helps external plugins (like Google Listings & Ads) that fetch a fresh product object
     * instead of using the cart item data directly.
     */
    public static function override_cart_item_price($price, $product) {
        if (is_admin() || !function_exists('is_cart') || !function_exists('is_checkout')) {
            return $price;
        }

        // Only run on cart or checkout pages to avoid overhead
        if (!is_cart() && !is_checkout()) {
            return $price;
        }

        if (!isset(WC()->cart)) {
            return $price;
        }

        // Check if this product ID is in the cart and has a price override
        // Note: This is imperfect if multiple items have same ID but different options.
        // But for GTAG purposes, usually it iterates the cart items specifically.
        // However, if it calls wc_get_product($id), it has no context of WHICH cart item it is.
        // So we can only return *one* price.
        
        // Strategy: If there's only one item with this ID in cart, return its price.
        // If multiple, this is ambiguous, but likely the first one is better than base price.
        
        $product_id = $product->get_id();
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id || $cart_item['variation_id'] == $product_id) {
                if (isset($cart_item['mg_custom_fields_base_price']) && is_numeric($cart_item['mg_custom_fields_base_price'])) {
                    // Start with the calculated price from Virtual Variant Manager or similar
                    // Wait, mg_custom_fields_base_price was the *display* override we removed.
                    // But typically our logic sets the PRICE on the object. 
                    // Let's check if the cart item has a price set that differs from product base.
                    
                    if (isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
                         $cart_price = $cart_item['data']->get_price();
                         if ($cart_price !== $price) {
                             return $cart_price;
                         }
                    }
                }
            }
        }
        
        return $price;
    }
}
