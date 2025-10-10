<?php
if (!defined('ABSPATH')) exit;

// ===== Helpers =====
function mgstp_read_products(){
    $all = get_option('mg_products', array());
    $out = array();
    if (is_array($all)) {
        foreach ($all as $k => $p) {
            if (!is_array($p)) continue;
            $key = isset($p['key']) ? sanitize_title($p['key']) : (is_string($k) ? sanitize_title($k) : '');
            if (!$key) continue;
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
                    $colors[] = array(
                        'slug'=>$slug,
                        'name'=>$name,
                        'mockups'=>array('front'=>$front,'back'=>$back),
                        'print_area'=>array('front'=>$pa_front,'back'=>$pa_back),
                    );
                }
            }
            $is_primary = !empty($p['is_primary']);
            $primary_color = isset($p['primary_color']) ? sanitize_title($p['primary_color']) : '';
            if ($primary_color && !array_filter($colors, function($c) use ($primary_color){ return $c['slug'] === $primary_color; })) {
                $primary_color = '';
            }
            $sizes = isset($p['sizes']) && is_array($p['sizes']) ? array_values(array_filter(array_map('trim', $p['sizes']), function($s){ return $s !== ''; })) : array();
            $primary_size = isset($p['primary_size']) ? sanitize_text_field($p['primary_size']) : '';
            if ($primary_size && !in_array($primary_size, $sizes, true)) {
                $primary_size = '';
            }
            $out[$key] = array(
                'key'=>$key,
                'label'=> isset($p['label']) ? wp_kses_post($p['label']) : $key,
                'sku_prefix'=> isset($p['sku_prefix']) ? sanitize_text_field($p['sku_prefix']) : strtoupper($key),
                'price'=> isset($p['price']) ? intval($p['price']) : 0,
                'sizes'=> $sizes,
                'colors'=> $colors,
                // ÚJ
                'type_description'=> isset($p['type_description']) ? wp_kses_post($p['type_description']) : '',
                'is_primary' => $is_primary ? 1 : 0,
                'primary_color' => $primary_color,
                'primary_size' => $primary_size,
            );
        }
    }
    return $out;
}

function mgstp_save_products($products){
    $norm = array();
    foreach ($products as $p) {
        $colors = array();
        if (is_array($p['colors'])) {
            foreach ($p['colors'] as $c) {
                $colors[] = array(
                    'slug'=>$c['slug'],
                    'name'=>$c['name'],
                    'mockups'=>array(
                        'front'=> isset($c['mockups']['front']) ? intval($c['mockups']['front']) : 0,
                        'back' => isset($c['mockups']['back'])  ? intval($c['mockups']['back'])  : 0,
                    ),
                    'print_area'=>array(
                        'front'=> array(
                            'x'=> floatval($c['print_area']['front']['x']),
                            'y'=> floatval($c['print_area']['front']['y']),
                            'w'=> floatval($c['print_area']['front']['w']),
                            'h'=> floatval($c['print_area']['front']['h']),
                            'unit'=>'pct'
                        ),
                        'back'=> array(
                            'x'=> floatval($c['print_area']['back']['x']),
                            'y'=> floatval($c['print_area']['back']['y']),
                            'w'=> floatval($c['print_area']['back']['w']),
                            'h'=> floatval($c['print_area']['back']['h']),
                            'unit'=>'pct'
                        ),
                    ),
                );
            }
        }
        $sizes = is_array($p['sizes']) ? array_values(array_filter(array_map('trim', $p['sizes']), function($s){ return $s !== ''; })) : array();
        $primary_size = isset($p['primary_size']) ? sanitize_text_field($p['primary_size']) : '';
        if ($primary_size && !in_array($primary_size, $sizes, true)) {
            $primary_size = '';
        }
        $norm[] = array(
            'key'=>$p['key'],
            'label'=>$p['label'],
            'sku_prefix'=>$p['sku_prefix'],
            'price'=> intval($p['price']),
            'sizes'=> $sizes,
            'colors'=> $colors,
            // ÚJ
            'type_description'=> isset($p['type_description']) ? wp_kses_post($p['type_description']) : '',
            'is_primary' => !empty($p['is_primary']) ? 1 : 0,
            'primary_color' => isset($p['primary_color']) ? sanitize_title($p['primary_color']) : '',
            'primary_size' => $primary_size,
        );
    }
    update_option('mg_products', $norm);
}

function mgstp_render_settings(){
    if (!current_user_can('manage_woocommerce')) wp_die(__('Nincs jogosultság.','mgstp'));
    if (function_exists('wp_enqueue_editor')) wp_enqueue_editor();

    $products = mgstp_read_products();

    if (isset($_POST['mgstp_add']) && check_admin_referer('mgstp_add_nonce')) {
        $new_key = isset($_POST['mg_key']) ? sanitize_title(wp_unslash($_POST['mg_key'])) : '';
        $new_label = isset($_POST['mg_label']) ? wp_kses_post(wp_unslash($_POST['mg_label'])) : '';
        if ($new_key && $new_label) {
            $products[$new_key] = array(
                'key'=>$new_key, 'label'=>$new_label, 'sku_prefix'=>strtoupper($new_key),
                'price'=>0, 'sizes'=>array(), 'colors'=>array(), 'type_description'=>'',
                'is_primary'=>0, 'primary_color'=>'', 'primary_size'=>''
            );
            mgstp_save_products($products);
            wp_safe_redirect( admin_url('admin.php?page=mockup-generator-settings&product='.$new_key) );
            exit;
        } else {
            echo '<div class="notice notice-error"><p>'.esc_html__('Kulcs és Címke kötelező.','mgstp').'</p></div>';
        }
    }

    $current_key = isset($_GET['product']) ? sanitize_title(wp_unslash($_GET['product'])) : '';
    $is_detail = ($current_key && isset($products[$current_key]));

    if ($is_detail && isset($_POST['mgstp_save_detail']) && check_admin_referer('mgstp_save_detail_'.$current_key)) {
        $row = isset($_POST['mg']) ? wp_unslash($_POST['mg']) : array();
        $label = isset($row['label']) ? wp_kses_post($row['label']) : $products[$current_key]['label'];
        $sku   = isset($row['sku_prefix']) ? sanitize_text_field($row['sku_prefix']) : $products[$current_key]['sku_prefix'];
        $price = isset($row['price']) ? intval($row['price']) : $products[$current_key]['price'];
        $sizes_in = isset($row['sizes']) ? sanitize_text_field($row['sizes']) : '';
        $sizes = array();
        if (!empty($sizes_in)) {
            foreach (array_map('trim', explode(',', $sizes_in)) as $s) { if ($s!=='') $sizes[] = $s; }
        } else { $sizes = $products[$current_key]['sizes']; }

        // ÚJ: típus-leírás fogadása
        $type_desc = isset($row['type_description']) ? wp_kses_post($row['type_description']) : $products[$current_key]['type_description'];

        // Színek mockup/print_area: változatlanul hagyva
        $colors = isset($row['colors']) && is_array($row['colors']) ? $row['colors'] : $products[$current_key]['colors'];

        $is_primary = !empty($row['is_primary']);
        $primary_color = isset($row['primary_color']) ? sanitize_title($row['primary_color']) : ($products[$current_key]['primary_color'] ?? '');
        $primary_size = isset($row['primary_size']) ? sanitize_text_field($row['primary_size']) : ($products[$current_key]['primary_size'] ?? '');
        $color_slugs = array();
        if (is_array($colors)) {
            foreach ($colors as $c) {
                if (is_array($c) && isset($c['slug'])) {
                    $color_slugs[] = $c['slug'];
                }
            }
        }
        if ($primary_color && !in_array($primary_color, $color_slugs, true)) {
            $primary_color = '';
        }
        if ($primary_size && !in_array($primary_size, $sizes, true)) {
            $primary_size = '';
        }
        if ($is_primary && !$primary_color && !empty($color_slugs)) {
            $primary_color = $color_slugs[0];
        }
        if ($is_primary && !$primary_size && !empty($sizes)) {
            $primary_size = $sizes[0];
        }
        if ($is_primary) {
            foreach ($products as $key => $existing) {
                if ($key !== $current_key) {
                    $products[$key]['is_primary'] = 0;
                }
            }
        }
        $products[$current_key] = array(
            'key'=>$current_key,'label'=>$label,'sku_prefix'=>$sku,'price'=>$price,
            'sizes'=>$sizes,'colors'=>$colors,
            'type_description'=>$type_desc,
            'is_primary'=> $is_primary ? 1 : 0,
            'primary_color'=> $primary_color,
            'primary_size' => $primary_size,
        );
        mgstp_save_products($products);
        echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Mentve.','mgstp').'</p></div>';
    }

    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Mockup Generator – Beállítások','mgstp').'</h1>';

    if (!$is_detail) {
        echo '<div class="card" style="padding:16px;margin-bottom:16px;max-width:880px">';
        echo '<h2>'.esc_html__('Új terméktípus','mgstp').'</h2>';
        echo '<form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">';
        wp_nonce_field('mgstp_add_nonce');
        echo '<p><label>'.esc_html__('Kulcs (slug)','mgstp').'<br><input type="text" name="mg_key" required placeholder="pl. polo"></label></p>';
        echo '<p><label>'.esc_html__('Címke','mgstp').'<br><input type="text" name="mg_label" required placeholder="pl. Póló"></label></p>';
        submit_button(__('Hozzáadás','mgstp'), 'secondary', 'mgstp_add', false);
        echo '</form></div>';

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

    $p = $products[$current_key];
    echo '<h2>'.esc_html($p['label']).' <code style="opacity:.7">'.esc_html($p['key']).'</code></h2>';
    echo '<form method="post">';
    wp_nonce_field('mgstp_save_detail_'.$current_key);
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>'.esc_html__('Címke','mgstp').'</th><td><input type="text" name="mg[label]" value="'.esc_attr($p['label']).'"></td></tr>';
    echo '<tr><th>'.esc_html__('SKU prefix','mgstp').'</th><td><input type="text" name="mg[sku_prefix]" value="'.esc_attr($p['sku_prefix']).'"></td></tr>';
    echo '<tr><th>'.esc_html__('Alapár','mgstp').'</th><td><input type="number" min="0" step="1" name="mg[price]" value="'.esc_attr($p['price']).'"></td></tr>';
    $sizes_str = implode(', ',$p['sizes']);
    echo '<tr><th>'.esc_html__('Méretvariációk','mgstp').'</th><td><input type="text" name="mg[sizes]" value="'.esc_attr($sizes_str).'" style="width:100%"><p class="description">pl. S, M, L, XL</p></td></tr>';

    // ÚJ: Típus leírás editor ugyanitt
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
    echo '<p class="description">'.esc_html__('Ez a típushoz tartozó szülő termék leírása lesz.','mgstp').'</p>';
    echo '</td></tr>';

    $is_primary_checked = !empty($p['is_primary']);
    $selected_primary_color = isset($p['primary_color']) ? $p['primary_color'] : '';
    $selected_primary_size = isset($p['primary_size']) ? $p['primary_size'] : '';
    $color_select = '<select name="mg[primary_color]" style="min-width:220px">';
    $color_select .= '<option value="">— '.esc_html__('Válassz színt','mgstp').' —</option>';
    if (!empty($p['colors']) && is_array($p['colors'])) {
        foreach ($p['colors'] as $c) {
            if (!isset($c['slug']) || !isset($c['name'])) continue;
            $color_select .= '<option value="'.esc_attr($c['slug']).'"'.selected($selected_primary_color, $c['slug'], false).'>'.esc_html($c['name']).'</option>';
        }
    }
    $color_select .= '</select>';
    $size_select = '<select name="mg[primary_size]" style="min-width:220px">';
    $size_select .= '<option value="">— '.esc_html__('Válassz méretet','mgstp').' —</option>';
    if (!empty($p['sizes']) && is_array($p['sizes'])) {
        foreach ($p['sizes'] as $size_label) {
            if (!is_string($size_label) || $size_label === '') continue;
            $size_select .= '<option value="'.esc_attr($size_label).'"'.selected($selected_primary_size, $size_label, false).'>'.esc_html($size_label).'</option>';
        }
    }
    $size_select .= '</select>';
    echo '<tr><th>'.esc_html__('Elsődleges beállítás','mgstp').'</th><td>';
    echo '<label><input type="checkbox" name="mg[is_primary]" value="1"'.checked($is_primary_checked, true, false).'> '.esc_html__('Ez legyen az alapértelmezett terméktípus','mgstp').'</label>';
    echo '<div style="margin-top:8px"><label>'.esc_html__('Elsődleges szín','mgstp').'<br>'.$color_select.'</label>';
    echo '<p class="description">'.esc_html__('Csak az itt felsorolt színek közül választhatsz. A kiválasztott szín jelenik meg alapértelmezetten a bulk feltöltésnél.','mgstp').'</p></div>';
    echo '<div style="margin-top:8px"><label>'.esc_html__('Elsődleges méret','mgstp').'<br>'.$size_select.'</label>';
    echo '<p class="description">'.esc_html__('A kiválasztott méret jelenik meg alapértelmezettként a bulk feltöltéskor.','mgstp').'</p></div>';
    echo '</td></tr>';

    echo '</tbody></table>';

    submit_button(__('Mentés','mgstp'), 'primary', 'mgstp_save_detail', true);
    echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=mockup-generator-settings')).'">← '.esc_html__('Vissza a listához','mgstp').'</a>';
    echo '</form></div>';
}
