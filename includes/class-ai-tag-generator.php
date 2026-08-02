<?php
if (!defined('ABSPATH')) exit;

/**
 * Kanonikus AI minta-tagelő.
 *
 * A külső ai_rename_gui5.py programmal azonos elvet használ: a modell csak a
 * beépített kanonikus taglistából választhat. A válasz szigorú JSON schema szerint érkezik.
 */
class MG_AI_Tag_Generator {

    const OPTION_KEY = 'mg_ai_tag_settings';
    const OPTION_CUSTOM_DICTIONARY = 'mg_ai_tag_custom_labels';
    const META_RESULT = '_mg_ai_tag_result';
    const META_TAGGED_AT = '_mg_ai_tagged_at';
    const META_DICTIONARY_VERSION = '_mg_ai_tag_dictionary_version';
    const META_CACHE_USAGE = '_mg_ai_tag_cache_usage';
    const DEFAULT_MAX_TOKENS = 4000;
    const MIN_MAX_TOKENS = 4000;
    const DEFAULT_DELAY_MS = 600;
    const DEFAULT_WORKERS = 4;
    const MAX_WORKERS = 8;
    const DEFAULT_CANDIDATE_LIMIT = 10000;
    const NONCE_ACTION = 'mg_ai_tag_nonce';
    const PROMPT_CACHE_PREFIX = 'forme-ai-retag-tags-v2';
    const DICTIONARY_RELATIVE_PATH = 'assets/data/forme-taglista-vegleges-2026-08-02.json';

    private static $dictionary_cache = null;

    public static function init() {
        add_action('wp_ajax_mg_ai_tag_candidates', array(__CLASS__, 'ajax_candidates'));
        add_action('wp_ajax_mg_ai_tag_test_candidates', array(__CLASS__, 'ajax_test_candidates'));
        add_action('wp_ajax_mg_ai_tag_one', array(__CLASS__, 'ajax_tag_one'));
        add_action('admin_post_mg_ai_tag_dictionary_save', array(__CLASS__, 'admin_save_dictionary'));
    }

    public static function get_settings() {
        $defaults = array(
            'enabled' => false,
            'max_output_tokens' => self::DEFAULT_MAX_TOKENS,
            'delay_ms' => self::DEFAULT_DELAY_MS,
            'workers' => self::DEFAULT_WORKERS,
        );
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $settings = array_merge($defaults, $stored);
        $settings['max_output_tokens'] = max(self::MIN_MAX_TOKENS, min(4000, (int) $settings['max_output_tokens']));
        $settings['delay_ms'] = max(0, (int) $settings['delay_ms']);
        $settings['workers'] = max(1, min(self::MAX_WORKERS, (int) $settings['workers']));
        return $settings;
    }

    public static function save_settings(array $input) {
        $clean = array(
            'enabled' => !empty($input['enabled']),
            'max_output_tokens' => isset($input['max_output_tokens'])
                ? max(self::MIN_MAX_TOKENS, min(4000, intval($input['max_output_tokens'])))
                : self::DEFAULT_MAX_TOKENS,
            'delay_ms' => isset($input['delay_ms'])
                ? max(0, intval($input['delay_ms']))
                : self::DEFAULT_DELAY_MS,
            'workers' => isset($input['workers'])
                ? max(1, min(self::MAX_WORKERS, intval($input['workers'])))
                : self::DEFAULT_WORKERS,
        );
        update_option(self::OPTION_KEY, $clean);
        return $clean;
    }

    /**
     * Administrator-maintained additions are stored in a WordPress option,
     * not in the plugin JSON, so plugin updates cannot overwrite them.
     */
    public static function get_custom_labels() {
        $stored = get_option(self::OPTION_CUSTOM_DICTIONARY, array());
        if (!is_array($stored)) {
            $stored = is_string($stored) ? preg_split('/\r\n|\r|\n/', $stored) : array();
        }

        $labels = array();
        $normalized = array();
        foreach ($stored as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $label = sanitize_text_field((string) $value);
            $label = trim(preg_replace('/\s+/', ' ', $label));
            if ($label === '') {
                continue;
            }
            $label = function_exists('mb_substr')
                ? mb_substr($label, 0, 80, 'UTF-8')
                : substr($label, 0, 80);
            $key = self::normalize_concept($label);
            if ($key === '' || isset($normalized[$key])) {
                continue;
            }
            $normalized[$key] = true;
            $labels[] = $label;
        }
        return $labels;
    }

    public static function save_custom_labels($input) {
        if (is_string($input)) {
            $input = preg_split('/\r\n|\r|\n/', $input);
        }
        $input = is_array($input) ? $input : array();
        $clean = array();
        $normalized = array();
        foreach ($input as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $label = sanitize_text_field((string) $value);
            $label = trim(preg_replace('/\s+/', ' ', $label));
            if ($label === '') {
                continue;
            }
            $label = function_exists('mb_substr')
                ? mb_substr($label, 0, 80, 'UTF-8')
                : substr($label, 0, 80);
            $key = self::normalize_concept($label);
            if ($key === '' || isset($normalized[$key])) {
                continue;
            }
            $normalized[$key] = true;
            $clean[] = $label;
        }
        update_option(self::OPTION_CUSTOM_DICTIONARY, $clean, false);
        self::$dictionary_cache = null;
        return $clean;
    }

    public static function admin_save_dictionary() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_ai_tag_dictionary_save_action');

        $input = isset($_POST['mg_ai_tag_custom_labels'])
            ? wp_unslash($_POST['mg_ai_tag_custom_labels'])
            : '';
        self::save_custom_labels($input);

        wp_redirect(admin_url('admin.php?page=mockup-generator&mg_tab=ai_seo&tag_dictionary_updated=1'));
        exit;
    }

    public static function get_dictionary() {
        if (is_array(self::$dictionary_cache)) {
            return self::$dictionary_cache;
        }

        $path = dirname(__DIR__) . '/' . self::DICTIONARY_RELATIVE_PATH;
        if (!file_exists($path) || !is_readable($path)) {
            self::$dictionary_cache = new WP_Error(
                'mg_ai_tag_dictionary_missing',
                __('A kanonikus taglista JSON nem található a pluginban.', 'mockup-generator')
            );
            return self::$dictionary_cache;
        }

        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::$dictionary_cache = new WP_Error(
                'mg_ai_tag_dictionary_invalid',
                __('A kanonikus taglista JSON hibás.', 'mockup-generator')
            );
            return self::$dictionary_cache;
        }

        $records = array();
        $labels = array();
        $normalized_labels = array();
        foreach ((array) ($data['tags'] ?? array()) as $item) {
            if (is_string($item)) {
                $label = trim($item);
                $record = array('id' => sanitize_title($label), 'label' => $label, 'group' => '');
            } elseif (is_array($item)) {
                $label = isset($item['label']) ? trim((string) $item['label']) : '';
                $record = array(
                    'id' => isset($item['id']) ? sanitize_key($item['id']) : sanitize_title($label),
                    'label' => $label,
                    'group' => isset($item['group']) ? trim((string) $item['group']) : '',
                );
            } else {
                continue;
            }
            $normalized_label = self::normalize_concept($label);
            if ($label === '' || isset($labels[$label]) || ($normalized_label !== '' && isset($normalized_labels[$normalized_label]))) {
                continue;
            }
            $labels[$label] = true;
            if ($normalized_label !== '') {
                $normalized_labels[$normalized_label] = true;
            }
            $records[] = $record;
        }

        foreach (self::get_custom_labels() as $label) {
            $normalized_label = self::normalize_concept($label);
            if ($normalized_label === '' || isset($normalized_labels[$normalized_label])) {
                continue;
            }
            $labels[$label] = true;
            $normalized_labels[$normalized_label] = true;
            $records[] = array(
                'id' => 'custom-' . sanitize_title($label),
                'label' => $label,
                'group' => 'Egyedi',
            );
        }

        if (!$records) {
            self::$dictionary_cache = new WP_Error(
                'mg_ai_tag_dictionary_empty',
                __('A kanonikus taglista nem tartalmaz használható taget.', 'mockup-generator')
            );
            return self::$dictionary_cache;
        }

        $base_version = (string) ($data['dictionary_version'] ?? ($data['version'] ?? 'ismeretlen'));
        $custom_labels = self::get_custom_labels();
        $version = $base_version;
        if ($custom_labels) {
            $version .= '-custom-' . substr(md5(implode("\n", $custom_labels)), 0, 8);
        }

        self::$dictionary_cache = array(
            'version' => $version,
            'records' => $records,
            'labels' => array_keys($labels),
            'path' => $path,
        );
        return self::$dictionary_cache;
    }

    public static function get_dictionary_labels() {
        $dictionary = self::get_dictionary();
        return is_wp_error($dictionary) ? array() : $dictionary['labels'];
    }

    private static function get_openai_settings() {
        $seo = class_exists('MG_AI_SEO_Generator') ? MG_AI_SEO_Generator::get_settings() : array();
        return array(
            'api_key' => isset($seo['api_key']) ? trim((string) $seo['api_key']) : '',
            'model' => !empty($seo['model']) ? (string) $seo['model'] : 'gpt-5-mini',
            'endpoint' => !empty($seo['endpoint']) ? (string) $seo['endpoint'] : 'https://api.openai.com/v1/responses',
        );
    }

    private static function build_schema($dictionary) {
        return array(
            'name' => 'forme_retag_tags_v2',
            'schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'tags' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string', 'enum' => array_values($dictionary['labels'])),
                        'minItems' => 0,
                        'maxItems' => 8,
                    ),
                ),
                'required' => array('tags'),
            ),
        );
    }

    private static function build_instructions($dictionary) {
        $allowed_tags = implode(', ', $dictionary['labels']);
        return "Analyze the attached forme.hu design. Return only 0-8 tags from the canonical list below. "
            . "Choose only concepts clearly visible in the image. Do not return colors, graphic properties, styles, text, quotes, "
            . "free text, a title, a category, a confidence value, or unmatched concepts. Do not invent tags. "
            . "Return only the JSON object required by the schema. "
            . "Canonical tag list (dictionary_version=" . $dictionary['version'] . "): [" . $allowed_tags . "]";
    }

    private static function build_cache_key($instructions, $schema, $model = '', $shard = 0) {
        $material = $model . "\n" . $instructions . "\n" . wp_json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
        );
        return self::PROMPT_CACHE_PREFIX . ':' . substr(hash('sha256', $material), 0, 20) . ':' . absint($shard);
    }

    private static function get_image_url_for_product($product) {
        if (!$product || !method_exists($product, 'get_image_id')) {
            return '';
        }
        $image_id = (int) $product->get_image_id();
        if ($image_id <= 0) {
            return '';
        }
        $url = wp_get_attachment_url($image_id);
        return is_string($url) ? $url : '';
    }

    private static function extract_output_text($data) {
        if (!is_array($data)) {
            return '';
        }
        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            return trim($data['output_text']);
        }
        $chunks = array();
        $output = is_array($data) ? ($data['output'] ?? array()) : array();
        foreach ((array) $output as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content_items = $item['content'] ?? array();
            if (is_array($content_items) && (isset($content_items['type']) || isset($content_items['text']) || isset($content_items['parsed']))) {
                $content_items = array($content_items);
            }
            foreach ((array) $content_items as $content) {
                if (!is_array($content)) {
                    continue;
                }
                if (isset($content['text']) && $content['text'] !== '' && (!isset($content['type']) || in_array($content['type'], array('output_text', 'text'), true))) {
                    $chunks[] = is_string($content['text'])
                        ? $content['text']
                        : wp_json_encode($content['text'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (isset($content['parsed']) && is_array($content['parsed'])) {
                    $chunks[] = wp_json_encode($content['parsed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }
        return trim(implode("\n", $chunks));
    }

    private static function describe_empty_response($data) {
        $status = is_array($data) ? (string) ($data['status'] ?? '') : '';
        $reason = is_array($data) && isset($data['incomplete_details']['reason'])
            ? (string) $data['incomplete_details']['reason'] : '';
        $refusal = '';
        $types = array();
        $output = is_array($data) ? ($data['output'] ?? array()) : array();
        foreach ((array) $output as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['type'])) {
                $types[] = (string) $item['type'];
            }
            $content_items = $item['content'] ?? array();
            if (is_array($content_items) && (isset($content_items['type']) || isset($content_items['text']) || isset($content_items['parsed']) || isset($content_items['refusal']))) {
                $content_items = array($content_items);
            }
            foreach ((array) $content_items as $content) {
                if (is_array($content) && !empty($content['type'])) {
                    $types[] = (string) $content['type'];
                }
                if (is_array($content) && !empty($content['refusal'])) {
                    $refusal = (string) $content['refusal'];
                }
            }
        }
        $types = array_values(array_unique($types));

        if ($refusal !== '') {
            return sprintf(__('Az AI megtagadta a választ: %s', 'mockup-generator'), $refusal);
        }
        if ($reason !== '') {
            $message = sprintf(__('Az AI válasza hiányos (%s).', 'mockup-generator'), $reason);
            if ($reason === 'max_output_tokens') {
                $message .= ' ' . sprintf(__('A max. kimeneti token legyen legalább %d.', 'mockup-generator'), self::MIN_MAX_TOKENS);
            }
            return $message;
        }

        $details = array();
        if ($status !== '') {
            $details[] = 'status=' . $status;
        }
        if (!empty($types)) {
            $details[] = 'output=' . implode(',', $types);
        }
        return __('Az AI nem adott vissza tagelési választ.', 'mockup-generator')
            . (!empty($details) ? ' (' . implode('; ', $details) . ')' : '');
    }

    private static function decode_json_result($text) {
        $text = trim((string) $text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) {
            $text = trim($matches[1]);
        }
        $data = json_decode($text, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $text, $matches)) {
            $data = json_decode(trim($matches[0]), true);
        }
        return is_array($data) ? $data : new WP_Error(
            'mg_ai_tag_invalid_json',
            __('Az AI válasza nem érvényes JSON.', 'mockup-generator')
        );
    }

    private static function call_openai($settings, $product, $instructions, $schema, $cache_key) {
        if (empty($settings['api_key'])) {
            return new WP_Error('mg_ai_tag_no_key', __('Nincs megadva OpenAI API kulcs az AI SEO beállításoknál.', 'mockup-generator'));
        }

        $image_url = self::get_image_url_for_product($product);
        if ($image_url === '') {
            return new WP_Error('mg_ai_tag_no_image', __('A terméknek nincs kiemelt (mockup) képe.', 'mockup-generator'));
        }

        $content = array(
            array('type' => 'input_text', 'text' => 'Return the canonical tags for this design according to the instructions.'),
            array('type' => 'input_image', 'image_url' => $image_url, 'detail' => 'low'),
        );
        $body = array(
            'model' => $settings['model'],
            'instructions' => $instructions,
            'input' => array(array('role' => 'user', 'content' => $content)),
            'text' => array('format' => array(
                'type' => 'json_schema',
                'name' => $schema['name'],
                'schema' => $schema['schema'],
            )),
            'max_output_tokens' => (int) $settings['max_output_tokens'],
            'store' => false,
            // A modell és a statikus taglista-prefix azonos marad; a kép a változó rész.
            'prompt_cache_key' => $cache_key,
        );

        $response = wp_remote_post($settings['endpoint'], array(
            'timeout' => 120,
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
            $message = is_array($data) && isset($data['error']['message'])
                ? $data['error']['message']
                : ('HTTP ' . $code);
            return new WP_Error('mg_ai_tag_api_error', $message);
        }

        $text = self::extract_output_text($data);
        if ($text === '') {
            return new WP_Error('mg_ai_tag_empty', self::describe_empty_response($data));
        }
        $meta = self::decode_json_result($text);
        if (is_wp_error($meta)) {
            return $meta;
        }

        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : array();
        $details = isset($usage['input_tokens_details']) && is_array($usage['input_tokens_details'])
            ? $usage['input_tokens_details'] : array();
        $cache_usage = array(
            'cached_tokens' => (int) ($details['cached_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($details['cache_write_tokens'] ?? 0),
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
        );
        return array('meta' => $meta, 'cache_usage' => $cache_usage);
    }

    private static function sanitize_result($meta, $dictionary) {
        $allowed = array_fill_keys($dictionary['labels'], true);
        $tags = array();
        foreach ((array) ($meta['tags'] ?? array()) as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && isset($allowed[$tag]) && !in_array($tag, $tags, true) && count($tags) < 8) {
                $tags[] = $tag;
            }
        }

        return array(
            'tags' => $tags,
            'tag_dictionary_version' => (string) $dictionary['version'],
        );
    }

    private static function normalize_concept($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    private static function ensure_tag_terms($labels) {
        $term_ids = array();
        foreach ((array) $labels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $existing = term_exists($label, 'product_tag');
            if ($existing) {
                $term_ids[] = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
                continue;
            }
            // Ne adjunk meg kézzel slugot: egy régi, eltérő nevű zajtag azonos
            // slugja ne akadályozza meg a kanonikus term létrehozását.
            $created = wp_insert_term($label, 'product_tag');
            if (is_wp_error($created)) {
                return $created;
            }
            $term_ids[] = (int) $created['term_id'];
        }
        return array_values(array_unique(array_filter($term_ids)));
    }

    public static function retag_for_product($product_id, $options = array()) {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return new WP_Error('mg_ai_tag_disabled', __('Az AI minta-tagelés ki van kapcsolva.', 'mockup-generator'));
        }

        $product_id = absint($product_id);
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return new WP_Error('mg_ai_tag_no_product', __('A termék nem található.', 'mockup-generator'));
        }

        $dictionary = self::get_dictionary();
        if (is_wp_error($dictionary)) {
            return $dictionary;
        }
        $schema = self::build_schema($dictionary);
        $instructions = self::build_instructions($dictionary);
        $api_settings = self::get_openai_settings();
        $api_settings['max_output_tokens'] = (int) $settings['max_output_tokens'];
        $cache_shards = max(1, min(8, (int) ($options['cache_shards'] ?? 1)));
        $cache_shard = max(0, min($cache_shards - 1, (int) ($options['cache_shard'] ?? 0)));
        $cache_key = self::build_cache_key($instructions, $schema, $api_settings['model'], $cache_shard);

        $result = self::call_openai($api_settings, $product, $instructions, $schema, $cache_key);
        if (is_wp_error($result)) {
            return $result;
        }

        $meta = self::sanitize_result($result['meta'], $dictionary);
        $tag_labels = $meta['tags'];
        $replace_tags = !empty($options['replace_tags']);
        $preview = !empty($options['preview']);
        $tag_ids = array();
        if (!$preview) {
            $tag_ids = self::ensure_tag_terms($tag_labels);
            if (is_wp_error($tag_ids)) {
                return $tag_ids;
            }
        }

        $cache_usage = $result['cache_usage'];
        if ($preview) {
            return array(
                'product_id' => $product_id,
                'tags' => $tag_labels,
                'cache_usage' => $cache_usage,
                'prompt_cache_key' => $cache_key,
                'replace_tags' => $replace_tags,
                'preview' => true,
                'saved' => false,
            );
        }

        $assigned = wp_set_object_terms($product_id, $tag_ids, 'product_tag', !$replace_tags);
        if (is_wp_error($assigned)) {
            return $assigned;
        }

        if (class_exists('MG_Gift_Finder') && method_exists('MG_Gift_Finder', 'bump_cache_version')) {
            MG_Gift_Finder::bump_cache_version();
        }

        update_post_meta($product_id, self::META_RESULT, wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_post_meta($product_id, self::META_TAGGED_AT, current_time('mysql'));
        update_post_meta($product_id, self::META_DICTIONARY_VERSION, $dictionary['version']);
        update_post_meta($product_id, self::META_CACHE_USAGE, wp_json_encode($cache_usage));

        return array(
            'product_id' => $product_id,
            'tags' => $tag_labels,
            'cache_usage' => $cache_usage,
            'prompt_cache_key' => $cache_key,
            'replace_tags' => $replace_tags,
        );
    }

    public static function get_candidate_product_ids($force = false, $limit = self::DEFAULT_CANDIDATE_LIMIT) {
        $dictionary = self::get_dictionary();
        $dictionary_version = is_wp_error($dictionary) ? '' : (string) $dictionary['version'];
        $args = array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending'),
            'posts_per_page' => max(1, (int) $limit),
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query' => array(array(
                'key' => '_thumbnail_id',
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            )),
        );
        if (!$force) {
            $not_processed = array(
                'relation' => 'OR',
                array('key' => self::META_TAGGED_AT, 'compare' => 'NOT EXISTS'),
                array('key' => self::META_TAGGED_AT, 'value' => '', 'compare' => '='),
            );
            if ($dictionary_version !== '') {
                $not_processed[] = array('key' => self::META_DICTIONARY_VERSION, 'compare' => 'NOT EXISTS');
                $not_processed[] = array('key' => self::META_DICTIONARY_VERSION, 'value' => $dictionary_version, 'compare' => '!=');
            }
            $args['meta_query'][] = $not_processed;
        }
        $query = new WP_Query($args);
        return array_map('intval', (array) $query->posts);
    }

    public static function check_ajax_permissions() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Jogosultság hiányzik.', 'mockup-generator')), 403);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::NONCE_ACTION)) {
            wp_send_json_error(array('message' => __('Érvénytelen kérés (nonce).', 'mockup-generator')), 401);
        }
    }

    public static function ajax_candidates() {
        self::check_ajax_permissions();
        $force = !empty($_POST['force']) && $_POST['force'] === '1';
        $ids = self::get_candidate_product_ids($force);
        $dictionary = self::get_dictionary();
        if (is_wp_error($dictionary)) {
            wp_send_json_error(array('message' => $dictionary->get_error_message()));
        }
        $version = $dictionary['version'];
        wp_send_json_success(array(
            'ids' => $ids,
            'total' => count($ids),
            'cache_shards' => max(1, min(8, (int) ceil(count($ids) / 15))),
            'dictionary_version' => $version,
            'dictionary_count' => count($dictionary['labels']),
        ));
    }

    /**
     * Searchable product list for the single-sample preview in the admin UI.
     * This endpoint only reads products; the actual preview call still runs
     * through retag_for_product() with the explicit preview flag.
     */
    public static function ajax_test_candidates() {
        self::check_ajax_permissions();

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $args = array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending'),
            'posts_per_page' => 50,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query' => array(array(
                'key' => '_thumbnail_id',
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            )),
        );

        // A numeric search is an exact product ID lookup. For text, WP_Query's
        // normal title/content/excerpt search is useful for the sample name.
        if ($search !== '' && ctype_digit($search)) {
            $args['p'] = absint($search);
            $args['posts_per_page'] = 1;
        } elseif ($search !== '') {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $products = array();
        foreach ((array) $query->posts as $id) {
            $id = absint($id);
            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
            if (!$product) {
                continue;
            }
            $image_id = method_exists($product, 'get_image_id') ? absint($product->get_image_id()) : 0;
            if ($image_id <= 0) {
                continue;
            }
            $products[] = array(
                'id' => $id,
                'name' => method_exists($product, 'get_name') ? (string) $product->get_name() : get_the_title($id),
                'sku' => method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
                'image_url' => $image_id ? (string) wp_get_attachment_image_url($image_id, 'thumbnail') : '',
            );
        }

        wp_send_json_success(array('products' => $products));
    }

    public static function ajax_tag_one() {
        self::check_ajax_permissions();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if ($product_id <= 0) {
            wp_send_json_error(array('message' => __('Hiányzó termék ID.', 'mockup-generator')));
        }
        $options = array(
            'replace_tags' => !empty($_POST['replace_tags']) && $_POST['replace_tags'] === '1',
            'preview' => !empty($_POST['preview']) && $_POST['preview'] === '1',
            'cache_shard' => isset($_POST['cache_shard']) ? absint($_POST['cache_shard']) : 0,
            'cache_shards' => isset($_POST['cache_shards']) ? max(1, min(8, absint($_POST['cache_shards']))) : 1,
        );
        $result = self::retag_for_product($product_id, $options);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message(), 'product_id' => $product_id));
        }
        wp_send_json_success($result);
    }
}
