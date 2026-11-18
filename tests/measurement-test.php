<?php
/**
 * Google Ads + Meta mérés: pótló modul, köszönőoldal, kosár/pénztár események.
 *
 *   php tests/measurement-test.php            egységtesztek
 *   php tests/measurement-test.php --fixture <oldal>   HTML a böngészős teszthez
 */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('MG_VERSION', 'test');

// A CLI az első kiírt bájt után "elküldöttnek" tekinti a fejléceket, a
// cookie-kezelés viszont headers_sent()-re figyel: mindent pufferelünk.
ob_start();

$GLOBALS['mg_test'] = array(
    'options' => array(
        'mg_gads_settings' => array('conversion_id' => 'AW-123456', 'purchase_label' => 'AbCdEf'),
        'mg_fb_pixel_settings' => array('pixel_id' => '987654321', 'server_side_enabled' => 1, 'access_token' => 'token'),
    ),
    'orders' => array(),
    'cookies_sent' => array(),
    'page' => '',
    'query' => array(),
    'cart' => array(),
);

function add_action() {}
function add_filter() {}
function __($text) { return $text; }
function get_option($name, $default = false) { return $GLOBALS['mg_test']['options'][$name] ?? $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, (array) $args); }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_ssl() { return true; }
function absint($value) { return abs((int) $value); }
function wp_unslash($value) { return $value; }
function wc_clean($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9_]+/', '-', strtolower((string) $value)), '-'); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function esc_js($value) { return addslashes((string) $value); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url_raw($value) { return (string) $value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function admin_url($path = '') { return 'https://shop.test/wp-admin/' . $path; }
function nocache_headers() {}
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function remove_accents($value) { return strtr($value, array('á' => 'a', 'é' => 'e', 'ő' => 'o', 'Á' => 'A')); }
function get_woocommerce_currency() { return 'HUF'; }
function get_bloginfo() { return 'Forme'; }
function get_the_terms() { return array(); }
function is_wp_error() { return false; }
function get_query_var($name) { return $GLOBALS['mg_test']['query'][$name] ?? ''; }
function is_order_received_page() { return $GLOBALS['mg_test']['page'] === 'order-received'; }
function is_checkout() { return in_array($GLOBALS['mg_test']['page'], array('checkout', 'order-received', 'order-pay'), true); }
function is_cart() { return $GLOBALS['mg_test']['page'] === 'cart'; }
function is_wc_endpoint_url($endpoint) { return $GLOBALS['mg_test']['page'] === $endpoint; }
function wc_setcookie($name, $value, $expire = 0, $secure = false, $httponly = false) {
    $GLOBALS['mg_test']['cookies_sent'][$name] = array('value' => $value, 'expire' => $expire, 'httponly' => $httponly);
}
function wc_get_order($id) {
    if ($id instanceof WC_Order) return $id;
    return $GLOBALS['mg_test']['orders'][(int) $id] ?? false;
}
function wc_get_order_id_by_order_key($key) {
    foreach ($GLOBALS['mg_test']['orders'] as $order) if ($order->key === $key) return $order->id;
    return 0;
}
function wc_get_product() { return false; }
function WC() { return (object) array('cart' => new FakeCart()); }

class JsonResult extends Exception {
    public $data;
    public function __construct($data) { parent::__construct('json'); $this->data = $data; }
}
function wp_send_json_success($data = null) { throw new JsonResult(array('success' => true, 'data' => $data)); }
function wp_send_json_error($data = null) { throw new JsonResult(array('success' => false, 'data' => $data)); }

class FakeProduct {
    function get_id() { return 7; }
    function get_parent_id() { return 0; }
    function get_sku() { return 'SKU7'; }
    function get_name() { return 'Teszt minta'; }
    function get_price() { return 4990; }
}
class FakeItem {
    function get_product() { return new FakeProduct(); }
    function get_meta($key) { return $key === 'mg_product_type' ? 'ferfi-polo' : ''; }
    function get_quantity() { return 2; }
    function get_total() { return 9980; }
    function get_subtotal() { return 9980; }
}
class FakeCart {
    function get_cart() { return $GLOBALS['mg_test']['cart']; }
    function get_discount_total() { return 0; }
}
class WC_Order {
    public $id; public $key; public $status; public $meta = array(); public $created; public $saves = 0;
    public $phone = '06 30 123 4567'; public $email = 'Teszt.Vevo@Gmail.com'; public $country = 'HU'; public $gateway = 'card';
    function __construct($id, $key, $status) { $this->id = $id; $this->key = $key; $this->status = $status; $this->created = time(); }
    function get_id() { return $this->id; }
    function get_order_key() { return $this->key; }
    function get_order_number() { return (string) $this->id; }
    function get_status() { return $this->status; }
    function get_payment_method() { return $this->gateway; }
    function get_meta($key) { return $this->meta[$key] ?? ''; }
    function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    function delete_meta_data($key) { unset($this->meta[$key]); }
    function save() { $this->saves++; }
    function get_date_created() { $t = $this->created; return new class($t) { private $t; function __construct($t) { $this->t = $t; } function getTimestamp() { return $this->t; } }; }
    function get_date_paid() { return null; }
    function get_total() { return 11470; }
    function get_currency() { return 'HUF'; }
    function get_discount_total() { return 0; }
    function get_items() { return array(new FakeItem()); }
    function get_customer_id() { return 0; }
    function get_billing_email() { return $this->email; }
    function get_billing_phone() { return $this->phone; }
    function get_billing_first_name() { return 'Vevő'; }
    function get_billing_last_name() { return 'Teszt'; }
    function get_billing_address_1() { return 'Fő utca 1.'; }
    function get_billing_city() { return 'Budapest'; }
    function get_billing_state() { return 'BU'; }
    function get_billing_postcode() { return '1011'; }
    function get_billing_country() { return $this->country; }
    function get_checkout_order_received_url() { return 'https://shop.test/penztar/order-received/' . $this->id . '/?key=' . $this->key; }
}

require __DIR__ . '/../includes/class-consent-bridge.php';
require __DIR__ . '/../includes/class-google-ads-reliability.php';
require __DIR__ . '/../includes/class-google-ads-tracking.php';
require __DIR__ . '/../includes/class-facebook-pixel-reliability.php';
require __DIR__ . '/../includes/class-facebook-pixel.php';
require __DIR__ . '/../includes/class-purchase-recovery.php';

function check($condition, $message) { if (!$condition) throw new Exception($message); }
function capture(callable $callback) { ob_start(); $callback(); return ob_get_clean(); }
function order($id, $key, $status) { return $GLOBALS['mg_test']['orders'][$id] = new WC_Order($id, $key, $status); }
function reset_request() {
    $_COOKIE = array();
    $_GET = array();
    $_POST = array();
    $GLOBALS['mg_test']['cookies_sent'] = array();
    $GLOBALS['mg_test']['page'] = '';
    $GLOBALS['mg_test']['query'] = array();
}
function pending() {
    try { MG_Purchase_Recovery::ajax_pending_conversions(); } catch (JsonResult $result) { return $result->data['data']; }
    throw new Exception('Missing AJAX response');
}
function cart_item($type = 'ferfi-polo') {
    return array('data' => new FakeProduct(), 'quantity' => 2, 'line_total' => 9980, 'mg_product_type' => $type);
}

/* ---------------------------------------------------------------------
 * Böngészős teszt oldalai
 * ------------------------------------------------------------------ */
if (($argv[1] ?? '') === '--fixture') {
    $page = $argv[2] ?? 'thankyou';
    $order_status = $argv[3] ?? 'processing';
    reset_request();
    $order = order(555, 'wc_order_fixture', $order_status);
    $_COOKIE['mg_gads_consent'] = 'granted';
    if ($page === 'thankyou') {
        $GLOBALS['mg_test']['page'] = 'order-received';
        $GLOBALS['mg_test']['query'] = array('order-received' => 555);
        $_GET['key'] = 'wc_order_fixture';
    } elseif ($page === 'checkout') {
        $GLOBALS['mg_test']['page'] = 'checkout';
        $GLOBALS['mg_test']['cart'] = array(cart_item());
    } elseif ($page === 'cart') {
        $GLOBALS['mg_test']['page'] = 'cart';
        $GLOBALS['mg_test']['cart'] = array(cart_item());
    }
    $head = capture(function () {
        MG_Purchase_Recovery::output_confirm_helper();
        MG_Google_Ads_Tracking::output_gtag_script();
        MG_Facebook_Pixel::output_pixel_base();
        MG_Consent_Bridge::output_bridge_script();
    });
    $body = capture(function () use ($page, $order) {
        if ($page === 'thankyou') {
            // A WooCommerce csak érvényes kulcsnál hívja a woocommerce_thankyou hookot.
            MG_Google_Ads_Tracking::output_purchase_event($order->get_id());
            MG_Facebook_Pixel::output_purchase_event($order->get_id());
        }
    });
    $footer = capture(function () {
        MG_Google_Ads_Tracking::output_begin_checkout_event();
        MG_Google_Ads_Tracking::output_view_cart_event();
        MG_Facebook_Pixel::output_initiate_checkout_event();
        MG_Purchase_Recovery::output_recovery_script();
    });
    ob_end_clean();
    echo '<!doctype html><html><head><meta charset="utf-8"><title>fixture</title>' . $head . '</head><body><main>Teszt oldal</main>' . $body . $footer . '</body></html>';
    exit;
}

/* ---------------------------------------------------------------------
 * 1. Köszönőoldal: rendelés csak a titkos kulccsal jegyezhető meg
 * ------------------------------------------------------------------ */
$victim = order(101, 'wc_order_secret', 'processing');

reset_request();
$GLOBALS['mg_test']['page'] = 'order-received';
$GLOBALS['mg_test']['query'] = array('order-received' => 101);
MG_Purchase_Recovery::handle_order_received_page();
check(empty($_COOKIE[MG_Purchase_Recovery::COOKIE]), 'An order-received URL without the key must not be remembered');
check(pending() === array('pending' => array(), 'waiting' => false), 'Without the key the endpoint must disclose nothing');

$_GET['key'] = 'wc_order_guess';
MG_Purchase_Recovery::handle_order_received_page();
check(empty($_COOKIE[MG_Purchase_Recovery::COOKIE]), 'A wrong key must not be remembered either');
check(pending()['pending'] === array(), 'A wrong key must disclose nothing');

$_GET['key'] = 'wc_order_secret';
MG_Purchase_Recovery::handle_order_received_page();
check($_COOKIE[MG_Purchase_Recovery::COOKIE] === '101:wc_order_secret', 'The right key remembers the order');
$sent = $GLOBALS['mg_test']['cookies_sent'];
check($sent[MG_Purchase_Recovery::COOKIE]['httponly'] === true, 'The order key cookie stays HttpOnly');
check($sent[MG_Purchase_Recovery::FLAG_COOKIE]['value'] === '1' && $sent[MG_Purchase_Recovery::FLAG_COOKIE]['httponly'] === false, 'The flag is readable by the page script and holds no key');

/* ---------------------------------------------------------------------
 * 2. Pótló végpont: fizetésre váró -> mérhető -> elküldött
 * ------------------------------------------------------------------ */
reset_request();
$order = order(202, 'wc_order_card', 'pending');
MG_Purchase_Recovery::remember_order($order);
$result = pending();
check($result['pending'] === array() && $result['waiting'] === true, 'An unpaid order waits and tells the thank-you page to ask again');
check($_COOKIE[MG_Purchase_Recovery::COOKIE] === '202:wc_order_card', 'A waiting order stays remembered');

$order->status = 'processing';
$result = pending();
check(count($result['pending']) === 1 && $result['waiting'] === false, 'A paid order is delivered');
$entry = $result['pending'][0];
check($entry['google']['event']['transaction_id'] === '202' && $entry['google']['event']['send_to'] === 'AW-123456/AbCdEf', 'Google purchase payload');
check($entry['google']['event']['items'][0]['id'] === 'SKU7_ferfi-polo', 'Google item id matches the feed');
check($entry['meta']['event_id'] === '202' && $entry['meta']['data']['content_ids'] === array('SKU7_ferfi-polo'), 'Meta purchase payload');

$order->meta['_mg_gads_browser_fired'] = time();
$result = pending();
check(count($result['pending']) === 1 && !isset($result['pending'][0]['google']) && isset($result['pending'][0]['meta']), 'A confirmed channel is not sent again');
$order->meta['_mg_fb_browser_fired'] = time();
$result = pending();
check($result === array('pending' => array(), 'waiting' => false), 'Nothing left once both channels fired');
check(empty($_COOKIE[MG_Purchase_Recovery::COOKIE]) && empty($_COOKIE[MG_Purchase_Recovery::FLAG_COOKIE]), 'Both cookies are cleared when nothing is left');

reset_request();
$cancelled = order(303, 'wc_order_gone', 'cancelled');
MG_Purchase_Recovery::remember_order($cancelled);
$result = pending();
check($result === array('pending' => array(), 'waiting' => false) && empty($_COOKIE[MG_Purchase_Recovery::COOKIE]), 'A cancelled order is dropped instead of polled');

reset_request();
$transfer = order(304, 'wc_order_bank', 'on-hold');
$transfer->gateway = 'bacs';
MG_Purchase_Recovery::remember_order($transfer);
$result = pending();
check($result === array('pending' => array(), 'waiting' => false), 'A bank transfer is not polled: nobody confirms it within minutes');
check($_COOKIE[MG_Purchase_Recovery::COOKIE] === '304:wc_order_bank', 'It stays remembered for the customer\'s next visit');
$transfer->status = 'processing';
check(count(pending()['pending']) === 1, 'Once the shop marks it paid, the next visit delivers it');

reset_request();
$_COOKIE[MG_Purchase_Recovery::FLAG_COOKIE] = '1';
pending();
check($GLOBALS['mg_test']['cookies_sent'][MG_Purchase_Recovery::FLAG_COOKIE]['value'] === '', 'An orphaned flag is removed');

reset_request();
$_COOKIE[MG_Purchase_Recovery::COOKIE] = '202:wc_order_card';
MG_Purchase_Recovery::sync_flag_cookie();
check(($_COOKIE[MG_Purchase_Recovery::FLAG_COOKIE] ?? '') === '1', 'Browsers holding an older order cookie get the flag');

/* ---------------------------------------------------------------------
 * 3. Meta: nincs második Purchase a 48 órás deduplikációs ablakon túl
 * ------------------------------------------------------------------ */
$meta_order = order(404, 'wc_order_meta', 'processing');
check(MG_Facebook_Pixel::browser_purchase_allowed($meta_order), 'A fresh order may send the browser Purchase');
$meta_order->meta[MG_Facebook_Pixel_Reliability::META_STATUS] = 'processed';
$meta_order->meta[MG_Facebook_Pixel_Reliability::META_LAST_UPDATE] = time() - HOUR_IN_SECONDS;
check(MG_Facebook_Pixel::browser_purchase_allowed($meta_order), 'Inside the dedup window the browser event still goes out');
$meta_order->meta[MG_Facebook_Pixel_Reliability::META_LAST_UPDATE] = time() - 41 * HOUR_IN_SECONDS;
check(!MG_Facebook_Pixel::browser_purchase_allowed($meta_order), 'After the dedup window a CAPI-delivered order is not sent again');
check(capture(function () { MG_Facebook_Pixel::output_purchase_event(404); }) === '', 'No Purchase tag on a thank-you page reopened days later');

$meta_order->meta = array('_mg_fb_browser_fired' => time());
check(!MG_Facebook_Pixel::browser_purchase_allowed($meta_order), 'A confirmed browser Purchase is never repeated');
check(capture(function () { MG_Facebook_Pixel::output_purchase_event(404); }) === '', 'No Purchase tag after a confirmed one');

$meta_order->meta = array();
$tag = capture(function () { MG_Facebook_Pixel::output_purchase_event(404); });
check(strpos($tag, "fbq('track', 'Purchase'") !== false && strpos($tag, '"meta:404"') !== false, 'First view renders the Purchase and marks it for the recovery script');
$tag = capture(function () { MG_Google_Ads_Tracking::output_purchase_event(404); });
check(strpos($tag, "gtag('event', 'purchase'") !== false && strpos($tag, '"google:404"') !== false, 'Google purchase tag marks itself too');

/* ---------------------------------------------------------------------
 * 4. Kosár- és pénztáresemények blokkos oldalon is
 * ------------------------------------------------------------------ */
reset_request();
$GLOBALS['mg_test']['cart'] = array(cart_item());

$GLOBALS['mg_test']['page'] = 'checkout';
$google = capture(function () { MG_Google_Ads_Tracking::output_begin_checkout_event(); });
$meta = capture(function () { MG_Facebook_Pixel::output_initiate_checkout_event(); });
check(strpos($google, "'begin_checkout'") !== false && strpos($google, 'SKU7_ferfi-polo') !== false, 'begin_checkout on the checkout page without the classic form hook');
check(strpos($meta, "'InitiateCheckout'") !== false && strpos($meta, 'SKU7_ferfi-polo') !== false, 'InitiateCheckout on the checkout page without the classic form hook');
check(capture(function () { MG_Google_Ads_Tracking::output_view_cart_event(); }) === '', 'No view_cart on the checkout page');

foreach (array('order-received', 'order-pay', 'product', 'cart') as $other) {
    $GLOBALS['mg_test']['page'] = $other;
    check(capture(function () { MG_Google_Ads_Tracking::output_begin_checkout_event(); }) === '', "No begin_checkout on {$other}");
    check(capture(function () { MG_Facebook_Pixel::output_initiate_checkout_event(); }) === '', "No InitiateCheckout on {$other}");
}

$GLOBALS['mg_test']['page'] = 'cart';
check(strpos(capture(function () { MG_Google_Ads_Tracking::output_view_cart_event(); }), "'view_cart'") !== false, 'view_cart on the cart page');
$GLOBALS['mg_test']['page'] = 'checkout';
$GLOBALS['mg_test']['cart'] = array();
check(capture(function () { MG_Facebook_Pixel::output_initiate_checkout_event(); }) === '', 'An empty cart sends nothing');

/* ---------------------------------------------------------------------
 * 5. Google egyezési adatok normalizálása
 * ------------------------------------------------------------------ */
check(MG_Google_Ads_Reliability::normalize_email(' Teszt.Vevo@Gmail.com ') === 'tesztvevo@gmail.com', 'Gmail dots are removed before hashing');
check(MG_Google_Ads_Reliability::normalize_email('a.b.c@googlemail.com') === 'abc@googlemail.com', 'googlemail.com follows the Gmail rule');
check(MG_Google_Ads_Reliability::normalize_email('Kis.Pista@Freemail.hu') === 'kis.pista@freemail.hu', 'Other domains keep their dots');
check(MG_Google_Ads_Reliability::normalize_phone('06 30 123 4567', 'HU') === '+36301234567', '06 prefix');
check(MG_Google_Ads_Reliability::normalize_phone('0036 30 123 4567', 'HU') === '+36301234567', '00 international prefix');
check(MG_Google_Ads_Reliability::normalize_phone('+36 (30) 123-4567', 'HU') === '+36301234567', 'Already international');
check(MG_Google_Ads_Reliability::normalize_phone('30 123 4567', 'HU') === '+36301234567', 'National number without prefix');
check(MG_Google_Ads_Reliability::normalize_phone('123', 'HU') === '', 'Too short numbers are dropped');
check(MG_Google_Ads_Reliability::normalize_phone('06 30 123 4567', '') === '+36301234567', 'A missing country means the home format');
check(MG_Google_Ads_Reliability::normalize_phone('+43 664 1234567', 'AT') === '+436641234567', 'International foreign number');
check(MG_Google_Ads_Reliability::normalize_phone('+43 664 1234567', 'HU') === '+436641234567', 'A + prefix wins over the billing country');
check(MG_Google_Ads_Reliability::normalize_phone('0664 1234567', 'AT') === '', 'A foreign national number is dropped, not guessed');

$intl = order(505, 'wc_order_intl', 'processing');
$intl->phone = '0036 30 123 4567';
$payload = MG_Google_Ads_Tracking::build_purchase_payload($intl);
check($payload['user_data']['phone_number'] === '+36301234567', 'Browser Enhanced Conversions use the same phone format as the server upload');

ob_end_clean();
echo "Measurement: order key check, flag cookie, pending/waiting states, Meta dedup guard, block cart/checkout events and Google normalisation passed.\n";
