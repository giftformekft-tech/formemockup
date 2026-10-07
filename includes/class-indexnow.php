<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * IndexNow: új és módosított termékek, kategóriák azonnali bejelentése a
 * Bingnek (és a protokollt használó keresőknek). A ChatGPT keresés és a
 * Copilot a Bing indexére is támaszkodik, így az új minták hamarabb
 * megjelenhetnek ott is.
 *
 * Alapból kikapcsolt: külső szolgáltatásnak küld URL-eket. A termékeknél a
 * kanonikus, típusos URL megy (ugyanaz, mint a sitemapben és a feedekben).
 */
class MG_IndexNow {
    const OPTION = 'mg_indexnow';
    const QUEUE = 'mg_indexnow_queue';
    const STATUS = 'mg_indexnow_status';
    const CRON = 'mg_indexnow_flush';
    const ENDPOINT = 'https://api.indexnow.org/indexnow';
    const BATCH = 10000;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_serve_key'), 1);
        add_action(self::CRON, array(__CLASS__, 'flush'));
        add_action('transition_post_status', array(__CLASS__, 'on_post_status'), 20, 3);
        add_action('mg_seo_term_saved', array(__CLASS__, 'on_term_saved'));
    }

    public static function get_settings() {
        $stored = get_option(self::OPTION, array());
        return array_merge(array('enabled' => 0, 'key' => ''), is_array($stored) ? $stored : array());
    }

    public static function is_enabled() {
        $settings = self::get_settings();
        return !empty($settings['enabled']) && self::is_valid_key($settings['key']);
    }

    public static function is_valid_key($key) {
        return is_string($key) && (bool) preg_match('/^[A-Za-z0-9-]{8,128}$/', $key);
    }

    public static function save_settings($enabled) {
        $settings = self::get_settings();
        $settings['enabled'] = $enabled ? 1 : 0;
        if ($settings['enabled'] && !self::is_valid_key($settings['key'])) {
            $settings['key'] = wp_generate_password(32, false, false);
        }
        update_option(self::OPTION, $settings, false);
        return $settings;
    }

    public static function key_url($key) {
        return home_url('/' . $key . '.txt');
    }

    /** A kulcsfájl (/<kulcs>.txt) kiszolgálása a tulajdonjog igazolásához. */
    public static function maybe_serve_key() {
        $settings = self::get_settings();
        if (!self::is_valid_key($settings['key'])) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ($path !== (string) wp_parse_url(self::key_url($settings['key']), PHP_URL_PATH)) {
            return;
        }
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $settings['key']; // phpcs:ignore WordPress.Security.EscapeOutput -- [A-Za-z0-9-]
        exit;
    }

    public static function on_post_status($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || !$post instanceof WP_Post || $post->post_type !== 'product' || !self::is_enabled()) {
            return;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }
        $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
        if (!$product) {
            return;
        }
        $url = class_exists('MG_SEO_Meta') ? MG_SEO_Meta::get_product_canonical_url($product) : get_permalink($post);
        self::queue(array($url));
    }

    public static function on_term_saved($term_id) {
        if (!self::is_enabled()) {
            return;
        }
        $term = get_term((int) $term_id, 'product_cat');
        if (!$term || is_wp_error($term) || (class_exists('MG_SEO_Meta') && MG_SEO_Meta::term_is_noindex($term))) {
            return;
        }
        $link = get_term_link($term);
        if (!is_wp_error($link)) {
            self::queue(array($link));
        }
    }

    public static function queue(array $urls) {
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $queue = get_option(self::QUEUE, array());
        $queue = is_array($queue) ? $queue : array();
        foreach ($urls as $url) {
            $url = esc_url_raw((string) $url);
            if ($url !== '' && (string) wp_parse_url($url, PHP_URL_HOST) === $home_host) {
                $queue[] = $url;
            }
        }
        $queue = array_slice(array_values(array_unique($queue)), -50000);
        update_option(self::QUEUE, $queue, false);
        if ($queue && !wp_next_scheduled(self::CRON)) {
            wp_schedule_single_event(time() + 120, self::CRON);
        }
    }

    public static function flush() {
        $settings = self::get_settings();
        $queue = get_option(self::QUEUE, array());
        if (!self::is_enabled() || !is_array($queue) || !$queue) {
            return;
        }
        $batch = array_slice(array_values($queue), 0, self::BATCH);
        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => wp_json_encode(array(
                'host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
                'key' => $settings['key'],
                'keyLocation' => self::key_url($settings['key']),
                'urlList' => $batch,
            ), JSON_UNESCAPED_SLASHES),
        ));
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $message = is_wp_error($response) ? $response->get_error_message() : '';
        update_option(self::STATUS, array(
            'time' => time(),
            'code' => $code,
            'count' => count($batch),
            'message' => $message,
        ), false);

        // 429 / 5xx / hálózati hiba: később újra; egyéb hibánál a köteg eldobva.
        $retry = $code === 0 || $code === 429 || $code >= 500;
        $current = get_option(self::QUEUE, array());
        $current = is_array($current) ? $current : array();
        if (!$retry) {
            $current = array_values(array_diff($current, $batch));
            update_option(self::QUEUE, $current, false);
        }
        if ($current && !wp_next_scheduled(self::CRON)) {
            wp_schedule_single_event(time() + ($retry ? HOUR_IN_SECONDS : 300), self::CRON);
        }
    }
}
