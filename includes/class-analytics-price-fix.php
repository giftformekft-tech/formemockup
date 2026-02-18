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
        add_action('wp_footer', array(__CLASS__, 'output_cart_price_fix_script'), 999);
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

    /**
     * Output JavaScript to fix gtag price tracking on Cart page
     */
    public static function output_cart_price_fix_script() {
        if (!is_cart()) {
            return;
        }

        // Get cart items with their actual final prices
        $cart_items = array();
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $key => $item) {
                // Get the active price (which should now be correct after our previous fix)
                $product = $item['data'];
                // But specifically we want the price including surcharges, which might be stored in the object
                // or just $product->get_price(). 
                // Since we fixed MG_Cart_Pricing, display is using $product->get_price() or $cart_item_data.
                // Let's rely on what the cart says the price is.
                $price = $product->get_price();
                
                // If the product has custom fields surcharge, it might be in the cart item data but not set on the product object 
                // if it's a fresh page load and our calculations happened in before_calculate_totals.
                // Wait, before_calculate_totals sets $product->set_price(). So $product->get_price() IS correct.
                
                $variant_name = $product->get_name(); // Fallback
                
                $id = $product->get_sku() ?: 'ID_' . $product->get_id();
                // If it's a virtual variant, maybe append type info to ID?
                if (!empty($item['mg_product_type'])) {
                    $id .= '_' . $item['mg_product_type'];
                }

                $cart_items[] = array(
                    'id' => $id,
                    'price' => floatval($price),
                    'quantity' => $item['quantity'],
                    'name' => $product->get_name(),
                );
            }
        }
        
        if (empty($cart_items)) {
            return;
        }
        ?>
        <script>
        (function() {
            if (typeof window.gtag === 'undefined') {
                return;
            }

            var cartItems = <?php echo json_encode($cart_items); ?>;
            var totalValue = cartItems.reduce(function(sum, item) {
                return sum + (item.price * item.quantity);
            }, 0);

            // Wait a moment to ensure other plugins have fired their (potentially wrong) events
            setTimeout(function() {
                // clear previous ecommerce object to prevent merging
                if (typeof window.dataLayer !== 'undefined') {
                    window.dataLayer.push({ ecommerce: null });
                }

                // We'll send a 'view_cart' event with CORRECT data to ensure the latest signal is accurate.
                window.gtag('event', 'view_cart', {
                    send_to: 'GLA',
                    ecomm_pagetype: 'cart',
                    value: totalValue,
                    items: cartItems.map(function(item) {
                        return {
                            id: item.id,
                            price: item.price,
                            name: item.name,
                            quantity: item.quantity,
                            google_business_vertical: 'retail'
                        };
                    })
                });
            }, 500); // 500ms delay to override others
            
            // Also hacky fix for 'page_view' if it was already sent with bad data? 
            // We can't undo a sent event, but sending a specialized ecommerce event is the standard way to track funnels.
        })();
        </script>
        <?php
    }
}
