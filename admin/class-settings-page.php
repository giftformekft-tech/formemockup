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
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'products';
        
        // Handle saves before rendering to show notices at top
        self::handle_saves($active_tab);

        ?>
        <div class="wrap">
            <h1>Mockup Generator – Beállítások</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=mockup-generator-settings&tab=products'); ?>" class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">📦 Termékek</a>
                <a href="<?php echo admin_url('admin.php?page=mockup-generator-settings&tab=images'); ?>" class="nav-tab <?php echo $active_tab === 'images' ? 'nav-tab-active' : ''; ?>">🖼️ Képoptimalizálás</a>
                <a href="<?php echo admin_url('admin.php?page=mockup-generator-settings&tab=frontend'); ?>" class="nav-tab <?php echo $active_tab === 'frontend' ? 'nav-tab-active' : ''; ?>">🧩 Termékoldali elemek</a>
                <a href="<?php echo admin_url('admin.php?page=mockup-generator-settings&tab=feeds'); ?>" class="nav-tab <?php echo $active_tab === 'feeds' ? 'nav-tab-active' : ''; ?>">📢 Export & Feedek</a>
                <a href="<?php echo admin_url('admin.php?page=mockup-generator-settings&tab=emails'); ?>" class="nav-tab <?php echo $active_tab === 'emails' ? 'nav-tab-active' : ''; ?>">📧 E-mailek</a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'products':
                        self::render_products_tab();
                        break;
                    case 'images':
                        self::render_images_tab();
                        break;
                    case 'frontend':
                        self::render_frontend_tab();
                        break;
                    case 'feeds':
                        self::render_feeds_tab();
                        break;
                    case 'emails':
                        self::render_emails_tab();
                        break;
                    default:
                        self::render_products_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function handle_saves($tab) {
        // Products Tab Saves
        if ($tab === 'products') {
            if (isset($_POST['mg_add_product_nonce']) && wp_verify_nonce($_POST['mg_add_product_nonce'],'mg_add_product')) {
                // Logic moved to create_product_from_request generally, but here specifically for the main form
                // maybe_handle_add_product_submission() is called usually, checking if we need to call it explicitely or if it runs on init?
                // The original code called it inside render. Let's keep it simple.
                // Actually, the original code had add module logic inside render. 
                // We will rely on maybe_handle_add_product_submission() if it was hooked, but let's look at original.
                // Original: create_product_from_request was called.
                
                // Let's keep the logic from original render_settings
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
        }

        // Images Tab Saves
        if ($tab === 'images') {
             if (isset($_POST['mg_resize_nonce']) && wp_verify_nonce($_POST['mg_resize_nonce'],'mg_save_resize')) {
                $enabled = isset($_POST['resize_enabled']) ? (bool)$_POST['resize_enabled'] : false;
                $max_w = max(0, intval($_POST['resize_max_w'] ?? 0));
                $max_h = max(0, intval($_POST['resize_max_h'] ?? 0));
                $mode  = in_array(($_POST['resize_mode'] ?? 'fit'), array('fit','width','height'), true) ? $_POST['resize_mode'] : 'fit';
                $filter = isset($_POST['resize_filter']) ? sanitize_text_field($_POST['resize_filter']) : 'lanczos';
                $filter = in_array($filter, array('lanczos', 'triangle', 'catrom', 'mitchell'), true) ? $filter : 'lanczos';
                $resize_method = isset($_POST['resize_method']) ? sanitize_text_field($_POST['resize_method']) : 'resize';
                $resize_method = in_array($resize_method, array('resize', 'thumbnail'), true) ? $resize_method : 'resize';
    
                $quality = isset($_POST['webp_quality']) ? max(0, min(100, intval($_POST['webp_quality']))) : 78;
                $alpha_quality = isset($_POST['webp_alpha']) ? max(0, min(100, intval($_POST['webp_alpha']))) : 92;
                $method = isset($_POST['webp_method']) ? max(0, min(6, intval($_POST['webp_method']))) : 3;
                $thread_limit = isset($_POST['imagick_thread_limit']) ? max(0, intval($_POST['imagick_thread_limit'])) : 0;
    
                update_option('mg_output_resize', array(
                    'enabled' => $enabled,
                    'max_w'   => $max_w,
                    'max_h'   => $max_h,
                    'mode'    => $mode,
                    'filter'  => $filter,
                    'method'  => $resize_method,
                ));
                update_option('mg_webp_options', array(
                    'quality' => $quality,
                    'alpha'   => $alpha_quality,
                    'method'  => $method,
                ));
                update_option('mg_imagick_options', array(
                    'thread_limit' => $thread_limit,
                ));
                echo '<div class="notice notice-success is-dismissible"><p>Képoptimalizálás beállítások elmentve.</p></div>';
            }
        }

        // Frontend Tab Saves
        if ($tab === 'frontend') {
            // Gallery
            if (class_exists('MG_Design_Gallery') && isset($_POST['mg_design_gallery_nonce']) && wp_verify_nonce($_POST['mg_design_gallery_nonce'], 'mg_design_gallery_save')) {
                $input = isset($_POST['mg_design_gallery']) ? wp_unslash($_POST['mg_design_gallery']) : array();
                MG_Design_Gallery::sanitize_settings($input);
                echo '<div class="notice notice-success is-dismissible"><p>Mintagalléria beállítások elmentve.</p></div>';
            }
            
            // Description Variables
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

            // Delivery Estimate
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
                $payment_image_id = max(0, intval($input['payment_image_id'] ?? 0));
                $payment_image_url = '';
                if ($payment_image_id > 0) {
                    $pay_url = wp_get_attachment_url($payment_image_id);
                    if ($pay_url) {
                        $payment_image_url = $pay_url;
                    } else {
                        $payment_image_id = 0;
                    }
                }
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
                    'mode' => sanitize_text_field($input['mode'] ?? 'automatic'),
                    'manual_title' => sanitize_text_field($input['manual_title'] ?? ''),
                    'manual_list' => sanitize_textarea_field($input['manual_list'] ?? ''),
                    'payment_image_id' => $payment_image_id,
                    'payment_image_url' => $payment_image_url,
                ));
                echo '<div class="notice notice-success is-dismissible"><p>Várható érkezés csempe beállítások elmentve.</p></div>';
            }
        }

        // Emails Tab Saves
        if ($tab === 'emails') {
            if (isset($_POST['mg_emails_nonce']) && wp_verify_nonce($_POST['mg_emails_nonce'], 'mg_save_emails')) {
                $terms_url = isset($_POST['mg_terms_pdf_url']) ? esc_url_raw($_POST['mg_terms_pdf_url']) : '';
                $withdrawal_url = isset($_POST['mg_withdrawal_pdf_url']) ? esc_url_raw($_POST['mg_withdrawal_pdf_url']) : '';

                update_option('mg_terms_pdf_url', $terms_url);
                update_option('mg_withdrawal_pdf_url', $withdrawal_url);
                echo '<div class="notice notice-success is-dismissible"><p>E-mail beállítások elmentve.</p></div>';
            }
        }
    }

    private static function render_products_tab() {
        $products = get_option('mg_products', array());
        ?>
        <h2>Termékek listája</h2>
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

        <br>
        <hr>

        <h2>Új termék hozzáadása</h2>
        <form method="post" class="mg-product-add-form">
            <?php echo self::render_add_product_form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php submit_button('Hozzáadás'); ?>
        </form>
        <?php
    }

    private static function render_images_tab() {
        $resize = get_option('mg_output_resize', array('enabled'=>false,'max_w'=>0,'max_h'=>0,'mode'=>'fit','filter'=>'lanczos','method'=>'resize'));
        $r_enabled = !empty($resize['enabled']);
        $r_w = intval($resize['max_w'] ?? 0);
        $r_h = intval($resize['max_h'] ?? 0);
        $r_mode = $resize['mode'] ?? 'fit';
        $r_filter = $resize['filter'] ?? 'lanczos';
        $r_method = $resize['method'] ?? 'resize';
        $webp_defaults = array('quality'=>78,'alpha'=>92,'method'=>3);
        $webp = get_option('mg_webp_options', $webp_defaults);
        $w_quality = max(0, min(100, intval($webp['quality'] ?? $webp_defaults['quality'])));
        $w_alpha = max(0, min(100, intval($webp['alpha'] ?? $webp_defaults['alpha'])));
        $w_method = max(0, min(6, intval($webp['method'] ?? $webp_defaults['method'])));
        $imagick_options = get_option('mg_imagick_options', array('thread_limit' => 0));
        $imagick_thread_limit = max(0, intval($imagick_options['thread_limit'] ?? 0));
        ?>
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
                    <th scope="row">Átméretezési filter</th>
                    <td>
                        <select name="resize_filter">
                            <option value="lanczos" <?php selected($r_filter,'lanczos'); ?>>LANCZOS</option>
                            <option value="triangle" <?php selected($r_filter,'triangle'); ?>>TRIANGLE</option>
                            <option value="catrom" <?php selected($r_filter,'catrom'); ?>>CATROM</option>
                            <option value="mitchell" <?php selected($r_filter,'mitchell'); ?>>MITCHEL</option>
                        </select>
                        <p class="description">A filter hatással van a lekicsinyítés minőségére és sebességére.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Átméretezési metódus</th>
                    <td>
                        <select name="resize_method">
                            <option value="resize" <?php selected($r_method,'resize'); ?>>resizeImage</option>
                            <option value="thumbnail" <?php selected($r_method,'thumbnail'); ?>>thumbnailImage</option>
                        </select>
                        <p class="description">Válaszd ki, hogy resizeImage vagy thumbnailImage legyen használva.</p>
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
                <tr>
                    <th scope="row">Imagick szál limit</th>
                    <td>
                        <input type="number" name="imagick_thread_limit" min="0" step="1" value="<?php echo esc_attr($imagick_thread_limit); ?>" class="small-text" />
                        <p class="description">0 = automatikus (imagick.thread_limit vagy fallback 2). Pozitív érték esetén ezt használja a feldolgozás.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Képoptimalizálás mentése'); ?>
        </form>
        <?php
    }

    private static function render_frontend_tab() {
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
        $gallery_settings = class_exists('MG_Design_Gallery') ? MG_Design_Gallery::get_settings() : array('enabled' => false, 'position' => 'after_summary', 'max_items' => 6, 'layout' => 'grid', 'title' => '', 'show_title' => true);
        $position_choices = class_exists('MG_Design_Gallery') ? MG_Design_Gallery::get_position_choices() : array();
        $description_variables = function_exists('mgtd__get_description_variables') ? mgtd__get_description_variables() : array();
        $description_variables_lines = array();
        foreach ($description_variables as $slug => $text) {
            $description_variables_lines[] = $slug . ' | ' . $text;
        }
        $description_variables_text = implode("\n", $description_variables_lines);
        $delivery_settings = class_exists('MG_Delivery_Estimate') ? MG_Delivery_Estimate::get_settings() : array('enabled' => true, 'normal_days' => 3, 'express_days' => 1, 'normal_label' => '', 'express_label' => '', 'cheapest_label' => '', 'cheapest_text' => '', 'icon_id' => 0, 'icon_url' => '', 'holidays' => array(), 'cutoff_time' => '', 'cutoff_extra_days' => 1, 'mode' => 'automatic', 'manual_title' => '', 'manual_list' => '', 'payment_image_id' => 0, 'payment_image_url' => '');
        $delivery_icon_url = $delivery_settings['icon_url'] ?? '';
        $delivery_icon_id = intval($delivery_settings['icon_id'] ?? 0);
        if ($delivery_icon_id > 0 && function_exists('wp_get_attachment_url')) {
            $icon_url = wp_get_attachment_url($delivery_icon_id);
            if ($icon_url) {
                $delivery_icon_url = $icon_url;
            }
        }
        $delivery_payment_image_id = intval($delivery_settings['payment_image_id'] ?? 0);
        $delivery_payment_image_url = $delivery_settings['payment_image_url'] ?? '';
        if ($delivery_payment_image_id > 0 && function_exists('wp_get_attachment_url')) {
            $pay_url = wp_get_attachment_url($delivery_payment_image_id);
            if ($pay_url) {
                $delivery_payment_image_url = $pay_url;
            }
        }
        $delivery_holidays_text = '';
        if (!empty($delivery_settings['holidays']) && is_array($delivery_settings['holidays'])) {
            $delivery_holidays_text = implode("\n", $delivery_settings['holidays']);
        }
        ?>
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
                    <th scope="row">Működési mód</th>
                    <td>
                        <select name="mg_delivery_estimate[mode]" id="mg_delivery_mode">
                            <option value="automatic" <?php selected(($delivery_settings['mode'] ?? 'automatic'), 'automatic'); ?>>Automatikus dátum kalkuláció</option>
                            <option value="manual" <?php selected(($delivery_settings['mode'] ?? 'automatic'), 'manual'); ?>>Manuális lista</option>
                        </select>
                    </td>
                </tr>
                <tbody id="mg_delivery_automatic_settings">
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
                    <td>
                        <input type="time" name="mg_delivery_estimate[cutoff_time]" value="<?php echo esc_attr($delivery_settings['cutoff_time'] ?? ''); ?>" />
                        <input type="number" name="mg_delivery_estimate[cutoff_extra_days]" min="0" step="1" value="<?php echo esc_attr(intval($delivery_settings['cutoff_extra_days'] ?? 0)); ?>" class="small-text" /> nap extra
                        <p class="description">Ha a rendelés ez után érkezik be egy munkanapon, ennyi plusz munkanap kerül hozzá a számításhoz.</p>
                    </td>
                </tr>
                </tbody>
                <tbody id="mg_delivery_manual_settings" style="display:none;">
                <tr>
                    <th scope="row">Cím (Header)</th>
                    <td>
                        <input type="text" name="mg_delivery_estimate[manual_title]" value="<?php echo esc_attr($delivery_settings['manual_title'] ?? ''); ?>" class="large-text" placeholder="Várható szállítási idő 2-6 nap" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Szállítási módok listája</th>
                    <td>
                        <textarea name="mg_delivery_estimate[manual_list]" rows="6" class="large-text" placeholder="GLS Házhozszállítás | 1990 Ft&#10;Foxpost Csomagautomata | 1290 Ft"><?php echo esc_textarea($delivery_settings['manual_list'] ?? ''); ?></textarea>
                        <p class="description">Soronként egy mód. Formátum: <code>Név | Ár</code></p>
                    </td>
                </tr>
                </tbody>
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
                    <th scope="row">Fizetési opciók kép</th>
                    <td>
                        <input type="hidden" name="mg_delivery_estimate[payment_image_id]" id="mg-payment-image-id" value="<?php echo esc_attr($delivery_payment_image_id); ?>" />
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <button type="button" class="button" id="mg-payment-image-upload">Kép kiválasztása</button>
                            <button type="button" class="button" id="mg-payment-image-remove">Eltávolítás</button>
                            <input type="text" id="mg-payment-image-url" class="regular-text" value="<?php echo esc_attr($delivery_payment_image_url); ?>" readonly />
                        </div>
                        <div id="mg-payment-image-preview" style="margin-top:10px;">
                            <?php if (!empty($delivery_payment_image_url)) : ?>
                                <img src="<?php echo esc_url($delivery_payment_image_url); ?>" alt="" style="max-height:60px;width:auto;" />
                            <?php endif; ?>
                        </div>
                        <p class="description">A várható érkezés csempe alatt megjelenő fizetési opciók kép (pl. kártya, PayPal ikonok).</p>
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
            <?php submit_button('Várható érkezés mentése'); ?>
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

                var payFrame;
                function setPaymentImage(id, url) {
                    $('#mg-payment-image-id').val(id || '');
                    $('#mg-payment-image-url').val(url || '');
                    $('#mg-payment-image-preview').html(url ? '<img src="' + url + '" alt="" style="max-height:60px;width:auto;" />' : '');
                }
                $('#mg-payment-image-upload').on('click', function(e){
                    e.preventDefault();
                    if (payFrame) {
                        payFrame.open();
                        return;
                    }
                    payFrame = wp.media({
                        title: 'Fizetési opciók kép kiválasztása',
                        button: { text: 'Kiválasztás' },
                        library: { type: 'image' },
                        multiple: false
                    });
                    payFrame.on('select', function(){
                        var attachment = payFrame.state().get('selection').first().toJSON();
                        setPaymentImage(attachment.id, attachment.url);
                    });
                    payFrame.open();
                });
                $('#mg-payment-image-remove').on('click', function(e){
                    e.preventDefault();
                    setPaymentImage('', '');
                });

                function toggleDeliveryMode() {
                    var mode = $('#mg_delivery_mode').val();
                    if (mode === 'manual') {
                        $('#mg_delivery_automatic_settings').hide();
                        $('#mg_delivery_manual_settings').show();
                    } else {
                        $('#mg_delivery_automatic_settings').show();
                        $('#mg_delivery_manual_settings').hide();
                    }
                }
                $('#mg_delivery_mode').on('change', toggleDeliveryMode);
                toggleDeliveryMode();
            });
        </script>
        
        <hr/>
        
        <h2>Leírás változók</h2>
        <p class="description">Adj meg újrahasznosítható szövegeket, amelyeket a termék leírásába a <code>{seo:slug}</code> formában illeszthetsz be.</p>
        <p class="description">Formátum: <code>slug | ide kerül a leírás</code> (soronként egy változó).</p>
        <form method="post">
            <?php wp_nonce_field('mg_description_variables_save', 'mg_description_variables_nonce'); ?>
            <textarea name="mg_description_variables_input" rows="6" class="large-text"><?php echo esc_textarea($description_variables_text); ?></textarea>
            <?php submit_button('Leírás változók mentése'); ?>
        </form>
        <?php
    }

    private static function render_feeds_tab() {
        ?>
        <h2>Google Merchant Feed</h2>
        <?php
        $google_last_update = get_option('mg_google_feed_last_update', 0);
        $google_feed_url = class_exists('MG_Google_Merchant_Feed') ? MG_Google_Merchant_Feed::get_feed_url() : '';
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Feed URL</th>
                <td>
                    <code><a href="<?php echo esc_url($google_feed_url); ?>" target="_blank"><?php echo esc_html($google_feed_url); ?></a></code>
                    <p class="description">Másold be ezt a linket a Google Merchant Centerbe.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Utolsó frissítés</th>
                <td>
                    <?php echo $google_last_update ? date_i18n('Y-m-d H:i:s', $google_last_update) : 'Még nem volt frissítve'; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Művelet</th>
                <td>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="mg_regenerate_feed">
                        <?php wp_nonce_field('mg_regenerate_feed'); ?>
                        <?php submit_button('Feed újragenerálása most', 'secondary', 'submit', false); ?>
                    </form>
                    <p class="description">A feed 24 óránként automatikusan frissül, ha megnyitják az URL-t, de itt manuálisan is kikényszerítheted a frissítést.</p>
                </td>
            </tr>
        </table>

        <hr/>

        <h2>Facebook Catalog Feed</h2>
        <?php
        $fb_last_update = get_option('mg_facebook_feed_last_update', 0);
        $fb_feed_url = class_exists('MG_Facebook_Catalog_Feed') ? MG_Facebook_Catalog_Feed::get_feed_url() : '';
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Feed URL</th>
                <td>
                    <code><a href="<?php echo esc_url($fb_feed_url); ?>" target="_blank"><?php echo esc_html($fb_feed_url); ?></a></code>
                    <p class="description">Másold be ezt a linket a Facebook Commerce Managerbe (Catalog > Data Sources > Data Feed).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Utolsó frissítés</th>
                <td>
                    <?php echo $fb_last_update ? date_i18n('Y-m-d H:i:s', $fb_last_update) : 'Még nem volt frissítve'; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Művelet</th>
                <td>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="mg_regenerate_facebook_feed">
                        <?php wp_nonce_field('mg_regenerate_facebook_feed'); ?>
                        <?php submit_button('Facebook Feed újragenerálása most', 'secondary', 'submit', false); ?>
                    </form>
                    <p class="description">A feed 24 óránként automatikusan frissül, ha megnyitják az URL-t.</p>
                </td>
            </tr>
        </table>
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
    public static function render_emails_tab() {
        $terms_url = get_option('mg_terms_pdf_url', '');
        $withdrawal_url = get_option('mg_withdrawal_pdf_url', '');
        ?>
        <h2>E-mail Lábléc Kiegészítések</h2>
        <p>A "Megrendelés Fizetés folyamatban" (On-hold) levél láblécében megjelenő PDF dokumentumok linkjei.</p>
        <form method="post">
            <?php wp_nonce_field('mg_save_emails', 'mg_emails_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">ÁSZF PDF URL</th>
                    <td>
                        <input type="url" name="mg_terms_pdf_url" value="<?php echo esc_attr($terms_url); ?>" class="large-text" placeholder="https://..." />
                        <p class="description">Link az Általános Szerződési Feltételek PDF-re.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Elállási Nyilatkozat PDF URL</th>
                    <td>
                        <input type="url" name="mg_withdrawal_pdf_url" value="<?php echo esc_attr($withdrawal_url); ?>" class="large-text" placeholder="https://..." />
                        <p class="description">Link a letölthető elállási nyilatkozat PDF-re.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('E-mail beállítások mentése'); ?>
        </form>
        <?php
    }
}
