<?php
if (!defined('ABSPATH')) {
    exit;
}

$ajax_bulk = plugin_dir_path(__FILE__) . 'ajax-bulk.php';
if (file_exists($ajax_bulk)) {
    require_once $ajax_bulk;
}

$ajax_search = plugin_dir_path(__FILE__) . 'ajax-product-search.php';
if (file_exists($ajax_search)) {
    require_once $ajax_search;
}

require_once plugin_dir_path(__FILE__) . 'class-dashboard-page.php';

class MG_Admin_Page {
    const MAIN_SLUG = 'mockup-generator';

    public static function add_menu_page() {
        add_menu_page(
            'Mockup Generator',
            'Mockup Generator',
            'edit_products',
            self::MAIN_SLUG,
            [self::class, 'render_page'],
            'dashicons-art'
        );
    }

    private static function get_tabs() {
        return array(
            'dashboard' => array(
                'label' => __('Dashboard', 'mockup-generator'),
                'slug'  => 'mockup-generator-dashboard',
            ),
            'mockups' => array(
                'label' => __('Mockupok', 'mockup-generator'),
                'slug'  => 'mockup-generator-bulk',
            ),
            'variants' => array(
                'label' => __('Variánsok', 'mockup-generator'),
                'slug'  => 'mockup-generator-variant-display',
            ),
            'regenerate' => array(
                'label' => __('Regenerálás', 'mockup-generator'),
                'slug'  => 'mockup-generator-maintenance',
            ),
            'surcharges' => array(
                'label' => __('Felárak', 'mockup-generator'),
                'slug'  => 'mockup-generator-surcharges',
            ),
            'settings' => array(
                'label' => __('Beállítások', 'mockup-generator'),
                'slug'  => 'mockup-generator-settings',
            ),
            'logs' => array(
                'label' => __('Logok', 'mockup-generator'),
                'slug'  => '',
            ),
        );
    }

    private static function get_tab_url($slug) {
        if ($slug === '') {
            return '';
        }
        return admin_url('admin.php?page=' . $slug);
    }

    public static function render_page() {
        if (!current_user_can('edit_products')) {
            wp_die(__('Nincs jogosultságod a Mockup Generator megnyitásához.', 'mockup-generator'));
        }

        $tabs = self::get_tabs();
        $requested = isset($_GET['mg_tab']) ? sanitize_key(wp_unslash($_GET['mg_tab'])) : '';
        $current = $requested && isset($tabs[$requested]) ? $requested : 'dashboard';

        $css_path = plugin_dir_path(__FILE__) . '../assets/css/admin-ui.css';
        $js_path  = plugin_dir_path(__FILE__) . '../assets/js/admin-ui.js';

        wp_enqueue_style(
            'mg-admin-ui',
            plugins_url('../assets/css/admin-ui.css', __FILE__),
            array(),
            file_exists($css_path) ? filemtime($css_path) : '1.0.0'
        );
        wp_enqueue_script(
            'mg-admin-ui',
            plugins_url('../assets/js/admin-ui.js', __FILE__),
            array('jquery'),
            file_exists($js_path) ? filemtime($js_path) : '1.0.0',
            true
        );
        wp_localize_script(
            'mg-admin-ui',
            'MG_ADMIN_UI',
            array(
                'defaultTab' => $current,
                'hashPrefix' => '#',
            )
        );

        echo '<div class="mg-admin-shell">';
        echo '<header class="mg-admin-header">';
        echo '<div class="mg-admin-title">';
        echo '<span class="dashicons dashicons-art" aria-hidden="true"></span>';
        echo '<div class="mg-admin-heading">';
        echo '<h1>Mockup Generator</h1>';
        echo '<p class="mg-admin-sub">' . esc_html__('Egységes admin felület gyors tabváltással.', 'mockup-generator') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</header>';

        echo '<nav class="mg-tabbar" role="tablist">';
        foreach ($tabs as $id => $tab) {
            $is_active = $id === $current ? ' is-active' : '';
            printf(
                '<button type="button" id="mg-tab-%1$s" class="mg-tab%3$s" data-tab="%1$s" role="tab">%2$s</button>',
                esc_attr($id),
                esc_html($tab['label']),
                esc_attr($is_active)
            );
        }
        echo '</nav>';

        echo '<div class="mg-tabpanels">';
        foreach ($tabs as $id => $tab) {
            $panel_id = 'tab-' . $id;
            $url = self::get_tab_url($tab['slug']);
            $panel_classes = 'mg-panel' . ($id === $current ? ' is-active' : '');
            echo '<section id="' . esc_attr($panel_id) . '" class="' . esc_attr($panel_classes) . '" role="tabpanel" aria-labelledby="mg-tab-' . esc_attr($id) . '">';
            if ($url) {
                $iframe_attrs = array(
                    'class' => 'mg-panel-frame',
                    'title' => $tab['label'],
                );
                if ($id === $current) {
                    $iframe_attrs['src'] = $url;
                } else {
                    $iframe_attrs['data-src'] = $url;
                }
                $attr_html = '';
                foreach ($iframe_attrs as $attr => $value) {
                    $attr_html .= ' ' . esc_attr($attr) . '="' . esc_attr($value) . '"';
                }
                echo '<iframe' . $attr_html . '></iframe>';
            } else {
                echo '<div class="mg-panel-placeholder">';
                echo '<h2>' . esc_html__('Logok', 'mockup-generator') . '</h2>';
                echo '<p>' . esc_html__('A napló nézet hamarosan érkezik. Addig a log fájlok a wp-content/uploads/mockup-generator mappában találhatók.', 'mockup-generator') . '</p>';
                echo '</div>';
            }
            echo '</section>';
        }
        echo '</div>';

        echo '<footer class="mg-save-bar" aria-hidden="true">';
        echo '<div class="mg-save-bar__inner">';
        echo '<span class="mg-save-bar__message">' . esc_html__('Nem mentett módosítások', 'mockup-generator') . '</span>';
        echo '<button type="button" class="button button-primary mg-save-bar__submit">' . esc_html__('Mentés', 'mockup-generator') . '</button>';
        echo '</div>';
        echo '</footer>';
        echo '</div>';
    }
}

