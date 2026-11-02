<?php
/**
 * Rendelés szerkesztés – tételenkénti darabszám módosítás, WordPress nélkül.
 *
 * Lefedi, hogy az egységár (és a soron lévő kedvezmény) megmarad, a rendelés
 * újraszámolódik, és a nem utánvétes / visszatérített rendelés nem módosítható.
 *
 * Futtatás: php tests/order-item-qty-test.php
 */

define('ABSPATH', __DIR__);

class MG_Test_Json_Exit extends Exception {
    public $success;
    public $data;
    public function __construct($success, $data) {
        $this->success = $success;
        $this->data = $data;
        parent::__construct('json');
    }
}

function __($text) { return $text; }
function add_action() {}
function apply_filters($tag, $value) { return $value; }
function do_action() {}
function check_ajax_referer() { return true; }
function current_user_can() { return true; }
function absint($v) { return abs((int) $v); }
function wp_strip_all_tags($v) { return strip_tags((string) $v); }
function wc_price($v) { return number_format((float) $v, 0, ',', ' ') . ' Ft'; }
function wc_get_rounding_precision() { return 6; }
function current_time() { return '2026-11-02 10:00:00'; }
function get_current_user_id() { return 7; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wc_get_order_status_name($s) { return $s; }
function wp_send_json_success($data) { throw new MG_Test_Json_Exit(true, $data); }
function wp_send_json_error($data) { throw new MG_Test_Json_Exit(false, $data); }

class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class WC_Order_Item_Product {
    public $id, $order_id, $qty, $subtotal, $total, $meta = array(), $saved = 0;
    public function __construct($id, $order_id, $qty, $subtotal, $total) {
        $this->id = $id; $this->order_id = $order_id; $this->qty = $qty;
        $this->subtotal = $subtotal; $this->total = $total;
    }
    public function get_order_id() { return $this->order_id; }
    public function get_quantity() { return $this->qty; }
    public function set_quantity($q) { $this->qty = $q; }
    public function get_subtotal() { return $this->subtotal; }
    public function set_subtotal($v) { $this->subtotal = $v; }
    public function get_total() { return $this->total; }
    public function set_total($v) { $this->total = $v; }
    public function get_total_tax() { return round($this->total * 0.27, 2); }
    public function get_name() { return 'Póló Teszt'; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function save() { $this->saved++; }
}

class WC_Order {
    public $items = array(), $notes = array(), $meta = array(), $payment = 'cod', $status = 'processing', $refunded = 0.0;
    public function get_payment_method() { return $this->payment; }
    public function get_payment_method_title() { return $this->payment === 'cod' ? 'Utánvét' : 'Banki átutalás'; }
    public function get_status() { return $this->status; }
    public function get_total_refunded() { return $this->refunded; }
    public function get_items() { return $this->items; }
    public function get_item($id) { return $this->items[$id] ?? false; }
    public function calculate_taxes() {}
    public function calculate_totals() {}
    public function get_total() {
        $sum = 0.0;
        foreach ($this->items as $item) { $sum += $item->total * 1.27; }
        return $sum;
    }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function save() {}
    public function add_order_note($note, $customer = false) { $this->notes[] = array($note, $customer); }
}

class WC_Order_Factory {
    public static function get_order_item($id) {
        global $mg_order;
        return $mg_order->get_item($id);
    }
}

function wc_get_order($id) {
    global $mg_order;
    return $mg_order;
}

require_once dirname(__DIR__) . '/admin/class-order-add-item.php';

$failures = 0;

function expect_same($expected, $actual, $message) {
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n\n");
        return;
    }
    echo "ok  – {$message}\n";
}

function run_qty_request($item_id, $qty, $notify = 1) {
    $_POST = array('item_id' => $item_id, 'qty' => $qty, 'notify' => $notify, 'nonce' => 'x');
    try {
        MG_Order_Add_Item::ajax_update_item_qty();
    } catch (MG_Test_Json_Exit $e) {
        return $e;
    }
    return null;
}

function fresh_order() {
    global $mg_order;
    $mg_order = new WC_Order();
    // 2 db, 4000 Ft nettó listaár, 3000 Ft a kedvezmény után.
    $mg_order->items[11] = new WC_Order_Item_Product(11, 5, 2, 4000.0, 3000.0);
    $mg_order->items[11]->meta = array('Terméktípus' => 'Póló', 'Szín' => 'Fekete', 'Méret' => 'L');
    return $mg_order;
}

/* --------------------------------------------------------- scale_line_amounts */
expect_same(
    array('subtotal' => 6000.0, 'total' => 4500.0),
    MG_Order_Add_Item::scale_line_amounts(4000, 3000, 2, 3),
    'unit price and per-piece discount are kept when scaling up'
);
expect_same(
    array('subtotal' => 1000.0, 'total' => 1000.0),
    MG_Order_Add_Item::scale_line_amounts(3000, 3000, 3, 1),
    'scaling down to one piece'
);

/* ------------------------------------------------------- ajax_update_item_qty */
$order = fresh_order();
$res = run_qty_request(11, 3);
expect_same(true, $res->success, 'quantity change succeeds on a COD order');
expect_same(3, $order->items[11]->qty, 'line quantity updated');
expect_same(6000.0, $order->items[11]->subtotal, 'line subtotal scaled');
expect_same(4500.0, $order->items[11]->total, 'line total scaled, discount kept');
expect_same(1, $order->items[11]->saved, 'line item saved');
expect_same(2, count($order->notes), 'admin note + customer note added');
expect_same(true, strpos($order->notes[0][0], '2 db → 3 db') !== false, 'admin note shows old → new quantity');
expect_same(true, $order->notes[1][1], 'second note goes to the customer');

$order = fresh_order();
run_qty_request(11, 3, 0);
expect_same(1, count($order->notes), 'no customer note when notify is off');

$order = fresh_order();
$res = run_qty_request(11, 2);
expect_same(false, $res->success, 'unchanged quantity is rejected');

$order = fresh_order();
$res = run_qty_request(11, 0);
expect_same(false, $res->success, 'zero quantity is rejected');
expect_same(2, $order->items[11]->qty, 'line untouched after rejected request');

$order = fresh_order();
$order->payment = 'bacs';
$res = run_qty_request(11, 3);
expect_same(false, $res->success, 'non-COD order cannot be changed');

$order = fresh_order();
$order->refunded = 100.0;
$res = run_qty_request(11, 3);
expect_same(false, $res->success, 'order with a refund cannot be changed');

$order = fresh_order();
$order->status = 'manufacturing';
$res = run_qty_request(11, 1);
expect_same(true, $res->success, 'allowed while in production');
expect_same('yes', $order->meta['_mg_items_qty_changed_after_export'] ?? null, 'in-production change is flagged on the order');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} test(s) failed.\n");
    exit(1);
}
echo "\nAll tests passed.\n";
