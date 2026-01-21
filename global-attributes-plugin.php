<?php
/*
Plugin Name: Global Product Attributes (Static)
Description: Globális terméktípus/szín/méret konfiguráció és kliensoldali mockup képváltás egyszerű termékekhez.
Version: 1.0.0
Author: OpenAI
*/

if (!defined('ABSPATH')) {
    exit;
}

class MG_Global_Attributes_Plugin {
    const CONFIG_PATH = __DIR__ . '/includes/global-attributes.php';

    public static function init() {
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_selection'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'add_cart_item_data'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'render_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'add_order_item_meta'), 10, 4);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_cart_pricing'), 20);
        add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'filter_cart_thumbnail'), 10, 3);
    }

    public static function get_config() {
        if (!file_exists(self::CONFIG_PATH)) {
            return array('types' => array(), 'colors' => array(), 'sizes' => array());
        }
        $config = include self::CONFIG_PATH;
        if (!is_array($config)) {
            return array('types' => array(), 'colors' => array(), 'sizes' => array());
        }
        return $config;
    }

    public static function validate_selection($passed, $product_id, $quantity) {
        if (!isset($_POST['mg_global_type'], $_POST['mg_global_color'], $_POST['mg_global_size'])) {
            wc_add_notice(__('Válaszd ki a terméktípust, színt és méretet.', 'mgdtp'), 'error');
            return false;
        }
        return $passed;
    }

    public static function add_cart_item_data($cart_item_data, $product_id) {
        $type = sanitize_text_field($_POST['mg_global_type'] ?? '');
        $color = sanitize_text_field($_POST['mg_global_color'] ?? '');
        $size = sanitize_text_field($_POST['mg_global_size'] ?? '');
        if ($type === '' || $color === '' || $size === '') {
            return $cart_item_data;
        }
        $cart_item_data['mg_global_type'] = $type;
        $cart_item_data['mg_global_color'] = $color;
        $cart_item_data['mg_global_size'] = $size;
        $cart_item_data['mg_global_uid'] = md5($type . '|' . $color . '|' . $size . '|' . microtime(true));
        return $cart_item_data;
    }

    public static function render_cart_item_data($item_data, $cart_item) {
        if (!empty($cart_item['mg_global_type'])) {
            $item_data[] = array(
                'key' => __('Terméktípus', 'mgdtp'),
                'value' => wc_clean($cart_item['mg_global_type']),
            );
        }
        if (!empty($cart_item['mg_global_color'])) {
            $item_data[] = array(
                'key' => __('Szín', 'mgdtp'),
                'value' => wc_clean($cart_item['mg_global_color']),
            );
        }
        if (!empty($cart_item['mg_global_size'])) {
            $item_data[] = array(
                'key' => __('Méret', 'mgdtp'),
                'value' => wc_clean($cart_item['mg_global_size']),
            );
        }
        return $item_data;
    }

    public static function add_order_item_meta($item, $cart_item_key, $values, $order) {
        if (!empty($values['mg_global_type'])) {
            $item->add_meta_data('mg_global_type', $values['mg_global_type']);
        }
        if (!empty($values['mg_global_color'])) {
            $item->add_meta_data('mg_global_color', $values['mg_global_color']);
        }
        if (!empty($values['mg_global_size'])) {
            $item->add_meta_data('mg_global_size', $values['mg_global_size']);
        }
    }

    public static function apply_cart_pricing($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !method_exists($cart, 'get_cart')) {
            return;
        }
        $types = self::index_types_by_id();
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['mg_global_type']) || empty($cart_item['data'])) {
                continue;
            }
            $type_id = $cart_item['mg_global_type'];
            if (!isset($types[$type_id])) {
                continue;
            }
            $price = floatval($types[$type_id]['price'] ?? 0);
            $cart_item['data']->set_price($price);
        }
    }

    public static function filter_cart_thumbnail($thumbnail, $cart_item, $cart_item_key) {
        if (empty($cart_item['mg_global_type']) || empty($cart_item['mg_global_color'])) {
            return $thumbnail;
        }
        $sku = '';
        if (!empty($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
            $sku = $cart_item['data']->get_sku();
            if ($sku === '') {
                $sku = (string) $cart_item['data']->get_id();
            }
        }
        if ($sku === '') {
            return $thumbnail;
        }
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_url = isset($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') : '';
        $default_base = $base_url !== '' ? $base_url . '/mockups' : '';
        $mockup_base = apply_filters('mg_global_mockup_base_url', $default_base, $cart_item['data'] ?? null);
        if ($mockup_base === '') {
            return $thumbnail;
        }
        $type_id = sanitize_title($cart_item['mg_global_type']);
        $color_id = sanitize_title($cart_item['mg_global_color']);
        $url = trailingslashit($mockup_base) . rawurlencode($sku . '_' . $type_id . '_' . $color_id . '.webp');
        $size = wc_get_image_size('woocommerce_thumbnail');
        $width = isset($size['width']) ? intval($size['width']) : 300;
        $height = isset($size['height']) ? intval($size['height']) : 300;
        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" />',
            esc_url($url),
            esc_attr__('Mockup előnézet', 'mgdtp'),
            $width,
            $height
        );
    }

    private static function index_types_by_id() {
        $config = self::get_config();
        $types = array();
        if (!empty($config['types']) && is_array($config['types'])) {
            foreach ($config['types'] as $type) {
                if (!is_array($type) || empty($type['id'])) {
                    continue;
                }
                $types[$type['id']] = $type;
            }
        }
        return $types;
    }
}

MG_Global_Attributes_Plugin::init();
