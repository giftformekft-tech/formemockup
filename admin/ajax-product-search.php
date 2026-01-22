<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_mg_search_products', function(){
    // Nonce & capability
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'mg_search_nonce')) {
        wp_send_json_error(array('message'=>'Érvénytelen kérés (nonce).'), 401);
    }
    if (!current_user_can('edit_products')) {
        wp_send_json_error(array('message'=>'Nincs jogosultság a kereséshez.'), 403);
    }
    // Query
    $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    if ($q === '') {
        wp_send_json_success(array()); // empty query -> empty list (no fatal)
    }
    try {
        $args = array(
            'post_type'      => 'product',
            's'              => $q,
            'posts_per_page' => 15,
            'post_status'    => array('publish','draft','pending','private'),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'suppress_filters' => true,
            'no_found_rows'  => true,
        );
        $res = new WP_Query($args);
        $out = array();
        if (!empty($res->posts)) {
            foreach ($res->posts as $p) {
                $out[] = array('id' => intval($p->ID), 'title' => $p->post_title);
            }
        }
        wp_send_json_success($out);
    } catch (Throwable $e) {
        wp_send_json_error(array('message'=>'Keresési hiba: '.$e->getMessage()), 500);
    }
});
