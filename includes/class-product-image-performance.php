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
        if (!$product || !$product->get_id()) {
            return;
        }

        self::$lcp_image_id = (int) $product->get_image_id();

        add_action('wp_head', array(__CLASS__, 'output_lcp_preload'), 1);
        add_filter('woocommerce_product_get_gallery_image_ids', array(__CLASS__, 'limit_gallery_image_ids'), 20, 2);
        add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'filter_attachment_attributes'), 20, 3);
        add_filter('post_thumbnail_html', array(__CLASS__, 'inject_fetchpriority_in_thumbnail_html'), 20, 5);
    }

    public static function limit_gallery_image_ids($image_ids, $product) {
        if (!function_exists('is_product') || !is_product()) {
            return $image_ids;
        }

        if (!$product instanceof WC_Product) {
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

        // Fix 1x1 placeholder dimensions if the image is a mockup but not properly registered in the media library
        $current_w = isset($attr['width']) ? (int) $attr['width'] : 0;
        $current_h = isset($attr['height']) ? (int) $attr['height'] : 0;
        if ($current_w <= 1 || $current_h <= 1) {
            $src = isset($attr['src']) ? $attr['src'] : '';
            if ($src && strpos($src, 'mg_mockups') !== false) {
                $upload_dir = wp_upload_dir();
                $file_path = str_replace(
                    trailingslashit($upload_dir['baseurl']),
                    trailingslashit($upload_dir['basedir']),
                    strtok($src, '?')
                );
                if (file_exists($file_path)) {
                    $info = @getimagesize($file_path);
                    if (!empty($info[0]) && $info[0] > 1) {
                        $attr['width']  = $info[0];
                        $attr['height'] = $info[1];
                        // Also fix the srcset with correct sizes so it renders correctly
                        $attr['srcset'] = esc_url($src);
                        $attr['sizes']  = '(max-width: ' . $info[0] . 'px) 100vw, ' . $info[0] . 'px';
                    }
                }
            }
        }

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
        <style>
        /* Critical: override WooCommerce gallery opacity:0 so the LCP image renders immediately */
        .woocommerce-product-gallery.images{opacity:1!important;transition:none!important}
        .woocommerce-product-gallery__image{opacity:1!important;transition:none!important}
        </style>
        <?php
    }

    /**
     * Fallback: inject fetchpriority=high and loading=eager directly into the
     * rendered post-thumbnail HTML when the attachment attributes filter missed it.
     * This covers Astra / WooCommerce code paths that don't go through
     * wp_get_attachment_image() for the featured image.
     */
    public static function inject_fetchpriority_in_thumbnail_html($html, $post_id, $thumbnail_id, $size, $attr) {
        if (!function_exists('is_product') || !is_product()) {
            return $html;
        }

        if (self::$lcp_image_id <= 0 || (int) $thumbnail_id !== self::$lcp_image_id) {
            return $html;
        }

        // Inject fetchpriority=high if not already present
        if (strpos($html, 'fetchpriority') === false) {
            $html = str_replace('<img ', '<img fetchpriority="high" ', $html);
        }

        // Ensure loading=eager (override lazy if set)
        if (strpos($html, 'loading=') !== false) {
            $html = preg_replace('/loading=["\']lazy["\']/', 'loading="eager"', $html);
        } else {
            $html = str_replace('<img ', '<img loading="eager" ', $html);
        }

        return $html;
    }
}

