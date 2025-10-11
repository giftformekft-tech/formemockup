<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Variant_Maintenance {
    public static function init() {
        add_filter('pre_update_option_mg_products', [__CLASS__, 'handle_catalog_update'], 20, 2);
    }

    public static function handle_catalog_update($new_value, $old_value) {
        if (!class_exists('MG_Mockup_Maintenance') || !function_exists('wc_get_product')) {
            return $new_value;
        }

        $new_types = self::normalize_catalog($new_value);
        $old_types = self::normalize_catalog($old_value);

        foreach ($new_types as $type_slug => $type_data) {
            $old_data = isset($old_types[$type_slug]) ? $old_types[$type_slug] : ['colors' => [], 'sizes' => [], 'matrix' => []];
            $added_colors = array_diff(array_keys($type_data['colors']), array_keys($old_data['colors']));
            $added_sizes = array_diff($type_data['sizes'], $old_data['sizes']);
            $allowed_additions = self::detect_allowed_size_additions($type_data, $old_data);

            if (empty($added_colors) && empty($added_sizes) && empty($allowed_additions)) {
                continue;
            }

            self::synchronize_products_for_type($type_slug, $type_data, $added_colors, $allowed_additions);
        }

        return $new_value;
    }

    private static function normalize_catalog($raw) {
        $result = [];
        if (!is_array($raw)) {
            return $result;
        }
        foreach ($raw as $type) {
            if (!is_array($type) || empty($type['key'])) {
                continue;
            }
            $slug = sanitize_title($type['key']);
            if ($slug === '') {
                continue;
            }
            $result[$slug] = [
                'colors' => self::normalize_colors($type),
                'sizes'  => self::normalize_sizes($type),
                'matrix' => self::normalize_matrix($type),
            ];
        }
        return $result;
    }

    private static function normalize_colors($type) {
        $colors = [];
        if (!is_array($type) || empty($type['colors']) || !is_array($type['colors'])) {
            return $colors;
        }
        foreach ($type['colors'] as $color) {
            if (!is_array($color)) {
                continue;
            }
            $slug = isset($color['slug']) ? sanitize_title($color['slug']) : '';
            if ($slug === '') {
                continue;
            }
            $label = isset($color['name']) ? sanitize_text_field($color['name']) : $slug;
            $colors[$slug] = [
                'slug'  => $slug,
                'label' => $label,
            ];
        }
        return $colors;
    }

    private static function normalize_sizes($type) {
        $sizes = [];
        if (!is_array($type) || empty($type['sizes']) || !is_array($type['sizes'])) {
            return $sizes;
        }
        foreach ($type['sizes'] as $size) {
            if (!is_string($size)) {
                continue;
            }
            $size = sanitize_text_field($size);
            if ($size === '') {
                continue;
            }
            if (!in_array($size, $sizes, true)) {
                $sizes[] = $size;
            }
        }
        return $sizes;
    }

    private static function normalize_matrix($type) {
        $matrix = [];
        if (!is_array($type) || empty($type['size_color_matrix']) || !is_array($type['size_color_matrix'])) {
            return $matrix;
        }
        foreach ($type['size_color_matrix'] as $size_label => $colors) {
            if (!is_string($size_label)) {
                continue;
            }
            $size_label = sanitize_text_field($size_label);
            if ($size_label === '') {
                continue;
            }
            if (!is_array($colors)) {
                continue;
            }
            $normalized_colors = [];
            foreach ($colors as $color_slug) {
                $color_slug = sanitize_title($color_slug);
                if ($color_slug === '') {
                    continue;
                }
                if (!in_array($color_slug, $normalized_colors, true)) {
                    $normalized_colors[] = $color_slug;
                }
            }
            $matrix[$size_label] = $normalized_colors;
        }
        return $matrix;
    }

    private static function detect_allowed_size_additions($new_data, $old_data) {
        $additions = [];
        $color_slugs = array_unique(array_merge(array_keys($new_data['colors']), array_keys($old_data['colors'])));
        foreach ($color_slugs as $color_slug) {
            $new_allowed = self::allowed_sizes_from_struct($new_data, $color_slug);
            $old_allowed = self::allowed_sizes_from_struct($old_data, $color_slug);
            $diff = array_diff($new_allowed, $old_allowed);
            if (!empty($diff)) {
                foreach ($diff as $size) {
                    if ($size === '') {
                        continue;
                    }
                    if (!isset($additions[$color_slug])) {
                        $additions[$color_slug] = [];
                    }
                    $additions[$color_slug][$size] = true;
                }
            }
        }
        return $additions;
    }

    private static function allowed_sizes_from_struct($data, $color_slug) {
        $color_slug = sanitize_title($color_slug);
        if ($color_slug === '') {
            return [];
        }
        $sizes = isset($data['sizes']) && is_array($data['sizes']) ? $data['sizes'] : [];
        $matrix = isset($data['matrix']) && is_array($data['matrix']) ? $data['matrix'] : [];
        if (empty($matrix)) {
            return $sizes;
        }
        $allowed = [];
        foreach ($matrix as $size_label => $colors) {
            if (!is_array($colors)) {
                continue;
            }
            if (in_array($color_slug, $colors, true)) {
                $allowed[] = $size_label;
            }
        }
        return array_values(array_unique($allowed));
    }

    private static function synchronize_products_for_type($type_slug, $type_data, $added_colors, $allowed_additions) {
        $product_ids = self::collect_products_for_type($type_slug);
        if (empty($product_ids)) {
            return;
        }
        foreach ($product_ids as $product_id) {
            $missing = self::determine_missing_variants($product_id, $type_slug, $type_data, $added_colors, $allowed_additions);
            if (empty($missing)) {
                continue;
            }
            foreach ($missing as $color_slug => $payload) {
                $sizes = isset($payload['sizes']) ? $payload['sizes'] : [];
                $reason = __('Hiányzó variáns pótlása', 'mgdtp');
                if (!empty($payload['color_new'])) {
                    $reason = __('Új szín variánsainak pótlása', 'mgdtp');
                } elseif (!empty($payload['size_addition'])) {
                    $reason = __('Új méret variánsainak pótlása', 'mgdtp');
                }
                if (!empty($sizes)) {
                    $reason = sprintf('%s: %s', $reason, implode(', ', $sizes));
                }
                MG_Mockup_Maintenance::queue_for_regeneration($product_id, $type_slug, $color_slug, $reason);
            }
        }
    }

    private static function collect_products_for_type($type_slug) {
        $type_slug = sanitize_title($type_slug);
        if ($type_slug === '') {
            return [];
        }
        $ids = [];
        $index = MG_Mockup_Maintenance::get_index();
        if (is_array($index)) {
            foreach ($index as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (($entry['type_slug'] ?? '') !== $type_slug) {
                    continue;
                }
                $product_id = absint($entry['product_id'] ?? 0);
                if ($product_id > 0) {
                    $ids[$product_id] = true;
                }
            }
        }
        return array_keys($ids);
    }

    private static function determine_missing_variants($product_id, $type_slug, $type_data, $added_colors, $allowed_additions) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return [];
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->get_id()) {
            return [];
        }
        $existing = self::map_existing_variations($product, $type_slug);
        $missing = [];
        $color_keys = array_keys($type_data['colors']);
        foreach ($color_keys as $color_slug) {
            $allowed_sizes = self::allowed_sizes_from_struct($type_data, $color_slug);
            if (empty($allowed_sizes)) {
                continue;
            }
            $color_new = in_array($color_slug, $added_colors, true);
            $color_missing_before = empty($existing[$color_slug]);
            $size_targets = [];
            $size_addition_flag = false;

            if ($color_new || $color_missing_before) {
                foreach ($allowed_sizes as $size_label) {
                    if (empty($existing[$color_slug][$size_label])) {
                        $size_targets[] = $size_label;
                        if (!$color_new && isset($allowed_additions[$color_slug][$size_label])) {
                            $size_addition_flag = true;
                        }
                    }
                }
            }

            if (!$color_new && !$color_missing_before && !empty($allowed_additions[$color_slug])) {
                foreach ($allowed_additions[$color_slug] as $size_label => $flag) {
                    if (empty($flag)) {
                        continue;
                    }
                    if (!in_array($size_label, $allowed_sizes, true)) {
                        continue;
                    }
                    if (!empty($existing[$color_slug][$size_label])) {
                        continue;
                    }
                    $size_targets[] = $size_label;
                    $size_addition_flag = true;
                }
            }

            if (!empty($size_targets)) {
                $size_targets = array_values(array_unique($size_targets));
                $missing[$color_slug] = [
                    'sizes' => $size_targets,
                    'color_new' => $color_new,
                    'size_addition' => $size_addition_flag,
                ];
            }
        }
        return $missing;
    }

    private static function map_existing_variations($product, $type_slug) {
        $map = [];
        if (!is_object($product) || !method_exists($product, 'get_children')) {
            return $map;
        }
        $children = $product->get_children();
        if (empty($children) || !is_array($children)) {
            return $map;
        }
        foreach ($children as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation || !$variation->get_id()) {
                continue;
            }
            $attrs = $variation->get_attributes();
            $normalized = self::normalize_attributes($attrs);
            if (($normalized['pa_termektipus'] ?? '') !== $type_slug) {
                continue;
            }
            $color = isset($normalized['pa_szin']) ? $normalized['pa_szin'] : '';
            $size = isset($normalized['meret']) ? $normalized['meret'] : '';
            if ($color === '') {
                continue;
            }
            if (!isset($map[$color])) {
                $map[$color] = [];
            }
            if ($size !== '') {
                $map[$color][$size] = true;
            }
        }
        return $map;
    }

    private static function normalize_attributes($attributes) {
        $normalized = [];
        if (!is_array($attributes)) {
            return $normalized;
        }
        foreach ($attributes as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized_key = $key;
            if (strpos($normalized_key, 'attribute_') === 0) {
                $normalized_key = substr($normalized_key, 10);
            }
            $lower_key = strtolower($normalized_key);
            if ($lower_key === 'méret' || $lower_key === 'meret' || $lower_key === 'pa_meret') {
                $normalized_key = 'meret';
            } elseif ($lower_key === 'attribute_méret' || $lower_key === 'attribute_meret') {
                $normalized_key = 'meret';
            } elseif ($lower_key === 'pa_termektipus') {
                $normalized_key = 'pa_termektipus';
            } elseif ($lower_key === 'pa_szin') {
                $normalized_key = 'pa_szin';
            } elseif (strpos($lower_key, 'pa_') === 0) {
                $normalized_key = $lower_key;
            } else {
                $normalized_key = sanitize_title($normalized_key);
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            $value = is_string($value) ? $value : (string) $value;
            if ($normalized_key === 'pa_termektipus' || $normalized_key === 'pa_szin') {
                $normalized[$normalized_key] = sanitize_title($value);
            } elseif ($normalized_key === 'meret') {
                $normalized[$normalized_key] = sanitize_text_field($value);
            } else {
                $normalized[$normalized_key] = sanitize_text_field($value);
            }
        }
        return $normalized;
    }
}
