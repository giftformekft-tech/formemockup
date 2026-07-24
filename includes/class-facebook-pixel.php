<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Facebook_Pixel
 *
 * Meta (Facebook) Pixel integrációt kezel: PageView, ViewContent, AddToCart,
 * InitiateCheckout, Purchase eseményekkel + Advanced Matching + GDPR Consent.
 * Ugyanazt az mg_gads_consent eseményt használja, mint a Google Ads modul.
 */
class MG_Facebook_Pixel {

    public static function init() {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $settings = get_option('mg_fb_pixel_settings', array('pixel_id' => ''));
        if (empty($settings['pixel_id'])) {
            return;
        }

        add_action('wp_head', array(__CLASS__, 'output_pixel_base'), 2);
        add_action('woocommerce_after_single_product', array(__CLASS__, 'output_view_content_event'), 20);
        add_action('woocommerce_thankyou', array(__CLASS__, 'output_purchase_event'), 10, 1);
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'output_initiate_checkout_event'), 5);
        add_action('woocommerce_after_add_to_cart_button', array(__CLASS__, 'output_add_to_cart_script'), 10);
    }

    private static function get_pixel_id() {
        $settings = get_option('mg_fb_pixel_settings', array('pixel_id' => ''));
        return esc_js($settings['pixel_id'] ?? '');
    }

    private static function get_virtual_item_id($product, $type_slug = '', $allow_request_fallback = true) {
        $base_id = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        $actual_product = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        $base_sku = $actual_product->get_sku() ? $actual_product->get_sku() : 'ID_' . $base_id;

        if (empty($type_slug) && $allow_request_fallback) {
            $type_slug = class_exists('MG_Virtual_Variant_Manager') ? MG_Virtual_Variant_Manager::get_type_from_request() : (isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '');
        }

        if (!empty($type_slug)) {
            return $base_sku . '_' . $type_slug;
        }

        return (string) $base_sku;
    }

    /** Return the current order only when its private order key is present. */
    private static function get_received_order() {
        if (!function_exists('wc_get_order_id_by_order_key') || empty($_GET['key'])) {
            return false;
        }

        $order_key = wc_clean(wp_unslash($_GET['key']));
        $order_id = wc_get_order_id_by_order_key($order_key);
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || !hash_equals((string) $order->get_order_key(), (string) $order_key)) {
            return false;
        }
        if (class_exists('MG_Facebook_Pixel_Reliability') && !MG_Facebook_Pixel_Reliability::is_order_eligible($order)) {
            return false;
        }
        return $order;
    }

    private static function normalize_match_text($value) {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9]/', '', $value);
    }

    private static function normalize_match_phone($phone, $country) {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        } elseif (strtoupper((string) $country) === 'HU' && strpos($digits, '06') === 0) {
            $digits = '36' . substr($digits, 2);
        } elseif (strtoupper((string) $country) === 'HU' && strpos($digits, '36') !== 0) {
            $digits = '36' . ltrim($digits, '0');
        }
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : '';
    }

    private static function external_id_source($order) {
        if ($order->get_customer_id()) {
            return 'wc_user_' . $order->get_customer_id();
        }
        $email = strtolower(trim((string) $order->get_billing_email()));
        return $email !== '' ? 'wc_guest_' . $email : '';
    }

    /** Hashed Advanced Matching data for the private order-received page. */
    private static function build_advanced_matching($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $values = array(
            'em' => strtolower(trim((string) $order->get_billing_email())),
            'ph' => self::normalize_match_phone($order->get_billing_phone(), $order->get_billing_country()),
            'fn' => self::normalize_match_text($order->get_billing_first_name()),
            'ln' => self::normalize_match_text($order->get_billing_last_name()),
            'ct' => self::normalize_match_text($order->get_billing_city()),
            'st' => self::normalize_match_text($order->get_billing_state()),
            'zp' => strtolower(preg_replace('/\s+/', '', (string) $order->get_billing_postcode())),
            'country' => strtolower((string) $order->get_billing_country()),
            'external_id' => self::external_id_source($order),
        );

        $hashed = array();
        foreach ($values as $key => $value) {
            if ($value !== '') {
                $hashed[$key] = hash('sha256', $value);
            }
        }
        return $hashed;
    }

    /**
     * Betölti a Meta Pixel alap szkriptet Consent Mode-dal.
     * Alapból visszavont beleegyezéssel indul (GDPR-kompatibilis).
     * Az mg_gads_consent esemény adja meg a beleegyezést (ugyanaz, mint Google Ads-nél).
     */
    public static function output_pixel_base() {
        $pixel_id = self::get_pixel_id();
        $advanced_matching = self::build_advanced_matching(self::get_received_order());
        ?>
        <!-- Meta Pixel (Facebook Pixel) + Consent - Mockup Generator -->
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');

        // GDPR: alapból visszavont beleegyezés – a cookie banner adja meg
        fbq('consent', 'revoke');
        fbq('init', '<?php echo $pixel_id; ?>'<?php if ($advanced_matching): ?>, <?php echo wp_json_encode($advanced_matching, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?><?php endif; ?>);

        window.mgFbConsentGranted = false;
        window.mgFbSetConsent = function(granted) {
            var state = granted ? 'granted' : 'denied';
            window.mgFbConsentGranted = !!granted;
            fbq('consent', granted ? 'grant' : 'revoke');
            document.cookie = 'mg_gads_consent=' + state + ';path=/;max-age=31536000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
            if (granted && !window.mgFbPageViewSent) {
                fbq('track', 'PageView');
                window.mgFbPageViewSent = true;
            }
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
            window.mgFbSetConsent(granted);
        });
        document.addEventListener('rcb:consent', function(event) {
            if (event.detail && typeof event.detail.acceptedAll !== 'undefined') {
                window.mgFbSetConsent(!!event.detail.acceptedAll);
            }
        });
        var mgFbConsentMatch = document.cookie.match(/(?:^|; )mg_gads_consent=(granted|denied)/);
        if (mgFbConsentMatch) {
            window.mgFbSetConsent(mgFbConsentMatch[1] === 'granted');
        }
        </script>
        <?php
    }

    /**
     * ViewContent esemény a termékoldalon – pontos virtuális variáns ID-val.
     */
    public static function output_view_content_event() {
        global $product;
        if (!$product) {
            return;
        }

        $type_slug = class_exists('MG_Virtual_Variant_Manager') ? MG_Virtual_Variant_Manager::get_type_from_request() : (isset($_GET['mg_type']) ? sanitize_text_field($_GET['mg_type']) : '');

        if (empty($type_slug) && class_exists('MG_Virtual_Variant_Manager')) {
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            if (!empty($config['types'])) {
                $type_slug = array_key_first($config['types']);
            }
        }

        $item_id = self::get_virtual_item_id($product, $type_slug);

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
            var _mgFbViewData = {
                content_ids: ['<?php echo esc_js($item_id); ?>'],
                content_type: 'product',
                content_name: '<?php echo esc_js($product->get_name()); ?>',
                value: <?php echo number_format($price, 2, '.', ''); ?>,
                currency: '<?php echo esc_js(get_woocommerce_currency()); ?>'
            };
            var _mgFbViewSent = false;

            function mg_fb_fire_view_content() {
                if (_mgFbViewSent) return;
                if (window.mgFbConsentGranted !== true) return;
                if (typeof window.fbq === 'function') {
                    window.fbq('track', 'ViewContent', _mgFbViewData);
                    _mgFbViewSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fb_fire_view_content);
            document.addEventListener('rcb:consent', function(event) {
                if (event.detail && event.detail.acceptedAll) setTimeout(mg_fb_fire_view_content, 300);
            });

            var fallback = function() { setTimeout(mg_fb_fire_view_content, 1200); };
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
     * Összeállítja a Purchase esemény adatcsomagját.
     *
     * A köszönőoldali Pixel és a késleltetett (recovery) mérés is ezt használja,
     * így mindkét úton azonos az `eventID`, ami garantálja a CAPI-val való
     * deduplikációt.
     *
     * @return array{event_id: string, data: array}|array üres tömb, ha nem mérhető
     */
    public static function build_purchase_payload($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        if (!$order) {
            return array();
        }

        $value        = (float) $order->get_total();
        $currency     = $order->get_currency();
        $transaction_id = $order->get_order_number();

        $content_ids = array();
        $contents    = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $type_slug   = '';
            $direct_slug = $item->get_meta('mg_product_type');
            if (!empty($direct_slug)) {
                $type_slug = sanitize_title($direct_slug);
            }

            if (empty($type_slug)) {
                $type_label = $item->get_meta(__('Terméktípus', 'mgdtp'));
                if (!$type_label) {
                    $type_label = $item->get_meta('Terméktípus');
                }
                if ($type_label) {
                    $catalog_flat = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
                    foreach ($catalog_flat as $raw_slug => $data) {
                        if (isset($data['label']) && $data['label'] === $type_label) {
                            $type_slug = sanitize_title($raw_slug);
                            break;
                        }
                    }
                }
            }

            $item_id = self::get_virtual_item_id($product, $type_slug, false);
            $quantity = max(1, (int) $item->get_quantity());
            $content_ids[] = $item_id;
            $contents[] = array(
                'id' => $item_id,
                'quantity' => $quantity,
                'item_price' => round((float) $item->get_total() / $quantity, 2),
            );
        }

        return array(
            'event_id' => (string) $transaction_id,
            'data' => array(
                'value' => round($value, 2),
                'currency' => $currency,
                'content_ids' => $content_ids,
                'contents' => $contents,
                'content_type' => 'product',
                'order_id' => (string) $transaction_id,
                'discount' => (float) $order->get_discount_total(),
            ),
        );
    }

    /**
     * Purchase esemény a köszönöm oldalon.
     * Advanced Matching: SHA-256 hashelt vásárló adatok a pontosabb egyeztetéshez.
     * Az eventID deduplication-t biztosít (rendelés száma).
     */
    public static function output_purchase_event($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (class_exists('MG_Facebook_Pixel_Reliability') && !MG_Facebook_Pixel_Reliability::is_order_eligible($order)) {
            // Még nem mérhető állapot (pl. átutalás): a késleltetett mérés pótolja.
            if (class_exists('MG_Purchase_Recovery')) {
                MG_Purchase_Recovery::remember_order($order);
            }
            return;
        }

        $payload = self::build_purchase_payload($order);
        if (!$payload) {
            return;
        }

        $order->update_meta_data('_mg_fb_browser_rendered', time());
        $order->save();

        $confirm = class_exists('MG_Purchase_Recovery')
            ? sprintf(
                "if (typeof window.mgConvFired === 'function') { window.mgConvFired('meta', %d, %s); }",
                (int) $order->get_id(),
                wp_json_encode((string) $order->get_order_key())
            )
            : '';
        ?>
        <script>
        (function() {
            var _mgFbPurchaseData = <?php echo wp_json_encode($payload['data'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var _mgFbPurchaseEventId = <?php echo wp_json_encode($payload['event_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var _mgFbPurchaseSent = false;

            function mg_fb_fire_purchase() {
                if (_mgFbPurchaseSent) return;
                if (window.mgFbConsentGranted !== true) return;
                if (typeof window.fbq === 'function') {
                    // Ugyanez az event ID kerül a tartós CAPI eseménybe is.
                    window.fbq('track', 'Purchase', _mgFbPurchaseData, {eventID: _mgFbPurchaseEventId});
                    _mgFbPurchaseSent = true;
                    <?php echo $confirm; // phpcs:ignore -- fixed, escaped payload built above ?>
                }
            }

            document.addEventListener('mg_gads_consent', mg_fb_fire_purchase);
            document.addEventListener('rcb:consent', function(event) {
                if (event.detail && event.detail.acceptedAll) setTimeout(mg_fb_fire_purchase, 300);
            });

            var fallback = function() { setTimeout(mg_fb_fire_purchase, 1200); };
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
     * InitiateCheckout esemény a pénztár oldalon.
     */
    public static function output_initiate_checkout_event() {
        if (!WC()->cart) {
            return;
        }

        $content_ids = array();
        $contents    = array();
        $total       = 0;
        $num_items   = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) {
                continue;
            }

            $type_slug   = isset($cart_item['mg_product_type']) ? sanitize_key($cart_item['mg_product_type']) : '';
            $item_id     = self::get_virtual_item_id($product, $type_slug);
            $qty         = (int) $cart_item['quantity'];
            $line_total  = isset($cart_item['line_total']) ? (float) $cart_item['line_total'] : ((float) $product->get_price() * $qty);
            $unit_price  = $qty > 0 ? $line_total / $qty : 0;
            $total      += $line_total;
            $num_items  += $qty;
            $content_ids[] = $item_id;
            $contents[] = array(
                'id' => $item_id,
                'quantity' => $qty,
                'item_price' => round($unit_price, 2),
            );
        }

        if (empty($content_ids)) {
            return;
        }
        ?>
        <script>
        (function() {
            var _mgFbCheckoutData = {
                value: <?php echo number_format($total, 2, '.', ''); ?>,
                currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                content_ids: <?php echo wp_json_encode($content_ids); ?>,
                contents: <?php echo wp_json_encode($contents); ?>,
                content_type: 'product',
                num_items: <?php echo (int) $num_items; ?>,
                discount: <?php echo wp_json_encode((float) WC()->cart->get_discount_total()); ?>
            };
            var _mgFbCheckoutSent = false;

            function mg_fb_fire_checkout() {
                if (_mgFbCheckoutSent) return;
                if (window.mgFbConsentGranted !== true) return;
                if (typeof window.fbq === 'function') {
                    window.fbq('track', 'InitiateCheckout', _mgFbCheckoutData);
                    _mgFbCheckoutSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fb_fire_checkout);
            document.addEventListener('rcb:consent', function(event) {
                if (event.detail && event.detail.acceptedAll) setTimeout(mg_fb_fire_checkout, 200);
            });

            var fallback = function() { setTimeout(mg_fb_fire_checkout, 800); };
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
     * AddToCart JS esemény – a termékoldal "Kosárba" gombjánál tüzel.
     * Ugyanúgy olvassa ki az mg_product_type inputot, mint a Google Ads modul.
     */
    public static function output_add_to_cart_script() {
        global $product;
        if (!$product) {
            return;
        }

        $base_sku   = $product->get_sku() ? $product->get_sku() : 'ID_' . $product->get_id();
        $base_price = (float) $product->get_price();
        $type_prices = array();
        $color_surcharges = array();
        $size_surcharges = array();
        if (class_exists('MG_Virtual_Variant_Manager')) {
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            foreach ((array) ($config['types'] ?? array()) as $slug => $type) {
                $type_prices[$slug] = !empty($type['price']) ? (float) $type['price'] : $base_price;
                $color_surcharges[$slug] = array();
                foreach ((array) ($type['colors'] ?? array()) as $color_slug => $color) {
                    $color_surcharges[$slug][$color_slug] = (float) ($color['surcharge'] ?? 0);
                }
                $size_surcharges[$slug] = (array) ($type['size_surcharges'] ?? array());
            }
        }
        ?>
        <script>
        (function() {
            var _mgFbAtcForm = document.querySelector('form.cart');
            if (!_mgFbAtcForm) return;
            var _mgFbTypePrices = <?php echo wp_json_encode($type_prices); ?>;
            var _mgFbColorSurcharges = <?php echo wp_json_encode($color_surcharges); ?>;
            var _mgFbSizeSurcharges = <?php echo wp_json_encode($size_surcharges); ?>;

            _mgFbAtcForm.addEventListener('submit', function() {
                var typeInput = _mgFbAtcForm.querySelector('[name="mg_product_type"]');
                var typeSlug  = typeInput ? typeInput.value : '';
                var itemId    = typeSlug ? ('<?php echo esc_js($base_sku); ?>' + '_' + typeSlug) : '<?php echo esc_js($base_sku); ?>';

                var qtyInput = _mgFbAtcForm.querySelector('[name="quantity"]');
                var qty      = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;
                var colorInput = _mgFbAtcForm.querySelector('[name="mg_color"]');
                var colorValue = colorInput ? colorInput.value : '';
                var sizeInput = _mgFbAtcForm.querySelector('[name="mg_size"]');
                var sizeValue = sizeInput ? sizeInput.value : '';
                if (_mgFbAtcForm.querySelector('[data-mg-virtual="1"]') && (!typeSlug || !colorValue || !sizeValue)) {
                    return;
                }
                var unitPrice = Object.prototype.hasOwnProperty.call(_mgFbTypePrices, typeSlug) ? parseFloat(_mgFbTypePrices[typeSlug]) : <?php echo wp_json_encode($base_price); ?>;
                if (_mgFbColorSurcharges[typeSlug] && Object.prototype.hasOwnProperty.call(_mgFbColorSurcharges[typeSlug], colorValue)) {
                    unitPrice += parseFloat(_mgFbColorSurcharges[typeSlug][colorValue]) || 0;
                }
                if (_mgFbSizeSurcharges[typeSlug] && Object.prototype.hasOwnProperty.call(_mgFbSizeSurcharges[typeSlug], sizeValue)) {
                    unitPrice += parseFloat(_mgFbSizeSurcharges[typeSlug][sizeValue]) || 0;
                }
                unitPrice = Math.max(0, unitPrice);

                var atcData = {
                    content_ids:  [itemId],
                    contents:     [{id: itemId, quantity: qty, item_price: unitPrice}],
                    content_type: 'product',
                    content_name: <?php echo wp_json_encode($product->get_name(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    value:        unitPrice * qty,
                    currency:     '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    quantity:     qty
                };

                function doFbAtc() {
                    if (window.mgFbConsentGranted !== true) return false;
                    if (typeof window.fbq === 'function') {
                        window.fbq('track', 'AddToCart', atcData);
                        return true;
                    }
                    return false;
                }

                if (!doFbAtc()) {
                    document.addEventListener('mg_gads_consent', doFbAtc);
                    document.addEventListener('rcb:consent', function(e) {
                        if (e.detail && e.detail.acceptedAll) { setTimeout(doFbAtc, 300); }
                    });
                }
            });
        })();
        </script>
        <?php
    }
}
