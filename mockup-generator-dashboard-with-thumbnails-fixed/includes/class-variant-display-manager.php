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

        $display_settings = self::get_settings();
        $types_payload = array();
        $type_order = array();

        foreach ($type_slugs as $type_slug) {
            if (!isset($catalog[$type_slug])) {
                continue;
            }
            $type_meta = $catalog[$type_slug];
            $type_order[] = $type_slug;

            $icon_url = self::resolve_type_icon_url($display_settings, $type_slug);
            $colors_payload = array();
            $color_order = array();

            foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                $color_order[] = $color_slug;
                $color_settings = isset($display_settings['colors'][$type_slug][$color_slug]) ? $display_settings['colors'][$type_slug][$color_slug] : array();
                $swatch = isset($color_settings['swatch']) ? $color_settings['swatch'] : '';
                $image_url = self::resolve_color_image_url($color_settings);
                $colors_payload[$color_slug] = array(
                    'label' => $color_meta['label'],
                    'swatch' => $swatch,
                    'image' => $image_url,
                    'sizes' => self::sizes_for_color($type_meta, $color_slug),
                );
            }

            $types_payload[$type_slug] = array(
                'label' => $type_meta['label'],
                'icon' => $icon_url,
                'color_order' => $color_order,
                'colors' => $colors_payload,
                'size_order' => $type_meta['sizes'],
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
            ),
            'default' => array(
                'type' => isset($defaults['pa_termektipus']) ? sanitize_title($defaults['pa_termektipus']) : '',
                'color' => isset($defaults['pa_szin']) ? sanitize_title($defaults['pa_szin']) : '',
                'size' => isset($defaults['meret']) ? sanitize_text_field($defaults['meret']) : '',
            ),
        );
    }

    protected static function get_catalog_index() {
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

    protected static function get_settings() {
        $raw = get_option('mg_variant_display', array());
        $result = array(
            'types' => array(),
            'colors' => array(),
        );

        if (!is_array($raw)) {
            return $result;
        }

        if (!empty($raw['types']) && is_array($raw['types'])) {
            foreach ($raw['types'] as $type_slug => $type_settings) {
                $slug = sanitize_title($type_slug);
                if ($slug === '') {
                    continue;
                }
                $icon_id = isset($type_settings['icon_id']) ? intval($type_settings['icon_id']) : 0;
                $result['types'][$slug] = array(
                    'icon_id' => $icon_id > 0 ? $icon_id : 0,
                );
            }
        }

        if (!empty($raw['colors']) && is_array($raw['colors'])) {
            foreach ($raw['colors'] as $type_slug => $colors) {
                $type_slug = sanitize_title($type_slug);
                if ($type_slug === '') {
                    continue;
                }
                foreach ((array) $colors as $color_slug => $color_settings) {
                    $color_slug = sanitize_title($color_slug);
                    if ($color_slug === '') {
                        continue;
                    }
                    if (!isset($result['colors'][$type_slug])) {
                        $result['colors'][$type_slug] = array();
                    }
                    $swatch = '';
                    if (isset($color_settings['swatch'])) {
                        $clean = sanitize_hex_color($color_settings['swatch']);
                        $swatch = $clean ? $clean : '';
                    }
                    $image_id = isset($color_settings['image_id']) ? intval($color_settings['image_id']) : 0;
                    $result['colors'][$type_slug][$color_slug] = array(
                        'swatch' => $swatch,
                        'image_id' => $image_id > 0 ? $image_id : 0,
                    );
                }
            }
        }

        return $result;
    }

    protected static function resolve_type_icon_url($settings, $type_slug) {
        if (empty($settings['types'][$type_slug]['icon_id'])) {
            return '';
        }
        $icon_id = intval($settings['types'][$type_slug]['icon_id']);
        if ($icon_id <= 0) {
            return '';
        }
        $url = wp_get_attachment_image_url($icon_id, 'thumbnail');
        return $url ? esc_url_raw($url) : '';
    }

    protected static function resolve_color_image_url($settings) {
        if (empty($settings['image_id'])) {
            return '';
        }
        $image_id = intval($settings['image_id']);
        if ($image_id <= 0) {
            return '';
        }
        $url = wp_get_attachment_image_url($image_id, 'thumbnail');
        return $url ? esc_url_raw($url) : '';
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
