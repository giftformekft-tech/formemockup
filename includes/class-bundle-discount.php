<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Bundle_Discount
 *
 * Automatikus kedvezményt alkalmaz a WooCommerce kosárra.
 * Kétféle küszöb-típus: darabszám (qty) vagy összeghatár (amount).
 *
 * Beállítások (mg_bundle_discount wp_option):
 *   enabled                 bool    – alap kedvezmény be/ki
 *   type                    string  – 'fixed' | 'percent'
 *   base_threshold_type     string  – 'qty' | 'amount'
 *   qty2_amount             float   – alap kedvezmény 2 db / 1. összeg-sáv esetén
 *   qty3_amount             float   – alap kedvezmény 3+ db / 2. összeg-sáv esetén
 *   base_amount2_threshold  float   – 1. összeg-küszöb (base, amount módban)
 *   base_amount3_threshold  float   – 2. összeg-küszöb (base, amount módban)
 *   campaigns               array   – kampány tömbök
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

    public static function get_settings() {
        $defaults = array(
            'enabled'                => true,
            'type'                   => 'fixed',
            'base_threshold_type'    => 'qty',
            'qty2_amount'            => 990.0,
            'qty3_amount'            => 2480.0,
            'base_amount2_threshold' => 10000.0,
            'base_amount3_threshold' => 20000.0,
            'campaigns'              => array(),
        );

        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, $defaults );
    }

    public static function save_settings( array $settings ) {
        $clean = array(
            'enabled'                => ! empty( $settings['enabled'] ),
            'type'                   => ( isset( $settings['type'] ) && $settings['type'] === 'percent' ) ? 'percent' : 'fixed',
            'base_threshold_type'    => ( isset( $settings['base_threshold_type'] ) && $settings['base_threshold_type'] === 'amount' ) ? 'amount' : 'qty',
            'qty2_amount'            => isset( $settings['qty2_amount'] ) ? max( 0.0, floatval( $settings['qty2_amount'] ) ) : 0.0,
            'qty3_amount'            => isset( $settings['qty3_amount'] ) ? max( 0.0, floatval( $settings['qty3_amount'] ) ) : 0.0,
            'base_amount2_threshold' => isset( $settings['base_amount2_threshold'] ) ? max( 0.0, floatval( $settings['base_amount2_threshold'] ) ) : 0.0,
            'base_amount3_threshold' => isset( $settings['base_amount3_threshold'] ) ? max( 0.0, floatval( $settings['base_amount3_threshold'] ) ) : 0.0,
            'campaigns'              => isset( $settings['campaigns'] ) ? self::sanitize_campaigns( $settings['campaigns'] ) : array(),
        );
        update_option( self::OPTION_KEY, $clean, false );
    }

    private static function sanitize_campaigns( $campaigns ) {
        if ( ! is_array( $campaigns ) ) {
            return array();
        }

        $clean = array();
        foreach ( $campaigns as $campaign ) {
            if ( ! is_array( $campaign ) ) {
                continue;
            }

            $threshold_type = ( isset( $campaign['threshold_type'] ) && $campaign['threshold_type'] === 'amount' ) ? 'amount' : 'qty';

            $tiers = array();
            if ( ! empty( $campaign['tiers'] ) && is_array( $campaign['tiers'] ) ) {
                foreach ( $campaign['tiers'] as $tier ) {
                    $amount = isset( $tier['amount'] ) ? max( 0.0, floatval( $tier['amount'] ) ) : 0.0;
                    if ( $threshold_type === 'amount' ) {
                        $min_amount = isset( $tier['min_amount'] ) ? max( 0.0, floatval( $tier['min_amount'] ) ) : 0.0;
                        if ( $min_amount >= 0 && $amount > 0 ) {
                            $tiers[] = array( 'min_amount' => $min_amount, 'amount' => $amount );
                        }
                    } else {
                        $min_qty = isset( $tier['min_qty'] ) ? max( 1, intval( $tier['min_qty'] ) ) : 1;
                        if ( $min_qty >= 1 && $amount > 0 ) {
                            $tiers[] = array( 'min_qty' => $min_qty, 'amount' => $amount );
                        }
                    }
                }
                if ( $threshold_type === 'amount' ) {
                    usort( $tiers, function( $a, $b ) { return $a['min_amount'] <=> $b['min_amount']; } );
                } else {
                    usort( $tiers, function( $a, $b ) { return $a['min_qty'] - $b['min_qty']; } );
                }
            }

            $categories = array();
            if ( ! empty( $campaign['categories'] ) && is_array( $campaign['categories'] ) ) {
                $categories = array_values( array_filter( array_map( 'intval', $campaign['categories'] ) ) );
            }

            $mg_types = array();
            if ( ! empty( $campaign['mg_types'] ) && is_array( $campaign['mg_types'] ) ) {
                $mg_types = array_values( array_filter( array_map( 'sanitize_key', $campaign['mg_types'] ) ) );
            }

            $clean[] = array(
                'id'             => sanitize_key( isset( $campaign['id'] ) && $campaign['id'] !== '' ? $campaign['id'] : uniqid( 'campaign_' ) ),
                'name'           => sanitize_text_field( isset( $campaign['name'] ) ? $campaign['name'] : '' ),
                'enabled'        => ! empty( $campaign['enabled'] ),
                'threshold_type' => $threshold_type,
                'categories'     => $categories,
                'mg_types'       => $mg_types,
                'tiers'          => $tiers,
            );
        }

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Kampány logika
    // -------------------------------------------------------------------------

    public static function get_campaigns() {
        $settings = self::get_settings();
        return array_values( array_filter( $settings['campaigns'], function( $c ) {
            return ! empty( $c['enabled'] ) && ! empty( $c['tiers'] );
        } ) );
    }

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

        $product_cat_ids = wc_get_product_term_ids( $product_id, 'product_cat' );

        $best_campaign = null;
        $best_amount   = -1;

        foreach ( $campaigns as $campaign ) {
            if ( ! empty( $campaign['categories'] ) ) {
                if ( empty( $product_cat_ids ) || empty( array_intersect( $campaign['categories'], $product_cat_ids ) ) ) {
                    continue;
                }
            }

            if ( ! empty( $campaign['mg_types'] ) ) {
                if ( empty( $mg_type ) || ! in_array( $mg_type, $campaign['mg_types'], true ) ) {
                    continue;
                }
            }

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

    public static function get_campaign_discount( $campaign, $cart ) {
        $threshold_type = isset( $campaign['threshold_type'] ) ? $campaign['threshold_type'] : 'qty';

        if ( $threshold_type === 'amount' ) {
            $subtotal = self::get_campaign_amount_in_cart( $campaign['id'], $cart );
            return self::get_discount_for_amount_tiers( $subtotal, $campaign['tiers'] );
        }

        $qty = 0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $item_campaign = self::get_item_campaign( $cart_item );
            if ( $item_campaign && $item_campaign['id'] === $campaign['id'] ) {
                $qty += isset( $cart_item['quantity'] ) ? intval( $cart_item['quantity'] ) : 0;
            }
        }
        return self::get_discount_for_tiers( $qty, $campaign['tiers'] );
    }

    private static function get_discount_for_tiers( $qty, $tiers ) {
        $amount = 0.0;
        foreach ( $tiers as $tier ) {
            if ( $qty >= (int) $tier['min_qty'] ) {
                $amount = (float) $tier['amount'];
            }
        }
        return $amount;
    }

    private static function get_discount_for_amount_tiers( $cart_amount, $tiers ) {
        $amount = 0.0;
        foreach ( $tiers as $tier ) {
            if ( $cart_amount >= (float) $tier['min_amount'] ) {
                $amount = (float) $tier['amount'];
            }
        }
        return $amount;
    }

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
     * Kampány által nem lefedett tételek kosárösszege (alap, amount módhoz).
     */
    public static function get_eligible_base_amount() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0.0;
        }

        $campaigns = self::get_campaigns();
        $total     = 0.0;

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $item_campaign = ! empty( $campaigns ) ? self::get_item_campaign( $cart_item ) : null;
            if ( ! $item_campaign ) {
                $product  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
                $price    = $product instanceof WC_Product ? (float) $product->get_price() : 0.0;
                $qty      = isset( $cart_item['quantity'] ) ? intval( $cart_item['quantity'] ) : 0;
                $total   += $price * $qty;
            }
        }

        return $total;
    }

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
     * Adott kampányhoz tartozó tételek kosárösszege (összeg-alapú kampányhoz).
     */
    public static function get_campaign_amount_in_cart( $campaign_id, $cart = null ) {
        if ( $cart === null ) {
            if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                return 0.0;
            }
            $cart = WC()->cart;
        }
        $total = 0.0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $c = self::get_item_campaign( $cart_item );
            if ( $c && $c['id'] === $campaign_id ) {
                $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
                $price   = $product instanceof WC_Product ? (float) $product->get_price() : 0.0;
                $qty     = isset( $cart_item['quantity'] ) ? intval( $cart_item['quantity'] ) : 0;
                $total  += $price * $qty;
            }
        }
        return $total;
    }

    public static function get_discount_amount( $qty, $cart_subtotal = 0.0 ) {
        $settings = self::get_settings();
        if ( ! $settings['enabled'] ) {
            return 0.0;
        }

        $threshold_type = isset( $settings['base_threshold_type'] ) ? $settings['base_threshold_type'] : 'qty';

        if ( $threshold_type === 'amount' ) {
            $t2 = (float) $settings['base_amount2_threshold'];
            $t3 = (float) $settings['base_amount3_threshold'];
            $d2 = (float) $settings['qty2_amount'];
            $d3 = (float) $settings['qty3_amount'];

            if ( $t3 > 0 && $cart_subtotal >= $t3 ) {
                $raw = $d3;
            } elseif ( $t2 > 0 && $cart_subtotal >= $t2 ) {
                $raw = $d2;
            } else {
                return 0.0;
            }

            if ( $settings['type'] === 'percent' ) {
                return max( 0.0, $cart_subtotal * ( $raw / 100.0 ) );
            }
            return max( 0.0, $raw );
        }

        // qty mode
        if ( $qty < 2 ) {
            return 0.0;
        }
        $raw = ( $qty >= 3 ) ? $settings['qty3_amount'] : $settings['qty2_amount'];

        if ( $settings['type'] === 'percent' ) {
            return max( 0.0, $cart_subtotal * ( $raw / 100.0 ) );
        }
        return max( 0.0, $raw );
    }

    // -------------------------------------------------------------------------
    // WooCommerce hookok
    // -------------------------------------------------------------------------

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
                    false
                );
            }
        }

        // 2. Alap kedvezmény – csak kampány által nem lefedett termékekre
        if ( ! $settings['enabled'] ) {
            return;
        }

        $threshold_type = isset( $settings['base_threshold_type'] ) ? $settings['base_threshold_type'] : 'qty';

        if ( $threshold_type === 'amount' ) {
            $base_amount = self::get_eligible_base_amount();
            $subtotal    = $cart->get_subtotal();
            $amount      = self::get_discount_amount( 0, $base_amount );
        } else {
            $base_qty = self::get_eligible_base_quantity();
            if ( $base_qty < 2 ) {
                return;
            }
            $subtotal = $cart->get_subtotal();
            $amount   = self::get_discount_amount( $base_qty, $subtotal );
        }

        if ( $amount > 0 ) {
            $cart->add_fee(
                __( '🏷️ Mennyiségi kedvezmény', 'mockup-generator' ),
                -1 * $amount,
                false
            );
        }
    }

    public static function maybe_recalculate( $cart ) {
        // WC maga kezeli – hook regisztráció helye
    }

    // -------------------------------------------------------------------------
    // Megjelenítés
    // -------------------------------------------------------------------------

    public static function render_discount_row_cart() {
        // WC fee-k natívan megjelennek
    }

    public static function render_discount_row_checkout() {
        // WC fee-k natívan megjelennek
    }

    // -------------------------------------------------------------------------
    // Segédfüggvények kampány bannerhöz
    // -------------------------------------------------------------------------

    public static function get_product_campaign( $product_id, $mg_type = '' ) {
        $fake_cart_item = array(
            'product_id'      => (int) $product_id,
            'mg_product_type' => sanitize_key( $mg_type ),
        );
        return self::get_item_campaign( $fake_cart_item );
    }

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
     * Következő sáv db-alapú kampányhoz.
     * Visszatér: ['needed'=>int,'min_qty'=>int,'amount'=>float] vagy null.
     */
    public static function get_next_tier( $current_qty, $tiers ) {
        foreach ( $tiers as $tier ) {
            if ( $current_qty < (int) $tier['min_qty'] ) {
                return array(
                    'needed'  => (int) $tier['min_qty'] - $current_qty,
                    'min_qty' => (int) $tier['min_qty'],
                    'amount'  => (float) $tier['amount'],
                );
            }
        }
        return null;
    }

    /**
     * Következő sáv összeg-alapú kampányhoz.
     * Visszatér: ['needed'=>float,'min_amount'=>float,'amount'=>float] vagy null.
     */
    public static function get_next_amount_tier( $current_amount, $tiers ) {
        foreach ( $tiers as $tier ) {
            if ( $current_amount < (float) $tier['min_amount'] ) {
                return array(
                    'needed'     => (float) $tier['min_amount'] - $current_amount,
                    'min_amount' => (float) $tier['min_amount'],
                    'amount'     => (float) $tier['amount'],
                );
            }
        }
        return null;
    }
}
