<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Veszélyes rendelés" rendelésállapot.
 *
 * Utánvétes rendelés egyedi termékkel, amelyet csak előre utalás után
 * gyártunk le. Az állapotra váltáskor a vevő megkapja az MG_Risky_Order_Email
 * levelet a banki adatokkal. Ha megjött az utalás, a rendelést "Fizetve"
 * állapotra kell állítani: ez leveszi az utánvét díját és átállítja a
 * fizetési módot, így a GLS plugin utánvét nélkül készíti el a címkét.
 * Ha a vevő nem válaszol, a rendelést kézzel kell törölni.
 */
class MG_Risky_Order {

    const STATUS = 'risky-order';

    const LABEL = 'Veszélyes rendelés';

    const PAID_STATUS = 'prepaid';

    const PAID_LABEL = 'Fizetve';

    /** A GLS plugin (MyGLS) csak ennél a fizetési módnál tesz utánvétet a csomagra. */
    const COD_GATEWAY = 'cod';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_order_status'));
        add_filter('wc_order_statuses', array(__CLASS__, 'add_to_status_list'));

        // A WooCommerce a "mark_<status>" tömeges műveletet magától kezeli,
        // csak a listába kell felvenni.
        add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'register_bulk_action'), 20);
        add_filter('bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'register_bulk_action'), 20);

        add_action('admin_head', array(__CLASS__, 'print_status_style'));

        add_action('woocommerce_order_status_' . self::STATUS, array(__CLASS__, 'add_admin_note'), 10, 2);
        add_action('woocommerce_order_status_' . self::PAID_STATUS, array(__CLASS__, 'apply_prepayment'), 10, 2);
        add_filter('woocommerce_order_is_paid_statuses', array(__CLASS__, 'add_paid_status'));

        // Enélkül a WC nem tölti be az email rendszert az állapotváltáskor,
        // így az email osztály nem iratkozik fel, és nem megy ki levél.
        add_filter('woocommerce_email_actions', array(__CLASS__, 'register_email_action'));
        add_filter('woocommerce_email_classes', array(__CLASS__, 'register_email_class'));
    }

    protected static function get_statuses() {
        return array(self::STATUS => self::LABEL, self::PAID_STATUS => self::PAID_LABEL);
    }

    public static function register_order_status() {
        foreach (self::get_statuses() as $status => $label) {
            register_post_status('wc-' . $status, array(
                'label'                     => $label,
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: rendelések száma */
                'label_count'               => _n_noop($label . ' (%s)', $label . ' (%s)', 'mockup-generator'),
            ));
        }
    }

    /**
     * A "Függőben lévő" után szúrjuk be, hogy a legördülőben a fizetésre
     * váró állapotok mellett legyenek.
     */
    public static function add_to_status_list($statuses) {
        $ours = array();
        foreach (self::get_statuses() as $status => $label) {
            $ours['wc-' . $status] = $label;
        }
        $result = array();
        foreach ($statuses as $key => $label) {
            $result[$key] = $label;
            if ($key === 'wc-on-hold') {
                $result += $ours;
            }
        }
        return $result + $ours;
    }

    public static function register_bulk_action($actions) {
        foreach (self::get_statuses() as $status => $label) {
            $actions['mark_' . $status] = 'Állapot módosítása: ' . $label;
        }
        return $actions;
    }

    /** A "Fizetve" rendelés a riportokban és a WC-ben is kifizetettnek számít. */
    public static function add_paid_status($statuses) {
        $statuses[] = self::PAID_STATUS;
        return $statuses;
    }

    public static function print_status_style() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, array('edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders'), true)) {
            return;
        }
        echo '<style>.order-status.status-' . esc_attr(self::STATUS) . '{background:#f8dada;color:#8a1f1f;}'
            . '.order-status.status-' . esc_attr(self::PAID_STATUS) . '{background:#c6e1c6;color:#2c4700;}</style>';
    }

    /**
     * Az utánvét díját egy másik plugin teszi fel díj (fee) sorként. Előre
     * utalásnál ez nem jár, ezért kihagyjuk az utalandó összegből.
     */
    public static function get_cod_fees($order) {
        $patterns = array('/utánvét/u', '/utanvet/u', '/cash on delivery/u', '/\bcod\b/u');
        // A GLS plugin a beállításaiban megadott néven teszi fel a díjat.
        $gls = get_option('mygls_settings', array());
        if (!empty($gls['cod_fee_label'])) {
            $patterns[] = '/^' . preg_quote(mb_strtolower(trim($gls['cod_fee_label']), 'UTF-8'), '/') . '$/u';
        }
        $patterns = apply_filters('mg_risky_order_cod_fee_patterns', $patterns);
        $fees = array();
        foreach ($order->get_fees() as $item_id => $fee) {
            $name = mb_strtolower(trim((string) $fee->get_name()), 'UTF-8');
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $fees[$item_id] = $fee;
                    break;
                }
            }
        }
        return $fees;
    }

    public static function get_cod_fee_total($order) {
        $total = 0.0;
        foreach (self::get_cod_fees($order) as $fee) {
            $total += (float) $fee->get_total() + (float) $fee->get_total_tax();
        }
        return $total;
    }

    protected static function format_price($order, $amount) {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount, array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8');
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
        $cod_fee = self::get_cod_fee_total($order);
        $note = 'Előre utalás kérve: ' . self::format_price($order, self::get_prepay_amount($order));
        $note .= $cod_fee > 0
            ? ' (az utánvét díja – ' . self::format_price($order, $cod_fee) . ' – nélkül).'
            : ' (utánvét díj sort nem találtunk, a teljes végösszeget kértük).';
        $note .= ' Ha megjött az utalás, állítsd a rendelést "' . self::PAID_LABEL . '" állapotra: ez leveszi az utánvét díját és átállítja a fizetési módot, így a GLS címkén nem lesz utánvét.';
        $order->add_order_note($note);
    }

    /**
     * Beérkezett az utalás: az utánvétes rendelést átírjuk előre utalásosra.
     * A GLS plugin a fizetési módból dönti el, kerül-e utánvét a csomagra,
     * és a végösszeget szedeti be, ezért mindkettőt rendbe kell tenni.
     */
    public static function apply_prepayment($order_id, $order = null) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || $order->get_payment_method() !== self::COD_GATEWAY) {
            return;
        }

        $removed = array();
        foreach (self::get_cod_fees($order) as $item_id => $fee) {
            $removed[] = $fee->get_name() . ' (' . self::format_price($order, (float) $fee->get_total() + (float) $fee->get_total_tax()) . ')';
            $order->remove_item($item_id);
        }

        $gateways = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $order->set_payment_method('bacs');
        $order->set_payment_method_title(isset($gateways['bacs']) ? $gateways['bacs']->get_title() : 'Előre utalás');
        if (!$order->get_date_paid()) {
            $order->set_date_paid(time());
        }

        $order->update_taxes();
        $order->calculate_totals(false);

        $note = 'Utalás beérkezett. Fizetési mód: utánvét → ' . $order->get_payment_method_title() . '.';
        $note .= $removed ? ' Törölt díj: ' . implode(', ', $removed) . '.' : ' Utánvét díj sort nem találtunk.';
        $note .= ' Új végösszeg: ' . self::format_price($order, $order->get_total()) . '. A GLS címke már utánvét nélkül készül.';
        $order->add_order_note($note);
        $order->save();
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
