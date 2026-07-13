<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Katalógusexport és ajándékkereső-konfiguráció import. */
class MG_Gift_Finder_Transfer {
    const EXPORT_ACTION = 'mg_gift_catalog_export';
    const IMPORT_ACTION = 'mg_gift_config_import';
    const RESTORE_ACTION = 'mg_gift_config_restore';
    const IMPORT_SCHEMA = 'mg-gift-finder-config';
    const CATALOG_SCHEMA = 'mg-gift-catalog';
    const MAX_IMPORT_SIZE = 2097152;

    public static function init() {
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'handle_export' ) );
        add_action( 'admin_post_' . self::IMPORT_ACTION, array( __CLASS__, 'handle_import' ) );
        add_action( 'admin_post_' . self::RESTORE_ACTION, array( __CLASS__, 'handle_restore' ) );
    }

    public static function render_panel() {
        if ( isset( $_GET['mg_gift_imported'] ) ) {
            echo '<div class="notice notice-success inline"><p><strong>Az ajándékkereső konfigurációja sikeresen importálva.</strong> Az import előtti beállításokról biztonsági mentés készült.</p></div>';
        }
        if ( isset( $_GET['mg_gift_restored'] ) ) {
            echo '<div class="notice notice-success inline"><p><strong>Az import előtti ajándékkereső-beállítások visszaállítva.</strong></p></div>';
        }
        $error = get_transient( 'mg_gift_import_error_' . get_current_user_id() );
        if ( $error ) {
            delete_transient( 'mg_gift_import_error_' . get_current_user_id() );
            echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
        }
        ?>
        <section class="mg-gift-transfer">
            <h2>Katalógus átadás és konfiguráció import</h2>
            <p class="description">Az export nem tartalmaz vásárlói nevet, címet, e-mailt, telefonszámot vagy rendelésazonosítót.</p>
            <div class="mg-gift-transfer__grid">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mg-gift-transfer__card">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" />
                    <?php wp_nonce_field( self::EXPORT_ACTION, 'mg_gift_export_nonce' ); ?>
                    <h3>1. Katalógus exportálása</h3>
                    <p>Kategóriahierarchia, termék–kategória kapcsolatok, címkék, terméktípusok és jelenlegi kérdéskapcsolatok. Neveket, SKU-kat, képeket, árakat, attribútumokat és készletadatokat nem tartalmaz.</p>
                    <label><input type="checkbox" name="include_sales" value="1" /> Összesített 12 havi eladási darabszám hozzáadása</label>
                    <p class="description">Az eladási összesítés nagy katalógusnál tovább tarthat, de személyes adatot nem exportál.</p>
                    <?php submit_button( 'Katalógus JSON letöltése', 'secondary', 'submit', false ); ?>
                </form>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mg-gift-transfer__card">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
                    <?php wp_nonce_field( self::IMPORT_ACTION, 'mg_gift_import_nonce' ); ?>
                    <h3>2. Konfiguráció importálása</h3>
                    <p>Csak a visszakapott <code>mg-gift-finder-config</code> JSON fájl tölthető be.</p>
                    <input type="file" name="config_file" accept="application/json,.json" required />
                    <p class="description">Importálás előtt a jelenlegi beállításokról automatikus biztonsági mentés készül.</p>
                    <?php submit_button( 'Konfiguráció importálása', 'primary', 'submit', false ); ?>
                    <?php $backup = get_option( 'mg_gift_finder_import_backup', array() ); if ( ! empty( $backup['settings'] ) ) : ?>
                        <hr />
                        <p><strong>Elérhető biztonsági mentés:</strong> <?php echo esc_html( $backup['created_at'] ?? '' ); ?></p>
                        <button type="submit" form="mg-gift-restore-form" class="button">Import előtti állapot visszaállítása</button>
                    <?php endif; ?>
                </form>
                <?php if ( ! empty( $backup['settings'] ) ) : ?>
                    <form id="mg-gift-restore-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::RESTORE_ACTION ); ?>" />
                        <?php wp_nonce_field( self::RESTORE_ACTION, 'mg_gift_restore_nonce' ); ?>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <style>
            .mg-gift-transfer{margin:24px 0;padding:20px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:8px}.mg-gift-transfer h2{margin-top:0}.mg-gift-transfer__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:14px}.mg-gift-transfer__card{padding:18px;background:#fff;border:1px solid #dcdcde;border-radius:6px}.mg-gift-transfer__card h3{margin-top:0}@media(max-width:800px){.mg-gift-transfer__grid{grid-template-columns:1fr}}
        </style>
        <?php
    }

    public static function handle_export() {
        self::authorize( self::EXPORT_ACTION, 'mg_gift_export_nonce' );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 120 );

        $include_sales = ! empty( $_POST['include_sales'] );
        $payload = self::build_catalog( $include_sales );
        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            wp_die( esc_html__( 'A katalógus JSON előállítása nem sikerült.', 'mockup-generator' ) );
        }

        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="forme-ajandek-katalogus-' . gmdate( 'Y-m-d' ) . '.json"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function build_catalog( $include_sales ) {
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'term_id' ) );
        $categories = is_wp_error( $categories ) ? array() : $categories;
        $category_rows = array();
        foreach ( $categories as $term ) {
            $category_rows[] = array(
                'id'            => (int) $term->term_id,
                'parent_id'     => (int) $term->parent,
                'name'          => $term->name,
                'slug'          => $term->slug,
                'path'          => self::term_path( $term ),
                'product_count' => (int) $term->count,
                'description'   => wp_strip_all_tags( $term->description ),
            );
        }

        $tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false, 'orderby' => 'term_id' ) );
        $tags = is_wp_error( $tags ) ? array() : array_map( function( $term ) {
            return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'product_count' => (int) $term->count );
        }, $tags );

        $sales = $include_sales ? self::aggregate_sales() : array();
        $product_ids = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
        $products = array();
        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            $cat_terms = wp_get_post_terms( $product_id, 'product_cat' );
            $tag_terms = wp_get_post_terms( $product_id, 'product_tag' );
            $cat_terms = is_wp_error( $cat_terms ) ? array() : $cat_terms;
            $tag_terms = is_wp_error( $tag_terms ) ? array() : $tag_terms;
            $products[] = array(
                'id'                => (int) $product_id,
                'featured'          => $product->is_featured(),
                'category_ids'      => array_map( 'intval', wp_list_pluck( $cat_terms, 'term_id' ) ),
                'category_paths'    => array_map( array( __CLASS__, 'term_path' ), $cat_terms ),
                'tags'              => array_map( function( $term ) { return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug ); }, $tag_terms ),
                'sales_12m_quantity'=> isset( $sales[ $product_id ] ) ? $sales[ $product_id ] : null,
            );
        }

        return array(
            'schema'       => self::CATALOG_SCHEMA,
            'version'      => 1,
            'generated_at' => current_time( 'mysql' ),
            'site_url'     => home_url( '/' ),
            'privacy'      => 'No customer, address, email, phone or order identifier is included.',
            'sales_period' => $include_sales ? 'last_12_months_aggregated_quantity_only' : 'not_included',
            'categories'   => $category_rows,
            'tags'         => $tags,
            'products'     => $products,
            'mockup_product_types' => self::get_mockup_types(),
            'cross_sell_rules'     => class_exists( 'MG_Crosssell_Manager' ) ? MG_Crosssell_Manager::get_rules() : array(),
            'current_gift_finder_settings' => self::get_gift_settings_without_prices(),
        );
    }

    public static function term_path( $term ) {
        if ( ! is_object( $term ) || empty( $term->term_id ) ) return '';
        $names = array( $term->name );
        $parent_id = (int) $term->parent;
        $guard = 0;
        while ( $parent_id && $guard < 20 ) {
            $parent = get_term( $parent_id, 'product_cat' );
            if ( ! $parent || is_wp_error( $parent ) ) break;
            array_unshift( $names, $parent->name );
            $parent_id = (int) $parent->parent;
            $guard++;
        }
        return implode( ' > ', $names );
    }

    private static function get_mockup_types() {
        $catalog = class_exists( 'MG_Variant_Display_Manager' ) ? MG_Variant_Display_Manager::get_catalog_index() : array();
        $rows = array();
        foreach ( $catalog as $slug => $data ) {
            $rows[] = array(
                'slug'          => sanitize_key( $slug ),
                'label'         => wp_strip_all_tags( $data['label'] ?? $slug ),
            );
        }
        return $rows;
    }

    private static function get_gift_settings_without_prices() {
        $settings = MG_Gift_Finder::get_settings();
        unset( $settings['budgets'] );
        return $settings;
    }

    private static function aggregate_sales() {
        $totals = array();
        $after = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
        $page = 1;
        do {
            $result = wc_get_orders( array(
                'status'    => array_keys( wc_get_order_statuses() ),
                'date_paid' => '>=' . $after,
                'limit'     => 100,
                'page'      => $page,
                'paginate'  => true,
            ) );
            foreach ( $result->orders as $order ) {
                foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                    $product_id = (int) $item->get_product_id();
                    if ( ! $product_id ) continue;
                    $quantity = max( 0, (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $item_id ) );
                    $totals[ $product_id ] = ( $totals[ $product_id ] ?? 0 ) + $quantity;
                }
            }
            $page++;
        } while ( $page <= (int) $result->max_num_pages );
        return $totals;
    }

    public static function handle_import() {
        self::authorize( self::IMPORT_ACTION, 'mg_gift_import_nonce' );
        $file = $_FILES['config_file'] ?? null;
        if ( ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            self::import_error( 'Nem érkezett érvényes JSON fájl.' );
        }
        $name = sanitize_file_name( $file['name'] ?? '' );
        $size = (int) ( $file['size'] ?? 0 );
        if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'json' || $size <= 0 || $size > self::MAX_IMPORT_SIZE ) {
            self::import_error( 'Csak legfeljebb 2 MB méretű .json konfiguráció tölthető fel.' );
        }
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            self::import_error( 'A feltöltött konfigurációs fájl nem érvényes.' );
        }
        $raw = file_get_contents( $file['tmp_name'] );
        if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_IMPORT_SIZE ) {
            self::import_error( 'A feltöltött konfigurációs fájl nem olvasható vagy túl nagy.' );
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || ( $decoded['schema'] ?? '' ) !== self::IMPORT_SCHEMA || (int) ( $decoded['version'] ?? 0 ) !== 1 || ! is_array( $decoded['settings'] ?? null ) ) {
            self::import_error( 'A fájl nem támogatott ajándékkereső-konfiguráció.' );
        }

        $settings = self::sanitize_import_settings( $decoded['settings'] );
        update_option( 'mg_gift_finder_import_backup', array( 'created_at' => current_time( 'mysql' ), 'settings' => MG_Gift_Finder::get_settings() ), false );
        update_option( MG_Gift_Finder::OPTION_KEY, $settings, false );
        wp_safe_redirect( add_query_arg( array( 'page' => MG_Gift_Finder_Page::MENU_SLUG, 'mg_gift_imported' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_restore() {
        self::authorize( self::RESTORE_ACTION, 'mg_gift_restore_nonce' );
        $backup = get_option( 'mg_gift_finder_import_backup', array() );
        if ( empty( $backup['settings'] ) || ! is_array( $backup['settings'] ) ) {
            self::import_error( 'Nem található visszaállítható ajándékkereső-mentés.' );
        }
        update_option( MG_Gift_Finder::OPTION_KEY, $backup['settings'], false );
        delete_option( 'mg_gift_finder_import_backup' );
        wp_safe_redirect( add_query_arg( array( 'page' => MG_Gift_Finder_Page::MENU_SLUG, 'mg_gift_restored' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function sanitize_import_settings( $raw ) {
        $defaults = MG_Gift_Finder::defaults();
        $clean = $defaults;
        $current = MG_Gift_Finder::get_settings();
        $page_id = absint( $raw['page_id'] ?? 0 );
        $clean['page_id'] = $page_id && get_post( $page_id ) ? $page_id : (int) $current['page_id'];
        $source_colors = is_array( $raw['colors'] ?? null ) ? $raw['colors'] : $current['colors'];
        foreach ( $clean['colors'] as $key => $fallback ) {
            $clean['colors'][ $key ] = sanitize_hex_color( $source_colors[ $key ] ?? '' ) ?: $fallback;
        }

        $clean['cards'] = array();
        foreach ( (array) ( $raw['cards'] ?? array() ) as $card ) {
            $category_id = absint( $card['category_id'] ?? 0 );
            if ( ! term_exists( $category_id, 'product_cat' ) ) continue;
            $clean['cards'][] = array(
                'category_id' => $category_id,
                'label'       => sanitize_text_field( $card['label'] ?? '' ),
                'description' => sanitize_text_field( $card['description'] ?? '' ),
                'image_mode'  => ( $card['image_mode'] ?? '' ) === 'product' ? 'product' : 'category',
                'product_id'  => wc_get_product( absint( $card['product_id'] ?? 0 ) ) ? absint( $card['product_id'] ) : 0,
                'season'      => in_array( $card['season'] ?? 'all', array( 'all', 'spring', 'summer', 'autumn', 'winter' ), true ) ? $card['season'] : 'all',
            );
        }

        foreach ( $clean['questions'] as $key => &$question ) {
            $source = (array) ( $raw['questions'][ $key ] ?? array() );
            $question['title'] = sanitize_text_field( $source['title'] ?? $question['title'] );
            $question['options'] = array();
            foreach ( (array) ( $source['options'] ?? array() ) as $option ) {
                $category_id = absint( $option['category_id'] ?? 0 );
                $label = sanitize_text_field( $option['label'] ?? '' );
                if ( ! $category_id || $label === '' || ! term_exists( $category_id, 'product_cat' ) ) continue;
                $parents = array_filter( array_map( 'absint', (array) ( $option['parent_category_ids'] ?? array() ) ), function( $id ) { return (bool) term_exists( $id, 'product_cat' ); } );
                $question['options'][] = array( 'label' => $label, 'category_id' => $category_id, 'parent_category_ids' => array_values( $parents ) );
            }
        }
        unset( $question );

        $clean['budgets'] = array();

        $clean['bundles'] = array();
        foreach ( (array) ( $raw['bundles'] ?? array() ) as $bundle ) {
            $title = sanitize_text_field( $bundle['title'] ?? '' );
            $product_ids = array_values( array_filter( array_map( 'absint', (array) ( $bundle['product_ids'] ?? array() ) ), 'wc_get_product' ) );
            if ( $title === '' || empty( $product_ids ) ) continue;
            $category_ids = array_values( array_filter( array_map( 'absint', (array) ( $bundle['category_ids'] ?? array() ) ), function( $id ) { return (bool) term_exists( $id, 'product_cat' ); } ) );
            $clean['bundles'][] = array( 'title' => $title, 'badge' => sanitize_text_field( $bundle['badge'] ?? '' ), 'category_ids' => $category_ids, 'product_ids' => $product_ids );
        }
        return $clean;
    }

    private static function authorize( $action, $nonce_field ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Nincs jogosultság.', 'mockup-generator' ), '', array( 'response' => 403 ) );
        check_admin_referer( $action, $nonce_field );
    }

    private static function import_error( $message ) {
        set_transient( 'mg_gift_import_error_' . get_current_user_id(), sanitize_text_field( $message ), 60 );
        wp_safe_redirect( add_query_arg( 'page', MG_Gift_Finder_Page::MENU_SLUG, admin_url( 'admin.php' ) ) );
        exit;
    }
}
