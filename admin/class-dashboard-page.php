<?php
if (!defined('ABSPATH')) exit;

class MG_Dashboard_Page {
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

        $join = " LEFT JOIN {$pm} pmg ON (pmg.post_id = p.ID AND pmg.meta_key = '_mg_generated') ";
        $prefixes = self::get_sku_prefixes();
        if (!empty($prefixes)) {
            $join .= " LEFT JOIN {$pm} pmsku ON (pmsku.post_id = p.ID AND pmsku.meta_key = '_sku') ";
            $sku_or = array();
            foreach ($prefixes as $pref) {
                $sku_or[] = 'pmsku.meta_value LIKE %s';
                $params[] = $wpdb->esc_like($pref) . '%';
            }
            $where_parts[] = "(pmg.meta_value = '1' OR (" . implode(' OR ', $sku_or) . '))';
        } else {
            $where_parts[] = "pmg.meta_value = '1'";
        }

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

        $data = self::find_generated_products(compact('paged','per_page','status','s','date_from','date_to'));
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
        echo '<select name="per_page">';
        foreach(array(10,20,50,100) as $pp){ echo '<option value="'.$pp.'"'.selected($per_page,$pp,false).'>'.$pp.'/oldal</option>'; }
        echo '</select> ';
        submit_button('Szűrés', 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>Kép</th><th>ID</th><th>Cím</th><th>Státusz</th><th>Dátum</th><th>Műveletek</th>';
        echo '</tr></thead><tbody>';

        if (!empty($data['items'])){
            foreach($data['items'] as $r){
                $id = intval($r['ID']); $edit = get_edit_post_link($id, ''); $view = get_permalink($id);
                echo '<tr>';
                echo '<td>'.$id.'</td>';
                echo '<td><a href="'.esc_url($edit).'">'.esc_html($r['post_title']).'</a></td>';
                echo '<td>'.esc_html($r['post_status']).'</td>';
                echo '<td>'.esc_html(mysql2date('Y-m-d H:i', $r['post_date'])).'</td>';
                echo '<td><a class="button button-small" href="'.esc_url($edit).'">Szerkesztés</a> ';
                echo '<a class="button button-small" target="_blank" href="'.esc_url($view).'">Megnyitás</a> ';
                echo '<button type="button" class="button button-small mg-replace-design-trigger" data-mg-open-modal="replace-design" data-product-id="'.esc_attr($id).'" data-product-title="'.esc_attr($r['post_title']).'">Minta cseréje</button></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">Nincs találat a szűrőkre.</td></tr>';
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

        echo '</div>';
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
}
