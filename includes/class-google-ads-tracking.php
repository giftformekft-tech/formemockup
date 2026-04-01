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

        // 1. Global Site Tag (gtag.js) betöltése Consent Mode v2-vel
        // A gtag most betöltődik az oldal elejéről (denied alapértelmezéssel),
        // a cookie banner csak a consent 'update'-et hívja, nem az inicializáló szkriptet.
        add_action('wp_head', array(__CLASS__, 'output_gtag_script'), 1);

        // 2. View Item (Remarketing) - Termékoldalon
        add_action('woocommerce_after_single_product', array(__CLASS__, 'output_view_item_event'), 20);

        // 3. Purchase (Conversion) - Köszönöm oldalon
        add_action('woocommerce_thankyou', array(__CLASS__, 'output_purchase_event'), 10, 1);

        // 4. Begin Checkout - Pénztár oldalon
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'output_begin_checkout_event'), 5);

        // 5. Add to Cart - JS esemény a termékoldalakon
        add_action('woocommerce_after_add_to_cart_button', array(__CLASS__, 'output_add_to_cart_script'), 10);

        // 6. View Cart - Kosár oldalon
        add_action('woocommerce_before_cart', array(__CLASS__, 'output_view_cart_event'), 5);
    }

    private static function get_virtual_item_id($product, $type_slug = '') {
        $base_id = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        $actual_product = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        $base_sku = $actual_product->get_sku() ? $actual_product->get_sku() : 'ID_' . $base_id;
        
        if (empty($type_slug)) {
            $type_slug = class_exists('MG_Virtual_Variant_Manager') ? MG_Virtual_Variant_Manager::get_type_from_request() : (isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '');
        }

        if (!empty($type_slug)) {
            // Ez a formátum megegyezik a Google Merchant Feed generator logikájával
            return $base_sku . '_' . $type_slug;
        }

        // Ha nincs paraméter, próbáljuk kikerülni, hogy legalább az alapvető ID átmenjen
        $catalog = get_option('mg_product_catalog', array());
        if (empty($catalog)) {
            return (string) $base_sku;
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
             return $base_sku . '_' . $first_type_slug;
        }

        return (string) $base_sku;
    }

    /**
     * Injektálja a Google tag alap szkriptet Consent Mode v2-vel.
     * A gtag.js az oldal elejesétől betöltődik, de csak beleegyezés után küld adatot.
     */
    public static function output_gtag_script() {
        $settings = get_option('mg_gads_settings');
        $conversion_id = esc_js($settings['conversion_id']);
        ?>
        <!-- Google tag (gtag.js) + Consent Mode v2 - Mockup Generator -->
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}

          // Consent Mode v2: alapból minden tiltva (GDPR-kompatibilis)
          gtag('consent', 'default', {
              'ad_storage':          'denied',
              'ad_user_data':        'denied',
              'ad_personalization':  'denied',
              'analytics_storage':   'denied',
              'wait_for_update':     2000
          });
        </script>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $conversion_id; ?>"></script>
        <script>
          gtag('js', new Date());
          // allow_enhanced_conversions:true szükséges a Bővített konverziók működéséhez
          gtag('config', '<?php echo $conversion_id; ?>', {
              'send_page_view': false,
              'allow_enhanced_conversions': true
          });
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

        // Típus meghatározása: request → frontend config alapértelmezett → üres
        $type_slug = class_exists('MG_Virtual_Variant_Manager') ? MG_Virtual_Variant_Manager::get_type_from_request() : (isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '');

        // Ha nem sikerült a request-ből kiolvasni, kérjük el a termék alapértelmezett típusát
        if (empty($type_slug) && class_exists('MG_Virtual_Variant_Manager')) {
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            if (!empty($config['types'])) {
                $type_slug = array_key_first($config['types']);
            }
        }

        $item_id = self::get_virtual_item_id($product, $type_slug);
        
        // Dinamikus ár kiszámítása
        $price = (float) $product->get_price();
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
        (function() {
            var _mgViewItemData = {
                send_to: '<?php echo $conversion_id; ?>',
                value: <?php echo number_format($price, 2, '.', ''); ?>,
                items: [{
                    id: '<?php echo esc_js($item_id); ?>',
                    google_business_vertical: 'retail'
                }]
            };

            var _mgSent = false;

            function mg_fire_view_item() {
                if (_mgSent) return;
                if (typeof window.gtag === 'function') {
                    window.gtag('event', 'view_item', _mgViewItemData);
                    _mgSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fire_view_item);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fire_view_item, 200); });
            
            var fallback = function() { setTimeout(mg_fire_view_item, 800); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fallback);
            } else {
                fallback();
            }
        })();
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

            // Próbáljuk kiolvasni a típust a rendelés elem metákból
            $type_slug = '';

            // 1. Legjobb: direkt slug meta (ezt menti el a cart pricing kód)
            $direct_slug = $item->get_meta('mg_product_type');
            if (!empty($direct_slug)) {
                $type_slug = sanitize_key($direct_slug);
            }

            // 2. Fallback: label alapú keresés
            if (empty($type_slug)) {
                $type_label = $item->get_meta(__('Terméktípus', 'mgdtp'));
                if (!$type_label) {
                    $type_label = $item->get_meta('Terméktípus');
                }
                if ($type_label) {
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
            }

            $item_id = self::get_virtual_item_id($product, $type_slug);
            $item_price = (float) $item->get_total() / max(1, $item->get_quantity());

            // GA4 standard item mezők
            $item_name = $product->get_name();
            if (!empty($type_slug)) {
                $catalog = get_option('mg_product_catalog', array());
                foreach ($catalog as $level2) {
                    if (isset($level2[$type_slug]['label'])) {
                        $item_name .= ' - ' . $level2[$type_slug]['label'];
                        break;
                    }
                }
            }

            $item_category = '';
            $terms = get_the_terms($product->get_id(), 'product_cat');
            if ($terms && !is_wp_error($terms)) {
                $item_category = reset($terms)->name;
            }

            $items[] = array(
                'id'                       => $item_id,   // Google Ads Remarketing
                'item_id'                  => $item_id,   // GA4
                'item_name'                => $item_name,
                'item_category'            => $item_category,
                'item_brand'               => get_bloginfo('name'),
                'price'                    => number_format($item_price, 2, '.', ''),
                'quantity'                 => $item->get_quantity(),
                'google_business_vertical' => 'retail',
            );
        }

        // Enhanced Conversions: vásárló adatai (Google normalizálja és hash-eli a plaintext-et)
        $customer_email = strtolower(trim($order->get_billing_email()));
        $customer_phone = $order->get_billing_phone();
        // E.164 format: csak számok + előtag
        $phone_e164 = '';
        if (!empty($customer_phone)) {
            $phone_digits = preg_replace('/[^0-9]/', '', $customer_phone);
            // Magyar számok: 06... → +36...
            if (strlen($phone_digits) === 11 && substr($phone_digits, 0, 2) === '06') {
                $phone_e164 = '+36' . substr($phone_digits, 2);
            } elseif (strlen($phone_digits) >= 11) {
                $phone_e164 = '+' . $phone_digits;
            }
        }
        $first_name   = $order->get_billing_first_name();
        $last_name    = $order->get_billing_last_name();
        $street       = $order->get_billing_address_1();
        $city         = $order->get_billing_city();
        $postal_code  = $order->get_billing_postcode();
        $country      = $order->get_billing_country(); // ISO 2-letter
        // SHA-256 csak email-hez (fallback), a többit plaintext küldjük – Google hasheli
        $hashed_email = !empty($customer_email) ? hash('sha256', $customer_email) : '';

        ?>
        <script>
        (function() {
            var _mgPurchaseData = {
                send_to: '<?php echo $send_to; ?>',
                transaction_id: '<?php echo esc_js($transaction_id); ?>',
                value: <?php echo number_format($value, 2, '.', ''); ?>,
                currency: '<?php echo esc_js($currency); ?>',
                items: <?php echo wp_json_encode($items); ?><?php if (!empty($hashed_email)): ?>,
                user_data: {
                    sha256_email_address: '<?php echo esc_js($hashed_email); ?>'
                }<?php endif; ?>
            };
            var _mgSent = false;

            function mg_fire_purchase() {
                if (_mgSent) return;
                if (typeof window.gtag === 'function') {
                    <?php
                    // Felépítjük a user_data objektumot (Google majd normalizálja/hasheli)
                    $ud_fields = [];
                    if (!empty($customer_email))  { $ud_fields[] = '"email": ' . json_encode($customer_email); }
                    if (!empty($phone_e164))      { $ud_fields[] = '"phone_number": ' . json_encode($phone_e164); }
                    $addr_fields = [];
                    if (!empty($first_name))  { $addr_fields[] = '"first_name": ' . json_encode($first_name); }
                    if (!empty($last_name))   { $addr_fields[] = '"last_name": '  . json_encode($last_name); }
                    if (!empty($street))      { $addr_fields[] = '"street": '     . json_encode($street); }
                    if (!empty($city))        { $addr_fields[] = '"city": '       . json_encode($city); }
                    if (!empty($postal_code)) { $addr_fields[] = '"postal_code": '. json_encode($postal_code); }
                    if (!empty($country))     { $addr_fields[] = '"country": '    . json_encode($country); }
                    if (!empty($addr_fields)) { $ud_fields[] = '"address": {' . implode(',', $addr_fields) . '}'; }
                    ?>
                    <?php if (!empty($ud_fields)): ?>
                    // Enhanced Conversions: user_data globálisan (Google ajánlás)
                    window.gtag('set', 'user_data', { <?php echo implode(',', $ud_fields); ?> });
                    <?php endif; ?>
                    window.gtag('event', 'purchase', _mgPurchaseData);
                    _mgSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fire_purchase);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fire_purchase, 200); });
            
            var fallback = function() { setTimeout(mg_fire_purchase, 800); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fallback);
            } else {
                fallback();
            }
        })();
        </script>
        <?php
    }

    /**
     * Begin Checkout esemény a pénztár oldalon.
     */
    public static function output_begin_checkout_event() {
        if (!WC()->cart) {
            return;
        }

        $settings = get_option('mg_gads_settings');
        $conversion_id = esc_js($settings['conversion_id']);

        $items = array();
        $total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) continue;

            $type_slug = isset($cart_item['mg_product_type']) ? sanitize_key($cart_item['mg_product_type']) : '';
            $item_id = self::get_virtual_item_id($product, $type_slug);
            $price = (float) $product->get_price();
            $qty = (int) $cart_item['quantity'];
            $total += $price * $qty;

            $items[] = array(
                'id'                     => $item_id,
                'price'                  => number_format($price, 2, '.', ''),
                'quantity'               => $qty,
                'google_business_vertical' => 'retail',
            );
        }

        if (empty($items)) {
            return;
        }
        ?>
        <script>
        (function() {
            var _mgCheckoutData = {
                send_to: '<?php echo $conversion_id; ?>',
                value: <?php echo number_format($total, 2, '.', ''); ?>,
                currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                items: <?php echo wp_json_encode($items); ?>
            };
            var _mgSent = false;

            function mg_fire_checkout() {
                if (_mgSent) return;
                if (typeof window.gtag === 'function') {
                    window.gtag('event', 'begin_checkout', _mgCheckoutData);
                    _mgSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fire_checkout);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fire_checkout, 200); });
            
            var fallback = function() { setTimeout(mg_fire_checkout, 800); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fallback);
            } else {
                fallback();
            }
        })();
        </script>
        <?php
    }

    /**
     * Add to Cart JS esemény a termékoldalakon.
     * Az mg_product_type hidden inputból olvassa ki a variánst.
     */
    public static function output_add_to_cart_script() {
        global $product;
        if (!$product) {
            return;
        }

        $settings = get_option('mg_gads_settings');
        $conversion_id = esc_js($settings['conversion_id']);
        $base_sku = $product->get_sku() ? $product->get_sku() : 'ID_' . $product->get_id();
        $base_price = (float) $product->get_price();
        $product_name = esc_js($product->get_name());
        ?>
        <script>
        (function() {
            var _mgAtcForm = document.querySelector('form.cart');
            if (!_mgAtcForm) return;

            _mgAtcForm.addEventListener('submit', function() {
                var typeInput = _mgAtcForm.querySelector('[name="mg_product_type"]');
                var typeSlug = typeInput ? typeInput.value : '';
                var itemId = typeSlug ? ('<?php echo esc_js($base_sku); ?>' + '_' + typeSlug) : '<?php echo esc_js($base_sku); ?>';

                var qtyInput = _mgAtcForm.querySelector('[name="quantity"]');
                var qty = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

                var atcData = {
                    send_to: '<?php echo $conversion_id; ?>',
                    value: <?php echo $base_price; ?>,
                    currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    items: [{
                        id: itemId,
                        quantity: qty,
                        google_business_vertical: 'retail'
                    }]
                };

                function doAtc() {
                    if (typeof window.gtag === 'function') {
                        window.gtag('event', 'add_to_cart', atcData);
                        return true;
                    }
                    return false;
                }

                if (!doAtc()) {
                    document.addEventListener('mg_gads_consent', doAtc);
                    document.addEventListener('rcb:consent', function(e) {
                        if (e.detail && e.detail.acceptedAll) { setTimeout(doAtc, 300); }
                    });
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * View Cart esemény a kosár oldalon.
     */
    public static function output_view_cart_event() {
        if (!WC()->cart) {
            return;
        }

        $settings = get_option('mg_gads_settings');
        $conversion_id = esc_js($settings['conversion_id']);

        $items = array();
        $total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) continue;

            $type_slug = isset($cart_item['mg_product_type']) ? sanitize_key($cart_item['mg_product_type']) : '';
            $item_id = self::get_virtual_item_id($product, $type_slug);
            $price = (float) $product->get_price();
            $qty = (int) $cart_item['quantity'];
            $total += $price * $qty;

            $items[] = array(
                'id'                       => $item_id,
                'price'                    => number_format($price, 2, '.', ''),
                'quantity'                 => $qty,
                'google_business_vertical' => 'retail',
            );
        }

        if (empty($items)) {
            return;
        }
        ?>
        <script>
        (function() {
            var _mgCartData = {
                send_to: '<?php echo $conversion_id; ?>',
                value: <?php echo number_format($total, 2, '.', ''); ?>,
                currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                items: <?php echo wp_json_encode($items); ?>
            };
            var _mgSent = false;

            function mg_fire_view_cart() {
                if (_mgSent) return;
                if (typeof window.gtag === 'function') {
                    window.gtag('event', 'view_cart', _mgCartData);
                    _mgSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fire_view_cart);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fire_view_cart, 200); });
            
            var fallback = function() { setTimeout(mg_fire_view_cart, 800); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fallback);
            } else {
                fallback();
            }
        })();
        </script>
        <?php
    }
}
