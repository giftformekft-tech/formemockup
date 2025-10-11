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

        $products = self::get_variable_products();
        $store = self::get_settings_store();
        $selected_product_id = self::get_selected_product_id($products);

        if (!empty($_POST['mg_variant_display_nonce']) && check_admin_referer('mg_variant_display_save', 'mg_variant_display_nonce')) {
            $posted_product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            if ($posted_product_id && self::product_exists($products, $posted_product_id)) {
                $product = wc_get_product($posted_product_id);
                if ($product && $product->is_type('variable')) {
                    $catalog = self::get_catalog_for_product($product);
                    $input = isset($_POST['variant_display']) ? wp_unslash($_POST['variant_display']) : array();
                    $sanitized = MG_Variant_Display_Manager::sanitize_settings_block($input, $catalog);
                    $store['products'][$posted_product_id] = $sanitized;
                    update_option('mg_variant_display', $store);
                    $selected_product_id = $posted_product_id;
                    add_settings_error('mg_variant_display', 'mgvd_saved', __('Beállítások elmentve.', 'mgvd'), 'updated');
                } else {
                    add_settings_error('mg_variant_display', 'mgvd_invalid_product', __('A kiválasztott termék nem variálható.', 'mgvd'));
                }
            } else {
                add_settings_error('mg_variant_display', 'mgvd_missing_product', __('Érvénytelen termék lett kiválasztva.', 'mgvd'));
            }
        }

        settings_errors('mg_variant_display');

        echo '<div class="wrap mgvd-admin">';
        echo '<h1>' . esc_html__('Variáns megjelenítés', 'mgvd') . '</h1>';
        echo '<p>' . esc_html__('Állítsd be, hogyan jelenjenek meg a terméktípusok, színek és méretek a termékoldalon.', 'mgvd') . '</p>';

        if (empty($products)) {
            echo '<div class="notice notice-info"><p>' . esc_html__('Még nincs variálható WooCommerce termék, amihez beállításokat lehetne megadni.', 'mgvd') . '</p></div>';
            echo '</div>';
            return;
        }

        $product = $selected_product_id ? wc_get_product($selected_product_id) : null;
        if (!$product || !$product->is_type('variable')) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('A kiválasztott termék nem variálható.', 'mgvd') . '</p></div>';
            echo '</div>';
            return;
        }

        $catalog = self::get_catalog_for_product($product);
        if (empty($catalog)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Ehhez a termékhez nem található beállítható terméktípus.', 'mgvd') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<form method="get" class="mgvd-toolbar">';
        echo '<input type="hidden" name="page" value="mockup-generator-variant-display" />';
        echo '<label class="mgvd-toolbar__label" for="mgvd-product-select">' . esc_html__('Termék kiválasztása', 'mgvd') . '</label>';
        echo '<div class="mgvd-toolbar__control">';
        echo '<select id="mgvd-product-select" name="product_id" class="mgvd-toolbar__select" onchange="this.form.submit()">';
        foreach ($products as $item) {
            $selected_attr = selected($selected_product_id, $item['id'], false);
            echo '<option value="' . esc_attr($item['id']) . '"' . $selected_attr . '>' . esc_html($item['name']) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</form>';

        $product_settings = isset($store['products'][$selected_product_id]) ? MG_Variant_Display_Manager::sanitize_settings_block($store['products'][$selected_product_id], $catalog) : array('types' => array(), 'colors' => array());

        echo '<form method="post" class="mgvd-settings-form">';
        wp_nonce_field('mg_variant_display_save', 'mg_variant_display_nonce');
        echo '<input type="hidden" name="product_id" value="' . esc_attr($selected_product_id) . '" />';
        echo '<div class="mgvd-type-grid">';

        foreach ($catalog as $type_slug => $type_meta) {
            $icon_id = isset($product_settings['types'][$type_slug]['icon_id']) ? intval($product_settings['types'][$type_slug]['icon_id']) : 0;
            $icon_url = $icon_id ? wp_get_attachment_image_url($icon_id, 'thumbnail') : '';

            echo '<section class="mgvd-type-card" data-type="' . esc_attr($type_slug) . '">';
            echo '<div class="mgvd-type-card__header">';
            echo '<div class="mgvd-type-card__title">';
            echo '<h2>' . esc_html($type_meta['label']) . '</h2>';
            echo '<p>' . esc_html__('Ikon és színbeállítások testreszabása', 'mgvd') . '</p>';
            echo '</div>';
            echo '<div class="mgvd-field mgvd-field--icon">';
            echo '<span class="mgvd-field__label">' . esc_html__('Terméktípus ikon', 'mgvd') . '</span>';
            self::render_media_control(
                'variant_display[types][' . $type_slug . '][icon_id]',
                $icon_id,
                $icon_url,
                __('Ikon kiválasztása', 'mgvd')
            );
            echo '</div>';
            echo '</div>';

            if (!empty($type_meta['colors'])) {
                echo '<div class="mgvd-color-grid">';
                foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                    $color_settings = isset($product_settings['colors'][$type_slug][$color_slug]) ? $product_settings['colors'][$type_slug][$color_slug] : array();
                    $swatch = isset($color_settings['swatch']) && $color_settings['swatch'] ? $color_settings['swatch'] : '#ffffff';
                    $image_id = isset($color_settings['image_id']) ? intval($color_settings['image_id']) : 0;
                    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
                    $chip_style = $image_url ? 'background-image:url(' . esc_url($image_url) . ');' : 'background-color:' . esc_attr($swatch) . ';';
                    $chip_class = $image_url ? ' has-image' : '';

                    echo '<div class="mgvd-color-card" data-color="' . esc_attr($color_slug) . '">';
                    echo '<div class="mgvd-color-card__header">';
                    echo '<span class="mgvd-color-chip' . $chip_class . '" style="' . esc_attr($chip_style) . '"></span>';
                    echo '<div class="mgvd-color-card__title">' . esc_html($color_meta['label']) . '</div>';
                    echo '</div>';
                    echo '<div class="mgvd-color-card__controls">';
                    echo '<label class="mgvd-field__label" for="mgvd-color-' . esc_attr($type_slug . '-' . $color_slug) . '">' . esc_html__('Színkód', 'mgvd') . '</label>';
                    echo '<input id="mgvd-color-' . esc_attr($type_slug . '-' . $color_slug) . '" type="color" name="variant_display[colors][' . esc_attr($type_slug) . '][' . esc_attr($color_slug) . '][swatch]" value="' . esc_attr($swatch) . '" />';
                    echo '<div class="mgvd-field mgvd-field--media">';
                    echo '<span class="mgvd-field__label">' . esc_html__('Mintakép', 'mgvd') . '</span>';
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
            } else {
                echo '<p class="mgvd-empty">' . esc_html__('Ehhez a terméktípushoz nem tartoznak színek.', 'mgvd') . '</p>';
            }

            echo '</section>';
        }

        echo '</div>';
        submit_button(__('Beállítások mentése', 'mgvd'));
        echo '</form>';
        echo '</div>';
    }

    protected static function get_settings_store() {
        $raw = get_option('mg_variant_display', array());
        $store = array(
            'global' => array(
                'types' => array(),
                'colors' => array(),
            ),
            'products' => array(),
        );

        if (!is_array($raw)) {
            return $store;
        }

        if (isset($raw['types']) || isset($raw['colors'])) {
            $store['global'] = MG_Variant_Display_Manager::sanitize_settings_block($raw);
            return $store;
        }

        if (!empty($raw['global']) && is_array($raw['global'])) {
            $store['global'] = MG_Variant_Display_Manager::sanitize_settings_block($raw['global']);
        }

        if (!empty($raw['products']) && is_array($raw['products'])) {
            foreach ($raw['products'] as $product_id => $settings) {
                $product_id = absint($product_id);
                if ($product_id <= 0) {
                    continue;
                }
                $store['products'][$product_id] = MG_Variant_Display_Manager::sanitize_settings_block($settings);
            }
        }

        return $store;
    }

    protected static function get_variable_products() {
        if (!function_exists('wc_get_products')) {
            return array();
        }

        if (!class_exists('WC_Product')) {
            return array();
        }

        $products = wc_get_products(array(
            'type' => array('variable'),
            'limit' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'status' => array('publish', 'draft', 'private'),
            'return' => 'objects',
        ));

        $list = array();
        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }
            $list[] = array(
                'id' => $product->get_id(),
                'name' => sprintf('%s (#%d)', $product->get_name(), $product->get_id()),
            );
        }

        return $list;
    }

    protected static function get_selected_product_id($products) {
        if (empty($products)) {
            return 0;
        }
        $requested = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        if ($requested && self::product_exists($products, $requested)) {
            return $requested;
        }
        return absint($products[0]['id']);
    }

    protected static function product_exists($products, $product_id) {
        foreach ($products as $product) {
            if ((int) $product['id'] === (int) $product_id) {
                return true;
            }
        }
        return false;
    }

    protected static function get_catalog_for_product($product) {
        $catalog_index = MG_Variant_Display_Manager::get_catalog_index();
        if (empty($catalog_index)) {
            return array();
        }

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

        $variation_map = array();
        $variations = $product->get_available_variations();
        if (is_array($variations)) {
            foreach ($variations as $variation) {
                if (!is_array($variation) || empty($variation['attributes'])) {
                    continue;
                }
                $attrs = $variation['attributes'];
                $type_slug = isset($attrs['attribute_pa_termektipus']) ? sanitize_title($attrs['attribute_pa_termektipus']) : '';
                $color_slug = isset($attrs['attribute_pa_szin']) ? sanitize_title($attrs['attribute_pa_szin']) : '';
                if ($type_slug === '' || $color_slug === '') {
                    continue;
                }
                if (!isset($variation_map[$type_slug])) {
                    $variation_map[$type_slug] = array();
                }
                $variation_map[$type_slug][$color_slug] = true;
            }
        }

        $catalog = array();
        foreach ($type_slugs as $type_slug) {
            $meta = isset($catalog_index[$type_slug]) ? $catalog_index[$type_slug] : array();
            $label = isset($meta['label']) ? $meta['label'] : self::get_attribute_label('pa_termektipus', $type_slug);
            $colors = array();
            $available_colors = isset($variation_map[$type_slug]) ? array_keys($variation_map[$type_slug]) : array();
            if (empty($available_colors) && !empty($meta['colors'])) {
                $available_colors = array_keys($meta['colors']);
            }

            foreach ($available_colors as $color_slug) {
                $color_label = isset($meta['colors'][$color_slug]['label']) ? $meta['colors'][$color_slug]['label'] : self::get_attribute_label('pa_szin', $color_slug);
                $colors[$color_slug] = array(
                    'label' => $color_label,
                );
            }

            $catalog[$type_slug] = array(
                'label' => $label ? $label : $type_slug,
                'colors' => $colors,
            );
        }

        return $catalog;
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
        echo '<div class="mgvd-media__preview">' . $preview . '</div>';
        echo '<input type="hidden" class="mgvd-media-id" name="' . esc_attr($field_name) . '" value="' . esc_attr($attachment_id) . '" />';
        echo '<button type="button" class="button mgvd-media-select" data-modal-title="' . esc_attr($button_label) . '">' . esc_html($button_label) . '</button>';
        echo '<button type="button" class="button mgvd-media-remove"' . $remove_style . '>' . esc_html__('Eltávolítás', 'mgvd') . '</button>';
        echo '</div>';
    }
}
