<?php
// php tests/seo-meta-test.php
// SEO modul: címek, leírások, kanonikus típusos URL, sitemap, slug-ütközés,
// GYIK, robots.txt, llms.txt, IndexNow és séma – WordPress stubokkal.
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('OBJECT', 'OBJECT');
define('JSON_PRETTY', 0);

$checks = 0;
function check($condition, $message) {
    $GLOBALS['checks']++;
    if (!$condition) {
        throw new Exception('FAILED: ' . $message);
    }
}

/* ---------------- WordPress stubok ---------------- */
$options = array();
$term_meta = array();
$post_meta = array();
$terms = array();
$object_terms = array();
$query_vars = array();
$existing_slugs = array();
$remote_posts = array();
$remote_post_code = 200;
$scheduled = array();
$robots_body = '';
$is = array('product' => false, 'product_category' => false, 'product_tag' => false, 'front_page' => false, 'singular' => false, 'search' => false);
$queried_object = null;
$queried_object_id = 0;

class WP_Term {
    public $term_id; public $name; public $slug; public $parent = 0; public $taxonomy = 'product_cat'; public $count = 0; public $description = '';
    public function __construct($data) { foreach ($data as $k => $v) { $this->$k = $v; } }
}
class WP_Post {
    public $ID; public $post_type = 'product'; public $post_name = ''; public $post_status = 'publish'; public $post_excerpt = ''; public $post_content = ''; public $post_title = '';
    public function __construct($data) { foreach ($data as $k => $v) { $this->$k = $v; } }
}
class WP_Query {
    public $posts = array();
    public function get($key) { return $key === 'posts_per_page' ? 20 : ''; }
    public function get_queried_object() { return $GLOBALS['queried_object']; }
}
class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class WC_Product {
    public $id; public $name; public $price = 5990; public $type = 'simple'; public $sku = 'FORME1'; public $slug; public $reviews = 0; public $rating = 0;
    public function __construct($id, $name, $slug) { $this->id = $id; $this->name = $name; $this->slug = $slug; }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_price() { return $this->price; }
    public function get_sku() { return $this->sku; }
    public function get_slug() { return $this->slug; }
    public function is_type($type) { return $this->type === $type; }
    public function get_permalink() { return 'https://forme.hu/termek/' . $this->slug . '/'; }
    public function get_image_id() { return 0; }
    public function is_in_stock() { return true; }
    public function get_review_count() { return $this->reviews; }
    public function get_average_rating() { return $this->rating; }
    public function get_short_description() { return '<p>Rövid leírás.</p>'; }
    public function get_description() { return ''; }
}
class MG_Outlet {
    const META = '_mg_outlet';
    public static function is_outlet($product) { return false; }
}
class MG_Variant_Display_Manager {
    public static function get_catalog_index() {
        return array(
            'ferfi-polo' => array('label' => 'Férfi póló'),
            'noi-polo' => array('label' => 'Női póló'),
            'pulcsi' => array('label' => 'Pulcsi'),
            'bogre' => array('label' => 'Bögre'),
        );
    }
}
class MG_Virtual_Variant_Manager {
    public static $requested = false;
    public static function get_type_from_request() { return self::$requested; }
    public static function get_frontend_config($product) {
        $types = array();
        foreach (MG_Variant_Display_Manager::get_catalog_index() as $slug => $type) {
            $types[$slug] = array('label' => $type['label'], 'price' => $slug === 'bogre' ? 4490 : 5990, 'preview_url' => 'https://forme.hu/mg/' . $product->get_sku() . '_' . $slug . '.webp');
        }
        return array('types' => $types, 'default' => array('type' => 'ferfi-polo'), 'typeUrls' => array());
    }
}

function __($text) { return $text; }
function esc_html__($text) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return (string) $url; }
function esc_url_raw($url) { return filter_var(trim((string) $url), FILTER_SANITIZE_URL); }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field($text) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $text))); }
function sanitize_textarea_field($text) { return trim(strip_tags((string) $text)); }
function sanitize_email($text) { return trim((string) $text); }
function sanitize_key($text) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $text)); }
function sanitize_title($text) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', remove_accents((string) $text)), '-')); }
function wp_unslash($value) { return $value; }
function wp_kses_post($text) { return (string) $text; }
function wp_kses($text, $allowed) { return strip_tags((string) $text, '<' . implode('><', array_keys($allowed)) . '>'); }
function wpautop($text) { return '<p>' . trim((string) $text) . '</p>'; }
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
    $text = strip_tags($text);
    return $remove_breaks ? trim(preg_replace('/[\r\n\t ]+/', ' ', $text)) : trim($text);
}
function remove_accents($text) {
    return strtr((string) $text, array('á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U'));
}
function apply_filters($name, $value) { return $value; }
function do_action() {}
function add_action($hook, $callback, $priority = 10) { $GLOBALS['actions'][] = array($hook, $callback, $priority); }
function add_filter() {}
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default; }
function update_option($name, $value) { $GLOBALS['options'][$name] = $value; return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function taxonomy_exists($taxonomy) { return $GLOBALS['taxonomies_registered'] ?? true; }
function home_url($path = '/') { return 'https://forme.hu' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function get_bloginfo($key = 'name') { return $key === 'admin_email' ? 'info@forme.hu' : 'www.forme.hu'; }
function trailingslashit($text) { return rtrim($text, '/') . '/'; }
function untrailingslashit($text) { return rtrim($text, '/'); }
function user_trailingslashit($text) { return trailingslashit($text); }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function get_term_meta($term_id, $key, $single = true) { return isset($GLOBALS['term_meta'][$term_id][$key]) ? $GLOBALS['term_meta'][$term_id][$key] : ''; }
function update_term_meta($term_id, $key, $value) { $GLOBALS['term_meta'][$term_id][$key] = $value; }
function delete_term_meta($term_id, $key) { unset($GLOBALS['term_meta'][$term_id][$key]); }
function get_post_meta($id, $key, $single = true) { return isset($GLOBALS['post_meta'][$id][$key]) ? $GLOBALS['post_meta'][$id][$key] : ''; }
function get_the_terms($id, $taxonomy) {
    $out = array();
    foreach ($GLOBALS['object_terms'][$id] ?? array() as $term_id) { $out[] = $GLOBALS['terms'][$term_id]; }
    return $out ?: false;
}
function get_term($id, $taxonomy = '') { return $GLOBALS['terms'][(int) $id] ?? null; }
function get_terms($args) { return array_values($GLOBALS['terms']); }
function get_ancestors($id, $taxonomy) {
    $out = array();
    $term = $GLOBALS['terms'][$id] ?? null;
    while ($term && $term->parent) { $out[] = $term->parent; $term = $GLOBALS['terms'][$term->parent] ?? null; }
    return $out;
}
function get_term_link($term) { return 'https://forme.hu/product-category/' . $term->slug . '/'; }
function term_description($term_id, $taxonomy = '') { return isset($GLOBALS['terms'][$term_id]) ? '<p>' . $GLOBALS['terms'][$term_id]->description . '</p>' : ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_query_var($key) { return $GLOBALS['query_vars'][$key] ?? ''; }
function is_admin() { return false; }
function is_feed() { return false; }
function is_404() { return false; }
function is_search() { return $GLOBALS['is']['search']; }
function is_product() { return $GLOBALS['is']['product']; }
function is_product_category() { return $GLOBALS['is']['product_category']; }
function is_product_tag() { return $GLOBALS['is']['product_tag']; }
function is_front_page() { return $GLOBALS['is']['front_page']; }
function is_home() { return false; }
function is_shop() { return false; }
function is_singular() { return $GLOBALS['is']['singular']; }
function get_queried_object() { return $GLOBALS['queried_object']; }
function get_queried_object_id() { return $GLOBALS['queried_object_id']; }
function wc_get_product($id) { return $GLOBALS['products'][is_object($id) ? $id->ID : (int) $id] ?? null; }
function wp_get_canonical_url($id) { return MG_GMC_SEO_Optimizer::override_canonical('https://forme.hu/termek/' . $GLOBALS['products'][$id]->slug . '/'); }
function get_woocommerce_currency() { return 'HUF'; }
function get_locale() { return 'hu_HU'; }
function get_theme_mod($key) { return 0; }
function get_site_icon_url() { return 'https://forme.hu/icon.png'; }
function wp_get_attachment_image_url() { return ''; }
function wp_get_attachment_url() { return ''; }
function get_permalink($post) { return 'https://forme.hu/termek/' . $post->post_name . '/'; }
function get_the_title($post) { return $post->post_title !== '' ? $post->post_title : 'Szállítás'; }
function get_posts($args) {
    $not_in = array_map('intval', $args['post__not_in'] ?? array());
    return array_values(array_filter($GLOBALS['get_posts_result'] ?? array(), function ($post) use ($not_in) {
        return !in_array(is_object($post) ? (int) $post->ID : (int) $post, $not_in, true);
    }));
}
function absint($value) { return abs((int) $value); }
function get_home_path() { return $GLOBALS['home_path'] ?? '/nonexistent/'; }
function wc_get_page_id($page) { return 0; }
function wc_get_page_permalink($page) { return 'https://forme.hu/bolt/'; }
function get_page_by_path($slug, $output = OBJECT, $type = 'page') {
    if (in_array($slug, $GLOBALS['attachment_slugs'] ?? array(), true)) { return new WP_Post(array('post_name' => $slug, 'post_type' => 'attachment')); }
    return in_array($slug, $GLOBALS['existing_slugs'], true) ? new WP_Post(array('post_name' => $slug)) : null;
}
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_remote_get($url) { return array('code' => 200, 'body' => $GLOBALS['robots_body']); }
function wp_remote_post($url, $args) { $GLOBALS['remote_posts'][] = $args; return array('code' => $GLOBALS['remote_post_code'], 'body' => ''); }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_next_scheduled($hook) { return isset($GLOBALS['scheduled'][$hook]) ? $GLOBALS['scheduled'][$hook] : false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['scheduled'][$hook] = $time; }
function wp_generate_password($length) { return str_repeat('a', $length); }

$GLOBALS['wp_query'] = new WP_Query();

require dirname(__DIR__) . '/includes/class-seo-meta.php';
require dirname(__DIR__) . '/includes/class-gmc-seo-optimizer.php';
require dirname(__DIR__) . '/includes/class-seo-category-content.php';
require dirname(__DIR__) . '/includes/class-seo-ai-visibility.php';
require dirname(__DIR__) . '/includes/class-indexnow.php';
require dirname(__DIR__) . '/includes/class-product-structured-data.php';

/* ---------------- adatok ---------------- */
$terms[15] = new WP_Term(array('term_id' => 15, 'name' => 'Minden egyébb', 'slug' => 'uncategorized'));
$terms[93] = new WP_Term(array('term_id' => 93, 'name' => 'Születésnap', 'slug' => 'szuletesnapi-polok', 'count' => 442,
    'description' => 'Egyedi születésnapi pólók vicces, poénos és stílusos mintákkal. Tökéletes ajándék férfiaknak, nőknek és barátoknak bármilyen életkorra. Lepd meg az ünnepeltet egy igazán személyes születésnapi pólóval, ami emlékezetes marad.'));
$terms[94] = new WP_Term(array('term_id' => 94, 'name' => 'Hobbi', 'slug' => 'hobbi-polok'));
$terms[95] = new WP_Term(array('term_id' => 95, 'name' => 'Horgász', 'slug' => 'horgasz-hobbi-polok', 'parent' => 94));
$terms[96] = new WP_Term(array('term_id' => 96, 'name' => 'minecraft-polok', 'slug' => 'minecraft', 'parent' => 97));
$terms[97] = new WP_Term(array('term_id' => 97, 'name' => 'Gamer', 'slug' => 'gamer-polok'));
$terms[98] = new WP_Term(array('term_id' => 98, 'name' => 'Vicces', 'slug' => 'vicces-polok', 'count' => 1789,
    'description' => 'Vicces egyedi pólók poénos, szókimondó és kreatív mintákkal.'));
$options['default_product_cat'] = 15;

$products = array();
$products[1] = new WC_Product(1, '50-nek születik', '50-nek-szuletik');
$products[2] = new WC_Product(2, 'Az élet 50 felett kezdődik Születésnap', 'az-elet-50-felett-kezdodik-szuletesnap');
$products[3] = new WC_Product(3, 'Kapitány horgász póló pulcsi', 'kapitany-horgasz-polo-pulcsi');
$products[4] = new WC_Product(4, 'Ez egy nagyon hosszú, sok szavas mintanév a vicces nyugdíjas horgász nagypapáknak', 'hosszu');
$products[5] = new WC_Product(5, 'Kreeper', 'kreeper');
$object_terms = array(1 => array(15, 93), 2 => array(93), 3 => array(94, 95, 93), 4 => array(95), 5 => array(96));

/* ---------------- szöveg-segédek ---------------- */
check(MG_SEO_Meta::contains('Az élet 50 felett kezdődik Születésnap', 'születésnap'), 'accent/case insensitive contains');
check(!MG_SEO_Meta::contains('Születésnapi buli', 'Születésnap'), 'contains respects word boundaries');
check(MG_SEO_Meta::looks_like_slug('minecraft-polok') && !MG_SEO_Meta::looks_like_slug('Horgász'), 'slug-like names detected');
check(MG_SEO_Meta::clean_segment('Név - ') === 'Név', 'dangling type separator removed');
check(MG_SEO_Meta::clean_segment(' – vicces pólók') === 'vicces pólók', 'leading separator removed');
check(MG_SEO_Meta::clean_segment('50-nek születik') === '50-nek születik', 'hyphen inside words kept');
check(MG_SEO_Meta::clean_description('Név - . Minta leírás.  Előnyök.') === 'Név. Minta leírás. Előnyök.', 'empty type cleaned in description');
check(MG_SEO_Meta::clean_description('Szöveg.. Más!.') === 'Szöveg. Más!', 'double punctuation cleaned');
$long = str_repeat('Hosszú mondat a mintáról. ', 10);
$cut = MG_SEO_Meta::truncate($long, 160);
check(mb_strlen($cut) <= 160 && substr($cut, -1) === '.', 'truncate prefers sentence boundary');
$cut = MG_SEO_Meta::truncate(str_repeat('szó ', 60), 160);
check(mb_strlen($cut) <= 160 && mb_substr($cut, -1) === '…', 'truncate falls back to word boundary with ellipsis');
check(MG_SEO_Meta::fit_sentences('Első mondat. Második mondat itt van. Harmadik.', 40) === 'Első mondat. Második mondat itt van.', 'fit_sentences keeps whole sentences');
check(MG_SEO_Meta::fit_sentences('Első mondat. Második mondat itt van. Harmadik.', 35) === 'Első mondat.', 'fit_sentences stops before overflowing');
check(MG_SEO_Meta::fit_sentences('Rövid.', 20) === '', 'fit_sentences skips tiny budgets');

/* ---------------- termékcím ---------------- */
$ctx = MG_SEO_Meta::get_product_context($products[1], '');
check($ctx['type'] === 'ferfi-polo' && $ctx['type_label'] === 'Férfi póló', 'default virtual type used without request');
check($ctx['term']->term_id === 93, 'uncategorized skipped for primary category');
check(MG_SEO_Meta::build_product_title($ctx) === '50-nek születik - Férfi póló | Születésnap | Forme.hu', 'product title has type and category: ' . MG_SEO_Meta::build_product_title($ctx));
check(MG_SEO_Meta::build_product_title($ctx, false) === '50-nek születik - Férfi póló | Születésnap', 'og title without site name');

$ctx = MG_SEO_Meta::get_product_context($products[1], 'bogre');
check(MG_SEO_Meta::build_product_title($ctx) === '50-nek születik - Bögre | Születésnap | Forme.hu', 'requested virtual type in title');
check($ctx['price'] == 4490 && strpos($ctx['image'], '_bogre.webp') !== false, 'type price and image like the feed');

MG_Virtual_Variant_Manager::$requested = 'noi-polo';
$is['product'] = true; $queried_object_id = 1;
$ctx = MG_SEO_Meta::get_product_context($products[1]);
check($ctx['type'] === 'noi-polo', 'type taken from the virtual URL on product pages');
MG_Virtual_Variant_Manager::$requested = 'nincs-ilyen';
MG_SEO_Meta::reset_cache();
$ctx = MG_SEO_Meta::get_product_context($products[1]);
check($ctx['type'] === 'ferfi-polo', 'unknown requested type falls back to default');
MG_Virtual_Variant_Manager::$requested = false;
$is['product'] = false; $queried_object_id = 0;
MG_SEO_Meta::reset_cache();

$ctx = MG_SEO_Meta::get_product_context($products[2], '');
check(MG_SEO_Meta::build_product_title($ctx) === 'Az élet 50 felett kezdődik Születésnap - Férfi póló | Forme.hu', 'category not repeated when the name has it');

$ctx = MG_SEO_Meta::get_product_context($products[3], '');
check($ctx['term']->term_id === 95, 'sub-category chosen like the feed product_type');
check(MG_SEO_Meta::display_name($ctx) === array('Kapitány horgász', 'Férfi póló'), 'generic „póló pulcsi” postfix stripped like the H1');
check(MG_SEO_Meta::build_product_title($ctx) === 'Kapitány horgász - Férfi póló | Forme.hu', 'keyword already in name is skipped');

update_term_meta(93, MG_SEO_Meta::TERM_PRODUCT_KEYWORD, 'Születésnapi ajándék');
MG_SEO_Meta::reset_cache();
$ctx = MG_SEO_Meta::get_product_context($products[3], '');
check($ctx['term']->term_id === 93 && $ctx['keyword'] === 'Születésnapi ajándék', 'category with own keyword wins');
check(MG_SEO_Meta::build_product_title($ctx) === 'Kapitány horgász - Férfi póló | Születésnapi ajándék | Forme.hu', 'custom keyword in title');
delete_term_meta(93, MG_SEO_Meta::TERM_PRODUCT_KEYWORD);
MG_SEO_Meta::reset_cache();

$ctx = MG_SEO_Meta::get_product_context($products[4], '');
$title = MG_SEO_Meta::build_product_title($ctx);
check($title === 'Ez egy nagyon hosszú, sok szavas mintanév a vicces nyugdíjas horgász nagypapáknak - Férfi póló', 'long title drops site then category, keeps name and type: ' . $title);

$ctx = MG_SEO_Meta::get_product_context($products[5], '');
check($ctx['keyword'] === 'Gamer', 'slug-like category name replaced by parent name');

/* ---------------- termék meta leírás ---------------- */
$post_meta[1]['_mg_sample_seo'] = 'Vicces felirat az ötvenedik születésnapra, ami mosolyt csal az ünnepelt arcára. Tökéletes ajándék baráti társaságnak. Harmadik mondat, ami már nem fér bele a keretbe semmiképpen sem.';
$ctx = MG_SEO_Meta::get_product_context($products[1], '');
$description = MG_SEO_Meta::build_product_description($ctx);
check(mb_strlen($description) <= 160, 'product description within 160 chars');
check(strpos($description, '50-nek születik - Férfi póló.') === 0, 'description starts with name and virtual type');
check(strpos($description, 'Vicces felirat az ötvenedik születésnapra') !== false, 'AI design text used');
check(substr($description, -strlen('gyors gyártás.')) === 'gyors gyártás.', 'USP kept at the end');
$ctx = MG_SEO_Meta::get_product_context($products[2], '');
$description = MG_SEO_Meta::build_product_description($ctx);
check(strpos($description, 'Egyedi születésnapi pólók vicces') !== false, 'category description is the fallback design text');

/* ---------------- kategória ---------------- */
$term = $terms[93];
check(MG_SEO_Meta::build_term_title($term) === 'Születésnap – vicces, egyedi pólók és ajándékok | Forme.hu', 'category template title');
check(MG_SEO_Meta::get_term_h1($term) === 'Születésnap – vicces, egyedi pólók, pulóverek és ajándékok', 'H1 from the template: ' . MG_SEO_Meta::get_term_h1($term));
check(MG_SEO_Meta::get_term_h1($term, false) === 'Születésnap', 'H1 without template is the name');
$query_vars['paged'] = 2;
check(MG_SEO_Meta::build_term_title($term) === 'Születésnap – vicces, egyedi pólók és ajándékok | 2. oldal | Forme.hu', 'paged category title');
$query_vars['paged'] = 0;
update_term_meta(93, MG_SEO_Meta::TERM_TITLE, 'Születésnapi pólók – Vicces és egyedi pólók');
update_term_meta(93, MG_SEO_Meta::TERM_H1, 'Születésnapi pólók – egyedi és vicces minták minden korra');
check(MG_SEO_Meta::build_term_title($term) === 'Születésnapi pólók – Vicces és egyedi pólók | Forme.hu', 'custom category title gets site name');
update_term_meta(93, MG_SEO_Meta::TERM_TITLE, 'Születésnapi pólók | Forme.hu');
check(MG_SEO_Meta::build_term_title($term) === 'Születésnapi pólók | Forme.hu', 'site name not duplicated');
check(MG_SEO_Meta::get_term_h1($term) === 'Születésnapi pólók – egyedi és vicces minták minden korra', 'custom H1');
$description = MG_SEO_Meta::build_term_description($term);
check($description === 'Egyedi születésnapi pólók vicces, poénos és stílusos mintákkal. Tökéletes ajándék férfiaknak, nőknek és barátoknak bármilyen életkorra.', 'term description trimmed to whole sentences: ' . $description);
update_term_meta(93, MG_SEO_Meta::TERM_DESCRIPTION, 'Több száz születésnapi póló férfiaknak és nőknek.');
check(MG_SEO_Meta::build_term_description($term) === 'Több száz születésnapi póló férfiaknak és nőknek.', 'custom meta description wins');
check(MG_SEO_Meta::build_term_description($terms[94]) === 'Hobbi: 0 egyedi, vicces minta férfi, női és gyerek pólón, pulóveren, párnán és táskán. Prémium minőség, tartós nyomtatás, gyors gyártás.', 'template description when nothing else: ' . MG_SEO_Meta::build_term_description($terms[94]));
check(MG_SEO_Meta::build_term_h1($term) === 'Születésnap – vicces, egyedi pólók, pulóverek és ajándékok', 'template H1 shown as the hint next to a custom H1');
check(MG_SEO_Meta::get_term_h1($term, false) === 'Születésnapi pólók – egyedi és vicces minták minden korra', 'custom H1 wins even without template');

/* ---------------- kategória H1 sablon, ismétlődő szavak ---------------- */
check(MG_SEO_Meta::drop_repeated_words('{kategoria} – vicces, egyedi pólók', 'Vicces') === '{kategoria} – egyedi pólók', 'repeated word before a comma dropped');
check(MG_SEO_Meta::drop_repeated_words('{kategoria}: {db} egyedi, vicces minta', 'Vicces') === '{kategoria}: {db} egyedi minta', 'repeated word after a comma dropped');
check(MG_SEO_Meta::drop_repeated_words('{kategoria} – vicces és egyedi minták', 'Vicces') === '{kategoria} – egyedi minták', 'repeated word before „és” dropped');
check(MG_SEO_Meta::drop_repeated_words('{kategoria} – pólók, pulóverek és táskák', 'Táskák') === '{kategoria} – pólók, pulóverek', 'repeated last list item dropped');
check(MG_SEO_Meta::drop_repeated_words('{kategoria} – vicces pólók', 'Vicces') === '{kategoria} – vicces pólók', 'words outside a list are kept');
check(MG_SEO_Meta::drop_repeated_words('{kategoria} – vicces, egyedi pólók', 'Születésnap') === '{kategoria} – vicces, egyedi pólók', 'nothing dropped without overlap');
check(MG_SEO_Meta::drop_repeated_words('{kategoria}, egyedi', 'Kategoria egyedi') === '{kategoria}', 'placeholders are never touched');
$vicces = $terms[98];
check(MG_SEO_Meta::get_term_h1($vicces) === 'Vicces – egyedi pólók, pulóverek és ajándékok', 'H1 template does not repeat the category name: ' . MG_SEO_Meta::get_term_h1($vicces));
check(MG_SEO_Meta::build_term_title($vicces) === 'Vicces – egyedi pólók és ajándékok | Forme.hu', 'title template does not repeat the category name: ' . MG_SEO_Meta::build_term_title($vicces));
check(MG_SEO_Meta::build_term_description($vicces) === 'Vicces egyedi pólók poénos, szókimondó és kreatív mintákkal. Prémium minőség, tartós nyomtatás, gyors gyártás.', 'short category description completed with the USP: ' . MG_SEO_Meta::build_term_description($vicces));
check(MG_SEO_Meta::get_term_display_name($terms[96]) === 'Minecraft polok', 'slug-like name made readable');
check(MG_SEO_Meta::get_term_h1($terms[96]) === 'Minecraft polok – vicces, egyedi pulóverek és ajándékok', 'slug-like name in the H1 template: ' . MG_SEO_Meta::get_term_h1($terms[96]));
$options[MG_SEO_Meta::OPTION] = array('category_h1_template' => '{kategoria} pólók – {szulo} témában, {db} mintával');
MG_SEO_Meta::reset_cache();
check(MG_SEO_Meta::get_term_h1($terms[95]) === 'Horgász pólók – Hobbi témában, 0 mintával', 'parent and count placeholders: ' . MG_SEO_Meta::get_term_h1($terms[95]));
$options[MG_SEO_Meta::OPTION] = array('category_h1_template' => '');
MG_SEO_Meta::reset_cache();
check(MG_SEO_Meta::get_term_h1($vicces) === 'Vicces', 'empty H1 template falls back to the name');
$is['product_category'] = true; $queried_object = $vicces;
check(MG_SEO_Category_Content::filter_page_title('Vicces') === 'Vicces', 'category page H1 is the name without template');
$options[MG_SEO_Meta::OPTION] = array();
MG_SEO_Meta::reset_cache();
check(MG_SEO_Category_Content::filter_page_title('Vicces') === 'Vicces – egyedi pólók, pulóverek és ajándékok', 'category page H1 uses the template');
update_term_meta(98, MG_SEO_Meta::TERM_H1, 'Vicces & "poénos" <b>pólók</b>');
check(MG_SEO_Category_Content::filter_page_title('Vicces') === 'Vicces &amp; &quot;poénos&quot; pólók', 'custom category page H1 is plain text, escaped');
delete_term_meta(98, MG_SEO_Meta::TERM_H1);
$is['product_category'] = false; $queried_object = null;
$saved = MG_SEO_Meta::save_settings(array('category_h1_template' => '  ', 'category_title_template' => ''));
check($saved['category_h1_template'] === '' && $saved['category_title_template'] === MG_SEO_Meta::defaults()['category_title_template'], 'H1 template may be emptied, required templates fall back');
$saved = MG_SEO_Meta::save_settings(array('category_h1_template' => '{kategoria} pólók és ajándékok'));
check($saved['category_h1_template'] === '{kategoria} pólók és ajándékok', 'H1 template saved');
$options[MG_SEO_Meta::OPTION] = array(
    'category_h1_template' => '{kategoria} – vicces, egyedi pólók, pulóverek és bögrék',
    'home_title' => '{oldal} – vicces, egyedi pólók, pulóverek és bögrék ajándékba',
    'category_description_template' => 'Saját: {kategoria} bögrén is',
);
MG_SEO_Meta::reset_cache();
check(MG_SEO_Meta::get_setting('category_h1_template') === MG_SEO_Meta::defaults()['category_h1_template'] && MG_SEO_Meta::get_setting('home_title') === MG_SEO_Meta::defaults()['home_title'], 'unchanged old defaults mentioning mugs are replaced');
check(MG_SEO_Meta::get_setting('category_description_template') === 'Saját: {kategoria} bögrén is', 'customized templates are kept');
foreach (array('category_description_template', 'category_h1_template', 'home_title', 'home_description') as $key) {
    check(stripos(MG_SEO_Meta::defaults()[$key], 'bögr') === false, 'no mug in the default ' . $key);
}
$options[MG_SEO_Meta::OPTION] = array();
MG_SEO_Meta::reset_cache();

/* ---------------- kanonikus típusos URL, slug-ütközés, sitemap ---------------- */
$is['product'] = true; $queried_object_id = 1;
MG_SEO_Meta::reset_cache();
check(MG_GMC_SEO_Optimizer::override_canonical('https://forme.hu/termek/50-nek-szuletik/') === 'https://forme.hu/termek/50-nek-szuletik-ferfi-polo/', 'base URL canonical is the default virtual type URL');
$_GET['mg_type'] = 'bogre';
check(MG_GMC_SEO_Optimizer::override_canonical('x') === 'https://forme.hu/termek/50-nek-szuletik-bogre/', 'type URL self-canonical');
$_GET['mg_type'] = 'nincs-ilyen';
check(MG_GMC_SEO_Optimizer::override_canonical('x') === 'https://forme.hu/termek/50-nek-szuletik-ferfi-polo/', 'invalid type falls back to default type URL');
unset($_GET['mg_type']);
check(MG_GMC_SEO_Optimizer::override_canonical('keep', new WP_Post(array('ID' => 99))) === 'keep', 'other post canonical untouched');
$options[MG_SEO_Meta::OPTION] = array('consolidate_base_url' => 0);
MG_SEO_Meta::reset_cache();
check(MG_GMC_SEO_Optimizer::override_canonical('https://forme.hu/termek/50-nek-szuletik/') === 'https://forme.hu/termek/50-nek-szuletik/', 'consolidation can be switched off');
$options[MG_SEO_Meta::OPTION] = array();
MG_SEO_Meta::reset_cache();

$data = MG_SEO_Meta::get_page_data();
check($data['kind'] === 'product' && $data['canonical'] === 'https://forme.hu/termek/50-nek-szuletik-ferfi-polo/', 'page data canonical is virtual');
check($data['title'] === '50-nek születik - Férfi póló | Születésnap | Forme.hu', 'document title on product page');
$og = MG_SEO_Meta::build_open_graph($data);
check($og['og:type'] === 'product' && $og['product:price:amount'] === '5990.00' && $og['product:retailer_item_id'] === 'FORME1_ferfi-polo', 'OG product like the feed item');
check($og['product:brand'] === 'www.forme.hu' && $og['og:site_name'] === 'Forme.hu', 'feed brand and short site name');
$breadcrumb = MG_SEO_Meta::build_breadcrumb($data);
$last = end($breadcrumb['itemListElement']);
check(count($breadcrumb['itemListElement']) === 3 && $last['name'] === '50-nek születik - Férfi póló' && $last['item'] === 'https://forme.hu/termek/50-nek-szuletik-ferfi-polo/', 'breadcrumb ends with the virtual product');
$is['product'] = false; $queried_object_id = 0;
MG_SEO_Meta::reset_cache();

$existing_slugs = array('kapitany-horgasz-polo-pulcsi', '50-nek-szuletik');
$vars = MG_GMC_SEO_Optimizer::resolve_base_slug_collision(array('product' => 'kapitany-horgasz-polo', 'name' => 'kapitany-horgasz-polo', 'mg_v_type' => 'pulcsi', 'post_type' => 'product'));
check($vars['product'] === 'kapitany-horgasz-polo-pulcsi' && $vars['name'] === 'kapitany-horgasz-polo-pulcsi' && !isset($vars['mg_v_type']), 'base URL ending with a type slug resolves to the product');
$vars = MG_GMC_SEO_Optimizer::resolve_base_slug_collision(array('product' => '50-nek-szuletik', 'name' => '50-nek-szuletik', 'mg_v_type' => 'pulcsi'));
check($vars['mg_v_type'] === 'pulcsi' && $vars['product'] === '50-nek-szuletik', 'real virtual URL untouched');
$GLOBALS['attachment_slugs'] = array('kapitany-horgasz-polo');
$vars = MG_GMC_SEO_Optimizer::resolve_base_slug_collision(array('product' => 'kapitany-horgasz-polo', 'name' => 'kapitany-horgasz-polo', 'mg_v_type' => 'pulcsi'));
check($vars['product'] === 'kapitany-horgasz-polo-pulcsi', 'an attachment with the short slug does not block the fix');
$GLOBALS['attachment_slugs'] = array();
$vars = MG_GMC_SEO_Optimizer::resolve_base_slug_collision(array('product' => 'nincs', 'mg_v_type' => 'pulcsi'));
check($vars['product'] === 'nincs' && $vars['mg_v_type'] === 'pulcsi', 'unknown slugs stay a 404');

$GLOBALS['get_posts_result'] = array(5);
$entry = MG_SEO_Meta::filter_sitemap_entry(array('loc' => 'https://forme.hu/termek/50-nek-szuletik/'), new WP_Post(array('ID' => 1)), 'product');
check($entry['loc'] === 'https://forme.hu/termek/50-nek-szuletik-ferfi-polo/', 'sitemap lists the default virtual type URL');
$entry = MG_SEO_Meta::filter_sitemap_entry(array('loc' => 'https://forme.hu/termek/kreeper/'), new WP_Post(array('ID' => 5)), 'product');
check($entry['loc'] === 'https://forme.hu/termek/kreeper/', 'non-virtual products keep their URL');
check(MG_SEO_Meta::filter_sitemap_provider('provider', 'users') === false, 'author sitemap removed');
$GLOBALS['get_posts_result'] = array();
MG_SEO_Meta::reset_cache();

/* ---------------- márka a feedekhez ---------------- */
check(MG_SEO_Meta::get_brand_name() === 'www.forme.hu', 'brand defaults to the feed brand');
$options[MG_SEO_Meta::OPTION] = array('brand_name' => 'Forme');
MG_SEO_Meta::reset_cache();
check(MG_SEO_Meta::get_brand_name() === 'Forme', 'brand setting used everywhere');
$options[MG_SEO_Meta::OPTION] = array();
MG_SEO_Meta::reset_cache();

/* ---------------- GYIK ---------------- */
$faq = MG_SEO_Category_Content::parse_faq("Mennyi idő alatt készül el?\n1–3 munkanap alatt.\n\nK: Kérhetem egyedi felirattal?\nV: Igen, a jelölt mintáknál.\nK: Van női fazon?\nV: Igen.\n\nMosható? | Igen, 30 fokon kifordítva.");
check(count($faq) === 4, 'all FAQ formats parsed');
check($faq[0]['q'] === 'Mennyi idő alatt készül el?' && $faq[0]['a'] === '1–3 munkanap alatt.', 'plain block format');
check($faq[2]['q'] === 'Van női fazon?' && $faq[2]['a'] === 'Igen.', 'K:/V: pairs without blank lines');
check($faq[3]['a'] === 'Igen, 30 fokon kifordítva.', 'single line pipe format');
$html = MG_SEO_Category_Content::render_faq($faq);
check(strpos($html, '"@type":"FAQPage"') !== false && substr_count($html, '<details') === 4, 'FAQ markup and schema');
check(strpos(MG_SEO_Category_Content::render_faq(array(array('q' => '</script>?', 'a' => 'x'))), '</script>?') === false, 'FAQ JSON-LD cannot close the script tag');

/* ---------------- robots.txt ---------------- */
$robots = "User-agent: *\nAllow: /\nDisallow: /wp-admin/\n\nUser-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n\nUser-agent: PerplexityBot\nDisallow: /termek/\n\nContent-Signal: search=yes, ai-train=no";
$report = MG_SEO_AI_Visibility::evaluate_robots($robots, MG_SEO_AI_Visibility::ai_bots(), array('/', '/termek/', '/product-category/'));
check($report['OAI-SearchBot']['status'] === 'allowed', 'search bot allowed via *');
check($report['GPTBot']['status'] === 'blocked' && $report['ClaudeBot']['status'] === 'blocked', 'grouped user-agents blocked');
check($report['PerplexityBot']['status'] === 'partial' && $report['PerplexityBot']['blocked'] === array('/termek/'), 'partial block detected');
check(MG_SEO_AI_Visibility::is_allowed(array(array('type' => 'disallow', 'path' => '/'), array('type' => 'allow', 'path' => '/termek/')), '/termek/x/'), 'longest match wins');
check(!MG_SEO_AI_Visibility::is_allowed(array(array('type' => 'disallow', 'path' => '/*.php$')), '/index.php'), 'wildcard and end anchor');
$robots_body = $robots;
$fetched = MG_SEO_AI_Visibility::get_robots_report(true);
check($fetched['code'] === 200 && $fetched['content_signals'] === array('Content-Signal: search=yes, ai-train=no'), 'content signal reported');

/* ---------------- llms.txt ---------------- */
$GLOBALS['get_posts_result'] = array(new WP_Post(array('ID' => 7, 'post_type' => 'page', 'post_name' => 'szallitas')));
$llms = MG_SEO_AI_Visibility::build_llms_txt();
check(strpos($llms, "# Forme.hu\n") === 0, 'llms.txt title');
check(strpos($llms, 'Férfi póló, Női póló, Pulcsi, Bögre') !== false, 'virtual types listed in summary');
check(strpos($llms, '- [Hobbi](https://forme.hu/product-category/hobbi-polok/)') !== false && strpos($llms, '  - [Horgász](https://forme.hu/product-category/horgasz-hobbi-polok/)') !== false, 'category tree nested');
check(strpos($llms, 'Minden egyébb') === false, 'default category skipped');
check(strpos($llms, '[Szállítás](https://forme.hu/termek/szallitas/)') !== false, 'pages listed');
update_term_meta(95, MG_SEO_Meta::TERM_NOINDEX, '1');
check(strpos(MG_SEO_AI_Visibility::build_llms_txt(), 'Horgász') === false, 'noindex categories skipped');
delete_term_meta(95, MG_SEO_Meta::TERM_NOINDEX);
$GLOBALS['get_posts_result'] = array();

/* ---------------- llms.txt: kategóriák, kiszolgálás, gyorsítótár ---------------- */
$GLOBALS['actions'] = array();
MG_SEO_AI_Visibility::init();
$serve = array_values(array_filter($GLOBALS['actions'], function ($action) {
    return $action[0] === 'init' && $action[1] === array('MG_SEO_AI_Visibility', 'maybe_serve_llms_txt');
}));
check(count($serve) === 1 && $serve[0][2] > 5, 'llms.txt served after WooCommerce registers product_cat (init 5)');
$llms = MG_SEO_AI_Visibility::build_llms_txt();
check(strpos($llms, "- [Születésnap](https://forme.hu/product-category/szuletesnapi-polok/): 442 minta. Több száz születésnapi póló férfiaknak és nőknek.\n") !== false, 'category line has the name, the design count and its own description: ' . $llms);
check(strpos($llms, "- [Vicces](https://forme.hu/product-category/vicces-polok/): 1789 minta. Vicces egyedi pólók poénos, szókimondó és kreatív mintákkal.\n") !== false, 'category description without the repeated benefits sentence');
check(strpos($llms, "  - [Minecraft polok](https://forme.hu/product-category/minecraft/)\n") !== false, 'slug-like category shown readable, empty note omitted');
check(substr_count($llms, 'Prémium minőség') === 1, 'benefits appear once, in the summary');
$GLOBALS['transients'] = array();
$GLOBALS['taxonomies_registered'] = false;
MG_SEO_AI_Visibility::get_llms_txt();
check($GLOBALS['transients'] === array(), 'llms.txt built before product_cat exists is not cached');
$GLOBALS['taxonomies_registered'] = true;
$body = MG_SEO_AI_Visibility::get_llms_txt();
check(count($GLOBALS['transients']) === 1 && reset($GLOBALS['transients']) === $body, 'llms.txt cached once categories are available');
MG_SEO_AI_Visibility::flush_llms_cache();
check($GLOBALS['transients'] === array(), 'cache flush clears the versioned key');

/* ---------------- llms.txt: vásárlási infók, oldalválasztás, fizikai fájl ---------------- */
$GLOBALS['get_posts_result'] = array(
    new WP_Post(array('ID' => 7, 'post_type' => 'page', 'post_name' => 'shipping_policy', 'post_title' => 'Szállítás',
        'post_content' => '<!-- wp:paragraph --><p>Szállítási díjak:&nbsp;GLS csomagpont 990 Ft</p><!-- /wp:paragraph --><p>Ingyenes szállítás 15000 Ft felett.</p>[cookie_banner id="2"]')),
    new WP_Post(array('ID' => 8, 'post_type' => 'page', 'post_name' => 'info-menu', 'post_title' => 'Info menü', 'post_content' => 'info menü')),
    new WP_Post(array('ID' => 9, 'post_type' => 'page', 'post_name' => 'home', 'post_title' => 'Home', 'post_content' => 'Főoldal')),
    new WP_Post(array('ID' => 10, 'post_type' => 'page', 'post_name' => 'tervezd-meg', 'post_title' => 'Tervezd meg', 'post_content' => '[designer]')),
);
$options['page_on_front'] = 9;
$options[MG_SEO_Meta::OPTION] = array('llms_facts' => "Ingyenes szállítás 15 000 Ft feletti rendelésnél.\n- Várható szállítási idő: 2–4 munkanap\n\n• Fizetés: bankkártya vagy utánvét\n„Tervezd meg” oldalon saját minta\n€ nélkül", 'llms_excluded_pages' => array(8));
MG_SEO_Meta::reset_cache();
$llms = MG_SEO_AI_Visibility::build_llms_txt();
check(strpos($llms, "## Vásárlási információk\n\n- Ingyenes szállítás 15 000 Ft feletti rendelésnél.\n- Várható szállítási idő: 2–4 munkanap\n- Fizetés: bankkártya vagy utánvét\n- „Tervezd meg” oldalon saját minta\n- € nélkül\n") !== false, 'facts listed one per line without duplicate bullets, multibyte starts intact');
check(strpos($llms, '## Vásárlási információk') < strpos($llms, '## Termékkategóriák'), 'facts come before the category tree');
check(strpos($llms, '- [Szállítás](https://forme.hu/termek/shipping_policy/): Szállítási díjak: GLS csomagpont 990 Ft Ingyenes szállítás 15000 Ft felett.' . "\n") !== false, 'page listed with a readable summary: ' . $llms);
check(strpos($llms, 'Info menü') === false, 'pages unticked in the settings are left out');
check(strpos($llms, '[Home]') === false, 'front page left out');
check(strpos($llms, "- [Tervezd meg](https://forme.hu/termek/tervezd-meg/)\n") !== false, 'page without text kept without summary');
check(strpos($llms, '- Cím: 4371 Nyírlugos, Hunyadi utca 35.') !== false, 'contact lists the company address');
check(count(MG_SEO_AI_Visibility::llms_page_candidates()) === 3 && count(MG_SEO_AI_Visibility::llms_pages()) === 2, 'candidates exclude system pages, list excludes unticked pages');
$saved = MG_SEO_Meta::save_settings(array('llms_pages_listed' => array('7', '8', '10'), 'llms_pages_checked' => array('7'), 'llms_facts' => "<b>Tény</b>\nMásik"));
check($saved['llms_excluded_pages'] === array(8, 10) && $saved['llms_facts'] === "Tény\nMásik", 'unticked pages saved as excluded, facts sanitized');
$options[MG_SEO_Meta::OPTION] = array();
unset($options['page_on_front']);
MG_SEO_Meta::reset_cache();
$GLOBALS['get_posts_result'] = array();

$home = sys_get_temp_dir() . '/mg-seo-home-' . getmypid() . '/';
@mkdir($home);
$GLOBALS['home_path'] = $home;
check(MG_SEO_AI_Visibility::physical_llms_path() === '', 'no physical llms.txt');
file_put_contents($home . 'llms.txt', "# www.forme.hu\n\n## Posts\n\n- [Hello world!](https://forme.hu/hello-world/)\n" . str_repeat("- [Termék](https://forme.hu/termek/x/): leírás\n", 400) . "\n[comment]: # (Generated by Hostinger Tools Plugin)\n");
check(MG_SEO_AI_Visibility::physical_llms_path() === $home . 'llms.txt', 'physical llms.txt found in the site root');
check(MG_SEO_AI_Visibility::physical_llms_generator($home . 'llms.txt') === 'Hostinger Tools', 'Hostinger Tools file recognised from its closing comment');
file_put_contents($home . 'llms.txt', "# Saját\n");
check(MG_SEO_AI_Visibility::physical_llms_generator($home . 'llms.txt') === '', 'other physical files have no known generator');
unlink($home . 'llms.txt');
rmdir($home);
unset($GLOBALS['home_path']);

/* ---------------- IndexNow ---------------- */
check(!MG_IndexNow::is_enabled(), 'IndexNow off by default');
MG_IndexNow::save_settings(true);
check(MG_IndexNow::is_enabled() && MG_IndexNow::is_valid_key(MG_IndexNow::get_settings()['key']), 'key generated on enable');
MG_IndexNow::queue(array('https://forme.hu/termek/a-ferfi-polo/', 'https://masik.hu/x/', 'https://forme.hu/termek/a-ferfi-polo/'));
check(get_option(MG_IndexNow::QUEUE) === array('https://forme.hu/termek/a-ferfi-polo/'), 'queue keeps own unique URLs only');
check(isset($scheduled[MG_IndexNow::CRON]), 'flush scheduled');
unset($scheduled[MG_IndexNow::CRON]);
$remote_post_code = 429;
MG_IndexNow::flush();
check(count(get_option(MG_IndexNow::QUEUE)) === 1 && isset($scheduled[MG_IndexNow::CRON]), 'rate limited batch kept for retry');
unset($scheduled[MG_IndexNow::CRON]);
$remote_post_code = 202;
MG_IndexNow::flush();
$sent = json_decode(end($remote_posts)['body'], true);
check($sent['host'] === 'forme.hu' && $sent['urlList'] === array('https://forme.hu/termek/a-ferfi-polo/') && $sent['keyLocation'] === 'https://forme.hu/' . $sent['key'] . '.txt', 'IndexNow payload');
check(get_option(MG_IndexNow::QUEUE) === array() && !isset($scheduled[MG_IndexNow::CRON]), 'accepted batch removed');

/* ---------------- séma ---------------- */
$graph = MG_Product_Structured_Data::build_organization_graph();
$org = $graph['@graph'][0];
check($org['@type'] === 'OnlineStore' && $org['name'] === 'Forme.hu' && $org['legalName'] === 'Gift for me Kft.', 'organization names');
check($org['address']['addressLocality'] === 'Nyírlugos', 'previous address kept until changed');
$options[MG_SEO_Meta::OPTION] = array('street' => '', 'city' => '', 'postal_code' => '', 'vat_id' => 'HU27038417', 'same_as' => "https://facebook.com/forme\nhttps://instagram.com/forme");
MG_SEO_Meta::reset_cache();
$org = MG_Product_Structured_Data::build_organization_graph()['@graph'][0];
check(!isset($org['address']) && $org['vatID'] === 'HU27038417' && count($org['sameAs']) === 2, 'empty address skipped, ids and profiles added');
$options[MG_SEO_Meta::OPTION] = array();
MG_SEO_Meta::reset_cache();
check(MG_Product_Structured_Data::get_feed_product_type(3, 'Férfi póló') === 'Ruházat > Férfi póló > Hobbi > Horgász', 'schema category equals the feed product_type');

echo "SEO meta: $checks assertions passed\n";
