<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('mgtd__get_type_desc')) {
    function mgtd__get_type_desc($type_key){
        $type_key = sanitize_title($type_key);
        $all = get_option('mg_products', array());
        if (!is_array($all)) return '';
        foreach ($all as $p){
            if (!is_array($p)) continue;
            $k = isset($p['key']) ? sanitize_title($p['key']) : '';
            if ($k === $type_key) {
                return isset($p['type_description']) ? wp_kses_post($p['type_description']) : '';
            }
        }
        return '';
    }
}

if (!function_exists('mgtd__make_excerpt')) {
    function mgtd__make_excerpt($html, $limit = 180){
        $txt = wp_strip_all_tags($html, true);
        $txt = trim(preg_replace('/\s+/', ' ', $txt));
        if (mb_strlen($txt) > $limit) {
            $txt = mb_substr($txt, 0, $limit - 1) . '…';
        }
        return $txt;
    }
}

add_filter('mg_parent_product_payload', function($payload, $type_key){
    try {
        $desc = mgtd__get_type_desc($type_key);
        if ($desc) {
            $payload['description'] = $desc;
            $payload['short_description'] = mgtd__make_excerpt($desc, 180);
        }
    } catch (\Throwable $e) {}
    return $payload;
}, 10, 2);
