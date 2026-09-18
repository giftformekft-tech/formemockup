<?php
define('ABSPATH', __DIR__);
function apply_filters($name, $value) { return $value; }
function nocache_headers() {}
function get_woocommerce_currency() { return 'HUF'; }
function absint($value) { return abs((int) $value); }
function wc_clean($value) { return $value; }
function wp_unslash($value) { return $value; }
function wp_generate_uuid4() { return 'unique-addition'; }
function wc_get_price_including_tax($product, $args) { return 3990 * $args['qty']; }
class JsonResult extends Exception { public $data; public function __construct($data) { $this->data = $data; } }
function wp_send_json_success($data) { throw new JsonResult($data); }
class MG_Consent_Bridge { public static $consent = 'granted'; public static function detect_server_consent() { return self::$consent; } }
class FakeProduct { function get_id() { return 7; } function get_name() { return 'Test'; } }
class FakeSession {
    public $data = array();
    function get($key, $fallback) { return $this->data[$key] ?? $fallback; }
    function set($key, $value) { $this->data[$key] = $value; }
}
class FakeCart {
    function calculate_totals() {}
    function get_cart_item($key) { return $key === 'valid' ? array('data' => new FakeProduct(), 'quantity' => 2) : array(); }
    function get_cart() { return array($this->get_cart_item('valid')); }
    function is_empty() { return false; }
    function get_total($context) { return 7980; }
}
class FakeOrder {
    public $status = 'processing';
    function get_order_key() { return 'private-key'; }
    function has_status($statuses) { return in_array($this->status, $statuses, true); }
    function get_items() { return array(); }
    function get_currency() { return 'HUF'; }
    function get_total() { return 3990; }
}
$wc = (object) array('cart' => new FakeCart(), 'session' => new FakeSession());
$order = new FakeOrder();
function WC() { return $GLOBALS['wc']; }
function wc_get_order($id) { return $id === 9 ? $GLOBALS['order'] : false; }
require __DIR__ . '/../includes/class-openai-pixel.php';
function check($condition, $message) { if (!$condition) throw new Exception($message); }
function events($post = array()) {
    $_POST = $post;
    try { MG_OpenAI_Pixel::events(); } catch (JsonResult $result) { return $result->data; }
    throw new Exception('Missing response');
}
check(MG_OpenAI_Pixel::amount(3990, 'HUF') === 399000, 'HUF must use ISO units, not display decimals');
check(MG_OpenAI_Pixel::amount(25.99, 'USD') === 2599, 'USD cents');
check(MG_OpenAI_Pixel::amount(100, 'JPY') === 100, 'JPY units');
check(MG_OpenAI_Pixel::amount(1.234, 'KWD') === 1234, 'KWD minor units');
MG_Consent_Bridge::$consent = 'denied';
MG_OpenAI_Pixel::remember_addition('valid', 7, 2);
check(!$wc->session->data, 'No addition storage without consent');
check(!events(array('order_id' => 9, 'order_key' => 'private-key'))['events'], 'No data without consent');
MG_Consent_Bridge::$consent = 'granted';
MG_OpenAI_Pixel::remember_addition('valid', 7, 2);
$result = events(array('checkout' => 1));
check(count($result['events']) === 2, 'Confirmed addition and checkout');
check($result['events'][0]['data']['amount'] === 798000, 'Actual added quantity and tax-inclusive value');
check(!events()['events'], 'Consumed additions do not repeat');
check(!events(array('order_id' => 9, 'order_key' => 'wrong'))['events'], 'Wrong order key must disclose nothing');
check(!events(array('order_id' => 9))['events'], 'Missing order key must disclose nothing');
$result = events(array('order_id' => 9, 'order_key' => 'private-key'));
check($result['events'][0]['data']['amount'] === 399000, 'Authorized order amount');
check($result['events'][0]['id'] === 'mg_openai_order_9', 'Stable purchase event ID');
$order->status = 'pending';
$result = events(array('order_id' => 9, 'order_key' => 'private-key'));
check(!$result['events'] && $result['pending'], 'Unpaid orders wait');
$order->status = 'failed';
$result = events(array('order_id' => 9, 'order_key' => 'private-key'));
check(!$result['events'] && !$result['pending'], 'Failed orders never convert');
echo "OpenAI Pixel: currency, consent, actual additions, guest authorization and order status checks passed.\n";
