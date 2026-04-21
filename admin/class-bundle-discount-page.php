<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MG_Bundle_Discount_Page
 *
 * Admin aloldal a mennyiségi kedvezmény beállításaihoz.
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

        // Mentés kezelése
        if ( ! empty( $_POST['mg_bundle_discount_nonce'] ) ) {
            self::handle_save();
        }

        settings_errors( 'mg_bundle_discount' );

        $settings = class_exists( 'MG_Bundle_Discount' ) ? MG_Bundle_Discount::get_settings() : array(
            'enabled'     => true,
            'type'        => 'fixed',
            'qty2_amount' => 990.0,
            'qty3_amount' => 2480.0,
        );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( '🏷️ Mennyiségi kedvezmény beállítások', 'mockup-generator' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Automatikus kosár-szintű kedvezmény a megrendelésben lévő darabszám alapján.', 'mockup-generator' ); ?>
            </p>
            <hr class="wp-header-end" />

            <form method="post" action="">
                <?php wp_nonce_field( 'mg_save_bundle_discount', 'mg_bundle_discount_nonce' ); ?>

                <table class="form-table" role="presentation">

                    <!-- Aktív -->
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
                            <p class="description">
                                <?php esc_html_e( 'Ha ki van kapcsolva, a kedvezmény nem kerül alkalmazásra és nem jelenik meg a kosárban.', 'mockup-generator' ); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Típus -->
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e( 'Kedvezmény típusa', 'mockup-generator' ); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="type" value="fixed"
                                        <?php checked( $settings['type'], 'fixed' ); ?> />
                                    <?php esc_html_e( 'Fix összeg (Ft)', 'mockup-generator' ); ?>
                                </label>
                                <br />
                                <label>
                                    <input type="radio" name="type" value="percent"
                                        <?php checked( $settings['type'], 'percent' ); ?> />
                                    <?php esc_html_e( 'Százalékos (% a kosár részösszegéből)', 'mockup-generator' ); ?>
                                </label>
                            </fieldset>
                            <p class="description" id="mg-bd-type-hint">
                                <?php esc_html_e( 'Fix módban az összegek Ft-ban értendők. Százalékos módban a kosár részösszegének százaléka kerül levonásra.', 'mockup-generator' ); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- 2 db kedvezmény -->
                    <tr>
                        <th scope="row">
                            <label for="mg-bd-qty2">
                                <?php esc_html_e( 'Kedvezmény 2 db esetén', 'mockup-generator' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" id="mg-bd-qty2" name="qty2_amount"
                                   value="<?php echo esc_attr( $settings['qty2_amount'] ); ?>"
                                   min="0" step="0.01" class="small-text" required />
                            <span class="mg-bd-unit"><?php echo esc_html( $settings['type'] === 'percent' ? '%' : 'Ft' ); ?></span>
                            <p class="description">
                                <?php esc_html_e( 'Ha a kosárban pontosan 2 db termék van, ennyi kedvezményt kap a vevő.', 'mockup-generator' ); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- 3+ db kedvezmény -->
                    <tr>
                        <th scope="row">
                            <label for="mg-bd-qty3">
                                <?php esc_html_e( 'Kedvezmény 3 vagy több db esetén', 'mockup-generator' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" id="mg-bd-qty3" name="qty3_amount"
                                   value="<?php echo esc_attr( $settings['qty3_amount'] ); ?>"
                                   min="0" step="0.01" class="small-text" required />
                            <span class="mg-bd-unit"><?php echo esc_html( $settings['type'] === 'percent' ? '%' : 'Ft' ); ?></span>
                            <p class="description">
                                <?php esc_html_e( 'Ha a kosárban 3 vagy több db termék van, ennyi kedvezményt kap a vevő.', 'mockup-generator' ); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button( __( 'Beállítások mentése', 'mockup-generator' ) ); ?>
            </form>

            <!-- Előnézet / segítség kártya -->
            <div class="card" style="max-width:520px; margin-top:24px; padding:16px 20px;">
                <h2 style="font-size:1em; margin-top:0;"><?php esc_html_e( 'Megjelenési példa a kosárban', 'mockup-generator' ); ?></h2>
                <table class="widefat" style="background:#fff;">
                    <tbody>
                        <tr>
                            <td><?php esc_html_e( 'Részösszeg', 'mockup-generator' ); ?></td>
                            <td>12 000 Ft</td>
                        </tr>
                        <tr style="color:#c0392b; font-weight:600;">
                            <td>🏷️ <?php esc_html_e( 'Mennyiségi kedvezmény', 'mockup-generator' ); ?></td>
                            <td>-990 Ft <small style="font-weight:normal;">(990 Ft megtakarítás)</small></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Összesen', 'mockup-generator' ); ?></strong></td>
                            <td><strong>11 010 Ft</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        (function () {
            var radios = document.querySelectorAll('input[name="type"]');
            var units  = document.querySelectorAll('.mg-bd-unit');
            function updateUnit() {
                var isPercent = document.querySelector('input[name="type"]:checked');
                if (!isPercent) return;
                var label = isPercent.value === 'percent' ? '%' : 'Ft';
                units.forEach(function (el) { el.textContent = label; });
            }
            radios.forEach(function (r) { r.addEventListener('change', updateUnit); });
        })();
        </script>
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

        $data = array(
            'enabled'     => ! empty( $_POST['enabled'] ),
            'type'        => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'fixed',
            'qty2_amount' => isset( $_POST['qty2_amount'] ) ? floatval( wp_unslash( $_POST['qty2_amount'] ) ) : 0.0,
            'qty3_amount' => isset( $_POST['qty3_amount'] ) ? floatval( wp_unslash( $_POST['qty3_amount'] ) ) : 0.0,
        );

        if ( class_exists( 'MG_Bundle_Discount' ) ) {
            MG_Bundle_Discount::save_settings( $data );
        } else {
            // Fallback – direkt mentés ha az osztály valamilyen okból nem töltődött be
            $clean = array(
                'enabled'     => $data['enabled'],
                'type'        => in_array( $data['type'], array( 'fixed', 'percent' ), true ) ? $data['type'] : 'fixed',
                'qty2_amount' => max( 0.0, $data['qty2_amount'] ),
                'qty3_amount' => max( 0.0, $data['qty3_amount'] ),
            );
            update_option( 'mg_bundle_discount', $clean, false );
        }

        add_settings_error(
            'mg_bundle_discount',
            'saved',
            __( 'Mennyiségi kedvezmény beállításai elmentve.', 'mockup-generator' ),
            'updated'
        );
    }
}
