<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Express_Order_Flag
 *
 * Injects visual badges into the WooCommerce admin orders list.
 *
 * Performance:
 *  - JS reads ~20 order IDs from the DOM (current page only).
 *  - One AJAX call checks only those orders (not ALL orders in the DB).
 *  - Results are cached in order meta so repeated views are instant (no rescan).
 *
 * Badges are controlled per-surcharge in:
 *   Feláras opciók → Szerkesztés → ☑ Express / Prémium / Nagy nyomat jelzés
 */
class MG_Express_Order_Flag {

    const AJAX_ACTION = 'mg_check_order_badges';

    const BADGE_TYPES = [
        'is_express' => [
            'meta_key'   => '_mg_has_express',
            'label'      => '⚡ Express',
            'color_from' => '#ff8c00',
            'color_to'   => '#ff4500',
        ],
        'is_premium_material' => [
            'meta_key'   => '_mg_has_premium_material',
            'label'      => '★ Prémium',
            'color_from' => '#7b2ff7',
            'color_to'   => '#b721ff',
        ],
        'is_large_print' => [
            'meta_key'   => '_mg_has_large_print',
            'label'      => '◈ Nagy nyomat',
            'color_from' => '#0066cc',
            'color_to'   => '#00aaff',
        ],
    ];

    private static $names_cache = [];

    public static function init() {
        // Flag new orders at checkout (fast – runs once per order creation)
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'flag_on_create' ] );

        // Register AJAX handler
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_check' ] );

        // Enqueue assets on orders list only
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    /* ── Flag order at checkout ─────────────────────────────────────── */

    public static function flag_on_create( $order ) {
        if ( ! ( $order instanceof WC_Abstract_Order ) ) {
            return;
        }
        foreach ( self::BADGE_TYPES as $type_key => $def ) {
            $value = self::order_matches_type( $order, $type_key ) ? '1' : '0';
            $order->update_meta_data( $def['meta_key'], $value );
        }
        $order->save_meta_data();
    }

    /* ── AJAX: check a batch of order IDs (current page only) ───────── */

    public static function ajax_check() {
        check_ajax_referer( self::AJAX_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }

        $raw_ids   = isset( $_POST['order_ids'] ) ? wp_unslash( $_POST['order_ids'] ) : '[]';
        $order_ids = json_decode( $raw_ids, true );

        if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
            wp_send_json_success( [] );
        }

        $order_ids = array_map( 'absint', array_slice( $order_ids, 0, 100 ) ); // hard cap: 100
        $result    = array_fill_keys( array_keys( self::BADGE_TYPES ), [] );

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $needs_save = false;

            foreach ( self::BADGE_TYPES as $type_key => $def ) {
                // Check cached meta first (instant, no DB scan)
                $cached = $order->get_meta( $def['meta_key'], true );

                if ( $cached === '1' ) {
                    $result[ $type_key ][] = $order_id;
                    continue;
                }

                if ( $cached === '0' ) {
                    // Already checked, not a match
                    continue;
                }

                // No meta yet → scan fees + item meta, then save for next time
                $matches = self::order_matches_type( $order, $type_key );
                $order->update_meta_data( $def['meta_key'], $matches ? '1' : '0' );
                $needs_save = true;

                if ( $matches ) {
                    $result[ $type_key ][] = $order_id;
                }
            }

            if ( $needs_save ) {
                $order->save_meta_data();
            }
        }

        wp_send_json_success( $result );
    }

    /* ── Enqueue JS + CSS on orders list ────────────────────────────── */

    public static function enqueue( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
            return;
        }

        // Build badge HTML definitions (inline styles = no external CSS dependency)
        $badge_defs = [];
        foreach ( self::BADGE_TYPES as $type_key => $def ) {
            $badge_defs[ $type_key ] = sprintf(
                '<span style="display:inline-block;margin-top:4px;padding:2px 8px;background:linear-gradient(135deg,%s,%s);color:#fff;font-size:11px;font-weight:700;border-radius:3px;line-height:1.6;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.2)">%s</span>',
                esc_attr( $def['color_from'] ),
                esc_attr( $def['color_to'] ),
                esc_html( $def['label'] )
            );
        }

        // Inline script – no separate .js file needed
        $script = '(function(){
            var cfg = ' . wp_json_encode( [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'action'   => self::AJAX_ACTION,
                'nonce'    => wp_create_nonce( self::AJAX_ACTION ),
                'badges'   => $badge_defs,
            ] ) . ';

            document.addEventListener("DOMContentLoaded", function(){
                // Collect visible order IDs from the DOM (~20 per page)
                var ids = [];
                document.querySelectorAll("tr[id^=\'order-\']").forEach(function(row){
                    var m = row.id.match(/^order-(\d+)$/);
                    if(m) ids.push(parseInt(m[1],10));
                });
                if(!ids.length) return;

                var body = new URLSearchParams();
                body.append("action",    cfg.action);
                body.append("nonce",     cfg.nonce);
                body.append("order_ids", JSON.stringify(ids));

                fetch(cfg.ajax_url, {
                    method:"POST",
                    credentials:"same-origin",
                    headers:{"Content-Type":"application/x-www-form-urlencoded"},
                    body: body.toString()
                })
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    if(!resp||!resp.success||!resp.data) return;
                    Object.keys(resp.data).forEach(function(typeKey){
                        var html = cfg.badges[typeKey];
                        if(!html) return;
                        resp.data[typeKey].forEach(function(id){
                            var row = document.getElementById("order-"+id);
                            if(!row) return;
                            var cell = row.querySelector(".column-order_total")||row.querySelector("td.order_total");
                            if(cell && !cell.querySelector("[data-mg-badge=\'"+typeKey+"\']")){
                                var span = document.createElement("span");
                                span.setAttribute("data-mg-badge", typeKey);
                                span.innerHTML = "<br>"+html;
                                cell.appendChild(span);
                            }
                        });
                    });
                })
                .catch(function(){});
            });
        }());';

        wp_register_script( 'mg-order-badges', '', [], null, true );
        wp_enqueue_script( 'mg-order-badges' );
        wp_add_inline_script( 'mg-order-badges', $script );
    }

    /* ── Detection helpers ──────────────────────────────────────────── */

    private static function order_matches_type( WC_Abstract_Order $order, $type_key ) {
        $names = self::get_names_for_type( $type_key );
        if ( empty( $names ) ) {
            return false;
        }

        // Fees: "surcharge name (Product name)"
        foreach ( $order->get_fees() as $fee ) {
            $n = mb_strtolower( trim( $fee->get_name() ) );
            foreach ( $names as $name ) {
                if ( strpos( $n, $name ) === 0 ) {
                    return true;
                }
            }
        }

        // Item meta: key = surcharge name
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
