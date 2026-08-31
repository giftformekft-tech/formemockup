<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Ads product performance import and Merchant label classification.
 *
 * Google Ads Scripts pushes daily shopping_performance_view rows to the REST
 * endpoint. Classification is deliberately kept separate from feed rendering:
 * feeds only read the last committed result and never call Google while they
 * are being generated.
 */
class MG_Google_Ads_Product_Performance {
    const DB_VERSION_OPTION = 'mg_gads_product_performance_db_version';
    const DB_VERSION = '1.1.0';
    const SETTINGS_OPTION = 'mg_gads_product_performance_settings';
    const SECRET_OPTION = 'mg_gads_product_performance_secret';
    const SYNC_OPTION = 'mg_gads_product_performance_last_sync';
    const IMPORT_STATE_OPTION = 'mg_gads_product_performance_import_state';
    const IMPORT_PROGRESS_OPTION = 'mg_gads_product_performance_import_progress';
    const IMPORT_COVERAGE_OPTION = 'mg_gads_product_performance_import_coverage';
    const CLASSIFICATION_STATE_OPTION = 'mg_gads_product_performance_last_classification';
    const RESET_GUARD_OPTION = 'mg_gads_product_performance_reset_in_progress';
    const IMPORT_LOCK_TTL_SECONDS = 7200;
    const CLASSIFY_HOOK = 'mg_gads_product_performance_classify';
    const ACTION_GROUP = 'mg-google-ads-product-performance';
    const REST_NAMESPACE = 'mg-ads/v1';
    const REST_ROUTE = '/performance';

    private static $label_cache = array();

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedules'));
        add_action('init', array(__CLASS__, 'maybe_install'), 5);
        add_action('rest_api_init', array(__CLASS__, 'register_rest_route'));
        add_action(self::CLASSIFY_HOOK, array(__CLASS__, 'run_scheduled_classification'));
        add_action('init', array(__CLASS__, 'ensure_schedule'), 30);
    }

    public static function add_cron_schedules($schedules) {
        $schedules['mg_weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display' => 'Mockup Generator – hetente',
        );
        return $schedules;
    }

    public static function daily_table() {
        global $wpdb;
        return $wpdb->prefix . 'mg_gads_product_daily';
    }

    public static function classification_table() {
        global $wpdb;
        return $wpdb->prefix . 'mg_gads_product_classification';
    }

    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        self::install();
    }

    public static function install() {
        global $wpdb;

        $previous_version = (string) get_option(self::DB_VERSION_OPTION, '');
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $daily = self::daily_table();
        $classification = self::classification_table();

        dbDelta(
            "CREATE TABLE {$daily} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                offer_id VARCHAR(191) NOT NULL,
                metric_date DATE NOT NULL,
                impressions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                cost_micros BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                conversions DECIMAL(20,6) NOT NULL DEFAULT 0,
                conversion_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (id),
                UNIQUE KEY offer_date (offer_id, metric_date),
                KEY metric_date (metric_date)
            ) ENGINE=InnoDB {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$classification} (
                product_id BIGINT(20) UNSIGNED NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'normal',
                candidate_status VARCHAR(12) NOT NULL DEFAULT 'normal',
                candidate_runs SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                impressions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                cost_micros BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                conversions DECIMAL(20,6) NOT NULL DEFAULT 0,
                conversion_value DECIMAL(20,6) NOT NULL DEFAULT 0,
                window_start DATE NOT NULL,
                window_end DATE NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'maintenance',
                reason VARCHAR(191) NOT NULL DEFAULT '',
                classified_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                PRIMARY KEY  (product_id),
                KEY status (status),
                KEY classified_at (classified_at)
            ) ENGINE=InnoDB {$charset};"
        );
        $daily_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $daily));
        $classification_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $classification));
        if ($daily_exists !== $daily || $classification_exists !== $classification) {
            return;
        }

        $did_reset = false;
        if ($previous_version !== '' && $previous_version !== self::DB_VERSION) {
            if (!self::save_option_verified(self::RESET_GUARD_OPTION, array('started_at' => time(), 'from_version' => $previous_version))) {
                return;
            }
            self::$label_cache = array();
            self::unschedule_classification();
            self::schedule_google_feed_regeneration();
            if ($wpdb->query('START TRANSACTION') === false) {
                return;
            }
            $daily_reset = $wpdb->query("DELETE FROM {$daily}");
            $classification_reset = $wpdb->query("DELETE FROM {$classification}");
            if ($daily_reset === false || $classification_reset === false) {
                $wpdb->query('ROLLBACK');
                return;
            }
            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return;
            }
            $settings = get_option(self::SETTINGS_OPTION, array());
            $settings = is_array($settings) ? $settings : array();
            $settings['initial_completed_at'] = 0;
            if (!self::save_option_verified(self::SETTINGS_OPTION, $settings)) {
                return;
            }
            foreach (array(self::SYNC_OPTION, self::IMPORT_STATE_OPTION, self::IMPORT_PROGRESS_OPTION, self::IMPORT_COVERAGE_OPTION, self::CLASSIFICATION_STATE_OPTION) as $option) {
                if (!self::delete_option_verified($option)) {
                    return;
                }
            }
            self::$label_cache = array();
            self::unschedule_classification();
            if (!self::delete_option_verified(self::RESET_GUARD_OPTION)) {
                return;
            }
            $did_reset = true;
        }
        if (!self::save_option_verified(self::DB_VERSION_OPTION, self::DB_VERSION)) {
            return;
        }
        if ($did_reset) {
            if (!self::regenerate_google_feeds()) {
                self::schedule_google_feed_regeneration();
            }
        }
    }

    public static function defaults() {
        return array(
            'enabled' => 0,
            'automation_enabled' => 1,
            'label_slot' => 1,
            'winner_conversions' => 2.0,
            'loser_basis' => 'spend',
            'loser_spend' => 10000,
            'loser_clicks' => 30,
            'conversion_lag_days' => 3,
            'history_start_date' => wp_date('Y-m-d', strtotime('-9 months')),
            'ads_customer_id' => '',
            'purchase_action_name' => '',
            'campaign_ids' => '',
            'initial_completed_at' => 0,
        );
    }

    public static function get_settings() {
        $saved = get_option(self::SETTINGS_OPTION, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, self::defaults());
    }

    public static function save_settings($input) {
        $old = self::get_settings();
        $slot = isset($input['label_slot']) ? absint($input['label_slot']) : 1;
        $slot = min(4, max(0, $slot));

        $start = isset($input['history_start_date']) ? sanitize_text_field($input['history_start_date']) : '';
        if (!self::is_valid_date($start)) {
            $start = $old['history_start_date'];
        }

        $campaign_ids = array_filter(array_map('absint', preg_split('/[\s,;]+/', (string) ($input['campaign_ids'] ?? ''))));
        $loser_basis = isset($input['loser_basis']) && $input['loser_basis'] === 'clicks' ? 'clicks' : 'spend';
        $clean = array(
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'automation_enabled' => !empty($input['automation_enabled']) ? 1 : 0,
            'label_slot' => $slot,
            'winner_conversions' => max(0.01, (float) ($input['winner_conversions'] ?? 2)),
            'loser_basis' => $loser_basis,
            'loser_spend' => max(1, (float) ($input['loser_spend'] ?? 10000)),
            'loser_clicks' => max(1, absint($input['loser_clicks'] ?? 30)),
            'conversion_lag_days' => min(14, max(0, absint($input['conversion_lag_days'] ?? 3))),
            'history_start_date' => $start,
            'ads_customer_id' => preg_replace('/[^0-9]/', '', (string) ($input['ads_customer_id'] ?? '')),
            'purchase_action_name' => sanitize_text_field($input['purchase_action_name'] ?? ''),
            'campaign_ids' => implode(',', array_values(array_unique($campaign_ids))),
            'initial_completed_at' => absint($old['initial_completed_at']),
        );
        $latest_classifiable_date = wp_date('Y-m-d', time() - $clean['conversion_lag_days'] * DAY_IN_SECONDS);
        if ($clean['history_start_date'] > $latest_classifiable_date) {
            return new WP_Error('mg_ads_history_range', 'A webshop indulási dátuma nem lehet későbbi a konverziós késéssel korrigált mai napnál.');
        }
        $import_changed = false;
        foreach (array('history_start_date', 'ads_customer_id', 'purchase_action_name', 'campaign_ids', 'conversion_lag_days') as $import_key) {
            if ((string) $clean[$import_key] !== (string) $old[$import_key]) {
                $clean['initial_completed_at'] = 0;
                $import_changed = true;
                break;
            }
        }
        $feed_settings_changed = (int) $clean['enabled'] !== (int) $old['enabled']
            || (int) $clean['label_slot'] !== (int) $old['label_slot'];
        $classification_settings_changed = (float) $clean['winner_conversions'] !== (float) $old['winner_conversions']
            || (string) $clean['loser_basis'] !== (string) $old['loser_basis']
            || (float) $clean['loser_spend'] !== (float) $old['loser_spend']
            || (int) $clean['loser_clicks'] !== (int) $old['loser_clicks'];
        $reclassification_required = $classification_settings_changed && !$import_changed && !empty($old['initial_completed_at']);
        if ($reclassification_required && $clean['loser_basis'] === 'spend') {
            $import_state = self::get_import_state();
            if (strtoupper((string) ($import_state['currency_code'] ?? '')) !== 'HUF') {
                return new WP_Error('mg_ads_currency', 'A forintalapú Loser-besorolás csak HUF pénznemű Ads-importtal kapcsolható be.');
            }
        }
        if ($reclassification_required) {
            // Never publish classifications calculated with the previous
            // thresholds while the replacement classification is in flight.
            $clean['initial_completed_at'] = 0;
        }
        if ($import_changed) {
            $reset = self::reset_import_data();
            if (is_wp_error($reset)) {
                return $reset;
            }
        }
        if (!self::save_option_verified(self::SETTINGS_OPTION, $clean)) {
            if ($import_changed) {
                self::unschedule_classification();
                self::schedule_google_feed_regeneration();
            }
            return new WP_Error('mg_ads_settings_write', 'A Google Ads-besorolás beállításai nem menthetők.');
        }
        self::$label_cache = array();
        if (empty($clean['automation_enabled'])) {
            self::unschedule_classification();
        } else {
            self::ensure_schedule();
        }
        if ($feed_settings_changed || $import_changed || $reclassification_required) {
            if (!self::regenerate_google_feeds()) {
                self::schedule_google_feed_regeneration();
                return new WP_Error('mg_ads_feed_regeneration', 'A Google Merchant feedek nem regenerálhatók.');
            }
        }
        if ($reclassification_required) {
            $import_state = self::get_import_state();
            $classification_end = (string) ($import_state['end'] ?? '');
            $classification = self::is_valid_date($classification_end) && $classification_end >= $clean['history_start_date']
                ? self::run_classification($clean['history_start_date'], $classification_end, 'settings')
                : new WP_Error('mg_ads_import_incomplete', 'A küszöbök újraszámításához előbb teljes Ads-import szükséges.');
            if (is_wp_error($classification)) {
                return $classification;
            }
            $clean['initial_completed_at'] = time();
            if (!self::save_option_verified(self::SETTINGS_OPTION, $clean)) {
                return new WP_Error('mg_ads_reclassification_state_write', 'Az új küszöbökkel készült besorolás kész állapota nem menthető.');
            }
            if (!empty($clean['automation_enabled'])) {
                self::ensure_schedule();
            }
            if (!empty($clean['enabled']) && !self::regenerate_google_feeds()) {
                self::schedule_google_feed_regeneration();
                return new WP_Error('mg_ads_feed_regeneration', 'Az új besorolás elkészült, de a Google Merchant feedek nem regenerálhatók.');
            }
        }
        return $clean;
    }

    /**
     * Identifies the exact Ads data scope accepted by the REST endpoint.
     * Changing any import-affecting setting invalidates already copied scripts.
     */
    public static function get_import_scope($settings = null) {
        $settings = is_array($settings) ? $settings : self::get_settings();
        $scope = array(
            'protocol_version' => self::DB_VERSION,
            'history_start_date' => (string) ($settings['history_start_date'] ?? ''),
            'ads_customer_id' => preg_replace('/[^0-9]/', '', (string) ($settings['ads_customer_id'] ?? '')),
            'purchase_action_name' => (string) ($settings['purchase_action_name'] ?? ''),
            'campaign_ids' => (string) ($settings['campaign_ids'] ?? ''),
            'conversion_lag_days' => absint($settings['conversion_lag_days'] ?? 0),
        );
        return hash('sha256', (string) wp_json_encode($scope));
    }

    /** Remove data that belongs to a previous Ads import scope. */
    public static function reset_import_data() {
        global $wpdb;
        self::maybe_install();
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            return new WP_Error('mg_ads_migration_incomplete', 'Az Ads-adatok adatbázis-migrációja még nem fejeződött be.');
        }

        if (!self::save_option_verified(self::RESET_GUARD_OPTION, array('started_at' => time(), 'scope' => self::get_import_scope()))) {
            return new WP_Error('mg_ads_reset_guard', 'A korábbi Ads-besorolás nem tiltható le biztonságosan.');
        }
        self::$label_cache = array();
        self::unschedule_classification();
        self::schedule_google_feed_regeneration();
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('mg_ads_reset_transaction', 'A korábbi Ads-adatok törlése nem indítható el.');
        }
        foreach (array(self::daily_table(), self::classification_table()) as $table) {
            if ($wpdb->query("DELETE FROM {$table}") === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mg_ads_reset_failed', 'A korábbi Ads-adatok nem törölhetők.');
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mg_ads_reset_commit', 'A korábbi Ads-adatok törlése nem véglegesíthető.');
        }

        $settings = self::get_settings();
        $settings['initial_completed_at'] = 0;
        if (!self::save_option_verified(self::SETTINGS_OPTION, $settings)) {
            self::unschedule_classification();
            self::schedule_google_feed_regeneration();
            return new WP_Error('mg_ads_reset_settings', 'A korábbi Ads-besorolás nem kapcsolható ki biztonságosan.');
        }
        foreach (array(self::SYNC_OPTION, self::IMPORT_STATE_OPTION, self::IMPORT_PROGRESS_OPTION, self::IMPORT_COVERAGE_OPTION, self::CLASSIFICATION_STATE_OPTION) as $option) {
            if (!self::delete_option_verified($option)) {
                self::unschedule_classification();
                self::schedule_google_feed_regeneration();
                return new WP_Error('mg_ads_reset_option', 'A korábbi Ads-import állapota nem törölhető teljesen.');
            }
        }
        self::$label_cache = array();
        if (!self::delete_option_verified(self::RESET_GUARD_OPTION)) {
            self::unschedule_classification();
            self::schedule_google_feed_regeneration();
            return new WP_Error('mg_ads_reset_guard_delete', 'A korábbi Ads-adatok törlése elkészült, de a biztonsági zárolás nem oldható fel.');
        }
        return true;
    }

    public static function get_secret() {
        $secret = (string) get_option(self::SECRET_OPTION, '');
        if ($secret === '') {
            $secret = self::rotate_secret();
        }
        return $secret;
    }

    public static function rotate_secret() {
        $bytes = function_exists('random_bytes') ? random_bytes(32) : wp_generate_password(64, true, true);
        $secret = is_string($bytes) && strlen($bytes) === 32
            ? rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')
            : (string) $bytes;
        update_option(self::SECRET_OPTION, $secret, false);
        return $secret;
    }

    public static function register_rest_route() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_import_request'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle_import_request($request) {
        self::maybe_install();
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            return new WP_Error('mg_ads_migration_incomplete', 'Az Ads-adatok adatbázis-migrációja még nem fejeződött be.', array('status' => 503));
        }
        if (get_option(self::RESET_GUARD_OPTION, false)) {
            return new WP_Error('mg_ads_reset_incomplete', 'Az Ads-adatok biztonságos alaphelyzetbe állítása még nem fejeződött be.', array('status' => 503));
        }
        $body = (string) $request->get_body();
        if (strlen($body) > 10 * MB_IN_BYTES) {
            return new WP_Error('mg_ads_payload_large', 'A kérés túl nagy.', array('status' => 413));
        }

        $timestamp = (string) $request->get_header('x-mg-timestamp');
        $request_id = sanitize_text_field($request->get_header('x-mg-request-id'));
        $signature = strtolower((string) $request->get_header('x-mg-signature'));
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || $request_id === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return new WP_Error('mg_ads_auth', 'Érvénytelen vagy lejárt importkérés.', array('status' => 401));
        }
        $replay_key = 'mg_gads_perf_replay_' . md5($request_id);
        if (get_transient($replay_key)) {
            return new WP_Error('mg_ads_replay', 'Ez az importkérés már feldolgozásra került.', array('status' => 409));
        }
        $expected = hash_hmac('sha256', $timestamp . "\n" . $request_id . "\n" . $body, self::get_secret());
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('mg_ads_signature', 'Hibás import-aláírás.', array('status' => 401));
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new WP_Error('mg_ads_payload', 'Hibás importadat.', array('status' => 400));
        }
        $settings = self::get_settings();
        $scope = sanitize_text_field($payload['scope'] ?? '');
        if ($scope === '' || !hash_equals(self::get_import_scope($settings), $scope)) {
            return new WP_Error('mg_ads_scope', 'Az importscript beállításai elavultak. Másold be újra az adminban látható scriptet.', array('status' => 409));
        }
        $account_id = preg_replace('/[^0-9]/', '', (string) ($payload['account_id'] ?? ''));
        if ($account_id === '') {
            return new WP_Error('mg_ads_account_missing', 'Az importból hiányzik a Google Ads-fiók azonosítója.', array('status' => 400));
        }
        if ($settings['ads_customer_id'] !== '' && $account_id !== $settings['ads_customer_id']) {
            return new WP_Error('mg_ads_account', 'Az import másik Google Ads-fiókból érkezett.', array('status' => 403));
        }
        $known_import = self::get_import_state();
        $known_coverage = get_option(self::IMPORT_COVERAGE_OPTION, array());
        $known_sync = self::get_sync_status();
        $known_account_id = preg_replace('/[^0-9]/', '', (string) ($known_import['account_id'] ?? ($known_coverage['account_id'] ?? ($known_sync['account_id'] ?? ''))));
        if ($known_account_id !== '' && $account_id !== $known_account_id) {
            return new WP_Error('mg_ads_account_changed', 'Ehhez az importbeállításhoz már másik Google Ads-fiók adatai tartoznak.', array('status' => 409));
        }
        $currency_code = strtoupper(sanitize_text_field($payload['currency_code'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency_code)) {
            return new WP_Error('mg_ads_currency_missing', 'Az importból hiányzik az Ads-fiók pénzneme.', array('status' => 400));
        }
        $known_currency_code = strtoupper((string) ($known_import['currency_code'] ?? ($known_coverage['currency_code'] ?? ($known_sync['currency_code'] ?? ''))));
        if ($known_currency_code !== '' && $currency_code !== $known_currency_code) {
            return new WP_Error('mg_ads_currency_changed', 'Ehhez az importbeállításhoz már más pénznemű Ads-adatok tartoznak.', array('status' => 409));
        }
        if ($settings['loser_basis'] === 'spend' && $currency_code !== 'HUF') {
            return new WP_Error('mg_ads_currency', 'A forintalapú Loser-besoroláshoz HUF pénznemű Google Ads-fiók szükséges.', array('status' => 400));
        }

        set_transient($replay_key, 1, 15 * MINUTE_IN_SECONDS);
        $operation = sanitize_key($payload['operation'] ?? 'import');
        if ($operation === 'complete_initial') {
            $result = self::complete_initial_import($payload, $scope, $account_id, $currency_code, $settings);
        } elseif ($operation === 'import' || $operation === 'rows') {
            $result = self::import_range_batch($payload, $scope, $account_id, $currency_code);
        } else {
            return new WP_Error('mg_ads_operation', 'Ismeretlen importművelet.', array('status' => 400));
        }
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(array('success' => true) + $result, 200);
    }

    private static function import_range_batch($payload, $scope, $account_id, $currency_code) {
        global $wpdb;
        $lock_name = 'mg_gads_' . substr(md5($wpdb->prefix . self::IMPORT_PROGRESS_OPTION), 0, 32);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $acquired !== 1) {
            return new WP_Error('mg_ads_import_busy', 'Egy másik importkérés adatbázis-művelete még folyamatban van.', array('status' => 409));
        }
        try {
            return self::import_range_batch_locked($payload, $scope, $account_id, $currency_code);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private static function import_range_batch_locked($payload, $scope, $account_id, $currency_code) {
        global $wpdb;

        $rows = $payload['rows'] ?? null;
        $range_start = sanitize_text_field($payload['range_start'] ?? '');
        $range_end = sanitize_text_field($payload['range_end'] ?? '');
        $batch_index = isset($payload['batch_index']) ? absint($payload['batch_index']) : -1;
        $batch_count = isset($payload['batch_count']) ? absint($payload['batch_count']) : 0;
        $attempt_id = sanitize_text_field($payload['attempt_id'] ?? '');
        $snapshot_id = strtolower(sanitize_text_field($payload['snapshot_id'] ?? ''));
        $import_mode = ($payload['import_mode'] ?? '') === 'initial' ? 'initial' : 'rolling';
        if (!is_array($rows) || count($rows) > 5000 || $attempt_id === '' || strlen($attempt_id) > 100 || !preg_match('/^[a-f0-9]{64}$/', $snapshot_id) || !self::is_valid_date($range_start) || !self::is_valid_date($range_end) || $range_start > $range_end || $batch_count < 1 || $batch_index < 0 || $batch_index >= $batch_count) {
            return new WP_Error('mg_ads_batch', 'Hibás importtartomány vagy batch-metaadat.', array('status' => 400));
        }

        foreach ($rows as $row) {
            $offer_id = is_array($row) && isset($row['offer_id']) ? sanitize_text_field($row['offer_id']) : '';
            $date = is_array($row) && isset($row['date']) ? sanitize_text_field($row['date']) : '';
            if ($offer_id === '' || strlen($offer_id) > 191 || !self::is_valid_date($date) || $date < $range_start || $date > $range_end) {
                return new WP_Error('mg_ads_row', 'Az import egyik sora érvénytelen vagy a megadott tartományon kívül esik.', array('status' => 400));
            }
            foreach (array('impressions', 'clicks', 'cost_micros', 'conversions', 'conversion_value') as $metric_key) {
                $metric_value = $row[$metric_key] ?? 0;
                if (!is_numeric($metric_value) || !is_finite((float) $metric_value) || (float) $metric_value < 0) {
                    return new WP_Error('mg_ads_metric', 'Az import egyik mérőszáma érvénytelen.', array('status' => 400));
                }
            }
        }

        $progress = get_option(self::IMPORT_PROGRESS_OPTION, array());
        if ($import_mode === 'rolling') {
            $state = self::get_import_state();
            $state_end = (string) ($state['end'] ?? '');
            $last_date = self::is_valid_date($state_end) ? DateTime::createFromFormat('!Y-m-d', $state_end) : false;
            $next_uncovered_date = $last_date ? $last_date->modify('+1 day')->format('Y-m-d') : '';
            if (($state['scope'] ?? '') !== $scope || empty($state['completed_at']) || $next_uncovered_date === '' || $range_start > $next_uncovered_date) {
                $progress_updated_at = is_array($progress) ? absint($progress['updated_at'] ?? ($progress['started_at'] ?? 0)) : 0;
                if ($progress_updated_at > 0 && time() - $progress_updated_at < self::IMPORT_LOCK_TTL_SECONDS) {
                    return new WP_Error('mg_ads_import_busy', 'Egy másik importtartomány még folyamatban van.', array('status' => 409));
                }
                return self::prepare_initial_restart('A gördülő import előtt lefedetlen időszak keletkezett; teljes újraimport indul.');
            }
        }

        $progress_matches = is_array($progress)
            && ($progress['scope'] ?? '') === $scope
            && ($progress['account_id'] ?? '') === $account_id
            && ($progress['currency_code'] ?? '') === $currency_code
            && ($progress['range_start'] ?? '') === $range_start
            && ($progress['range_end'] ?? '') === $range_end
            && absint($progress['batch_count'] ?? 0) === $batch_count
            && ($progress['import_mode'] ?? '') === $import_mode
            && ($progress['attempt_id'] ?? '') === $attempt_id
            && ($progress['snapshot_id'] ?? '') === $snapshot_id;
        if ($progress_matches
            && (int) ($progress['next_batch'] ?? -1) === $batch_index + 1
            && (int) ($progress['last_batch_index'] ?? -1) === $batch_index) {
            $progress['updated_at'] = time();
            if (!self::save_option_verified(self::IMPORT_PROGRESS_OPTION, $progress)) {
                return new WP_Error('mg_ads_progress_write', 'Az importfolyamat állapota nem frissíthető.', array('status' => 500));
            }
            return array(
                'accepted' => 0,
                'rejected' => 0,
                'min_date' => $range_start,
                'max_date' => $range_end,
                'batch_index' => $batch_index,
                'batch_count' => $batch_count,
                'range_complete' => false,
                'duplicate_ack' => true,
            );
        }
        if (empty($progress)) {
            $last_sync = self::get_sync_status();
            if (($last_sync['scope'] ?? '') === $scope
                && ($last_sync['account_id'] ?? '') === $account_id
                && ($last_sync['currency_code'] ?? '') === $currency_code
                && ($last_sync['range_start'] ?? '') === $range_start
                && ($last_sync['range_end'] ?? '') === $range_end
                && absint($last_sync['batch_count'] ?? 0) === $batch_count
                && ($last_sync['import_mode'] ?? '') === $import_mode
                && ($last_sync['attempt_id'] ?? '') === $attempt_id
                && ($last_sync['snapshot_id'] ?? '') === $snapshot_id
                && (int) ($last_sync['final_batch_index'] ?? -1) === $batch_index) {
                return array(
                    'accepted' => 0,
                    'rejected' => 0,
                    'min_date' => $range_start,
                    'max_date' => $range_end,
                    'batch_index' => $batch_index,
                    'batch_count' => $batch_count,
                    'range_complete' => true,
                    'duplicate_ack' => true,
                );
            }
            if ($batch_index > 0) {
                return array(
                    'accepted' => 0,
                    'rejected' => 0,
                    'min_date' => $range_start,
                    'max_date' => $range_end,
                    'batch_index' => $batch_index,
                    'batch_count' => $batch_count,
                    'range_complete' => false,
                    'restart_range' => true,
                );
            }
        }
        if ($batch_index > 0 && !$progress_matches) {
            $lock_updated_at = is_array($progress) ? absint($progress['updated_at'] ?? ($progress['started_at'] ?? 0)) : 0;
            if ($lock_updated_at > 0 && ($progress['attempt_id'] ?? '') !== $attempt_id && time() - $lock_updated_at < self::IMPORT_LOCK_TTL_SECONDS) {
                return new WP_Error('mg_ads_import_busy', 'Egy másik importtartomány még folyamatban van.', array('status' => 409));
            }
            return array(
                'accepted' => 0,
                'rejected' => 0,
                'min_date' => $range_start,
                'max_date' => $range_end,
                'batch_index' => $batch_index,
                'batch_count' => $batch_count,
                'range_complete' => false,
                'restart_range' => true,
            );
        }
        if ($batch_index === 0) {
            $lock_updated_at = is_array($progress) ? absint($progress['updated_at'] ?? ($progress['started_at'] ?? 0)) : 0;
            if ($lock_updated_at > 0 && ($progress['attempt_id'] ?? '') !== $attempt_id && time() - $lock_updated_at < self::IMPORT_LOCK_TTL_SECONDS) {
                return new WP_Error('mg_ads_import_busy', 'Egy másik importtartomány még folyamatban van.', array('status' => 409));
            }
            $progress = array(
                'scope' => $scope,
                'account_id' => $account_id,
                'currency_code' => $currency_code,
                'range_start' => $range_start,
                'range_end' => $range_end,
                'batch_count' => $batch_count,
                'next_batch' => 0,
                'accepted' => 0,
                'import_mode' => $import_mode,
                'attempt_id' => $attempt_id,
                'snapshot_id' => $snapshot_id,
                'started_at' => time(),
                'updated_at' => time(),
            );
            if (!self::save_option_verified(self::IMPORT_PROGRESS_OPTION, $progress)) {
                return new WP_Error('mg_ads_progress_write', 'Az importfolyamat állapota nem menthető.', array('status' => 500));
            }
        } elseif ((int) ($progress['next_batch'] ?? -1) !== $batch_index) {
            return array(
                'accepted' => 0,
                'rejected' => 0,
                'min_date' => $range_start,
                'max_date' => $range_end,
                'batch_index' => $batch_index,
                'batch_count' => $batch_count,
                'range_complete' => false,
                'restart_range' => true,
            );
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('mg_ads_import_transaction', 'Az importtranzakció nem indítható el.', array('status' => 500));
        }
        if ($batch_index === 0) {
            $deleted = $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . self::daily_table() . ' WHERE metric_date BETWEEN %s AND %s',
                $range_start,
                $range_end
            ));
            if ($deleted === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mg_ads_range_delete', 'A korábbi importtartomány nem cserélhető le.', array('status' => 500));
            }
        }

        $result = self::import_rows($rows);
        if (is_wp_error($result)) {
            $wpdb->query('ROLLBACK');
            return $result;
        }
        if (!empty($result['rejected'])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mg_ads_rows_rejected', 'Az import egy vagy több sora nem írható adatbázisba.', array('status' => 500));
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mg_ads_import_commit', 'Az import nem véglegesíthető.', array('status' => 500));
        }

        $progress['accepted'] = absint($progress['accepted'] ?? 0) + absint($result['accepted']);
        $progress['next_batch'] = $batch_index + 1;
        $progress['last_batch_index'] = $batch_index;
        $progress['updated_at'] = time();
        $range_complete = ($progress['next_batch'] >= $batch_count);
        if ($range_complete) {
            $coverage_result = $import_mode === 'initial'
                ? self::record_initial_coverage($scope, $range_start, $range_end, $currency_code, $account_id)
                : self::advance_import_coverage($scope, $range_start, $range_end, $currency_code, $account_id, $import_mode);
            if (is_wp_error($coverage_result)) {
                return $coverage_result;
            }
            $sync_state = array(
                'timestamp' => time(),
                'scope' => $scope,
                'account_id' => $account_id,
                'accepted' => $progress['accepted'],
                'rejected' => 0,
                'min_date' => $range_start,
                'max_date' => $range_end,
                'currency_code' => $currency_code,
                'range_start' => $range_start,
                'range_end' => $range_end,
                'batch_count' => $batch_count,
                'final_batch_index' => $batch_index,
                'import_mode' => $import_mode,
                'attempt_id' => $attempt_id,
                'snapshot_id' => $snapshot_id,
            );
            if (!self::save_option_verified(self::SYNC_OPTION, $sync_state)) {
                return new WP_Error('mg_ads_sync_write', 'Az import szinkronállapota nem menthető.', array('status' => 500));
            }
            if (!self::delete_option_verified(self::IMPORT_PROGRESS_OPTION)) {
                return new WP_Error('mg_ads_progress_delete', 'A lezárt importfolyamat zárolása nem oldható fel.', array('status' => 500));
            }
        } else {
            if (!self::save_option_verified(self::IMPORT_PROGRESS_OPTION, $progress)) {
                return new WP_Error('mg_ads_progress_write', 'Az importfolyamat állapota nem menthető.', array('status' => 500));
            }
        }

        return array(
            'accepted' => absint($result['accepted']),
            'rejected' => 0,
            'min_date' => $range_start,
            'max_date' => $range_end,
            'batch_index' => $batch_index,
            'batch_count' => $batch_count,
            'range_complete' => $range_complete,
        );
    }

    private static function complete_initial_import($payload, $scope, $account_id, $currency_code, $settings) {
        $start = sanitize_text_field($payload['start_date'] ?? '');
        $end = sanitize_text_field($payload['end_date'] ?? '');
        if (!self::is_valid_date($start) || !self::is_valid_date($end) || $start !== $settings['history_start_date'] || $start > $end) {
            return new WP_Error('mg_ads_initial_range', 'A teljes import időszaka érvénytelen.', array('status' => 400));
        }
        if (get_option(self::IMPORT_PROGRESS_OPTION, array())) {
            return new WP_Error('mg_ads_import_busy', 'Egy importtartomány még nincs teljesen feltöltve.', array('status' => 409));
        }

        $coverage = get_option(self::IMPORT_COVERAGE_OPTION, array());
        if (!self::coverage_contains($coverage, $scope, $account_id, $currency_code, $start, $end)) {
            return new WP_Error('mg_ads_initial_coverage', 'A szerver még nem kapta meg hiánytalanul a teljes történeti időszakot.', array('status' => 409));
        }

        $state = array(
            'scope' => $scope,
            'start' => $start,
            'end' => $end,
            'currency_code' => $currency_code,
            'account_id' => $account_id,
            'completed_at' => time(),
        );
        if (!self::save_option_verified(self::IMPORT_STATE_OPTION, $state)) {
            return new WP_Error('mg_ads_initial_state_write', 'A teljes import kész állapota nem menthető.', array('status' => 500));
        }
        return array('initial_import_complete' => true, 'start' => $start, 'end' => $end);
    }

    private static function advance_import_coverage($scope, $start, $end, $currency_code, $account_id, $import_mode) {
        if ($import_mode !== 'rolling') {
            return true;
        }
        $state = get_option(self::IMPORT_STATE_OPTION, array());
        if (!is_array($state) || ($state['scope'] ?? '') !== $scope || empty($state['completed_at'])) {
            return true;
        }
        if (($state['account_id'] ?? '') !== $account_id || ($state['currency_code'] ?? '') !== $currency_code) {
            return new WP_Error('mg_ads_coverage_source', 'A gördülő import Ads-forrása eltér a teljes történeti importétól.', array('status' => 409));
        }
        if (empty($state['start']) || $start < $state['start']) {
            $state['start'] = $start;
        }
        if (empty($state['end']) || $end > $state['end']) {
            $state['end'] = $end;
        }
        $state['currency_code'] = $currency_code;
        $state['account_id'] = $account_id;
        if (!self::save_option_verified(self::IMPORT_STATE_OPTION, $state)) {
            return new WP_Error('mg_ads_coverage_write', 'Az importált időszak állapota nem menthető.', array('status' => 500));
        }
        return true;
    }

    private static function prepare_initial_restart($reason) {
        $reset = self::reset_import_data();
        if (is_wp_error($reset)) {
            return new WP_Error($reset->get_error_code(), $reset->get_error_message(), array('status' => 500));
        }
        $settings = self::get_settings();
        $settings['initial_completed_at'] = 0;
        if (!self::save_option_verified(self::SETTINGS_OPTION, $settings)) {
            return new WP_Error('mg_ads_restart_state', 'A teljes újraimport állapota nem menthető.', array('status' => 500));
        }
        self::$label_cache = array();
        self::unschedule_classification();
        self::schedule_google_feed_regeneration();
        return array(
            'restart_initial' => true,
            'range_complete' => false,
            'reason' => $reason,
        );
    }

    private static function record_initial_coverage($scope, $start, $end, $currency_code, $account_id) {
        $coverage = get_option(self::IMPORT_COVERAGE_OPTION, array());
        if (!is_array($coverage) || ($coverage['scope'] ?? '') !== $scope) {
            $coverage = array(
                'scope' => $scope,
                'account_id' => $account_id,
                'currency_code' => $currency_code,
                'ranges' => array(),
            );
        }
        if (($coverage['account_id'] ?? '') !== $account_id || ($coverage['currency_code'] ?? '') !== $currency_code) {
            return new WP_Error('mg_ads_coverage_source', 'A történeti import tartományai eltérő Ads-forrásból érkeztek.', array('status' => 409));
        }

        $ranges = isset($coverage['ranges']) && is_array($coverage['ranges']) ? $coverage['ranges'] : array();
        $ranges[] = array('start' => $start, 'end' => $end);
        usort($ranges, function ($left, $right) {
            return strcmp((string) ($left['start'] ?? ''), (string) ($right['start'] ?? ''));
        });
        $merged = array();
        foreach ($ranges as $range) {
            $range_start = (string) ($range['start'] ?? '');
            $range_end = (string) ($range['end'] ?? '');
            if (!self::is_valid_date($range_start) || !self::is_valid_date($range_end) || $range_start > $range_end) {
                continue;
            }
            if (!$merged) {
                $merged[] = array('start' => $range_start, 'end' => $range_end);
                continue;
            }
            $last_index = count($merged) - 1;
            $last_date = DateTime::createFromFormat('!Y-m-d', $merged[$last_index]['end']);
            $next_day = $last_date ? $last_date->modify('+1 day')->format('Y-m-d') : $merged[$last_index]['end'];
            if ($range_start <= $next_day) {
                if ($range_end > $merged[$last_index]['end']) {
                    $merged[$last_index]['end'] = $range_end;
                }
            } else {
                $merged[] = array('start' => $range_start, 'end' => $range_end);
            }
        }
        $coverage['ranges'] = $merged;
        $coverage['updated_at'] = time();
        if (!self::save_option_verified(self::IMPORT_COVERAGE_OPTION, $coverage)) {
            return new WP_Error('mg_ads_coverage_write', 'A történeti import lefedettsége nem menthető.', array('status' => 500));
        }
        return true;
    }

    private static function coverage_contains($coverage, $scope, $account_id, $currency_code, $start, $end) {
        if (!is_array($coverage)
            || ($coverage['scope'] ?? '') !== $scope
            || ($coverage['account_id'] ?? '') !== $account_id
            || ($coverage['currency_code'] ?? '') !== $currency_code
            || empty($coverage['ranges'])
            || !is_array($coverage['ranges'])) {
            return false;
        }
        foreach ($coverage['ranges'] as $range) {
            if (($range['start'] ?? '') <= $start && ($range['end'] ?? '') >= $end) {
                return true;
            }
        }
        return false;
    }

    private static function save_option_verified($key, $value) {
        update_option($key, $value, false);
        return get_option($key, null) === $value;
    }

    private static function delete_option_verified($key) {
        delete_option($key);
        return get_option($key, null) === null;
    }

    public static function import_rows($rows) {
        global $wpdb;
        $accepted = 0;
        $rejected = 0;
        $min_date = '';
        $max_date = '';
        $table = self::daily_table();
        $now = current_time('mysql', true);

        foreach ((array) $rows as $row) {
            // Kanonikus, kisbetűs alakban tároljuk, hogy a besoroláskori
            // párosítás forrása egységes legyen.
            $offer_id = isset($row['offer_id']) ? self::normalize_offer_id(sanitize_text_field($row['offer_id'])) : '';
            $date = isset($row['date']) ? sanitize_text_field($row['date']) : '';
            if ($offer_id === '' || strlen($offer_id) > 191 || !self::is_valid_date($date)) {
                $rejected++;
                continue;
            }
            $impressions = max(0, (int) ($row['impressions'] ?? 0));
            $clicks = max(0, (int) ($row['clicks'] ?? 0));
            $cost_micros = max(0, (int) ($row['cost_micros'] ?? 0));
            $conversions = max(0, (float) ($row['conversions'] ?? 0));
            $conversion_value = max(0, (float) ($row['conversion_value'] ?? 0));

            $sql = $wpdb->prepare(
                "INSERT INTO {$table}
                    (offer_id, metric_date, impressions, clicks, cost_micros, conversions, conversion_value, updated_at)
                 VALUES (%s, %s, %d, %d, %d, %f, %f, %s)
                 ON DUPLICATE KEY UPDATE
                    impressions = VALUES(impressions), clicks = VALUES(clicks), cost_micros = VALUES(cost_micros),
                    conversions = VALUES(conversions), conversion_value = VALUES(conversion_value), updated_at = VALUES(updated_at)",
                $offer_id, $date, $impressions, $clicks, $cost_micros, $conversions, $conversion_value, $now
            );
            if ($wpdb->query($sql) === false) {
                $rejected++;
                continue;
            }
            $accepted++;
            $min_date = $min_date === '' || $date < $min_date ? $date : $min_date;
            $max_date = $max_date === '' || $date > $max_date ? $date : $max_date;
        }

        return compact('accepted', 'rejected', 'min_date', 'max_date');
    }

    public static function classify_metrics($conversions, $clicks, $cost_micros = 0, $winner_threshold = 2.0, $loser_basis = 'spend', $loser_clicks = 30, $loser_spend = 10000) {
        $conversions = (float) $conversions;
        $clicks = (int) $clicks;
        $spend = max(0, (float) $cost_micros / 1000000);
        if ($conversions >= (float) $winner_threshold) {
            return array('status' => 'winner', 'reason' => 'Legalább ' . self::format_number($winner_threshold) . ' attribútált eladás.');
        }
        if ($conversions <= 0.000001 && $loser_basis === 'clicks' && $clicks >= (int) $loser_clicks) {
            return array('status' => 'loser', 'reason' => 'Nincs eladás legalább ' . (int) $loser_clicks . ' kattintásból.');
        }
        if ($conversions <= 0.000001 && $loser_basis === 'spend' && $spend >= (float) $loser_spend) {
            return array('status' => 'loser', 'reason' => 'Nincs eladás legalább ' . self::format_number($loser_spend) . ' Ft költésből.');
        }
        if ($conversions > 0) {
            $reason = 'Van eladás, de még nem érte el a Winner-küszöböt.';
        } elseif ($loser_basis === 'spend') {
            $reason = 'Még nem érte el a ' . self::format_number($loser_spend) . ' Ft-os Loser-költést.';
        } else {
            $reason = 'Még nincs elegendő kattintás a Loser-döntéshez.';
        }
        return array('status' => 'normal', 'reason' => $reason);
    }

    public static function run_initial_classification() {
        $settings = self::get_settings();
        $state = self::get_import_state();
        $end = (string) ($state['end'] ?? '');
        if (!self::is_valid_date($end)) {
            return new WP_Error('mg_ads_import_incomplete', 'A teljes Ads-import záródátuma hiányzik.');
        }
        $ready = self::validate_import_ready($settings['history_start_date'], $end, $settings);
        if (is_wp_error($ready)) {
            return $ready;
        }
        $result = self::run_classification($settings['history_start_date'], $end, 'initial');
        if (!is_wp_error($result)) {
            $settings['initial_completed_at'] = time();
            if (!self::save_option_verified(self::SETTINGS_OPTION, $settings)) {
                return new WP_Error('mg_ads_initial_state_write', 'Az induló besorolás kész állapota nem menthető.');
            }
            if (!empty($settings['enabled'])) {
                if (!self::regenerate_google_feeds()) {
                    self::schedule_google_feed_regeneration();
                    return new WP_Error('mg_ads_feed_regeneration', 'A besorolás elkészült, de a Google Merchant feedek nem regenerálhatók.');
                }
            }
        }
        return $result;
    }

    public static function run_rolling_classification() {
        $settings = self::get_settings();
        if (empty($settings['initial_completed_at'])) {
            return new WP_Error('mg_ads_initial_missing', 'Az induló besorolás még nem futott le.');
        }
        $state = self::get_import_state();
        $end = (string) ($state['end'] ?? '');
        if (!self::is_valid_date($end)) {
            return new WP_Error('mg_ads_import_incomplete', 'A gördülő Ads-import záródátuma hiányzik.');
        }
        // Always use the complete webshop history. Once a design reaches the
        // Winner threshold it must never lose that status in a quieter season.
        $start = $settings['history_start_date'];
        return self::run_classification($start, $end, 'maintenance');
    }

    public static function run_scheduled_classification() {
        $settings = self::get_settings();
        if (empty($settings['automation_enabled']) || empty($settings['initial_completed_at'])) {
            self::unschedule_classification();
            return new WP_Error('mg_ads_automation_disabled', 'Az automatikus besorolás ki van kapcsolva.');
        }
        return self::run_rolling_classification();
    }

    public static function run_classification($start, $end, $source = 'maintenance') {
        global $wpdb;
        self::maybe_install();
        if (!self::is_valid_date($start) || !self::is_valid_date($end) || $start > $end) {
            return new WP_Error('mg_ads_dates', 'Érvénytelen besorolási időszak.');
        }

        $settings = self::get_settings();
        $ready = self::validate_import_ready($start, $end, $settings);
        if (is_wp_error($ready)) {
            return $ready;
        }

        $daily = self::daily_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT offer_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
                    SUM(cost_micros) AS cost_micros, SUM(conversions) AS conversions,
                    SUM(conversion_value) AS conversion_value
             FROM {$daily}
             WHERE metric_date BETWEEN %s AND %s
             GROUP BY offer_id",
            $start, $end
        ), ARRAY_A);
        if (!is_array($rows)) {
            return new WP_Error('mg_ads_query', 'A teljesítményadatok nem olvashatók.');
        }

        $map = self::build_offer_product_map();
        $metrics = array();
        foreach ($map['product_ids'] as $product_id) {
            $metrics[$product_id] = array('impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0.0, 'conversion_value' => 0.0);
        }
        $unmatched = array();
        foreach ($rows as $row) {
            $offer_id = (string) $row['offer_id'];
            $offer_key = self::normalize_offer_id($offer_id);
            if (!isset($map['offers'][$offer_key])) {
                $unmatched[] = $offer_id;
                continue;
            }
            $product_id = $map['offers'][$offer_key];
            foreach (array('impressions', 'clicks', 'cost_micros') as $key) {
                $metrics[$product_id][$key] += (int) $row[$key];
            }
            $metrics[$product_id]['conversions'] += (float) $row['conversions'];
            $metrics[$product_id]['conversion_value'] += (float) $row['conversion_value'];
        }

        $changed = 0;
        $counts = array('winner' => 0, 'normal' => 0, 'loser' => 0);
        $now = current_time('mysql', true);
        $table = self::classification_table();

        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('mg_ads_classification_transaction', 'A besorolási tranzakció nem indítható el.');
        }

        foreach ($metrics as $product_id => $values) {
            $wpdb->last_error = '';
            $current = $wpdb->get_row($wpdb->prepare("SELECT status, candidate_status, candidate_runs FROM {$table} WHERE product_id = %d", $product_id), ARRAY_A);
            if (!empty($wpdb->last_error)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mg_ads_classification_read', 'A korábbi termékbesorolások nem olvashatók biztonságosan.');
            }
            $decision = self::classify_metrics(
                $values['conversions'],
                $values['clicks'],
                $values['cost_micros'],
                $settings['winner_conversions'],
                $settings['loser_basis'],
                $settings['loser_clicks'],
                $settings['loser_spend']
            );
            if ($current && self::sanitize_status($current['status']) === 'winner') {
                $decision = array(
                    'status' => 'winner',
                    'reason' => 'Korábban már elérte a Winner-küszöböt; a Winner státusz végleges.',
                );
            }
            $status = $decision['status'];
            $candidate = $status;
            $candidate_runs = 0;
            if (!$current || self::sanitize_status($current['status']) !== $status) {
                $changed++;
            }

            $counts[$status]++;
            $sql = $wpdb->prepare(
                "INSERT INTO {$table}
                    (product_id, status, candidate_status, candidate_runs, impressions, clicks, cost_micros, conversions, conversion_value, window_start, window_end, source, reason, classified_at)
                 VALUES (%d, %s, %s, %d, %d, %d, %d, %f, %f, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status), candidate_status = VALUES(candidate_status), candidate_runs = VALUES(candidate_runs),
                    impressions = VALUES(impressions), clicks = VALUES(clicks), cost_micros = VALUES(cost_micros),
                    conversions = VALUES(conversions), conversion_value = VALUES(conversion_value), window_start = VALUES(window_start),
                    window_end = VALUES(window_end), source = VALUES(source), reason = VALUES(reason), classified_at = VALUES(classified_at)",
                $product_id, $status, $candidate, $candidate_runs,
                $values['impressions'], $values['clicks'], $values['cost_micros'], $values['conversions'], $values['conversion_value'],
                $start, $end, sanitize_key($source), $decision['reason'], $now
            );
            if ($wpdb->query($sql) === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('mg_ads_classification_write', 'A termékbesorolások nem írhatók adatbázisba.');
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mg_ads_classification_commit', 'A termékbesorolások nem véglegesíthetők.');
        }

        self::$label_cache = array();
        $classification_state = array(
            'timestamp' => time(),
            'start' => $start,
            'end' => $end,
            'source' => $source,
            'counts' => $counts,
            'changed' => $changed,
            'unmatched_count' => count($unmatched),
            'unmatched_sample' => array_slice(array_values(array_unique($unmatched)), 0, 20),
        );
        if (!self::save_option_verified(self::CLASSIFICATION_STATE_OPTION, $classification_state)) {
            return new WP_Error('mg_ads_classification_state_write', 'A besorolás összesítő állapota nem menthető.');
        }

        if ($source === 'maintenance' && $changed > 0 && !empty($settings['enabled'])) {
            if (!self::regenerate_google_feeds()) {
                self::schedule_google_feed_regeneration();
                return new WP_Error('mg_ads_feed_regeneration', 'A besorolás elkészült, de a Google Merchant feedek nem regenerálhatók.');
            }
        }
        return array('counts' => $counts, 'changed' => $changed, 'unmatched' => count($unmatched), 'start' => $start, 'end' => $end);
    }

    public static function get_product_status($product_id) {
        $status = self::get_stored_product_status($product_id);
        return $status !== '' ? $status : 'normal';
    }

    private static function get_stored_product_status($product_id) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return '';
        }
        if (array_key_exists($product_id, self::$label_cache)) {
            return self::$label_cache[$product_id];
        }
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . self::classification_table() . ' WHERE product_id = %d', $product_id));
        self::$label_cache[$product_id] = in_array($status, array('winner', 'normal', 'loser'), true) ? $status : '';
        return self::$label_cache[$product_id];
    }

    public static function is_enabled() {
        return !empty(self::get_settings()['enabled']);
    }

    public static function get_label_slot() {
        return min(4, max(0, absint(self::get_settings()['label_slot'])));
    }

    public static function get_feed_label($product_id) {
        $settings = self::get_settings();
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION || get_option(self::RESET_GUARD_OPTION, false) || empty($settings['enabled']) || empty($settings['initial_completed_at'])) {
            return '';
        }
        $state = self::get_import_state();
        if (($state['scope'] ?? '') !== self::get_import_scope($settings) || empty($state['completed_at'])) {
            return '';
        }
        return self::get_stored_product_status($product_id);
    }

    public static function get_classifications($limit = 100) {
        global $wpdb;
        $limit = min(500, max(1, absint($limit)));
        return $wpdb->get_results("SELECT * FROM " . self::classification_table() . " ORDER BY FIELD(status, 'winner', 'normal', 'loser'), conversions DESC, clicks DESC LIMIT {$limit}", ARRAY_A);
    }

    public static function get_sync_status() {
        $value = get_option(self::SYNC_OPTION, array());
        return is_array($value) ? $value : array();
    }

    public static function get_classification_status() {
        $value = get_option(self::CLASSIFICATION_STATE_OPTION, array());
        return is_array($value) ? $value : array();
    }

    public static function get_import_state() {
        $value = get_option(self::IMPORT_STATE_OPTION, array());
        return is_array($value) ? $value : array();
    }

    public static function ensure_schedule() {
        $settings = self::get_settings();
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION || get_option(self::RESET_GUARD_OPTION, false) || empty($settings['automation_enabled']) || empty($settings['initial_completed_at'])) {
            self::unschedule_classification();
            return;
        }
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (!as_has_scheduled_action(self::CLASSIFY_HOOK, array(), self::ACTION_GROUP)) {
                as_schedule_recurring_action(time() + DAY_IN_SECONDS, WEEK_IN_SECONDS, self::CLASSIFY_HOOK, array(), self::ACTION_GROUP, true);
            }
            return;
        }
        if (!wp_next_scheduled(self::CLASSIFY_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'mg_weekly', self::CLASSIFY_HOOK);
        }
    }

    private static function unschedule_classification() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CLASSIFY_HOOK, array(), self::ACTION_GROUP);
        }
        wp_clear_scheduled_hook(self::CLASSIFY_HOOK);
    }

    private static function build_offer_product_map() {
        $result = array('offers' => array(), 'product_ids' => array());
        if (!class_exists('MG_Virtual_Variant_Manager') || !function_exists('wc_get_product')) {
            return $result;
        }
        $ids = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            if (empty($config['types']) || !is_array($config['types'])) {
                continue;
            }
            $base_sku = $product->get_sku() ?: 'ID_' . $product_id;
            $result['product_ids'][$product_id] = $product_id;
            foreach (array_keys($config['types']) as $type_slug) {
                $result['offers'][self::normalize_offer_id($base_sku . '_' . $type_slug)] = (int) $product_id;
            }
        }
        return $result;
    }

    private static function validate_import_ready($start, $end, $settings) {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            return new WP_Error('mg_ads_migration_incomplete', 'Az Ads-adatok adatbázis-migrációja még nem fejeződött be.');
        }
        if (get_option(self::RESET_GUARD_OPTION, false)) {
            return new WP_Error('mg_ads_reset_incomplete', 'Az Ads-adatok biztonságos alaphelyzetbe állítása még nem fejeződött be.');
        }
        if (get_option(self::IMPORT_PROGRESS_OPTION, array())) {
            return new WP_Error('mg_ads_import_busy', 'Az Ads-import még folyamatban van; a besorolás csak a teljes batch után futhat.');
        }

        $state = self::get_import_state();
        $current_scope = self::get_import_scope($settings);
        if (!is_array($state) || ($state['scope'] ?? '') !== $current_scope || empty($state['completed_at'])) {
            return new WP_Error('mg_ads_import_incomplete', 'A jelenlegi beállításokhoz tartozó teljes Ads-import még nem készült el.');
        }
        if (($state['start'] ?? '') > $start || ($state['end'] ?? '') < $end) {
            return new WP_Error('mg_ads_import_coverage', 'A teljes Ads-import még nem fedi le a besorolási időszakot.');
        }
        if (($settings['loser_basis'] ?? 'spend') === 'spend' && strtoupper((string) ($state['currency_code'] ?? '')) !== 'HUF') {
            return new WP_Error('mg_ads_currency', 'A forintalapú Loser-besorolás csak igazolt HUF importból futtatható.');
        }
        return true;
    }

    public static function regenerate_google_feeds() {
        $success = true;
        if (class_exists('MG_Google_Merchant_Feed')) {
            $success = MG_Google_Merchant_Feed::generate_feed_to_file() !== false && $success;
        }
        if (class_exists('MG_Custom_Feed_Manager')) {
            foreach ((array) get_option('mg_custom_feeds', array()) as $slug => $feed) {
                if (($feed['format'] ?? '') === 'google') {
                    $success = MG_Custom_Feed_Manager::generate_feed_to_file($slug) !== false && $success;
                }
            }
        }
        return $success;
    }

    private static function schedule_google_feed_regeneration() {
        if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) {
            return;
        }
        if (class_exists('MG_Google_Merchant_Feed') && !wp_next_scheduled('mg_cron_regenerate_feed')) {
            wp_schedule_single_event(time(), 'mg_cron_regenerate_feed');
        }
        if (class_exists('MG_Custom_Feed_Manager')) {
            foreach ((array) get_option('mg_custom_feeds', array()) as $slug => $feed) {
                if (($feed['format'] ?? '') !== 'google') {
                    continue;
                }
                $args = array($slug);
                if (!wp_next_scheduled('mg_cron_regenerate_custom_feed_slug', $args)) {
                    wp_schedule_single_event(time(), 'mg_cron_regenerate_custom_feed_slug', $args);
                }
            }
        }
    }

    /**
     * Az ajánlatazonosító összehasonlítható alakja.
     *
     * A Merchant feed a `<SKU>_<type_slug>` azonosítót a WooCommerce SKU-ból
     * képzi, az pedig a MG_Product_Creator::sanitize_sku() miatt csupa nagybetű
     * (`FORME10001_polo`). A Google Ads riport viszont kisbetűsítve adja vissza
     * a `segments.product_item_id` mezőt (`forme10001_polo`). A PHP tömbkulcs
     * kis-nagybetű érzékeny, ezért normalizálás nélkül egyetlen offer sem
     * párosítható a termékére.
     */
    public static function normalize_offer_id($offer_id) {
        return strtolower(trim((string) $offer_id));
    }

    private static function sanitize_status($status) {
        return in_array($status, array('winner', 'normal', 'loser'), true) ? $status : 'normal';
    }

    private static function is_valid_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private static function format_number($number) {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }
}
