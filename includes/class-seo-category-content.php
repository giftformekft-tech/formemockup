<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kategóriaoldal tartalma keresőknek és AI-keresőknek.
 *
 * - Látható H1: a téma (Astra) elrejtette, így a kategóriaoldalon nem volt H1.
 * - A terméklista alatti SEO szöveg és GYIK (FAQPage sémával) csak az első
 *   oldalon, hogy a termékek maradjanak felül.
 * - A kategóriaszerkesztő SEO mezői: cím, H1, meta leírás, termékcím-kifejezés,
 *   alsó szöveg, GYIK, noindex.
 */
class MG_SEO_Category_Content {
    const NONCE = 'mg_seo_term_save';

    public static function init() {
        add_filter('woocommerce_show_page_title', array(__CLASS__, 'show_page_title'), 999);
        add_filter('woocommerce_page_title', array(__CLASS__, 'filter_page_title'), 20);
        add_action('woocommerce_after_shop_loop', array(__CLASS__, 'render_bottom_content'), 40);
        add_action('wp_head', array(__CLASS__, 'print_styles'), 30);
        add_action('product_cat_edit_form_fields', array(__CLASS__, 'render_term_fields'), 30);
        add_action('edited_product_cat', array(__CLASS__, 'save_term_fields'), 20);
    }

    protected static function current_category() {
        if (!function_exists('is_product_category') || !is_product_category()) {
            return null;
        }
        $term = get_queried_object();
        return $term instanceof WP_Term ? $term : null;
    }

    public static function show_page_title($show) {
        if (self::current_category() && MG_SEO_Meta::content_enabled() && MG_SEO_Meta::get_setting('category_h1')) {
            return true;
        }
        return $show;
    }

    public static function filter_page_title($title) {
        $term = self::current_category();
        if (!$term || !MG_SEO_Meta::content_enabled()) {
            return $title;
        }
        $h1 = MG_SEO_Meta::get_term_h1($term);
        return $h1 !== '' ? esc_html($h1) : $title;
    }

    /**
     * GYIK feldolgozása. Elfogadott formák (a kérdéseket üres sor választja el):
     *   Mennyi idő alatt készül el?            K: Mennyi idő alatt készül el?
     *   1–3 munkanap alatt legyártjuk.          V: 1–3 munkanap alatt legyártjuk.
     * vagy egy sorban: Kérdés? | Válasz
     */
    public static function parse_faq($text) {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $items = array();
        $flush = function ($item) use (&$items) {
            if ($item && trim($item['q']) !== '' && trim($item['a']) !== '') {
                $items[] = array('q' => trim($item['q']), 'a' => trim($item['a']));
            }
        };
        foreach (preg_split('/\n\s*\n/', $text) as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen'));
            if (!$lines) {
                continue;
            }
            if (count($lines) === 1 && strpos($lines[0], '|') !== false) {
                $parts = explode('|', $lines[0], 2);
                $flush(array('q' => $parts[0], 'a' => $parts[1]));
                continue;
            }
            $current = null;
            foreach ($lines as $line) {
                if (preg_match('/^(?:K|Q|Kérdés)\s*[:.)]\s*(.+)$/iu', $line, $match)) {
                    $flush($current);
                    $current = array('q' => $match[1], 'a' => '');
                    continue;
                }
                if ($current === null) {
                    $current = array('q' => $line, 'a' => '');
                    continue;
                }
                $line = preg_replace('/^(?:V|A|Válasz)\s*[:.)]\s*/iu', '', $line);
                $current['a'] = $current['a'] === '' ? $line : $current['a'] . "\n" . $line;
            }
            $flush($current);
        }
        return $items;
    }

    protected static function answer_html($answer) {
        $allowed = array(
            'a' => array('href' => true, 'title' => true, 'target' => true, 'rel' => true),
            'strong' => array(),
            'b' => array(),
            'em' => array(),
            'i' => array(),
            'br' => array(),
        );
        return wpautop(wp_kses($answer, $allowed));
    }

    public static function render_faq(array $items, $heading = 'Gyakori kérdések') {
        if (!$items) {
            return '';
        }
        $html = '<div class="mg-seo-faq"><h2 class="mg-seo-faq__title">' . esc_html($heading) . '</h2>';
        $entities = array();
        foreach ($items as $item) {
            $html .= '<details class="mg-seo-faq__item"><summary>' . esc_html($item['q']) . '</summary>'
                . '<div class="mg-seo-faq__answer">' . self::answer_html($item['a']) . '</div></details>';
            $entities[] = array(
                '@type' => 'Question',
                'name' => MG_SEO_Meta::plain($item['q']),
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => MG_SEO_Meta::plain($item['a'])),
            );
        }
        $html .= '</div>';
        $html .= MG_SEO_Meta::json_ld(array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ));
        return $html;
    }

    public static function render_bottom_content() {
        $term = self::current_category();
        if (!$term || !MG_SEO_Meta::content_enabled() || !MG_SEO_Meta::get_setting('category_content')) {
            return;
        }
        if ((int) get_query_var('paged') > 1) {
            return;
        }
        $text = (string) get_term_meta($term->term_id, MG_SEO_Meta::TERM_BOTTOM_TEXT, true);
        $has_text = trim(wp_strip_all_tags($text)) !== '';
        $faq = self::parse_faq((string) get_term_meta($term->term_id, MG_SEO_Meta::TERM_FAQ, true));
        if (!$has_text && !$faq) {
            return;
        }
        echo '<section class="mg-seo-category-content">';
        if ($has_text) {
            echo '<div class="mg-seo-bottom-text">' . wpautop(wp_kses_post($text)) . '</div>';
        }
        echo self::render_faq($faq); // phpcs:ignore WordPress.Security.EscapeOutput -- elemenként escape-elve
        echo '</section>';
    }

    public static function print_styles() {
        if (!self::current_category() || !MG_SEO_Meta::content_enabled()) {
            return;
        }
        echo '<style id="mg-seo-category-css">'
            . '.tax-product_cat .woocommerce-products-header__title.page-title{font-size:clamp(1.6rem,1.1rem + 1.6vw,2.4rem);line-height:1.2;margin:0 0 .5rem}'
            . '.mg-seo-category-content{margin:2.5rem 0 1rem;max-width:960px}'
            . '.mg-seo-category-content h2{font-size:clamp(1.25rem,1rem + .8vw,1.6rem);line-height:1.3;margin:1.5rem 0 .6rem}'
            . '.mg-seo-category-content h3{font-size:1.15rem;margin:1.2rem 0 .5rem}'
            . '.mg-seo-faq__item{border-bottom:1px solid rgba(0,0,0,.12);padding:.8rem 0}'
            . '.mg-seo-faq__item summary{cursor:pointer;font-weight:600}'
            . '.mg-seo-faq__answer{margin-top:.5rem}'
            . '.mg-seo-faq__answer p:last-child{margin-bottom:0}'
            . '</style>' . "\n";
    }

    /* ------------------------------------------------------------------ */
    /* Kategóriaszerkesztő                                                 */
    /* ------------------------------------------------------------------ */

    public static function render_term_fields($term) {
        if (!$term instanceof WP_Term) {
            return;
        }
        $get = function ($key) use ($term) {
            return (string) get_term_meta($term->term_id, $key, true);
        };
        $current_title = MG_SEO_Meta::build_term_title($term);
        $current_description = MG_SEO_Meta::build_term_description($term);

        echo '<tr class="form-field mg-seo-term-heading"><th colspan="2" style="padding-bottom:0">';
        echo '<h2 style="margin:1.5em 0 .3em">' . esc_html__('SEO és AI keresők', 'mockup-generator') . '</h2>';
        echo '<p class="description" style="font-weight:normal">' . esc_html__('Üresen hagyott mezőnél automatikus érték kerül ki (SEO és AI keresők beállítások). Az alsó szöveg és a GYIK a terméklista alatt, csak az első oldalon jelenik meg.', 'mockup-generator') . '</p>';
        wp_nonce_field(self::NONCE, 'mg_seo_term_nonce');
        echo '</th></tr>';

        self::text_row(MG_SEO_Meta::TERM_TITLE, __('SEO cím (title)', 'mockup-generator'), $get(MG_SEO_Meta::TERM_TITLE), 60,
            sprintf(__('Jelenleg: %s – a „| Oldalnév” automatikusan a végére kerül, ha hiányzik.', 'mockup-generator'), $current_title),
            __('pl. Születésnapi pólók – Vicces és egyedi pólók', 'mockup-generator'));
        if (MG_SEO_Meta::looks_like_slug(MG_SEO_Meta::plain($term->name))) {
            echo '<tr class="form-field"><td colspan="2"><div class="notice notice-warning inline"><p>' . esc_html(sprintf(
                __('A kategória neve slug-szerű („%s”): nevezd át olvasható névre (pl. „Minecraft pólók”), mert a címekben és a H1-ben is a név jelenik meg.', 'mockup-generator'),
                $term->name
            )) . '</p></div></td></tr>';
        }
        self::text_row(MG_SEO_Meta::TERM_H1, __('H1 főcím', 'mockup-generator'), $get(MG_SEO_Meta::TERM_H1), 70,
            sprintf(__('A kategóriaoldal látható főcíme. Üresen a H1 sablon szerint: %s', 'mockup-generator'), MG_SEO_Meta::build_term_h1($term)),
            __('pl. Születésnapi pólók – egyedi és vicces minták minden korra', 'mockup-generator'));

        echo '<tr class="form-field"><th scope="row"><label for="mg-seo-desc">' . esc_html__('Meta leírás', 'mockup-generator') . '</label></th><td>';
        echo '<textarea name="' . esc_attr(MG_SEO_Meta::TERM_DESCRIPTION) . '" id="mg-seo-desc" rows="3" class="large-text" data-mg-seo-limit="160">' . esc_textarea($get(MG_SEO_Meta::TERM_DESCRIPTION)) . '</textarea>';
        echo '<p class="description">' . esc_html(sprintf(__('120–160 karakter ajánlott. Jelenleg: %s', 'mockup-generator'), $current_description)) . '</p></td></tr>';

        self::text_row(MG_SEO_Meta::TERM_PRODUCT_KEYWORD, __('Kifejezés a termékoldalak címében', 'mockup-generator'), $get(MG_SEO_Meta::TERM_PRODUCT_KEYWORD), 30,
            __('A kategória termékeinek <title>-jébe kerül (pl. „Minta - Férfi póló | Születésnapi ajándék | Forme.hu”). Üresen a kategória neve. Ha a terméknek több kategóriája van, a kitöltött mezőjű kategória élvez elsőbbséget.', 'mockup-generator'),
            __('pl. Születésnapi ajándék', 'mockup-generator'));

        echo '<tr class="form-field"><th scope="row"><label for="mg_seo_bottom">' . esc_html__('Alsó SEO szöveg', 'mockup-generator') . '</label></th><td>';
        wp_editor($get(MG_SEO_Meta::TERM_BOTTOM_TEXT), 'mg_seo_bottom', array(
            'textarea_name' => MG_SEO_Meta::TERM_BOTTOM_TEXT,
            'textarea_rows' => 10,
            'media_buttons' => false,
            'teeny' => true,
        ));
        echo '<p class="description">' . esc_html__('A terméklista alatt jelenik meg. Alcímekkel (H2/H3) írd le, kinek, milyen alkalomra, milyen terméktípuson (póló, pulóver, bögre…) érhető el a téma, kérhető-e egyedi felirat, és mit érdemes tudni a rendelésről.', 'mockup-generator') . '</p></td></tr>';

        echo '<tr class="form-field"><th scope="row"><label for="mg-seo-faq">' . esc_html__('Gyakori kérdések (GYIK)', 'mockup-generator') . '</label></th><td>';
        echo '<textarea name="' . esc_attr(MG_SEO_Meta::TERM_FAQ) . '" id="mg-seo-faq" rows="8" class="large-text code">' . esc_textarea($get(MG_SEO_Meta::TERM_FAQ)) . '</textarea>';
        echo '<p class="description">' . esc_html__('Kérdésenként: első sor a kérdés, alatta a válasz, a kérdések között üres sor. A „K:” / „V:” előtag és a „Kérdés? | Válasz” egysoros forma is jó. FAQPage sémát is kap.', 'mockup-generator') . '</p></td></tr>';

        $noindex = $get(MG_SEO_Meta::TERM_NOINDEX) === '1';
        echo '<tr class="form-field"><th scope="row">' . esc_html__('Keresőkből kizárás', 'mockup-generator') . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(MG_SEO_Meta::TERM_NOINDEX) . '" value="1"' . checked($noindex, true, false) . ' /> ';
        echo esc_html__('noindex – a kategóriaoldal ne kerüljön a keresőkbe és a sitemapbe (pl. duplikált vagy üres kategória)', 'mockup-generator') . '</label></td></tr>';

        self::print_counter_script();
    }

    protected static function text_row($key, $label, $value, $limit, $help, $placeholder) {
        echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" name="' . esc_attr($key) . '" id="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="large-text" placeholder="' . esc_attr($placeholder) . '" data-mg-seo-limit="' . (int) $limit . '" />';
        echo '<p class="description">' . esc_html($help) . '</p></td></tr>';
    }

    protected static function print_counter_script() {
        ?>
        <script>
        (function () {
            document.querySelectorAll('[data-mg-seo-limit]').forEach(function (field) {
                var limit = parseInt(field.getAttribute('data-mg-seo-limit'), 10) || 0;
                var counter = document.createElement('span');
                counter.style.marginLeft = '6px';
                field.insertAdjacentElement('afterend', counter);
                function update() {
                    var length = Array.from(field.value || '').length;
                    counter.textContent = length + ' / ' + limit + ' karakter';
                    counter.style.color = length > limit ? '#b32d2e' : '#50575e';
                }
                field.addEventListener('input', update);
                update();
            });
        })();
        </script>
        <?php
    }

    public static function save_term_fields($term_id) {
        if (!isset($_POST['mg_seo_term_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_seo_term_nonce'])), self::NONCE)) {
            return;
        }
        if (!current_user_can('edit_term', $term_id)) {
            return;
        }
        $faq_tags = array(
            'a' => array('href' => true, 'title' => true, 'target' => true, 'rel' => true),
            'strong' => array(),
            'b' => array(),
            'em' => array(),
            'i' => array(),
            'br' => array(),
        );
        $fields = array(
            MG_SEO_Meta::TERM_TITLE => 'sanitize_text_field',
            MG_SEO_Meta::TERM_H1 => 'sanitize_text_field',
            MG_SEO_Meta::TERM_PRODUCT_KEYWORD => 'sanitize_text_field',
            MG_SEO_Meta::TERM_DESCRIPTION => 'sanitize_textarea_field',
            MG_SEO_Meta::TERM_BOTTOM_TEXT => 'wp_kses_post',
            MG_SEO_Meta::TERM_FAQ => function ($value) use ($faq_tags) {
                return wp_kses($value, $faq_tags);
            },
        );
        foreach ($fields as $key => $sanitize) {
            $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            $value = is_string($raw) ? trim((string) call_user_func($sanitize, $raw)) : '';
            if ($value === '') {
                delete_term_meta($term_id, $key);
            } else {
                update_term_meta($term_id, $key, $value);
            }
        }
        if (!empty($_POST[MG_SEO_Meta::TERM_NOINDEX])) {
            update_term_meta($term_id, MG_SEO_Meta::TERM_NOINDEX, '1');
        } else {
            delete_term_meta($term_id, MG_SEO_Meta::TERM_NOINDEX);
        }
        do_action('mg_seo_term_saved', $term_id);
    }
}
