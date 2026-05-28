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
        add_action('admin_init', array(__CLASS__, 'register_settings'));
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

    public static function register_settings() {
        register_setting('mg_fb_pixel_settings_group', 'mg_fb_pixel_settings', array(__CLASS__, 'sanitize_settings'));
    }

    public static function sanitize_settings($input) {
        $sanitized = array();
        if (isset($input['pixel_id'])) {
            $sanitized['pixel_id'] = sanitize_text_field($input['pixel_id']);
        }
        return $sanitized;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('mg_fb_pixel_settings', array('pixel_id' => ''));
        ?>
        <div class="wrap">
            <h1>Meta (Facebook) Pixel (Mockup Generator)</h1>
            <p>A Meta Pixel automatikusan beküldi az összes fontos vásárlói eseményt (PageView, ViewContent, AddToCart, InitiateCheckout, Purchase) a virtuális variáns ID-kkal. Advanced Matching (hashelt vásárlóadatok) automatikusan működik a Purchase eseménynél.</p>

            <form method="post" action="options.php">
                <?php settings_fields('mg_fb_pixel_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pixel_id">Pixel ID (Pl.: 123456789012345)</label></th>
                        <td>
                            <input type="text" id="pixel_id" name="mg_fb_pixel_settings[pixel_id]" value="<?php echo esc_attr($settings['pixel_id'] ?? ''); ?>" class="regular-text" placeholder="123456789012345" />
                            <p class="description">A Meta Business Suite → Eseménykezelő → Adatforrások alatt találod. Csak a számokat add meg.</p>
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
                        <td>content_ids, value, currency, num_items + Advanced Matching</td>
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
