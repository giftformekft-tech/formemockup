<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once dirname(__DIR__) . '/includes/class-google-ads-product-performance.php';
require_once dirname(__DIR__) . '/includes/class-product-creator.php';

function expect_gads_offer_id_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// A Merchant feed a `<SKU>_<type_slug>` azonosítót a WooCommerce SKU-ból képzi,
// az pedig csupa nagybetű. A Google Ads riport ugyanezt kisbetűsítve adja
// vissza. A két oldal csak normalizálás után találkozik.
$base_sku = MG_Product_Creator::sanitize_sku('forme10001');
expect_gads_offer_id_same('FORME10001', $base_sku, 'A plugin SKU-i csupa nagybetűsek.');

$feed_offer_id = $base_sku . '_polo';
$google_offer_id = 'forme10001_polo';

expect_gads_offer_id_same(
    MG_Google_Ads_Product_Performance::normalize_offer_id($feed_offer_id),
    MG_Google_Ads_Product_Performance::normalize_offer_id($google_offer_id),
    'A feedből és a Google Ads riportból származó offer ID normalizálva megegyezik.'
);

// A párosítás pontosan így történik a besoroláskor: a térkép kulcsa a feed
// azonosítójából, a keresett érték a Google által küldött sorból származik.
$map = array(
    MG_Google_Ads_Product_Performance::normalize_offer_id($feed_offer_id) => 4321,
);
$lookup = MG_Google_Ads_Product_Performance::normalize_offer_id($google_offer_id);
expect_gads_offer_id_same(
    4321,
    isset($map[$lookup]) ? $map[$lookup] : 0,
    'A kisbetűs Ads offer ID megtalálja a nagybetűs SKU-ból épített termékét.'
);

expect_gads_offer_id_same(
    'forme10001_polo',
    MG_Google_Ads_Product_Performance::normalize_offer_id('  FORME10001_Polo  '),
    'A normalizálás levágja a whitespace-t és kisbetűsít.'
);

expect_gads_offer_id_same(
    '',
    MG_Google_Ads_Product_Performance::normalize_offer_id(null),
    'A hiányzó azonosító üres sztringgé normalizálódik.'
);

echo "Google Ads product performance offer ID tests passed\n";
