<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI-keresők (ChatGPT, Perplexity, Claude, Copilot, Google AI) segítése.
 *
 * - /llms.txt: a bolt rövid, géppel olvasható összefoglalója a kategóriákkal
 *   és a fontos oldalakkal (llmstxt.org formátum). Ha a webgyökérben fizikai
 *   llms.txt van (pl. a Hostinger Tools bővítményé), a webszerver azt adja ki,
 *   ezért a beállításoldal jelzi és törölhetővé teszi.
 * - robots.txt ellenőrzés: megmutatja, hogy a kereső- és AI-botok közül
 *   melyik tilthatja ki a robots.txt (pl. a Cloudflare kezelt robots.txt-je).
 */
class MG_SEO_AI_Visibility {
    const LLMS_CACHE = 'mg_seo_llms_txt';
    const ROBOTS_CACHE = 'mg_seo_ai_robots';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_serve_llms_txt'), 1);
        foreach (array('mg_seo_term_saved', 'mg_seo_settings_saved', 'created_product_cat', 'edited_product_cat', 'delete_product_cat', 'save_post_page', 'deleted_post') as $hook) {
            add_action($hook, array(__CLASS__, 'flush_llms_cache'));
        }
    }

    /** A webgyökérben lévő fizikai llms.txt útvonala, ha van: ilyenkor a webszerver azt adja ki, nem a miénket. */
    public static function physical_llms_path() {
        if (!function_exists('get_home_path') && is_admin() && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $root = function_exists('get_home_path') ? get_home_path() : ABSPATH;
        $path = trailingslashit($root) . 'llms.txt';
        return is_file($path) ? $path : '';
    }

    /** A fizikai llms.txt készítője a fájl végi megjegyzés alapján (a Hostinger Tools ide írja magát). */
    public static function physical_llms_generator($path) {
        $handle = $path !== '' ? @fopen($path, 'rb') : false;
        if (!$handle) {
            return '';
        }
        $size = (int) @filesize($path);
        if ($size > 4096) {
            fseek($handle, -4096, SEEK_END);
        }
        $tail = (string) fread($handle, 4096);
        fclose($handle);
        return stripos($tail, 'Hostinger') !== false ? 'Hostinger Tools' : '';
    }

    public static function flush_llms_cache() {
        delete_transient(self::LLMS_CACHE);
    }

    protected static function request_path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        return (string) wp_parse_url($uri, PHP_URL_PATH);
    }

    public static function maybe_serve_llms_txt() {
        if (!MG_SEO_Meta::get_setting('enabled') || !MG_SEO_Meta::get_setting('llms_txt')) {
            return;
        }
        $target = (string) wp_parse_url(home_url('/llms.txt'), PHP_URL_PATH);
        if (self::request_path() !== $target) {
            return;
        }
        $body = get_transient(self::LLMS_CACHE);
        if (!is_string($body) || $body === '') {
            $body = self::build_llms_txt();
            set_transient(self::LLMS_CACHE, $body, 12 * HOUR_IN_SECONDS);
        }
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        // Géppel olvasható összefoglaló, nem találati oldal.
        header('X-Robots-Tag: noindex');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- egyszerű szöveg
        exit;
    }

    /** A katalógus terméktípusainak nevei („Férfi póló, Női póló, …”). */
    public static function get_type_labels() {
        if (!class_exists('MG_Variant_Display_Manager')) {
            return array();
        }
        $labels = array();
        foreach (MG_Variant_Display_Manager::get_catalog_index() as $type) {
            if (!empty($type['label'])) {
                $labels[] = MG_SEO_Meta::plain($type['label']);
            }
        }
        return array_values(array_unique($labels));
    }

    protected static function md_link($label, $url, $note = '') {
        $label = str_replace(array('[', ']'), array('(', ')'), MG_SEO_Meta::plain($label));
        $line = '[' . $label . '](' . esc_url_raw($url) . ')';
        $note = MG_SEO_Meta::plain($note);
        return $note !== '' ? $line . ': ' . $note : $line;
    }

    /** Az llms.txt-be nem való rendszeroldalak: főoldal, blogoldal, bolt, kosár, pénztár, fiók. */
    public static function llms_system_page_ids() {
        $ids = array((int) get_option('page_on_front', 0), (int) get_option('page_for_posts', 0));
        if (function_exists('wc_get_page_id')) {
            foreach (array('shop', 'cart', 'checkout', 'myaccount') as $page) {
                $ids[] = (int) wc_get_page_id($page);
            }
        }
        return array_values(array_filter($ids, function ($id) {
            return $id > 0;
        }));
    }

    /** Az llms.txt-be választható oldalak (a rendszeroldalak nélkül). */
    public static function llms_page_candidates() {
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'post__not_in' => self::llms_system_page_ids(),
            'no_found_rows' => true,
        ));
        return is_array($pages) ? $pages : array();
    }

    /** A beállításokban kihagyott oldalak nélküli lista. */
    public static function llms_pages() {
        $excluded = array_map('intval', (array) MG_SEO_Meta::get_setting('llms_excluded_pages'));
        return array_values(array_filter(self::llms_page_candidates(), function ($page) use ($excluded) {
            return !in_array((int) $page->ID, $excluded, true);
        }));
    }

    /** Az oldal első mondatai: az AI ebből tudja, melyik oldal mire válaszol (pl. szállítási díjak). */
    public static function page_summary($page) {
        $text = (string) ($page->post_excerpt !== '' ? $page->post_excerpt : $page->post_content);
        if (function_exists('strip_shortcodes')) {
            $text = strip_shortcodes($text);
        }
        $text = preg_replace('/\[[a-z0-9_-]+[^\]]*\]/i', ' ', $text);
        $text = MG_SEO_Meta::plain(str_replace(array('&nbsp;', "\xC2\xA0", '<', '>'), array(' ', ' ', ' <', '> '), $text));
        return $text === '' ? '' : MG_SEO_Meta::truncate($text, 200);
    }

    public static function build_llms_txt() {
        $site = (string) MG_SEO_Meta::get_setting('site_name');
        $types = self::get_type_labels();
        $summary = trim((string) MG_SEO_Meta::get_setting('llms_summary'));
        if ($summary === '') {
            $summary = $site . ': magyar webshop vicces és egyedi mintás termékekkel. Minden minta több terméktípuson rendelhető'
                . ($types ? ' (' . implode(', ', $types) . ')' : '') . '. '
                . MG_SEO_Meta::plain((string) MG_SEO_Meta::get_setting('usp'));
        }

        $out = array('# ' . $site, '', '> ' . MG_SEO_Meta::plain($summary), '');
        $out[] = 'A termékoldalak terméktípusonként külön URL-en érhetők el (pl. /termek/minta-ferfi-polo/), saját árral és képpel; ugyanezek szerepelnek a termékfeedekben is.';
        $out[] = '';

        $facts = array_filter(array_map(array('MG_SEO_Meta', 'plain'), preg_split('/\r\n|\r|\n/', (string) MG_SEO_Meta::get_setting('llms_facts'))), 'strlen');
        if ($facts) {
            $out[] = '## Vásárlási információk';
            $out[] = '';
            foreach ($facts as $fact) {
                $out[] = '- ' . preg_replace('/^[-–•*]+\s*/u', '', $fact);
            }
            $out[] = '';
        }

        $out[] = '## Termékkategóriák';
        $out[] = '';
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        $default_cat = (int) get_option('default_product_cat', 0);
        $children = array();
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                if ((int) $term->term_id === $default_cat || MG_SEO_Meta::term_is_noindex($term)) {
                    continue;
                }
                $children[(int) $term->parent][] = $term;
            }
        }
        $render = function ($parent_id, $depth) use (&$render, &$out, $children) {
            if (empty($children[$parent_id])) {
                return;
            }
            foreach ($children[$parent_id] as $term) {
                $link = get_term_link($term);
                if (is_wp_error($link)) {
                    continue;
                }
                // A fában a név (vagy az egyedi H1) áll: a H1 sablon minden sorban ugyanazt ismételné.
                $out[] = str_repeat('  ', $depth) . '- ' . self::md_link(MG_SEO_Meta::get_term_h1($term, false), $link, MG_SEO_Meta::build_term_description($term));
                $render((int) $term->term_id, $depth + 1);
            }
        };
        $render(0, 0);
        $out[] = '';

        $pages = self::llms_pages();
        if ($pages) {
            $out[] = '## Fontos oldalak';
            $out[] = '';
            foreach ($pages as $page) {
                $out[] = '- ' . self::md_link(get_the_title($page), get_permalink($page), self::page_summary($page));
            }
            $out[] = '';
        }

        $contact = get_option('mg_seo_contact', array());
        $contact = is_array($contact) ? $contact : array();
        $out[] = '## Kapcsolat';
        $out[] = '';
        $legal = trim((string) MG_SEO_Meta::get_setting('legal_name'));
        if ($legal !== '') {
            $out[] = '- Üzemeltető: ' . $legal;
        }
        $address = trim(MG_SEO_Meta::get_setting('postal_code') . ' ' . MG_SEO_Meta::get_setting('city'));
        $street = trim((string) MG_SEO_Meta::get_setting('street'));
        if ($street !== '') {
            $address = $address !== '' ? $address . ', ' . $street : $street;
        }
        if ($address !== '') {
            $out[] = '- Cím: ' . MG_SEO_Meta::plain($address);
        }
        $email = !empty($contact['email']) ? $contact['email'] : get_bloginfo('admin_email');
        if ($email) {
            $out[] = '- E-mail: ' . sanitize_email($email);
        }
        if (!empty($contact['phone'])) {
            $out[] = '- Telefon: ' . MG_SEO_Meta::plain($contact['phone']);
        }
        $out[] = '';

        $out[] = '## Optional';
        $out[] = '';
        if (function_exists('wc_get_page_permalink')) {
            $out[] = '- ' . self::md_link('Összes termék', wc_get_page_permalink('shop'));
        }
        $out[] = '- ' . self::md_link('Oldaltérkép', home_url('/wp-sitemap.xml'));

        return implode("\n", apply_filters('mg_seo_llms_txt_lines', $out)) . "\n";
    }

    /* ------------------------------------------------------------------ */
    /* robots.txt ellenőrzés                                               */
    /* ------------------------------------------------------------------ */

    public static function ai_bots() {
        return array(
            'OAI-SearchBot' => 'ChatGPT keresés – ettől függ, ajánl-e a ChatGPT',
            'ChatGPT-User' => 'ChatGPT – a felhasználó kérésére megnyitott oldalak',
            'GPTBot' => 'OpenAI modelltanítás (kereséshez nem kell)',
            'PerplexityBot' => 'Perplexity keresés',
            'Claude-SearchBot' => 'Claude keresés',
            'ClaudeBot' => 'Anthropic modelltanítás (kereséshez nem kell)',
            'Googlebot' => 'Google keresés és AI-áttekintések',
            'Google-Extended' => 'Gemini modelltanítás (a Google keresést nem érinti)',
            'Bingbot' => 'Bing és Copilot – a ChatGPT keresés is erre támaszkodik',
            'Applebot' => 'Apple (Siri, Spotlight) keresés',
        );
    }

    /** robots.txt csoportok (RFC 9309): user-agent tokenek + szabályok. */
    public static function parse_robots($text) {
        $groups = array();
        $current = null;
        $last_was_agent = false;
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            list($field, $value) = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if (!$last_was_agent || $current === null) {
                    $groups[] = array('agents' => array(), 'rules' => array());
                    $current = count($groups) - 1;
                }
                $groups[$current]['agents'][] = strtolower($value);
                $last_was_agent = true;
                continue;
            }
            $last_was_agent = false;
            if ($current !== null && in_array($field, array('allow', 'disallow'), true)) {
                $groups[$current]['rules'][] = array('type' => $field, 'path' => $value);
            }
        }
        return $groups;
    }

    protected static function rules_for(array $groups, $bot) {
        $bot = strtolower($bot);
        $rules = array();
        $matched = false;
        foreach ($groups as $group) {
            if (in_array($bot, $group['agents'], true)) {
                $rules = array_merge($rules, $group['rules']);
                $matched = true;
            }
        }
        if ($matched) {
            return $rules;
        }
        foreach ($groups as $group) {
            if (in_array('*', $group['agents'], true)) {
                $rules = array_merge($rules, $group['rules']);
            }
        }
        return $rules;
    }

    /** A leghosszabb illeszkedő szabály dönt, egyenlőnél az Allow. */
    public static function is_allowed(array $rules, $path) {
        $best = null;
        foreach ($rules as $rule) {
            $pattern = $rule['path'];
            if ($pattern === '') {
                continue;
            }
            $anchored = substr($pattern, -1) === '$';
            $core = $anchored ? substr($pattern, 0, -1) : $pattern;
            $regex = '#^' . str_replace('\*', '.*', preg_quote($core, '#')) . ($anchored ? '$' : '') . '#';
            if (!preg_match($regex, $path)) {
                continue;
            }
            $length = strlen($pattern);
            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['type'] === 'allow')) {
                $best = array('length' => $length, 'type' => $rule['type']);
            }
        }
        return $best === null || $best['type'] === 'allow';
    }

    public static function evaluate_robots($text, array $bots, array $paths) {
        $groups = self::parse_robots($text);
        $report = array();
        foreach ($bots as $bot => $purpose) {
            $rules = self::rules_for($groups, $bot);
            $blocked = array();
            foreach ($paths as $path) {
                if (!self::is_allowed($rules, $path)) {
                    $blocked[] = $path;
                }
            }
            if (!$blocked) {
                $status = 'allowed';
            } elseif (in_array('/', $blocked, true)) {
                $status = 'blocked';
            } else {
                $status = 'partial';
            }
            $report[$bot] = array('purpose' => $purpose, 'status' => $status, 'blocked' => $blocked);
        }
        return $report;
    }

    public static function get_robots_report($force = false) {
        if (!$force) {
            $cached = get_transient(self::ROBOTS_CACHE);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $url = home_url('/robots.txt');
        $response = wp_remote_get($url, array('timeout' => 10, 'redirection' => 3));
        if (is_wp_error($response)) {
            $report = array('error' => $response->get_error_message(), 'url' => $url, 'checked_at' => time());
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $paths = array('/', '/termek/', '/product-category/');
            $signals = array();
            foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
                if (stripos(trim($line), 'content-signal:') === 0) {
                    $signals[] = trim($line);
                }
            }
            $report = array(
                'url' => $url,
                'code' => $code,
                'checked_at' => time(),
                'bots' => $code === 200 ? self::evaluate_robots($body, self::ai_bots(), $paths) : array(),
                'content_signals' => array_values(array_unique($signals)),
            );
        }
        set_transient(self::ROBOTS_CACHE, $report, HOUR_IN_SECONDS);
        return $report;
    }
}
