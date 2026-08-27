<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Admin page for the Google Ads Scripts based product classifier. */
class MG_Google_Ads_Product_Performance_Page {
    const PAGE_SLUG = 'mg-gads-product-performance';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'));
        add_action('admin_post_mg_gads_performance_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_mg_gads_performance_initial', array(__CLASS__, 'handle_initial_classification'));
        add_action('admin_post_mg_gads_performance_rolling', array(__CLASS__, 'handle_rolling_classification'));
        add_action('admin_post_mg_gads_performance_rotate_secret', array(__CLASS__, 'handle_rotate_secret'));
    }

    public static function add_settings_page() {
        add_submenu_page(
            'mockup-generator',
            'PMax termékbesorolás',
            'PMax besorolás',
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    public static function handle_save() {
        self::authorize('mg_gads_performance_save');
        $input = isset($_POST['mg_gads_performance']) && is_array($_POST['mg_gads_performance'])
            ? wp_unslash($_POST['mg_gads_performance'])
            : array();
        MG_Google_Ads_Product_Performance::save_settings($input);
        self::redirect(array('updated' => 1));
    }

    public static function handle_initial_classification() {
        self::authorize('mg_gads_performance_initial');
        $result = MG_Google_Ads_Product_Performance::run_initial_classification();
        if (is_wp_error($result)) {
            self::redirect(array('error' => $result->get_error_message()));
        }
        self::redirect(array('classified' => 'initial'));
    }

    public static function handle_rolling_classification() {
        self::authorize('mg_gads_performance_rolling');
        $result = MG_Google_Ads_Product_Performance::run_rolling_classification();
        if (is_wp_error($result)) {
            self::redirect(array('error' => $result->get_error_message()));
        }
        self::redirect(array('classified' => 'rolling'));
    }

    public static function handle_rotate_secret() {
        self::authorize('mg_gads_performance_rotate_secret');
        MG_Google_Ads_Product_Performance::rotate_secret();
        self::redirect(array('secret_rotated' => 1));
    }

    private static function authorize($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer($nonce_action);
    }

    private static function redirect($args) {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        MG_Google_Ads_Product_Performance::maybe_install();
        $settings = MG_Google_Ads_Product_Performance::get_settings();
        $sync = MG_Google_Ads_Product_Performance::get_sync_status();
        $classification = MG_Google_Ads_Product_Performance::get_classification_status();
        $rows = MG_Google_Ads_Product_Performance::get_classifications(200);
        $coverage = self::get_data_coverage();
        $script = self::build_google_ads_script($settings);
        ?>
        <div class="wrap mg-gads-product-performance">
            <h1>PMax termékbesorolás</h1>
            <p>A Google Ads Scriptből érkező termékadatok alapján a rendszer automatikusan <code>winner</code>, <code>normal</code> vagy <code>loser</code> címkét ír a Merchant feedbe.</p>

            <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success inline"><p>A beállítások mentve.</p></div><?php endif; ?>
            <?php if (!empty($_GET['classified'])): ?><div class="notice notice-success inline"><p>A besorolás lefutott.</p></div><?php endif; ?>
            <?php if (!empty($_GET['secret_rotated'])): ?><div class="notice notice-warning inline"><p>Új importtitok készült. A Google Ads-fiókban a teljes scriptet cserélni kell.</p></div><?php endif; ?>
            <?php if (!empty($_GET['error'])): ?><div class="notice notice-error inline"><p><?php echo esc_html(wp_unslash($_GET['error'])); ?></p></div><?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:20px 0">
                <?php self::status_card('Importált időszak', $coverage['min_date'] ? $coverage['min_date'] . ' – ' . $coverage['max_date'] : 'Még nincs adat', number_format_i18n($coverage['rows']) . ' napi terméksor'); ?>
                <?php self::status_card('Utolsó Ads-import', !empty($sync['timestamp']) ? wp_date('Y-m-d H:i', $sync['timestamp']) : 'Még nem történt', !empty($sync) ? absint($sync['accepted']) . ' elfogadva / ' . absint($sync['rejected']) . ' elutasítva' . (!empty($sync['currency_code']) ? ' · ' . $sync['currency_code'] : '') : ''); ?>
                <?php self::status_card('Utolsó besorolás', !empty($classification['timestamp']) ? wp_date('Y-m-d H:i', $classification['timestamp']) : 'Még nem történt', !empty($classification['start']) ? $classification['start'] . ' – ' . $classification['end'] : ''); ?>
                <?php self::status_card('Feed címke', !empty($settings['enabled']) ? 'custom_label_' . absint($settings['label_slot']) : 'Kikapcsolva', !empty($settings['initial_completed_at']) ? 'Induló besorolás kész' : 'Induló besorolás szükséges'); ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_gads_performance_save">
                <?php wp_nonce_field('mg_gads_performance_save'); ?>

                <h2>Besorolási és feedbeállítások</h2>
                <table class="form-table">
                    <tr>
                        <th>Feedcímke bekapcsolása</th>
                        <td><label><input type="checkbox" name="mg_gads_performance[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>> A besorolás kerüljön bele a Google Merchant feedekbe</label></td>
                    </tr>
                    <tr>
                        <th><label for="mg-gads-label-slot">Google custom label</label></th>
                        <td>
                            <select id="mg-gads-label-slot" name="mg_gads_performance[label_slot]">
                                <?php for ($i = 0; $i <= 4; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected((int) $settings['label_slot'], $i); ?>>custom_label_<?php echo $i; ?><?php echo $i === 1 || $i === 4 ? ' – jelenleg szabad' : ' – meglévő feedadatot cserél'; ?></option>
                                <?php endfor; ?>
                            </select>
                            <p class="description">A jelenlegi feed a 0. helyen terméktípust, a 2–3. helyen kategóriát küld. Ha ezeket választod, a teljesítménycímke szándékosan a korábbi érték helyére kerül.</p>
                        </td>
                    </tr>
                    <tr><th><label for="mg-gads-winner">Winner küszöb</label></th><td><input id="mg-gads-winner" type="number" min="0.01" step="0.01" name="mg_gads_performance[winner_conversions]" value="<?php echo esc_attr($settings['winner_conversions']); ?>"> attribútált Purchase konverzió</td></tr>
                    <tr>
                        <th><label for="mg-gads-loser-basis">Loser feltétel</label></th>
                        <td>
                            <select id="mg-gads-loser-basis" name="mg_gads_performance[loser_basis]">
                                <option value="spend" <?php selected($settings['loser_basis'], 'spend'); ?>>Költés alapján</option>
                                <option value="clicks" <?php selected($settings['loser_basis'], 'clicks'); ?>>Kattintás alapján</option>
                            </select>
                            <p>0 eladás és legalább <input class="small-text" type="number" min="1" step="1" name="mg_gads_performance[loser_spend]" value="<?php echo esc_attr($settings['loser_spend']); ?>"> Ft költés</p>
                            <p>vagy kattintásos módban legalább <input id="mg-gads-loser" class="small-text" type="number" min="1" name="mg_gads_performance[loser_clicks]" value="<?php echo esc_attr($settings['loser_clicks']); ?>"> kattintás.</p>
                            <p class="description">A forintos küszöb a Google Ads-fiók pénznemét feltételezi. Az importkártyán ellenőrizd, hogy <code>HUF</code> érkezik.</p>
                        </td>
                    </tr>
                    <tr><th><label for="mg-gads-lag">Konverziós késés</label></th><td>Az utolsó <input id="mg-gads-lag" class="small-text" type="number" min="0" max="14" name="mg_gads_performance[conversion_lag_days]" value="<?php echo esc_attr($settings['conversion_lag_days']); ?>"> nap kimarad a döntésből</td></tr>
                    <tr><th>Heti automatizmus</th><td><label><input type="checkbox" name="mg_gads_performance[automation_enabled]" value="1" <?php checked(!empty($settings['automation_enabled'])); ?>> Az induló besorolás után hetente újraszámolás a teljes webshop-történetből</label><p class="description">Aki egyszer eléri a Winner-küszöböt, végleg Winner marad. Emiatt a szezonális minták korábbi sikere sem évül el.</p></td></tr>
                </table>

                <h2>Google Ads Script beállítása</h2>
                <table class="form-table">
                    <tr><th><label for="mg-gads-history">Új webshop indulása</label></th><td><input id="mg-gads-history" type="date" name="mg_gads_performance[history_start_date]" value="<?php echo esc_attr($settings['history_start_date']); ?>"><p class="description">Az első scriptfutás ettől a naptól tölti vissza a történeti adatokat.</p></td></tr>
                    <tr><th><label for="mg-gads-account">Google Ads customer ID</label></th><td><input id="mg-gads-account" class="regular-text" name="mg_gads_performance[ads_customer_id]" value="<?php echo esc_attr($settings['ads_customer_id']); ?>" placeholder="1234567890"><p class="description">Kötőjelek nélkül. Ha megadod, a WordPress más Ads-fiók importját elutasítja.</p></td></tr>
                    <tr><th><label for="mg-gads-action-name">Purchase művelet neve</label></th><td><input id="mg-gads-action-name" class="regular-text" name="mg_gads_performance[purchase_action_name]" value="<?php echo esc_attr($settings['purchase_action_name']); ?>" placeholder="Purchase"><p class="description">Pontosan a Google Adsben látható konverziós műveletnév. Ha üres, minden elsődleges konverzió összege számít.</p></td></tr>
                    <tr><th><label for="mg-gads-campaigns">PMax kampány ID-k</label></th><td><input id="mg-gads-campaigns" class="large-text" name="mg_gads_performance[campaign_ids]" value="<?php echo esc_attr($settings['campaign_ids']); ?>" placeholder="123456789,987654321"><p class="description">Opcionális. Vesszővel elválasztva; üresen minden PMax kampány számít.</p></td></tr>
                </table>

                <?php submit_button('Besorolási beállítások mentése'); ?>
            </form>

            <hr>
            <h2>Google Ads Script</h2>
            <ol>
                <li>Mentsd a fenti beállításokat.</li>
                <li>Google Ads → Eszközök → Tömeges műveletek → Szkriptek → új script.</li>
                <li>Másold be az alábbi teljes kódot, engedélyezd, majd futtasd kézzel egyszer a kilenchavi importhoz.</li>
                <li>Ha sikeres, állíts be napi ütemezést. A további futások mindig az utolsó 30 napot frissítik.</li>
            </ol>
            <textarea readonly class="large-text code" rows="28" onclick="this.select();"><?php echo esc_textarea($script); ?></textarea>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mg_gads_performance_rotate_secret'), 'mg_gads_performance_rotate_secret')); ?>" onclick="return confirm('Az eddigi script azonnal érvénytelenné válik. Folytatod?');">Importtitok cseréje</a></p>

            <hr>
            <h2>Besorolás futtatása</h2>
            <p>Az első teljes Ads-import után futtasd az induló besorolást. Minden későbbi futás is a megadott webshop-indulástól összesít, ezért a Winner státusz nem évül el.</p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mg_gads_performance_initial'), 'mg_gads_performance_initial')); ?>">Induló besorolás futtatása</a>
                <?php if (!empty($settings['initial_completed_at'])): ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mg_gads_performance_rolling'), 'mg_gads_performance_rolling')); ?>">Teljes besorolás futtatása most</a>
                <?php endif; ?>
            </p>

            <?php self::render_classification_summary($classification); ?>
            <?php self::render_product_table($rows); ?>
        </div>
        <?php
    }

    private static function status_card($title, $value, $detail) {
        echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:14px">';
        echo '<div style="color:#646970;margin-bottom:5px">' . esc_html($title) . '</div>';
        echo '<strong style="font-size:17px">' . esc_html($value) . '</strong>';
        if ($detail !== '') {
            echo '<div style="margin-top:5px;color:#646970">' . esc_html($detail) . '</div>';
        }
        echo '</div>';
    }

    private static function get_data_coverage() {
        global $wpdb;
        $table = MG_Google_Ads_Product_Performance::daily_table();
        $row = $wpdb->get_row("SELECT MIN(metric_date) AS min_date, MAX(metric_date) AS max_date, COUNT(*) AS rows FROM {$table}", ARRAY_A);
        return wp_parse_args(is_array($row) ? $row : array(), array('min_date' => '', 'max_date' => '', 'rows' => 0));
    }

    private static function render_classification_summary($classification) {
        if (empty($classification['counts'])) {
            return;
        }
        echo '<h2>Utolsó eredmény</h2><p>';
        foreach (array('winner' => 'Winner', 'normal' => 'Normal', 'loser' => 'Loser') as $key => $label) {
            echo '<span style="display:inline-block;padding:7px 11px;margin:0 8px 8px 0;background:#fff;border:1px solid #ccd0d4;border-radius:4px">' . esc_html($label) . ': <strong>' . absint($classification['counts'][$key] ?? 0) . '</strong></span>';
        }
        echo '<span style="display:inline-block;padding:7px 11px">Változás: <strong>' . absint($classification['changed'] ?? 0) . '</strong>, nem párosított offer ID: <strong>' . absint($classification['unmatched_count'] ?? 0) . '</strong></span></p>';
        if (!empty($classification['unmatched_sample'])) {
            echo '<details><summary>Nem párosított offer ID minták</summary><code>' . esc_html(implode(', ', $classification['unmatched_sample'])) . '</code></details>';
        }
    }

    private static function render_product_table($rows) {
        if (!$rows) {
            return;
        }
        echo '<h2>Termékbesorolások</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Termék</th><th>Feed státusz</th><th>Konverzió</th><th>Kattintás</th><th>Költség</th><th>Időszak</th><th>Indok</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $product = function_exists('wc_get_product') ? wc_get_product($row['product_id']) : null;
            $name = $product ? $product->get_name() : '#' . absint($row['product_id']);
            $url = get_edit_post_link($row['product_id']);
            $cost = ((float) $row['cost_micros']) / 1000000;
            $feed_status = MG_Google_Ads_Product_Performance::get_feed_label($row['product_id']);
            echo '<tr>';
            echo '<td>' . ($url ? '<a href="' . esc_url($url) . '"><strong>' . esc_html($name) . '</strong></a>' : esc_html($name)) . '</td>';
            echo '<td><code>' . esc_html($feed_status ?: $row['status']) . '</code></td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row['conversions'], 2)) . '</td>';
            echo '<td>' . absint($row['clicks']) . '</td>';
            echo '<td>' . esc_html(function_exists('wc_price') ? wp_strip_all_tags(wc_price($cost)) : number_format_i18n($cost, 0)) . '</td>';
            echo '<td>' . esc_html($row['window_start'] . ' – ' . $row['window_end']) . '</td>';
            echo '<td>' . esc_html($row['reason']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function build_google_ads_script($settings) {
        $template = <<<'JS'
const MG_ENDPOINT = __ENDPOINT__;
const MG_SECRET = __SECRET__;
const INITIAL_START_DATE = __START_DATE__;
const CONVERSION_LAG_DAYS = __LAG_DAYS__;
const PURCHASE_ACTION_NAME = __ACTION_NAME__;
const CAMPAIGN_IDS = __CAMPAIGN_IDS__;
const API_VERSION = 'v25';
const BATCH_SIZE = 500;

function main() {
  const props = PropertiesService.getScriptProperties();
  const end = addDays(accountToday(), -CONVERSION_LAG_DAYS);
  const importConfig = [INITIAL_START_DATE, PURCHASE_ACTION_NAME, CAMPAIGN_IDS.join(',')].join('|');
  if (props.getProperty('MG_INITIAL_IMPORT_CONFIG') !== importConfig) {
    let cursor = INITIAL_START_DATE;
    while (cursor <= end) {
      const chunkEnd = minDate(endOfMonth(cursor), end);
      importRange(cursor, chunkEnd);
      cursor = addDays(chunkEnd, 1);
    }
    props.setProperty('MG_INITIAL_IMPORT_CONFIG', importConfig);
  } else {
    importRange(addDays(end, -29), end);
  }
}

function importRange(start, end) {
  const rows = {};
  const campaignFilter = CAMPAIGN_IDS.length ? ' AND campaign.id IN (' + CAMPAIGN_IDS.join(',') + ')' : '';
  const trafficQuery =
    'SELECT segments.date, segments.product_item_id, metrics.impressions, metrics.clicks, metrics.cost_micros ' +
    'FROM shopping_performance_view ' +
    "WHERE campaign.advertising_channel_type = 'PERFORMANCE_MAX' " +
    "AND segments.date BETWEEN '" + start + "' AND '" + end + "'" + campaignFilter;

  const traffic = AdsApp.search(trafficQuery, {apiVersion: API_VERSION});
  while (traffic.hasNext()) {
    const row = traffic.next();
    const key = row.segments.date + '|' + row.segments.productItemId;
    rows[key] = {
      date: row.segments.date,
      offer_id: String(row.segments.productItemId),
      impressions: Number(row.metrics.impressions || 0),
      clicks: Number(row.metrics.clicks || 0),
      cost_micros: Number(row.metrics.costMicros || 0),
      conversions: 0,
      conversion_value: 0
    };
  }

  let conversionFilter = '';
  let conversionSelect = '';
  if (PURCHASE_ACTION_NAME) {
    conversionSelect = ', segments.conversion_action_name';
    conversionFilter = " AND segments.conversion_action_name = '" + gaqlEscape(PURCHASE_ACTION_NAME) + "'";
  }
  const conversionQuery =
    'SELECT segments.date, segments.product_item_id' + conversionSelect + ', metrics.conversions, metrics.conversions_value ' +
    'FROM shopping_performance_view ' +
    "WHERE campaign.advertising_channel_type = 'PERFORMANCE_MAX' " +
    "AND segments.date BETWEEN '" + start + "' AND '" + end + "'" + campaignFilter + conversionFilter;

  const conversions = AdsApp.search(conversionQuery, {apiVersion: API_VERSION});
  while (conversions.hasNext()) {
    const row = conversions.next();
    const key = row.segments.date + '|' + row.segments.productItemId;
    if (!rows[key]) {
      rows[key] = {date: row.segments.date, offer_id: String(row.segments.productItemId), impressions: 0, clicks: 0, cost_micros: 0, conversions: 0, conversion_value: 0};
    }
    rows[key].conversions += Number(row.metrics.conversions || 0);
    rows[key].conversion_value += Number(row.metrics.conversionsValue || 0);
  }

  sendRows(Object.keys(rows).map(function (key) { return rows[key]; }));
}

function sendRows(rows) {
  for (let offset = 0; offset < rows.length; offset += BATCH_SIZE) {
    const payload = JSON.stringify({
      account_id: AdsApp.currentAccount().getCustomerId().replace(/-/g, ''),
      currency_code: AdsApp.currentAccount().getCurrencyCode(),
      rows: rows.slice(offset, offset + BATCH_SIZE)
    });
    const timestamp = String(Math.floor(Date.now() / 1000));
    const requestId = Utilities.getUuid();
    const message = timestamp + '\n' + requestId + '\n' + payload;
    const signature = toHex(Utilities.computeHmacSha256Signature(message, MG_SECRET, Utilities.Charset.UTF_8));
    const response = UrlFetchApp.fetch(MG_ENDPOINT, {
      method: 'post',
      contentType: 'application/json',
      payload: payload,
      headers: {'X-MG-Timestamp': timestamp, 'X-MG-Request-Id': requestId, 'X-MG-Signature': signature},
      muteHttpExceptions: true
    });
    const code = response.getResponseCode();
    if (code < 200 || code >= 300) {
      throw new Error('WordPress import HTTP ' + code + ': ' + response.getContentText());
    }
  }
}

function accountToday() {
  return Utilities.formatDate(new Date(), AdsApp.currentAccount().getTimeZone(), 'yyyy-MM-dd');
}

function parseDate(value) { return new Date(value + 'T12:00:00Z'); }
function formatDate(date) { return Utilities.formatDate(date, 'UTC', 'yyyy-MM-dd'); }
function addDays(value, days) { const d = parseDate(value); d.setUTCDate(d.getUTCDate() + days); return formatDate(d); }
function endOfMonth(value) { const d = parseDate(value); return formatDate(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0, 12))); }
function minDate(a, b) { return a < b ? a : b; }
function gaqlEscape(value) { return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }
function toHex(bytes) { return bytes.map(function (b) { const n = b < 0 ? b + 256 : b; return ('0' + n.toString(16)).slice(-2); }).join(''); }
JS;
        $campaign_ids = array_values(array_filter(array_map('absint', explode(',', (string) $settings['campaign_ids']))));
        return strtr($template, array(
            '__ENDPOINT__' => wp_json_encode(rest_url(MG_Google_Ads_Product_Performance::REST_NAMESPACE . MG_Google_Ads_Product_Performance::REST_ROUTE)),
            '__SECRET__' => wp_json_encode(MG_Google_Ads_Product_Performance::get_secret()),
            '__START_DATE__' => wp_json_encode($settings['history_start_date']),
            '__LAG_DAYS__' => (string) absint($settings['conversion_lag_days']),
            '__ACTION_NAME__' => wp_json_encode($settings['purchase_action_name']),
            '__CAMPAIGN_IDS__' => wp_json_encode($campaign_ids),
        ));
    }
}
