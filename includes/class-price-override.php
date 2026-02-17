<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Price_Override
 * 
 * Intercepts product price on the frontend if 'mg_type' URL parameter is present.
 * This ensures Google Merchant Center bots see the correct price for the specific virtual variant.
 */
class MG_Price_Override {

    public static function init() {
        // Only run on frontend and if mg_type is present
        if (is_admin() || !isset($_GET['mg_type'])) {
            return;
        }

        // Hook into price filters
        // Note: 'woocommerce_product_get_price' filters the raw price. 
        // We also hook into 'woocommerce_product_get_regular_price' to be consistent.
        add_filter('woocommerce_product_get_price', array(__CLASS__, 'override_price'), 99, 2);
        add_filter('woocommerce_product_get_regular_price', array(__CLASS__, 'override_price'), 99, 2);
        add_filter('woocommerce_product_variation_get_price', array(__CLASS__, 'override_price'), 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', array(__CLASS__, 'override_price'), 99, 2);
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
     * Calculate price based strictly on URL parameters (mg_type, mg_color, mg_size)
     * using the global catalog / helper functions.
     * 
     * @return float|null The calculated price or null if calculation failed/invalid
     */
    private static function calculate_price_from_url() {
        // Dependencies
        if (!function_exists('mgsc_compute_variant_price') || !function_exists('mgsc_get_size_surcharge')) {
            // If helper functions from includes/variant-surcharge-applier.php are not loaded, 
            // we cannot calculate. We could include the file here, but it's better to rely on global load.
            // Let's assume they are loaded since we are in WP init flow usually.
            // If strictly needed, we can manually include the file:
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
        // mgsc_compute_variant_price($type_key, $color_slug) returns float price (Base + Color)
        // If color is empty, it returns Base price (assuming no color surcharge for empty color or iterates defaults? 
        // Let's check mgsc_compute_variant_price implementation:
        // if color is found, looks for surcharge. if color not found (empty loop), adds 0.
        // So just type is enough for base price.
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
}

// Initialize immediately

