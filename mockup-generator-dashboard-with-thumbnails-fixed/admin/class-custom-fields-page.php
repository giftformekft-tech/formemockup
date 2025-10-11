<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Custom_Fields_Page {
    const NONCE_FIELD = 'mg_custom_fields_nonce';
    const NONCE_ACTION = 'mg_custom_fields_action';

    public static function add_submenu_page() {
        $hook = add_submenu_page(
            'mockup-generator',
            __('Egyedi mezők', 'mgcf'),
            __('Egyedi mezők', 'mgcf'),
            'edit_products',
            'mockup-generator-custom-fields',
            array(__CLASS__, 'render_page')
        );
        if (!has_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'))) {
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        }
        return $hook;
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'mockup-generator_page_mockup-generator-custom-fields') {
            return;
        }
        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_url = plugins_url('assets/css/custom-fields.css', $base_file);
        wp_enqueue_style('mg-custom-fields-admin', $style_url, array(), '1.0.0');
    }

    protected static function handle_post() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (empty($_POST['mg_custom_fields_action'])) {
            return;
        }
        if (!current_user_can('edit_products')) {
            return;
        }
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce(wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
            add_settings_error('mg_custom_fields_admin', 'mgcf_nonce', __('Érvénytelen biztonsági token.', 'mgcf'), 'error');
            return;
        }
        if (isset($_POST['mg_custom_fields_delete'])) {
            $action = 'delete_field';
        } else {
            $action = sanitize_key($_POST['mg_custom_fields_action']);
        }
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if ($product_id <= 0) {
            add_settings_error('mg_custom_fields_admin', 'mgcf_product', __('Hiányzó termékazonosító.', 'mgcf'), 'error');
            return;
        }
        switch ($action) {
            case 'add_field':
            case 'update_field':
                $field = self::read_field_from_request();
                if (empty($field['label'])) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_label', __('A mező neve nem lehet üres.', 'mgcf'), 'error');
                    break;
                }
                if ($action === 'update_field' && empty($field['id'])) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_id', __('Hiányzik a mező azonosítója.', 'mgcf'), 'error');
                    break;
                }
                if ($action === 'add_field') {
                    $field['id'] = 'mgcf_' . uniqid();
                }
                MG_Custom_Fields_Manager::upsert_field($product_id, $field);
                add_settings_error('mg_custom_fields_admin', 'mgcf_saved', __('A mező beállításai mentve.', 'mgcf'), 'updated');
                break;
            case 'delete_field':
                $field_id = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
                if ($field_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_delete_id', __('Hiányzik a mező azonosítója.', 'mgcf'), 'error');
                    break;
                }
                MG_Custom_Fields_Manager::delete_field($product_id, $field_id);
                add_settings_error('mg_custom_fields_admin', 'mgcf_deleted', __('A mező törlésre került.', 'mgcf'), 'updated');
                break;
            case 'toggle_custom':
                $flag = isset($_POST['make_custom']) ? (bool) $_POST['make_custom'] : false;
                MG_Custom_Fields_Manager::set_custom_product($product_id, $flag);
                if ($flag) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_marked', __('A termék egyedi státuszt kapott.', 'mgcf'), 'updated');
                } else {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_unmarked', __('A termék többé nem egyedi.', 'mgcf'), 'updated');
                }
                break;
        }
    }

    protected static function read_field_from_request() {
        $field = array();
        $field['id'] = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
        $field['label'] = isset($_POST['field_label']) ? sanitize_text_field($_POST['field_label']) : '';
        $field['type'] = isset($_POST['field_type']) ? sanitize_key($_POST['field_type']) : 'text';
        $field['required'] = !empty($_POST['field_required']);
        $field['default'] = isset($_POST['field_default']) ? sanitize_text_field($_POST['field_default']) : '';
        $field['validation_min'] = isset($_POST['field_validation_min']) ? sanitize_text_field($_POST['field_validation_min']) : '';
        $field['validation_max'] = isset($_POST['field_validation_max']) ? sanitize_text_field($_POST['field_validation_max']) : '';
        $field['placement'] = isset($_POST['field_placement']) ? sanitize_text_field($_POST['field_placement']) : '';
        $field['position'] = isset($_POST['field_position']) ? intval($_POST['field_position']) : 0;
        $field['description'] = isset($_POST['field_description']) ? sanitize_textarea_field($_POST['field_description']) : '';
        $options_raw = isset($_POST['field_options']) ? wp_kses_post($_POST['field_options']) : '';
        $field['options'] = $options_raw;
        $field['surcharge_type'] = isset($_POST['field_surcharge_type']) ? sanitize_key($_POST['field_surcharge_type']) : 'none';
        $field['surcharge_amount'] = isset($_POST['field_surcharge_amount']) ? floatval($_POST['field_surcharge_amount']) : 0.0;
        $field['mockup'] = array(
            'x' => isset($_POST['field_mockup_x']) ? intval($_POST['field_mockup_x']) : '',
            'y' => isset($_POST['field_mockup_y']) ? intval($_POST['field_mockup_y']) : '',
            'font' => isset($_POST['field_mockup_font']) ? sanitize_text_field($_POST['field_mockup_font']) : '',
            'color' => isset($_POST['field_mockup_color']) ? sanitize_text_field($_POST['field_mockup_color']) : '',
            'size' => isset($_POST['field_mockup_size']) ? floatval($_POST['field_mockup_size']) : '',
            'view' => isset($_POST['field_mockup_view']) ? sanitize_text_field($_POST['field_mockup_view']) : '',
            'additional' => isset($_POST['field_mockup_additional']) ? sanitize_text_field($_POST['field_mockup_additional']) : '',
        );
        return $field;
    }

    public static function render_page() {
        if (!current_user_can('edit_products')) {
            wp_die(__('Nincs jogosultság.', 'mgcf'));
        }
        self::handle_post();
        settings_errors('mg_custom_fields_admin');
        $current_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        echo '<div class="wrap mg-custom-fields-admin">';
        echo '<h1>' . esc_html__('Egyedi mezők', 'mgcf') . '</h1>';
        if ($current_product_id > 0) {
            self::render_product_editor($current_product_id);
        } else {
            self::render_product_list();
        }
        echo '</div>';
    }

    protected static function render_product_list() {
        $products = MG_Custom_Fields_Manager::get_custom_products();
        echo '<p>' . esc_html__('Az alábbi listában azok a termékek szerepelnek, amelyeket az egyedi jelölővel láttál el.', 'mgcf') . '</p>';
        if (empty($products)) {
            echo '<p><em>' . esc_html__('Még nincs egyedi termék kijelölve. A bulk feltöltő oldalon pipáld be a „Egyedi termék” jelölőt.', 'mgcf') . '</em></p>';
            return;
        }
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__('Termék', 'mgcf') . '</th><th>' . esc_html__('Állapot', 'mgcf') . '</th><th>' . esc_html__('Művelet', 'mgcf') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($products as $product) {
            $status = get_post_status_object($product->post_status);
            $status_label = $status ? $status->label : $product->post_status;
            $edit_link = add_query_arg(array('page' => 'mockup-generator-custom-fields', 'product_id' => $product->ID), admin_url('admin.php'));
            echo '<tr>';
            echo '<td>' . esc_html(get_the_title($product)) . '</td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '<td><a class="button" href="' . esc_url($edit_link) . '">' . esc_html__('Mezők szerkesztése', 'mgcf') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    protected static function render_product_editor($product_id) {
        $product = get_post($product_id);
        if (!$product) {
            echo '<p><em>' . esc_html__('A megadott termék nem található.', 'mgcf') . '</em></p>';
            return;
        }
        $is_custom = MG_Custom_Fields_Manager::is_custom_product($product_id);
        $back_link = remove_query_arg('product_id');
        echo '<p><a class="button" href="' . esc_url($back_link) . '">' . esc_html__('Vissza a listához', 'mgcf') . '</a></p>';
        echo '<h2>' . esc_html(get_the_title($product)) . '</h2>';
        echo '<p>' . esc_html__('Itt adhatod meg, hogy milyen mezők jelenjenek meg a termékoldalon.', 'mgcf') . '</p>';

        echo '<form method="post" class="mg-custom-toggle-form">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="toggle_custom" />';
        echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
        if ($is_custom) {
            echo '<input type="hidden" name="make_custom" value="0" />';
            echo '<button type="submit" class="button">' . esc_html__('Egyedi státusz eltávolítása', 'mgcf') . '</button>';
        } else {
            echo '<input type="hidden" name="make_custom" value="1" />';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Egyedi státusz beállítása', 'mgcf') . '</button>';
        }
        echo '</form>';

        $fields = MG_Custom_Fields_Manager::get_fields_for_product($product_id);
        if (empty($fields)) {
            echo '<h3>' . esc_html__('Még nincs mező konfigurálva', 'mgcf') . '</h3>';
        } else {
            echo '<h3>' . esc_html__('Meglévő mezők', 'mgcf') . '</h3>';
            foreach ($fields as $field) {
                self::render_field_editor_form($product_id, $field);
            }
        }
        echo '<h3>' . esc_html__('Új mező hozzáadása', 'mgcf') . '</h3>';
        self::render_field_editor_form($product_id, null, true);
    }

    protected static function render_field_editor_form($product_id, $field = null, $is_new = false) {
        $field = is_array($field) ? $field : array();
        $field_id = isset($field['id']) ? $field['id'] : '';
        $label = isset($field['label']) ? $field['label'] : '';
        $type = isset($field['type']) ? $field['type'] : 'text';
        $required = !empty($field['required']);
        $default = isset($field['default']) ? $field['default'] : '';
        $validation_min = isset($field['validation_min']) ? $field['validation_min'] : '';
        $validation_max = isset($field['validation_max']) ? $field['validation_max'] : '';
        $placement = isset($field['placement']) ? $field['placement'] : '';
        $position = isset($field['position']) ? intval($field['position']) : 0;
        $description = isset($field['description']) ? $field['description'] : '';
        $options = isset($field['options']) ? $field['options'] : array();
        $options_text = '';
        if (is_array($options)) {
            $options_text = implode("\n", $options);
        } elseif (is_string($options)) {
            $options_text = $options;
        }
        $surcharge_type = isset($field['surcharge_type']) ? $field['surcharge_type'] : 'none';
        $surcharge_amount = isset($field['surcharge_amount']) ? floatval($field['surcharge_amount']) : 0.0;
        $mockup = isset($field['mockup']) && is_array($field['mockup']) ? $field['mockup'] : array();
        $mockup_x = isset($mockup['x']) ? $mockup['x'] : '';
        $mockup_y = isset($mockup['y']) ? $mockup['y'] : '';
        $mockup_font = isset($mockup['font']) ? $mockup['font'] : '';
        $mockup_color = isset($mockup['color']) ? $mockup['color'] : '';
        $mockup_size = isset($mockup['size']) ? $mockup['size'] : '';
        $mockup_view = isset($mockup['view']) ? $mockup['view'] : '';
        $mockup_additional = isset($mockup['additional']) ? $mockup['additional'] : '';

        echo '<div class="mg-custom-field-card">';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
        echo '<input type="hidden" name="mg_custom_fields_action" value="' . ($is_new ? 'add_field' : 'update_field') . '" />';
        if (!$is_new) {
            echo '<input type="hidden" name="field_id" value="' . esc_attr($field_id) . '" />';
        }
        echo '<table class="form-table mg-custom-field-table">';
        echo '<tr><th scope="row"><label>' . esc_html__('Mező neve', 'mgcf') . '</label></th><td><input type="text" name="field_label" value="' . esc_attr($label) . '" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Típus', 'mgcf') . '</label></th><td><select name="field_type">';
        $types = array(
            'text' => __('Szöveg', 'mgcf'),
            'number' => __('Szám', 'mgcf'),
            'select' => __('Választólista', 'mgcf'),
            'date' => __('Dátum', 'mgcf'),
            'color' => __('Szín', 'mgcf'),
        );
        foreach ($types as $key => $label_text) {
            echo '<option value="' . esc_attr($key) . '"' . selected($type, $key, false) . '>' . esc_html($label_text) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Kötelező', 'mgcf') . '</th><td><label><input type="checkbox" name="field_required" value="1"' . checked($required, true, false) . ' /> ' . esc_html__('Igen', 'mgcf') . '</label></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Alapértelmezett érték', 'mgcf') . '</label></th><td><input type="text" name="field_default" value="' . esc_attr($default) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Érvényességi minimum', 'mgcf') . '</label></th><td><input type="text" name="field_validation_min" value="' . esc_attr($validation_min) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Érvényességi maximum', 'mgcf') . '</label></th><td><input type="text" name="field_validation_max" value="' . esc_attr($validation_max) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Elhelyezés (pl. variánsok alatt)', 'mgcf') . '</label></th><td><input type="text" name="field_placement" value="' . esc_attr($placement) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Sorrend', 'mgcf') . '</label></th><td><input type="number" name="field_position" value="' . esc_attr($position) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Leírás', 'mgcf') . '</label></th><td><textarea name="field_description" rows="2" class="large-text">' . esc_textarea($description) . '</textarea></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Választólista értékek', 'mgcf') . '</label></th><td><textarea name="field_options" rows="3" class="large-text" placeholder="Érték1\nÉrték2">' . esc_textarea($options_text) . '</textarea><p class="description">' . esc_html__('Választólista típusnál soronként egy opció.', 'mgcf') . '</p></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Árpótdíj típusa', 'mgcf') . '</label></th><td><select name="field_surcharge_type">';
        $surcharge_types = array(
            'none' => __('Nincs', 'mgcf'),
            'fixed' => __('Fix összeg', 'mgcf'),
            'percent' => __('Százalékos', 'mgcf'),
        );
        foreach ($surcharge_types as $key => $label_text) {
            echo '<option value="' . esc_attr($key) . '"' . selected($surcharge_type, $key, false) . '>' . esc_html($label_text) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Árpótdíj összege', 'mgcf') . '</label></th><td><input type="number" step="0.01" name="field_surcharge_amount" value="' . esc_attr($surcharge_amount) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup X koordináta', 'mgcf') . '</label></th><td><input type="number" name="field_mockup_x" value="' . esc_attr($mockup_x) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup Y koordináta', 'mgcf') . '</label></th><td><input type="number" name="field_mockup_y" value="' . esc_attr($mockup_y) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup betűtípus', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_font" value="' . esc_attr($mockup_font) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup szín', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_color" value="' . esc_attr($mockup_color) . '" class="regular-text" placeholder="#000000" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup méret', 'mgcf') . '</label></th><td><input type="number" step="0.1" name="field_mockup_size" value="' . esc_attr($mockup_size) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup nézet', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_view" value="' . esc_attr($mockup_view) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup megjegyzés', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_additional" value="' . esc_attr($mockup_additional) . '" class="regular-text" /></td></tr>';
        echo '</table>';
        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . esc_html($is_new ? __('Mező hozzáadása', 'mgcf') : __('Mező frissítése', 'mgcf')) . '</button>';
        if (!$is_new) {
            echo ' <button type="submit" name="mg_custom_fields_delete" value="1" class="button mg-delete-field" onclick="return confirm(\'' . esc_js(__('Biztosan törölni szeretnéd ezt a mezőt?', 'mgcf')) . '\');">' . esc_html__('Mező törlése', 'mgcf') . '</button>';
        }
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }
}
