<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Bulk_Queue {
    const JOB_OPTION_PREFIX = 'mg_bulk_job_';
    const ORDER_OPTION = 'mg_bulk_queue_order';
    const WORKER_TOKEN_OPTION = 'mg_bulk_worker_token';
    const WORKER_COUNT_OPTION = 'mg_bulk_worker_count';
    const WORKER_COUNT_CHOICES = array(1, 2, 3, 4, 6, 8);
    const DEFAULT_WORKER_COUNT = 1;
    const ACTIVE_WORKERS_OPTION = 'mg_bulk_active_workers';
    const ACTIVE_WORKER_TTL = 300;
    const ACTIVE_WORKER_HEARTBEAT = 30;
    const DISPATCH_LOCK = 'mg_bulk_dispatch_lock';
    const LOCK_TTL = 180; // seconds
    const STALE_TTL = 300; // seconds
    const MAX_JOBS_PER_WORKER = 10;
    const CRON_HOOK = 'mg_bulk_process_queue';
    const CRON_LOCK = 'mg_bulk_queue_lock';
    const OPTION_BATCH_SIZE = 'mg_bulk_queue_batch_size';
    const OPTION_INTERVAL_MINUTES = 'mg_bulk_queue_interval_minutes';
    const DEFAULT_BATCH = 5;
    const MIN_BATCH = 1;
    const MAX_BATCH = 50;
    const DEFAULT_INTERVAL = 2;
    const MIN_INTERVAL = 1;
    const MAX_INTERVAL = 60;

    public static function init() {
        add_action('init', [__CLASS__, 'register_cron_schedule']);
        add_filter('cron_schedules', [__CLASS__, 'register_interval']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
    }

    public static function register_interval($schedules) {
        $interval = self::get_interval_minutes();
        $schedules['mg_bulk_interval'] = [
            'interval' => $interval * MINUTE_IN_SECONDS,
            /* translators: %d: interval minutes */
            'display'  => sprintf(__('Bulk queue (%d perc)', 'mgdtp'), $interval),
        ];
        return $schedules;
    }

    public static function register_cron_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'mg_bulk_interval', self::CRON_HOOK);
        }
    }

    private static function maybe_schedule_processor() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            self::register_cron_schedule();
        }
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

    public static function get_interval_minutes() {
        $stored = get_option(self::OPTION_INTERVAL_MINUTES, self::DEFAULT_INTERVAL);
        $normalized = self::normalize_interval_minutes($stored);
        if ((int) $stored !== $normalized) {
            update_option(self::OPTION_INTERVAL_MINUTES, $normalized, false);
        }
        return $normalized;
    }

    public static function set_interval_minutes($value) {
        $normalized = self::normalize_interval_minutes($value);
        update_option(self::OPTION_INTERVAL_MINUTES, $normalized, false);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        self::register_cron_schedule();
        return $normalized;
    }

    private static function normalize_interval_minutes($value) {
        $value = absint($value);
        if ($value < self::MIN_INTERVAL) {
            return self::MIN_INTERVAL;
        }
        if ($value > self::MAX_INTERVAL) {
            return self::MAX_INTERVAL;
        }
        return $value;
    }

    public static function get_allowed_worker_counts() {
        $choices = apply_filters('mg_bulk_worker_counts', self::WORKER_COUNT_CHOICES);
        $choices = array_map('intval', (array) $choices);
        $choices = array_values(array_filter(array_unique($choices), function($value) {
            return $value > 0;
        }));
        if (empty($choices)) {
            $choices = array(self::DEFAULT_WORKER_COUNT);
        }
        sort($choices);
        return $choices;
    }

    private static function normalize_worker_count($count) {
        $count = intval($count);
        $allowed = self::get_allowed_worker_counts();
        if (in_array($count, $allowed, true)) {
            return $count;
        }
        if (in_array(self::DEFAULT_WORKER_COUNT, $allowed, true)) {
            return self::DEFAULT_WORKER_COUNT;
        }
        return reset($allowed);
    }

    public static function get_configured_worker_count() {
        $saved = get_option(self::WORKER_COUNT_OPTION, null);
        if ($saved === null || $saved === false) {
            return self::normalize_worker_count(self::DEFAULT_WORKER_COUNT);
        }
        return self::normalize_worker_count($saved);
    }

    public static function update_worker_count($count) {
        $normalized = self::normalize_worker_count($count);
        update_option(self::WORKER_COUNT_OPTION, $normalized, false);
        return $normalized;
    }

    public static function enqueue(array $payload, $dispatch = true) {
        $job_id = 'mgjob_' . wp_generate_uuid4();
        $job = array(
            'id' => $job_id,
            'status' => 'pending',
            'message' => __('Sorban áll', 'mgdtp'),
            'created_at' => time(),
            'started_at' => null,
            'finished_at' => null,
            'worker' => '',
            'result' => array(),
            'payload' => self::sanitize_payload($payload),
        );
        update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);

        $order = get_option(self::ORDER_OPTION, array());
        if (!is_array($order)) {
            $order = array();
        }
        $order[] = $job_id;
        update_option(self::ORDER_OPTION, array_values(array_unique($order)), false);

        if ($dispatch) {
            self::dispatch_workers();
        } else {
            self::maybe_schedule_processor();
        }

        return $job_id;
    }

    public static function get_status(array $job_ids) {
        self::maybe_recover_stalled_jobs();

        $jobs = array();
        $queued = 0; $running = 0; $completed = 0; $failed = 0;
        foreach ($job_ids as $job_id_raw) {
            $job_id = sanitize_text_field($job_id_raw);
            $data = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
            if (!is_array($data)) {
                $jobs[] = array(
                    'id' => $job_id,
                    'status' => 'missing',
                    'message' => __('Ismeretlen feladat', 'mgdtp'),
                    'product_id' => 0,
                );
                continue;
            }
            $status = isset($data['status']) ? $data['status'] : 'pending';
            if ($status === 'pending') { $queued++; }
            elseif ($status === 'running') { $running++; }
            elseif ($status === 'completed') { $completed++; }
            elseif ($status === 'failed') { $failed++; }
            $jobs[] = array(
                'id' => $job_id,
                'status' => $status,
                'message' => isset($data['message']) ? $data['message'] : '',
                'product_id' => isset($data['result']['product_id']) ? intval($data['result']['product_id']) : 0,
            );
        }
        $total = count($job_ids);
        $percent = 0;
        if ($total > 0) {
            $percent = round((($completed + $failed) / $total) * 100);
            if ($percent > 100) { $percent = 100; }
            if ($percent < 0) { $percent = 0; }
        }
        return array(
            'jobs' => $jobs,
            'stats' => array(
                'total' => $total,
                'queued' => $queued,
                'running' => $running,
                'completed' => $completed,
                'failed' => $failed,
                'percent' => $percent,
            ),
        );
    }

    public static function dispatch_workers($count = null, $force = false) {
        if ($count === null) {
            $count = self::get_configured_worker_count();
        } else {
            $count = max(1, intval($count));
            $allowed = self::get_allowed_worker_counts();
            if (!in_array($count, $allowed, true)) {
                $count = self::get_configured_worker_count();
            }
        }
        if ($count < 1) { $count = 1; }
        if (!$force && false !== get_transient(self::DISPATCH_LOCK)) {
            return;
        }
        if ($force) {
            delete_transient(self::DISPATCH_LOCK);
        }
        self::maybe_recover_stalled_jobs();
        $active_workers = self::prune_stale_workers();
        $active_count = count($active_workers);
        $pending_jobs = self::count_pending_jobs();
        if ($pending_jobs <= 0) {
            return;
        }
        $available_slots = $count - $active_count;
        if ($available_slots < 1) {
            return;
        }
        $needed = min($pending_jobs, $available_slots);
        if ($needed < 1) {
            return;
        }
        set_transient(self::DISPATCH_LOCK, 1, 5);
        $token = self::get_worker_token();
        $url = admin_url('admin-ajax.php');
        $args = array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'action' => 'mg_bulk_worker',
                'token' => $token,
            ),
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        );
        for ($i = 0; $i < $needed; $i++) {
            wp_remote_post($url, $args);
        }
    }

    public static function run_worker_request($token) {
        if (!self::is_valid_token($token)) {
            status_header(403);
            echo 'forbidden';
            wp_die();
        }
        $worker_id = uniqid('worker_', true);
        self::mark_worker_active($worker_id, true);
        $processed = 0;
        try {
            while ($processed < self::MAX_JOBS_PER_WORKER) {
                self::mark_worker_active($worker_id);
                $job = self::claim_next_job($worker_id);
                if (!$job) {
                    break;
                }
                self::mark_worker_active($worker_id, true);
                self::execute_job($job, $worker_id);
                $processed++;
            }
        } finally {
            self::unregister_worker($worker_id);
        }
        if (self::has_pending_jobs()) {
            self::dispatch_workers(null, true);
        }
        wp_die();
    }

    public static function process_queue() {
        if (get_transient(self::CRON_LOCK)) {
            return;
        }
        set_transient(self::CRON_LOCK, 1, 300);
        $worker_id = uniqid('cron_', true);
        self::mark_worker_active($worker_id, true);
        try {
            $batch = self::get_batch_size();
            $processed = 0;
            while ($processed < $batch) {
                self::mark_worker_active($worker_id);
                $job = self::claim_next_job($worker_id);
                if (!$job) {
                    break;
                }
                self::execute_job($job, $worker_id, false);
                $processed++;
            }
        } finally {
            self::unregister_worker($worker_id);
            delete_transient(self::CRON_LOCK);
        }
        if (self::has_pending_jobs()) {
            self::maybe_schedule_processor();
        }
    }

    private static function claim_next_job($worker_id) {
        $order = get_option(self::ORDER_OPTION, array());
        if (!is_array($order) || empty($order)) {
            return null;
        }
        foreach ($order as $job_id) {
            $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
            if (!is_array($job)) {
                continue;
            }
            if (isset($job['status']) && $job['status'] !== 'pending') {
                continue;
            }
            if (false !== get_transient(self::lock_key($job_id))) {
                continue;
            }
            if (!set_transient(self::lock_key($job_id), $worker_id, self::LOCK_TTL)) {
                continue;
            }
            $job['status'] = 'running';
            $job['message'] = __('Feldolgozás…', 'mgdtp');
            $job['started_at'] = time();
            $job['worker'] = $worker_id;
            update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);
            return $job;
        }
        return null;
    }

    private static function execute_job(array $job, $worker_id, $dispatch_after = true) {
        $job_id = $job['id'];
        self::mark_worker_active($worker_id, true);
        try {
            $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : array();
            $design_path = isset($payload['design_path']) ? $payload['design_path'] : '';
            if (!$design_path || !file_exists($design_path)) {
                throw new RuntimeException(__('A design fájl nem található.', 'mgdtp'));
            }
            $product_keys = isset($payload['product_keys']) ? (array)$payload['product_keys'] : array();
            if (empty($product_keys)) {
                throw new RuntimeException(__('Nincs terméktípus megadva.', 'mgdtp'));
            }
            
            // FIX: Use global catalog (PHP file) instead of database
            // This uses the same source as the frontend (global-attributes.php or mg_products fallback)
            $all = function_exists('mg_get_catalog_products') ? mg_get_catalog_products() : get_option('mg_products', array());
            
            $selected = array_values(array_filter(is_array($all) ? $all : array(), function($p) use ($product_keys){
                return is_array($p) && !empty($p['key']) && in_array($p['key'], $product_keys, true);
            }));
            if (empty($selected)) {
                throw new RuntimeException(__('A kiválasztott terméktípusok nem elérhetők.', 'mgdtp'));
            }

            $base_dir = plugin_dir_path(__FILE__);
            if (!class_exists('MG_Generator')) {
                $gen_file = $base_dir . 'class-generator.php';
                if (!file_exists($gen_file)) {
                    throw new RuntimeException(__('A generator osztály nem található.', 'mgdtp'));
                }
                require_once $gen_file;
            }
            if (!class_exists('MG_Product_Creator')) {
                $creator_file = $base_dir . 'class-product-creator.php';
                if (!file_exists($creator_file)) {
                    throw new RuntimeException(__('A termékkészítő osztály nem található.', 'mgdtp'));
                }
                require_once $creator_file;
            }
            if (!class_exists('MG_Custom_Fields_Manager')) {
                $cf_file = $base_dir . 'class-custom-fields-manager.php';
                if (!file_exists($cf_file)) {
                    throw new RuntimeException(__('A custom mező kezelő nem található.', 'mgdtp'));
                }
                require_once $cf_file;
            }

            remove_all_actions('save_post_product');

            // Extract parent info FIRST (needed for SKU-based generation)
            $parent_id = isset($payload['parent_id']) ? intval($payload['parent_id']) : 0;
            $parent_name = isset($payload['parent_name']) ? $payload['parent_name'] : '';
            if ($parent_name === '') {
                $parent_name = basename($design_path);
            }
            
            $defaults = isset($payload['defaults']) && is_array($payload['defaults']) ? $payload['defaults'] : array('type' => '', 'color' => '', 'size' => '');
            $cats = isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : array();
            $cats = array(
                'main' => isset($cats['main']) ? intval($cats['main']) : 0,
                'subs' => isset($cats['subs']) ? array_map('intval', (array)$cats['subs']) : array(),
            );
            $context_trigger = isset($payload['trigger']) ? sanitize_key($payload['trigger']) : 'bulk_queue';

            $creator = new MG_Product_Creator();
            $result_product_id = 0;
            
            // TWO-PHASE GENERATION FOR NEW PRODUCTS
            if ($parent_id > 0) {
                // EXISTING PRODUCT - can generate mockups immediately (SKU exists)
                $generator = new MG_Generator();
                $images_by_type_color = array();
                
                $generation_context_base = array(
                    'product_id' => $parent_id,
                    'design_path' => $design_path,
                );
                
                foreach ($selected as $prod) {
                    $res = $generator->generate_for_product($prod['key'], $design_path, $generation_context_base);
                    if (is_wp_error($res)) {
                        throw new RuntimeException($res->get_error_message());
                    }
                    $images_by_type_color[$prod['key']] = $res;
                }
                
                if (empty($images_by_type_color)) {
                    throw new RuntimeException(__('Nem sikerült mockupot generálni.', 'mgdtp'));
                }
                
                $generation_context = array(
                    'design_path' => $design_path,
                    'trigger' => $context_trigger,
                );
                
                $res = $creator->add_type_to_existing_parent($parent_id, $selected, $images_by_type_color, $parent_name, $cats, $defaults, $generation_context);
                if (is_wp_error($res)) {
                    throw new RuntimeException($res->get_error_message());
                }
                $result_product_id = intval($parent_id);
                
            } else {
                // NEW PRODUCT - Two-phase generation
                
                // PHASE 1: Create product WITHOUT mockups (skip_register_maintenance flag)
                $generation_context_phase1 = array(
                    'design_path' => $design_path,
                    'trigger' => $context_trigger,
                    'skip_register_maintenance' => true,  // Don't register mockups yet
                );
                
                // Create product with empty images array (product creation generates SKU)
                $empty_images = array();
                foreach ($selected as $prod) {
                    $empty_images[$prod['key']] = array();
                }
                
                $result_product_id = $creator->create_parent_with_type_color_size_webp_fast(
                    $parent_name, 
                    $selected, 
                    $empty_images,  // Empty - no mockups yet
                    $cats, 
                    $defaults, 
                    $generation_context_phase1
                );
                
                if (is_wp_error($result_product_id)) {
                    throw new RuntimeException($result_product_id->get_error_message());
                }
                $result_product_id = intval($result_product_id);
                
                if ($result_product_id <= 0) {
                    throw new RuntimeException(__('Nem sikerült terméket létrehozni.', 'mgdtp'));
                }
                
                // PHASE 2: Generate mockups with product_id/SKU
                
                // DEBUG: Verify SKU before generation
                $product_object = function_exists('wc_get_product') ? wc_get_product($result_product_id) : null;
                $actual_sku = $product_object ? $product_object->get_sku() : '';
                
                if (!$actual_sku || strpos($actual_sku, 'FORME') !== 0) {
                    error_log("[MG Bulk Queue] WARNING: Product $result_product_id has invalid SKU: " . var_export($actual_sku, true));
                    error_log("[MG Bulk Queue] Expected FORME prefix SKU. Attempting to regenerate...");
                    
                    // Force regenerate SKU
                    if (class_exists('MG_Product_Creator')) {
                        delete_post_meta($result_product_id, '_sku');
                        $new_sku = MG_Product_Creator::generate_product_sku($result_product_id, $parent_name);
                        if ($new_sku && $product_object) {
                            $product_object->set_sku($new_sku);
                            $product_object->save();
                            
                            // CLEAR CACHE TO ENSURE VISIBILITY
                            clean_post_cache($result_product_id);
                            $product_object = wc_get_product($result_product_id); // Reload
                            
                            $actual_sku = $new_sku;
                            error_log("[MG Bulk Queue] SKU regenerated successfully: $new_sku");
                        }
                    }
                }
                
                error_log("[MG Bulk Queue] Phase 2 starting with product_id=$result_product_id, SKU=$actual_sku");
                
                $generator = new MG_Generator();
                $images_by_type_color = array();
                
                $generation_context_base = array(
                    'product_id' => $result_product_id,
                    'product_sku' => $actual_sku, // Explicitly pass SKU to ensure correct file paths
                    'design_path' => $design_path,
                );
                
                // DEBUG: Log context to verify SKU presence
                error_log("[MG Bulk Queue] Context prepared for generator: " . print_r($generation_context_base, true));
                
                foreach ($selected as $prod) {
                    $res = $generator->generate_for_product($prod['key'], $design_path, $generation_context_base);
                    if (is_wp_error($res)) {
                        throw new RuntimeException($res->get_error_message());
                    }
                    $images_by_type_color[$prod['key']] = $res;
                }
                
                if (empty($images_by_type_color)) {
                    throw new RuntimeException(__('Nem sikerült mockupot generálni.', 'mgdtp'));
                }
                
                // PHASE 3: Register mockups in maintenance index
                if (class_exists('MG_Mockup_Maintenance')) {
                    $maintenance_context = array(
                        'design_path' => $design_path,
                        'trigger' => $context_trigger,
                    );
                    MG_Mockup_Maintenance::register_generation($result_product_id, $selected, $images_by_type_color, $maintenance_context);
                }
                
                // PHASE 4: Set featured image from generated mockups
                $product = function_exists('wc_get_product') ? wc_get_product($result_product_id) : null;
                if ($product) {
                    $creator_reflection = new ReflectionClass($creator);
                    $method = $creator_reflection->getMethod('maybe_set_default_featured_image');
                    $method->setAccessible(true);
                    
                    $resolved_defaults = $creator_reflection->getMethod('resolve_default_combo');
                    $resolved_defaults->setAccessible(true);
                    $defaults_result = $resolved_defaults->invoke($creator, $selected, $defaults['type'] ?? '', $defaults['color'] ?? '', $defaults['size'] ?? '');
                    
                    $maintenance_context = array(
                        'design_path' => $design_path,
                        'trigger' => $context_trigger,
                    );
                    
                    $method->invoke($creator, $result_product_id, $defaults_result, $selected, $cats, $maintenance_context);
                }
            }

            if ($result_product_id > 0 && !empty($payload['tags']) && taxonomy_exists('product_tag')) {
                $tags = array_map('sanitize_text_field', (array)$payload['tags']);
                $tags = array_values(array_filter(array_unique($tags))); 
                if (!empty($tags)) {
                    wp_set_object_terms($result_product_id, $tags, 'product_tag', true);
                }
            }
            if ($result_product_id > 0 && !empty($payload['custom_product'])) {
                MG_Custom_Fields_Manager::set_custom_product($result_product_id, true);
            } elseif ($result_product_id > 0) {
                MG_Custom_Fields_Manager::set_custom_product($result_product_id, false);
            }

            self::complete_job($job_id, array(
                'product_id' => $result_product_id,
            ), $dispatch_after);
        } catch (Throwable $e) {
            self::fail_job($job_id, $e->getMessage(), $dispatch_after);
        }
    }

    private static function complete_job($job_id, array $result, $dispatch_after = true) {
        $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
        if (!is_array($job)) {
            self::release_lock($job_id);
            return;
        }
        if (!empty($job['worker'])) {
            self::mark_worker_active($job['worker'], true);
        }
        $job['status'] = 'completed';
        $job['message'] = __('Kész', 'mgdtp');
        $job['finished_at'] = time();
        $job['result'] = $result;
        update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);
        self::release_lock($job_id);
        if ($dispatch_after && self::has_pending_jobs()) {
            self::dispatch_workers(null, true);
        }
    }

    private static function fail_job($job_id, $message, $dispatch_after = true) {
        $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
        if (!is_array($job)) {
            self::release_lock($job_id);
            return;
        }
        if (!empty($job['worker'])) {
            self::mark_worker_active($job['worker'], true);
        }
        $job['status'] = 'failed';
        $job['message'] = $message;
        $job['finished_at'] = time();
        update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);
        self::release_lock($job_id);
        if ($dispatch_after && self::has_pending_jobs()) {
            self::dispatch_workers(null, true);
        }
    }

    private static function release_lock($job_id) {
        delete_transient(self::lock_key($job_id));
    }

    private static function count_pending_jobs() {
        $order = get_option(self::ORDER_OPTION, array());
        if (!is_array($order)) {
            return 0;
        }
        $pending = 0;
        foreach ($order as $job_id) {
            $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
            if (is_array($job) && isset($job['status']) && $job['status'] === 'pending') {
                $pending++;
            }
        }
        return $pending;
    }

    private static function has_pending_jobs() {
        self::maybe_recover_stalled_jobs();
        return self::count_pending_jobs() > 0;
    }

    private static function maybe_recover_stalled_jobs() {
        self::prune_stale_workers();

        $order = get_option(self::ORDER_OPTION, array());
        if (!is_array($order) || empty($order)) {
            return;
        }

        $now = time();
        foreach ($order as $job_id) {
            $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
            if (!is_array($job) || !isset($job['status']) || $job['status'] !== 'running') {
                continue;
            }

            $started_at = isset($job['started_at']) ? intval($job['started_at']) : 0;
            $lock = get_transient(self::lock_key($job_id));
            $is_stale = ($started_at > 0 && ($now - $started_at) > self::STALE_TTL);
            if (!$is_stale && $lock !== false) {
                continue;
            }

            self::release_lock($job_id);
            if (!empty($job['worker'])) {
                self::unregister_worker($job['worker']);
            }

            $job['status'] = 'pending';
            $job['message'] = __('Újrapróbáljuk…', 'mgdtp');
            $job['started_at'] = null;
            $job['worker'] = '';
            update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);
        }
    }

    private static function load_active_workers() {
        $workers = get_option(self::ACTIVE_WORKERS_OPTION, array());
        if (!is_array($workers)) {
            return array();
        }
        return $workers;
    }

    private static function save_active_workers(array $workers) {
        update_option(self::ACTIVE_WORKERS_OPTION, $workers, false);
    }

    private static function prune_stale_workers() {
        $workers = self::load_active_workers();
        if (empty($workers)) {
            return $workers;
        }
        $now = time();
        $changed = false;
        foreach ($workers as $worker_id => $timestamp) {
            $timestamp = intval($timestamp);
            if ($timestamp <= 0 || ($now - $timestamp) > self::ACTIVE_WORKER_TTL) {
                unset($workers[$worker_id]);
                $changed = true;
            }
        }
        if ($changed) {
            self::save_active_workers($workers);
        }
        return $workers;
    }

    private static function mark_worker_active($worker_id, $force = false) {
        $worker_id = is_string($worker_id) ? $worker_id : strval($worker_id);
        if ($worker_id === '') {
            return;
        }
        $workers = self::load_active_workers();
        $now = time();
        $current = isset($workers[$worker_id]) ? intval($workers[$worker_id]) : 0;
        if ($force || $current === 0 || ($now - $current) >= self::ACTIVE_WORKER_HEARTBEAT) {
            $workers[$worker_id] = $now;
            self::save_active_workers($workers);
        }
    }

    private static function unregister_worker($worker_id) {
        $worker_id = is_string($worker_id) ? $worker_id : strval($worker_id);
        if ($worker_id === '') {
            return;
        }
        $workers = self::load_active_workers();
        if (isset($workers[$worker_id])) {
            unset($workers[$worker_id]);
            self::save_active_workers($workers);
        }
    }

    private static function lock_key($job_id) {
        return 'mg_bulk_lock_' . md5($job_id);
    }

    private static function sanitize_payload(array $payload) {
        $clean = array();
        $clean['design_path'] = isset($payload['design_path']) ? $payload['design_path'] : '';
        $clean['product_keys'] = array_values(array_filter(array_unique(array_map('sanitize_text_field', isset($payload['product_keys']) ? (array)$payload['product_keys'] : array()))));
        $clean['parent_id'] = isset($payload['parent_id']) ? intval($payload['parent_id']) : 0;
        $clean['parent_name'] = isset($payload['parent_name']) ? sanitize_text_field($payload['parent_name']) : '';
        $cats = isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : array();
        $subs = isset($cats['subs']) ? array_map('intval', (array)$cats['subs']) : array();
        $subs = array_values(array_filter($subs, function($v){ return $v > 0; }));
        $clean['categories'] = array(
            'main' => isset($cats['main']) ? intval($cats['main']) : 0,
            'subs' => $subs,
        );
        $defaults = isset($payload['defaults']) && is_array($payload['defaults']) ? $payload['defaults'] : array();
        $clean['defaults'] = array(
            'type' => isset($defaults['type']) ? sanitize_text_field($defaults['type']) : '',
            'color' => isset($defaults['color']) ? sanitize_title($defaults['color']) : '',
            'size' => isset($defaults['size']) ? sanitize_text_field($defaults['size']) : '',
        );
        $clean['trigger'] = isset($payload['trigger']) ? sanitize_key($payload['trigger']) : 'bulk_queue';
        $clean['custom_product'] = !empty($payload['custom_product']) ? 1 : 0;
        $tags = isset($payload['tags']) ? (array)$payload['tags'] : array();
        $tags = array_map('sanitize_text_field', array_filter(array_map('trim', $tags)));
        $clean['tags'] = array_values(array_filter(array_unique($tags)));
        return $clean;
    }

    private static function get_worker_token() {
        $token = get_option(self::WORKER_TOKEN_OPTION, '');
        if (!$token) {
            $token = wp_generate_password(32, false, false);
            update_option(self::WORKER_TOKEN_OPTION, $token, false);
        }
        return $token;
    }

    private static function is_valid_token($token) {
        if (!$token) {
            return false;
        }
        $saved = self::get_worker_token();
        return hash_equals($saved, $token);
    }
}
