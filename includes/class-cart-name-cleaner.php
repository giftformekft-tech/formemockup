<?php

if (!defined('ABSPATH')) {
    exit;
}

class MG_Cart_Name_Cleaner {
    const BULK_SUFFIX = ' póló pulcsi';

    public static function init() {
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'filter_cart_item_name'], PHP_INT_MAX, 3);
        add_filter('woocommerce_blocks_cart_item_name', [__CLASS__, 'filter_cart_item_name'], PHP_INT_MAX, 3);
    }

    public static function filter_cart_item_name($product_name, $cart_item, $cart_item_key) {
        if (!isset($cart_item['data']) || !($cart_item['data'] instanceof WC_Product)) {
            return $product_name;
        }
        $original_name = $cart_item['data']->get_name();
        if (!$original_name) {
            return $product_name;
        }

        // Strip the " – Type" or " - Type" suffix from the WC product title,
        // then re-append the correct type label for this cart item (handles crosssell).
        $type_label = self::get_type_label_from_cart_item($cart_item);
        $clean_name = self::strip_type_suffix($original_name);
        if (!$clean_name) {
            return $product_name;
        }

        if ($type_label) {
            $clean_name .= " \u{2013} " . $type_label; // em dash separator
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

    /**
     * Get the type label from the cart item's mg_product_type field
     */
    private static function get_type_label_from_cart_item($cart_item) {
        if (empty($cart_item['mg_product_type'])) {
            return '';
        }

        $type_slug = sanitize_title($cart_item['mg_product_type']);
        if ($type_slug === '') {
            return '';
        }

        // Try to get label from catalog
        $label = '';
        if (class_exists('MG_Variant_Display_Manager')) {
            $catalog = MG_Variant_Display_Manager::get_catalog_index();
            if (isset($catalog[$type_slug]['label']) && $catalog[$type_slug]['label'] !== $type_slug) {
                $label = $catalog[$type_slug]['label'];
            }
        }

        // Fallback: WooCommerce product attribute taxonomy (helyes ékezetes nevek)
        if ($label === '') {
            $term = get_term_by('slug', $type_slug, 'pa_termektipus');
            if ($term && !is_wp_error($term)) {
                $label = $term->name;
            }
        }

        return $label !== '' ? $label : ucfirst(str_replace('-', ' ', $type_slug));
    }

    private static function strip_type_suffix($name) {
        if (!$name || !is_string($name)) {
            return $name;
        }
        // Remove legacy BULK_SUFFIX first
        if (substr($name, -strlen(self::BULK_SUFFIX)) === self::BULK_SUFFIX) {
            return trim(substr($name, 0, -strlen(self::BULK_SUFFIX)));
        }
        // Strip " – Type" or " — Type" or " - Type" at the end.
        // Uses the last occurrence so "Design – Sub – Type" → "Design – Sub".
        $pos = strrpos($name, " \u{2013} "); // en dash
        if ($pos === false) {
            $pos = strrpos($name, " \u{2014} "); // em dash
        }
        if ($pos === false) {
            $pos = strrpos($name, ' - '); // hyphen-minus fallback
        }
        if ($pos !== false && $pos > 0) {
            return trim(substr($name, 0, $pos));
        }
        return $name;
    }
}
