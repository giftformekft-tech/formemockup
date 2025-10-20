<?php
if (!defined('ABSPATH')) exit;
class MG_Generator {

    private $product_cache = null;

    private function get_product_definition($product_key) {
        if ($this->product_cache === null) {
            $raw_products = get_option('mg_products', array());
            $indexed = array();
            if (is_array($raw_products)) {
                foreach ($raw_products as $item) {
                    if (!is_array($item) || empty($item['key'])) { continue; }
                    $indexed[$item['key']] = $item;
                }
            }
            $this->product_cache = $indexed;
        }
        return $this->product_cache[$product_key] ?? null;
    }

    private function absolutize_override_path($path) {
        if (!is_string($path)) {
            return '';
        }
        $path = wp_normalize_path(trim($path));
        if ($path === '') {
            return '';
        }

        $candidates = array();
        $uploads = wp_upload_dir();
        $basedir = !empty($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        $baseurl = !empty($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') : '';

        $is_url = filter_var($path, FILTER_VALIDATE_URL);
        if ($is_url) {
            if ($baseurl && $basedir && strpos($path, $baseurl) === 0) {
                $relative = ltrim(substr($path, strlen($baseurl)), '/');
                $candidates[] = wp_normalize_path(trailingslashit($basedir) . $relative);
            } else {
                return '';
            }
        } else {
            $candidates[] = $path;
            if ($basedir !== '') {
                $candidates[] = wp_normalize_path(trailingslashit($basedir) . ltrim($path, '/'));
            }
            $candidates[] = wp_normalize_path(ABSPATH . ltrim($path, '/'));
            $plugin_root = wp_normalize_path(trailingslashit(dirname(__DIR__)));
            $candidates[] = wp_normalize_path($plugin_root . ltrim($path, '/'));
        }

        $checked = array();
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $candidate = wp_normalize_path($candidate);
            if (in_array($candidate, $checked, true)) {
                continue;
            }
            $checked[] = $candidate;
            if (file_exists($candidate) && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function collect_override_templates($product, $color_slug, $view_file) {
        $candidates = array();
        if (!empty($product['mockup_overrides'][$color_slug][$view_file])) {
            $override = $product['mockup_overrides'][$color_slug][$view_file];
            if (!is_array($override)) {
                $override = array($override);
            }
            foreach ($override as $path) {
                $path = $this->absolutize_override_path($path);
                if ($path === '' || in_array($path, $candidates, true)) { continue; }
                $candidates[] = $path;
            }
        }
        return $candidates;
    }

    private function resolve_default_template_path($product, $color_slug, $view_file) {
        $rel = trailingslashit($product['template_base']) . $color_slug . '/' . $view_file;
        $abs = ABSPATH . ltrim($rel, '/');
        if (file_exists($abs)) {
            return wp_normalize_path($abs);
        }
        $abs2 = plugin_dir_path(__FILE__) . '../' . ltrim($rel,'/');
        return wp_normalize_path($abs2);
    }

    private function webp_supported() {
        if (!class_exists('Imagick')) return false;
        try { $fmts = @Imagick::queryFormats('WEBP'); if (is_array($fmts) && !empty($fmts)) return true; }
        catch (Throwable $e) { return false; }
        return false;
    }

    public function generate_for_product($product_key, $design_path) {
        if (!$this->webp_supported()) {
            return new WP_Error('webp_unsupported', 'A szerveren nincs WEBP támogatás az Imagick-ben. Kérd meg a tárhelyszolgáltatót, vagy engedélyezd a WebP codert.');
        }
        $product = $this->get_product_definition($product_key);
        if (!$product) return new WP_Error('product_missing','Ismeretlen terméktípus: '.$product_key);
        $views = $product['views']; $colors = $product['colors'];
        if (empty($views)) return new WP_Error('views_missing','Nincs nézet.');
        if (empty($colors)) return new WP_Error('colors_missing','Nincs szín.');

        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['path'])) {
            return new WP_Error('upload_dir_missing', 'A feltöltési könyvtár nem elérhető.');
        }
        $upload_path = $upload_dir['path'];

        try {
            $design_base = new Imagick($design_path);
            if (method_exists($design_base, 'stripImage')) {
                $design_base->stripImage();
            }
        } catch (Throwable $e) {
            return new WP_Error('design_load_failed', $e->getMessage());
        }

        $out = [];
        try {
            foreach ($colors as $c) {
                $slug = $c['slug'];
                $out[$slug] = [];
                foreach ($views as $view) {
                    $templates_to_try = $this->collect_override_templates($product, $slug, $view['file']);
                    if (!empty($templates_to_try)) {
                        shuffle($templates_to_try);
                    }
                    $templates_to_try[] = $this->resolve_default_template_path($product, $slug, $view['file']);
                    $templates_to_try = array_values(array_filter(array_unique($templates_to_try)));
                    $outfile = $upload_path . '/mockup_' . $product['key'] . '_' . $slug . '_' . $view['key'] . '_' . uniqid() . '.webp';
                    $generated = false;
                    $last_error = null;
                    foreach ($templates_to_try as $template) {
                        if (!$template || !file_exists($template)) {
                            $last_error = new WP_Error('template_missing','Hiányzó template: '.$template);
                            continue;
                        }
                        $ok = $this->apply_imagick_webp_with_optional_resize($template, $design_base, $view, $outfile);
                        if ($ok === true) {
                            $generated = true;
                            break;
                        }
                        if (is_wp_error($ok)) {
                            $last_error = $ok;
                            if (file_exists($outfile)) { @unlink($outfile); }
                        }
                    }
                    if (!$generated) {
                        if (file_exists($outfile)) { @unlink($outfile); }
                        if ($last_error) {
                            return $last_error;
                        }
                        return new WP_Error('template_missing','Hiányzó template: '.$view['file']);
                    }
                    $out[$slug][] = $outfile;
                }
            }
        } finally {
            $design_base->clear();
            $design_base->destroy();
        }
        return $out;
    }

    // WebP output, preserve alpha, optional output resize from settings
    private function apply_imagick_webp_with_optional_resize($template_path, Imagick $design_base, $cfg, $outfile) {
        $template_width = 0;
        $template_height = 0;
        $probing = null;
        $max_area = (int)apply_filters('mg_mockup_template_max_area', 120000000);
        $filesize = 0;
        $size_hint = '';
        $scale_plan = null;
        try {
            $probing = new Imagick();
            $probing->pingImage($template_path);
            $template_width = (int)$probing->getImageWidth();
            $template_height = (int)$probing->getImageHeight();
            $filesize = @filesize($template_path);
        } catch (Throwable $e) {
            if ($probing instanceof Imagick) {
                $probing->clear();
                $probing->destroy();
            }
            return new WP_Error('imagick_error', sprintf('A mockup háttér nem olvasható (%s): %s', basename($template_path), $e->getMessage()));
        }

        $area = $template_width > 0 && $template_height > 0 ? ($template_width * $template_height) : 0;
        if ($probing instanceof Imagick) {
            $probing->clear();
            $probing->destroy();
        }

        if ($filesize > 0) {
            $size_hint = sprintf(' (~%.2f MB)', $filesize / 1048576);
        }

        if ($max_area > 0 && $area > $max_area) {
            if ($template_width <= 0 || $template_height <= 0) {
                return new WP_Error('mockup_template_too_large', 'A mockup háttér mérete nem állapítható meg, ezért nem skálázható biztonságosan.');
            }

            $scale_factor = sqrt($max_area / $area);
            if (!is_finite($scale_factor) || $scale_factor <= 0) {
                $megapixels = $area / 1000000;
                $message = sprintf(
                    'A mockup háttér túl nagy (%dx%d px ≈ %.1f MP%s). Csökkentsd a felbontást, vagy állíts be kisebb hátteret, majd próbáld újra.',
                    $template_width,
                    $template_height,
                    $megapixels,
                    $size_hint
                );
                return new WP_Error('mockup_template_too_large', $message);
            }

            $scaled_width = (int)floor($template_width * $scale_factor);
            $scaled_height = (int)floor($template_height * $scale_factor);

            if ($scaled_width < 1 || $scaled_height < 1) {
                $megapixels = $area / 1000000;
                $message = sprintf(
                    'A mockup háttér túl nagy (%dx%d px ≈ %.1f MP%s), és nem sikerült automatikusan lekicsinyíteni. Csökkentsd a felbontást, vagy állíts be kisebb hátteret, majd próbáld újra.',
                    $template_width,
                    $template_height,
                    $megapixels,
                    $size_hint
                );
                return new WP_Error('mockup_template_too_large', $message);
            }

            $scaled_area = $scaled_width * $scaled_height;
            if ($scaled_area > $max_area) {
                // Biztonsági ellenőrzés a kerekítési anomáliákhoz.
                $adjusted_scale = sqrt($max_area / ($scaled_area > 0 ? $scaled_area : 1));
                if (is_finite($adjusted_scale) && $adjusted_scale > 0) {
                    $scaled_width = max(1, (int)floor($scaled_width * $adjusted_scale));
                    $scaled_height = max(1, (int)floor($scaled_height * $adjusted_scale));
                    $scaled_area = $scaled_width * $scaled_height;
                }
            }

            if ($scaled_width < 1 || $scaled_height < 1 || ($max_area > 0 && $scaled_area > $max_area)) {
                $megapixels = $area / 1000000;
                $message = sprintf(
                    'A mockup háttér túl nagy (%dx%d px ≈ %.1f MP%s), és nem sikerült automatikusan lekicsinyíteni. Csökkentsd a felbontást, vagy állíts be kisebb hátteret, majd próbáld újra.',
                    $template_width,
                    $template_height,
                    $megapixels,
                    $size_hint
                );
                return new WP_Error('mockup_template_too_large', $message);
            }

            $scale_plan = array(
                'width'  => $scaled_width,
                'height' => $scaled_height,
                'factor' => $scale_factor,
            );
        }

        $mockup = null;
        $design = null;
        try {
            $mockup = new Imagick($template_path);
            $design = clone $design_base;

            if (method_exists('Imagick','setResourceLimit')) {
                $threads = max(1, (int)@ini_get('imagick.thread_limit') ?: 2);
                $mockup->setResourceLimit(Imagick::RESOURCETYPE_THREAD, $threads);
                $design->setResourceLimit(Imagick::RESOURCETYPE_THREAD, $threads);
            }
            if (method_exists($mockup,'stripImage')) $mockup->stripImage();
            if (method_exists($design,'stripImage')) $design->stripImage();

            $placement_scale = 1.0;
            if (is_array($scale_plan)) {
                if ($scale_plan['width'] !== $template_width || $scale_plan['height'] !== $template_height) {
                    $mockup->resizeImage($scale_plan['width'], $scale_plan['height'], Imagick::FILTER_LANCZOS, 1, true);
                    if (method_exists($mockup,'setImageAlphaChannel')) $mockup->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                }
                $scale_w = ($template_width > 0) ? ($scale_plan['width'] / $template_width) : 1.0;
                $scale_h = ($template_height > 0) ? ($scale_plan['height'] / $template_height) : 1.0;
                $candidates = array();
                if (is_finite($scale_w) && $scale_w > 0) { $candidates[] = $scale_w; }
                if (is_finite($scale_h) && $scale_h > 0) { $candidates[] = $scale_h; }
                if (!empty($candidates)) {
                    $placement_scale = min($candidates);
                }
                if ($placement_scale <= 0 || !is_finite($placement_scale)) {
                    $placement_scale = 1.0;
                }
                if (function_exists('do_action')) {
                    do_action(
                        'mg_mockup_template_scaled_down',
                        $template_path,
                        $template_width,
                        $template_height,
                        $scale_plan['width'],
                        $scale_plan['height'],
                        $placement_scale,
                        $max_area
                    );
                }
            }

            $target_w = isset($cfg['w']) ? (float)$cfg['w'] : 0.0;
            $target_h = isset($cfg['h']) ? (float)$cfg['h'] : 0.0;
            $target_x = isset($cfg['x']) ? (float)$cfg['x'] : 0.0;
            $target_y = isset($cfg['y']) ? (float)$cfg['y'] : 0.0;

            if ($placement_scale !== 1.0) {
                $target_w *= $placement_scale;
                $target_h *= $placement_scale;
                $target_x *= $placement_scale;
                $target_y *= $placement_scale;
            }

            $design_width_px = $target_w > 0 ? max(1, (int)round($target_w)) : 1;
            $design_height_px = $target_h > 0 ? max(1, (int)round($target_h)) : 1;
            $design_x_px = (int)round($target_x);
            $design_y_px = (int)round($target_y);

            if (method_exists($mockup,'setImageAlphaChannel')) $mockup->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            if (method_exists($design,'setImageAlphaChannel')) $design->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            if (method_exists($design,'setBackgroundColor')) $design->setBackgroundColor(new ImagickPixel('transparent'));

            if (method_exists($design,'thumbnailImage')) $design->thumbnailImage($design_width_px, $design_height_px, true, false);
            if (method_exists($design,'setImageAlphaChannel')) $design->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

            if (method_exists($mockup,'compositeImage')) $mockup->compositeImage($design, Imagick::COMPOSITE_OVER, $design_x_px, $design_y_px);

            // Optional output resize
            $resize = get_option('mg_output_resize', array('enabled'=>false,'max_w'=>0,'max_h'=>0,'mode'=>'fit'));
            $enabled = !empty($resize['enabled']);
            $max_w = max(0, intval($resize['max_w'] ?? 0));
            $max_h = max(0, intval($resize['max_h'] ?? 0));
            $mode  = $resize['mode'] ?? 'fit';

            if ($enabled && ($max_w > 0 || $max_h > 0)) {
                $cw = $mockup->getImageWidth();
                $ch = $mockup->getImageHeight();
                $target_w = $cw; $target_h = $ch;

                if ($mode === 'width' && $max_w > 0 && $cw > $max_w) {
                    $ratio = $max_w / $cw; $target_w = $max_w; $target_h = (int)round($ch * $ratio);
                } elseif ($mode === 'height' && $max_h > 0 && $ch > $max_h) {
                    $ratio = $max_h / $ch; $target_h = $max_h; $target_w = (int)round($cw * $ratio);
                } else { // fit
                    $limit_w = $max_w > 0 ? $max_w : $cw;
                    $limit_h = $max_h > 0 ? $max_h : $ch;
                    $scale = min($limit_w / $cw, $limit_h / $ch);
                    if ($scale < 1.0) {
                        $target_w = (int)floor($cw * $scale);
                        $target_h = (int)floor($ch * $scale);
                    }
                }
                if (($target_w != $cw) || ($target_h != $ch)) {
                    $mockup->resizeImage($target_w, $target_h, Imagick::FILTER_LANCZOS, 1, true);
                    if (method_exists($mockup,'setImageAlphaChannel')) $mockup->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                }
            }

            if (method_exists($mockup,'setImageFormat')) $mockup->setImageFormat('webp');
            if (method_exists($mockup,'setOption')) {
                $mockup->setOption('webp:method', '4');
                $mockup->setOption('webp:thread-level', '1');
                $mockup->setOption('webp:auto-filter', '1');
            }
            if (method_exists($mockup,'setImageCompressionQuality')) $mockup->setImageCompressionQuality(76);
            $mockup->writeImage($outfile);
            return true;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($template_width > 0 && $template_height > 0) {
                $area = $template_width * $template_height;
                $effective_width = is_array($scale_plan) ? $scale_plan['width'] : $template_width;
                $effective_height = is_array($scale_plan) ? $scale_plan['height'] : $template_height;
                if (($max_area > 0 && $area > $max_area && !is_array($scale_plan)) || preg_match('/(limit|exceed|memory|cache)/i', $message)) {
                    $error_template_width = is_array($scale_plan) ? $scale_plan['width'] : $template_width;
                    $error_template_height = is_array($scale_plan) ? $scale_plan['height'] : $template_height;
                    return new WP_Error(
                        'mockup_template_too_large',
                        sprintf(
                            'A mockup háttér túl nagy (%dx%d px%s). Csökkentsd a kép felbontását, majd próbáld újra. Eredeti hiba: %s',
                            $error_template_width,
                            $error_template_height,
                            is_array($scale_plan) ? sprintf(' – eredetileg %dx%d px', $template_width, $template_height) : '',
                            $message
                        )
                    );
                }
            }
            return new WP_Error('imagick_error', $message);
        } finally {
            if ($design instanceof Imagick) {
                $design->clear();
                $design->destroy();
            }
            if ($mockup instanceof Imagick) {
                $mockup->clear();
                $mockup->destroy();
            }
        }
    }
}
