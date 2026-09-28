<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Read-only reporting from the authoritative WooCommerce order store. */
class MG_Order_Attribution_Report {
    const BATCH_SIZE = 250;

    public static function init() {
        add_action('wp_ajax_mg_order_attribution_report', array(__CLASS__, 'ajax_report'));
    }

    public static function ajax_report() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Nincs jogosultságod a rendelésstatisztikához.'), 403);
            return;
        }
        if (!check_ajax_referer('mg_order_attribution_report', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Lejárt a munkamenet. Frissítsd az oldalt.'), 403);
            return;
        }
        try {
            $result = self::read_batch(wp_unslash($_POST));
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()), 400);
                return;
            }
            wp_send_json_success($result);
        } catch (Throwable $error) {
            // Database details can contain internal configuration; do not expose them.
            wp_send_json_error(array('message' => 'Nem sikerült lekérni a rendeléseket. Próbáld újra a betöltést.'), 500);
        }
    }

    public static function parse_range(array $input) {
        $dates = array();
        foreach (array('from', 'to') as $key) {
            $value = isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
            if (!$date || $date->format('Y-m-d') !== $value) {
                return new WP_Error('date', 'Adj meg érvényes kezdő és záró dátumot.');
            }
            $dates[$key] = $date;
        }
        if ($dates['from'] > $dates['to']) {
            return new WP_Error('range', 'A kezdő dátum nem lehet későbbi a záró dátumnál.');
        }
        if ($dates['from']->diff($dates['to'])->days > 3660) {
            return new WP_Error('range', 'Egyszerre legfeljebb tíz év rendeléseit kérheted le.');
        }
        $utc = new DateTimeZone('UTC');
        return array(
            'from' => $dates['from']->setTimezone($utc)->format('Y-m-d H:i:s'),
            // Calendar arithmetic, not 86400 seconds: handles daylight-saving changes.
            'until' => $dates['to']->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        );
    }

    private static function query($sql) {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException('Order report query failed.');
        }
        return $rows;
    }

    /** Keyset pagination keeps memory bounded and never relies on a changing OFFSET. */
    public static function read_batch(array $input) {
        global $wpdb;
        $range = self::parse_range($input);
        if (is_wp_error($range)) {
            return $range;
        }
        foreach (array('cursor', 'ceiling') as $key) {
            if (isset($input[$key]) && (!is_scalar($input[$key]) || !preg_match('/^\d+$/', (string) $input[$key]))) {
                return new WP_Error('cursor', 'Érvénytelen folytatási adat. Indíts új lekérdezést.');
            }
        }
        $cursor = isset($input['cursor']) ? absint($input['cursor']) : 0;
        $ceiling = isset($input['ceiling']) ? absint($input['ceiling']) : 0;
        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
        $meta_table = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
        $id = $hpos ? 'id' : 'ID';
        $type = $hpos ? 'type' : 'post_type';
        $status = $hpos ? 'status' : 'post_status';
        $created = $hpos ? 'date_created_gmt' : 'post_date_gmt';
        $meta_id = $hpos ? 'id' : 'meta_id';
        $meta_order = $hpos ? 'order_id' : 'post_id';
        // Supplier export moves accepted purchases from processing to our own
        // manufacturing state. They must remain in historical purchase reports.
        $statuses = array_unique(array_merge(wc_get_is_paid_statuses(), array('manufacturing', 'refunded')));
        $statuses = array_map(function ($value) { return 'wc-' . preg_replace('/^wc-/', '', sanitize_key($value)); }, $statuses);
        $slots = implode(',', array_fill(0, count($statuses), '%s'));
        $where = $wpdb->prepare(
            "o.{$type} = 'shop_order' AND o.{$status} IN ({$slots}) AND o.{$created} >= %s AND o.{$created} < %s",
            array_merge($statuses, array($range['from'], $range['until']))
        );
        $total = null;
        if (!$ceiling) {
            $bounds = self::query("SELECT COUNT(*) AS total, MAX(o.{$id}) AS ceiling FROM {$table} o WHERE {$where}");
            $ceiling = (int) $bounds[0]['ceiling'];
            $total = (int) $bounds[0]['total'];
        }
        $money = $hpos ? 'o.total_amount AS amount, o.currency' : "'0' AS amount, '' AS currency";
        $orders = self::query($wpdb->prepare(
            "SELECT o.{$id} AS id, o.{$created} AS created, {$money} FROM {$table} o
             WHERE {$where} AND o.{$id} > %d AND o.{$id} <= %d ORDER BY o.{$id} ASC LIMIT %d",
            $cursor, $ceiling, self::BATCH_SIZE + 1
        ));
        $done = count($orders) <= self::BATCH_SIZE;
        $orders = array_slice($orders, 0, self::BATCH_SIZE);
        $rows = array();
        if ($orders) {
            $ids = array_map('intval', array_column($orders, 'id'));
            $id_list = implode(',', $ids); // Only database-provided integer IDs.
            $prefix = '_' . trim((string) apply_filters('wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_'), '_') . '_';
            $fields = array('utm_source', 'utm_medium', 'utm_campaign', 'source_type', 'referrer');
            $keys = array_map(function ($field) use ($prefix) { return $prefix . $field; }, $fields);
            if (!$hpos) {
                $keys = array_merge($keys, array('_order_total', '_order_currency'));
            }
            $key_slots = implode(',', array_fill(0, count($keys), '%s'));
            $metadata = self::query($wpdb->prepare(
                "SELECT {$meta_order} AS order_id, meta_key, meta_value FROM {$meta_table}
                 WHERE {$meta_order} IN ({$id_list}) AND meta_key IN ({$key_slots}) ORDER BY {$meta_id} ASC", $keys
            ));
            $meta = array();
            foreach ($metadata as $item) {
                // Duplicate historical metadata must not multiply the order totals.
                $meta[(int) $item['order_id']][$item['meta_key']] = $item['meta_value'];
            }
            if ($hpos) {
                $refund_sql = "SELECT parent_order_id AS parent_id, ABS(total_amount) AS amount FROM {$table}
                    WHERE type = 'shop_order_refund' AND status <> 'trash' AND parent_order_id IN ({$id_list})";
            } else {
                $refund_sql = "SELECT r.post_parent AS parent_id,
                    (SELECT m.meta_value FROM {$meta_table} m WHERE m.post_id = r.ID AND m.meta_key = '_refund_amount' ORDER BY m.meta_id DESC LIMIT 1) AS amount
                    FROM {$table} r WHERE r.post_type = 'shop_order_refund' AND r.post_status <> 'trash' AND r.post_parent IN ({$id_list})";
            }
            $refunds = array();
            foreach (self::query($refund_sql) as $refund) {
                $parent = (int) $refund['parent_id'];
                $refunds[$parent] = ($refunds[$parent] ?? 0) + abs(self::money_units($refund['amount']));
            }
            $facts = array();
            foreach ($orders as $order) {
                $order_id = (int) $order['id'];
                $data = $meta[$order_id] ?? array();
                $attribution = array();
                foreach ($fields as $field) {
                    $attribution[$field] = self::clean_value($data[$prefix . $field] ?? '');
                }
                $date = new DateTimeImmutable($order['created'], new DateTimeZone('UTC'));
                $facts[] = array_merge(self::classify($attribution), array(
                    'day' => $date->setTimezone(wp_timezone())->format('Y-m-d'),
                    'campaign' => $attribution['utm_campaign'],
                    // Never combine an unknown currency with the shop's current currency.
                    'currency' => strtoupper(self::clean_value($hpos ? $order['currency'] : ($data['_order_currency'] ?? ''))) ?: 'UNKNOWN',
                    'gross' => self::money_units($hpos ? $order['amount'] : ($data['_order_total'] ?? '0')),
                    'refunds' => $refunds[$order_id] ?? 0,
                ));
            }
            $rows = self::aggregate($facts);
            $cursor = (int) end($orders)['id'];
        }
        return array('rows' => $rows, 'cursor' => $cursor, 'ceiling' => $ceiling, 'total' => $total, 'processed' => count($orders), 'done' => $done);
    }

    /** Fixed four-decimal units, independent of currency display precision. */
    public static function money_units($value) {
        return (int) round((float) $value * 10000);
    }

    public static function clean_value($value) {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim(sanitize_text_field((string) $value));
        return in_array(strtolower($value), array('', '(none)', '(not set)', 'null'), true) ? '' : $value;
    }

    /** Attribution is taken only from WooCommerce, never guessed from a Pixel cookie. */
    public static function classify(array $data) {
        $source = strtolower(self::clean_value($data['utm_source'] ?? ''));
        $medium = strtolower(self::clean_value($data['utm_medium'] ?? ''));
        $type = strtolower(self::clean_value($data['source_type'] ?? ''));
        $source = $source ?: strtolower(self::clean_value($data['referrer'] ?? ''));
        $platform = $source;
        $aliases = array(
            'facebook' => array('fb', 'facebook', 'facebook.com'),
            'instagram' => array('ig', 'instagram', 'instagram.com'),
            'meta' => array('meta', 'meta_ads'),
            'messenger' => array('msg', 'messenger', 'messenger.com'),
            'audience_network' => array('an', 'audience_network', 'audience network'),
            'google' => array('google', 'googleads', 'google_ads', 'adwords', 'google.com', 'google.hu'),
            'tiktok' => array('tiktok', 'tiktok.com'),
            'pinterest' => array('pinterest', 'pinterest.com'),
            'microsoft' => array('bing', 'bing.com', 'microsoft', 'microsoft_ads'),
            'youtube' => array('youtube', 'youtube.com', 'youtu.be'),
            'chatgpt' => array('chatgpt', 'openai', 'chatgpt.com', 'chat.openai.com'),
            'newsletter' => array('newsletter', 'hirlevel', 'hírlevél'),
        );
        $host = parse_url(strpos($source, '://') === false ? 'https://' . $source : $source, PHP_URL_HOST);
        foreach ($aliases as $canonical => $names) {
            foreach ($names as $name) {
                if ($source === $name || ($host && ($host === $name || substr($host, -strlen('.' . $name)) === '.' . $name))) {
                    $platform = $canonical;
                    break 2;
                }
            }
        }
        if ($platform === $source && $host && strpos($host, '.') !== false) {
            $platform = $host;
        }
        if ($type === 'typein' || $source === '(direct)' || $source === 'direct') {
            $platform = 'direct';
        } elseif (in_array($type, array('admin', 'pos', 'mobile_app'), true)) {
            $platform = $type;
        } elseif (!$platform) {
            $platform = 'unknown';
        }
        $channel = 'other';
        if (in_array($medium, array('cpc', 'ppc', 'paid', 'paid_social', 'paid-social', 'paidsocial', 'paid_search', 'paidsearch', 'display', 'cpm', 'cpv', 'cpa', 'retargeting'), true)) {
            $channel = 'paid';
        } elseif ($medium === 'email' || $medium === 'e-mail') {
            $channel = 'email';
        } elseif ($type === 'organic' || $medium === 'organic') {
            $channel = 'organic';
        } elseif (in_array($platform, array('direct', 'unknown', 'admin', 'pos', 'mobile_app'), true)) {
            $channel = $platform;
        } elseif ($type === 'referral' || $medium === 'referral') {
            $channel = 'referral';
        }
        return array('platform' => $platform, 'channel' => $channel);
    }

    public static function aggregate(array $facts) {
        $groups = array();
        foreach ($facts as $fact) {
            $key = json_encode(array($fact['day'], $fact['platform'], $fact['channel'], $fact['campaign'], $fact['currency']));
            if (!isset($groups[$key])) {
                $groups[$key] = array_merge($fact, array('orders' => 0, 'gross' => 0, 'refunds' => 0));
            }
            $groups[$key]['orders']++;
            $groups[$key]['gross'] += $fact['gross'];
            $groups[$key]['refunds'] += $fact['refunds'];
        }
        return array_values($groups);
    }
}
