<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Size_Selection {
    const FIELD_NAME = 'mg_size';

    public static function init() {
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_size'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 20, 3);
        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'restore_cart_item'], 10, 2);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'render_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_order_item_meta'], 10, 4);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_size_surcharge'], 20, 1);
    }

    public static function validate_size($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
        if (!$passed) {
            return $passed;
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return $passed;
        }
        $type_slug = sanitize_title($variations['attribute_pa_termektipus'] ?? '');
        $color_slug = sanitize_title($variations['attribute_pa_szin'] ?? '');
        $available_sizes = self::get_sizes_for_color($type_slug, $color_slug);
        if (empty($available_sizes)) {
            return $passed;
        }
        $selected = isset($_POST[self::FIELD_NAME]) ? sanitize_text_field(wp_unslash($_POST[self::FIELD_NAME])) : '';
        if ($selected === '' || !in_array($selected, $available_sizes, true)) {
            wc_add_notice(__('Kérjük válassz méretet.', 'mgdtp'), 'error');
            return false;
        }
        return $passed;
    }

    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        $selected = isset($_POST[self::FIELD_NAME]) ? sanitize_text_field(wp_unslash($_POST[self::FIELD_NAME])) : '';
        if ($selected === '') {
            return $cart_item_data;
        }
        $cart_item_data['mg_size'] = $selected;
        $base_price = 0.0;
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $base_price = floatval(wc_get_price_to_display($variation));
            }
        } else {
            $product = wc_get_product($product_id);
            if ($product) {
                $base_price = floatval(wc_get_price_to_display($product));
            }
        }
        $cart_item_data['mg_size_base_price'] = $base_price;
        $type_slug = '';
        $color_slug = '';
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $type_slug = sanitize_title($variation->get_attribute('pa_termektipus'));
                $color_slug = sanitize_title($variation->get_attribute('pa_szin'));
            }
        }
        if ($type_slug !== '' && $color_slug !== '') {
            $available_sizes = self::get_sizes_for_color($type_slug, $color_slug);
            if (!empty($available_sizes) && !in_array($selected, $available_sizes, true)) {
                return $cart_item_data;
            }
        }
        $size_surcharge = 0.0;
        if ($type_slug !== '' && function_exists('mgsc_get_size_surcharge')) {
            $size_surcharge = floatval(mgsc_get_size_surcharge($type_slug, $selected));
        }
        $cart_item_data['mg_size_surcharge'] = $size_surcharge;
        $updated_base = max(0, $base_price + $size_surcharge);
        $cart_item_data['mg_custom_fields_base_price'] = $updated_base;
        if (!empty($cart_item_data['unique_key'])) {
            $cart_item_data['unique_key'] = md5($cart_item_data['unique_key'] . '|' . $selected);
        } else {
            $cart_item_data['unique_key'] = md5('mg_size|' . $product_id . '|' . $variation_id . '|' . $selected);
        }
        return $cart_item_data;
    }

    public static function restore_cart_item($cart_item, $values) {
        if (isset($values['mg_size'])) {
            $cart_item['mg_size'] = $values['mg_size'];
        }
        if (isset($values['mg_size_surcharge'])) {
            $cart_item['mg_size_surcharge'] = $values['mg_size_surcharge'];
        }
        if (isset($values['mg_size_base_price'])) {
            $cart_item['mg_size_base_price'] = $values['mg_size_base_price'];
        }
        return $cart_item;
    }

    public static function render_cart_item_data($item_data, $cart_item) {
        if (empty($cart_item['mg_size'])) {
            return $item_data;
        }
        $item_data[] = [
            'name' => __('Méret', 'mgdtp'),
            'value' => sanitize_text_field($cart_item['mg_size']),
        ];
        return $item_data;
    }

    public static function add_order_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['mg_size'])) {
            return;
        }
        $item->add_meta_data(__('Méret', 'mgdtp'), sanitize_text_field($values['mg_size']), true);
    }

    public static function apply_size_surcharge($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['mg_size'])) {
                continue;
            }
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            if (!$product instanceof WC_Product) {
                continue;
            }
            $size_surcharge = isset($cart_item['mg_size_surcharge']) ? floatval($cart_item['mg_size_surcharge']) : 0.0;
            if (!$size_surcharge) {
                $type_slug = '';
                if ($product->is_type('variation')) {
                    $type_slug = sanitize_title($product->get_attribute('pa_termektipus'));
                }
                if ($type_slug !== '' && function_exists('mgsc_get_size_surcharge')) {
                    $size_surcharge = floatval(mgsc_get_size_surcharge($type_slug, $cart_item['mg_size']));
                }
            }
            $base_price = isset($cart_item['mg_size_base_price']) ? floatval($cart_item['mg_size_base_price']) : floatval($product->get_price());
            $cart->cart_contents[$cart_item_key]['mg_size_surcharge'] = $size_surcharge;
            $cart->cart_contents[$cart_item_key]['mg_size_base_price'] = $base_price;
            $new_price = max(0, $base_price + $size_surcharge);
            $product->set_price($new_price);
            $cart->cart_contents[$cart_item_key]['mg_custom_fields_base_price'] = $new_price;
        }
    }

    private static function get_sizes_for_color($type_slug, $color_slug) {
        if ($type_slug === '' || $color_slug === '' || !class_exists('MG_Variant_Display_Manager')) {
            return [];
        }
        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        if (empty($catalog[$type_slug]) || !is_array($catalog[$type_slug])) {
            return [];
        }
        $type_meta = $catalog[$type_slug];
        $sizes = isset($type_meta['sizes']) && is_array($type_meta['sizes']) ? $type_meta['sizes'] : [];
        $matrix = isset($type_meta['size_color_matrix']) && is_array($type_meta['size_color_matrix']) ? $type_meta['size_color_matrix'] : [];
        if (empty($matrix)) {
            return $sizes;
        }
        $allowed = [];
        foreach ($sizes as $size_label) {
            $size_label = sanitize_text_field($size_label);
            if ($size_label === '') {
                continue;
            }
            if (!isset($matrix[$size_label]) || !is_array($matrix[$size_label])) {
                continue;
            }
            if (in_array($color_slug, $matrix[$size_label], true)) {
                $allowed[] = $size_label;
            }
        }
        return $allowed;
    }
}
