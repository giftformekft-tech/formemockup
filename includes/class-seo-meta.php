<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO alapok dedikált SEO bővítmény nélkül.
 *
 * A boltban nincs Yoast / Rank Math, ezért a WordPress csak „Név – www.forme.hu”
 * címet ír ki, meta leírás, kategória-canonical és Open Graph nélkül, a
 * GMC SEO optimalizáló Yoast/Rank Math filterei pedig le sem futnak. Ez az
 * osztály pótolja a fejléc-adatokat: a termékoldal címébe beírja a virtuális
 * terméktípust és a kategóriát, a kategóriaoldal saját címet és leírást kap,
 * az alap termék-URL a típusos URL-re kanonizál.
 *
 * Dedikált SEO bővítmény mellett a fejléc-kimenet (cím, meta, OG, canonical,
 * robots, breadcrumb séma) automatikusan kimarad, hogy ne legyen duplikáció.
 */
class MG_SEO_Meta {
    const OPTION = 'mg_seo_settings';

    const TERM_TITLE = 'mg_seo_title';
    const TERM_H1 = 'mg_seo_h1';
    const TERM_DESCRIPTION = 'mg_seo_meta_description';
    const TERM_PRODUCT_KEYWORD = 'mg_seo_product_keyword';
    const TERM_BOTTOM_TEXT = 'mg_seo_bottom_text';
    const TERM_FAQ = 'mg_seo_faq';
    const TERM_NOINDEX = 'mg_seo_noindex';

    const TITLE_MAX = 70;
    const DESCRIPTION_MAX = 160;

    protected static $settings = null;
    protected static $page = null;
    protected static $product_contexts = array();
    protected static $default_type_slug = null;
    protected static $non_virtual_ids = null;

    public static function init() {
        add_filter('pre_get_document_title', array(__CLASS__, 'filter_document_title'), 20);
        add_filter('document_title_separator', array(__CLASS__, 'filter_title_separator'), 20);
        add_filter('document_title_parts', array(__CLASS__, 'filter_title_parts'), 20);
        add_action('wp_head', array(__CLASS__, 'output_head'), 1);
        add_action('wp_head', array(__CLASS__, 'output_schema'), 6);
        add_filter('wp_robots', array(__CLASS__, 'filter_robots'), 20);

        add_filter('wp_sitemaps_posts_entry', array(__CLASS__, 'filter_sitemap_entry'), 10, 3);
        add_filter('wp_sitemaps_add_provider', array(__CLASS__, 'filter_sitemap_provider'), 10, 2);
        add_filter('wp_sitemaps_taxonomies', array(__CLASS__, 'filter_sitemap_taxonomies'));
        add_filter('wp_sitemaps_taxonomies_query_args', array(__CLASS__, 'filter_sitemap_term_args'), 10, 2);
    }

    /* ------------------------------------------------------------------ */
    /* Beállítások                                                         */
    /* ------------------------------------------------------------------ */

    public static function default_site_name() {
        $host = function_exists('home_url') ? (string) wp_parse_url(home_url('/'), PHP_URL_HOST) : '';
        $host = preg_replace('/^www\./i', '', $host);
        if ($host === '') {
            return 'Forme.hu';
        }
        return function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($host, 0, 1)) . mb_substr($host, 1)
            : ucfirst($host);
    }

    public static function defaults() {
        $site = self::default_site_name();
        return array(
            'enabled' => 1,
            'site_name' => $site,
            'brand_name' => '',
            'separator' => '|',
            'product_title_template' => '{termek} - {tipus} | {kategoria} | {oldal}',
            'product_description_template' => '{termek} - {tipus}. {minta_leiras} {elonyok}',
            'category_title_template' => '{kategoria} – vicces, egyedi pólók és ajándékok | {oldal}',
            'category_description_template' => '{kategoria}: {db} egyedi, vicces minta pólón, pulóveren és bögrén. {elonyok}',
            'category_h1_template' => '{kategoria} – vicces, egyedi pólók, pulóverek és bögrék',
            'usp' => 'Prémium minőség, tartós nyomtatás, gyors gyártás.',
            'home_title' => '{oldal} – vicces, egyedi pólók, pulóverek és bögrék ajándékba',
            'home_description' => 'Vicces és egyedi mintás pólók, pulóverek, bögrék és táskák születésnapra, ünnepekre és minden alkalomra. Prémium minőség, tartós nyomtatás, gyors gyártás.',
            'category_h1' => 1,
            'category_content' => 1,
            'og_tags' => 1,
            'breadcrumb_schema' => 1,
            'consolidate_base_url' => 1,
            'noindex_tags' => 0,
            'llms_txt' => 1,
            'llms_summary' => '',
            'llms_facts' => '',
            'llms_excluded_pages' => array(),
            'legal_name' => 'Gift for me Kft.',
            'street' => 'Hunyadi utca 35.',
            'city' => 'Nyírlugos',
            'postal_code' => '4371',
            'country' => 'HU',
            'vat_id' => '',
            'tax_id' => '',
            'same_as' => '',
        );
    }

    public static function get_settings() {
        if (self::$settings === null) {
            $stored = get_option(self::OPTION, array());
            self::$settings = array_merge(self::defaults(), is_array($stored) ? $stored : array());
        }
        return self::$settings;
    }

    public static function get_setting($key) {
        $settings = self::get_settings();
        return isset($settings[$key]) ? $settings[$key] : null;
    }

    /**
     * Márkanév a sémában, az OG-ban és a termékfeedekben: üresen a webshop
     * neve, ahogy a feedek eddig is küldték – így minden kimenet egyezik.
     */
    public static function get_brand_name() {
        $brand = trim((string) self::get_setting('brand_name'));
        if ($brand === '' && function_exists('get_bloginfo')) {
            $brand = (string) get_bloginfo('name');
        }
        return $brand;
    }

    public static function reset_cache() {
        self::$settings = null;
        self::$page = null;
        self::$product_contexts = array();
        self::$default_type_slug = null;
        self::$non_virtual_ids = null;
    }

    public static function save_settings(array $input) {
        $defaults = self::defaults();
        $clean = array();
        $checkboxes = array('enabled', 'category_h1', 'category_content', 'og_tags', 'breadcrumb_schema', 'consolidate_base_url', 'noindex_tags', 'llms_txt');
        foreach ($checkboxes as $key) {
            $clean[$key] = !empty($input[$key]) ? 1 : 0;
        }
        // Üresen hagyva az alapértelmezés marad érvényben.
        $required = array('site_name', 'product_title_template', 'category_title_template', 'home_title');
        foreach ($required as $key) {
            $value = isset($input[$key]) ? trim(sanitize_text_field(wp_unslash($input[$key]))) : '';
            $clean[$key] = $value !== '' ? $value : $defaults[$key];
        }
        $areas = array('product_description_template', 'category_description_template', 'usp', 'home_description');
        foreach ($areas as $key) {
            $value = isset($input[$key]) ? trim(sanitize_textarea_field(wp_unslash($input[$key]))) : '';
            $clean[$key] = $value !== '' ? $value : $defaults[$key];
        }
        // A cégadatok üresen hagyhatók: az üres mező kimarad a sémából.
        $optional = array('brand_name', 'legal_name', 'street', 'city', 'postal_code', 'vat_id', 'tax_id');
        foreach ($optional as $key) {
            $clean[$key] = isset($input[$key]) ? trim(sanitize_text_field(wp_unslash($input[$key]))) : '';
        }
        $clean['llms_summary'] = isset($input['llms_summary']) ? trim(sanitize_textarea_field(wp_unslash($input['llms_summary']))) : '';
        $clean['llms_facts'] = isset($input['llms_facts']) ? trim(sanitize_textarea_field(wp_unslash($input['llms_facts']))) : '';
        // Az llms.txt-ből kihagyott oldalak: a listázott, de be nem pipált oldalak.
        $listed = isset($input['llms_pages_listed']) && is_array($input['llms_pages_listed']) ? array_map('absint', $input['llms_pages_listed']) : array();
        $checked = isset($input['llms_pages_checked']) && is_array($input['llms_pages_checked']) ? array_map('absint', $input['llms_pages_checked']) : array();
        $clean['llms_excluded_pages'] = array_values(array_filter(array_unique(array_diff($listed, $checked))));
        // Üres H1 sablonnál a kategória neve a H1.
        $clean['category_h1_template'] = isset($input['category_h1_template']) ? trim(sanitize_text_field(wp_unslash($input['category_h1_template']))) : '';

        $separator = isset($input['separator']) ? trim(sanitize_text_field(wp_unslash($input['separator']))) : '';
        $clean['separator'] = in_array($separator, array('|', '–', '-', '·', '•'), true) ? $separator : '|';

        $country = isset($input['country']) ? strtoupper(trim(sanitize_text_field(wp_unslash($input['country'])))) : '';
        $clean['country'] = preg_match('/^[A-Z]{2}$/', $country) ? $country : 'HU';

        $same_as = array();
        $lines = isset($input['same_as']) ? preg_split('/\r\n|\r|\n/', (string) wp_unslash($input['same_as'])) : array();
        foreach ($lines as $line) {
            $url = esc_url_raw(trim($line));
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $same_as[] = $url;
            }
        }
        $clean['same_as'] = implode("\n", array_values(array_unique($same_as)));

        update_option(self::OPTION, $clean);
        self::reset_cache();
        do_action('mg_seo_settings_saved', $clean);
        return $clean;
    }

    /* ------------------------------------------------------------------ */
    /* Aktiválás                                                           */
    /* ------------------------------------------------------------------ */

    /** A ténylegesen aktív SEO bővítmény neve, vagy üres szöveg. */
    public static function detect_seo_plugin() {
        $map = array(
            'WPSEO_VERSION' => 'Yoast SEO',
            'RANK_MATH_VERSION' => 'Rank Math',
            'SEOPRESS_VERSION' => 'SEOPress',
            'AIOSEO_VERSION' => 'All in One SEO',
            'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
            'SLIM_SEO_VER' => 'Slim SEO',
            'SQ_VERSION' => 'Squirrly SEO',
        );
        foreach ($map as $constant => $name) {
            if (defined($constant)) {
                return $name;
            }
        }
        return '';
    }

    /** Fejléc-kimenet (cím, meta, OG, canonical, robots, breadcrumb). */
    public static function is_active() {
        if (!self::get_setting('enabled')) {
            return false;
        }
        if (self::detect_seo_plugin() !== '') {
            return false;
        }
        return (bool) apply_filters('mg_seo_meta_active', true);
    }

    /** Tartalmi funkciók (H1, alsó szöveg, GYIK) SEO bővítmény mellett is mennek. */
    public static function content_enabled() {
        return (bool) self::get_setting('enabled');
    }

    /* ------------------------------------------------------------------ */
    /* Szöveg-segédek                                                      */
    /* ------------------------------------------------------------------ */

    public static function plain($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
        $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text, true) : strip_tags($text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    public static function normalize($text) {
        $text = self::plain($text);
        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim($text);
    }

    /** Ékezet- és kisbetű-független, szóhatárt figyelő „tartalmazza”. */
    public static function contains($haystack, $needle) {
        $needle = self::normalize($needle);
        if ($needle === '') {
            return false;
        }
        return strpos(' ' . self::normalize($haystack) . ' ', ' ' . $needle . ' ') !== false;
    }

    public static function length($text) {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    public static function lower_first($text) {
        if ($text === '' || !function_exists('mb_strtolower')) {
            return $text;
        }
        return mb_strtolower(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    /** Kategórianevek közül a slug-szerűek („minecraft-polok”) nem valók címbe. */
    public static function looks_like_slug($text) {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/', (string) $text);
    }

    public static function clean_segment($text) {
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $text = preg_replace('/\(\s*\)/u', '', $text);
        // Üressé vált helyőrző után maradt dupla elválasztó: „A – – B”.
        $text = preg_replace('/(\s[–—-])(?:\s*[–—-](?=\s))+/u', '$1', $text);
        $text = preg_replace('/^(?:\s*[–—:,;]+\s*|\s*-\s+)+/u', '', $text);
        $text = preg_replace('/(?:\s*[–—:,;]+\s*|\s+-\s*)+$/u', '', $text);
        return trim($text);
    }

    public static function clean_description($text) {
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $text = preg_replace('/\(\s*\)/u', '', $text);
        // „Név – .” → „Név.” ha a típus üres maradt.
        $text = preg_replace('/\s[–—-]\s*([.,!?])/u', '$1', $text);
        $text = preg_replace('/\s+([.,!?:;])/u', '$1', $text);
        $text = preg_replace('/(?<!\.)\.\.(?!\.)/u', '.', $text);
        $text = preg_replace('/([!?])\./u', '$1', $text);
        $text = preg_replace('/^[\s–—:,;.-]+/u', '', $text);
        return trim($text);
    }

    public static function truncate($text, $max = self::DESCRIPTION_MAX) {
        $text = trim((string) $text);
        if (self::length($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max, 'UTF-8');
        $positions = array();
        foreach (array('. ', '! ', '? ') as $mark) {
            $pos = mb_strrpos($cut, $mark, 0, 'UTF-8');
            if ($pos !== false) {
                $positions[] = $pos;
            }
        }
        if ($positions && max($positions) >= (int) ($max * 0.6)) {
            return mb_substr($cut, 0, max($positions) + 1, 'UTF-8');
        }
        $space = mb_strrpos(mb_substr($text, 0, $max - 1, 'UTF-8'), ' ', 0, 'UTF-8');
        if ($space === false) {
            $space = $max - 1;
        }
        return rtrim(mb_substr($text, 0, $space, 'UTF-8'), " ,;:–—-") . '…';
    }

    /** A szövegből annyi teljes mondat, amennyi a keretbe fér. */
    public static function fit_sentences($text, $budget) {
        $text = self::plain($text);
        if ($text === '' || $budget < 30) {
            return '';
        }
        $sentences = preg_split('/(?<=[.!?…])\s+/u', $text);
        $out = '';
        foreach ($sentences as $sentence) {
            $candidate = $out === '' ? $sentence : $out . ' ' . $sentence;
            if (self::length($candidate) > $budget) {
                break;
            }
            $out = $candidate;
        }
        if ($out === '' && $budget >= 40) {
            $out = self::truncate($sentences[0], $budget);
        }
        return $out;
    }

    protected static function placeholders(array $vars) {
        $map = array();
        foreach ($vars as $key => $value) {
            $map['{' . $key . '}'] = (string) $value;
        }
        return $map;
    }

    /**
     * A sablon saját szavai közül kihagyja a felsorolásban álló, a megadott
     * névben már szereplő szót: „{kategoria} – vicces, egyedi pólók” a
     * „Vicces” kategóriánál „Vicces – egyedi pólók” lesz. A helyőrzők
     * értékéhez és a felsoroláson kívüli szavakhoz nem nyúl.
     */
    public static function drop_repeated_words($template, $name) {
        $known = array();
        foreach (explode(' ', self::normalize($name)) as $word) {
            if (strlen($word) >= 3) {
                $known[$word] = true;
            }
        }
        $parts = preg_split('/(\{[a-z_]+\})/', (string) $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$known || !$parts) {
            return (string) $template;
        }
        $patterns = array(
            '/(?<![\p{L}\p{N}])%s,\s*/u',      // „vicces, egyedi” → „egyedi”
            '/\s*,\s*%s(?![\p{L}\p{N}])/u',    // „egyedi, vicces minta” → „egyedi minta”
            '/(?<![\p{L}\p{N}])%s\s+és\s+/u',  // „vicces és egyedi” → „egyedi”
            '/\s+és\s+%s(?![\p{L}\p{N}])/u',   // „pulóverek és bögrék” → „pulóverek”
        );
        foreach ($parts as $index => $part) {
            // A páratlan indexű részek a helyőrzők.
            if ($index % 2 === 1 || !preg_match_all('/[\p{L}\p{N}]+/u', $part, $matches)) {
                continue;
            }
            foreach (array_unique($matches[0]) as $word) {
                if (!isset($known[self::normalize($word)])) {
                    continue;
                }
                foreach ($patterns as $pattern) {
                    $count = 0;
                    $part = preg_replace(sprintf($pattern, preg_quote($word, '/')), '', $part, 1, $count);
                    if ($count) {
                        break;
                    }
                }
            }
            $parts[$index] = $part;
        }
        return implode('', $parts);
    }

    /**
     * Címsablon kitöltése: a „|” a szakaszhatár. Az üresre futó szakasz
     * kimarad, túl hosszú címnél előbb az oldalnév, majd a hátsó szakaszok.
     */
    public static function render_title($template, array $vars, $extra = '', $include_site = true) {
        $map = self::placeholders($vars);
        $segments = array();
        foreach (explode('|', (string) $template) as $raw) {
            $is_site = strpos($raw, '{oldal}') !== false;
            if ($is_site && !$include_site && trim(str_replace('{oldal}', '', $raw)) === '') {
                continue;
            }
            $text = self::clean_segment(strtr($raw, $map));
            if ($text === '') {
                continue;
            }
            $segments[] = array('text' => $text, 'site' => $is_site);
        }
        if ($extra !== '' && $segments) {
            $site_index = null;
            foreach ($segments as $i => $segment) {
                if ($i > 0 && $segment['site']) {
                    $site_index = $i;
                }
            }
            $item = array('text' => $extra, 'site' => false, 'keep' => true);
            if ($site_index === null) {
                $segments[] = $item;
            } else {
                array_splice($segments, $site_index, 0, array($item));
            }
        }
        $separator = ' ' . trim((string) self::get_setting('separator')) . ' ';
        $join = function ($items) use ($separator) {
            return implode($separator, array_map(function ($item) {
                return $item['text'];
            }, $items));
        };
        $max = (int) apply_filters('mg_seo_title_max_length', self::TITLE_MAX);
        while (count($segments) > 1 && self::length($join($segments)) > $max) {
            $drop = null;
            foreach ($segments as $i => $segment) {
                if ($i > 0 && $segment['site']) {
                    $drop = $i;
                }
            }
            if ($drop === null) {
                for ($i = count($segments) - 1; $i > 0; $i--) {
                    if (empty($segments[$i]['keep'])) {
                        $drop = $i;
                        break;
                    }
                }
            }
            if ($drop === null) {
                break;
            }
            array_splice($segments, $drop, 1);
        }
        return $join($segments);
    }

    public static function page_suffix() {
        $paged = function_exists('get_query_var') ? (int) get_query_var('paged') : 0;
        return $paged > 1 ? $paged . '. oldal' : '';
    }

    /* ------------------------------------------------------------------ */
    /* Termék-kontextus                                                    */
    /* ------------------------------------------------------------------ */

    public static function get_current_product() {
        if (!function_exists('is_product') || !is_product()) {
            return null;
        }
        $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;
        return $product instanceof WC_Product ? $product : null;
    }

    public static function supports_virtual_types($product) {
        if (!$product instanceof WC_Product || !class_exists('MG_Virtual_Variant_Manager')) {
            return false;
        }
        if (class_exists('MG_Outlet') && MG_Outlet::is_outlet($product)) {
            return false;
        }
        return $product->is_type('simple');
    }

    /** Az első katalógustípus – a listák és a feedek is ezt tekintik alapnak. */
    public static function get_default_type_slug() {
        if (self::$default_type_slug === null) {
            self::$default_type_slug = '';
            if (class_exists('MG_Variant_Display_Manager')) {
                $catalog = MG_Variant_Display_Manager::get_catalog_index();
                if (is_array($catalog) && $catalog) {
                    reset($catalog);
                    self::$default_type_slug = sanitize_title((string) key($catalog));
                }
            }
        }
        return self::$default_type_slug;
    }

    /** Termékkategóriák közül a címbe és a breadcrumbba illő elsődleges. */
    public static function get_primary_category($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return null;
        }
        $default_cat = (int) get_option('default_product_cat', 0);
        $disabled = get_option('mg_disabled_categories', array());
        $disabled = is_array($disabled) ? array_map('intval', $disabled) : array();
        $candidates = array();
        foreach ($terms as $term) {
            if ((int) $term->term_id === $default_cat || $term->slug === 'uncategorized' || in_array((int) $term->term_id, $disabled, true)) {
                continue;
            }
            $candidates[] = $term;
        }
        if (!$candidates) {
            return null;
        }
        foreach ($candidates as $term) {
            if (trim((string) get_term_meta($term->term_id, self::TERM_PRODUCT_KEYWORD, true)) !== '') {
                return $term;
            }
        }
        // Ugyanaz a választás, mint a termékfeedek product_type mezőjében:
        // az első alkategória, ennek híján az első kategória.
        foreach ($candidates as $term) {
            if (!empty($term->parent)) {
                return $term;
            }
        }
        return $candidates[0];
    }

    /** A kategória termékcímekbe szánt kifejezése (egyedi mező vagy a név). */
    public static function get_term_keyword($term) {
        if (!$term || empty($term->term_id)) {
            return '';
        }
        $custom = trim((string) get_term_meta($term->term_id, self::TERM_PRODUCT_KEYWORD, true));
        if ($custom !== '') {
            return self::plain($custom);
        }
        $name = self::plain($term->name);
        if ($name !== '' && !self::looks_like_slug($name)) {
            return $name;
        }
        if (!empty($term->parent)) {
            $parent = get_term((int) $term->parent, 'product_cat');
            if ($parent && !is_wp_error($parent)) {
                $parent_name = self::plain($parent->name);
                if (!self::looks_like_slug($parent_name)) {
                    return $parent_name;
                }
            }
        }
        return '';
    }

    /**
     * A termékoldal SEO-adatainak alapja: név, aktuális (vagy alap) típus,
     * elsődleges kategória és a virtuális konfiguráció.
     */
    public static function get_product_context($product, $type_slug = null) {
        if (!$product instanceof WC_Product) {
            return null;
        }
        $requested = $type_slug;
        if ($requested === null && class_exists('MG_Virtual_Variant_Manager') && self::get_current_product()) {
            $requested = MG_Virtual_Variant_Manager::get_type_from_request();
        }
        $cache_key = $product->get_id() . '|' . (string) $requested;
        if (isset(self::$product_contexts[$cache_key])) {
            return self::$product_contexts[$cache_key];
        }

        $config = self::supports_virtual_types($product) ? MG_Virtual_Variant_Manager::get_frontend_config($product) : array();
        $types = !empty($config['types']) && is_array($config['types']) ? $config['types'] : array();
        $default_type = isset($config['default']['type']) ? (string) $config['default']['type'] : '';
        $type = is_string($requested) ? sanitize_title($requested) : '';
        if ($type === '' || !isset($types[$type])) {
            $type = $default_type;
        }
        if ($type !== '' && !isset($types[$type])) {
            $type = '';
        }
        $type_data = $type !== '' ? $types[$type] : array();
        $term = self::get_primary_category($product->get_id());

        $price = isset($type_data['price']) && (float) $type_data['price'] > 0 ? (float) $type_data['price'] : (float) $product->get_price();

        $context = array(
            'product' => $product,
            'name' => self::plain($product->get_name()),
            'type' => $type,
            'type_label' => isset($type_data['label']) ? self::plain($type_data['label']) : '',
            'is_default_type' => $type === '' || $type === $default_type,
            'default_type' => $default_type,
            'config' => $config,
            'term' => $term,
            'keyword' => $term ? self::get_term_keyword($term) : '',
            'price' => $price,
            'image' => isset($type_data['preview_url']) ? (string) $type_data['preview_url'] : '',
        );
        self::$product_contexts[$cache_key] = $context;
        return $context;
    }

    /**
     * A típusos név ugyanúgy, ahogy a H1 (sync_frontend_title), a feed g:title
     * és a JSON-LD name mutatja: „Név - Típus”. Üres típusnál csak a név.
     */
    public static function display_name(array $ctx) {
        $name = $ctx['name'];
        $type = $ctx['type_label'];
        if ($type === '' || strpos($name, $type) !== false) {
            return array($name, '');
        }
        foreach (array(' póló pulcsi', ' polo pulcsi') as $postfix) {
            if (substr($name, -strlen($postfix)) === $postfix) {
                $name = trim(substr($name, 0, -strlen($postfix)));
            }
        }
        return array($name, $type);
    }

    /** A típus URL-je pontosan úgy, ahogy a termékfeedek link mezője. */
    public static function get_type_url($product, $type_slug, array $config = array()) {
        if (!$product instanceof WC_Product || $type_slug === '') {
            return '';
        }
        if (!empty($config['typeUrls'][$type_slug])) {
            return (string) $config['typeUrls'][$type_slug];
        }
        if (class_exists('MG_GMC_SEO_Optimizer')) {
            return MG_GMC_SEO_Optimizer::get_virtual_permalink($product, $type_slug);
        }
        return add_query_arg('mg_type', $type_slug, $product->get_permalink());
    }

    public static function build_product_title(array $ctx, $include_site = true) {
        list($name, $type) = self::display_name($ctx);
        $keyword = $ctx['keyword'];
        if ($keyword !== '' && (self::contains($name, $keyword) || ($type !== '' && self::contains($type, $keyword)))) {
            $keyword = '';
        }
        $vars = array(
            'termek' => $name,
            'tipus' => $type,
            'tipus_kisbetu' => self::lower_first($type),
            'kategoria' => $keyword,
            'oldal' => (string) self::get_setting('site_name'),
        );
        return self::render_title((string) self::get_setting('product_title_template'), $vars, '', $include_site);
    }

    /** A minta saját (AI) SEO szövege, ennek híján a kategória leírása. */
    public static function get_product_design_text(array $ctx) {
        $product = $ctx['product'];
        $text = self::plain((string) get_post_meta($product->get_id(), '_mg_sample_seo', true));
        if ($text === '' && $ctx['term']) {
            $text = self::plain((string) get_term_meta($ctx['term']->term_id, self::TERM_DESCRIPTION, true));
            if ($text === '') {
                $text = self::plain(term_description($ctx['term']->term_id, 'product_cat'));
            }
        }
        return $text;
    }

    public static function build_product_description(array $ctx) {
        $template = (string) self::get_setting('product_description_template');
        list($name, $type) = self::display_name($ctx);
        $vars = array(
            'termek' => $name,
            'tipus' => $type,
            'tipus_kisbetu' => self::lower_first($type),
            'kategoria' => $ctx['keyword'],
            'oldal' => (string) self::get_setting('site_name'),
            'elonyok' => trim((string) self::get_setting('usp')),
            'minta_leiras' => '',
        );
        $base = self::clean_description(strtr($template, self::placeholders($vars)));
        $budget = self::DESCRIPTION_MAX - self::length($base) - 1;
        $vars['minta_leiras'] = self::fit_sentences(self::get_product_design_text($ctx), $budget);
        $description = self::clean_description(strtr($template, self::placeholders($vars)));
        return self::truncate($description, self::DESCRIPTION_MAX);
    }

    /**
     * Típusonkénti <title> a böngészőnek: típusváltáskor a fül címe is a
     * kiválasztott virtuális típusét mutatja, nem csak a H1 és az URL.
     */
    public static function get_type_titles($product) {
        if (!self::is_active() || !self::supports_virtual_types($product)) {
            return array();
        }
        $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
        $titles = array();
        foreach (array_keys(isset($config['types']) && is_array($config['types']) ? $config['types'] : array()) as $slug) {
            $ctx = self::get_product_context($product, $slug);
            if ($ctx) {
                $titles[$slug] = self::build_product_title($ctx);
            }
        }
        return $titles;
    }

    /** Az alap termék-URL helyett mindenhol a típusos URL legyen a kanonikus. */
    public static function should_consolidate_base_url($product) {
        // SEO bővítmény mellett is: a GMC optimalizáló az ő canonical filtereikre is rá van kötve.
        return (bool) self::get_setting('enabled')
            && (bool) self::get_setting('consolidate_base_url')
            && self::supports_virtual_types($product)
            && class_exists('MG_GMC_SEO_Optimizer');
    }

    /** Termék kanonikus URL-je: az alapértelmezett típus virtuális URL-je. */
    public static function get_product_canonical_url($product) {
        if (!$product instanceof WC_Product) {
            return '';
        }
        if (self::should_consolidate_base_url($product)) {
            $default = self::get_default_type_slug();
            if ($default !== '') {
                $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
                return self::get_type_url($product, $default, is_array($config) ? $config : array());
            }
        }
        return $product->get_permalink();
    }

    /* ------------------------------------------------------------------ */
    /* Kategória-kontextus                                                 */
    /* ------------------------------------------------------------------ */

    /** A kategória neve címekhez: a slug-szerű név („minecraft-polok”) olvasható alakban. */
    public static function get_term_display_name($term) {
        $name = self::plain($term->name);
        if (!self::looks_like_slug($name)) {
            return $name;
        }
        $name = str_replace('-', ' ', $name);
        return function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8')
            : ucfirst($name);
    }

    /** A kategóriasablonok közös helyőrzői. */
    protected static function term_vars($term) {
        $parent = '';
        if (!empty($term->parent)) {
            $parent_term = get_term((int) $term->parent, $term->taxonomy);
            if ($parent_term && !is_wp_error($parent_term)) {
                $parent = self::get_term_display_name($parent_term);
            }
        }
        return array(
            'kategoria' => self::get_term_display_name($term),
            'szulo' => $parent,
            'db' => (int) $term->count,
            'oldal' => (string) self::get_setting('site_name'),
            'elonyok' => trim((string) self::get_setting('usp')),
        );
    }

    /** A H1 sablon szerinti alakja, az egyedi H1 mezőtől függetlenül. */
    public static function build_term_h1($term) {
        $vars = self::term_vars($term);
        $template = trim((string) self::get_setting('category_h1_template'));
        if ($template === '') {
            return $vars['kategoria'];
        }
        $h1 = self::clean_segment(strtr(self::drop_repeated_words($template, $vars['kategoria']), self::placeholders($vars)));
        return $h1 !== '' ? $h1 : $vars['kategoria'];
    }

    /** A kategóriaoldal H1-e: az egyedi mező, különben a sablon (vagy a név). */
    public static function get_term_h1($term, $use_template = true) {
        $custom = trim((string) get_term_meta($term->term_id, self::TERM_H1, true));
        if ($custom !== '') {
            return self::plain($custom);
        }
        return $use_template ? self::build_term_h1($term) : self::get_term_display_name($term);
    }

    public static function build_term_title($term, $include_site = true) {
        $vars = self::term_vars($term) + array('h1' => self::get_term_h1($term));
        $custom = trim((string) get_term_meta($term->term_id, self::TERM_TITLE, true));
        if ($custom !== '') {
            $template = self::contains($custom, $vars['oldal']) || strpos($custom, '{oldal}') !== false ? $custom : $custom . ' | {oldal}';
        } else {
            $template = self::drop_repeated_words((string) self::get_setting('category_title_template'), $vars['kategoria']);
        }
        return self::render_title($template, $vars, self::page_suffix(), $include_site);
    }

    public static function build_term_description($term) {
        $custom = self::plain((string) get_term_meta($term->term_id, self::TERM_DESCRIPTION, true));
        if ($custom !== '') {
            return self::truncate($custom, self::DESCRIPTION_MAX);
        }
        $vars = self::term_vars($term);
        $from_description = self::fit_sentences(term_description($term->term_id, $term->taxonomy), self::DESCRIPTION_MAX);
        if ($from_description !== '') {
            // Egy rövid (pl. 60 karakteres) leírás után az előnyök is bekerülnek, ha elférnek.
            $usp = $vars['elonyok'];
            if ($usp !== '' && self::length($from_description) < 120 && preg_match('/[.!?]$/u', $from_description)
                && !self::contains($from_description, $usp)
                && self::length($from_description . ' ' . $usp) <= self::DESCRIPTION_MAX) {
                $from_description .= ' ' . $usp;
            }
            return $from_description;
        }
        $vars['h1'] = self::get_term_h1($term);
        $template = self::drop_repeated_words((string) self::get_setting('category_description_template'), $vars['kategoria']);
        $text = self::clean_description(strtr($template, self::placeholders($vars)));
        return self::truncate($text, self::DESCRIPTION_MAX);
    }

    public static function term_is_noindex($term) {
        if (!$term || empty($term->term_id)) {
            return false;
        }
        if (get_term_meta($term->term_id, self::TERM_NOINDEX, true) === '1') {
            return true;
        }
        return $term->taxonomy === 'product_tag' && (bool) self::get_setting('noindex_tags');
    }

    /* ------------------------------------------------------------------ */
    /* Oldaladatok                                                         */
    /* ------------------------------------------------------------------ */

    protected static function current_url_base() {
        global $wp;
        $path = isset($wp->request) ? (string) $wp->request : '';
        return home_url(user_trailingslashit($path));
    }

    /**
     * Az aktuális oldal SEO-adatai: cím, leírás, canonical (csak ahol a
     * WordPress maga nem írja ki), Open Graph és séma-alapadatok.
     */
    public static function get_page_data() {
        if (self::$page !== null) {
            return self::$page;
        }
        $data = array(
            'kind' => '',
            'title' => '',
            'og_title' => '',
            'description' => '',
            'canonical' => '',
            'print_canonical' => false,
            'og_type' => 'website',
            'image' => '',
            'term' => null,
            'product_context' => null,
        );
        if (is_admin() || is_feed() || is_404() || is_search()) {
            self::$page = $data;
            return $data;
        }

        $product = self::get_current_product();
        if ($product) {
            $ctx = self::get_product_context($product);
            $data['kind'] = 'product';
            $data['product_context'] = $ctx;
            $data['title'] = self::build_product_title($ctx);
            $data['og_title'] = self::build_product_title($ctx, false);
            $data['description'] = self::build_product_description($ctx);
            $data['canonical'] = (string) wp_get_canonical_url(get_queried_object_id());
            $data['og_type'] = 'product';
            $data['image'] = $ctx['image'];
            if ($data['image'] === '' && $product->get_image_id()) {
                $data['image'] = (string) wp_get_attachment_image_url($product->get_image_id(), 'large');
            }
        } elseif ((function_exists('is_product_category') && is_product_category()) || (function_exists('is_product_tag') && is_product_tag())) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $data['kind'] = 'term';
                $data['term'] = $term;
                $data['title'] = self::build_term_title($term);
                $data['og_title'] = self::build_term_title($term, false);
                $data['description'] = self::build_term_description($term);
                $link = get_term_link($term);
                $paged = (int) get_query_var('paged');
                if (!is_wp_error($link)) {
                    $data['canonical'] = $paged > 1 ? trailingslashit($link) . user_trailingslashit('page/' . $paged, 'paged') : $link;
                    $data['print_canonical'] = true;
                }
                $data['image'] = self::get_term_image($term);
            }
        } elseif (is_front_page()) {
            $site = (string) self::get_setting('site_name');
            $data['kind'] = 'home';
            $data['title'] = self::render_title((string) self::get_setting('home_title'), array('oldal' => $site));
            $data['og_title'] = $data['title'];
            $data['description'] = self::truncate(self::plain((string) self::get_setting('home_description')));
            $data['canonical'] = is_singular() ? (string) wp_get_canonical_url(get_queried_object_id()) : home_url('/');
            $data['print_canonical'] = !is_singular();
            $data['image'] = self::get_site_image();
        } elseif (function_exists('is_shop') && is_shop()) {
            $data['kind'] = 'shop';
            $data['description'] = self::truncate(self::plain((string) self::get_setting('home_description')));
            $data['canonical'] = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : '';
            $data['print_canonical'] = (int) get_query_var('paged') < 2;
            $data['image'] = self::get_site_image();
        } elseif (is_singular()) {
            $post = get_queried_object();
            if ($post instanceof WP_Post) {
                $data['kind'] = 'singular';
                $data['og_title'] = self::plain(get_the_title($post));
                $source = $post->post_excerpt !== '' ? $post->post_excerpt : strip_shortcodes($post->post_content);
                $data['description'] = self::fit_sentences($source, self::DESCRIPTION_MAX);
                $data['canonical'] = (string) wp_get_canonical_url($post);
                $data['og_type'] = $post->post_type === 'post' ? 'article' : 'website';
                $thumb = get_post_thumbnail_id($post);
                $data['image'] = $thumb ? (string) wp_get_attachment_image_url($thumb, 'large') : self::get_site_image();
            }
        }

        self::$page = apply_filters('mg_seo_page_data', $data);
        return self::$page;
    }

    public static function get_site_image() {
        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id) {
            $url = wp_get_attachment_image_url($logo_id, 'full');
            if ($url) {
                return (string) $url;
            }
        }
        $icon = function_exists('get_site_icon_url') ? get_site_icon_url(512) : '';
        return (string) $icon;
    }

    public static function get_term_image($term) {
        $thumb = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
        if ($thumb) {
            $url = wp_get_attachment_image_url($thumb, 'large');
            if ($url) {
                return (string) $url;
            }
        }
        global $wp_query;
        if (!empty($wp_query->posts[0]) && function_exists('wc_get_product')) {
            $first = wc_get_product($wp_query->posts[0]);
            if ($first) {
                $ctx = self::get_product_context($first, '');
                if ($ctx && $ctx['image'] !== '') {
                    return $ctx['image'];
                }
                if ($first->get_image_id()) {
                    return (string) wp_get_attachment_image_url($first->get_image_id(), 'large');
                }
            }
        }
        return self::get_site_image();
    }

    /* ------------------------------------------------------------------ */
    /* Fejléc-kimenet                                                      */
    /* ------------------------------------------------------------------ */

    public static function filter_document_title($title) {
        if ($title !== '' || !self::is_active()) {
            return $title;
        }
        $data = self::get_page_data();
        return $data['title'] !== '' ? $data['title'] : $title;
    }

    public static function filter_title_separator($separator) {
        return self::is_active() ? trim((string) self::get_setting('separator')) : $separator;
    }

    /** A többi oldal címében is a rövid oldalnév szerepeljen („www.forme.hu” helyett). */
    public static function filter_title_parts($parts) {
        if (!self::is_active() || !is_array($parts)) {
            return $parts;
        }
        $site = (string) self::get_setting('site_name');
        if (isset($parts['site'])) {
            $parts['site'] = $site;
        }
        if (isset($parts['title']) && $parts['title'] === get_bloginfo('name', 'display')) {
            $parts['title'] = $site;
        }
        return $parts;
    }

    public static function output_head() {
        if (!self::is_active()) {
            return;
        }
        $data = self::get_page_data();
        if ($data['kind'] === '') {
            return;
        }
        $lines = array();
        if ($data['description'] !== '') {
            $lines[] = '<meta name="description" content="' . esc_attr($data['description']) . '" />';
        }
        if ($data['print_canonical'] && $data['canonical'] !== '') {
            $lines[] = '<link rel="canonical" href="' . esc_url($data['canonical']) . '" />';
        }
        if (self::get_setting('og_tags')) {
            $og = self::build_open_graph($data);
            foreach ($og as $property => $content) {
                if ($content === '' || $content === null) {
                    continue;
                }
                $attribute = strpos($property, 'twitter:') === 0 ? 'name' : 'property';
                $value = in_array($property, array('og:url', 'og:image'), true) ? esc_url($content) : esc_attr($content);
                $lines[] = '<meta ' . $attribute . '="' . esc_attr($property) . '" content="' . $value . '" />';
            }
        }
        if ($lines) {
            echo "\n<!-- Mockup Generator SEO -->\n" . implode("\n", $lines) . "\n";
        }
    }

    public static function build_open_graph(array $data) {
        $title = $data['og_title'] !== '' ? $data['og_title'] : $data['title'];
        if ($title === '') {
            $title = self::plain(wp_get_document_title());
        }
        $og = array(
            'og:locale' => function_exists('get_locale') ? get_locale() : 'hu_HU',
            'og:site_name' => (string) self::get_setting('site_name'),
            'og:type' => $data['og_type'],
            'og:title' => $title,
            'og:description' => $data['description'],
            'og:url' => $data['canonical'] !== '' ? $data['canonical'] : self::current_url_base(),
            'og:image' => $data['image'],
            'og:image:alt' => $data['image'] !== '' ? $title : '',
            'twitter:card' => $data['image'] !== '' ? 'summary_large_image' : 'summary',
        );
        if ($data['kind'] === 'product' && is_array($data['product_context'])) {
            $ctx = $data['product_context'];
            $product = $ctx['product'];
            $sku = $product->get_sku() !== '' ? $product->get_sku() : 'ID_' . $product->get_id();
            $og['product:price:amount'] = $ctx['price'] > 0 ? number_format($ctx['price'], 2, '.', '') : '';
            $og['product:price:currency'] = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HUF';
            $og['product:availability'] = $product->is_in_stock() ? 'in stock' : 'out of stock';
            $og['product:condition'] = 'new';
            $og['product:brand'] = self::get_brand_name();
            $og['product:retailer_item_id'] = $ctx['type'] !== '' ? $sku . '_' . $ctx['type'] : $sku;
        }
        return apply_filters('mg_seo_open_graph', $og, $data);
    }

    public static function filter_robots($robots) {
        if (!self::is_active()) {
            return $robots;
        }
        // A keresési találatoldalt a WordPress maga noindexeli (wp_robots_noindex_search).
        $noindex = false;
        if ((function_exists('is_product_category') && is_product_category()) || (function_exists('is_product_tag') && is_product_tag())) {
            $noindex = self::term_is_noindex(get_queried_object());
        }
        if ($noindex) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
            unset($robots['index']);
        }
        return $robots;
    }

    /* ------------------------------------------------------------------ */
    /* Strukturált adatok (breadcrumb, kategória-lista)                    */
    /* ------------------------------------------------------------------ */

    public static function term_trail($term) {
        $trail = array();
        $ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy'));
        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term((int) $ancestor_id, $term->taxonomy);
            if ($ancestor && !is_wp_error($ancestor)) {
                $trail[] = $ancestor;
            }
        }
        $trail[] = $term;
        return $trail;
    }

    public static function build_breadcrumb(array $data) {
        $items = array(array('name' => 'Kezdőlap', 'url' => home_url('/')));
        $term = null;
        if ($data['kind'] === 'product' && is_array($data['product_context'])) {
            $term = $data['product_context']['term'];
        } elseif ($data['kind'] === 'term') {
            $term = $data['term'];
        }
        if ($term) {
            foreach (self::term_trail($term) as $crumb) {
                $link = get_term_link($crumb);
                if (!is_wp_error($link)) {
                    $items[] = array('name' => self::plain($crumb->name), 'url' => $link);
                }
            }
        }
        if ($data['kind'] === 'product' && is_array($data['product_context'])) {
            list($name, $type) = self::display_name($data['product_context']);
            $items[] = array('name' => $type !== '' ? $name . ' - ' . $type : $name, 'url' => $data['canonical']);
        }
        if (count($items) < 2) {
            return array();
        }
        $elements = array();
        foreach ($items as $index => $item) {
            $elements[] = array(
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            );
        }
        return array('@type' => 'BreadcrumbList', 'itemListElement' => $elements);
    }

    public static function build_collection(array $data) {
        global $wp_query;
        $term = $data['term'];
        if (!$term || empty($wp_query->posts)) {
            return array();
        }
        $per_page = max(1, (int) $wp_query->get('posts_per_page'));
        $offset = max(0, (int) get_query_var('paged') - 1) * $per_page;
        $elements = array();
        foreach ($wp_query->posts as $index => $post) {
            $product = function_exists('wc_get_product') ? wc_get_product($post) : null;
            if (!$product) {
                continue;
            }
            $url = apply_filters('woocommerce_loop_product_link', get_permalink($post), $product);
            $elements[] = array(
                '@type' => 'ListItem',
                'position' => $offset + $index + 1,
                'url' => $url,
                'name' => self::plain($product->get_name()),
            );
        }
        if (!$elements) {
            return array();
        }
        return array(
            '@type' => 'CollectionPage',
            'url' => $data['canonical'],
            'name' => self::get_term_h1($term),
            'description' => $data['description'],
            'mainEntity' => array(
                '@type' => 'ItemList',
                'numberOfItems' => (int) $term->count,
                'itemListElement' => $elements,
            ),
        );
    }

    public static function json_ld($data) {
        return '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . PHP_EOL;
    }

    public static function output_schema() {
        if (!self::is_active()) {
            return;
        }
        $data = self::get_page_data();
        if (!in_array($data['kind'], array('product', 'term'), true)) {
            return;
        }
        $graph = array();
        if (self::get_setting('breadcrumb_schema')) {
            $breadcrumb = self::build_breadcrumb($data);
            if ($breadcrumb) {
                $graph[] = $breadcrumb;
            }
        }
        if ($data['kind'] === 'term') {
            $collection = self::build_collection($data);
            if ($collection) {
                $graph[] = $collection;
            }
        }
        if ($graph) {
            echo self::json_ld(array('@context' => 'https://schema.org', '@graph' => $graph));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Oldaltérkép                                                         */
    /* ------------------------------------------------------------------ */

    /** Nem virtuális (outlet, nem egyszerű) termékek – kérésenként egyszer lekérve. */
    protected static function get_non_virtual_product_ids() {
        if (self::$non_virtual_ids === null) {
            $outlet = get_posts(array(
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'meta_key' => class_exists('MG_Outlet') ? MG_Outlet::META : '_mg_outlet',
                'meta_value' => 'yes',
            ));
            $not_simple = get_posts(array(
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'tax_query' => array(array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => array('simple'),
                    'operator' => 'NOT IN',
                )),
            ));
            self::$non_virtual_ids = array_flip(array_map('intval', array_merge($outlet, $not_simple)));
        }
        return self::$non_virtual_ids;
    }

    /** A sitemap is a kanonikus (típusos) termék-URL-t adja. */
    public static function filter_sitemap_entry($entry, $post, $post_type) {
        if ($post_type !== 'product' || !self::is_active() || !self::get_setting('consolidate_base_url') || !class_exists('MG_GMC_SEO_Optimizer')) {
            return $entry;
        }
        $ids = self::get_non_virtual_product_ids();
        if (isset($ids[(int) $post->ID])) {
            return $entry;
        }
        $default = self::get_default_type_slug();
        if ($default !== '' && !empty($entry['loc'])) {
            $entry['loc'] = trailingslashit(untrailingslashit($entry['loc']) . '-' . $default);
        }
        return $entry;
    }

    /** A szerzői archívumok (admin felhasználónév) ne kerüljenek a sitemapbe. */
    public static function filter_sitemap_provider($provider, $name) {
        if ($name === 'users' && self::is_active()) {
            return false;
        }
        return $provider;
    }

    public static function filter_sitemap_taxonomies($taxonomies) {
        if (self::is_active() && self::get_setting('noindex_tags') && isset($taxonomies['product_tag'])) {
            unset($taxonomies['product_tag']);
        }
        return $taxonomies;
    }

    public static function filter_sitemap_term_args($args, $taxonomy) {
        if (!self::is_active() || !in_array($taxonomy, array('product_cat', 'product_tag'), true)) {
            return $args;
        }
        $args['meta_query'] = array(
            'relation' => 'OR',
            array('key' => self::TERM_NOINDEX, 'compare' => 'NOT EXISTS'),
            array('key' => self::TERM_NOINDEX, 'value' => '1', 'compare' => '!='),
        );
        return $args;
    }
}
