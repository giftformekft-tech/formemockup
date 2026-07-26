<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Gutenberg blokk, shortcode és vezetett ajándékkereső. */
class MG_Gift_Finder {
    const OPTION_KEY = 'mg_gift_finder_settings';
    const STATS_KEY  = 'mg_gift_finder_no_results';
    const CACHE_VERSION_KEY = 'mg_gift_finder_cache_version';
    const CACHE_TTL = HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_block_and_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_action( 'wp', array( __CLASS__, 'maybe_mark_results_noindex' ) );

        // A találati rangsor gyorsítótárát minden olyan változás elavulttá teszi,
        // ami a termékkínálatot vagy a besorolást érinti.
        foreach ( array(
            'woocommerce_update_product',
            'woocommerce_new_product',
            'woocommerce_product_set_stock',
            'woocommerce_variation_set_stock',
            'edited_product_cat',
            'created_product_cat',
            'delete_product_cat',
            'update_option_' . self::OPTION_KEY,
        ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'bump_cache_version' ) );
        }
    }

    /** A rangsor-gyorsítótár kulcsába épített verzió. A facet-halmazok is ezt használják. */
    public static function cache_version() {
        return (int) get_option( self::CACHE_VERSION_KEY, 1 );
    }

    /**
     * Elavulttá teszi a gyorsítótárat.
     *
     * Percenként legfeljebb egyszer ír, hogy egy több százas tömeges
     * termékimport ne generáljon ugyanannyi option-írást.
     */
    public static function bump_cache_version() {
        $current = self::cache_version();
        if ( $current > time() - MINUTE_IN_SECONDS ) {
            return;
        }
        update_option( self::CACHE_VERSION_KEY, time(), false );
    }

    public static function defaults() {
        return array(
            'page_id'   => 0,
            'mascot_image_id' => 0,
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
        $settings = self::add_work_recipient_routes( $settings );
        $settings['colors'] = wp_parse_args( is_array( $settings['colors'] ?? null ) ? $settings['colors'] : array(), self::defaults()['colors'] );
        $settings['budgets'] = array();
        return $settings;
    }

    private static function add_work_recipient_routes( $settings ) {
        $recipients = (array) ( $settings['questions']['recipient']['options'] ?? array() );
        $routes = array(
            array(
                'label'               => 'Kollégának',
                'category_slug'       => 'foglalkozas-polok',
                'category_ids'        => array(),
                'keywords'            => array( 'kolléga', 'kolléganő', 'munkatárs' ),
                'option_id'           => 'colleague',
                'parent_category_ids' => array(),
            ),
            array(
                'label'               => 'Főnöknek',
                'category_slug'       => 'fonok',
                'category_ids'        => array(),
                'keywords'            => array( 'főnök', 'főnökasszony' ),
                'option_id'           => 'boss',
                'parent_category_ids' => array(),
            ),
        );
        $active_route_categories = array();
        foreach ( $routes as $route ) {
            $term = get_term_by( 'slug', $route['category_slug'], 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) continue;
            $route['category_id'] = (int) $term->term_id;
            unset( $route['category_slug'] );
            $active_route_categories[] = $route['category_id'];
            $route_updated = false;
            foreach ( $recipients as &$recipient ) {
                if ( ( $recipient['option_id'] ?? '' ) !== $route['option_id'] ) continue;
                $recipient = array_merge( $recipient, $route );
                $route_updated = true;
                break;
            }
            unset( $recipient );
            if ( ! $route_updated ) $recipients[] = $route;
        }
        if ( empty( $active_route_categories ) ) return $settings;
        $settings['questions']['recipient']['options'] = $recipients;

        $general_occasion_ids = array( 15, 93, 99, 107, 118, 120, 121 );
        foreach ( $settings['questions']['occasion']['options'] as &$option ) {
            $category_id = (int) ( $option['category_id'] ?? 0 );
            $is_retirement = ( $option['option_id'] ?? '' ) === 'retirement';
            if ( ! $is_retirement && ! in_array( $category_id, $general_occasion_ids, true ) ) continue;
            $parents = array_values( array_unique( array_map( 'intval', (array) ( $option['parent_category_ids'] ?? array() ) ) ) );
            $parents = array_values( array_unique( array_merge( $parents, $active_route_categories ) ) );
            $option['parent_category_ids'] = $parents;
        }
        unset( $option );
        return $settings;
    }

    public static function get_finder_url() {
        $settings = self::get_settings();
        $url = $settings['page_id'] ? get_permalink( (int) $settings['page_id'] ) : '';
        return $url ?: home_url( '/ajandekkereso/' );
    }

    /**
     * A szűrt találati oldalak kikerülnek az indexből.
     *
     * A válasz-chipek eltávolító linkjei bejárható URL-teret nyitnak: minden
     * válaszkombináció külön cím ugyanazzal a tartalommal. A `follow` marad,
     * hogy a termékkártyák linkjei tovább öröklődjenek.
     */
    public static function maybe_mark_results_noindex() {
        if ( is_admin() || ! isset( $_GET['mg_gift_submitted'] ) || ! self::is_finder_request() ) {
            return;
        }
        add_filter( 'wp_robots', array( __CLASS__, 'filter_results_robots' ) );
        // A saját canonical az alap keresőoldalra mutat, ezért a WordPress és a
        // Yoast saját, paraméteres canonicalját is le kell cserélni. Ahol a
        // Yoast aktív, ő írja ki a fejlécet – enélkül két canonical kerülne ki.
        remove_action( 'wp_head', 'rel_canonical' );
        if ( defined( 'WPSEO_VERSION' ) ) {
            add_filter( 'wpseo_canonical', array( __CLASS__, 'filter_results_canonical' ) );
            add_filter( 'wpseo_robots', array( __CLASS__, 'filter_results_yoast_robots' ) );
        } else {
            add_action( 'wp_head', array( __CLASS__, 'render_results_canonical' ), 1 );
        }
    }

    /** Igaz, ha a jelenlegi kérés az ajándékkereső oldala. */
    private static function is_finder_request() {
        if ( ! is_singular() ) return false;
        $post = get_post();
        if ( ! $post ) return false;
        $page_id = (int) self::get_settings()['page_id'];
        if ( $page_id && (int) $post->ID === $page_id ) return true;
        return has_shortcode( (string) $post->post_content, 'mg_gift_finder' );
    }

    public static function filter_results_robots( $robots ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
        unset( $robots['index'], $robots['nofollow'] );
        return $robots;
    }

    public static function filter_results_canonical( $canonical ) {
        return self::get_finder_url();
    }

    public static function filter_results_yoast_robots( $robots ) {
        return 'noindex, follow';
    }

    public static function render_results_canonical() {
        echo '<link rel="canonical" href="' . esc_url( self::get_finder_url() ) . '" />' . "\n";
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

        register_block_type( 'mockup-generator/trust-section', array(
            'api_version'     => 2,
            'editor_script'   => 'mg-gift-finder-block',
            'style'           => 'mg-gift-finder',
            'attributes'      => array(
                'eyebrow'          => array( 'type' => 'string', 'default' => 'A termék mögött valódi műhely áll' ),
                'title'            => array( 'type' => 'string', 'default' => 'Nem továbbadjuk. Mi készítjük.' ),
                'intro'            => array( 'type' => 'string', 'default' => 'A Forme ajándékok saját gépparkkal, saját műhelyben, Debrecen közelében készülnek – olyan emberek kezében, akik nap mint nap ezzel foglalkoznak.' ),
                'familyText'       => array( 'type' => 'string', 'default' => 'Családi magyar vállalkozás' ),
                'locationText'     => array( 'type' => 'string', 'default' => 'Saját gyártás Debrecen közelében' ),
                'experienceText'   => array( 'type' => 'string', 'default' => 'Több év gyakorlati tapasztalat' ),
                'printingText'     => array( 'type' => 'string', 'default' => 'Tartós DTG- és DTF-nyomtatás' ),
                'speedText'        => array( 'type' => 'string', 'default' => 'Gyors, helyben végzett elkészítés' ),
                'reviewsText'      => array( 'type' => 'string', 'default' => 'Valós vásárlói értékelések' ),
                'workshopImageId'  => array( 'type' => 'number', 'default' => 0 ),
                'workshopImageUrl' => array( 'type' => 'string', 'default' => '' ),
                'workshopImageAlt' => array( 'type' => 'string', 'default' => 'A Forme saját műhelye és gépparkja' ),
                'workshopCaption'  => array( 'type' => 'string', 'default' => 'Saját műhelyünk és gépparkunk' ),
                'teamImageId'      => array( 'type' => 'number', 'default' => 0 ),
                'teamImageUrl'     => array( 'type' => 'string', 'default' => '' ),
                'teamImageAlt'     => array( 'type' => 'string', 'default' => 'A Forme csapata' ),
                'teamCaption'      => array( 'type' => 'string', 'default' => 'A csapat, aki elkészíti a rendeléseket' ),
            ),
            'render_callback' => array( __CLASS__, 'render_trust_section' ),
        ) );

        add_shortcode( 'mg_gift_finder_teaser', array( __CLASS__, 'teaser_shortcode' ) );
        add_shortcode( 'mg_gift_finder', array( __CLASS__, 'finder_shortcode' ) );
    }

    public static function render_trust_section( $attributes = array() ) {
        wp_enqueue_style( 'mg-gift-finder' );
        $defaults = array(
            'eyebrow' => 'A termék mögött valódi műhely áll',
            'title' => 'Nem továbbadjuk. Mi készítjük.',
            'intro' => 'A Forme ajándékok saját gépparkkal, saját műhelyben, Debrecen közelében készülnek – olyan emberek kezében, akik nap mint nap ezzel foglalkoznak.',
            'familyText' => 'Családi magyar vállalkozás',
            'locationText' => 'Saját gyártás Debrecen közelében',
            'experienceText' => 'Több év gyakorlati tapasztalat',
            'printingText' => 'Tartós DTG- és DTF-nyomtatás',
            'speedText' => 'Gyors, helyben végzett elkészítés',
            'reviewsText' => 'Valós vásárlói értékelések',
            'workshopCaption' => 'Saját műhelyünk és gépparkunk',
            'teamCaption' => 'A csapat, aki elkészíti a rendeléseket',
        );
        $attributes = wp_parse_args( is_array( $attributes ) ? $attributes : array(), $defaults );
        $facts = array_values( array_filter( array(
            $attributes['familyText'], $attributes['locationText'], $attributes['experienceText'],
            $attributes['printingText'], $attributes['speedText'], $attributes['reviewsText'],
        ) ) );
        $workshop_id = absint( $attributes['workshopImageId'] ?? 0 );
        $team_id = absint( $attributes['teamImageId'] ?? 0 );
        $workshop = $workshop_id ? wp_get_attachment_image( $workshop_id, 'large', false, array(
            'loading' => 'lazy', 'decoding' => 'async', 'alt' => sanitize_text_field( $attributes['workshopImageAlt'] ?? '' ),
        ) ) : '';
        $team = $team_id ? wp_get_attachment_image( $team_id, 'large', false, array(
            'loading' => 'lazy', 'decoding' => 'async', 'alt' => sanitize_text_field( $attributes['teamImageAlt'] ?? '' ),
        ) ) : '';
        $heading_id = wp_unique_id( 'mg-trust-title-' );
        $align = isset( $attributes['align'] ) ? sanitize_key( $attributes['align'] ) : '';
        $class = 'mg-trust-section' . ( $align ? ' align' . $align : '' ) . ( ! $workshop && ! $team ? ' mg-trust-section--no-media' : '' );

        ob_start(); ?>
        <section class="<?php echo esc_attr( $class ); ?>" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
            <div class="mg-trust-section__heading">
                <span class="mg-gift-eyebrow"><?php echo esc_html( $attributes['eyebrow'] ); ?></span>
                <h2 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $attributes['title'] ); ?></h2>
                <p><?php echo esc_html( $attributes['intro'] ); ?></p>
            </div>
            <div class="mg-trust-section__body">
                <ul class="mg-trust-section__facts">
                    <?php foreach ( $facts as $index => $fact ) : ?>
                        <li><span aria-hidden="true"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span><strong><?php echo esc_html( $fact ); ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( $workshop || $team ) : ?>
                    <div class="mg-trust-section__media">
                        <?php if ( $workshop ) : ?><figure class="mg-trust-section__photo mg-trust-section__photo--workshop"><?php echo wp_kses_post( $workshop ); ?><figcaption><?php echo esc_html( $attributes['workshopCaption'] ); ?></figcaption></figure><?php endif; ?>
                        <?php if ( $team ) : ?><figure class="mg-trust-section__photo mg-trust-section__photo--team"><?php echo wp_kses_post( $team ); ?><figcaption><?php echo esc_html( $attributes['teamCaption'] ); ?></figcaption></figure><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php return ob_get_clean();
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
        $mascot_image_id = absint( $settings['mascot_image_id'] ?? 0 );
        $mascot_html = $mascot_image_id ? wp_get_attachment_image( $mascot_image_id, 'large', false, array( 'loading' => 'eager', 'decoding' => 'async', 'alt' => '' ) ) : '';
        if ( $mascot_html ) $class .= ' mg-gift-teaser--has-mascot';
        $seasonal_cards = array_filter( $settings['cards'], function( $card ) {
            return ( $card['season'] ?? 'all' ) !== 'all' && self::is_card_in_season( $card );
        } );

        ob_start(); ?>
        <section class="<?php echo esc_attr( $class ); ?>" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
            <?php if ( $mascot_html ) : ?>
                <div class="mg-gift-teaser__mascot" aria-hidden="true"><?php echo wp_kses_post( $mascot_html ); ?></div>
            <?php endif; ?>
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
                                <input type="radio" name="mg_gift_recipient" value="<?php echo esc_attr( self::get_option_value( $option ) ); ?>" required />
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
            $requested_recipient = sanitize_text_field( wp_unslash( $_GET['mg_gift_recipient'] ) );
            foreach ( $settings['questions']['recipient']['options'] as $option ) {
                if ( self::option_matches_value( $option, $requested_recipient ) ) {
                    $initial_step = 1;
                    break;
                }
            }
        }

        ob_start(); ?>
        <section class="mg-gift-finder" data-mg-gift-finder data-initial-step="<?php echo esc_attr( $initial_step ); ?>">
            <header class="mg-gift-finder__header">
                <span class="mg-gift-eyebrow">Forme ajándéksegéd</span>
                <h1>Segítünk megtalálni az igazit</h1>
                <p>Néhány gyors választás, és máris mutatjuk a leginkább hozzád illő ajándékokat.</p>
                <div class="mg-gift-progress" aria-hidden="true"><?php for ( $i = 0; $i < $total_steps; $i++ ) echo '<span></span>'; ?></div>
                <p class="mg-gift-status" role="status" aria-live="polite"></p>
            </header>
            <form method="get" action="<?php echo esc_url( self::get_finder_url() ); ?>" class="mg-gift-wizard">
                <?php $step = 0; foreach ( $questions as $key => $question ) : $step++; ?>
                    <fieldset class="mg-gift-step" tabindex="-1" data-step="<?php echo esc_attr( $step ); ?>" data-question="<?php echo esc_attr( $key ); ?>" data-title="<?php echo esc_attr( $question['title'] ); ?>">
                        <legend><small><?php echo esc_html( $step . '/' . $total_steps ); ?></small><?php echo esc_html( $question['title'] ); ?></legend>
                        <div class="mg-gift-options">
                            <?php foreach ( $question['options'] as $option ) :
                                $term_id = (int) ( $option['category_id'] ?? 0 );
                                $parent_ids = array_values( array_filter( array_map( 'intval', (array) ( $option['parent_category_ids'] ?? array() ) ) ) );
                                $option_value = self::get_option_value( $option );
                                $option_category_ids = self::get_option_category_ids( $option );
                                if ( $option_value === '' ) continue; ?>
                                <label class="mg-gift-option"<?php if ( $parent_ids ) : ?> data-parent-ids="<?php echo esc_attr( implode( ',', $parent_ids ) ); ?>"<?php endif; ?>>
                                    <input type="radio" name="mg_gift_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $option_value ); ?>" data-category-ids="<?php echo esc_attr( implode( ',', $option_category_ids ) ); ?>" <?php checked( self::option_matches_value( $option, sanitize_text_field( wp_unslash( $_GET[ 'mg_gift_' . $key ] ?? '' ) ) ), true ); ?> />
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

    private static function option_matches_value( $option, $value ) {
        $value = (string) $value;
        if ( $value === '' ) return false;
        if ( self::get_option_value( $option ) === $value ) return true;
        if ( ! ctype_digit( $value ) ) return false;
        return in_array( (int) $value, self::get_option_category_ids( $option ), true );
    }

    private static function get_option_keywords( $option ) {
        $sources = self::get_explicit_keywords( $option );
        $sources[] = sanitize_text_field( $option['label'] ?? '' );
        foreach ( self::get_option_category_ids( $option ) as $category_id ) {
            $term = get_term( $category_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) $sources[] = $term->name;
        }

        $keywords = array();
        foreach ( $sources as $source ) $keywords = array_merge( $keywords, self::keyword_variants( $source ) );
        return array_values( array_unique( $keywords ) );
    }

    private static function get_explicit_keywords( $option ) {
        $keywords = is_string( $option['keywords'] ?? null )
            ? preg_split( '/[,;\r\n]+/', $option['keywords'] )
            : (array) ( $option['keywords'] ?? array() );
        $keywords = array_map( 'sanitize_text_field', $keywords );
        return array_values( array_unique( array_filter( array_map( 'trim', $keywords ), function( $keyword ) {
            return mb_strlen( $keyword ) >= 3;
        } ) ) );
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

    /**
     * Egy adminban felvett válaszlehetőségből a találatszámításban használt
     * választás. A frontend és az admin diagnosztikája ugyanezt az alakot
     * használja, különben a diagnosztika mást mérne, mint amit a vevő lát.
     */
    public static function build_choice( $question_key, $option ) {
        $category_ids = self::get_option_category_ids( $option );
        return array(
            'question'            => $question_key,
            'label'               => sanitize_text_field( $option['label'] ?? '' ),
            'category_id'         => (int) ( $category_ids[0] ?? 0 ),
            'category_ids'        => $category_ids,
            'parent_category_ids' => array_values( array_filter( array_map( 'intval', (array) ( $option['parent_category_ids'] ?? array() ) ) ) ),
            'keywords'            => self::get_option_keywords( $option ),
            'priority_keywords'   => self::get_explicit_keywords( $option ),
            'keyword_priority'    => ! empty( $option['keywords'] ),
        );
    }

    private static function get_selected_choices( $settings ) {
        $selected = array();
        $prior = array();
        foreach ( $settings['questions'] as $key => $question ) {
            $value = sanitize_text_field( wp_unslash( $_GET[ 'mg_gift_' . $key ] ?? '' ) );
            if ( $value === '' || $value === '0' ) continue;
            foreach ( $question['options'] as $option ) {
                if ( ! self::option_matches_value( $option, $value ) ) continue;
                $parents = array_values( array_filter( array_map( 'intval', (array) ( $option['parent_category_ids'] ?? array() ) ) ) );
                if ( empty( $parents ) || array_intersect( $parents, $prior ) ) {
                    $choice = self::build_choice( $key, $option );
                    $selected[] = $choice;
                    $prior = array_merge( $prior, $choice['category_ids'] );
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

    /** A választásokból és a belépési kategóriából felépíti a pontozási alapot. */
    private static function build_scoring_choices( $choices, $term_ids ) {
        $scoring_choices = $choices;
        foreach ( $term_ids as $term_id ) {
            $already_selected = array_filter( $scoring_choices, function( $choice ) use ( $term_id ) { return in_array( $term_id, array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) ), true ); } );
            if ( empty( $already_selected ) ) $scoring_choices[] = array( 'question' => 'start', 'label' => '', 'category_id' => $term_id, 'category_ids' => array( $term_id ), 'keywords' => array(), 'priority_keywords' => array(), 'keyword_priority' => false );
        }
        return $scoring_choices;
    }

    /**
     * A rangsor kiszámítása gyorsítótárral.
     *
     * A számítás több száz terméket kérdez le és pontoz, ezért ugyanazt a
     * válaszkombinációt nem számoljuk újra minden oldalletöltésnél. A kulcs a
     * választások aláírásából és egy verziószámból áll, amit minden
     * termék-/kategóriaváltozás elavulttá tesz.
     *
     * @return array<int,array{product_id:int,score:int,tier:string}>
     */
    private static function get_ranked_results( array $scoring_choices ) {
        $signature = array( 'v' => self::cache_version(), 'oos' => get_option( 'woocommerce_hide_out_of_stock_items' ) );
        foreach ( $scoring_choices as $choice ) {
            $categories = array_values( array_filter( array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) ) ) );
            sort( $categories );
            $keywords = self::get_option_keywords( $choice );
            sort( $keywords );
            $signature[] = array( $choice['question'] ?? '', $categories, $keywords );
        }
        $key = 'mg_gift_rank_' . md5( (string) wp_json_encode( $signature ) );

        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $ranked = self::compute_ranked_results( $scoring_choices );
        set_transient( $key, $ranked, self::CACHE_TTL );
        return $ranked;
    }

    private static function compute_ranked_results( array $scoring_choices ) {
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
                // Rendezés nélkül a LIMIT eredménye futásonként változhatna, a
                // gyorsítótár pedig egy véletlen pillanatképet fagyasztana be.
                $title_ids = $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND (" . implode( ' OR ', $title_where ) . ') ORDER BY ID DESC LIMIT 500'
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

        // A jelölteket `fields => 'ids'` adja vissza, ami nem tölti fel a post
        // cache-t – enélkül a pontozó ciklus `get_the_title()` hívása
        // termékenként külön lekérdezést indítana.
        foreach ( array_chunk( $candidate_ids, 200 ) as $chunk ) {
            _prime_post_caches( $chunk, false, false );
        }

        $product_categories = array_fill_keys( $candidate_ids, array() );
        if ( ! empty( $candidate_ids ) ) {
            $category_terms = wp_get_object_terms( $candidate_ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
            if ( ! is_wp_error( $category_terms ) ) foreach ( $category_terms as $term ) $product_categories[ (int) $term->object_id ][] = (int) $term->term_id;
        }

        $choice_families = array();
        $choice_keywords = array();
        $choice_priority_keywords = array();
        foreach ( $scoring_choices as $index => $choice ) {
            $choice_families[ $index ] = array();
            $choice_keywords[ $index ] = self::get_option_keywords( $choice );
            $choice_priority_keywords[ $index ] = self::get_explicit_keywords( array( 'keywords' => $choice['priority_keywords'] ?? array() ) );
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
            $category_matches = array();
            $keyword_matches = array();
            $priority_keyword_matches = array();
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
                $category_matches[ $index ] = $category_match;
                $keyword_matches[ $index ] = $keyword_match_count > 0;
                $priority_keyword_matches[ $index ] = false;
                foreach ( $choice_priority_keywords[ $index ] as $priority_keyword ) {
                    $normalized_priority_keyword = mb_strtolower( remove_accents( $priority_keyword ) );
                    if ( $normalized_priority_keyword !== '' && mb_strpos( $normalized_title, $normalized_priority_keyword ) !== false ) {
                        $priority_keyword_matches[ $index ] = true;
                        break;
                    }
                }
            }
            $ranked[] = array(
                'product_id'               => $product_id,
                'score'                    => $match_count,
                'name_score'               => $name_score,
                'tie'                      => $tie_score,
                'category_matches'         => $category_matches,
                'keyword_matches'          => $keyword_matches,
                'priority_keyword_matches' => $priority_keyword_matches,
            );
        }

        if ( ! empty( $ranked ) ) update_meta_cache( 'post', array_column( $ranked, 'product_id' ) );
        $ranked = self::compose_diverse_results( $ranked, $scoring_choices, 10, 10 );
        $ranked = array_slice( $ranked, 0, 50 );

        // Csak a megjelenítéshez kellő mezőket tároljuk el: a pontozás közbeni
        // egyezéstérképek nagyok, és a rendereléshez már nincs rájuk szükség.
        return array_map( function( $item ) {
            return array(
                'product_id' => (int) $item['product_id'],
                'score'      => (int) $item['score'],
                'tier'       => (string) ( $item['tier'] ?? '' ),
            );
        }, $ranked );
    }

    /** A jelenlegi keresés URL-paraméterei, fertőtlenítve. */
    private static function get_active_query_args( $settings ) {
        $args = array();
        foreach ( array_keys( $settings['questions'] ) as $key ) {
            $value = sanitize_text_field( wp_unslash( $_GET[ 'mg_gift_' . $key ] ?? '' ) );
            if ( $value !== '' && $value !== '0' ) $args[ 'mg_gift_' . $key ] = $value;
        }
        $start = isset( $_GET['mg_gift_start'] ) ? (int) $_GET['mg_gift_start'] : 0;
        if ( $start ) $args['mg_gift_start'] = $start;
        $args['mg_gift_submitted'] = 1;
        return $args;
    }

    /**
     * Egy válasz elhagyásával képzett találati URL.
     *
     * A rá épülő függő válaszokat is elhagyja: az „Anyák napja” önmagában
     * értelmetlen az „Anyának” címzett nélkül, és a kereső úgyis kiszűrné –
     * de az URL-ben ottfelejtve zavaros állapotot hagyna.
     */
    private static function get_chip_remove_url( $question_key, $choices, $settings ) {
        $args = self::get_active_query_args( $settings );
        unset( $args[ 'mg_gift_' . $question_key ] );
        if ( $question_key === 'start' ) unset( $args['mg_gift_start'] );

        $removed = array();
        foreach ( $choices as $choice ) {
            if ( ( $choice['question'] ?? '' ) !== $question_key ) continue;
            $removed = array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) );
        }
        if ( $question_key === 'start' && isset( $_GET['mg_gift_start'] ) ) $removed = array( (int) $_GET['mg_gift_start'] );
        if ( ! empty( $removed ) ) {
            $seen_current = false;
            foreach ( $choices as $choice ) {
                $key = $choice['question'] ?? '';
                if ( $key === $question_key ) { $seen_current = true; continue; }
                if ( ! $seen_current ) continue;
                $parents = array_map( 'intval', (array) ( $choice['parent_category_ids'] ?? array() ) );
                if ( $parents && array_intersect( $parents, $removed ) ) unset( $args[ 'mg_gift_' . $key ] );
            }
        }
        return add_query_arg( $args, self::get_finder_url() );
    }

    /** Rövid, chipre férő kérdésnév. */
    private static function get_chip_prefix( $question_key ) {
        $prefixes = array(
            'recipient'    => 'Kinek',
            'occasion'     => 'Alkalom',
            'wedding_type' => 'Esemény',
            'interest'     => 'Érdeklődés',
            'occupation'   => 'Foglalkozás',
            'start'        => 'Kiindulás',
        );
        return $prefixes[ $question_key ] ?? '';
    }

    private static function build_chips( $choices, $settings ) {
        $chips = array();
        foreach ( $choices as $choice ) {
            $key = $choice['question'] ?? '';
            if ( $key === '' || ( $choice['label'] ?? '' ) === '' ) continue;
            $chips[] = array(
                'question'   => $key,
                'prefix'     => self::get_chip_prefix( $key ),
                'label'      => $choice['label'],
                'remove_url' => self::get_chip_remove_url( $key, $choices, $settings ),
            );
        }

        $start = isset( $_GET['mg_gift_start'] ) ? (int) $_GET['mg_gift_start'] : 0;
        if ( $start ) {
            $label = '';
            foreach ( $settings['cards'] as $card ) {
                if ( (int) ( $card['category_id'] ?? 0 ) !== $start ) continue;
                $term = get_term( $start, 'product_cat' );
                $label = sanitize_text_field( $card['label'] ?? '' ) ?: ( $term && ! is_wp_error( $term ) ? $term->name : '' );
                break;
            }
            if ( $label !== '' ) {
                $chips[] = array(
                    'question'   => 'start',
                    'prefix'     => self::get_chip_prefix( 'start' ),
                    'label'      => $label,
                    'remove_url' => self::get_chip_remove_url( 'start', $choices, $settings ),
                );
            }
        }
        return $chips;
    }

    /**
     * A megadott válaszok chipsávja a találatok fölött.
     *
     * A találat így kiindulópont lesz, nem végállapot: egyetlen válasz is
     * levehető anélkül, hogy a vevőnek elölről kellene kezdenie. A chipek
     * hétköznapi linkek, ezért a vissza gomb és a megosztott URL is működik.
     */
    private static function render_chip_bar( $chips ) {
        if ( empty( $chips ) ) return;
        ?>
        <div class="mg-gift-chips" role="group" aria-label="A választásaid">
            <span class="mg-gift-chips__intro">A válaszaid:</span>
            <?php foreach ( $chips as $chip ) : ?>
                <span class="mg-gift-chip">
                    <?php if ( $chip['prefix'] !== '' ) : ?><span class="mg-gift-chip__question"><?php echo esc_html( $chip['prefix'] ); ?></span><?php endif; ?>
                    <span class="mg-gift-chip__label"><?php echo esc_html( $chip['label'] ); ?></span>
                    <a class="mg-gift-chip__remove" href="<?php echo esc_url( $chip['remove_url'] ); ?>" data-question="<?php echo esc_attr( $chip['question'] ); ?>" aria-label="<?php echo esc_attr( $chip['label'] . ' szűrő elhagyása' ); ?>"><span aria-hidden="true">×</span></a>
                </span>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_results( $choices, $term_ids, $settings ) {
        $scoring_choices = self::build_scoring_choices( $choices, $term_ids );
        $ranked = self::get_ranked_results( $scoring_choices );
        $max_score = empty( $ranked ) ? 0 : max( array_column( $ranked, 'score' ) );
        if ( ! empty( $ranked ) ) {
            _prime_post_caches( array_column( $ranked, 'product_id' ), false, false );
        }
        $virtual_types = self::get_virtual_type_options();
        ?>
        <div class="mg-gift-results" id="mg-gift-results"
             data-result-count="<?php echo esc_attr( count( $ranked ) ); ?>"
             data-choice-count="<?php echo esc_attr( count( $scoring_choices ) ); ?>"
             data-max-score="<?php echo esc_attr( $max_score ); ?>">
            <div class="mg-gift-results__heading"><div><span class="mg-gift-eyebrow">Személyre szabott találatok</span><h2 tabindex="-1">Ezeket neked válogattuk</h2></div><button type="button" class="mg-gift-restart">Újrakezdem</button></div>
            <?php self::render_chip_bar( self::build_chips( $choices, $settings ) ); ?>
            <?php if ( empty( $ranked ) ) : ?>
                <?php self::log_no_results( $term_ids ); ?>
                <p class="mg-gift-empty">Erre a kombinációra még nincs találat. Válassz másik lehetőséget, vagy nézd meg az összes ajándékot.</p>
            <?php else : ?>
                <?php if ( ( count( $scoring_choices ) >= 3 && $max_score < count( $scoring_choices ) ) || ! empty( $virtual_types ) ) : ?>
                    <div class="mg-gift-results-controls">
                        <?php if ( count( $scoring_choices ) >= 3 && $max_score < count( $scoring_choices ) ) : ?>
                            <p class="mg-gift-fallback-note">Nincs olyan termék, amely mindegyik választásnak pontosan megfelel. A legközelebbi, <?php echo esc_html( $max_score . '/' . count( $scoring_choices ) ); ?> feltételhez illő találatokat mutatjuk.</p>
                        <?php endif; ?>
                        <?php if ( ! empty( $virtual_types ) ) : ?>
                            <label class="mg-gift-type-picker"><span>Előnézet terméktípusa</span><select class="mg-gift-type-select" data-type-colors="<?php echo esc_attr( wp_json_encode( array_map( function( $type ) { return $type['colors']; }, $virtual_types ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>"><option value="">Alapértelmezett termék</option><?php foreach ( $virtual_types as $type_slug => $type ) : ?><option value="<?php echo esc_attr( $type_slug ); ?>"><?php echo esc_html( $type['label'] ); ?></option><?php endforeach; ?></select></label>
                            <label class="mg-gift-type-picker mg-gift-color-picker" hidden><span>Előnézet színe</span><select class="mg-gift-color-select" disabled></select></label>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="mg-gift-product-grid">
                <?php foreach ( $ranked as $index => $item ) : $product = wc_get_product( $item['product_id'] ); if ( ! $product ) continue;
                    $default_image = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src( 'woocommerce_thumbnail' );
                    $type_data = self::get_product_type_data( $product, $virtual_types );
                    $product_url = $product->get_permalink(); ?>
                    <article class="mg-gift-product-card" <?php echo $index >= 20 ? 'hidden' : ''; ?>>
                        <a href="<?php echo esc_url( $product_url ); ?>" data-default-url="<?php echo esc_url( $product_url ); ?>" data-type-urls="<?php echo esc_attr( wp_json_encode( $type_data['urls'], JSON_UNESCAPED_SLASHES ) ); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-product-name="<?php echo esc_attr( $product->get_name() ); ?>" data-position="<?php echo esc_attr( $index + 1 ); ?>">
                            <span class="mg-gift-product-card__image"><img src="<?php echo esc_url( $default_image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy" decoding="async" data-default-src="<?php echo esc_url( $default_image ); ?>" data-preview-base="<?php echo esc_url( $type_data['preview_base'] ); ?>" data-product-sku="<?php echo esc_attr( $type_data['sku'] ); ?>" /></span>
                            <?php if ( ( $item['tier'] ?? '' ) === 'related' ) : ?>
                                <span class="mg-gift-match">Kapcsolódó ötlet</span>
                            <?php elseif ( $item['score'] > 1 ) : ?>
                                <span class="mg-gift-match"><?php echo esc_html( $item['score'] ); ?> válaszodhoz is illik</span>
                            <?php endif; ?>
                            <h3><?php echo esc_html( wp_trim_words( $product->get_name(), 5, '…' ) ); ?></h3>
                        </a>
                    </article>
                <?php endforeach; ?>
                </div>
                <?php if ( count( $ranked ) > 20 ) : ?>
                    <div class="mg-gift-load-more-wrap"><button type="button" class="mg-gift-primary-button mg-gift-load-more">Mutass még ötleteket</button></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php self::render_bundles( $term_ids, $settings ); ?>
        <?php wp_reset_postdata();
    }

    private static function get_virtual_type_options() {
        if ( ! class_exists( 'MG_Variant_Display_Manager' ) ) return array();
        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        if ( ! is_array( $catalog ) ) return array();
        $types = array();
        foreach ( $catalog as $type_slug => $type ) {
            $colors = array_keys( (array) ( $type['colors'] ?? array() ) );
            if ( empty( $colors ) ) continue;
            $type_colors = array();
            foreach ( (array) ( $type['colors'] ?? array() ) as $color_slug => $color ) {
                $color_slug = sanitize_title( $color_slug );
                if ( $color_slug === '' ) continue;
                $type_colors[ $color_slug ] = sanitize_text_field( $color['label'] ?? $color_slug );
            }
            $types[ sanitize_title( $type_slug ) ] = array(
                'label' => sanitize_text_field( $type['label'] ?? $type_slug ),
                'color' => sanitize_title( reset( $colors ) ),
                'colors' => $type_colors,
            );
        }
        return $types;
    }

    private static function get_product_type_data( $product, $virtual_types ) {
        $data = array( 'preview_base' => '', 'sku' => '', 'urls' => array() );
        if ( ! $product instanceof WC_Product || ! class_exists( 'MG_Virtual_Variant_Manager' ) ) return $data;
        $config = MG_Virtual_Variant_Manager::get_frontend_config( $product );
        $data['preview_base'] = esc_url_raw( $config['mockup']['baseUrl'] ?? '' );
        $data['sku'] = sanitize_text_field( $config['product']['sku'] ?? $product->get_sku() );
        $configured_urls = isset( $config['typeUrls'] ) && is_array( $config['typeUrls'] ) ? $config['typeUrls'] : array();
        foreach ( $virtual_types as $type_slug => $type ) {
            if ( empty( $config['types'][ $type_slug ] ) ) continue;
            if ( ! empty( $configured_urls[ $type_slug ] ) ) {
                $data['urls'][ $type_slug ] = esc_url_raw( $configured_urls[ $type_slug ] );
            } elseif ( ! empty( $config['useVirtualPermalinks'] ) && class_exists( 'MG_GMC_SEO_Optimizer' ) ) {
                $data['urls'][ $type_slug ] = esc_url_raw( MG_GMC_SEO_Optimizer::get_virtual_permalink( $product, $type_slug ) );
            } else {
                $data['urls'][ $type_slug ] = esc_url_raw( add_query_arg( 'mg_type', $type_slug, $product->get_permalink() ) );
            }
        }
        return $data;
    }

    private static function compose_diverse_results( $all_items, $choices, $primary_limit, $related_limit ) {
        if ( empty( $all_items ) ) return array();
        $choice_indexes = array_keys( $choices );
        self::sort_ranked_items( $all_items );
        $primary_index = reset( $choice_indexes );
        $primary = array_values( array_filter( $all_items, function( $item ) use ( $primary_index ) {
            return ! empty( $item['category_matches'][ $primary_index ] );
        } ) );
        if ( empty( $primary ) ) {
            $primary = array_values( array_filter( $all_items, function( $item ) use ( $primary_index ) {
                return ! empty( $item['keyword_matches'][ $primary_index ] );
            } ) );
        }
        if ( empty( $primary ) ) $primary = $all_items;
        self::sort_ranked_items( $primary );
        $primary_ids = array_fill_keys( array_map( 'intval', array_column( $primary, 'product_id' ) ), true );

        $buckets = array();
        foreach ( array_slice( $choice_indexes, 1 ) as $choice_index ) {
            $keyword_first = ! empty( $choices[ $choice_index ]['keyword_priority'] );
            $category_bucket = array_values( array_filter( $all_items, function( $item ) use ( $choice_index, $primary_ids, $keyword_first ) {
                return empty( $primary_ids[ (int) $item['product_id'] ] )
                    && ! empty( $item['category_matches'][ $choice_index ] )
                    && ( ! $keyword_first || empty( $item['priority_keyword_matches'][ $choice_index ] ) );
            } ) );
            $keyword_bucket = array_values( array_filter( $all_items, function( $item ) use ( $choice_index, $primary_ids, $keyword_first ) {
                return empty( $primary_ids[ (int) $item['product_id'] ] )
                    && ( $keyword_first ? ! empty( $item['priority_keyword_matches'][ $choice_index ] ) : ! empty( $item['keyword_matches'][ $choice_index ] ) )
                    && ( $keyword_first || empty( $item['category_matches'][ $choice_index ] ) );
            } ) );
            self::sort_ranked_items( $category_bucket );
            self::sort_ranked_items( $keyword_bucket );
            $bucket = $keyword_first ? array_merge( $keyword_bucket, $category_bucket ) : array_merge( $category_bucket, $keyword_bucket );
            if ( ! empty( $bucket ) ) $buckets[] = $bucket;
        }

        $related = array();
        $related_seen = array();
        $positions = array_fill( 0, count( $buckets ), 0 );
        while ( count( $related ) < $related_limit ) {
            $added = false;
            foreach ( $buckets as $bucket_index => $bucket ) {
                while ( isset( $bucket[ $positions[ $bucket_index ] ] ) ) {
                    $item = $bucket[ $positions[ $bucket_index ]++ ];
                    $product_id = (int) $item['product_id'];
                    if ( isset( $related_seen[ $product_id ] ) ) continue;
                    $item['tier'] = 'related';
                    $related[] = $item;
                    $related_seen[ $product_id ] = true;
                    $added = true;
                    break;
                }
                if ( count( $related ) >= $related_limit ) break;
            }
            if ( ! $added ) break;
        }

        $ordered = array();
        $seen = array();
        $append = function( $items, $limit = null, $tier = '' ) use ( &$ordered, &$seen ) {
            $added = 0;
            foreach ( $items as $item ) {
                $product_id = (int) $item['product_id'];
                if ( isset( $seen[ $product_id ] ) ) continue;
                if ( $tier !== '' ) $item['tier'] = $tier;
                $ordered[] = $item;
                $seen[ $product_id ] = true;
                $added++;
                if ( $limit !== null && $added >= $limit ) break;
            }
        };
        $append( $primary, $primary_limit, 'primary' );
        $append( $related, $related_limit );
        $append( array_slice( $primary, $primary_limit ), null, 'primary' );
        $append( $all_items, null, 'related' );
        return $ordered;
    }

    private static function sort_ranked_items( &$items ) {
        usort( $items, function( $a, $b ) {
            if ( $a['score'] !== $b['score'] ) return $b['score'] <=> $a['score'];
            if ( $a['name_score'] !== $b['name_score'] ) return $b['name_score'] <=> $a['name_score'];
            if ( $a['tie'] !== $b['tie'] ) return $b['tie'] <=> $a['tie'];
            return (int) get_post_meta( $b['product_id'], 'total_sales', true ) <=> (int) get_post_meta( $a['product_id'], 'total_sales', true );
        } );
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
                    ?>
                    <article class="mg-gift-bundle-card">
                        <?php if ( ! empty( $bundle['badge'] ) ) : ?><span class="mg-gift-bundle-card__badge"><?php echo esc_html( $bundle['badge'] ); ?></span><?php endif; ?>
                        <h3><?php echo esc_html( $bundle['title'] ); ?></h3>
                        <div class="mg-gift-bundle-card__products">
                            <?php foreach ( $products as $product ) : ?><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?><span><?php echo esc_html( $product->get_name() ); ?></span></a><?php endforeach; ?>
                        </div>
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
