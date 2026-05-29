<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Facebook_Pixel_Settings
 *
 * Admin beállítási felület a Meta (Facebook) Pixel integrációhoz.
 */
class MG_Facebook_Pixel_Settings {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
        add_action('admin_post_mg_fb_pixel_save', array(__CLASS__, 'handle_save'));
    }

    public static function add_settings_page() {
        add_submenu_page(
            'mockup-generator',
            'Facebook Pixel',
            'Facebook Pixel',
            'manage_options',
            'mg-fb-pixel-settings',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_fb_pixel_save_action');

        $input = isset($_POST['mg_fb_pixel_settings']) ? $_POST['mg_fb_pixel_settings'] : array();

        $saved = array(
            'pixel_id'        => isset($input['pixel_id'])        ? trim(wp_strip_all_tags($input['pixel_id']))        : '',
            'access_token'    => isset($input['access_token'])    ? trim(wp_strip_all_tags($input['access_token']))    : '',
            'test_event_code' => isset($input['test_event_code']) ? trim(wp_strip_all_tags($input['test_event_code'])) : '',
        );

        update_option('mg_fb_pixel_settings', $saved);

        wp_redirect(admin_url('admin.php?page=mg-fb-pixel-settings&updated=1'));
        exit;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('mg_fb_pixel_settings', array('pixel_id' => '', 'access_token' => '', 'test_event_code' => ''));
        ?>
        <div class="wrap">
            <h1>Meta (Facebook) Pixel (Mockup Generator)</h1>

            <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong>Beállítások elmentve.</strong></p></div>
            <?php endif; ?>

            <p>A Meta Pixel automatikusan beküldi az összes fontos vásárlói eseményt (PageView, ViewContent, AddToCart, InitiateCheckout, Purchase) a virtuális variáns ID-kkal. Advanced Matching (hashelt vásárlóadatok) automatikusan működik a Purchase eseménynél.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_fb_pixel_save">
                <?php wp_nonce_field('mg_fb_pixel_save_action'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pixel_id">Pixel ID (Pl.: 123456789012345)</label></th>
                        <td>
                            <input type="text" id="pixel_id" name="mg_fb_pixel_settings[pixel_id]" value="<?php echo esc_attr($settings['pixel_id'] ?? ''); ?>" class="regular-text" placeholder="123456789012345" />
                            <p class="description">Meta Business Suite → Eseménykezelő → Adatforrások. Csak a számokat add meg.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="access_token">Conversions API Access Token</label></th>
                        <td>
                            <input type="text" id="access_token" name="mg_fb_pixel_settings[access_token]" value="<?php echo esc_attr($settings['access_token'] ?? ''); ?>" class="large-text" placeholder="EAAxxxxxxxx..." />
                            <p class="description">Meta Business Suite → Eseménykezelő → <strong>Beállítások → Conversions API → Token generálása</strong>. Ha üres, a CAPI ki van kapcsolva.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="test_event_code">Test Event Code <em>(opcionális)</em></label></th>
                        <td>
                            <input type="text" id="test_event_code" name="mg_fb_pixel_settings[test_event_code]" value="<?php echo esc_attr($settings['test_event_code'] ?? ''); ?>" class="regular-text" placeholder="TEST12345" />
                            <p class="description">Eseménykezelő → Test Events alatt találod. Csak tesztelés alatt töltsd ki – éles üzemben hagyd üresen!</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Beállítások mentése'); ?>
            </form>

            <hr style="margin-top: 40px; margin-bottom: 20px;">

            <h2>Beküldött események</h2>
            <table class="widefat" style="max-width: 700px;">
                <thead>
                    <tr>
                        <th>Facebook esemény</th>
                        <th>Mikor tüzel</th>
                        <th>Adatok</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>PageView</strong></td>
                        <td>Minden oldal betöltésekor (beleegyezés után)</td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td><strong>ViewContent</strong></td>
                        <td>Termékoldal megtekintésekor</td>
                        <td>content_ids (variáns ID), value, currency</td>
                    </tr>
                    <tr>
                        <td><strong>AddToCart</strong></td>
                        <td>Kosárba gomb kattintásakor</td>
                        <td>content_ids (variáns ID), value, currency, quantity</td>
                    </tr>
                    <tr>
                        <td><strong>InitiateCheckout</strong></td>
                        <td>Pénztár oldal megnyitásakor</td>
                        <td>content_ids, value, currency, num_items</td>
                    </tr>
                    <tr>
                        <td><strong>Purchase</strong></td>
                        <td>Köszönöm oldalon (sikeres rendelés)</td>
                        <td>content_ids, value, currency, num_items + Advanced Matching<br><em>+ CAPI (szerver-oldali) – ha Access Token meg van adva</em></td>
                    </tr>
                </tbody>
            </table>

            <h2 style="margin-top: 30px;">Advanced Matching (Purchase)</h2>
            <p>A Purchase eseménynél az alábbi vásárlóadatokat küldi be a rendszer SHA-256 hashelt formában:</p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>Email cím</li>
                <li>Telefonszám (E.164 formátumban, +36-os előhívóval)</li>
                <li>Keresztnév és Vezetéknév</li>
                <li>Város, Irányítószám, Ország</li>
            </ul>

            <hr style="margin-top: 40px; margin-bottom: 20px;">
            <h2>Fontos figyelmeztetés!</h2>
            <div class="notice notice-warning inline">
                <p>Ha ezt a modult használod, <strong>távolítsd el</strong> az összes többi Facebook Pixel plugin-t (pl. PixelYourSite, Facebook for WooCommerce / Meta for WooCommerce), különben a Purchase esemény duplán fog bemenni!</p>
                <p>A GDPR Consent kezelés automatikusan összekötve az <code>mg_gads_consent</code> eseménnyel – ugyanaz a cookie banner kezeli, mint a Google Ads modult.</p>
            </div>
        </div>
        <?php
    }
}
