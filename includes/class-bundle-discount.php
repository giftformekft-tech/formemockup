<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Bundle_Discount
 *
 * Automatikus mennyiségi kedvezményt alkalmaz a WooCommerce kosárra.
 *
 * Beállítások (mg_bundle_discount wp_option):
 *   enabled     bool    – be/ki
 *   type        string  – 'fixed' | 'percent'
 *   qty2_amount float   – kedvezmény 2 db esetén
 *   qty3_amount float   – kedvezmény 3+ db esetén
 */
class MG_Bundle_Discount {

    const OPTION_KEY   = 'mg_bundle_discount';
    const FEE_NAME_KEY = 'mg_bundle_discount_fee'; // belső azonosítóhoz

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init() {
        // Kedvezmény hozzáadása a kosárhoz (negatív fee)
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_discount' ), 20 );

        // Megjelenítő sor a kosár totals táblában
        add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_discount_row_cart' ) );

        // Megjelenítő sor a checkout review táblában
        add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'render_discount_row_checkout' ) );

        // AJAX kosárfrissítés esetén is újraszámolódjon
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'maybe_recalculate' ), 10 );
    }

    // -------------------------------------------------------------------------
    // Beállítások
    // -------------------------------------------------------------------------

    /**
     * Visszaadja a mentett beállításokat, hiányzó kulcsokra alapértékekkel egészítve ki.
     *
     * @return array{enabled: bool, type: string, qty2_amount: float, qty3_amount: float}
     */
    public static function get_settings() {
        $defaults = array(
            'enabled'     => true,
            'type'        => 'fixed',
            'qty2_amount' => 990.0,
            'qty3_amount' => 2480.0,
        );

        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Elmenti a beállításokat.
     *
     * @param array $settings
     */
    public static function save_settings( array $settings ) {
        $clean = array(
            'enabled'     => ! empty( $settings['enabled'] ),
            'type'        => ( isset( $settings['type'] ) && $settings['type'] === 'percent' ) ? 'percent' : 'fixed',
            'qty2_amount' => isset( $settings['qty2_amount'] ) ? max( 0.0, floatval( $settings['qty2_amount'] ) ) : 0.0,
            'qty3_amount' => isset( $settings['qty3_amount'] ) ? max( 0.0, floatval( $settings['qty3_amount'] ) ) : 0.0,
        );
        update_option( self::OPTION_KEY, $clean, false );
    }

    // -------------------------------------------------------------------------
    // Logika
    // -------------------------------------------------------------------------

    /**
     * Visszaadja a kosárban lévő összes termék darabszámát.
     *
     * @return int
     */
    public static function get_cart_quantity() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }
        $total = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            $total += isset( $item['quantity'] ) ? intval( $item['quantity'] ) : 0;
        }
        return $total;
    }

    /**
     * Kiszámítja a levonandó kedvezmény összegét (Ft-ban) az adott darabszám alapján.
     * Ha százalékos módban van, a kosár részösszegéből számol.
     *
     * @param int   $qty        Kosárban lévő összes db
     * @param float $cart_subtotal  Kosár részösszege (adó nélkül)
     * @return float  Levonandó összeg (mindig pozitív; 0 ha nincs kedvezmény)
     */
    public static function get_discount_amount( $qty, $cart_subtotal = 0.0 ) {
        if ( $qty < 2 ) {
            return 0.0;
        }

        $settings = self::get_settings();
        if ( ! $settings['enabled'] ) {
            return 0.0;
        }

        $raw = ( $qty >= 3 ) ? $settings['qty3_amount'] : $settings['qty2_amount'];

        if ( $settings['type'] === 'percent' ) {
            // százalék: a kosár részösszegéből számolunk
            $amount = $cart_subtotal * ( $raw / 100.0 );
        } else {
            $amount = $raw;
        }

        return max( 0.0, $amount );
    }

    // -------------------------------------------------------------------------
    // WooCommerce hookok
    // -------------------------------------------------------------------------

    /**
     * woocommerce_cart_calculate_fees – negatív fee hozzáadása.
     *
     * @param WC_Cart $cart
     */
    public static function apply_discount( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $qty = self::get_cart_quantity();
        if ( $qty < 2 ) {
            return;
        }

        $subtotal = $cart->get_subtotal(); // adómentes részösszeg
        $amount   = self::get_discount_amount( $qty, $subtotal );

        if ( $amount <= 0 ) {
            return;
        }

        // Negatív fee taxable=false, hogy ne befolyásolja az adóalapot
        $cart->add_fee(
            __( 'Mennyiségi kedvezmény', 'mockup-generator' ),
            -1 * $amount,
            false
        );
    }

    /**
     * woocommerce_before_calculate_totals – biztosítja az újraszámolást AJAX esetén.
     */
    public static function maybe_recalculate( $cart ) {
        // WC maga kezeli, ez csak hook regisztrálás helye – üres callback elegendő
    }

    // -------------------------------------------------------------------------
    // Megjelenítés
    // -------------------------------------------------------------------------

    /**
     * Megjeleníti a kedvezmény sort a kosár totals táblában.
     * (A fee során felüli dekorált sor.)
     */
    public static function render_discount_row_cart() {
        self::render_discount_row();
    }

    /**
     * Megjeleníti a kedvezmény sort a checkout order review táblában.
     */
    public static function render_discount_row_checkout() {
        self::render_discount_row();
    }

    /**
     * Közös megjelenítési logika.
     */
    private static function render_discount_row() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $qty      = self::get_cart_quantity();
        $subtotal = WC()->cart->get_subtotal();
        $amount   = self::get_discount_amount( $qty, $subtotal );

        if ( $amount <= 0 ) {
            return;
        }

        $settings = self::get_settings();
        $formatted = wc_price( $amount );

        ?>
        <tr class="mg-bundle-discount-row">
            <th><?php echo esc_html__( '🏷️ Mennyiségi kedvezmény', 'mockup-generator' ); ?></th>
            <td>
                <strong class="mg-bundle-discount-amount">
                    -<?php echo wp_kses_post( $formatted ); ?>
                    <span class="mg-bundle-discount-savings">
                        (<?php
                            printf(
                                /* translators: %s: megtakarítás összege */
                                esc_html__( '%s megtakarítás', 'mockup-generator' ),
                                wp_kses_post( $formatted )
                            );
                        ?>)
                    </span>
                </strong>
            </td>
        </tr>
        <style>
        .mg-bundle-discount-row th,
        .mg-bundle-discount-row td {
            color: #c0392b !important;
        }
        .mg-bundle-discount-savings {
            font-size: 0.88em;
            opacity: 0.9;
        }
        </style>
        <?php
    }
}
