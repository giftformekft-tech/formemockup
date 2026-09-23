<?php
/**
 * php -d extension=zip tests/ai-print-export-test.php
 * Real PNG bytes and ZIP I/O; WordPress, scheduler, HTTP and Imagick are doubles.
 * No paid API calls. This does not validate model output or real Imagick rendering.
 */
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
$options = $transients = $actions = $http_calls = $posts = $orders = array();
$current_user = 7;
$http_mode = 'ok';
$worker_stages = $worker_logs = array();
$test_dir = sys_get_temp_dir() . '/mg-ai-test-' . bin2hex(random_bytes(6));
mkdir($test_dir);
function __($s) { return $s; }
function absint($value) { return abs((int) $value); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_title($s) { return $s; }
function sanitize_file_name($s) { return str_replace(array('/', '\\'), '_', $s); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function wp_unslash($s) { return stripslashes($s); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_html($s) { return esc_attr($s); }
function esc_textarea($s) { return esc_attr($s); }
function esc_url($s) { return esc_attr($s); }
function esc_js($s) { return addslashes($s); }
function esc_attr__($s) { return esc_attr($s); }
function esc_html__($s) { return esc_attr($s); }
function checked($a, $b, $echo = true) { return $a == $b ? ' checked="checked"' : ''; }
function selected($a, $b, $echo = true) { return $a == $b ? ' selected="selected"' : ''; }
function wp_nonce_field($action, $field) {}
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function apply_filters($hook, $value, ...$args) { return $value; }
function wc_get_product($id) { return new class { public function get_parent_id() { return 0; } }; }
function wp_json_encode($s, $flags = 0) { return json_encode($s, $flags); }
function wp_normalize_path($s) { return str_replace('\\', '/', $s); }
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function get_temp_dir() { return $GLOBALS['test_dir']; }
function wp_upload_dir() { return array('basedir' => $GLOBALS['test_dir']); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($key, $GLOBALS['options'])) { return false; }
    return update_option($key, $value);
}
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function set_transient($key, $value, $ttl) {
    $GLOBALS['transients'][$key] = $value;
    if (($value['status'] ?? '') === 'running' && isset($value['stage'])) $GLOBALS['worker_stages'][$key][] = $value['stage'];
}
function wc_get_logger() { return new class { public function error($message, $context) { $GLOBALS['worker_logs'][] = array($message, $context); } }; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function current_time($format) { return '2026-09-08 12:00:00'; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function user_can($id, $cap) { return $id === 7; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_schedule_single_event($time, $hook, $args) { return true; }
function as_schedule_single_action($time, $hook, $args, $group, $unique) {
    $GLOBALS['action_delays'][] = $time - time();
    return as_enqueue_async_action($hook, $args, $group, $unique);
}
function as_enqueue_async_action($hook, $args, $group, $unique) {
    $GLOBALS['actions'][] = array($hook, $args);
    return count($GLOBALS['actions']);
}
function get_post_meta($id, $key, $single) { return $GLOBALS['posts'][$id][$key] ?? ''; }
function get_the_title($id) { return 'Minta ' . $id; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function is_wp_error($r) { return $r instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
class WP_Error { public function __construct(private $message = '') {} public function get_error_message() { return $this->message; } }
function png_chunk($type, $data) { return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data)); }
function make_png($w, $h, $red, $alpha = true) {
    $pixel = chr($red) . "\x00\x00" . ($alpha ? "\x00" : '');
    $rows = str_repeat("\x00" . str_repeat($pixel, $w), $h);
    return "\x89PNG\r\n\x1a\n" . png_chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $alpha ? 6 : 2, 0, 0, 0)) . png_chunk('IDAT', gzcompress($rows)) . png_chunk('IEND', '');
}
function wp_remote_post($url, $request) {
    $GLOBALS['http_calls'][] = array($url, $request);
    if (!empty($GLOBALS['http_hook'])) { $hook = $GLOBALS['http_hook']; unset($GLOBALS['http_hook']); $hook(); }
    $mode = $GLOBALS['http_mode'];
    if ($mode === 'network') { return new WP_Error(); }
    if ($mode === 'timeout28') { return new WP_Error('cURL error 28: secret provider URL and credentials'); }
    if ($mode === '429') { return array('code' => 429, 'body' => 'secret provider message'); }
    if ($mode === '401') { return array('code' => 401, 'body' => 'secret provider message'); }
    preg_match('/name="size"\r\n\r\n(\d+)x(\d+)/', $request['body'], $size);
    $png = make_png((int) $size[1], (int) $size[2], count($GLOBALS['http_calls']), $mode !== 'opaque');
    if ($mode === 'bad') { $png = 'not a PNG'; }
    if ($mode === 'size') { $png = make_png(16, 16, 42); }
    return array('code' => 200, 'body' => json_encode(array('data' => array(array('b64_json' => base64_encode($png))))));
}
class Imagick {
    const CHANNEL_ALPHA = 8;
    const FILTER_LANCZOS = 22;
    public static $resize_calls = 0;
    public static $resize_failure = false;
    private $bytes = '';
    public function __construct($path = null) { if ($path) { $this->bytes = file_get_contents($path); } }
    public function getImageAlphaChannel() { return ord($this->bytes[25]) === 6; }
    // Deliberately omit the removed getImageChannelExtrema method.
    public function getImageChannelRange($channel) { return array('minima' => 0.0, 'maxima' => 65535.0); }
    public static function getQuantumRange() { return array('quantumRangeLong' => 65535); }
    public function readImageBlob($bytes) { $this->bytes = $bytes; }
    public function resizeImage($width, $height, $filter, $blur) {
        self::$resize_calls++;
        if (self::$resize_failure) { return false; }
        $this->bytes = make_png($width, $height, self::$resize_calls, $this->getImageAlphaChannel());
        return true;
    }
    public function setImagePage($width, $height, $x, $y) {}
    public function getImageBlob() { return $this->bytes; }
    public function setImageFormat($format) {}
    public function setImageDepth($depth) {}
    public function setOption($key, $value) {}
    public function writeImage($path) { file_put_contents($path, $this->bytes); }
    public function clear() {}
    public function destroy() {}
}
class MG_Image_Utils {
    public static $stripped = 0;
    public static $defringed = 0;
    public static $defringe_resize_positions = array();
    public static $defringe_failure = false;
    public static function clean_transparent_edges($image) {
        if (self::$defringe_failure) throw new RuntimeException('Fehér perem korrekciós hiba');
        self::$defringed++;
        self::$defringe_resize_positions[] = Imagick::$resize_calls;
    }
    public static function strip_color_to_transparent($image, $color) { self::$stripped++; }
    public static function trim_transparent_bounds($image) {}
    public static function rotate_portrait_to_landscape($image) {}
    public static function rotate_landscape_to_portrait($image) {}
    public static function threshold_alpha_binary($image, $value) {}
    public static function force_png_dpi($path, $dpi) {}
}
class WC_Order_Item_Product {
    public $meta;
    public function __construct(public $id, public $product_id, public $quantity, $fields) {
        $this->meta = array('_mg_custom_fields' => $fields, 'mg_product_type' => 'polo', 'mg_size' => 'M', 'mg_color' => 'fekete');
    }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_id() { return $this->id; }
    public function get_product_id() { return $this->product_id; }
    public function get_quantity() { return $this->quantity; }
    public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
}
class Test_Order {
    public function __construct(public $items) {}
    public function get_id() { return 90; }
    public function get_items() { return $this->items; }
    public function get_date_created() { return new DateTime('2026-09-08'); }
}
require_once dirname(__DIR__) . '/includes/class-custom-fields-manager.php';
require_once dirname(__DIR__) . '/includes/class-custom-fields-frontend.php';
require_once dirname(__DIR__) . '/includes/class-ai-seo-generator.php';
require_once dirname(__DIR__) . '/includes/class-ai-print-generator.php';
require_once dirname(__DIR__) . '/admin/class-order-design-download.php';
require_once dirname(__DIR__) . '/includes/class-outlet.php';
require_once dirname(__DIR__) . '/admin/class-custom-fields-page.php';
require_once dirname(__DIR__) . '/admin/class-ai-seo-page.php';
function call_hidden($class, $method, ...$args) { return (new ReflectionMethod($class, $method))->invoke(null, ...$args); }
$assertions = 0;
function check($condition, $message) {
    $GLOBALS['assertions']++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'ok - ' . $message . "\n";
}
function expect_error($callback, $text) {
    try { $callback(); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), $text), $text); return; }
    throw new RuntimeException('Expected error: ' . $text);
}
function set_fields($fields) {
    update_option('mg_custom_fields', array(42 => array('fields' => $fields)));
    update_option('mg_custom_field_presets', array());
    (new ReflectionProperty('MG_Custom_Fields_Manager', 'cached_presets'))->setValue(null, null);
}
function make_job($id, $tasks) {
    $job = array('tasks' => $tasks, 'next_index' => 0, 'total' => count($tasks), 'completed' => 0, 'zip_path' => $GLOBALS['test_dir'] . '/' . $id . '.zip', 'strip_black' => true, 'cache' => array(), 'temp_files' => array(), 'status' => 'processing', 'user_id' => 7);
    set_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . $id, $job, 3600);
    return $job;
}
try {
    check(class_exists('ZipArchive'), 'real ZIP extension available');
    check(!method_exists('Imagick', 'getImageChannelExtrema'), 'test runtime reproduces missing deprecated Imagick method');
    foreach (array(
        array(false, 65535.0, false, 'RGB without alpha'),
        array(true, 65535.0, false, 'fully opaque RGBA'),
        array(true, 32767.5, true, 'partially transparent RGBA'),
        array(true, 0.0, true, 'fully transparent pixels'),
    ) as [$alpha, $minimum, $expected, $case]) {
        $probe = new class($alpha, $minimum) extends Imagick {
            public function __construct(private $alpha, private $minimum) {}
            public function getImageAlphaChannel() { return $this->alpha; }
            public function getImageChannelRange($channel) {
                if (!$this->alpha || $channel !== Imagick::CHANNEL_ALPHA) {
                    throw new RuntimeException('Unexpected alpha channel inspection');
                }
                return array('minima' => $this->minimum, 'maxima' => 65535.0);
            }
        };
        check(call_hidden('MG_AI_Print_Generator', 'has_transparency', $probe) === $expected, 'channel range transparency detection: ' . $case);
    }
    $fields = array(
        array('id' => 'month', 'label' => 'Hónap', 'type' => 'select', 'required' => true, 'ai_print_enabled' => true, 'ai_print_prompt' => 'A képen látható hónapot cseréld erre: {{ertek}}. Kövesd a ragozást.'),
        array('id' => 'year', 'label' => 'Év', 'type' => 'number', 'required' => true, 'ai_print_enabled' => true, 'ai_print_prompt' => 'A születési évet cseréld erre: {{ertek}}.'),
    );
    set_fields($fields);
    update_option('mg_ai_seo_settings', array('api_key' => 'test-key', 'enabled' => false));
    $seo_settings_before = get_option('mg_ai_seo_settings');
    check(MG_AI_Print_Generator::get_model() === 'gpt-image-2', 'existing installs keep Image 2 by default');
    check(!MG_AI_Print_Generator::get_defringe_enabled(), 'Design Flow correction is disabled by default');
    $options[MG_AI_Print_Generator::OPTION_KEY] = array('model' => 'gpt-image-2', 'defringe_enabled' => true);
    check(!MG_AI_Print_Generator::get_defringe_enabled(), 'old enabled settings cannot reactivate correction');
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2', 'defringe_enabled' => '0'));
    check(!MG_AI_Print_Generator::get_defringe_enabled(), 'fringe correction can be disabled');
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2'));
    check(!MG_AI_Print_Generator::get_defringe_enabled(), 'model-only updates preserve the fringe setting');
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2', 'defringe_enabled' => '1'));
    check(array_keys(MG_AI_Print_Generator::get_models()) === array('gpt-image-2', 'gpt-image-2.5-sunburst', 'gpt-image-2.5-flare'), 'picker contains exact documented image model IDs');
    foreach (MG_AI_Print_Generator::get_models() as $model => $label) {
        MG_AI_Print_Generator::save_settings(array('model' => $model));
        check(MG_AI_Print_Generator::get_model() === $model, 'model setting round trip: ' . $model);
        ob_start();
        MG_AI_SEO_Page::render_print_settings();
        $model_html = ob_get_clean();
        check(str_contains($model_html, 'value="' . $model . '" selected="selected"'), 'admin picker renders selected model: ' . $model);
        check(str_contains($model_html, 'name="mg_ai_print_settings[model]"') && str_contains($model_html, 'value="mg_ai_print_save"'), 'model picker saves through its own form');
    }
    $before_invalid = get_option(MG_AI_Print_Generator::OPTION_KEY);
    foreach (array('gpt-image-2.5', 'gpt-5-mini', '', array('gpt-image-2')) as $invalid) {
        expect_error(fn() => MG_AI_Print_Generator::save_settings(array('model' => $invalid)), 'támogatott AI nyomat');
        check(get_option(MG_AI_Print_Generator::OPTION_KEY) === $before_invalid, 'invalid model does not overwrite saved settings');
    }
    check(get_option('mg_ai_seo_settings') === $seo_settings_before, 'image model settings do not change SEO model, key or enabled flag');
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2'));
    $item = new WC_Order_Item_Product(11, 42, 2, array(array('id' => 'month', 'value' => 'szeptember'), array('id' => 'year', 'value' => '1995')));
    $prompt = MG_AI_Print_Generator::prompt_for_item($item);
    check(str_contains($prompt, '"szeptember"') && str_contains($prompt, '"1995"'), 'legacy order values combine into one prompt');
    check(!str_contains($prompt, '{{ertek}}'), 'all placeholders replaced');
    $item->meta['_mg_custom_fields'][0]['raw_value'] = 'július';
    check(str_contains(MG_AI_Print_Generator::prompt_for_item($item), '"július"'), 'new raw order value takes precedence');
    unset($item->meta['_mg_custom_fields'][0]['raw_value']);
    $relabeled = new WC_Order_Item_Product(16, 42, 1, array(array('id' => 'month', 'value' => 'május'), array('id' => 'old_year_id', 'label' => ' év ', 'value' => '1987')));
    check(str_contains(MG_AI_Print_Generator::prompt_for_item($relabeled), '"1987"') && MG_AI_Print_Generator::values_for_item($relabeled)['year'] === '1987', 'value stored under an old field ID is matched by label');
    $visible_only = new WC_Order_Item_Product(17, 42, 1, array(array('id' => 'month', 'value' => 'május')));
    $visible_only->meta['Év'] = '2003';
    check(str_contains(MG_AI_Print_Generator::prompt_for_item($visible_only), '"2003"'), 'visible order item meta is used when the stored field entry is missing');
    $_POST = array('field_ai_print_enabled' => '1', 'field_ai_print_prompt' => addslashes('Csere: {{ertek}}. "Példa"'));
    $request_field = call_hidden('MG_Custom_Fields_Page', 'read_field_from_request');
    check($request_field['ai_print_enabled'] && $request_field['ai_print_prompt'] === 'Csere: {{ertek}}. "Példa"', 'admin save preserves prompt quotes and enabled flag');
    $render_field = $fields[0];
    $render_field['ai_print_prompt'] = 'Csere: {{ertek}}. </textarea><script>bad</script>';
    ob_start();
    call_hidden('MG_Custom_Fields_Page', 'render_field_editor_form', 'birthday', $render_field, false);
    $html = ob_get_clean();
    check(str_contains($html, 'name="field_ai_print_enabled" value="1" checked="checked"'), 'admin checkbox renders saved state');
    check(str_contains($html, 'name="field_ai_print_prompt"') && !str_contains($html, '<script>bad</script>'), 'admin prompt textarea escapes stored markup');
    $templates = MG_Custom_Fields_Page::get_ai_print_prompt_templates();
    check(count($templates) === 9, 'six known presets have nine field-specific prompt choices');
    foreach ($templates as $template_id => $template) {
        check(str_contains($html, esc_html($template['label'])) && str_contains($html, 'data-prompt="' . esc_attr($template['prompt']) . '"'), 'template is available and safely encoded: ' . $template_id);
        $template_field = $fields[0];
        $template_field['ai_print_prompt'] = $template['prompt'];
        set_fields(array($template_field));
        $template_item = new WC_Order_Item_Product(100, 42, 1, array(array('id' => 'month', 'value' => 'Árvíztűrő-Őz')));
        $template_prompt = MG_AI_Print_Generator::prompt_for_item($template_item);
        check(str_contains($template_prompt, '"Árvíztűrő-Őz"') && !str_contains($template_prompt, '{{ertek}}'), 'template accepts the ordered value: ' . $template_id);
    }
    check(str_contains($templates['age_year_age']['prompt'], 'életkort') && str_contains($templates['age_year_year']['prompt'], 'naptári évszámot'), 'age and calendar year have distinct instructions');
    set_fields($fields);
    check(!call_hidden('MG_Custom_Fields_Manager', 'sanitize_field', array('id' => 'legacy'))['ai_print_enabled'], 'existing fields default off');
    update_option('mg_custom_field_presets', array('birthday' => array('name' => 'Születésnap', 'product_ids' => array(42), 'fields' => $fields)));
    (new ReflectionProperty('MG_Custom_Fields_Manager', 'cached_presets'))->setValue(null, null);
    $updated = $fields;
    $updated[1]['ai_print_prompt'] = 'AKTUÁLIS év: {{ertek}}.';
    MG_Custom_Fields_Manager::update_preset('birthday', array('fields' => $updated));
    check(str_contains(MG_AI_Print_Generator::prompt_for_item($item), 'AKTUÁLIS'), 'current preset prompt applies without reassigning products');
    $updated[1]['ai_print_enabled'] = false;
    MG_Custom_Fields_Manager::update_preset('birthday', array('fields' => $updated));
    check(!str_contains(MG_AI_Print_Generator::prompt_for_item($item), '1995'), 'disabling preset AI immediately stops that edit');
    MG_Custom_Fields_Manager::update_preset('birthday', array('fields' => array()));
    check(MG_AI_Print_Generator::prompt_for_item($item) === '', 'deleted preset fields do not use stale product copies');
    set_fields($fields);
    $empty_item = new WC_Order_Item_Product(12, 42, 1, array());
    expect_error(fn() => MG_AI_Print_Generator::prompt_for_item($empty_item), 'Hiányzik a rendelt érték');
    $optional = $fields;
    $optional[0]['required'] = $optional[1]['required'] = false;
    set_fields($optional);
    check(MG_AI_Print_Generator::prompt_for_item($empty_item) === '', 'empty optional values do not generate');
    $bad_prompt = $fields;
    $bad_prompt[0]['ai_print_prompt'] = 'no placeholder';
    set_fields($bad_prompt);
    expect_error(fn() => MG_AI_Print_Generator::prompt_for_item($item), 'Hiányzó vagy hibás AI');
    set_fields($fields);
    foreach (array(array(3000, 4000), array(1000, 1000), array(4000, 3000), array(3000, 1000), array(1000, 3000)) as [$w, $h]) {
        [$rw, $rh] = array_map('intval', explode('x', MG_AI_Print_Generator::economical_size($w, $h)));
        check($rw % 16 === 0 && $rh % 16 === 0 && $rw * $rh >= 655360 && $rw * $rh <= 750000 && abs(($rw / $rh) / ($w / $h) - 1) <= 0.01, 'economical valid dimensions ' . $w . 'x' . $h);
    }
    expect_error(fn() => MG_AI_Print_Generator::economical_size(4000, 1000), 'képaránya');
    $source_path = $test_dir . '/source.png';
    $source_bytes = make_png(300, 400, 255);
    file_put_contents($source_path, $source_bytes);
    $posts[42] = $posts[99] = array('_mg_last_design_path' => $source_path);
    $checkout_item = new WC_Order_Item_Product(14, 42, 1, array());
    MG_Custom_Fields_Frontend::add_order_item_meta($checkout_item, 'cart-key', array('mg_custom_fields' => array(array('id' => 'year', 'label' => 'Év', 'value' => '1995', 'display' => '1995'))), null);
    check($checkout_item->meta['_mg_custom_fields'][0]['raw_value'] === '1995', 'checkout persists the raw customer value');
    $other = new WC_Order_Item_Product(12, 42, 1, array(array('id' => 'month', 'value' => 'május'), array('id' => 'year', 'value' => '2001')));
    $normal = new WC_Order_Item_Product(13, 99, 1, array());
    $normal->meta['mg_color'] = 'feher';
    $outlet = new WC_Order_Item_Product(15, 42, 1, array());
    $outlet->meta['_mg_outlet'] = 'yes';
    $orders[90] = new Test_Order(array($item, $other, $normal, $outlet));
    $tasks = call_hidden('MG_Order_Design_Download', 'build_export_tasks', array(90));
    check(count($tasks) === 4 && $tasks[0]['item_id'] === $tasks[1]['item_id'], 'one export file per quantity, same item shares edit');
    check($tasks[0]['ai_prompt'] !== $tasks[2]['ai_prompt'] && $tasks[3]['ai_prompt'] === '', 'different customer values stay isolated; normal product bypasses AI');
    check($tasks[0]['ai_model'] === 'gpt-image-2' && $tasks[1]['ai_model'] === 'gpt-image-2' && $tasks[3]['ai_model'] === '', 'task list pins the model for AI quantity copies only');
    check(!$tasks[0]['ai_defringe'] && !$tasks[1]['ai_defringe'] && !$tasks[3]['ai_defringe'], 'all new tasks disable fringe correction');
    check(!$actions && !$http_calls, 'building export plan does not generate');
    $job = make_job('job1', $tasks);
    $progress = call_hidden('MG_Order_Design_Download', 'process_export_step', 'job1');
    check($progress['waiting'] && $progress['completed'] === 0 && count($actions) === 1, 'first step dispatches once and returns promptly');
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'job1');
    check(count($actions) === 1, 'repeated polling does not enqueue twice');
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2', 'defringe_enabled' => '0'));
    $http_hook = function () {
        $during_api = call_hidden('MG_Order_Design_Download', 'process_export_step', 'job1');
        check($during_api['ai_stage'] === 'api' && $during_api['ai_status'] === 'running' && $during_api['ai_api_timeout'] === 180 && str_contains($during_api['message'], 'OpenAI válaszára vár'), 'polling exposes the real API stage and its configured timeout');
        check(isset($during_api['ai_elapsed'], $during_api['ai_stage_elapsed'], $during_api['ai_key']), 'progress includes elapsed times and the item attempt identity');
    };
    MG_AI_Print_Generator::run($actions[0][1][0]);
    check($worker_stages[MG_AI_Print_Generator::PREFIX . $actions[0][1][0]] === array('prepare', 'api', 'validate', 'upscale', 'save'), 'worker checkpoints each processing stage before expensive work');
    MG_AI_Print_Generator::run($actions[0][1][0]);
    check(MG_Image_Utils::$defringed === 0, 'generation skips fringe correction');
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2', 'defringe_enabled' => '1'));
    check(count($http_calls) === 1, 'duplicate worker delivery does not charge twice');
    [$url, $request] = $http_calls[0];
    check($url === 'https://api.openai.com/v1/images/edits' && $request['redirection'] === 0, 'fixed image edit endpoint without redirecting credentials');
    foreach (array('model' => 'gpt-image-2', 'quality' => 'low', 'n' => '1', 'output_format' => 'png', 'background' => 'transparent') as $name => $value) {
        check(str_contains($request['body'], 'name="' . $name . "\"\r\n\r\n" . $value . "\r\n"), 'API parameter ' . $name . '=' . $value);
    }
    check(str_contains($request['body'], $source_bytes) && !str_contains($request['body'], 'input_fidelity'), 'source PNG is uploaded and unsupported fidelity option omitted');
    $progress = call_hidden('MG_Order_Design_Download', 'process_export_step', 'job1');
    check($progress['waiting'] && $progress['completed'] === 2 && count($actions) === 2, 'quantity copies reuse image before next item is generated');
    MG_AI_Print_Generator::run($actions[1][1][0]);
    $progress = call_hidden('MG_Order_Design_Download', 'process_export_step', 'job1');
    check($progress['done'] && $progress['completed'] === 4 && count($http_calls) === 2, 'mixed export completes with exactly two edits');
    $zip = new ZipArchive();
    $zip->open($job['zip_path']);
    check($zip->numFiles === 4, 'actual ZIP contains all ordered copies');
    check($zip->getFromIndex(0) === $zip->getFromIndex(1), 'quantity copies contain identical edited PNG');
    check($zip->getFromIndex(0) !== $source_bytes && $zip->getFromIndex(0) !== $zip->getFromIndex(2), 'ZIP uses generated bytes, with distinct results per item');
    check($zip->getFromIndex(3) === $source_bytes && file_get_contents($source_path) === $source_bytes, 'normal export and original design preserved');
    [$generated_width, $generated_height] = array_map('intval', explode('x', MG_AI_Print_Generator::economical_size(300, 400)));
    foreach (array(0, 1, 2) as $index) {
        $export_info = getimagesizefromstring($zip->getFromIndex($index));
        check($export_info[0] === $generated_width * 3 && $export_info[1] === $generated_height * 3, 'AI ZIP entry has triple width and height: ' . $index);
        check(ord($zip->getFromIndex($index)[25]) === 6, 'upscaled AI entry retains alpha channel: ' . $index);
    }
    check(Imagick::$resize_calls === 2, 'only AI images upscale, once per item despite quantity copies');
    check(MG_Image_Utils::$defringed === 0, 'generated images receive no Design Flow correction');
    check(MG_Image_Utils::$defringe_resize_positions === array(), 'enlargement runs without fringe correction');
    $zip->close();
    check(MG_Image_Utils::$stripped === 4, 'black garment export removes black before sizing and again after final alpha');
    check(!file_exists($test_dir . '/mg-ai-print-' . $actions[0][1][0] . '.png'), 'completed export removes generated temporary PNG');
    foreach (array('gpt-image-2.5-sunburst', 'gpt-image-2.5-flare') as $model) {
        MG_AI_Print_Generator::save_settings(array('model' => $model));
        $model_tasks = call_hidden('MG_Order_Design_Download', 'build_export_tasks', array(90));
        check($model_tasks[0]['ai_model'] === $model, 'new export picks selected model: ' . $model);
        MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2'));
        make_job($model, array($model_tasks[0], $model_tasks[1]));
        call_hidden('MG_Order_Design_Download', 'process_export_step', $model);
        $model_action = end($actions);
        $model_calls_before = count($http_calls);
        MG_AI_Print_Generator::run($model_action[1][0]);
        MG_AI_Print_Generator::run($model_action[1][0]);
        check(count($http_calls) === $model_calls_before + 1, 'selected model generates only once: ' . $model);
        $model_request = end($http_calls)[1];
        foreach (array('model' => $model, 'quality' => 'low', 'n' => '1', 'output_format' => 'png', 'background' => 'transparent') as $name => $value) {
            check(str_contains($model_request['body'], 'name="' . $name . "\"\r\n\r\n" . $value . "\r\n"), 'pinned API parameter ' . $name . '=' . $value);
        }
        check(call_hidden('MG_Order_Design_Download', 'process_export_step', $model)['done'], 'selected model completes quantity export: ' . $model);
    }
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2.5-sunburst'));
    $legacy_task = $tasks[0];
    unset($legacy_task['ai_model']);
    make_job('legacy-model', array($legacy_task));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'legacy-model');
    $legacy_action = end($actions);
    MG_AI_Print_Generator::run($legacy_action[1][0]);
    check(str_contains(end($http_calls)[1]['body'], "name=\"model\"\r\n\r\ngpt-image-2\r\n"), 'pre-upgrade queued exports retain Image 2');
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'legacy-model')['done'], 'pre-upgrade export completes');
    $invalid_task = $tasks[0];
    $invalid_task['ai_model'] = 'not-supported';
    make_job('invalid-model', array($invalid_task));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'invalid-model');
    $invalid_action = end($actions);
    $before_invalid_http = count($http_calls);
    MG_AI_Print_Generator::run($invalid_action[1][0]);
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'invalid-model'), 'támogatott AI nyomat');
    check(count($http_calls) === $before_invalid_http, 'unsupported queued model stops before API billing');
    MG_AI_Print_Generator::save_settings(array('model' => 'gpt-image-2'));
    make_job('job2', array($tasks[0]));
    $current_user = 8;
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'job2'), 'más felhasználóhoz');
    $current_user = 7;
    $lock = 'mg_export_lock_' . hash('sha256', 'job2');
    add_option($lock, time());
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'job2')['waiting'], 'concurrent ZIP step waits without mutation');
    delete_option($lock);
    foreach (array('401' => 'API-kulcs érvénytelen', '429' => 'HTTP 429', 'network' => 'hálózati hibával', 'timeout28' => '180 másodperces időkorlátot', 'bad' => 'érvényes PNG', 'size' => 'érvényes PNG', 'opaque' => 'átlátszó hátteret') as $mode => $error) {
        $http_mode = (string) $mode;
        $id = 'failure-' . $mode;
        make_job($id, array($tasks[0]));
        call_hidden('MG_Order_Design_Download', 'process_export_step', $id);
        $last = end($actions);
        MG_AI_Print_Generator::run($last[1][0]);
        $before = count($http_calls);
        expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', $id), $error);
        MG_AI_Print_Generator::run($last[1][0]);
        check(count($http_calls) === $before, 'failure is not automatically retried: ' . $mode);
        $failed = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . $id);
        check($failed['status'] === 'error' && $failed['completed'] === 0 && !file_exists($failed['zip_path']), 'failure never exports the original: ' . $mode);
    }
    $http_mode = 'ok';
    $timeout_state = get_transient(MG_AI_Print_Generator::PREFIX . MG_AI_Print_Generator::task_key('failure-timeout28', $tasks[0]));
    check(str_contains($timeout_state['message'], 'cURL 28') && str_contains($timeout_state['message'], 'OpenAI válaszára vár') && !str_contains($timeout_state['message'], 'secret'), 'network timeout identifies the cause and last stage without leaking transport details');
    check(count($worker_logs) > 0 && end($worker_logs)[1]['source'] === 'mg-ai-print' && !str_contains(json_encode($worker_logs), 'secret'), 'failures have persistent, safe WooCommerce log context');

    make_job('worker-deadline', array($tasks[0]));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'worker-deadline');
    $deadline_key = MG_AI_Print_Generator::task_key('worker-deadline', $tasks[0]);
    $deadline_state =& $transients[MG_AI_Print_Generator::PREFIX . $deadline_key];
    $deadline_state['status'] = 'running';
    $deadline_state['stage'] = 'api';
    $deadline_state['started'] = time() - MG_AI_Print_Generator::STALLED_TIMEOUT - 1;
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'worker-deadline'), '5 percen belül');
    check(get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'worker-deadline')['status'] === 'error' && str_contains(end($worker_logs)[0], 'worker_stalled'), 'a killed process gets a persisted export error and log even when its shutdown callback cannot run');
    unset($deadline_state);
    make_job('late-before-retry', array($tasks[0]));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'late-before-retry');
    $late_before_key = MG_AI_Print_Generator::task_key('late-before-retry', $tasks[0]);
    $http_hook = function () use ($late_before_key) {
        $GLOBALS['transients'][MG_AI_Print_Generator::PREFIX . $late_before_key]['started'] -= MG_AI_Print_Generator::STALLED_TIMEOUT + 1;
        expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'late-before-retry'), '5 percen belül');
    };
    MG_AI_Print_Generator::run($late_before_key);
    $before_late_retry_calls = count($http_calls);
    call_hidden('MG_Order_Design_Download', 'retry_export', 'late-before-retry');
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'late-before-retry')['done'] && count($http_calls) === $before_late_retry_calls, 'valid result arriving after the watchdog but before explicit retry is reused without another API call');
    foreach (array('Allowed memory size exhausted: private-path' => 'memóriakeretét', 'Maximum execution time exceeded: private-path' => 'PHP-futásidőkorlátja') as $fatal_text => $expected) {
        $fatal_job = make_job('fatal-diagnosis', array($tasks[0]));
        $fatal_key = MG_AI_Print_Generator::task_key('fatal-diagnosis', $tasks[0]);
        set_transient(MG_AI_Print_Generator::PREFIX . $fatal_key, array('status' => 'running', 'stage' => 'upscale', 'started' => time() - 31, 'created' => time() - 35, 'job_id' => 'fatal-diagnosis', 'task' => $tasks[0]), 3600);
        add_option(MG_AI_Print_Generator::PREFIX . 'lock_' . $fatal_key, time());
        call_hidden('MG_AI_Print_Generator', 'record_interrupted_worker', $fatal_key, array('type' => E_ERROR, 'message' => $fatal_text));
        expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'fatal-diagnosis'), $expected);
        $fatal_state = get_transient(MG_AI_Print_Generator::PREFIX . $fatal_key);
        check(str_contains($fatal_state['message'], 'felnagyítása') && !str_contains($fatal_state['message'], 'private-path') && !get_option(MG_AI_Print_Generator::PREFIX . 'lock_' . $fatal_key), 'fatal diagnosis keeps the last stage, redacts raw details and releases the worker lock');
    }
    // A blocked scheduler can be serviced by the authenticated browser worker.
    make_job('fallback', array($tasks[0]));
    $fallback_progress = call_hidden('MG_Order_Design_Download', 'process_export_step', 'fallback');
    $fallback_key = MG_AI_Print_Generator::task_key('fallback', $tasks[0]);
    check($fallback_progress['ai_status'] === 'queued' && $fallback_progress['ai_worker_key'] === $fallback_key && str_contains($fallback_progress['message'], 'indításra vár'), 'queued work is handed to the open export tab immediately');
    check(min($GLOBALS['action_delays']) >= MG_AI_Print_Generator::SCHEDULER_DELAY - 1, 'the scheduler is only a delayed fallback, not the first worker');
    $fallback_calls = count($http_calls);
    $current_user = 8;
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'run_export_ai', 'fallback', $fallback_key), 'más felhasználóhoz');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'retry_export', 'failure-401'), 'más felhasználóhoz');
    $current_user = 7;
    call_hidden('MG_Order_Design_Download', 'run_export_ai', 'fallback', hash('sha256', 'foreign'));
    $fallback_lock = MG_AI_Print_Generator::PREFIX . 'lock_' . $fallback_key;
    add_option($fallback_lock, time());
    call_hidden('MG_Order_Design_Download', 'run_export_ai', 'fallback', $fallback_key);
    check(count($http_calls) === $fallback_calls, 'foreign keys and a scheduler-owned lock prevent fallback billing');
    delete_option($fallback_lock);
    call_hidden('MG_Order_Design_Download', 'run_export_ai', 'fallback', $fallback_key);
    MG_AI_Print_Generator::run($fallback_key);
    check(count($http_calls) === $fallback_calls + 1 && call_hidden('MG_Order_Design_Download', 'process_export_step', 'fallback')['done'], 'fallback completes and late scheduler delivery does not duplicate the edit');

    // Key expiry after an earlier item succeeded must retain that paid result.
    $recovery_job = make_job('recovery', $tasks);
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'recovery');
    $first_key = MG_AI_Print_Generator::task_key('recovery', $tasks[0]);
    MG_AI_Print_Generator::run($first_key);
    $first_path = MG_AI_Print_Generator::ready_path('recovery', $tasks[0]);
    $first_bytes = file_get_contents($first_path);
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'recovery');
    $second_key = MG_AI_Print_Generator::task_key('recovery', $tasks[2]);
    $http_mode = '401';
    MG_AI_Print_Generator::run($second_key);
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'recovery'), 'Export folytatása');
    $saved = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'recovery');
    check($saved['status'] === 'error' && is_file($first_path) && !is_file($saved['zip_path']), 'API failure preserves the first image while withholding the incomplete ZIP');
    check(!str_contains($saved['message'], 'secret provider message'), 'authentication failure does not expose provider response bodies');
    $recovery_calls = count($http_calls);
    update_option('mg_ai_seo_settings', array('api_key' => 'replacement-key'));
    call_hidden('MG_Order_Design_Download', 'retry_export', 'recovery');
    $retry_job = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'recovery');
    check($retry_job['status'] === 'processing' && $retry_job['completed'] === 0 && $retry_job['cache'] === array(), 'retry resets only local ZIP assembly');
    check(MG_AI_Print_Generator::task_key('recovery', $retry_job['tasks'][0]) === $first_key && MG_AI_Print_Generator::task_key('recovery', $retry_job['tasks'][2]) !== $second_key, 'retry retains ready image identity and isolates the failed attempt');
    call_hidden('MG_Order_Design_Download', 'retry_export', 'recovery');
    check(get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'recovery') === $retry_job && count($http_calls) === $recovery_calls, 'duplicate retry requests neither rotate attempts again nor call the API');
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'recovery')['completed'] === 2, 'successful quantity copies rebuild from the retained image');
    $http_mode = 'ok';
    $retry_key = MG_AI_Print_Generator::task_key('recovery', $retry_job['tasks'][2]);
    MG_AI_Print_Generator::run($retry_key);
    MG_AI_Print_Generator::run($second_key);
    check(count($http_calls) === $recovery_calls + 1 && end($http_calls)[1]['headers']['Authorization'] === 'Bearer replacement-key', 'only the failed image is retried using the newly saved API key');
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'recovery')['done'], 'recovered export completes');
    $zip->open($recovery_job['zip_path']);
    check($zip->numFiles === 4 && $zip->getFromIndex(0) === $first_bytes && $zip->getFromIndex(1) === $first_bytes && $zip->getFromIndex(2) !== $source_bytes, 'recovered ZIP has each copy exactly once and preserves the previous generated image');
    $zip->close();

    // A killed worker can leave both running state and its non-expiring option lock.
    make_job('stale-worker', array($tasks[0], $tasks[1]));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'stale-worker');
    $stale_key = MG_AI_Print_Generator::task_key('stale-worker', $tasks[0]);
    $transients[MG_AI_Print_Generator::PREFIX . $stale_key]['status'] = 'running';
    $transients[MG_AI_Print_Generator::PREFIX . $stale_key]['created'] -= MG_AI_Print_Generator::WAIT_TIMEOUT + 1;
    add_option(MG_AI_Print_Generator::PREFIX . 'lock_' . $stale_key, time() - 700);
    make_job('new-export', array($tasks[0]));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'new-export');
    $new_export_key = MG_AI_Print_Generator::task_key('new-export', $tasks[0]);
    call_hidden('MG_Order_Design_Download', 'run_export_ai', 'new-export', $new_export_key);
    check($new_export_key !== $stale_key && call_hidden('MG_Order_Design_Download', 'process_export_step', 'new-export')['done'], 'a new export completes while the previous export still has a stale running state and lock');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'stale-worker'), 'megszakadt vagy nem indult el');
    call_hidden('MG_Order_Design_Download', 'retry_export', 'stale-worker');
    $stale_retry = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'stale-worker');
    $stale_retry_key = MG_AI_Print_Generator::task_key('stale-worker', $stale_retry['tasks'][0]);
    check($stale_retry_key !== $stale_key && $stale_retry_key === MG_AI_Print_Generator::task_key('stale-worker', $stale_retry['tasks'][1]), 'stale-lock recovery gives quantity copies one fresh attempt');
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'stale-worker');
    MG_AI_Print_Generator::run($stale_retry_key);
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'stale-worker')['done'], 'stale worker lock cannot block the explicit replacement attempt');

    // Simulate an old HTTP request returning after timeout and explicit recovery.
    make_job('late-worker', array($tasks[0]));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'late-worker');
    $late_key = MG_AI_Print_Generator::task_key('late-worker', $tasks[0]);
    $http_hook = function () use ($late_key) {
        $GLOBALS['transients'][MG_AI_Print_Generator::PREFIX . $late_key]['created'] -= MG_AI_Print_Generator::WAIT_TIMEOUT + 1;
        expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'late-worker'), 'megszakadt vagy nem indult el');
        call_hidden('MG_Order_Design_Download', 'retry_export', 'late-worker');
    };
    MG_AI_Print_Generator::run($late_key);
    check(!is_file($test_dir . '/mg-ai-print-' . $late_key . '.png'), 'late old result is discarded without replacing the new attempt');
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'late-worker');
    $late_retry = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'late-worker');
    MG_AI_Print_Generator::run(MG_AI_Print_Generator::task_key('late-worker', $late_retry['tasks'][0]));
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'late-worker')['done'], 'late response leaves the replacement job usable');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'retry_export', 'missing'), 'lejárt');
    update_option('mg_ai_seo_settings', array('api_key' => 'test-key'));
    Imagick::$resize_failure = true;
    make_job('upscale-failure', array($tasks[0]));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'upscale-failure');
    $upscale_action = end($actions);
    MG_AI_Print_Generator::run($upscale_action[1][0]);
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'process_export_step', 'upscale-failure'), '3×-os felnagyítása');
    $failed_upscale = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'upscale-failure');
    check($failed_upscale['status'] === 'error' && $failed_upscale['completed'] === 0 && !file_exists($failed_upscale['zip_path']), 'upscale failure stops export without falling back to the original');
    Imagick::$resize_failure = false;
    // Review decisions are server-bound; no export/generation until all are supplied.
    $calls_before_review = count($http_calls);
    $actions_before_review = count($actions);
    $resizes_before_review = Imagick::$resize_calls;
    $defringed_before_review = MG_Image_Utils::$defringed;
    update_option('mg_ai_seo_settings', array('api_key' => ''));
    $review = call_hidden('MG_Order_Design_Download', 'create_export_review', array(90), false);
    $review_id = $review['review_id'];
    check(count($review['items']) === 2 && $review['total'] === 4, 'review groups quantity copies and excludes ordinary/outlet items');
    check($review['items'][0]['quantity'] === 2 && count($review['items'][0]['fields']) === 2, 'review shows all AI field values for one item together');
    check($review['items'][0]['fields'][0]['value'] === 'szeptember' && $review['items'][0]['fields'][1]['value'] === '1995', 'review values match the values used in the AI prompt');
    check(!str_contains(json_encode($review), 'design_path') && !str_contains(json_encode($review), $test_dir), 'review response exposes no server file paths');
    check(count($http_calls) === $calls_before_review && count($actions) === $actions_before_review, 'review needs no API key and dispatches no work');
    $original_decisions = array('90_11' => 'original', '90_12' => 'original');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', '', $original_decisions), 'ellenőrzés lejárt');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, array()), 'Minden egyedi tételnél');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, array('90_11' => 'original')), 'Minden egyedi tételnél');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, array('90_11' => 'original', '90_999' => 'original')), 'Minden egyedi tételnél');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, array('90_11' => true, '90_12' => 'original')), 'Minden egyedi tételnél');
    $current_user = 8;
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, $original_decisions), 'más felhasználóhoz');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'review_preview_path', $review_id, '90_11'), 'más felhasználóhoz');
    $current_user = 7;
    check(call_hidden('MG_Order_Design_Download', 'review_preview_path', $review_id, '90_11') === realpath($source_path), 'preview serves the exact source selected for export');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'review_preview_path', $review_id, '90_13'), 'nem része');
    $outside = tempnam(sys_get_temp_dir(), 'mg_preview_outside_');
    file_put_contents($outside, $source_bytes);
    try {
        expect_error(fn() => call_hidden('MG_Order_Design_Download', 'review_image_path', $outside), 'feltöltések könyvtárában');
    } finally { unlink($outside); }
    $item->meta['_mg_custom_fields'][1]['value'] = '2000';
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, $original_decisions), 'megváltoztak');
    $item->meta['_mg_custom_fields'][1]['value'] = '1995';
    file_put_contents($source_path, make_png(300, 400, 200));
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, $original_decisions), 'alapminta megváltozott');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'review_preview_path', $review_id, '90_11'), 'alapminta megváltozott');
    file_put_contents($source_path, $source_bytes);
    $review_lock = 'mg_review_start_' . hash('sha256', $review_id);
    add_option($review_lock, time());
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, $original_decisions), 'indítása folyamatban');
    delete_option($review_lock);
    $review_job = call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, $original_decisions);
    check(call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, $original_decisions) === $review_job, 'duplicate final submission reuses the same job');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $review_id, array('90_11' => 'generate', '90_12' => 'original')), 'már más döntésekkel');
    $saved_review_job = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . $review_job['job_id']);
    check($saved_review_job['tasks'][0]['ai_prompt'] === '' && $saved_review_job['tasks'][1]['ai_prompt'] === '', 'original decision applies to every quantity copy');
    do { $result = call_hidden('MG_Order_Design_Download', 'process_export_step', $review_job['job_id']); } while (!$result['done']);
    $review_zip = new ZipArchive();
    $review_zip->open($saved_review_job['zip_path']);
    check($review_zip->numFiles === 4 && $review_zip->getFromIndex(0) === $source_bytes && $review_zip->getFromIndex(2) === $source_bytes, 'all-original review exports the original designs with normal processing');
    $review_zip->close();
    unlink($saved_review_job['zip_path']);
    check(count($http_calls) === $calls_before_review && count($actions) === $actions_before_review && Imagick::$resize_calls === $resizes_before_review && MG_Image_Utils::$defringed === $defringed_before_review, 'original choices skip AI billing, worker dispatch, fringe correction and 3x enlargement');
    $mixed_review = call_hidden('MG_Order_Design_Download', 'create_export_review', array(90), true);
    $mixed_decisions = array('90_11' => 'original', '90_12' => 'generate');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $mixed_review['review_id'], $mixed_decisions), 'OpenAI API-kulcsot');
    update_option('mg_ai_seo_settings', array('api_key' => 'test-key'));
    $mixed_job = call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $mixed_review['review_id'], $mixed_decisions);
    $saved_mixed_job = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . $mixed_job['job_id']);
    check($saved_mixed_job['strip_black'] === true, 'review retains the selected black-removal option');
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', $mixed_job['job_id'])['waiting'], 'mixed review waits only for the chosen AI item');
    $mixed_action = end($actions);
    MG_AI_Print_Generator::run($mixed_action[1][0]);
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', $mixed_job['job_id'])['done'], 'mixed reviewed export completes');
    $review_zip->open($saved_mixed_job['zip_path']);
    check($review_zip->numFiles === 4 && $review_zip->getFromIndex(0) === $source_bytes && $review_zip->getFromIndex(1) === $source_bytes && $review_zip->getFromIndex(2) !== $source_bytes && $review_zip->getFromIndex(3) === $source_bytes, 'mixed ZIP uses original or generated bytes exactly as reviewed');
    $review_zip->close();
    unlink($saved_mixed_job['zip_path']);
    check(count($http_calls) === $calls_before_review + 1 && Imagick::$resize_calls === $resizes_before_review + 1, 'multiple field values still produce one generation and enlargement for the selected item');
    $defringe_before = MG_Image_Utils::$defringed;
    $disabled_task = $tasks[0];
    $disabled_task['ai_defringe'] = false;
    make_job('defringe-disabled', array($disabled_task));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'defringe-disabled');
    $disabled_action = end($actions);
    MG_AI_Print_Generator::run($disabled_action[1][0]);
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'defringe-disabled')['done'] && MG_Image_Utils::$defringed === $defringe_before, 'disabled correction keeps generation and export working');
    MG_Image_Utils::$defringe_failure = true;
    $legacy_task = $tasks[0];
    $legacy_task['ai_defringe'] = true;
    make_job('defringe-failure', array($legacy_task));
    call_hidden('MG_Order_Design_Download', 'process_export_step', 'defringe-failure');
    $failed_action = end($actions);
    MG_AI_Print_Generator::run($failed_action[1][0]);
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', 'defringe-failure')['done'], 'legacy enabled task skips correction even when helper would fail');
    $failed_fringe_job = get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . 'defringe-failure');
    check(MG_Image_Utils::$defringed === 0 && is_file($failed_fringe_job['zip_path']), 'legacy task exports successfully with correction disabled');
    MG_Image_Utils::$defringe_failure = false;
    $orders[91] = new Test_Order(array($normal));
    $normal_review = call_hidden('MG_Order_Design_Download', 'create_export_review', array(91), false);
    check($normal_review['items'] === array() && $normal_review['total'] === 1, 'ordinary-only exports have no decisions to review');
    $normal_job = call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $normal_review['review_id'], array());
    check(call_hidden('MG_Order_Design_Download', 'process_export_step', $normal_job['job_id'])['done'], 'ordinary-only reviewed export still works');
    unlink(get_transient(MG_Order_Design_Download::JOB_TRANSIENT_PREFIX . $normal_job['job_id'])['zip_path']);
    delete_transient(MG_Order_Design_Download::REVIEW_TRANSIENT_PREFIX . $normal_review['review_id']);
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'start_reviewed_export', $normal_review['review_id'], array()), 'ellenőrzés lejárt');
    update_option('mg_ai_seo_settings', array('api_key' => ''));
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'build_export_tasks', array(90)), 'OpenAI API-kulcsot');
    update_option('mg_ai_seo_settings', array('api_key' => 'test-key'));
    $item->meta['_mg_print_design_reference'] = array('design_path' => $test_dir . '/missing.png');
    expect_error(fn() => call_hidden('MG_Order_Design_Download', 'build_export_tasks', array(90)), 'alapmintája nem található');
    echo "\n" . $assertions . " assertions passed. HTTP/scheduler/Imagick mocked; ZIP and PNG bytes are real.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach (glob($test_dir . '/*') as $file) { unlink($file); }
    rmdir($test_dir);
}
