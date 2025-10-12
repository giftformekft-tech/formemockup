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

if (!class_exists('MG_Custom_Fields_Manager')) {
    require_once plugin_dir_path(__FILE__) . '../includes/class-custom-fields-manager.php';
}
if (!class_exists('MG_Bulk_Queue')) {
    require_once plugin_dir_path(__FILE__) . '../includes/class-bulk-queue.php';
}

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
        $is_custom_product = !empty($_POST['custom_product']) && $_POST['custom_product'] === '1';

        // Load config for defaults
        $all = get_option('mg_products', array());
        $selected = array_values(array_filter($all, function($p) use ($keys){ return in_array($p['key'], $keys, true); }));
        if (empty($selected)) wp_send_json_error(array('message'=>'A kiválasztott terméktípusok nem találhatók.'), 400);

        $tags_raw = isset($_POST['tags']) ? (string)$_POST['tags'] : '';
        $tags = array_values(array_filter(array_unique(array_map('trim', explode(',', $tags_raw)))));

        $primary_type = sanitize_text_field($_POST['primary_type'] ?? '');
        $primary_color_input = sanitize_text_field($_POST['primary_color'] ?? '');
        $primary_size_input = sanitize_text_field($_POST['primary_size'] ?? '');
        $defaults = array('type' => '', 'color' => '', 'size' => '');
        if ($primary_type !== '') {
            foreach ($selected as $prod) {
                if ($prod['key'] === $primary_type) {
                    $defaults['type'] = $primary_type;
                    $color_slugs = array();
                    if (!empty($prod['colors']) && is_array($prod['colors'])) {
                        foreach ($prod['colors'] as $c) {
                            if (isset($c['slug'])) { $color_slugs[] = sanitize_title($c['slug']); }
                        }
                    }
                    $resolved_color = '';
                    if ($primary_color_input && in_array($primary_color_input, $color_slugs, true)) {
                        $resolved_color = $primary_color_input;
                    } elseif (!empty($prod['primary_color']) && in_array($prod['primary_color'], $color_slugs, true)) {
                        $resolved_color = sanitize_title($prod['primary_color']);
                    } elseif (!empty($color_slugs)) {
                        $resolved_color = $color_slugs[0];
                    }
                    $allowed_sizes = mg_bulk_allowed_sizes_for_color($prod, $resolved_color);
                    if ($resolved_color && empty($allowed_sizes) && !empty($color_slugs)) {
                        foreach ($color_slugs as $candidate_color) {
                            $candidate_sizes = mg_bulk_allowed_sizes_for_color($prod, $candidate_color);
                            if (!empty($candidate_sizes)) {
                                $resolved_color = $candidate_color;
                                $allowed_sizes = $candidate_sizes;
                                break;
                            }
                        }
                    }
                    if (empty($allowed_sizes)) {
                        $allowed_sizes = mg_bulk_sanitize_size_list($prod);
                    }
                    $resolved_size = '';
                    if ($primary_size_input && in_array($primary_size_input, $allowed_sizes, true)) {
                        $resolved_size = $primary_size_input;
                    } elseif (!empty($prod['primary_size']) && in_array($prod['primary_size'], $allowed_sizes, true)) {
                        $resolved_size = $prod['primary_size'];
                    } elseif (!empty($allowed_sizes)) {
                        $resolved_size = $allowed_sizes[0];
                    }
                    $defaults['color'] = $resolved_color;
                    $defaults['size'] = $resolved_size;
                    break;
                }
            }
        }

        $payload = array(
            'design_path' => $design_path,
            'product_keys' => $keys,
            'parent_id' => $parent_id,
            'parent_name' => $parent_name,
            'categories' => array('main' => $main_cat, 'subs' => $sub_cats),
            'defaults' => $defaults,
            'trigger' => 'ajax_bulk',
            'custom_product' => $is_custom_product ? 1 : 0,
            'tags' => $tags,
        );

        $job_id = MG_Bulk_Queue::enqueue($payload);
        wp_send_json_success(array('job_id' => $job_id));
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>$e->getMessage()), 500);
    }
});


add_action('wp_ajax_mg_bulk_queue_status', function(){
    try {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message'=>'Jogosultság hiányzik.'), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_bulk_nonce')) {
            wp_send_json_error(array('message'=>'Érvénytelen kérés (nonce).'), 401);
        }
        $job_ids = isset($_POST['job_ids']) ? (array)$_POST['job_ids'] : array();
        $job_ids = array_values(array_filter(array_map('sanitize_text_field', $job_ids)));
        $status = MG_Bulk_Queue::get_status($job_ids);
        wp_send_json_success($status);
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>$e->getMessage()), 500);
    }
});

if (!function_exists('mg_bulk_worker_gateway')) {
    function mg_bulk_worker_gateway() {
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        MG_Bulk_Queue::run_worker_request($token);
    }
}
add_action('wp_ajax_mg_bulk_worker', 'mg_bulk_worker_gateway');
add_action('wp_ajax_nopriv_mg_bulk_worker', 'mg_bulk_worker_gateway');


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
