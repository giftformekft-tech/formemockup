<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Variant_Display_Manager {
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
            $thumbnail = self::get_type_thumbnail($settings, $type_slug);
            if (!isset($thumbnail['alt'])) {
                $thumbnail['alt'] = $type_meta['label'];
            }
            $size_chart = isset($settings['size_charts'][$type_slug]) ? $settings['size_charts'][$type_slug] : '';
            if ($size_chart !== '') {
                $size_chart = do_shortcode($size_chart);
            }

            foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                $color_order[] = $color_slug;
                $color_settings = self::get_color_settings($settings, $type_slug, $color_slug);
                $swatch = isset($color_settings['swatch']) ? $color_settings['swatch'] : '';
                $colors_payload[$color_slug] = array(
                    'label' => $color_meta['label'],
                    'swatch' => $swatch,
                    'sizes' => self::sizes_for_color($type_meta, $color_slug),
                );
            }

            $types_payload[$type_slug] = array(
                'label' => $type_meta['label'],
                'thumbnail' => $thumbnail,
                'color_order' => $color_order,
                'colors' => $colors_payload,
                'size_order' => isset($type_meta['sizes']) ? $type_meta['sizes'] : array(),
                'size_chart' => $size_chart,
            );
        }

        if (empty($types_payload)) {
            return array();
        }

        $availability = self::build_availability_map($product);
        $defaults = $product->get_default_attributes();

        return array(
            'types' => $types_payload,
            'order' => array(
                'types' => $type_order,
            ),
            'availability' => $availability,
            'text' => array(
                'type' => __('Terméktípus', 'mgvd'),
                'color' => __('Szín', 'mgvd'),
                'size' => __('Méret', 'mgvd'),
                'chooseTypeFirst' => __('Először válassz terméktípust.', 'mgvd'),
                'chooseColorFirst' => __('Először válassz színt.', 'mgvd'),
                'noColors' => __('Ehhez a terméktípushoz nincs elérhető szín.', 'mgvd'),
                'noSizes' => __('Ehhez a kombinációhoz nincs elérhető méret.', 'mgvd'),
                'sizeChartLink' => __('📏 Mérettáblázat megnyitása', 'mgvd'),
                'sizeChartTitle' => __('Mérettáblázat', 'mgvd'),
                'sizeChartClose' => __('Bezárás', 'mgvd'),
            ),
            'default' => array(
                'type' => isset($defaults['pa_termektipus']) ? sanitize_title($defaults['pa_termektipus']) : '',
                'color' => isset($defaults['pa_szin']) ? sanitize_title($defaults['pa_szin']) : '',
                'size' => isset($defaults['meret']) ? sanitize_text_field($defaults['meret']) : '',
            ),
        );
    }

    public static function get_catalog_index() {
        $all = get_option('mg_products', array());
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
                    $colors[$color_slug] = array(
                        'label' => isset($color['name']) ? wp_strip_all_tags($color['name']) : $color_slug,
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

            $index[$slug] = array(
                'label' => $label,
                'colors' => $colors,
                'sizes' => $sizes,
                'matrix' => $matrix,
            );
        }

        return $index;
    }

    protected static function get_settings($catalog) {
        $raw = get_option('mg_variant_display', array());
        if (!is_array($catalog)) {
            $catalog = array();
        }
        if (isset($raw['icons']) && !isset($raw['thumbnails'])) {
            $raw['thumbnails'] = $raw['icons'];
            unset($raw['icons']);
        }

        $sanitized = self::sanitize_settings_block($raw, $catalog);
        return wp_parse_args($sanitized, array(
            'colors' => array(),
            'thumbnails' => array(),
            'size_charts' => array(),
        ));
    }

    public static function sanitize_settings_block($input, $catalog = null) {
        $clean = array(
            'colors' => array(),
            'thumbnails' => array(),
            'size_charts' => array(),
        );

        if (!is_array($input)) {
            return $clean;
        }

        if (isset($input['icons']) && !isset($input['thumbnails'])) {
            $input['thumbnails'] = $input['icons'];
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

        if (!empty($input['thumbnails']) && is_array($input['thumbnails'])) {
            foreach ($input['thumbnails'] as $type_slug => $icon_settings) {
                $type_slug = sanitize_title($type_slug);
                if ($type_slug === '') {
                    continue;
                }
                if (is_array($allowed_type_slugs) && !in_array($type_slug, $allowed_type_slugs, true)) {
                    continue;
                }
                $attachment_id = 0;
                if (isset($icon_settings['attachment_id'])) {
                    $attachment_id = absint($icon_settings['attachment_id']);
                }
                $icon_url = '';
                if (!empty($icon_settings['url'])) {
                    $icon_url = esc_url_raw($icon_settings['url']);
                }
                if (!$attachment_id && $icon_url === '') {
                    continue;
                }
                if (!isset($clean['thumbnails'][$type_slug])) {
                    $clean['thumbnails'][$type_slug] = array();
                }
                $clean['thumbnails'][$type_slug] = array(
                    'attachment_id' => $attachment_id,
                    'url' => $icon_url,
                );
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

        return $clean;
    }

    protected static function get_type_thumbnail($settings, $type_slug) {
        if (empty($settings['thumbnails'][$type_slug]) || !is_array($settings['thumbnails'][$type_slug])) {
            return array(
                'attachment_id' => 0,
                'url' => '',
            );
        }

        $icon_settings = $settings['thumbnails'][$type_slug];
        $icon_id = isset($icon_settings['attachment_id']) ? absint($icon_settings['attachment_id']) : 0;
        $icon_url = '';

        if ($icon_id) {
            $image_src = wp_get_attachment_image_src($icon_id, 'thumbnail');
            if (is_array($image_src) && !empty($image_src[0])) {
                $icon_url = $image_src[0];
            }
        }

        if ($icon_url !== '') {
            $icon_url = esc_url($icon_url);
        } elseif (!empty($icon_settings['url'])) {
            $icon_url = esc_url($icon_settings['url']);
        }

        if ($icon_url === '') {
            return array(
                'attachment_id' => $icon_id,
                'url' => '',
            );
        }

        return array(
            'attachment_id' => $icon_id,
            'url' => $icon_url,
        );
    }

    protected static function get_color_settings($settings, $type_slug, $color_slug) {
        if (empty($settings['colors'][$type_slug][$color_slug])) {
            return array();
        }
        return $settings['colors'][$type_slug][$color_slug];
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

        foreach ($available as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            $attributes = isset($variation['attributes']) ? $variation['attributes'] : array();
            $type_slug = isset($attributes['attribute_pa_termektipus']) ? sanitize_title($attributes['attribute_pa_termektipus']) : '';
            $color_slug = isset($attributes['attribute_pa_szin']) ? sanitize_title($attributes['attribute_pa_szin']) : '';
            $size_label = isset($attributes['attribute_meret']) ? sanitize_text_field($attributes['attribute_meret']) : '';
            if ($type_slug === '' || $color_slug === '' || $size_label === '') {
                continue;
            }
            if (!isset($map[$type_slug])) {
                $map[$type_slug] = array();
            }
            if (!isset($map[$type_slug][$color_slug])) {
                $map[$type_slug][$color_slug] = array();
            }
            $map[$type_slug][$color_slug][$size_label] = array(
                'in_stock' => !empty($variation['is_in_stock']),
                'is_purchasable' => !empty($variation['is_purchasable']),
            );
        }

        return $map;
    }
}
