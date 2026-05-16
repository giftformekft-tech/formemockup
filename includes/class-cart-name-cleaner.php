<?php

if (!defined('ABSPATH')) {
    exit;
}

class MG_Cart_Name_Cleaner {
    const BULK_SUFFIX = ' póló pulcsi';

    public static function init() {
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'filter_cart_item_name'], PHP_INT_MAX, 3);
        add_filter('woocommerce_blocks_cart_item_name', [__CLASS__, 'filter_cart_item_name'], PHP_INT_MAX, 3);
        // Block cart (Store API): set the correct per-item name in the REST JSON response.
        // Runs at PHP_INT_MAX so it has final say over any earlier name overrides.
        add_filter('woocommerce_store_api_cart_item', [__CLASS__, 'filter_store_api_cart_item_name'], PHP_INT_MAX, 2);
    }

    public static function filter_cart_item_name($product_name, $cart_item, $cart_item_key) {
        // Always look up the live session item by key so we get the correct mg_product_type
        // for every item, even when woocommerce_blocks_cart_item_name passes a WC_Product
        // object or the wrong array as $cart_item.
        $item_data = null;
        if ($cart_item_key && WC()->cart) {
            $item_data = WC()->cart->get_cart_item($cart_item_key);
        }
        // Fallback: use the passed $cart_item if the key lookup returned nothing
        if (!$item_data && is_array($cart_item)) {
            $item_data = $cart_item;
        }
        if (!$item_data) {
            return $product_name;
        }

        $product = $item_data['data'] ?? null;
        if (!($product instanceof WC_Product)) {
            // Last resort: $cart_item itself might be the WC_Product
            if ($cart_item instanceof WC_Product) {
                $product = $cart_item;
            }
        }
        if (!($product instanceof WC_Product)) {
            return $product_name;
        }

        // Use 'edit' context: returns the raw stored title without triggering
        // woocommerce_product_get_name filters (avoids override_cart_item_name recursion).
        $original_name = $product->get_name('edit');
        if (!$original_name) {
            return $product_name;
        }

        // Strip the " – Type" or " - Type" suffix from the stored product title,
        // then re-append the correct type label for this specific cart item.
        $type_label = self::get_type_label_from_cart_item($item_data);
        $clean_name = self::strip_type_suffix($original_name);
        if (!$clean_name) {
            return $product_name;
        }

        if ($type_label) {
            $clean_name .= " \u{2013} " . $type_label;
        }

        $permalink = apply_filters(
            'woocommerce_cart_item_permalink',
            $product->is_visible() ? $product->get_permalink($item_data) : '',
            $item_data,
            $cart_item_key
        );
        if ($permalink) {
            return sprintf('<a href="%s">%s</a>', esc_url($permalink), esc_html($clean_name));
        }
        return esc_html($clean_name);
    }

    /**
     * Block cart (Store API): set correct per-item name in the REST JSON response.
     *
     * The WC Block cart React app reads the 'name' field from the Store API cart item.
     * This filter runs after woocommerce_product_get_name and any earlier Store API
     * overrides, ensuring each line item displays the name for its own product type
     * (including crosssell items that share a product_id with the source item).
     */
    public static function filter_store_api_cart_item_name($cart_item_data, $cart_item) {
        if (empty($cart_item['mg_product_type'])) {
            return $cart_item_data;
        }

        $product = $cart_item['data'] ?? null;
        if (!($product instanceof WC_Product)) {
            return $cart_item_data;
        }

        // Crosssell items: mg_product_type was already overwritten with the target type
        // by fix_crosssell_cart_item_data, but mg_crosssell_target_type is the authoritative
        // source for crosssell items. For regular items both fields agree (or only mg_product_type exists).
        $type_key = !empty($cart_item['mg_crosssell_target_type'])
            ? $cart_item['mg_crosssell_target_type']
            : $cart_item['mg_product_type'];

        // 'edit' context returns the stored title without triggering woocommerce_product_get_name
        // filters, avoiding override_cart_item_name adding the wrong type for this item.
        $raw_name   = $product->get_name('edit');
        $clean_name = self::strip_type_suffix($raw_name);
        if (!$clean_name) {
            return $cart_item_data;
        }

        $type_label = self::get_type_label_from_slug(sanitize_title($type_key));
        if ($type_label) {
            $clean_name .= " \u{2013} " . $type_label;
        }

        $cart_item_data['name'] = $clean_name;
        return $cart_item_data;
    }

    /**
     * Get the type label from the cart item's mg_product_type field.
     * For crosssell items, mg_crosssell_target_type takes precedence.
     */
    private static function get_type_label_from_cart_item($cart_item) {
        // Prefer crosssell target type when present (crosssell items)
        $type_key = !empty($cart_item['mg_crosssell_target_type'])
            ? $cart_item['mg_crosssell_target_type']
            : ($cart_item['mg_product_type'] ?? '');

        if (empty($type_key)) {
            return '';
        }

        $type_slug = sanitize_title($type_key);

        return self::get_type_label_from_slug($type_slug);
    }

    private static function get_type_label_from_slug($type_slug) {
        if ($type_slug === '') {
            return '';
        }

        $label = '';
        if (class_exists('MG_Variant_Display_Manager')) {
            $catalog = MG_Variant_Display_Manager::get_catalog_index();
            if (isset($catalog[$type_slug]['label']) && $catalog[$type_slug]['label'] !== $type_slug) {
                $label = $catalog[$type_slug]['label'];
            }
        }

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
