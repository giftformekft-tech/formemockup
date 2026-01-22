<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Variant_Display_Manager {
    /**
     * Whether the preload assets have already been hooked for the current request.
     *
     * @var bool
     */
    protected static $preload_hooked = false;

    /**
     * Whether the language_attributes filter has already been hooked.
     *
     * @var bool
     */
    protected static $language_attributes_hooked = false;

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 20);
    }

    public static function enqueue_assets() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('variable')) {
            return;
        }

        $config = self::build_frontend_config($product);
        if (empty($config) || empty($config['types'])) {
            return;
        }

        self::hook_preload_assets();

        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_path = dirname(__DIR__) . '/assets/css/variant-display.css';
        $script_path = dirname(__DIR__) . '/assets/js/variant-display.js';

        wp_enqueue_style(
            'mg-variant-display',
            plugins_url('assets/css/variant-display.css', $base_file),
            array(),
            file_exists($style_path) ? filemtime($style_path) : '1.0.0'
        );

        wp_enqueue_script(
            'mg-variant-display',
            plugins_url('assets/js/variant-display.js', $base_file),
            array('jquery', 'wc-add-to-cart-variation'),
            file_exists($script_path) ? filemtime($script_path) : '1.0.0',
            true
        );

        wp_localize_script('mg-variant-display', 'MG_VARIANT_DISPLAY', $config);
    }

    protected static function hook_preload_assets() {
        if (self::$preload_hooked) {
            return;
        }

        self::$preload_hooked = true;

        add_action('wp_head', array(__CLASS__, 'output_preload_markup'), 0);

        if (!self::$language_attributes_hooked) {
            self::$language_attributes_hooked = true;
            add_filter('language_attributes', array(__CLASS__, 'ensure_preload_language_attributes'), 20, 2);
        }
    }

    public static function output_preload_markup() {
        ?>
        <style id="mg-variant-preload-css">
            html.mg-variant-preload form.variations_form .variations,
            html.mg-variant-preload form.variations_form .woocommerce-variation,
            html.mg-variant-preload form.variations_form .single_variation,
            html.mg-variant-preload form.variations_form .woocommerce-variation-add-to-cart {
                opacity: 0 !important;
            }

            html.mg-variant-preload form.variations_form .variations,
            html.mg-variant-preload form.variations_form .woocommerce-variation,
            html.mg-variant-preload form.variations_form .single_variation,
            html.mg-variant-preload form.variations_form .woocommerce-variation-add-to-cart {
                visibility: hidden !important;
                pointer-events: none !important;
            }

            html.mg-variant-preload form.variations_form .variations {
                display: none !important;
            }
        </style>
        <script id="mg-variant-preload-script">
            (function () {
                var doc = document.documentElement;
                if (!doc) {
                    return;
                }
                if (!doc.classList.contains('mg-variant-preparing')) {
                    doc.classList.add('mg-variant-preparing');
                }
                doc.classList.remove('mg-variant-fallback');
                doc.classList.add('mg-variant-preload');
                if (typeof window !== 'undefined') {
                    if (window.__mgVariantPreloadCleanup) {
                        window.clearTimeout(window.__mgVariantPreloadCleanup);
                    }
                    window.__mgVariantPreloadCleanup = window.setTimeout(function () {
                        doc.classList.remove('mg-variant-preload');
                        doc.classList.remove('mg-variant-preparing');
                        doc.classList.add('mg-variant-fallback');
                        window.__mgVariantPreloadCleanup = null;
                    }, 4000);
                }
            })();
        </script>
        <noscript>
            <style>
                html form.variations_form .variations,
                html form.variations_form .woocommerce-variation,
                html form.variations_form .single_variation,
                html form.variations_form .woocommerce-variation-add-to-cart {
                    opacity: 1 !important;
                    visibility: visible !important;
                    pointer-events: auto !important;
                }

                html form.variations_form .variations {
                    display: table !important;
                }

                html form.variations_form .woocommerce-variation,
                html form.variations_form .single_variation,
                html form.variations_form .woocommerce-variation-add-to-cart {
                    display: block !important;
                }
            </style>
        </noscript>
        <?php
    }

    public static function ensure_preload_language_attributes($output, $doctype) {
        $required_classes = array('mg-variant-preparing', 'mg-variant-preload');

        if (stripos($output, 'class=') !== false) {
            $pattern = "/\\bclass=([\"\'])([^\"\']*)([\"\'])/i";
            if (preg_match($pattern, $output, $matches)) {
                $quote = $matches[1];
                $existing = preg_split('/\\s+/', trim($matches[2]));
                if (!is_array($existing)) {
                    $existing = array();
                }
                foreach ($required_classes as $class) {
                    if (!in_array($class, $existing, true)) {
                        $existing[] = $class;
                    }
                }
                $clean = implode(' ', array_filter(array_unique($existing)));
                $clean_attr = function_exists('esc_attr') ? esc_attr($clean) : htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
                $replacement = 'class=' . $quote . $clean_attr . $quote;
                $output = preg_replace($pattern, $replacement, $output, 1);
            }
            return $output;
        }

        $class_attr = function_exists('esc_attr') ? esc_attr(implode(' ', $required_classes)) : htmlspecialchars(implode(' ', $required_classes), ENT_QUOTES, 'UTF-8');
        return trim($output) . ' class="' . $class_attr . '"';
    }

    protected static function build_frontend_config($product) {
        $attributes = $product->get_variation_attributes();
        if (empty($attributes['pa_termektipus']) || !is_array($attributes['pa_termektipus'])) {
            return array();
        }

        $type_slugs = array();
        foreach ($attributes['pa_termektipus'] as $value) {
            $slug = sanitize_title($value);
            if ($slug !== '' && !in_array($slug, $type_slugs, true)) {
                $type_slugs[] = $slug;
            }
        }

        if (empty($type_slugs)) {
            return array();
        }

        $catalog = self::get_catalog_index();
        if (empty($catalog)) {
            return array();
        }

        $settings = self::get_settings($catalog);
        $types_payload = array();
        $type_order = array();

        foreach ($type_slugs as $type_slug) {
            if (!isset($catalog[$type_slug])) {
                continue;
            }
            $type_meta = $catalog[$type_slug];
            $type_order[] = $type_slug;
            $colors_payload = array();
            $color_order = array();
            $size_chart = isset($settings['size_charts'][$type_slug]) ? $settings['size_charts'][$type_slug] : '';
            if ($size_chart !== '') {
                $size_chart = do_shortcode($size_chart);
            }
            $size_chart_models = isset($settings['size_chart_models'][$type_slug]) ? $settings['size_chart_models'][$type_slug] : '';
            if ($size_chart_models !== '') {
                $size_chart_models = do_shortcode($size_chart_models);
            }

            $type_description = isset($type_meta['description']) ? $type_meta['description'] : '';
            if ($type_description !== '') {
                /**
                 * Filter the rendered type description before it is exposed to the frontend script.
                 *
                 * @param string   $type_description HTML for the description.
                 * @param string   $type_slug        The current type slug.
                 * @param WC_Product $product        The WooCommerce product instance.
                 */
                $type_description = apply_filters('mg_variant_display_type_description', $type_description, $type_slug, $product);
            }

            foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                $color_order[] = $color_slug;
                $default_hex = isset($color_meta['hex']) ? $color_meta['hex'] : '';
                $color_settings = self::get_color_settings($settings, $type_slug, $color_slug, $default_hex);
                $swatch = isset($color_settings['swatch']) ? $color_settings['swatch'] : '';
                if ($swatch === '' && $default_hex !== '') {
                    $candidate = sanitize_hex_color($default_hex);
                    if ($candidate) {
                        $swatch = $candidate;
                    }
                }
                $colors_payload[$color_slug] = array(
                    'label' => $color_meta['label'],
                    'swatch' => $swatch,
                    'sizes' => self::sizes_for_color($type_meta, $color_slug),
                );
            }

            $types_payload[$type_slug] = array(
                'label' => $type_meta['label'],
                'color_order' => $color_order,
                'colors' => $colors_payload,
                'size_order' => isset($type_meta['sizes']) ? $type_meta['sizes'] : array(),
                'size_chart' => $size_chart,
                'size_chart_models' => $size_chart_models,
                'description' => $type_description,
            );
        }

        if (empty($types_payload)) {
            return array();
        }

        $availability = self::build_availability_map($product);
        $defaults = $product->get_default_attributes();

        $description_targets = apply_filters(
            'mg_variant_display_description_targets',
            array(
                '.woocommerce-product-details__short-description',
                '#tab-description',
                '.woocommerce-Tabs-panel--description',
            ),
            $product
        );

        if (!is_array($description_targets)) {
            $description_targets = array();
        }

        $description_targets = array_values(array_filter(array_unique(array_map('strval', $description_targets))));

        $visuals = self::build_visual_map($product, $types_payload, $defaults);

        return array(
            'types' => $types_payload,
            'order' => array(
                'types' => $type_order,
            ),
            'availability' => $availability,
            'text' => array(
                'type' => __('Terméktípus:', 'mgvd'),
                'typePrompt' => __('Válassz terméket:', 'mgvd'),
                'color' => __('Szín', 'mgvd'),
                'size' => __('Méret', 'mgvd'),
                'typePlaceholder' => __('Válassz terméktípust', 'mgvd'),
                'typeModalTitle' => __('Válaszd ki a terméktípust', 'mgvd'),
                'typeModalClose' => __('Bezárás', 'mgvd'),
                'chooseTypeFirst' => __('Először válassz terméktípust.', 'mgvd'),
                'chooseColorFirst' => __('Először válassz színt.', 'mgvd'),
                'noColors' => __('Ehhez a terméktípushoz nincs elérhető szín.', 'mgvd'),
                'noSizes' => __('Ehhez a kombinációhoz nincs elérhető méret.', 'mgvd'),
                'sizeChartLink' => __('📏 Mérettáblázat megnyitása', 'mgvd'),
                'sizeChartTitle' => __('Mérettáblázat', 'mgvd'),
                'sizeChartModelsTitle' => __('Modelleken', 'mgvd'),
                'sizeChartModelsLink' => __('Nézd meg modelleken', 'mgvd'),
                'sizeChartBack' => __('Vissza a mérettáblázatra', 'mgvd'),
                'sizeChartClose' => __('Bezárás', 'mgvd'),
                'previewButton' => __('Minta nagyban', 'mgvd'),
                'previewClose' => __('Bezárás', 'mgvd'),
                'previewUnavailable' => __('Ehhez a variációhoz nem érhető el minta.', 'mgvd'),
                'previewNoColor' => __('Ehhez a variációhoz nem található háttérszín.', 'mgvd'),
                'previewWatermark' => __('www.forme.hu', 'mgvd'),
            ),
            'default' => array(
                'type' => isset($defaults['pa_termektipus']) ? sanitize_title($defaults['pa_termektipus']) : '',
                'color' => isset($defaults['pa_szin']) ? sanitize_title($defaults['pa_szin']) : '',
                'size' => isset($defaults['meret']) ? sanitize_text_field($defaults['meret']) : '',
            ),
            'descriptionTargets' => $description_targets,
            'visuals' => $visuals,
        );
    }

    protected static function build_visual_map($product, $types_payload, $defaults) {
        $visuals = array(
            'defaults' => array(
                'color' => '',
                'mockup' => '',
                'pattern' => '',
            ),
            'typeMockups' => array(),
            'variationColors' => array(),
            'variationMockups' => array(),
            'variationPatterns' => array(),
        );

        $base_pattern = self::resolve_design_url($product->get_id());
        $default_type = isset($defaults['pa_termektipus']) ? sanitize_title($defaults['pa_termektipus']) : '';

        $available = $product->get_available_variations();
        if (is_array($available)) {
            foreach ($available as $variation) {
                if (!is_array($variation)) {
                    continue;
                }
                $variation_id = isset($variation['variation_id']) ? absint($variation['variation_id']) : 0;
                $attributes = isset($variation['attributes']) ? $variation['attributes'] : array();
                $type_slug = isset($attributes['attribute_pa_termektipus']) ? sanitize_title($attributes['attribute_pa_termektipus']) : '';
                $color_slug = isset($attributes['attribute_pa_szin']) ? sanitize_title($attributes['attribute_pa_szin']) : '';

                if ($variation_id && $type_slug && $color_slug && isset($types_payload[$type_slug]['colors'][$color_slug]['swatch'])) {
                    $swatch = $types_payload[$type_slug]['colors'][$color_slug]['swatch'];
                    if ($swatch !== '') {
                        $visuals['variationColors'][$variation_id] = $swatch;
                    }
                }

                if ($variation_id) {
                    $mockup = self::extract_variation_image($variation);
                    if ($mockup !== '' && self::is_valid_mockup_url($mockup)) {
                        $visuals['variationMockups'][$variation_id] = $mockup;
                    }
                }

                $pattern_url = self::resolve_design_url($variation_id);
                if ($pattern_url === '' && $base_pattern !== '') {
                    $pattern_url = $base_pattern;
                }
                if ($pattern_url !== '') {
                    $visuals['variationPatterns'][$variation_id] = $pattern_url;
                }
            }
        }

        // NEW: Use same logic as MG_Design_Gallery to get type mockups
        // For each type, find the first variation that has that type and get its image
        if (!empty($types_payload) && method_exists($product, 'get_children')) {
            $children = $product->get_children();
            foreach ($types_payload as $type_slug => $type_meta) {
                if (isset($visuals['typeMockups'][$type_slug])) {
                    continue; // Already have a mockup for this type
                }
                
                // Find the preferred color for this type (same logic as design gallery)
                $color_order = isset($type_meta['color_order']) ? $type_meta['color_order'] : array();
                $preferred_color = $color_order ? reset($color_order) : '';
                if ($preferred_color === '' && !empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                    $color_keys = array_keys($type_meta['colors']);
                    $preferred_color = $color_keys ? reset($color_keys) : '';
                }
                
                // Search through variations to find one matching this type
                foreach ((array) $children as $child_id) {
                    $variation = wc_get_product($child_id);
                    if (!$variation || !method_exists($variation, 'get_attributes')) {
                        continue;
                    }
                    $attrs = $variation->get_attributes();
                    $var_type = sanitize_title($attrs['pa_termektipus'] ?? '');
                    
                    if ($var_type !== $type_slug) {
                        continue; // Not the type we're looking for
                    }
                    
                    // Found a variation with this type, now get its image
                    if (method_exists($variation, 'get_image_id')) {
                        $image_id = (int) $variation->get_image_id();
                        if ($image_id > 0 && function_exists('wp_get_attachment_image_url')) {
                            $url = wp_get_attachment_image_url($image_id, 'large');
                            if ($url && self::is_valid_mockup_url($url)) {
                                $visuals['typeMockups'][$type_slug] = $url;
                                break; // Found image for this type, move to next type
                            }
                        }
                    }
                }
            }
        }

        $default_color = '';
        $default_color_slug = isset($defaults['pa_szin']) ? sanitize_title($defaults['pa_szin']) : '';
        if ($default_type && $default_color_slug && isset($types_payload[$default_type]['colors'][$default_color_slug]['swatch'])) {
            $default_color = $types_payload[$default_type]['colors'][$default_color_slug]['swatch'];
        }
        if ($default_color !== '') {
            $visuals['defaults']['color'] = $default_color;
        }

        $default_variation_id = self::find_matching_variation_id($available, $defaults);
        if ($default_variation_id && isset($visuals['variationMockups'][$default_variation_id])) {
            $visuals['defaults']['mockup'] = $visuals['variationMockups'][$default_variation_id];
        }
        if (
            ($visuals['defaults']['mockup'] === '' || !self::is_valid_mockup_url($visuals['defaults']['mockup']))
            && $default_type !== ''
            && !empty($visuals['typeMockups'][$default_type])
        ) {
            $visuals['defaults']['mockup'] = $visuals['typeMockups'][$default_type];
        }
        if ($visuals['defaults']['mockup'] === '' || !self::is_valid_mockup_url($visuals['defaults']['mockup'])) {
            $featured_id = method_exists($product, 'get_image_id') ? (int) $product->get_image_id() : 0;
            if ($featured_id > 0) {
                $featured_url = wp_get_attachment_image_url($featured_id, 'large');
                if ($featured_url && self::is_valid_mockup_url($featured_url)) {
                    $visuals['defaults']['mockup'] = $featured_url;
                }
            }
        }

        if ($default_variation_id && isset($visuals['variationPatterns'][$default_variation_id])) {
            $visuals['defaults']['pattern'] = $visuals['variationPatterns'][$default_variation_id];
        } elseif ($visuals['defaults']['pattern'] === '' && $base_pattern !== '') {
            $visuals['defaults']['pattern'] = $base_pattern;
        }

        return $visuals;
    }

    protected static function is_valid_mockup_url($url) {
        if (!is_string($url) || $url === '') {
            return false;
        }
        if (!wp_http_validate_url($url)) {
            return false;
        }
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return true;
        }
        $baseurl = rtrim($uploads['baseurl'], '/') . '/';
        if (strpos($url, $baseurl) !== 0) {
            return true;
        }
        $relative = ltrim(substr($url, strlen($baseurl)), '/');
        $path = wp_normalize_path(trailingslashit($uploads['basedir']) . $relative);
        return file_exists($path);
    }

    protected static function get_type_mockups_from_index($product_id, $types_payload) {
        $mockups = array();
        if (empty($types_payload) || !class_exists('MG_Mockup_Maintenance')) {
            return $mockups;
        }
        $index = MG_Mockup_Maintenance::get_index();
        if (!is_array($index) || empty($index)) {
            return $mockups;
        }
        foreach ($types_payload as $type_slug => $type_meta) {
            $color_order = isset($type_meta['color_order']) ? $type_meta['color_order'] : array();
            $fallback_color = $color_order ? reset($color_order) : '';
            if ($fallback_color === '' && !empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                $color_keys = array_keys($type_meta['colors']);
                $fallback_color = $color_keys ? reset($color_keys) : '';
            }
            $color_slug = $fallback_color;
            if ($color_slug === '' || !is_array($type_meta['colors'])) {
                continue;
            }
            $key = absint($product_id) . '|' . sanitize_title($type_slug) . '|' . sanitize_title($color_slug);
            if (!isset($index[$key]) || !is_array($index[$key])) {
                continue;
            }
            $entry = $index[$key];
            $url = self::resolve_preview_url_from_entry($entry);
            if ($url !== '') {
                $mockups[$type_slug] = $url;
            }
        }
        return $mockups;
    }

    protected static function get_type_mockups_from_renders($product, $types_payload) {
        $mockups = array();
        if (!is_object($product) || empty($types_payload)) {
            return $mockups;
        }

        // NEW: Try SKU-based lookup first
        $sku = '';
        if (method_exists($product, 'get_sku')) {
            $sku = $product->get_sku();
        }
        if ($sku !== '') {
            $sku = trim($sku);
            $sku = preg_replace('/[^a-zA-Z0-9\-_]/', '', $sku);
            $sku = strtoupper($sku);
        }

        if ($sku !== '') {
            foreach ($types_payload as $type_slug => $type_meta) {
                 $color_order = isset($type_meta['color_order']) ? $type_meta['color_order'] : array();
                $fallback_color = $color_order ? reset($color_order) : '';
                if ($fallback_color === '' && !empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                    $color_keys = array_keys($type_meta['colors']);
                    $fallback_color = $color_keys ? reset($color_keys) : '';
                }
                if ($fallback_color === '') {
                    continue;
                }

                $sku_url = self::find_sku_render_url($sku, $type_slug, $fallback_color);
                if ($sku_url !== '') {
                    $mockups[$type_slug] = $sku_url;
                    continue; // Found SKU based, skip legacy
                }
            }
        }

        $render_version = self::get_render_version($product);
        $design_id = self::get_design_id($product);
        if ($render_version === '' || $design_id <= 0) {
            return $mockups;
        }
        foreach ($types_payload as $type_slug => $type_meta) {
            // IF we already found a mockup via SKU, skip this type
            if (isset($mockups[$type_slug])) {
                continue;
            }

            $color_order = isset($type_meta['color_order']) ? $type_meta['color_order'] : array();
            $fallback_color = $color_order ? reset($color_order) : '';
            if ($fallback_color === '' && !empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                $color_keys = array_keys($type_meta['colors']);
                $fallback_color = $color_keys ? reset($color_keys) : '';
            }
            if ($fallback_color === '') {
                continue;
            }
            $render_path = self::build_render_path($render_version, $design_id, $type_slug, $fallback_color);
            if ($render_path !== '' && file_exists($render_path)) {
                $render_url = self::build_render_url($render_version, $design_id, $type_slug, $fallback_color);
                if ($render_url !== '') {
                    $mockups[$type_slug] = $render_url;
                }
                continue;
            }

            $render_dir = self::build_render_dir($render_version, $design_id, $type_slug);
            if ($render_dir === '' || !is_dir($render_dir)) {
                continue;
            }
            $pattern = $render_dir . '/mockup_' . sanitize_title($type_slug) . '_' . sanitize_title($fallback_color) . '_*.webp';
            $candidates = glob($pattern);
            if (empty($candidates)) {
                continue;
            }
            $candidate = $candidates[0];
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                $url = self::convert_path_to_url($candidate);
                if ($url !== '') {
                    $mockups[$type_slug] = $url;
                }
            }
        }
        return $mockups;
    }

    protected static function resolve_preview_url_from_entry($entry) {
        if (!is_array($entry)) {
            return '';
        }
        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : array();
        if (!empty($source['attachment_ids']) && is_array($source['attachment_ids'])) {
            foreach ($source['attachment_ids'] as $attachment_id) {
                $attachment_id = absint($attachment_id);
                if ($attachment_id <= 0) {
                    continue;
                }
                $file = get_attached_file($attachment_id);
                if ($file && file_exists($file)) {
                    $url = wp_get_attachment_image_url($attachment_id, 'large');
                } else {
                    $url = '';
                }
                if ($url) {
                    return $url;
                }
            }
        }

        $images = array();
        if (!empty($source['images']) && is_array($source['images'])) {
            $images = $source['images'];
        } elseif (!empty($source['last_generated_files']) && is_array($source['last_generated_files'])) {
            $images = $source['last_generated_files'];
        }
        foreach ($images as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            if (!file_exists($path)) {
                continue;
            }
            $url = self::convert_path_to_url($path);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    protected static function convert_path_to_url($path) {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return '';
        }
        $normalized_base = wp_normalize_path($uploads['basedir']);
        $normalized_path = wp_normalize_path($path);
        if (strpos($normalized_path, $normalized_base) !== 0) {
            return '';
        }
        $relative = ltrim(str_replace($normalized_base, '', $normalized_path), '/');
        return trailingslashit($uploads['baseurl']) . str_replace('\\', '/', $relative);
    }

    protected static function get_render_version($product) {
        $default_version = 'v4';
        $version = apply_filters('mg_virtual_variant_render_version', $default_version, $product);
        $version = sanitize_title($version);
        return $version !== '' ? $version : $default_version;
    }

    protected static function get_design_id($product) {
        $product_id = $product && method_exists($product, 'get_id') ? $product->get_id() : 0;
        return absint(apply_filters('mg_virtual_variant_design_id', $product_id, $product));
    }

    protected static function get_render_base_dir() {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($base_dir === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($base_dir) . 'mockup-renders');
    }

    protected static function get_render_base_url() {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_url = isset($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') : '';
        if ($base_url === '') {
            return '';
        }
        return trailingslashit($base_url) . 'mockup-renders';
    }

    // NEW HELPERS FOR SKU BASED PATHS

    protected static function get_sku_render_base_dir() {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($base_dir === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($base_dir) . 'mg_mockups');
    }

    protected static function get_sku_render_base_url() {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_url = isset($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') : '';
        if ($base_url === '') {
            return '';
        }
        return trailingslashit($base_url) . 'mg_mockups';
    }

    protected static function find_sku_render_url($sku, $type_slug, $color_slug) {
        $base_dir = self::get_sku_render_base_dir();
        $base_url = self::get_sku_render_base_url();
        
        if ($base_dir === '' || $base_url === '' || $sku === '') {
            return '';
        }
        
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        
        $sku_dir = $base_dir . '/' . $sku;
        if (!is_dir($sku_dir)) {
            return '';
        }

        // 1. Try exact match: {SKU}_{TYPE}_{COLOR}_*.webp
        $pattern = $sku . '_' . $type_slug . '_' . $color_slug . '_*.webp';
        $candidates = glob($sku_dir . '/' . $pattern);
        
        // 2. Fallback: Try type match only: {SKU}_{TYPE}_*.webp
        if (empty($candidates)) {
            $pattern_loose = $sku . '_' . $type_slug . '_*.webp';
            $candidates = glob($sku_dir . '/' . $pattern_loose);
        }

        if (empty($candidates)) {
            return '';
        }

        // Default to first match
        $match = $candidates[0];
        
        // Try to prefer 'front' if available
        foreach ($candidates as $c) {
            if (strpos($c, '_front.webp') !== false) {
                $match = $c;
                break;
            }
        }
        
        if (!file_exists($match)) {
            return '';
        }

        $filename = basename($match);
        return trailingslashit($base_url) . $sku . '/' . $filename;
    }


    protected static function format_design_folder($design_id) {
        $design_id = absint($design_id);
        if ($design_id <= 0) {
            return '';
        }
        return 'd' . sprintf('%03d', $design_id);
    }

    protected static function build_render_path($render_version, $design_id, $type_slug, $color_slug) {
        $base_dir = self::get_render_base_dir();
        if ($base_dir === '') {
            return '';
        }
        $render_version = sanitize_title($render_version);
        $design_folder = self::format_design_folder($design_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if ($render_version === '' || $design_folder === '' || $type_slug === '' || $color_slug === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($base_dir) . $render_version . '/' . $design_folder . '/' . $type_slug . '/' . $color_slug . '.webp');
    }

    protected static function build_render_url($render_version, $design_id, $type_slug, $color_slug) {
        $base_url = self::get_render_base_url();
        if ($base_url === '') {
            return '';
        }
        $render_version = sanitize_title($render_version);
        $design_folder = self::format_design_folder($design_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if ($render_version === '' || $design_folder === '' || $type_slug === '' || $color_slug === '') {
            return '';
        }
        $path = $render_version . '/' . $design_folder . '/' . $type_slug . '/' . $color_slug . '.webp';
        return trailingslashit($base_url) . str_replace('\\', '/', $path);
    }

    protected static function build_render_dir($render_version, $design_id, $type_slug) {
        $base_dir = self::get_render_base_dir();
        if ($base_dir === '') {
            return '';
        }
        $render_version = sanitize_title($render_version);
        $design_folder = self::format_design_folder($design_id);
        $type_slug = sanitize_title($type_slug);
        if ($render_version === '' || $design_folder === '' || $type_slug === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($base_dir) . $render_version . '/' . $design_folder . '/' . $type_slug);
    }

    protected static function resolve_design_url($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return '';
        }

        $attachment_id = (int) get_post_meta($post_id, '_mg_last_design_attachment', true);
        if ($attachment_id > 0 && function_exists('wp_get_attachment_url')) {
            $attachment_url = wp_get_attachment_url($attachment_id);
            if ($attachment_url) {
                return esc_url_raw($attachment_url);
            }
        }

        $design_path = get_post_meta($post_id, '_mg_last_design_path', true);
        $design_path = is_string($design_path) ? wp_normalize_path($design_path) : '';
        if ($design_path !== '' && file_exists($design_path)) {
            $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
            $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
            $base_url = isset($uploads['baseurl']) ? $uploads['baseurl'] : '';
            if ($base_dir !== '' && $base_url !== '' && strpos($design_path, $base_dir) === 0) {
                $relative = ltrim(str_replace($base_dir, '', $design_path), '/');
                $relative = str_replace('\\', '/', $relative);
                $url = trailingslashit($base_url) . $relative;
                return esc_url_raw($url);
            }
        }

        return '';
    }

    protected static function find_matching_variation_id($available, $defaults) {
        if (!is_array($available) || empty($defaults)) {
            return 0;
        }

        $default_type = isset($defaults['pa_termektipus']) ? sanitize_title($defaults['pa_termektipus']) : '';
        $default_color = isset($defaults['pa_szin']) ? sanitize_title($defaults['pa_szin']) : '';

        foreach ($available as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            $variation_id = isset($variation['variation_id']) ? absint($variation['variation_id']) : 0;
            if (!$variation_id) {
                continue;
            }
            $attributes = isset($variation['attributes']) ? $variation['attributes'] : array();
            $type_slug = isset($attributes['attribute_pa_termektipus']) ? sanitize_title($attributes['attribute_pa_termektipus']) : '';
            $color_slug = isset($attributes['attribute_pa_szin']) ? sanitize_title($attributes['attribute_pa_szin']) : '';

            if ($default_type && $type_slug !== $default_type) {
                continue;
            }
            if ($default_color && $color_slug !== $default_color) {
                continue;
            }

            return $variation_id;
        }

        return 0;
    }

    protected static function extract_variation_image($variation) {
        if (!is_array($variation)) {
            return '';
        }

        if (!empty($variation['image']) && is_array($variation['image'])) {
            if (!empty($variation['image']['full_src'])) {
                return esc_url_raw($variation['image']['full_src']);
            }
            if (!empty($variation['image']['src'])) {
                return esc_url_raw($variation['image']['src']);
            }
        }

        if (!empty($variation['image_id'])) {
            $image_url = wp_get_attachment_image_url($variation['image_id'], 'full');
            if ($image_url) {
                return esc_url_raw($image_url);
            }
        }

        return '';
    }

    public static function get_catalog_index() {
        if (function_exists('mg_get_catalog_products')) {
            $all = mg_get_catalog_products();
        } else {
            $all = get_option('mg_products', array());
        }
        $index = array();
        if (!is_array($all)) {
            return $index;
        }

        foreach ($all as $entry) {
            if (!is_array($entry) || empty($entry['key'])) {
                continue;
            }
            $slug = sanitize_title($entry['key']);
            if ($slug === '') {
                continue;
            }

            $label = isset($entry['label']) ? wp_strip_all_tags($entry['label']) : $slug;
            $colors = array();
            if (!empty($entry['colors']) && is_array($entry['colors'])) {
                foreach ($entry['colors'] as $color) {
                    if (!is_array($color)) {
                        continue;
                    }
                    $color_slug = isset($color['slug']) ? sanitize_title($color['slug']) : '';
                    if ($color_slug === '') {
                        continue;
                    }
                    $hex = '';
                    if (!empty($color['hex'])) {
                        $candidate = sanitize_hex_color($color['hex']);
                        if ($candidate) {
                            $hex = $candidate;
                        }
                    }
                    $colors[$color_slug] = array(
                        'label' => isset($color['name']) ? wp_strip_all_tags($color['name']) : $color_slug,
                        'hex' => $hex,
                    );
                }
            }

            $sizes = array();
            if (!empty($entry['sizes']) && is_array($entry['sizes'])) {
                foreach ($entry['sizes'] as $size) {
                    if (!is_string($size)) {
                        continue;
                    }
                    $clean = sanitize_text_field($size);
                    if ($clean === '') {
                        continue;
                    }
                    if (!in_array($clean, $sizes, true)) {
                        $sizes[] = $clean;
                    }
                }
            }

            $matrix = array();
            if (!empty($entry['size_color_matrix']) && is_array($entry['size_color_matrix'])) {
                foreach ($entry['size_color_matrix'] as $size_label => $color_list) {
                    if (!is_string($size_label)) {
                        continue;
                    }
                    $size_key = sanitize_text_field($size_label);
                    if ($size_key === '') {
                        continue;
                    }
                    $matrix[$size_key] = array();
                    if (is_array($color_list)) {
                        foreach ($color_list as $color_slug) {
                            $color_slug = sanitize_title($color_slug);
                            if ($color_slug === '') {
                                continue;
                            }
                            if (!in_array($color_slug, $matrix[$size_key], true)) {
                                $matrix[$size_key][] = $color_slug;
                            }
                        }
                    }
                }
            }

            $description = isset($entry['type_description']) ? wp_kses_post($entry['type_description']) : '';

            $index[$slug] = array(
                'label' => $label,
                'colors' => $colors,
                'sizes' => $sizes,
                'matrix' => $matrix,
                'description' => $description,
            );
        }

        return $index;
    }

    protected static function get_settings($catalog) {
        $raw = get_option('mg_variant_display', array());
        if (!is_array($catalog)) {
            $catalog = array();
        }
        $sanitized = self::sanitize_settings_block($raw, $catalog);
        return wp_parse_args($sanitized, array(
            'colors' => array(),
            'size_charts' => array(),
            'size_chart_models' => array(),
        ));
    }

    public static function sanitize_settings_block($input, $catalog = null) {
        $clean = array(
            'colors' => array(),
            'size_charts' => array(),
            'size_chart_models' => array(),
        );

        if (!is_array($input)) {
            return $clean;
        }

        $allowed_types = null;
        $allowed_type_slugs = null;
        if (is_array($catalog)) {
            $allowed_types = array();
            foreach ($catalog as $type_slug => $meta) {
                $type_slug = sanitize_title($type_slug);
                if ($type_slug === '') {
                    continue;
                }
                if (!is_array($allowed_type_slugs)) {
                    $allowed_type_slugs = array();
                }
                $allowed_type_slugs[] = $type_slug;
                $allowed_types[$type_slug] = array();
                if (!empty($meta['colors']) && is_array($meta['colors'])) {
                    foreach ($meta['colors'] as $color_slug => $color_meta) {
                        $color_slug = sanitize_title($color_slug);
                        if ($color_slug === '') {
                            continue;
                        }
                        $allowed_types[$type_slug][$color_slug] = true;
                    }
                }
            }
        }

        if (!empty($input['colors']) && is_array($input['colors'])) {
            foreach ($input['colors'] as $type_slug => $colors) {
                $type_slug = sanitize_title($type_slug);
                if ($type_slug === '') {
                    continue;
                }
                if (is_array($allowed_types) && !isset($allowed_types[$type_slug])) {
                    continue;
                }
                foreach ((array) $colors as $color_slug => $color_settings) {
                    $color_slug = sanitize_title($color_slug);
                    if ($color_slug === '') {
                        continue;
                    }
                    if (is_array($allowed_types) && empty($allowed_types[$type_slug][$color_slug])) {
                        continue;
                    }
                    if (!isset($clean['colors'][$type_slug])) {
                        $clean['colors'][$type_slug] = array();
                    }
                    $swatch = '';
                    if (isset($color_settings['swatch'])) {
                        $clean_color = sanitize_hex_color($color_settings['swatch']);
                        $swatch = $clean_color ? $clean_color : '';
                    }
                    $clean['colors'][$type_slug][$color_slug] = array(
                        'swatch' => $swatch,
                    );
                }
            }
        }

        if (!empty($input['size_charts']) && is_array($input['size_charts'])) {
            foreach ($input['size_charts'] as $type_slug => $chart) {
                $type_slug = sanitize_title($type_slug);
                if ($type_slug === '') {
                    continue;
                }
                if (is_array($allowed_type_slugs) && !in_array($type_slug, $allowed_type_slugs, true)) {
                    continue;
                }
                if (!isset($clean['size_charts'])) {
                    $clean['size_charts'] = array();
                }
                $chart = is_string($chart) ? $chart : '';
                $clean['size_charts'][$type_slug] = wp_kses_post($chart);
            }
        }

        if (!empty($input['size_chart_models']) && is_array($input['size_chart_models'])) {
            foreach ($input['size_chart_models'] as $type_slug => $chart) {
                $type_slug = sanitize_title($type_slug);
                if ($type_slug === '') {
                    continue;
                }
                if (is_array($allowed_type_slugs) && !in_array($type_slug, $allowed_type_slugs, true)) {
                    continue;
                }
                if (!isset($clean['size_chart_models'])) {
                    $clean['size_chart_models'] = array();
                }
                $chart = is_string($chart) ? $chart : '';
                $clean['size_chart_models'][$type_slug] = wp_kses_post($chart);
            }
        }

        return $clean;
    }

    protected static function get_color_settings($settings, $type_slug, $color_slug, $fallback_hex = '') {
        if (!empty($settings['colors'][$type_slug][$color_slug]) && is_array($settings['colors'][$type_slug][$color_slug])) {
            $entry = $settings['colors'][$type_slug][$color_slug];
            if (!empty($entry['swatch'])) {
                return $entry;
            }
        }

        $fallback_hex = sanitize_hex_color($fallback_hex);
        if ($fallback_hex) {
            return array('swatch' => $fallback_hex);
        }

        return array();
    }

    protected static function sizes_for_color($type_meta, $color_slug) {
        $sizes = isset($type_meta['sizes']) ? $type_meta['sizes'] : array();
        if (empty($sizes) || !is_array($sizes)) {
            return array();
        }

        $matrix = isset($type_meta['matrix']) && is_array($type_meta['matrix']) ? $type_meta['matrix'] : array();
        if (empty($matrix)) {
            return $sizes;
        }

        $allowed = array();
        foreach ($sizes as $size_label) {
            if (!isset($matrix[$size_label]) || !is_array($matrix[$size_label])) {
                continue;
            }
            if (in_array($color_slug, $matrix[$size_label], true)) {
                $allowed[] = $size_label;
            }
        }

        return $allowed;
    }

    protected static function build_availability_map($product) {
        $map = array();
        $available = $product->get_available_variations();
        if (!is_array($available)) {
            return $map;
        }
        $catalog = self::get_catalog_index();

        foreach ($available as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            $attributes = isset($variation['attributes']) ? $variation['attributes'] : array();
            $type_slug = isset($attributes['attribute_pa_termektipus']) ? sanitize_title($attributes['attribute_pa_termektipus']) : '';
            $color_slug = isset($attributes['attribute_pa_szin']) ? sanitize_title($attributes['attribute_pa_szin']) : '';
            if ($type_slug === '' || $color_slug === '') {
                continue;
            }
            if (!isset($map[$type_slug])) {
                $map[$type_slug] = array();
            }
            if (!isset($map[$type_slug][$color_slug])) {
                $map[$type_slug][$color_slug] = array();
            }
            $sizes = array();
            if (isset($catalog[$type_slug])) {
                $sizes = self::sizes_for_color($catalog[$type_slug], $color_slug);
            }
            if (empty($sizes)) {
                continue;
            }
            foreach ($sizes as $size_label) {
                $map[$type_slug][$color_slug][$size_label] = array(
                    'in_stock' => !empty($variation['is_in_stock']),
                    'is_purchasable' => !empty($variation['is_purchasable']),
                );
            }
        }

        return $map;
    }
}
