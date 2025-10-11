<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Custom_Fields_Manager {
    const META_KEY = '_mg_is_custom_product';
    const OPTION_KEY = 'mg_custom_fields';
    const PRESET_OPTION_KEY = 'mg_custom_field_presets';

    protected static $placement_keys = array('below_variants', 'above_variants');

    protected static $cached_presets = null;

    /**
     * Return whether the given product is marked as custom.
     */
    public static function is_custom_product($product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return false;
        }
        $value = get_post_meta($product_id, self::META_KEY, true);
        if ($value === 'yes' || $value === 1 || $value === '1') {
            return true;
        }
        return false;
    }

    /**
     * Mark or unmark a product as custom.
     */
    public static function set_custom_product($product_id, $is_custom) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return;
        }
        if ($is_custom) {
            update_post_meta($product_id, self::META_KEY, 'yes');
        } else {
            delete_post_meta($product_id, self::META_KEY);
        }
    }

    public static function get_placement_options() {
        return array(
            'below_variants' => __('Variánsok alatt', 'mgcf'),
            'above_variants' => __('Variánsok felett', 'mgcf'),
        );
    }

    public static function normalize_placement($placement) {
        $placement = sanitize_key($placement);
        if (in_array($placement, self::$placement_keys, true)) {
            return $placement;
        }
        return 'below_variants';
    }

    /**
     * Retrieve all custom products as WP_Post objects.
     *
     * @return WP_Post[]
     */
    public static function get_custom_products() {
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => array('publish', 'pending', 'draft', 'future', 'private'),
            'meta_key'       => self::META_KEY,
            'meta_value'     => 'yes',
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        $products = get_posts($args);
        if (!is_array($products)) {
            return array();
        }
        return $products;
    }

    /**
     * Load the full option payload.
     */
    protected static function get_all_settings() {
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            return array();
        }
        return $stored;
    }

    /**
     * Persist the full option payload.
     */
    protected static function save_all_settings($data) {
        if (!is_array($data)) {
            $data = array();
        }
        update_option(self::OPTION_KEY, $data, false);
    }

    protected static function get_all_presets() {
        if (is_array(self::$cached_presets)) {
            return self::$cached_presets;
        }
        $stored = get_option(self::PRESET_OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        self::$cached_presets = $stored;
        return self::$cached_presets;
    }

    protected static function save_all_presets($presets) {
        if (!is_array($presets)) {
            $presets = array();
        }
        self::$cached_presets = $presets;
        update_option(self::PRESET_OPTION_KEY, $presets, false);
    }

    /**
     * Retrieve fields for a product.
     */
    public static function get_fields_for_product($product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return array();
        }
        $all = self::get_all_settings();
        if (!isset($all[$product_id]) || !is_array($all[$product_id])) {
            return array();
        }
        $fields = isset($all[$product_id]['fields']) ? $all[$product_id]['fields'] : array();
        if (!is_array($fields)) {
            return array();
        }
        $sanitized = array();
        foreach ($fields as $field) {
            $sanitized[] = self::sanitize_field($field);
        }
        usort($sanitized, function($a, $b) {
            $pos_a = isset($a['position']) ? intval($a['position']) : 0;
            $pos_b = isset($b['position']) ? intval($b['position']) : 0;
            if ($pos_a === $pos_b) {
                return strcmp(strtolower($a['label'] ?? ''), strtolower($b['label'] ?? ''));
            }
            return ($pos_a < $pos_b) ? -1 : 1;
        });
        return $sanitized;
    }

    public static function get_presets() {
        $presets = self::get_all_presets();
        $sorted = $presets;
        uasort($sorted, function($a, $b) {
            $name_a = isset($a['name']) ? strtolower($a['name']) : '';
            $name_b = isset($b['name']) ? strtolower($b['name']) : '';
            return strcmp($name_a, $name_b);
        });
        return $sorted;
    }

    public static function get_preset($preset_id) {
        $preset_id = sanitize_key($preset_id);
        if ($preset_id === '') {
            return null;
        }
        $presets = self::get_all_presets();
        if (!isset($presets[$preset_id]) || !is_array($presets[$preset_id])) {
            return null;
        }
        $preset = $presets[$preset_id];
        if (!isset($preset['fields']) || !is_array($preset['fields'])) {
            $preset['fields'] = array();
        }
        $clean_fields = array();
        foreach ($preset['fields'] as $field) {
            $clean_fields[] = self::sanitize_field($field);
        }
        $preset['fields'] = $clean_fields;
        return $preset;
    }

    public static function save_preset($name, $fields, $preset_id = '') {
        $name = is_string($name) ? trim(wp_strip_all_tags($name)) : '';
        if ($name === '') {
            return false;
        }
        if (!is_array($fields) || empty($fields)) {
            return false;
        }
        $clean_fields = array();
        foreach ($fields as $field) {
            $clean_fields[] = self::sanitize_field($field);
        }
        $presets = self::get_all_presets();
        $preset_id = sanitize_key($preset_id);
        if ($preset_id === '' || !isset($presets[$preset_id])) {
            $preset_id = '';
        }
        if ($preset_id === '') {
            foreach ($presets as $existing_id => $preset) {
                if (!is_array($preset)) {
                    continue;
                }
                if (isset($preset['name']) && strcasecmp($preset['name'], $name) === 0) {
                    $preset_id = $existing_id;
                    break;
                }
            }
        }
        if ($preset_id === '') {
            $preset_id = 'mgcf_preset_' . uniqid();
        }
        $presets[$preset_id] = array(
            'id'     => $preset_id,
            'name'   => $name,
            'fields' => $clean_fields,
            'updated'=> current_time('mysql'),
        );
        self::save_all_presets($presets);
        return $preset_id;
    }

    public static function delete_preset($preset_id) {
        $preset_id = sanitize_key($preset_id);
        if ($preset_id === '') {
            return;
        }
        $presets = self::get_all_presets();
        if (isset($presets[$preset_id])) {
            unset($presets[$preset_id]);
            self::save_all_presets($presets);
        }
    }

    public static function apply_preset_to_product($product_id, $preset_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return false;
        }
        $preset = self::get_preset($preset_id);
        if (!$preset) {
            return false;
        }
        $fields = isset($preset['fields']) && is_array($preset['fields']) ? $preset['fields'] : array();
        if (empty($fields)) {
            return false;
        }
        self::save_fields_for_product($product_id, $fields);
        self::set_custom_product($product_id, true);
        return true;
    }

    /**
     * Store a full list of fields for a product.
     */
    public static function save_fields_for_product($product_id, $fields) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return;
        }
        if (!is_array($fields)) {
            $fields = array();
        }
        $clean = array();
        foreach ($fields as $field) {
            $clean[] = self::sanitize_field($field);
        }
        $all = self::get_all_settings();
        $all[$product_id] = array('fields' => $clean);
        self::save_all_settings($all);
    }

    /**
     * Add or update a single field.
     */
    public static function upsert_field($product_id, $field) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return;
        }
        $clean = self::sanitize_field($field);
        $all = self::get_all_settings();
        if (!isset($all[$product_id]) || !is_array($all[$product_id])) {
            $all[$product_id] = array('fields' => array());
        }
        if (!isset($all[$product_id]['fields']) || !is_array($all[$product_id]['fields'])) {
            $all[$product_id]['fields'] = array();
        }
        $found = false;
        foreach ($all[$product_id]['fields'] as &$existing) {
            if (!is_array($existing)) {
                continue;
            }
            if (!empty($existing['id']) && $existing['id'] === $clean['id']) {
                $existing = $clean;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $all[$product_id]['fields'][] = $clean;
        }
        self::save_all_settings($all);
    }

    /**
     * Delete a field by ID for a product.
     */
    public static function delete_field($product_id, $field_id) {
        $product_id = intval($product_id);
        $field_id = is_string($field_id) ? trim($field_id) : '';
        if ($product_id <= 0 || $field_id === '') {
            return;
        }
        $all = self::get_all_settings();
        if (!isset($all[$product_id]['fields']) || !is_array($all[$product_id]['fields'])) {
            return;
        }
        $all[$product_id]['fields'] = array_values(array_filter($all[$product_id]['fields'], function($field) use ($field_id) {
            if (!is_array($field)) {
                return false;
            }
            return empty($field['id']) || $field['id'] !== $field_id;
        }));
        self::save_all_settings($all);
    }

    /**
     * Ensure the field definition is sanitized and complete.
     */
    protected static function sanitize_field($field) {
        $field = is_array($field) ? $field : array();
        $id = isset($field['id']) ? sanitize_key($field['id']) : '';
        if ($id === '') {
            $id = 'mgcf_' . uniqid();
        }
        $label = isset($field['label']) ? sanitize_text_field($field['label']) : '';
        $type = isset($field['type']) ? sanitize_key($field['type']) : 'text';
        $allowed_types = array('text', 'number', 'select', 'date', 'color');
        if (!in_array($type, $allowed_types, true)) {
            $type = 'text';
        }
        $required = !empty($field['required']) ? true : false;
        $position = isset($field['position']) ? intval($field['position']) : 0;
        $default = isset($field['default']) ? self::sanitize_field_value($field['default'], $type) : '';
        $validation_min = isset($field['validation_min']) ? sanitize_text_field($field['validation_min']) : '';
        $validation_max = isset($field['validation_max']) ? sanitize_text_field($field['validation_max']) : '';
        $placement = isset($field['placement']) ? self::normalize_placement($field['placement']) : 'below_variants';
        $options = array();
        if ($type === 'select') {
            if (!empty($field['options']) && is_array($field['options'])) {
                foreach ($field['options'] as $option) {
                    $option = sanitize_text_field($option);
                    if ($option === '') {
                        continue;
                    }
                    $options[] = $option;
                }
            } elseif (!empty($field['options']) && is_string($field['options'])) {
                $parts = preg_split('/\r?\n|,/', $field['options']);
                foreach ($parts as $option) {
                    $option = sanitize_text_field($option);
                    if ($option === '') {
                        continue;
                    }
                    $options[] = $option;
                }
            }
        }
        $surcharge_type = isset($field['surcharge_type']) ? sanitize_key($field['surcharge_type']) : 'none';
        if (!in_array($surcharge_type, array('none', 'fixed', 'percent'), true)) {
            $surcharge_type = 'none';
        }
        $surcharge_amount = 0.0;
        if (!empty($field['surcharge_amount'])) {
            $surcharge_amount = floatval($field['surcharge_amount']);
        }
        $mockup = array();
        if (!empty($field['mockup']) && is_array($field['mockup'])) {
            $mockup['x'] = isset($field['mockup']['x']) ? intval($field['mockup']['x']) : '';
            $mockup['y'] = isset($field['mockup']['y']) ? intval($field['mockup']['y']) : '';
            $mockup['font'] = isset($field['mockup']['font']) ? sanitize_text_field($field['mockup']['font']) : '';
            $mockup['color'] = '';
            if (isset($field['mockup']['color'])) {
                $color = sanitize_text_field($field['mockup']['color']);
                if (function_exists('sanitize_hex_color')) {
                    $color = sanitize_hex_color($color);
                }
                $mockup['color'] = $color ? $color : '';
            }
            $mockup['size'] = isset($field['mockup']['size']) ? floatval($field['mockup']['size']) : '';
            $mockup['view'] = isset($field['mockup']['view']) ? sanitize_text_field($field['mockup']['view']) : '';
            $mockup['additional'] = isset($field['mockup']['additional']) ? sanitize_text_field($field['mockup']['additional']) : '';
        }
        $description = isset($field['description']) ? sanitize_textarea_field($field['description']) : '';

        return array(
            'id'              => $id,
            'label'           => $label,
            'type'            => $type,
            'required'        => $required,
            'position'        => $position,
            'default'         => $default,
            'validation_min'  => $validation_min,
            'validation_max'  => $validation_max,
            'placement'       => $placement,
            'options'         => $options,
            'surcharge_type'  => $surcharge_type,
            'surcharge_amount'=> $surcharge_type === 'none' ? 0.0 : $surcharge_amount,
            'mockup'          => $mockup,
            'description'     => $description,
        );
    }

    protected static function sanitize_field_value($value, $type) {
        switch ($type) {
            case 'number':
                return is_numeric($value) ? $value : '';
            case 'date':
                return sanitize_text_field($value);
            case 'color':
                $color = sanitize_text_field($value);
                if (function_exists('sanitize_hex_color')) {
                    $color = sanitize_hex_color($color);
                }
                return $color ? $color : '';
            default:
                return sanitize_text_field($value);
        }
    }
}
