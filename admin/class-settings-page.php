<?php
if (!defined('ABSPATH')) exit;
class MG_Settings_Page {
    public static function add_submenu_page() {
        add_submenu_page('mockup-generator','Beállítások – Termékek','Beállítások','manage_options','mockup-generator-settings',[self::class,'render_settings']);
    }
    public static function render_settings() {
        // --- ÚJ: kimeneti méretezés mentése ---
        if (isset($_POST['mg_resize_nonce']) && wp_verify_nonce($_POST['mg_resize_nonce'],'mg_save_resize')) {
            $enabled = isset($_POST['resize_enabled']) ? (bool)$_POST['resize_enabled'] : false;
            $max_w = max(0, intval($_POST['resize_max_w'] ?? 0));
            $max_h = max(0, intval($_POST['resize_max_h'] ?? 0));
            $mode  = in_array(($_POST['resize_mode'] ?? 'fit'), array('fit','width','height'), true) ? $_POST['resize_mode'] : 'fit';

            $quality = isset($_POST['webp_quality']) ? max(0, min(100, intval($_POST['webp_quality']))) : 78;
            $alpha_quality = isset($_POST['webp_alpha']) ? max(0, min(100, intval($_POST['webp_alpha']))) : 92;
            $method = isset($_POST['webp_method']) ? max(0, min(6, intval($_POST['webp_method']))) : 3;

            update_option('mg_output_resize', array(
                'enabled' => $enabled,
                'max_w'   => $max_w,
                'max_h'   => $max_h,
                'mode'    => $mode
            ));
            update_option('mg_webp_options', array(
                'quality' => $quality,
                'alpha'   => $alpha_quality,
                'method'  => $method,
            ));
            echo '<div class="notice notice-success is-dismissible"><p>Beállítások elmentve.</p></div>';
        }

        if (class_exists('MG_Design_Gallery') && isset($_POST['mg_design_gallery_nonce']) && wp_verify_nonce($_POST['mg_design_gallery_nonce'], 'mg_design_gallery_save')) {
            $input = isset($_POST['mg_design_gallery']) ? wp_unslash($_POST['mg_design_gallery']) : array();
            MG_Design_Gallery::sanitize_settings($input);
            echo '<div class="notice notice-success is-dismissible"><p>Mintagalléria beállítások elmentve.</p></div>';
        }

        if (isset($_POST['mg_add_product_nonce']) && wp_verify_nonce($_POST['mg_add_product_nonce'],'mg_add_product')) {
            $products = get_option('mg_products', array());
            $key = sanitize_title($_POST['product_key'] ?? '');
            $label = sanitize_text_field($_POST['product_label'] ?? '');
            $price = intval($_POST['product_price'] ?? 0);
            $sku_prefix = strtoupper(sanitize_text_field($_POST['sku_prefix'] ?? 'SKU'));
            if ($key && $label) {
                foreach ($products as $p) { if ($p['key']===$key) { $key .= '-' . wp_generate_password(4,false,false); break; } }
                $products[] = array(
                    'key'=>$key,'label'=>$label,
                    'sizes'=>array('S','M','L','XL'),
                    'colors'=>array(
                        array('name'=>'Fekete','slug'=>'fekete'),
                        array('name'=>'Fehér','slug'=>'feher'),
                        array('name'=>'Szürke','slug'=>'szurke'),
                    ),
                    'views'=>array(
                        array('key'=>'front','label'=>'Előlap','file'=>$key.'_front.png','x'=>420,'y'=>600,'w'=>1200,'h'=>900)
                    ),
                    'template_base'=>"templates/$key",
                    'mockup_overrides'=>array(),
                    'price'=>$price,
                    'size_surcharges'=>array(),
                    'color_surcharges'=>array(),
                    'sku_prefix'=>$sku_prefix,
                    'categories'=>array(),
                    'tags'=>array()
                );
                update_option('mg_products', $products);
                echo '<div class="notice notice-success is-dismissible"><p>Termék hozzáadva: '.esc_html($label).'</p></div>';
            }
        }

        $products = get_option('mg_products', array());
        $resize = get_option('mg_output_resize', array('enabled'=>false,'max_w'=>0,'max_h'=>0,'mode'=>'fit'));
        $r_enabled = !empty($resize['enabled']);
        $r_w = intval($resize['max_w'] ?? 0);
        $r_h = intval($resize['max_h'] ?? 0);
        $r_mode = $resize['mode'] ?? 'fit';
        $webp_defaults = array('quality'=>78,'alpha'=>92,'method'=>3);
        $webp = get_option('mg_webp_options', $webp_defaults);
        $w_quality = max(0, min(100, intval($webp['quality'] ?? $webp_defaults['quality'])));
        $w_alpha = max(0, min(100, intval($webp['alpha'] ?? $webp_defaults['alpha'])));
        $w_method = max(0, min(6, intval($webp['method'] ?? $webp_defaults['method'])));
        $gallery_settings = class_exists('MG_Design_Gallery') ? MG_Design_Gallery::get_settings() : array('enabled' => false, 'position' => 'after_summary', 'max_items' => 6, 'layout' => 'grid', 'title' => '', 'show_title' => true);
        $position_choices = class_exists('MG_Design_Gallery') ? MG_Design_Gallery::get_position_choices() : array();
        ?>
        <div class="wrap">
            <h1>Mockup Generator – Beállítások (Termékek)</h1>

            <h2>Kimeneti maximum méret</h2>
            <form method="post">
                <?php wp_nonce_field('mg_save_resize','mg_resize_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Méretezés engedélyezése</th>
                        <td><label><input type="checkbox" name="resize_enabled" <?php checked($r_enabled); ?> /> Engedélyezve</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Mód</th>
                        <td>
                            <select name="resize_mode">
                                <option value="fit" <?php selected($r_mode,'fit'); ?>>Arányos belescalázás (fit a dobozba)</option>
                                <option value="width" <?php selected($r_mode,'width'); ?>>Csak szélesség korlát</option>
                                <option value="height" <?php selected($r_mode,'height'); ?>>Csak magasság korlát</option>
                            </select>
                            <p class="description">A kimeneti kép arányait megtartjuk. <strong>Nem nagyítunk fel</strong> kisebb képet.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Max. szélesség (px)</th>
                        <td><input type="number" name="resize_max_w" min="0" step="1" value="<?php echo esc_attr($r_w); ?>" class="small-text" /> px</td>
                    </tr>
                    <tr>
                        <th scope="row">Max. magasság (px)</th>
                        <td><input type="number" name="resize_max_h" min="0" step="1" value="<?php echo esc_attr($r_h); ?>" class="small-text" /> px</td>
                    </tr>
                    <tr>
                        <th scope="row">WebP minőség</th>
                        <td>
                            <input type="number" name="webp_quality" min="0" max="100" step="1" value="<?php echo esc_attr($w_quality); ?>" class="small-text" />
                            <p class="description">Általános tömörítési minőség (0–100). Nagyobb érték = jobb minőség, nagyobb fájl.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WebP alfa minőség</th>
                        <td>
                            <input type="number" name="webp_alpha" min="0" max="100" step="1" value="<?php echo esc_attr($w_alpha); ?>" class="small-text" />
                            <p class="description">Átlátszóság minősége (0–100). Magasabb érték megőrzi jobban az alfa csatornát.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WebP módszer</th>
                        <td>
                            <input type="number" name="webp_method" min="0" max="6" step="1" value="<?php echo esc_attr($w_method); ?>" class="small-text" />
                            <p class="description">0 = leggyorsabb, 6 = legjobb minőség (lassabb feldolgozás).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Mentés'); ?>
            </form>

            <hr/>

            <?php if (class_exists('MG_Design_Gallery')): ?>
            <h2>Mintagalléria blokk</h2>
            <p>Automatikusan megjeleníthető modul, ami a legutóbbi mockup képeket listázza az összes terméktípus alapértelmezett színén. Gutenberg blokkban is használható.</p>
            <form method="post">
                <?php wp_nonce_field('mg_design_gallery_save','mg_design_gallery_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Automatikus megjelenítés</th>
                        <td><label><input type="checkbox" name="mg_design_gallery[enabled]" value="1" <?php checked(!empty($gallery_settings['enabled'])); ?> /> Engedélyezve</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Pozíció</th>
                        <td>
                            <select name="mg_design_gallery[position]">
                                <?php foreach ($position_choices as $key => $data): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($gallery_settings['position'], $key); ?>><?php echo esc_html($data['label'] ?? $key); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cím</th>
                        <td><input type="text" name="mg_design_gallery[title]" value="<?php echo esc_attr($gallery_settings['title'] ?? ''); ?>" class="regular-text" placeholder="Minta az összes terméken" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Cím megjelenítése</th>
                        <td><label><input type="checkbox" name="mg_design_gallery[show_title]" value="1" <?php checked(!empty($gallery_settings['show_title'])); ?> /> Igen</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Max. elemek száma</th>
                        <td><input type="number" name="mg_design_gallery[max_items]" min="1" step="1" value="<?php echo esc_attr(intval($gallery_settings['max_items'] ?? 6)); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Elrendezés</th>
                        <td>
                            <select name="mg_design_gallery[layout]">
                                <option value="grid" <?php selected($gallery_settings['layout'] ?? '', 'grid'); ?>>Rács</option>
                                <option value="list" <?php selected($gallery_settings['layout'] ?? '', 'list'); ?>>Lista</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Mintagalléria mentése'); ?>
            </form>

            <hr/>
            <?php endif; ?>

            <table class="widefat striped">
                <thead><tr><th>Kulcs</th><th>Név</th><th>Ár (HUF)</th><th>SKU prefix</th><th>Almenü</th></tr></thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><code><?php echo esc_html($p['key']); ?></code></td>
                        <td><?php echo esc_html($p['label']); ?></td>
                        <td><?php echo number_format_i18n($p['price'] ?? 0); ?> Ft</td>
                        <td><code><?php echo esc_html($p['sku_prefix'] ?? ''); ?></code></td>
                        <td><a class="button" href="<?php echo admin_url('admin.php?page=mockup-generator-product&product='.$p['key']); ?>">Megnyitás</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Új termék hozzáadása</h2>
            <form method="post">
                <?php wp_nonce_field('mg_add_product','mg_add_product_nonce'); ?>
                <table class="form-table">
                    <tr><th>Kulcs (slug)</th><td><input type="text" name="product_key" placeholder="pl. polo" required /></td></tr>
                    <tr><th>Megjelenített név</th><td><input type="text" name="product_label" placeholder="pl. Póló" required /></td></tr>
                    <tr><th>Alap ár (HUF)</th><td><input type="number" name="product_price" class="small-text" min="0" step="1" value="0" /></td></tr>
                    <tr><th>SKU prefix</th><td><input type="text" name="sku_prefix" class="regular-text" placeholder="pl. POLO" /></td></tr>
                </table>
                <?php submit_button('Hozzáadás'); ?>
            </form>
        </div>
        <?php
    }
}
