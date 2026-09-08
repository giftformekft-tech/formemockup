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
    'loser_clicks' => 30,
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
$classification_only_settings['loser_basis'] = 'clicks';
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

echo "Google Ads product performance state tests passed.\n";
