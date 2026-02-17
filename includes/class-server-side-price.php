<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Simple HTML-only approach: modifies the price HTML output using WooCommerce filter.
 * Does NOT touch WooCommerce objects - only modifies the final HTML string.
 * Astra Pro compatible.
 */
class MG_Server_Side_Price {

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // Modify price HTML output (safe - only HTML string, no WC object modification)
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'modify_price_html'), 999, 2);
        
        // Modify product title HTML
        add_filter('the_title', array(__CLASS__, 'modify_title'), 10, 2);
    }

    /**
     * Modify the price HTML output
     */
    public static function modify_price_html($price_html, $product) {
        // Only on single product pages
        if (!is_product()) {
            return $price_html;
        }

        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return $price_html;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);

        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            return $price_html;
        }

        $type_data = $config['types'][$requested_type];
        
        // Get variant price
        $variant_price = isset($type_data['price']) && $type_data['price'] > 0 
            ? (float) $type_data['price'] 
            : (float) $product->get_price();

        // Build new price HTML
        $currency = get_woocommerce_currency_symbol();
        $formatted = number_format($variant_price, 0, '', ' ');
        
        $new_html = '<p class="price">';
        $new_html .= '<span class="woocommerce-Price-amount amount">';
        $new_html .= '<bdi>' . $formatted . '&nbsp;<span class="woocommerce-Price-currencySymbol">' . $currency . '</span></bdi>';
        $new_html .= '</span>';
        $new_html .= '</p>';

        return $new_html;
    }

    /**
     * Modify product title
     */
    public static function modify_title($title, $id = null) {
        if (!is_product() || !in_the_loop() || !is_main_query()) {
            return $title;
        }

        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return $title;
        }

        global $product;
        if (!$product) {
            return $title;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);

        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            return $title;
        }

        $type_data = $config['types'][$requested_type];
        $type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;

        // Add type label if not already present
        if (strpos($title, ' - ' . $type_label) === false) {
            return $title . ' - ' . $type_label;
        }

        return $title;
    }
}
