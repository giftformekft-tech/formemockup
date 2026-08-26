<?php
/**
 * Egyedi termékmezők kosárba rakásának WordPress nélküli regressziós tesztje.
 *
 * Futtatás: php tests/custom-fields-cart-test.php
 */

define('ABSPATH', __DIR__);

$mg_test_notices = array();

function __($text) {
    return $text;
}

function esc_html($text) {
    return (string) $text;
}

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function wc_add_notice($message, $type) {
    global $mg_test_notices;
    $mg_test_notices[] = array($type, $message);
}

function wc_get_product() {
    return new class {
        public function get_price() {
            return 3990;
        }
    };
}

function wc_get_price_to_display($product) {
    return $product->get_price();
}

function wp_json_encode($value) {
    return json_encode($value);
}

class MG_Custom_Fields_Manager {
    public static $fields = array();

    public static function is_custom_product($product_id) {
        return $product_id === 42;
    }

    public static function get_fields_for_product($product_id) {
        return self::is_custom_product($product_id) ? self::$fields : array();
    }
}

require_once dirname(__DIR__) . '/includes/class-custom-fields-frontend.php';

$failures = 0;

function expect_custom_fields($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
        return;
    }
    echo "ok  – {$message}\n";
}

MG_Custom_Fields_Manager::$fields = array(
    array(
        'id' => 'ev_tol',
        'label' => 'Év',
        'type' => 'number',
        'required' => true,
        'validation_min' => '1900',
        'validation_max' => '2100',
    ),
    array(
        'id' => 'ev_ig',
        'label' => 'Évszám',
        'type' => 'number',
        'required' => true,
        'validation_min' => '1900',
        'validation_max' => '2100',
    ),
);

// A gyorsítótárazott termékoldalról érkező kérésben szándékosan nincs nonce.
$_POST = array(
    'mg_custom_fields' => array(
        'ev_tol' => '2024',
        'ev_ig' => '2026',
    ),
);

$valid = MG_Custom_Fields_Frontend::validate_fields(true, 42, 1);
expect_custom_fields($valid === true, 'A helyes egyedi mezők nonce nélkül is átmennek a kosárvalidáción.');
expect_custom_fields(empty($mg_test_notices), 'A helyes kérés nem kap érvénytelen kérés hibaüzenetet.');

$cart_item = MG_Custom_Fields_Frontend::add_cart_item_data(array(), 42, 0);
expect_custom_fields(isset($cart_item['mg_custom_fields']['ev_tol']['value']) && $cart_item['mg_custom_fields']['ev_tol']['value'] === '2024', 'Az első évszám bekerül a kosártételbe.');
expect_custom_fields(isset($cart_item['mg_custom_fields']['ev_ig']['value']) && $cart_item['mg_custom_fields']['ev_ig']['value'] === '2026', 'A második évszám bekerül a kosártételbe.');

$mg_test_notices = array();
$_POST['mg_custom_fields']['ev_tol'] = 'nem szám';
$valid = MG_Custom_Fields_Frontend::validate_fields(true, 42, 1);
expect_custom_fields($valid === false, 'A mezőérték validáció nonce nélkül is elutasítja a hibás számot.');
expect_custom_fields(!empty($mg_test_notices), 'A hibás mezőérték továbbra is célzott hibaüzenetet kap.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} teszt sikertelen.\n");
    exit(1);
}

echo "\nMinden egyedi mező kosárteszt sikeres.\n";
