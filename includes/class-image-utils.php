<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared Imagick helpers used by both the mockup generator and the
 * order design export (production PNG) pipeline.
 */
class MG_Image_Utils {

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
     * Rewrites a PNG as an indexed (palette) PNG of at most $colors colors,
     * in place, the way the Design-flow app finishes its upscales: fully
     * transparent RGB is zeroed, colors are quantized without dithering,
     * antialiased edges keep their alpha (a full tRNS table; alpha below 6
     * becomes fully transparent) and the transparent margins are cropped by
     * alpha only, never by color, so an opaque background stays.
     *
     * Imagick quantizes (native code) but cannot write a palette PNG with
     * per-entry alpha reliably across versions, so the file is encoded here,
     * row by row to keep memory flat on large prints.
     *
     * @return array{width:int,height:int,colors:int}
     * @throws RuntimeException If the image cannot be converted completely.
     */
    public static function to_palette_png($path, $colors = 255) {
        $colors = max(2, min(255, (int) $colors));
        try {
            $image = new Imagick($path);
            try {
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $image->setImageBackgroundColor(new ImagickPixel('rgba(0,0,0,0)'));
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_BACKGROUND);
                $image->quantizeImage($colors, Imagick::COLORSPACE_SRGB, 0, false, false);
                $width = $image->getImageWidth();
                $height = $image->getImageHeight();
                $image->setImageDepth(8);
                $image->setImageFormat('rgba');
                $raw = $image->getImageBlob();
            } finally {
                $image->clear();
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Nem sikerült a PNG-t palettás színekre bontani.', 0, $e);
        }
        $stride = $width * 4;
        if ($width < 1 || $height < 1 || strlen($raw) !== $stride * $height) {
            throw new RuntimeException('A palettás átalakítás hibás képadatot adott.');
        }

        // Palette index per RGBA value; near-invisible pixels share one fully
        // transparent black entry (Design-flow's smooth alpha cutoff).
        $transparent = "\0\0\0\0";
        $index = array();
        $entries = array();
        for ($y = 0; $y < $height; $y++) {
            foreach (array_keys(array_count_values(str_split(substr($raw, $y * $stride, $stride), 4))) as $rgba) {
                if (isset($index[$rgba])) {
                    continue;
                }
                $key = ord($rgba[3]) < 6 ? $transparent : $rgba;
                if (!isset($entries[$key])) {
                    $entries[$key] = count($entries);
                }
                $index[$rgba] = chr($entries[$key]);
            }
        }
        if (count($entries) > 256) {
            throw new RuntimeException('A kép több színt tartalmaz, mint amennyi egy palettába fér.');
        }
        $plte = $trns = '';
        foreach (array_keys($entries) as $rgba) {
            $plte .= substr($rgba, 0, 3);
            $trns .= $rgba[3];
        }
        $trns = rtrim($trns, "\xFF");
        $lines = array();
        for ($y = 0; $y < $height; $y++) {
            $lines[] = strtr(substr($raw, $y * $stride, $stride), $index);
        }
        unset($raw);
        // Alpha bounding box from the index rows (native strspn scans).
        if (isset($entries[$transparent])) {
            $clear = chr($entries[$transparent]);
            $top = $bottom = null;
            $left = $width;
            $right = 0;
            foreach ($lines as $y => $line) {
                $lead = strspn($line, $clear);
                if ($lead === $width) {
                    continue;
                }
                $top = $top ?? $y;
                $bottom = $y;
                $left = min($left, $lead);
                $right = max($right, $width - strspn(strrev($line), $clear));
            }
            if ($top === null) {
                throw new RuntimeException('A kép teljesen átlátszó, nincs mit palettásra alakítani.');
            }
            $lines = array_slice($lines, $top, $bottom - $top + 1);
            if ($left > 0 || $right < $width) {
                foreach ($lines as $y => $line) {
                    $lines[$y] = substr($line, $left, $right - $left);
                }
            }
            $width = $right - $left;
            $height = $bottom - $top + 1;
        }
        $rows = "\0" . implode("\0", $lines);
        unset($lines);
        $chunk = function ($type, $data) {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 3, 0, 0, 0))
            . $chunk('PLTE', $plte)
            . ($trns !== '' ? $chunk('tRNS', $trns) : '')
            . $chunk('IDAT', gzcompress($rows, 9))
            . $chunk('IEND', '');
        unset($rows);
        $tmp = $path . '.palette-tmp';
        if (file_put_contents($tmp, $png, LOCK_EX) !== strlen($png) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('A palettás PNG nem menthető.');
        }
        return array('width' => $width, 'height' => $height, 'colors' => count($entries));
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
