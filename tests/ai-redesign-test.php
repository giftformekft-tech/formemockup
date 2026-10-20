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

// The real palette encoder runs on real Imagick in tests/palette-png-test.php.
$palette_calls = array();
class MG_Image_Utils {
    public static function to_palette_png($path, $colors) {
        $GLOBALS['palette_calls'][] = array(basename($path), $colors);
        $info = getimagesize($path);
        return array('width' => $info[0], 'height' => $info[1], 'colors' => 42);
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
check($palette_calls === array(array('att-11.png', 255)), 'an older candidate gets the palette finish on approval');
check($deleted === array(12) && !isset($meta[5][MG_AI_Redesign::META_CANDIDATES]), 'other candidates deleted');
check($meta[5][MG_AI_Redesign::META_HISTORY][0]['previous_path'] === $source && $result['job_id'] === 'job1', 'previous design kept in history');

// Browser Real-ESRGAN result: only an exact 4× PNG of a not yet upscaled candidate.
$meta[6][MG_AI_Redesign::META_CANDIDATES] = array(
    array('attachment_id' => 21, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'strong'),
);
file_put_contents(get_attached_file(21), make_png(10, 10));
$wrong = $test_dir . '/wrong.png';
file_put_contents($wrong, make_png(30, 30));
throws(function () use ($wrong) { MG_AI_Redesign::store_upscaled(6, 21, $wrong); }, 'négyszerese', 'non-4× upload rejected');
$bad = $test_dir . '/bad.png';
file_put_contents($bad, 'not a png');
throws(function () use ($bad) { MG_AI_Redesign::store_upscaled(6, 21, $bad); }, 'PNG', 'non-PNG upload rejected');
throws(function () use ($wrong) { MG_AI_Redesign::store_upscaled(6, 99, $wrong); }, 'nem található', 'unknown candidate rejected for upload');
$good = $test_dir . '/good.png';
file_put_contents($good, make_png(40, 40));
$stored = MG_AI_Redesign::store_upscaled(6, 21, $good);
check(getimagesize(get_attached_file(21))[0] === 40 && $stored['upscale'] === 'ai4x' && $stored['width'] === 40, '4× PNG replaces the candidate file');
check(end($palette_calls) === array('att-21.png', 255) && $stored['palette'] === 42, 'upscaled PNG finished as a 255-color palette PNG');
check(end($metadata_refreshed) === 21, 'attachment metadata refreshed');
$v = filemtime(get_attached_file(21));
check($stored['preview'] === "https://example.test/att-21-medium.png?v=$v" && $stored['full'] === "https://example.test/att-21.png?v=$v", 'preview and full URLs versioned by the file time');
throws(function () use ($good) { MG_AI_Redesign::store_upscaled(6, 21, $good); }, 'már fel van skálázva', 'second upload rejected');

// Approval of a candidate the browser could not upscale: 3× Lanczos fallback.
$meta[6][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 22, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'strong');
file_put_contents(get_attached_file(22), make_png(10, 10));
$enqueued = array();
MG_AI_Redesign::apply(6, 22);
check(getimagesize(get_attached_file(22))[0] === 30, 'not upscaled candidate gets 3× on approval');
check(end($palette_calls) === array('att-22.png', 255) && end($meta[6][MG_AI_Redesign::META_HISTORY])['palette'] === 42, 'the 3× fallback gets the palette finish too');
check(end($meta[6][MG_AI_Redesign::META_HISTORY])['upscale'] === 'lanczos3x' && $enqueued[0][1] === get_attached_file(22), 'history records the fallback');

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

array_map('unlink', glob($test_dir . '/*'));
rmdir($test_dir);
echo "AI redesign: $passed checks passed. HTTP and Imagick mocked, no API calls.\n";
