<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_mg_bulk_process', function(){
    try {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message'=>'Jogosultság hiányzik.'), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_bulk_nonce')) {
            wp_send_json_error(array('message'=>'Érvénytelen kérés (nonce).'), 401);
        }
        // Validate keys
        $keys = isset($_POST['product_keys']) ? array_map('sanitize_text_field', (array)$_POST['product_keys']) : array();
        if (empty($keys)) wp_send_json_error(array('message'=>'Nincs kiválasztott terméktípus.'), 400);

        // File
        if (!isset($_FILES['design_file']) || empty($_FILES['design_file']['tmp_name'])) {
            wp_send_json_error(array('message'=>'Hiányzó design fájl.'), 400);
        }
        $uploaded = wp_handle_upload($_FILES['design_file'], ['test_form' => false, 'mimes' => ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp']]);
        if (isset($uploaded['error'])) {
            wp_send_json_error(array('message'=>'Feltöltési hiba: '.$uploaded['error']), 400);
        }
        $design_path = $uploaded['file'];

        $parent_id = intval($_POST['parent_id'] ?? 0);
        $parent_name = sanitize_text_field($_POST['product_name'] ?? pathinfo($design_path, PATHINFO_FILENAME));
        $main_cat  = max(0, intval($_POST['main_cat'] ?? 0));
        $sub_cats  = isset($_POST['sub_cats']) ? array_map('intval', (array)$_POST['sub_cats']) : array();

        // Load config & engine
        $all = get_option('mg_products', array());
        $selected = array_values(array_filter($all, function($p) use ($keys){ return in_array($p['key'], $keys, true); }));
        if (empty($selected)) wp_send_json_error(array('message'=>'A kiválasztott terméktípusok nem találhatók.'), 400);

        $gen_path = plugin_dir_path(__FILE__) . '../includes/class-generator.php';
        $creator_path = plugin_dir_path(__FILE__) . '../includes/class-product-creator.php';
        if (!file_exists($gen_path) || !file_exists($creator_path)) {
            wp_send_json_error(array('message'=>'Hiányzó rendszerfájlok.'), 500);
        }
        require_once $gen_path; require_once $creator_path;

        $gen = new MG_Generator();
        $images_by_type_color = array();
        foreach ($selected as $prod) {
            $res = $gen->generate_for_product($prod['key'], $design_path);
            if (is_wp_error($res)) {
                wp_send_json_error(array('message'=>$res->get_error_message()), 500);
            }
            $images_by_type_color[$prod['key']] = $res;
        }

        $creator = new MG_Product_Creator();
        $cats = array('main'=>$main_cat, 'subs'=>$sub_cats);
        if ($parent_id > 0) {
            $result = $creator->add_type_to_existing_parent($parent_id, $selected, $images_by_type_color, $parent_name, $cats);
            if (is_wp_error($result)) wp_send_json_error(array('message'=>$result->get_error_message()), 500);
            wp_send_json_success(array('product_id'=>$parent_id));
        } else {
            $pid = $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, $cats);
            if (is_wp_error($pid)) wp_send_json_error(array('message'=>$pid->get_error_message()), 500);
            wp_send_json_success(array('product_id'=>$pid));
        }
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>$e->getMessage()), 500);
    }
});


// Extra: direct tag setter to ensure tags are applied
add_action('wp_ajax_mg_set_product_tags', function(){
    try {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message'=>'Jogosultság hiányzik.'), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_bulk_nonce')) {
            wp_send_json_error(array('message'=>'Érvénytelen kérés (nonce).'), 401);
        }
        $pid = intval($_POST['product_id'] ?? 0);
        if ($pid <= 0) wp_send_json_error(array('message'=>'Hiányzó product_id'), 400);
        $tags_raw = isset($_POST['tags']) ? (string) $_POST['tags'] : '';
        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', wp_strip_all_tags($tags_raw))))));
        if (!empty($tags)) { wp_set_object_terms($pid, $tags, 'product_tag', true); }
        wp_send_json_success(array('product_id'=>$pid, 'tags'=>$tags));
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>$e->getMessage()), 500);
    }
});
