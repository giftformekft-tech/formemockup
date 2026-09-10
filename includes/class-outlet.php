<?php
if (!defined('ABSPATH')) exit;

/** Finished goods: independent WooCommerce stock, never virtual/made-to-order items. */
class MG_Outlet {
    const META = '_mg_outlet';
    const PAGE_OPTION = 'mg_outlet_page_id';

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'ensure_page'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('add_meta_boxes_product', array(__CLASS__, 'meta_box'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
        add_action('wp_ajax_mg_create_outlet', array(__CLASS__, 'ajax_create'));
        add_action('pre_get_posts', array(__CLASS__, 'admin_filter'));
        add_shortcode('mg_outlet', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('template_redirect', array(__CLASS__, 'avoid_stale_stock'));
        add_action('woocommerce_before_product_object_save', array(__CLASS__, 'enforce_stock'));
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cart_data'), PHP_INT_MAX, 4);
        add_filter('woocommerce_get_cart_item_from_session', array(__CLASS__, 'restore_cart'), PHP_INT_MAX, 2);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'cart_prices'), PHP_INT_MAX);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'item_data'), 100, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_item'), 100, 4);
        add_action('woocommerce_new_order_item', array(__CLASS__, 'snapshot_admin_item'), 100, 3);
    }

    public static function is_outlet($product) {
        $id = is_object($product) ? $product->get_id() : absint($product);
        return $id && get_post_meta($id, self::META, true) === 'yes';
    }

    public static function is_outlet_item($item) {
        return $item->get_meta(self::META, true) === 'yes' || self::is_outlet($item->get_product_id());
    }

    public static function enforce_stock($product) {
        if ($product->get_meta(self::META, true) !== 'yes') return;
        $product->set_manage_stock(true);
        $product->set_backorders('no');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(false);
        $product->set_stock_status($product->get_stock_quantity() > 0 ? 'instock' : 'outofstock');
    }

    public static function ensure_page() {
        if (!current_user_can('manage_woocommerce') || get_option(self::PAGE_OPTION)) return;
        // Never overwrite an existing /outlet/ page. WordPress chooses a free slug.
        if (!add_option('mg_outlet_page_installing', time(), '', false)) {
            if ((int) get_option('mg_outlet_page_installing') < time() - 120) delete_option('mg_outlet_page_installing');
            return;
        }
        $existing = get_page_by_path('outlet');
        if ($existing && has_shortcode($existing->post_content, 'mg_outlet') && $existing->post_status === 'publish') {
            $id = $existing->ID;
        } else {
            $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Outlet',
                'post_name' => 'outlet', 'post_content' => '[mg_outlet]', 'comment_status' => 'closed'), true);
        }
        if (!is_wp_error($id) && $id) update_option(self::PAGE_OPTION, $id, false);
        delete_option('mg_outlet_page_installing');
    }

    public static function page_url() {
        $id = absint(get_option(self::PAGE_OPTION));
        return $id && get_post_status($id) === 'publish' ? get_permalink($id) : '';
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=product', 'Outlet', 'Outlet', 'edit_products', 'mg-outlet', array(__CLASS__, 'admin_page'));
    }

    public static function admin_page() {
        if (!current_user_can('edit_products')) return;
        echo '<div class="wrap"><h1>Outlet – készáru</h1><p>Az eredeti termék szerkesztőoldalán, az „Outlet darab létrehozása” dobozban vehetsz fel új darabot.</p>';
        $url = self::page_url();
        if ($url) {
            echo '<p><label for="mg-outlet-link">Menübe illeszthető link</label></p><p><input id="mg-outlet-link" class="large-text" readonly value="' . esc_attr($url) . '"></p>';
            echo '<p><button type="button" class="button" id="mg-outlet-copy">Link másolása</button> <a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener">Outlet megnyitása</a></p><p id="mg-outlet-copy-status" role="status"></p>';
        } else {
            echo '<p>Az Outlet oldal nem elérhető. Állítsd vissza a korábban létrehozott oldalt az Oldalak menüben; tartalma: <code>[mg_outlet]</code>.</p>';
        }
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=product&mg_outlet_only=1')) . '">Outlet darabok kezelése</a></p>';
        echo '<p>A terméklistában szerkesztheted az árat, a készletet, a képet és az állapotleírást. A kínálatból a termék vázlatba állításával veheted le. A készlet nélküli darab automatikusan eltűnik az Outlet oldalról.</p></div>';
    }

    public static function admin_filter($query) {
        if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'product' && !empty($_GET['mg_outlet_only'])) {
            $meta = (array) $query->get('meta_query');
            $query->set('meta_query', array('relation' => 'AND', $meta, array('key' => self::META, 'value' => 'yes')));
        }
    }

    public static function meta_box($post) {
        add_meta_box('mg-outlet-create', self::is_outlet($post->ID) ? 'Outlet – készáru' : 'Outlet darab létrehozása', array(__CLASS__, 'render_box'), 'product', 'normal', 'default');
    }

    public static function admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || ($screen->post_type !== 'product' && strpos($hook, 'mg-outlet') === false)) return;
        wp_enqueue_media();
        wp_enqueue_script('mg-outlet-admin', plugins_url('../assets/js/outlet-admin.js', __FILE__), array(), MG_VERSION, true);
    }

    public static function render_box($post) {
        if (self::is_outlet($post->ID)) {
            $source = absint(get_post_meta($post->ID, '_mg_outlet_source', true));
            echo '<p>Rögzített kombináció: <strong>' . esc_html(self::combination($post->ID)) . '</strong></p>';
            echo '<p>A normál Termékadatok panelen módosíthatod az árat és a készletet. Az állapotleírás a rövid leírásban, a fotó a termékképnél módosítható. Feedből, nyomatgyártásból és nagykerexportból kizárva.</p>';
            if ($source && current_user_can('edit_post', $source)) echo '<a href="' . esc_url(get_edit_post_link($source)) . '">Eredeti termék szerkesztése</a>';
            return;
        }
        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('simple') || $post->post_status === 'auto-draft') {
            echo '<p>Előbb mentsd el az eredeti terméket.</p>';
            return;
        }
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        $types = array();
        foreach (($config['types'] ?? array()) as $key => $type) {
            $types[$key] = array('label' => $type['label'], 'colors' => $type['colors']);
        }
        echo '<div id="mg-outlet-form" data-product="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr(wp_create_nonce('mg_create_outlet_' . $post->ID)) . '" data-request="' . esc_attr(wp_generate_uuid4()) . '">';
        echo '<script type="application/json" id="mg-outlet-types">' . wp_json_encode($types, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        echo '<p>A létrehozás az eredeti termék legutóbb mentett adatait használja. Egy outlet termék egy rögzített mintához, típushoz, színhez és mérethez tartozik.</p>';
        foreach (array('type' => 'Típus', 'color' => 'Szín', 'size' => 'Méret') as $key => $label) {
            echo '<p><label for="mg-outlet-' . esc_attr($key) . '">' . esc_html($label) . '</label><br><select id="mg-outlet-' . esc_attr($key) . '" style="min-width:220px;max-width:100%"></select></p>';
        }
        echo '<p><label for="mg-outlet-qty">Darabszám</label><br><input id="mg-outlet-qty" type="number" min="1" step="1" value="1"></p>';
        echo '<p><label for="mg-outlet-price">Outlet ár (' . esc_html(get_woocommerce_currency()) . ', a bolt adóbeállítása szerint)</label><br><input id="mg-outlet-price" type="text" inputmode="decimal" placeholder="3990"></p>';
        echo '<p><label for="mg-outlet-note">Állapot / meglévő felirat (a vásárló is látja)</label><br><textarea id="mg-outlet-note" rows="3" class="widefat" placeholder="Pl. hibátlan, visszaküldött darab. Meglévő felirat: Anna."></textarea></p>';
        echo '<p><button class="button" type="button" id="mg-outlet-photo">Saját fotó választása</button> <button class="button" type="button" id="mg-outlet-photo-clear">Fotó törlése</button><input id="mg-outlet-image" type="hidden" value="0"><span id="mg-outlet-photo-label"> A kombináció mockupját használjuk.</span></p>';
        echo '<p><button class="button button-primary" type="button" id="mg-outlet-submit">Létrehozás és megjelenítés az Outletben</button></p><div id="mg-outlet-result" role="status" aria-live="polite"></div></div>';
    }

    /** Validate against the same per-color size matrix as the original selector. */
    public static function validate_selection($config, $type, $color, $size) {
        $entry = $config['types'][$type] ?? null;
        if (!$entry || !isset($entry['colors'][$color]) || !in_array($size, array_map('strval', $entry['colors'][$color]['sizes'] ?? array()), true)) {
            return new WP_Error('invalid_combination', 'Érvénytelen típus–szín–méret kombináció. Frissítsd az oldalt.');
        }
        return array('type' => $entry['label'], 'color' => $entry['colors'][$color]['label'], 'size' => $size);
    }

    public static function ajax_create() {
        $source_id = absint($_POST['product_id'] ?? 0);
        check_ajax_referer('mg_create_outlet_' . $source_id, 'nonce');
        if (!current_user_can('edit_post', $source_id) || !current_user_can('publish_products') || !current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Nincs jogosultságod terméket létrehozni.'), 403);
        }
        $source = wc_get_product($source_id);
        if (get_option('woocommerce_manage_stock') !== 'yes') {
            wp_send_json_error(array('message' => 'Előbb kapcsold be a WooCommerce → Beállítások → Termékek → Készlet → Készletkezelés engedélyezése beállítást. Enélkül az 1 darabos készlet nem védhető.'), 400);
        }
        if (!$source || !$source->is_type('simple') || self::is_outlet($source_id) || in_array($source->get_status(), array('trash', 'auto-draft'), true)) {
            wp_send_json_error(array('message' => 'Az eredeti egyszerű termék nem elérhető.'), 400);
        }
        $input = array();
        foreach (array('type', 'color', 'size', 'price', 'qty', 'note', 'request') as $key) {
            $input[$key] = isset($_POST[$key]) && is_scalar($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : '';
        }
        $selection = self::validate_selection(MG_Virtual_Variant_Manager::get_frontend_config($source), $input['type'], $input['color'], $input['size']);
        $price = wc_format_decimal($input['price']);
        if (is_wp_error($selection)) wp_send_json_error(array('message' => $selection->get_error_message()), 400);
        if (!is_numeric($price) || (float) $price <= 0 || !is_finite((float) $price) || !preg_match('/^[1-9][0-9]{0,5}$/D', $input['qty'])) {
            wp_send_json_error(array('message' => 'Adj meg pozitív árat és egész darabszámot (1–999999).'), 400);
        }
        if (!preg_match('/^[a-f0-9-]{36}$/D', $input['request'])) wp_send_json_error(array('message' => 'Érvénytelen kérés. Frissítsd az oldalt.'), 400);
        // Atomic unique request key prevents double clicks/retries from creating two stock units.
        $lock = 'mg_outlet_request_' . md5(get_current_user_id() . ':' . $source_id . ':' . $input['request']);
        if (!add_option($lock, array('id' => 0), '', false)) {
            $saved = get_option($lock);
            if (!empty($saved['id'])) self::created_response($saved['id']);
            wp_send_json_error(array('message' => 'Ez a létrehozás már folyamatban van. Próbáld újra később; új darabhoz frissítsd az oldalt.'), 409);
        }
        $product = null;
        try {
            $image_id = absint($_POST['image_id'] ?? 0);
            if ($image_id && (!current_user_can('upload_files') || !current_user_can('edit_post', $image_id) || !wp_attachment_is_image($image_id))) {
                throw new RuntimeException('A kiválasztott kép nem használható.');
            }
            if (!$image_id) $image_id = self::mockup_image($source, $input['type'], $input['color']);
            if (is_wp_error($image_id)) throw new RuntimeException($image_id->get_error_message());
            $product = new WC_Product_Simple();
            $product->set_name($source->get_name('edit') . ' – ' . implode(' / ', $selection) . ' – Outlet');
            $product->set_status('draft');
            $product->set_regular_price($price);
            $product->set_stock_quantity((int) $input['qty']);
            $product->set_image_id($image_id);
            $product->set_short_description($input['note']);
            $product->set_description('Outlet készáru. Rögzített típus, szín, méret és minta; személyre szabás nem kérhető.');
            $product->set_tax_status($source->get_tax_status());
            $product->set_tax_class($source->get_tax_class());
            $product->set_shipping_class_id($source->get_shipping_class_id());
            foreach (array('weight', 'length', 'width', 'height') as $dimension) {
                $product->{'set_' . $dimension}($source->{'get_' . $dimension}('edit'));
            }
            $product->update_meta_data(self::META, 'yes');
            $product->update_meta_data('_mg_outlet_source', $source_id);
            foreach (array('type', 'color', 'size') as $key) {
                $product->update_meta_data('_mg_outlet_' . $key, $input[$key]);
                $product->update_meta_data('_mg_outlet_' . $key . '_label', $selection[$key]);
            }
            $attributes = array();
            foreach (array('type' => 'Típus', 'color' => 'Szín', 'size' => 'Méret') as $key => $label) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($label);
                $attribute->set_options(array($selection[$key]));
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[] = $attribute;
            }
            $product->set_attributes($attributes);
            $id = $product->save();
            $product->set_sku('OUTLET-' . $id);
            $product->set_status('publish');
            $product->save();
            update_option($lock, array('id' => $id), false);
        } catch (Throwable $e) {
            // Keep the key if a product exists: a retry must never duplicate published stock.
            if ($product && $product->get_id()) {
                update_option($lock, array('id' => $product->get_id()), false);
                wp_send_json_error(array('message' => 'A darab részben létrejött (#' . $product->get_id() . '). Ellenőrizd a terméklistában, mielőtt újra létrehozod. ' . $e->getMessage()), 500);
            }
            delete_option($lock);
            wp_send_json_error(array('message' => $e->getMessage()), 400);
        }
        self::created_response($id);
    }

    private static function created_response($id) {
        $product = wc_get_product($id);
        if (!$product) wp_send_json_error(array('message' => 'A korábban létrehozott darabot törölték. Új darabhoz frissítsd az oldalt.'), 409);
        wp_send_json_success(array('message' => $product->get_status() === 'publish' ? 'Az outlet darab elkészült.' : 'A darab már létrejött. Ellenőrizd és publikáld a szerkesztőben.',
            'edit_url' => get_edit_post_link($id, 'raw'), 'url' => get_permalink($id)));
    }

    /** Copy the exact existing mockup into a normal attachment; never reuse the wrong color. */
    private static function mockup_image($source, $type, $color) {
        $uploads = wp_upload_dir();
        $sku = $source->get_sku('edit');
        foreach (array($sku, $type, $color) as $part) {
            if (!$part || preg_match('~[\\\\/\x00]~', $part) || $part === '.' || $part === '..') return new WP_Error('image', 'Válassz saját fotót ehhez a darabhoz.');
        }
        $path = trailingslashit($uploads['basedir']) . 'mg_mockups/' . $sku . '/' . $sku . '_' . $type . '_' . $color . '_front.webp';
        $real = realpath($path);
        $root = realpath(trailingslashit($uploads['basedir']) . 'mg_mockups');
        if (!$root || !$real || strpos(wp_normalize_path($real), trailingslashit(wp_normalize_path($root))) !== 0 || !is_file($real)) {
            return new WP_Error('image', 'Ehhez a kombinációhoz nincs kész mockup. Válassz saját fotót, vagy előbb generáld le a mockupot.');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $temp = wp_tempnam('outlet.webp');
        if (!$temp || !copy($real, $temp)) {
            if ($temp) wp_delete_file($temp);
            return new WP_Error('image', 'A mockup másolása sikertelen.');
        }
        $id = media_handle_sideload(array('name' => 'outlet-' . basename($real), 'tmp_name' => $temp), 0);
        if (is_wp_error($id)) wp_delete_file($temp);
        return $id;
    }

    public static function combination($id) {
        $parts = array();
        foreach (array('type', 'color', 'size') as $key) $parts[] = get_post_meta($id, '_mg_outlet_' . $key . '_label', true);
        return implode(' · ', array_filter($parts, 'strlen'));
    }

    public static function cart_data($data, $product_id, $variation_id = 0, $quantity = 1) {
        if (!self::is_outlet($product_id)) return $data;
        // Discard submitted customization/cross-sell/base-price payloads. Read the fixed item on the server.
        foreach (array_keys($data) as $key) if (strpos($key, 'mg_') === 0) unset($data[$key]);
        return $data;
    }

    public static function restore_cart($data, $values) {
        return self::cart_data($data, $data['product_id'] ?? 0);
    }

    public static function cart_prices($cart) {
        if (!$cart) return;
        foreach ($cart->get_cart() as $key => $line) {
            if (!self::is_outlet($line['product_id'])) continue;
            $fresh = wc_get_product($line['product_id']);
            if ($fresh && isset($line['data'])) $line['data']->set_price($fresh->get_price('edit'));
        }
    }

    public static function item_data($data, $line) {
        if (self::is_outlet($line['product_id'] ?? 0)) $data[] = array('key' => 'Outlet – készáru', 'value' => self::combination($line['product_id']));
        return $data;
    }

    public static function order_item($item, $key, $values, $order) {
        if (!self::is_outlet($item->get_product_id())) return;
        $item->update_meta_data(self::META, 'yes');
        $item->update_meta_data('Outlet – készáru', self::combination($item->get_product_id()));
    }

    public static function snapshot_admin_item($id, $item, $order_id) {
        if (!is_a($item, 'WC_Order_Item_Product') || !self::is_outlet($item->get_product_id())) return;
        self::order_item($item, '', array(), null);
        $item->save_meta_data();
    }

    public static function assets() {
        if (is_page(absint(get_option(self::PAGE_OPTION))) || (is_singular() && has_shortcode(get_post_field('post_content', get_queried_object_id()), 'mg_outlet'))) {
            wp_enqueue_style('mg-outlet', plugins_url('../assets/css/outlet.css', __FILE__), array(), MG_VERSION);
        }
    }

    public static function avoid_stale_stock() {
        if (is_singular() && has_shortcode(get_post_field('post_content', get_queried_object_id()), 'mg_outlet')) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            nocache_headers();
        }
    }

    public static function shortcode() {
        $meta = array('relation' => 'AND', array('key' => self::META, 'value' => 'yes'),
            array('key' => '_stock_status', 'value' => 'instock'), array('key' => '_stock', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'));
        $filters = array();
        foreach (array('type' => 'Típus', 'color' => 'Szín', 'size' => 'Méret') as $key => $label) {
            $value = isset($_GET['outlet_' . $key]) && is_scalar($_GET['outlet_' . $key]) ? sanitize_text_field(wp_unslash($_GET['outlet_' . $key])) : '';
            $filters[$key] = array('label' => $label, 'value' => $value);
            if ($value !== '') $meta[] = array('key' => '_mg_outlet_' . $key, 'value' => $value);
        }
        $page = max(1, absint($_GET['outlet_page'] ?? 1));
        $query = new WP_Query(array('post_type' => 'product', 'post_status' => 'publish', 'has_password' => false,
            'posts_per_page' => 24, 'paged' => $page, 'meta_query' => $meta, 'orderby' => 'date', 'order' => 'DESC'));
        // Facets come from saved outlet snapshots, so retired catalog combinations remain discoverable.
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT m.meta_key, m.meta_value FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
            INNER JOIN {$wpdb->postmeta} flag ON flag.post_id=p.ID AND flag.meta_key=%s AND flag.meta_value='yes'
            INNER JOIN {$wpdb->postmeta} stock ON stock.post_id=p.ID AND stock.meta_key='_stock' AND CAST(stock.meta_value AS DECIMAL(20,4))>0
            INNER JOIN {$wpdb->postmeta} status ON status.post_id=p.ID AND status.meta_key='_stock_status' AND status.meta_value='instock'
            WHERE p.post_type='product' AND p.post_status='publish' AND p.post_password='' AND m.meta_key IN ('_mg_outlet_type','_mg_outlet_color','_mg_outlet_size')", self::META));
        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        $options = array('type' => array(), 'color' => array(), 'size' => array());
        foreach ($rows as $row) {
            $key = substr($row->meta_key, strlen('_mg_outlet_'));
            $label = $row->meta_value;
            if ($key === 'type') $label = $catalog[$row->meta_value]['label'] ?? $label;
            if ($key === 'color') foreach ($catalog as $type) if (isset($type['colors'][$row->meta_value]['label'])) { $label = $type['colors'][$row->meta_value]['label']; break; }
            $options[$key][$row->meta_value] = $label;
        }
        ob_start();
        echo wc_print_notices(true);
        echo '<section class="mg-outlet"><p class="mg-outlet-intro">Készleten lévő, egyedi darabok outlet áron. A feltüntetett típusban, színben és méretben, a készlet erejéig.</p><form method="get" class="mg-outlet-filters">';
        // Preserve plain permalink page IDs as well as pretty permalinks.
        if (isset($_GET['page_id'])) echo '<input type="hidden" name="page_id" value="' . esc_attr(absint($_GET['page_id'])) . '">';
        foreach ($filters as $key => $filter) {
            if ($filter['value'] !== '' && !isset($options[$key][$filter['value']])) $options[$key][$filter['value']] = $filter['value'];
            natcasesort($options[$key]);
            echo '<label>' . esc_html($filter['label']) . '<select name="outlet_' . esc_attr($key) . '"><option value="">Összes</option>';
            foreach ($options[$key] as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($filter['value'], (string) $value, false) . '>' . esc_html($label) . '</option>';
            echo '</select></label>';
        }
        echo '<button type="submit" class="button">Szűrés</button><a href="' . esc_url(remove_query_arg(array('outlet_type', 'outlet_color', 'outlet_size', 'outlet_page'))) . '">Szűrők törlése</a></form>';
        if (!$query->have_posts()) echo '<p role="status">Jelenleg nincs ilyen outlet darab. Próbálj másik szűrést, vagy nézz vissza később.</p>';
        echo '<div class="mg-outlet-grid">';
        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product || !$product->is_in_stock()) continue;
            echo '<article class="mg-outlet-card"><a href="' . esc_url($product->get_permalink()) . '">' . $product->get_image('woocommerce_thumbnail') . '<h2>' . esc_html($product->get_name()) . '</h2></a>';
            echo '<p class="mg-outlet-combination">' . esc_html(self::combination($post->ID)) . '</p><p class="mg-outlet-stock">' . esc_html($product->get_stock_quantity()) . ' db készleten</p>';
            if ($product->get_short_description()) echo '<p>' . esc_html(wp_strip_all_tags($product->get_short_description())) . '</p>';
            echo '<div class="mg-outlet-buy"><span class="price">' . $product->get_price_html() . '</span>';
            if ($product->is_purchasable()) echo '<a class="button" href="' . esc_url($product->add_to_cart_url()) . '" aria-label="' . esc_attr($product->get_name() . ' kosárba') . '">Kosárba</a>';
            echo '</div></article>';
        }
        echo '</div>';
        if ($query->max_num_pages > 1) echo '<nav aria-label="Outlet oldalak" class="mg-outlet-pages">' . paginate_links(array('base' => add_query_arg('outlet_page', '%#%'), 'format' => '', 'current' => $page, 'total' => $query->max_num_pages)) . '</nav>';
        echo '</section>';
        return ob_get_clean();
    }
}
