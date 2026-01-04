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
        return sprintf(
            '<span class="mg-cart-price"><span class="mg-cart-price__label">%s</span><span class="mg-cart-price__value">%s</span></span>',
            $label,
            $price
        );
    }

    public static function format_cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
        if (!function_exists('is_cart') || !is_cart()) {
            return $subtotal;
        }
        $label = esc_html__('Összesen', 'mockup-generator');
        return sprintf(
            '<span class="mg-cart-price"><span class="mg-cart-price__label">%s</span><span class="mg-cart-price__value">%s</span></span>',
            $label,
            $subtotal
        );
    }
}
