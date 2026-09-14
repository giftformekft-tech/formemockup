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
    public static function strip_color_to_transparent($image, $color = 'black', $fuzz_percent = 25.0) {
        if (!($image instanceof Imagick) || !method_exists($image, 'transparentPaintImage')) {
            return;
        }
        try {
            $range         = Imagick::getQuantumRange();
            $quantum_range = isset($range['quantumRangeLong']) ? $range['quantumRangeLong'] : 255;
            $fuzz          = ($fuzz_percent / 100) * $quantum_range;
            $image->transparentPaintImage(new ImagickPixel($color), 0, $fuzz, false);
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Pull dark contour color into pale, semi-transparent fringe pixels.
     * Only a two-pixel boundary band is considered, at the native AI size.
     * Opaque whites, colored edges and alpha/geometry are never removed.
     * Rolling ORIGINAL rows prevent corrections from spreading into the artwork.
     * Returns the number of corrected pixels. No erosion or global white removal.
     */
    public static function reduce_white_fringe($image) {
        if (!($image instanceof Imagick)) {
            throw new RuntimeException('A fehér perem korrekciója Imagick képet igényel.');
        }
        if (!$image->getImageAlphaChannel()) return 0;
        if (!method_exists($image, 'exportImagePixels') || !method_exists($image, 'importImagePixels') || !defined('Imagick::PIXEL_FLOAT')) {
            throw new RuntimeException('Az Imagick nem támogatja a fehér perem korrekcióját. Kapcsold ki a nyomatmodell beállításainál.');
        }
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $rows = array();
        $corrected = 0;
        for ($y = 0; $y < $height; $y++) {
            unset($rows[$y - 3]);
            for ($ny = max(0, $y - 2); $ny <= min($height - 1, $y + 2); $ny++) {
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
                $color = array($rows[$y][$offset], $rows[$y][$offset + 1], $rows[$y][$offset + 2]);
                $alpha = $rows[$y][$offset + 3];
                $minimum = min($color);
                // Near-neutral, partially transparent pixels only. A fully opaque
                // white outline cannot safely be distinguished from intended ink.
                if ($alpha > 0.02 && $alpha < 0.995 && $minimum >= 0.15 && max($color) - $minimum <= 0.12) {
                    $near_clear = false;
                    $nearest = 9;
                    $sum = array(0.0, 0.0, 0.0);
                    $samples = 0;
                    for ($dy = -2; $dy <= 2; $dy++) {
                        for ($dx = -2; $dx <= 2; $dx++) {
                            if ($dx === 0 && $dy === 0) continue;
                            $nx = $x + $dx;
                            $ny = $y + $dy;
                            if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                                $near_clear = true;
                                continue;
                            }
                            $no = $nx * 4;
                            $neighbor_alpha = $rows[$ny][$no + 3];
                            if ($neighbor_alpha <= 0.02) $near_clear = true;
                            $distance = $dx * $dx + $dy * $dy;
                            if ($neighbor_alpha < 0.995 || $distance > $nearest) continue;
                            if ($distance < $nearest) {
                                $sum = array(0.0, 0.0, 0.0);
                                $samples = 0;
                                $nearest = $distance;
                            }
                            for ($c = 0; $c < 3; $c++) $sum[$c] += $rows[$ny][$no + $c];
                            $samples++;
                        }
                    }
                    if ($near_clear && $samples > 0) {
                        $inner = array($sum[0] / $samples, $sum[1] / $samples, $sum[2] / $samples);
                        // Choose the nearest opaque color, even if it is white:
                        // do not jump over a genuine light feature to find black.
                        if (max($inner) <= 0.25 && max($inner) - min($inner) <= 0.12 && $minimum - max($inner) >= 0.15) {
                            $color = $inner;
                            $changed = true;
                            $corrected++;
                        }
                    }
                }
                $rgb[] = $color[0];
                $rgb[] = $color[1];
                $rgb[] = $color[2];
            }
            // RGB-only import leaves the original alpha samples untouched.
            if ($changed && !$image->importImagePixels(0, $y, $width, 1, 'RGB', Imagick::PIXEL_FLOAT, $rgb)) {
                throw new RuntimeException('Nem sikerült a nyomat fehér peremét korrigálni.');
            }
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
