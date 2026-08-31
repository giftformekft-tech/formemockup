<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once dirname(__DIR__) . '/includes/class-google-ads-product-performance.php';

function expect_gads_threshold_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function expect_gads_threshold_status($expected, $result, $message) {
    $actual = isset($result['status']) ? $result['status'] : null;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// CVR-ből számolt kattintásküszöb
// ---------------------------------------------------------------------------

// n = ln(0.05) / ln(0.98) = 148.28 -> 149 (felfelé kerekítve a küszöb elérésre)
expect_gads_threshold_same(
    149,
    MG_Google_Ads_Product_Performance::recommended_loser_clicks(0.02),
    '2%-os CVR mellett 95%-os bizonyossághoz ~149 kattintás kell.'
);

// A régi, kézzel beírt 30 kattintás mellett egy átlagos termék:
// 0.98^30 = 0.5455 -> az átlagos termékek több mint fele Losert kapna.
$false_loser_rate = pow(1 - 0.02, 30);
if ($false_loser_rate < 0.5) {
    fwrite(STDERR, "FAIL: A 30 kattintásos küszöb téves Loser-aránya nem a várt nagyságrendű.\n");
    exit(1);
}

// Magasabb CVR kevesebb bizonyítékot igényel.
expect_gads_threshold_same(
    14,
    MG_Google_Ads_Product_Performance::recommended_loser_clicks(0.20),
    '20%-os CVR mellett 14 kattintás is elég.'
);

// Nem számítható küszöb: nincs adat, vagy értelmetlen CVR.
foreach (array(0.0, -0.1, 1.0, 1.5) as $invalid) {
    expect_gads_threshold_same(
        0,
        MG_Google_Ads_Product_Performance::recommended_loser_clicks($invalid),
        'Érvénytelen CVR-ből (' . $invalid . ') nem számítható küszöb.'
    );
}

// ---------------------------------------------------------------------------
// Melyik küszöb lép életbe
// ---------------------------------------------------------------------------

$auto = array('loser_clicks' => 30, 'loser_clicks_auto' => 1);
$manual = array('loser_clicks' => 30, 'loser_clicks_auto' => 0);

expect_gads_threshold_same(
    149,
    MG_Google_Ads_Product_Performance::effective_loser_clicks($auto, 0.02),
    'Automatikus módban a CVR-ből számolt küszöb érvényes.'
);
expect_gads_threshold_same(
    30,
    MG_Google_Ads_Product_Performance::effective_loser_clicks($manual, 0.02),
    'Kikapcsolt automatikánál a kézi érték érvényes.'
);
expect_gads_threshold_same(
    30,
    MG_Google_Ads_Product_Performance::effective_loser_clicks($auto, 0.0),
    'Adat híján az automatika a kézi értékre esik vissza.'
);

// ---------------------------------------------------------------------------
// Gördülő Loser-ablak: az elnyomott termék kimászik a Loserből
// ---------------------------------------------------------------------------

$settings = array('loser_window_days' => 90);
expect_gads_threshold_same(
    '2026-06-03',
    MG_Google_Ads_Product_Performance::loser_window_start('2026-01-01', '2026-08-31', $settings),
    'A 90 napos ablak a záródátumtól visszafelé nyílik.'
);
expect_gads_threshold_same(
    '2026-08-01',
    MG_Google_Ads_Product_Performance::loser_window_start('2026-08-01', '2026-08-31', $settings),
    'Az ablak sosem lép a besorolási időszak kezdete elé.'
);

// Teljes történet: 500 kattintás, 0 eladás. Friss ablak: már nincs forgalma,
// mert a Loser címke miatt ki van véve a kampányból.
$suppressed = array('conversions' => 0.0, 'clicks' => 0, 'cost_micros' => 0);
expect_gads_threshold_status(
    'normal',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 500, 90000000, 2, 'clicks', 149, 10000, $suppressed),
    'Az elnyomott termék kifut a Loser-ablakból és újra tesztelhető lesz.'
);

// Ugyanaz a termék, de a friss ablakban is költ és nem hoz: marad Loser.
$still_spending = array('conversions' => 0.0, 'clicks' => 200, 'cost_micros' => 40000000);
expect_gads_threshold_status(
    'loser',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 500, 90000000, 2, 'clicks', 149, 10000, $still_spending),
    'A friss ablakban is eredménytelenül költő termék Loser marad.'
);

// A friss ablak kevés kattintása nem elég a Loser-döntéshez.
$thin = array('conversions' => 0.0, 'clicks' => 40, 'cost_micros' => 8000000);
expect_gads_threshold_status(
    'normal',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 500, 90000000, 2, 'clicks', 149, 10000, $thin),
    'A statisztikai küszöb alatti friss kattintásból nem lesz Loser.'
);

// A Winner a teljes történetből dől el, a friss ablak ürességétől függetlenül.
expect_gads_threshold_status(
    'winner',
    MG_Google_Ads_Product_Performance::classify_metrics(3, 500, 90000000, 2, 'clicks', 149, 10000, $suppressed),
    'A Winner-döntés a teljes történetre épül, nem a friss ablakra.'
);

// $recent nélkül a régi, egyablakos viselkedés marad.
expect_gads_threshold_status(
    'loser',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 500, 90000000, 2, 'clicks', 149, 10000),
    'Friss ablak nélkül a teljes időszak metrikái döntenek.'
);

echo "Google Ads product performance threshold tests passed\n";
