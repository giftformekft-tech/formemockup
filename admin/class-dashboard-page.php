<?php
if (!defined('ABSPATH')) exit;

class MG_Dashboard_Page {
    const DESIGN_THUMB_SIZE = 128;
    const DESIGN_THUMB_DIR = 'mg_design_thumbs';
    // New previews made per page load; the rest follow on the next load.
    const DESIGN_THUMB_BUDGET = 10;

    protected static $design_thumbs_made = 0;

    public static function add_menu_page(){
        add_submenu_page(
            'mockup-generator',
            'MG Dashboard',
            'Dashboard',
            'edit_products',
            'mockup-generator-dashboard',
            [self::class, 'render_page']
        );
    }

    protected static function get_sku_prefixes(){
        $prefixes = array();
        $products = get_option('mg_products', array());
        if (is_array($products)){
            foreach ($products as $p){
                if (is_array($p) && !empty($p['sku_prefix'])){
                    $prefixes[] = sanitize_text_field($p['sku_prefix']);
                }
            }
        }
        return array_unique(array_filter($prefixes));
    }

    protected static function find_generated_products($args=array()){
        global $wpdb;
        $paged     = max(1, intval($args['paged'] ?? 1));
        $per_page  = max(1, min(100, intval($args['per_page'] ?? 20)));
        $search    = isset($args['s']) ? sanitize_text_field($args['s']) : '';
        $status    = isset($args['status']) ? sanitize_text_field($args['status']) : '';
        $date_from = isset($args['date_from']) ? sanitize_text_field($args['date_from']) : '';
        $date_to   = isset($args['date_to']) ? sanitize_text_field($args['date_to']) : '';
        $cat       = isset($args['cat']) ? absint($args['cat']) : 0;

        $posts = $wpdb->posts; $pm = $wpdb->postmeta;

        // Minden feltétel egyetlen placeholder-listába kerül, és a végén egy
        // lépésben prepare-eljük - korábban a szűrők összeálltak, de a futó
        // lekérdezés nem használta őket, ezért a keresés/szűrés nem működött.
        $where_parts = array("p.post_type = 'product'");
        $params = array();

        if ($status) {
            $where_parts[] = 'p.post_status = %s';
            $params[] = $status;
        } else {
            $where_parts[] = "p.post_status IN ('publish','draft','pending','private')";
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_parts[] = '(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($date_from) {
            $where_parts[] = 'p.post_date >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $where_parts[] = 'p.post_date <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        // Main or subcategory: the term itself and everything below it.
        if ($cat > 0) {
            $term_ids = array($cat);
            $children = function_exists('get_term_children') ? get_term_children($cat, 'product_cat') : array();
            if (is_array($children)) {
                $term_ids = array_merge($term_ids, array_map('intval', $children));
            }
            $term_ids = array_values(array_unique($term_ids));
            $where_parts[] = "p.ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt"
                . " ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ("
                . implode(',', array_fill(0, count($term_ids), '%d')) . '))';
            $params = array_merge($params, $term_ids);
        }

        // Generátorral készült termék ismertetőjegyei: van mentett design
        // útvonala (_mg_last_design_path), VAGY a szülő SKU a kreátor FORME
        // prefixével kezdődik, VAGY a katalógusban beállított típus-prefixek
        // egyikével. (A korábbi _mg_generated meta sosem íródott, halott jelző.)
        $join  = " LEFT JOIN {$pm} pmd ON (pmd.post_id = p.ID AND pmd.meta_key = '_mg_last_design_path') ";
        $join .= " LEFT JOIN {$pm} pmsku ON (pmsku.post_id = p.ID AND pmsku.meta_key = '_sku') ";

        $gen_or = array('pmd.meta_id IS NOT NULL');

        $parent_prefix = class_exists('MG_Product_Creator') ? MG_Product_Creator::SKU_PREFIX : 'FORME';
        $gen_or[] = 'pmsku.meta_value LIKE %s';
        $params[] = $wpdb->esc_like($parent_prefix) . '%';

        foreach (self::get_sku_prefixes() as $pref) {
            $gen_or[] = 'pmsku.meta_value LIKE %s';
            $params[] = $wpdb->esc_like($pref) . '%';
        }

        $where_parts[] = '(' . implode(' OR ', $gen_or) . ')';

        $base = "FROM {$posts} p {$join} WHERE " . implode(' AND ', $where_parts);

        $count_sql = "SELECT COUNT(DISTINCT p.ID) {$base}";
        $total = (int) $wpdb->get_var(empty($params) ? $count_sql : $wpdb->prepare($count_sql, $params));

        $offset = ($paged - 1) * $per_page;
        $list_sql = "SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_date {$base} ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, array($per_page, $offset))), ARRAY_A);

        return array('items'=>$rows,'total'=>$total,'per_page'=>$per_page,'paged'=>$paged,'pages'=>max(1,ceil($total/$per_page)));
    }

    /**
     * Stat cards + quick action links shown at the top of the dashboard.
     * Read-only summary, no behaviour change to the list below.
     *
     * @param array $data Result of find_generated_products() for the total count.
     */
    protected static function render_overview_cards($data){
        global $wpdb;

        $products = get_option('mg_products', array());
        $type_count = is_array($products) ? count($products) : 0;

        $statuses = "('publish','draft','pending','private')";
        $recent_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN {$statuses} AND post_date >= %s",
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        $previous_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN {$statuses} AND post_date >= %s AND post_date < %s",
            gmdate('Y-m-d H:i:s', strtotime('-60 days')),
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        ));

        $daily_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(post_date) AS d, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN {$statuses} AND post_date >= %s GROUP BY DATE(post_date)",
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        ), ARRAY_A);
        $daily = array();
        foreach ((array) $daily_rows as $row) {
            $daily[$row['d']] = (int) $row['c'];
        }
        $series = array();
        for ($i = 29; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $series[] = isset($daily[$day]) ? $daily[$day] : 0;
        }

        $delta_html = '';
        if ($previous_count > 0) {
            $pct = (int) round((($recent_count - $previous_count) / $previous_count) * 100);
            $dir = $pct >= 0 ? 'up' : 'down';
            $delta_html = '<span class="mg-delta mg-delta--' . $dir . '">' . ($pct >= 0 ? '+' : '') . $pct . '%</span>';
        } elseif ($recent_count > 0) {
            $delta_html = '<span class="mg-delta mg-delta--up">+' . esc_html(number_format_i18n($recent_count)) . '</span>';
        }

        $queue_pending = class_exists('MG_Bulk_Queue') ? MG_Bulk_Queue::get_pending_count() : 0;

        echo '<div class="mg-dash-stats">';
        echo '<div class="mg-dash-stat"><div class="mg-dash-stat__top"><p class="mg-dash-stat__label">Aktív terméktípus</p></div><p class="mg-dash-stat__value">' . esc_html(number_format_i18n($type_count)) . '</p><p class="mg-dash-stat__hint">a Beállításokban konfigurálva</p></div>';
        echo '<div class="mg-dash-stat"><div class="mg-dash-stat__top"><p class="mg-dash-stat__label">Termék összesen</p></div><p class="mg-dash-stat__value">' . esc_html(number_format_i18n((int) $data['total'])) . '</p><p class="mg-dash-stat__hint">publish, draft, pending, private</p></div>';
        echo '<div class="mg-dash-stat"><div class="mg-dash-stat__top"><p class="mg-dash-stat__label">Új termék (30 nap)</p>' . $delta_html . '</div><p class="mg-dash-stat__value">' . esc_html(number_format_i18n($recent_count)) . '</p>' . self::render_sparkline($series) . '</div>';
        echo '<div class="mg-dash-stat"><div class="mg-dash-stat__top"><p class="mg-dash-stat__label">Bulk sor</p></div><p class="mg-dash-stat__value">' . ($queue_pending > 0 ? esc_html(number_format_i18n($queue_pending)) : 'Üres') . '</p><p class="mg-dash-stat__hint">' . ($queue_pending > 0 ? 'feldolgozásra váró tétel' : 'nincs feldolgozásra váró tétel') . '</p></div>';
        echo '</div>';

        $actions = array(
            array('bulk', 'dashicons-upload', 'Bulk minta feltöltés', 'Több design egyszerre'),
            array('temu_export', 'dashicons-migrate', 'Temu Export', 'XLSX a sablonod alapján'),
            array('dedup', 'dashicons-search', 'Duplikátum-keresés', 'Ismétlődő termékek tisztítása'),
            array('settings', 'dashicons-admin-generic', 'Beállítások', 'Terméktípusok és opciók'),
        );
        echo '<div class="mg-quick-actions">';
        foreach ($actions as $a) {
            echo '<a class="mg-quick-action" href="' . esc_url(MG_Admin_Page::build_panel_url($a[0])) . '">';
            echo '<span class="dashicons ' . esc_attr($a[1]) . '" aria-hidden="true"></span>';
            echo '<span><strong>' . esc_html($a[2]) . '</strong><span class="mg-quick-action__desc">' . esc_html($a[3]) . '</span></span>';
            echo '</a>';
        }
        echo '</div>';
    }

    /**
     * Renders a tiny inline SVG sparkline from a numeric series.
     *
     * @param int[] $series
     * @return string
     */
    protected static function render_sparkline($series){
        $count = count($series);
        if ($count < 2 || max($series) <= 0) {
            return '<p class="mg-dash-stat__hint">az elmúlt 30 napban létrehozva</p>';
        }

        $max = max($series);
        $points = array();
        $step = 100 / ($count - 1);
        foreach ($series as $i => $value) {
            $x = round($i * $step, 1);
            // 2px top/bottom padding inside the 30-unit-high viewBox.
            $y = round(28 - (($value / $max) * 26), 1);
            $points[] = $x . ',' . $y;
        }
        $line = implode(' ', $points);
        $area = '0,30 ' . $line . ' 100,30';

        return '<svg class="mg-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">'
            . '<polygon points="' . esc_attr($area) . '"></polygon>'
            . '<polyline points="' . esc_attr($line) . '"></polyline>'
            . '</svg>';
    }

    public static function render_page(){
        if (!current_user_can('edit_products')) { wp_die(__('Nincs jogosultság.')); }

        $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $s = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $cat_main = isset($_GET['cat_main']) ? absint($_GET['cat_main']) : 0;
        $cat_sub = isset($_GET['cat_sub']) ? absint($_GET['cat_sub']) : 0;
        $cat = $cat_sub > 0 ? $cat_sub : $cat_main;

        $data = self::find_generated_products(compact('paged','per_page','status','s','date_from','date_to','cat'));
        $base_url = admin_url('admin.php?page=mockup-generator-dashboard');

        echo '<div class="wrap">';
        echo '<h1>Dashboard</h1>';
        echo '<p>Áttekintés a pluginnal generált termékekről. Keresés, szűrés, lapozás.</p>';

        self::render_overview_cards($data);

        echo '<form method="get" class="mg-filters">';
        echo '<input type="hidden" name="page" value="mockup-generator-dashboard">';
        echo '<input type="search" name="s" value="'.esc_attr($s).'" placeholder="Keresés cím/leírás alapján"> ';
        echo '<select name="status">';
        $statuses = array('' => '— bármely státusz —','publish'=>'publish','draft'=>'draft','pending'=>'pending','private'=>'private');
        foreach($statuses as $k=>$label){ echo '<option value="'.esc_attr($k).'"'.selected($status,$k,false).'>'.esc_html($label).'</option>'; }
        echo '</select> ';
        echo '<input type="date" name="date_from" value="'.esc_attr($date_from).'"> – ';
        echo '<input type="date" name="date_to" value="'.esc_attr($date_to).'"> ';
        self::render_category_filters($cat_main, $cat_sub);
        echo '<select name="per_page">';
        foreach(array(10,20,50,100) as $pp){ echo '<option value="'.$pp.'"'.selected($per_page,$pp,false).'>'.$pp.'/oldal</option>'; }
        echo '</select> ';
        submit_button('Szűrés', 'secondary', '', false);
        echo '</form>';

        echo '<div class="mg-dash-bulkbar">';
        echo '<button type="button" class="button" id="mg-dash-select-all">Mind kijelölése</button> ';
        echo '<button type="button" class="button" id="mg-dash-select-none">Kijelölés törlése</button> ';
        echo '<span class="mg-dash-bulkbar__count" id="mg-dash-selected-count">0 kijelölve</span> ';
        if (class_exists('MG_AI_Redesign')) {
            echo '<button type="button" class="button button-primary" id="mg-dash-bulk-redesign" data-mg-open-modal="ai-redesign-bulk" disabled>AI újratervezés a kijelöltekre</button>';
        }
        echo '</div>';

        echo '<table class="widefat fixed striped mg-dash-table"><thead><tr>';
        echo '<td class="manage-column check-column mg-dash-check-col"><input type="checkbox" id="mg-dash-check-page" aria-label="Az oldal összes mintájának kijelölése" /></td>';
        echo '<th class="mg-dash-design-col">Minta</th><th>ID</th><th>Cím</th><th>Státusz</th><th>Dátum</th><th>Műveletek</th>';
        echo '</tr></thead><tbody>';

        if (!empty($data['items'])){
            foreach($data['items'] as $r){
                $id = intval($r['ID']); $edit = get_edit_post_link($id, ''); $view = get_permalink($id);
                echo '<tr>';
                echo '<th scope="row" class="check-column"><input type="checkbox" class="mg-dash-check" value="'.esc_attr($id).'" data-title="'.esc_attr($r['post_title']).'" aria-label="'.esc_attr($r['post_title']).' kijelölése" /></th>';
                echo '<td class="mg-dash-design">'.self::design_thumbnail_html($id).'</td>';
                echo '<td>'.$id.'</td>';
                echo '<td><a href="'.esc_url($edit).'">'.esc_html($r['post_title']).'</a></td>';
                echo '<td>'.esc_html($r['post_status']).'</td>';
                echo '<td>'.esc_html(mysql2date('Y-m-d H:i', $r['post_date'])).'</td>';
                echo '<td><a class="button button-small" href="'.esc_url($edit).'">Szerkesztés</a> ';
                echo '<a class="button button-small" target="_blank" href="'.esc_url($view).'">Megnyitás</a> ';
                echo '<button type="button" class="button button-small mg-replace-design-trigger" data-mg-open-modal="replace-design" data-product-id="'.esc_attr($id).'" data-product-title="'.esc_attr($r['post_title']).'">Minta cseréje</button> ';
                echo '<button type="button" class="button button-small mg-ai-redesign-trigger" data-mg-open-modal="ai-redesign" data-product-id="'.esc_attr($id).'" data-product-title="'.esc_attr($r['post_title']).'">AI újratervezés</button></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7">Nincs találat a szűrőkre.</td></tr>';
        }
        echo '</tbody></table>';

        $pages = intval($data['pages']); $pg = intval($data['paged']);
        if ($pages > 1){
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($i=1; $i<=$pages; $i++){
                $url = esc_url(add_query_arg(array_merge($_GET, array('paged'=>$i, 'page'=>'mockup-generator-dashboard')), $base_url));
                $cls = ($i==$pg) ? ' class="page-numbers current"' : ' class="page-numbers"';
                echo '<a'.$cls.' href="'.$url.'">'.$i.'</a> ';
            }
            echo '</div></div>';
        }

        self::render_replace_design_modal();
        self::render_ai_redesign_modal();
        self::render_ai_redesign_bulk_modal();

        echo '</div>';
    }

    /**
     * Small preview of the product's print design (not its mockup). Print
     * PNGs can be tens of MB, so the list never loads the file itself: it
     * uses the attachment's thumbnail size, or a 128 px PNG made once into
     * uploads/mg_design_thumbs/ (keyed by path, mtime and size, so a replaced
     * design gets a new preview).
     *
     * @return array|null {thumb: preview URL or '' if not made yet, full: design URL}
     */
    public static function design_thumbnail($product_id, $size = self::DESIGN_THUMB_SIZE){
        $size = max(32, min(1024, (int) $size));
        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($basedir === '') {
            return null;
        }
        $path = get_post_meta($product_id, '_mg_last_design_path', true);
        $path = is_string($path) && $path !== '' ? wp_normalize_path($path) : '';
        $attachment_id = absint(get_post_meta($product_id, '_mg_last_design_attachment', true));
        $attachment_path = $attachment_id > 0 ? wp_normalize_path((string) get_attached_file($attachment_id)) : '';
        // The attachment can be stale after a replacement; the path wins, like in the export.
        if ($attachment_path !== '' && ($path === '' || $attachment_path === $path) && is_file($attachment_path)) {
            $src = wp_get_attachment_image_src($attachment_id, $size <= 150 ? 'thumbnail' : 'medium_large');
            if ($src && !empty($src[3])) {
                return array('thumb' => $src[0], 'full' => (string) wp_get_attachment_url($attachment_id));
            }
            $path = $attachment_path;
        }
        if ($path === '' || !is_file($path) || strpos($path, trailingslashit($basedir)) !== 0) {
            return null;
        }
        $full = trailingslashit($uploads['baseurl']) . ltrim(substr($path, strlen($basedir)), '/');
        $rel = self::DESIGN_THUMB_DIR . '/' . md5($path . '|' . filemtime($path) . '|' . filesize($path)) . ($size === self::DESIGN_THUMB_SIZE ? '' : '-' . $size) . '.png';
        $thumb = trailingslashit($basedir) . $rel;
        if (!is_file($thumb)) {
            if (self::$design_thumbs_made >= self::DESIGN_THUMB_BUDGET) {
                return array('thumb' => '', 'full' => $full);
            }
            self::$design_thumbs_made++;
            $editor = wp_get_image_editor($path);
            if (is_wp_error($editor) || !wp_mkdir_p(dirname($thumb))) {
                return array('thumb' => '', 'full' => $full);
            }
            $editor->resize($size, $size, false);
            if (is_wp_error($editor->save($thumb, 'image/png'))) {
                return array('thumb' => '', 'full' => $full);
            }
        }
        return array('thumb' => trailingslashit($uploads['baseurl']) . $rel, 'full' => $full);
    }

    protected static function design_thumbnail_html($product_id){
        $design = self::design_thumbnail($product_id);
        if ($design && $design['thumb'] !== '') {
            return '<a href="'.esc_url($design['full']).'" target="_blank" rel="noopener" title="Minta megnyitása teljes méretben">'
                . '<img src="'.esc_url($design['thumb']).'" alt="Minta" width="56" height="56" loading="lazy" /></a>';
        }
        // No design file, or its preview is made on the next load: show the mockup.
        $note = $design ? 'A minta előnézete a következő betöltéskor készül el.' : 'Nincs mentett mintafájl – a termék képe látható.';
        $mockup = get_the_post_thumbnail($product_id, array(48, 48), array('title' => $note));
        if ($design) {
            return '<a href="'.esc_url($design['full']).'" target="_blank" rel="noopener" title="'.esc_attr($note).'">'.$mockup.'</a>';
        }
        return $mockup !== '' ? $mockup : '<span class="description">–</span>';
    }

    /**
     * Modal for replacing the base design of a generated product. Opened via
     * the per-row "Minta cseréje" buttons; submission runs over AJAX
     * (mg_replace_design) and queues a background regeneration job.
     */
    protected static function render_replace_design_modal(){
        echo '<div class="mg-modal" id="mg-replace-design-modal" aria-hidden="true">';
        echo '<div class="mg-modal__backdrop" data-mg-close-modal></div>';
        echo '<div class="mg-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mg-replace-design-title">';
        echo '<button type="button" class="mg-modal__close" aria-label="Bezárás" data-mg-close-modal>&times;</button>';
        echo '<h2 id="mg-replace-design-title">Minta cseréje</h2>';
        echo '<p class="description">Termék: <strong id="mg-replace-product-name"></strong></p>';
        echo '<p class="description">Töltsd fel a javított mintát (PNG, JPG vagy WebP). A rendszer a termék összes terméktípusára újragenerálja a mockupokat a háttérben, majd kitakarítja a régi képeket. A bolt képei csere közben is elérhetők maradnak.</p>';
        echo '<input type="hidden" id="mg-replace-product-id" value="" />';
        echo '<p><input type="file" id="mg-replace-design-file" accept=".png,.jpg,.jpeg,.webp" /></p>';
        echo '<p><button type="button" class="button button-primary" id="mg-replace-design-submit">Csere indítása</button></p>';
        echo '<p class="description" id="mg-replace-design-status" aria-live="polite"></p>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * "AI újratervezés" modal: the analyzer model (OpenAI or Claude) writes a
     * new-style prompt with the same text, GPT Image draws the PNG, and an
     * approved PNG regenerates the mockups (MG_AI_Redesign AJAX).
     */
    protected static function render_ai_redesign_modal(){
        if (!class_exists('MG_AI_Redesign')) {
            return;
        }
        echo '<div class="mg-modal" id="mg-ai-redesign-modal" aria-hidden="true">';
        echo '<div class="mg-modal__backdrop" data-mg-close-modal></div>';
        echo '<div class="mg-modal__dialog mg-ai-redesign" role="dialog" aria-modal="true" aria-labelledby="mg-ai-redesign-title">';
        echo '<button type="button" class="mg-modal__close" aria-label="Bezárás" data-mg-close-modal>&times;</button>';
        echo '<h2 id="mg-ai-redesign-title">AI újratervezés</h2>';
        echo '<p class="description">Termék: <strong id="mg-ai-redesign-product-name"></strong></p>';
        echo '<p class="description">Az AI új stílusban rajzolja újra a mintát ugyanazzal a szöveggel. A kész PNG-t itt nézed meg; csak a jóváhagyott minta kerül a termékre, ekkor az összes mockup újragenerálódik. Az eredeti minta a médiatárban marad.</p>';
        echo '<input type="hidden" id="mg-ai-redesign-product-id" value="" />';
        echo '<div class="mg-ai-redesign__original"><span>Jelenlegi minta</span><img id="mg-ai-redesign-original" alt="" /><p class="description" id="mg-ai-redesign-original-error"></p></div>';
        echo '<table class="form-table mg-ai-redesign__form">';
        echo '<tr><th scope="row"><label for="mg-ai-redesign-intensity">Eltérés mértéke</label></th><td><select id="mg-ai-redesign-intensity">';
        foreach (MG_AI_Redesign::get_intensities() as $value => $label) {
            echo '<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
        }
        echo '</select><p class="description">Az elemző egy rövid tervezési briefet ír a mintáról (műfaj, téma, hangulat, melyik sor a legnagyobb, durva felépítés), és kigyűjti a jellegzetes elemeit (pl. babérkoszorú, szalag, írott betű). A képgeneráló csak ebből dolgozik, az eredeti képet nem kapja meg. Finom: hasonló felépítés, a jellegzetes elemek kicserélve. Új stílus: ugyanaz a műfaj és hangsúly, saját elrendezés, a jellegzetes elemek tiltva. Teljesen új: csak a téma és a hangulat.</p></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-redesign-mode">Prompt mód</label></th><td><select id="mg-ai-redesign-mode">';
        foreach (MG_AI_Redesign::get_prompt_modes() as $value => $label) {
            echo '<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
        }
        echo '</select><p class="description">Teszteléshez: a „brief” módban az elemző rövid mezőket tölt ki (műfaj, hangsúly, jellegzetes elemek és cseréik), és a promptot a rendszer rakja össze; a „szabad leírás” módban az elemző maga ír egy rövid, kreatív promptot. A kártyán látszik, melyik módban készült.</p></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-redesign-analyzer">Elemző modell</label></th><td><select id="mg-ai-redesign-analyzer"></select><p class="description">Kiolvassa a szöveget és megírja az új stílus promptját. Claude modellekhez az Anthropic API-kulcsot az AI Minta SEO oldalon kell menteni.</p></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-redesign-quality">Képminőség</label></th><td><select id="mg-ai-redesign-quality"><option value="low">Alacsony (olcsó, gyors)</option><option value="medium">Közepes</option><option value="high">Magas (drágább, lassabb)</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-redesign-text">Pontos szöveg</label></th><td><textarea id="mg-ai-redesign-text" rows="3" class="large-text" placeholder="Üresen az AI olvassa ki a képről"></textarea><p class="description">Ajánlott megadni: soronként egy sor a mintán. Így biztosan ugyanaz a szöveg kerül az új mintára.</p></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-redesign-hint">Stílusötlet</label></th><td><input type="text" id="mg-ai-redesign-hint" class="large-text" placeholder="pl. retro, 70-es évek, vastag betűk, narancs-barna színek" /><p class="description">Nem kötelező. Üresen az AI választ az eredetitől eltérő stílust.</p></td></tr>';
        echo '</table>';
        echo '<details class="mg-ai-redesign__prompt"><summary>Saját képprompt (haladó)</summary><p class="description">Ha kitöltöd, az elemzés kimarad, és pontosan ez a prompt megy a képgenerálónak. Egy korábbi jelölt promptját a „Prompt szerkesztése” gombbal töltheted be ide.</p><textarea id="mg-ai-redesign-custom-prompt" rows="8" class="large-text code"></textarea></details>';
        echo '<p><button type="button" class="button button-primary" id="mg-ai-redesign-submit">Új minta generálása</button></p>';
        echo '<p class="description" id="mg-ai-redesign-status" aria-live="polite"></p>';
        echo '<p class="mg-ai-redesign__bulk" hidden><button type="button" class="button button-link-delete" id="mg-ai-redesign-discard-all">Összes jelölt törlése</button></p>';
        echo '<div class="mg-ai-redesign__candidates" id="mg-ai-redesign-candidates"></div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Main category and subcategory selects. The subcategory list holds the
     * children of every main category; the page script shows only those of
     * the chosen one. Either filter includes everything below the term.
     */
    protected static function render_category_filters($cat_main, $cat_sub){
        if (!function_exists('get_terms')) {
            return;
        }
        $mains = get_terms(array('taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false, 'orderby' => 'name'));
        if (is_wp_error($mains) || empty($mains)) {
            return;
        }
        echo '<select name="cat_main" id="mg-dash-cat-main"><option value="0">— összes főkategória —</option>';
        foreach ($mains as $term) {
            echo '<option value="'.esc_attr($term->term_id).'"'.selected($cat_main, $term->term_id, false).'>'.esc_html($term->name).'</option>';
        }
        echo '</select> ';
        echo '<select name="cat_sub" id="mg-dash-cat-sub"><option value="0" data-main="0">— összes alkategória —</option>';
        foreach ($mains as $main) {
            $subs = get_terms(array('taxonomy' => 'product_cat', 'parent' => $main->term_id, 'hide_empty' => false, 'orderby' => 'name'));
            if (is_wp_error($subs)) {
                continue;
            }
            foreach ($subs as $term) {
                echo '<option value="'.esc_attr($term->term_id).'" data-main="'.esc_attr($main->term_id).'"'.selected($cat_sub, $term->term_id, false).'>'.esc_html($term->name).'</option>';
            }
        }
        echo '</select> ';
    }

    /**
     * Bulk "AI újratervezés" for the selected rows (assets/js/dashboard-bulk.js):
     * settings first, then one card per design with the original and the new
     * design side by side. Approved cards are upscaled, finished and queued
     * for mockup regeneration together at the end.
     */
    protected static function render_ai_redesign_bulk_modal(){
        if (!class_exists('MG_AI_Redesign')) {
            return;
        }
        $settings = MG_AI_Redesign::get_settings();
        echo '<div class="mg-modal" id="mg-ai-redesign-bulk-modal" aria-hidden="true">';
        echo '<div class="mg-modal__backdrop" data-mg-close-modal></div>';
        echo '<div class="mg-modal__dialog mg-ai-bulk" role="dialog" aria-modal="true" aria-labelledby="mg-ai-bulk-title">';
        echo '<button type="button" class="mg-modal__close" aria-label="Bezárás" data-mg-close-modal>&times;</button>';
        echo '<h2 id="mg-ai-bulk-title">AI újratervezés – tömeges</h2>';
        echo '<div class="mg-ai-bulk__setup" id="mg-ai-bulk-setup">';
        echo '<p class="description"><strong id="mg-ai-bulk-count">0</strong> kijelölt minta. Válaszd ki az újratervezés módját; minden minta ugyanezekkel a beállításokkal készül. A felskálázás és a képjavítás csak a végén, a jóváhagyott mintákon fut.</p>';
        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="mg-ai-bulk-mode">Prompt mód</label></th><td><select id="mg-ai-bulk-mode">';
        foreach (MG_AI_Redesign::get_prompt_modes() as $value => $label) {
            echo '<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-bulk-intensity">Eltérés mértéke</label></th><td><select id="mg-ai-bulk-intensity">';
        foreach (MG_AI_Redesign::get_intensities() as $value => $label) {
            echo '<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-bulk-analyzer">Elemző modell</label></th><td><select id="mg-ai-bulk-analyzer">';
        foreach (MG_AI_Redesign::get_analyzers($settings) as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($settings['analyzer'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-bulk-quality">Képminőség</label></th><td><select id="mg-ai-bulk-quality">';
        foreach (array('low' => 'Alacsony (olcsó, gyors)', 'medium' => 'Közepes', 'high' => 'Magas (drágább, lassabb)') as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($settings['quality'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="mg-ai-bulk-hint">Stílusötlet</label></th><td><input type="text" id="mg-ai-bulk-hint" class="large-text" placeholder="Nem kötelező, minden mintára érvényes" /></td></tr>';
        echo '</table>';
        echo '<p><button type="button" class="button button-primary" id="mg-ai-bulk-start">Generálás indítása</button></p>';
        echo '</div>';
        echo '<div class="mg-ai-bulk__run" id="mg-ai-bulk-run" hidden>';
        echo '<div class="mg-ai-bulk-progress" id="mg-ai-bulk-progress" hidden></div>';
        echo '<p class="mg-ai-bulk__summary" id="mg-ai-bulk-summary" aria-live="polite"></p>';
        echo '<div class="mg-ai-bulk__list" id="mg-ai-bulk-list"></div>';
        echo '<div class="mg-ai-bulk__footer">';
        echo '<button type="button" class="button" id="mg-ai-bulk-stop">Hátralévők leállítása</button> ';
        echo '<button type="button" class="button" id="mg-ai-bulk-approve-all">Mindet jóváhagyom</button> ';
        echo '<button type="button" class="button button-primary" id="mg-ai-bulk-finalize" disabled>Jóváhagyottak véglegesítése</button>';
        echo ' <button type="button" class="button" id="mg-ai-bulk-new">Új futás a mostani kijelöléssel</button>';
        echo '<p class="description">Véglegesítéskor a jóváhagyott minták egyenként 3×-os AI felskálázást (a böngészőben, Real-CUGAN, mint a Design-flow-ban) és 255 színes palettát kapnak, majd sorba áll a mockupjaik újragenerálása. A böngészőlapot csak addig hagyd nyitva (és ne frissítsd), amíg a folyamatcsík „Kész”-t mutat; ez az ablak közben bezárható, a csík a lap alján követi a folyamatot. A mockupok ezután a szerveren, a háttérben készülnek, azt már nem kell megvárni.</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}
