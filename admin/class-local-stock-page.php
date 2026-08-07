<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin felület a helyi póló készlethez.
 *
 * Négy képernyő az admin shell „Készlet” csoportjában:
 *  - matrix   : szín × méret rács egy terméktípusra, ez a kitöltő felület
 *  - goods_in : bevételezés a nagyker CSV formátumából
 *  - low      : hiánylista (elfogyott / biztonsági szint alatti sorok)
 *  - log      : mozgásnapló
 */
class MG_Local_Stock_Page {

    const CAPABILITY = 'manage_woocommerce';

    public static function init() {
        add_action('admin_post_mg_local_stock_save_matrix', array(__CLASS__, 'handle_save_matrix'));
        add_action('admin_post_mg_local_stock_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_mg_local_stock_goods_in', array(__CLASS__, 'handle_goods_in'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['mg_tab']) ? sanitize_key(wp_unslash($_GET['mg_tab'])) : '';
        if ($page !== 'mockup-generator' || strpos($tab, 'stock_') !== 0) {
            return;
        }

        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $css_path = dirname(__DIR__) . '/assets/css/local-stock-admin.css';
        $js_path = dirname(__DIR__) . '/assets/js/local-stock-admin.js';

        wp_enqueue_style(
            'mg-local-stock-admin',
            plugins_url('assets/css/local-stock-admin.css', $base_file),
            array(),
            file_exists($css_path) ? filemtime($css_path) : MG_VERSION
        );
        wp_enqueue_script(
            'mg-local-stock-admin',
            plugins_url('assets/js/local-stock-admin.js', $base_file),
            array('jquery'),
            file_exists($js_path) ? filemtime($js_path) : MG_VERSION,
            true
        );
    }

    private static function tab_url($tab, $args = array()) {
        return add_query_arg(
            array_merge(array('page' => 'mockup-generator', 'mg_tab' => $tab), $args),
            admin_url('admin.php')
        );
    }

    /**
     * A shell hívja meg, képernyőnként.
     *
     * @param string $screen matrix|goods_in|low|log
     */
    public static function render_page($screen = 'matrix') {
        if (!current_user_can(self::CAPABILITY)) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Nincs jogosultságod a készlet kezeléséhez.', 'mockup-generator') . '</p></div>';
            return;
        }

        MG_Local_Stock::maybe_install();
        self::render_notices();

        switch ($screen) {
            case 'goods_in':
                self::render_goods_in();
                break;
            case 'low':
                self::render_low_stock();
                break;
            case 'log':
                self::render_log();
                break;
            case 'matrix':
            default:
                self::render_matrix();
                break;
        }
    }

    private static function render_notices() {
        $notice = isset($_GET['mg_stock_notice']) ? sanitize_key(wp_unslash($_GET['mg_stock_notice'])) : '';
        if ($notice === '') {
            return;
        }
        $count = isset($_GET['mg_stock_count']) ? absint($_GET['mg_stock_count']) : 0;

        $messages = array(
            'saved' => array(
                'success',
                sprintf(
                    /* translators: %d: number of changed cells */
                    _n('%d készletsor frissítve.', '%d készletsor frissítve.', $count, 'mockup-generator'),
                    $count
                ),
            ),
            'nochange' => array('info', __('Nem változott egyetlen készletsor sem.', 'mockup-generator')),
            'settings' => array('success', __('Beállítások elmentve.', 'mockup-generator')),
            'goods_in' => array(
                'success',
                sprintf(
                    /* translators: %d: number of pieces booked in */
                    _n('%d db bevételezve.', '%d db bevételezve.', $count, 'mockup-generator'),
                    $count
                ),
            ),
            'invalid_type' => array('error', __('Ismeretlen terméktípus.', 'mockup-generator')),
        );

        if (!isset($messages[$notice])) {
            return;
        }
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($messages[$notice][0]),
            esc_html($messages[$notice][1])
        );
    }

    /* ---------------------------------------------------------------- matrix */

    private static function render_matrix() {
        $types = MG_Local_Stock::get_types();

        echo '<div class="mg-local-stock">';
        echo '<h1>' . esc_html__('Helyi készlet', 'mockup-generator') . '</h1>';

        if (empty($types)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Még nincs egyetlen terméktípus sem felvéve virtuális variánsként, így nincs mit nyilvántartani.', 'mockup-generator') . '</p></div>';
            echo '</div>';
            return;
        }

        $selected = isset($_GET['mg_stock_type']) ? sanitize_title(wp_unslash($_GET['mg_stock_type'])) : '';
        if ($selected === '' || !isset($types[$selected])) {
            $selected = key($types);
        }

        self::render_settings_box($selected);

        // Terméktípus választó
        echo '<form method="get" class="mg-ls-toolbar">';
        echo '<input type="hidden" name="page" value="mockup-generator" />';
        echo '<input type="hidden" name="mg_tab" value="stock_matrix" />';
        echo '<label for="mg-ls-type">' . esc_html__('Terméktípus', 'mockup-generator') . '</label> ';
        echo '<select id="mg-ls-type" name="mg_stock_type" onchange="this.form.submit()">';
        foreach ($types as $slug => $label) {
            printf(
                '<option value="%1$s"%3$s>%2$s</option>',
                esc_attr($slug),
                esc_html($label),
                selected($slug, $selected, false)
            );
        }
        echo '</select> ';
        echo '<noscript><button type="submit" class="button">' . esc_html__('Mutasd', 'mockup-generator') . '</button></noscript>';
        echo '</form>';

        $matrix = MG_Local_Stock::get_type_matrix($selected);
        if (!$matrix || empty($matrix['colors']) || empty($matrix['sizes'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Ehhez a terméktípushoz nincs szín vagy méret felvéve.', 'mockup-generator') . '</p></div>';
            echo '</div>';
            return;
        }

        $levels = MG_Local_Stock::get_levels_for_type($selected);

        echo '<p class="description">' . esc_html__('Írd be, hány darab van raktáron az egyes szín/méret párokból. A szürke cellákhoz nem létezik variáns. A biztonsági készlet az a mennyiség, ami alá a nagyker export nem nyúl.', 'mockup-generator') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mg-ls-form">';
        wp_nonce_field('mg_local_stock_save_matrix');
        echo '<input type="hidden" name="action" value="mg_local_stock_save_matrix" />';
        echo '<input type="hidden" name="type_slug" value="' . esc_attr($selected) . '" />';

        echo '<div class="mg-ls-actions">';
        echo '<label class="mg-ls-toggle"><input type="checkbox" id="mg-ls-show-safety" /> ' . esc_html__('Biztonsági készlet szerkesztése', 'mockup-generator') . '</label>';
        echo '<button type="button" class="button" data-mg-ls-zero>' . esc_html__('Minden cella nullázása', 'mockup-generator') . '</button>';
        echo '</div>';

        echo '<div class="mg-ls-scroller">';
        echo '<table class="mg-ls-grid widefat">';
        echo '<thead><tr>';
        echo '<th class="mg-ls-corner">' . esc_html__('Szín', 'mockup-generator') . '</th>';
        foreach ($matrix['sizes'] as $index => $size) {
            echo '<th class="mg-ls-size">';
            echo '<span class="mg-ls-size__label">' . esc_html($size['label']) . '</span>';
            printf(
                '<button type="button" class="mg-ls-fill" data-mg-ls-fill-col="%1$d" title="%2$s">%3$s</button>',
                (int) $index,
                esc_attr__('Oszlop kitöltése', 'mockup-generator'),
                esc_html__('↓', 'mockup-generator')
            );
            echo '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($matrix['colors'] as $color) {
            echo '<tr>';
            echo '<th scope="row" class="mg-ls-color">';
            if ($color['hex'] !== '') {
                echo '<span class="mg-ls-swatch" style="background:' . esc_attr($color['hex']) . '"></span>';
            }
            echo '<span class="mg-ls-color__label">' . esc_html($color['label']) . '</span>';
            if ($color['utt_sku'] !== '') {
                echo '<code class="mg-ls-sku">' . esc_html($color['utt_sku']) . '</code>';
            } else {
                echo '<span class="mg-ls-sku mg-ls-sku--missing" title="' . esc_attr__('Nincs UTT cikkszám beállítva ehhez a színhez', 'mockup-generator') . '">' . esc_html__('nincs cikkszám', 'mockup-generator') . '</span>';
            }
            printf(
                '<button type="button" class="mg-ls-fill" data-mg-ls-fill-row="1" title="%1$s">%2$s</button>',
                esc_attr__('Sor kitöltése', 'mockup-generator'),
                esc_html__('→', 'mockup-generator')
            );
            echo '</th>';

            foreach ($matrix['sizes'] as $index => $size) {
                $allowed = !empty($matrix['allowed'][$color['slug']][$size['key']]);
                if (!$allowed) {
                    echo '<td class="mg-ls-cell mg-ls-cell--disabled" data-col="' . (int) $index . '"><span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__('Nincs ilyen variáns', 'mockup-generator') . '</span></td>';
                    continue;
                }

                $level = isset($levels[$color['slug']][$size['key']]) ? $levels[$color['slug']][$size['key']] : array('qty' => 0, 'safety' => 0);
                $qty = (int) $level['qty'];
                $safety = (int) $level['safety'];

                $classes = 'mg-ls-cell';
                if ($qty <= 0) {
                    $classes .= ' is-empty';
                } elseif ($qty <= $safety) {
                    $classes .= ' is-low';
                }

                printf(
                    '<td class="%1$s" data-col="%2$d">',
                    esc_attr($classes),
                    (int) $index
                );
                printf(
                    '<input type="number" min="0" step="1" class="mg-ls-qty" name="qty[%1$s][%2$s]" value="%3$d" data-initial="%3$d" aria-label="%4$s" />',
                    esc_attr($color['slug']),
                    esc_attr($size['key']),
                    $qty,
                    esc_attr(sprintf('%s / %s', $color['label'], $size['label']))
                );
                printf(
                    '<input type="number" min="0" step="1" class="mg-ls-safety" name="safety[%1$s][%2$s]" value="%3$d" aria-label="%4$s" />',
                    esc_attr($color['slug']),
                    esc_attr($size['key']),
                    $safety,
                    esc_attr(sprintf('%s / %s – biztonsági készlet', $color['label'], $size['label']))
                );
                echo '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Készlet mentése', 'mockup-generator') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    private static function render_settings_box($type_slug = '') {
        $settings = MG_Local_Stock::get_settings();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mg-ls-settings">';
        wp_nonce_field('mg_local_stock_save_settings');
        echo '<input type="hidden" name="action" value="mg_local_stock_save_settings" />';
        echo '<input type="hidden" name="mg_stock_type" value="' . esc_attr($type_slug) . '" />';
        echo '<label><input type="checkbox" name="enabled" value="yes"' . checked($settings['enabled'], 'yes', false) . ' /> ' . esc_html__('Nagyker export levonja a helyi készletet', 'mockup-generator') . '</label>';
        echo '<label><input type="checkbox" name="preview" value="yes"' . checked($settings['preview'], 'yes', false) . ' /> ' . esc_html__('Megerősítő előnézet a CSV letöltése előtt', 'mockup-generator') . '</label>';
        echo '<button type="submit" class="button">' . esc_html__('Beállítások mentése', 'mockup-generator') . '</button>';
        echo '</form>';
    }

    public static function handle_save_matrix() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Nincs jogosultságod a készlet kezeléséhez.', 'mockup-generator'));
        }
        check_admin_referer('mg_local_stock_save_matrix');

        $type_slug = isset($_POST['type_slug']) ? sanitize_title(wp_unslash($_POST['type_slug'])) : '';
        $matrix = $type_slug !== '' ? MG_Local_Stock::get_type_matrix($type_slug) : null;
        if (!$matrix) {
            wp_safe_redirect(self::tab_url('stock_matrix', array('mg_stock_notice' => 'invalid_type')));
            exit;
        }

        $qty_input = isset($_POST['qty']) ? (array) wp_unslash($_POST['qty']) : array();
        $safety_input = isset($_POST['safety']) ? (array) wp_unslash($_POST['safety']) : array();

        $changed = 0;
        foreach ($matrix['colors'] as $color) {
            foreach ($matrix['sizes'] as $size) {
                if (empty($matrix['allowed'][$color['slug']][$size['key']])) {
                    continue;
                }
                if (!isset($qty_input[$color['slug']][$size['key']])) {
                    continue;
                }
                $qty = max(0, (int) $qty_input[$color['slug']][$size['key']]);
                $safety = isset($safety_input[$color['slug']][$size['key']])
                    ? max(0, (int) $safety_input[$color['slug']][$size['key']])
                    : null;

                if (MG_Local_Stock::set_cell($type_slug, $color['slug'], $size['key'], $qty, $safety, 'manual')) {
                    $changed++;
                }
            }
        }

        wp_safe_redirect(self::tab_url('stock_matrix', array(
            'mg_stock_type'   => $type_slug,
            'mg_stock_notice' => $changed > 0 ? 'saved' : 'nochange',
            'mg_stock_count'  => $changed,
        )));
        exit;
    }

    public static function handle_save_settings() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Nincs jogosultságod a készlet kezeléséhez.', 'mockup-generator'));
        }
        check_admin_referer('mg_local_stock_save_settings');

        MG_Local_Stock::save_settings(array(
            'enabled' => isset($_POST['enabled']) ? 'yes' : 'no',
            'preview' => isset($_POST['preview']) ? 'yes' : 'no',
        ));

        $type_slug = isset($_POST['mg_stock_type']) ? sanitize_title(wp_unslash($_POST['mg_stock_type'])) : '';
        wp_safe_redirect(self::tab_url('stock_matrix', array_filter(array(
            'mg_stock_type'   => $type_slug,
            'mg_stock_notice' => 'settings',
        ))));
        exit;
    }

    /* -------------------------------------------------------------- goods in */

    private static function render_goods_in() {
        echo '<div class="mg-local-stock">';
        echo '<h1>' . esc_html__('Bevételezés', 'mockup-generator') . '</h1>';
        echo '<p class="description">' . esc_html__('Illeszd be a megérkezett nagyker szállítmány sorait ugyanabban a formában, ahogy a nagyker CSV kiment: soronként cikkszám és darabszám (pl. gi2000as-2xl,12). A rendszer hozzáadja a mennyiségeket a helyi készlethez.', 'mockup-generator') . '</p>';

        $raw = isset($_POST['csv']) ? (string) wp_unslash($_POST['csv']) : '';
        $preview = null;
        if ($raw !== '' && isset($_POST['mg_goods_in_preview_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_goods_in_preview_nonce'])), 'mg_local_stock_goods_in_preview')) {
            $preview = MG_Local_Stock::parse_goods_in($raw, false);
        }

        echo '<form method="post" class="mg-ls-goods-in">';
        wp_nonce_field('mg_local_stock_goods_in_preview', 'mg_goods_in_preview_nonce');
        echo '<textarea name="csv" rows="10" class="large-text code" placeholder="gi2000as-2xl,12">' . esc_textarea($raw) . '</textarea>';
        echo '<p class="submit"><button type="submit" class="button">' . esc_html__('Ellenőrzés', 'mockup-generator') . '</button></p>';
        echo '</form>';

        if ($preview === null) {
            echo '</div>';
            return;
        }

        $types = MG_Local_Stock::get_types();

        if (!empty($preview['rows'])) {
            echo '<h2>' . esc_html__('Bevételezendő sorok', 'mockup-generator') . '</h2>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Cikkszám', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Terméktípus', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Szín', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Méret', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Darab', 'mockup-generator') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($preview['rows'] as $row) {
                echo '<tr>';
                echo '<td><code>' . esc_html($row['sku']) . '</code></td>';
                echo '<td>' . esc_html(isset($types[$row['type']]) ? $types[$row['type']] : $row['type']) . '</td>';
                echo '<td>' . esc_html($row['color']) . '</td>';
                echo '<td>' . esc_html(strtoupper($row['size'])) . '</td>';
                echo '<td class="mg-num">' . esc_html($row['qty']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mg_local_stock_goods_in');
            echo '<input type="hidden" name="action" value="mg_local_stock_goods_in" />';
            // Textarea, nem hidden input: a sortöréseket így biztosan
            // változatlanul kapja vissza a megerősítő POST.
            echo '<textarea name="csv" class="mg-ls-hidden-field">' . esc_textarea($raw) . '</textarea>';
            echo '<p class="submit"><button type="submit" class="button button-primary">' . sprintf(
                /* translators: %d: total pieces */
                esc_html__('Bevételezés (%d db)', 'mockup-generator'),
                (int) $preview['total']
            ) . '</button></p>';
            echo '</form>';
        } else {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Egyetlen felismerhető sor sem volt a beillesztett szövegben.', 'mockup-generator') . '</p></div>';
        }

        if (!empty($preview['unknown'])) {
            echo '<h2>' . esc_html__('Nem felismert sorok', 'mockup-generator') . '</h2>';
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Ezekhez a cikkszámokhoz nem találtam variánst a katalógusban. Ellenőrizd az UTT cikkszámokat a terméktípus beállításainál.', 'mockup-generator') . '</p></div>';
            echo '<ul class="mg-ls-unknown">';
            foreach ($preview['unknown'] as $line) {
                echo '<li><code>' . esc_html($line) . '</code></li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    public static function handle_goods_in() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Nincs jogosultságod a készlet kezeléséhez.', 'mockup-generator'));
        }
        check_admin_referer('mg_local_stock_goods_in');

        $raw = isset($_POST['csv']) ? (string) wp_unslash($_POST['csv']) : '';
        $result = MG_Local_Stock::parse_goods_in($raw, true);

        wp_safe_redirect(self::tab_url('stock_goods_in', array(
            'mg_stock_notice' => 'goods_in',
            'mg_stock_count'  => (int) $result['total'],
        )));
        exit;
    }

    /* ------------------------------------------------------------- low stock */

    private static function render_low_stock() {
        $rows = MG_Local_Stock::get_all_rows(array('low_only' => true));
        $types = MG_Local_Stock::get_types();

        echo '<div class="mg-local-stock">';
        echo '<h1>' . esc_html__('Hiánylista', 'mockup-generator') . '</h1>';
        echo '<p class="description">' . esc_html__('Azok a variánsok, amelyeknek a készlete elérte vagy elhagyta a biztonsági szintet. Ezekből a nagyker rendelés a teljes mennyiséget fogja megrendelni.', 'mockup-generator') . '</p>';

        if (empty($rows)) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Nincs hiányzó tétel.', 'mockup-generator') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Terméktípus', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Szín', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Méret', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Nagyker cikkszám', 'mockup-generator') . '</th>';
        echo '<th class="mg-num">' . esc_html__('Készlet', 'mockup-generator') . '</th>';
        echo '<th class="mg-num">' . esc_html__('Biztonsági', 'mockup-generator') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $sku = $row['utt_sku'] !== '' ? $row['utt_sku'] . '-' . $row['size_key'] : '';
            echo '<tr>';
            echo '<td>' . esc_html(isset($types[$row['type_slug']]) ? $types[$row['type_slug']] : $row['type_slug']) . '</td>';
            echo '<td>' . esc_html($row['color_slug']) . '</td>';
            echo '<td>' . esc_html(strtoupper($row['size_key'])) . '</td>';
            echo '<td>' . ($sku !== '' ? '<code>' . esc_html($sku) . '</code>' : '<span class="mg-ls-sku--missing">' . esc_html__('nincs', 'mockup-generator') . '</span>') . '</td>';
            echo '<td class="mg-num">' . esc_html($row['qty']) . '</td>';
            echo '<td class="mg-num">' . esc_html($row['safety']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------- log */

    private static function render_log() {
        $types = MG_Local_Stock::get_types();
        $reasons = MG_Local_Stock::reason_labels();

        $filter_type = isset($_GET['mg_stock_type']) ? sanitize_title(wp_unslash($_GET['mg_stock_type'])) : '';
        $filter_reason = isset($_GET['mg_stock_reason']) ? sanitize_key(wp_unslash($_GET['mg_stock_reason'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 50;

        $args = array(
            'type_slug' => $filter_type,
            'reason'    => $filter_reason,
            'limit'     => $per_page,
            'offset'    => ($paged - 1) * $per_page,
        );
        $entries = MG_Local_Stock::get_log($args);
        $total = MG_Local_Stock::count_log($args);

        echo '<div class="mg-local-stock">';
        echo '<h1>' . esc_html__('Mozgásnapló', 'mockup-generator') . '</h1>';

        echo '<form method="get" class="mg-ls-toolbar">';
        echo '<input type="hidden" name="page" value="mockup-generator" />';
        echo '<input type="hidden" name="mg_tab" value="stock_log" />';
        echo '<select name="mg_stock_type">';
        echo '<option value="">' . esc_html__('Minden terméktípus', 'mockup-generator') . '</option>';
        foreach ($types as $slug => $label) {
            printf('<option value="%1$s"%3$s>%2$s</option>', esc_attr($slug), esc_html($label), selected($slug, $filter_type, false));
        }
        echo '</select> ';
        echo '<select name="mg_stock_reason">';
        echo '<option value="">' . esc_html__('Minden mozgás', 'mockup-generator') . '</option>';
        foreach ($reasons as $key => $label) {
            printf('<option value="%1$s"%3$s>%2$s</option>', esc_attr($key), esc_html($label), selected($key, $filter_reason, false));
        }
        echo '</select> ';
        echo '<button type="submit" class="button">' . esc_html__('Szűrés', 'mockup-generator') . '</button>';
        echo '</form>';

        if (empty($entries)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Nincs megjeleníthető mozgás.', 'mockup-generator') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Időpont', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Variáns', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Mozgás', 'mockup-generator') . '</th>';
        echo '<th class="mg-num">' . esc_html__('Változás', 'mockup-generator') . '</th>';
        echo '<th class="mg-num">' . esc_html__('Utána', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Rendelés', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Ki', 'mockup-generator') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $type_label = isset($types[$entry['type_slug']]) ? $types[$entry['type_slug']] : $entry['type_slug'];
            $user = $entry['user_id'] ? get_userdata($entry['user_id']) : null;
            $delta = (int) $entry['delta'];

            echo '<tr>';
            echo '<td>' . esc_html(mysql2date('Y.m.d H:i', $entry['created_at'])) . '</td>';
            echo '<td>' . esc_html(sprintf('%s / %s / %s', $type_label, $entry['color_slug'], strtoupper($entry['size_key']))) . '</td>';
            echo '<td>' . esc_html(isset($reasons[$entry['reason']]) ? $reasons[$entry['reason']] : $entry['reason']);
            if ($entry['note'] !== '') {
                echo ' <span class="description">– ' . esc_html($entry['note']) . '</span>';
            }
            echo '</td>';
            printf(
                '<td class="mg-num %1$s">%2$s</td>',
                $delta < 0 ? 'mg-ls-delta--out' : 'mg-ls-delta--in',
                esc_html(($delta > 0 ? '+' : '') . $delta)
            );
            echo '<td class="mg-num">' . esc_html($entry['qty_after']) . '</td>';
            if ((int) $entry['order_id'] > 0) {
                $edit_url = function_exists('wc_get_order') && wc_get_order($entry['order_id'])
                    ? wc_get_order($entry['order_id'])->get_edit_order_url()
                    : '';
                echo '<td>' . ($edit_url ? '<a href="' . esc_url($edit_url) . '">#' . esc_html($entry['order_id']) . '</a>' : '#' . esc_html($entry['order_id'])) . '</td>';
            } else {
                echo '<td>—</td>';
            }
            echo '<td>' . esc_html($user ? $user->display_name : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $pages = (int) ceil($total / $per_page);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(array(
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $pages,
                'prev_text' => '‹',
                'next_text' => '›',
            ));
            echo '</div></div>';
        }

        echo '</div>';
    }
}
