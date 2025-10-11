<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Mockup_Maintenance {
    const OPTION_STATUS_INDEX = 'mg_mockup_status_index';
    const OPTION_QUEUE = 'mg_mockup_regen_queue';
    const OPTION_ACTIVITY_LOG = 'mg_mockup_activity_log';
    const DEFAULT_BATCH = 3;
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
        return $index;
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

    private static function compose_key($product_id, $type_slug, $color_slug) {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        return $product_id . '|' . $type_slug . '|' . $color_slug;
    }

    private static function current_timestamp() {
        return current_time('timestamp', true);
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
            if ($color_slug === '' || !is_array($files)) {
                continue;
            }
            foreach ($files as $view_key => $path) {
                if (!is_string($view_key)) {
                    continue;
                }
                $view_key = trim($view_key);
                if ($view_key === '') {
                    continue;
                }
                $path = is_string($path) ? trim($path) : '';
                if ($path === '') {
                    continue;
                }
                $out[$color_slug][$view_key] = wp_normalize_path($path);
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
        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : [];
        $selected_snapshot = isset($source['selected_products']) ? $source['selected_products'] : [];
        $selected_payload = self::prepare_selected_payload_for_color($selected_snapshot, $type_slug, $color_slug);
        if (empty($selected_payload)) {
            return;
        }
        $defaults_source = isset($source['defaults']) ? $source['defaults'] : [];
        $defaults_source = self::sanitize_default_attributes($defaults_source);
        $product_defaults = self::sanitize_default_attributes($product->get_default_attributes());
        $merged_defaults = array_merge($product_defaults, $defaults_source);
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
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return;
        }
        $key = self::compose_key($product_id, $type_slug, $color_slug);
        $index = self::get_index();
        $timestamp = self::current_timestamp();
        $is_new_entry = !isset($index[$key]);
        if ($is_new_entry) {
            $index[$key] = [
                'product_id'   => $product_id,
                'type_slug'    => sanitize_title($type_slug),
                'color_slug'   => sanitize_title($color_slug),
                'status'       => 'pending',
                'updated_at'   => $timestamp,
                'last_generated' => 0,
                'last_message' => '',
                'pending_reason' => sanitize_text_field($reason),
                'source'       => [],
            ];
        } else {
            $index[$key]['status'] = 'pending';
            $index[$key]['updated_at'] = $timestamp;
            $index[$key]['pending_reason'] = sanitize_text_field($reason);
        }
        $source = isset($index[$key]['source']) && is_array($index[$key]['source']) ? $index[$key]['source'] : [];
        if (empty($source)) {
            $source = self::inherit_source($index, $product_id, $type_slug);
        }
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
        $index[$key]['source'] = $source;
        $queue = self::get_queue();
        if (!in_array($key, $queue, true)) {
            $queue[] = $key;
        }
        self::set_index($index);
        self::set_queue($queue);
        self::maybe_schedule_processor();
        if ($is_new_entry && isset($index[$key])) {
            self::ensure_product_has_color_variant($index[$key]);
        }
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
            $path = wp_normalize_path($overrides[$color_slug][$view_key]);
            if ($path && file_exists($path)) {
                $images[] = $path;
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
        $index = self::get_index();
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

    public static function process_queue() {
        $queue = self::get_queue();
        if (empty($queue)) {
            return;
        }
        $batch = array_slice($queue, 0, self::DEFAULT_BATCH);
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
        $new_attachment_ids = [];
        foreach ($files as $file) {
            $attachment_id = self::attach_image($file);
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

    private static function attach_image($path) {
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
        $attachment = [
            'guid'           => trailingslashit($wp_upload_dir['url']) . basename($path),
            'post_mime_type' => $filetype['type'] ?? 'image/webp',
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($path)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $path);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = ['file' => _wp_relative_upload_path($path)];
        wp_update_attachment_metadata($attach_id, $attach_data);
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
            if ($old_type && $old_hash !== $new_hash) {
                foreach ($index_snapshot as $entry) {
                    if (($entry['type_slug'] ?? '') === $slug) {
                        self::queue_for_regeneration($entry['product_id'], $slug, $entry['color_slug'], __('A nézetek módosultak.', 'mgdtp'));
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
                    $new_path = isset($new_views[$view_key]) ? $new_views[$view_key] : '';
                    $old_path = isset($old_views[$view_key]) ? $old_views[$view_key] : '';
                    if ($new_path !== $old_path) {
                        $override_changes[$color_slug] = true;
                        break;
                    }
                }
            }
            if (!empty($override_changes)) {
                $changed_colors = array_keys($override_changes);
                foreach ($index_snapshot as $entry) {
                    if (($entry['type_slug'] ?? '') !== $slug) {
                        continue;
                    }
                    if (!in_array($entry['color_slug'] ?? '', $changed_colors, true)) {
                        continue;
                    }
                    self::queue_for_regeneration($entry['product_id'], $slug, $entry['color_slug'], __('Az alap mockup lecserélésre került.', 'mgdtp'));
                }
            }
            foreach ($colors as $color_slug => $color) {
                $old_color_hash = isset($old_colors[$color_slug]) ? md5(wp_json_encode($old_colors[$color_slug])) : '';
                $new_color_hash = md5(wp_json_encode($color));
                if ($old_color_hash && $old_color_hash !== $new_color_hash) {
                    foreach ($index_snapshot as $entry) {
                        if (($entry['type_slug'] ?? '') === $slug && ($entry['color_slug'] ?? '') === $color_slug) {
                            self::queue_for_regeneration($entry['product_id'], $slug, $color_slug, __('A mockup sablonja megváltozott.', 'mgdtp'));
                        }
                    }
                } elseif (!$old_color_hash) {
                    foreach ($index_snapshot as $entry) {
                        if (($entry['type_slug'] ?? '') === $slug) {
                            self::queue_for_regeneration($entry['product_id'], $slug, $color_slug, __('Új szín került hozzáadásra.', 'mgdtp'));
                        }
                    }
                }
            }
        }
        return $new_value;
    }
}
