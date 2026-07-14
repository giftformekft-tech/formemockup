<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable Google Ads purchase measurement for WooCommerce.
 *
 * The browser tag remains the primary source. This class records the original
 * attribution context on the order, supplements eligible purchases through the
 * Google Data Manager API and verifies asynchronous processing afterwards.
 */
class MG_Google_Ads_Reliability {
    const PROCESS_HOOK = 'mg_gads_process_order';
    const STATUS_HOOK = 'mg_gads_check_request_status';
    const ADJUST_HOOK = 'mg_gads_process_adjustment';
    const ACTION_GROUP = 'mg-google-ads';
    const MAX_ATTEMPTS = 6;

    const META_STATUS = '_mg_gads_server_status';
    const META_ATTEMPTS = '_mg_gads_server_attempts';
    const META_LAST_ERROR = '_mg_gads_server_last_error';
    const META_REQUEST_ID = '_mg_gads_request_id';
    const META_STATUS_CHECKS = '_mg_gads_status_checks';
    const META_LAST_UPDATE = '_mg_gads_server_updated_at';

    public static function init() {
        add_action('init', array(__CLASS__, 'capture_click_identifiers'), 1);

        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'save_order_context'), 20, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'save_order_context'), 20, 1);

        add_action('woocommerce_payment_complete', array(__CLASS__, 'queue_purchase'), 20, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'handle_status_change'), 20, 4);

        add_action(self::PROCESS_HOOK, array(__CLASS__, 'process_purchase'), 10, 1);
        add_action(self::STATUS_HOOK, array(__CLASS__, 'check_request_status'), 10, 1);
        add_action(self::ADJUST_HOOK, array(__CLASS__, 'process_adjustment'), 10, 3);

        add_action('woocommerce_order_refunded', array(__CLASS__, 'handle_refund'), 20, 2);

        add_action('add_meta_boxes', array(__CLASS__, 'register_order_meta_box'));
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array(__CLASS__, 'register_hpos_order_meta_box'));
    }

    public static function defaults() {
        return array(
            'conversion_id' => '',
            'purchase_label' => '',
            'eligible_statuses' => array('processing', 'completed'),
            'server_side_enabled' => 0,
            'customer_id' => '',
            'login_customer_id' => '',
            'conversion_action_id' => '',
            'service_account_json' => '',
            'developer_token' => '',
            'adjustments_enabled' => 0,
            'merchant_id' => '',
            'feed_country' => 'HU',
            'feed_language' => 'hu',
            'validate_only' => 0,
        );
    }

    public static function get_settings() {
        $stored = get_option('mg_gads_settings', array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $settings = wp_parse_args($stored, self::defaults());
        $settings['eligible_statuses'] = array_values(array_unique(array_filter(array_map(
            'sanitize_key',
            (array) $settings['eligible_statuses']
        ))));
        return $settings;
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

    /** Save Google click IDs as first-party cookies before checkout. */
    public static function capture_click_identifiers() {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        foreach (array('gclid', 'gbraid', 'wbraid') as $key) {
            if (!isset($_GET[$key])) {
                continue;
            }
            $value = self::sanitize_click_id(wp_unslash($_GET[$key]));
            if ($value === '') {
                continue;
            }
            if (function_exists('wc_setcookie')) {
                wc_setcookie('mg_gads_' . $key, $value, time() + (90 * DAY_IN_SECONDS), is_ssl(), true);
            }
            $_COOKIE['mg_gads_' . $key] = $value;
        }

        if (isset($_GET['gclid']) || isset($_GET['gbraid']) || isset($_GET['wbraid'])) {
            $landing_url = self::current_url();
            if ($landing_url && function_exists('wc_setcookie')) {
                wc_setcookie('mg_gads_landing_url', $landing_url, time() + (90 * DAY_IN_SECONDS), is_ssl(), true);
                $_COOKIE['mg_gads_landing_url'] = $landing_url;
            }
        }
    }

    private static function sanitize_click_id($value) {
        $value = substr((string) $value, 0, 250);
        return preg_replace('/[^A-Za-z0-9._~-]/', '', $value);
    }

    private static function current_url() {
        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
            return '';
        }
        $scheme = is_ssl() ? 'https://' : 'http://';
        return esc_url_raw($scheme . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) . wp_unslash($_SERVER['REQUEST_URI']));
    }

    /** Persist attribution and consent while the checkout request is available. */
    public static function save_order_context($order_or_id, $data = array()) {
        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return;
        }

        foreach (array('gclid', 'gbraid', 'wbraid') as $key) {
            $cookie_key = 'mg_gads_' . $key;
            if (!empty($_COOKIE[$cookie_key])) {
                $order->update_meta_data('_mg_gads_' . $key, self::sanitize_click_id(wp_unslash($_COOKIE[$cookie_key])));
            }
        }

        if (!empty($_COOKIE['mg_gads_landing_url'])) {
            $order->update_meta_data('_mg_gads_landing_url', esc_url_raw(wp_unslash($_COOKIE['mg_gads_landing_url'])));
        }
        if (!empty($_COOKIE['mg_gads_consent'])) {
            $consent = sanitize_key(wp_unslash($_COOKIE['mg_gads_consent']));
            if (in_array($consent, array('granted', 'denied'), true)) {
                $order->update_meta_data('_mg_gads_consent', $consent);
            }
        }
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $order->update_meta_data('_mg_gads_user_agent', substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 500));
        }
        // Classic checkout persists the new order immediately after this hook.
        // Store API passes an already-created order, which needs an explicit save.
        if ($order->get_id()) {
            $order->save();
        }
    }

    public static function handle_status_change($order_id, $old_status, $new_status, $order) {
        if (in_array($new_status, self::get_eligible_statuses(), true)) {
            self::queue_purchase($order_id);
            return;
        }

        if (in_array($new_status, array('cancelled', 'refunded', 'failed'), true)) {
            self::maybe_queue_retraction($order_id);
        }
    }

    public static function queue_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_order_eligible($order)) {
            return;
        }

        $status = (string) $order->get_meta(self::META_STATUS);
        if (in_array($status, array('accepted', 'processing', 'processed', 'validated'), true)) {
            return;
        }

        $settings = self::get_settings();
        if (empty($settings['server_side_enabled'])) {
            if ($status === '') {
                self::set_order_state($order, 'browser_only', '');
            }
            return;
        }

        $order->update_meta_data(self::META_STATUS, 'queued');
        $order->update_meta_data(self::META_LAST_ERROR, '');
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();
        self::schedule(self::PROCESS_HOOK, array((int) $order_id), time() + 5);
    }

    private static function schedule($hook, array $args, $timestamp) {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action((int) $timestamp, $hook, $args, self::ACTION_GROUP, true);
            return;
        }
        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event((int) $timestamp, $hook, $args);
        }
    }

    public static function process_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_order_eligible($order)) {
            return;
        }

        $status = (string) $order->get_meta(self::META_STATUS);
        if (in_array($status, array('accepted', 'processing', 'processed', 'validated'), true)) {
            return;
        }

        $settings = self::get_settings();
        $config_error = self::server_configuration_error($settings);
        if ($config_error !== '') {
            self::set_order_state($order, 'waiting_configuration', $config_error);
            return;
        }

        if ((string) $order->get_meta('_mg_gads_consent') !== 'granted') {
            self::set_order_state($order, 'skipped_no_consent', 'A szerveroldali feltöltéshez nincs eltárolt ad_user_data hozzájárulás.');
            return;
        }

        $attempts = (int) $order->get_meta(self::META_ATTEMPTS) + 1;
        $order->update_meta_data(self::META_ATTEMPTS, $attempts);
        $order->update_meta_data(self::META_STATUS, 'sending');
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();

        $token = self::get_access_token('https://www.googleapis.com/auth/datamanager');
        if (is_wp_error($token)) {
            self::retry_or_fail($order, $token->get_error_message(), $attempts);
            return;
        }

        $payload = self::build_data_manager_payload($order, $settings);
        $response = wp_remote_post('https://datamanager.googleapis.com/v1/events:ingest', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            self::retry_or_fail($order, $response->get_error_message(), $attempts);
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($body['requestId'])) {
            self::retry_or_fail($order, self::api_error_message($code, $body), $attempts);
            return;
        }

        $order->update_meta_data(self::META_STATUS, !empty($settings['validate_only']) ? 'validated' : 'accepted');
        $order->update_meta_data(self::META_REQUEST_ID, sanitize_text_field($body['requestId']));
        $order->update_meta_data(self::META_LAST_ERROR, '');
        $order->update_meta_data(self::META_STATUS_CHECKS, 0);
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();

        if (empty($settings['validate_only'])) {
            self::schedule(self::STATUS_HOOK, array((int) $order_id), time() + (30 * MINUTE_IN_SECONDS));
        }
    }

    private static function server_configuration_error(array $settings) {
        if (empty($settings['server_side_enabled'])) {
            return 'A Google Data Manager szerveroldali küldés nincs bekapcsolva.';
        }
        foreach (array('customer_id', 'conversion_action_id') as $required) {
            if (empty($settings[$required])) {
                return 'Hiányzó szerveroldali beállítás: ' . $required;
            }
        }
        if (!self::get_service_account()) {
            return 'Hiányzó vagy hibás Google service account JSON.';
        }
        return '';
    }

    private static function build_data_manager_payload($order, array $settings) {
        $destination = array(
            'operatingAccount' => array(
                'accountType' => 'GOOGLE_ADS',
                'accountId' => self::digits($settings['customer_id']),
            ),
            'productDestinationId' => self::digits($settings['conversion_action_id']),
        );
        if (!empty($settings['login_customer_id'])) {
            $destination['loginAccount'] = array(
                'accountType' => 'GOOGLE_ADS',
                'accountId' => self::digits($settings['login_customer_id']),
            );
        }

        $event = array(
            'transactionId' => (string) $order->get_order_number(),
            'eventTimestamp' => self::order_timestamp($order),
            'eventSource' => 'WEB',
            'conversionValue' => (float) $order->get_total(),
            'currency' => strtoupper($order->get_currency()),
            'consent' => array(
                'adUserData' => 'CONSENT_GRANTED',
                'adPersonalization' => 'CONSENT_GRANTED',
            ),
        );

        $ad_ids = array();
        foreach (array('gclid', 'gbraid', 'wbraid') as $key) {
            $value = self::sanitize_click_id($order->get_meta('_mg_gads_' . $key));
            if ($value !== '') {
                $ad_ids[$key] = $value;
            }
        }
        if ($ad_ids) {
            $event['adIdentifiers'] = $ad_ids;
        }

        $identifiers = self::build_user_identifiers($order);
        if ($identifiers) {
            $event['userData'] = array('userIdentifiers' => $identifiers);
        }

        $cart = self::build_cart_data($order, $settings);
        if ($cart) {
            $event['cartData'] = $cart;
        }

        return array(
            'destinations' => array($destination),
            'events' => array($event),
            'consent' => array(
                'adUserData' => 'CONSENT_GRANTED',
                'adPersonalization' => 'CONSENT_GRANTED',
            ),
            'encoding' => 'HEX',
            'validateOnly' => !empty($settings['validate_only']),
        );
    }

    private static function build_user_identifiers($order) {
        $identifiers = array();
        $email = strtolower(trim((string) $order->get_billing_email()));
        if (is_email($email)) {
            $identifiers[] = array('emailAddress' => strtoupper(hash('sha256', $email)));
        }

        $phone = self::normalize_phone($order->get_billing_phone(), $order->get_billing_country());
        if ($phone !== '') {
            $identifiers[] = array('phoneNumber' => strtoupper(hash('sha256', $phone)));
        }

        $first = self::normalize_name($order->get_billing_first_name());
        $last = self::normalize_name($order->get_billing_last_name());
        if ($first !== '' && $last !== '' && $order->get_billing_country()) {
            $identifiers[] = array('address' => array(
                'givenName' => strtoupper(hash('sha256', $first)),
                'familyName' => strtoupper(hash('sha256', $last)),
                'regionCode' => strtoupper($order->get_billing_country()),
                'postalCode' => strtoupper(preg_replace('/\s+/', '', (string) $order->get_billing_postcode())),
            ));
        }
        return array_slice($identifiers, 0, 5);
    }

    private static function normalize_name($value) {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
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
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : '';
    }

    private static function build_cart_data($order, array $settings) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $type_slug = sanitize_title($item->get_meta('mg_product_type'));
            $item_id = self::virtual_item_id($product, $type_slug);
            $qty = max(1, (int) $item->get_quantity());
            $unit_price = (float) $item->get_subtotal() / $qty;
            $items[] = array(
                'itemId' => $item_id,
                'merchantProductId' => $item_id,
                'quantity' => $qty,
                'unitPrice' => round($unit_price, 2),
            );
        }
        if (!$items) {
            return array();
        }

        $cart = array(
            'transactionDiscount' => (float) $order->get_discount_total(),
            'items' => $items,
        );
        if (!empty($settings['merchant_id'])) {
            $cart['merchantId'] = self::digits($settings['merchant_id']);
        }
        if (!empty($settings['feed_country'])) {
            $cart['merchantFeedLabel'] = strtoupper(sanitize_text_field($settings['feed_country']));
        }
        if (!empty($settings['feed_language'])) {
            $cart['merchantFeedLanguageCode'] = strtolower(sanitize_text_field($settings['feed_language']));
        }
        return $cart;
    }

    private static function virtual_item_id($product, $type_slug) {
        $parent_id = method_exists($product, 'get_parent_id') ? $product->get_parent_id() : 0;
        $base = $parent_id ? wc_get_product($parent_id) : $product;
        $base_id = $parent_id ?: $product->get_id();
        $sku = $base && $base->get_sku() ? $base->get_sku() : 'ID_' . $base_id;
        return $type_slug ? $sku . '_' . $type_slug : (string) $sku;
    }

    private static function order_timestamp($order) {
        $date = $order->get_date_paid();
        if (!$date) {
            $date = $order->get_date_created();
        }
        return $date ? gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp()) : gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function check_request_status($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_meta(self::META_REQUEST_ID)) {
            return;
        }
        if (!in_array($order->get_meta(self::META_STATUS), array('accepted', 'processing'), true)) {
            return;
        }

        $checks = (int) $order->get_meta(self::META_STATUS_CHECKS) + 1;
        $order->update_meta_data(self::META_STATUS_CHECKS, $checks);
        $order->save();

        $token = self::get_access_token('https://www.googleapis.com/auth/datamanager');
        if (is_wp_error($token)) {
            self::schedule_status_retry($order, $checks, $token->get_error_message());
            return;
        }

        $url = add_query_arg('requestId', rawurlencode($order->get_meta(self::META_REQUEST_ID)), 'https://datamanager.googleapis.com/v1/requestStatus:retrieve');
        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'Bearer ' . $token),
        ));
        if (is_wp_error($response)) {
            self::schedule_status_retry($order, $checks, $response->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $destination = isset($body['requestStatusPerDestination'][0]) ? $body['requestStatusPerDestination'][0] : array();
        $status = isset($destination['requestStatus']) ? $destination['requestStatus'] : '';
        if ($code >= 200 && $code < 300 && $status === 'SUCCESS') {
            self::set_order_state($order, 'processed', '');
            if (!empty($destination['warningInfo'])) {
                $order->update_meta_data('_mg_gads_server_warnings', wp_json_encode($destination['warningInfo']));
                $order->save();
            }
            return;
        }
        if ($code >= 200 && $code < 300 && $status === 'PROCESSING') {
            self::set_order_state($order, 'processing', '');
            self::schedule_status_retry($order, $checks, '');
            return;
        }
        if ($status === 'FAILED' || $status === 'PARTIAL_SUCCESS') {
            self::set_order_state($order, 'failed', self::diagnostic_error_message($destination));
            return;
        }
        self::schedule_status_retry($order, $checks, self::api_error_message($code, $body));
    }

    private static function schedule_status_retry($order, $checks, $error) {
        if ($checks >= self::MAX_ATTEMPTS) {
            self::set_order_state($order, 'failed', $error ?: 'A Google feldolgozási státusza nem vált véglegessé.');
            return;
        }
        if ($error) {
            $order->update_meta_data(self::META_LAST_ERROR, sanitize_text_field($error));
        }
        $order->update_meta_data(self::META_STATUS, 'processing');
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();
        $delays = array(1800, 3600, 7200, 14400, 28800, 43200);
        self::schedule(self::STATUS_HOOK, array((int) $order->get_id()), time() + $delays[min($checks, count($delays) - 1)]);
    }

    private static function diagnostic_error_message(array $destination) {
        $reasons = array();
        foreach ((array) ($destination['errorInfo']['errorCounts'] ?? array()) as $error) {
            if (!empty($error['reason'])) {
                $reasons[] = $error['reason'] . (!empty($error['recordCount']) ? ' (' . $error['recordCount'] . ')' : '');
            }
        }
        return $reasons ? implode(', ', $reasons) : 'A Google nem dolgozta fel a konverziós rekordot.';
    }

    private static function retry_or_fail($order, $message, $attempts) {
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::set_order_state($order, 'failed', $message);
            return;
        }
        $delays = array(300, 1800, 7200, 43200, 86400, 172800);
        self::set_order_state($order, 'retrying', $message);
        self::schedule(self::PROCESS_HOOK, array((int) $order->get_id()), time() + $delays[min($attempts - 1, count($delays) - 1)]);
    }

    private static function set_order_state($order, $status, $error) {
        $order->update_meta_data(self::META_STATUS, sanitize_key($status));
        $order->update_meta_data(self::META_LAST_ERROR, sanitize_text_field((string) $error));
        $order->update_meta_data(self::META_LAST_UPDATE, time());
        $order->save();
    }

    private static function api_error_message($code, $body) {
        if (is_array($body) && !empty($body['error']['message'])) {
            return 'HTTP ' . $code . ': ' . sanitize_text_field($body['error']['message']);
        }
        return 'Google API HTTP ' . $code . ' válasz.';
    }

    private static function digits($value) {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    private static function get_service_account() {
        $raw = defined('MG_GADS_SERVICE_ACCOUNT_JSON') ? MG_GADS_SERVICE_ACCOUNT_JSON : self::get_settings()['service_account_json'];
        if (is_array($raw)) {
            $data = $raw;
        } else {
            $raw = trim((string) $raw);
            if ($raw !== '' && strpos($raw, '{') !== 0 && is_readable($raw)) {
                $raw = file_get_contents($raw);
            }
            $data = json_decode($raw, true);
        }
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return array();
        }
        return $data;
    }

    private static function get_access_token($scope) {
        $account = self::get_service_account();
        if (!$account) {
            return new WP_Error('mg_gads_credentials', 'Hiányzó vagy hibás service account JSON.');
        }
        if (!function_exists('openssl_sign')) {
            return new WP_Error('mg_gads_openssl', 'A PHP OpenSSL bővítmény nem érhető el.');
        }

        $cache_key = 'mg_gads_token_' . md5($account['client_email'] . '|' . $scope);
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        $now = time();
        $header = self::base64url(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $claims = self::base64url(wp_json_encode(array(
            'iss' => $account['client_email'],
            'scope' => $scope,
            'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        )));
        $unsigned = $header . '.' . $claims;
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            return new WP_Error('mg_gads_signing', 'A service account JWT aláírása sikertelen.');
        }
        $assertion = $unsigned . '.' . self::base64url($signature);

        $response = wp_remote_post($account['token_uri'] ?? 'https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            return new WP_Error('mg_gads_token', self::api_error_message(wp_remote_retrieve_response_code($response), $body));
        }
        set_transient($cache_key, sanitize_text_field($body['access_token']), max(300, ((int) ($body['expires_in'] ?? 3600)) - 300));
        return sanitize_text_field($body['access_token']);
    }

    private static function base64url($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function handle_refund($order_id, $refund_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if (!$order->get_meta(self::META_REQUEST_ID) && !$order->get_meta('_mg_gads_browser_rendered')) {
            return;
        }
        $remaining = max(0, (float) $order->get_total() - abs((float) $order->get_total_refunded()));
        $type = $remaining <= 0.00001 ? 'RETRACTION' : 'RESTATEMENT';
        self::queue_adjustment($order, $type, $remaining);
    }

    private static function maybe_queue_retraction($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $was_measured = $order->get_meta(self::META_REQUEST_ID) || $order->get_meta('_mg_gads_browser_rendered');
        if ($was_measured) {
            self::queue_adjustment($order, 'RETRACTION', 0);
        }
    }

    private static function queue_adjustment($order, $type, $value) {
        $settings = self::get_settings();
        if (empty($settings['adjustments_enabled'])) {
            $order->update_meta_data('_mg_gads_adjustment_status', 'waiting_configuration');
            $order->update_meta_data('_mg_gads_adjustment_type', $type);
            $order->update_meta_data('_mg_gads_adjustment_value', $value);
            $order->save();
            return;
        }
        $order->update_meta_data('_mg_gads_adjustment_status', 'queued');
        $order->update_meta_data('_mg_gads_adjustment_type', $type);
        $order->update_meta_data('_mg_gads_adjustment_value', $value);
        $order->save();
        self::schedule(self::ADJUST_HOOK, array((int) $order->get_id(), $type, (float) $value), time() + (6 * HOUR_IN_SECONDS));
    }

    public static function process_adjustment($order_id, $type, $value) {
        $order = wc_get_order($order_id);
        $settings = self::get_settings();
        if (!$order || empty($settings['adjustments_enabled'])) {
            return;
        }
        if (empty($settings['developer_token']) || empty($settings['customer_id']) || empty($settings['conversion_action_id'])) {
            $order->update_meta_data('_mg_gads_adjustment_status', 'waiting_configuration');
            $order->save();
            return;
        }

        $token = self::get_access_token('https://www.googleapis.com/auth/adwords');
        if (is_wp_error($token)) {
            $order->update_meta_data('_mg_gads_adjustment_status', 'failed');
            $order->update_meta_data('_mg_gads_adjustment_error', $token->get_error_message());
            $order->save();
            return;
        }

        $customer_id = self::digits($settings['customer_id']);
        $adjustment = array(
            'conversionAction' => 'customers/' . $customer_id . '/conversionActions/' . self::digits($settings['conversion_action_id']),
            'adjustmentType' => $type === 'RESTATEMENT' ? 'RESTATEMENT' : 'RETRACTION',
            'orderId' => (string) $order->get_order_number(),
            'adjustmentDateTime' => wp_date('Y-m-d H:i:sP'),
        );
        if ($type === 'RESTATEMENT') {
            $adjustment['restatementValue'] = array(
                'adjustedValue' => (float) $value,
                'currencyCode' => strtoupper($order->get_currency()),
            );
        }
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'developer-token' => $settings['developer_token'],
        );
        if (!empty($settings['login_customer_id'])) {
            $headers['login-customer-id'] = self::digits($settings['login_customer_id']);
        }
        $url = 'https://googleads.googleapis.com/v24/customers/' . $customer_id . ':uploadConversionAdjustments';
        $response = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => $headers,
            'body' => wp_json_encode(array(
                'customerId' => $customer_id,
                'conversionAdjustments' => array($adjustment),
                'partialFailure' => true,
            )),
        ));
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $code = 0;
            $body = array();
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = self::api_error_message($code, $body);
        }
        if ($code >= 200 && $code < 300 && empty($body['partialFailureError'])) {
            $order->update_meta_data('_mg_gads_adjustment_status', strtolower($type));
            $order->update_meta_data('_mg_gads_adjustment_error', '');
        } else {
            $order->update_meta_data('_mg_gads_adjustment_status', 'failed');
            $order->update_meta_data('_mg_gads_adjustment_error', !empty($body['partialFailureError']['message']) ? $body['partialFailureError']['message'] : $error);
        }
        $order->save();
    }

    public static function backfill_recent_orders($days = 30) {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }
        $orders = wc_get_orders(array(
            'limit' => 200,
            'status' => self::get_eligible_statuses(),
            'date_created' => '>' . (time() - (absint($days) * DAY_IN_SECONDS)),
            'return' => 'ids',
        ));
        $count = 0;
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && !in_array($order->get_meta(self::META_STATUS), array('accepted', 'processing', 'processed', 'validated'), true)) {
                self::queue_purchase($order_id);
                $count++;
            }
        }
        return $count;
    }

    public static function backfill_pending_adjustments($days = 90) {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }
        $orders = wc_get_orders(array(
            'limit' => 200,
            'date_created' => '>' . (time() - (absint($days) * DAY_IN_SECONDS)),
            'return' => 'objects',
        ));
        $count = 0;
        foreach ($orders as $order) {
            $status = $order->get_meta('_mg_gads_adjustment_status');
            $type = $order->get_meta('_mg_gads_adjustment_type');
            if (!in_array($status, array('waiting_configuration', 'failed'), true) || !in_array($type, array('RETRACTION', 'RESTATEMENT'), true)) {
                continue;
            }
            $value = (float) $order->get_meta('_mg_gads_adjustment_value');
            $order->update_meta_data('_mg_gads_adjustment_status', 'queued');
            $order->save();
            self::schedule(self::ADJUST_HOOK, array((int) $order->get_id(), $type, $value), time() + 5);
            $count++;
        }
        return $count;
    }

    public static function register_order_meta_box() {
        add_meta_box('mg-gads-measurement', 'Google Ads mérés', array(__CLASS__, 'render_order_meta_box'), 'shop_order', 'side', 'default');
    }

    public static function register_hpos_order_meta_box() {
        add_meta_box('mg-gads-measurement', 'Google Ads mérés', array(__CLASS__, 'render_order_meta_box'), 'woocommerce_page_wc-orders', 'side', 'default');
    }

    public static function render_order_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order) {
            return;
        }
        $status = $order->get_meta(self::META_STATUS) ?: 'nincs sorba állítva';
        echo '<p><strong>Szerver:</strong> ' . esc_html($status) . '</p>';
        echo '<p><strong>Consent:</strong> ' . esc_html($order->get_meta('_mg_gads_consent') ?: 'ismeretlen') . '</p>';
        echo '<p><strong>GCLID:</strong> ' . esc_html($order->get_meta('_mg_gads_gclid') ? 'mentve' : 'nincs') . '</p>';
        if ($order->get_meta(self::META_REQUEST_ID)) {
            echo '<p><strong>Request ID:</strong><br><code style="word-break:break-all">' . esc_html($order->get_meta(self::META_REQUEST_ID)) . '</code></p>';
        }
        if ($order->get_meta(self::META_LAST_ERROR)) {
            echo '<p style="color:#b32d2e"><strong>Hiba:</strong> ' . esc_html($order->get_meta(self::META_LAST_ERROR)) . '</p>';
        }
    }
}
