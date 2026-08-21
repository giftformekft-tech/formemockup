<?php

require_once dirname(__DIR__) . '/includes/class-temu-name-filter.php';

function expect_temu_name_filter_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$terms = MG_Temu_Name_Filter::parse_terms("# márka lista\r\n Marvel \r\nStar Wars\n\nMarvel\n");
expect_temu_name_filter_same(array('Marvel', 'Star Wars'), $terms, 'Terms accept CRLF, ignore comments/blanks, and deduplicate case-insensitively.');

expect_temu_name_filter_same(
    'PÓLÓ SHIRT',
    MG_Temu_Name_Filter::filter('PÓLÓ marvel SHIRT', array('Marvel')),
    'Matching is case-insensitive.'
);
expect_temu_name_filter_same(
    'Különleges Póló',
    MG_Temu_Name_Filter::filter('Különleges ÁRNYÉK Póló', array('árnyék')),
    'Accented terms are matched case-insensitively.'
);
expect_temu_name_filter_same(
    'Programozó póló',
    MG_Temu_Name_Filter::filter('Programozó C++ (Official) [2026] póló', array('C++ (Official) [2026]')),
    'Regex metacharacters are treated as literal text.'
);
expect_temu_name_filter_same(
    'Marvelous Shirt',
    MG_Temu_Name_Filter::filter('Marvelous Marvel Shirt', array('Marvel')),
    'A single word is not removed inside a larger alphanumeric word.'
);
expect_temu_name_filter_same(
    'Classic Shirt',
    MG_Temu_Name_Filter::filter('Classic Star Wars Shirt', array('Star Wars')),
    'Multi-word phrases are removed.'
);
expect_temu_name_filter_same(
    'Classic Shirt',
    MG_Temu_Name_Filter::filter('Classic BMWvel Shirt', array('BMW')),
    'An attached Hungarian -vel suffix is removed with the brand.'
);
expect_temu_name_filter_same(
    'Classic Shirt',
    MG_Temu_Name_Filter::filter('Classic Audival Shirt', array('Audi')),
    'An attached Hungarian -val suffix is removed with the brand.'
);
expect_temu_name_filter_same(
    'Classic Shirt',
    MG_Temu_Name_Filter::filter('Classic BMW-wel Shirt', array('BMW')),
    'A hyphenated Hungarian suffix is removed with the brand.'
);
expect_temu_name_filter_same(
    'Classic Shirt',
    MG_Temu_Name_Filter::filter('Classic BMW ben Shirt', array('BMW')),
    'A space-separated Hungarian suffix is removed with the brand.'
);
expect_temu_name_filter_same(
    'Classic Shirt',
    MG_Temu_Name_Filter::filter('Classic BMW-s Shirt', array('BMW')),
    'A Hungarian brand adjective suffix is removed with the brand.'
);
expect_temu_name_filter_same(
    'Classic Shirt',
    MG_Temu_Name_Filter::filter('Classic BMW Shirt', array('BMW')),
    'An exact brand occurrence remains supported.'
);
expect_temu_name_filter_same(
    'Cool, Shirt',
    MG_Temu_Name_Filter::filter('Cool  Marvel ,  Shirt', array('Marvel')),
    'Whitespace and punctuation spacing are normalized after removal.'
);
expect_temu_name_filter_same(
    'Marvel',
    MG_Temu_Name_Filter::filter('Marvel', array('marvel')),
    'A completely removed name falls back to its original value.'
);
expect_temu_name_filter_same(
    'Marvelous Shirt',
    MG_Temu_Name_Filter::filter('Marvelous Shirt', array('Marvel')),
    'A larger word is not matched as a brand plus suffix.'
);

$defaults = MG_Temu_Name_Filter::default_terms();
expect_temu_name_filter_same(true, in_array('Minecraft', $defaults, true), 'Starter list contains a game/franchise entry.');
expect_temu_name_filter_same(true, in_array('Star Wars', $defaults, true), 'Starter list contains a movie/franchise entry.');
expect_temu_name_filter_same(true, in_array('BMW', $defaults, true), 'Starter list contains a car brand.');
expect_temu_name_filter_same(true, in_array('BWM', $defaults, true), 'Starter list contains the common BWM typo variant.');
expect_temu_name_filter_same('Classic Shirt', MG_Temu_Name_Filter::filter('Classic BWM Shirt', $defaults), 'The starter-list BWM typo is actively filtered.');
expect_temu_name_filter_same(true, in_array('Ducati', $defaults, true), 'Starter list contains a motorcycle brand.');
expect_temu_name_filter_same(true, in_array('Nike', $defaults, true), 'Starter list contains a sports/fashion brand.');
expect_temu_name_filter_same(true, in_array('Samsung', $defaults, true), 'Starter list contains a technology brand.');
expect_temu_name_filter_same(true, strpos(MG_Temu_Name_Filter::default_terms_text(), '# AUTÓMÁRKÁK') !== false, 'Starter list exposes logical comment groups.');
expect_temu_name_filter_same('Minecraft Shirt', MG_Temu_Name_Filter::filter('Minecraft Shirt', array()), 'An explicitly empty term list disables filtering.');

echo "Temu name filter tests passed.\n";
