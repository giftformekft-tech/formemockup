<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Express_Order_Flag
 *
 * Detects orders that contain an "express" surcharge (is_express = true)
 * and shows a visual ⚡ Express badge next to the order amount in the admin orders list.
 *
 * The surcharge name is stored as order item meta key by MG_Surcharge_Frontend::add_order_item_meta.
 * We look up which surcharge IDs are flagged as express, then check whether any item
 * has a meta key matching one of those surcharge names.
 */
class MG_Express_Order_Flag {

    /** Runtime cache: array of surcharge names that are flagged is_express. */
    private static $express_names = null;

    /** Per-request cache: order_id → bool. */
    private static $order_cache = [];

    public static function init() {
        add_filter( 'woocommerce_admin_order_amount_html', [ __CLASS__, 'append_badge_to_amount' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
    }

    /**
     * Append the ⚡ Express badge after the formatted order amount HTML.
     */
    public static function append_badge_to_amount( $amount_html, $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            return $amount_html;
        }

        if ( self::order_has_express( $order ) ) {
            $badge = '<span class="mg-express-badge" title="' . esc_attr__( 'Express gyártás', 'mockup-generator' ) . '">⚡ Express</span>';
            $amount_html .= '<br>' . $badge;
        }

        return $amount_html;
    }

    /**
     * Returns the list of surcharge names (meta keys) that are flagged as is_express.
     * Result is cached for the lifetime of the request.
     *
     * @return string[]  Lowercase surcharge names.
     */
    private static function get_express_names() {
        if ( self::$express_names !== null ) {
            return self::$express_names;
        }

        self::$express_names = [];

        if ( ! class_exists( 'MG_Surcharge_Manager' ) ) {
            return self::$express_names;
        }

        foreach ( MG_Surcharge_Manager::get_surcharges( false ) as $surcharge ) {
            if ( ! empty( $surcharge['is_express'] ) && ! empty( $surcharge['name'] ) ) {
                self::$express_names[] = mb_strtolower( trim( $surcharge['name'] ) );
            }
        }

        return self::$express_names;
    }

    /**
     * Check whether any line item in the order has an express surcharge meta.
     *
     * @param WC_Abstract_Order $order
     * @return bool
     */
    private static function order_has_express( WC_Abstract_Order $order ) {
        $order_id = $order->get_id();

        if ( isset( self::$order_cache[ $order_id ] ) ) {
            return self::$order_cache[ $order_id ];
        }

        $express_names = self::get_express_names();
        $found         = false;

        if ( ! empty( $express_names ) ) {
            foreach ( $order->get_items() as $item ) {
                foreach ( $item->get_meta_data() as $meta ) {
                    $meta_data = $meta->get_data();
                    $key       = mb_strtolower( trim( (string) $meta_data['key'] ) );
                    if ( in_array( $key, $express_names, true ) ) {
                        $found = true;
                        break 2;
                    }
                }
            }
        }

        self::$order_cache[ $order_id ] = $found;
        return $found;
    }

    /**
     * Enqueue the badge stylesheet only on the WooCommerce orders admin screen.
     *
     * @param string $hook
     */
    public static function enqueue_styles( $hook ) {
        $is_orders_page = (
            $hook === 'edit.php'
            || $hook === 'woocommerce_page_wc-orders'
        );

        if ( ! $is_orders_page ) {
            return;
        }

        // Classic CPT mode: only for shop_order post type
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
