<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Cart_Pricing {
    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_filter('woocommerce_cart_item_price', array(__CLASS__, 'format_cart_item_price'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array(__CLASS__, 'format_cart_item_subtotal'), 10, 3);
    }

    public static function enqueue_assets() {
        if (!function_exists('is_cart') || !is_cart()) {
            return;
        }
        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_path = dirname(__DIR__) . '/assets/css/cart-pricing.css';
        wp_enqueue_style(
            'mg-cart-pricing',
            plugins_url('assets/css/cart-pricing.css', $base_file),
            array(),
            file_exists($style_path) ? filemtime($style_path) : '1.0.0'
        );
    }

    public static function format_cart_item_price($price, $cart_item, $cart_item_key) {
        if (!function_exists('is_cart') || !is_cart()) {
            return $price;
        }
        $label = esc_html__('Egység ár', 'mockup-generator');
        $surcharge_line = self::get_surcharge_line($cart_item, false);
        $base_price = self::get_base_price($cart_item);
        if ($base_price !== null && isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
            $display_price = wc_get_price_to_display($cart_item['data'], ['qty' => 1, 'price' => $base_price]);
            $price = wc_price($display_price);
        }
        return sprintf(
            '<span class="mg-cart-price"><span class="mg-cart-price__label">%s</span><span class="mg-cart-price__value">%s%s</span></span>',
            $label,
            $price,
            $surcharge_line
        );
    }

    public static function format_cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
        if (!function_exists('is_cart') || !is_cart()) {
            return $subtotal;
        }
        $label = esc_html__('Összesen', 'mockup-generator');
        $surcharge_total = self::get_surcharge_total($cart_item, true);
        if (isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
            $quantity = isset($cart_item['quantity']) ? max(1, intval($cart_item['quantity'])) : 1;
            $base_price = self::get_base_price($cart_item);
            if ($base_price !== null) {
                $base_subtotal = wc_get_price_to_display($cart_item['data'], ['qty' => $quantity, 'price' => $base_price]);
            } else {
                $base_subtotal = wc_get_price_to_display($cart_item['data'], ['qty' => $quantity]);
            }
            if ($surcharge_total > 0) {
                $base_subtotal += $surcharge_total;
            }
            $subtotal = wc_price($base_subtotal);
        }
        return sprintf(
            '<span class="mg-cart-price"><span class="mg-cart-price__label">%s</span><span class="mg-cart-price__value">%s</span></span>',
            $label,
            $subtotal
        );
    }

    private static function get_base_price($cart_item) {
        if (!empty($cart_item['variation_id'])) {
            $variation = wc_get_product($cart_item['variation_id']);
            if ($variation instanceof WC_Product) {
                return floatval($variation->get_price());
            }
        }
        if (isset($cart_item['mg_surcharge_base_price'])) {
            return floatval($cart_item['mg_surcharge_base_price']);
        }
        if (isset($cart_item['mg_custom_fields_base_price'])) {
            return floatval($cart_item['mg_custom_fields_base_price']);
        }
        if (isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
            return floatval($cart_item['data']->get_price());
        }
        return null;
    }

    private static function get_surcharge_total($cart_item, $include_quantity = true) {
        if (empty($cart_item['mg_surcharge_data']) || !is_array($cart_item['mg_surcharge_data'])) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($cart_item['mg_surcharge_data'] as $surcharge) {
            if (empty($surcharge['enabled'])) {
                continue;
            }
            $total += floatval($surcharge['amount']);
        }
        if ($include_quantity) {
            $quantity = isset($cart_item['quantity']) ? max(1, intval($cart_item['quantity'])) : 1;
            $total *= $quantity;
        }
        return $total;
    }

    private static function get_surcharge_line($cart_item, $include_quantity = false) {
        $total = self::get_surcharge_total($cart_item, $include_quantity);
        if ($total <= 0) {
            return '';
        }
        return sprintf(
            '<span class="mg-cart-price__surcharge">+ %s</span>',
            wc_price($total)
        );
    }
}
