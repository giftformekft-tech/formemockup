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
                $index[$key]['source'] = array_merge($index[$key]['source'] ?? [], [
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
        if (!isset($index[$key])) {
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
        if (!empty($context)) {
            if (isset($context['design_path'])) {
                $context['design_path'] = wp_normalize_path($context['design_path']);
            }
            $index[$key]['source'] = array_merge($index[$key]['source'], $context);
        }
        if (empty($index[$key]['source'])) {
            $index[$key]['source'] = self::inherit_source($index, $product_id, $type_slug);
        }
        $queue = self::get_queue();
        if (!in_array($key, $queue, true)) {
            $queue[] = $key;
        }
        self::set_index($index);
        self::set_queue($queue);
        self::maybe_schedule_processor();
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

    private static function resolve_design_path($entry) {
        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : [];
        $path = isset($source['design_path']) ? $source['design_path'] : '';
        if (!empty($path) && file_exists($path)) {
            return $path;
        }
        if (!empty($source['design_attachment_id']) && function_exists('get_attached_file')) {
            $file = get_attached_file((int) $source['design_attachment_id']);
            if ($file && file_exists($file)) {
                return $file;
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
