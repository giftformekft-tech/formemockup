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
class MG_Design_Replace {
    public static function enqueue_replacement($product_id, $path, $trigger) {
        $GLOBALS['enqueued'][] = array($product_id, $path, $trigger);
        return array('job_id' => 'job1', 'message' => 'A csere sorba állítva (2 terméktípus).');
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
    return json_encode(array('text_lines' => array('Legjobb Apa', 'a világon'), 'original_style' => 'kézírás', 'new_style' => 'retro', 'image_prompt' => 'Bold retro badge with "Legjobb Apa" and "a világon".'), JSON_UNESCAPED_UNICODE);
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

// Claude request shape and parsing.
$http_responses[] = array('code' => 200, 'body' => json_encode(array('stop_reason' => 'end_turn', 'content' => array(
    array('type' => 'thinking', 'thinking' => ''),
    array('type' => 'text', 'text' => analysis_json()),
))));
$analysis = MG_AI_Redesign::analyze('anthropic:claude-opus-5-5', 'QUJD', 'strong', MG_AI_Redesign::analysis_request_text('Legjobb apa póló', '', ''));
list($url, $request) = $http_calls[0];
$body = json_decode($request['body'], true);
check($url === 'https://api.anthropic.com/v1/messages', 'Claude endpoint');
check($request['headers']['x-api-key'] === 'sk-ant-test' && $request['headers']['anthropic-version'] === '2023-06-01', 'Claude auth headers');
check($request['headers']['anthropic-beta'] === 'server-side-fallback-2026-07-01' && $body['fallbacks'] === 'default', 'Opus gets refusal fallbacks');
check($body['model'] === 'claude-opus-5-5' && $body['output_config']['effort'] === 'medium', 'Claude model and effort');
check($body['output_config']['format']['type'] === 'json_schema' && $body['output_config']['format']['schema']['additionalProperties'] === false, 'Claude structured output');
check(!isset($body['thinking']), 'no thinking config sent (adaptive by default)');
check($body['messages'][0]['content'][0]['source'] === array('type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'QUJD'), 'Claude image block');
check(strpos($body['system'], 'ugyanannak a mintának új változata') !== false && strpos($body['system'], 'mérsékelten') !== false && strpos($body['system'], '(4) a színek') !== false, 'new style: a moderate new version, four aspects change');
check($analysis['text_lines'] === array('Legjobb Apa', 'a világon'), 'analysis text lines parsed');

$http_calls = array();
$http_responses[] = array('code' => 200, 'body' => json_encode(array('stop_reason' => 'end_turn', 'content' => array(array('type' => 'text', 'text' => analysis_json())))));
MG_AI_Redesign::analyze('anthropic:claude-haiku-5-5', 'QUJD', 'medium', 'x');
$body = json_decode($http_calls[0][1]['body'], true);
check(!isset($http_calls[0][1]['headers']['anthropic-beta']) && !isset($body['fallbacks']), 'Haiku has no server-side fallback');
check(strpos($body['system'], 'Finom átdolgozás') !== false && strpos($body['system'], 'elrendezés') !== false, 'light rework keeps the layout');
check(array_keys(MG_AI_Redesign::get_intensities()) === array('strong', 'medium', 'full'), 'new style is the default, full redesign last');
foreach (array_keys(MG_AI_Redesign::get_intensities()) as $level) {
    check(strpos(MG_AI_Redesign::analysis_instructions($level), 'az eredeti képet NEM látja') !== false, "analyzer told to describe what to keep ($level)");
}

$http_responses[] = array('code' => 200, 'body' => json_encode(array('stop_reason' => 'refusal', 'content' => array())));
throws(function () { MG_AI_Redesign::analyze('anthropic:claude-opus-5-5', 'QUJD', 'strong', 'x'); }, 'elutasította', 'refusal is reported');
$http_responses[] = array('code' => 401, 'body' => 'secret provider body');
throws(function () { MG_AI_Redesign::analyze('anthropic:claude-opus-5-5', 'QUJD', 'strong', 'x'); }, 'HTTP 401', '401 is reported without the body');
throws(function () { MG_AI_Redesign::analyze('anthropic:claude-unknown', 'QUJD', 'strong', 'x'); }, 'nem elérhető', 'unknown analyzer rejected');

// OpenAI request shape.
$options['mg_ai_seo_settings'] = array('api_key' => 'sk-openai');
$http_calls = array();
$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'completed', 'output_text' => analysis_json())));
MG_AI_Redesign::analyze('openai', 'QUJD', 'strong', 'x');
list($url, $request) = $http_calls[0];
$body = json_decode($request['body'], true);
check($url === 'https://api.openai.com/v1/responses' && $request['headers']['Authorization'] === 'Bearer sk-openai', 'OpenAI endpoint and key');
check($body['text']['format']['type'] === 'json_schema' && $body['text']['format']['strict'] === true, 'OpenAI structured output');
check($body['input'][0]['content'][1]['image_url'] === 'data:image/jpeg;base64,QUJD', 'OpenAI image as data URL');
$http_responses[] = array('code' => 200, 'body' => json_encode(array('status' => 'incomplete')));
throws(function () { MG_AI_Redesign::analyze('openai', 'QUJD', 'strong', 'x'); }, 'hiányos', 'incomplete OpenAI response reported');

// Parsing and the fixed image prompt rules.
check(MG_AI_Redesign::parse_analysis("```json\n" . analysis_json() . "\n```")['new_style'] === 'retro', 'fenced JSON tolerated');
throws(function () { MG_AI_Redesign::parse_analysis('{"text_lines":[],"image_prompt":""}'); }, 'képpromptot', 'empty prompt rejected');
$prompt = MG_AI_Redesign::build_image_prompt($analysis, true);
check(strpos($prompt, 'exactly this text, line by line, and no other text: "Legjobb Apa" / "a világon"') !== false, 'exact text with accents enforced');
check(strpos($prompt, 'fully transparent background') !== false && strpos($prompt, 'attached') === false, 'transparent, no reference wording');
$prompt = MG_AI_Redesign::build_image_prompt($analysis, false);
check(strpos($prompt, 'plain, solid background') !== false && strpos($prompt, 'Bold retro badge') === 0, 'opaque, analyzer prompt first');
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
check($metadata_refreshed === array(21), 'attachment metadata refreshed');
throws(function () use ($good) { MG_AI_Redesign::store_upscaled(6, 21, $good); }, 'már fel van skálázva', 'second upload rejected');

// Approval of a candidate the browser could not upscale: 3× Lanczos fallback.
$meta[6][MG_AI_Redesign::META_CANDIDATES][] = array('attachment_id' => 22, 'upscale' => 'none', 'width' => 10, 'height' => 10, 'analyzer' => 'openai', 'intensity' => 'strong');
file_put_contents(get_attached_file(22), make_png(10, 10));
$enqueued = array();
MG_AI_Redesign::apply(6, 22);
check(getimagesize(get_attached_file(22))[0] === 30, 'not upscaled candidate gets 3× on approval');
check(end($meta[6][MG_AI_Redesign::META_HISTORY])['upscale'] === 'lanczos3x' && $enqueued[0][1] === get_attached_file(22), 'history records the fallback');

array_map('unlink', glob($test_dir . '/*'));
rmdir($test_dir);
echo "AI redesign: $passed checks passed. HTTP and Imagick mocked, no API calls.\n";
