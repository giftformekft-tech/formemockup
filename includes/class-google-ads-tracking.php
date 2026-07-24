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

        if (self::get_conversion_id() === '') {
            return;
        }

        // 1. Global Site Tag (gtag.js) betöltése Consent Mode v2-vel
        // A gtag most betöltődik az oldal elejéről (denied alapértelmezéssel),
        // a cookie banner csak a consent 'update'-et hívja, nem az inicializáló szkriptet.
        add_action('wp_head', array(__CLASS__, 'output_gtag_script'), 1);

        // 2. View Item (Remarketing) - Termékoldalon
        add_action('woocommerce_after_single_product', array(__CLASS__, 'output_view_item_event'), 20);

        // 3. Purchase (Conversion) - Köszönöm oldalon (klasszikus + block checkout)
        add_action('woocommerce_thankyou', array(__CLASS__, 'output_purchase_event'), 10, 1);

        // 4. Begin Checkout - Pénztár oldalon
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'output_begin_checkout_event'), 5);

        // 5. Add to Cart - JS esemény a termékoldalakon
        add_action('woocommerce_after_add_to_cart_button', array(__CLASS__, 'output_add_to_cart_script'), 10);

        // 6. View Cart - Kosár oldalon
        add_action('woocommerce_before_cart', array(__CLASS__, 'output_view_cart_event'), 5);
    }

    private static function get_conversion_id() {
        $settings = get_option('mg_gads_settings', array());
        $conversion_id = strtoupper(trim((string) ($settings['conversion_id'] ?? '')));
        return preg_match('/^AW-[0-9]+$/', $conversion_id) ? $conversion_id : '';
    }

    /**
     * @param bool $allow_request_fallback false on thank-you page to avoid URL-based type guessing
     */
    private static function get_virtual_item_id($product, $type_slug = '', $allow_request_fallback = true) {
        $base_id = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        $actual_product = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        $base_sku = $actual_product->get_sku() ? $actual_product->get_sku() : 'ID_' . $base_id;

        if (empty($type_slug) && $allow_request_fallback) {
            $type_slug = class_exists('MG_Virtual_Variant_Manager') ? MG_Virtual_Variant_Manager::get_type_from_request() : (isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '');
        }

        if (!empty($type_slug)) {
            // Ez a formátum megegyezik a Google Merchant Feed generator logikájával
            return $base_sku . '_' . $type_slug;
        }

        return (string) $base_sku;
    }

    /**
     * Injektálja a Google tag alap szkriptet Consent Mode v2-vel.
     * A gtag.js az oldal elejétől betöltődik. Tiltott consent mellett csak a
     * Consent Mode modellezéséhez használható, cookie nélküli jeleket küldi.
     */
    public static function output_gtag_script() {
        $conversion_id = self::get_conversion_id();
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
          // Megőrzi a hirdetési kattintási paramétert az azonos domainen belüli
          // navigációban akkor is, amikor hirdetési cookie nem írható.
          gtag('set', 'url_passthrough', true);
          window.mgGadsConsentGranted = false;
          // Külön jelzi, hogy a látogató *döntött*-e már: a granted=false önmagában
          // nem különböztethető meg az alapértelmezett, még el nem dőlt állapottól.
          window.mgGadsConsentDecided = false;

          // Persist the choice so checkout can attach consent to the order.
          window.mgGadsSetConsent = function(granted) {
              var state = granted ? 'granted' : 'denied';
              window.mgGadsConsentGranted = !!granted;
              window.mgGadsConsentDecided = true;
              gtag('consent', 'update', {
                  'ad_storage': state,
                  'ad_user_data': state,
                  'ad_personalization': state,
                  'analytics_storage': state
              });
              document.cookie = 'mg_gads_consent=' + state + ';path=/;max-age=31536000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
          };
          document.addEventListener('mg_gads_consent', function(event) {
              var detail = event ? event.detail : null;
              var granted = true; // Visszafelé kompatibilis a korábbi, detail nélküli eseménnyel.
              if (detail === false || detail === 'denied') {
                  granted = false;
              } else if (detail && typeof detail === 'object') {
                  if (typeof detail.granted !== 'undefined') {
                      granted = !!detail.granted;
                  } else if (typeof detail.marketing !== 'undefined') {
                      granted = !!detail.marketing;
                  }
              }
              window.mgGadsSetConsent(granted);
          });
          document.addEventListener('rcb:consent', function(event) {
              if (event.detail && typeof event.detail.acceptedAll !== 'undefined') {
                  window.mgGadsSetConsent(!!event.detail.acceptedAll);
              }
          });
          var mgConsentMatch = document.cookie.match(/(?:^|; )mg_gads_consent=(granted|denied)/);
          if (mgConsentMatch) {
              window.mgGadsSetConsent(mgConsentMatch[1] === 'granted');
          }
        </script>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($conversion_id); ?>"></script>
        <script>
          gtag('js', new Date());
          // allow_enhanced_conversions:true szükséges a Bővített konverziók működéséhez
          // send_page_view: true szükséges az Enhanced Conversions session-linkinghez
          gtag('config', '<?php echo esc_js($conversion_id); ?>', {
              'send_page_view': true,
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

        $conversion_id = self::get_conversion_id();

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
                send_to: '<?php echo esc_js($conversion_id); ?>',
                value: <?php echo number_format($price, 2, '.', ''); ?>,
                currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                items: [{
                    id: '<?php echo esc_js($item_id); ?>',
                    item_id: '<?php echo esc_js($item_id); ?>',
                    item_name: '<?php echo esc_js($product->get_name()); ?>',
                    price: <?php echo number_format($price, 2, '.', ''); ?>,
                    quantity: 1,
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
     * Összeállítja a 'purchase' esemény teljes adatcsomagját.
     *
     * A köszönőoldali tag és a késleltetett (recovery) mérés is ezt használja,
     * így a két úton pontosan ugyanaz az érték, tranzakcióazonosító és
     * kosáradat megy ki.
     *
     * @return array{event: array, user_data: array}|array üres tömb, ha nem mérhető
     */
    public static function build_purchase_payload($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        if (!$order || self::get_conversion_id() === '') {
            return array();
        }

        $settings = get_option('mg_gads_settings');
        $conversion_id = self::get_conversion_id();
        $purchase_label = trim((string) ($settings['purchase_label'] ?? ''));

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
                // sanitize_title matches how the catalog keys and feed IDs are built
                $type_slug = sanitize_title($direct_slug);
            }

            // 2. Fallback: label alapú keresés a feed-del azonos katalógusban
            if (empty($type_slug)) {
                $type_label = $item->get_meta(__('Terméktípus', 'mgdtp'));
                if (!$type_label) {
                    $type_label = $item->get_meta('Terméktípus');
                }
                if ($type_label) {
                    // Először a MG_Variant_Display_Manager katalógusából keresünk (feed-del azonos forrás)
                    $catalog_flat = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
                    foreach ($catalog_flat as $raw_slug => $data) {
                        if (isset($data['label']) && $data['label'] === $type_label) {
                            $type_slug = sanitize_title($raw_slug);
                            break;
                        }
                    }
                    // Ha nem találtunk, próbáljuk a legacy mg_product_catalog struktúrát
                    if (empty($type_slug)) {
                        $catalog = get_option('mg_product_catalog', array());
                        foreach ($catalog as $level2) {
                            foreach ($level2 as $slug => $data) {
                                if (isset($data['label']) && $data['label'] === $type_label) {
                                    $type_slug = sanitize_title($slug);
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

            $item_id = self::get_virtual_item_id($product, $type_slug, false);
            $item_price = (float) $item->get_subtotal() / max(1, $item->get_quantity());

            // GA4 standard item mezők
            $item_name = $product->get_name();
            if (!empty($type_slug)) {
                $type_label = '';
                $catalog_flat = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
                if (isset($catalog_flat[$type_slug]['label'])) {
                    $type_label = $catalog_flat[$type_slug]['label'];
                } else {
                    $catalog = get_option('mg_product_catalog', array());
                    foreach ($catalog as $level2) {
                        if (isset($level2[$type_slug]['label'])) {
                            $type_label = $level2[$type_slug]['label'];
                            break;
                        }
                    }
                }
                if ($type_label) {
                    $item_name .= ' - ' . $type_label;
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
            // Magyar mobil- és vezetékes számok egységes E.164 formátumban.
            if (substr($phone_digits, 0, 2) === '06') {
                $phone_e164 = '+36' . substr($phone_digits, 2);
            } elseif (substr($phone_digits, 0, 2) === '36') {
                $phone_e164 = '+' . $phone_digits;
            } elseif ($order->get_billing_country() === 'HU') {
                $phone_e164 = '+36' . ltrim($phone_digits, '0');
            } elseif (strlen($phone_digits) >= 8) {
                $phone_e164 = '+' . $phone_digits;
            }
        }
        $first_name   = $order->get_billing_first_name();
        $last_name    = $order->get_billing_last_name();
        $street       = $order->get_billing_address_1();
        $city         = $order->get_billing_city();
        $region       = $order->get_billing_state();
        $postal_code  = $order->get_billing_postcode();
        $country      = $order->get_billing_country(); // ISO 2-letter
        $event_extras = array('discount' => (float) $order->get_discount_total());
        if (!empty($settings['merchant_id'])) {
            $event_extras['aw_merchant_id'] = preg_replace('/[^0-9]/', '', $settings['merchant_id']);
        }
        if (!empty($settings['feed_country'])) {
            $event_extras['aw_feed_country'] = strtoupper(sanitize_text_field($settings['feed_country']));
        }
        if (!empty($settings['feed_language'])) {
            $event_extras['aw_feed_language'] = strtolower(sanitize_text_field($settings['feed_language']));
        }

        $user_data = array_filter(array(
            'email' => $customer_email,
            'phone_number' => $phone_e164,
        ), 'strlen');
        $user_address = array_filter(array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'street' => $street,
            'city' => $city,
            'region' => $region,
            'postal_code' => $postal_code,
            'country' => $country,
        ), 'strlen');
        if ($user_address) {
            $user_data['address'] = $user_address;
        }

        $event = array(
            'send_to' => $send_to,
            'transaction_id' => (string) $transaction_id,
            'value' => round($value, 2),
            'currency' => $currency,
            'items' => $items,
            'discount' => $event_extras['discount'],
        );
        foreach (array('aw_merchant_id', 'aw_feed_country', 'aw_feed_language') as $key) {
            if (isset($event_extras[$key])) {
                $event[$key] = $event_extras[$key];
            }
        }

        return array('event' => $event, 'user_data' => $user_data);
    }

    /**
     * Injektálja a 'purchase' eseményt a köszönöm oldalra.
     */
    public static function output_purchase_event($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (class_exists('MG_Google_Ads_Reliability') && !MG_Google_Ads_Reliability::is_order_eligible($order)) {
            // Átutalás vagy késve visszaigazolt fizetés: a rendelés még nem mérhető
            // állapotú. A késleltetett mérés pótolja, amint azzá válik.
            if (class_exists('MG_Purchase_Recovery')) {
                MG_Purchase_Recovery::remember_order($order);
            }
            return;
        }

        $payload = self::build_purchase_payload($order);
        if (!$payload) {
            return;
        }

        $order->update_meta_data('_mg_gads_browser_rendered', time());
        $order->save();

        $confirm = class_exists('MG_Purchase_Recovery')
            ? sprintf(
                "if (typeof window.mgConvFired === 'function') { window.mgConvFired('google', %d, %s); }",
                (int) $order->get_id(),
                wp_json_encode((string) $order->get_order_key())
            )
            : '';
        ?>
        <script>
        (function() {
            var _mgPurchaseData = <?php echo wp_json_encode($payload['event'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var _mgPurchaseUserData = <?php echo wp_json_encode((object) $payload['user_data'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var _mgSent = false;

            function mg_fire_purchase() {
                if (_mgSent) return;
                if (typeof window.gtag === 'function') {
                    // Enhanced Conversions: user_data globálisan (Google ajánlás)
                    if (window.mgGadsConsentGranted === true && Object.keys(_mgPurchaseUserData).length) {
                        window.gtag('set', 'user_data', _mgPurchaseUserData);
                    }
                    window.gtag('event', 'purchase', _mgPurchaseData);
                    _mgSent = true;
                    <?php echo $confirm; // phpcs:ignore -- fixed, escaped payload built above ?>
                }
            }

            document.addEventListener('mg_gads_consent', mg_fire_purchase);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fire_purchase, 300); });

            // A konverziót megvárjuk a hozzájárulási döntéssel: a `denied`
            // állapotban kiküldött purchase-t a Consent Mode nem küldi újra,
            // amikor később megérkezik a granted – az a konverzió véglegesen
            // modellezett maradna, bővített konverziók nélkül.
            var start = function() {
                if (typeof window.mgWhenConsentDecided === 'function') {
                    window.mgWhenConsentDecided(mg_fire_purchase, 3000);
                } else {
                    setTimeout(mg_fire_purchase, 1200);
                }
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', start);
            } else {
                start();
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
        $conversion_id = self::get_conversion_id();

        $items = array();
        $total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) continue;

            $type_slug = isset($cart_item['mg_product_type']) ? sanitize_key($cart_item['mg_product_type']) : '';
            $item_id = self::get_virtual_item_id($product, $type_slug);
            $qty = max(1, (int) $cart_item['quantity']);
            $line_total = isset($cart_item['line_total'])
                ? (float) $cart_item['line_total']
                : ((float) $product->get_price() * $qty);
            $price = $line_total / $qty;
            $total += $line_total;

            $items[] = array(
                'id'                       => $item_id,
                'item_id'                  => $item_id,
                'item_name'                => $product->get_name(),
                'price'                    => round($price, 2),
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
            var _mgCheckoutData = {
                send_to: '<?php echo esc_js($conversion_id); ?>',
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
        $conversion_id = self::get_conversion_id();
        $base_sku = $product->get_sku() ? $product->get_sku() : 'ID_' . $product->get_id();
        $base_price = (float) $product->get_price();
        $product_name = $product->get_name();
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
                var colorInput = _mgAtcForm.querySelector('[name="mg_color"]');
                var colorSlug = colorInput ? colorInput.value : '';
                var sizeInput = _mgAtcForm.querySelector('[name="mg_size"]');
                var sizeValue = sizeInput ? sizeInput.value : '';

                // Virtuális terméknél csak teljes, érvényes kiválasztást mérünk.
                if (_mgAtcForm.querySelector('[data-mg-virtual="1"]') && (!typeSlug || !colorSlug || !sizeValue)) {
                    return;
                }

                var unitPrice = <?php echo wp_json_encode($base_price); ?>;
                var config = window.MG_VIRTUAL_VARIANTS || null;
                if (config && config.types && config.types[typeSlug]) {
                    var typeMeta = config.types[typeSlug];
                    var configuredPrice = parseFloat(typeMeta.price);
                    if (!isNaN(configuredPrice) && configuredPrice > 0) {
                        unitPrice = configuredPrice;
                    }
                    if (typeMeta.colors && typeMeta.colors[colorSlug]) {
                        unitPrice += parseFloat(typeMeta.colors[colorSlug].surcharge) || 0;
                    }
                    if (typeMeta.size_surcharges && Object.prototype.hasOwnProperty.call(typeMeta.size_surcharges, sizeValue)) {
                        unitPrice += parseFloat(typeMeta.size_surcharges[sizeValue]) || 0;
                    }
                }
                unitPrice = Math.max(0, unitPrice);

                var atcData = {
                    send_to: '<?php echo esc_js($conversion_id); ?>',
                    value: unitPrice * qty,
                    currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    items: [{
                        id: itemId,
                        item_id: itemId,
                        item_name: <?php echo wp_json_encode($product_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                        price: unitPrice,
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
        $conversion_id = self::get_conversion_id();

        $items = array();
        $total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) continue;

            $type_slug = isset($cart_item['mg_product_type']) ? sanitize_key($cart_item['mg_product_type']) : '';
            $item_id = self::get_virtual_item_id($product, $type_slug);
            $qty = max(1, (int) $cart_item['quantity']);
            $line_total = isset($cart_item['line_total'])
                ? (float) $cart_item['line_total']
                : ((float) $product->get_price() * $qty);
            $price = $line_total / $qty;
            $total += $line_total;

            $items[] = array(
                'id'                       => $item_id,
                'item_id'                  => $item_id,
                'item_name'                => $product->get_name(),
                'price'                    => round($price, 2),
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
                send_to: '<?php echo esc_js($conversion_id); ?>',
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
