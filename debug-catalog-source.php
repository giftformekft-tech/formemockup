<?php
/**
 * IDEIGLENES DEBUG ELLENŐRZŐ
 * 
 * Megjeleníti a HTML-ben commentként, hogy honnan jönnek a catalog adatok.
 * 
 * Használat:
 * 1. Nyiss meg egy terméket a frontenden
 * 2. Jobb klikk → "View Page Source" (Ctrl+U)
 * 3. Keresd meg: "CATALOG SOURCE DEBUG"
 * 4. Láthatod: honnan jönnek az adatok (PHP FILE vagy DATABASE)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', function() {
    // Csak termék oldalakon jelenjen meg
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    
    // Ellenőrizzük honnan jön a katalógus
    $global_catalog = function_exists('mg_get_global_catalog') ? mg_get_global_catalog() : array();
    $db_products = get_option('mg_products', array());
    
    $source = 'UNKNOWN';
    $product_count = 0;
    $source_details = '';
    
    // Fájl ellenőrzés
    $config_file = dirname(dirname(__FILE__)) . '/includes/config/global-attributes.php';
    $file_exists = file_exists($config_file);
    $file_readable = is_readable($config_file);
    $file_size = $file_exists ? filesize($config_file) : 0;
    
    if (!empty($global_catalog)) {
        $source = 'PHP FILE (global-attributes.php)';
        $product_count = count($global_catalog);
        $source_details = 'Using fast file-based config';
    } elseif (!empty($db_products)) {
        $source = 'DATABASE (wp_options.mg_products)';
        $product_count = count($db_products);
        $source_details = 'Using database option (slower)';
    } else {
        $source_details = 'No catalog data found!';
    }
    
    // HTML comment kimenet
    echo "\n<!-- ========================================= -->\n";
    echo "<!-- CATALOG SOURCE DEBUG                      -->\n";
    echo "<!-- ========================================= -->\n";
    echo "<!-- Data Source: " . esc_html($source) . " -->\n";
    echo "<!-- Product Types: " . esc_html($product_count) . " -->\n";
    echo "<!-- Details: " . esc_html($source_details) . " -->\n";
    echo "<!-- ========================================= -->\n";
    echo "<!-- FILE CHECK:                               -->\n";
    echo "<!-- Config File: " . esc_html($config_file) . " -->\n";
    echo "<!-- File Exists: " . ($file_exists ? 'YES' : 'NO') . " -->\n";
    echo "<!-- File Readable: " . ($file_readable ? 'YES' : 'NO') . " -->\n";
    echo "<!-- File Size: " . esc_html($file_size) . " bytes -->\n";
    echo "<!-- Global Catalog Count: " . count($global_catalog) . " -->\n";
    echo "<!-- DB Products Count: " . count($db_products) . " -->\n";
    echo "<!-- ========================================= -->\n";
    echo "<!-- Timestamp: " . date('Y-m-d H:i:s') . " -->\n";
    echo "<!-- ========================================= -->\n";
    echo "<!-- Ha 'PHP FILE' látható, akkor jól működik! -->\n";
    echo "<!-- Ha 'File Size' = 1170 bytes, akkor ÜRES!  -->\n";
    echo "<!-- ========================================= -->\n\n";
}, 999);
