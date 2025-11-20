<?php
if (!defined('ABSPATH')) exit;

/**
 * Variáns ár felárakkal: típus alapár + méret felár + szín felár
 * Forrás: mg_products opció
 */

if (!function_exists('mgsc_get_products')) {
    function mgsc_get_products(){
        $all = get_option('mg_products', array());
        $out = array();
        if (is_array($all)) {
            foreach ($all as $p) {
                if (!is_array($p)) continue;
                $key = isset($p['key']) ? sanitize_title($p['key']) : '';
                if (!$key) continue;
                $out[$key] = $p;
            }
        }
        return $out;
    }
}

if (!function_exists('mgsc_compute_variant_price')) {
    function mgsc_compute_variant_price($type_key, $size, $color_slug){
        $prods = mgsc_get_products();
        $type_key = sanitize_title($type_key);
        if (!isset($prods[$type_key])) return null;
        $p = $prods[$type_key];

        $base = isset($p['price']) ? floatval($p['price']) : 0;
        // size surcharge
        $sz = (string)$size;
        $size_sur = 0;
        if (isset($p['size_surcharges']) && is_array($p['size_surcharges']) && isset($p['size_surcharges'][$sz])) {
            $size_sur = floatval($p['size_surcharges'][$sz]);
        }
        // color surcharge
        $col_sur = 0;
        if (isset($p['colors']) && is_array($p['colors'])) {
            foreach ($p['colors'] as $c) {
                $slug = isset($c['slug']) ? sanitize_title($c['slug']) : '';
                if ($slug === sanitize_title($color_slug)) {
                    $col_sur = isset($c['surcharge']) ? floatval($c['surcharge']) : 0;
                    break;
                }
            }
        }
        $price = $base + $size_sur + $col_sur;
        return max($price, 0);
    }
}

/**
 * 1) Ha a saját variáns payload-ot szűritek, használjátok ezt:
 *    $payload = apply_filters('mg_variant_payload', $payload, $type_key, $size, $color_slug);
 */
add_filter('mg_variant_payload', function($payload, $type_key, $size, $color_slug){
    try {
        $new = mgsc_compute_variant_price($type_key, $size, $color_slug);
        if ($new !== null) {
            $payload['regular_price'] = (string) $new;
        }
    } catch (\Throwable $e) {}
    return $payload;
}, 10, 4);

/**
 * 2) Automatikus WooCommerce REST hook – ha a plugin REST-en hoz létre variánst,
 *    a beszúrás előtt megpróbáljuk beállítani az árat.
 *    FONTOS: ehhez szükséges, hogy a SZÜLŐ terméken legyen _mg_type_key meta.
 */
add_filter('woocommerce_rest_pre_insert_product_variation', function($product, $request, $creating){
    try {
        if (!$creating) return $product;
        if (!is_a($product, 'WC_Product_Variation')) return $product;

        $parent_id = $product->get_parent_id();
        if (!$parent_id) return $product;

        $type_key = get_post_meta($parent_id, '_mg_type_key', true);
        if (!$type_key) return $product;

        // try to get size & color from attributes
        $attrs = $product->get_attributes(); // array of name => value
        $size = ''; $color_slug = '';
        foreach ($attrs as $name => $value){
            $n = sanitize_title($name);
            if (strpos($n,'size') !== false || strpos($n,'meret') !== false) $size = is_string($value)? $value : '';
            if (strpos($n,'color') !== false || strpos($n,'szin') !== false) $color_slug = is_string($value)? $value : '';
        }
        if (!$size && isset($request['attributes']) && is_array($request['attributes'])) {
            foreach ($request['attributes'] as $a){
                $n = isset($a['name']) ? sanitize_title($a['name']) : '';
                $v = isset($a['option']) ? $a['option'] : '';
                if (strpos($n,'size') !== false || strpos($n,'meret') !== false) $size = $v;
                if (strpos($n,'color') !== false || strpos($n,'szin') !== false) $color_slug = $v;
            }
        }

        if ($size && $color_slug) {
            $price = mgsc_compute_variant_price($type_key, $size, $color_slug);
            if ($price !== null) {
                $product->set_regular_price( (string)$price );
            }
        }
    } catch (\Throwable $e) {}
    return $product;
}, 10, 3);
