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
