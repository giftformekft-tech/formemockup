<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Product_Structured_Data
 *
 * Generates Schema.org JSON-LD structured data for virtual variant products.
 * This ensures Google Merchant Center sees the correct variant prices even without JavaScript.
 *
 * A termékséma pontosan úgy írja le a virtuális típust, mint a termékfeedek:
 * SKU_típus azonosító, „Név - Típus” név, típusos URL, kép és ár, ugyanaz a
 * márka és product_type útvonal.
 */
class MG_Product_Structured_Data {

    public static function init() {
        // Output JSON-LD on product pages
        add_action('wp_head', array(__CLASS__, 'output_json_ld'), 5);

        // Output Organization JSON-LD on front page
        add_action('wp_head', array(__CLASS__, 'output_organization_schema'), 5);

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

    protected static function setting($key, $default = '') {
        if (!class_exists('MG_SEO_Meta')) {
            return $default;
        }
        $value = MG_SEO_Meta::get_setting($key);
        return $value === null ? $default : $value;
    }

    public static function get_brand_name() {
        return class_exists('MG_SEO_Meta') ? MG_SEO_Meta::get_brand_name() : get_bloginfo('name');
    }

    /**
     * Organization (OnlineStore) és WebSite séma a főoldalon. A cégadatok a
     * SEO és AI keresők beállításaiból jönnek; az üres mező kimarad.
     */
    public static function build_organization_graph() {
        $site_url = untrailingslashit(home_url('/'));
        $blog_name = (string) get_bloginfo('name');
        $name = (string) self::setting('site_name', $blog_name);
        if ($name === '') {
            $name = $blog_name;
        }

        $seo_contact = get_option('mg_seo_contact', array('email' => '', 'phone' => ''));
        $seo_contact = is_array($seo_contact) ? $seo_contact : array();
        $site_email = !empty($seo_contact['email']) ? $seo_contact['email'] : get_bloginfo('admin_email');
        $site_phone = !empty($seo_contact['phone']) ? $seo_contact['phone'] : '+36305538083';

        $organization = array(
            '@type' => 'OnlineStore',
            '@id' => $site_url . '/#organization',
            'name' => $name,
            'url' => $site_url . '/',
            'email' => $site_email,
            'telephone' => $site_phone,
        );
        if ($blog_name !== '' && $blog_name !== $name) {
            $organization['alternateName'] = $blog_name;
        }
        $legal_name = trim((string) self::setting('legal_name', 'Gift for me Kft.'));
        if ($legal_name !== '') {
            $organization['legalName'] = $legal_name;
        }
        $logo = class_exists('MG_SEO_Meta') ? MG_SEO_Meta::get_site_image() : '';
        if ($logo !== '') {
            $organization['logo'] = $logo;
        }

        $address = array_filter(array(
            'streetAddress' => trim((string) self::setting('street', 'Hunyadi utca 35.')),
            'addressLocality' => trim((string) self::setting('city', 'Nyírlugos')),
            'postalCode' => trim((string) self::setting('postal_code', '4371')),
            'addressCountry' => trim((string) self::setting('country', 'HU')),
        ), 'strlen');
        if (isset($address['streetAddress']) || isset($address['addressLocality'])) {
            $organization['address'] = array_merge(array('@type' => 'PostalAddress'), $address);
        }
        $vat_id = trim((string) self::setting('vat_id'));
        if ($vat_id !== '') {
            $organization['vatID'] = $vat_id;
        }
        $tax_id = trim((string) self::setting('tax_id'));
        if ($tax_id !== '') {
            $organization['taxID'] = $tax_id;
        }
        $same_as = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) self::setting('same_as'))), 'strlen'));
        if ($same_as) {
            $organization['sameAs'] = $same_as;
        }
        $organization['contactPoint'] = array(
            '@type' => 'ContactPoint',
            'contactType' => 'customer service',
            'email' => $site_email,
            'telephone' => $site_phone,
            'areaServed' => 'HU',
            'availableLanguage' => 'hu',
        );

        $website = array(
            '@type' => 'WebSite',
            '@id' => $site_url . '/#website',
            'name' => $name,
            'url' => $site_url . '/',
            'inLanguage' => 'hu-HU',
            'publisher' => array('@id' => $site_url . '/#organization'),
            'potentialAction' => array(
                '@type' => 'SearchAction',
                'target' => $site_url . '/?s={search_term_string}&post_type=product',
                'query-input' => 'required name=search_term_string'
            )
        );

        return array('@context' => 'https://schema.org', '@graph' => array($organization, $website));
    }

    /**
     * Output Organization and WebSite JSON-LD structured data on the front page
     */
    public static function output_organization_schema() {
        if (!is_front_page() && !is_home()) {
            return;
        }
        echo '<script type="application/ld+json">';
        echo wp_json_encode(self::build_organization_graph(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT);
        echo '</script>' . PHP_EOL;
    }

    /**
     * Output JSON-LD structured data on product pages
     */
    public static function output_json_ld() {
        if (!is_product()) {
            return;
        }

        // A global $product a wp_head idején még nem megbízható: a lekérdezett terméket használjuk.
        $product = wc_get_product(get_queried_object_id());
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
            $type_slug = $requested_type;
        } else {
            // If NO type specified, output default type OR first type
            $default_type = isset($config['default']['type']) ? $config['default']['type'] : '';
            $type_slug = ($default_type && isset($config['types'][$default_type])) ? $default_type : key($config['types']);
        }

        $structured_data = self::build_product_schema($product, $type_slug, $config['types'][$type_slug], $config);
        if ($structured_data) {
            echo '<script type="application/ld+json">';
            echo wp_json_encode($structured_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT);
            echo '</script>' . PHP_EOL;
        }
    }

    /**
     * Ugyanaz a product_type útvonal, mint a Google Merchant feedben:
     * Ruházat > típus > főkategória > alkategória.
     */
    public static function get_feed_product_type($product_id, $type_label) {
        $parts = array('Ruházat', $type_label);
        $terms = get_the_terms($product_id, 'product_cat');
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
                if ($parent && !is_wp_error($parent)) {
                    $parts[] = $parent->name;
                }
                $parts[] = $term->name;
            } else {
                $parts[] = $term->name;
            }
        }
        return implode(' > ', array_filter(array_map('strval', $parts), 'strlen'));
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

        // Product URL with type parameter or virtual permalink
        $custom_urls = isset($config['typeUrls']) ? $config['typeUrls'] : array();
        if (isset($custom_urls[$type_slug]) && !empty($custom_urls[$type_slug])) {
            $product_url = $custom_urls[$type_slug];
        } else {
            if (class_exists('MG_GMC_SEO_Optimizer')) {
                $product_url = MG_GMC_SEO_Optimizer::get_virtual_permalink($product, $type_slug);
            } else {
                $product_url = add_query_arg('mg_type', $type_slug, $product->get_permalink());
            }
        }

        // Availability
        $availability = $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';

        // Build the schema
        $schema = array(
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            '@id' => $product_url . '#product',
            'sku' => $variant_sku,
            'name' => $product_name,
            'description' => $description,
            'url' => $product_url,
            'category' => self::get_feed_product_type($product->get_id(), $type_label),
            'offers' => array(
                '@type' => 'Offer',
                'url' => $product_url,
                'priceCurrency' => $currency,
                'price' => number_format($price, 2, '.', ''),
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
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

        // Add brand – ugyanaz, mint a feedek g:brand mezője
        $schema['brand'] = array(
            '@type' => 'Brand',
            'name' => self::get_brand_name(),
        );

        // Add item condition
        $schema['itemCondition'] = 'https://schema.org/NewCondition';

        // Valódi vásárlói értékelések esetén (az oldalon is látszanak)
        if ($product->get_review_count() > 0 && (float) $product->get_average_rating() > 0) {
            $schema['aggregateRating'] = array(
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $product->get_average_rating(), 2),
                'reviewCount' => (int) $product->get_review_count(),
                'bestRating' => 5,
                'worstRating' => 1,
            );
        }

        return apply_filters('mg_product_structured_data', $schema, $product, $type_slug, $config);
    }
}
