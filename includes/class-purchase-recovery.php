<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Késleltetett vásárlásmérés – a köszönőoldalon elveszett konverziók pótlása.
 *
 * A böngészős Google Ads és Meta Purchase csak akkor tüzel, ha a rendelés a
 * köszönőoldal betöltésekor már mérhető állapotban van. Átutalásnál, utólag
 * visszaigazolt online fizetésnél vagy késve érkező IPN-nél a rendelés ilyenkor
 * még `pending`/`on-hold`, így a konverzió sosem kerül ki – szerveroldali API
 * nélkül végleg elveszik.
 *
 * Ez az osztály a checkoutkor first-party cookie-ba jegyzi a rendelést, és a
 * vásárló következő oldalletöltésekor pótolja a kimaradt eseményt, ugyanazzal a
 * tranzakcióazonosítóval / event ID-val, amit a köszönőoldali tag használt
 * volna. Így a Google és a Meta duplikációvédelme miatt dupla mérés nem
 * keletkezhet.
 */
class MG_Purchase_Recovery {
    const COOKIE = 'mg_pending_conv';
    const MAX_ENTRIES = 5;
    const MAX_AGE_DAYS = 7;

    public static function init() {
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'remember_order'), 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'remember_order'), 20, 1);
        add_action('template_redirect', array(__CLASS__, 'handle_order_received_page'));

        add_action('wp_head', array(__CLASS__, 'output_confirm_helper'), 0);
        add_action('wp_footer', array(__CLASS__, 'output_recovery_script'), 99);

        add_action('wp_ajax_mg_pending_conversions', array(__CLASS__, 'ajax_pending_conversions'));
        add_action('wp_ajax_nopriv_mg_pending_conversions', array(__CLASS__, 'ajax_pending_conversions'));
        add_action('wp_ajax_mg_conversion_fired', array(__CLASS__, 'ajax_conversion_fired'));
        add_action('wp_ajax_nopriv_mg_conversion_fired', array(__CLASS__, 'ajax_conversion_fired'));
    }

    private static function measurement_active() {
        $gads = get_option('mg_gads_settings', array());
        $meta = get_option('mg_fb_pixel_settings', array());
        return !empty($gads['conversion_id']) || !empty($meta['pixel_id']);
    }

    /* ---------------------------------------------------------------------
     * Függőben lévő rendelések nyilvántartása
     * ------------------------------------------------------------------ */

    /** @param WC_Order|int $order_or_id */
    public static function remember_order($order_or_id) {
        if (!self::measurement_active()) {
            return;
        }
        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order($order_or_id);
        if (!$order || !$order->get_id() || !$order->get_order_key()) {
            return;
        }

        $entries = self::read_entries();
        $entries[(int) $order->get_id()] = (string) $order->get_order_key();
        self::write_entries($entries);
    }

    /**
     * A köszönőoldal soha ne kerüljön statikus gyorsítótárba: onnan minden
     * látogató ugyanazt a – vagy épp semmilyen – konverziós szkriptet kapná.
     * Egyben ez az utolsó pont, ahol a függőben lévő rendelést még cookie-ba
     * tudjuk írni (a `woocommerce_thankyou` már kimenet közben fut).
     */
    public static function handle_order_received_page() {
        if (is_admin() || !function_exists('is_order_received_page') || !is_order_received_page()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();

        $order_id = absint(get_query_var('order-received'));
        if ($order_id) {
            self::remember_order($order_id);
        }
    }

    private static function read_entries() {
        if (empty($_COOKIE[self::COOKIE])) {
            return array();
        }
        $entries = array();
        foreach (explode('~', (string) wp_unslash($_COOKIE[self::COOKIE])) as $chunk) {
            $parts = explode(':', $chunk, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $id = absint($parts[0]);
            $key = preg_replace('/[^A-Za-z0-9_-]/', '', $parts[1]);
            if ($id && $key !== '') {
                $entries[$id] = $key;
            }
        }
        return $entries;
    }

    private static function write_entries(array $entries) {
        $entries = array_slice($entries, -self::MAX_ENTRIES, self::MAX_ENTRIES, true);

        $pairs = array();
        foreach ($entries as $id => $key) {
            $pairs[] = (int) $id . ':' . $key;
        }
        $value = implode('~', $pairs);

        if (!headers_sent() && function_exists('wc_setcookie')) {
            $expiry = $value === ''
                ? time() - DAY_IN_SECONDS
                : time() + (self::MAX_AGE_DAYS * DAY_IN_SECONDS);
            wc_setcookie(self::COOKIE, $value, $expiry, is_ssl(), true);
        }
        if ($value === '') {
            unset($_COOKIE[self::COOKIE]);
        } else {
            $_COOKIE[self::COOKIE] = $value;
        }
    }

    /* ---------------------------------------------------------------------
     * Frontend
     * ------------------------------------------------------------------ */

    /** Közös visszajelző segéd: rögzíti, hogy a tag ténylegesen el is sült. */
    public static function output_confirm_helper() {
        if (is_admin() || !self::measurement_active()) {
            return;
        }
        ?>
        <script>
        window.mgConvAjaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        window.mgConvFired = function(channel, orderId, orderKey) {
            try {
                var body = 'action=mg_conversion_fired&channel=' + encodeURIComponent(channel)
                    + '&order_id=' + encodeURIComponent(orderId)
                    + '&order_key=' + encodeURIComponent(orderKey);
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(window.mgConvAjaxUrl, new Blob([body], {type: 'application/x-www-form-urlencoded'}));
                } else if (window.fetch) {
                    fetch(window.mgConvAjaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: body
                    }).catch(function() {});
                }
            } catch (e) {}
        };
        </script>
        <?php
    }

    /** A függőben maradt konverziók pótlása a következő oldalletöltéskor. */
    public static function output_recovery_script() {
        if (is_admin() || !self::measurement_active()) {
            return;
        }
        // A köszönőoldalon a saját tag felel a mérésért, itt ne duplikáljunk.
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }
        if (!self::read_entries()) {
            return;
        }
        ?>
        <script>
        (function() {
            if (!window.fetch) return;
            var ajaxUrl = window.mgConvAjaxUrl;
            if (!ajaxUrl) return;

            function deliver(entry) {
                var attempts = 0;
                var googleDone = !entry.google;
                var metaDone = !entry.meta;

                var timer = setInterval(function() {
                    attempts++;

                    if (!googleDone && typeof window.gtag === 'function') {
                        if (window.mgGadsConsentGranted === true && entry.google.user_data && Object.keys(entry.google.user_data).length) {
                            window.gtag('set', 'user_data', entry.google.user_data);
                        }
                        window.gtag('event', 'purchase', entry.google.event);
                        window.mgConvFired('google', entry.order_id, entry.order_key);
                        googleDone = true;
                    }

                    // A Meta Pixel csak megadott hozzájárulás mellett mérhet.
                    if (!metaDone && window.mgFbConsentGranted === true && typeof window.fbq === 'function') {
                        window.fbq('track', 'Purchase', entry.meta.data, {eventID: entry.meta.event_id});
                        window.mgConvFired('meta', entry.order_id, entry.order_key);
                        metaDone = true;
                    }

                    if ((googleDone && metaDone) || attempts >= 20) {
                        clearInterval(timer);
                    }
                }, 500);
            }

            fetch(ajaxUrl + '?action=mg_pending_conversions', {credentials: 'same-origin'})
                .then(function(response) { return response.json(); })
                .then(function(result) {
                    if (!result || !result.success || !result.data || !result.data.pending) return;
                    result.data.pending.forEach(deliver);
                })
                .catch(function() {});
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    /**
     * A rendeléskulcs maga a titok (ugyanaz védi a köszönőoldalt is), ezért
     * nonce nélkül is csak a saját rendelését kérdezheti le a látogató.
     */
    private static function resolve_order($order_id, $order_key) {
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || $order_key === '') {
            return false;
        }
        return hash_equals((string) $order->get_order_key(), (string) $order_key) ? $order : false;
    }

    private static function is_expired($order) {
        $created = $order->get_date_created();
        return $created && $created->getTimestamp() < time() - (self::MAX_AGE_DAYS * DAY_IN_SECONDS);
    }

    public static function ajax_pending_conversions() {
        $entries = self::read_entries();
        if (!$entries) {
            wp_send_json_success(array('pending' => array()));
        }

        $gads_ready = class_exists('MG_Google_Ads_Tracking') && !empty(get_option('mg_gads_settings', array())['conversion_id']);
        $meta_ready = class_exists('MG_Facebook_Pixel') && !empty(get_option('mg_fb_pixel_settings', array())['pixel_id']);

        $pending = array();
        $remaining = array();

        foreach ($entries as $order_id => $order_key) {
            $order = self::resolve_order($order_id, $order_key);
            if (!$order || self::is_expired($order)) {
                continue;
            }

            $needs_google = $gads_ready
                && !$order->get_meta('_mg_gads_browser_fired')
                && (!class_exists('MG_Google_Ads_Reliability') || MG_Google_Ads_Reliability::is_order_eligible($order));
            $needs_meta = $meta_ready
                && !$order->get_meta('_mg_fb_browser_fired')
                && (!class_exists('MG_Facebook_Pixel_Reliability') || MG_Facebook_Pixel_Reliability::is_order_eligible($order));

            $still_waiting = ($gads_ready && !$order->get_meta('_mg_gads_browser_fired'))
                || ($meta_ready && !$order->get_meta('_mg_fb_browser_fired'));
            if ($still_waiting) {
                $remaining[$order_id] = $order_key;
            }

            if (!$needs_google && !$needs_meta) {
                continue;
            }

            $entry = array(
                'order_id' => (int) $order_id,
                'order_key' => (string) $order_key,
            );
            if ($needs_google) {
                $payload = MG_Google_Ads_Tracking::build_purchase_payload($order);
                if ($payload) {
                    $entry['google'] = array(
                        'event' => $payload['event'],
                        'user_data' => (object) $payload['user_data'],
                    );
                }
            }
            if ($needs_meta) {
                $payload = MG_Facebook_Pixel::build_purchase_payload($order);
                if ($payload) {
                    $entry['meta'] = $payload;
                }
            }
            if (isset($entry['google']) || isset($entry['meta'])) {
                $pending[] = $entry;
            }
        }

        self::write_entries($remaining);
        wp_send_json_success(array('pending' => $pending));
    }

    public static function ajax_conversion_fired() {
        $order_id = absint($_POST['order_id'] ?? 0);
        $order_key = preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_POST['order_key'] ?? ''));
        $channel = sanitize_key($_POST['channel'] ?? '');

        $order = self::resolve_order($order_id, $order_key);
        if (!$order || !in_array($channel, array('google', 'meta'), true)) {
            wp_send_json_error(array('message' => 'invalid'), 400);
        }

        $meta_key = $channel === 'google' ? '_mg_gads_browser_fired' : '_mg_fb_browser_fired';
        if (!$order->get_meta($meta_key)) {
            $order->update_meta_data($meta_key, time());
            // A köszönőoldali tag már beállítja; a pótlásnál ez jelzi a mérést.
            $rendered_key = $channel === 'google' ? '_mg_gads_browser_rendered' : '_mg_fb_browser_rendered';
            if (!$order->get_meta($rendered_key)) {
                $order->update_meta_data($rendered_key, time());
                $order->update_meta_data('_mg_conv_recovered_' . $channel, time());
            }
            $order->save();
        }

        wp_send_json_success();
    }
}
