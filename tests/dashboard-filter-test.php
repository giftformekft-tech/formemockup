<?php
/**
 * php tests/dashboard-filter-test.php
 * Dashboard list query with a $wpdb double: the main/subcategory filter
 * includes the term's descendants, and its placeholders line up with the
 * other filters' parameters.
 */
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
$queries = array();
$options = array('mg_products' => array(array('sku_prefix' => 'POLO')));
$children = array(10 => array(11, 12), 11 => array(), 99 => array());

function absint($v) { return abs((int) $v); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function get_term_children($id, $tax) { return $GLOBALS['children'][$id] ?? array(); }
class FakeWpdb {
    public $posts = 'wp_posts', $postmeta = 'wp_postmeta', $term_relationships = 'wp_term_relationships', $term_taxonomy = 'wp_term_taxonomy';
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, $params) {
        $params = (array) $params;
        $i = 0;
        $out = preg_replace_callback('/%[sd]/', function ($m) use (&$i, $params) {
            $v = $params[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $sql);
        if ($i !== count($params)) {
            throw new RuntimeException("placeholder/param count mismatch: $i vs " . count($params));
        }
        return $out;
    }
    public function get_var($sql) { $GLOBALS['queries'][] = $sql; return 3; }
    public function get_results($sql, $mode) { $GLOBALS['queries'][] = $sql; return array(); }
}
$wpdb = new FakeWpdb();

require __DIR__ . '/../admin/class-dashboard-page.php';

$passed = 0;
function check($cond, $label) {
    if (!$cond) { fwrite(STDERR, "FAIL - $label\n"); exit(1); }
    $GLOBALS['passed']++;
}
$find = new ReflectionMethod('MG_Dashboard_Page', 'find_generated_products');
$find->setAccessible(true);

// Main category: the term and all its subcategories.
$queries = array();
$data = $find->invoke(null, array('cat' => 10, 's' => 'apa', 'status' => 'publish'));
$count_sql = $queries[0];
check(strpos($count_sql, "tt.taxonomy = 'product_cat' AND tt.term_id IN (10,11,12)") !== false, 'main category includes its subcategories');
check(strpos($count_sql, "p.post_status = 'publish'") !== false && strpos($count_sql, "p.post_title LIKE '%apa%'") !== false, 'other filters keep their values');
check(strpos($count_sql, "pmsku.meta_value LIKE 'FORME%'") !== false && strpos($count_sql, "pmsku.meta_value LIKE 'POLO%'") !== false, 'generator SKU filters keep their values after the category');
check(preg_match('/LIMIT 20 OFFSET 0$/', $queries[1]) === 1, 'paging parameters last');
check($data['total'] === 3, 'total from the count query');

// Subcategory: just that term (and anything below it).
$queries = array();
$find->invoke(null, array('cat' => 11));
check(strpos($queries[0], 'tt.term_id IN (11)') !== false, 'subcategory filter');

// No category: no taxonomy subquery.
$queries = array();
$find->invoke(null, array());
check(strpos($queries[0], 'term_taxonomy') === false, 'no category, no subquery');

echo "Dashboard filter: $passed checks passed.\n";
