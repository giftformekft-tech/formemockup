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

        // CAPI (Conversions API) – szerver-oldali eseményküldés
        $settings = get_option('mg_fb_pixel_settings', array());
        if (!empty($settings['access_token'])) {
            // FB cookie-k mentése rendelés metába (thankyou oldalon már lehet, hogy nincs cookie)
            add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'save_fb_cookies_to_order'), 10, 1);
            add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'save_fb_cookies_to_order'), 10, 1);
            // Purchase CAPI – 20-as prioritás, a pixel (10) után fut
            add_action('woocommerce_thankyou', array(__CLASS__, 'send_capi_purchase'), 20, 1);
        }
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

        var _mgFbConsentGranted = false;
        function mg_fb_grant_consent() {
            if (_mgFbConsentGranted) return;
            _mgFbConsentGranted = true;
            fbq('consent', 'grant');
            fbq('track', 'PageView');
        }

        document.addEventListener('mg_gads_consent', mg_fb_grant_consent);
        document.addEventListener('rcb:consent', function() { setTimeout(mg_fb_grant_consent, 200); });

        // Fallback: ha nincs cookie banner, 1000ms után küldünk
        var _mgFbFallbackStarted = false;
        function mg_fb_start_fallback() {
            if (_mgFbFallbackStarted) return;
            _mgFbFallbackStarted = true;
            setTimeout(mg_fb_grant_consent, 1000);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', mg_fb_start_fallback);
        } else {
            mg_fb_start_fallback();
        }
        </script>
        <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?php echo $pixel_id; ?>&ev=PageView&noscript=1"
        /></noscript>
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
                if (typeof window.fbq === 'function') {
                    window.fbq('track', 'ViewContent', _mgFbViewData);
                    _mgFbViewSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fb_fire_view_content);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fb_fire_view_content, 300); });

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

        $pixel_id     = self::get_pixel_id();
        $value        = (float) $order->get_total();
        $currency     = $order->get_currency();
        $transaction_id = $order->get_order_number();

        $content_ids = array();
        $num_items   = 0;

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

            $content_ids[] = self::get_virtual_item_id($product, $type_slug, false);
            $num_items    += $item->get_quantity();
        }

        // Advanced Matching mezők normalizálása és SHA-256 hash
        $email      = strtolower(trim($order->get_billing_email()));
        $phone_raw  = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
        // Magyar számok: 06... → 36...
        if (strlen($phone_raw) === 11 && substr($phone_raw, 0, 2) === '06') {
            $phone_raw = '36' . substr($phone_raw, 2);
        }
        $first_name = strtolower(preg_replace('/\s+/', '', $order->get_billing_first_name()));
        $last_name  = strtolower(preg_replace('/\s+/', '', $order->get_billing_last_name()));
        $city       = strtolower(preg_replace('/\s+/', '', $order->get_billing_city()));
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
        ?>
        <script>
        (function() {
            <?php if (!empty($hashed)): ?>
            // Advanced Matching: re-init hashelt adatokkal a pontosabb vevőegyeztetésért
            window.fbq('init', '<?php echo $pixel_id; ?>', <?php echo wp_json_encode($hashed); ?>);
            <?php endif; ?>

            var _mgFbPurchaseData = {
                value: <?php echo number_format($value, 2, '.', ''); ?>,
                currency: '<?php echo esc_js($currency); ?>',
                content_ids: <?php echo wp_json_encode($content_ids); ?>,
                content_type: 'product',
                num_items: <?php echo (int) $num_items; ?>
            };
            var _mgFbPurchaseSent = false;

            function mg_fb_fire_purchase() {
                if (_mgFbPurchaseSent) return;
                if (typeof window.fbq === 'function') {
                    // eventID a szerver-oldali API deduplication-höz (jövőbeli CAPI-hoz)
                    window.fbq('track', 'Purchase', _mgFbPurchaseData, {eventID: '<?php echo esc_js($transaction_id); ?>'});
                    _mgFbPurchaseSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fb_fire_purchase);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fb_fire_purchase, 300); });

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
        $total       = 0;
        $num_items   = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) {
                continue;
            }

            $type_slug   = isset($cart_item['mg_product_type']) ? sanitize_key($cart_item['mg_product_type']) : '';
            $item_id     = self::get_virtual_item_id($product, $type_slug);
            $price       = (float) $product->get_price();
            $qty         = (int) $cart_item['quantity'];
            $total      += $price * $qty;
            $num_items  += $qty;
            $content_ids[] = $item_id;
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
                content_type: 'product',
                num_items: <?php echo (int) $num_items; ?>
            };
            var _mgFbCheckoutSent = false;

            function mg_fb_fire_checkout() {
                if (_mgFbCheckoutSent) return;
                if (typeof window.fbq === 'function') {
                    window.fbq('track', 'InitiateCheckout', _mgFbCheckoutData);
                    _mgFbCheckoutSent = true;
                }
            }

            document.addEventListener('mg_gads_consent', mg_fb_fire_checkout);
            document.addEventListener('rcb:consent', function() { setTimeout(mg_fb_fire_checkout, 200); });

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

    // -------------------------------------------------------------------------
    // CAPI – Conversions API (szerver-oldali eseményküldés)
    // -------------------------------------------------------------------------

    /**
     * Elmenti az _fbp és _fbc cookie-kat a rendelés metaadatai közé.
     * A thankyou oldalon esetleg már nincs cookie (redirect, cache), ezért
     * a checkout feldolgozásakor menti el.
     */
    public static function save_fb_cookies_to_order($order_or_id) {
        $order_id = is_object($order_or_id) ? $order_or_id->get_id() : (int) $order_or_id;
        $order    = wc_get_order($order_id);
        if (!$order) return;

        if (!empty($_COOKIE['_fbp'])) {
            $order->update_meta_data('_mg_fbp', sanitize_text_field($_COOKIE['_fbp']));
        }
        if (!empty($_COOKIE['_fbc'])) {
            $order->update_meta_data('_mg_fbc', sanitize_text_field($_COOKIE['_fbc']));
        }
        $order->save();
    }

    /**
     * Felépíti a CAPI user_data tömböt: hashelt vásárlóadatok + IP + UA + FB cookie-k.
     */
    private static function build_capi_user_data($order) {
        $ud = array(
            'client_ip_address' => self::get_client_ip(),
            'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        );

        // _fbp és _fbc: előnyben a rendelés metaadata (checkout alatt mentve)
        $fbp = $order->get_meta('_mg_fbp') ?: (isset($_COOKIE['_fbp']) ? sanitize_text_field($_COOKIE['_fbp']) : '');
        $fbc = $order->get_meta('_mg_fbc') ?: (isset($_COOKIE['_fbc']) ? sanitize_text_field($_COOKIE['_fbc']) : '');
        if (!empty($fbp)) $ud['fbp'] = $fbp;
        if (!empty($fbc)) $ud['fbc'] = $fbc;

        // Hashelt vásárlóadatok (SHA-256, normalizált)
        $email = strtolower(trim($order->get_billing_email()));
        $phone = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '06') {
            $phone = '36' . substr($phone, 2);
        }
        $fn      = strtolower(preg_replace('/\s+/', '', $order->get_billing_first_name()));
        $ln      = strtolower(preg_replace('/\s+/', '', $order->get_billing_last_name()));
        $city    = strtolower(preg_replace('/\s+/', '', $order->get_billing_city()));
        $zip     = strtolower(preg_replace('/\s+/', '', $order->get_billing_postcode()));
        $country = strtolower($order->get_billing_country());

        if (!empty($email))   $ud['em']      = hash('sha256', $email);
        if (!empty($phone))   $ud['ph']      = hash('sha256', $phone);
        if (!empty($fn))      $ud['fn']      = hash('sha256', $fn);
        if (!empty($ln))      $ud['ln']      = hash('sha256', $ln);
        if (!empty($city))    $ud['ct']      = hash('sha256', $city);
        if (!empty($zip))     $ud['zp']      = hash('sha256', $zip);
        if (!empty($country)) $ud['country'] = hash('sha256', $country);

        return $ud;
    }

    /**
     * Visszaadja a látogató valódi IP-jét (proxy-tudatos).
     */
    private static function get_client_ip() {
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '';
    }

    /**
     * Elküldi az eseményt a Meta Graph API-ra (szerver → szerver).
     * Non-blocking: nem lassítja az oldal betöltést.
     */
    private static function send_capi_event($event_name, $custom_data, $user_data, $event_id = '', $source_url = '') {
        $settings     = get_option('mg_fb_pixel_settings', array());
        $pixel_id     = $settings['pixel_id'] ?? '';
        $access_token = $settings['access_token'] ?? '';

        if (empty($pixel_id) || empty($access_token)) {
            return false;
        }

        $event = array(
            'event_name'       => $event_name,
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => $source_url ?: (is_ssl() ? 'https' : 'http') . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'),
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        );

        if (!empty($event_id)) {
            $event['event_id'] = (string) $event_id;
        }

        $payload = array(
            'data'         => array($event),
            'access_token' => $access_token,
        );

        $test_code = $settings['test_event_code'] ?? '';
        if (!empty($test_code)) {
            $payload['test_event_code'] = $test_code;
        }

        wp_remote_post(
            'https://graph.facebook.com/v21.0/' . rawurlencode($pixel_id) . '/events',
            array(
                'body'     => wp_json_encode($payload),
                'headers'  => array('Content-Type' => 'application/json'),
                'timeout'  => 8,
                'blocking' => false,
            )
        );

        return true;
    }

    /**
     * CAPI Purchase esemény – a köszönöm oldalon fut, a pixel event után (prio 20).
     * Az event_id = rendelésszám, megegyezik a pixel eventID-jával → deduplication.
     */
    public static function send_capi_purchase($order_id) {
        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Duplikáció-védelem: oldal-frissítésre sem küld kétszer
        if ($order->get_meta('_mg_fb_capi_purchase_sent')) return;
        $order->update_meta_data('_mg_fb_capi_purchase_sent', time());
        $order->save();

        $content_ids = array();
        $num_items   = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $type_slug   = '';
            $direct_slug = $item->get_meta('mg_product_type');
            if (!empty($direct_slug)) {
                $type_slug = sanitize_title($direct_slug);
            }
            if (empty($type_slug)) {
                $type_label = $item->get_meta(__('Terméktípus', 'mgdtp'));
                if (!$type_label) $type_label = $item->get_meta('Terméktípus');
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

            $content_ids[] = self::get_virtual_item_id($product, $type_slug, false);
            $num_items    += $item->get_quantity();
        }

        $custom_data = array(
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'num_items'    => $num_items,
        );

        self::send_capi_event(
            'Purchase',
            $custom_data,
            self::build_capi_user_data($order),
            $order->get_order_number(),
            $order->get_checkout_order_received_url()
        );
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
        ?>
        <script>
        (function() {
            var _mgFbAtcForm = document.querySelector('form.cart');
            if (!_mgFbAtcForm) return;

            _mgFbAtcForm.addEventListener('submit', function() {
                var typeInput = _mgFbAtcForm.querySelector('[name="mg_product_type"]');
                var typeSlug  = typeInput ? typeInput.value : '';
                var itemId    = typeSlug ? ('<?php echo esc_js($base_sku); ?>' + '_' + typeSlug) : '<?php echo esc_js($base_sku); ?>';

                var qtyInput = _mgFbAtcForm.querySelector('[name="quantity"]');
                var qty      = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

                var atcData = {
                    content_ids:  [itemId],
                    content_type: 'product',
                    value:        <?php echo $base_price; ?>,
                    currency:     '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    quantity:     qty
                };

                function doFbAtc() {
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
