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

if (!function_exists('mg_bulk_resolve_defaults')) {
    function mg_bulk_resolve_defaults($selected, $primary_type, $primary_color_input, $primary_size_input) {
        $defaults = array('type' => '', 'color' => '', 'size' => '');
        if (empty($selected) || !is_array($selected)) {
            return $defaults;
        }
        if ($primary_type !== '') {
            foreach ($selected as $prod) {
                if (!is_array($prod) || empty($prod['key']) || $prod['key'] !== $primary_type) {
                    continue;
                }
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
        return $defaults;
    }
}

if (!class_exists('MG_Custom_Fields_Manager')) {
    require_once plugin_dir_path(__FILE__) . '../includes/class-custom-fields-manager.php';
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
        if (taxonomy_exists('product_cat')) {
            if ($main_cat > 0) {
                $main_term = get_term($main_cat, 'product_cat');
                if (!$main_term || is_wp_error($main_term)) {
                    $main_cat = 0;
                }
            }
            $valid_subs = array();
            foreach ($sub_cats as $sub_id) {
                if ($sub_id <= 0) {
                    continue;
                }
                $term = get_term($sub_id, 'product_cat');
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                if ($main_cat > 0 && intval($term->parent) !== $main_cat) {
                    continue;
                }
                $valid_subs[] = intval($term->term_id);
            }
            $sub_cats = array_values(array_unique($valid_subs));
        }

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

        $primary_type = sanitize_text_field($_POST['primary_type'] ?? '');
        $primary_color_input = sanitize_text_field($_POST['primary_color'] ?? '');
        $primary_size_input = sanitize_text_field($_POST['primary_size'] ?? '');
        $defaults = mg_bulk_resolve_defaults($selected, $primary_type, $primary_color_input, $primary_size_input);

        $creator = new MG_Product_Creator();
        $generation_context = array('design_path' => $design_path, 'trigger' => 'ajax_bulk');
        $cats = array('main'=>$main_cat, 'subs'=>$sub_cats);
        if ($parent_id > 0) {
            $result = $creator->add_type_to_existing_parent($parent_id, $selected, $images_by_type_color, $parent_name, $cats, $defaults, $generation_context);
            if (is_wp_error($result)) wp_send_json_error(array('message'=>$result->get_error_message()), 500);
            MG_Custom_Fields_Manager::set_custom_product($parent_id, $is_custom_product);
            wp_send_json_success(array('product_id'=>$parent_id));
        } else {
            $pid = $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, $cats, $defaults, $generation_context);
            if (is_wp_error($pid)) wp_send_json_error(array('message'=>$pid->get_error_message()), 500);
            MG_Product_Creator::apply_bulk_suffix_slug($pid, $parent_name);
            MG_Custom_Fields_Manager::set_custom_product($pid, $is_custom_product);
            wp_send_json_success(array('product_id'=>$pid));
        }
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>$e->getMessage()), 500);
    }
});

add_action('wp_ajax_mg_bulk_queue_enqueue', function(){
    try {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message'=>'Jogosultság hiányzik.'), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_bulk_nonce')) {
            wp_send_json_error(array('message'=>'Érvénytelen kérés (nonce).'), 401);
        }
        if (!class_exists('MG_Bulk_Queue')) {
            $queue_file = plugin_dir_path(__FILE__) . '../includes/class-bulk-queue.php';
            if (file_exists($queue_file)) {
                require_once $queue_file;
            }
        }
        if (!class_exists('MG_Bulk_Queue')) {
            wp_send_json_error(array('message' => 'A queue osztály nem érhető el.'), 500);
        }
        $keys = isset($_POST['product_keys']) ? array_map('sanitize_text_field', (array)$_POST['product_keys']) : array();
        if (empty($keys)) {
            wp_send_json_error(array('message'=>'Nincs kiválasztott terméktípus.'), 400);
        }
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
        if (taxonomy_exists('product_cat')) {
            if ($main_cat > 0) {
                $main_term = get_term($main_cat, 'product_cat');
                if (!$main_term || is_wp_error($main_term)) {
                    $main_cat = 0;
                }
            }
            $valid_subs = array();
            foreach ($sub_cats as $sub_id) {
                if ($sub_id <= 0) {
                    continue;
                }
                $term = get_term($sub_id, 'product_cat');
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                if ($main_cat > 0 && intval($term->parent) !== $main_cat) {
                    continue;
                }
                $valid_subs[] = intval($term->term_id);
            }
            $sub_cats = array_values(array_unique($valid_subs));
        }

        $all = get_option('mg_products', array());
        $selected = array_values(array_filter($all, function($p) use ($keys){ return in_array($p['key'], $keys, true); }));
        if (empty($selected)) {
            wp_send_json_error(array('message'=>'A kiválasztott terméktípusok nem találhatók.'), 400);
        }

        $primary_type = sanitize_text_field($_POST['primary_type'] ?? '');
        $primary_color_input = sanitize_text_field($_POST['primary_color'] ?? '');
        $primary_size_input = sanitize_text_field($_POST['primary_size'] ?? '');
        $defaults = mg_bulk_resolve_defaults($selected, $primary_type, $primary_color_input, $primary_size_input);

        $tags_raw = isset($_POST['tags']) ? (string) $_POST['tags'] : '';
        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', $tags_raw)))));
        $payload = array(
            'design_path' => $design_path,
            'product_keys' => $keys,
            'parent_id' => $parent_id,
            'parent_name' => $parent_name,
            'categories' => array('main' => $main_cat, 'subs' => $sub_cats),
            'defaults' => $defaults,
            'tags' => $tags,
            'custom_product' => $is_custom_product ? 1 : 0,
            'trigger' => 'bulk_queue',
        );

        $job_id = MG_Bulk_Queue::enqueue($payload, false);
        wp_send_json_success(array('job_id' => $job_id));
    } catch (Throwable $e) {
        wp_send_json_error(array('message' => $e->getMessage()), 500);
    }
});

add_action('wp_ajax_mg_bulk_queue_status', function(){
    try {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Jogosultság hiányzik.'), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_bulk_nonce')) {
            wp_send_json_error(array('message' => 'Érvénytelen kérés (nonce).'), 401);
        }
        if (!class_exists('MG_Bulk_Queue')) {
            $queue_file = plugin_dir_path(__FILE__) . '../includes/class-bulk-queue.php';
            if (file_exists($queue_file)) {
                require_once $queue_file;
            }
        }
        if (!class_exists('MG_Bulk_Queue')) {
            wp_send_json_error(array('message' => 'A queue osztály nem érhető el.'), 500);
        }
        $job_ids = isset($_POST['job_ids']) ? (array) $_POST['job_ids'] : array();
        $job_ids = array_values(array_filter(array_map('sanitize_text_field', $job_ids)));
        $status = MG_Bulk_Queue::get_status($job_ids);
        wp_send_json_success($status);
    } catch (Throwable $e) {
        wp_send_json_error(array('message' => $e->getMessage()), 500);
    }
});

add_action('wp_ajax_mg_bulk_queue_config', function(){
    try {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Jogosultság hiányzik.'), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_bulk_nonce')) {
            wp_send_json_error(array('message' => 'Érvénytelen kérés (nonce).'), 401);
        }
        if (!class_exists('MG_Bulk_Queue')) {
            $queue_file = plugin_dir_path(__FILE__) . '../includes/class-bulk-queue.php';
            if (file_exists($queue_file)) {
                require_once $queue_file;
            }
        }
        if (!class_exists('MG_Bulk_Queue')) {
            wp_send_json_error(array('message' => 'A queue osztály nem érhető el.'), 500);
        }
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 0;
        $interval = isset($_POST['interval_minutes']) ? intval($_POST['interval_minutes']) : 0;
        $updated_batch = MG_Bulk_Queue::set_batch_size($batch_size);
        $updated_interval = MG_Bulk_Queue::set_interval_minutes($interval);
        wp_send_json_success(array(
            'batch_size' => $updated_batch,
            'interval_minutes' => $updated_interval,
        ));
    } catch (Throwable $e) {
        wp_send_json_error(array('message' => $e->getMessage()), 500);
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

add_action('wp_ajax_mg_bulk_set_worker_count', function(){
    try {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Jogosultság hiányzik.'), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_bulk_nonce')) {
            wp_send_json_error(array('message' => 'Érvénytelen kérés (nonce).'), 401);
        }
        if (!class_exists('MG_Bulk_Queue')) {
            $queue_file = plugin_dir_path(__FILE__) . '../includes/class-bulk-queue.php';
            if (file_exists($queue_file)) {
                require_once $queue_file;
            }
        }
        if (!class_exists('MG_Bulk_Queue')) {
            wp_send_json_error(array('message' => 'A queue osztály nem érhető el.'), 500);
        }
        $count = isset($_POST['count']) ? intval($_POST['count']) : 0;
        $updated = MG_Bulk_Queue::update_worker_count($count);
        wp_send_json_success(array('count' => $updated));
    } catch (Throwable $e) {
        wp_send_json_error(array('message' => $e->getMessage()), 500);
    }
});
