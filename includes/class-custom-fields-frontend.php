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
        add_action('woocommerce_after_order_itemmeta', array(__CLASS__, 'render_order_item_design_reference'), 10, 3);
        add_action('admin_post_mgcf_download_design', array(__CLASS__, 'handle_admin_design_download'));
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

        $design_reference = self::capture_design_reference_for_item($item, $values);
        if (!empty($design_reference)) {
            $item->add_meta_data('_mg_print_design_reference', $design_reference, true);
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

    protected static function capture_design_reference_for_item($item, $values) {
        if (!is_object($item) || !is_callable(array($item, 'get_product_id'))) {
            return array();
        }

        $product_id = (int) $item->get_product_id();
        if ($product_id <= 0 && !empty($values['product_id'])) {
            $product_id = (int) $values['product_id'];
        }

        $variation_id = !empty($values['variation_id']) ? (int) $values['variation_id'] : 0;
        $candidate_ids = array();

        if ($variation_id > 0) {
            $candidate_ids[] = $variation_id;
        }
        if ($product_id > 0) {
            $candidate_ids[] = $product_id;
        }

        if (function_exists('wc_get_product')) {
            $product_object = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
            if ($product_object) {
                $parent_id = (int) $product_object->get_parent_id();
                if ($parent_id > 0) {
                    $candidate_ids[] = $parent_id;
                }
            }
        }

        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
        if (empty($candidate_ids)) {
            return array();
        }

        $meta_keys = self::get_design_meta_keys();

        foreach ($candidate_ids as $candidate_id) {
            $design_path = '';
            $design_attachment_id = 0;

            if (!empty($meta_keys['path']) && function_exists('get_post_meta')) {
                $stored_path = get_post_meta($candidate_id, $meta_keys['path'], true);
                if (is_string($stored_path) && $stored_path !== '') {
                    $design_path = wp_normalize_path($stored_path);
                }
            }

            if (!empty($meta_keys['attachment']) && function_exists('get_post_meta')) {
                $stored_attachment = get_post_meta($candidate_id, $meta_keys['attachment'], true);
                if (!empty($stored_attachment)) {
                    $design_attachment_id = (int) $stored_attachment;
                }
            }

            if ($design_path === '' && $design_attachment_id <= 0) {
                $fallback = self::locate_design_reference_from_index($candidate_id);
                if (!empty($fallback['design_path']) && is_string($fallback['design_path'])) {
                    $design_path = wp_normalize_path($fallback['design_path']);
                }
                if (!empty($fallback['design_attachment_id'])) {
                    $design_attachment_id = (int) $fallback['design_attachment_id'];
                }
            }

            if ($design_path === '' && $design_attachment_id > 0 && function_exists('get_attached_file')) {
                $attached_path = get_attached_file($design_attachment_id);
                if (is_string($attached_path) && $attached_path !== '') {
                    $design_path = wp_normalize_path($attached_path);
                }
            }

            $design_url = '';
            if ($design_attachment_id > 0 && function_exists('wp_get_attachment_url')) {
                $design_url = wp_get_attachment_url($design_attachment_id);
            }

            if ($design_url === '' && $design_path !== '') {
                $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
                if (!empty($uploads['basedir']) && !empty($uploads['baseurl'])) {
                    $normalized_base = wp_normalize_path($uploads['basedir']);
                    $normalized_path = wp_normalize_path($design_path);
                    if ($normalized_base !== '' && strpos($normalized_path, $normalized_base) === 0) {
                        $relative = ltrim(substr($normalized_path, strlen($normalized_base)), '/\\');
                        $design_url = trailingslashit($uploads['baseurl']) . str_replace('\\', '/', $relative);
                    }
                }
            }

            if ($design_path === '' && $design_url === '') {
                continue;
            }

            $filename = '';
            if ($design_path !== '') {
                $filename = function_exists('wp_basename') ? wp_basename($design_path) : basename($design_path);
            } elseif ($design_url !== '') {
                $url_path = wp_parse_url($design_url, PHP_URL_PATH);
                if (!empty($url_path)) {
                    $filename = function_exists('wp_basename') ? wp_basename($url_path) : basename($url_path);
                }
            }

            $reference = array(
                'ordered_product_id'   => $product_id,
                'source_product_id'    => $candidate_id,
                'design_path'          => $design_path,
                'design_filename'      => $filename,
                'design_url'           => $design_url,
                'design_attachment_id' => $design_attachment_id,
                'captured_at'          => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            );

            $reference = apply_filters('mgcf_captured_design_reference', $reference, $item, $values);

            if (!empty($reference) && is_array($reference)) {
                return $reference;
            }
        }

        return array();
    }

    protected static function get_design_meta_keys() {
        $path_key = '_mg_last_design_path';
        $attachment_key = '_mg_last_design_attachment';

        if (class_exists('MG_Mockup_Maintenance')) {
            if (defined('MG_Mockup_Maintenance::META_LAST_DESIGN_PATH')) {
                $path_key = MG_Mockup_Maintenance::META_LAST_DESIGN_PATH;
            }
            if (defined('MG_Mockup_Maintenance::META_LAST_DESIGN_ATTACHMENT')) {
                $attachment_key = MG_Mockup_Maintenance::META_LAST_DESIGN_ATTACHMENT;
            }
        }

        return array(
            'path'       => $path_key,
            'attachment' => $attachment_key,
        );
    }

    protected static function locate_design_reference_from_index($product_id) {
        $reference = array();
        $product_id = absint($product_id);
        if ($product_id <= 0 || !class_exists('MG_Mockup_Maintenance') || !method_exists('MG_Mockup_Maintenance', 'get_index')) {
            return $reference;
        }

        $index = MG_Mockup_Maintenance::get_index();
        if (empty($index) || !is_array($index)) {
            return $reference;
        }

        foreach ($index as $entry) {
            if (!is_array($entry) || (int) ($entry['product_id'] ?? 0) !== $product_id) {
                continue;
            }
            $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : array();
            if (!empty($source['design_attachment_id']) && empty($reference['design_attachment_id'])) {
                $reference['design_attachment_id'] = (int) $source['design_attachment_id'];
            }
            if (!empty($source['design_path']) && empty($reference['design_path'])) {
                $reference['design_path'] = $source['design_path'];
            }
            if (!empty($reference['design_path']) && !empty($reference['design_attachment_id'])) {
                break;
            }
        }

        return $reference;
    }

    public static function render_order_item_design_reference($item_id, $item, $product) {
        if (!is_admin() || !is_object($item) || !method_exists($item, 'get_meta')) {
            return;
        }

        $reference = $item->get_meta('_mg_print_design_reference', true);
        if (empty($reference) || !is_array($reference)) {
            $reference = self::rehydrate_order_item_design_reference($item);
            if (empty($reference)) {
                return;
            }
        }

        $label = apply_filters('mgcf_order_item_design_label', __('Nyomtatási minta', 'mgcf'), $item, $reference);
        $design_url = isset($reference['design_url']) ? $reference['design_url'] : '';
        $design_path = isset($reference['design_path']) ? $reference['design_path'] : '';
        $filename = isset($reference['design_filename']) ? $reference['design_filename'] : '';

        echo '<div class="mg-order-design-reference">';
        echo '<strong>' . esc_html($label) . ':</strong> ';

        if ($design_url) {
            $link_text = $filename !== '' ? $filename : $design_url;
            echo '<a href="' . esc_url($design_url) . '" target="_blank" rel="noopener">' . esc_html($link_text) . '</a>';
            $download_url = self::get_design_download_url($item_id, $reference);
            if ($download_url === '' && $design_url !== '') {
                $download_url = $design_url;
            }
            if ($download_url !== '') {
                echo ' <a class="button button-small mg-order-design-download" href="' . esc_url($download_url) . '" target="_blank" rel="noopener">' . esc_html__('Letöltés', 'mgcf') . '</a>';
            }
            if ($design_path && $design_path !== $link_text) {
                echo '<br /><small class="mg-order-design-path">' . esc_html($design_path) . '</small>';
            }
        } elseif ($design_path) {
            echo esc_html($design_path);
        } elseif ($filename) {
            echo esc_html($filename);
        } else {
            echo esc_html__('Nem elérhető', 'mgcf');
        }

        echo '</div>';
    }

    protected static function rehydrate_order_item_design_reference($item) {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return array();
        }

        $values = array();

        if (method_exists($item, 'get_product_id')) {
            $values['product_id'] = (int) $item->get_product_id();
        }

        if (method_exists($item, 'get_variation_id')) {
            $values['variation_id'] = (int) $item->get_variation_id();
        }

        $reference = self::capture_design_reference_for_item($item, $values);
        if (empty($reference)) {
            return array();
        }

        if (method_exists($item, 'update_meta_data')) {
            $item->update_meta_data('_mg_print_design_reference', $reference);
            if (method_exists($item, 'save')) {
                $item->save();
            }
        }

        return $reference;
    }

    protected static function get_design_download_url($item_id, $reference) {
        $item_id = absint($item_id);
        if ($item_id <= 0 || empty($reference) || !is_array($reference)) {
            return '';
        }

        $design_path = isset($reference['design_path']) ? $reference['design_path'] : '';
        $design_attachment_id = isset($reference['design_attachment_id']) ? absint($reference['design_attachment_id']) : 0;

        if (!function_exists('wp_upload_dir')) {
            return '';
        }

        $uploads = wp_upload_dir();
        if (empty($uploads['basedir'])) {
            return '';
        }

        $normalized_base = wp_normalize_path($uploads['basedir']);
        if ($normalized_base === '') {
            return '';
        }

        $has_local_reference = false;

        if ($design_path !== '') {
            $normalized_path = wp_normalize_path($design_path);
            if (strpos($normalized_path, $normalized_base) === 0) {
                $has_local_reference = true;
            }
        }

        if (!$has_local_reference && $design_attachment_id > 0 && function_exists('get_attached_file')) {
            $attachment_path = get_attached_file($design_attachment_id);
            if (is_string($attachment_path) && $attachment_path !== '') {
                $normalized_attachment = wp_normalize_path($attachment_path);
                if (strpos($normalized_attachment, $normalized_base) === 0) {
                    $has_local_reference = true;
                }
            }
        }

        if (!$has_local_reference) {
            return '';
        }

        $url = add_query_arg(
            array(
                'action'  => 'mgcf_download_design',
                'item_id' => $item_id,
            ),
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, 'mgcf_download_design_' . $item_id);
    }

    public static function handle_admin_design_download() {
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nincs jogosultság a fájl letöltéséhez.', 'mgcf'));
        }

        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        if ($item_id <= 0 || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mgcf_download_design_' . $item_id)) {
            wp_die(esc_html__('Érvénytelen letöltési hivatkozás.', 'mgcf'));
        }

        if (!class_exists('WC_Order_Factory')) {
            wp_die(esc_html__('A rendelési tétel nem található.', 'mgcf'));
        }

        $item = WC_Order_Factory::get_order_item($item_id);
        if (!$item || !is_object($item) || !method_exists($item, 'get_meta')) {
            wp_die(esc_html__('A rendelési tétel nem található.', 'mgcf'));
        }

        $reference = $item->get_meta('_mg_print_design_reference', true);
        if (empty($reference) || !is_array($reference)) {
            wp_die(esc_html__('A fájl nem érhető el.', 'mgcf'));
        }

        $design_path = isset($reference['design_path']) ? $reference['design_path'] : '';
        $design_url = isset($reference['design_url']) ? $reference['design_url'] : '';
        $filename = isset($reference['design_filename']) ? $reference['design_filename'] : '';
        $design_attachment_id = isset($reference['design_attachment_id']) ? absint($reference['design_attachment_id']) : 0;

        $resolved_path = '';
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
        $normalized_base = !empty($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';

        if ($design_attachment_id > 0 && function_exists('get_attached_file')) {
            $attachment_path = get_attached_file($design_attachment_id);
            if (is_string($attachment_path) && $attachment_path !== '') {
                $normalized_attachment = wp_normalize_path($attachment_path);
                if ($normalized_base === '' || strpos($normalized_attachment, $normalized_base) === 0) {
                    $resolved_path = $normalized_attachment;
                }
            }
        }

        if ($resolved_path === '' && $design_path !== '') {
            $normalized_path = wp_normalize_path($design_path);
            if ($normalized_base === '' || strpos($normalized_path, $normalized_base) === 0) {
                $resolved_path = $normalized_path;
            }
        }

        if ($resolved_path !== '' && file_exists($resolved_path) && is_readable($resolved_path)) {
            if ($filename === '') {
                $filename = function_exists('wp_basename') ? wp_basename($resolved_path) : basename($resolved_path);
            }

            $real_path = function_exists('realpath') ? realpath($resolved_path) : $resolved_path;
            if ($real_path !== false) {
                $resolved_path = wp_normalize_path($real_path);
            }

            nocache_headers();

            $type_source = $resolved_path;
            if ($filename !== '') {
                $type_source = $filename;
            }

            $filetype = function_exists('wp_check_filetype') ? wp_check_filetype($type_source) : false;
            if (!empty($filetype['type'])) {
                header('Content-Type: ' . $filetype['type']);
            } else {
                header('Content-Type: application/octet-stream');
            }

            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');

            $filesize = filesize($resolved_path);
            if ($filesize !== false) {
                header('Content-Length: ' . $filesize);
            }

            readfile($resolved_path);
            exit;
        }

        if ($design_url !== '') {
            wp_safe_redirect($design_url);
            exit;
        }

        wp_die(esc_html__('A fájl nem érhető el.', 'mgcf'));
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
