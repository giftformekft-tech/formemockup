<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consolidates the plugin's wp-admin sidebar menu into a small set of grouped
 * entries that deep-link into the unified admin shell, and redirects the old
 * standalone page URLs to their new place inside the shell.
 *
 * The legacy pages stay registered (hidden), so their capabilities and any
 * POST handling they perform keep working exactly as before.
 */
class MG_Menu_Manager {

    const SHELL_SLUG = 'mockup-generator';

    public static function init() {
        // Late priority: every legacy registration must exist before we prune.
        add_action('admin_menu', array(__CLASS__, 'consolidate_menu'), 999);
        add_action('admin_init', array(__CLASS__, 'redirect_legacy_pages'));
        add_filter('submenu_file', array(__CLASS__, 'highlight_submenu'));
    }

    /**
     * Sidebar entries: one per shell group, in fixed order. Each links to the
     * shell with the group's default tab. The capability is the most
     * permissive one within the group – individual tabs are still gated by
     * their own capability inside the shell.
     *
     * @return array<string,array{label:string,tab:string,capability:string}>
     */
    private static function get_group_links() {
        return array(
            'dashboard' => array(
                'label'      => __('Dashboard', 'mockup-generator'),
                'tab'        => 'dashboard',
                'capability' => 'edit_products',
            ),
            'mockups' => array(
                'label'      => __('Mockupok', 'mockup-generator'),
                'tab'        => 'mockups',
                'capability' => 'edit_products',
            ),
            'sales' => array(
                'label'      => __('Értékesítés', 'mockup-generator'),
                'tab'        => 'variants',
                'capability' => 'edit_products',
            ),
            'marketing' => array(
                'label'      => __('Marketing & Mérés', 'mockup-generator'),
                'tab'        => 'gads',
                'capability' => 'manage_options',
            ),
            'export' => array(
                'label'      => __('Export & Feedek', 'mockup-generator'),
                'tab'        => 'temu_export',
                'capability' => 'edit_products',
            ),
            'tools' => array(
                'label'      => __('Eszközök', 'mockup-generator'),
                'tab'        => 'maintenance',
                'capability' => 'manage_woocommerce',
            ),
            'settings' => array(
                'label'      => __('Beállítások', 'mockup-generator'),
                'tab'        => 'settings',
                'capability' => 'manage_options',
            ),
        );
    }

    /**
     * Builds the sidebar link target for a shell tab.
     *
     * @param string $tab
     * @return string
     */
    private static function group_link($tab) {
        return 'admin.php?page=' . self::SHELL_SLUG . '&mg_tab=' . $tab;
    }

    /**
     * Removes the legacy submenu entries from the sidebar (the pages stay
     * registered and reachable) and adds the grouped deep links instead.
     */
    public static function consolidate_menu() {
        if (!class_exists('MG_Admin_Page')) {
            return;
        }

        // Hide every legacy standalone entry under our top-level menu.
        foreach (array_keys(MG_Admin_Page::get_legacy_slug_map()) as $slug) {
            remove_submenu_page(self::SHELL_SLUG, $slug);
        }

        // Hide the auto-generated first item (same label as the top level)
        // and the Tools-menu migration entry that moved into the shell.
        remove_submenu_page(self::SHELL_SLUG, self::SHELL_SLUG);
        remove_submenu_page('tools.php', 'mg-migration');

        foreach (self::get_group_links() as $group) {
            add_submenu_page(
                self::SHELL_SLUG,
                $group['label'],
                $group['label'],
                $group['capability'],
                self::group_link($group['tab'])
            );
        }
    }

    /**
     * Redirects GET requests aimed at a legacy standalone page to the same
     * feature inside the shell, preserving every extra query argument.
     * POST/AJAX requests are left untouched so form submissions keep working.
     */
    public static function redirect_legacy_pages() {
        if (wp_doing_ajax()) {
            return;
        }
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
            return;
        }
        if (!class_exists('MG_Admin_Page')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page === '') {
            return;
        }

        $map = MG_Admin_Page::get_legacy_slug_map();
        if (!isset($map[$page])) {
            return;
        }

        $args = array(
            'page'   => self::SHELL_SLUG,
            'mg_tab' => $map[$page],
        );

        foreach ($_GET as $key => $value) {
            if (in_array($key, array('page', 'mg_tab'), true) || is_array($value)) {
                continue;
            }
            $key = sanitize_key($key);
            if ($key === '') {
                continue;
            }
            // The old product editor used "product", the shell uses "mg_product".
            if ($page === 'mockup-generator-product' && $key === 'product') {
                $args['mg_product'] = sanitize_text_field(wp_unslash($value));
                continue;
            }
            $args[$key] = sanitize_text_field(wp_unslash($value));
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Keeps the correct grouped sidebar entry highlighted while browsing the
     * shell with an mg_tab parameter.
     *
     * @param string|null $submenu_file
     * @return string|null
     */
    public static function highlight_submenu($submenu_file) {
        if (!isset($_GET['page']) || $_GET['page'] !== self::SHELL_SLUG || !class_exists('MG_Admin_Page')) {
            return $submenu_file;
        }

        $tab = isset($_GET['mg_tab']) ? sanitize_key(wp_unslash($_GET['mg_tab'])) : 'dashboard';
        $tabs = MG_Admin_Page::get_tabs();
        if (!isset($tabs[$tab])) {
            return $submenu_file;
        }

        $group_id = isset($tabs[$tab]['group']) ? $tabs[$tab]['group'] : 'dashboard';
        $links = self::get_group_links();
        if (!isset($links[$group_id])) {
            return $submenu_file;
        }

        return self::group_link($links[$group_id]['tab']);
    }
}
