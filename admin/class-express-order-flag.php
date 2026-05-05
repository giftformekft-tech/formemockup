<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MG_Express_Order_Flag {

    const META_KEY = '_mg_has_express';

    private static $express_names = null;

    public static function init() {
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'flag_on_create' ] );
        add_action( 'current_screen',                     [ __CLASS__, 'on_orders_screen' ] );
        add_action( 'admin_footer',                       [ __CLASS__, 'inject_script' ] );
        add_action( 'admin_head',                         [ __CLASS__, 'inline_styles' ] );
    }

    /* ── Checkout: flag new orders ─────────────────────────────────── */

    public static function flag_on_create( $order ) {
        if ( ! ( $order instanceof WC_Abstract_Order ) ) {
            return;
        }
        if ( self::has_express( $order ) ) {
            $order->update_meta_data( self::META_KEY, '1' );
            $order->save_meta_data();
        }
    }

    /* ── On orders screen: backfill old orders ─────────────────────── */

    public static function on_orders_screen( $screen ) {
        if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
            return;
        }

        // Check 30 un-flagged orders per page load
        $orders = wc_get_orders( [
            'limit'      => 30,
            'return'     => 'objects',
            'meta_query' => [ [ 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ] ],
        ] );

        foreach ( $orders as $order ) {
            $value = self::has_express( $order ) ? '1' : '0';
            $order->update_meta_data( self::META_KEY, $value );
            $order->save_meta_data();
        }
    }

    /* ── JS: inject badge into DOM ─────────────────────────────────── */

    public static function inject_script() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
            return;
        }

        $ids = wc_get_orders( [
            'meta_key'   => self::META_KEY,
            'meta_value' => '1',
            'limit'      => 2000,
            'return'     => 'ids',
        ] );

        if ( empty( $ids ) ) {
            return;
        }
        ?>
        <script>
        (function () {
            var ids   = <?php echo wp_json_encode( array_map( 'intval', $ids ) ); ?>;
            var badge = '<span class="mg-express-badge" title="Express gy\u00e1rt\u00e1s">&#9889; Express</span>';
            ids.forEach( function ( id ) {
                var row = document.getElementById( 'order-' + id );
                if ( ! row ) {
                    row = document.querySelector( '[data-order-id="' + id + '"]' );
                }
                if ( ! row ) return;
                var cell = row.querySelector( '.column-order_total' );
                if ( ! cell ) cell = row.querySelector( 'td.order_total' );
                if ( cell ) cell.innerHTML += '<br>' + badge;
            } );
        } )();
        </script>
        <?php
    }

    /* ── CSS ───────────────────────────────────────────────────────── */

    public static function inline_styles() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
            return;
        }
        echo '<style>.mg-express-badge{display:inline-block;margin-top:4px;padding:2px 8px;background:linear-gradient(135deg,#ff8c00,#ff4500);color:#fff;font-size:11px;font-weight:700;border-radius:3px;line-height:1.5;box-shadow:0 1px 3px rgba(255,69,0,.35);cursor:default}</style>';
    }

    /* ── Detection ─────────────────────────────────────────────────── */

    private static function has_express( WC_Abstract_Order $order ) {
        $names = self::express_names();
        if ( empty( $names ) ) {
            return false;
        }

        // Check fees first (format: "express 24 órás gyártás (Termék neve)")
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

    private static function express_names() {
        if ( self::$express_names !== null ) {
            return self::$express_names;
        }
        self::$express_names = [];
        if ( ! class_exists( 'MG_Surcharge_Manager' ) ) {
            return self::$express_names;
        }
        foreach ( MG_Surcharge_Manager::get_surcharges( false ) as $s ) {
            if ( ! empty( $s['is_express'] ) && ! empty( $s['name'] ) ) {
                self::$express_names[] = mb_strtolower( trim( $s['name'] ) );
            }
        }
        return self::$express_names;
    }
}
