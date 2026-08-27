<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Ads product performance import and Merchant label classification.
 *
 * Google Ads Scripts pushes daily shopping_performance_view rows to the REST
 * endpoint. Classification is deliberately kept separate from feed rendering:
 * feeds only read the last committed result and never call Google while they
 * are being generated.
 */
class MG_Google_Ads_Product_Performance {
    const DB_VERSION_OPTION = 'mg_gads_product_performance_db_version';
    const DB_VERSION = '1.0.0';
    const SETTINGS_OPTION = 'mg_gads_product_performance_settings';
    const SECRET_OPTION = 'mg_gads_product_performance_secret';
    const SYNC_OPTION = 'mg_gads_product_performance_last_sync';
    const CLASSIFY_HOOK = 'mg_gads_product_performance_classify';
    const ACTION_GROUP = 'mg-google-ads-product-performance';
    const REST_NAMESPACE = 'mg-ads/v1';
    const REST_ROUTE = '/performance';

    private static $label_cache = array();

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_install'), 5);
        add_action('rest_api_init', array(__CLASS__, 'register_rest_route'));
        add_action(self::CLASSIFY_HOOK, array(__CLASS__, 'run_rolling_classification'));
        add_action('init', array(__CLASS__, 'ensure_schedule'), 30);
    }

    public static function daily_table() {
        global $wpdb;
        return $wpdb->prefix . 'mg_gads_product_daily';
    }

    public static function classification_table() {
        global $wpdb;
        return $wpdb->prefix . 'mg_gads_product_classification';
    }

    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        self::install();
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $daily = self::daily_table();
        $classification = self::classification_table();

        dbDelta(
            "CREATE TABLE {$daily} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                offer_id VARCHAR(191) NOT NULL,
                metric_date DATE NOT NULL,
                impressions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                cost_micros BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                conversions DECIMAL(20,6) NOT NULL DEFAULT 0,
                conversion_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (id),
                UNIQUE KEY offer_date (offer_id, metric_date),
                KEY metric_date (metric_date)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$classification} (
                product_id BIGINT(20) UNSIGNED NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'normal',
                candidate_status VARCHAR(12) NOT NULL DEFAULT 'normal',
                candidate_runs SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                impressions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                cost_micros BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                conversions DECIMAL(20,6) NOT NULL DEFAULT 0,
                conversion_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                window_start DATE NOT NULL,
                window_end DATE NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'maintenance',
                reason VARCHAR(191) NOT NULL DEFAULT '',
                classified_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (product_id),
                KEY status (status),
                KEY classified_at (classified_at)
            ) {$charset};"
        );

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function defaults() {
        return array(
            'enabled' => 0,
            'automation_enabled' => 1,
            'label_slot' => 1,
            'winner_conversions' => 2.0,
            'loser_basis' => 'spend',
            'loser_spend' => 10000,
            'loser_clicks' => 30,
            'conversion_lag_days' => 3,
            'history_start_date' => wp_date('Y-m-d', strtotime('-9 months')),
            'ads_customer_id' => '',
            'purchase_action_name' => '',
            'campaign_ids' => '',
            'initial_completed_at' => 0,
        );
    }

    public static function get_settings() {
        $saved = get_option(self::SETTINGS_OPTION, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, self::defaults());
    }

    public static function save_settings($input) {
        $old = self::get_settings();
        $slot = isset($input['label_slot']) ? absint($input['label_slot']) : 1;
        $slot = min(4, max(0, $slot));

        $start = isset($input['history_start_date']) ? sanitize_text_field($input['history_start_date']) : '';
        if (!self::is_valid_date($start)) {
            $start = $old['history_start_date'];
        }

        $campaign_ids = array_filter(array_map('absint', preg_split('/[\s,;]+/', (string) ($input['campaign_ids'] ?? ''))));
        $loser_basis = isset($input['loser_basis']) && $input['loser_basis'] === 'clicks' ? 'clicks' : 'spend';
        $clean = array(
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'automation_enabled' => !empty($input['automation_enabled']) ? 1 : 0,
            'label_slot' => $slot,
            'winner_conversions' => max(0.01, (float) ($input['winner_conversions'] ?? 2)),
            'loser_basis' => $loser_basis,
            'loser_spend' => max(1, (float) ($input['loser_spend'] ?? 10000)),
            'loser_clicks' => max(1, absint($input['loser_clicks'] ?? 30)),
            'conversion_lag_days' => min(14, max(0, absint($input['conversion_lag_days'] ?? 3))),
            'history_start_date' => $start,
            'ads_customer_id' => preg_replace('/[^0-9]/', '', (string) ($input['ads_customer_id'] ?? '')),
            'purchase_action_name' => sanitize_text_field($input['purchase_action_name'] ?? ''),
            'campaign_ids' => implode(',', array_values(array_unique($campaign_ids))),
            'initial_completed_at' => absint($old['initial_completed_at']),
        );
        foreach (array('history_start_date', 'ads_customer_id', 'purchase_action_name', 'campaign_ids') as $import_key) {
            if ((string) $clean[$import_key] !== (string) $old[$import_key]) {
                $clean['initial_completed_at'] = 0;
                break;
            }
        }
        update_option(self::SETTINGS_OPTION, $clean, false);
        self::$label_cache = array();
        return $clean;
    }

    public static function get_secret() {
        $secret = (string) get_option(self::SECRET_OPTION, '');
        if ($secret === '') {
            $secret = self::rotate_secret();
        }
        return $secret;
    }

    public static function rotate_secret() {
        $bytes = function_exists('random_bytes') ? random_bytes(32) : wp_generate_password(64, true, true);
        $secret = is_string($bytes) && strlen($bytes) === 32
            ? rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')
            : (string) $bytes;
        update_option(self::SECRET_OPTION, $secret, false);
        return $secret;
    }

    public static function register_rest_route() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_import_request'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle_import_request($request) {
        self::maybe_install();
        $body = (string) $request->get_body();
        if (strlen($body) > 10 * MB_IN_BYTES) {
            return new WP_Error('mg_ads_payload_large', 'A kérés túl nagy.', array('status' => 413));
        }

        $timestamp = (string) $request->get_header('x-mg-timestamp');
        $request_id = sanitize_text_field($request->get_header('x-mg-request-id'));
        $signature = strtolower((string) $request->get_header('x-mg-signature'));
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || $request_id === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return new WP_Error('mg_ads_auth', 'Érvénytelen vagy lejárt importkérés.', array('status' => 401));
        }
        $replay_key = 'mg_gads_perf_replay_' . md5($request_id);
        if (get_transient($replay_key)) {
            return new WP_Error('mg_ads_replay', 'Ez az importkérés már feldolgozásra került.', array('status' => 409));
        }
        $expected = hash_hmac('sha256', $timestamp . "\n" . $request_id . "\n" . $body, self::get_secret());
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('mg_ads_signature', 'Hibás import-aláírás.', array('status' => 401));
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['rows']) || !is_array($payload['rows']) || count($payload['rows']) > 5000) {
            return new WP_Error('mg_ads_payload', 'Hibás vagy üres importadat.', array('status' => 400));
        }
        $settings = self::get_settings();
        $account_id = preg_replace('/[^0-9]/', '', (string) ($payload['account_id'] ?? ''));
        if ($settings['ads_customer_id'] !== '' && $account_id !== $settings['ads_customer_id']) {
            return new WP_Error('mg_ads_account', 'Az import másik Google Ads-fiókból érkezett.', array('status' => 403));
        }
        $currency_code = strtoupper(sanitize_text_field($payload['currency_code'] ?? ''));
        if ($settings['loser_basis'] === 'spend' && $currency_code !== 'HUF') {
            return new WP_Error('mg_ads_currency', 'A forintalapú Loser-besoroláshoz HUF pénznemű Google Ads-fiók szükséges.', array('status' => 400));
        }

        set_transient($replay_key, 1, 15 * MINUTE_IN_SECONDS);
        $result = self::import_rows($payload['rows']);
        update_option(self::SYNC_OPTION, array(
            'timestamp' => time(),
            'account_id' => $account_id,
            'accepted' => $result['accepted'],
            'rejected' => $result['rejected'],
            'min_date' => $result['min_date'],
            'max_date' => $result['max_date'],
            'currency_code' => $currency_code,
        ), false);

        if (!empty($settings['automation_enabled']) && !empty($settings['initial_completed_at'])) {
            self::schedule_classification(time() + 60);
        }

        return new WP_REST_Response(array('success' => true) + $result, 200);
    }

    public static function import_rows($rows) {
        global $wpdb;
        $accepted = 0;
        $rejected = 0;
        $min_date = '';
        $max_date = '';
        $table = self::daily_table();
        $now = current_time('mysql', true);

        foreach ((array) $rows as $row) {
            $offer_id = isset($row['offer_id']) ? sanitize_text_field($row['offer_id']) : '';
            $date = isset($row['date']) ? sanitize_text_field($row['date']) : '';
            if ($offer_id === '' || strlen($offer_id) > 191 || !self::is_valid_date($date)) {
                $rejected++;
                continue;
            }
            $impressions = max(0, (int) ($row['impressions'] ?? 0));
            $clicks = max(0, (int) ($row['clicks'] ?? 0));
            $cost_micros = max(0, (int) ($row['cost_micros'] ?? 0));
            $conversions = max(0, (float) ($row['conversions'] ?? 0));
            $conversion_value = max(0, (float) ($row['conversion_value'] ?? 0));

            $sql = $wpdb->prepare(
                "INSERT INTO {$table}
                    (offer_id, metric_date, impressions, clicks, cost_micros, conversions, conversion_value, updated_at)
                 VALUES (%s, %s, %d, %d, %d, %f, %f, %s)
                 ON DUPLICATE KEY UPDATE
                    impressions = VALUES(impressions), clicks = VALUES(clicks), cost_micros = VALUES(cost_micros),
                    conversions = VALUES(conversions), conversion_value = VALUES(conversion_value), updated_at = VALUES(updated_at)",
                $offer_id, $date, $impressions, $clicks, $cost_micros, $conversions, $conversion_value, $now
            );
            if ($wpdb->query($sql) === false) {
                $rejected++;
                continue;
            }
            $accepted++;
            $min_date = $min_date === '' || $date < $min_date ? $date : $min_date;
            $max_date = $max_date === '' || $date > $max_date ? $date : $max_date;
        }

        return compact('accepted', 'rejected', 'min_date', 'max_date');
    }

    public static function classify_metrics($conversions, $clicks, $cost_micros = 0, $winner_threshold = 2.0, $loser_basis = 'spend', $loser_clicks = 30, $loser_spend = 10000) {
        $conversions = (float) $conversions;
        $clicks = (int) $clicks;
        $spend = max(0, (float) $cost_micros / 1000000);
        if ($conversions >= (float) $winner_threshold) {
            return array('status' => 'winner', 'reason' => 'Legalább ' . self::format_number($winner_threshold) . ' attribútált eladás.');
        }
        if ($conversions <= 0.000001 && $loser_basis === 'clicks' && $clicks >= (int) $loser_clicks) {
            return array('status' => 'loser', 'reason' => 'Nincs eladás legalább ' . (int) $loser_clicks . ' kattintásból.');
        }
        if ($conversions <= 0.000001 && $loser_basis === 'spend' && $spend >= (float) $loser_spend) {
            return array('status' => 'loser', 'reason' => 'Nincs eladás legalább ' . self::format_number($loser_spend) . ' Ft költésből.');
        }
        if ($conversions > 0) {
            $reason = 'Van eladás, de még nem érte el a Winner-küszöböt.';
        } elseif ($loser_basis === 'spend') {
            $reason = 'Még nem érte el a ' . self::format_number($loser_spend) . ' Ft-os Loser-költést.';
        } else {
            $reason = 'Még nincs elegendő kattintás a Loser-döntéshez.';
        }
        return array('status' => 'normal', 'reason' => $reason);
    }

    public static function run_initial_classification() {
        $settings = self::get_settings();
        $end = self::classification_end_date($settings);
        $result = self::run_classification($settings['history_start_date'], $end, 'initial');
        if (!is_wp_error($result)) {
            $settings['initial_completed_at'] = time();
            update_option(self::SETTINGS_OPTION, $settings, false);
        }
        return $result;
    }

    public static function run_rolling_classification() {
        $settings = self::get_settings();
        if (empty($settings['initial_completed_at'])) {
            return new WP_Error('mg_ads_initial_missing', 'Az induló besorolás még nem futott le.');
        }
        $end = self::classification_end_date($settings);
        // Always use the complete webshop history. Once a design reaches the
        // Winner threshold it must never lose that status in a quieter season.
        $start = $settings['history_start_date'];
        return self::run_classification($start, $end, 'maintenance');
    }

    public static function run_classification($start, $end, $source = 'maintenance') {
        global $wpdb;
        self::maybe_install();
        if (!self::is_valid_date($start) || !self::is_valid_date($end) || $start > $end) {
            return new WP_Error('mg_ads_dates', 'Érvénytelen besorolási időszak.');
        }

        $daily = self::daily_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT offer_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
                    SUM(cost_micros) AS cost_micros, SUM(conversions) AS conversions,
                    SUM(conversion_value) AS conversion_value
             FROM {$daily}
             WHERE metric_date BETWEEN %s AND %s
             GROUP BY offer_id",
            $start, $end
        ), ARRAY_A);
        if (!is_array($rows)) {
            return new WP_Error('mg_ads_query', 'A teljesítményadatok nem olvashatók.');
        }

        $map = self::build_offer_product_map();
        $metrics = array();
        foreach ($map['product_ids'] as $product_id) {
            $metrics[$product_id] = array('impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0.0, 'conversion_value' => 0.0);
        }
        $unmatched = array();
        foreach ($rows as $row) {
            $offer_id = (string) $row['offer_id'];
            if (!isset($map['offers'][$offer_id])) {
                $unmatched[] = $offer_id;
                continue;
            }
            $product_id = $map['offers'][$offer_id];
            foreach (array('impressions', 'clicks', 'cost_micros') as $key) {
                $metrics[$product_id][$key] += (int) $row[$key];
            }
            $metrics[$product_id]['conversions'] += (float) $row['conversions'];
            $metrics[$product_id]['conversion_value'] += (float) $row['conversion_value'];
        }

        $settings = self::get_settings();
        $changed = 0;
        $counts = array('winner' => 0, 'normal' => 0, 'loser' => 0);
        $now = current_time('mysql', true);
        $table = self::classification_table();

        foreach ($metrics as $product_id => $values) {
            $current = $wpdb->get_row($wpdb->prepare("SELECT status, candidate_status, candidate_runs FROM {$table} WHERE product_id = %d", $product_id), ARRAY_A);
            $decision = self::classify_metrics(
                $values['conversions'],
                $values['clicks'],
                $values['cost_micros'],
                $settings['winner_conversions'],
                $settings['loser_basis'],
                $settings['loser_clicks'],
                $settings['loser_spend']
            );
            if ($current && self::sanitize_status($current['status']) === 'winner') {
                $decision = array(
                    'status' => 'winner',
                    'reason' => 'Korábban már elérte a Winner-küszöböt; a Winner státusz végleges.',
                );
            }
            $status = $decision['status'];
            $candidate = $status;
            $candidate_runs = 0;
            if (!$current || self::sanitize_status($current['status']) !== $status) {
                $changed++;
            }

            $counts[$status]++;
            $sql = $wpdb->prepare(
                "INSERT INTO {$table}
                    (product_id, status, candidate_status, candidate_runs, impressions, clicks, cost_micros, conversions, conversion_value, window_start, window_end, source, reason, classified_at)
                 VALUES (%d, %s, %s, %d, %d, %d, %d, %f, %f, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status), candidate_status = VALUES(candidate_status), candidate_runs = VALUES(candidate_runs),
                    impressions = VALUES(impressions), clicks = VALUES(clicks), cost_micros = VALUES(cost_micros),
                    conversions = VALUES(conversions), conversion_value = VALUES(conversion_value), window_start = VALUES(window_start),
                    window_end = VALUES(window_end), source = VALUES(source), reason = VALUES(reason), classified_at = VALUES(classified_at)",
                $product_id, $status, $candidate, $candidate_runs,
                $values['impressions'], $values['clicks'], $values['cost_micros'], $values['conversions'], $values['conversion_value'],
                $start, $end, sanitize_key($source), $decision['reason'], $now
            );
            $wpdb->query($sql);
        }

        self::$label_cache = array();
        update_option('mg_gads_product_performance_last_classification', array(
            'timestamp' => time(),
            'start' => $start,
            'end' => $end,
            'source' => $source,
            'counts' => $counts,
            'changed' => $changed,
            'unmatched_count' => count($unmatched),
            'unmatched_sample' => array_slice(array_values(array_unique($unmatched)), 0, 20),
        ), false);

        if ($changed > 0 && !empty($settings['enabled'])) {
            self::regenerate_google_feeds();
        }
        return array('counts' => $counts, 'changed' => $changed, 'unmatched' => count($unmatched), 'start' => $start, 'end' => $end);
    }

    public static function get_product_status($product_id) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return 'normal';
        }
        if (isset(self::$label_cache[$product_id])) {
            return self::$label_cache[$product_id];
        }
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . self::classification_table() . ' WHERE product_id = %d', $product_id));
        self::$label_cache[$product_id] = self::sanitize_status($status);
        return self::$label_cache[$product_id];
    }

    public static function is_enabled() {
        return !empty(self::get_settings()['enabled']);
    }

    public static function get_label_slot() {
        return min(4, max(0, absint(self::get_settings()['label_slot'])));
    }

    public static function get_feed_label($product_id) {
        if (!self::is_enabled()) {
            return '';
        }
        return self::get_product_status($product_id);
    }

    public static function get_classifications($limit = 100) {
        global $wpdb;
        $limit = min(500, max(1, absint($limit)));
        return $wpdb->get_results("SELECT * FROM " . self::classification_table() . " ORDER BY FIELD(status, 'winner', 'normal', 'loser'), conversions DESC, clicks DESC LIMIT {$limit}", ARRAY_A);
    }

    public static function get_sync_status() {
        $value = get_option(self::SYNC_OPTION, array());
        return is_array($value) ? $value : array();
    }

    public static function get_classification_status() {
        $value = get_option('mg_gads_product_performance_last_classification', array());
        return is_array($value) ? $value : array();
    }

    public static function ensure_schedule() {
        $settings = self::get_settings();
        if (empty($settings['automation_enabled']) || empty($settings['initial_completed_at'])) {
            return;
        }
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (!as_has_scheduled_action(self::CLASSIFY_HOOK, array(), self::ACTION_GROUP)) {
                as_schedule_recurring_action(time() + DAY_IN_SECONDS, WEEK_IN_SECONDS, self::CLASSIFY_HOOK, array(), self::ACTION_GROUP, true);
            }
            return;
        }
        if (!wp_next_scheduled(self::CLASSIFY_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', self::CLASSIFY_HOOK);
        }
    }

    private static function schedule_classification($timestamp) {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action(self::CLASSIFY_HOOK, array(), self::ACTION_GROUP)) {
                as_schedule_single_action((int) $timestamp, self::CLASSIFY_HOOK, array(), self::ACTION_GROUP, true);
            }
            return;
        }
        if (!wp_next_scheduled(self::CLASSIFY_HOOK)) {
            wp_schedule_single_event((int) $timestamp, self::CLASSIFY_HOOK);
        }
    }

    private static function build_offer_product_map() {
        $result = array('offers' => array(), 'product_ids' => array());
        if (!class_exists('MG_Virtual_Variant_Manager') || !function_exists('wc_get_product')) {
            return $result;
        }
        $ids = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => array(array('taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'simple')),
        ));
        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            if (empty($config['types']) || !is_array($config['types'])) {
                continue;
            }
            $base_sku = $product->get_sku() ?: 'ID_' . $product_id;
            $result['product_ids'][$product_id] = $product_id;
            foreach (array_keys($config['types']) as $type_slug) {
                $result['offers'][$base_sku . '_' . $type_slug] = (int) $product_id;
            }
        }
        return $result;
    }

    private static function classification_end_date($settings) {
        $today = wp_date('Y-m-d');
        return gmdate('Y-m-d', strtotime($today . ' -' . absint($settings['conversion_lag_days']) . ' days'));
    }

    public static function regenerate_google_feeds() {
        if (class_exists('MG_Google_Merchant_Feed')) {
            MG_Google_Merchant_Feed::generate_feed_to_file();
        }
        if (class_exists('MG_Custom_Feed_Manager')) {
            foreach ((array) get_option('mg_custom_feeds', array()) as $slug => $feed) {
                if (($feed['format'] ?? '') === 'google') {
                    MG_Custom_Feed_Manager::generate_feed_to_file($slug);
                }
            }
        }
    }

    private static function sanitize_status($status) {
        return in_array($status, array('winner', 'normal', 'loser'), true) ? $status : 'normal';
    }

    private static function is_valid_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private static function format_number($number) {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }
}
