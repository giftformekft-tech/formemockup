<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Product_Structured_Data
 * 
 * Generates Schema.org JSON-LD structured data for virtual variant products.
 * This ensures Google Merchant Center sees the correct variant prices even without JavaScript.
 */
class MG_Product_Structured_Data {

    public static function init() {
        // Output JSON-LD on product pages
        add_action('wp_head', array(__CLASS__, 'output_json_ld'), 5);
        
        // Disable WooCommerce default structured data (prevents duplicate schemas)
        add_filter('woocommerce_structured_data_product', '__return_false', 999);
        add_action('wp', array(__CLASS__, 'remove_woocommerce_structured_data'), 99);
    }
    
    /**
     * Remove WooCommerce default structured data output
     */
    public static function remove_woocommerce_structured_data() {
        // Remove WooCommerce StructuredData class hooks
        if (class_exists('WC_Structured_Data')) {
            $structured_data = WC()->structured_data;
            if ($structured_data) {
                remove_action('woocommerce_before_main_content', array($structured_data, 'generate_website_data'), 30);
                remove_action('woocommerce_before_single_product', array($structured_data, 'generate_product_data'), 60);
                remove_action('woocommerce_shop_loop', array($structured_data, 'generate_product_data'), 10);
                remove_action('wp_footer', array($structured_data, 'output_structured_data'), 10);
            }
        }
    }

    /**
     * Output JSON-LD structured data on product pages
     */
    public static function output_json_ld() {
        if (!is_product()) {
            return;
        }

        global $product;
        if (!$product) {
            return;
        }

        // Check if this product uses virtual variants
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            return;
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        
        if (empty($config) || empty($config['types'])) {
            return;
        }

        // Check if a specific type is requested via URL parameter
        $requested_type = isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '';
        
        // If specific type requested, output ONLY that type's schema
        if ($requested_type && isset($config['types'][$requested_type])) {
            $type_data = $config['types'][$requested_type];
            $structured_data = self::build_product_schema($product, $requested_type, $type_data, $config);
            if ($structured_data) {
                echo '<script type="application/ld+json">';
                echo wp_json_encode($structured_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                echo '</script>' . PHP_EOL;
            }
            return;
        }
        
        // If NO type specified, output default type OR first type
        $default_type = isset($config['default']['type']) ? $config['default']['type'] : '';
        if ($default_type && isset($config['types'][$default_type])) {
            $type_data = $config['types'][$default_type];
            $structured_data = self::build_product_schema($product, $default_type, $type_data, $config);
            if ($structured_data) {
                echo '<script type="application/ld+json">';
                echo wp_json_encode($structured_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                echo '</script>' . PHP_EOL;
            }
        } else {
            // Fallback: first available type
            $first_type_slug = key($config['types']);
            $first_type_data = $config['types'][$first_type_slug];
            $structured_data = self::build_product_schema($product, $first_type_slug, $first_type_data, $config);
            if ($structured_data) {
                echo '<script type="application/ld+json">';
                echo wp_json_encode($structured_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                echo '</script>' . PHP_EOL;
            }
        }
    }

    /**
     * Build Schema.org Product structure for a specific variant type
     */
    private static function build_product_schema($product, $type_slug, $type_data, $config) {
        $base_sku = $product->get_sku();
        if (!$base_sku) {
            $base_sku = 'ID_' . $product->get_id();
        }

        $currency = get_woocommerce_currency();
        
        // Variant-specific SKU (matches feed)
        $variant_sku = $base_sku . '_' . $type_slug;
        
        // Product name with type label
        $type_label = isset($type_data['label']) ? $type_data['label'] : $type_slug;
        $product_name = $product->get_name() . ' - ' . $type_label;
        
        // Description
        $description = $product->get_short_description();
        if (!$description) {
            $description = $product->get_description();
        }
        if (!$description && isset($type_data['description'])) {
            $description = $type_data['description'];
        }
        $description = wp_strip_all_tags($description);
        
        // Image - use variant preview
        $image_url = isset($type_data['preview_url']) ? $type_data['preview_url'] : '';
        if (!$image_url) {
            $image_id = $product->get_image_id();
            if ($image_id) {
                $image_url = wp_get_attachment_url($image_id);
            }
        }
        
        // Price - this MUST match the feed price
        $price = 0.0;
        if (isset($type_data['price']) && $type_data['price'] > 0) {
            $price = (float) $type_data['price'];
        } else {
            $price = (float) $product->get_price();
        }
        
        // Product URL with type parameter
        $custom_urls = isset($config['typeUrls']) ? $config['typeUrls'] : array();
        if (isset($custom_urls[$type_slug]) && !empty($custom_urls[$type_slug])) {
            $product_url = $custom_urls[$type_slug];
        } else {
            $product_url = add_query_arg('mg_type', $type_slug, $product->get_permalink());
        }
        
        // Availability
        $availability = $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        
        // Build the schema
        $schema = array(
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'sku' => $variant_sku,
            'name' => $product_name,
            'description' => $description,
            'url' => $product_url,
            'offers' => array(
                '@type' => 'Offer',
                'url' => $product_url,
                'priceCurrency' => $currency,
                'price' => number_format($price, 2, '.', ''),
                'availability' => $availability,
                'seller' => array(
                    '@type' => 'Organization',
                    'name' => get_bloginfo('name'),
                ),
            ),
        );
        
        // Add image if available
        if ($image_url) {
            $schema['image'] = $image_url;
        }
        
        // Add brand
        $schema['brand'] = array(
            '@type' => 'Brand',
            'name' => get_bloginfo('name'),
        );
        
        // Add item condition
        $schema['itemCondition'] = 'https://schema.org/NewCondition';
        
        return $schema;
    }
}
