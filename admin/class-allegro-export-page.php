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
        add_action('wp_ajax_mg_allegro_get_products', array(self::class, 'ajax_get_products'));
        add_action('wp_ajax_mg_allegro_get_variants', array(self::class, 'ajax_get_variants'));
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
            'size_measurements' => array(),
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
        $raw_measurements = isset($_POST['size_measurements']) ? (array) wp_unslash($_POST['size_measurements']) : array();
        $allowed_colors = MG_Allegro_Exporter::allowed_colors();

        $category_type_map = array();
        $category_map = array();
        $color_map = array();
        $size_map = array();
        $size_measurements = array();
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
                $length = MG_Allegro_Exporter::normalize_measurement($raw_measurements[$type_slug][$key]['length'] ?? '');
                $width = MG_Allegro_Exporter::normalize_measurement($raw_measurements[$type_slug][$key]['width'] ?? '');
                if ($length !== '' || $width !== '') {
                    $size_measurements[$type_slug][$key] = array(
                        'length' => $length,
                        'width' => $width,
                    );
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
            'size_measurements' => $size_measurements,
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

        $selection = isset($_POST['selection']) ? json_decode(wp_unslash($_POST['selection']), true) : array();
        $selection = is_array($selection) ? array_values(array_filter($selection, 'is_array')) : array();
        if (!$selection) {
            wp_die(self::error_page(array('Legalább egy pontos termék–típus–szín–méret variációt válassz ki.')));
        }
        $types = array_values(array_unique(array_filter(array_map(function ($item) {
            return sanitize_title($item['type'] ?? '');
        }, $selection))));
        $result = MG_Allegro_Exporter::build_rows(
            $types,
            false,
            self::get_settings(),
            $selection
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

    public static function ajax_get_products() {
        check_ajax_referer('mg_allegro_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultságod a termékek lekéréséhez.', 403);
        }

        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = absint($_POST['per_page'] ?? 25);
        if (!in_array($per_page, array(25, 50, 100), true)) {
            $per_page = 25;
        }
        $category_id = absint($_POST['category_id'] ?? 0);
        $args = array(
            'status' => 'publish',
            'limit' => $per_page,
            'page' => $page,
            'paginate' => true,
        );
        if ($category_id) {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $args['category'] = array($term->slug);
            }
        }

        $results = wc_get_products($args);
        $products = array();
        foreach ((array) $results->products as $product) {
            if (!$product || !$product->is_in_stock()) {
                continue;
            }
            $image_id = $product->get_image_id();
            $cats = wc_get_product_term_ids($product->get_id(), 'product_cat');
            $category = '';
            if ($cats) {
                $term = get_term($cats[0], 'product_cat');
                $category = $term && !is_wp_error($term) ? $term->name : '';
            }
            $exported = MG_Allegro_Exporter::get_exported_types($product->get_id());
            $exported_types = array();
            $frontend_config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            foreach ((array) ($frontend_config['types'] ?? array()) as $type_slug => $type_meta) {
                $type_slug = sanitize_title($type_slug);
                $exported_types[] = array(
                    'slug' => $type_slug,
                    'label' => wp_strip_all_tags($type_meta['label'] ?? $type_slug),
                    'exported_at' => isset($exported[$type_slug]) ? (string) $exported[$type_slug] : '',
                );
            }
            $products[] = array(
                'id' => (int) $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'category' => $category,
                'image' => $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src(),
                'exported_types' => $exported_types,
            );
        }

        wp_send_json_success(array(
            'products' => $products,
            'total_pages' => (int) $results->max_num_pages,
            'total' => (int) $results->total,
        ));
    }

    public static function ajax_get_variants() {
        check_ajax_referer('mg_allegro_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultságod a variációk lekéréséhez.', 403);
        }
        $product_ids = isset($_POST['product_ids']) ? array_values(array_unique(array_map('absint', (array) wp_unslash($_POST['product_ids'])))) : array();
        if (!$product_ids) {
            wp_send_json_error('Nincs kiválasztott termék.');
        }

        $settings = self::get_settings();
        $profiles = MG_Allegro_Exporter::category_profiles();
        $strict = !empty($settings['strict_mappings']);
        $data = array();
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_in_stock()) {
                continue;
            }
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            $exported = MG_Allegro_Exporter::get_exported_types($product->get_id());
            $variants = array();
            foreach ((array) ($config['types'] ?? array()) as $type_slug => $type_meta) {
                $type_slug = sanitize_title($type_slug);
                $category_id = (string) ($settings['category_map'][$type_slug] ?? '');
                if (!isset($profiles[$category_id])) {
                    continue;
                }
                $type_color_map = isset($settings['color_map'][$type_slug]) && is_array($settings['color_map'][$type_slug])
                    ? $settings['color_map'][$type_slug]
                    : (array) $settings['color_map'];
                foreach ((array) ($type_meta['colors'] ?? array()) as $color_slug => $color_meta) {
                    $color_slug = sanitize_title($color_slug);
                    $color_label = wp_strip_all_tags($color_meta['label'] ?? $color_slug);
                    $mapped_color = MG_Allegro_Exporter::map_color($color_slug, $color_label, $type_color_map, !$strict);
                    foreach ((array) ($color_meta['sizes'] ?? array()) as $source_size) {
                        $mapped_size = MG_Allegro_Exporter::map_size($type_slug, $source_size, $settings['size_map'], !$strict);
                        $color_valid = $mapped_color !== '';
                        $size_valid = $mapped_size !== ''
                            && in_array($mapped_size, MG_Allegro_Exporter::allowed_sizes_for_category($category_id), true);
                        $variants[] = array(
                            'type' => $type_slug,
                            'type_label' => wp_strip_all_tags($type_meta['label'] ?? $type_slug),
                            'category_id' => $category_id,
                            'category_label' => $profiles[$category_id]['label'],
                            'color' => $color_slug,
                            'color_label' => $color_label,
                            'allegro_color' => $mapped_color,
                            'color_valid' => $color_valid,
                            'size' => (string) $source_size,
                            'allegro_size' => $mapped_size,
                            'size_valid' => $size_valid,
                            'valid' => $color_valid && $size_valid,
                            'exported_at' => isset($exported[$type_slug]) ? (string) $exported[$type_slug] : '',
                        );
                    }
                }
            }
            $data[] = array(
                'product_id' => (int) $product->get_id(),
                'product_name' => $product->get_name(),
                'sku_base' => $product->get_sku(),
                'variants' => $variants,
            );
        }
        wp_send_json_success($data);
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
                                    <p class="description"><?php esc_html_e('A hossz és szélesség a póló sík felületre kiterített mérete centiméterben. A megadott adatok automatikusan bekerülnek az adott variáns leírásába.', 'mockup-generator'); ?></p>
                                    <table class="widefat striped"><thead><tr><th><?php esc_html_e('Saját méret', 'mockup-generator'); ?></th><th><?php esc_html_e('Allegro méret', 'mockup-generator'); ?></th><th><?php esc_html_e('Hossz (cm)', 'mockup-generator'); ?></th><th><?php esc_html_e('Szélesség (cm)', 'mockup-generator'); ?></th></tr></thead><tbody>
                                    <?php foreach ((array) ($dictionary['sizes'][$type_slug] ?? array()) as $source_size):
                                        $size_key = MG_Allegro_Exporter::mapping_key($source_size);
                                        $mapped = MG_Allegro_Exporter::map_size($type_slug, $source_size, $settings['size_map'], empty($settings['strict_mappings']));
                                        $measurement = MG_Allegro_Exporter::get_size_measurement($type_slug, $source_size, $settings['size_measurements']);
                                    ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($source_size); ?></strong></td>
                                            <td><select name="size_map[<?php echo esc_attr($type_slug); ?>][<?php echo esc_attr($size_key); ?>]" style="width:100%"><option value=""><?php esc_html_e('— válassz —', 'mockup-generator'); ?></option><?php foreach ($allowed_sizes as $target_size): ?><option value="<?php echo esc_attr($target_size); ?>" <?php selected($mapped, $target_size); ?>><?php echo esc_html($target_size); ?></option><?php endforeach; ?></select></td>
                                            <td><input type="number" min="0.1" max="999" step="0.1" inputmode="decimal" name="size_measurements[<?php echo esc_attr($type_slug); ?>][<?php echo esc_attr($size_key); ?>][length]" value="<?php echo esc_attr($measurement['length']); ?>" style="width:90px" aria-label="<?php echo esc_attr(sprintf(__('%s hosszúsága centiméterben', 'mockup-generator'), $source_size)); ?>"></td>
                                            <td><input type="number" min="0.1" max="999" step="0.1" inputmode="decimal" name="size_measurements[<?php echo esc_attr($type_slug); ?>][<?php echo esc_attr($size_key); ?>][width]" value="<?php echo esc_attr($measurement['width']); ?>" style="width:90px" aria-label="<?php echo esc_attr(sprintf(__('%s szélessége centiméterben', 'mockup-generator'), $source_size)); ?>"></td>
                                        </tr>
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
                <h3><?php esc_html_e('4. Exportálandó termékek és variációk kiválasztása', 'mockup-generator'); ?></h3>
                <div id="mg-allegro-export-app">
                    <div id="mg-allegro-product-step">
                        <div class="mg-allegro-toolbar">
                            <div class="mg-allegro-toolbar__filters">
                                <label><?php esc_html_e('Termékek oldalanként:', 'mockup-generator'); ?>
                                    <select id="mg-allegro-per-page"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
                                </label>
                                <label><?php esc_html_e('Főkategória:', 'mockup-generator'); ?>
                                    <select id="mg-allegro-parent-category"><option value=""><?php esc_html_e('— mind —', 'mockup-generator'); ?></option>
                                    <?php foreach ((array) get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0, 'orderby' => 'name')) as $term): ?>
                                        <option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                                    <?php endforeach; ?>
                                    </select>
                                </label>
                                <label id="mg-allegro-child-category-wrap" hidden><?php esc_html_e('Alkategória:', 'mockup-generator'); ?>
                                    <select id="mg-allegro-child-category"><option value=""><?php esc_html_e('— mind —', 'mockup-generator'); ?></option></select>
                                </label>
                            </div>
                            <div><button type="button" class="button" id="mg-allegro-select-page"><?php esc_html_e('Összes kijelölése az oldalon', 'mockup-generator'); ?></button> <button type="button" class="button button-primary" id="mg-allegro-next"><?php esc_html_e('Tovább a variációkhoz', 'mockup-generator'); ?></button></div>
                        </div>
                        <div class="mg-table-wrap"><table class="widefat fixed striped"><thead><tr><td class="check-column"><input type="checkbox" id="mg-allegro-check-page"></td><th style="width:90px"><?php esc_html_e('Kép', 'mockup-generator'); ?></th><th><?php esc_html_e('Terméknév', 'mockup-generator'); ?></th><th><?php esc_html_e('Base SKU', 'mockup-generator'); ?></th><th><?php esc_html_e('Woo kategória', 'mockup-generator'); ?></th><th><?php esc_html_e('Allegro export', 'mockup-generator'); ?></th></tr></thead><tbody id="mg-allegro-products"><tr><td colspan="6"><?php esc_html_e('Betöltés…', 'mockup-generator'); ?></td></tr></tbody></table></div>
                        <div id="mg-allegro-pages" class="mg-allegro-pages"></div>
                    </div>

                    <div id="mg-allegro-variant-step" hidden>
                        <div class="mg-allegro-toolbar"><button type="button" class="button" id="mg-allegro-back"><?php esc_html_e('« Vissza a termékekhez', 'mockup-generator'); ?></button><button type="button" class="button button-primary button-hero" id="mg-allegro-download"><span class="dashicons dashicons-download" style="vertical-align:-4px"></span> <?php esc_html_e('Kijelölt Allegro CSV letöltése', 'mockup-generator'); ?></button></div>
                        <div id="mg-allegro-variants"><p><?php esc_html_e('Variációk betöltése…', 'mockup-generator'); ?></p></div>
                    </div>
                </div>

                <form id="mg-allegro-download-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden>
                    <input type="hidden" name="action" value="mg_allegro_export_csv">
                    <input type="hidden" name="selection" id="mg-allegro-selection" value="">
                    <?php wp_nonce_field('mg_allegro_export_csv'); ?>
                </form>

                <style>
                    .mg-allegro-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:12px;margin:14px 0}.mg-allegro-toolbar__filters{display:flex;align-items:center;gap:16px;flex-wrap:wrap}.mg-allegro-pages{display:flex;justify-content:center;gap:5px;margin:15px 0}.mg-allegro-pages .button{min-width:34px}.mg-allegro-filter-grid{display:grid;grid-template-columns:repeat(3,minmax(220px,1fr));gap:16px}.mg-allegro-filter-card{background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:14px}.mg-allegro-filter-list{max-height:420px;overflow:auto}.mg-allegro-filter-list label{display:block;padding:4px 0}.mg-allegro-summary{margin-top:16px;padding:14px;background:#f0f6fc;border-left:4px solid #2271b1;font-weight:600}.mg-allegro-invalid{color:#b32d2e;font-weight:600}@media(max-width:900px){.mg-allegro-filter-grid{grid-template-columns:1fr}}
                </style>
                <style>
                    .mg-allegro-export-statuses{display:flex;flex-wrap:wrap;gap:5px;min-width:180px}.mg-allegro-export-chip{display:inline-flex;flex-direction:column;gap:1px;padding:4px 8px;border-radius:7px;background:#f0f0f1;color:#50575e;font-size:12px;line-height:1.2}.mg-allegro-export-chip small{font-size:11px}.mg-allegro-export-chip.is-exported{background:#edfaef;border:1px solid #b7dfbd;color:#116329}.mg-allegro-export-chip.is-partial{background:#fff8e5;border:1px solid #f0c36d;color:#7a4b00}.mg-allegro-export-chip.is-pending{border:1px solid #dcdcde}.mg-allegro-export-none{color:#646970;font-size:12px}
                </style>

                <script>
                jQuery(function($) {
                    const nonce = '<?php echo esc_js(wp_create_nonce('mg_allegro_nonce')); ?>';
                    const childCategories = <?php
                        $children = array();
                        foreach ((array) get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'parent__not_in' => array(0), 'orderby' => 'name')) as $term) {
                            $children[$term->parent][] = array('id' => $term->term_id, 'name' => $term->name);
                        }
                        echo wp_json_encode($children);
                    ?>;
                    let page = 1;
                    let selectedProducts = {};
                    let variants = [];

                    function esc(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
                    function activeCategory() { return $('#mg-allegro-child-category').val() || $('#mg-allegro-parent-category').val() || ''; }

                    function loadProducts(newPage) {
                        page = newPage;
                        $('#mg-allegro-check-page').prop('checked', false);
                        $('#mg-allegro-products').html('<tr><td colspan="5">Betöltés…</td></tr>');
                        $.post(ajaxurl, {action:'mg_allegro_get_products', nonce:nonce, page:page, per_page:$('#mg-allegro-per-page').val(), category_id:activeCategory()})
                            .done(function(response) {
                                if (!response.success) { $('#mg-allegro-products').html('<tr><td colspan="5">Hiba: '+esc(response.data)+'</td></tr>'); return; }
                                let html = '';
                                response.data.products.forEach(function(product) {
                                    const statuses = (product.exported_types || []).map(function(type) {
                                        const exported = !!type.exported_at;
                                        const statusText = exported ? '&#10003; export&aacute;lva' : 'm&eacute;g nincs export&aacute;lva';
                                        const title = exported ? ' title="Export&aacute;lva: '+esc(type.exported_at)+'"' : ' title="M&eacute;g nincs export&aacute;lva"';
                                        return '<span class="mg-allegro-export-chip '+(exported?'is-exported':'is-pending')+'"'+title+'><strong>'+esc(type.label)+'</strong><small>'+statusText+'</small></span>';
                                    }).join('') || '<span class="mg-allegro-export-none">Nincs virtu&aacute;lis t&iacute;pus</span>';
                                    html += '<tr><th class="check-column"><input type="checkbox" class="mg-allegro-product" value="'+product.id+'" '+(selectedProducts[product.id]?'checked':'')+'></th><td><img src="'+esc(product.image)+'" width="72" height="72" style="object-fit:cover;border-radius:6px"></td><td><strong>'+esc(product.name)+'</strong></td><td>'+esc(product.sku)+'</td><td>'+esc(product.category)+'</td><td><div class="mg-allegro-export-statuses">'+statuses+'</div></td></tr>';
                                });
                                $('#mg-allegro-products').html(html || '<tr><td colspan="5">Nincs megjeleníthető termék.</td></tr>');
                                renderPages(response.data.total_pages);
                            }).fail(function() { $('#mg-allegro-products').html('<tr><td colspan="5">Kommunikációs hiba.</td></tr>'); });
                    }

                    function renderPages(total) {
                        let html = '';
                        for (let i=1; i<=total; i++) {
                            if (i<=3 || i>total-3 || Math.abs(i-page)<=1) html += '<button type="button" class="button '+(i===page?'button-primary':'')+' mg-allegro-page" data-page="'+i+'">'+i+'</button>';
                            else if (!html.endsWith('<span>…</span>')) html += '<span>…</span>';
                        }
                        $('#mg-allegro-pages').html(html);
                    }

                    $(document).on('change', '.mg-allegro-product', function() { if (this.checked) selectedProducts[this.value]=true; else delete selectedProducts[this.value]; });
                    $(document).on('click', '.mg-allegro-page', function() { loadProducts(Number($(this).data('page'))); });
                    $('#mg-allegro-check-page').on('change', function() { $('.mg-allegro-product').prop('checked', this.checked).trigger('change'); });
                    $('#mg-allegro-select-page').on('click', function() { $('.mg-allegro-product').prop('checked', true).trigger('change'); });
                    $('#mg-allegro-per-page').on('change', function() { loadProducts(1); });
                    $('#mg-allegro-parent-category').on('change', function() {
                        const list = childCategories[this.value] || [];
                        $('#mg-allegro-child-category').html('<option value="">— mind —</option>'+list.map(x=>'<option value="'+x.id+'">'+esc(x.name)+'</option>').join(''));
                        $('#mg-allegro-child-category-wrap').prop('hidden', !list.length);
                        loadProducts(1);
                    });
                    $('#mg-allegro-child-category').on('change', function() { loadProducts(1); });

                    $('#mg-allegro-next').on('click', function() {
                        const ids = Object.keys(selectedProducts);
                        if (!ids.length) { alert('Válassz legalább egy terméket.'); return; }
                        $('#mg-allegro-product-step').prop('hidden', true);
                        $('#mg-allegro-variant-step').prop('hidden', false);
                        $('#mg-allegro-variants').html('<p style="padding:20px;text-align:center">Virtuális variációk betöltése…</p>');
                        $.post(ajaxurl, {action:'mg_allegro_get_variants', nonce:nonce, product_ids:ids}).done(function(response) {
                            if (!response.success) { $('#mg-allegro-variants').html('<p>Hiba: '+esc(response.data)+'</p>'); return; }
                            renderVariants(response.data);
                        }).fail(function() { $('#mg-allegro-variants').html('<p>Kommunikációs hiba.</p>'); });
                    });
                    $('#mg-allegro-back').on('click', function() { $('#mg-allegro-variant-step').prop('hidden', true); $('#mg-allegro-product-step').prop('hidden', false); });

                    function renderVariants(products) {
                        variants = products;
                        const types={}, colors={}, sizes={};
                        products.forEach(p=>p.variants.forEach(v=>{
                            if(!types[v.type]) types[v.type]={label:v.category_label+' ↔ '+v.type_label,count:0}; types[v.type].count++;
                            if(!colors[v.color]) colors[v.color]={label:v.color_label,allegro:v.allegro_color,count:0,valid:v.color_valid}; else { colors[v.color].valid=colors[v.color].valid&&v.color_valid; if(colors[v.color].allegro!==v.allegro_color) colors[v.color].allegro='profilfüggő'; } colors[v.color].count++;
                            if(!sizes[v.size]) sizes[v.size]={label:v.size,allegro:v.allegro_size,count:0,valid:v.size_valid}; else { sizes[v.size].valid=sizes[v.size].valid&&v.size_valid; if(sizes[v.size].allegro!==v.allegro_size) sizes[v.size].allegro='profilfüggő'; } sizes[v.size].count++;
                         }));
                         const typeProducts={};
                         products.forEach(function(p){p.variants.forEach(function(v){if(!typeProducts[v.type])typeProducts[v.type]={};typeProducts[v.type][p.product_id]=!!v.exported_at;});});
                         Object.keys(typeProducts).forEach(function(type){
                             if(!types[type])return;
                             const states=Object.keys(typeProducts[type]).map(function(pid){return typeProducts[type][pid];});
                             const exportedCount=states.filter(Boolean).length;
                             const status=exportedCount===states.length?'[EXPORTALVA]':(exportedCount?'[RESZBEN EXPORTALVA]':'[NINCS EXPORT]');
                             types[type].label += ' '+status;
                         });
                         function column(title, allId, cls, values) {
                            return '<div class="mg-allegro-filter-card"><h3>'+title+'</h3><div class="mg-allegro-filter-list"><label><input type="checkbox" id="'+allId+'" checked> <strong>Összes kijelölése</strong></label><hr>'+Object.keys(values).map(k=>{const mapping=Object.prototype.hasOwnProperty.call(values[k],'allegro')?(values[k].allegro?' → '+esc(values[k].allegro):' → nincs megfeleltetés'):'';return '<label class="'+(values[k].valid===false?'mg-allegro-invalid':'')+'"><input type="checkbox" class="'+cls+'" value="'+esc(k)+'" checked> '+esc(values[k].label)+mapping+' <small>('+values[k].count+')</small></label>';}).join('')+'</div></div>';
                        }
                        $('#mg-allegro-variants').html('<div class="mg-allegro-filter-grid">'+column('Terméktípusok','mg-allegro-all-types','mg-allegro-type',types)+column('Színek','mg-allegro-all-colors','mg-allegro-color',colors)+column('Méretek','mg-allegro-all-sizes','mg-allegro-size',sizes)+'</div><div id="mg-allegro-summary" class="mg-allegro-summary"></div>');
                        updateSummary();
                    }

                    function collectSelection() {
                        const types=$('.mg-allegro-type:checked').map((i,e)=>e.value).get(), colors=$('.mg-allegro-color:checked').map((i,e)=>e.value).get(), sizes=$('.mg-allegro-size:checked').map((i,e)=>e.value).get();
                        const selection=[], invalid=[];
                        variants.forEach(p=>p.variants.forEach(v=>{ if(types.includes(v.type)&&colors.includes(v.color)&&sizes.includes(v.size)){ const row={pid:p.product_id,type:v.type,color:v.color,size:v.size}; selection.push(row); if(!v.valid) invalid.push(row); } }));
                        return {selection:selection, invalid:invalid};
                    }
                    function updateSummary() { const result=collectSelection(); $('#mg-allegro-summary').html('Összesen <strong>'+result.selection.length+'</strong> pontos variáció kerül a fájlba '+(result.invalid.length?'<span class="mg-allegro-invalid">· '+result.invalid.length+' hiányos megfeleltetés</span>':'· minden megfeleltetés rendben')); }
                    $(document).on('change','.mg-allegro-type,.mg-allegro-color,.mg-allegro-size',updateSummary);
                    $(document).on('change','#mg-allegro-all-types',function(){$('.mg-allegro-type').prop('checked',this.checked).trigger('change');});
                    $(document).on('change','#mg-allegro-all-colors',function(){$('.mg-allegro-color').prop('checked',this.checked).trigger('change');});
                    $(document).on('change','#mg-allegro-all-sizes',function(){$('.mg-allegro-size').prop('checked',this.checked).trigger('change');});
                    $('#mg-allegro-download').on('click', function() { const result=collectSelection(); if(!result.selection.length){alert('Nincs kiválasztott variáció.');return;} if(result.invalid.length){alert('A kijelölésben hiányos szín- vagy méretmegfeleltetés van. Előbb javítsd a profilt.');return;} $('#mg-allegro-selection').val(JSON.stringify(result.selection)); document.getElementById('mg-allegro-download-form').submit(); });
                    loadProducts(1);
                });
                </script>
            </section>
        </div>
        <?php
    }
}
