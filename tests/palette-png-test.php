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

// Upscale input preparation (Design-flow): edge cleanup, binary alpha (optional), alpha crop.
function px_at($path, $x, $y) {
    $im = new Imagick($path);
    $c = $im->getImagePixelColor($x, $y)->getColor(true);
    $im->clear();
    return array((int) round($c['r'] * 255), (int) round($c['g'] * 255), (int) round($c['b'] * 255), (int) round($c['a'] * 255));
}
// 80×60 transparent canvas, an opaque red 20×20 square at (30,20) with a
// semi-transparent dark contaminated ring around it (the generated fringe).
$fringe = new Imagick();
$fringe->newImage(80, 60, new ImagickPixel('transparent'));
$ring = new ImagickDraw();
$ring->setFillColor(new ImagickPixel('rgba(40,10,10,0.6)'));
$ring->rectangle(28, 18, 51, 41);
$fringe->drawImage($ring);
$core = new ImagickDraw();
$core->setFillColor('#ff0000');
$core->rectangle(30, 20, 49, 39);
$fringe->drawImage($core);
$fringe->setImageFormat('png');
$fringe->writeImage("$dir/fringe.png");
$r = MG_Image_Utils::prepare_upscale_input("$dir/fringe.png", true);
check($r['changed'] && array($r['width'], $r['height']) === array(24, 24), 'cropped to the alpha bounds of the ring: ' . $r['width'] . 'x' . $r['height']);
$edge = px_at("$dir/fringe.png", 0, 0);
check($edge[3] === 255 && $edge[0] > 240 && $edge[1] < 15, 'the 60% fringe became opaque with the interior red: ' . implode(',', $edge));
check(px_at("$dir/fringe.png", 12, 12) === array(255, 0, 0, 255), 'interior untouched');
$fringe->writeImage("$dir/fringe2.png");
MG_Image_Utils::prepare_upscale_input("$dir/fringe2.png", false);
$dark = px_at("$dir/fringe2.png", 0, 0);
check($dark[3] === 255 && $dark[0] < 80, 'without edge cleanup the fringe keeps its own color: ' . implode(',', $dark));

// A faint (below 50%) haze disappears instead of becoming a hard outline.
$haze = new Imagick();
$haze->newImage(50, 50, new ImagickPixel('transparent'));
$h = new ImagickDraw();
$h->setFillColor(new ImagickPixel('rgba(0,0,255,0.3)'));
$h->rectangle(0, 0, 49, 49);
$haze->drawImage($h);
$c2 = new ImagickDraw();
$c2->setFillColor('#00ff00');
$c2->rectangle(20, 20, 29, 29);
$haze->drawImage($c2);
$haze->setImageFormat('png');
$haze->writeImage("$dir/haze.png");
$r = MG_Image_Utils::prepare_upscale_input("$dir/haze.png", true);
check(array($r['width'], $r['height']) === array(10, 10), 'faint haze removed, crop to the solid part');

// Binary alpha off: the antialiased edge goes to the upscaler as it is.
$fringe->writeImage("$dir/fringe3.png");
$r = MG_Image_Utils::prepare_upscale_input("$dir/fringe3.png", true, 0);
$soft = px_at("$dir/fringe3.png", 0, 0);
check(array($r['width'], $r['height']) === array(24, 24) && abs($soft[3] - 153) <= 2, 'binary alpha off: the 60% fringe keeps its partial alpha: ' . implode(',', $soft));
check($soft[0] > 240 && $soft[1] < 15, 'binary alpha off: the fringe still gets the interior red');
// A higher threshold drops the 60% fringe, a lower one keeps the 30% haze.
$fringe->writeImage("$dir/fringe4.png");
$r = MG_Image_Utils::prepare_upscale_input("$dir/fringe4.png", true, 191);
check(array($r['width'], $r['height']) === array(20, 20), '75% threshold: the 60% fringe removed');
$haze->writeImage("$dir/haze2.png");
$r = MG_Image_Utils::prepare_upscale_input("$dir/haze2.png", false, 64);
check(array($r['width'], $r['height']) === array(50, 50) && px_at("$dir/haze2.png", 0, 0)[3] === 255, '25% threshold: the 30% haze becomes opaque');
// Nothing to do (no cleanup, no binary alpha, no margin): the file is not rewritten.
$haze->writeImage("$dir/haze3.png");
$before = file_get_contents("$dir/haze3.png");
$r = MG_Image_Utils::prepare_upscale_input("$dir/haze3.png", false, 0);
check(!$r['changed'] && file_get_contents("$dir/haze3.png") === $before, 'both off and no margin: file untouched');

// Opaque artwork is left exactly as it is.
$before = file_get_contents("$dir/white.png");
$r = MG_Image_Utils::prepare_upscale_input("$dir/white.png", true);
check(!$r['changed'] && file_get_contents("$dir/white.png") === $before, 'opaque image untouched');

array_map('unlink', glob("$dir/*"));
rmdir($dir);
echo "Palette PNG: $passed checks passed on real Imagick.\n";
