<?php
/**
 * php tests/variation-group-generation-test.php
 * "Minta variációk" group generation with WordPress/WooCommerce doubles:
 * the base product keeps its value, name and URL, the other values become
 * renamed copies in their own subcategory, and unapproved AI PNGs block.
 */
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
$meta = $posts = $terms = $options = $applied_presets = array();
$next_id = 500;
$tmp = sys_get_temp_dir() . '/mg-variation-test-' . bin2hex(random_bytes(4));
mkdir($tmp);

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function __($s) { return $s; }
function absint($v) { return abs((int) $v); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_title($s) {
    $s = strtr(mb_strtolower((string) $s, 'UTF-8'), array('á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u'));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function current_time($f) { return '2026-10-19 12:00:00'; }
function wp_generate_uuid4() { return 'aaaa-bbbb'; }
function wp_list_pluck($list, $field, $index = null) {
    $out = array();
    foreach ($list as $row) { if ($index === null) { $out[] = $row[$field]; } else { $out[$row[$index]] = $row[$field]; } }
    return $out;
}
function wp_normalize_path($p) { return str_replace('\\', '/', $p); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function maybe_unserialize($v) { return $v; }
function wp_slash($v) { return $v; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function get_post_meta($id, $key = '', $single = false) {
    if ($key === '') { return array_map(function ($v) { return array($v); }, $GLOBALS['meta'][$id] ?? array()); }
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function add_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); return true; }
function get_post_type($id) { return $GLOBALS['posts'][$id]['type'] ?? false; }
function get_post_mime_type($id) { return $GLOBALS['posts'][$id]['mime'] ?? ''; }
function get_attached_file($id) { return $GLOBALS['posts'][$id]['file'] ?? ''; }
function wp_get_attachment_url($id) { return 'https://example.test/' . basename(get_attached_file($id)); }
function get_the_title($id) { return $GLOBALS['posts'][$id]['title'] ?? ''; }
function get_post($id, $output = null) { return array('ID' => $id, 'post_title' => get_the_title($id), 'post_type' => 'product', 'post_status' => 'publish'); }
function wp_insert_post($post, $wp_error = false) {
    $id = ++$GLOBALS['next_id'];
    $GLOBALS['posts'][$id] = array('type' => 'product', 'title' => $post['post_title'], 'status' => 'draft', 'slug' => '');
    return $id;
}
function get_object_taxonomies($type) { return array('product_cat'); }
function wp_get_object_terms($id, $tax, $args = array()) { return $GLOBALS['terms'][$id] ?? array(); }
function wp_set_object_terms($id, $ids, $tax, $append = false) { $GLOBALS['terms'][$id] = array_values($ids); return $ids; }
function wp_unique_post_slug($slug, $id, $status, $type, $parent) { return $slug; }
function wc_get_product($id) { return isset($GLOBALS['posts'][$id]) && $GLOBALS['posts'][$id]['type'] === 'product' ? new Test_Product($id) : false; }

class Test_Product {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function is_type($t) { return false; }
    public function get_name() { return $GLOBALS['posts'][$this->id]['title']; }
    public function set_name($n) { $GLOBALS['posts'][$this->id]['title'] = $n; }
    public function get_status() { return $GLOBALS['posts'][$this->id]['status']; }
    public function set_status($s) { $GLOBALS['posts'][$this->id]['status'] = $s; }
    public function set_slug($s) { $GLOBALS['posts'][$this->id]['slug'] = $s; }
    public function set_sku($s) { $GLOBALS['meta'][$this->id]['_sku'] = $s; }
    public function save() { return $this->id; }
}
class MG_Product_Creator {
    public static function generate_product_sku($id, $name) { return 'SKU-' . $id; }
}
class MG_Custom_Fields_Manager {
    public static function get_fields_for_product($id) { return $GLOBALS['options']['mg_custom_fields'][$id]['fields'] ?? array(); }
    public static function save_fields_for_product($id, $fields) { $GLOBALS['options']['mg_custom_fields'][$id]['fields'] = $fields; }
    public static function set_custom_product($id, $on) {}
    public static function apply_preset_to_product($id, $preset) { $GLOBALS['applied_presets'][] = array($id, $preset); return true; }
}

require dirname(__DIR__) . '/includes/class-variant-name-inflector.php';
require dirname(__DIR__) . '/includes/class-custom-field-product-variants.php';

$failures = 0;
function check($ok, $label) {
    global $failures;
    if (!$ok) { $failures++; echo "FAIL: {$label}\n"; }
}
function png_attachment($id, $name) {
    $path = $GLOBALS['tmp'] . '/' . $name . '.png';
    file_put_contents($path, 'png');
    $GLOBALS['posts'][$id] = array('type' => 'attachment', 'mime' => 'image/png', 'file' => $path, 'title' => $name);
    return $id;
}

// Source product "Februári szülinapos póló" in Szülinap (10) and the February subcategory (12).
$source = 100;
$posts[$source] = array('type' => 'product', 'title' => 'Februári szülinapos póló', 'status' => 'publish', 'slug' => 'februari-szulinapos-polo');
$terms[$source] = array(10, 12);
$field = array(
    'id' => 'honap', 'label' => 'Hónap', 'type' => 'select', 'linked_product_variants' => true,
    'options' => array('Január', 'Február', 'Március'),
    'variation_values' => array(),
    'linked_option_categories' => array('januar' => 11, 'februar' => 12, 'marcius' => 13),
);
$options['mg_custom_fields'][$source]['fields'] = array($field);
update_post_meta($source, MG_Custom_Field_Product_Variants::META_VARIANTS, array('honap' => array(
    'linked_product_variants' => true,
    'base_slug' => 'februar',
    'mapping' => array(
        'januar' => array('slug' => 'januar', 'label' => 'Január', 'attachment_id' => png_attachment(201, 'jan'), 'ai_generated' => true, 'approved' => false),
        'februar' => array('slug' => 'februar', 'label' => 'Február', 'attachment_id' => png_attachment(202, 'feb')),
        'marcius' => array('slug' => 'marcius', 'label' => 'Március', 'attachment_id' => png_attachment(203, 'mar'), 'ai_generated' => true, 'approved' => true),
    ),
)));

$blocked = MG_Custom_Field_Product_Variants::generate_or_update($source, 'honap', array('field' => $field, 'preset_id' => 'p1'));
check(is_wp_error($blocked) && $blocked->get_error_code() === 'unapproved_mapping', 'an unapproved AI PNG blocks generation');
check(strpos(is_wp_error($blocked) ? $blocked->get_error_message() : '', 'Január') !== false, 'the blocking message names the value');

$config = get_post_meta($source, MG_Custom_Field_Product_Variants::META_VARIANTS);
$config['honap']['mapping']['januar']['approved'] = true;
update_post_meta($source, MG_Custom_Field_Product_Variants::META_VARIANTS, $config);

$result = MG_Custom_Field_Product_Variants::generate_or_update($source, 'honap', array('field' => $field, 'preset_id' => 'p1'));
check(!is_wp_error($result), 'generation succeeds once approved: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
$by_slug = array();
foreach ((array) ($result['products'] ?? array()) as $row) { $by_slug[$row['slug']] = $row; }
check(($by_slug['februar']['product_id'] ?? 0) === $source, 'the base value stays on the original product');
check($posts[$source]['title'] === 'Februári szülinapos póló', 'the original product keeps its name');
check($posts[$source]['slug'] === 'februari-szulinapos-polo', 'the original product keeps its URL');
$jan = $by_slug['januar']['product_id'] ?? 0;
$mar = $by_slug['marcius']['product_id'] ?? 0;
check($jan > 0 && $jan !== $source && $mar > 0 && $mar !== $source, 'other values get new products');
check(($posts[$jan]['title'] ?? '') === 'Januári szülinapos póló', 'January copy is renamed with the suffix');
check(($posts[$mar]['title'] ?? '') === 'Márciusi szülinapos póló', 'March copy is renamed with the suffix');
check(($posts[$mar]['status'] ?? '') === 'publish', 'copies take the source status');
check(($meta[$mar]['_mg_last_design_attachment'] ?? 0) === 203, 'March copy prints the March PNG');
check($terms[$source] === array(10, 12), 'the original stays in Szülinap + February');
check($terms[$jan] === array(10, 11), 'January copy moves to the January subcategory');
check($terms[$mar] === array(10, 13), 'March copy moves to the March subcategory');
check(in_array(array($jan, 'p1'), $applied_presets, true) && in_array(array($mar, 'p1'), $applied_presets, true), 'copies join the preset');
check(!in_array(array($source, 'p1'), $applied_presets, true), 'the service does not re-apply the preset to the source');
check(get_post_meta($mar, MG_Custom_Field_Product_Variants::META_OPTION_LABEL) === 'Március', 'copies know their value');
check(get_post_meta($mar, MG_Custom_Field_Product_Variants::META_PARENT) === $source, 'copies point to the original');

// Re-running reuses the same products and does not create duplicates.
$count = count($posts);
$again = MG_Custom_Field_Product_Variants::generate_or_update($source, 'honap', array('field' => $field, 'preset_id' => 'p1'));
check(!is_wp_error($again) && empty($again['created_product_ids']) && count($posts) === $count, 'regeneration is idempotent');

// Once a group exists its base cannot move to another value.
$config = get_post_meta($source, MG_Custom_Field_Product_Variants::META_VARIANTS);
$config['honap']['base_slug'] = 'januar';
update_post_meta($source, MG_Custom_Field_Product_Variants::META_VARIANTS, $config);
$moved = MG_Custom_Field_Product_Variants::generate_or_update($source, 'honap', array('field' => $field));
check(is_wp_error($moved) && $moved->get_error_code() === 'base_changed', 'the base of an existing group is locked');

// A typed age field uses its own variation list; numbers keep vowel harmony.
$age = 300;
$posts[$age] = array('type' => 'product', 'title' => 'Az élet 60 felett kezdődik – 60-as póló', 'status' => 'publish', 'slug' => 'az-elet-60');
$age_field = array('id' => 'kor', 'label' => 'Hány éves?', 'type' => 'number', 'linked_product_variants' => true, 'variation_values' => array('50', '60'));
update_post_meta($age, MG_Custom_Field_Product_Variants::META_VARIANTS, array('kor' => array(
    'linked_product_variants' => true,
    'base_slug' => '60',
    'mapping' => array(
        '50' => array('slug' => '50', 'label' => '50', 'attachment_id' => png_attachment(401, 'age50')),
        '60' => array('slug' => '60', 'label' => '60', 'attachment_id' => png_attachment(402, 'age60')),
    ),
)));
$ages = MG_Custom_Field_Product_Variants::generate_or_update($age, 'kor', array('field' => $age_field));
check(!is_wp_error($ages), 'typed field generation succeeds: ' . (is_wp_error($ages) ? $ages->get_error_message() : ''));
$fifty = 0;
foreach ((array) ($ages['products'] ?? array()) as $row) { if ($row['slug'] === '50') { $fifty = $row['product_id']; } }
check(($posts[$fifty]['title'] ?? '') === 'Az élet 50 felett kezdődik – 50-es póló', 'age copy name: ' . ($posts[$fifty]['title'] ?? ''));
check($posts[$age]['title'] === 'Az élet 60 felett kezdődik – 60-as póló', 'age original keeps its name');

array_map('unlink', glob($tmp . '/*'));
rmdir($tmp);
echo $failures === 0 ? "Variation group generation: all checks passed\n" : "{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
