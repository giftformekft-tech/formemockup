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
