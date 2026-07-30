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
        ));
        $settings['category_map'] = array_merge(
            MG_Allegro_Exporter::default_category_map(),
            is_array($settings['category_map']) ? $settings['category_map'] : array()
        );
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
        $raw_colors = isset($_POST['color_map']) ? (array) wp_unslash($_POST['color_map']) : array();
        $raw_sizes = isset($_POST['size_map']) ? (array) wp_unslash($_POST['size_map']) : array();
        $raw_categories = isset($_POST['category_map']) ? (array) wp_unslash($_POST['category_map']) : array();
        $allowed_colors = MG_Allegro_Exporter::allowed_colors();

        $color_map = array();
        foreach ($dictionary['colors'] as $color_slug => $color_meta) {
            $key = MG_Allegro_Exporter::mapping_key($color_slug);
            $value = isset($raw_colors[$key]) ? sanitize_text_field($raw_colors[$key]) : '';
            if (in_array($value, $allowed_colors, true)) {
                $color_map[$key] = $value;
            }
        }

        $size_map = array();
        $category_map = array();
        foreach ($dictionary['types'] as $type_slug => $type_label) {
            $type_slug = sanitize_title($type_slug);
            $category = isset($raw_categories[$type_slug]) ? preg_replace('/\D+/', '', (string) $raw_categories[$type_slug]) : '';
            if ($category !== '') {
                $category_map[$type_slug] = $category;
            }
            foreach ((array) ($dictionary['sizes'][$type_slug] ?? array()) as $source_size) {
                $size_key = MG_Allegro_Exporter::mapping_key($source_size);
                $value = isset($raw_sizes[$type_slug][$size_key]) ? sanitize_text_field($raw_sizes[$type_slug][$size_key]) : '';
                if ($value !== '') {
                    $size_map[$type_slug][$size_key] = $value;
                }
            }
        }

        update_option(self::OPTION_KEY, array(
            'default_stock' => max(1, absint($_POST['default_stock'] ?? 10)),
            'brand' => sanitize_text_field(wp_unslash($_POST['brand'] ?? 'márkanév nélkül')),
            'material' => sanitize_text_field(wp_unslash($_POST['material'] ?? 'pamut')),
            'color_map' => $color_map,
            'size_map' => $size_map,
            'category_map' => $category_map,
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
            wp_die(self::error_page(array('Legalább egy terméktípust válassz ki.')));
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
        $html .= '<p>Javítsd az alábbi adatokat; így nem kerül hibás szín vagy méret az Allegro importba.</p><ul style="list-style:disc;padding-left:22px">';
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
        $allowed_colors = MG_Allegro_Exporter::allowed_colors();
        ?>
        <div class="mg-panel-body mg-panel-body--allegro-export">
            <section class="mg-panel-section">
                <div class="mg-panel-section__header">
                    <div>
                        <h2><?php esc_html_e('Allegro Export', 'mockup-generator'); ?></h2>
                        <p><?php esc_html_e('Allegro-kompatibilis katalógus készítése: minden pontos szín–méret kombináció külön ajánlatsor.', 'mockup-generator'); ?></p>
                    </div>
                </div>

                <?php if (!empty($_GET['mg_allegro_saved'])): ?>
                    <div class="notice notice-success inline"><p><?php esc_html_e('Az Allegro megfeleltetések mentve.', 'mockup-generator'); ?></p></div>
                <?php endif; ?>

                <div style="padding:14px 16px;margin:16px 0;background:#f0f6fc;border-left:4px solid #2271b1;">
                    <strong><?php esc_html_e('Hogyan működik?', 'mockup-generator'); ?></strong>
                    <p style="margin-bottom:0;"><?php esc_html_e('A Woo szín neve megmarad gyártói színként, mellette kiválasztod az Allegro kötelező főszínét. A méretet terméktípusonként kell megfeleltetni, mert ugyanaz a felirat eltérő Allegro-kategóriában mást jelenthet. Ismeretlen értékkel az export biztonságból leáll.', 'mockup-generator'); ?></p>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mg_allegro_save_settings">
                    <?php wp_nonce_field('mg_allegro_save_settings'); ?>

                    <h3><?php esc_html_e('Alapadatok', 'mockup-generator'); ?></h3>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th><label for="mg-allegro-brand"><?php esc_html_e('Alap márka', 'mockup-generator'); ?></label></th><td><input class="regular-text" id="mg-allegro-brand" name="brand" value="<?php echo esc_attr($settings['brand']); ?>"></td></tr>
                        <tr><th><label for="mg-allegro-material"><?php esc_html_e('Alap fő anyag', 'mockup-generator'); ?></label></th><td><input class="regular-text" id="mg-allegro-material" name="material" value="<?php echo esc_attr($settings['material']); ?>"></td></tr>
                        <tr><th><label for="mg-allegro-stock"><?php esc_html_e('Sablon készlet', 'mockup-generator'); ?></label></th><td><input type="number" min="1" id="mg-allegro-stock" name="default_stock" value="<?php echo esc_attr($settings['default_stock']); ?>"><p class="description"><?php esc_html_e('Ha a Woo termék nem kezel külön készletet, ez kerül minden Allegro variánsba.', 'mockup-generator'); ?></p></td></tr>
                    </tbody></table>

                    <h3><?php esc_html_e('Terméktípus és kategória', 'mockup-generator'); ?></h3>
                    <table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e('Woo terméktípus', 'mockup-generator'); ?></th><th><?php esc_html_e('Allegro kategóriaazonosító', 'mockup-generator'); ?></th></tr></thead><tbody>
                    <?php foreach ($dictionary['types'] as $type_slug => $type_label): ?>
                        <tr><td><strong><?php echo esc_html($type_label); ?></strong><br><code><?php echo esc_html($type_slug); ?></code></td><td><input inputmode="numeric" pattern="[0-9]*" name="category_map[<?php echo esc_attr($type_slug); ?>]" value="<?php echo esc_attr($settings['category_map'][$type_slug] ?? ''); ?>" placeholder="pl. 87913"><p class="description"><?php esc_html_e('Férfi póló: 87913, női póló: 76104, gyermek póló: 89528. Más típusnál add meg annak pontos kategóriáját.', 'mockup-generator'); ?></p></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>

                    <h3><?php esc_html_e('Színek megfeleltetése', 'mockup-generator'); ?></h3>
                    <table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e('Woo / gyártói szín', 'mockup-generator'); ?></th><th><?php esc_html_e('Allegro főszín', 'mockup-generator'); ?></th><th><?php esc_html_e('Állapot', 'mockup-generator'); ?></th></tr></thead><tbody>
                    <?php foreach ($dictionary['colors'] as $color_slug => $color_meta):
                        $key = MG_Allegro_Exporter::mapping_key($color_slug);
                        $mapped = MG_Allegro_Exporter::map_color($color_slug, $color_meta['label'], $settings['color_map']);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($color_meta['label']); ?></strong><br><code><?php echo esc_html($color_slug); ?></code></td>
                            <td><select name="color_map[<?php echo esc_attr($key); ?>]"><option value=""><?php esc_html_e('— válassz —', 'mockup-generator'); ?></option><?php foreach ($allowed_colors as $value): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($mapped, $value); ?>><?php echo esc_html($value); ?></option><?php endforeach; ?></select></td>
                            <td><?php echo $mapped !== '' ? '<span style="color:#16803b">✓ Kész</span>' : '<span style="color:#b32d2e">Hiányzik</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>

                    <h3><?php esc_html_e('Méretek megfeleltetése', 'mockup-generator'); ?></h3>
                    <p><?php esc_html_e('Az S, M, L, XL és hasonló szabványos értékeket automatikusan felismerjük (például 2XL → XXL). A gyermek korcsoportokat ne találjuk ki: azokat a gyártó centimétertáblája szerint add meg.', 'mockup-generator'); ?></p>
                    <?php foreach ($dictionary['types'] as $type_slug => $type_label): ?>
                        <details style="max-width:900px;margin:10px 0;border:1px solid #dcdcde;border-radius:4px;background:#fff" open>
                            <summary style="padding:10px 12px;cursor:pointer;font-weight:600"><?php echo esc_html($type_label); ?></summary>
                            <table class="widefat striped"><thead><tr><th><?php esc_html_e('Woo méret', 'mockup-generator'); ?></th><th><?php esc_html_e('Allegro méret', 'mockup-generator'); ?></th><th><?php esc_html_e('Állapot', 'mockup-generator'); ?></th></tr></thead><tbody>
                            <?php foreach ((array) ($dictionary['sizes'][$type_slug] ?? array()) as $source_size):
                                $size_key = MG_Allegro_Exporter::mapping_key($source_size);
                                $mapped = MG_Allegro_Exporter::map_size($type_slug, $source_size, $settings['size_map']);
                            ?>
                                <tr><td><strong><?php echo esc_html($source_size); ?></strong></td><td><input name="size_map[<?php echo esc_attr($type_slug); ?>][<?php echo esc_attr($size_key); ?>]" value="<?php echo esc_attr($mapped); ?>" placeholder="pl. XL vagy 122/128"></td><td><?php echo $mapped !== '' ? '<span style="color:#16803b">✓ Kész</span>' : '<span style="color:#b32d2e">Hiányzik</span>'; ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table>
                        </details>
                    <?php endforeach; ?>

                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Allegro beállítások mentése', 'mockup-generator'); ?></button></p>
                </form>

                <hr style="margin:28px 0">
                <h3><?php esc_html_e('CSV export készítése', 'mockup-generator'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mg_allegro_export_csv">
                    <?php wp_nonce_field('mg_allegro_export_csv'); ?>
                    <fieldset style="margin:12px 0"><legend class="screen-reader-text"><?php esc_html_e('Exportálandó terméktípusok', 'mockup-generator'); ?></legend>
                    <?php foreach ($dictionary['types'] as $type_slug => $type_label): ?>
                        <label style="display:inline-block;margin:0 18px 10px 0"><input type="checkbox" name="types[]" value="<?php echo esc_attr($type_slug); ?>" checked> <?php echo esc_html($type_label); ?></label>
                    <?php endforeach; ?>
                    </fieldset>
                    <p><label><input type="checkbox" name="only_unexported" value="1"> <?php esc_html_e('Csak a még nem exportált termék–típus párok', 'mockup-generator'); ?></label></p>
                    <p><button type="submit" class="button button-primary button-hero"><span class="dashicons dashicons-download" style="vertical-align:-4px"></span> <?php esc_html_e('Allegro CSV letöltése', 'mockup-generator'); ?></button></p>
                    <p class="description"><?php esc_html_e('A fájl az allegro-sync alkalmazásba importálható. Az export nem tesz közzé automatikusan ajánlatot.', 'mockup-generator'); ?></p>
                </form>
            </section>
        </div>
        <?php
    }
}
