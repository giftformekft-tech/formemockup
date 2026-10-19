<?php
/** php tests/variant-name-inflector-test.php */
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/class-variant-name-inflector.php';

$cases = array(
    array('Februári szülinapos póló', 'Február', 'Március', 'Márciusi szülinapos póló'),
    array('Februári szülinapos póló', 'Február', 'Szeptember', 'Szeptemberi szülinapos póló'),
    array('A legjobbak februárban születnek', 'Február', 'Október', 'A legjobbak októberben születnek'),
    array('A legjobbak szeptemberben születnek', 'Szeptember', 'Május', 'A legjobbak májusban születnek'),
    array('FEBRUÁRI KIRÁLYNŐ', 'Február', 'Április', 'ÁPRILISI KIRÁLYNŐ'),
    array('Az élet 50 felett kezdődik', '50', '60', 'Az élet 60 felett kezdődik'),
    array('60 éves szülinapos póló', '60', '40', '40 éves szülinapos póló'),
    array('1960-as születésű legenda', '1960', '1975', '1975-ös születésű legenda'),
    array('1960-as születésű legenda', '1960', '1966', '1966-os születésű legenda'),
    array('1966-os évjárat', '1966', '1950', '1950-es évjárat'),
    array('50-es évek', '50', '60', '60-as évek'),
    array('Legenda 1980 óta', '1980', '1990', 'Legenda 1990 óta'),
    array('Januáriak a legjobbak', 'Január', 'December', 'Decemberiek a legjobbak'),
    array('Szülinapos póló – Január', 'Január', 'Március', 'Szülinapos póló – Március'),
);
$failed = 0;
foreach ($cases as $case) {
    list($name, $base, $value, $expected) = $case;
    $actual = MG_Variant_Name_Inflector::replace($name, $base, $value);
    if ($actual !== $expected) {
        $failed++;
        echo "FAIL: {$name} [{$base} → {$value}] expected '{$expected}', got '{$actual}'\n";
    }
}
// The base must be a whole value: 50 must not match inside 150 or 500.
foreach (array(array('150 éves', '50'), array('500 darab', '50'), array('Születésnapos póló', 'Február')) as $case) {
    if (MG_Variant_Name_Inflector::replace($case[0], $case[1], '60') !== '') {
        $failed++;
        echo "FAIL: '{$case[1]}' must not match in '{$case[0]}'\n";
    }
}
echo $failed === 0 ? "OK\n" : "{$failed} failure(s)\n";
exit($failed === 0 ? 0 : 1);
