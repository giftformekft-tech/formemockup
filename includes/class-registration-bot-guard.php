<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Botvédelem a regisztrációs űrlapokon (WooCommerce "Fiókom" és wp-login.php).
 *
 * Két láthatatlan ellenőrzés, külső szolgáltatás és kulcs nélkül:
 * - honeypot mező: a vevő nem látja, a botok viszont kitöltik;
 * - időbélyeg: aláírt token az űrlap megjelenítésének idejével. Ha hiányzik,
 *   hamis, vagy túl gyorsan jön vissza a beküldés, a regisztrációt elutasítjuk.
 *
 * Csak minimum időt nézünk, maximumot nem, így a gyorsítótárazott oldal régi
 * tokenje sem zárja ki a valódi vevőt.
 */
class MG_Registration_Bot_Guard {

    const HONEYPOT_FIELD = 'mg_reg_ref_code';

    const TOKEN_FIELD = 'mg_reg_token';

    const MIN_SECONDS = 3;

    public static function init() {
        add_action('woocommerce_register_form', array(__CLASS__, 'render_fields'));
        add_filter('woocommerce_process_registration_errors', array(__CLASS__, 'validate_woocommerce'), 10, 4);

        add_action('register_form', array(__CLASS__, 'render_fields'));
        add_filter('registration_errors', array(__CLASS__, 'validate_wp'), 10, 3);
    }

    public static function render_fields() {
        $field = self::HONEYPOT_FIELD;
        echo '<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
            . '<label for="' . esc_attr($field) . '">Ezt a mezőt hagyd üresen</label>'
            . '<input type="text" name="' . esc_attr($field) . '" id="' . esc_attr($field) . '" value="" tabindex="-1" autocomplete="off" />'
            . '</div>';
        echo '<input type="hidden" name="' . esc_attr(self::TOKEN_FIELD) . '" value="' . esc_attr(self::create_token()) . '" />';
    }

    public static function validate_woocommerce($errors, $username = '', $password = '', $email = '') {
        return self::add_error_if_bot($errors);
    }

    public static function validate_wp($errors, $sanitized_user_login = '', $user_email = '') {
        return self::add_error_if_bot($errors);
    }

    protected static function add_error_if_bot($errors) {
        if (is_wp_error($errors) && self::is_bot_submission($_POST)) {
            $errors->add('mg_registration_bot', 'A regisztráció nem sikerült. Kérjük, töltsd újra az oldalt, és próbáld meg ismét.');
        }
        return $errors;
    }

    public static function is_bot_submission($data, $now = null) {
        $now = $now === null ? time() : (int) $now;

        $honeypot = isset($data[self::HONEYPOT_FIELD]) ? trim((string) wp_unslash($data[self::HONEYPOT_FIELD])) : '';
        if ($honeypot !== '') {
            return true;
        }

        $token = isset($data[self::TOKEN_FIELD]) ? (string) wp_unslash($data[self::TOKEN_FIELD]) : '';
        $issued = self::read_token($token);
        if ($issued === null) {
            return true;
        }

        $min = (int) apply_filters('mg_registration_bot_guard_min_seconds', self::MIN_SECONDS);
        $age = $now - $issued;
        // Kis tűrés a jövő felé, ha a szerverek órája eltér; ennél távolabbi jövő hamis token.
        if ($age <= -60) {
            return true;
        }
        return $age >= 0 && $age < $min;
    }

    public static function create_token($now = null) {
        $issued = $now === null ? time() : (int) $now;
        return $issued . '.' . self::sign($issued);
    }

    protected static function read_token($token) {
        if (!preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }
        return hash_equals(self::sign($m[1]), $m[2]) ? (int) $m[1] : null;
    }

    protected static function sign($issued) {
        return hash_hmac('sha256', 'mg_registration|' . $issued, wp_salt('nonce'));
    }
}
