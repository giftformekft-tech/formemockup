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
     * Cached dataset for the bulk upload panel.
     *
     * @var array|null
     */
    private static $bulk_data = null;

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
    }

    /**
     * Enqueues the consolidated admin shell assets when the shell or one of
     * the legacy single-page endpoints is requested.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $valid_hook = $hook === 'toplevel_page_' . self::MAIN_SLUG;
        $valid_request = $page === self::MAIN_SLUG;

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

        $bulk_data = self::prepare_bulk_data();
        if (!empty($bulk_data['products'])) {
            self::enqueue_bulk_assets($bulk_data);
        }

        wp_localize_script('mg-admin-ui', 'MG_ADMIN_UI', $data);
    }

    /**
     * Returns the list of admin tabs rendered inside the SPA shell.
     *
     * @return array<string,array<string,string>>
     */
    private static function get_tabs() {
        return array(
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
            'custom_fields' => array(
                'label'     => __('Egyedi mezők', 'mockup-generator'),
                'type'      => 'legacy',
                'page_slug' => 'mockup-generator-custom-fields',
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
            'mockup-generator-custom-fields'    => 'custom_fields',
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
        if (class_exists('MG_Custom_Fields_Page')) {
            $callbacks['mockup-generator-custom-fields'] = array('MG_Custom_Fields_Page', 'render_page');
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
        $cta_url  = esc_url(self::build_panel_url('settings'));
        $bulk_url = esc_url(self::build_panel_url('bulk'));
        echo '<div class="mg-admin-actions">';
        echo '<a class="button button-primary mg-primary-action" href="' . $cta_url . '">' . esc_html__('Új mockup hozzáadása', 'mockup-generator') . '</a>';
        echo '<a class="button" href="' . $bulk_url . '">' . esc_html__('Bulk minta feltöltés', 'mockup-generator') . '</a>';
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
     * Renders the modern dashboard panel.
     */
    private static function render_dashboard_panel() {
        try {
            $upload_dir = wp_upload_dir();
            $mockup_dir = trailingslashit($upload_dir['basedir']) . 'mockup-generator';
            $storage_usage = 0;
            $file_count = 0;
            
            if (file_exists($mockup_dir)) {
                try {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mockup_dir, RecursiveDirectoryIterator::SKIP_DOTS));
                    foreach ($iterator as $file) {
                        $storage_usage += $file->getSize();
                        $file_count++;
                    }
                } catch (Exception $e) {
                    // Fail silently
                }
            }
            $storage_formatted = size_format($storage_usage);

            $products = get_option('mg_products', array());
            if (!is_array($products)) {
                $products = array();
            }
            $product_count = count($products);

            $recent_products = get_posts(array(
                'post_type' => 'product',
                'posts_per_page' => 5,
                'orderby' => 'date',
                'order' => 'DESC',
            ));

            echo '<div class="mg-panel-body mg-panel-body--dashboard">';
            
            // Welcome Section
            echo '<section class="mg-panel-section mg-panel-section--welcome">';
            echo '<h2>' . sprintf(__('Üdvözöllek, %s!', 'mockup-generator'), wp_get_current_user()->display_name) . '</h2>';
            echo '<p>' . __('Itt láthatod a rendszer állapotát és a legutóbbi aktivitásokat.', 'mockup-generator') . '</p>';
            echo '</section>';

            // Stats Grid
            echo '<div class="mg-stats-grid">';
            
            // Storage Widget
            echo '<div class="mg-stat">';
            echo '<span class="dashicons dashicons-database" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 8px; color: #2271b1;"></span>';
            echo '<strong>' . esc_html($storage_formatted) . '</strong>';
            echo '<span>' . __('Tárhely foglalás', 'mockup-generator') . '</span>';
            echo '<p>' . sprintf(__('%d fájl a mockup mappában', 'mockup-generator'), $file_count) . '</p>';
            echo '</div>';

            // Products Widget
            echo '<div class="mg-stat">';
            echo '<span class="dashicons dashicons-products" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 8px; color: #2271b1;"></span>';
            echo '<strong>' . esc_html($product_count) . '</strong>';
            echo '<span>' . __('Konfigurált típus', 'mockup-generator') . '</span>';
            echo '<p>' . __('Elérhető terméktípusok', 'mockup-generator') . '</p>';
            echo '</div>';

            // System Status Widget
            $php_version = phpversion();
            $imagick = extension_loaded('imagick') ? 'Aktív' : 'Hiányzik';
            $max_exec = ini_get('max_execution_time');
            echo '<div class="mg-stat">';
            echo '<span class="dashicons dashicons-performance" style="font-size: 24px; width: 24px; height: 24px; margin-bottom: 8px; color: #2271b1;"></span>';
            echo '<strong>PHP ' . esc_html(substr($php_version, 0, 3)) . '</strong>';
            echo '<span>' . __('Rendszer állapot', 'mockup-generator') . '</span>';
            echo '<p>Imagick: ' . esc_html($imagick) . ' | Timeout: ' . esc_html($max_exec) . 's</p>';
            echo '</div>';

            echo '</div>'; // .mg-stats-grid

            echo '<div class="mg-row-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-top: 24px;">';

            // Recent Activity
            echo '<div class="mg-card">';
            echo '<div class="mg-card__header"><h3>' . __('Legutóbb generált termékek', 'mockup-generator') . '</h3></div>';
            if (!empty($recent_products)) {
                echo '<ul class="mg-activity-list" style="list-style: none; margin: 0; padding: 0;">';
                foreach ($recent_products as $p) {
                    $thumb = get_the_post_thumbnail_url($p->ID, 'thumbnail');
                    echo '<li style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f0f0f1;">';
                    if ($thumb) {
                        echo '<img src="' . esc_url($thumb) . '" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">';
                    } else {
                        echo '<div style="width: 40px; height: 40px; background: #f0f0f1; border-radius: 4px;"></div>';
                    }
                    echo '<div>';
                    echo '<a href="' . get_edit_post_link($p->ID) . '" style="font-weight: 600; text-decoration: none;">' . esc_html($p->post_title) . '</a>';
                    echo '<div style="font-size: 12px; color: #646970;">' . human_time_diff(get_the_time('U', $p->ID), current_time('timestamp')) . ' éve</div>';
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p>' . __('Még nincs generált termék.', 'mockup-generator') . '</p>';
            }
            echo '</div>';

            // Quick Actions
            echo '<div class="mg-card">';
            echo '<div class="mg-card__header"><h3>' . __('Gyorsműveletek', 'mockup-generator') . '</h3></div>';
            echo '<div style="display: flex; flex-direction: column; gap: 12px;">';
            echo '<a href="' . esc_url(self::build_panel_url('bulk')) . '" class="button button-primary" style="text-align: center; padding: 8px;">' . __('Új Bulk Generálás', 'mockup-generator') . '</a>';
            echo '<a href="' . esc_url(self::build_panel_url('settings')) . '" class="button" style="text-align: center; padding: 8px;">' . __('Új Terméktípus', 'mockup-generator') . '</a>';
            echo '<a href="' . esc_url(admin_url('edit.php?post_type=product')) . '" class="button" style="text-align: center; padding: 8px;">' . __('WooCommerce Termékek', 'mockup-generator') . '</a>';
            echo '</div>';
            echo '</div>';

            echo '</div>'; // .mg-row-grid

            echo '</div>'; // .mg-panel-body
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>Hiba a vezérlőpult betöltésekor: ' . esc_html($e->getMessage()) . '</p></div>';
        }
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
            case 'dashboard':
                self::render_dashboard_panel();
                break;
            case 'bulk':
                self::render_bulk_panel();
                break;
            case 'custom':
                self::render_mockups_panel();
                break;
            case 'bulk':
                self::render_bulk_panel();
                break;
            case 'maintenance':
                self::render_maintenance_panel();
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

        echo self::decorate_legacy_markup($content, $slug);
    }

    /**
     * Renders the maintenance panel.
     */
    private static function render_maintenance_panel() {
        if (isset($_POST['mg_delete_orphans']) && check_admin_referer('mg_delete_orphans_nonce')) {
            $orphans = MG_Maintenance::scan_orphaned_files();
            $files_to_delete = array();
            foreach ($orphans as $o) {
                $files_to_delete[] = $o['path'];
            }
            $count = MG_Maintenance::delete_files($files_to_delete);
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('%d árva fájl törölve.', 'mockup-generator'), $count) . '</p></div>';
        }

        $orphans = MG_Maintenance::scan_orphaned_files();
        $total_size = 0;
        foreach ($orphans as $o) {
            $total_size += filesize($o['path']);
        }

        echo '<div class="mg-panel-body mg-panel-body--maintenance">';
        echo '<section class="mg-panel-section">';
        echo '<div class="mg-panel-section__header">';
        echo '<h2>' . __('Tárhely tisztítás', 'mockup-generator') . '</h2>';
        echo '<p>' . __('Itt listázzuk azokat a fájlokat a <code>mockup-generator</code> mappában, amelyek nincsenek hozzárendelve egyetlen WordPress média elemhez sem.', 'mockup-generator') . '</p>';
        echo '</div>';

        if (empty($orphans)) {
            echo '<div class="mg-empty">';
            echo '<p>' . __('Nincs árva fájl. A rendszer tiszta.', 'mockup-generator') . '</p>';
            echo '</div>';
        } else {
            echo '<div class="mg-stat" style="margin-bottom: 20px;">';
            echo '<strong>' . size_format($total_size) . '</strong>';
            echo '<span>' . sprintf(__('%d felesleges fájl', 'mockup-generator'), count($orphans)) . '</span>';
            echo '</div>';

            echo '<form method="post">';
            wp_nonce_field('mg_delete_orphans_nonce');
            echo '<input type="hidden" name="mg_delete_orphans" value="1">';
            echo '<button type="submit" class="button button-primary button-large" onclick="return confirm(\'' . __('Biztosan törölni akarod az összes árva fájlt? Ez nem visszavonható.', 'mockup-generator') . '\')">' . __('Összes törlése', 'mockup-generator') . '</button>';
            echo '</form>';

            echo '<div class="mg-table-wrap" style="margin-top: 20px;">';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>' . __('Fájlnév', 'mockup-generator') . '</th><th>' . __('Méret', 'mockup-generator') . '</th><th>' . __('Dátum', 'mockup-generator') . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($orphans as $o) {
                echo '<tr>';
                echo '<td>' . esc_html($o['name']) . '</td>';
                echo '<td>' . esc_html($o['size']) . '</td>';
                echo '<td>' . esc_html($o['date']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
        echo '</section>';
        echo '</div>';
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
     * Outputs the streamlined bulk upload tools inside the SPA layout.
     */
    private static function render_bulk_panel() {
        $data = self::prepare_bulk_data();
        echo '<div class="mg-panel-body mg-panel-body--bulk">';

        echo '<section class="mg-panel-section">';
        echo '<div class="mg-panel-section__header">';
        echo '<h2>' . esc_html__('Bulk feltöltés – kényelmes mód', 'mockup-generator') . '</h2>';
        echo '<p>' . esc_html__('Tölts fel egyszerre több mintaképet, válaszd ki a terméktípusokat és kövesd a létrejövő termékek állapotát.', 'mockup-generator') . '</p>';
        echo '</div>';

        if (empty($data['products'])) {
            echo '<div class="mg-empty">';
            echo '<p>' . esc_html__('Még nincs konfigurált terméktípus. A Beállítások fülön hozd létre őket, hogy használhasd a bulk feltöltést.', 'mockup-generator') . '</p>';
            echo '</div>';
            echo '</section>';
            echo '</div>';
            return;
        }

        echo '<div class="card">';
        echo '<div class="mg-row">';
        echo '<div id="mg-drop-zone" class="mg-drop-zone">';
        echo '<div class="mg-drop-inner">';
        /* translators: Heading shown inside the bulk upload drop zone. */
        echo '<strong>' . esc_html__('Húzd ide a mintákat', 'mockup-generator') . '</strong>';
        echo '<p>' . esc_html__('…vagy', 'mockup-generator') . ' <a href="#" class="button-link">' . esc_html__('válaszd ki a fájlokat', 'mockup-generator') . '</a></p>';
        echo '<p class="description">' . esc_html__('PNG, JPG vagy WebP képeket adhatsz hozzá. A fájlnév alapján készül az alap terméknév.', 'mockup-generator') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<input type="file" id="mg-bulk-files-adv" name="mg-bulk-files-adv[]" accept="image/png,image/jpeg,image/webp" multiple style="display:none" />';
        echo '</div>';

        echo '<div class="mg-row">';
        echo '<h3>' . esc_html__('Terméktípusok', 'mockup-generator') . '</h3>';
        echo '<div class="mg-types">';
        foreach ($data['products'] as $product) {
            $checked = true;
            echo '<label class="mg-type">';
            echo '<input type="checkbox" class="mg-type-cb" value="' . esc_attr($product['key']) . '"' . checked($checked, true, false) . ' />';
            echo '<span>' . esc_html($product['label']) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__('Jelöld be, mely terméktípusokra készüljön el minden feltöltött minta.', 'mockup-generator') . '</p>';
        echo '</div>';

        echo '<div class="mg-row">';
        echo '<h3>' . esc_html__('Alapértelmezett variáció', 'mockup-generator') . '</h3>';
        echo '<div class="mg-defaults">';
        echo '<label>' . esc_html__('Terméktípus', 'mockup-generator');
        echo '<select id="mg-default-type">';
        foreach ($data['products'] as $product) {
            $selected = selected($product['key'], $data['default_type'], false);
            echo '<option value="' . esc_attr($product['key']) . '"' . $selected . '>' . esc_html($product['label']) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label>' . esc_html__('Szín', 'mockup-generator');
        echo '<select id="mg-default-color"></select>';
        echo '</label>';

        echo '<label>' . esc_html__('Méret', 'mockup-generator');
        echo '<select id="mg-default-size"></select>';
        echo '</label>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Ezek az értékek kerülnek az új termékek első variációjába. A listák a kiválasztott típushoz igazodnak.', 'mockup-generator') . '</p>';
        echo '</div>';

        echo '</div>'; // .card

        echo '</section>';

        echo '<section class="mg-panel-section">';
        echo '<div class="mg-panel-section__header">';
        echo '<h3>' . esc_html__('Fájlnevek és sorok', 'mockup-generator') . '</h3>';
        echo '<p>' . esc_html__('Minden sorhoz beállíthatod a kategóriákat, meglévő terméket, tageket és az „Egyedi” jelölést.', 'mockup-generator') . '</p>';
        echo '</div>';

        echo '<div class="mg-table-wrap">';
        echo '<table class="widefat fixed striped mg-bulk-table">';
        echo '<thead><tr>';
        echo '<th>#</th>';
        echo '<th>' . esc_html__('Előnézet', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Fájl', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Főkategória', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Alkategóriák', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Terméknév', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Meglévő termék keresése', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Egyedi termék', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Tag-ek', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Állapot', 'mockup-generator') . '</th>';
        echo '</tr></thead>';
        echo '<tbody id="mg-bulk-rows">';
        echo '<tr class="no-items"><td colspan="10">' . esc_html__('Válassz fájlokat a fenti feltöltővel.', 'mockup-generator') . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        echo '<div class="mg-actions">';
        echo '<button type="button" class="button" id="mg-bulk-apply-first">' . esc_html__('Első sor beállításainak másolása az összes sorba', 'mockup-generator') . '</button>';
        echo '</div>';

        echo '<div class="mg-worker-control">';
        echo '<span class="mg-worker-label">' . esc_html__('Párhuzamos generáló folyamatok', 'mockup-generator') . '</span>';
        echo '<div class="mg-worker-toggle-group">';
        if (!empty($data['worker_options'])) {
            foreach ($data['worker_options'] as $option) {
                $is_active = intval($option) === intval($data['worker_count']);
                $classes = 'button mg-worker-toggle' . ($is_active ? ' is-active' : '');
                echo '<button type="button" class="' . esc_attr($classes) . '" data-workers="' . esc_attr($option) . '" aria-pressed="' . ($is_active ? 'true' : 'false') . '">' . sprintf(esc_html__('%d worker', 'mockup-generator'), intval($option)) . '</button>';
            }
        }
        echo '</div>';
        $worker_summary = sprintf(
            /* translators: %s: selected worker count. */
            __('Aktív: %s', 'mockup-generator'),
            '<span class="mg-worker-active-count">' . esc_html(intval($data['worker_count'])) . '</span>'
        );
        echo '<p class="mg-worker-summary">' . wp_kses_post($worker_summary) . '</p>';
        echo '<p class="mg-worker-feedback" aria-live="polite"></p>';
        echo '</div>';

        echo '<div class="mg-actions">';
        echo '<button type="button" class="button button-primary" id="mg-bulk-start">' . esc_html__('Bulk generálás indítása', 'mockup-generator') . '</button>';
        echo '<div class="mg-progress" aria-hidden="true"><div class="mg-bar" id="mg-bulk-bar"></div></div>';
        echo '<span id="mg-bulk-status" class="mg-bulk-status" aria-live="polite">0%</span>';
        echo '</div>';

        echo '</section>';

        echo '</div>';
    }

    /**
     * Prepares the dataset used by the bulk upload panel and JS helpers.
     *
     * @return array<string,mixed>
     */
    private static function prepare_bulk_data() {
        if (self::$bulk_data !== null) {
            return self::$bulk_data;
        }

        $products_raw = get_option('mg_products', array());
        $products_raw = is_array($products_raw) ? $products_raw : array();

        $products = array();
        $default_type = '';
        $default_color = '';
        $default_size = '';

        foreach ($products_raw as $product) {
            if (!is_array($product) || empty($product['key'])) {
                continue;
            }
            $key = self::sanitize_product_key($product['key']);
            if ($key === '') {
                continue;
            }

            $label = isset($product['label']) && $product['label'] !== '' ? wp_strip_all_tags($product['label']) : $key;
            $is_primary = !empty($product['is_primary']);

            $colors = array();
            if (!empty($product['colors']) && is_array($product['colors'])) {
                foreach ($product['colors'] as $color) {
                    if (is_array($color)) {
                        $slug = isset($color['slug']) ? sanitize_title($color['slug']) : '';
                        if ($slug === '' && isset($color['name'])) {
                            $slug = sanitize_title($color['name']);
                        }
                        if ($slug === '') {
                            continue;
                        }
                        $name = isset($color['name']) && $color['name'] !== '' ? wp_strip_all_tags($color['name']) : $slug;
                        $colors[] = array(
                            'slug' => $slug,
                            'name' => $name,
                        );
                    } elseif (is_string($color)) {
                        $slug = sanitize_title($color);
                        if ($slug === '') {
                            continue;
                        }
                        $colors[] = array(
                            'slug' => $slug,
                            'name' => $color,
                        );
                    }
                }
            }

            $sizes = array();
            if (!empty($product['sizes']) && is_array($product['sizes'])) {
                foreach ($product['sizes'] as $size_value) {
                    if (!is_string($size_value)) {
                        continue;
                    }
                    $size_value = trim($size_value);
                    if ($size_value === '' || in_array($size_value, $sizes, true)) {
                        continue;
                    }
                    $sizes[] = $size_value;
                }
            }

            $color_sizes = array();
            if (!empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
                foreach ($product['size_color_matrix'] as $size_label => $color_list) {
                    if (!is_string($size_label)) {
                        continue;
                    }
                    $size_label = trim($size_label);
                    if ($size_label === '') {
                        continue;
                    }
                    if (!is_array($color_list)) {
                        continue;
                    }
                    foreach ($color_list as $color_slug_raw) {
                        $color_slug = sanitize_title($color_slug_raw);
                        if ($color_slug === '') {
                            continue;
                        }
                        if (!isset($color_sizes[$color_slug])) {
                            $color_sizes[$color_slug] = array();
                        }
                        if (!in_array($size_label, $color_sizes[$color_slug], true)) {
                            $color_sizes[$color_slug][] = $size_label;
                        }
                    }
                }
            }

            $primary_color = isset($product['primary_color']) ? sanitize_title($product['primary_color']) : '';
            if ($primary_color !== '' && !self::bulk_color_exists($colors, $primary_color)) {
                $primary_color = '';
            }

            $primary_size = isset($product['primary_size']) ? sanitize_text_field($product['primary_size']) : '';
            if ($primary_size !== '' && !in_array($primary_size, $sizes, true)) {
                $primary_size = '';
            }

            if ($default_type === '' && $is_primary) {
                $default_type = $key;
                $default_color = $primary_color;
                $default_size = $primary_size;
            }

            $products[] = array(
                'key'            => $key,
                'label'          => $label,
                'colors'         => $colors,
                'sizes'          => $sizes,
                'primary_color'  => $primary_color,
                'primary_size'   => $primary_size,
                'color_sizes'    => $color_sizes,
                'is_primary'     => $is_primary ? 1 : 0,
            );
        }

        if ($default_type === '' && !empty($products)) {
            $default_type = $products[0]['key'];
            if ($default_color === '' && !empty($products[0]['primary_color'])) {
                $default_color = $products[0]['primary_color'];
            } elseif ($default_color === '' && !empty($products[0]['colors'])) {
                $default_color = $products[0]['colors'][0]['slug'];
            }
            if ($default_size === '' && !empty($products[0]['primary_size'])) {
                $default_size = $products[0]['primary_size'];
            } elseif ($default_size === '' && !empty($products[0]['sizes'])) {
                $default_size = $products[0]['sizes'][0];
            }
        }

        if ($default_type !== '') {
            foreach ($products as $product) {
                if ($product['key'] !== $default_type) {
                    continue;
                }
                if ($default_color === '' && !empty($product['colors'])) {
                    $default_color = $product['colors'][0]['slug'];
                }
                if ($default_size === '') {
                    if ($default_color !== '' && !empty($product['color_sizes']) && isset($product['color_sizes'][$default_color]) && !empty($product['color_sizes'][$default_color])) {
                        $default_size = $product['color_sizes'][$default_color][0];
                    } elseif (!empty($product['sizes'])) {
                        $default_size = $product['sizes'][0];
                    }
                }
                break;
            }
        }

        $mains = array();
        $subs = array();
        if (taxonomy_exists('product_cat')) {
            $main_terms = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'parent'     => 0,
                )
            );
            if (!is_wp_error($main_terms)) {
                foreach ($main_terms as $term) {
                    $mains[] = array(
                        'id'   => (int) $term->term_id,
                        'name' => wp_strip_all_tags($term->name),
                    );
                    $children = get_terms(
                        array(
                            'taxonomy'   => 'product_cat',
                            'hide_empty' => false,
                            'parent'     => $term->term_id,
                        )
                    );
                    if (!is_wp_error($children) && !empty($children)) {
                        foreach ($children as $child) {
                            $subs[(int) $term->term_id][] = array(
                                'id'   => (int) $child->term_id,
                                'name' => wp_strip_all_tags($child->name),
                            );
                        }
                    } else {
                        $subs[(int) $term->term_id] = array();
                    }
                }
            }
        }

        $worker_options = array();
        $worker_count = 1;
        if (class_exists('MG_Bulk_Queue')) {
            $worker_options = MG_Bulk_Queue::get_allowed_worker_counts();
            $worker_count = MG_Bulk_Queue::get_configured_worker_count();
        }

        self::$bulk_data = array(
            'products'       => $products,
            'default_type'   => $default_type,
            'default_color'  => $default_color,
            'default_size'   => $default_size,
            'mains'          => $mains,
            'subs'           => $subs,
            'worker_options' => $worker_options,
            'worker_count'   => $worker_count,
        );

        return self::$bulk_data;
    }

    /**
     * Ensures the bulk upload scripts and styles are registered with the prepared payload.
     *
     * @param array $bulk_data
     */
    private static function enqueue_bulk_assets($bulk_data) {
        $css_path = plugin_dir_path(__FILE__) . '../assets/css/bulk-upload.css';
        $search_js = plugin_dir_path(__FILE__) . '../assets/js/product-search.js';
        $bulk_js = plugin_dir_path(__FILE__) . '../assets/js/bulk-upload-advanced.js';

        wp_enqueue_style(
            'mg-bulk-upload',
            plugins_url('../assets/css/bulk-upload.css', __FILE__),
            array(),
            file_exists($css_path) ? filemtime($css_path) : '1.0.0'
        );

        wp_enqueue_script(
            'mg-product-search',
            plugins_url('../assets/js/product-search.js', __FILE__),
            array('jquery'),
            file_exists($search_js) ? filemtime($search_js) : '1.0.0',
            true
        );

        wp_enqueue_script(
            'mg-bulk-advanced',
            plugins_url('../assets/js/bulk-upload-advanced.js', __FILE__),
            array('jquery'),
            file_exists($bulk_js) ? filemtime($bulk_js) : '1.0.0',
            true
        );

        $payload = array(
            'ajax_url'              => admin_url('admin-ajax.php'),
            'nonce'                 => wp_create_nonce('mg_bulk_nonce'),
            'products'              => $bulk_data['products'],
            'mains'                 => $bulk_data['mains'],
            'subs'                  => $bulk_data['subs'],
            'default_type'          => $bulk_data['default_type'],
            'default_color'         => $bulk_data['default_color'],
            'default_size'          => $bulk_data['default_size'],
            'worker_options'        => $bulk_data['worker_options'],
            'worker_count'          => $bulk_data['worker_count'],
            'worker_feedback_saving'=> __('Mentés folyamatban…', 'mockup-generator'),
            'worker_feedback_saved' => __('Beállítva: %d worker.', 'mockup-generator'),
            'worker_feedback_error' => __('Nem sikerült menteni. Próbáld újra.', 'mockup-generator'),
        );

        wp_localize_script('mg-bulk-advanced', 'MG_BULK_ADV', $payload);

        wp_localize_script(
            'mg-product-search',
            'MG_SEARCH',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('mg_search_nonce'),
            )
        );
    }

    /**
     * Checks whether the provided slug exists in the color list.
     *
     * @param array  $colors
     * @param string $slug
     * @return bool
     */
    private static function bulk_color_exists($colors, $slug) {
        if ($slug === '' || empty($colors)) {
            return false;
        }
        foreach ($colors as $color) {
            if (isset($color['slug']) && $color['slug'] === $slug) {
                return true;
            }
        }
        return false;
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
