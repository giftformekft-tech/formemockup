<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Catalog_Integration
 * 
 * Modifies catalog loop links to include the default variant type parameter.
 * This ensures that when a user clicks a product, the server already knows 
 * which variant to display, preventing price flash and improving UX.
 */
class MG_Catalog_Integration {

    public static function init() {
        add_filter('woocommerce_loop_product_link', array(__CLASS__, 'append_default_variant_param'), 10, 2);
    }

    /**
     * Appends ?mg_type=slug to the product permalink in the loop.
     * 
     * @param string $link The product permalink.
     * @param WC_Product $product The product object.
     * @return string Modified link with mg_type parameter.
     */
    public static function append_default_variant_param($link, $product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return $link;
        }

        // Only simple products are supported by this plugin logic
        if (!$product->is_type('simple')) {
            return $link;
        }

        // We need to find the default type for this product.
        // We can use the MG_Virtual_Variant_Manager logic, but to avoid 
        // heavy logic in the loop, we try to use cached or lightweight checks.
        
        // 1. Check if we can get the catalog from global function
        if (!function_exists('mg_get_global_catalog')) {
            return $link;
        }

        // Determine default type
        // Usually it's the first key in the global catalog, but filtering might apply per product.
        // Since all products share the global catalog in this plugin version:
        $catalog = mg_get_global_catalog();
        if (empty($catalog)) {
            return $link;
        }

        // Get default type slug
        // Logic from MG_Virtual_Variant_Manager::get_frontend_config
        // 1. Check if there is an explicit default set in config (not easily accessible here without heavy call)
        // 2. Just take the first available type from the catalog.
        
        // Let's assume the first type in the catalog is the default 
        // OR checks if the product has specific allowed types (though current global-catalog implies all are allowed).
        
        reset($catalog);
        $default_type_slug = key($catalog);

        if ($default_type_slug) {
            $link = add_query_arg('mg_type', $default_type_slug, $link);
        }

        return $link;
    }
}
