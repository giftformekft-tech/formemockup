<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Surcharge_Frontend {
    const FIELD_NAME = 'mg_surcharge';
    const CART_FIELD_NAME = 'mg_surcharge_cart';

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'render_product_options'], 25);
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 4);
        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'restore_cart_item'], 10, 2);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'render_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_order_item_meta'], 10, 4);
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_fees']);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'validate_cart_items']);
        add_action('woocommerce_after_cart_item_name', [__CLASS__, 'render_cart_item_controls'], 20, 2);
        add_action('woocommerce_update_cart_action_cart_updated', [__CLASS__, 'handle_cart_update']);
    }

    public static function register_assets() {
        $base_dir = plugin_dir_path(__FILE__) . '../assets/';
        $css_version = file_exists($base_dir . 'css/mg-surcharges.css') ? filemtime($base_dir . 'css/mg-surcharges.css') : '1.0.0';
        $js_version = file_exists($base_dir . 'js/mg-surcharges.js') ? filemtime($base_dir . 'js/mg-surcharges.js') : '1.0.0';
        wp_register_style('mg-surcharges', plugins_url('../assets/css/mg-surcharges.css', __FILE__), [], $css_version);
        wp_register_script('mg-surcharges', plugins_url('../assets/js/mg-surcharges.js', __FILE__), ['jquery'], $js_version, true);
    }

    public static function render_product_options() {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }
        $available = self::get_applicable_surcharges($product, null, 'product');
        if (empty($available)) {
            return;
        }
        wp_enqueue_style('mg-surcharges');
        wp_enqueue_script('mg-surcharges');
        $context = self::get_product_context($product, null);
        wp_localize_script('mg-surcharges', 'MGSurchargeProduct', [
            'options' => self::prepare_frontend_options($available),
            'context' => $context,
            'messages' => [
                'required' => __('Kérjük válassz az extra opciók közül.', 'mockup-generator'),
            ],
        ]);
        $title = esc_html__('Feláras opciók', 'mockup-generator');
        echo '<div class="mg-surcharge-box" data-context="product" data-title="' . esc_attr($title) . '">';
        echo '<div class="mg-surcharge-box__header">';
        echo '<h3 class="mg-surcharge-box__title">' . $title . '</h3>';
        echo '</div>';
        echo '<div class="mg-surcharge-box__options">';
        foreach ($available as $option) {
            echo self::render_option_control($option, null, 'product');
        }
        echo '</div>';
        echo '</div>';
    }

    public static function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$product) {
            return $passed;
        }
        $parent = $product_id && $variation_id ? wc_get_product($product_id) : ($product->is_type('variation') ? $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product : $product);
        $variation = $product->is_type('variation') ? $product : ($variation_id ? wc_get_product($variation_id) : null);
        $parent = $parent instanceof WC_Product ? $parent : $product;
        $available = self::get_applicable_surcharges($parent, $variation, 'product');
        if (empty($available)) {
            return $passed;
        }
        $selections = isset($_POST[self::FIELD_NAME]) ? wp_unslash((array)$_POST[self::FIELD_NAME]) : [];
        foreach ($available as $option) {
            $id = $option['id'];
            if (!empty($option['require_choice']) && !array_key_exists($id, $selections)) {
                wc_add_notice(sprintf(__('Válaszd ki, hogy kéred-e: %s', 'mockup-generator'), $option['name']), 'error');
                return false;
            }
        }
        if (!empty(WC()->cart)) {
            $locked_ids = self::get_cart_lock_ids(WC()->cart);
            if (!empty($locked_ids)) {
                $prepared = self::prepare_cart_item_surcharges($available, $selections);
                $new_locked = [];
                foreach ($available as $option) {
                    if (empty($option['cart_lock'])) {
                        continue;
                    }
                    $id = $option['id'];
                    if (!empty($prepared[$id]['enabled'])) {
                        $new_locked[] = $id;
                    }
                }
                $shared = array_intersect($new_locked, $locked_ids);
                $extra = array_diff($new_locked, $locked_ids);
                if (empty($shared) || !empty($extra)) {
                    wc_add_notice(self::get_cart_lock_message(), 'error');
                    return false;
                }
            }
        }
        return $passed;
    }

    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id, $quantity) {
        $product = wc_get_product($product_id);
        $variation = $variation_id ? wc_get_product($variation_id) : null;
        if (!$product instanceof WC_Product) {
            return $cart_item_data;
        }
        $available = self::get_applicable_surcharges($product, $variation, 'product');
        if (empty($available)) {
            return $cart_item_data;
        }
        $selections = isset($_POST[self::FIELD_NAME]) ? wp_unslash((array)$_POST[self::FIELD_NAME]) : [];
        $cart_item_data['mg_surcharge_data'] = self::prepare_cart_item_surcharges($available, $selections);
        return $cart_item_data;
    }

    public static function restore_cart_item($cart_item, $values) {
        if (isset($values['mg_surcharge_data'])) {
            $cart_item['mg_surcharge_data'] = $values['mg_surcharge_data'];
        }
        return $cart_item;
    }

    public static function render_cart_item_data($item_data, $cart_item) {
        if (empty($cart_item['mg_surcharge_data']) || !is_array($cart_item['mg_surcharge_data'])) {
            return $item_data;
        }
        $quantity = isset($cart_item['quantity']) ? max(1, intval($cart_item['quantity'])) : 1;
        foreach ($cart_item['mg_surcharge_data'] as $surcharge) {
            if (empty($surcharge['enabled'])) {
                continue;
            }
            $amount = floatval($surcharge['amount']) * $quantity;
            $item_data[] = [
                'key' => $surcharge['name'],
                'display' => wc_price($amount),
            ];
        }
        return $item_data;
    }

    public static function add_order_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['mg_surcharge_data']) || !is_array($values['mg_surcharge_data'])) {
            return;
        }
        foreach ($values['mg_surcharge_data'] as $surcharge) {
            if (empty($surcharge['enabled'])) {
                continue;
            }
            $item->add_meta_data($surcharge['name'], wp_strip_all_tags(wc_price($surcharge['amount'])), true);
        }
    }

    public static function apply_fees($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['mg_surcharge_data'])) {
                continue;
            }
            foreach ($cart_item['mg_surcharge_data'] as $surcharge) {
                if (empty($surcharge['enabled'])) {
                    continue;
                }
                $amount = floatval($surcharge['amount']) * $cart_item['quantity'];
                if ($amount <= 0) {
                    continue;
                }
                $product_name = isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product ? $cart_item['data']->get_name() : wc_get_product($cart_item['product_id'])->get_name();
                $name = sprintf('%s (%s)', $surcharge['name'], $product_name);
                $cart->add_fee($name, $amount, true);
            }
        }
    }

    public static function validate_cart_items($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        $notices_added = [];
        $modified = false;
        foreach ($cart->get_cart() as $cart_item_key => &$cart_item) {
            if (empty($cart_item['mg_surcharge_data']) || !is_array($cart_item['mg_surcharge_data'])) {
                continue;
            }
            $product = wc_get_product($cart_item['product_id']);
            $variation = !empty($cart_item['variation_id']) ? wc_get_product($cart_item['variation_id']) : null;
            $available = self::get_applicable_surcharges($product, $variation, 'any');
            $valid_ids = wp_list_pluck($available, 'id');
            foreach ($cart_item['mg_surcharge_data'] as $id => $surcharge) {
                $option = MG_Surcharge_Manager::get_surcharge($id);
                if (!$option || !in_array($id, $valid_ids, true)) {
                    unset($cart_item['mg_surcharge_data'][$id]);
                    $modified = true;
                    continue;
                }
                if (!MG_Surcharge_Manager::conditions_match_product($option, $product, $variation, $cart->get_subtotal())) {
                    if (!isset($notices_added[$cart_item_key])) {
                        wc_add_notice(sprintf(__('A "%1$s" opció eltávolításra került, mert már nem elérhető ehhez a termékhez.', 'mockup-generator'), $option['name']), 'notice');
                        $notices_added[$cart_item_key] = true;
                    }
                    unset($cart_item['mg_surcharge_data'][$id]);
                    $modified = true;
                }
            }
        }
        unset($cart_item);
        if ($modified) {
            $cart->set_session();
        }
    }

    public static function render_cart_item_controls($cart_item, $cart_item_key) {
        if (is_cart() === false) {
            return;
        }
        $product = wc_get_product($cart_item['product_id']);
        if (!$product) {
            return;
        }
        $variation = !empty($cart_item['variation_id']) ? wc_get_product($cart_item['variation_id']) : null;
        $available = self::get_applicable_surcharges($product, $variation, 'cart');
        if (empty($available)) {
            return;
        }
        wp_enqueue_style('mg-surcharges');
        echo '<div class="mg-surcharge-box mg-surcharge-box--cart" data-context="cart">';
        echo '<div class="mg-surcharge-box__options">';
        foreach ($available as $option) {
            $data = isset($cart_item['mg_surcharge_data'][$option['id']]) ? $cart_item['mg_surcharge_data'][$option['id']] : null;
            echo self::render_option_control($option, $data, 'cart', $cart_item_key);
        }
        echo '</div>';
        echo '</div>';
    }

    public static function handle_cart_update() {
        if (!isset($_POST[self::CART_FIELD_NAME]) || !WC()->cart) {
            return;
        }
        $data = wp_unslash($_POST[self::CART_FIELD_NAME]);
        foreach (WC()->cart->cart_contents as $key => &$item) {
            $product = wc_get_product($item['product_id']);
            if (!$product) {
                continue;
            }
            $variation = !empty($item['variation_id']) ? wc_get_product($item['variation_id']) : null;
            $available = self::get_applicable_surcharges($product, $variation, 'cart');
            $selection = isset($data[$key]) ? (array)$data[$key] : [];
            $updated = self::prepare_cart_item_surcharges($available, $selection, $item);
            $existing = isset($item['mg_surcharge_data']) && is_array($item['mg_surcharge_data']) ? $item['mg_surcharge_data'] : [];
            foreach ($existing as $id => $surcharge) {
                if (!isset($updated[$id])) {
                    $updated[$id] = $surcharge;
                }
            }
            $item['mg_surcharge_data'] = $updated;
        }
        unset($item);
        WC()->cart->set_session();
    }

    private static function get_applicable_surcharges($product, $variation = null, $location = 'product') {
        if (!$product instanceof WC_Product) {
            return [];
        }
        $surcharges = MG_Surcharge_Manager::get_surcharges(true);
        $result = [];
        foreach ($surcharges as $surcharge) {
            if (!self::should_display_in_location($surcharge, $location)) {
                continue;
            }
            if (!MG_Surcharge_Manager::conditions_match_product($surcharge, $product, $variation)) {
                continue;
            }
            $result[] = $surcharge;
        }
        return MG_Surcharge_Manager::sort_surcharges($result);
    }

    private static function should_display_in_location($surcharge, $location) {
        $display = isset($surcharge['frontend_display']) ? $surcharge['frontend_display'] : 'product';
        if ($location === 'any') {
            return true;
        }
        if ($display === 'both') {
            return true;
        }
        return $display === $location;
    }

    private static function prepare_cart_item_surcharges($available, $selections, $cart_item = null) {
        $prepared = [];
        foreach ($available as $option) {
            $id = $option['id'];
            $value = isset($selections[$id]) ? sanitize_text_field($selections[$id]) : null;
            if ($value === null && $cart_item && isset($cart_item['mg_surcharge_data'][$id])) {
                $value = $cart_item['mg_surcharge_data'][$id]['enabled'] ? '1' : ($cart_item['mg_surcharge_data'][$id]['require_choice'] ? '0' : null);
            }
            if ($value === null && !empty($option['default_enabled'])) {
                $value = '1';
            }
            $enabled = self::is_truthy($value);
            $prepared[$id] = [
                'id' => $id,
                'name' => $option['name'],
                'amount' => $option['amount'],
                'description' => $option['description'],
                'enabled' => $enabled,
                'require_choice' => !empty($option['require_choice']),
                'default_enabled' => !empty($option['default_enabled']),
                'cart_lock' => !empty($option['cart_lock']),
            ];
        }
        return $prepared;
    }

    private static function prepare_frontend_options($options) {
        $prepared = [];
        foreach ($options as $option) {
            $prepared[] = [
                'id' => $option['id'],
                'require_choice' => !empty($option['require_choice']),
                'default_enabled' => !empty($option['default_enabled']),
                'conditions' => $option['conditions'],
            ];
        }
        return $prepared;
    }

    private static function render_option_control($option, $existing = null, $location = 'product', $cart_key = '') {
        $id = $option['id'];
        $field_name = $location === 'cart' ? sprintf('%s[%s][%s]', self::CART_FIELD_NAME, $cart_key, $id) : sprintf('%s[%s]', self::FIELD_NAME, $id);
        $value_yes = '1';
        $value_no = '0';
        $is_required = !empty($option['require_choice']);
        $is_checked = $existing ? !empty($existing['enabled']) : (!empty($option['default_enabled']));
        $has_choice = $existing ? true : (!empty($option['default_enabled']));
        $tooltip = $option['description'] ? '<span class="mg-surcharge-tooltip" title="' . esc_attr(wp_strip_all_tags($option['description'])) . '">i</span>' : '';
        $price = wp_strip_all_tags(wc_price($option['amount']));
        ob_start();
        echo '<div class="mg-surcharge-option" data-id="' . esc_attr($id) . '" data-required="' . ($is_required ? '1' : '0') . '">';
        echo '<div class="mg-surcharge-option__header">';
        if ($is_required) {
            echo '<span class="mg-surcharge-option__title">' . esc_html($option['name']) . $tooltip . '</span>';
        } else {
            echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="0" />';
            echo '<label class="mg-surcharge-option__title mg-surcharge-option__title--toggle">';
            echo '<input type="checkbox" value="' . esc_attr($value_yes) . '" name="' . esc_attr($field_name) . '" ' . checked($is_checked, true, false) . ' />';
            echo '<span class="mg-surcharge-option__label-text">' . esc_html($option['name']) . '</span>' . $tooltip;
            echo '</label>';
        }
        echo '<span class="mg-surcharge-option__amount">+' . esc_html($price) . '</span>';
        echo '</div>';
        if ($is_required) {
            echo '<div class="mg-surcharge-option__control">';
            echo '<label><input type="radio" value="' . esc_attr($value_yes) . '" name="' . esc_attr($field_name) . '" ' . checked($is_checked, true, false) . ' /> ' . esc_html__('Igen', 'mockup-generator') . '</label> ';
            echo '<label><input type="radio" value="' . esc_attr($value_no) . '" name="' . esc_attr($field_name) . '" ' . checked(!$is_checked && $has_choice, true, false) . ' /> ' . esc_html__('Nem', 'mockup-generator') . '</label>';
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function get_product_context($product, $variation = null) {
        $is_variable = $product->is_type('variable');
        $context = [
            'product_id' => $product->get_id(),
            'variation_id' => $variation instanceof WC_Product_Variation ? $variation->get_id() : 0,
            'attributes' => [],
            'base_attributes' => [],
            'categories' => wc_get_product_term_ids($product->get_id(), 'product_cat'),
            'is_variable' => $is_variable,
        ];
        $taxonomies = ['pa_termektipus', 'pa_product_type', 'pa_szin', 'pa_color', 'pa_meret', 'pa_size'];
        foreach ($taxonomies as $taxonomy) {
            $slugs = self::get_attribute_slugs($product, $taxonomy);
            $context['base_attributes'][$taxonomy] = $slugs;
            if (!$is_variable) {
                $context['attributes'][$taxonomy] = $slugs;
            }
        }
        if ($variation instanceof WC_Product_Variation) {
            foreach ($variation->get_attributes() as $key => $value) {
                if (strpos($key, 'attribute_') === 0 && $value !== '') {
                    $taxonomy = str_replace('attribute_', '', $key);
                    $context['attributes'][$taxonomy] = [sanitize_title($value)];
                }
            }
        }
        return $context;
    }

    private static function get_attribute_slugs($product, $taxonomy) {
        $terms = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'slugs']);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map('sanitize_title', $terms);
    }

    private static function is_truthy($value) {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower((string)$value);
        return in_array($value, ['1', 'yes', 'on', 'true'], true);
    }

    private static function get_cart_lock_ids($cart) {
        $locked = [];
        foreach ($cart->get_cart() as $item) {
            if (empty($item['mg_surcharge_data']) || !is_array($item['mg_surcharge_data'])) {
                continue;
            }
            foreach ($item['mg_surcharge_data'] as $surcharge_id => $data) {
                if (empty($data['enabled'])) {
                    continue;
                }
                $is_locked = isset($data['cart_lock']) ? !empty($data['cart_lock']) : false;
                if (!$is_locked) {
                    $option = MG_Surcharge_Manager::get_surcharge($surcharge_id);
                    $is_locked = !empty($option['cart_lock']);
                }
                if ($is_locked) {
                    $locked[] = $surcharge_id;
                }
            }
        }
        return array_values(array_unique($locked));
    }

    private static function get_cart_lock_message() {
        $message = get_option(MG_Surcharge_Manager::LOCK_MESSAGE_OPTION, '');
        if ('' === trim((string)$message)) {
            return __('A kosárban már van olyan termék, amely feltételhez kötött feláras opciót tartalmaz, ezért most csak ilyen opcióval rendelkező terméket adhatsz hozzá. Ha más terméket szeretnél, külön vásárlásban teheted meg.', 'mockup-generator');
        }
        return wp_kses_post($message);
    }
}
