<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Uses output buffering to replace base price/title in final HTML output.
 * This is the ONLY guaranteed way to modify content before it reaches the browser.
 */
class MG_Server_Side_Price {

    private static $variant_price = null;
    private static $variant_title = null;
    private static $base_price = null;
    private static $base_title = null;

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // Start output buffer at template_redirect (before wp_head)
        add_action('template_redirect', array(__CLASS__, 'start_buffer'), 1);
    }

    public static function start_buffer() {
        if (!is_product()) {
            return;
        }

        // Load variant config
        global $post, $product;
        if (!$post) {
            return;
        }

        if (!$product) {
            $product = wc_get_product($post->ID);
        }
        if (!$product || !class_exists('MG_Virtual_Variant_Manager')) {
            return;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);

        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            return;
        }

        $type_data = $config['types'][$requested_type];

        // Store prices for replacement
        self::$base_price = (float) $product->get_price();
        if (isset($type_data['price']) && $type_data['price'] > 0) {
            self::$variant_price = (float) $type_data['price'];
        }

        // Store titles
        self::$base_title = $product->get_name();
        $type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;
        self::$variant_title = self::$base_title . ' - ' . $type_label;

        // Start output buffering
        ob_start(array(__CLASS__, 'replace_content'));
    }

    public static function replace_content($html) {
        // Only proceed if we have data to replace
        if (self::$variant_price === null && self::$variant_title === null) {
            return $html;
        }

        // Replace price if different
        if (self::$variant_price !== null && self::$base_price != self::$variant_price) {
            $base_formatted = number_format(self::$base_price, 0, '', ' ');
            $variant_formatted = number_format(self::$variant_price, 0, '', ' ');

            // Replace in price HTML elements (<bdi>4 990&nbsp;...)
            $html = preg_replace(
                '/(<bdi>)' . preg_quote($base_formatted, '/') . '(\s*&nbsp;)/u',
                '${1}' . $variant_formatted . '${2}',
                $html
            );

            // Replace in gtag/analytics (value: 4990.000000 or price: 4990.00)
            $base_decimal = number_format(self::$base_price, 2, '.', '');
            $variant_decimal = number_format(self::$variant_price, 2, '.', '');
            
            $html = str_replace('value: ' . $base_decimal, 'value: ' . $variant_decimal, $html);
            $html = str_replace('price: ' . $base_decimal, 'price: ' . $variant_decimal, $html);
            
            // Also .000000 format
            $html = str_replace('value: ' . (int)self::$base_price . '.000000', 'value: ' . (int)self::$variant_price . '.000000', $html);
            $html = str_replace('price: ' . (int)self::$base_price . '.000000', 'price: ' . (int)self::$variant_price . '.000000', $html);
        }

        // Replace title
        if (self::$variant_title !== null) {
            // Replace in <h1 class="product_title">
            $html = preg_replace(
                '/(<h1[^>]*class="[^"]*product_title[^"]*"[^>]*>)' . preg_quote(self::$base_title, '/') . '(<\/h1>)/ui',
                '${1}' . self::$variant_title . '${2}',
                $html
            );

            // Replace in <title> tag (in case structured data doesn't override it)
            $html = preg_replace(
                '/(<title[^>]*>)([^<]*' . preg_quote(self::$base_title, '/') . '[^<]*)(<\/title>)/ui',
                '${1}' . self::$variant_title . ' – ' . get_bloginfo('name') . '${3}',
                $html
            );
        }

        return $html;
    }
}
