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
        $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos((string) $hook, 'mockup-generator-variant-display') === false && $page_slug !== 'mockup-generator-variant-display') {
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
        $script_deps = array('jquery');
        if (wp_script_is('media-editor', 'registered') || wp_script_is('media-editor', 'enqueued') || function_exists('wp_enqueue_media')) {
            $script_deps[] = 'media-editor';
        }

        wp_enqueue_script(
            'mg-variant-display-admin',
            plugins_url('assets/js/variant-display-admin.js', $base_file),
            $script_deps,
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

        // Ensure the WordPress media modal assets are available when the page renders
        // even if enqueue_assets() did not run due to an unexpected hook name.
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        if (empty($catalog)) {
            echo '<div class="wrap mgvd-admin">';
            echo '<h1>' . esc_html__('Variáns megjelenítés', 'mgvd') . '</h1>';
            echo '<div class="notice notice-info"><p>' . esc_html__('Még nincs beállított terméktípus a variánsokhoz.', 'mgvd') . '</p></div>';
            echo '</div>';
            return;
        }

        $store = self::get_settings_store($catalog);
        $selected_type = self::get_selected_type_slug($catalog);

        if (!empty($_POST['mg_variant_display_nonce']) && check_admin_referer('mg_variant_display_save', 'mg_variant_display_nonce')) {
            $posted_type = isset($_POST['type_slug']) ? sanitize_title(wp_unslash($_POST['type_slug'])) : '';
            if ($posted_type && isset($catalog[$posted_type])) {
                $input = isset($_POST['variant_display']) ? wp_unslash($_POST['variant_display']) : array();
                $sanitized = MG_Variant_Display_Manager::sanitize_settings_block($input, array($posted_type => $catalog[$posted_type]));
                $store = self::apply_type_settings($store, $posted_type, $sanitized);
                $store = MG_Variant_Display_Manager::sanitize_settings_block($store, $catalog);
                update_option('mg_variant_display', $store);
                $selected_type = $posted_type;
                add_settings_error('mg_variant_display', 'mgvd_saved', __('Beállítások elmentve.', 'mgvd'), 'updated');
            } else {
                add_settings_error('mg_variant_display', 'mgvd_invalid_type', __('Érvénytelen terméktípust választottál.', 'mgvd'));
            }
        }

        settings_errors('mg_variant_display');

        echo '<div class="wrap mgvd-admin">';
        echo '<h1>' . esc_html__('Variáns megjelenítés', 'mgvd') . '</h1>';
        echo '<p>' . esc_html__('Állítsd be, hogyan jelenjenek meg a terméktípusok, színek és méretek a termékoldalon.', 'mgvd') . '</p>';

        if (!$selected_type || !isset($catalog[$selected_type])) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Nem található a kiválasztott terméktípus.', 'mgvd') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<form method="get" class="mgvd-toolbar">';
        echo '<input type="hidden" name="page" value="mockup-generator-variant-display" />';
        echo '<label class="mgvd-toolbar__label" for="mgvd-type-select">' . esc_html__('Terméktípus kiválasztása', 'mgvd') . '</label>';
        echo '<div class="mgvd-toolbar__control">';
        echo '<select id="mgvd-type-select" name="type_slug" class="mgvd-toolbar__select" onchange="this.form.submit()">';
        foreach ($catalog as $type_slug => $type_meta) {
            $label = isset($type_meta['label']) && $type_meta['label'] ? $type_meta['label'] : self::get_attribute_label('pa_termektipus', $type_slug);
            $selected_attr = selected($selected_type, $type_slug, false);
            echo '<option value="' . esc_attr($type_slug) . '"' . $selected_attr . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</form>';

        $type_meta = $catalog[$selected_type];
        $type_label = isset($type_meta['label']) && $type_meta['label'] ? $type_meta['label'] : self::get_attribute_label('pa_termektipus', $selected_type);
        $icon_id = !empty($store['types'][$selected_type]['icon_id']) ? intval($store['types'][$selected_type]['icon_id']) : 0;
        $icon_url = $icon_id ? wp_get_attachment_image_url($icon_id, 'thumbnail') : '';

        echo '<form method="post" class="mgvd-settings-form">';
        wp_nonce_field('mg_variant_display_save', 'mg_variant_display_nonce');
        echo '<input type="hidden" name="type_slug" value="' . esc_attr($selected_type) . '" />';
        echo '<div class="mgvd-type-grid">';

        echo '<section class="mgvd-type-card" data-type="' . esc_attr($selected_type) . '">';
        echo '<div class="mgvd-type-card__header">';
        echo '<div class="mgvd-type-card__title">';
        echo '<h2>' . esc_html($type_label) . '</h2>';
        echo '<p>' . esc_html__('Ikon és színbeállítások testreszabása', 'mgvd') . '</p>';
        echo '</div>';
        echo '<div class="mgvd-field mgvd-field--icon">';
        echo '<span class="mgvd-field__label">' . esc_html__('Terméktípus ikon', 'mgvd') . '</span>';
        self::render_media_control(
            'variant_display[types][' . $selected_type . '][icon_id]',
            $icon_id,
            $icon_url,
            __('Ikon kiválasztása', 'mgvd')
        );
        echo '</div>';
        echo '</div>';

        if (!empty($type_meta['colors']) && is_array($type_meta['colors'])) {
            echo '<div class="mgvd-color-grid">';
            foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                $color_settings = isset($store['colors'][$selected_type][$color_slug]) ? $store['colors'][$selected_type][$color_slug] : array();
                $swatch = isset($color_settings['swatch']) && $color_settings['swatch'] ? $color_settings['swatch'] : '#ffffff';
                $image_id = isset($color_settings['image_id']) ? intval($color_settings['image_id']) : 0;
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
                $chip_style = $image_url ? 'background-image:url(' . esc_url($image_url) . ');' : 'background-color:' . esc_attr($swatch) . ';';
                $chip_class = $image_url ? ' has-image' : '';
                $color_label = isset($color_meta['label']) && $color_meta['label'] ? $color_meta['label'] : self::get_attribute_label('pa_szin', $color_slug);

                echo '<div class="mgvd-color-card" data-color="' . esc_attr($color_slug) . '">';
                echo '<div class="mgvd-color-card__header">';
                echo '<span class="mgvd-color-chip' . $chip_class . '" style="' . esc_attr($chip_style) . '"></span>';
                echo '<div class="mgvd-color-card__title">' . esc_html($color_label) . '</div>';
                echo '</div>';
                echo '<div class="mgvd-color-card__controls">';
                echo '<label class="mgvd-field__label" for="mgvd-color-' . esc_attr($selected_type . '-' . $color_slug) . '">' . esc_html__('Színkód', 'mgvd') . '</label>';
                echo '<input id="mgvd-color-' . esc_attr($selected_type . '-' . $color_slug) . '" type="color" name="variant_display[colors][' . esc_attr($selected_type) . '][' . esc_attr($color_slug) . '][swatch]" value="' . esc_attr($swatch) . '" />';
                echo '<div class="mgvd-field mgvd-field--media">';
                echo '<span class="mgvd-field__label">' . esc_html__('Mintakép', 'mgvd') . '</span>';
                self::render_media_control(
                    'variant_display[colors][' . $selected_type . '][' . $color_slug . '][image_id]',
                    $image_id,
                    $image_url,
                    __('Mintakép kiválasztása', 'mgvd')
                );
                echo '</div>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<p class="mgvd-empty">' . esc_html__('Ehhez a terméktípushoz nem tartoznak színek.', 'mgvd') . '</p>';
        }

        echo '</section>';
        echo '</div>';
        submit_button(__('Beállítások mentése', 'mgvd'));
        echo '</form>';
        echo '</div>';
    }

    protected static function get_settings_store($catalog) {
        $raw = get_option('mg_variant_display', array());
        if (!is_array($catalog)) {
            $catalog = array();
        }
        $store = MG_Variant_Display_Manager::sanitize_settings_block($raw, $catalog);
        return wp_parse_args($store, array(
            'types' => array(),
            'colors' => array(),
        ));
    }

    protected static function get_selected_type_slug($catalog) {
        if (empty($catalog) || !is_array($catalog)) {
            return '';
        }
        $requested = isset($_GET['type_slug']) ? sanitize_title(wp_unslash($_GET['type_slug'])) : '';
        if ($requested && isset($catalog[$requested])) {
            return $requested;
        }
        $keys = array_keys($catalog);
        return !empty($keys[0]) ? $keys[0] : '';
    }

    protected static function apply_type_settings($store, $type_slug, $sanitized) {
        if (!is_array($store)) {
            $store = array(
                'types' => array(),
                'colors' => array(),
            );
        }
        if (!isset($store['types']) || !is_array($store['types'])) {
            $store['types'] = array();
        }
        if (!isset($store['colors']) || !is_array($store['colors'])) {
            $store['colors'] = array();
        }

        $icon_settings = isset($sanitized['types'][$type_slug]) ? $sanitized['types'][$type_slug] : array('icon_id' => 0);
        $store['types'][$type_slug] = array(
            'icon_id' => isset($icon_settings['icon_id']) ? intval($icon_settings['icon_id']) : 0,
        );

        if (!empty($sanitized['colors'][$type_slug])) {
            $store['colors'][$type_slug] = $sanitized['colors'][$type_slug];
        } else {
            unset($store['colors'][$type_slug]);
        }

        return $store;
    }

    protected static function get_attribute_label($taxonomy, $slug) {
        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return wp_strip_all_tags($term->name);
        }
        $readable = str_replace('-', ' ', $slug);
        return ucwords($readable);
    }

    protected static function render_media_control($field_name, $attachment_id, $current_url, $button_label) {
        $preview = $current_url ? '<img src="' . esc_url($current_url) . '" alt="" />' : '<span class="mgvd-media__placeholder">' . esc_html__('Nincs kép', 'mgvd') . '</span>';
        $remove_style = $attachment_id ? '' : ' style="display:none;"';
        echo '<div class="mgvd-media">';
        echo '<div class="mgvd-media__preview" role="button" tabindex="0" aria-label="' . esc_attr($button_label) . '">' . $preview . '</div>';
        echo '<input type="hidden" class="mgvd-media-id" name="' . esc_attr($field_name) . '" value="' . esc_attr($attachment_id) . '" />';
        echo '<button type="button" class="button mgvd-media-select" data-modal-title="' . esc_attr($button_label) . '">' . esc_html($button_label) . '</button>';
        echo '<button type="button" class="button mgvd-media-remove"' . $remove_style . '>' . esc_html__('Eltávolítás', 'mgvd') . '</button>';
        echo '</div>';
    }
}
