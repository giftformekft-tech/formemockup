<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Custom_Feed_Manager {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('admin_post_mg_save_custom_feed', array(__CLASS__, 'handle_save'));
        add_action('admin_post_mg_delete_custom_feed', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_mg_regenerate_custom_feed', array(__CLASS__, 'handle_regeneration'));
        add_action('init', array(__CLASS__, 'check_feed_request'));
    }

    public static function register_admin_page() {
        add_submenu_page(
            'mockup-generator',
            'Egyedi Feeder',
            'Egyedi Feeder',
            'manage_options',
            'mg-custom-feeds',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function render_admin_page() {
        $feeds = get_option('mg_custom_feeds', array());
        $catalog = self::get_catalog_index();
        $categories = get_terms(array('taxonomy' => 'product_type', 'hide_empty' => false)); // product_cat actually
        $product_cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        ?>
        <div class="wrap">
            <h1>Egyedi Termék Feeder (Google / Facebook)</h1>
            
            <div style="display: flex; gap: 20px; align-items: flex-start;">
                
                <!-- List Existing Feeds -->
                <div style="flex: 2;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Név</th>
                                <th>Típus</th>
                                <th>Szűrők</th>
                                <th>URL</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($feeds)): ?>
                                <tr><td colspan="5">Nincs létrehozott egyedi feed.</td></tr>
                            <?php else: ?>
                                <?php foreach ($feeds as $slug => $feed): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($feed['name']); ?></strong></td>
                                        <td><?php echo esc_html(ucfirst($feed['format'])); ?></td>
                                        <td>
                                            <?php 
                                            if (!empty($feed['product_type'])) {
                                                echo 'Típus: ' . esc_html($feed['product_type']) . '<br>';
                                            }
                                            if (!empty($feed['category_id'])) {
                                                $term = get_term($feed['category_id'], 'product_cat');
                                                if ($term && !is_wp_error($term)) {
                                                    echo 'Kategória: ' . esc_html($term->name);
                                                } else {
                                                    echo 'Kategória ID: ' . esc_html($feed['category_id']);
                                                }
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <input type="text" readonly value="<?php echo esc_url(home_url('/?mg_custom_feed=' . $slug)); ?>" class="regular-text" style="width: 100%;" onclick="this.select();">
                                        </td>
                                        <td>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=mg_regenerate_custom_feed&slug=' . $slug), 'mg_regenerate_custom_feed'); ?>" class="button button-small">Generálás</a>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=mg_delete_custom_feed&slug=' . $slug), 'mg_delete_custom_feed'); ?>" class="button button-small button-link-delete" onclick="return confirm('Biztosan törlöd?');">Törlés</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Add New Feed -->
                <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2>Új Feed Létrehozása</h2>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="mg_save_custom_feed">
                        <?php wp_nonce_field('mg_save_custom_feed'); ?>
                        
                        <p>
                            <label><strong>Feed Neve</strong></label><br>
                            <input type="text" name="feed_name" class="widefat" required placeholder="pl. Férfi Póló Gamer">
                        </p>

                        <p>
                            <label><strong>Formátum</strong></label><br>
                            <select name="feed_format" class="widefat">
                                <option value="google">Google Merchant (XML)</option>
                                <option value="facebook">Facebook Catalog (XML)</option>
                            </select>
                        </p>

                        <p>
                            <label><strong>Terméktípus Szűrés</strong> (Opcionális)</label><br>
                            <select name="product_type" class="widefat">
                                <option value="">-- Minden típus --</option>
                                <?php foreach ($catalog as $type_slug => $type_data): ?>
                                    <option value="<?php echo esc_attr($type_slug); ?>"><?php echo esc_html($type_data['label']); ?> (<?php echo esc_html($type_slug); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <span class="description">Ha kiválasztod, csak az ilyen típusú variációk kerülnek a feedbe.</span>
                        </p>

                        <p>
                            <label><strong>Kategória Szűrés</strong> (Opcionális)</label><br>
                            <select name="category_id" class="widefat">
                                <option value="">-- Minden kategória --</option>
                                <?php self::render_category_options($product_cats); ?>
                            </select>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">Feed Létrehozása</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_category_options($cats, $parent = 0, $depth = 0) {
        foreach ($cats as $cat) {
            if ($cat->parent == $parent) {
                echo '<option value="' . esc_attr($cat->term_id) . '">' . str_repeat('- ', $depth) . esc_html($cat->name) . '</option>';
                self::render_category_options($cats, $cat->term_id, $depth + 1);
            }
        }
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_save_custom_feed');

        $name = sanitize_text_field($_POST['feed_name']);
        $format = sanitize_text_field($_POST['feed_format']);
        $product_type = sanitize_text_field($_POST['product_type']);
        $category_id = intval($_POST['category_id']);

        if (!$name) {
            wp_die('Hiányzó név.');
        }

        $slug = sanitize_title($name) . '-' . substr(md5(time()), 0, 6);
        
        $feeds = get_option('mg_custom_feeds', array());
        $feeds[$slug] = array(
            'name' => $name,
            'format' => $format,
            'product_type' => $product_type,
            'category_id' => $category_id,
            'created_at' => time()
        );

        update_option('mg_custom_feeds', $feeds);
        
        wp_redirect(admin_url('admin.php?page=mg-custom-feeds&created=1'));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_delete_custom_feed');

        $slug = sanitize_key($_GET['slug']);
        $feeds = get_option('mg_custom_feeds', array());

        if (isset($feeds[$slug])) {
            unset($feeds[$slug]);
            update_option('mg_custom_feeds', $feeds);
            
            // Allow file deletion if exists
            $path = self::get_feed_file_path($slug);
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        wp_redirect(admin_url('admin.php?page=mg-custom-feeds&deleted=1'));
        exit;
    }

    public static function handle_regeneration() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_regenerate_custom_feed');

        $slug = sanitize_key($_GET['slug']);
        self::generate_feed_to_file($slug);

        wp_redirect(admin_url('admin.php?page=mg-custom-feeds&regenerated=1'));
        exit;
    }

    public static function check_feed_request() {
        if (isset($_GET['mg_custom_feed'])) {
            $slug = sanitize_key($_GET['mg_custom_feed']);
            $feeds = get_option('mg_custom_feeds', array());

            if (!isset($feeds[$slug])) {
                wp_die('Custom feed not found.', 404);
            }

            $path = self::get_feed_file_path($slug);

            if (file_exists($path)) {
                // If force check logic needed, add here.
                // For now, simple serve or regen if missing.
                header('Content-Type: application/xml; charset=UTF-8');
                header('Content-Length: ' . filesize($path));
                readfile($path);
                exit;
            } else {
                
                self::generate_feed_to_file($slug);
                $path = self::get_feed_file_path($slug); // Re-check after generation
                 if (file_exists($path)) {
                    header('Content-Type: application/xml; charset=UTF-8');
                    header('Content-Length: ' . filesize($path));
                    readfile($path);
                    exit;
                 } else {
                     wp_die('Error generating feed.');
                 }
            }
        }
    }

    public static function get_feed_file_path($slug) {
        $upload_dir = wp_upload_dir();
        $path = trailingslashit($upload_dir['basedir']) . 'mg_feeds';
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
        return $path . '/custom_' . $slug . '.xml';
    }

    public static function generate_feed_to_file($slug) {
        $feeds = get_option('mg_custom_feeds', array());
        if (!isset($feeds[$slug])) {
            return false;
        }

        $config = $feeds[$slug];
        $path = self::get_feed_file_path($slug);
        
        // Setup Query
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        // Category Filter
        if (!empty($config['category_id'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $config['category_id'],
                    'include_children' => true
                )
            );
        }

        $product_ids = get_posts($args);
        
        // Start XML
        $handle = fopen($path, 'w');
        if (!$handle) return false;

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
        fwrite($handle, '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . PHP_EOL);
        fwrite($handle, '<channel>' . PHP_EOL);
        fwrite($handle, '<title>' . esc_html($config['name']) . '</title>' . PHP_EOL);
        fwrite($handle, '<link>' . home_url() . '</link>' . PHP_EOL);
        fwrite($handle, '<description>Custom Feed: ' . esc_html($config['name']) . '</description>' . PHP_EOL);

        foreach ($product_ids as $product_id) {
            $xml_chunk = self::get_product_xml($product_id, $config);
            if ($xml_chunk) {
                fwrite($handle, $xml_chunk);
            }
        }

        fwrite($handle, '</channel>' . PHP_EOL);
        fwrite($handle, '</rss>');
        fclose($handle);

        return true;
    }

    private static function get_product_xml($product_id, $feed_config) {
        $product = wc_get_product($product_id);
        if (!$product) return '';

        // Check cache or manager for Virtual Config
        if (!class_exists('MG_Virtual_Variant_Manager')) return '';
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        
        if (empty($config) || empty($config['types'])) return '';

        $target_type = isset($feed_config['product_type']) ? $feed_config['product_type'] : '';
        
        $output = '';
        $base_sku = $product->get_sku() ?: 'ID_'.$product_id;
        $blog_name = get_bloginfo('name');
        $currency = get_woocommerce_currency();
        $custom_urls = isset($config['typeUrls']) ? $config['typeUrls'] : array();

        foreach ($config['types'] as $type_slug => $type_data) {
            // FILTER: If we only want a specific type, skip others
            if ($target_type !== '' && $type_slug !== $target_type) {
                continue;
            }

            $type_label = isset($type_data['label']) ? $type_data['label'] : $type_slug;
            $g_id = $base_sku . '_' . $type_slug;
            $g_title = $product->get_name() . ' - ' . $type_label;
            
            // Description
            $g_description = $product->get_short_description() ?: $product->get_description();
            
            // Link
            if (isset($custom_urls[$type_slug]) && !empty($custom_urls[$type_slug])) {
                $g_link = $custom_urls[$type_slug];
            } else {
                $g_link = add_query_arg('mg_type', $type_slug, $product->get_permalink());
            }

            // Image
            $g_image_link = isset($type_data['preview_url']) ? $type_data['preview_url'] : '';
            if (!$g_image_link) {
                $image_id = $product->get_image_id();
                $g_image_link = wp_get_attachment_url($image_id);
            }

            // Price
            $price_val = (float)$product->get_price();
            if (isset($type_data['price']) && $type_data['price'] > 0) {
                 $price_val = (float)$type_data['price'];
            }

            $g_availability = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';

            $output .= '<item>' . PHP_EOL;
            $output .= '<g:id>' . self::xml_sanitize($g_id) . '</g:id>' . PHP_EOL;
            $output .= '<g:title>' . self::xml_sanitize($g_title) . '</g:title>' . PHP_EOL;
            $output .= '<g:description>' . self::xml_sanitize(strip_tags($g_description)) . '</g:description>' . PHP_EOL;
            $output .= '<g:link>' . self::xml_sanitize($g_link) . '</g:link>' . PHP_EOL;
            $output .= '<g:image_link>' . self::xml_sanitize($g_image_link) . '</g:image_link>' . PHP_EOL;
            $output .= '<g:condition>new</g:condition>' . PHP_EOL;
            $output .= '<g:availability>' . $g_availability . '</g:availability>' . PHP_EOL;
            $output .= '<g:price>' . number_format($price_val, 2, '.', '') . ' ' . $currency . '</g:price>' . PHP_EOL;
            $output .= '<g:brand>' . self::xml_sanitize($blog_name) . '</g:brand>' . PHP_EOL;
            $output .= '<g:item_group_id>' . self::xml_sanitize($base_sku) . '</g:item_group_id>' . PHP_EOL;
            $output .= '<g:custom_label_0>' . self::xml_sanitize($type_slug) . '</g:custom_label_0>' . PHP_EOL;
            
            // Categories logic (simple)
            $terms = get_the_terms($product_id, 'product_cat');
            if ($terms && !is_wp_error($terms)) {
                $cat_names = wp_list_pluck($terms, 'name');
                if (!empty($cat_names)) {
                    $output .= '<g:product_type>' . self::xml_sanitize(implode(' > ', $cat_names)) . '</g:product_type>' . PHP_EOL;
                }
            }

            // New Mandatory Fields
            $output .= '<g:identifier_exists>no</g:identifier_exists>' . PHP_EOL;
            $output .= '<g:google_product_category>212</g:google_product_category>' . PHP_EOL;
            
            // Age Group Logic
            $age_group = 'adult';
            $concat_name_check = $g_title . ' ' . $type_slug;
            if (stripos($concat_name_check, 'baba') !== false) {
                $age_group = 'infant'; // infant or toddler
            } elseif (stripos($concat_name_check, 'gyerek') !== false) {
                $age_group = 'kids';
            }
            $output .= '<g:age_group>' . $age_group . '</g:age_group>' . PHP_EOL;
            
            // Gender logic
            $gender = 'unisex';
            // Simple check in title or type
            $concat_name = $g_title . ' ' . $type_slug;
            if (stripos($concat_name, 'férfi') !== false || stripos($concat_name, 'ferfi') !== false) {
                $gender = 'male';
            } elseif (stripos($concat_name, 'női') !== false || stripos($concat_name, 'noi') !== false) {
                $gender = 'female';
            }
            $output .= '<g:gender>' . $gender . '</g:gender>' . PHP_EOL;

            // Default Color and Size
            $default_color_slug = '';
            $default_size_label = '';

            // Color
            if (!empty($type_data['color_order'])) {
                $default_color_slug = reset($type_data['color_order']);
            } elseif (!empty($type_data['colors'])) {
                $keys = array_keys($type_data['colors']);
                $default_color_slug = reset($keys);
            }

            // Size
            if ($default_color_slug && !empty($type_data['colors'][$default_color_slug]['sizes'])) {
                $default_size_label = reset($type_data['colors'][$default_color_slug]['sizes']);
            } elseif (!empty($type_data['size_order'])) {
                 $default_size_label = reset($type_data['size_order']);
            }

            if ($default_color_slug) {
                 // Get label if possible
                 $color_label = isset($type_data['colors'][$default_color_slug]['label']) ? $type_data['colors'][$default_color_slug]['label'] : $default_color_slug;
                 $output .= '<g:color>' . self::xml_sanitize($color_label) . '</g:color>' . PHP_EOL;
            }
            if ($default_size_label) {
                $output .= '<g:size>' . self::xml_sanitize($default_size_label) . '</g:size>' . PHP_EOL;
            }

            $output .= '</item>' . PHP_EOL;
        }

        return $output;
    }

    private static function xml_sanitize($text) {
        $text = preg_replace('/[\x00-\x08\x0b-\x0c\x0e-\x1f]/', '', $text);
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }

    private static function get_catalog_index() {
        if (class_exists('MG_Variant_Display_Manager')) {
            return MG_Variant_Display_Manager::get_catalog_index();
        }
        return array();
    }
}
