<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Veszélyes rendelés" rendelésállapot.
 *
 * Utánvétes rendelés egyedi termékkel, amelyet csak előre utalás után
 * gyártunk le. Az állapotra váltáskor a vevő megkapja az MG_Risky_Order_Email
 * levelet a banki adatokkal; a rendelést kézzel kell továbbléptetni
 * (beérkezett utalás) vagy törölni (nem válaszolt).
 */
class MG_Risky_Order {

    const STATUS = 'risky-order';

    const LABEL = 'Veszélyes rendelés';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_order_status'));
        add_filter('wc_order_statuses', array(__CLASS__, 'add_to_status_list'));

        // A WooCommerce a "mark_<status>" tömeges műveletet magától kezeli,
        // csak a listába kell felvenni.
        add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'register_bulk_action'), 20);
        add_filter('bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'register_bulk_action'), 20);

        add_action('admin_head', array(__CLASS__, 'print_status_style'));

        add_action('woocommerce_order_status_' . self::STATUS, array(__CLASS__, 'add_admin_note'), 10, 2);

        // Enélkül a WC nem tölti be az email rendszert az állapotváltáskor,
        // így az email osztály nem iratkozik fel, és nem megy ki levél.
        add_filter('woocommerce_email_actions', array(__CLASS__, 'register_email_action'));
        add_filter('woocommerce_email_classes', array(__CLASS__, 'register_email_class'));
    }

    public static function register_order_status() {
        register_post_status('wc-' . self::STATUS, array(
            'label'                     => self::LABEL,
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Veszélyes rendelés (%s)', 'Veszélyes rendelés (%s)', 'mockup-generator'),
        ));
    }

    /**
     * A "Függőben lévő" után szúrjuk be, hogy a legördülőben a fizetésre
     * váró állapotok mellett legyen.
     */
    public static function add_to_status_list($statuses) {
        $result = array();
        foreach ($statuses as $key => $label) {
            $result[$key] = $label;
            if ($key === 'wc-on-hold') {
                $result['wc-' . self::STATUS] = self::LABEL;
            }
        }
        if (!isset($result['wc-' . self::STATUS])) {
            $result['wc-' . self::STATUS] = self::LABEL;
        }
        return $result;
    }

    public static function register_bulk_action($actions) {
        $actions['mark_' . self::STATUS] = 'Állapot módosítása: ' . self::LABEL;
        return $actions;
    }

    public static function print_status_style() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, array('edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders'), true)) {
            return;
        }
        echo '<style>.order-status.status-' . esc_attr(self::STATUS) . '{background:#f8dada;color:#8a1f1f;}</style>';
    }

    /**
     * Az utánvét díját egy másik plugin teszi fel díj (fee) sorként. Előre
     * utalásnál ez nem jár, ezért kihagyjuk az utalandó összegből.
     */
    public static function get_cod_fee_total($order) {
        $patterns = apply_filters('mg_risky_order_cod_fee_patterns', array('/utánvét/u', '/utanvet/u', '/cash on delivery/u', '/\bcod\b/u'));
        $total = 0.0;
        foreach ($order->get_fees() as $fee) {
            $name = mb_strtolower((string) $fee->get_name(), 'UTF-8');
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $total += (float) $fee->get_total() + (float) $fee->get_total_tax();
                    break;
                }
            }
        }
        return $total;
    }

    public static function get_prepay_amount($order) {
        return max(0, (float) $order->get_total() - self::get_cod_fee_total($order));
    }

    /**
     * Emlékeztető az adminnak: a rendelésen még utánvét van, amit a
     * szállítási plugin a csomagra is ráírna.
     */
    public static function add_admin_note($order_id, $order = null) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        $args = array('currency' => $order->get_currency());
        $price = function ($amount) use ($args) {
            return html_entity_decode(wp_strip_all_tags(wc_price($amount, $args)), ENT_QUOTES, 'UTF-8');
        };
        $cod_fee = self::get_cod_fee_total($order);
        $note = 'Előre utalás kérve: ' . $price(self::get_prepay_amount($order));
        $note .= $cod_fee > 0
            ? ' (az utánvét díja – ' . $price($cod_fee) . ' – nélkül).'
            : ' (utánvét díj sort nem találtunk, a teljes végösszeget kértük).';
        $note .= ' Az utalás beérkezése után töröld az utánvét díjat, és állítsd át a fizetési módot előre utalásra, hogy a futár ne szedjen be utánvétet.';
        $order->add_order_note($note);
    }

    public static function register_email_action($actions) {
        $actions[] = 'woocommerce_order_status_' . self::STATUS;
        return $actions;
    }

    public static function register_email_class($email_classes) {
        if (!class_exists('MG_Risky_Order_Email')) {
            $file = __DIR__ . '/class-risky-order-email.php';
            if (!file_exists($file)) {
                return $email_classes;
            }
            require_once $file;
        }
        $email_classes['MG_Risky_Order_Email'] = new MG_Risky_Order_Email();
        return $email_classes;
    }
}
