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

    /**
     * Registers the top-level admin menu entry and hooks the asset loader.
     */
    public static function add_menu_page() {
        add_menu_page(
            'Mockup Generator',
            'Mockup Generator',
            'edit_products',
            self::MAIN_SLUG,
            [self::class, 'render_page'],
            'dashicons-art'
        );

        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_init', [self::class, 'redirect_legacy_requests']);
    }

    /**
     * Enqueues the consolidated admin shell assets when the shell or one of
     * the legacy single-page endpoints is requested.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets($hook) {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET') {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $valid_hook = $hook === 'toplevel_page_' . self::MAIN_SLUG;

        $eligible_pages = array_merge(
            [self::MAIN_SLUG],
            array_keys(self::get_legacy_slug_map())
        );
        $valid_request = in_array($page, $eligible_pages, true);

        if (!$valid_hook && !$valid_request) {
            return;
        }

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

        $tabs = self::get_tabs();
        $active = self::determine_active_tab($tabs);
        $data = array(
            'defaultTab' => $active,
            'legacyMap'  => self::get_legacy_slug_map(),
            'pageSlug'   => self::MAIN_SLUG,
        );

        $product = self::get_requested_product_key();
        if ($product !== '') {
            $data['currentProduct'] = $product;
        }

        wp_localize_script('mg-admin-ui', 'MG_ADMIN_UI', $data);
    }

    /**
     * Redirects legacy submenu slugs to the SPA shell so the WordPress admin
     * menu keeps working even without the JavaScript link rewriter.
     */
    public static function redirect_legacy_requests() {
        if (!is_admin()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!$page) {
            return;
        }

        if ($page === self::MAIN_SLUG) {
            return;
        }

        $map = self::get_legacy_slug_map();
        if (!isset($map[$page])) {
            return;
        }

        $target_tab = $map[$page];
        $args = array(
            'page'   => self::MAIN_SLUG,
            'mg_tab' => $target_tab,
        );

        if ($page === 'mockup-generator-product') {
            $product = isset($_GET['product']) ? sanitize_key(wp_unslash($_GET['product'])) : '';
            if ($product) {
                $args['mg_product'] = $product;
            }
        }

        $current_tab = isset($_GET['mg_tab']) ? sanitize_key(wp_unslash($_GET['mg_tab'])) : '';
        $current_product = isset($_GET['mg_product']) ? sanitize_key(wp_unslash($_GET['mg_product'])) : '';

        $needs_redirect = ($current_tab !== $target_tab) || ($page === 'mockup-generator-product' && (!isset($_GET['mg_product']) || $current_product === ''));
        if (!$needs_redirect) {
            return;
        }

        $url = add_query_arg($args, admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Returns the list of admin tabs rendered inside the SPA shell.
     *
     * @return array<string,array<string,string>>
     */
    private static function get_tabs() {
        return array(
            'dashboard' => array(
                'label'     => __('Dashboard', 'mockup-generator'),
                'type'      => 'legacy',
                'page_slug' => 'mockup-generator-dashboard',
            ),
            'mockups' => array(
                'label' => __('Mockupok', 'mockup-generator'),
                'type'  => 'custom',
            ),
            'variants' => array(
                'label'     => __('Variánsok', 'mockup-generator'),
                'type'      => 'legacy',
                'page_slug' => 'mockup-generator-variant-display',
            ),
            'regenerate' => array(
                'label'     => __('Regenerálás', 'mockup-generator'),
                'type'      => 'legacy',
                'page_slug' => 'mockup-generator-maintenance',
            ),
            'surcharges' => array(
                'label'     => __('Felárak', 'mockup-generator'),
                'type'      => 'legacy',
                'page_slug' => 'mockup-generator-surcharges',
            ),
            'settings' => array(
                'label'     => __('Beállítások', 'mockup-generator'),
                'type'      => 'legacy',
                'page_slug' => 'mockup-generator-settings',
            ),
            'logs' => array(
                'label' => __('Logok', 'mockup-generator'),
                'type'  => 'placeholder',
            ),
        );
    }

    /**
     * Determines which tab should be active for the current request.
     *
     * @param array $tabs
     * @return string
     */
    private static function determine_active_tab($tabs) {
        $requested = '';

        if (isset($_REQUEST['mg_tab'])) {
            $requested = sanitize_key(wp_unslash($_REQUEST['mg_tab']));
        } elseif (!empty($_GET['mg_tab'])) {
            $requested = sanitize_key(wp_unslash($_GET['mg_tab']));
        }

        if ($requested && isset($tabs[$requested])) {
            return $requested;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        if ($page) {
            foreach ($tabs as $key => $tab) {
                if (!empty($tab['page_slug']) && $tab['page_slug'] === $page) {
                    return $key;
                }
            }
        }

        return self::get_default_tab();
    }

    /**
     * Returns the default tab key.
     *
     * @return string
     */
    private static function get_default_tab() {
        return 'dashboard';
    }

    /**
     * Collects the mapping between legacy submenu slugs and the SPA tab IDs.
     *
     * @return array<string,string>
     */
    private static function get_legacy_slug_map() {
        return array(
            'mockup-generator-dashboard'        => 'dashboard',
            'mockup-generator-variant-display'  => 'variants',
            'mockup-generator-maintenance'      => 'regenerate',
            'mockup-generator-surcharges'       => 'surcharges',
            'mockup-generator-settings'         => 'settings',
            'mockup-generator-product'          => 'mockups',
        );
    }

    /**
     * Legacy page callback registry used by the shell to embed existing screens.
     *
     * @return array<string,callable>
     */
    private static function get_legacy_callbacks() {
        $callbacks = array();

        if (class_exists('MG_Dashboard_Page')) {
            $callbacks['mockup-generator-dashboard'] = array('MG_Dashboard_Page', 'render_page');
        }
        if (class_exists('MG_Variant_Display_Page')) {
            $callbacks['mockup-generator-variant-display'] = array('MG_Variant_Display_Page', 'render_page');
        }
        if (class_exists('MG_Mockup_Maintenance_Page')) {
            $callbacks['mockup-generator-maintenance'] = array('MG_Mockup_Maintenance_Page', 'render_page');
        }
        if (class_exists('MG_Surcharge_Options_Page')) {
            $callbacks['mockup-generator-surcharges'] = array('MG_Surcharge_Options_Page', 'render');
        }
        if (class_exists('MG_Settings_Page')) {
            $callbacks['mockup-generator-settings'] = array('MG_Settings_Page', 'render_settings');
        }
        if (class_exists('MG_Product_Settings_Page')) {
            $callbacks['mockup-generator-product'] = array('MG_Product_Settings_Page', 'render_product');
        }

        return $callbacks;
    }

    /**
     * Renders the main admin shell with tab navigation and panels.
     */
    public static function render_page() {
        if (!current_user_can('edit_products')) {
            wp_die(__('Nincs jogosultságod a Mockup Generator megnyitásához.', 'mockup-generator'));
        }
        echo '</div>';

        $tabs    = self::get_tabs();
        $current = self::determine_active_tab($tabs);
        $product = self::get_requested_product_key();

        echo '<div class="mg-admin-shell">';
        echo '<header class="mg-admin-header">';
        echo '<div class="mg-admin-title">';
        echo '<span class="dashicons dashicons-art" aria-hidden="true"></span>';
        echo '<div class="mg-admin-heading">';
        echo '<h1>Mockup Generator</h1>';
        echo '<p class="mg-admin-sub">' . esc_html__('Egységes admin felület gyors tabváltással.', 'mockup-generator') . '</p>';
        echo '</div>';
        echo '</div>';
        $cta_url = esc_url(self::build_panel_url('settings'));
        echo '<div class="mg-admin-actions">';
        echo '<a class="button button-primary mg-primary-action" href="' . $cta_url . '">' . esc_html__('Új mockup hozzáadása', 'mockup-generator') . '</a>';
        echo '</div>';
        echo '</header>';

        echo '<nav class="mg-tabbar" role="tablist">';
        foreach ($tabs as $id => $tab) {
            $is_active = $id === $current ? ' is-active' : '';
            $url = esc_url(self::build_panel_url($id, $id === 'mockups' && $product ? array('mg_product' => $product) : array()));
            printf(
                '<button type="button" id="mg-tab-%1$s" class="mg-tab%4$s" data-tab="%1$s" role="tab" data-url="%3$s">%2$s</button>',
                esc_attr($id),
                esc_html($tab['label']),
                $url,
                esc_attr($is_active)
            );
        }
        echo '</nav>';

        echo '<div class="mg-tabpanels">';
        foreach ($tabs as $id => $tab) {
            $panel_classes = 'mg-panel' . ($id === $current ? ' is-active' : '');
            $attrs = array(
                'id'            => 'tab-' . $id,
                'class'         => $panel_classes,
                'role'          => 'tabpanel',
                'aria-labelledby' => 'mg-tab-' . $id,
                'data-tab-id'   => $id,
            );

            if ($id === 'mockups') {
                $attrs['data-product-key'] = $product;
            }

            echo '<section' . self::compile_attributes($attrs) . '>';
            self::render_panel_body($id, $tab);
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

    /**
     * Outputs the panel body markup for a given tab definition.
     *
     * @param string $id
     * @param array  $tab
     */
    private static function render_panel_body($id, $tab) {
        switch ($tab['type']) {
            case 'legacy':
                self::render_legacy_panel($tab);
                break;
            case 'custom':
                self::render_mockups_panel();
                break;
            case 'placeholder':
            default:
                self::render_placeholder_panel();
                break;
        }
    }

    /**
     * Wraps the legacy PHP pages inside the SPA panel shell.
     *
     * @param array $tab
     */
    private static function render_legacy_panel($tab) {
        $slug = isset($tab['page_slug']) ? $tab['page_slug'] : '';
        $callbacks = self::get_legacy_callbacks();

        if (!$slug || empty($callbacks[$slug])) {
            self::render_placeholder_panel();
            return;
        }

        $original_get_page = isset($_GET['page']) ? $_GET['page'] : null;
        $original_request_page = isset($_REQUEST['page']) ? $_REQUEST['page'] : null;

        $_GET['page'] = $slug;
        $_REQUEST['page'] = $slug;

        ob_start();
        call_user_func($callbacks[$slug]);
        $content = ob_get_clean();

        if ($original_get_page === null) {
            unset($_GET['page']);
        } else {
            $_GET['page'] = $original_get_page;
        }

        if ($original_request_page === null) {
            unset($_REQUEST['page']);
        } else {
            $_REQUEST['page'] = $original_request_page;
        }

        echo self::decorate_legacy_markup($content, $slug); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Handles the custom mockup management panel.
     */
    private static function render_mockups_panel() {
        $product_key = self::get_requested_product_key();
        echo '<div class="mg-panel-body mg-panel-body--mockups" data-legacy-slug="mockup-generator-product">';
        if ($product_key) {
            self::render_product_editor($product_key);
        } else {
            self::render_mockup_overview();
        }
        echo '</div>';
    }

    /**
     * Outputs the mockup overview cards and quick stats.
     */
    private static function render_mockup_overview() {
        $products = get_option('mg_products', array());
        $total_products = is_array($products) ? count($products) : 0;
        $colors = 0;
        $views = 0;
        $templates = 0;

        if (is_array($products)) {
            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                if (!empty($product['colors']) && is_array($product['colors'])) {
                    $colors += count($product['colors']);
                }
                if (!empty($product['views']) && is_array($product['views'])) {
                    $views += count($product['views']);
                }
                if (!empty($product['template_base'])) {
                    $templates++;
                }
            }
        }

        echo '<div class="mg-panel-section">';
        echo '<div class="mg-panel-section__header">';
        echo '<h2>' . esc_html__('Mockup áttekintés', 'mockup-generator') . '</h2>';
        echo '<p>' . esc_html__('Terméktípusok, színek és nézetek egy helyen.', 'mockup-generator') . '</p>';
        echo '</div>';

        echo '<div class="mg-stats-grid">';
        self::render_stat_chip($total_products, __('Terméktípus', 'mockup-generator'), __('Aktív konfigurációk a rendszerben.', 'mockup-generator'));
        self::render_stat_chip($colors, __('Szín variáció', 'mockup-generator'), __('Színek összesen a terméktípusokban.', 'mockup-generator'));
        self::render_stat_chip($views, __('Mockup nézet', 'mockup-generator'), __('Elérhető nézetek száma a mockup sablonokban.', 'mockup-generator'));
        self::render_stat_chip($templates, __('Sablon útvonal', 'mockup-generator'), __('Mockup könyvtárak a feltöltések között.', 'mockup-generator'));
        echo '</div>';
        echo '</div>';

        echo '<div class="mg-panel-section">';
        echo '<div class="mg-panel-section__header">';
        echo '<h3>' . esc_html__('Terméktípusok kezelése', 'mockup-generator') . '</h3>';
        echo '<p>' . esc_html__('Válassz egy terméket a részletes mockup beállításokhoz.', 'mockup-generator') . '</p>';
        echo '</div>';

        if (empty($products)) {
            echo '<div class="mg-empty">';
            echo '<p>' . esc_html__('Még nincs konfigurált terméktípus. A Beállítások fülön adhatsz hozzá újat.', 'mockup-generator') . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="mg-card-grid">';
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $key   = isset($product['key']) ? self::sanitize_product_key($product['key']) : '';
            $label = isset($product['label']) ? $product['label'] : $key;
            $price = isset($product['price']) ? intval($product['price']) : 0;
            $sku   = isset($product['sku_prefix']) ? $product['sku_prefix'] : '';
            $color_count = !empty($product['colors']) && is_array($product['colors']) ? count($product['colors']) : 0;
            $size_count  = !empty($product['sizes']) && is_array($product['sizes']) ? count($product['sizes']) : 0;
            $view_count  = !empty($product['views']) && is_array($product['views']) ? count($product['views']) : 0;

            echo '<article class="mg-card">';
            echo '<header class="mg-card__header">';
            echo '<div class="mg-card__title">';
            echo '<h4>' . esc_html($label) . '</h4>';
            if ($sku) {
                echo '<span class="mg-badge">' . esc_html($sku) . '</span>';
            }
            echo '</div>';
            if ($price > 0) {
                echo '<span class="mg-card__price">' . esc_html(number_format_i18n($price)) . ' Ft</span>';
            }
            echo '</header>';

            echo '<ul class="mg-card__meta">';
            echo '<li><strong>' . esc_html($size_count) . '</strong> <span>' . esc_html__('Méret', 'mockup-generator') . '</span></li>';
            echo '<li><strong>' . esc_html($color_count) . '</strong> <span>' . esc_html__('Szín', 'mockup-generator') . '</span></li>';
            echo '<li><strong>' . esc_html($view_count) . '</strong> <span>' . esc_html__('Nézet', 'mockup-generator') . '</span></li>';
            echo '</ul>';

            echo '<footer class="mg-card__footer">';
            $edit_url = self::build_panel_url('mockups', array(
                'mg_product' => $key,
            ));
            echo '<a class="button button-primary" href="' . esc_url($edit_url) . '">' . esc_html__('Szerkesztés', 'mockup-generator') . '</a>';
            $settings_url = self::build_panel_url('settings');
            echo '<a class="button mg-card__secondary" href="' . esc_url($settings_url) . '">' . esc_html__('Beállítások', 'mockup-generator') . '</a>';
            echo '</footer>';
            echo '</article>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Renders the legacy product editor inside the SPA layout.
     *
     * @param string $product_key
     */
    private static function render_product_editor($product_key) {
        $back_url = self::build_panel_url('mockups');
        echo '<div class="mg-panel-section mg-panel-section--breadcrumb">';
        echo '<a class="mg-breadcrumb" href="' . esc_url($back_url) . '">' . esc_html__('← Vissza a listához', 'mockup-generator') . '</a>';
        echo '</div>';

        $callbacks = self::get_legacy_callbacks();
        if (empty($callbacks['mockup-generator-product'])) {
            self::render_placeholder_panel();
            return;
        }

        $original_product_get = isset($_GET['product']) ? $_GET['product'] : null;
        $original_product_request = isset($_REQUEST['product']) ? $_REQUEST['product'] : null;
        $sanitized_key = self::sanitize_product_key($product_key);

        $_GET['page'] = 'mockup-generator-product';
        $_REQUEST['page'] = 'mockup-generator-product';
        $_GET['product'] = $sanitized_key;
        $_REQUEST['product'] = $sanitized_key;

        ob_start();
        call_user_func($callbacks['mockup-generator-product']);
        $content = ob_get_clean();

        if ($original_product_get === null) {
            unset($_GET['product']);
        } else {
            $_GET['product'] = $original_product_get;
        }

        if ($original_product_request === null) {
            unset($_REQUEST['product']);
        } else {
            $_REQUEST['product'] = $original_product_request;
        }

        echo self::decorate_legacy_markup($content, 'mockup-generator-product'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Outputs a badge-like statistic chip used in the overview.
     *
     * @param int    $value
     * @param string $label
     * @param string $description
     */
    private static function render_stat_chip($value, $label, $description) {
        echo '<div class="mg-stat">';
        echo '<strong>' . esc_html(number_format_i18n($value)) . '</strong>';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '</div>';
    }

    /**
     * Fallback content for tabs that do not yet have a dedicated screen.
     */
    private static function render_placeholder_panel() {
        echo '<div class="mg-panel-body mg-panel-body--empty">';
        echo '<h2>' . esc_html__('Logok', 'mockup-generator') . '</h2>';
        echo '<p>' . esc_html__('A naplók a wp-content/uploads/mockup-generator könyvtárban találhatók. A következő frissítésben itt is elérhető lesz egy kényelmes nézet.', 'mockup-generator') . '</p>';
        echo '</div>';
    }

    /**
     * Wraps legacy markup in a uniform container to align with the SPA styling.
     *
     * @param string $content
     * @param string $slug
     * @return string
     */
    private static function decorate_legacy_markup($content, $slug) {
        $content = trim((string) $content);
        if ($content === '') {
            return '<div class="mg-panel-body mg-panel-body--empty"><p>' . esc_html__('Nincs megjeleníthető tartalom.', 'mockup-generator') . '</p></div>';
        }

        return '<div class="mg-panel-body mg-panel-body--legacy" data-legacy-slug="' . esc_attr($slug) . '">' . $content . '</div>';
    }

    /**
     * Compiles an associative array of HTML attributes into a string.
     *
     * @param array<string,string> $attributes
     * @return string
     */
    private static function compile_attributes($attributes) {
        $compiled = '';
        foreach ($attributes as $attr => $value) {
            $compiled .= ' ' . esc_attr($attr) . '="' . esc_attr($value) . '"';
        }
        return $compiled;
    }

    /**
     * Normalizes the requested product key.
     *
     * @return string
     */
    private static function get_requested_product_key() {
        if (empty($_REQUEST['mg_product'])) {
            return '';
        }
        return self::sanitize_product_key(wp_unslash($_REQUEST['mg_product']));
    }

    /**
     * Sanitizes product keys while keeping dashes and underscores intact.
     *
     * @param string $key
     * @return string
     */
    private static function sanitize_product_key($key) {
        $key = is_string($key) ? $key : '';
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_-]/', '', $key);
    }

    /**
     * Builds a URL pointing back to the SPA shell with the selected tab.
     *
     * @param string $tab
     * @param array  $args
     * @return string
     */
    private static function build_panel_url($tab, $args = array()) {
        $args = array_merge(
            array(
                'page'   => self::MAIN_SLUG,
                'mg_tab' => $tab,
            ),
            $args
        );

        return add_query_arg($args, admin_url('admin.php'));
    }
}
