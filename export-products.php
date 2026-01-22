<?php
/**
 * EGYSZERŰ EXPORT SCRIPT
 * 
 * Ez a file egyszerűen kiírja a mg_products tartalmát olvasható formában.
 * 
 * HASZNÁLAT:
 * 1. Töltsd fel a plugin root-ba
 * 2. Navigálj: yoursite.com/wp-content/plugins/mockup-generator/export-products.php
 * 3. Másold ki a teljes outputot
 * 4. Cseréld le vele a global-attributes.php tartalmát
 * 5. TÖRÖLD EZT A FÁJLT a szerverről!
 */

// WordPress betöltés
require_once '../../../wp-load.php';

// Biztonság
if (!current_user_can('manage_options')) {
    die('Unauthorized');
}

// Adatok lekérése
$mg_products = get_option('mg_products', array());

if (empty($mg_products)) {
    echo "HIBA: Nincs adat az mg_products opcióban!";
    exit;
}

// Header
header('Content-Type: text/plain; charset=utf-8');

echo "<?php\n";
echo "/**\n";
echo " * Global attributes - Migrated from database\n";
echo " * Migration date: " . date('Y-m-d H:i:s') . "\n";
echo " * Product types: " . count($mg_products) . "\n";
echo " */\n\n";
echo "return array(\n";
echo "    'products' => ";
echo var_export($mg_products, true);
echo ",\n";
echo ");\n";

echo "\n\n";
echo "// ========================================\n";
echo "// MÁSOLD KI A FENTI TELJES TARTALMAT!\n";
echo "// ========================================\n";
