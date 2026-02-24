<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_GMC_SEO_Optimizer
 * 
 * Implements Virtual Permalinks for Mockup Generator types.
 * Translates /product-slug-ferfi-polo/ -> product-slug + ?mg_type=ferfi-polo
 * and overrides Canonical, Title, and OpenGraph/SEO tags for Google Merchant Center.
 */
class MG_GMC_SEO_Optimizer {
    
    public static function init() {
        // Rewrite rules
        add_filter('query_vars', [self::class, 'add_query_vars']);
        add_action('init', [self::class, 'add_virtual_rewrite_rules'], 10);
        
        // Hydrate $_GET from query_var before the rest of the logic
        add_action('template_redirect', [self::class, 'hydrate_get_parameters'], 1);

        // SEO Overrides (Canonical)
        add_filter('get_canonical_url', [self::class, 'override_canonical'], 999, 2);
        add_filter('wpseo_canonical', [self::class, 'override_canonical'], 999);
        add_filter('rank_math/frontend/canonical', [self::class, 'override_canonical'], 999);

        // SEO Overrides (Title)
        add_filter('wpseo_title', [self::class, 'override_title'], 999);
        add_filter('rank_math/frontend/title', [self::class, 'override_title'], 999);
        add_filter('wpseo_opengraph_title', [self::class, 'override_title'], 999);
        add_filter('rank_math/opengraph/facebook/title', [self::class, 'override_title'], 999);
        
        // SEO Overrides (OG URL)
        add_filter('wpseo_opengraph_url', [self::class, 'override_canonical'], 999);
        add_filter('rank_math/opengraph/url', [self::class, 'override_canonical'], 999);

        // SEO Overrides (Image)
        add_filter('wpseo_opengraph_image', [self::class, 'override_image'], 999);
        add_filter('rank_math/opengraph/facebook/image', [self::class, 'override_image'], 999);
    }

    public static function add_query_vars($vars) {
        $vars[] = 'mg_v_type';
        return $vars;
    }

    public static function add_virtual_rewrite_rules() {
        // Find valid product bases
        $permalinks = wc_get_permalink_structure();
        $product_base = isset($permalinks['product_rewrite_slug']) ? trim($permalinks['product_rewrite_slug'], '/') : 'termek';
        if (empty($product_base)) {
            $product_base = 'termek';
        }

        // Gather all valid type slugs from catalog to construct a restricted regex safely
        $types = array();
        if (class_exists('MG_Variant_Display_Manager')) {
            $catalog = MG_Variant_Display_Manager::get_catalog_index();
            if (is_array($catalog)) {
                $types = array_keys($catalog);
            }
        } elseif (function_exists('mg_get_global_catalog')) {
            $catalog = mg_get_global_catalog();
            if (is_array($catalog)) {
                $types = array_keys($catalog);
            }
        }
        
        if (empty($types)) {
            $types = array('ferfi-polo', 'noi-polo', 'pulcsi', 'vaszontaska', 'bogre', 'oriasi-teabogre', 'premium-pulcsi', 'hosszu-ujju-polo', 'premium-ferfi-polo', 'premium-noi-polo');
        }

        $types_regex = implode('|', array_map('preg_quote', $types));

        // Create rule targeting the product base, e.g. termek/capybara-ferfi-polo/ -> product=capybara, mg_v_type=ferfi-polo
        $regex = '^' . $product_base . '/([^/]+)-(' . $types_regex . ')/?$';
        $redirect = 'index.php?product=$matches[1]&mg_v_type=$matches[2]';
        
        add_rewrite_rule($regex, $redirect, 'top');
    }

    public static function hydrate_get_parameters() {
        if (is_product() && get_query_var('mg_v_type')) {
            $type = sanitize_text_field(get_query_var('mg_v_type'));
            $_GET['mg_type'] = $type;
            $_REQUEST['mg_type'] = $type;
        }
    }

    public static function get_virtual_permalink($product, $type_slug) {
        if (!$product) {
            return '';
        }
        $link = $product->get_permalink();
        $link = untrailingslashit($link);
        return $link . '-' . $type_slug . '/';
    }

    public static function override_canonical($canonical, $post = null) {
        if (is_product() && isset($_GET['mg_type'])) {
            global $product;
            if ($product) {
                return self::get_virtual_permalink($product, sanitize_text_field($_GET['mg_type']));
            }
        }
        return $canonical;
    }

    public static function override_title($title) {
        if (is_admin()) return $title;

        if (is_product() && isset($_GET['mg_type'])) {
            $type_slug = sanitize_text_field($_GET['mg_type']);
            $label = self::get_type_label($type_slug);
            if ($label && strpos($title, $label) === false) {
                // Try to inject before " - Site name"
                if (strpos($title, ' - ') !== false) {
                    $parts = explode(' - ', $title);
                    if (count($parts) > 1) {
                        $site_name = array_pop($parts);
                        return implode(' - ', $parts) . ' - ' . $label . ' - ' . $site_name;
                    }
                }
                return $title . ' - ' . $label;
            }
        }
        return $title;
    }

    public static function override_image($image_url) {
        if (is_product() && isset($_GET['mg_type'])) {
            global $product;
            if ($product && class_exists('MG_Virtual_Variant_Manager')) {
                $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
                $type_slug = sanitize_text_field($_GET['mg_type']);
                if (isset($config['types'][$type_slug]['preview_url']) && !empty($config['types'][$type_slug]['preview_url'])) {
                    return $config['types'][$type_slug]['preview_url'];
                }
            }
        }
        return $image_url;
    }

    private static function get_type_label($type_slug) {
        if (class_exists('MG_Variant_Display_Manager')) {
            $catalog = MG_Variant_Display_Manager::get_catalog_index();
            if (isset($catalog[$type_slug]['label'])) {
                return $catalog[$type_slug]['label'];
            }
        }
        return ucfirst(str_replace('-', ' ', $type_slug));
    }
}
