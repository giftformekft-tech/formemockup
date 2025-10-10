<?php
if (!defined('ABSPATH')) exit;
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
            $size_values = array();
            if (!empty($primary_candidate['colors']) && is_array($primary_candidate['colors'])) {
                foreach ($primary_candidate['colors'] as $c) { if (isset($c['slug'])) { $color_slugs[] = $c['slug']; } }
            }
            if (!empty($primary_candidate['sizes']) && is_array($primary_candidate['sizes'])) {
                foreach ($primary_candidate['sizes'] as $size_value) {
                    $size_value = is_string($size_value) ? trim($size_value) : '';
                    if ($size_value !== '') { $size_values[] = $size_value; }
                }
            }
            if (!empty($primary_candidate['primary_color']) && in_array($primary_candidate['primary_color'], $color_slugs, true)) {
                $defaults['color'] = $primary_candidate['primary_color'];
            } elseif (!empty($color_slugs)) {
                $defaults['color'] = $color_slugs[0];
            }
            if (!empty($primary_candidate['primary_size']) && in_array($primary_candidate['primary_size'], $size_values, true)) {
                $defaults['size'] = $primary_candidate['primary_size'];
            } elseif (!empty($size_values)) {
                $defaults['size'] = $size_values[0];
            }
        }
        $product_id = $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, array(), $defaults);
        if (is_wp_error($product_id)) wp_send_json_error(array('message'=>$product_id->get_error_message()), 500);

        wp_send_json_success(array('product_id'=>$product_id, 'name'=>$parent_name));
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>$e->getMessage()), 500);
    }
});
