<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Bundle_Discount
 *
 * Automatikus mennyiségi kedvezményt alkalmaz a WooCommerce kosárra.
 * Támogat több, egymástól független kampányt (kategória + mg_product_type szűrővel).
 *
 * Beállítások (mg_bundle_discount wp_option):
 *   enabled      bool    – alap kedvezmény be/ki
 *   type         string  – 'fixed' | 'percent'
 *   qty2_amount  float   – alap kedvezmény 2 db esetén
 *   qty3_amount  float   – alap kedvezmény 3+ db esetén
 *   campaigns    array   – kampány tömbök (lásd: sanitize_campaigns)
 */
class MG_Bundle_Discount {

    const OPTION_KEY   = 'mg_bundle_discount';
    const FEE_NAME_KEY = 'mg_bundle_discount_fee';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init() {
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_discount' ), 20 );
        add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_discount_row_cart' ) );
        add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'render_discount_row_checkout' ) );
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'maybe_recalculate' ), 10 );
    }

    // -------------------------------------------------------------------------
    // Beállítások
    // -------------------------------------------------------------------------

    /**
     * Visszaadja a mentett beállításokat, hiányzó kulcsokra alapértékekkel egészítve ki.
     *
     * @return array
     */
    public static function get_settings() {
        $defaults = array(
            'enabled'     => true,
            'type'        => 'fixed',
            'qty2_amount' => 990.0,
            'qty3_amount' => 2480.0,
            'campaigns'   => array(),
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
            'campaigns'   => isset( $settings['campaigns'] ) ? self::sanitize_campaigns( $settings['campaigns'] ) : array(),
        );
        update_option( self::OPTION_KEY, $clean, false );
    }

    /**
     * Szanitálja a kampányok tömbjét.
     */
    private static function sanitize_campaigns( $campaigns ) {
        if ( ! is_array( $campaigns ) ) {
            return array();
        }

        $clean = array();
        foreach ( $campaigns as $campaign ) {
            if ( ! is_array( $campaign ) ) {
                continue;
            }

            // Sávok szanitálása
            $tiers = array();
            if ( ! empty( $campaign['tiers'] ) && is_array( $campaign['tiers'] ) ) {
                foreach ( $campaign['tiers'] as $tier ) {
                    $min_qty = isset( $tier['min_qty'] ) ? max( 1, intval( $tier['min_qty'] ) ) : 1;
                    $amount  = isset( $tier['amount'] )  ? max( 0.0, floatval( $tier['amount'] ) ) : 0.0;
                    if ( $min_qty >= 1 && $amount > 0 ) {
                        $tiers[] = array( 'min_qty' => $min_qty, 'amount' => $amount );
                    }
                }
                // Növekvő sorrendbe rendezés
                usort( $tiers, function( $a, $b ) { return $a['min_qty'] - $b['min_qty']; } );
            }

            // Kategóriák szanitálása
            $categories = array();
            if ( ! empty( $campaign['categories'] ) && is_array( $campaign['categories'] ) ) {
                $categories = array_values( array_filter( array_map( 'intval', $campaign['categories'] ) ) );
            }

            // mg_product_type slug-ok szanitálása
            $mg_types = array();
            if ( ! empty( $campaign['mg_types'] ) && is_array( $campaign['mg_types'] ) ) {
                $mg_types = array_values( array_filter( array_map( 'sanitize_key', $campaign['mg_types'] ) ) );
            }

            $clean[] = array(
                'id'         => sanitize_key( isset( $campaign['id'] ) && $campaign['id'] !== '' ? $campaign['id'] : uniqid( 'campaign_' ) ),
                'name'       => sanitize_text_field( isset( $campaign['name'] ) ? $campaign['name'] : '' ),
                'enabled'    => ! empty( $campaign['enabled'] ),
                'categories' => $categories,
                'mg_types'   => $mg_types,
                'tiers'      => $tiers,
            );
        }

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Kampány logika
    // -------------------------------------------------------------------------

    /**
     * Visszaadja az összes aktív, érvényes kampányt.
     *
     * @return array
     */
    public static function get_campaigns() {
        $settings = self::get_settings();
        return array_values( array_filter( $settings['campaigns'], function( $c ) {
            return ! empty( $c['enabled'] ) && ! empty( $c['tiers'] );
        } ) );
    }

    /**
     * Visszaadja a cart_itemre illő kampányt (vagy null-t ha egyik sem).
     * Ha több kampány is illene, a legmagasabb max. kedvezményű kampányt adja vissza.
     *
     * Illesztési logika:
     * - Ha a kampányban van categories szűrő: a terméknek BENNE kell lennie
     * - Ha a kampányban van mg_types szűrő: a termék mg_product_type-jának BENNE kell lennie
     * - Ha mindkét szűrő üres: minden termékre vonatkozik (globális kampány)
     *
     * @param array $cart_item
     * @return array|null
     */
    public static function get_item_campaign( $cart_item ) {
        $campaigns = self::get_campaigns();
        if ( empty( $campaigns ) ) {
            return null;
        }

        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        $mg_type    = isset( $cart_item['mg_product_type'] ) ? sanitize_key( $cart_item['mg_product_type'] ) : '';

        if ( ! $product_id ) {
            return null;
        }

        // Termék kategória ID-k (beleértve az összes szülő kategóriát)
        $product_cat_ids = wc_get_product_term_ids( $product_id, 'product_cat' );

        $best_campaign = null;
        $best_amount   = -1;

        foreach ( $campaigns as $campaign ) {
            // Kategória szűrő ellenőrzése
            if ( ! empty( $campaign['categories'] ) ) {
                if ( empty( $product_cat_ids ) || empty( array_intersect( $campaign['categories'], $product_cat_ids ) ) ) {
                    continue; // Nem illeszkedik
                }
            }

            // mg_product_type szűrő ellenőrzése
            if ( ! empty( $campaign['mg_types'] ) ) {
                if ( empty( $mg_type ) || ! in_array( $mg_type, $campaign['mg_types'], true ) ) {
                    continue; // Nem illeszkedik
                }
            }

            // Illeszkedik – a legjobb (legmagasabb max. kedvezmény) kampányt tartjuk
            $max_amount = 0.0;
            foreach ( $campaign['tiers'] as $tier ) {
                $max_amount = max( $max_amount, (float) $tier['amount'] );
            }

            if ( $max_amount > $best_amount ) {
                $best_amount   = $max_amount;
                $best_campaign = $campaign;
            }
        }

        return $best_campaign;
    }

    /**
     * Kiszámítja egy adott kampány kedvezményét a kosár alapján.
     *
     * @param array    $campaign
     * @param WC_Cart  $cart
     * @return float
     */
    public static function get_campaign_discount( $campaign, $cart ) {
        $qty = 0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $item_campaign = self::get_item_campaign( $cart_item );
            if ( $item_campaign && $item_campaign['id'] === $campaign['id'] ) {
                $qty += isset( $cart_item['quantity'] ) ? intval( $cart_item['quantity'] ) : 0;
            }
        }
        return self::get_discount_for_tiers( $qty, $campaign['tiers'] );
    }

    /**
     * Sáv-alapú kedvezmény kiszámítása.
     * A legmagasabb illeszkedő sáv összegét adja vissza.
     *
     * @param int   $qty
     * @param array $tiers  [['min_qty'=>int,'amount'=>float],...]
     * @return float
     */
    private static function get_discount_for_tiers( $qty, $tiers ) {
        $amount = 0.0;
        foreach ( $tiers as $tier ) {
            if ( $qty >= (int) $tier['min_qty'] ) {
                $amount = (float) $tier['amount'];
            }
        }
        return $amount;
    }

    /**
     * Visszaadja a kampány által NEM lefedett termékek darabszámát (alap kedvezményhez).
     *
     * @return int
     */
    public static function get_eligible_base_quantity() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        $campaigns = self::get_campaigns();
        $total     = 0;

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $item_campaign = ! empty( $campaigns ) ? self::get_item_campaign( $cart_item ) : null;
            if ( ! $item_campaign ) {
                $total += isset( $cart_item['quantity'] ) ? intval( $cart_item['quantity'] ) : 0;
            }
        }

        return $total;
    }

    /**
     * Visszaadja a kosárban lévő összes termék darabszámát (backward compat).
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
     * Kiszámítja az alap kedvezmény összegét.
     *
     * @param int   $qty
     * @param float $cart_subtotal
     * @return float
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
     * woocommerce_cart_calculate_fees – kedvezmények alkalmazása.
     * 1. Kampány kedvezmények (kategória/típus szűrőre illő termékek)
     * 2. Alap kedvezmény (kampány által nem lefedett termékekre)
     *
     * @param WC_Cart $cart
     */
    public static function apply_discount( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $settings = self::get_settings();

        // 1. Kampány kedvezmények
        $campaigns = self::get_campaigns();
        foreach ( $campaigns as $campaign ) {
            $amount = self::get_campaign_discount( $campaign, $cart );
            if ( $amount > 0 ) {
                $cart->add_fee(
                    sprintf( '🏷️ %s', esc_html( $campaign['name'] ) ),
                    -1 * $amount,
                    false // taxable = false
                );
            }
        }

        // 2. Alap kedvezmény – csak kampány által nem lefedett termékekre
        if ( ! $settings['enabled'] ) {
            return;
        }

        $base_qty = self::get_eligible_base_quantity();
        if ( $base_qty < 2 ) {
            return;
        }

        $subtotal = $cart->get_subtotal();
        $amount   = self::get_discount_amount( $base_qty, $subtotal );

        if ( $amount > 0 ) {
            $cart->add_fee(
                __( '🏷️ Mennyiségi kedvezmény', 'mockup-generator' ),
                -1 * $amount,
                false
            );
        }
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
     * A WC fee-k automatikusan megjelennek a kosár totals táblájában.
     * Ezek a metódusok visszafelé kompatibilitás miatt maradnak.
     */
    public static function render_discount_row_cart() {
        // WC fee-k natívan megjelennek – nincs szükség dupla sorra
    }

    public static function render_discount_row_checkout() {
        // WC fee-k natívan megjelennek – nincs szükség dupla sorra
    }

    // -------------------------------------------------------------------------
    // Segédfüggvények kampány bannerhöz (MG_Bundle_Discount_Banner használja)
    // -------------------------------------------------------------------------

    /**
     * Visszaadja hogy egy termék (product_id, mg_type) melyik kampányba illik.
     *
     * @param int    $product_id
     * @param string $mg_type
     * @return array|null
     */
    public static function get_product_campaign( $product_id, $mg_type = '' ) {
        $fake_cart_item = array(
            'product_id'       => (int) $product_id,
            'mg_product_type'  => sanitize_key( $mg_type ),
        );
        return self::get_item_campaign( $fake_cart_item );
    }

    /**
     * Visszaadja a kosárban lévő, adott kampányhoz tartozó termékek darabszámát.
     *
     * @param string $campaign_id
     * @return int
     */
    public static function get_campaign_qty_in_cart( $campaign_id ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }
        $qty = 0;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $c = self::get_item_campaign( $cart_item );
            if ( $c && $c['id'] === $campaign_id ) {
                $qty += intval( $cart_item['quantity'] );
            }
        }
        return $qty;
    }

    /**
     * Visszaadja a következő sávhoz szükséges darabszámot (és annak összegét).
     * Visszatér: ['needed' => int, 'amount' => float] vagy null ha nincs következő sáv.
     *
     * @param int   $current_qty
     * @param array $tiers
     * @return array|null
     */
    public static function get_next_tier( $current_qty, $tiers ) {
        foreach ( $tiers as $tier ) {
            if ( $current_qty < (int) $tier['min_qty'] ) {
                return array(
                    'needed' => (int) $tier['min_qty'] - $current_qty,
                    'min_qty' => (int) $tier['min_qty'],
                    'amount' => (float) $tier['amount'],
                );
            }
        }
        return null; // Már a legfelső sávban van
    }
}
