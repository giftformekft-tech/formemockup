<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Category_Popularity_Pins
 *
 * Lehetővé teszi, hogy "Népszerűség szerint" rendezésnél egyes termékek
 * mindig a kategórialista elejére kerüljenek, a manuálisan megadott sorrendben.
 *
 * Tárolás: term meta kulcs "mg_popularity_pins" → product ID-k tömbje.
 * Frontend: posts_clauses hook – CASE WHEN + FIELD() az ORDER BY-ban.
 */
class MG_Category_Popularity_Pins {

    const META_KEY  = 'mg_popularity_pins';
    const NONCE_KEY = 'mg_pop_pins_nonce';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init() {
        // Admin UI – kategória szerkesztő old. (meglévő kategóriánál)
        add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit_fields' ), 20 );
        add_action( 'edited_product_cat',           array( __CLASS__, 'save_term_meta' ), 10, 2 );

        // Admin UI – új kategória létrehozásakor (kevésbé fontos, de teljesebb)
        add_action( 'product_cat_add_form_fields',  array( __CLASS__, 'render_add_fields' ), 20 );
        add_action( 'created_product_cat',          array( __CLASS__, 'save_term_meta' ), 10, 2 );

        // Admin: scriptek és stílusok betöltése
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

        // Frontend: ORDER BY módosítása
        add_filter( 'posts_clauses', array( __CLASS__, 'modify_orderby' ), 20, 2 );
    }

    // -------------------------------------------------------------------------
    // Admin assets
    // -------------------------------------------------------------------------

    public static function enqueue_admin_assets( $hook ) {
        // Csak a taxonómia szerkesztő oldalakon töltjük be
        if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->taxonomy !== 'product_cat' ) {
            return;
        }

        // jQuery UI Sortable (WP-vel együtt jön)
        wp_enqueue_script( 'jquery-ui-sortable' );

        // Kis inline script + style – nem érdemes külön fájlba tenni
        $nonce = wp_create_nonce( 'mg_search_nonce' );
        wp_add_inline_script(
            'jquery-ui-sortable',
            self::get_inline_js( admin_url( 'admin-ajax.php' ), $nonce ),
            'after'
        );
        wp_add_inline_style( 'wp-admin', self::get_inline_css() );
    }

    // -------------------------------------------------------------------------
    // Admin UI – mezők renderelése
    // -------------------------------------------------------------------------

    /**
     * Meglévő kategória szerkesztő oldalán.
     *
     * @param WP_Term $term
     */
    public static function render_edit_fields( $term ) {
        $pins = self::get_pins( $term->term_id );
        wp_nonce_field( 'mg_save_pop_pins_' . $term->term_id, self::NONCE_KEY );
        ?>
        <tr class="form-field mg-pop-pins-row">
            <th scope="row">
                <label><?php esc_html_e( '⭐ Kiemelt termékek', 'mockup-generator' ); ?></label>
            </th>
            <td>
                <?php self::render_widget( $pins ); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Új kategória létrehozása formban.
     */
    public static function render_add_fields() {
        wp_nonce_field( 'mg_save_pop_pins_new', self::NONCE_KEY );
        ?>
        <div class="form-field mg-pop-pins-row">
            <label><?php esc_html_e( '⭐ Kiemelt termékek', 'mockup-generator' ); ?></label>
            <?php self::render_widget( array() ); ?>
        </div>
        <?php
    }

    /**
     * Maga a widget HTML (keresőmező + drag-drop lista).
     *
     * @param array $pins  [ ['id'=>int,'title'=>string], ... ]
     */
    private static function render_widget( array $pins ) {
        ?>
        <div class="mg-pop-pins-widget" id="mg-pop-pins-widget">
            <div class="mg-pop-pins-search-row">
                <input
                    type="text"
                    id="mg-pop-pins-search"
                    class="regular-text"
                    placeholder="<?php esc_attr_e( 'Termék keresése…', 'mockup-generator' ); ?>"
                    autocomplete="off"
                />
                <div id="mg-pop-pins-suggestions" class="mg-pop-pins-suggestions" style="display:none;"></div>
            </div>

            <ul id="mg-pop-pins-list" class="mg-pop-pins-list">
                <?php foreach ( $pins as $pin ) : ?>
                    <li class="mg-pop-pin-item" data-id="<?php echo esc_attr( $pin['id'] ); ?>">
                        <span class="mg-pop-pin-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Húzd a sorrend módosításához', 'mockup-generator' ); ?>"></span>
                        <span class="mg-pop-pin-title"><?php echo esc_html( $pin['title'] ); ?></span>
                        <input type="hidden" name="mg_popularity_pins[]" value="<?php echo esc_attr( $pin['id'] ); ?>" />
                        <button type="button" class="mg-pop-pin-remove button-link" aria-label="<?php esc_attr_e( 'Eltávolítás', 'mockup-generator' ); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="description">
                <?php esc_html_e( '„Népszerűség szerint" rendezésnél ezek a termékek mindig a lista elejére kerülnek (a megadott sorrendben), a többi termék mögött az eladási szám dönt.', 'mockup-generator' ); ?>
            </p>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Mentés
    // -------------------------------------------------------------------------

    /**
     * @param int $term_id
     */
    public static function save_term_meta( $term_id ) {
        // Nonce ellenőrzés
        $nonce_value = isset( $_POST[ self::NONCE_KEY ] ) ? wp_unslash( $_POST[ self::NONCE_KEY ] ) : '';
        $nonce_action = 'mg_save_pop_pins_' . $term_id;
        // Új kategóriánál más action-t is elfogadunk
        if (
            ! wp_verify_nonce( $nonce_value, $nonce_action ) &&
            ! wp_verify_nonce( $nonce_value, 'mg_save_pop_pins_new' )
        ) {
            return;
        }

        if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        $raw_ids = isset( $_POST['mg_popularity_pins'] ) ? (array) wp_unslash( $_POST['mg_popularity_pins'] ) : array();
        $ids     = array();
        foreach ( $raw_ids as $id ) {
            $clean = absint( $id );
            if ( $clean > 0 && ! in_array( $clean, $ids, true ) ) {
                $ids[] = $clean;
            }
        }

        if ( empty( $ids ) ) {
            delete_term_meta( $term_id, self::META_KEY );
        } else {
            update_term_meta( $term_id, self::META_KEY, $ids );
        }
    }

    // -------------------------------------------------------------------------
    // Adat lekérés
    // -------------------------------------------------------------------------

    /**
     * Visszaadja a kitűzött termékeket ID + cím párokban.
     *
     * @param  int   $term_id
     * @return array [ ['id'=>int, 'title'=>string], ... ]
     */
    public static function get_pins( $term_id ) {
        $ids = get_term_meta( $term_id, self::META_KEY, true );
        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return array();
        }
        $result = array();
        foreach ( $ids as $id ) {
            $id    = absint( $id );
            $title = get_the_title( $id );
            if ( $title ) {
                $result[] = array( 'id' => $id, 'title' => $title );
            }
        }
        return $result;
    }

    /**
     * Visszaadja csak az ID tömböt (gyors frontend lekéréshez).
     *
     * @param  int   $term_id
     * @return int[]
     */
    public static function get_pin_ids( $term_id ) {
        $ids = get_term_meta( $term_id, self::META_KEY, true );
        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return array();
        }
        return array_map( 'absint', $ids );
    }

    // -------------------------------------------------------------------------
    // Frontend – ORDER BY módosítás
    // -------------------------------------------------------------------------

    /**
     * posts_clauses filter – csak akkor módosít, ha:
     *  1. Termék kategória archív oldal
     *  2. Aktív rendezés: popularity (total_sales)
     *  3. Van legalább 1 kitűzött termék a kategóriában
     *
     * @param  array    $clauses
     * @param  WP_Query $wp_query
     * @return array
     */
    public static function modify_orderby( $clauses, $wp_query ) {
        if ( is_admin() ) {
            return $clauses;
        }

        if ( ! $wp_query->is_main_query() ) {
            return $clauses;
        }

        if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
            return $clauses;
        }

        // Csak popularity rendezésnél
        if ( ! self::is_popularity_ordering() ) {
            return $clauses;
        }

        // Aktuális kategória term ID-ja
        $term = get_queried_object();
        if ( ! $term || ! isset( $term->term_id ) ) {
            return $clauses;
        }

        $pin_ids = self::get_pin_ids( $term->term_id );
        if ( empty( $pin_ids ) ) {
            return $clauses;
        }

        global $wpdb;

        // Biztonságos, kizárólag egész számok
        $safe_ids    = implode( ',', $pin_ids );
        $field_expr  = 'FIELD(' . $wpdb->posts . '.ID, ' . $safe_ids . ')';
        $case_expr   = 'CASE WHEN ' . $wpdb->posts . '.ID IN (' . $safe_ids . ') THEN 0 ELSE 1 END';

        // Az eredeti ORDER BY-t megtartjuk (total_sales DESC) – de a pinelt ID-k előre mennek
        $original_orderby = $clauses['orderby'];
        $clauses['orderby'] = $case_expr . ' ASC, ' . $field_expr . ' ASC, ' . $original_orderby;

        return $clauses;
    }

    /**
     * Meghatározza, hogy jelenleg "popularity" rendezés aktív-e.
     * WooCommerce saját session/cookie/default logikáját követi.
     *
     * @return bool
     */
    private static function is_popularity_ordering() {
        // WooCommerce az aktív rendezést a woocommerce_get_catalog_ordering_args filteren
        // keresztül alkalmazza; a legmegbízhatóbb hogy a globális változóból olvassuk
        // vagy az URL query stringből.

        // 1. URL paraméter (legmagasabb prioritás)
        if ( isset( $_GET['orderby'] ) ) {
            return sanitize_key( wp_unslash( $_GET['orderby'] ) ) === 'popularity';
        }

        // 2. WooCommerce session (ha elérhető)
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_order = WC()->session->get( 'woocommerce_sort_by' );
            if ( $session_order ) {
                return $session_order === 'popularity';
            }
        }

        // 3. Az alapértelmezett rendezési mód
        $default = get_option( 'woocommerce_default_catalog_orderby', 'menu_order' );
        return $default === 'popularity';
    }

    // -------------------------------------------------------------------------
    // Inline JS
    // -------------------------------------------------------------------------

    private static function get_inline_js( $ajax_url, $nonce ) {
        return <<<JS
(function($){
    'use strict';

    var \$widget   = $('#mg-pop-pins-widget');
    var \$input    = $('#mg-pop-pins-search');
    var \$suggest  = $('#mg-pop-pins-suggestions');
    var \$list     = $('#mg-pop-pins-list');
    var searchTimer;

    if ( !\$widget.length ) return;

    // Drag & drop sorrend
    \$list.sortable({
        handle: '.mg-pop-pin-handle',
        axis: 'y',
        placeholder: 'mg-pop-pin-placeholder',
        tolerance: 'pointer'
    });

    // Termék eltávolítása
    \$list.on('click', '.mg-pop-pin-remove', function(){
        $(this).closest('.mg-pop-pin-item').remove();
    });

    // Keresés (debounce 300 ms)
    \$input.on('input', function(){
        clearTimeout(searchTimer);
        var q = $.trim(\$input.val());
        if ( q.length < 2 ) { \$suggest.hide().empty(); return; }
        searchTimer = setTimeout(function(){
            $.getJSON(
                '{$ajax_url}',
                { action: 'mg_search_products', q: q, nonce: '{$nonce}' },
                function(resp){
                    \$suggest.empty();
                    if ( !resp.success || !resp.data.length ) {
                        \$suggest.append('<div class="mg-pop-pins-no-result">Nincs találat</div>').show();
                        return;
                    }
                    resp.data.forEach(function(item){
                        // Ne adjuk hozzá, ha már a listában van
                        if ( \$list.find('[data-id="'+item.id+'"]').length ) return;
                        var \$row = $('<div class="mg-pop-pins-suggestion-item"/>')
                            .text(item.title)
                            .data('id', item.id);
                        \$suggest.append(\$row);
                    });
                    \$suggest.show();
                }
            );
        }, 300);
    });

    // Találatra kattintás → hozzáadás a listához
    \$suggest.on('click', '.mg-pop-pins-suggestion-item', function(){
        var id    = $(this).data('id');
        var title = $(this).text();
        \$list.append(
            '<li class="mg-pop-pin-item" data-id="'+id+'">' +
                '<span class="mg-pop-pin-handle dashicons dashicons-menu"></span>' +
                '<span class="mg-pop-pin-title">'+title+'</span>' +
                '<input type="hidden" name="mg_popularity_pins[]" value="'+id+'" />' +
                '<button type="button" class="mg-pop-pin-remove button-link">' +
                    '<span class="dashicons dashicons-no-alt"></span>' +
                '</button>' +
            '</li>'
        );
        \$input.val('');
        \$suggest.hide().empty();
    });

    // Kattintás a widgeten kívülre → bezárja a javaslatlistát
    $(document).on('click', function(e){
        if ( !$(e.target).closest('#mg-pop-pins-widget').length ) {
            \$suggest.hide();
        }
    });

})(jQuery);
JS;
    }

    // -------------------------------------------------------------------------
    // Inline CSS
    // -------------------------------------------------------------------------

    private static function get_inline_css() {
        return '
        .mg-pop-pins-widget { max-width: 480px; }
        .mg-pop-pins-search-row { position: relative; margin-bottom: 8px; }
        .mg-pop-pins-suggestions {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 9999;
            background: #fff; border: 1px solid #c3c4c7; border-top: none;
            max-height: 200px; overflow-y: auto; border-radius: 0 0 4px 4px;
            box-shadow: 0 3px 8px rgba(0,0,0,.12);
        }
        .mg-pop-pins-suggestion-item {
            padding: 7px 10px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f0f0f1;
        }
        .mg-pop-pins-suggestion-item:last-child { border-bottom: none; }
        .mg-pop-pins-suggestion-item:hover { background: #f6f7f7; }
        .mg-pop-pins-no-result { padding: 7px 10px; font-size: 13px; color: #757575; }
        .mg-pop-pins-list {
            list-style: none; margin: 0 0 8px; padding: 0;
            border: 1px solid #c3c4c7; border-radius: 4px;
            background: #fff; min-height: 40px;
        }
        .mg-pop-pins-list:empty::after {
            content: "Még nincs kiemelt termék";
            display: block; padding: 10px 12px;
            color: #757575; font-size: 13px; font-style: italic;
        }
        .mg-pop-pin-item {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 10px; border-bottom: 1px solid #f0f0f1;
            background: #fff; cursor: default;
        }
        .mg-pop-pin-item:last-child { border-bottom: none; }
        .mg-pop-pin-item.ui-sortable-helper {
            box-shadow: 0 4px 16px rgba(0,0,0,.15);
            background: #f6f7f7;
        }
        .mg-pop-pin-placeholder {
            height: 36px; background: #f0f6fc;
            border: 1px dashed #72aee6; border-radius: 2px; list-style: none;
        }
        .mg-pop-pin-handle {
            color: #c3c4c7; cursor: grab; flex-shrink: 0;
        }
        .mg-pop-pin-handle:active { cursor: grabbing; }
        .mg-pop-pin-title { flex: 1; font-size: 13px; }
        .mg-pop-pin-remove {
            color: #b32d2e; flex-shrink: 0; padding: 0 2px;
            border: none; background: none; cursor: pointer;
            line-height: 1; display: flex; align-items: center;
        }
        .mg-pop-pin-remove:hover { color: #8a1f1f; }
        ';
    }
}
