<?php
namespace Automattic\WooCommerce\Utilities {
    class OrderUtil {
        public static $hpos = false;
        public static function custom_orders_table_usage_is_enabled() { return self::$hpos; }
    }
}
namespace {
define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('MG_VERSION', '2.39.1');
$GLOBALS['allow_report'] = true;
$GLOBALS['valid_nonce'] = true;
$GLOBALS['prefix'] = 'wc_order_attribution_';
$GLOBALS['hooks'] = array();
function current_user_can($cap) { return $GLOBALS['allow_report'] && !in_array($cap, $GLOBALS['denied_caps'] ?? array(), true); }
function wp_timezone() { return new \DateTimeZone('Europe/Budapest'); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wp_unslash($value) { return $value; }
function absint($value) { return abs((int) $value); }
function apply_filters($name, $value) { return $name === 'wc_order_attribution_tracking_field_prefix' ? $GLOBALS['prefix'] : $value; }
function wc_get_is_paid_statuses() { return array('processing', 'completed'); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function add_action($hook, $callback) { $GLOBALS['hooks'][$hook] = $callback; }
function check_ajax_referer($action, $field, $die) { return $GLOBALS['valid_nonce']; }
function wp_send_json_error($data, $status) { $GLOBALS['response'] = array('success' => false, 'data' => $data, 'status' => $status); }
function wp_send_json_success($data) { $GLOBALS['response'] = array('success' => true, 'data' => $data, 'status' => 200); }
function wp_die($message) { throw new \RuntimeException($message); }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function __($value, $domain = '') { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_attr__($value, $domain = '') { return esc_attr($value); }
function admin_url($path) { return 'https://report.test/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function plugins_url($path, $file) { return 'https://report.test/' . $path; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['styles'][$handle] = $args; }
function wp_enqueue_script($handle, ...$args) { $GLOBALS['scripts'][$handle] = $args; }
function wp_localize_script($handle, $name, $data) { $GLOBALS['localized'][$handle] = $data; }
function wp_create_nonce($action) { return 'test-nonce'; }
function wp_date($format) { return (new \DateTimeImmutable('2026-09-28', wp_timezone()))->format($format); }
function get_woocommerce_currency() { return 'HUF'; }
class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
require ABSPATH . 'includes/class-order-attribution-report.php';
require ABSPATH . 'admin/class-order-attribution-page.php';
require ABSPATH . 'admin/class-admin-page.php';
if (in_array('--fixture', $argv, true)) {
    echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>';
    echo 'body{margin:0;font:14px Arial;background:#f4f6fa}main{padding:20px;max-width:1400px;margin:auto}table{border-collapse:collapse;width:100%}td,th{padding:12px;text-align:left}th{background:#f1f5f9}.striped tbody tr:nth-child(odd){background:#f8fafc}.button{cursor:pointer;padding:5px 12px;border:1px solid #2271b1;border-radius:4px}.button-primary{color:#fff;background:#2271b1}.button-link{background:none;border:0;color:#2271b1;cursor:pointer}input,select{padding:5px;background:#fff;border:1px solid #94a3b8;border-radius:4px}*{box-sizing:border-box}';
    echo file_get_contents(ABSPATH . 'assets/css/admin-ui.css');
    echo file_get_contents(ABSPATH . 'assets/css/order-attribution.css');
    echo 'body{padding-left:20px}';
    echo '</style></head><body>';
    $_GET = $_REQUEST = array('page' => 'mockup-generator', 'mg_tab' => 'order_attribution');
    MG_Admin_Page::render_page();
    echo '</body></html>';
    exit;
}

class ReportDatabase {
    public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
    public $last_error = ''; public $calls = 0; public $fail = false; private $file;
    public function __construct() { $this->file = tempnam(sys_get_temp_dir(), 'mg-report-'); }
    public function __destruct() { if (is_file($this->file)) unlink($this->file); }
    public function prepare($sql, ...$args) {
        $values = count($args) === 1 && is_array($args[0]) ? $args[0] : $args;
        return preg_replace_callback('/%[ds]/', function ($match) use (&$values) {
            $value = array_shift($values);
            return $match[0] === '%d' ? (string) (int) $value : "'" . str_replace("'", "''", $value) . "'";
        }, $sql);
    }
    public function run($sql, $mode = 'query') {
        $process = proc_open(array(getenv('NODE_BIN') ?: 'node', __DIR__ . '/order-attribution-sqlite.js', $this->file, $mode), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        fwrite($pipes[0], $sql); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new \RuntimeException($error . "\n" . $sql);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    public function get_results($sql, $format) {
        $this->calls++;
        $this->last_error = $this->fail ? 'secret internal database error' : '';
        return $this->fail ? null : $this->run($sql);
    }
}
$wpdb = new ReportDatabase();
$checks = 0;
function same($expected, $actual, $label) {
    global $checks; $checks++;
    if ($expected !== $actual) throw new \RuntimeException($label . ': expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
}
function source($source, $medium = '', $campaign = '', $type = 'utm') {
    return array('utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => $campaign, 'source_type' => $type);
}
$sql = 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_date_gmt TEXT, post_parent INTEGER DEFAULT 0);
CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT);
CREATE TABLE wp_wc_orders (id INTEGER PRIMARY KEY, type TEXT, status TEXT, date_created_gmt TEXT, total_amount REAL, currency TEXT, parent_order_id INTEGER DEFAULT 0);
CREATE TABLE wp_wc_orders_meta (id INTEGER PRIMARY KEY, order_id INTEGER, meta_key TEXT, meta_value TEXT);
CREATE INDEX post_meta_order ON wp_postmeta(post_id); CREATE INDEX hpos_meta_order ON wp_wc_orders_meta(order_id);';
function fixture_order($id, $created, $amount, $currency, $attribution, $status = 'wc-completed', $type = 'shop_order', $parent = 0) {
    global $sql, $wpdb;
    $sql .= $wpdb->prepare('INSERT INTO wp_posts VALUES (%d,%s,%s,%s,%d);', $id, $type, $status, $created, $parent);
    $sql .= $wpdb->prepare('INSERT INTO wp_wc_orders VALUES (%d,%s,%s,%s,%s,%s,%d);', $id, $type, $status, $created, (string) ($type === 'shop_order_refund' ? -abs($amount) : $amount), $currency, $parent);
    foreach (array_merge(array('_order_total' => $amount, '_order_currency' => $currency, '_refund_amount' => abs($amount)), array_combine(array_map(function ($key) { return '_wc_order_attribution_' . $key; }, array_keys($attribution)), array_values($attribution))) as $key => $value) {
        $sql .= $wpdb->prepare('INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (%d,%s,%s);', $id, $key, (string) $value);
        $sql .= $wpdb->prepare('INSERT INTO wp_wc_orders_meta(order_id,meta_key,meta_value) VALUES (%d,%s,%s);', $id, $key, (string) $value);
    }
}
fixture_order(1, '2026-08-31 22:00:00', 10000, 'HUF', source('fb', 'paid_social', 'Őszi bögrék'));
fixture_order(2, '2026-09-06 21:59:59', 20000, 'HUF', source('facebook', 'paid_social', 'Őszi bögrék'));
fixture_order(3, '2026-09-06 22:00:00', 30000, 'HUF', source('ig', 'paid_social', 'Őszi bögrék'));
fixture_order(4, '2026-09-10 12:00:00', 40000, 'HUF', source('google', 'organic', '', 'organic'));
fixture_order(5, '2026-09-11 12:00:00', 50000, 'HUF', source('l.facebook.com', 'referral', '', 'referral'));
fixture_order(6, '2026-09-12 12:00:00', 60000, 'HUF', source('(direct)', '(none)', '(none)', 'typein'));
fixture_order(7, '2026-09-13 12:00:00', 70000, 'HUF', array());
fixture_order(8, '2026-09-14 12:00:00', 1000, 'HUF', source('fb', 'paid_social', 'Visszatérítve'), 'wc-refunded');
fixture_order(9, '2026-09-15 12:00:00', 12.3456, 'EUR', source('fb', 'paid_social', 'Őszi bögrék'));
fixture_order(10, '2026-09-16 12:00:00', 99999, 'HUF', source('fb'), 'wc-pending');
fixture_order(11, '2026-09-16 12:00:00', 99999, 'HUF', source('fb'), 'wc-failed');
fixture_order(12, '2026-09-16 12:00:00', 99999, 'HUF', source('fb'), 'wc-cancelled');
fixture_order(13, '2026-08-31 21:59:59', 99999, 'HUF', source('fb'));
fixture_order(14, '2026-09-30 22:00:00', 99999, 'HUF', source('fb'));
fixture_order(15, '2026-09-18 12:00:00', 8000, 'HUF', source('facebook.com', 'referral', '', 'referral'), 'wc-manufacturing');
fixture_order(1001, '2026-10-01 12:00:00', 500, 'HUF', array(), 'wc-completed', 'shop_order_refund', 1);
fixture_order(1002, '2026-10-02 12:00:00', 100, 'HUF', array(), 'wc-completed', 'shop_order_refund', 1);
fixture_order(1003, '2026-10-02 12:00:00', 9000, 'HUF', array(), 'trash', 'shop_order_refund', 1);
fixture_order(1004, '2026-09-14 12:00:00', 1000, 'HUF', array(), 'wc-completed', 'shop_order_refund', 8);
// Duplicate metadata is common in legacy stores; it must not multiply totals.
$sql .= "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES(1,'_wc_order_attribution_utm_source','fb');
INSERT INTO wp_wc_orders_meta(order_id,meta_key,meta_value) VALUES(1,'_wc_order_attribution_utm_source','fb');";
$wpdb->run($sql, 'exec');
$range = array('from' => '2026-09-01', 'to' => '2026-09-30');
$results = array();
foreach (array(false, true) as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $data = MG_Order_Attribution_Report::read_batch($range);
    same(true, $data['done'], 'Small query completes');
    same(10, $data['total'], 'Paid, manufacturing and refunded orders within local boundaries');
    same(10, array_sum(array_column($data['rows'], 'orders')), 'Exactly one count per order');
    same(16000000, array_sum(array_column($data['rows'], 'refunds')), 'Partial/full refunds included, trashed refund excluded');
    $first = array_values(array_filter($data['rows'], function ($row) { return $row['day'] === '2026-09-01'; }))[0];
    same('facebook', $first['platform'], 'Platform alias normalization');
    same(100000000, $first['gross'], 'Gross order value is not multiplied by duplicate metadata');
    same(6000000, $first['refunds'], 'Later refunds restate original purchase period');
    same('2026-09-07', $data['rows'][2]['day'], 'Local midnight conversion');
    same(123456, $data['rows'][8]['gross'], 'Currency precision preserved');
    same('EUR', $data['rows'][8]['currency'], 'Foreign currency remains separate');
    $manufacturing = array_values(array_filter($data['rows'], function ($row) { return $row['day'] === '2026-09-18'; }));
    same(1, count($manufacturing), 'Earlier manufacturing purchases stay in the report');
    same('', $manufacturing[0]['campaign'], 'Historical purchases do not require UTM campaign tags');
    same(80000000, $manufacturing[0]['gross'], 'Manufacturing order value remains in revenue');
    $results[] = $data['rows'];
}
same($results[0], $results[1], 'Legacy and HPOS produce identical facts');
same('organic', MG_Order_Attribution_Report::classify(source('google', 'organic', '', 'organic'))['channel'], 'Google organic is not an ad');
same('referral', MG_Order_Attribution_Report::classify(source('facebook.com', 'referral', '', 'referral'))['channel'], 'Facebook referral is not an ad');
same('instagram', MG_Order_Attribution_Report::classify(source('https://l.instagram.com/link'))['platform'], 'Instagram referrer');
same('facebook.com.evil.test', MG_Order_Attribution_Report::classify(source('facebook.com.evil.test'))['platform'], 'Host matching respects domain boundaries');
same('unknown', MG_Order_Attribution_Report::classify(array())['platform'], 'Missing source is not direct');
same('', MG_Order_Attribution_Report::clean_value('(none)'), 'Missing campaign sentinel');
same('admin', MG_Order_Attribution_Report::classify(source('', '', '', 'admin'))['platform'], 'Admin orders are identified');
$spring = MG_Order_Attribution_Report::parse_range(array('from' => '2026-03-29', 'to' => '2026-03-29'));
same(23 * 3600, strtotime($spring['until']) - strtotime($spring['from']), 'Spring DST day has 23 hours');
$autumn = MG_Order_Attribution_Report::parse_range(array('from' => '2026-10-25', 'to' => '2026-10-25'));
same(25 * 3600, strtotime($autumn['until']) - strtotime($autumn['from']), 'Autumn DST day has 25 hours');
foreach (array(array('2026-02-30', '2026-03-01'), array('2026-09-30', '2026-09-01'), array('2000-01-01', '2026-01-01'), array("2026-09-01' OR 1=1", '2026-09-30')) as $invalid) {
    same(true, is_wp_error(MG_Order_Attribution_Report::parse_range(array('from' => $invalid[0], 'to' => $invalid[1]))), 'Invalid dates rejected');
}
same(true, is_wp_error(MG_Order_Attribution_Report::read_batch($range + array('cursor' => array(1)))), 'Array cursor rejected');
$empty = MG_Order_Attribution_Report::read_batch(array('from' => '2025-01-01', 'to' => '2025-01-01'));
same(array(), $empty['rows'], 'Empty range returns no invented facts');

// The actual consolidated shell renders the tab and preserves its permission boundary.
$_GET = $_REQUEST = array('page' => 'mockup-generator', 'mg_tab' => 'order_attribution');
$tabs = MG_Admin_Page::get_accessible_tabs();
same('marketing', $tabs['order_attribution']['group'], 'Report is registered in Marketing & Mérés');
ob_start(); MG_Admin_Page::render_page(); $shell = ob_get_clean();
same(true, strpos($shell, 'id="mg-order-attribution"') !== false, 'Shell actually renders the report, not a placeholder');
same(true, strpos($shell, 'id="tab-order_attribution"') !== false, 'Active report panel has its own navigation target');
MG_Order_Attribution_Page::enqueue_assets();
same(true, isset($GLOBALS['scripts']['mg-order-attribution']), 'Report scripts enqueued on the actual admin URL');
same('test-nonce', $GLOBALS['localized']['mg-order-attribution']['nonce'], 'AJAX nonce supplied only on report page');
$GLOBALS['scripts'] = array(); $_GET['mg_tab'] = 'gads';
MG_Order_Attribution_Page::enqueue_assets();
same(array(), $GLOBALS['scripts'], 'Report assets do not load on unrelated admin tabs');
$GLOBALS['denied_caps'] = array('manage_woocommerce');
same(false, isset(MG_Admin_Page::get_accessible_tabs()['order_attribution']), 'Product editors cannot open sales statistics');
$GLOBALS['denied_caps'] = array();

// More than one batch: new orders cannot enter the original report snapshot.
$sql = '';
for ($i = 20; $i < 530; $i++) fixture_order($i, '2026-09-20 12:00:00', 100, 'HUF', source('google', 'cpc', 'PMax'));
$wpdb->run($sql, 'exec');
foreach (array(false, true) as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $page = MG_Order_Attribution_Report::read_batch($range);
    same(false, $page['done'], 'Large report is paginated');
    same(250, $page['processed'], 'Batch is bounded');
    $count = $page['processed']; $ceiling = $page['ceiling'];
    do {
        $next = MG_Order_Attribution_Report::read_batch($range + array('cursor' => $page['cursor'], 'ceiling' => $ceiling));
        same(true, $next['cursor'] > $page['cursor'], 'Cursor advances');
        $count += $next['processed']; $page = $next;
    } while (!$page['done']);
    same(520, $count, 'Every purchase counted once across batches');
}
$sql = ''; fixture_order(900, '2026-09-21 12:00:00', 100, 'HUF', source('fb'));
$wpdb->run($sql, 'exec');
$last = MG_Order_Attribution_Report::read_batch($range + array('cursor' => 529, 'ceiling' => $ceiling));
same(0, $last['processed'], 'Orders added beyond snapshot are excluded until refresh');

MG_Order_Attribution_Report::init();
same(false, isset($GLOBALS['hooks']['wp_ajax_nopriv_mg_order_attribution_report']), 'Report is never public');
$before = $wpdb->calls;
$GLOBALS['allow_report'] = false; MG_Order_Attribution_Report::ajax_report();
same(403, $GLOBALS['response']['status'], 'Unauthorized users rejected');
$GLOBALS['allow_report'] = true; $GLOBALS['valid_nonce'] = false; MG_Order_Attribution_Report::ajax_report();
same(403, $GLOBALS['response']['status'], 'Invalid nonce rejected');
same($before, $wpdb->calls, 'Authorization occurs before database access');
$GLOBALS['valid_nonce'] = true; $_POST = $range;
$wpdb->fail = true; MG_Order_Attribution_Report::ajax_report();
same(500, $GLOBALS['response']['status'], 'Database errors are not shown as an empty report');
same(false, strpos($GLOBALS['response']['data']['message'], 'secret') !== false, 'Internal DB details not disclosed');
echo "PASS: {$checks} order attribution checks (actual SQL, legacy + HPOS).\n";
}
