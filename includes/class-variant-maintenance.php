<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Variant_Maintenance {
    const OPTION_QUEUE = 'mg_variant_sync_queue';
    const OPTION_PROGRESS = 'mg_variant_sync_progress';
    const CRON_HOOK = 'mg_variant_sync_process';
    const CRON_TIME_LIMIT = 8.0;

    public static function init() {
        add_filter('pre_update_option_mg_products', [__CLASS__, 'handle_catalog_update'], 20, 2);
        add_action('init', [__CLASS__, 'bootstrap_queue_processor']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
        add_action('wp_ajax_mg_variant_progress', [__CLASS__, 'handle_progress_ajax']);
        add_action('wp_ajax_mg_variant_sync_run', [__CLASS__, 'handle_sync_run_ajax']);
    }

    public static function handle_catalog_update($new_value, $old_value) {
        if (!class_exists('MG_Mockup_Maintenance') || !function_exists('wc_get_product')) {
            return $new_value;
        }

        $type_renames = self::detect_type_slug_renames($old_value, $new_value);
        $new_types = self::normalize_catalog($new_value);
        $old_types = self::normalize_catalog($old_value);
        if (!empty($type_renames)) {
            foreach ($type_renames as $old_slug => $new_slug) {
                $old_slug = sanitize_title($old_slug);
                $new_slug = sanitize_title($new_slug);
                if ($old_slug === '' || $new_slug === '' || $old_slug === $new_slug) {
                    continue;
                }
                if (isset($old_types[$old_slug])) {
                    $old_types[$new_slug] = $old_types[$old_slug];
                    unset($old_types[$old_slug]);
                }
                self::apply_type_slug_rename($old_slug, $new_slug);
            }
        }

        $type_keys = array_unique(array_merge(array_keys($new_types), array_keys($old_types)));
        foreach ($type_keys as $type_slug) {
            $is_new_type = isset($new_types[$type_slug]) && !isset($old_types[$type_slug]);
            $type_data = isset($new_types[$type_slug]) ? $new_types[$type_slug] : [];
            $old_data = isset($old_types[$type_slug]) ? $old_types[$type_slug] : ['colors' => [], 'sizes' => [], 'matrix' => []];

            if (empty($type_data) && !empty($old_data)) {
                $product_ids = self::collect_products_for_type($type_slug);
                if (!empty($product_ids)) {
                    self::queue_products_for_removal($type_slug, $old_data, $product_ids);
                }
                continue;
            }

            $added_colors = array_diff(array_keys($type_data['colors']), array_keys($old_data['colors']));
            $removed_colors = array_diff(array_keys($old_data['colors']), array_keys($type_data['colors']));
            if (empty($added_colors) && empty($removed_colors)) {
                continue;
            }

            $product_ids = $is_new_type ? self::collect_all_products() : self::collect_products_for_type($type_slug);
            if (!empty($product_ids)) {
                self::queue_products_for_later($type_slug, $type_data, $added_colors, [], $product_ids, []);
            }
        }

        return $new_value;
    }

    private static function detect_type_slug_renames($old_value, $new_value) {
        $old_map = self::build_type_identity_map($old_value);
        $new_map = self::build_type_identity_map($new_value);
        if (empty($old_map) || empty($new_map)) {
            return [];
        }
        $renames = [];
        foreach ($old_map as $identity => $old_slug) {
            if (!isset($new_map[$identity])) {
                continue;
            }
            $new_slug = $new_map[$identity];
            if ($new_slug !== $old_slug) {
                $renames[$old_slug] = $new_slug;
            }
        }
        return $renames;
    }

    private static function build_type_identity_map($types) {
        $map = [];
        if (!is_array($types)) {
            return $map;
        }
        foreach ($types as $type) {
            if (!is_array($type) || empty($type['key'])) {
                continue;
            }
            $slug = sanitize_title($type['key']);
            if ($slug === '') {
                continue;
            }
            $label = sanitize_text_field($type['label'] ?? $type['name'] ?? '');
            if ($label === '') {
                continue;
            }
            $identity = 'label:' . strtolower($label);
            if (!isset($map[$identity])) {
                $map[$identity] = $slug;
            } else {
                $map[$identity] = null;
            }
        }
        return array_filter($map);
    }

    private static function apply_type_slug_rename($old_slug, $new_slug) {
        $old_slug = sanitize_title($old_slug);
        $new_slug = sanitize_title($new_slug);
        if ($old_slug === '' || $new_slug === '' || $old_slug === $new_slug) {
            return;
        }
        $product_ids = self::collect_products_for_type($old_slug);
        if (empty($product_ids)) {
            return;
        }
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !method_exists($product, 'get_children')) {
                continue;
            }
            $existing = self::map_existing_variations_with_ids($product, $old_slug);
            if (empty($existing)) {
                continue;
            }
            foreach ($existing as $color_slug => $sizes) {
                foreach ($sizes as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if (!$variation || !method_exists($variation, 'get_attributes')) {
                        continue;
                    }
                    $attributes = $variation->get_attributes();
                    $attributes['pa_termektipus'] = $new_slug;
                    $variation->set_attributes($attributes);
                    $variation->save();
                }
            }
            self::refresh_product_attributes_from_variations($product);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product->get_id());
            }
            if (function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables($product->get_id());
            }
        }
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

    private static function detect_size_renames($new_data, $old_data, $added_sizes) {
        $added_sizes = is_array($added_sizes) ? array_values($added_sizes) : [];
        $removed_sizes = array_diff($old_data['sizes'] ?? [], $new_data['sizes'] ?? []);
        if (empty($added_sizes) || empty($removed_sizes)) {
            return [];
        }
        $renames = [];
        $used_new = [];
        foreach ($removed_sizes as $old_size) {
            if (!is_string($old_size) || $old_size === '') {
                continue;
            }
            $old_allowed = self::colors_for_size_label($old_data, $old_size);
            $candidate = '';
            foreach ($added_sizes as $new_size) {
                if (!is_string($new_size) || $new_size === '' || isset($used_new[$new_size])) {
                    continue;
                }
                if (strcasecmp($old_size, $new_size) === 0) {
                    $candidate = $new_size;
                    break;
                }
                $new_allowed = self::colors_for_size_label($new_data, $new_size);
                if (!empty($old_allowed) && !empty($new_allowed) && $old_allowed === $new_allowed) {
                    if ($candidate !== '') {
                        $candidate = '';
                        break;
                    }
                    $candidate = $new_size;
                }
            }
            if ($candidate !== '' && $candidate !== $old_size) {
                $renames[$old_size] = $candidate;
                $used_new[$candidate] = true;
            }
        }
        return $renames;
    }

    private static function colors_for_size_label($data, $size_label) {
        $size_label = is_string($size_label) ? sanitize_text_field($size_label) : '';
        if ($size_label === '') {
            return [];
        }
        $matrix = isset($data['matrix']) && is_array($data['matrix']) ? $data['matrix'] : [];
        if (empty($matrix)) {
            return [];
        }
        $colors = isset($matrix[$size_label]) && is_array($matrix[$size_label]) ? $matrix[$size_label] : [];
        $normalized = [];
        foreach ($colors as $color_slug) {
            $color_slug = sanitize_title($color_slug);
            if ($color_slug === '') {
                continue;
            }
            $normalized[] = $color_slug;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    private static function detect_removed_size_allowances($new_data, $old_data) {
        $removals = [];
        $color_slugs = array_unique(array_merge(array_keys($new_data['colors']), array_keys($old_data['colors'])));
        foreach ($color_slugs as $color_slug) {
            $new_allowed = self::allowed_sizes_from_struct($new_data, $color_slug);
            $old_allowed = self::allowed_sizes_from_struct($old_data, $color_slug);
            $diff = array_diff($old_allowed, $new_allowed);
            if (!empty($diff)) {
                foreach ($diff as $size) {
                    if ($size === '') {
                        continue;
                    }
                    if (!isset($removals[$color_slug])) {
                        $removals[$color_slug] = [];
                    }
                    $removals[$color_slug][$size] = true;
                }
            }
        }
        return $removals;
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

    private static function process_single_product($product_id, $type_slug, $type_data, $added_colors, $allowed_additions, $size_renames) {
        self::sync_product_variants($product_id, $type_slug, $type_data, $size_renames);
        $missing = self::determine_missing_variants($product_id, $type_slug, $type_data, $added_colors, $allowed_additions);
        if (empty($missing)) {
            self::queue_missing_mockups_for_product($product_id, $type_slug, $type_data, []);
            return;
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
        self::queue_missing_mockups_for_product($product_id, $type_slug, $type_data, array_keys($missing));
    }

    private static function queue_missing_mockups_for_product($product_id, $type_slug, $type_data, $skip_colors) {
        if (!class_exists('MG_Mockup_Maintenance')) {
            return;
        }
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        if ($product_id <= 0 || $type_slug === '') {
            return;
        }
        $normalized = self::normalize_type_data_for_queue($type_data);
        $colors = array_keys($normalized['colors']);
        if (empty($colors)) {
            return;
        }
        $skip_colors = is_array($skip_colors) ? $skip_colors : [];
        $skip = [];
        foreach ($skip_colors as $color_slug) {
            $color_slug = sanitize_title($color_slug);
            if ($color_slug !== '') {
                $skip[$color_slug] = true;
            }
        }
        $index = MG_Mockup_Maintenance::get_index();
        if (!is_array($index)) {
            $index = [];
        }
        $requests = [];
        foreach ($colors as $color_slug) {
            if (isset($skip[$color_slug])) {
                continue;
            }
            $key = self::compose_mockup_index_key($product_id, $type_slug, $color_slug);
            $entry = isset($index[$key]) && is_array($index[$key]) ? $index[$key] : [];
            $attachment_ids = isset($entry['source']['attachment_ids']) ? (array) $entry['source']['attachment_ids'] : [];
            $has_attachment = false;
            foreach ($attachment_ids as $attachment_id) {
                if (absint($attachment_id) > 0) {
                    $has_attachment = true;
                    break;
                }
            }
            if ($has_attachment) {
                continue;
            }
            $requests[] = [
                'product_id' => $product_id,
                'type_slug' => $type_slug,
                'color_slug' => $color_slug,
                'reason' => __('Mockup hiányzik a variáns szinkron után.', 'mgdtp'),
            ];
        }
        if (!empty($requests)) {
            MG_Mockup_Maintenance::queue_multiple_for_regeneration($requests);
        }
    }

    private static function compose_mockup_index_key($product_id, $type_slug, $color_slug) {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        return $product_id . '|' . $type_slug . '|' . $color_slug;
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

    private static function collect_all_products() {
        $index = MG_Mockup_Maintenance::get_index();
        if (!is_array($index)) {
            return [];
        }
        $ids = [];
        foreach ($index as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $product_id = absint($entry['product_id'] ?? 0);
            if ($product_id > 0) {
                $ids[$product_id] = true;
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
            $color_new = in_array($color_slug, $added_colors, true);
            $color_missing_before = empty($existing[$color_slug]);
            if ($color_new || $color_missing_before) {
                $missing[$color_slug] = [
                    'sizes' => [],
                    'color_new' => $color_new,
                    'size_addition' => false,
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
            if ($color === '') {
                continue;
            }
            if (!isset($map[$color])) {
                $map[$color] = true;
            }
        }
        return $map;
    }

    private static function map_existing_variations_with_ids($product, $type_slug) {
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
            if ($color === '') {
                continue;
            }
            if (!isset($map[$color])) {
                $map[$color] = [];
            }
            $map[$color][] = $variation->get_id();
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

    public static function bootstrap_queue_processor() {
        $queue = self::get_queue();
        if (!empty($queue) && function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time() + 15, self::CRON_HOOK);
            }
        }
    }

    public static function process_queue($time_limit = null) {
        $queue = self::get_queue();
        if (empty($queue)) {
            self::set_queue([]);
            return;
        }

        $time_limit = is_numeric($time_limit) ? (float) $time_limit : self::get_cron_time_limit();
        if ($time_limit <= 0) {
            $time_limit = self::get_cron_time_limit();
        }
        $start = microtime(true);
        $remaining = [];

        while (!empty($queue) && (microtime(true) - $start) < $time_limit) {
            $item = array_shift($queue);
            $normalized = self::normalize_queue_item($item);
            if (empty($normalized['product_ids'])) {
                continue;
            }

            while (!empty($normalized['product_ids']) && (microtime(true) - $start) < $time_limit) {
                $product_id = array_shift($normalized['product_ids']);
                if (!empty($normalized['remove_type'])) {
                    self::remove_type_variations($product_id, $normalized['type_slug']);
                } else {
                    self::process_single_product(
                        $product_id,
                        $normalized['type_slug'],
                        $normalized['type_data'],
                        $normalized['added_colors'],
                        $normalized['allowed_additions'],
                        $normalized['size_renames']
                    );
                }
            }

            if (!empty($normalized['product_ids'])) {
                $remaining[] = $normalized;
                break;
            }
        }

        if (!empty($queue)) {
            foreach ($queue as $item) {
                $normalized = self::normalize_queue_item($item);
                if (!empty($normalized['product_ids'])) {
                    $remaining[] = $normalized;
                }
            }
        }

        if (!empty($remaining)) {
            self::set_queue($remaining);
            self::maybe_schedule_processor();
        } else {
            self::set_queue([]);
        }
    }

    public static function get_queue_count() {
        return count(self::get_queue());
    }

    public static function queue_full_sync() {
        $catalog = get_option('mg_products', []);
        $types = self::normalize_catalog($catalog);
        if (empty($types)) {
            return 0;
        }
        $product_ids = self::collect_all_products();
        if (empty($product_ids)) {
            return 0;
        }
        $queued = 0;
        foreach ($types as $type_slug => $type_data) {
            $added_colors = array_keys($type_data['colors'] ?? []);
            self::queue_products_for_later($type_slug, $type_data, $added_colors, [], $product_ids);
            $queued++;
        }
        return $queued;
    }

    public static function queue_type_sync($type_slug) {
        $type_slug = sanitize_title($type_slug);
        if ($type_slug === '') {
            return false;
        }
        $catalog = get_option('mg_products', []);
        $types = self::normalize_catalog($catalog);
        if (empty($types[$type_slug])) {
            return false;
        }
        $product_ids = self::collect_all_products();
        if (empty($product_ids)) {
            return false;
        }
        $type_data = $types[$type_slug];
        $added_colors = array_keys($type_data['colors'] ?? []);
        self::queue_products_for_later($type_slug, $type_data, $added_colors, [], $product_ids);
        return true;
    }

    private static function queue_products_for_later($type_slug, $type_data, $added_colors, $allowed_additions, $product_ids, $size_renames = []) {
        $type_slug = sanitize_title($type_slug);
        if ($type_slug === '') {
            return;
        }
        $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
        if (empty($product_ids)) {
            return;
        }

        $queue = self::get_queue();
        $normalized_type = self::normalize_type_data_for_queue($type_data);
        $normalized_colors = self::normalize_color_list($added_colors);
        $normalized_allowed = self::normalize_allowed_additions_map($allowed_additions);
        $normalized_renames = self::normalize_size_rename_map($size_renames);

        $merged = false;
        foreach ($queue as &$entry) {
            if (!is_array($entry) || !isset($entry['type_slug']) || sanitize_title($entry['type_slug']) !== $type_slug) {
                continue;
            }
            $entry_type = self::normalize_type_data_for_queue(isset($entry['type_data']) ? $entry['type_data'] : []);
            $entry_colors = self::normalize_color_list(isset($entry['added_colors']) ? $entry['added_colors'] : []);
            $entry_allowed = self::normalize_allowed_additions_map(isset($entry['allowed_additions']) ? $entry['allowed_additions'] : []);
            $entry_renames = self::normalize_size_rename_map(isset($entry['size_renames']) ? $entry['size_renames'] : []);
            $entry_products = array_values(array_unique(array_filter(array_map('absint', isset($entry['product_ids']) ? $entry['product_ids'] : []))));

            $entry_type = self::merge_type_data($entry_type, $normalized_type);
            $entry_colors = array_values(array_unique(array_merge($entry_colors, $normalized_colors)));
            $entry_allowed = self::merge_allowed_additions($entry_allowed, $normalized_allowed);
            $entry_renames = self::merge_size_rename_map($entry_renames, $normalized_renames);
            $entry_products = array_values(array_unique(array_merge($entry_products, $product_ids)));

            $entry['type_slug'] = $type_slug;
            $entry['type_data'] = $entry_type;
            $entry['added_colors'] = $entry_colors;
            $entry['allowed_additions'] = $entry_allowed;
            $entry['size_renames'] = $entry_renames;
            $entry['remove_type'] = !empty($entry['remove_type']);
            $entry['product_ids'] = $entry_products;
            $merged = true;
            break;
        }
        unset($entry);

        if (!$merged) {
            $queue[] = [
                'type_slug' => $type_slug,
                'type_data' => $normalized_type,
                'added_colors' => $normalized_colors,
                'allowed_additions' => $normalized_allowed,
                'size_renames' => $normalized_renames,
                'remove_type' => false,
                'product_ids' => $product_ids,
            ];
        }

        self::set_queue($queue);
        self::maybe_schedule_processor();
    }

    private static function normalize_queue_item($item) {
        $type_slug = is_array($item) && isset($item['type_slug']) ? sanitize_title($item['type_slug']) : '';
        $type_data = self::normalize_type_data_for_queue(is_array($item) && isset($item['type_data']) ? $item['type_data'] : []);
        $added_colors = self::normalize_color_list(is_array($item) && isset($item['added_colors']) ? $item['added_colors'] : []);
        $allowed_additions = self::normalize_allowed_additions_map(is_array($item) && isset($item['allowed_additions']) ? $item['allowed_additions'] : []);
        $size_renames = self::normalize_size_rename_map(is_array($item) && isset($item['size_renames']) ? $item['size_renames'] : []);
        $remove_type = !empty($item['remove_type']);
        $product_ids = [];
        if (is_array($item) && isset($item['product_ids']) && is_array($item['product_ids'])) {
            foreach ($item['product_ids'] as $id) {
                $id = absint($id);
                if ($id > 0) {
                    $product_ids[] = $id;
                }
            }
        }
        $product_ids = array_values(array_unique($product_ids));

        return [
            'type_slug' => $type_slug,
            'type_data' => $type_data,
            'added_colors' => $added_colors,
            'allowed_additions' => $allowed_additions,
            'size_renames' => $size_renames,
            'remove_type' => $remove_type,
            'product_ids' => $product_ids,
        ];
    }

    private static function normalize_type_data_for_queue($type_data) {
        $normalized = [
            'colors' => [],
            'sizes' => [],
            'matrix' => [],
        ];
        if (!is_array($type_data)) {
            return $normalized;
        }
        if (isset($type_data['colors']) && is_array($type_data['colors'])) {
            foreach ($type_data['colors'] as $slug => $color) {
                $color_slug = sanitize_title(is_array($color) && isset($color['slug']) ? $color['slug'] : $slug);
                if ($color_slug === '') {
                    continue;
                }
                $label = '';
                if (is_array($color) && isset($color['label'])) {
                    $label = sanitize_text_field($color['label']);
                } elseif (is_array($color) && isset($color['name'])) {
                    $label = sanitize_text_field($color['name']);
                }
                if ($label === '') {
                    $label = $color_slug;
                }
                $normalized['colors'][$color_slug] = [
                    'slug' => $color_slug,
                    'label' => $label,
                ];
            }
        }
        if (isset($type_data['sizes']) && is_array($type_data['sizes'])) {
            foreach ($type_data['sizes'] as $size) {
                if (!is_string($size)) {
                    continue;
                }
                $size = sanitize_text_field($size);
                if ($size === '') {
                    continue;
                }
                if (!in_array($size, $normalized['sizes'], true)) {
                    $normalized['sizes'][] = $size;
                }
            }
        }
        if (isset($type_data['matrix']) && is_array($type_data['matrix'])) {
            foreach ($type_data['matrix'] as $size_label => $colors) {
                if (!is_string($size_label)) {
                    continue;
                }
                $size_label = sanitize_text_field($size_label);
                if ($size_label === '') {
                    continue;
                }
                if (!isset($normalized['matrix'][$size_label])) {
                    $normalized['matrix'][$size_label] = [];
                }
                if (!is_array($colors)) {
                    continue;
                }
                foreach ($colors as $color_slug) {
                    $color_slug = sanitize_title($color_slug);
                    if ($color_slug === '') {
                        continue;
                    }
                    if (!in_array($color_slug, $normalized['matrix'][$size_label], true)) {
                        $normalized['matrix'][$size_label][] = $color_slug;
                    }
                }
            }
        }
        return $normalized;
    }

    private static function normalize_color_list($colors) {
        $normalized = [];
        if (!is_array($colors)) {
            return $normalized;
        }
        foreach ($colors as $color) {
            $slug = sanitize_title($color);
            if ($slug === '') {
                continue;
            }
            if (!in_array($slug, $normalized, true)) {
                $normalized[] = $slug;
            }
        }
        return $normalized;
    }

    private static function normalize_allowed_additions_map($input) {
        $normalized = [];
        if (!is_array($input)) {
            return $normalized;
        }
        foreach ($input as $color_slug => $sizes) {
            $color_key = sanitize_title($color_slug);
            if ($color_key === '') {
                continue;
            }
            if (!isset($normalized[$color_key])) {
                $normalized[$color_key] = [];
            }
            if (!is_array($sizes)) {
                $sizes = [$sizes];
            }
            foreach ($sizes as $size_label => $flag) {
                $size_value = '';
                if (is_bool($flag)) {
                    if (!$flag) {
                        continue;
                    }
                    $size_value = is_string($size_label) ? $size_label : '';
                } elseif (is_string($flag)) {
                    $size_value = $flag;
                } elseif (is_numeric($flag) && is_string($size_label)) {
                    $size_value = $size_label;
                }
                $size_value = sanitize_text_field($size_value);
                if ($size_value === '') {
                    continue;
                }
                $normalized[$color_key][$size_value] = true;
            }
        }
        return $normalized;
    }

    private static function normalize_size_rename_map($input) {
        $normalized = [];
        if (!is_array($input)) {
            return $normalized;
        }
        foreach ($input as $old_size => $new_size) {
            if (!is_string($old_size) || !is_string($new_size)) {
                continue;
            }
            $old_size = sanitize_text_field($old_size);
            $new_size = sanitize_text_field($new_size);
            if ($old_size === '' || $new_size === '' || $old_size === $new_size) {
                continue;
            }
            $normalized[$old_size] = $new_size;
        }
        return $normalized;
    }

    private static function merge_size_rename_map($current, $incoming) {
        $current = is_array($current) ? $current : [];
        $incoming = is_array($incoming) ? $incoming : [];
        foreach ($incoming as $old_size => $new_size) {
            if (!isset($current[$old_size])) {
                $current[$old_size] = $new_size;
            }
        }
        return $current;
    }

    private static function merge_allowed_additions($current, $incoming) {
        if (!is_array($current)) {
            $current = [];
        }
        if (!is_array($incoming)) {
            return $current;
        }
        foreach ($incoming as $color_slug => $sizes) {
            if (!is_array($sizes)) {
                continue;
            }
            if (!isset($current[$color_slug])) {
                $current[$color_slug] = [];
            }
            foreach ($sizes as $size_label => $flag) {
                if (!$flag) {
                    continue;
                }
                $current[$color_slug][$size_label] = true;
            }
        }
        return $current;
    }

    private static function merge_type_data($current, $incoming) {
        if (!is_array($current) || empty($current)) {
            return $incoming;
        }
        if (!is_array($incoming) || empty($incoming)) {
            return $current;
        }
        if (!isset($current['colors']) || !is_array($current['colors'])) {
            $current['colors'] = [];
        }
        if (isset($incoming['colors']) && is_array($incoming['colors'])) {
            foreach ($incoming['colors'] as $slug => $color) {
                $current['colors'][$slug] = $color;
            }
        }
        if (!isset($current['sizes']) || !is_array($current['sizes'])) {
            $current['sizes'] = [];
        }
        if (isset($incoming['sizes']) && is_array($incoming['sizes'])) {
            $current['sizes'] = array_values(array_unique(array_merge($current['sizes'], $incoming['sizes'])));
        }
        if (!isset($current['matrix']) || !is_array($current['matrix'])) {
            $current['matrix'] = [];
        }
        if (isset($incoming['matrix']) && is_array($incoming['matrix'])) {
            foreach ($incoming['matrix'] as $size_label => $colors) {
                if (!is_array($colors)) {
                    continue;
                }
                if (!isset($current['matrix'][$size_label])) {
                    $current['matrix'][$size_label] = $colors;
                } else {
                    $current['matrix'][$size_label] = array_values(array_unique(array_merge($current['matrix'][$size_label], $colors)));
                }
            }
        }
        return $current;
    }

    private static function queue_products_for_removal($type_slug, $type_data, $product_ids) {
        $type_slug = sanitize_title($type_slug);
        if ($type_slug === '') {
            return;
        }
        $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) $product_ids))));
        if (empty($product_ids)) {
            return;
        }
        $queue = self::get_queue();
        $normalized_type = self::normalize_type_data_for_queue($type_data);

        $queue[] = [
            'type_slug' => $type_slug,
            'type_data' => $normalized_type,
            'added_colors' => [],
            'allowed_additions' => [],
            'remove_type' => true,
            'product_ids' => $product_ids,
        ];

        self::set_queue($queue);
        self::maybe_schedule_processor();
    }

    public static function sync_product_variants($product_id, $type_slug, $type_data, $size_renames = []) {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        if ($product_id <= 0 || $type_slug === '') {
            return [
                'removed_colors' => [],
                'added_variants' => [],
            ];
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->get_id()) {
            return [
                'removed_colors' => [],
                'added_variants' => [],
            ];
        }
        $allow_sync = apply_filters('mg_enable_wc_variation_sync', false, $product_id, $type_slug, $type_data);
        if (!$allow_sync || $product->is_type('simple')) {
            return [
                'removed_colors' => [],
                'added_variants' => [],
            ];
        }
        $normalized = self::normalize_type_data_for_queue($type_data);
        $colors = array_keys($normalized['colors']);
        $existing = self::map_existing_variations_with_ids($product, $type_slug);
        $progress_total = self::count_existing_variations($existing) + self::count_allowed_variations($colors);
        $progress_current = 0;
        self::start_progress($product_id, $type_slug, $progress_total);
        $removed_colors = [];

        $parent_sku_base = $product->get_sku();
        if (!$parent_sku_base && method_exists($product, 'get_name')) {
            $parent_sku_base = strtoupper(sanitize_title($product->get_name()));
        }
        $base_price = isset($type_data['price']) ? (int) $type_data['price'] : 0;
        $color_surcharge_map = isset($type_data['color_surcharges']) && is_array($type_data['color_surcharges']) ? $type_data['color_surcharges'] : [];
        $sku_prefix = isset($type_data['sku_prefix']) ? strtoupper(sanitize_text_field($type_data['sku_prefix'])) : strtoupper($type_slug);
        $type_label = $type_data['label'] ?? $type_slug;

        self::ensure_term_exists('pa_termektipus', $type_slug, $type_label);
        foreach ($normalized['colors'] as $color_slug => $color_data) {
            $color_label = $color_data['label'] ?? $color_slug;
            self::ensure_term_exists('pa_szin', $color_slug, $color_label);
        }

        $added_variants = [];
        $kept_variations = [];
        foreach ($existing as $color_slug => $variation_ids) {
            $color_allowed = in_array($color_slug, $colors, true);
            $kept_id = 0;
            foreach ($variation_ids as $variation_id) {
                $progress_current++;
                self::update_progress($product_id, $type_slug, $progress_current, $progress_total);
                if (!$color_allowed) {
                    $removed_colors[$color_slug] = true;
                    $variation = wc_get_product($variation_id);
                    if ($variation && method_exists($variation, 'delete')) {
                        $variation->delete(true);
                    } else {
                        wp_delete_post($variation_id, true);
                    }
                    continue;
                }
                if ($kept_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation && method_exists($variation, 'delete')) {
                        $variation->delete(true);
                    } else {
                        wp_delete_post($variation_id, true);
                    }
                    continue;
                }
                $kept_id = $variation_id;
                $kept_variations[$color_slug] = $variation_id;
                $variation = wc_get_product($variation_id);
                if ($variation && method_exists($variation, 'set_attributes')) {
                    $variation->set_attributes([
                        'pa_termektipus' => $type_slug,
                        'pa_szin' => $color_slug,
                    ]);
                    $price = max(0, $base_price + (int) ($color_surcharge_map[$color_slug] ?? 0));
                    if ($price > 0) {
                        $variation->set_regular_price($price);
                    }
                    if ($parent_sku_base) {
                        $variation->set_sku(strtoupper($parent_sku_base . '-' . $sku_prefix . '-' . $color_slug));
                    }
                    $variation->save();
                }
            }
        }

        foreach ($colors as $color_slug) {
            $progress_current++;
            self::update_progress($product_id, $type_slug, $progress_current, $progress_total);
            if (!empty($kept_variations[$color_slug])) {
                continue;
            }
            $price = max(0, $base_price + (int) ($color_surcharge_map[$color_slug] ?? 0));
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product->get_id());
            $variation->set_attributes([
                'pa_termektipus' => $type_slug,
                'pa_szin' => $color_slug,
            ]);
            if ($price > 0) {
                $variation->set_regular_price($price);
            }
            if ($parent_sku_base) {
                $variation->set_sku(strtoupper($parent_sku_base . '-' . $sku_prefix . '-' . $color_slug));
            }
            $variation->save();
            $added_variants[$color_slug] = true;
        }

        if (!empty($removed_colors) || !empty($added_variants)) {
            self::refresh_product_attributes_from_variations($product);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product->get_id());
            }
            if (function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables($product->get_id());
            }
        }

        if (!empty($removed_colors) && class_exists('MG_Mockup_Maintenance')) {
            MG_Mockup_Maintenance::purge_index_entries_for_type($product_id, $type_slug, array_keys($removed_colors));
        }

        self::finish_progress($product_id, $type_slug, max($progress_current, $progress_total));

        return [
            'removed_colors' => array_keys($removed_colors),
            'added_variants' => array_keys($added_variants),
        ];
    }

    public static function get_progress() {
        $progress = get_option(self::OPTION_PROGRESS, []);
        return is_array($progress) ? $progress : [];
    }

    private static function set_progress($progress) {
        if (!is_array($progress)) {
            $progress = [];
        }
        update_option(self::OPTION_PROGRESS, $progress, false);
    }

    private static function start_progress($product_id, $type_slug, $total) {
        $total = max(1, (int) $total);
        self::set_progress([
            'status' => 'running',
            'product_id' => absint($product_id),
            'type_slug' => sanitize_title($type_slug),
            'current' => 0,
            'total' => $total,
            'percent' => 0,
            'updated_at' => current_time('timestamp', true),
        ]);
    }

    private static function update_progress($product_id, $type_slug, $current, $total) {
        $total = max(1, (int) $total);
        $current = min($total, max(0, (int) $current));
        $percent = (int) round(($current / $total) * 100);
        self::set_progress([
            'status' => 'running',
            'product_id' => absint($product_id),
            'type_slug' => sanitize_title($type_slug),
            'current' => $current,
            'total' => $total,
            'percent' => min(100, max(0, $percent)),
            'updated_at' => current_time('timestamp', true),
        ]);
    }

    private static function finish_progress($product_id, $type_slug, $total) {
        $total = max(1, (int) $total);
        self::set_progress([
            'status' => 'done',
            'product_id' => absint($product_id),
            'type_slug' => sanitize_title($type_slug),
            'current' => $total,
            'total' => $total,
            'percent' => 100,
            'updated_at' => current_time('timestamp', true),
        ]);
    }

    private static function count_existing_variations($existing) {
        $count = 0;
        foreach ((array) $existing as $variation_ids) {
            if (is_array($variation_ids)) {
                $count += count($variation_ids);
            }
        }
        return $count;
    }

    private static function count_allowed_variations($allowed_by_color) {
        return is_array($allowed_by_color) ? count($allowed_by_color) : 0;
    }

    public static function handle_progress_ajax() {
        check_ajax_referer('mg_ajax_nonce', 'nonce');
        wp_send_json_success(self::get_progress());
    }

    public static function handle_sync_run_ajax() {
        check_ajax_referer('mg_ajax_nonce', 'nonce');
        self::process_queue(5);
        wp_send_json_success([
            'remaining' => self::get_queue_count(),
        ]);
    }

    private static function remove_type_variations($product_id, $type_slug) {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        if ($product_id <= 0 || $type_slug === '') {
            return;
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->get_id()) {
            return;
        }
        $existing = self::map_existing_variations_with_ids($product, $type_slug);
        foreach ($existing as $sizes) {
            foreach ($sizes as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation && method_exists($variation, 'delete')) {
                    $variation->delete(true);
                } else {
                    wp_delete_post($variation_id, true);
                }
            }
        }
        self::refresh_product_attributes_from_variations($product);
        if (class_exists('MG_Mockup_Maintenance')) {
            MG_Mockup_Maintenance::purge_index_entries_for_type($product_id, $type_slug);
        }
    }

    private static function refresh_product_attributes_from_variations($product) {
        if (!$product || !method_exists($product, 'get_children')) {
            return;
        }
        $children = $product->get_children();
        $type_slugs = [];
        $color_slugs = [];
        foreach ($children as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }
            $attrs = self::normalize_attributes($variation->get_attributes());
            if (!empty($attrs['pa_termektipus'])) {
                $type_slugs[$attrs['pa_termektipus']] = true;
            }
            if (!empty($attrs['pa_szin'])) {
                $color_slugs[$attrs['pa_szin']] = true;
            }
        }

        $attrs = $product->get_attributes();
        $attr_type = isset($attrs['pa_termektipus']) ? $attrs['pa_termektipus'] : new WC_Product_Attribute();
        $attr_type->set_name('pa_termektipus');
        $attr_type->set_visible(true);
        $attr_type->set_variation(true);
        $attr_type->set_options(self::lookup_term_ids('pa_termektipus', array_keys($type_slugs)));

        $attr_color = isset($attrs['pa_szin']) ? $attrs['pa_szin'] : new WC_Product_Attribute();
        $attr_color->set_name('pa_szin');
        $attr_color->set_visible(true);
        $attr_color->set_variation(true);
        $attr_color->set_options(self::lookup_term_ids('pa_szin', array_keys($color_slugs)));

        $product->set_attributes([$attr_type, $attr_color]);
        $product->save();
    }

    private static function lookup_term_ids($taxonomy, $slugs) {
        $ids = [];
        if (!taxonomy_exists($taxonomy)) {
            return $ids;
        }
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $ids[] = (int) $term->term_id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function ensure_term_exists($taxonomy, $slug, $name) {
        if (!taxonomy_exists($taxonomy)) {
            return;
        }
        $slug = sanitize_title($slug);
        $name = sanitize_text_field($name);
        if ($slug === '') {
            return;
        }
        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return;
        }
        wp_insert_term($name !== '' ? $name : $slug, $taxonomy, ['slug' => $slug]);
    }

    private static function get_queue() {
        $queue = get_option(self::OPTION_QUEUE, []);
        if (!is_array($queue)) {
            $queue = [];
        }
        return array_values($queue);
    }

    private static function set_queue($queue) {
        if (!is_array($queue)) {
            $queue = [];
        }
        update_option(self::OPTION_QUEUE, array_values($queue), false);
    }

    private static function maybe_schedule_processor() {
        if (empty(self::get_queue())) {
            return;
        }
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time() + 30, self::CRON_HOOK);
            }
        }
    }

    private static function get_cron_time_limit() {
        $limit = apply_filters('mg_variant_sync_cron_time_limit', self::CRON_TIME_LIMIT);
        $limit = is_numeric($limit) ? (float) $limit : self::CRON_TIME_LIMIT;
        if ($limit <= 0) {
            $limit = self::CRON_TIME_LIMIT;
        }
        return $limit;
    }
}
