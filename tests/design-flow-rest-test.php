<?php
/**
 * php tests/design-flow-rest-test.php
 * The Design Flow Editor routes with WordPress and queue doubles: a request becomes the same
 * MG_Bulk_Queue job as the bulk page's, repeats make no second job, a new product stays a draft
 * unless publishing was asked for, and an unclear custom field match stays a plain product.
 */
define('ABSPATH', __DIR__);
if (!function_exists('mb_strtolower')) {
    echo "This test requires the mbstring extension, like the bulk page's name matching.
";
    exit(0);
}
define('DAY_IN_SECONDS', 86400);

$options = $transients = $enqueued = $updated_posts = $routes = $actions = array();
$can_edit = true;
$upload_result = array('file' => '/uploads/Horgasz.png');

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = array()) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
}

class WP_REST_Request {
    private $params;
    private $files;
    public function __construct(array $params = array(), array $files = array()) {
        $this->params = $params;
        $this->files = $files;
    }
    public function get_param($key) { return $this->params[$key] ?? null; }
    public function get_file_params() { return $this->files; }
}

function add_action($hook, $callback, $priority = 10, $args = 1) { global $actions; $actions[$hook] = $callback; }
function register_rest_route($namespace, $route, $args) { global $routes; $routes[$namespace . $route] = $args; }
function current_user_can($capability) { global $can_edit; return $can_edit && $capability === 'edit_products'; }
function rest_ensure_response($value) { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_kses_post($s) { return $s; }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { global $transients; $transients[$key] = $value; return true; }
function delete_transient($key) { global $transients; unset($transients[$key]); return true; }
function wp_update_post($values) { global $updated_posts; $updated_posts[] = $values; return $values['ID']; }
function wp_handle_upload($file, $overrides) { global $upload_result; return $upload_result; }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat'; }
function get_terms($args) {
    if ($args['parent'] === 0) {
        return array((object) array('term_id' => 10, 'name' => 'Hobbi'), (object) array('term_id' => 20, 'name' => 'Család'));
    }
    return $args['parent'] === 10 ? array((object) array('term_id' => 11, 'name' => 'Horgász')) : array();
}
function mg_bulk_resolve_defaults($selected, $type, $color, $size) {
    return array('type' => $type ?: $selected[0]['key'], 'color' => $color, 'size' => $size);
}
function mg_bulk_normalize_product_name($value) { return mb_strtolower(trim((string) $value), 'UTF-8'); }

class MG_Bulk_Queue {
    public static function enqueue(array $payload, $dispatch = true) {
        global $enqueued;
        $enqueued[] = array('payload' => $payload, 'dispatch' => $dispatch);
        return 'mgjob_' . count($enqueued);
    }
    public static function get_status(array $ids) { return array('jobs' => array_fill_keys($ids, array('status' => 'pending'))); }
}

class MG_Personalization_Import {
    public static function resolve($data) {
        return (is_array($data) && ($data['kinds'] ?? null) === array('month'))
            ? array('state' => 'matched', 'preset_id' => 'honap')
            : array('state' => 'unclear', 'preset_id' => '');
    }
}

class MG_Custom_Fields_Manager {
    public static function get_presets() { return array('honap' => array('name' => 'Hónap'), 'hibas' => 'nem tömb'); }
}

class FakeDb {
    public $posts = 'wp_posts';
    public $titles = array();
    public function prepare($sql, $values) { return $values; }
    public function get_results($values) {
        return array_map(function ($title) { return (object) array('post_title' => $title); }, array_values(array_intersect($this->titles, $values)));
    }
}
$wpdb = new FakeDb();

require __DIR__ . '/../includes/class-design-flow-rest.php';

$failures = 0;
function check($label, $condition) {
    global $failures;
    if (!$condition) {
        $failures++;
        echo "FAILED: {$label}\n";
    }
}

function file_param() {
    return array('design_file' => array('tmp_name' => '/tmp/x', 'name' => 'Horgasz.png'));
}

function design_request(array $params = array(), $files = null) {
    return new WP_REST_Request(array_merge(array(
        'request_id' => 'abc123', 'product_name' => 'Horgász', 'product_keys' => array('polo'),
        'main_label' => 'hobbi', 'sub_label' => ' HORGÁSZ ', 'tags' => 'horgász, , vicces, horgász',
        'sample_seo' => 'Leírás', 'primary_type' => 'polo', 'primary_color' => 'fekete', 'primary_size' => 'M',
    ), $params), $files ?? file_param());
}

$options['mg_products'] = array(
    array('key' => 'polo', 'label' => '<b>Póló</b>', 'colors' => array(array('slug' => 'fekete'), 'feher'), 'sizes' => array('M', 'L')),
    array('key' => 'bogre', 'label' => 'Bögre'),
    'nem tömb',
);

// Routes and rights
MG_Design_Flow_Rest::init();
check('hooks', isset($actions['rest_api_init'], $actions['mg_bulk_queue_product_created']));
MG_Design_Flow_Rest::register_routes();
check('four routes', count($routes) === 4 && isset($routes['design-flow/v1/designs']));
foreach ($routes as $route) {
    check('every route checks rights', $route['permission_callback'] === array('MG_Design_Flow_Rest', 'can_edit_products'));
}
check('edit_products allowed', MG_Design_Flow_Rest::can_edit_products());
$can_edit = false;
check('others refused', !MG_Design_Flow_Rest::can_edit_products());
$can_edit = true;

// Config
$config = MG_Design_Flow_Rest::config();
check('config lists product types', array_column($config['products'], 'key') === array('polo', 'bogre'));
check('config strips labels', $config['products'][0]['label'] === 'Póló');
check('config reads both color forms', $config['products'][0]['colors'] === array('fekete', 'feher'));
check('config categories', $config['categories'][0]['subs'][0]['id'] === 11);
check('config presets', $config['presets'] === array(array('id' => 'honap', 'name' => 'Hónap')));

// Refusals queue nothing
check('no type', MG_Design_Flow_Rest::enqueue(design_request(array('product_keys' => array()))) instanceof WP_Error);
check('unknown type', MG_Design_Flow_Rest::enqueue(design_request(array('product_keys' => array('nincs')))) instanceof WP_Error);
check('no file', MG_Design_Flow_Rest::enqueue(design_request(array(), array())) instanceof WP_Error);
$upload_result = array('error' => 'Túl nagy');
$refused = MG_Design_Flow_Rest::enqueue(design_request());
check('upload error is reported', $refused instanceof WP_Error && strpos($refused->message, 'Túl nagy') !== false);
$upload_result = array('file' => '/uploads/Horgasz.png');
check('nothing queued by refusals', empty($enqueued) && empty($transients));

// A normal upload
$answer = MG_Design_Flow_Rest::enqueue(design_request());
check('job id', $answer === array('job_id' => 'mgjob_1'));
$payload = $enqueued[0]['payload'];
check('design file', $payload['design_path'] === '/uploads/Horgasz.png');
check('name', $payload['parent_name'] === 'Horgász');
check('categories by name', $payload['categories'] === array('main' => 10, 'subs' => array(11)));
check('tags cleaned', $payload['tags'] === array('horgász', 'vicces'));
check('defaults', $payload['defaults'] === array('type' => 'polo', 'color' => 'fekete', 'size' => 'M'));
check('plain product', $payload['custom_product'] === 0 && $payload['personalization_selection']['mode'] === 'manual');
check('selection has the shape the queue validates', $payload['personalization_selection']['custom'] === false && $payload['personalization_selection']['preset_id'] === '');
check('trigger', $payload['trigger'] === 'design_flow');
check('same payload keys as the bulk page', array_keys($payload) === array(
    'design_path', 'product_keys', 'parent_id', 'parent_name', 'batch_id', 'categories', 'defaults', 'tags',
    'custom_product', 'preset_id', 'personalization_selection', 'sample_seo', 'trigger',
));

// The same request again makes no second job
$again = MG_Design_Flow_Rest::enqueue(design_request());
check('repeat answers with the first job', $again === array('job_id' => 'mgjob_1', 'already_queued' => true));
check('repeat queues nothing', count($enqueued) === 1);

// Draft unless publishing was asked for
MG_Design_Flow_Rest::apply_requested_status(55, array('parent_name' => 'Horgász'));
check('new product stays a draft', $updated_posts === array(array('ID' => 55, 'post_status' => 'draft')));
MG_Design_Flow_Rest::apply_requested_status(56, array('parent_name' => 'Horgász'));
check('the draft flag is used once', count($updated_posts) === 1);
MG_Design_Flow_Rest::enqueue(design_request(array('request_id' => 'd2', 'product_name' => 'Kettő')));
MG_Design_Flow_Rest::enqueue(design_request(array('request_id' => 'd3', 'product_name' => 'Kettő', 'publish_status' => 'publish')));
MG_Design_Flow_Rest::apply_requested_status(57, array('parent_name' => 'Kettő'));
check('publishing clears an earlier draft request of the same name', count($updated_posts) === 1);
MG_Design_Flow_Rest::apply_requested_status(58, array('parent_name' => 'Bulk oldalról'));
check('products from the bulk page are not touched', count($updated_posts) === 1);

// Custom fields
MG_Design_Flow_Rest::enqueue(design_request(array('request_id' => 'p1', 'personalization' => json_encode(array('kinds' => array('month'))))));
$matched = end($enqueued)['payload'];
check('a clear match sets the preset', $matched['custom_product'] === 1 && $matched['preset_id'] === 'honap' && $matched['personalization_selection']['mode'] === 'auto');
MG_Design_Flow_Rest::enqueue(design_request(array('request_id' => 'p2', 'personalization' => json_encode(array('kinds' => array('name'))))));
check('an unclear match stays plain', end($enqueued)['payload']['custom_product'] === 0);
MG_Design_Flow_Rest::enqueue(design_request(array('request_id' => 'p3', 'personalization' => str_repeat('x', 20001))));
check('an oversized value stays plain', end($enqueued)['payload']['custom_product'] === 0);

// Missing name falls back to the file name, categories may stay empty
MG_Design_Flow_Rest::enqueue(design_request(array('request_id' => 'n1', 'product_name' => '', 'main_label' => '', 'sub_label' => '')));
$unnamed = end($enqueued)['payload'];
check('file name is the fallback', $unnamed['parent_name'] === 'Horgasz');
check('no categories', $unnamed['categories'] === array('main' => 0, 'subs' => array()));

// Existing names and status
$wpdb->titles = array('Horgász');
$existing = MG_Design_Flow_Rest::existing(new WP_REST_Request(array('names' => array('Horgász', 'Új', '  '))));
check('existing names', $existing === array('names' => array('Horgász')));
check('no names', MG_Design_Flow_Rest::existing(new WP_REST_Request(array('names' => array()))) === array('names' => array()));
$status = MG_Design_Flow_Rest::status(new WP_REST_Request(array('job_ids' => array('mgjob_1'))));
check('status', $status['jobs']['mgjob_1']['status'] === 'pending');

if ($failures) {
    echo "{$failures} failed.\n";
    exit(1);
}
echo "All Design Flow route checks passed.\n";
