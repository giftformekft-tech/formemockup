    /**
     * Renders the modern dashboard panel.
     */
    private static function render_dashboard_panel() {
        $upload_dir = wp_upload_dir();
        $mockup_dir = trailingslashit($upload_dir['basedir']) . 'mockup-generator';
        $storage_usage = 0;
        $file_count = 0;
        if (file_exists($mockup_dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mockup_dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $storage_usage += $file->getSize();
                $file_count++;
            }
        }
        $storage_formatted = size_format($storage_usage);

        $products = get_option('mg_products', array());
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
    }
