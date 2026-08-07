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

    public static function get_config( $settings = null ) {
        $settings = is_array( $settings ) ? $settings : MG_Gift_Finder::get_settings();
        return is_array( $settings['facets'] ?? null ) ? $settings['facets'] : MG_Gift_Finder::defaults()['facets'];
    }

    public static function is_enabled( $settings = null ) {
        $config = self::get_config( $settings );
        return ! empty( $config['enabled'] );
    }

    public static function get_threshold( $settings = null ) {
        $config = self::get_config( $settings );
        return max( 1, (int) ( $config['threshold'] ?? 12 ) );
    }

    /**
     * Egy kérdés facet-szintje.
     *
     * A szezonális kártyáról érkező belépési kategória (`start`) az alkalommal
     * azonos szinten mozog: mindkettő ugyanazt a „mire” kérdést válaszolja meg.
     */
    public static function level_for( $question_key, $settings = null ) {
        $levels = (array) ( self::get_config( $settings )['levels'] ?? array() );
        if ( $question_key === 'start' ) $question_key = 'occasion';
        return max( 1, (int) ( $levels[ $question_key ] ?? 2 ) );
    }

    /** Az URL-ben visszakapcsolt (lazításból kizárt) kérdések. */
    public static function get_locked_questions() {
        $raw = sanitize_text_field( wp_unslash( $_GET['mg_gift_keep'] ?? '' ) );
        if ( $raw === '' ) return array();
        $keys = array_filter( array_map( 'sanitize_key', preg_split( '/[,\s]+/', $raw ) ) );
        $known = array_keys( MG_Gift_Finder::defaults()['questions'] );
        $known[] = 'start';
        return array_values( array_unique( array_intersect( $keys, $known ) ) );
    }

    /**
     * Kemény facet-szűrés progresszív lazítással.
     *
     * A válaszok metszetként szűrnek – enélkül a 3–5. kérdés alig változtatna a
     * látható első képernyőn, mert az unió találatait az első válasz tölti ki.
     * Ha a metszet a küszöb alá esik, a legkevésbé fontos (legmagasabb szintű)
     * szintet feloldjuk, és újrapróbáljuk. A feloldott feltétel nem tűnik el:
     * a rangsorban továbbra is pontot ad, csak nem zár ki termékeket.
     *
     * Egy szint atomi: az `occasion` és a `wedding_type` együtt mozog, mert az
     * alkalom feloldása a hozzá tartozó altípus nélkül értelmetlen állapot.
     *
     * @param array $choices A pontozási választások (a `start` belépéssel együtt).
     * @param array $locked  Kérdéskulcsok, amelyeken tilos lazítani.
     * @return array{enabled:bool,ids:?array,released:array,locked:array,strict_count:int,count:int,threshold:int}
     */
    public static function resolve( array $choices, $settings = null, array $locked = array() ) {
        $settings = is_array( $settings ) ? $settings : MG_Gift_Finder::get_settings();
        $result = array(
            'enabled'      => self::is_enabled( $settings ),
            'ids'          => null,
            'released'     => array(),
            'locked'       => array_values( $locked ),
            'strict_count' => 0,
            'count'        => 0,
            'threshold'    => self::get_threshold( $settings ),
        );

        $filtering = array();
        foreach ( $choices as $index => $choice ) {
            if ( ! self::choice_has_source( $choice ) ) continue;
            $filtering[ $index ] = $choice;
        }
        if ( ! $result['enabled'] || empty( $filtering ) ) return $result;

        $active = array_keys( $filtering );
        $ids = self::intersect_choices( $filtering );
        $result['strict_count'] = count( $ids );

        while ( count( $ids ) < $result['threshold'] ) {
            $level = self::next_releasable_level( $filtering, $active, $locked, $settings );
            if ( $level === 0 ) break;
            foreach ( $active as $position => $index ) {
                if ( self::level_for( $filtering[ $index ]['question'] ?? '', $settings ) !== $level ) continue;
                unset( $active[ $position ] );
                $result['released'][] = (string) ( $filtering[ $index ]['question'] ?? '' );
            }
            $active = array_values( $active );
            $remaining = array_intersect_key( $filtering, array_flip( $active ) );
            $ids = empty( $remaining ) ? array() : self::intersect_choices( $remaining );
        }

        $result['ids'] = $ids;
        $result['count'] = count( $ids );
        $result['released'] = array_values( array_unique( array_filter( $result['released'] ) ) );
        return $result;
    }

    /**
     * A következőként feloldható szint.
     *
     * A legmagasabb szinttől lefelé keres. Egy szint akkor oldható fel, ha nem
     * az 1. szint, és egyetlen benne lévő kérdés sincs visszakapcsolva – így az
     * `occasion` és a `wedding_type` sosem válik szét.
     */
    private static function next_releasable_level( array $filtering, array $active, array $locked, $settings ) {
        $levels = array();
        $blocked = array();
        foreach ( $active as $index ) {
            $question = (string) ( $filtering[ $index ]['question'] ?? '' );
            $level = self::level_for( $question, $settings );
            if ( $level <= 1 ) continue;
            $levels[] = $level;
            if ( in_array( $question, $locked, true ) ) $blocked[ $level ] = true;
        }
        $levels = array_values( array_unique( $levels ) );
        rsort( $levels );
        foreach ( $levels as $level ) {
            if ( empty( $blocked[ $level ] ) ) return $level;
        }
        return 0;
    }

    /**
     * Egy válaszhoz tartozó termékhalmaz.
     *
     * Ugyanaz a definíció, mint a pontozásé: a termék akkor felel meg egy
     * válasznak, ha a válasz valamelyik kategóriájában (vagy annak
     * gyerekében) van, tag módban valamelyik kanonikus taggel rendelkezik,
     * **vagy** a nevében szerepel a válasz kulcsszavainak egyike. Enélkül a
     * szigorú metszet mást mérne, mint amit a rangsor egyezésnek tekint.
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
        $tags = self::choice_tags( $choice );
        $keywords = self::choice_keywords( $choice );
        sort( $categories );
        sort( $tags );
        sort( $keywords );
        return array(
            'v'   => MG_Gift_Finder::cache_version(),
            'oos' => get_option( 'woocommerce_hide_out_of_stock_items' ),
            'tag_mode' => MG_Gift_Finder::is_tag_mode_enabled() ? 1 : 0,
            'c'   => $categories,
            't'   => $tags,
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

        if ( MG_Gift_Finder::is_tag_mode_enabled() ) {
            $tag_ids = MG_Gift_Finder::get_tag_term_ids( self::choice_tags( $choice ) );
            if ( ! empty( $tag_ids ) ) {
                $tax_query = $base_tax_query;
                $tax_query[] = array(
                    'taxonomy' => 'product_tag',
                    'field'    => 'term_id',
                    'terms'    => $tag_ids,
                    'operator' => 'IN',
                );
                if ( count( $tax_query ) > 1 ) $tax_query['relation'] = 'AND';
                $ids = array_merge( $ids, self::query_ids( array( 'tax_query' => $tax_query ) ) );
            }
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

    public static function choice_tags( array $choice ) {
        return MG_Gift_Finder::get_option_tag_labels( $choice );
    }

    private static function choice_has_source( array $choice ) {
        return ! empty( self::choice_categories( $choice ) )
            || ! empty( self::choice_keywords( $choice ) )
            || ( MG_Gift_Finder::is_tag_mode_enabled() && ! empty( self::choice_tags( $choice ) ) );
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
            if ( ! self::choice_has_source( $choice ) ) continue;
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
            if ( ! self::choice_has_source( $choice ) ) continue;
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
            if ( ! self::choice_has_source( $recipient ) ) continue;
            $recipient_ids = self::facet_product_ids( $recipient );
            $recipient_family = self::choice_family( $recipient );

            foreach ( $occasions as $occasion_option ) {
                $occasion = MG_Gift_Finder::build_choice( 'occasion', $occasion_option );
                if ( ! self::choice_has_source( $occasion ) ) continue;
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
