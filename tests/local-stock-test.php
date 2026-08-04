<?php
/**
 * Helyi készlet modul – WordPress nélküli egységteszt.
 *
 * A DB-t érintő metódusokat nem futtatjuk; azt a logikát fedjük le, ami a
 * készlet kulcsát és a nagyker cikkszámot összeköti, mert ha ez elcsúszik,
 * a levonás csendben rossz rekeszből fogy.
 *
 * Futtatás: php tests/local-stock-test.php
 */

define('ABSPATH', __DIR__);

function remove_accents($value) {
    return strtr((string) $value, array(
        'á' => 'a', 'Á' => 'A', 'é' => 'e', 'É' => 'E', 'í' => 'i', 'Í' => 'I',
        'ó' => 'o', 'Ó' => 'O', 'ö' => 'o', 'Ö' => 'O', 'ő' => 'o', 'Ő' => 'O',
        'ú' => 'u', 'Ú' => 'U', 'ü' => 'u', 'Ü' => 'U', 'ű' => 'u', 'Ű' => 'U',
    ));
}

function wp_strip_all_tags($value) {
    return strip_tags((string) $value);
}

function sanitize_title($value) {
    $value = strtolower(remove_accents(wp_strip_all_tags($value)));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    return trim($value, '-');
}

function sanitize_text_field($value) {
    return trim(wp_strip_all_tags($value));
}

function sanitize_key($value) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function wp_parse_args($args, $defaults) {
    return array_merge($defaults, is_array($args) ? $args : array());
}

function get_option($name, $default = false) {
    global $mg_test_options;
    return isset($mg_test_options[$name]) ? $mg_test_options[$name] : $default;
}

function add_action() {}

/** A katalógus indexet adó menedzser tesztdublőre. */
class MG_Variant_Display_Manager {
    public static $catalog = array();

    public static function get_catalog_index() {
        return self::$catalog;
    }
}

require_once dirname(__DIR__) . '/includes/class-local-stock.php';

$failures = 0;

function expect_same($expected, $actual, $message) {
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n\n");
        return;
    }
    echo "ok  – {$message}\n";
}

/* ------------------------------------------------------------ normalize_size */

expect_same('m', MG_Local_Stock::normalize_size('M'), 'Az egyszerű méret kisbetűs kulcsot kap.');
expect_same('2xl', MG_Local_Stock::normalize_size('XXL'), 'Az XXL a nagyker 2XL alakjára képződik le.');
expect_same('2xl', MG_Local_Stock::normalize_size('2XL'), 'A 2XL változatlan marad.');
expect_same('3xl', MG_Local_Stock::normalize_size('XXXL'), 'Az XXXL a 3XL alakra képződik le.');
expect_same('3/6m', MG_Local_Stock::normalize_size('3-6 hó'), 'A hónapos gyerekméret a nagyker alakját kapja.');
expect_same('', MG_Local_Stock::normalize_size('   '), 'Az üres méret üres kulcsot ad.');

// Ez a legfontosabb: a metódust a hívók már normalizált kulcsra is meghívják
// (mátrix oszlop, előnézet sorai). Ha nem idempotens, a perjeles gyerekméretek
// másik rekeszbe kerülnek, mint amiből az export levonna.
foreach (array('m', '2xl', '3xl', '4xl', '2a', '10a', '3/6m', '6/12m', '12/18m') as $key) {
    expect_same($key, MG_Local_Stock::normalize_size($key), "A(z) '{$key}' kulcs újranormalizálva sem változik.");
}

/* -------------------------------------------------------------- get_type_matrix */

MG_Variant_Display_Manager::$catalog = array(
    'polo' => array(
        'label'  => 'Póló',
        'colors' => array(
            'feher'  => array('label' => 'Fehér', 'hex' => '#FFFFFF'),
            'fekete' => array('label' => 'Fekete', 'hex' => '#000000'),
        ),
        // 'XXL' és '2XL' ugyanaz a fizikai méret: egy oszlop lesz belőlük.
        'sizes'  => array('S', 'M', 'XXL', '2XL'),
        'matrix' => array(
            'S'   => array('feher', 'fekete'),
            'M'   => array('feher', 'fekete'),
            'XXL' => array('fekete'),
        ),
    ),
    'body' => array(
        'label'  => 'Body',
        'colors' => array('feher' => array('label' => 'Fehér', 'hex' => '')),
        'sizes'  => array('3-6 hó'),
        'matrix' => array(),
    ),
);

$GLOBALS['mg_test_options'] = array(
    'mg_products' => array(
        array(
            'key'      => 'polo',
            'utt_skus' => array('feher' => 'gi2000as', 'fekete' => 'gi2000bs'),
        ),
        array(
            'key'      => 'body',
            'utt_skus' => array('feher' => 'bb100'),
        ),
    ),
);

$matrix = MG_Local_Stock::get_type_matrix('polo');

expect_same('Póló', $matrix['label'], 'A mátrix a terméktípus címkéjét adja vissza.');
expect_same(
    array('s', 'm', '2xl'),
    array_column($matrix['sizes'], 'key'),
    'Az XXL és 2XL oszlop egyetlen 2xl oszloppá olvad össze.'
);
expect_same(
    array('feher', 'fekete'),
    array_column($matrix['colors'], 'slug'),
    'Minden katalógus szín sorként megjelenik.'
);
expect_same('gi2000as', $matrix['colors'][0]['utt_sku'], 'A színsor melletti nagyker cikkszám a mg_products-ból jön.');

// A size_color_matrix méret CÍMKÉKKEL van kulcsolva, a készlet viszont
// normalizált kulccsal. A leképezésnek át kell hidalnia ezt.
expect_same(true, $matrix['allowed']['feher']['s'], 'A fehér S engedélyezett.');
expect_same(true, $matrix['allowed']['fekete']['2xl'], 'A fekete 2XL az XXL sorból engedélyezett.');
expect_same(false, $matrix['allowed']['feher']['2xl'], 'A fehér 2XL nem létező variáns.');

$body = MG_Local_Stock::get_type_matrix('body');
expect_same(array('3/6m'), array_column($body['sizes'], 'key'), 'A gyerekméret normalizált kulcsot kap.');
expect_same(true, $body['allowed']['feher']['3/6m'], 'Üres size_color_matrix esetén minden variáns engedélyezett.');

expect_same(null, MG_Local_Stock::get_type_matrix('nincs-ilyen'), 'Ismeretlen terméktípusra null a válasz.');

/* ------------------------------------------------------------------ sku_index */

$index = MG_Local_Stock::sku_index();

expect_same(
    array('type' => 'polo', 'color' => 'feher', 'size' => 's'),
    $index['gi2000as-s'],
    'A nagyker cikkszám visszafejthető variánsra.'
);
expect_same(
    array('type' => 'polo', 'color' => 'fekete', 'size' => '2xl'),
    $index['gi2000bs-2xl'],
    'A 2XL cikkszám a fekete színhez tartozik.'
);
expect_same(false, isset($index['gi2000as-2xl']), 'A nem létező fehér 2XL variánshoz nincs cikkszám.');
expect_same(
    array('type' => 'body', 'color' => 'feher', 'size' => '3/6m'),
    $index['bb100-3/6m'],
    'A gyerekméret cikkszáma is visszafejthető.'
);

/* ------------------------------------------------------------- parse_goods_in */

$csv = "gi2000as-s,10\ngi2000bs-2xl;4\nbb100-3/6m,2\nismeretlen-xl,5\n\nrossz-sor";
$result = MG_Local_Stock::parse_goods_in($csv, false);

expect_same(3, count($result['rows']), 'Három sor ismerhető fel a bevételezésből.');
expect_same(16, $result['total'], 'A felismert darabszámok összege helyes.');
expect_same('polo', $result['rows'][0]['type'], 'Az első bevételezett sor a pólóhoz tartozik.');
expect_same(4, $result['rows'][1]['qty'], 'A pontosvesszős elválasztás is működik.');
expect_same('3/6m', $result['rows'][2]['size'], 'A perjeles méret bevételezésnél sem sérül.');
expect_same(2, count($result['unknown']), 'Az ismeretlen cikkszám és a hibás sor a nem felismertek közé kerül.');

/* ----------------------------------------------------------------------- vége */

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} teszt bukott el.\n");
    exit(1);
}

echo "\nMinden teszt sikeres.\n";
