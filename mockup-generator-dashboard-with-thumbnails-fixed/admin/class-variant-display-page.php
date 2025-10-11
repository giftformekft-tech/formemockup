<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Variant_Display_Page {
    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __('Variáns megjelenítés', 'mgvd'),
            __('Variáns megjelenítés', 'mgvd'),
            'manage_woocommerce',
            'mockup-generator-variant-display',
            array(__CLASS__, 'render_page')
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'mockup-generator_page_mockup-generator-variant-display') {
            return;
        }

        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $css_path = dirname(__DIR__) . '/assets/css/variant-display-admin.css';
        $js_path = dirname(__DIR__) . '/assets/js/variant-display-admin.js';

        wp_enqueue_media();
        wp_enqueue_style(
            'mg-variant-display-admin',
            plugins_url('assets/css/variant-display-admin.css', $base_file),
            array(),
            file_exists($css_path) ? filemtime($css_path) : '1.0.0'
        );
        wp_enqueue_script(
            'mg-variant-display-admin',
            plugins_url('assets/js/variant-display-admin.js', $base_file),
            array('jquery'),
            file_exists($js_path) ? filemtime($js_path) : '1.0.0',
            true
        );
        wp_localize_script('mg-variant-display-admin', 'MGVD_Admin', array(
            'placeholder' => __('Nincs kép', 'mgvd'),
            'select' => __('Kép kiválasztása', 'mgvd'),
        ));
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nincs jogosultságod a beállítások módosításához.', 'mgvd'));
        }

        $catalog = self::get_catalog();
        $settings = get_option('mg_variant_display', array());

        if (!empty($_POST['mg_variant_display_nonce']) && check_admin_referer('mg_variant_display_save', 'mg_variant_display_nonce')) {
            $input = isset($_POST['variant_display']) ? wp_unslash($_POST['variant_display']) : array();
            $sanitized = self::sanitize_settings($input, $catalog);
            update_option('mg_variant_display', $sanitized);
            $settings = $sanitized;
            add_settings_error('mg_variant_display', 'mgvd_saved', __('Beállítások elmentve.', 'mgvd'), 'updated');
        }

        settings_errors('mg_variant_display');

        echo '<div class="wrap mgvd-admin">';
        echo '<h1>' . esc_html__('Variáns megjelenítés', 'mgvd') . '</h1>';
        echo '<p>' . esc_html__('Állítsd be, hogyan jelenjenek meg a terméktípusok, színek és méretek a termékoldalon.', 'mgvd') . '</p>';

        if (empty($catalog)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Még nincs beállított terméktípus.', 'mgvd') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<form method="post">';
        wp_nonce_field('mg_variant_display_save', 'mg_variant_display_nonce');
        echo '<div class="mgvd-type-list">';

        foreach ($catalog as $type_slug => $type_meta) {
            $icon_id = isset($settings['types'][$type_slug]['icon_id']) ? intval($settings['types'][$type_slug]['icon_id']) : 0;
            $icon_url = $icon_id ? wp_get_attachment_image_url($icon_id, 'thumbnail') : '';

            echo '<div class="mgvd-type" data-type="' . esc_attr($type_slug) . '">';
            echo '<h2 class="mgvd-type__title">' . esc_html($type_meta['label']) . '</h2>';

            echo '<div class="mgvd-field mgvd-field--icon">';
            echo '<label>' . esc_html__('Terméktípus ikon', 'mgvd') . '</label>';
            self::render_media_control(
                'variant_display[types][' . $type_slug . '][icon_id]',
                $icon_id,
                $icon_url,
                __('Ikon kiválasztása', 'mgvd')
            );
            echo '</div>';

            if (!empty($type_meta['colors'])) {
                echo '<div class="mgvd-colors">';
                echo '<h3>' . esc_html__('Színek', 'mgvd') . '</h3>';
                foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                    $color_settings = isset($settings['colors'][$type_slug][$color_slug]) ? $settings['colors'][$type_slug][$color_slug] : array();
                    $swatch = isset($color_settings['swatch']) ? $color_settings['swatch'] : '#ffffff';
                    $image_id = isset($color_settings['image_id']) ? intval($color_settings['image_id']) : 0;
                    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';

                    echo '<div class="mgvd-color" data-color="' . esc_attr($color_slug) . '">';
                    echo '<div class="mgvd-color__header">';
                    echo '<span class="mgvd-color__name">' . esc_html($color_meta['label']) . '</span>';
                    echo '</div>';
                    echo '<div class="mgvd-color__controls">';
                    echo '<label>' . esc_html__('Színkód', 'mgvd') . '</label>';
                    echo '<input type="color" name="variant_display[colors][' . esc_attr($type_slug) . '][' . esc_attr($color_slug) . '][swatch]" value="' . esc_attr($swatch ? $swatch : '#ffffff') . '" />';
                    echo '<div class="mgvd-color__media">';
                    echo '<span class="mgvd-color__media-label">' . esc_html__('Color swatch kép (opcionális)', 'mgvd') . '</span>';
                    self::render_media_control(
                        'variant_display[colors][' . $type_slug . '][' . $color_slug . '][image_id]',
                        $image_id,
                        $image_url,
                        __('Mintakép kiválasztása', 'mgvd')
                    );
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>';
        submit_button(__('Beállítások mentése', 'mgvd'));
        echo '</form>';
        echo '</div>';
    }

    protected static function get_catalog() {
        $all = get_option('mg_products', array());
        $catalog = array();
        if (!is_array($all)) {
            return $catalog;
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

            $catalog[$slug] = array(
                'label' => $label,
                'colors' => $colors,
            );
        }

        return $catalog;
    }

    protected static function sanitize_settings($input, $catalog) {
        $clean = array(
            'types' => array(),
            'colors' => array(),
        );

        if (!is_array($input)) {
            return $clean;
        }

        if (!empty($input['types']) && is_array($input['types'])) {
            foreach ($input['types'] as $type_slug => $type_data) {
                $slug = sanitize_title($type_slug);
                if ($slug === '' || !isset($catalog[$slug])) {
                    continue;
                }
                $icon_id = isset($type_data['icon_id']) ? intval($type_data['icon_id']) : 0;
                $clean['types'][$slug] = array(
                    'icon_id' => $icon_id > 0 ? $icon_id : 0,
                );
            }
        }

        if (!empty($input['colors']) && is_array($input['colors'])) {
            foreach ($input['colors'] as $type_slug => $colors) {
                $type_slug = sanitize_title($type_slug);
                if ($type_slug === '' || !isset($catalog[$type_slug])) {
                    continue;
                }
                foreach ((array) $colors as $color_slug => $color_data) {
                    $color_slug = sanitize_title($color_slug);
                    if ($color_slug === '' || !isset($catalog[$type_slug]['colors'][$color_slug])) {
                        continue;
                    }
                    if (!isset($clean['colors'][$type_slug])) {
                        $clean['colors'][$type_slug] = array();
                    }
                    $swatch = isset($color_data['swatch']) ? sanitize_hex_color($color_data['swatch']) : '';
                    $image_id = isset($color_data['image_id']) ? intval($color_data['image_id']) : 0;
                    $clean['colors'][$type_slug][$color_slug] = array(
                        'swatch' => $swatch ? $swatch : '',
                        'image_id' => $image_id > 0 ? $image_id : 0,
                    );
                }
            }
        }

        return $clean;
    }

    protected static function render_media_control($field_name, $attachment_id, $current_url, $button_label) {
        $preview = $current_url ? '<img src="' . esc_url($current_url) . '" alt="" />' : '<span class="mgvd-media__placeholder">' . esc_html__('Nincs kép', 'mgvd') . '</span>';
        $remove_style = $attachment_id ? '' : ' style="display:none;"';
        echo '<div class="mgvd-media">';
        echo '<div class="mgvd-media__preview">' . $preview . '</div>';
        echo '<input type="hidden" class="mgvd-media-id" name="' . esc_attr($field_name) . '" value="' . esc_attr($attachment_id) . '" />';
        echo '<button type="button" class="button mgvd-media-select" data-modal-title="' . esc_attr($button_label) . '">' . esc_html($button_label) . '</button>';
        echo '<button type="button" class="button mgvd-media-remove"' . $remove_style . '>' . esc_html__('Eltávolítás', 'mgvd') . '</button>';
        echo '</div>';
    }
}
