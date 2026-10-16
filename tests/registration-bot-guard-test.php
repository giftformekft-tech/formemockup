<?php
/** Registration bot guard with WordPress doubles; no live site. */
define('ABSPATH', __DIR__ . '/');
$checks = 0;
$hooks = array();
function check($value, $message) { $GLOBALS['checks']++; if (!$value) throw new RuntimeException($message); }
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][] = $hook; }
function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][] = $hook; }
function apply_filters($hook, $value) { return $value; }
function wp_salt($scheme) { return 'test-salt'; }
function wp_unslash($v) { return stripslashes($v); }
function esc_attr($v) { return htmlspecialchars($v, ENT_QUOTES); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error {
    public $errors = array();
    public function add($code, $message) { $this->errors[$code][] = $message; }
    public function get_error_code() { return $this->errors ? array_key_first($this->errors) : ''; }
}
require __DIR__ . '/../includes/class-registration-bot-guard.php';

$G = 'MG_Registration_Bot_Guard';
$now = 1700000000;
$valid = array($G::TOKEN_FIELD => $G::create_token($now - 10), $G::HONEYPOT_FIELD => '');

MG_Registration_Bot_Guard::init();
foreach (array('woocommerce_register_form', 'woocommerce_process_registration_errors', 'register_form', 'registration_errors') as $hook) {
    check(in_array($hook, $hooks, true), "hook missing: $hook");
}
check(!in_array('woocommerce_registration_errors', $hooks, true), 'must not hook checkout account creation, which has no guard fields');

check(!$G::is_bot_submission($valid, $now), 'human submission after 10s passes');
check(!$G::is_bot_submission(array($G::TOKEN_FIELD => $valid[$G::TOKEN_FIELD]), $now), 'missing honeypot key is not a bot signal');
check(!$G::is_bot_submission(array($G::TOKEN_FIELD => $G::create_token($now - 86400 * 30)), $now), 'old token from a cached page still passes');
check(!$G::is_bot_submission(array($G::TOKEN_FIELD => $G::create_token($now + 30)), $now), 'small clock skew passes');

check($G::is_bot_submission(array_merge($valid, array($G::HONEYPOT_FIELD => 'http://spam')), $now), 'filled honeypot blocks');
check($G::is_bot_submission(array($G::HONEYPOT_FIELD => ''), $now), 'missing token blocks direct POSTs');
check($G::is_bot_submission(array($G::TOKEN_FIELD => $G::create_token($now - 1)), $now), 'submission within 1s blocks');
check($G::is_bot_submission(array($G::TOKEN_FIELD => $G::create_token($now + 3600)), $now), 'far-future token blocks');
check($G::is_bot_submission(array($G::TOKEN_FIELD => ($now - 10) . '.' . str_repeat('a', 64)), $now), 'forged signature blocks');
check($G::is_bot_submission(array($G::TOKEN_FIELD => 'garbage'), $now), 'malformed token blocks');

$_POST = array($G::HONEYPOT_FIELD => 'x');
$errors = $G::validate_woocommerce(new WP_Error(), 'u', 'p', 'e@x.hu');
check($errors->get_error_code() === 'mg_registration_bot', 'WooCommerce registration gets an error');
$errors = $G::validate_wp(new WP_Error(), 'u', 'e@x.hu');
check($errors->get_error_code() === 'mg_registration_bot', 'wp-login registration gets an error');

$_POST = array($G::TOKEN_FIELD => $G::create_token(time() - 10));
check($G::validate_woocommerce(new WP_Error())->get_error_code() === '', 'real visitor gets no error');

ob_start();
$G::render_fields();
$html = ob_get_clean();
check(strpos($html, 'name="' . $G::HONEYPOT_FIELD . '"') !== false && strpos($html, 'tabindex="-1"') !== false, 'honeypot rendered, out of tab order');
check(preg_match('/name="' . $G::TOKEN_FIELD . '" value="(\d+\.[a-f0-9]{64})"/', $html, $m) === 1, 'token rendered');

echo "registration-bot-guard-test: {$checks} checks passed\n";
