<?php
/** CLI regression tests: real file I/O, with WordPress/WooCommerce query and scheduler doubles. */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
$test_dir = sys_get_temp_dir() . '/mg-feed-test-' . bin2hex(random_bytes(6));
mkdir($test_dir);
$options = $events = $filters = $queries = array();
$products = range(1, 25);
$visited = array();
$mode = '';
$schedule_ok = true;
$assertions = 0;
function check($ok, $message) {
    $GLOBALS['assertions']++;
    if (!$ok) throw new RuntimeException($message);
}
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function wp_unslash($s) { return $s; }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function wp_upload_dir() { return array('basedir' => $GLOBALS['test_dir']); }
function wp_mkdir_p($path) { return mkdir($path, 0777, true); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function wp_next_scheduled($hook, $args) { return $GLOBALS['events'][$hook . ':' . implode(',', $args)] ?? false; }
function wp_schedule_single_event($time, $hook, $args) {
    if (!$GLOBALS['schedule_ok']) return false;
    $GLOBALS['events'][$hook . ':' . implode(',', $args)] = $time;
    return true;
}
function wp_clear_scheduled_hook($hook, $args) { unset($GLOBALS['events'][$hook . ':' . implode(',', $args)]); }
function add_filter($hook, $fn, $priority, $count) { $GLOBALS['filters'][$hook] = $fn; }
function remove_filter($hook, $fn, $priority) { unset($GLOBALS['filters'][$hook]); }
$wpdb = new class {
    public $posts = 'wp_posts';
    public function prepare($sql, $id) { return sprintf($sql, $id); }
};
function get_posts($args) {
    $GLOBALS['queries'][] = $args;
    check($args['posts_per_page'] === 10, 'query must be bounded');
    check($args['orderby'] === 'ID' && $args['order'] === 'ASC', 'stable cursor order');
    check($args['suppress_filters'] === false, 'cursor filter enabled');
    $query = (object) array('query_vars' => $args);
    $where = $GLOBALS['filters']['posts_where']('', $query);
    check($where === ' AND wp_posts.ID > ' . $args['mg_custom_feed_cursor'], 'cursor applied to SQL');
    check($GLOBALS['filters']['posts_where']('original', (object) array('query_vars' => array())) === 'original', 'unrelated queries unaffected');
    return array_slice(array_values(array_filter($GLOBALS['products'], static function ($id) use ($args) {
        return $id > $args['mg_custom_feed_cursor'];
    })), 0, $args['posts_per_page']);
}
function home_url() { return 'https://example.test/?a=1&b=2'; }
function get_bloginfo($key) { return 'Test Shop'; }
function get_woocommerce_currency() { return 'HUF'; }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function get_the_terms($id, $taxonomy) { return false; }
function wc_get_product($id) {
    $GLOBALS['visited'][] = $id;
    check((bool) wp_next_scheduled('mg_custom_feed_batch', array($GLOBALS['active_slug'])), 'watchdog exists before product work');
    if ($GLOBALS['mode'] === 'throw') throw new RuntimeException('Product failed');
    if ($GLOBALS['mode'] === 'slow') { $GLOBALS['mode'] = ''; usleep(5100000); }
    return new class($id) {
        private $id;
        public function __construct($id) { $this->id = $id; }
        public function get_sku() { return 'SKU' . $this->id; }
        public function get_name() { return 'Product & ' . $this->id; }
        public function get_short_description() { return 'Description'; }
        public function get_permalink() { return 'https://example.test/product/' . $this->id; }
        public function get_price() { return 4000; }
        public function is_in_stock() { return true; }
    };
}
class MG_Virtual_Variant_Manager {
    public static function get_frontend_config($product) {
        return array('types' => array(
            'shirt' => array('label' => 'Póló', 'preview_url' => 'https://example.test/shirt.png'),
            'mug' => array('label' => 'Bögre', 'preview_url' => 'https://example.test/mug.png')));
    }
}
function current_user_can($cap) { return $GLOBALS['authorized'] ?? true; }
function check_ajax_referer($action, $key) { check($action === 'mg_custom_feed_progress' && $key === 'nonce', 'AJAX checks nonce'); }
function wp_send_json_error($data, $code) { throw new RuntimeException('HTTP ' . $code); }
function wp_send_json_success($data) { $GLOBALS['ajax_result'] = $data; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function wp_die($message, $title = '', $args = array()) { throw new RuntimeException('HTTP ' . ($args['response'] ?? 500)); }
require __DIR__ . '/../includes/class-custom-feed-manager.php';
function setup_feed($slug) {
    $GLOBALS['active_slug'] = $slug;
    $GLOBALS['options']['mg_custom_feeds'][$slug] = array('name' => 'Feed & Test', 'format' => 'google',
        'product_type' => 'shirt', 'category_id' => 42, 'force_gender' => 'female', 'force_age_group' => 'kids');
    return MG_Custom_Feed_Manager::get_feed_file_path($slug);
}
function finish_feed($slug) {
    for ($i = 0; $i < 20 && MG_Custom_Feed_Manager::get_state($slug)['status'] === 'running'; $i++) {
        MG_Custom_Feed_Manager::process_batch($slug);
    }
    check(MG_Custom_Feed_Manager::get_state($slug)['status'] === 'complete', 'feed completed');
}
try {
    $path = setup_feed('normal');
    file_put_contents($path, 'OLD FEED');
    check(MG_Custom_Feed_Manager::generate_feed_to_file('normal'), 'queue succeeds');
    check(!$visited && !$queries, 'queue never queries or renders products');
    MG_Custom_Feed_Manager::process_batch('normal');
    $state = MG_Custom_Feed_Manager::get_state('normal');
    check($state['processed'] === 10 && $state['cursor'] === 10, 'first batch checkpoints');
    check(file_get_contents($path) === 'OLD FEED', 'old feed remains during generation');
    check($queries[0]['tax_query'][0]['terms'] === 42 && $queries[0]['tax_query'][0]['include_children'], 'category filter preserved');
    check(!$filters, 'temporary SQL filter removed');
    MG_Custom_Feed_Manager::generate_feed_to_file('normal');
    check(MG_Custom_Feed_Manager::get_state('normal') === $state, 'repeated generation does not reset cursor');

    // Simulate an interrupted worker that wrote an item but never committed its checkpoint.
    file_put_contents($path . '.tmp', '<item>INTERRUPTED', FILE_APPEND);
    $products = range(2, 25); // Deleting an earlier product must not skip ID 11.
    $busy = fopen($path . '.lock', 'c');
    flock($busy, LOCK_EX);
    wp_clear_scheduled_hook('mg_custom_feed_batch', array('normal'));
    MG_Custom_Feed_Manager::process_batch('normal');
    check(MG_Custom_Feed_Manager::get_state('normal') === $state, 'concurrent worker cannot advance state');
    check((bool) wp_next_scheduled('mg_custom_feed_batch', array('normal')), 'busy watchdog reschedules recovery');
    flock($busy, LOCK_UN);
    fclose($busy);
    finish_feed('normal');
    $xml = file_get_contents($path);
    check(substr_count($xml, '<item>') === 25, 'every product appears exactly once despite interruption and deletion');
    check(strpos($xml, 'INTERRUPTED') === false && strpos($xml, 'SKU11_shirt') !== false, 'partial tail removed and cursor preserved');
    check(strpos($xml, '_mug') === false, 'product type filter preserved');
    check(substr_count($xml, '<g:gender>female</g:gender>') === 25, 'gender override preserved');
    check(substr_count($xml, '<g:age_group>kids</g:age_group>') === 25, 'age override preserved');
    check(simplexml_load_string($xml) !== false, 'output is valid XML');
    check(!file_exists($path . '.tmp') && !wp_next_scheduled('mg_custom_feed_batch', array('normal')), 'completion cleans temp file and scheduled job');
    $before = count($visited);
    MG_Custom_Feed_Manager::process_batch('normal');
    check(count($visited) === $before, 'late callback does not restart completed job');

    $path = setup_feed('failure');
    file_put_contents($path, 'KEEP OLD');
    MG_Custom_Feed_Manager::generate_feed_to_file('failure');
    $mode = 'throw';
    MG_Custom_Feed_Manager::process_batch('failure');
    check(MG_Custom_Feed_Manager::get_state('failure')['status'] === 'failed', 'product exception marks failure');
    check(file_get_contents($path) === 'KEEP OLD', 'error preserves published file');
    check(!MG_Custom_Feed_Manager::generate_feed_to_file('failure', false), 'crawler retries are throttled after failure');
    $mode = '';
    MG_Custom_Feed_Manager::generate_feed_to_file('failure');
    finish_feed('failure');

    setup_feed('slow');
    MG_Custom_Feed_Manager::generate_feed_to_file('slow');
    $mode = 'slow';
    MG_Custom_Feed_Manager::process_batch('slow');
    check(MG_Custom_Feed_Manager::get_state('slow')['processed'] === 1, 'time budget yields before ten products');

    setup_feed('fatal');
    MG_Custom_Feed_Manager::generate_feed_to_file('fatal');
    $options['mg_custom_feed_job_fatal']['attempts'] = 3;
    MG_Custom_Feed_Manager::process_batch('fatal');
    check(MG_Custom_Feed_Manager::get_state('fatal')['status'] === 'failed', 'repeated killed workers stop retrying');

    $path = setup_feed('missing-temp');
    MG_Custom_Feed_Manager::generate_feed_to_file('missing-temp');
    MG_Custom_Feed_Manager::process_batch('missing-temp');
    unlink($path . '.tmp');
    MG_Custom_Feed_Manager::process_batch('missing-temp');
    check(MG_Custom_Feed_Manager::get_state('missing-temp')['status'] === 'failed', 'missing partial file cannot publish truncated feed');

    setup_feed('no-scheduler');
    $schedule_ok = false;
    check(!MG_Custom_Feed_Manager::generate_feed_to_file('no-scheduler'), 'scheduler failure is reported');
    check(MG_Custom_Feed_Manager::get_state('no-scheduler')['status'] === 'failed', 'scheduler error visible in status');
    $schedule_ok = true;

    $path = setup_feed('empty');
    $products = array();
    MG_Custom_Feed_Manager::generate_feed_to_file('empty');
    finish_feed('empty');
    check(simplexml_load_file($path) !== false && strpos(file_get_contents($path), '<item>') === false, 'empty catalog produces valid feed');

    setup_feed('ajax');
    $products = range(1, 2);
    MG_Custom_Feed_Manager::generate_feed_to_file('ajax');
    $_POST['slug'] = 'ajax';
    MG_Custom_Feed_Manager::ajax_progress();
    check($ajax_result['status'] === 'running' && strpos($ajax_result['message'], '2 termék') !== false, 'admin AJAX advances job and reports progress');
    $authorized = false;
    try { MG_Custom_Feed_Manager::ajax_progress(); throw new RuntimeException('authorization missing'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'HTTP 403', 'unauthorized AJAX rejected'); }
    $authorized = true;

    setup_feed('first-request');
    $_GET['mg_custom_feed'] = 'first-request';
    $before = count($visited);
    try { MG_Custom_Feed_Manager::check_feed_request(); throw new RuntimeException('HTTP response missing'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'HTTP 503', 'initial feed request returns retryable 503'); }
    check(count($visited) === $before && $nocache, 'initial feed request never renders products and is not cached');
    $_GET['mg_custom_feed'] = 'unknown';
    try { MG_Custom_Feed_Manager::check_feed_request(); throw new RuntimeException('404 missing'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'HTTP 404', 'unknown feed returns 404'); }
    echo 'PASS: ' . $assertions . " assertions\n";
} finally {
    foreach (glob($test_dir . '/mg_feeds/*') as $file) unlink($file);
    rmdir($test_dir . '/mg_feeds');
    rmdir($test_dir);
}
