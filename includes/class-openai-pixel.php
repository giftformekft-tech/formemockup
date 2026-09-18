<?php
if (!defined('ABSPATH')) exit;

/** ChatGPT Ads browser measurement; all dispatch is gated by the consent bridge. */
class MG_OpenAI_Pixel {
    const CART_EVENTS = 'mg_openai_cart_events';

    public static function pixel_id() {
        return (string) apply_filters('mg_openai_pixel_id', 'BjMWTWbs5kDjdhkt1jY7j2');
    }

    public static function init() {
        if (self::pixel_id() === '') return;
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('woocommerce_add_to_cart', array(__CLASS__, 'remember_addition'), 100, 3);
        add_action('wc_ajax_mg_openai_events', array(__CLASS__, 'events'));
    }

    public static function enqueue() {
        $config = array(
            'pixelId' => self::pixel_id(),
            'debug' => (bool) apply_filters('mg_openai_pixel_debug', false),
            'endpoint' => WC_AJAX::get_endpoint('mg_openai_events'),
            'checkout' => is_checkout() && !is_order_received_page() && !is_wc_endpoint_url('order-pay'),
            'orderId' => is_order_received_page() ? absint(get_query_var('order-received')) : 0,
        );
        if (is_product()) {
            $product = wc_get_product(get_queried_object_id());
            if ($product) {
                // No initial price: virtual variants can change the visible amount in the browser.
                $config['product'] = self::content($product, 1);
            }
        }
        wp_enqueue_script('mg-openai-pixel', plugins_url('../assets/js/openai-pixel.js', __FILE__), array(), MG_VERSION, false);
        wp_add_inline_script('mg-openai-pixel', 'window.mgOpenAIConfig=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    /** OpenAI uses ISO minor units, independently of WooCommerce display decimals (HUF: 2). */
    public static function amount($value, $currency) {
        $zero = array('BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF');
        $three = array('BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND');
        $decimals = in_array($currency, $zero, true) ? 0 : (in_array($currency, $three, true) ? 3 : 2);
        if (in_array($currency, array('CLF', 'UYW'), true)) $decimals = 4;
        return (int) round((float) $value * pow(10, $decimals));
    }

    private static function content($product, $quantity) {
        return array('id' => (string) $product->get_id(), 'name' => $product->get_name(), 'content_type' => 'product', 'quantity' => (int) $quantity);
    }

    /** WooCommerce calls this only after an item was successfully added. */
    public static function remember_addition($cart_key, $product_id, $quantity) {
        if (!WC()->session || MG_Consent_Bridge::detect_server_consent() !== 'granted') return;
        $events = (array) WC()->session->get(self::CART_EVENTS, array());
        $events[] = array('key' => $cart_key, 'quantity' => (int) $quantity, 'id' => wp_generate_uuid4(), 'time' => time());
        WC()->session->set(self::CART_EVENTS, array_slice($events, -20));
    }

    /** Session/order data is fetched live, never embedded in cacheable page HTML. */
    public static function events() {
        nocache_headers();
        $events = array();
        if (MG_Consent_Bridge::detect_server_consent() !== 'granted') {
            wp_send_json_success(array('events' => $events, 'pending' => false));
            return;
        }
        $currency = get_woocommerce_currency();
        $additions = WC()->session ? (array) WC()->session->get(self::CART_EVENTS, array()) : array();
        if (WC()->cart && WC()->session && ($additions || !empty($_POST['checkout']))) {
            WC()->cart->calculate_totals();
            foreach ($additions as $addition) {
                if (time() - $addition['time'] > 300) continue;
                $item = WC()->cart->get_cart_item($addition['key']);
                if (empty($item['data'])) continue;
                $amount = wc_get_price_including_tax($item['data'], array('qty' => $addition['quantity']));
                $events[] = array('name' => 'items_added', 'id' => $addition['id'], 'data' => array(
                    'type' => 'contents', 'amount' => self::amount($amount, $currency), 'currency' => $currency,
                    'contents' => array(self::content($item['data'], $addition['quantity'])),
                ));
            }
            WC()->session->set(self::CART_EVENTS, array());
            if (!empty($_POST['checkout']) && !WC()->cart->is_empty()) {
                $contents = array();
                foreach (WC()->cart->get_cart() as $item) $contents[] = self::content($item['data'], $item['quantity']);
                $events[] = array('name' => 'checkout_started', 'id' => '', 'data' => array(
                    'type' => 'contents', 'amount' => self::amount(WC()->cart->get_total('edit'), $currency),
                    'currency' => $currency, 'contents' => $contents,
                ));
            }
        }
        $pending = false;
        $id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $key = isset($_POST['order_key']) && is_string($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        $order = $id && $key !== '' ? wc_get_order($id) : false;
        // The unguessable order key is the guest authorization credential, as in WooCommerce.
        if ($order && hash_equals((string) $order->get_order_key(), $key)) {
            if ($order->has_status(array('processing', 'completed'))) {
                $contents = array();
                foreach ($order->get_items() as $item) {
                    $contents[] = array('id' => (string) ($item->get_variation_id() ?: $item->get_product_id()),
                        'quantity' => (int) $item->get_quantity(), 'content_type' => 'product');
                }
                $currency = $order->get_currency();
                $events[] = array('name' => 'order_created', 'id' => 'mg_openai_order_' . $id, 'data' => array(
                    'type' => 'contents', 'amount' => self::amount($order->get_total(), $currency),
                    'currency' => $currency, 'contents' => $contents,
                ));
            } else {
                $pending = $order->has_status(array('pending', 'on-hold'));
            }
        }
        wp_send_json_success(array('events' => $events, 'pending' => $pending));
    }
}
