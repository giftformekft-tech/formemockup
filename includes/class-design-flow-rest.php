<?php
/**
 * Lets Design Flow Editor send finished designs into the bulk queue.
 *
 * The routes only translate the request: the product is made by the same
 * MG_Bulk_Queue job as on the bulk upload page, which stays unchanged.
 * Authentication is a WordPress application password of a user with edit_products.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Design_Flow_Rest {
    const REST_NAMESPACE = 'design-flow/v1';
    const REQUEST_PREFIX = 'mg_df_request_';
    const DRAFT_PREFIX = 'mg_df_draft_';
    const MAX_NAMES = 1000;
    const MAX_PERSONALIZATION_BYTES = 20000;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('mg_bulk_queue_product_created', array(__CLASS__, 'apply_requested_status'), 10, 2);
    }

    public static function register_routes() {
        $routes = array(
            array('/config', 'GET', 'config'),
            array('/designs', 'POST', 'enqueue'),
            array('/designs/existing', 'POST', 'existing'),
            array('/designs/status', 'POST', 'status'),
        );
        foreach ($routes as $route) {
            register_rest_route(self::REST_NAMESPACE, $route[0], array(
                'methods' => $route[1],
                'callback' => array(__CLASS__, $route[2]),
                'permission_callback' => array(__CLASS__, 'can_edit_products'),
            ));
        }
    }

    public static function can_edit_products() {
        return current_user_can('edit_products');
    }

    /** Product types, categories and presets, for the upload form in the program. */
    public static function config() {
        $products = array();
        foreach ((array) get_option('mg_products', array()) as $product) {
            if (!is_array($product) || empty($product['key'])) {
                continue;
            }
            $colors = array();
            foreach ((array) ($product['colors'] ?? array()) as $color) {
                $colors[] = is_array($color) ? (string) ($color['slug'] ?? '') : (string) $color;
            }
            $products[] = array(
                'key' => (string) $product['key'],
                'label' => wp_strip_all_tags((string) ($product['label'] ?? $product['key'])),
                'colors' => array_values(array_filter($colors)),
                'sizes' => array_values(array_map('strval', (array) ($product['sizes'] ?? array()))),
            );
        }
        $presets = array();
        if (class_exists('MG_Custom_Fields_Manager')) {
            foreach (MG_Custom_Fields_Manager::get_presets() as $preset_id => $preset) {
                if (isset($preset['name'])) {
                    $presets[] = array('id' => $preset_id, 'name' => $preset['name']);
                }
            }
        }
        return rest_ensure_response(array(
            'version' => defined('MG_VERSION') ? MG_VERSION : '',
            'products' => $products,
            'categories' => self::category_tree(),
            'presets' => $presets,
        ));
    }

    private static function category_tree() {
        $tree = array();
        if (!taxonomy_exists('product_cat')) {
            return $tree;
        }
        $mains = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0));
        if (is_wp_error($mains)) {
            return $tree;
        }
        foreach ($mains as $main) {
            $children = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $main->term_id));
            $subs = array();
            if (!is_wp_error($children)) {
                foreach ($children as $child) {
                    $subs[] = array('id' => (int) $child->term_id, 'name' => wp_strip_all_tags($child->name));
                }
            }
            $tree[] = array('id' => (int) $main->term_id, 'name' => wp_strip_all_tags($main->name), 'subs' => $subs);
        }
        return $tree;
    }

    private static function normalize_label($value) {
        $value = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $value)));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** Category names to ids, the same match the bulk page makes on its select lists. */
    private static function categories_from_labels($main_label, $sub_label) {
        $main_id = 0;
        $sub_ids = array();
        $wanted_main = self::normalize_label($main_label);
        $wanted_sub = self::normalize_label($sub_label);
        if ($wanted_main === '') {
            return array('main' => 0, 'subs' => array());
        }
        foreach (self::category_tree() as $main) {
            if (self::normalize_label($main['name']) !== $wanted_main) {
                continue;
            }
            $main_id = $main['id'];
            foreach ($main['subs'] as $sub) {
                if ($wanted_sub !== '' && self::normalize_label($sub['name']) === $wanted_sub) {
                    $sub_ids[] = $sub['id'];
                }
            }
            break;
        }
        return array('main' => $main_id, 'subs' => $sub_ids);
    }

    private static function personalization_selection($raw) {
        if (!is_string($raw) || $raw === '') {
            return array('mode' => 'manual', 'custom' => false, 'preset_id' => '');
        }
        if (strlen($raw) > self::MAX_PERSONALIZATION_BYTES) {
            return array('mode' => 'manual', 'custom' => false, 'preset_id' => '');
        }
        $data = json_decode($raw, true);
        $resolved = MG_Personalization_Import::resolve($data);
        if (($resolved['state'] ?? '') !== 'matched') {
            // An unclear match must not guess a preset: it stays a plain product.
            return array('mode' => 'manual', 'custom' => false, 'preset_id' => '');
        }
        return array('mode' => 'auto', 'custom' => true, 'preset_id' => $resolved['preset_id'], 'data' => $data);
    }

    private static function require_bulk_helpers() {
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('mg_bulk_resolve_defaults')) {
            require_once plugin_dir_path(__FILE__) . '../admin/ajax-bulk.php';
        }
        if (!class_exists('MG_Bulk_Queue')) {
            require_once plugin_dir_path(__FILE__) . 'class-bulk-queue.php';
        }
    }

    public static function enqueue(WP_REST_Request $request) {
        self::require_bulk_helpers();
        $request_id = sanitize_key((string) $request->get_param('request_id'));
        $guard = $request_id !== '' ? self::REQUEST_PREFIX . $request_id : '';
        if ($guard !== '') {
            $previous = get_transient($guard);
            if (is_string($previous) && $previous !== '') {
                return rest_ensure_response(array('job_id' => $previous, 'already_queued' => true));
            }
        }

        $keys = array_values(array_map('sanitize_text_field', (array) $request->get_param('product_keys')));
        if (empty($keys)) {
            return new WP_Error('mg_df_keys', 'Nincs kiválasztott terméktípus.', array('status' => 400));
        }
        $all = (array) get_option('mg_products', array());
        $selected = array_values(array_filter($all, function ($product) use ($keys) {
            return is_array($product) && in_array($product['key'] ?? '', $keys, true);
        }));
        if (empty($selected)) {
            return new WP_Error('mg_df_keys', 'A kiválasztott terméktípusok nem találhatók.', array('status' => 400));
        }

        $files = $request->get_file_params();
        if (empty($files['design_file']['tmp_name'])) {
            return new WP_Error('mg_df_file', 'Hiányzó design fájl.', array('status' => 400));
        }
        $uploaded = wp_handle_upload($files['design_file'], array(
            'test_form' => false,
            'mimes' => array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'),
        ));
        if (isset($uploaded['error'])) {
            return new WP_Error('mg_df_upload', 'Feltöltési hiba: ' . $uploaded['error'], array('status' => 400));
        }

        $name = sanitize_text_field((string) $request->get_param('product_name'));
        if ($name === '') {
            $name = pathinfo($uploaded['file'], PATHINFO_FILENAME);
        }
        $selection = self::personalization_selection($request->get_param('personalization'));
        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $request->get_param('tags'))))));
        $job_id = MG_Bulk_Queue::enqueue(array(
            'design_path' => $uploaded['file'],
            'product_keys' => $keys,
            'parent_id' => 0,
            'parent_name' => $name,
            'batch_id' => '',
            'categories' => self::categories_from_labels($request->get_param('main_label'), $request->get_param('sub_label')),
            'defaults' => mg_bulk_resolve_defaults(
                $selected,
                sanitize_text_field((string) $request->get_param('primary_type')),
                sanitize_text_field((string) $request->get_param('primary_color')),
                sanitize_text_field((string) $request->get_param('primary_size'))
            ),
            'tags' => $tags,
            'custom_product' => !empty($selection['custom']) ? 1 : 0,
            'preset_id' => $selection['preset_id'] ?? '',
            'personalization_selection' => $selection,
            'sample_seo' => wp_kses_post((string) $request->get_param('sample_seo')),
            'trigger' => 'design_flow',
        ), true);

        if ($request->get_param('publish_status') !== 'publish') {
            set_transient(self::DRAFT_PREFIX . md5($name), 1, 7 * DAY_IN_SECONDS);
        } else {
            // An earlier draft request of the same name must not hide this one.
            delete_transient(self::DRAFT_PREFIX . md5($name));
        }
        if ($guard !== '') {
            set_transient($guard, $job_id, 30 * DAY_IN_SECONDS);
        }
        return rest_ensure_response(array('job_id' => $job_id));
    }

    /** Runs when the queue finished a new product: keeps it a draft if that was asked for. */
    public static function apply_requested_status($product_id, $payload) {
        $key = self::DRAFT_PREFIX . md5((string) ($payload['parent_name'] ?? ''));
        if (!get_transient($key)) {
            return;
        }
        delete_transient($key);
        wp_update_post(array('ID' => (int) $product_id, 'post_status' => 'draft'));
    }

    public static function existing(WP_REST_Request $request) {
        self::require_bulk_helpers();
        $requested = array();
        foreach (array_slice((array) $request->get_param('names'), 0, self::MAX_NAMES) as $name) {
            $name = sanitize_text_field((string) $name);
            $normalized = mg_bulk_normalize_product_name($name);
            if ($normalized !== '') {
                $requested[$normalized] = $name;
            }
        }
        if (empty($requested)) {
            return rest_ensure_response(array('names' => array()));
        }
        global $wpdb;
        $names = array_values($requested);
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft') AND post_title IN ({$placeholders})",
            $names
        ));
        $found = array();
        foreach ((array) $rows as $row) {
            $normalized = mg_bulk_normalize_product_name($row->post_title ?? '');
            if (isset($requested[$normalized])) {
                $found[$normalized] = $requested[$normalized];
            }
        }
        return rest_ensure_response(array('names' => array_values($found)));
    }

    public static function status(WP_REST_Request $request) {
        self::require_bulk_helpers();
        $ids = array_slice(array_map('sanitize_text_field', (array) $request->get_param('job_ids')), 0, self::MAX_NAMES);
        return rest_ensure_response(MG_Bulk_Queue::get_status($ids));
    }
}
