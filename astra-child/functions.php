<?php
/**
 * Astra Child Theme functions
 *
 * @package Astra Child
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Enqueue parent and child theme styles
 */
function astra_child_enqueue_styles() {
    // Enqueue parent Astra theme stylesheet
    wp_enqueue_style('astra-parent-style', get_template_directory_uri() . '/style.css');
    
    // Enqueue child theme stylesheet
    wp_enqueue_style(
        'astra-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array('astra-parent-style'),
        wp_get_theme()->get('Version')
    );
}
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles', 15);

/**
 * Theme setup
 */
function astra_child_theme_setup() {
    // Add WooCommerce support if not already present
    if (!current_theme_supports('woocommerce')) {
        add_theme_support('woocommerce');
    }
}
add_action('after_setup_theme', 'astra_child_theme_setup');
