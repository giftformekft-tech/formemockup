<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Mockup_Maintenance {
    const OPTION_STATUS_INDEX = 'mg_mockup_status_index';
    const OPTION_QUEUE = 'mg_mockup_regen_queue';
    const OPTION_ACTIVITY_LOG = 'mg_mockup_activity_log';
    const OPTION_BATCH_SIZE = 'mg_mockup_batch_size';
    const DEFAULT_BATCH = 3;
    const MIN_BATCH = 1;
    const MAX_BATCH = 50;
    const CRON_HOOK = 'mg_mockup_process_queue';
    const META_LAST_DESIGN_PATH = '_mg_last_design_path';
    const META_LAST_DESIGN_ATTACHMENT = '_mg_last_design_attachment';

    public static function init() {
        add_action('init', [__CLASS__, 'register_cron_schedule']);
        add_action('init', [__CLASS__, 'maybe_schedule_processor']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
        add_filter('cron_schedules', [__CLASS__, 'register_interval']);
        add_filter('pre_update_option_mg_products', [__CLASS__, 'handle_product_catalog_update'], 10, 2);
    }

    public static function register_interval($schedules) {
        $schedules['mg_mockup_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Mockup karbantartás (5 perc)', 'mgdtp'),
        ];
        return $schedules;
    }

    public static function register_cron_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'mg_mockup_five_minutes', self::CRON_HOOK);
        }
    }

    public static function maybe_schedule_processor() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            self::register_cron_schedule();
        }
    }

    public static function get_index() {
        $index = get_option(self::OPTION_STATUS_INDEX, []);
        if (!is_array($index)) {
            $index = [];
        }
        list($normalized, $needs_update) = self::normalize_index_entries($index);
        if ($needs_update) {
            $index = $normalized;
            self::set_index($index);
        } else {
            $index = $normalized;
        }
        return $index;
    }

    private static function normalize_index_entries($raw_index) {
        $normalized = [];
        $needs_update = false;
        if (!is_array($raw_index)) {
            return [$normalized, true];
        }
        foreach ($raw_index as $key => $entry) {
            if (!is_array($entry)) {
                $needs_update = true;
                continue;
            }
            $product_id = absint($entry['product_id'] ?? 0);
            $type_slug = sanitize_title($entry['type_slug'] ?? '');
            $color_slug = sanitize_title($entry['color_slug'] ?? '');
            if ($product_id <= 0 || $type_slug === '' || $color_slug === '') {
                $needs_update = true;
                continue;
            }
            $expected_key = self::compose_key($product_id, $type_slug, $color_slug);
            $entry['product_id'] = $product_id;
            $entry['type_slug'] = $type_slug;
            $entry['color_slug'] = $color_slug;
            if (!isset($normalized[$expected_key])) {
                $normalized[$expected_key] = $entry;
            } else {
                $normalized[$expected_key] = self::prefer_latest_entry($normalized[$expected_key], $entry);
                $needs_update = true;
            }
            if ($key !== $expected_key) {
                $needs_update = true;
            }
        }
        if (count($normalized) !== count($raw_index)) {
            $needs_update = true;
        }
        return [$normalized, $needs_update];
    }

    private static function prefer_latest_entry($current, $candidate) {
        $current = is_array($current) ? $current : [];
        $candidate = is_array($candidate) ? $candidate : [];
        $current_updated = isset($current['updated_at']) ? (int) $current['updated_at'] : 0;
        $candidate_updated = isset($candidate['updated_at']) ? (int) $candidate['updated_at'] : 0;
        if ($candidate_updated > $current_updated) {
            $merged = array_merge($current, $candidate);
        } else {
            $merged = array_merge($candidate, $current);
            if (empty($merged['source']) && !empty($candidate['source'])) {
                $merged['source'] = $candidate['source'];
            }
        }
        $merged['product_id'] = absint($merged['product_id'] ?? 0);
        $merged['type_slug'] = sanitize_title($merged['type_slug'] ?? '');
        $merged['color_slug'] = sanitize_title($merged['color_slug'] ?? '');
        return $merged;
    }

    public static function set_index($index) {
        update_option(self::OPTION_STATUS_INDEX, $index, false);
    }

    public static function get_queue() {
        $queue = get_option(self::OPTION_QUEUE, []);
        if (!is_array($queue)) {
            $queue = [];
        }
        return array_values(array_unique(array_filter(array_map('strval', $queue))));
    }

    public static function set_queue($queue) {
        update_option(self::OPTION_QUEUE, array_values(array_unique(array_filter($queue))), false);
    }

    public static function get_batch_size() {
        $stored = get_option(self::OPTION_BATCH_SIZE, self::DEFAULT_BATCH);
        $normalized = self::normalize_batch_size($stored);
        if ((int) $stored !== $normalized) {
            update_option(self::OPTION_BATCH_SIZE, $normalized, false);
        }
        return $normalized;
    }

    public static function set_batch_size($value) {
        $normalized = self::normalize_batch_size($value);
        update_option(self::OPTION_BATCH_SIZE, $normalized, false);
        return $normalized;
    }

    private static function normalize_batch_size($value) {
        $value = absint($value);
        if ($value < self::MIN_BATCH) {
            return self::MIN_BATCH;
        }
        if ($value > self::MAX_BATCH) {
            return self::MAX_BATCH;
        }
        return $value;
    }

    private static function compose_key($product_id, $type_slug, $color_slug) {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        return $product_id . '|' . $type_slug . '|' . $color_slug;
    }

    private static function current_timestamp() {
        return current_time('timestamp', true);
    }

    private static function product_is_active($product) {
        if (!$product || !is_object($product)) {
            return false;
        }
        if (method_exists($product, 'get_status')) {
            $status = $product->get_status();
            if (!in_array($status, ['publish'], true)) {
                return false;
            }
        }
        return true;
    }

    private static function find_type_definition($type_slug) {
        $catalog = get_option('mg_products', []);
        if (!is_array($catalog)) {
            return null;
        }
        foreach ($catalog as $type) {
            if (!is_array($type) || empty($type['key'])) {
                continue;
            }
            if (sanitize_title($type['key']) === $type_slug) {
                return $type;
            }
        }
        return null;
    }

    private static function normalize_colors_from_type($type) {
        $out = [];
        if (!is_array($type) || empty($type['colors']) || !is_array($type['colors'])) {
            return $out;
        }
        foreach ($type['colors'] as $color) {
            if (!is_array($color) || empty($color['slug'])) {
                continue;
            }
            $slug = sanitize_title($color['slug']);
            $out[$slug] = $color;
        }
        return $out;
    }

    private static function normalize_single_override_path($path) {
        if (!is_string($path)) {
            return '';
        }
        $path = wp_normalize_path(trim($path));
        if ($path === '') {
            return '';
        }

        $candidates = [];
        $uploads = wp_upload_dir();
        $basedir = !empty($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        $baseurl = !empty($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') : '';

        $is_url = filter_var($path, FILTER_VALIDATE_URL);
        if ($is_url) {
            if ($baseurl && $basedir && strpos($path, $baseurl) === 0) {
                $relative = ltrim(substr($path, strlen($baseurl)), '/');
                $candidates[] = wp_normalize_path(trailingslashit($basedir) . $relative);
            } else {
                return '';
            }
        } else {
            $candidates[] = $path;
            if ($basedir !== '') {
                $candidates[] = wp_normalize_path(trailingslashit($basedir) . ltrim($path, '/'));
            }
            $candidates[] = wp_normalize_path(ABSPATH . ltrim($path, '/'));
            $plugin_root = wp_normalize_path(trailingslashit(dirname(__DIR__)));
            $candidates[] = wp_normalize_path($plugin_root . ltrim($path, '/'));
        }

        $checked = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $candidate = wp_normalize_path($candidate);
            if (in_array($candidate, $checked, true)) {
                continue;
            }
            $checked[] = $candidate;
            if (file_exists($candidate) && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private static function normalize_override_path_list($paths) {
        $list = [];
        if (is_array($paths)) {
            foreach ($paths as $path) {
                $normalized = self::normalize_single_override_path($path);
                if ($normalized === '' || in_array($normalized, $list, true)) {
                    continue;
                }
                $list[] = $normalized;
            }
        } elseif (is_string($paths)) {
            $normalized = self::normalize_single_override_path($paths);
            if ($normalized !== '') {
                $list[] = $normalized;
            }
        }
        return array_values($list);
    }

    private static function normalize_overrides_from_type($type) {
        $out = [];
        if (!is_array($type) || empty($type['mockup_overrides']) || !is_array($type['mockup_overrides'])) {
            return $out;
        }
        foreach ($type['mockup_overrides'] as $color_slug => $files) {
            if (!is_string($color_slug)) {
                continue;
            }
            $color_slug = sanitize_title($color_slug);
            if ($color_slug === '') {
                continue;
            }
            if (!is_array($files)) {
                $files = [];
            }
            foreach ($files as $view_key => $paths) {
                if (!is_string($view_key)) {
                    continue;
                }
                $view_key = trim($view_key);
                if ($view_key === '') {
                    continue;
                }
                $list = self::normalize_override_path_list($paths);
                if (empty($list)) {
                    continue;
                }
                $out[$color_slug][$view_key] = $list;
            }
        }
        return $out;
    }

    private static function sanitize_single_product_snapshot($product) {
        if (!is_array($product) || empty($product['key'])) {
            return null;
        }
        $key = sanitize_title($product['key']);
        if ($key === '') {
            return null;
        }
        $sanitized = [
            'key'             => $key,
            'label'           => isset($product['label']) ? sanitize_text_field($product['label']) : $key,
            'is_primary'      => !empty($product['is_primary']) ? 1 : 0,
            'primary_color'   => isset($product['primary_color']) ? sanitize_title($product['primary_color']) : '',
            'primary_size'    => isset($product['primary_size']) ? sanitize_text_field($product['primary_size']) : '',
            'price'           => isset($product['price']) ? (int) $product['price'] : 0,
            'sku_prefix'      => isset($product['sku_prefix']) ? sanitize_text_field($product['sku_prefix']) : '',
            'type_description'=> isset($product['type_description']) ? wp_kses_post($product['type_description']) : '',
            'colors'          => [],
            'sizes'           => [],
            'size_color_matrix' => [],
            'size_surcharges' => [],
            'color_surcharges'=> [],
            'tags'            => [],
        ];
        if (!empty($product['colors']) && is_array($product['colors'])) {
            foreach ($product['colors'] as $color) {
                if (!is_array($color)) {
                    continue;
                }
                $slug = sanitize_title($color['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $name = isset($color['name']) ? sanitize_text_field($color['name']) : $slug;
                $sanitized['colors'][] = [
                    'slug' => $slug,
                    'name' => $name,
                ];
            }
        }
        if (!empty($product['sizes']) && is_array($product['sizes'])) {
            foreach ($product['sizes'] as $size_label) {
                if (!is_string($size_label)) {
                    continue;
                }
                $size_label = sanitize_text_field($size_label);
                if ($size_label === '') {
                    continue;
                }
                if (!in_array($size_label, $sanitized['sizes'], true)) {
                    $sanitized['sizes'][] = $size_label;
                }
            }
        }
        if (!empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
            foreach ($product['size_color_matrix'] as $size_key => $colors) {
                if (!is_string($size_key)) {
                    continue;
                }
                $size_key = sanitize_text_field($size_key);
                if ($size_key === '') {
                    continue;
                }
                $clean = [];
                if (is_array($colors)) {
                    foreach ($colors as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '' || in_array($slug, $clean, true)) {
                            continue;
                        }
                        $clean[] = $slug;
                    }
                }
                $sanitized['size_color_matrix'][$size_key] = $clean;
            }
        }
        if (!empty($product['size_surcharges']) && is_array($product['size_surcharges'])) {
            foreach ($product['size_surcharges'] as $size_label => $amount) {
                if (!is_string($size_label)) {
                    continue;
                }
                $size_label = sanitize_text_field($size_label);
                if ($size_label === '') {
                    continue;
                }
                $sanitized['size_surcharges'][$size_label] = (int) $amount;
            }
        }
        if (!empty($product['color_surcharges']) && is_array($product['color_surcharges'])) {
            foreach ($product['color_surcharges'] as $slug => $amount) {
                $slug = sanitize_title($slug);
                if ($slug === '') {
                    continue;
                }
                $sanitized['color_surcharges'][$slug] = (int) $amount;
            }
        }
        if (!empty($product['tags']) && is_array($product['tags'])) {
            foreach ($product['tags'] as $tag) {
                if (!is_string($tag)) {
                    continue;
                }
                $tag = sanitize_text_field($tag);
                if ($tag === '') {
                    continue;
                }
                $sanitized['tags'][] = $tag;
            }
        }
        return $sanitized;
    }

    private static function sanitize_selected_products($selected_products) {
        $result = [];
        if (!is_array($selected_products)) {
            return $result;
        }
        foreach ($selected_products as $product) {
            $sanitized = self::sanitize_single_product_snapshot($product);
            if ($sanitized) {
                $result[] = $sanitized;
            }
        }
        return $result;
    }

    private static function sanitize_default_attributes($defaults) {
        $normalized = [];
        if (!is_array($defaults)) {
            return $normalized;
        }
        foreach ($defaults as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $key_lower = strtolower($key);
            if ($key_lower === 'pa_termektipus' || $key_lower === 'termektipus') {
                $normalized['pa_termektipus'] = sanitize_title($value);
            } elseif ($key_lower === 'pa_szin' || $key_lower === 'szin') {
                $normalized['pa_szin'] = sanitize_title($value);
            } elseif ($key_lower === 'pa_meret' || $key_lower === 'meret' || $key_lower === 'méret') {
                $normalized['meret'] = sanitize_text_field($value);
            }
        }
        return $normalized;
    }

    private static function merge_size_color_matrix($existing, $catalog) {
        $existing = is_array($existing) ? $existing : [];
        $catalog = is_array($catalog) ? $catalog : [];
        if (empty($catalog)) {
            return $existing;
        }
        foreach ($catalog as $size_label => $colors) {
            if (!is_string($size_label)) {
                continue;
            }
            $size_label = sanitize_text_field($size_label);
            if ($size_label === '') {
                continue;
            }
            $catalog_colors = [];
            if (is_array($colors)) {
                foreach ($colors as $slug) {
                    $slug = sanitize_title($slug);
                    if ($slug === '' || in_array($slug, $catalog_colors, true)) {
                        continue;
                    }
                    $catalog_colors[] = $slug;
                }
            }
            $existing_colors = isset($existing[$size_label]) && is_array($existing[$size_label]) ? $existing[$size_label] : [];
            foreach ($catalog_colors as $slug) {
                if (!in_array($slug, $existing_colors, true)) {
                    $existing_colors[] = $slug;
                }
            }
            $existing[$size_label] = $existing_colors;
        }
        return $existing;
    }

    private static function ensure_color_allowed_for_sizes($matrix, $color_slug, $catalog_matrix, $sizes) {
        $matrix = is_array($matrix) ? $matrix : [];
        $color_slug = sanitize_title($color_slug);
        $catalog_matrix = is_array($catalog_matrix) ? $catalog_matrix : [];
        $sizes = is_array($sizes) ? $sizes : [];
        if (!empty($catalog_matrix)) {
            foreach ($catalog_matrix as $size_label => $colors) {
                if (!is_string($size_label)) {
                    continue;
                }
                $size_label = sanitize_text_field($size_label);
                if ($size_label === '') {
                    continue;
                }
                $normalized_colors = [];
                if (is_array($colors)) {
                    foreach ($colors as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '' || in_array($slug, $normalized_colors, true)) {
                            continue;
                        }
                        $normalized_colors[] = $slug;
                    }
                }
                if (!in_array($color_slug, $normalized_colors, true)) {
                    continue;
                }
                if (!isset($matrix[$size_label]) || !is_array($matrix[$size_label])) {
                    $matrix[$size_label] = [];
                }
                if (!in_array($color_slug, $matrix[$size_label], true)) {
                    $matrix[$size_label][] = $color_slug;
                }
            }
        } elseif (!empty($matrix)) {
            foreach ($matrix as $size_label => &$color_list) {
                if (!is_array($color_list)) {
                    $color_list = [];
                }
                if (!in_array($color_slug, $color_list, true)) {
                    $color_list[] = $color_slug;
                }
            }
            unset($color_list);
        }
        if (empty($catalog_matrix) && empty($matrix) && !empty($sizes)) {
            // leave matrix empty to allow all sizes when no restrictions exist
            return $matrix;
        }
        foreach ($matrix as $size_label => $color_list) {
            if (!is_array($color_list)) {
                $matrix[$size_label] = [];
            } else {
                $matrix[$size_label] = array_values(array_unique($color_list));
            }
        }
        return $matrix;
    }

    private static function prepare_selected_payload_for_color($snapshot, $type_slug, $color_slug) {
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        $snapshot = self::sanitize_selected_products($snapshot);
        $catalog_item = self::sanitize_single_product_snapshot(self::find_type_definition($type_slug));
        $result = [];
        $found = false;
        foreach ($snapshot as $item) {
            $item_slug = sanitize_title($item['key'] ?? '');
            if ($item_slug === '') {
                continue;
            }
            if ($item_slug === $type_slug) {
                $result[] = self::enrich_type_snapshot_with_catalog($item, $catalog_item, $color_slug);
                $found = true;
            } else {
                $result[] = $item;
            }
        }
        if (!$found && $catalog_item) {
            $result[] = self::enrich_type_snapshot_with_catalog([], $catalog_item, $color_slug);
        }
        return $result;
    }

    private static function enrich_type_snapshot_with_catalog($snapshot_item, $catalog_item, $focus_color_slug) {
        if (!$catalog_item) {
            return is_array($snapshot_item) ? $snapshot_item : [];
        }
        $snapshot_item = is_array($snapshot_item) ? $snapshot_item : [];
        $focus_color_slug = sanitize_title($focus_color_slug);
        $merged = $snapshot_item;
        $merged['key'] = $catalog_item['key'];
        $merged['label'] = $catalog_item['label'];
        $merged['price'] = $catalog_item['price'];
        $merged['sku_prefix'] = $catalog_item['sku_prefix'];
        $merged['type_description'] = $catalog_item['type_description'];
        $merged['is_primary'] = $catalog_item['is_primary'];
        $merged['primary_color'] = $catalog_item['primary_color'];
        $merged['primary_size'] = $catalog_item['primary_size'];
        $merged['sizes'] = !empty($catalog_item['sizes']) ? $catalog_item['sizes'] : (isset($merged['sizes']) ? $merged['sizes'] : []);
        $merged['colors'] = $catalog_item['colors'];
        $merged['size_surcharges'] = $catalog_item['size_surcharges'];
        $merged['color_surcharges'] = $catalog_item['color_surcharges'];
        $existing_matrix = isset($merged['size_color_matrix']) ? $merged['size_color_matrix'] : [];
        $merged_matrix = self::merge_size_color_matrix($existing_matrix, $catalog_item['size_color_matrix']);
        $merged['size_color_matrix'] = self::ensure_color_allowed_for_sizes($merged_matrix, $focus_color_slug, $catalog_item['size_color_matrix'], $merged['sizes']);
        $has_color = false;
        foreach ($merged['colors'] as $color) {
            if (($color['slug'] ?? '') === $focus_color_slug) {
                $has_color = true;
                break;
            }
        }
        if (!$has_color) {
            foreach ($catalog_item['colors'] as $color) {
                if (($color['slug'] ?? '') === $focus_color_slug) {
                    $merged['colors'][] = $color;
                    break;
                }
            }
        }
        return $merged;
    }

    private static function ensure_product_has_color_variant($entry) {
        if (!class_exists('MG_Product_Creator')) {
            return;
        }
        $product_id = absint($entry['product_id'] ?? 0);
        $type_slug = sanitize_title($entry['type_slug'] ?? '');
        $color_slug = sanitize_title($entry['color_slug'] ?? '');
        if ($product_id <= 0 || $type_slug === '' || $color_slug === '') {
            return;
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->get_id()) {
            return;
        }
        $key = self::compose_key($product_id, $type_slug, $color_slug);
        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : [];
        $selected_snapshot = isset($source['selected_products']) ? $source['selected_products'] : [];
        $selected_payload = self::prepare_selected_payload_for_color($selected_snapshot, $type_slug, $color_slug);
        $selected_payload = self::sanitize_selected_products($selected_payload);
        if (empty($selected_payload)) {
            return;
        }
        $defaults_source = isset($source['defaults']) ? $source['defaults'] : [];
        $defaults_source = self::sanitize_default_attributes($defaults_source);
        $product_defaults = self::sanitize_default_attributes($product->get_default_attributes());
        $merged_defaults = array_merge($product_defaults, $defaults_source);
        $index = self::get_index();
        if (isset($index[$key])) {
            $stored_source = isset($index[$key]['source']) && is_array($index[$key]['source']) ? $index[$key]['source'] : [];
            $stored_source['selected_products'] = $selected_payload;
            if (!empty($merged_defaults)) {
                $stored_source['defaults'] = $merged_defaults;
            }
            $index[$key]['source'] = $stored_source;
            self::set_index($index);
        }
        $defaults_payload = [
            'type'  => $merged_defaults['pa_termektipus'] ?? '',
            'color' => $merged_defaults['pa_szin'] ?? '',
            'size'  => $merged_defaults['meret'] ?? '',
        ];
        $generation_context = [
            'skip_register_maintenance' => true,
            'trigger' => 'maintenance_auto_color',
        ];
        $type_definition = self::find_type_definition($type_slug);
        $images_payload = [];
        $override_images = self::collect_override_images_for_color($type_definition, $color_slug);
        if (!empty($override_images)) {
            $type_key_for_images = $type_definition && !empty($type_definition['key']) ? sanitize_title($type_definition['key']) : $type_slug;
            $images_payload[$type_key_for_images][$color_slug] = $override_images;
        }
        $creator = new MG_Product_Creator();
        $result = $creator->add_type_to_existing_parent(
            $product_id,
            $selected_payload,
            $images_payload,
            '',
            [],
            $defaults_payload,
            $generation_context
        );
        if (is_wp_error($result)) {
            self::log_activity($entry, 'error', sprintf(__('Nem sikerült az új szín variáns létrehozása: %s', 'mgdtp'), $result->get_error_message()));
            return;
        }
        $latest_product = wc_get_product($product_id);
        if ($latest_product && $latest_product->get_id()) {
            $latest_defaults = self::sanitize_default_attributes($latest_product->get_default_attributes());
            if (!empty($latest_defaults)) {
                $index = self::get_index();
                if (isset($index[$key])) {
                    $stored_source = isset($index[$key]['source']) && is_array($index[$key]['source']) ? $index[$key]['source'] : [];
                    $stored_source['defaults'] = $latest_defaults;
                    $index[$key]['source'] = $stored_source;
                    self::set_index($index);
                }
            }
        }
    }

    public static function register_generation($product_id, $selected_products, $images_by_type_color, $context = []) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return;
        }
        $index = self::get_index();
        $queue = self::get_queue();
        $timestamp = self::current_timestamp();
        $design_path = isset($context['design_path']) ? wp_normalize_path($context['design_path']) : '';
        $design_attachment_id = isset($context['design_attachment_id']) ? absint($context['design_attachment_id']) : 0;
        if (!empty($design_attachment_id) && function_exists('get_attached_file')) {
            $resolved = get_attached_file($design_attachment_id);
            if ($resolved) {
                $design_path = $resolved;
            }
        }
        $shared_source = [];
        $sanitized_products = self::sanitize_selected_products($selected_products);
        if (!empty($sanitized_products)) {
            $shared_source['selected_products'] = $sanitized_products;
        }
        $sanitized_defaults = [];
        if (isset($context['applied_defaults'])) {
            $sanitized_defaults = self::sanitize_default_attributes($context['applied_defaults']);
        }
        if (!empty($sanitized_defaults)) {
            $shared_source['defaults'] = $sanitized_defaults;
        }
        foreach ((array) $selected_products as $type) {
            if (!is_array($type) || empty($type['key'])) {
                continue;
            }
            $type_slug = sanitize_title($type['key']);
            $colors = self::normalize_colors_from_type($type);
            foreach ($colors as $color_slug => $color) {
                $key = self::compose_key($product_id, $type_slug, $color_slug);
                if (!isset($index[$key])) {
                    $index[$key] = [
                        'product_id'   => $product_id,
                        'type_slug'    => $type_slug,
                        'color_slug'   => $color_slug,
                        'status'       => 'ok',
                        'updated_at'   => $timestamp,
                        'last_generated' => $timestamp,
                        'last_message' => '',
                        'pending_reason' => '',
                        'source'       => [],
                    ];
                } else {
                    $index[$key]['status'] = 'ok';
                    $index[$key]['updated_at'] = $timestamp;
                    $index[$key]['last_generated'] = $timestamp;
                    $index[$key]['last_message'] = '';
                    $index[$key]['pending_reason'] = '';
                }
                $index[$key]['source'] = array_merge($index[$key]['source'] ?? [], $shared_source, [
                    'design_path' => $design_path,
                    'design_attachment_id' => $design_attachment_id,
                    'type_label' => isset($type['label']) ? sanitize_text_field($type['label']) : $type_slug,
                    'color_label' => isset($color['name']) ? sanitize_text_field($color['name']) : $color_slug,
                    'views' => isset($type['views']) && is_array($type['views']) ? array_values($type['views']) : [],
                    'images' => self::extract_generated_images($images_by_type_color, $type['key'], $type_slug, $color_slug),
                ]);
                $queue = array_values(array_diff($queue, [$key]));
            }
        }
        self::store_design_reference($product_id, $design_path, $design_attachment_id);
        self::set_index($index);
        self::set_queue($queue);
    }

    public static function queue_for_regeneration($product_id, $type_slug, $color_slug, $reason = '', $context = []) {
        self::queue_multiple_for_regeneration([
            [
                'product_id' => $product_id,
                'type_slug' => $type_slug,
                'color_slug' => $color_slug,
                'reason' => $reason,
                'context' => $context,
            ],
        ]);
    }

    public static function queue_multiple_for_regeneration($items) {
        $normalized = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $product_id = absint($item['product_id'] ?? 0);
                $type_slug = sanitize_title($item['type_slug'] ?? '');
                $color_slug = sanitize_title($item['color_slug'] ?? '');
                if ($product_id <= 0 || $type_slug === '' || $color_slug === '') {
                    continue;
                }
                $key = self::compose_key($product_id, $type_slug, $color_slug);
                $normalized[$key] = [
                    'product_id' => $product_id,
                    'type_slug' => $type_slug,
                    'color_slug' => $color_slug,
                    'reason' => isset($item['reason']) ? $item['reason'] : '',
                    'context' => isset($item['context']) && is_array($item['context']) ? $item['context'] : [],
                ];
            }
        }
        if (empty($normalized)) {
            return;
        }
        $index = self::get_index();
        $queue = self::get_queue();
        $index_changed = false;
        $queue_changed = false;
        $new_entries = [];
        foreach ($normalized as $payload) {
            $result = self::queue_regeneration_entry(
                $index,
                $queue,
                $payload['product_id'],
                $payload['type_slug'],
                $payload['color_slug'],
                $payload['reason'],
                $payload['context'],
                $new_entries
            );
            if ($result['index_changed']) {
                $index_changed = true;
            }
            if ($result['queue_changed']) {
                $queue_changed = true;
            }
        }
        if ($index_changed) {
            self::set_index($index);
        }
        if ($queue_changed) {
            self::set_queue($queue);
        }
        if ($index_changed || $queue_changed) {
            self::maybe_schedule_processor();
        }
        if (!empty($new_entries)) {
            foreach ($new_entries as $entry) {
                self::ensure_product_has_color_variant($entry);
            }
        }
    }

    private static function queue_regeneration_entry(&$index, &$queue, $product_id, $type_slug, $color_slug, $reason, $context, &$new_entries) {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if ($product_id <= 0 || $type_slug === '' || $color_slug === '') {
            return [
                'index_changed' => false,
                'queue_changed' => false,
            ];
        }
        $key = self::compose_key($product_id, $type_slug, $color_slug);
        $timestamp = self::current_timestamp();
        $existing_entry = isset($index[$key]) && is_array($index[$key]) ? $index[$key] : null;
        $entry = $existing_entry ? $existing_entry : [];
        $is_new_entry = !$existing_entry;

        $entry['product_id'] = $product_id;
        $entry['type_slug'] = $type_slug;
        $entry['color_slug'] = $color_slug;
        $entry['status'] = 'pending';
        $entry['updated_at'] = $timestamp;
        $entry['pending_reason'] = sanitize_text_field($reason);
        if ($is_new_entry) {
            $entry['last_generated'] = 0;
            $entry['last_message'] = '';
        } else {
            if (!isset($entry['last_generated'])) {
                $entry['last_generated'] = 0;
            }
            if (!isset($entry['last_message'])) {
                $entry['last_message'] = '';
            }
        }

        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : [];
        if (empty($source)) {
            $source = self::inherit_source($index, $product_id, $type_slug);
        }
        $context = is_array($context) ? $context : [];
        if (!empty($context)) {
            if (isset($context['design_path'])) {
                $context['design_path'] = wp_normalize_path($context['design_path']);
            }
            $source = array_merge($source, $context);
            if (!empty($context['design_path']) || !empty($context['design_attachment_id'])) {
                self::store_design_reference($product_id, $context['design_path'] ?? '', $context['design_attachment_id'] ?? 0);
            }
        }
        $source = self::apply_type_metadata_to_source($source, $type_slug, $color_slug);
        if ($is_new_entry) {
            $source = self::prime_source_for_new_entry($source, $type_slug, $color_slug);
        }
        $source = self::merge_design_reference_into_source($source, $product_id);
        $entry['source'] = $source;

        $index_changed = $is_new_entry || !self::entries_are_equal($existing_entry, $entry);
        if ($index_changed) {
            $index[$key] = $entry;
        }

        $queue_changed = false;
        if (!in_array($key, $queue, true)) {
            $queue[] = $key;
            $queue_changed = true;
        }

        if ($is_new_entry && $index_changed) {
            $new_entries[] = $entry;
        }

        return [
            'index_changed' => $index_changed,
            'queue_changed' => $queue_changed,
        ];
    }

    private static function entries_are_equal($a, $b) {
        if (!is_array($a) && !is_array($b)) {
            return true;
        }
        if (!is_array($a) || !is_array($b)) {
            return false;
        }
        return md5(wp_json_encode($a)) === md5(wp_json_encode($b));
    }

    private static function inherit_source($index, $product_id, $type_slug) {
        foreach ($index as $entry) {
            if (empty($entry['product_id']) || empty($entry['type_slug'])) {
                continue;
            }
            if ((int) $entry['product_id'] === (int) $product_id && $entry['type_slug'] === sanitize_title($type_slug)) {
                if (!empty($entry['source'])) {
                    return $entry['source'];
                }
            }
        }
        return [];
    }

    private static function store_design_reference($product_id, $design_path, $design_attachment_id) {
        $product_id = absint($product_id);
        if ($product_id <= 0 || !function_exists('update_post_meta')) {
            return;
        }
        $design_attachment_id = absint($design_attachment_id);
        if ($design_attachment_id > 0) {
            update_post_meta($product_id, self::META_LAST_DESIGN_ATTACHMENT, $design_attachment_id);
        }
        $design_path = is_string($design_path) ? wp_normalize_path($design_path) : '';
        if ($design_path !== '' && file_exists($design_path)) {
            update_post_meta($product_id, self::META_LAST_DESIGN_PATH, $design_path);
        }
    }

    private static function fetch_design_reference($product_id) {
        $reference = [];
        $product_id = absint($product_id);
        if ($product_id <= 0 || !function_exists('get_post_meta')) {
            return $reference;
        }
        $attachment_id = get_post_meta($product_id, self::META_LAST_DESIGN_ATTACHMENT, true);
        if (!empty($attachment_id)) {
            $reference['design_attachment_id'] = (int) $attachment_id;
        }
        $stored_path = get_post_meta($product_id, self::META_LAST_DESIGN_PATH, true);
        if (is_string($stored_path) && $stored_path !== '') {
            $reference['design_path'] = wp_normalize_path($stored_path);
        }
        return $reference;
    }

    private static function merge_design_reference_into_source($source, $product_id) {
        $source = is_array($source) ? $source : [];
        $reference = self::fetch_design_reference($product_id);
        $has_valid_path = false;
        if (!empty($source['design_path'])) {
            $source['design_path'] = wp_normalize_path($source['design_path']);
            if ($source['design_path'] && file_exists($source['design_path'])) {
                $has_valid_path = true;
            }
        }
        if (!$has_valid_path && !empty($reference['design_path']) && file_exists($reference['design_path'])) {
            $source['design_path'] = wp_normalize_path($reference['design_path']);
            $has_valid_path = true;
        }
        if (empty($source['design_attachment_id']) && !empty($reference['design_attachment_id'])) {
            $source['design_attachment_id'] = (int) $reference['design_attachment_id'];
        }
        if (!$has_valid_path && !empty($source['design_attachment_id']) && function_exists('get_attached_file')) {
            $file = get_attached_file((int) $source['design_attachment_id']);
            if ($file && file_exists($file)) {
                $source['design_path'] = wp_normalize_path($file);
            }
        }
        return $source;
    }

    private static function prime_source_for_new_entry($source, $type_slug, $color_slug) {
        $source = is_array($source) ? $source : [];
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        $catalog_item = self::find_type_definition($type_slug);
        $sanitized_catalog = self::sanitize_single_product_snapshot($catalog_item);
        if ($sanitized_catalog) {
            $selected = isset($source['selected_products']) ? self::sanitize_selected_products($source['selected_products']) : [];
            $has_type = false;
            foreach ($selected as $idx => $item) {
                if (($item['key'] ?? '') === ($sanitized_catalog['key'] ?? '')) {
                    $selected[$idx] = self::enrich_type_snapshot_with_catalog($item, $sanitized_catalog, $color_slug);
                    $has_type = true;
                    break;
                }
            }
            if (!$has_type) {
                $selected[] = self::enrich_type_snapshot_with_catalog([], $sanitized_catalog, $color_slug);
            }
            $source['selected_products'] = $selected;
            if (empty($source['defaults'])) {
                $defaults = [];
                if (!empty($sanitized_catalog['key'])) {
                    $defaults['pa_termektipus'] = $sanitized_catalog['key'];
                }
                if (!empty($sanitized_catalog['primary_color'])) {
                    $defaults['pa_szin'] = $sanitized_catalog['primary_color'];
                }
                if (!empty($sanitized_catalog['primary_size'])) {
                    $defaults['meret'] = $sanitized_catalog['primary_size'];
                }
                if (!empty($defaults)) {
                    $source['defaults'] = $defaults;
                }
            }
        }
        return $source;
    }

    private static function collect_override_images_for_color($type, $color_slug) {
        $images = [];
        if (!is_array($type) || empty($type['views']) || !is_array($type['views'])) {
            return $images;
        }
        $color_slug = sanitize_title($color_slug);
        $overrides = self::normalize_overrides_from_type($type);
        if (!isset($overrides[$color_slug])) {
            return $images;
        }
        foreach ((array) $type['views'] as $view) {
            if (!is_array($view) || empty($view['file'])) {
                continue;
            }
            $view_key = $view['file'];
            if (!isset($overrides[$color_slug][$view_key])) {
                continue;
            }
            $paths = $overrides[$color_slug][$view_key];
            $paths = is_array($paths) ? $paths : [$paths];
            foreach ($paths as $path) {
                $path = wp_normalize_path($path);
                if ($path && file_exists($path)) {
                    $images[] = $path;
                }
            }
        }
        return $images;
    }

    private static function apply_type_metadata_to_source($source, $type_slug, $color_slug) {
        $source = is_array($source) ? $source : [];
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        $type = self::find_type_definition($type_slug);
        if ($type) {
            $type_label = isset($type['label']) ? sanitize_text_field($type['label']) : $type_slug;
            $source['type_label'] = $type_label;
            if (isset($type['views']) && is_array($type['views'])) {
                $source['views'] = array_values($type['views']);
            }
            $colors = self::normalize_colors_from_type($type);
            if (isset($colors[$color_slug])) {
                $color = $colors[$color_slug];
                if (isset($color['name'])) {
                    $source['color_label'] = sanitize_text_field($color['name']);
                }
            }
        }
        if (empty($source['type_label'])) {
            $source['type_label'] = $type_slug;
        }
        if (empty($source['color_label'])) {
            $source['color_label'] = $color_slug;
        }
        return $source;
    }

    private static function resolve_design_path($entry) {
        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : [];
        $path = isset($source['design_path']) ? wp_normalize_path($source['design_path']) : '';
        if (!empty($path) && file_exists($path)) {
            return $path;
        }
        if (!empty($source['design_attachment_id']) && function_exists('get_attached_file')) {
            $file = get_attached_file((int) $source['design_attachment_id']);
            if ($file && file_exists($file)) {
                return $file;
            }
        }
        $reference = self::fetch_design_reference($entry['product_id'] ?? 0);
        if (!empty($reference['design_attachment_id']) && function_exists('get_attached_file')) {
            $file = get_attached_file((int) $reference['design_attachment_id']);
            if ($file && file_exists($file)) {
                return $file;
            }
        }
        if (!empty($reference['design_path'])) {
            $ref_path = wp_normalize_path($reference['design_path']);
            if ($ref_path && file_exists($ref_path)) {
                return $ref_path;
            }
        }
        return apply_filters('mg_mockup_resolve_design_path', $path, $entry);
    }

    public static function get_status_entries($filters = []) {
        $filters = is_array($filters) ? $filters : [];
        $index = self::prune_missing_references(self::get_index());
        $result = [];
        foreach ($index as $key => $entry) {
            $status = $entry['status'] ?? '';
            if (!empty($filters['status']) && $filters['status'] !== $status) {
                continue;
            }
            if (!empty($filters['product_id']) && (int) $filters['product_id'] !== (int) ($entry['product_id'] ?? 0)) {
                continue;
            }
            if (!empty($filters['type_slug']) && sanitize_title($filters['type_slug']) !== ($entry['type_slug'] ?? '')) {
                continue;
            }
            if (!empty($filters['color_slug']) && sanitize_title($filters['color_slug']) !== ($entry['color_slug'] ?? '')) {
                continue;
            }
            $entry['key'] = $key;
            $result[] = $entry;
        }
        usort($result, function ($a, $b) {
            $ta = $a['updated_at'] ?? 0;
            $tb = $b['updated_at'] ?? 0;
            return $tb <=> $ta;
        });
        return $result;
    }

    private static function prune_missing_references($index) {
        if (!is_array($index) || empty($index)) {
            return [];
        }
        $pruned = $index;
        $needs_update = false;
        $product_cache = [];
        foreach ($pruned as $key => $entry) {
            $product_id = absint($entry['product_id'] ?? 0);
            if ($product_id > 0 && function_exists('wc_get_product')) {
                if (!array_key_exists($product_id, $product_cache)) {
                    $product_obj = wc_get_product($product_id);
                    $product_cache[$product_id] = [
                        'exists' => ($product_obj && $product_obj->get_id()),
                        'active' => ($product_obj && $product_obj->get_id() && self::product_is_active($product_obj)),
                    ];
                }
                $cached = $product_cache[$product_id];
                if (!$cached['exists'] || !$cached['active']) {
                    unset($pruned[$key]);
                    $needs_update = true;
                    continue;
                }
            }
            $type_slug = sanitize_title($entry['type_slug'] ?? '');
            if ($type_slug !== '' && !self::find_type_definition($type_slug)) {
                unset($pruned[$key]);
                $needs_update = true;
            }
        }
        if ($needs_update) {
            self::set_index($pruned);
        }
        return $pruned;
    }

    public static function process_queue() {
        $queue = self::get_queue();
        if (empty($queue)) {
            return;
        }
        $batch = array_slice($queue, 0, self::get_batch_size());
        foreach ($batch as $key) {
            self::process_single($key);
        }
    }

    private static function process_single($key) {
        $index = self::get_index();
        if (!isset($index[$key])) {
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            return;
        }
        $entry = $index[$key];
        self::ensure_product_has_color_variant($entry);
        $index = self::get_index();
        if (!isset($index[$key])) {
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            return;
        }
        $entry = $index[$key];
        $design_path = self::resolve_design_path($entry);
        if (empty($design_path) || !file_exists($design_path)) {
            $index[$key]['status'] = 'error';
            $index[$key]['last_message'] = __('Hiányzik a forrás design fájl. Kérjük töltsd fel újra.', 'mgdtp');
            $index[$key]['updated_at'] = self::current_timestamp();
            $index[$key]['last_generated'] = 0;
            self::set_index($index);
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            self::log_activity($entry, 'error', $index[$key]['last_message']);
            return;
        }
        $type_slug = $entry['type_slug'] ?? '';
        $color_slug = $entry['color_slug'] ?? '';
        $product_id = $entry['product_id'] ?? 0;
        $type = self::find_type_definition($type_slug);
        if (!$type) {
            $index[$key]['status'] = 'missing';
            $index[$key]['last_message'] = __('A terméktípus már nem található a mg_products listában.', 'mgdtp');
            $index[$key]['updated_at'] = self::current_timestamp();
            self::set_index($index);
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            self::log_activity($entry, 'missing', $index[$key]['last_message']);
            return;
        }
        if (!function_exists('wc_get_product')) {
            return; // WooCommerce not loaded yet.
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->get_id()) {
            $index[$key]['status'] = 'missing';
            $index[$key]['last_message'] = __('A WooCommerce termék nem található.', 'mgdtp');
            $index[$key]['updated_at'] = self::current_timestamp();
            self::set_index($index);
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            self::log_activity($entry, 'missing', $index[$key]['last_message']);
            return;
        }
        require_once __DIR__ . '/class-generator.php';
        $generator = new MG_Generator();
        $result = $generator->generate_for_product($type['key'], $design_path);
        if (is_wp_error($result)) {
            $index[$key]['status'] = 'error';
            $index[$key]['last_message'] = $result->get_error_message();
            $index[$key]['updated_at'] = self::current_timestamp();
            self::set_index($index);
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            self::log_activity($entry, 'error', $index[$key]['last_message']);
            return;
        }
        $color_slug = sanitize_title($color_slug);
        $files = isset($result[$color_slug]) ? (array) $result[$color_slug] : [];
        if (empty($files)) {
            $index[$key]['status'] = 'error';
            $index[$key]['last_message'] = __('A generálás nem adott vissza mockup fájlokat ehhez a színhez.', 'mgdtp');
            $index[$key]['updated_at'] = self::current_timestamp();
            self::set_index($index);
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            self::log_activity($entry, 'error', $index[$key]['last_message']);
            return;
        }
        $old_attachments = isset($entry['source']['attachment_ids']) ? (array) $entry['source']['attachment_ids'] : [];
        $seo_text = self::compose_image_seo_text($product, $type, $color_slug);
        $new_attachment_ids = [];
        foreach ($files as $file) {
            $attachment_id = self::attach_image($file, $seo_text);
            if ($attachment_id) {
                $new_attachment_ids[] = $attachment_id;
            }
        }
        if (empty($new_attachment_ids)) {
            $index[$key]['status'] = 'error';
            $index[$key]['last_message'] = __('Nem sikerült csatolni az új mockup képeket.', 'mgdtp');
            $index[$key]['updated_at'] = self::current_timestamp();
            self::set_index($index);
            self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
            self::log_activity($entry, 'error', $index[$key]['last_message']);
            return;
        }
        self::apply_variation_images($product, $type_slug, $color_slug, $new_attachment_ids);
        self::refresh_gallery($product, $old_attachments, $new_attachment_ids);
        $timestamp = self::current_timestamp();
        $index[$key]['status'] = 'ok';
        $index[$key]['updated_at'] = $timestamp;
        $index[$key]['last_generated'] = $timestamp;
        $index[$key]['last_message'] = __('Sikeres újragenerálás.', 'mgdtp');
        $index[$key]['pending_reason'] = '';
        $index[$key]['source']['design_path'] = $design_path;
        $index[$key]['source']['attachment_ids'] = $new_attachment_ids;
        $index[$key]['source']['last_generated_files'] = $files;
        self::set_index($index);
        self::set_queue(array_values(array_diff(self::get_queue(), [$key])));
        self::log_activity($entry, 'ok', __('Mockup újragenerálva.', 'mgdtp'));
        do_action('mg_mockup_regenerated', $index[$key]);
    }

    private static function compose_image_seo_text($product, $type, $color_slug) {
        $product_name = '';
        if (is_object($product) && method_exists($product, 'get_name')) {
            $product_name = sanitize_text_field($product->get_name());
        }

        $type_label = '';
        if (is_array($type)) {
            if (!empty($type['label'])) {
                $type_label = sanitize_text_field($type['label']);
            } elseif (!empty($type['name'])) {
                $type_label = sanitize_text_field($type['name']);
            } elseif (!empty($type['key'])) {
                $type_label = sanitize_text_field($type['key']);
            }
        }

        $color_label = sanitize_title($color_slug);
        $colors = self::normalize_colors_from_type($type);
        if (!empty($colors[$color_label]['name'])) {
            $color_label = sanitize_text_field($colors[$color_label]['name']);
        }

        $parts = array_filter([$product_name, $type_label, $color_label], 'strlen');
        return implode(' - ', $parts);
    }

    private static function attach_image($path, $seo_text = '') {
        if (!function_exists('wp_check_filetype')) {
            return 0;
        }
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 99);
        add_filter('big_image_size_threshold', '__return_false', 99);
        $filetype = wp_check_filetype(basename($path), null);
        if (empty($filetype['type']) && preg_match('/\.webp$/i', $path)) {
            $filetype['type'] = 'image/webp';
        }
        $wp_upload_dir = wp_upload_dir();
        $title = $seo_text !== '' ? sanitize_text_field($seo_text) : preg_replace('/\.[^.]+$/', '', basename($path));
        $attachment = [
            'guid'           => trailingslashit($wp_upload_dir['url']) . basename($path),
            'post_mime_type' => $filetype['type'] ?? 'image/webp',
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $path);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = ['file' => _wp_relative_upload_path($path)];
        wp_update_attachment_metadata($attach_id, $attach_data);
        if ($attach_id && $seo_text !== '') {
            update_post_meta($attach_id, '_wp_attachment_image_alt', $title);
        }
        remove_filter('intermediate_image_sizes_advanced', '__return_empty_array', 99);
        remove_filter('big_image_size_threshold', '__return_false', 99);
        return $attach_id;
    }

    private static function normalize_attributes($attributes) {
        $normalized = [];
        if (!is_array($attributes)) {
            return $normalized;
        }
        foreach ($attributes as $key => $value) {
            if (is_object($value) && method_exists($value, 'get_name')) {
                $key = $value->get_name();
                $value = $value->get_options();
            }
            $k = is_string($key) ? strtolower($key) : '';
            if (strpos($k, 'attribute_') === 0) {
                $k = substr($k, 10);
            }
            if ($k === 'pa_termektipus') {
                $normalized['pa_termektipus'] = is_array($value) ? reset($value) : $value;
            } elseif ($k === 'pa_szin') {
                $normalized['pa_szin'] = is_array($value) ? reset($value) : $value;
            } elseif ($k === 'meret' || $k === 'méret') {
                $normalized['meret'] = is_array($value) ? reset($value) : $value;
            }
        }
        return array_map('sanitize_title', $normalized);
    }

    private static function apply_variation_images($product, $type_slug, $color_slug, $attachment_ids) {
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if (!method_exists($product, 'get_children')) {
            return;
        }
        $children = $product->get_children();
        if (empty($children)) {
            return;
        }
        $primary_attachment = !empty($attachment_ids) ? (int) $attachment_ids[0] : 0;
        foreach ($children as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }
            $attrs = self::normalize_attributes($variation->get_attributes());
            if (($attrs['pa_termektipus'] ?? '') === $type_slug && ($attrs['pa_szin'] ?? '') === $color_slug) {
                if ($primary_attachment) {
                    $variation->set_image_id($primary_attachment);
                    $variation->save();
                }
            }
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product->get_id());
        }
        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables($product->get_id());
        }
    }

    private static function refresh_gallery($product, $old_ids, $new_ids) {
        if (!method_exists($product, 'get_gallery_image_ids')) {
            return;
        }
        $gallery = $product->get_gallery_image_ids();
        $gallery = is_array($gallery) ? $gallery : [];
        $gallery = array_diff($gallery, array_map('intval', (array) $old_ids));
        $gallery = array_merge($gallery, array_map('intval', (array) $new_ids));
        $gallery = array_values(array_unique($gallery));
        if (method_exists($product, 'set_gallery_image_ids')) {
            $product->set_gallery_image_ids($gallery);
            $product->save();
        }
    }

    private static function extract_generated_images($images_by_type_color, $type_key, $type_slug, $color_slug) {
        if (!is_array($images_by_type_color)) {
            return [];
        }
        $type_key_sanitized = sanitize_title($type_key);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        $by_type = [];
        if (isset($images_by_type_color[$type_key]) && is_array($images_by_type_color[$type_key])) {
            $by_type = $images_by_type_color[$type_key];
        } elseif (isset($images_by_type_color[$type_key_sanitized]) && is_array($images_by_type_color[$type_key_sanitized])) {
            $by_type = $images_by_type_color[$type_key_sanitized];
        } elseif (isset($images_by_type_color[$type_slug]) && is_array($images_by_type_color[$type_slug])) {
            $by_type = $images_by_type_color[$type_slug];
        }
        if (isset($by_type[$color_slug]) && is_array($by_type[$color_slug])) {
            return array_values($by_type[$color_slug]);
        }
        return [];
    }

    private static function log_activity($entry, $status, $message) {
        $log = get_option(self::OPTION_ACTIVITY_LOG, []);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'time'    => self::current_timestamp(),
            'entry'   => $entry,
            'status'  => $status,
            'message' => $message,
        ];
        $log = array_slice($log, -200);
        update_option(self::OPTION_ACTIVITY_LOG, $log, false);
    }

    public static function get_activity_log() {
        $log = get_option(self::OPTION_ACTIVITY_LOG, []);
        if (!is_array($log)) {
            $log = [];
        }
        return array_reverse($log);
    }

    public static function handle_product_catalog_update($new_value, $old_value) {
        $new_value = is_array($new_value) ? $new_value : [];
        $old_value = is_array($old_value) ? $old_value : [];
        $old_map = [];
        foreach ($old_value as $type) {
            if (!is_array($type) || empty($type['key'])) {
                continue;
            }
            $old_map[sanitize_title($type['key'])] = $type;
        }
        $index_snapshot = self::get_index();
        $index_lookup = [];
        foreach ($index_snapshot as $entry) {
            $type_key = sanitize_title($entry['type_slug'] ?? '');
            $color_key = sanitize_title($entry['color_slug'] ?? '');
            $product_id = absint($entry['product_id'] ?? 0);
            if ($type_key === '' || $product_id <= 0) {
                continue;
            }
            if (!isset($index_lookup[$type_key])) {
                $index_lookup[$type_key] = [
                    '__all_products' => [],
                ];
            }
            $index_lookup[$type_key]['__all_products'][$product_id] = true;
            if ($color_key === '') {
                continue;
            }
            if (!isset($index_lookup[$type_key][$color_key])) {
                $index_lookup[$type_key][$color_key] = [];
            }
            $index_lookup[$type_key][$color_key][] = $entry;
        }

        $regen_requests = [];
        foreach ($new_value as $type) {
            if (!is_array($type) || empty($type['key'])) {
                continue;
            }
            $slug = sanitize_title($type['key']);
            $colors = self::normalize_colors_from_type($type);
            $old_type = isset($old_map[$slug]) ? $old_map[$slug] : null;
            $old_colors = self::normalize_colors_from_type($old_type);
            $new_overrides = self::normalize_overrides_from_type($type);
            $old_overrides = self::normalize_overrides_from_type($old_type);
            $old_hash = md5(wp_json_encode($old_type['views'] ?? []));
            $new_hash = md5(wp_json_encode($type['views'] ?? []));
            if ($old_type && $old_hash !== $new_hash && isset($index_lookup[$slug])) {
                foreach ($index_lookup[$slug] as $color_slug => $entries) {
                    if ($color_slug === '__all_products' || empty($entries)) {
                        continue;
                    }
                    foreach ($entries as $entry) {
                        $regen_requests[] = [
                            'product_id' => $entry['product_id'],
                            'type_slug'  => $slug,
                            'color_slug' => $entry['color_slug'],
                            'reason'     => __('A nézetek módosultak.', 'mgdtp'),
                        ];
                    }
                }
            }
            $override_changes = [];
            $override_color_keys = array_unique(array_merge(array_keys($new_overrides), array_keys($old_overrides)));
            foreach ($override_color_keys as $color_slug) {
                $new_views = isset($new_overrides[$color_slug]) ? $new_overrides[$color_slug] : [];
                $old_views = isset($old_overrides[$color_slug]) ? $old_overrides[$color_slug] : [];
                $view_keys = array_unique(array_merge(array_keys($new_views), array_keys($old_views)));
                foreach ($view_keys as $view_key) {
                    $new_path = isset($new_views[$view_key]) ? $new_views[$view_key] : [];
                    $old_path = isset($old_views[$view_key]) ? $old_views[$view_key] : [];
                    $new_path = is_array($new_path) ? array_values($new_path) : (empty($new_path) ? [] : [wp_normalize_path($new_path)]);
                    $old_path = is_array($old_path) ? array_values($old_path) : (empty($old_path) ? [] : [wp_normalize_path($old_path)]);
                    if ($new_path !== $old_path) {
                        $override_changes[$color_slug] = true;
                        break;
                    }
                }
            }
            if (!empty($override_changes) && isset($index_lookup[$slug])) {
                $changed_colors = array_map('sanitize_title', array_keys($override_changes));
                foreach ($changed_colors as $color_slug) {
                    if ($color_slug === '' || empty($index_lookup[$slug][$color_slug])) {
                        continue;
                    }
                    foreach ($index_lookup[$slug][$color_slug] as $entry) {
                        $regen_requests[] = [
                            'product_id' => $entry['product_id'],
                            'type_slug'  => $slug,
                            'color_slug' => $entry['color_slug'],
                            'reason'     => __('Az alap mockup lecserélésre került.', 'mgdtp'),
                        ];
                    }
                }
            }
            foreach ($colors as $color_slug => $color) {
                $old_color_hash = isset($old_colors[$color_slug]) ? md5(wp_json_encode($old_colors[$color_slug])) : '';
                $new_color_hash = md5(wp_json_encode($color));
                $color_key = sanitize_title($color_slug);
                if ($old_color_hash && $old_color_hash !== $new_color_hash && isset($index_lookup[$slug][$color_key])) {
                    foreach ($index_lookup[$slug][$color_key] as $entry) {
                        $regen_requests[] = [
                            'product_id' => $entry['product_id'],
                            'type_slug'  => $slug,
                            'color_slug' => $color_key,
                            'reason'     => __('A mockup sablonja megváltozott.', 'mgdtp'),
                        ];
                    }
                } elseif (!$old_color_hash && isset($index_lookup[$slug]['__all_products'])) {
                    foreach (array_keys($index_lookup[$slug]['__all_products']) as $product_id) {
                        $regen_requests[] = [
                            'product_id' => $product_id,
                            'type_slug'  => $slug,
                            'color_slug' => $color_key,
                            'reason'     => __('Új szín került hozzáadásra.', 'mgdtp'),
                        ];
                    }
                }
            }
        }
        if (!empty($regen_requests)) {
            self::queue_multiple_for_regeneration($regen_requests);
        }
        return $new_value;
    }
}
