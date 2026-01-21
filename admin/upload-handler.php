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

        $all = mg_get_catalog_products();
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
        $generation_context = array('design_path' => $design_path, 'trigger' => 'admin_upload');
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

        if ($parent_id > 0) {
            $result = $creator->add_type_to_existing_parent($parent_id, $selected, $images_by_type_color, $parent_name, $assign_cats, $defaults, $generation_context);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
        } else {
            $product_id = $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, $assign_cats, $defaults, $generation_context);
            if (is_wp_error($product_id)) throw new Exception($product_id->get_error_message());
        }
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status=success')); exit;
    } catch (Throwable $e) {
        $msg = rawurlencode($e->getMessage());
        wp_safe_redirect(admin_url('admin.php?page=mockup-generator&status=error&mg_error='.$msg)); exit;
    }
});
