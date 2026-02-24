<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Category_Toggle {
    public static function init() {
        add_action('pre_get_posts', [self::class, 'exclude_disabled_categories'], 9999);
        add_filter('woocommerce_product_is_visible', [self::class, 'is_product_visible'], 10, 2);
        add_action('template_redirect', [self::class, 'redirect_disabled_product'], 9999);

        // Sitemap filterek
        add_filter('wp_sitemaps_posts_query_args', [self::class, 'sitemap_exclude_disabled'], 10, 2);
        add_filter('wpseo_sitemap_entry', [self::class, 'sitemap_exclude_yoast_rankmath'], 10, 3);
        add_filter('rank_math/sitemap/entry', [self::class, 'sitemap_exclude_yoast_rankmath'], 10, 3);

        // Menü filterek
        add_filter('wp_get_nav_menu_items', [self::class, 'exclude_disabled_categories_from_menus'], 10, 3);
    }

    private static function get_disabled_categories() {
        $cats = get_option('mg_disabled_categories', []);
        return is_array($cats) ? $cats : [];
    }

    public static function exclude_disabled_categories($query) {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if (is_shop() || is_product_category() || is_product_tag() || is_search() || is_post_type_archive('product')) {
            $disabled_cats = self::get_disabled_categories();
            if (empty($disabled_cats)) {
                return;
            }

            $tax_query = $query->get('tax_query');
            if (!is_array($tax_query)) {
                $tax_query = [];
            }

            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $disabled_cats,
                'operator' => 'NOT IN',
                'include_children' => true,
            ];

            $query->set('tax_query', $tax_query);
        }
    }

    public static function is_product_visible($visible, $product_id) {
        if (!$visible) {
            return $visible;
        }

        $disabled_cats = self::get_disabled_categories();
        if (empty($disabled_cats)) {
            return $visible;
        }

        $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($product_cats)) {
            return $visible;
        }

        if (!empty(array_intersect($disabled_cats, $product_cats))) {
            return false;
        }

        return $visible;
    }

    public static function redirect_disabled_product() {
        if (!is_product()) {
            return;
        }

        $disabled_cats = self::get_disabled_categories();
        if (empty($disabled_cats)) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $product_cats = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($product_cats)) {
            return;
        }

        if (!empty(array_intersect($disabled_cats, $product_cats))) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            include get_query_template('404');
            exit;
        }
    }

    public static function sitemap_exclude_disabled($args, $post_type) {
        if ($post_type !== 'product') {
            return $args;
        }

        $disabled_cats = self::get_disabled_categories();
        if (empty($disabled_cats)) {
            return $args;
        }

        if (!isset($args['tax_query'])) {
            $args['tax_query'] = [];
        }

        $args['tax_query'][] = [
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $disabled_cats,
            'operator' => 'NOT IN',
            'include_children' => true,
        ];

        return $args;
    }

    public static function sitemap_exclude_yoast_rankmath($url, $type, $object) {
        if ($type === 'post' && isset($object->post_type) && $object->post_type === 'product') {
            $disabled_cats = self::get_disabled_categories();
            if (!empty($disabled_cats)) {
                $product_cats = wp_get_post_terms($object->ID, 'product_cat', ['fields' => 'ids']);
                if (!is_wp_error($product_cats) && !empty(array_intersect($disabled_cats, $product_cats))) {
                    return false; // Kihagyja ezt a terméket a sitemapből
                }
            }
        }
        return $url;
    }

    public static function exclude_disabled_categories_from_menus($items, $menu, $args) {
        if (is_admin()) {
            return $items;
        }

        $disabled_cats = self::get_disabled_categories();
        if (empty($disabled_cats)) {
            return $items;
        }

        $filtered_items = [];
        $hidden_parents = [];

        foreach ($items as $item) {
            // Check if this menu item is a product category and if it's disabled.
            if ($item->object === 'product_cat' && in_array((int)$item->object_id, $disabled_cats, true)) {
                $hidden_parents[] = $item->ID;
                continue;
            }

            // Check if this is a child of a hidden parent
            if (in_array((int)$item->menu_item_parent, $hidden_parents, true)) {
                $hidden_parents[] = $item->ID; // Hide its children as well
                continue;
            }

            $filtered_items[] = $item;
        }

        return $filtered_items;
    }
}
