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

}
