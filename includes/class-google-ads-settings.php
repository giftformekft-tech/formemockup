<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Google_Ads_Settings
 *
 * Regisztrálja a beállítási felületet a Google Ads követéshez.
 */
class MG_Google_Ads_Settings {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
        add_action('admin_post_mg_gads_save', array(__CLASS__, 'handle_save'));
    }

    public static function add_settings_page() {
        add_submenu_page(
            'mockup-generator',
            'Google Ads Követés',
            'Google Ads Követés',
            'manage_options',
            'mg-gads-settings',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_gads_save_action');

        $input = isset($_POST['mg_gads_settings']) ? $_POST['mg_gads_settings'] : array();

        $saved = array(
            'conversion_id'  => isset($input['conversion_id'])  ? trim(wp_strip_all_tags($input['conversion_id']))  : '',
            'purchase_label' => isset($input['purchase_label']) ? trim(wp_strip_all_tags($input['purchase_label'])) : '',
        );

        update_option('mg_gads_settings', $saved);

        wp_redirect(admin_url('admin.php?page=mg-gads-settings&updated=1'));
        exit;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('mg_gads_settings', array('conversion_id' => '', 'purchase_label' => ''));
        ?>
        <div class="wrap">
            <h1>Google Ads Konverziókövetés (Mockup Generator)</h1>

            <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong>Beállítások elmentve.</strong></p></div>
            <?php endif; ?>

            <p>Itt állíthatod be a Google Ads konverziókövetés (gtag.js) azonosítóit. Ha ezeket kitöltöd, a rendszer automatikusan beküldi a megfelelő virtuális variáns ID-kat és árakat a Google felé.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_gads_save">
                <?php wp_nonce_field('mg_gads_save_action'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="conversion_id">Conversion ID (Pl.: AW-678723674)</label></th>
                        <td>
                            <input type="text" id="conversion_id" name="mg_gads_settings[conversion_id]" value="<?php echo esc_attr($settings['conversion_id'] ?? ''); ?>" class="regular-text" />
                            <p class="description">A Google Ads fiókod azonosítója. Ezt használja az alap követőkód (gtag) és a remarketing.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="purchase_label">Purchase Label (Pl.: FITDCNCqwP8bENqA0sMC)</label></th>
                        <td>
                            <input type="text" id="purchase_label" name="mg_gads_settings[purchase_label]" value="<?php echo esc_attr($settings['purchase_label'] ?? ''); ?>" class="regular-text" />
                            <p class="description">A "Vásárlás" (Purchase) konverziós művelethez tartozó specifikus címke. Ezt csak a Köszönöm oldalon küldjük be.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Beállítások mentése'); ?>
            </form>

            <hr style="margin-top: 40px; margin-bottom: 20px;">
            <h2>Fontos figyelmeztetés!</h2>
            <div class="notice notice-warning inline">
                <p>Ha ezt a modult használod, <strong>MINDEN MÁS</strong> Google Ads követő plugint (például GTM4WP, Google Listings and Ads, Site Kit) ki kell kapcsolnod az E-kereskedelmi / Konverzió követés tekintetében!</p>
                <p>Különben duplán fognak bemenni a konverziók: egyszer a mi jó, variánsokat ismerő "ID_123_ferfi-polo" kódunkkal, egyszer pedig a másik plugin hibás "123" hivatkozásával.</p>
            </div>
        </div>
        <?php
    }
}
