<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('mgtd__get_type_desc')) {
    function mgtd__get_type_desc($type_key){
        $type_key = sanitize_title($type_key);
        $all = get_option('mg_products', array());
        if (!is_array($all)) return '';
        foreach ($all as $p){
            if (!is_array($p)) continue;
            $k = isset($p['key']) ? sanitize_title($p['key']) : '';
            if ($k === $type_key) {
                return isset($p['type_description']) ? wp_kses_post($p['type_description']) : '';
            }
        }
        return '';
    }
}

if (!function_exists('mgtd__get_category_seo_description')) {
    function mgtd__get_category_seo_description($term_id) {
        $term_id = (int) $term_id;
        if ($term_id <= 0) {
            return '';
        }
        $value = get_term_meta($term_id, 'mg_category_seo_description', true);
        return is_string($value) ? wp_kses_post($value) : '';
    }
}

if (!function_exists('mgtd__render_category_seo_field')) {
    function mgtd__render_category_seo_field($term = null) {
        $value = '';
        if ($term && isset($term->term_id)) {
            $value = mgtd__get_category_seo_description($term->term_id);
        }
        $label = __('SEO leírás', 'mockup-generator');
        $description = __('Ezt a leírást a {category_seo} vagy {category_seo:slug} változóval illesztheted be.', 'mockup-generator');
        if ($term) {
            echo '<tr class="form-field">';
            echo '<th scope="row"><label for="mg-category-seo-description">' . esc_html($label) . '</label></th>';
            echo '<td><textarea name="mg_category_seo_description" id="mg-category-seo-description" rows="5" class="large-text">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">' . esc_html($description) . '</p></td>';
            echo '</tr>';
        } else {
            echo '<div class="form-field">';
            echo '<label for="mg-category-seo-description">' . esc_html($label) . '</label>';
            echo '<textarea name="mg_category_seo_description" id="mg-category-seo-description" rows="5" class="large-text"></textarea>';
            echo '<p class="description">' . esc_html($description) . '</p>';
            echo '</div>';
        }
    }
}

if (!function_exists('mgtd__save_category_seo_description')) {
    function mgtd__save_category_seo_description($term_id) {
        if (!current_user_can('edit_term', $term_id)) {
            return;
        }
        if (!isset($_POST['mg_category_seo_description'])) {
            return;
        }
        $value = wp_kses_post(wp_unslash($_POST['mg_category_seo_description']));
        if ($value === '') {
            delete_term_meta($term_id, 'mg_category_seo_description');
            return;
        }
        update_term_meta($term_id, 'mg_category_seo_description', $value);
    }
}

add_action('product_cat_add_form_fields', function() {
    mgtd__render_category_seo_field();
});

add_action('product_cat_edit_form_fields', function($term) {
    mgtd__render_category_seo_field($term);
});

add_action('created_product_cat', 'mgtd__save_category_seo_description');
add_action('edited_product_cat', 'mgtd__save_category_seo_description');

if (!function_exists('mgtd__parse_description_variables_input')) {
    function mgtd__parse_description_variables_input($input) {
        $vars = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $input);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array();
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line, 2);
            } elseif (strpos($line, ':') !== false) {
                $parts = explode(':', $line, 2);
            }
            if (count($parts) < 2) {
                continue;
            }
            $slug = sanitize_title($parts[0]);
            $text = trim($parts[1]);
            if ($slug === '' || $text === '') {
                continue;
            }
            $vars[$slug] = wp_kses_post($text);
        }
        return $vars;
    }
}

if (!function_exists('mgtd__get_description_variables')) {
    function mgtd__get_description_variables() {
        $stored = get_option('mg_description_variables', array());
        if (is_string($stored)) {
            $stored = mgtd__parse_description_variables_input($stored);
        }
        if (!is_array($stored)) {
            return array();
        }
        $clean = array();
        foreach ($stored as $key => $value) {
            $slug = sanitize_title($key);
            $text = is_string($value) ? trim($value) : '';
            if ($slug === '' || $text === '') {
                continue;
            }
            $clean[$slug] = wp_kses_post($text);
        }
        return $clean;
    }
}

if (!function_exists('mgtd__normalize_category_ids')) {
    function mgtd__normalize_category_ids($cats) {
        $ids = array();
        if (!is_array($cats)) {
            return $ids;
        }
        if (!empty($cats['main'])) {
            $ids[] = (int) $cats['main'];
        }
        if (!empty($cats['sub'])) {
            $ids[] = (int) $cats['sub'];
        }
        if (!empty($cats['subs']) && is_array($cats['subs'])) {
            foreach ($cats['subs'] as $sid) {
                $ids[] = (int) $sid;
            }
        }
        if (!empty($cats['categories']) && is_array($cats['categories'])) {
            foreach ($cats['categories'] as $sid) {
                $ids[] = (int) $sid;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        return $ids;
    }
}

if (!function_exists('mgtd__build_description_context')) {
    function mgtd__build_description_context($product = null, $category_ids = array(), $product_name = '') {
        $context = array(
            'product_name' => '',
            'product_category' => '',
            'product_categories' => '',
            'category_seo' => '',
            'category_seos' => '',
            'category_seo_map' => array(),
        );

        if ($product_name !== '') {
            $context['product_name'] = sanitize_text_field($product_name);
        }

        if (is_object($product) && method_exists($product, 'get_name')) {
            $context['product_name'] = sanitize_text_field($product->get_name());
            if (method_exists($product, 'get_category_ids')) {
                $category_ids = $product->get_category_ids();
            }
        }

        $names = array();
        $seo_descriptions = array();
        $seo_map = array();
        $parent_seo = '';
        $child_seo = '';
        if (!empty($category_ids) && is_array($category_ids)) {
            foreach ($category_ids as $term_id) {
                $term_id = (int) $term_id;
                if ($term_id <= 0) {
                    continue;
                }
                $term = get_term($term_id, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $names[] = sanitize_text_field($term->name);
                    $seo_desc = mgtd__get_category_seo_description($term_id);
                    if ($seo_desc !== '') {
                        $seo_descriptions[] = $seo_desc;
                        if (empty($term->parent) && $parent_seo === '') {
                            $parent_seo = $seo_desc;
                        } elseif (!empty($term->parent) && $child_seo === '') {
                            $child_seo = $seo_desc;
                        }
                        if (isset($term->slug)) {
                            $seo_map[sanitize_title($term->slug)] = $seo_desc;
                        }
                    }
                }
            }
        }
        if (!empty($names)) {
            $names = array_values(array_unique(array_filter($names, 'strlen')));
            $context['product_categories'] = implode(', ', $names);
            $context['product_category'] = $names[0] ?? '';
        }
        if (!empty($seo_descriptions)) {
            if ($parent_seo !== '' && $child_seo !== '') {
                $context['category_seo'] = $parent_seo . ', ' . $child_seo;
            } elseif ($parent_seo !== '') {
                $context['category_seo'] = $parent_seo;
            } elseif ($child_seo !== '') {
                $context['category_seo'] = $child_seo;
            } else {
                $context['category_seo'] = $seo_descriptions[0] ?? '';
            }
            $context['category_seos'] = implode("\n", $seo_descriptions);
        }
        if (!empty($seo_map)) {
            $context['category_seo_map'] = $seo_map;
        }

        return $context;
    }
}

if (!function_exists('mgtd__replace_placeholders')) {
    function mgtd__replace_placeholders($content, $context = array()) {
        if (!is_string($content) || $content === '') {
            return $content;
        }
        $context = is_array($context) ? $context : array();
        $replacements = array(
            '{product_name}' => $context['product_name'] ?? '',
            '{product_category}' => $context['product_category'] ?? '',
            '{product_categories}' => $context['product_categories'] ?? '',
            '{category_seo}' => $context['category_seo'] ?? '',
            '{category_seos}' => $context['category_seos'] ?? '',
        );
        $content = strtr($content, $replacements);

        $variables = mgtd__get_description_variables();
        if (!empty($variables)) {
            $content = preg_replace_callback('/\{seo:([a-z0-9_-]+)\}/i', function($matches) use ($variables) {
                $slug = sanitize_title($matches[1]);
                return $variables[$slug] ?? '';
            }, $content);
        }

        if (!empty($context['category_seo_map']) && is_array($context['category_seo_map'])) {
            $seo_map = $context['category_seo_map'];
            $content = preg_replace_callback('/\{category_seo:([a-z0-9_-]+)\}/i', function($matches) use ($seo_map) {
                $slug = sanitize_title($matches[1]);
                return $seo_map[$slug] ?? '';
            }, $content);
        }

        return $content;
    }
}

if (!function_exists('mgtd__make_excerpt')) {
    function mgtd__make_excerpt($html, $limit = 180){
        $txt = wp_strip_all_tags($html, true);
        $txt = trim(preg_replace('/\s+/', ' ', $txt));
        if (mb_strlen($txt) > $limit) {
            $txt = mb_substr($txt, 0, $limit - 1) . '…';
        }
        return $txt;
    }
}

add_filter('mg_parent_product_payload', function($payload, $type_key){
    try {
        $desc = mgtd__get_type_desc($type_key);
        if ($desc) {
            $desc = mgtd__replace_placeholders($desc);
            $payload['description'] = $desc;
            $payload['short_description'] = mgtd__make_excerpt($desc, 180);
        }
    } catch (\Throwable $e) {}
    return $payload;
}, 10, 2);

add_filter('mg_variant_display_type_description', function($description, $type_slug, $product){
    $context = mgtd__build_description_context($product);
    return mgtd__replace_placeholders($description, $context);
}, 10, 3);
