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
        add_filter('request', [self::class, 'resolve_base_slug_collision'], 5);
        
        // Hydrate $_GET from query_var before the rest of the logic
        add_action('template_redirect', [self::class, 'hydrate_get_parameters'], 1);

        // SEO Overrides (Canonical)
        add_filter('redirect_canonical', [self::class, 'disable_canonical_redirect_for_virtual'], 10, 2);
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

    /**
     * A virtuális szabály (termek/(.+)-(típus)) a típusra végződő alap
     * termék-URL-eket is elnyeli: a „…-polo-pulcsi” slugú termék alap URL-je
     * „…-polo” termék + „pulcsi” típus lenne, ami nem létezik, így 404.
     * Ha a rövid slug nem létezik, de a teljes igen, az alap terméket adjuk.
     */
    public static function resolve_base_slug_collision($query_vars) {
        if (empty($query_vars['mg_v_type']) || empty($query_vars['product']) || !is_string($query_vars['product'])) {
            return $query_vars;
        }
        $slug = (string) $query_vars['product'];
        $type = sanitize_title((string) $query_vars['mg_v_type']);
        if (self::product_slug_exists($slug)) {
            return $query_vars;
        }
        $full = $slug . '-' . $type;
        if (!self::product_slug_exists($full)) {
            return $query_vars;
        }
        $query_vars['product'] = $full;
        if (isset($query_vars['name'])) {
            $query_vars['name'] = $full;
        }
        unset($query_vars['mg_v_type']);
        return $query_vars;
    }

    private static function product_slug_exists($slug) {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return false;
        }
        // A get_page_by_path csatolmányt is visszaadhat: csak a termék számít.
        $post = get_page_by_path($slug, OBJECT, 'product');
        return $post instanceof WP_Post && $post->post_type === 'product';
    }

    /** A lekérdezett termék (a global $product a wp_head idején még nem megbízható). */
    public static function get_queried_product() {
        if (!function_exists('is_product') || !is_product()) {
            return null;
        }
        $product = wc_get_product(get_queried_object_id());
        return $product instanceof WC_Product ? $product : null;
    }

    /** Érvényes, a termékhez tartozó kért típus, vagy üres szöveg. */
    private static function get_requested_type($product) {
        if (!isset($_GET['mg_type']) || !class_exists('MG_Virtual_Variant_Manager')) {
            return '';
        }
        $type = sanitize_title(wp_unslash($_GET['mg_type']));
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        return isset($config['types'][$type]) ? $type : '';
    }

    public static function hydrate_get_parameters() {
        if (is_product() && get_query_var('mg_v_type')) {
            $type = sanitize_text_field(get_query_var('mg_v_type'));
            $_GET['mg_type'] = $type;
            $_REQUEST['mg_type'] = $type;
        }
    }

    public static function get_virtual_permalink($product, $type_slug) {
        if (class_exists('MG_Outlet') && MG_Outlet::is_outlet($product)) return $product->get_permalink();
        if (!$product) {
            return '';
        }
        $link = $product->get_permalink();
        $link = untrailingslashit($link);
        return $link . '-' . $type_slug . '/';
    }

    public static function override_canonical($canonical, $post = null) {
        $product = self::get_queried_product();
        if (!$product) {
            return $canonical;
        }
        // Más bejegyzés kanonikusát (pl. kapcsolódó termék) nem írjuk át.
        if ($post && is_object($post) && isset($post->ID) && (int) $post->ID !== (int) $product->get_id()) {
            return $canonical;
        }
        $type = self::get_requested_type($product);
        if ($type !== '') {
            return self::get_virtual_permalink($product, $type);
        }
        // Az alap /termek/minta/ URL ugyanazt mutatja, mint az alapértelmezett
        // típus URL-je, amit a lista, a feedek és a séma is használ: a bot is
        // a virtuális típusos URL-t kapja kanonikusnak.
        if (class_exists('MG_SEO_Meta') && MG_SEO_Meta::should_consolidate_base_url($product)) {
            $url = MG_SEO_Meta::get_product_canonical_url($product);
            if ($url !== '') {
                return $url;
            }
        }
        return $canonical;
    }

    public static function disable_canonical_redirect_for_virtual($redirect_url, $requested_url) {
        if (is_product() && get_query_var('mg_v_type')) {
            // WordPress core tries to "helpfuly" redirect unknown endpoints on single posts
            // back to the canonical URL string. We must disable this for our virtual variants
            // so they don't 301 redirect back to the `?mg_type=` base link.
            return false;
        }
        return $redirect_url;
    }

    public static function override_title($title) {
        if (class_exists('MG_Outlet') && MG_Outlet::is_outlet(get_queried_object_id())) return $title;
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
            $product = self::get_queried_product();
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
