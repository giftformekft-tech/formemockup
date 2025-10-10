<?php
if (!defined('ABSPATH')) exit;
class MG_Product_Settings_Page {
    public static function register_dynamic_product_submenus() {
        add_submenu_page('mockup-generator','Termék – szerkesztés','Termék: szerkesztés','manage_options','mockup-generator-product',[self::class,'render_product'],20);
    }
    private static function get_product_by_key($key) {
        $products = get_option('mg_products', array());
        
// --- Törlés kezelése ---
if (isset($_GET['mg_delete_key']) && current_user_can('manage_options')) {
    $del_key = sanitize_text_field($_GET['mg_delete_key']);
    $all = get_option('mg_products', array());
    $all = array_filter($all, function($p) use ($del_key) {
        return isset($p['key']) && $p['key'] !== $del_key;
    });
    update_option('mg_products', array_values($all));
    wp_safe_redirect(admin_url('admin.php?page=mockup-generator-settings&deleted=1'));
    exit;
}
foreach ($products as $p) if ($p['key']===$key) return $p;
        return null;
    }
    private static function save_product($prod) {
        $products = get_option('mg_products', array());
        $is_primary = !empty($prod['is_primary']);
        foreach ($products as $i=>$p) {
            if (!is_array($p) || !isset($p['key'])) continue;
            if ($p['key']===$prod['key']) {
                $products[$i]=$prod;
            } elseif ($is_primary) {
                $products[$i]['is_primary'] = 0;
            }
        }
        update_option('mg_products',$products);
    }
    public static function render_product() {
        // Enqueue assets for Print Area modal
        wp_enqueue_script('mg-printarea', plugin_dir_url(__FILE__).'../assets/js/printarea.js', array('jquery'), '1.0.0', true);
        wp_enqueue_style('mg-printarea', plugin_dir_url(__FILE__).'../assets/css/printarea.css', array(), '1.0.0');

        $key = sanitize_text_field($_GET['product'] ?? '');
        $prod = self::get_product_by_key($key);
        if (!$prod) { echo '<div class="notice notice-error"><p>Ismeretlen termék.</p></div>'; return; }

        if (isset($_POST['mg_save_product_nonce']) && wp_verify_nonce($_POST['mg_save_product_nonce'],'mg_save_product')) {
            $sizes = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['sizes'] ?? ''))));
            if (!empty($sizes)) $prod['sizes']=$sizes;

            $colors_text = sanitize_textarea_field($_POST['colors'] ?? '');
            $color_lines = array_filter(array_map('trim', explode(PHP_EOL, $colors_text)));
            $colors = array();
            foreach ($color_lines as $line) {
                if (strpos($line, ':') !== false) {
                    list($name,$slug) = array_map('trim', explode(':', $line, 2));
                    $colors[] = array('name'=>$name,'slug'=>sanitize_title($slug));
                }
            }
            if (!empty($colors)) $prod['colors']=$colors;

            $is_primary = !empty($_POST['is_primary']) ? 1 : 0;
            $chosen_color = isset($_POST['primary_color']) ? sanitize_title($_POST['primary_color']) : ($prod['primary_color'] ?? '');
            $chosen_size = isset($_POST['primary_size']) ? sanitize_text_field($_POST['primary_size']) : ($prod['primary_size'] ?? '');
            $color_slugs = array();
            if (!empty($prod['colors']) && is_array($prod['colors'])) {
                foreach ($prod['colors'] as $c) {
                    if (isset($c['slug'])) { $color_slugs[] = $c['slug']; }
                }
            }
            $size_values = isset($prod['sizes']) && is_array($prod['sizes']) ? array_values(array_filter(array_map('trim', $prod['sizes']), function($s){ return $s !== ''; })) : array();
            if ($chosen_color && !in_array($chosen_color, $color_slugs, true)) {
                $chosen_color = '';
            }
            if ($chosen_size && !in_array($chosen_size, $size_values, true)) {
                $chosen_size = '';
            }
            if ($is_primary && !$chosen_color && !empty($color_slugs)) {
                $chosen_color = $color_slugs[0];
            }
            if ($is_primary && !$chosen_size && !empty($size_values)) {
                $chosen_size = $size_values[0];
            }
            $prod['is_primary'] = $is_primary;
            $prod['primary_color'] = $chosen_color;
            $prod['primary_size'] = $chosen_size;

            $views_json = stripslashes($_POST['views'] ?? '');
            $views = json_decode($views_json, true);
            if (is_array($views)) $prod['views']=$views;

            $base = sanitize_text_field($_POST['template_base'] ?? $prod['template_base']);
            $prod['template_base']=$base;

            $price = intval($_POST['price'] ?? $prod['price'] ?? 0);
            $prod['price'] = $price;

            $prod['sku_prefix'] = strtoupper(sanitize_text_field($_POST['sku_prefix'] ?? ($prod['sku_prefix'] ?? '')));

            
// -- ÚJ: termék leírás mentése --
if (isset($_POST['type_description'])) {
    $prod['type_description'] = wp_kses_post(stripslashes($_POST['type_description']));
}
// -- ÚJ: méret felárak mentése --
if (isset($_POST['size_surcharges']) && is_array($_POST['size_surcharges'])) {
    $ss = array();
    foreach ($_POST['size_surcharges'] as $size => $val) {
        $size_key = sanitize_text_field($size);
        $amount = intval($val);
        if ($size_key !== '') { $ss[$size_key] = $amount; }
    }
    $prod['size_surcharges'] = $ss;
}
if (!isset($prod['mockup_overrides']) || !is_array($prod['mockup_overrides'])) $prod['mockup_overrides'] = array();
            if (!empty($_FILES['mockup_files']['name'])) {
                foreach ($_FILES['mockup_files']['name'] as $color_slug => $files) {
                    foreach ($files as $file_key => $name) {
                        if (!empty($name) && !empty($_FILES['mockup_files']['tmp_name'][$color_slug][$file_key])) {
                            $file = array(
                                'name' => $name,
                                'type' => $_FILES['mockup_files']['type'][$color_slug][$file_key],
                                'tmp_name' => $_FILES['mockup_files']['tmp_name'][$color_slug][$file_key],
                                'error' => $_FILES['mockup_files']['error'][$color_slug][$file_key],
                                'size' => $_FILES['mockup_files']['size'][$color_slug][$file_key],
                            );
                            $uploaded = wp_handle_upload($file, array('test_form'=>false, 'mimes'=>array('png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp')));
                            if (empty($uploaded['error'])) {
                                if (!isset($prod['mockup_overrides'][$color_slug])) $prod['mockup_overrides'][$color_slug] = array();
                                $prod['mockup_overrides'][$color_slug][$file_key] = $uploaded['file'];
                            }
                        }
                    }
                }
            }

            self::save_product($prod);
            echo '<div class="notice notice-success is-dismissible"><p>Termék beállításai elmentve.</p></div>';
        }

        $sizes = $prod['sizes'];
        $colors = $prod['colors'];
        $views  = $prod['views'];
        $template_base = $prod['template_base'];
        $sku_prefix = $prod['sku_prefix'] ?? '';
        $price = intval($prod['price'] ?? 0);
        $over = isset($prod['mockup_overrides']) && is_array($prod['mockup_overrides']) ? $prod['mockup_overrides'] : array();

        $colors_text = implode(PHP_EOL, array_map(function($c){ return $c['name'].':'.$c['slug']; }, $colors));
        $is_primary = !empty($prod['is_primary']);
        $primary_color = isset($prod['primary_color']) ? $prod['primary_color'] : '';
        $primary_size = isset($prod['primary_size']) ? $prod['primary_size'] : '';

        // Helper: path -> URL in uploads
        $uploads = wp_upload_dir();
        $uploads_base = trailingslashit($uploads['basedir']);
        $uploads_url  = trailingslashit($uploads['baseurl']);

        ?>
        <div class="wrap">
            <h1>Termék beállítások – <?php echo esc_html($prod['label']); ?>
<a href="<?php echo esc_url(add_query_arg('mg_delete_key', $prod['key'])); ?>"
   class="button button-link-delete"
   onclick="return confirm('Biztosan törlöd ezt a terméktípust?');">Törlés</a> (<code><?php echo esc_html($prod['key']); ?></code>)</h1>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('mg_save_product','mg_save_product_nonce'); ?>
                <h2>Alap ár (HUF)</h2>
                <p><input type="number" name="price" class="small-text" min="0" step="1" value="<?php echo esc_attr($price); ?>" /></p>

                <h2>SKU prefix</h2>
                <p><input type="text" name="sku_prefix" class="regular-text" value="<?php echo esc_attr($sku_prefix); ?>" /></p>

                <h2>Elsődleges beállítások</h2>
                <p><label><input type="checkbox" name="is_primary" value="1" <?php checked($is_primary); ?> /> Ez legyen az alapértelmezett terméktípus</label></p>
                <p>
                    <label>Elsődleges szín<br>
                        <select name="primary_color" style="min-width:220px">
                            <option value="">— Válassz színt —</option>
                            <?php foreach ($colors as $c): if (!isset($c['slug'], $c['name'])) continue; ?>
                                <option value="<?php echo esc_attr($c['slug']); ?>" <?php selected($primary_color, $c['slug']); ?>><?php echo esc_html($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="description" style="display:block;margin-top:4px;">Csak az aktuális terméktípus színei választhatók. A kiválasztott páros jelenik meg alapértelmezettként a bulk feltöltésben.</span>
                </p>
                <p>
                    <label>Elsődleges méret<br>
                        <select name="primary_size" style="min-width:220px">
                            <option value="">— Válassz méretet —</option>
                            <?php foreach ($sizes as $size_option): if (!is_string($size_option) || $size_option === '') continue; ?>
                                <option value="<?php echo esc_attr($size_option); ?>" <?php selected($primary_size, $size_option); ?>><?php echo esc_html($size_option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="description" style="display:block;margin-top:4px;">A kiválasztott méret jelenik meg alapértelmezésként a WooCommerce termékvariációknál.</span>
                </p>

                <h2>Méretek</h2>
                <p><input type="text" name="sizes" class="regular-text" value="<?php echo esc_attr(implode(',', $sizes)); ?>" /></p>

                
<h2>Méret felárak</h2>
<p class="description">Pozitív vagy negatív érték (HUF). Végső variáns ár = Alap ár + Méret felár.</p>
<table class="widefat striped">
    <thead><tr><th>Méret</th><th>Felár (HUF)</th></tr></thead>
    <tbody>
    <?php
    $sizes_list = is_array($sizes) ? $sizes : array();
    $saved_ss = isset($prod['size_surcharges']) && is_array($prod['size_surcharges']) ? $prod['size_surcharges'] : array();
    foreach ($sizes_list as $s):
        $val = isset($saved_ss[$s]) ? intval($saved_ss[$s]) : 0;
    ?>
        <tr>
            <td><code><?php echo esc_html($s); ?></code></td>
            <td><input type="number" name="size_surcharges[<?php echo esc_attr($s); ?>]" class="small-text" step="1" value="<?php echo esc_attr($val); ?>" /></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<h2>Színek</h2>

                <p><textarea name="colors" rows="6" class="large-text code"><?php echo esc_textarea($colors_text); ?></textarea></p>

                <h2>Nézetek (views)</h2>
                <p><textarea id="mg-views-json" name="views" rows="12" class="large-text code"><?php echo esc_textarea(json_encode($views, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea></p>

                <h2>Template alap mappa</h2>
                <p><input type="text" name="template_base" class="regular-text" value="<?php echo esc_attr($template_base); ?>" /></p>

                
<h2>Termék leírás</h2>
<p class="description">Ez kerül a WooCommerce termék hosszú leírásába; a rövid leírást automatikusan egy kivonatból generáljuk.</p>
<?php
$curr_desc = isset($prod['type_description']) ? $prod['type_description'] : '';
if (function_exists('wp_editor')) {
    wp_editor(
        $curr_desc,
        'mg_type_description',
        array(
            'textarea_name' => 'type_description',
            'textarea_rows' => 8,
            'media_buttons' => false,
            'teeny' => true,
            'tinymce' => true,
            'quicktags' => true,
        )
    );
} else {
    echo '<textarea name="type_description" rows="8" class="large-text">'.esc_textarea($curr_desc).'</textarea>';
}
?>
<h2>Mockup feltöltés
 (szín × nézet)</h2>
                <p class="description">Feltöltés után katt a <em>Print area jelölése</em> gombra: megnyílik egy jelölőréteg, ahol húzással/átméretezéssel állítod a nyomtatási területet. Az eredmény a fenti „Nézetek (views)” JSON-ba íródik (x,y,w,h).</p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Szín</th>
                            <?php foreach ($views as $v): ?>
                                <th style="vertical-align:top">
                                    <?php echo esc_html($v['label']); ?><br><code><?php echo esc_html($v['file']); ?></code><br>
                                    <button type="button"
                                        class="button button-secondary mg-open-printarea"
                                        data-viewkey="<?php echo esc_attr($v['key']); ?>"
                                        data-viewfile="<?php echo esc_attr($v['file']); ?>"
                                        data-productkey="<?php echo esc_attr($prod['key']); ?>"
                                        data-images='<?php
                                            $map = array();
                                            foreach ($colors as $c) {
                                                $slug = $c['slug'];
                                                $path = isset($over[$slug][$v['file']]) ? $over[$slug][$v['file']] : '';
                                                if ($path && strpos($path, $uploads_base) === 0) {
                                                    $rel = substr($path, strlen($uploads_base));
                                                    $url = $uploads_url . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
                                                    $map[$slug] = $url;
                                                }
                                            }
                                            echo esc_attr(json_encode($map));
                                        ?>'>Print area jelölése</button>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($colors as $c): $slug = $c['slug']; ?>
                        <tr>
                            <td><strong><?php echo esc_html($c['name']); ?></strong><br><code><?php echo esc_html($slug); ?></code></td>
                            <?php foreach ($views as $v):
                                $file_key = $v['file'];
                                $existing = isset($over[$slug][$file_key]) ? $over[$slug][$file_key] : '';
                                $display = '';
                                if ($existing && strpos($existing, $uploads_base) === 0) {
                                    $rel = substr($existing, strlen($uploads_base));
                                    $display = $uploads_url . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
                                }
                            ?>
                                <td>
                                    <?php if ($existing): ?>
                                        <div>Jelenlegi: <code><?php echo esc_html(basename($existing)); ?></code></div>
                                        <?php if ($display): ?>
                                            <div><a href="<?php echo esc_url($display); ?>" target="_blank">Megnyitás</a></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <input type="file" name="mockup_files[<?php echo esc_attr($slug); ?>][<?php echo esc_attr($file_key); ?>]" accept=".png,.jpg,.jpeg,.webp" />
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button('Mentés'); ?>
            </form>
        </div>

        <!-- Modal UI for print area -->
        <div id="mg-printarea-modal" class="mg-pa-hidden">
          <div class="mg-pa-backdrop"></div>
          <div class="mg-pa-dialog">
            <div class="mg-pa-header">
              <strong>Print area kijelölése</strong>
              <button type="button" class="button-link mg-pa-close">×</button>
            </div>
            <div class="mg-pa-toolbar">
              <label>Szín:
                <select id="mg-pa-color"></select>
              </label>
              <span class="mg-pa-hint">Húzd a keretet, vagy a sarkain fogd meg a méretezéshez. Mentés: „Alkalmaz”.</span>
            </div>
            <div class="mg-pa-canvas-wrap">
              <img id="mg-pa-image" src="" alt="" />
              <div id="mg-pa-rect">
                <div class="mg-pa-handle tl"></div>
                <div class="mg-pa-handle tr"></div>
                <div class="mg-pa-handle bl"></div>
                <div class="mg-pa-handle br"></div>
              </div>
            </div>
            <div class="mg-pa-footer">
              <button type="button" class="button button-secondary mg-pa-cancel">Mégse</button>
              <button type="button" class="button button-primary mg-pa-apply">Alkalmaz</button>
            </div>
          </div>
        </div>

        <script>
        window.MG_PA_DEFAULTS = <?php echo json_encode($views, JSON_UNESCAPED_UNICODE); ?>;
        </script>
        <?php
    }
}
