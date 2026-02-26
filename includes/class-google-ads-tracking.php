<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Google_Ads_Tracking
 * 
 * Kezeli a Google Ads konverziókövetést és a dinamikus remarketinget.
 * Képes a virtuális variánsok pontos (Feed szerinti) azonosítóit továbbítani.
 */
class MG_Google_Ads_Tracking {

    public static function init() {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $settings = get_option('mg_gads_settings', array(
            'conversion_id' => '',
            'purchase_label' => ''
        ));

        if (empty($settings['conversion_id'])) {
            return;
        }

        // 1. Global Site Tag (gtag.js) minden oldalra
        add_action('wp_head', array(__CLASS__, 'output_gtag_script'), 5);

        // 2. View Item (Remarketing) - Termékoldalon
        add_action('woocommerce_after_single_product', array(__CLASS__, 'output_view_item_event'), 20);

        // 3. Purchase (Conversion) - Köszönöm oldalon
        add_action('woocommerce_thankyou', array(__CLASS__, 'output_purchase_event'), 10, 1);
    }

    /**
     * Visszaadja a pontos ID-t a virtuális variánsnak megfelelően.
     */
    private static function get_virtual_item_id($product, $type_slug = '') {
        $base_id = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        
        if (empty($type_slug)) {
            $type_slug = class_exists('MG_Virtual_Variant_Manager') ? MG_Virtual_Variant_Manager::get_type_from_request() : (isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '');
        }

        if (!empty($type_slug)) {
            // Ez a formátum megegyezik a Google Merchant Feed generator logikájával
            return $base_id . '-' . $type_slug;
        }

        // Ha nincs paraméter, próbáljuk kikerülni, hogy legalább az alapvető ID átmenjen
        $catalog = get_option('mg_product_catalog', array());
        if (empty($catalog)) {
            return (string) $base_id;
        }

        // Keresünk egy alapértelmezett típust
        $first_type_slug = '';
        foreach ($catalog as $type_arr) {
            foreach ($type_arr as $slug => $data) {
                $first_type_slug = $slug;
                break 2;
            }
        }
        
        if (!empty($first_type_slug)) {
             return $base_id . '-' . $first_type_slug;
        }

        return (string) $base_id;
    }

    /**
     * Injektálja a Google tag alap szkriptet a fejlécbe.
     */
    public static function output_gtag_script() {
        $settings = get_option('mg_gads_settings');
        $conversion_id = esc_js($settings['conversion_id']);
        ?>
        <!-- Google tag (gtag.js) - Mockup Generator -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $conversion_id; ?>"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());

          gtag('config', '<?php echo $conversion_id; ?>');
        </script>
        <?php
    }

    /**
     * Injektálja a 'view_item' eseményt a termékoldalra, pontos virtuális ID-val.
     */
    public static function output_view_item_event() {
        global $product;
        if (!$product) {
            return;
        }

        $settings = get_option('mg_gads_settings');
        $conversion_id = esc_js($settings['conversion_id']);

        $item_id = self::get_virtual_item_id($product);
        
        // Dinamikus ár kiszámítása a paraméter vagy fallback alapján
        $price = (float) $product->get_price();
        $type_slug = class_exists('MG_Virtual_Variant_Manager') ? MG_Virtual_Variant_Manager::get_type_from_request() : (isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '');
        
        if (!empty($type_slug)) {
            $catalog = get_option('mg_product_catalog', array());
            foreach ($catalog as $level2) {
                if (isset($level2[$type_slug]['price']) && $level2[$type_slug]['price'] > 0) {
                    $price = (float) $level2[$type_slug]['price'];
                    break;
                }
            }
        }

        ?>
        <script>
            if (typeof gtag === 'function') {
                gtag('event', 'view_item', {
                    'send_to': '<?php echo $conversion_id; ?>',
                    'value': <?php echo number_format($price, 2, '.', ''); ?>,
                    'items': [{
                        'id': '<?php echo esc_js($item_id); ?>',
                        'google_business_vertical': 'retail'
                    }]
                });
            }
        </script>
        <?php
    }

    /**
     * Injektálja a 'purchase' eseményt a köszönöm oldalra, végigiterálva a kosáron.
     */
    public static function output_purchase_event($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $settings = get_option('mg_gads_settings');
        $conversion_id = esc_js($settings['conversion_id']);
        $purchase_label = esc_js(isset($settings['purchase_label']) ? $settings['purchase_label'] : '');
        
        $send_to = $conversion_id;
        if (!empty($purchase_label)) {
            $send_to .= '/' . $purchase_label;
        }

        $value = (float) $order->get_total();
        $currency = $order->get_currency();
        $transaction_id = $order->get_order_number();

        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            // Próbáljuk kiolvasni a típust a kosárelem metákból
            $type_slug = '';
            $type_label = $item->get_meta(__('Terméktípus', 'mgdtp'));
            
            if ($type_label) {
                // Meg kell találnunk a slug-ot a label alapján
                $catalog = get_option('mg_product_catalog', array());
                foreach ($catalog as $level2) {
                    foreach ($level2 as $slug => $data) {
                        if (isset($data['label']) && $data['label'] === $type_label) {
                            $type_slug = $slug;
                            break 2;
                        }
                    }
                }
            }

            $item_id = self::get_virtual_item_id($product, $type_slug);
            $item_price = (float) $item->get_total() / max(1, $item->get_quantity());

            $items[] = array(
                'id' => $item_id,
                'price' => number_format($item_price, 2, '.', ''),
                'quantity' => $item->get_quantity(),
                'google_business_vertical' => 'retail'
            );
        }

        ?>
        <script>
            if (typeof gtag === 'function') {
                gtag('event', 'purchase', {
                    'send_to': '<?php echo $send_to; ?>',
                    'transaction_id': '<?php echo esc_js($transaction_id); ?>',
                    'value': <?php echo number_format($value, 2, '.', ''); ?>,
                    'currency': '<?php echo esc_js($currency); ?>',
                    'items': <?php echo wp_json_encode($items); ?>
                });
            }
        </script>
        <?php
    }
}
