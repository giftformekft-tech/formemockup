<?php
if (!defined('ABSPATH')) exit;
add_action('admin_post_mg_upload_design_bulk', function(){
    try {
        if (!current_user_can('edit_products')) { throw new Exception('Jogosultság hiányzik.'); }
        if (!isset($_POST['mg_bulk_nonce']) || !wp_verify_nonce($_POST['mg_bulk_nonce'], 'mg_upload_design_bulk')) {
            throw new Exception('Érvénytelen kérés (nonce).');
        }
        $keys = isset($_POST['product_keys']) ? array_map('sanitize_text_field', (array)$_POST['product_keys']) : array();
        if (empty($keys)) throw new Exception('Nincs kiválasztott terméktípus.');
        $names = isset($_POST['product_name']) ? array_map('sanitize_text_field', (array)$_POST['product_name']) : array();
        $main  = isset($_POST['main_cat']) ? array_map('intval', (array)$_POST['main_cat']) : array();
        $sub   = isset($_POST['sub_cat']) ? array_map('intval', (array)$_POST['sub_cat']) : array();
        if (!isset($_FILES['design_files'])) throw new Exception('Nem érkeztek mintafájlok.');
        $files = $_FILES['design_files'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        if ($count < 1) throw new Exception('Üres fájllista.');
        $all = get_option('mg_products', array());
        $selected = array_values(array_filter($all, function($p) use ($keys){ return in_array($p['key'], $keys, true); }));
        if (empty($selected)) throw new Exception('A kiválasztott terméktípusok nem találhatók.');
        $gen_path = plugin_dir_path(__FILE__) . '../includes/class-generator.php';
        $creator_path = plugin_dir_path(__FILE__) . '../includes/class-product-creator.php';
        if (!file_exists($gen_path) || !file_exists($creator_path)) throw new Exception('Hiányzó rendszerfájlok.');
        require_once $gen_path; require_once $creator_path;
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
                foreach ($primary_candidate['colors'] as $c) { if (isset($c['slug'])) { $color_slugs[] = $c['slug']; } }
            }
            if (!empty($primary_candidate['primary_color']) && in_array($primary_candidate['primary_color'], $color_slugs, true)) {
                $defaults['color'] = $primary_candidate['primary_color'];
            } elseif (!empty($color_slugs)) {
                $defaults['color'] = $color_slugs[0];
            }
        }
        for ($i=0; $i < $count; $i++) {
            if (!isset($files['tmp_name'][$i]) || empty($files['tmp_name'][$i])) continue;
            if (!empty($files['error'][$i])) continue;
            $single = array('name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]);
            $uploaded = wp_handle_upload($single, ['test_form' => false, 'mimes' => ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp']]);
            if (isset($uploaded['error'])) continue;
            $design_path = $uploaded['file'];
            $typed = isset($names[$i]) ? trim($names[$i]) : '';
            if ($typed === '') $typed = pathinfo($design_path, PATHINFO_FILENAME);
            $parent_name = sanitize_text_field($typed);
            $gen = new MG_Generator();
            $images_by_type_color = array();
            foreach ($selected as $prod) {
                $res = $gen->generate_for_product($prod['key'], $design_path);
                if (is_wp_error($res)) { $images_by_type_color = array(); break; }
                $images_by_type_color[$prod['key']] = $res;
            }
            if (empty($images_by_type_color)) continue;
            $cats = array('main'=> intval($main[$i] ?? 0), 'sub'=> intval($sub[$i] ?? 0));
            $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, $cats, $defaults);
        }
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status=success')); exit;
    } catch (Throwable $e) {
        $msg = rawurlencode($e->getMessage());
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status=error&mg_error='.$msg)); exit;
    }
});