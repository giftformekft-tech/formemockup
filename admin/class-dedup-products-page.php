<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Dedup_Products_Page
 *
 * Admin eszköz az azonos nevű duplikált termékek megkereséséhez és törléséhez.
 * Minden névcsoportból a legrégebbi terméket (legkisebb ID) tartja meg.
 */
class MG_Dedup_Products_Page {

    const MENU_SLUG   = 'mockup-generator-dedup';
    const NONCE_SCAN  = 'mg_dedup_scan';
    const NONCE_DEL   = 'mg_dedup_delete';

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __( 'Duplikált termékek', 'mockup-generator' ),
            __( 'Duplikált termékek', 'mockup-generator' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    public static function register_ajax() {
        add_action( 'wp_ajax_mg_dedup_scan',   array( __CLASS__, 'ajax_scan' ) );
        add_action( 'wp_ajax_mg_dedup_delete', array( __CLASS__, 'ajax_delete' ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: duplikátumok keresése
    // -------------------------------------------------------------------------

    public static function ajax_scan() {
        check_ajax_referer( self::NONCE_SCAN, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Nincs jogosultság.' );
        }

        global $wpdb;

        // Lekéri az összes publikus + vázlat + közzétett termék id + cím párját
        $rows = $wpdb->get_results( "
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type = 'product'
              AND post_status IN ('publish','draft','private','pending')
            ORDER BY ID ASC
        ", ARRAY_A );

        if ( ! $rows ) {
            wp_send_json_success( array( 'groups' => array(), 'total_dupes' => 0 ) );
        }

        // Csoportosítás név alapján (kis/nagybetű-érzéketlen, trimmelt)
        $by_name = array();
        foreach ( $rows as $row ) {
            $key = mb_strtolower( trim( $row['post_title'] ) );
            if ( $key === '' ) {
                continue;
            }
            $by_name[ $key ][] = (int) $row['ID'];
        }

        $groups      = array();
        $total_dupes = 0;

        foreach ( $by_name as $name => $ids ) {
            if ( count( $ids ) < 2 ) {
                continue;
            }
            sort( $ids ); // legkisebb ID = legrégebbi = megtartandó
            $keep   = $ids[0];
            $delete = array_slice( $ids, 1 );

            // Thumbnail URL-ek a JS-nek
            $thumbs = array();
            foreach ( $ids as $id ) {
                $img = get_the_post_thumbnail_url( $id, 'thumbnail' );
                $thumbs[ $id ] = $img ?: '';
            }

            $groups[] = array(
                'name'   => $rows[ array_search( $keep, array_column( $rows, 'ID' ) ) ]['post_title'] ?? $name,
                'keep'   => $keep,
                'delete' => $delete,
                'thumbs' => $thumbs,
            );
            $total_dupes += count( $delete );
        }

        wp_send_json_success( array(
            'groups'      => $groups,
            'total_dupes' => $total_dupes,
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX: törlés
    // -------------------------------------------------------------------------

    public static function ajax_delete() {
        check_ajax_referer( self::NONCE_DEL, 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Nincs jogosultság.' );
        }

        $ids       = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
        $trash     = ! empty( $_POST['trash'] ); // true = kukába, false = végleges törlés
        $deleted   = 0;
        $errors    = array();

        foreach ( $ids as $id ) {
            if ( $id <= 0 ) {
                continue;
            }
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'product' ) {
                $errors[] = $id;
                continue;
            }
            if ( $trash ) {
                $result = wp_trash_post( $id );
            } else {
                $result = wp_delete_post( $id, true );
            }
            if ( $result ) {
                $deleted++;
            } else {
                $errors[] = $id;
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'errors'  => $errors,
        ) );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Nincs jogosultság.', 'mockup-generator' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( '🗂️ Duplikált termékek kezelése', 'mockup-generator' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Az eszköz azonos nevű termékeket keres. Minden csoportból a legrégebbit (legkisebb ID) tartja meg, a többi törölhető.', 'mockup-generator' ); ?>
            </p>
            <hr class="wp-header-end" />

            <div id="mg-dedup-toolbar" style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
                <button id="mg-dedup-scan" class="button button-primary">
                    🔍 <?php esc_html_e( 'Duplikátumok keresése', 'mockup-generator' ); ?>
                </button>
                <label style="display:flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="mg-dedup-trash-mode" checked />
                    <?php esc_html_e( 'Kukába (visszaállítható)', 'mockup-generator' ); ?>
                </label>
                <span id="mg-dedup-status" style="color:#666;font-style:italic;"></span>
            </div>

            <div id="mg-dedup-actions" style="display:none;margin-bottom:16px;display:none;gap:10px;align-items:center;">
                <button id="mg-dedup-select-all" class="button">
                    ☑ <?php esc_html_e( 'Összes kijelölése', 'mockup-generator' ); ?>
                </button>
                <button id="mg-dedup-deselect-all" class="button">
                    ☐ <?php esc_html_e( 'Kijelölés törlése', 'mockup-generator' ); ?>
                </button>
                <button id="mg-dedup-delete-selected" class="button button-primary" style="background:#b32d2e;border-color:#b32d2e;">
                    🗑 <?php esc_html_e( 'Kijelöltek törlése', 'mockup-generator' ); ?>
                </button>
                <span id="mg-dedup-selected-count" style="color:#666;"></span>
            </div>

            <div id="mg-dedup-results"></div>
        </div>

        <style>
        .mg-dedup-group {
            background:#fff;
            border:1px solid #c3c4c7;
            border-radius:4px;
            margin-bottom:12px;
            overflow:hidden;
        }
        .mg-dedup-group-header {
            background:#f6f7f7;
            padding:10px 14px;
            font-weight:600;
            border-bottom:1px solid #ddd;
            display:flex;
            align-items:center;
            gap:8px;
        }
        .mg-dedup-group-header .mg-dedup-name { flex:1; }
        .mg-dedup-table { width:100%; border-collapse:collapse; }
        .mg-dedup-table th,
        .mg-dedup-table td { padding:8px 12px; text-align:left; vertical-align:middle; }
        .mg-dedup-table th { background:#f0f0f1; font-weight:600; font-size:12px; }
        .mg-dedup-table tr + tr td { border-top:1px solid #f0f0f1; }
        .mg-dedup-keep-row { background:#f0fff4; }
        .mg-dedup-keep-badge {
            display:inline-block; padding:2px 7px;
            background:#00a32a; color:#fff;
            border-radius:3px; font-size:11px; font-weight:600;
        }
        .mg-dedup-dupe-badge {
            display:inline-block; padding:2px 7px;
            background:#d63638; color:#fff;
            border-radius:3px; font-size:11px; font-weight:600;
        }
        .mg-dedup-thumb {
            width:60px; height:60px;
            object-fit:cover; border-radius:3px;
            border:1px solid #ddd;
        }
        .mg-dedup-thumb-empty {
            width:60px; height:60px;
            background:#f0f0f1;
            border-radius:3px;
            border:1px solid #ddd;
            display:inline-block;
        }
        #mg-dedup-actions { display:none; }
        #mg-dedup-actions.visible { display:flex; }
        </style>

        <script>
        (function($) {
            var scanNonce   = '<?php echo esc_js( wp_create_nonce( self::NONCE_SCAN ) ); ?>';
            var deleteNonce = '<?php echo esc_js( wp_create_nonce( self::NONCE_DEL ) ); ?>';
            var ajaxUrl     = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var groups      = [];

            // ---- Scan ----
            $('#mg-dedup-scan').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('🔍 Keresés...');
                $('#mg-dedup-status').text('');
                $('#mg-dedup-results').html('<p style="padding:16px;color:#666;">Keresés folyamatban…</p>');
                $('#mg-dedup-actions').removeClass('visible');

                $.post(ajaxUrl, { action: 'mg_dedup_scan', nonce: scanNonce }, function(resp) {
                    $btn.prop('disabled', false).text('🔍 Duplikátumok keresése');
                    if (!resp.success) {
                        $('#mg-dedup-results').html('<p style="color:#d63638;">Hiba: ' + (resp.data || 'ismeretlen') + '</p>');
                        return;
                    }
                    groups = resp.data.groups;
                    var total = resp.data.total_dupes;
                    if (groups.length === 0) {
                        $('#mg-dedup-results').html('<p style="padding:16px;color:#00a32a;">✅ Nem találtunk duplikált terméket.</p>');
                        $('#mg-dedup-status').text('');
                        return;
                    }
                    $('#mg-dedup-status').text(groups.length + ' névcsoportban ' + total + ' duplikátum található.');
                    renderGroups(groups);
                    $('#mg-dedup-actions').addClass('visible');
                    updateSelectedCount();
                }).fail(function() {
                    $btn.prop('disabled', false).text('🔍 Duplikátumok keresése');
                    $('#mg-dedup-results').html('<p style="color:#d63638;">AJAX hiba.</p>');
                });
            });

            // ---- Render ----
            function thumb(url) {
                if (url) return '<img src="' + url + '" class="mg-dedup-thumb" />';
                return '<span class="mg-dedup-thumb-empty"></span>';
            }

            function renderGroups(gs) {
                var html = '';
                gs.forEach(function(g, gi) {
                    html += '<div class="mg-dedup-group">';
                    html += '<div class="mg-dedup-group-header">';
                    html += '<span class="mg-dedup-name">' + escHtml(g.name) + '</span>';
                    html += '<span style="color:#666;font-size:12px;font-weight:400;">' + (g.delete.length + 1) + ' termék, ' + g.delete.length + ' duplikátum</span>';
                    html += '</div>';
                    html += '<table class="mg-dedup-table"><thead><tr>'
                          + '<th style="width:36px;"></th>'
                          + '<th style="width:72px;">Kép</th>'
                          + '<th>ID</th>'
                          + '<th>Státusz</th>'
                          + '<th>Szerkesztés</th>'
                          + '</tr></thead><tbody>';

                    // Keep row
                    var keepId = g.keep;
                    html += '<tr class="mg-dedup-keep-row">';
                    html += '<td></td>';
                    html += '<td>' + thumb(g.thumbs[keepId] || '') + '</td>';
                    html += '<td>#' + keepId + '</td>';
                    html += '<td><span class="mg-dedup-keep-badge">MEGTARTANDÓ</span></td>';
                    html += '<td><a href="post.php?post=' + keepId + '&action=edit" target="_blank">Szerkesztés ↗</a></td>';
                    html += '</tr>';

                    // Delete rows
                    g.delete.forEach(function(id) {
                        html += '<tr data-id="' + id + '">';
                        html += '<td><input type="checkbox" class="mg-dedup-cb" value="' + id + '" checked /></td>';
                        html += '<td>' + thumb(g.thumbs[id] || '') + '</td>';
                        html += '<td>#' + id + '</td>';
                        html += '<td><span class="mg-dedup-dupe-badge">DUPLIKÁTUM</span></td>';
                        html += '<td><a href="post.php?post=' + id + '&action=edit" target="_blank">Szerkesztés ↗</a></td>';
                        html += '</tr>';
                    });

                    html += '</tbody></table></div>';
                });
                $('#mg-dedup-results').html(html);
            }

            function escHtml(s) {
                return $('<div>').text(s).html();
            }

            // ---- Select all / deselect ----
            $('#mg-dedup-select-all').on('click', function() {
                $('.mg-dedup-cb').prop('checked', true);
                updateSelectedCount();
            });
            $('#mg-dedup-deselect-all').on('click', function() {
                $('.mg-dedup-cb').prop('checked', false);
                updateSelectedCount();
            });
            $(document).on('change', '.mg-dedup-cb', updateSelectedCount);

            function updateSelectedCount() {
                var n = $('.mg-dedup-cb:checked').length;
                $('#mg-dedup-selected-count').text(n + ' kijelölve');
            }

            // ---- Delete ----
            $('#mg-dedup-delete-selected').on('click', function() {
                var ids = [];
                $('.mg-dedup-cb:checked').each(function() { ids.push($(this).val()); });
                if (ids.length === 0) { alert('Nincs kijelölt termék.'); return; }
                var trash = $('#mg-dedup-trash-mode').is(':checked');
                var msg = trash
                    ? ids.length + ' terméket kukába dobsz. Folytatod?'
                    : ids.length + ' terméket VÉGLEGESEN törölsz. Ez nem vonható vissza! Folytatod?';
                if (!confirm(msg)) return;

                var $btn = $(this).prop('disabled', true).text('Törlés...');

                $.post(ajaxUrl, {
                    action: 'mg_dedup_delete',
                    nonce:  deleteNonce,
                    ids:    ids,
                    trash:  trash ? 1 : 0,
                }, function(resp) {
                    $btn.prop('disabled', false).text('🗑 Kijelöltek törlése');
                    if (!resp.success) {
                        alert('Hiba: ' + (resp.data || 'ismeretlen'));
                        return;
                    }
                    var d = resp.data.deleted;
                    alert(d + ' termék ' + (trash ? 'kukába kerbült' : 'törölve') + '.');
                    // Törölt sorok eltávolítása a DOM-ból
                    ids.forEach(function(id) {
                        $('tr[data-id="' + id + '"]').fadeOut(300, function() { $(this).remove(); });
                    });
                    // Üres csoportok eltávolítása
                    setTimeout(function() {
                        $('.mg-dedup-group').each(function() {
                            if ($(this).find('.mg-dedup-cb').length === 0) {
                                $(this).remove();
                            }
                        });
                        updateSelectedCount();
                        if ($('.mg-dedup-group').length === 0) {
                            $('#mg-dedup-results').html('<p style="padding:16px;color:#00a32a;">✅ Minden duplikátum eltávolítva.</p>');
                            $('#mg-dedup-actions').removeClass('visible');
                            $('#mg-dedup-status').text('');
                        }
                    }, 400);
                }).fail(function() {
                    $btn.prop('disabled', false).text('🗑 Kijelöltek törlése');
                    alert('AJAX hiba.');
                });
            });

        })(jQuery);
        </script>
        <?php
    }
}
