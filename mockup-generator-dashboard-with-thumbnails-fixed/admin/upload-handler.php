<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_mg_upload_design_multi', function() {
    try {
        if (!current_user_can('edit_products')) { throw new Exception('Jogosultság hiányzik.'); }
        if (!isset($_POST['mg_nonce']) || !wp_verify_nonce($_POST['mg_nonce'], 'mg_upload_design')) {
            throw new Exception('Érvénytelen kérés (nonce).');
        }

        $parent_id = intval($_POST['parent_id'] ?? 0);
        $main_cat  = max(0, intval($_POST['main_cat'] ?? 0));
        $sub_cat   = max(0, intval($_POST['sub_cat'] ?? 0));

        $keys = isset($_POST['product_keys']) ? array_map('sanitize_text_field', (array)$_POST['product_keys']) : array();
        if (empty($keys)) throw new Exception('Nincs kiválasztott terméktípus.');

        if (!isset($_FILES['design_file']) || empty($_FILES['design_file']['tmp_name'])) {
            throw new Exception('Hiányzó design fájl.');
        }
        $uploaded = wp_handle_upload($_FILES['design_file'], ['test_form' => false, 'mimes' => ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp']]);
        if (isset($uploaded['error'])) throw new Exception('Feltöltési hiba: '.$uploaded['error']);
        $design_path = $uploaded['file'];

        // DEFAULT NAME: file name without extension (unless user typed one)
        $typed_name = isset($_POST['product_name']) ? trim(wp_unslash($_POST['product_name'])) : '';
        $default_from_file = pathinfo($design_path, PATHINFO_FILENAME);
        $parent_name = sanitize_text_field($typed_name !== '' ? $typed_name : $default_from_file);

        $all = get_option('mg_products', array());
        $selected = array_values(array_filter($all, function($p) use ($keys){ return in_array($p['key'], $keys, true); }));
        if (empty($selected)) throw new Exception('A kiválasztott terméktípusok nem találhatók.');

        $gen_path = plugin_dir_path(__FILE__) . '../includes/class-generator.php';
        $creator_path = plugin_dir_path(__FILE__) . '../includes/class-product-creator.php';
        if (!file_exists($gen_path) || !file_exists($creator_path)) throw new Exception('Hiányzó mag fájlok a pluginban.');
        require_once $gen_path;
        require_once $creator_path;

        $gen = new MG_Generator();
        $images_by_type_color = array();
        foreach ($selected as $prod) {
            $res = $gen->generate_for_product($prod['key'], $design_path);
            if (is_wp_error($res)) throw new Exception($res->get_error_message());
            $images_by_type_color[$prod['key']] = $res;
        }

        $creator = new MG_Product_Creator();
        $assign_cats = array('main'=>$main_cat, 'sub'=>$sub_cat);
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

        if ($parent_id > 0) {
            $result = $creator->add_type_to_existing_parent($parent_id, $selected, $images_by_type_color, $parent_name, $assign_cats, $defaults);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
        } else {
            $product_id = $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, $assign_cats, $defaults);
            if (is_wp_error($product_id)) throw new Exception($product_id->get_error_message());
        }
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status=success')); exit;
    } catch (Throwable $e) {
        $msg = rawurlencode($e->getMessage());
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status=error&mg_error='.$msg)); exit;
    }
});
