<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Bundle_Discount_Banner
 *
 * Promóciós bannert jelenít meg:
 * - Termékoldalakon: az adott termékre vonatkozó kedvezmény sávok
 * - Kosárban: hány db kell még a következő sávhoz
 */
class MG_Bundle_Discount_Banner {

    public static function init() {
        // Termékoldali banner (Add to Cart gomb után) – csak termékoldalakon
        add_action( 'woocommerce_after_add_to_cart_button', array( __CLASS__, 'render_product_banner' ), 15 );

        // CSS + JS betöltése
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    public static function register_assets() {
        $ver      = defined( 'MG_VERSION' ) ? MG_VERSION : '2.0.1';
        $base     = plugin_dir_path( dirname( __FILE__ ) );
        $base_url = plugins_url( '', dirname( __FILE__ ) . '/mockup-generator.php' );

        wp_register_style(
            'mg-discount-banner',
            $base_url . '/assets/css/mg-discount-banner.css',
            array(),
            $ver
        );

        wp_register_script(
            'mg-discount-banner',
            $base_url . '/assets/js/mg-discount-banner.js',
            array( 'jquery' ),
            $ver,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Termékoldali banner
    // -------------------------------------------------------------------------

    public static function render_product_banner() {
        global $product;
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        if ( ! class_exists( 'MG_Bundle_Discount' ) ) {
            return;
        }

        // Virtuális típus meghatározása (alapértelmezett)
        $mg_type = '';
        if ( class_exists( 'MG_Virtual_Variant_Manager' ) && method_exists( 'MG_Virtual_Variant_Manager', 'get_default_selection' ) ) {
            $defaults = MG_Virtual_Variant_Manager::get_default_selection( $product );
            $mg_type  = isset( $defaults['type'] ) ? sanitize_key( $defaults['type'] ) : '';
        }

        $campaign = MG_Bundle_Discount::get_product_campaign( $product->get_id(), $mg_type );

        if ( ! $campaign || empty( $campaign['tiers'] ) ) {
            return;
        }

        wp_enqueue_style( 'mg-discount-banner' );
        wp_enqueue_script( 'mg-discount-banner' );

        // Alap egységár a termékből
        $base_price = (float) $product->get_price();

        // Kosárban lévő darabszám
        $cart_qty = MG_Bundle_Discount::get_campaign_qty_in_cart( $campaign['id'] );
        $next     = MG_Bundle_Discount::get_next_tier( $cart_qty, $campaign['tiers'] );

        ?>
        <div class="mg-discount-banner mg-discount-banner--product"
             data-campaign-id="<?php echo esc_attr( $campaign['id'] ); ?>"
             data-cart-qty="<?php echo esc_attr( $cart_qty ); ?>">

            <div class="mg-discount-banner__header">
                <span class="mg-discount-banner__icon">🏷️</span>
                <span class="mg-discount-banner__title">
                    <?php echo esc_html( $campaign['name'] ); ?>
                </span>
            </div>

            <div class="mg-discount-banner__tiers">
                <?php foreach ( $campaign['tiers'] as $tier ) :
                    $active       = $cart_qty >= (int) $tier['min_qty'];
                    $min_qty      = (int) $tier['min_qty'];
                    $discount_amt = (float) $tier['amount'];
                    // Effektív egységár: alap ár - (levonás / min db)
                    $unit_price   = $base_price > 0 ? max( 0, $base_price - ( $discount_amt / $min_qty ) ) : 0;
                    ?>
                    <div class="mg-discount-banner__tier <?php echo $active ? 'mg-discount-banner__tier--active' : ''; ?>">
                        <span class="mg-discount-banner__tier-qty">
                            <?php printf( esc_html__( '%d db felett', 'mockup-generator' ), $min_qty ); ?>
                        </span>
                        <span class="mg-discount-banner__tier-arrow">→</span>
                        <span class="mg-discount-banner__tier-amount">
                            <?php if ( $unit_price > 0 ) : ?>
                                <strong><?php echo wp_kses_post( wc_price( $unit_price ) ); ?>/db</strong>
                                <span class="mg-discount-banner__tier-original">
                                    (alap: <?php echo wp_kses_post( wc_price( $base_price ) ); ?>/db)
                                </span>
                            <?php else : ?>
                                <?php echo wp_kses_post( wc_price( $discount_amt ) ); ?>
                                <?php esc_html_e( 'kedvezmény', 'mockup-generator' ); ?>
                            <?php endif; ?>
                        </span>
                        <?php if ( $active ) : ?>
                            <span class="mg-discount-banner__tier-badge">✓</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mg-discount-banner__disclaimer">
                <?php esc_html_e( 'ℹ️ Egyéb mennyiségi kedvezmény ezekre a termékekre nem érvényes.', 'mockup-generator' ); ?>
            </div>

            <?php if ( $next ) :
                $next_unit = $base_price > 0 ? max( 0, $base_price - ( $next['amount'] / $next['min_qty'] ) ) : 0;
                ?>
                <div class="mg-discount-banner__progress">
                    <?php if ( $cart_qty > 0 ) :
                        printf(
                            esc_html__( 'Kosárban: %1$d db – még %2$d db és %3$s/db az ár!', 'mockup-generator' ),
                            $cart_qty,
                            $next['needed'],
                            wp_kses_post( wc_price( $next_unit ) )
                        );
                    else :
                        printf(
                            esc_html__( 'Rendelj %1$d db-ot és csak %2$s/db lesz az ár!', 'mockup-generator' ),
                            $next['min_qty'],
                            wp_kses_post( wc_price( $next_unit ) )
                        );
                    endif; ?>
                </div>
            <?php else : ?>
                <div class="mg-discount-banner__progress mg-discount-banner__progress--reached">
                    <?php esc_html_e( '🎉 Elérted a maximális kedvezményszintet!', 'mockup-generator' ); ?>
                </div>
            <?php endif; ?>


        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Kosár banner
    // -------------------------------------------------------------------------

    public static function render_cart_banner() {
        if ( ! class_exists( 'MG_Bundle_Discount' ) ) {
            return;
        }

        $campaigns = MG_Bundle_Discount::get_campaigns();
        if ( empty( $campaigns ) ) {
            return;
        }

        $banners_rendered = 0;

        foreach ( $campaigns as $campaign ) {
            if ( empty( $campaign['tiers'] ) ) {
                continue;
            }

            $cart_qty = MG_Bundle_Discount::get_campaign_qty_in_cart( $campaign['id'] );
            if ( $cart_qty < 1 ) {
                continue;
            }

            $next = MG_Bundle_Discount::get_next_tier( $cart_qty, $campaign['tiers'] );

            if ( ! $next ) {
                // Maximális szinten van – mutassuk hogy elérte
                $current_discount = 0.0;
                foreach ( $campaign['tiers'] as $tier ) {
                    if ( $cart_qty >= (int) $tier['min_qty'] ) {
                        $current_discount = (float) $tier['amount'];
                    }
                }
                ?>
                <div class="mg-discount-banner mg-discount-banner--cart mg-discount-banner--reached">
                    <span class="mg-discount-banner__icon">🎉</span>
                    <strong><?php echo esc_html( $campaign['name'] ); ?></strong>:
                    <?php printf(
                        esc_html__( 'Maximális kedvezmény elérve! (%s)', 'mockup-generator' ),
                        wp_kses_post( wc_price( $current_discount ) )
                    ); ?>
                </div>
                <?php
            } else {
                // Van következő sáv
                $pct = min( 100, round( ( $cart_qty / $next['min_qty'] ) * 100 ) );
                wp_enqueue_style( 'mg-discount-banner' );
                ?>
                <div class="mg-discount-banner mg-discount-banner--cart">
                    <div class="mg-discount-banner__cart-text">
                        <span class="mg-discount-banner__icon">⚡</span>
                        <strong><?php echo esc_html( $campaign['name'] ); ?></strong>:
                        <?php printf(
                            esc_html__( 'Még %1$d db kell a %2$s kedvezményhez!', 'mockup-generator' ),
                            $next['needed'],
                            wp_kses_post( wc_price( $next['amount'] ) )
                        ); ?>
                    </div>
                    <div class="mg-discount-banner__bar-wrap">
                        <div class="mg-discount-banner__bar" style="width: <?php echo esc_attr( $pct ); ?>%"></div>
                    </div>
                    <div class="mg-discount-banner__bar-label">
                        <?php echo esc_html( $cart_qty ); ?> / <?php echo esc_html( $next['min_qty'] ); ?> db
                    </div>
                </div>
                <?php
            }
            $banners_rendered++;
        }

        if ( $banners_rendered > 0 ) {
            wp_enqueue_style( 'mg-discount-banner' );
        }
    }
}
