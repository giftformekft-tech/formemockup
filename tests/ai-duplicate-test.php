<?php
/**
 * php tests/ai-duplicate-test.php
 * "AI duplikátum" on real Imagick with WordPress, HTTP and product queue
 * doubles: one analysis for N variants, drawing, redrawing with an edited
 * prompt, the upscale input and upload, making new products from the
 * original's settings, and dropping variants. No paid API calls.
 */
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
if (!class_exists('Imagick')) {
    echo "This test requires the real Imagick extension.\n";
    exit(0);
}
$options = $meta = $http_calls = $deleted = $enqueued = $terms = array();
$http_responses = array();
$test_dir = sys_get_temp_dir() . '/mg-duplicate-test-' . bin2hex(random_bytes(4));
mkdir($test_dir);
$next_attachment = 100;

function __($s) { return $s; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_kses_post($s) { return $s; }
function wp_unslash($s) { return $s; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_normalize_path($p) { return str_replace('\\', '/', $p); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function wp_upload_dir() { return array('basedir' => $GLOBALS['test_dir'], 'baseurl' => 'https://example.test/uploads'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function get_temp_dir() { return $GLOBALS['test_dir']; }
function wp_generate_uuid4() { return bin2hex(random_bytes(8)); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function add_option($k, $v, $deprecated = '', $autoload = null) { if (isset($GLOBALS['options'][$k])) return false; $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function get_transient($k) { return false; }
function set_transient($k, $v, $ttl) { return true; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
function get_post_field($field, $id) { return $field === 'post_name' ? 'apa-cirkusz' : ''; }
$attached = array();
function get_attached_file($id) { return $GLOBALS['attached'][$id] ?? $GLOBALS['test_dir'] . '/att-' . $id . '.png'; }
function wp_delete_attachment($id, $force) { $GLOBALS['deleted'][] = $id; @unlink(get_attached_file($id)); return true; }
function get_current_user_id() { return 7; }
function current_time($type) { return '2026-10-30 10:00:00'; }
function wp_get_attachment_image_url($id, $size) { return "https://example.test/att-$id-medium.png"; }
function wp_get_attachment_url($id) { return "https://example.test/att-$id.png"; }
function wp_generate_attachment_metadata($id, $path) { return array('file' => basename($path)); }
function wp_update_attachment_metadata($id, $data) { return true; }
function get_post_thumbnail_id($id) { return $GLOBALS['meta'][$id]['_thumbnail_id'] ?? 0; }
function wp_get_post_terms($id, $taxonomy, $args) { return $GLOBALS['terms'][$id][$taxonomy] ?? array(); }
function mg_get_catalog_products() { return array(array('key' => 'polo'), array('key' => 'baba-body'), array('key' => 'bogre')); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error {
    public function __construct(private $message = '') {}
    public function get_error_message() { return $this->message; }
}
function wp_remote_post($url, $request) {
    $GLOBALS['http_calls'][] = array($url, $request);
    return array_shift($GLOBALS['http_responses']);
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function as_enqueue_async_action() { return 1; }
class Fake_Product {
    public function get_name() { return 'Apa cirkusz igazgatója'; }
    public function get_sku() { return 'FORME21779'; }
}
function wc_get_product($id) { return $id === 5 ? new Fake_Product() : false; }
class MG_Custom_Field_Product_Variants {
    public static function create_png_attachment($tmp, $filename, $title) {
        $id = ++$GLOBALS['next_attachment'];
        copy($tmp, get_attached_file($id));
        $GLOBALS['attachment_titles'][$id] = $title;
        return $id;
    }
}
class MG_Design_Replace {
    public static function detect_product_type_keys($product_id, $sku, $keys) {
        return $product_id === 5 && $sku === 'FORME21779' ? array('polo', 'baba-body') : array();
    }
}
class MG_Bulk_Queue {
    public static function enqueue(array $payload, $dispatch = true) {
        $GLOBALS['enqueued'][] = $payload;
        return 'mgjob_' . count($GLOBALS['enqueued']);
    }
}
$custom_fields = array(5 => array(array('id' => 'ev', 'label' => 'Év')));
$custom_products = array(5 => true);
$preset_copies = array();
class MG_Custom_Fields_Manager {
    public static function is_custom_product($id) { return !empty($GLOBALS['custom_products'][$id]); }
    public static function set_custom_product($id, $on) { $GLOBALS['custom_products'][$id] = $on; }
    public static function get_fields_for_product($id) { return $GLOBALS['custom_fields'][$id] ?? array(); }
    public static function save_fields_for_product($id, $fields) { $GLOBALS['custom_fields'][$id] = $fields; }
    public static function copy_preset_membership($from, $to) { $GLOBALS['preset_copies'][] = array($from, $to); }
}
require __DIR__ . '/../includes/class-image-utils.php';
require __DIR__ . '/../includes/class-ai-seo-generator.php';
require __DIR__ . '/../includes/class-ai-print-generator.php';
require __DIR__ . '/../includes/class-ai-redesign.php';
require __DIR__ . '/../includes/class-ai-duplicate.php';

$passed = 0;
function check($cond, $label) {
    if (!$cond) { fwrite(STDERR, "FAIL - $label\n"); exit(1); }
    $GLOBALS['passed']++;
}
function throws($fn, $needle, $label) {
    try { $fn(); } catch (RuntimeException $e) { check(strpos($e->getMessage(), $needle) !== false, $label . ' (' . $e->getMessage() . ')'); return; }
    check(false, $label . ' (no exception)');
}
/** A transparent PNG: a red square on transparent, $w × $h. */
function design_png($w, $h) {
    $image = new Imagick();
    $image->newImage($w, $h, new ImagickPixel('transparent'));
    $draw = new ImagickDraw();
    $draw->setFillColor('#e8562a');
    $draw->rectangle((int) ($w * 0.2), (int) ($h * 0.2), (int) ($w * 0.8), (int) ($h * 0.8));
    $image->drawImage($draw);
    $image->setImageFormat('png');
    $blob = $image->getImageBlob();
    $image->clear();
    return $blob;
}
function image_response($w, $h) {
    return array('code' => 200, 'body' => json_encode(array('data' => array(array('b64_json' => base64_encode(design_png($w, $h)))))));
}
function variant($name, $direction) {
    return array(
        'product_name' => $name, 'direction' => $direction, 'topic' => 'a circus dad', 'archetype' => 'a circus poster badge',
        'motif' => 'a ringmaster', 'mood' => 'proud, playful', 'layout' => 'the title in an arc above the figure',
        'signature_elements' => array(array('element' => 'circus tent', 'replacement' => $direction . ' frame')),
        'lettering_style' => 'bold slab serif', 'art_style' => 'flat vector', 'new_style' => 'Más: ' . $direction,
    );
}
function plan_json(array $variants) {
    return json_encode(array(
        'text_lines' => array('APA', 'CIRKUSZ', 'IGAZGATÓJA'), 'line_sizes' => array('dominant', 'large', 'small'),
        'tone' => 'light', 'has_illustration' => true, 'color_count' => 4, 'colors' => array('red', 'yellow', 'white', 'black'),
        'original_style' => 'Cirkuszi plakát.', 'variants' => $variants,
    ), JSON_UNESCAPED_UNICODE);
}

update_option(MG_AI_SEO_Generator::OPTION_KEY, array('api_key' => 'sk-test'));
file_put_contents($test_dir . '/design.png', design_png(800, 800));
$meta[5]['_mg_last_design_path'] = $test_dir . '/design.png';

// One analysis call for every variant: the count and level go to the analyzer.
$instructions = MG_AI_Duplicate::duplicate_instructions(3, 'subtle');
check(strpos($instructions, '3 új változatot') !== false && strpos($instructions, 'Extra finom') !== false, 'the analyzer is told the count and the level');
$schema = MG_AI_Duplicate::duplicate_schema();
check(in_array('variants', $schema['required'], true) && in_array('product_name', $schema['properties']['variants']['items']['required'], true), 'every variant has a product name');
throws(function () { MG_AI_Duplicate::parse_variants('{"variants": []}', 2); }, 'változatokat', 'no usable variant');
$parsed = MG_AI_Duplicate::parse_variants(plan_json(array(variant('Cirkuszkirály apa', 'retro badge'), array('product_name' => 'x'), variant('Porondmester', 'neon'))), 3);
check(count($parsed) === 2 && $parsed[0]['name'] === 'Cirkuszkirály apa' && $parsed[1]['direction'] === 'neon', 'a variant without a brief is skipped');
check($parsed[0]['analysis']['text_lines'] === array('APA', 'CIRKUSZ', 'IGAZGATÓJA') && $parsed[0]['analysis']['topic'] === 'a circus dad', 'the common fields go into every variant');

$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'completed', 'output_text' => plan_json(array(
    variant('Cirkuszkirály apa', 'retro badge'), variant('Porondmester apa', 'neon sign'), variant('Az apa a porond ura', 'woodcut print'),
)))));
$slots = MG_AI_Duplicate::plan(5, array('count' => 3, 'analyzer' => 'openai', 'intensity' => 'medium', 'quality' => 'low', 'style_hint' => 'vidám'));
list($url, $request) = $http_calls[0];
$body = json_decode($request['body'], true);
check($url === MG_AI_Redesign::OPENAI_ENDPOINT && count($http_calls) === 1, 'one analyzer call for three variants');
check(strpos($body['input'][0]['content'][0]['text'], 'Változatok száma: 3') !== false && $body['text']['format']['schema'] === MG_AI_Duplicate::duplicate_schema(), 'the count and the variants schema are sent');
check(count($slots) === 3 && MG_AI_Duplicate::slot_ids(5) === array_column($slots, 'id'), 'three slots, in order');
check($slots[0]['status'] === 'planned' && $slots[0]['name'] === 'Cirkuszkirály apa' && $slots[0]['quality'] === 'low' && $slots[0]['intensity'] === 'medium', 'a slot keeps its name and settings');
check(strpos($slots[1]['prompt'], 'Style direction: vidám; neon sign.') !== false, "the admin's idea and the variant's direction");
check(strpos($slots[1]['prompt'], '"APA" / "CIRKUSZ" / "IGAZGATÓJA"') !== false && strpos($slots[1]['prompt'], 'instead of circus tent, use neon sign frame') !== false, 'the text and the variant\'s own replacement');

// Drawing a slot: its own PNG; a redraw with an edited prompt replaces it.
$http_calls = array();
$http_responses[] = image_response(816, 816);
$slot = MG_AI_Duplicate::generate_slot(5, $slots[0]['id']);
$first = $slot['attachment_id'];
$sent = json_decode($http_calls[0][1]['body'], true);
check($sent['prompt'] === $slots[0]['prompt'] && $sent['quality'] === 'low' && $sent['size'] === '816x816', 'drawn from its own prompt');
check($slot['status'] === 'done' && $first > 100 && is_file(get_attached_file($first)) && $slot['upscale'] === 'none' && $slot['width'] === 816, 'a drawn slot');
check($GLOBALS['attachment_titles'][$first] === 'Apa cirkusz igazgatója – AI duplikátum' && strpos($slot['full'], 'att-' . $first . '.png?v=') !== false, 'its PNG in the media library, a versioned URL');
check($slot['upscale_factor'] === 3 && $slot['upscale_engine'] === 'cugan', 'the page is told the upscale');
$http_calls = array();
$http_responses[] = image_response(816, 816);
$slot = MG_AI_Duplicate::generate_slot(5, $slots[0]['id'], 'A ringmaster dad, woodcut.');
$sent = json_decode($http_calls[0][1]['body'], true);
check($slot['attachment_id'] !== $first && in_array($first, $deleted, true), 'the redraw replaces the earlier PNG');
check(strpos($sent['prompt'], 'A ringmaster dad, woodcut.') === 0 && strpos($sent['prompt'], MG_AI_Redesign::OUTLINE_RULE) !== false && $slot['prompt_mode'] === 'manual', 'an edited prompt keeps the house outline');
throws(function () { MG_AI_Duplicate::generate_slot(5, 'aaaaaaaaaaaa'); }, 'nem létezik', 'an unknown slot');

// The upscale input once, then the browser's 3× PNG.
$prepared = MG_AI_Duplicate::prepare_slot(5, $slots[0]['id']);
check($prepared['prepared'] === true && $prepared['width'] < 816 && getimagesize(get_attached_file($slot['attachment_id']))[0] === $prepared['width'], 'prepared: cropped to the artwork');
$upload = $test_dir . '/up.png';
file_put_contents($upload, design_png($prepared['width'] * 2, $prepared['height'] * 2 + 1));
throws(function () use ($upload, $slots) { MG_AI_Duplicate::store_upscaled_slot(5, $slots[0]['id'], $upload); }, 'négyszerese', 'a wrong size is refused');
file_put_contents($upload, design_png($prepared['width'] * 3, $prepared['height'] * 3));
$stored = MG_AI_Duplicate::store_upscaled_slot(5, $slots[0]['id'], $upload);
check($stored['upscale'] === 'ai3x' && $stored['upscale_model'] === 'cugan' && $stored['width'] === $prepared['width'] * 3, 'the 3× upload replaces the file');
check($stored['palette'] === MG_Image_Utils::png_palette_size(get_attached_file($stored['attachment_id'])), 'its palette size read back from the PNG');
throws(function () use ($upload, $slots) { MG_AI_Duplicate::store_upscaled_slot(5, $slots[0]['id'], $upload); }, 'már fel van skálázva', 'only once');

// A kept variant becomes a new product with the original's settings.
$terms[5] = array('product_cat' => array(12, 34), 'product_tag' => array('apa', 'cirkusz'));
$meta[5]['_thumbnail_id'] = 900;
$attached[900] = $test_dir . '/FORME21779_baba-body_aqua-kek_front.webp';
$result = MG_AI_Duplicate::create_product(5, $slots[0]['id'], '  Cirkuszkirály apa  ');
$payload = end($enqueued);
check($result['job_id'] === 'mgjob_1' && strpos($result['message'], 'Cirkuszkirály apa') !== false, 'queued as a new product');
check($payload['parent_id'] === 0 && $payload['parent_name'] === 'Cirkuszkirály apa' && $payload['design_path'] === get_attached_file($stored['attachment_id']), 'a new product from the variant\'s PNG');
check($payload['product_keys'] === array('polo', 'baba-body') && $payload['categories'] === array('main' => 0, 'subs' => array(12, 34)) && $payload['tags'] === array('apa', 'cirkusz'), 'product types, categories and tags of the original');
check($payload['defaults'] === array('type' => 'baba-body', 'color' => 'aqua-kek', 'size' => ''), 'the default type and color of the featured mockup');
check($payload['custom_product'] === 1 && $payload['duplicate_of'] === 5 && $payload['sample_seo'] === '' && $payload['trigger'] === 'ai_duplicate', 'custom fields flag, source, no copied SEO text');
check(!in_array($stored['attachment_id'], $deleted, true) && !in_array($slots[0]['id'], MG_AI_Duplicate::slot_ids(5), true), 'the slot leaves the list, its PNG stays');
check(end($meta[5][MG_AI_Duplicate::META_DUP_HISTORY])['name'] === 'Cirkuszkirály apa' && end($meta[5][MG_AI_Duplicate::META_DUP_HISTORY])['upscale'] === 'ai3x', 'history on the original');

// The default type and color: only from the original's own featured mockup of a type it has.
check(MG_AI_Duplicate::default_combo(5, 'FORME21779', array('polo')) === array('type' => '', 'color' => '', 'size' => ''), 'a type it does not have: catalog default');
$attached[900] = $test_dir . '/FORME11111_polo_fekete_front.webp';
check(MG_AI_Duplicate::default_combo(5, 'FORME21779', array('polo'))['type'] === '', "another product's mockup: catalog default");
$attached[900] = $test_dir . '/FORME21779_polo_fekete_front-1.webp';
check(MG_AI_Duplicate::default_combo(5, 'FORME21779', array('polo')) === array('type' => 'polo', 'color' => 'fekete', 'size' => ''), 'a numbered copy of the mockup');

// A variant the browser did not upscale: the sharp enlargement, then the product.
$http_responses[] = image_response(816, 816);
MG_AI_Duplicate::generate_slot(5, $slots[1]['id']);
$result = MG_AI_Duplicate::create_product(5, $slots[1]['id'], '');
$payload = end($enqueued);
check($payload['parent_name'] === 'Porondmester apa', 'an empty name field takes the suggested name');
$size = getimagesize($payload['design_path']);
check($size[0] > 816 && $size[0] % 3 === 0, 'the sharp 3× enlargement of the prepared PNG: ' . $size[0]);
check(end($meta[5][MG_AI_Duplicate::META_DUP_HISTORY])['upscale'] === 'lanczos3x', 'history records the fallback');
throws(function () use ($slots) { MG_AI_Duplicate::create_product(5, $slots[2]['id'], 'X'); }, 'Generáld újra', 'a slot without a picture cannot become a product');

// After the queue: the original's custom fields and preset lists, and the source.
MG_AI_Duplicate::finish_new_product(77, array('duplicate_of' => 5));
check($meta[77][MG_AI_Duplicate::META_SOURCE] === 5 && $custom_fields[77] === $custom_fields[5] && $custom_products[77] === true && $preset_copies === array(array(5, 77)), 'custom fields and presets copied');
MG_AI_Duplicate::finish_new_product(78, array());
check(!isset($meta[78]) && !isset($custom_fields[78]), 'other new products are left alone');

// Dropping: one or all, with their PNGs; a full list refuses a new plan.
$http_responses[] = image_response(816, 816);
$drawn = MG_AI_Duplicate::generate_slot(5, $slots[2]['id']);
$count = MG_AI_Duplicate::discard_slots(5);
check($count === 1 && in_array($drawn['attachment_id'], $deleted, true) && MG_AI_Duplicate::slot_ids(5) === array() && !isset($options[MG_AI_Duplicate::SLOT_PREFIX . '5_' . $slots[2]['id']]), 'all dropped with their PNGs');
throws(function () { MG_AI_Duplicate::discard_slots(5); }, 'nem található', 'nothing to drop');
$meta[5][MG_AI_Duplicate::META_SLOTS] = array_fill(0, 7, 'abcdefabcdef');
throws(function () { MG_AI_Duplicate::plan(5, array('count' => 2, 'analyzer' => 'openai')); }, 'legfeljebb 8', 'at most eight open variants');
unset($meta[5][MG_AI_Duplicate::META_SLOTS]);

// A slot stuck past the run limit becomes an error on the page.
$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'completed', 'output_text' => plan_json(array(variant('Egy', 'a'))))));
$one = MG_AI_Duplicate::plan(5, array('count' => 1, 'analyzer' => 'openai'));
$options[MG_AI_Duplicate::SLOT_PREFIX . '5_' . $one[0]['id']]['status'] = 'running';
$options[MG_AI_Duplicate::SLOT_PREFIX . '5_' . $one[0]['id']]['started'] = time() - 3600;
$ui = MG_AI_Duplicate::slots_for_ui(5);
check($ui[0]['status'] === 'error' && strpos($ui[0]['message'], 'PHP-időkorlát') !== false, 'a lost worker becomes an error');

array_map('unlink', glob("$test_dir/*"));
rmdir($test_dir);
echo "AI duplicate: $passed checks passed on real Imagick. HTTP and the product queue mocked, no API calls.\n";
