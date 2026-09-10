<?php
/** Focused outlet integration with WordPress/WooCommerce doubles; no live checkout/database. */
define('ABSPATH', __DIR__ . '/');
$options = $products = $meta = array();
$options['woocommerce_manage_stock'] = 'yes';
$permissions = true;
$checks = 0;
function check($value, $message) { $GLOBALS['checks']++; if (!$value) throw new RuntimeException($message); }
function absint($v) { return abs((int) $v); }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function add_option($key, $value, ...$args) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key] = $value; return true; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function current_user_can(...$args) { return $GLOBALS['permissions']; }
function get_current_user_id() { return 9; }
function sanitize_textarea_field($v) { return trim(strip_tags($v)); }
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function sanitize_title($v) { return strtolower(trim($v)); }
function wp_unslash($v) { return stripslashes($v); }
function wc_format_decimal($v) { return str_replace(',', '.', $v); }
function wc_get_product($id) { return isset($GLOBALS['products'][$id]) ? clone $GLOBALS['products'][$id] : false; }
function wp_attachment_is_image($id) { return $id === 7; }
function get_edit_post_link($id, $context = '') { return '/edit/' . $id; }
function get_permalink($id) { return '/product/' . $id; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function check_ajax_referer($action, $key) { if (($_POST[$key] ?? '') !== $action) wp_send_json_error(array('message' => 'nonce'), 403); }
function wp_send_json_error($data, $code = 400) { throw new OutletResponse(false, $data, $code); }
function wp_send_json_success($data) { throw new OutletResponse(true, $data, 200); }
class OutletResponse extends RuntimeException {
    public $success; public $data;
    public function __construct($success, $data, $code) { parent::__construct('', $code); $this->success=$success; $this->data=$data; }
}
class WP_Error { private $message; public function __construct($code, $message) { $this->message=$message; } public function get_error_message() { return $this->message; } }
class WC_Product {
    public $id = 0; public $props = array(); public $meta = array();
    public function __call($method, $args) {
        if (strpos($method, 'set_') === 0) { $this->props[substr($method, 4)]=$args[0]; return; }
        if (strpos($method, 'get_') === 0) return $this->props[substr($method, 4)] ?? '';
        throw new RuntimeException($method);
    }
    public function get_id() { return $this->id; }
    public function is_type($type) { return $type === 'simple'; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key]=$value; }
    public function save() {
        MG_Outlet::enforce_stock($this);
        if (!$this->id) $this->id = count($GLOBALS['products']) + 100;
        $this->props['price'] = $this->props['regular_price'] ?? '';
        $GLOBALS['meta'][$this->id]=$this->meta;
        $GLOBALS['products'][$this->id]=clone $this;
        return $this->id;
    }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Attribute extends WC_Product {}
class WC_Order_Item_Product extends WC_Product {
    public function get_product_id() { return $this->props['product_id']; }
    public function save_meta_data() {}
}
require __DIR__ . '/../includes/class-outlet.php';
require __DIR__ . '/../includes/class-virtual-variant-manager.php';
require __DIR__ . '/../includes/class-google-merchant-feed.php';
require __DIR__ . '/../includes/class-facebook-catalog-feed.php';
require __DIR__ . '/../includes/class-custom-feed-manager.php';
require __DIR__ . '/../includes/class-price-override.php';
require __DIR__ . '/../includes/class-server-side-price.php';
require __DIR__ . '/../includes/class-size-selection.php';
require __DIR__ . '/../includes/class-catalog-integration.php';
require __DIR__ . '/../includes/class-supplier-export.php';

$source = new WC_Product_Simple(); $source->id=1;
$source->props = array('status'=>'publish', 'name'=>'Minta', 'price'=>'6990', 'regular_price'=>'6990', 'tax_status'=>'taxable', 'tax_class'=>'', 'weight'=>'0.2');
$products[1] = clone $source;
$config = array('types'=>array('shirt'=>array('label'=>'Férfi póló', 'colors'=>array('black'=>array('label'=>'Fekete', 'sizes'=>array('M','XL')), 'white'=>array('label'=>'Fehér', 'sizes'=>array('S'))))));
$cache = new ReflectionProperty(MG_Virtual_Variant_Manager::class, 'config_cache');
$cache->setAccessible(true); $cache->setValue(null, array(1=>$config));
check(!is_wp_error(MG_Outlet::validate_selection($config, 'shirt','black','XL')), 'valid combination');
check(is_wp_error(MG_Outlet::validate_selection($config, 'shirt','white','XL')), 'per-color size restriction');
check(is_wp_error(MG_Outlet::validate_selection($config, 'hoodie','black','XL')), 'unknown type blocked');
$base = array('product_id'=>1, 'nonce'=>'mg_create_outlet_1', 'type'=>'shirt', 'color'=>'black', 'size'=>'XL', 'qty'=>'1', 'price'=>'3990', 'note'=>'Hibátlan. Felirat: Anna.', 'image_id'=>7, 'request'=>'12345678-1234-1234-1234-123456789abc');
function create_request($overrides=array()) {
    $_POST=array_merge($GLOBALS['base'], $overrides);
    try { MG_Outlet::ajax_create(); } catch (OutletResponse $r) { return $r; }
    throw new RuntimeException('Missing JSON response');
}
$permissions=false;
check(!create_request()->success && count($products)===1, 'permission failure creates nothing');
$permissions=true;
check(create_request(array('nonce'=>'bad'))->getCode()===403, 'nonce required');
$options['woocommerce_manage_stock']='no';
check(!create_request()->success && count($products)===1, 'global WooCommerce stock management required');
$options['woocommerce_manage_stock']='yes';
foreach (array(array('qty'=>'1.5'), array('qty'=>'0'), array('price'=>'0'), array('price'=>'-3'), array('price'=>'abc'), array('size'=>'XXXL'), array('image_id'=>88)) as $bad) {
    check(!create_request($bad)->success && count($products)===1, 'invalid input creates no stock: '.json_encode($bad));
}
$result = create_request();
check($result->success && count($products)===2, 'creates exactly one product');
$id = array_key_last($products); $outlet=wc_get_product($id);
check($outlet->get_status()==='publish', 'published');
check($outlet->get_price()==='3990' && $products[1]->get_price()==='6990', 'independent price; source untouched');
check($outlet->get_manage_stock()===true && $outlet->get_stock_quantity()===1 && $outlet->get_backorders()==='no', 'native managed stock without backorders');
check($outlet->get_catalog_visibility()==='hidden' && $outlet->get_virtual()===false, 'hidden finished physical product');
check($outlet->get_image_id()===7 && $outlet->get_short_description()===$base['note'], 'selected photo and existing print note retained');
check($outlet->get_sku()==='OUTLET-'.$id && $outlet->get_meta('_mg_outlet_source')===1, 'unique SKU and source link');
check(create_request()->success && count($products)===2, 'retry does not duplicate stock');
check(!create_request(array('product_id'=>$id, 'nonce'=>'mg_create_outlet_'.$id))->success, 'cannot create from outlet');
$outlet->set_stock_quantity(0); $outlet->set_manage_stock(false); $outlet->set_backorders('yes'); $outlet->save();
check($outlet->get_stock_status()==='outofstock' && $outlet->get_manage_stock()===true && $outlet->get_backorders()==='no', 'zero stock and invariants on edits');
$outlet->set_stock_quantity(1); $outlet->save();
check($outlet->get_stock_status()==='instock', 'restocked item available again');
$payload=array('mg_product_type'=>'hoodie','mg_size'=>'XXL','mg_custom_fields_base_price'=>1,'mg_crosssell_rule_id'=>'cheap','other'=>'keep');
check(MG_Outlet::cart_data($payload,$id)===array('other'=>'keep'), 'client variant and price tampering removed');
check(MG_Outlet::cart_data($payload,1)===$payload, 'normal cart payload untouched');
check(!isset(MG_Outlet::restore_cart($payload+array('product_id'=>$id),array())['mg_size']), 'session payload cleaned');
$line=clone $outlet; $line->set_price(1);
$normal=clone $source; $normal->set_price(5000);
$cart=new class($line,$normal,$id) {
    private $lines; public function __construct($outlet,$normal,$id) { $this->lines=array(array('product_id'=>$id,'data'=>$outlet),array('product_id'=>1,'data'=>$normal)); }
    public function get_cart() { return $this->lines; }
};
MG_Outlet::cart_prices($cart);
check($line->get_price()==='3990' && $normal->get_price()===5000, 'fixed current outlet price; mixed normal cart unchanged');
$item=new WC_Order_Item_Product(); $item->props['product_id']=$id;
MG_Outlet::order_item($item,'',array(),null);
check($item->get_meta('Outlet – készáru')==='Férfi póló · Fekete · XL', 'order snapshot includes fixed combination');
unset($meta[$id]);
check(MG_Outlet::is_outlet_item($item), 'export exclusion survives deletion of product');
$meta[$id]=$outlet->meta;
check(MG_Virtual_Variant_Manager::get_frontend_config($outlet)===array(), 'no global virtual selector config for outlet');
check(MG_Virtual_Variant_Manager::validate_add_to_cart(true,$id,1), 'no virtual selection required at add to cart');
foreach (array('MG_Google_Merchant_Feed','MG_Facebook_Catalog_Feed','MG_Custom_Feed_Manager') as $class) {
    $method=new ReflectionMethod($class,'get_product_xml'); $method->setAccessible(true);
    check($method->invoke(null,$id,array())==='', $class.' excludes outlet');
}
$_GET['mg_type']='hoodie';
check(MG_Price_Override::override_price('3990',$outlet)==='3990', 'URL cannot change price');
check(MG_Server_Side_Price::modify_price_html('3990 Ft',$outlet)==='3990 Ft', 'URL cannot change rendered price');
check(MG_Catalog_Integration::append_default_variant_param('/outlet-product',$outlet)==='/outlet-product', 'no virtual URL injected');
check(MG_Size_Selection::add_cart_item_data(array(),$id,0)===array(), 'no size surcharge payload');
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
$normal_item = new WC_Order_Item_Product();
$normal_item->props = array('product_id'=>1, 'quantity'=>2, 'name'=>'Normal');
$normal_item->meta = array('mg_product_type'=>'shirt', 'mg_color'=>'black', 'mg_size'=>'XL');
$item->props['quantity']=1;
$orders[9] = new class($normal_item,$item) {
    private $items; public function __construct(...$items) { $this->items=$items; }
    public function get_items() { return $this->items; }
};
$options['mg_products']=array(array('key'=>'shirt','utt_skus'=>array('black'=>'SHIRT-BLACK')));
$plan=MG_Supplier_Export::build_plan(array(9));
check($plan['lines']['SHIRT-BLACK-xl']['order']===2 && !$plan['skipped'] && !$plan['missing_sku'], 'mixed supplier plan contains only normal quantities');
$orders[10]=new class($item) {
    private $item; public function __construct($item) { $this->item=$item; }
    public function get_items() { return array($this->item); }
};
$plan=MG_Supplier_Export::build_plan(array(10));
check(!$plan['lines'] && !$plan['local'] && !$plan['missing_sku'], 'outlet-only order needs no supplier or blank garment stock');
if (in_array('--fixture', $argv, true)) {
    function esc_attr($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    function esc_html($v) { return esc_attr($v); }
    function esc_url($v) { return esc_attr($v); }
    function wp_create_nonce($v) { return $v; }
    function wp_generate_uuid4() { return '12345678-1234-1234-1234-123456789abc'; }
    function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
    function get_woocommerce_currency() { return 'HUF'; }
    function wc_print_notices($return=false) { return ''; }
    function wp_strip_all_tags($v) { return strip_tags($v); }
    function remove_query_arg($v) { return '/outlet/'; }
    function selected($a,$b,$echo=false) { return $a===$b ? ' selected' : ''; }
    class MG_Variant_Display_Manager { public static function get_catalog_index() { return $GLOBALS['config']['types']; } }
    class WP_Query {
        public $posts; public $max_num_pages=1;
        public function __construct($args) { $this->posts=array((object)array('ID'=>$GLOBALS['id'])); }
        public function have_posts() { return true; }
    }
    $wpdb=new class {
        public $postmeta='meta'; public $posts='posts';
        public function prepare($query,...$args) { return $query; }
        public function get_results($query) { return array((object)array('meta_key'=>'_mg_outlet_size','meta_value'=>'XL')); }
    };
    // Minimal template methods on a fixture product, while rendering the real PHP shortcode.
    $fixture=new class extends WC_Product_Simple {
        public function is_in_stock() { return true; }
        public function is_purchasable() { return true; }
        public function get_image($size) { return '<img alt="Póló" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'400\' height=\'400\'%3E%3Crect width=\'400\' height=\'400\' fill=\'%23eeeeee\'/%3E%3Cpath d=\'M130 80L60 140L105 195L130 170V330H270V170L295 195L340 140L270 80Z\' fill=\'%2327272a\'/%3E%3C/svg%3E">'; }
        public function get_price_html() { return '3 990 Ft'; }
        public function get_permalink() { return '/product/outlet-shirt'; }
        public function add_to_cart_url() { return '/outlet/?add-to-cart=101'; }
    };
    $fixture->id=$id; $fixture->props=$outlet->props; $fixture->meta=$outlet->meta; $products[$id]=$fixture;
    echo '<!doctype html><html lang="hu"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font-family:Arial;margin:20px}.button{padding:12px;background:#222;color:white;border:0;border-radius:6px;text-decoration:none}input,select,textarea{padding:8px;max-width:100%;box-sizing:border-box}#admin{margin-top:60px;max-width:700px}.widefat{width:100%}</style><style>' . file_get_contents(__DIR__.'/../assets/css/outlet.css') . '</style><h1>Outlet</h1>';
    echo MG_Outlet::shortcode();
    echo '<section id="admin"><h1>Outlet darab létrehozása</h1>';
    MG_Outlet::render_box((object)array('ID'=>1,'post_status'=>'publish'));
    echo '</section></html>';
} else {
    echo "Outlet: $checks assertions passed\n";
}
