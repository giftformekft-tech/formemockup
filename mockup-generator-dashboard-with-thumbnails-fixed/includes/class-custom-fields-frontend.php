<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Custom_Fields_Frontend {
    protected static $current_product_id = 0;
    protected static $cached_fields = array();
    protected static $heading_rendered = false;
    protected static $nonce_rendered = false;

    /**
     * Register front-end hooks.
     */
    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_fields_above'), 5);
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_fields_below'), 20);
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_fields'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'add_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'add_order_item_meta'), 10, 4);
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'add_order_meta'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_cart_surcharges'), 30, 1);
    }

    public static function enqueue_assets() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        global $post;
        if (!$post) {
            return;
        }
        if (!MG_Custom_Fields_Manager::is_custom_product($post->ID)) {
            return;
        }
        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_url = plugins_url('assets/css/custom-fields.css', $base_file);
        wp_enqueue_style('mg-custom-fields', $style_url, array(), '1.0.0');
        $script_url = plugins_url('assets/js/custom-fields-frontend.js', $base_file);
        wp_enqueue_script('mg-custom-fields-frontend', $script_url, array(), '1.0.0', true);
    }

    protected static function ensure_fields_loaded($product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return false;
        }
        if (self::$current_product_id !== $product_id) {
            self::$current_product_id = $product_id;
            self::$cached_fields = MG_Custom_Fields_Manager::get_fields_for_product($product_id);
            self::$heading_rendered = false;
            self::$nonce_rendered = false;
        }
        return !empty(self::$cached_fields);
    }

    protected static function render_fields_for_placement($placement) {
        global $product;
        if (!$product) {
            return;
        }
        $product_id = $product->get_id();
        if (!MG_Custom_Fields_Manager::is_custom_product($product_id)) {
            return;
        }
        if (!self::ensure_fields_loaded($product_id)) {
            return;
        }
        $normalized = MG_Custom_Fields_Manager::normalize_placement($placement);
        $group = array();
        foreach (self::$cached_fields as $field) {
            $field_placement = isset($field['placement']) ? MG_Custom_Fields_Manager::normalize_placement($field['placement']) : 'below_variants';
            if ($field_placement === $normalized) {
                $group[] = $field;
            }
        }
        if (empty($group)) {
            return;
        }
        if (!self::$nonce_rendered) {
            wp_nonce_field('mg_custom_fields', 'mg_custom_fields_nonce');
            self::$nonce_rendered = true;
        }
        $classes = array('mg-custom-fields', 'mg-custom-fields--placement-' . sanitize_html_class($normalized));
        echo '<div class="' . esc_attr(implode(' ', $classes)) . '" data-mgcf-placement="' . esc_attr($normalized) . '">';
        if (!self::$heading_rendered) {
            echo '<h3 class="mg-custom-fields__title">' . esc_html__('Egyedi mezők', 'mgcf') . '</h3>';
            self::$heading_rendered = true;
        }
        foreach ($group as $field) {
            self::render_single_field($field);
        }
        echo '</div>';
    }

    public static function render_fields_above() {
        self::render_fields_for_placement('above_variants');
    }

    public static function render_fields_below() {
        self::render_fields_for_placement('below_variants');
    }

    protected static function render_single_field($field) {
        $field = is_array($field) ? $field : array();
        $id = isset($field['id']) ? $field['id'] : 'mgcf_' . uniqid();
        $label = isset($field['label']) ? $field['label'] : '';
        $type = isset($field['type']) ? $field['type'] : 'text';
        $required = !empty($field['required']);
        $default = isset($field['default']) ? $field['default'] : '';
        $min = isset($field['validation_min']) ? $field['validation_min'] : '';
        $max = isset($field['validation_max']) ? $field['validation_max'] : '';
        $description = isset($field['description']) ? $field['description'] : '';
        $placement = isset($field['placement']) ? MG_Custom_Fields_Manager::normalize_placement($field['placement']) : 'below_variants';
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
        $surcharge_type = isset($field['surcharge_type']) ? $field['surcharge_type'] : 'none';
        $surcharge_amount = isset($field['surcharge_amount']) ? floatval($field['surcharge_amount']) : 0.0;

        $input_id = sanitize_html_class('mgcf_' . $id);
        $name_attr = 'mg_custom_fields[' . $id . ']';
        $required_attr = $required ? ' required' : '';
        $wrapper_classes = array('mg-custom-field', 'mg-custom-field--' . esc_attr($type));
        if ($placement !== '') {
            $wrapper_classes[] = 'mg-custom-field--placement-' . sanitize_html_class($placement);
        }
        echo '<div class="' . esc_attr(implode(' ', $wrapper_classes)) . '" data-field-id="' . esc_attr($id) . '">';
        echo '<label for="' . esc_attr($input_id) . '">' . esc_html($label);
        if ($required) {
            echo ' <span class="mg-required">*</span>';
        }
        echo '</label>';

        $pricing_note = '';
        if ($surcharge_type === 'fixed' && $surcharge_amount > 0) {
            $pricing_note = sprintf(__(' + %s', 'mgcf'), wc_price($surcharge_amount));
        } elseif ($surcharge_type === 'percent' && $surcharge_amount > 0) {
            $pricing_note = sprintf(__(' + %s%%', 'mgcf'), wc_format_decimal($surcharge_amount));
        }
        if ($pricing_note !== '') {
            echo '<span class="mg-custom-field__surcharge">' . esc_html($pricing_note) . '</span>';
        }

        switch ($type) {
            case 'number':
                $attrs = '';
                if ($min !== '') {
                    $attrs .= ' min="' . esc_attr($min) . '"';
                }
                if ($max !== '') {
                    $attrs .= ' max="' . esc_attr($max) . '"';
                }
                echo '<input type="number" id="' . esc_attr($input_id) . '" name="' . esc_attr($name_attr) . '" value="' . esc_attr($default) . '"' . $attrs . $required_attr . ' />';
                break;
            case 'date':
                $attrs = '';
                if ($min !== '') {
                    $attrs .= ' min="' . esc_attr($min) . '"';
                }
                if ($max !== '') {
                    $attrs .= ' max="' . esc_attr($max) . '"';
                }
                echo '<input type="date" id="' . esc_attr($input_id) . '" name="' . esc_attr($name_attr) . '" value="' . esc_attr($default) . '"' . $attrs . $required_attr . ' />';
                break;
            case 'color':
                $value = $default !== '' ? $default : '#000000';
                echo '<input type="color" id="' . esc_attr($input_id) . '" name="' . esc_attr($name_attr) . '" value="' . esc_attr($value) . '"' . $required_attr . ' />';
                break;
            case 'select':
                echo '<select id="' . esc_attr($input_id) . '" name="' . esc_attr($name_attr) . '"' . $required_attr . '>';
                echo '<option value="">' . esc_html__('Válassz…', 'mgcf') . '</option>';
                foreach ($options as $option) {
                    $selected = ($default !== '' && $default == $option) ? ' selected' : '';
                    echo '<option value="' . esc_attr($option) . '"' . $selected . '>' . esc_html($option) . '</option>';
                }
                echo '</select>';
                break;
            default:
                $attrs = '';
                if ($min !== '' && is_numeric($min)) {
                    $attrs .= ' minlength="' . esc_attr(intval($min)) . '"';
                }
                if ($max !== '' && is_numeric($max)) {
                    $attrs .= ' maxlength="' . esc_attr(intval($max)) . '"';
                }
                echo '<input type="text" id="' . esc_attr($input_id) . '" name="' . esc_attr($name_attr) . '" value="' . esc_attr($default) . '"' . $attrs . $required_attr . ' />';
                break;
        }
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
    }

    public static function validate_fields($passed, $product_id, $quantity, $variation_id = 0, $variations = null) {
        if (!MG_Custom_Fields_Manager::is_custom_product($product_id)) {
            return $passed;
        }
        $fields = MG_Custom_Fields_Manager::get_fields_for_product($product_id);
        if (empty($fields)) {
            return $passed;
        }
        if (empty($_POST['mg_custom_fields_nonce']) || !wp_verify_nonce(wp_unslash($_POST['mg_custom_fields_nonce']), 'mg_custom_fields')) {
            wc_add_notice(__('Érvénytelen kérés. Kérjük, frissítsd az oldalt.', 'mgcf'), 'error');
            return false;
        }
        $submitted = isset($_POST['mg_custom_fields']) ? (array) $_POST['mg_custom_fields'] : array();
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $label = $field['label'];
            $value = isset($submitted[$field_id]) ? $submitted[$field_id] : '';
            $is_required = !empty($field['required']);
            $type = $field['type'];
            $min = isset($field['validation_min']) ? $field['validation_min'] : '';
            $max = isset($field['validation_max']) ? $field['validation_max'] : '';
            if ($is_required && ('' === $value || (is_string($value) && trim($value) === ''))) {
                wc_add_notice(sprintf(__('A(z) %s mező kitöltése kötelező.', 'mgcf'), esc_html($label)), 'error');
                $passed = false;
                continue;
            }
            if ($value === '' && !$is_required) {
                continue;
            }
            switch ($type) {
                case 'number':
                    if (!is_numeric($value)) {
                        wc_add_notice(sprintf(__('A(z) %s mezőbe számot adj meg.', 'mgcf'), esc_html($label)), 'error');
                        $passed = false;
                        break;
                    }
                    $value_num = floatval($value);
                    if ($min !== '' && is_numeric($min) && $value_num < floatval($min)) {
                        wc_add_notice(sprintf(__('A(z) %s mező értéke nem lehet kevesebb, mint %s.', 'mgcf'), esc_html($label), esc_html($min)), 'error');
                        $passed = false;
                    }
                    if ($max !== '' && is_numeric($max) && $value_num > floatval($max)) {
                        wc_add_notice(sprintf(__('A(z) %s mező értéke nem lehet nagyobb, mint %s.', 'mgcf'), esc_html($label), esc_html($max)), 'error');
                        $passed = false;
                    }
                    break;
                case 'date':
                    $value_str = sanitize_text_field($value);
                    if ($value_str === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value_str)) {
                        wc_add_notice(sprintf(__('A(z) %s mezőbe érvényes dátumot adj meg (ÉÉÉÉ-HH-NN).', 'mgcf'), esc_html($label)), 'error');
                        $passed = false;
                        break;
                    }
                    if ($min !== '' && $value_str < $min) {
                        wc_add_notice(sprintf(__('A(z) %s mező értéke nem lehet korábbi, mint %s.', 'mgcf'), esc_html($label), esc_html($min)), 'error');
                        $passed = false;
                    }
                    if ($max !== '' && $value_str > $max) {
                        wc_add_notice(sprintf(__('A(z) %s mező értéke nem lehet későbbi, mint %s.', 'mgcf'), esc_html($label), esc_html($max)), 'error');
                        $passed = false;
                    }
                    break;
                case 'color':
                    $color = sanitize_text_field($value);
                    if (function_exists('sanitize_hex_color')) {
                        $color = sanitize_hex_color($color);
                    }
                    if (!$color) {
                        wc_add_notice(sprintf(__('A(z) %s mezőbe érvényes színt adj meg.', 'mgcf'), esc_html($label)), 'error');
                        $passed = false;
                    }
                    break;
                case 'select':
                    $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
                    if (!in_array($value, $options, true)) {
                        wc_add_notice(sprintf(__('A(z) %s mezőben a listából válassz.', 'mgcf'), esc_html($label)), 'error');
                        $passed = false;
                    }
                    break;
                default:
                    $value_str = is_string($value) ? trim($value) : '';
                    if ($value_str === '' && $is_required) {
                        wc_add_notice(sprintf(__('A(z) %s mező kitöltése kötelező.', 'mgcf'), esc_html($label)), 'error');
                        $passed = false;
                        break;
                    }
                    if ($min !== '' && is_numeric($min) && strlen($value_str) < intval($min)) {
                        wc_add_notice(sprintf(__('A(z) %s mező túl rövid.', 'mgcf'), esc_html($label)), 'error');
                        $passed = false;
                    }
                    if ($max !== '' && is_numeric($max) && strlen($value_str) > intval($max)) {
                        wc_add_notice(sprintf(__('A(z) %s mező túl hosszú.', 'mgcf'), esc_html($label)), 'error');
                        $passed = false;
                    }
                    break;
            }
        }
        return $passed;
    }

    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if (!MG_Custom_Fields_Manager::is_custom_product($product_id)) {
            return $cart_item_data;
        }
        $fields = MG_Custom_Fields_Manager::get_fields_for_product($product_id);
        if (empty($fields)) {
            return $cart_item_data;
        }
        if (empty($_POST['mg_custom_fields_nonce']) || !wp_verify_nonce(wp_unslash($_POST['mg_custom_fields_nonce']), 'mg_custom_fields')) {
            return $cart_item_data;
        }
        $submitted = isset($_POST['mg_custom_fields']) ? (array) $_POST['mg_custom_fields'] : array();
        $collected = array();
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $raw = isset($submitted[$field_id]) ? $submitted[$field_id] : '';
            $normalized = self::normalize_field_value($field, $raw);
            if ($normalized['value'] === '' && !empty($field['required'])) {
                continue;
            }
            $collected[$field_id] = array(
                'id'               => $field_id,
                'label'            => $field['label'],
                'value'            => $normalized['value'],
                'display'          => $normalized['display'],
                'surcharge_type'   => isset($field['surcharge_type']) ? $field['surcharge_type'] : 'none',
                'surcharge_amount' => isset($field['surcharge_amount']) ? floatval($field['surcharge_amount']) : 0.0,
                'applied_surcharge'=> 0.0,
                'mockup'           => isset($field['mockup']) ? $field['mockup'] : array(),
            );
        }
        if (empty($collected)) {
            return $cart_item_data;
        }
        $product_object = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
        $base_price = $product_object ? floatval(wc_get_price_to_display($product_object)) : 0.0;
        $cart_item_data['mg_custom_fields'] = $collected;
        $cart_item_data['mg_custom_fields_base_price'] = $base_price;
        $signature = md5(wp_json_encode($collected));
        $cart_item_data['mg_custom_fields_signature'] = $signature;
        $cart_item_data['unique_key'] = $signature;
        return $cart_item_data;
    }

    protected static function normalize_field_value($field, $raw) {
        $type = isset($field['type']) ? $field['type'] : 'text';
        $default = isset($field['default']) ? $field['default'] : '';
        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
        switch ($type) {
            case 'number':
                if ($raw === '' && $default !== '') {
                    $raw = $default;
                }
                return array(
                    'value' => is_numeric($raw) ? strval($raw) : '',
                    'display' => is_numeric($raw) ? strval($raw) : '',
                );
            case 'date':
                $value = sanitize_text_field($raw ? $raw : $default);
                return array('value' => $value, 'display' => $value);
            case 'color':
                $value = sanitize_text_field($raw ? $raw : $default);
                if (function_exists('sanitize_hex_color')) {
                    $value = sanitize_hex_color($value);
                }
                if (!$value) {
                    $value = '';
                }
                return array('value' => $value, 'display' => $value);
            case 'select':
                $value = $raw !== '' ? $raw : $default;
                if (!in_array($value, $options, true)) {
                    $value = '';
                }
                return array('value' => $value, 'display' => $value);
            default:
                $value = sanitize_text_field($raw !== '' ? $raw : $default);
                return array('value' => $value, 'display' => $value);
        }
    }

    public static function display_cart_item_data($item_data, $cart_item) {
        if (empty($cart_item['mg_custom_fields']) || !is_array($cart_item['mg_custom_fields'])) {
            return $item_data;
        }
        foreach ($cart_item['mg_custom_fields'] as $field) {
            if (empty($field['label'])) {
                continue;
            }
            $display = isset($field['display']) && $field['display'] !== '' ? $field['display'] : __('—', 'mgcf');
            $surcharge_note = '';
            if (!empty($field['applied_surcharge'])) {
                $surcharge_note = ' (' . sprintf(__('+%s', 'mgcf'), wc_price($field['applied_surcharge'])) . ')';
            } elseif (!empty($field['surcharge_type']) && $field['surcharge_type'] === 'percent' && !empty($field['surcharge_amount'])) {
                $surcharge_note = ' (' . sprintf(__('+%s%%', 'mgcf'), wc_format_decimal($field['surcharge_amount'])) . ')';
            }
            $item_data[] = array(
                'name'  => wp_kses_post($field['label']),
                'value' => wp_kses_post($display . $surcharge_note),
            );
        }
        return $item_data;
    }

    public static function add_order_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['mg_custom_fields']) || !is_array($values['mg_custom_fields'])) {
            return;
        }
        $stored = array();
        foreach ($values['mg_custom_fields'] as $field) {
            if (empty($field['label'])) {
                continue;
            }
            $display = isset($field['display']) && $field['display'] !== '' ? $field['display'] : __('—', 'mgcf');
            $item->add_meta_data($field['label'], $display, true);
            $stored[] = array(
                'id' => $field['id'],
                'label' => $field['label'],
                'value' => $display,
                'surcharge' => isset($field['applied_surcharge']) ? floatval($field['applied_surcharge']) : 0.0,
                'surcharge_type' => isset($field['surcharge_type']) ? $field['surcharge_type'] : 'none',
                'surcharge_amount' => isset($field['surcharge_amount']) ? floatval($field['surcharge_amount']) : 0.0,
                'mockup' => isset($field['mockup']) ? $field['mockup'] : array(),
            );
        }
        if (!empty($stored)) {
            $item->add_meta_data('_mg_custom_fields', $stored, true);
        }
    }

    public static function add_order_meta($order_id, $data) {
        if (empty($order_id)) {
            return;
        }
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $aggregated = array();
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (empty($cart_item['mg_custom_fields'])) {
                continue;
            }
            $aggregated[] = array(
                'product_id' => isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0,
                'variation_id' => isset($cart_item['variation_id']) ? intval($cart_item['variation_id']) : 0,
                'fields' => $cart_item['mg_custom_fields'],
            );
        }
        if (!empty($aggregated)) {
            $order->update_meta_data('_mg_custom_fields', $aggregated);
            $order->save();
        }
    }

    public static function apply_cart_surcharges($cart) {
        if (!is_object($cart) || !is_a($cart, 'WC_Cart')) {
            return;
        }
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['mg_custom_fields']) || empty($cart_item['data'])) {
                continue;
            }
            $product_object = $cart_item['data'];
            $base_price = isset($cart_item['mg_custom_fields_base_price']) ? floatval($cart_item['mg_custom_fields_base_price']) : floatval($product_object->get_price());
            $total_extra = 0.0;
            foreach ($cart_item['mg_custom_fields'] as $field_id => $field) {
                $applied = 0.0;
                $surcharge_type = isset($field['surcharge_type']) ? $field['surcharge_type'] : 'none';
                $amount = isset($field['surcharge_amount']) ? floatval($field['surcharge_amount']) : 0.0;
                if ($surcharge_type === 'fixed' && $amount > 0) {
                    $applied = $amount;
                } elseif ($surcharge_type === 'percent' && $amount > 0) {
                    $applied = ($base_price * $amount) / 100;
                }
                if ($applied < 0) {
                    $applied = 0.0;
                }
                $total_extra += $applied;
                $cart->cart_contents[$cart_item_key]['mg_custom_fields'][$field_id]['applied_surcharge'] = $applied;
            }
            $new_price = max(0, $base_price + $total_extra);
            $product_object->set_price($new_price);
            $cart->cart_contents[$cart_item_key]['data'] = $product_object;
            $cart->cart_contents[$cart_item_key]['mg_custom_fields_total_surcharge'] = $total_extra;
        }
    }
}
