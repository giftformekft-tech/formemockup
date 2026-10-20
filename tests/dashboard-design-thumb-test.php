<?php
/**
 * php tests/dashboard-design-thumb-test.php
 * Dashboard design previews with WordPress doubles: attachment thumbnail,
 * stale attachment after a replacement, cached 128 px PNG, per-load budget.
 */
define('ABSPATH', __DIR__);
$meta = $attachments = $editor_calls = array();
$dir = sys_get_temp_dir() . '/mg-dash-thumb-' . bin2hex(random_bytes(4));
mkdir($dir);

function absint($v) { return abs((int) $v); }
function wp_normalize_path($p) { return str_replace('\\', '/', $p); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function wp_upload_dir() { return array('basedir' => $GLOBALS['dir'], 'baseurl' => 'https://shop.test/uploads'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_attached_file($id) { return $GLOBALS['attachments'][$id]['file'] ?? ''; }
function wp_get_attachment_image_src($id, $size) { return $GLOBALS['attachments'][$id]['src'] ?? false; }
function wp_get_attachment_url($id) { return 'https://shop.test/uploads/' . basename(get_attached_file($id)); }
function get_the_post_thumbnail($id, $size, $attr = array()) { return '<img class="mockup" title="' . ($attr['title'] ?? '') . '">'; }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error {}
function wp_get_image_editor($path) {
    $GLOBALS['editor_calls'][] = $path;
    return new class {
        public $size;
        public function resize($w, $h, $crop) { $this->size = array($w, $h, $crop); return true; }
        public function save($file, $mime) { file_put_contents($file, 'thumb ' . $mime . ' ' . implode('x', array_slice($this->size, 0, 2))); return array('path' => $file); }
    };
}

require __DIR__ . '/../admin/class-dashboard-page.php';

$passed = 0;
function check($cond, $label) {
    if (!$cond) { fwrite(STDERR, "FAIL - $label\n"); exit(1); }
    $GLOBALS['passed']++;
}

// 1. Attachment with a generated thumbnail size: used as is, nothing generated.
file_put_contents("$dir/a.png", 'design a');
$attachments[10] = array('file' => "$dir/a.png", 'src' => array('https://shop.test/uploads/a-150x150.png', 150, 150, true));
$meta[1] = array('_mg_last_design_path' => "$dir/a.png", '_mg_last_design_attachment' => 10);
$t = MG_Dashboard_Page::design_thumbnail(1);
check($t['thumb'] === 'https://shop.test/uploads/a-150x150.png' && $editor_calls === array(), 'attachment thumbnail used');

// 2. Stale attachment (design replaced, path changed): the path wins, a cached preview is made once.
file_put_contents("$dir/new.png", 'design new');
$meta[2] = array('_mg_last_design_path' => "$dir/new.png", '_mg_last_design_attachment' => 10);
$t = MG_Dashboard_Page::design_thumbnail(2);
check($editor_calls === array("$dir/new.png"), 'preview made from the current design, not the stale attachment');
check(strpos($t['thumb'], 'https://shop.test/uploads/mg_design_thumbs/') === 0 && $t['full'] === 'https://shop.test/uploads/new.png', 'cached preview URL and full design URL');
$file = $dir . '/' . substr($t['thumb'], strlen('https://shop.test/uploads/'));
check(file_get_contents($file) === 'thumb image/png 128x128', '128 px PNG saved');
MG_Dashboard_Page::design_thumbnail(2);
check(count($editor_calls) === 1, 'cached preview reused');

// 3. Attachment without intermediate sizes (full-size only): never served to the list.
file_put_contents("$dir/big.png", 'design big');
$attachments[11] = array('file' => "$dir/big.png", 'src' => array('https://shop.test/uploads/big.png', 4000, 4000, false));
$meta[3] = array('_mg_last_design_attachment' => 11);
$t = MG_Dashboard_Page::design_thumbnail(3);
check(end($editor_calls) === "$dir/big.png" && strpos($t['thumb'], 'mg_design_thumbs/') !== false, 'full-size attachment gets a small preview');

// 4. Missing or outside-uploads design: no preview, mockup fallback.
$meta[4] = array('_mg_last_design_path' => '/etc/passwd');
check(MG_Dashboard_Page::design_thumbnail(4) === null, 'path outside uploads ignored');
$m = new ReflectionMethod('MG_Dashboard_Page', 'design_thumbnail_html');
$m->setAccessible(true);
$html = $m->invoke(null, 5);
check(strpos($html, 'class="mockup"') !== false && strpos($html, 'Nincs mentett mintafájl') !== false, 'no design: mockup with a note');
$html = $m->invoke(null, 2);
check(strpos($html, 'mg_design_thumbs/') !== false && strpos($html, 'href="https://shop.test/uploads/new.png"') !== false, 'preview links the full design');

// 5. Budget: at most 10 new previews per load, the rest show the mockup for now.
for ($i = 100; $i < 112; $i++) {
    file_put_contents("$dir/d$i.png", "design $i");
    $meta[$i] = array('_mg_last_design_path' => "$dir/d$i.png");
}
$made = $pending = 0;
for ($i = 100; $i < 112; $i++) {
    $t = MG_Dashboard_Page::design_thumbnail($i);
    $t['thumb'] !== '' ? $made++ : $pending++;
}
check($made === 8 && $pending === 4, 'budget of 10 per load (2 already used above)');
$html = $m->invoke(null, 111);
check(strpos($html, 'következő betöltéskor') !== false, 'pending preview explained');

// 6. A 512 px preview for the bulk view: medium_large size or its own cached file.
$attachments[12] = array('file' => "$dir/a.png", 'src' => array('https://shop.test/uploads/a-768x768.png', 768, 768, true));
$meta[200] = array('_mg_last_design_attachment' => 12);
$t = MG_Dashboard_Page::design_thumbnail(200, 512);
check($t['thumb'] === 'https://shop.test/uploads/a-768x768.png', '512 preview uses the medium_large size');
$budget = new ReflectionProperty('MG_Dashboard_Page', 'design_thumbs_made');
$budget->setAccessible(true);
$budget->setValue(null, 0); // a new page load
file_put_contents("$dir/p.png", 'design p');
$meta[201] = array('_mg_last_design_path' => "$dir/p.png");
$small = MG_Dashboard_Page::design_thumbnail(201);
$big = MG_Dashboard_Page::design_thumbnail(201, 512);
check($small['thumb'] !== $big['thumb'] && substr($big['thumb'], -8) === '-512.png', 'separate cached file per size');
$bigfile = $dir . '/' . substr($big['thumb'], strlen('https://shop.test/uploads/'));
check(file_get_contents($bigfile) === 'thumb image/png 512x512', '512 px PNG saved');

exec('rm -rf ' . escapeshellarg($dir));
echo "Dashboard design thumbnails: $passed checks passed.\n";
