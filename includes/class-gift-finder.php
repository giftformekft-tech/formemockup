<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Gutenberg blokk, shortcode és vezetett ajándékkereső. */
class MG_Gift_Finder {
    const OPTION_KEY = 'mg_gift_finder_settings';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_block_and_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    public static function defaults() {
        return array(
            'page_id'   => 0,
            'cards'     => array(),
            'questions' => array(
                'recipient' => array( 'title' => 'Kinek keresel ajándékot?', 'options' => array() ),
                'occasion'  => array( 'title' => 'Milyen alkalomra?', 'options' => array() ),
                'season'    => array( 'title' => 'Melyik évszakhoz illjen?', 'options' => array() ),
            ),
        );
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_KEY, array() );
        $settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
        $settings['questions'] = wp_parse_args( $settings['questions'], self::defaults()['questions'] );
        return $settings;
    }

    public static function get_finder_url() {
        $settings = self::get_settings();
        $url = $settings['page_id'] ? get_permalink( (int) $settings['page_id'] ) : '';
        return $url ?: home_url( '/ajandekkereso/' );
    }

    public static function register_assets() {
        $base = plugins_url( '', dirname( __DIR__ ) . '/mockup-generator.php' );
        wp_register_style( 'mg-gift-finder', $base . '/assets/css/gift-finder.css', array(), MG_VERSION );
        wp_register_script( 'mg-gift-finder', $base . '/assets/js/gift-finder.js', array(), MG_VERSION, true );
    }

    public static function register_block_and_shortcodes() {
        self::register_assets();

        $editor_path = dirname( __DIR__ ) . '/assets/js/gift-finder-block.js';
        wp_register_script(
            'mg-gift-finder-block',
            plugins_url( 'assets/js/gift-finder-block.js', dirname( __DIR__ ) . '/mockup-generator.php' ),
            array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
            file_exists( $editor_path ) ? filemtime( $editor_path ) : MG_VERSION,
            true
        );

        $cards = array();
        foreach ( self::get_settings()['cards'] as $card ) {
            $term = get_term( (int) ( $card['category_id'] ?? 0 ), 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $cards[] = array( 'id' => (int) $term->term_id, 'name' => $term->name );
            }
        }
        wp_localize_script( 'mg-gift-finder-block', 'MG_GIFT_BLOCK', array( 'categories' => $cards ) );

        register_block_type( 'mockup-generator/gift-finder', array(
            'api_version'     => 2,
            'editor_script'   => 'mg-gift-finder-block',
            'style'           => 'mg-gift-finder',
            'attributes'      => array(
                'title'       => array( 'type' => 'string', 'default' => 'Találd meg a tökéletes ajándékot' ),
                'intro'       => array( 'type' => 'string', 'default' => 'Válassz egy kiindulópontot, és pár kérdés után személyre szabott ötleteket mutatunk.' ),
                'buttonLabel' => array( 'type' => 'string', 'default' => 'Ajándékkereső indítása' ),
                'categoryIds' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'number' ) ),
            ),
            'render_callback' => array( __CLASS__, 'render_teaser_block' ),
        ) );

        add_shortcode( 'mg_gift_finder_teaser', array( __CLASS__, 'teaser_shortcode' ) );
        add_shortcode( 'mg_gift_finder', array( __CLASS__, 'finder_shortcode' ) );
    }

    public static function teaser_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'title' => '', 'category_ids' => '' ), $atts, 'mg_gift_finder_teaser' );
        $ids = array_values( array_filter( array_map( 'intval', explode( ',', $atts['category_ids'] ) ) ) );
        return self::render_teaser_block( array( 'title' => $atts['title'], 'categoryIds' => $ids ) );
    }

    public static function render_teaser_block( $attributes = array() ) {
        wp_enqueue_style( 'mg-gift-finder' );
        $settings = self::get_settings();
        $selected = array_map( 'intval', $attributes['categoryIds'] ?? array() );
        $cards = array_filter( $settings['cards'], function( $card ) use ( $selected ) {
            return empty( $selected ) || in_array( (int) ( $card['category_id'] ?? 0 ), $selected, true );
        } );
        if ( empty( $cards ) ) {
            return current_user_can( 'manage_woocommerce' )
                ? '<div class="mg-gift-empty">Az Ajándékkereső beállításaiban még nincs megjeleníthető kategória.</div>' : '';
        }

        $title  = trim( $attributes['title'] ?? '' ) ?: 'Találd meg a tökéletes ajándékot';
        $intro  = trim( $attributes['intro'] ?? '' ) ?: 'Válassz egy kiindulópontot, és pár kérdés után személyre szabott ötleteket mutatunk.';
        $button = trim( $attributes['buttonLabel'] ?? '' ) ?: 'Ajándékkereső indítása';
        $url = self::get_finder_url();
        $heading_id = wp_unique_id( 'mg-gift-teaser-title-' );
        $align = isset( $attributes['align'] ) ? sanitize_key( $attributes['align'] ) : '';
        $class = 'mg-gift-teaser' . ( $align ? ' align' . $align : '' );

        ob_start(); ?>
        <section class="<?php echo esc_attr( $class ); ?>" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
            <div class="mg-gift-teaser__heading">
                <span class="mg-gift-eyebrow">Ajándékötletek személyre szabva</span>
                <h2 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $title ); ?></h2>
                <p><?php echo esc_html( $intro ); ?></p>
            </div>
            <div class="mg-gift-teaser__cards">
                <?php foreach ( $cards as $card ) :
                    $term_id = (int) ( $card['category_id'] ?? 0 );
                    $term = get_term( $term_id, 'product_cat' );
                    if ( ! $term || is_wp_error( $term ) ) continue;
                    $image = self::get_card_image( $card, $term_id );
                    $card_url = add_query_arg( 'mg_gift_start', $term_id, $url ); ?>
                    <a class="mg-gift-category-card" href="<?php echo esc_url( $card_url ); ?>">
                        <span class="mg-gift-category-card__image">
                            <?php if ( $image ) : ?><img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" /><?php endif; ?>
                        </span>
                        <span class="mg-gift-category-card__content">
                            <strong><?php echo esc_html( $card['label'] ?: $term->name ); ?></strong>
                            <span><?php echo esc_html( $card['description'] ?? '' ); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <a class="mg-gift-primary-button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $button ); ?> <span aria-hidden="true">→</span></a>
        </section>
        <?php return ob_get_clean();
    }

    private static function get_card_image( $card, $term_id ) {
        if ( ( $card['image_mode'] ?? 'category' ) === 'product' && ! empty( $card['product_id'] ) ) {
            $product = wc_get_product( (int) $card['product_id'] );
            if ( $product && $product->get_image_id() ) {
                return wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
            }
        }
        $thumbnail_id = (int) get_term_meta( $term_id, 'thumbnail_id', true );
        return $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src();
    }

    public static function finder_shortcode() {
        wp_enqueue_style( 'mg-gift-finder' );
        wp_enqueue_script( 'mg-gift-finder' );
        $settings = self::get_settings();
        $selected = self::get_selected_terms( $settings );
        $is_submitted = isset( $_GET['mg_gift_season'] );

        ob_start(); ?>
        <section class="mg-gift-finder" data-mg-gift-finder>
            <header class="mg-gift-finder__header">
                <span class="mg-gift-eyebrow">Forme ajándéksegéd</span>
                <h1>Segítünk megtalálni az igazit</h1>
                <p>Három gyors választás, és máris mutatjuk a leginkább hozzád illő ajándékokat.</p>
                <div class="mg-gift-progress" aria-hidden="true"><span></span><span></span><span></span></div>
            </header>
            <form method="get" action="<?php echo esc_url( self::get_finder_url() ); ?>" class="mg-gift-wizard">
                <?php $step = 0; foreach ( $settings['questions'] as $key => $question ) : $step++; ?>
                    <fieldset class="mg-gift-step" data-step="<?php echo esc_attr( $step ); ?>">
                        <legend><small><?php echo esc_html( $step . '/3' ); ?></small><?php echo esc_html( $question['title'] ); ?></legend>
                        <div class="mg-gift-options">
                            <?php foreach ( $question['options'] as $option ) :
                                $term_id = (int) ( $option['category_id'] ?? 0 );
                                if ( ! $term_id ) continue; ?>
                                <label class="mg-gift-option">
                                    <input type="radio" name="mg_gift_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $term_id ); ?>" <?php checked( (int) ( $_GET[ 'mg_gift_' . $key ] ?? 0 ), $term_id ); ?> />
                                    <span><?php echo esc_html( $option['label'] ); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <label class="mg-gift-option mg-gift-option--skip">
                                <input type="radio" name="mg_gift_<?php echo esc_attr( $key ); ?>" value="0" />
                                <span>Mindegy / mutass mindent</span>
                            </label>
                        </div>
                        <div class="mg-gift-step__actions">
                            <?php if ( $step > 1 ) : ?><button type="button" class="mg-gift-back">Vissza</button><?php endif; ?>
                            <?php if ( $step < 3 ) : ?><button type="button" class="mg-gift-next">Tovább</button><?php else : ?><button type="submit" class="mg-gift-primary-button">Mutasd az ötleteket</button><?php endif; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <?php if ( ! empty( $_GET['mg_gift_start'] ) ) : ?><input type="hidden" name="mg_gift_start" value="<?php echo esc_attr( (int) $_GET['mg_gift_start'] ); ?>" /><?php endif; ?>
            </form>
            <?php if ( $is_submitted ) self::render_results( $selected ); ?>
        </section>
        <?php return ob_get_clean();
    }

    private static function get_selected_terms( $settings ) {
        $selected = array();
        $allowed = array();
        foreach ( $settings['questions'] as $key => $question ) {
            $allowed[ $key ] = array_map( 'intval', wp_list_pluck( $question['options'], 'category_id' ) );
            $value = isset( $_GET[ 'mg_gift_' . $key ] ) ? (int) $_GET[ 'mg_gift_' . $key ] : 0;
            if ( $value && in_array( $value, $allowed[ $key ], true ) ) $selected[] = $value;
        }
        $start = isset( $_GET['mg_gift_start'] ) ? (int) $_GET['mg_gift_start'] : 0;
        $card_ids = array_map( 'intval', wp_list_pluck( $settings['cards'], 'category_id' ) );
        if ( $start && in_array( $start, $card_ids, true ) ) $selected[] = $start;
        return array_values( array_unique( $selected ) );
    }

    private static function render_results( $term_ids ) {
        $tax_query = array();
        if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
            $visibility = wc_get_product_visibility_term_ids();
            if ( ! empty( $visibility['exclude-from-catalog'] ) ) {
                $tax_query[] = array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'term_taxonomy_id',
                    'terms'    => array( $visibility['exclude-from-catalog'] ),
                    'operator' => 'NOT IN',
                );
            }
        }
        if ( ! empty( $term_ids ) ) {
            $tax_query[] = array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $term_ids,
                'operator'         => 'IN',
                'include_children' => true,
            );
        }
        if ( count( $tax_query ) > 1 ) $tax_query['relation'] = 'AND';

        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 48,
        );
        if ( ! empty( $tax_query ) ) $args['tax_query'] = $tax_query;
        $query = new WP_Query( $args );
        $ranked = array();
        foreach ( $query->posts as $post ) {
            $product_terms = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'ids' ) );
            if ( is_wp_error( $product_terms ) ) $product_terms = array();
            $score = 0;
            foreach ( $term_ids as $term_id ) {
                $children = get_term_children( $term_id, 'product_cat' );
                $family = array_merge( array( $term_id ), is_wp_error( $children ) ? array() : $children );
                if ( array_intersect( $family, $product_terms ) ) $score++;
            }
            $ranked[] = array( 'post' => $post, 'score' => $score );
        }
        usort( $ranked, function( $a, $b ) { return $b['score'] <=> $a['score']; } );
        $ranked = array_slice( $ranked, 0, 12 );
        ?>
        <div class="mg-gift-results" id="mg-gift-results">
            <div class="mg-gift-results__heading"><div><span class="mg-gift-eyebrow">Személyre szabott találatok</span><h2>Ezeket neked válogattuk</h2></div><button type="button" class="mg-gift-restart">Újrakezdem</button></div>
            <?php if ( empty( $ranked ) ) : ?>
                <p class="mg-gift-empty">Erre a kombinációra még nincs találat. Válassz másik lehetőséget, vagy nézd meg az összes ajándékot.</p>
            <?php else : ?><div class="mg-gift-product-grid">
                <?php foreach ( $ranked as $item ) : $product = wc_get_product( $item['post']->ID ); if ( ! $product ) continue; ?>
                    <article class="mg-gift-product-card">
                        <a href="<?php echo esc_url( $product->get_permalink() ); ?>">
                            <?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
                            <?php if ( $item['score'] > 1 ) : ?><span class="mg-gift-match"><?php echo esc_html( $item['score'] ); ?> válaszodhoz is illik</span><?php endif; ?>
                            <h3><?php echo esc_html( $product->get_name() ); ?></h3>
                            <span class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </div>
        <?php wp_reset_postdata();
    }
}
