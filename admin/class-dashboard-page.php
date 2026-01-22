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
        $products = mg_get_catalog_products();
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
        $where = " WHERE p.post_type='product' ";
        if ($status){ $where .= $wpdb->prepare(" AND p.post_status=%s ", $status); }
        else { $where .= " AND p.post_status IN ('publish','draft','pending','private') "; }
        if ($search){
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND (p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s)", $like,$like,$like);
        }
        if ($date_from){ $where .= $wpdb->prepare(" AND p.post_date >= %s ", $date_from.' 00:00:00'); }
        if ($date_to){ $where .= $wpdb->prepare(" AND p.post_date <= %s ", $date_to.' 23:59:59'); }

        $join = " LEFT JOIN {$pm} pmg ON (pmg.post_id=p.ID AND pmg.meta_key=%s) ";
        $params = array('_mg_generated');

        $prefixes = self::get_sku_prefixes();
        if (!empty($prefixes)){
            $join .= " LEFT JOIN {$pm} pmsku ON (pmsku.post_id=p.ID AND pmsku.meta_key='_sku') ";
            $sku_or = array(); foreach ($prefixes as $pref){ $sku_or[] = " (pmsku.meta_value LIKE %s) "; $params[] = $pref.'%'; }
            $where .= " AND (pmg.meta_value='1' OR (".implode(' OR ', $sku_or).")) ";
        } else {
            $where .= " AND (pmg.meta_value='1') ";
        }

        $offset = ($paged-1)*$per_page;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$posts} WHERE post_type='product' AND post_status IN ('publish','draft','pending','private')");

        $sql = $wpdb->prepare("
    SELECT ID, post_title, post_status, post_date
    FROM {$posts}
    WHERE post_type='product'
    AND post_status IN ('publish','draft','pending','private')
    ORDER BY post_date DESC
    LIMIT %d OFFSET %d
", $per_page, $offset);

$rows = $wpdb->get_results($sql, ARRAY_A);

        return array('items'=>$rows,'total'=>$total,'per_page'=>$per_page,'paged'=>$paged,'pages'=>max(1,ceil($total/$per_page)));
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
        echo '<h1>Mockup Generator – Dashboard</h1>';
        echo '<p>Áttekintés a pluginnal generált termékekről. Keresés, szűrés, lapozás.</p>';

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
                echo '<a class="button button-small" target="_blank" href="'.esc_url($view).'">Megnyitás</a></td>';
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

        echo '</div>';
    }
}
