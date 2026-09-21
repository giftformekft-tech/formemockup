<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
foreach (array('MB_IN_BYTES' => 1048576, 'MINUTE_IN_SECONDS' => 60, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

$mg_test_options = array();
$mg_test_transients = array();
$mg_test_cleared_hooks = array();
$mg_test_update_failures = array();

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct($code, $message, $data = null) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data, $status) {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_args($args, $defaults) { return array_merge($defaults, is_array($args) ? $args : array()); }
function wp_date($format, $timestamp = null) { return date($format, $timestamp === null ? time() : $timestamp); }
function current_time($type, $gmt = false) { return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time(); }
function get_option($key, $default = false) {
    global $mg_test_options;
    return array_key_exists($key, $mg_test_options) ? $mg_test_options[$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    global $mg_test_options, $mg_test_update_failures;
    if (!empty($mg_test_update_failures[$key])) {
        return false;
    }
    $mg_test_options[$key] = $value;
    return true;
}
function delete_option($key) {
    global $mg_test_options;
    unset($mg_test_options[$key]);
    return true;
}
function get_transient($key) {
    global $mg_test_transients;
    return $mg_test_transients[$key] ?? false;
}
function set_transient($key, $value, $expiration) {
    global $mg_test_transients;
    $mg_test_transients[$key] = $value;
    return true;
}
function wp_clear_scheduled_hook($hook) {
    global $mg_test_cleared_hooks;
    $mg_test_cleared_hooks[] = $hook;
    return 1;
}

class MG_Test_Wpdb {
    public $prefix = 'wp_';
    public $queries = array();
    public $fail_insert = false;
    public function query($sql) {
        $this->queries[] = $sql;
        if ($this->fail_insert && stripos(ltrim($sql), 'INSERT INTO') === 0) {
            return false;
        }
        return 1;
    }
    public function prepare($query, ...$args) { return $query; }
    public function get_charset_collate() { return ''; }
    public function get_var($query) {
        if (stripos($query, 'GET_LOCK') !== false || stripos($query, 'RELEASE_LOCK') !== false) {
            return 1;
        }
        return null;
    }
}

class MG_Test_Request {
    private $body;
    private $headers;
    public function __construct($body, $headers) {
        $this->body = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }
    public function get_body() { return $this->body; }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? ''; }
}

function mg_state_expect($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$wpdb = new MG_Test_Wpdb();
require_once dirname(__DIR__) . '/includes/class-google-ads-product-performance.php';

$base_settings = array(
    'enabled' => 1,
    'automation_enabled' => 0,
    'label_slot' => 1,
    'winner_conversions' => 2.0,
    'loser_basis' => 'spend',
    'loser_spend' => 10000,
    'loser_target_cpa' => 3000,
    'loser_min_days' => 7,
    'conversion_lag_days' => 3,
    'history_start_date' => '2026-01-01',
    'ads_customer_id' => '1234567890',
    'purchase_action_name' => 'Purchase',
    'campaign_ids' => '111,222',
    'initial_completed_at' => 123,
);
$mg_test_options[MG_Google_Ads_Product_Performance::DB_VERSION_OPTION] = MG_Google_Ads_Product_Performance::DB_VERSION;
$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = $base_settings;
$mg_test_options[MG_Google_Ads_Product_Performance::SECRET_OPTION] = 'test-secret';

$scope_a = MG_Google_Ads_Product_Performance::get_import_scope($base_settings);
$changed_scope_settings = $base_settings;
$changed_scope_settings['campaign_ids'] = '333';
$scope_b = MG_Google_Ads_Product_Performance::get_import_scope($changed_scope_settings);
mg_state_expect($scope_a !== $scope_b, 'Campaign changes must invalidate the import scope.');
$classification_only_settings = $base_settings;
$classification_only_settings['loser_basis'] = 'cpa';
mg_state_expect($scope_a === MG_Google_Ads_Product_Performance::get_import_scope($classification_only_settings), 'Changing only the Loser rule must preserve valid imported Ads data.');

$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION] = array('scope' => $scope_a, 'completed_at' => 1);
$mg_test_options[MG_Google_Ads_Product_Performance::SYNC_OPTION] = array('account_id' => '1234567890');
$saved = MG_Google_Ads_Product_Performance::save_settings($changed_scope_settings);
mg_state_expect(!is_wp_error($saved), 'A valid scope change should reset imported state.');
mg_state_expect(empty($saved['initial_completed_at']), 'A scope change must require a new initial classification.');
mg_state_expect(!isset($mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION]), 'Old import completion state must be removed.');
mg_state_expect((bool) array_filter($wpdb->queries, function ($sql) { return stripos($sql, 'DELETE FROM wp_mg_gads_product_daily') === 0; }), 'Old daily Ads rows must be deleted.');
mg_state_expect((bool) array_filter($wpdb->queries, function ($sql) { return stripos($sql, 'DELETE FROM wp_mg_gads_product_classification') === 0; }), 'Old winner/loser rows must be deleted.');

$active_settings = $base_settings;
$active_settings['initial_completed_at'] = 0;
$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = $active_settings;
mg_state_expect(MG_Google_Ads_Product_Performance::get_feed_label(123) === '', 'Incomplete imports must not publish fallback normal labels.');

$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = $base_settings;
$scope = MG_Google_Ads_Product_Performance::get_import_scope($base_settings);
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION] = array(
    'scope' => $scope,
    'start' => '2026-01-01',
    'end' => '2026-01-31',
    'currency_code' => 'HUF',
    'account_id' => '1234567890',
    'completed_at' => 1,
);
mg_state_expect(MG_Google_Ads_Product_Performance::get_feed_label(123) === '', 'A missing classification row must not be published as a fallback normal label.');

$mg_test_update_failures[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = true;
$failed_reset = MG_Google_Ads_Product_Performance::reset_import_data();
mg_state_expect(is_wp_error($failed_reset), 'A partial reset must report its settings write failure.');
mg_state_expect(!empty($mg_test_options[MG_Google_Ads_Product_Performance::RESET_GUARD_OPTION]), 'A partial reset must leave the fail-safe publishing guard active.');
mg_state_expect(MG_Google_Ads_Product_Performance::get_feed_label(123) === '', 'The reset guard must suppress labels after a partial reset.');
unset($mg_test_update_failures[MG_Google_Ads_Product_Performance::SETTINGS_OPTION]);
mg_state_expect(MG_Google_Ads_Product_Performance::reset_import_data() === true, 'A retry must be able to finish a previously partial reset.');
mg_state_expect(empty($mg_test_options[MG_Google_Ads_Product_Performance::RESET_GUARD_OPTION]), 'A successful reset must clear the publishing guard.');

$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = $base_settings;
$payload = array(
    'account_id' => '1234567890',
    'currency_code' => 'HUF',
    'scope' => $scope,
    'operation' => 'import',
    'range_start' => '2026-01-01',
    'range_end' => '2026-01-31',
    'batch_index' => 0,
    'batch_count' => 1,
    'attempt_id' => 'attempt-db-failure',
    'snapshot_id' => str_repeat('a', 64),
    'import_mode' => 'initial',
    'rows' => array(array(
        'date' => '2026-01-10',
        'offer_id' => 'SKU_shirt',
        'impressions' => 10,
        'clicks' => 2,
        'cost_micros' => 1000000,
        'conversions' => 0,
        'conversion_value' => 0,
    )),
);
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_PROGRESS_OPTION] = array(
    'attempt_id' => 'still-active-attempt',
    'started_at' => time() - 3600,
    'updated_at' => time() - 3600,
);
$busy_body = json_encode($payload);
$busy_timestamp = (string) time();
$busy_id = 'active-lock-test';
$busy_signature = hash_hmac('sha256', $busy_timestamp . "\n" . $busy_id . "\n" . $busy_body, 'test-secret');
$busy = MG_Google_Ads_Product_Performance::handle_import_request(new MG_Test_Request($busy_body, array(
    'x-mg-timestamp' => $busy_timestamp,
    'x-mg-request-id' => $busy_id,
    'x-mg-signature' => $busy_signature,
)));
mg_state_expect(is_wp_error($busy) && $busy->get_error_code() === 'mg_ads_import_busy', 'An active one-hour import lock must not be stolen by another attempt.');
delete_option(MG_Google_Ads_Product_Performance::IMPORT_PROGRESS_OPTION);

$body = json_encode($payload);
$timestamp = (string) time();
$request_id = 'db-failure-test';
$signature = hash_hmac('sha256', $timestamp . "\n" . $request_id . "\n" . $body, 'test-secret');
$wpdb->fail_insert = true;
$response = MG_Google_Ads_Product_Performance::handle_import_request(new MG_Test_Request($body, array(
    'x-mg-timestamp' => $timestamp,
    'x-mg-request-id' => $request_id,
    'x-mg-signature' => $signature,
)));
mg_state_expect(is_wp_error($response), 'A database write failure must not return HTTP success.');
mg_state_expect(($response->get_error_data()['status'] ?? 0) === 500, 'A database write failure must be retryable as a server error.');
mg_state_expect(in_array('ROLLBACK', $wpdb->queries, true), 'A failed import batch must roll back its range replacement.');

delete_option(MG_Google_Ads_Product_Performance::IMPORT_PROGRESS_OPTION);
$complete_payload = array(
    'account_id' => '1234567890',
    'currency_code' => 'HUF',
    'scope' => $scope,
    'operation' => 'complete_initial',
    'start_date' => '2026-01-01',
    'end_date' => '2026-01-31',
    'rows' => array(),
);
$complete_body = json_encode($complete_payload);
$complete_timestamp = (string) time();
$complete_id = 'premature-complete-test';
$complete_signature = hash_hmac('sha256', $complete_timestamp . "\n" . $complete_id . "\n" . $complete_body, 'test-secret');
$premature = MG_Google_Ads_Product_Performance::handle_import_request(new MG_Test_Request($complete_body, array(
    'x-mg-timestamp' => $complete_timestamp,
    'x-mg-request-id' => $complete_id,
    'x-mg-signature' => $complete_signature,
)));
mg_state_expect(is_wp_error($premature) && $premature->get_error_code() === 'mg_ads_initial_coverage', 'Initial completion must be rejected before server-side range coverage exists.');

$wpdb->fail_insert = false;
$payload['attempt_id'] = 'attempt-success';
$success_body = json_encode($payload);
$success_timestamp = (string) time();
$success_id = 'successful-range-test';
$success_signature = hash_hmac('sha256', $success_timestamp . "\n" . $success_id . "\n" . $success_body, 'test-secret');
$success = MG_Google_Ads_Product_Performance::handle_import_request(new MG_Test_Request($success_body, array(
    'x-mg-timestamp' => $success_timestamp,
    'x-mg-request-id' => $success_id,
    'x-mg-signature' => $success_signature,
)));
mg_state_expect($success instanceof WP_REST_Response && !empty($success->data['range_complete']), 'A complete successful range must be acknowledged.');

$duplicate_id = 'successful-range-duplicate-ack-test';
$duplicate_signature = hash_hmac('sha256', $success_timestamp . "\n" . $duplicate_id . "\n" . $success_body, 'test-secret');
$duplicate = MG_Google_Ads_Product_Performance::handle_import_request(new MG_Test_Request($success_body, array(
    'x-mg-timestamp' => $success_timestamp,
    'x-mg-request-id' => $duplicate_id,
    'x-mg-signature' => $duplicate_signature,
)));
mg_state_expect($duplicate instanceof WP_REST_Response && !empty($duplicate->data['duplicate_ack']) && !empty($duplicate->data['range_complete']), 'A lost final ACK must be recoverable without importing the completed range again.');

$complete_id = 'successful-complete-test';
$complete_signature = hash_hmac('sha256', $complete_timestamp . "\n" . $complete_id . "\n" . $complete_body, 'test-secret');
$completed = MG_Google_Ads_Product_Performance::handle_import_request(new MG_Test_Request($complete_body, array(
    'x-mg-timestamp' => $complete_timestamp,
    'x-mg-request-id' => $complete_id,
    'x-mg-signature' => $complete_signature,
)));
mg_state_expect($completed instanceof WP_REST_Response && !empty($completed->data['initial_import_complete']), 'A fully covered initial range must be persisted and acknowledged.');

function mg_state_send_import($payload) {
    static $request_number = 0;
    $body = json_encode($payload);
    $timestamp = (string) time();
    $id = 'regression-' . ++$request_number;
    return MG_Google_Ads_Product_Performance::handle_import_request(new MG_Test_Request($body, array(
        'x-mg-timestamp' => $timestamp,
        'x-mg-request-id' => $id,
        'x-mg-signature' => hash_hmac('sha256', $timestamp . "\n" . $id . "\n" . $body, 'test-secret'),
    )));
}

$unfinished_payload = $payload;
$unfinished_payload['import_mode'] = 'rolling';
$unfinished_payload['attempt_id'] = 'unfinished-rolling';
$unfinished_payload['range_start'] = '2026-01-02';
$unfinished_payload['range_end'] = '2026-02-01';
$unfinished_payload['batch_count'] = 2;
$unfinished = mg_state_send_import($unfinished_payload);
mg_state_expect($unfinished instanceof WP_REST_Response && !$unfinished->data['range_complete'], 'The fixture must leave an incomplete rolling range.');
$unfinished_progress = $mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_PROGRESS_OPTION];
$unfinished_progress['updated_at'] = time() - 3 * 3600;
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_PROGRESS_OPTION] = $unfinished_progress;
$next_day_payload = $unfinished_payload;
$next_day_payload['range_start'] = '2026-01-03';
$next_day_payload['range_end'] = '2026-02-02';
$next_day_payload['attempt_id'] = 'next-day-rolling';
$query_count = count($wpdb->queries);
$resume = mg_state_send_import($next_day_payload);
mg_state_expect($resume instanceof WP_REST_Response && ($resume->data['resume_range']['start'] ?? '') === '2026-01-02', 'An expired lease must return the unfinished range instead of abandoning it.');
mg_state_expect(count($wpdb->queries) === $query_count, 'Rejecting a shifted range must not delete or insert daily rows.');
mg_state_expect($mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_PROGRESS_OPTION] === $unfinished_progress, 'The unfinished progress must remain recoverable.');
mg_state_expect($mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION]['end'] === '2026-01-31', 'Incomplete ranges must never advance coverage.');
$unfinished_payload['batch_index'] = 1;
$unfinished_payload['rows'] = array();
$resumed = mg_state_send_import($unfinished_payload);
mg_state_expect($resumed instanceof WP_REST_Response && !empty($resumed->data['range_complete']), 'The original range must still be resumable after the lease expires.');
mg_state_expect($mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION]['end'] === '2026-02-01', 'Coverage advances only after completing the original range.');

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
class MG_Classification_Test_Wpdb extends MG_Test_Wpdb {
    public $last_error = '';
    public $metric_rows = array();
    public $old_status = null;
    public $lock_available = true;
    public $lock_releases = 0;
    public function get_var($query) {
        if (stripos($query, 'GET_LOCK') !== false) return $this->lock_available ? 1 : 0;
        if (stripos($query, 'RELEASE_LOCK') !== false) { $this->lock_releases++; return 1; }
        return $this->old_status;
    }
    public function get_results($query, $format) { return $this->metric_rows; }
    public function get_row($query, $format) {
        return $this->old_status ? array('status' => $this->old_status, 'candidate_status' => $this->old_status, 'candidate_runs' => 0) : null;
    }
}
function get_posts($args) { return array(999); }
function wc_get_product($id) { return new MG_Classification_Test_Product(); }
class MG_Classification_Test_Product { public function get_sku() { return 'DESIGN'; } }
class MG_Virtual_Variant_Manager {
    public static function get_frontend_config($product) { return array('types' => array('men' => array(), 'women' => array())); }
}

$wpdb = new MG_Classification_Test_Wpdb();
$now = time();
$fresh_end = wp_date('Y-m-d', $now - 3 * DAY_IN_SECONDS);
$fresh_state = array('scope' => $scope, 'start' => '2026-01-01', 'end' => $fresh_end, 'account_id' => '1234567890', 'currency_code' => 'HUF', 'completed_at' => $now - DAY_IN_SECONDS);
$fresh_sync = array('scope' => $scope, 'account_id' => '1234567890', 'currency_code' => 'HUF', 'timestamp' => $now);
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION] = $fresh_state;
$mg_test_options[MG_Google_Ads_Product_Performance::SYNC_OPTION] = $fresh_sync;
mg_state_expect(MG_Google_Ads_Product_Performance::validate_import_freshness() === true, 'Fresh coverage and a recent completed import must pass.');

$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION]['end'] = wp_date('Y-m-d', $now - 40 * DAY_IN_SECONDS);
$last_classification = array('timestamp' => 123, 'counts' => array('winner' => 1, 'normal' => 0, 'loser' => 0));
$mg_test_options[MG_Google_Ads_Product_Performance::CLASSIFICATION_STATE_OPTION] = $last_classification;
$query_count = count($wpdb->queries);
$stale = MG_Google_Ads_Product_Performance::run_rolling_classification();
mg_state_expect(is_wp_error($stale) && $stale->get_error_code() === 'mg_ads_import_stale', 'A recent request cannot make old date coverage fresh.');
mg_state_expect(count($wpdb->queries) === $query_count, 'Stale data must be rejected before any classification writes.');
mg_state_expect($mg_test_options[MG_Google_Ads_Product_Performance::CLASSIFICATION_STATE_OPTION] === $last_classification, 'A skipped classification must retain the last successful summary and timestamp.');
mg_state_expect($wpdb->lock_releases === 1, 'A rejected classification must release its database lock.');
$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION]['automation_enabled'] = 1;
$scheduled_stale = MG_Google_Ads_Product_Performance::run_scheduled_classification();
mg_state_expect(is_wp_error($scheduled_stale) && $scheduled_stale->get_error_code() === 'mg_ads_import_stale', 'Scheduled classification must use the same freshness gate.');
mg_state_expect($mg_test_options[MG_Google_Ads_Product_Performance::CLASSIFICATION_STATE_OPTION] === $last_classification, 'A skipped scheduled run must not update the success timestamp.');
$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = $base_settings;
$wpdb->old_status = 'winner';
mg_state_expect(MG_Google_Ads_Product_Performance::get_feed_label(999) === 'winner', 'A stale import must freeze rather than erase the last published classification.');

$changed_threshold = $base_settings;
$changed_threshold['winner_conversions'] = 3;
$stale_save = MG_Google_Ads_Product_Performance::save_settings($changed_threshold);
mg_state_expect(is_wp_error($stale_save) && $stale_save->get_error_code() === 'mg_ads_import_stale', 'Threshold changes must also reject stale imported data.');
mg_state_expect($mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] === $base_settings, 'A rejected threshold change must retain settings and the published classification flag.');

$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION] = $fresh_state;
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION]['completed_at'] = $now - 3 * DAY_IN_SECONDS;
$mg_test_options[MG_Google_Ads_Product_Performance::SYNC_OPTION]['timestamp'] = $now - 3 * DAY_IN_SECONDS;
$stale = MG_Google_Ads_Product_Performance::validate_import_freshness();
mg_state_expect(is_wp_error($stale) && $stale->get_error_code() === 'mg_ads_import_stale', 'Recent date coverage alone cannot bypass the 48-hour completed-import limit.');
$mg_test_options[MG_Google_Ads_Product_Performance::SYNC_OPTION] = $fresh_sync;
$mg_test_options[MG_Google_Ads_Product_Performance::SYNC_OPTION]['scope'] = 'another-source';
mg_state_expect(is_wp_error(MG_Google_Ads_Product_Performance::validate_import_freshness()), 'A timestamp from a different Ads source must not refresh stale data.');
$mg_test_options[MG_Google_Ads_Product_Performance::SYNC_OPTION] = $fresh_sync;
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION]['end'] = wp_date('Y-m-d', $now - 5 * DAY_IN_SECONDS);
mg_state_expect(MG_Google_Ads_Product_Performance::validate_import_freshness() === true, 'Allow a two-day coverage delay beyond the configured conversion lag.');
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION]['end'] = wp_date('Y-m-d', $now - 6 * DAY_IN_SECONDS);
mg_state_expect(is_wp_error(MG_Google_Ads_Product_Performance::validate_import_freshness()), 'A third day of coverage delay must suspend classification.');

$legacy_click_settings = $base_settings;
$legacy_click_settings['loser_basis'] = 'clicks';
$legacy_click_settings['loser_clicks'] = 30;
unset($legacy_click_settings['loser_target_cpa']);
$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = $legacy_click_settings;
mg_state_expect(MG_Google_Ads_Product_Performance::get_settings()['loser_basis'] === 'cpa', 'Legacy click thresholds must no longer be selectable or executed.');
mg_state_expect(is_wp_error(MG_Google_Ads_Product_Performance::validate_loser_settings()), 'Legacy click mode requires a real business target instead of an invented CPA.');
$missing_target = MG_Google_Ads_Product_Performance::run_rolling_classification();
mg_state_expect(is_wp_error($missing_target) && $missing_target->get_error_code() === 'mg_ads_target_cpa_missing', 'Legacy click mode must suspend classification until an economic rule is configured.');
mg_state_expect(MG_Google_Ads_Product_Performance::get_import_scope() === $scope, 'Migrating the classification rule must preserve imported history.');

$cpa_settings = $base_settings;
$cpa_settings['enabled'] = 0;
$cpa_settings['loser_basis'] = 'cpa';
$invalid_cpa_settings = $cpa_settings;
$invalid_cpa_settings['loser_target_cpa'] = 'invalid';
$invalid_cpa = MG_Google_Ads_Product_Performance::save_settings($invalid_cpa_settings);
mg_state_expect(is_wp_error($invalid_cpa) && $invalid_cpa->get_error_code() === 'mg_ads_target_cpa', 'A malformed CPA value must not silently become a zero target.');
$mg_test_options[MG_Google_Ads_Product_Performance::SETTINGS_OPTION] = $cpa_settings;
$mg_test_options[MG_Google_Ads_Product_Performance::IMPORT_STATE_OPTION] = $fresh_state;
$wpdb->old_status = null;
$wpdb->metric_rows = array(array('offer_id' => 'DESIGN_men', 'impressions' => 100, 'clicks' => 100, 'cost_micros' => 100000 * 1000000, 'conversions' => 0.01, 'conversion_value' => 100, 'first_activity_date' => wp_date('Y-m-d', $now - 8 * DAY_IN_SECONDS)));
$young = MG_Google_Ads_Product_Performance::run_rolling_classification();
mg_state_expect(!is_wp_error($young) && $young['counts']['normal'] === 1, 'Observation time must exclude the configured conversion-lag days.');
$wpdb->metric_rows[0]['first_activity_date'] = wp_date('Y-m-d', $now - 9 * DAY_IN_SECONDS);
$mature = MG_Google_Ads_Product_Performance::run_rolling_classification();
mg_state_expect(!is_wp_error($mature) && $mature['counts']['loser'] === 1, 'Seven observed days and excessive CPA must classify the product as Loser.');
$wpdb->old_status = 'winner';
$winner = MG_Google_Ads_Product_Performance::run_rolling_classification();
mg_state_expect(!is_wp_error($winner) && $winner['counts']['winner'] === 1, 'The historical Winner behavior remains intact.');
$wpdb->lock_available = false;
$query_count = count($wpdb->queries);
$busy = MG_Google_Ads_Product_Performance::run_rolling_classification();
mg_state_expect(is_wp_error($busy) && $busy->get_error_code() === 'mg_ads_import_busy' && count($wpdb->queries) === $query_count, 'Classification must not race an import that owns the database lock.');

echo "Google Ads product performance state tests passed.\n";
