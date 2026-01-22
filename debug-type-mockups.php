<?php
/**
 * Debug script to check type mockup data
 * 
 * Add this line to functions.php temporarily:
 * require_once get_stylesheet_directory() . '/../plugins/mockup-generator/debug-type-mockups.php';
 * 
 * Then visit a product page and check browser console for "MG_DEBUG_MOCKUPS"
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', function() {
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    
    global $post;
    if (!$post) {
        return;
    }
    
    $product = wc_get_product($post->ID);
    if (!$product) {
        return;
    }
    
    // Check SKU
    $sku = $product->get_sku();
    echo "\n<!-- MG DEBUG: Product SKU = " . esc_html($sku) . " -->\n";
    
    // Check mg_mockups directory
    $uploads = wp_upload_dir();
    $mg_dir = $uploads['basedir'] . '/mg_mockups';
    echo "<!-- MG DEBUG: mg_mockups dir = " . esc_html($mg_dir) . " -->\n";
    echo "<!-- MG DEBUG: mg_mockups exists = " . (is_dir($mg_dir) ? 'YES' : 'NO') . " -->\n";
    
    if ($sku && is_dir($mg_dir)) {
        $sku_clean = strtoupper(preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($sku)));
        $sku_dir = $mg_dir . '/' . $sku_clean;
        echo "<!-- MG DEBUG: SKU dir = " . esc_html($sku_dir) . " -->\n";
        echo "<!-- MG DEBUG: SKU dir exists = " . (is_dir($sku_dir) ? 'YES' : 'NO') . " -->\n";
        
        if (is_dir($sku_dir)) {
            $files = glob($sku_dir . '/*.webp');
            echo "<!-- MG DEBUG: Files in SKU dir: " . count($files) . " -->\n";
            foreach ($files as $file) {
                echo "<!-- MG DEBUG: - " . esc_html(basename($file)) . " -->\n";
            }
        }
    }
    
    // Check MG_VARIANT_DISPLAY data
    ?>
    <script>
    if (typeof MG_VARIANT_DISPLAY !== 'undefined') {
        console.log('MG_DEBUG_MOCKUPS:', {
            visuals: MG_VARIANT_DISPLAY.visuals || {},
            typeMockups: (MG_VARIANT_DISPLAY.visuals && MG_VARIANT_DISPLAY.visuals.typeMockups) || {},
            types: Object.keys(MG_VARIANT_DISPLAY.types || {})
        });
    } else {
        console.log('MG_DEBUG_MOCKUPS: MG_VARIANT_DISPLAY not found');
    }
    </script>
    <?php
}, 999);
