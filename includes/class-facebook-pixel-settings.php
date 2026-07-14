<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Meta Pixel/CAPI settings and order-level delivery diagnostics. */
class MG_Facebook_Pixel_Settings {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
        add_action('admin_post_mg_fb_pixel_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_mg_meta_retry_order', array(__CLASS__, 'handle_retry_order'));
    }

    public static function add_settings_page() {
        add_submenu_page(
            'mockup-generator',
            'Meta mérés',
            'Meta mérés',
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
        $input = isset($_POST['mg_fb_pixel_settings']) && is_array($_POST['mg_fb_pixel_settings'])
            ? wp_unslash($_POST['mg_fb_pixel_settings'])
            : array();
        $old = class_exists('MG_Facebook_Pixel_Reliability')
            ? MG_Facebook_Pixel_Reliability::get_settings()
            : get_option('mg_fb_pixel_settings', array());

        $allowed = array('pending', 'on-hold', 'processing', 'completed');
        $statuses = array_values(array_intersect($allowed, array_map('sanitize_key', (array) ($input['eligible_statuses'] ?? array()))));
        if (!$statuses) {
            $statuses = array('processing', 'completed');
        }

        $token = trim((string) ($input['access_token'] ?? ''));
        if (!empty($input['delete_access_token'])) {
            $token = '';
        } elseif ($token === '') {
            $token = (string) ($old['access_token'] ?? '');
        }

        $saved = array(
            'pixel_id' => self::digits($input['pixel_id'] ?? ''),
            'access_token' => sanitize_text_field($token),
            'test_event_code' => sanitize_text_field($input['test_event_code'] ?? ''),
            'server_side_enabled' => !empty($input['server_side_enabled']) ? 1 : 0,
            'eligible_statuses' => $statuses,
        );
        update_option('mg_fb_pixel_settings', $saved, false);

        $queued = 0;
        if ($saved['server_side_enabled'] && class_exists('MG_Facebook_Pixel_Reliability')) {
            $queued = MG_Facebook_Pixel_Reliability::backfill_recent_orders(6);
        }
        wp_safe_redirect(admin_url('admin.php?page=mg-fb-pixel-settings&updated=1&queued=' . absint($queued)));
        exit;
    }

    public static function handle_retry_order() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        $order_id = absint($_GET['order_id'] ?? 0);
        check_admin_referer('mg_meta_retry_' . $order_id);
        if ($order_id && class_exists('MG_Facebook_Pixel_Reliability')) {
            $order = wc_get_order($order_id);
            if ($order) {
                foreach (array(
                    MG_Facebook_Pixel_Reliability::META_STATUS,
                    MG_Facebook_Pixel_Reliability::META_ATTEMPTS,
                    MG_Facebook_Pixel_Reliability::META_LAST_ERROR,
                    MG_Facebook_Pixel_Reliability::META_TRACE_ID,
                ) as $meta_key) {
                    $order->delete_meta_data($meta_key);
                }
                $order->save();
                MG_Facebook_Pixel_Reliability::queue_purchase($order_id);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=mg-fb-pixel-settings&retried=' . $order_id));
        exit;
    }

    private static function digits($value) {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = class_exists('MG_Facebook_Pixel_Reliability')
            ? MG_Facebook_Pixel_Reliability::get_settings()
            : get_option('mg_fb_pixel_settings', array());
        $has_token = defined('MG_META_CAPI_ACCESS_TOKEN') || !empty($settings['access_token']);
        ?>
        <div class="wrap">
            <h1>Meta – pontos Pixel és Conversions API mérés</h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Beállítások elmentve.</strong> <?php echo absint($_GET['queued'] ?? 0); ?> korábbi rendelés került CAPI-sorba.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['retried'])): ?>
                <div class="notice notice-success is-dismissible"><p>A #<?php echo absint($_GET['retried']); ?> rendelést újra sorba állítottuk.</p></div>
            <?php endif; ?>
            <?php if (!empty($settings['test_event_code'])): ?>
                <div class="notice notice-warning"><p><strong>Teszt mód aktív:</strong> a szerveroldali események a Meta Test Events nézetébe kerülnek. Élesítés előtt töröld a tesztkódot.</p></div>
            <?php endif; ?>

            <p>A böngészős Pixel és a tartós CAPI ugyanazt a rendelésszámot használja <code>event_id</code>-ként. A CAPI csak engedélyezett consent és mérhető rendelésállapot esetén küld, a Meta válaszát ellenőrzi, hiba esetén pedig újrapróbálkozik.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_fb_pixel_save">
                <?php wp_nonce_field('mg_fb_pixel_save_action'); ?>

                <h2>Pixel és vásárlási feltételek</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mg-meta-pixel-id">Meta Pixel ID</label></th>
                        <td><input id="mg-meta-pixel-id" class="regular-text" name="mg_fb_pixel_settings[pixel_id]" value="<?php echo esc_attr($settings['pixel_id']); ?>" placeholder="123456789012345"></td>
                    </tr>
                    <tr>
                        <th>Mérhető rendelésállapotok</th>
                        <td>
                            <?php foreach (array('pending' => 'Fizetésre vár', 'on-hold' => 'Fizetés folyamatban', 'processing' => 'Feldolgozás alatt', 'completed' => 'Teljesítve') as $key => $label): ?>
                                <label style="display:block;margin:4px 0"><input type="checkbox" name="mg_fb_pixel_settings[eligible_statuses][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $settings['eligible_statuses'], true)); ?>> <?php echo esc_html($label); ?> (<code><?php echo esc_html($key); ?></code>)</label>
                            <?php endforeach; ?>
                            <p class="description">Alapértelmezetten csak a <code>processing</code> és <code>completed</code> rendelés számít Purchase eseménynek.</p>
                        </td>
                    </tr>
                </table>

                <h2>Tartós szerveroldali mérés – Meta CAPI</h2>
                <table class="form-table">
                    <tr>
                        <th>Bekapcsolás</th>
                        <td><label><input type="checkbox" name="mg_fb_pixel_settings[server_side_enabled]" value="1" <?php checked(!empty($settings['server_side_enabled'])); ?>> Szerveroldali küldés, válaszellenőrzés és automatikus újrapróbálkozás</label></td>
                    </tr>
                    <tr>
                        <th><label for="mg-meta-token">Conversions API access token</label></th>
                        <td>
                            <input id="mg-meta-token" type="password" class="large-text" name="mg_fb_pixel_settings[access_token]" value="" placeholder="<?php echo $has_token ? 'Mentve – hagyd üresen a megőrzéshez' : 'EAA…'; ?>">
                            <p class="description">Állapot: <strong><?php echo $has_token ? 'beállítva' : 'hiányzik'; ?></strong>. A Meta Events Manager → Settings → Conversions API alatt generálható.</p>
                            <?php if ($has_token && !defined('MG_META_CAPI_ACCESS_TOKEN')): ?><label><input type="checkbox" name="mg_fb_pixel_settings[delete_access_token]" value="1"> Mentett token törlése</label><?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Graph API-verzió</th>
                        <td><code><?php echo esc_html(MG_Facebook_Pixel_Reliability::API_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <th><label for="mg-meta-test-code">Test Event Code</label></th>
                        <td><input id="mg-meta-test-code" class="regular-text" name="mg_fb_pixel_settings[test_event_code]" value="<?php echo esc_attr($settings['test_event_code']); ?>" placeholder="Éles üzemben üres"><p class="description">Csak rövid teszteléshez használd.</p></td>
                    </tr>
                </table>

                <?php submit_button('Meta mérési beállítások mentése'); ?>
            </form>

            <?php self::render_diagnostics(); ?>

            <div class="notice notice-warning inline" style="margin-top:24px"><p><strong>Duplikációvédelem:</strong> más Meta Pixel/CAPI plugin ugyanahhoz a Pixelhez ne küldjön külön Purchase eseményt.</p></div>
        </div>
        <?php
    }

    private static function render_diagnostics() {
        if (!function_exists('wc_get_orders') || !class_exists('MG_Facebook_Pixel_Reliability')) {
            return;
        }
        $orders = wc_get_orders(array(
            'limit' => 30,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ));
        $counts = array();
        foreach ($orders as $order) {
            $status = $order->get_meta(MG_Facebook_Pixel_Reliability::META_STATUS) ?: 'not_queued';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ?>
        <hr>
        <h2>Utolsó 30 rendelés Meta diagnosztikája</h2>
        <p>
            <?php foreach ($counts as $status => $count): ?>
                <span style="display:inline-block;padding:5px 9px;margin:0 6px 6px 0;background:#fff;border:1px solid #ccd0d4;border-radius:3px"><code><?php echo esc_html($status); ?></code>: <strong><?php echo absint($count); ?></strong></span>
            <?php endforeach; ?>
        </p>
        <table class="widefat striped">
            <thead><tr><th>Rendelés</th><th>WC állapot</th><th>CAPI</th><th>Consent</th><th>Attribution</th><th>Próba</th><th>Hiba / Trace ID</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order):
                $status = $order->get_meta(MG_Facebook_Pixel_Reliability::META_STATUS) ?: 'not_queued';
                $detail = $order->get_meta(MG_Facebook_Pixel_Reliability::META_LAST_ERROR) ?: $order->get_meta(MG_Facebook_Pixel_Reliability::META_TRACE_ID);
                $retry_url = wp_nonce_url(admin_url('admin-post.php?action=mg_meta_retry_order&order_id=' . $order->get_id()), 'mg_meta_retry_' . $order->get_id());
                ?>
                <tr>
                    <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>"><strong>#<?php echo esc_html($order->get_order_number()); ?></strong></a></td>
                    <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                    <td><code><?php echo esc_html($status); ?></code></td>
                    <td><?php echo esc_html($order->get_meta('_mg_meta_consent') ?: 'ismeretlen'); ?></td>
                    <td><?php echo ($order->get_meta('_mg_meta_fbc') || $order->get_meta('_mg_meta_fbp') || $order->get_meta('_mg_meta_fbclid')) ? 'igen' : 'nincs'; ?></td>
                    <td><?php echo absint($order->get_meta(MG_Facebook_Pixel_Reliability::META_ATTEMPTS)); ?></td>
                    <td style="max-width:360px;word-break:break-word"><?php echo esc_html($detail); ?></td>
                    <td><a class="button button-small" href="<?php echo esc_url($retry_url); ?>">Újrapróba</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
