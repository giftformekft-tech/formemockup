<?php
if (!defined('ABSPATH')) exit;

/**
 * GPT-5 mini alapú minta SEO leírás generátor.
 * A generált szöveg a `_mg_sample_seo` post metába kerül, amit a
 * {sample_seo} változó szúr be a terméktípus-leírás sablonba
 * (lásd includes/type-description-applier.php).
 */
class MG_AI_SEO_Generator {

    const OPTION_KEY = 'mg_ai_seo_settings';
    const META_KEY = '_mg_sample_seo';
    const META_GENERATED_AT = '_mg_sample_seo_generated_at';
    const DEFAULT_MODEL = 'gpt-5-mini';
    const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/responses';
    const DEFAULT_MAX_TOKENS = 500;
    const DEFAULT_DELAY_MS = 600;
    const NONCE_ACTION = 'mg_ai_seo_nonce';

    public static function init() {
        add_action('wp_ajax_mg_ai_seo_test_connection', array(__CLASS__, 'ajax_test_connection'));
        add_action('wp_ajax_mg_ai_seo_candidates', array(__CLASS__, 'ajax_candidates'));
        add_action('wp_ajax_mg_ai_seo_generate_one', array(__CLASS__, 'ajax_generate_one'));
    }

    public static function default_prompt() {
        return "Te egy magyar nyelvű e-kereskedelmi SEO szövegíró vagy. A mellékelt képen egy ruhadarabra (póló/pulóver) nyomtatott minta látható. Írj hozzá egyedi, 300-500 karakteres, keresőoptimalizált leírást magyarul, ami bemutatja a minta témáját, stílusát, hangulatát és kinek ajánlott ajándéknak vagy viseletnek. Ne használj HTML-t vagy felsorolást, ne írj árat, garanciát vagy szállítási időt, és ne ismételd feleslegesen a terméknevet.\n\nTerméknév: {product_name}\nKategória: {product_category}";
    }

    public static function get_settings() {
        $defaults = array(
            'enabled' => false,
            'api_key' => '',
            'model' => self::DEFAULT_MODEL,
            'endpoint' => self::DEFAULT_ENDPOINT,
            'prompt' => self::default_prompt(),
            'max_output_tokens' => self::DEFAULT_MAX_TOKENS,
            'delay_ms' => self::DEFAULT_DELAY_MS,
        );
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return array_merge($defaults, $stored);
    }

    public static function save_settings(array $input) {
        $clean = array(
            'enabled' => !empty($input['enabled']),
            'api_key' => isset($input['api_key']) ? trim(wp_strip_all_tags($input['api_key'])) : '',
            'model' => isset($input['model']) && trim($input['model']) !== '' ? sanitize_text_field($input['model']) : self::DEFAULT_MODEL,
            'endpoint' => isset($input['endpoint']) && trim($input['endpoint']) !== '' ? esc_url_raw($input['endpoint']) : self::DEFAULT_ENDPOINT,
            'prompt' => isset($input['prompt']) ? wp_kses_post(wp_unslash($input['prompt'])) : self::default_prompt(),
            'max_output_tokens' => isset($input['max_output_tokens']) ? max(50, intval($input['max_output_tokens'])) : self::DEFAULT_MAX_TOKENS,
            'delay_ms' => isset($input['delay_ms']) ? max(0, intval($input['delay_ms'])) : self::DEFAULT_DELAY_MS,
        );
        update_option(self::OPTION_KEY, $clean);
        return $clean;
    }

    protected static function build_prompt_text($settings, $product) {
        $context = function_exists('mgtd__build_description_context') ? mgtd__build_description_context($product) : array();
        $replacements = array(
            '{product_name}' => $context['product_name'] ?? ($product ? $product->get_name() : ''),
            '{product_category}' => $context['product_categories'] ?? ($context['product_category'] ?? ''),
        );
        return strtr($settings['prompt'], $replacements);
    }

    protected static function get_image_url_for_product($product) {
        if (!$product) {
            return '';
        }
        $image_id = method_exists($product, 'get_image_id') ? (int) $product->get_image_id() : 0;
        if ($image_id <= 0) {
            return '';
        }
        $url = wp_get_attachment_url($image_id);
        return is_string($url) ? $url : '';
    }

    protected static function call_openai($settings, $prompt_text, $image_url = '') {
        if (empty($settings['api_key'])) {
            return new WP_Error('mg_ai_seo_no_key', __('Nincs megadva OpenAI API kulcs.', 'mockup-generator'));
        }

        $content = array(array('type' => 'input_text', 'text' => $prompt_text));
        if ($image_url !== '') {
            $content[] = array('type' => 'input_image', 'image_url' => $image_url);
        }

        $body = array(
            'model' => $settings['model'],
            'input' => array(
                array('role' => 'user', 'content' => $content),
            ),
            'max_output_tokens' => (int) $settings['max_output_tokens'],
        );

        $response = wp_remote_post($settings['endpoint'], array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $settings['api_key'],
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            return new WP_Error('mg_ai_seo_api_error', $message);
        }

        $text = self::extract_output_text($data);
        if ($text === '') {
            return new WP_Error('mg_ai_seo_empty', __('Az AI nem adott vissza szöveget.', 'mockup-generator'));
        }

        return $text;
    }

    protected static function extract_output_text($data) {
        if (!is_array($data)) {
            return '';
        }
        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            return trim($data['output_text']);
        }
        $chunks = array();
        if (!empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $item) {
                if (empty($item['content']) || !is_array($item['content'])) {
                    continue;
                }
                foreach ($item['content'] as $c) {
                    if (isset($c['type']) && in_array($c['type'], array('output_text', 'text'), true) && !empty($c['text'])) {
                        $chunks[] = $c['text'];
                    }
                }
            }
        }
        return trim(implode("\n", $chunks));
    }

    public static function test_connection() {
        $settings = self::get_settings();
        $result = self::call_openai($settings, 'Válaszolj pontosan ennyivel: OK');
        if (is_wp_error($result)) {
            return $result;
        }
        return true;
    }

    public static function generate_for_product($product_id, $force = false) {
        $product_id = (int) $product_id;
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return new WP_Error('mg_ai_seo_disabled', __('Az AI SEO generálás ki van kapcsolva.', 'mockup-generator'));
        }
        if (!$force) {
            $existing = get_post_meta($product_id, self::META_KEY, true);
            if (is_string($existing) && trim($existing) !== '') {
                return $existing;
            }
        }
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return new WP_Error('mg_ai_seo_no_product', __('A termék nem található.', 'mockup-generator'));
        }
        $image_url = self::get_image_url_for_product($product);
        if ($image_url === '') {
            return new WP_Error('mg_ai_seo_no_image', __('A terméknek nincs kiemelt (mockup) képe.', 'mockup-generator'));
        }
        $prompt_text = self::build_prompt_text($settings, $product);
        $result = self::call_openai($settings, $prompt_text, $image_url);
        if (is_wp_error($result)) {
            return $result;
        }
        $clean = wp_kses_post($result);
        update_post_meta($product_id, self::META_KEY, $clean);
        update_post_meta($product_id, self::META_GENERATED_AT, current_time('mysql'));
        return $clean;
    }

    public static function get_candidate_product_ids($force = false, $limit = 2000) {
        $args = array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending'),
            'posts_per_page' => max(1, (int) $limit),
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => array(
                array(
                    'key' => '_thumbnail_id',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        if (!$force) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array('key' => self::META_KEY, 'compare' => 'NOT EXISTS'),
                array('key' => self::META_KEY, 'value' => '', 'compare' => '='),
            );
        }
        $query = new WP_Query($args);
        return array_map('intval', $query->posts);
    }

    protected static function check_ajax_permissions() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Jogosultság hiányzik.', 'mockup-generator')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::NONCE_ACTION)) {
            wp_send_json_error(array('message' => __('Érvénytelen kérés (nonce).', 'mockup-generator')), 401);
        }
    }

    public static function ajax_test_connection() {
        self::check_ajax_permissions();
        $result = self::test_connection();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success(array('message' => __('A kapcsolat rendben.', 'mockup-generator')));
    }

    public static function ajax_candidates() {
        self::check_ajax_permissions();
        $force = !empty($_POST['force']) && $_POST['force'] === '1';
        $ids = self::get_candidate_product_ids($force);
        wp_send_json_success(array('ids' => $ids, 'total' => count($ids)));
    }

    public static function ajax_generate_one() {
        self::check_ajax_permissions();
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $force = !empty($_POST['force']) && $_POST['force'] === '1';
        if ($product_id <= 0) {
            wp_send_json_error(array('message' => __('Hiányzó termék ID.', 'mockup-generator')));
        }
        $result = self::generate_for_product($product_id, $force);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'product_id' => $product_id));
        }
        wp_send_json_success(array('product_id' => $product_id, 'text' => $result));
    }
}
