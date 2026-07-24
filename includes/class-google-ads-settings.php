<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Admin settings and measurement diagnostics for Google Ads. */
class MG_Google_Ads_Settings {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
        add_action('admin_post_mg_gads_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_mg_gads_retry_order', array(__CLASS__, 'handle_retry_order'));
    }

    public static function add_settings_page() {
        add_submenu_page(
            'mockup-generator',
            'Google Ads mérés',
            'Google Ads mérés',
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

        $input = isset($_POST['mg_gads_settings']) && is_array($_POST['mg_gads_settings'])
            ? wp_unslash($_POST['mg_gads_settings'])
            : array();
        $old = class_exists('MG_Google_Ads_Reliability')
            ? MG_Google_Ads_Reliability::get_settings()
            : get_option('mg_gads_settings', array());

        $allowed_statuses = array('pending', 'on-hold', 'processing', 'completed');
        $statuses = array_intersect(
            $allowed_statuses,
            array_map('sanitize_key', (array) ($input['eligible_statuses'] ?? array()))
        );
        if (!$statuses) {
            $statuses = array('processing', 'completed');
        }

        $service_json = trim((string) ($input['service_account_json'] ?? ''));
        if (!empty($input['delete_service_account'])) {
            $service_json = '';
        } elseif ($service_json === '') {
            $service_json = (string) ($old['service_account_json'] ?? '');
        } else {
            $decoded = json_decode($service_json, true);
            if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
                wp_safe_redirect(admin_url('admin.php?page=mg-gads-settings&error=credentials'));
                exit;
            }
            $service_json = wp_json_encode($decoded);
        }

        $developer_token = trim((string) ($input['developer_token'] ?? ''));
        if (!empty($input['delete_developer_token'])) {
            $developer_token = '';
        } elseif ($developer_token === '') {
            $developer_token = (string) ($old['developer_token'] ?? '');
        }

        $saved = array(
            'conversion_id' => self::conversion_id($input['conversion_id'] ?? ''),
            'purchase_label' => sanitize_text_field($input['purchase_label'] ?? ''),
            'eligible_statuses' => array_values($statuses),
            'server_side_enabled' => !empty($input['server_side_enabled']) ? 1 : 0,
            'customer_id' => self::digits($input['customer_id'] ?? ''),
            'login_customer_id' => self::digits($input['login_customer_id'] ?? ''),
            'conversion_action_id' => self::digits($input['conversion_action_id'] ?? ''),
            'service_account_json' => $service_json,
            'developer_token' => sanitize_text_field($developer_token),
            'adjustments_enabled' => !empty($input['adjustments_enabled']) ? 1 : 0,
            'merchant_id' => self::digits($input['merchant_id'] ?? ''),
            'feed_country' => strtoupper(substr(sanitize_text_field($input['feed_country'] ?? 'HU'), 0, 10)),
            'feed_language' => strtolower(substr(sanitize_text_field($input['feed_language'] ?? 'hu'), 0, 10)),
            'validate_only' => !empty($input['validate_only']) ? 1 : 0,
        );
        update_option('mg_gads_settings', $saved, false);

        $queued = 0;
        if ($saved['server_side_enabled'] && class_exists('MG_Google_Ads_Reliability')) {
            $queued = MG_Google_Ads_Reliability::backfill_recent_orders(30);
        }
        if ($saved['adjustments_enabled'] && class_exists('MG_Google_Ads_Reliability')) {
            $queued += MG_Google_Ads_Reliability::backfill_pending_adjustments(90);
        }
        wp_safe_redirect(admin_url('admin.php?page=mg-gads-settings&updated=1&queued=' . absint($queued)));
        exit;
    }

    public static function handle_retry_order() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        $order_id = absint($_GET['order_id'] ?? 0);
        check_admin_referer('mg_gads_retry_' . $order_id);
        if ($order_id && class_exists('MG_Google_Ads_Reliability')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->delete_meta_data(MG_Google_Ads_Reliability::META_REQUEST_ID);
                $order->delete_meta_data(MG_Google_Ads_Reliability::META_STATUS);
                $order->delete_meta_data(MG_Google_Ads_Reliability::META_ATTEMPTS);
                $order->save();
                MG_Google_Ads_Reliability::queue_purchase($order_id);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=mg-gads-settings&retried=' . $order_id));
        exit;
    }

    private static function digits($value) {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    private static function conversion_id($value) {
        $value = strtoupper(trim(sanitize_text_field((string) $value)));
        return preg_match('/^AW-[0-9]+$/', $value) ? $value : '';
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = class_exists('MG_Google_Ads_Reliability')
            ? MG_Google_Ads_Reliability::get_settings()
            : get_option('mg_gads_settings', array());
        $has_service_account = !empty($settings['service_account_json']) || defined('MG_GADS_SERVICE_ACCOUNT_JSON');
        $has_developer_token = !empty($settings['developer_token']);
        ?>
        <div class="wrap">
            <h1>Google Ads – tűpontos vásárlásmérés</h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Beállítások elmentve.</strong> <?php echo absint($_GET['queued'] ?? 0); ?> korábbi rendelés került ellenőrzési sorba.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['retried'])): ?>
                <div class="notice notice-success is-dismissible"><p>A #<?php echo absint($_GET['retried']); ?> rendelést újra sorba állítottuk.</p></div>
            <?php endif; ?>
            <?php if (($_GET['error'] ?? '') === 'credentials'): ?>
                <div class="notice notice-error"><p>A service account JSON hibás: a <code>client_email</code> és <code>private_key</code> mező kötelező.</p></div>
            <?php endif; ?>
            <?php if (!empty($settings['conversion_id']) && empty($settings['purchase_label'])): ?>
                <div class="notice notice-error"><p><strong>Nincs Purchase Label.</strong> Címke nélkül a <code>send_to</code> csak a Google tag azonosítóját tartalmazza, így a Google Ads egyetlen vásárlást sem könyvel el konverzióként. Másold be a Vásárlás konverziós művelet címkéjét.</p></div>
            <?php endif; ?>

            <p><strong>A Google API nem szükséges a pontos alapméréshez.</strong> A böngészős Google tag Consent Mode v2-vel, Enhanced Conversions adatokkal, egyedi tranzakcióazonosítóval és teljes kosáradattal önállóan is működik. Ha nincs API-hozzáférésed, a szerveroldali részt hagyd kikapcsolva.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_gads_save">
                <?php wp_nonce_field('mg_gads_save_action'); ?>

                <h2>Böngészős mérés</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mg-gads-conversion-id">Conversion ID</label></th>
                        <td><input id="mg-gads-conversion-id" class="regular-text" name="mg_gads_settings[conversion_id]" value="<?php echo esc_attr($settings['conversion_id']); ?>" placeholder="AW-123456789"></td>
                    </tr>
                    <tr>
                        <th><label for="mg-gads-purchase-label">Purchase Label</label></th>
                        <td><input id="mg-gads-purchase-label" class="regular-text" name="mg_gads_settings[purchase_label]" value="<?php echo esc_attr($settings['purchase_label']); ?>" placeholder="AbCdEf..."><p class="description">A Google Ads Vásárlás konverziós művelet címkéje.</p></td>
                    </tr>
                    <tr>
                        <th>Mérhető rendelésállapotok</th>
                        <td>
                            <?php foreach (array('pending' => 'Fizetésre vár', 'on-hold' => 'Fizetés folyamatban', 'processing' => 'Feldolgozás alatt', 'completed' => 'Teljesítve') as $key => $label): ?>
                                <label style="display:block;margin:4px 0"><input type="checkbox" name="mg_gads_settings[eligible_statuses][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $settings['eligible_statuses'], true)); ?>> <?php echo esc_html($label); ?> (<code><?php echo esc_html($key); ?></code>)</label>
                            <?php endforeach; ?>
                            <p class="description">Alapértelmezetten csak a fizetett/feldolgozható rendelések számítanak. Átutalásnál az „on-hold” bekapcsolása üzleti döntés.</p>
                        </td>
                    </tr>
                </table>

                <h2>Opcionális szerveroldali kiegészítés – Google Data Manager API</h2>
                <table class="form-table">
                    <tr>
                        <th>Bekapcsolás</th>
                        <td><label><input type="checkbox" name="mg_gads_settings[server_side_enabled]" value="1" <?php checked(!empty($settings['server_side_enabled'])); ?>> Tartós szerveroldali küldés, újrapróbálkozás és feldolgozás-ellenőrzés</label><p class="description">Nem kötelező. API nélkül a Conversion ID és Purchase Label mezőkkel beállított böngészős mérés marad aktív.</p></td>
                    </tr>
                    <tr>
                        <th><label for="mg-gads-customer">Google Ads ügyfélazonosító</label></th>
                        <td><input id="mg-gads-customer" class="regular-text" name="mg_gads_settings[customer_id]" value="<?php echo esc_attr($settings['customer_id']); ?>" placeholder="1234567890"><p class="description">Kötőjelek nélkül.</p></td>
                    </tr>
                    <tr>
                        <th><label for="mg-gads-login-customer">MCC/login ügyfélazonosító</label></th>
                        <td><input id="mg-gads-login-customer" class="regular-text" name="mg_gads_settings[login_customer_id]" value="<?php echo esc_attr($settings['login_customer_id']); ?>" placeholder="opcionális"><p class="description">Csak akkor kell, ha a service account egy kezelői fiókon keresztül fér hozzá.</p></td>
                    </tr>
                    <tr>
                        <th><label for="mg-gads-action">Konverziós művelet ID</label></th>
                        <td><input id="mg-gads-action" class="regular-text" name="mg_gads_settings[conversion_action_id]" value="<?php echo esc_attr($settings['conversion_action_id']); ?>" placeholder="123456789"><p class="description">Google Ads → Konverzió részletei; az URL <code>ctId</code> értéke. Ugyanaz a művelet legyen, mint a Purchase Labelnél.</p></td>
                    </tr>
                    <tr>
                        <th><label for="mg-gads-service-json">Service account JSON</label></th>
                        <td>
                            <textarea id="mg-gads-service-json" class="large-text code" rows="7" name="mg_gads_settings[service_account_json]" placeholder="A mentett kulcs megőrzéséhez hagyd üresen."></textarea>
                            <p class="description">Állapot: <strong><?php echo $has_service_account ? 'beállítva' : 'hiányzik'; ?></strong>. A Google Cloudban engedélyezni kell a Data Manager API-t, a service account e-mailjét pedig fel kell venni a Google Ads fiók felhasználói közé.</p>
                            <?php if ($has_service_account && !defined('MG_GADS_SERVICE_ACCOUNT_JSON')): ?><label><input type="checkbox" name="mg_gads_settings[delete_service_account]" value="1"> Mentett kulcs törlése</label><?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Teszt mód</th>
                        <td><label><input type="checkbox" name="mg_gads_settings[validate_only]" value="1" <?php checked(!empty($settings['validate_only'])); ?>> Csak validálás, éles konverzió létrehozása nélkül</label></td>
                    </tr>
                </table>

                <h2>Merchant Center kosáradatok</h2>
                <table class="form-table">
                    <tr><th>Merchant Center ID</th><td><input class="regular-text" name="mg_gads_settings[merchant_id]" value="<?php echo esc_attr($settings['merchant_id']); ?>"></td></tr>
                    <tr><th>Feed ország/címke</th><td><input class="small-text" name="mg_gads_settings[feed_country]" value="<?php echo esc_attr($settings['feed_country']); ?>" placeholder="HU"></td></tr>
                    <tr><th>Feed nyelv</th><td><input class="small-text" name="mg_gads_settings[feed_language]" value="<?php echo esc_attr($settings['feed_language']); ?>" placeholder="hu"></td></tr>
                </table>

                <h2>Refund és törlés korrekció</h2>
                <table class="form-table">
                    <tr>
                        <th>Google Ads API</th>
                        <td><label><input type="checkbox" name="mg_gads_settings[adjustments_enabled]" value="1" <?php checked(!empty($settings['adjustments_enabled'])); ?>> Teljes refund/törlés visszavonása, részleges refund értékkorrekciója</label></td>
                    </tr>
                    <tr>
                        <th><label for="mg-gads-developer-token">Developer token</label></th>
                        <td><input id="mg-gads-developer-token" type="password" class="regular-text" name="mg_gads_settings[developer_token]" value="" placeholder="<?php echo $has_developer_token ? 'Mentve – hagyd üresen a megőrzéshez' : 'Google Ads API Center token'; ?>"><p class="description">Csak a korrekciókhoz szükséges; MCC fiók API Center oldaláról kérhető.</p><?php if ($has_developer_token): ?><label><input type="checkbox" name="mg_gads_settings[delete_developer_token]" value="1"> Mentett token törlése</label><?php endif; ?></td>
                    </tr>
                </table>

                <?php submit_button('Mérési beállítások mentése'); ?>
            </form>

            <?php self::render_diagnostics(); ?>

            <div class="notice notice-warning inline" style="margin-top:24px"><p><strong>Duplikációvédelem:</strong> más Google Ads/WooCommerce mérőplugin ugyanahhoz vagy másik Purchase konverziós művelethez ne küldjön külön vásárlást.</p></div>
        </div>
        <?php
    }

    private static function render_diagnostics() {
        if (!function_exists('wc_get_orders')) {
            return;
        }
        $orders = wc_get_orders(array(
            'limit' => 30,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ));
        $counts = array();
        $unmeasured = 0;
        $consent_unknown = 0;
        foreach ($orders as $order) {
            $status = $order->get_meta(MG_Google_Ads_Reliability::META_STATUS) ?: 'not_queued';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if (!$order->get_meta('_mg_gads_browser_fired') && !in_array($status, array('accepted', 'processing', 'processed', 'validated'), true)) {
                $unmeasured++;
            }
            if (!in_array((string) $order->get_meta('_mg_gads_consent'), array('granted', 'denied'), true)) {
                $consent_unknown++;
            }
        }
        $total = max(1, count($orders));
        ?>
        <hr>
        <h2>Utolsó 30 rendelés mérési diagnosztikája</h2>
        <?php if ($unmeasured > 0): ?>
            <div class="notice notice-warning inline"><p><strong><?php echo absint($unmeasured); ?> rendelésnél nincs igazolt vásárlásmérés.</strong> Se böngészős elküldés, se elfogadott szerveroldali feltöltés nem történt – ezek a konverziók hiányoznak a Google Adsből.</p></div>
        <?php endif; ?>
        <?php if ($consent_unknown === count($orders) && count($orders) > 0): ?>
            <div class="notice notice-error inline"><p><strong>Egyetlen rendelésnél sincs eltárolt hozzájárulási állapot.</strong> Ez általában azt jelenti, hogy a sütikezelő nem jut el a mérőmodulokhoz. A consent híd a bevett sütikezelőket (WP Consent API, Complianz, CookieYes, Cookiebot, Borlabs, Iubenda, Moove, Cookie Notice) automatikusan felismeri; ha egyedi bannert használsz, az elfogadáskor küldjön <code>mg_gads_consent</code> eseményt.</p></div>
        <?php endif; ?>
        <p>
            <?php foreach ($counts as $status => $count): ?>
                <span style="display:inline-block;padding:5px 9px;margin:0 6px 6px 0;background:#fff;border:1px solid #ccd0d4;border-radius:3px"><code><?php echo esc_html($status); ?></code>: <strong><?php echo absint($count); ?></strong></span>
            <?php endforeach; ?>
            <span style="display:inline-block;padding:5px 9px;margin:0 6px 6px 0;background:#fff;border:1px solid #ccd0d4;border-radius:3px">ismeretlen consent: <strong><?php echo absint($consent_unknown); ?></strong> / <?php echo absint($total); ?></span>
        </p>
        <table class="widefat striped">
            <thead><tr><th>Rendelés</th><th>WC állapot</th><th>Böngészős mérés</th><th>Server mérés</th><th>Korrekció</th><th>Consent</th><th>Click ID</th><th>Hiba / Request ID</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order):
                $status = $order->get_meta(MG_Google_Ads_Reliability::META_STATUS) ?: 'not_queued';
                if ($order->get_meta('_mg_conv_recovered_google')) {
                    $browser_state = 'pótolva';
                } elseif ($order->get_meta('_mg_gads_browser_fired')) {
                    $browser_state = 'elküldve';
                } elseif ($order->get_meta('_mg_gads_browser_rendered')) {
                    $browser_state = 'kiírva';
                } else {
                    $browser_state = 'nincs';
                }
                $detail = $order->get_meta(MG_Google_Ads_Reliability::META_LAST_ERROR) ?: $order->get_meta(MG_Google_Ads_Reliability::META_REQUEST_ID);
                $retry_url = wp_nonce_url(admin_url('admin-post.php?action=mg_gads_retry_order&order_id=' . $order->get_id()), 'mg_gads_retry_' . $order->get_id());
                ?>
                <tr>
                    <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>"><strong>#<?php echo esc_html($order->get_order_number()); ?></strong></a><br><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : ''); ?></td>
                    <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                    <td><?php echo esc_html($browser_state); ?></td>
                    <td><code><?php echo esc_html($status); ?></code></td>
                    <td><code><?php echo esc_html($order->get_meta('_mg_gads_adjustment_status') ?: '—'); ?></code></td>
                    <td><?php echo esc_html($order->get_meta('_mg_gads_consent') ?: 'ismeretlen'); ?></td>
                    <td><?php echo ($order->get_meta('_mg_gads_gclid') || $order->get_meta('_mg_gads_gbraid') || $order->get_meta('_mg_gads_wbraid')) ? 'igen' : 'nincs'; ?></td>
                    <td style="max-width:360px;word-break:break-word"><?php echo esc_html($detail); ?></td>
                    <td><a class="button button-small" href="<?php echo esc_url($retry_url); ?>">Újrapróba</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
