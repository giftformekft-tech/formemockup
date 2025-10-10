<?php
if (!defined('ABSPATH')) exit;

/**
 * Termék beállítások – MÉRET / SZÍN FELÁRAK (ÚJ), + meglévő mezők
 */

// ===== Helpers =====
function mgstp_read_products(){
    $all = get_option('mg_products', array());
    $out = array();
    if (is_array($all)) {
        foreach ($all as $k => $p) {
            if (!is_array($p)) continue;
            $key = isset($p['key']) ? sanitize_title($p['key']) : (is_string($k) ? sanitize_title($k) : '');
            if (!$key) continue;

            // sizes & size surcharges
            $sizes = array();
            if (isset($p['sizes']) && is_array($p['sizes'])) $sizes = array_values($p['sizes']);
            $size_surch = array();
            if (isset($p['size_surcharges']) && is_array($p['size_surcharges'])) $size_surch = $p['size_surcharges'];
            // normalize by sizes list
            $norm_size_surch = array();
            foreach ($sizes as $s) {
                $skey = (string)$s;
                $norm_size_surch[$skey] = isset($size_surch[$skey]) ? floatval($size_surch[$skey]) : 0;
            }

            // colors (+ color surcharge, mockups, print area)
            $colors = array();
            if (isset($p['colors']) && is_array($p['colors'])) {
                foreach ($p['colors'] as $c) {
                    if (!is_array($c)) { $name = (string)$c; $c = array('slug'=>sanitize_title($name),'name'=>$name); }
                    $slug = isset($c['slug']) ? sanitize_title($c['slug']) : (isset($c['name']) ? sanitize_title($c['name']) : '');
                    if (!$slug) continue;
                    $name = isset($c['name']) ? $c['name'] : $slug;
                    $mock = isset($c['mockups']) && is_array($c['mockups']) ? $c['mockups'] : array();
                    $front = isset($mock['front']) ? intval($mock['front']) : 0;
                    $back  = isset($mock['back'])  ? intval($mock['back'])  : 0;
                    $pa = isset($c['print_area']) && is_array($c['print_area']) ? $c['print_area'] : array();
                    $pa_front = isset($pa['front']) ? $pa['front'] : array('x'=>10,'y'=>10,'w'=>60,'h'=>60,'unit'=>'pct');
                    $pa_back  = isset($pa['back'])  ? $pa['back']  : array('x'=>10,'y'=>10,'w'=>60,'h'=>60,'unit'=>'pct');
                    $surcharge = isset($c['surcharge']) ? floatval($c['surcharge']) : 0;

                    $colors[] = array(
                        'slug'=>$slug,
                        'name'=>$name,
                        'surcharge'=>$surcharge,
                        'mockups'=>array('front'=>$front,'back'=>$back),
                        'print_area'=>array('front'=>$pa_front,'back'=>$pa_back),
                    );
                }
            }

            $out[$key] = array(
                'key'=>$key,
                'label'=> isset($p['label']) ? wp_kses_post($p['label']) : $key,
                'sku_prefix'=> isset($p['sku_prefix']) ? sanitize_text_field($p['sku_prefix']) : strtoupper($key),
                'price'=> isset($p['price']) ? floatval($p['price']) : 0,
                'sizes'=> $sizes,
                'size_surcharges'=> $norm_size_surch, // ÚJ
                'colors'=> $colors,
                'type_description'=> isset($p['type_description']) ? wp_kses_post($p['type_description']) : '',
            );
        }
    }
    return $out;
}

function mgstp_save_products($products){
    $norm = array();
    foreach ($products as $p) {
        // normalize size surcharges
        $sizes = is_array($p['sizes']) ? array_values($p['sizes']) : array();
        $size_surch = array();
        if (isset($p['size_surcharges']) && is_array($p['size_surcharges'])) {
            foreach ($sizes as $s) {
                $k = (string)$s;
                $size_surch[$k] = isset($p['size_surcharges'][$k]) ? floatval($p['size_surcharges'][$k]) : 0;
            }
        }

        // colors
        $colors = array();
        if (is_array($p['colors'])) {
            foreach ($p['colors'] as $c) {
                $colors[] = array(
                    'slug'=> isset($c['slug']) ? sanitize_title($c['slug']) : '',
                    'name'=> isset($c['name']) ? sanitize_text_field($c['name']) : '',
                    'surcharge'=> isset($c['surcharge']) ? floatval($c['surcharge']) : 0, // ÚJ
                    'mockups'=>array(
                        'front'=> isset($c['mockups']['front']) ? intval($c['mockups']['front']) : 0,
                        'back' => isset($c['mockups']['back'])  ? intval($c['mockups']['back'])  : 0,
                    ),
                    'print_area'=>array(
                        'front'=> array(
                            'x'=> isset($c['print_area']['front']['x']) ? floatval($c['print_area']['front']['x']) : 10,
                            'y'=> isset($c['print_area']['front']['y']) ? floatval($c['print_area']['front']['y']) : 10,
                            'w'=> isset($c['print_area']['front']['w']) ? floatval($c['print_area']['front']['w']) : 60,
                            'h'=> isset($c['print_area']['front']['h']) ? floatval($c['print_area']['front']['h']) : 60,
                            'unit'=>'pct'
                        ),
                        'back'=> array(
                            'x'=> isset($c['print_area']['back']['x']) ? floatval($c['print_area']['back']['x']) : 10,
                            'y'=> isset($c['print_area']['back']['y']) ? floatval($c['print_area']['back']['y']) : 10,
                            'w'=> isset($c['print_area']['back']['w']) ? floatval($c['print_area']['back']['w']) : 60,
                            'h'=> isset($c['print_area']['back']['h']) ? floatval($c['print_area']['back']['h']) : 60,
                            'unit'=>'pct'
                        ),
                    ),
                );
            }
        }

        $norm[] = array(
            'key'=>$p['key'],
            'label'=>$p['label'],
            'sku_prefix'=>$p['sku_prefix'],
            'price'=> isset($p['price']) ? floatval($p['price']) : 0,
            'sizes'=> $sizes,
            'size_surcharges'=> $size_surch, // ÚJ
            'colors'=> $colors,
            'type_description'=> isset($p['type_description']) ? wp_kses_post($p['type_description']) : '',
        );
    }
    update_option('mg_products', $norm);
}

// ===== RENDERER =====
function mgstp_render_settings(){
    if (!current_user_can('manage_woocommerce')) wp_die(__('Nincs jogosultság.','mgstp'));
    if (function_exists('wp_enqueue_editor')) wp_enqueue_editor();

    $products = mgstp_read_products();

    // add new type (unchanged)
    if (isset($_POST['mgstp_add']) && check_admin_referer('mgstp_add_nonce')) {
        $new_key = isset($_POST['mg_key']) ? sanitize_title(wp_unslash($_POST['mg_key'])) : '';
        $new_label = isset($_POST['mg_label']) ? wp_kses_post(wp_unslash($_POST['mg_label'])) : '';
        if ($new_key && $new_label) {
            $products[$new_key] = array(
                'key'=>$new_key, 'label'=>$new_label, 'sku_prefix'=>strtoupper($new_key),
                'price'=>0, 'sizes'=>array(), 'size_surcharges'=>array(), 'colors'=>array(), 'type_description'=>''
            );
            mgstp_save_products($products);
            wp_safe_redirect( admin_url('admin.php?page=mockup-generator-settings&product='.$new_key) );
            exit;
        } else {
            echo '<div class="notice notice-error"><p>'.esc_html__('Kulcs és Címke kötelező.','mgstp').'</p></div>';
        }
    }

    // Determine view
    $current_key = isset($_GET['product']) ? sanitize_title(wp_unslash($_GET['product'])) : '';
    $is_detail = ($current_key && isset($products[$current_key]));

    // Save detail
    if ($is_detail && isset($_POST['mgstp_save_detail']) && check_admin_referer('mgstp_save_detail_'.$current_key)) {
        $row = isset($_POST['mg']) ? wp_unslash($_POST['mg']) : array();
        $p = $products[$current_key];

        $p['label'] = isset($row['label']) ? wp_kses_post($row['label']) : $p['label'];
        $p['sku_prefix'] = isset($row['sku_prefix']) ? sanitize_text_field($row['sku_prefix']) : $p['sku_prefix'];
        $p['price'] = isset($row['price']) ? floatval($row['price']) : $p['price'];

        // sizes
        if (isset($row['sizes'])) {
            $sizes = array();
            foreach (array_map('trim', explode(',', sanitize_text_field($row['sizes']))) as $s) { if ($s!=='') $sizes[] = $s; }
            $p['sizes'] = $sizes;
        }
        // size surcharges
        $p['size_surcharges'] = array();
        if (isset($row['size_surcharges']) && is_array($row['size_surcharges'])) {
            foreach ($row['size_surcharges'] as $s => $val) {
                $p['size_surcharges'][ (string)$s ] = floatval($val);
            }
        }

        // type description
        $p['type_description'] = isset($row['type_description']) ? wp_kses_post($row['type_description']) : (isset($p['type_description'])?$p['type_description']:'');

        // colors (with surcharge)
        if (isset($row['colors']) && is_array($row['colors'])) {
            $new_colors = array();
            foreach ($row['colors'] as $slug => $cdata) {
                $name = isset($cdata['name']) ? sanitize_text_field($cdata['name']) : $slug;
                $front = isset($cdata['front']) ? intval($cdata['front']) : 0;
                $back  = isset($cdata['back'])  ? intval($cdata['back'])  : 0;
                $surch = isset($cdata['surcharge']) ? floatval($cdata['surcharge']) : 0;
                $pa_f = array(
                    'x'=> isset($cdata['pa_f_x']) ? floatval($cdata['pa_f_x']) : 10,
                    'y'=> isset($cdata['pa_f_y']) ? floatval($cdata['pa_f_y']) : 10,
                    'w'=> isset($cdata['pa_f_w']) ? floatval($cdata['pa_f_w']) : 60,
                    'h'=> isset($cdata['pa_f_h']) ? floatval($cdata['pa_f_h']) : 60,
                    'unit'=>'pct'
                );
                $pa_b = array(
                    'x'=> isset($cdata['pa_b_x']) ? floatval($cdata['pa_b_x']) : 10,
                    'y'=> isset($cdata['pa_b_y']) ? floatval($cdata['pa_b_y']) : 10,
                    'w'=> isset($cdata['pa_b_w']) ? floatval($cdata['pa_b_w']) : 60,
                    'h'=> isset($cdata['pa_b_h']) ? floatval($cdata['pa_b_h']) : 60,
                    'unit'=>'pct'
                );
                $new_colors[] = array(
                    'slug'=> sanitize_title($slug),
                    'name'=> $name,
                    'surcharge'=> $surch,
                    'mockups'=> array('front'=>$front,'back'=>$back),
                    'print_area'=> array('front'=>$pa_f,'back'=>$pa_b),
                );
            }
            $p['colors'] = $new_colors;
        }

        $products[$current_key] = $p;
        mgstp_save_products($products);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Mentve.','mgstp').'</p></div>';
    }

    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Mockup Generator – Beállítások','mgstp').'</h1>';

    // List view
    if (!$is_detail) {
        echo '<div class="card" style="padding:16px;margin-bottom:16px;max-width:880px">';
        echo '<h2>'.esc_html__('Új terméktípus','mgstp').'</h2>';
        echo '<form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">';
        wp_nonce_field('mgstp_add_nonce');
        echo '<p><label>'.esc_html__('Kulcs (slug)','mgstp').'<br><input type="text" name="mg_key" required placeholder="pl. polo"></label></p>';
        echo '<p><label>'.esc_html__('Címke','mgstp').'<br><input type="text" name="mg_label" required placeholder="pl. Póló"></label></p>';
        submit_button(__('Hozzáadás','mgstp'), 'secondary', 'mgstp_add', false);
        echo '</form></div>';

        $products = mgstp_read_products();
        if (empty($products)) {
            echo '<p><em>'.esc_html__('Még nincs terméktípus. Add hozzá fent.','mgstp').'</em></p>';
        } else {
            echo '<h2>'.esc_html__('Terméktípusok','mgstp').'</h2>';
            echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>'.esc_html__('Kulcs','mgstp').'</th><th>'.esc_html__('Címke','mgstp').'</th><th>'.esc_html__('Művelet','mgstp').'</th></tr></thead><tbody>';
            foreach ($products as $p){
                $url = admin_url('admin.php?page=mockup-generator-settings&product='.$p['key']);
                echo '<tr><td><code>'.esc_html($p['key']).'</code></td><td>'.esc_html($p['label']).'</td><td><a class="button button-primary" href="'.esc_url($url).'">'.esc_html__('Megnyitás','mgstp').'</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return;
    }

    // Detail view
    $p = $products[$current_key];
    echo '<h2>'.esc_html($p['label']).' <code style="opacity:.7">'.esc_html($p['key']).'</code></h2>';
    echo '<form method="post">';
    wp_nonce_field('mgstp_save_detail_'.$current_key);
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>'.esc_html__('Címke','mgstp').'</th><td><input type="text" name="mg[label]" value="'.esc_attr($p['label']).'"></td></tr>';
    echo '<tr><th>'.esc_html__('SKU prefix','mgstp').'</th><td><input type="text" name="mg[sku_prefix]" value="'.esc_attr($p['sku_prefix']).'"></td></tr>';
    echo '<tr><th>'.esc_html__('Alapár','mgstp').'</th><td><input type="number" min="0" step="1" name="mg[price]" value="'.esc_attr($p['price']).'"></td></tr>';

    // Sizes
    $sizes_str = implode(', ',$p['sizes']);
    echo '<tr><th>'.esc_html__('Méretvariációk','mgstp').'</th><td><input type="text" name="mg[sizes]" value="'.esc_attr($sizes_str).'" style="width:100%"><p class="description">pl. S, M, L, XL</p></td></tr>';

    // NEW: Size Surcharges table
    echo '<tr><th>'.esc_html__('Méret felárak','mgstp').'</th><td>';
    if (!empty($p['sizes'])) {
        echo '<table class="widefat striped" style="max-width:440px"><thead><tr><th>'.esc_html__('Méret','mgstp').'</th><th>'.esc_html__('Felár','mgstp').'</th></tr></thead><tbody>';
        foreach ($p['sizes'] as $sz) {
            $val = isset($p['size_surcharges'][$sz]) ? $p['size_surcharges'][$sz] : 0;
            echo '<tr><td>'.esc_html($sz).'</td><td><input type="number" name="mg[size_surcharges]['.esc_attr($sz).']" step="0.01" value="'.esc_attr($val).'"> <span class="description">'.esc_html__('nettó / bruttó a saját árlogikád szerint','mgstp').'</span></td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<em>'.esc_html__('Előbb add meg a méretlistát fent. Mentés után itt megjelennek a felár mezők.','mgstp').'</em>';
    }
    echo '</td></tr>';

    // Type description (unchanged)
    echo '<tr><th>'.esc_html__('Típus leírás','mgstp').'</th><td>';
    if (function_exists('wp_editor')) {
        ob_start();
        wp_editor($p['type_description'], 'mg_type_desc_'.$current_key, array(
            'textarea_name' => 'mg[type_description]',
            'textarea_rows' => 10,
            'media_buttons' => false,
        ));
        echo ob_get_clean();
    } else {
        echo '<textarea name="mg[type_description]" rows="10" style="width:100%">'.esc_textarea($p['type_description']).'</textarea>';
    }
    echo '</td></tr>';

    echo '</tbody></table>';

    // Colors UI (assumes your plugin already renders it; we inject the surcharge field next to color name if not existing)
    // Minimal inline enhancer: if you render colors in your own way, ignore this.

    submit_button(__('Mentés','mgstp'), 'primary', 'mgstp_save_detail', true);
    echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=mockup-generator-settings')).'">← '.esc_html__('Vissza a listához','mgstp').'</a>';
    echo '</form></div>';
}
