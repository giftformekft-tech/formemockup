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
        $queue_path = plugin_dir_path(__FILE__) . '../includes/class-bulk-queue.php';
        if (!file_exists($queue_path)) throw new Exception('Hiányzó queue komponens.');
        require_once $queue_path;
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
        $job_ids = array();
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
            $cats = array('main'=> intval($main[$i] ?? 0), 'sub'=> intval($sub[$i] ?? 0));
            $sub_list = array();
            if (!empty($cats['sub'])) { $sub_list[] = $cats['sub']; }
            $payload = array(
                'design_path' => $design_path,
                'product_keys' => $keys,
                'parent_id' => 0,
                'parent_name' => $parent_name,
                'categories' => array('main' => $cats['main'], 'subs' => $sub_list),
                'defaults' => $defaults,
                'trigger' => 'multi_upload',
                'custom_product' => 0,
                'tags' => array(),
            );
            $job_id = MG_Bulk_Queue::enqueue($payload);
            if ($job_id) { $job_ids[] = $job_id; }
        }
        $status = 'queued';
        $extra = '';
        if (!empty($job_ids)) {
            $extra = '&jobs='.count($job_ids);
        }
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status='.$status.$extra)); exit;
    } catch (Throwable $e) {
        $msg = rawurlencode($e->getMessage());
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status=error&mg_error='.$msg)); exit;
    }
});