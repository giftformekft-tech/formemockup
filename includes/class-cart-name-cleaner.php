<?php

if (!defined('ABSPATH')) {
    exit;
}

class MG_Cart_Name_Cleaner {
    const BULK_SUFFIX = ' póló pulcsi';

    public static function init() {
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'filter_cart_item_name'], 99, 3);
    }

    public static function filter_cart_item_name($product_name, $cart_item, $cart_item_key) {
        if (!function_exists('is_cart') || !is_cart()) {
            return $product_name;
        }
        if (!isset($cart_item['data']) || !($cart_item['data'] instanceof WC_Product)) {
            return $product_name;
        }
        $original_name = $cart_item['data']->get_name();
        if (!$original_name || strpos($original_name, self::BULK_SUFFIX) === false) {
            return self::strip_cart_item_description($product_name, $cart_item, $cart_item_key, $original_name);
        }
        $clean_name = self::strip_suffix($original_name);
        if ($clean_name === $original_name) {
            return self::strip_cart_item_description($product_name, $cart_item, $cart_item_key, $original_name);
        }
        $updated = str_replace($original_name, $clean_name, $product_name);
        if ($updated === $product_name) {
            $updated = self::strip_suffix_from_html($product_name);
        }
        return self::strip_cart_item_description($updated, $cart_item, $cart_item_key, $clean_name);
    }

    private static function strip_suffix($name) {
        if (!$name || !is_string($name)) {
            return $name;
        }
        if (substr($name, -strlen(self::BULK_SUFFIX)) === self::BULK_SUFFIX) {
            return trim(substr($name, 0, -strlen(self::BULK_SUFFIX)));
        }
        return $name;
    }

    private static function strip_suffix_from_html($html) {
        if (!$html || !is_string($html)) {
            return $html;
        }
        $suffix = preg_quote(self::BULK_SUFFIX, '/');
        $html = preg_replace('/' . $suffix . '(?=\\s*<)/u', '', $html);
        $html = preg_replace('/' . $suffix . '\\s*$/u', '', $html);
        return $html;
    }

    private static function strip_cart_item_description($product_name, $cart_item, $cart_item_key, $clean_name) {
        if (!$product_name || !$clean_name) {
            return $product_name;
        }
        $stripped = trim(wp_strip_all_tags($product_name));
        if ($stripped === $clean_name) {
            return $product_name;
        }
        if (strpos($stripped, $clean_name) !== 0) {
            return $product_name;
        }
        if (!isset($cart_item['data']) || !($cart_item['data'] instanceof WC_Product)) {
            return $product_name;
        }
        $product = $cart_item['data'];
        $permalink = apply_filters(
            'woocommerce_cart_item_permalink',
            $product->is_visible() ? $product->get_permalink($cart_item) : '',
            $cart_item,
            $cart_item_key
        );
        if ($permalink) {
            return sprintf('<a href="%s">%s</a>', esc_url($permalink), esc_html($clean_name));
        }
        return esc_html($clean_name);
    }
}
