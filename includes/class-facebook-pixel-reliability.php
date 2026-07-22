<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Durable, consent-aware Meta Conversions API delivery for WooCommerce. */
class MG_Facebook_Pixel_Reliability {
    const API_VERSION = 'v25.0';
    const PROCESS_HOOK = 'mg_meta_process_purchase';
    const ACTION_GROUP = 'mg-meta-capi';
    const MAX_ATTEMPTS = 6;

    const META_STATUS = '_mg_meta_capi_status';
    const META_ATTEMPTS = '_mg_meta_capi_attempts';
    const META_LAST_ERROR = '_mg_meta_capi_last_error';
    const META_TRACE_ID = '_mg_meta_capi_trace_id';
    const META_LAST_UPDATE = '_mg_meta_capi_updated_at';

    public static function init() {
        add_action('init', array(__CLASS__, 'capture_attribution'), 1);
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'save_order_context'), 20, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'save_order_context'), 20, 1);

        add_action('woocommerce_payment_complete', array(__CLASS__, 'queue_purchase'), 20, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'handle_status_change'), 20, 4);
        add_action(self::PROCESS_HOOK, array(__CLASS__, 'process_purchase'), 10, 1);

        add_action('add_meta_boxes', array(__CLASS__, 'register_order_meta_box'));
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array(__CLASS__, 'register_hpos_order_meta_box'));
    }

    public static function defaults() {
        return array(
            'pixel_id' => '',
            'access_token' => '',
            'test_event_code' => '',
            'server_side_enabled' => 0,
            'eligible_statuses' => array('processing', 'completed'),
        );
    }

    public static function get_settings() {
        $stored = get_option('mg_fb_pixel_settings', array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $had_server_flag = array_key_exists('server_side_enabled', $stored);
        $settings = wp_parse_args($stored, self::defaults());
        if (!$had_server_flag && !empty($settings['access_token'])) {
            $settings['server_side_enabled'] = 1;
        }
        $settings['eligible_statuses'] = array_values(array_unique(array_filter(array_map(
            'sanitize_key',
            (array) $settings['eligible_statuses']
        ))));
        return $settings;
    }

    public static function get_access_token() {
        if (defined('MG_META_CAPI_ACCESS_TOKEN')) {
            return trim((string) MG_META_CAPI_ACCESS_TOKEN);
        }
        return trim((string) self::get_settings()['access_token']);
    }

    public static function get_eligible_statuses() {
        $statuses = self::get_settings()['eligible_statuses'];
        return $statuses ? $statuses : array('processing', 'completed');
    }

    public static function is_order_eligible($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        return $order && in_array($order->get_status(), self::get_eligible_statuses(), true);
    }

    /** Preserve Meta click attribution in first-party cookies until checkout. */
    public static function capture_attribution() {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (empty(self::get_settings()['pixel_id']) || empty($_GET['fbclid'])) {
            return;
        }
        $fbclid = self::sanitize_meta_id(wp_unslash($_GET['fbclid']));
        if ($fbclid === '') {
            return;
        }
        $timestamp = (string) round(microtime(true) * 1000);
        self::set_cookie('mg_meta_fbclid', $fbclid, 90 * DAY_IN_SECONDS);
        self::set_cookie('mg_meta_fbclid_time', $timestamp, 90 * DAY_IN_SECONDS);
        self::set_cookie('mg_meta_landing_url', self::current_url(), 90 * DAY_IN_SECONDS);
    }

    private static function set_cookie($name, $value, $lifetime) {
        if ($value === '') {
            return;
        }
        if (function_exists('wc_setcookie')) {
            wc_setcookie($name, $value, time() + $lifetime, is_ssl(), true);
        }
        $_COOKIE[$name] = $value;
    }

    private static function sanitize_meta_id($value) {
        return substr(preg_replace('/[^A-Za-z0-9._~-]/', '', (string) $value), 0, 500);
    }

    private static function current_url() {
        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
            return '';
        }
        return esc_url_raw((is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) . wp_unslash($_SERVER['REQUEST_URI']));
    }

    /** Persist attribution, consent and device context while checkout still has them. */
    public static function save_order_context($order_or_id, $data = array()) {
        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return;
        }

        $fbclid = !empty($_COOKIE['mg_meta_fbclid']) ? self::sanitize_meta_id(wp_unslash($_COOKIE['mg_meta_fbclid'])) : '';
        $fbclid_time = !empty($_COOKIE['mg_meta_fbclid_time']) ? preg_replace('/[^0-9]/', '', wp_unslash($_COOKIE['mg_meta_fbclid_time'])) : '';
        $fbp = !empty($_COOKIE['_fbp']) ? self::sanitize_meta_id(wp_unslash($_COOKIE['_fbp'])) : '';
        $fbc = !empty($_COOKIE['_fbc']) ? self::sanitize_meta_id(wp_unslash($_COOKIE['_fbc'])) : '';
        if ($fbc === '' && $fbclid !== '') {
            $fbc = 'fb.1.' . ($fbclid_time ?: (string) round(microtime(true) * 1000)) . '.' . $fbclid;
        }

        if ($fbclid !== '') {
            $order->update_meta_data('_mg_meta_fbclid', $fbclid);
        }
        if ($fbp !== '') {
            $order->update_meta_data('_mg_meta_fbp', $fbp);
        }
        if ($fbc !== '') {
            $order->update_meta_data('_mg_meta_fbc', $fbc);
        }
        if (!empty($_COOKIE['mg_meta_landing_url'])) {
            $order->update_meta_data('_mg_meta_landing_url', esc_url_raw(wp_unslash($_COOKIE['mg_meta_landing_url'])));
        }

        $consent = !empty($_COOKIE['mg_gads_consent']) ? sanitize_key(wp_unslash($_COOKIE['mg_gads_consent'])) : '';
        if (in_array($consent, array('granted', 'denied'), true)) {
            $order->update_meta_data('_mg_meta_consent', $consent);
        }
        $ip = self::get_client_ip();
        if ($ip !== '') {
            $order->update_meta_data('_mg_meta_client_ip', $ip);
        }
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $order->update_meta_data('_mg_meta_user_agent', substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 500));
        }
        $order->update_meta_data('_mg_meta_source_url', self::current_url());

        // Classic checkout saves a new order after this hook; Store API passes an existing order.
        if ($order->get_id()) {
            $order->save();
        }
    }

    private static function get_client_ip() {
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $candidate = trim(explode(',', wp_unslash($_SERVER[$key]))[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return '';
    }

    public static function handle_status_change($order_id, $old_status, $new_status, $order) {
        if (in_array($new_status, self::get_eligible_statuses(), true)) {
            if ($order instanceof WC_Order && !$order->get_meta('_mg_meta_eligible_at')) {
                $order->update_meta_data('_mg_meta_eligible_at', time());
                $order->save();
            }
            self::queue_purchase($order_id);
        }
    }

    public static function queue_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_order_eligible($order)) {
            return;
        }
        $status = (string) $order->get_meta(self::META_STATUS);
        if (in_array($status, array('processed', 'test_processed'), true)) {
            return;
        }

        $settings = self::get_settings();
        if (empty($settings['server_side_enabled'])) {
            if ($status === '') {
                self::set_state($order, 'browser_only', '');
            }
            return;
        }
        if (empty($settings['pixel_id']) || self::get_access_token() === '') {
            self::set_state($order, 'waiting_configuration', 'Hiányzó Meta Pixel ID vagy Conversions API access token.');
            return;
        }

        $order->update_meta_data(self::META_STATUS, 'queued');
        $order->update_meta_data(self::META_LAST_ERROR, '');
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();
        self::schedule((int) $order_id, time() + 5);
    }

    private static function schedule($order_id, $timestamp) {
        $args = array((int) $order_id);
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action((int) $timestamp, self::PROCESS_HOOK, $args, self::ACTION_GROUP, true);
            return;
        }
        if (!wp_next_scheduled(self::PROCESS_HOOK, $args)) {
            wp_schedule_single_event((int) $timestamp, self::PROCESS_HOOK, $args);
        }
    }

    public static function process_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_order_eligible($order)) {
            return;
        }
        if (in_array($order->get_meta(self::META_STATUS), array('processed', 'test_processed'), true)) {
            return;
        }

        $settings = self::get_settings();
        if (empty($settings['server_side_enabled']) || empty($settings['pixel_id']) || self::get_access_token() === '') {
            self::set_state($order, 'waiting_configuration', 'A Meta CAPI nincs teljesen beállítva.');
            return;
        }
        $consent = (string) ($order->get_meta('_mg_meta_consent') ?: $order->get_meta('_mg_gads_consent'));
        if ($consent !== 'granted') {
            self::set_state($order, 'skipped_no_consent', 'Nincs eltárolt marketing-hozzájárulás a szerveroldali Meta méréshez.');
            return;
        }

        $attempts = (int) $order->get_meta(self::META_ATTEMPTS) + 1;
        $order->update_meta_data(self::META_ATTEMPTS, $attempts);
        $order->update_meta_data(self::META_STATUS, 'sending');
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();

        $payload = array(
            'data' => array(self::build_event($order)),
            'partner_agent' => 'mockup-generator-' . (defined('MG_VERSION') ? MG_VERSION : 'unknown'),
        );
        if (!empty($settings['test_event_code'])) {
            $payload['test_event_code'] = sanitize_text_field($settings['test_event_code']);
        }
        $url = add_query_arg(
            'access_token',
            self::get_access_token(),
            'https://graph.facebook.com/' . self::API_VERSION . '/' . rawurlencode(self::digits($settings['pixel_id'])) . '/events'
        );
        $response = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            self::retry_or_fail($order, $response->get_error_message(), $attempts);
            return;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 200 && $code < 300 && !empty($body['events_received'])) {
            $order->update_meta_data(self::META_STATUS, !empty($settings['test_event_code']) ? 'test_processed' : 'processed');
            $order->update_meta_data(self::META_LAST_ERROR, '');
            $order->update_meta_data(self::META_TRACE_ID, sanitize_text_field($body['fbtrace_id'] ?? ''));
            $order->update_meta_data(self::META_LAST_UPDATE, time());
            $order->save();
            return;
        }
        if (!empty($body['error']['fbtrace_id'])) {
            $order->update_meta_data(self::META_TRACE_ID, sanitize_text_field($body['error']['fbtrace_id']));
            $order->save();
        }
        self::retry_or_fail($order, self::api_error_message($code, $body), $attempts);
    }

    private static function build_event($order) {
        $date = $order->get_date_paid();
        $event_time = $date ? $date->getTimestamp() : (int) $order->get_meta('_mg_meta_eligible_at');
        if (!$event_time) {
            $created = $order->get_date_created();
            $event_time = $created ? $created->getTimestamp() : time();
        }
        return array(
            'event_name' => 'Purchase',
            'event_time' => $event_time,
            'event_id' => (string) $order->get_order_number(),
            'action_source' => 'website',
            'event_source_url' => $order->get_meta('_mg_meta_source_url') ?: $order->get_checkout_order_received_url(),
            'user_data' => self::build_user_data($order),
            'custom_data' => self::build_custom_data($order),
        );
    }

    private static function build_user_data($order) {
        $data = array();
        $email = strtolower(trim((string) $order->get_billing_email()));
        $phone = self::normalize_phone($order->get_billing_phone(), $order->get_billing_country());
        $fields = array(
            'em' => $email,
            'ph' => $phone,
            'fn' => self::normalize_text($order->get_billing_first_name()),
            'ln' => self::normalize_text($order->get_billing_last_name()),
            'ct' => self::normalize_text($order->get_billing_city()),
            'st' => self::normalize_text($order->get_billing_state()),
            'zp' => strtolower(preg_replace('/\s+/', '', (string) $order->get_billing_postcode())),
            'country' => strtolower((string) $order->get_billing_country()),
        );
        foreach ($fields as $key => $value) {
            if ($value !== '') {
                $data[$key] = hash('sha256', $value);
            }
        }
        $external_id = $order->get_customer_id()
            ? 'wc_user_' . $order->get_customer_id()
            : 'wc_guest_' . $email;
        if ($external_id !== 'wc_guest_') {
            $data['external_id'] = hash('sha256', $external_id);
        }
        foreach (array('fbp' => array('_mg_meta_fbp', '_mg_fbp'), 'fbc' => array('_mg_meta_fbc', '_mg_fbc')) as $key => $meta_keys) {
            $value = self::sanitize_meta_id($order->get_meta($meta_keys[0]) ?: $order->get_meta($meta_keys[1]));
            if ($value !== '') {
                $data[$key] = $value;
            }
        }
        $ip = (string) $order->get_meta('_mg_meta_client_ip');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $data['client_ip_address'] = $ip;
        }
        $ua = (string) $order->get_meta('_mg_meta_user_agent');
        if ($ua !== '') {
            $data['client_user_agent'] = $ua;
        }
        return $data;
    }

    private static function normalize_text($value) {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9]/', '', $value);
    }

    private static function normalize_phone($phone, $country) {
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

    private static function build_custom_data($order) {
        $content_ids = array();
        $contents = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $id = self::virtual_item_id($product, self::order_item_type($item));
            $quantity = max(1, (int) $item->get_quantity());
            $line_total = (float) $item->get_total();
            $content_ids[] = $id;
            $contents[] = array(
                'id' => $id,
                'quantity' => $quantity,
                'item_price' => round($line_total / $quantity, 2),
            );
        }
        return array(
            'value' => (float) $order->get_total(),
            'currency' => strtoupper($order->get_currency()),
            'content_ids' => $content_ids,
            'contents' => $contents,
            'content_type' => 'product',
            'order_id' => (string) $order->get_order_number(),
            'discount' => (float) $order->get_discount_total(),
        );
    }

    public static function order_item_type($item) {
        $direct = $item->get_meta('mg_product_type');
        if ($direct !== '') {
            return sanitize_title($direct);
        }
        $label = $item->get_meta(__('Terméktípus', 'mgdtp')) ?: $item->get_meta('Terméktípus');
        if (!$label || !class_exists('MG_Variant_Display_Manager')) {
            return '';
        }
        foreach (MG_Variant_Display_Manager::get_catalog_index() as $slug => $data) {
            if (!empty($data['label']) && $data['label'] === $label) {
                return sanitize_title($slug);
            }
        }
        return '';
    }

    public static function virtual_item_id($product, $type_slug) {
        $parent_id = method_exists($product, 'get_parent_id') ? $product->get_parent_id() : 0;
        $base = $parent_id ? wc_get_product($parent_id) : $product;
        $base_id = $parent_id ?: $product->get_id();
        $sku = $base && $base->get_sku() ? $base->get_sku() : 'ID_' . $base_id;
        return $type_slug ? $sku . '_' . sanitize_title($type_slug) : (string) $sku;
    }

    private static function retry_or_fail($order, $message, $attempts) {
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::set_state($order, 'failed', $message);
            return;
        }
        $delays = array(300, 1800, 7200, 21600, 43200, 86400);
        self::set_state($order, 'retrying', $message);
        self::schedule((int) $order->get_id(), time() + $delays[min($attempts - 1, count($delays) - 1)]);
    }

    private static function set_state($order, $status, $error) {
        $order->update_meta_data(self::META_STATUS, sanitize_key($status));
        $order->update_meta_data(self::META_LAST_ERROR, sanitize_text_field((string) $error));
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();
    }

    private static function api_error_message($code, $body) {
        if (is_array($body) && !empty($body['error']['message'])) {
            return 'HTTP ' . $code . ': ' . sanitize_text_field($body['error']['message']);
        }
        return 'Meta CAPI HTTP ' . $code . ' válasz, events_received nélkül.';
    }

    private static function digits($value) {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    public static function backfill_recent_orders($days = 6) {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }
        $orders = wc_get_orders(array(
            'limit' => 200,
            'status' => self::get_eligible_statuses(),
            'date_created' => '>' . (time() - (min(6, absint($days)) * DAY_IN_SECONDS)),
            'return' => 'ids',
        ));
        $count = 0;
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && !in_array($order->get_meta(self::META_STATUS), array('processed', 'test_processed'), true)) {
                self::queue_purchase($order_id);
                $count++;
            }
        }
        return $count;
    }

    public static function register_order_meta_box() {
        add_meta_box('mg-meta-measurement', 'Meta mérés', array(__CLASS__, 'render_order_meta_box'), 'shop_order', 'side', 'default');
    }

    public static function register_hpos_order_meta_box() {
        add_meta_box('mg-meta-measurement', 'Meta mérés', array(__CLASS__, 'render_order_meta_box'), 'woocommerce_page_wc-orders', 'side', 'default');
    }

    public static function render_order_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order) {
            return;
        }
        echo '<p><strong>CAPI:</strong> ' . esc_html($order->get_meta(self::META_STATUS) ?: 'nincs sorba állítva') . '</p>';
        echo '<p><strong>Consent:</strong> ' . esc_html($order->get_meta('_mg_meta_consent') ?: 'ismeretlen') . '</p>';
        echo '<p><strong>Attribution:</strong> ' . esc_html(($order->get_meta('_mg_meta_fbc') || $order->get_meta('_mg_meta_fbp')) ? 'mentve' : 'nincs') . '</p>';
        if ($order->get_meta(self::META_TRACE_ID)) {
            echo '<p><strong>Trace ID:</strong><br><code>' . esc_html($order->get_meta(self::META_TRACE_ID)) . '</code></p>';
        }
        if ($order->get_meta(self::META_LAST_ERROR)) {
            echo '<p style="color:#b32d2e"><strong>Hiba:</strong> ' . esc_html($order->get_meta(self::META_LAST_ERROR)) . '</p>';
        }
    }
}
