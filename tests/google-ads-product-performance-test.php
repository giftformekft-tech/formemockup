<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once dirname(__DIR__) . '/includes/class-google-ads-product-performance.php';

function expect_gads_performance_status($expected, $result, $message) {
    $actual = isset($result['status']) ? $result['status'] : null;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

expect_gads_performance_status(
    'winner',
    MG_Google_Ads_Product_Performance::classify_metrics(2, 1, 0, 2, 'spend', 35, 10000),
    'Two attributed purchases classify the product as Winner.'
);

expect_gads_performance_status(
    'normal',
    MG_Google_Ads_Product_Performance::classify_metrics(1.99, 100, 50000000, 2, 'spend', 35, 10000),
    'A product with a purchase is not a spend-based Loser before reaching Winner.'
);

expect_gads_performance_status(
    'loser',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 1, 10000000000, 2, 'spend', 35, 10000),
    'Zero purchases at exactly the configured HUF spend threshold is Loser.'
);

expect_gads_performance_status(
    'normal',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 100, 9999990000, 2, 'spend', 35, 10000),
    'Clicks do not cause Loser status while spend mode is selected.'
);

expect_gads_performance_status(
    'loser',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 35, 0, 2, 'clicks', 35, 10000),
    'The optional click mode still honors its configured threshold.'
);

echo "Google Ads product performance tests passed.\n";
