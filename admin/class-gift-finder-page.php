<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MG_Gift_Finder_Page {
    const MENU_SLUG = 'mockup-generator-gift-finder';

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            'Ajándékkereső',
            'Ajándékkereső',
            'manage_woocommerce',
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Nincs jogosultság.', 'mockup-generator' ) );
        }
        if ( isset( $_POST['mg_gift_finder_nonce'] ) ) {
            self::save();
        }
        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-enhanced-select' );
        $settings = MG_Gift_Finder::get_settings();
        $categories = self::get_categories();
        $pages = get_pages( array( 'sort_column' => 'post_title', 'post_status' => array( 'publish', 'draft' ) ) );
        $finder_url = MG_Gift_Finder::get_finder_url();
        ?>
        <div class="wrap mg-gift-admin">
            <h1>🎁 Ajándékkereső</h1>
            <p>A főoldali Gutenberg blokk és a vezetett ajándékkereső közös beállításai.</p>
            <?php settings_errors( 'mg_gift_finder' ); ?>

            <div class="notice notice-info inline"><p><strong>Menübe illeszthető link:</strong> <code id="mg-gift-menu-url"><?php echo esc_html( $finder_url ); ?></code> <button type="button" class="button button-small" data-copy-target="mg-gift-menu-url">Link másolása</button></p></div>

            <form method="post">
                <?php wp_nonce_field( 'mg_save_gift_finder', 'mg_gift_finder_nonce' ); ?>
                <h2>1. Ajándékkereső oldal</h2>
                <table class="form-table"><tr><th><label for="mg-gift-page">Céloldal</label></th><td>
                    <select id="mg-gift-page" name="settings[page_id]">
                        <option value="0">— Válassz oldalt —</option>
                        <?php foreach ( $pages as $page ) : ?><option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( (int) $settings['page_id'], $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option><?php endforeach; ?>
                    </select>
                    <p class="description">Az oldal tartalmába tedd ezt a shortcode-ot: <code>[mg_gift_finder]</code></p>
                </td></tr></table>

                <h2>2. Főoldali kategóriakártyák</h2>
                <p class="description">Ezekből válogathatsz a Gutenberg „Ajándékkereső” blokk oldalsávjában. A sorrend itt a megjelenési sorrend.</p>
                <div class="mg-gift-admin-list" id="mg-gift-cards">
                    <?php foreach ( $settings['cards'] as $index => $card ) self::card_row( $index, $card, $categories ); ?>
                </div>
                <button type="button" class="button mg-add-row" data-template="mg-gift-card-template" data-target="mg-gift-cards">+ Kategóriakártya</button>

                <h2>3. A kereső kérdései</h2>
                <p class="description">Minden válasz egy WooCommerce kategóriához kapcsolódik. A termék annál előrébb kerül, minél több választott kategóriához tartozik.</p>
                <?php foreach ( $settings['questions'] as $key => $question ) : ?>
                    <section class="mg-gift-admin-question">
                        <label><strong>Kérdés:</strong> <input type="text" class="regular-text" name="settings[questions][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $question['title'] ); ?>" /></label>
                        <div class="mg-gift-admin-list" id="mg-question-<?php echo esc_attr( $key ); ?>">
                            <?php foreach ( $question['options'] as $index => $option ) self::option_row( $key, $index, $option, $categories ); ?>
                        </div>
                        <button type="button" class="button mg-add-row" data-template="mg-option-template-<?php echo esc_attr( $key ); ?>" data-target="mg-question-<?php echo esc_attr( $key ); ?>">+ Válaszlehetőség</button>
                    </section>
                <?php endforeach; ?>

                <?php submit_button( 'Ajándékkereső mentése' ); ?>
            </form>
        </div>

        <script type="text/html" id="mg-gift-card-template"><?php self::card_row( '__INDEX__', array(), $categories ); ?></script>
        <?php foreach ( array_keys( $settings['questions'] ) as $key ) : ?>
            <script type="text/html" id="mg-option-template-<?php echo esc_attr( $key ); ?>"><?php self::option_row( $key, '__INDEX__', array(), $categories ); ?></script>
        <?php endforeach; ?>
        <style>
            .mg-gift-admin h2{margin-top:32px}.mg-gift-admin-list{display:grid;gap:10px;margin:12px 0}.mg-gift-admin-row{display:flex;align-items:center;gap:10px;padding:12px;background:#fff;border:1px solid #c3c4c7;border-radius:6px}.mg-gift-admin-row input[type=text]{min-width:180px}.mg-gift-admin-row select{max-width:260px}.mg-gift-admin-row .mg-row-remove{margin-left:auto;color:#b32d2e}.mg-gift-admin-question{margin:16px 0;padding:18px;background:#f6f7f7;border-left:4px solid #2271b1}.mg-gift-admin-question>.mg-gift-admin-list{margin-left:0}
        </style>
        <script>
        (function(){
            document.addEventListener('click',function(event){
                var add=event.target.closest('.mg-add-row');
                if(add){var target=document.getElementById(add.dataset.target),template=document.getElementById(add.dataset.template);var index=Date.now();target.insertAdjacentHTML('beforeend',template.innerHTML.replace(/__INDEX__/g,index));if(window.jQuery){window.jQuery(document.body).trigger('wc-enhanced-select-init');}}
                var remove=event.target.closest('.mg-row-remove');if(remove){remove.closest('.mg-gift-admin-row').remove();}
                var copy=event.target.closest('[data-copy-target]');if(copy){navigator.clipboard.writeText(document.getElementById(copy.dataset.copyTarget).textContent);copy.textContent='Kimásolva ✓';}
            });
        })();
        </script>
        <?php
    }

    private static function card_row( $index, $card, $categories ) {
        $card = wp_parse_args( $card, array( 'category_id' => 0, 'label' => '', 'description' => '', 'image_mode' => 'category', 'product_id' => 0 ) );
        $product = $card['product_id'] ? wc_get_product( (int) $card['product_id'] ) : false;
        $prefix = 'settings[cards][' . $index . ']'; ?>
        <div class="mg-gift-admin-row">
            <?php self::category_select( $prefix . '[category_id]', $card['category_id'], $categories ); ?>
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $card['label'] ); ?>" placeholder="Kártya címe (opcionális)" />
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[description]" value="<?php echo esc_attr( $card['description'] ); ?>" placeholder="Rövid leírás" />
            <select name="<?php echo esc_attr( $prefix ); ?>[image_mode]"><option value="category" <?php selected( $card['image_mode'], 'category' ); ?>>Kategória / mockup kép</option><option value="product" <?php selected( $card['image_mode'], 'product' ); ?>>Hero termék képe</option></select>
            <select class="wc-product-search" name="<?php echo esc_attr( $prefix ); ?>[product_id]" data-placeholder="Hero termék keresése" data-action="woocommerce_json_search_products_and_variations" style="min-width:240px"><option value="<?php echo esc_attr( $card['product_id'] ); ?>" selected><?php echo $product ? esc_html( $product->get_formatted_name() ) : ''; ?></option></select>
            <button type="button" class="button-link-delete mg-row-remove">Törlés</button>
        </div><?php
    }

    private static function option_row( $key, $index, $option, $categories ) {
        $option = wp_parse_args( $option, array( 'label' => '', 'category_id' => 0 ) );
        $prefix = 'settings[questions][' . $key . '][options][' . $index . ']'; ?>
        <div class="mg-gift-admin-row">
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $option['label'] ); ?>" placeholder="pl. Anyukának" required />
            <?php self::category_select( $prefix . '[category_id]', $option['category_id'], $categories ); ?>
            <button type="button" class="button-link-delete mg-row-remove">Törlés</button>
        </div><?php
    }

    private static function category_select( $name, $selected, $categories ) { ?>
        <select name="<?php echo esc_attr( $name ); ?>" required><option value="0">— Woo kategória —</option><?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( (int) $selected, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select><?php
    }

    private static function get_categories() {
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
        return is_wp_error( $terms ) ? array() : $terms;
    }

    private static function save() {
        check_admin_referer( 'mg_save_gift_finder', 'mg_gift_finder_nonce' );
        $raw = isset( $_POST['settings'] ) ? wp_unslash( (array) $_POST['settings'] ) : array();
        $clean = MG_Gift_Finder::defaults();
        $clean['page_id'] = absint( $raw['page_id'] ?? 0 );
        foreach ( (array) ( $raw['cards'] ?? array() ) as $card ) {
            $term_id = absint( $card['category_id'] ?? 0 );
            if ( ! $term_id || ! term_exists( $term_id, 'product_cat' ) ) continue;
            $clean['cards'][] = array(
                'category_id' => $term_id,
                'label'       => sanitize_text_field( $card['label'] ?? '' ),
                'description' => sanitize_text_field( $card['description'] ?? '' ),
                'image_mode'  => ( $card['image_mode'] ?? '' ) === 'product' ? 'product' : 'category',
                'product_id'  => absint( $card['product_id'] ?? 0 ),
            );
        }
        foreach ( $clean['questions'] as $key => &$question ) {
            $source = (array) ( $raw['questions'][ $key ] ?? array() );
            $question['title'] = sanitize_text_field( $source['title'] ?? $question['title'] );
            $question['options'] = array();
            foreach ( (array) ( $source['options'] ?? array() ) as $option ) {
                $term_id = absint( $option['category_id'] ?? 0 );
                $label = sanitize_text_field( $option['label'] ?? '' );
                if ( $term_id && $label && term_exists( $term_id, 'product_cat' ) ) $question['options'][] = array( 'label' => $label, 'category_id' => $term_id );
            }
        }
        unset( $question );
        update_option( MG_Gift_Finder::OPTION_KEY, $clean, false );
        add_settings_error( 'mg_gift_finder', 'saved', 'Az ajándékkereső beállításai elmentve.', 'updated' );
    }
}
