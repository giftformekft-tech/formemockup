<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Express_Order_Flag
 *
 * Detects orders that contain the "express 24 órás gyártás" surcharge
 * and shows a visual ⚡ badge next to the order amount in the admin orders list.
 *
 * The surcharge name is stored as order item meta key (see MG_Surcharge_Frontend::add_order_item_meta).
 * We match it case-insensitively so minor naming differences don't break detection.
 */
class MG_Express_Order_Flag {

    /** The surcharge meta key to look for (as stored by MG_Surcharge_Frontend). */
    const SURCHARGE_NAME = 'express 24 órás gyártás';

    /** Cache: order_id → bool (has express). */
    private static $cache = [];

    public static function init() {
        // Badge in the order amount column
        add_filter( 'woocommerce_admin_order_amount_html', [ __CLASS__, 'append_badge_to_amount' ], 10, 2 );

        // Inject CSS (only on orders list screens)
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
    }

    /**
     * Append the ⚡ Express badge after the formatted order amount HTML.
     *
     * @param string   $amount_html  The existing HTML for the amount column.
     * @param WC_Order $order
     * @return string
     */
    public static function append_badge_to_amount( $amount_html, $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            return $amount_html;
        }

        if ( self::order_has_express( $order ) ) {
            $badge = '<span class="mg-express-badge" title="' . esc_attr__( 'Express 24 órás gyártás', 'mockup-generator' ) . '">⚡ Express</span>';
            $amount_html .= '<br>' . $badge;
        }

        return $amount_html;
    }

    /**
     * Check whether any line item in the order has the express surcharge meta.
     *
     * Result is cached per request to avoid redundant DB queries when
     * WooCommerce calls the filter multiple times per order row.
     *
     * @param WC_Abstract_Order $order
     * @return bool
     */
    private static function order_has_express( WC_Abstract_Order $order ) {
        $order_id = $order->get_id();

        if ( isset( self::$cache[ $order_id ] ) ) {
            return self::$cache[ $order_id ];
        }

        $needle = mb_strtolower( trim( self::SURCHARGE_NAME ) );
        $found  = false;

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            foreach ( $item->get_meta_data() as $meta ) {
                $meta_data = $meta->get_data();
                $key       = mb_strtolower( trim( (string) $meta_data['key'] ) );
                if ( $key === $needle ) {
                    $found = true;
                    break 2; // exit both loops
                }
            }
        }

        self::$cache[ $order_id ] = $found;
        return $found;
    }

    /**
     * Enqueue the badge stylesheet only on the WooCommerce orders admin screen.
     *
     * @param string $hook
     */
    public static function enqueue_styles( $hook ) {
        $is_orders_page = (
            $hook === 'edit.php'                          // classic CPT orders list
            || $hook === 'woocommerce_page_wc-orders'    // HPOS orders list
        );

        if ( ! $is_orders_page ) {
            return;
        }

        // Only load on shop_order post type (classic mode guard)
        if ( $hook === 'edit.php' ) {
            $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
            if ( $post_type !== 'shop_order' ) {
                return;
            }
        }

        $mg_ver = defined( 'MG_VERSION' ) ? MG_VERSION : '2.0.1';
        wp_enqueue_style(
            'mg-express-order-flag',
            plugins_url( '../assets/css/express-order-flag.css', __FILE__ ),
            [],
            $mg_ver
        );
    }
}
