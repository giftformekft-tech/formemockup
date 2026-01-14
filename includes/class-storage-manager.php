<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Storage_Manager {
    const MOCKUP_INDEX_TABLE = 'mg_mockup_index';
    const VARIANT_QUEUE_TABLE = 'mg_variant_queue';
    const MANIFEST_SUBDIR = 'mockup-generator';
    const MANIFEST_FILE = 'manifest.json';
    const LEGACY_MOCKUP_INDEX = 'mg_mockup_status_index';
    const LEGACY_MOCKUP_INDEX_META = 'mg_mockup_status_index_meta';
    const LEGACY_MOCKUP_INDEX_CHUNK_PREFIX = 'mg_mockup_status_index_chunk_';
    const LEGACY_VARIANT_QUEUE = 'mg_variant_sync_queue';
    const LEGACY_VARIANT_QUEUE_META = 'mg_variant_sync_queue_meta';
    const LEGACY_VARIANT_QUEUE_CHUNK_PREFIX = 'mg_variant_sync_queue_chunk_';

    public static function init() {
        if (!self::is_enabled()) {
            return;
        }
        add_action('admin_init', [__CLASS__, 'maybe_install']);
    }

    public static function is_enabled() {
        if (defined('MG_DISABLE_STORAGE_MANAGER') && MG_DISABLE_STORAGE_MANAGER) {
            return false;
        }
        return (bool) apply_filters('mg_storage_manager_enabled', true);
    }

    public static function maybe_install() {
        if (!self::is_enabled()) {
            return;
        }
        if (!is_admin() && !defined('WP_CLI')) {
            return;
        }
        if (function_exists('current_user_can')) {
            if (!defined('WP_CLI') && function_exists('is_user_logged_in') && !is_user_logged_in()) {
                return;
            }
            if (!current_user_can('manage_options')) {
                return;
            }
        }
        if (!function_exists('dbDelta')) {
            $upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (!file_exists($upgrade_path)) {
                return;
            }
            require_once $upgrade_path;
            if (!function_exists('dbDelta')) {
                return;
            }
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }
        $charset_collate = $wpdb->get_charset_collate();
        $mockup_table = self::table_name(self::MOCKUP_INDEX_TABLE);
        $queue_table = self::table_name(self::VARIANT_QUEUE_TABLE);

        $mockup_sql = "CREATE TABLE {$mockup_table} (
            entry_key varchar(190) NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            type_slug varchar(191) NOT NULL DEFAULT '',
            color_slug varchar(191) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (entry_key),
            KEY product_id (product_id),
            KEY type_slug (type_slug(191)),
            KEY color_slug (color_slug(191))
        ) {$charset_collate};";

        $queue_sql = "CREATE TABLE {$queue_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type_slug varchar(191) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY type_slug (type_slug(191))
        ) {$charset_collate};";

        dbDelta($mockup_sql);
        dbDelta($queue_sql);

        self::maybe_migrate_mockup_index();
        self::maybe_migrate_variant_queue();
    }

    public static function get_mockup_index() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !self::table_exists(self::MOCKUP_INDEX_TABLE)) {
            return self::get_legacy_mockup_index();
        }
        $table = self::table_name(self::MOCKUP_INDEX_TABLE);
        $rows = $wpdb->get_results("SELECT entry_key, payload FROM {$table}", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $index = [];
        foreach ($rows as $row) {
            $key = isset($row['entry_key']) ? (string) $row['entry_key'] : '';
            if ($key === '') {
                continue;
            }
            $payload = json_decode($row['payload'] ?? '', true);
            if (is_array($payload)) {
                $index[$key] = $payload;
            }
        }
        return $index;
    }

    public static function set_mockup_index($index) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !self::table_exists(self::MOCKUP_INDEX_TABLE)) {
            return self::set_legacy_mockup_index($index);
        }
        if (!is_array($index)) {
            $index = [];
        }
        $table = self::table_name(self::MOCKUP_INDEX_TABLE);
        $wpdb->query("TRUNCATE TABLE {$table}");
        foreach ($index as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $product_id = absint($entry['product_id'] ?? 0);
            $type_slug = sanitize_title($entry['type_slug'] ?? '');
            $color_slug = sanitize_title($entry['color_slug'] ?? '');
            $updated_at = isset($entry['updated_at']) ? (int) $entry['updated_at'] : 0;
            $payload = wp_json_encode($entry);
            $wpdb->insert(
                $table,
                [
                    'entry_key' => (string) $key,
                    'product_id' => $product_id,
                    'type_slug' => $type_slug,
                    'color_slug' => $color_slug,
                    'payload' => $payload,
                    'updated_at' => $updated_at,
                ],
                ['%s', '%d', '%s', '%s', '%s', '%d']
            );
        }
        return true;
    }

    public static function get_variant_queue() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !self::table_exists(self::VARIANT_QUEUE_TABLE)) {
            return self::get_legacy_variant_queue();
        }
        $table = self::table_name(self::VARIANT_QUEUE_TABLE);
        $rows = $wpdb->get_results("SELECT payload FROM {$table} ORDER BY id ASC", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $queue = [];
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'] ?? '', true);
            if (is_array($payload)) {
                $queue[] = $payload;
            }
        }
        return $queue;
    }

    public static function set_variant_queue($queue) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !self::table_exists(self::VARIANT_QUEUE_TABLE)) {
            return self::set_legacy_variant_queue($queue);
        }
        if (!is_array($queue)) {
            $queue = [];
        }
        $table = self::table_name(self::VARIANT_QUEUE_TABLE);
        $wpdb->query("TRUNCATE TABLE {$table}");
        $now = current_time('timestamp', true);
        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type_slug = sanitize_title($entry['type_slug'] ?? '');
            $payload = wp_json_encode($entry);
            $wpdb->insert(
                $table,
                [
                    'type_slug' => $type_slug,
                    'payload' => $payload,
                    'created_at' => $now,
                ],
                ['%s', '%s', '%d']
            );
        }
        return true;
    }

    public static function dedupe_generated_asset($path) {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return $path;
        }
        $hash = @sha1_file($path);
        if (!$hash) {
            return $path;
        }
        $manifest = self::load_manifest();
        $entry = isset($manifest[$hash]) && is_array($manifest[$hash]) ? $manifest[$hash] : [];
        $relative = self::relative_upload_path($path);
        $now = current_time('timestamp', true);
        if (!empty($entry['path'])) {
            $existing_path = self::absolute_upload_path($entry['path']);
            if ($existing_path && file_exists($existing_path)) {
                if ($existing_path !== $path) {
                    @unlink($path);
                }
                return $existing_path;
            }
        }
        $manifest_path = self::manifest_path();
        if ($manifest_path) {
            $manifest[$hash] = [
                'path' => $relative ?: $path,
                'size' => filesize($path),
                'updated_at' => $now,
            ];
            self::save_manifest($manifest);
        }
        return $path;
    }

    private static function load_manifest() {
        $path = self::manifest_path();
        if (!$path || !file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function save_manifest($manifest) {
        if (!is_array($manifest)) {
            return false;
        }
        $path = self::manifest_path();
        if (!$path) {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $json = wp_json_encode($manifest, JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        return file_put_contents($path, $json) !== false;
    }

    private static function manifest_path() {
        $uploads = self::get_uploads_info();
        if (empty($uploads['basedir'])) {
            return '';
        }
        return trailingslashit($uploads['basedir']) . self::MANIFEST_SUBDIR . '/' . self::MANIFEST_FILE;
    }

    private static function relative_upload_path($path) {
        $uploads = self::get_uploads_info();
        $base = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        $path = wp_normalize_path($path);
        if ($base && strpos($path, $base) === 0) {
            return ltrim(substr($path, strlen($base)), '/');
        }
        return '';
    }

    private static function absolute_upload_path($relative) {
        $uploads = self::get_uploads_info();
        $base = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($base === '' || $relative === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($base) . ltrim($relative, '/'));
    }

    private static function maybe_migrate_mockup_index() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !self::table_exists(self::MOCKUP_INDEX_TABLE)) {
            return;
        }
        $table = self::table_name(self::MOCKUP_INDEX_TABLE);
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }
        $legacy = self::get_legacy_mockup_index();
        if (empty($legacy)) {
            return;
        }
        self::set_mockup_index($legacy);
        delete_option(self::LEGACY_MOCKUP_INDEX);
    }

    private static function maybe_migrate_variant_queue() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !self::table_exists(self::VARIANT_QUEUE_TABLE)) {
            return;
        }
        $table = self::table_name(self::VARIANT_QUEUE_TABLE);
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }
        $legacy = self::get_legacy_variant_queue();
        if (empty($legacy)) {
            return;
        }
        self::set_variant_queue($legacy);
        delete_option(self::LEGACY_VARIANT_QUEUE);
    }

    private static function get_legacy_mockup_index() {
        $meta = get_option(self::LEGACY_MOCKUP_INDEX_META, []);
        $meta = is_array($meta) ? $meta : [];
        $chunk_count = isset($meta['chunks']) ? (int) $meta['chunks'] : 0;
        $index = [];
        if ($chunk_count > 0) {
            for ($i = 1; $i <= $chunk_count; $i++) {
                $chunk = get_option(self::LEGACY_MOCKUP_INDEX_CHUNK_PREFIX . $i, []);
                if (is_array($chunk)) {
                    $index += $chunk;
                }
            }
            return $index;
        }
        $legacy = get_option(self::LEGACY_MOCKUP_INDEX, []);
        return is_array($legacy) ? $legacy : [];
    }

    private static function set_legacy_mockup_index($index) {
        update_option(self::LEGACY_MOCKUP_INDEX, $index, false);
        return true;
    }

    private static function get_legacy_variant_queue() {
        $meta = get_option(self::LEGACY_VARIANT_QUEUE_META, []);
        $meta = is_array($meta) ? $meta : [];
        $chunk_count = isset($meta['chunks']) ? (int) $meta['chunks'] : 0;
        $queue = [];
        if ($chunk_count > 0) {
            for ($i = 1; $i <= $chunk_count; $i++) {
                $chunk = get_option(self::LEGACY_VARIANT_QUEUE_CHUNK_PREFIX . $i, []);
                if (is_array($chunk)) {
                    $queue = array_merge($queue, $chunk);
                }
            }
            return array_values($queue);
        }
        $legacy = get_option(self::LEGACY_VARIANT_QUEUE, []);
        return is_array($legacy) ? array_values($legacy) : [];
    }

    private static function set_legacy_variant_queue($queue) {
        update_option(self::LEGACY_VARIANT_QUEUE, array_values((array) $queue), false);
        return true;
    }

    private static function table_exists($table_name) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }
        $table = self::table_name($table_name);
        $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return $found === $table;
    }

    private static function table_name($table_name) {
        global $wpdb;
        return $wpdb->prefix . $table_name;
    }

    private static function get_uploads_info() {
        $uploads = wp_upload_dir();
        if (is_wp_error($uploads)) {
            return [];
        }
        return is_array($uploads) ? $uploads : [];
    }
}
