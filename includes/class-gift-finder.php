<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Gutenberg blokk, shortcode és vezetett ajándékkereső. */
class MG_Gift_Finder {
    const OPTION_KEY = 'mg_gift_finder_settings';
    const STATS_KEY  = 'mg_gift_finder_no_results';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_block_and_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    public static function defaults() {
        return array(
            'page_id'   => 0,
            'cards'     => array(),
            'budgets'   => array(),
            'bundles'   => array(),
            'colors'    => array(
                'accent'      => '#c6503e',
                'accent_dark' => '#9d392b',
                'ink'         => '#24211d',
                'muted'       => '#6d675f',
                'background'  => '#faf6ef',
                'panel'       => '#f7f2e9',
                'card'        => '#ffffff',
            ),
            'questions' => array(
                'recipient' => array( 'title' => 'Kinek keresel ajándékot?', 'options' => array() ),
                'occasion'  => array( 'title' => 'Milyen alkalomra?', 'options' => array() ),
                'wedding_type' => array( 'title' => 'Milyen házassághoz kapcsolódó eseményre?', 'options' => array() ),
                'interest'  => array( 'title' => 'Milyen a személyisége, mi érdekli?', 'options' => array() ),
                'occupation'=> array( 'title' => 'Mi a foglalkozása?', 'options' => array() ),
            ),
        );
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_KEY, array() );
        $settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
        $saved_questions = is_array( $settings['questions'] ?? null ) ? $settings['questions'] : array();
        $settings['questions'] = array();
        foreach ( self::defaults()['questions'] as $key => $question ) {
            $settings['questions'][ $key ] = wp_parse_args( $saved_questions[ $key ] ?? array(), $question );
        }
        $settings['colors'] = wp_parse_args( is_array( $settings['colors'] ?? null ) ? $settings['colors'] : array(), self::defaults()['colors'] );
        $settings['budgets'] = array();
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
        $colors = self::get_settings()['colors'];
        $variables = array();
        foreach ( self::defaults()['colors'] as $key => $fallback ) {
            $value = sanitize_hex_color( $colors[ $key ] ?? '' ) ?: $fallback;
            $variables[] = '--mg-gift-' . str_replace( '_', '-', $key ) . ':' . $value;
        }
        wp_add_inline_style( 'mg-gift-finder', ':root{' . implode( ';', $variables ) . '}' );
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

        $recipients = array();
        foreach ( self::get_settings()['questions']['recipient']['options'] as $option ) {
            $term_id = (int) ( $option['category_id'] ?? 0 );
            if ( $term_id ) $recipients[] = array( 'id' => $term_id, 'name' => $option['label'] );
        }
        wp_localize_script( 'mg-gift-finder-block', 'MG_GIFT_BLOCK', array( 'recipients' => $recipients ) );

        register_block_type( 'mockup-generator/gift-finder', array(
            'api_version'     => 2,
            'editor_script'   => 'mg-gift-finder-block',
            'style'           => 'mg-gift-finder',
            'attributes'      => array(
                'title'       => array( 'type' => 'string', 'default' => 'Találd meg a tökéletes ajándékot' ),
                'intro'       => array( 'type' => 'string', 'default' => 'Először válaszd ki, kinek keresel ajándékot, és máris mutatjuk a hozzá illő alkalmakat.' ),
                'buttonLabel' => array( 'type' => 'string', 'default' => 'Tovább az alkalomhoz' ),
                'categoryIds' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'number' ) ),
                'recipientIds'=> array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'number' ) ),
            ),
            'render_callback' => array( __CLASS__, 'render_teaser_block' ),
        ) );

        add_shortcode( 'mg_gift_finder_teaser', array( __CLASS__, 'teaser_shortcode' ) );
        add_shortcode( 'mg_gift_finder', array( __CLASS__, 'finder_shortcode' ) );
    }

    public static function teaser_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'title' => '', 'recipient_ids' => '' ), $atts, 'mg_gift_finder_teaser' );
        $ids = array_values( array_filter( array_map( 'intval', explode( ',', $atts['recipient_ids'] ) ) ) );
        return self::render_teaser_block( array( 'title' => $atts['title'], 'recipientIds' => $ids ) );
    }

    public static function render_teaser_block( $attributes = array() ) {
        wp_enqueue_style( 'mg-gift-finder' );
        $settings = self::get_settings();
        $selected = array_map( 'intval', $attributes['recipientIds'] ?? array() );
        $recipients = array_filter( $settings['questions']['recipient']['options'], function( $option ) use ( $selected ) {
            return empty( $selected ) || in_array( (int) ( $option['category_id'] ?? 0 ), $selected, true );
        } );
        if ( empty( $recipients ) ) {
            return current_user_can( 'manage_woocommerce' )
                ? '<div class="mg-gift-empty">Az Ajándékkereső beállításaiban még nincs megjeleníthető címzett.</div>' : '';
        }

        $title  = trim( $attributes['title'] ?? '' ) ?: 'Találd meg a tökéletes ajándékot';
        $intro  = trim( $attributes['intro'] ?? '' ) ?: 'Először válaszd ki, kinek keresel ajándékot, és máris mutatjuk a hozzá illő alkalmakat.';
        $button = trim( $attributes['buttonLabel'] ?? '' ) ?: 'Tovább az alkalomhoz';
        $url = self::get_finder_url();
        $heading_id = wp_unique_id( 'mg-gift-teaser-title-' );
        $align = isset( $attributes['align'] ) ? sanitize_key( $attributes['align'] ) : '';
        $class = 'mg-gift-teaser' . ( $align ? ' align' . $align : '' );
        $seasonal_cards = array_filter( $settings['cards'], function( $card ) {
            return ( $card['season'] ?? 'all' ) !== 'all' && self::is_card_in_season( $card );
        } );

        ob_start(); ?>
        <section class="<?php echo esc_attr( $class ); ?>" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
            <div class="mg-gift-teaser__heading">
                <span class="mg-gift-eyebrow">Ajándékötletek személyre szabva</span>
                <h2 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $title ); ?></h2>
                <p><?php echo esc_html( $intro ); ?></p>
            </div>
            <form class="mg-gift-teaser__recipient-form" method="get" action="<?php echo esc_url( $url ); ?>">
                <fieldset>
                    <legend><?php echo esc_html( $settings['questions']['recipient']['title'] ); ?></legend>
                    <div class="mg-gift-options mg-gift-teaser__recipients">
                        <?php foreach ( $recipients as $option ) : $term_id = (int) ( $option['category_id'] ?? 0 ); if ( ! $term_id ) continue; ?>
                            <label class="mg-gift-option">
                                <input type="radio" name="mg_gift_recipient" value="<?php echo esc_attr( $term_id ); ?>" required />
                                <span><?php echo esc_html( $option['label'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <button class="mg-gift-primary-button" type="submit"><?php echo esc_html( $button ); ?> <span aria-hidden="true">→</span></button>
            </form>
            <?php if ( ! empty( $seasonal_cards ) ) : ?>
                <nav class="mg-gift-teaser__seasonal" aria-label="Aktuális ajándékötletek">
                    <strong>Aktuális ötletek:</strong>
                    <?php foreach ( $seasonal_cards as $card ) : $term_id = (int) ( $card['category_id'] ?? 0 ); if ( ! $term_id ) continue; ?>
                        <a href="<?php echo esc_url( add_query_arg( 'mg_gift_start', $term_id, $url ) ); ?>"><?php echo esc_html( $card['label'] ); ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
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

    private static function is_card_in_season( $card ) {
        $season = sanitize_key( $card['season'] ?? 'all' );
        if ( $season === '' || $season === 'all' ) return true;
        $month = (int) current_time( 'n' );
        $current = in_array( $month, array( 12, 1, 2 ), true ) ? 'winter'
            : ( in_array( $month, array( 3, 4, 5 ), true ) ? 'spring'
            : ( in_array( $month, array( 6, 7, 8 ), true ) ? 'summer' : 'autumn' ) );
        return $season === $current;
    }

    public static function finder_shortcode() {
        wp_enqueue_style( 'mg-gift-finder' );
        wp_enqueue_script( 'mg-gift-finder' );
        $settings = self::get_settings();
        $selected_choices = self::get_selected_choices( $settings );
        $selected = self::get_selected_terms( $selected_choices, $settings );
        $is_submitted = isset( $_GET['mg_gift_submitted'] );
        $questions = array_filter( $settings['questions'], function( $question ) { return ! empty( $question['options'] ); } );
        $total_steps = count( $questions );
        $initial_step = 0;
        if ( ! $is_submitted && ! empty( $_GET['mg_gift_recipient'] ) ) {
            $requested_recipient = (int) $_GET['mg_gift_recipient'];
            $recipient_ids = array_map( 'intval', wp_list_pluck( $settings['questions']['recipient']['options'], 'category_id' ) );
            if ( in_array( $requested_recipient, $recipient_ids, true ) ) $initial_step = 1;
        }

        ob_start(); ?>
        <section class="mg-gift-finder" data-mg-gift-finder data-initial-step="<?php echo esc_attr( $initial_step ); ?>">
            <header class="mg-gift-finder__header">
                <span class="mg-gift-eyebrow">Forme ajándéksegéd</span>
                <h1>Segítünk megtalálni az igazit</h1>
                <p>Néhány gyors választás, és máris mutatjuk a leginkább hozzád illő ajándékokat.</p>
                <div class="mg-gift-progress" aria-hidden="true"><?php for ( $i = 0; $i < $total_steps; $i++ ) echo '<span></span>'; ?></div>
            </header>
            <form method="get" action="<?php echo esc_url( self::get_finder_url() ); ?>" class="mg-gift-wizard">
                <?php $step = 0; foreach ( $questions as $key => $question ) : $step++; ?>
                    <fieldset class="mg-gift-step" data-step="<?php echo esc_attr( $step ); ?>">
                        <legend><small><?php echo esc_html( $step . '/' . $total_steps ); ?></small><?php echo esc_html( $question['title'] ); ?></legend>
                        <div class="mg-gift-options">
                            <?php foreach ( $question['options'] as $option ) :
                                $term_id = (int) ( $option['category_id'] ?? 0 );
                                $parent_ids = array_values( array_filter( array_map( 'intval', (array) ( $option['parent_category_ids'] ?? array() ) ) ) );
                                $option_value = self::get_option_value( $option );
                                $option_category_ids = self::get_option_category_ids( $option );
                                if ( $option_value === '' ) continue; ?>
                                <label class="mg-gift-option"<?php if ( $parent_ids ) : ?> data-parent-ids="<?php echo esc_attr( implode( ',', $parent_ids ) ); ?>"<?php endif; ?>>
                                    <input type="radio" name="mg_gift_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $option_value ); ?>" data-category-ids="<?php echo esc_attr( implode( ',', $option_category_ids ) ); ?>" <?php checked( sanitize_text_field( wp_unslash( $_GET[ 'mg_gift_' . $key ] ?? '' ) ), $option_value ); ?> />
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
                            <?php if ( $step === $total_steps ) : ?>
                                <button type="submit" class="mg-gift-primary-button">Mutasd az ötleteket</button>
                            <?php else : ?>
                                <button type="button" class="mg-gift-next">Tovább</button>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <input type="hidden" name="mg_gift_submitted" value="1" />
                <?php if ( ! empty( $_GET['mg_gift_start'] ) ) : ?><input type="hidden" name="mg_gift_start" value="<?php echo esc_attr( (int) $_GET['mg_gift_start'] ); ?>" /><?php endif; ?>
            </form>
            <?php if ( $is_submitted ) self::render_results( $selected_choices, $selected, $settings ); ?>
        </section>
        <?php return ob_get_clean();
    }

    private static function get_option_value( $option ) {
        $option_id = sanitize_key( $option['option_id'] ?? '' );
        if ( $option_id !== '' ) return $option_id;
        $category_ids = self::get_option_category_ids( $option );
        if ( count( $category_ids ) === 1 ) return (string) $category_ids[0];
        return ! empty( $category_ids ) ? 'group-' . substr( md5( implode( ',', $category_ids ) ), 0, 10 ) : '';
    }

    private static function get_option_category_ids( $option ) {
        $category_ids = array_values( array_filter( array_map( 'intval', (array) ( $option['category_ids'] ?? array() ) ) ) );
        $primary = (int) ( $option['category_id'] ?? 0 );
        if ( $primary ) array_unshift( $category_ids, $primary );
        return array_values( array_unique( $category_ids ) );
    }

    private static function get_option_keywords( $option ) {
        $sources = is_string( $option['keywords'] ?? null )
            ? preg_split( '/[,;\r\n]+/', $option['keywords'] )
            : (array) ( $option['keywords'] ?? array() );
        $sources[] = sanitize_text_field( $option['label'] ?? '' );
        foreach ( self::get_option_category_ids( $option ) as $category_id ) {
            $term = get_term( $category_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) $sources[] = $term->name;
        }

        $keywords = array();
        foreach ( $sources as $source ) $keywords = array_merge( $keywords, self::keyword_variants( $source ) );
        return array_values( array_unique( $keywords ) );
    }

    private static function keyword_variants( $source ) {
        $source = mb_strtolower( sanitize_text_field( $source ) );
        $words = preg_split( '/[^\p{L}\p{N}]+/u', $source, -1, PREG_SPLIT_NO_EMPTY );
        $stopwords = array( 'ajándék', 'ajándékok', 'ajandek', 'ajandekok', 'szeret', 'szereti', 'rajong', 'igazi', 'kapcsolódó', 'kapcsolodo', 'esemény', 'esemeny', 'alkalom', 'alkalmak', 'valaki', 'akinek', 'csak', 'meglepetésként', 'meglepeteskent', 'világa', 'vilaga', 'egy', 'és', 'es', 'vagy', 'nekik' );
        $suffixes = array( 'atok', 'etek', 'otok', 'ötök', 'jának', 'jének', 'javal', 'jevel', 'ként', 'kent', 'tól', 'től', 'ról', 'ről', 'ból', 'ből', 'hoz', 'hez', 'höz', 'ban', 'ben', 'nak', 'nek', 'val', 'vel', 'ért', 'ert', 'kor', 'ra', 're', 'ni' );
        $variants = array();
        foreach ( $words as $word ) {
            $normalized = mb_strtolower( remove_accents( $word ) );
            if ( mb_strlen( $word ) < 3 || in_array( $normalized, array_map( 'remove_accents', $stopwords ), true ) ) continue;
            $variants[] = $word;

            $stem = $word;
            foreach ( $suffixes as $suffix ) {
                if ( mb_strlen( $stem ) - mb_strlen( $suffix ) >= 3 && mb_substr( $stem, -mb_strlen( $suffix ) ) === $suffix ) {
                    $stem = mb_substr( $stem, 0, mb_strlen( $stem ) - mb_strlen( $suffix ) );
                    break;
                }
            }
            if ( preg_match( '/[aáeéiíoóöőuúüű]k$/u', $stem ) && mb_strlen( $stem ) > 3 ) $stem = mb_substr( $stem, 0, -1 );
            if ( preg_match( '/(ászat|észet)$/u', $stem ) ) $stem = mb_substr( $stem, 0, -2 );
            if ( mb_strlen( $stem ) >= 3 && $stem !== $word ) $variants[] = $stem;
        }
        return $variants;
    }

    private static function get_selected_choices( $settings ) {
        $selected = array();
        $prior = array();
        foreach ( $settings['questions'] as $key => $question ) {
            $value = sanitize_text_field( wp_unslash( $_GET[ 'mg_gift_' . $key ] ?? '' ) );
            if ( $value === '' || $value === '0' ) continue;
            foreach ( $question['options'] as $option ) {
                if ( self::get_option_value( $option ) !== $value ) continue;
                $parents = array_values( array_filter( array_map( 'intval', (array) ( $option['parent_category_ids'] ?? array() ) ) ) );
                if ( empty( $parents ) || array_intersect( $parents, $prior ) ) {
                    $category_ids = self::get_option_category_ids( $option );
                    $selected[] = array(
                        'question'   => $key,
                        'label'      => sanitize_text_field( $option['label'] ?? '' ),
                        'category_id'=> (int) ( $category_ids[0] ?? 0 ),
                        'category_ids'=> $category_ids,
                        'keywords'    => self::get_option_keywords( $option ),
                    );
                    $prior = array_merge( $prior, $category_ids );
                }
                break;
            }
        }
        return $selected;
    }

    private static function get_selected_terms( $choices, $settings ) {
        $selected = array();
        foreach ( $choices as $choice ) $selected = array_merge( $selected, array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) ) );
        $start = isset( $_GET['mg_gift_start'] ) ? (int) $_GET['mg_gift_start'] : 0;
        $card_ids = array_map( 'intval', wp_list_pluck( $settings['cards'], 'category_id' ) );
        if ( $start && in_array( $start, $card_ids, true ) ) $selected[] = $start;
        return array_values( array_unique( $selected ) );
    }

    private static function render_results( $choices, $term_ids, $settings ) {
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
            if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! empty( $visibility['outofstock'] ) ) {
                $tax_query[] = array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'term_taxonomy_id',
                    'terms'    => array( $visibility['outofstock'] ),
                    'operator' => 'NOT IN',
                );
            }
        }
        $scoring_choices = $choices;
        foreach ( $term_ids as $term_id ) {
            $already_selected = array_filter( $scoring_choices, function( $choice ) use ( $term_id ) { return in_array( $term_id, array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) ), true ); } );
            if ( empty( $already_selected ) ) $scoring_choices[] = array( 'question' => 'start', 'label' => '', 'category_id' => $term_id, 'category_ids' => array( $term_id ), 'keywords' => array() );
        }

        $candidate_ids = array();
        foreach ( $scoring_choices as $choice ) {
            $choice_filter = array( 'relation' => 'OR' );
            $category_ids = array_values( array_filter( array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) ) ) );
            if ( ! empty( $category_ids ) ) $choice_filter[] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $category_ids, 'operator' => 'IN', 'include_children' => true );
            if ( count( $choice_filter ) === 1 ) continue;
            $query_tax = $tax_query;
            $query_tax[] = $choice_filter;
            if ( count( $query_tax ) > 1 ) $query_tax['relation'] = 'AND';
            $query = new WP_Query( array(
                'post_type'              => 'product',
                'post_status'            => 'publish',
                'posts_per_page'         => 500,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'tax_query'              => $query_tax,
            ) );
            $candidate_ids = array_merge( $candidate_ids, array_map( 'intval', $query->posts ) );

            $keywords = self::get_option_keywords( $choice );
            if ( ! empty( $keywords ) ) {
                global $wpdb;
                $title_where = array();
                foreach ( $keywords as $keyword ) {
                    $title_where[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $keyword ) . '%' );
                }
                $title_ids = $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND (" . implode( ' OR ', $title_where ) . ') LIMIT 500'
                );
                if ( ! empty( $title_ids ) ) {
                    $title_query = new WP_Query( array(
                        'post_type'              => 'product',
                        'post_status'            => 'publish',
                        'post__in'               => array_map( 'intval', $title_ids ),
                        'posts_per_page'         => 500,
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                        'tax_query'              => $tax_query,
                    ) );
                    $candidate_ids = array_merge( $candidate_ids, array_map( 'intval', $title_query->posts ) );
                }
            }
        }
        if ( empty( $candidate_ids ) && empty( $scoring_choices ) ) {
            $query = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 48, 'fields' => 'ids', 'no_found_rows' => true ) );
            $candidate_ids = array_map( 'intval', $query->posts );
        }
        $candidate_ids = array_values( array_unique( $candidate_ids ) );

        $product_categories = array_fill_keys( $candidate_ids, array() );
        if ( ! empty( $candidate_ids ) ) {
            $category_terms = wp_get_object_terms( $candidate_ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
            if ( ! is_wp_error( $category_terms ) ) foreach ( $category_terms as $term ) $product_categories[ (int) $term->object_id ][] = (int) $term->term_id;
        }

        $choice_families = array();
        $choice_keywords = array();
        foreach ( $scoring_choices as $index => $choice ) {
            $choice_families[ $index ] = array();
            $choice_keywords[ $index ] = self::get_option_keywords( $choice );
            foreach ( array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) ) as $category_id ) {
                $children = get_term_children( $category_id, 'product_cat' );
                $choice_families[ $index ] = array_merge( $choice_families[ $index ], array( $category_id ), is_wp_error( $children ) ? array() : array_map( 'intval', $children ) );
            }
            $choice_families[ $index ] = array_values( array_unique( $choice_families[ $index ] ) );
        }

        $ranked = array();
        foreach ( $candidate_ids as $product_id ) {
            $match_count = 0;
            $tie_score = 0;
            $name_score = 0;
            $normalized_title = mb_strtolower( remove_accents( get_the_title( $product_id ) ) );
            foreach ( $scoring_choices as $index => $choice ) {
                $category_match = ! empty( $choice_families[ $index ] ) && (bool) array_intersect( $choice_families[ $index ], $product_categories[ $product_id ] ?? array() );
                $keyword_match_count = 0;
                foreach ( $choice_keywords[ $index ] as $keyword ) {
                    $normalized_keyword = mb_strtolower( remove_accents( $keyword ) );
                    if ( $normalized_keyword !== '' && mb_strpos( $normalized_title, $normalized_keyword ) !== false ) {
                        $keyword_match_count = 1;
                        break;
                    }
                }
                if ( $category_match || $keyword_match_count > 0 ) {
                    $match_count++;
                    $tie_score += 1 << min( $index, 20 );
                }
                if ( $keyword_match_count > 0 ) $name_score += $keyword_match_count;
            }
            $ranked[] = array( 'product_id' => $product_id, 'score' => $match_count, 'name_score' => $name_score, 'tie' => $tie_score );
        }
        $max_score = empty( $ranked ) ? 0 : max( array_column( $ranked, 'score' ) );
        $ranked = array_values( array_filter( $ranked, function( $item ) use ( $max_score ) { return $item['score'] === $max_score; } ) );
        if ( ! empty( $ranked ) ) update_meta_cache( 'post', array_column( $ranked, 'product_id' ) );
        usort( $ranked, function( $a, $b ) {
            if ( $a['name_score'] !== $b['name_score'] ) return $b['name_score'] <=> $a['name_score'];
            if ( $a['tie'] !== $b['tie'] ) return $b['tie'] <=> $a['tie'];
            return (int) get_post_meta( $b['product_id'], 'total_sales', true ) <=> (int) get_post_meta( $a['product_id'], 'total_sales', true );
        } );
        $ranked = array_slice( $ranked, 0, 12 );
        foreach ( $ranked as &$item ) $item['post'] = get_post( $item['product_id'] );
        unset( $item );
        ?>
        <div class="mg-gift-results" id="mg-gift-results">
            <div class="mg-gift-results__heading"><div><span class="mg-gift-eyebrow">Személyre szabott találatok</span><h2>Ezeket neked válogattuk</h2></div><button type="button" class="mg-gift-restart">Újrakezdem</button></div>
            <?php if ( empty( $ranked ) ) : ?>
                <?php self::log_no_results( $term_ids ); ?>
                <p class="mg-gift-empty">Erre a kombinációra még nincs találat. Válassz másik lehetőséget, vagy nézd meg az összes ajándékot.</p>
            <?php else : ?>
                <?php if ( count( $scoring_choices ) >= 3 && $max_score < count( $scoring_choices ) ) : ?>
                    <p class="mg-gift-fallback-note">Nincs olyan termék, amely mindegyik választásnak pontosan megfelel. A legközelebbi, <?php echo esc_html( $max_score . '/' . count( $scoring_choices ) ); ?> feltételhez illő találatokat mutatjuk.</p>
                <?php endif; ?>
                <div class="mg-gift-product-grid">
                <?php foreach ( $ranked as $item ) : if ( empty( $item['post'] ) ) continue; $product = wc_get_product( $item['post']->ID ); if ( ! $product ) continue; ?>
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
        <?php self::render_bundles( $term_ids, $settings ); ?>
        <?php wp_reset_postdata();
    }

    private static function render_bundles( $term_ids, $settings ) {
        $matches = array_filter( $settings['bundles'], function( $bundle ) use ( $term_ids ) {
            $categories = array_map( 'intval', $bundle['category_ids'] ?? array() );
            return empty( $categories ) || (bool) array_intersect( $categories, $term_ids );
        } );
        if ( empty( $matches ) ) return;
        ?>
        <section class="mg-gift-bundles">
            <div class="mg-gift-results__heading"><div><span class="mg-gift-eyebrow">Együtt még jobb</span><h2>Ajándékcsomag ötletek</h2></div></div>
            <div class="mg-gift-bundle-grid">
                <?php foreach ( array_slice( $matches, 0, 4 ) as $bundle ) :
                    $products = array_filter( array_map( 'wc_get_product', array_map( 'intval', $bundle['product_ids'] ?? array() ) ) );
                    if ( empty( $products ) ) continue;
                    $total = array_sum( array_map( function( $product ) { return (float) $product->get_price(); }, $products ) ); ?>
                    <article class="mg-gift-bundle-card">
                        <?php if ( ! empty( $bundle['badge'] ) ) : ?><span class="mg-gift-bundle-card__badge"><?php echo esc_html( $bundle['badge'] ); ?></span><?php endif; ?>
                        <h3><?php echo esc_html( $bundle['title'] ); ?></h3>
                        <div class="mg-gift-bundle-card__products">
                            <?php foreach ( $products as $product ) : ?><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?><span><?php echo esc_html( $product->get_name() ); ?></span></a><?php endforeach; ?>
                        </div>
                        <strong class="mg-gift-bundle-card__price"><?php echo wp_kses_post( wc_price( $total ) ); ?> összérték</strong>
                    </article>
                <?php endforeach; ?>
            </div>
        </section><?php
    }

    private static function log_no_results( $term_ids ) {
        sort( $term_ids );
        $hash = md5( implode( ',', $term_ids ) );
        $remote = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        $dedupe = 'mg_gift_no_result_' . md5( $hash . '|' . $remote );
        if ( get_transient( $dedupe ) ) return;
        set_transient( $dedupe, 1, 30 * MINUTE_IN_SECONDS );

        $stats = self::get_no_result_stats();
        $labels = array();
        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) $labels[] = $term->name;
        }
        if ( ! isset( $stats[ $hash ] ) ) {
            $stats[ $hash ] = array( 'terms' => $labels, 'budget' => '', 'count' => 0, 'last_seen' => '' );
        }
        $stats[ $hash ]['count']++;
        $stats[ $hash ]['last_seen'] = current_time( 'mysql' );
        uasort( $stats, function( $a, $b ) { return strcmp( $b['last_seen'], $a['last_seen'] ); } );
        update_option( self::STATS_KEY, array_slice( $stats, 0, 100, true ), false );
    }

    public static function get_no_result_stats() {
        $stats = get_option( self::STATS_KEY, array() );
        return is_array( $stats ) ? $stats : array();
    }

    public static function clear_no_result_stats() {
        delete_option( self::STATS_KEY );
    }
}
