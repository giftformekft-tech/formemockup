<?php
if (!defined('ABSPATH')) exit;


class MG_Product_Creator {
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
        $sizes = array();
        if (!empty($candidate['sizes']) && is_array($candidate['sizes'])) {
            foreach ($candidate['sizes'] as $size_value) {
                if (!is_string($size_value)) { continue; }
                $size_value = trim($size_value);
                if ($size_value === '') { continue; }
                $sizes[] = $size_value;
            }
        }
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
        if ($requested_size && in_array($requested_size, $sizes, true)) {
            $resolved_size = $requested_size;
        } elseif (!empty($candidate['primary_size']) && in_array($candidate['primary_size'], $sizes, true)) {
            $resolved_size = $candidate['primary_size'];
        } elseif (!empty($sizes)) {
            $resolved_size = $sizes[0];
        }
        return array('type' => $resolved_type, 'color' => $resolved_color, 'size' => $resolved_size);
    }

    private function attributes_match_required($required, $candidate) {
        if (!is_array($required) || empty($required)) { return false; }
        if (!is_array($candidate) || empty($candidate)) { return false; }
        foreach ($required as $key => $value) {
            if ($value === '' || $value === null) { continue; }
            if (!array_key_exists($key, $candidate)) { return false; }
            if ($candidate[$key] !== $value) { return false; }
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
    private function attach_image($path) {
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 99);
        add_filter('big_image_size_threshold', '__return_false', 99);
        $filetype = wp_check_filetype(basename($path), null);
        if (empty($filetype['type']) && preg_match('/\.webp$/i', $path)) $filetype['type'] = 'image/webp';
        $wp_upload_dir = wp_upload_dir();
        $attachment = array('guid'=>$wp_upload_dir['url'].'/'.basename($path),'post_mime_type'=>$filetype['type'] ?? 'image/webp','post_title'=>preg_replace('/\.[^.]+$/','',basename($path)),'post_content'=>'','post_status'=>'inherit');
        $attach_id = wp_insert_attachment($attachment, $path);
        require_once(ABSPATH.'wp-admin/includes/image.php');
        $attach_data = ['file'=>_wp_relative_upload_path($path)];
        wp_update_attachment_metadata($attach_id, $attach_data);
        remove_filter('intermediate_image_sizes_advanced', '__return_empty_array', 99);
        remove_filter('big_image_size_threshold', '__return_false', 99);
        return $attach_id;
    }
    private function assign_categories($product_id, $cats = array()) {
        $ids = array();
        if (!empty($cats['main'])) $ids[] = (int)$cats['main'];
        // support single 'sub' or multiple 'subs'
        if (!empty($cats['sub']))  $ids[] = (int)$cats['sub'];
        if (!empty($cats['subs']) && is_array($cats['subs'])) foreach ($cats['subs'] as $sid) $ids[] = (int)$sid;
        $ids = array_values(array_unique(array_filter($ids)));
        if (!empty($ids)) {
            $existing = wp_get_object_terms($product_id, 'product_cat', array('fields'=>'ids'));
            if (!is_wp_error($existing)) $ids = array_values(array_unique(array_merge($existing, $ids)));
            wp_set_object_terms($product_id, $ids, 'product_cat', false);
        }
    }

    public function create_parent_with_type_color_size_webp_fast($parent_name, $selected_products, $images_by_type_color, $cats = array(), $defaults = array()) {
        $defaults = is_array($defaults) ? $defaults : array();
        $resolved_defaults = $this->resolve_default_combo($selected_products, $defaults['type'] ?? '', $defaults['color'] ?? '', $defaults['size'] ?? '');
        $default_type = $resolved_defaults['type'];
        $default_color = $resolved_defaults['color'];
        $default_size = $resolved_defaults['size'];

        $attr_type_id  = $this->ensure_attribute_taxonomy('Terméktípus','termektipus');
        $attr_color_id = $this->ensure_attribute_taxonomy('Szín','szin');
        $tax_type  = 'pa_termektipus'; $tax_color='pa_szin';
        $type_terms=array(); $color_terms=array(); $all_sizes=array();
        $price_map=array(); $size_surcharge_map=array(); $color_surcharge_map=array(); $sku_prefix_map=array();
        $tags_map = array();foreach ($selected_products as $p) {
            if (isset($p['size_surcharges']) && is_array($p['size_surcharges'])) {
                foreach ($p['size_surcharges'] as $sk => $sv) { $size_surcharge_map[$sk] = intval($sv); }
            }

            $tags_map[$p['key']] = isset($p['tags']) && is_array($p['tags']) ? $p['tags'] : array();
            $type_terms[] = array('slug'=>$p['key'], 'name'=>$p['label']);
            foreach ($p['colors'] as $c) $color_terms[$c['slug']] = $c['name'];
            $all_sizes = array_values(array_unique(array_merge($all_sizes, $p['sizes'])));
            $price_map[$p['key']] = intval($p['price'] ?? 0);
            $size_surcharge_map[$p['key']]  = is_array($p['size_surcharges'] ?? null) ? $p['size_surcharges'] : array();
            $color_surcharge_map[$p['key']] = is_array($p['color_surcharges'] ?? null) ? $p['color_surcharges'] : array();
            $sku_prefix_map[$p['key']] = strtoupper($p['sku_prefix'] ?? $p['key']);
        }
        $type_terms = array_values(array_unique($type_terms, SORT_REGULAR));
        $color_pairs = array(); foreach ($color_terms as $slug=>$name) $color_pairs[] = array('slug'=>$slug,'name'=>$name);
        $available_type_slugs = array_map(function($t){ return isset($t['slug']) ? $t['slug'] : ''; }, $type_terms);
        $available_color_slugs = array_map(function($pair){ return isset($pair['slug']) ? $pair['slug'] : ''; }, $color_pairs);
        $type_term_ids  = $this->ensure_terms_and_get_ids($tax_type,  $type_terms);
        $color_term_ids = $this->ensure_terms_and_get_ids($tax_color, $color_pairs);
        $image_ids=array(); $gallery=array();
        foreach ($images_by_type_color as $type_slug=>$bycolor) foreach ($bycolor as $color_slug=>$files) foreach ($files as $file) {
            $id=$this->attach_image($file); $image_ids[$type_slug][$color_slug][]=$id; $gallery[]=$id;
        }
        $product = new WC_Product_Variable();
        $product->set_name($parent_name);
        
        // Leírás beállítása (első talált type_description alapján)
        $desc = '';
        foreach ($selected_products as $p) {
            if (!empty($p['type_description'])) { $desc = wp_kses_post($p['type_description']); break; }
        }
        if ($desc) {
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
$parent_sku_base = strtoupper(sanitize_title($parent_name));
        $product->set_sku($parent_sku_base);
        if (!empty($gallery)) $product->set_image_id($gallery[0]);
        $attr_type = new WC_Product_Attribute(); if ($attr_type_id) $attr_type->set_id($attr_type_id);
        $attr_type->set_name($tax_type); $attr_type->set_options($type_term_ids); $attr_type->set_visible(true); $attr_type->set_variation(true);
        $attr_color = new WC_Product_Attribute(); if ($attr_color_id) $attr_color->set_id($attr_color_id);
        $attr_color->set_name($tax_color); $attr_color->set_options($color_term_ids); $attr_color->set_visible(true); $attr_color->set_variation(true);
        $attr_size = new WC_Product_Attribute(); $attr_size->set_name('Méret'); $attr_size->set_options($all_sizes); $attr_size->set_visible(true); $attr_size->set_variation(true);
        $product->set_attributes([$attr_type,$attr_color,$attr_size]);
        $parent_id=$product->save();
        $this->assign_categories($parent_id,$cats);
        if (isset($tags_map)) { $all_tags = array(); foreach ($selected_products as $p) if (!empty($tags_map[$p['key']])) $all_tags = array_merge($all_tags, $tags_map[$p['key']]); if (!empty($all_tags)) $this->assign_tags($parent_id, array_values(array_unique($all_tags))); }
        $created_variations = array();
        foreach ($selected_products as $p) {
            $type_slug=$p['key']; $valid_sizes=$p['sizes']; $colors=array_map(function($c){return $c['slug'];}, $p['colors']);
            $base_price=intval($price_map[$type_slug]??0); $size_map=$size_surcharge_map[$type_slug]??array(); $color_map_local=$color_surcharge_map[$type_slug]??array(); $prefix=$sku_prefix_map[$type_slug]??strtoupper($type_slug);
            foreach ($colors as $color_slug) {
                $imgs=$image_ids[$type_slug][$color_slug]??array(); $img_id=!empty($imgs)?$imgs[0]:0;
                foreach ($valid_sizes as $size) {
                    $price=max(0, $base_price+intval($size_map[$size]??0)+intval($color_map_local[$color_slug]??0));
                    $variation=new WC_Product_Variation(); $variation->set_parent_id($parent_id);
                    $variation->set_attributes(['pa_termektipus'=>$type_slug,'pa_szin'=>$color_slug,'méret'=>$size]);
                    if ($price>0) $variation->set_regular_price($price);
                    $variation->set_sku(strtoupper($parent_sku_base.'-'.$prefix.'-'.$color_slug.'-'.$size));
                    if ($img_id) $variation->set_image_id($img_id);
                    $variation->save();
                    $created_variations[] = array('pa_termektipus'=>$type_slug,'pa_szin'=>$color_slug,'méret'=>$size);
                }
            }
        }
        $needs_save = false;
        if (!empty($gallery)) {
            $product->set_gallery_image_ids(array_values(array_unique($gallery)));
            $needs_save = true;
        }
        $default_attrs = array();
        if ($default_type && in_array($default_type, $available_type_slugs, true)) {
            $default_attrs['pa_termektipus'] = $default_type;
        }
        if ($default_color && in_array($default_color, $available_color_slugs, true)) {
            $default_attrs['pa_szin'] = $default_color;
        }
        if ($default_size && in_array($default_size, $all_sizes, true)) {
            $default_attrs['méret'] = $default_size;
        }
        $applied_defaults = array();
        if (!empty($default_attrs)) {
            $default_attrs = array_filter($default_attrs, function($value){ return $value !== '' && $value !== null; });
            $matched = null;
            foreach ($created_variations as $attrs) {
                if ($this->attributes_match_required($default_attrs, $attrs)) { $matched = $attrs; break; }
            }
            if (!$matched && !empty($created_variations)) { $matched = $created_variations[0]; }
            if ($matched) {
                $default_attrs = array_merge($matched, $default_attrs);
                $existing_defaults = $product->get_default_attributes();
                if (!is_array($existing_defaults)) { $existing_defaults = array(); }
                $product->set_default_attributes(array_merge($existing_defaults, $default_attrs));
                $needs_save = true;
                $applied_defaults = $product->get_default_attributes();
            }
        }
        if ($needs_save) { $product->save(); $applied_defaults = $product->get_default_attributes(); }
        if (!empty($applied_defaults)) {
            update_post_meta($parent_id, '_default_attributes', $applied_defaults);
            if (function_exists('wc_delete_product_transients')) { wc_delete_product_transients($parent_id); }
            if (function_exists('wc_update_product_lookup_tables')) { wc_update_product_lookup_tables($parent_id); }
        }
        return $parent_id;
    }

    public function add_type_to_existing_parent($parent_id, $selected_products, $images_by_type_color, $fallback_parent_name='', $cats = array(), $defaults = array()) {
        $defaults = is_array($defaults) ? $defaults : array();
        $resolved_defaults = $this->resolve_default_combo($selected_products, $defaults['type'] ?? '', $defaults['color'] ?? '', $defaults['size'] ?? '');
        $default_type = $resolved_defaults['type'];
        $default_color = $resolved_defaults['color'];
        $default_size = $resolved_defaults['size'];

        $product = wc_get_product($parent_id);
        if (!$product || !$product->get_id()) return new WP_Error('parent_missing','A kiválasztott szülő termék nem található.');
        if (!$product->is_type('variable')) { $p = new WC_Product_Variable($parent_id); $parent_id = $p->save(); $product = wc_get_product($parent_id); }
        $attr_type_id = $this->ensure_attribute_taxonomy('Terméktípus','termektipus');
        $attr_color_id = $this->ensure_attribute_taxonomy('Szín','szin');
        $tax_type='pa_termektipus'; $tax_color='pa_szin';
        $type_terms=array(); $color_terms=array(); $all_sizes=array();
        $price_map=array(); $size_surcharge_map=array(); $color_surcharge_map=array(); $sku_prefix_map=array();
        foreach ($selected_products as $p) {
            $type_terms[] = array('slug'=>$p['key'], 'name'=>$p['label']);
            foreach ($p['colors'] as $c) $color_terms[$c['slug']]=$c['name'];
            $all_sizes = array_values(array_unique(array_merge($all_sizes, $p['sizes'])));
            $price_map[$p['key']] = intval($p['price'] ?? 0);
            $size_surcharge_map[$p['key']]  = is_array($p['size_surcharges'] ?? null) ? $p['size_surcharges'] : array();
            $color_surcharge_map[$p['key']] = is_array($p['color_surcharges'] ?? null) ? $p['color_surcharges'] : array();
            $sku_prefix_map[$p['key']] = strtoupper($p['sku_prefix'] ?? $p['key']);
        }
        $type_terms = array_values(array_unique($type_terms, SORT_REGULAR));
        $color_pairs=array(); foreach ($color_terms as $slug=>$name) $color_pairs[] = array('slug'=>$slug,'name'=>$name);
        $available_type_slugs = array_map(function($t){ return isset($t['slug']) ? $t['slug'] : ''; }, $type_terms);
        $available_color_slugs = array_map(function($pair){ return isset($pair['slug']) ? $pair['slug'] : ''; }, $color_pairs);
        $type_term_ids=$this->ensure_terms_and_get_ids($tax_type,$type_terms);
        $color_term_ids=$this->ensure_terms_and_get_ids($tax_color,$color_pairs);
        $attrs=$product->get_attributes();
        $attr_type = isset($attrs[$tax_type]) ? $attrs[$tax_type] : new WC_Product_Attribute();
        if ($attr_type_id) $attr_type->set_id($attr_type_id);
        $attr_type->set_name($tax_type);
        $attr_type->set_options(array_values(array_unique(array_merge($attr_type->get_options()?:array(), $type_term_ids))));
        $attr_type->set_visible(true); $attr_type->set_variation(true);
        $attr_color = isset($attrs[$tax_color]) ? $attrs[$tax_color] : new WC_Product_Attribute();
        if ($attr_color_id) $attr_color->set_id($attr_color_id);
        $attr_color->set_name($tax_color);
        $attr_color->set_options(array_values(array_unique(array_merge($attr_color->get_options()?:array(), $color_term_ids))));
        $attr_color->set_visible(true); $attr_color->set_variation(true);
        $attr_size = isset($attrs['Méret']) ? $attrs['Méret'] : new WC_Product_Attribute();
        $attr_size->set_name('Méret');
        $attr_size->set_options(array_values(array_unique(array_merge($attr_size->get_options()?:array(), $all_sizes))));
        $attr_size->set_visible(true); $attr_size->set_variation(true);
        $product->set_attributes([$attr_type,$attr_color,$attr_size]);
        if ($fallback_parent_name && !$product->get_name()) $product->set_name($fallback_parent_name);
        $product->save();
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

        $image_ids=array();
        foreach ($images_by_type_color as $type_slug=>$bycolor) foreach ($bycolor as $color_slug=>$files) foreach ($files as $file) {
            $id=$this->attach_image($file); $image_ids[$type_slug][$color_slug][]=$id;
        }
        $existing=array();
        foreach ($product->get_children() as $vid){
            $v=wc_get_product($vid); $atts=$v->get_attributes();
            $k = ($atts[$tax_type] ?? '').'|'.($atts[$tax_color] ?? '').'|'.($atts['méret'] ?? $atts['Méret'] ?? '');
            $existing[$k]=true;
        }
        $parent_sku_base=$product->get_sku(); if (!$parent_sku_base) $parent_sku_base=strtoupper(sanitize_title($product->get_name()));
        $created_variations = array();
        foreach ($selected_products as $p) {
            $type_slug=$p['key']; $valid_sizes=$p['sizes']; $colors=array_map(function($c){return $c['slug'];}, $p['colors']);
            $base_price=intval($price_map[$type_slug]??0); $size_map=$size_surcharge_map[$type_slug]??array(); $color_map_local=$color_surcharge_map[$type_slug]??array(); $prefix=$sku_prefix_map[$type_slug]??strtoupper($type_slug);
            foreach ($colors as $color_slug) { $imgs=$image_ids[$type_slug][$color_slug]??array(); $img_id=!empty($imgs)?$imgs[0]:0;
                foreach ($valid_sizes as $size) {
                    $key=$type_slug.'|'.$color_slug.'|'.$size; if (isset($existing[$key])) continue;
                    $price=max(0,$base_price+intval($size_map[$size]??0)+intval($color_map_local[$color_slug]??0));
                    $variation=new WC_Product_Variation();
                    $variation->set_parent_id($product->get_id());
                    $variation->set_attributes(['pa_termektipus'=>$type_slug,'pa_szin'=>$color_slug,'méret'=>$size]);
                    if ($price>0) $variation->set_regular_price($price);
                    $variation->set_sku(strtoupper($parent_sku_base.'-'.$prefix.'-'.$color_slug.'-'.$size));
                    if ($img_id) $variation->set_image_id($img_id);
                    $variation->save();
                    $created_variations[] = array('pa_termektipus'=>$type_slug,'pa_szin'=>$color_slug,'méret'=>$size);
                }
            }
        }
        $default_attrs = array();
        if ($default_type && in_array($default_type, $available_type_slugs, true)) {
            $default_attrs['pa_termektipus'] = $default_type;
        }
        if ($default_color && in_array($default_color, $available_color_slugs, true)) {
            $default_attrs['pa_szin'] = $default_color;
        }
        $existing_sizes = $attr_size->get_options();
        $existing_sizes = is_array($existing_sizes) ? array_values(array_unique(array_filter($existing_sizes))) : array();
        if ($default_size && in_array($default_size, $existing_sizes, true)) {
            $default_attrs['méret'] = $default_size;
        }
        $applied_defaults = array();
        if (!empty($default_attrs)) {
            $default_attrs = array_filter($default_attrs, function($value){ return $value !== '' && $value !== null; });
            $variation_candidates = array();
            foreach ($product->get_children() as $vid) {
                $v = wc_get_product($vid);
                if ($v && $v->get_id()) { $variation_candidates[] = $v->get_attributes(); }
            }
            foreach ($created_variations as $attrs) { $variation_candidates[] = $attrs; }
            $matched = null;
            foreach ($variation_candidates as $attrs) {
                if ($this->attributes_match_required($default_attrs, $attrs)) { $matched = $attrs; break; }
            }
            if (!$matched && !empty($variation_candidates)) { $matched = $variation_candidates[0]; }
            if ($matched) {
                $default_attrs = array_merge($matched, $default_attrs);
                $existing_defaults = $product->get_default_attributes();
                if (!is_array($existing_defaults)) { $existing_defaults = array(); }
                $product->set_default_attributes(array_merge($existing_defaults, $default_attrs));
                $product->save();
                $applied_defaults = $product->get_default_attributes();
            }
        }
        if (!empty($applied_defaults)) {
            update_post_meta($product->get_id(), '_default_attributes', $applied_defaults);
            if (function_exists('wc_delete_product_transients')) { wc_delete_product_transients($product->get_id()); }
            if (function_exists('wc_update_product_lookup_tables')) { wc_update_product_lookup_tables($product->get_id()); }
        }
        return $product->get_id();
    }
}
