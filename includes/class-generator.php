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

    private function imagick_image_has_alpha($image) {
        if (!($image instanceof Imagick)) {
            return null;
        }
        if (method_exists($image, 'getImageAlphaChannel')) {
            try {
                $alpha = $image->getImageAlphaChannel();
                if (is_bool($alpha)) {
                    return $alpha;
                }
                if (is_int($alpha)) {
                    return $alpha !== 0;
                }
            } catch (Throwable $ignored) {
            }
        }
        if (method_exists($image, 'getImageMatte')) {
            try {
                $matte = $image->getImageMatte();
                if (is_bool($matte)) {
                    return $matte;
                }
                if (is_int($matte)) {
                    return $matte !== 0;
                }
            } catch (Throwable $ignored) {
            }
        }
        return null;
    }

    private function force_imagick_alpha_channel_opaque($image) {
        if (!($image instanceof Imagick)) {
            return;
        }
        if (method_exists($image, 'setImageAlphaChannel') && defined('Imagick::ALPHACHANNEL_OPAQUE')) {
            try {
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
                return;
            } catch (Throwable $ignored) {
            }
        }
        if (method_exists($image, 'evaluateImage') && defined('Imagick::EVALUATE_SET')) {
            $channel = null;
            if (defined('Imagick::CHANNEL_ALPHA')) {
                $channel = Imagick::CHANNEL_ALPHA;
            } elseif (defined('Imagick::CHANNEL_OPACITY')) {
                $channel = Imagick::CHANNEL_OPACITY;
            }
            if ($channel !== null) {
                try {
                    $image->evaluateImage(Imagick::EVALUATE_SET, 1.0, $channel);
                    return;
                } catch (Throwable $ignored) {
                }
            }
        }
        if (method_exists($image, 'setImageAlpha')) {
            try {
                $image->setImageAlpha(1.0);
                return;
            } catch (Throwable $ignored) {
            }
        }
        if (method_exists($image, 'setImageOpacity')) {
            try {
                $image->setImageOpacity(1.0);
            } catch (Throwable $ignored) {
            }
        }
    }

    private function ensure_imagick_alpha_channel($image, $preferred_mode = null) {
        if (!($image instanceof Imagick)) {
            return;
        }
        if ($preferred_mode === null) {
            if (defined('Imagick::ALPHACHANNEL_ACTIVATE')) {
                $preferred_mode = Imagick::ALPHACHANNEL_ACTIVATE;
            } elseif (defined('Imagick::ALPHACHANNEL_SET')) {
                $preferred_mode = Imagick::ALPHACHANNEL_SET;
            }
        }
        $modes = array();
        if ($preferred_mode !== null) {
            $modes[] = $preferred_mode;
        }
        if (defined('Imagick::ALPHACHANNEL_ACTIVATE')) {
            $modes[] = Imagick::ALPHACHANNEL_ACTIVATE;
        }
        if (defined('Imagick::ALPHACHANNEL_SET')) {
            $modes[] = Imagick::ALPHACHANNEL_SET;
        }
        if (defined('Imagick::ALPHACHANNEL_RESET')) {
            $modes[] = Imagick::ALPHACHANNEL_RESET;
        }
        $modes = array_unique($modes);
        foreach ($modes as $mode) {
            if (!method_exists($image, 'setImageAlphaChannel')) {
                break;
            }
            try {
                $image->setImageAlphaChannel($mode);
                return;
            } catch (Throwable $e) {
                continue;
            }
        }
        if (method_exists($image, 'setImageMatte')) {
            try { $image->setImageMatte(true); } catch (Throwable $ignored) {}
        }
    }

    private function prepare_imagick_image_for_compositing($image) {
        if (!($image instanceof Imagick)) {
            return null;
        }
        $had_alpha = $this->imagick_image_has_alpha($image);
        if (method_exists($image, 'setIteratorIndex')) {
            try { $image->setIteratorIndex(0); } catch (Throwable $ignored) {}
        }
        $palette_types = array();
        if (defined('Imagick::IMGTYPE_PALETTE')) {
            $palette_types[] = Imagick::IMGTYPE_PALETTE;
        }
        if (defined('Imagick::IMGTYPE_PALETTEALPHA')) {
            $palette_types[] = Imagick::IMGTYPE_PALETTEALPHA;
        }
        $target_type = defined('Imagick::IMGTYPE_TRUECOLORALPHA') ? Imagick::IMGTYPE_TRUECOLORALPHA : null;
        $current_type = null;
        try {
            $current_type = $image->getImageType();
            if ($target_type !== null && in_array($current_type, $palette_types, true) && method_exists($image, 'setImageType')) {
                $image->setImageType($target_type);
                $current_type = $target_type;
            }
        } catch (Throwable $ignored) {
        }
        if (method_exists($image, 'transformImageColorspace')) {
            try {
                $colorspace = $image->getImageColorspace();
                $rgb_spaces = array();
                if (defined('Imagick::COLORSPACE_RGB')) {
                    $rgb_spaces[] = Imagick::COLORSPACE_RGB;
                }
                if (defined('Imagick::COLORSPACE_SRGB')) {
                    $rgb_spaces[] = Imagick::COLORSPACE_SRGB;
                }
                $target = defined('Imagick::COLORSPACE_SRGB') ? Imagick::COLORSPACE_SRGB : (defined('Imagick::COLORSPACE_RGB') ? Imagick::COLORSPACE_RGB : null);
                if ($target !== null && !in_array($colorspace, $rgb_spaces, true)) {
                    $image->transformImageColorspace($target);
                }
            } catch (Throwable $ignored) {
            }
        }
        $this->ensure_imagick_alpha_channel($image);
        if ($had_alpha === false) {
            $this->force_imagick_alpha_channel_opaque($image);
        }
        if ($target_type !== null && method_exists($image, 'setImageType') && $current_type !== $target_type) {
            try {
                $image->setImageType($target_type);
                if ($had_alpha === false) {
                    $this->force_imagick_alpha_channel_opaque($image);
                }
            } catch (Throwable $ignored) {
            }
        }
        return $had_alpha;
    }

    private function resolve_imagick_resize_filter($filter_key) {
        $key = is_string($filter_key) ? strtolower(trim($filter_key)) : '';
        $map = array(
            'lanczos' => 'Imagick::FILTER_LANCZOS',
            'triangle' => 'Imagick::FILTER_TRIANGLE',
            'catrom' => 'Imagick::FILTER_CATROM',
            'mitchell' => 'Imagick::FILTER_MITCHELL',
        );
        if (!array_key_exists($key, $map)) {
            $key = 'lanczos';
        }
        $constant = $map[$key];
        if (defined($constant)) {
            return constant($constant);
        }
        if (defined('Imagick::FILTER_LANCZOS')) {
            return Imagick::FILTER_LANCZOS;
        }
        if (defined('Imagick::FILTER_TRIANGLE')) {
            return Imagick::FILTER_TRIANGLE;
        }
        return 0;
    }

    private function resize_imagick_image($image, $width, $height, $filter, $use_thumbnail) {
        if (!($image instanceof Imagick)) {
            return;
        }
        $w = max(1, (int)$width);
        $h = max(1, (int)$height);
        if ($use_thumbnail && method_exists($image, 'thumbnailImage')) {
            if (method_exists($image, 'setImageFilter') && is_int($filter)) {
                try {
                    $image->setImageFilter($filter);
                } catch (Throwable $ignored) {
                }
            }
            try {
                $image->thumbnailImage($w, $h, true, true);
            } catch (Throwable $ignored) {
            }
            return;
        }
        if (method_exists($image, 'resizeImage')) {
            try {
                $image->resizeImage($w, $h, $filter, 1, true);
            } catch (Throwable $ignored) {
            }
        }
    }

    private function trim_imagick_image_bounds($image) {
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

    public function generate_for_product($product_key, $design_path, $context = array()) {
        if (!$this->webp_supported()) {
            return new WP_Error('webp_unsupported', 'A szerveren nincs WEBP támogatás az Imagick-ben. Kérd meg a tárhelyszolgáltatót, vagy engedélyezd a WebP codert.');
        }
        $product = $this->get_product_definition($product_key);
        if (!$product) return new WP_Error('product_missing','Ismeretlen terméktípus: '.$product_key);
        $views = $product['views']; $colors = $product['colors'];
        $context = is_array($context) ? $context : array();
        if (!empty($context['view_filter'])) {
            $view_filter = is_array($context['view_filter']) ? $context['view_filter'] : array($context['view_filter']);
            $view_filter = array_values(array_filter(array_map('sanitize_title', $view_filter)));
            if (!empty($view_filter)) {
                $views = array_values(array_filter($views, function($view) use ($view_filter) {
                    $key = isset($view['key']) ? sanitize_title($view['key']) : '';
                    return $key !== '' && in_array($key, $view_filter, true);
                }));
            }
        }
        if (!empty($context['color_filter'])) {
            $color_filter = is_array($context['color_filter']) ? $context['color_filter'] : array($context['color_filter']);
            $color_filter = array_values(array_filter(array_map('sanitize_title', $color_filter)));
            if (!empty($color_filter)) {
                $colors = array_values(array_filter($colors, function($color) use ($color_filter) {
                    $slug = isset($color['slug']) ? sanitize_title($color['slug']) : '';
                    return $slug !== '' && in_array($slug, $color_filter, true);
                }));
            }
        }
        if (empty($views)) return new WP_Error('views_missing','Nincs nézet.');
        if (empty($colors)) return new WP_Error('colors_missing','Nincs szín.');

        $output_dir = $this->resolve_output_directory($product['key'], $design_path, $context);
        if ($output_dir === '') {
            return new WP_Error('upload_dir_missing', 'A feltöltési könyvtár nem elérhető.');
        }

        try {
            $design_base = new Imagick($design_path);
            if (method_exists($design_base, 'stripImage')) {
                $design_base->stripImage();
            }
            $this->prepare_imagick_image_for_compositing($design_base);
            $this->trim_imagick_image_bounds($design_base);
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
                    $type_slug = sanitize_title($product['key']);
                    $color_slug = sanitize_title($slug);
                    $view_key = isset($view['key']) ? sanitize_title($view['key']) : 'view';
                    $filename = 'mockup_' . $type_slug . '_' . $color_slug . '_' . $view_key . '.webp';
                    $outfile = wp_normalize_path(trailingslashit($output_dir) . $filename);
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
                    if (class_exists('MG_Storage_Manager') && method_exists('MG_Storage_Manager', 'dedupe_generated_asset')) {
                        $deduped = MG_Storage_Manager::dedupe_generated_asset($outfile);
                        if (is_string($deduped) && $deduped !== '') {
                            $outfile = $deduped;
                        }
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

    private function resolve_output_directory($type_key, $design_path, array $context) {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($base_dir === '') {
            return '';
        }

        $render_version = $this->resolve_render_version($context);
        $design_id = $this->resolve_design_id($design_path, $context);
        $type_slug = sanitize_title($type_key);
        $design_folder = $design_id > 0 ? 'd' . sprintf('%03d', $design_id) : '';
        $render_bucket = $this->resolve_render_bucket($context);

        if ($render_version === '' || $design_folder === '' || $type_slug === '') {
            return '';
        }

        $output_dir = wp_normalize_path(trailingslashit($base_dir) . $render_bucket . '/' . $render_version . '/' . $design_folder . '/' . $type_slug);
        if (!is_dir($output_dir)) {
            wp_mkdir_p($output_dir);
        }
        return is_dir($output_dir) ? $output_dir : '';
    }

    private function resolve_render_bucket(array $context) {
        $scope = sanitize_key($context['render_scope'] ?? '');
        if ($scope === 'woo_featured') {
            return 'mockup-featured-renders';
        }
        return 'mockup-renders';
    }

    private function resolve_render_version(array $context) {
        if (!empty($context['render_version'])) {
            $version = sanitize_title($context['render_version']);
            return $version !== '' ? $version : 'v4';
        }
        $default_version = 'v4';
        $version = apply_filters('mg_virtual_variant_render_version', $default_version, null);
        $version = sanitize_title($version);
        return $version !== '' ? $version : $default_version;
    }

    private function resolve_design_id($design_path, array $context) {
        if (!empty($context['design_id'])) {
            return absint($context['design_id']);
        }
        if (!empty($context['product_id'])) {
            return absint($context['product_id']);
        }
        $attachment_id = $this->find_attachment_id_for_path($design_path);
        if ($attachment_id > 0) {
            return $attachment_id;
        }
        $fallback = absint(crc32((string) $design_path));
        if ($fallback > 0) {
            return $fallback;
        }
        return absint(time());
    }

    private function find_attachment_id_for_path($path) {
        if (!function_exists('wp_upload_dir')) {
            return 0;
        }
        $path = wp_normalize_path((string) $path);
        if ($path === '') {
            return 0;
        }
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir'] ?? '');
        if ($basedir === '' || strpos($path, $basedir) !== 0) {
            return 0;
        }
        $relative = ltrim(substr($path, strlen($basedir)), '/');
        if ($relative === '') {
            return 0;
        }
        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_wp_attached_file',
                    'value' => $relative,
                ],
            ],
        ]);
        return !empty($query->posts) ? (int) $query->posts[0] : 0;
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
        $resize_settings = get_option('mg_output_resize', array('enabled'=>false,'max_w'=>0,'max_h'=>0,'mode'=>'fit','filter'=>'lanczos','method'=>'resize'));
        $resize_filter = $this->resolve_imagick_resize_filter($resize_settings['filter'] ?? 'lanczos');
        $use_thumbnail = ($resize_settings['method'] ?? 'resize') === 'thumbnail';
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
        $mockup_started_with_alpha = null;
        try {
            $mockup = new Imagick($template_path);
            $mockup_started_with_alpha = $this->imagick_image_has_alpha($mockup);
            $design = clone $design_base;

            if (method_exists('Imagick','setResourceLimit')) {
                $imagick_options = get_option('mg_imagick_options', array('thread_limit' => 0));
                $thread_limit_setting = max(0, intval($imagick_options['thread_limit'] ?? 0));
                $threads = $thread_limit_setting > 0 ? $thread_limit_setting : max(1, (int)@ini_get('imagick.thread_limit') ?: 2);
                $mockup->setResourceLimit(Imagick::RESOURCETYPE_THREAD, $threads);
                $design->setResourceLimit(Imagick::RESOURCETYPE_THREAD, $threads);
            }
            if (method_exists($mockup,'stripImage')) $mockup->stripImage();
            if (method_exists($design,'stripImage')) $design->stripImage();
            $this->prepare_imagick_image_for_compositing($mockup);
            $this->prepare_imagick_image_for_compositing($design);

            $placement_scale = 1.0;
            if (is_array($scale_plan)) {
                if ($scale_plan['width'] !== $template_width || $scale_plan['height'] !== $template_height) {
                    $this->resize_imagick_image($mockup, $scale_plan['width'], $scale_plan['height'], $resize_filter, $use_thumbnail);
                    $this->ensure_imagick_alpha_channel($mockup, defined('Imagick::ALPHACHANNEL_SET') ? Imagick::ALPHACHANNEL_SET : null);
                    if ($mockup_started_with_alpha === false) {
                        $this->force_imagick_alpha_channel_opaque($mockup);
                    }
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

            $has_explicit_x = array_key_exists('x', $cfg);
            $has_explicit_y = array_key_exists('y', $cfg);
            $target_x = $has_explicit_x ? (float)$cfg['x'] : null;
            $target_y = $has_explicit_y ? (float)$cfg['y'] : null;

            $auto_center_x = (!$has_explicit_x || $target_x === null || $target_x === 0.0);

            if ($auto_center_x && $target_w > 0 && $template_width > 0) {
                $target_x = max(0.0, ($template_width - $target_w) / 2);
            }
            if ($target_y === null) {
                $target_y = 0.0;
            }

            if ($target_x === null) {
                $target_x = 0.0;
            }

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

            $this->ensure_imagick_alpha_channel($mockup, defined('Imagick::ALPHACHANNEL_ACTIVATE') ? Imagick::ALPHACHANNEL_ACTIVATE : null);
            if ($mockup_started_with_alpha === false) {
                $this->force_imagick_alpha_channel_opaque($mockup);
            }
            $this->ensure_imagick_alpha_channel($design, defined('Imagick::ALPHACHANNEL_ACTIVATE') ? Imagick::ALPHACHANNEL_ACTIVATE : null);
            if (method_exists($design,'setBackgroundColor')) $design->setBackgroundColor(new ImagickPixel('transparent'));

            $this->trim_imagick_image_bounds($design);

            if (method_exists($design,'thumbnailImage')) $design->thumbnailImage($design_width_px, $design_height_px, true, false);
            $this->ensure_imagick_alpha_channel($design, defined('Imagick::ALPHACHANNEL_SET') ? Imagick::ALPHACHANNEL_SET : null);

            if (method_exists($design, 'getImageWidth')) {
                $final_design_w = (int)$design->getImageWidth();
                if ($target_w > 0 && $final_design_w > 0) {
                    $design_x_px = max(0, (int)round($target_x + (($target_w - $final_design_w) / 2)));
                } elseif ($auto_center_x && method_exists($mockup, 'getImageWidth')) {
                    $final_mockup_w = (int)$mockup->getImageWidth();
                    if ($final_mockup_w > 0 && $final_design_w > 0) {
                        $design_x_px = max(0, (int)round(($final_mockup_w - $final_design_w) / 2));
                    }
                }
            }

            if (method_exists($mockup,'compositeImage')) $mockup->compositeImage($design, Imagick::COMPOSITE_OVER, $design_x_px, $design_y_px);

            // Optional output resize
            $enabled = !empty($resize_settings['enabled']);
            $max_w = max(0, intval($resize_settings['max_w'] ?? 0));
            $max_h = max(0, intval($resize_settings['max_h'] ?? 0));
            $mode  = $resize_settings['mode'] ?? 'fit';

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
                    $this->resize_imagick_image($mockup, $target_w, $target_h, $resize_filter, $use_thumbnail);
                    $this->ensure_imagick_alpha_channel($mockup, defined('Imagick::ALPHACHANNEL_SET') ? Imagick::ALPHACHANNEL_SET : null);
                    if ($mockup_started_with_alpha === false) {
                        $this->force_imagick_alpha_channel_opaque($mockup);
                    }
                }
            }

            $webp_defaults = array('quality'=>78,'alpha'=>92,'method'=>3);
            $webp_settings = get_option('mg_webp_options', $webp_defaults);
            $webp_quality = max(0, min(100, intval($webp_settings['quality'] ?? $webp_defaults['quality'])));
            $webp_alpha = max(0, min(100, intval($webp_settings['alpha'] ?? $webp_defaults['alpha'])));
            $webp_method = max(0, min(6, intval($webp_settings['method'] ?? $webp_defaults['method'])));

            if (method_exists($mockup,'setImageFormat')) $mockup->setImageFormat('webp');
            if (method_exists($mockup,'setOption')) {
                $mockup->setOption('webp:method', (string)$webp_method);
                $mockup->setOption('webp:thread-level', '1');
                $mockup->setOption('webp:auto-filter', '0');
                $mockup->setOption('webp:alpha-quality', (string)$webp_alpha);
            }
            if ($mockup_started_with_alpha === false) {
                $this->force_imagick_alpha_channel_opaque($mockup);
            }
            if (method_exists($mockup,'setImageCompressionQuality')) $mockup->setImageCompressionQuality($webp_quality);
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
