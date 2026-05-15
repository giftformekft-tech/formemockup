<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Crosssell_Manager
 *
 * Cross-sell szabályok tárolása és lekérdezése.
 * Egy szabály meghatározza: melyik forrás kategóriájú/típusú termék
 * kosárban léte esetén melyik célterméket ajánljuk fel kedvezménnyel.
 *
 * Option: mg_crosssell_rules (array of rule arrays)
 */
class MG_Crosssell_Manager {

    const OPTION_KEY = 'mg_crosssell_rules';

    // -------------------------------------------------------------------------
    // Beállítások
    // -------------------------------------------------------------------------

    public static function get_rules() {
        $rules = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $rules ) ) {
            return array();
        }
        return array_values( array_filter( $rules, function( $r ) {
            return is_array( $r ) && ! empty( $r['id'] );
        } ) );
    }

    public static function save_rules( array $rules ) {
        update_option( self::OPTION_KEY, self::sanitize_rules( $rules ), false );
    }

    public static function get_rule( $rule_id ) {
        $rule_id = sanitize_key( $rule_id );
        foreach ( self::get_rules() as $rule ) {
            if ( $rule['id'] === $rule_id ) {
                return $rule;
            }
        }
        return null;
    }

    public static function sanitize_rules( $rules ) {
        if ( ! is_array( $rules ) ) {
            return array();
        }

        $clean = array();
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            // Forrás kategóriák
            $source_cats = array();
            if ( ! empty( $rule['source_cats'] ) && is_array( $rule['source_cats'] ) ) {
                $source_cats = array_values( array_filter( array_map( 'intval', $rule['source_cats'] ) ) );
            }

            // Forrás mg_types
            $source_mg_types = array();
            if ( ! empty( $rule['source_mg_types'] ) && is_array( $rule['source_mg_types'] ) ) {
                $source_mg_types = array_values( array_filter( array_map( 'sanitize_key', $rule['source_mg_types'] ) ) );
            }

            // Cél termékek
            $target_products = array();
            if ( ! empty( $rule['target_products'] ) && is_array( $rule['target_products'] ) ) {
                $target_products = array_values( array_filter( array_map( 'intval', $rule['target_products'] ) ) );
            }

            $clean[] = array(
                'id'               => sanitize_key( isset( $rule['id'] ) && $rule['id'] !== '' ? $rule['id'] : uniqid( 'cs_' ) ),
                'name'             => sanitize_text_field( $rule['name'] ?? '' ),
                'enabled'          => ! empty( $rule['enabled'] ),
                'source_cats'      => $source_cats,
                'source_mg_types'  => $source_mg_types,
                'target_products'  => $target_products,
                'discount_amount'  => max( 0.0, floatval( $rule['discount_amount'] ?? 0 ) ),
                'headline'         => sanitize_text_field( $rule['headline'] ?? '' ),
                'description'      => sanitize_textarea_field( $rule['description'] ?? '' ),
            );
        }

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Illesztési logika
    // -------------------------------------------------------------------------

    /**
     * Visszaadja azokat az aktív szabályokat, amelyek illeszkednek az adott cart itemre.
     * Illesztés: ha a termék benne van a source_cats-ban ÉS (ha meg van adva) a source_mg_types-ban.
     *
     * @param array $cart_item
     * @return array  Illeszkedő szabályok tömbje
     */
    public static function get_matching_rules( $cart_item ) {
        $rules = self::get_rules();
        if ( empty( $rules ) ) {
            return array();
        }

        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        $mg_type    = isset( $cart_item['mg_product_type'] ) ? sanitize_key( $cart_item['mg_product_type'] ) : '';

        if ( ! $product_id ) {
            return array();
        }

        $product_cat_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
        $matched         = array();

        foreach ( $rules as $rule ) {
            if ( empty( $rule['enabled'] ) ) {
                continue;
            }
            if ( empty( $rule['target_products'] ) ) {
                continue;
            }

            // Forrás kategória szűrő
            if ( ! empty( $rule['source_cats'] ) ) {
                if ( empty( $product_cat_ids ) || empty( array_intersect( $rule['source_cats'], $product_cat_ids ) ) ) {
                    continue;
                }
            }

            // Forrás mg_type szűrő
            if ( ! empty( $rule['source_mg_types'] ) ) {
                if ( empty( $mg_type ) || ! in_array( $mg_type, $rule['source_mg_types'], true ) ) {
                    continue;
                }
            }

            $matched[] = $rule;
        }

        return $matched;
    }

    /**
     * Ellenőrzi, hogy a megadott termék már a kosárban van-e az adott design_id-val cross-sell-ként.
     *
     * @param int    $target_product_id
     * @param int    $design_id
     * @param string $rule_id
     * @return bool
     */
    public static function is_already_in_cart( $target_product_id, $design_id, $rule_id ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( (int) $item['product_id'] !== (int) $target_product_id ) {
                continue;
            }
            if ( empty( $item['mg_crosssell_rule_id'] ) ) {
                continue;
            }
            if ( (int) ( $item['mg_design_id'] ?? 0 ) === (int) $design_id
                && $item['mg_crosssell_rule_id'] === $rule_id ) {
                return true;
            }
        }
        return false;
    }
}
