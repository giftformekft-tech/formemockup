<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helyi (raktári) póló készlet nyilvántartás.
 *
 * A készlet sorai a virtuális variáns katalógusból származnak
 * (MG_Variant_Display_Manager::get_catalog_index()), és a
 * típus + szín + méret hármas azonosítja őket. A méretkulcsot minden
 * hívó ezen az osztályon keresztül állítja elő (normalize_size()), így a
 * készlet kulcsa és a nagyker export CSV-jének kulcsa nem tud elcsúszni.
 *
 * A készletmozgás mindig atomi UPDATE-tel történik, mert két párhuzamosan
 * futó nagyker export ugyanazt a darabot nem oszthatja ki kétszer.
 */
class MG_Local_Stock {

    const DB_VERSION_OPTION = 'mg_local_stock_db_version';
    const DB_VERSION = '1.0.0';
    const SETTINGS_OPTION = 'mg_local_stock_settings';

    /** Rendelés tételre írt meta: mennyit fedeztünk eddig helyi készletből. */
    const ITEM_META_TAKEN = '_mg_local_stock_taken';

    /**
     * Régi méretnevek leképezése a nagyker cikkszámban használt alakra.
     * Ez a nagyker exportból került ide, hogy egyetlen forrása legyen.
     */
    private static $size_map = array(
        'xxl'      => '2xl',
        'xxxl'     => '3xl',
        'xxxxl'    => '4xl',
        '2'        => '2a',
        '4'        => '4a',
        '6'        => '6a',
        '8'        => '8a',
        '10'       => '10a',
        '12'       => '12a',
        '3-6-ho'   => '3/6m',
        '6-12-ho'  => '6/12m',
        '12-18-ho' => '12/18m',
    );

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'maybe_install'));
        // Sztornó / visszatérítés esetén a helyi készlet visszakerül a polcra.
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'release_order'), 10, 1);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'release_order'), 10, 1);
    }

    /* ---------------------------------------------------------------- tables */

    public static function stock_table() {
        global $wpdb;
        return $wpdb->prefix . 'mg_local_stock';
    }

    public static function log_table() {
        global $wpdb;
        return $wpdb->prefix . 'mg_local_stock_log';
    }

    /** Létrehozza vagy frissíti a táblákat, ha a séma verziója változott. */
    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        self::install();
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $stock = self::stock_table();
        $log = self::log_table();

        dbDelta(
            "CREATE TABLE {$stock} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                type_slug VARCHAR(64) NOT NULL DEFAULT '',
                color_slug VARCHAR(64) NOT NULL DEFAULT '',
                size_key VARCHAR(32) NOT NULL DEFAULT '',
                utt_sku VARCHAR(64) NOT NULL DEFAULT '',
                qty INT(11) NOT NULL DEFAULT 0,
                safety INT(11) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (id),
                UNIQUE KEY variant (type_slug, color_slug, size_key),
                KEY utt_sku (utt_sku)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$log} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                stock_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                type_slug VARCHAR(64) NOT NULL DEFAULT '',
                color_slug VARCHAR(64) NOT NULL DEFAULT '',
                size_key VARCHAR(32) NOT NULL DEFAULT '',
                delta INT(11) NOT NULL DEFAULT 0,
                qty_after INT(11) NOT NULL DEFAULT 0,
                reason VARCHAR(20) NOT NULL DEFAULT '',
                order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                order_item_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (id),
                KEY stock_id (stock_id),
                KEY order_id (order_id),
                KEY created_at (created_at)
            ) {$charset};"
        );

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /* -------------------------------------------------------------- settings */

    public static function get_settings() {
        $saved = get_option(self::SETTINGS_OPTION, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, array(
            'enabled' => 'yes',
            'preview' => 'yes',
        ));
    }

    public static function save_settings($settings) {
        $clean = array(
            'enabled' => (isset($settings['enabled']) && $settings['enabled'] === 'yes') ? 'yes' : 'no',
            'preview' => (isset($settings['preview']) && $settings['preview'] === 'yes') ? 'yes' : 'no',
        );
        update_option(self::SETTINGS_OPTION, $clean);
        return $clean;
    }

    /** Levonja-e egyáltalán a nagyker export a helyi készletet. */
    public static function is_enabled() {
        $settings = self::get_settings();
        return $settings['enabled'] === 'yes';
    }

    /** Kell-e megerősítő előnézet a CSV letöltése előtt. */
    public static function preview_required() {
        $settings = self::get_settings();
        return $settings['preview'] === 'yes';
    }

    /* ------------------------------------------------------------ normalizing */

    /**
     * Egységes méretkulcs. Ugyanazt az alakot adja vissza, amit a nagyker
     * cikkszám végére fűzünk, így a katalógus címkéje ('2XL', 'XXL') és a
     * rendelés tétel metája ugyanabba a rekeszbe esik.
     *
     * @param string $raw
     * @return string
     */
    public static function normalize_size($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        // A metódusnak idempotensnek kell lennie: a hívók egy része már
        // normalizált kulcsot ad át (pl. a mátrix oszlopai). A gyerekméretek
        // perjelét a sanitize_title levágná, ezért a kész alakot érintetlenül
        // hagyjuk.
        $lower = strtolower($raw);
        if (in_array($lower, self::$size_map, true)) {
            return $lower;
        }

        $size = sanitize_title($raw);
        if ($size === '') {
            return '';
        }
        return isset(self::$size_map[$size]) ? self::$size_map[$size] : $size;
    }

    /**
     * A típus + szín párhoz tartozó nagyker (UTT) alap cikkszám.
     *
     * @param string $type_slug
     * @param string $color_slug
     * @return string
     */
    public static function utt_sku($type_slug, $color_slug) {
        $lookup = self::product_lookup();
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if (!isset($lookup[$type_slug]['utt_skus'][$color_slug])) {
            return '';
        }
        return trim((string) $lookup[$type_slug]['utt_skus'][$color_slug]);
    }

    /**
     * A mg_products bejegyzések kulcs szerint indexelve (UTT cikkszámokhoz).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function product_lookup() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $products = function_exists('mg_get_catalog_products')
            ? mg_get_catalog_products()
            : get_option('mg_products', array());

        $cache = array();
        foreach ((array) $products as $product) {
            if (is_array($product) && !empty($product['key'])) {
                $cache[sanitize_title($product['key'])] = $product;
            }
        }
        return $cache;
    }

    /* --------------------------------------------------------------- catalog */

    /**
     * A virtuális variáns katalógus terméktípusai (slug => címke).
     *
     * @return array<string,string>
     */
    public static function get_types() {
        $types = array();
        if (!class_exists('MG_Variant_Display_Manager')) {
            return $types;
        }
        foreach ((array) MG_Variant_Display_Manager::get_catalog_index() as $slug => $meta) {
            $slug = sanitize_title($slug);
            if ($slug === '' || !is_array($meta)) {
                continue;
            }
            $types[$slug] = !empty($meta['label']) ? wp_strip_all_tags($meta['label']) : $slug;
        }
        return $types;
    }

    /**
     * Egy terméktípus kitölthető mátrixa: szín sorok × méret oszlopok.
     *
     * A size_color_matrix alapján megjelöljük, mely cellákhoz létezik
     * egyáltalán variáns – a többi nem tölthető ki.
     *
     * @param string $type_slug
     * @return array{label:string,sizes:array<int,array{key:string,label:string}>,colors:array<int,array<string,mixed>>,allowed:array<string,array<string,bool>>}|null
     */
    public static function get_type_matrix($type_slug) {
        if (!class_exists('MG_Variant_Display_Manager')) {
            return null;
        }
        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        $type_slug = sanitize_title($type_slug);
        if (!isset($catalog[$type_slug]) || !is_array($catalog[$type_slug])) {
            return null;
        }
        $meta = $catalog[$type_slug];

        // Méret oszlopok normalizált kulcs szerint deduplikálva: 'XXL' és
        // '2XL' fizikailag ugyanaz a méret, egy oszlopot kap.
        $sizes = array();
        $seen = array();
        foreach ((array) ($meta['sizes'] ?? array()) as $size_label) {
            $key = self::normalize_size($size_label);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sizes[] = array(
                'key'   => $key,
                'label' => sanitize_text_field($size_label),
            );
        }

        $colors = array();
        foreach ((array) ($meta['colors'] ?? array()) as $color_slug => $color_meta) {
            $color_slug = sanitize_title($color_slug);
            if ($color_slug === '') {
                continue;
            }
            $colors[] = array(
                'slug'    => $color_slug,
                'label'   => is_array($color_meta) && !empty($color_meta['label']) ? wp_strip_all_tags($color_meta['label']) : $color_slug,
                'hex'     => is_array($color_meta) && !empty($color_meta['hex']) ? $color_meta['hex'] : '',
                'utt_sku' => self::utt_sku($type_slug, $color_slug),
            );
        }

        $matrix = isset($meta['matrix']) && is_array($meta['matrix']) ? $meta['matrix'] : array();
        $allowed = array();
        foreach ($colors as $color) {
            foreach ($sizes as $size) {
                $allowed[$color['slug']][$size['key']] = self::is_variant_allowed($matrix, $size['key'], $color['slug']);
            }
        }

        return array(
            'label'   => !empty($meta['label']) ? wp_strip_all_tags($meta['label']) : $type_slug,
            'sizes'   => $sizes,
            'colors'  => $colors,
            'allowed' => $allowed,
        );
    }

    /**
     * A size_color_matrix méret címkékkel van kulcsolva, a készlet viszont
     * normalizált méretkulccsal, ezért címkénként normalizálva keresünk.
     *
     * @param array  $matrix
     * @param string $size_key
     * @param string $color_slug
     * @return bool
     */
    private static function is_variant_allowed($matrix, $size_key, $color_slug) {
        if (empty($matrix)) {
            return true;
        }
        $known = false;
        foreach ($matrix as $size_label => $color_list) {
            if (self::normalize_size($size_label) !== $size_key) {
                continue;
            }
            $known = true;
            if (is_array($color_list) && in_array($color_slug, array_map('sanitize_title', $color_list), true)) {
                return true;
            }
        }
        // Ha a mátrix egyáltalán nem rendelkezik erről a méretről, engedjük:
        // a katalógus méretlistája a mérvadó.
        return !$known;
    }

    /* ----------------------------------------------------------------- reads */

    /**
     * Készletszintek egy terméktípusra: [szín][méretkulcs] => sor.
     *
     * @param string $type_slug
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function get_levels_for_type($type_slug) {
        global $wpdb;

        $type_slug = sanitize_title($type_slug);
        $levels = array();
        if ($type_slug === '') {
            return $levels;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::stock_table() . ' WHERE type_slug = %s', $type_slug),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $levels[$row['color_slug']][$row['size_key']] = array(
                'id'     => (int) $row['id'],
                'qty'    => (int) $row['qty'],
                'safety' => (int) $row['safety'],
            );
        }
        return $levels;
    }

    /**
     * Egy variáns aktuális készlete.
     *
     * @return array{id:int,qty:int,safety:int}|null
     */
    public static function get_row($type_slug, $color_slug, $size_key) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, qty, safety FROM ' . self::stock_table() . ' WHERE type_slug = %s AND color_slug = %s AND size_key = %s',
                sanitize_title($type_slug),
                sanitize_title($color_slug),
                self::normalize_size($size_key)
            ),
            ARRAY_A
        );
        if (!$row) {
            return null;
        }
        return array(
            'id'     => (int) $row['id'],
            'qty'    => (int) $row['qty'],
            'safety' => (int) $row['safety'],
        );
    }

    /**
     * Ténylegesen kiadható mennyiség (a biztonsági készlet felett).
     *
     * @return int
     */
    public static function available($type_slug, $color_slug, $size_key) {
        $row = self::get_row($type_slug, $color_slug, $size_key);
        if (!$row) {
            return 0;
        }
        return max(0, $row['qty'] - $row['safety']);
    }

    /**
     * Minden készleten lévő sor, opcionálisan csak a hiányzók.
     *
     * @param array $args low_only, type_slug
     * @return array<int,array<string,mixed>>
     */
    public static function get_all_rows($args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'low_only'  => false,
            'type_slug' => '',
        ));

        $sql = 'SELECT * FROM ' . self::stock_table() . ' WHERE 1=1';
        $params = array();

        $type_slug = sanitize_title($args['type_slug']);
        if ($type_slug !== '') {
            $sql .= ' AND type_slug = %s';
            $params[] = $type_slug;
        }
        if (!empty($args['low_only'])) {
            $sql .= ' AND qty <= safety';
        }
        $sql .= ' ORDER BY type_slug ASC, color_slug ASC, size_key ASC';

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /* ---------------------------------------------------------------- writes */

    /**
     * Abszolút készletérték beállítása (kézi szerkesztés a mátrixban).
     * A különbséget naplózzuk, nem a végállapotot.
     *
     * @return bool Történt-e változás.
     */
    public static function set_cell($type_slug, $color_slug, $size_key, $qty, $safety = null, $reason = 'manual', $note = '') {
        global $wpdb;

        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        $size_key = self::normalize_size($size_key);
        if ($type_slug === '' || $color_slug === '' || $size_key === '') {
            return false;
        }

        $qty = max(0, (int) $qty);
        $existing = self::get_row($type_slug, $color_slug, $size_key);
        $current_qty = $existing ? $existing['qty'] : 0;
        $current_safety = $existing ? $existing['safety'] : 0;
        $safety = ($safety === null) ? $current_safety : max(0, (int) $safety);

        if ($existing && $current_qty === $qty && $current_safety === $safety) {
            return false;
        }

        $now = current_time('mysql');
        $data = array(
            'type_slug'  => $type_slug,
            'color_slug' => $color_slug,
            'size_key'   => $size_key,
            'utt_sku'    => self::utt_sku($type_slug, $color_slug),
            'qty'        => $qty,
            'safety'     => $safety,
            'updated_at' => $now,
        );
        $format = array('%s', '%s', '%s', '%s', '%d', '%d', '%s');

        if ($existing) {
            $wpdb->update(self::stock_table(), $data, array('id' => $existing['id']), $format, array('%d'));
            $stock_id = $existing['id'];
        } else {
            $wpdb->insert(self::stock_table(), $data, $format);
            $stock_id = (int) $wpdb->insert_id;
        }

        if ($qty !== $current_qty) {
            self::log($stock_id, $type_slug, $color_slug, $size_key, $qty - $current_qty, $qty, $reason, array('note' => $note));
        }
        return true;
    }

    /**
     * Készlet növelése (bevételezés, korrekció, visszaírás).
     *
     * @return int Az új készletszint.
     */
    public static function add($type_slug, $color_slug, $size_key, $delta, $reason = 'goods_in', $args = array()) {
        global $wpdb;

        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        $size_key = self::normalize_size($size_key);
        $delta = (int) $delta;
        if ($type_slug === '' || $color_slug === '' || $size_key === '' || $delta === 0) {
            return 0;
        }

        $existing = self::get_row($type_slug, $color_slug, $size_key);
        if (!$existing) {
            self::set_cell($type_slug, $color_slug, $size_key, max(0, $delta), null, $reason, isset($args['note']) ? $args['note'] : '');
            return max(0, $delta);
        }

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::stock_table() . ' SET qty = GREATEST(0, qty + %d), updated_at = %s WHERE id = %d',
                $delta,
                current_time('mysql'),
                $existing['id']
            )
        );

        $after = self::get_row($type_slug, $color_slug, $size_key);
        $qty_after = $after ? $after['qty'] : 0;
        self::log($existing['id'], $type_slug, $color_slug, $size_key, $qty_after - $existing['qty'], $qty_after, $reason, $args);
        return $qty_after;
    }

    /**
     * Atomi kivétel a készletből, a biztonsági szint felett.
     *
     * Az UPDATE feltétele tartalmazza az elvárt mennyiséget, így két
     * párhuzamos export nem tudja ugyanazt a darabot kétszer kiadni: a
     * vesztes tranzakció 0 érintett sort kap és újraolvassa a szintet.
     *
     * @return int Ténylegesen kivett darabszám (0..$qty).
     */
    public static function take($type_slug, $color_slug, $size_key, $qty, $args = array()) {
        global $wpdb;

        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        $size_key = self::normalize_size($size_key);
        $qty = (int) $qty;
        if ($type_slug === '' || $color_slug === '' || $size_key === '' || $qty <= 0) {
            return 0;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = self::get_row($type_slug, $color_slug, $size_key);
            if (!$row) {
                return 0;
            }
            $take = min($qty, max(0, $row['qty'] - $row['safety']));
            if ($take <= 0) {
                return 0;
            }

            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . self::stock_table() . ' SET qty = qty - %d, updated_at = %s WHERE id = %d AND (qty - safety) >= %d',
                    $take,
                    current_time('mysql'),
                    $row['id'],
                    $take
                )
            );

            if ($updated) {
                self::log(
                    $row['id'],
                    $type_slug,
                    $color_slug,
                    $size_key,
                    -$take,
                    $row['qty'] - $take,
                    isset($args['reason']) ? $args['reason'] : 'order',
                    $args
                );
                return $take;
            }
            // Közben más módosította a sort – újraolvassuk és próbáljuk újra.
        }

        return 0;
    }

    /**
     * A rendeléshez levont helyi készlet visszaírása (sztornó, refund).
     *
     * @param int $order_id
     * @return int Visszaírt darabszám.
     */
    public static function release_order($order_id) {
        $order_id = absint($order_id);
        if (!$order_id || !function_exists('wc_get_order')) {
            return 0;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return 0;
        }

        $restored = 0;
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $taken = (int) $item->get_meta(self::ITEM_META_TAKEN);
            if ($taken <= 0) {
                continue;
            }
            $variant = self::describe_item($item);
            if ($variant['type'] === '' || $variant['color'] === '' || $variant['size'] === '') {
                continue;
            }
            self::add($variant['type'], $variant['color'], $variant['size'], $taken, 'rollback', array(
                'order_id'      => $order_id,
                'order_item_id' => $item_id,
                'note'          => 'Rendelés visszavonva',
            ));
            $item->update_meta_data(self::ITEM_META_TAKEN, 0);
            $item->save_meta_data();
            $restored += $taken;
        }

        return $restored;
    }

    /* ------------------------------------------------------------- item read */

    /**
     * Kiolvassa egy rendelés tételből a típus / szín / méret hármast.
     *
     * Ugyanaz a sorrend, amit a nagyker export használ: először a tétel
     * metaadatai, utána a variáció vagy a termék attribútumai.
     *
     * @param WC_Order_Item_Product $item
     * @return array{type:string,color:string,size:string}
     */
    public static function describe_item($item) {
        $product_type = $item->get_meta('mg_product_type') ?: $item->get_meta('product_type') ?: $item->get_meta('termektipus') ?: $item->get_meta('pa_termektipus');
        $color_slug = $item->get_meta('mg_color') ?: $item->get_meta('color') ?: $item->get_meta('pa_szin') ?: $item->get_meta('szin');
        $size_val = $item->get_meta('mg_size') ?: $item->get_meta('size') ?: $item->get_meta('pa_meret') ?: $item->get_meta('meret');

        if (empty($product_type) || empty($color_slug) || empty($size_val)) {
            $variation_id = $item->get_variation_id();
            $wc_product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($item->get_product_id());
            if ($wc_product) {
                $attributes = $wc_product->get_attributes();
                if (empty($product_type)) {
                    $product_type = $attributes['pa_termektipus'] ?? ($attributes['pa_product_type'] ?? '');
                }
                if (empty($color_slug)) {
                    $color_slug = $attributes['pa_szin'] ?? ($attributes['pa_color'] ?? '');
                }
                if (empty($size_val)) {
                    $size_val = $attributes['pa_meret'] ?? ($attributes['pa_size'] ?? ($attributes['meret'] ?? ''));
                }
            }
        }

        return array(
            'type'  => sanitize_title($product_type),
            'color' => sanitize_title($color_slug),
            'size'  => self::normalize_size($size_val),
        );
    }

    /* ------------------------------------------------------------------- log */

    private static function log($stock_id, $type_slug, $color_slug, $size_key, $delta, $qty_after, $reason, $args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'order_id'      => 0,
            'order_item_id' => 0,
            'note'          => '',
        ));

        $wpdb->insert(
            self::log_table(),
            array(
                'stock_id'      => (int) $stock_id,
                'type_slug'     => $type_slug,
                'color_slug'    => $color_slug,
                'size_key'      => $size_key,
                'delta'         => (int) $delta,
                'qty_after'     => (int) $qty_after,
                'reason'        => substr(sanitize_key($reason), 0, 20),
                'order_id'      => absint($args['order_id']),
                'order_item_id' => absint($args['order_item_id']),
                'user_id'       => get_current_user_id(),
                'note'          => substr(sanitize_text_field($args['note']), 0, 255),
                'created_at'    => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s')
        );
    }

    /**
     * Mozgásnapló bejegyzések.
     *
     * @param array $args limit, offset, type_slug, order_id, reason
     * @return array<int,array<string,mixed>>
     */
    public static function get_log($args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'limit'     => 100,
            'offset'    => 0,
            'type_slug' => '',
            'order_id'  => 0,
            'reason'    => '',
        ));

        $sql = 'SELECT * FROM ' . self::log_table() . ' WHERE 1=1';
        $params = array();

        $type_slug = sanitize_title($args['type_slug']);
        if ($type_slug !== '') {
            $sql .= ' AND type_slug = %s';
            $params[] = $type_slug;
        }
        if (absint($args['order_id']) > 0) {
            $sql .= ' AND order_id = %d';
            $params[] = absint($args['order_id']);
        }
        $reason = sanitize_key($args['reason']);
        if ($reason !== '') {
            $sql .= ' AND reason = %s';
            $params[] = $reason;
        }

        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = max(1, min(500, (int) $args['limit']));
        $params[] = max(0, (int) $args['offset']);

        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function count_log($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('type_slug' => '', 'order_id' => 0, 'reason' => ''));

        $sql = 'SELECT COUNT(*) FROM ' . self::log_table() . ' WHERE 1=1';
        $params = array();

        $type_slug = sanitize_title($args['type_slug']);
        if ($type_slug !== '') {
            $sql .= ' AND type_slug = %s';
            $params[] = $type_slug;
        }
        if (absint($args['order_id']) > 0) {
            $sql .= ' AND order_id = %d';
            $params[] = absint($args['order_id']);
        }
        $reason = sanitize_key($args['reason']);
        if ($reason !== '') {
            $sql .= ' AND reason = %s';
            $params[] = $reason;
        }

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Emberi olvasásra szánt mozgás-ok.
     *
     * @return array<string,string>
     */
    public static function reason_labels() {
        return array(
            'manual'   => __('Kézi szerkesztés', 'mockup-generator'),
            'order'    => __('Rendelés levonás', 'mockup-generator'),
            'goods_in' => __('Bevételezés', 'mockup-generator'),
            'rollback' => __('Visszaírás', 'mockup-generator'),
            'import'   => __('Import', 'mockup-generator'),
        );
    }

    /* --------------------------------------------------------------- goods in */

    /**
     * Bevételezés a nagyker CSV formátumából: soronként `cikkszám,darab`.
     * A cikkszám a `UTT-alapcikkszám + '-' + méret` alak, azaz pontosan az,
     * amit a nagyker export kiír – így a megérkezett szállítmány egy
     * másolás-beillesztéssel visszakerül a készletbe.
     *
     * @param string $csv
     * @param bool   $commit
     * @return array{rows:array<int,array<string,mixed>>,unknown:array<int,string>,total:int}
     */
    public static function parse_goods_in($csv, $commit = false) {
        $index = self::sku_index();
        $rows = array();
        $unknown = array();
        $total = 0;

        $lines = preg_split('/\r\n|\r|\n/', (string) $csv);
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line, strpos($line, ';') !== false ? ';' : ',');
            if (count($parts) < 2) {
                $unknown[] = $line;
                continue;
            }
            $sku = trim((string) $parts[0]);
            $qty = (int) trim((string) $parts[1]);
            if ($sku === '' || $qty === 0) {
                $unknown[] = $line;
                continue;
            }
            $key = strtolower($sku);
            if (!isset($index[$key])) {
                $unknown[] = $line;
                continue;
            }

            $variant = $index[$key];
            $rows[] = array(
                'sku'   => $sku,
                'type'  => $variant['type'],
                'color' => $variant['color'],
                'size'  => $variant['size'],
                'qty'   => $qty,
            );
            $total += $qty;

            if ($commit) {
                self::add($variant['type'], $variant['color'], $variant['size'], $qty, 'goods_in', array(
                    'note' => 'CSV bevételezés: ' . $sku,
                ));
            }
        }

        return array('rows' => $rows, 'unknown' => $unknown, 'total' => $total);
    }

    /**
     * Nagyker cikkszám → variáns index a bevételezéshez.
     * Ha ugyanaz a cikkszám több típushoz tartozik, az elsőt használjuk és
     * a hívó oldalon ez a bevételezés listájában látszik.
     *
     * @return array<string,array{type:string,color:string,size:string}>
     */
    public static function sku_index() {
        $index = array();
        foreach (self::get_types() as $type_slug => $label) {
            $matrix = self::get_type_matrix($type_slug);
            if (!$matrix) {
                continue;
            }
            foreach ($matrix['colors'] as $color) {
                if ($color['utt_sku'] === '') {
                    continue;
                }
                foreach ($matrix['sizes'] as $size) {
                    if (empty($matrix['allowed'][$color['slug']][$size['key']])) {
                        continue;
                    }
                    $sku = strtolower($color['utt_sku'] . '-' . $size['key']);
                    if (isset($index[$sku])) {
                        continue;
                    }
                    $index[$sku] = array(
                        'type'  => $type_slug,
                        'color' => $color['slug'],
                        'size'  => $size['key'],
                    );
                }
            }
        }
        return $index;
    }
}
