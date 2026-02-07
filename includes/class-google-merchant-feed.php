<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Google_Merchant_Feed {

    public static function init() {
        add_action('init', array(__CLASS__, 'check_feed_request'));
    }

    public static function check_feed_request() {
        if (isset($_GET['mg_feed']) && $_GET['mg_feed'] === 'google') {
            self::generate_feed();
            exit;
        }
    }

    public static function generate_feed() {
        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . PHP_EOL;
        echo '<channel>' . PHP_EOL;
        echo '<title>' . get_bloginfo('name') . ' - Google Merchant Feed</title>' . PHP_EOL;
        echo '<link>' . home_url() . '</link>' . PHP_EOL;
        echo '<description>WooCommerce Product Feed for Google Merchant Center</description>' . PHP_EOL;

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
            self::process_product($product_id);
        }

        echo '</channel>' . PHP_EOL;
        echo '</rss>';
    }

    private static function process_product($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Use the existing Virtual Variant Manager to get configuration
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return;
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        
        // If no virtual config, maybe export as simple product? 
        // For now, let's assume we only want virtual variants if they exist.
        if (empty($config) || empty($config['types'])) {
            // Optional: fallback to simple export if needed
            return;
        }

        $base_sku = $product->get_sku();
        if (!$base_sku) {
            $base_sku = 'ID_' . $product_id;
        }
        
        $blog_name = get_bloginfo('name');
        $currency = get_woocommerce_currency();

        // Get custom URL mappings if any
        $custom_urls = isset($config['typeUrls']) ? $config['typeUrls'] : array();

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

            // XML Output
            echo '<item>' . PHP_EOL;
            echo '<g:id>' . self::xml_sanitize($g_id) . '</g:id>' . PHP_EOL;
            echo '<g:title>' . self::xml_sanitize($g_title) . '</g:title>' . PHP_EOL;
            echo '<g:description>' . self::xml_sanitize(strip_tags($g_description)) . '</g:description>' . PHP_EOL;
            echo '<g:link>' . self::xml_sanitize($g_link) . '</g:link>' . PHP_EOL;
            echo '<g:image_link>' . self::xml_sanitize($g_image_link) . '</g:image_link>' . PHP_EOL;
            echo '<g:condition>new</g:condition>' . PHP_EOL;
            echo '<g:availability>' . $g_availability . '</g:availability>' . PHP_EOL;
            echo '<g:price>' . number_format($price_val, 2, '.', '') . ' ' . $currency . '</g:price>' . PHP_EOL;
            echo '<g:brand>' . self::xml_sanitize($blog_name) . '</g:brand>' . PHP_EOL;
            echo '<g:item_group_id>' . self::xml_sanitize($base_sku) . '</g:item_group_id>' . PHP_EOL; // To group variants together
            
            // Custom Label 0 for Type slug (useful for filtering campaigns)
            echo '<g:custom_label_0>' . self::xml_sanitize($type_slug) . '</g:custom_label_0>' . PHP_EOL;

            echo '</item>' . PHP_EOL;
        }
    }

    private static function xml_sanitize($text) {
        // Remove control characters
        $text = preg_replace('/[\x00-\x08\x0b-\x0c\x0e-\x1f]/', '', $text);
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }
}
