<?php
if (!defined('ABSPATH')) exit;

class MG_Designer_Button {
    const OPTION_KEY = 'mg_designer_button';

    public static function init() {
        add_action('woocommerce_after_add_to_cart_button', array(__CLASS__, 'render_button'), 20);
    }

    public static function get_settings() {
        $defaults = array('enabled' => true);
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return wp_parse_args($stored, $defaults);
    }

    public static function sanitize_settings($input) {
        $clean = array(
            'enabled' => !empty($input['enabled']),
        );
        update_option(self::OPTION_KEY, $clean);
        return $clean;
    }

    public static function render_button() {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        global $product;
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $product_id = $product->get_parent_id() ?: $product->get_id();

        if (!self::product_has_saved_design($product_id)) {
            return;
        }

        $url = self::get_designer_url($product_id);
        if ($url === '') {
            return;
        }

        echo '<a href="' . esc_url($url) . '" class="mg-designer-edit-button button">' . esc_html__('Szerkesztés a designerben', 'mockup-generator') . '</a>';
    }

    protected static function product_has_saved_design($product_id) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return false;
        }
        $attachment_id = get_post_meta($product_id, '_mg_last_design_attachment', true);
        if (!empty($attachment_id)) {
            return true;
        }
        $design_path = get_post_meta($product_id, '_mg_last_design_path', true);
        return is_string($design_path) && $design_path !== '';
    }

    public static function get_designer_url($product_id) {
        $page_url = self::locate_designer_page_url();
        if ($page_url === '') {
            return '';
        }
        return add_query_arg('nb_product', absint($product_id), $page_url);
    }

    protected static function locate_designer_page_url() {
        $cached = get_transient('mg_designer_page_url');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $like = $wpdb->esc_like('[nb_designer') . '%';
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            $like
        ));

        $url = $page_id ? get_permalink($page_id) : '';
        set_transient('mg_designer_page_url', $url, HOUR_IN_SECONDS);

        return $url;
    }
}
