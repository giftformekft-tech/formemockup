<?php
/**
 * Allegro export – CSV a forme.hu termékvariánsokból.
 *
 * A Temu-exporttal ellentétben itt nem kézzel feltöltendő XLSX készül, hanem
 * egy CSV, amit az önálló `allegro-sync` program olvas be és tölt fel API-n.
 * Ezért a fájl szerkezete szerződés: az oszlopneveket az allegro-sync
 * CsvReader ismeri.
 *
 * Három ponton tér el szándékosan a Temu-exporttól:
 *
 *  1. Az SKU DETERMINISZTIKUS. A Temu-export véletlen Sub SKU-t generál
 *     (str_shuffle), ami az Allegrón duplikált ajánlatokat okozna, mert az
 *     SKU egyben az idempotencia-kulcs (external.id).
 *  2. Van ÁR és KÉSZLET. Ezek nélkül az Allegro nem tud ajánlatot létrehozni.
 *  3. Nincs kamu méretsor. A Temu-export a 12-es gyerekméretből csinál egy
 *     14Y sort is – az az ottani sablon miatt kell, itt hibás adat lenne.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Allegro_Export_Page {

    /** Az allegro-sync CsvReader által várt oszlopsorrend. */
    const CSV_COLUMNS = [
        'sku', 'parent_sku', 'name', 'description', 'type', 'type_label',
        'color', 'size', 'price_huf', 'stock', 'image_url',
        'weight_g', 'brand', 'material', 'ai_content',
    ];

    public static function init() {
        add_action('wp_ajax_mg_allegro_get_products', [self::class, 'ajax_get_products']);
        add_action('wp_ajax_mg_allegro_get_variants', [self::class, 'ajax_get_variants']);
        add_action('wp_ajax_mg_allegro_generate_csv', [self::class, 'ajax_generate_csv']);
        add_action('wp_ajax_mg_allegro_save_settings', [self::class, 'ajax_save_settings']);
    }

    // ------------------------------------------------------------- beállítások

    public static function get_settings() {
        $defaults = [
            'price_multiplier' => 1.0,
            'stock'            => 100,
            'brand'            => '',
            'ai_content'       => 0,
        ];
        $saved = get_option('mg_allegro_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $settings = array_merge($defaults, $saved);
        $settings['price_multiplier'] = (float) $settings['price_multiplier'];
        if ($settings['price_multiplier'] <= 0) {
            $settings['price_multiplier'] = 1.0;
        }
        $settings['stock'] = max(0, (int) $settings['stock']);

        return $settings;
    }

    /** Terméktípusonkénti súly és anyag. */
    public static function get_type_meta() {
        $meta = get_option('mg_allegro_type_meta', []);

        return is_array($meta) ? $meta : [];
    }

    public static function ajax_save_settings() {
        check_ajax_referer('mg_allegro_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultság.');
        }

        $settings = [
            'price_multiplier' => isset($_POST['price_multiplier']) ? (float) wp_unslash($_POST['price_multiplier']) : 1.0,
            'stock'            => isset($_POST['stock']) ? (int) wp_unslash($_POST['stock']) : 100,
            'brand'            => isset($_POST['brand']) ? sanitize_text_field(wp_unslash($_POST['brand'])) : '',
            'ai_content'       => !empty($_POST['ai_content']) ? 1 : 0,
        ];
        update_option('mg_allegro_settings', $settings);

        $raw_meta = isset($_POST['type_meta']) ? wp_unslash($_POST['type_meta']) : '';
        if (is_string($raw_meta) && $raw_meta !== '') {
            $decoded = json_decode($raw_meta, true);
            if (is_array($decoded)) {
                $clean = [];
                foreach ($decoded as $slug => $entry) {
                    $slug = sanitize_title($slug);
                    if ($slug === '' || !is_array($entry)) {
                        continue;
                    }
                    $clean[$slug] = [
                        'weight'   => isset($entry['weight']) ? max(0, (int) $entry['weight']) : 0,
                        'material' => isset($entry['material']) ? sanitize_text_field($entry['material']) : '',
                    ];
                }
                update_option('mg_allegro_type_meta', $clean);
            }
        }

        wp_send_json_success(['message' => 'Beállítások mentve.']);
    }

    // ------------------------------------------------------------- terméklista

    public static function ajax_get_products() {
        check_ajax_referer('mg_allegro_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultság.');
        }

        $page            = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page        = isset($_POST['per_page']) ? max(1, min(100, (int) $_POST['per_page'])) : 25;
        $only_unexported = !empty($_POST['only_unexported']);
        $category_id     = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

        $args = [
            'status'   => 'publish',
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
        ];

        if ($only_unexported) {
            global $wpdb;
            $exported_ids = $wpdb->get_col(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mg_allegro_exported' AND meta_value != ''"
            );
            $args['exclude'] = !empty($exported_ids) ? $exported_ids : [-1];
        }

        if ($category_id > 0) {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $args['category'] = [$term->slug];
            }
        }

        $results  = wc_get_products($args);
        $products = [];

        foreach ($results->products as $product) {
            $image_id  = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src();

            $products[] = [
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'sku'         => $product->get_sku(),
                'image'       => $image_url,
                'exported_at' => (string) get_post_meta($product->get_id(), '_mg_allegro_exported', true),
            ];
        }

        wp_send_json_success([
            'products'    => $products,
            'total_pages' => $results->max_num_pages,
            'total'       => $results->total,
        ]);
    }

    public static function ajax_get_variants() {
        check_ajax_referer('mg_allegro_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultság.');
        }
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            wp_send_json_error('Hiányzik az MG_Virtual_Variant_Manager osztály.');
        }

        $product_ids = isset($_POST['product_ids']) ? (array) wp_unslash($_POST['product_ids']) : [];
        $data        = [];

        foreach ($product_ids as $pid) {
            $pid     = (int) $pid;
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            if (empty($config['types']) || !is_array($config['types'])) {
                continue;
            }

            $types = [];
            foreach ($config['types'] as $type_slug => $type) {
                $colors = [];
                foreach ((array) ($type['colors'] ?? []) as $color_slug => $color) {
                    $colors[] = [
                        'slug'  => $color_slug,
                        'label' => isset($color['label']) ? $color['label'] : $color_slug,
                        'sizes' => array_values((array) ($color['sizes'] ?? [])),
                    ];
                }
                $types[] = [
                    'slug'   => $type_slug,
                    'label'  => isset($type['label']) ? $type['label'] : $type_slug,
                    'price'  => isset($type['price']) ? (float) $type['price'] : 0.0,
                    'colors' => $colors,
                ];
            }

            $data[] = [
                'id'    => $pid,
                'name'  => $product->get_name(),
                'types' => $types,
            ];
        }

        wp_send_json_success($data);
    }

    // ------------------------------------------------------------------ export

    public static function ajax_generate_csv() {
        check_ajax_referer('mg_allegro_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultság.');
        }

        $selection = isset($_POST['selection']) ? wp_unslash($_POST['selection']) : '';
        if (is_string($selection)) {
            $selection = json_decode($selection, true);
        }
        if (!is_array($selection) || $selection === []) {
            wp_send_json_error('Nincs kiválasztott variáns.');
        }

        $result = self::build_export_rows($selection);
        $rows   = $result['rows'];

        if ($rows === []) {
            wp_send_json_error('A kiválasztásból nem keletkezett exportsor.');
        }

        $upload_dir = wp_upload_dir();
        $filename   = 'allegro-export-' . gmdate('Y-m-d-H-i-s') . '.csv';
        $filepath   = trailingslashit($upload_dir['path']) . $filename;

        $fp = fopen($filepath, 'w');
        if (!$fp) {
            wp_send_json_error('A fájl nem hozható létre.');
        }

        // BOM az Excel kedvéért – az allegro-sync leszedi.
        fwrite($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fp, self::CSV_COLUMNS, ';');

        foreach ($rows as $row) {
            $line = [];
            foreach (self::CSV_COLUMNS as $column) {
                $line[] = isset($row[$column]) ? $row[$column] : '';
            }
            fputcsv($fp, $line, ';');
        }
        fclose($fp);

        self::mark_selection_exported($selection);

        wp_send_json_success([
            'url'      => trailingslashit($upload_dir['url']) . $filename,
            'filename' => $filename,
            'rows'     => count($rows),
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * A kiválasztott variánsokból exportsorok.
     *
     * @param array $selection [ { pid, type, color, size }, ... ]
     * @return array{rows:array,warnings:array}
     */
    public static function build_export_rows(array $selection) {
        $settings  = self::get_settings();
        $type_meta = self::get_type_meta();

        $rows     = [];
        $warnings = [];
        $seen     = [];

        $product_cache = [];
        $config_cache  = [];
        $desc_cache    = [];

        foreach ($selection as $item) {
            $pid = isset($item['pid']) ? (int) $item['pid'] : 0;
            if ($pid <= 0) {
                continue;
            }

            if (!array_key_exists($pid, $product_cache)) {
                $product_cache[$pid] = wc_get_product($pid);
                $config_cache[$pid]  = $product_cache[$pid]
                    ? MG_Virtual_Variant_Manager::get_frontend_config($product_cache[$pid])
                    : [];
                $desc_cache[$pid]    = $product_cache[$pid]
                    ? self::build_description($product_cache[$pid])
                    : '';
            }

            $product = $product_cache[$pid];
            $config  = $config_cache[$pid];
            if (!$product || empty($config['types'])) {
                continue;
            }

            $type_slug  = isset($item['type']) ? sanitize_title($item['type']) : '';
            $color_slug = isset($item['color']) ? sanitize_title($item['color']) : '';
            $size       = isset($item['size']) ? trim((string) $item['size']) : '';

            if ($type_slug === '' || $color_slug === '' || $size === '') {
                continue;
            }

            $type = isset($config['types'][$type_slug]) ? $config['types'][$type_slug] : null;
            if (!$type) {
                $warnings[] = sprintf('%s: ismeretlen típus (%s) – kihagyva.', $product->get_name(), $type_slug);
                continue;
            }

            $color = isset($type['colors'][$color_slug]) ? $type['colors'][$color_slug] : null;
            if (!$color) {
                $warnings[] = sprintf('%s: ismeretlen szín (%s) – kihagyva.', $product->get_name(), $color_slug);
                continue;
            }

            $base_sku = '';
            if (isset($config['product']['sku']) && $config['product']['sku'] !== '') {
                $base_sku = (string) $config['product']['sku'];
            } elseif ($product->get_sku()) {
                $base_sku = (string) $product->get_sku();
            }
            if ($base_sku === '') {
                $warnings[] = sprintf('%s: nincs SKU – kihagyva.', $product->get_name());
                continue;
            }

            // Determinisztikus, stabil SKU. Ez lesz az Allegro external.id-ja,
            // tehát MINDEN futásnál ugyanaznak kell lennie, különben duplikált
            // ajánlatok keletkeznek.
            $sku = self::variant_sku($base_sku, $type_slug, $color_slug, $size);
            if (isset($seen[$sku])) {
                continue;
            }
            $seen[$sku] = true;

            $price = self::variant_price($type, $color, $size, $settings['price_multiplier']);
            if ($price <= 0) {
                $warnings[] = sprintf('%s (%s): nulla ár – ellenőrizd a típus árát.', $product->get_name(), $sku);
            }

            $meta = isset($type_meta[$type_slug]) ? $type_meta[$type_slug] : [];

            $rows[] = [
                'sku'         => $sku,
                'parent_sku'  => $base_sku,
                'name'        => $product->get_name(),
                'description' => $desc_cache[$pid],
                'type'        => $type_slug,
                'type_label'  => isset($type['label']) ? $type['label'] : $type_slug,
                'color'       => isset($color['label']) ? $color['label'] : $color_slug,
                'size'        => strtoupper($size),
                'price_huf'   => (string) $price,
                'stock'       => (string) $settings['stock'],
                'image_url'   => self::mockup_url($config, $base_sku, $type_slug, $color_slug),
                'weight_g'    => isset($meta['weight']) && (int) $meta['weight'] > 0 ? (string) (int) $meta['weight'] : '',
                'brand'       => $settings['brand'],
                'material'    => isset($meta['material']) ? $meta['material'] : '',
                'ai_content'  => $settings['ai_content'] ? 'igen' : '',
            ];
        }

        return ['rows' => $rows, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * A bolti ár a típus árából, a szín és a méret felárából áll össze.
     * Az Allegro-ár ezt szorozza a beállított szorzóval (jutalék, szállítás).
     */
    private static function variant_price($type, $color, $size, $multiplier) {
        $price = isset($type['price']) ? (float) $type['price'] : 0.0;
        $price += isset($color['surcharge']) ? (float) $color['surcharge'] : 0.0;

        $size_key = strtolower(trim((string) $size));
        if (!empty($type['size_surcharges']) && is_array($type['size_surcharges'])) {
            foreach ($type['size_surcharges'] as $key => $surcharge) {
                if (strtolower(trim((string) $key)) === $size_key) {
                    $price += (float) $surcharge;
                    break;
                }
            }
        }

        // HUF-ban nincs értelme fillérnek, és az API stringként várja az árat –
        // egész forintra kerekítünk, hogy ne keletkezzen lebegőpontos maradék.
        return (int) round($price * (float) $multiplier);
    }

    private static function variant_sku($base_sku, $type_slug, $color_slug, $size) {
        $parts = [$base_sku, $type_slug, $color_slug, $size];
        $parts = array_map(function ($part) {
            $part = remove_accents((string) $part);
            $part = strtoupper($part);
            $part = preg_replace('/[^A-Z0-9]+/', '', $part);

            return $part;
        }, $parts);

        return implode('-', array_filter($parts, 'strlen'));
    }

    /**
     * A mockup URL-je kiszámítható a konfigurációból:
     * {baseUrl}/{sku}/{sku}_{type}_{color}_front.webp
     */
    private static function mockup_url($config, $base_sku, $type_slug, $color_slug) {
        $base_url = '';
        if (isset($config['mockup']['baseUrl'])) {
            $base_url = (string) $config['mockup']['baseUrl'];
        }
        if ($base_url === '') {
            $uploads  = wp_upload_dir();
            $base_url = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '';
        }
        if ($base_url === '') {
            return '';
        }

        $file = $base_sku . '_' . $type_slug . '_' . $color_slug . '_front.webp';

        return trailingslashit($base_url) . $base_sku . '/' . $file;
    }

    /** A leírás a kategória-SEO szövegből jön, ahogy a Temu-exportnál is. */
    private static function build_description($product) {
        $description = '';

        if (function_exists('mgtd__build_description_context')) {
            $context = mgtd__build_description_context($product);
            if (!empty($context['category_seos'])) {
                $description = $context['category_seos'];
            } elseif (!empty($context['category_seo'])) {
                $description = $context['category_seo'];
            }
        }

        if ($description === '') {
            $description = $product->get_description();
        }

        return trim(wp_kses_post($description));
    }

    private static function mark_selection_exported(array $selection) {
        $now  = current_time('Y-m-d H:i');
        $pids = [];
        foreach ($selection as $item) {
            $pid = isset($item['pid']) ? (int) $item['pid'] : 0;
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }
        foreach (array_keys($pids) as $pid) {
            update_post_meta($pid, '_mg_allegro_exported', $now);
        }
    }

    // -------------------------------------------------------------------- UI

    public static function render_page() {
        $settings  = self::get_settings();
        $type_meta = self::get_type_meta();
        $types     = self::get_all_types();
        ?>
        <div class="mg-panel-body mg-panel-body--allegro-export">
            <section class="mg-panel-section">
                <div class="mg-panel-section__header">
                    <div>
                        <h2><?php esc_html_e('Allegro Export', 'mockup-generator'); ?></h2>
                        <p><?php esc_html_e('CSV a termékvariánsokból az allegro-sync programnak, ami feltölti őket az Allegróra.', 'mockup-generator'); ?></p>
                    </div>
                </div>

                <div style="margin-bottom:16px;padding:12px 14px;background:#fff;border:1px solid #ddd;border-radius:8px;">
                    <strong><?php esc_html_e('Export beállítások', 'mockup-generator'); ?></strong>
                    <table class="widefat striped" style="max-width:720px;margin-top:8px;">
                        <tbody>
                            <tr>
                                <td style="width:220px;"><?php esc_html_e('Árszorzó', 'mockup-generator'); ?></td>
                                <td>
                                    <input type="number" step="0.01" min="0.01" id="mg-allegro-multiplier" value="<?php echo esc_attr($settings['price_multiplier']); ?>" style="width:120px;">
                                    <span style="color:#888;font-size:12px;"><?php esc_html_e('A bolti ár szorzója. Fedezze a jutalékot és a szállítást.', 'mockup-generator'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Készlet', 'mockup-generator'); ?></td>
                                <td>
                                    <input type="number" min="0" id="mg-allegro-stock" value="<?php echo esc_attr($settings['stock']); ?>" style="width:120px;">
                                    <span style="color:#888;font-size:12px;"><?php esc_html_e('Saját gyártásnál fix, magas érték szokott lenni.', 'mockup-generator'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Márka', 'mockup-generator'); ?></td>
                                <td><input type="text" id="mg-allegro-brand" value="<?php echo esc_attr($settings['brand']); ?>" style="width:260px;" placeholder="forme"></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('AI-val készült tartalom', 'mockup-generator'); ?></td>
                                <td>
                                    <label>
                                        <input type="checkbox" id="mg-allegro-ai" <?php checked($settings['ai_content'], 1); ?>>
                                        <?php esc_html_e('A leírás vagy a kép AI-val készült', 'mockup-generator'); ?>
                                    </label>
                                    <span style="color:#888;font-size:12px;"><?php esc_html_e('Az Allegro külön mezőben kéri ennek deklarálását.', 'mockup-generator'); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if (!empty($types)): ?>
                    <p style="margin:12px 0 4px;"><strong><?php esc_html_e('Terméktípusonként', 'mockup-generator'); ?></strong>
                        <span style="color:#888;font-size:12px;"><?php esc_html_e('A súly a szállítási díjszabáshoz kell.', 'mockup-generator'); ?></span>
                    </p>
                    <table class="widefat striped" style="max-width:720px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Típus', 'mockup-generator'); ?></th>
                                <th style="width:140px;"><?php esc_html_e('Súly (g)', 'mockup-generator'); ?></th>
                                <th><?php esc_html_e('Anyag', 'mockup-generator'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($types as $slug => $label):
                            $entry = isset($type_meta[$slug]) ? $type_meta[$slug] : []; ?>
                            <tr>
                                <td><?php echo esc_html($label); ?></td>
                                <td><input type="number" min="0" class="mg-allegro-type-weight" data-type="<?php echo esc_attr($slug); ?>" value="<?php echo esc_attr(isset($entry['weight']) ? $entry['weight'] : ''); ?>" style="width:110px;"></td>
                                <td><input type="text" class="mg-allegro-type-material" data-type="<?php echo esc_attr($slug); ?>" value="<?php echo esc_attr(isset($entry['material']) ? $entry['material'] : ''); ?>" style="width:100%;" placeholder="pl. 100% pamut"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <p style="margin-top:10px;">
                        <button type="button" class="button button-primary" id="mg-allegro-save-settings"><?php esc_html_e('Beállítások mentése', 'mockup-generator'); ?></button>
                        <span id="mg-allegro-settings-status" style="font-style:italic;color:#666;font-size:12px;margin-left:8px;"></span>
                    </p>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="margin-right:12px;">
                        <input type="checkbox" id="mg-allegro-only-unexported">
                        <?php esc_html_e('Csak a még nem exportált termékek', 'mockup-generator'); ?>
                    </label>
                    <button type="button" class="button" id="mg-allegro-load"><?php esc_html_e('Termékek betöltése', 'mockup-generator'); ?></button>
                    <span id="mg-allegro-load-status" style="font-style:italic;color:#666;font-size:12px;margin-left:8px;"></span>
                </div>

                <div id="mg-allegro-products"></div>

                <div style="margin-top:16px;">
                    <button type="button" class="button button-primary" id="mg-allegro-generate" disabled><?php esc_html_e('Allegro CSV generálása', 'mockup-generator'); ?></button>
                    <span id="mg-allegro-generate-status" style="font-style:italic;color:#666;font-size:12px;margin-left:8px;"></span>
                </div>

                <div id="mg-allegro-result" style="margin-top:12px;"></div>
            </section>
        </div>
        <?php
        self::render_scripts();
    }

    private static function render_scripts() {
        $nonce = wp_create_nonce('mg_allegro_nonce');
        ?>
        <script>
        (function ($) {
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var variantCache = {};

            function post(action, data) {
                return $.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, data || {}));
            }

            $('#mg-allegro-save-settings').on('click', function () {
                var typeMeta = {};
                $('.mg-allegro-type-weight').each(function () {
                    var slug = $(this).data('type');
                    typeMeta[slug] = typeMeta[slug] || {};
                    typeMeta[slug].weight = $(this).val();
                });
                $('.mg-allegro-type-material').each(function () {
                    var slug = $(this).data('type');
                    typeMeta[slug] = typeMeta[slug] || {};
                    typeMeta[slug].material = $(this).val();
                });

                $('#mg-allegro-settings-status').text('Mentés…');
                post('mg_allegro_save_settings', {
                    price_multiplier: $('#mg-allegro-multiplier').val(),
                    stock: $('#mg-allegro-stock').val(),
                    brand: $('#mg-allegro-brand').val(),
                    ai_content: $('#mg-allegro-ai').is(':checked') ? 1 : 0,
                    type_meta: JSON.stringify(typeMeta)
                }).done(function (res) {
                    $('#mg-allegro-settings-status').text(res && res.success ? '✓ Mentve.' : 'Hiba a mentésnél.');
                }).fail(function () {
                    $('#mg-allegro-settings-status').text('Hiba a mentésnél.');
                });
            });

            $('#mg-allegro-load').on('click', function () {
                $('#mg-allegro-load-status').text('Betöltés…');
                post('mg_allegro_get_products', {
                    page: 1,
                    per_page: 50,
                    only_unexported: $('#mg-allegro-only-unexported').is(':checked') ? 1 : 0
                }).done(function (res) {
                    if (!res || !res.success) {
                        $('#mg-allegro-load-status').text('Nem sikerült betölteni.');
                        return;
                    }
                    renderProducts(res.data.products);
                    $('#mg-allegro-load-status').text(res.data.products.length + ' termék betöltve (összesen ' + res.data.total + ').');
                }).fail(function () {
                    $('#mg-allegro-load-status').text('Nem sikerült betölteni.');
                });
            });

            function renderProducts(products) {
                if (!products.length) {
                    $('#mg-allegro-products').html('<p>Nincs megjeleníthető termék.</p>');
                    $('#mg-allegro-generate').prop('disabled', true);
                    return;
                }

                var html = '<table class="widefat striped"><thead><tr>' +
                    '<td style="width:28px;"><input type="checkbox" id="mg-allegro-all"></td>' +
                    '<th>Termék</th><th style="width:160px;">SKU</th><th style="width:150px;">Exportálva</th>' +
                    '</tr></thead><tbody>';

                products.forEach(function (p) {
                    html += '<tr>' +
                        '<td><input type="checkbox" class="mg-allegro-pick" value="' + p.id + '"></td>' +
                        '<td>' + escapeHtml(p.name) + '</td>' +
                        '<td>' + escapeHtml(p.sku || '') + '</td>' +
                        '<td>' + escapeHtml(p.exported_at || '–') + '</td>' +
                        '</tr>';
                });

                $('#mg-allegro-products').html(html + '</tbody></table>');
                $('#mg-allegro-generate').prop('disabled', false);
            }

            $(document).on('change', '#mg-allegro-all', function () {
                $('.mg-allegro-pick').prop('checked', $(this).is(':checked'));
            });

            $('#mg-allegro-generate').on('click', function () {
                var ids = $('.mg-allegro-pick:checked').map(function () { return $(this).val(); }).get();
                if (!ids.length) {
                    $('#mg-allegro-generate-status').text('Válassz legalább egy terméket.');
                    return;
                }

                $('#mg-allegro-generate-status').text('Variánsok lekérése…');
                post('mg_allegro_get_variants', { product_ids: ids }).done(function (res) {
                    if (!res || !res.success) {
                        $('#mg-allegro-generate-status').text('A variánsok lekérése nem sikerült.');
                        return;
                    }

                    var selection = [];
                    res.data.forEach(function (product) {
                        product.types.forEach(function (type) {
                            type.colors.forEach(function (color) {
                                color.sizes.forEach(function (size) {
                                    selection.push({
                                        pid: product.id,
                                        type: type.slug,
                                        color: color.slug,
                                        size: size
                                    });
                                });
                            });
                        });
                    });

                    if (!selection.length) {
                        $('#mg-allegro-generate-status').text('A kiválasztott termékeknek nincs variánsa.');
                        return;
                    }

                    $('#mg-allegro-generate-status').text(selection.length + ' variáns, CSV készítése…');

                    post('mg_allegro_generate_csv', { selection: JSON.stringify(selection) }).done(function (out) {
                        if (!out || !out.success) {
                            $('#mg-allegro-generate-status').text(out && out.data ? out.data : 'A CSV készítése nem sikerült.');
                            return;
                        }
                        $('#mg-allegro-generate-status').text('✓ Kész: ' + out.data.rows + ' sor.');

                        var html = '<p><a class="button button-primary" href="' + out.data.url + '" download>' +
                            'Letöltés: ' + escapeHtml(out.data.filename) + '</a></p>';

                        if (out.data.warnings && out.data.warnings.length) {
                            html += '<div class="notice notice-warning inline"><p><strong>Figyelmeztetések:</strong></p><ul style="margin-left:18px;list-style:disc;">';
                            out.data.warnings.forEach(function (w) {
                                html += '<li>' + escapeHtml(w) + '</li>';
                            });
                            html += '</ul></div>';
                        }

                        html += '<p style="color:#666;font-size:12px;">Ellenőrzés feltöltés előtt:<br>' +
                            '<code>bin/allegro import:validate &lt;fájl&gt; --titles</code></p>';

                        $('#mg-allegro-result').html(html);
                    }).fail(function () {
                        $('#mg-allegro-generate-status').text('A CSV készítése nem sikerült.');
                    });
                }).fail(function () {
                    $('#mg-allegro-generate-status').text('A variánsok lekérése nem sikerült.');
                });
            });

            function escapeHtml(text) {
                return $('<div>').text(text == null ? '' : text).html();
            }
        })(jQuery);
        </script>
        <?php
    }

    private static function get_all_types() {
        $types = [];
        if (class_exists('MG_Variant_Display_Manager')) {
            $catalog = MG_Variant_Display_Manager::get_catalog_index();
            if (is_array($catalog)) {
                foreach ($catalog as $slug => $meta) {
                    $slug = sanitize_title($slug);
                    if ($slug === '') {
                        continue;
                    }
                    $types[$slug] = (is_array($meta) && !empty($meta['label'])) ? $meta['label'] : $slug;
                }
            }
        }

        return $types;
    }
}
