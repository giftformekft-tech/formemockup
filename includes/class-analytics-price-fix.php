<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Analytics_Price_Fix
 * 
 * Fixes Google Analytics tracking price to match selected variant.
 * Updates gtag/dataLayer when variant changes.
 */
class MG_Analytics_Price_Fix {

    public static function init() {
        add_action('wp_footer', array(__CLASS__, 'output_price_fix_script'), 999);
    }

    /**
     * Output JavaScript to fix gtag price tracking
     */
    public static function output_price_fix_script() {
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

        ?>
        <script>
        (function() {
            if (typeof jQuery === 'undefined' || typeof window.gtag === 'undefined') {
                return;
            }

            // Listen to variant changes
            jQuery(document).on('mgVariantStateChanged', function(e, data) {
                if (!data || !data.type || !data.price) {
                    return;
                }

                // Send updated view_item event with correct price
                window.gtag('event', 'view_item', {
                    send_to: 'GLA',
                    ecomm_pagetype: 'product',
                    value: parseFloat(data.price),
                    items: [{
                        id: '<?php echo esc_js($product->get_sku() ?: 'ID_' . $product->get_id()); ?>_' + data.type,
                        price: parseFloat(data.price),
                        google_business_vertical: 'retail',
                        name: '<?php echo esc_js($product->get_name()); ?> - ' + (data.typeLabel || data.type),
                        category: '<?php 
                            $terms = get_the_terms($product->get_id(), 'product_cat');
                            echo $terms && !is_wp_error($terms) ? esc_js($terms[0]->name) : '';
                        ?>'
                    }]
                });

                // Also update dataLayer if exists
                if (typeof window.dataLayer !== 'undefined') {
                    window.dataLayer.push({
                        'event': 'view_item_variant',
                        'ecommerce': {
                            'value': parseFloat(data.price),
                            'items': [{
                                'item_id': '<?php echo esc_js($product->get_sku() ?: 'ID_' . $product->get_id()); ?>_' + data.type,
                                'item_name': '<?php echo esc_js($product->get_name()); ?> - ' + (data.typeLabel || data.type),
                                'price': parseFloat(data.price),
                                'item_variant': data.type
                            }]
                        }
                    });
                }
            });
        })();
        </script>
        <?php
    }
}
