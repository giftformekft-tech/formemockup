<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MG_Crosssell_Frontend {

    private static $rendered_pairs   = array();
    private static $content_injected = false;

    public static function init() {
        // PHP alapú injekció – the_content filter, nincs JS DOM manipuláció
        add_filter( 'the_content', array( __CLASS__, 'append_to_cart_content' ), 20 );

        // Klasszikus shortcode kosár fallback
        add_action( 'woocommerce_after_cart_table', array( __CLASS__, 'render_crosssell_offers' ), 20 );

        // AJAX
        add_action( 'wp_ajax_mg_crosssell_add',        array( __CLASS__, 'ajax_add_to_cart' ) );
        add_action( 'wp_ajax_nopriv_mg_crosssell_add', array( __CLASS__, 'ajax_add_to_cart' ) );

        // Kedvezmény fee
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_crosssell_discount' ), 25 );

        // Session restore
        add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'restore_cart_item' ), 10, 2 );

        // Assets
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_on_cart' ) );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_on_cart() {
        if ( ! is_cart() ) {
            return;
        }
        $ver      = defined( 'MG_VERSION' ) ? MG_VERSION : '2.0.1';
        $base_url = plugins_url( '', dirname( __FILE__ ) . '/mockup-generator.php' );

        wp_enqueue_style(
            'mg-crosssell',
            $base_url . '/assets/css/mg-crosssell.css',
            array(),
            $ver
        );

        wp_enqueue_script(
            'mg-crosssell',
            $base_url . '/assets/js/mg-crosssell.js',
            array( 'jquery' ),
            $ver,
            true
        );

        wp_localize_script( 'mg-crosssell', 'MG_Crosssell', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mg_crosssell_nonce' ),
            'i18n'     => array(
                'adding'  => __( 'Hozzáadás...', 'mockup-generator' ),
                'added'   => __( '✓ Kosárban van', 'mockup-generator' ),
                'error'   => __( 'Hiba történt, próbáld újra.', 'mockup-generator' ),
                'already' => __( '✓ Már a kosárban', 'mockup-generator' ),
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Katalógus helper
    // -------------------------------------------------------------------------

    private static function get_catalog() {
        if ( function_exists( 'mgsc_get_products' ) ) {
            return mgsc_get_products();
        }
        if ( class_exists( 'MG_Variant_Display_Manager' )
            && method_exists( 'MG_Variant_Display_Manager', 'get_catalog_index' ) ) {
            return MG_Variant_Display_Manager::get_catalog_index();
        }
        return array();
    }

    // -------------------------------------------------------------------------
    // the_content filter – WC Blocks kosár után injektálja a cross-sell HTML-t
    // -------------------------------------------------------------------------

    public static function append_to_cart_content( $content ) {
        if ( ! is_cart() ) {
            return $content;
        }
        if ( self::$content_injected ) {
            return $content;
        }
        self::$content_injected = true;

        $html = self::get_crosssell_html();
        if ( ! $html ) {
            return $content;
        }

        return $content . $html;
    }

    // -------------------------------------------------------------------------
    // Klasszikus kosár (shortcode fallback)
    // -------------------------------------------------------------------------

    public static function render_crosssell_offers() {
        $html = self::get_crosssell_html();
        if ( $html ) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    // -------------------------------------------------------------------------
    // HTML generálás
    // -------------------------------------------------------------------------

    private static function get_crosssell_html() {
        if ( ! class_exists( 'MG_Crosssell_Manager' ) ) {
            return '';
        }
        $cart = WC()->cart;
        if ( ! $cart ) {
            return '';
        }

        $blocks = array();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( ! empty( $cart_item['mg_crosssell_rule_id'] ) ) {
                continue;
            }
            if ( empty( $cart_item['mg_product_type'] ) ) {
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
            return '';
        }

        return '<div class="mg-crosssell-wrapper">' . implode( '', $blocks ) . '</div>';
    }

    private static function build_offer_block( $rule, $source_cart_item, $source_cart_item_key ) {
        $source_product_id = (int) ( $source_cart_item['product_id'] ?? 0 );
        $design_id         = (int) ( $source_cart_item['mg_design_id'] ?? 0 );
        $preview_url       = $source_cart_item['mg_preview_url'] ?? '';
        $headline          = $rule['headline'] !== '' ? $rule['headline'] : __( '🎁 Vidd magaddal a mintát!', 'mockup-generator' );
        $description       = $rule['description'];
        $discount          = (float) $rule['discount_amount'];
        $catalog           = self::get_catalog();

        $product_obj = wc_get_product( $source_product_id );
        $source_sku  = $product_obj ? strtoupper( preg_replace( '/[^a-zA-Z0-9\-_]/', '', $product_obj->get_sku() ) ) : '';

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
                <?php foreach ( $rule['target_mg_types'] as $target_type_slug ) :
                    $type_data  = $catalog[ $target_type_slug ] ?? array();
                    $type_label = $type_data['label'] ?? $type_data['name'] ?? $target_type_slug;
                    $orig_price = isset( $type_data['price'] ) ? (float) $type_data['price'] : 0.0;
                    $disc_price = max( 0, $orig_price - $discount );

                    // Típus képe: SKU alapú mockup render (ugyanaz mint product page selector)
                    $type_img_url = '';
                    if ( $source_sku !== '' && class_exists( 'MG_Variant_Display_Manager' )
                        && method_exists( 'MG_Variant_Display_Manager', 'find_sku_render_url' ) ) {
                        $first_color = '';
                        if ( ! empty( $type_data['color_order'] ) ) {
                            $first_color = reset( $type_data['color_order'] );
                        } elseif ( ! empty( $type_data['colors'] ) ) {
                            $color_keys  = array_keys( $type_data['colors'] );
                            $first_color = reset( $color_keys );
                        }
                        if ( $first_color !== '' ) {
                            $type_img_url = MG_Variant_Display_Manager::find_sku_render_url(
                                $source_sku, $target_type_slug, $first_color
                            );
                        }
                    }
                    if ( ! $type_img_url && $product_obj ) {
                        $img_id       = $product_obj->get_image_id();
                        $type_img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : wc_placeholder_img_src();
                    }
                    if ( ! $type_img_url ) {
                        $type_img_url = wc_placeholder_img_src();
                    }

                    $already_in_cart = MG_Crosssell_Manager::is_already_in_cart(
                        $source_product_id, $target_type_slug, $design_id, $rule['id']
                    );
                    ?>
                    <div class="mg-crosssell-product" data-type-slug="<?php echo esc_attr( $target_type_slug ); ?>">
                        <div class="mg-crosssell-product__img-wrap">
                            <img src="<?php echo esc_url( $type_img_url ); ?>"
                                 alt="<?php echo esc_attr( $type_label ); ?>"
                                 class="mg-crosssell-product__img" />
                            <?php if ( $discount > 0 ) : ?>
                                <span class="mg-crosssell-product__badge">
                                    -<?php echo wp_kses_post( wc_price( $discount ) ); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="mg-crosssell-product__info">
                            <span class="mg-crosssell-product__name"><?php echo esc_html( $type_label ); ?></span>
                            <div class="mg-crosssell-product__price">
                                <?php if ( $discount > 0 && $orig_price > 0 ) : ?>
                                    <span class="mg-crosssell-product__price-orig">
                                        <?php echo wp_kses_post( wc_price( $orig_price ) ); ?>
                                    </span>
                                    <span class="mg-crosssell-product__price-disc">
                                        <?php echo wp_kses_post( wc_price( $disc_price ) ); ?>
                                    </span>
                                <?php elseif ( $orig_price > 0 ) : ?>
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
                                        data-target-type="<?php echo esc_attr( $target_type_slug ); ?>"
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

        $target_type = sanitize_key( $_POST['target_type'] ?? '' );
        $source_key  = sanitize_key( $_POST['source_cart_item_key'] ?? '' );
        $rule_id     = sanitize_key( $_POST['rule_id'] ?? '' );

        if ( ! $target_type || ! $source_key || ! $rule_id ) {
            wp_send_json_error( array( 'message' => 'Hiányzó paraméterek.' ) );
            return;
        }

        $cart        = WC()->cart;
        $cart_items  = $cart->get_cart();
        $source_item = $cart_items[ $source_key ] ?? null;

        if ( ! $source_item ) {
            wp_send_json_error( array( 'message' => 'A forrás kosártétel nem található.' ) );
            return;
        }

        $rule = MG_Crosssell_Manager::get_rule( $rule_id );
        if ( ! $rule || ! in_array( $target_type, $rule['target_mg_types'] ?? array(), true ) ) {
            wp_send_json_error( array( 'message' => 'A szabály nem érvényes.' ) );
            return;
        }

        $product_id = (int) ( $source_item['product_id'] ?? 0 );
        $design_id  = (int) ( $source_item['mg_design_id'] ?? 0 );

        if ( MG_Crosssell_Manager::is_already_in_cart( $product_id, $target_type, $design_id, $rule_id ) ) {
            wp_send_json_error( array( 'message' => 'Már a kosárban van.', 'already_added' => true ) );
            return;
        }

        $catalog   = self::get_catalog();
        $type_data = $catalog[ $target_type ] ?? array();

        $default_color = '';
        $default_size  = '';
        if ( ! empty( $type_data['color_order'] ) ) {
            $default_color = reset( $type_data['color_order'] );
        } elseif ( ! empty( $type_data['colors'] ) ) {
            $keys          = array_keys( $type_data['colors'] );
            $default_color = reset( $keys );
        }
        if ( ! empty( $type_data['sizes'] ) ) {
            $default_size = reset( $type_data['sizes'] );
        }

        $post_backup = $_POST;
        $_POST['mg_product_type']   = $target_type;
        $_POST['mg_color']          = $default_color;
        $_POST['mg_size']           = $default_size;
        $_POST['mg_design_id']      = $design_id;
        $_POST['mg_preview_url']    = '';
        $_POST['mg_render_version'] = '';

        $extra_data = array(
            'mg_crosssell_rule_id'         => $rule_id,
            'mg_crosssell_discount_amount' => (float) $rule['discount_amount'],
            'mg_crosssell_source_key'      => $source_key,
            'mg_design_id'                 => $design_id,
            'unique_key'                   => md5( 'mg_cs|' . $rule_id . '|' . $product_id . '|' . $target_type . '|' . $design_id ),
        );

        self::set_crosssell_flag( true );
        $new_cart_key = $cart->add_to_cart( $product_id, 1, 0, array(), $extra_data );
        self::set_crosssell_flag( false );
        $_POST = $post_backup;

        if ( $new_cart_key ) {
            wp_send_json_success( array( 'message' => 'Sikeresen hozzáadva!', 'cart_item_key' => $new_cart_key ) );
        } else {
            $notices = wc_get_notices( 'error' );
            $msg     = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ) : 'Nem sikerült hozzáadni.';
            wc_clear_notices();
            wp_send_json_error( array( 'message' => $msg ) );
        }
    }

    // -------------------------------------------------------------------------
    // Session restore
    // -------------------------------------------------------------------------

    public static function restore_cart_item( $cart_item, $values ) {
        foreach ( array( 'mg_crosssell_rule_id', 'mg_crosssell_discount_amount', 'mg_crosssell_source_key', 'mg_design_id' ) as $field ) {
            if ( isset( $values[ $field ] ) ) {
                $cart_item[ $field ] = $values[ $field ];
            }
        }
        return $cart_item;
    }

    // -------------------------------------------------------------------------
    // Session flag
    // -------------------------------------------------------------------------

    private static function set_crosssell_flag( $value ) {
        if ( function_exists( 'WC' ) && WC()->session ) {
            if ( $value ) {
                WC()->session->set( 'mg_crosssell_adding', 1 );
            } else {
                WC()->session->__unset( 'mg_crosssell_adding' );
            }
        }
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
            $amount    = (float) $cart_item['mg_crosssell_discount_amount'] * max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
            $catalog   = self::get_catalog();
            $type_slug = $cart_item['mg_product_type'] ?? '';
            $label_str = isset( $catalog[ $type_slug ]['label'] ) ? $catalog[ $type_slug ]['label'] : $type_slug;
            $label     = $label_str
                ? sprintf( '🎁 %s (%s)', __( 'Cross-sell kedvezmény', 'mockup-generator' ), $label_str )
                : __( '🎁 Cross-sell kedvezmény', 'mockup-generator' );
            $cart->add_fee( $label, -1 * $amount, false );
        }
    }
}
