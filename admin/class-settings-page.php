<?php
if (!defined('ABSPATH')) exit;
class MG_Settings_Page {
    /**
     * Stores the last quick add product feedback for reuse across renders.
     *
     * @var array|null
     */
    private static $add_product_result = null;

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

        if (isset($_POST['mg_description_variables_nonce']) && wp_verify_nonce($_POST['mg_description_variables_nonce'], 'mg_description_variables_save')) {
            $input = isset($_POST['mg_description_variables_input']) ? wp_unslash($_POST['mg_description_variables_input']) : '';
            if (function_exists('mgtd__parse_description_variables_input')) {
                $variables = mgtd__parse_description_variables_input($input);
            } else {
                $variables = array();
            }
            update_option('mg_description_variables', $variables);
            echo '<div class="notice notice-success is-dismissible"><p>Leírás változók elmentve.</p></div>';
        }

        if (isset($_POST['mg_delivery_estimate_nonce']) && wp_verify_nonce($_POST['mg_delivery_estimate_nonce'], 'mg_delivery_estimate_save')) {
            $input = isset($_POST['mg_delivery_estimate']) && is_array($_POST['mg_delivery_estimate']) ? wp_unslash($_POST['mg_delivery_estimate']) : array();
            $enabled = !empty($input['enabled']);
            $normal_days = max(0, intval($input['normal_days'] ?? 0));
            $express_days = max(0, intval($input['express_days'] ?? 0));
            $normal_label = sanitize_text_field($input['normal_label'] ?? '');
            $express_label = sanitize_text_field($input['express_label'] ?? '');
            $cheapest_label = sanitize_text_field($input['cheapest_label'] ?? '');
            $cheapest_text = sanitize_text_field($input['cheapest_text'] ?? '');
            $icon_id = max(0, intval($input['icon_id'] ?? 0));
            $icon_url = '';
            if ($icon_id > 0) {
                $mime_type = get_post_mime_type($icon_id);
                if ($mime_type === 'image/png') {
                    $icon_url = wp_get_attachment_url($icon_id);
                } else {
                    $icon_id = 0;
                }
            }
            $holidays_raw = $input['holidays'] ?? '';
            $holiday_lines = preg_split('/\r\n|\r|\n/', (string)$holidays_raw);
            $holidays = array();
            foreach ($holiday_lines as $line) {
                $normalized = class_exists('MG_Delivery_Estimate') ? MG_Delivery_Estimate::normalize_holiday_line($line) : '';
                if ($normalized !== '' && !in_array($normalized, $holidays, true)) {
                    $holidays[] = $normalized;
                }
            }
            $cutoff_time = sanitize_text_field($input['cutoff_time'] ?? '');
            if ($cutoff_time !== '' && !preg_match('/^\d{2}:\d{2}$/', $cutoff_time)) {
                $cutoff_time = '';
            }
            $cutoff_extra_days = max(0, intval($input['cutoff_extra_days'] ?? 0));
            update_option('mg_delivery_estimate', array(
                'enabled' => $enabled,
                'normal_days' => $normal_days,
                'express_days' => $express_days,
                'normal_label' => $normal_label,
                'express_label' => $express_label,
                'cheapest_label' => $cheapest_label,
                'cheapest_text' => $cheapest_text,
                'icon_id' => $icon_id,
                'icon_url' => $icon_url,
                'holidays' => $holidays,
                'cutoff_time' => $cutoff_time,
                'cutoff_extra_days' => $cutoff_extra_days,
            ));
            echo '<div class="notice notice-success is-dismissible"><p>Várható érkezés csempe beállítások elmentve.</p></div>';
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
        $description_variables = function_exists('mgtd__get_description_variables') ? mgtd__get_description_variables() : array();
        $description_variables_lines = array();
        foreach ($description_variables as $slug => $text) {
            $description_variables_lines[] = $slug . ' | ' . $text;
        }
        $description_variables_text = implode("\n", $description_variables_lines);
        $delivery_settings = class_exists('MG_Delivery_Estimate') ? MG_Delivery_Estimate::get_settings() : array(
            'enabled' => true,
            'normal_days' => 3,
            'express_days' => 1,
            'normal_label' => 'Normál kézbesítés várható:',
            'express_label' => 'SOS kézbesítés:',
            'cheapest_label' => 'Legolcsóbb szállítás:',
            'cheapest_text' => '',
            'icon_id' => 0,
            'icon_url' => '',
            'holidays' => array(),
            'cutoff_time' => '',
            'cutoff_extra_days' => 1,
        );
        $delivery_icon_url = $delivery_settings['icon_url'] ?? '';
        $delivery_icon_id = intval($delivery_settings['icon_id'] ?? 0);
        if ($delivery_icon_id > 0 && function_exists('wp_get_attachment_url')) {
            $icon_url = wp_get_attachment_url($delivery_icon_id);
            if ($icon_url) {
                $delivery_icon_url = $icon_url;
            }
        }
        $delivery_holidays_text = '';
        if (!empty($delivery_settings['holidays']) && is_array($delivery_settings['holidays'])) {
            $delivery_holidays_text = implode("\n", $delivery_settings['holidays']);
        }
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
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
                        <th scope="row">Csak vélemények fül</th>
                        <td>
                            <label><input type="checkbox" name="mg_design_gallery[reviews_only]" value="1" <?php checked(!empty($gallery_settings['reviews_only'])); ?> /> Csak a vélemények tab megjelenítése</label>
                            <p class="description">Eltávolítja a Leírás és További információk tabokat a termékoldalról.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Max. elemek száma</th>
                        <td>
                            <input type="number" name="mg_design_gallery[max_items]" min="0" step="1" value="<?php echo esc_attr(intval($gallery_settings['max_items'] ?? 0)); ?>" class="small-text" />
                            <p class="description">0 = az összes elérhető terméktípus megjelenítése</p>
                        </td>
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

            <h2>Várható érkezés csempe</h2>
            <p>Ez a csempe a kosár gomb alatt jelenik meg a termékoldalon, és kiszámolja a várható érkezési dátumot munka- és szállítási napok alapján.</p>
            <form method="post">
                <?php wp_nonce_field('mg_delivery_estimate_save', 'mg_delivery_estimate_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Megjelenítés</th>
                        <td><label><input type="checkbox" name="mg_delivery_estimate[enabled]" value="1" <?php checked(!empty($delivery_settings['enabled'])); ?> /> Engedélyezve</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Normál szállítás (munkanap)</th>
                        <td><input type="number" name="mg_delivery_estimate[normal_days]" min="0" step="1" value="<?php echo esc_attr(intval($delivery_settings['normal_days'] ?? 0)); ?>" class="small-text" /> nap</td>
                    </tr>
                    <tr>
                        <th scope="row">SOS szállítás (munkanap)</th>
                        <td><input type="number" name="mg_delivery_estimate[express_days]" min="0" step="1" value="<?php echo esc_attr(intval($delivery_settings['express_days'] ?? 0)); ?>" class="small-text" /> nap</td>
                    </tr>
                    <tr>
                        <th scope="row">Normál címke</th>
                        <td><input type="text" name="mg_delivery_estimate[normal_label]" value="<?php echo esc_attr($delivery_settings['normal_label'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">SOS címke</th>
                        <td><input type="text" name="mg_delivery_estimate[express_label]" value="<?php echo esc_attr($delivery_settings['express_label'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Legolcsóbb címke</th>
                        <td><input type="text" name="mg_delivery_estimate[cheapest_label]" value="<?php echo esc_attr($delivery_settings['cheapest_label'] ?? ''); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Legolcsóbb szöveg</th>
                        <td>
                            <input type="text" name="mg_delivery_estimate[cheapest_text]" value="<?php echo esc_attr($delivery_settings['cheapest_text'] ?? ''); ?>" class="regular-text" />
                            <p class="description">Ez a szöveg jelenik meg a legolcsóbb sor jobb oldalán (nem jelenik meg dátum).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cutoff idő</th>
                        <td>
                            <input type="time" name="mg_delivery_estimate[cutoff_time]" value="<?php echo esc_attr($delivery_settings['cutoff_time'] ?? ''); ?>" />
                            <input type="number" name="mg_delivery_estimate[cutoff_extra_days]" min="0" step="1" value="<?php echo esc_attr(intval($delivery_settings['cutoff_extra_days'] ?? 0)); ?>" class="small-text" /> nap extra
                            <p class="description">Ha a rendelés ez után érkezik be egy munkanapon, ennyi plusz munkanap kerül hozzá a számításhoz.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">PNG ikon feltöltés</th>
                        <td>
                            <input type="hidden" name="mg_delivery_estimate[icon_id]" id="mg-delivery-icon-id" value="<?php echo esc_attr($delivery_icon_id); ?>" />
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="button" id="mg-delivery-icon-upload">Kép kiválasztása</button>
                                <button type="button" class="button" id="mg-delivery-icon-remove">Eltávolítás</button>
                                <input type="text" id="mg-delivery-icon-url" class="regular-text" value="<?php echo esc_attr($delivery_icon_url); ?>" readonly />
                            </div>
                            <div id="mg-delivery-icon-preview" style="margin-top:10px;">
                                <?php if (!empty($delivery_icon_url)) : ?>
                                    <img src="<?php echo esc_url($delivery_icon_url); ?>" alt="" style="max-width:120px;height:auto;" />
                                <?php endif; ?>
                            </div>
                            <p class="description">Csak PNG képet válassz. A kép a csempe jobb oldalán jelenik meg.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Munkaszüneti napok</th>
                        <td>
                            <textarea name="mg_delivery_estimate[holidays]" rows="6" class="large-text code" placeholder="2024-12-25"><?php echo esc_textarea($delivery_holidays_text); ?></textarea>
                            <p class="description">Adj meg egy dátumot soronként (YYYY-MM-DD). Hétvégék automatikusan kiesnek.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Mentés'); ?>
            </form>
            <script>
                jQuery(function($){
                    var frame;
                    function setDeliveryIcon(id, url) {
                        $('#mg-delivery-icon-id').val(id || '');
                        $('#mg-delivery-icon-url').val(url || '');
                        $('#mg-delivery-icon-preview').html(url ? '<img src="' + url + '" alt="" style="max-width:120px;height:auto;" />' : '');
                    }
                    $('#mg-delivery-icon-upload').on('click', function(e){
                        e.preventDefault();
                        if (frame) {
                            frame.open();
                            return;
                        }
                        frame = wp.media({
                            title: 'PNG ikon kiválasztása',
                            button: { text: 'Kiválasztás' },
                            library: { type: 'image' },
                            multiple: false
                        });
                        frame.on('select', function(){
                            var attachment = frame.state().get('selection').first().toJSON();
                            if (attachment && attachment.mime && attachment.mime !== 'image/png') {
                                alert('Kérlek PNG képet válassz!');
                                return;
                            }
                            setDeliveryIcon(attachment.id, attachment.url);
                        });
                        frame.open();
                    });
                    $('#mg-delivery-icon-remove').on('click', function(e){
                        e.preventDefault();
                        setDeliveryIcon('', '');
                    });
                });
            </script>

            <h2>Leírás változók</h2>
            <p class="description">Adj meg újrahasznosítható szövegeket, amelyeket a termék leírásába a <code>{seo:slug}</code> formában illeszthetsz be.</p>
            <p class="description">Formátum: <code>slug | ide kerül a leírás</code> (soronként egy változó).</p>
            <form method="post">
                <?php wp_nonce_field('mg_description_variables_save', 'mg_description_variables_nonce'); ?>
                <textarea name="mg_description_variables_input" rows="6" class="large-text"><?php echo esc_textarea($description_variables_text); ?></textarea>
                <?php submit_button('Leírás változók mentése'); ?>
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
            <form method="post" class="mg-product-add-form">
                <?php echo self::render_add_product_form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php submit_button('Hozzáadás'); ?>
            </form>
        </div>
        <?php
    }
    /**
     * Handles the creation of a new product from a quick add submission.
     *
     * @return array{success:bool,message:string}|null
     */
    public static function maybe_handle_add_product_submission() {
        static $processed = false;

        if ($processed) {
            return self::$add_product_result;
        }

        $processed = true;

        $nonce_valid = false;
        if (isset($_POST['mg_add_product_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_add_product_nonce'])), 'mg_add_product')) {
            $nonce_valid = true;
        }

        if (isset($_POST['mg_quick_add_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_quick_add_nonce'])), 'mg_quick_add_product')) {
            $nonce_valid = true;
        }

        if (!$nonce_valid) {
            return null;
        }

        if (!current_user_can('manage_options')) {
            self::$add_product_result = array(
                'success' => false,
                'message' => __('Nincs jogosultságod új termék hozzáadásához.', 'mockup-generator'),
            );

            return self::$add_product_result;
        }

        self::$add_product_result = self::create_product_from_request($_POST);

        return self::$add_product_result;
    }

    /**
     * Creates a new product entry from request data.
     *
     * @param array $data Raw request array.
     * @return array{success:bool,message:string}
     */
    private static function create_product_from_request($data) {
        $products = get_option('mg_products', array());

        $key = sanitize_title($data['product_key'] ?? '');
        $label = sanitize_text_field($data['product_label'] ?? '');
        $price = intval($data['product_price'] ?? 0);
        $sku_prefix = strtoupper(sanitize_text_field($data['sku_prefix'] ?? 'SKU'));

        if (!$key || !$label) {
            return array(
                'success' => false,
                'message' => __('A kulcs és a megjelenített név megadása kötelező.', 'mockup-generator'),
            );
        }

        foreach ($products as $p) {
            if (!empty($p['key']) && $p['key'] === $key) {
                $key .= '-' . wp_generate_password(4, false, false);
                break;
            }
        }

        $products[] = array(
            'key'            => $key,
            'label'          => $label,
            'sizes'          => array('S', 'M', 'L', 'XL'),
            'colors'         => array(
                array('name' => 'Fekete', 'slug' => 'fekete'),
                array('name' => 'Fehér', 'slug' => 'feher'),
                array('name' => 'Szürke', 'slug' => 'szurke'),
            ),
            'views'          => array(
                array('key' => 'front', 'label' => 'Előlap', 'file' => $key . '_front.png', 'x' => 420, 'y' => 600, 'w' => 1200, 'h' => 900),
            ),
            'template_base'  => "templates/$key",
            'mockup_overrides' => array(),
            'price'          => $price,
            'size_surcharges' => array(),
            'color_surcharges' => array(),
            'sku_prefix'     => $sku_prefix,
            'categories'     => array(),
            'tags'           => array(),
        );

        update_option('mg_products', $products);

        return array(
            'success' => true,
            'message' => sprintf(__('Termék hozzáadva: %s', 'mockup-generator'), $label),
        );
    }

    /**
     * Returns the shared quick add product form fields.
     *
     * @return string
     */
    public static function render_add_product_form_fields() {
        ob_start();
        wp_nonce_field('mg_add_product', 'mg_add_product_nonce');
        wp_nonce_field('mg_quick_add_product', 'mg_quick_add_nonce');
        ?>
        <input type="hidden" name="mg_tab" value="settings" />
        <table class="form-table">
            <tr><th>Kulcs (slug)</th><td><input type="text" name="product_key" placeholder="pl. polo" required /></td></tr>
            <tr><th>Megjelenített név</th><td><input type="text" name="product_label" placeholder="pl. Póló" required /></td></tr>
            <tr><th>Alap ár (HUF)</th><td><input type="number" name="product_price" class="small-text" min="0" step="1" value="0" /></td></tr>
            <tr><th>SKU prefix</th><td><input type="text" name="sku_prefix" class="regular-text" placeholder="pl. POLO" /></td></tr>
        </table>
        <?php
        return (string) ob_get_clean();
    }
}
