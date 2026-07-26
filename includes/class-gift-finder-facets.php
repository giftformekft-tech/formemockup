<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Az ajándékkereső facet-logikája: válaszonkénti termékhalmazok, metszet és
 * találatszámlálás.
 *
 * Külön osztály, mert három hívó használja – a találati oldal, az admin
 * szűrő-diagnosztikája és az élő találatszámláló végpont –, a
 * `class-gift-finder.php` pedig már így is közel ezer sor.
 *
 * A központi ötlet: minden válaszhoz **külön** cache-elt termék-ID halmaz
 * tartozik. Egy válaszkombináció szigorú (AND) találatszáma ezek metszete,
 * ami memóriában, adatbázis-lekérdezés nélkül számolható. Így a diagnosztika
 * R×O párja is csak R+O lekérdezésbe kerül, és a számlálóvégpont ugyanezekből
 * a halmazokból dolgozik.
 */
class MG_Gift_Finder_Facets {
    const CACHE_TTL = HOUR_IN_SECONDS;

    /** Halmazonkénti felső korlát. A csonkolás ID szerint csökkenő, tehát determinisztikus. */
    const MAX_PRODUCTS = 2000;

    /** A diagnosztikai táblázat legfeljebb ennyi párt számol ki egy oldalletöltésen. */
    const MAX_DIAGNOSTIC_PAIRS = 400;

    /**
     * Egy válaszhoz tartozó termékhalmaz.
     *
     * Ugyanaz a definíció, mint a pontozásé: a termék akkor felel meg egy
     * válasznak, ha a válasz valamelyik kategóriájában (vagy annak
     * gyerekében) van, **vagy** a nevében szerepel a válasz kulcsszavainak
     * egyike. Enélkül a szigorú metszet mást mérne, mint amit a rangsor
     * egyezésnek tekint.
     *
     * @return int[] ID szerint csökkenő sorrendű termékazonosítók.
     */
    public static function facet_product_ids( array $choice ) {
        $key = 'mg_gift_facet_' . md5( (string) wp_json_encode( self::facet_signature( $choice ) ) );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;

        $ids = self::compute_facet_product_ids( $choice );
        set_transient( $key, $ids, self::CACHE_TTL );
        return $ids;
    }

    private static function facet_signature( array $choice ) {
        $categories = self::choice_categories( $choice );
        $keywords = self::choice_keywords( $choice );
        sort( $categories );
        sort( $keywords );
        return array(
            'v'   => MG_Gift_Finder::cache_version(),
            'oos' => get_option( 'woocommerce_hide_out_of_stock_items' ),
            'c'   => $categories,
            'k'   => $keywords,
        );
    }

    private static function compute_facet_product_ids( array $choice ) {
        $ids = array();
        $categories = self::choice_categories( $choice );
        $base_tax_query = self::base_tax_query();

        if ( ! empty( $categories ) ) {
            $tax_query = $base_tax_query;
            $tax_query[] = array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $categories,
                'operator'         => 'IN',
                'include_children' => true,
            );
            if ( count( $tax_query ) > 1 ) $tax_query['relation'] = 'AND';
            $ids = array_merge( $ids, self::query_ids( array( 'tax_query' => $tax_query ) ) );
        }

        $keywords = self::choice_keywords( $choice );
        if ( ! empty( $keywords ) ) {
            global $wpdb;
            $title_where = array();
            foreach ( $keywords as $keyword ) {
                $title_where[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $keyword ) . '%' );
            }
            $title_ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ("
                . implode( ' OR ', $title_where ) . ') ORDER BY ID DESC LIMIT ' . (int) self::MAX_PRODUCTS
            );
            $title_ids = array_values( array_filter( array_map( 'intval', (array) $title_ids ) ) );
            if ( ! empty( $title_ids ) ) {
                $args = array( 'post__in' => $title_ids );
                if ( ! empty( $base_tax_query ) ) {
                    $base_tax_query['relation'] = 'AND';
                    $args['tax_query'] = $base_tax_query;
                }
                $ids = array_merge( $ids, self::query_ids( $args ) );
            }
        }

        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        rsort( $ids );
        return array_slice( $ids, 0, self::MAX_PRODUCTS );
    }

    private static function query_ids( array $args ) {
        $query = new WP_Query( array_merge( array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => self::MAX_PRODUCTS,
            'orderby'                => 'ID',
            'order'                  => 'DESC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ), $args ) );
        return array_map( 'intval', $query->posts );
    }

    /** A katalógusból kizárt és – beállítástól függően – a készlethiányos termékek szűrője. */
    private static function base_tax_query() {
        $tax_query = array();
        if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) return $tax_query;
        $visibility = wc_get_product_visibility_term_ids();
        if ( ! empty( $visibility['exclude-from-catalog'] ) ) {
            $tax_query[] = array(
                'taxonomy' => 'product_visibility',
                'field'    => 'term_taxonomy_id',
                'terms'    => array( $visibility['exclude-from-catalog'] ),
                'operator' => 'NOT IN',
            );
        }
        if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! empty( $visibility['outofstock'] ) ) {
            $tax_query[] = array(
                'taxonomy' => 'product_visibility',
                'field'    => 'term_taxonomy_id',
                'terms'    => array( $visibility['outofstock'] ),
                'operator' => 'NOT IN',
            );
        }
        return $tax_query;
    }

    public static function choice_categories( array $choice ) {
        return array_values( array_unique( array_filter( array_map( 'intval', (array) ( $choice['category_ids'] ?? array() ) ) ) ) );
    }

    public static function choice_keywords( array $choice ) {
        $keywords = array_map( 'sanitize_text_field', (array) ( $choice['keywords'] ?? array() ) );
        return array_values( array_unique( array_filter( array_map( 'trim', $keywords ), function( $keyword ) {
            return mb_strlen( $keyword ) >= 3;
        } ) ) );
    }

    /** Egy válasz kategóriái a gyerekeikkel együtt – az `include_children` átfedés kimutatásához. */
    public static function choice_family( array $choice ) {
        $family = array();
        foreach ( self::choice_categories( $choice ) as $category_id ) {
            $children = get_term_children( $category_id, 'product_cat' );
            $family[] = $category_id;
            if ( ! is_wp_error( $children ) ) $family = array_merge( $family, array_map( 'intval', $children ) );
        }
        return array_values( array_unique( $family ) );
    }

    /**
     * Több válasz szigorú (AND) metszete.
     *
     * @return int[]
     */
    public static function intersect_choices( array $choices ) {
        $sets = array();
        foreach ( $choices as $choice ) {
            if ( empty( self::choice_categories( $choice ) ) && empty( self::choice_keywords( $choice ) ) ) continue;
            $sets[] = self::facet_product_ids( $choice );
        }
        if ( empty( $sets ) ) return array();

        $result = array_shift( $sets );
        foreach ( $sets as $set ) {
            if ( empty( $result ) ) return array();
            $lookup = array_flip( $set );
            $result = array_values( array_filter( $result, function( $id ) use ( $lookup ) {
                return isset( $lookup[ $id ] );
            } ) );
        }
        return $result;
    }

    /** Több válasz uniója – a jelenlegi (OR-alapú) viselkedés jelöltjei. */
    public static function union_choices( array $choices ) {
        $ids = array();
        foreach ( $choices as $choice ) {
            if ( empty( self::choice_categories( $choice ) ) && empty( self::choice_keywords( $choice ) ) ) continue;
            $ids = array_merge( $ids, self::facet_product_ids( $choice ) );
        }
        return array_values( array_unique( $ids ) );
    }

    public static function strict_count( array $choices ) {
        return count( self::intersect_choices( $choices ) );
    }

    /**
     * Címzett × alkalom diagnosztika.
     *
     * A legnagyobb kockázat, hogy a bolt taxonómiájában az alkalmak ugyanazokra
     * a kategóriákra mutatnak, mint a címzettek (az „Apák napja” alá az
     * `Apának`, `Papának`, `Férjnek` tartozik). Ilyenkor a metszet nem szűkít
     * semmit, és a kemény facetnek ezen az útvonalon nincs értéke. Ez a
     * táblázat ezt méri ki, mielőtt bárki a viselkedésen változtatna.
     *
     * @return array{rows:array<int,array>,truncated:bool}
     */
    public static function recipient_occasion_matrix( $settings = null ) {
        $settings = is_array( $settings ) ? $settings : MG_Gift_Finder::get_settings();
        $recipients = (array) ( $settings['questions']['recipient']['options'] ?? array() );
        $occasions  = (array) ( $settings['questions']['occasion']['options'] ?? array() );

        $rows = array();
        $truncated = false;
        foreach ( $recipients as $recipient_option ) {
            $recipient = MG_Gift_Finder::build_choice( 'recipient', $recipient_option );
            if ( empty( self::choice_categories( $recipient ) ) && empty( self::choice_keywords( $recipient ) ) ) continue;
            $recipient_ids = self::facet_product_ids( $recipient );
            $recipient_family = self::choice_family( $recipient );

            foreach ( $occasions as $occasion_option ) {
                $occasion = MG_Gift_Finder::build_choice( 'occasion', $occasion_option );
                if ( empty( self::choice_categories( $occasion ) ) && empty( self::choice_keywords( $occasion ) ) ) continue;
                // A függő alkalmak csak a megfelelő címzett után jelennek meg,
                // ezért a többi párosuk nem is fordulhat elő a keresőben.
                $parents = array_map( 'intval', (array) ( $occasion['parent_category_ids'] ?? array() ) );
                if ( ! empty( $parents ) && ! array_intersect( $parents, self::choice_categories( $recipient ) ) ) continue;

                if ( count( $rows ) >= self::MAX_DIAGNOSTIC_PAIRS ) {
                    $truncated = true;
                    break 2;
                }

                $pair = array( $recipient, $occasion );
                $strict = self::strict_count( $pair );
                $union  = count( self::union_choices( $pair ) );
                $base   = count( $recipient_ids );
                $overlap = (bool) array_intersect( $recipient_family, self::choice_family( $occasion ) );

                $rows[] = array(
                    'recipient'        => $recipient['label'],
                    'occasion'         => $occasion['label'],
                    'recipient_count'  => $base,
                    'union_count'      => $union,
                    'strict_count'     => $strict,
                    'narrowing_percent'=> $base > 0 ? (int) round( ( 1 - ( $strict / $base ) ) * 100 ) : 0,
                    'no_op'            => $base > 0 && $strict >= $base,
                    'overlapping_tree' => $overlap,
                );
            }
        }

        usort( $rows, function( $a, $b ) {
            if ( $a['narrowing_percent'] !== $b['narrowing_percent'] ) return $a['narrowing_percent'] <=> $b['narrowing_percent'];
            return strcmp( $a['recipient'] . $a['occasion'], $b['recipient'] . $b['occasion'] );
        } );
        return array( 'rows' => $rows, 'truncated' => $truncated );
    }
}
