<?php
if (!defined('ABSPATH')) exit;
if (!function_exists('mg_bulk_sanitize_size_list')) {
    function mg_bulk_sanitize_size_list($product) {
        $sizes = array();
        if (is_array($product)) {
            if (!empty($product['sizes']) && is_array($product['sizes'])) {
                foreach ($product['sizes'] as $size_value) {
                    if (!is_string($size_value)) { continue; }
                    $size_value = trim($size_value);
                    if ($size_value === '') { continue; }
                    $sizes[] = $size_value;
                }
            }
            if (!empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
                foreach ($product['size_color_matrix'] as $size_label => $colors) {
                    if (!is_string($size_label)) { continue; }
                    $size_label = trim($size_label);
                    if ($size_label === '') { continue; }
                    $sizes[] = $size_label;
                }
            }
        }
        return array_values(array_unique($sizes));
    }
}

if (!function_exists('mg_bulk_allowed_sizes_for_color')) {
    function mg_bulk_allowed_sizes_for_color($product, $color_slug) {
        $color_slug = sanitize_title($color_slug ?? '');
        $base_sizes = mg_bulk_sanitize_size_list($product);
        $matrix = array();
        $has_entries = false;
        if (is_array($product) && !empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
            foreach ($product['size_color_matrix'] as $size_label => $colors) {
                if (!is_string($size_label)) { continue; }
                $size_label = trim($size_label);
                if ($size_label === '') { continue; }
                $clean = array();
                if (is_array($colors)) {
                    foreach ($colors as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '') { continue; }
                        if (!in_array($slug, $clean, true)) { $clean[] = $slug; }
                    }
                }
                $matrix[$size_label] = $clean;
                $has_entries = true;
            }
        }
        if (!$has_entries) {
            return $base_sizes;
        }
        if ($color_slug === '') {
            return $base_sizes;
        }
        $allowed = array();
        foreach ($matrix as $size_label => $colors) {
            if (in_array($color_slug, $colors, true)) {
                $allowed[] = $size_label;
            }
        }
        return array_values(array_unique($allowed));
    }
}
add_action('wp_ajax_mg_bulk_process_one', function(){
    check_ajax_referer('mg_ajax_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'Jogosultság hiányzik.'), 403);

    if (empty($_FILES['design']['tmp_name'])) wp_send_json_error(array('message'=>'Hiányzó design fájl.'), 400);
    $uploaded = wp_handle_upload($_FILES['design'], ['test_form' => false]);
    if (!empty($uploaded['error'])) wp_send_json_error(array('message'=>$uploaded['error']), 400);
    $design_path = $uploaded['file'];

    $parent_name = sanitize_text_field($_POST['parent_name'] ?? basename($design_path));
    $keys = isset($_POST['product_keys']) ? (array) $_POST['product_keys'] : array();
    $keys = array_map('sanitize_text_field', $keys);
    if (empty($keys)) wp_send_json_error(array('message'=>'Nincs kiválasztott terméktípus.'), 400);

    require_once plugin_dir_path(__FILE__) . '../includes/class-generator.php';
    require_once plugin_dir_path(__FILE__) . '../includes/class-product-creator.php';

    $all = get_option('mg_products', array());
    $selected = array_values(array_filter($all, function($p) use ($keys){ return in_array($p['key'], $keys, true); }));
    if (empty($selected)) wp_send_json_error(array('message'=>'A kiválasztott terméktípusok nem találhatók.'), 400);

    try {
        $gen = new MG_Generator();
        $images_by_type_color = array();
        foreach ($selected as $prod) {
            $res = $gen->generate_for_product($prod['key'], $design_path);
            if (is_wp_error($res)) wp_send_json_error(array('message'=>$res->get_error_message()), 500);
            $images_by_type_color[$prod['key']] = $res;
        }

        $creator = new MG_Product_Creator();
        $defaults = array('type' => '', 'color' => '', 'size' => '');
        $primary_candidate = null;
        foreach ($selected as $prod) {
            if (!empty($prod['is_primary'])) { $primary_candidate = $prod; break; }
        }
        if (!$primary_candidate && !empty($selected)) { $primary_candidate = $selected[0]; }
        if ($primary_candidate) {
            $defaults['type'] = $primary_candidate['key'];
            $color_slugs = array();
            if (!empty($primary_candidate['colors']) && is_array($primary_candidate['colors'])) {
                foreach ($primary_candidate['colors'] as $c) { if (isset($c['slug'])) { $color_slugs[] = sanitize_title($c['slug']); } }
            }
            $resolved_color = '';
            if (!empty($primary_candidate['primary_color']) && in_array($primary_candidate['primary_color'], $color_slugs, true)) {
                $resolved_color = sanitize_title($primary_candidate['primary_color']);
            } elseif (!empty($color_slugs)) {
                $resolved_color = $color_slugs[0];
            }
            $allowed_sizes = mg_bulk_allowed_sizes_for_color($primary_candidate, $resolved_color);
            if ($resolved_color && empty($allowed_sizes) && !empty($color_slugs)) {
                foreach ($color_slugs as $candidate_color) {
                    $candidate_sizes = mg_bulk_allowed_sizes_for_color($primary_candidate, $candidate_color);
                    if (!empty($candidate_sizes)) {
                        $resolved_color = $candidate_color;
                        $allowed_sizes = $candidate_sizes;
                        break;
                    }
                }
            }
            if (empty($allowed_sizes)) {
                $allowed_sizes = mg_bulk_sanitize_size_list($primary_candidate);
            }
            $resolved_size = '';
            if (!empty($primary_candidate['primary_size']) && in_array($primary_candidate['primary_size'], $allowed_sizes, true)) {
                $resolved_size = $primary_candidate['primary_size'];
            } elseif (!empty($allowed_sizes)) {
                $resolved_size = $allowed_sizes[0];
            }
            $defaults['color'] = $resolved_color;
            $defaults['size'] = $resolved_size;
        }
        $product_id = $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, array(), $defaults);
        if (is_wp_error($product_id)) wp_send_json_error(array('message'=>$product_id->get_error_message()), 500);

        wp_send_json_success(array('product_id'=>$product_id, 'name'=>$parent_name));
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>$e->getMessage()), 500);
    }
});
