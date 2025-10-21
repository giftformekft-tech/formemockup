<?php
if (!defined('ABSPATH')) exit;

// Always load AJAX handlers so admin-ajax.php has the hooks
$ajax_bulk = plugin_dir_path(__FILE__).'ajax-bulk.php';
if (file_exists($ajax_bulk)) { require_once $ajax_bulk; }

// Keep product search if present
$ajax_search = plugin_dir_path(__FILE__).'ajax-product-search.php';
if (file_exists($ajax_search)) { require_once $ajax_search; }

require_once plugin_dir_path(__FILE__).'class-dashboard-page.php';

class MG_Admin_Page {
    private static function sanitize_size_list($product) {
        $sizes = array();
        if (is_array($product)) {
            if (!empty($product['sizes']) && is_array($product['sizes'])) {
                foreach ($product['sizes'] as $size_value) {
                    if (!is_string($size_value)) { continue; }
                    $size_value = trim($size_value);
                    if ($size_value === '') { continue; }
                    $sizes[] = $size_value;
                }
            }
            if (!empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
                foreach ($product['size_color_matrix'] as $size_label => $colors) {
                    if (!is_string($size_label)) { continue; }
                    $size_label = trim($size_label);
                    if ($size_label === '') { continue; }
                    $sizes[] = $size_label;
                }
            }
        }
        return array_values(array_unique($sizes));
    }

    private static function normalize_size_color_matrix($product) {
        $matrix = array();
        $has_entries = false;
        if (is_array($product) && !empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
            foreach ($product['size_color_matrix'] as $size_label => $colors) {
                if (!is_string($size_label)) { continue; }
                $size_label = trim($size_label);
                if ($size_label === '') { continue; }
                $clean_colors = array();
                if (is_array($colors)) {
                    foreach ($colors as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '') { continue; }
                        if (!in_array($slug, $clean_colors, true)) {
                            $clean_colors[] = $slug;
                        }
                    }
                }
                $matrix[$size_label] = $clean_colors;
                $has_entries = true;
            }
        }
        return array($matrix, $has_entries);
    }

    private static function allowed_sizes_for_color($product, $color_slug) {
        $color_slug = sanitize_title($color_slug ?? '');
        $base_sizes = self::sanitize_size_list($product);
        list($matrix, $has_entries) = self::normalize_size_color_matrix($product);
        if (!$has_entries) {
            return $base_sizes;
        }
        if ($color_slug === '') {
            return $base_sizes;
        }
        $allowed = array();
        foreach ($matrix as $size_label => $colors) {
            if (in_array($color_slug, $colors, true)) {
                $allowed[] = $size_label;
            }
        }
        return array_values(array_unique($allowed));
    }

    public static function add_menu_page() {
        add_menu_page('Mockup Generator','Mockup Generator','edit_products','mockup-generator',[ 'MG_Dashboard_Page','render_page' ],'dashicons-art');

        add_submenu_page('mockup-generator','Dashboard','Dashboard','edit_products','mockup-generator-dashboard',[ 'MG_Dashboard_Page','render_page' ]);
        add_submenu_page('mockup-generator','Bulk feltöltés','Bulk feltöltés','edit_products','mockup-generator-bulk',[ self::class,'render_page' ]);


}

    public static function render_page() {
        if (isset($_GET['status'])) {
            if ($_GET['status'] === 'success') {
                echo '<div class="notice notice-success is-dismissible"><p>Sikeres generálás.</p></div>';
            } elseif ($_GET['status'] === 'error') {
                $msg = isset($_GET['mg_error']) ? sanitize_text_field(wp_unslash($_GET['mg_error'])) : 'Ismeretlen hiba.';
                echo '<div class="notice notice-error"><p><strong>Hiba:</strong> '.esc_html($msg).'</p></div>';
            }
        }

        // Enqueue assets
        wp_enqueue_style('mg-bulk-css', plugin_dir_url(__FILE__).'../assets/css/bulk-upload.css', array(), filemtime(plugin_dir_path(__FILE__).'../assets/css/bulk-upload.css'));
if (file_exists(plugin_dir_path(__FILE__).'../assets/js/product-search.js')) {
            wp_enqueue_script('mg-product-search', plugin_dir_url(__FILE__).'../assets/js/product-search.js', array('jquery'), '1.0.1', true);
            wp_localize_script('mg-product-search', 'MG_SEARCH', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('mg_search_nonce'),
            ));
        }

        // Data for categories
        $mains = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>0));
        $subs_map = array();
        if (!is_wp_error($mains)) {
            foreach ($mains as $c) {
                $subs = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$c->term_id));
                $subs_map[$c->term_id] = !is_wp_error($subs) ? array_map(function($t){
                    return array('id'=>$t->term_id,'name'=>$t->name);
                }, $subs) : array();
            }
        }
        $products = get_option('mg_products', array());
        $products = is_array($products) ? array_values(array_filter($products, function($p){ return is_array($p) && !empty($p['key']); })) : array();
        $default_type = '';
        $default_color = '';
        $default_size = '';
        foreach ($products as &$prod) {
            if (!isset($prod['colors']) || !is_array($prod['colors'])) { $prod['colors'] = array(); }
            if (!isset($prod['label'])) { $prod['label'] = $prod['key']; }
            if (!$default_type && !empty($prod['is_primary'])) {
                $default_type = $prod['key'];
                $default_color = isset($prod['primary_color']) ? $prod['primary_color'] : '';
                $default_size = isset($prod['primary_size']) ? $prod['primary_size'] : '';
            }
        }
        unset($prod);
        if (!$default_type && !empty($products)) {
            $default_type = $products[0]['key'];
            $default_color = isset($products[0]['primary_color']) ? $products[0]['primary_color'] : '';
            $default_size = isset($products[0]['primary_size']) ? $products[0]['primary_size'] : '';
        }
        $default_color = is_string($default_color) ? $default_color : '';
        $default_size = is_string($default_size) ? $default_size : '';
        $default_colors_available = array();
        $default_sizes_available = array();
        $default_product = null;
        foreach ($products as $prod) {
            if ($prod['key'] === $default_type) { $default_product = $prod; break; }
        }
        if ($default_product) {
            if (!empty($default_product['colors']) && is_array($default_product['colors'])) {
                foreach ($default_product['colors'] as $c) {
                    if (isset($c['slug'])) { $default_colors_available[] = sanitize_title($c['slug']); }
                }
            }
            if ($default_color && !in_array($default_color, $default_colors_available, true)) {
                $default_color = '';
            }
            if (!$default_color && !empty($default_colors_available)) {
                $preferred_color = isset($default_product['primary_color']) ? sanitize_title($default_product['primary_color']) : '';
                if ($preferred_color && in_array($preferred_color, $default_colors_available, true)) {
                    $default_color = $preferred_color;
                } else {
                    $default_color = $default_colors_available[0];
                }
            }
            $default_sizes_available = self::allowed_sizes_for_color($default_product, $default_color);
            if ($default_color && empty($default_sizes_available) && !empty($default_colors_available)) {
                foreach ($default_colors_available as $color_candidate) {
                    $candidate_sizes = self::allowed_sizes_for_color($default_product, $color_candidate);
                    if (!empty($candidate_sizes)) {
                        $default_color = $color_candidate;
                        $default_sizes_available = $candidate_sizes;
                        break;
                    }
                }
            }
            if (empty($default_sizes_available)) {
                $default_sizes_available = self::sanitize_size_list($default_product);
            }
            if ($default_size && !in_array($default_size, $default_sizes_available, true)) {
                $default_size = '';
            }
            if (!$default_size && !empty($default_sizes_available)) {
                $preferred_size = isset($default_product['primary_size']) ? $default_product['primary_size'] : '';
                if (!is_string($preferred_size)) { $preferred_size = ''; }
                if ($preferred_size && in_array($preferred_size, $default_sizes_available, true)) {
                    $default_size = $preferred_size;
                } else {
                    $default_size = $default_sizes_available[0];
                }
            }
        }
        if ($default_type) {
            $primary_index = null;
            foreach ($products as $idx => $prod) {
                if ($prod['key'] === $default_type) { $primary_index = $idx; break; }
            }
            if ($primary_index !== null && $primary_index > 0) {
                $primary_item = $products[$primary_index];
                array_splice($products, $primary_index, 1);
                array_unshift($products, $primary_item);
            }
        }
        $products_for_js = array_map(function($p){
            $colors = array();
            if (!empty($p['colors']) && is_array($p['colors'])) {
                foreach ($p['colors'] as $c) {
                    if (!isset($c['slug'])) continue;
                    $colors[] = array(
                        'slug' => $c['slug'],
                        'name' => isset($c['name']) ? $c['name'] : $c['slug'],
                    );
                }
            }
            $sizes = MG_Admin_Page::sanitize_size_list($p);
            $color_size_map = array();
            foreach ($colors as $c) {
                if (!isset($c['slug'])) { continue; }
                $slug = sanitize_title($c['slug']);
                $color_size_map[$slug] = MG_Admin_Page::allowed_sizes_for_color($p, $slug);
            }
            return array(
                'key' => $p['key'],
                'label' => isset($p['label']) ? $p['label'] : $p['key'],
                'colors' => $colors,
                'primary_color' => isset($p['primary_color']) ? $p['primary_color'] : '',
                'is_primary' => !empty($p['is_primary']) ? 1 : 0,
                'sizes' => $sizes,
                'primary_size' => isset($p['primary_size']) ? $p['primary_size'] : '',
                'color_sizes' => $color_size_map,
            );
        }, $products);

        $worker_count = 1;
        $worker_options = array(1, 2, 3, 4, 6, 8);
        if (class_exists('MG_Bulk_Queue')) {
            $worker_count = MG_Bulk_Queue::get_configured_worker_count();
            $worker_options = MG_Bulk_Queue::get_allowed_worker_counts();
        }

        wp_enqueue_script('mg-bulk-advanced', plugin_dir_url(__FILE__).'../assets/js/bulk-upload-advanced.js', array('jquery'), filemtime(plugin_dir_path(__FILE__).'../assets/js/bulk-upload-advanced.js'), true);
        wp_localize_script('mg-bulk-advanced', 'MG_BULK_ADV', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mg_bulk_nonce'),
            'mains'    => !is_wp_error($mains) ? array_map(function($t){ return array('id'=>$t->term_id,'name'=>$t->name); }, $mains) : array(),
            'subs'     => $subs_map,
            'products' => $products_for_js,
            'default_type' => $default_type,
            'default_color' => $default_color,
            'default_size' => $default_size,
            'worker_count' => $worker_count,
            'worker_options' => $worker_options,
            'worker_feedback_saving' => 'Mentés…',
            'worker_feedback_saved' => 'Beállítva: %d worker.',
            'worker_feedback_error' => 'Nem sikerült menteni. Próbáld újra.',
        ));

        $default_colors_render = array();
        foreach ($products as $prod) {
            if ($prod['key'] === $default_type) {
                $default_colors_render = is_array($prod['colors']) ? $prod['colors'] : array();
                break;
            }
        }
        $default_sizes_render = array();
        if ($default_product) {
            $default_sizes_render = self::allowed_sizes_for_color($default_product, $default_color);
            if (empty($default_sizes_render)) {
                $default_sizes_render = self::sanitize_size_list($default_product);
            }
        }

        ?>
        <div class="wrap">
          <h1>Mockup Generator</h1>
          <p><a href="<?php echo admin_url('admin.php?page=mockup-generator-settings'); ?>" class="button">Beállítások</a></p>

          <h2>Bulk feltöltés – kényelmes mód</h2>
          <div class="card">
            <p>Több mintát tölthetsz fel egyszerre, és <strong>soronként</strong> beállíthatod a főkategóriát, több alkategóriát, terméknevet, és a (meglévő) szülő terméket is.</p>
            <div class="mg-row">
              <label><strong>Mintafájlok</strong></label>
              <div id="mg-drop-zone" class="mg-drop-zone"><div class="mg-drop-inner">Húzd ide a mintákat vagy <button type="button" class="button-link">válaszd ki</button></div><input type="file" id="mg-bulk-files-adv" accept=".png,.jpg,.jpeg,.webp" multiple style="display:none"></div>
            </div>
            <div class="mg-row">
              <label><strong>Terméktípusok (globális)</strong></label>
              <div class="mg-types">
              <?php if (empty($products)): ?>
                  <em>Még nincs felvéve terméktípus. Menj a Beállítások oldalra.</em>
              <?php else: foreach ($products as $p): ?>
                  <label class="mg-type"><input type="checkbox" class="mg-type-cb" value="<?php echo esc_attr($p['key']); ?>" checked="checked"> <?php echo esc_html($p['label']); ?></label>
              <?php endforeach; endif; ?>
              </div>
              <?php if (!empty($products)): ?>
              <div class="mg-default-selects">
                <label><strong>Alapértelmezett terméktípus</strong></label>
                <select id="mg-default-type">
                  <?php foreach ($products as $p): ?>
                    <option value="<?php echo esc_attr($p['key']); ?>" <?php selected($default_type, $p['key']); ?>><?php echo esc_html($p['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mg-default-selects">
                <label><strong>Alapértelmezett szín</strong></label>
                <select id="mg-default-color">
                  <?php if (!empty($default_colors_render)): ?>
                    <?php foreach ($default_colors_render as $color): if (!isset($color['slug'], $color['name'])) continue; ?>
                      <option value="<?php echo esc_attr($color['slug']); ?>" <?php selected($default_color, $color['slug']); ?>><?php echo esc_html($color['name']); ?></option>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <option value="">— Ehhez a típushoz nincs szín —</option>
                  <?php endif; ?>
                </select>
              </div>
              <div class="mg-default-selects">
                <label><strong>Alapértelmezett méret</strong></label>
                <select id="mg-default-size">
                  <?php if (!empty($default_sizes_render)): ?>
                    <?php foreach ($default_sizes_render as $size_label): if (!is_string($size_label) || $size_label === '') continue; ?>
                      <option value="<?php echo esc_attr($size_label); ?>" <?php selected($default_size, $size_label); ?>><?php echo esc_html($size_label); ?></option>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <option value="">— Ehhez a típushoz nincs méret —</option>
                  <?php endif; ?>
                </select>
            </div>
            <p class="description" style="margin-top:8px;">Az itt megadott kombináció lesz előre kiválasztva a bulk feltöltés elindításakor.</p>
            <?php endif; ?>
          </div>

          <div class="mg-worker-control" id="mg-worker-control">
            <span class="mg-worker-label"><strong>Párhuzamos feldolgozás</strong></span>
            <div class="mg-worker-toggle-group" role="group" aria-label="Párhuzamos worker szám kiválasztása">
              <?php foreach ($worker_options as $option): ?>
                <?php $is_active = (intval($worker_count) === intval($option)); ?>
                <button type="button" class="button mg-worker-toggle<?php if ($is_active) { echo ' is-active'; } ?>" data-workers="<?php echo esc_attr($option); ?>" aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"><?php echo esc_html($option); ?> worker</button>
              <?php endforeach; ?>
            </div>
            <p class="mg-worker-summary">Aktív: <span class="mg-worker-active-count"><?php echo esc_html($worker_count); ?></span> worker</p>
            <p class="description">A több worker egyszerre több mockupot készít el, de jelentősen növelheti a szerver terhelését (CPU, memória).</p>
            <p class="mg-worker-feedback" aria-live="polite"></p>
          </div>

          <h3>Tételek</h3>
          <p>
            <button type="button" class="button" id="mg-bulk-apply-first">Első sor beállításainak másolása a többire</button>
          </p>
            <div class="mg-table-wrap">
              <table class="widefat fixed striped mg-bulk-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Előnézet</th>
                    <th>Fájlnév</th>
                    <th>Főkategória</th>
                    <th>Alkategóriák</th>
                    <th>Terméknév</th>
                    <th>Szülő keresése</th>
                    <th>Egyedi termék</th>
                    <th>Tag-ek</th>
                    <th class="mg-state">Állapot</th>
                  </tr>
                </thead>
                <tbody id="mg-bulk-rows">
                  <tr class="no-items"><td colspan="10">Válassz fájlokat fent.</td></tr>
                </tbody>
              </table>
            </div>

            <div class="mg-actions">
              <button class="button button-primary" id="mg-bulk-start">Bulk generálás indítása</button>
              <div class="mg-progress"><div class="mg-bar" id="mg-bulk-bar" style="width:0%"></div></div>
              <span id="mg-bulk-status">0%</span>
            </div>
          </div>
        </div>
        <?php
    }
}

add_action('admin_menu', ['MG_Dashboard_Page','add_menu_page']);

// Cleanup: remove auto mirror submenu that points to 'mockup-generator'
add_action('admin_menu', function(){
    global $submenu;
    if (isset($submenu['mockup-generator'])){
        foreach ($submenu['mockup-generator'] as $i => $item){
            if (isset($item[2]) && $item[2] === 'mockup-generator'){
                unset($submenu['mockup-generator'][$i]);
                break;
            }
        }
    }
}, 999);

// Final cleanup: remove duplicated first 'Dashboard' submenu automatically created by WP
add_action('admin_menu', function(){
    global $submenu;
    if (isset($submenu['mockup-generator'])){
        $seen_dashboard = false;
        foreach ($submenu['mockup-generator'] as $i => $item){
            if (isset($item[2]) && ($item[2] === 'mockup-generator' || stripos($item[0],'dashboard') !== false)){
                if ($seen_dashboard){
                    unset($submenu['mockup-generator'][$i]);
                } else {
                    $seen_dashboard = true;
                }
            }
        }
        // Reindex
        $submenu['mockup-generator'] = array_values($submenu['mockup-generator']);
    }
}, 999);
