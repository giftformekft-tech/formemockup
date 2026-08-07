<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MG_Gift_Finder_Page {
    const MENU_SLUG = 'mockup-generator-gift-finder';

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            'Ajándékkereső',
            'Ajándékkereső',
            'manage_woocommerce',
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Nincs jogosultság.', 'mockup-generator' ) );
        }
        if ( isset( $_POST['mg_gift_finder_nonce'] ) ) {
            self::save();
        }
        if ( isset( $_POST['mg_gift_stats_nonce'] ) ) {
            check_admin_referer( 'mg_clear_gift_stats', 'mg_gift_stats_nonce' );
            MG_Gift_Finder::clear_no_result_stats();
            add_settings_error( 'mg_gift_finder', 'stats-cleared', 'Az eredménytelen keresések statisztikája törölve.', 'updated' );
        }
        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_media();
        $settings = MG_Gift_Finder::get_settings();
        $categories = self::get_categories();
        $pages = get_pages( array( 'sort_column' => 'post_title', 'post_status' => array( 'publish', 'draft' ) ) );
        $finder_url = MG_Gift_Finder::get_finder_url();
        ?>
        <div class="wrap mg-gift-admin">
            <h1>🎁 Ajándékkereső</h1>
            <p>A főoldali Gutenberg blokk és a vezetett ajándékkereső közös beállításai.</p>
            <?php settings_errors( 'mg_gift_finder' ); ?>

            <div class="notice notice-info inline"><p><strong>Menübe illeszthető link:</strong> <code id="mg-gift-menu-url"><?php echo esc_html( $finder_url ); ?></code> <button type="button" class="button button-small" data-copy-target="mg-gift-menu-url">Link másolása</button></p></div>

            <?php if ( class_exists( 'MG_Gift_Finder_Transfer' ) ) MG_Gift_Finder_Transfer::render_panel(); ?>

            <form method="post">
                <?php wp_nonce_field( 'mg_save_gift_finder', 'mg_gift_finder_nonce' ); ?>
                <h2>1. Ajándékkereső oldal</h2>
                <table class="form-table"><tr><th><label for="mg-gift-page">Céloldal</label></th><td>
                    <select id="mg-gift-page" name="settings[page_id]">
                        <option value="0">— Válassz oldalt —</option>
                        <?php foreach ( $pages as $page ) : ?><option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( (int) $settings['page_id'], $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option><?php endforeach; ?>
                    </select>
                    <p class="description">Az oldal tartalmába tedd ezt a shortcode-ot: <code>[mg_gift_finder]</code></p>
                </td></tr></table>

                <h2>2. Ajándékkereső színei</h2>
                <p class="description">A színek a teljes ajándékkereső oldalon és a Gutenberg főoldali blokkon is érvényesek.</p>
                <div class="mg-gift-color-grid">
                    <?php
                    $color_labels = array(
                        'accent'      => 'Kiemelőszín és gombok',
                        'accent_dark' => 'Gomb rámutatási színe',
                        'ink'         => 'Fő szövegszín',
                        'muted'       => 'Másodlagos szöveg',
                        'background'  => 'Főoldali blokk háttere',
                        'panel'       => 'Kérdésdoboz háttere',
                        'card'        => 'Válaszkártyák háttere',
                    );
                    foreach ( $color_labels as $key => $label ) : ?>
                        <label><span><?php echo esc_html( $label ); ?></span><input type="color" name="settings[colors][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings['colors'][ $key ] ); ?>" /></label>
                    <?php endforeach; ?>
                </div>

                <h2>3. Forme kabala</h2>
                <p class="description">A kiválasztott átlátszó PNG a főoldali ajándékkereső blokk jobb felső sarkában jelenik meg.</p>
                <?php
                $mascot_id = absint( $settings['mascot_image_id'] ?? 0 );
                $mascot_url = $mascot_id ? wp_get_attachment_image_url( $mascot_id, 'medium' ) : '';
                ?>
                <div class="mg-gift-mascot-setting">
                    <input type="hidden" id="mg-gift-mascot-id" name="settings[mascot_image_id]" value="<?php echo esc_attr( $mascot_id ); ?>" />
                    <div id="mg-gift-mascot-preview" class="mg-gift-mascot-preview" <?php echo $mascot_url ? '' : 'hidden'; ?>>
                        <img src="<?php echo esc_url( $mascot_url ); ?>" alt="Forme kabala előnézete" />
                    </div>
                    <div>
                        <button type="button" class="button button-secondary" id="mg-gift-mascot-select">Kabala PNG kiválasztása</button>
                        <button type="button" class="button-link-delete" id="mg-gift-mascot-remove" <?php echo $mascot_url ? '' : 'hidden'; ?>>Eltávolítás</button>
                        <p class="description">Átlátszó hátterű PNG ajánlott. A kép méretét és elhelyezését a blokk automatikusan kezeli.</p>
                    </div>
                </div>

                <h2>4. Szezonális kategóriakártyák</h2>
                <p class="description">Az évszakhoz kötött kártyák rövid hivatkozásként jelennek meg a főoldali címzettválasztó alatt. A „Mindig látszik” kártyák megmaradnak korábbi közvetlen linkekhez, de nem terhelik a kezdőblokkot.</p>
                <div class="mg-gift-admin-list" id="mg-gift-cards">
                    <?php foreach ( $settings['cards'] as $index => $card ) self::card_row( $index, $card, $categories ); ?>
                </div>
                <button type="button" class="button mg-add-row" data-template="mg-gift-card-template" data-target="mg-gift-cards">+ Kategóriakártya</button>

                <h2>5. A kereső kérdései</h2>
                <p class="description">Egy válaszhoz megadhatsz egy elsődleges és több további WooCommerce-kategóriát, valamint kanonikus tageket. A tag mód kikapcsolva a korábbi kategória- és kulcsszólogika marad érvényben; bekapcsolva a pontos tag-egyezések előnyt kapnak.</p>
                <p class="description"><strong>Függő válaszok:</strong> a „Csak ezek után jelenjen meg” mezővel szabályozható, hogy például az „Anyának” választás után mely alkalmak legyenek láthatók. Üresen hagyva a válasz minden korábbi választásnál megjelenik. Új első lépcsős válasz felvétele után ments egyszer, hogy megjelenjen a későbbi lépcsők szülőlistájában.</p>
                <?php foreach ( $settings['questions'] as $key => $question ) : ?>
                    <section class="mg-gift-admin-question">
                        <label><strong>Kérdés:</strong> <input type="text" class="regular-text" name="settings[questions][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $question['title'] ); ?>" /></label>
                        <div class="mg-gift-admin-list" id="mg-question-<?php echo esc_attr( $key ); ?>">
                            <?php $parent_categories = self::get_parent_categories( $settings, $key, $categories ); foreach ( $question['options'] as $index => $option ) self::option_row( $key, $index, $option, $categories, $parent_categories ); ?>
                        </div>
                        <button type="button" class="button mg-add-row" data-template="mg-option-template-<?php echo esc_attr( $key ); ?>" data-target="mg-question-<?php echo esc_attr( $key ); ?>">+ Válaszlehetőség</button>
                    </section>
                <?php endforeach; ?>

                <h2>6. Ajándékcsomag-ajánlások</h2>
                <p class="description">A csomag akkor jelenik meg, ha legalább egy hozzárendelt kategória egyezik a vevő válaszaival. Kategória nélkül minden találatnál megjelenik.</p>
                <div class="mg-gift-admin-list" id="mg-gift-bundles">
                    <?php foreach ( $settings['bundles'] as $index => $bundle ) self::bundle_row( $index, $bundle, $categories ); ?>
                </div>
                <button type="button" class="button mg-add-row" data-template="mg-bundle-template" data-target="mg-gift-bundles">+ Ajándékcsomag</button>

                <h2>7. Kemény szűrés és lazítás</h2>
                <p class="description">A válaszok metszetként (ÉS-kapcsolattal) szűrnek: a termékhez mindegyik megadott válasznak illenie kell. Ha így a küszöbnél kevesebb találat marad, a kereső feloldja a legmagasabb szintű feloldható szűrőt, és újra próbálkozik. A feloldott szűrő nem vész el: a rangsorban továbbra is előre hozza a neki megfelelő termékeket, és a vevő a találatok fölötti chipre kattintva visszakapcsolhatja.</p>
                <p class="description"><strong>Mielőtt bekapcsolod:</strong> nézd meg a lenti <em>9. Szűrő-diagnosztika</em> táblázatot. Ahol az alkalom 0%-kal szűkít, ott a metszetes szűrésnek nincs hatása – azon az útvonalon a katalógus besorolásán kell változtatni.</p>
                <?php $facets = $settings['facets']; ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Kanonikus tag mód</th>
                        <td>
                            <label><input type="checkbox" name="settings[tag_mode][enabled]" value="1" <?php checked( ! empty( $settings['tag_mode']['enabled'] ) ); ?> /> A pontos kanonikus tagegyezések legyenek erősebbek</label>
                            <p class="description">Kikapcsolva a kereső változatlanul csak a kategóriákat és a terméknevek kulcsszavait használja. Bekapcsolva a válaszokhoz rendelt kanonikus tagek is jelöltet és rangsorolási pontot adnak.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Metszetes szűrés</th>
                        <td>
                            <label><input type="checkbox" name="settings[facets][enabled]" value="1" <?php checked( ! empty( $facets['enabled'] ) ); ?> /> A válaszok metszetként szűrjenek</label>
                            <p class="description">Kikapcsolva a kereső a korábbi, unió (VAGY) szerinti viselkedésre vált, és lazítás sem történik.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg-gift-threshold">Lazítási küszöb</label></th>
                        <td>
                            <input type="number" min="1" max="100" step="1" class="small-text" id="mg-gift-threshold" name="settings[facets][threshold]" value="<?php echo esc_attr( (int) $facets['threshold'] ); ?>" /> találat
                            <p class="description">Ennyi találat alatt old fel a kereső egy szintet. Alapérték: 12.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Feloldási sorrend</th>
                        <td>
                            <p class="description">A magasabb szint oldódik fel előbb. Az azonos szintű kérdések együtt mozognak – az alkalom és a hozzá tartozó esemény ezért alapból közös szinten van. A címzett soha nem oldható fel.</p>
                            <table class="widefat striped" style="max-width:640px">
                                <thead><tr><th>Kérdés</th><th style="width:150px">Szint</th></tr></thead>
                                <tbody>
                                <tr><td><?php echo esc_html( $settings['questions']['recipient']['title'] ); ?></td><td><em>1 – sosem oldódik fel</em></td></tr>
                                <?php foreach ( array( 'occasion', 'wedding_type', 'interest', 'occupation' ) as $facet_key ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $settings['questions'][ $facet_key ]['title'] ); ?></td>
                                        <td><input type="number" min="2" max="9" step="1" class="small-text" name="settings[facets][levels][<?php echo esc_attr( $facet_key ); ?>]" value="<?php echo esc_attr( (int) ( $facets['levels'][ $facet_key ] ?? 2 ) ); ?>" /></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Ajándékkereső mentése' ); ?>
            </form>

            <?php self::render_stats(); ?>
            <?php self::render_facet_diagnostics(); ?>
        </div>

        <script type="text/html" id="mg-gift-card-template"><?php self::card_row( '__INDEX__', array(), $categories ); ?></script>
        <?php foreach ( array_keys( $settings['questions'] ) as $key ) : ?>
            <script type="text/html" id="mg-option-template-<?php echo esc_attr( $key ); ?>"><?php self::option_row( $key, '__INDEX__', array(), $categories, self::get_parent_categories( $settings, $key, $categories ) ); ?></script>
        <?php endforeach; ?>
        <script type="text/html" id="mg-bundle-template"><?php self::bundle_row( '__INDEX__', array(), $categories ); ?></script>
        <style>
            .mg-gift-admin h2{margin-top:32px}.mg-gift-admin-list{display:grid;gap:10px;margin:12px 0}.mg-gift-admin-row{display:flex;align-items:center;gap:10px;padding:12px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;flex-wrap:wrap}.mg-gift-admin-row input[type=text]{min-width:180px}.mg-gift-admin-row select{max-width:300px}.mg-gift-admin-row>label{display:flex;align-items:center;gap:8px}.mg-gift-admin-row .mg-row-remove{margin-left:auto;color:#b32d2e}.mg-gift-admin-question{margin:16px 0;padding:18px;background:#f6f7f7;border-left:4px solid #2271b1}.mg-gift-admin-question>.mg-gift-admin-list{margin-left:0}.mg-gift-bundle-row{align-items:flex-start}.mg-gift-stats{margin-top:38px;padding-top:8px;border-top:1px solid #c3c4c7}.mg-gift-color-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;max-width:1000px;margin:12px 0}.mg-gift-color-grid label{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;background:#fff;border:1px solid #c3c4c7;border-radius:6px}.mg-gift-color-grid input[type=color]{width:52px;height:36px;padding:2px;cursor:pointer}.mg-gift-mascot-setting{display:flex;align-items:center;gap:18px;max-width:720px;margin:12px 0;padding:16px;background:#fff;border:1px solid #c3c4c7;border-radius:8px}.mg-gift-mascot-preview{display:flex;align-items:center;justify-content:center;width:150px;height:150px;padding:8px;background:#f0f0f1;border:1px dashed #a7aaad;border-radius:8px}.mg-gift-mascot-preview[hidden]{display:none}.mg-gift-mascot-preview img{max-width:100%;max-height:100%;object-fit:contain}.mg-gift-mascot-setting .button-link-delete{margin-left:10px}
        </style>
        <script>
        (function(){
            var mascotFrame;
            document.addEventListener('click',function(event){
                var add=event.target.closest('.mg-add-row');
                if(add){var target=document.getElementById(add.dataset.target),template=document.getElementById(add.dataset.template);var index=Date.now();target.insertAdjacentHTML('beforeend',template.innerHTML.replace(/__INDEX__/g,index));if(window.jQuery){window.jQuery(document.body).trigger('wc-enhanced-select-init');}}
                var remove=event.target.closest('.mg-row-remove');if(remove){remove.closest('.mg-gift-admin-row').remove();}
                var copy=event.target.closest('[data-copy-target]');if(copy){navigator.clipboard.writeText(document.getElementById(copy.dataset.copyTarget).textContent);copy.textContent='Kimásolva ✓';}
                if(event.target.closest('#mg-gift-mascot-select')){
                    event.preventDefault();
                    if(!mascotFrame){
                        mascotFrame=wp.media({title:'Forme kabala PNG kiválasztása',button:{text:'Ezt a képet használom'},library:{type:'image'},multiple:false});
                        mascotFrame.on('select',function(){
                            var attachment=mascotFrame.state().get('selection').first().toJSON();
                            if(attachment.mime!=='image/png'){window.alert('Kérlek, PNG formátumú kabala képet válassz.');return;}
                            document.getElementById('mg-gift-mascot-id').value=attachment.id;
                            document.querySelector('#mg-gift-mascot-preview img').src=(attachment.sizes&&attachment.sizes.medium?attachment.sizes.medium.url:attachment.url);
                            document.getElementById('mg-gift-mascot-preview').hidden=false;
                            document.getElementById('mg-gift-mascot-remove').hidden=false;
                        });
                    }
                    mascotFrame.open();
                }
                if(event.target.closest('#mg-gift-mascot-remove')){
                    event.preventDefault();document.getElementById('mg-gift-mascot-id').value='';document.getElementById('mg-gift-mascot-preview').hidden=true;event.target.hidden=true;
                }
            });
        })();
        </script>
        <?php
    }

    private static function card_row( $index, $card, $categories ) {
        $card = wp_parse_args( $card, array( 'category_id' => 0, 'label' => '', 'description' => '', 'image_mode' => 'category', 'product_id' => 0, 'season' => 'all' ) );
        $product = $card['product_id'] ? wc_get_product( (int) $card['product_id'] ) : false;
        $prefix = 'settings[cards][' . $index . ']'; ?>
        <div class="mg-gift-admin-row">
            <?php self::category_select( $prefix . '[category_id]', $card['category_id'], $categories ); ?>
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $card['label'] ); ?>" placeholder="Kártya címe (opcionális)" />
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[description]" value="<?php echo esc_attr( $card['description'] ); ?>" placeholder="Rövid leírás" />
            <select name="<?php echo esc_attr( $prefix ); ?>[image_mode]"><option value="category" <?php selected( $card['image_mode'], 'category' ); ?>>Kategória / mockup kép</option><option value="product" <?php selected( $card['image_mode'], 'product' ); ?>>Hero termék képe</option></select>
            <select class="wc-product-search" name="<?php echo esc_attr( $prefix ); ?>[product_id]" data-placeholder="Hero termék keresése" data-action="woocommerce_json_search_products_and_variations" style="min-width:240px"><option value="<?php echo esc_attr( $card['product_id'] ); ?>" selected><?php echo $product ? esc_html( $product->get_formatted_name() ) : ''; ?></option></select>
            <select name="<?php echo esc_attr( $prefix ); ?>[season]"><option value="all" <?php selected( $card['season'], 'all' ); ?>>Mindig látszik</option><option value="spring" <?php selected( $card['season'], 'spring' ); ?>>Csak tavasszal</option><option value="summer" <?php selected( $card['season'], 'summer' ); ?>>Csak nyáron</option><option value="autumn" <?php selected( $card['season'], 'autumn' ); ?>>Csak ősszel</option><option value="winter" <?php selected( $card['season'], 'winter' ); ?>>Csak télen</option></select>
            <button type="button" class="button-link-delete mg-row-remove">Törlés</button>
        </div><?php
    }

    private static function bundle_row( $index, $bundle, $categories ) {
        $bundle = wp_parse_args( $bundle, array( 'title' => '', 'badge' => '', 'category_ids' => array(), 'product_ids' => array() ) );
        $prefix = 'settings[bundles][' . $index . ']'; ?>
        <div class="mg-gift-admin-row mg-gift-bundle-row">
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[title]" value="<?php echo esc_attr( $bundle['title'] ); ?>" placeholder="Csomag neve" required />
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[badge]" value="<?php echo esc_attr( $bundle['badge'] ); ?>" placeholder="Jelvény, pl. Legnépszerűbb" />
            <select multiple name="<?php echo esc_attr( $prefix ); ?>[category_ids][]" size="5" title="Kapcsolódó kategóriák"><?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( $term->term_id ); ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', $bundle['category_ids'] ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select>
            <select multiple class="wc-product-search" name="<?php echo esc_attr( $prefix ); ?>[product_ids][]" data-placeholder="A csomag termékei" data-action="woocommerce_json_search_products_and_variations" style="min-width:320px">
                <?php foreach ( array_map( 'intval', $bundle['product_ids'] ) as $product_id ) : $product = wc_get_product( $product_id ); if ( $product ) : ?><option value="<?php echo esc_attr( $product_id ); ?>" selected><?php echo esc_html( $product->get_formatted_name() ); ?></option><?php endif; endforeach; ?>
            </select>
            <button type="button" class="button-link-delete mg-row-remove">Törlés</button>
        </div><?php
    }

    private static function render_stats() {
        $stats = MG_Gift_Finder::get_no_result_stats(); ?>
        <section class="mg-gift-stats">
            <h2>8. Lazítás és eredménytelen keresések</h2>
            <p class="description">Azok a keresések, ahol fel kellett oldani legalább egy szűrőt, vagy egyáltalán nem lett találat. Ez mutatja meg, milyen termék hiányzik a kínálatból. Egy látogató azonos keresését 30 percenként legfeljebb egyszer számoljuk. A 2026 nyara előtt rögzített sorok még feloldás nélküli, régi formátumúak.</p>
            <?php if ( empty( $stats ) ) : ?><p>Még nincs rögzített keresés.</p><?php else : ?>
                <table class="widefat striped"><thead><tr><th>Válaszok</th><th>Feloldott szűrők</th><th>Szigorú találat</th><th>Végső találat</th><th>Darabszám</th><th>Utolsó keresés</th></tr></thead><tbody>
                    <?php foreach ( $stats as $row ) : $released = array_map( array( __CLASS__, 'question_label' ), array_filter( (array) ( $row['released'] ?? array() ) ) ); ?>
                        <tr>
                            <td><?php echo esc_html( ! empty( $row['terms'] ) ? implode( ', ', (array) $row['terms'] ) : 'Nincs kategóriaszűrés' ); ?></td>
                            <td><?php echo esc_html( $released ? implode( ', ', $released ) : '–' ); ?></td>
                            <td><?php echo isset( $row['strict_count'] ) ? esc_html( (int) $row['strict_count'] ) : '–'; ?></td>
                            <td><?php echo isset( $row['result_count'] ) ? esc_html( (int) $row['result_count'] ) : '0'; ?></td>
                            <td><?php echo esc_html( (int) $row['count'] ); ?>×</td>
                            <td><?php echo esc_html( $row['last_seen'] ?? '' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <form method="post"><?php wp_nonce_field( 'mg_clear_gift_stats', 'mg_gift_stats_nonce' ); ?><?php submit_button( 'Statisztika törlése', 'delete', 'submit', false ); ?></form>
            <?php endif; ?>
        </section><?php
    }

    /**
     * Címzett × alkalom szűrő-diagnosztika.
     *
     * Azt méri ki, hogy a metszet szerinti (AND) szűrés egyáltalán szűkít-e.
     * Ahol az alkalom ugyanazokra a kategóriákra mutat, mint a címzett, ott a
     * szigorú szűrésnek nincs értelme – ezt a táblázat külön jelzi.
     */
    /** Rövid, adminban olvasható kérdésnév. */
    private static function question_label( $key ) {
        $labels = array(
            'recipient'    => 'Címzett',
            'occasion'     => 'Alkalom',
            'wedding_type' => 'Esemény',
            'interest'     => 'Érdeklődés',
            'occupation'   => 'Foglalkozás',
            'start'        => 'Kiindulás',
        );
        return $labels[ $key ] ?? (string) $key;
    }

    private static function render_facet_diagnostics() {
        if ( ! class_exists( 'MG_Gift_Finder_Facets' ) ) return;
        $matrix = MG_Gift_Finder_Facets::recipient_occasion_matrix();
        $rows = $matrix['rows'];
        $no_op_count = count( array_filter( $rows, function( $row ) { return ! empty( $row['no_op'] ); } ) );
        ?>
        <section class="mg-gift-stats mg-gift-diagnostics">
            <h2>9. Szűrő-diagnosztika (címzett × alkalom)</h2>
            <p class="description">Csak olvasható kimutatás: a keresőn semmit nem változtat. A „mai unió” a jelenlegi OR-alapú jelöltszám, a „szigorú metszet” pedig az, amennyi termék mindkét válasznak egyszerre megfelel kategória-, kulcsszó- vagy bekapcsolt tag módban tag-egyezéssel. A szűkítés azt mutatja, hogy az alkalom hány százalékkal csökkenti a címzett önmagában adott termékkörét. A számok a találati gyorsítótárral közös, verzióhoz kötött cache-ből jönnek, és termékmentéskor frissülnek.</p>
            <?php if ( empty( $rows ) ) : ?>
                <p>Még nincs kiszámítható címzett–alkalom pár. Adj hozzá válaszokat a címzett és az alkalom kérdéshez.</p>
            <?php else : ?>
                <p><strong><?php echo esc_html( count( $rows ) ); ?></strong> lehetséges pár közül <strong><?php echo esc_html( $no_op_count ); ?></strong> esetben az alkalom egyáltalán nem szűkít (0%). Ezeken az útvonalakon a szigorú szűrésnek nincs hatása – ott a katalógus besorolásán kell változtatni, nem a keresőn.</p>
                <?php if ( ! empty( $matrix['truncated'] ) ) : ?>
                    <p class="description"><em>A lista <?php echo esc_html( MG_Gift_Finder_Facets::MAX_DIAGNOSTIC_PAIRS ); ?> párnál elvágva.</em></p>
                <?php endif; ?>
                <table class="widefat striped">
                    <thead><tr><th>Címzett</th><th>Alkalom</th><th>Címzett önmagában</th><th>Mai unió (OR)</th><th>Szigorú metszet (AND)</th><th>Szűkítés</th><th>Megjegyzés</th></tr></thead>
                    <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr<?php echo ! empty( $row['no_op'] ) ? ' style="background:#fcf0f1"' : ''; ?>>
                            <td><?php echo esc_html( $row['recipient'] ); ?></td>
                            <td><?php echo esc_html( $row['occasion'] ); ?></td>
                            <td><?php echo esc_html( $row['recipient_count'] ); ?></td>
                            <td><?php echo esc_html( $row['union_count'] ); ?></td>
                            <td><?php echo esc_html( $row['strict_count'] ); ?></td>
                            <td><strong><?php echo esc_html( $row['narrowing_percent'] ); ?>%</strong></td>
                            <td>
                                <?php if ( ! empty( $row['no_op'] ) ) : ?><span style="color:#b32d2e;font-weight:700">Az AND itt hatástalan</span><?php endif; ?>
                                <?php if ( ! empty( $row['overlapping_tree'] ) ) : ?><br /><span class="description">Átfedő kategóriafa: az alkalom és a címzett közös kategóriaágon él, ezért az <code>include_children</code> miatt a „szigorú” szint sem igazán szigorú.</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section><?php
    }

    private static function option_row( $key, $index, $option, $categories, $parent_categories = array() ) {
        $option = wp_parse_args( $option, array( 'label' => '', 'category_id' => 0, 'category_ids' => array(), 'tag_labels' => array(), 'keywords' => array(), 'option_id' => '', 'parent_category_ids' => array() ) );
        $tag_groups = MG_Gift_Finder::get_canonical_tag_groups();
        $selected_tags = MG_Gift_Finder::get_option_tag_labels( $option );
        $prefix = 'settings[questions][' . $key . '][options][' . $index . ']'; ?>
        <div class="mg-gift-admin-row">
            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $option['label'] ); ?>" placeholder="pl. Anyukának" required />
            <?php self::category_select( $prefix . '[category_id]', $option['category_id'], $categories, false ); ?>
            <label>Alá tartozó további kategóriák
                <select multiple name="<?php echo esc_attr( $prefix ); ?>[category_ids][]" size="5">
                    <?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( $term->term_id ); ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', (array) $option['category_ids'] ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Kapcsolódó kanonikus tagek
                <select multiple class="wc-enhanced-select" data-placeholder="Tag keresése…" name="<?php echo esc_attr( $prefix ); ?>[tag_labels][]" style="min-width:280px;">
                    <?php foreach ( $tag_groups as $group => $labels ) : ?>
                        <optgroup label="<?php echo esc_attr( $group ); ?>">
                            <?php foreach ( (array) $labels as $tag_label ) : ?><option value="<?php echo esc_attr( $tag_label ); ?>" <?php selected( in_array( $tag_label, $selected_tags, true ) ); ?>><?php echo esc_html( $tag_label ); ?></option><?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <span class="description">A tag mód bekapcsolásakor ezek a tagek erősítik a válaszhoz illő termékeket.</span>
            </label>
            <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[option_id]" value="<?php echo esc_attr( $option['option_id'] ); ?>" />
            <label>További terméknév-kulcsszavak
                <input type="text" name="<?php echo esc_attr( $prefix ); ?>[keywords]" value="<?php echo esc_attr( implode( ', ', (array) $option['keywords'] ) ); ?>" placeholder="pl. nyugdíj, nyugdíjas" />
                <span class="description">A válasz és a kapcsolódó kategóriák neve automatikusan szerepel a keresésben. Ide csak szinonimák vagy további kifejezések kellenek.</span>
            </label>
            <?php if ( ! empty( $parent_categories ) ) : ?>
                <label>Csak ezek után jelenjen meg
                    <select multiple name="<?php echo esc_attr( $prefix ); ?>[parent_category_ids][]" size="4">
                        <?php foreach ( $parent_categories as $term ) : ?><option value="<?php echo esc_attr( $term->term_id ); ?>" <?php echo in_array( (int) $term->term_id, array_map( 'intval', $option['parent_category_ids'] ), true ) ? 'selected' : ''; ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <button type="button" class="button-link-delete mg-row-remove">Törlés</button>
        </div><?php
    }

    private static function category_select( $name, $selected, $categories, $required = true ) { ?>
        <select name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>><option value="0"><?php echo $required ? '— Woo kategória —' : '— Elsődleges kategória (opcionális) —'; ?></option><?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( (int) $selected, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select><?php
    }

    private static function get_categories() {
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
        return is_wp_error( $terms ) ? array() : $terms;
    }

    private static function get_parent_categories( $settings, $current_key, $categories ) {
        $ids = array();
        foreach ( $settings['questions'] as $key => $question ) {
            if ( $key === $current_key ) break;
            foreach ( $question['options'] as $option ) {
                $ids[] = (int) ( $option['category_id'] ?? 0 );
                $ids = array_merge( $ids, array_map( 'intval', (array) ( $option['category_ids'] ?? array() ) ) );
            }
        }
        return array_values( array_filter( $categories, function( $term ) use ( $ids ) {
            return in_array( (int) $term->term_id, $ids, true );
        } ) );
    }

    private static function save() {
        check_admin_referer( 'mg_save_gift_finder', 'mg_gift_finder_nonce' );
        $raw = isset( $_POST['settings'] ) ? wp_unslash( (array) $_POST['settings'] ) : array();
        $clean = MG_Gift_Finder::defaults();
        $clean['page_id'] = absint( $raw['page_id'] ?? 0 );
        $mascot_id = absint( $raw['mascot_image_id'] ?? 0 );
        if ( $mascot_id && get_post_mime_type( $mascot_id ) !== 'image/png' ) {
            $mascot_id = 0;
            add_settings_error( 'mg_gift_finder', 'mascot-format', 'A kabala kép csak PNG formátumú lehet.', 'error' );
        }
        $clean['mascot_image_id'] = $mascot_id;
        foreach ( $clean['colors'] as $key => $fallback ) {
            $clean['colors'][ $key ] = sanitize_hex_color( $raw['colors'][ $key ] ?? '' ) ?: $fallback;
        }
        $raw_facets = (array) ( $raw['facets'] ?? array() );
        $clean['facets']['enabled']   = ! empty( $raw_facets['enabled'] ) ? 1 : 0;
        $clean['facets']['threshold'] = max( 1, min( 100, absint( $raw_facets['threshold'] ?? 12 ) ) );
        $raw_levels = (array) ( $raw_facets['levels'] ?? array() );
        foreach ( $clean['facets']['levels'] as $facet_key => $level ) {
            $clean['facets']['levels'][ $facet_key ] = max( 1, min( 9, (int) ( $raw_levels[ $facet_key ] ?? $level ) ) );
        }
        // A címzett szintje kötött: enélkül a lazítás a teljes kínálatig tágulhatna.
        $clean['facets']['levels']['recipient'] = 1;
        $raw_tag_mode = is_array( $raw['tag_mode'] ?? null ) ? $raw['tag_mode'] : array();
        $clean['tag_mode']['enabled'] = ! empty( $raw_tag_mode['enabled'] ) ? 1 : 0;
        foreach ( (array) ( $raw['cards'] ?? array() ) as $card ) {
            $term_id = absint( $card['category_id'] ?? 0 );
            if ( ! $term_id || ! term_exists( $term_id, 'product_cat' ) ) continue;
            $clean['cards'][] = array(
                'category_id' => $term_id,
                'label'       => sanitize_text_field( $card['label'] ?? '' ),
                'description' => sanitize_text_field( $card['description'] ?? '' ),
                'image_mode'  => ( $card['image_mode'] ?? '' ) === 'product' ? 'product' : 'category',
                'product_id'  => absint( $card['product_id'] ?? 0 ),
                'season'      => in_array( $card['season'] ?? 'all', array( 'all', 'spring', 'summer', 'autumn', 'winter' ), true ) ? $card['season'] : 'all',
            );
        }
        foreach ( $clean['questions'] as $key => &$question ) {
            $source = (array) ( $raw['questions'][ $key ] ?? array() );
            $question['title'] = sanitize_text_field( $source['title'] ?? $question['title'] );
            $question['options'] = array();
            foreach ( (array) ( $source['options'] ?? array() ) as $option ) {
                $term_id = absint( $option['category_id'] ?? 0 );
                $label = sanitize_text_field( $option['label'] ?? '' );
                if ( $term_id && ! term_exists( $term_id, 'product_cat' ) ) $term_id = 0;
                $category_ids = self::parse_id_list( $option['category_ids'] ?? array() );
                $category_ids = array_values( array_filter( $category_ids, function( $id ) { return (bool) term_exists( $id, 'product_cat' ); } ) );
                $tag_labels = MG_Gift_Finder::sanitize_tag_labels( $option['tag_labels'] ?? array() );
                if ( $label && ( $term_id || ! empty( $category_ids ) ) ) {
                    $question['options'][] = array(
                        'label'               => $label,
                        'category_id'         => $term_id,
                        'category_ids'        => $category_ids,
                        'tag_labels'          => $tag_labels,
                        'keywords'            => self::sanitize_keywords( $option['keywords'] ?? array() ),
                        'option_id'           => sanitize_key( $option['option_id'] ?? '' ),
                        'parent_category_ids' => array_values( array_filter( array_map( 'absint', (array) ( $option['parent_category_ids'] ?? array() ) ) ) ),
                    );
                }
            }
        }
        unset( $question );
        $clean['bundles'] = array();
        foreach ( (array) ( $raw['bundles'] ?? array() ) as $bundle ) {
            $title = sanitize_text_field( $bundle['title'] ?? '' );
            $product_ids = array_values( array_filter( array_map( 'absint', (array) ( $bundle['product_ids'] ?? array() ) ) ) );
            if ( $title === '' || empty( $product_ids ) ) continue;
            $clean['bundles'][] = array(
                'title'        => $title,
                'badge'        => sanitize_text_field( $bundle['badge'] ?? '' ),
                'category_ids' => array_values( array_filter( array_map( 'absint', (array) ( $bundle['category_ids'] ?? array() ) ) ) ),
                'product_ids'  => $product_ids,
            );
        }
        update_option( MG_Gift_Finder::OPTION_KEY, $clean, false );
        add_settings_error( 'mg_gift_finder', 'saved', 'Az ajándékkereső beállításai elmentve.', 'updated' );
    }

    private static function parse_id_list( $value ) {
        if ( is_string( $value ) ) $value = preg_split( '/[\s,;]+/', $value );
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
    }

    private static function sanitize_keywords( $value ) {
        if ( is_string( $value ) ) $value = preg_split( '/[,;\r\n]+/', $value );
        $keywords = array_map( 'sanitize_text_field', (array) $value );
        $keywords = array_filter( array_map( 'trim', $keywords ), function( $keyword ) { return mb_strlen( $keyword ) >= 3; } );
        return array_values( array_unique( $keywords ) );
    }
}
