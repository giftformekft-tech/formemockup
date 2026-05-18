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
        // Prefer the direct $cart_item argument: it is always the item being rendered.
        // Key lookup is a fallback for when $cart_item is a WC_Product object (older Blocks
        // versions) and is also used to supply the WC_Product 'data' object when missing.
        $item_data = null;
        if (is_array($cart_item) && !empty($cart_item['product_id'])) {
            $item_data = $cart_item;
        }
        if (!$item_data && $cart_item_key && WC()->cart) {
            $item_data = WC()->cart->get_cart_item($cart_item_key);
        }
        if (!$item_data) {
            return $product_name;
        }

        // If $item_data is missing the WC_Product object (can happen when $cart_item was
        // used directly and 'data' was not yet attached), pull it from the session lookup.
        if (empty($item_data['data']) && $cart_item_key && WC()->cart) {
            $session_item = WC()->cart->get_cart_item($cart_item_key);
            if (!empty($session_item['data'])) {
                $item_data['data'] = $session_item['data'];
            }
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
        $product = $cart_item['data'] ?? null;
        if (!($product instanceof WC_Product)) {
            return $cart_item_data;
        }

        // Resolve the per-item type. Crosssell items MUST use mg_crosssell_target_type —
        // never fall back to mg_product_type (which may carry the source item's type if any
        // upstream filter mutated $cart_item). For regular items, use mg_product_type.
        $is_crosssell = !empty($cart_item['mg_crosssell_rule_id']);

        $session_item = null;
        if (!empty($cart_item['key']) && WC()->cart) {
            $session_item = WC()->cart->get_cart_item($cart_item['key']);
        }

        if ($is_crosssell) {
            $type_key = '';
            if (!empty($cart_item['mg_crosssell_target_type'])) {
                $type_key = $cart_item['mg_crosssell_target_type'];
            } elseif (!empty($session_item['mg_crosssell_target_type'])) {
                $type_key = $session_item['mg_crosssell_target_type'];
            }
            // If we cannot resolve the target type, leave the name untouched rather than
            // risk re-appending the source type from mg_product_type.
            if ($type_key === '') {
                return $cart_item_data;
            }
        } else {
            if (empty($cart_item['mg_product_type'])) {
                return $cart_item_data;
            }
            $type_key = $cart_item['mg_product_type'];
        }

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
     * Get the type label from the cart item.
     *
     * For crosssell items (detected via mg_crosssell_rule_id), the target type is
     * authoritative — we never fall back to mg_product_type, which may carry the
     * source item's type if any upstream filter mutated the array. If the target
     * type is missing from $cart_item, look it up from WC()->cart directly.
     */
    private static function get_type_label_from_cart_item($cart_item) {
        $is_crosssell = !empty($cart_item['mg_crosssell_rule_id']);

        if ($is_crosssell) {
            $type_key = '';
            if (!empty($cart_item['mg_crosssell_target_type'])) {
                $type_key = $cart_item['mg_crosssell_target_type'];
            } elseif (!empty($cart_item['key']) && WC()->cart) {
                $session_item = WC()->cart->get_cart_item($cart_item['key']);
                if (!empty($session_item['mg_crosssell_target_type'])) {
                    $type_key = $session_item['mg_crosssell_target_type'];
                }
            }
            if ($type_key === '') {
                return '';
            }
        } else {
            $type_key = $cart_item['mg_product_type'] ?? '';
            if ($type_key === '') {
                return '';
            }
        }

        return self::get_type_label_from_slug(sanitize_title($type_key));
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
