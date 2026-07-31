<?php

define('ABSPATH', __DIR__);

function wp_strip_all_tags($value) {
    return strip_tags((string) $value);
}

function remove_accents($value) {
    return strtr((string) $value, array(
        'á' => 'a', 'Á' => 'A', 'é' => 'e', 'É' => 'E', 'í' => 'i', 'Í' => 'I',
        'ó' => 'o', 'Ó' => 'O', 'ö' => 'o', 'Ö' => 'O', 'ő' => 'o', 'Ő' => 'O',
        'ú' => 'u', 'Ú' => 'U', 'ü' => 'u', 'Ü' => 'U', 'ű' => 'u', 'Ű' => 'U',
    ));
}

function sanitize_title($value) {
    $value = strtolower(remove_accents(wp_strip_all_tags($value)));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    return trim($value, '-');
}

require_once dirname(__DIR__) . '/includes/class-temu-api-exporter.php';

function expect_temu_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

expect_temu_same('TEMU-ABC-FERFI-POLO', MG_Temu_API_Exporter::build_parent_sku('ABC', 'ferfi-polo'), 'Temu product families are separated by product type and SKU namespace.');
expect_temu_same('TEMU-ABC-FERFI-POLO-FEKETE-2XL', MG_Temu_API_Exporter::build_sku('ABC', 'ferfi-polo', 'fekete', '2XL'), 'Temu SKU cannot collide with the Allegro SKU.');
expect_temu_same("Men's Clothing / T-Shirts", MG_Temu_API_Exporter::category_name('ferfi-polo'), 'Men category name is Temu-specific.');
expect_temu_same("Women's Clothing / T-Shirts", MG_Temu_API_Exporter::category_name('noi-polo'), 'Women category name is Temu-specific.');
expect_temu_same("Kids' Clothing / T-Shirts", MG_Temu_API_Exporter::category_name('gyerek-polo'), 'Kids category name is Temu-specific.');

$headers = MG_Temu_API_Exporter::headers();
foreach (array('marketplace', 'sku', 'parent_sku', 'type', 'color', 'size', 'image_url', 'temu_common_image_url', 'weight_g', 'temu_category_name') as $required) {
    if (!in_array($required, $headers, true)) {
        fwrite(STDERR, "FAIL: missing Temu API CSV header {$required}\n");
        exit(1);
    }
}
expect_temu_same(false, in_array('category_id', $headers, true), 'Allegro category IDs never enter the Temu API export.');

echo "Temu API export tests passed.\n";
