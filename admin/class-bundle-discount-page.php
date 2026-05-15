<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Bundle_Discount_Page
 *
 * Admin aloldal a mennyiségi kedvezmény és kampány beállításokhoz.
 * Elérhető: Mockup Generator → Mennyiségi kedvezmény
 */
class MG_Bundle_Discount_Page {

    const MENU_SLUG = 'mockup-generator-bundle-discount';

    // -------------------------------------------------------------------------
    // Regisztráció
    // -------------------------------------------------------------------------

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __( 'Mennyiségi kedvezmény', 'mockup-generator' ),
            __( 'Mennyiségi kedvezmény', 'mockup-generator' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Nincs jogosultság.', 'mockup-generator' ) );
        }

        if ( ! empty( $_POST['mg_bundle_discount_nonce'] ) ) {
            self::handle_save();
        }

        settings_errors( 'mg_bundle_discount' );

        $settings = class_exists( 'MG_Bundle_Discount' ) ? MG_Bundle_Discount::get_settings() : array(
            'enabled'     => true,
            'type'        => 'fixed',
            'qty2_amount' => 990.0,
            'qty3_amount' => 2480.0,
            'campaigns'   => array(),
        );

        $wc_categories = self::get_wc_categories_flat();
        $mg_types      = self::get_mg_types();
        $campaigns     = isset( $settings['campaigns'] ) ? $settings['campaigns'] : array();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( '🏷️ Mennyiségi kedvezmény beállítások', 'mockup-generator' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Alap kosárszintű kedvezmény minden termékre, illetve egyedi kampányok meghatározott kategóriákra/típusokra.', 'mockup-generator' ); ?>
            </p>
            <hr class="wp-header-end" />

            <form method="post" action="" id="mg-bd-form">
                <?php wp_nonce_field( 'mg_save_bundle_discount', 'mg_bundle_discount_nonce' ); ?>

                <!-- ============================================================ -->
                <!-- ALAP KEDVEZMÉNY -->
                <!-- ============================================================ -->
                <h2><?php esc_html_e( 'Alap mennyiségi kedvezmény', 'mockup-generator' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Ez a kedvezmény azokra a termékekre vonatkozik, amelyeket egyetlen kampány sem fed le.', 'mockup-generator' ); ?>
                </p>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">
                            <label for="mg-bd-enabled"><?php esc_html_e( 'Kedvezmény aktív', 'mockup-generator' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="mg-bd-enabled" name="enabled" value="1"
                                    <?php checked( $settings['enabled'], true ); ?> />
                                <?php esc_html_e( 'Bekapcsolva', 'mockup-generator' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e( 'Kedvezmény típusa', 'mockup-generator' ); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="type" value="fixed" <?php checked( $settings['type'], 'fixed' ); ?> />
                                    <?php esc_html_e( 'Fix összeg (Ft)', 'mockup-generator' ); ?>
                                </label><br />
                                <label>
                                    <input type="radio" name="type" value="percent" <?php checked( $settings['type'], 'percent' ); ?> />
                                    <?php esc_html_e( 'Százalékos (% a kosár részösszegéből)', 'mockup-generator' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mg-bd-qty2"><?php esc_html_e( 'Kedvezmény 2 db esetén', 'mockup-generator' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="mg-bd-qty2" name="qty2_amount"
                                   value="<?php echo esc_attr( $settings['qty2_amount'] ); ?>"
                                   min="0" step="0.01" class="small-text" required />
                            <span class="mg-bd-unit"><?php echo esc_html( $settings['type'] === 'percent' ? '%' : 'Ft' ); ?></span>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mg-bd-qty3"><?php esc_html_e( 'Kedvezmény 3+ db esetén', 'mockup-generator' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="mg-bd-qty3" name="qty3_amount"
                                   value="<?php echo esc_attr( $settings['qty3_amount'] ); ?>"
                                   min="0" step="0.01" class="small-text" required />
                            <span class="mg-bd-unit"><?php echo esc_html( $settings['type'] === 'percent' ? '%' : 'Ft' ); ?></span>
                        </td>
                    </tr>

                </table>

                <hr />

                <!-- ============================================================ -->
                <!-- KAMPÁNYOK -->
                <!-- ============================================================ -->
                <h2><?php esc_html_e( '🎯 Kampányok', 'mockup-generator' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Egyedi mennyiségi kedvezmény meghatározott kategóriájú / típusú termékekre. Ha egy termék lefedett kampánnyal, az alap kedvezmény nem vonatkozik rá.', 'mockup-generator' ); ?>
                </p>

                <div id="mg-campaigns-list">
                    <?php foreach ( $campaigns as $idx => $campaign ) :
                        self::render_campaign_row( $idx, $campaign, $wc_categories, $mg_types );
                    endforeach; ?>
                </div>

                <p>
                    <button type="button" id="mg-add-campaign" class="button button-secondary">
                        ➕ <?php esc_html_e( 'Új kampány hozzáadása', 'mockup-generator' ); ?>
                    </button>
                </p>

                <hr />

                <?php submit_button( __( 'Beállítások mentése', 'mockup-generator' ) ); ?>
            </form>
        </div>

        <!-- Kampány sor sablon (hidden) -->
        <script type="text/html" id="mg-campaign-template">
            <?php self::render_campaign_row( '__IDX__', array(
                'id'         => '',
                'name'       => '',
                'enabled'    => true,
                'categories' => array(),
                'mg_types'   => array(),
                'tiers'      => array(),
            ), $wc_categories, $mg_types, true ); ?>
        </script>

        <!-- Sáv sor sablon (hidden) -->
        <script type="text/html" id="mg-tier-template">
            <?php self::render_tier_row( '__IDX__', '__TIDX__', array( 'min_qty' => '', 'amount' => '' ), true ); ?>
        </script>

        <style>
        .mg-campaign-box {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            margin-bottom: 16px;
            padding: 0;
        }
        .mg-campaign-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #f6f7f7;
            border-bottom: 1px solid #c3c4c7;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
        }
        .mg-campaign-header .mg-campaign-title {
            font-weight: 600;
            flex: 1;
        }
        .mg-campaign-body {
            padding: 16px;
        }
        .mg-campaign-body table.form-table th {
            width: 180px;
        }
        .mg-tiers-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .mg-tiers-table th, .mg-tiers-table td {
            padding: 6px 8px;
            text-align: left;
        }
        .mg-tiers-table th {
            font-weight: 600;
            background: #f0f0f1;
        }
        .mg-tiers-table tr + tr td {
            border-top: 1px solid #f0f0f1;
        }
        .mg-tier-remove {
            color: #b32d2e;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 18px;
            line-height: 1;
            padding: 0 4px;
        }
        .mg-campaign-cats select,
        .mg-campaign-types select {
            min-width: 280px;
            min-height: 80px;
        }
        .mg-campaign-delete {
            color: #b32d2e;
        }
        </style>

        <script>
        (function($) {
            // ---- Alap kedvezmény típus váltás ----
            var radios = $('input[name="type"]');
            var units  = $('.mg-bd-unit');
            function updateUnit() {
                var val = $('input[name="type"]:checked').val();
                units.text(val === 'percent' ? '%' : 'Ft');
            }
            radios.on('change', updateUnit);

            // ---- Kampány toggle ----
            $(document).on('click', '.mg-campaign-header', function() {
                $(this).siblings('.mg-campaign-body').slideToggle(150);
            });

            // ---- Kampány neve live frissítés ----
            $(document).on('input', '.mg-campaign-name-input', function() {
                var val = $(this).val().trim() || '<?php echo esc_js( __('Névtelen kampány', 'mockup-generator') ); ?>';
                $(this).closest('.mg-campaign-box').find('.mg-campaign-title').text(val);
            });

            // ---- Kampány törlése ----
            $(document).on('click', '.mg-campaign-delete', function(e) {
                e.stopPropagation();
                if (!confirm('<?php echo esc_js( __('Biztosan törlöd ezt a kampányt?', 'mockup-generator') ); ?>')) return;
                $(this).closest('.mg-campaign-box').remove();
                reindex();
            });

            // ---- Új kampány ----
            $('#mg-add-campaign').on('click', function() {
                var idx  = $('#mg-campaigns-list .mg-campaign-box').length;
                var html = $('#mg-campaign-template').html();
                html = html.replace(/__IDX__/g, idx);
                var $box = $(html);
                $box.find('.mg-campaign-body').show();
                $('#mg-campaigns-list').append($box);
            });

            // ---- Sáv hozzáadása ----
            $(document).on('click', '.mg-add-tier', function() {
                var $box  = $(this).closest('.mg-campaign-box');
                var idx   = $box.data('idx');
                var tidx  = $box.find('.mg-tier-row').length;
                var html  = $('#mg-tier-template').html();
                html = html.replace(/__IDX__/g, idx).replace(/__TIDX__/g, tidx);
                $box.find('.mg-tiers-tbody').append(html);
            });

            // ---- Sáv törlése ----
            $(document).on('click', '.mg-tier-remove', function() {
                $(this).closest('.mg-tier-row').remove();
            });

            // ---- Re-index (törlés után) ----
            function reindex() {
                $('#mg-campaigns-list .mg-campaign-box').each(function(idx) {
                    $(this).data('idx', idx);
                    $(this).find('[name]').each(function() {
                        var n = $(this).attr('name');
                        $(this).attr('name', n.replace(/campaigns\[\d+\]/, 'campaigns[' + idx + ']'));
                    });
                });
            }

            // Kampány testek kezdetben zárva (ha már van mentett adat)
            $('.mg-campaign-body:not(.mg-campaign-body--open)').hide();

        })(jQuery);
        </script>
        <?php
    }

    /**
     * Egy kampány sor kirendrelése.
     */
    private static function render_campaign_row( $idx, $campaign, $wc_categories, $mg_types, $is_template = false ) {
        $name       = isset( $campaign['name'] ) ? $campaign['name'] : '';
        $enabled    = isset( $campaign['enabled'] ) ? (bool) $campaign['enabled'] : true;
        $categories = isset( $campaign['categories'] ) ? $campaign['categories'] : array();
        $types      = isset( $campaign['mg_types'] ) ? $campaign['mg_types'] : array();
        $tiers      = isset( $campaign['tiers'] ) ? $campaign['tiers'] : array();
        $id         = isset( $campaign['id'] ) ? $campaign['id'] : '';
        $title      = $name !== '' ? $name : __( 'Névtelen kampány', 'mockup-generator' );
        $prefix     = "campaigns[{$idx}]";
        $open_class = $is_template ? ' mg-campaign-body--open' : '';
        ?>
        <div class="mg-campaign-box" data-idx="<?php echo esc_attr( $idx ); ?>">
            <div class="mg-campaign-header">
                <span class="mg-campaign-title"><?php echo esc_html( $title ); ?></span>
                <button type="button" class="button button-small mg-campaign-delete">🗑 <?php esc_html_e( 'Törlés', 'mockup-generator' ); ?></button>
            </div>
            <div class="mg-campaign-body<?php echo esc_attr( $open_class ); ?>">
                <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
                <table class="form-table" role="presentation">

                    <tr>
                        <th><?php esc_html_e( 'Kampány neve', 'mockup-generator' ); ?></th>
                        <td>
                            <input type="text" class="regular-text mg-campaign-name-input"
                                   name="<?php echo esc_attr( $prefix ); ?>[name]"
                                   value="<?php echo esc_attr( $name ); ?>"
                                   placeholder="<?php esc_attr_e( 'pl. Legénybúcsús akció', 'mockup-generator' ); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Aktív', 'mockup-generator' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( $prefix ); ?>[enabled]"
                                       value="1"
                                       <?php checked( $enabled, true ); ?> />
                                <?php esc_html_e( 'Bekapcsolva', 'mockup-generator' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'WC Kategóriák', 'mockup-generator' ); ?></th>
                        <td class="mg-campaign-cats">
                            <select multiple name="<?php echo esc_attr( $prefix ); ?>[categories][]" size="6">
                                <?php foreach ( $wc_categories as $cat ) : ?>
                                    <option value="<?php echo esc_attr( $cat['id'] ); ?>"
                                        <?php echo in_array( (int) $cat['id'], array_map( 'intval', $categories ), true ) ? 'selected' : ''; ?>>
                                        <?php echo esc_html( $cat['label'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Ctrl+klikk a több kiválasztáshoz. Üres = minden kategória.', 'mockup-generator' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Terméktípusok (mg)', 'mockup-generator' ); ?></th>
                        <td class="mg-campaign-types">
                            <select multiple name="<?php echo esc_attr( $prefix ); ?>[mg_types][]" size="5">
                                <?php foreach ( $mg_types as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"
                                        <?php echo in_array( $slug, $types, true ) ? 'selected' : ''; ?>>
                                        <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Ctrl+klikk a több kiválasztáshoz. Üres = minden típus.', 'mockup-generator' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Kedvezmény sávok', 'mockup-generator' ); ?></th>
                        <td>
                            <table class="mg-tiers-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Min. db', 'mockup-generator' ); ?></th>
                                        <th><?php esc_html_e( 'Levonás (Ft)', 'mockup-generator' ); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody class="mg-tiers-tbody">
                                    <?php foreach ( $tiers as $tidx => $tier ) :
                                        self::render_tier_row( $idx, $tidx, $tier );
                                    endforeach; ?>
                                </tbody>
                            </table>
                            <button type="button" class="button button-small mg-add-tier">
                                ➕ <?php esc_html_e( 'Sáv hozzáadása', 'mockup-generator' ); ?>
                            </button>
                            <p class="description"><?php esc_html_e( 'Pl.: 5 db → 5000 Ft levonás, 10 db → 15000 Ft levonás', 'mockup-generator' ); ?></p>
                        </td>
                    </tr>

                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Egy sáv sor kirendrelése.
     */
    private static function render_tier_row( $idx, $tidx, $tier, $is_template = false ) {
        $prefix = "campaigns[{$idx}][tiers][{$tidx}]";
        ?>
        <tr class="mg-tier-row">
            <td>
                <input type="number" name="<?php echo esc_attr( $prefix ); ?>[min_qty]"
                       value="<?php echo esc_attr( isset( $tier['min_qty'] ) ? $tier['min_qty'] : '' ); ?>"
                       min="1" step="1" class="small-text" placeholder="5" required />
                <?php esc_html_e( 'db', 'mockup-generator' ); ?>
            </td>
            <td>
                <input type="number" name="<?php echo esc_attr( $prefix ); ?>[amount]"
                       value="<?php echo esc_attr( isset( $tier['amount'] ) ? $tier['amount'] : '' ); ?>"
                       min="0" step="0.01" class="small-text" placeholder="5000" required />
                Ft
            </td>
            <td>
                <button type="button" class="mg-tier-remove" title="<?php esc_attr_e( 'Sor törlése', 'mockup-generator' ); ?>">✕</button>
            </td>
        </tr>
        <?php
    }

    // -------------------------------------------------------------------------
    // Mentés
    // -------------------------------------------------------------------------

    private static function handle_save() {
        check_admin_referer( 'mg_save_bundle_discount', 'mg_bundle_discount_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $campaigns_raw = isset( $_POST['campaigns'] ) ? wp_unslash( (array) $_POST['campaigns'] ) : array();

        $data = array(
            'enabled'     => ! empty( $_POST['enabled'] ),
            'type'        => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'fixed',
            'qty2_amount' => isset( $_POST['qty2_amount'] ) ? floatval( wp_unslash( $_POST['qty2_amount'] ) ) : 0.0,
            'qty3_amount' => isset( $_POST['qty3_amount'] ) ? floatval( wp_unslash( $_POST['qty3_amount'] ) ) : 0.0,
            'campaigns'   => $campaigns_raw,
        );

        if ( class_exists( 'MG_Bundle_Discount' ) ) {
            MG_Bundle_Discount::save_settings( $data );
        }

        add_settings_error(
            'mg_bundle_discount',
            'saved',
            __( 'Beállítások elmentve.', 'mockup-generator' ),
            'updated'
        );
    }

    // -------------------------------------------------------------------------
    // Segédfüggvények
    // -------------------------------------------------------------------------

    /**
     * WooCommerce kategóriák lapos listája (id + label + mélység).
     */
    private static function get_wc_categories_flat() {
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        // Fa rendezés
        $tree   = array();
        $lookup = array();
        foreach ( $terms as $term ) {
            $lookup[ $term->term_id ] = array(
                'id'       => $term->term_id,
                'label'    => $term->name,
                'parent'   => $term->parent,
                'depth'    => 0,
                'children' => array(),
            );
        }
        foreach ( $lookup as $id => &$node ) {
            if ( $node['parent'] && isset( $lookup[ $node['parent'] ] ) ) {
                $lookup[ $node['parent'] ]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset( $node );

        $flat = array();
        self::flatten_category_tree( $tree, $flat, 0 );

        return $flat;
    }

    private static function flatten_category_tree( $nodes, &$flat, $depth ) {
        foreach ( $nodes as $node ) {
            $flat[] = array(
                'id'    => $node['id'],
                'label' => str_repeat( '— ', $depth ) . $node['label'],
            );
            if ( ! empty( $node['children'] ) ) {
                self::flatten_category_tree( $node['children'], $flat, $depth + 1 );
            }
        }
    }

    /**
     * MG terméktípus slug → label párok a katalógusból.
     */
    private static function get_mg_types() {
        $catalog = get_option( 'mg_product_catalog', array() );
        $types   = array();

        if ( ! is_array( $catalog ) ) {
            return $types;
        }

        foreach ( $catalog as $level2 ) {
            if ( ! is_array( $level2 ) ) {
                continue;
            }
            foreach ( $level2 as $slug => $data ) {
                if ( ! isset( $types[ $slug ] ) ) {
                    $label = isset( $data['label'] ) ? $data['label'] : $slug;
                    $types[ $slug ] = $label;
                }
            }
        }

        asort( $types );
        return $types;
    }
}
