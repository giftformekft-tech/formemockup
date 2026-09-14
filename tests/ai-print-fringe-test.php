<?php
// Real Imagick required: php -d extension=imagick tests/ai-print-fringe-test.php
define('ABSPATH', __DIR__);
require_once dirname(__DIR__) . '/includes/class-image-utils.php';
if (!extension_loaded('imagick')) {
    fwrite(STDERR, "This test requires the real Imagick extension.\n");
    exit(1);
}
$checks = 0;
function verify($condition, $label) {
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'ok - ' . $label . "\n";
}
function fixture($edge, $core = array(0.02, 0.02, 0.02, 1.0)) {
    $image = new Imagick();
    $image->newImage(9, 9, new ImagickPixel('transparent'), 'png');
    $pixels = array();
    for ($y = 0; $y < 9; $y++) {
        for ($x = 0; $x < 9; $x++) {
            $p = array(0.0, 0.0, 0.0, 0.0);
            if ($x >= 2 && $x <= 6 && $y >= 2 && $y <= 6) $p = $edge;
            if ($x >= 3 && $x <= 5 && $y >= 3 && $y <= 5) $p = $core;
            foreach ($p as $channel) $pixels[] = $channel;
        }
    }
    $image->importImagePixels(0, 0, 9, 9, 'RGBA', Imagick::PIXEL_FLOAT, $pixels);
    return $image;
}
function pixels($image) { return $image->exportImagePixels(0, 0, 9, 9, 'RGBA', Imagick::PIXEL_FLOAT); }
function unchanged($before, $after) {
    foreach ($before as $i => $value) if (abs($value - $after[$i]) > 0.00002) return false;
    return true;
}
try {
    $image = fixture(array(0.8, 0.8, 0.8, 0.7));
    $before = clone $image;
    $old = pixels($image);
    verify(MG_Image_Utils::clean_transparent_edges($image) === 16, 'all thin pale semi-transparent contour pixels corrected');
    $new = pixels($image);
    for ($i = 0; $i < count($old); $i += 4) {
        verify(abs($old[$i + 3] - $new[$i + 3]) < 0.00002, 'alpha unchanged at pixel ' . ($i / 4));
        if ($old[$i + 3] > 0.99 || $old[$i + 3] < 0.01) {
            verify(unchanged(array_slice($old, $i, 4), array_slice($new, $i, 4)), 'opaque and hidden colors preserved at pixel ' . ($i / 4));
        }
    }
    verify($new[(2 * 9 + 4) * 4] < 0.03, 'light fringe takes its dark contour color');
    verify(MG_Image_Utils::clean_transparent_edges($image) === 0, 'correction is idempotent on this contour');
    $reversed = clone $before;
    $reversed->rotateImage(new ImagickPixel('transparent'), 180);
    MG_Image_Utils::clean_transparent_edges($reversed);
    $reversed->rotateImage(new ImagickPixel('transparent'), 180);
    verify(unchanged($new, pixels($reversed)), 'row processing does not propagate edits or depend on scan direction');
    foreach (array(
        'opaque white outline' => array(array(1.0, 1.0, 1.0, 1.0), array(0.02, 0.02, 0.02, 1.0)),
        'real white artwork and its antialiasing' => array(array(1.0, 1.0, 1.0, 0.7), array(1.0, 1.0, 1.0, 1.0)),
        'already clean dark edge' => array(array(0.02, 0.02, 0.02, 0.7), array(0.02, 0.02, 0.02, 1.0)),
        'no opaque contour reference' => array(array(0.8, 0.8, 0.8, 0.7), array(0.02, 0.02, 0.02, 0.7)),
    ) as $label => [$edge, $core]) {
        $sample = fixture($edge, $core);
        $original = pixels($sample);
        verify(MG_Image_Utils::clean_transparent_edges($sample) === 0 && unchanged($original, pixels($sample)), 'preserves ' . $label);
        $sample->clear();
    }
    foreach (array(array(0.9, 0.1, 0.2, 1.0), array(1.0, 1.0, 1.0, 1.0), array(0.0, 0.0, 0.0, 1.0)) as $core) {
        $sample = fixture(array(0.1, 0.6, 0.9, 0.7), $core);
        verify(MG_Image_Utils::clean_transparent_edges($sample) === 16, 'colored fringe corrected for each contour color');
        verify(unchanged(array_slice(pixels($sample), (2 * 9 + 4) * 4, 3), array_slice($core, 0, 3)), 'edge inherits actual interior color');
        $sample->clear();
    }
    // One-row artwork exercises canvas edges, the three-step limit and gaps.
    $line = new Imagick();
    $line->newImage(8, 1, new ImagickPixel('transparent'), 'png');
    $line->importImagePixels(0, 0, 8, 1, 'RGBA', Imagick::PIXEL_FLOAT, array(
        0,0,0,1, 0.8,0.8,0.8,0.7, 0.8,0.8,0.8,0.7, 0.8,0.8,0.8,0.7,
        0.8,0.8,0.8,0.7, 1,1,1,0, 0.8,0.8,0.8,0.7, 0.8,0.8,0.8,0.7
    ));
    verify(MG_Image_Utils::clean_transparent_edges($line) === 3, 'exactly three connected fringe layers corrected');
    $lp = $line->exportImagePixels(0, 0, 8, 1, 'RGBA', Imagick::PIXEL_FLOAT);
    verify($lp[12] === 0.0 && $lp[16] > 0.79, 'fourth layer stays outside radius');
    verify($lp[24] > 0.79 && $lp[28] > 0.79, 'does not jump transparent gap into detached artwork');
    verify(array_slice($lp, 20, 4) === array(0.0,0.0,0.0,0.0), 'hidden RGB normalized to transparent black');
    $line->clear();
    $line->newImage(3, 1, new ImagickPixel('transparent'), 'png');
    $line->importImagePixels(0, 0, 3, 1, 'RGBA', Imagick::PIXEL_FLOAT, array(1,0,0,250/255, 0,1,0,1/255, 0,0,1,1));
    MG_Image_Utils::clean_transparent_edges($line);
    $lp = $line->exportImagePixels(0, 0, 3, 1, 'RGBA', Imagick::PIXEL_FLOAT);
    verify(unchanged(array_slice($lp,4,4), array(1,0,0,1/255)), '250 alpha seeds and tiny alpha edges follow Design Flow direction priority');
    $line->clear();
    // The recolored black fringe disappears during black-free export, while
    // the white lettering inside remains opaque.
    $sample = fixture(array(0.8,0.8,0.8,0.7), array(0,0,0,1));
    $sample->importImagePixels(4,4,1,1,'RGBA',Imagick::PIXEL_FLOAT,array(1,1,1,1));
    MG_Image_Utils::clean_transparent_edges($sample);
    $sample->resizeImage(27,27,Imagick::FILTER_LANCZOS,1.0);
    MG_Image_Utils::threshold_alpha_binary($sample);
    MG_Image_Utils::strip_color_to_transparent($sample, 'black', 25.0, true);
    MG_Image_Utils::threshold_alpha_binary($sample);
    $sp = $sample->exportImagePixels(0,0,27,27,'RGBA',Imagick::PIXEL_FLOAT);
    verify($sp[(6*27+12)*4+3] === 0.0, 'original pale outer edge disappears with black after enlargement');
    verify($sp[(13*27+13)*4] > 0.99 && $sp[(13*27+13)*4+3] === 1.0, 'white letter interior survives black removal');
    $sample->clear();
    $opaque = new Imagick();
    $opaque->newImage(9, 9, new ImagickPixel('white'), 'png');
    verify(MG_Image_Utils::clean_transparent_edges($opaque) === 0, 'opaque image is untouched');
    $opaque->clear();

    // Native pipeline check and inspectable artifacts, no mocked resizing/alpha methods.
    $artifact_dir = sys_get_temp_dir() . '/mg-fringe-qa';
    if (!is_dir($artifact_dir)) mkdir($artifact_dir);
    foreach (array('before' => $before, 'after' => $image) as $name => $source) {
        $preview = clone $source;
        $preview->resizeImage(27, 27, Imagick::FILTER_LANCZOS, 1.0);
        MG_Image_Utils::threshold_alpha_binary($preview);
        $preview->setImageFormat('png');
        $preview->writeImage($artifact_dir . '/' . $name . '.png');
        $roundtrip = new Imagick($artifact_dir . '/' . $name . '.png');
        verify($roundtrip->getImageWidth() === 27 && $roundtrip->getImageHeight() === 27, $name . ' survives actual 3x resize and PNG round trip');
        $alpha = $roundtrip->exportImagePixels(0, 0, 27, 27, 'A', Imagick::PIXEL_FLOAT);
        verify(count(array_filter($alpha, fn($a) => $a > 0.00002 && $a < 0.99998)) === 0, $name . ' exports binary DTF alpha');
        $preview->clear(); $roundtrip->clear();
    }
    $after_png = new Imagick($artifact_dir . '/after.png');
    $colors = $after_png->exportImagePixels(0, 0, 27, 27, 'RGBA', Imagick::PIXEL_FLOAT);
    $bright_opaque = 0;
    for ($i = 0; $i < count($colors); $i += 4) if ($colors[$i + 3] > 0.99 && $colors[$i] > 0.15) $bright_opaque++;
    verify($bright_opaque === 0, 'no white halo reappears after native enlargement and alpha threshold');
    $after_png->clear();
    $comparison = new Imagick();
    $comparison->newImage(570, 290, new ImagickPixel('#606060'), 'png');
    foreach (array('before', 'after') as $index => $name) {
        $panel = new Imagick($artifact_dir . '/' . $name . '.png');
        $panel->resizeImage(270, 270, Imagick::FILTER_POINT, 1.0);
        $comparison->compositeImage($panel, Imagick::COMPOSITE_OVER, 10 + $index * 280, 10);
        $panel->clear();
    }
    $comparison->writeImage($artifact_dir . '/comparison.png');
    $comparison->clear();
    $production = new Imagick();
    $production->newImage(832, 832, new ImagickPixel('transparent'), 'png');
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('rgba(204,204,204,0.7)'));
    $draw->rectangle(100, 100, 732, 732);
    $draw->setFillColor(new ImagickPixel('black'));
    $draw->rectangle(101, 101, 731, 731);
    $production->drawImage($draw);
    $started = microtime(true);
    verify(MG_Image_Utils::clean_transparent_edges($production) > 2000, 'processes an AI-sized image with a thin light border');
    echo '832x832 correction time: ' . round(microtime(true) - $started, 3) . " s\n";
    $production->clear(); $draw->clear();
    echo "\n" . $checks . ' checks passed with real Imagick ' . phpversion('imagick') . '. Artifacts: ' . $artifact_dir . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
