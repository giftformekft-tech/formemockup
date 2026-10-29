<?php
/**
 * php tests/ai-redesign-test.php
 * "AI újratervezés" with WordPress, HTTP and Imagick doubles. No paid API
 * calls: checks the Claude/OpenAI analysis requests, the image prompt rules,
 * the reference-free generation request and the approval flow.
 */
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
$options = $meta = $http_calls = $deleted = $enqueued = array();
$http_responses = array();
$test_dir = sys_get_temp_dir() . '/mg-redesign-test-' . bin2hex(random_bytes(4));
mkdir($test_dir);

function __($s) { return $s; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function esc_url_raw($s) { return $s; }
function wp_kses_post($s) { return $s; }
function wp_unslash($s) { return $s; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_normalize_path($p) { return str_replace('\\', '/', $p); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function wp_upload_dir() { return array('basedir' => $GLOBALS['test_dir'], 'baseurl' => 'https://example.test/uploads'); }
function get_temp_dir() { return $GLOBALS['test_dir']; }
function wp_generate_uuid4() { return bin2hex(random_bytes(8)); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
// The run state must not depend on transients (an object cache may drop them).
function get_transient($k) { return false; }
function set_transient($k, $v, $ttl) { return true; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
function get_attached_file($id) { return $GLOBALS['test_dir'] . '/att-' . $id . '.png'; }
function wp_delete_attachment($id, $force) { $GLOBALS['deleted'][] = $id; return true; }
function get_current_user_id() { return 7; }
$metadata_refreshed = array();
function wp_get_attachment_image_url($id, $size) { return "https://example.test/att-$id-medium.png"; }
function wp_get_attachment_url($id) { return "https://example.test/att-$id.png"; }
function wp_generate_attachment_metadata($id, $path) { return array('file' => basename($path)); }
function wp_update_attachment_metadata($id, $data) { $GLOBALS['metadata_refreshed'][] = $id; return true; }
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
if (!class_exists('Imagick')) {
class Imagick {
    const CHANNEL_ALPHA = 8;
    const FILTER_LANCZOS = 22;
    private $w = 0, $h = 0;
    public function __construct($path = null) { if ($path) { $i = getimagesize($path); $this->w = $i[0]; $this->h = $i[1]; } }
    public function getImageWidth() { return $this->w; }
    public function getImageHeight() { return $this->h; }
    public function resizeImage($w, $h, $f, $b) { $this->w = $w; $this->h = $h; return true; }
    public function setImagePage($a, $b, $c, $d) {}
    public function setImageFormat($f) {}
    public function getImageBlob() { return make_png($this->w, $this->h); }
    public function getImageAlphaChannel() { return true; }
    public function getImageChannelRange($channel) { return array('minima' => 0.0); }
    public static function getQuantumRange() { return array('quantumRangeLong' => 65535); }
    public function readImageBlob($b) {}
    public function clear() {}
}
}
class MG_Design_Replace {
    public static function enqueue_replacement($product_id, $path, $trigger) {
        $GLOBALS['enqueued'][] = array($product_id, $path, $trigger);
        return array('job_id' => 'job1', 'message' => 'A csere sorba állítva (2 terméktípus).');
    }
}

// The palette PNG is made in the browser (tests/palette-png-reference-test.py);
// the server only reads its size back (tests/palette-png-test.php on real Imagick).
$palette_sizes = array();
$prepare_calls = array();
$color_loss = array();
class MG_Image_Utils {
    const COLOR_LOSS = 4201;
    public static function prepare_upscale_input($path, $clean_edges, $alpha_threshold) {
        $GLOBALS['prepare_calls'][] = array(basename($path), $clean_edges, $alpha_threshold);
        if (in_array('prepare', $GLOBALS['color_loss'], true)) {
            throw new RuntimeException('A szerver ImageMagickje (ImageMagick 7.1.2-34 Q16-HDRI) elrontotta a minta színeit: a felskálázás előtti előkészítés.', self::COLOR_LOSS);
        }
        // The crop: a 10×10 test PNG becomes 8×6.
        file_put_contents($path, make_png(8, 6));
        return array('width' => 8, 'height' => 6, 'changed' => true);
    }
    public static function png_palette_size($path) {
        return $GLOBALS['palette_sizes'][basename($path)] ?? 0;
    }
}
require __DIR__ . '/../includes/class-ai-seo-generator.php';
require __DIR__ . '/../includes/class-ai-print-generator.php';
require __DIR__ . '/../includes/class-ai-redesign.php';

$passed = 0;
function check($cond, $label) {
    if (!$cond) { fwrite(STDERR, "FAIL - $label\n"); exit(1); }
    $GLOBALS['passed']++;
}
function throws($fn, $needle, $label) {
    try { $fn(); } catch (RuntimeException $e) { check(strpos($e->getMessage(), $needle) !== false, $label . ' (' . $e->getMessage() . ')'); return; }
    check(false, $label . ' (no exception)');
}
function analysis_json() {
    return json_encode(array(
        'text_lines' => array('Legjobb Apa', 'a világon'), 'line_sizes' => array('dominant', 'small'),
        'topic' => 'the best dad in the world', 'archetype' => 'a bold typographic badge',
        'motif' => '', 'mood' => 'proud, warm', 'layout' => 'the title in an arc above, the subtitle below',
        'signature_elements' => array(
            array('element' => 'laurel wreath around the title', 'replacement' => 'a ring of small stars'),
            array('element' => 'teal ribbon banner', 'replacement' => 'a rounded label'),
        ),
        'tone' => 'light', 'has_illustration' => false, 'color_count' => 4, 'colors' => array('white', 'yellow', 'teal', 'red'),
        'original_style' => 'kézírás', 'new_style' => 'retro',
    ), JSON_UNESCAPED_UNICODE);
}

// Settings: Claude models appear only with a saved key, the key survives an empty save.
check(array_keys(MG_AI_Redesign::get_analyzers()) === array('openai'), 'no Claude without key');
MG_AI_Redesign::save_settings(array('analyzer' => 'anthropic:claude-opus-5-5', 'anthropic_api_key' => 'sk-ant-test', 'quality' => 'high'));
check(MG_AI_Redesign::get_settings()['analyzer'] === 'anthropic:claude-opus-5-5', 'Claude analyzer saved');
MG_AI_Redesign::save_settings(array('analyzer' => 'anthropic:claude-haiku-5-5', 'anthropic_api_key' => '', 'quality' => 'bogus'));
$settings = MG_AI_Redesign::get_settings();
check($settings['anthropic_api_key'] === 'sk-ant-test', 'empty key field keeps the saved key');
check($settings['quality'] === 'medium', 'invalid quality falls back to medium');
check(count(MG_AI_Redesign::get_analyzers()) === 5, 'OpenAI + 4 Claude analyzers');
check($settings['edge_cleanup'] === true, 'edge cleanup on by default for redesigns');
MG_AI_Redesign::save_settings(array('edge_cleanup' => '0'));
check(MG_AI_Redesign::get_settings()['edge_cleanup'] === false, 'edge cleanup can be turned off');
MG_AI_Redesign::save_settings(array('quality' => 'low'));
check(MG_AI_Redesign::get_settings()['edge_cleanup'] === false, 'a save without the field keeps it');
MG_AI_Redesign::save_settings(array('edge_cleanup' => '1', 'quality' => 'medium'));
check($settings['alpha_binary'] === true && $settings['alpha_threshold'] === 50, 'binary alpha on at 50% by default');
MG_AI_Redesign::save_settings(array('alpha_binary' => '0', 'alpha_threshold' => '30'));
$s = MG_AI_Redesign::get_settings();
check($s['alpha_binary'] === false && $s['alpha_threshold'] === 30, 'binary alpha can be turned off, threshold saved');
MG_AI_Redesign::save_settings(array('quality' => 'medium'));
$s = MG_AI_Redesign::get_settings();
check($s['alpha_binary'] === false && $s['alpha_threshold'] === 30, 'a save without the fields keeps them');
MG_AI_Redesign::save_settings(array('alpha_binary' => '1', 'alpha_threshold' => '150'));
check(MG_AI_Redesign::get_settings()['alpha_threshold'] === 99, 'threshold clamped to 99%');
MG_AI_Redesign::save_settings(array('alpha_threshold' => 'abc'));
check(MG_AI_Redesign::get_settings()['alpha_threshold'] === 99, 'a non-number keeps the saved threshold');
MG_AI_Redesign::save_settings(array('alpha_threshold' => '50', 'quality' => 'medium'));
check(MG_AI_Redesign::get_settings()['resolution'] === 'economy', 'economical resolution by default');
MG_AI_Redesign::save_settings(array('resolution' => '2048'));
check(MG_AI_Redesign::get_settings()['resolution'] === 2048, 'a longest side is saved');
MG_AI_Redesign::save_settings(array('quality' => 'medium'));
check(MG_AI_Redesign::get_settings()['resolution'] === 2048, 'a save without the field keeps it');
MG_AI_Redesign::save_settings(array('resolution' => '2050'));
check(MG_AI_Redesign::get_settings()['resolution'] === 'economy', 'not a multiple of 16: economical');
MG_AI_Redesign::save_settings(array('resolution' => '4000'));
check(MG_AI_Redesign::get_settings()['resolution'] === 'economy', 'over 3840 px: economical');
MG_AI_Redesign::save_settings(array('resolution' => 'economy', 'quality' => 'medium'));
$s = MG_AI_Redesign::get_settings();
check($s['upscaler'] === 'cugan' && $s['palette'] === true, 'Real-CUGAN and the Design-flow palette by default');
MG_AI_Redesign::save_settings(array('upscaler' => 'esrgan', 'palette' => '0'));
$s = MG_AI_Redesign::get_settings();
check($s['upscaler'] === 'esrgan' && $s['palette'] === false, 'Real-ESRGAN can be chosen, the palette turned off');
MG_AI_Redesign::save_settings(array('upscaler' => 'bogus', 'quality' => 'medium'));
$s = MG_AI_Redesign::get_settings();
check($s['upscaler'] === 'esrgan' && $s['palette'] === false, 'an unknown upscaler or a save without the fields keeps them');
MG_AI_Redesign::save_settings(array('upscaler' => 'cugan', 'palette' => '1'));

// Every allowed image size at the design's aspect ratio (GPT Image 2 / 2.5 rules).
foreach (array(array(1000, 1000, 130, '816x816', '2880x2880'), array(1920, 1080, 172, '1088x608', '3840x2160'), array(3000, 1000, 112, '1440x480', '3840x1280')) as $case) {
    list($w, $h, $count, $first, $last) = $case;
    $sizes = MG_AI_Print_Generator::available_sizes($w, $h);
    $ok = true;
    foreach ($sizes as $z) {
        $ok = $ok && $z['width'] % 16 === 0 && $z['height'] % 16 === 0 && max($z['width'], $z['height']) <= 3840
            && $z['pixels'] >= 655360 && $z['pixels'] <= 8294400 && abs(($z['width'] / $z['height']) / ($w / $h) - 1) <= 0.01
            && $z['experimental'] === ($z['pixels'] > 3686400 || max($z['width'], $z['height']) > 2560);
    }
    $ends = $sizes[0]['width'] . 'x' . $sizes[0]['height'] . ' ' . end($sizes)['width'] . 'x' . end($sizes)['height'];
    check($ok && count($sizes) === $count && $ends === "$first $last", "all sizes valid at $w:$h ($ends, " . count($sizes) . ')');
}
check(MG_AI_Print_Generator::size_for_resolution(1000, 1000, 1024) === '1024x1024', 'a longest side gives that size');
check(MG_AI_Print_Generator::size_for_resolution(1000, 1000, 3840) === '2880x2880', 'square: capped at 8.3 MP');
check(MG_AI_Print_Generator::size_for_resolution(1920, 1080, 2560) === '2560x1440', '16:9 at 2560: the largest standard size');
check(MG_AI_Print_Generator::size_for_resolution(3000, 1000, 832) === '1440x480', 'below what the ratio allows: the smallest size');
check(MG_AI_Print_Generator::size_for_resolution(1000, 1000, 'economy') === MG_AI_Print_Generator::economical_size(1000, 1000), 'economy: the economical size');
throws(function () { MG_AI_Print_Generator::size_for_resolution(1000, 1000, '1000'); }, 'felbontás', 'invalid longest side rejected');
check(MG_AI_Print_Generator::is_experimental_size(2560, 1440) === false && MG_AI_Print_Generator::is_experimental_size(1936, 1936) === true, 'experimental above 2560x1440 pixels');

// Claude request shape and parsing.
$http_responses[] = array('code' => 200, 'body' => json_encode(array('stop_reason' => 'end_turn', 'content' => array(
    array('type' => 'thinking', 'thinking' => ''),
    array('type' => 'text', 'text' => analysis_json()),
))));
$analysis = MG_AI_Redesign::analyze('anthropic:claude-opus-5-5', 'QUJD', MG_AI_Redesign::analysis_request_text('Legjobb apa póló', '', ''));
list($url, $request) = $http_calls[0];
$body = json_decode($request['body'], true);
check($url === 'https://api.anthropic.com/v1/messages', 'Claude endpoint');
check($request['headers']['x-api-key'] === 'sk-ant-test' && $request['headers']['anthropic-version'] === '2023-06-01', 'Claude auth headers');
check($request['headers']['anthropic-beta'] === 'server-side-fallback-2026-07-01' && $body['fallbacks'] === 'default', 'Opus gets refusal fallbacks');
check($body['model'] === 'claude-opus-5-5' && $body['output_config']['effort'] === 'medium', 'Claude model and effort');
check($body['output_config']['format']['type'] === 'json_schema' && $body['output_config']['format']['schema']['additionalProperties'] === false, 'Claude structured output');
check(!isset($body['thinking']), 'no thinking config sent (adaptive by default)');
check($body['messages'][0]['content'][0]['source'] === array('type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'QUJD'), 'Claude image block');
check(strpos($body['system'], 'tervezési briefet') !== false && strpos($body['system'], 'signature_elements') !== false, 'analyzer fills a design brief');
check($body['output_config']['format']['schema']['properties']['signature_elements']['items']['additionalProperties'] === false, 'nested schema is strict too');
check($analysis['text_lines'] === array('Legjobb Apa', 'a világon'), 'analysis text lines parsed');

$http_calls = array();
$http_responses[] = array('code' => 200, 'body' => json_encode(array('stop_reason' => 'end_turn', 'content' => array(array('type' => 'text', 'text' => analysis_json())))));
MG_AI_Redesign::analyze('anthropic:claude-haiku-5-5', 'QUJD', 'x');
$body = json_decode($http_calls[0][1]['body'], true);
check(!isset($http_calls[0][1]['headers']['anthropic-beta']) && !isset($body['fallbacks']), 'Haiku has no server-side fallback');
check(array_keys(MG_AI_Redesign::get_intensities()) === array('strong', 'medium', 'subtle', 'full'), 'new style is the default, then finom, extra finom, full');
check(strpos(MG_AI_Redesign::analysis_instructions(), 'méretek, százalékok, pontos helyek') !== false, 'brief fields stay rough');

$http_responses[] = array('code' => 200, 'body' => json_encode(array('stop_reason' => 'refusal', 'content' => array())));
throws(function () { MG_AI_Redesign::analyze('anthropic:claude-opus-5-5', 'QUJD', 'x'); }, 'elutasította', 'refusal is reported');
$http_responses[] = array('code' => 401, 'body' => 'secret provider body');
throws(function () { MG_AI_Redesign::analyze('anthropic:claude-opus-5-5', 'QUJD', 'x'); }, 'HTTP 401', '401 is reported without the body');
throws(function () { MG_AI_Redesign::analyze('anthropic:claude-unknown', 'QUJD', 'x'); }, 'nem elérhető', 'unknown analyzer rejected');

// OpenAI request shape.
$options['mg_ai_seo_settings'] = array('api_key' => 'sk-openai');
$http_calls = array();
$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'completed', 'output_text' => analysis_json())));
MG_AI_Redesign::analyze('openai', 'QUJD', 'x');
list($url, $request) = $http_calls[0];
$body = json_decode($request['body'], true);
check($url === 'https://api.openai.com/v1/responses' && $request['headers']['Authorization'] === 'Bearer sk-openai', 'OpenAI endpoint and key');
check($body['text']['format']['type'] === 'json_schema' && $body['text']['format']['strict'] === true, 'OpenAI structured output');
check($body['input'][0]['content'][1]['image_url'] === 'data:image/jpeg;base64,QUJD', 'OpenAI image as data URL');
$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'incomplete')));
throws(function () { MG_AI_Redesign::analyze('openai', 'QUJD', 'x'); }, 'hiányos', 'incomplete OpenAI response reported');

// Parsing and the fixed image prompt rules.
check(MG_AI_Redesign::parse_analysis("```json\n" . analysis_json() . "\n```")['new_style'] === 'retro', 'fenced JSON tolerated');
throws(function () { MG_AI_Redesign::parse_analysis('{"text_lines":[],"topic":"","archetype":""}'); }, 'tervezési briefet', 'empty brief rejected');
check($analysis['line_sizes'] === array('dominant', 'small') && $analysis['tone'] === 'light' && count($analysis['signature_elements']) === 2, 'brief parsed');

// Levels decide what of the brief reaches the image model.
$fin = MG_AI_Redesign::build_image_prompt($analysis, true, 'medium');
$new = MG_AI_Redesign::build_image_prompt($analysis, true, 'strong');
$full = MG_AI_Redesign::build_image_prompt($analysis, true, 'full');
check(strpos($fin, 'Design an original T-shirt print: a bold typographic badge, about the best dad in the world.') === 0, 'finom: genre and topic first');
check(strpos($fin, 'Keep a similar overall structure: the title in an arc above, the subtitle below.') !== false, 'finom: rough layout kept');
check(strpos($fin, 'instead of laurel wreath around the title, use a ring of small stars; instead of teal ribbon banner, use a rounded label') !== false, 'finom: signature elements swapped');
check(strpos($fin, 'Text emphasis: "Legjobb Apa" is the dominant element; the other lines are small.') !== false, 'finom: text emphasis kept');
check(strpos($fin, 'design every detail yourself') !== false, 'finom: details new');
check(strpos($new, 'a bold typographic badge') !== false && strpos($new, 'Keep a similar overall structure') === false, 'new style: genre without layout');
check(strpos($new, 'Do not use: laurel wreath around the title; teal ribbon banner.') !== false && strpos($new, 'ring of small stars') === false, 'new style: signature elements forbidden, no replacements');
check(strpos($new, 'Text emphasis:') !== false && strpos($new, 'Be creative') !== false, 'new style: emphasis kept, creative');
check(strpos($full, 'Design an original T-shirt print about the best dad in the world.') === 0 && strpos($full, 'badge') === false && strpos($full, 'Text emphasis') === false, 'full: topic and mood only');
check(strpos($full, 'Do not use: laurel wreath') !== false, 'full: signature elements forbidden');
check(strpos($new, 'predominantly light-colored, so it works on dark garments') !== false, 'tone kept for the garment colors');
$hinted = MG_AI_Redesign::build_image_prompt(array_merge($analysis, array('style_hint' => 'retro 70s')), true, 'strong');
check(strpos($hinted, 'Style direction: retro 70s.') !== false, 'style hint passed on');
check(strpos(MG_AI_Redesign::build_image_prompt(array_merge($analysis, array('text_lines' => array('Más szöveg'))), true, 'strong'), 'Text emphasis') === false, 'emphasis dropped when the text changed');
$long = MG_AI_Redesign::build_image_prompt(array_merge($analysis, array('archetype' => str_repeat('very long genre words ', 20))), true, 'strong');
check(strlen(strtok($long, "\n")) < 200, 'over-long brief fields capped');
$prompt = MG_AI_Redesign::build_image_prompt($analysis, true);
check(strpos($prompt, 'exactly this text, line by line, and no other text: "Legjobb Apa" / "a világon"') !== false, 'exact text with accents enforced');
foreach (array('medium', 'strong', 'full') as $level) {
    check(strpos(MG_AI_Redesign::build_image_prompt($analysis, true, $level), 'solid black outline (contour) around it') !== false, "black outline required ($level)");
}
check(strpos(MG_AI_Redesign::analysis_instructions(), 'fekete körvonal nem számítanak') !== false, 'outline not counted as a design color');
check(strpos($prompt, 'Be creative') !== false && strpos($prompt, 'instead of illustrating it literally') !== false, 'image model asked to be creative');
check(strpos($prompt, 'fully transparent background') !== false && strpos($prompt, 'attached') === false, 'transparent, no reference wording');
$prompt = MG_AI_Redesign::build_image_prompt($analysis, false);
check(strpos($prompt, 'plain, solid background') !== false && strpos($prompt, 'Design an original T-shirt print') === 0, 'opaque, brief first');
// "Extra finom": layout, lettering and art style and every color kept; only the top two swaps.
$subtle_analysis = array_merge($analysis, array(
    'lettering_style' => 'bold rounded sans-serif, all caps', 'art_style' => 'flat vector badge shapes',
    'has_illustration' => true, 'color_count' => 4, 'colors' => array('white', 'yellow', 'teal', 'red'),
    'signature_elements' => array(
        array('element' => 'laurel wreath', 'replacement' => 'a ring of stars'),
        array('element' => 'teal ribbon banner', 'replacement' => 'a rounded label'),
        array('element' => 'small sparkles', 'replacement' => 'tiny hearts'),
    ),
));
$sub = MG_AI_Redesign::build_image_prompt($subtle_analysis, true, 'subtle');
check(strpos($sub, 'Keep the same layout: the title in an arc above, the subtitle below.') !== false, 'extra finom: same layout');
check(strpos($sub, 'Lettering style: bold rounded sans-serif, all caps (use a different typeface of the same kind).') !== false, 'extra finom: same lettering kind, other typeface');
check(strpos($sub, 'Art style: flat vector badge shapes.') !== false, 'extra finom: same art style');
check(strpos($sub, 'Keep these distinctive elements, but redraw each as your own creative variation of the same element (same kind and role, new details of your choice): laurel wreath; teal ribbon banner.') !== false,
    'extra finom: the two most distinctive elements kept as creative variations');
check(strpos($sub, 'ring of stars') === false && strpos($sub, 'rounded label') === false && strpos($sub, 'small sparkles') === false && strpos($sub, 'tiny hearts') === false,
    'extra finom: no prescribed replacement, third element not touched');
check(strpos($sub, 'Use only these four ink colors for the whole design (text and illustration): white, yellow, teal, red, plus the black outline.') !== false, 'extra finom: colors of an illustrated design kept');
check(strpos($sub, 'Stay close to this brief') !== false && strpos($sub, 'Be creative') === false && strpos($sub, 'design every detail yourself') === false, 'extra finom: stay close, no creative line');
check(strpos($sub, 'Text emphasis:') !== false && strpos($sub, 'solid black outline (contour)') !== false, 'extra finom keeps the emphasis and fixed rules');
check(strpos(MG_AI_Redesign::build_image_prompt($subtle_analysis, true, 'medium'), 'Lettering style:') === false, 'finom does not pass the lettering style');
check(MG_AI_Redesign::palette_rule($subtle_analysis, 'medium') === '', 'finom keeps illustrated designs free');
check(MG_AI_Redesign::palette_rule(array_merge($subtle_analysis, array('color_count' => 7)), 'subtle') === '', 'extra finom: no rule beyond six colors');
check(strpos(MG_AI_Redesign::build_image_prompt(array_merge($subtle_analysis, array('signature_elements' => array())), true, 'subtle'), 'creative variation of the same element') === false, 'extra finom without distinctive elements: only the typeface changes');
check(strpos($sub, 'Only the typeface and the elements marked for variation are yours to vary, creatively.') !== false, 'extra finom: closing line names what may vary');
check(strpos(MG_AI_Redesign::analysis_instructions(), 'a legfeltűnőbbel kezdve') !== false && strpos(MG_AI_Redesign::analysis_instructions(), 'lettering_style') !== false, 'brief asks for ordered elements and the styles');
$schema = MG_AI_Redesign::analysis_schema();
check(in_array('lettering_style', $schema['required'], true) && in_array('art_style', $schema['required'], true), 'schema requires the new fields');
check(MG_AI_Redesign::parse_analysis(json_encode(array('topic' => 'x', 'lettering_style' => 'script', 'art_style' => 'flat')))['art_style'] === 'flat', 'new fields parsed');
$free_subtle = MG_AI_Redesign::free_analysis_instructions('subtle');
check(strpos($free_subtle, 'legfeljebb 5 mondat') !== false && strpos($free_subtle, 'NE írd le az eredeti részleteit') === false
    && strpos($free_subtle, 'Hagyj teret') === false && strpos($free_subtle, 'Extra finom: maradjon nagyon közel') !== false, 'free extra finom may describe layout, style and colors');
check(strpos($free_subtle, 'saját kreatív variációjaként') !== false && strpos($free_subtle, 'ne írd elő') !== false && strpos($free_subtle, 'mire cserélődjön') === false, 'free extra finom: creative variations, not prescribed');
check(strpos(MG_AI_Redesign::free_analysis_instructions('medium'), 'NE írd le az eredeti részleteit') !== false, 'free finom unchanged');
$free_sub_prompt = MG_AI_Redesign::build_image_prompt(array_merge($subtle_analysis, array('image_prompt' => 'A badge.')), true, 'subtle', 'free');
check(strpos($free_sub_prompt, 'Stay close to this description') !== false && strpos($free_sub_prompt, 'Be creative') === false, 'free extra finom: stay close');

// Few-color text designs keep their palette: red and white must not become a rainbow.
$redWhite = array_merge($analysis, array('has_illustration' => false, 'color_count' => 2, 'colors' => array('Red', 'white')));
$prompt = MG_AI_Redesign::build_image_prompt($redWhite, true, 'medium');
check(strpos($prompt, 'Use only these two ink colors for the whole design (text and illustration): red, white, plus the black outline. No other colors') !== false, 'finom: same colors kept');
check(strpos($prompt, 'color palette') === false, 'creative line does not invite a new palette');
check(strpos(MG_AI_Redesign::build_image_prompt($analysis, true, 'medium'), 'and so is the color palette') !== false, 'finom: free palette when no color rule');
check(strpos(MG_AI_Redesign::build_image_prompt($analysis, true, 'strong'), 'lettering style, color palette and') !== false, 'new style: free palette when no color rule');
$prompt = MG_AI_Redesign::build_image_prompt($redWhite, true, 'strong');
check(strpos($prompt, 'Use exactly two solid ink colors') !== false && strpos($prompt, 'red, white') === false, 'new style: same number of colors, new colors');
check(strpos($prompt, 'colors (within the color limit below)') !== false, 'new style: new colors within the limit');
check(MG_AI_Redesign::palette_rule($redWhite, 'full') === '', 'full redesign: colors free');
check(MG_AI_Redesign::palette_rule(array_merge($redWhite, array('has_illustration' => true)), 'medium') === '', 'figure or pattern: colors free');
check(MG_AI_Redesign::palette_rule(array_merge($redWhite, array('color_count' => 5)), 'medium') === '', 'many colors: no limit');
check(strpos(MG_AI_Redesign::palette_rule(array_merge($redWhite, array('color_count' => 1, 'colors' => array('black'))), 'medium'), 'Use only this ink color for the whole design (text and illustration): black, plus the black outline.') !== false, 'one-color design');
check(strpos(MG_AI_Redesign::palette_rule(array_merge($redWhite, array('colors' => array('red'))), 'medium'), 'Use exactly two solid ink colors') !== false, 'missing color names: count only');
check(MG_AI_Redesign::parse_analysis(json_encode(array('topic' => 'x', 'has_illustration' => false, 'color_count' => 2, 'colors' => array('red', 'white'))))['colors'] === array('red', 'white'), 'colors parsed');
check(strpos(MG_AI_Redesign::analysis_request_text('Név', "Sor 1\nSor 2", 'retro'), '"Sor 1\nSor 2"') !== false, 'user text passed as quoted data');

// Every level: the image model gets the prompt only, never the original PNG (cost).
function make_png($w, $h) {
    $chunk = function ($t, $d) { return pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d)); };
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\x00" . str_repeat("\x00\x00\x00\x00", $w), $h))) . $chunk('IEND', '');
}
$source = $test_dir . '/design.png';
file_put_contents($source, make_png(1000, 1000));
$http_calls = array();
$http_responses[] = new WP_Error('cURL error 28: timeout');
try { MG_AI_Print_Generator::redesign_image($source, 'PROMPT', str_repeat('a', 64), 'high'); } catch (RuntimeException $e) {}
list($url, $request) = $http_calls[0];
$body = json_decode($request['body'], true);
check($url === 'https://api.openai.com/v1/images/generations', 'generations endpoint, not edits');
check($body['prompt'] === 'PROMPT' && $body['quality'] === 'high' && $body['background'] === 'transparent' && !isset($body['image']) && strpos($request['body'], 'PNG') === false, 'prompt only: no image bytes sent');
throws(function () use ($source) { MG_AI_Print_Generator::redesign_image($source, 'P', str_repeat('c', 64), 'ultra', false); }, 'minőség', 'invalid quality rejected');
check($body['size'] === '816x816' && $request['timeout'] === 180, 'economical size by default');
foreach (array(array(1024, '1024x1024', 180), array(2880, '2880x2880', 300)) as $case) {
    $http_calls = array();
    $http_responses[] = new WP_Error('cURL error 28: timeout');
    try { MG_AI_Print_Generator::redesign_image($source, 'P', str_repeat('d', 64), 'low', $case[0]); } catch (RuntimeException $e) { $last_error = $e->getMessage(); }
    $sent = json_decode($http_calls[0][1]['body'], true);
    check($sent['size'] === $case[1] && $http_calls[0][1]['timeout'] === $case[2], "longest side {$case[0]}: {$sent['size']}, {$http_calls[0][1]['timeout']} s");
}
check(strpos($last_error, '300 másodperces') !== false, 'the timeout message names the longer timeout');
throws(function () use ($source) { MG_AI_Print_Generator::redesign_image($source, 'P', str_repeat('e', 64), 'low', 1000); }, 'felbontás', 'invalid resolution rejected');

// The AI upscale follows the size: 3×, 2× when 3× would pass 5120 px, none above 2560 px.
check(MG_AI_Redesign::upscale_factor(816, 816) === 3 && MG_AI_Redesign::upscale_factor(1706, 900) === 3, 'up to 1706 px: 3×');
check(MG_AI_Redesign::upscale_factor(1707, 900) === 2 && MG_AI_Redesign::upscale_factor(2560, 1440) === 2, 'up to 2560 px: 2×');
check(MG_AI_Redesign::upscale_factor(2576, 1440) === 1 && MG_AI_Redesign::upscale_factor(0, 0) === 1, 'larger: none');
// Real-ESRGAN: 4×, 2× when 4× would pass 5120 px.
MG_AI_Redesign::save_settings(array('upscaler' => 'esrgan'));
check(MG_AI_Redesign::upscale_factor(912, 736) === 4 && MG_AI_Redesign::upscale_factor(1280, 900) === 4, 'Real-ESRGAN up to 1280 px: 4×');
check(MG_AI_Redesign::upscale_factor(1281, 900) === 2 && MG_AI_Redesign::upscale_factor(2576, 1440) === 1, 'Real-ESRGAN above: 2×, then none');
// waifu2x (Design-flow's default): 4× like Real-ESRGAN.
MG_AI_Redesign::save_settings(array('upscaler' => 'waifu2x'));
check(MG_AI_Redesign::get_settings()['upscaler'] === 'waifu2x' && MG_AI_Redesign::upscale_factor(912, 736) === 4 && MG_AI_Redesign::upscale_factor(2000, 900) === 2, 'waifu2x: 4×, 2× above 1280 px');
MG_AI_Redesign::save_settings(array('upscaler' => 'cugan'));


// Approval: queues the replacement, deletes the other candidates, keeps history.
file_put_contents(get_attached_file(11), make_png(10, 10));
$meta[5]['_mg_last_design_path'] = $source;
$meta[5][MG_AI_Redesign::META_CANDIDATES] = array(
    array('attachment_id' => 12, 'analyzer' => 'openai', 'intensity' => 'strong'),
    array('attachment_id' => 11, 'analyzer' => 'anthropic:claude-opus-5-5', 'intensity' => 'strong'),
);
throws(function () { MG_AI_Redesign::apply(5, 99); }, 'nem található', 'unknown candidate rejected');
check(empty($enqueued), 'nothing queued for an unknown candidate');
$result = MG_AI_Redesign::apply(5, 11);
check($enqueued === array(array(5, get_attached_file(11), 'ai_redesign')), 'replacement queued with the AI PNG');
check($meta[5][MG_AI_Redesign::META_HISTORY][0]['palette'] === 0, 'the server never quantizes: an older candidate is approved as it is');
check($deleted === array(12) && !isset($meta[5][MG_AI_Redesign::META_CANDIDATES]), 'other candidates deleted');
check($meta[5][MG_AI_Redesign::META_HISTORY][0]['previous_path'] === $source && $result['job_id'] === 'job1', 'previous design kept in history');

// Browser Real-CUGAN result: only an exact 3× PNG (4× from a pre-2.43.9 page) of a not yet upscaled candidate.
$meta[6][MG_AI_Redesign::META_CANDIDATES] = array(
    array('attachment_id' => 21, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'strong'),
);
file_put_contents(get_attached_file(21), make_png(10, 10));
$wrong = $test_dir . '/wrong.png';
file_put_contents($wrong, make_png(25, 25));
throws(function () use ($wrong) { MG_AI_Redesign::store_upscaled(6, 21, $wrong); }, 'háromszorosa', 'non-3× upload rejected');
$bad = $test_dir . '/bad.png';
file_put_contents($bad, 'not a png');
throws(function () use ($bad) { MG_AI_Redesign::store_upscaled(6, 21, $bad); }, 'PNG', 'non-PNG upload rejected');
throws(function () use ($wrong) { MG_AI_Redesign::store_upscaled(6, 99, $wrong); }, 'nem található', 'unknown candidate rejected for upload');
$good = $test_dir . '/good.png';
file_put_contents($good, make_png(30, 30));
$palette_sizes['att-21.png'] = 255;
$stored = MG_AI_Redesign::store_upscaled(6, 21, $good);
check(getimagesize(get_attached_file(21))[0] === 30 && $stored['upscale'] === 'ai3x' && $stored['width'] === 30, '3× PNG replaces the candidate file');
check($stored['palette'] === 255, 'the browser\'s palette PNG: its colors recorded');
check(end($metadata_refreshed) === 21, 'attachment metadata refreshed');
$v = filemtime(get_attached_file(21));
check($stored['preview'] === "https://example.test/att-21-medium.png?v=$v" && $stored['full'] === "https://example.test/att-21.png?v=$v", 'preview and full URLs versioned by the file time');
throws(function () use ($good) { MG_AI_Redesign::store_upscaled(6, 21, $good); }, 'már fel van skálázva', 'second upload rejected');
$meta[6][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 22, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'strong');
file_put_contents(get_attached_file(22), make_png(10, 10));
$old_page = $test_dir . '/old4x.png';
file_put_contents($old_page, make_png(40, 40));
check(MG_AI_Redesign::store_upscaled(6, 22, $old_page)['upscale'] === 'ai4x', 'a 4× upload from a page opened before 2.43.9 is still accepted');
$meta[6][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 23, 'upscale' => 'none', 'width' => 2000, 'height' => 1000, 'analyzer' => 'openai', 'intensity' => 'strong');
file_put_contents(get_attached_file(23), make_png(2000, 1000));
$two = $test_dir . '/two.png';
file_put_contents($two, make_png(4000, 2000));
check(MG_AI_Redesign::store_upscaled(6, 23, $two)['upscale'] === 'ai2x', 'a 2× upload of a large design is accepted');

// Approval of a candidate the browser could not upscale: 3× Lanczos fallback.
$meta[6][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 22, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'strong');
file_put_contents(get_attached_file(22), make_png(10, 10));
$enqueued = array();
$applied = MG_AI_Redesign::apply(6, 22);
check(getimagesize(get_attached_file(22))[0] === 24, 'not upscaled candidate gets prepared, then 3× on approval');
check(end($meta[6][MG_AI_Redesign::META_HISTORY])['palette'] === 0 && $applied['candidate']['width'] === 24, 'the 3× fallback stays RGBA, its new size recorded');
check($applied['candidate']['attachment_id'] === 22 && $applied['candidate']['upscale'] === 'lanczos3x'
    && strpos($applied['candidate']['full'], 'att-22.png?v=') !== false, 'approval returns the finished file for the page');
check(end($meta[6][MG_AI_Redesign::META_HISTORY])['upscale'] === 'lanczos3x' && $enqueued[0][1] === get_attached_file(22), 'history records the fallback');

// Upscale input is prepared right before the upscale (the preview stays the GPT image), once.
$meta[9][MG_AI_Redesign::META_CANDIDATES] = array(
    array('attachment_id' => 41, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'subtle'),
);
file_put_contents(get_attached_file(41), make_png(10, 10));
$prepare_calls = array();
$prepared = MG_AI_Redesign::prepare_candidate(9, 41);
check($prepare_calls === array(array('att-41.png', true, 128)), 'prepared with the edge cleanup and 50% binary alpha settings');
check($prepared['prepared'] === true && $prepared['width'] === 8 && $prepared['height'] === 6 && strpos($prepared['full'], 'att-41.png?v=') !== false, 'prepared candidate: new size, versioned URL');
check($prepared['upscale_factor'] === 3 && $prepared['upscale_engine'] === 'cugan' && $prepared['palette_finish'] === true, 'the page is told the model and finish with the factor');
MG_AI_Redesign::save_settings(array('upscaler' => 'esrgan', 'palette' => '0'));
$fresh = MG_AI_Redesign::prepare_candidate(9, 41);
check($fresh['upscale_factor'] === 4 && $fresh['upscale_engine'] === 'esrgan' && $fresh['palette_finish'] === false, 'settings changed after the page opened: the current ones go with the candidate');
MG_AI_Redesign::save_settings(array('upscaler' => 'cugan', 'palette' => '1'));
MG_AI_Redesign::prepare_candidate(9, 41);
check(count($prepare_calls) === 1, 'prepared only once');
file_put_contents($test_dir . '/up41.png', make_png(24, 18));
check(MG_AI_Redesign::store_upscaled(9, 41, $test_dir . '/up41.png')['upscale'] === 'ai3x', 'the 3× check uses the prepared size');
MG_AI_Redesign::save_settings(array('edge_cleanup' => '0'));
$meta[9][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 42, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'subtle');
file_put_contents(get_attached_file(42), make_png(10, 10));
MG_AI_Redesign::prepare_candidate(9, 42);
check(end($prepare_calls) === array('att-42.png', false, 128), 'edge cleanup off: still binary alpha and crop');
MG_AI_Redesign::save_settings(array('edge_cleanup' => '1', 'alpha_threshold' => '30'));
$meta[9][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 44, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'subtle');
file_put_contents(get_attached_file(44), make_png(10, 10));
MG_AI_Redesign::prepare_candidate(9, 44);
check(end($prepare_calls) === array('att-44.png', true, 77), '30% threshold passed as 77 of 255');
MG_AI_Redesign::save_settings(array('alpha_binary' => '0'));
$meta[9][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 45, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'subtle');
file_put_contents(get_attached_file(45), make_png(10, 10));
MG_AI_Redesign::prepare_candidate(9, 45);
check(end($prepare_calls) === array('att-45.png', true, 0), 'binary alpha off: threshold 0, partial alpha kept');
MG_AI_Redesign::save_settings(array('alpha_binary' => '1', 'alpha_threshold' => '50'));
throws(function () { MG_AI_Redesign::prepare_candidate(9, 99); }, 'nem található', 'unknown candidate not prepared');
// The 3× fallback prepares an unprepared candidate first.
$meta[9][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => 43, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'subtle'));
file_put_contents(get_attached_file(43), make_png(10, 10));
$prepare_calls = array();
MG_AI_Redesign::apply(9, 43);
check($prepare_calls === array(array('att-43.png', true, 128)) && getimagesize(get_attached_file(43))[0] === 24, 'fallback: prepared, then 3× (8 → 24 px)');
// A large prepared design: the fallback enlarges 2× or, above 2560 px, not at all.
foreach (array(array(44, 2000, 'lanczos2x', 4000), array(45, 3000, 'native', 3000)) as $case) {
    list($att, $width, $label, $final) = $case;
    $meta[9][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => $att, 'upscale' => 'none', 'prepared' => true, 'width' => $width, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'subtle'));
    file_put_contents(get_attached_file($att), make_png($width, 10));
    $result = MG_AI_Redesign::apply(9, $att);
    check($result['candidate']['upscale'] === $label && getimagesize(get_attached_file($att))[0] === $final, "fallback for $width px: $label");
}

// Palette off: the browser sends its RGBA PNG and it stays as it is.
MG_AI_Redesign::save_settings(array('palette' => '0'));
$meta[11][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => 61, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'prepared' => true, 'analyzer' => 'openai', 'intensity' => 'subtle'));
file_put_contents(get_attached_file(61), make_png(10, 10));
file_put_contents($test_dir . '/up61.png', make_png(30, 30));
$stored = MG_AI_Redesign::store_upscaled(11, 61, $test_dir . '/up61.png');
check($stored['upscale'] === 'ai3x' && $stored['upscale_model'] === 'cugan' && $stored['palette'] === 0 && $stored['width'] === 30, 'palette off: the upload stays RGBA');
$enqueued = array();
$applied = MG_AI_Redesign::apply(11, 61);
check($applied['candidate']['palette'] === 0 && $enqueued[0][1] === get_attached_file(61), 'palette off: approval queues it as it is');
check(end($meta[11][MG_AI_Redesign::META_HISTORY])['upscale_model'] === 'cugan', 'history records the model');
// The sharp-enlargement fallback with the Real-ESRGAN setting: 4×.
MG_AI_Redesign::save_settings(array('upscaler' => 'esrgan'));
$meta[11][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => 62, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'prepared' => true, 'analyzer' => 'openai', 'intensity' => 'subtle'));
file_put_contents(get_attached_file(62), make_png(10, 10));
$applied = MG_AI_Redesign::apply(11, 62);
check($applied['candidate']['upscale'] === 'lanczos4x' && $applied['candidate']['width'] === 40, 'Real-ESRGAN setting: 4× fallback, size recorded');
// A Real-ESRGAN upload: 4× of the candidate, labeled with its model.
$meta[11][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => 63, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'prepared' => true, 'analyzer' => 'openai', 'intensity' => 'subtle'));
file_put_contents(get_attached_file(63), make_png(10, 10));
file_put_contents($test_dir . '/up63.png', make_png(40, 40));
$stored = MG_AI_Redesign::store_upscaled(11, 63, $test_dir . '/up63.png');
check($stored['upscale'] === 'ai4x' && $stored['upscale_model'] === 'esrgan', 'Real-ESRGAN 4× upload accepted and labeled');
MG_AI_Redesign::save_settings(array('upscaler' => 'waifu2x'));
$meta[11][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => 64, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'prepared' => true, 'analyzer' => 'openai', 'intensity' => 'subtle'));
file_put_contents(get_attached_file(64), make_png(10, 10));
check(MG_AI_Redesign::prepare_candidate(11, 64)['upscale_engine'] === 'waifu2x', 'the page is told to run waifu2x');
file_put_contents($test_dir . '/up64.png', make_png(40, 40));
check(MG_AI_Redesign::store_upscaled(11, 64, $test_dir . '/up64.png')['upscale_model'] === 'waifu2x', 'a waifu2x 4× upload is labeled waifu2x');
MG_AI_Redesign::save_settings(array('upscaler' => 'cugan'));
$meta[11][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => 65, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'prepared' => true, 'analyzer' => 'openai', 'intensity' => 'subtle'));
file_put_contents(get_attached_file(65), make_png(10, 10));
file_put_contents($test_dir . '/up65.png', make_png(40, 40));
check(MG_AI_Redesign::store_upscaled(11, 65, $test_dir . '/up65.png')['upscale_model'] === 'esrgan', 'a 4× upload under the Real-CUGAN setting came from Real-ESRGAN');
MG_AI_Redesign::save_settings(array('upscaler' => 'esrgan'));
MG_AI_Redesign::save_settings(array('upscaler' => 'cugan', 'palette' => '1'));

// ImageMagick 7.1.2 Q16-HDRI blackened the designs: a preparation that loses
// the colors is dropped, the GPT image goes to the upscale uncropped.
$color_loss = array('prepare');
$meta[10][MG_AI_Redesign::META_CANDIDATES] = array(array('attachment_id' => 51, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'subtle'));
file_put_contents(get_attached_file(51), make_png(10, 10));
$prepared = MG_AI_Redesign::prepare_candidate(10, 51);
check($prepared['prepared'] === true && $prepared['width'] === 10 && getimagesize(get_attached_file(51))[0] === 10, 'prepare color loss: the GPT image goes to the upscale as it is');
$color_loss = array();

// Discarding candidates: one, then all; the PNGs leave the media library.
$deleted = array();
$meta[8][MG_AI_Redesign::META_CANDIDATES] = array(
    array('attachment_id' => 31), array('attachment_id' => 32), array('attachment_id' => 33),
);
check(MG_AI_Redesign::discard(8, 32) === 1 && $deleted === array(32), 'one candidate deleted');
check(array_column($meta[8][MG_AI_Redesign::META_CANDIDATES], 'attachment_id') === array(31, 33), 'others kept in order');
throws(function () { MG_AI_Redesign::discard(8, 32); }, 'nem található', 'already deleted candidate rejected');
check(MG_AI_Redesign::discard(8) === 2 && $deleted === array(32, 31, 33) && !isset($meta[8][MG_AI_Redesign::META_CANDIDATES]), 'all candidates deleted');
throws(function () { MG_AI_Redesign::discard(8); }, 'nem található', 'nothing left to delete');

// "Szabad leírás" mode: the analyzer writes the short prompt itself, as before 2.42.8.
check(array_keys(MG_AI_Redesign::get_prompt_modes())[0] === 'brief', 'brief is the default prompt mode');
$free_json = json_encode(array('text_lines' => array('Legjobb Apa'), 'original_style' => 'x', 'new_style' => 'y', 'has_illustration' => false,
    'color_count' => 2, 'colors' => array('red', 'white'), 'image_prompt' => 'A playful typographic badge about the best dad.'));
$http_calls = array();
$http_responses[] = array('code' => 200, 'body' => json_encode(array('stop_reason' => 'end_turn', 'content' => array(array('type' => 'text', 'text' => $free_json)))));
$free = MG_AI_Redesign::analyze('anthropic:claude-opus-5-5', 'QUJD', 'x', 'free', 'medium');
$body = json_decode($http_calls[0][1]['body'], true);
check(isset($body['output_config']['format']['schema']['properties']['image_prompt']) && !isset($body['output_config']['format']['schema']['properties']['signature_elements']), 'free mode sends its own schema');
check(strpos($body['system'], 'RÖVID') !== false && strpos($body['system'], 'Finom átdolgozás: a promptban a fő motívum') !== false, 'free mode instructions with the level');
$http_calls = array();
$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'completed', 'output_text' => $free_json)));
MG_AI_Redesign::analyze('openai', 'QUJD', 'x', 'free', 'full');
$body = json_decode($http_calls[0][1]['body'], true);
check(isset($body['text']['format']['schema']['properties']['image_prompt']) && strpos($body['instructions'], 'Teljesen új: a promptban csak a téma') !== false, 'free mode on OpenAI too');
$prompt = MG_AI_Redesign::build_image_prompt($free, true, 'medium', 'free');
check(strpos($prompt, 'A playful typographic badge about the best dad.') === 0 && strpos($prompt, 'interpret the description freely') !== false, 'free mode: analyzer prompt first, creative line');
check(strpos($prompt, 'red, white, plus the black outline') !== false && strpos($prompt, 'solid black outline (contour)') !== false && strpos($prompt, '"Legjobb Apa"') !== false, 'free mode keeps the fixed rules');
check(strpos($prompt, 'Design an original T-shirt print') === false, 'free mode has no brief');
throws(function () { MG_AI_Redesign::parse_analysis('{"text_lines":[],"image_prompt":""}', 'free'); }, 'képpromptot', 'free mode: empty prompt rejected');
throws(function () use ($free_json) { MG_AI_Redesign::parse_analysis($free_json); }, 'tervezési briefet', 'a free reply is not a brief');

// "Részletes leírás" mode: the admin's own analyzer prompt, editable in the settings.
check(array_keys(MG_AI_Redesign::get_prompt_modes()) === array('brief', 'free', 'describe'), 'three prompt modes');
check(MG_AI_Redesign::get_settings()['describe_prompt'] === MG_AI_Redesign::default_describe_prompt(), 'default describe prompt');
check(strpos(MG_AI_Redesign::default_describe_prompt(), 'TEXT Hungarian: "..."') !== false && strpos(MG_AI_Redesign::default_describe_prompt(), 'webcím a képen nem lehet') !== false, 'default keeps the admin wording');
MG_AI_Redesign::save_settings(array('describe_prompt' => 'My own analyzer prompt.', 'analyzer' => 'openai'));
check(MG_AI_Redesign::get_settings()['describe_prompt'] === 'My own analyzer prompt.', 'edited prompt saved');
check(MG_AI_Redesign::get_settings()['anthropic_api_key'] === 'sk-ant-test', 'saving the prompt keeps the Claude key');
$describe_json = json_encode(array('text_lines' => array('60', 'Szép szerény'), 'image_prompt' => 'Bold typographic print, TEXT Hungarian: "60" centered above, TEXT Hungarian: "Szép szerény" below.'));
$http_calls = array();
$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'completed', 'output_text' => $describe_json)));
$described = MG_AI_Redesign::analyze('openai', 'QUJD', 'x', 'describe', 'medium');
$body = json_decode($http_calls[0][1]['body'], true);
check(strpos($body['instructions'], 'My own analyzer prompt.') === 0 && strpos($body['instructions'], 'Return JSON with two fields') !== false, 'saved prompt + JSON contract sent');
check(array_keys($body['text']['format']['schema']['properties']) === array('text_lines', 'image_prompt'), 'describe schema');
$prompt = MG_AI_Redesign::build_image_prompt($described, true, 'medium', 'describe');
check(strpos($prompt, 'Bold typographic print, TEXT Hungarian') === 0, 'describe: analyzer description first');
check(strpos($prompt, 'no website address or URL anywhere in the image (e.g. no www.lolmarkt.hu)') !== false && strpos($prompt, 'none clipped or cut off by the edges') !== false && strpos($prompt, 'no chromatic aberration') !== false, 'describe: fixed image rules added');
check(strpos($prompt, 'exactly this text and no other text: "60" / "Szép szerény"') !== false && strpos($prompt, 'line by line') === false, 'describe: exact text without line-break wording');
check(strpos($prompt, 'Be creative') === false && strpos($prompt, 'ink color') === false && strpos($prompt, 'Design an original') === false, 'describe: no creative line, palette or brief');
MG_AI_Redesign::save_settings(array('describe_prompt' => 'My own analyzer prompt.', 'describe_prompt_reset' => '1'));
check(MG_AI_Redesign::get_settings()['describe_prompt'] === MG_AI_Redesign::default_describe_prompt(), 'reset to the default prompt');
MG_AI_Redesign::save_settings(array('describe_prompt' => '   '));
check(MG_AI_Redesign::get_settings()['describe_prompt'] === MG_AI_Redesign::default_describe_prompt(), 'empty prompt falls back to the default');

// Run state: kept in an option, a lost PHP worker is detected and its lock released.
$state_of = new ReflectionMethod('MG_AI_Redesign', 'current_state');
$state_of->setAccessible(true);
$stage = new ReflectionMethod('MG_AI_Redesign', 'set_stage');
$stage->setAccessible(true);
$skey = MG_AI_Redesign::STATE_PREFIX . 50;
$lkey = MG_AI_Redesign::LOCK_PREFIX . 50;
check($state_of->invoke(null, 50) === array('status' => 'none'), 'no run: none');
$options[$lkey] = time() - 2;
$st = $state_of->invoke(null, 50);
check($st['status'] === 'running' && $st['started'] === $options[$lkey], 'lock without state yet: running');
$options[$skey] = array('status' => 'running', 'stage' => 'prepare', 'started' => time() - 100, 'stage_at' => time() - 100, 'updated' => time() - 100);
$stage->invoke(null, 50, 'image');
$st = $state_of->invoke(null, 50);
check($st['status'] === 'running' && $st['stage'] === 'image' && $st['stage_at'] >= time() - 1, 'stage change stored with its time');
$options[$skey]['stage_at'] = time() - 200;
check($state_of->invoke(null, 50)['status'] === 'running', 'a long API call (200 s) is still running');
$options[$skey]['stage_at'] = time() - 320;
check($state_of->invoke(null, 50)['status'] === 'running', 'image stage: a large image may take 300 s, still running at 320 s');
$options[$skey]['stage_at'] = time() - 440;
$st = $state_of->invoke(null, 50);
check($st['status'] === 'error' && strpos($st['message'], 'képgenerálás, 7 perce') !== false, 'image stage: no progress for 7+ min, the worker is gone: ' . $st['message']);
check(!isset($options[$lkey]) && $options[$skey]['status'] === 'error', 'its lock released, the error stored');
$options[$skey] = array('status' => 'running', 'stage' => 'analyze', 'started' => time() - 330, 'stage_at' => time() - 320, 'updated' => time() - 10);
check(strpos($state_of->invoke(null, 50)['message'] ?? '', 'elemzés, 5 perce') !== false, 'analysis stage: no progress for 5+ min, the worker is gone');
$options[$skey] = array('status' => 'running', 'stage' => 'analyze', 'started' => time() - 700, 'stage_at' => time() - 10, 'updated' => time() - 10);
check(strpos($state_of->invoke(null, 50)['message'], '10 percen belül') !== false, 'whole run over 10 minutes: error');
$options[$skey] = array('status' => 'done', 'candidate' => array('attachment_id' => 5), 'updated' => time() - 30);
check($state_of->invoke(null, 50)['candidate']['attachment_id'] === 5, 'a finished run keeps its result');
$options[$skey]['updated'] = time() - 4000;
check($state_of->invoke(null, 50) === array('status' => 'none'), 'an hour-old result is forgotten');

array_map('unlink', glob($test_dir . '/*'));
rmdir($test_dir);
echo "AI redesign: $passed checks passed. HTTP and Imagick mocked, no API calls.\n";
