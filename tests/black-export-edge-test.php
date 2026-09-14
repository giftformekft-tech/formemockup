<?php
// Real Imagick required. Optional first argument: an exported PNG to inspect in memory.
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
function __($value, $domain = '') { return $value; }
$target_cm = 1.016;
function mgsc_get_print_height_cm($type, $size) { return $GLOBALS['target_cm']; }
require_once dirname(__DIR__) . '/includes/class-image-utils.php';
require_once dirname(__DIR__) . '/admin/class-order-design-download.php';
if (!extension_loaded('imagick')) { fwrite(STDERR, "Real Imagick required.\n"); exit(1); }
$checks = 0;
function verify($ok, $label) {
    $GLOBALS['checks']++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'ok - ' . $label . "\n";
}
function dark_pixels($image) {
    $count = 0;
    for ($y = 0; $y < $image->getImageHeight(); $y++) {
        $row = $image->exportImagePixels(0, $y, $image->getImageWidth(), 1, 'RGBA', Imagick::PIXEL_FLOAT);
        for ($x = 0; $x < count($row); $x += 4) {
            if ($row[$x + 3] > 0.99 && max($row[$x], $row[$x + 1], $row[$x + 2]) < 0.12) $count++;
        }
    }
    return $count;
}
$dir = sys_get_temp_dir() . '/mg-black-edge-' . bin2hex(random_bytes(5));
mkdir($dir);
$temps = $cache = array();
try {
    $source = new Imagick();
    $source->newImage(32, 40, new ImagickPixel('transparent'), 'png');
    $rgba = array();
    for ($y = 0; $y < 40; $y++) {
        for ($x = 0; $x < 32; $x++) {
            $pixel = array(0, 0, 0, 0);
            if ($x >= 6 && $x <= 25 && $y >= 5 && $y <= 34) $pixel = array(0.02, 0.02, 0.02, 0.6);
            if ($x >= 7 && $x <= 24 && $y >= 6 && $y <= 33) $pixel = array(0.02, 0.02, 0.02, 1);
            if ($x >= 10 && $x <= 21 && $y >= 9 && $y <= 30) $pixel = array(1, 1, 1, 1);
            foreach ($pixel as $v) $rgba[] = $v;
        }
    }
    $source->importImagePixels(0, 0, 32, 40, 'RGBA', Imagick::PIXEL_FLOAT, $rgba);
    $path = $dir . '/source.png';
    $source->writeImage($path);
    $original_hash = hash_file('sha256', $path);
    $prepare = new ReflectionMethod('MG_Order_Design_Download', 'prepare_export_png');
    foreach (array(0.0, 1.016) as $target_cm) {
        $cache = array();
        $plain_path = $prepare->invokeArgs(null, array($path, 'polo', 'M', &$cache, &$temps, false, false));
        $plain = new Imagick($plain_path);
        verify(dark_pixels($plain) > 0, 'normal export retains the black outline, print height ' . $target_cm);
        $clean_path = $prepare->invokeArgs(null, array($path, 'polo', 'M', &$cache, &$temps, false, true));
        $clean = new Imagick($clean_path);
        foreach (array($plain_path, $clean_path) as $export_path) {
            $header = file_get_contents($export_path, false, null, 0, 33);
            verify(ord($header[24]) === 8 && ord($header[25]) === 6, 'serialized export is always 8-bit RGBA, including monochrome art');
        }
        verify(dark_pixels($clean) === 0, 'black-free export leaves no opaque hairline, print height ' . $target_cm);
        $white = $clean->getImagePixelColor((int) ($clean->getImageWidth() / 2), (int) ($clean->getImageHeight() / 2))->getColor(true);
        verify($white['r'] > 0.99 && $white['g'] > 0.99 && $white['b'] > 0.99 && $white['a'] > 0.99, 'white letter interior stays opaque white');
        $alpha = $clean->exportImagePixels(0, 0, $clean->getImageWidth(), $clean->getImageHeight(), 'A', Imagick::PIXEL_FLOAT);
        verify(count(array_filter($alpha, fn($v) => $v > 0.00002 && $v < 0.99998)) === 0, 'final mask remains binary');
        if ($target_cm > 0) verify($clean->getImageHeight() === 120, 'configured print size survives final cleanup');
        $plain->clear(); $clean->clear();
    }
    verify(hash_file('sha256', $path) === $original_hash, 'source file is unchanged');
    // Reproduce the failing RIP format: 1-bit gray + tRNS. No resizing is
    // requested here, so decoded pixels must survive the format conversion.
    $mono = new Imagick();
    $mono->newImage(2, 2, new ImagickPixel('transparent'), 'png');
    $mono->importImagePixels(0, 0, 2, 2, 'RGBA', Imagick::PIXEL_CHAR,
        array(255,255,255,255, 0,0,0,0, 0,0,0,0, 255,255,255,255));
    $mono->setOption('png:color-type', '0');
    $mono->setOption('png:bit-depth', '1');
    $mono_path = $dir . '/mono.png';
    $mono->writeImage($mono_path);
    $header = file_get_contents($mono_path, false, null, 0, 33);
    verify(ord($header[24]) === 1 && ord($header[25]) === 0, 'fixture reproduces reported 1-bit grayscale source');
    $target_cm = 0.0;
    $mono_export = $prepare->invokeArgs(null, array($mono_path, 'polo', 'M', &$cache, &$temps, false, false));
    $header = file_get_contents($mono_export, false, null, 0, 33);
    verify(ord($header[24]) === 8 && ord($header[25]) === 6, '1-bit source is exported as explicit 8-bit RGBA');
    $roundtrip = new Imagick($mono_export);
    verify($roundtrip->getImageWidth() === 2 && $roundtrip->getImageHeight() === 2 &&
        $roundtrip->exportImagePixels(0,0,2,2,'RGBA',Imagick::PIXEL_CHAR) ===
        $mono->exportImagePixels(0,0,2,2,'RGBA',Imagick::PIXEL_CHAR), 'format conversion preserves every decoded pixel and transparency');
    $roundtrip->clear(); $mono->clear();
    try {
        MG_Image_Utils::strip_color_to_transparent(new Imagick(), 'black', 25.0, true);
        throw new Exception('Empty image should fail the strict final color pass');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'Nem sikerült'), 'final color pass reports native failures instead of silently exporting');
    }
    if (!empty($argv[1])) {
        $actual = new Imagick($argv[1]);
        $before_dark = dark_pixels($actual);
        $before_signature = $actual->getImageSignature();
        $original = clone $actual;
        MG_Image_Utils::threshold_alpha_binary($actual);
        MG_Image_Utils::strip_color_to_transparent($actual, 'black', 25.0, true);
        MG_Image_Utils::threshold_alpha_binary($actual);
        verify($before_dark > 0 && dark_pixels($actual) === 0, 'attached export: all ' . $before_dark . ' near-black opaque pixels removed in memory');
        $preserved = true;
        for ($y = 0; $y < $actual->getImageHeight(); $y++) {
            $old = $original->exportImagePixels(0, $y, $actual->getImageWidth(), 1, 'RGBA', Imagick::PIXEL_CHAR);
            $row = $actual->exportImagePixels(0, $y, $actual->getImageWidth(), 1, 'RGBA', Imagick::PIXEL_CHAR);
            for ($i = 0; $i < count($old); $i += 4) {
                if ($old[$i + 3] === 255 && min($old[$i], $old[$i + 1], $old[$i + 2]) >= 128 && array_slice($old, $i, 4) !== array_slice($row, $i, 4)) $preserved = false;
            }
        }
        verify($preserved, 'attached export: opaque light/white pixels unchanged');
        verify((new Imagick($argv[1]))->getImageSignature() === $before_signature, 'attached original is never overwritten');
        $actual->clear(); $original->clear();
    }
    echo "\n" . $checks . " real Imagick checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach (array_unique($temps) as $temp) if (is_file($temp)) unlink($temp);
    foreach (glob($dir . '/*') as $temp) unlink($temp);
    rmdir($dir);
}
