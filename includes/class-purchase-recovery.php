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
    /**
     * JS-ből olvasható jelző a HttpOnly rendeléscookie mellé. Rendeléskulcsot
     * nem tartalmaz; csak azt mondja meg a gyorsítótárazott oldal szkriptjének,
     * hogy érdemes-e egyáltalán lekérdezni a pótolandó konverziókat.
     */
    const FLAG_COOKIE = 'mg_pending_conv_f';
    const MAX_ENTRIES = 5;
    const MAX_AGE_DAYS = 7;

    public static function init() {
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'remember_order'), 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'remember_order'), 20, 1);
        add_action('template_redirect', array(__CLASS__, 'handle_order_received_page'));
        add_action('init', array(__CLASS__, 'sync_flag_cookie'), 5);

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

        // Csak a rendelés titkos kulcsával együtt jegyezzük meg a rendelést. A
        // kulcs nélküli (vagy hibás kulcsú) köszönőoldal-URL bárki számára
        // megnyitható: ha itt eltárolnánk a rendelést, a pótló végpont a
        // vevő adataival együtt adná vissza egy idegennek.
        $order_id = absint(get_query_var('order-received'));
        $order_key = isset($_GET['key']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_GET['key'])) : '';
        $order = self::resolve_order($order_id, $order_key);
        if ($order) {
            self::remember_order($order);
        }
    }

    /**
     * A jelző pótlása olyan böngészőben, amely még a jelző bevezetése előtt
     * kapta meg a rendeléscookie-t.
     */
    public static function sync_flag_cookie() {
        if (empty($_COOKIE[self::COOKIE]) || !empty($_COOKIE[self::FLAG_COOKIE])) {
            return;
        }
        if (headers_sent() || !function_exists('wc_setcookie') || !self::measurement_active()) {
            return;
        }
        wc_setcookie(self::FLAG_COOKIE, '1', time() + (self::MAX_AGE_DAYS * DAY_IN_SECONDS), is_ssl(), false);
        $_COOKIE[self::FLAG_COOKIE] = '1';
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
            wc_setcookie(self::FLAG_COOKIE, $value === '' ? '' : '1', $expiry, is_ssl(), false);
        }
        if ($value === '') {
            unset($_COOKIE[self::COOKIE], $_COOKIE[self::FLAG_COOKIE]);
        } else {
            $_COOKIE[self::COOKIE] = $value;
            $_COOKIE[self::FLAG_COOKIE] = '1';
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

    /**
     * A függőben maradt konverziók pótlása.
     *
     * Két helyzetet kezel:
     *  - bármely későbbi oldalletöltést (a vevő visszatér a boltba);
     *  - magát a köszönőoldalt, ha a rendelés a betöltésekor még nem volt
     *    mérhető állapotú. Kártyás fizetésnél a szolgáltató visszaigazolása
     *    (IPN/callback) gyakran másodpercekkel a vevő visszaérkezése után fut
     *    be: ilyenkor a köszönőoldali tag ki sem íródik, a vevő pedig jellemzően
     *    már nem tölt le újabb oldalt. Ezért a köszönőoldal rövid ideig
     *    újrakérdezi a rendelés állapotát.
     */
    public static function output_recovery_script() {
        if (is_admin() || !self::measurement_active()) {
            return;
        }
        // A közös, cache-elhető HTML ne függjön a látogató cookie-jától: a
        // HttpOnly rendeléscookie-t az AJAX végpont olvassa az aktuális kérésből.
        // A köszönőoldal sosem gyorsítótárazott, ott megengedett az eltérő kimenet.
        $thank_you = function_exists('is_order_received_page') && is_order_received_page();
        ?>
        <script>
        (function() {
            if (!window.fetch) return;
            var ajaxUrl = window.mgConvAjaxUrl;
            if (!ajaxUrl) return;
            // Jelző nélkül nincs megjegyzett rendelés: a látogatók túlnyomó
            // többségénél így egyetlen fölösleges szerverkérés sem indul.
            if (!/(?:^|;\s*)<?php echo esc_js(self::FLAG_COOKIE); ?>=1(?:;|$)/.test(document.cookie)) return;

            var thankYou = <?php echo $thank_you ? 'true' : 'false'; ?>;
            var pollDelays = [3000, 5000, 5000, 7000, 10000, 15000, 20000, 30000, 30000];
            var polls = 0;
            var started = {};

            function deliver(entry) {
                // A köszönőoldal saját tagje által már kiírt csatornát nem duplikáljuk.
                var rendered = window.mgConvRendered || {};
                var googleKey = 'google:' + entry.order_id;
                var metaKey = 'meta:' + entry.order_id;
                var googleDone = !entry.google || rendered[googleKey] === true || started[googleKey] === true;
                var metaDone = !entry.meta || rendered[metaKey] === true || started[metaKey] === true;
                if (googleDone && metaDone) return;
                if (!googleDone) started[googleKey] = true;
                if (!metaDone) started[metaKey] = true;

                var attempts = 0;
                var timer = setInterval(function() {
                    attempts++;

                    // 500 ms-onként fut, tehát 6 kör ~3 mp: eddig várunk a
                    // hozzájárulási döntésre, hogy a konverzió ne cookie nélküli
                    // pingként menjen ki (a Consent Mode nem küldi újra).
                    var consentSettled = window.mgGadsConsentDecided === true || attempts >= 6;

                    if (!googleDone && consentSettled && typeof window.gtag === 'function') {
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

            function check() {
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=mg_pending_conversions'
                })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (!result || !result.success || !result.data) return;
                        (result.data.pending || []).forEach(deliver);
                        // Csak a köszönőoldalon, és csak amíg a fizetés
                        // visszaigazolására várunk (összesen kb. két percig).
                        if (thankYou && result.data.waiting === true && polls < pollDelays.length) {
                            setTimeout(check, pollDelays[polls++]);
                        }
                    })
                    .catch(function() {});
            }

            check();
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

    /**
     * Várható-e perceken belül automatikus fizetési visszaigazolás?
     *
     * Átutalásnál, csekknél és utánvétnél nem: ott a boltos állítja át a
     * rendelést, ezért fölösleges a köszönőoldalról újrakérdezni.
     */
    private static function is_awaiting_payment($order) {
        if (!in_array($order->get_status(), array('pending', 'on-hold', 'failed'), true)) {
            return false;
        }
        return !in_array((string) $order->get_payment_method(), array('bacs', 'cheque', 'cod'), true);
    }

    /** Lezárt rendelés: ebből már nem lesz mérhető vásárlás. */
    private static function is_closed($order) {
        return in_array($order->get_status(), array('cancelled', 'refunded', 'trash'), true);
    }

    public static function ajax_pending_conversions() {
        nocache_headers();
        $entries = self::read_entries();
        if (!$entries) {
            // Árva jelző (lejárt vagy törölt rendeléscookie) eltakarítása.
            if (!empty($_COOKIE[self::FLAG_COOKIE])) {
                self::write_entries(array());
            }
            wp_send_json_success(array('pending' => array(), 'waiting' => false));
        }

        $gads_ready = class_exists('MG_Google_Ads_Tracking') && !empty(get_option('mg_gads_settings', array())['conversion_id']);
        $meta_ready = class_exists('MG_Facebook_Pixel') && !empty(get_option('mg_fb_pixel_settings', array())['pixel_id']);

        $pending = array();
        $remaining = array();
        $waiting = false;

        foreach ($entries as $order_id => $order_key) {
            $order = self::resolve_order($order_id, $order_key);
            if (!$order || self::is_expired($order) || self::is_closed($order)) {
                continue;
            }

            // "Nyitott" csatorna: innen még nem ment ki böngészős vásárlás.
            $google_open = $gads_ready && !$order->get_meta('_mg_gads_browser_fired');
            $meta_open = $meta_ready && MG_Facebook_Pixel::browser_purchase_allowed($order);
            if (!$google_open && !$meta_open) {
                continue;
            }
            $remaining[$order_id] = $order_key;

            $needs_google = $google_open
                && (!class_exists('MG_Google_Ads_Reliability') || MG_Google_Ads_Reliability::is_order_eligible($order));
            $needs_meta = $meta_open
                && (!class_exists('MG_Facebook_Pixel_Reliability') || MG_Facebook_Pixel_Reliability::is_order_eligible($order));

            if ((($google_open && !$needs_google) || ($meta_open && !$needs_meta)) && self::is_awaiting_payment($order)) {
                $waiting = true;
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
        wp_send_json_success(array('pending' => $pending, 'waiting' => $waiting));
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
