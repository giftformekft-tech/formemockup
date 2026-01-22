<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Surcharge_Manager {
    const OPTION_KEY = 'mg_mockup_surcharges';
    const CACHE_KEY = 'mg_mockup_surcharges_cache';
    const CACHE_GROUP = 'mg_mockup';
    const LOCK_MESSAGE_OPTION = 'mg_surcharge_cart_lock_message';

    public static function get_surcharges($only_active = false) {
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if ($cached === false) {
            $data = get_option(self::OPTION_KEY, []);
            if (!is_array($data)) {
                $data = [];
            }
            $cached = array_values(array_map([__CLASS__, 'normalize_surcharge'], $data));
            wp_cache_set(self::CACHE_KEY, $cached, self::CACHE_GROUP);
        }
        if (!$only_active) {
            return $cached;
        }
        return array_values(array_filter($cached, function ($item) {
            return !empty($item['active']);
        }));
    }

    public static function get_surcharge($id) {
        foreach (self::get_surcharges(false) as $surcharge) {
            if ($surcharge['id'] === $id) {
                return $surcharge;
            }
        }
        return null;
    }

    public static function save_surcharges(array $surcharges) {
        $normalized = [];
        foreach ($surcharges as $surcharge) {
            $normalized[$surcharge['id']] = self::normalize_surcharge($surcharge);
        }
        update_option(self::OPTION_KEY, $normalized, false);
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }

    public static function upsert_surcharge(array $surcharge) {
        $all = self::get_surcharges(false);
        $found = false;
        foreach ($all as $index => $item) {
            if ($item['id'] === $surcharge['id']) {
                $all[$index] = self::normalize_surcharge($surcharge);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $all[] = self::normalize_surcharge($surcharge);
        }
        self::save_surcharges($all);
    }

    public static function delete_surcharge($id) {
        $remaining = array_filter(self::get_surcharges(false), function ($item) use ($id) {
            return $item['id'] !== $id;
        });
        self::save_surcharges($remaining);
    }

    public static function normalize_surcharge($surcharge) {
        $defaults = [
            'id' => '',
            'name' => '',
            'description' => '',
            'amount' => 0,
            'active' => false,
            'priority' => 10,
            'mandatory' => false,
            'default_enabled' => false,
            'frontend_display' => 'product',
            'cart_lock' => false,
            'conditions' => [
                'product_types' => [],
                'colors' => [],
                'sizes' => [],
                'categories' => [],
                'products' => [],
                'min_cart_total' => '',
            ],
            'require_choice' => false,
        ];
        $surcharge = wp_parse_args($surcharge, $defaults);
        $surcharge['id'] = $surcharge['id'] ? sanitize_key($surcharge['id']) : uniqid('mg_surcharge_');
        $surcharge['name'] = sanitize_text_field($surcharge['name']);
        $surcharge['description'] = wp_kses_post($surcharge['description']);
        $surcharge['amount'] = floatval($surcharge['amount']);
        $surcharge['priority'] = intval($surcharge['priority']);
        $surcharge['active'] = !empty($surcharge['active']);
        $surcharge['mandatory'] = !empty($surcharge['mandatory']);
        $surcharge['require_choice'] = !empty($surcharge['require_choice']);
        $surcharge['default_enabled'] = !empty($surcharge['default_enabled']);
        $surcharge['cart_lock'] = !empty($surcharge['cart_lock']);
        $allowed_display = ['product', 'cart', 'both'];
        $surcharge['frontend_display'] = in_array($surcharge['frontend_display'], $allowed_display, true) ? $surcharge['frontend_display'] : 'product';
        $conditions = is_array($surcharge['conditions']) ? $surcharge['conditions'] : [];
        $surcharge['conditions'] = [
            'product_types' => self::sanitize_string_array(isset($conditions['product_types']) ? $conditions['product_types'] : []),
            'colors' => self::sanitize_string_array(isset($conditions['colors']) ? $conditions['colors'] : []),
            'sizes' => self::sanitize_string_array(isset($conditions['sizes']) ? $conditions['sizes'] : []),
            'categories' => self::sanitize_int_array(isset($conditions['categories']) ? $conditions['categories'] : []),
            'products' => self::sanitize_int_array(isset($conditions['products']) ? $conditions['products'] : []),
            'min_cart_total' => isset($conditions['min_cart_total']) && $conditions['min_cart_total'] !== '' ? floatval($conditions['min_cart_total']) : '',
        ];
        return $surcharge;
    }

    private static function sanitize_string_array($values) {
        if (!is_array($values)) {
            $values = array_filter(array_map('trim', explode(',', (string)$values)));
        }
        $values = array_map('sanitize_title', $values);
        return array_values(array_filter($values));
    }

    private static function sanitize_int_array($values) {
        if (!is_array($values)) {
            $values = array_filter(array_map('trim', explode(',', (string)$values)));
        }
        $values = array_map('intval', $values);
        return array_values(array_filter($values));
    }

    public static function sort_surcharges(array $surcharges) {
        usort($surcharges, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return strcmp($a['name'], $b['name']);
            }
            return ($a['priority'] < $b['priority']) ? -1 : 1;
        });
        return $surcharges;
    }

    public static function conditions_match_product($surcharge, $product, $variation = null, $cart_total = null, $context = array()) {
        if (!$surcharge || !$product) {
            return false;
        }
        $context = is_array($context) ? $context : array();
        $conditions = isset($surcharge['conditions']) ? $surcharge['conditions'] : [];
        if (!is_array($conditions)) {
            $conditions = [];
        }

        if (!empty($conditions['products'])) {
            $ids = array_map('intval', $conditions['products']);
            $variation_id = ($variation instanceof WC_Product) ? $variation->get_id() : 0;
            if ($variation_id && in_array($variation_id, $ids, true)) {
                // valid via variation
            } elseif (in_array($product->get_id(), $ids, true)) {
                // valid via product
            } elseif ($variation === null) {
                // defer final validation until variation is known
            } else {
                return false;
            }
        }

        if (!empty($conditions['categories'])) {
            $product_cats = wc_get_product_term_ids($product->get_id(), 'product_cat');
            if (empty(array_intersect($product_cats, array_map('intval', $conditions['categories'])))) {
                return false;
            }
        }

        if (!self::match_attribute_condition($conditions, 'product_types', $product, $variation, array('pa_termektipus', 'pa_product_type'), $context)) {
            return false;
        }
        if (!self::match_attribute_condition($conditions, 'colors', $product, $variation, array('pa_szin', 'pa_color'), $context)) {
            return false;
        }
        if (!self::match_attribute_condition($conditions, 'sizes', $product, $variation, array('pa_meret', 'pa_size', 'meret'), $context)) {
            return false;
        }

        if (isset($conditions['min_cart_total']) && $conditions['min_cart_total'] !== '') {
            $cart_total = $cart_total === null && function_exists('WC') && WC() && WC()->cart ? WC()->cart->get_subtotal() : $cart_total;
            $cart_total = floatval($cart_total);
            if ($cart_total < floatval($conditions['min_cart_total'])) {
                return false;
            }
        }
        return true;
    }

    private static function match_attribute_condition($conditions, $key, $product, $variation, $taxonomies, $context) {
        if (empty($conditions[$key])) {
            return true;
        }
        $required = array_map('sanitize_title', $conditions[$key]);
        $values = self::get_attribute_values($product, $variation, $taxonomies);
        $virtual = self::get_virtual_attribute_values($context, $taxonomies);
        if (!empty($virtual)) {
            $values = array_values(array_unique(array_merge($values, $virtual)));
        }
        if (empty($values) && $variation === null && $product instanceof WC_Product && $product->is_type('variable')) {
            return true;
        }
        if (empty($values)) {
            return false;
        }
        return !empty(array_intersect($required, $values));
    }

    private static function get_attribute_values($product, $variation, $taxonomies) {
        $values = [];
        foreach ($taxonomies as $taxonomy) {
            $values = array_merge($values, self::get_values_for_taxonomy($product, $variation, $taxonomy));
        }
        return array_values(array_unique(array_filter($values)));
    }

    private static function get_virtual_attribute_values($context, $taxonomies) {
        if (empty($context['virtual_attributes']) || !is_array($context['virtual_attributes'])) {
            return [];
        }
        $values = [];
        foreach ($taxonomies as $taxonomy) {
            if (empty($context['virtual_attributes'][$taxonomy])) {
                continue;
            }
            $values = array_merge($values, array_map('sanitize_title', (array) $context['virtual_attributes'][$taxonomy]));
        }
        return array_values(array_unique(array_filter($values)));
    }

    private static function get_values_for_taxonomy($product, $variation, $taxonomy) {
        $slugs = [];
        if ($variation && $variation instanceof WC_Product_Variation) {
            $attr = $variation->get_attribute(str_replace('pa_', 'attribute_pa_', $taxonomy));
            if ($attr) {
                $slugs[] = sanitize_title($attr);
            }
        }
        if (empty($slugs)) {
            $terms = wc_get_product_terms($product->get_id(), $taxonomy, array('fields' => 'slugs'));
            if (!is_wp_error($terms)) {
                $slugs = array_merge($slugs, array_map('sanitize_title', $terms));
            }
        }
        return $slugs;
    }
}
