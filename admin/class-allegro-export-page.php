<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Admin UI and download handler for the Allegro catalogue export. */
class MG_Allegro_Export_Page {

    const OPTION_KEY = 'mg_allegro_export_settings';

    public static function init() {
        add_action('admin_post_mg_allegro_save_settings', array(self::class, 'handle_save_settings'));
        add_action('admin_post_mg_allegro_export_csv', array(self::class, 'handle_export_csv'));
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $settings = wp_parse_args($saved, array(
            'default_stock' => 10,
            'brand' => 'márkanév nélkül',
            'material' => 'pamut',
            'color_map' => array(),
            'size_map' => array(),
            'category_map' => array(),
            'category_type_map' => array(),
            'strict_mappings' => false,
        ));

        $profiles = MG_Allegro_Exporter::category_profiles();
        $inverse = is_array($settings['category_type_map']) ? $settings['category_type_map'] : array();
        foreach ($profiles as $category_id => $profile) {
            if (!empty($inverse[$category_id])) {
                continue;
            }
            $preferred = sanitize_title($profile['default_type']);
            if (isset($settings['category_map'][$preferred]) && (string) $settings['category_map'][$preferred] === $category_id) {
                $inverse[$category_id] = $preferred;
                continue;
            }
            foreach ((array) $settings['category_map'] as $type_slug => $mapped_category) {
                if ((string) $mapped_category === $category_id) {
                    $inverse[$category_id] = sanitize_title($type_slug);
                    break;
                }
            }
            if (!isset($inverse[$category_id]) && empty($saved)) {
                $inverse[$category_id] = $preferred;
            }
        }

        $settings['category_type_map'] = $inverse;
        $settings['category_map'] = array();
        foreach ($inverse as $category_id => $type_slug) {
            $type_slug = sanitize_title($type_slug);
            if (isset($profiles[(string) $category_id]) && $type_slug !== '') {
                $settings['category_map'][$type_slug] = (string) $category_id;
            }
        }
        return $settings;
    }

    private static function return_url($args = array()) {
        return add_query_arg(
            array_merge(array('page' => 'mockup-generator', 'mg_tab' => 'allegro_export'), $args),
            admin_url('admin.php')
        );
    }

    public static function handle_save_settings() {
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('Nincs jogosultságod az Allegro export beállításaihoz.', 'mockup-generator'));
        }
        check_admin_referer('mg_allegro_save_settings');

        $dictionary = MG_Allegro_Exporter::source_dictionary();
        $profiles = MG_Allegro_Exporter::category_profiles();
        $raw_category_types = isset($_POST['category_type_map']) ? (array) wp_unslash($_POST['category_type_map']) : array();
        $raw_colors = isset($_POST['color_map']) ? (array) wp_unslash($_POST['color_map']) : array();
        $raw_sizes = isset($_POST['size_map']) ? (array) wp_unslash($_POST['size_map']) : array();
        $allowed_colors = MG_Allegro_Exporter::allowed_colors();

        $category_type_map = array();
        $category_map = array();
        $color_map = array();
        $size_map = array();
        $used_types = array();

        foreach ($profiles as $category_id => $profile) {
            $type_slug = isset($raw_category_types[$category_id]) ? sanitize_title($raw_category_types[$category_id]) : '';
            if ($type_slug === '') {
                continue;
            }
            if (!isset($dictionary['types'][$type_slug])) {
                continue;
            }
            if (isset($used_types[$type_slug])) {
                wp_safe_redirect(self::return_url(array('mg_allegro_error' => 'duplicate_type')));
                exit;
            }
            $used_types[$type_slug] = true;
            $category_type_map[$category_id] = $type_slug;
            $category_map[$type_slug] = $category_id;

            foreach ((array) ($dictionary['colors_by_type'][$type_slug] ?? array()) as $color_slug => $color_meta) {
                $key = MG_Allegro_Exporter::mapping_key($color_slug);
                $value = isset($raw_colors[$type_slug][$key]) ? sanitize_text_field($raw_colors[$type_slug][$key]) : '';
                if (in_array($value, $allowed_colors, true)) {
                    $color_map[$type_slug][$key] = $value;
                }
            }

            $allowed_sizes = MG_Allegro_Exporter::allowed_sizes_for_category($category_id);
            foreach ((array) ($dictionary['sizes'][$type_slug] ?? array()) as $source_size) {
                $key = MG_Allegro_Exporter::mapping_key($source_size);
                $value = isset($raw_sizes[$type_slug][$key]) ? sanitize_text_field($raw_sizes[$type_slug][$key]) : '';
                if (in_array($value, $allowed_sizes, true)) {
                    $size_map[$type_slug][$key] = $value;
                }
            }
        }

        update_option(self::OPTION_KEY, array(
            'default_stock' => max(1, absint($_POST['default_stock'] ?? 10)),
            'brand' => sanitize_text_field(wp_unslash($_POST['brand'] ?? 'márkanév nélkül')),
            'material' => sanitize_text_field(wp_unslash($_POST['material'] ?? 'pamut')),
            'category_type_map' => $category_type_map,
            'category_map' => $category_map,
            'color_map' => $color_map,
            'size_map' => $size_map,
            'strict_mappings' => true,
        ), false);

        wp_safe_redirect(self::return_url(array('mg_allegro_saved' => '1')));
        exit;
    }

    public static function handle_export_csv() {
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('Nincs jogosultságod az Allegro exporthoz.', 'mockup-generator'));
        }
        check_admin_referer('mg_allegro_export_csv');

        $types = isset($_POST['types']) ? array_map('sanitize_title', (array) wp_unslash($_POST['types'])) : array();
        if (!$types) {
            wp_die(self::error_page(array('Legalább egy összerendelt terméktípust válassz ki.')));
        }
        $result = MG_Allegro_Exporter::build_rows(
            $types,
            !empty($_POST['only_unexported']),
            self::get_settings()
        );
        if (!empty($result['errors'])) {
            wp_die(self::error_page($result['errors']), 'Allegro export ellenőrzési hiba', array('response' => 400));
        }
        if (empty($result['rows'])) {
            wp_die(self::error_page(array('Nincs exportálható variáns. Kapcsold ki a „csak még nem exportált” szűrőt, vagy ellenőrizd a termékeket.')));
        }

        MG_Allegro_Exporter::mark_exported($result['product_types']);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="allegro-export-' . gmdate('Y-m-d-His') . '.csv"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, MG_Allegro_Exporter::headers(), ';');
        foreach ($result['rows'] as $row) {
            $line = array();
            foreach (MG_Allegro_Exporter::headers() as $header) {
                $line[] = isset($row[$header]) ? $row[$header] : '';
            }
            fputcsv($output, $line, ';');
        }
        fclose($output);
        exit;
    }

    private static function error_page($errors) {
        $shown = array_slice((array) $errors, 0, 100);
        $html = '<div style="max-width:900px"><h2>Az export nem készült el</h2>';
        $html .= '<p>Javítsd az alábbi megfeleltetéseket; így nem kerül hibás adat az Allegro importba.</p><ul style="list-style:disc;padding-left:22px">';
        foreach ($shown as $error) {
            $html .= '<li>' . esc_html($error) . '</li>';
        }
        if (count((array) $errors) > count($shown)) {
            $html .= '<li>…és még ' . esc_html(count($errors) - count($shown)) . ' hiba.</li>';
        }
        $html .= '</ul><p><a class="button" href="' . esc_url(self::return_url()) . '">Vissza az Allegro exporthoz</a></p></div>';
        return $html;
    }

    public static function render_page() {
        if (!current_user_can('edit_products')) {
            return;
        }
        $settings = self::get_settings();
        $dictionary = MG_Allegro_Exporter::source_dictionary();
        $profiles = MG_Allegro_Exporter::category_profiles();
        ?>
        <div class="mg-panel-body mg-panel-body--allegro-export">
            <section class="mg-panel-section">
                <div class="mg-panel-section__header">
                    <div>
                        <h2><?php esc_html_e('Allegro megfeleltetési profilok', 'mockup-generator'); ?></h2>
                        <p><?php esc_html_e('Először rendeld az Allegro pólókategóriát a saját virtuális terméktípusodhoz, majd párosítsd a színeket és méreteket.', 'mockup-generator'); ?></p>
                    </div>
                </div>

                <?php if (!empty($_GET['mg_allegro_saved'])): ?>
                    <div class="notice notice-success inline"><p><?php esc_html_e('Az Allegro megfeleltetési profilok mentve.', 'mockup-generator'); ?></p></div>
                <?php endif; ?>
                <?php if (isset($_GET['mg_allegro_error']) && $_GET['mg_allegro_error'] === 'duplicate_type'): ?>
                    <div class="notice notice-error inline"><p><?php esc_html_e('Egy virtuális terméktípus csak egy Allegro pólókategóriához rendelhető.', 'mockup-generator'); ?></p></div>
                <?php endif; ?>

                <div style="padding:14px 16px;margin:16px 0;background:#f0f6fc;border-left:4px solid #2271b1;">
                    <strong><?php esc_html_e('Három kezdő profil', 'mockup-generator'); ?></strong>
                    <p style="margin:4px 0 0"><?php esc_html_e('Gyermek póló, férfi póló és női póló. A galléros póló nincs bekapcsolva. Ha megváltoztatod egy kategória virtuális típusát, ments egyszer; utána megjelennek annak saját színei és méretei.', 'mockup-generator'); ?></p>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mg_allegro_save_settings">
                    <?php wp_nonce_field('mg_allegro_save_settings'); ?>

                    <h3><?php esc_html_e('1. Allegro kategória ↔ virtuális terméktípus', 'mockup-generator'); ?></h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:12px;max-width:1100px;margin-bottom:24px">
                    <?php foreach ($profiles as $category_id => $profile):
                        $selected_type = sanitize_title($settings['category_type_map'][$category_id] ?? '');
                    ?>
                        <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:15px">
                            <strong style="display:block;font-size:15px"><?php echo esc_html($profile['label']); ?></strong>
                            <span style="display:block;color:#646970;font-size:12px;margin:3px 0 10px"><?php echo esc_html($profile['path']); ?> · ID <?php echo esc_html($category_id); ?></span>
                            <label><span style="display:block;font-weight:600;margin-bottom:5px"><?php esc_html_e('Saját virtuális terméktípus', 'mockup-generator'); ?></span>
                                <select name="category_type_map[<?php echo esc_attr($category_id); ?>]" style="width:100%">
                                    <option value=""><?php esc_html_e('— nincs összerendelve —', 'mockup-generator'); ?></option>
                                    <?php foreach ($dictionary['types'] as $type_slug => $type_label): ?>
                                        <option value="<?php echo esc_attr($type_slug); ?>" <?php selected($selected_type, $type_slug); ?>><?php echo esc_html($type_label); ?> (<?php echo esc_html($type_slug); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <h3><?php esc_html_e('2. Profilonkénti szín- és méretmegfeleltetés', 'mockup-generator'); ?></h3>
                    <?php foreach ($profiles as $category_id => $profile):
                        $type_slug = sanitize_title($settings['category_type_map'][$category_id] ?? '');
                        if ($type_slug === '' || !isset($dictionary['types'][$type_slug])) {
                            continue;
                        }
                        $type_colors = (array) ($dictionary['colors_by_type'][$type_slug] ?? array());
                        $type_color_map = isset($settings['color_map'][$type_slug]) && is_array($settings['color_map'][$type_slug])
                            ? $settings['color_map'][$type_slug]
                            : (array) $settings['color_map'];
                        $allowed_sizes = MG_Allegro_Exporter::allowed_sizes_for_category($category_id);
                    ?>
                        <details open style="max-width:1100px;margin:14px 0;border:1px solid #c3c4c7;border-radius:10px;background:#fff;overflow:hidden">
                            <summary style="padding:14px 16px;cursor:pointer;background:#f6f7f7;font-weight:700;font-size:15px">
                                <?php echo esc_html($profile['label']); ?> ↔ <?php echo esc_html($dictionary['types'][$type_slug]); ?>
                            </summary>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;padding:16px">
                                <div>
                                    <h4 style="margin-top:0"><?php esc_html_e('Színek', 'mockup-generator'); ?></h4>
                                    <table class="widefat striped"><thead><tr><th><?php esc_html_e('Saját virtuális szín', 'mockup-generator'); ?></th><th><?php esc_html_e('Allegro főszín', 'mockup-generator'); ?></th></tr></thead><tbody>
                                    <?php foreach ($type_colors as $color_slug => $color_meta):
                                        $color_key = MG_Allegro_Exporter::mapping_key($color_slug);
                                        $mapped = MG_Allegro_Exporter::map_color($color_slug, $color_meta['label'], $type_color_map, empty($settings['strict_mappings']));
                                    ?>
                                        <tr><td><strong><?php echo esc_html($color_meta['label']); ?></strong><br><code><?php echo esc_html($color_slug); ?></code></td><td><select name="color_map[<?php echo esc_attr($type_slug); ?>][<?php echo esc_attr($color_key); ?>]" style="width:100%"><option value=""><?php esc_html_e('— válassz —', 'mockup-generator'); ?></option><?php foreach (MG_Allegro_Exporter::allowed_colors() as $target_color): ?><option value="<?php echo esc_attr($target_color); ?>" <?php selected($mapped, $target_color); ?>><?php echo esc_html($target_color); ?></option><?php endforeach; ?></select></td></tr>
                                    <?php endforeach; ?>
                                    </tbody></table>
                                </div>
                                <div>
                                    <h4 style="margin-top:0"><?php esc_html_e('Méretek', 'mockup-generator'); ?></h4>
                                    <table class="widefat striped"><thead><tr><th><?php esc_html_e('Saját méret', 'mockup-generator'); ?></th><th><?php esc_html_e('Allegro méret', 'mockup-generator'); ?></th></tr></thead><tbody>
                                    <?php foreach ((array) ($dictionary['sizes'][$type_slug] ?? array()) as $source_size):
                                        $size_key = MG_Allegro_Exporter::mapping_key($source_size);
                                        $mapped = MG_Allegro_Exporter::map_size($type_slug, $source_size, $settings['size_map'], empty($settings['strict_mappings']));
                                    ?>
                                        <tr><td><strong><?php echo esc_html($source_size); ?></strong></td><td><select name="size_map[<?php echo esc_attr($type_slug); ?>][<?php echo esc_attr($size_key); ?>]" style="width:100%"><option value=""><?php esc_html_e('— válassz —', 'mockup-generator'); ?></option><?php foreach ($allowed_sizes as $target_size): ?><option value="<?php echo esc_attr($target_size); ?>" <?php selected($mapped, $target_size); ?>><?php echo esc_html($target_size); ?></option><?php endforeach; ?></select></td></tr>
                                    <?php endforeach; ?>
                                    </tbody></table>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>

                    <h3 style="margin-top:24px"><?php esc_html_e('3. Export alapadatai', 'mockup-generator'); ?></h3>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th><label for="mg-allegro-brand"><?php esc_html_e('Alap márka', 'mockup-generator'); ?></label></th><td><input class="regular-text" id="mg-allegro-brand" name="brand" value="<?php echo esc_attr($settings['brand']); ?>"></td></tr>
                        <tr><th><label for="mg-allegro-material"><?php esc_html_e('Alap fő anyag', 'mockup-generator'); ?></label></th><td><input class="regular-text" id="mg-allegro-material" name="material" value="<?php echo esc_attr($settings['material']); ?>"></td></tr>
                        <tr><th><label for="mg-allegro-stock"><?php esc_html_e('Sablon készlet', 'mockup-generator'); ?></label></th><td><input type="number" min="1" id="mg-allegro-stock" name="default_stock" value="<?php echo esc_attr($settings['default_stock']); ?>"><p class="description"><?php esc_html_e('Ha a Woo termék nem kezel külön készletet, ez kerül minden Allegro variánsba.', 'mockup-generator'); ?></p></td></tr>
                    </tbody></table>
                    <p><button type="submit" class="button button-primary button-hero"><?php esc_html_e('Megfeleltetési profilok mentése', 'mockup-generator'); ?></button></p>
                </form>

                <hr style="margin:30px 0">
                <h3><?php esc_html_e('Allegro CSV export', 'mockup-generator'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mg_allegro_export_csv">
                    <?php wp_nonce_field('mg_allegro_export_csv'); ?>
                    <fieldset style="margin:12px 0"><legend class="screen-reader-text"><?php esc_html_e('Exportálandó profilok', 'mockup-generator'); ?></legend>
                    <?php foreach ($profiles as $category_id => $profile):
                        $type_slug = sanitize_title($settings['category_type_map'][$category_id] ?? '');
                        if ($type_slug === '' || !isset($dictionary['types'][$type_slug])) {
                            continue;
                        }
                    ?>
                        <label style="display:inline-block;margin:0 18px 10px 0"><input type="checkbox" name="types[]" value="<?php echo esc_attr($type_slug); ?>" checked> <?php echo esc_html($profile['label']); ?> ↔ <?php echo esc_html($dictionary['types'][$type_slug]); ?></label>
                    <?php endforeach; ?>
                    </fieldset>
                    <p><label><input type="checkbox" name="only_unexported" value="1"> <?php esc_html_e('Csak a még nem exportált termék–típus párok', 'mockup-generator'); ?></label></p>
                    <p><button type="submit" class="button button-primary button-hero"><span class="dashicons dashicons-download" style="vertical-align:-4px"></span> <?php esc_html_e('Allegro CSV letöltése', 'mockup-generator'); ?></button></p>
                    <p class="description"><?php esc_html_e('Minden termék–szín–méret kombináció külön sort kap. A fájl az allegro-sync alkalmazásba importálható.', 'mockup-generator'); ?></p>
                </form>
            </section>
        </div>
        <?php
    }
}
