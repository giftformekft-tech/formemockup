<?php
if (!defined('ABSPATH')) exit;

/**
 * Kanonikus AI minta-tagelő és kategorizáló.
 *
 * A külső ai_rename_gui5.py programmal azonos elvet használ: a modell csak a
 * beépített kanonikus taglistából választhat, a kategóriákat pedig a WooCommerce
 * product_cat taxonómiából kapja. A válasz szigorú JSON schema szerint érkezik.
 */
class MG_AI_Tag_Generator {

    const OPTION_KEY = 'mg_ai_tag_settings';
    const META_RESULT = '_mg_ai_tag_result';
    const META_TAGGED_AT = '_mg_ai_tagged_at';
    const META_DICTIONARY_VERSION = '_mg_ai_tag_dictionary_version';
    const META_UNMATCHED = '_mg_ai_tag_unmatched_concepts';
    const META_CACHE_USAGE = '_mg_ai_tag_cache_usage';
    const DEFAULT_MAX_TOKENS = 4000;
    const MIN_MAX_TOKENS = 4000;
    const DEFAULT_DELAY_MS = 600;
    const DEFAULT_WORKERS = 4;
    const MAX_WORKERS = 8;
    const DEFAULT_CANDIDATE_LIMIT = 10000;
    const NONCE_ACTION = 'mg_ai_tag_nonce';
    const PROMPT_CACHE_PREFIX = 'forme-ai-retag-canonical-v1';
    const DICTIONARY_RELATIVE_PATH = 'assets/data/forme-taglista-vegleges-2026-08-02.json';

    private static $dictionary_cache = null;
    private static $category_cache = null;

    public static function init() {
        add_action('wp_ajax_mg_ai_tag_candidates', array(__CLASS__, 'ajax_candidates'));
        add_action('wp_ajax_mg_ai_tag_one', array(__CLASS__, 'ajax_tag_one'));
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
     * A taglista a plugin része, így az admin oldalon és a futtatáskor mindig
     * ugyanaz a verzió használható, mint a külső programban.
     */
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
            if ($label === '' || isset($labels[$label])) {
                continue;
            }
            $labels[$label] = true;
            $records[] = $record;
        }

        if (!$records) {
            self::$dictionary_cache = new WP_Error(
                'mg_ai_tag_dictionary_empty',
                __('A kanonikus taglista nem tartalmaz használható taget.', 'mockup-generator')
            );
            return self::$dictionary_cache;
        }

        self::$dictionary_cache = array(
            'version' => (string) ($data['dictionary_version'] ?? ($data['version'] ?? 'ismeretlen')),
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

    /**
     * WooCommerce kategóriák két szintű, promptbarát reprezentációja.
     * A modell ID-t ad vissza, ezért az azonos nevű kategóriák sem keverednek.
     */
    public static function get_category_catalog() {
        if (is_array(self::$category_cache)) {
            return self::$category_cache;
        }

        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'term_id',
            'order' => 'ASC',
        ));
        if (is_wp_error($terms)) {
            self::$category_cache = array(
                'roots' => array(),
                'root_ids' => array(),
                'sub_ids' => array(),
                'sub_to_root' => array(),
                'terms' => array(),
            );
            return self::$category_cache;
        }

        $term_by_id = array();
        foreach ($terms as $term) {
            $term_by_id[(int) $term->term_id] = $term;
        }

        $roots = array();
        foreach ($terms as $term) {
            if ((int) $term->parent !== 0) {
                continue;
            }
            $roots[(int) $term->term_id] = array(
                'id' => (int) $term->term_id,
                'name' => (string) $term->name,
                'children' => array(),
            );
        }

        $sub_to_root = array();
        foreach ($terms as $term) {
            $term_id = (int) $term->term_id;
            if ((int) $term->parent === 0) {
                continue;
            }

            $ancestors = get_ancestors($term_id, 'product_cat', 'taxonomy');
            $root_id = $ancestors ? (int) end($ancestors) : (int) $term->parent;
            if (!isset($roots[$root_id])) {
                $parent = isset($term_by_id[$root_id]) ? $term_by_id[$root_id] : null;
                $roots[$root_id] = array(
                    'id' => $root_id,
                    'name' => $parent ? (string) $parent->name : __('Egyéb', 'mockup-generator'),
                    'children' => array(),
                );
            }

            $path_parts = array((string) $term->name);
            foreach ($ancestors as $ancestor_id) {
                if (isset($term_by_id[(int) $ancestor_id])) {
                    array_unshift($path_parts, (string) $term_by_id[(int) $ancestor_id]->name);
                }
            }
            $roots[$root_id]['children'][] = array(
                'id' => $term_id,
                'name' => (string) $term->name,
                'path' => implode(' › ', $path_parts),
            );
            $sub_to_root[$term_id] = $root_id;
        }

        $root_ids = array_map('intval', array_keys($roots));
        sort($root_ids, SORT_NUMERIC);
        $ordered_roots = array();
        foreach ($root_ids as $root_id) {
            $root = $roots[$root_id];
            usort($root['children'], function ($a, $b) {
                return strcasecmp($a['path'], $b['path']);
            });
            $ordered_roots[] = $root;
        }

        $sub_ids = array_keys($sub_to_root);
        $sub_ids = array_map('intval', $sub_ids);
        sort($sub_ids, SORT_NUMERIC);

        self::$category_cache = array(
            'roots' => $ordered_roots,
            'root_ids' => $root_ids,
            'sub_ids' => $sub_ids,
            'sub_to_root' => $sub_to_root,
            'terms' => $term_by_id,
        );
        return self::$category_cache;
    }

    private static function get_openai_settings() {
        $seo = class_exists('MG_AI_SEO_Generator') ? MG_AI_SEO_Generator::get_settings() : array();
        return array(
            'api_key' => isset($seo['api_key']) ? trim((string) $seo['api_key']) : '',
            'model' => !empty($seo['model']) ? (string) $seo['model'] : 'gpt-5-mini',
            'endpoint' => !empty($seo['endpoint']) ? (string) $seo['endpoint'] : 'https://api.openai.com/v1/responses',
        );
    }

    private static function build_schema($dictionary, $catalog) {
        $root_ids = array_merge(array(0), array_map('intval', (array) $catalog['root_ids']));
        $sub_ids = array_merge(array(0), array_map('intval', (array) $catalog['sub_ids']));
        return array(
            'name' => 'forme_retag_v1',
            'schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'title_hu' => array('type' => 'string'),
                    'confidence' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1),
                    'tags' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string', 'enum' => array_values($dictionary['labels'])),
                        'minItems' => 0,
                        'maxItems' => 8,
                    ),
                    'unmatched_concepts' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                        'minItems' => 0,
                        'maxItems' => 5,
                    ),
                    'categories' => array(
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => array(
                            'main_id' => array('type' => 'integer', 'enum' => array_values($root_ids)),
                            'sub_id' => array('type' => 'integer', 'enum' => array_values($sub_ids)),
                        ),
                        'required' => array('main_id', 'sub_id'),
                    ),
                ),
                'required' => array('title_hu', 'confidence', 'tags', 'unmatched_concepts', 'categories'),
            ),
        );
    }

    private static function build_instructions($dictionary, $catalog) {
        $category_json = wp_json_encode(
            $catalog['roots'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $allowed_tags = implode(', ', $dictionary['labels']);

        return "Te a forme.hu egyedi póló- és ajándékwebáruházának magyar nyelvű mintaelemzője vagy. "
            . "A mellékelt képen látható mintát elemezd, és kizárólag a kért JSON sémában válaszolj. "
            . "title_hu: a minta rövid, felismerhető magyar neve, legfeljebb 80 karakter; ne írd bele, hogy póló. "
            . "confidence: 0 és 1 közötti bizonyosság. "
            . "tags: 0–8 darab kanonikus tag. KIZÁRÓLAG az alábbi lista pontos label értékeiből válassz; "
            . "ne adj hozzá színt, grafikai tulajdonságot, stílus- vagy idézettaget, ragozott alakot, SEO-mondatot "
            . "vagy szabad szöveget. Ha nincs biztosan illő tag, hagyd üresen. "
            . "unmatched_concepts: legfeljebb 5 lényeges, de a kanonikus listában nem szereplő fogalom javaslatként; "
            . "ezek nem kerülhetnek a tags tömbbe. Konkrét film, sorozat, játék vagy márka csak akkor legyen tag, "
            . "ha a képen egyértelműen felismerhető. "
            . "categories.main_id és categories.sub_id: a megadott WooCommerce kategórialistából válassz ID-t. "
            . "Ha nincs megfelelő kategória, 0 legyen. A sub_id csak a hozzá tartozó fő kategória alá tartozhat. "
            . "A jelenlegi régi tageket ne másold át automatikusan, mert azok lehetnek zajosak. "
            . "Kanonikus taglista (dictionary_version=" . $dictionary['version'] . "): [" . $allowed_tags . "] "
            . "WooCommerce kategóriák: " . ($category_json ?: '[]') . " "
            . "Válaszolj kizárólag a JSON sémában.";
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

    private static function get_product_context($product) {
        $categories = array();
        if ($product && method_exists($product, 'get_id')) {
            $terms = wp_get_post_terms($product->get_id(), 'product_cat');
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = (string) $term->name;
                }
            }
        }
        return "Termék neve (csak támpont, ne másold vakon): "
            . ($product && method_exists($product, 'get_name') ? $product->get_name() : '')
            . "\nJelenlegi WooCommerce kategóriák (csak támpont): " . implode(', ', $categories);
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
            array('type' => 'input_text', 'text' => 'Elemezd a képet a fenti szabályok szerint, és add vissza a JSON sémát.'),
            array('type' => 'input_text', 'text' => self::get_product_context($product)),
            array('type' => 'input_image', 'image_url' => $image_url),
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
            // A modell és a statikus szótár/kategória-prefix azonos marad; a kép a változó rész.
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

    private static function sanitize_result($meta, $dictionary, $catalog) {
        if (!is_array($meta)) {
            $meta = array();
        }
        $allowed = array_fill_keys($dictionary['labels'], true);
        $tags = array();
        foreach ((array) ($meta['tags'] ?? array()) as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && isset($allowed[$tag]) && !in_array($tag, $tags, true) && count($tags) < 8) {
                $tags[] = $tag;
            }
        }

        $categories = isset($meta['categories']) && is_array($meta['categories']) ? $meta['categories'] : array();
        $main_id = absint($categories['main_id'] ?? 0);
        $sub_id = absint($categories['sub_id'] ?? 0);
        if ($main_id !== 0 && !in_array($main_id, $catalog['root_ids'], true)) {
            $main_id = 0;
        }
        if ($sub_id !== 0 && !in_array($sub_id, $catalog['sub_ids'], true)) {
            $sub_id = 0;
        }
        if ($sub_id > 0) {
            $expected_main = (int) ($catalog['sub_to_root'][$sub_id] ?? 0);
            if ($expected_main > 0) {
                $main_id = $expected_main;
            }
        }

        $unmatched = array();
        foreach ((array) ($meta['unmatched_concepts'] ?? array()) as $concept) {
            $concept = sanitize_text_field((string) $concept);
            if ($concept !== '' && !in_array($concept, $unmatched, true) && count($unmatched) < 5) {
                $unmatched[] = $concept;
            }
        }

        return array(
            'title_hu' => sanitize_text_field((string) ($meta['title_hu'] ?? '')),
            'confidence' => max(0, min(1, (float) ($meta['confidence'] ?? 0))),
            'tags' => $tags,
            'unmatched_concepts' => $unmatched,
            'categories' => array('main_id' => $main_id, 'sub_id' => $sub_id),
            'tag_dictionary_version' => (string) $dictionary['version'],
        );
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

    private static function category_term_ids($main_id, $sub_id, $catalog) {
        $ids = array();
        $main_id = absint($main_id);
        $sub_id = absint($sub_id);
        if ($main_id > 0 && in_array($main_id, $catalog['root_ids'], true)) {
            $ids[] = $main_id;
        }
        if ($sub_id > 0 && in_array($sub_id, $catalog['sub_ids'], true)) {
            $ids[] = $sub_id;
            foreach ((array) get_ancestors($sub_id, 'product_cat', 'taxonomy') as $ancestor_id) {
                $ancestor_id = absint($ancestor_id);
                if ($ancestor_id > 0) {
                    $ids[] = $ancestor_id;
                }
            }
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
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
        $catalog = self::get_category_catalog();
        $schema = self::build_schema($dictionary, $catalog);
        $instructions = self::build_instructions($dictionary, $catalog);
        $api_settings = self::get_openai_settings();
        $api_settings['max_output_tokens'] = (int) $settings['max_output_tokens'];
        $cache_shards = max(1, min(8, (int) ($options['cache_shards'] ?? 1)));
        $cache_shard = max(0, min($cache_shards - 1, (int) ($options['cache_shard'] ?? 0)));
        $cache_key = self::build_cache_key($instructions, $schema, $api_settings['model'], $cache_shard);

        $result = self::call_openai($api_settings, $product, $instructions, $schema, $cache_key);
        if (is_wp_error($result)) {
            return $result;
        }
        $meta = self::sanitize_result($result['meta'], $dictionary, $catalog);
        $tag_labels = $meta['tags'];
        $tag_ids = self::ensure_tag_terms($tag_labels);
        if (is_wp_error($tag_ids)) {
            return $tag_ids;
        }

        $replace_tags = !empty($options['replace_tags']);
        $update_categories = !empty($options['update_categories']);
        if ($replace_tags) {
            $assigned = wp_set_object_terms($product_id, $tag_ids, 'product_tag', false);
        } else {
            $assigned = wp_set_object_terms($product_id, $tag_ids, 'product_tag', true);
        }
        if (is_wp_error($assigned)) {
            return $assigned;
        }

        $category_ids = array();
        if ($update_categories) {
            $category_ids = self::category_term_ids(
                $meta['categories']['main_id'],
                $meta['categories']['sub_id'],
                $catalog
            );
            if ($category_ids) {
                $category_result = wp_set_object_terms($product_id, $category_ids, 'product_cat', false);
                if (is_wp_error($category_result)) {
                    return $category_result;
                }
            }
        }

        // A kategória/tag változás a gift finder rangsorának és facet-cache-ének
        // újraszámítását is igényli. A saját bump metódus percenként legfeljebb
        // egyszer ír optiont, ezért tömeges futásnál sem okoz egy írást/mintát.
        if (class_exists('MG_Gift_Finder') && method_exists('MG_Gift_Finder', 'bump_cache_version')) {
            MG_Gift_Finder::bump_cache_version();
        }

        $cache_usage = $result['cache_usage'];
        update_post_meta($product_id, self::META_RESULT, wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_post_meta($product_id, self::META_TAGGED_AT, current_time('mysql'));
        update_post_meta($product_id, self::META_DICTIONARY_VERSION, $dictionary['version']);
        update_post_meta($product_id, self::META_UNMATCHED, wp_json_encode($meta['unmatched_concepts'], JSON_UNESCAPED_UNICODE));
        update_post_meta($product_id, self::META_CACHE_USAGE, wp_json_encode($cache_usage));

        return array(
            'product_id' => $product_id,
            'tags' => $tag_labels,
            'categories' => $meta['categories'],
            'category_ids' => $category_ids,
            'unmatched_concepts' => $meta['unmatched_concepts'],
            'confidence' => $meta['confidence'],
            'cache_usage' => $cache_usage,
            'prompt_cache_key' => $cache_key,
            'replace_tags' => $replace_tags,
            'update_categories' => $update_categories,
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
            'meta_query' => array(array('key' => '_thumbnail_id', 'compare' => 'EXISTS')),
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

    public static function ajax_tag_one() {
        self::check_ajax_permissions();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if ($product_id <= 0) {
            wp_send_json_error(array('message' => __('Hiányzó termék ID.', 'mockup-generator')));
        }
        $options = array(
            'replace_tags' => !empty($_POST['replace_tags']) && $_POST['replace_tags'] === '1',
            'update_categories' => !empty($_POST['update_categories']) && $_POST['update_categories'] === '1',
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
