<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared Imagick helpers used by both the mockup generator and the
 * order design export (production PNG) pipeline.
 */
class MG_Image_Utils {

    /** Exception code: an Imagick step lost the colors (seen on ImageMagick 7.1.2 Q16-HDRI). */
    const COLOR_LOSS = 4201;

    /**
     * Opaque pixels (alpha >= 250), every 4th on about 64 evenly spaced rows:
     * no step of the upscale preparation may change their color.
     *
     * @return array<int, array<int, int[]>> y => x => RGB
     */
    public static function opaque_probe($image) {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $probe = array();
        for ($y = 0; $y < $height; $y += max(1, (int) floor($height / 64))) {
            $row = $image->exportImagePixels(0, $y, $width, 1, 'RGBA', Imagick::PIXEL_CHAR);
            for ($x = 0; $x < $width; $x += 4) {
                if ($row[$x * 4 + 3] >= 250) {
                    $probe[$y][$x] = array($row[$x * 4], $row[$x * 4 + 1], $row[$x * 4 + 2]);
                }
            }
        }
        return $probe;
    }

    /** Mean RGB of the probe in $image, which may be cropped at ($dx, $dy); null if empty. */
    public static function probe_mean($image, array $probe, $dx = 0, $dy = 0) {
        $sum = array(0, 0, 0);
        $count = 0;
        $width = $image ? $image->getImageWidth() : 0;
        foreach ($probe as $y => $pixels) {
            $row = $image ? $image->exportImagePixels(0, $y - $dy, $width, 1, 'RGB', Imagick::PIXEL_CHAR) : null;
            foreach ($pixels as $x => $rgb) {
                $color = $row ? array_slice($row, ($x - $dx) * 3, 3) : $rgb;
                $sum[0] += $color[0];
                $sum[1] += $color[1];
                $sum[2] += $color[2];
                $count++;
            }
        }
        return $count ? array($sum[0] / $count, $sum[1] / $count, $sum[2] / $count) : null;
    }

    /**
     * Throws COLOR_LOSS when the visible colors moved far: a quantize or a crop
     * shifts the mean by a few levels, a step that blackens the design by far more.
     */
    public static function assert_colors_kept($before, $after, $step) {
        if ($before === null || $after === null) {
            return;
        }
        $shift = max(abs($before[0] - $after[0]), abs($before[1] - $after[1]), abs($before[2] - $after[2]));
        if ($shift > 40) {
            $version = Imagick::getVersion();
            throw new RuntimeException(sprintf(
                'A szerver ImageMagickje (%s) elrontotta a minta színeit: %s.',
                preg_replace('/ https?:.*$/', '', (string) ($version['versionString'] ?? '?')),
                $step
            ), self::COLOR_LOSS);
        }
    }

    /**
     * Trims fully transparent margins from every side of an Imagick image,
     * in place. Idempotent (marks the image so repeated calls are no-ops).
     */
    public static function trim_transparent_bounds($image) {
        if (!($image instanceof Imagick)) {
            return;
        }
        if (method_exists($image, 'getImageProperty')) {
            try {
                $already_trimmed = $image->getImageProperty('mg_trimmed');
                if ($already_trimmed === '1') {
                    return;
                }
            } catch (Throwable $ignored) {
            }
        }
        if (!method_exists($image, 'trimImage')) {
            return;
        }
        try {
            $image->trimImage(0);
            if (method_exists($image, 'setImagePage')) {
                $image->setImagePage(0, 0, 0, 0);
            }
            if (method_exists($image, 'setImageProperty')) {
                $image->setImageProperty('mg_trimmed', '1');
            }
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Rotates an Imagick image 90 degrees if it's wider than it is tall,
     * so landscape-oriented patterns become portrait before being sized
     * to a target print height. Does nothing to already-portrait/square
     * images.
     */
    public static function rotate_landscape_to_portrait($image) {
        if (!($image instanceof Imagick) || !method_exists($image, 'rotateImage')) {
            return;
        }
        try {
            $width  = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width <= $height) {
                return;
            }
            $image->rotateImage(new ImagickPixel('transparent'), 90);
            if (method_exists($image, 'setImagePage')) {
                $image->setImagePage(0, 0, 0, 0);
            }
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Rotates an Imagick image 90 degrees if it's taller than it is wide,
     * so portrait-oriented patterns become landscape. Used for the "nagy
     * méret PNG" surcharge option, where the export must always be
     * landscape regardless of the source design's orientation. Does
     * nothing to already-landscape/square images.
     */
    public static function rotate_portrait_to_landscape($image) {
        if (!($image instanceof Imagick) || !method_exists($image, 'rotateImage')) {
            return;
        }
        try {
            $width  = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($height <= $width) {
                return;
            }
            $image->rotateImage(new ImagickPixel('transparent'), 90);
            if (method_exists($image, 'setImagePage')) {
                $image->setImagePage(0, 0, 0, 0);
            }
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Makes pixels matching the given color transparent, with a fuzz
     * tolerance so anti-aliased edges (slightly off-target shades at the
     * border of a shape) are caught too. Used by the optional "fekete
     * kivétel" export choice, so black design elements don't get printed
     * on black garments.
     */
    public static function strip_color_to_transparent($image, $color = 'black', $fuzz_percent = 25.0, $throw_on_error = false) {
        if (!($image instanceof Imagick) || !method_exists($image, 'transparentPaintImage')) {
            if ($throw_on_error) throw new RuntimeException('Az Imagick nem támogatja a nyomat fekete részeinek eltávolítását.');
            return;
        }
        try {
            $range         = Imagick::getQuantumRange();
            $quantum_range = isset($range['quantumRangeLong']) ? $range['quantumRangeLong'] : 255;
            $fuzz          = ($fuzz_percent / 100) * $quantum_range;
            if ($image->transparentPaintImage(new ImagickPixel($color), 0, $fuzz, false) === false && $throw_on_error) {
                throw new RuntimeException('Sikertelen színeltávolítás.');
            }
        } catch (Throwable $e) {
            if ($throw_on_error) throw new RuntimeException('Nem sikerült a nyomat fekete részeinek eltávolítása.', 0, $e);
        }
    }

    /**
     * Design Flow AI edge cleanup: propagate interior RGB through at most three
     * connected alpha-positive pixels, using 8-neighbor layers. Never cross a
     * transparent gap. Alpha and interior seeds (alpha >= 250/255) stay intact.
     * Direction priority matches Design Flow's NumPy implementation.
     * Packed masks and rolling rows avoid full-image PHP pixel arrays.
     * Returns the number of visibly recolored pixels (hidden RGB also clears).
     */
    public static function clean_transparent_edges($image) {
        if (!($image instanceof Imagick)) {
            throw new RuntimeException('A peremkorrekció Imagick képet igényel.');
        }
        if (!$image->getImageAlphaChannel()) return 0;
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $size = $width * $height;
        $frontier = str_repeat("0", $size);
        for ($y = 0; $y < $height; $y++) {
            $alpha = $image->exportImagePixels(0, $y, $width, 1, 'A', Imagick::PIXEL_FLOAT);
            if (!is_array($alpha) || count($alpha) !== $width) {
                throw new RuntimeException('Nem olvasható a nyomat átlátszósága.');
            }
            foreach ($alpha as $x => $value) {
                if ($value >= 250 / 255) $frontier[$y * $width + $x] = "1";
            }
        }
        $visited = $frontier;
        $directions = array(array(-1,-1), array(-1,0), array(-1,1), array(0,-1), array(0,1), array(1,-1), array(1,0), array(1,1));
        $corrected = 0;
        for ($step = 0; $step < 3; $step++) {
            $next = str_repeat("0", $size);
            $reached = false;
            $rows = array();
            for ($y = 0; $y < $height; $y++) {
                unset($rows[$y - 2]);
                for ($ny = max(0, $y - 1); $ny <= min($height - 1, $y + 1); $ny++) {
                    if (!isset($rows[$ny])) {
                        $rows[$ny] = $image->exportImagePixels(0, $ny, $width, 1, 'RGBA', Imagick::PIXEL_FLOAT);
                        if (!is_array($rows[$ny]) || count($rows[$ny]) !== $width * 4) {
                            throw new RuntimeException('Nem olvashatók a nyomat szélpixelei.');
                        }
                    }
                }
                $rgb = array();
                $changed = false;
                for ($x = 0; $x < $width; $x++) {
                    $offset = $x * 4;
                    $index = $y * $width + $x;
                    $color = array($rows[$y][$offset], $rows[$y][$offset + 1], $rows[$y][$offset + 2]);
                    $alpha = $rows[$y][$offset + 3];
                    if ($alpha <= 0) {
                        if ($color !== array(0.0, 0.0, 0.0)) $changed = true;
                        $color = array(0.0, 0.0, 0.0);
                    } elseif ($visited[$index] === "0") {
                        foreach ($directions as [$dy, $dx]) {
                            $nx = $x + $dx;
                            $ny = $y + $dy;
                            if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height || $frontier[$ny * $width + $nx] !== "1") continue;
                            $no = $nx * 4;
                            $inner = array($rows[$ny][$no], $rows[$ny][$no + 1], $rows[$ny][$no + 2]);
                            if ($color !== $inner) {
                                $color = $inner;
                                $changed = true;
                                $corrected++;
                            }
                            $visited[$index] = "1";
                            $next[$index] = "1";
                            $reached = true;
                            break;
                        }
                    }
                    foreach ($color as $channel) $rgb[] = $channel;
                }
                // RGB-only imports preserve all alpha values exactly.
                if ($changed && !$image->importImagePixels(0, $y, $width, 1, 'RGB', Imagick::PIXEL_FLOAT, $rgb)) {
                    throw new RuntimeException('Nem sikerült a nyomat peremét korrigálni.');
                }
            }
            if (!$reached) break;
            $frontier = $next;
        }
        return $corrected;
    }

    /**
     * Converts a PNG's alpha channel to a hard, binary mask in place.
     *
     * Pixels below the 0-255 alpha threshold become transparent black. Pixels
     * at or above it become fully opaque while retaining their original RGB
     * values. This removes semi-transparent edge pixels that can produce a
     * white halo during DTF printing.
     *
     * @param Imagick $image
     * @param int     $threshold Alpha threshold in the 0-255 range.
     * @throws RuntimeException If the image cannot be processed completely.
     */
    public static function threshold_alpha_binary($image, $threshold = 128) {
        if (!($image instanceof Imagick)) {
            throw new RuntimeException('Az alpha-csatorna feldolgozása Imagick képet igényel.');
        }

        $threshold = max(0, min(255, (int) $threshold));

        try {
            // Images without an explicit alpha channel must be treated as
            // fully opaque instead of accidentally receiving a blank mask.
            if (!method_exists($image, 'setImageAlphaChannel') ||
                !defined('Imagick::ALPHACHANNEL_ACTIVATE') ||
                !defined('Imagick::ALPHACHANNEL_BACKGROUND')) {
                throw new RuntimeException('A telepített Imagick verzió nem támogatja a szükséges alpha-műveleteket.');
            }
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

            $range = Imagick::getQuantumRange();
            if (!isset($range['quantumRangeLong']) || $range['quantumRangeLong'] <= 0) {
                throw new RuntimeException('Nem határozható meg az Imagick alpha-tartománya.');
            }

            // The half-step boundary makes 127 transparent and 128 opaque.
            // thresholdImage performs the per-pixel scan in native code.
            $quantum_threshold = (($threshold - 0.5) / 255) * $range['quantumRangeLong'];
            $image->thresholdImage($quantum_threshold, Imagick::CHANNEL_ALPHA);

            // Normalize the hidden RGB values of fully transparent pixels.
            // ALPHACHANNEL_BACKGROUND leaves their alpha at zero.
            $image->setImageBackgroundColor(new ImagickPixel('rgba(0,0,0,0)'));
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_BACKGROUND);
        } catch (Throwable $e) {
            throw new RuntimeException('Nem sikerült binárissá alakítani a PNG alpha-csatornáját.', 0, $e);
        }
    }

    /**
     * Prepares a generated PNG for the AI upscale, in place, the way the
     * Design-flow app prepares its upscale input: edge cleanup (optional),
     * binary alpha (optional, 128 and up opaque by default), then the
     * transparent margins cropped by alpha only. Clean, hard edges keep the
     * upscaler from enlarging colored fringes; without the binary alpha the
     * antialiased edge goes in as it is. Opaque images are left untouched.
     *
     * @param int $alpha_threshold 1-255 binary alpha threshold, 0 keeps the partial alpha.
     * @return array{width:int,height:int,changed:bool}
     * @throws RuntimeException If the image cannot be processed completely.
     */
    public static function prepare_upscale_input($path, $clean_edges = true, $alpha_threshold = 128) {
        $image = new Imagick($path);
        try {
            $range = $image->getImageAlphaChannel() ? $image->getImageChannelRange(Imagick::CHANNEL_ALPHA) : null;
            $quantum = Imagick::getQuantumRange();
            if ($range === null || $range['minima'] >= $quantum['quantumRangeLong']) {
                return array('width' => $image->getImageWidth(), 'height' => $image->getImageHeight(), 'changed' => false);
            }
            // Opaque pixels only: the edge cleanup and the binary alpha change the others on purpose.
            $probe = self::opaque_probe($image);
            if ($clean_edges) {
                self::clean_transparent_edges($image);
            }
            $alpha_threshold = (int) $alpha_threshold;
            if ($alpha_threshold > 0) {
                self::threshold_alpha_binary($image, $alpha_threshold);
            }
            list($x, $y, $width, $height) = self::alpha_bounds($image);
            $crop = $x > 0 || $y > 0 || $width < $image->getImageWidth() || $height < $image->getImageHeight();
            if (!$crop && !$clean_edges && $alpha_threshold <= 0) {
                return array('width' => $width, 'height' => $height, 'changed' => false);
            }
            if ($crop) {
                $image->cropImage($width, $height, $x, $y);
                $image->setImagePage(0, 0, 0, 0);
            }
            $image->setImageFormat('png');
            $image->setImageDepth(8);
            $image->setOption('png:color-type', '6');
            $image->setOption('png:bit-depth', '8');
            $tmp = $path . '.prepared-tmp';
            if (!$image->writeImage($tmp)) {
                @unlink($tmp);
                throw new RuntimeException('Az előkészített PNG nem menthető.');
            }
            $written = new Imagick($tmp);
            try {
                self::assert_colors_kept(self::probe_mean(null, $probe), self::probe_mean($written, $probe, $x, $y), 'a felskálázás előtti előkészítés');
            } catch (RuntimeException $e) {
                @unlink($tmp);
                throw $e;
            } finally {
                $written->clear();
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Az előkészített PNG nem menthető.');
            }
            return array('width' => $width, 'height' => $height, 'changed' => true);
        } finally {
            $image->clear();
        }
    }

    /**
     * Bounding box of the non-transparent pixels, from the alpha channel only
     * (trimImage() would trim by the corner color, also on opaque artwork).
     *
     * @return array{0:int,1:int,2:int,3:int} x, y, width, height
     */
    public static function alpha_bounds($image) {
        $mask = clone $image;
        try {
            $mask->setImageAlphaChannel(Imagick::ALPHACHANNEL_EXTRACT);
            $mask->setImageDepth(8);
            $mask->setImageFormat('gray');
            $raw = $mask->getImageBlob();
            $width = $mask->getImageWidth();
            $height = $mask->getImageHeight();
        } finally {
            $mask->clear();
        }
        if (strlen($raw) !== $width * $height) {
            throw new RuntimeException('Nem olvasható a kép átlátszósága.');
        }
        $top = $bottom = null;
        $left = $width;
        $right = 0;
        for ($y = 0; $y < $height; $y++) {
            $line = substr($raw, $y * $width, $width);
            $lead = strspn($line, "\0");
            if ($lead === $width) {
                continue;
            }
            $top = $top ?? $y;
            $bottom = $y;
            $left = min($left, $lead);
            $right = max($right, $width - strspn(strrev($line), "\0"));
        }
        if ($top === null) {
            throw new RuntimeException('A kép az átlátszóság küszöbölése után teljesen átlátszó lett.');
        }
        return array($left, $top, $right - $left, $bottom - $top + 1);
    }

    /**
     * Palette entries of an indexed PNG (its PLTE chunk), 0 for any other PNG.
     * The browser writes the Design-flow 255-color palette PNG
     * (assets/js/palette-png.js); the server only reads it back.
     */
    public static function png_palette_size($path) {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return 0;
        }
        try {
            if (fread($handle, 8) !== "\x89PNG\r\n\x1a\n") {
                return 0;
            }
            while (($head = fread($handle, 8)) !== false && strlen($head) === 8) {
                $length = unpack('N', substr($head, 0, 4))[1];
                $type = substr($head, 4, 4);
                if ($type === 'IHDR') {
                    $ihdr = fread($handle, $length);
                    if (strlen($ihdr) < 10 || ord($ihdr[9]) !== 3) {
                        return 0;
                    }
                    fseek($handle, 4, SEEK_CUR);
                    continue;
                }
                if ($type === 'PLTE') {
                    return intdiv($length, 3);
                }
                if ($type === 'IDAT' || $type === 'IEND') {
                    return 0;
                }
                fseek($handle, $length + 4, SEEK_CUR);
            }
            return 0;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Stamps an exact DPI into a PNG file's pHYs chunk by editing the raw
     * bytes directly, bypassing Imagick's setImageResolution()/setImageUnits()
     * entirely. Those calls don't reliably survive to the written file across
     * Imagick/libpng versions (observed: files still reporting 96 DPI even
     * after calling them), so this guarantees the embedded value regardless
     * of which image library produced the file.
     */
    public static function force_png_dpi($path, $dpi) {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 8 || substr($data, 0, 8) !== "\x89PNG\x0d\x0a\x1a\x0a") {
            return false;
        }

        $pixels_per_meter = (int) round($dpi / 0.0254);
        $phys_chunk = self::build_png_chunk('pHYs', pack('NNC', $pixels_per_meter, $pixels_per_meter, 1));

        $offset    = 8;
        $length    = strlen($data);
        $ihdr_end  = 0;
        while ($offset + 8 <= $length) {
            $chunk_len    = unpack('N', substr($data, $offset, 4))[1];
            $chunk_type   = substr($data, $offset + 4, 4);
            $chunk_total  = 8 + $chunk_len + 4;

            if ($chunk_type === 'pHYs') {
                $data   = substr($data, 0, $offset) . substr($data, $offset + $chunk_total);
                $length = strlen($data);
                continue;
            }

            if ($chunk_type === 'IHDR') {
                $ihdr_end = $offset + $chunk_total;
            }

            $offset += $chunk_total;
        }

        if ($ihdr_end === 0) {
            return false;
        }

        $data = substr($data, 0, $ihdr_end) . $phys_chunk . substr($data, $ihdr_end);
        return @file_put_contents($path, $data) !== false;
    }

    private static function build_png_chunk($type, $data) {
        $length = pack('N', strlen($data));
        $crc    = pack('N', crc32($type . $data));
        return $length . $type . $data . $crc;
    }
}
