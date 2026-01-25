<?php
if (!defined('ABSPATH')) exit;


class MG_Product_Creator {
    const BULK_SUFFIX = 'póló pulcsi';
    const SKU_PREFIX = 'FORME';
    const SKU_START = 10000;

    /**
     * Generate or retrieve product SKU
     * Auto-generates sequential SKU if product doesn't have one
     */
    public static function generate_product_sku($product_id, $product_name = '') {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return '';
        }

        // Check existing SKU
        $existing_sku = get_post_meta($product_id, '_sku', true);
        if ($existing_sku && trim($existing_sku) !== '') {
            return self::sanitize_sku($existing_sku);
        }

        // Generate new SKU
        $new_sku = self::generate_next_sku();
        
        // Save to product
        update_post_meta($product_id, '_sku', $new_sku);
        
        return $new_sku;
    }

    /**
     * Generate next sequential SKU
     */
    protected static function generate_next_sku() {
        $last_number = get_option('mg_last_sku_number', self::SKU_START - 1);
        $max_attempts = 100;
        $attempt = 0;

        do {
            $next_number = $last_number + 1 + $attempt;
            $new_sku = self::SKU_PREFIX . str_pad($next_number, 5, '0', STR_PAD_LEFT);
            $attempt++;
        } while (self::sku_exists($new_sku) && $attempt < $max_attempts);

        if ($attempt >= $max_attempts) {
            // Fallback: use timestamp suffix
            $new_sku = self::SKU_PREFIX . time();
        }

        // Store the successfully generated number
        update_option('mg_last_sku_number', $next_number, false);

        return $new_sku;
    }

    /**
     * Check if SKU already exists in database
     */
    protected static function sku_exists($sku) {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
            $sku
        ));
        return $count > 0;
    }

    /**
     * Sanitize SKU - only uppercase letters, numbers, hyphens, underscores
     */
    public static function sanitize_sku($sku) {
        $sku = strtoupper(trim($sku));
        $sku = preg_replace('/[^A-Z0-9\-_]/', '', $sku);
        return $sku;
    }

    public static function apply_bulk_suffix_slug($product_id, $base_name) {
        $product_id = intval($product_id);
        if ($product_id <= 0 || !is_string($base_name) || $base_name === '') {
            return;
        }
        $post = get_post($product_id);
        if (!$post) {
            return;
        }
        $slug_base = sanitize_title(trim($base_name) . ' ' . self::BULK_SUFFIX);
        if ($slug_base === '') {
            return;
        }
        $unique_slug = wp_unique_post_slug($slug_base, $post->ID, $post->post_status, $post->post_type, $post->post_parent);
        if ($unique_slug && $unique_slug !== $post->post_name) {
            wp_update_post(['ID' => $post->ID, 'post_name' => $unique_slug]);
        }
    }

    private function sanitize_size_list($product) {
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

    private function normalize_size_color_matrix($product) {
        $matrix = array();
        $has_entries = false;
        if (is_array($product) && !empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
            foreach ($product['size_color_matrix'] as $size_label => $colors) {
                if (!is_string($size_label)) { continue; }
                $size_label = trim($size_label);
                if ($size_label === '') { continue; }
                $clean_colors = array();
                if (is_array($colors)) {
                    foreach ($colors as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '') { continue; }
                        if (!in_array($slug, $clean_colors, true)) {
                            $clean_colors[] = $slug;
                        }
                    }
                }
                $matrix[$size_label] = $clean_colors;
                if (!empty($clean_colors) || array_key_exists($size_label, $product['size_color_matrix'])) {
                    $has_entries = true;
                }
            }
        }
        return array($matrix, $has_entries);
    }

    private function get_allowed_sizes_for_color($product, $color_slug) {
        $color_slug = sanitize_title($color_slug ?? '');
        $base_sizes = $this->sanitize_size_list($product);
        list($matrix, $has_entries) = $this->normalize_size_color_matrix($product);
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
        $allowed = array_values(array_unique($allowed));
        return $allowed;
    }

    private function resolve_default_combo($selected_products, $requested_type, $requested_color, $requested_size = '') {
        $requested_type = sanitize_title($requested_type ?? '');
        $requested_color = sanitize_title($requested_color ?? '');
        $requested_size = is_string($requested_size) ? sanitize_text_field($requested_size) : '';
        $candidate = null;
        if (is_array($selected_products)) {
            foreach ($selected_products as $prod) {
                if (!is_array($prod) || empty($prod['key'])) { continue; }
                $prod_key = sanitize_title($prod['key']);
                if ($requested_type && $prod_key === $requested_type) {
                    $candidate = $prod;
                    break;
                }
            }
            if (!$candidate) {
                foreach ($selected_products as $prod) {
                    if (!is_array($prod) || empty($prod['key'])) { continue; }
                    if (!empty($prod['is_primary'])) {
                        $candidate = $prod;
                        break;
                    }
                }
            }
            if (!$candidate && !empty($selected_products)) {
                foreach ($selected_products as $prod) {
                    if (is_array($prod) && !empty($prod['key'])) { $candidate = $prod; break; }
                }
            }
        }
        if (!$candidate) {
            return array('type' => '', 'color' => '', 'size' => '');
        }
        $resolved_type = sanitize_title($candidate['key']);
        $color_slugs = array();
        if (!empty($candidate['colors']) && is_array($candidate['colors'])) {
            foreach ($candidate['colors'] as $c) {
                if (is_array($c) && isset($c['slug'])) {
                    $color_slugs[] = sanitize_title($c['slug']);
                }
            }
        }
        $resolved_size = '';
        $sizes = $this->sanitize_size_list($candidate);
        $resolved_color = '';
        if ($requested_color && in_array($requested_color, $color_slugs, true)) {
            $resolved_color = $requested_color;
        } else {
            $preferred = isset($candidate['primary_color']) ? sanitize_title($candidate['primary_color']) : '';
            if ($preferred && in_array($preferred, $color_slugs, true)) {
                $resolved_color = $preferred;
            } elseif (!empty($color_slugs)) {
                $resolved_color = $color_slugs[0];
            }
        }
        $allowed_sizes = $this->get_allowed_sizes_for_color($candidate, $resolved_color);
        if ($resolved_color && empty($allowed_sizes) && !empty($color_slugs)) {
            foreach ($color_slugs as $slug_option) {
                $candidate_allowed = $this->get_allowed_sizes_for_color($candidate, $slug_option);
                if (!empty($candidate_allowed)) {
                    $resolved_color = $slug_option;
                    $allowed_sizes = $candidate_allowed;
                    break;
                }
            }
        }
        if (empty($allowed_sizes)) {
            $allowed_sizes = $sizes;
        }
        $primary_size = isset($candidate['primary_size']) ? $candidate['primary_size'] : '';
        if (!is_string($primary_size)) { $primary_size = ''; }
        if ($requested_size && in_array($requested_size, $allowed_sizes, true)) {
            $resolved_size = $requested_size;
        } elseif ($primary_size && in_array($primary_size, $allowed_sizes, true)) {
            $resolved_size = $primary_size;
        } elseif (!empty($allowed_sizes)) {
            $resolved_size = $allowed_sizes[0];
        }
        return array('type' => $resolved_type, 'color' => $resolved_color, 'size' => $resolved_size);
    }

    private function normalize_attributes($attributes) {
        $normalized = array();
        if (!is_array($attributes)) { return $normalized; }
        foreach ($attributes as $key => $value) {
            if ($value === '' || $value === null) { continue; }
            if (!is_string($key) || $key === '') { continue; }
            $normalized_key = $key;
            if (strpos($normalized_key, 'attribute_') === 0) {
                $normalized_key = substr($normalized_key, 10);
            }
            $lower_key = strtolower($normalized_key);
            if ($lower_key === 'méret' || $lower_key === 'meret') {
                $normalized_key = 'meret';
            } elseif ($lower_key === 'attribute_méret' || $lower_key === 'attribute_meret') {
                $normalized_key = 'meret';
            } elseif ($lower_key === 'pa_termektipus') {
                $normalized_key = 'pa_termektipus';
            } elseif ($lower_key === 'pa_szin') {
                $normalized_key = 'pa_szin';
            } elseif ($lower_key === 'pa_meret') {
                $normalized_key = 'meret';
            } elseif (strpos($lower_key, 'pa_') === 0) {
                $normalized_key = $lower_key;
            } else {
                $normalized_key = sanitize_title($normalized_key);
            }
            $normalized[$normalized_key] = $value;
        }
        return $normalized;
    }

    private function attributes_match_required($required, $candidate) {
        if (!is_array($required) || empty($required)) { return false; }
        if (!is_array($candidate) || empty($candidate)) { return false; }
        $required_normalized = $this->normalize_attributes($required);
        if (empty($required_normalized)) { return false; }
        $candidate_normalized = $this->normalize_attributes($candidate);
        if (empty($candidate_normalized)) { return false; }
        foreach ($required_normalized as $key => $value) {
            if ($value === '' || $value === null) { continue; }
            if (!array_key_exists($key, $candidate_normalized)) { return false; }
            if ((string)$candidate_normalized[$key] !== (string)$value) { return false; }
        }
        return true;
    }

    private function assign_tags($product_id, $tags = array()){
        if (empty($tags) || !is_array($tags)) return;
        $names = array();
        foreach ($tags as $t){ $name = trim(wp_strip_all_tags($t)); if ($name !== '') $names[] = $name; }
        if (empty($names)) return;
        wp_set_object_terms($product_id, $names, 'product_tag', true);
    }

    private function ensure_attribute_taxonomy($label, $name){
        if (!function_exists('wc_get_attribute_taxonomies')) return 0;
        $name = sanitize_title($name);
        $attr_id = 0; $exists=false;
        foreach (wc_get_attribute_taxonomies() as $tax) if ($tax->attribute_name === $name) { $exists=true; $attr_id=(int)$tax->attribute_id; break; }
        if (!$exists) {
            if (!function_exists('wc_create_attribute')) return 0;
            $attr_id = wc_create_attribute(array('slug'=>$name,'name'=>$label,'type'=>'select','order_by'=>'menu_order','has_archives'=>false,));
            delete_transient('wc_attribute_taxonomies'); wc_get_attribute_taxonomies();
            register_taxonomy('pa_'.$name, apply_filters('woocommerce_taxonomy_objects_'.$name, array('product')),
                apply_filters('woocommerce_taxonomy_args_'.$name, array('labels'=>array('name'=>$label),'hierarchical'=>false,'show_ui'=>false,'query_var'=>true,'rewrite'=>false)));
        }
        return (int)$attr_id;
    }
    private function ensure_terms_and_get_ids($taxonomy, $terms){
        $ids = array();
        foreach ($terms as $t) {
            $slug = sanitize_title($t['slug']); $name = $t['name'];
            if (!term_exists($slug, $taxonomy)) wp_insert_term($name, $taxonomy, array('slug'=>$slug));
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && !is_wp_error($term)) $ids[] = (int)$term->term_id;
        }
        return $ids;
    }
    private function resolve_category_labels($cats = array()) {
        $labels = array('parent' => '', 'child' => '');
        if (!is_array($cats)) {
            return $labels;
        }
        $parent_id = !empty($cats['main']) ? (int) $cats['main'] : 0;
        $child_id = 0;
        if (!empty($cats['sub'])) {
            $child_id = (int) $cats['sub'];
        } elseif (!empty($cats['subs']) && is_array($cats['subs'])) {
            $child_id = (int) $cats['subs'][0];
        }
        if ($parent_id > 0) {
            $term = get_term($parent_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $labels['parent'] = sanitize_text_field($term->name);
            }
        }
        if ($child_id > 0) {
            $term = get_term($child_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $labels['child'] = sanitize_text_field($term->name);
            }
        }
        return $labels;
    }

    private function compose_image_seo_text($product_name, $type_slug, $color_slug, $selected_products, $cats = array()) {
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);

        $type_label = '';
        $color_label = $color_slug;
        foreach ($selected_products as $product) {
            $slug = isset($product['key']) ? sanitize_title($product['key']) : '';
            if ($slug !== $type_slug) { continue; }
            if (!empty($product['label'])) {
                $type_label = sanitize_text_field($product['label']);
            } elseif (!empty($product['name'])) {
                $type_label = sanitize_text_field($product['name']);
            }
            if (!empty($product['colors']) && is_array($product['colors'])) {
                foreach ($product['colors'] as $color) {
                    $cslug = isset($color['slug']) ? sanitize_title($color['slug']) : '';
                    if ($cslug === $color_slug && !empty($color['name'])) {
                        $color_label = sanitize_text_field($color['name']);
                        break 2;
                    }
                }
            }
        }

        $category_labels = $this->resolve_category_labels($cats);

        $parts = array_filter([
            sanitize_text_field($product_name),
            $type_label,
            $color_label,
            $category_labels['parent'] ?? '',
            $category_labels['child'] ?? '',
        ], 'strlen');
        return implode(' - ', $parts);
    }

    private function attach_image($path, $seo_text = '') {
        $existing_id = $this->find_existing_attachment_id($path);
        if ($existing_id) {
            if ($seo_text !== '') {
                $title = sanitize_text_field($seo_text);
                wp_update_post([
                    'ID'         => $existing_id,
                    'post_title' => $title,
                ]);
                update_post_meta($existing_id, '_wp_attachment_image_alt', $title);
            }
            return $existing_id;
        }
        $filetype = wp_check_filetype(basename($path), null);
        if (empty($filetype['type']) && preg_match('/\.webp$/i', $path)) $filetype['type'] = 'image/webp';
        $wp_upload_dir = wp_upload_dir();
        $basedir = wp_normalize_path($wp_upload_dir['basedir'] ?? '');
        $relative = $basedir && strpos($path, $basedir) === 0 ? ltrim(substr($path, strlen($basedir)), '/') : basename($path);
        $guid = trailingslashit($wp_upload_dir['baseurl'] ?? $wp_upload_dir['url'] ?? '') . $relative;
        $title = $seo_text !== '' ? sanitize_text_field($seo_text) : preg_replace('/\.[^.]+$/','',basename($path));
        $attachment = array('guid'=>$guid,'post_mime_type'=>$filetype['type'] ?? 'image/webp','post_title'=>$title,'post_content'=>'','post_status'=>'inherit');
        $attach_id = wp_insert_attachment($attachment, $path);
        require_once(ABSPATH.'wp-admin/includes/image.php');
        $attach_data = ['file' => _wp_relative_upload_path($path)];
        wp_update_attachment_metadata($attach_id, $attach_data);
        if ($attach_id && $seo_text !== '') update_post_meta($attach_id, '_wp_attachment_image_alt', $title);
        return $attach_id;
    }

    private function log_error($message, $context = array()) {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        $logger = wc_get_logger();
        $logger->error($message, array_merge(array('source' => 'mg_product_creator'), $context));
    }

    private function maybe_set_default_featured_image($product_id, $resolved_defaults, $selected_products, $cats, $generation_context) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return;
        }
        if (!is_array($resolved_defaults)) {
            return;
        }
        $default_type = sanitize_title($resolved_defaults['type'] ?? '');
        $default_color = sanitize_title($resolved_defaults['color'] ?? '');
        if ($default_type === '' || $default_color === '') {
            return;
        }
        $generation_context = is_array($generation_context) ? $generation_context : array();
        $design_path = $generation_context['design_path'] ?? '';
        if ($design_path === '' || !file_exists($design_path)) {
            return;
        }
        $force_featured = !empty($generation_context['force_featured']);
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $current_featured_id = ($product && method_exists($product, 'get_image_id')) ? (int) $product->get_image_id() : 0;
        if ($current_featured_id > 0 && !$force_featured) {
            return;
        }
        if (!class_exists('MG_Generator')) {
            $gen_file = plugin_dir_path(__FILE__) . 'class-generator.php';
            if (file_exists($gen_file)) {
                require_once $gen_file;
            }
        }
        if (!class_exists('MG_Generator')) {
            return;
        }
        $render_version = apply_filters('mg_virtual_variant_render_version', 'v4', $product);
        $design_id = apply_filters('mg_virtual_variant_design_id', $product_id, $product);
        $generator = new MG_Generator();
        $result = $generator->generate_for_product($default_type, $design_path, array(
            'product_id' => $product_id,
            'design_id' => $design_id,
            'render_version' => $render_version,
            'render_scope' => 'woo_featured',
            'color_filter' => array($default_color),
        ));
        if (is_wp_error($result)) {
            $this->log_error('Woo featured render failed: ' . $result->get_error_message(), array(
                'product_id' => $product_id,
                'type' => $default_type,
                'color' => $default_color,
            ));
            return;
        }
        $files = isset($result[$default_color]) ? (array) $result[$default_color] : array();
        if (empty($files)) {
            $this->log_error('Woo featured render missing files.', array(
                'product_id' => $product_id,
                'type' => $default_type,
                'color' => $default_color,
            ));
            return;
        }
        $featured_file = $files[0] ?? '';
        if ($featured_file === '' || !file_exists($featured_file)) {
            $this->log_error('Woo featured render file not found.', array(
                'product_id' => $product_id,
                'type' => $default_type,
                'color' => $default_color,
            ));
            return;
        }
        $product_name = ($product && method_exists($product, 'get_name')) ? $product->get_name() : '';
        $seo_text = $this->compose_image_seo_text($product_name, $default_type, $default_color, $selected_products, $cats);
        $attachment_id = $this->attach_image($featured_file, $seo_text);
        if ($attachment_id) {
            set_post_thumbnail($product_id, $attachment_id);
        }
    }

    private function find_existing_attachment_id($path) {
        if (!function_exists('wp_upload_dir')) {
            return 0;
        }
        $path = wp_normalize_path($path);
        if ($path === '') {
            return 0;
        }
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir'] ?? '');
        if ($basedir === '' || strpos($path, $basedir) !== 0) {
            return 0;
        }
        $relative = ltrim(substr($path, strlen($basedir)), '/');
        if ($relative === '') {
            return 0;
        }
        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_wp_attached_file',
                    'value' => $relative,
                ],
            ],
        ]);
        return !empty($query->posts) ? (int) $query->posts[0] : 0;
    }
    private function assign_categories($product_id, $cats = array()) {
        $ids = array();
        if (!empty($cats['main'])) $ids[] = (int)$cats['main'];
        // support single 'sub' or multiple 'subs'
        if (!empty($cats['sub']))  $ids[] = (int)$cats['sub'];
        if (!empty($cats['subs']) && is_array($cats['subs'])) foreach ($cats['subs'] as $sid) $ids[] = (int)$sid;
        $ids = array_values(array_unique(array_filter($ids)));
        if (!empty($ids)) {
            wp_set_object_terms($product_id, $ids, 'product_cat', false);
        }
    }

    public function create_parent_with_type_color_size_webp_fast($parent_name, $selected_products, $images_by_type_color, $cats = array(), $defaults = array(), $generation_context = array()) {
        $defaults = is_array($defaults) ? $defaults : array();
        $generation_context = is_array($generation_context) ? $generation_context : array();
        $resolved_defaults = $this->resolve_default_combo($selected_products, $defaults['type'] ?? '', $defaults['color'] ?? '', $defaults['size'] ?? '');
        $type_terms=array(); $color_terms=array();
        $price_map=array(); $color_surcharge_map=array(); $sku_prefix_map=array();
        $tags_map = array();foreach ($selected_products as $p) {
            $tags_map[$p['key']] = isset($p['tags']) && is_array($p['tags']) ? $p['tags'] : array();
            $type_terms[] = array('slug'=>$p['key'], 'name'=>$p['label']);
            foreach ($p['colors'] as $c) $color_terms[$c['slug']] = $c['name'];
            $price_map[$p['key']] = intval($p['price'] ?? 0);
            $color_surcharge_map[$p['key']] = is_array($p['color_surcharges'] ?? null) ? $p['color_surcharges'] : array();
            $sku_prefix_map[$p['key']] = strtoupper($p['sku_prefix'] ?? $p['key']);
        }
        $type_terms = array_values(array_unique($type_terms, SORT_REGULAR));
        $color_pairs = array(); foreach ($color_terms as $slug=>$name) $color_pairs[] = array('slug'=>$slug,'name'=>$name);
        
        // Support using an existing product ID (created for SKU generation purposes)
        $existing_id = isset($generation_context['existing_product_id']) ? intval($generation_context['existing_product_id']) : 0;
        if ($existing_id > 0) {
            $product = wc_get_product($existing_id);
            if (!$product) {
                $product = new WC_Product_Simple();
                $product->set_name($parent_name);
            }
        } else {
            $product = new WC_Product_Simple();
            $product->set_name($parent_name);
        }
        
        // Leírás beállítása (első talált type_description alapján)
        $desc = '';
        foreach ($selected_products as $p) {
            if (!empty($p['type_description'])) { $desc = wp_kses_post($p['type_description']); break; }
        }
        if ($desc) {
            if (function_exists('mgtd__replace_placeholders')) {
                $category_ids = function_exists('mgtd__normalize_category_ids') ? mgtd__normalize_category_ids($cats) : array();
                $context = function_exists('mgtd__build_description_context')
                    ? mgtd__build_description_context(null, $category_ids, $parent_name)
                    : array('product_name' => sanitize_text_field($parent_name));
                $desc = mgtd__replace_placeholders($desc, $context);
            }
            if (method_exists($product, 'set_description')) {
                $product->set_description($desc);
            } else {
                $product->set_props(['description' => $desc]);
            }
            $short = wp_strip_all_tags(wp_trim_words($desc, 40, '…'));
            if (method_exists($product, 'set_short_description')) {
                $product->set_short_description($short);
            } else {
                $product->set_props(['short_description' => $short]);
            }
        }
        
        // Don't set manual SKU - will be auto-generated after save!
        // OLD CODE (REMOVED):
        // $parent_sku_base = strtoupper(sanitize_title($parent_name));
        // $product->set_sku($parent_sku_base);
        
        $price_candidates = array_values(array_filter(array_map('floatval', $price_map), function($value){ return $value >= 0; }));
        $min_price = !empty($price_candidates) ? min($price_candidates) : 0;
        if ($min_price > 0) {
            $product->set_regular_price((string) $min_price);
        }
        $parent_id=$product->save();
        
        // Generate SKU if not exists
        $sku = self::generate_product_sku($parent_id, $parent_name);
        if ($sku) {
            $product->set_sku($sku);
            $product->save();
        }
        
        // Save design file reference for download functionality
        if (!empty($generation_context['design_path'])) {
            $design_path = wp_normalize_path($generation_context['design_path']);
            if ($design_path !== '' && file_exists($design_path)) {
                update_post_meta($parent_id, '_mg_last_design_path', $design_path);
                
                // Also try to get attachment ID if exists
                $attachment_id = $this->find_existing_attachment_id($design_path);
                if ($attachment_id > 0) {
                    update_post_meta($parent_id, '_mg_last_design_attachment', $attachment_id);
                }
            }
        }
        
        $this->assign_categories($parent_id,$cats);
        if (isset($tags_map)) { $all_tags = array(); foreach ($selected_products as $p) if (!empty($tags_map[$p['key']])) $all_tags = array_merge($all_tags, $tags_map[$p['key']]); if (!empty($all_tags)) $this->assign_tags($parent_id, array_values(array_unique($all_tags))); }
        // REMOVED: MG_Mockup_Maintenance::register_generation(...)
        $this->maybe_set_default_featured_image($parent_id, $resolved_defaults, $selected_products, $cats, $generation_context);
        return $parent_id;
    }

    public function add_type_to_existing_parent($parent_id, $selected_products, $images_by_type_color, $fallback_parent_name='', $cats = array(), $defaults = array(), $generation_context = array()) {
        $defaults = is_array($defaults) ? $defaults : array();
        $generation_context = is_array($generation_context) ? $generation_context : array();
        $resolved_defaults = $this->resolve_default_combo($selected_products, $defaults['type'] ?? '', $defaults['color'] ?? '', $defaults['size'] ?? '');
        $product = wc_get_product($parent_id);
        if (!$product || !$product->get_id()) return new WP_Error('parent_missing','A kiválasztott szülő termék nem található.');
        if ($product->is_type('variable')) { return new WP_Error('parent_variable','A kiválasztott termék variálható; a virtuális modell egyszerű terméket vár.'); }
        $type_terms=array(); $color_terms=array();
        $price_map=array(); $color_surcharge_map=array(); $sku_prefix_map=array();
        foreach ($selected_products as $p) {
            $type_terms[] = array('slug'=>$p['key'], 'name'=>$p['label']);
            foreach ($p['colors'] as $c) $color_terms[$c['slug']]=$c['name'];
            $price_map[$p['key']] = intval($p['price'] ?? 0);
            $color_surcharge_map[$p['key']] = is_array($p['color_surcharges'] ?? null) ? $p['color_surcharges'] : array();
            $sku_prefix_map[$p['key']] = strtoupper($p['sku_prefix'] ?? $p['key']);
        }
        $type_terms = array_values(array_unique($type_terms, SORT_REGULAR));
        $color_pairs=array(); foreach ($color_terms as $slug=>$name) $color_pairs[] = array('slug'=>$slug,'name'=>$name);
        if ($fallback_parent_name && !$product->get_name()) $product->set_name($fallback_parent_name);
        $price_candidates = array_values(array_filter(array_map('floatval', $price_map), function($value){ return $value >= 0; }));
        $min_price = !empty($price_candidates) ? min($price_candidates) : 0;
        if ($min_price > 0 && !$product->get_regular_price()) {
            $product->set_regular_price((string) $min_price);
        }
    public function add_type_to_existing_parent($parent_id, $selected_products, $images_by_type_color, $fallback_parent_name='', $cats = array(), $defaults = array(), $generation_context = array()) {
        $defaults = is_array($defaults) ? $defaults : array();
        $generation_context = is_array($generation_context) ? $generation_context : array();
        $resolved_defaults = $this->resolve_default_combo($selected_products, $defaults['type'] ?? '', $defaults['color'] ?? '', $defaults['size'] ?? '');
        $product = wc_get_product($parent_id);
        if (!$product || !$product->get_id()) return new WP_Error('parent_missing','A kiválasztott szülő termék nem található.');
        if ($product->is_type('variable')) { return new WP_Error('parent_variable','A kiválasztott termék variálható; a virtuális modell egyszerű terméket vár.'); }
        $type_terms=array(); $color_terms=array();
        $price_map=array(); $color_surcharge_map=array(); $sku_prefix_map=array();
        foreach ($selected_products as $p) {
            $type_terms[] = array('slug'=>$p['key'], 'name'=>$p['label']);
            foreach ($p['colors'] as $c) $color_terms[$c['slug']]=$c['name'];
            $price_map[$p['key']] = intval($p['price'] ?? 0);
            $color_surcharge_map[$p['key']] = is_array($p['color_surcharges'] ?? null) ? $p['color_surcharges'] : array();
            $sku_prefix_map[$p['key']] = strtoupper($p['sku_prefix'] ?? $p['key']);
        }
        $type_terms = array_values(array_unique($type_terms, SORT_REGULAR));
        $color_pairs=array(); foreach ($color_terms as $slug=>$name) $color_pairs[] = array('slug'=>$slug,'name'=>$name);
        if ($fallback_parent_name && !$product->get_name()) $product->set_name($fallback_parent_name);
        $price_candidates = array_values(array_filter(array_map('floatval', $price_map), function($value){ return $value >= 0; }));
        $min_price = !empty($price_candidates) ? min($price_candidates) : 0;
        if ($min_price > 0 && !$product->get_regular_price()) {
            $product->set_regular_price((string) $min_price);
        }
        $product->save();
        
        // Save design file reference for download functionality
        if (!empty($generation_context['design_path'])) {
            $design_path = wp_normalize_path($generation_context['design_path']);
            if ($design_path !== '' && file_exists($design_path)) {
                update_post_meta($parent_id, '_mg_last_design_path', $design_path);
                
                // Also try to get attachment ID if exists
                $attachment_id = $this->find_existing_attachment_id($design_path);
                if ($attachment_id > 0) {
                    update_post_meta($parent_id, '_mg_last_design_attachment', $attachment_id);
                }
            }
        }
        
        // assign categories merge
        $this->assign_categories($product->get_id(), $cats);

        $tags_map = array();
        foreach ($selected_products as $p) {
            $tags_map[$p['key']] = is_array($p['tags'] ?? null) ? $p['tags'] : array();
        }
        $all_tags = array();
        foreach ($selected_products as $p) {
            if (!empty($tags_map[$p['key']])) {
                $all_tags = array_merge($all_tags, $tags_map[$p['key']]);
            }
        }
        if (!empty($all_tags)) {
            $this->assign_tags($product->get_id(), array_values(array_unique($all_tags)));
        }

        $parent_sku_base=$product->get_sku(); if (!$parent_sku_base) $parent_sku_base=strtoupper(sanitize_title($product->get_name()));
        $result_id = $product->get_id();
        // REMOVED: MG_Mockup_Maintenance::register_generation(...)
        $this->maybe_set_default_featured_image($result_id, $resolved_defaults, $selected_products, $cats, $generation_context);
        return $result_id;
    }
}
