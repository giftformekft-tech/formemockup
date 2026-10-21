<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grafikus profilok.
 *
 * Minden mintához (szülő termékhez) egy grafikus profil tartozik: a neve a
 * terméknév alatt jelenik meg, és a saját profiloldalára (/grafikus/{slug}/)
 * visz, ahol a profil összes mintája látszik.
 *
 *  - mg_designer taxonómia a termékeken, a profiladatok term meta-ban
 *  - kiosztás: véletlen aktív profil – az adminban gombbal tömegesen, új
 *    termékeknél (bulk feltöltés) automatikusan
 *  - a megjelenés kapcsolható, alapból KI van kapcsolva; kikapcsolva a
 *    profiloldalak és a programoldal 404-et adnak (adminnak előnézetként
 *    megnyílnak)
 *  - feedekbe, structured datába, sitemapbe nem kerül semmi; a profiloldal
 *    noindex
 */
class MG_Designer_Profiles {

    const TAXONOMY = 'mg_designer';
    const REWRITE_SLUG = 'grafikus';
    const REWRITE_VERSION = '1';
    const OPTION_KEY = 'mg_designer_profiles';
    const REWRITE_OPTION = 'mg_designer_rewrite_version';
    const SHORTCODE = 'mg_designer_program';
    const PREVIEW_CAP = 'edit_products';

    const META_AVATAR = 'mg_designer_avatar';
    const META_TAGLINE = 'mg_designer_tagline';
    const META_LOCATION = 'mg_designer_location';
    const META_SINCE = 'mg_designer_since';
    const META_BIO = 'mg_designer_bio';
    const META_ACTIVE = 'mg_designer_active';
    const META_MAX = 'mg_designer_max';

    /** A kiosztásban számító termékstátuszok (a kuka és az auto-draft nem). */
    const PRODUCT_STATUSES = "'publish','draft','pending','private','future'";

    /** @var int[]|null */
    private static $active_ids = null;

    /** @var array<int,bool> Termékek, amelyeknél a termékoldali sor már kiment. */
    private static $single_rendered = array();

    /** @var bool */
    private static $header_rendered = false;

    public static function init() {
        add_action('init', array(__CLASS__, 'register_taxonomy'), 5);
        add_action('init', array(__CLASS__, 'maybe_flush_rewrite_rules'), 99);
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_program_shortcode'));
        add_action('template_redirect', array(__CLASS__, 'guard_public_pages'), 1);
        add_action('wp', array(__CLASS__, 'hook_frontend'));
        add_filter('wp_robots', array(__CLASS__, 'filter_robots'), 30);
        add_filter('wp_sitemaps_taxonomies', array(__CLASS__, 'filter_sitemap_taxonomies'));
        add_filter('wp_sitemaps_posts_query_args', array(__CLASS__, 'filter_sitemap_posts'), 10, 2);
        add_action('save_post_product', array(__CLASS__, 'save_product_meta_box'), 10, 2);
    }

    /* ------------------------------------------------------------------ */
    /* Beállítások                                                         */
    /* ------------------------------------------------------------------ */

    public static function get_default_settings() {
        return array(
            'enabled'            => false,
            'show_single'        => true,
            'show_loop'          => true,
            'show_gift_finder'   => true,
            'label'              => 'Tervezte:',
            'auto_assign'        => true,
            'program_page_id'    => 0,
            'program_commission' => 3,
            'program_minimum'    => 5000,
            'program_payout'     => 'havonta, a tárgyhónapot követő hónap 15-éig, banki átutalással',
            'program_intro'      => 'Grafikus vagy? A {oldal} grafikus programjában a mintáid a termékeinken kelnek el, saját profiloldallal. Te tervezel, mi gyártunk, értékesítünk és szállítunk – minden eladott termék után pedig jutalékot kapsz.',
            'program_faq'        => implode("\n", array(
                'Ki vehet részt a programban? | A programba jelenleg meghívásos alapon, egyedi elbírálás után kerülnek be a grafikusok.',
                'Mennyi a jutalék? | Minden eladott termék nettó eladási árának {jutalek}-a, darabonként.',
                'Mikor és hogyan fizettek? | A jutalékot {kifizetes} fizetjük ki. A kifizetés {minimum} egyenlegtől indul, az alatti összeg a következő hónapra átkerül.',
                'Nekem kell gyártanom vagy postáznom? | Nem. A gyártás, a csomagolás, a kiszállítás és a vásárlói ügyintézés a mi feladatunk.',
            )),
            'program_extra'      => '',
        );
    }

    public static function get_settings() {
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return array_merge(self::get_default_settings(), $stored);
    }

    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $commission = isset($input['program_commission']) ? (float) str_replace(',', '.', (string) $input['program_commission']) : 0;

        return array(
            'enabled'            => !empty($input['enabled']),
            'show_single'        => !empty($input['show_single']),
            'show_loop'          => !empty($input['show_loop']),
            'show_gift_finder'   => !empty($input['show_gift_finder']),
            'label'              => isset($input['label']) ? sanitize_text_field($input['label']) : '',
            'auto_assign'        => !empty($input['auto_assign']),
            'program_page_id'    => isset($input['program_page_id']) ? absint($input['program_page_id']) : 0,
            'program_commission' => (float) max(0, min(100, round($commission, 2))),
            'program_minimum'    => isset($input['program_minimum']) ? absint($input['program_minimum']) : 0,
            'program_payout'     => isset($input['program_payout']) ? sanitize_text_field($input['program_payout']) : '',
            'program_intro'      => isset($input['program_intro']) ? sanitize_textarea_field($input['program_intro']) : '',
            'program_faq'        => isset($input['program_faq']) ? sanitize_textarea_field($input['program_faq']) : '',
            'program_extra'      => isset($input['program_extra']) ? wp_kses_post($input['program_extra']) : '',
        );
    }

    public static function update_settings($values) {
        $settings = array_merge(self::get_settings(), is_array($values) ? $values : array());
        update_option(self::OPTION_KEY, self::sanitize_settings($settings));
    }

    /** A látogatók látják-e a funkciót (tervezősor, profiloldal, programoldal). */
    public static function is_display_enabled() {
        $settings = self::get_settings();
        return !empty($settings['enabled']);
    }

    /** Kikapcsolt állapotban az admin előnézetként megnyithatja a profil- és programoldalt. */
    public static function can_view_pages() {
        return self::is_display_enabled() || current_user_can(self::PREVIEW_CAP);
    }

    public static function get_program_page_id() {
        $settings = self::get_settings();
        $page_id = absint($settings['program_page_id']);
        if ($page_id <= 0) {
            return 0;
        }
        $status = get_post_status($page_id);
        return ($status && $status !== 'trash') ? $page_id : 0;
    }

    /** Kikapcsolva a programoldal ne kerüljön az llms.txt-be sem. */
    public static function hidden_page_ids() {
        $page_id = self::get_program_page_id();
        return ($page_id > 0 && !self::is_display_enabled()) ? array($page_id) : array();
    }

    /* ------------------------------------------------------------------ */
    /* Taxonómia                                                           */
    /* ------------------------------------------------------------------ */

    public static function register_taxonomy() {
        register_taxonomy(self::TAXONOMY, array('product'), array(
            'labels' => array(
                'name'          => __('Grafikusok', 'mockup-generator'),
                'singular_name' => __('Grafikus', 'mockup-generator'),
                'menu_name'     => __('Grafikusok', 'mockup-generator'),
                'all_items'     => __('Összes grafikus', 'mockup-generator'),
                'edit_item'     => __('Grafikus szerkesztése', 'mockup-generator'),
                'add_new_item'  => __('Új grafikus', 'mockup-generator'),
                'search_items'  => __('Grafikus keresése', 'mockup-generator'),
                'not_found'     => __('Nincs grafikus.', 'mockup-generator'),
            ),
            'public'             => true,
            'publicly_queryable' => true,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'show_in_nav_menus'  => false,
            'show_tagcloud'      => false,
            'show_in_quick_edit' => false,
            'show_admin_column'  => true,
            'show_in_rest'       => false,
            'meta_box_cb'        => array(__CLASS__, 'render_product_meta_box'),
            'query_var'          => true,
            'rewrite'            => array('slug' => self::REWRITE_SLUG, 'with_front' => false),
            'capabilities'       => array(
                'manage_terms' => 'manage_woocommerce',
                'edit_terms'   => 'manage_woocommerce',
                'delete_terms' => 'manage_woocommerce',
                'assign_terms' => 'edit_products',
            ),
        ));
    }

    public static function maybe_flush_rewrite_rules() {
        if (get_option(self::REWRITE_OPTION) === self::REWRITE_VERSION) {
            return;
        }
        flush_rewrite_rules(false);
        update_option(self::REWRITE_OPTION, self::REWRITE_VERSION);
    }

    /* ------------------------------------------------------------------ */
    /* Profilok és kiosztás                                                */
    /* ------------------------------------------------------------------ */

    /** @return WP_Term[] */
    public static function get_profiles() {
        $terms = get_terms(array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        return is_array($terms) ? $terms : array();
    }

    public static function is_profile_active($term_id) {
        return get_term_meta((int) $term_id, self::META_ACTIVE, true) !== '0';
    }

    /** A véletlen kiosztásban részt vevő profilok. */
    public static function get_active_profile_ids() {
        if (self::$active_ids === null) {
            $ids = array();
            foreach (self::get_profiles() as $term) {
                if (self::is_profile_active($term->term_id)) {
                    $ids[] = (int) $term->term_id;
                }
            }
            self::$active_ids = $ids;
        }
        return self::$active_ids;
    }

    public static function flush_cache() {
        self::$active_ids = null;
    }

    /** A profil alá kerülhető minták maximuma; 0 = korlátlan. */
    public static function get_profile_max($term_id) {
        return absint(get_term_meta((int) $term_id, self::META_MAX, true));
    }

    /** @return array<int,int> profil => maximum, csak a korlátos profilok */
    public static function get_profile_limits($ids) {
        $limits = array();
        foreach ((array) $ids as $id) {
            $max = self::get_profile_max($id);
            if ($max > 0) {
                $limits[(int) $id] = $max;
            }
        }
        return $limits;
    }

    /**
     * Profilonként a besorolt minták száma (minden státusz, ami a kiosztásban
     * is számít, nem csak a közzétettek).
     *
     * @param int|null $max_product_id csak az eddigi ID-ig (újrasorsolás közben)
     * @return array<int,int>
     */
    public static function get_assignment_counts($max_product_id = null) {
        global $wpdb;
        $sql = "SELECT tt.term_id, COUNT(*) FROM {$wpdb->term_relationships} tr"
            . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . " INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id"
            . " WHERE tt.taxonomy = %s AND p.post_type = 'product' AND p.post_status IN (" . self::PRODUCT_STATUSES . ")";
        $args = array(self::TAXONOMY);
        if ($max_product_id !== null) {
            $sql .= ' AND p.ID <= %d';
            $args[] = (int) $max_product_id;
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql . ' GROUP BY tt.term_id', $args), ARRAY_N);
        $counts = array();
        foreach ((array) $rows as $row) {
            $counts[(int) $row[0]] = (int) $row[1];
        }
        return $counts;
    }

    /**
     * Szabad hely az aktív profiloknál; null, ha van korlátlan aktív profil.
     *
     * @return int|null
     */
    public static function get_free_capacity() {
        $ids = self::get_active_profile_ids();
        $limits = self::get_profile_limits($ids);
        if (count($limits) < count($ids)) {
            return null;
        }
        $counts = self::get_assignment_counts();
        $free = 0;
        foreach ($limits as $id => $max) {
            $free += max(0, $max - ($counts[$id] ?? 0));
        }
        return $free;
    }

    /**
     * Véletlen profil a még nem betelt profilok közül, egyenletes eloszlással.
     *
     * @param int[]|null      $ids    jelöltek (alapból az aktív profilok)
     * @param array<int,int>  $counts profilonként a mostani mintaszám
     * @param array<int,int>  $limits profilonként a maximum (hiányzó = korlátlan)
     * @return int 0, ha nincs (szabad helyű) profil
     */
    public static function pick_random_profile_id($ids = null, $counts = array(), $limits = array()) {
        $ids = $ids === null ? self::get_active_profile_ids() : array_values(array_map('intval', (array) $ids));
        $ids = array_values(array_filter($ids, function ($id) use ($counts, $limits) {
            return empty($limits[$id]) || ($counts[$id] ?? 0) < $limits[$id];
        }));
        if (empty($ids)) {
            return 0;
        }
        return $ids[random_int(0, count($ids) - 1)];
    }

    /** @return WP_Term|null */
    public static function get_product_profile($product_id) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return null;
        }
        if (get_post_type($product_id) === 'product_variation') {
            $product_id = (int) wp_get_post_parent_id($product_id);
        }
        $terms = get_the_terms($product_id, self::TAXONOMY);
        if (empty($terms) || is_wp_error($terms)) {
            return null;
        }
        return reset($terms);
    }

    public static function assign_profile($product_id, $term_id) {
        $result = wp_set_object_terms((int) $product_id, array((int) $term_id), self::TAXONOMY, false);
        return !is_wp_error($result);
    }

    /**
     * A termék-létrehozás hívja (egyedi feltöltés és minden bulk út): ha a
     * terméknek még nincs grafikusa, kap egy véletlen aktív, még nem betelt
     * profilt. Ha minden profil betelt, grafikus nélkül marad.
     */
    public static function maybe_assign_new_product($product_id) {
        $settings = self::get_settings();
        if (empty($settings['auto_assign']) || self::get_product_profile($product_id)) {
            return 0;
        }
        $ids = self::get_active_profile_ids();
        $limits = self::get_profile_limits($ids);
        $counts = $limits ? self::get_assignment_counts() : array();
        $term_id = self::pick_random_profile_id($ids, $counts, $limits);
        if ($term_id <= 0 || !self::assign_profile($product_id, $term_id)) {
            return 0;
        }
        return $term_id;
    }

    private static function batch_where_sql($mode) {
        global $wpdb;
        $where = "p.post_type = 'product' AND p.post_status IN (" . self::PRODUCT_STATUSES . ")";
        $has_profile = $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = %s",
            self::TAXONOMY
        );
        if ($mode === 'missing') {
            $where .= " AND NOT EXISTS ($has_profile)";
        } elseif ($mode === 'clear') {
            $where .= " AND EXISTS ($has_profile)";
        }
        return $where;
    }

    /** @return array{total:int,assigned:int,missing:int} */
    public static function get_assignment_stats() {
        global $wpdb;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . self::batch_where_sql('all'));
        $assigned = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . self::batch_where_sql('clear'));
        return array(
            'total'    => $total,
            'assigned' => $assigned,
            'missing'  => max(0, $total - $assigned),
        );
    }

    /**
     * Egy köteg kiosztás / törlés, ID szerint növekvő kurzorral.
     *
     * A betelt profilok kimaradnak. Újrasorsoláskor a kurzorig már újra
     * kiosztott termékek számítanak bele a profilok mintaszámába (a kurzor
     * utániak úgyis új grafikust kapnak); akinek már nem jut hely, annál a
     * régi besorolás is lekerül, különben túllépné a régi profil maximumát.
     *
     * @param string $mode   missing: csak a grafikus nélküliek; all: mindenki újrasorsolva; clear: besorolás törlése
     * @param int    $cursor az előző köteg utolsó termék-ID-ja
     * @param int    $limit
     * @return array|WP_Error
     */
    public static function process_batch($mode, $cursor, $limit = 200) {
        global $wpdb;
        if (!in_array($mode, array('missing', 'all', 'clear'), true)) {
            return new WP_Error('invalid_mode', __('Ismeretlen művelet.', 'mockup-generator'));
        }
        $profile_ids = $mode === 'clear' ? array() : self::get_active_profile_ids();
        if ($mode !== 'clear' && empty($profile_ids)) {
            return new WP_Error('no_profiles', __('Nincs aktív grafikus profil, akik között szét lehetne osztani a mintákat.', 'mockup-generator'));
        }

        $cursor = absint($cursor);
        $limit = max(1, absint($limit));
        $where = self::batch_where_sql($mode);

        $limits = $mode === 'clear' ? array() : self::get_profile_limits($profile_ids);
        $counts = $limits ? self::get_assignment_counts($mode === 'all' ? $cursor : null) : array();
        if ($mode === 'missing' && $cursor === 0 && self::pick_random_profile_id($profile_ids, $counts, $limits) === 0) {
            return new WP_Error('all_full', __('Minden aktív profil betelt (elérte a maximum mintaszámát). Emeld valamelyik maximumát, vagy hozz létre új profilt.', 'mockup-generator'));
        }

        $total = null;
        if ($cursor === 0) {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where");
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p WHERE $where AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
            $cursor,
            $limit
        ));
        $ids = array_map('intval', (array) $ids);

        $skipped = 0;
        wp_defer_term_counting(true);
        foreach ($ids as $product_id) {
            if ($mode === 'clear') {
                wp_delete_object_term_relationships($product_id, self::TAXONOMY);
                continue;
            }
            $term_id = self::pick_random_profile_id($profile_ids, $counts, $limits);
            if ($term_id <= 0) {
                if ($mode === 'all') {
                    wp_delete_object_term_relationships($product_id, self::TAXONOMY);
                }
                $skipped++;
                continue;
            }
            self::assign_profile($product_id, $term_id);
            if (isset($limits[$term_id])) {
                $counts[$term_id] = ($counts[$term_id] ?? 0) + 1;
            }
        }
        wp_defer_term_counting(false);

        return array(
            'processed' => count($ids),
            'skipped'   => $skipped,
            'cursor'    => $ids ? end($ids) : $cursor,
            'done'      => count($ids) < $limit,
            'total'     => $total,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Termékszerkesztő meta box                                           */
    /* ------------------------------------------------------------------ */

    public static function render_product_meta_box($post) {
        $current = wp_get_object_terms($post->ID, self::TAXONOMY, array('fields' => 'ids'));
        $current_id = (!is_wp_error($current) && !empty($current)) ? (int) $current[0] : 0;

        $profiles = self::get_profiles();
        $limits = self::get_profile_limits(wp_list_pluck($profiles, 'term_id'));
        $counts = $limits ? self::get_assignment_counts() : array();

        wp_nonce_field('mg_designer_product', 'mg_designer_product_nonce');
        echo '<select name="mg_designer_term" class="widefat">';
        echo '<option value="0">' . esc_html__('— nincs —', 'mockup-generator') . '</option>';
        foreach ($profiles as $term) {
            $label = $term->name;
            $id = (int) $term->term_id;
            if (isset($limits[$id]) && ($counts[$id] ?? 0) >= $limits[$id]) {
                /* translators: %s: profile name */
                $label = sprintf(__('%s (betelt)', 'mockup-generator'), $label);
            }
            echo '<option value="' . esc_attr($id) . '"' . selected($current_id, $id, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        if ($limits) {
            echo '<p class="description">' . esc_html__('Kézzel betelt profil is választható, a maximum csak a véletlen kiosztásra vonatkozik.', 'mockup-generator') . '</p>';
        }
    }

    public static function save_product_meta_box($post_id, $post) {
        if (!isset($_POST['mg_designer_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_designer_product_nonce'])), 'mg_designer_product')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) {
            return;
        }
        $term_id = isset($_POST['mg_designer_term']) ? absint($_POST['mg_designer_term']) : 0;
        if ($term_id > 0 && term_exists($term_id, self::TAXONOMY)) {
            self::assign_profile($post_id, $term_id);
        } else {
            wp_delete_object_term_relationships($post_id, self::TAXONOMY);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Frontend                                                            */
    /* ------------------------------------------------------------------ */

    public static function hook_frontend() {
        if (is_admin()) {
            return;
        }
        $on_profile = is_tax(self::TAXONOMY);
        $page_id = self::get_program_page_id();
        $on_program = $page_id > 0 && is_page($page_id);

        if (self::is_display_enabled() || (($on_profile || $on_program) && self::can_view_pages())) {
            add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        }

        if ($on_profile && self::can_view_pages()) {
            add_action('woocommerce_archive_description', array(__CLASS__, 'render_profile_header'), 5);
            remove_action('woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10);
            // Tartalék, ha a téma nem futtatja a woocommerce_archive_description hookot.
            add_action('woocommerce_before_shop_loop', array(__CLASS__, 'render_profile_header'), 5);
            add_action('woocommerce_no_products_found', array(__CLASS__, 'render_profile_header'), 5);
        }

        if (!self::is_display_enabled()) {
            return;
        }
        $settings = self::get_settings();
        if (!empty($settings['show_single'])) {
            // Astra a saját termékoldal-struktúrájában külön hookot futtat a cím után;
            // a sima WooCommerce sorrendben a cím 5-ös prioritású.
            add_action('astra_woo_single_title_after', array(__CLASS__, 'render_single_byline'));
            add_action('woocommerce_single_product_summary', array(__CLASS__, 'render_single_byline_fallback'), 6);
        }
        if (!empty($settings['show_loop'])) {
            if (self::is_astra()) {
                // Astra a címet a kártya képlinkjén kívül rendereli, ide mehet link.
                add_action('astra_woo_shop_title_after', array(__CLASS__, 'render_loop_byline'));
            } else {
                // A sima WooCommerce kártyán a cím a termék linkjén belül van: csak szöveg.
                add_action('woocommerce_after_shop_loop_item_title', array(__CLASS__, 'render_loop_byline_plain'), 4);
            }
        }
    }

    private static function is_astra() {
        return function_exists('get_template') && get_template() === 'astra';
    }

    public static function enqueue_assets() {
        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        wp_enqueue_style(
            'mg-designer-profiles',
            plugins_url('assets/css/designer-profiles.css', $base_file),
            array(),
            MG_VERSION
        );
    }

    private static function current_product_id() {
        global $product;
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return 0;
        }
        return (int) ($product->get_parent_id() ?: $product->get_id());
    }

    public static function render_single_byline() {
        $product_id = self::current_product_id();
        if ($product_id <= 0 || isset(self::$single_rendered[$product_id])) {
            return;
        }
        self::$single_rendered[$product_id] = true;
        echo self::get_byline_html($product_id, 'single'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Tartalék a sima WooCommerce sorrendhez (cím: 5). Ha a cím innen ki van
     * véve – Astra a saját struktúrájában, később rendereli –, itt nem írunk
     * semmit, különben a sor a cím fölé kerülne; ott az Astra hook viszi.
     */
    public static function render_single_byline_fallback() {
        $title_priority = has_action('woocommerce_single_product_summary', 'woocommerce_template_single_title');
        if (($title_priority === false || $title_priority > 6) && self::is_astra()) {
            return;
        }
        self::render_single_byline();
    }

    public static function render_loop_byline() {
        echo self::get_byline_html(self::current_product_id(), 'loop'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function render_loop_byline_plain() {
        echo self::get_byline_html(self::current_product_id(), 'plain'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** Ajándékkereső kártya: a kártya maga egy link, ezért itt csak szöveg. */
    public static function get_card_byline_html($product_id) {
        $settings = self::get_settings();
        if (empty($settings['enabled']) || empty($settings['show_gift_finder'])) {
            return '';
        }
        return self::get_byline_html($product_id, 'plain');
    }

    /**
     * @param string $context single: avatar + link; loop: link; plain: szöveg, link nélkül
     */
    public static function get_byline_html($product_id, $context = 'loop') {
        $term = self::get_product_profile($product_id);
        if (!$term) {
            return '';
        }
        $settings = self::get_settings();
        $label = trim((string) $settings['label']);

        $html = '<div class="mg-designer-byline mg-designer-byline--' . esc_attr($context) . '">';
        if ($label !== '') {
            $html .= '<span class="mg-designer-byline__label">' . esc_html($label) . '</span> ';
        }
        $name = '';
        if ($context === 'single') {
            $name .= self::get_avatar_html($term);
        }
        $name .= '<span class="mg-designer-byline__name">' . esc_html($term->name) . '</span>';

        $link = $context === 'plain' ? '' : get_term_link($term);
        if ($link && !is_wp_error($link)) {
            $html .= '<a class="mg-designer-byline__link" href="' . esc_url($link) . '">' . $name . '</a>';
        } else {
            $html .= $name;
        }
        return $html . '</div>';
    }

    public static function get_initials($name) {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        return $initials;
    }

    public static function get_avatar_html($term, $size = 'thumbnail') {
        $attachment_id = (int) get_term_meta($term->term_id, self::META_AVATAR, true);
        if ($attachment_id > 0) {
            $img = wp_get_attachment_image($attachment_id, $size, false, array(
                'class'    => 'mg-designer-avatar',
                'alt'      => $term->name,
                'loading'  => 'lazy',
                'decoding' => 'async',
            ));
            if ($img) {
                return $img;
            }
        }
        return '<span class="mg-designer-avatar mg-designer-avatar--initials" aria-hidden="true">' . esc_html(self::get_initials($term->name)) . '</span>';
    }

    public static function render_profile_header() {
        if (self::$header_rendered) {
            return;
        }
        $term = get_queried_object();
        if (!$term instanceof WP_Term || $term->taxonomy !== self::TAXONOMY) {
            return;
        }
        self::$header_rendered = true;
        echo self::get_profile_header_html($term); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function get_profile_header_html($term) {
        $tagline = (string) get_term_meta($term->term_id, self::META_TAGLINE, true);
        $location = (string) get_term_meta($term->term_id, self::META_LOCATION, true);
        $since = absint(get_term_meta($term->term_id, self::META_SINCE, true));
        $bio = (string) get_term_meta($term->term_id, self::META_BIO, true);

        $html = '';
        if (!self::is_display_enabled()) {
            $html .= '<p class="mg-designer-preview-note">' . esc_html__('Előnézet: a grafikus profilok ki vannak kapcsolva, a látogatók ezt az oldalt nem látják.', 'mockup-generator') . '</p>';
        }

        $html .= '<div class="mg-designer-profile">';
        $html .= '<div class="mg-designer-profile__avatar">' . self::get_avatar_html($term, 'medium') . '</div>';
        $html .= '<div class="mg-designer-profile__body">';

        $headline = array_filter(array($tagline, $location));
        if ($headline) {
            $html .= '<p class="mg-designer-profile__tagline">' . esc_html(implode(' · ', $headline)) . '</p>';
        }

        $meta = array(sprintf(
            /* translators: %s: number of designs */
            _n('%s minta', '%s minta', (int) $term->count, 'mockup-generator'),
            number_format_i18n((int) $term->count)
        ));
        if ($since > 0) {
            /* translators: %d: year */
            $meta[] = sprintf(__('Velünk %d óta', 'mockup-generator'), $since);
        }
        $html .= '<p class="mg-designer-profile__meta">' . esc_html(implode(' · ', $meta)) . '</p>';

        if ($bio !== '') {
            $html .= '<div class="mg-designer-profile__bio">' . wpautop(esc_html($bio)) . '</div>';
        }

        $page_id = self::get_program_page_id();
        if ($page_id > 0 && get_post_status($page_id) === 'publish') {
            $html .= '<a class="mg-designer-profile__program" href="' . esc_url(get_permalink($page_id)) . '">' . esc_html__('Grafikus vagy? Ismerd meg a programunkat →', 'mockup-generator') . '</a>';
        }

        return $html . '</div></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Programoldal                                                        */
    /* ------------------------------------------------------------------ */

    public static function format_commission($value) {
        $value = round((float) $value, 2);
        return str_replace('.', ',', (string) ($value + 0)) . '%';
    }

    public static function get_program_placeholders($settings = null) {
        $settings = $settings === null ? self::get_settings() : $settings;
        $site = class_exists('MG_SEO_Meta') ? (string) MG_SEO_Meta::get_brand_name() : (string) get_bloginfo('name');
        return array(
            '{jutalek}'   => self::format_commission($settings['program_commission']),
            '{minimum}'   => number_format((int) $settings['program_minimum'], 0, ',', ' ') . ' Ft',
            '{kifizetes}' => (string) $settings['program_payout'],
            '{oldal}'     => $site,
        );
    }

    public static function replace_placeholders($text, $placeholders) {
        return strtr((string) $text, $placeholders);
    }

    /** @return array<int,array{0:string,1:string}> „Kérdés | Válasz” soronként */
    public static function parse_faq($text) {
        $items = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $question = trim($parts[0]);
            $answer = trim($parts[1]);
            if ($question !== '' && $answer !== '') {
                $items[] = array($question, $answer);
            }
        }
        return $items;
    }

    public static function render_program_shortcode() {
        if (!self::can_view_pages()) {
            return '';
        }
        $settings = self::get_settings();
        $vars = self::get_program_placeholders($settings);
        $t = function ($text) use ($vars) {
            return esc_html(self::replace_placeholders($text, $vars));
        };

        $html = '<div class="mg-designer-program">';
        if (!self::is_display_enabled()) {
            $html .= '<p class="mg-designer-preview-note">' . esc_html__('Előnézet: a grafikus profilok ki vannak kapcsolva, a látogatók ezt az oldalt nem látják.', 'mockup-generator') . '</p>';
        }
        if (trim($settings['program_intro']) !== '') {
            $html .= '<div class="mg-designer-program__intro">' . wpautop($t($settings['program_intro'])) . '</div>';
        }

        $steps = array(
            array(__('Te tervezel', 'mockup-generator'), __('Elkészíted a mintát, mi pedig felrakjuk a termékeinkre, a saját profiloldaladon.', 'mockup-generator')),
            array(__('Mi gyártunk és szállítunk', 'mockup-generator'), __('A gyártás, a csomagolás, a kiszállítás és az ügyfélszolgálat a mi dolgunk.', 'mockup-generator')),
            array(__('Jutalékot kapsz', 'mockup-generator'), __('Minden eladott termék után a nettó eladási ár {jutalek}-a a tiéd.', 'mockup-generator')),
        );
        $html .= '<h2>' . esc_html__('Hogyan működik?', 'mockup-generator') . '</h2>';
        $html .= '<ol class="mg-designer-program__steps">';
        foreach ($steps as $step) {
            $html .= '<li><strong>' . $t($step[0]) . '</strong><span>' . $t($step[1]) . '</span></li>';
        }
        $html .= '</ol>';

        $html .= '<h2>' . esc_html__('A program részletei', 'mockup-generator') . '</h2>';
        $html .= '<dl class="mg-designer-program__facts">';
        $html .= '<dt>' . esc_html__('Jutalék', 'mockup-generator') . '</dt><dd>' . $t(__('{jutalek} minden eladott termék nettó eladási ára után', 'mockup-generator')) . '</dd>';
        if (trim($settings['program_payout']) !== '') {
            $html .= '<dt>' . esc_html__('Kifizetés', 'mockup-generator') . '</dt><dd>' . $t(ucfirst($settings['program_payout'])) . '</dd>';
        }
        if ((int) $settings['program_minimum'] > 0) {
            $html .= '<dt>' . esc_html__('Minimum kifizetés', 'mockup-generator') . '</dt><dd>' . $t('{minimum}') . '</dd>';
        }
        $html .= '<dt>' . esc_html__('Profiloldal', 'mockup-generator') . '</dt><dd>' . esc_html__('Saját grafikus profil az összes mintáddal', 'mockup-generator') . '</dd>';
        $html .= '</dl>';

        $faq = self::parse_faq($settings['program_faq']);
        if ($faq) {
            $html .= '<h2>' . esc_html__('Gyakori kérdések', 'mockup-generator') . '</h2>';
            $html .= '<div class="mg-designer-program__faq">';
            foreach ($faq as $item) {
                $html .= '<details><summary>' . $t($item[0]) . '</summary><p>' . $t($item[1]) . '</p></details>';
            }
            $html .= '</div>';
        }

        if (trim($settings['program_extra']) !== '') {
            $html .= '<div class="mg-designer-program__extra">' . wpautop(wp_kses_post(self::replace_placeholders($settings['program_extra'], $vars))) . '</div>';
        }

        return $html . '</div>';
    }

    /* ------------------------------------------------------------------ */
    /* Láthatóság, SEO                                                     */
    /* ------------------------------------------------------------------ */

    /** Kikapcsolva a profiloldalak és a programoldal 404-et adnak a látogatóknak. */
    public static function guard_public_pages() {
        if (self::can_view_pages()) {
            return;
        }
        $page_id = self::get_program_page_id();
        if (is_tax(self::TAXONOMY) || ($page_id > 0 && is_page($page_id))) {
            global $wp_query;
            $wp_query->set_404();
            // Ne irányítson át egy hasonló slugú bejegyzésre.
            add_filter('do_redirect_guess_404_permalink', '__return_false');
            status_header(404);
            nocache_headers();
        }
    }

    public static function filter_robots($robots) {
        if (is_tax(self::TAXONOMY)) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
            unset($robots['index']);
        }
        return $robots;
    }

    public static function filter_sitemap_taxonomies($taxonomies) {
        unset($taxonomies[self::TAXONOMY]);
        return $taxonomies;
    }

    public static function filter_sitemap_posts($args, $post_type) {
        if ($post_type !== 'page') {
            return $args;
        }
        $hidden = self::hidden_page_ids();
        if ($hidden) {
            $args['post__not_in'] = array_merge(isset($args['post__not_in']) ? (array) $args['post__not_in'] : array(), $hidden);
        }
        return $args;
    }
}
