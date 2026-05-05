<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Express_Order_Flag
 *
 * Injects visual badges (⚡ Express / ★ Prémium / ◈ Nagy nyomat) into the
 * WooCommerce admin orders list for orders containing flagged surcharges.
 *
 * Approach:
 *  1. Page loads → JS collects all visible order row IDs from the DOM.
 *  2. JS sends them via AJAX to the server.
 *  3. Server checks each order's fees + item meta against flagged surcharge names.
 *  4. Server returns which IDs need which badge.
 *  5. JS injects coloured badges into the amount column.
 *
 * Which surcharges are flagged is set in:
 *   Feláras opciók → Szerkesztés → ☑ Express / Prémium / Nagy nyomat jelzés
 */
class MG_Express_Order_Flag {

    const AJAX_ACTION = 'mg_check_order_badges';

    /** Badge type definitions: surcharge flag → visual badge config. */
    const BADGE_TYPES = [
        'is_express'          => [ 'label' => 'Express',    'icon' => '⚡', 'color_from' => '#ff8c00', 'color_to' => '#ff4500' ],
        'is_premium_material' => [ 'label' => 'Prémium',   'icon' => '★', 'color_from' => '#7b2ff7', 'color_to' => '#b721ff' ],
        'is_large_print'      => [ 'label' => 'Nagy nyomat','icon' => '◈', 'color_from' => '#0066cc', 'color_to' => '#00aaff' ],
    ];

    /** Per-request cache: type_key → [ lowercase surcharge names ]. */
    private static $names_cache = [];

    public static function init() {
        // AJAX handler (logged-in users only)
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_check' ] );

        // Enqueue JS + CSS on orders list screens
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /* ── Asset enqueue ──────────────────────────────────────────────── */

    public static function enqueue_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $is_orders = in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true );
        if ( ! $is_orders ) {
            return;
        }

        // Build badge definitions for JS
        $badge_defs = [];
        foreach ( self::BADGE_TYPES as $type_key => $badge ) {
            $badge_defs[ $type_key ] = [
                'html' => sprintf(
                    '<span class="mg-order-badge mg-order-badge--%s" style="background:linear-gradient(135deg,%s,%s)">%s %s</span>',
                    esc_attr( $type_key ),
                    esc_attr( $badge['color_from'] ),
                    esc_attr( $badge['color_to'] ),
                    $badge['icon'],
                    esc_html( $badge['label'] )
                ),
            ];
        }

        wp_register_script(
            'mg-order-badges',
            plugins_url( '../assets/js/order-badges.js', __FILE__ ),
            [],
            defined( 'MG_VERSION' ) ? MG_VERSION : '1.0',
            true
        );

        wp_localize_script( 'mg-order-badges', 'MgOrderBadges', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'action'   => self::AJAX_ACTION,
            'nonce'    => wp_create_nonce( self::AJAX_ACTION ),
            'badges'   => $badge_defs,
        ] );

        wp_enqueue_script( 'mg-order-badges' );

        // Inline CSS (no external file needed)
        wp_add_inline_style( 'woocommerce_admin_styles',
            '.mg-order-badge{display:inline-block;margin-top:4px;padding:2px 7px;color:#fff;font-size:11px;font-weight:700;border-radius:3px;line-height:1.6;cursor:default;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.2)}'
        );
    }

    /* ── AJAX handler ───────────────────────────────────────────────── */

    public static function ajax_check() {
        check_ajax_referer( self::AJAX_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }

        $raw_ids = isset( $_POST['order_ids'] ) ? wp_unslash( $_POST['order_ids'] ) : '[]';
        $order_ids = json_decode( $raw_ids, true );

        if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
            wp_send_json_success( [] );
        }

        $order_ids = array_map( 'absint', $order_ids );
        $result    = [];

        foreach ( self::BADGE_TYPES as $type_key => $badge ) {
            $result[ $type_key ] = [];
        }

        foreach ( $order_ids as $order_id ) {
            if ( ! $order_id ) {
                continue;
            }
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            foreach ( self::BADGE_TYPES as $type_key => $badge ) {
                if ( self::order_matches_type( $order, $type_key ) ) {
                    $result[ $type_key ][] = $order_id;
                }
            }
        }

        wp_send_json_success( $result );
    }

    /* ── Detection ──────────────────────────────────────────────────── */

    private static function order_matches_type( WC_Abstract_Order $order, $type_key ) {
        $names = self::get_names_for_type( $type_key );
        if ( empty( $names ) ) {
            return false;
        }

        // Check order fees: "surcharge name (Product name)"
        foreach ( $order->get_fees() as $fee ) {
            $fee_name = mb_strtolower( trim( $fee->get_name() ) );
            foreach ( $names as $name ) {
                if ( strpos( $fee_name, $name ) === 0 ) {
                    return true;
                }
            }
        }

        // Fallback: item meta key = surcharge name
        foreach ( $order->get_items() as $item ) {
            foreach ( $item->get_meta_data() as $meta ) {
                $d = $meta->get_data();
                if ( in_array( mb_strtolower( trim( (string) $d['key'] ) ), $names, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function get_names_for_type( $type_key ) {
        if ( isset( self::$names_cache[ $type_key ] ) ) {
            return self::$names_cache[ $type_key ];
        }

        self::$names_cache[ $type_key ] = [];

        if ( ! class_exists( 'MG_Surcharge_Manager' ) ) {
            return self::$names_cache[ $type_key ];
        }

        foreach ( MG_Surcharge_Manager::get_surcharges( false ) as $s ) {
            if ( ! empty( $s[ $type_key ] ) && ! empty( $s['name'] ) ) {
                self::$names_cache[ $type_key ][] = mb_strtolower( trim( $s['name'] ) );
            }
        }

        return self::$names_cache[ $type_key ];
    }
}
