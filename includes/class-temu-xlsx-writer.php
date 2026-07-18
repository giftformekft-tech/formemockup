<?php
/**
 * MG_Temu_Xlsx_Writer — Temu upload xlsx generálás "sebészi ZIP-módszerrel".
 *
 * A Temu hivatalos sablonját NEM generáljuk újra (PhpSpreadsheet nélkül dolgozunk),
 * hanem a mestersablon másolatában KIZÁRÓLAG a "Template" munkalap XML-jét
 * (xl/worksheets/sheetN.xml) írjuk át. Minden más fájl a ZIP-ben bájtra
 * változatlan marad, így a dropdownok, validációk és a base64 metaadat is megmarad.
 *
 * A kitöltési logika a temu_kitolto.py portja:
 *  - 4. sor = mezőkulcsok, az oszlopokat mindig ezek alapján keressük meg
 *  - 5. sor = mintasor, minden fix érték innen másolódik minden kimeneti sorba
 *  - 7 változó oszlop jön a WooCommerce-ből (név, SKU, Sub SKU, leírás, méret, szín, kép URL)
 *
 * Az osztály szándékosan NEM használ WordPress függvényt, így a
 * tools/temu-xlsx-cli.php tesztszkripttel önállóan is futtatható.
 */

if (!class_exists('MG_Temu_Xlsx_Error')) {
    class MG_Temu_Xlsx_Error extends RuntimeException {}
}

if (!class_exists('MG_Temu_Xlsx_Writer')) {

class MG_Temu_Xlsx_Writer {

    const SHEET_NAME     = 'Template';
    const KEY_ROW        = 4; // mezőkulcs sor (t_1_..., t_2_... stb.)
    const DATA_START_ROW = 5; // első adatsor = mintasor

    // Template mezőkulcs -> adatmező (ezek jönnek a WooCommerce-ből,
    // minden más oszlop a mintasorból másolódik)
    const KEY_TO_FIELD = [
        't_1_Product Name'          => 'name',
        't_1_Contribution Goods'    => 'sku',
        't_1_Contribution SKU'      => 'sub_sku',
        't_2_Product Description'   => 'desc',
        't_4_Size:3001'             => 'size',
        't_4_Sale Property:1001'    => 'color',
        't_6_SKU Images URL'        => 'img',
    ];

    // magyar szín -> Temu angol érték (temu_kitolto.py COLOR_MAP)
    const COLOR_MAP = [
        'fekete'       => 'Black',
        'fehér'        => 'White',
        'feher'        => 'White',
        'szürke'       => 'Grey',
        'szurke'       => 'Grey',
        'piros'        => 'Red',
        'kék'          => 'Blue',
        'kek'          => 'Blue',
        'sötétkék'     => 'Navy Blue',
        'sotetkek'     => 'Navy Blue',
        'zöld'         => 'Green',
        'zold'         => 'Green',
        'sárga'        => 'Yellow',
        'sarga'        => 'Yellow',
        'rózsaszín'    => 'Pink',
        'rozsaszin'    => 'Pink',
        'narancssárga' => 'Orange',
        'narancssarga' => 'Orange',
        'lila'         => 'Purple',
        'barna'        => 'Brown',
        'bordó'        => 'Burgundy',
        'bordo'        => 'Burgundy',
    ];

    /**
     * Magyar szín leképezése a Temu angol értékére.
     * Ismeretlen szín esetén az eredeti érték marad, és a második elem jelzi,
     * hogy figyelmeztetni kell rá a felületen.
     *
     * @param string $color
     * @return array{0:string,1:?string} [érték, ismeretlen-szín-vagy-null]
     */
    public static function map_color($color) {
        $c = trim((string) $color);
        $key = function_exists('mb_strtolower') ? mb_strtolower($c, 'UTF-8') : strtolower($c);
        if (isset(self::COLOR_MAP[$key])) {
            return [self::COLOR_MAP[$key], null];
        }
        // ha már angol / ismeretlen, hagyjuk, de jelezzük (üres színre nem szólunk)
        return [$c, $c === '' ? null : $c];
    }

    /**
     * Sablon gyors ellenőrzése (feltöltéskor): megnyitható-e, van-e Template lap,
     * megvannak-e a mezőkulcsok és a mintasor. Hibánál MG_Temu_Xlsx_Error-t dob.
     *
     * @param string $template_path
     * @return array{sheet_path:string,field_cols:array<string,string>}
     */
    public static function validate_template($template_path) {
        $zip = self::open_zip($template_path);
        try {
            $tpl = self::parse_template($zip);
        } finally {
            $zip->close();
        }
        return [
            'sheet_path' => $tpl['sheet_path'],
            'field_cols' => $tpl['field_col'],
        ];
    }

    /**
     * A kész Temu upload xlsx generálása.
     *
     * @param string $template_path A mestersablon xlsx útvonala (nem módosul).
     * @param array  $rows          Adatsorok: [['name','sku','sub_sku','desc','size','color','img'], ...]
     *                              A szín itt már a VÉGSŐ (angol) érték — a map_color-t a hívó futtatja.
     * @param string $out_path      A kimeneti xlsx útvonala.
     * @return array{rows:int,sheet_path:string}
     */
    public static function generate($template_path, array $rows, $out_path) {
        if (empty($rows)) {
            throw new MG_Temu_Xlsx_Error('Nincs exportálható adatsor.');
        }
        if (!is_file($template_path)) {
            throw new MG_Temu_Xlsx_Error('A Temu mestersablon nem található — töltsd fel a beállításoknál.');
        }
        if (!@copy($template_path, $out_path)) {
            throw new MG_Temu_Xlsx_Error('Nem sikerült létrehozni a kimeneti fájlt (írási jogosultság?).');
        }

        $tmp_sheet = $out_path . '.sheet.tmp';
        $zip = null;
        $closed = false;
        try {
            $zip = self::open_zip($out_path);
            $tpl = self::parse_template($zip);

            // Az új sheet XML-t fájlba streameljük (309 oszlop × sok sor —
            // nem tartjuk az egészet egyben a memóriában), majd az addFile()
            // lecseréli a ZIP-ben a régi bejegyzést. Minden más entry változatlan.
            self::write_sheet_file($tmp_sheet, $tpl, $rows);
            if (!$zip->addFile($tmp_sheet, $tpl['sheet_path'])) {
                throw new MG_Temu_Xlsx_Error('A munkalap cseréje nem sikerült a ZIP-ben.');
            }
            if (!$zip->close()) { // az addFile a close()-nál olvassa be a fájlt
                throw new MG_Temu_Xlsx_Error('A kimeneti xlsx lezárása nem sikerült.');
            }
            $closed = true;
        } catch (Exception $e) {
            if ($zip instanceof ZipArchive && !$closed) {
                @$zip->close();
            }
            @unlink($tmp_sheet);
            @unlink($out_path);
            throw $e;
        }
        @unlink($tmp_sheet);

        return ['rows' => count($rows), 'sheet_path' => $tpl['sheet_path']];
    }

    // ------------------------------------------------------------------ belső

    /**
     * @param string $path
     * @return ZipArchive
     */
    private static function open_zip($path) {
        if (!class_exists('ZipArchive')) {
            throw new MG_Temu_Xlsx_Error('A szerveren nem érhető el a PHP ZipArchive bővítmény.');
        }
        if (!is_file($path)) {
            throw new MG_Temu_Xlsx_Error('A fájl nem található: ' . basename($path));
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new MG_Temu_Xlsx_Error('A fájl nem nyitható meg xlsx-ként (sérült vagy nem ZIP): ' . basename($path));
        }
        return $zip;
    }

    /**
     * A sablon feldolgozása: Template lap megkeresése, kulcssor + mintasor
     * elemzése, a kimenethez szükséges "terv" összeállítása.
     *
     * @param ZipArchive $zip
     * @return array
     */
    private static function parse_template(ZipArchive $zip) {
        $sheet_path = self::locate_template_sheet($zip);
        $sheet_xml = $zip->getFromName($sheet_path);
        if ($sheet_xml === false) {
            throw new MG_Temu_Xlsx_Error('A Template munkalap XML-je nem olvasható a sablonból.');
        }
        $shared = self::read_shared_strings($zip);

        // --- 4. sor: mezőkulcsok -> oszlopbetű (első előfordulás számít) ---
        if (!preg_match('#<row[^>]*\br="' . self::KEY_ROW . '"[^>]*>.*?</row>#s', $sheet_xml, $m)) {
            throw new MG_Temu_Xlsx_Error('A Template lapon nem található a ' . self::KEY_ROW . '. (mezőkulcs) sor.');
        }
        $key_col = [];
        foreach (self::parse_cells($m[0]) as $cell) {
            $text = self::cell_text($cell, $shared);
            if ($text !== '' && !isset($key_col[$text])) {
                $key_col[$text] = $cell['col'];
            }
        }
        $missing = [];
        $field_col = []; // adatmező -> oszlopbetű
        foreach (self::KEY_TO_FIELD as $key => $field) {
            $col = null;
            if (isset($key_col[$key])) {
                $col = $key_col[$key];
            } elseif (($colon = strpos($key, ':')) !== false) {
                // pl. t_4_Size:3001 — a property-azonosító sablononként eltérhet
                // (női/gyerek/más kategóriájú sablon), ezért ha a pontos kulcs
                // nincs meg, az azonos előtagú első kulcsot fogadjuk el
                $prefix = substr($key, 0, $colon + 1);
                foreach ($key_col as $k => $c) {
                    if (strpos($k, $prefix) === 0) {
                        $col = $c;
                        break;
                    }
                }
            }
            if ($col !== null) {
                $field_col[$field] = $col;
            } else {
                $missing[] = $key;
            }
        }
        if ($missing) {
            throw new MG_Temu_Xlsx_Error('Hiányzó mezőkulcs(ok) a Template ' . self::KEY_ROW . '. sorában: ' . implode(', ', $missing));
        }

        // --- 5. sor: mintasor ---
        if (!preg_match(
            '#(<row[^>]*\br="' . self::DATA_START_ROW . '"[^>]*>)(.*?)</row>#s',
            $sheet_xml,
            $sm,
            PREG_OFFSET_CAPTURE
        )) {
            throw new MG_Temu_Xlsx_Error('Az ' . self::DATA_START_ROW . '. sor (mintasor) üresnek tűnik — a sablonban kell lennie legalább egy kitöltött adatsornak.');
        }
        $sample_offset   = $sm[0][1];
        $sample_row_open = $sm[1][0];
        $sample_cells    = self::parse_cells($sm[0][0]);

        // fejrész: minden a mintasor ELŐTT (1–4. sor bájtra változatlanul megy át)
        $head = substr($sheet_xml, 0, $sample_offset);
        // zárórész: a </sheetData>-tól a fájl végéig — a régi adatsorok kimaradnak
        $tail_pos = strpos($sheet_xml, '</sheetData>', $sample_offset);
        if ($tail_pos === false) {
            throw new MG_Temu_Xlsx_Error('Érvénytelen munkalap XML: hiányzó </sheetData>.');
        }
        $tail = substr($sheet_xml, $tail_pos);

        $by_col = [];
        foreach ($sample_cells as $cell) {
            if (!isset($by_col[$cell['col']])) {
                $by_col[$cell['col']] = $cell;
            }
        }

        // mintasor-ellenőrzés: a Sub SKU oszlopban kell értéknek lennie
        $sub_col = $field_col['sub_sku'];
        $sub_val = isset($by_col[$sub_col]) ? self::cell_text($by_col[$sub_col], $shared) : '';
        if (trim($sub_val) === '') {
            throw new MG_Temu_Xlsx_Error('Az ' . self::DATA_START_ROW . '. sor (mintasor) üresnek tűnik — a sablonban kell lennie legalább egy kitöltött adatsornak.');
        }

        // --- kimeneti oszlopterv a mintasor alapján ---
        $col_to_field = array_flip($field_col);
        $plan = []; // oszlopindex => cellaterv
        foreach ($by_col as $col => $cell) {
            $idx = self::col_index($col);
            if (isset($col_to_field[$col])) {
                // változó oszlop: az érték a WooCommerce-ből jön, a stílus a mintából
                $plan[$idx] = ['kind' => 'field', 'col' => $col, 'field' => $col_to_field[$col], 's' => $cell['s']];
            } elseif ($cell['f'] !== null) {
                // formulás oszlop (pl. Category Name VLOOKUP) — a formula szövege a mintasorból
                if (trim($cell['f']) === '') {
                    throw new MG_Temu_Xlsx_Error('A mintasor formulacellája (' . $col . self::DATA_START_ROW . ') üres — a megosztott formula főcellája hiányzik a sablonból.');
                }
                $plan[$idx] = ['kind' => 'formula', 'col' => $col, 'f' => $cell['f'], 's' => $cell['s']];
            } elseif ($cell['t'] === 's' || $cell['t'] === 'inlineStr' || $cell['t'] === 'str') {
                // szöveges fix érték: inline stringként írjuk ki, így a
                // sharedStrings.xml-hez hozzá sem kell nyúlni
                $plan[$idx] = ['kind' => 'inline', 'col' => $col, 'text' => self::cell_text($cell, $shared), 's' => $cell['s']];
            } elseif ($cell['v'] !== null && $cell['v'] !== '') {
                // szám (ár, súly, mennyiség stb.): sima <v> érték, típusjelölő nélkül
                $plan[$idx] = ['kind' => 'raw', 'col' => $col, 'v' => $cell['v'], 't' => $cell['t'], 's' => $cell['s']];
            } elseif ($cell['s'] !== null) {
                // üres, de formázott cella: a stílust megőrizzük
                $plan[$idx] = ['kind' => 'blank', 'col' => $col, 's' => $cell['s']];
            }
        }
        // változó oszlop, aminek a mintasorban nincs cellája (pl. üres leírás)
        foreach ($field_col as $field => $col) {
            $idx = self::col_index($col);
            if (!isset($plan[$idx])) {
                $plan[$idx] = ['kind' => 'field', 'col' => $col, 'field' => $field, 's' => null];
            }
        }
        ksort($plan);

        return [
            'sheet_path'      => $sheet_path,
            'head'            => $head,
            'tail'            => $tail,
            'sample_row_open' => $sample_row_open,
            'plan'            => $plan,
            'field_col'       => $field_col,
        ];
    }

    /**
     * A "Template" nevű munkalap ZIP-beli útvonalának megkeresése a
     * xl/workbook.xml + xl/_rels/workbook.xml.rels alapján.
     * Fix sheet-indexet NEM feltételezünk.
     *
     * @param ZipArchive $zip
     * @return string pl. "xl/worksheets/sheet4.xml"
     */
    private static function locate_template_sheet(ZipArchive $zip) {
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb === false) {
            throw new MG_Temu_Xlsx_Error('Érvénytelen xlsx: hiányzik az xl/workbook.xml.');
        }
        $rid = null;
        if (preg_match_all('/<sheet\b[^>]*>/', $wb, $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('/\bname="([^"]*)"/', $tag, $nm)
                    && self::xml_decode($nm[1]) === self::SHEET_NAME
                    && preg_match('/\br:id="([^"]*)"/', $tag, $rm)) {
                    $rid = $rm[1];
                    break;
                }
            }
        }
        if ($rid === null) {
            throw new MG_Temu_Xlsx_Error('Nincs "' . self::SHEET_NAME . '" nevű munkalap a sablonban.');
        }

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels === false) {
            throw new MG_Temu_Xlsx_Error('Érvénytelen xlsx: hiányzik az xl/_rels/workbook.xml.rels.');
        }
        $target = null;
        if (preg_match_all('/<Relationship\b[^>]*>/', $rels, $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('/\bId="([^"]*)"/', $tag, $im) && $im[1] === $rid
                    && preg_match('/\bTarget="([^"]*)"/', $tag, $tm)) {
                    $target = self::xml_decode($tm[1]);
                    break;
                }
            }
        }
        if ($target === null) {
            throw new MG_Temu_Xlsx_Error('A "' . self::SHEET_NAME . '" munkalap fájlja nem azonosítható a sablonban.');
        }

        // a Target lehet abszolút ("/xl/worksheets/...") vagy az xl/-hez relatív
        $path = ($target !== '' && $target[0] === '/') ? ltrim($target, '/') : 'xl/' . $target;
        if ($zip->locateName($path) === false) {
            throw new MG_Temu_Xlsx_Error('A munkalap XML (' . $path . ') nem található a sablon ZIP-jében.');
        }
        return $path;
    }

    /**
     * sharedStrings.xml beolvasása: index -> teljes szöveg (rich text futamok
     * összefűzve). A sablon fix cellái shared string hivatkozások, ezek
     * feloldásához kell.
     *
     * @param ZipArchive $zip
     * @return string[]
     */
    private static function read_shared_strings(ZipArchive $zip) {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $out = [];
        if (preg_match_all('#<si(?:\s[^>]*)?>(.*?)</si>#s', $xml, $m)) {
            foreach ($m[1] as $si) {
                $text = '';
                if (preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $si, $tm)) {
                    $text = self::xml_decode(implode('', $tm[1]));
                }
                $out[] = $text;
            }
        }
        return $out;
    }

    /**
     * Egy <row>...</row> XML celláinak elemzése.
     *
     * @param string $row_xml
     * @return array<int,array{col:string,t:?string,s:?string,v:?string,f:?string,is:?string}>
     */
    private static function parse_cells($row_xml) {
        $cells = [];
        if (!preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $row_xml, $m, PREG_SET_ORDER)) {
            return $cells;
        }
        foreach ($m as $cm) {
            $attrs = $cm[1];
            $inner = isset($cm[2]) ? $cm[2] : '';
            if (!preg_match('/\br="([A-Z]+)\d+"/', $attrs, $rm)) {
                continue;
            }
            $cell = ['col' => $rm[1], 't' => null, 's' => null, 'v' => null, 'f' => null, 'is' => null];
            if (preg_match('/\bt="([^"]*)"/', $attrs, $tm)) {
                $cell['t'] = $tm[1];
            }
            if (preg_match('/\bs="([^"]*)"/', $attrs, $sm2)) {
                $cell['s'] = $sm2[1];
            }
            if ($inner !== '') {
                if (preg_match('#<f(?:\s[^>]*)?>(.*?)</f>#s', $inner, $fm)) {
                    $cell['f'] = $fm[1]; // XML-escapelt formulaszöveg
                } elseif (preg_match('#<f(?:\s[^>]*)?/>#', $inner)) {
                    $cell['f'] = '';
                }
                if (preg_match('#<v(?:\s[^>]*)?>(.*?)</v>#s', $inner, $vm)) {
                    $cell['v'] = $vm[1];
                }
                if (preg_match('#<is>(.*?)</is>#s', $inner, $im)) {
                    $cell['is'] = $im[1];
                }
            }
            $cells[] = $cell;
        }
        return $cells;
    }

    /**
     * Cella megjelenített szövege (shared string / inline string feloldással).
     *
     * @param array    $cell
     * @param string[] $shared
     * @return string
     */
    private static function cell_text(array $cell, array $shared) {
        if ($cell['t'] === 's') {
            $i = (int) $cell['v'];
            return isset($shared[$i]) ? $shared[$i] : '';
        }
        if ($cell['t'] === 'inlineStr') {
            if ($cell['is'] === null) {
                return '';
            }
            if (preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $cell['is'], $tm)) {
                return self::xml_decode(implode('', $tm[1]));
            }
            return '';
        }
        if ($cell['v'] === null) {
            return '';
        }
        return self::xml_decode($cell['v']);
    }

    /**
     * Az új sheet XML megírása fájlba: fejrész (frissített dimension-nel),
     * adatsorok egyenként streamelve, majd a zárórész.
     *
     * @param string $path
     * @param array  $tpl
     * @param array  $rows
     */
    private static function write_sheet_file($path, array $tpl, array $rows) {
        $fh = @fopen($path, 'wb');
        if (!$fh) {
            throw new MG_Temu_Xlsx_Error('Nem hozható létre az ideiglenes munkalap-fájl (írási jogosultság?).');
        }
        $last_row = self::DATA_START_ROW + count($rows) - 1;
        fwrite($fh, self::update_dimension($tpl['head'], $last_row));
        $n = self::DATA_START_ROW;
        foreach ($rows as $row) {
            fwrite($fh, self::build_row_xml($tpl, $row, $n));
            $n++;
        }
        fwrite($fh, $tpl['tail']);
        fclose($fh);
    }

    /**
     * A <dimension ref="A1:XX999"/> frissítése az új utolsó sorra.
     *
     * @param string $head
     * @param int    $last_row
     * @return string
     */
    private static function update_dimension($head, $last_row) {
        return preg_replace_callback(
            '#(<dimension\s+ref=")([^"]+)(")#',
            function ($m) use ($last_row) {
                $ref = $m[2];
                if (preg_match('/^([A-Z]+\d+):([A-Z]+)\d+$/', $ref, $r)) {
                    $ref = $r[1] . ':' . $r[2] . $last_row;
                }
                return $m[1] . $ref . $m[3];
            },
            $head,
            1
        );
    }

    /**
     * Egy kimeneti adatsor XML-je. A sor nyitó tag-je a mintasoré (spans stb.
     * megmarad), csak az r sorszám változik.
     *
     * @param array $tpl
     * @param array $row  ['name','sku','sub_sku','desc','size','color','img']
     * @param int   $n    kimeneti sorszám
     * @return string
     */
    private static function build_row_xml(array $tpl, array $row, $n) {
        $xml = preg_replace('/\br="' . self::DATA_START_ROW . '"/', 'r="' . $n . '"', $tpl['sample_row_open'], 1);
        foreach ($tpl['plan'] as $spec) {
            $ref = $spec['col'] . $n;
            $s_attr = ($spec['s'] !== null && $spec['s'] !== '') ? ' s="' . $spec['s'] . '"' : '';
            switch ($spec['kind']) {
                case 'field':
                    $val = isset($row[$spec['field']]) ? (string) $row[$spec['field']] : '';
                    $xml .= self::inline_cell($ref, $s_attr, $val);
                    break;
                case 'formula':
                    // cached <v> nélkül — az Excel megnyitáskor újraszámolja
                    $f = self::translate_formula($spec['f'], self::DATA_START_ROW, $n);
                    $xml .= '<c r="' . $ref . '"' . $s_attr . '><f>' . $f . '</f></c>';
                    break;
                case 'inline':
                    $xml .= self::inline_cell($ref, $s_attr, $spec['text']);
                    break;
                case 'raw':
                    $t_attr = ($spec['t'] !== null && $spec['t'] !== '' && $spec['t'] !== 'n') ? ' t="' . $spec['t'] . '"' : '';
                    $xml .= '<c r="' . $ref . '"' . $s_attr . $t_attr . '><v>' . $spec['v'] . '</v></c>';
                    break;
                case 'blank':
                default:
                    $xml .= '<c r="' . $ref . '"' . $s_attr . '/>';
                    break;
            }
        }
        $xml .= '</row>';
        return $xml;
    }

    /**
     * Inline string cella. Escapeli az &, <, > stb. karaktereket; ha a szöveg
     * szóközzel kezdődik/végződik vagy sortörést tartalmaz, xml:space="preserve"
     * kerül a <t>-re, hogy az Excel ne nyelje le.
     *
     * @param string $ref
     * @param string $s_attr kész ' s="N"' attribútum vagy üres
     * @param string $text
     * @return string
     */
    private static function inline_cell($ref, $s_attr, $text) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        // XML-ben tiltott vezérlőkarakterek eltávolítása
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        if ($text === '') {
            return '<c r="' . $ref . '"' . $s_attr . '/>';
        }
        $space = ($text !== trim($text) || strpos($text, "\n") !== false) ? ' xml:space="preserve"' : '';
        return '<c r="' . $ref . '"' . $s_attr . ' t="inlineStr"><is><t' . $space . '>'
            . self::xml_encode($text) . '</t></is></c>';
    }

    /**
     * A mintasor formulájának átvitele másik sorba: a relatív (nem $-os)
     * sorhivatkozásokat a célsorra írjuk át (pl. VLOOKUP($D5,...) -> $D6),
     * ahogy az Excel is tenné kitöltésnél. Az idézőjeles szövegrészekhez
     * nem nyúlunk.
     *
     * @param string $f_escaped XML-escapelt formulaszöveg a sablonból
     * @param int    $from_row
     * @param int    $to_row
     * @return string XML-escapelt formulaszöveg
     */
    private static function translate_formula($f_escaped, $from_row, $to_row) {
        if ($from_row === (int) $to_row) {
            return $f_escaped;
        }
        $f = self::xml_decode($f_escaped);
        $parts = preg_split('/("(?:""|[^"])*")/', $f, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '"') {
                continue;
            }
            $parts[$i] = preg_replace_callback(
                '/(?<![A-Za-z0-9_$])(\$?[A-Z]{1,3})(\$?)(\d+)(?![\dA-Za-z_])/',
                function ($m) use ($from_row, $to_row) {
                    // csak a relatív sorhivatkozást toljuk el ($5 marad)
                    if ($m[2] === '' && (int) $m[3] === $from_row) {
                        return $m[1] . $to_row;
                    }
                    return $m[0];
                },
                $part
            );
        }
        return self::xml_encode(implode('', $parts));
    }

    /**
     * Oszlopbetű -> 1-alapú index (A=1, Z=26, AA=27...).
     *
     * @param string $letters
     * @return int
     */
    private static function col_index($letters) {
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n;
    }

    /**
     * @param string $s
     * @return string
     */
    private static function xml_encode($s) {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @param string $s
     * @return string
     */
    private static function xml_decode($s) {
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

}
