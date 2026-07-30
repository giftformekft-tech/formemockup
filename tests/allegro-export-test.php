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

function sanitize_text_field($value) {
    return trim(wp_strip_all_tags($value));
}

require_once dirname(__DIR__) . '/includes/class-allegro-exporter.php';

function expect_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

expect_same('fekete', MG_Allegro_Exporter::map_color('black', 'Black'), 'English black maps to the Allegro core colour.');
expect_same('fehér', MG_Allegro_Exporter::map_color('feher', 'Fehér'), 'Hungarian white maps with accents.');
expect_same('kék', MG_Allegro_Exporter::map_color('navy', 'Navy'), 'Navy maps to the Allegro blue core colour.');
expect_same('', MG_Allegro_Exporter::map_color('mint-pattern', 'Mintás'), 'Unknown colours are not guessed.');
expect_same('', MG_Allegro_Exporter::map_color('black', 'Black', array(), false), 'Strict profiles do not fall back to a guessed colour.');

expect_same('XXL', MG_Allegro_Exporter::map_size('ferfi-polo', '2XL'), 'Woo 2XL maps to Allegro XXL.');
expect_same('3XL', MG_Allegro_Exporter::map_size('ferfi-polo', 'XXXL'), 'Woo XXXL maps to Allegro 3XL.');
expect_same('', MG_Allegro_Exporter::map_size('gyerek-polo', '1/2'), 'Age ranges require an explicit height mapping.');
expect_same('86-92', MG_Allegro_Exporter::map_size('gyerek-polo', '1/2', array(
    'gyerek-polo' => array('1/2' => '86-92'),
)), 'An explicit child-size mapping wins.');
expect_same('', MG_Allegro_Exporter::map_size('ferfi-polo', 'XL', array(), false), 'Strict profiles require a saved size mapping.');

expect_same('ABC-FERFI-POLO', MG_Allegro_Exporter::build_parent_sku('ABC', 'ferfi-polo'), 'Parent SKU groups the design and product type.');
expect_same('ABC-FERFI-POLO-FEKETE-2XL', MG_Allegro_Exporter::build_sku('ABC', 'ferfi-polo', 'fekete', '2XL'), 'Variant SKU is stable and contains the exact combination.');
expect_same('87913', MG_Allegro_Exporter::default_category_map()['ferfi-polo'], 'The verified Allegro men T-shirt category is the default.');
expect_same('76104', MG_Allegro_Exporter::default_category_map()['noi-polo'], 'The verified Allegro women T-shirt category is the default.');
expect_same('89528', MG_Allegro_Exporter::default_category_map()['gyerek-polo'], 'The verified Allegro child T-shirt category is the default.');
expect_same(3, count(MG_Allegro_Exporter::category_profiles()), 'Only child, men and women T-shirt profiles are enabled initially.');
expect_same(false, in_array('13XL', MG_Allegro_Exporter::allowed_sizes_for_category('87913'), true), 'Men and women retain separate Allegro size dictionaries.');
expect_same(true, in_array('13XL', MG_Allegro_Exporter::allowed_sizes_for_category('76104'), true), 'The women profile contains the current 13XL Allegro size.');
expect_same(true, in_array('86-92', MG_Allegro_Exporter::allowed_sizes_for_category('89528'), true), 'The child profile contains current height ranges.');

$headers = MG_Allegro_Exporter::headers();
foreach (array('sku', 'parent_sku', 'color', 'manufacturer_color', 'size', 'price_huf', 'stock', 'image_url', 'category_id') as $required) {
    if (!in_array($required, $headers, true)) {
        fwrite(STDERR, "FAIL: missing CSV header {$required}\n");
        exit(1);
    }
}

echo "Allegro export tests passed.\n";
