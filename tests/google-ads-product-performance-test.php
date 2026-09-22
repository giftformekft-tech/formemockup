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
    MG_Google_Ads_Product_Performance::classify_metrics(2, 1, 0, 2, 'spend', 0, 10000),
    'Two attributed purchases classify the product as Winner.'
);

expect_gads_performance_status(
    'normal',
    MG_Google_Ads_Product_Performance::classify_metrics(1.99, 100, 50000000, 2, 'spend', 0, 10000, 7),
    'A product with a purchase is not a spend-based Loser before reaching Winner.'
);

expect_gads_performance_status(
    'loser',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 1, 10000000000, 2, 'spend', 0, 10000, 7),
    'Zero purchases at exactly the configured HUF spend threshold is Loser.'
);

expect_gads_performance_status(
    'normal',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 100, 9999990000, 2, 'spend', 0, 10000, 7),
    'Clicks do not cause Loser status while spend mode is selected.'
);

expect_gads_performance_status(
    'normal',
    MG_Google_Ads_Product_Performance::classify_metrics(0, 1000, 0, 2, 'cpa', 3000, 10000, 30),
    'Click count alone must never classify a product as Loser.'
);

foreach (array(
    array('normal', 0, 8999, 7, 'The test budget must be exhausted before a zero-sale product is Loser.'),
    array('loser', 0, 9000, 7, 'Zero sales at three target CPAs after seven observed days is Loser.'),
    array('normal', 0, 100000, 6, 'A spend spike cannot bypass the minimum observation period.'),
    array('normal', 0, 100000, 0, 'Missing activity dates cannot justify a Loser decision.'),
    array('loser', 0.01, 100000, 7, 'A tiny attributed conversion must not give unlimited spending protection.'),
    array('normal', 1.5, 13499, 7, 'Attributed purchases proportionally increase the CPA test budget.'),
    array('loser', 1.5, 13500, 7, 'A sufficiently expensive non-Winner can become Loser despite having purchases.'),
    array('winner', 2, 100000, 1, 'The existing Winner rule retains priority.'),
) as $case) {
    expect_gads_performance_status($case[0], MG_Google_Ads_Product_Performance::classify_metrics($case[1], 100, $case[2] * 1000000, 2, 'cpa', 3000, 10000, $case[3]), $case[4]);
}
expect_gads_performance_status('normal', MG_Google_Ads_Product_Performance::classify_metrics(0, 1000, 100000000000, 2, 'cpa', 0, 10000, 30), 'Missing CPA must not turn every product into a Loser.');
expect_gads_performance_status('normal', MG_Google_Ads_Product_Performance::classify_metrics(0, 1000, 100000000000, 2, 'spend', 0, 10000, 6), 'The fixed budget also respects the minimum observation period.');

// ROAS mode: 300% break-even ROAS, 10 000 Ft minimum test budget.
foreach (array(
    array('normal', 0, 0, 9999, 7, 'Zero revenue must first exhaust the minimum test budget.'),
    array('loser', 0, 0, 10000, 7, 'Zero revenue at the minimum test budget is Loser.'),
    array('normal', 0.5, 20000, 19999, 7, 'Revenue proportionally increases the ROAS test budget.'),
    array('loser', 0.5, 20000, 20000, 7, 'A product at a third of break-even ROAS is Loser.'),
    array('normal', 1, 2000, 9999, 7, 'Low revenue never lowers the minimum test budget.'),
    array('loser', 1, 2000, 10000, 7, 'Low revenue after the minimum test budget is Loser.'),
    array('normal', 0, 0, 100000, 6, 'ROAS mode respects the minimum observation period.'),
    array('winner', 2, 0, 100000, 7, 'The Winner rule retains priority in ROAS mode.'),
) as $case) {
    expect_gads_performance_status($case[0], MG_Google_Ads_Product_Performance::classify_metrics($case[1], 100, $case[3] * 1000000, 2, 'roas', 0, 10000, $case[4], 7, $case[2], 300), $case[5]);
}
expect_gads_performance_status('normal', MG_Google_Ads_Product_Performance::classify_metrics(0, 1000, 100000000000, 2, 'roas', 3000, 10000, 30, 7, 0, 0), 'Missing ROAS must not turn every product into a Loser.');
expect_gads_performance_status('normal', MG_Google_Ads_Product_Performance::classify_metrics(0.5, 100, 13500000000, 2, 'roas', 3000, 10000, 30, 7, 20000, 300), 'Fixed CPA must not apply in ROAS mode.');

echo "Google Ads product performance tests passed.\n";
