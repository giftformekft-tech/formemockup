<?php
/** Grafikus profilok: kiosztás, kapcsoló, megjelenítés, kötegelt szétosztás – WordPress doubles, adatbázis nélkül. */
define('ABSPATH', __DIR__ . '/');
define('MG_VERSION', 'test');
define('ARRAY_N', 'ARRAY_N');

$checks = 0;
function check($value, $message) { $GLOBALS['checks']++; if (!$value) throw new RuntimeException($message); }

$options = array();
$terms = array();          // term_id => WP_Term
$term_meta = array();      // term_id => [key => value]
$relations = array();      // product_id => term_id
$products = array();       // product_id => post_status
$hooks = array();
$caps = array('edit_products' => false);
$query = array('tax' => false, 'page' => 0);
$template = 'astra';
$is_404 = false;
$deferred = array();

class WP_Error { private $code; private $message; public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; } public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } }
class WP_Term { public $term_id; public $name; public $slug; public $count = 0; public $taxonomy = 'mg_designer'; }
class Fake_Query { public function set_404() { $GLOBALS['is_404'] = true; } }
$wp_query = new Fake_Query();

function __($s, $d = '') { return $s; }
function _n($single, $plural, $n, $d = '') { return $n === 1 ? $single : $plural; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_html__($s, $d = '') { return esc_html($s); }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function wp_kses_post($v) { return strip_tags((string) $v, '<p><a><strong><em><ul><li><br>'); }
function wpautop($s) { return '<p>' . $s . '</p>'; }
function number_format_i18n($n) { return number_format($n, 0, ',', ' '); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function add_action($hook, $cb, $prio = 10) { $GLOBALS['hooks'][$hook][] = array($cb, $prio); }
function add_filter($hook, $cb, $prio = 10) { add_action($hook, $cb, $prio); }
function remove_action($hook, $cb, $prio = 10) { $GLOBALS['hooks']['removed:' . $hook][] = $cb; }
function add_shortcode($tag, $cb) {}
function has_hook($hook) { return !empty($GLOBALS['hooks'][$hook]); }
$wc_title_priority = 5;
function has_action($hook, $cb) { return $GLOBALS['wc_title_priority']; }
function is_admin() { return false; }
function is_tax($tax = '') { return $GLOBALS['query']['tax']; }
function is_page($id = 0) { return $GLOBALS['query']['page'] > 0 && (int) $id === $GLOBALS['query']['page']; }
function current_user_can($cap) { return !empty($GLOBALS['caps'][$cap]); }
function get_template() { return $GLOBALS['template']; }
function get_bloginfo($k) { return 'Forme'; }
function status_header($code) {}
function nocache_headers() {}
function get_post_status($id) { return $id === 77 ? 'publish' : false; }
function get_permalink($id) { return 'https://example.test/grafikus-program/'; }
function get_post_type($id) { return isset($GLOBALS['products'][$id]) ? 'product' : 'product_variation'; }
function wp_get_post_parent_id($id) { return 0; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_terms($args) { return array_values($GLOBALS['terms']); }
function get_term_meta($id, $key, $single = true) { return $GLOBALS['term_meta'][$id][$key] ?? ''; }
function get_the_terms($product_id, $tax) { $id = $GLOBALS['relations'][$product_id] ?? 0; return $id ? array($GLOBALS['terms'][$id]) : false; }
function get_term_link($term) { return 'https://example.test/grafikus/' . $term->slug . '/'; }
function wp_set_object_terms($product_id, $ids, $tax, $append) { $GLOBALS['relations'][$product_id] = (int) $ids[0]; return $ids; }
function wp_delete_object_term_relationships($product_id, $tax) { unset($GLOBALS['relations'][$product_id]); }
function wp_defer_term_counting($defer) { $GLOBALS['deferred'][] = $defer; }
function wp_get_attachment_image($id, $size, $icon, $attr) { return '<img class="' . $attr['class'] . '" src="/avatar-' . $id . '.png" alt="' . esc_attr($attr['alt']) . '">'; }

/** A batch_where_sql() lekérdezéseit a $products / $relations tömbökből szolgálja ki. */
class Fake_WPDB {
    public $posts = 'wp_posts';
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy = 'wp_term_taxonomy';
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        foreach ($args as $arg) {
            $sql = preg_replace('/%[ds]/', is_int($arg) ? (string) $arg : "'" . $arg . "'", $sql, 1);
        }
        return $sql;
    }
    private function matching($sql) {
        $ids = array();
        foreach ($GLOBALS['products'] as $id => $status) {
            if (!in_array($status, array('publish', 'draft', 'pending', 'private', 'future'), true)) continue;
            $has = isset($GLOBALS['relations'][$id]);
            if (strpos($sql, 'NOT EXISTS') !== false && $has) continue;
            if (strpos($sql, 'NOT EXISTS') === false && strpos($sql, 'EXISTS') !== false && !$has) continue;
            $ids[] = $id;
        }
        sort($ids);
        return $ids;
    }
    public function get_var($sql) { return count($this->matching($sql)); }
    /** get_assignment_counts(): profilonként, a kiosztásban számító státuszokkal, opcionálisan ID-ig */
    public function get_results($sql, $output) {
        $max = preg_match('/p\.ID <= (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
        $counts = array();
        foreach ($GLOBALS['relations'] as $pid => $tid) {
            $status = $GLOBALS['products'][$pid] ?? '';
            if ($pid > $max || !in_array($status, array('publish', 'draft', 'pending', 'private', 'future'), true)) continue;
            $counts[$tid] = ($counts[$tid] ?? 0) + 1;
        }
        $rows = array();
        foreach ($counts as $tid => $n) $rows[] = array((string) $tid, (string) $n);
        return $rows;
    }
    public function get_col($sql) {
        preg_match('/p\.ID > (\d+) ORDER BY p\.ID ASC LIMIT (\d+)/', $sql, $m);
        $ids = array_values(array_filter($this->matching($sql), function ($id) use ($m) { return $id > (int) $m[1]; }));
        return array_slice($ids, 0, (int) $m[2]);
    }
}
$wpdb = new Fake_WPDB();

function add_profile($id, $name, $active = true) {
    $t = new WP_Term(); $t->term_id = $id; $t->name = $name; $t->slug = strtolower(str_replace(' ', '-', $name));
    $GLOBALS['terms'][$id] = $t;
    if (!$active) $GLOBALS['term_meta'][$id]['mg_designer_active'] = '0';
    MG_Designer_Profiles::flush_cache();
    return $t;
}
function reset_hooks() { $GLOBALS['hooks'] = array(); }

require __DIR__ . '/../includes/class-designer-profiles.php';

// --- Alapértelmezések: a megjelenés KI, az automatikus besorolás BE -------------------------
$s = MG_Designer_Profiles::get_settings();
check($s['enabled'] === false, 'A megjelenés alapból ki van kapcsolva');
check($s['auto_assign'] === true, 'Az automatikus besorolás alapból be van kapcsolva');
check(!MG_Designer_Profiles::is_display_enabled(), 'is_display_enabled alapból hamis');
check((float) $s['program_commission'] === 3.0, 'A jutalék alapból 3%');

// --- Beállítások mentése --------------------------------------------------------------------
$clean = MG_Designer_Profiles::sanitize_settings(array('enabled' => '1', 'program_commission' => '2,5', 'label' => '<b>Tervezte:</b>', 'program_page_id' => '77'));
check($clean['enabled'] === true && $clean['show_single'] === false, 'A hiányzó checkbox hamis');
check($clean['program_commission'] === 2.5, 'Vesszős jutalék');
check($clean['label'] === 'Tervezte:', 'A felirat szűrve');
check(MG_Designer_Profiles::sanitize_settings(array('program_commission' => '250'))['program_commission'] === 100.0, 'A jutalék max 100');
MG_Designer_Profiles::update_settings(array('program_page_id' => 77));
check(MG_Designer_Profiles::get_settings()['program_page_id'] === 77, 'update_settings összefésül');
check(MG_Designer_Profiles::get_settings()['auto_assign'] === true, 'update_settings nem írja felül a többi kulcsot');

// --- Véletlen választás -----------------------------------------------------------------------
check(MG_Designer_Profiles::pick_random_profile_id(array()) === 0, 'Üres listából 0');
$seen = array();
for ($i = 0; $i < 300; $i++) {
    $pick = MG_Designer_Profiles::pick_random_profile_id(array(11, 12, 13));
    check(in_array($pick, array(11, 12, 13), true), 'Csak a megadott profilok közül választ');
    $seen[$pick] = true;
}
check(count($seen) === 3, 'Mindhárom profil előfordul 300 húzásból');

// --- Új termék automatikus besorolása (bulk feltöltés) ---------------------------------------
$products = array(501 => 'publish');
check(MG_Designer_Profiles::maybe_assign_new_product(501) === 0 && !isset($relations[501]), 'Profil nélkül nincs besorolás');
add_profile(11, 'Kiss Anna');
add_profile(12, 'Nagy Bence');
add_profile(13, 'Szünetelő Szilvi', false);
check(MG_Designer_Profiles::get_active_profile_ids() === array(11, 12), 'A szünetelő profil kimarad');
for ($i = 0; $i < 50; $i++) {
    unset($relations[501]);
    $assigned = MG_Designer_Profiles::maybe_assign_new_product(501);
    check(in_array($assigned, array(11, 12), true) && $relations[501] === $assigned, 'Új termék aktív profilt kap');
}
$relations[501] = 12;
check(MG_Designer_Profiles::maybe_assign_new_product(501) === 0 && $relations[501] === 12, 'Meglévő grafikus nem változik');
unset($relations[501]);
MG_Designer_Profiles::update_settings(array('auto_assign' => false));
check(MG_Designer_Profiles::maybe_assign_new_product(501) === 0 && !isset($relations[501]), 'Kikapcsolt automatikus besorolás');
MG_Designer_Profiles::update_settings(array('auto_assign' => true));

// --- Kötegelt szétosztás, kurzorral ----------------------------------------------------------
$products = array();
for ($id = 1000; $id < 1450; $id++) $products[$id] = 'publish';
$products[1450] = 'trash';
$products[1451] = 'auto-draft';
$relations = array(1000 => 13);
$r = MG_Designer_Profiles::process_batch('missing', 0, 200);
check($r['total'] === 449 && $r['processed'] === 200 && !$r['done'], 'Első köteg: 449 grafikus nélküli, 200 feldolgozva');
check($deferred === array(true, false), 'A termszámlálás a köteg végére halasztva');
$cursor = $r['cursor']; $rounds = 1;
while (!$r['done']) { $r = MG_Designer_Profiles::process_batch('missing', $cursor, 200); $cursor = $r['cursor']; $rounds++; check($r['total'] === null, 'Csak az első kötegnél van total'); }
check($rounds === 3, 'Három köteg 449 termékre');
check(count($relations) === 450 && $relations[1000] === 13, 'Mind kapott grafikust, a meglévő maradt');
check(!isset($relations[1450]) && !isset($relations[1451]), 'Kuka és auto-draft kimarad');
foreach ($relations as $pid => $tid) if ($pid !== 1000) check(in_array($tid, array(11, 12), true), 'Csak aktív profil kerül kiosztásra');
check(MG_Designer_Profiles::get_assignment_stats() === array('total' => 450, 'assigned' => 450, 'missing' => 0), 'Statisztika');

$r = MG_Designer_Profiles::process_batch('all', 0, 1000);
check($r['total'] === 450 && $r['done'] && $relations[1000] !== 13, 'Újrasorsolás mindenkit érint');
$r = MG_Designer_Profiles::process_batch('clear', 0, 1000);
check($r['processed'] === 450 && empty($relations), 'Törlés minden besorolást levesz');
check(is_wp_error(MG_Designer_Profiles::process_batch('bogus', 0)), 'Ismeretlen mód hiba');
$saved_terms = $terms; $terms = array(); MG_Designer_Profiles::flush_cache();
check(is_wp_error(MG_Designer_Profiles::process_batch('missing', 0)), 'Aktív profil nélkül hiba');
$terms = $saved_terms; MG_Designer_Profiles::flush_cache();

// --- Maximum minta profilonként ------------------------------------------------------------
check(MG_Designer_Profiles::pick_random_profile_id(array(11, 12), array(11 => 5), array(11 => 5)) === 12, 'A betelt profil kimarad');
check(MG_Designer_Profiles::pick_random_profile_id(array(11, 12), array(11 => 5, 12 => 2), array(11 => 5, 12 => 2)) === 0, 'Ha mind betelt: 0');
check(MG_Designer_Profiles::pick_random_profile_id(array(11, 12), array(11 => 99, 12 => 1), array(12 => 1)) === 11, 'Hiányzó limit = korlátlan');
check(MG_Designer_Profiles::get_free_capacity() === null, 'Korlátlan profil mellett a szabad hely korlátlan');

$term_meta[11]['mg_designer_max'] = '100';
$term_meta[12]['mg_designer_max'] = '50';
check(MG_Designer_Profiles::get_profile_limits(array(11, 12, 13)) === array(11 => 100, 12 => 50), 'Limitek term meta-ból');

// Szétosztás limittel: 450 grafikus nélküli termék, 150 hely
$products = array();
for ($id = 1000; $id < 1450; $id++) $products[$id] = 'publish';
$relations = array();
$cursor = 0; $skipped = 0; $assigned_total = 0;
do { $r = MG_Designer_Profiles::process_batch('missing', $cursor, 200); $cursor = $r['cursor']; $skipped += $r['skipped']; } while (!$r['done']);
$by_term = array_count_values($relations);
check(($by_term[11] ?? 0) === 100 && ($by_term[12] ?? 0) === 50, 'Pont a maximumig tölt fel');
check($skipped === 300 && count($relations) === 150, '300 termék grafikus nélkül marad');
check(MG_Designer_Profiles::get_free_capacity() === 0, 'Nincs szabad hely');
$r = MG_Designer_Profiles::process_batch('missing', 0, 200);
check(is_wp_error($r) && $r->get_error_code() === 'all_full', 'Betelt profiloknál a szétosztás hibát ad');

// Kuka nem számít bele a mintaszámba
$products[1000] = 'trash';
check(MG_Designer_Profiles::get_free_capacity() === 1, 'A kukás termék helye felszabadul');
$products[1000] = 'publish';

// Újrasorsolás limittel, több kötegben: a kurzor előttiek számítanak, a többi lekerül
$term_meta[11]['mg_designer_max'] = '120';
$term_meta[12]['mg_designer_max'] = '130';
$cursor = 0; $skipped = 0;
do { $r = MG_Designer_Profiles::process_batch('all', $cursor, 200); $cursor = $r['cursor']; $skipped += $r['skipped']; } while (!$r['done']);
$by_term = array_count_values($relations);
check(($by_term[11] ?? 0) === 120 && ($by_term[12] ?? 0) === 130, 'Újrasorsolás: mindkét profil pont a maximumáig');
check(count($relations) === 250 && $skipped === 200, 'Újrasorsolás: a fölösleg grafikus nélkül marad');

// Újrasorsolás, ha pont annyi termék van, amennyi hely, és a mostani besorolás túllépi a limitet:
// a kurzor utáni (még újra nem sorsolt) régi besorolások nem foglalhatnak helyet
$products = array(); $relations = array();
for ($id = 3000; $id < 3250; $id++) { $products[$id] = 'publish'; $relations[$id] = $id < 3200 ? 11 : 12; }
$cursor = 0; $skipped = 0;
do { $r = MG_Designer_Profiles::process_batch('all', $cursor, 100); $cursor = $r['cursor']; $skipped += $r['skipped']; } while (!$r['done']);
$by_term = array_count_values($relations);
check($skipped === 0 && count($relations) === 250, 'Teltházas újrasorsolás: egy termék sem marad ki');
check(($by_term[11] ?? 0) === 120 && ($by_term[12] ?? 0) === 130, 'Teltházas újrasorsolás: pont a maximumok');

// Új termék (bulk) limittel
$products[2000] = 'publish';
check(MG_Designer_Profiles::maybe_assign_new_product(2000) === 0 && !isset($relations[2000]), 'Betelt profiloknál az új termék grafikus nélkül marad');
$term_meta[12]['mg_designer_max'] = '131';
check(MG_Designer_Profiles::maybe_assign_new_product(2000) === 12, 'Az egyetlen szabad helyű profilt kapja');
check(MG_Designer_Profiles::get_free_capacity() === 0, 'Ezzel betelt');
unset($term_meta[11]['mg_designer_max'], $term_meta[12]['mg_designer_max']);

// --- Tervezősor HTML -------------------------------------------------------------------------
$products = array(501 => 'publish');
$relations = array(501 => 11);
$term_meta[11]['mg_designer_avatar'] = 9;
$loop = MG_Designer_Profiles::get_byline_html(501, 'loop');
check(strpos($loop, '<a class="mg-designer-byline__link" href="https://example.test/grafikus/kiss-anna/">') !== false, 'Lista: link a profiloldalra');
check(strpos($loop, 'Tervezte:') !== false && strpos($loop, '<img') === false, 'Lista: felirat, profilkép nélkül');
$plain = MG_Designer_Profiles::get_byline_html(501, 'plain');
check(strpos($plain, '<a ') === false && strpos($plain, 'Kiss Anna') !== false, 'Szöveges változat link nélkül (kártyán belül)');
$single = MG_Designer_Profiles::get_byline_html(501, 'single');
check(strpos($single, 'src="/avatar-9.png"') !== false, 'Termékoldal: profilkép');
check(MG_Designer_Profiles::get_byline_html(999, 'loop') === '', 'Grafikus nélküli termék: üres');
$terms[12]->name = '<script>x</script>';
$relations[502] = 12; $products[502] = 'publish';
check(strpos(MG_Designer_Profiles::get_byline_html(502, 'single'), '<script>') === false, 'A név escape-elve');
$terms[12]->name = 'Nagy Bence';
check(MG_Designer_Profiles::get_initials('nagy bence  kálmán') === 'NB', 'Monogram');
check(strpos(MG_Designer_Profiles::get_byline_html(502, 'single'), '>NB</span>') !== false, 'Profilkép nélkül monogram');

// Ajándékkereső: csak bekapcsolva
check(MG_Designer_Profiles::get_card_byline_html(501) === '', 'Kikapcsolva az ajándékkereső kártyán sincs');
MG_Designer_Profiles::update_settings(array('enabled' => true));
check(strpos(MG_Designer_Profiles::get_card_byline_html(501), 'Kiss Anna') !== false, 'Bekapcsolva megjelenik a kártyán');
MG_Designer_Profiles::update_settings(array('show_gift_finder' => false));
check(MG_Designer_Profiles::get_card_byline_html(501) === '', 'A kártyás megjelenés külön kikapcsolható');
MG_Designer_Profiles::update_settings(array('show_gift_finder' => true, 'enabled' => false));

// --- Hookok: kikapcsolva semmi, bekapcsolva témától függően ---------------------------------
reset_hooks();
MG_Designer_Profiles::hook_frontend();
check(!has_hook('woocommerce_single_product_summary') && !has_hook('astra_woo_shop_title_after') && !has_hook('wp_enqueue_scripts'), 'Kikapcsolva nincs frontend hook');

MG_Designer_Profiles::update_settings(array('enabled' => true));
reset_hooks();
MG_Designer_Profiles::hook_frontend();
check(has_hook('astra_woo_shop_title_after') && !has_hook('woocommerce_after_shop_loop_item_title'), 'Astra: a lista a cím utáni Astra hookra kerül');
check(has_hook('astra_woo_single_title_after') && has_hook('woocommerce_single_product_summary'), 'Termékoldal: Astra hook + WooCommerce tartalék');
$template = 'storefront';
reset_hooks();
MG_Designer_Profiles::hook_frontend();
check(has_hook('woocommerce_after_shop_loop_item_title') && !has_hook('astra_woo_shop_title_after'), 'Más téma: WooCommerce hook');
$template = 'astra';

// Termékoldal: két hook, egyszer jelenik meg
class Fake_Product { public function get_id() { return 501; } public function get_parent_id() { return 0; } }
$product = new Fake_Product();
ob_start();
MG_Designer_Profiles::render_single_byline();
MG_Designer_Profiles::render_single_byline();
$out = ob_get_clean();
check(substr_count($out, 'mg-designer-byline--single') === 1, 'A termékoldali sor csak egyszer jelenik meg');

// Tartalék (prio 6): sima WooCommerce sorrendben a cím (5) után kiír, Astra-struktúránál nem
$single_fallback = function () { $GLOBALS['product'] = new Fake_Product(); $r = new ReflectionProperty('MG_Designer_Profiles', 'single_rendered'); $r->setAccessible(true); $r->setValue(null, array()); ob_start(); MG_Designer_Profiles::render_single_byline_fallback(); return ob_get_clean(); };
check(strpos($single_fallback(), 'mg-designer-byline--single') !== false, 'Tartalék: a cím 5-ös prioritású, kiírja');
$wc_title_priority = false;
check($single_fallback() === '', 'Astra: ha a címet az Astra rendereli, a tartalék nem ír a cím fölé');
$template = 'storefront';
check(strpos($single_fallback(), 'mg-designer-byline--single') !== false, 'Más téma: a tartalék kiírja');
$template = 'astra'; $wc_title_priority = 5;

// --- Profiloldal és programoldal láthatósága ------------------------------------------------
MG_Designer_Profiles::update_settings(array('enabled' => false));
$query = array('tax' => true, 'page' => 0);
$is_404 = false;
MG_Designer_Profiles::guard_public_pages();
check($is_404, 'Kikapcsolva a profiloldal 404 a látogatónak');
$caps['edit_products'] = true;
$is_404 = false;
MG_Designer_Profiles::guard_public_pages();
check(!$is_404, 'Az admin előnézetben látja');
$caps['edit_products'] = false;
$query = array('tax' => false, 'page' => 77);
MG_Designer_Profiles::guard_public_pages();
check($is_404, 'Kikapcsolva a programoldal is 404');
check(MG_Designer_Profiles::hidden_page_ids() === array(77), 'Kikapcsolva a programoldal rejtett (sitemap, llms.txt)');
check(MG_Designer_Profiles::filter_sitemap_posts(array(), 'page')['post__not_in'] === array(77), 'A sitemapből kimarad');
MG_Designer_Profiles::update_settings(array('enabled' => true));
$is_404 = false;
MG_Designer_Profiles::guard_public_pages();
check(!$is_404 && MG_Designer_Profiles::hidden_page_ids() === array(), 'Bekapcsolva elérhető');
check(!isset(MG_Designer_Profiles::filter_sitemap_taxonomies(array('mg_designer' => 1, 'product_cat' => 1))['mg_designer']), 'A profilok sosem kerülnek a sitemapbe');
$query = array('tax' => true, 'page' => 0);
$robots = MG_Designer_Profiles::filter_robots(array('index' => true));
check(!empty($robots['noindex']) && !isset($robots['index']), 'A profiloldal noindex');

// --- Programoldal tartalma -------------------------------------------------------------------
check(MG_Designer_Profiles::format_commission(3) === '3%' && MG_Designer_Profiles::format_commission(2.5) === '2,5%', 'Jutalék formázás');
check(MG_Designer_Profiles::parse_faq("K1 | V1\n\nrossz sor\nK2|V2") === array(array('K1', 'V1'), array('K2', 'V2')), 'GYIK feldolgozás');
$html = MG_Designer_Profiles::render_program_shortcode();
check(strpos($html, 'nettó eladási ár 3%-a') !== false, 'A lépésekben a 3%');
check(strpos($html, '5 000 Ft') !== false, 'Minimum kifizetés');
check(strpos($html, 'A jutalékot havonta, a tárgyhónapot követő hónap 15-éig, banki átutalással fizetjük ki.') !== false, 'Helyettesítők a GYIK-ban');
check(strpos($html, '<details>') !== false && strpos($html, '<form') === false, 'GYIK van, jelentkezési űrlap nincs');
check(strpos($html, 'A Forme grafikus programjában') !== false, '{oldal} helyettesítő');
MG_Designer_Profiles::update_settings(array('enabled' => false));
check(MG_Designer_Profiles::render_program_shortcode() === '', 'Kikapcsolva a látogató üres shortcode-ot kap');

// --- Profiloldal fejléce ---------------------------------------------------------------------
MG_Designer_Profiles::update_settings(array('enabled' => true));
$terms[11]->count = 128;
$term_meta[11] += array('mg_designer_tagline' => 'Illusztrátor', 'mg_designer_location' => 'Szeged', 'mg_designer_since' => '2023', 'mg_designer_bio' => 'Vicces <b>feliratok</b>.');
$header = MG_Designer_Profiles::get_profile_header_html($terms[11]);
check(strpos($header, 'Illusztrátor · Szeged') !== false && strpos($header, '128 minta · Velünk 2023 óta') !== false, 'Profil fejléc adatai');
check(strpos($header, '&lt;b&gt;feliratok') !== false, 'A bemutatkozás escape-elve');
check(strpos($header, 'href="https://example.test/grafikus-program/"') !== false, 'Link a programoldalra');
check(strpos($header, 'mg-designer-preview-note') === false, 'Bekapcsolva nincs előnézeti figyelmeztetés');

echo "designer-profiles-test: {$checks} checks passed\n";
