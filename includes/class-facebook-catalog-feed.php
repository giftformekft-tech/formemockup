<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Facebook_Catalog_Feed {

    public static function init() {
        add_action('init', array(__CLASS__, 'add_rewrite_rules'));
        add_action('init', array(__CLASS__, 'check_feed_request'));
        add_action('init', array(__CLASS__, 'schedule_daily_event'));
        add_action('admin_post_mg_regenerate_facebook_feed', array(__CLASS__, 'handle_manual_regeneration'));
        add_action('mg_cron_regenerate_facebook_feed', array(__CLASS__, 'generate_feed_to_file'));
        
        // Auto-flush rules if needed (e.g. after update)
        if (!get_option('mg_facebook_rewrite_flushed')) {
            add_action('init', function() {
                flush_rewrite_rules();
                update_option('mg_facebook_rewrite_flushed', 1);
            }, 999);
        }
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^mg-feed/facebook\.xml$', 'index.php?mg_feed=facebook', 'top');
    }

    public static function schedule_daily_event() {
        if (!wp_next_scheduled('mg_cron_regenerate_facebook_feed')) {
            wp_schedule_event(time(), 'daily', 'mg_cron_regenerate_facebook_feed');
        }
    }

    public static function get_feed_file_path() {
        $upload_dir = wp_upload_dir();
        $path = trailingslashit($upload_dir['basedir']) . 'mg_feeds';
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
        return $path . '/facebook_catalog.xml';
    }

    public static function get_feed_url() {
        // Return pretty URL
        return home_url('/mg-feed/facebook.xml');
    }

    public static function check_feed_request() {
        if (isset($_GET['mg_feed']) && $_GET['mg_feed'] === 'facebook') {
            $path = self::get_feed_file_path();
            
            // 1. If file exists
            if (file_exists($path)) {
                $file_time = filemtime($path);
                
                // If STALE (older than 24h)
                if (time() - $file_time > 24 * HOUR_IN_SECONDS && !isset($_GET['force'])) {
                     if (!get_transient('mg_facebook_feed_regenerating')) {
                        wp_schedule_single_event(time(), 'mg_cron_regenerate_facebook_feed');
                        set_transient('mg_facebook_feed_regenerating', 'true', 10 * MINUTE_IN_SECONDS);
                    }
                }
                
                self::serve_file($path);
                exit;
            }
            
            // 2. If file does NOT exist -> Generate Synchronously
            self::generate_feed_to_file();
            self::serve_file($path);
            exit;
        }
    }

    public static function handle_manual_regeneration() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_regenerate_facebook_feed');
        
        // Manual generation is synchronous to give feedback
        self::generate_feed_to_file();
        
        wp_redirect(admin_url('admin.php?page=mockup-generator-settings&facebook_feed_updated=1'));
        exit;
    }

    private static function serve_file($path) {
        if (!file_exists($path)) {
            wp_die('Feed file not found.');
        }
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    public static function generate_feed_to_file() {
        // Increase limits for large feed
        @set_time_limit(1200); // 20 minutes
        @ini_set('memory_limit', '1024M'); // 1GB

        $path = self::get_feed_file_path();
        $temp_path = $path . '.tmp';
        
        $handle = fopen($temp_path, 'w');
        if (!$handle) {
            delete_transient('mg_facebook_feed_regenerating'); // Clear lock on failure
            return false;
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
        fwrite($handle, '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . PHP_EOL);
        fwrite($handle, '<channel>' . PHP_EOL);
        fwrite($handle, '<title>' . get_bloginfo('name') . ' - Facebook Catalog Feed</title>' . PHP_EOL);
        fwrite($handle, '<link>' . home_url() . '</link>' . PHP_EOL);
        fwrite($handle, '<description>WooCommerce Product Feed for Facebook Catalog</description>' . PHP_EOL);

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => 'simple', 
                ),
            ),
        );

        $product_ids = get_posts($args);

        foreach ($product_ids as $product_id) {
            $xml_chunk = self::get_product_xml($product_id);
            if ($xml_chunk) {
                fwrite($handle, $xml_chunk);
            }
        }

        fwrite($handle, '</channel>' . PHP_EOL);
        fwrite($handle, '</rss>');
        fclose($handle);

        // Atomically replace the file
        rename($temp_path, $path);
        
        update_option('mg_facebook_feed_last_update', time());
        delete_transient('mg_facebook_feed_regenerating'); // Clear lock on success
        return true;
    }

    private static function get_product_xml($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        // Use the existing Virtual Variant Manager to get configuration
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return '';
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        
        // If no virtual config, maybe export as simple product? 
        // For now, let's assume we only want virtual variants if they exist.
        if (empty($config) || empty($config['types'])) {
            // Optional: fallback to simple export if needed
            return '';
        }

        $base_sku = $product->get_sku();
        if (!$base_sku) {
            $base_sku = 'ID_' . $product_id;
        }
        
        $blog_name = get_bloginfo('name');
        $currency = get_woocommerce_currency();

        // Get custom URL mappings if any
        $custom_urls = isset($config['typeUrls']) ? $config['typeUrls'] : array();
        $output = '';

        foreach ($config['types'] as $type_slug => $type_data) {
            $type_label = isset($type_data['label']) ? $type_data['label'] : $type_slug;
            
            // Unique ID for this Variant Type
            $g_id = $base_sku . '_' . $type_slug;
            
            // Title: Product Name + Type Label
            $g_title = $product->get_name() . ' - ' . $type_label;
            
            // Description
            $g_description = $product->get_short_description();
            if (!$g_description) {
                $g_description = $product->get_description();
            }
            if (!$g_description) {
                // If specific type has description, use it
                $g_description = isset($type_data['description']) ? $type_data['description'] : $g_title;
            }
            
            // Link - Priority: Custom URL -> Parameterized URL
            $g_link = '';
            if (isset($custom_urls[$type_slug]) && !empty($custom_urls[$type_slug])) {
                $g_link = $custom_urls[$type_slug];
            } else {
                $g_link = add_query_arg('mg_type', $type_slug, $product->get_permalink());
            }

            // Image Link - Use the preview URL from config
            // Usually this is the default color for this type
            $g_image_link = isset($type_data['preview_url']) ? $type_data['preview_url'] : '';
            if (!$g_image_link) {
                $image_id = $product->get_image_id();
                $g_image_link = wp_get_attachment_url($image_id);
            }

            // Price - Base Price + Type Surcharge (if encoded in logic, but standard logic is Price is per product)
            // In config, 'price' key usually holds the final calculated price for that type if managed by plugin
            $price_val = 0.0;
            if (isset($type_data['price']) && $type_data['price'] > 0) {
                 $price_val = $type_data['price'];
            } else {
                 $price_val = (float) $product->get_price();
                 // Add any type-specific surcharge if stored separately?
                 // In current plugin logic, type price might override base price.
            }
            
            // Availability
            $g_availability = 'in_stock';
            if (!$product->is_in_stock()) {
                $g_availability = 'out_of_stock';
            }

            // XML Output (Using same Google namespace as Facebook uses it too)
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
            $output .= '<g:google_product_category>212</g:google_product_category>' . PHP_EOL;
            $output .= '<g:item_group_id>' . self::xml_sanitize($base_sku) . '</g:item_group_id>' . PHP_EOL;
            
            // Product Type: Ruházat > terméktípus > főkategória > alkategória
            $product_type_parts = array('Ruházat', $type_label);
            $terms = get_the_terms($product_id, 'product_cat');
            $main_cat = '';
            $sub_cat = '';
            if ($terms && !is_wp_error($terms)) {
                $term = reset($terms);
                foreach ($terms as $t) {
                    if ($t->parent != 0) {
                        $term = $t;
                        break;
                    }
                }
                if ($term->parent != 0) {
                    $parent = get_term($term->parent, 'product_cat');
                    $main_cat = $parent->name;
                    $sub_cat = $term->name;
                } else {
                    $main_cat = $term->name;
                }
            }
            if ($main_cat) {
                $product_type_parts[] = $main_cat;
            }
            if ($sub_cat) {
                $product_type_parts[] = $sub_cat;
            }
            $output .= '<g:product_type>' . self::xml_sanitize(implode(' > ', $product_type_parts)) . '</g:product_type>' . PHP_EOL;

            // Custom Labels
            $output .= '<g:custom_label_0>' . self::xml_sanitize($type_slug) . '</g:custom_label_0>' . PHP_EOL;
            if ($main_cat) {
                $output .= '<g:custom_label_1>' . self::xml_sanitize($main_cat) . '</g:custom_label_1>' . PHP_EOL;
            }
            if ($sub_cat) {
                $output .= '<g:custom_label_2>' . self::xml_sanitize($sub_cat) . '</g:custom_label_2>' . PHP_EOL;
            }

            // New Mandatory Fields
            $output .= '<g:identifier_exists>no</g:identifier_exists>' . PHP_EOL;
            
            // Age Group Logic
            $age_group = 'adult';
            $concat_name_check = $g_title . ' ' . $type_slug;
            if (stripos($concat_name_check, 'baba') !== false) {
                $age_group = 'infant'; 
            } elseif (stripos($concat_name_check, 'gyerek') !== false) {
                $age_group = 'kids';
            }
            $output .= '<g:age_group>' . $age_group . '</g:age_group>' . PHP_EOL;
            
            // Gender logic
            $gender = 'unisex';
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
        // Remove control characters
        $text = preg_replace('/[\x00-\x08\x0b-\x0c\x0e-\x1f]/', '', $text);
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }
}
