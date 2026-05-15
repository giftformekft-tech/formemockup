<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Crosssell_Frontend
 *
 * Kosár oldali cross-sell ajánló megjelenítése.
 * Ha a vevő kosarában van egy designos termék (mg_design_id),
 * felajánlja ugyanazt a mintát más terméken kedvezménnyel.
 *
 * - Megjelenítés: woocommerce_after_cart_table hook
 * - AJAX add-to-cart: mg_crosssell_add endpoint
 * - Kedvezmény: negatív fee via woocommerce_cart_calculate_fees
 */
class MG_Crosssell_Frontend {

    // Belső flag hogy ugyanazt a rule+source párt ne rendereljük kétszer
    private static $rendered_pairs = array();

    public static function init() {
        // Kosár oldali megjelenítés
        add_action( 'woocommerce_after_cart_table', array( __CLASS__, 'render_crosssell_offers' ), 20 );

        // AJAX kosárba adás (bejelentkezett és vendég)
        add_action( 'wp_ajax_mg_crosssell_add',        array( __CLASS__, 'ajax_add_to_cart' ) );
        add_action( 'wp_ajax_nopriv_mg_crosssell_add', array( __CLASS__, 'ajax_add_to_cart' ) );

        // Kedvezmény fee
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_crosssell_discount' ), 25 );

        // Session restore
        add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'restore_cart_item' ), 10, 2 );

        // Validate add_to_cart – cross-sell termékeket skip-eljük a VVM validátoron
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'skip_vvm_validation' ), 5, 5 );

        // Assets
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function register_assets() {
        $ver      = defined( 'MG_VERSION' ) ? MG_VERSION : '2.0.1';
        $base_url = plugins_url( '', dirname( __FILE__ ) . '/mockup-generator.php' );

        wp_register_style(
            'mg-crosssell',
            $base_url . '/assets/css/mg-crosssell.css',
            array(),
            $ver
        );

        wp_register_script(
            'mg-crosssell',
            $base_url . '/assets/js/mg-crosssell.js',
            array( 'jquery' ),
            $ver,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Megjelenítés
    // -------------------------------------------------------------------------

    public static function render_crosssell_offers() {
        if ( ! is_cart() ) {
            return;
        }
        if ( ! class_exists( 'MG_Crosssell_Manager' ) ) {
            return;
        }

        $cart = WC()->cart;
        if ( ! $cart ) {
            return;
        }

        $blocks = array(); // [rule_id => [source_cart_item_key => rendered_block]]

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            // Kihagyjuk a már meglévő cross-sell termékeket
            if ( ! empty( $cart_item['mg_crosssell_rule_id'] ) ) {
                continue;
            }
            // Csak design-os termékekre
            if ( empty( $cart_item['mg_design_id'] ) ) {
                continue;
            }

            $rules = MG_Crosssell_Manager::get_matching_rules( $cart_item );
            foreach ( $rules as $rule ) {
                $pair_key = $rule['id'] . '|' . $cart_item_key;
                if ( isset( self::$rendered_pairs[ $pair_key ] ) ) {
                    continue;
                }
                self::$rendered_pairs[ $pair_key ] = true;

                $block = self::build_offer_block( $rule, $cart_item, $cart_item_key );
                if ( $block ) {
                    $blocks[] = $block;
                }
            }
        }

        if ( empty( $blocks ) ) {
            return;
        }

        wp_enqueue_style( 'mg-crosssell' );
        wp_enqueue_script( 'mg-crosssell' );
        wp_localize_script( 'mg-crosssell', 'MG_Crosssell', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mg_crosssell_nonce' ),
            'i18n'     => array(
                'adding'       => __( 'Hozzáadás...', 'mockup-generator' ),
                'added'        => __( '✓ Kosárban van', 'mockup-generator' ),
                'error'        => __( 'Hiba történt, próbáld újra.', 'mockup-generator' ),
                'already'      => __( '✓ Már a kosárban', 'mockup-generator' ),
            ),
        ) );

        echo '<div class="mg-crosssell-wrapper">';
        foreach ( $blocks as $block ) {
            echo $block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</div>';
    }

    /**
     * Felépíti egy szabály ajánló blokkját egy forrás cart itemhez.
     */
    private static function build_offer_block( $rule, $source_cart_item, $source_cart_item_key ) {
        $design_id   = (int) ( $source_cart_item['mg_design_id'] ?? 0 );
        $preview_url = $source_cart_item['mg_preview_url'] ?? '';
        $headline    = $rule['headline'] !== '' ? $rule['headline'] : __( '🎁 Vidd magaddal a mintát!', 'mockup-generator' );
        $description = $rule['description'];
        $discount    = (float) $rule['discount_amount'];

        ob_start();
        ?>
        <div class="mg-crosssell-block">
            <div class="mg-crosssell-block__header">
                <span class="mg-crosssell-block__headline"><?php echo esc_html( $headline ); ?></span>
                <?php if ( $description ) : ?>
                    <span class="mg-crosssell-block__desc"><?php echo esc_html( $description ); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( $preview_url ) : ?>
                <div class="mg-crosssell-block__source-preview">
                    <img src="<?php echo esc_url( $preview_url ); ?>"
                         alt="<?php esc_attr_e( 'A mintád', 'mockup-generator' ); ?>"
                         class="mg-crosssell-block__source-img" />
                    <span class="mg-crosssell-block__source-label">
                        <?php esc_html_e( 'A te mintád', 'mockup-generator' ); ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="mg-crosssell-block__products">
                <?php foreach ( $rule['target_products'] as $target_product_id ) :
                    $product = wc_get_product( $target_product_id );
                    if ( ! $product || ! $product->is_purchasable() ) {
                        continue;
                    }

                    $already_in_cart = MG_Crosssell_Manager::is_already_in_cart( $target_product_id, $design_id, $rule['id'] );
                    $orig_price      = (float) $product->get_price();
                    $disc_price      = max( 0, $orig_price - $discount );
                    $image_id        = $product->get_image_id();
                    $image_url       = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src();
                    ?>
                    <div class="mg-crosssell-product" data-product-id="<?php echo esc_attr( $target_product_id ); ?>">
                        <div class="mg-crosssell-product__img-wrap">
                            <img src="<?php echo esc_url( $image_url ); ?>"
                                 alt="<?php echo esc_attr( $product->get_name() ); ?>"
                                 class="mg-crosssell-product__img" />
                            <?php if ( $discount > 0 ) : ?>
                                <span class="mg-crosssell-product__badge">
                                    -<?php echo wp_kses_post( wc_price( $discount ) ); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="mg-crosssell-product__info">
                            <span class="mg-crosssell-product__name"><?php echo esc_html( $product->get_name() ); ?></span>
                            <div class="mg-crosssell-product__price">
                                <?php if ( $discount > 0 ) : ?>
                                    <span class="mg-crosssell-product__price-orig">
                                        <?php echo wp_kses_post( wc_price( $orig_price ) ); ?>
                                    </span>
                                    <span class="mg-crosssell-product__price-disc">
                                        <?php echo wp_kses_post( wc_price( $disc_price ) ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="mg-crosssell-product__price-disc">
                                        <?php echo wp_kses_post( wc_price( $orig_price ) ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ( $already_in_cart ) : ?>
                                <button class="button mg-crosssell-btn mg-crosssell-btn--added" disabled>
                                    <?php esc_html_e( '✓ Már a kosárban', 'mockup-generator' ); ?>
                                </button>
                            <?php else : ?>
                                <button class="button mg-crosssell-btn mg-crosssell-btn--add"
                                        data-product-id="<?php echo esc_attr( $target_product_id ); ?>"
                                        data-source-key="<?php echo esc_attr( $source_cart_item_key ); ?>"
                                        data-rule-id="<?php echo esc_attr( $rule['id'] ); ?>">
                                    <?php esc_html_e( '+ Kosárba', 'mockup-generator' ); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX – kosárba adás
    // -------------------------------------------------------------------------

    public static function ajax_add_to_cart() {
        check_ajax_referer( 'mg_crosssell_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $source_key = sanitize_key( $_POST['source_cart_item_key'] ?? '' );
        $rule_id    = sanitize_key( $_POST['rule_id'] ?? '' );

        if ( ! $product_id || ! $source_key || ! $rule_id ) {
            wp_send_json_error( array( 'message' => 'Hiányzó paraméterek.' ) );
            return;
        }

        $cart = WC()->cart;
        if ( ! $cart ) {
            wp_send_json_error( array( 'message' => 'A kosár nem elérhető.' ) );
            return;
        }

        // Forrás cart item lekérdezése
        $cart_items  = $cart->get_cart();
        $source_item = isset( $cart_items[ $source_key ] ) ? $cart_items[ $source_key ] : null;
        if ( ! $source_item ) {
            wp_send_json_error( array( 'message' => 'A forrás kosártétel nem található.' ) );
            return;
        }

        // Szabály lekérdezése
        $rule = MG_Crosssell_Manager::get_rule( $rule_id );
        if ( ! $rule ) {
            wp_send_json_error( array( 'message' => 'A szabály nem található.' ) );
            return;
        }

        // Ellenőrizzük hogy a cél termék szerepel a szabályban
        if ( ! in_array( $product_id, array_map( 'intval', $rule['target_products'] ), true ) ) {
            wp_send_json_error( array( 'message' => 'Ez a termék nem szerepel a cross-sell szabályban.' ) );
            return;
        }

        $design_id = (int) ( $source_item['mg_design_id'] ?? 0 );

        // Már a kosárban van?
        if ( MG_Crosssell_Manager::is_already_in_cart( $product_id, $design_id, $rule_id ) ) {
            wp_send_json_error( array(
                'message'      => 'A termék már a kosárban van.',
                'already_added' => true,
            ) );
            return;
        }

        // Extra cart item adat
        $extra_data = array(
            'mg_crosssell_rule_id'        => $rule_id,
            'mg_crosssell_discount_amount' => (float) $rule['discount_amount'],
            'mg_crosssell_source_key'      => $source_key,
            'mg_design_id'                 => $design_id,
            'mg_preview_url'               => $source_item['mg_preview_url'] ?? '',
            // unique_key hogy ne merge-öljön más tételekkel
            'unique_key'                   => md5( 'mg_cs|' . $rule_id . '|' . $product_id . '|' . $design_id . '|' . $source_key ),
        );

        // Belső flag: a VVM validátor skip-pelése kereszteladáshoz
        self::set_crosssell_flag( true );

        $new_cart_key = $cart->add_to_cart( $product_id, 1, 0, array(), $extra_data );

        self::set_crosssell_flag( false );

        if ( $new_cart_key ) {
            wp_send_json_success( array(
                'message'       => 'Sikeresen hozzáadva!',
                'cart_item_key' => $new_cart_key,
            ) );
        } else {
            wp_send_json_error( array( 'message' => 'Nem sikerült hozzáadni a kosárhoz.' ) );
        }
    }

    // -------------------------------------------------------------------------
    // Session restore
    // -------------------------------------------------------------------------

    public static function restore_cart_item( $cart_item, $values ) {
        $fields = array(
            'mg_crosssell_rule_id',
            'mg_crosssell_discount_amount',
            'mg_crosssell_source_key',
            'mg_design_id',
            'mg_preview_url',
        );
        foreach ( $fields as $field ) {
            if ( isset( $values[ $field ] ) ) {
                $cart_item[ $field ] = $values[ $field ];
            }
        }
        return $cart_item;
    }

    // -------------------------------------------------------------------------
    // VVM validátor skip – cross-sell termékre nem kell type/color/size
    // -------------------------------------------------------------------------

    public static function skip_vvm_validation( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
        if ( self::get_crosssell_flag() ) {
            return $passed; // skip extra validation; WC maga kezeli az alap validációt
        }
        return $passed;
    }

    private static function set_crosssell_flag( $value ) {
        // Statikus property helyett option-t használunk hogy filter-en is átmenjen
        if ( $value ) {
            WC()->session->set( 'mg_crosssell_adding', 1 );
        } else {
            WC()->session->__unset( 'mg_crosssell_adding' );
        }
    }

    private static function get_crosssell_flag() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }
        return (bool) WC()->session->get( 'mg_crosssell_adding' );
    }

    // -------------------------------------------------------------------------
    // Kedvezmény fee
    // -------------------------------------------------------------------------

    public static function apply_crosssell_discount( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['mg_crosssell_discount_amount'] ) ) {
                continue;
            }
            $discount_per_item = (float) $cart_item['mg_crosssell_discount_amount'];
            if ( $discount_per_item <= 0 ) {
                continue;
            }
            $qty    = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
            $amount = $discount_per_item * $qty;

            $product_name = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product
                ? $cart_item['data']->get_name()
                : '';

            $label = $product_name
                ? sprintf( '🎁 %s (%s)', __( 'Cross-sell kedvezmény', 'mockup-generator' ), $product_name )
                : __( '🎁 Cross-sell kedvezmény', 'mockup-generator' );

            $cart->add_fee( $label, -1 * $amount, false );
        }
    }
}
