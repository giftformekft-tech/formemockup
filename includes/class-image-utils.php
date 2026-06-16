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
