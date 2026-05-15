<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Crosssell_Page
 *
 * Admin aloldal a cross-sell szabályok kezeléséhez.
 * Elérhető: Mockup Generator → Cross-sell szabályok
 */
class MG_Crosssell_Page {

    const MENU_SLUG = 'mockup-generator-crosssell';

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __( 'Cross-sell szabályok', 'mockup-generator' ),
            __( 'Cross-sell', 'mockup-generator' ),
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

        if ( ! empty( $_POST['mg_crosssell_nonce'] ) ) {
            self::handle_save();
        }

        settings_errors( 'mg_crosssell' );

        $rules         = class_exists( 'MG_Crosssell_Manager' ) ? MG_Crosssell_Manager::get_rules() : array();
        $wc_categories = self::get_wc_categories_flat();
        $mg_types      = self::get_mg_types();

        // WC select2 assets betöltése
        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_style( 'select2' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( '🔁 Cross-sell szabályok', 'mockup-generator' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Ha a vevő kosarában van egy designos termék, felajánljuk ugyanazt a mintát más terméken kedvezménnyel.', 'mockup-generator' ); ?>
            </p>
            <hr class="wp-header-end" />

            <form method="post" action="" id="mg-cs-form">
                <?php wp_nonce_field( 'mg_save_crosssell', 'mg_crosssell_nonce' ); ?>

                <div id="mg-cs-rules-list">
                    <?php foreach ( $rules as $idx => $rule ) :
                        self::render_rule_row( $idx, $rule, $wc_categories, $mg_types );
                    endforeach; ?>
                </div>

                <p>
                    <button type="button" id="mg-cs-add-rule" class="button button-secondary">
                        ➕ <?php esc_html_e( 'Új szabály hozzáadása', 'mockup-generator' ); ?>
                    </button>
                </p>

                <hr />
                <?php submit_button( __( 'Beállítások mentése', 'mockup-generator' ) ); ?>
            </form>
        </div>

        <!-- Szabály sablon -->
        <script type="text/html" id="mg-cs-rule-template">
            <?php self::render_rule_row( '__IDX__', array(
                'id'              => '',
                'name'            => '',
                'enabled'         => true,
                'source_cats'     => array(),
                'source_mg_types' => array(),
                'target_products' => array(),
                'discount_amount' => 0.0,
                'headline'        => '',
                'description'     => '',
            ), $wc_categories, $mg_types, true ); ?>
        </script>

        <style>
        .mg-cs-rule-box {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .mg-cs-rule-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #f0f4f9;
            border-bottom: 1px solid #c3c4c7;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
        }
        .mg-cs-rule-header .mg-cs-rule-title {
            font-weight: 600;
            flex: 1;
        }
        .mg-cs-rule-body {
            padding: 16px;
        }
        .mg-cs-rule-body table.form-table th {
            width: 200px;
        }
        .mg-cs-rule-cats select,
        .mg-cs-rule-types select {
            min-width: 280px;
            min-height: 80px;
        }
        .mg-cs-target-products .wc-product-search {
            min-width: 400px;
        }
        .mg-cs-delete {
            color: #b32d2e;
        }
        </style>

        <script>
        (function($) {
            // Toggle
            $(document).on('click', '.mg-cs-rule-header', function() {
                $(this).siblings('.mg-cs-rule-body').slideToggle(150);
            });

            // Név live update
            $(document).on('input', '.mg-cs-name-input', function() {
                var val = $(this).val().trim() || '<?php echo esc_js( __( 'Névtelen szabály', 'mockup-generator' ) ); ?>';
                $(this).closest('.mg-cs-rule-box').find('.mg-cs-rule-title').text(val);
            });

            // Törlés
            $(document).on('click', '.mg-cs-delete', function(e) {
                e.stopPropagation();
                if (!confirm('<?php echo esc_js( __( 'Biztosan törlöd?', 'mockup-generator' ) ); ?>')) return;
                $(this).closest('.mg-cs-rule-box').remove();
                reindex();
            });

            // Új szabály
            $('#mg-cs-add-rule').on('click', function() {
                var idx  = $('#mg-cs-rules-list .mg-cs-rule-box').length;
                var html = $('#mg-cs-rule-template').html().replace(/__IDX__/g, idx);
                var $box = $(html);
                $box.find('.mg-cs-rule-body').show();
                $('#mg-cs-rules-list').append($box);
                // WC product search init
                $box.find('.wc-product-search').select2({
                    ajax: {
                        url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return { term: params.term, action: 'woocommerce_json_search_products', security: '<?php echo esc_js( wp_create_nonce('search-products') ); ?>' };
                        },
                        processResults: function(data) {
                            var terms = [];
                            if (data) {
                                $.each(data, function(id, text) {
                                    terms.push({ id: id, text: text });
                                });
                            }
                            return { results: terms };
                        },
                        cache: true
                    },
                    minimumInputLength: 1,
                    placeholder: '<?php echo esc_js( __( 'Keress termékre...', 'mockup-generator' ) ); ?>',
                    allowClear: true,
                    multiple: true,
                });
            });

            // Meglévő szabályok bezárva induljanak
            $('.mg-cs-rule-body').hide();

            // Re-index
            function reindex() {
                $('#mg-cs-rules-list .mg-cs-rule-box').each(function(idx) {
                    $(this).data('idx', idx);
                    $(this).find('[name]').each(function() {
                        var n = $(this).attr('name');
                        $(this).attr('name', n.replace(/rules\[\d+\]/, 'rules[' + idx + ']'));
                    });
                });
            }

            // WC product search init meglévőkre
            $('.wc-product-search').each(function() {
                if (!$(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2({
                        ajax: {
                            url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
                            dataType: 'json',
                            delay: 250,
                            data: function(params) {
                                return { term: params.term, action: 'woocommerce_json_search_products', security: '<?php echo esc_js( wp_create_nonce('search-products') ); ?>' };
                            },
                            processResults: function(data) {
                                var terms = [];
                                if (data) {
                                    $.each(data, function(id, text) {
                                        terms.push({ id: id, text: text });
                                    });
                                }
                                return { results: terms };
                            },
                            cache: true
                        },
                        minimumInputLength: 1,
                        placeholder: '<?php echo esc_js( __( 'Keress termékre...', 'mockup-generator' ) ); ?>',
                        allowClear: true,
                        multiple: true,
                    });
                }
            });

        })(jQuery);
        </script>
        <?php
    }

    private static function render_rule_row( $idx, $rule, $wc_categories, $mg_types, $is_template = false ) {
        $name        = $rule['name'] ?? '';
        $enabled     = ! empty( $rule['enabled'] );
        $source_cats = $rule['source_cats'] ?? array();
        $source_types= $rule['source_mg_types'] ?? array();
        $targets     = $rule['target_products'] ?? array();
        $discount    = $rule['discount_amount'] ?? 0.0;
        $headline    = $rule['headline'] ?? '';
        $description = $rule['description'] ?? '';
        $id          = $rule['id'] ?? '';
        $title       = $name !== '' ? $name : __( 'Névtelen szabály', 'mockup-generator' );
        $prefix      = "rules[{$idx}]";
        ?>
        <div class="mg-cs-rule-box" data-idx="<?php echo esc_attr( $idx ); ?>">
            <div class="mg-cs-rule-header">
                <span class="mg-cs-rule-title"><?php echo esc_html( $title ); ?></span>
                <button type="button" class="button button-small mg-cs-delete">🗑 <?php esc_html_e( 'Törlés', 'mockup-generator' ); ?></button>
            </div>
            <div class="mg-cs-rule-body" <?php echo $is_template ? 'style="display:block"' : ''; ?>>
                <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
                <table class="form-table" role="presentation">

                    <tr>
                        <th><?php esc_html_e( 'Szabály neve', 'mockup-generator' ); ?></th>
                        <td>
                            <input type="text" class="regular-text mg-cs-name-input"
                                   name="<?php echo esc_attr( $prefix ); ?>[name]"
                                   value="<?php echo esc_attr( $name ); ?>"
                                   placeholder="<?php esc_attr_e( 'pl. Ballagós szett', 'mockup-generator' ); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Aktív', 'mockup-generator' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="1" <?php checked( $enabled ); ?> />
                                <?php esc_html_e( 'Bekapcsolva', 'mockup-generator' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Ha a kosárban van (kategória)', 'mockup-generator' ); ?></th>
                        <td class="mg-cs-rule-cats">
                            <select multiple name="<?php echo esc_attr( $prefix ); ?>[source_cats][]" size="5">
                                <?php foreach ( $wc_categories as $cat ) : ?>
                                    <option value="<?php echo esc_attr( $cat['id'] ); ?>"
                                        <?php echo in_array( (int) $cat['id'], array_map( 'intval', $source_cats ), true ) ? 'selected' : ''; ?>>
                                        <?php echo esc_html( $cat['label'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Ctrl+klikk a több kiválasztáshoz. Üres = minden kategória.', 'mockup-generator' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Ha a kosárban van (terméktípus)', 'mockup-generator' ); ?></th>
                        <td class="mg-cs-rule-types">
                            <select multiple name="<?php echo esc_attr( $prefix ); ?>[source_mg_types][]" size="4">
                                <?php foreach ( $mg_types as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"
                                        <?php echo in_array( $slug, $source_types, true ) ? 'selected' : ''; ?>>
                                        <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Üres = minden típus.', 'mockup-generator' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Ajánlott termékek', 'mockup-generator' ); ?></th>
                        <td class="mg-cs-target-products">
                            <select class="wc-product-search"
                                    name="<?php echo esc_attr( $prefix ); ?>[target_products][]"
                                    multiple
                                    data-placeholder="<?php esc_attr_e( 'Keress termékre...', 'mockup-generator' ); ?>">
                                <?php foreach ( $targets as $target_id ) :
                                    $p = wc_get_product( $target_id );
                                    if ( ! $p ) continue;
                                    ?>
                                    <option value="<?php echo esc_attr( $target_id ); ?>" selected>
                                        <?php echo esc_html( $p->get_name() . ' (#' . $target_id . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Ezeket a termékeket ajánljuk fel a vevőnek.', 'mockup-generator' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Kedvezmény összege', 'mockup-generator' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( $prefix ); ?>[discount_amount]"
                                   value="<?php echo esc_attr( $discount ); ?>"
                                   min="0" step="1" class="small-text" /> Ft
                            <p class="description"><?php esc_html_e( 'Pl. 1000 = 1.000 Ft levonás az ajánlott termék árából.', 'mockup-generator' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Ajánló főcím', 'mockup-generator' ); ?></th>
                        <td>
                            <input type="text" class="regular-text"
                                   name="<?php echo esc_attr( $prefix ); ?>[headline]"
                                   value="<?php echo esc_attr( $headline ); ?>"
                                   placeholder="<?php esc_attr_e( 'pl. 🎁 Vidd magaddal a mintát!', 'mockup-generator' ); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Ajánló leírás', 'mockup-generator' ); ?></th>
                        <td>
                            <textarea name="<?php echo esc_attr( $prefix ); ?>[description]"
                                      rows="2" class="large-text"
                                      placeholder="<?php esc_attr_e( 'pl. Rendeld meg bögrén is ugyanezzel a mintával!', 'mockup-generator' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
                        </td>
                    </tr>

                </table>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Mentés
    // -------------------------------------------------------------------------

    private static function handle_save() {
        check_admin_referer( 'mg_save_crosssell', 'mg_crosssell_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $rules_raw = isset( $_POST['rules'] ) ? wp_unslash( (array) $_POST['rules'] ) : array();

        if ( class_exists( 'MG_Crosssell_Manager' ) ) {
            MG_Crosssell_Manager::save_rules( $rules_raw );
        }

        add_settings_error( 'mg_crosssell', 'saved', __( 'Beállítások elmentve.', 'mockup-generator' ), 'updated' );
    }

    // -------------------------------------------------------------------------
    // Segédfüggvények
    // -------------------------------------------------------------------------

    private static function get_wc_categories_flat() {
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }
        $lookup = array();
        $tree   = array();
        foreach ( $terms as $term ) {
            $lookup[ $term->term_id ] = array(
                'id'       => $term->term_id,
                'label'    => $term->name,
                'parent'   => $term->parent,
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
        self::flatten_tree( $tree, $flat, 0 );
        return $flat;
    }

    private static function flatten_tree( $nodes, &$flat, $depth ) {
        foreach ( $nodes as $node ) {
            $flat[] = array(
                'id'    => $node['id'],
                'label' => str_repeat( '— ', $depth ) . $node['label'],
            );
            if ( ! empty( $node['children'] ) ) {
                self::flatten_tree( $node['children'], $flat, $depth + 1 );
            }
        }
    }

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
                    $types[ $slug ] = isset( $data['label'] ) ? $data['label'] : $slug;
                }
            }
        }
        asort( $types );
        return $types;
    }
}
