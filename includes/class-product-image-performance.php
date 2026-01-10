<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Product_Image_Performance {
    /**
     * Featured/LCP image ID.
     *
     * @var int
     */
    protected static $lcp_image_id = 0;

    public static function init() {
        add_action('wp', array(__CLASS__, 'setup'));
    }

    public static function setup() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());
        if (!$product || !$product->get_id() || !$product->is_type('variable')) {
            return;
        }

        self::$lcp_image_id = (int) $product->get_image_id();

        add_action('wp_head', array(__CLASS__, 'output_lcp_preload'), 1);
        add_filter('woocommerce_product_get_gallery_image_ids', array(__CLASS__, 'limit_gallery_image_ids'), 20, 2);
        add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'filter_attachment_attributes'), 20, 3);
    }

    public static function limit_gallery_image_ids($image_ids, $product) {
        if (!function_exists('is_product') || !is_product()) {
            return $image_ids;
        }

        if (!$product instanceof WC_Product || !$product->is_type('variable')) {
            return $image_ids;
        }

        if (!is_array($image_ids) || count($image_ids) <= 1) {
            return $image_ids;
        }

        return array_slice(array_values($image_ids), 0, 1);
    }

    public static function filter_attachment_attributes($attr, $attachment, $size) {
        if (!function_exists('is_product') || !is_product()) {
            return $attr;
        }

        $attachment_id = $attachment instanceof WP_Post ? (int) $attachment->ID : 0;
        if (!$attachment_id || $attachment_id !== self::$lcp_image_id) {
            return $attr;
        }

        $attr['fetchpriority'] = 'high';
        $attr['loading'] = 'eager';
        $attr['decoding'] = 'async';

        return $attr;
    }

    public static function output_lcp_preload() {
        if (self::$lcp_image_id <= 0) {
            return;
        }

        $src = wp_get_attachment_image_src(self::$lcp_image_id, 'full');
        if (!$src || empty($src[0])) {
            return;
        }

        $src_url = esc_url($src[0]);
        $srcset = wp_get_attachment_image_srcset(self::$lcp_image_id, 'full');
        $sizes = wp_get_attachment_image_sizes(self::$lcp_image_id, 'full');
        ?>
        <link rel="preload" as="image" href="<?php echo $src_url; ?>"<?php echo $srcset ? ' imagesrcset="' . esc_attr($srcset) . '"' : ''; ?><?php echo $sizes ? ' imagesizes="' . esc_attr($sizes) . '"' : ''; ?> fetchpriority="high" />
        <?php
    }
}
