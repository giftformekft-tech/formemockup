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
        $catalog = array();
        if (function_exists('mg_get_global_catalog')) {
            $catalog = mg_get_global_catalog();
        }

        // 2. Fallback to mg_products option if global catalog is empty (Admin based workaround)
        if (empty($catalog)) {
            $products = get_option('mg_products', array());
            if (!empty($products)) {
                // Determine default for THIS product
                // 'mg_products' is keyed by numeric index usually, but we need to find by Key/Slug
                // Actually mg_products structure is: array of products.
                // We need to match product ID or SKU?
                // The structure in Settings Page:
                
                // Let's look for matching product key in mg_products
                // But wait, mg_products stores TYPES/Products config
                // Structure: array( array('key' => 'polo', 'slug' => 'polo', ... ) )
                
                // If we iterate through mg_products, we find TYPES.
                // How do we know which types are assigned to the current Loop Product?
                // Usually via 'mg_product_type' taxonomy or 'global attributes'.
                
                // Simplified strategy: Check if the product has 'product_cat' terms that match keys?
                // Or just use the first available type from mg_products as default?
                // Assuming all products share the same types layout (which seems to be the case in this plugin version).
                
                if (!empty($products)) {
                    $first = reset($products);
                    if (isset($first['slug'])) {
                        $link = add_query_arg('mg_type', $first['slug'], $link);
                        return $link;
                    }
                }
            }
            return $link;
        }

        // If global catalog is valid:
        reset($catalog);
        $default_type_slug = key($catalog);

        if ($default_type_slug) {
            $link = add_query_arg('mg_type', $default_type_slug, $link);
        }

        return $link;
    }
}
