<?php
/**
 * Temu XLSX CLI teszt — böngésző és WordPress nélkül futtatható.
 *
 * Használat:
 *   php tools/temu-xlsx-cli.php <sablon.xlsx> <adatok.csv> <kimenet.xlsx>
 *
 * A CSV formátuma azonos a plugin Temu CSV exportjával
 * (fejléc: Termék neve;SKU;Sub SKU;Szín;Méret;Leírás;Kép URL — ; vagy ,
 * elválasztóval, UTF-8, opcionális BOM-mal).
 *
 * A generálás után automatikusan ellenőrzi, hogy a kimeneti ZIP-ben
 * KIZÁRÓLAG a Template munkalap XML-je tér el a sablontól.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ez a szkript csak parancssorból futtatható.\n");
    exit(1);
}

require __DIR__ . '/../includes/class-temu-xlsx-writer.php';

function cli_fail($msg) {
    fwrite(STDERR, "HIBA: {$msg}\n");
    exit(1);
}

if ($argc !== 4) {
    fwrite(STDERR, "Használat: php tools/temu-xlsx-cli.php <sablon.xlsx> <adatok.csv> <kimenet.xlsx>\n");
    exit(1);
}

list(, $template_path, $csv_path, $out_path) = $argv;

if (!is_file($template_path)) {
    cli_fail("A sablon nem található: {$template_path}");
}
if (!is_file($csv_path)) {
    cli_fail("A CSV nem található: {$csv_path}");
}

// ---------------------------------------------------------------- CSV beolvasás

$csv_cols = [
    'Termék neve' => 'name',
    'SKU'         => 'sku',
    'Sub SKU'     => 'sub_sku',
    'Szín'        => 'color',
    'Méret'       => 'size',
    'Leírás'      => 'desc',
    'Kép URL'     => 'img',
];

$raw = file_get_contents($csv_path);
if ($raw === false) {
    cli_fail('A CSV nem olvasható.');
}
// UTF-8 BOM eltávolítása
if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
    $raw = substr($raw, 3);
}
// elválasztó automatikus felismerése (; vagy ,)
$sample = substr($raw, 0, 4096);
$delim = substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';

$fh = fopen('php://temp', 'r+');
fwrite($fh, $raw);
rewind($fh);

$header = fgetcsv($fh, 0, $delim);
if (!$header) {
    cli_fail('Üres CSV.');
}
$header = array_map('trim', $header);
$col_idx = [];
foreach ($csv_cols as $label => $field) {
    $idx = array_search($label, $header, true);
    if ($idx === false) {
        cli_fail("Hiányzó CSV oszlop: {$label}\nTalált fejléc: " . implode(' | ', $header));
    }
    $col_idx[$field] = $idx;
}

$rows = [];
$unknown_colors = [];
while (($line = fgetcsv($fh, 0, $delim)) !== false) {
    $get = function ($field) use ($line, $col_idx) {
        return isset($line[$col_idx[$field]]) ? trim((string) $line[$col_idx[$field]]) : '';
    };
    if ($get('sub_sku') === '') {
        continue; // üres sor kihagyása
    }
    $row = [];
    foreach ($csv_cols as $field) {
        $row[$field] = $get($field);
    }
    // magyar szín -> Temu angol érték
    list($color, $unknown) = MG_Temu_Xlsx_Writer::map_color($row['color']);
    if ($unknown !== null) {
        $unknown_colors[$unknown] = true;
    }
    $row['color'] = $color;
    $rows[] = $row;
}
fclose($fh);

echo 'CSV beolvasva: ' . count($rows) . " sor (elválasztó: '{$delim}')\n";
$skus = [];
foreach ($rows as $r) {
    $skus[$r['sku']] = true;
}
echo 'Egyedi termék (SKU): ' . count($skus) . "\n";
if ($unknown_colors) {
    echo 'FIGYELEM — ismeretlen szín(ek), változatlanul beírva: ' . implode(', ', array_keys($unknown_colors)) . "\n";
}
if (!$rows) {
    cli_fail('A CSV nem tartalmaz adatsort.');
}

// ---------------------------------------------------------------- generálás

try {
    $result = MG_Temu_Xlsx_Writer::generate($template_path, $rows, $out_path);
} catch (Exception $e) {
    cli_fail($e->getMessage());
}

echo "Generálva: {$out_path} ({$result['rows']} adatsor, munkalap: {$result['sheet_path']})\n";

// ---------------------------------------------------------------- ellenőrzés
// Elfogadási kritérium: a ZIP tartalma bájtra azonos a sablonnal,
// KIVÉVE a Template lap sheet XML-jét.

$za = new ZipArchive();
$zb = new ZipArchive();
if ($za->open($template_path) !== true || $zb->open($out_path) !== true) {
    cli_fail('Az összehasonlításhoz nem nyitható meg valamelyik fájl.');
}

$entries = function (ZipArchive $z) {
    $map = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        $map[$st['name']] = $st['crc'] . ':' . $st['size'];
    }
    return $map;
};

$a = $entries($za);
$b = $entries($zb);

$changed = [];
foreach ($a as $name => $sig) {
    if (!isset($b[$name])) {
        $changed[] = "HIÁNYZIK a kimenetből: {$name}";
    } elseif ($b[$name] !== $sig) {
        $changed[] = $name;
    }
}
foreach ($b as $name => $sig) {
    if (!isset($a[$name])) {
        $changed[] = "ÚJ a kimenetben: {$name}";
    }
}

$ok = (count($changed) === 1 && $changed[0] === $result['sheet_path']);

echo "Eltérő ZIP-bejegyzések:\n";
foreach ($changed as $c) {
    echo "  - {$c}\n";
}

// sorszám-ellenőrzés a generált sheetben
$sheet_xml = $zb->getFromName($result['sheet_path']);
$data_rows = 0;
if ($sheet_xml !== false && preg_match_all('/<row[^>]*\br="(\d+)"/', $sheet_xml, $m)) {
    foreach ($m[1] as $r) {
        if ((int) $r >= MG_Temu_Xlsx_Writer::DATA_START_ROW) {
            $data_rows++;
        }
    }
}
echo "Adatsorok a kimeneti Template lapon: {$data_rows} (elvárt: " . count($rows) . ")\n";
$rows_ok = ($data_rows === count($rows));

// formula-ellenőrzés: minden adatsorban legyen <f> elem, cached <v> nélkül
$formula_count = $sheet_xml !== false ? preg_match_all('#<f>[^<]*</f>#', $sheet_xml) : 0;
echo "Formulacellák a kimenetben: {$formula_count}\n";

$za->close();
$zb->close();

if ($ok && $rows_ok) {
    echo "KÉSZ ✔  Csak a Template munkalap XML-je változott, a sorszám stimmel.\n";
    exit(0);
}
if (!$ok) {
    fwrite(STDERR, "HIBA: a vártnál több/más ZIP-bejegyzés változott!\n");
}
if (!$rows_ok) {
    fwrite(STDERR, "HIBA: az adatsorok száma nem egyezik!\n");
}
exit(1);
