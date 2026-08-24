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

require_once dirname(__DIR__) . '/admin/class-temu-export-page.php';

function expect_temu_export_sku($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

expect_temu_export_sku('ABC123-FERFI', MG_Temu_Export_Page::build_family_sku('ABC123', 'ferfi-polo'), 'Male T-shirt gets a separate family SKU.');
expect_temu_export_sku('ABC123-FERFI', MG_Temu_Export_Page::build_family_sku('ABC123', 'ferfi_polo'), 'Male type aliases resolve to the same family SKU.');
expect_temu_export_sku('ABC123-NOI', MG_Temu_Export_Page::build_family_sku('ABC123', 'noi-polo'), 'Female T-shirt gets a separate family SKU.');
expect_temu_export_sku('ABC123-NOI', MG_Temu_Export_Page::build_family_sku('ABC123', 'noi_polo'), 'Female type aliases resolve to the same family SKU.');
expect_temu_export_sku('ABC123-GYEREK', MG_Temu_Export_Page::build_family_sku('ABC123', 'gyerek-polo'), 'Child T-shirt keeps the child family suffix.');
expect_temu_export_sku('ABC123-PREMIUM-NOI-POLO', MG_Temu_Export_Page::build_family_sku('ABC123', 'premium-noi-polo'), 'Premium aliases remain distinct from the base families.');
expect_temu_export_sku('ABC123-HOODIE', MG_Temu_Export_Page::build_family_sku('ABC123', 'hoodie'), 'Unknown types use a stable normalized suffix.');

$source = file_get_contents(dirname(__DIR__) . '/admin/class-temu-export-page.php');
if (substr_count($source, 'self::build_family_sku($base_sku, $type_slug)') < 2) {
    fwrite(STDERR, "FAIL: preview and export rows must share the family SKU helper.\n");
    exit(1);
}
if (strpos($source, 'in_array($normalized_size') !== false) {
    fwrite(STDERR, "FAIL: size-based child SKU inference must be removed.\n");
    exit(1);
}

echo "Temu export SKU tests passed.\n";
