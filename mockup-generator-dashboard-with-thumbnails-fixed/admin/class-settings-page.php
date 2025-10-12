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
            update_option('mg_output_resize', array(
                'enabled' => $enabled,
                'max_w'   => $max_w,
                'max_h'   => $max_h,
                'mode'    => $mode
            ));
            echo '<div class="notice notice-success is-dismissible"><p>Kimeneti méretezés elmentve.</p></div>';
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
                </table>
                <?php submit_button('Mentés'); ?>
            </form>

            <hr/>

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
