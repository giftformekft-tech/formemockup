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

    /**
     * Betölti a Meta Pixel alap szkriptet Consent Mode-dal.
     * Alapból visszavont beleegyezéssel indul (GDPR-kompatibilis).
     * Az mg_gads_consent esemény adja meg a beleegyezést (ugyanaz, mint Google Ads-nél).
     */
    public static function output_pixel_base() {
        $pixel_id = self::get_pixel_id();
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
        fbq('init', '<?php echo $pixel_id; ?>');

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

        document.addEventListener('mg_gads_consent', function() {
            window.mgFbSetConsent(true);
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
            return;
        }

        $pixel_id     = self::get_pixel_id();
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

        // Advanced Matching mezők normalizálása és SHA-256 hash
        $email      = strtolower(trim($order->get_billing_email()));
        $phone_raw  = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
        // Magyar mobil- és vezetékes számok egységes nemzetközi formátumban.
        if ($phone_raw !== '') {
            if (substr($phone_raw, 0, 2) === '06') {
                $phone_raw = '36' . substr($phone_raw, 2);
            } elseif ($order->get_billing_country() === 'HU' && substr($phone_raw, 0, 2) !== '36') {
                $phone_raw = '36' . ltrim($phone_raw, '0');
            }
        }
        $first_name = strtolower(preg_replace('/[^a-z0-9]/', '', remove_accents($order->get_billing_first_name())));
        $last_name  = strtolower(preg_replace('/[^a-z0-9]/', '', remove_accents($order->get_billing_last_name())));
        $city       = strtolower(preg_replace('/[^a-z0-9]/', '', remove_accents($order->get_billing_city())));
        $zip        = strtolower(preg_replace('/\s+/', '', $order->get_billing_postcode()));
        $country    = strtolower($order->get_billing_country());

        $hashed = array();
        if (!empty($email))      { $hashed['em']      = hash('sha256', $email); }
        if (!empty($phone_raw))  { $hashed['ph']      = hash('sha256', $phone_raw); }
        if (!empty($first_name)) { $hashed['fn']      = hash('sha256', $first_name); }
        if (!empty($last_name))  { $hashed['ln']      = hash('sha256', $last_name); }
        if (!empty($city))       { $hashed['ct']      = hash('sha256', $city); }
        if (!empty($zip))        { $hashed['zp']      = hash('sha256', $zip); }
        if (!empty($country))    { $hashed['country'] = hash('sha256', $country); }

        $order->update_meta_data('_mg_fb_browser_rendered', time());
        $order->save();
        ?>
        <script>
        (function() {
            var _mgFbPurchaseData = {
                value: <?php echo number_format($value, 2, '.', ''); ?>,
                currency: '<?php echo esc_js($currency); ?>',
                content_ids: <?php echo wp_json_encode($content_ids); ?>,
                contents: <?php echo wp_json_encode($contents); ?>,
                content_type: 'product',
                order_id: '<?php echo esc_js($transaction_id); ?>',
                discount: <?php echo wp_json_encode((float) $order->get_discount_total()); ?>
            };
            var _mgFbPurchaseSent = false;

            function mg_fb_fire_purchase() {
                if (_mgFbPurchaseSent) return;
                if (window.mgFbConsentGranted !== true) return;
                if (typeof window.fbq === 'function') {
                    <?php if (!empty($hashed)): ?>
                    window.fbq('init', '<?php echo $pixel_id; ?>', <?php echo wp_json_encode($hashed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
                    <?php endif; ?>
                    // Ugyanez az event ID kerül a tartós CAPI eseménybe is.
                    window.fbq('track', 'Purchase', _mgFbPurchaseData, {eventID: '<?php echo esc_js($transaction_id); ?>'});
                    _mgFbPurchaseSent = true;
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
        $size_surcharges = array();
        if (class_exists('MG_Virtual_Variant_Manager')) {
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            foreach ((array) ($config['types'] ?? array()) as $slug => $type) {
                $type_prices[$slug] = !empty($type['price']) ? (float) $type['price'] : $base_price;
                $size_surcharges[$slug] = (array) ($type['size_surcharges'] ?? array());
            }
        }
        ?>
        <script>
        (function() {
            var _mgFbAtcForm = document.querySelector('form.cart');
            if (!_mgFbAtcForm) return;
            var _mgFbTypePrices = <?php echo wp_json_encode($type_prices); ?>;
            var _mgFbSizeSurcharges = <?php echo wp_json_encode($size_surcharges); ?>;

            _mgFbAtcForm.addEventListener('submit', function() {
                var typeInput = _mgFbAtcForm.querySelector('[name="mg_product_type"]');
                var typeSlug  = typeInput ? typeInput.value : '';
                var itemId    = typeSlug ? ('<?php echo esc_js($base_sku); ?>' + '_' + typeSlug) : '<?php echo esc_js($base_sku); ?>';

                var qtyInput = _mgFbAtcForm.querySelector('[name="quantity"]');
                var qty      = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;
                var sizeInput = _mgFbAtcForm.querySelector('[name="mg_size"]');
                var sizeValue = sizeInput ? sizeInput.value : '';
                var unitPrice = Object.prototype.hasOwnProperty.call(_mgFbTypePrices, typeSlug) ? parseFloat(_mgFbTypePrices[typeSlug]) : <?php echo wp_json_encode($base_price); ?>;
                if (_mgFbSizeSurcharges[typeSlug] && Object.prototype.hasOwnProperty.call(_mgFbSizeSurcharges[typeSlug], sizeValue)) {
                    unitPrice += parseFloat(_mgFbSizeSurcharges[typeSlug][sizeValue]) || 0;
                }

                var atcData = {
                    content_ids:  [itemId],
                    contents:     [{id: itemId, quantity: qty, item_price: unitPrice}],
                    content_type: 'product',
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
