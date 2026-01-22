<?php
/**
 * Migration Script: Database to Global Config
 * 
 * This script reads the existing mg_products option from the WordPress database
 * and outputs it as a formatted PHP array that can be copied into the
 * global-attributes.php config file.
 * 
 * Usage:
 * 1. Navigate to WordPress root directory
 * 2. Run: php -f wp-content/plugins/mockup-generator/tools/migrate-to-global-config.php
 * 3. Copy the output to includes/config/global-attributes.php
 * 
 * OR use from browser (admin only):
 * yoursite.com/wp-content/plugins/mockup-generator/tools/migrate-to-global-config.php
 */

// WordPress bootstrap
$wp_load_paths = [
    __DIR__ . '/../../../../wp-load.php',  // From plugin tools directory
    __DIR__ . '/../../../wp-load.php',      // Alternative path
    dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php',  // Absolute
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die("Error: Could not find wp-load.php. Please run this script from the WordPress root directory or ensure WordPress is installed.\n");
}

// Security check for web access
if (php_sapi_name() !== 'cli') {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    echo '<pre>';
}

// Get the mg_products data
$mg_products = get_option('mg_products', array());

if (empty($mg_products)) {
    echo "WARNING: No data found in 'mg_products' option.\n";
    echo "The database appears to be empty. Nothing to migrate.\n\n";
    exit(1);
}

// Format the data nicely
function format_array_recursive($array, $indent = 0) {
    if (!is_array($array)) {
        return var_export($array, true);
    }
    
    $is_assoc = array_keys($array) !== range(0, count($array) - 1);
    $indent_str = str_repeat('    ', $indent);
    $inner_indent = str_repeat('    ', $indent + 1);
    
    if (empty($array)) {
        return 'array()';
    }
    
    $output = "array(\n";
    foreach ($array as $key => $value) {
        if ($is_assoc) {
            $output .= $inner_indent . var_export($key, true) . ' => ';
        } else {
            $output .= $inner_indent;
        }
        
        if (is_array($value)) {
            $output .= format_array_recursive($value, $indent + 1);
        } else {
            $output .= var_export($value, true);
        }
        $output .= ",\n";
    }
    $output .= $indent_str . ')';
    
    return $output;
}

// Output header
echo "================================================================================\n";
echo "MIGRATION OUTPUT: mg_products -> global-attributes.php\n";
echo "================================================================================\n\n";
echo "Found " . count($mg_products) . " product type(s) in the database.\n\n";

// List product types
echo "Product types found:\n";
foreach ($mg_products as $product) {
    if (isset($product['key']) && isset($product['label'])) {
        $color_count = isset($product['colors']) && is_array($product['colors']) ? count($product['colors']) : 0;
        $size_count = isset($product['sizes']) && is_array($product['sizes']) ? count($product['sizes']) : 0;
        echo "  - {$product['label']} ({$product['key']}): {$color_count} colors, {$size_count} sizes\n";
    }
}

echo "\n";
echo "================================================================================\n";
echo "COPY THE CONTENT BELOW TO: includes/config/global-attributes.php\n";
echo "================================================================================\n\n";

// Output the formatted PHP code
echo "<?php\n";
echo "/**\n";
echo " * Global attributes source of truth.\n";
echo " *\n";
echo " * IMPORTANT:\n";
echo " * - This file contains all product types, colors, sizes, and pricing.\n";
echo " * - All generated WooCommerce products use these same configurations.\n";
echo " * - Migrated from database on: " . date('Y-m-d H:i:s') . "\n";
echo " */\n\n";
echo "return array(\n";
echo "    'products' => " . format_array_recursive($mg_products, 1) . ",\n";
echo ");\n";

echo "\n================================================================================\n";
echo "MIGRATION COMPLETE\n";
echo "================================================================================\n";

if (php_sapi_name() !== 'cli') {
    echo '</pre>';
}
