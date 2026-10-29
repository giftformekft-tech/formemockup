<?php
/**
 * php tests/palette-png-test.php
 * On real Imagick: the AI redesign's upscale input preparation, its color
 * guard, and reading back the browser's palette PNG. The palette itself is
 * made in the browser (assets/js/palette-png.js, tests/palette-png-reference-test.py).
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

$dir = sys_get_temp_dir() . '/mg-palette-' . bin2hex(random_bytes(4));
mkdir($dir);

// The browser's palette PNG is read back by its PLTE; any other file counts 0.
$mark = new ImagickDraw();
$mark->setFillColor('#cc0000');
$mark->rectangle(90, 40, 110, 60);
$white = new Imagick();
$white->newImage(200, 100, new ImagickPixel('#ffffff'));
$white->drawImage($mark);
$white->setImageFormat('png');
$white->writeImage("$dir/white.png");
$indexed = clone $white;
$indexed->setImageFormat('png8');
$indexed->writeImage("$dir/indexed.png");
check(ord(chunks("$dir/indexed.png")['IHDR'][9]) === 3, 'fixture: an indexed PNG');
check(MG_Image_Utils::png_palette_size("$dir/indexed.png") === strlen(chunks("$dir/indexed.png")['PLTE']) / 3, 'palette size from PLTE: ' . MG_Image_Utils::png_palette_size("$dir/indexed.png"));
$truecolor = clone $white;
$truecolor->setImageFormat('png');
$truecolor->setOption('png:color-type', '6');
$truecolor->writeImage("$dir/truecolor.png");
check(ord(chunks("$dir/truecolor.png")['IHDR'][9]) === 6 && MG_Image_Utils::png_palette_size("$dir/truecolor.png") === 0, 'a truecolor PNG has no palette');
file_put_contents("$dir/not.png", 'GIF89a');
check(MG_Image_Utils::png_palette_size("$dir/not.png") === 0 && MG_Image_Utils::png_palette_size("$dir/missing.png") === 0, 'not a PNG, or missing: 0');

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

// The color guard (ImageMagick 7.1.2 Q16-HDRI blackened designs): what a
// crop does passes, a blackened design throws COLOR_LOSS.
$design = new Imagick();
$design->newImage(60, 40, new ImagickPixel('transparent'));
$draw = new ImagickDraw();
$draw->setFillColor('#e8b020');
$draw->rectangle(10, 5, 49, 34);
$design->drawImage($draw);
$probe = MG_Image_Utils::opaque_probe($design);
$mean = MG_Image_Utils::probe_mean(null, $probe);
check($mean !== null && abs($mean[0] - 232) < 1 && abs($mean[1] - 176) < 1 && abs($mean[2] - 32) < 1, 'probe mean of the opaque pixels: ' . implode(',', array_map('round', $mean ?: array())));
MG_Image_Utils::assert_colors_kept($mean, array($mean[0] - 6, $mean[1] + 4, $mean[2]), 'test');
check(true, 'a shift of a few levels passes');
try {
    MG_Image_Utils::assert_colors_kept($mean, array(0, 0, 0), 'a felskálázás előtti előkészítés');
    check(false, 'a blackened design throws');
} catch (RuntimeException $e) {
    check($e->getCode() === MG_Image_Utils::COLOR_LOSS && strpos($e->getMessage(), 'ImageMagick') !== false && strpos($e->getMessage(), 'előkészítés') !== false, 'a blackened design throws COLOR_LOSS: ' . $e->getMessage());
}
$cropped = clone $design;
$cropped->cropImage(40, 30, 10, 5);
$cropped->setImagePage(0, 0, 0, 0);
check(MG_Image_Utils::probe_mean($cropped, $probe, 10, 5) == MG_Image_Utils::probe_mean(null, $probe), 'the probe finds its pixels after the crop');
$black = clone $cropped;
$black->evaluateImage(Imagick::EVALUATE_SET, 0, Imagick::CHANNEL_RED | Imagick::CHANNEL_GREEN | Imagick::CHANNEL_BLUE);
check(MG_Image_Utils::probe_mean($black, $probe, 10, 5)[0] < 40, 'and sees a blackened crop');

array_map('unlink', glob("$dir/*"));
rmdir($dir);
echo "Palette PNG: $passed checks passed on real Imagick.\n";
