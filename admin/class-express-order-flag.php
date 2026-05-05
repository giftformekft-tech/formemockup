<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Express_Order_Flag
 *
 * Injects visual badges into the WooCommerce admin orders list for orders
 * that contain specific surcharge types (express, premium material, large print).
 *
 * Badge types are defined in BADGE_TYPES and controlled via:
 *   Feláras opciók → Szerkesztés → badge checkboxok
 */
class MG_Express_Order_Flag {

    /**
     * Badge type definitions.
     *
     * key          = surcharge flag field name (is_*)
     * meta_key     = order meta key used to store the flag
     * label        = text shown in the badge
     * icon         = unicode character prefix
     * gradient     = CSS gradient (from, to)
     */
    const BADGE_TYPES = [
        'is_express' => [
            'meta_key'  => '_mg_has_express',
            'label'     => 'Express',
            'icon'      => '&#9889;',
            'gradient'  => [ '#ff8c00', '#ff4500' ],
        ],
        'is_premium_material' => [
            'meta_key'  => '_mg_has_premium_material',
            'label'     => 'Pr&eacute;mium',
            'icon'      => '&#9733;',
            'gradient'  => [ '#7b2ff7', '#b721ff' ],
        ],
        'is_large_print' => [
            'meta_key'  => '_mg_has_large_print',
            'label'     => 'Nagy nyomat',
            'icon'      => '&#9642;',
            'gradient'  => [ '#0066cc', '#00aaff' ],
        ],
    ];

    /** Runtime cache: type_key → [ lowercase surcharge names ] */
    private static $names_cache = [];

    public static function init() {
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'flag_on_create' ] );
        add_action( 'current_screen',                     [ __CLASS__, 'on_orders_screen' ] );
        add_action( 'admin_footer',                       [ __CLASS__, 'inject_script' ] );
        add_action( 'admin_head',                         [ __CLASS__, 'inline_styles' ] );
    }

    /* ── Checkout: flag new orders ──────────────────────────────────── */

    public static function flag_on_create( $order ) {
        if ( ! ( $order instanceof WC_Abstract_Order ) ) {
            return;
        }
        foreach ( self::BADGE_TYPES as $type_key => $badge ) {
            if ( self::order_matches_type( $order, $type_key ) ) {
                $order->update_meta_data( $badge['meta_key'], '1' );
            }
        }
        $order->save_meta_data();
    }

    /* ── Backfill old orders (30 per page load) ─────────────────────── */

    public static function on_orders_screen( $screen ) {
        if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
            return;
        }

        // Find orders missing ANY of our meta keys
        $orders = wc_get_orders( [
            'limit'      => 30,
            'return'     => 'objects',
            'meta_query' => [ [ 'key' => '_mg_has_express', 'compare' => 'NOT EXISTS' ] ],
        ] );

        foreach ( $orders as $order ) {
            foreach ( self::BADGE_TYPES as $type_key => $badge ) {
                $value = self::order_matches_type( $order, $type_key ) ? '1' : '0';
                $order->update_meta_data( $badge['meta_key'], $value );
            }
            $order->save_meta_data();
        }
    }

    /* ── JS badge injection ─────────────────────────────────────────── */

    public static function inject_script() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
            return;
        }

        $badge_data = [];

        foreach ( self::BADGE_TYPES as $type_key => $badge ) {
            $ids = wc_get_orders( [
                'meta_key'   => $badge['meta_key'],
                'meta_value' => '1',
                'limit'      => 2000,
                'return'     => 'ids',
            ] );

            if ( ! empty( $ids ) ) {
                $badge_data[] = [
                    'ids'      => array_map( 'intval', $ids ),
                    'html'     => '<span class="mg-order-badge mg-order-badge--' . esc_attr( $type_key ) . '">'
                                  . $badge['icon'] . ' ' . $badge['label'] . '</span>',
                ];
            }
        }

        if ( empty( $badge_data ) ) {
            return;
        }
        ?>
        <script>
        (function () {
            var badges = <?php echo wp_json_encode( $badge_data ); ?>;

            badges.forEach( function ( badge ) {
                badge.ids.forEach( function ( id ) {
                    var row = document.getElementById( 'order-' + id );
                    if ( ! row ) row = document.querySelector( '[data-order-id="' + id + '"]' );
                    if ( ! row ) return;

                    var cell = row.querySelector( '.column-order_total' );
                    if ( ! cell ) cell = row.querySelector( 'td.order_total' );
                    if ( cell ) cell.innerHTML += '<br>' + badge.html;
                } );
            } );
        } )();
        </script>
        <?php
    }

    /* ── Inline CSS ─────────────────────────────────────────────────── */

    public static function inline_styles() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
            return;
        }

        $css = '.mg-order-badge{display:inline-block;margin-top:4px;padding:2px 8px;color:#fff;font-size:11px;font-weight:700;border-radius:3px;line-height:1.5;cursor:default;white-space:nowrap}';

        foreach ( self::BADGE_TYPES as $type_key => $badge ) {
            $css .= sprintf(
                '.mg-order-badge--%s{background:linear-gradient(135deg,%s,%s);box-shadow:0 1px 3px rgba(0,0,0,.25)}',
                esc_attr( $type_key ),
                $badge['gradient'][0],
                $badge['gradient'][1]
            );
        }

        echo '<style id="mg-order-badges-css">' . $css . '</style>';
    }

    /* ── Detection helpers ──────────────────────────────────────────── */

    private static function order_matches_type( WC_Abstract_Order $order, $type_key ) {
        $names = self::get_names_for_type( $type_key );
        if ( empty( $names ) ) {
            return false;
        }

        // Check fees: "surcharge name (Product name)"
        foreach ( $order->get_fees() as $fee ) {
            $n = mb_strtolower( trim( $fee->get_name() ) );
            foreach ( $names as $name ) {
                if ( strpos( $n, $name ) === 0 ) {
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
