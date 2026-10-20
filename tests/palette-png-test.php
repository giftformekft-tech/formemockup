<?php
/**
 * php tests/palette-png-test.php
 * MG_Image_Utils::to_palette_png() on real Imagick: the AI redesign's
 * Design-flow style finish (255-color palette PNG, antialiased alpha kept).
 */
define('ABSPATH', __DIR__);
if (!class_exists('Imagick')) {
    echo "This test requires the real Imagick extension.\n";
    exit(0);
}
require __DIR__ . '/../includes/class-image-utils.php';

$passed = 0;
function check($cond, $label) {
    if (!$cond) { fwrite(STDERR, "FAIL - $label\n"); exit(1); }
    $GLOBALS['passed']++;
}
function chunks($path) {
    $d = file_get_contents($path);
    $out = array();
    for ($i = 8; $i < strlen($d);) {
        $len = unpack('N', substr($d, $i, 4))[1];
        $type = substr($d, $i + 4, 4);
        $out[$type] = substr($d, $i + 8, $len);
        $i += 12 + $len;
    }
    return $out;
}
function pixels($path) {
    $im = new Imagick($path);
    $im->setImageFormat('rgba');
    $im->setImageDepth(8);
    $raw = $im->getImageBlob();
    $size = array($im->getImageWidth(), $im->getImageHeight());
    $im->clear();
    return array($raw, $size);
}

$dir = sys_get_temp_dir() . '/mg-palette-' . bin2hex(random_bytes(4));
mkdir($dir);
// 600×600 transparent canvas: a gradient disc with antialiased edges, then outlined text.
$disc = new Imagick();
$disc->newImage(600, 600, new ImagickPixel('transparent'));
$draw = new ImagickDraw();
$draw->setFillColor('#ffffff');
$draw->circle(300, 260, 300, 90);
$disc->drawImage($draw);
$src = new Imagick();
$src->newPseudoImage(600, 600, 'gradient:#ffee00-#ff0000');
$src->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
$src->compositeImage($disc, Imagick::COMPOSITE_DSTIN, 0, 0);
$text = new ImagickDraw();
$text->setFillColor('#ffffff');
$text->setStrokeColor('#000000');
$text->setStrokeWidth(6);
$text->setFontSize(80);
$text->annotation(170, 520, 'SZEP');
$src->drawImage($text);
$src->setImageFormat('png');
$path = "$dir/design.png";
$src->writeImage($path);
list($before, $size_before) = pixels($path);

$result = MG_Image_Utils::to_palette_png($path, 255);
$c = chunks($path);
$ihdr = unpack('Nw/Nh/Cdepth/Ctype', $c['IHDR']);
check($ihdr['type'] === 3 && $ihdr['depth'] === 8, 'indexed 8-bit PNG (color type 3)');
check(strlen($c['PLTE']) / 3 <= 256 && $result['colors'] <= 256 && $result['colors'] > 20, 'at most 256 palette entries: ' . $result['colors']);
check(isset($c['tRNS']) && strlen($c['tRNS']) > 1, 'full tRNS table, not a single transparent color');
check($result['width'] < 600 && $result['height'] < 600 && array($ihdr['w'], $ihdr['h']) === array($result['width'], $result['height']), 'transparent margins trimmed');

list($after) = pixels($path);
$alphas = $transparent_rgb = 0;
$levels = array();
for ($i = 0; $i < strlen($after); $i += 4) {
    $a = ord($after[$i + 3]);
    $levels[$a] = 1;
    if ($a === 0 && substr($after, $i, 3) !== "\0\0\0") {
        $transparent_rgb++;
    }
    if ($a > 0 && $a < 6) {
        $alphas++;
    }
}
check(count($levels) > 10, 'antialiased edges keep partial alpha: ' . count($levels) . ' levels');
check($transparent_rgb === 0, 'fully transparent pixels have RGB 0');
check($alphas === 0, 'alpha below 6 became fully transparent');

// Color fidelity over a solid background, inside the trimmed area.
$orig = new Imagick();
$orig->readImageBlob($src->getImageBlob());
$orig->trimImage(0);
$orig->setImagePage(0, 0, 0, 0);
$conv = new Imagick($path);
foreach (array($orig, $conv) as $im) {
    $im->setImageBackgroundColor('#00aa00');
}
$a = $orig->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
$b = $conv->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
$psnr = $a->compareImages($b, Imagick::METRIC_PEAKSIGNALTONOISERATIO)[1];
check($psnr > 35, sprintf('colors kept (PSNR %.1f dB)', $psnr));

// A file that already is a palette PNG stays valid on a second pass.
$again = MG_Image_Utils::to_palette_png($path, 255);
check($again['width'] === $result['width'] && chunks($path)['IHDR'] === $c['IHDR'], 'idempotent on its own output');

// A fully opaque design keeps working (no tRNS needed).
$opaque = new Imagick();
$opaque->newImage(40, 30, new ImagickPixel('#123456'));
$opaque->setImageFormat('png');
$opaque->writeImage("$dir/opaque.png");
$r = MG_Image_Utils::to_palette_png("$dir/opaque.png", 255);
check($r['colors'] === 1 && !isset(chunks("$dir/opaque.png")['tRNS']), 'opaque single-color design');

// Opaque white background with a small mark: cropped by alpha only, so nothing is cut.
$white = new Imagick();
$white->newImage(200, 100, new ImagickPixel('#ffffff'));
$mark = new ImagickDraw();
$mark->setFillColor('#cc0000');
$mark->rectangle(90, 40, 110, 60);
$white->drawImage($mark);
$white->setImageFormat('png');
$white->writeImage("$dir/white.png");
$r = MG_Image_Utils::to_palette_png("$dir/white.png", 255);
check(array($r['width'], $r['height']) === array(200, 100), 'opaque white margins are kept (no color trim)');

// Artwork touching the top-left corner, transparent on the right and bottom.
$corner = new Imagick();
$corner->newImage(100, 80, new ImagickPixel('transparent'));
$block = new ImagickDraw();
$block->setFillColor('#00aa00');
$block->rectangle(0, 0, 59, 39);
$corner->drawImage($block);
$corner->setImageFormat('png');
$corner->writeImage("$dir/corner.png");
$r = MG_Image_Utils::to_palette_png("$dir/corner.png", 255);
check(array($r['width'], $r['height']) === array(60, 40), 'cropped to the alpha bounds: ' . $r['width'] . 'x' . $r['height']);

// Fully transparent input is refused instead of producing an empty print.
$empty = new Imagick();
$empty->newImage(10, 10, new ImagickPixel('transparent'));
$empty->setImageFormat('png');
$empty->writeImage("$dir/empty.png");
try {
    MG_Image_Utils::to_palette_png("$dir/empty.png", 255);
    check(false, 'fully transparent input refused');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'teljesen átlátszó') !== false, 'fully transparent input refused');
}

array_map('unlink', glob("$dir/*"));
rmdir($dir);
echo "Palette PNG: $passed checks passed on real Imagick.\n";
