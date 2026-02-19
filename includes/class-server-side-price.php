<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * Simple CSS-based solution: Hide price until JavaScript sets the correct value.
 * Structured data (JSON-LD) already provides correct prices to Google bot.
 */
class MG_Server_Side_Price {

    public static function init() {
        // ALWAYS hook logic that handles the price display if parameter is present
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'modify_price_html'), 10, 2);

        if (!isset($_GET['mg_type'])) {
            return;
        }
    
        // Output inline CSS to hide price until JS loads (fallback)
        add_action('wp_head', array(__CLASS__, 'output_price_hiding_css'), 1);
        
        // Output inline JS to immediately show price after variant-display.js runs
        add_action('wp_footer', array(__CLASS__, 'output_price_reveal_js'), 999);
    }

    /**
     * Modifies the price HTML server-side if a specific variant type is requested.
     * 
     * @param string $price_html
     * @param WC_Product $product
     * @return string
     */
    public static function modify_price_html($price_html, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price_html;
        }

        // Check for mg_type parameter
        if (!isset($_GET['mg_type']) || empty($_GET['mg_type'])) {
            return $price_html;
        }

        $type_slug = sanitize_title($_GET['mg_type']);

        // Get functionality to retrieve catalog price
        if (!function_exists('mg_get_global_catalog')) {
            return $price_html;
        }

        $catalog = mg_get_global_catalog();
        if (empty($catalog) || !isset($catalog[$type_slug])) {
            return $price_html;
        }

        $type_data = $catalog[$type_slug];
        $price = isset($type_data['price']) ? (float) $type_data['price'] : 0.0;

        if ($price > 0) {
            // We return the formatted price for this variant
            return wc_price($price);
        }

        return $price_html;
    }

    public static function output_price_hiding_css() {

        if (!is_product()) {
            return;
        }
        ?>
        <style id="mg-price-hide">
            /* Hide price until JavaScript sets correct variant price */
            .product .price,
            .product_meta { 
                opacity: 0 !important;
            }
            /* Show after JS marks ready */
            .mg-variant-ready .price,
            .mg-variant-ready .product_meta {
                opacity: 1 !important;
                transition: opacity 0.2s;
            }
        </style>
        <?php
    }

    public static function output_price_reveal_js() {
        if (!is_product()) {
            return;
        }
        ?>
        <script>
        (function() {
            // Force immediate variant sync if mg_type parameter present
            var urlParams = new URLSearchParams(window.location.search);
            var mgType = urlParams.get('mg_type');
            
            if (mgType) {
                // Wait for variant-display.js to load and mark ready
                var checkReady = setInterval(function() {
                    if (document.querySelector('.mg-variant-ready')) {
                        clearInterval(checkReady);
                    }
                    // Fallback: show price after 1 second even if not marked ready
                }, 50);
                
                setTimeout(function() {
                    clearInterval(checkReady);
                    // Force show if still hidden
                    var priceEl = document.querySelector('.product .price');
                    if (priceEl && window.getComputedStyle(priceEl).opacity === '0') {
                        document.querySelector('.product').classList.add('mg-variant-ready');
                    }
                }, 1000);
            }
        })();
        </script>
        <?php
    }
}
