<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Simple, safe approach: outputs inline CSS to hide price/title on initial load,
 * then inline JS to replace them with correct variant values BEFORE page paints.
 * Does NOT modify any WooCommerce internals - zero crash risk.
 */
class MG_Server_Side_Price {

    public static function init() {
        if (!isset($_GET['mg_type'])) {
            return;
        }

        // Output fix in wp_head (runs before body renders)
        add_action('wp_head', array(__CLASS__, 'output_inline_fix'), 1);
        
        // Override document <title> only
        add_filter('document_title_parts', array(__CLASS__, 'override_doc_title'), 999);
        add_filter('wpseo_title', array(__CLASS__, 'override_seo_title'), 999);
        add_filter('rank_math/frontend/title', array(__CLASS__, 'override_seo_title'), 999);
    }

    /**
     * Get variant data for the current product
     */
    private static function get_variant_data() {
        if (!is_product()) {
            return null;
        }
        
        global $post;
        if (!$post || !class_exists('MG_Virtual_Variant_Manager') || !function_exists('wc_get_product')) {
            return null;
        }

        $requested_type = sanitize_text_field($_GET['mg_type']);
        $product = wc_get_product($post->ID);
        if (!$product) {
            return null;
        }

        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        if (empty($config) || empty($config['types']) || !isset($config['types'][$requested_type])) {
            return null;
        }

        $type_data = $config['types'][$requested_type];
        $variant_price = isset($type_data['price']) && $type_data['price'] > 0 ? (float) $type_data['price'] : null;
        $type_label = isset($type_data['label']) ? $type_data['label'] : $requested_type;

        return array(
            'price' => $variant_price,
            'label' => $type_label,
            'base_name' => $product->get_name(),
            'currency' => get_woocommerce_currency_symbol(),
        );
    }

    /**
     * Output inline CSS + JS in wp_head to fix price/title before page paints
     */
    public static function output_inline_fix() {
        $data = self::get_variant_data();
        if (!$data) {
            return;
        }

        $variant_name = $data['base_name'] . ' - ' . $data['label'];
        $formatted_price = $data['price'] ? number_format($data['price'], 0, '', ' ') : null;
        ?>
        <style id="mg-price-fix">
            /* Hide price and title until JS replaces them */
            .summary .product_title,
            .summary > p.price,
            .entry-summary .product_title,
            .entry-summary > p.price {
                visibility: hidden;
            }
        </style>
        <script id="mg-price-fix-js">
        (function() {
            // Run as soon as DOM is ready (before full page load)
            function fixPriceAndTitle() {
                <?php if ($formatted_price) : ?>
                // Fix all price elements
                var priceEls = document.querySelectorAll('.summary > p.price .woocommerce-Price-amount bdi, .entry-summary > p.price .woocommerce-Price-amount bdi');
                for (var i = 0; i < priceEls.length; i++) {
                    priceEls[i].innerHTML = '<?php echo esc_js($formatted_price); ?>&nbsp;<span class="woocommerce-Price-currencySymbol"><?php echo esc_js($data['currency']); ?></span>';
                }
                <?php endif; ?>

                // Fix title
                var titleEls = document.querySelectorAll('.summary .product_title, .entry-summary .product_title');
                for (var i = 0; i < titleEls.length; i++) {
                    titleEls[i].textContent = <?php echo wp_json_encode($variant_name); ?>;
                }

                // Show everything
                var style = document.getElementById('mg-price-fix');
                if (style) style.remove();
            }

            // Run at DOMContentLoaded OR after a tiny delay if DOM is already ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixPriceAndTitle);
            } else {
                fixPriceAndTitle();
            }

            // Also run with MutationObserver for immediate detection
            var observer = new MutationObserver(function(mutations) {
                var title = document.querySelector('.summary .product_title, .entry-summary .product_title');
                if (title) {
                    observer.disconnect();
                    fixPriceAndTitle();
                }
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });
        })();
        </script>
        <?php
    }

    /**
     * Override document <title> tag (safe, no WooCommerce internals)
     */
    public static function override_doc_title($parts) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $parts;
        }
        $data = self::get_variant_data();
        if ($data && isset($parts['title'])) {
            if (strpos($parts['title'], ' - ' . $data['label']) === false) {
                $parts['title'] .= ' - ' . $data['label'];
            }
        }
        return $parts;
    }

    /**
     * Override SEO title (safe)
     */
    public static function override_seo_title($title) {
        if (!is_product() || !isset($_GET['mg_type'])) {
            return $title;
        }
        $data = self::get_variant_data();
        if ($data && strpos($title, $data['label']) === false) {
            $title .= ' - ' . $data['label'];
        }
        return $title;
    }
}
