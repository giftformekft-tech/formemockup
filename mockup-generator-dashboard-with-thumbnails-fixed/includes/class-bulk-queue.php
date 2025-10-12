<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Bulk_Queue {
    const JOB_OPTION_PREFIX = 'mg_bulk_job_';
    const ORDER_OPTION = 'mg_bulk_queue_order';
    const WORKER_TOKEN_OPTION = 'mg_bulk_worker_token';
    const DISPATCH_LOCK = 'mg_bulk_dispatch_lock';
    const WORKER_COUNT = 4;
    const LOCK_TTL = 180; // seconds
    const STALE_TTL = 300; // seconds
    const MAX_JOBS_PER_WORKER = 10;

    public static function enqueue(array $payload) {
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

        self::dispatch_workers();

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
        $count = ($count === null) ? self::WORKER_COUNT : max(1, intval($count));
        if ($count < 1) { $count = 1; }
        if (!$force && false !== get_transient(self::DISPATCH_LOCK)) {
            return;
        }
        if ($force) {
            delete_transient(self::DISPATCH_LOCK);
        }
        self::maybe_recover_stalled_jobs();
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
        for ($i = 0; $i < $count; $i++) {
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
        $processed = 0;
        while ($processed < self::MAX_JOBS_PER_WORKER) {
            $job = self::claim_next_job($worker_id);
            if (!$job) {
                break;
            }
            self::execute_job($job, $worker_id);
            $processed++;
        }
        wp_die();
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

    private static function execute_job(array $job, $worker_id) {
        $job_id = $job['id'];
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
            $all = get_option('mg_products', array());
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

            $generator = new MG_Generator();
            $images_by_type_color = array();
            foreach ($selected as $prod) {
                $res = $generator->generate_for_product($prod['key'], $design_path);
                if (is_wp_error($res)) {
                    throw new RuntimeException($res->get_error_message());
                }
                $images_by_type_color[$prod['key']] = $res;
            }
            if (empty($images_by_type_color)) {
                throw new RuntimeException(__('Nem sikerült mockupot generálni.', 'mgdtp'));
            }

            $defaults = isset($payload['defaults']) && is_array($payload['defaults']) ? $payload['defaults'] : array('type' => '', 'color' => '', 'size' => '');
            $cats = isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : array();
            $cats = array(
                'main' => isset($cats['main']) ? intval($cats['main']) : 0,
                'subs' => isset($cats['subs']) ? array_map('intval', (array)$cats['subs']) : array(),
            );
            $parent_id = isset($payload['parent_id']) ? intval($payload['parent_id']) : 0;
            $parent_name = isset($payload['parent_name']) ? $payload['parent_name'] : '';
            if ($parent_name === '') {
                $parent_name = basename($design_path);
            }
            $context_trigger = isset($payload['trigger']) ? sanitize_key($payload['trigger']) : 'bulk_queue';
            $generation_context = array(
                'design_path' => $design_path,
                'trigger' => $context_trigger,
            );

            $creator = new MG_Product_Creator();
            $result_product_id = 0;
            if ($parent_id > 0) {
                $res = $creator->add_type_to_existing_parent($parent_id, $selected, $images_by_type_color, $parent_name, $cats, $defaults, $generation_context);
                if (is_wp_error($res)) {
                    throw new RuntimeException($res->get_error_message());
                }
                $result_product_id = intval($parent_id);
            } else {
                $res = $creator->create_parent_with_type_color_size_webp_fast($parent_name, $selected, $images_by_type_color, $cats, $defaults, $generation_context);
                if (is_wp_error($res)) {
                    throw new RuntimeException($res->get_error_message());
                }
                $result_product_id = intval($res);
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
            ));
        } catch (Throwable $e) {
            self::fail_job($job_id, $e->getMessage());
        }
    }

    private static function complete_job($job_id, array $result) {
        $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
        if (!is_array($job)) {
            self::release_lock($job_id);
            return;
        }
        $job['status'] = 'completed';
        $job['message'] = __('Kész', 'mgdtp');
        $job['finished_at'] = time();
        $job['result'] = $result;
        update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);
        self::release_lock($job_id);
        if (self::has_pending_jobs()) {
            self::dispatch_workers(1, true);
        }
    }

    private static function fail_job($job_id, $message) {
        $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
        if (!is_array($job)) {
            self::release_lock($job_id);
            return;
        }
        $job['status'] = 'failed';
        $job['message'] = $message;
        $job['finished_at'] = time();
        update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);
        self::release_lock($job_id);
        if (self::has_pending_jobs()) {
            self::dispatch_workers(1, true);
        }
    }

    private static function release_lock($job_id) {
        delete_transient(self::lock_key($job_id));
    }

    private static function has_pending_jobs() {
        self::maybe_recover_stalled_jobs();

        $order = get_option(self::ORDER_OPTION, array());
        if (!is_array($order)) {
            return false;
        }
        foreach ($order as $job_id) {
            $job = get_option(self::JOB_OPTION_PREFIX . $job_id, null);
            if (is_array($job) && isset($job['status']) && $job['status'] === 'pending') {
                return true;
            }
        }
        return false;
    }

    private static function maybe_recover_stalled_jobs() {
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

            $job['status'] = 'pending';
            $job['message'] = __('Újrapróbáljuk…', 'mgdtp');
            $job['started_at'] = null;
            $job['worker'] = '';
            update_option(self::JOB_OPTION_PREFIX . $job_id, $job, false);
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
