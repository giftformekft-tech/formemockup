<?php
// php tests/virtual-variant-content-test.php
define('ABSPATH', __DIR__);
$checks = 0;
$shortcodes = array();
$catalog = array();
$settings = array('size_charts' => array(), 'size_chart_models' => array());
foreach (array('shirt', 'hoodie', 'mug') as $slug) {
    $catalog[$slug] = array('label' => $slug, 'description' => str_repeat($slug, 1000), 'colors' => array(), 'sizes' => array());
    $settings['size_charts'][$slug] = '[' . $slug . '-chart]';
    $settings['size_chart_models'][$slug] = '[' . $slug . '-models]';
}
class WC_Product {
    public $status = 'publish';
    public $type = 'simple';
    public function get_id() { return 42; }
    public function get_sku() { return 'SKU42'; }
    public function get_name() { return 'Test product'; }
    public function get_status() { return $this->status; }
    public function is_type($type) { return $this->type === $type; }
}
class MG_Product_Creator {}
class MG_Variant_Display_Manager {
    public static function get_catalog_index() { return $GLOBALS['catalog']; }
    public static function sanitize_settings_block($raw, $catalog) { return $raw; }
}
class JsonResponse extends Exception {
    public $data;
    public $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
$test_product = new WC_Product();
$protected = false;
function __($text) { return $text; }
function absint($value) { return abs((int) $value); }
function sanitize_title($text) { return strtolower(trim($text)); }
function sanitize_key($text) { return strtolower(trim($text)); }
function wp_unslash($text) { return $text; }
function wp_normalize_path($text) { return str_replace('\\', '/', $text); }
function sanitize_text_field($text) { return $text; }
function sanitize_hex_color($text) { return $text; }
function get_query_var($name) { return ''; }
function get_option($name, $default) { return $GLOBALS['settings']; }
function wp_parse_args($values, $defaults) { return array_merge($defaults, $values); }
function get_post_meta($id, $key, $single) { return ''; }
function wp_upload_dir() { return array('baseurl' => 'https://example.test/uploads', 'basedir' => '/uploads'); }
function trailingslashit($text) { return rtrim($text, '/') . '/'; }
function admin_url($path) { return 'https://example.test/' . $path; }
function wp_create_nonce($action) { return $action; }
function apply_filters($name, $value, ...$args) {
    if ($name === 'mg_virtual_variant_type_mockups_enabled') return false;
    if ($name === 'mg_variant_display_type_description') return '<p>' . $value . '</p>';
    return $value;
}
function wc_get_product($id) { return $id === 42 ? $GLOBALS['test_product'] : false; }
function post_password_required($id) { return $GLOBALS['protected']; }
function get_post($id) { return (object) array('ID' => $id); }
function do_shortcode($html) {
    $GLOBALS['shortcodes'][] = $html;
    check($GLOBALS['product']->get_id() === 42 && $GLOBALS['post']->ID === 42, 'shortcode product context');
    return '<div>' . $html . '</div>';
}
function check_ajax_referer($action, $key) {
    if (($_POST[$key] ?? '') !== $action) throw new JsonResponse(null, 403);
}
function wp_send_json_error($data, $status = 400) { throw new JsonResponse($data, $status); }
function wp_send_json_success($data) { throw new JsonResponse($data, 200); }
function check($condition, $message) {
    $GLOBALS['checks']++;
    if (!$condition) throw new Exception($message);
}
require dirname(__DIR__) . '/includes/class-virtual-variant-manager.php';

$GLOBALS['post'] = (object) array('ID' => 42);
$GLOBALS['product'] = $test_product;
$full = MG_Virtual_Variant_Manager::get_frontend_config($test_product);
check(count($shortcodes) === 6, 'charts rendered for every selectable type');
check($full['types']['shirt']['has_size_chart'], 'chart availability retained');
check($full['types']['shirt']['size_chart'] === '<div>[shirt-chart]</div>', 'chart HTML available to current and older scripts');
check($full['types']['shirt']['size_chart_models'] === '<div>[shirt-models]</div>', 'models HTML available without AJAX');
check($full['types']['hoodie']['description'] === '<p>' . $catalog['hoodie']['description'] . '</p>', 'feed/schema description preserved');

$_GET['mg_type'] = 'hoodie';
$browser = MG_Virtual_Variant_Manager::prepare_browser_config($full);
foreach (array('shirt', 'hoodie', 'mug') as $slug) {
    check($browser['types'][$slug]['size_chart'] === '<div>[' . $slug . '-chart]</div>', 'browser retains chart for ' . $slug);
    check($browser['types'][$slug]['size_chart_models'] === '<div>[' . $slug . '-models]</div>', 'browser retains models for ' . $slug);
}
check(isset($browser['types']['hoodie']['description']), 'requested type description included');
check(!isset($browser['types']['shirt']['description']), 'other description deferred');
check($browser['types']['shirt']['has_description'], 'description availability retained');
check(isset($full['types']['shirt']['description']), 'browser trimming must not mutate server config');
check(!isset($browser['visuals']['defaults']), 'disabled canvas pattern omitted');
$_GET['mg_type'] = 'invalid';
$fallback = MG_Virtual_Variant_Manager::prepare_browser_config($full);
check(isset($fallback['types']['shirt']['description']), 'invalid URL falls back to default');

function request_content($section, $extra = array()) {
    $_POST = array_merge(array('nonce' => MG_Virtual_Variant_Manager::CONTENT_NONCE_ACTION, 'product_id' => 42, 'product_type' => 'shirt', 'section' => $section), $extra);
    try { MG_Virtual_Variant_Manager::ajax_content(); } catch (JsonResponse $response) { return $response; }
    throw new Exception('Endpoint did not return JSON');
}
$GLOBALS['post'] = (object) array('ID' => 99);
$GLOBALS['product'] = 'previous product';
$shortcodes = array();
$response = request_content('size_chart');
check($response->status === 200 && $response->data['html'] === '<div>[shirt-chart]</div>', 'requested chart rendered');
check($shortcodes === array('[shirt-chart]'), 'only requested section rendered');
check($GLOBALS['post']->ID === 99 && $GLOBALS['product'] === 'previous product', 'globals restored');
$response = request_content('size_chart_models', array('product_type' => 'hoodie'));
check($response->data['html'] === '<div>[hoodie-models]</div>', 'models rendered independently');
$before = count($shortcodes);
$response = request_content('description');
check($response->data['html'] === $full['types']['shirt']['description'], 'lazy and server descriptions match');
check(count($shortcodes) === $before, 'description does not render charts');
check(request_content('size_chart', array('nonce' => 'bad'))->status === 403, 'invalid nonce rejected');
check(request_content('size_chart', array('product_id' => 999))->status === 404, 'unknown product rejected');
check(request_content('size_chart', array('product_type' => 'missing'))->status === 404, 'unknown type rejected');
check(request_content('private_meta')->status === 400, 'section allowlist enforced');
$test_product->status = 'draft';
check(request_content('size_chart')->status === 404, 'draft product rejected');
$test_product->status = 'publish';
$test_product->type = 'variable';
check(request_content('size_chart')->status === 404, 'unsupported product rejected');
$test_product->type = 'simple';
$protected = true;
check(request_content('size_chart')->status === 404, 'password protected product rejected');
$protected = false;
$settings['size_charts']['mug'] = '';
check(request_content('size_chart', array('product_type' => 'mug'))->data['html'] === '', 'empty content handled');
check(count($shortcodes) === $before, 'invalid and empty requests execute no shortcodes');
echo "Virtual variant content: $checks assertions passed\n";
